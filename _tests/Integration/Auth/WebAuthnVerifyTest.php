<?php
/**
 * ============================================================================
 * 🧪 WebAuthn Verify-Path Characterization Tests (STABILIZE-2 safety net)
 * ============================================================================
 *
 * Integration tests that pin the CURRENT accept/reject decision path of
 * WebAuthnHandler::verifyAuthentication() / verifyRegistration() — the FG-013
 * seam the PassKey/biometric SECURE work will harden.
 *
 * These are DB-gated (the verify methods read tblWebAuthnCredentials and
 * tblWebAuthnChallenges and write usage rows), so they live under Integration/
 * and follow DatabaseTestCase. Without a running signula_test database they
 * error/skip exactly like the other Integration suites — that is expected.
 *
 * ✅ RESOLVED (cycle 6 — FG-013)
 * --------------------------------------------------
 *  • SIGNATURE / AUTHENTICATOR-DATA NOW VERIFIED. verifyAuthentication() now
 *    performs full W3C §7.2 cryptographic verification: it requires
 *    authenticatorData + signature, parses authData (rpIdHash + UP/UV flags +
 *    signCount), and runs openssl_verify(authData ‖ SHA-256(clientDataJSON),
 *    signature, storedPublicKeyPem, SHA-256), accepting ONLY verify === 1.
 *    Sign-count clone-detection is enforced. The unsigned-assertion auth-bypass
 *    is closed — pinned here by testTamperedSignatureRejected() (an assertion
 *    with NO signature is now REJECTED), and exercised at the crypto level (with
 *    a real EC P-256 keypair + each attack vector) by the DB-free unit suite
 *    _tests/Unit/Auth/WebAuthnVerificationTest.php.
 *
 * ✅ RESOLVED (cycle 5)
 * --------------------------------------------------
 *  • B-027: storeChallenge()/storeCredential() called Database::insert(), which
 *    previously did not exist on the Database class — the storage path fataled
 *    with an undefined-method Error. Database::insert() is now a real static
 *    helper (web/_config/database.php) that runs the INSERT and returns the new
 *    id. The corrected behaviour is pinned by
 *    testRegistrationStoragePathUsesDatabaseInsert().
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.7.0-beta
 * @see        web/private_html/auth/WebAuthnHandler.php
 * @see        https://www.w3.org/TR/webauthn-2/#sctn-verifying-assertion
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;
use ReflectionMethod;
use Throwable;

// 📦 Source classes (order matters: deps before the class under test).
requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('_lib/webauthn/WebAuthnCbor.php');
requireSource('private_html/auth/WebAuthnHandler.php');

/**
 * WebAuthn Verify-Path Characterization Test Suite
 */
class WebAuthnVerifyTest extends DatabaseTestCase
{
    /**
     * Tables this suite touches (rolled back by the transaction each test).
     */
    protected array $truncateTables = [
        'tblUsers',
        'tblWebAuthnCredentials',
        'tblWebAuthnChallenges',
        'tblActivityLog',
    ];

    /**
     * RP origin the handler will compare against (from settings.json fixture).
     */
    private string $rpOrigin = 'http://localhost';

    /**
     * Build a clientDataJSON base64 blob the verify path expects.
     *
     * @param string $type      Ceremony type ("webauthn.get"|"webauthn.create")
     * @param string $challenge The stored challenge value
     * @param string $origin    The origin to claim
     * @return string base64-encoded clientDataJSON
     */
    private function clientDataJSON(string $type, string $challenge, string $origin): string
    {
        return base64_encode(json_encode([
            'type'      => $type,
            'challenge' => $challenge,
            'origin'    => $origin,
        ]));
    }

    /**
     * Seed a user + active credential + a live (unused, unexpired) challenge,
     * returning the data needed to drive verifyAuthentication().
     *
     * @return array{userID:int,credId:string,challenge:string}
     */
    private function seedAuthScenario(): array
    {
        $userID = $this->insertRecord('tblUsers', [
            // 🆔 userUUID is NOT NULL / UNIQUE with no DB default
            'userUUID'      => self::generateTestUuid(),
            'email'         => random_email('wa'),
            'username'      => 'wa_' . uniqid(),
            'passwordHash'  => \SecurityUtils::hashPassword('TestPassword123!'),
            'displayName'   => 'WebAuthn User',
            'accountStatus' => 'active', // 🔧 B-028: canonical ENUM case
            'emailVerified' => 1,
            'createdAt'     => date('Y-m-d H:i:s'),
        ]);

        $credId = 'cred_' . bin2hex(random_bytes(8));
        $this->insertRecord('tblWebAuthnCredentials', [
            'userID'                => $userID,
            'credentialPublicKeyID' => $credId,
            'credentialPublicKey'   => base64_encode('fake-public-key'),
            'attestationType'       => 'none',
            'isActive'              => 1,
            'createdAt'             => date('Y-m-d H:i:s'),
        ]);

        $challenge = base64_encode(random_bytes(32));
        $this->insertRecord('tblWebAuthnChallenges', [
            'userID'         => $userID,
            'challenge'      => $challenge,
            'challengeType'  => 'authentication',
            'isUsed'         => 0,
            'expiresAt'      => date('Y-m-d H:i:s', time() + 300),
            'validityMinutes'=> 5,
            'createdAt'      => date('Y-m-d H:i:s'),
        ]);

        return ['userID' => $userID, 'credId' => $credId, 'challenge' => $challenge];
    }

