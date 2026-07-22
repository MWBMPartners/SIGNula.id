<?php
/**
 * Support Ticket Submission Page
 * Submit a new support ticket (authenticated users only)
 * 
 * @package SIGNula
 * @version 1.0.0
 */

// Start session and check authentication
session_start();
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'auth' . DIRECTORY_SEPARATOR . 'check-auth.php';

// Page configuration
$pageTitle = 'Submit Support Ticket - SIGNula';
$currentPage = 'support';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = 'Invalid security token.';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    // Sanitize and validate input
    $subject = trim($_POST['subject']);
    $description = trim($_POST['description']);
    $category = $_POST['category'];
    $priority = $_POST['priority'];
    $relatedProduct = trim($_POST['relatedProduct'] ?? '');
    $relatedURL = trim($_POST['relatedURL'] ?? '');
    $errorMessage = trim($_POST['errorMessage'] ?? '');

    // Get user info
    $userQuery = "SELECT email, displayName FROM tblUsers WHERE userID = ?";
    $userStmt = $mysqli->prepare($userQuery);
    $userStmt->bind_param('i', $_SESSION['user_id']);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $user = $userResult->fetch_assoc();

    // Validation
    $errors = [];
    if (strlen($subject) < 5) {
        $errors[] = 'Subject must be at least 5 characters.';
    }
    if (strlen($description) < 20) {
        $errors[] = 'Description must be at least 20 characters.';
    }

    if (empty($errors)) {
        // Collect metadata
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';

        // Collect browser info
        $browserInfo = json_encode([
            'userAgent' => $userAgent,
            'ip' => $ipAddress,
            'referrer' => $referrer
        ]);

        // Insert ticket
        $insertQuery = "
            INSERT INTO tblSupportTickets (
                userID, email, name, subject, description, category, priority,
                relatedProduct, relatedURL, errorMessage, browserInfo,
                ipAddress, userAgent, referrer
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        
        $insertStmt = $mysqli->prepare($insertQuery);
        $insertStmt->bind_param(
            'isssssssssssss',
            $_SESSION['user_id'],
            $user['email'],
            $user['displayName'],
            $subject,
            $description,
            $category,
            $priority,
            $relatedProduct,
            $relatedURL,
            $errorMessage,
            $browserInfo,
            $ipAddress,
            $userAgent,
            $referrer
        );

        if ($insertStmt->execute()) {
            $ticketID = $insertStmt->insert_id;
            
            // Get the generated ticket number
            $ticketQuery = "SELECT ticketNumber FROM tblSupportTickets WHERE ticketID = ?";
            $ticketStmt = $mysqli->prepare($ticketQuery);
            $ticketStmt->bind_param('i', $ticketID);
            $ticketStmt->execute();
            $ticketResult = $ticketStmt->get_result();
            $ticket = $ticketResult->fetch_assoc();

            // 📧 Send email notifications
            $baseURL = getSetting('url.base', 'https://signula.id');
            $ticketUrl = "{$baseURL}/support/ticket/{$ticket['ticketNumber']}";

            // Send to user
            $userEmailVariables = [
                'displayName' => $user['displayName'],
                'ticketNumber' => $ticket['ticketNumber'],
                'subject' => $subject,
                'priority' => ucfirst($priority),
                'category' => ucfirst($category),
                'message' => $description,
                'ticketUrl' => $ticketUrl
            ];
            EmailService::sendTemplateEmail($user['email'], 'support_ticket_user', $userEmailVariables, $_SESSION['user_id'], 3);

            // Send to support team
            $supportEmail = getSetting('support.email_address', 'support@signula.id');
            $priorityColors = ['low' => '#28a745', 'medium' => '#ffc107', 'high' => '#fd7e14', 'critical' => '#dc3545'];

            $teamEmailVariables = [
                'displayName' => $user['displayName'],
                'email' => $user['email'],
                'ticketNumber' => $ticket['ticketNumber'],
                'subject' => $subject,
                'priority' => ucfirst($priority),
                'priorityColor' => $priorityColors[$priority] ?? '#6c757d',
                'category' => ucfirst($category),
                'message' => $description,
                'ticketUrl' => $ticketUrl
            ];
            EmailService::sendTemplateEmail($supportEmail, 'support_ticket_team', $teamEmailVariables, null, 2);

            $_SESSION['success_message'] = 'Ticket submitted successfully! Your ticket number is ' . $ticket['ticketNumber'];
            header('Location: /support/my-tickets');
            exit;
        } else {
            $errors[] = 'Failed to submit ticket: ' . $mysqli->error;
        }
    }

    // Store errors in session
    if (!empty($errors)) {
        $_SESSION['error_message'] = implode('<br>', $errors);
    }
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get categories for dropdown
$categories = [
    'Technical Support',
    'Billing',
    'Account',
    'API Integration',
    'Security',
    'Feature Request',
    'Bug Report',
    'General Inquiry',
    'Other'
];

// Include header (TODO: Create authenticated header)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    
    <style>
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 3rem 0;
            margin-bottom: 2rem;
        }
        
        .form-section {
            background: white;
            border-radius: 8px;
            padding: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .char-counter {
            font-size: 0.875rem;
            color: #6c757d;
        }
    </style>
</head>
<body class="bg-light">
    <!-- Page Header -->
    <header class="page-header">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h2 mb-2">Submit Support Ticket</h1>
                    <p class="mb-0 opacity-75">Describe your issue and we'll get back to you shortly</p>
                </div>
                <div>
                    <a href="/support/my-tickets" class="btn btn-light">
                        <i class="fas fa-list me-2"></i>My Tickets
                    </a>
                    <a href="https://signula.com/support" class="btn btn-outline-light ms-2">
                        <i class="fas fa-book me-2"></i>Help Center
                    </a>
                </div>
            </div>
        </div>
    </header>

    <div class="container pb-5">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <!-- Messages -->
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Ticket Form -->
                <div class="form-section">
                    <form method="POST" action="" id="ticketForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                        <!-- Category and Priority -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="category" class="form-label">Category *</label>
                                <select class="form-select" id="category" name="category" required>
                                    <option value="">-- Select Category --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>">
                                            <?php echo htmlspecialchars($cat); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="priority" class="form-label">Priority *</label>
                                <select class="form-select" id="priority" name="priority" required>
                                    <option value="Normal">Normal</option>
                                    <option value="Low">Low</option>
                                    <option value="High">High</option>
                                    <option value="Urgent">Urgent</option>
                                </select>
                                <small class="text-muted">Select "Urgent" only for critical issues</small>
                            </div>
                        </div>

                        <!-- Subject -->
                        <div class="mb-3">
                            <label for="subject" class="form-label">Subject *</label>
                            <input type="text" class="form-control" id="subject" name="subject" 
                                   placeholder="Brief summary of your issue" required minlength="5" maxlength="255">
                        </div>

                        <!-- Description -->
                        <div class="mb-3">
                            <label for="description" class="form-label">Description *</label>
                            <textarea class="form-control" id="description" name="description" rows="8" 
                                      placeholder="Please provide as much detail as possible about your issue..." 
                                      required minlength="20" maxlength="5000"></textarea>
                            <small class="text-muted char-counter">
                                <span id="descriptionCount">0</span>/5000 characters
                            </small>
                        </div>

                        <!-- Additional Details -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <h3 class="h6 mb-0">
                                    <i class="fas fa-info-circle me-2"></i>Additional Information (Optional)
                                </h3>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="relatedProduct" class="form-label">Related Product/Service</label>
                                    <input type="text" class="form-control" id="relatedProduct" name="relatedProduct" 
                                           placeholder="e.g., iOS SDK, Dashboard, API">
                                </div>

                                <div class="mb-3">
                                    <label for="relatedURL" class="form-label">Related URL</label>
                                    <input type="url" class="form-control" id="relatedURL" name="relatedURL" 
                                           placeholder="https://example.com/page-with-issue">
                                    <small class="text-muted">URL where the issue occurred</small>
                                </div>

                                <div class="mb-0">
                                    <label for="errorMessage" class="form-label">Error Message</label>
                                    <textarea class="form-control" id="errorMessage" name="errorMessage" rows="3" 
                                              placeholder="Copy and paste any error messages here"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-paper-plane me-2"></i>Submit Ticket
                            </button>
                            <a href="/dashboard" class="btn btn-outline-secondary">
                                <i class="fas fa-times me-2"></i>Cancel
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Tips -->
                <div class="mt-4">
                    <div class="card">
                        <div class="card-body">
                            <h3 class="h6 card-title">
                                <i class="fas fa-lightbulb me-2 text-warning"></i>Tips for faster resolution
                            </h3>
                            <ul class="mb-0 small">
                                <li>Provide detailed steps to reproduce the issue</li>
                                <li>Include relevant error messages or screenshots</li>
                                <li>Specify which browser/device you're using</li>
                                <li>Check our <a href="https://signula.com/support">Knowledge Base</a> first for common solutions</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    
    <!-- Custom JS -->
    <script>
        // Character counter
        const descriptionField = document.getElementById('description');
        const descriptionCounter = document.getElementById('descriptionCount');

        descriptionField.addEventListener('input', function() {
            descriptionCounter.textContent = this.value.length;
            
            if (this.value.length > 4500) {
                descriptionCounter.classList.add('text-warning');
            } else {
                descriptionCounter.classList.remove('text-warning');
            }
        });

        // Form validation
        document.getElementById('ticketForm').addEventListener('submit', function(e) {
            const subject = document.getElementById('subject').value.trim();
            const description = document.getElementById('description').value.trim();

            if (subject.length < 5) {
                e.preventDefault();
                alert('Subject must be at least 5 characters long.');
                return false;
            }

            if (description.length < 20) {
                e.preventDefault();
                alert('Description must be at least 20 characters long.');
                return false;
            }
        });

        // Priority warning
        document.getElementById('priority').addEventListener('change', function() {
            if (this.value === 'Urgent') {
                if (!confirm('Urgent priority should only be used for critical issues that prevent you from using the service. Continue?')) {
                    this.value = 'Normal';
                }
            }
        });
    </script>
</body>
</html>
