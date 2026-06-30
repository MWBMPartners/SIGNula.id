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
 * ⚠️ NEEDS-LEAD-REVIEW (documented, NOT fixed here)
 * --------------------------------------------------
 *  • SIGNATURE / AUTHENTICATOR-DATA NOT VERIFIED. verifyAuthentication() checks
 *    only: credential exists + challenge valid/unused/unexpired + origin match +
 *    type == "webauthn.get". It NEVER verifies the assertion signature, the
 *    authenticatorData, or the sign-count (the source code comments admit this).
 *    Consequence pinned by testTamperedSignatureStillAccepted(): an attacker who
 *    can read a credentialPublicKeyID and replay/obtain a live challenge can
 *    forge a successful authentication. This is an auth-bypass risk.
 *  • Database::insert() does NOT exist on the Database class (no method, no
 *    __callStatic), yet storeChallenge()/storeCredential() call it — pinned by
 *    testRegistrationStoragePathUsesMissingDatabaseInsert().
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
            'email'         => random_email('wa'),
            'username'      => 'wa_' . uniqid(),
            'passwordHash'  => \SecurityUtils::hashPassword('TestPassword123!'),
            'displayName'   => 'WebAuthn User',
            'accountStatus' => 'Active',
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
    // 🟢 ACCEPT PATH + ⚠️ THE SIGNATURE GAP (NEEDS-LEAD-REVIEW)
    // ========================================================================

    /**
     * ⚠️ AUTH-BYPASS CHARACTERIZATION.
     *
     * With a valid stored credential + live challenge + correct origin + correct
     * type, verifyAuthentication() SUCCEEDS even though the supplied response
     * carries NO authenticatorData and NO signature (and what is present is
     * attacker-forgeable). This pins the missing cryptographic verification.
     *
     * The SECURE fix will make this case FAIL — at which point this test must be
     * inverted deliberately. Do NOT "fix" by editing production code here.
     */
    public function testTamperedSignatureStillAccepted(): void
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

        $this->assertTrue(
            $result['success'],
            'CURRENT behaviour: assertion accepted without signature verification (auth-bypass risk)'
        );
        $this->assertSame((int) $scenario['userID'], (int) $result['userID']);
    }

    /**
     * The accept path marks the challenge used (single-use enforcement) so a
     * replay of the SAME challenge is then rejected.
     */
    public function testChallengeIsConsumedAfterSuccessfulAuth(): void
    {
        $scenario = $this->seedAuthScenario();
        $handler  = new \WebAuthnHandler();

        $payload = [
            'id'       => $scenario['credId'],
            'rawId'    => $scenario['credId'],
            'response' => [
                'clientDataJSON' => $this->clientDataJSON('webauthn.get', $scenario['challenge'], $this->rpOrigin),
            ],
        ];

        $first = $handler->verifyAuthentication($payload);
        $this->assertTrue($first['success']);

        // Replaying the now-consumed challenge must fail the challenge check.
        $second = $handler->verifyAuthentication($payload);
        $this->assertFalse($second['success']);
        $this->assertStringContainsString('challenge', strtolower($second['error']));
    }

    // ========================================================================
    // 🐞 STORAGE PATH — Database::insert() does not exist (NEEDS-LEAD-REVIEW)
    // ========================================================================

    /**
     * ⚠️ storeChallenge()/storeCredential() call Database::insert(), but the
     * Database class defines no insert() method and no __callStatic(). This
     * pins that the registration/options storage path raises an Error today.
     *
     * generateAuthenticationOptions() (no email) generates a challenge then
     * calls storeChallenge() → Database::insert() → undefined-method Error.
     */
    public function testRegistrationStoragePathUsesMissingDatabaseInsert(): void
    {
        // Confirm the missing-method premise without needing a live DB write.
        $this->assertFalse(
            method_exists(\Database::class, 'insert'),
            'Database::insert() is expected to be ABSENT today (storeChallenge/storeCredential would fatal)'
        );

        $handler = new \WebAuthnHandler();

        $threw = false;
        try {
            // Usernameless options → generateChallenge() then storeChallenge().
            $handler->generateAuthenticationOptions(null);
        } catch (Throwable $e) {
            $threw = true;
            // Error message mentions the undefined insert() call.
            $this->assertStringContainsStringIgnoringCase('insert', $e->getMessage());
        }

        $this->assertTrue(
            $threw,
            'CURRENT behaviour: storing a challenge fatals because Database::insert() is undefined'
        );
    }
}
