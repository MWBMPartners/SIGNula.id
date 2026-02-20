<?php
/**
 * ============================================================================
 * 🖼️ SIGNula - Avatar Service
 * ============================================================================
 *
 * Purpose: Manage user avatar/profile pictures with priority-based resolution,
 *          file upload processing, and multiple fallback service support.
 * PHP Version: 8.3+
 *
 * Priority chain:
 *   1. Partner-specific uploaded avatar (per-service customisation)
 *   2. Global SIGNula uploaded avatar (user's main profile picture)
 *   3. Primary OAuth account avatar (Google, Microsoft, Facebook, etc.)
 *   4. Fallback services (Gravatar, Libravatar, UI Avatars, DiceBear, RoboHash)
 *   5. Generated initials avatar (SVG, deterministic colour)
 *
 * @package    SIGNula
 * @subpackage Utils
 * @version    1.0.0
 * @see        https://gravatar.com - Gravatar service
 * @see        https://www.libravatar.org - Federated avatar service
 * @see        https://ui-avatars.com - Initial-based avatar generation
 * @see        https://www.dicebear.com - Avatar style generation
 * @see        https://robohash.org - Robot avatar generation
 * ============================================================================
 */

// 🚫 Prevent direct access
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🖼️ Avatar Service Class
 *
 * Provides avatar resolution, upload processing, and fallback service URL generation.
 * All methods are static, following the SIGNula utility class convention.
 *
 * Usage:
 *   // 🎯 Get avatar URL for a user (follows priority chain automatically)
 *   $avatarUrl = AvatarService::resolve($userID, null, 96);
 *
 *   // 📤 Upload a new avatar
 *   $result = AvatarService::uploadAvatar($userID, $_FILES['avatar']);
 *
 *   // 🔄 Set an OAuth provider's picture as the user's avatar
 *   AvatarService::setOAuthAsAvatar($userID, $oauthAccountID);
 *
 *   // ⚙️ Get/set user's fallback priority order
 *   $priority = AvatarService::getUserFallbackPriority($userID);
 *   AvatarService::setUserFallbackPriority($userID, ['gravatar', 'dicebear']);
 */
class AvatarService
{
    // ============================================================================
    // 📌 Constants
    // ============================================================================

    /**
     * 📁 Upload directory relative to web root
     * Files stored outside web-accessible directories for security
     * @see https://owasp.org/www-community/vulnerabilities/Unrestricted_File_Upload
     */
    private const UPLOAD_DIR = '_private' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';

    /**
     * 📐 Pre-generated avatar sizes (in pixels)
     * Multiple sizes generated on upload for responsive display
     */
    private const THUMB_SIZES = [32, 48, 64, 96, 128, 256];

    /**
     * 🌐 Supported fallback avatar services
     * Maps service identifiers to their URL generator methods
     */
    private const FALLBACK_SERVICES = [
        'gravatar'   => 'getGravatarUrl',
        'libravatar' => 'getLibravatarUrl',
        'ui_avatars' => 'getUIAvatarsUrl',
        'dicebear'   => 'getDiceBearUrl',
        'robohash'   => 'getRoboHashUrl',
    ];

    /**
     * 🎨 Background colours for generated initials avatars
     * Deterministic colour selection based on userID hash
     */
    private const INITIALS_COLOURS = [
        '#1abc9c', '#2ecc71', '#3498db', '#9b59b6', '#34495e',
        '#16a085', '#27ae60', '#2980b9', '#8e44ad', '#2c3e50',
        '#f1c40f', '#e67e22', '#e74c3c', '#f39c12', '#d35400',
        '#c0392b', '#7f8c8d', '#95a5a6', '#6c5ce7', '#00b894',
    ];

    /**
     * 💾 In-request cache to avoid repeated DB queries on same page load
     * Keyed by "userID:partnerID:size"
     */
    private static array $resolveCache = [];

    // ============================================================================
    // 🎯 Core Resolution
    // ============================================================================

    /**
     * 🎯 Resolve avatar URL for a user with full priority chain
     *
     * This is the main entry point for avatar display. It checks each source
     * in priority order and returns the first available avatar URL.
     *
     * Priority:
     *   1. Partner-specific avatar (if $partnerID provided)
     *   2. Global SIGNula avatar (uploaded or set by user)
     *   3. Primary OAuth account avatar (Google, Microsoft, etc.)
     *   4. Fallback services (in user's custom or system default order)
     *   5. Generated SVG initials avatar (always available)
     *
     * @param int      $userID    The user's ID
     * @param int|null $partnerID Partner/service ID for partner-specific avatar (NULL = global)
     * @param int      $size      Desired avatar size in pixels (default 96)
     * @return string             Avatar URL (absolute URL or data URI)
     */
    public static function resolve(int $userID, ?int $partnerID = null, int $size = 96): string
    {
        // 💾 Check in-request cache first
        $cacheKey = $userID . ':' . ($partnerID ?? 'null') . ':' . $size;
        if (isset(self::$resolveCache[$cacheKey])) {
            return self::$resolveCache[$cacheKey];
        }

        // 🔍 Fetch user data for fallback info (email, displayName, username)
        $user = self::getUserData($userID);
        if ($user === null) {
            // ❌ User not found — return generic initials
            return self::generateInitialsAvatar('?', $size);
        }

        // 📋 Step 1: Check partner-specific avatar (if partner context)
        if ($partnerID !== null) {
            $partnerAvatar = self::getStoredAvatar($userID, $partnerID, $size);
            if ($partnerAvatar !== null) {
                self::$resolveCache[$cacheKey] = $partnerAvatar;
                return $partnerAvatar;
            }
        }

        // 📋 Step 2: Check global SIGNula avatar
        $globalAvatar = self::getStoredAvatar($userID, null, $size);
        if ($globalAvatar !== null) {
            self::$resolveCache[$cacheKey] = $globalAvatar;
            return $globalAvatar;
        }

        // 📋 Step 3: Check primary OAuth account avatar
        $oauthAvatar = self::getOAuthAvatar($userID);
        if ($oauthAvatar !== null) {
            self::$resolveCache[$cacheKey] = $oauthAvatar;
            return $oauthAvatar;
        }

        // 📋 Step 4: Try fallback services in priority order
        $email = $user['email'] ?? '';
        $displayName = $user['displayName'] ?? $user['username'] ?? 'User';

        $fallbackUrl = self::getFallbackAvatar($userID, $email, $displayName, $size);
        if ($fallbackUrl !== null) {
            self::$resolveCache[$cacheKey] = $fallbackUrl;
            return $fallbackUrl;
        }

        // 📋 Step 5: Ultimate fallback — generated initials avatar
        $initialsUrl = self::generateInitialsAvatar($displayName, $size);
        self::$resolveCache[$cacheKey] = $initialsUrl;
        return $initialsUrl;
    }

