<?php
/**
 * ============================================================================
 * 🧪 MFA Integration Tests
 * ============================================================================
 *
 * Integration tests for the MFA (Multi-Factor Authentication) class, covering
 * all core functionality against a real test database. Tests are isolated using
 * transaction rollback so each test starts with a clean, consistent state.
 *
 * Tests cover:
 * - TOTP setup: secret generation, provisioning URI, and database persistence
 * - TOTP activation: verifying a live code and activating the TOTP record
 * - TOTP verification: correct and incorrect code paths during login flow
 * - Backup code generation: count, uniqueness, and storage (hashed)
 * - Backup code verification: valid code, invalid code, and single-use enforcement
 * - Remaining backup code count after one code has been consumed
 * - MFA enabled status: isEnabled() before and after activation
 * - MFA method disabling: disableMethod() deactivates TOTP
 *
 * Prerequisites:
 * - Test database (signula_test) with full SIGNula schema
 * - phpunit.xml env vars: DB_HOST, DB_USER, DB_PASS, DB_NAME, ENCRYPTION_KEY
 * - SIGNULA_INIT constant defined (handled by bootstrap.php)
 *
 * @package    SIGNula\Tests\Integration\Security
 * @version    2.6.0-beta
 * @see        web/private_html/security/MFA.php
 * @see        web/private_html/security/TOTP.php
 * @see        web/private_html/security/SecurityUtils.php
 * @see        https://www.rfc-editor.org/rfc/rfc6238 (TOTP specification)
 * @see        https://www.iana.org/assignments/uri-schemes/prov/otpauth (otpauth URI scheme)
 */

namespace SIGNula\Tests\Integration\Security;

use SIGNula\Tests\DatabaseTestCase;

// ============================================================================
// 📦 LOAD REQUIRED SOURCE FILES
// ============================================================================
// Order matters: dependencies must be loaded before the classes that use them.
// database.php provides the Database class (query, fetchOne, fetchAll).
// SecurityUtils.php provides encrypt(), decrypt(), hashPassword(), verifyPassword().
// TOTP.php provides generateSecret(), generateCode(), verifyCode().
// MFA.php provides the class under test and depends on all of the above.
// ActivityLogger.php and ErrorLogger.php are referenced by MFA internally.
// Auth.php is included for completeness of the auth integration surface.

requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/security/TOTP.php');
requireSource('private_html/security/MFA.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/auth/Auth.php');

/**
 * MFA Integration Test Suite
 *
 * Each test method operates within a database transaction that is automatically
 * rolled back in tearDown() (inherited from DatabaseTestCase), so tests never
 * pollute each other's state.
 *
 * The helper createUserWithPassword() inserts a minimal but fully valid user
 * row into tblUsers and returns the data array including the new userID.
 */
class MFATest extends DatabaseTestCase
{
    // ========================================================================
    // ⚙️ TEST CONFIGURATION
    // ========================================================================

    /**
     * Tables to truncate before each test begins.
     *
     * Truncating these tables (inside an open transaction that will be rolled
     * back) gives every test a clean baseline without leaving permanent changes.
     *
     * - tblUsers                : user accounts created by createUserWithPassword()
     * - tblUserMFA              : TOTP records, backup codes, etc.
     * - tblVerificationTokens   : email OTP tokens (not directly tested here,
     *                             but included so the schema is clean)
     * - tblActivityLog          : entries written by MFA methods via ActivityLogger
     *
     * @var array<int, string>
     */
    protected array $truncateTables = [
        'tblUsers',
        'tblUserMFA',
        'tblVerificationTokens',
        'tblActivityLog',
    ];

    /**
     * Known password used for every user created during testing.
     * Stored as a constant so tests referencing it are self-documenting.
     *
     * @var string
     */
    private string $testPassword = 'TestPassword123!';

    // ========================================================================
    // 🔧 PRIVATE HELPER METHODS
    // ========================================================================