    // ========================================================================
    // 🚫 REJECT PATHS (these are correctly enforced today)
    // ========================================================================

    /**
     * Missing required fields → reject with "Invalid credential data".
     */
    public function testVerifyAuthenticationRejectsMissingFields(): void
    {
        $handler = new \WebAuthnHandler();

        $result = $handler->verifyAuthentication(['id' => 'x']); // no rawId/response

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid credential data', $result['error']);
    }

    /**
     * Unknown / inactive credential → reject with "Credential not found".
     */
    public function testVerifyAuthenticationRejectsUnknownCredential(): void
    {
        $handler = new \WebAuthnHandler();

        $result = $handler->verifyAuthentication([
            'id'       => 'does-not-exist',
            'rawId'    => 'does-not-exist',
            'response' => ['clientDataJSON' => $this->clientDataJSON('webauthn.get', 'c', $this->rpOrigin)],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Credential not found', $result['error']);
    }

    /**
     * Origin mismatch → reject, even with a valid credential + challenge.
     */
    public function testVerifyAuthenticationRejectsOriginMismatch(): void
    {
        $scenario = $this->seedAuthScenario();
        $handler  = new \WebAuthnHandler();

        $result = $handler->verifyAuthentication([
            'id'       => $scenario['credId'],
            'rawId'    => $scenario['credId'],
            'response' => [
                'clientDataJSON' => $this->clientDataJSON(
                    'webauthn.get',
                    $scenario['challenge'],
                    'https://evil.example' // wrong origin
                ),
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Origin mismatch', $result['error']);
    }

    /**
     * Wrong ceremony type ("webauthn.create" on a GET) → reject.
     */
    public function testVerifyAuthenticationRejectsWrongCeremonyType(): void
    {
        $scenario = $this->seedAuthScenario();
        $handler  = new \WebAuthnHandler();

        $result = $handler->verifyAuthentication([
            'id'       => $scenario['credId'],
            'rawId'    => $scenario['credId'],
            'response' => [
                'clientDataJSON' => $this->clientDataJSON(
                    'webauthn.create', // wrong type for authentication
                    $scenario['challenge'],
                    $this->rpOrigin
                ),
            ],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Invalid ceremony type', $result['error']);
    }

    // ========================================================================
    // 🟢 SIGNATURE VERIFICATION NOW ENFORCED (FG-013 RESOLVED)
    // ========================================================================

    /**
     * ✅ AUTH-BYPASS CLOSED (FG-013, cycle 6).
     *
     * The exact PoC that previously proved the bypass: a valid stored credential
     * + live challenge + correct origin + correct type, but a response carrying
     * NO authenticatorData and NO signature. verifyAuthentication() now REJECTS
     * it — the missing assertion fields are caught before any session is granted.
     *
     * This is the deliberate inversion of the old
     * testTamperedSignatureStillAccepted(): an unsigned assertion MUST fail.
     */
    public function testTamperedSignatureRejected(): void
    {
        $scenario = $this->seedAuthScenario();
        $handler  = new \WebAuthnHandler();

        $result = $handler->verifyAuthentication([
            'id'       => $scenario['credId'],
            'rawId'    => $scenario['credId'],
            'response' => [
                // 🚨 No "signature", no "authenticatorData" — only clientDataJSON.
                'clientDataJSON' => $this->clientDataJSON(
                    'webauthn.get',
                    $scenario['challenge'],
                    $this->rpOrigin
                ),
            ],
        ]);

        $this->assertFalse(
            $result['success'],
            'FG-013 FIX: an assertion with NO signature MUST be rejected (auth-bypass closed)'
        );
        // The reject reason should point at the missing signature/authenticator data.
        $this->assertMatchesRegularExpression(
            '/signature|authenticator/i',
            $result['error'],
            'Rejection must be due to missing/invalid signature or authenticator data'
        );
    }

    /**
     * ✅ END-TO-END ACCEPT: a genuinely signed assertion verifies through the
     * full DB-backed path (real EC P-256 key stored as the credential PEM, a
     * real ECDSA signature over authData ‖ SHA-256(clientDataJSON)).
     */
    public function testValidSignedAssertionAcceptedEndToEnd(): void
    {
        $rpId = 'localhost'; // matches the settings.json fixture rp_id

        // 🔑 Real EC P-256 keypair → COSE → PEM (as registration would store).
        $priv = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        $details = openssl_pkey_get_details($priv);
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $pem = \WebAuthnCbor::coseKeyToPem([
            \WebAuthnCbor::COSE_KTY => \WebAuthnCbor::KTY_EC2,
            \WebAuthnCbor::COSE_ALG => \WebAuthnCbor::ALG_ES256,
            \WebAuthnCbor::COSE_CRV => \WebAuthnCbor::CRV_P256,
            \WebAuthnCbor::COSE_X   => $x,
            \WebAuthnCbor::COSE_Y   => $y,
        ])['pem'];

        // 🌱 Seed user + credential (storing the REAL PEM) + live challenge.
        $userID = $this->insertRecord('tblUsers', [
            // 🆔 userUUID is NOT NULL / UNIQUE with no DB default
            'userUUID'      => self::generateTestUuid(),
            'email'         => random_email('wa'),
            'username'      => 'wa_' . uniqid(),
            'passwordHash'  => \SecurityUtils::hashPassword('TestPassword123!'),
            'displayName'   => 'WebAuthn User',
            'accountStatus' => 'active',
            'emailVerified' => 1,
            'createdAt'     => date('Y-m-d H:i:s'),
        ]);
        $credId = 'cred_' . bin2hex(random_bytes(8));
        $this->insertRecord('tblWebAuthnCredentials', [
            'userID'                => $userID,
            'credentialPublicKeyID' => $credId,
            'credentialPublicKey'   => $pem,
            'attestationType'       => 'none',
            'signCount'             => 5,
            'isActive'              => 1,
            'createdAt'             => date('Y-m-d H:i:s'),
        ]);
        $challenge = base64_encode(random_bytes(32));
        $this->insertRecord('tblWebAuthnChallenges', [
            'userID'          => $userID,
            'challenge'       => $challenge,
            'challengeType'   => 'authentication',
            'isUsed'          => 0,
            'expiresAt'       => date('Y-m-d H:i:s', time() + 300),
            'validityMinutes' => 5,
            'createdAt'       => date('Y-m-d H:i:s'),
        ]);

        // 🧱 Build authData (rpIdHash + UP flag + a HIGHER sign-count) and sign.
        $authData = hash('sha256', $rpId, true) . chr(0x01) . pack('N', 6);
        $clientDataJSON = json_encode([
            'type'      => 'webauthn.get',
            'challenge' => $challenge,
            'origin'    => $this->rpOrigin,
        ]);
        $signedData = $authData . hash('sha256', $clientDataJSON, true);
        $signature  = '';
        openssl_sign($signedData, $signature, $priv, OPENSSL_ALGO_SHA256);

        $handler = new \WebAuthnHandler();
        $result  = $handler->verifyAuthentication([
            'id'       => $credId,
            'rawId'    => $credId,
            'response' => [
                'clientDataJSON'    => base64_encode($clientDataJSON),
                'authenticatorData' => base64_encode($authData),
                'signature'         => base64_encode($signature),
            ],
        ]);

        $this->assertTrue(
            $result['success'],
            'A genuinely signed assertion MUST verify through the full DB-backed path'
        );
        $this->assertSame((int) $userID, (int) $result['userID']);

        // 🔢 The new (higher) sign-count must have been persisted.
        $row = \Database::fetchOne(
            "SELECT signCount FROM tblWebAuthnCredentials WHERE credentialPublicKeyID = ?",
            [$credId],
            's'
        );
        $this->assertSame(6, (int) $row['signCount'], 'Sign-count must advance to the asserted value');
    }

    /**
     * 🚫 END-TO-END sign-count regression: a correctly signed assertion whose
     * sign-count is NOT greater than the stored value is rejected (clone guard).
     */
    public function testSignCountRegressionRejectedEndToEnd(): void
    {
        $rpId = 'localhost';

        $priv = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ]);
        $details = openssl_pkey_get_details($priv);
        $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $pem = \WebAuthnCbor::coseKeyToPem([
            \WebAuthnCbor::COSE_KTY => \WebAuthnCbor::KTY_EC2,
            \WebAuthnCbor::COSE_ALG => \WebAuthnCbor::ALG_ES256,
            \WebAuthnCbor::COSE_CRV => \WebAuthnCbor::CRV_P256,
            \WebAuthnCbor::COSE_X   => $x,
            \WebAuthnCbor::COSE_Y   => $y,
        ])['pem'];

        $userID = $this->insertRecord('tblUsers', [
            // 🆔 userUUID is NOT NULL / UNIQUE with no DB default
            'userUUID'      => self::generateTestUuid(),
            'email'         => random_email('wa'),
            'username'      => 'wa_' . uniqid(),
            'passwordHash'  => \SecurityUtils::hashPassword('TestPassword123!'),
            'displayName'   => 'WebAuthn User',
            'accountStatus' => 'active',
            'emailVerified' => 1,
            'createdAt'     => date('Y-m-d H:i:s'),
        ]);
        $credId = 'cred_' . bin2hex(random_bytes(8));
        $this->insertRecord('tblWebAuthnCredentials', [
            'userID'                => $userID,
            'credentialPublicKeyID' => $credId,
            'credentialPublicKey'   => $pem,
            'attestationType'       => 'none',
            'signCount'             => 10, // stored counter
            'isActive'              => 1,
            'createdAt'             => date('Y-m-d H:i:s'),
        ]);
        $challenge = base64_encode(random_bytes(32));
        $this->insertRecord('tblWebAuthnChallenges', [
            'userID'          => $userID,
            'challenge'       => $challenge,
            'challengeType'   => 'authentication',
            'isUsed'          => 0,
            'expiresAt'       => date('Y-m-d H:i:s', time() + 300),
            'validityMinutes' => 5,
            'createdAt'       => date('Y-m-d H:i:s'),
        ]);

        // Sign with a LOWER sign-count (5 <= stored 10) — must be rejected.
        $authData = hash('sha256', $rpId, true) . chr(0x01) . pack('N', 5);
        $clientDataJSON = json_encode([
            'type'      => 'webauthn.get',
            'challenge' => $challenge,
            'origin'    => $this->rpOrigin,
        ]);
        $signedData = $authData . hash('sha256', $clientDataJSON, true);
        $signature  = '';
        openssl_sign($signedData, $signature, $priv, OPENSSL_ALGO_SHA256);

        $handler = new \WebAuthnHandler();
        $result  = $handler->verifyAuthentication([
            'id'       => $credId,
            'rawId'    => $credId,
            'response' => [
                'clientDataJSON'    => base64_encode($clientDataJSON),
                'authenticatorData' => base64_encode($authData),
                'signature'         => base64_encode($signature),
            ],
        ]);

        $this->assertFalse(
            $result['success'],
            'A signed assertion with a regressed sign-count MUST be rejected (clone detection)'
        );
        $this->assertStringContainsString('Sign count', $result['error']);
    }

    // ========================================================================
    // 💾 STORAGE PATH — Database::insert() now exists (B-027 fix, cycle 5)
    // ========================================================================

    /**
     * ✅ B-027 FIX: storeChallenge()/storeCredential() call Database::insert(),
     * which is now a real static helper on the Database class (returns the new
     * insert id). This pins the corrected behaviour:
     *   1. The insert() method EXISTS (and is static + public), so the storage
     *      path no longer dies with an undefined-method Error.
     *   2. Driving generateAuthenticationOptions(null) — which generates a
     *      challenge then calls storeChallenge() → Database::insert() — completes
     *      and persists a challenge row, instead of fataling.
     *
     * @see web/_config/database.php Database::insert()
     */
    public function testRegistrationStoragePathUsesDatabaseInsert(): void
    {
        // ✅ The helper must now exist and be a public static method.
        $this->assertTrue(
            method_exists(\Database::class, 'insert'),
            'Database::insert() must exist after the B-027 fix (storeChallenge/storeCredential rely on it)'
        );

        $insertRef = new ReflectionMethod(\Database::class, 'insert');
        $this->assertTrue($insertRef->isStatic(), 'Database::insert() must be static');
        $this->assertTrue($insertRef->isPublic(), 'Database::insert() must be public');

        $handler = new \WebAuthnHandler();

        // 🟢 No undefined-method Error now: the storage path runs to completion.
        //    (DB-gated — this writes a real challenge row via Database::insert().)
        $options = $handler->generateAuthenticationOptions(null);

        $this->assertIsArray($options, 'generateAuthenticationOptions() should return options once storage works');
        $this->assertArrayHasKey('challenge', $options, 'Options must carry the generated challenge');

        // 🔎 Prove the challenge was actually persisted by storeChallenge().
        $stored = \Database::fetchOne(
            "SELECT challengeID FROM tblWebAuthnChallenges WHERE challenge = ? AND challengeType = 'authentication'",
            [$options['challenge']],
            's'
        );
        $this->assertNotNull($stored, 'storeChallenge() should have persisted the challenge row via Database::insert()');
    }
}
