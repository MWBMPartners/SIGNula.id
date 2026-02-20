<?php
/**
 * ============================================================================
 * 🧪 TOTP Unit Tests
 * ============================================================================
 *
 * Tests for the TOTP (Time-based One-Time Password) class covering:
 * - Secret generation (Base32 format, length, uniqueness)
 * - Code generation (deterministic, RFC 6238 test vectors)
 * - Code verification (correct, incorrect, window tolerance, format)
 * - Provisioning URI generation (otpauth:// format)
 * - Utility methods (time step, remaining time, secret validation)
 *
 * These tests do NOT require a database connection.
 * All cryptographic operations are pure functions.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.5.0-beta
 * @see        web/private_html/security/TOTP.php
 * @see        https://www.rfc-editor.org/rfc/rfc6238 (TOTP specification)
 * @see        https://www.rfc-editor.org/rfc/rfc4226 (HOTP specification)
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/security/TOTP.php');

/**
 * TOTP Test Suite
 *
 * Comprehensive unit tests for the RFC 6238 TOTP implementation.
 * Includes known test vectors for algorithm verification.
 */
class TOTPTest extends TestCase
{
    // ========================================================================
    // 🔑 SECRET GENERATION TESTS
    // ========================================================================

    /**
     * Test generateSecret returns a valid Base32 string
     */
    public function testGenerateSecretReturnsBase32String(): void
    {
        $secret = \TOTP::generateSecret();

        $this->assertNotEmpty($secret, 'Secret should not be empty');
        // 📝 Base32 alphabet: A-Z and 2-7
        $this->assertMatchesRegularExpression(
            '/^[A-Z2-7]+=*$/',
            $secret,
            'Secret should be a valid Base32 string (A-Z, 2-7, optional = padding)'
        );
    }

    /**
     * Test generateSecret returns correct length for 20-byte (160-bit) key
     */
    public function testGenerateSecretCorrectLength(): void
    {
        $secret = \TOTP::generateSecret();

        // 📝 20 bytes = 160 bits. Base32 encodes 5 bits per char.
        // 160 / 5 = 32 characters (no padding needed for 20 bytes)
        $this->assertEquals(32, strlen($secret), 'Secret should be 32 Base32 characters (20 bytes / 160 bits)');
    }

    /**
     * Test generateSecret produces unique secrets
     */
    public function testGenerateSecretUniqueness(): void
    {
        $secrets = [];
        for ($i = 0; $i < 50; $i++) {
            $secrets[] = \TOTP::generateSecret();
        }

        $unique = array_unique($secrets);
        $this->assertCount(50, $unique, 'All 50 generated secrets should be unique');
    }

    // ========================================================================
    // 🔢 CODE GENERATION TESTS
    // ========================================================================

