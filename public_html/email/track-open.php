<?php
/**
 * ============================================================================
 * 📊 SIGNula - Email Open Tracking Pixel
 * ============================================================================
 *
 * Purpose: Track email opens via 1x1 transparent pixel
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📚 Load email tracker
require_once SIGNULA_ROOT . '/_includes/email/EmailTracker.php';

// 📊 Get tracking token
$trackingToken = $_GET['t'] ?? '';

if (!empty($trackingToken)) {
    // 🌐 Get client information
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    // 📝 Record the open
    EmailTracker::recordOpen($trackingToken, $ipAddress, $userAgent);
}

// 🎨 Output 1x1 transparent PNG pixel
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

// 📦 1x1 transparent PNG (67 bytes)
echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
exit;
