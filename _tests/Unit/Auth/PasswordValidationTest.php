<?php
/**
 * ============================================================================
 * 🧪 Password Validation Unit Tests
 * ============================================================================
 *
 * Tests password validation using the actual SecurityUtils::validatePassword()
 * method, which reads password policy settings from getSetting().
 *
 * Default settings (from _tests/Fixtures/settings.json):
 * - min_length: 12
 * - require_uppercase: true
 * - require_lowercase: true
 * - require_numbers: true
 * - require_symbols: true
 *
 * @package    SIGNula\Tests\Unit\Auth
 * @version    2.6.0-beta
 * @see        web/private_html/security/SecurityUtils.php
 */

namespace SIGNula\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use SIGNula\Tests\TestCase;

// 📦 Load SecurityUtils class
requireSource('private_html/security/SecurityUtils.php');

class PasswordValidationTest extends TestCase
{
    // ========================================================================
    // 🔐 PASSWORD STRENGTH VALIDATION (via SecurityUtils::validatePassword)
    // ========================================================================

    /**
     * Test password strength validation with various passwords
     *
     * Uses SecurityUtils::validatePassword() which reads from getSetting().
     * Default settings require: 12+ chars, uppercase, lowercase, numbers, symbols.
     */
    #[DataProvider('passwordStrengthProvider')]
    public function testPasswordStrengthValidation(string $password, bool $expectedValid, string $message): void
    {
        $result = \SecurityUtils::validatePassword($password);
        $this->assertEquals($expectedValid, $result['valid'], $message);
    }

    /**
     * Data provider for password strength tests
     *
     * Default settings: min 12 chars, uppercase, lowercase, numbers, symbols required
     *
     * @return array Test cases: [password, expectedValid, message]
     */
    public static function passwordStrengthProvider(): array
    {
        return [
            // ❌ Should fail - too short
            ['short', false, 'Password too short (5 chars, need 12) should fail'],
            ['Sh0rt!', false, 'Password too short (6 chars, need 12) should fail'],

            // ❌ Should fail - missing character types
            ['weakpasswordnone', false, 'Password without uppercase/numbers/symbols should fail'],
            ['12345678901234', false, 'Only numbers should fail (missing letters, symbols)'],
            ['UPPERCASEONLY!!1', false, 'Missing lowercase should fail'],
            ['lowercaseonly!!1', false, 'Missing uppercase should fail'],
            ['NoSymbolsHere123', false, 'Missing symbols should fail'],
            ['NoNumbersHere!!a', false, 'Missing numbers should fail (only has letters/symbols)'],

            // ❌ Should fail - common passwords
            ['password', false, 'Common password "password" should fail'],

            // ✅ Should pass - meets all requirements
            ['Str0ng!P@ssw0rd', true, 'Strong password with all requirements should pass'],
            ['MyP@ssword123!', true, 'Complex password meeting all criteria should pass'],
            ['C0mpl3x!Passw0', true, 'Another valid complex password should pass'],
        ];
    }

    // ========================================================================
    // 🔑 PASSWORD HASHING TESTS
    // ========================================================================

    /**
     * Test password hashing with Argon2id
     */
    public function testPasswordHashing(): void
    {
        $password = 'TestPassword123!';
        $hash = \SecurityUtils::hashPassword($password);

        // 📝 Hash should use Argon2id algorithm
        $this->assertStringStartsWith('$argon2id$', $hash, 'Hash should use Argon2id algorithm');
        $this->assertNotEquals($password, $hash, 'Hash should not equal plain password');
        $this->assertTrue(
            \SecurityUtils::verifyPassword($password, $hash),
            'Password should verify against its hash'
        );
        $this->assertFalse(
            \SecurityUtils::verifyPassword('WrongPassword', $hash),
            'Wrong password should not verify'
        );
    }

    /**
     * Test password comparison (timing-safe, case-sensitive)
     */
    public function testPasswordComparison(): void
    {
        $password = 'TestPassword123!';
        $hash = \SecurityUtils::hashPassword($password);

        // ✅ Correct password
        $this->assertTrue(
            \SecurityUtils::verifyPassword($password, $hash),
            'Correct password should verify'
        );

        // ❌ Incorrect password
        $this->assertFalse(
            \SecurityUtils::verifyPassword('WrongPassword1!', $hash),
            'Incorrect password should not verify'
        );

        // ❌ Case sensitivity
        $this->assertFalse(
            \SecurityUtils::verifyPassword('testpassword123!', $hash),
            'Password comparison should be case-sensitive'
        );
    }

    /**
     * Test that validation result includes error messages
     */
    public function testValidationResultIncludesErrors(): void
    {
        $result = \SecurityUtils::validatePassword('short');

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('errors', $result);
        $this->assertNotEmpty($result['errors'], 'Failed validation should include error messages');
        $this->assertIsArray($result['errors']);
    }

    /**
     * Test that valid password returns empty errors array
     */
    public function testValidPasswordReturnsNoErrors(): void
    {
        $result = \SecurityUtils::validatePassword('Str0ng!P@ssw0rd');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors'], 'Valid password should have no errors');
    }
}
