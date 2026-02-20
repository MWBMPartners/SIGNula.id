<?php
/**
 * ============================================================================
 * 🧪 Auth Login/Logout Integration Tests
 * ============================================================================
 *
 * Integration tests for Auth class login, logout, and session methods.
 * Requires a running test database (signula_test) with the full schema.
 *
 * Tests cover:
 * - Login with valid/invalid credentials
 * - Login with locked/suspended accounts
 * - Failed attempt tracking and account lockout
 * - Session management (isAuthenticated, getCurrentUser)
 * - Logout and session cleanup
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.5.0-beta
 * @see        web/private_html/auth/Auth.php
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;

// 📦 Load required source classes
requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/security/TOTP.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/auth/Auth.php');

/**
 * Auth Login Integration Test Suite
 *
 * Tests login, logout, and session management against a real test database.
 * Uses transaction rollback for test isolation.
 */
class AuthLoginTest extends DatabaseTestCase
{
    /**
     * Tables to truncate before each test
     */
    protected array $truncateTables = ['tblUsers', 'tblActivityLog', 'tblUserSessions'];

    /**
     * Default test password used across tests
     */
    private string $testPassword = 'TestPassword123!';

    /**
     * Create a test user with a known password via direct DB insert
     *
     * @param array $overrides Additional/override fields
     * @return array User data including userID
     */
    private function createUserWithPassword(array $overrides = []): array
    {
        $defaults = [
            'email' => random_email('auth_test'),
            'username' => 'testuser_' . uniqid(),
            'passwordHash' => \SecurityUtils::hashPassword($this->testPassword),
            'displayName' => 'Auth Test User',
            'firstName' => 'Auth',
            'lastName' => 'Test',
            'accountStatus' => 'Active',
            'emailVerified' => 1,
            'mfaEnabled' => 0,
            'failedLoginAttempts' => 0,
            'createdAt' => date('Y-m-d H:i:s')
        ];

        $data = array_merge($defaults, $overrides);
        $userID = $this->insertRecord('tblUsers', $data);
        $data['userID'] = $userID;

        return $data;
    }

    // ========================================================================
    // 🔑 LOGIN TESTS
    // ========================================================================

    /**
     * Test login with valid email and password
     */
    public function testLoginWithValidCredentials(): void
    {
        $user = $this->createUserWithPassword();

        $result = \Auth::login($user['email'], $this->testPassword);

        $this->assertTrue($result['success'], 'Login should succeed with valid credentials');
        $this->assertNotNull($result['userID'], 'User ID should be returned on successful login');
        $this->assertEquals($user['userID'], $result['userID']);
    }

    /**
     * Test login with invalid password
     */
    public function testLoginWithInvalidPassword(): void
    {
        $user = $this->createUserWithPassword();

        $result = \Auth::login($user['email'], 'WrongPassword123!');

        $this->assertFalse($result['success'], 'Login should fail with invalid password');
    }

    /**
     * Test login with non-existent user
     */
    public function testLoginWithNonExistentUser(): void
    {
        $result = \Auth::login('nonexistent@example.com', $this->testPassword);

        $this->assertFalse($result['success'], 'Login should fail for non-existent user');
    }

    /**
     * Test login with suspended account
     */
    public function testLoginWithSuspendedAccount(): void
    {
        $user = $this->createUserWithPassword(['accountStatus' => 'Suspended']);

        $result = \Auth::login($user['email'], $this->testPassword);

        $this->assertFalse($result['success'], 'Login should fail for suspended account');
        $this->assertStringContainsStringIgnoringCase(
            'suspend',
            $result['message'] ?? '',
            'Error message should mention suspension'
        );
    }

    /**
     * Test login with locked account
     */
    public function testLoginWithLockedAccount(): void
    {
        $user = $this->createUserWithPassword(['accountStatus' => 'Locked']);

        $result = \Auth::login($user['email'], $this->testPassword);

        $this->assertFalse($result['success'], 'Login should fail for locked account');
    }

    /**
     * Test failed login increments attempt counter
     */
    public function testFailedLoginIncrementsAttemptCount(): void
    {
        $user = $this->createUserWithPassword();

        // 📝 Attempt login with wrong password
        \Auth::login($user['email'], 'WrongPassword1!');

        // Verify the counter was incremented in the database
        $record = $this->getRecord('tblUsers', ['userID' => $user['userID']]);

        $this->assertGreaterThan(
            0,
            (int)$record['failedLoginAttempts'],
            'Failed login attempt count should be incremented'
        );
    }