    /**
     * 🗄️ Get stored avatar from tblUserAvatars
     *
     * Checks the database for an active avatar record (uploaded, oauth, or url type).
     *
     * @param int      $userID    User ID
     * @param int|null $partnerID Partner ID (NULL for global)
     * @param int      $size      Desired size in pixels
     * @return string|null        Avatar URL or null if none found
     */
    private static function getStoredAvatar(int $userID, ?int $partnerID, int $size): ?string
    {
        // 🔍 Query for active avatar record
        // Using IS NULL / = ? pattern for nullable partnerID
        if ($partnerID === null) {
            $sql = "SELECT avatarID, avatarType, avatarPath, avatarURL
                    FROM tblUserAvatars
                    WHERE userID = ? AND partnerID IS NULL AND isActive = TRUE
                    LIMIT 1";
            $row = Database::fetchOne($sql, [$userID], 'i');
        } else {
            $sql = "SELECT avatarID, avatarType, avatarPath, avatarURL
                    FROM tblUserAvatars
                    WHERE userID = ? AND partnerID = ? AND isActive = TRUE
                    LIMIT 1";
            $row = Database::fetchOne($sql, [$userID, $partnerID], 'ii');
        }

        if ($row === null) {
            return null;
        }

        // 🖼️ Return URL based on avatar type
        switch ($row['avatarType']) {
            case 'upload':
                // 📁 Local file — return serve endpoint URL
                if (!empty($row['avatarPath'])) {
                    return self::getServeUrl($userID, $size);
                }
                return null;

            case 'oauth':
            case 'url':
                // 🌐 External URL — return directly
                return !empty($row['avatarURL']) ? $row['avatarURL'] : null;

            default:
                return null;
        }
    }

    /**
     * 🔗 Get avatar URL from the user's primary OAuth account
     *
     * Checks tblOAuthAccounts for the user's primary linked account
     * and returns its profile picture URL if available.
     *
     * @param int $userID User ID
     * @return string|null OAuth avatar URL or null
     */
    public static function getOAuthAvatar(int $userID): ?string
    {
        // 🔍 Query primary OAuth account for profile picture
        $sql = "SELECT profilePicture
                FROM tblOAuthAccounts
                WHERE userID = ? AND isPrimary = TRUE AND profilePicture IS NOT NULL AND profilePicture != ''
                LIMIT 1";

        $row = Database::fetchOne($sql, [$userID], 'i');

        return $row['profilePicture'] ?? null;
    }

