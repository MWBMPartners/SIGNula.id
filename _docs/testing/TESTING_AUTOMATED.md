# Automated Testing Guide

**Version:** 2.5.0-beta
**Framework:** PHPUnit 10.5
**PHP Requirement:** 8.3+

---

## Quick Start

### Prerequisites

- PHP 8.3+ with extensions: `mbstring`, `mysqli`, `openssl`, `json`
- Composer (installed locally at project root)
- MySQL/MariaDB (only for integration tests)

### Run Unit Tests (No Database Required)

```bash
# From project root
php vendor/bin/phpunit --testsuite="Unit Tests"
```

### Run Integration Tests (Requires Database)

```bash
# 1. Start MySQL
brew services start mysql  # macOS with Homebrew

# 2. Create test database
mysql -u root -e "CREATE DATABASE IF NOT EXISTS signula_test;"
mysql -u root signula_test < _database/signula_complete_install_v2.5.0.sql

# 3. Run integration tests
php vendor/bin/phpunit --testsuite="Integration Tests"
```

### Run All Tests

```bash
php vendor/bin/phpunit
```

---

## Test Suite Overview

### Unit Tests (130 tests, 610+ assertions)

| Test File | Tests | Description |
| --------- | ----- | ----------- |
| `_tests/Unit/Security/SecurityUtilsTest.php` | 48 | Encryption, hashing, tokens, CSRF, password validation, sanitization |
| `_tests/Unit/Security/TOTPTest.php` | 25 | Secret generation, RFC 6238 vectors, code verification, provisioning URI |
| `_tests/Unit/API/ValidatorTest.php` | 40+ | Required, type, length, format rules, pipe-delimited parsing, API surface |
| `_tests/Unit/Auth/PasswordValidationTest.php` | 17 | Data-driven password strength tests via SecurityUtils::validatePassword() |

**No database required.** Uses function stubs from `_tests/bootstrap.php` for global dependencies (`getSetting`, `getClientIP`, etc.).

### Integration Tests (46 tests)

| Test File | Tests | Description |
| --------- | ----- | ----------- |
| `_tests/Integration/Auth/AuthLoginTest.php` | 15 | Login credentials, lockout, session, registration |
| `_tests/Integration/Security/MFATest.php` | 12 | TOTP enable/verify, backup codes, disable |
| `_tests/Integration/Utils/ActivityLoggerTest.php` | 6 | Record creation, IP/UA capture, JSON metadata |
| `_tests/Integration/Utils/ErrorLoggerTest.php` | 5 | Error records, backtrace, sensitive field redaction |
| `_tests/Integration/Security/RateLimiterTest.php` | 8 | Rate limits, remaining count, unblock, progressive blocking |

**Requires `signula_test` database.** Uses transaction rollback for test isolation via `DatabaseTestCase`.

---

## Architecture

### Test Hierarchy

```
PHPUnit\Framework\TestCase
  └── SIGNula\Tests\TestCase          (unit tests base)
        └── SIGNula\Tests\DatabaseTestCase  (integration tests base)
```

### Key Files

| File | Purpose |
| ---- | ------- |
| `phpunit.xml` | PHPUnit 10.x configuration, test suites, environment variables |
| `_tests/bootstrap.php` | Constants, autoloader, global function stubs, test helpers |
| `_tests/TestCase.php` | Base class for unit tests (session, settings, output buffering) |
| `_tests/DatabaseTestCase.php` | Base class for integration tests (DB connection, transactions, helpers) |
| `_tests/Fixtures/settings.json` | Default test settings loaded by bootstrap |

### Bootstrap Function Stubs

Source classes depend on global functions defined in `web/_config/config.php`. The bootstrap provides test-compatible stubs:

| Function | Stub Behaviour |
| -------- | -------------- |
| `getSetting($key, $default)` | Reads from `$GLOBALS['test_settings']` |
| `getClientIP()` | Returns `$_SERVER['REMOTE_ADDR']` or `'127.0.0.1'` |
| `getUserAgent()` | Returns `$_SERVER['HTTP_USER_AGENT']` or `'PHPUnit/TestAgent'` |
| `redirect($url, $code)` | Throws `RuntimeException` (prevents `exit()`) |
| `jsonResponse(...)` | Stores in `$GLOBALS['last_json_response']` |
| `sanitizeInput($data)` | `htmlspecialchars(strip_tags(trim(...)))` |

