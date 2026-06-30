<?php
/**
 * ============================================================================
 * 🧪 Auth Decision-Logic Characterization Tests (STABILIZE-2 safety net)
 * ============================================================================
 *
 * Characterizes the PURE / pre-database decision logic in the Auth class — the
 * parts that the JWT/OAuth/OIDC builds will sit next to. Auth is heavily
 * database-coupled (login/register persistence, lockout counting, session
 * lookup), so those paths are covered by the existing Integration tests
 * (Integration/Auth/AuthLoginTest.php) and are NOT duplicated here.
 *
 * What IS pure and pinned here:
 * - 📝 register() input gates that return BEFORE any Database:: call:
 *      invalid email, username too short, username too long.
 * - 🆔 generateUUID() — RFC 4122 v4 format/length/uniqueness (via reflection),
 *      anchoring the user-identifier format the OAuth account-link build relies on.
 * - 📱 getDeviceInfo() — UA→device/browser/OS mapping (via reflection), which
 *      feeds the session rows the auth seam writes.
 *
 * Argon2id hashing/verification and password-policy validation already have
 * dedicated unit suites (SecurityUtilsTest, PasswordValidationTest) — not
 * re-asserted here to avoid hollow duplication.
 *
 * @package    SIGNula\Tests\Unit\Auth
 * @version    2.7.0-beta
 * @see        web/private_html/auth/Auth.php
 * @see        https://www.rfc-editor.org/rfc/rfc4122 (UUID v4)
 */

namespace SIGNula\Tests\Unit\Auth;

use SIGNula\Tests\TestCase;
use ReflectionMethod;

// 📦 Load source classes. SecurityUtils is a hard dependency of register().
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/auth/Auth.php');

/**
 * Auth Decision-Logic Characterization Test Suite
 */
class AuthDecisionTest extends TestCase
{
    /**
     * Invoke a private static Auth method via reflection.
     */
    private function callPrivateStatic(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(\Auth::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    // ========================================================================
    // 📝 REGISTER() PRE-DATABASE INPUT GATES
    // ========================================================================
    // These branches return before any Database:: call, so they are exercisable
    // without a database connection. They protect the OAuth provisioning path,
    // which will reuse register() for first-login account creation.

    /**
     * register() rejects an invalid email before touching the database.
     */
    public function testRegisterRejectsInvalidEmail(): void
    {
        $result = \Auth::register('not-an-email', 'validuser', 'Str0ng!P@ssw0rd');

        $this->assertFalse($result['success']);
        $this->assertNull($result['userID']);
        $this->assertSame('Invalid email address', $result['message']);
    }

    /**
     * register() rejects a username shorter than 3 characters.
     */
    public function testRegisterRejectsShortUsername(): void
    {
        $result = \Auth::register('good@example.com', 'ab', 'Str0ng!P@ssw0rd');

        $this->assertFalse($result['success']);
        $this->assertNull($result['userID']);
        $this->assertStringContainsString('between 3 and 30', $result['message']);
    }

    /**
     * register() rejects a username longer than 30 characters.
     */
    public function testRegisterRejectsLongUsername(): void
    {
        $result = \Auth::register('good@example.com', str_repeat('x', 31), 'Str0ng!P@ssw0rd');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('between 3 and 30', $result['message']);
    }

    /**
     * register() result always carries the documented keys, even on failure.
     */
    public function testRegisterResultShapeOnFailure(): void
    {
        $result = \Auth::register('not-an-email', 'x', '');

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('userID', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertIsBool($result['success']);
    }

    // ========================================================================
    // 🆔 generateUUID() — RFC 4122 v4
    // ========================================================================

    /**
     * generateUUID() returns a 36-char RFC 4122 version-4 UUID.
     */
    public function testGenerateUuidFormat(): void
    {
        $uuid = $this->callPrivateStatic('generateUUID');

        $this->assertIsString($uuid);
        $this->assertSame(36, strlen($uuid));
        // 8-4-4-4-12 with version nibble '4' and variant nibble in [89ab].
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid
        );
    }

    /**
     * generateUUID() produces unique values across many calls.
     */
    public function testGenerateUuidUniqueness(): void
    {
        $uuids = [];
        for ($i = 0; $i < 200; $i++) {
            $uuids[] = $this->callPrivateStatic('generateUUID');
        }

        $this->assertCount(200, array_unique($uuids), 'All generated UUIDs should be unique');
    }

    // ========================================================================
    // 📱 getDeviceInfo() — user-agent parsing
    // ========================================================================

    /**
     * getDeviceInfo() returns the full set of device keys with sane defaults
     * for an unknown user agent.
     */
    public function testGetDeviceInfoDefaultsForUnknownUa(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'SomeOpaqueClient/1.0';

        $info = $this->callPrivateStatic('getDeviceInfo');

        $this->assertSame('web', $info['deviceType']);
        $this->assertSame('Web Browser', $info['deviceName']);
        $this->assertSame('Unknown', $info['browserName']);
        $this->assertSame('Unknown', $info['osName']);
        $this->assertFalse((bool) $info['isMobile']);
    }

    /**
     * getDeviceInfo() detects Chrome on Windows desktop.
     */
    public function testGetDeviceInfoDetectsChromeWindows(): void
    {
        $_SERVER['HTTP_USER_AGENT'] =
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

        $info = $this->callPrivateStatic('getDeviceInfo');

        $this->assertSame('Chrome', $info['browserName']);
        $this->assertSame('120.0.0.0', $info['browserVersion']);
        $this->assertSame('Windows', $info['osName']);
        $this->assertSame('10.0', $info['osVersion']);
        $this->assertFalse((bool) $info['isMobile']);
        $this->assertSame('web', $info['deviceType']);
    }

    /**
     * getDeviceInfo() flags a mobile iPhone user agent.
     */
    public function testGetDeviceInfoDetectsMobile(): void
    {
        $_SERVER['HTTP_USER_AGENT'] =
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605 Firefox/121.0';

        $info = $this->callPrivateStatic('getDeviceInfo');

        $this->assertTrue((bool) $info['isMobile']);
        // 📝 iPhone (not iPad/tablet) maps to the 'mobile_ios' device type today.
        $this->assertSame('mobile_ios', $info['deviceType']);
        $this->assertSame('Firefox', $info['browserName']);
    }

    /**
     * getDeviceInfo() classifies an iPad UA as a tablet.
     */
    public function testGetDeviceInfoDetectsTablet(): void
    {
        $_SERVER['HTTP_USER_AGENT'] =
            'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605 Safari/604.1';

        $info = $this->callPrivateStatic('getDeviceInfo');

        $this->assertTrue((bool) $info['isMobile']);
        $this->assertSame('tablet', $info['deviceType']);
    }
}
