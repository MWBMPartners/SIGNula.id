<?php
/**
 * ============================================================================
 * ✅ SIGNula - Cloud Export Success/Error Page
 * ============================================================================
 *
 * Displays the result of a cloud export operation (Google Sheets or Excel Online).
 * Shows either a success message with a link to the created spreadsheet, or an
 * error message with an option to retry.
 *
 * Query Parameters:
 *   - status   (string)  "success" or "error"
 *   - provider (string)  "google_sheets" or "excel_online" (success only)
 *   - url      (string)  URL to the created spreadsheet/workbook (success only)
 *   - title    (string)  Title of the exported spreadsheet (success only)
 *   - message  (string)  Error message (error only)
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage API\Export
 * @version    1.0.0
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA INIT GUARD
// ============================================================================
if (!defined('SIGNULA_INIT')) {
    define('SIGNULA_INIT', true);
}

// ============================================================================
// 🔧 LOAD APPLICATION CONFIGURATION
// ============================================================================
require_once dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 📋 EXTRACT & SANITIZE PARAMETERS
// ============================================================================
$status   = SecurityUtils::sanitizeString($_GET['status'] ?? 'error');
$provider = SecurityUtils::sanitizeString($_GET['provider'] ?? '');
$url      = filter_var($_GET['url'] ?? '', FILTER_VALIDATE_URL) ?: '';
$title    = SecurityUtils::sanitizeString($_GET['title'] ?? 'Data Export');
$message  = SecurityUtils::sanitizeString($_GET['message'] ?? '');

// 🔒 Validate the URL points to an expected domain (prevent open redirect / phishing)
$allowedDomains = [
    'docs.google.com',
    'sheets.google.com',
    'onedrive.live.com',
    'www.onedrive.com',
    '1drv.ms',
];

if (!empty($url)) {
    $urlHost = parse_url($url, PHP_URL_HOST);
    $isDomainAllowed = false;
    foreach ($allowedDomains as $domain) {
        if ($urlHost === $domain || str_ends_with($urlHost, '.' . $domain)) {
            $isDomainAllowed = true;
            break;
        }
    }
    // 🛡️ Also allow any *.sharepoint.com domain (for enterprise OneDrive)
    if (!$isDomainAllowed && str_ends_with($urlHost, '.sharepoint.com')) {
        $isDomainAllowed = true;
    }
    if (!$isDomainAllowed) {
        $url = ''; // ❌ Reject unrecognised domains
    }
}

// 🏷️ Provider display names and icons
$providerInfo = match ($provider) {
    'google_sheets' => [
        'name'     => 'Google Sheets',
        'icon'     => 'fa-brands fa-google',
        'colour'   => '#0F9D58',
        'btnClass' => 'btn-success',
    ],
    'excel_online' => [
        'name'     => 'Excel Online',
        'icon'     => 'fa-brands fa-microsoft',
        'colour'   => '#217346',
        'btnClass' => 'btn-success',
    ],
    default => [
        'name'     => 'Cloud Service',
        'icon'     => 'fa-solid fa-cloud',
        'colour'   => '#0d6efd',
        'btnClass' => 'btn-primary',
    ],
};

// 📄 Page title
$pageTitle = ($status === 'success') ? 'Export Successful' : 'Export Failed';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars($pageTitle); ?> — SIGNula</title>

    <!-- 🎨 Bootstrap 5.3.2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN"
          crossorigin="anonymous">

    <!-- 🎨 Font Awesome 6.4.2 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css"
          rel="stylesheet"
          integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA=="
          crossorigin="anonymous">

    <style>
        /* 🎨 Custom styles for the export result page */
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .result-card {
            max-width: 550px;
            width: 100%;
            border: none;
            border-radius: 1rem;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.1);
        }
        .result-icon {
            font-size: 4rem;
            margin-bottom: 1rem;
        }
        .result-icon.success { color: #198754; }
        .result-icon.error { color: #dc3545; }
        .provider-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.75rem;
            border-radius: 2rem;
            font-size: 0.875rem;
            font-weight: 500;
        }
        /* 🌙 Dark mode support */
        @media (prefers-color-scheme: dark) {
            body { background-color: #1a1a2e; }
            .result-card { background: #16213e; color: #e0e0e0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card result-card mx-auto">
            <div class="card-body text-center p-5">

                <?php if ($status === 'success' && !empty($url)): ?>
                    <!-- ============================================================ -->
                    <!-- ✅ SUCCESS STATE -->
                    <!-- ============================================================ -->
                    <div class="result-icon success">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>

                    <h2 class="mb-3">Export Successful!</h2>

                    <p class="text-muted mb-3">
                        Your data has been exported to
                        <span class="provider-badge bg-light border">
                            <i class="<?php echo htmlspecialchars($providerInfo['icon']); ?>"></i>
                            <?php echo htmlspecialchars($providerInfo['name']); ?>
                        </span>
                    </p>

                    <?php if (!empty($title)): ?>
                        <p class="mb-4">
                            <strong>Spreadsheet:</strong>
                            <em><?php echo htmlspecialchars($title); ?></em>
                        </p>
                    <?php endif; ?>

                    <!-- 🔗 Open spreadsheet button -->
                    <div class="d-grid gap-2 mb-3">
                        <a href="<?php echo htmlspecialchars($url); ?>"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="btn <?php echo htmlspecialchars($providerInfo['btnClass']); ?> btn-lg">
                            <i class="<?php echo htmlspecialchars($providerInfo['icon']); ?> me-2"></i>
                            Open in <?php echo htmlspecialchars($providerInfo['name']); ?>
                        </a>
                    </div>

                    <p class="text-muted small mb-0">
                        <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>
                        Opens in a new tab. You can also find this file in your
                        <?php echo ($provider === 'google_sheets') ? 'Google Drive' : 'OneDrive'; ?>.
                    </p>

                <?php else: ?>
                    <!-- ============================================================ -->
                    <!-- ❌ ERROR STATE -->
                    <!-- ============================================================ -->
                    <div class="result-icon error">
                        <i class="fa-solid fa-circle-xmark"></i>
                    </div>

                    <h2 class="mb-3">Export Failed</h2>

                    <?php if (!empty($message)): ?>
                        <div class="alert alert-danger text-start">
                            <i class="fa-solid fa-triangle-exclamation me-2"></i>
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-4">
                            An unexpected error occurred during the export. Please try again.
                        </p>
                    <?php endif; ?>

                    <!-- 🔄 Retry button -->
                    <div class="d-grid gap-2 mb-3">
                        <a href="/settings/usage" class="btn btn-primary btn-lg">
                            <i class="fa-solid fa-rotate-right me-2"></i>
                            Try Again
                        </a>
                    </div>

                <?php endif; ?>

                <!-- 🏠 Back to dashboard link -->
                <hr class="my-4">
                <div class="d-flex justify-content-center gap-3">
                    <a href="/settings/usage" class="btn btn-outline-secondary btn-sm">
                        <i class="fa-solid fa-chart-line me-1"></i> Usage Dashboard
                    </a>
                    <a href="/dashboard" class="btn btn-outline-secondary btn-sm">
                        <i class="fa-solid fa-home me-1"></i> Dashboard
                    </a>
                </div>

            </div>
        </div>

        <!-- 🏢 Footer -->
        <p class="text-center text-muted small mt-4">
            &copy; <?php echo date('Y'); ?> MWBM Partners Ltd. All rights reserved.
        </p>
    </div>

    <!-- 📦 Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
            crossorigin="anonymous"></script>

    <?php if ($status === 'success' && !empty($url)): ?>
    <script>
        // ⏱️ Auto-close this tab after 30 seconds if the user opened the spreadsheet
        // (only if this page was opened in a new tab/window)
        let autoCloseTimer = null;
        document.querySelector('a[target="_blank"]')?.addEventListener('click', function() {
            autoCloseTimer = setTimeout(function() {
                if (window.opener || window.history.length <= 1) {
                    window.close();
                }
            }, 30000);
        });
    </script>
    <?php endif; ?>
</body>
</html>
