<?php
/**
 * ============================================================================
 * 🧪 Validator Unit Tests
 * ============================================================================
 *
 * Tests for the API Validator class covering:
 * - Required field validation
 * - Type validation (string, integer, email, URL, boolean, array, numeric)
 * - Length validation (min, max, between)
 * - Format validation (in, alpha, alphanumeric, date, json)
 * - Pipe-delimited rule parsing
 * - Error reporting (fails, passes, errors, firstError, validated)
 *
 * Does NOT test 'unique' and 'exists' rules (require database).
 *
 * @package    SIGNula\Tests\Unit\API
 * @version    2.5.0-beta
 * @see        web/private_html/api/Validator.php
 */

namespace SIGNula\Tests\Unit\API;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/api/Validator.php');

/**
 * Validator Test Suite
 */
class ValidatorTest extends TestCase
{
    // ========================================================================
    // ✅ REQUIRED RULE TESTS
    // ========================================================================

    /**
     * Test required field passes when present
     */
    public function testRequiredFieldPresent(): void
    {
        $validator = new \Validator(['name' => 'John']);
        $result = $validator->validate(['name' => ['required']]);

        $this->assertTrue($result, 'Validation should pass when required field is present');
        $this->assertTrue($validator->passes());
    }

    /**
     * Test required field fails when missing
     */
    public function testRequiredFieldMissing(): void
    {
        $validator = new \Validator([]);
        $result = $validator->validate(['name' => ['required']]);

        $this->assertFalse($result, 'Validation should fail when required field is missing');
        $this->assertTrue($validator->fails());
    }

    /**
     * Test required field fails when value is empty string
     */
    public function testRequiredFieldEmptyString(): void
    {
        $validator = new \Validator(['name' => '']);
        $result = $validator->validate(['name' => ['required']]);

        $this->assertFalse($result, 'Validation should fail when required field is empty string');
    }

    /**
     * Test required field passes when value is zero (integer)
     */
    public function testRequiredFieldZeroIntegerIsValid(): void
    {
        $validator = new \Validator(['count' => 0]);
        $result = $validator->validate(['count' => ['required']]);

        $this->assertTrue($result, 'Zero (integer) should be valid for required field');
    }

    /**
     * Test required field passes when value is string "0"
     */
    public function testRequiredFieldZeroStringIsValid(): void
    {
        $validator = new \Validator(['count' => '0']);
        $result = $validator->validate(['count' => ['required']]);

        $this->assertTrue($result, 'String "0" should be valid for required field');
    }

    // ========================================================================
    // 📧 TYPE VALIDATION TESTS
    // ========================================================================

    /**
     * Test email validation with valid email
     */
    public function testEmailValidation(): void
    {
        $validator = new \Validator(['email' => 'user@example.com']);
        $result = $validator->validate(['email' => ['email']]);

        $this->assertTrue($result, 'Valid email should pass validation');
    }

    /**
     * Test email validation with invalid email
     */
    public function testEmailInvalid(): void
    {
        $validator = new \Validator(['email' => 'not-an-email']);
        $result = $validator->validate(['email' => ['email']]);

        $this->assertFalse($result, 'Invalid email should fail validation');
    }

    /**
     * Test URL validation with valid URL
     */
    public function testUrlValidation(): void
    {
        $validator = new \Validator(['site' => 'https://example.com']);
        $result = $validator->validate(['site' => ['url']]);

        $this->assertTrue($result, 'Valid URL should pass validation');
    }

    /**
     * Test URL validation with invalid URL
     */
    public function testUrlInvalid(): void
    {
        $validator = new \Validator(['site' => 'not a url']);
        $result = $validator->validate(['site' => ['url']]);

        $this->assertFalse($result, 'Invalid URL should fail validation');
    }

    /**
     * Test integer validation with valid integer
     */
    public function testIntegerValidation(): void
    {
        $validator = new \Validator(['age' => '25']);
        $result = $validator->validate(['age' => ['integer']]);

        $this->assertTrue($result, 'Valid integer string should pass validation');
    }

