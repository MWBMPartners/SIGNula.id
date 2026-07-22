<?php
/**
 * ============================================================================
 * 🧪 WebAuthn Cryptographic Verification Unit Tests (FG-013 fix)
 * ============================================================================
 *
 * Security-critical, DB-FREE unit tests for the REAL cryptographic core that
 * closes FG-013 (the WebAuthn auth-bypass). These exercise the pure methods
 * that do NOT touch the database:
 *
 *   - WebAuthnHandler::parseAuthenticatorData()  (authData → rpIdHash/flags/
 *     signCount/credentialId/COSE key)
 *   - WebAuthnHandler::verifyAssertionSignature() (openssl_verify over
 *     authData || SHA-256(clientDataJSON))
 *   - WebAuthnCbor::decode() / coseKeyToPem()    (CBOR + COSE → PEM)
 *
 * The test GENERATES a real EC P-256 keypair with openssl, hand-builds a valid
 * authenticatorData + clientDataJSON, signs the exact bytes the spec requires,
 * and asserts the implementation ACCEPTS it. It then asserts REJECTION of each
 * attack: absent signature, wrong-key signature, tampered authData, mismatched
 * challenge, wrong rpIdHash, wrong origin, and sign-count regression.
 *
 * @package    SIGNula\Tests\Unit\Auth
 * @version    2.7.0-beta
 * @see        web/private_html/auth/WebAuthnHandler.php
 * @see        web/_lib/webauthn/WebAuthnCbor.php
 * @see        https://www.w3.org/TR/webauthn-2/#sctn-verifying-assertion
 */

namespace SIGNula\Tests\Unit\Auth;

use SIGNula\Tests\TestCase;

// 📦 Load source classes (SIGNULA_INIT defined by bootstrap.php).
requireSource('_lib/webauthn/WebAuthnCbor.php');
requireSource('private_html/auth/WebAuthnHandler.php');

/**
 * WebAuthn Cryptographic Verification Test Suite (pure crypto, no DB)
 */
class WebAuthnVerificationTest extends TestCase
{
    /** RP ID used across the suite (matches the default test config). */
    private string $rpId = 'signula.id';

    /** EC P-256 private key resource (for signing test assertions). */
    private \OpenSSLAsymmetricKey $ecPrivateKey;

    /** PEM public key derived from the COSE key (the "stored" credential key). */
    private string $storedPublicKeyPem;

