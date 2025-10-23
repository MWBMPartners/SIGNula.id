<?php
/**
 * ============================================================================
 * 🏢 SIGNula - Organization Management
 * ============================================================================
 *
 * Purpose: Organization/company account management
 * PHP Version: 8.3+
 *
 * Features:
 * - Organization creation and management
 * - Domain verification
 * - Member management
 * - OAuth provider policies
 * - Organization-level settings
 *
 * @package    SIGNula
 * @subpackage Authentication
 * @version    1.1.0
 * @link       https://signulo.id
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🏢 Organization Class
 *
 * Handles organization account operations, member management,
 * and organizational policies.
 */
class Organization
{
    // ========================================================================
    // 🏢 ORGANIZATION MANAGEMENT
    // ========================================================================

    /**
     * 📝 Create New Organization
     *
     * @param string $name Organization name
     * @param string $type Organization type
     * @param int $createdBy User ID of creator
     * @param array $additionalData Additional organization data
     * @return array Result ['success' => bool, 'organizationID' => int|null, 'message' => string]
     */
    public static function create(
        string $name,
        string $type,
        int $createdBy,
        array $additionalData = []
    ): array {
        try {
            // 🧹 Sanitize and validate
            $name = SecurityUtils::sanitizeString($name);
            $slug = self::generateSlug($name);

            // Check if slug already exists
            $existing = Database::fetchOne(
                "SELECT organizationID FROM tblOrganizations WHERE organizationSlug = ?",
                [$slug],
                's'
            );

            if ($existing) {
                $slug = $slug . '-' . substr(md5(uniqid()), 0, 6);
            }

            // Generate UUID
            $uuid = self::generateUUID();

            // Begin transaction
            Database::beginTransaction();

            // Create organization
            $query = "
                INSERT INTO tblOrganizations (
                    organizationUUID, organizationName, organizationSlug,
                    organizationType, website, contactEmail, createdBy,
                    accountStatus, subscriptionTier
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_verification', 'free')
            ";

            Database::query($query, [
                $uuid,
                $name,
                $slug,
                $type,
                $additionalData['website'] ?? null,
                $additionalData['contactEmail'] ?? null,
                $createdBy
            ], 'ssssssi');

            $organizationID = Database::getLastInsertId();

            // Add creator as owner
            Database::query(
                "INSERT INTO tblOrganizationMembers (organizationID, userID, role, status)
                 VALUES (?, ?, 'owner', 'active')",
                [$organizationID, $createdBy],
                'ii'
            );

            // Update user's organization
            Database::query(
                "UPDATE tblUsers SET organizationID = ? WHERE userID = ?",
                [$organizationID, $createdBy],
                'ii'
            );

            // Log activity
            ActivityLogger::log($createdBy, 'organization_created', 'account', 'info',
                'Organization created', ['organization_id' => $organizationID, 'name' => $name]);

            Database::commit();

            return [
                'success' => true,
                'organizationID' => $organizationID,
                'message' => 'Organization created successfully!'
            ];

        } catch (Exception $e) {
            if (Database::isConnected()) {
                Database::rollback();
            }

            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());

            return [
                'success' => false,
                'organizationID' => null,
                'message' => 'Failed to create organization. Please try again.'
            ];
        }
    }

    /**
     * 🌐 Find Organization by Email Domain
     *
     * @param string $email Email address
     * @return array|null Organization data or null
     */
    public static function findByEmailDomain(string $email): ?array
    {
        try {
            // Extract domain from email
            $domain = substr(strrchr($email, '@'), 1);

            if (!$domain) {
                return null;
            }

            $query = "
                SELECT o.*
                FROM tblOrganizations o
                JOIN tblOrganizationDomains od ON o.organizationID = od.organizationID
                WHERE od.domain = ?
                AND od.isVerified = TRUE
                AND o.accountStatus = 'active'
                AND o.deletedAt IS NULL
                LIMIT 1
            ";

            return Database::fetchOne($query, [$domain], 's');

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return null;
        }
    }