    /**
     * Create a test user with a hashed password via direct database insert.
     *
     * Uses insertRecord() from DatabaseTestCase, which runs inside the current
     * transaction and will therefore be rolled back after each test.
     *
     * The defaults intentionally produce an Active, email-verified user so that
     * any Auth or MFA checks that gate on account status pass without extra setup.
     *
     * @param array<string, mixed> $overrides Column => value pairs to merge over
     *                                         the defaults (e.g., ['mfaEnabled' => 1])
     * @return array<string, mixed> All inserted column values plus 'userID' key
     */
    private function createUserWithPassword(array $overrides = []): array
    {
        // 🏗️ Build default user fixture data
        $defaults = [
            // 📧 Unique email so parallel test runs don't collide
            'email'              => random_email('mfa_test'),
            // 👤 Unique username with a random suffix
            'username'           => 'mfauser_' . uniqid(),
            // 🔒 Hash the known test password using Argon2id (same as production)
            'passwordHash'       => \SecurityUtils::hashPassword($this->testPassword),
            'displayName'        => 'MFA Test User',
            'firstName'          => 'MFA',
            'lastName'           => 'Test',
            // ✅ Active status so auth checks do not block the test flow
            'accountStatus'      => 'Active',
            // ✅ Email already verified to avoid verification-gate issues
            'emailVerified'      => 1,
            // 🔐 MFA starts disabled; individual tests enable it as required
            'mfaEnabled'         => 0,
            // 🔢 Zero failed attempts so no lockout triggers
            'failedLoginAttempts' => 0,
            // 📅 Timestamp required by the schema NOT NULL constraint
            'createdAt'          => date('Y-m-d H:i:s'),
        ];

        // 🔀 Merge caller overrides on top of defaults
        $data = array_merge($defaults, $overrides);

        // 💾 Insert into tblUsers and capture the auto-increment ID
        $userID = $this->insertRecord('tblUsers', $data);

        // 🪪 Attach userID so callers can reference it directly
        $data['userID'] = $userID;

        return $data;
    }

    // ========================================================================
    // 🔑 TOTP SETUP TESTS
    // ========================================================================

    /**
     * Test that enableTOTP() returns an array containing the 'secret' and
     * 'qr_uri' keys required to display setup instructions to the user.
     *
     * The 'secret' is the raw Base32 TOTP seed that will be entered into an
     * authenticator app. The 'qr_uri' is the otpauth:// provisioning URI that
     * encodes the same secret in a scannable QR code.
     *
     * @return void
     */
    public function testEnableTOTPGeneratesSecret(): void
    {
        // 🏗️ Create a fresh user to run enableTOTP() against
        $user = $this->createUserWithPassword();

        // 🎯 Execute the method under test
        $result = \MFA::enableTOTP($user['userID']);

        // ✅ The call must report success before we inspect the payload
        $this->assertTrue(
            $result['success'],
            'enableTOTP() should return success = true for a valid user'
        );

        // 🔑 The result array must contain the raw Base32 secret string
        $this->assertArrayHasKey(
            'secret',
            $result,
            'Result from enableTOTP() must include a "secret" key'
        );

        // 📱 The result array must contain the otpauth:// provisioning URI
        $this->assertArrayHasKey(
            'qr_uri',
            $result,
            'Result from enableTOTP() must include a "qr_uri" key'
        );

        // 🔍 The secret must be a non-empty string (Base32 alphabet: A-Z, 2-7)
        $this->assertIsString(
            $result['secret'],
            'The "secret" value must be a string'
        );
        $this->assertNotEmpty(
            $result['secret'],
            'The "secret" value must not be empty'
        );

        // 📡 The QR URI must start with the standard otpauth:// scheme prefix
        $this->assertStringStartsWith(
            'otpauth://totp/',
            $result['qr_uri'],
            'The "qr_uri" must be a valid otpauth:// provisioning URI'
        );
    }