    /**
     * Test integer validation with non-integer
     */
    public function testIntegerInvalid(): void
    {
        $validator = new \Validator(['age' => '3.14']);
        $result = $validator->validate(['age' => ['integer']]);

        $this->assertFalse($result, 'Float string should fail integer validation');
    }

    /**
     * Test numeric validation
     */
    public function testNumericValidation(): void
    {
        $validator = new \Validator(['price' => '19.99']);
        $result = $validator->validate(['price' => ['numeric']]);

        $this->assertTrue($result, 'Numeric string should pass numeric validation');
    }

    /**
     * Test boolean validation with valid values
     */
    public function testBooleanValidation(): void
    {
        $testCases = [true, false, 0, 1, '0', '1', 'true', 'false'];

        foreach ($testCases as $value) {
            $validator = new \Validator(['flag' => $value]);
            $result = $validator->validate(['flag' => ['boolean']]);
            $this->assertTrue($result, "Boolean value " . var_export($value, true) . " should pass validation");
        }
    }

    /**
     * Test boolean validation rejects non-boolean
     */
    public function testBooleanInvalid(): void
    {
        $validator = new \Validator(['flag' => 'yes']);
        $result = $validator->validate(['flag' => ['boolean']]);

        $this->assertFalse($result, 'Non-boolean string "yes" should fail boolean validation');
    }

    /**
     * Test string validation
     */
    public function testStringValidation(): void
    {
        $validator = new \Validator(['name' => 'John']);
        $result = $validator->validate(['name' => ['string']]);

        $this->assertTrue($result, 'String value should pass string validation');
    }

    /**
     * Test string validation rejects non-string
     */
    public function testStringInvalid(): void
    {
        $validator = new \Validator(['name' => 123]);
        $result = $validator->validate(['name' => ['string']]);

        $this->assertFalse($result, 'Integer should fail string validation');
    }

    /**
     * Test array validation
     */
    public function testArrayValidation(): void
    {
        $validator = new \Validator(['items' => ['a', 'b']]);
        $result = $validator->validate(['items' => ['array']]);

        $this->assertTrue($result, 'Array value should pass array validation');
    }

    /**
     * Test array validation rejects non-array
     */
    public function testArrayInvalid(): void
    {
        $validator = new \Validator(['items' => 'not-array']);
        $result = $validator->validate(['items' => ['array']]);

        $this->assertFalse($result, 'String should fail array validation');
    }

    // ========================================================================
    // 📏 LENGTH VALIDATION TESTS
    // ========================================================================

    /**
     * Test min length validation passes
     */
    public function testMinLengthPasses(): void
    {
        $validator = new \Validator(['name' => 'John']);
        $result = $validator->validate(['name' => ['min:3']]);

        $this->assertTrue($result, 'String of 4 chars should pass min:3');
    }

    /**
     * Test min length validation fails
     */
    public function testMinLengthFails(): void
    {
        $validator = new \Validator(['name' => 'Jo']);
        $result = $validator->validate(['name' => ['min:3']]);

        $this->assertFalse($result, 'String of 2 chars should fail min:3');
    }

    /**
     * Test max length validation passes
     */
    public function testMaxLengthPasses(): void
    {
        $validator = new \Validator(['name' => 'John']);
        $result = $validator->validate(['name' => ['max:10']]);

        $this->assertTrue($result, 'String of 4 chars should pass max:10');
    }

    /**
     * Test max length validation fails
     */
    public function testMaxLengthFails(): void
    {
        $validator = new \Validator(['name' => 'Very Long Name Here']);
        $result = $validator->validate(['name' => ['max:10']]);

        $this->assertFalse($result, 'String of 19 chars should fail max:10');
    }

    /**
     * Test between length validation
     */
    public function testBetweenLengthPasses(): void
    {
        $validator = new \Validator(['name' => 'John']);
        $result = $validator->validate(['name' => ['between:2,10']]);

        $this->assertTrue($result, 'String of 4 chars should pass between:2,10');
    }

