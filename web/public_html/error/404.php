<?php
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found | SIGNula</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
<div class="auth-layout">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-card-header">
                <div class="auth-logo" style="color: var(--warning-color);">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <h1>404 - Page Not Found</h1>
                <p>The page you're looking for doesn't exist.</p>
            </div>

            <div style="background: var(--bg-light); padding: 1.5rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                <p style="margin: 0; color: var(--text-secondary);">
                    The requested URL was not found on this server. This might be because:
                </p>
                <ul style="margin: 1rem 0 0; padding-left: 1.5rem; color: var(--text-secondary);">
                    <li>The page has been moved or deleted</li>
                    <li>You typed the URL incorrectly</li>
                    <li>The link you followed is outdated</li>
                </ul>
            </div>

            <div style="display: grid; gap: 0.75rem;">
                <a href="/" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-home"></i> Go to Homepage
                </a>
                <a href="javascript:history.back()" class="btn btn-outline btn-block">
                    <i class="fas fa-arrow-left"></i> Go Back
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
