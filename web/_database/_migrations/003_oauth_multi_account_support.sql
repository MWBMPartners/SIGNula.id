-- ============================================================================
-- 🔄 Migration 003: OAuth Multi-Account Support
-- ============================================================================
--
-- This migration adds support for linking multiple OAuth accounts from the
-- same provider to a single SIGNula account.
--
-- Changes:
-- 1. Add unique constraint on (provider, providerUserID) to prevent the same
--    external account from being linked to multiple SIGNula accounts
-- 2. Add accountType field to distinguish personal/work/school accounts
-- 3. Add emailDomain field for domain-based filtering by third-party services
--
-- @version    1.0.0
-- @date       2026-02-03
-- ============================================================================

USE signula;

-- ============================================================================
-- 🔧 Add new fields to tblOAuthAccounts
-- ============================================================================

-- Add accountType field (personal, work, school, etc.)
ALTER TABLE tblOAuthAccounts
ADD COLUMN accountType VARCHAR(20) DEFAULT 'personal' AFTER displayName,
ADD INDEX idx_accountType (accountType);

-- Add emailDomain field for domain-based filtering
ALTER TABLE tblOAuthAccounts
ADD COLUMN emailDomain VARCHAR(255) NULL AFTER accountType,
ADD INDEX idx_emailDomain (emailDomain);

-- ============================================================================
-- 🔒 Add unique constraint to prevent duplicate external accounts
-- ============================================================================

-- This ensures the same external account (e.g., john@gmail.com from Google)
-- cannot be linked to multiple SIGNula accounts
ALTER TABLE tblOAuthAccounts
ADD CONSTRAINT uq_provider_external_account UNIQUE (provider, providerUserID);

-- ============================================================================
-- 📊 Update existing records with default values
-- ============================================================================

-- Set accountType based on email domain for existing records
UPDATE tblOAuthAccounts
SET accountType = CASE
    WHEN email LIKE '%@gmail.com' OR email LIKE '%@yahoo.com' OR email LIKE '%@outlook.com'
         OR email LIKE '%@hotmail.com' OR email LIKE '%@live.com' OR email LIKE '%@icloud.com'
         OR email LIKE '%@me.com' THEN 'personal'
    WHEN email LIKE '%@%.edu' OR email LIKE '%@%.ac.%' THEN 'school'
    ELSE 'work'
END
WHERE accountType = 'personal';

-- Extract and set emailDomain for existing records
UPDATE tblOAuthAccounts
SET emailDomain = SUBSTRING_INDEX(email, '@', -1)
WHERE emailDomain IS NULL AND email IS NOT NULL;

-- ============================================================================
-- ✅ Migration complete
-- ============================================================================

-- Add migration record
INSERT INTO tblMigrations (migrationName, migrationDescription, appliedAt, appliedBy, status)
VALUES (
    '003_oauth_multi_account_support',
    'Add support for multiple OAuth accounts per provider with unique constraint and account type tracking',
    NOW(),
    'system',
    'completed'
);

-- Log activity
INSERT INTO tblActivityLog (userID, activityType, activityResult, activityDetails, ipAddress, userAgent, createdAt)
VALUES (
    NULL,
    'migration_applied',
    'success',
    'Applied migration 003_oauth_multi_account_support: Added accountType, emailDomain fields and unique constraint',
    '127.0.0.1',
    'Database Migration System',
    NOW()
);

-- ============================================================================
-- 📝 Rollback script (for reference)
-- ============================================================================
-- To rollback this migration, run:
--
-- ALTER TABLE tblOAuthAccounts DROP CONSTRAINT uq_provider_external_account;
-- ALTER TABLE tblOAuthAccounts DROP INDEX idx_emailDomain;
-- ALTER TABLE tblOAuthAccounts DROP COLUMN emailDomain;
-- ALTER TABLE tblOAuthAccounts DROP INDEX idx_accountType;
-- ALTER TABLE tblOAuthAccounts DROP COLUMN accountType;
-- DELETE FROM tblMigrations WHERE migrationName = '003_oauth_multi_account_support';
-- ============================================================================