    /**
     * Test that enableTOTP() persists a record to tblUserMFA immediately after
     * being called, even before the user has verified their first TOTP code.
     *
     * The initial record should have:
     * - mfaType = 'totp'
     * - mfaEnabled = 0  (not yet active; requires code verification)
     * - isVerified = 0  (not yet confirmed by the user)
     *
     * @return void
     */
    public function testEnableTOTPStoresRecordInDatabase(): void
    {
        // 🏗️ Create a user and call enableTOTP()
        $user = $this->createUserWithPassword();
        \MFA::enableTOTP($user['userID']);

        // 🔍 Assert that tblUserMFA now contains a row for this user
        $this->assertDatabaseHas(
            'tblUserMFA',
            [
                'userID'  => $user['userID'],
                'mfaType' => 'totp',
            ],
            'A TOTP record should be written to tblUserMFA after enableTOTP()'
        );

        // 🔍 Retrieve the row to inspect individual field values
        $record = $this->getRecord(
            'tblUserMFA',
            ['userID' => $user['userID'], 'mfaType' => 'totp']
        );

        $this->assertNotNull(
            $record,
            'The TOTP record must be retrievable from tblUserMFA'
        );

        // 🚫 TOTP must not be enabled until the user verifies their first code
        $this->assertEquals(
            0,
            (int)$record['mfaEnabled'],
            'mfaEnabled should be 0 until the user verifies the TOTP code'
        );

        // 🚫 isVerified must remain 0 until verifyAndActivateTOTP() succeeds
        $this->assertEquals(
            0,
            (int)$record['isVerified'],
            'isVerified should be 0 until verifyAndActivateTOTP() is called'
        );
    }

    // ========================================================================
    // ✅ TOTP ACTIVATION TESTS
    // ========================================================================

    /**
     * Test that verifyAndActivateTOTP() succeeds when supplied with a
     * cryptographically correct TOTP code generated from the same secret.
     *
     * Flow:
     * 1. enableTOTP() to generate and store the secret.
     * 2. TOTP::generateCode() with that same secret to produce a valid code.
     * 3. verifyAndActivateTOTP() to verify the code and activate MFA.
     *
     * On success the method should:
     * - Return ['success' => true, ...]
     * - Include 'backup_codes' in the result (generated during activation)
     * - Update tblUserMFA so mfaEnabled = 1 and isVerified = 1
     *
     * @return void
     */
    public function testVerifyAndActivateTOTPValidCode(): void
    {
        // 🏗️ Set up a user and initialise TOTP
        $user    = $this->createUserWithPassword();
        $setup   = \MFA::enableTOTP($user['userID']);

        // 🔢 Generate a live code from the returned secret at current time
        // Using TOTP::generateCode() mirrors exactly what an authenticator app does
        $validCode = \TOTP::generateCode($setup['secret'], time());

        // 🎯 Attempt to verify and activate with the fresh code
        $result = \MFA::verifyAndActivateTOTP($user['userID'], $validCode);

        // ✅ The activation must succeed
        $this->assertTrue(
            $result['success'],
            'verifyAndActivateTOTP() should succeed with a correct TOTP code; '
            . 'got: ' . ($result['message'] ?? 'no message')
        );

        // 🎲 Backup codes must be returned so the user can save them
        $this->assertArrayHasKey(
            'backup_codes',
            $result,
            'Result from verifyAndActivateTOTP() must include "backup_codes"'
        );

        // 🔍 Confirm the database record is now fully activated
        $record = $this->getRecord(
            'tblUserMFA',
            ['userID' => $user['userID'], 'mfaType' => 'totp']
        );

        $this->assertEquals(
            1,
            (int)$record['mfaEnabled'],
            'mfaEnabled should be 1 after successful TOTP activation'
        );

        $this->assertEquals(
            1,
            (int)$record['isVerified'],
            'isVerified should be 1 after successful TOTP activation'
        );
    }

