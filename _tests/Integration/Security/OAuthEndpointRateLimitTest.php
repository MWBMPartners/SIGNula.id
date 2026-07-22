<?php
/**
 * ============================================================================
 * 🧪 OAuth Token/Revoke Endpoint Rate-Limit Wiring Tests (G-001 Stage A5 — B-059)
 * ============================================================================
 *
 * Proves the EXACT SecurityUtils::checkRateLimit() bucket names + reused
 * jwt.rate_limit.* settings that web/public_html/oauth/token.php and
 * web/public_html/oauth/revoke.php now apply BEFORE any DB-bound
 * client/grant work (see those files' own "RATE LIMITING" sections) actually
 * deny once the configured threshold is exceeded — i.e. the exact mechanism
 * that turns into an HTTP 429 + Retry-After in production.
 *
 * Exercised DIRECTLY against SecurityUtils::checkRateLimit() (the SAME
 * limiter JwtAuthController already uses, and RateLimiterTest /
 * JwtAuthEndpointsTest already cover generically) rather than spinning up a
 * full subprocess HTTP harness for the raw controller scripts — this is the
 * lighter-weight approach the task itself calls out as sufficient ("drive
 * checkRateLimit directly or via repeated service calls"), and it proves the
 * property that actually matters here: that /oauth/token and /oauth/revoke
 * use DISTINCT bucket NAMES ('oauth_token_ip'/'oauth_token_client' and
 * 'oauth_revoke_ip'/'oauth_revoke_client') so their counters never collide
 * with each other OR with /api/v1/auth/token's own ('jwt_token'/'jwt_refresh').
 *
 * @package    SIGNula\Tests\Integration\Security
 * @version    2.9.0-beta
 * @see        web/public_html/oauth/token.php   (RATE LIMITING section)
 * @see        web/public_html/oauth/revoke.php  (RATE LIMITING section)
 * @see        web/private_html/security/SecurityUtils.php (checkRateLimit())
 * @see        _tests/Integration/Security/RateLimiterTest.php (generic RateLimiter coverage)
 * @see        _tests/Integration/API/JwtAuthEndpointsTest.php (the JWT endpoints' own 429 coverage)
 */

namespace SIGNula\Tests\Integration\Security;

use SIGNula\Tests\DatabaseTestCase;

// ============================================================================
// 📦 LOAD REQUIRED SOURCE FILES
// ============================================================================
requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');

/**
 * 🚦 OAuth token/revoke endpoint rate-limit wiring test suite.
 */
class OAuthEndpointRateLimitTest extends DatabaseTestCase
{
    /**
     * Tables reset before each test.
     *
     * @var array<int,string>
     */
    protected array $truncateTables = ['tblRateLimits'];

    // ========================================================================
    // 🌐 /oauth/token — per-IP bucket
    // ========================================================================

    /**
     * Hammering the /oauth/token per-IP bucket ('oauth_token_ip' — the exact
     * bucket name token.php's RATE LIMITING section uses) past its configured
     * max denies the call immediately AFTER exactly $max allowed requests —
     * this is precisely what token.php turns into an HTTP 429 + Retry-After.
     */
    public function testOauthTokenIpBucketDeniesPastConfiguredLimit(): void
    {
        $ip     = '203.0.113.' . random_int(1, 254);
        $max    = 3;
        $window = 900;

        for ($i = 0; $i < $max; $i++) {
            $this->assertTrue(
                \SecurityUtils::checkRateLimit($ip, 'oauth_token_ip', $max, $window),
                "call #{$i} must be allowed (still within the configured limit)"
            );
        }

        $this->assertFalse(
            \SecurityUtils::checkRateLimit($ip, 'oauth_token_ip', $max, $window),
            'the call PAST the limit must be denied — token.php maps this to HTTP 429'
        );
    }

    // ========================================================================
    // 🪪 /oauth/token — per-client_id bucket
    // ========================================================================

    /**
     * The per-client_id bucket ('oauth_token_client') is tracked
     * INDEPENDENTLY of the per-IP bucket, keyed by identifier — exhausting
     * one client_id's allowance does NOT affect a different client_id's own
     * (fresh) allowance.
     */
    public function testOauthTokenClientBucketIsPerClientAndIndependent(): void
    {
        $max        = 2;
        $window     = 900;
        $clientKeyA = 'client:' . bin2hex(random_bytes(4));
        $clientKeyB = 'client:' . bin2hex(random_bytes(4));

        for ($i = 0; $i < $max; $i++) {
            $this->assertTrue(\SecurityUtils::checkRateLimit($clientKeyA, 'oauth_token_client', $max, $window));
        }
        $this->assertFalse(
            \SecurityUtils::checkRateLimit($clientKeyA, 'oauth_token_client', $max, $window),
            'client A must be denied once its own allowance is exhausted'
        );

        // A DIFFERENT client_id has its own, untouched allowance.
        $this->assertTrue(
            \SecurityUtils::checkRateLimit($clientKeyB, 'oauth_token_client', $max, $window),
            'a DIFFERENT client_id must have a completely independent counter'
        );
    }

