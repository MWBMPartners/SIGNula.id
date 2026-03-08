<?php
/**
 * ============================================================================
 * 🗑️ SIGNula - Account Manager (GDPR Compliance)
 * ============================================================================
 *
 * Handles GDPR Article 17 (Right to Erasure) and Article 20 (Right to Data
 * Portability) operations: account deletion with grace period, data export
 * to JSON, and scheduled deletion processing.
 *
 * All methods are static (matching project conventions).
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @link       https://gdpr-info.eu/art-17-gdpr/
 * @link       https://gdpr-info.eu/art-20-gdpr/
 * @see        _database/migrations/021_account_deletion_export.sql
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class AccountManager
{
    // ========================================================================
    // 🗑️ ACCOUNT DELETION
    // ========================================================================

    /**
     * 🗑️ Request Account Deletion
     *
     * Initiates the account deletion process with a configurable grace period.
     * During the grace period, the user can cancel the request by logging in.
     *
     * @param int    $userID   The user's ID
     * @param string $password The user's current password (verification)
     * @param string $reason   Optional reason for deletion
     * @return array ['success' => bool, 'message' => string, 'scheduled_for' => string|null]
     *
     * @see https://gdpr-info.eu/art-17-gdpr/ — Right to Erasure
     */
    public static function requestAccountDeletion(
        int $userID,
        string $password,
        string $reason = ''
    ): array {
        try {
            // 🔍 Fetch user data
            $user = Database::fetchOne(
                "SELECT userID, email, displayName, passwordHash, deletionRequestedAt
                 FROM tblUsers WHERE userID = ? AND deletedAt IS NULL",
                [$userID],
                'i'
            );

            if (!$user) {
                return ['success' => false, 'message' => 'User not found.', 'scheduled_for' => null];
            }

            // 🔐 Verify password
            if (!password_verify($password, $user['passwordHash'])) {
                if (class_exists('ActivityLogger')) {
                    ActivityLogger::log(
                        userID: $userID,
                        activityType: 'account_deletion_failed',
                        activityResult: 'failure',
                        activityDetails: 'Incorrect password during account deletion request'
                    );
                }
                return ['success' => false, 'message' => 'Incorrect password.', 'scheduled_for' => null];
            }

            // 🔍 Check if deletion already requested
            if (!empty($user['deletionRequestedAt'])) {
                return ['success' => false, 'message' => 'Account deletion already requested.', 'scheduled_for' => null];
            }

            // ⏰ Calculate deletion date (grace period)
            $graceDays = (int) getSetting('gdpr.deletion.grace_period_days', 30);
            $deletionDate = new DateTime();
            $deletionDate->modify("+{$graceDays} days");
            $deletionDateStr = $deletionDate->format('Y-m-d H:i:s');

            // 💾 Update user record
            $sanitizedReason = SecurityUtils::sanitizeString($reason);
            Database::query(
                "UPDATE tblUsers
                 SET deletionRequestedAt = NOW(),
                     deletionScheduledFor = ?,
                     deletionReason = ?
                 WHERE userID = ?",
                [$deletionDateStr, $sanitizedReason ?: null, $userID],
                'ssi'
            );

            // 📝 Log activity
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    userID: $userID,
                    activityType: 'account_deletion_requested',
                    activityResult: 'success',
                    activityDetails: 'Account deletion requested, scheduled for ' . $deletionDateStr
                );
            }

            // 📧 Send confirmation email
            if (class_exists('EmailService')) {
                $baseURL = getSetting('url.base', 'https://SIGNula.id');
                EmailService::sendTemplateEmail($user['email'], 'account_deletion_requested', [
                    'displayName'  => $user['displayName'] ?? 'User',
                    'deletionDate' => $deletionDate->format('j F Y'),
                    'gracePeriod'  => (string) $graceDays,
                    'loginUrl'     => $baseURL . '/login',
                ]);
            }

            // 📧 Notify admin if configured
            $notifyAdmin = getSetting('gdpr.deletion.notify_admin', 'true');
            if ($notifyAdmin === 'true' || $notifyAdmin === '1') {
                $adminEmail = getSetting('admin.email', '');
                if (!empty($adminEmail) && class_exists('EmailService')) {
                    EmailService::queueEmail(
                        recipientEmail: $adminEmail,
                        subject: 'Account Deletion Request — User #' . $userID,
                        bodyText: "User {$user['email']} (ID: {$userID}) has requested account deletion.\nScheduled for: {$deletionDateStr}\nReason: " . ($sanitizedReason ?: 'Not provided'),
                        priority: 3
                    );
                }
            }

            return [
                'success'       => true,
                'message'       => "Account deletion scheduled. You have {$graceDays} days to cancel.",
                'scheduled_for' => $deletionDateStr,
            ];

        } catch (Exception $e) {
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            }
            return ['success' => false, 'message' => 'An error occurred. Please try again.', 'scheduled_for' => null];
        }
    }

    /**
     * ↩️ Cancel Account Deletion
     *
     * Cancels a pending account deletion request.
     *
     * @param int $userID The user's ID
     * @return array ['success' => bool, 'message' => string]
     */
    public static function cancelAccountDeletion(int $userID): array
    {
        try {
            Database::query(
                "UPDATE tblUsers
                 SET deletionRequestedAt = NULL,
                     deletionScheduledFor = NULL,
                     deletionReason = NULL
                 WHERE userID = ? AND deletionRequestedAt IS NOT NULL AND deletedAt IS NULL",
                [$userID],
                'i'
            );

            if (Database::affectedRows() === 0) {
                return ['success' => false, 'message' => 'No pending deletion request found.'];
            }

            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    userID: $userID,
                    activityType: 'account_deletion_cancelled',
                    activityResult: 'success',
                    activityDetails: 'Account deletion request cancelled by user'
                );
            }

            return ['success' => true, 'message' => 'Account deletion cancelled successfully.'];

        } catch (Exception $e) {
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            }
            return ['success' => false, 'message' => 'An error occurred. Please try again.'];
        }
    }

    /**
     * ⏰ Process Scheduled Deletions
     *
     * Finds all accounts past their grace period and permanently deletes them.
     * Should be called via cron job or admin CLI script.
     *
     * @return array ['processed' => int, 'deleted' => int, 'errors' => int]
     */
    public static function processScheduledDeletions(): array
    {
        $result = ['processed' => 0, 'deleted' => 0, 'errors' => 0];

        try {
            // 🔍 Find accounts past their scheduled deletion date
            $pendingDeletions = Database::fetchAll(
                "SELECT userID, email, displayName
                 FROM tblUsers
                 WHERE deletionScheduledFor IS NOT NULL
                 AND deletionScheduledFor <= NOW()
                 AND deletedAt IS NULL",
                [],
                ''
            );

            foreach ($pendingDeletions as $user) {
                $result['processed']++;

                try {
                    self::permanentlyDeleteAccount($user['userID']);

                    // 📧 Send deletion completion email
                    if (class_exists('EmailService') && !empty($user['email'])) {
                        EmailService::sendTemplateEmail($user['email'], 'account_deletion_completed', []);
                    }

                    $result['deleted']++;

                } catch (Exception $e) {
                    $result['errors']++;
                    if (class_exists('ErrorLogger')) {
                        ErrorLogger::logError('Exception', 'Failed to delete account #' . $user['userID'] . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
                    }
                }
            }

        } catch (Exception $e) {
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            }
        }

        return $result;
    }

    /**
     * 💀 Permanently Delete Account
     *
     * Hard-deletes or anonymises all user data. This is irreversible.
     * Data is removed from: tblUsers, tblUserSessions, tblVerificationTokens,
     * tblActivityLog (anonymised), tblUserMFA, tblWebAuthnCredentials,
     * tblOAuthAccounts, tblUserAvatars, tblUserPreferences, tblPasswordHistory.
     *
     * @param int $userID The user's ID
     * @return void
     * @throws RuntimeException If deletion fails
     */
    public static function permanentlyDeleteAccount(int $userID): void
    {
        Database::beginTransaction();

        try {
            // 🗑️ Delete sessions
            Database::query("DELETE FROM tblUserSessions WHERE userID = ?", [$userID], 'i');

            // 🗑️ Delete verification tokens
            Database::query("DELETE FROM tblVerificationTokens WHERE userID = ?", [$userID], 'i');

            // 🗑️ Delete MFA data
            Database::query("DELETE FROM tblUserMFA WHERE userID = ?", [$userID], 'i');

            // 🗑️ Delete WebAuthn credentials
            Database::query("DELETE FROM tblWebAuthnCredentials WHERE userID = ?", [$userID], 'i');

            // 🗑️ Delete OAuth linked accounts
            Database::query("DELETE FROM tblOAuthAccounts WHERE userID = ?", [$userID], 'i');

            // 🗑️ Delete avatars (and remove files)
            $avatars = Database::fetchAll(
                "SELECT avatarPath FROM tblUserAvatars WHERE userID = ?",
                [$userID],
                'i'
            );
            if (!empty($avatars)) {
                $uploadBase = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_private'
                    . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
                foreach ($avatars as $avatar) {
                    if (!empty($avatar['avatarPath'])) {
                        $filePath = $uploadBase . DIRECTORY_SEPARATOR . $avatar['avatarPath'];
                        if (file_exists($filePath)) {
                            @unlink($filePath);
                        }
                    }
                }
                Database::query("DELETE FROM tblUserAvatars WHERE userID = ?", [$userID], 'i');
            }

            // 🗑️ Delete password history
            Database::query("DELETE FROM tblPasswordHistory WHERE userID = ?", [$userID], 'i');

            // 🗑️ Delete user preferences
            Database::query("DELETE FROM tblUserPreferences WHERE userID = ?", [$userID], 'i');

            // 🗑️ Delete data export requests and files
            $exports = Database::fetchAll(
                "SELECT filePath FROM tblDataExportRequests WHERE userID = ?",
                [$userID],
                'i'
            );
            if (!empty($exports)) {
                $exportBase = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_private'
                    . DIRECTORY_SEPARATOR . 'exports';
                foreach ($exports as $export) {
                    if (!empty($export['filePath'])) {
                        $filePath = $exportBase . DIRECTORY_SEPARATOR . $export['filePath'];
                        if (file_exists($filePath)) {
                            @unlink($filePath);
                        }
                    }
                }
                Database::query("DELETE FROM tblDataExportRequests WHERE userID = ?", [$userID], 'i');
            }

            // 🔒 Anonymise activity log (don't delete — needed for audit)
            Database::query(
                "UPDATE tblActivityLog SET userID = NULL, ipAddress = 'REDACTED', userAgent = 'REDACTED'
                 WHERE userID = ?",
                [$userID],
                'i'
            );

            // 🔒 Anonymise payment records (financial records must be retained)
            Database::query(
                "UPDATE tblPayments SET ipAddress = 'REDACTED', userAgent = 'REDACTED'
                 WHERE userID = ?",
                [$userID],
                'i'
            );

            // 💀 Soft-delete the user (keep record for FK integrity)
            Database::query(
                "UPDATE tblUsers
                 SET email = CONCAT('deleted_', userID, '@redacted.local'),
                     username = CONCAT('deleted_', userID),
                     passwordHash = NULL,
                     passwordSalt = NULL,
                     firstName = 'Deleted',
                     lastName = 'User',
                     displayName = 'Deleted User',
                     phoneNumber = NULL,
                     dateOfBirth = NULL,
                     profilePicture = NULL,
                     accountStatus = 'deleted',
                     deletedAt = NOW(),
                     deletionRequestedAt = NULL,
                     deletionScheduledFor = NULL
                 WHERE userID = ?",
                [$userID],
                'i'
            );

            Database::commit();

            // 📝 Log (system-level, no userID since user is deleted)
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    userID: null,
                    activityType: 'account_permanently_deleted',
                    activityResult: 'success',
                    activityDetails: 'User account #' . $userID . ' permanently deleted per GDPR request'
                );
            }

        } catch (Exception $e) {
            Database::rollback();
            throw new RuntimeException('Account deletion failed: ' . $e->getMessage(), 0, $e);
        }
    }

    // ========================================================================
    // 📦 DATA EXPORT (Right to Data Portability)
    // ========================================================================

    /**
     * 📦 Export User Data
     *
     * Generates a JSON file containing all personal data for the user.
     * Implements GDPR Article 20 (Right to Data Portability).
     *
     * @param int    $userID   The user's ID
     * @param string $password The user's current password (verification)
     * @return array ['success' => bool, 'message' => string, 'export_id' => int|null]
     *
     * @see https://gdpr-info.eu/art-20-gdpr/ — Right to Data Portability
     */
    public static function exportUserData(int $userID, string $password): array
    {
        try {
            // 🔍 Fetch and verify user
            $user = Database::fetchOne(
                "SELECT * FROM tblUsers WHERE userID = ? AND deletedAt IS NULL",
                [$userID],
                'i'
            );

            if (!$user) {
                return ['success' => false, 'message' => 'User not found.', 'export_id' => null];
            }

            // 🔐 Verify password
            if (!password_verify($password, $user['passwordHash'])) {
                return ['success' => false, 'message' => 'Incorrect password.', 'export_id' => null];
            }

            // ⏰ Rate limiting — check for recent export request
            $rateLimitHours = (int) getSetting('gdpr.export.rate_limit_hours', 24);
            $recentExport = Database::fetchOne(
                "SELECT exportID FROM tblDataExportRequests
                 WHERE userID = ? AND requestedAt > DATE_SUB(NOW(), INTERVAL ? HOUR)
                 AND status IN ('pending', 'processing', 'completed')
                 ORDER BY requestedAt DESC LIMIT 1",
                [$userID, $rateLimitHours],
                'ii'
            );

            if ($recentExport) {
                return [
                    'success'   => false,
                    'message'   => "You can only request one export every {$rateLimitHours} hours.",
                    'export_id' => null,
                ];
            }

            // 📋 Collect all user data
            $exportData = self::collectUserData($userID, $user);

            // 💾 Generate export file
            $retentionHours = (int) getSetting('gdpr.export.retention_hours', 72);
            $downloadToken = bin2hex(random_bytes(32));
            $expiresAt = (new DateTime())->modify("+{$retentionHours} hours")->format('Y-m-d H:i:s');

            // 📂 Ensure export directory exists
            $exportDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_private'
                . DIRECTORY_SEPARATOR . 'exports';
            if (!is_dir($exportDir)) {
                mkdir($exportDir, 0750, true);
            }

            // 📄 Write JSON file
            $fileName = 'export_' . $userID . '_' . date('Ymd_His') . '.json';
            $filePath = $exportDir . DIRECTORY_SEPARATOR . $fileName;
            $jsonContent = json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            file_put_contents($filePath, $jsonContent);

            $fileSize = filesize($filePath);
            $fileHash = hash_file('sha256', $filePath);

            // 💾 Record export request
            Database::query(
                "INSERT INTO tblDataExportRequests
                 (userID, status, filePath, fileSize, fileHash, downloadToken, ipAddress, completedAt, expiresAt)
                 VALUES (?, 'completed', ?, ?, ?, ?, ?, NOW(), ?)",
                [$userID, $fileName, $fileSize, $fileHash, $downloadToken, getClientIP(), $expiresAt],
                'isisss s'
            );

            $exportID = Database::insertId();

            // 📧 Send notification email
            if (class_exists('EmailService')) {
                $baseURL = getSetting('url.base', 'https://SIGNula.id');
                EmailService::sendTemplateEmail($user['email'], 'data_export_ready', [
                    'displayName' => $user['displayName'] ?? 'User',
                    'downloadUrl' => $baseURL . '/settings/privacy?action=download_export&token=' . urlencode($downloadToken),
                    'expiryHours' => (string) $retentionHours,
                ]);
            }

            // 📝 Log
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    userID: $userID,
                    activityType: 'data_export_completed',
                    activityResult: 'success',
                    activityDetails: 'GDPR data export generated (' . number_format($fileSize) . ' bytes)'
                );
            }

            return [
                'success'   => true,
                'message'   => 'Your data export is ready for download.',
                'export_id' => $exportID,
            ];

        } catch (Exception $e) {
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            }
            return ['success' => false, 'message' => 'An error occurred. Please try again.', 'export_id' => null];
        }
    }

    /**
     * 📋 Collect All User Data for Export
     *
     * Gathers data from all tables containing user information.
     * Sensitive fields (password hashes, tokens, secrets) are excluded.
     *
     * @param int   $userID The user's ID
     * @param array $user   The user's tblUsers row
     * @return array Structured data for JSON export
     */
    private static function collectUserData(int $userID, array $user): array
    {
        $data = [
            'export_metadata' => [
                'export_date'   => date('c'),
                'format'        => 'SIGNula GDPR Data Export v1.0',
                'user_id'       => $userID,
                'description'   => 'Complete personal data export per GDPR Article 20',
            ],
            'profile' => [
                'username'      => $user['username'] ?? null,
                'email'         => $user['email'] ?? null,
                'first_name'    => $user['firstName'] ?? null,
                'last_name'     => $user['lastName'] ?? null,
                'display_name'  => $user['displayName'] ?? null,
                'phone_number'  => $user['phoneNumber'] ?? null,
                'date_of_birth' => $user['dateOfBirth'] ?? null,
                'locale'        => $user['locale'] ?? null,
                'timezone'      => $user['timezone'] ?? null,
                'account_status' => $user['accountStatus'] ?? null,
                'email_verified' => (bool) ($user['emailVerified'] ?? false),
                'created_at'    => $user['createdAt'] ?? null,
                'last_login'    => $user['lastLoginAt'] ?? null,
            ],
        ];

        // 📝 Activity log (last 1000 entries)
        $activities = Database::fetchAll(
            "SELECT activityType, activityResult, activityDetails, ipAddress, createdAt
             FROM tblActivityLog WHERE userID = ? ORDER BY createdAt DESC LIMIT 1000",
            [$userID],
            'i'
        );
        $data['activity_log'] = $activities ?: [];

        // 🔐 MFA methods (no secrets)
        $mfaMethods = Database::fetchAll(
            "SELECT mfaType, isEnabled, isPrimary, lastUsedAt, createdAt
             FROM tblUserMFA WHERE userID = ?",
            [$userID],
            'i'
        );
        $data['mfa_methods'] = $mfaMethods ?: [];

        // 🔑 WebAuthn credentials (no private keys)
        $passkeys = Database::fetchAll(
            "SELECT credentialType, deviceName, deviceType, lastUsedAt, createdAt
             FROM tblWebAuthnCredentials WHERE userID = ?",
            [$userID],
            'i'
        );
        $data['passkeys'] = $passkeys ?: [];

        // 🔗 Linked OAuth accounts
        $linkedAccounts = Database::fetchAll(
            "SELECT provider, providerEmail, providerDisplayName, linkedAt
             FROM tblOAuthAccounts WHERE userID = ?",
            [$userID],
            'i'
        );
        $data['linked_accounts'] = $linkedAccounts ?: [];

        // 🖥️ Sessions (last 50)
        $sessions = Database::fetchAll(
            "SELECT deviceType, deviceName, browserName, osName, ipAddress, createdAt, expiresAt
             FROM tblUserSessions WHERE userID = ? ORDER BY createdAt DESC LIMIT 50",
            [$userID],
            'i'
        );
        $data['sessions'] = $sessions ?: [];

        // 💳 Payments
        $payments = Database::fetchAll(
            "SELECT paymentMethod, amount, currency, status, description, paidAt, createdAt
             FROM tblPayments WHERE userID = ? ORDER BY createdAt DESC",
            [$userID],
            'i'
        );
        $data['payments'] = $payments ?: [];

        // ⚙️ Preferences
        $preferences = Database::fetchAll(
            "SELECT preferenceKey, preferenceValue, updatedAt
             FROM tblUserPreferences WHERE userID = ?",
            [$userID],
            'i'
        );
        $data['preferences'] = $preferences ?: [];

        // 🖼️ Avatars
        $avatars = Database::fetchAll(
            "SELECT avatarType, avatarURL, mimeType, isActive, createdAt
             FROM tblUserAvatars WHERE userID = ?",
            [$userID],
            'i'
        );
        $data['avatars'] = $avatars ?: [];

        return $data;
    }

    /**
     * 📥 Get Export Download Info
     *
     * Retrieves export file info for download by token.
     *
     * @param string $token The download token
     * @return array|null Export info or null if not found/expired
     */
    public static function getExportByToken(string $token): ?array
    {
        if (empty($token)) {
            return null;
        }

        return Database::fetchOne(
            "SELECT * FROM tblDataExportRequests
             WHERE downloadToken = ? AND status IN ('completed', 'downloaded')
             AND expiresAt > NOW()",
            [$token],
            's'
        );
    }

    /**
     * 📥 Download Export File
     *
     * Streams the export file to the browser and updates download stats.
     *
     * @param int $exportID The export request ID
     * @return void (streams file and exits)
     */
    public static function serveExportFile(int $exportID): void
    {
        $export = Database::fetchOne(
            "SELECT * FROM tblDataExportRequests WHERE exportID = ? AND expiresAt > NOW()",
            [$exportID],
            'i'
        );

        if (!$export || empty($export['filePath'])) {
            http_response_code(404);
            echo 'Export not found or expired.';
            exit;
        }

        $exportDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_private'
            . DIRECTORY_SEPARATOR . 'exports';
        $filePath = $exportDir . DIRECTORY_SEPARATOR . $export['filePath'];

        if (!file_exists($filePath)) {
            http_response_code(404);
            echo 'Export file not found.';
            exit;
        }

        // ✅ Verify file integrity
        if (!empty($export['fileHash'])) {
            $currentHash = hash_file('sha256', $filePath);
            if ($currentHash !== $export['fileHash']) {
                http_response_code(500);
                echo 'Export file integrity check failed.';
                exit;
            }
        }

        // 📊 Update download stats
        Database::query(
            "UPDATE tblDataExportRequests
             SET downloadCount = downloadCount + 1, downloadedAt = NOW(), status = 'downloaded'
             WHERE exportID = ?",
            [$exportID],
            'i'
        );

        // 📤 Stream the file
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="signula-data-export.json"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($filePath);
        exit;
    }

    /**
     * 🧹 Cleanup Expired Exports
     *
     * Deletes export files and records that have passed their expiry date.
     *
     * @return int Number of exports cleaned up
     */
    public static function cleanupExpiredExports(): int
    {
        try {
            $expired = Database::fetchAll(
                "SELECT exportID, filePath FROM tblDataExportRequests WHERE expiresAt < NOW()",
                [],
                ''
            );

            $count = 0;
            $exportDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_private'
                . DIRECTORY_SEPARATOR . 'exports';

            foreach ($expired as $export) {
                // 🗑️ Delete file
                if (!empty($export['filePath'])) {
                    $filePath = $exportDir . DIRECTORY_SEPARATOR . $export['filePath'];
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }

                // 🗑️ Update record status
                Database::query(
                    "UPDATE tblDataExportRequests SET status = 'expired', filePath = NULL WHERE exportID = ?",
                    [$export['exportID']],
                    'i'
                );
                $count++;
            }

            return $count;

        } catch (Exception $e) {
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            }
            return 0;
        }
    }
}
