<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Minimal CBOR / COSE Helper for WebAuthn (FIDO2)
 * ============================================================================
 *
 * Purpose:
 *   A TARGETED, self-hosted CBOR decoder sufficient for the two CBOR structures
 *   WebAuthn requires us to parse on the server:
 *
 *     1. The attestation object (a CBOR map: { fmt, attStmt, authData }).
 *     2. The COSE_Key for the credential public key (a CBOR map of small
 *        integer labels → values), embedded in the attestedCredentialData.
 *
 *   These are small, well-defined, canonically-encoded maps. A full third-party
 *   CBOR library is NOT required for this scope — only the CBOR major types that
 *   actually appear in these structures are implemented:
 *
 *     - Major type 0 : unsigned integer
 *     - Major type 1 : negative integer (COSE labels -1, -2, -3 …)
 *     - Major type 2 : byte string (n, e, x, y coordinates, raw attStmt blobs)
 *     - Major type 3 : text string (map keys like "fmt", "attStmt", "authData")
 *     - Major type 4 : array (attStmt x5c certificate chains)
 *     - Major type 5 : map (the attestation map and the COSE key)
 *
 *   Major types 6 (tag) and 7 (float/simple) are intentionally NOT supported;
 *   they do not appear in COSE keys or "none"/"packed" attestation maps. If an
 *   unsupported type is encountered we throw — fail-closed, never guess.
 *
 *   ⚠️ Scope note: this DECODES CBOR and converts a COSE key to a PEM public key.
 *   It does NOT perform attestation-statement (x5c / MDS) trust validation —
 *   that is a documented follow-on (see WebAuthnHandler / NEEDS-LEAD-REVIEW).
 *
 * Why self-hosted (no Composer in prod):
 *   The project runs on Dreamhost shared hosting with no CLI/Composer. Per the
 *   project's _lib self-host pattern (see web/_lib/), small dependencies are
 *   vendored as plain PHP requiring no build step.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4)
 *
 * Standards:
 *   - RFC 8949 (CBOR)            https://www.rfc-editor.org/rfc/rfc8949
 *   - RFC 9052 / RFC 8152 (COSE) https://www.rfc-editor.org/rfc/rfc9052
 *   - W3C WebAuthn L2 §6.5.2     https://www.w3.org/TR/webauthn-2/#sctn-attested-credential-data
 *
 * @package    SIGNula
 * @subpackage Authentication\WebAuthn
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access (class file → SIGNULA_INIT guard per project rules)
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🔐 WebAuthnCbor
 *
 * Static utility: minimal CBOR decoding + COSE-key → PEM conversion.
 * Stateless; all methods are pure functions of their byte-string input.
 */
final class WebAuthnCbor
{
    // ------------------------------------------------------------------
    // 🔢 COSE key common labels (RFC 9052 §7.1 / §13) used below
    // ------------------------------------------------------------------
    public const COSE_KTY    = 1;   // Key type
    public const COSE_ALG    = 3;   // Algorithm
    public const COSE_CRV    = -1;  // EC2: curve
    public const COSE_X      = -2;  // EC2: x-coordinate   | RSA: n (modulus)
    public const COSE_Y      = -3;  // EC2: y-coordinate   | RSA: e (exponent)
    public const COSE_RSA_N  = -1;  // RSA: modulus  (shares slot with EC2 crv)
    public const COSE_RSA_E  = -2;  // RSA: exponent (shares slot with EC2 x)

    public const KTY_EC2 = 2;   // Elliptic Curve keys (ES256 etc.)
    public const KTY_RSA = 3;   // RSA keys (RS256 etc.)

    public const ALG_ES256 = -7;    // ECDSA w/ SHA-256 (P-256)
    public const ALG_RS256 = -257;  // RSASSA-PKCS1-v1_5 w/ SHA-256

    public const CRV_P256 = 1;  // NIST P-256 (secp256r1)

    /**
     * 🎯 Decode a complete CBOR data item from a byte string.
     *
     * @param string $data Raw CBOR bytes.
     * @return mixed Decoded value (int|string|array).
     * @throws RuntimeException On malformed / trailing / unsupported CBOR.
     */
    public static function decode(string $data): mixed
    {
        $offset = 0;
        $value  = self::decodeItem($data, $offset);

        // ✅ Canonical structures must consume ALL input — reject trailing bytes.
        if ($offset !== strlen($data)) {
            throw new RuntimeException('CBOR: trailing bytes after top-level item');
        }

        return $value;
    }

