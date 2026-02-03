<?php
/**
 * ============================================================================
 * 🔗 SIGNula - Email Click Tracking
 * ============================================================================
 *
 * Purpose: Track email link clicks and redirect to destination
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📚 Load email tracker
require_once SIGNULA_ROOT . '/private_html/email/EmailTracker.php';

// 📊 Get tracking parameters
$trackingToken = $_GET['t'] ?? '';
$destinationUrl = $_GET['url'] ?? '';

// ✅ Validate inputs
if (empty($trackingToken) || empty($destinationUrl)) {
    http_response_code(400);
    die('Invalid tracking parameters');
}

// 🔐 Validate URL format
if (!filter_var($destinationUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    die('Invalid destination URL');
}

// 🌐 Get client information
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
$referer = $_SERVER['HTTP_REFERER'] ?? null;

// 📝 Record the click
$validatedUrl = EmailTracker::recordClick($trackingToken, $destinationUrl, $ipAddress, $userAgent, $referer);

// 🔄 Redirect to destination
if ($validatedUrl) {
    header('Location: ' . $validatedUrl, true, 302);
    exit;
} else {
    http_response_code(404);
    die('Tracking token not found');
}
