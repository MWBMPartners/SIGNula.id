-- ============================================================================
-- 🔐 Migration 027: Credential Reset Composite Indexes
-- ============================================================================
-- Version: 2.7.0-beta
-- Date: 2026-06-30
-- Description: Adds the missing COMPOSITE indexes on the credential-reset
--              tables created by migration 017. Migration 017 only added
--              single-column indexes, which forces full-table / filesort
--              scans for the multi-column WHERE + ORDER BY patterns the
--              CredentialResetService hot paths actually run (batch
--              processing, status reporting, compliance reporting).
--
-- 🛡️ Security/robustness rationale (issue #25):
--   • CredentialResetService::processNextBatch() runs, per batch:
--         WHERE cru.resetID = ? AND cru.processedAt IS NULL ORDER BY cru.id
--     With only idx_cru_user_id / idx_cru_email_sent / idx_cru_password_changed
--     and the UNIQUE (resetID, userID), MySQL can't satisfy both the
--     (resetID, processedAt) filter AND the ORDER BY id without a filesort,
--     so a large reset re-scans growing slices of the table on every poll.
--   • getResetStatus() / getComplianceReport() aggregate over
--         WHERE resetID = ?  (counting passwordChangedAt / emailSent / processedAt)
--   • getNonCompliantUsers() runs
--         WHERE resetID = ? AND passwordChangedAt IS NULL AND processedAt IS NOT NULL
--         ORDER BY processedAt
--   • listResetOperations() / the rate-limit guard in initiateMassReset()
--         WHERE status IN ('pending','in_progress')  +  ORDER BY createdAt DESC
--
-- ✅ This migration is purely ADDITIVE and NON-DESTRUCTIVE:
--      - It only CREATEs indexes (no data change, no column change, no drop).
--      - Each index uses ADD INDEX IF NOT EXISTS, matching the project's
--        established idiom (see 001_email_system_upgrade.sql) so the migration
--        is idempotent and safe to re-run.
--      - The upgrade wizard (web/public_html/install/upgrade.php) records this
--        file in tblMigrations itself (migrationName + status='completed') after
--        a clean run, so this file deliberately does NOT write its own
--        tblMigrations bookkeeping row.
--
-- @see _database/migrations/017_credential_reset_system.sql (table definitions)
-- @see web/private_html/admin/CredentialResetService.php       (the hot queries)
-- @see https://dev.mysql.com/doc/refman/8.0/en/multiple-column-indexes.html
-- @see https://mariadb.com/kb/en/alter-table/#add-index
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1️⃣ tblCredentialResetUsers — the batch-processing & reporting workhorse
-- ----------------------------------------------------------------------------
-- 🔄 Covers processNextBatch():
--      WHERE resetID = ? AND processedAt IS NULL ORDER BY id ASC LIMIT ?
--    The leading (resetID, processedAt) resolves the filter; the trailing `id`
--    lets InnoDB walk the index in PK order and avoids a filesort.
-- 📊 Also covers getNonCompliantUsers() / getComplianceReport(), which scope
--    by resetID and then test processedAt / passwordChangedAt.
ALTER TABLE `tblCredentialResetUsers`
    ADD INDEX IF NOT EXISTS `idx_cru_reset_processed_id`
        (`resetID`, `processedAt`, `id`),
    ADD INDEX IF NOT EXISTS `idx_cru_reset_password_changed`
        (`resetID`, `passwordChangedAt`),
    ADD INDEX IF NOT EXISTS `idx_cru_reset_email_sent`
        (`resetID`, `emailSent`);

-- ----------------------------------------------------------------------------
-- 2️⃣ tblCredentialResets — list view & concurrent-operation guard
-- ----------------------------------------------------------------------------
-- 🚦 Covers the rate-limit guard in initiateMassReset():
--      WHERE status IN ('pending','in_progress') LIMIT 1
--    and the listResetOperations() ordering (ORDER BY createdAt DESC), letting
--    a status-scoped lookup return most-recent-first without a separate sort.
ALTER TABLE `tblCredentialResets`
    ADD INDEX IF NOT EXISTS `idx_cr_status_created`
        (`status`, `createdAt`);

-- ----------------------------------------------------------------------------
-- 3️⃣ tblSaltRotationHistory — history listing
-- ----------------------------------------------------------------------------
-- 📜 Covers getSaltRotationHistory():
--      ORDER BY createdAt DESC LIMIT ?
--    scoped by the rotating admin where relevant; the composite supports
--    "this admin's rotations, newest first" without a filesort.
ALTER TABLE `tblSaltRotationHistory`
    ADD INDEX IF NOT EXISTS `idx_srh_rotated_by_created`
        (`rotatedBy`, `createdAt`);

-- ============================================================================
-- ✅ MIGRATION COMPLETE
-- ============================================================================
-- Post-migration verification (run manually if desired):
--   SHOW INDEX FROM tblCredentialResetUsers WHERE Key_name = 'idx_cru_reset_processed_id';
--   SHOW INDEX FROM tblCredentialResets     WHERE Key_name = 'idx_cr_status_created';
--   SHOW INDEX FROM tblSaltRotationHistory  WHERE Key_name = 'idx_srh_rotated_by_created';
-- ============================================================================
