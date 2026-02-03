# SIGNula Testing Guide

Comprehensive guide to testing the SIGNula authentication system.

## Table of Contents
- [Overview](#overview)
- [Setup](#setup)
- [Running Tests](#running-tests)
- [Writing Tests](#writing-tests)
- [Code Quality](#code-quality)
- [Continuous Integration](#continuous-integration)
- [Best Practices](#best-practices)

## Overview

SIGNula uses a comprehensive testing strategy:
- **Unit Tests**: Test individual functions/classes in isolation
- **Integration Tests**: Test database interactions and API endpoints
- **Code Quality**: Static analysis and style checking
- **Manual Testing**: Browser and user acceptance testing

### Testing Stack
- **PHPUnit 10+**: Unit and integration testing framework
- **PHPStan**: Static analysis for bug detection
- **PHP_CodeSniffer**: Code style and standards enforcement

## Setup

### 1. Install Dependencies

Since the project is hosted on Dreamhost shared hosting (no CLI composer access), you'll need to install dependencies locally:

```bash
# Install Composer (if not already installed)
# Visit: https://getcomposer.org/download/

# Install dependencies
composer install
```

### 2. Create Test Database

Create a separate database for testing:

```sql
CREATE DATABASE signula_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON signula_test.* TO 'your_user'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Configure Test Environment

Update environment variables in [phpunit.xml](../phpunit.xml):

```xml
<php>
    <env name="DB_HOST" value="localhost"/>
    <env name="DB_NAME" value="signula_test"/>
    <env name="DB_USER" value="your_username"/>
    <env name="DB_PASS" value="your_password"/>
</php>
```

### 4. Run Migrations on Test Database

```bash
mysql -u your_username -p signula_test < _migrations/001_initial_setup.sql
mysql -u your_username -p signula_test < _migrations/002_auth_enhancements.sql
# ... run all migrations
```

## Running Tests

### All Tests
```bash
# Run all tests
composer test

# or
./vendor/bin/phpunit
```

### Specific Test Suites
```bash
# Unit tests only
composer test:unit

# Integration tests only
composer test:integration
```

### Specific Test File
```bash
./vendor/bin/phpunit _tests/Unit/Auth/PasswordValidationTest.php
```

### With Code Coverage
```bash
composer test:coverage

# Coverage report will be in: _coverage/html/index.html
```

### Verbose Output
```bash
./vendor/bin/phpunit --verbose
```

### Stop on First Failure
```bash
./vendor/bin/phpunit --stop-on-failure
```

## Writing Tests

### Unit Test Example

Unit tests should test isolated logic without database or external dependencies:

```php
<?php
namespace SIGNula\Tests\Unit\Auth;

use SIGNula\Tests\TestCase;

class PasswordValidationTest extends TestCase
{
    public function testPasswordTooShort(): void
    {
        $password = '123';
        $this->assertFalse($this->validatePassword($password));
    }

    public function testValidPassword(): void
    {
        $password = 'SecurePass123!';
        $this->assertTrue($this->validatePassword($password));
    }

    private function validatePassword(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[0-9]/', $password);
    }
}
```

### Integration Test Example

Integration tests interact with the database:

```php
<?php
namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;

class UserRegistrationTest extends DatabaseTestCase
{
    // Tables to clean before each test
    protected array $truncateTables = ['tblUsers'];

    public function testUserCanRegister(): void
    {
        // Create user
        $user = $this->createTestUser([
            'email' => 'test@example.com',
            'displayName' => 'Test User'
        ]);

        // Assert user was created
        $this->assertGreaterThan(0, $user['userID']);

        // Assert user exists in database
        $this->assertDatabaseHas('tblUsers', [
            'email' => 'test@example.com',
            'displayName' => 'Test User'
        ]);
    }

    public function testDuplicateEmailFails(): void
    {
        $email = 'duplicate@example.com';

        // Create first user
        $this->createTestUser(['email' => $email]);

        // Attempt duplicate should throw exception
        $this->expectException(\mysqli_sql_exception::class);
        $this->createTestUser(['email' => $email]);
    }
}
```

### Test Naming Conventions

- Test classes end with `Test`: `PasswordValidationTest`
- Test methods start with `test`: `testPasswordTooShort()`
- Use descriptive names: `testUserCannotLoginWithWrongPassword()`

### Data Providers

Use data providers for testing multiple scenarios:

```php
/**
 * @dataProvider emailProvider
 */
public function testEmailValidation(string $email, bool $expected): void
{
    $result = $this->validateEmail($email);
    $this->assertEquals($expected, $result);
}

public function emailProvider(): array
{
    return [
        ['valid@example.com', true],
        ['invalid@', false],
        ['no-at-sign.com', false],
        ['test+tag@example.com', true],
    ];
}
```

### Custom Assertions

The base `TestCase` provides custom assertions:

```php
// Assert valid email
$this->assertValidEmail('user@example.com');

// Assert valid URL
$this->assertValidUrl('https://signula.id');

// Assert valid date
$this->assertValidDate('2026-02-03 14:30:00');

// Assert valid JSON
$this->assertValidJson('{"key": "value"}');

// Assert array key with value
$this->assertArrayHasKeyWithValue('expected', 'key', $array);

// Assert string contains all substrings
$this->assertStringContainsAll(['foo', 'bar'], $string);
```

### Database Assertions

For integration tests:

```php
// Assert record exists
$this->assertDatabaseHas('tblUsers', [
    'email' => 'test@example.com'
]);

// Assert record doesn't exist
$this->assertDatabaseMissing('tblUsers', [
    'email' => 'deleted@example.com'
]);

// Get record
$user = $this->getRecord('tblUsers', ['userID' => 1]);

// Count records
$count = $this->countRecords('tblUsers', ['accountStatus' => 'Active']);
```

### Fixtures

Load test data from JSON fixtures:

```php
// Load fixture
$users = load_fixture('users');

// Use fixture data
$testUser = $users['testUsers'][0];
$this->createTestUser($testUser);
```

## Code Quality

### Static Analysis with PHPStan

Analyze code for bugs and type errors:

```bash
# Run PHPStan
composer analyze

# or
./vendor/bin/phpstan analyze
```

PHPStan configuration: [phpstan.neon](../phpstan.neon)

### Code Style with PHP_CodeSniffer

Check and fix code style:

```bash
# Check code style
composer check-style

# Auto-fix issues
composer fix-style
```

PHP_CodeSniffer configuration: [phpcs.xml](../phpcs.xml)

### Run All Quality Checks

```bash
composer quality
```

This runs:
1. Code style check
2. Static analysis
3. All tests

## Best Practices

### 1. Test One Thing

Each test should verify one specific behavior:

**Good:**
```php
public function testPasswordMustContainNumber(): void
{
    $this->assertFalse($this->validatePassword('NoNumbers'));
}

public function testPasswordMustContainUppercase(): void
{
    $this->assertFalse($this->validatePassword('nouppercase123'));
}
```

**Bad:**
```php
public function testPasswordValidation(): void
{
    // Testing too many things at once
    $this->assertFalse($this->validatePassword('short'));
    $this->assertFalse($this->validatePassword('NoNumbers'));
    $this->assertTrue($this->validatePassword('GoodPass123'));
}
```

### 2. Use Descriptive Test Names

```php
// Good
public function testUserCannotDeleteOwnAccount(): void

// Bad
public function testDelete(): void
```

### 3. Arrange, Act, Assert Pattern

Structure tests in three parts:

```php
public function testUserLogin(): void
{
    // Arrange - set up test data
    $user = $this->createTestUser([
        'email' => 'test@example.com',
        'passwordHash' => password_hash('password', PASSWORD_ARGON2ID)
    ]);

    // Act - perform the action
    $result = $this->attemptLogin('test@example.com', 'password');

    // Assert - verify the result
    $this->assertTrue($result);
    $this->assertDatabaseHas('tblActivityLog', [
        'userID' => $user['userID'],
        'activity' => 'login_success'
    ]);
}
```

### 4. Don't Test Framework Code

Test your logic, not PHP/MySQL built-ins:

```php
// Bad - testing PHP's password_hash function
public function testPasswordHash(): void
{
    $hash = password_hash('test', PASSWORD_ARGON2ID);
    $this->assertNotEquals('test', $hash);
}

// Good - testing your password validation logic
public function testPasswordMeetsRequirements(): void
{
    $result = $this->meetsPasswordRequirements('Test123!');
    $this->assertTrue($result);
}
```

### 5. Keep Tests Fast

- Use transactions for database tests (automatic rollback)
- Mock external API calls
- Don't sleep() in tests

### 6. Test Edge Cases

Test boundary conditions and error cases:

```php
public function testEmptyEmail(): void
public function testVeryLongEmail(): void
public function testEmailWithSpecialCharacters(): void
public function testNullEmail(): void
```

### 7. Use setUp() and tearDown()

Initialize common test data in setUp():

```php
protected function setUp(): void
{
    parent::setUp();
    $this->testUser = $this->createTestUser();
    $this->generateCsrfToken();
}
```

## Continuous Integration

### GitHub Actions Example

Create `.github/workflows/tests.yml`:

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mysqli, mbstring

      - name: Install dependencies
        run: composer install

      - name: Run tests
        run: composer test

      - name: Run PHPStan
        run: composer analyze

      - name: Check code style
        run: composer check-style
```

## Troubleshooting

### Tests Fail with Database Connection Error

- Verify test database exists
- Check credentials in `phpunit.xml`
- Ensure MySQL is running

### Coverage Report Not Generated

- Install Xdebug: `pecl install xdebug`
- Enable in php.ini: `zend_extension=xdebug.so`

### PHPStan Memory Errors

Increase memory limit:
```bash
php -d memory_limit=1G vendor/bin/phpstan analyze
```

## Resources

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [PHPStan Documentation](https://phpstan.org/user-guide/getting-started)
- [PHP_CodeSniffer Documentation](https://github.com/squizlabs/PHP_CodeSniffer/wiki)

---

**Last Updated**: 2026-02-03
**Version**: 1.0.0
