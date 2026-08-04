<?php
/**
 * ============================================================================
 * 🗝️ SIGNula - SAML Signing Key Manager (RSA keypair + self-signed X.509 cert)
 * ============================================================================
 *
 * Purpose:
 *   Owns the lifecycle of the RSA signing keypair + self-signed X.509
 *   certificate used to sign SIGNula's outbound SAML `<Response>`/
 *   `<Assertion>` documents (G-001 Phase B, #100). This is a DELIBERATE
 *   sibling of {@see KeyManager} (G-003's JWT RS256 key manager) rather than
 *   a generalisation of it — the plan (§6.1) calls for a dedicated SAML
 *   signing cert, protocol-isolated from the JWT keys (a compromise of one
 *   protocol's signing material must never automatically compromise the
 *   other), and KeyManager's helpers are private + `jwt.`-namespaced, so a
 *   surgical copy is lower-risk than refactoring an already red-teamed class.
 *
 *   Responsibilities:
 *     • generateKey()  — mint a new RSA keypair + a SELF-SIGNED X.509
 *                        certificate (SAML requires an X.509 cert in
 *                        metadata/KeyInfo — a bare JWK is not enough), tag
 *                        it with a `kid`, store the PRIVATE key ENCRYPTED
 *                        (isSensitive) and the certificate PEM in the clear
 *                        (it is, by design, published in IdP metadata) in
 *                        tblSettings, and mirror both to the `_private`
 *                        key-file fallback.
 *     • getActiveKey() — the current signer (kid + private PEM + cert PEM),
 *                        DB-first, file-fallback.
 *     • getCertificate() — the certificate PEM for a given kid.
 *     • getAllCertificates() — every currently-stored certificate (active +
 *                        not-yet-retired) — feeds
 *                        SamlMetadataService::buildIdpMetadataDocument()'s
 *                        multiple `<KeyDescriptor>` elements during a
 *                        rotation overlap window (SPs cache metadata).
 *     • rotateKey()    — mint a new key + point active_kid at it (the OLD
 *                        key's certificate stays published in metadata
 *                        until retireKey() is called).
 *     • retireKey()    — drop an old key's certificate from metadata once no
 *                        SP can plausibly still be relying on it.
 *
 * Storage model (mirrors KeyManager's, §6.1's precedence order):
 *   1. tblSettings — saml.signing_key.<kid>.private_pem (isSensitive=1,
 *      encrypted at rest via SecurityUtils::encrypt(), decrypted on load by
 *      config.php's loadSettings()). Certificate PEM in
 *      saml.signing_key.<kid>.certificate_pem (isSensitive=0 — a public
 *      certificate is meant to be published). Active signer pointer in
 *      saml.signing_key.active_kid.
 *   2. `_private` PEM files — web/_private/keys/saml/<kid>.key (0600) and
 *      <kid>.crt (0600), dir 0700, outside web root, `_`-prefixed. Read when
 *      the DB row is absent/unreadable, mirroring how KeyManager's own
 *      `_private/keys/jwt/` fallback works.
 *
 *   The SAML signing key is a SEPARATE secret from BOTH ENCRYPTION_KEY (which
 *   encrypts the private PEM at rest) and the G-003 JWT signing keys
 *   (protocol isolation — see class header above).
 *
 * Testability:
 *   All persistence goes through the same small set of private helpers
 *   KeyManager uses (readSetting/writeSetting/deleteSetting/readActiveKid/
 *   writeActiveKid), defaulting to the real Database + config.php
 *   getSetting(), swappable for an in-memory store via
 *   setStorageOverride() so a future SamlKeyManagerTest can run with NO
 *   database. Production never calls setStorageOverride().
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). Requires ext-openssl.
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @link       https://www.php.net/manual/en/book.openssl.php
 * @link       https://www.php.net/manual/en/function.openssl-csr-sign.php
 * @link       https://docs.oasis-open.org/security/saml/v2.0/saml-metadata-2.0-os.pdf (SAML 2.0 Metadata — KeyDescriptor)
 *
 * @see web/private_html/security/KeyManager.php (the storage-pattern precedent this class mirrors)
 * @see web/private_html/auth/SamlMetadataService.php (publishes getAllCertificates() into IdP metadata)
 * @see web/private_html/security/SamlXmlSignature.php (consumes getActiveKey() to sign Assertions/Responses)
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
 * 🗝️ SAML signing-key + self-signed-certificate lifecycle manager.
 *
 * All methods are static (matches the KeyManager / SecurityUtils house style).
 */
