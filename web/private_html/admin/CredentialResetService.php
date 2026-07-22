<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Credential Reset Service
 * ============================================================================
 *
 * Purpose: Manage mass credential reset operations for Global Admins.
 * PHP Version: 8.3+
 *
 * Features:
 * - Mass password invalidation across all or filtered users
 * - Global salt rotation with history tracking
 * - Individual password reset token generation and email dispatch
 * - Batch processing to handle large user bases without timeouts
 * - Comprehensive audit trail via tblCredentialResets + tblCredentialResetUsers
 * - Session invalidation for affected users
 * - Progress tracking for long-running operations
 *
 * Security Considerations:
 * - Only callable by Super Admin (enforced at controller/API level)
 * - All operations logged to tblCredentialResets and tblAdminAuditLog
 * - Salt values encrypted before storage using SecurityUtils::encrypt()
 * - Reset tokens generated via SecurityUtils::generateToken()
 * - Emails queued via EmailService (not sent inline) to prevent timeouts
 *
 * @package    SIGNula
 * @subpackage Admin
 * @version    1.0.0
 * @see        https://cheatsheetseries.owasp.org/cheatsheets/Credential_Stuffing_Prevention_Cheat_Sheet.html
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🔐 Credential Reset Service
 *
 * Handles mass credential reset operations including:
 * - Password invalidation for all/filtered users
 * - Global salt rotation
 * - Reset token generation and email notification
 * - Batch processing with progress tracking
 *
 * Usage:
 * ```php
 * // 🚨 Trigger mass password reset (security breach)
 * $result = CredentialResetService::initiateMassReset(
 *     adminUserID: 1,
 *     resetType: 'mass_password_reset',
 *     reason: 'Security breach detected - all credentials must be rotated',
 *     scope: 'all_users'
 * );
 *
 * // 🔄 Process next batch
 * $progress = CredentialResetService::processNextBatch($result['resetID']);
 *
 * // 📊 Check progress
 * $status = CredentialResetService::getResetStatus($result['resetID']);
 * ```
 */
class CredentialResetService
{
    // ========================================================================
    // 📋 CONSTANTS
    // ========================================================================

    /**
     * @var string Reset type: Mass password reset (passwords invalidated, tokens sent)
     */
    public const TYPE_MASS_PASSWORD_RESET = 'mass_password_reset';

    /**
     * @var string Reset type: Salt rotation (global salt changed, passwords re-hashed)
     */
    public const TYPE_SALT_ROTATION = 'salt_rotation';

    /**
     * @var string Reset type: Full credential reset (salt + password invalidation)
     */
    public const TYPE_FULL_CREDENTIAL_RESET = 'full_credential_reset';

    /**
     * @var string Scope: All active users
     */
    public const SCOPE_ALL_USERS = 'all_users';

    /**
     * @var string Scope: Users matching filter criteria
     */
    public const SCOPE_FILTERED = 'filtered';

    /**
     * @var string Scope: Specific user IDs
     */
    public const SCOPE_SPECIFIC = 'specific_users';

    /**
     * @var array Valid reset types
     */
    private const VALID_TYPES = [
        self::TYPE_MASS_PASSWORD_RESET,
        self::TYPE_SALT_ROTATION,
        self::TYPE_FULL_CREDENTIAL_RESET,
    ];

    /**
     * @var array Valid scopes
     */
    private const VALID_SCOPES = [
        self::SCOPE_ALL_USERS,
        self::SCOPE_FILTERED,
        self::SCOPE_SPECIFIC,
    ];

    // ========================================================================
    // 🚀 INITIATION
    // ========================================================================

