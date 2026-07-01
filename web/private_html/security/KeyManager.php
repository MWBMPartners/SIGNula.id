<?php
/**
 * ============================================================================
 * 🗝️ SIGNula - JWT Signing Key Manager (RS256 keypairs)
 * ============================================================================
 *
 * Purpose:
 *   Owns the lifecycle of the RSA signing keys used to sign/verify SIGNula's
 *   JWT access tokens (G-003). Responsibilities:
 *     • generateKey()  — mint a new RSA keypair, tag it with a `kid`, store the
 *                        PRIVATE key ENCRYPTED (isSensitive) in tblSettings and
 *                        the PUBLIC key (JWK) in tblSettings, and mirror both to
 *                        the `_private` key-file fallback.
 *     • getActiveKey() — the current signer (kid + private PEM), DB-first,
 *                        file-fallback.
 *     • getPublicKey() — the public PEM for a given kid (for local verify).
 *     • getJwks()      — the public JWKS document (all currently-valid public
 *                        keys) for the /.well-known/jwks.json endpoint (Stage 4)
 *                        and for G-001 relying parties.
 *     • rotateKey()    — mint a new key + point active_kid at it (old public key
 *                        stays in JWKS until retired).
 *     • retireKey()    — drop an old key's public JWK from JWKS once no live
 *                        token can still reference it.
 *
 * Storage model (per G-003 §4, precedence order):
 *   1. tblSettings — jwt.signing_key.<kid>.private_pem  (isSensitive=1, AES-256-CBC
 *      encrypted at rest via SecurityUtils::encrypt, decrypted on load by
 *      config.php's loadSettings()). Public JWK in jwt.signing_key.<kid>.public_jwk
 *      (isSensitive=0). Active signer pointer in jwt.signing_key.active_kid.
 *   2. `_private` PEM files — web/_private/keys/jwt/<kid>.key (0600) and
 *      <kid>.pub (0600), dir 0700, outside web root, `_`-prefixed. Read when the
 *      DB row is absent/unreadable, mirroring how ENCRYPTION_KEY lives in
 *      web/_private/auth.php.
 *
 *   The JWT signing key is a SEPARATE secret from ENCRYPTION_KEY: ENCRYPTION_KEY
 *   ENCRYPTS the signing private key at rest; it does not itself sign tokens.
 *
 * Testability:
 *   All persistence goes through a small set of private helpers
 *   (readSetting/writeSetting/deleteSetting/readActiveKid/writeActiveKid) that
 *   default to the real Database + config.php getSetting(), but can be swapped
 *   for an in-memory store via setStorageOverride() so KeyManagerTest runs with
 *   NO database (unit test). Production never calls setStorageOverride().
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). Requires ext-openssl.
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.8.0-beta (G-003 Stage 1)
 * @link       https://www.php.net/manual/en/book.openssl.php
 * @link       https://datatracker.ietf.org/doc/html/rfc7517 (JWK)
 * @link       https://datatracker.ietf.org/doc/html/rfc7518#section-6.3 (RSA JWK n/e)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🗝️ RS256 signing-key lifecycle manager.
 *
 * All methods are static (matches the SecurityUtils / MFA / TOTP house style).
 */
class KeyManager
{
    /**
     * @var int RSA key size in bits.
     *
     * G-003 §4: RSA-2048 minimum, RSA-3072 recommended. We default to 3072 for
     * longevity (the brief prizes "future industry standards"). Overridable via
     * the jwt.key_bits setting for operators who need 2048 for perf.
     * @see https://www.keylength.com/
     */
    private const DEFAULT_KEY_BITS = 3072;

    /** @var string Signing algorithm — RS256 only for external verifiability. */
    public const ALGORITHM = 'RS256';

    /** @var string tblSettings key holding the active signer's kid. */
    private const ACTIVE_KID_KEY = 'jwt.signing_key.active_kid';

    /**
     * 🧪 Optional in-memory storage override for unit tests.
     *
     * When set, it is an associative array:
     *   [ 'settings' => array<string,string> (by reference), ]
     * and reads/writes hit this array instead of the database. Null in
     * production (the default) — DB path is used.
     *
     * @var array<string,array<string,string>>|null
     */
    private static ?array $storageOverride = null;

    /**
     * 📁 Optional key-file directory override (unit tests point this at a temp
     * dir so the file-fallback path can be exercised without touching
     * web/_private). Null → the real PRIVATE_DIR/keys/jwt location.
     *
     * @var string|null
     */
    private static ?string $keyDirOverride = null;