class SamlKeyManager
{
    /**
     * @var int RSA key size in bits.
     *
     * Mirrors KeyManager's own default: RSA-2048 is the accepted floor,
     * RSA-3072 is used for longevity. Overridable via the saml.key_bits
     * setting for operators who need 2048 for performance reasons.
     * @see https://www.keylength.com/
     */
    private const DEFAULT_KEY_BITS = 3072;

    /** @var int Certificate validity in days (10 years — a long-lived self-signed IdP cert; rotation, not expiry, is the primary key-hygiene mechanism here). */
    private const CERT_VALIDITY_DAYS = 3650;

    /** @var string Certificate subject Common Name. */
    private const CERT_SUBJECT_CN = 'SIGNula SAML IdP';

    /** @var string tblSettings key holding the active signer's kid. */
    private const ACTIVE_KID_KEY = 'saml.signing_key.active_kid';

    /**
     * 🧪 Optional in-memory storage override for unit tests.
     *
     * @var array<string,array<string,string>>|null
     */
    private static ?array $storageOverride = null;

    /**
     * 📁 Optional key-file directory override (unit tests point this at a
     * temp dir). Null → the real PRIVATE_DIR/keys/saml location.
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
     * @param array<string,string> $settings Initial settings map (by value).
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
     * 🔨 Generate a new RSA keypair + self-signed X.509 certificate, and
     * persist both.
     *
     * Lazy-generation contract: callers (e.g. SamlResponseBuilder,
     * SamlMetadataService) call {@see self::getActiveKey()} unconditionally;
     * THIS method is invoked internally the first time no active key exists
     * yet — there is no separate "provisioning" step an operator must run.
     *
     * @param bool $makeActive Set this key as the active signer (default true).
     * @return string The `kid` of the newly generated key.
     * @throws RuntimeException On any OpenSSL or persistence failure.
     */
    public static function generateKey(bool $makeActive = true): string
    {
        // 🎲 Same kid shape as KeyManager's jwt.* keys — collision-resistant,
        //    sortable, never used as a raw filesystem path without the
        //    isValidKid() gate below re-checking it first.
        $kid = date('Ym') . '-' . bin2hex(random_bytes(6)); // e.g. 202608-1a2b3c4d5e6f

        $bits = (int) (getSetting('saml.key_bits', self::DEFAULT_KEY_BITS));
        if ($bits < 2048) {
            // 🛡️ Never below the RSA-2048 floor, whatever the setting says.
            $bits = 2048;
        }

        // 🔐 1) Generate the RSA keypair.
        $privateKeyResource = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($privateKeyResource === false) {
            throw new RuntimeException(
                'SamlKeyManager: openssl_pkey_new failed — ' . self::opensslErrors()
            );
        }

        // 📜 2) Build + self-sign an X.509 certificate around that keypair.
        //    SAML requires a certificate (not a bare public key) in metadata
        //    KeyDescriptor / <ds:KeyInfo><ds:X509Data> elements. A CSR + a
        //    self-issued signature (issuer == subject, $ca_cert = null) is
        //    all core PHP/openssl — no external CA and no CLI needed, which
        //    is Dreamhost-shared-hosting-safe.
        //
        //    ⚠️ Operational note: on some minimal PHP/openssl builds,
        //    openssl_csr_new()/openssl_csr_sign() consult an `openssl.cnf`
        //    that may not be discoverable. If that happens on a target host,
        //    pass an explicit ['config' => '/path/to/minimal-openssl.cnf'] in
        //    the $configArgs below (documented here rather than guessed at,
        //    since this environment's openssl.cnf resolves cleanly and the
        //    exact remediation is host-specific).
        $configArgs = ['digest_alg' => 'sha256'];

        $csr = openssl_csr_new(['CN' => self::CERT_SUBJECT_CN], $privateKeyResource, $configArgs);
        if ($csr === false) {
            throw new RuntimeException(
                'SamlKeyManager: openssl_csr_new failed — ' . self::opensslErrors()
            );
        }

        // 🎲 Non-guessable serial (a self-signed IdP cert has no external CA
        //    policy to satisfy, but a predictable serial is still needless
        //    fingerprinting surface).
        $serial = random_int(1, PHP_INT_MAX);

        $certResource = openssl_csr_sign($csr, null, $privateKeyResource, self::CERT_VALIDITY_DAYS, $configArgs, $serial);
        if ($certResource === false) {
            throw new RuntimeException(
                'SamlKeyManager: openssl_csr_sign failed — ' . self::opensslErrors()
            );
        }

        // 📤 3) Export both PEMs.
        $privatePem = '';
        if (!openssl_pkey_export($privateKeyResource, $privatePem)) {
            throw new RuntimeException(
                'SamlKeyManager: openssl_pkey_export failed — ' . self::opensslErrors()
            );
        }

        $certPem = '';
        if (!openssl_x509_export($certResource, $certPem)) {
            throw new RuntimeException(
                'SamlKeyManager: openssl_x509_export failed — ' . self::opensslErrors()
            );
        }

        // 💾 4) Persist: private (encrypted, isSensitive) + certificate (clear
        //    — it is, by design, meant to be published in IdP metadata).
        self::writeSetting(
            'saml.signing_key.' . $kid . '.private_pem',
            SecurityUtils::encrypt($privatePem),
            true // isSensitive
        );
        self::writeSetting(
            'saml.signing_key.' . $kid . '.certificate_pem',
            $certPem,
            false
        );

        // 📁 Mirror to the `_private` key-file fallback (best-effort — DB is
        //    the primary store; a file-write failure must not abort key
        //    generation, but IS logged).
        self::writeKeyFiles($kid, $privatePem, $certPem);

        // 🎯 Promote to active signer if requested.
        if ($makeActive) {
            self::writeActiveKid($kid);
        }

        return $kid;
    }