    /**
     * Test generateCode returns a 6-digit string
     */
    public function testGenerateCodeReturns6DigitString(): void
    {
        $secret = \TOTP::generateSecret();
        $code = \TOTP::generateCode($secret);

        $this->assertEquals(6, strlen($code), 'Code should be exactly 6 characters');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code, 'Code should be exactly 6 digits');
    }

    /**
     * Test generateCode is deterministic for the same secret and timestamp
     */
    public function testGenerateCodeDeterministicForSameTimestamp(): void
    {
        $secret = \TOTP::generateSecret();
        $timestamp = 1000000;

        $code1 = \TOTP::generateCode($secret, $timestamp);
        $code2 = \TOTP::generateCode($secret, $timestamp);

        $this->assertEquals($code1, $code2, 'Same secret + timestamp should produce same code');
    }

    /**
     * Test generateCode produces different codes for different time steps
     */
    public function testGenerateCodeDifferentForDifferentTimeSteps(): void
    {
        $secret = \TOTP::generateSecret();

        // 📝 Time steps are 30 seconds apart, so codes at t=0 and t=30 should differ
        $code1 = \TOTP::generateCode($secret, 0);
        $code2 = \TOTP::generateCode($secret, 30);

        $this->assertNotEquals($code1, $code2, 'Different time steps should produce different codes');
    }

    /**
     * Test generateCode with known RFC 6238 test vector
     *
     * RFC 6238 Appendix B defines test vectors for SHA1:
     * Secret: "12345678901234567890" (20 bytes ASCII)
     * Base32 of that secret: GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
     *
     * @see https://www.rfc-editor.org/rfc/rfc6238#appendix-B
     */
    public function testGenerateCodeWithKnownVector(): void
    {
        // 📝 The RFC 6238 test secret is the ASCII string "12345678901234567890"
        // Its Base32 encoding is: GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        // 📝 RFC 6238 test vector: at time = 59, expected TOTP = 287082
        $code = \TOTP::generateCode($secret, 59);
        $this->assertEquals('287082', $code, 'RFC 6238 test vector: time=59 should produce 287082');

        // 📝 RFC 6238 test vector: at time = 1111111109, expected TOTP = 081804
        $code = \TOTP::generateCode($secret, 1111111109);
        $this->assertEquals('081804', $code, 'RFC 6238 test vector: time=1111111109 should produce 081804');

        // 📝 RFC 6238 test vector: at time = 1234567890, expected TOTP = 005924
        $code = \TOTP::generateCode($secret, 1234567890);
        $this->assertEquals('005924', $code, 'RFC 6238 test vector: time=1234567890 should produce 005924');
    }

    /**
     * Test generateCode pads with leading zeros
     */
    public function testGenerateCodePadsWithLeadingZeros(): void
    {
        $secret = \TOTP::generateSecret();

        // 📝 Generate many codes and verify they're all exactly 6 digits
        // (some will have leading zeros due to modulo operation)
        for ($t = 0; $t < 100; $t++) {
            $code = \TOTP::generateCode($secret, $t * 30);
            $this->assertEquals(6, strlen($code), "Code at step {$t} should be exactly 6 characters");
        }
    }

    // ========================================================================
    // ✅ CODE VERIFICATION TESTS
    // ========================================================================

    /**
     * Test verifyCode with correct code
     */
    public function testVerifyCodeCorrectCode(): void
    {
        $secret = \TOTP::generateSecret();
        $timestamp = time();
        $code = \TOTP::generateCode($secret, $timestamp);

        $this->assertTrue(
            \TOTP::verifyCode($secret, $code, $timestamp),
            'Correct code should verify successfully'
        );
    }

    /**
     * Test verifyCode with incorrect code
     */
    public function testVerifyCodeIncorrectCode(): void
    {
        $secret = \TOTP::generateSecret();
        $timestamp = time();

        $this->assertFalse(
            \TOTP::verifyCode($secret, '000000', $timestamp),
            'Incorrect code should fail verification'
        );
    }

    /**
     * Test verifyCode accepts code within window tolerance (clock drift)
     */
    public function testVerifyCodeWithinWindowTolerance(): void
    {
        $secret = \TOTP::generateSecret();
        $timestamp = 1000000;

        // 📝 Generate code for one time step behind (simulating 30s clock drift)
        $previousCode = \TOTP::generateCode($secret, $timestamp - 30);

        // Verify at current timestamp with window=1 (allows ±30s)
        $this->assertTrue(
            \TOTP::verifyCode($secret, $previousCode, $timestamp, 1),
            'Code from previous time step should verify with window=1'
        );
    }

    /**
     * Test verifyCode rejects code outside window tolerance
     */
    public function testVerifyCodeOutsideWindowTolerance(): void
    {
        $secret = \TOTP::generateSecret();
        $timestamp = 1000000;

        // 📝 Generate code for 3 time steps behind (90 seconds ago)
        $oldCode = \TOTP::generateCode($secret, $timestamp - 90);

        // Verify with window=1 (only allows ±30s)
        $this->assertFalse(
            \TOTP::verifyCode($secret, $oldCode, $timestamp, 1),
            'Code from 3 time steps ago should fail with window=1'
        );
    }

    /**
     * Test verifyCode strips spaces and dashes from input
     */
    public function testVerifyCodeStripsSpacesAndDashes(): void
    {
        $secret = \TOTP::generateSecret();
        $timestamp = time();
        $code = \TOTP::generateCode($secret, $timestamp);

        // 📝 Insert spaces and dashes (common user input patterns)
        $codeWithSpaces = substr($code, 0, 3) . ' ' . substr($code, 3);
        $codeWithDashes = substr($code, 0, 3) . '-' . substr($code, 3);

        $this->assertTrue(
            \TOTP::verifyCode($secret, $codeWithSpaces, $timestamp),
            'Code with spaces should be accepted'
        );
        $this->assertTrue(
            \TOTP::verifyCode($secret, $codeWithDashes, $timestamp),
            'Code with dashes should be accepted'
        );
    }

    /**
     * Test verifyCode rejects non-numeric input
     */
    public function testVerifyCodeRejectsNonNumeric(): void
    {
        $secret = \TOTP::generateSecret();

        $this->assertFalse(
            \TOTP::verifyCode($secret, 'abcdef'),
            'Non-numeric code should be rejected'
        );
    }

    /**
     * Test verifyCode rejects wrong-length input
     */
    public function testVerifyCodeRejectsWrongLength(): void
    {
        $secret = \TOTP::generateSecret();

        $this->assertFalse(\TOTP::verifyCode($secret, '12345'), 'Too-short code should be rejected');
        $this->assertFalse(\TOTP::verifyCode($secret, '1234567'), 'Too-long code should be rejected');
    }

    // ========================================================================
    // 📱 PROVISIONING URI TESTS
    // ========================================================================

    /**
     * Test getProvisioningUri returns otpauth:// format
     */
    public function testGetProvisioningUriFormat(): void
    {
        $secret = \TOTP::generateSecret();
        $uri = \TOTP::getProvisioningUri($secret, 'user@example.com', 'SIGNula');

        $this->assertStringStartsWith('otpauth://totp/', $uri, 'URI should start with otpauth://totp/');
    }

    /**
     * Test getProvisioningUri contains required parameters
     */
    public function testGetProvisioningUriContainsRequiredParams(): void
    {
        $secret = 'JBSWY3DPEHPK3PXP';
        $uri = \TOTP::getProvisioningUri($secret, 'user@example.com', 'SIGNula');

        $this->assertStringContainsString('secret=' . $secret, $uri, 'URI should contain the secret');
        $this->assertStringContainsString('issuer=SIGNula', $uri, 'URI should contain the issuer');
        $this->assertStringContainsString('user%40example.com', $uri, 'URI should contain URL-encoded account');
    }

    /**
     * Test getProvisioningUri includes algorithm, digits, and period
     */
    public function testGetProvisioningUriContainsAlgorithmParams(): void
    {
        $secret = \TOTP::generateSecret();
        $uri = \TOTP::getProvisioningUri($secret, 'test@test.com');

        $this->assertStringContainsString('algorithm=SHA1', $uri, 'URI should contain algorithm');
        $this->assertStringContainsString('digits=6', $uri, 'URI should contain digits=6');
        $this->assertStringContainsString('period=30', $uri, 'URI should contain period=30');
    }

    // ========================================================================
    // 🧪 UTILITY METHOD TESTS
    // ========================================================================

    /**
     * Test getCurrentTimeStep returns an integer
     */
    public function testGetCurrentTimeStepReturnsInteger(): void
    {
        $timeStep = \TOTP::getCurrentTimeStep();

        $this->assertIsInt($timeStep, 'Time step should be an integer');
        $this->assertGreaterThan(0, $timeStep, 'Time step should be positive');
    }

    /**
     * Test getCurrentTimeStep is deterministic for same timestamp
     */
    public function testGetCurrentTimeStepDeterministic(): void
    {
        $step1 = \TOTP::getCurrentTimeStep(1000000);
        $step2 = \TOTP::getCurrentTimeStep(1000000);

        $this->assertEquals($step1, $step2, 'Same timestamp should produce same time step');

        // 📝 floor(1000000 / 30) = 33333
        $this->assertEquals(33333, $step1, 'Time step for 1000000 should be 33333');
    }

    /**
     * Test getRemainingTime returns value between 1 and 30
     */
    public function testGetRemainingTimeReturnsValidRange(): void
    {
        $remaining = \TOTP::getRemainingTime();

        $this->assertGreaterThanOrEqual(1, $remaining, 'Remaining time should be >= 1');
        $this->assertLessThanOrEqual(30, $remaining, 'Remaining time should be <= 30');
    }

    /**
     * Test getRemainingTime is correct for known timestamp
     */
    public function testGetRemainingTimeForKnownTimestamp(): void
    {
        // 📝 At timestamp 59: 59 % 30 = 29, remaining = 30 - 29 = 1
        $remaining = \TOTP::getRemainingTime(59);
        $this->assertEquals(1, $remaining, 'At timestamp 59, remaining should be 1 second');

        // 📝 At timestamp 60: 60 % 30 = 0, remaining = 30 - 0 = 30
        $remaining = \TOTP::getRemainingTime(60);
        $this->assertEquals(30, $remaining, 'At timestamp 60, remaining should be 30 seconds');
    }

    /**
     * Test validateSecret with valid Base32 secret
     */
    public function testValidateSecretValidSecret(): void
    {
        $secret = \TOTP::generateSecret();

        $this->assertTrue(
            \TOTP::validateSecret($secret),
            'Generated secret should be valid'
        );
    }

    /**
     * Test validateSecret with known valid Base32 string
     */
    public function testValidateSecretKnownValidString(): void
    {
        $this->assertTrue(
            \TOTP::validateSecret('JBSWY3DPEHPK3PXP'),
            'Known Base32 string should be valid'
        );
    }

    /**
     * Test validateSecret with invalid string
     */
    public function testValidateSecretInvalidString(): void
    {
        $this->assertFalse(
            \TOTP::validateSecret('invalid!@#$%'),
            'String with non-Base32 characters should be invalid'
        );
    }
}