### Test Settings

Override settings per-test using `setTestSetting()`:

```php
public function testCustomPasswordLength(): void
{
    setTestSetting('security.password.min_length', 20);
    $result = \SecurityUtils::validatePassword('ShortPass1!');
    $this->assertFalse($result['valid']);
}
```

Settings are automatically reset in `setUp()` via `clearTestSettings()` + `loadDefaultTestSettings()`.

---

## Database Setup for Integration Tests

### Environment Variables (phpunit.xml)

```xml
<env name="DB_HOST" value="localhost"/>
<env name="DB_NAME" value="signula_test"/>
<env name="DB_USER" value="root"/>
<env name="DB_PASS" value=""/>
```

Override these for your local environment if needed.

### DatabaseTestCase Features

- **Transaction rollback:** Each test runs in a transaction that is rolled back, keeping the database clean
- **Table truncation:** Specify `protected array $truncateTables` to truncate tables before each test
- **Helper methods:**
  - `insertRecord($table, $data)` - Insert a row and return the insert ID
  - `getRecord($table, $where)` - Fetch a single row by conditions
  - `getRecordCount($table, $where)` - Count matching rows

---

## Writing New Tests

### Unit Test Template

```php
<?php
namespace SIGNula\Tests\Unit\YourNamespace;

use SIGNula\Tests\TestCase;

// Load source class(es)
requireSource('private_html/path/to/YourClass.php');

class YourClassTest extends TestCase
{
    public function testSomething(): void
    {
        $result = \YourClass::someMethod();
        $this->assertTrue($result);
    }
}
```

### Integration Test Template

```php
<?php
namespace SIGNula\Tests\Integration\YourNamespace;

use SIGNula\Tests\DatabaseTestCase;

requireSource('_config/database.php');
requireSource('private_html/path/to/YourClass.php');

class YourClassTest extends DatabaseTestCase
{
    protected array $truncateTables = ['tblYourTable'];

    public function testDatabaseOperation(): void
    {
        $id = $this->insertRecord('tblYourTable', [
            'column1' => 'value1',
            'column2' => 'value2',
        ]);

        $record = $this->getRecord('tblYourTable', ['id' => $id]);
        $this->assertEquals('value1', $record['column1']);
    }
}
```

### Data-Driven Tests (PHPUnit 10)

```php
use PHPUnit\Framework\Attributes\DataProvider;

class MyTest extends TestCase
{
    public static function dataProvider(): array
    {
        return [
            'valid case' => ['input', true],
            'invalid case' => ['bad', false],
        ];
    }

    #[DataProvider('dataProvider')]
    public function testWithData(string $input, bool $expected): void
    {
        $this->assertEquals($expected, MyClass::validate($input));
    }
}
```

---

## Code Coverage

Generate HTML coverage report:

```bash
php vendor/bin/phpunit --coverage-html=_tests/coverage
```

> Requires Xdebug or PCOV extension.

Coverage source is configured in `phpunit.xml` under `<source>`:

```xml
<source>
    <include>
        <directory suffix=".php">web/private_html</directory>
    </include>
    <exclude>
        <directory>web/private_html/layout</directory>
    </exclude>
</source>
```

---

## Troubleshooting

### "SIGNULA_INIT not defined" errors
The bootstrap defines `SIGNULA_INIT`. Ensure `phpunit.xml` points to `_tests/bootstrap.php`.

### "Class not found" errors
Use `requireSource()` at the top of your test file to load the source class:
```php
requireSource('private_html/security/SecurityUtils.php');
```

### Integration tests fail to connect
1. Verify MySQL is running: `mysqladmin ping`
2. Check credentials in `phpunit.xml` match your local MySQL setup
3. Ensure `signula_test` database exists with the full schema

### "Risky test" warnings
PHPUnit 10 flags tests that produce unexpected output. If your test triggers `error_log()`, either suppress it or mark the test with `@doesNotPerformAssertions` if appropriate.