    /**
     * 🔄 Rotate the signing key.
     *
     * Mints a new key + certificate and makes it active. The OLD key's
     * certificate deliberately REMAINS published in metadata (via
     * {@see self::getAllCertificates()}) so Responses already in flight (or
     * SPs that have cached the old metadata) keep validating until
     * retireKey() is called — mirrors KeyManager::rotateKey()'s overlap
     * window rationale, applied to certificates instead of JWKS entries.
     *
     * @return string The new active kid.
     * @throws RuntimeException On failure.
     */
    public static function rotateKey(): string
    {
        return self::generateKey(true);
    }

    /**
     * 🗑️ Retire a key: remove its certificate from metadata + delete its
     * private key material.
     *
     * Refuses to retire the currently-active kid. Call this only once no SP
     * can plausibly still be relying on the old certificate (i.e. well past
     * their metadata refresh interval past the rotation).
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

        $hadCert = self::readSetting('saml.signing_key.' . $kid . '.certificate_pem') !== null;

        self::deleteSetting('saml.signing_key.' . $kid . '.certificate_pem');
        self::deleteSetting('saml.signing_key.' . $kid . '.private_pem');

        // 📁 Best-effort removal of the mirrored key files.
        self::deleteKeyFiles($kid);

        return $hadCert;
    }

    // ========================================================================
    // 🔍 KEY RETRIEVAL
    // ========================================================================

    /**
     * 🎯 Get the active signing key, LAZILY GENERATING one on first use if
     * none exists yet — mirrors the "mint a secret at runtime, never
     * hardcode it in a migration" precedent (migration 050's header note);
     * unlike KeyManager::getActiveKey() (which throws if unconfigured),
     * this method self-provisions so the very first `/saml/metadata` or
     * `/saml/sso` request (once `saml.enabled` is on) never 500s for want of
     * an operator having run a separate key-generation step.
     *
     * @return array{kid:string,private_pem:string,certificate_pem:string}
     * @throws RuntimeException If generation itself fails, or an active kid
     *                          is recorded but its material cannot be read.
     */
    public static function getActiveKey(): array
    {
        $kid = self::getActiveKid();
        if ($kid === '') {
            $kid = self::generateKey(true);
        }

        $privatePem = self::getPrivateKey($kid);
        $certPem    = self::getCertificate($kid);
        if ($privatePem === null || $certPem === null) {
            throw new RuntimeException('SamlKeyManager: active kid "' . $kid . '" has no loadable key material.');
        }

        return ['kid' => $kid, 'private_pem' => $privatePem, 'certificate_pem' => $certPem];
    }

