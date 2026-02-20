<?php
/**
 * ============================================================================
 * 🖼️ SIGNula - Avatar Serve Endpoint
 * ============================================================================
 *
 * Purpose: Serve user avatar images from the private upload directory.
 *          Provides HTTP caching support (ETag, Last-Modified, 304 responses)
 *          and fallback to external avatar URLs via redirect.
 *
 * URL Patterns:
 *   GET /avatar/{userUUID}         → Default size (96px)
 *   GET /avatar/{userUUID}/{size}  → Specific size
 *
 * Security:
 *   - Uses userUUID (not userID) to prevent ID enumeration
 *   - Files stored outside web root in _private/uploads/avatars/
 *   - Only serves files with approved extensions
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Avatar
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
// This loads config, database, autoloader, and all required classes
$configPath = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    die('Configuration not found');
}
require_once $configPath;

// 🔍 Parse request parameters
$uuid = $_GET['uuid'] ?? '';
$requestedSize = (int) ($_GET['size'] ?? 96);

// ✅ Validate UUID format (standard UUID v4 format)
// @see https://www.php.net/manual/en/function.preg-match.php
if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $uuid)) {
    http_response_code(400);
    die('Invalid request');
}

// 📐 Validate and snap size to nearest available thumbnail
$validSizes = AvatarService::getThumbSizes();
$size = $validSizes[0]; // Default to smallest
$minDiff = PHP_INT_MAX;
foreach ($validSizes as $thumbSize) {
    $diff = abs($thumbSize - $requestedSize);
    if ($diff < $minDiff) {
        $minDiff = $diff;
        $size = $thumbSize;
    }
}

// 🔍 Look up user by UUID
// Using UUID instead of userID prevents ID enumeration attacks
$sql = "SELECT userID FROM tblUsers WHERE userUUID = ? LIMIT 1";
$user = Database::fetchOne($sql, [$uuid], 's');

if ($user === null) {
    http_response_code(404);
    die('Not found');
}

$userID = (int) $user['userID'];

// 🖼️ Check for a local uploaded avatar first
$avatarSql = "SELECT avatarType, avatarPath, avatarURL
              FROM tblUserAvatars
              WHERE userID = ? AND partnerID IS NULL AND isActive = TRUE
              LIMIT 1";
$avatar = Database::fetchOne($avatarSql, [$userID], 'i');

// 📁 If we have a local upload, serve it directly
if ($avatar !== null && $avatar['avatarType'] === 'upload' && !empty($avatar['avatarPath'])) {
    $outputFormat = getSetting('avatar.output_format', 'webp');

    // 📁 Build the file path
    // Avatar path format: "{userID}/avatar_randomhex"
    // We need to find the specific size file
    $webRoot = defined('WEB_ROOT') ? WEB_ROOT : dirname(dirname(__DIR__));
    $uploadsDir = $webRoot . DIRECTORY_SEPARATOR . '_private' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';

    // 🔍 Extract base name from the stored path and build sized filename
    $pathInfo = pathinfo($avatar['avatarPath']);
    $baseName = $pathInfo['filename'] ?? '';
    $filePath = $uploadsDir . DIRECTORY_SEPARATOR . $userID . DIRECTORY_SEPARATOR . $baseName . '_' . $size . '.' . $outputFormat;

    // 📄 Check if the specific size file exists
    if (file_exists($filePath) && is_file($filePath)) {
        // 🔧 Determine MIME type for the output format
        $mimeTypes = [
            'webp' => 'image/webp',
            'jpeg' => 'image/jpeg',
            'jpg'  => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
        ];
        $contentType = $mimeTypes[$outputFormat] ?? 'image/jpeg';

        // 📊 Get file stats for caching headers
        $fileStats = stat($filePath);
        $lastModified = $fileStats['mtime'];
        $etag = '"' . md5($filePath . $lastModified . $fileStats['size']) . '"';

        // 🔄 Check for conditional requests (304 Not Modified)
        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/If-None-Match
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }

        // @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/If-Modified-Since
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
            $ifModifiedSince = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']);
            if ($ifModifiedSince !== false && $ifModifiedSince >= $lastModified) {
                http_response_code(304);
                exit;
            }
        }

        // 📤 Serve the file with appropriate headers
        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $fileStats['size']);
        header('Cache-Control: public, max-age=86400'); // 🕐 Cache for 1 day
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        header('X-Content-Type-Options: nosniff');

        // 📤 Stream the file
        // @see https://www.php.net/manual/en/function.readfile.php
        readfile($filePath);
        exit;
    }
}

// 🌐 If we have an external URL (OAuth or custom), redirect to it
if ($avatar !== null && in_array($avatar['avatarType'], ['oauth', 'url'], true) && !empty($avatar['avatarURL'])) {
    $proxyExternal = (bool) getSetting('avatar.proxy_external', false);

    if ($proxyExternal) {
        // 🔒 Proxy mode: fetch external image and serve locally (adds privacy)
        // For now, just redirect — proxy implementation can be added later
        header('Location: ' . $avatar['avatarURL'], true, 302);
        header('Cache-Control: public, max-age=3600'); // 🕐 Cache redirect for 1 hour
        exit;
    } else {
        // 🔗 Direct redirect to external URL
        header('Location: ' . $avatar['avatarURL'], true, 302);
        header('Cache-Control: public, max-age=3600');
        exit;
    }
}

// 🔄 No stored avatar — try OAuth primary account
$oauthAvatar = AvatarService::getOAuthAvatar($userID);
if ($oauthAvatar !== null) {
    header('Location: ' . $oauthAvatar, true, 302);
    header('Cache-Control: public, max-age=3600');
    exit;
}

// 🎨 Ultimate fallback — redirect to a fallback service or serve initials SVG
$userDataSql = "SELECT email, displayName, username FROM tblUsers WHERE userID = ? LIMIT 1";
$userData = Database::fetchOne($userDataSql, [$userID], 'i');

if ($userData !== null) {
    $email = $userData['email'] ?? '';
    $displayName = $userData['displayName'] ?? $userData['username'] ?? 'User';

    // 🔄 Try fallback services
    $fallbackUrl = AvatarService::getFallbackAvatar($userID, $email, $displayName, $size);
    if ($fallbackUrl !== null) {
        header('Location: ' . $fallbackUrl, true, 302);
        header('Cache-Control: public, max-age=3600');
        exit;
    }

    // 🎨 Generate and serve inline SVG initials avatar
    $svg = AvatarService::generateInitialsAvatar($displayName, $size);

    // 📤 The generateInitialsAvatar returns a data URI — extract and serve the raw SVG
    $svgContent = base64_decode(str_replace('data:image/svg+xml;base64,', '', $svg));
    header('Content-Type: image/svg+xml');
    header('Content-Length: ' . strlen($svgContent));
    header('Cache-Control: public, max-age=86400');
    echo $svgContent;
    exit;
}

// ❌ Nothing found at all
http_response_code(404);
die('Not found');
