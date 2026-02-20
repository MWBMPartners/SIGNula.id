-- ============================================================================
-- 🛡️ Migration 015: Local Form Protection Settings
-- ============================================================================
--
-- Adds settings for the FormProtection class (honeypot, timing validation,
-- JavaScript challenge). These are local-only bot protections that work
-- independently of external CAPTCHA APIs.
--
-- @version    2.6.0-beta
-- @date       2026-02-20
-- @see        web/private_html/security/FormProtection.php
-- ============================================================================

-- 📋 Pre-flight check: Ensure tblSettings exists
SELECT IF(
    EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tblSettings'),
    'OK: tblSettings exists',
    'ERROR: tblSettings does not exist — run earlier migrations first'
) AS migration_check;


-- ============================================================================
-- ⚙️ SETTINGS: Local Form Protection Configuration
-- ============================================================================

-- 🛡️ These protections provide defense-in-depth alongside CAPTCHA:
--    - Honeypot field: invisible field that only bots fill in
--    - Timing validation: rejects forms submitted faster than threshold
--    - JavaScript challenge: hidden field computed by JS on page load
--
-- All three are local-only (zero external API calls) and always active
-- when enabled, regardless of CAPTCHA API availability.

INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `description`)
VALUES
    ('security.form_protection.enabled', '1', 'boolean', 'security', FALSE,
        'Enable local form protection (honeypot, timing validation, JS challenge). Works independently of CAPTCHA — provides bot protection even when CAPTCHA APIs are unavailable.'),
    ('security.form_protection.min_submit_time', '3', 'integer', 'security', FALSE,
        'Minimum seconds between form page load and form submission. Submissions faster than this threshold are rejected as automated. Default: 3 seconds.')
ON DUPLICATE KEY UPDATE `settingKey` = VALUES(`settingKey`);


-- ============================================================================
-- ✅ Migration Complete
-- ============================================================================

SELECT '✅ Migration 015_form_protection_settings.sql completed successfully' AS status;
