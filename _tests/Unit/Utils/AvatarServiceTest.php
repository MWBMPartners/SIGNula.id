<?php
/**
 * ============================================================================
 * 🧪 AvatarService Unit Tests
 * ============================================================================
 *
 * Tests for the AvatarService class covering:
 * - URL generation for all fallback services (Gravatar, Libravatar, UI Avatars,
 *   DiceBear, RoboHash)
 * - SVG initials avatar generation (initials extraction, deterministic colours)
 * - Upload validation logic
 * - Helper/utility methods (thumb sizes, supported services, upload enabled)
 *
 * These tests do NOT require a database connection — they only test the pure
 * functions (URL generators, initials generation, validation helpers).
 *
 * @package    SIGNula\Tests\Unit\Utils
 * @version    2.6.0-beta
 * @see        web/private_html/utils/AvatarService.php
 */

namespace SIGNula\Tests\Unit\Utils;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/utils/AvatarService.php');

/**
 * AvatarService Test Suite
 *
 * Unit tests for all AvatarService static methods that don't require
 * a database connection. Focuses on URL generation, initials avatars,
 * and configuration accessors.
 */
class AvatarServiceTest extends TestCase
{
    // ========================================================================
    // 🌐 GRAVATAR URL TESTS
    // ========================================================================

    /**
     * Test Gravatar URL generates correct MD5 hash
     *
     * Gravatar uses MD5 of lowercase trimmed email.
     * @see https://docs.gravatar.com/general/hash/
     */
    public function testGravatarUrlGeneratesCorrectMD5Hash(): void
    {
        $email = 'Test@Example.COM';
        $url = \AvatarService::getGravatarUrl($email, 96);

        // 🔐 MD5 of lowercase trimmed email
        $expectedHash = md5('test@example.com');

        $this->assertNotNull($url);
        $this->assertStringContainsString($expectedHash, $url);
        $this->assertStringStartsWith('https://www.gravatar.com/avatar/', $url);
    }

    /**
     * Test Gravatar URL includes correct size parameter
     */
    public function testGravatarUrlIncludesSize(): void
    {
        $url = \AvatarService::getGravatarUrl('user@test.com', 128);

        $this->assertNotNull($url);
        $this->assertStringContainsString('s=128', $url);
    }

    /**
     * Test Gravatar URL includes default style from settings
     */
    public function testGravatarUrlIncludesDefaultFromSettings(): void
    {
        setTestSetting('avatar.gravatar.default', 'identicon');
        $url = \AvatarService::getGravatarUrl('user@test.com', 96);

        $this->assertNotNull($url);
        $this->assertStringContainsString('d=identicon', $url);
    }

    /**
     * Test Gravatar URL uses 'mp' default when no setting configured
     */
    public function testGravatarUrlUsesDefaultMpStyle(): void
    {
        $url = \AvatarService::getGravatarUrl('user@test.com', 96);

        $this->assertNotNull($url);
        $this->assertStringContainsString('d=mp', $url);
    }

    /**
     * Test Gravatar URL includes general audiences rating
     */
    public function testGravatarUrlIncludesRating(): void
    {
        $url = \AvatarService::getGravatarUrl('user@test.com', 96);

        $this->assertNotNull($url);
        $this->assertStringContainsString('r=g', $url);
    }

    /**
     * Test Gravatar returns null for empty email
     */
    public function testGravatarReturnsNullForEmptyEmail(): void
    {
        $this->assertNull(\AvatarService::getGravatarUrl(''));
        $this->assertNull(\AvatarService::getGravatarUrl('   '));
    }

    /**
     * Test Gravatar with explicit default parameter
     */
    public function testGravatarWithExplicitDefault(): void
    {
        $url = \AvatarService::getGravatarUrl('user@test.com', 96, 'retro');

        $this->assertNotNull($url);
        $this->assertStringContainsString('d=retro', $url);
    }

    /**
     * Test Gravatar produces same URL for different email capitalisations
     */
    public function testGravatarNormalisesEmailCase(): void
    {
        $url1 = \AvatarService::getGravatarUrl('User@Test.COM', 96);
        $url2 = \AvatarService::getGravatarUrl('user@test.com', 96);

        $this->assertEquals($url1, $url2);
    }

    // ========================================================================
    // 🟢 LIBRAVATAR URL TESTS
    // ========================================================================

    /**
     * Test Libravatar URL generates correctly
     */
    public function testLibravatarUrlGeneratesCorrectly(): void
    {
        $email = 'user@example.com';
        $url = \AvatarService::getLibravatarUrl($email, 64);

        $expectedHash = md5($email);

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://seccdn.libravatar.org/avatar/', $url);
        $this->assertStringContainsString($expectedHash, $url);
        $this->assertStringContainsString('s=64', $url);
        $this->assertStringContainsString('d=mm', $url);
    }

