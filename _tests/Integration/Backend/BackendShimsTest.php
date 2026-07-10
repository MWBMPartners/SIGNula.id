<?php
/**
 * ============================================================================
 * 🧪 _backend/*.php Bootstrap Shim Integration Tests (B-054 follow-up)
 * ============================================================================
 *
 * Live, DB-backed proof that the FOUR follow-up shims created alongside the
 * B-054 Database/getInstance fix actually make their canonical class usable
 * (not merely present) once loaded through the `_backend/<Class>.php` path
 * the dependent public_html pages require:
 *
 *   web/_backend/ActivityLogger.php    -> web/private_html/utils/ActivityLogger.php
 *   web/_backend/APIKeyManager.php     -> web/private_html/api/APIKeyManager.php
 *   web/_backend/RateLimiter.php       -> web/private_html/security/RateLimiter.php
 *   web/_backend/ServiceFeeManager.php -> web/private_html/payments/ServiceFeeManager.php
 *
 * The Unit companion (_tests/Unit/Backend/BackendShimsTest.php) pins, in an
 * isolated subprocess, that each shim makes its class available and declares
 * no duplicate class. THIS suite goes further against the real test database:
 *   - each shim, once required, exposes the expected class (no redeclare —
 *     the shim's class_exists() guard co-exists with the canonical file);
 *   - the two instance classes (APIKeyManager, RateLimiter) construct against
 *     a real mysqli connection exactly as their dependent pages do
 *     (`new APIKeyManager($db)` / `new RateLimiter($db)`);
 *   - the two static classes (ActivityLogger, ServiceFeeManager) expose their
 *     documented static entry points (ActivityLogger::log,
 *     ServiceFeeManager::calculateFee).
 *
 * Safe to load these classes in the shared Integration process: unlike the
 * Unit suite, no Integration test depends on any of them being ABSENT
 * (RateLimiterTest/OAuthClientTest/ActivityLoggerTest already load them),
 * and `class Database` is already present suite-wide.
 *
 * @package    SIGNula\Tests\Integration\Backend
 * @version    2.7.0-beta
 * @see        web/_backend/ActivityLogger.php
 * @see        web/_backend/APIKeyManager.php
 * @see        web/_backend/RateLimiter.php
 * @see        web/_backend/ServiceFeeManager.php
 * @see        _tests/Unit/Backend/BackendShimsTest.php (isolated shape pins)
 */

namespace SIGNula\Tests\Integration\Backend;

use SIGNula\Tests\DatabaseTestCase;

// 📦 Load each shim exactly as a public_html page does — through the
// _backend/<Class>.php path. Each shim then loads the canonical class from
// its true private_html/ location. requireSource() is require_once-backed,
// so this is idempotent with any other suite that already loaded them.
requireSource('_backend/ActivityLogger.php');
requireSource('_backend/APIKeyManager.php');
requireSource('_backend/RateLimiter.php');
requireSource('_backend/ServiceFeeManager.php');

/**
 * _backend/*.php Shim Integration Test Suite
 */
class BackendShimsTest extends DatabaseTestCase
{
    /**
     * All four shims, loaded via their _backend/ path, must expose their
     * canonical class with no redeclaration fatal (reaching any assertion in
     * this file already proves no fatal occurred at require time).
     */
    public function testAllFourShimsExposeTheirCanonicalClass(): void
    {
        $this->assertTrue(class_exists('ActivityLogger'), 'ActivityLogger must be loadable via web/_backend/ActivityLogger.php.');
        $this->assertTrue(class_exists('APIKeyManager'), 'APIKeyManager must be loadable via web/_backend/APIKeyManager.php.');
        $this->assertTrue(class_exists('RateLimiter'), 'RateLimiter must be loadable via web/_backend/RateLimiter.php.');
        $this->assertTrue(class_exists('ServiceFeeManager'), 'ServiceFeeManager must be loadable via web/_backend/ServiceFeeManager.php.');
    }

    /**
     * APIKeyManager must construct against a real mysqli connection — the
     * `new APIKeyManager($db)` shape web/public_html/partners/api-keys.php uses.
     */
    public function testAPIKeyManagerConstructsAgainstRealConnection(): void
    {
        $manager = new \APIKeyManager($this->db);

        $this->assertInstanceOf(\APIKeyManager::class, $manager);
    }

    /**
     * RateLimiter must construct against a real mysqli connection — the
     * `new RateLimiter($db)` shape web/public_html/admin/security/rate-limits.php
     * uses. Its constructor reads rate_limiting.* rows from tblSettings, so a
     * successful construction also proves the shimmed class can talk to the
     * live schema.
     */
    public function testRateLimiterConstructsAgainstRealConnection(): void
    {
        $limiter = new \RateLimiter($this->db);

        $this->assertInstanceOf(\RateLimiter::class, $limiter);
    }

    /**
     * ActivityLogger (static) must expose its documented log() entry point —
     * the ActivityLogger::log(...) call its 10 dependent pages make.
     */
    public function testActivityLoggerExposesStaticLogMethod(): void
    {
        $this->assertTrue(method_exists('ActivityLogger', 'log'));
    }

    /**
     * ServiceFeeManager (static) must expose its documented calculateFee()
     * entry point — the ServiceFeeManager::* calls
     * web/public_html/admin/api/service-fee-actions.php makes.
     */
    public function testServiceFeeManagerExposesStaticCalculateFeeMethod(): void
    {
        $this->assertTrue(method_exists('ServiceFeeManager', 'calculateFee'));
    }
}