    /**
     * 🚨 Initiate Mass Credential Reset
     *
     * Creates a credential reset operation record and prepares the user list.
     * Does NOT process users immediately - use processNextBatch() to process.
     *
     * @param int    $adminUserID Admin user ID who triggered the reset
     * @param string $resetType   Type of reset (mass_password_reset|salt_rotation|full_credential_reset)
     * @param string $reason      Admin-provided reason for the reset
     * @param string $scope       Scope (all_users|filtered|specific_users)
     * @param array  $scopeFilter Filter criteria for 'filtered' scope or user IDs for 'specific_users'
     * @return array Result with 'success', 'resetID', 'resetUUID', 'totalUsers', 'message'
     *
     * @throws InvalidArgumentException If parameters are invalid
     *
     * @see https://www.php.net/manual/en/function.random-bytes.php
     */
    public static function initiateMassReset(
        int $adminUserID,
        string $resetType,
        string $reason,
        string $scope = self::SCOPE_ALL_USERS,
        array $scopeFilter = []
    ): array {
        // ✅ Validate parameters (pure, no DB access — keep this first)
        if (!in_array($resetType, self::VALID_TYPES, true)) {
            throw new InvalidArgumentException(
                'Invalid reset type. Must be one of: ' . implode(', ', self::VALID_TYPES)
            );
        }

        if (!in_array($scope, self::VALID_SCOPES, true)) {
            throw new InvalidArgumentException(
                'Invalid scope. Must be one of: ' . implode(', ', self::VALID_SCOPES)
            );
        }

        // 🧼 Sanitise the free-text reason before any storage/logging/display (issue #23).
        // It is persisted to tblCredentialResets.reason, copied into emails, the
        // activity log and salt-rotation history, so strip tags + escape up front.
        $reason = SecurityUtils::sanitizeString($reason);

        if (empty(trim($reason))) {
            throw new InvalidArgumentException('Reason is required for credential reset operations');
        }

        try {
            // 🛡️ Validate the initiating admin BEFORE any side effect (issue #22).
            // The adminUserID must resolve to a real, active Super Admin — never
            // trust a caller-supplied ID blindly, even though the API controller
            // currently sources it from the session. This makes the service safe
            // by itself. Runs here (not before the pure parameter checks above) so
            // the DB-free validation contract is preserved, but still strictly
            // before the rate-limit read, the transaction, and any INSERT/UPDATE.
            self::assertAuthorizedAdmin($adminUserID);

            // 🔐 Rate limit: prevent multiple concurrent reset operations
            $existingActive = Database::fetchOne(
                "SELECT resetID FROM tblCredentialResets WHERE status IN ('pending', 'in_progress') LIMIT 1"
            );
            if ($existingActive) {
                return [
                    'success' => false,
                    'message' => 'A credential reset operation is already in progress (ID: '
                        . (int)$existingActive['resetID'] . '). Please wait for it to complete or cancel it first.',
                    'totalUsers' => 0,
                ];
            }

            // 🔄 Begin transaction for atomicity
            Database::beginTransaction();

            // 🆔 Generate UUID for this operation
            $resetUUID = self::generateUUID();

            // 🔐 Handle salt rotation if applicable
            $previousSalt = null;
            $newSalt = null;

            if ($resetType === self::TYPE_SALT_ROTATION || $resetType === self::TYPE_FULL_CREDENTIAL_RESET) {
                $previousSalt = getSetting('security.global_salt', '');
                $newSalt = bin2hex(random_bytes(32));

                // 🔐 Encrypt salt values before storage
                $encryptedPreviousSalt = !empty($previousSalt)
                    ? SecurityUtils::encrypt($previousSalt)
                    : '';
                $encryptedNewSalt = SecurityUtils::encrypt($newSalt);
            }

            // 📋 Get affected users based on scope
            $userIDs = self::getAffectedUsers($scope, $scopeFilter);
            $totalUsers = count($userIDs);

            if ($totalUsers === 0) {
                Database::rollback();
                return [
                    'success' => false,
                    'message' => 'No users match the specified criteria',
                    'totalUsers' => 0,
                ];
            }

            // 📝 Create credential reset record
            Database::query(
                "INSERT INTO tblCredentialResets (
                    resetUUID, initiatedBy, resetType, reason, scope,
                    scopeFilter, totalUsers, status, previousSalt, newSalt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
                [
                    $resetUUID,
                    $adminUserID,
                    $resetType,
                    $reason,
                    $scope,
                    !empty($scopeFilter) ? json_encode($scopeFilter) : null,
                    $totalUsers,
                    $encryptedPreviousSalt ?? null,
                    $encryptedNewSalt ?? null,
                ],
                'sissssiss'
            );

            $resetID = Database::getLastInsertId();

            // 📋 Insert individual user records for tracking
            self::insertUserRecords($resetID, $userIDs);

            // 🔄 Update global salt in settings (if salt rotation)
            if ($newSalt !== null) {
                Database::query(
                    "UPDATE tblSettings SET settingValue = ? WHERE settingKey = 'security.global_salt'",
                    [$encryptedNewSalt],
                    's'
                );

                // 📜 Record salt rotation history
                Database::query(
                    "INSERT INTO tblSaltRotationHistory (
                        resetID, previousSalt, newSalt, rotatedBy, reason, affectedUsers
                    ) VALUES (?, ?, ?, ?, ?, ?)",
                    [
                        $resetID,
                        $encryptedPreviousSalt ?? '',
                        $encryptedNewSalt,
                        $adminUserID,
                        $reason,
                        $totalUsers,
                    ],
                    'isssis'
                );
            }

            // ✅ Commit transaction
            Database::commit();

            // 📝 Log to activity log
            ActivityLogger::log(
                $adminUserID,
                'mass_credential_reset_initiated',
                'admin',
                'critical',
                "Mass credential reset initiated: {$resetType} for {$totalUsers} users",
                [
                    'resetID' => $resetID,
                    'resetUUID' => $resetUUID,
                    'resetType' => $resetType,
                    'scope' => $scope,
                    'totalUsers' => $totalUsers,
                    'reason' => $reason,
                ]
            );

            return [
                'success' => true,
                'resetID' => $resetID,
                'resetUUID' => $resetUUID,
                'totalUsers' => $totalUsers,
                'message' => "Credential reset initiated for {$totalUsers} users",
            ];

        } catch (Exception $e) {
            Database::rollback();
            ErrorLogger::logError('CREDENTIAL_RESET_ERROR', $e->getMessage(), $e->getFile(), $e->getLine());

            // 🔐 Never expose raw exception details in API responses (CWE-209)
            return [
                'success' => false,
                'message' => 'Failed to initiate credential reset. Please check the error log for details.',
                'totalUsers' => 0,
            ];
        }
    }

    // ========================================================================
    // 🔄 BATCH PROCESSING
    // ========================================================================

    /**
     * 🔄 Process Next Batch of Users
     *
     * Processes the next batch of users for a credential reset operation.
     * Call this repeatedly until all users are processed.
     *
     * @param int $resetID Credential reset operation ID
     * @return array Progress data with 'processed', 'remaining', 'emailsSent', 'emailsFailed', 'complete'
     */
    public static function processNextBatch(int $resetID): array
    {
        try {
            // 📋 Get reset operation details
            $reset = Database::fetchOne(
                "SELECT * FROM tblCredentialResets WHERE resetID = ?",
                [$resetID],
                'i'
            );

            if (!$reset) {
                return ['success' => false, 'message' => 'Reset operation not found'];
            }

            if ($reset['status'] === 'completed') {
                return [
                    'success' => true,
                    'complete' => true,
                    'processed' => (int)$reset['totalUsers'],
                    'remaining' => 0,
                    'emailsSent' => (int)$reset['emailsSent'],
                    'emailsFailed' => (int)$reset['emailsFailed'],
                    'message' => 'Reset operation already completed',
                ];
            }

            if ($reset['status'] === 'cancelled') {
                return ['success' => false, 'message' => 'Reset operation was cancelled'];
            }

            // 📊 Update status to in_progress
            if ($reset['status'] === 'pending') {
                Database::query(
                    "UPDATE tblCredentialResets SET status = 'in_progress' WHERE resetID = ?",
                    [$resetID],
                    'i'
                );
            }

            // 📋 Get batch size from settings
            $batchSize = (int)getSetting('security.credential_reset.batch_size', 100);

            // 📋 Get next batch of unprocessed users
            $users = Database::fetchAll(
                "SELECT cru.id, cru.userID, u.email, u.displayName, u.username, u.userUUID
                 FROM tblCredentialResetUsers cru
                 JOIN tblUsers u ON cru.userID = u.userID
                 WHERE cru.resetID = ?
                   AND cru.processedAt IS NULL
                   AND u.accountStatus != 'deleted'
                 ORDER BY cru.id ASC
                 LIMIT ?",
                [$resetID, $batchSize],
                'ii'
            );

            if (empty($users)) {
                // ✅ All users processed - mark as completed
                self::markResetCompleted($resetID);

                $finalReset = Database::fetchOne(
                    "SELECT * FROM tblCredentialResets WHERE resetID = ?",
                    [$resetID],
                    'i'
                );

                return [
                    'success' => true,
                    'complete' => true,
                    'processed' => (int)$finalReset['totalUsers'],
                    'remaining' => 0,
                    'emailsSent' => (int)$finalReset['emailsSent'],
                    'emailsFailed' => (int)$finalReset['emailsFailed'],
                    'message' => 'Reset operation completed',
                ];
            }

            // 🔄 Process each user in batch
            $batchEmailsSent = 0;
            $batchEmailsFailed = 0;
            $batchProcessed = 0;

            foreach ($users as $user) {
                $emailResult = self::processUserReset(
                    $resetID,
                    $reset['resetType'],
                    $user,
                    $reset['reason']
                );

                if ($emailResult['emailSent']) {
                    $batchEmailsSent++;
                } else {
                    $batchEmailsFailed++;
                }

                $batchProcessed++;
            }

            // 📊 Update progress counters
            Database::query(
                "UPDATE tblCredentialResets
                 SET processedUsers = processedUsers + ?,
                     emailsSent = emailsSent + ?,
                     emailsFailed = emailsFailed + ?
                 WHERE resetID = ?",
                [$batchProcessed, $batchEmailsSent, $batchEmailsFailed, $resetID],
                'iiii'
            );

            // 📊 Calculate remaining
            $remaining = Database::fetchOne(
                "SELECT COUNT(*) as cnt FROM tblCredentialResetUsers
                 WHERE resetID = ? AND processedAt IS NULL",
                [$resetID],
                'i'
            );

            $remainingCount = (int)($remaining['cnt'] ?? 0);

            // ✅ Check if all done
            if ($remainingCount === 0) {
                self::markResetCompleted($resetID);
            }

            return [
                'success' => true,
                'complete' => ($remainingCount === 0),
                'processed' => $batchProcessed,
                'remaining' => $remainingCount,
                'emailsSent' => $batchEmailsSent,
                'emailsFailed' => $batchEmailsFailed,
                'totalProcessed' => (int)$reset['processedUsers'] + $batchProcessed,
                'totalUsers' => (int)$reset['totalUsers'],
                'message' => "Processed {$batchProcessed} users, {$remainingCount} remaining",
            ];

        } catch (Exception $e) {
            ErrorLogger::logError('CREDENTIAL_RESET_ERROR', $e->getMessage(), $e->getFile(), $e->getLine());

            // 📝 Log error to reset record
            Database::query(
                "UPDATE tblCredentialResets
                 SET errorLog = JSON_ARRAY_APPEND(COALESCE(errorLog, '[]'), '$', ?)
                 WHERE resetID = ?",
                [json_encode(['error' => $e->getMessage(), 'time' => date('Y-m-d H:i:s')]), $resetID],
                'si'
            );

            // 🔐 Never expose raw exception details in API responses (CWE-209)
            return [
                'success' => false,
                'message' => 'Batch processing encountered an error. Please check the error log for details.',
            ];
        }
    }

    /**
     * 👤 Process Individual User Reset
     *
     * Invalidates password, generates reset token, and queues notification email.
     *
     * @param int    $resetID   Reset operation ID
     * @param string $resetType Type of reset
     * @param array  $user      User data (userID, email, displayName, username, userUUID)
     * @param string $reason    Reset reason for email template
     * @return array Result with 'passwordInvalidated', 'saltRotated', 'emailSent'
     */
    private static function processUserReset(
        int $resetID,
        string $resetType,
        array $user,
        string $reason
    ): array {
        $result = [
            'passwordInvalidated' => false,
            'saltRotated' => false,
            'emailSent' => false,
            'emailError' => null,
        ];

        try {
            $userID = (int)$user['userID'];

            // 🔐 Invalidate password if applicable
            if ($resetType === self::TYPE_MASS_PASSWORD_RESET || $resetType === self::TYPE_FULL_CREDENTIAL_RESET) {
                // 🔒 Set requiresPasswordChange flag and generate new salt
                $newUserSalt = bin2hex(random_bytes(32));

                Database::query(
                    "UPDATE tblUsers
                     SET requiresPasswordChange = TRUE,
                         passwordSalt = ?
                     WHERE userID = ?",
                    [$newUserSalt, $userID],
                    'si'
                );

                $result['passwordInvalidated'] = true;
            }

            // 🔄 Rotate user-level salt if applicable
            if ($resetType === self::TYPE_SALT_ROTATION || $resetType === self::TYPE_FULL_CREDENTIAL_RESET) {
                $newUserSalt = bin2hex(random_bytes(32));

                // 🔐 Set requiresPasswordChange when rotating salt — users must re-encrypt credentials
                Database::query(
                    "UPDATE tblUsers SET passwordSalt = ?, requiresPasswordChange = TRUE WHERE userID = ?",
                    [$newUserSalt, $userID],
                    'si'
                );

                $result['saltRotated'] = true;
            }

            // 🔑 Generate password reset token
            $rawToken = SecurityUtils::generateToken();
            $code = SecurityUtils::generateNumericCode(6);
            $expiryHours = (int)getSetting('security.credential_reset.token_expiry_hours', 72);
            $expiresAt = (new DateTime())->modify("+{$expiryHours} hours")->format('Y-m-d H:i:s');

            // 🔐 Hash token before storage — raw token sent in email, hashed version in DB
            // This prevents token theft if database is compromised
            // @see https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html
            $hashedToken = hash('sha256', $rawToken);

            // 📝 Store HASHED reset token in tblVerificationTokens
            Database::query(
                "INSERT INTO tblVerificationTokens (
                    userID, token, tokenType, email, code,
                    metadata, ipAddress, userAgent, expiresAt
                ) VALUES (?, ?, 'password_reset', ?, ?, ?, '0.0.0.0', 'CredentialResetService', ?)",
                [
                    $userID,
                    $hashedToken,
                    $user['email'],
                    $code,
                    json_encode(['resetID' => $resetID, 'mass_reset' => true]),
                    $expiresAt,
                ],
                'isssss'
            );

            // 📧 Use raw (unhashed) token for email — user needs this to verify
            $token = $rawToken;

            // 🔒 Invalidate existing sessions if configured
            $invalidateSessions = getSetting('security.credential_reset.invalidate_sessions', true);
            if ($invalidateSessions) {
                Database::query(
                    "UPDATE tblUserSessions SET isActive = FALSE, logoutReason = 'credential_reset'
                     WHERE userID = ? AND isActive = TRUE",
                    [$userID],
                    'i'
                );
            }

            // 📧 Queue notification email
            $emailResult = self::sendResetNotification($user, $token, $resetType, $reason, $expiryHours);
            $result['emailSent'] = $emailResult;

            if (!$emailResult) {
                $result['emailError'] = 'Failed to queue notification email';
            }

        } catch (Exception $e) {
            $result['emailError'] = $e->getMessage();
            ErrorLogger::logError(
                'CREDENTIAL_RESET_USER_ERROR',
                "Failed to process user {$user['userID']}: " . $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }

        // 📊 Update individual user tracking record
        Database::query(
            "UPDATE tblCredentialResetUsers
             SET passwordInvalidated = ?,
                 saltRotated = ?,
                 emailSent = ?,
                 emailError = ?,
                 processedAt = NOW()
             WHERE resetID = ? AND userID = ?",
            [
                $result['passwordInvalidated'] ? 1 : 0,
                $result['saltRotated'] ? 1 : 0,
                $result['emailSent'] ? 1 : 0,
                $result['emailError'],
                $resetID,
                (int)$user['userID'],
            ],
            'iiisii'
        );

        return $result;
    }

    // ========================================================================
    // 📧 EMAIL NOTIFICATIONS
    // ========================================================================

    /**
     * 📧 Send Reset Notification Email
     *
     * Queues an email notification for a user affected by a credential reset.
     * Uses the appropriate template based on reset type.
     *
     * @param array  $user       User data
     * @param string $token      Reset token
     * @param string $resetType  Type of reset
     * @param string $reason     Admin-provided reason
     * @param int    $expiryHours Token expiry in hours
     * @return bool True if email was successfully queued
     *
     * @see EmailService::sendTemplateEmail()
     */
    private static function sendResetNotification(
        array $user,
        string $token,
        string $resetType,
        string $reason,
        int $expiryHours
    ): bool {
        $baseURL = getSetting('site.url', 'https://SIGNula.id');
        $appName = getSetting('app.name', 'SIGNula');
        $supportUrl = $baseURL . '/support';
        // 🔒 B-039: rawurlencode() the token in the query string (hardening;
        //    no-op for current hex tokens, safe against future formats, no
        //    double-encoding since hex is unchanged by rawurlencode()).
        $resetUrl = $baseURL . '/reset-password?token=' . rawurlencode($token);
        $expiryTime = (new DateTime())->modify("+{$expiryHours} hours")->format('d M Y \a\t H:i T');

        // 🎯 Choose template based on reset type
        $templateKey = match ($resetType) {
            self::TYPE_FULL_CREDENTIAL_RESET => 'security_breach_alert',
            self::TYPE_SALT_ROTATION => 'mass_password_reset',
            default => 'mass_password_reset',
        };

        // 📝 Prepare template variables
        $variables = [
            'displayName' => $user['displayName'] ?? $user['username'] ?? 'User',
            'email' => $user['email'],
            'resetUrl' => $resetUrl,
            'resetReason' => $reason,
            'breachDescription' => $reason,
            'expiryTime' => $expiryTime,
            'expiryHours' => (string)$expiryHours,
            'incidentRef' => 'CR-' . strtoupper(substr($user['userUUID'] ?? uniqid(), 0, 8)),
            'appName' => $appName,
            'currentYear' => date('Y'),
            'supportUrl' => $supportUrl,
        ];

        // 📧 Queue email with highest priority for security notifications
        $priority = (int)getSetting('security.credential_reset.email_priority', 1);

        return EmailService::sendTemplateEmail(
            $user['email'],
            $templateKey,
            $variables,
            (int)$user['userID'],
            $priority
        );
    }

    // ========================================================================
    // 📊 STATUS & MONITORING
    // ========================================================================

    /**
     * 📊 Get Reset Operation Status
     *
     * Returns current status and progress of a credential reset operation.
     *
     * @param int $resetID Reset operation ID
     * @return array|null Reset status data or null if not found
     */
    public static function getResetStatus(int $resetID): ?array
    {
        $reset = Database::fetchOne(
            "SELECT cr.*,
                    u.username AS initiatedByUsername,
                    u.displayName AS initiatedByName
             FROM tblCredentialResets cr
             JOIN tblUsers u ON cr.initiatedBy = u.userID
             WHERE cr.resetID = ?",
            [$resetID],
            'i'
        );

        if (!$reset) {
            return null;
        }

        // 📊 Calculate progress percentage
        $total = (int)$reset['totalUsers'];
        $processed = (int)$reset['processedUsers'];
        $reset['progressPercent'] = $total > 0 ? round(($processed / $total) * 100, 1) : 0;

        // 📊 Get password change completion stats
        $completionStats = Database::fetchOne(
            "SELECT
                COUNT(CASE WHEN passwordChangedAt IS NOT NULL THEN 1 END) as passwordsChanged,
                COUNT(CASE WHEN emailSent = TRUE THEN 1 END) as emailsSentCount,
                COUNT(CASE WHEN emailSent = FALSE AND processedAt IS NOT NULL THEN 1 END) as emailsFailedCount
             FROM tblCredentialResetUsers
             WHERE resetID = ?",
            [$resetID],
            'i'
        );

        $reset['passwordsChanged'] = (int)($completionStats['passwordsChanged'] ?? 0);
        $reset['passwordChangePercent'] = $total > 0
            ? round(($reset['passwordsChanged'] / $total) * 100, 1)
            : 0;

        return $reset;
    }

    /**
     * 📋 List All Reset Operations
     *
     * Returns a paginated list of all credential reset operations.
     *
     * @param int $page   Page number (1-based)
     * @param int $perPage Results per page
     * @return array List of operations with pagination info
     */
    public static function listResetOperations(int $page = 1, int $perPage = 20): array
    {
        // 🔒 Clamp pagination to sane bounds so a caller cannot request unbounded
        // rows or a negative offset (issue #24). page >= 1; 1 <= perPage <= 200.
        $page    = max(1, $page);
        $perPage = min(200, max(1, $perPage));

        $offset = ($page - 1) * $perPage;

        // 📊 Get total count
        $countResult = Database::fetchOne(
            "SELECT COUNT(*) as total FROM tblCredentialResets"
        );
        $total = (int)($countResult['total'] ?? 0);

        // 📋 Get operations
        $operations = Database::fetchAll(
            "SELECT cr.resetID, cr.resetUUID, cr.resetType, cr.reason, cr.scope,
                    cr.totalUsers, cr.processedUsers, cr.emailsSent, cr.emailsFailed,
                    cr.status, cr.createdAt, cr.completedAt,
                    u.username AS initiatedByUsername
             FROM tblCredentialResets cr
             JOIN tblUsers u ON cr.initiatedBy = u.userID
             ORDER BY cr.createdAt DESC
             LIMIT ? OFFSET ?",
            [$perPage, $offset],
            'ii'
        );

        return [
            'operations' => $operations ?: [],
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'totalPages' => (int)ceil($total / $perPage),
            ],
        ];
    }

    /**
     * 🚫 Cancel Reset Operation
     *
     * Cancels a pending or in-progress reset operation.
     * Users already processed will not be reverted.
     *
     * @param int $resetID  Reset operation ID
     * @param int $adminUserID Admin who is cancelling
     * @return array Result with 'success' and 'message'
     */
    public static function cancelReset(int $resetID, int $adminUserID): array
    {
        // 🛡️ Validate the cancelling admin is a real, active Super Admin (issue #22).
        self::assertAuthorizedAdmin($adminUserID);

        $reset = Database::fetchOne(
            "SELECT status, processedUsers, totalUsers FROM tblCredentialResets WHERE resetID = ?",
            [$resetID],
            'i'
        );

        if (!$reset) {
            return ['success' => false, 'message' => 'Reset operation not found'];
        }

        if ($reset['status'] === 'completed') {
            return ['success' => false, 'message' => 'Cannot cancel a completed operation'];
        }

        if ($reset['status'] === 'cancelled') {
            return ['success' => false, 'message' => 'Operation is already cancelled'];
        }

        Database::query(
            "UPDATE tblCredentialResets SET status = 'cancelled', completedAt = NOW() WHERE resetID = ?",
            [$resetID],
            'i'
        );

        // 📝 Log cancellation
        ActivityLogger::log(
            $adminUserID,
            'mass_credential_reset_cancelled',
            'admin',
            'warning',
            "Mass credential reset cancelled. {$reset['processedUsers']}/{$reset['totalUsers']} users were already processed.",
            ['resetID' => $resetID]
        );

        return [
            'success' => true,
            'message' => "Reset cancelled. {$reset['processedUsers']} of {$reset['totalUsers']} users were already processed.",
        ];
    }

    // ========================================================================
    // 🔐 SALT MANAGEMENT
    // ========================================================================

    /**
     * 🔄 Get Current Global Salt
     *
     * Retrieves and decrypts the current global application salt.
     *
     * @return string Current global salt (empty string if not set)
     */
    public static function getCurrentGlobalSalt(): string
    {
        $encryptedSalt = getSetting('security.global_salt', '');

        if (empty($encryptedSalt)) {
            return '';
        }

        try {
            $decrypted = SecurityUtils::decrypt($encryptedSalt);

            // 🔐 Verify decryption produced valid output — never return corrupted data
            if ($decrypted === false || $decrypted === '') {
                throw new \RuntimeException(
                    'Failed to decrypt global salt. Encryption key may have changed or data is corrupted.'
                );
            }

            return $decrypted;
        } catch (\RuntimeException $e) {
            // 🚨 Re-throw RuntimeException (our own validation failures)
            throw $e;
        } catch (Exception $e) {
            // 🚨 Decryption failure is critical — do NOT silently return corrupted data
            // @see https://cwe.mitre.org/data/definitions/311.html
            throw new \RuntimeException(
                'Failed to decrypt global salt: ' . $e->getMessage()
            );
        }
    }

    /**
     * 📜 Get Salt Rotation History
     *
     * Returns the history of all salt rotations.
     *
     * @param int $limit Number of records to return
     * @return array Array of rotation history records
     */
    public static function getSaltRotationHistory(int $limit = 20): array
    {
        // 🔒 Clamp result count to sane bounds (issue #24): 1 <= limit <= 200.
        $limit = min(200, max(1, $limit));

        return Database::fetchAll(
            "SELECT srh.*, u.username AS rotatedByUsername
             FROM tblSaltRotationHistory srh
             JOIN tblUsers u ON srh.rotatedBy = u.userID
             ORDER BY srh.createdAt DESC
             LIMIT ?",
            [$limit],
            'i'
        ) ?: [];
    }

    // ========================================================================
    // 🔧 HELPER METHODS
    // ========================================================================

    /**
     * 🛡️ Assert the supplied admin ID is a real, active Super Admin
     *
     * Defence-in-depth for issue #22: callers pass an adminUserID that ends up
     * persisted (as initiatedBy / rotatedBy) and recorded in the activity log.
     * This guard makes sure a credential reset can never be attributed to — or
     * triggered on behalf of — a non-existent, inactive, or non-admin account,
     * regardless of how the caller obtained the ID.
     *
     * @param int $adminUserID Admin user ID to validate
     * @return void
     * @throws InvalidArgumentException If the ID is not a valid active Super Admin
     */
    private static function assertAuthorizedAdmin(int $adminUserID): void
    {
        // 🔢 Type-coerced bounds check — reject zero/negative IDs outright.
        if ($adminUserID <= 0) {
            throw new InvalidArgumentException('A valid initiating admin is required for credential reset operations');
        }

        // 🔍 Verify the account exists, is active, and holds Super Admin rights.
        $admin = Database::fetchOne(
            "SELECT userID
             FROM tblUsers
             WHERE userID = ?
               AND isSuperAdmin = 1
               AND accountStatus = 'active'",
            [$adminUserID],
            'i'
        );

        if (!$admin) {
            throw new InvalidArgumentException('The initiating user is not an authorized active Super Admin');
        }
    }

    /**
     * 👥 Get Affected Users Based on Scope
     *
     * Returns array of user IDs matching the specified scope and filter.
     *
     * @param string $scope       Scope type
     * @param array  $scopeFilter Filter criteria
     * @return array Array of user IDs
     */
    private static function getAffectedUsers(string $scope, array $scopeFilter): array
    {
        switch ($scope) {
            case self::SCOPE_ALL_USERS:
                // 📋 All active users with passwords (not deleted, not passwordless-only)
                $result = Database::fetchAll(
                    "SELECT userID FROM tblUsers
                     WHERE accountStatus != 'deleted'
                       AND passwordHash IS NOT NULL
                     ORDER BY userID ASC"
                );
                return array_column($result ?: [], 'userID');

            case self::SCOPE_FILTERED:
                return self::getFilteredUsers($scopeFilter);

            case self::SCOPE_SPECIFIC:
                // 📋 Specific user IDs (validate they exist)
                if (empty($scopeFilter['userIDs'])) {
                    return [];
                }

                $userIDs = array_map('intval', $scopeFilter['userIDs']);
                $placeholders = implode(',', array_fill(0, count($userIDs), '?'));
                $types = str_repeat('i', count($userIDs));

                $result = Database::fetchAll(
                    "SELECT userID FROM tblUsers
                     WHERE userID IN ({$placeholders})
                       AND accountStatus != 'deleted'
                       AND passwordHash IS NOT NULL",
                    $userIDs,
                    $types
                );
                return array_column($result ?: [], 'userID');

            default:
                return [];
        }
    }

    /**
     * 🔍 Get Filtered Users
     *
     * Returns user IDs matching filter criteria.
     * Supported filters: status, tier, createdBefore, createdAfter, lastLoginBefore
     *
     * @param array $filter Filter criteria
     * @return array Array of user IDs
     */
    private static function getFilteredUsers(array $filter): array
    {
        $conditions = ["accountStatus != 'deleted'", "passwordHash IS NOT NULL"];
        $params = [];
        $types = '';

        // 📊 Filter by account status
        if (!empty($filter['status'])) {
            $conditions[] = 'accountStatus = ?';
            $params[] = $filter['status'];
            $types .= 's';
        }

        // 🏷️ Filter by account tier
        if (!empty($filter['tier'])) {
            $conditions[] = 'accountTier = ?';
            $params[] = $filter['tier'];
            $types .= 's';
        }

        // 📅 Filter by creation date
        if (!empty($filter['createdBefore'])) {
            $conditions[] = 'createdAt < ?';
            $params[] = $filter['createdBefore'];
            $types .= 's';
        }

        if (!empty($filter['createdAfter'])) {
            $conditions[] = 'createdAt > ?';
            $params[] = $filter['createdAfter'];
            $types .= 's';
        }

        // 📅 Filter by last login
        if (!empty($filter['lastLoginBefore'])) {
            $conditions[] = '(lastLoginAt IS NULL OR lastLoginAt < ?)';
            $params[] = $filter['lastLoginBefore'];
            $types .= 's';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $conditions);

        $result = Database::fetchAll(
            "SELECT userID FROM tblUsers {$whereClause} ORDER BY userID ASC",
            $params,
            $types
        );

        return array_column($result ?: [], 'userID');
    }

    /**
     * 📝 Insert User Records for Tracking
     *
     * Bulk inserts user tracking records for a credential reset operation.
     * Uses batched inserts for efficiency with large user counts.
     *
     * @param int   $resetID Reset operation ID
     * @param array $userIDs Array of user IDs
     */
    private static function insertUserRecords(int $resetID, array $userIDs): void
    {
        // 📦 Insert in batches of 500 to avoid query size limits
        $batchSize = 500;
        $batches = array_chunk($userIDs, $batchSize);

        foreach ($batches as $batch) {
            $values = [];
            $params = [];
            $types = '';

            foreach ($batch as $userID) {
                $values[] = '(?, ?)';
                $params[] = $resetID;
                $params[] = (int)$userID;
                $types .= 'ii';
            }

            $query = "INSERT INTO tblCredentialResetUsers (resetID, userID) VALUES " . implode(', ', $values);
            Database::query($query, $params, $types);
        }
    }

    /**
     * ✅ Mark Reset as Completed
     *
     * Updates a reset operation status to completed.
     *
     * @param int $resetID Reset operation ID
     */
    private static function markResetCompleted(int $resetID): void
    {
        Database::query(
            "UPDATE tblCredentialResets SET status = 'completed', completedAt = NOW() WHERE resetID = ?",
            [$resetID],
            'i'
        );

        // 📋 Get final stats for logging
        $reset = Database::fetchOne(
            "SELECT * FROM tblCredentialResets WHERE resetID = ?",
            [$resetID],
            'i'
        );

        if ($reset) {
            ActivityLogger::log(
                (int)$reset['initiatedBy'],
                'mass_credential_reset_completed',
                'admin',
                'critical',
                "Mass credential reset completed: {$reset['totalUsers']} users processed, {$reset['emailsSent']} emails sent",
                [
                    'resetID' => $resetID,
                    'totalUsers' => (int)$reset['totalUsers'],
                    'emailsSent' => (int)$reset['emailsSent'],
                    'emailsFailed' => (int)$reset['emailsFailed'],
                ]
            );
        }
    }

    /**
     * 🆔 Generate UUID v4
     *
     * Generates a RFC 4122 compliant UUID v4.
     *
     * @return string UUID string (e.g., 'f47ac10b-58cc-4372-a567-0e02b2c3d479')
     * @see https://www.php.net/manual/en/function.random-bytes.php
     */
    private static function generateUUID(): string
    {
        $data = random_bytes(16);

        // 🔧 Set version (4) and variant bits per RFC 4122
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    // ========================================================================
    // 📊 REPORTING
    // ========================================================================

    /**
     * 📊 Get User Compliance Report
     *
     * Returns how many users have completed their password change after a mass reset.
     *
     * @param int $resetID Reset operation ID
     * @return array Compliance statistics
     */
    public static function getComplianceReport(int $resetID): array
    {
        $stats = Database::fetchOne(
            "SELECT
                COUNT(*) as totalUsers,
                COUNT(CASE WHEN passwordChangedAt IS NOT NULL THEN 1 END) as compliant,
                COUNT(CASE WHEN passwordChangedAt IS NULL AND processedAt IS NOT NULL THEN 1 END) as pending,
                COUNT(CASE WHEN emailSent = FALSE AND processedAt IS NOT NULL THEN 1 END) as emailFailed
             FROM tblCredentialResetUsers
             WHERE resetID = ?",
            [$resetID],
            'i'
        );

        $total = (int)($stats['totalUsers'] ?? 0);
        $compliant = (int)($stats['compliant'] ?? 0);

        return [
            'resetID' => $resetID,
            'totalUsers' => $total,
            'compliant' => $compliant,
            'pending' => (int)($stats['pending'] ?? 0),
            'emailFailed' => (int)($stats['emailFailed'] ?? 0),
            'compliancePercent' => $total > 0 ? round(($compliant / $total) * 100, 1) : 0,
        ];
    }

    /**
     * 📋 Get Non-Compliant Users
     *
     * Returns users who have not yet changed their password after a mass reset.
     *
     * @param int $resetID Reset operation ID
     * @param int $limit   Max results
     * @return array Array of non-compliant user data
     */
    public static function getNonCompliantUsers(int $resetID, int $limit = 100): array
    {
        // 🔒 Clamp result count to sane bounds (issue #24): 1 <= limit <= 200.
        $limit = min(200, max(1, $limit));

        return Database::fetchAll(
            "SELECT cru.userID, u.username, u.email, u.displayName,
                    cru.emailSent, cru.processedAt, u.lastLoginAt
             FROM tblCredentialResetUsers cru
             JOIN tblUsers u ON cru.userID = u.userID
             WHERE cru.resetID = ?
               AND cru.passwordChangedAt IS NULL
               AND cru.processedAt IS NOT NULL
             ORDER BY cru.processedAt ASC
             LIMIT ?",
            [$resetID, $limit],
            'ii'
        ) ?: [];
    }
}

// ✅ CredentialResetService loaded successfully