    /**
     * 🔄 Get avatar URL from fallback services
     *
     * Iterates through the user's preferred fallback service order (or system default)
     * and returns the first available avatar URL.
     *
     * @param int    $userID      User ID (for preference lookup)
     * @param string $email       User's email (for Gravatar/Libravatar)
     * @param string $displayName User's display name (for initial-based services)
     * @param int    $size        Desired size in pixels
     * @return string|null        Fallback avatar URL or null if all services disabled
     */
    public static function getFallbackAvatar(int $userID, string $email, string $displayName, int $size = 96): ?string
    {
        // 📋 Get service priority order (user custom → system default)
        $services = self::getUserFallbackPriority($userID);

        if (empty($services)) {
            return null;
        }

        // 🔄 Try each service in order
        foreach ($services as $service) {
            $service = strtolower(trim($service));

            if (!isset(self::FALLBACK_SERVICES[$service])) {
                continue; // ⏭️ Skip unknown services
            }

            // 🎯 Generate URL using the appropriate method
            $url = match ($service) {
                'gravatar'   => self::getGravatarUrl($email, $size),
                'libravatar' => self::getLibravatarUrl($email, $size),
                'ui_avatars' => self::getUIAvatarsUrl($displayName, $size),
                'dicebear'   => self::getDiceBearUrl($email, $size),
                'robohash'   => self::getRoboHashUrl($email, $size),
                default      => null,
            };

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    // ============================================================================
    // 🌐 Fallback Service URL Generators
    // ============================================================================

    /**
     * 🟣 Generate Gravatar URL
     *
     * Gravatar uses MD5 hash of lowercase trimmed email.
     *
     * @param string $email   User's email address
     * @param int    $size    Image size in pixels (1-2048)
     * @param string $default Default image style when no Gravatar exists
     *                        Options: mp, identicon, monsterid, wavatar, retro, robohash, blank
     * @return string|null    Gravatar URL or null if email is empty
     * @see https://docs.gravatar.com/general/images/
     */
    public static function getGravatarUrl(string $email, int $size = 96, string $default = ''): ?string
    {
        $email = strtolower(trim($email));
        if (empty($email)) {
            return null;
        }

        // 🔧 Use setting for default style if not explicitly provided
        if (empty($default)) {
            $default = getSetting('avatar.gravatar.default', 'mp');
        }

        // 🔐 MD5 hash of email (Gravatar standard)
        // @see https://docs.gravatar.com/general/hash/
        $hash = md5($email);

        // 📎 Build URL with query parameters
        // s = size, d = default image, r = rating (g = general audiences)
        return 'https://www.gravatar.com/avatar/' . $hash
            . '?s=' . urlencode((string) $size)
            . '&d=' . urlencode($default)
            . '&r=g';
    }

    /**
     * 🟢 Generate Libravatar URL
     *
     * Open-source, federated alternative to Gravatar. Uses the same
     * MD5 hash format but supports SRV DNS records for federation.
     *
     * @param string $email User's email address
     * @param int    $size  Image size in pixels
     * @return string|null  Libravatar URL or null if email is empty
     * @see https://wiki.libravatar.org/api/
     */
    public static function getLibravatarUrl(string $email, int $size = 96): ?string
    {
        $email = strtolower(trim($email));
        if (empty($email)) {
            return null;
        }

        // 🔐 MD5 hash (same format as Gravatar for compatibility)
        $hash = md5($email);

        // 🌐 Use Libravatar CDN (falls back to Gravatar if no Libravatar account)
        // d=mm = mystery man default
        return 'https://seccdn.libravatar.org/avatar/' . $hash
            . '?s=' . urlencode((string) $size)
            . '&d=mm';
    }

    /**
     * 🔵 Generate UI Avatars URL
     *
     * Generates clean initial-based avatars from a name string.
     * No account required — the service generates images on-the-fly.
     *
     * @param string $name User's display name
     * @param int    $size Image size in pixels (16-512)
     * @return string|null UI Avatars URL or null if name is empty
     * @see https://ui-avatars.com/
     */
    public static function getUIAvatarsUrl(string $name, int $size = 96): ?string
    {
        $name = trim($name);
        if (empty($name)) {
            return null;
        }

        // 📎 Build URL with parameters
        // background=random generates a unique colour per name
        // color=fff = white text
        // bold=true = bold initials
        // format=svg = scalable vector (best quality at any size)
        return 'https://ui-avatars.com/api/'
            . '?name=' . urlencode($name)
            . '&size=' . urlencode((string) $size)
            . '&background=random'
            . '&color=fff'
            . '&bold=true'
            . '&format=svg';
    }

    /**
     * 🟡 Generate DiceBear URL
     *
     * Creates unique avatar images from a seed string using various art styles.
     * Uses the 'initials' style by default for a professional look.
     *
     * @param string $seed  Seed string (email or username)
     * @param int    $size  Image size in pixels
     * @param string $style Art style (initials, avataaars, bottts, pixel-art, etc.)
     * @return string|null  DiceBear URL or null if seed is empty
     * @see https://www.dicebear.com/styles/
     */
    public static function getDiceBearUrl(string $seed, int $size = 96, string $style = 'initials'): ?string
    {
        $seed = trim($seed);
        if (empty($seed)) {
            return null;
        }

        // 🎨 DiceBear API v7.x — generates SVG or PNG avatars
        return 'https://api.dicebear.com/7.x/' . urlencode($style) . '/svg'
            . '?seed=' . urlencode($seed)
            . '&size=' . urlencode((string) $size);
    }

    /**
     * 🤖 Generate RoboHash URL
     *
     * Creates unique robot/monster/alien avatars from a hash string.
     * Fun, recognisable avatars — good as a playful fallback option.
     *
     * @param string $seed Seed string (email, username, or any unique string)
     * @param int    $size Image size in pixels
     * @return string|null RoboHash URL or null if seed is empty
     * @see https://robohash.org/
     */
    public static function getRoboHashUrl(string $seed, int $size = 96): ?string
    {
        $seed = trim($seed);
        if (empty($seed)) {
            return null;
        }

        // 🤖 RoboHash generates images based on the URL path
        return 'https://robohash.org/' . urlencode($seed)
            . '?size=' . $size . 'x' . $size;
    }

    // ============================================================================
    // 📤 Upload Processing
    // ============================================================================

    /**
     * 📤 Process and store an uploaded avatar file
     *
     * Performs comprehensive security validation, image processing via GD,
     * and stores multiple sizes for responsive display.
     *
     * Security measures:
     *   1. Check $_FILES error code
     *   2. Validate file extension against whitelist
     *   3. Validate MIME type via finfo_file() (not trusting $_FILES['type'])
     *   4. Check file size against configured maximum
     *   5. Confirm real image via getimagesize()
     *   6. GD load + re-encode (strips EXIF data and any embedded payloads)
     *   7. Random filename generation (prevents URL guessing)
     *
     * @param int      $userID    User ID
     * @param array    $file      $_FILES['avatar'] array
     * @param int|null $partnerID Partner ID (NULL for global avatar)
     * @return array              ['success' => bool, 'path' => ?string, 'error' => ?string]
     * @see https://owasp.org/www-community/vulnerabilities/Unrestricted_File_Upload
     */
    public static function uploadAvatar(int $userID, array $file, ?int $partnerID = null): array
    {
        // ✅ Check if uploads are enabled
        if (!self::isUploadEnabled()) {
            return ['success' => false, 'path' => null, 'error' => 'Avatar uploads are currently disabled.'];
        }

        // 🔍 Step 1: Validate the uploaded file
        $validation = self::validateUploadedFile($file);
        if (!$validation['valid']) {
            return ['success' => false, 'path' => null, 'error' => $validation['error']];
        }

        // 🔍 Step 2: Deep content validation (is it a real image?)
        $tmpPath = $file['tmp_name'];
        if (!self::validateImageContent($tmpPath)) {
            return ['success' => false, 'path' => null, 'error' => 'The uploaded file is not a valid image.'];
        }

        // 📁 Step 3: Prepare upload directory
        $uploadDir = self::getUploadDirectory($userID);
        if ($uploadDir === null) {
            return ['success' => false, 'path' => null, 'error' => 'Failed to create upload directory.'];
        }

        // 🎲 Step 4: Generate random filename
        $baseName = self::generateFileName();

        // 🖼️ Step 5: Process image (resize, re-encode, generate multiple sizes)
        $mimeType = mime_content_type($tmpPath) ?: 'image/jpeg';
        $processResult = self::processImage($tmpPath, $uploadDir, $baseName, $mimeType);
        if (!$processResult['success']) {
            return ['success' => false, 'path' => null, 'error' => $processResult['error']];
        }

        // 🗑️ Step 6: Clean up old avatar files for this user/partner context
        self::cleanOldAvatars($userID, $partnerID);

        // 💾 Step 7: Store record in tblUserAvatars
        $relativePath = $userID . DIRECTORY_SEPARATOR . $baseName;
        $outputFormat = getSetting('avatar.output_format', 'webp');
        $outputSize = (int) getSetting('avatar.output_size', 256);

        // 📊 Get file info for the largest generated size
        $largestFile = $uploadDir . DIRECTORY_SEPARATOR . $baseName . '_' . $outputSize . '.' . $outputFormat;
        $fileSize = file_exists($largestFile) ? filesize($largestFile) : 0;

        // 🗄️ Insert/update avatar record
        if ($partnerID === null) {
            // 🌍 Global avatar — check for existing record
            $existingSql = "SELECT avatarID FROM tblUserAvatars
                           WHERE userID = ? AND partnerID IS NULL
                           LIMIT 1";
            $existing = Database::fetchOne($existingSql, [$userID], 'i');
        } else {
            // 🤝 Partner-specific avatar
            $existingSql = "SELECT avatarID FROM tblUserAvatars
                           WHERE userID = ? AND partnerID = ?
                           LIMIT 1";
            $existing = Database::fetchOne($existingSql, [$userID, $partnerID], 'ii');
        }

        if ($existing !== null) {
            // 📝 Update existing record
            $updateSql = "UPDATE tblUserAvatars
                         SET avatarType = 'upload', avatarPath = ?, avatarURL = NULL,
                             oauthAccountID = NULL, mimeType = ?, fileSize = ?,
                             width = ?, height = ?, isActive = TRUE
                         WHERE avatarID = ?";
            Database::query($updateSql, [
                $relativePath, 'image/' . $outputFormat, $fileSize,
                $outputSize, $outputSize, $existing['avatarID']
            ], 'ssiiis');
        } else {
            // ➕ Insert new record
            if ($partnerID === null) {
                $insertSql = "INSERT INTO tblUserAvatars
                             (userID, partnerID, avatarType, avatarPath, mimeType, fileSize, width, height, isActive)
                             VALUES (?, NULL, 'upload', ?, ?, ?, ?, ?, TRUE)";
                Database::query($insertSql, [
                    $userID, $relativePath, 'image/' . $outputFormat,
                    $fileSize, $outputSize, $outputSize
                ], 'issiii');
            } else {
                $insertSql = "INSERT INTO tblUserAvatars
                             (userID, partnerID, avatarType, avatarPath, mimeType, fileSize, width, height, isActive)
                             VALUES (?, ?, 'upload', ?, ?, ?, ?, ?, TRUE)";
                Database::query($insertSql, [
                    $userID, $partnerID, $relativePath, 'image/' . $outputFormat,
                    $fileSize, $outputSize, $outputSize
                ], 'iissiii');
            }
        }

        // 🔄 Update tblUsers.profilePicture cache field (for global avatar only)
        if ($partnerID === null) {
            self::updateUserProfilePicture($userID, $relativePath);
        }

        // 🧹 Clear resolve cache for this user
        self::clearCache($userID);

        // ✅ Log activity
        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $userID,
                'avatar_uploaded',
                'account',
                'info',
                'User uploaded a new avatar' . ($partnerID !== null ? ' (partner-specific)' : ' (global)'),
                ['partner_id' => $partnerID, 'file_size' => $fileSize, 'format' => $outputFormat]
            );
        }

        return [
            'success' => true,
            'path'    => $relativePath,
            'error'   => null,
        ];
    }

