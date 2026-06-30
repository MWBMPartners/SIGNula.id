<?php
/**
 * ============================================================================
 * 🧪 WebAuthn Challenge Characterization Tests (STABILIZE-2 safety net)
 * ============================================================================
 *
 * Characterizes the PURE parts of WebAuthnHandler — challenge generation and
 * relying-party configuration — that the PassKey/biometric build (FG-013) will
 * modify. The accept/reject decision path (verifyAuthentication /
 * verifyRegistration) is database-coupled and is pinned in the companion
 * Integration test (Integration/Auth/WebAuthnVerifyTest.php), where the
 * SIGNIFICANT signature-verification GAP is documented as NEEDS-LEAD-REVIEW.
 *
 * Coverage here:
 * - 🎲 generateChallenge() randomness/format/length (32 bytes, base64) via reflection
 * - 🏗️ Constructor reads RP config from settings (rp_name, rp_id, origin, validity)
 *
 * The challenge is the cryptographic anti-replay nonce; pinning its size and
 * encoding protects against a regression that would weaken it.
 *
 * @package    SIGNula\Tests\Unit\Auth
 * @version    2.7.0-beta
 * @see        web/private_html/auth/WebAuthnHandler.php
 * @see        https://www.w3.org/TR/webauthn-2/#sctn-cryptographic-challenges
 */

namespace SIGNula\Tests\Unit\Auth;

use SIGNula\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;

// 📦 Load source class (SIGNULA_INIT defined by bootstrap.php)
requireSource('private_html/auth/WebAuthnHandler.php');

/**
 * WebAuthn Challenge Characterization Test Suite
 */
class WebAuthnChallengeTest extends TestCase
{
    /**
     * Invoke the private generateChallenge() on a handler instance.
     */
    private function generateChallenge(\WebAuthnHandler $handler): string
    {
        $ref = new ReflectionMethod(\WebAuthnHandler::class, 'generateChallenge');
        $ref->setAccessible(true);
        return $ref->invoke($handler);
    }

    /**
     * Read a private property from a handler instance.
     */
    private function readProperty(\WebAuthnHandler $handler, string $name): mixed
    {
        $ref = new ReflectionProperty(\WebAuthnHandler::class, $name);
        $ref->setAccessible(true);
        return $ref->getValue($handler);
    }

    // ========================================================================
    // 🎲 CHALLENGE GENERATION
    // ========================================================================

    /**
     * generateChallenge() returns a base64 string decoding to exactly 32 bytes.
     */
    public function testGenerateChallengeFormatAndLength(): void
    {
        $handler = new \WebAuthnHandler();
        $challenge = $this->generateChallenge($handler);

        // base64_encode(random_bytes(32)) => 44 chars (32 bytes, no padding loss).
        $this->assertSame(44, strlen($challenge), 'Encoded challenge should be 44 base64 chars');

        $raw = base64_decode($challenge, true);
        $this->assertNotFalse($raw, 'Challenge must be valid (strict) base64');
        $this->assertSame(32, strlen($raw), 'Challenge must decode to 32 random bytes');
    }

    /**
     * generateChallenge() is non-deterministic across calls.
     */
    public function testGenerateChallengeUniqueness(): void
    {
        $handler = new \WebAuthnHandler();

        $challenges = [];
        for ($i = 0; $i < 100; $i++) {
            $challenges[] = $this->generateChallenge($handler);
        }

        $this->assertCount(100, array_unique($challenges), 'Challenges must be unique per call');
    }

    // ========================================================================
    // 🏗️ RELYING-PARTY CONFIGURATION
    // ========================================================================

    /**
     * Constructor reads RP configuration from settings.json fixture defaults.
     *
     * Fixture provides: rp_name="SIGNula Test", rp_id="localhost",
     * site.url="http://localhost". challenge_validity is not set -> default 5.
     */
    public function testConstructorReadsRpConfigFromSettings(): void
    {
        $handler = new \WebAuthnHandler();

        $this->assertSame('SIGNula Test', $this->readProperty($handler, 'rpName'));
        $this->assertSame('localhost', $this->readProperty($handler, 'rpId'));
        $this->assertSame('http://localhost', $this->readProperty($handler, 'rpOrigin'));
        // 📝 challenge_validity absent from fixture -> coerced default of 5 minutes.
        $this->assertSame(5, $this->readProperty($handler, 'challengeValidity'));
    }

    /**
     * Constructor honours explicit overrides for every RP setting.
     */
    public function testConstructorHonoursOverriddenSettings(): void
    {
        setTestSetting('auth.webauthn.rp_name', 'CustomRP');
        setTestSetting('auth.webauthn.rp_id', 'custom.example');
        setTestSetting('site.url', 'https://custom.example/');
        setTestSetting('auth.webauthn.challenge_validity', '12'); // string -> int cast

        $handler = new \WebAuthnHandler();

        $this->assertSame('CustomRP', $this->readProperty($handler, 'rpName'));
        $this->assertSame('custom.example', $this->readProperty($handler, 'rpId'));
        // 📝 rpOrigin is stored verbatim (trailing slash kept; trimmed only at verify time).
        $this->assertSame('https://custom.example/', $this->readProperty($handler, 'rpOrigin'));
        $this->assertSame(12, $this->readProperty($handler, 'challengeValidity'));
    }
}