    /**
     * Test Libravatar returns null for empty email
     */
    public function testLibravatarReturnsNullForEmptyEmail(): void
    {
        $this->assertNull(\AvatarService::getLibravatarUrl(''));
    }

    /**
     * Test Libravatar normalises email case
     */
    public function testLibravatarNormalisesEmailCase(): void
    {
        $url1 = \AvatarService::getLibravatarUrl('User@Test.COM', 96);
        $url2 = \AvatarService::getLibravatarUrl('user@test.com', 96);

        $this->assertEquals($url1, $url2);
    }

    // ========================================================================
    // 🔵 UI AVATARS URL TESTS
    // ========================================================================

    /**
     * Test UI Avatars URL generates correctly
     */
    public function testUIAvatarsUrlGeneratesCorrectly(): void
    {
        $url = \AvatarService::getUIAvatarsUrl('John Doe', 128);

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://ui-avatars.com/api/', $url);
        $this->assertStringContainsString('name=John+Doe', $url);
        $this->assertStringContainsString('size=128', $url);
        $this->assertStringContainsString('background=random', $url);
        $this->assertStringContainsString('color=fff', $url);
        $this->assertStringContainsString('bold=true', $url);
        $this->assertStringContainsString('format=svg', $url);
    }

    /**
     * Test UI Avatars returns null for empty name
     */
    public function testUIAvatarsReturnsNullForEmptyName(): void
    {
        $this->assertNull(\AvatarService::getUIAvatarsUrl(''));
        $this->assertNull(\AvatarService::getUIAvatarsUrl('   '));
    }

    /**
     * Test UI Avatars handles special characters in name
     */
    public function testUIAvatarsHandlesSpecialCharacters(): void
    {
        $url = \AvatarService::getUIAvatarsUrl('José María', 96);

        $this->assertNotNull($url);
        // 📝 Name should be URL-encoded
        $this->assertStringContainsString('name=', $url);
    }

    // ========================================================================
    // 🟡 DICEBEAR URL TESTS
    // ========================================================================

    /**
     * Test DiceBear URL generates correctly with default style
     */
    public function testDiceBearUrlGeneratesCorrectly(): void
    {
        $url = \AvatarService::getDiceBearUrl('user123', 96);

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://api.dicebear.com/7.x/', $url);
        $this->assertStringContainsString('initials/svg', $url);
        $this->assertStringContainsString('seed=user123', $url);
        $this->assertStringContainsString('size=96', $url);
    }

    /**
     * Test DiceBear URL with custom style
     */
    public function testDiceBearUrlWithCustomStyle(): void
    {
        $url = \AvatarService::getDiceBearUrl('user123', 128, 'avataaars');

        $this->assertNotNull($url);
        $this->assertStringContainsString('avataaars/svg', $url);
    }

    /**
     * Test DiceBear returns null for empty seed
     */
    public function testDiceBearReturnsNullForEmptySeed(): void
    {
        $this->assertNull(\AvatarService::getDiceBearUrl(''));
    }

    // ========================================================================
    // 🤖 ROBOHASH URL TESTS
    // ========================================================================

    /**
     * Test RoboHash URL generates correctly
     */
    public function testRoboHashUrlGeneratesCorrectly(): void
    {
        $url = \AvatarService::getRoboHashUrl('user123', 128);

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://robohash.org/', $url);
        $this->assertStringContainsString('user123', $url);
        $this->assertStringContainsString('size=128x128', $url);
    }

    /**
     * Test RoboHash returns null for empty seed
     */
    public function testRoboHashReturnsNullForEmptySeed(): void
    {
        $this->assertNull(\AvatarService::getRoboHashUrl(''));
    }

    /**
     * Test RoboHash URL-encodes special characters in seed
     */
    public function testRoboHashEncodesSpecialCharacters(): void
    {
        $url = \AvatarService::getRoboHashUrl('user with spaces', 96);

        $this->assertNotNull($url);
        $this->assertStringContainsString('robohash.org/', $url);
        // 📝 Space should be URL-encoded
        $this->assertStringNotContainsString(' ', $url);
    }

    // ========================================================================
    // 🔤 INITIALS AVATAR TESTS
    // ========================================================================