    /**
     * 🗑️ Delete a user's avatar
     *
     * Removes the avatar files from disk and deactivates the database record.
     *
     * @param int      $userID    User ID
     * @param int|null $partnerID Partner ID (NULL for global avatar)
     * @return bool               True if successfully deleted
     */
    public static function deleteAvatar(int $userID, ?int $partnerID = null): bool
    {
        // 🔍 Find the existing avatar record
        if ($partnerID === null) {
            $sql = "SELECT avatarID, avatarPath, avatarType
                    FROM tblUserAvatars
                    WHERE userID = ? AND partnerID IS NULL AND isActive = TRUE
                    LIMIT 1";
            $avatar = Database::fetchOne($sql, [$userID], 'i');
        } else {
            $sql = "SELECT avatarID, avatarPath, avatarType
                    FROM tblUserAvatars
                    WHERE userID = ? AND partnerID = ? AND isActive = TRUE
                    LIMIT 1";
            $avatar = Database::fetchOne($sql, [$userID, $partnerID], 'ii');
        }

        if ($avatar === null) {
            return false; // ❌ No avatar to delete
        }

        // 📁 Delete files from disk (if uploaded type)
        if ($avatar['avatarType'] === 'upload' && !empty($avatar['avatarPath'])) {
            self::deleteAvatarFiles($userID, $avatar['avatarPath']);
        }

        // 🗄️ Deactivate the database record
        $deleteSql = "DELETE FROM tblUserAvatars WHERE avatarID = ?";
        Database::query($deleteSql, [$avatar['avatarID']], 'i');

        // 🔄 Clear tblUsers.profilePicture cache (for global avatar)
        if ($partnerID === null) {
            self::updateUserProfilePicture($userID, null);
        }

        // 🧹 Clear resolve cache
        self::clearCache($userID);

        // ✅ Log activity
        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $userID,
                'avatar_deleted',
                'account',
                'info',
                'User deleted their avatar' . ($partnerID !== null ? ' (partner-specific)' : ' (global)'),
                ['partner_id' => $partnerID]
            );
        }

        return true;
    }

    /**
     * 🔗 Set an OAuth provider's avatar as the user's SIGNula avatar
     *
     * Creates/updates a tblUserAvatars record pointing to the OAuth account's
     * profile picture URL.
     *
     * @param int $userID         User ID
     * @param int $oauthAccountID OAuth account ID from tblOAuthAccounts
     * @return array              ['success' => bool, 'error' => ?string]
     */
    public static function setOAuthAsAvatar(int $userID, int $oauthAccountID): array
    {
        // 🔍 Verify the OAuth account belongs to this user and has a profile picture
        $sql = "SELECT oauthAccountID, profilePicture, provider
                FROM tblOAuthAccounts
                WHERE oauthAccountID = ? AND userID = ? AND profilePicture IS NOT NULL AND profilePicture != ''
                LIMIT 1";
        $oauth = Database::fetchOne($sql, [$oauthAccountID, $userID], 'ii');

        if ($oauth === null) {
            return ['success' => false, 'error' => 'OAuth account not found or has no profile picture.'];
        }

        // 🗑️ Clean existing global avatar files if it was an upload
        self::cleanOldAvatars($userID, null);

        // 🗄️ Upsert avatar record (global context, partnerID = NULL)
        $existingSql = "SELECT avatarID FROM tblUserAvatars
                       WHERE userID = ? AND partnerID IS NULL
                       LIMIT 1";
        $existing = Database::fetchOne($existingSql, [$userID], 'i');

        if ($existing !== null) {
            $updateSql = "UPDATE tblUserAvatars
                         SET avatarType = 'oauth', avatarPath = NULL, avatarURL = ?,
                             oauthAccountID = ?, mimeType = NULL, fileSize = NULL,
                             width = NULL, height = NULL, isActive = TRUE
                         WHERE avatarID = ?";
            Database::query($updateSql, [$oauth['profilePicture'], $oauthAccountID, $existing['avatarID']], 'sii');
        } else {
            $insertSql = "INSERT INTO tblUserAvatars
                         (userID, partnerID, avatarType, avatarURL, oauthAccountID, isActive)
                         VALUES (?, NULL, 'oauth', ?, ?, TRUE)";
            Database::query($insertSql, [$userID, $oauth['profilePicture'], $oauthAccountID], 'isi');
        }

        // 🔄 Update tblUsers.profilePicture cache
        self::updateUserProfilePicture($userID, $oauth['profilePicture']);

        // 🧹 Clear resolve cache
        self::clearCache($userID);

        // ✅ Log activity
        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $userID,
                'avatar_oauth_set',
                'account',
                'info',
                'User set ' . htmlspecialchars($oauth['provider']) . ' OAuth avatar as profile picture',
                ['oauth_account_id' => $oauthAccountID, 'provider' => $oauth['provider']]
            );
        }

        return ['success' => true, 'error' => null];
    }

    // ============================================================================
    // ⚙️ User Preference Management
    // ============================================================================

    /**
     * 📋 Get user's fallback service priority order
     *
     * Returns the user's custom fallback order, or the system default
     * if no custom order has been set.
     *
     * @param int $userID User ID
     * @return array      Ordered array of service identifiers (e.g., ['gravatar', 'ui_avatars'])
     */
    public static function getUserFallbackPriority(int $userID): array
    {
        // 🔍 Check for user-specific preference
        $sql = "SELECT preferenceValue
                FROM tblUserPreferences
                WHERE userID = ? AND preferenceKey = 'avatar.fallback_priority'
                LIMIT 1";
        $row = Database::fetchOne($sql, [$userID], 'i');

        if ($row !== null && !empty($row['preferenceValue'])) {
            $decoded = json_decode($row['preferenceValue'], true);
            if (is_array($decoded) && !empty($decoded)) {
                // 🔒 Filter to only known services
                return array_values(array_filter($decoded, function ($s) {
                    return isset(self::FALLBACK_SERVICES[strtolower(trim($s))]);
                }));
            }
        }

        // 🔄 Fall back to system default
        $default = getSetting('avatar.fallback_services', '["gravatar","ui_avatars"]');
        $decoded = is_string($default) ? json_decode($default, true) : $default;

        return is_array($decoded) ? $decoded : ['gravatar', 'ui_avatars'];
    }

    /**
     * 💾 Set user's fallback service priority order
     *
     * Stores the user's preferred order in tblUserPreferences.
     *
     * @param int   $userID   User ID
     * @param array $services Ordered array of service identifiers
     * @return bool           True if saved successfully
     */
    public static function setUserFallbackPriority(int $userID, array $services): bool
    {
        // 🔒 Validate: only allow known services
        $validServices = array_values(array_filter($services, function ($s) {
            return isset(self::FALLBACK_SERVICES[strtolower(trim($s))]);
        }));

        $json = json_encode($validServices);

        // 🗄️ Upsert preference
        $sql = "INSERT INTO tblUserPreferences (userID, preferenceKey, preferenceValue)
                VALUES (?, 'avatar.fallback_priority', ?)
                ON DUPLICATE KEY UPDATE preferenceValue = ?, updatedAt = CURRENT_TIMESTAMP";
        Database::query($sql, [$userID, $json, $json], 'iss');

        // 🧹 Clear resolve cache for this user
        self::clearCache($userID);

        return true;
    }

    /**
     * 📋 Get list of all available fallback services
     *
     * Returns metadata about each supported fallback service
     * for display in the UI.
     *
     * @return array Array of service info arrays
     */
    public static function getAvailableServices(): array
    {
        return [
            [
                'id'          => 'gravatar',
                'name'        => 'Gravatar',
                'description' => 'Globally Recognized Avatars — used by WordPress, GitHub, and many others',
                'url'         => 'https://gravatar.com',
                'requires'    => 'email',
            ],
            [
                'id'          => 'libravatar',
                'name'        => 'Libravatar',
                'description' => 'Open-source, federated avatar service — privacy-friendly Gravatar alternative',
                'url'         => 'https://www.libravatar.org',
                'requires'    => 'email',
            ],
            [
                'id'          => 'ui_avatars',
                'name'        => 'UI Avatars',
                'description' => 'Clean, initial-based avatars generated from your name',
                'url'         => 'https://ui-avatars.com',
                'requires'    => 'name',
            ],
            [
                'id'          => 'dicebear',
                'name'        => 'DiceBear',
                'description' => 'Unique avatar art styles generated from your identity',
                'url'         => 'https://www.dicebear.com',
                'requires'    => 'seed',
            ],
            [
                'id'          => 'robohash',
                'name'        => 'RoboHash',
                'description' => 'Fun robot avatars unique to your identity',
                'url'         => 'https://robohash.org',
                'requires'    => 'seed',
            ],
        ];
    }

    // ============================================================================
    // 🎨 Initials Avatar Generator
    // ============================================================================

    /**
     * 🎨 Generate an SVG initials avatar as a data URI
     *
     * Creates an inline SVG image with the user's initials on a
     * deterministic background colour. Works without any external service.
     *
     * @param string $name Display name to extract initials from
     * @param int    $size Image size in pixels
     * @return string      Data URI containing the SVG image
     */
    public static function generateInitialsAvatar(string $name, int $size = 96): string
    {
        // 🔤 Extract initials (first letter of first two words)
        $name = trim($name);
        $words = preg_split('/\s+/', $name);
        $initials = '';

        if (!empty($words[0])) {
            $initials .= mb_strtoupper(mb_substr($words[0], 0, 1));
        }
        if (!empty($words[1])) {
            $initials .= mb_strtoupper(mb_substr($words[1], 0, 1));
        }

        // 📝 Fallback if no valid initials
        if (empty($initials)) {
            $initials = '?';
        }

        // 🎨 Select deterministic background colour based on name hash
        // Using CRC32 for fast, consistent colour selection
        $colourIndex = abs(crc32($name)) % count(self::INITIALS_COLOURS);
        $bgColour = self::INITIALS_COLOURS[$colourIndex];

        // 📐 Calculate font size (proportional to avatar size)
        $fontSize = (int) ($size * 0.4);

        // 🖼️ Generate SVG
        // @see https://developer.mozilla.org/en-US/docs/Web/SVG
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 ' . $size . ' ' . $size . '">'
            . '<rect width="100%" height="100%" fill="' . htmlspecialchars($bgColour) . '"/>'
            . '<text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" '
            . 'fill="#ffffff" font-family="Arial, Helvetica, sans-serif" font-size="' . $fontSize . '" font-weight="600">'
            . htmlspecialchars($initials)
            . '</text></svg>';

        // 📦 Return as data URI for inline use in img src
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    // ============================================================================
    // 🔧 Image Processing (Private)
    // ============================================================================

    /**
     * ✅ Validate uploaded file metadata
     *
     * Checks error code, extension, MIME type, and file size.
     *
     * @param array $file $_FILES entry
     * @return array      ['valid' => bool, 'error' => ?string]
     */
    private static function validateUploadedFile(array $file): array
    {
        // 🔍 Check for upload errors
        // @see https://www.php.net/manual/en/features.file-upload.errors.php
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server maximum upload size.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form maximum upload size.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A server extension stopped the upload.',
            ];
            $errorCode = $file['error'] ?? -1;
            $message = $errorMessages[$errorCode] ?? 'Unknown upload error.';
            return ['valid' => false, 'error' => $message];
        }

        // 📁 Check file exists and was uploaded via HTTP POST
        // @see https://www.php.net/manual/en/function.is-uploaded-file.php
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Invalid upload detected.'];
        }

        // 📐 Check file size against configured maximum
        $maxSize = (int) getSetting('avatar.max_file_size', 2097152);
        if ($file['size'] > $maxSize) {
            $maxMB = round($maxSize / 1048576, 1);
            return ['valid' => false, 'error' => 'File size exceeds the maximum of ' . $maxMB . 'MB.'];
        }

        // 🔍 Validate MIME type using finfo (NOT trusting $_FILES['type'])
        // @see https://www.php.net/manual/en/function.finfo-file.php
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);

        $allowedTypes = json_decode(
            getSetting('avatar.allowed_types', '["image/jpeg","image/png","image/gif","image/webp"]'),
            true
        );

        if (!is_array($allowedTypes) || !in_array($detectedMime, $allowedTypes, true)) {
            return ['valid' => false, 'error' => 'File type not allowed. Please upload a JPEG, PNG, GIF, or WebP image.'];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * 🔍 Deep content validation — confirm the file is a real image
     *
     * Uses getimagesize() which reads the actual file header to determine
     * if it's a valid image. This catches files that have been renamed
     * with image extensions but contain non-image data.
     *
     * @param string $tmpPath Path to the temporary uploaded file
     * @return bool           True if the file is a valid image
     * @see https://www.php.net/manual/en/function.getimagesize.php
     */
    private static function validateImageContent(string $tmpPath): bool
    {
        // 🔍 getimagesize() reads the first bytes to determine image type
        // Returns false if the file is not a valid image
        $imageInfo = @getimagesize($tmpPath);
        if ($imageInfo === false) {
            return false;
        }

        // 📐 Check dimensions against maximum
        $maxDimensions = (int) getSetting('avatar.max_dimensions', 2048);
        if ($imageInfo[0] > $maxDimensions || $imageInfo[1] > $maxDimensions) {
            return false;
        }

        // ✅ Confirm GD can handle this image type
        // @see https://www.php.net/manual/en/function.gd-info.php
        $supportedTypes = [
            IMAGETYPE_JPEG => true,
            IMAGETYPE_PNG  => true,
            IMAGETYPE_GIF  => true,
        ];

        // 🔍 Check WebP support (available PHP 7.0+)
        $gdInfo = gd_info();
        if (!empty($gdInfo['WebP Support'])) {
            $supportedTypes[IMAGETYPE_WEBP] = true;
        }

        return isset($supportedTypes[$imageInfo[2]]);
    }

    /**
     * 🖼️ Process image: load, re-encode, generate multiple sizes
     *
     * Loads the image via GD (which strips EXIF and any embedded payloads),
     * then generates square cropped versions at multiple sizes.
     *
     * @param string $sourcePath Source image file path
     * @param string $destDir    Destination directory (user's avatar folder)
     * @param string $baseName   Base filename (without extension)
     * @param string $mimeType   Detected MIME type of the source
     * @return array             ['success' => bool, 'error' => ?string]
     * @see https://www.php.net/manual/en/ref.image.php
     */
    private static function processImage(string $sourcePath, string $destDir, string $baseName, string $mimeType): array
    {
        // 🖼️ Load image into GD based on detected type
        // This re-encoding step strips EXIF data and any embedded payloads
        $gdImage = match ($mimeType) {
            'image/jpeg'          => @imagecreatefromjpeg($sourcePath),
            'image/png'           => @imagecreatefrompng($sourcePath),
            'image/gif'           => @imagecreatefromgif($sourcePath),
            'image/webp'          => @imagecreatefromwebp($sourcePath),
            default               => false,
        };

        if ($gdImage === false) {
            return ['success' => false, 'error' => 'Failed to process image. The file may be corrupted.'];
        }

        // 📐 Get source dimensions for square cropping
        $srcWidth = imagesx($gdImage);
        $srcHeight = imagesy($gdImage);

        // ✂️ Calculate square crop area (centre crop)
        $cropSize = min($srcWidth, $srcHeight);
        $cropX = (int) (($srcWidth - $cropSize) / 2);
        $cropY = (int) (($srcHeight - $cropSize) / 2);

        // 🎯 Get output settings
        $outputFormat = getSetting('avatar.output_format', 'webp');
        $outputQuality = (int) getSetting('avatar.output_quality', 85);

        // 🔍 Check if output format is supported by GD
        $gdInfo = gd_info();
        if ($outputFormat === 'webp' && empty($gdInfo['WebP Support'])) {
            $outputFormat = 'jpeg'; // 🔄 Fall back to JPEG if WebP not supported
        }

        // 📐 Generate each size
        foreach (self::THUMB_SIZES as $targetSize) {
            // 🖼️ Create target image with transparency support
            $resized = imagecreatetruecolor($targetSize, $targetSize);

            // 🎨 Handle transparency for PNG/WebP/GIF
            if (in_array($outputFormat, ['png', 'webp'], true)) {
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefill($resized, 0, 0, $transparent);
            }

            // 📐 Resample from source (square crop → target size)
            // @see https://www.php.net/manual/en/function.imagecopyresampled.php
            imagecopyresampled(
                $resized,           // destination image
                $gdImage,           // source image
                0, 0,               // destination x, y
                $cropX, $cropY,     // source x, y (crop offset)
                $targetSize, $targetSize,   // destination width, height
                $cropSize, $cropSize        // source width, height (crop area)
            );

            // 💾 Save to file
            $outputPath = $destDir . DIRECTORY_SEPARATOR . $baseName . '_' . $targetSize . '.' . $outputFormat;

            $saved = self::saveImage($resized, $outputPath, $outputFormat, $outputQuality);
            imagedestroy($resized);

            if (!$saved) {
                imagedestroy($gdImage);
                return ['success' => false, 'error' => 'Failed to save processed avatar image.'];
            }
        }

        // 🧹 Clean up source GD image
        imagedestroy($gdImage);

        return ['success' => true, 'error' => null];
    }

    /**
     * 💾 Save a GD image resource to file
     *
     * @param \GdImage $image   GD image resource
     * @param string   $path    Output file path
     * @param string   $format  Output format (webp, jpeg, png)
     * @param int      $quality Image quality (1-100)
     * @return bool             True if saved successfully
     */
    private static function saveImage(\GdImage $image, string $path, string $format, int $quality): bool
    {
        return match ($format) {
            'webp'  => @imagewebp($image, $path, $quality),
            'jpeg'  => @imagejpeg($image, $path, $quality),
            'png'   => @imagepng($image, $path, (int) (9 - ($quality / 100 * 9))), // PNG quality is 0-9 (inverted)
            default => @imagejpeg($image, $path, $quality), // Fallback to JPEG
        };
    }

    /**
     * 📁 Get (or create) the upload directory for a user
     *
     * Creates the directory structure: _private/uploads/avatars/{userID}/
     *
     * @param int $userID User ID
     * @return string|null Absolute path to the user's avatar directory, or null on failure
     */
    private static function getUploadDirectory(int $userID): ?string
    {
        // 📁 Build path relative to web root
        $webRoot = defined('WEB_ROOT') ? WEB_ROOT : dirname(dirname(__DIR__));
        $baseDir = $webRoot . DIRECTORY_SEPARATOR . self::UPLOAD_DIR;
        $userDir = $baseDir . DIRECTORY_SEPARATOR . $userID;

        // 📁 Create directory if it doesn't exist
        // @see https://www.php.net/manual/en/function.mkdir.php
        if (!is_dir($userDir)) {
            if (!@mkdir($userDir, 0755, true)) {
                return null;
            }

            // 🔒 Add .htaccess to prevent direct access
            $htaccess = $baseDir . DIRECTORY_SEPARATOR . '.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Deny from all\n");
            }
        }

        return $userDir;
    }

    /**
     * 🎲 Generate a random filename for an uploaded avatar
     *
     * Uses cryptographically secure random bytes to prevent guessing.
     *
     * @return string Random filename base (without extension)
     */
    private static function generateFileName(): string
    {
        // 🔐 Use random_bytes for cryptographically secure random name
        // @see https://www.php.net/manual/en/function.random-bytes.php
        return 'avatar_' . bin2hex(random_bytes(12));
    }

    /**
     * 🗑️ Clean up old avatar files for a user/partner context
     *
     * Removes previous uploaded avatar files when a new avatar is set.
     *
     * @param int      $userID    User ID
     * @param int|null $partnerID Partner ID (NULL for global)
     */
    private static function cleanOldAvatars(int $userID, ?int $partnerID = null): void
    {
        // 🔍 Find existing upload-type avatar
        if ($partnerID === null) {
            $sql = "SELECT avatarPath FROM tblUserAvatars
                    WHERE userID = ? AND partnerID IS NULL AND avatarType = 'upload'
                    LIMIT 1";
            $existing = Database::fetchOne($sql, [$userID], 'i');
        } else {
            $sql = "SELECT avatarPath FROM tblUserAvatars
                    WHERE userID = ? AND partnerID = ? AND avatarType = 'upload'
                    LIMIT 1";
            $existing = Database::fetchOne($sql, [$userID, $partnerID], 'ii');
        }

        // 📁 Delete old files if found
        if ($existing !== null && !empty($existing['avatarPath'])) {
            self::deleteAvatarFiles($userID, $existing['avatarPath']);
        }
    }

    /**
     * 📁 Delete avatar files from disk
     *
     * Removes all size variants of an avatar file.
     *
     * @param int    $userID    User ID
     * @param string $avatarPath Relative avatar path (e.g., "42/avatar_abc123")
     */
    private static function deleteAvatarFiles(int $userID, string $avatarPath): void
    {
        $webRoot = defined('WEB_ROOT') ? WEB_ROOT : dirname(dirname(__DIR__));
        $baseDir = $webRoot . DIRECTORY_SEPARATOR . self::UPLOAD_DIR;
        $userDir = $baseDir . DIRECTORY_SEPARATOR . $userID;

        if (!is_dir($userDir)) {
            return;
        }

        // 🔍 Extract the base filename from the path
        $pathParts = pathinfo($avatarPath);
        $baseName = $pathParts['filename'] ?? '';

        // 🗑️ Delete all size variants
        $files = @glob($userDir . DIRECTORY_SEPARATOR . $baseName . '_*.*');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        // 🗑️ Also try deleting the exact path (if it has an extension)
        $exactPath = $baseDir . DIRECTORY_SEPARATOR . $avatarPath;
        if (file_exists($exactPath)) {
            @unlink($exactPath);
        }
    }

    /**
     * 🔄 Update the cached profilePicture path in tblUsers
     *
     * The tblUsers.profilePicture field acts as a quick-access cache
     * for the user's current global avatar.
     *
     * @param int         $userID User ID
     * @param string|null $path   Avatar path/URL (null to clear)
     */
    private static function updateUserProfilePicture(int $userID, ?string $path): void
    {
        $sql = "UPDATE tblUsers SET profilePicture = ? WHERE userID = ?";
        Database::query($sql, [$path, $userID], 'si');
    }

    /**
     * 👤 Get basic user data for avatar resolution
     *
     * @param int $userID User ID
     * @return array|null User data array or null if not found
     */
    private static function getUserData(int $userID): ?array
    {
        $sql = "SELECT userID, email, displayName, username, userUUID
                FROM tblUsers
                WHERE userID = ?
                LIMIT 1";

        return Database::fetchOne($sql, [$userID], 'i');
    }

    /**
     * 🧹 Clear the in-request resolve cache for a user
     *
     * Called after avatar changes to ensure fresh resolution.
     *
     * @param int $userID User ID
     */
    public static function clearCache(int $userID): void
    {
        foreach (array_keys(self::$resolveCache) as $key) {
            if (str_starts_with($key, $userID . ':')) {
                unset(self::$resolveCache[$key]);
            }
        }
    }

    // ============================================================================
    // 🔗 URL Helpers
    // ============================================================================

    /**
     * 🔗 Get the serve endpoint URL for a user's avatar
     *
     * Generates the URL for the /avatar/{uuid}/{size} endpoint
     * which serves local avatar files from the private directory.
     *
     * @param int $userID User ID
     * @param int $size   Desired size in pixels
     * @return string|null Serve URL or null if user UUID not found
     */
    private static function getServeUrl(int $userID, int $size): ?string
    {
        // 🔍 Get user's UUID for URL generation (prevents ID enumeration)
        $sql = "SELECT userUUID FROM tblUsers WHERE userID = ? LIMIT 1";
        $row = Database::fetchOne($sql, [$userID], 'i');

        if ($row === null || empty($row['userUUID'])) {
            return null;
        }

        // 🔗 Build serve URL
        // Snap size to nearest available thumbnail
        $snapSize = self::snapToNearestSize($size);

        return '/avatar/' . urlencode($row['userUUID']) . '/' . $snapSize;
    }

    /**
     * 📐 Snap a requested size to the nearest available thumbnail size
     *
     * @param int $requestedSize Requested size in pixels
     * @return int Nearest available size from THUMB_SIZES
     */
    private static function snapToNearestSize(int $requestedSize): int
    {
        $closest = self::THUMB_SIZES[0];
        $minDiff = PHP_INT_MAX;

        foreach (self::THUMB_SIZES as $thumbSize) {
            $diff = abs($thumbSize - $requestedSize);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $thumbSize;
            }
        }

        return $closest;
    }

    /**
     * ✅ Check if avatar uploads are enabled
     *
     * @return bool True if uploads are enabled
     */
    public static function isUploadEnabled(): bool
    {
        return (bool) getSetting('avatar.enable_upload', true);
    }

    /**
     * 📋 Get list of supported thumbnail sizes
     *
     * @return array Array of available sizes in pixels
     */
    public static function getThumbSizes(): array
    {
        return self::THUMB_SIZES;
    }

    /**
     * 📋 Get list of supported fallback service identifiers
     *
     * @return array Array of service ID strings
     */
    public static function getSupportedServices(): array
    {
        return array_keys(self::FALLBACK_SERVICES);
    }
}
