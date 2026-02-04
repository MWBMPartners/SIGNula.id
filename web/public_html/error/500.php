<?php
http_response_code(500);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 - Internal Server Error | SIGNula</title>
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
                    <i class="fas fa-exclamation-circle"></i>
                </div>
                <h1>500 - Internal Server Error</h1>
                <p>Something went wrong on our end.</p>
            </div>

            <div style="background: var(--bg-light); padding: 1.5rem; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                <p style="margin: 0; color: var(--text-secondary);">
                    We're experiencing technical difficulties. Our team has been notified and is working to resolve the issue.
                </p>
                <p style="margin: 1rem 0 0; color: var(--text-secondary);">
                    Please try again in a few moments.
                </p>
            </div>

            <div style="display: grid; gap: 0.75rem;">
                <a href="/" class="btn btn-primary btn-block btn-lg">
                    <i class="fas fa-home"></i> Go to Homepage
                </a>
                <a href="javascript:location.reload()" class="btn btn-outline btn-block">
                    <i class="fas fa-sync"></i> Try Again
                </a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