    /**
     * 🎯 Decode a CBOR item starting at &$offset, advancing $offset past it.
     *
     * Internal recursive worker. Public-by-necessity is avoided; this is
     * private and only reached through decode() / decodeFirst().
     *
     * @param string $data   Raw CBOR bytes.
     * @param int    $offset Current read position (modified by reference).
     * @return mixed
     * @throws RuntimeException
     */
    private static function decodeItem(string $data, int &$offset): mixed
    {
        if ($offset >= strlen($data)) {
            throw new RuntimeException('CBOR: unexpected end of input');
        }

        // 📥 First byte: high 3 bits = major type, low 5 bits = additional info.
        $initialByte    = ord($data[$offset]);
        $majorType      = $initialByte >> 5;
        $additionalInfo = $initialByte & 0x1F;
        $offset++;

        // 🔢 Resolve the argument (length / value) per the additional-info rules.
        $argument = self::readArgument($data, $offset, $additionalInfo);

        switch ($majorType) {
            case 0: // unsigned integer
                return $argument;

            case 1: // negative integer: encoded value is (-1 - n)
                return -1 - $argument;

            case 2: // byte string
                return self::readBytes($data, $offset, $argument);

            case 3: // text string (we treat identically to bytes; keys are ASCII)
                return self::readBytes($data, $offset, $argument);

            case 4: // array
                $array = [];
                for ($i = 0; $i < $argument; $i++) {
                    $array[] = self::decodeItem($data, $offset);
                }
                return $array;

            case 5: // map
                $map = [];
                for ($i = 0; $i < $argument; $i++) {
                    // Keys in COSE / attestation maps are ints or short text.
                    $key   = self::decodeItem($data, $offset);
                    $value = self::decodeItem($data, $offset);

                    // Normalise key to a usable array index (int stays int,
                    // text string stays string). PHP array keys handle both.
                    $map[$key] = $value;
                }
                return $map;

            default:
                // 🚫 Major types 6 (tag) and 7 (float/simple) are out of scope
                //    and must NOT appear in the structures we parse.
                throw new RuntimeException(
                    'CBOR: unsupported major type ' . $majorType
                );
        }
    }

    /**
     * 🔢 Read the integer argument that follows the initial byte.
     *
     * Per RFC 8949 §3: additional info 0–23 is the value itself; 24/25/26/27
     * mean the value is in the next 1/2/4/8 bytes (big-endian). 28–31 are
     * reserved / indefinite-length and are rejected (canonical CBOR only).
     *
     * @param string $data
     * @param int    $offset Modified by reference.
     * @param int    $additionalInfo Low 5 bits of the initial byte.
     * @return int
     * @throws RuntimeException
     */
    private static function readArgument(string $data, int &$offset, int $additionalInfo): int
    {
        if ($additionalInfo < 24) {
            return $additionalInfo;
        }

        switch ($additionalInfo) {
            case 24: // 1 byte
                $bytes = self::readBytes($data, $offset, 1);
                return ord($bytes);

            case 25: // 2 bytes (uint16 BE)
                $bytes = self::readBytes($data, $offset, 2);
                return (ord($bytes[0]) << 8) | ord($bytes[1]);

            case 26: // 4 bytes (uint32 BE)
                $bytes  = self::readBytes($data, $offset, 4);
                $result = 0;
                for ($i = 0; $i < 4; $i++) {
                    $result = ($result << 8) | ord($bytes[$i]);
                }
                return $result;

            case 27: // 8 bytes (uint64 BE) — clamp to PHP_INT for safety
                $bytes  = self::readBytes($data, $offset, 8);
                $result = 0;
                for ($i = 0; $i < 8; $i++) {
                    // On 64-bit PHP this is safe for the small lengths we see.
                    $result = ($result << 8) | ord($bytes[$i]);
                }
                if ($result < 0) {
                    throw new RuntimeException('CBOR: 64-bit length overflow');
                }
                return $result;

            default:
                // 28–31: reserved or indefinite-length — not allowed here.
                throw new RuntimeException(
                    'CBOR: unsupported additional info ' . $additionalInfo
                );
        }
    }

