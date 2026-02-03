<?php
/**
 * ============================================================================
 * 🌐 SIGNula - Organization Domain Management
 * ============================================================================
 *
 * Purpose: Manage organization email domains and verification
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔄 Require authentication
Auth::requireAuth();

// Get current user
$user = Auth::getCurrentUser();

// 🏢 Check if user belongs to an organization
$organizationID = $user['organizationID'] ?? null;
$organization = null;
$userRole = null;
$isAdmin = false;

if ($organizationID) {
    $organization = Database::fetchOne(
        "SELECT * FROM tblOrganizations WHERE organizationID = ?",
        [$organizationID],
        'i'
    );

    $membership = Database::fetchOne(
        "SELECT role FROM tblOrganizationMembers WHERE organizationID = ? AND userID = ?",
        [$organizationID, $user['userID']],
        'ii'
    );

    $userRole = $membership['role'] ?? 'member';
    $isAdmin = in_array($userRole, ['owner', 'admin']);
}

// 🚫 Redirect if not part of organization
if (!$organization) {
    redirect('/dashboard?error=' . urlencode('You are not part of an organization.'));
}

// 📝 Handle form submissions
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    $action = $_POST['action'] ?? '';

    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            switch ($action) {
                case 'add_domain':
                    $domain = strtolower(trim($_POST['domain'] ?? ''));
                    $tenantID = trim($_POST['tenant_id'] ?? '');
                    $autoEnroll = isset($_POST['auto_enroll']);

                    // Validate domain
                    if (empty($domain)) {
                        $error = 'Domain is required.';
                    } elseif (!preg_match('/^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}$/', $domain)) {
                        $error = 'Invalid domain format.';
                    } else {
                        // Check if domain already exists
                        $existing = Database::fetchOne(
                            "SELECT domainID FROM tblOrganizationDomains WHERE domain = ?",
                            [$domain],
                            's'
                        );

                        if ($existing) {
                            $error = 'This domain is already registered.';
                        } else {
                            // Add domain
                            Database::query(
                                "INSERT INTO tblOrganizationDomains (organizationID, domain, tenantID, autoEnroll, createdAt)
                                 VALUES (?, ?, ?, ?, NOW())",
                                [$organizationID, $domain, $tenantID ?: null, $autoEnroll ? 1 : 0],
                                'issi'
                            );

                            ActivityLogger::log($user['userID'], 'domain_added', 'organization', 'info',
                                'Domain added to organization', ['domain' => $domain]);

                            $success = 'Domain added successfully. Please verify ownership to activate it.';
                        }
                    }
                    break;

                case 'remove_domain':
                    $domainID = (int)($_POST['domain_id'] ?? 0);

                    if ($domainID) {
                        // Get domain info for logging
                        $domainInfo = Database::fetchOne(
                            "SELECT domain FROM tblOrganizationDomains WHERE domainID = ? AND organizationID = ?",
                            [$domainID, $organizationID],
                            'ii'
                        );

                        if ($domainInfo) {
                            Database::query(
                                "DELETE FROM tblOrganizationDomains WHERE domainID = ? AND organizationID = ?",
                                [$domainID, $organizationID],
                                'ii'
                            );

                            ActivityLogger::log($user['userID'], 'domain_removed', 'organization', 'warning',
                                'Domain removed from organization', ['domain' => $domainInfo['domain']]);

                            $success = 'Domain removed successfully.';
                        }
                    }
                    break;

                case 'verify_domain':
                    $domainID = (int)($_POST['domain_id'] ?? 0);
                    $verificationMethod = $_POST['verification_method'] ?? 'manual';

                    if ($domainID) {
                        $result = Organization::verifyDomain($domainID, $verificationMethod);

                        if ($result['success']) {
                            $success = $result['message'];
                        } else {
                            $error = $result['message'];
                        }
                    }
                    break;
            }
        } catch (Exception $e) {
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            $error = 'An error occurred. Please try again.';
        }
    }
}

// 📋 Get all domains for this organization
$domains = [];
try {
    $domains = Database::fetchAll(
        "SELECT * FROM tblOrganizationDomains WHERE organizationID = ? ORDER BY isVerified DESC, createdAt DESC",
        [$organizationID],
        'i'
    );
} catch (Exception $e) {
    ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
}

// 🎫 Generate CSRF token
$csrfToken = SecurityUtils::generateCSRFToken();

// 📄 Page metadata
$pageTitle = 'Domain Management - ' . $organization['name'] . ' - SIGNula';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage organization domains">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>

    <!-- 🎨 Favicon -->
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">

    <!-- 🎨 Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- 🎨 Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous" />

    <!-- 🎨 Custom CSS -->
    <link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>

<!-- 📱 Mobile Menu Toggle -->
<button class="mobile-menu-toggle" id="mobileMenuToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- 🗂️ Sidebar Navigation -->
<?php include __DIR__ . '/../private_html/organization-nav.php'; ?>

<!-- 📄 Main Content -->
<main class="dashboard-main">
    <!-- 🎯 Page Header -->
    <div class="page-header">
        <div>
            <h1><i class="fas fa-globe"></i> Domain Management</h1>
            <p class="text-muted">Manage and verify email domains for <?php echo htmlspecialchars($organization['name']); ?></p>
        </div>
        <?php if ($isAdmin): ?>
        <div class="page-header-actions">
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDomainModal">
                <i class="fas fa-plus"></i> Add Domain
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- 🚨 Messages -->
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
            <button type="button" class="alert-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($success); ?></span>
            <button type="button" class="alert-close" onclick="this.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    <?php endif; ?>

    <!-- 📊 Domain Statistics -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value"><?php echo count($domains); ?></div>
                <div class="stat-label">Total Domains</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($domains, fn($d) => $d['isVerified'])); ?></div>
                <div class="stat-label">Verified Domains</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value"><?php echo count(array_filter($domains, fn($d) => !$d['isVerified'])); ?></div>
                <div class="stat-label">Pending Verification</div>
            </div>
        </div>
    </div>

    <!-- 📋 Domains List -->
    <div class="card">
        <div class="card-header">
            <h2><i class="fas fa-list"></i> Registered Domains</h2>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (empty($domains)): ?>
                <div class="empty-state">
                    <i class="fas fa-globe"></i>
                    <h3>No Domains Yet</h3>
                    <p>Add your first domain to enable email-based organization membership.</p>
                    <?php if ($isAdmin): ?>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDomainModal">
                        <i class="fas fa-plus"></i> Add Domain
                    </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Domain</th>
                                <th>Status</th>
                                <th>Verification Method</th>
                                <th>Tenant ID</th>
                                <th>Auto-Enroll</th>
                                <th>Added</th>
                                <?php if ($isAdmin): ?>
                                <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($domains as $domain): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($domain['domain']); ?></strong>
                                </td>
                                <td>
                                    <?php if ($domain['isVerified']): ?>
                                        <span class="badge badge-success">
                                            <i class="fas fa-check-circle"></i> Verified
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">
                                            <i class="fas fa-clock"></i> Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($domain['verificationMethod']): ?>
                                        <span class="badge badge-secondary">
                                            <?php echo strtoupper($domain['verificationMethod']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($domain['tenantID']): ?>
                                        <code><?php echo htmlspecialchars(substr($domain['tenantID'], 0, 20)); ?>...</code>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($domain['autoEnroll']): ?>
                                        <i class="fas fa-check text-success"></i>
                                    <?php else: ?>
                                        <i class="fas fa-times text-muted"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $created = new DateTime($domain['createdAt']);
                                    echo $created->format('M j, Y');
                                    ?>
                                </td>
                                <?php if ($isAdmin): ?>
                                <td>
                                    <div class="btn-group-sm">
                                        <?php if (!$domain['isVerified']): ?>
                                        <button
                                            class="btn btn-sm btn-primary"
                                            onclick="verifyDomain(<?php echo $domain['domainID']; ?>, '<?php echo htmlspecialchars($domain['domain']); ?>')"
                                        >
                                            <i class="fas fa-check"></i> Verify
                                        </button>
                                        <?php endif; ?>
                                        <button
                                            class="btn btn-sm btn-danger"
                                            onclick="removeDomain(<?php echo $domain['domainID']; ?>, '<?php echo htmlspecialchars($domain['domain']); ?>')"
                                        >
                                            <i class="fas fa-trash"></i> Remove
                                        </button>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ℹ️ Information Box -->
    <div class="card" style="margin-top: 2rem;">
        <div class="card-header">
            <h2><i class="fas fa-info-circle"></i> About Domain Verification</h2>
        </div>
        <div class="card-body">
            <h4>Why verify domains?</h4>
            <p>Verified domains allow your organization to:</p>
            <ul>
                <li>Automatically identify employees when they register with company email addresses</li>
                <li>Enforce OAuth provider restrictions for specific tenants (e.g., MS365 work accounts)</li>
                <li>Control who can join your organization based on email domain</li>
                <li>Enable auto-enrollment for new users with matching email domains</li>
            </ul>

            <h4 style="margin-top: 1.5rem;">Verification Methods:</h4>
            <ul>
                <li><strong>DNS:</strong> Add a TXT record to your domain's DNS settings</li>
                <li><strong>Email:</strong> Receive a verification link sent to admin@yourdomain.com</li>
                <li><strong>File:</strong> Upload a verification file to your website</li>
                <li><strong>Manual:</strong> Contact support for manual verification (for enterprise customers)</li>
            </ul>

            <h4 style="margin-top: 1.5rem;">Tenant ID:</h4>
            <p>
                For Microsoft 365 or Google Workspace, you can specify a tenant ID to restrict OAuth logins
                to only your organization's tenant. This prevents personal Microsoft/Google accounts from being used.
            </p>
        </div>
    </div>
</main>

<!-- 🔲 Add Domain Modal -->
<?php if ($isAdmin): ?>
<div class="modal fade" id="addDomainModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="/organization/domains">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="add_domain">

                <div class="modal-header">
                    <h3><i class="fas fa-plus"></i> Add Domain</h3>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="form-group">
                        <label for="domain" class="form-label form-label-required">Domain Name</label>
                        <input
                            type="text"
                            class="form-control"
                            id="domain"
                            name="domain"
                            placeholder="example.com"
                            required
                            pattern="[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}"
                        >
                        <small class="form-text">Enter domain without www or @ prefix</small>
                    </div>

                    <div class="form-group">
                        <label for="tenant_id" class="form-label">Tenant ID (Optional)</label>
                        <input
                            type="text"
                            class="form-control"
                            id="tenant_id"
                            name="tenant_id"
                            placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                        >
                        <small class="form-text">For MS365/Google Workspace tenant restriction</small>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="auto_enroll" name="auto_enroll" checked>
                        <label class="form-check-label" for="auto_enroll">
                            Auto-enroll users with this domain
                        </label>
                        <small class="form-text d-block">Automatically add new users with this email domain to your organization</small>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Add Domain
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- 🗑️ Hidden forms for delete/verify actions -->
<form id="removeDomainForm" method="POST" action="/organization/domains" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="remove_domain">
    <input type="hidden" name="domain_id" id="removeDomainId">
</form>

<form id="verifyDomainForm" method="POST" action="/organization/domains" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
    <input type="hidden" name="action" value="verify_domain">
    <input type="hidden" name="domain_id" id="verifyDomainId">
    <input type="hidden" name="verification_method" value="manual">
</form>
<?php endif; ?>

<!-- 📜 JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
<script src="/assets/js/main.js"></script>

<?php if ($isAdmin): ?>
<script>
function removeDomain(domainId, domainName) {
    if (confirm('Are you sure you want to remove the domain "' + domainName + '"?\n\nThis action cannot be undone.')) {
        document.getElementById('removeDomainId').value = domainId;
        document.getElementById('removeDomainForm').submit();
    }
}

function verifyDomain(domainId, domainName) {
    if (confirm('Mark domain "' + domainName + '" as verified?\n\nNote: In production, this should verify domain ownership first.')) {
        document.getElementById('verifyDomainId').value = domainId;
        document.getElementById('verifyDomainForm').submit();
    }
}
</script>
<?php endif; ?>

</body>
</html>
