-- ============================================================================
-- 🖼️ Migration 016: Avatar/Profile Picture System
-- ============================================================================
-- Version: 2.6.0-beta
-- Date: 2026-02-20
-- Description: Adds avatar management support including:
--   - tblUserAvatars table for per-partner/global avatar storage
--   - 11 new settings for avatar configuration
--   - Support for uploaded, OAuth-sourced, and external URL avatars
--   - Multiple fallback avatar services (Gravatar, Libravatar, UI Avatars, etc.)
-- ============================================================================
-- Prerequisites:
--   - tblUsers table must exist (core schema)
--   - tblOAuthAccounts table must exist (migration 003+)
--   - tblSettings table must exist (core schema)
--   - tblUserPreferences table must exist (core schema)
-- ============================================================================

-- ============================================================================
-- 📋 Table: tblUserAvatars
-- ============================================================================
-- Stores avatar/profile picture information for each user, supporting both
-- global (SIGNula-wide) avatars and per-partner/service-specific avatars.
--
-- Priority chain for avatar resolution:
--   1. Partner-specific avatar (partnerID IS NOT NULL, isActive = TRUE)
--   2. Global SIGNula avatar (partnerID IS NULL, isActive = TRUE)
--   3. Primary OAuth account avatar (from tblOAuthAccounts.profilePicture)
--   4. Fallback services (Gravatar, Libravatar, UI Avatars, DiceBear, RoboHash)
--   5. Generated initials avatar (SVG, deterministic colour)
-- ============================================================================

