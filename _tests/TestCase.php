<?php
/**
 * ============================================================================
 * 🧪 Base Test Case
 * ============================================================================
 *
 * All test classes should extend this base class.
 * Provides common functionality, custom assertions, and session management.
 *
 * @package SIGNula\Tests
 * @version 2.6.0-beta
 */

namespace SIGNula\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base TestCase for all SIGNula tests
 *
 * Provides:
 * - Session management for tests
 * - Custom assertions (email, URL, date, JSON, etc.)
 * - Mock file upload helpers
 * - User authentication simulation
 * - CSRF token generation
 */
abstract class TestCase extends PHPUnitTestCase
{
    /**
     * 🔧 Set up before each test
     *
     * Initializes session, clears superglobals, and resets test settings.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 🔑 Ensure an ACTIVE session for every test (hermetic-test fix).
        //
        // 🐞 bootstrap.php starts a real session before any output so that
        //    session_status() is PHP_SESSION_ACTIVE process-wide. That keeps
        //    production code (e.g. SecurityUtils::generateCSRFToken()) from ever
        //    reaching its own session_start() call — which would otherwise emit
        //    "session_start(): ... headers have already been sent" once the CLI
        //    banner output flipped headers_sent() to true, failing the suite under
        //    failOnWarning="true".
        //
        //    We deliberately do NOT gate this on !headers_sent(): the previous
        //    version did, and that gate is what let the session stay unstarted
        //    (headers were already sent by the banner) and produced the flaky
        //    warning. If — for any reason — no session is active, re-establish one
        //    using the isolated fallback path below so state cannot bleed and no
        //    warning surfaces.
        if (session_status() === PHP_SESSION_NONE) {
            if (headers_sent()) {
                // 🧰 Headers already flushed (e.g. banner printed): a normal
                //    session_start() would warn. Use an isolated in-memory-ish
                //    save path we control so the call succeeds silently.
                @ini_set('session.use_cookies', '0');
                @ini_set('session.use_only_cookies', '0');
                @ini_set('session.cache_limiter', '');
            }
            @session_start();
        }

        // 🧹 Clear session data (session stays ACTIVE, only its contents reset).
        $_SESSION = [];

        // 🧹 Reset superglobals
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];

        // 🧹 Reset request-scoped $_SERVER keys that CORS / header / routing code
        //    reads, so no test can leak an Origin, method, URI or content-type
        //    decision into another under random execution order. (Belt-and-braces:
        //    the API header tests run in subprocesses, but unit tests that set
        //    these in-process — e.g. RouterTest — must not bleed either.)
        unset(
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['CONTENT_TYPE'],
            $_SERVER['HTTP_AUTHORIZATION']
        );

        // 🧹 Clear test settings and reload defaults
        clearTestSettings();
        loadDefaultTestSettings();
    }

    /**
     * 🧹 Clean up after each test
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // 📝 Only clean output buffers that were started during the test,
        // not PHPUnit's own buffers. PHPUnit typically maintains 1 buffer level.
        while (ob_get_level() > 1) {
            ob_end_clean();
        }

        // 🧹 Symmetric cleanup: strip request-scoped $_SERVER keys a test may have
        //    set (CORS Origin, method, URI, content-type, auth) so nothing leaks
        //    into the next test before its setUp() runs. Keeps tests order-safe
        //    under executionOrder="random".
        unset(
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['CONTENT_TYPE'],
            $_SERVER['HTTP_AUTHORIZATION']
        );

        // 🧹 Reset session contents (session itself stays active for the process).
        $_SESSION = [];

        parent::tearDown();
    }

    // ========================================================================
    // ✅ CUSTOM ASSERTIONS
    // ========================================================================

    /**
     * Assert that an array has a specific key with a specific value
     *
     * @param mixed  $expected Expected value
     * @param string $key      Array key
     * @param array  $array    Array to check
     * @param string $message  Optional message
     * @return void
     */
    protected function assertArrayHasKeyWithValue(mixed $expected, string $key, array $array, string $message = ''): void
    {
        $this->assertArrayHasKey($key, $array, $message);
        $this->assertEquals($expected, $array[$key], $message);
    }

    /**
     * Assert that a string contains multiple substrings
     *
     * @param array  $needles  Array of strings to find
     * @param string $haystack String to search in
     * @param string $message  Optional message
     * @return void
     */
    protected function assertStringContainsAll(array $needles, string $haystack, string $message = ''): void
    {
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $haystack, $message);
        }
    }

    /**
     * Assert that a variable is a valid email address
     *
     * @param mixed  $value   Value to check
     * @param string $message Optional message
     * @return void
     */
    protected function assertValidEmail(mixed $value, string $message = ''): void
    {
        $this->assertIsString($value, $message);
        $this->assertTrue(
            filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            $message ?: "Failed asserting that '{$value}' is a valid email address"
        );
    }

    /**
     * Assert that a variable is a valid URL
     *
     * @param mixed  $value   Value to check
     * @param string $message Optional message
     * @return void
     */
    protected function assertValidUrl(mixed $value, string $message = ''): void
    {
        $this->assertIsString($value, $message);
        $this->assertTrue(
            filter_var($value, FILTER_VALIDATE_URL) !== false,
            $message ?: "Failed asserting that '{$value}' is a valid URL"
        );
    }

    /**
     * Assert that a date string is valid
     *
     * @param string $date    Date string
     * @param string $format  Expected format (default: Y-m-d H:i:s)
     * @param string $message Optional message
     * @return void
     */
    protected function assertValidDate(string $date, string $format = 'Y-m-d H:i:s', string $message = ''): void
    {
        $dateTime = \DateTime::createFromFormat($format, $date);
        $this->assertInstanceOf(
            \DateTime::class,
            $dateTime,
            $message ?: "Failed asserting that '{$date}' is a valid date in format '{$format}'"
        );
        $this->assertEquals($date, $dateTime->format($format), $message);
    }

    /**
     * Assert that a value is a valid JSON string
     *
     * @param string $json    JSON string
     * @param string $message Optional message
     * @return void
     */
    protected function assertValidJson(string $json, string $message = ''): void
    {
        json_decode($json);
        $this->assertEquals(
            JSON_ERROR_NONE,
            json_last_error(),
            $message ?: "Failed asserting that string is valid JSON: " . json_last_error_msg()
        );
    }

    // ========================================================================
    // 🔧 HELPER METHODS
    // ========================================================================

    /**
     * Create a mock file upload
     *
     * @param string $name     Form field name
     * @param string $filename Original filename
     * @param string $tmpName  Temporary file path
     * @param string $type     MIME type
     * @param int    $size     File size in bytes
     * @param int    $error    Error code (default: UPLOAD_ERR_OK)
     * @return void
     */
    protected function mockFileUpload(
        string $name,
        string $filename,
        string $tmpName,
        string $type = 'text/plain',
        int $size = 1024,
        int $error = UPLOAD_ERR_OK
    ): void {
        $_FILES[$name] = [
            'name' => $filename,
            'type' => $type,
            'tmp_name' => $tmpName,
            'error' => $error,
            'size' => $size
        ];
    }

    /**
     * Set authenticated user in session for testing
     *
     * @param int   $userID   User ID
     * @param array $userData Additional user data
     * @return void
     */
    protected function actingAs(int $userID, array $userData = []): void
    {
        $_SESSION['user_id'] = $userID;
        $_SESSION['authenticated'] = true;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        foreach ($userData as $key => $value) {
            $_SESSION[$key] = $value;
        }
    }

    /**
     * Generate a CSRF token for testing
     *
     * @return string CSRF token
     */
    protected function generateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();
        return $token;
    }
}