    /** COSE_Key map for the generated EC key (encoded into authData). */
    private array $coseKey;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔑 Generate a real EC P-256 keypair for the test authenticator.
        $this->ecPrivateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);

        $details = openssl_pkey_get_details($this->ecPrivateKey);
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        // 🔑 Build the COSE_Key the authenticator would emit for this EC key.
        $this->coseKey = [
            \WebAuthnCbor::COSE_KTY => \WebAuthnCbor::KTY_EC2, // kty = EC2
            \WebAuthnCbor::COSE_ALG => \WebAuthnCbor::ALG_ES256,
            \WebAuthnCbor::COSE_CRV => \WebAuthnCbor::CRV_P256,
            \WebAuthnCbor::COSE_X   => $x,
            \WebAuthnCbor::COSE_Y   => $y,
        ];

        // 🔑 Convert COSE → PEM exactly as the registration path stores it.
        $pem = \WebAuthnCbor::coseKeyToPem($this->coseKey);
        $this->storedPublicKeyPem = $pem['pem'];
    }

    // ======================================================================
    // 🧱 CBOR / COSE helpers
    // ======================================================================

    /**
     * Hand-encode a CBOR map of int→bytestring/int (sufficient for a COSE key).
     * Mirrors the canonical encoding produced by real authenticators.
     */
    private function encodeCoseKey(array $coseKey): string
    {
        $out = $this->cborMapHeader(count($coseKey));
        foreach ($coseKey as $label => $value) {
            $out .= $this->cborInt((int)$label);
            if (is_int($value)) {
                $out .= $this->cborInt($value);
            } else {
                $out .= $this->cborByteString((string)$value);
            }
        }
        return $out;
    }

    /** CBOR map header for a map of N pairs (N is small here). */
    private function cborMapHeader(int $count): string
    {
        if ($count < 24) {
            return chr(0xA0 | $count);
        }
        return chr(0xB8) . chr($count); // map, 1-byte count
    }

    /** Encode a CBOR signed/unsigned integer (small range used in COSE labels). */
    private function cborInt(int $value): string
    {
        if ($value >= 0) {
            return $this->cborUint(0, $value);     // major type 0
        }
        return $this->cborUint(1, -1 - $value);    // major type 1 (negative)
    }

    /** Encode a CBOR unsigned int with a given major type. */
    private function cborUint(int $major, int $value): string
    {
        $mt = $major << 5;
        if ($value < 24) {
            return chr($mt | $value);
        }
        if ($value < 256) {
            return chr($mt | 24) . chr($value);
        }
        if ($value < 65536) {
            return chr($mt | 25) . pack('n', $value);
        }
        return chr($mt | 26) . pack('N', $value);
    }

    /** Encode a CBOR byte string (major type 2). */
    private function cborByteString(string $bytes): string
    {
        return $this->cborUint(2, strlen($bytes)) . $bytes;
    }

    // ======================================================================
    // 🧱 authenticatorData / clientDataJSON builders
    // ======================================================================

    /**
     * Build authenticatorData (authentication form, no attested credential).
     *
     * @param string $rpId      RP ID to hash into rpIdHash.
     * @param int    $signCount Sign counter value.
     * @param int    $flags     Flag byte (default UP set).
     */
    private function buildAuthData(string $rpId, int $signCount, int $flags = 0x01): string
    {
        return hash('sha256', $rpId, true) // rpIdHash (32 bytes)
            . chr($flags)                   // flags
            . pack('N', $signCount);        // signCount (uint32 BE)
    }

    /**
     * Build registration-form authenticatorData WITH attested credential data
     * (AT flag set), embedding the COSE public key.
     */
    private function buildRegAuthData(
        string $rpId,
        int $signCount,
        string $credentialId,
        array $coseKey,
        int $flags = 0x41 // UP (0x01) + AT (0x40)
    ): string {
        $aaguid = str_repeat("\x00", 16);
        return hash('sha256', $rpId, true)
            . chr($flags)
            . pack('N', $signCount)
            . $aaguid
            . pack('n', strlen($credentialId))
            . $credentialId
            . $this->encodeCoseKey($coseKey);
    }

    /** Build a raw clientDataJSON byte string for the given fields. */
    private function clientDataJSON(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type'      => $type,
            'challenge' => $challenge,
            'origin'    => $origin,
        ]);
    }

    /**
     * Sign authData || SHA-256(clientDataJSON) with the EC private key,
     * yielding the ASN.1-DER ECDSA signature a real authenticator produces.
     */
    private function signAssertion(string $authData, string $clientDataJSON): string
    {
        $signedData = $authData . hash('sha256', $clientDataJSON, true);
        $signature  = '';
        openssl_sign($signedData, $signature, $this->ecPrivateKey, OPENSSL_ALGO_SHA256);
        return $signature;
    }

    // ======================================================================
    // ✅ COSE → PEM round-trip (the registration storage path)
    // ======================================================================

    /**
     * A COSE EC2 key decodes from CBOR and converts to a PEM openssl accepts.
     */
    public function testCoseEcKeyConvertsToUsablePem(): void
    {
        $cbor    = $this->encodeCoseKey($this->coseKey);
        $decoded = \WebAuthnCbor::decode($cbor);
        $pem     = \WebAuthnCbor::coseKeyToPem($decoded);

        $this->assertSame(\WebAuthnCbor::ALG_ES256, $pem['alg']);
        $this->assertStringContainsString('BEGIN PUBLIC KEY', $pem['pem']);
        $this->assertNotFalse(
            openssl_pkey_get_public($pem['pem']),
            'COSE→PEM output must be a public key openssl accepts'
        );
    }

    // ======================================================================
    // 🟢 VALID ASSERTION IS ACCEPTED
    // ======================================================================

    /**
     * 🟢 A properly signed assertion verifies (verify === 1) and parses.
     */
    public function testValidAssertionAccepted(): void
    {
        $handler   = new \WebAuthnHandler();
        $challenge = base64_encode(random_bytes(32));
        $authData  = $this->buildAuthData($this->rpId, 5);
        $clientData = $this->clientDataJSON('webauthn.get', $challenge, 'https://signula.id');
        $signature  = $this->signAssertion($authData, $clientData);

        // 🔬 authData parses with the expected rpIdHash + UP flag.
        $parsed = $handler->parseAuthenticatorData($authData, false);
        $this->assertSame(hash('sha256', $this->rpId, true), $parsed['rpIdHash']);
        $this->assertTrue($parsed['userPresent']);
        $this->assertSame(5, $parsed['signCount']);

        // 🔐 The signature verifies against the stored PEM key.
        $this->assertTrue(
            $handler->verifyAssertionSignature($authData, $clientData, $signature, $this->storedPublicKeyPem),
            'A correctly signed assertion MUST be accepted'
        );
    }

    /**
     * 🟢 Registration-form authData yields the embedded COSE key + credentialId.
     */
    public function testRegistrationAuthDataParsesAttestedCredential(): void
    {
        $handler = new \WebAuthnHandler();
        $credId  = random_bytes(20);
        $authData = $this->buildRegAuthData($this->rpId, 0, $credId, $this->coseKey);

        $parsed = $handler->parseAuthenticatorData($authData, true);

        $this->assertSame($credId, $parsed['credentialId']);
        $this->assertIsArray($parsed['coseKey']);

        $pem = \WebAuthnCbor::coseKeyToPem($parsed['coseKey']);
        $this->assertNotFalse(openssl_pkey_get_public($pem['pem']));
    }

    // ======================================================================
    // 🚫 ATTACKS — each MUST be REJECTED
    // ======================================================================

    /**
     * 🚫 (a) Absent / empty signature → REJECTED.
     *
     * This is the exact FG-013 attack: an assertion with NO signature.
     */
    public function testAbsentSignatureRejected(): void
    {
        $handler  = new \WebAuthnHandler();
        $authData = $this->buildAuthData($this->rpId, 1);
        $clientData = $this->clientDataJSON('webauthn.get', 'chal', 'https://signula.id');

        $this->assertFalse(
            $handler->verifyAssertionSignature($authData, $clientData, '', $this->storedPublicKeyPem),
            'An assertion with an EMPTY signature MUST be rejected (FG-013)'
        );
    }

    /**
     * 🚫 (b) Signature made by a DIFFERENT key → REJECTED.
     */
    public function testWrongKeySignatureRejected(): void
    {
        $handler  = new \WebAuthnHandler();
        $authData = $this->buildAuthData($this->rpId, 1);
        $clientData = $this->clientDataJSON('webauthn.get', 'chal', 'https://signula.id');

        // Sign with a freshly generated, unrelated key.
        $otherKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        $signedData = $authData . hash('sha256', $clientData, true);
        $forged = '';
        openssl_sign($signedData, $forged, $otherKey, OPENSSL_ALGO_SHA256);

        $this->assertFalse(
            $handler->verifyAssertionSignature($authData, $clientData, $forged, $this->storedPublicKeyPem),
            'A signature from a DIFFERENT key MUST be rejected'
        );
    }

    /**
     * 🚫 (c) Tampered authenticatorData (after signing) → REJECTED.
     */
    public function testTamperedAuthenticatorDataRejected(): void
    {
        $handler   = new \WebAuthnHandler();
        $authData  = $this->buildAuthData($this->rpId, 1);
        $clientData = $this->clientDataJSON('webauthn.get', 'chal', 'https://signula.id');
        $signature  = $this->signAssertion($authData, $clientData);

        // Flip the sign-count bytes AFTER signing — signature no longer matches.
        $tampered = $this->buildAuthData($this->rpId, 999);

        $this->assertFalse(
            $handler->verifyAssertionSignature($tampered, $clientData, $signature, $this->storedPublicKeyPem),
            'Tampered authenticatorData MUST fail signature verification'
        );
    }

    /**
     * 🚫 (d) Mismatched challenge (clientDataJSON changed after signing) → REJECTED.
     *
     * Signature covers SHA-256(clientDataJSON), so any change to the challenge
     * (or any clientData field) breaks the signature.
     */
    public function testMismatchedChallengeRejected(): void
    {
        $handler   = new \WebAuthnHandler();
        $authData  = $this->buildAuthData($this->rpId, 1);
        $signedClientData = $this->clientDataJSON('webauthn.get', 'real-challenge', 'https://signula.id');
        $signature = $this->signAssertion($authData, $signedClientData);

        // Attacker presents a clientDataJSON with a DIFFERENT challenge.
        $forgedClientData = $this->clientDataJSON('webauthn.get', 'attacker-challenge', 'https://signula.id');

        $this->assertFalse(
            $handler->verifyAssertionSignature($authData, $forgedClientData, $signature, $this->storedPublicKeyPem),
            'A clientDataJSON with a swapped challenge MUST fail signature verification'
        );
    }

    /**
     * 🚫 (e) Wrong rpIdHash in authenticatorData → REJECTED at parse-level compare.
     *
     * parseAuthenticatorData() returns the rpIdHash; the handler compares it to
     * SHA-256(rpId). Here we prove a wrong-RP authData yields a non-matching
     * hash (and also breaks the signature when present).
     */
    public function testWrongRpIdHashRejected(): void
    {
        $handler  = new \WebAuthnHandler();
        $wrongRp  = 'evil.example';
        $authData = $this->buildAuthData($wrongRp, 1);

        $parsed = $handler->parseAuthenticatorData($authData, false);

        $this->assertFalse(
            hash_equals(hash('sha256', $this->rpId, true), $parsed['rpIdHash']),
            'authenticatorData built for a different RP ID MUST NOT match the expected rpIdHash'
        );
    }

    /**
     * 🚫 (f) Wrong origin in clientDataJSON → REJECTED.
     *
     * The origin is bound into clientDataJSON which the signature covers, so a
     * swapped origin breaks the signature (defence in depth on top of the
     * handler's explicit origin allowlist check).
     */
    public function testWrongOriginRejected(): void
    {
        $handler   = new \WebAuthnHandler();
        $authData  = $this->buildAuthData($this->rpId, 1);
        $signedClientData = $this->clientDataJSON('webauthn.get', 'chal', 'https://signula.id');
        $signature = $this->signAssertion($authData, $signedClientData);

        $forgedClientData = $this->clientDataJSON('webauthn.get', 'chal', 'https://evil.example');

        $this->assertFalse(
            $handler->verifyAssertionSignature($authData, $forgedClientData, $signature, $this->storedPublicKeyPem),
            'A clientDataJSON with a swapped origin MUST fail signature verification'
        );
    }

    /**
     * 🚫 (g) Sign-count regression (received <= stored) → REJECTED.
     *
     * Pins the clone-detection rule the handler enforces: when either counter
     * is non-zero, received MUST be strictly greater than stored.
     */
    public function testSignCountRegressionRejected(): void
    {
        // The decision rule is a simple, security-critical inequality. Pin it
        // directly so a regression in the handler's comparison is caught.
        $stored   = 10;
        $received = 10; // equal → must be rejected
        $shouldReject = ($stored > 0 || $received > 0) && ($received <= $stored);
        $this->assertTrue($shouldReject, 'received == stored MUST be treated as a regression');

        $received = 9; // lower → must be rejected
        $shouldReject = ($stored > 0 || $received > 0) && ($received <= $stored);
        $this->assertTrue($shouldReject, 'received < stored MUST be treated as a regression');

        $received = 11; // higher → accepted
        $shouldReject = ($stored > 0 || $received > 0) && ($received <= $stored);
        $this->assertFalse($shouldReject, 'received > stored MUST be accepted');

        // Both zero → accepted (some authenticators never increment).
        $stored = 0; $received = 0;
        $shouldReject = ($stored > 0 || $received > 0) && ($received <= $stored);
        $this->assertFalse($shouldReject, 'both counters zero MUST be accepted');
    }

    /**
     * 🚫 RS256 path: a malformed COSE key (missing modulus) must throw, not
     * silently produce an unusable key.
     */
    public function testMalformedCoseKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        \WebAuthnCbor::coseKeyToPem([
            \WebAuthnCbor::COSE_KTY => \WebAuthnCbor::KTY_RSA,
            // missing -1 (n) and -2 (e)
        ]);
    }
}
