-- ============================================================================
-- SIGNula - Migration 023: Internationalisation (i18n) Settings
-- ============================================================================
--
-- Purpose: Add i18n/localisation settings to the tblSettings table.
--          These settings control the default locale, fallback locale,
--          available locales, and whether to auto-detect browser locale.
--
-- Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
-- ============================================================================

-- 🌐 i18n Settings
-- These settings configure the internationalisation framework for the SIGNula system.
-- Settings are loaded by the I18n class during initialisation.
-- @see web/private_html/i18n/I18n.php

INSERT INTO tblSettings (settingKey, settingValue, settingType, category, description, isPublic) VALUES
('i18n.default_locale', 'en', 'string', 'i18n', 'Default application locale', 0),
('i18n.fallback_locale', 'en', 'string', 'i18n', 'Fallback locale when translation missing', 0),
('i18n.available_locales', 'en', 'string', 'i18n', 'Comma-separated list of available locales', 0),
('i18n.detect_browser_locale', '1', 'boolean', 'i18n', 'Auto-detect locale from browser Accept-Language header', 0)
ON DUPLICATE KEY UPDATE settingKey = settingKey;