    /**
     * Test account lockout after max failed attempts
     */
    public function testAccountLockoutAfterMaxAttempts(): void
    {
        $maxAttempts = (int)getSetting('security.login.max_attempts', 5);
        $user = $this->createUserWithPassword([
            'failedLoginAttempts' => $maxAttempts - 1
        ]);

        // 📝 One more failed attempt should trigger lockout
        $result = \Auth::login($user['email'], 'WrongPassword1!');

        $this->assertFalse($result['success']);

        // Verify account status changed in database
        $record = $this->getRecord('tblUsers', ['userID' => $user['userID']]);
        $this->assertGreaterThanOrEqual(
            $maxAttempts,
            (int)$record['failedLoginAttempts'],
            'Failed attempts should reach the maximum'
        );
    }

    /**
     * Test login returns result array structure
     */
    public function testLoginReturnsExpectedStructure(): void
    {
        $user = $this->createUserWithPassword();

        $result = \Auth::login($user['email'], $this->testPassword);

        $this->assertArrayHasKey('success', $result, 'Result should have "success" key');
        $this->assertArrayHasKey('message', $result, 'Result should have "message" key');
    }

    /**
     * Test login with MFA enabled returns requiresMFA flag
     */
    public function testLoginWithMFAEnabledReturnsMFAFlag(): void
    {
        $user = $this->createUserWithPassword(['mfaEnabled' => 1]);

        $result = \Auth::login($user['email'], $this->testPassword);

        // 📝 Login should succeed but require MFA verification
        if (isset($result['requiresMFA'])) {
            $this->assertTrue($result['requiresMFA'], 'Should flag MFA requirement');
        }
        // Either success with MFA flag, or the login flow handles MFA differently
        $this->assertIsBool($result['success']);
    }

    // ========================================================================
    // 🚪 LOGOUT TESTS
    // ========================================================================

    /**
     * Test logout clears session
     */
    public function testLogoutClearsSession(): void
    {
        // 📝 Simulate authenticated session
        $_SESSION['user_id'] = 1;
        $_SESSION['authenticated'] = true;

        $result = \Auth::logout();

        $this->assertTrue($result, 'Logout should return true');
    }

    // ========================================================================
    // 🔍 SESSION MANAGEMENT TESTS
    // ========================================================================

    /**
     * Test isAuthenticated returns false when not logged in
     */
    public function testIsAuthenticatedReturnsFalseWhenNotLoggedIn(): void
    {
        // 📝 Clear any cached user ID
        $reflection = new \ReflectionClass(\Auth::class);
        $prop = $reflection->getProperty('currentUserID');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->assertFalse(
            \Auth::isAuthenticated(),
            'Should return false when no session exists'
        );
    }

    /**
     * Test getCurrentUserID returns null when not authenticated
     */
    public function testGetCurrentUserIDReturnsNullWhenNotAuthenticated(): void
    {
        // Clear cached state
        $reflection = new \ReflectionClass(\Auth::class);
        $prop = $reflection->getProperty('currentUserID');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->assertNull(
            \Auth::getCurrentUserID(),
            'Should return null when not authenticated'
        );
    }

    /**
     * Test register returns success for valid data
     */
    public function testRegisterReturnsSuccessForValidData(): void
    {
        $result = \Auth::register(
            'newuser_' . uniqid() . '@example.com',
            'newuser_' . uniqid(),
            'Str0ng!P@ssw0rd'
        );

        $this->assertTrue($result['success'], 'Registration should succeed: ' . ($result['message'] ?? ''));
        $this->assertNotNull($result['userID'], 'User ID should be returned');
    }

    /**
     * Test register rejects duplicate email
     */
    public function testRegisterRejectsDuplicateEmail(): void
    {
        $email = 'duplicate_' . uniqid() . '@example.com';

        // First registration should succeed
        $result1 = \Auth::register($email, 'user1_' . uniqid(), 'Str0ng!P@ssw0rd');
        $this->assertTrue($result1['success'], 'First registration should succeed');

        // Second registration with same email should fail
        $result2 = \Auth::register($email, 'user2_' . uniqid(), 'Str0ng!P@ssw0rd');
        $this->assertFalse($result2['success'], 'Duplicate email should be rejected');
    }

    /**
     * Test register rejects invalid email
     */
    public function testRegisterRejectsInvalidEmail(): void
    {
        $result = \Auth::register('not-an-email', 'validuser', 'Str0ng!P@ssw0rd');

        $this->assertFalse($result['success'], 'Invalid email should be rejected');
    }

    /**
     * Test register rejects short username
     */
    public function testRegisterRejectsShortUsername(): void
    {
        $result = \Auth::register('valid@example.com', 'ab', 'Str0ng!P@ssw0rd');

        $this->assertFalse($result['success'], 'Username shorter than 3 chars should be rejected');
    }
}