    /**
     * Test initials avatar generates valid SVG data URI
     */
    public function testInitialsAvatarGeneratesDataUri(): void
    {
        $result = \AvatarService::generateInitialsAvatar('John Doe', 96);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $result);
    }

    /**
     * Test initials avatar extracts correct initials from two-word name
     */
    public function testInitialsAvatarExtractsTwoLetterInitials(): void
    {
        $result = \AvatarService::generateInitialsAvatar('John Doe', 96);

        // 📝 Decode the SVG and check for initials
        $base64 = str_replace('data:image/svg+xml;base64,', '', $result);
        $svg = base64_decode($base64);

        $this->assertStringContainsString('JD', $svg);
    }

    /**
     * Test initials avatar extracts single initial for one-word name
     */
    public function testInitialsAvatarExtractsSingleInitial(): void
    {
        $result = \AvatarService::generateInitialsAvatar('Alice', 96);

        $base64 = str_replace('data:image/svg+xml;base64,', '', $result);
        $svg = base64_decode($base64);

        $this->assertStringContainsString('>A<', $svg);
    }

    /**
     * Test initials avatar uses fallback for empty name
     */
    public function testInitialsAvatarFallsBackForEmptyName(): void
    {
        $result = \AvatarService::generateInitialsAvatar('', 96);

        $base64 = str_replace('data:image/svg+xml;base64,', '', $result);
        $svg = base64_decode($base64);

        $this->assertStringContainsString('?', $svg);
    }

    /**
     * Test initials avatar generates correct SVG dimensions
     */
    public function testInitialsAvatarHasCorrectDimensions(): void
    {
        $result = \AvatarService::generateInitialsAvatar('John', 200);

        $base64 = str_replace('data:image/svg+xml;base64,', '', $result);
        $svg = base64_decode($base64);

        $this->assertStringContainsString('width="200"', $svg);
        $this->assertStringContainsString('height="200"', $svg);
    }

    /**
     * Test initials avatar uses deterministic background colour
     *
     * Same name should always produce the same colour.
     */
    public function testInitialsAvatarDeterministicColour(): void
    {
        $result1 = \AvatarService::generateInitialsAvatar('John Doe', 96);
        $result2 = \AvatarService::generateInitialsAvatar('John Doe', 96);

        // 📝 Same name, same size → identical output
        $this->assertEquals($result1, $result2);
    }

    /**
     * Test initials avatar produces different colours for different names
     */
    public function testInitialsAvatarDifferentColoursForDifferentNames(): void
    {
        $result1 = \AvatarService::generateInitialsAvatar('John Doe', 96);
        $result2 = \AvatarService::generateInitialsAvatar('Alice Smith', 96);

        // 📝 Different names should (very likely) produce different SVGs
        $this->assertNotEquals($result1, $result2);
    }

    /**
     * Test initials avatar handles multi-word names (takes first two initials)
     */
    public function testInitialsAvatarMultipleWords(): void
    {
        $result = \AvatarService::generateInitialsAvatar('John Robert Doe III', 96);

        $base64 = str_replace('data:image/svg+xml;base64,', '', $result);
        $svg = base64_decode($base64);

        // 📝 Should take only first two words' initials
        $this->assertStringContainsString('JR', $svg);
    }

    /**
     * Test initials avatar handles unicode characters
     */
    public function testInitialsAvatarHandlesUnicode(): void
    {
        $result = \AvatarService::generateInitialsAvatar('Ñoño García', 96);

        // Should produce a valid data URI without errors
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $result);

        $base64 = str_replace('data:image/svg+xml;base64,', '', $result);
        $svg = base64_decode($base64);

        // 📝 Should contain unicode initials
        $this->assertStringContainsString('Ñ', $svg);
    }

    /**
     * Test initials avatar SVG contains valid XML structure
     */
    public function testInitialsAvatarValidSvgStructure(): void
    {
        $result = \AvatarService::generateInitialsAvatar('Test User', 96);

        $base64 = str_replace('data:image/svg+xml;base64,', '', $result);
        $svg = base64_decode($base64);

        $this->assertStringContainsString('<svg xmlns=', $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertStringContainsString('<text', $svg);
    }

    // ========================================================================
    // ⚙️ CONFIGURATION & HELPER METHOD TESTS
    // ========================================================================

    /**
     * Test isUploadEnabled reads setting correctly
     */
    public function testIsUploadEnabledReadsSettingCorrectly(): void
    {
        setTestSetting('avatar.enable_upload', '1');
        $this->assertTrue(\AvatarService::isUploadEnabled());

        setTestSetting('avatar.enable_upload', '0');
        $this->assertFalse(\AvatarService::isUploadEnabled());
    }

    /**
     * Test isUploadEnabled defaults to true
     */
    public function testIsUploadEnabledDefaultsToTrue(): void
    {
        clearTestSettings();
        $this->assertTrue(\AvatarService::isUploadEnabled());
    }

    /**
     * Test getThumbSizes returns expected sizes array
     */
    public function testGetThumbSizesReturnsArray(): void
    {
        $sizes = \AvatarService::getThumbSizes();

        $this->assertIsArray($sizes);
        $this->assertNotEmpty($sizes);
        // 📐 Should contain standard sizes
        $this->assertContains(32, $sizes);
        $this->assertContains(96, $sizes);
        $this->assertContains(256, $sizes);
    }

    /**
     * Test getSupportedServices returns all service keys
     */
    public function testGetSupportedServicesReturnsAllServices(): void
    {
        $services = \AvatarService::getSupportedServices();

        $this->assertIsArray($services);
        $this->assertContains('gravatar', $services);
        $this->assertContains('libravatar', $services);
        $this->assertContains('ui_avatars', $services);
        $this->assertContains('dicebear', $services);
        $this->assertContains('robohash', $services);
        $this->assertCount(5, $services);
    }

    /**
     * Test getAvailableServices returns service info array
     */
    public function testGetAvailableServicesReturnsDetailedInfo(): void
    {
        $services = \AvatarService::getAvailableServices();

        $this->assertIsArray($services);
        $this->assertCount(5, $services);

        // 📝 Each service should have id, name, description, url, requires keys
        $serviceIds = array_column($services, 'id');
        $this->assertContains('gravatar', $serviceIds);
        $this->assertContains('libravatar', $serviceIds);
        $this->assertContains('ui_avatars', $serviceIds);
        $this->assertContains('dicebear', $serviceIds);
        $this->assertContains('robohash', $serviceIds);

        // Check structure of first service entry
        $this->assertArrayHasKey('id', $services[0]);
        $this->assertArrayHasKey('name', $services[0]);
        $this->assertArrayHasKey('description', $services[0]);
        $this->assertArrayHasKey('url', $services[0]);
        $this->assertArrayHasKey('requires', $services[0]);
    }

    // ========================================================================
    // 🧹 CACHE TESTS
    // ========================================================================

    /**
     * Test clearCache does not throw exceptions
     */
    public function testClearCacheDoesNotThrow(): void
    {
        // 📝 Should not throw even with a non-existent user
        \AvatarService::clearCache(999999);

        // 👍 If we got here, no exception was thrown
        $this->assertTrue(true);
    }

    // ========================================================================
    // 🔗 URL VALIDITY TESTS (Cross-service)
    // ========================================================================

    /**
     * Test all URL generators return HTTPS URLs
     */
    public function testAllUrlGeneratorsReturnHttps(): void
    {
        $email = 'test@example.com';
        $name = 'Test User';

        $urls = [
            'gravatar'   => \AvatarService::getGravatarUrl($email, 96),
            'libravatar' => \AvatarService::getLibravatarUrl($email, 96),
            'ui_avatars' => \AvatarService::getUIAvatarsUrl($name, 96),
            'dicebear'   => \AvatarService::getDiceBearUrl($name, 96),
            'robohash'   => \AvatarService::getRoboHashUrl($name, 96),
        ];

        foreach ($urls as $service => $url) {
            $this->assertNotNull($url, "{$service} should return a URL");
            $this->assertStringStartsWith('https://', $url, "{$service} should use HTTPS");
        }
    }

    /**
     * Test all URL generators accept and use size parameter
     */
    public function testAllUrlGeneratorsAcceptSizeParameter(): void
    {
        $email = 'test@example.com';
        $name = 'Test User';
        $size = 200;

        $urls = [
            'gravatar'   => \AvatarService::getGravatarUrl($email, $size),
            'libravatar' => \AvatarService::getLibravatarUrl($email, $size),
            'ui_avatars' => \AvatarService::getUIAvatarsUrl($name, $size),
            'dicebear'   => \AvatarService::getDiceBearUrl($name, $size),
            'robohash'   => \AvatarService::getRoboHashUrl($name, $size),
        ];

        foreach ($urls as $service => $url) {
            $this->assertNotNull($url);
            $this->assertStringContainsString('200', $url, "{$service} should include the size parameter");
        }
    }

    /**
     * Test all URL generators return null for empty input
     */
    public function testAllUrlGeneratorsReturnNullForEmptyInput(): void
    {
        $this->assertNull(\AvatarService::getGravatarUrl(''));
        $this->assertNull(\AvatarService::getLibravatarUrl(''));
        $this->assertNull(\AvatarService::getUIAvatarsUrl(''));
        $this->assertNull(\AvatarService::getDiceBearUrl(''));
        $this->assertNull(\AvatarService::getRoboHashUrl(''));
    }

    /**
     * Test Gravatar and Libravatar produce different URLs for same email
     * (they use the same hash but different domains)
     */
    public function testGravatarAndLibravatarDifferentDomains(): void
    {
        $email = 'user@example.com';
        $gravatar = \AvatarService::getGravatarUrl($email, 96);
        $libravatar = \AvatarService::getLibravatarUrl($email, 96);

        $this->assertNotEquals($gravatar, $libravatar);
        $this->assertStringContainsString('gravatar.com', $gravatar);
        $this->assertStringContainsString('libravatar.org', $libravatar);
    }
}