    /**
     * 🔑 Get the private PEM for a kid (DB-first, file-fallback).
     *
     * @param string $kid Key id.
     * @return string|null Decrypted PEM, or null if not found.
     */
    public static function getPrivateKey(string $kid): ?string
    {
        // 🛡️ Same kid-traversal defence as KeyManager::getPrivateKey() (G-003
        //    §7.2 FIX 1) — even though a SAML kid is never attacker-supplied
        //    the way a JWT header kid is, this class is deliberately built to
        //    the SAME hardened standard rather than a weaker one because it
        //    is loaded before "we don't need it" can be fully proven.
        if (!self::isValidKid($kid)) {
            return null;
        }

        $stored = self::readSetting('saml.signing_key.' . $kid . '.private_pem');
        if ($stored !== null && $stored !== '') {
            $pem = self::maybeDecrypt($stored);
            if ($pem !== null && self::looksLikePrivatePem($pem)) {
                return $pem;
            }
        }

        $file = self::keyDir() . DIRECTORY_SEPARATOR . basename($kid) . '.key';
        if (is_file($file) && is_readable($file)) {
            $pem = file_get_contents($file);
            if ($pem !== false && self::looksLikePrivatePem($pem)) {
                return $pem;
            }
        }

        return null;
    }

    /**
     * 📜 Get the certificate PEM for a kid (DB-first, file-fallback).
     *
     * @param string $kid Key id.
     * @return string|null Certificate PEM, or null if not found.
     */
    public static function getCertificate(string $kid): ?string
    {
        if (!self::isValidKid($kid)) {
            return null;
        }

        $stored = self::readSetting('saml.signing_key.' . $kid . '.certificate_pem');
        if ($stored !== null && str_contains($stored, 'CERTIFICATE')) {
            return $stored;
        }

        $file = self::keyDir() . DIRECTORY_SEPARATOR . basename($kid) . '.crt';
        if (is_file($file) && is_readable($file)) {
            $pem = file_get_contents($file);
            if ($pem !== false && str_contains($pem, 'CERTIFICATE')) {
                return $pem;
            }
        }

        return null;
    }

    /**
     * 🆔 Get the active kid (or '' if none — {@see self::getActiveKey()}
     * lazily generates one; this raw accessor does NOT).
     *
     * @return string
     */
    public static function getActiveKid(): string
    {
        return self::readActiveKid();
    }

    // ========================================================================
    // 🌐 METADATA ASSEMBLY SUPPORT
    // ========================================================================

    /**
     * 📜 Every currently-stored certificate (active + not-yet-retired), for
     * SamlMetadataService to publish as multiple `<KeyDescriptor>` elements
     * during a rotation overlap window.
     *
     * @return array<string,string> kid => certificate PEM.
     */
    public static function getAllCertificates(): array
    {
        $out = [];

        foreach (self::listCertificateSettingKeys() as $settingKey) {
            $certPem = self::readSetting($settingKey);
            if ($certPem === null || !str_contains($certPem, 'CERTIFICATE')) {
                continue;
            }
            // saml.signing_key.<kid>.certificate_pem -> extract <kid>.
            $prefix = 'saml.signing_key.';
            $suffix = '.certificate_pem';
            if (str_starts_with($settingKey, $prefix) && str_ends_with($settingKey, $suffix)) {
                $kid = substr($settingKey, strlen($prefix), -strlen($suffix));
                $out[$kid] = $certPem;
            }
        }

        return $out;
    }

    /**
     * 🗂️ List all setting keys of the form saml.signing_key.<kid>.certificate_pem.
     *
     * @return string[] Setting keys.
     */
    private static function listCertificateSettingKeys(): array
    {
        if (self::$storageOverride !== null) {
            $out = [];
            foreach (array_keys(self::$storageOverride['settings']) as $k) {
                if (str_starts_with($k, 'saml.signing_key.') && str_ends_with($k, '.certificate_pem')) {
                    $out[] = $k;
                }
            }
            return $out;
        }

        $rows = Database::fetchAll(
            "SELECT settingKey FROM tblSettings WHERE settingKey LIKE 'saml.signing_key.%.certificate_pem'"
        );
        return array_map(static fn(array $r): string => $r['settingKey'], $rows);
    }