    /**
     * Test between length validation fails (too short)
     */
    public function testBetweenLengthFailsTooShort(): void
    {
        $validator = new \Validator(['name' => 'J']);
        $result = $validator->validate(['name' => ['between:2,10']]);

        $this->assertFalse($result, 'String of 1 char should fail between:2,10');
    }

    /**
     * Test min for numeric values (value comparison, not length)
     */
    public function testMinNumericValue(): void
    {
        $validator = new \Validator(['age' => 25]);
        $result = $validator->validate(['age' => ['min:18']]);

        $this->assertTrue($result, 'Numeric value 25 should pass min:18');
    }

    // ========================================================================
    // 🔤 FORMAT VALIDATION TESTS
    // ========================================================================

    /**
     * Test 'in' rule passes with allowed value
     */
    public function testInRulePasses(): void
    {
        $validator = new \Validator(['role' => 'admin']);
        $result = $validator->validate(['role' => ['in:admin,user,moderator']]);

        $this->assertTrue($result, 'Value "admin" should pass in:admin,user,moderator');
    }

    /**
     * Test 'in' rule fails with disallowed value
     */
    public function testInRuleFails(): void
    {
        $validator = new \Validator(['role' => 'superadmin']);
        $result = $validator->validate(['role' => ['in:admin,user,moderator']]);

        $this->assertFalse($result, 'Value "superadmin" should fail in:admin,user,moderator');
    }

    /**
     * Test alpha rule
     */
    public function testAlphaRule(): void
    {
        $validator = new \Validator(['name' => 'John']);
        $result = $validator->validate(['name' => ['alpha']]);
        $this->assertTrue($result, 'Alpha-only string should pass alpha rule');

        $validator2 = new \Validator(['name' => 'John123']);
        $result2 = $validator2->validate(['name' => ['alpha']]);
        $this->assertFalse($result2, 'String with digits should fail alpha rule');
    }

    /**
     * Test alphanumeric rule
     */
    public function testAlphanumericRule(): void
    {
        $validator = new \Validator(['code' => 'ABC123']);
        $result = $validator->validate(['code' => ['alphanumeric']]);
        $this->assertTrue($result, 'Alphanumeric string should pass');

        $validator2 = new \Validator(['code' => 'ABC-123']);
        $result2 = $validator2->validate(['code' => ['alphanumeric']]);
        $this->assertFalse($result2, 'String with dashes should fail alphanumeric');
    }

    /**
     * Test date rule
     */
    public function testDateRule(): void
    {
        $validator = new \Validator(['date' => '2026-02-19']);
        $result = $validator->validate(['date' => ['date']]);
        $this->assertTrue($result, 'Valid date string should pass');

        $validator2 = new \Validator(['date' => 'not-a-date']);
        $result2 = $validator2->validate(['date' => ['date']]);
        $this->assertFalse($result2, 'Invalid date string should fail');
    }

    /**
     * Test json rule
     */
    public function testJsonRule(): void
    {
        $validator = new \Validator(['data' => '{"key":"value"}']);
        $result = $validator->validate(['data' => ['json']]);
        $this->assertTrue($result, 'Valid JSON string should pass');

        $validator2 = new \Validator(['data' => '{invalid json}']);
        $result2 = $validator2->validate(['data' => ['json']]);
        $this->assertFalse($result2, 'Invalid JSON string should fail');
    }

    // ========================================================================
    // 📋 API SURFACE TESTS
    // ========================================================================

    /**
     * Test fails() returns correct status
     */
    public function testFailsReturnsCorrectStatus(): void
    {
        $valid = new \Validator(['name' => 'John']);
        $valid->validate(['name' => ['required']]);
        $this->assertFalse($valid->fails(), 'fails() should return false for valid data');

        $invalid = new \Validator([]);
        $invalid->validate(['name' => ['required']]);
        $this->assertTrue($invalid->fails(), 'fails() should return true for invalid data');
    }

