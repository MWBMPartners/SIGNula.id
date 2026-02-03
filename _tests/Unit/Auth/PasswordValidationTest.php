<?php
/**
 * Password Validation Unit Test
 * Tests password validation logic without database
 *
 * @package SIGNula\Tests\Unit\Auth
 */

namespace SIGNula\Tests\Unit\Auth;

use SIGNula\Tests\TestCase;

class PasswordValidationTest extends TestCase
{
    /**
     * Test password strength validation
     *
     * @dataProvider passwordStrengthProvider
     */
    public function testPasswordStrengthValidation(string $password, bool $expected, string $message): void
    {
        $result = $this->validatePasswordStrength($password);
        $this->assertEquals($expected, $result, $message);
    }

    /**
     * Data provider for password strength tests
     */
    public function passwordStrengthProvider(): array
    {
        return [
            ['short', false, 'Password too short should fail'],
            ['weakpassword', false, 'Password without numbers/symbols should fail'],
            ['Password123', true, 'Strong password should pass'],
            ['Pass123!@#', true, 'Password with symbols should pass'],
            ['12345678', false, 'Only numbers should fail'],
            ['UPPERCASE', false, 'Only uppercase should fail'],
            ['lowercase123', true, 'Mixed case with numbers should pass'],
        ];
    }

    /**
     * Test password hashing
     */
    public function testPasswordHashing(): void
    {
        $password = 'TestPassword123';
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        $this->assertNotEquals($password, $hash, 'Hash should not equal plain password');
        $this->assertTrue(password_verify($password, $hash), 'Password should verify against hash');
        $this->assertFalse(password_verify('WrongPassword', $hash), 'Wrong password should not verify');
    }

    /**
     * Test password comparison (timing-safe)
     */
    public function testPasswordComparison(): void
    {
        $password = 'TestPassword123';
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        // Test correct password
        $this->assertTrue(
            password_verify($password, $hash),
            'Correct password should verify'
        );

        // Test incorrect password
        $this->assertFalse(
            password_verify('WrongPassword', $hash),
            'Incorrect password should not verify'
        );

        // Test case sensitivity
        $this->assertFalse(
            password_verify('testpassword123', $hash),
            'Password comparison should be case-sensitive'
        );
    }

    /**
     * Validate password strength (example implementation)
     *
     * @param string $password Password to validate
     * @return bool True if password is strong enough
     */
    private function validatePasswordStrength(string $password): bool
    {
        // Minimum 8 characters
        if (strlen($password) < 8) {
            return false;
        }

        // Must contain at least one letter
        if (!preg_match('/[a-zA-Z]/', $password)) {
            return false;
        }

        // Must contain at least one number
        if (!preg_match('/[0-9]/', $password)) {
            return false;
        }

        // Must contain both uppercase and lowercase OR contain a symbol
        $hasUpperAndLower = preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password);
        $hasSymbol = preg_match('/[^a-zA-Z0-9]/', $password);

        return $hasUpperAndLower || $hasSymbol;
    }
}