    /**
     * 📥 Read exactly $length raw bytes, advancing the offset.
     *
     * @param string $data
     * @param int    $offset Modified by reference.
     * @param int    $length
     * @return string
     * @throws RuntimeException When fewer than $length bytes remain.
     */
    private static function readBytes(string $data, int &$offset, int $length): string
    {
        if ($length < 0 || $offset + $length > strlen($data)) {
            throw new RuntimeException('CBOR: byte string length exceeds input');
        }

        $bytes   = substr($data, $offset, $length);
        $offset += $length;

        return $bytes;
    }

    /**
     * 🎯 Decode the FIRST CBOR item and return how many bytes it consumed.
     *
     * Needed for the COSE key embedded in attestedCredentialData: the COSE key
     * is "the remaining bytes" of authData, but a robust parse decodes exactly
     * one map and ignores any trailing extension data CBOR that may follow.
     *
     * @param string $data
     * @param int    &$consumed Receives the number of bytes the item used.
     * @return mixed
     * @throws RuntimeException
     */
    public static function decodeFirst(string $data, int &$consumed): mixed
    {
        $offset   = 0;
        $value    = self::decodeItem($data, $offset);
        $consumed = $offset;

        return $value;
    }

    // ======================================================================
    // 🔑 COSE KEY → PEM PUBLIC KEY
    // ======================================================================

    /**
     * 🔑 Convert a decoded COSE_Key map to a PEM-encoded public key.
     *
     * Supports the two algorithms SIGNula offers in pubKeyCredParams:
     *   - ES256 (kty=EC2/2, crv=P-256/1, x=-2, y=-3)
     *   - RS256 (kty=RSA/3, n=-1, e=-2)
     *
     * The PEM produced is a SubjectPublicKeyInfo (SPKI) DER wrapped in
     * "-----BEGIN PUBLIC KEY-----", which openssl_verify() accepts directly.
     *
     * @param array $coseKey Decoded COSE key (int-labelled map from decode()).
     * @return array{pem:string,alg:int} PEM string + COSE alg id for the caller.
     * @throws RuntimeException On unsupported / malformed key material.
     */
    public static function coseKeyToPem(array $coseKey): array
    {
        if (!isset($coseKey[self::COSE_KTY])) {
            throw new RuntimeException('COSE key: missing kty (label 1)');
        }

        $kty = (int) $coseKey[self::COSE_KTY];

        switch ($kty) {
            case self::KTY_EC2:
                return self::ec2KeyToPem($coseKey);

            case self::KTY_RSA:
                return self::rsaKeyToPem($coseKey);

            default:
                throw new RuntimeException('COSE key: unsupported kty ' . $kty);
        }
    }

    /**
     * 🔑 Build an EC P-256 SPKI PEM from a COSE EC2 key.
     *
     * DER layout (SPKI for an EC public key) — fixed prefix for id-ecPublicKey
     * with the P-256 named curve, followed by the uncompressed point
     * 0x04 || X(32) || Y(32). Hand-building this header is standard practice
     * and avoids needing a DER encoder for this one fixed case.
     *
     * @param array $coseKey
     * @return array{pem:string,alg:int}
     * @throws RuntimeException
     */
    private static function ec2KeyToPem(array $coseKey): array
    {
        $crv = (int) ($coseKey[self::COSE_CRV] ?? 0);
        if ($crv !== self::CRV_P256) {
            throw new RuntimeException('COSE EC2 key: unsupported curve ' . $crv);
        }

        $x = $coseKey[self::COSE_X] ?? null;
        $y = $coseKey[self::COSE_Y] ?? null;

        if (!is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('COSE EC2 key: x/y must be 32-byte coordinates');
        }

        // 🧱 SPKI DER prefix for an id-ecPublicKey on prime256v1 (P-256),
        //    BIT STRING of 0x42 = 66 bytes (0x00 unused-bits + 0x04 + 64 coord).
        //    Ref: the well-known constant SPKI header for P-256 public keys.
        $derPrefix = "\x30\x59"                              // SEQUENCE, 0x59 bytes
            . "\x30\x13"                                     //   SEQUENCE, 0x13 bytes
            . "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"         //     OID id-ecPublicKey
            . "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"     //     OID prime256v1
            . "\x03\x42\x00"                                 //   BIT STRING (66 bytes, 0 unused)
            . "\x04";                                        //     uncompressed point marker

        $der = $derPrefix . $x . $y;

        return [
            'pem' => self::derToPem($der),
            'alg' => self::ALG_ES256,
        ];
    }