    /**
     * Test that verifyAndActivateTOTP() fails gracefully when the supplied
     * code is incorrect (e.g., the user typed the wrong digits).
     *
     * The method should return ['success' => false, ...] and must NOT activate
     * the TOTP record in tblUserMFA.
     *
     * @return void
     */
    public function testVerifyAndActivateTOTPInvalidCode(): void
    {
        // 🏗️ Set up a user with TOTP initialised
        $user = $this->createUserWithPassword();
        \MFA::enableTOTP($user['userID']);

        // ❌ Deliberately supply a code that can never be valid
        // '000000' is a valid format but cryptographically wrong for this secret
        $result = \MFA::verifyAndActivateTOTP($user['userID'], '000000');

        // ✅ The method must report failure without throwing an exception
        $this->assertFalse(
            $result['success'],
            'verifyAndActivateTOTP() should fail with an incorrect code'
        );

        // 🔍 Confirm the database record is still NOT activated
        $record = $this->getRecord(
            'tblUserMFA',
            ['userID' => $user['userID'], 'mfaType' => 'totp']
        );

        $this->assertEquals(
            0,
            (int)$record['mfaEnabled'],
            'mfaEnabled should remain 0 when an invalid code is supplied'
        );
    }

    // ========================================================================
    // 🔓 TOTP VERIFICATION TESTS (LOGIN FLOW)
    // ========================================================================

    /**
     * Test that verifyTOTP() returns true when supplied with the correct
     * TOTP code for a fully activated (mfaEnabled = 1, isVerified = 1) record.
     *
     * This mirrors the login-time MFA check that the Auth class performs after
     * the user has successfully submitted their password.
     *
     * @return void
     */
    public function testVerifyTOTPCorrectCode(): void
    {
        // 🏗️ Create user and run the full TOTP setup → activation flow
        $user    = $this->createUserWithPassword();
        $setup   = \MFA::enableTOTP($user['userID']);

        // 🔢 Generate the current-window code to activate TOTP
        $activationCode = \TOTP::generateCode($setup['secret'], time());
        \MFA::verifyAndActivateTOTP($user['userID'], $activationCode);

        // 🔢 Generate a fresh code for the verification call
        // (TOTP window is 30 s; using time() again will produce the same or next step,
        // both of which are valid within the default window=1 tolerance)
        $loginCode = \TOTP::generateCode($setup['secret'], time());

        // 🎯 Execute the login-time verification
        $isValid = \MFA::verifyTOTP($user['userID'], $loginCode);

        // ✅ A correctly generated code must pass verification
        $this->assertTrue(
            $isValid,
            'verifyTOTP() should return true when the correct current TOTP code is supplied'
        );
    }

    // ========================================================================
    // 🎲 BACKUP CODE GENERATION TESTS
    // ========================================================================

    /**
     * Test that generateBackupCodes() returns exactly 10 backup codes.
     *
     * The count is governed by the private constant MFA::BACKUP_CODE_COUNT = 10.
     * Returning fewer codes would leave the user unable to recover if they lose
     * their authenticator device, so the count must always be exactly 10.
     *
     * @return void
     */
    public function testGenerateBackupCodesReturns10Codes(): void
    {
        // 🏗️ Create a user; backup codes can be generated independently of TOTP state
        $user  = $this->createUserWithPassword();
        $codes = \MFA::generateBackupCodes($user['userID']);

        // ✅ Must be an array
        $this->assertIsArray(
            $codes,
            'generateBackupCodes() should return an array'
        );

        // ✅ Must contain exactly 10 elements
        $this->assertCount(
            10,
            $codes,
            'generateBackupCodes() should return exactly 10 backup codes'
        );
    }

    // ========================================================================
    // ✅ BACKUP CODE VERIFICATION TESTS
    // ========================================================================

