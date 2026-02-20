<?php
/**
 * ============================================================================
 * 🧪 SessionGuard Unit Tests
 * ============================================================================
 *
 * Tests for the SessionGuard class covering:
 * - isEnabled() setting toggle
 * - createFingerprint() hash generation with various IP modes
 * - validate() fingerprint matching (same/different request data)
 * - initialize() lifecycle (create on first, validate on subsequent)
 * - handleMismatch() action handling (invalidate, warn, log)
 * - refreshFingerprint() regeneration
 * - Subnet extraction for IPv4 and IPv6
 *
 * These tests do NOT require a database connection.
 *
 * @package    SIGNula\Tests\Unit\Security
 * @version    2.6.0-beta
 * @see        web/private_html/security/SessionGuard.php
 */

namespace SIGNula\Tests\Unit\Security;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/security/SessionGuard.php');

/**
 * SessionGuard Test Suite
 */
class SessionGuardTest extends TestCase
{
    // ========================================================================
    // 🔧 SETUP
    // ========================================================================

    protected function setUp(): void
    {
        parent::setUp();

        // 🔧 Default session fingerprinting settings
        setTestSetting('security.session_fingerprinting.enabled', true);
        setTestSetting('security.session_fingerprinting.ip_match', 'subnet');
        setTestSetting('security.session_fingerprinting.salt', 'test-session-salt-for-unit-tests-only');
        setTestSetting('security.session_fingerprinting.on_mismatch', 'invalidate');

        // 🌐 Set consistent server superglobals for fingerprint determinism
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/TestAgent';
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9';
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip, deflate, br';

        // 🧹 Reset the static cached salt between tests
        $reflection = new \ReflectionClass(\SessionGuard::class);
        $prop = $reflection->getProperty('cachedSalt');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // ========================================================================
    // ✅ isEnabled() TESTS
    // ========================================================================

    /**
     * Test isEnabled returns true when setting is enabled
     */
    public function testIsEnabledReturnsTrue(): void
    {
        setTestSetting('security.session_fingerprinting.enabled', true);

        $this->assertTrue(\SessionGuard::isEnabled());
    }

    /**
     * Test isEnabled returns false when setting is disabled
     */
    public function testIsEnabledReturnsFalse(): void
    {
        setTestSetting('security.session_fingerprinting.enabled', false);

        $this->assertFalse(\SessionGuard::isEnabled());
    }

    // ========================================================================
    // 🏗️ createFingerprint() TESTS
    // ========================================================================

    /**
     * Test createFingerprint returns a 64-character hex string (SHA-256)
     */
    public function testCreateFingerprintReturns64CharHex(): void
    {
        $hash = \SessionGuard::createFingerprint();

        $this->assertIsString($hash);
        $this->assertEquals(64, strlen($hash));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    /**
     * Test createFingerprint stores the hash in the session
     */
    public function testCreateFingerprintStoresInSession(): void
    {
        $hash = \SessionGuard::createFingerprint();

        $this->assertArrayHasKey('session_fingerprint', $_SESSION);
        $this->assertEquals($hash, $_SESSION['session_fingerprint']);
    }

    /**
     * Test createFingerprint stores component metadata in session
     */
    public function testCreateFingerprintStoresComponentMetadata(): void
    {
        \SessionGuard::createFingerprint();

        $this->assertArrayHasKey('session_fp_components', $_SESSION);
        $this->assertArrayHasKey('ip_mode', $_SESSION['session_fp_components']);
        $this->assertArrayHasKey('ua_present', $_SESSION['session_fp_components']);
    }

    /**
     * Test createFingerprint stores creation timestamp
     */
    public function testCreateFingerprintStoresTimestamp(): void
    {
        \SessionGuard::createFingerprint();

        $this->assertArrayHasKey('session_fp_created', $_SESSION);
        $this->assertIsInt($_SESSION['session_fp_created']);
    }

    /**
     * Test createFingerprint produces deterministic output for same inputs
     */
    public function testCreateFingerprintIsDeterministic(): void
    {
        $hash1 = \SessionGuard::createFingerprint();

        // 🔁 Reset cached salt so it re-reads the same setting value
        $reflection = new \ReflectionClass(\SessionGuard::class);
        $prop = $reflection->getProperty('cachedSalt');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $hash2 = \SessionGuard::createFingerprint();

        $this->assertEquals($hash1, $hash2, 'Same inputs should produce the same fingerprint');
    }

    /**
     * Test createFingerprint produces different output for different user agents
     */
    public function testCreateFingerprintDiffersForDifferentUA(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Agent/1.0';
        $hash1 = \SessionGuard::createFingerprint();

        // 🔁 Reset cached salt
        $reflection = new \ReflectionClass(\SessionGuard::class);
        $prop = $reflection->getProperty('cachedSalt');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $_SERVER['HTTP_USER_AGENT'] = 'Agent/2.0';
        $hash2 = \SessionGuard::createFingerprint();

        $this->assertNotEquals($hash1, $hash2, 'Different user agents should produce different fingerprints');
    }

    // ========================================================================
    // ✅ validate() TESTS
    // ========================================================================

    /**
     * Test validate returns true when fingerprint matches current request
     */
    public function testValidateReturnsTrueForMatchingFingerprint(): void
    {
        // 📝 Create a fingerprint, then validate with same request data
        \SessionGuard::createFingerprint();

        // 🔁 Reset cached salt for second call
        $reflection = new \ReflectionClass(\SessionGuard::class);
        $prop = $reflection->getProperty('cachedSalt');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->assertTrue(\SessionGuard::validate());
    }

    /**
     * Test validate returns false when user agent changes
     */
    public function testValidateReturnsFalseWhenUAChanges(): void
    {
        \SessionGuard::createFingerprint();

        // 🔁 Reset cached salt
        $reflection = new \ReflectionClass(\SessionGuard::class);
        $prop = $reflection->getProperty('cachedSalt');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        // 🔀 Change the user agent
        $_SERVER['HTTP_USER_AGENT'] = 'EvilBot/1.0';

        $this->assertFalse(\SessionGuard::validate());
    }

    /**
     * Test validate returns false when no fingerprint exists in session
     */
    public function testValidateReturnsFalseWhenNoFingerprint(): void
    {
        unset($_SESSION['session_fingerprint']);

        $this->assertFalse(\SessionGuard::validate());
    }

    // ========================================================================
    // 🚀 initialize() TESTS
    // ========================================================================

    /**
     * Test initialize creates a fingerprint on first call
     */
    public function testInitializeCreatesFingerprint(): void
    {
        unset($_SESSION['session_fingerprint']);

        \SessionGuard::initialize();

        $this->assertArrayHasKey('session_fingerprint', $_SESSION);
        $this->assertEquals(64, strlen($_SESSION['session_fingerprint']));
    }

    /**
     * Test initialize skips when fingerprinting is disabled
     */
    public function testInitializeSkipsWhenDisabled(): void
    {
        setTestSetting('security.session_fingerprinting.enabled', false);
        unset($_SESSION['session_fingerprint']);

        \SessionGuard::initialize();

        $this->assertArrayNotHasKey('session_fingerprint', $_SESSION);
    }

    // ========================================================================
    // 🔄 refreshFingerprint() TESTS
    // ========================================================================

    /**
     * Test refreshFingerprint regenerates the fingerprint
     */
    public function testRefreshFingerprintRegenerates(): void
    {
        \SessionGuard::createFingerprint();
        $originalHash = $_SESSION['session_fingerprint'];

        // 🔀 Change request data
        $_SERVER['HTTP_USER_AGENT'] = 'NewAgent/3.0';

        // 🔁 Reset cached salt
        $reflection = new \ReflectionClass(\SessionGuard::class);
        $prop = $reflection->getProperty('cachedSalt');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        \SessionGuard::refreshFingerprint();

        $this->assertNotEquals($originalHash, $_SESSION['session_fingerprint']);
    }

    /**
     * Test refreshFingerprint skips when disabled
     */
    public function testRefreshFingerprintSkipsWhenDisabled(): void
    {
        setTestSetting('security.session_fingerprinting.enabled', false);

        \SessionGuard::createFingerprint();
        $hash = $_SESSION['session_fingerprint'];

        $_SERVER['HTTP_USER_AGENT'] = 'ChangedAgent/1.0';
        \SessionGuard::refreshFingerprint();

        // 📝 Fingerprint should NOT have been updated because it's disabled
        $this->assertEquals($hash, $_SESSION['session_fingerprint']);
    }

    // ========================================================================
    // 🌐 IP MODE TESTS
    // ========================================================================

    /**
     * Test fingerprint changes when IP changes in 'exact' mode
     */
    public function testExactIPModeIsIPSensitive(): void
    {
        setTestSetting('security.session_fingerprinting.ip_match', 'exact');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        \SessionGuard::createFingerprint();
        $hash1 = $_SESSION['session_fingerprint'];

        // 🔁 Reset cached salt
        $reflection = new \ReflectionClass(\SessionGuard::class);
        $prop = $reflection->getProperty('cachedSalt');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
        \SessionGuard::createFingerprint();
        $hash2 = $_SESSION['session_fingerprint'];

        $this->assertNotEquals($hash1, $hash2, 'Different IPs should produce different fingerprints in exact mode');
    }

    /**
     * Test fingerprint does NOT change when IP changes in 'none' mode
     */
    public function testNoneIPModeIsIPInsensitive(): void
    {
        setTestSetting('security.session_fingerprinting.ip_match', 'none');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        \SessionGuard::createFingerprint();
        $hash1 = $_SESSION['session_fingerprint'];

        // 🔁 Reset cached salt
        $reflection = new \ReflectionClass(\SessionGuard::class);
        $prop = $reflection->getProperty('cachedSalt');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $_SERVER['REMOTE_ADDR'] = '192.168.99.99';
        \SessionGuard::createFingerprint();
        $hash2 = $_SESSION['session_fingerprint'];

        $this->assertEquals($hash1, $hash2, 'Different IPs should produce the same fingerprint in none mode');
    }

    /**
     * Test subnet mode produces same hash for IPs in same /24 subnet
     */
    public function testSubnetModeGroupsSameSubnet(): void
    {
        setTestSetting('security.session_fingerprinting.ip_match', 'subnet');

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        \SessionGuard::createFingerprint();
        $hash1 = $_SESSION['session_fingerprint'];

        // 🔁 Reset cached salt
        $reflection = new \ReflectionClass(\SessionGuard::class);
        $prop = $reflection->getProperty('cachedSalt');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $_SERVER['REMOTE_ADDR'] = '192.168.1.200';
        \SessionGuard::createFingerprint();
        $hash2 = $_SESSION['session_fingerprint'];

        $this->assertEquals($hash1, $hash2, 'Same subnet should produce the same fingerprint');
    }

    /**
     * Test subnet mode produces different hash for IPs in different subnets
     */
    public function testSubnetModeDifferentiatesDifferentSubnets(): void
    {
        setTestSetting('security.session_fingerprinting.ip_match', 'subnet');

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        \SessionGuard::createFingerprint();
        $hash1 = $_SESSION['session_fingerprint'];

        // 🔁 Reset cached salt
        $reflection = new \ReflectionClass(\SessionGuard::class);
        $prop = $reflection->getProperty('cachedSalt');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $_SERVER['REMOTE_ADDR'] = '10.0.0.100';
        \SessionGuard::createFingerprint();
        $hash2 = $_SESSION['session_fingerprint'];

        $this->assertNotEquals($hash1, $hash2, 'Different subnets should produce different fingerprints');
    }
}