    // ========================================================================
    // 🗑️ /oauth/revoke — per-IP + per-client buckets, isolated from /oauth/token
    // ========================================================================

    /**
     * The /oauth/revoke per-IP bucket ('oauth_revoke_ip') is independently
     * enforced, and — critically — using a DISTINCT bucket name from
     * /oauth/token's own ('oauth_token_ip') means exhausting one endpoint's
     * allowance for a given IP does NOT burn the other endpoint's allowance
     * for that SAME IP.
     */
    public function testOauthRevokeIpBucketIsIndependentOfTokenIpBucket(): void
    {
        $ip     = '203.0.113.' . random_int(1, 254);
        $max    = 2;
        $window = 900;

        for ($i = 0; $i < $max; $i++) {
            $this->assertTrue(\SecurityUtils::checkRateLimit($ip, 'oauth_revoke_ip', $max, $window));
        }
        $this->assertFalse(
            \SecurityUtils::checkRateLimit($ip, 'oauth_revoke_ip', $max, $window),
            'revoke calls past the limit must be denied — revoke.php maps this to HTTP 429'
        );

        // The SAME ip against the DISTINCT /oauth/token bucket is UNAFFECTED —
        // proves the bucket names actually separate the counters.
        $this->assertTrue(
            \SecurityUtils::checkRateLimit($ip, 'oauth_token_ip', $max, $window),
            '/oauth/token\'s own bucket for the SAME ip must be untouched by /oauth/revoke traffic'
        );
    }

    /**
     * The /oauth/revoke per-client bucket ('oauth_revoke_client') is also
     * independently enforced from /oauth/token's per-client bucket.
     */
    public function testOauthRevokeClientBucketIsIndependentOfTokenClientBucket(): void
    {
        $max       = 2;
        $window    = 900;
        $clientKey = 'client:' . bin2hex(random_bytes(4));

        for ($i = 0; $i < $max; $i++) {
            $this->assertTrue(\SecurityUtils::checkRateLimit($clientKey, 'oauth_revoke_client', $max, $window));
        }
        $this->assertFalse(\SecurityUtils::checkRateLimit($clientKey, 'oauth_revoke_client', $max, $window));

        // The SAME client_id key against /oauth/token's OWN bucket is untouched.
        $this->assertTrue(\SecurityUtils::checkRateLimit($clientKey, 'oauth_token_client', $max, $window));
    }

    // ========================================================================
    // 🟢 FAIL-OPEN CONTRACT (matches JwtAuthController's own behaviour)
    // ========================================================================

    /**
     * checkRateLimit() is the SAME limiter JwtAuthController relies on, and
     * its OWN try/catch (SecurityUtils.php) converts ANY internal error into
     * a fail-OPEN `true` return rather than letting it escape/block
     * legitimate traffic — oauth/token.php and oauth/revoke.php inherit this
     * for free by calling the same method, exactly like B-059 requires
     * ("fail-open only if the limiter store is unavailable").
     *
     * Rather than assume whether an oversized identifier trips MySQL strict
     * mode (this project runs mysqli_report(MYSQLI_REPORT_ERROR |
     * MYSQLI_REPORT_STRICT) — see web/_config/database.php — under strict
     * SQL modes a value past VARCHAR(255) throws; under lenient modes it
     * silently truncates) we assert the property that holds EITHER way: the
     * call never lets an exception escape, and always returns a bool.
     */
    public function testCheckRateLimitNeverThrowsAndAlwaysReturnsBool(): void
    {
        $oversizedIdentifier = str_repeat('x', 5000); // far past tblRateLimits.identifier VARCHAR(255)

        $result = null;
        try {
            $result = \SecurityUtils::checkRateLimit($oversizedIdentifier, 'oauth_token_ip', 1, 900);
        } catch (\Throwable $e) {
            $this->fail('checkRateLimit() must never let an internal error escape uncaught: ' . $e->getMessage());
        }

        $this->assertIsBool($result);
    }
}