    // ========================================================================
    // 📁 KEY-FILE FALLBACK (web/_private/keys/saml/)
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
        $base = defined('PRIVATE_DIR')
            ? PRIVATE_DIR
            : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_private';
        return $base . DIRECTORY_SEPARATOR . 'keys' . DIRECTORY_SEPARATOR . 'saml';
    }

    /**
     * 💾 Write the private-key + certificate files (0700 dir, 0600 files).
     *
     * Best-effort: failures are logged, never fatal (DB is the primary store).
     *
     * @param string $kid        Key id.
     * @param string $privatePem Private PEM (PLAINTEXT on disk — protected
     *                           by 0600 + being outside the web root).
     * @param string $certPem    Certificate PEM.
     * @return void
     */
    private static function writeKeyFiles(string $kid, string $privatePem, string $certPem): void
    {
        try {
            $dir = self::keyDir();
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            if (!is_dir($dir) || !is_writable($dir)) {
                error_log('SamlKeyManager: key dir not writable, skipping file mirror: ' . $dir);
                return;
            }

            $priv = $dir . DIRECTORY_SEPARATOR . $kid . '.key';
            $cert = $dir . DIRECTORY_SEPARATOR . $kid . '.crt';

            if (file_put_contents($priv, $privatePem, LOCK_EX) !== false) {
                @chmod($priv, 0600);
            }
            if (file_put_contents($cert, $certPem, LOCK_EX) !== false) {
                @chmod($cert, 0600);
            }
        } catch (\Throwable $e) {
            // 🔒 Never log key material — only the failure reason.
            error_log('SamlKeyManager: writeKeyFiles failed for kid=' . $kid . ': ' . $e->getMessage());
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
        if (!self::isValidKid($kid)) {
            return;
        }
        foreach (['.key', '.crt'] as $ext) {
            $f = self::keyDir() . DIRECTORY_SEPARATOR . basename($kid) . $ext;
            if (is_file($f)) {
                @unlink($f);
            }
        }
    }

    // ========================================================================
    // 🗄️ SETTINGS PERSISTENCE (DB or in-memory override)
    // ========================================================================

    /**
     * 📖 Read a setting value (override-aware). Direct DB read (not the
     * bootstrap $GLOBALS['settings'] cache) so a key minted earlier in THIS
     * request is immediately visible — mirrors KeyManager::readSetting().
     *
     * @param string $key Setting key.
     * @return string|null Raw stored value, or null if absent.
     */
    private static function readSetting(string $key): ?string
    {
        if (self::$storageOverride !== null) {
            return self::$storageOverride['settings'][$key] ?? null;
        }

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
        if (self::$storageOverride !== null) {
            self::$storageOverride['settings'][$key] = $value;
            return;
        }

        Database::query(
            "INSERT INTO tblSettings (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
             VALUES (?, ?, ?, ?, 'saml', ?)
             ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue), isSensitive = VALUES(isSensitive)",
            [
                $key,
                $value,
                $isSensitive ? 'encrypted' : 'string',
                $isSensitive ? 1 : 0,
                'SAML signing-key material (' . ($isSensitive ? 'ENCRYPTED private PEM' : 'public certificate PEM') . ')',
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
     * 🔓 Decrypt a stored value if it is encrypted; return as-is if it
     * already looks like a PEM (test/override convenience).
     *
     * @param string $stored Raw stored value.
     * @return string|null Decrypted/plaintext value, or null on failure.
     */
    private static function maybeDecrypt(string $stored): ?string
    {
        if (self::looksLikePrivatePem($stored)) {
            return $stored;
        }
        try {
            return SecurityUtils::decrypt($stored);
        } catch (\Throwable $e) {
            error_log('SamlKeyManager: private key decrypt failed: ' . $e->getMessage());
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
     * 🛡️ Validate a `kid` before it is EVER used to compose a filesystem
     * path. Same charset/traversal rules as KeyManager::isValidKid() —
     * see that method's docblock for the full rationale.
     *
     * @param string $kid Candidate key id.
     * @return bool True only if the kid is strictly path-safe.
     * @link https://owasp.org/www-community/attacks/Path_Traversal
     */
    private static function isValidKid(string $kid): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $kid) === 1
            && !str_contains($kid, '..');
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

// ✅ SamlKeyManager loaded successfully
