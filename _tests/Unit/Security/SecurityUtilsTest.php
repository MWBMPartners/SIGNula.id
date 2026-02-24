<?php
/**
 * ============================================================================
 * 🧪 SecurityUtils Unit Tests
 * ============================================================================
 *
 * Tests for the SecurityUtils class covering:
 * - AES-256-CBC encryption and decryption
 * - Argon2id password hashing and verification
 * - Secure token generation (hex, numeric, alphanumeric, backup codes)
 * - CSRF token generation and verification
 * - Password strength validation
 * - Input sanitization (string, email, URL, int, float)
 *
 * These tests do NOT require a database connection.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.6.0-beta
 * @see        web/private_html/security/SecurityUtils.php
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;
use RuntimeException;

// 📦 Load source class
requireSource('private_html/security/SecurityUtils.php');

/**
 * SecurityUtils Test Suite
 *
 * Comprehensive unit tests for all SecurityUtils static methods.
 * Uses test settings from _tests/Fixtures/settings.json for password validation.
 */
class SecurityUtilsTest extends TestCase
{
    // ========================================================================
    // 🔐 ENCRYPTION / DECRYPTION TESTS
    // ========================================================================

    /**
     * Test that encrypt() returns a non-empty base64 string
     */
    public function testEncryptReturnsNonEmptyString(): void
    {
        $encrypted = \SecurityUtils::encrypt('test data');

        $this->assertNotEmpty($encrypted, 'Encrypted output should not be empty');
        $this->assertIsString($encrypted, 'Encrypted output should be a string');
        // 📝 Should be valid base64
        $this->assertNotFalse(base64_decode($encrypted, true), 'Encrypted output should be valid base64');
    }

    /**
     * Test that decrypt() returns the original data
     */
    public function testDecryptReturnsOriginalData(): void
    {
        $original = 'Hello, SIGNula!';
        $encrypted = \SecurityUtils::encrypt($original);
        $decrypted = \SecurityUtils::decrypt($encrypted);

        $this->assertEquals($original, $decrypted, 'Decrypted data should match original');
    }

    /**
     * Test encrypt/decrypt with a custom key
     */
    public function testEncryptDecryptWithCustomKey(): void
    {
        $customKey = 'custom-key-for-testing-purposes!';
        $original = 'secret message';

        $encrypted = \SecurityUtils::encrypt($original, $customKey);
        $decrypted = \SecurityUtils::decrypt($encrypted, $customKey);

        $this->assertEquals($original, $decrypted, 'Custom key encrypt/decrypt round-trip should work');
    }

    /**
     * Test that decryption with wrong key fails
     */
    public function testDecryptWithWrongKeyFails(): void
    {
        $encrypted = \SecurityUtils::encrypt('test data', 'correct-key-32-characters-long!');

        $this->expectException(RuntimeException::class);
        \SecurityUtils::decrypt($encrypted, 'wrong-key-32-characters-padding!');
    }

    /**
     * Test encrypt/decrypt with special characters (UTF-8, emojis)
     */
    public function testEncryptDecryptWithSpecialCharacters(): void
    {
        $testStrings = [
            'Unicode: äöü ñ 日本語 中文',
            'Symbols: !@#$%^&*()_+-=[]{}|;:\'",.<>?/',
            'Newlines: line1\nline2\ttab',
            'Empty-like: 0',
            'Long: ' . str_repeat('A', 10000),
        ];

        foreach ($testStrings as $original) {
            $encrypted = \SecurityUtils::encrypt($original);
            $decrypted = \SecurityUtils::decrypt($encrypted);
            $this->assertEquals($original, $decrypted, "Round-trip failed for: " . substr($original, 0, 50));
        }
    }

    /**
     * Test encrypt/decrypt with empty string
     */
    public function testEncryptDecryptEmptyString(): void
    {
        $encrypted = \SecurityUtils::encrypt('');
        $decrypted = \SecurityUtils::decrypt($encrypted);

        $this->assertEquals('', $decrypted, 'Empty string should round-trip successfully');
    }

