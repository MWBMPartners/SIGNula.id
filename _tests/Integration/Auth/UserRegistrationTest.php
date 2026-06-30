<?php
/**
 * User Registration Integration Test
 * Tests user registration with database
 *
 * @package SIGNula\Tests\Integration\Auth
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;

class UserRegistrationTest extends DatabaseTestCase
{
    /**
     * Tables to truncate before each test
     */
    protected array $truncateTables = ['tblUsers', 'tblActivityLog'];

    /**
     * Test successful user registration
     */
    public function testSuccessfulUserRegistration(): void
    {
        $userData = [
            'email' => 'newuser@example.com',
            'passwordHash' => password_hash('Password123', PASSWORD_ARGON2ID),
            'displayName' => 'New User',
            'firstName' => 'New',
            'lastName' => 'User',
            'accountStatus' => 'active', // 🔧 B-028: canonical ENUM case
            'emailVerified' => 0,
            'createdAt' => date('Y-m-d H:i:s')
        ];

        $userID = $this->insertRecord('tblUsers', $userData);

        // Assert user was created
        $this->assertGreaterThan(0, $userID, 'User ID should be greater than 0');

        // Assert user exists in database
        $this->assertDatabaseHas('tblUsers', [
            'email' => 'newuser@example.com',
            'displayName' => 'New User'
        ]);

        // Verify password hash
        $user = $this->getRecord('tblUsers', ['userID' => $userID]);
        $this->assertTrue(
            password_verify('Password123', $user['passwordHash']),
            'Password should verify against stored hash'
        );
    }

    /**
     * Test duplicate email registration should fail
     */
    public function testDuplicateEmailRegistration(): void
    {
        $email = 'duplicate@example.com';

        // Create first user
        $this->createTestUser(['email' => $email]);

        // Attempt to create second user with same email
        $this->expectException(\mysqli_sql_exception::class);
        $this->createTestUser(['email' => $email]);
    }

    /**
     * Test user count increases after registration
     */
    public function testUserCountIncreasesAfterRegistration(): void
    {
        $initialCount = $this->countRecords('tblUsers');

        // Create 3 test users
        $this->createTestUser();
        $this->createTestUser();
        $this->createTestUser();

        $finalCount = $this->countRecords('tblUsers');

        $this->assertEquals(
            $initialCount + 3,
            $finalCount,
            'User count should increase by 3'
        );
    }

    /**
     * Test email uniqueness constraint
     */
    public function testEmailMustBeUnique(): void
    {
        $email = 'unique@example.com';

        // Create first user
        $user1 = $this->createTestUser(['email' => $email]);
        $this->assertNotNull($user1['userID']);

        // Verify only one user with this email exists
        $count = $this->countRecords('tblUsers', ['email' => $email]);
        $this->assertEquals(1, $count, 'Should only be one user with this email');
    }

    /**
     * Test default account status
     */
    public function testDefaultAccountStatus(): void
    {
        $user = $this->createTestUser();

        $this->assertEquals(
            'active', // 🔧 B-028: canonical ENUM case (matches createTestUser default)
            $user['accountStatus'],
            'Default account status should be active (canonical ENUM case)'
        );
    }

    /**
     * Test email verification defaults to false
     */
    public function testEmailVerificationDefaultsToFalse(): void
    {
        $userID = $this->insertRecord('tblUsers', [
            'email' => 'unverified@example.com',
            'passwordHash' => password_hash('test', PASSWORD_ARGON2ID),
            'displayName' => 'Test User',
            'accountStatus' => 'active', // 🔧 B-028: canonical ENUM case
            'createdAt' => date('Y-m-d H:i:s')
        ]);

        $user = $this->getRecord('tblUsers', ['userID' => $userID]);

        $this->assertEquals(
            0,
            $user['emailVerified'],
            'Email verification should default to false (0)'
        );
    }
}