    // ========================================================================
    // 🧪 TEST SEAMS
    // ========================================================================

    /**
     * 🧪 Install an in-memory settings store (unit tests only).
     *
     * @param array<string,string> $settings Initial settings map (by value; the
     *                                        method keeps its own copy).
     * @return void
     */
    public static function setStorageOverride(array $settings = []): void
    {
        self::$storageOverride = ['settings' => $settings];
    }

    /**
     * 🧪 Point the key-file fallback at a custom directory (unit tests only).
     *
     * @param string|null $dir Directory, or null to restore the default.
     * @return void
     */
    public static function setKeyDirOverride(?string $dir): void
    {
        self::$keyDirOverride = $dir;
    }

    /**
     * 🧪 Clear all test overrides (restore production behaviour).
     *
     * @return void
     */
    public static function resetOverrides(): void
    {
        self::$storageOverride = null;
        self::$keyDirOverride  = null;
    }

    // ========================================================================
    // 🔑 KEY GENERATION & ROTATION
    // ========================================================================

    /**
     * 🔨 Generate a new RSA signing keypair and persist it.
     *
     * Mints a fresh RSA keypair, derives a `kid`, encrypts+stores the private
     * PEM (isSensitive), stores the public JWK, mirrors both to the `_private`
     * key files, and — unless $makeActive is false — points active_kid at it.
     *
     * @param bool $makeActive Set this key as the active signer (default true).
     * @return string The `kid` of the newly generated key.
     * @throws RuntimeException On any OpenSSL or persistence failure.
     */
    public static function generateKey(bool $makeActive = true): string
    {
        // 🎲 Derive a collision-resistant, sortable kid: YYYYMM slug + random.
        //    Random component prevents guessing/enumeration; date component aids
        //    operators reading JWKS. Never used as a file path or SQL fragment
        //    (see G-003 §7.2) — always looked up, never concatenated.
        $kid = date('Ym') . '-' . bin2hex(random_bytes(6)); // e.g. 202607-1a2b3c4d5e6f

        // 🔐 Create the keypair with OpenSSL.
        $bits = (int) (getSetting('jwt.key_bits', self::DEFAULT_KEY_BITS));
        if ($bits < 2048) {
            // 🛡️ Never below the RSA-2048 floor, whatever the setting says.
            $bits = 2048;
        }

        $config = [
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        if ($res === false) {
            throw new RuntimeException(
                'KeyManager: openssl_pkey_new failed — ' . self::opensslErrors()
            );
        }

        // 📤 Export the private key PEM.
        $privatePem = '';
        if (!openssl_pkey_export($res, $privatePem)) {
            throw new RuntimeException(
                'KeyManager: openssl_pkey_export failed — ' . self::opensslErrors()
            );
        }

        // 📤 Extract the public key PEM + details (modulus/exponent) for the JWK.
        $details = openssl_pkey_get_details($res);
        if ($details === false || empty($details['key']) || ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_RSA) {
            throw new RuntimeException('KeyManager: could not read RSA public key details.');
        }
        $publicPem = $details['key'];

        // 🧱 Build the public JWK (RFC 7518 §6.3.1: n = modulus, e = exponent,
        //    both base64url of the big-endian unsigned integer bytes).
        $jwk = [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => self::ALGORITHM,
            'kid' => $kid,
            'n'   => self::base64UrlEncode($details['rsa']['n']),
            'e'   => self::base64UrlEncode($details['rsa']['e']),
        ];

        // 💾 Persist: private (encrypted, isSensitive) + public JWK (clear).
        self::writeSetting(
            'jwt.signing_key.' . $kid . '.private_pem',
            SecurityUtils::encrypt($privatePem),
            true // isSensitive
        );
        self::writeSetting(
            'jwt.signing_key.' . $kid . '.public_jwk',
            json_encode($jwk, JSON_UNESCAPED_SLASHES),
            false
        );

        // 📁 Mirror to the `_private` key-file fallback (best-effort — DB is the
        //    primary store; a file-write failure must not abort key generation,
        //    but IS logged).
        self::writeKeyFiles($kid, $privatePem, $publicPem);

        // 🎯 Promote to active signer if requested.
        if ($makeActive) {
            self::writeActiveKid($kid);
        }

        return $kid;
    }

    /**
     * 🔄 Rotate the signing key.
     *
     * Mints a new key and makes it active. The OLD key's public JWK deliberately
     * REMAINS in JWKS (getJwks) so tokens already signed by it keep verifying
     * until they expire; call retireKey() on the old kid once
     * now > rotation_time + max_access_token_ttl.
     *
     * @return string The new active kid.
     * @throws RuntimeException On failure.
     */
    public static function rotateKey(): string
    {
        return self::generateKey(true);
    }

    /**
     * 🗑️ Retire a key: remove its PUBLIC JWK from JWKS.
     *
     * Called once no unexpired access token can still carry this kid. The
     * (encrypted) private key row is deleted too — S1 zeroises rather than
     * keeping it for forensic verify; a future forensic-retention flag can
     * change this. Refuses to retire the currently-active kid.
     *
     * @param string $kid The key id to retire.
     * @return bool True if a key was retired; false if it was active/absent.
     */
    public static function retireKey(string $kid): bool
    {
        // 🛑 Never retire the live signer.
        if ($kid === self::getActiveKid()) {
            return false;
        }

        $hadPublic = self::readSetting('jwt.signing_key.' . $kid . '.public_jwk') !== null;

        self::deleteSetting('jwt.signing_key.' . $kid . '.public_jwk');
        self::deleteSetting('jwt.signing_key.' . $kid . '.private_pem');

        // 📁 Best-effort removal of the mirrored key files.
        self::deleteKeyFiles($kid);

        return $hadPublic;
    }

    // ========================================================================
    // 🔍 KEY RETRIEVAL
    // ========================================================================

    /**
     * 🎯 Get the active signing key.
     *
     * @return array{kid:string,private_pem:string}
     * @throws RuntimeException If no active key is configured/loadable.
     */
    public static function getActiveKey(): array
    {
        $kid = self::getActiveKid();
        if ($kid === '') {
            throw new RuntimeException('KeyManager: no active signing key (jwt.signing_key.active_kid is empty). Generate one first.');
        }

        $privatePem = self::getPrivateKey($kid);
        if ($privatePem === null) {
            throw new RuntimeException('KeyManager: active kid "' . $kid . '" has no loadable private key.');
        }

        return ['kid' => $kid, 'private_pem' => $privatePem];
    }

    /**
     * 🔑 Get the private PEM for a kid (DB-first, file-fallback).
     *
     * @param string $kid Key id.
     * @return string|null Decrypted PEM, or null if not found.
     */
    public static function getPrivateKey(string $kid): ?string
    {
        // 1️⃣ Database (already decrypted for us by config.php loadSettings() in
        //    production; in the test/override path we decrypt here since the
        //    override stores the encrypted blob exactly as the DB would).
        $stored = self::readSetting('jwt.signing_key.' . $kid . '.private_pem');
        if ($stored !== null && $stored !== '') {
            $pem = self::maybeDecrypt($stored);
            if ($pem !== null && self::looksLikePrivatePem($pem)) {
                return $pem;
            }
        }

        // 2️⃣ `_private` key-file fallback.
        $file = self::keyDir() . DIRECTORY_SEPARATOR . $kid . '.key';
        if (is_file($file) && is_readable($file)) {
            $pem = file_get_contents($file);
            if ($pem !== false && self::looksLikePrivatePem($pem)) {
                return $pem;
            }
        }

        return null;
    }

    /**
     * 🔓 Get the PUBLIC PEM for a kid (DB-first, file-fallback).
     *
     * Derives the PEM from the stored JWK when only the JWK is present, or reads
     * the mirrored .pub file.
     *
     * @param string $kid Key id.
     * @return string|null Public PEM, or null if not found.
     */
    public static function getPublicKey(string $kid): ?string
    {
        // 1️⃣ Reconstruct from the stored JWK (authoritative in DB).
        $jwkJson = self::readSetting('jwt.signing_key.' . $kid . '.public_jwk');
        if ($jwkJson !== null && $jwkJson !== '') {
            $jwk = json_decode($jwkJson, true);
            if (is_array($jwk) && isset($jwk['n'], $jwk['e'])) {
                $pem = self::jwkToPublicPem($jwk);
                if ($pem !== null) {
                    return $pem;
                }
            }
        }

        // 2️⃣ `_private` .pub file fallback.
        $file = self::keyDir() . DIRECTORY_SEPARATOR . $kid . '.pub';
        if (is_file($file) && is_readable($file)) {
            $pem = file_get_contents($file);
            if ($pem !== false && str_contains($pem, 'PUBLIC KEY')) {
                return $pem;
            }
        }

        return null;
    }

    /**
     * 🆔 Get the active kid (or '' if none).
     *
     * @return string
     */
    public static function getActiveKid(): string
    {
        return self::readActiveKid();
    }

    // ========================================================================
    // 🌐 JWKS ASSEMBLY
    // ========================================================================

    /**
     * 📜 Build the public JWKS document.
     *
     * Returns { "keys": [ {kty,use,alg,kid,n,e}, ... ] } for every currently
     * stored public key (active + not-yet-retired). This is the shared contract
     * consumed by the /.well-known/jwks.json endpoint (Stage 4) and by G-001
     * relying parties. Pure read — no private material ever appears here.
     *
     * @return array{keys:array<int,array<string,string>>}
     */
    public static function getJwks(): array
    {
        $keys = [];

        foreach (self::listPublicKidKeys() as $settingKey) {
            $jwkJson = self::readSetting($settingKey);
            if ($jwkJson === null || $jwkJson === '') {
                continue;
            }
            $jwk = json_decode($jwkJson, true);
            // 🛡️ Only publish well-formed public RSA JWKs — never leak anything else.
            if (is_array($jwk)
                && ($jwk['kty'] ?? null) === 'RSA'
                && isset($jwk['kid'], $jwk['n'], $jwk['e'])
                && !isset($jwk['d'])) { // 'd' = private exponent — must never appear
                $keys[] = [
                    'kty' => 'RSA',
                    'use' => $jwk['use'] ?? 'sig',
                    'alg' => $jwk['alg'] ?? self::ALGORITHM,
                    'kid' => (string) $jwk['kid'],
                    'n'   => (string) $jwk['n'],
                    'e'   => (string) $jwk['e'],
                ];
            }
        }

        return ['keys' => $keys];
    }

    /**
     * 🗂️ List all setting keys of the form jwt.signing_key.<kid>.public_jwk.
     *
     * @return string[] Setting keys.
     */
    private static function listPublicKidKeys(): array
    {
        // 🧪 Override path: scan the in-memory map.
        if (self::$storageOverride !== null) {
            $out = [];
            foreach (array_keys(self::$storageOverride['settings']) as $k) {
                if (str_starts_with($k, 'jwt.signing_key.') && str_ends_with($k, '.public_jwk')) {
                    $out[] = $k;
                }
            }
            return $out;
        }

        // 🗄️ Production path: query tblSettings.
        $rows = Database::fetchAll(
            "SELECT settingKey FROM tblSettings WHERE settingKey LIKE 'jwt.signing_key.%.public_jwk'"
        );
        return array_map(static fn(array $r): string => $r['settingKey'], $rows);
    }

    // ========================================================================
    // 🧮 JWK ↔ PEM HELPERS
    // ========================================================================

    /**
     * 🧱 Convert a public RSA JWK (n,e) to a PEM public key.
     *
     * Builds the DER (SubjectPublicKeyInfo) by hand for portability, then
     * base64-wraps it. Used so a verifier holding only the JWKS can obtain a PEM
     * OpenSSL can consume.
     *
     * @param array{n:string,e:string} $jwk
     * @return string|null PEM, or null on malformed input.
     */
    public static function jwkToPublicPem(array $jwk): ?string
    {
        if (!isset($jwk['n'], $jwk['e'])) {
            return null;
        }

        $modulus  = self::base64UrlDecode((string) $jwk['n']);
        $exponent = self::base64UrlDecode((string) $jwk['e']);
        if ($modulus === '' || $exponent === '') {
            return null;
        }

        // 🔢 DER-encode the RSAPublicKey SEQUENCE { modulus INTEGER, exponent INTEGER }.
        $modInt = self::derInteger($modulus);
        $expInt = self::derInteger($exponent);
        $rsaPublicKey = self::derSequence($modInt . $expInt);

        // 🔖 Wrap as SubjectPublicKeyInfo with the rsaEncryption OID.
        //    AlgorithmIdentifier: SEQUENCE { OID 1.2.840.113549.1.1.1, NULL }
        $algId = self::derSequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" // OID rsaEncryption
            . "\x05\x00"                                    // NULL
        );
        // BIT STRING wrapping the RSAPublicKey (leading 0x00 = unused bits).
        $bitString = "\x03" . self::derLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        $spki = self::derSequence($algId . $bitString);

        $pem = "-----BEGIN PUBLIC KEY-----\r\n"
            . chunk_split(base64_encode($spki), 64, "\r\n")
            . "-----END PUBLIC KEY-----\r\n";

        // ✅ Validate OpenSSL can actually parse what we built.
        $check = openssl_pkey_get_public($pem);
        if ($check === false) {
            return null;
        }

        return $pem;
    }