    /**
     * 🔑 Build an RSA SPKI PEM from a COSE RSA key.
     *
     * Encodes RSAPublicKey ::= SEQUENCE { modulus INTEGER, exponent INTEGER }
     * then wraps it in the SPKI rsaEncryption SubjectPublicKeyInfo. A tiny,
     * self-contained DER encoder is used (length + INTEGER helpers only).
     *
     * @param array $coseKey
     * @return array{pem:string,alg:int}
     * @throws RuntimeException
     */
    private static function rsaKeyToPem(array $coseKey): array
    {
        $n = $coseKey[self::COSE_RSA_N] ?? null; // modulus  (label -1)
        $e = $coseKey[self::COSE_RSA_E] ?? null; // exponent (label -2)

        if (!is_string($n) || !is_string($e) || $n === '' || $e === '') {
            throw new RuntimeException('COSE RSA key: missing modulus/exponent');
        }

        // 🧱 RSAPublicKey ::= SEQUENCE { n INTEGER, e INTEGER }
        $rsaPublicKey = self::derSequence(
            self::derInteger($n) . self::derInteger($e)
        );

        // 🧱 AlgorithmIdentifier for rsaEncryption: SEQUENCE { OID, NULL }
        $algId = self::derSequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"  // OID 1.2.840.113549.1.1.1
            . "\x05\x00"                                     // NULL parameters
        );

        // 🧱 SubjectPublicKeyInfo ::= SEQUENCE { algId, BIT STRING { RSAPublicKey } }
        $spki = self::derSequence(
            $algId . self::derBitString($rsaPublicKey)
        );

        return [
            'pem' => self::derToPem($spki),
            'alg' => self::ALG_RS256,
        ];
    }

    // ----------------------------------------------------------------------
    // 🧱 Minimal DER encoding helpers (only what coseKeyToPem needs)
    // ----------------------------------------------------------------------

    /**
     * 🧱 Encode a DER length prefix (short or long form) for $len bytes.
     */
    private static function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }

        // Long form: 0x80 | number-of-length-bytes, then the length big-endian.
        $bytes = '';
        while ($len > 0) {
            $bytes  = chr($len & 0xFF) . $bytes;
            $len  >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * 🧱 Wrap content in a DER SEQUENCE (tag 0x30).
     */
    private static function derSequence(string $content): string
    {
        return "\x30" . self::derLength(strlen($content)) . $content;
    }

    /**
     * 🧱 Encode a DER INTEGER (tag 0x02) from a raw big-endian magnitude.
     *
     * Strips leading 0x00 padding, then re-adds a single 0x00 if the high bit
     * is set (so the integer stays positive / unsigned) per DER rules.
     */
    private static function derInteger(string $bytes): string
    {
        // Trim leading zero bytes (but keep at least one byte).
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }

        // If the MSB is set, prepend 0x00 to mark it as a positive integer.
        if (ord($bytes[0]) & 0x80) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::derLength(strlen($bytes)) . $bytes;
    }

    /**
     * 🧱 Wrap content in a DER BIT STRING (tag 0x03) with 0 unused bits.
     */
    private static function derBitString(string $content): string
    {
        $content = "\x00" . $content; // 0 unused bits
        return "\x03" . self::derLength(strlen($content)) . $content;
    }

    /**
     * 🧱 Wrap DER bytes into a PEM "PUBLIC KEY" block.
     *
     * @param string $der Raw DER (SubjectPublicKeyInfo).
     * @return string PEM with BEGIN/END armor and 64-char wrapped base64.
     */
    private static function derToPem(string $der): string
    {
        $base64 = base64_encode($der);
        $lines  = chunk_split($base64, 64, PHP_EOL);

        return '-----BEGIN PUBLIC KEY-----' . PHP_EOL
            . $lines
            . '-----END PUBLIC KEY-----' . PHP_EOL;
    }
}

// ✅ WebAuthnCbor helper loaded successfully