    /**
     * Test that each encryption produces different output (random IV)
     */
    public function testEncryptProducesDifferentOutputEachTime(): void
    {
        $data = 'same data';
        $encrypted1 = \SecurityUtils::encrypt($data);
        $encrypted2 = \SecurityUtils::encrypt($data);

        $this->assertNotEquals(
            $encrypted1,
            $encrypted2,
            'Same data encrypted twice should produce different ciphertext (due to random IV)'
        );
    }

    /**
     * Test decrypt with invalid base64 data throws exception
     */
    public function testDecryptInvalidDataThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        \SecurityUtils::decrypt('not-valid-base64!!!');
    }

    // ========================================================================
    // 🔑 PASSWORD HASHING TESTS
    // ========================================================================

    /**
     * Test that hashPassword() returns an Argon2id hash
     */
    public function testHashPasswordReturnsArgon2idHash(): void
    {
        $hash = \SecurityUtils::hashPassword('TestPassword123!');

        $this->assertStringStartsWith('$argon2id$', $hash, 'Hash should use Argon2id algorithm');
    }

    /**
     * Test that same password produces different hashes (unique salt)
     */
    public function testHashPasswordReturnsUniqueHashes(): void
    {
        $password = 'TestPassword123!';
        $hash1 = \SecurityUtils::hashPassword($password);
        $hash2 = \SecurityUtils::hashPassword($password);

        $this->assertNotEquals($hash1, $hash2, 'Same password should produce different hashes (unique salts)');
    }

    /**
     * Test verifyPassword with correct password
     */
    public function testVerifyPasswordCorrectPassword(): void
    {
        $password = 'TestPassword123!';
        $hash = \SecurityUtils::hashPassword($password);

        $this->assertTrue(
            \SecurityUtils::verifyPassword($password, $hash),
            'Correct password should verify against its hash'
        );
    }

    /**
     * Test verifyPassword with incorrect password
     */
    public function testVerifyPasswordIncorrectPassword(): void
    {
        $hash = \SecurityUtils::hashPassword('CorrectPassword1!');

        $this->assertFalse(
            \SecurityUtils::verifyPassword('WrongPassword1!', $hash),
            'Incorrect password should not verify'
        );
    }

    /**
     * Test verifyPassword is case-sensitive
     */
    public function testVerifyPasswordCaseSensitive(): void
    {
        $hash = \SecurityUtils::hashPassword('TestPassword123!');

        $this->assertFalse(
            \SecurityUtils::verifyPassword('testpassword123!', $hash),
            'Password verification should be case-sensitive'
        );
    }

    /**
     * Test needsRehash returns false for current Argon2id hash
     */
    public function testNeedsRehashReturnsFalseForCurrentHash(): void
    {
        $hash = \SecurityUtils::hashPassword('TestPassword123!');

        $this->assertFalse(
            \SecurityUtils::needsRehash($hash),
            'Current Argon2id hash should not need rehashing'
        );
    }

    /**
     * Test needsRehash returns true for bcrypt hash (lower algorithm)
     */
    public function testNeedsRehashReturnsTrueForBcryptHash(): void
    {
        // 📝 Create a bcrypt hash (lower security than Argon2id)
        $bcryptHash = password_hash('TestPassword123!', PASSWORD_BCRYPT);

        $this->assertTrue(
            \SecurityUtils::needsRehash($bcryptHash),
            'Bcrypt hash should need rehashing to Argon2id'
        );
    }

    // ========================================================================
    // 🎟️ TOKEN GENERATION TESTS
    // ========================================================================

    /**
     * Test generateToken returns hex string of correct length
     */
    public function testGenerateTokenReturnsHexStringOfCorrectLength(): void
    {
        $token = \SecurityUtils::generateToken();

        // Default length is 64, but generateToken does bin2hex(random_bytes(length/2))
        // So 64 / 2 = 32 bytes -> 64 hex chars
        $this->assertEquals(64, strlen($token), 'Default token should be 64 hex characters');
        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $token, 'Token should be lowercase hexadecimal');
    }

    /**
     * Test generateToken with custom length
     */
    public function testGenerateTokenCustomLength(): void
    {
        $token = \SecurityUtils::generateToken(32);

        $this->assertEquals(32, strlen($token), 'Token with length 32 should be 32 hex characters');
    }

    /**
     * Test generateToken produces unique tokens
     */
    public function testGenerateTokenUniqueness(): void
    {
        $tokens = [];
        for ($i = 0; $i < 100; $i++) {
            $tokens[] = \SecurityUtils::generateToken();
        }

        $unique = array_unique($tokens);
        $this->assertCount(100, $unique, '100 generated tokens should all be unique');
    }

    /**
     * Test generateNumericCode returns digits of correct length
     */
    public function testGenerateNumericCodeCorrectLength(): void
    {
        $code = \SecurityUtils::generateNumericCode(6);

        $this->assertEquals(6, strlen($code), 'Numeric code should be 6 characters');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code, 'Numeric code should contain only digits');
    }

    /**
     * Test generateNumericCode with different lengths
     */
    public function testGenerateNumericCodeDifferentLengths(): void
    {
        foreach ([4, 6, 8, 10] as $length) {
            $code = \SecurityUtils::generateNumericCode($length);
            $this->assertEquals($length, strlen($code), "Numeric code should be {$length} characters");
            $this->assertMatchesRegularExpression('/^\d+$/', $code, 'Should contain only digits');
        }
    }

    /**
     * Test generateAlphanumericCode excludes ambiguous characters
     */
    public function testGenerateAlphanumericCodeExcludesAmbiguousChars(): void
    {
        // 📝 Ambiguous characters that should be excluded: 0, O, 1, I, l
        $ambiguous = ['0', 'O', '1', 'I', 'l'];

        // Generate many codes to increase confidence
        for ($i = 0; $i < 50; $i++) {
            $code = \SecurityUtils::generateAlphanumericCode(8);

            $this->assertEquals(8, strlen($code), 'Alphanumeric code should be 8 characters');

            foreach ($ambiguous as $char) {
                $this->assertStringNotContainsString(
                    $char,
                    $code,
                    "Code should not contain ambiguous character '{$char}'"
                );
            }
        }
    }

    /**
     * Test generateAlphanumericCode correct length
     */
    public function testGenerateAlphanumericCodeCorrectLength(): void
    {
        $code = \SecurityUtils::generateAlphanumericCode(12);

        $this->assertEquals(12, strlen($code), 'Alphanumeric code should be 12 characters');
        // 📝 Should only contain allowed characters: 2-9, A-H, J-N, P-Z
        $this->assertMatchesRegularExpression(
            '/^[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]+$/',
            $code,
            'Alphanumeric code should only contain non-ambiguous characters'
        );
    }

    /**
     * Test generateBackupCodes returns correct count
     */
    public function testGenerateBackupCodesReturnsCorrectCount(): void
    {
        $codes = \SecurityUtils::generateBackupCodes(10);

        $this->assertCount(10, $codes, 'Should generate exactly 10 backup codes');
    }

    /**
     * Test generateBackupCodes returns unique codes
     */
    public function testGenerateBackupCodesReturnsUniqueCodes(): void
    {
        $codes = \SecurityUtils::generateBackupCodes(10, 8);

        $unique = array_unique($codes);
        $this->assertCount(10, $unique, 'All 10 backup codes should be unique');

        // 📝 Each code should be 8 characters
        foreach ($codes as $code) {
            $this->assertEquals(8, strlen($code), 'Each backup code should be 8 characters');
        }
    }

    // ========================================================================
    // 🛡️ CSRF TOKEN TESTS
    // ========================================================================

    /**
     * Test generateCSRFToken stores token in session
     */
    public function testGenerateCSRFTokenStoresInSession(): void
    {
        $token = \SecurityUtils::generateCSRFToken();

        $this->assertNotEmpty($token, 'CSRF token should not be empty');
        $this->assertEquals(64, strlen($token), 'CSRF token should be 64 hex characters');
        $this->assertEquals($token, $_SESSION['csrf_token'], 'CSRF token should be stored in session');
        $this->assertArrayHasKey('csrf_token_time', $_SESSION, 'CSRF token time should be stored in session');
    }

    /**
     * Test verifyCSRFToken with valid token
     */
    public function testVerifyCSRFTokenValid(): void
    {
        $token = \SecurityUtils::generateCSRFToken();

        $this->assertTrue(
            \SecurityUtils::verifyCSRFToken($token),
            'Valid CSRF token should verify successfully'
        );
    }

    /**
     * Test verifyCSRFToken with invalid token
     */
    public function testVerifyCSRFTokenInvalid(): void
    {
        \SecurityUtils::generateCSRFToken();

        $this->assertFalse(
            \SecurityUtils::verifyCSRFToken('invalid-token-value'),
            'Invalid CSRF token should not verify'
        );
    }

    /**
     * Test verifyCSRFToken with expired token
     */
    public function testVerifyCSRFTokenExpired(): void
    {
        $token = \SecurityUtils::generateCSRFToken();

        // 📝 Manually set the token time to 2 hours ago (exceeds default 1 hour maxAge)
        $_SESSION['csrf_token_time'] = time() - 7200;

        $this->assertFalse(
            \SecurityUtils::verifyCSRFToken($token),
            'Expired CSRF token should not verify'
        );
    }

    /**
     * Test verifyCSRFToken with null token
     */
    public function testVerifyCSRFTokenNull(): void
    {
        \SecurityUtils::generateCSRFToken();

        $this->assertFalse(
            \SecurityUtils::verifyCSRFToken(null),
            'Null CSRF token should not verify'
        );
    }

    /**
     * Test verifyCSRFToken with empty session (no token generated)
     */
    public function testVerifyCSRFTokenEmptySession(): void
    {
        // No token generated, session is empty
        $this->assertFalse(
            \SecurityUtils::verifyCSRFToken('some-token'),
            'CSRF verification should fail when no token in session'
        );
    }

    // ========================================================================
    // 🔒 PASSWORD VALIDATION TESTS
    // ========================================================================

    /**
     * Test validatePassword with a strong password (meets all requirements)
     */
    public function testValidatePasswordStrong(): void
    {
        $result = \SecurityUtils::validatePassword('Str0ng!P@ssw0rd');

        $this->assertTrue($result['valid'], 'Strong password should pass validation');
        $this->assertEmpty($result['errors'], 'Strong password should have no errors');
    }

    /**
     * Test validatePassword rejects short passwords
     */
    public function testValidatePasswordTooShort(): void
    {
        // 📝 Default min_length from settings.json is 12
        $result = \SecurityUtils::validatePassword('Sh0rt!');

        $this->assertFalse($result['valid'], 'Short password should fail validation');
        $this->assertNotEmpty($result['errors'], 'Should have validation errors');
    }

    /**
     * Test validatePassword requires uppercase when configured
     */
    public function testValidatePasswordMissingUppercase(): void
    {
        $result = \SecurityUtils::validatePassword('alllowercase123!');

        $this->assertFalse($result['valid'], 'Password without uppercase should fail');
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test validatePassword requires lowercase when configured
     */
    public function testValidatePasswordMissingLowercase(): void
    {
        $result = \SecurityUtils::validatePassword('ALLUPPERCASE123!');

        $this->assertFalse($result['valid'], 'Password without lowercase should fail');
    }

    /**
     * Test validatePassword requires numbers when configured
     */
    public function testValidatePasswordMissingNumbers(): void
    {
        $result = \SecurityUtils::validatePassword('NoNumbersHere!@');

        $this->assertFalse($result['valid'], 'Password without numbers should fail');
    }

    /**
     * Test validatePassword requires symbols when configured
     */
    public function testValidatePasswordMissingSymbols(): void
    {
        $result = \SecurityUtils::validatePassword('NoSymbolsHere123');

        $this->assertFalse($result['valid'], 'Password without symbols should fail');
    }

    /**
     * Test validatePassword rejects common passwords
     */
    public function testValidatePasswordRejectsCommonPasswords(): void
    {
        $result = \SecurityUtils::validatePassword('password');

        $this->assertFalse($result['valid'], 'Common password should fail validation');
    }

    /**
     * Test validatePassword respects custom settings
     */
    public function testValidatePasswordRespectsCustomSettings(): void
    {
        // 📝 Relax requirements: only require length >= 8, no special chars
        setTestSetting('security.password.min_length', 8);
        setTestSetting('security.password.require_uppercase', false);
        setTestSetting('security.password.require_lowercase', false);
        setTestSetting('security.password.require_numbers', false);
        setTestSetting('security.password.require_symbols', false);

        $result = \SecurityUtils::validatePassword('simplepwd');

        $this->assertTrue($result['valid'], 'Password should pass with relaxed settings');
    }

    // ========================================================================
    // 🧹 SANITIZATION TESTS
    // ========================================================================

    /**
     * Test sanitizeString removes HTML tags
     */
    public function testSanitizeStringRemovesHtml(): void
    {
        $input = '<script>alert("xss")</script>Hello';
        $result = \SecurityUtils::sanitizeString($input);

        $this->assertStringNotContainsString('<script>', $result, 'HTML tags should be removed');
        $this->assertStringContainsString('Hello', $result, 'Text content should be preserved');
    }

    /**
     * Test sanitizeString trims whitespace
     */
    public function testSanitizeStringTrimsWhitespace(): void
    {
        $result = \SecurityUtils::sanitizeString('  hello world  ');

        $this->assertEquals('hello world', $result, 'Leading/trailing whitespace should be trimmed');
    }

    /**
     * Test sanitizeString escapes special characters
     */
    public function testSanitizeStringEscapesSpecialChars(): void
    {
        $result = \SecurityUtils::sanitizeString('Test "quotes" & <angles>');

        $this->assertStringNotContainsString('<', $result, 'Angle brackets should be escaped');
        $this->assertStringContainsString('&amp;', $result, 'Ampersands should be escaped');
    }

    /**
     * Test sanitizeEmail with valid email
     */
    public function testSanitizeEmailValid(): void
    {
        $result = \SecurityUtils::sanitizeEmail('user@example.com');

        $this->assertEquals('user@example.com', $result, 'Valid email should be returned');
    }

    /**
     * Test sanitizeEmail with invalid email returns false
     */
    public function testSanitizeEmailInvalid(): void
    {
        $result = \SecurityUtils::sanitizeEmail('not-an-email');

        $this->assertFalse($result, 'Invalid email should return false');
    }

    /**
     * Test sanitizeEmail trims whitespace
     */
    public function testSanitizeEmailTrimsWhitespace(): void
    {
        $result = \SecurityUtils::sanitizeEmail('  user@example.com  ');

        $this->assertEquals('user@example.com', $result, 'Email should be trimmed');
    }

    /**
     * Test sanitizeURL with valid URL
     */
    public function testSanitizeURLValid(): void
    {
        $result = \SecurityUtils::sanitizeURL('https://example.com/path?q=1');

        $this->assertEquals('https://example.com/path?q=1', $result, 'Valid URL should be returned');
    }

    /**
     * Test sanitizeURL with invalid URL returns false
     */
    public function testSanitizeURLInvalid(): void
    {
        $result = \SecurityUtils::sanitizeURL('not a url');

        $this->assertFalse($result, 'Invalid URL should return false');
    }

    /**
     * Test sanitizeInt
     */
    public function testSanitizeInt(): void
    {
        $this->assertEquals(42, \SecurityUtils::sanitizeInt('42'), 'String "42" should become int 42');
        $this->assertEquals(0, \SecurityUtils::sanitizeInt('abc'), 'Non-numeric string should become 0');
        $this->assertEquals(-5, \SecurityUtils::sanitizeInt('-5'), 'Negative string should work');
    }

    /**
     * Test sanitizeFloat
     */
    public function testSanitizeFloat(): void
    {
        $this->assertEquals(3.14, \SecurityUtils::sanitizeFloat('3.14'), 'String "3.14" should become float');
        $this->assertEquals(0.0, \SecurityUtils::sanitizeFloat('abc'), 'Non-numeric should become 0.0');
    }
}