CREATE TABLE IF NOT EXISTS `tblUserAvatars` (
    -- 🔑 Primary Key
    `avatarID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
        COMMENT 'Unique avatar record identifier',

    -- 👤 User Reference
    `userID` BIGINT UNSIGNED NOT NULL
        COMMENT 'User who owns this avatar (FK → tblUsers.userID)',

    -- 🤝 Partner/Service Context
    -- NULL = global SIGNula avatar (used across all services)
    -- Non-NULL = avatar specific to a particular partner/service
    `partnerID` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Partner/service this avatar is for. NULL = global SIGNula avatar',

    -- 🖼️ Avatar Source Type
    `avatarType` ENUM('upload', 'oauth', 'url') NOT NULL DEFAULT 'upload'
        COMMENT 'Source type: upload = user-uploaded file, oauth = from OAuth provider, url = custom external URL',

    -- 📁 Upload Storage (used when avatarType = upload)
    -- Stores relative path from the avatar upload directory
    -- e.g., "42/avatar_a1b2c3d4e5f6.webp"
    `avatarPath` VARCHAR(500) NULL DEFAULT NULL
        COMMENT 'Relative file path for uploaded avatars (from _private/uploads/avatars/)',

    -- 🌐 External URL (used when avatarType = oauth or url)
    `avatarURL` VARCHAR(2048) NULL DEFAULT NULL
        COMMENT 'External URL for OAuth provider or custom avatar URLs',

    -- 🔗 OAuth Account Reference (used when avatarType = oauth)
    `oauthAccountID` BIGINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Source OAuth account (FK → tblOAuthAccounts.oauthAccountID)',

    -- 📐 Image Metadata
    `mimeType` VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'MIME type of the avatar image (e.g., image/webp, image/jpeg)',

    `fileSize` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'File size in bytes (for uploaded avatars)',

    `width` SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Image width in pixels',

    `height` SMALLINT UNSIGNED NULL DEFAULT NULL
        COMMENT 'Image height in pixels',

    -- ✅ Status
    `isActive` BOOLEAN NOT NULL DEFAULT TRUE
        COMMENT 'Whether this avatar is currently active/in-use',

    -- 🕐 Timestamps
    `createdAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When this avatar record was created',

    `updatedAt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        COMMENT 'When this avatar record was last modified',

    -- 🔗 Foreign Keys
    CONSTRAINT `fk_useravatars_userid`
        FOREIGN KEY (`userID`) REFERENCES `tblUsers`(`userID`)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT `fk_useravatars_oauthaccountid`
        FOREIGN KEY (`oauthAccountID`) REFERENCES `tblOAuthAccounts`(`oauthAccountID`)
        ON DELETE SET NULL ON UPDATE CASCADE,

    -- 🔒 Unique Constraint: One active avatar per user per partner context
    -- NULL partnerID = global avatar, non-NULL = partner-specific
    -- Note: MySQL treats NULL as distinct in UNIQUE constraints, so multiple
    -- users can have NULL partnerID entries (which is correct behaviour)
    UNIQUE KEY `uq_user_partner_avatar` (`userID`, `partnerID`),

    -- 📊 Indexes for common queries
    INDEX `idx_useravatars_userid` (`userID`),
    INDEX `idx_useravatars_partnerid` (`partnerID`),
    INDEX `idx_useravatars_isactive` (`isActive`),
    INDEX `idx_useravatars_type` (`avatarType`)

) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='User avatar/profile picture storage with per-partner support';


-- ============================================================================
-- ⚙️ Avatar Settings (tblSettings)
-- ============================================================================
-- These settings control avatar upload limits, processing options, and
-- fallback service configuration. All are editable via the admin UI.
-- ============================================================================

-- 📏 Upload Limits
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `isEditable`, `description`, `defaultValue`)
VALUES
    -- 🖼️ Maximum file size for avatar uploads (in bytes)
    -- Default: 2,097,152 bytes = 2MB
    -- Recommended range: 1MB-5MB depending on hosting storage
    ('avatar.max_file_size', '2097152', 'integer', 'avatar', FALSE, TRUE,
     'Maximum avatar upload file size in bytes. Default: 2097152 (2MB). Set to 0 to disallow uploads entirely.',
     '2097152'),

    -- 📋 Allowed MIME types for avatar uploads
    -- JSON array of accepted image MIME types
    -- Supported by PHP GD: JPEG, PNG, GIF, WebP (PHP 7.0+), AVIF (PHP 8.1+)
    ('avatar.allowed_types', '["image/jpeg","image/png","image/gif","image/webp"]', 'json', 'avatar', FALSE, TRUE,
     'JSON array of allowed MIME types for avatar uploads. Must be types supported by PHP GD library.',
     '["image/jpeg","image/png","image/gif","image/webp"]'),

    -- 📐 Maximum image dimensions (width or height)
    -- Images larger than this in either dimension are rejected before processing
    -- Prevents excessive memory usage in GD during resize
    ('avatar.max_dimensions', '2048', 'integer', 'avatar', FALSE, TRUE,
     'Maximum allowed width or height in pixels for uploaded avatar images. Prevents excessive memory use during processing.',
     '2048'),

    -- 🎯 Output size (square, in pixels)
    -- The largest size the avatar will be stored at
    -- Smaller sizes (32, 48, 64, 96, 128) are generated automatically
    ('avatar.output_size', '256', 'integer', 'avatar', FALSE, TRUE,
     'Output avatar size in pixels (square). Multiple smaller sizes are auto-generated for responsive display.',
     '256'),

    -- 🎨 Output image format
    -- webp: Best compression, supported in all modern browsers
    -- jpeg: Universal support, larger files
    -- png: Supports transparency, largest files
    ('avatar.output_format', 'webp', 'string', 'avatar', FALSE, TRUE,
     'Output format for processed avatars: webp (recommended, best compression), jpeg, or png.',
     'webp'),

    -- 💎 Output image quality (1-100)
    -- Higher = better quality but larger file size
    -- 85 is a good balance for avatar-sized images
    ('avatar.output_quality', '85', 'integer', 'avatar', FALSE, TRUE,
     'Image quality for processed avatars (1-100). 85 is recommended for good quality with reasonable file size.',
     '85')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;

-- 🔄 Fallback Service Configuration
INSERT INTO `tblSettings` (`settingKey`, `settingValue`, `settingType`, `settingCategory`, `isSensitive`, `isEditable`, `description`, `defaultValue`)
VALUES
    -- 🌐 System default fallback service priority order
    -- Users can override this per-account via tblUserPreferences
    -- Available services: gravatar, libravatar, ui_avatars, dicebear, robohash
    -- See: https://gravatar.com, https://www.libravatar.org, https://ui-avatars.com,
    --      https://www.dicebear.com, https://robohash.org
    ('avatar.fallback_services', '["gravatar","ui_avatars"]', 'json', 'avatar', FALSE, TRUE,
     'JSON array of fallback avatar services in priority order. Available: gravatar, libravatar, ui_avatars, dicebear, robohash. Users can customise their own order.',
     '["gravatar","ui_avatars"]'),

    -- 👤 Gravatar default image style
    -- Used when a Gravatar account does not exist for the email
    -- Options: mp (mystery person), identicon, monsterid, wavatar, retro, robohash, blank
    -- See: https://docs.gravatar.com/general/images/
    ('avatar.gravatar.default', 'mp', 'string', 'avatar', FALSE, TRUE,
     'Gravatar default image style when no Gravatar exists. Options: mp (mystery person), identicon, monsterid, wavatar, retro, robohash, blank.',
     'mp'),

    -- ⏱️ Cache TTL for external avatar URLs (in seconds)
    -- How long to cache the resolved external avatar URL before re-checking
    -- 3600 = 1 hour (good balance between freshness and performance)
    ('avatar.cache_external_ttl', '3600', 'integer', 'avatar', FALSE, TRUE,
     'Cache time-to-live in seconds for external avatar service URLs. 3600 (1 hour) recommended.',
     '3600'),

    -- ✅ Enable/disable file upload functionality
    -- Set to 0 to only allow OAuth and fallback service avatars
    ('avatar.enable_upload', '1', 'boolean', 'avatar', FALSE, TRUE,
     'Enable avatar file uploads. Set to 0 to only allow OAuth and fallback service avatars.',
     '1'),

    -- 🔒 Proxy external avatars through server
    -- When enabled, external avatar URLs (Gravatar, etc.) are fetched server-side
    -- and served through the /avatar/ endpoint. Adds privacy but increases server load.
    ('avatar.proxy_external', '0', 'boolean', 'avatar', FALSE, TRUE,
     'Proxy external avatar URLs through server for privacy. Increases server load. Default: disabled (direct external URLs).',
     '0')
ON DUPLICATE KEY UPDATE `settingKey` = `settingKey`;


-- ============================================================================
-- 📝 Record Migration
-- ============================================================================

-- [mig-fix] removed incompatible tblMigrations self-bookkeeping (the migration runner records migrations)


-- ============================================================================
-- ✅ Migration Complete
-- ============================================================================
-- New table: tblUserAvatars (per-partner avatar storage with upload/oauth/url types)
-- New settings: 11 entries in tblSettings (category: avatar)
--
-- Next steps:
--   1. Deploy AvatarService.php to web/private_html/utils/
--   2. Create avatar serve endpoint at web/public_html/avatar/
--   3. Create upload directory: web/_private/uploads/avatars/
--   4. Update profile settings page with avatar management UI
-- ============================================================================
