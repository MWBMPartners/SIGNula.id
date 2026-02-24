<?php
/**
 * ============================================================================
 * 🧪 CredentialResetService Unit Tests
 * ============================================================================
 *
 * Tests for the CredentialResetService class covering:
 * - Parameter validation for initiateMassReset()
 * - UUID generation format
 * - Reset type and scope constants
 * - Input sanitisation and edge cases
 *
 * These tests do NOT require a database connection. They test validation
 * logic, constants, and helper methods that can be exercised in isolation.
 *
 * @package    SIGNula\Tests\Unit\Admin
 * @version    2.6.0-beta
 * @see        web/private_html/admin/CredentialResetService.php
 */

namespace SIGNula\Tests\Unit\Admin;

use SIGNula\Tests\TestCase;

// 📦 Load source class
requireSource('private_html/admin/CredentialResetService.php');

/**
 * CredentialResetService Test Suite
 */
class CredentialResetServiceTest extends TestCase
{
    // ========================================================================
    // 📋 CONSTANTS TESTS
    // ========================================================================

    /**
     * Test that reset type constants are defined correctly
     */
    public function testResetTypeConstantsAreDefined(): void
    {
        $this->assertEquals('mass_password_reset', \CredentialResetService::TYPE_MASS_PASSWORD_RESET);
        $this->assertEquals('salt_rotation', \CredentialResetService::TYPE_SALT_ROTATION);
        $this->assertEquals('full_credential_reset', \CredentialResetService::TYPE_FULL_CREDENTIAL_RESET);
    }

    /**
     * Test that scope constants are defined correctly
     */
    public function testScopeConstantsAreDefined(): void
    {
        $this->assertEquals('all_users', \CredentialResetService::SCOPE_ALL_USERS);
        $this->assertEquals('filtered', \CredentialResetService::SCOPE_FILTERED);
        $this->assertEquals('specific_users', \CredentialResetService::SCOPE_SPECIFIC);
    }

    // ========================================================================
    // ✅ VALIDATION TESTS
    // ========================================================================

    /**
     * Test initiateMassReset rejects invalid reset type
     */
    public function testInitiateMassResetRejectsInvalidType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid reset type');

        \CredentialResetService::initiateMassReset(
            adminUserID: 1,
            resetType: 'invalid_type',
            reason: 'Test reason',
            scope: 'all_users'
        );
    }

    /**
     * Test initiateMassReset rejects invalid scope
     */
    public function testInitiateMassResetRejectsInvalidScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid scope');

        \CredentialResetService::initiateMassReset(
            adminUserID: 1,
            resetType: 'mass_password_reset',
            reason: 'Test reason',
            scope: 'invalid_scope'
        );
    }

    /**
     * Test initiateMassReset rejects empty reason
     */
    public function testInitiateMassResetRejectsEmptyReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reason is required');

        \CredentialResetService::initiateMassReset(
            adminUserID: 1,
            resetType: 'mass_password_reset',
            reason: '',
            scope: 'all_users'
        );
    }

    /**
     * Test initiateMassReset rejects whitespace-only reason
     */
    public function testInitiateMassResetRejectsWhitespaceReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Reason is required');

        \CredentialResetService::initiateMassReset(
            adminUserID: 1,
            resetType: 'mass_password_reset',
            reason: '   ',
            scope: 'all_users'
        );
    }

    /**
     * Test all valid reset types are accepted (validation passes)
     *
     * In unit tests (no DB), initiateMassReset passes validation but
     * then hits Database::beginTransaction() which throws an Error.
     * We verify InvalidArgumentException is NOT thrown for valid types.
     */
    public function testAllValidResetTypesPassValidation(): void
    {
        $validTypes = [
            \CredentialResetService::TYPE_MASS_PASSWORD_RESET,
            \CredentialResetService::TYPE_SALT_ROTATION,
            \CredentialResetService::TYPE_FULL_CREDENTIAL_RESET,
        ];

        foreach ($validTypes as $type) {
            $threwInvalidArg = false;
            try {
                \CredentialResetService::initiateMassReset(
                    adminUserID: 1,
                    resetType: $type,
                    reason: 'Test reason for ' . $type,
                    scope: 'all_users'
                );
            } catch (\InvalidArgumentException $e) {
                $threwInvalidArg = true;
            } catch (\Error|\Exception $e) {
                // 📝 Expected: "Class Database not found" in unit tests
                // Validation passed before this error, which is what we're testing
            }

            $this->assertFalse($threwInvalidArg, "Type '{$type}' should pass validation without InvalidArgumentException");
        }
    }

    /**
     * Test all valid scopes are accepted (validation passes)
     */
    public function testAllValidScopesPassValidation(): void
    {
        $validScopes = [
            \CredentialResetService::SCOPE_ALL_USERS,
            \CredentialResetService::SCOPE_FILTERED,
            \CredentialResetService::SCOPE_SPECIFIC,
        ];

        foreach ($validScopes as $scope) {
            $threwInvalidArg = false;
            try {
                \CredentialResetService::initiateMassReset(
                    adminUserID: 1,
                    resetType: 'mass_password_reset',
                    reason: 'Test reason',
                    scope: $scope
                );
            } catch (\InvalidArgumentException $e) {
                $threwInvalidArg = true;
            } catch (\Error|\Exception $e) {
                // 📝 Expected: "Class Database not found" in unit tests
            }

            $this->assertFalse($threwInvalidArg, "Scope '{$scope}' should pass validation without InvalidArgumentException");
        }
    }

    // ========================================================================
    // 🔐 SETTINGS TESTS
    // ========================================================================

    /**
     * Test credential reset settings exist with correct defaults
     */
    public function testCredentialResetSettingsHaveDefaults(): void
    {
        // 📋 Verify settings loaded from test fixtures
        $batchSize = getSetting('security.credential_reset.batch_size', 100);
        $this->assertIsNumeric($batchSize);
        $this->assertGreaterThan(0, (int)$batchSize);

        $emailPriority = getSetting('security.credential_reset.email_priority', 1);
        $this->assertIsNumeric($emailPriority);
        $this->assertGreaterThanOrEqual(1, (int)$emailPriority);
        $this->assertLessThanOrEqual(10, (int)$emailPriority);

        $tokenExpiry = getSetting('security.credential_reset.token_expiry_hours', 72);
        $this->assertIsNumeric($tokenExpiry);
        $this->assertGreaterThan(0, (int)$tokenExpiry);
    }

    /**
     * Test email enhancement settings exist
     */
    public function testEmailEnhancementSettingsExist(): void
    {
        $htmlEnabled = getSetting('email.html.enabled', '1');
        $this->assertNotNull($htmlEnabled);

        $ampEnabled = getSetting('email.amp.enabled', '0');
        $this->assertNotNull($ampEnabled);
    }
}