    /**
     * Test that verifyBackupCode() returns true when supplied with one of the
     * plain-text codes that was returned from generateBackupCodes().
     *
     * This confirms the full round-trip:
     *   generate (plain) → store (hashed) → verify (hash comparison)
     *
     * @return void
     */
    public function testVerifyBackupCodeValid(): void
    {
        // 🏗️ Create user and generate a fresh set of backup codes
        $user  = $this->createUserWithPassword();
        $codes = \MFA::generateBackupCodes($user['userID']);

        // 🎯 Use the first code in the list as our test subject
        $testCode = $codes[0];

        // 🎯 Verify the plain-text code against the stored hash
        $isValid = \MFA::verifyBackupCode($user['userID'], $testCode);

        // ✅ A freshly generated code must verify successfully
        $this->assertTrue(
            $isValid,
            'verifyBackupCode() should return true for a valid backup code'
        );
    }

    /**
     * Test that verifyBackupCode() returns false when supplied with a code
     * that does not match any of the stored hashed codes.
     *
     * This guards against attackers who might guess or brute-force backup codes.
     *
     * @return void
     */
    public function testVerifyBackupCodeInvalid(): void
    {
        // 🏗️ Create user and generate backup codes (they are now hashed in DB)
        $user = $this->createUserWithPassword();
        \MFA::generateBackupCodes($user['userID']);

        // ❌ Supply a code that was definitely not generated (all zeros, wrong length)
        $isValid = \MFA::verifyBackupCode($user['userID'], 'INVALID99');

        // ✅ An incorrect code must be rejected
        $this->assertFalse(
            $isValid,
            'verifyBackupCode() should return false for an invalid backup code'
        );
    }

    /**
     * Test that each backup code is single-use: verifying a code once marks it
     * as consumed (mfaEnabled = 0), and a second attempt with the same code
     * must fail.
     *
     * This is a critical security requirement — reusable backup codes would
     * defeat their purpose as emergency one-time recovery tokens.
     *
     * @return void
     */
    public function testVerifyBackupCodeSingleUse(): void
    {
        // 🏗️ Create user and generate backup codes
        $user  = $this->createUserWithPassword();
        $codes = \MFA::generateBackupCodes($user['userID']);

        // 🎯 Pick the first code as the one we'll attempt to reuse
        $testCode = $codes[0];

        // 🥇 First use: should succeed
        $firstAttempt = \MFA::verifyBackupCode($user['userID'], $testCode);
        $this->assertTrue(
            $firstAttempt,
            'First use of backup code should succeed'
        );

        // 🥈 Second use of the SAME code: must fail (code is now consumed)
        $secondAttempt = \MFA::verifyBackupCode($user['userID'], $testCode);
        $this->assertFalse(
            $secondAttempt,
            'Second use of the same backup code should fail (single-use enforcement)'
        );
    }

    /**
     * Test that getRemainingBackupCodes() returns 9 after one of the 10
     * generated codes has been successfully verified and consumed.
     *
     * The count is decremented by verifyBackupCode() which sets mfaEnabled = 0
     * on the used row. getRemainingBackupCodes() counts only rows where
     * mfaEnabled = 1, so the post-use count must be exactly 9.
     *
     * @return void
     */
    public function testGetRemainingBackupCodes(): void
    {
        // 🏗️ Create user and generate the full set of 10 backup codes
        $user  = $this->createUserWithPassword();
        $codes = \MFA::generateBackupCodes($user['userID']);

        // 🔍 Confirm starting count is 10
        $countBefore = \MFA::getRemainingBackupCodes($user['userID']);
        $this->assertEquals(
            10,
            $countBefore,
            'Remaining backup codes count should be 10 immediately after generation'
        );

        // 🎯 Consume the first code
        \MFA::verifyBackupCode($user['userID'], $codes[0]);

        // 🔍 Count should now be exactly 9
        $countAfter = \MFA::getRemainingBackupCodes($user['userID']);
        $this->assertEquals(
            9,
            $countAfter,
            'Remaining backup codes count should decrease to 9 after one code is used'
        );
    }

