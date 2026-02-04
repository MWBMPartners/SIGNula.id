<?php
/**
 * Partner Registration
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 2) . '/_config/config.php';
require_once dirname(__DIR__, 2) . '/_backend/Database.php';
require_once dirname(__DIR__, 2) . '/_backend/SessionManager.php';

$db = Database::getInstance();
$sessionManager = new SessionManager($db);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $companyName = trim($_POST['company_name'] ?? '');
    $contactName = trim($_POST['contact_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $website = trim($_POST['website'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if (empty($companyName) || empty($contactName) || empty($email)) {
        $error = 'Please fill in all required fields';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address';
    } else {
        // Check if partner already exists
        $stmt = $db->prepare("SELECT partnerID FROM tblPartners WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {
            $error = 'A partner with this email already exists';
        } else {
            // Insert new partner
            $stmt = $db->prepare("
                INSERT INTO tblPartners (companyName, contactName, email, website, description, tier, status)
                VALUES (?, ?, ?, ?, ?, 'free', 'pending')
            ");
            $stmt->bind_param('sssss', $companyName, $contactName, $email, $website, $description);

            if ($stmt->execute()) {
                $success = 'Registration successful! Your application is pending admin approval.';
            } else {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Partner Registration - SIGNula</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .register-card { max-width: 600px; margin: 50px auto; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.3); }
    </style>
</head>
<body>
    <div class="container">
        <div class="card register-card">
            <div class="card-body p-5">
                <h2 class="text-center mb-4"><i class="fas fa-handshake me-2 text-primary"></i>Partner Registration</h2>
                <p class="text-center text-muted mb-4">Join the SIGNula partner program to access our authentication API</p>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
                    <div class="text-center mt-4">
                        <a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Company Name <span class="text-danger">*</span></label>
                            <input type="text" name="company_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Contact Name <span class="text-danger">*</span></label>
                            <input type="text" name="contact_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Website</label>
                            <input type="url" name="website" class="form-control" placeholder="https://example.com">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="Tell us about your use case..."></textarea>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-paper-plane me-2"></i>Submit Application
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="text-center mt-4">
                    <small class="text-muted">Already registered? <a href="dashboard.php">Login to Dashboard</a></small>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