    /**
     * Test passes() returns correct status
     */
    public function testPassesReturnsCorrectStatus(): void
    {
        $valid = new \Validator(['name' => 'John']);
        $valid->validate(['name' => ['required']]);
        $this->assertTrue($valid->passes(), 'passes() should return true for valid data');
    }

    /**
     * Test errors() returns field-specific errors
     */
    public function testErrorsReturnsFieldErrors(): void
    {
        $validator = new \Validator([]);
        $validator->validate([
            'email' => ['required', 'email'],
            'name' => ['required']
        ]);

        $errors = $validator->errors();

        $this->assertArrayHasKey('email', $errors, 'Should have error for email field');
        $this->assertArrayHasKey('name', $errors, 'Should have error for name field');
        $this->assertIsArray($errors['email'], 'Field errors should be an array');
    }

    /**
     * Test firstError() returns the first error message
     */
    public function testFirstErrorReturnsFirstMessage(): void
    {
        $validator = new \Validator([]);
        $validator->validate(['name' => ['required']]);

        $firstError = $validator->firstError();

        $this->assertNotNull($firstError, 'firstError() should return a message');
        $this->assertStringContainsString('name', $firstError, 'Error should reference the field name');
    }

    /**
     * Test firstError() returns null when no errors
     */
    public function testFirstErrorReturnsNullWhenValid(): void
    {
        $validator = new \Validator(['name' => 'John']);
        $validator->validate(['name' => ['required']]);

        $this->assertNull($validator->firstError(), 'firstError() should return null when valid');
    }

    /**
     * Test validated() returns data on success
     */
    public function testValidatedReturnsDataOnSuccess(): void
    {
        $data = ['name' => 'John', 'email' => 'john@example.com'];
        $validator = new \Validator($data);
        $validator->validate(['name' => ['required'], 'email' => ['required', 'email']]);

        $this->assertEquals($data, $validator->validated(), 'validated() should return original data on success');
    }

    /**
     * Test validated() returns empty array on failure
     */
    public function testValidatedReturnsEmptyOnFailure(): void
    {
        $validator = new \Validator([]);
        $validator->validate(['name' => ['required']]);

        $this->assertEmpty($validator->validated(), 'validated() should return empty array on failure');
    }

    /**
     * Test pipe-delimited rule parsing
     */
    public function testPipeDelimitedRuleParsing(): void
    {
        $validator = new \Validator(['name' => 'Jo']);

        // 📝 Rules as pipe-delimited string instead of array
        $result = $validator->validate(['name' => 'required|min:3|max:50']);

        $this->assertFalse($result, 'Pipe-delimited rules should be parsed correctly');
        $this->assertTrue($validator->fails());
    }

    /**
     * Test fieldErrors() returns errors for specific field
     */
    public function testFieldErrorsReturnsSpecificFieldErrors(): void
    {
        $validator = new \Validator(['email' => 'invalid']);
        $validator->validate(['email' => ['email'], 'name' => ['required']]);

        $emailErrors = $validator->fieldErrors('email');
        $nameErrors = $validator->fieldErrors('name');
        $nonExistent = $validator->fieldErrors('nonexistent');

        $this->assertNotEmpty($emailErrors, 'Should have errors for email');
        $this->assertNotEmpty($nameErrors, 'Should have errors for name');
        $this->assertEmpty($nonExistent, 'Should have no errors for non-existent field');
    }

    /**
     * Test optional fields (not required) skip validation when null
     */
    public function testOptionalFieldSkipsValidationWhenNull(): void
    {
        // 📝 email is not 'required', and not present in data
        $validator = new \Validator(['name' => 'John']);
        $result = $validator->validate([
            'name' => ['required'],
            'email' => ['email']  // Not required, not present
        ]);

        $this->assertTrue($result, 'Optional field not present should not cause failure');
    }
}