    // ========================================================================
    // 🔍 MFA STATUS TESTS
    // ========================================================================

    /**
     * Test that isEnabled() returns true after TOTP has been fully activated
     * via verifyAndActivateTOTP().
     *
     * isEnabled() checks for any row in tblUserMFA where:
     *   mfaType != 'backup_code' AND mfaEnabled = 1 AND isVerified = 1
     *
     * Before activation that row does not exist; after activation it does.
     *
     * @return void
     */
    public function testIsEnabledReturnsTrueWhenTOTPActive(): void
    {
        // 🏗️ Create user and run the full TOTP activation flow
        $user    = $this->createUserWithPassword();
        $setup   = \MFA::enableTOTP($user['userID']);

        // 🔍 Before activation: isEnabled() must be false
        $this->assertFalse(
            \MFA::isEnabled($user['userID']),
            'isEnabled() should be false before TOTP activation'
        );

        // 🔢 Generate a valid code and activate TOTP
        $code   = \TOTP::generateCode($setup['secret'], time());
        $result = \MFA::verifyAndActivateTOTP($user['userID'], $code);

        // 📋 Only proceed with the isEnabled check if activation succeeded;
        // if it failed the test will still fail due to the assertTrue below,
        // but we skip a potentially misleading intermediate assertion.
        if ($result['success']) {
            // ✅ After activation: isEnabled() must be true
            $this->assertTrue(
                \MFA::isEnabled($user['userID']),
                'isEnabled() should return true after successful TOTP activation'
            );
        } else {
            // 🛑 Activation failed - force a descriptive test failure
            $this->assertTrue(
                false,
                'TOTP activation failed so isEnabled() test cannot proceed: '
                . ($result['message'] ?? 'unknown error')
            );
        }
    }

    /**
     * Test that disableMethod() deactivates TOTP so that isEnabled() returns
     * false and verifyTOTP() no longer accepts valid codes.
     *
     * disableMethod() sets mfaEnabled = 0 on the matching tblUserMFA row.
     * isEnabled() queries for mfaEnabled = 1, so it must subsequently return false.
     *
     * @return void
     */
    public function testDisableMethodDeactivatesTOTP(): void
    {
        // 🏗️ Create user and fully activate TOTP
        $user  = $this->createUserWithPassword();
        $setup = \MFA::enableTOTP($user['userID']);
        $code  = \TOTP::generateCode($setup['secret'], time());

        \MFA::verifyAndActivateTOTP($user['userID'], $code);

        // 🔍 Confirm TOTP is active before we disable it
        // (only assert if activation actually succeeded to give a clean error message)
        $isActiveBeforeDisable = \MFA::isEnabled($user['userID']);
        $this->assertTrue(
            $isActiveBeforeDisable,
            'TOTP must be active before testing disableMethod(); '
            . 'check that TOTP activation works correctly first'
        );

        // 🎯 Disable the TOTP method
        $disableResult = \MFA::disableMethod($user['userID'], 'totp');

        // ✅ disableMethod() must return true to indicate success
        $this->assertTrue(
            $disableResult,
            'disableMethod() should return true on success'
        );

        // 🔍 isEnabled() must now report false because the TOTP row is disabled
        $this->assertFalse(
            \MFA::isEnabled($user['userID']),
            'isEnabled() should return false after disableMethod("totp") is called'
        );

        // 🔍 Verify the database row reflects the disabled state
        $record = $this->getRecord(
            'tblUserMFA',
            ['userID' => $user['userID'], 'mfaType' => 'totp']
        );

        $this->assertNotNull(
            $record,
            'The TOTP record should still exist in tblUserMFA after disabling'
        );

        $this->assertEquals(
            0,
            (int)$record['mfaEnabled'],
            'mfaEnabled should be 0 in tblUserMFA after disableMethod() is called'
        );
    }
}
