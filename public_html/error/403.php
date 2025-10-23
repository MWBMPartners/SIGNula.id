<?php
http_response_code(403);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Access Forbidden | SIGNula</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
<div class="auth-layout">
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-card-header">
                <div class="auth-logo" style="color: var(--danger-color);">
                    <i class="fas fa-ban"></i>
                </div>
                <h1>403 - Access Forbidden</h1>
                <p>You don't have permission to access this resource.</p>
            </div>

            <div style="background: var(--bg-light); padding: 1.5rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                <p style="margin: 0; color: var(--text-secondary);">
                    Access to this resource is restricted. This might be because:
                </p>
                <ul style="margin: 1rem 0 0; padding-left: 1.5rem; color: var(--text-secondary);">
                    <li>You don't have sufficient privileges</li>
                    <li>The resource is protected</li>
                    <li>You need to be logged in</li>
                </ul>
            </div>

            <div style="display: grid; gap: 0.75rem;">
                <a href="/" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-home"></i> Go to Homepage
                </a>
                <a href="/login" class="btn btn-outline btn-block">
                    <i class="fas fa-sign-in-alt"></i> Login
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