    /**
     * 🔢 DER-encode a positive INTEGER from a big-endian magnitude.
     *
     * Prepends 0x00 when the high bit is set (so the value stays positive).
     *
     * @param string $bytes Big-endian magnitude bytes.
     * @return string DER INTEGER TLV.
     */
    private static function derInteger(string $bytes): string
    {
        // Strip leading zero bytes (keep at least one byte).
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }
        // If the top bit is set, prepend a 0x00 to signal "unsigned/positive".
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }
        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    /**
     * 🔢 DER-encode a SEQUENCE around already-encoded contents.
     *
     * @param string $contents Concatenated DER TLVs.
     * @return string DER SEQUENCE TLV.
     */
    private static function derSequence(string $contents): string
    {
        return "\x30" . self::derLength(strlen($contents)) . $contents;
    }

    /**
     * 🔢 DER length encoding (short + long form).
     *
     * @param int $length Content length in bytes.
     * @return string DER length bytes.
     */
    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }
        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    // ========================================================================
    // 📁 KEY-FILE FALLBACK (web/_private/keys/jwt/)
    // ========================================================================

    /**
     * 📁 The key-file directory (override-aware).
     *
     * @return string
     */
    private static function keyDir(): string
    {
        if (self::$keyDirOverride !== null) {
            return self::$keyDirOverride;
        }
        // web/_private/keys/jwt/ — outside web root, `_`-prefixed per house rules.
        $base = defined('PRIVATE_DIR')
            ? PRIVATE_DIR
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_private';
        return $base . DIRECTORY_SEPARATOR . 'keys' . DIRECTORY_SEPARATOR . 'jwt';
    }

    /**
     * 💾 Write the private/public key files (0700 dir, 0600 files).
     *
     * Best-effort: failures are logged, never fatal (DB is the primary store).
     *
     * @param string $kid        Key id.
     * @param string $privatePem Private PEM (PLAINTEXT on disk — protected by 0600
     *                           + being outside the web root, mirroring how
     *                           web/_private/auth.php holds ENCRYPTION_KEY).
     * @param string $publicPem  Public PEM.
     * @return void
     */
    private static function writeKeyFiles(string $kid, string $privatePem, string $publicPem): void
    {
        try {
            $dir = self::keyDir();
            if (!is_dir($dir)) {
                // 0700 — owner-only. @ suppresses the race warning; we re-check below.
                @mkdir($dir, 0700, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                error_log('KeyManager: key dir not writable, skipping file mirror: ' . $dir);
                return;
            }

            $priv = $dir . DIRECTORY_SEPARATOR . $kid . '.key';
            $pub  = $dir . DIRECTORY_SEPARATOR . $kid . '.pub';

            if (file_put_contents($priv, $privatePem, LOCK_EX) !== false) {
                @chmod($priv, 0600);
            }
            if (file_put_contents($pub, $publicPem, LOCK_EX) !== false) {
                @chmod($pub, 0600);
            }
        } catch (\Throwable $e) {
            // 🔒 Never log key material — only the failure reason.
            error_log('KeyManager: writeKeyFiles failed for kid=' . $kid . ': ' . $e->getMessage());
        }
    }

    /**
     * 🗑️ Remove the mirrored key files for a kid (best-effort).
     *
     * @param string $kid Key id.
     * @return void
     */
    private static function deleteKeyFiles(string $kid): void
    {
        foreach (['.key', '.pub'] as $ext) {
            $f = self::keyDir() . DIRECTORY_SEPARATOR . $kid . $ext;
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    // ========================================================================
    // 🗄️ SETTINGS PERSISTENCE (DB or in-memory override)
    // ========================================================================

    /**
     * 📖 Read a setting value (override-aware).
     *
     * In production this reads from the already-loaded $GLOBALS['settings']
     * (via getSetting) for values loaded at bootstrap, but signing-key rows can
     * be created AFTER bootstrap, so it falls through to a direct tblSettings
     * read to guarantee freshness.
     *
     * @param string $key Setting key.
     * @return string|null Raw stored value (encrypted blob for sensitive rows),
     *                     or null if absent.
     */
    private static function readSetting(string $key): ?string
    {
        // 🧪 Override path.
        if (self::$storageOverride !== null) {
            return self::$storageOverride['settings'][$key] ?? null;
        }

        // 🗄️ Direct DB read (fresh — avoids stale bootstrap cache for keys minted
        //    this request). Returns the RAW settingValue (still encrypted for
        //    sensitive rows — getPrivateKey() decrypts when needed).
        $row = Database::fetchOne(
            "SELECT settingValue FROM tblSettings WHERE settingKey = ?",
            [$key],
            's'
        );
        return $row['settingValue'] ?? null;
    }

    /**
     * ✍️ Write (insert or update) a setting value (override-aware).
     *
     * @param string $key         Setting key.
     * @param string $value       Value to store (caller pre-encrypts sensitive).
     * @param bool   $isSensitive Whether the row is flagged sensitive.
     * @return void
     */
    private static function writeSetting(string $key, string $value, bool $isSensitive): void
    {
        // 🧪 Override path.
        if (self::$storageOverride !== null) {
            self::$storageOverride['settings'][$key] = $value;
            return;
        }

        // 🗄️ Upsert into tblSettings. Uses the real column names (settingCategory,
        //    NOT the spec's stale "category"; there is no isPublic column).
        Database::query(
            "INSERT INTO tblSettings (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
             VALUES (?, ?, ?, ?, 'jwt', ?)
             ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), isSensitive = VALUES(isSensitive)",
            [
                $key,
                $value,
                $isSensitive ? 'encrypted' : 'string',
                $isSensitive ? 1 : 0,
                'JWT signing-key material (' . ($isSensitive ? 'ENCRYPTED private PEM' : 'public JWK') . ')',
            ],
            'sssis'
        );
    }

    /**
     * 🗑️ Delete a setting row (override-aware).
     *
     * @param string $key Setting key.
     * @return void
     */
    private static function deleteSetting(string $key): void
    {
        if (self::$storageOverride !== null) {
            unset(self::$storageOverride['settings'][$key]);
            return;
        }
        Database::query("DELETE FROM tblSettings WHERE settingKey = ?", [$key], 's');
    }

    /**
     * 🆔 Read the active kid (override-aware).
     *
     * @return string '' if unset.
     */
    private static function readActiveKid(): string
    {
        $v = self::readSetting(self::ACTIVE_KID_KEY);
        return $v !== null ? (string) $v : '';
    }

    /**
     * 🆔 Write the active kid (override-aware).
     *
     * @param string $kid Key id.
     * @return void
     */
    private static function writeActiveKid(string $kid): void
    {
        self::writeSetting(self::ACTIVE_KID_KEY, $kid, false);
    }

    // ========================================================================
    // 🔧 SMALL UTILITIES
    // ========================================================================

    /**
     * 🔓 Decrypt a stored value if it is encrypted; return as-is if it already
     * looks like a PEM (the override path may store plaintext-in-a-blob for
     * tests, and production stores the ciphertext).
     *
     * @param string $stored Raw stored value.
     * @return string|null Decrypted/plaintext value, or null on failure.
     */
    private static function maybeDecrypt(string $stored): ?string
    {
        // Already a PEM? (override/test convenience) — return unchanged.
        if (self::looksLikePrivatePem($stored)) {
            return $stored;
        }
        try {
            return SecurityUtils::decrypt($stored);
        } catch (\Throwable $e) {
            // Negative-path decryption errors are expected in some tests; log only.
            error_log('KeyManager: private key decrypt failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 🔍 Cheap PEM shape check (does NOT validate the key cryptographically).
     *
     * @param string $s Candidate.
     * @return bool
     */
    private static function looksLikePrivatePem(string $s): bool
    {
        return str_contains($s, 'PRIVATE KEY');
    }

    /**
     * 🧾 Base64URL encode (RFC 4648 §5, no padding).
     *
     * @param string $data Raw bytes.
     * @return string
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * 🧾 Base64URL decode.
     *
     * @param string $data Base64URL string.
     * @return string Raw bytes ('' on failure).
     */
    public static function base64UrlDecode(string $data): string
    {
        $b64 = strtr($data, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($b64, true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * 🧯 Drain and format the OpenSSL error queue (for exception messages).
     *
     * @return string
     */
    private static function opensslErrors(): string
    {
        $errors = [];
        while (($e = openssl_error_string()) !== false) {
            $errors[] = $e;
        }
        return $errors ? implode('; ', $errors) : 'no OpenSSL error detail';
    }
}

// ✅ KeyManager loaded successfully