    /**
     * ✅ Verify Domain for Organization
     *
     * @param int $organizationID Organization ID
     * @param string $domain Domain to verify
     * @param string $method Verification method
     * @param int $verifiedBy User ID who verified
     * @return bool Success status
     */
    public static function verifyDomain(
        int $organizationID,
        string $domain,
        string $method,
        int $verifiedBy
    ): bool {
        try {
            Database::beginTransaction();

            // Update domain as verified
            Database::query(
                "UPDATE tblOrganizationDomains
                 SET isVerified = TRUE, verifiedAt = NOW(), verificationMethod = ?, verifiedBy = ?
                 WHERE organizationID = ? AND domain = ?",
                [$method, $verifiedBy, $organizationID, $domain],
                'siis'
            );

            // Mark organization as verified if this is first verified domain
            $verifiedDomains = Database::fetchOne(
                "SELECT COUNT(*) as count FROM tblOrganizationDomains
                 WHERE organizationID = ? AND isVerified = TRUE",
                [$organizationID],
                'i'
            );

            if ($verifiedDomains && $verifiedDomains['count'] > 0) {
                Database::query(
                    "UPDATE tblOrganizations
                     SET isVerified = TRUE, verifiedAt = NOW(), accountStatus = 'active'
                     WHERE organizationID = ?",
                    [$organizationID],
                    'i'
                );
            }

            ActivityLogger::log($verifiedBy, 'domain_verified', 'account', 'info',
                'Organization domain verified', [
                    'organization_id' => $organizationID,
                    'domain' => $domain,
                    'method' => $method
                ]);

            Database::commit();
            return true;

        } catch (Exception $e) {
            if (Database::isConnected()) {
                Database::rollback();
            }

            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    // ========================================================================
    // 👥 MEMBER MANAGEMENT
    // ========================================================================

    /**
     * 🎫 Invite User to Organization
     *
     * @param int $organizationID Organization ID
     * @param string $email Invitee email
     * @param string $role Role to assign
     * @param int $invitedBy User ID of inviter
     * @param string|null $message Personal message
     * @return array Result ['success' => bool, 'message' => string]
     */
    public static function inviteMember(
        int $organizationID,
        string $email,
        string $role,
        int $invitedBy,
        ?string $message = null
    ): array {
        try {
            // Check if user already exists
            $existingUser = Database::fetchOne(
                "SELECT userID FROM tblUsers WHERE email = ?",
                [$email],
                's'
            );

            // Check if already a member
            if ($existingUser) {
                $isMember = Database::fetchOne(
                    "SELECT memberID FROM tblOrganizationMembers
                     WHERE organizationID = ? AND userID = ?",
                    [$organizationID, $existingUser['userID']],
                    'ii'
                );

                if ($isMember) {
                    return [
                        'success' => false,
                        'message' => 'User is already a member of this organization.'
                    ];
                }
            }

            // Generate invitation token
            $token = SecurityUtils::generateToken();
            $expiryDays = getSetting('organizations.invitation_expiry_days', 7);

            Database::query(
                "INSERT INTO tblOrganizationInvitations
                 (organizationID, email, role, invitationToken, invitedBy, message, expiresAt)
                 VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))",
                [$organizationID, $email, $role, $token, $invitedBy, $message, $expiryDays],
                'isssiis'
            );

            // TODO: Send invitation email

            ActivityLogger::log($invitedBy, 'org_invitation_sent', 'account', 'info',
                'Organization invitation sent', [
                    'organization_id' => $organizationID,
                    'email' => $email,
                    'role' => $role
                ]);

            return [
                'success' => true,
                'message' => 'Invitation sent successfully!'
            ];

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());

            return [
                'success' => false,
                'message' => 'Failed to send invitation. Please try again.'
            ];
        }
    }

    /**
     * 👤 Check if User has Organization Role
     *
     * @param int $userID User ID
     * @param int $organizationID Organization ID
     * @param string|array $requiredRole Required role(s)
     * @return bool Has role status
     */
    public static function userHasRole(int $userID, int $organizationID, string|array $requiredRole): bool
    {
        try {
            $member = Database::fetchOne(
                "SELECT role FROM tblOrganizationMembers
                 WHERE userID = ? AND organizationID = ? AND status = 'active'",
                [$userID, $organizationID],
                'ii'
            );

            if (!$member) {
                return false;
            }

            $roles = is_array($requiredRole) ? $requiredRole : [$requiredRole];

            return in_array($member['role'], $roles, true);

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    /**
     * 📊 Get Organization Members
     *
     * @param int $organizationID Organization ID
     * @param string|null $status Filter by status
     * @return array Members array
     */
    public static function getMembers(int $organizationID, ?string $status = 'active'): array
    {
        try {
            $query = "
                SELECT om.*, u.username, u.email, u.displayName, u.firstName, u.lastName,
                       u.lastLoginAt
                FROM tblOrganizationMembers om
                JOIN tblUsers u ON om.userID = u.userID
                WHERE om.organizationID = ?
            ";

            $params = [$organizationID];
            $types = 'i';

            if ($status) {
                $query .= " AND om.status = ?";
                $params[] = $status;
                $types .= 's';
            }

            $query .= " ORDER BY om.role ASC, om.joinedAt DESC";

            return Database::fetchAll($query, $params, $types);

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return [];
        }
    }

    // ========================================================================
    // 🔐 OAUTH PROVIDER POLICIES
    // ========================================================================

    /**
     * ✅ Check if OAuth Provider is Allowed
     *
     * @param int $organizationID Organization ID
     * @param string $provider OAuth provider
     * @param string|null $tenantID Tenant ID (optional)
     * @return bool Allowed status
     */
    public static function isOAuthProviderAllowed(
        int $organizationID,
        string $provider,
        ?string $tenantID = null
    ): bool {
        try {
            $query = "
                SELECT COUNT(*) as count
                FROM tblOrganizationOAuthProviders
                WHERE organizationID = ?
                AND provider = ?
                AND isEnabled = TRUE
            ";

            $params = [$organizationID, $provider];
            $types = 'is';

            // If tenant check is required
            if ($tenantID) {
                $query .= " AND (tenantID IS NULL OR tenantID = ?)";
                $params[] = $tenantID;
                $types .= 's';
            }

            $result = Database::fetchOne($query, $params, $types);

            return $result && $result['count'] > 0;

        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    // ========================================================================
    // 🔧 UTILITY FUNCTIONS
    // ========================================================================

    /**
     * 🔗 Generate URL-friendly Slug
     *
     * @param string $text Text to slugify
     * @return string Slug
     */
    private static function generateSlug(string $text): string
    {
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return substr($text, 0, 100);
    }

    /**
     * 🆔 Generate UUID v4
     *
     * @return string UUID
     */
    private static function generateUUID(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

// ✅ Organization class loaded successfully
