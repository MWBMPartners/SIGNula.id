<?php
/**
 * 👤 User Management Interface (Super Admin)
 *
 * Provides a comprehensive dashboard for managing all user accounts.
 * Features include search, filtering, pagination, status/tier management,
 * password resets, and super admin toggling.
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons — FontAwesome 6.4 Icons
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

require_once dirname(__DIR__, 3) . '/_config/config.php';
require_once dirname(__DIR__, 3) . '/_backend/Database.php';
require_once dirname(__DIR__, 3) . '/_backend/SessionManager.php';
require_once dirname(__DIR__, 3) . '/_backend/AccessControl.php';

// 🔌 Initialise core services
$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔒 Authentication check
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check
$accessControl->requireSuperAdmin();

// 📊 Get initial stats for dashboard cards
$statsStmt = $db->prepare("SELECT accountStatus, COUNT(*) as count FROM tblUsers GROUP BY accountStatus");
$statsStmt->execute();
$statusCounts = $statsStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$statusMap = ['active' => 0, 'suspended' => 0, 'locked' => 0, 'pending' => 0, 'deleted' => 0];
$totalUsers = 0;
foreach ($statusCounts as $row) {
    $statusMap[$row['accountStatus']] = (int)$row['count'];
    $totalUsers += (int)$row['count'];
}

// 🆕 New users this month
$newStmt = $db->prepare("SELECT COUNT(*) as count FROM tblUsers WHERE createdAt >= DATE_FORMAT(NOW(), '%Y-%m-01')");
$newStmt->execute();
$newThisMonth = $newStmt->get_result()->fetch_assoc()['count'];

// 👤 Current admin user ID (to prevent self-modification in UI)
$currentUserID = $sessionManager->getUserID();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - SIGNula Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">
    <style>
        body { background-color: #f8f9fa; }

        /* 🎨 Gradient header — blue-purple theme for user management */
        .page-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }

        /* 📊 Stat cards with hover effect */
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
            text-align: center;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
        }
        .stat-card .stat-number {
            font-size: 2rem;
            font-weight: 700;
            line-height: 1;
        }
        .stat-card .stat-label {
            color: #6c757d;
            font-size: 0.85rem;
            margin-top: 0.5rem;
        }

        /* 📋 User table styles */
        .user-table-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .user-table-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 1rem 1.5rem;
        }

        /* 👤 Avatar styles */
        .user-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            object-fit: cover;
            background: #667eea;
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.85rem;
        }

        /* 🏷️ Status badges */
        .badge-active { background-color: #28a745; }
        .badge-suspended { background-color: #ffc107; color: #333; }
        .badge-locked { background-color: #dc3545; }
        .badge-pending { background-color: #17a2b8; }
        .badge-deleted { background-color: #6c757d; }

        /* 🏷️ Tier badges */
        .badge-free { background-color: #6c757d; }
        .badge-basic { background-color: #17a2b8; }
        .badge-premium { background-color: #ffc107; color: #333; }
        .badge-enterprise { background-color: #667eea; }
        .badge-admin { background-color: #dc3545; }

        /* 📄 Pagination */
        .pagination-info {
            color: #6c757d;
            font-size: 0.9rem;
        }

        /* 🔍 Filter bar */
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 1.5rem;
        }

        /* 🛡️ Super admin indicator */
        .super-admin-badge {
            background: linear-gradient(135deg, #f093fb, #f5576c);
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* 📱 Modal enhancements */
        .detail-section {
            border-left: 3px solid #667eea;
            padding-left: 1rem;
            margin-bottom: 1rem;
        }
        .detail-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.85rem;
        }
        .detail-value {
            color: #212529;
        }

        /* ⏳ Loading spinner */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <!-- 🎨 Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <a href="../index.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2"></i> Back to Admin Dashboard
                    </a>
                    <h1><i class="fas fa-users me-2"></i>User Management</h1>
                    <p class="mb-0 opacity-75">Manage all SIGNula user accounts, statuses, and permissions</p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="d-flex align-items-center justify-content-end">
                        <div class="text-end">
                            <div class="fs-3 fw-bold"><?php echo number_format($totalUsers); ?></div>
                            <small class="opacity-75">Total Users</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 📋 Alert Container -->
        <div id="alertContainer"></div>

        <!-- 📊 Stats Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md">
                <div class="stat-card cursor-pointer" onclick="filterByStatus('active')">
                    <div class="stat-number text-success"><?php echo number_format($statusMap['active']); ?></div>
                    <div class="stat-label"><i class="fas fa-check-circle me-1"></i>Active</div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="stat-card cursor-pointer" onclick="filterByStatus('suspended')">
                    <div class="stat-number text-warning"><?php echo number_format($statusMap['suspended']); ?></div>
                    <div class="stat-label"><i class="fas fa-pause-circle me-1"></i>Suspended</div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="stat-card cursor-pointer" onclick="filterByStatus('locked')">
                    <div class="stat-number text-danger"><?php echo number_format($statusMap['locked']); ?></div>
                    <div class="stat-label"><i class="fas fa-lock me-1"></i>Locked</div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="stat-card cursor-pointer" onclick="filterByStatus('pending')">
                    <div class="stat-number text-info"><?php echo number_format($statusMap['pending']); ?></div>
                    <div class="stat-label"><i class="fas fa-clock me-1"></i>Pending</div>
                </div>
            </div>
            <div class="col-6 col-md">
                <div class="stat-card">
                    <div class="stat-number text-primary"><?php echo number_format($newThisMonth); ?></div>
                    <div class="stat-label"><i class="fas fa-user-plus me-1"></i>New This Month</div>
                </div>
            </div>
        </div>

        <!-- 🔍 Search & Filters -->
        <div class="filter-bar">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold"><i class="fas fa-search me-1"></i>Search</label>
                    <input type="text" id="searchInput" class="form-control" placeholder="Username, email, or display name...">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold"><i class="fas fa-circle me-1"></i>Status</label>
                    <select id="statusFilter" class="form-select">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                        <option value="locked">Locked</option>
                        <option value="pending">Pending</option>
                        <option value="deleted">Deleted</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold"><i class="fas fa-tag me-1"></i>Tier</label>
                    <select id="tierFilter" class="form-select">
                        <option value="">All Tiers</option>
                        <option value="free">Free</option>
                        <option value="basic">Basic</option>
                        <option value="premium">Premium</option>
                        <option value="enterprise">Enterprise</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" onclick="loadUsers(1)">
                        <i class="fas fa-filter me-1"></i>Apply
                    </button>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                        <i class="fas fa-times me-1"></i>Clear
                    </button>
                </div>
            </div>
        </div>

        <!-- 📋 Users Table -->
        <div class="user-table-card position-relative">
            <div class="user-table-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>User Accounts</h5>
                <span id="resultCount" class="badge bg-light text-dark"></span>
            </div>

            <!-- ⏳ Loading overlay -->
            <div id="loadingOverlay" class="loading-overlay" style="display: none;">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 40px;"></th>
                            <th>User</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Tier</th>
                            <th>Last Login</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fas fa-spinner fa-spin fa-2x mb-3 d-block"></i>
                                Loading users...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- 📄 Pagination -->
            <div class="d-flex justify-content-between align-items-center p-3 border-top">
                <div class="pagination-info" id="paginationInfo">Showing 0 of 0 users</div>
                <nav>
                    <ul class="pagination pagination-sm mb-0" id="paginationNav"></ul>
                </nav>
            </div>
        </div>
    </div>

    <!-- 👁️ User Detail Modal -->
    <div class="modal fade" id="userDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white;">
                    <h5 class="modal-title"><i class="fas fa-user me-2"></i>User Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="userDetailBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary"></div>
                        <p class="mt-2">Loading user details...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ✏️ Change Status Modal -->
    <div class="modal fade" id="changeStatusModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-exchange-alt me-2"></i>Change User Status</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Change status for <strong id="statusUsername"></strong>:</p>
                    <input type="hidden" id="statusUserID">
                    <select id="newStatusSelect" class="form-select">
                        <option value="active">Active</option>
                        <option value="suspended">Suspended</option>
                        <option value="locked">Locked</option>
                        <option value="pending">Pending</option>
                        <option value="deleted">Deleted</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="confirmStatusChange()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 🏷️ Change Tier Modal -->
    <div class="modal fade" id="changeTierModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-tag me-2"></i>Change User Tier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Change tier for <strong id="tierUsername"></strong>:</p>
                    <input type="hidden" id="tierUserID">
                    <select id="newTierSelect" class="form-select">
                        <option value="free">Free</option>
                        <option value="basic">Basic</option>
                        <option value="premium">Premium</option>
                        <option value="enterprise">Enterprise</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="confirmTierChange()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>
    <!-- 🔐 CSRF Token for secure AJAX requests -->
    <script>const csrfToken = '<?php echo SecurityUtils::generateCSRFToken(); ?>';</script>
    <script>
        // 🔧 Configuration
        const currentUserID = <?php echo (int)$currentUserID; ?>;
        let currentPage = 1;
        const perPage = 25;

        // 📋 Status and tier badge colour maps
        const statusColors = {
            'active': 'badge-active',
            'suspended': 'badge-suspended',
            'locked': 'badge-locked',
            'pending': 'badge-pending',
            'deleted': 'badge-deleted'
        };

        const tierColors = {
            'free': 'badge-free',
            'basic': 'badge-basic',
            'premium': 'badge-premium',
            'enterprise': 'badge-enterprise',
            'admin': 'badge-admin'
        };

        /**
         * 💬 Show alert message
         */
        function showAlert(message, type = 'info') {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.getElementById('alertContainer').appendChild(alert);
            setTimeout(() => alert.remove(), 5000);
        }

        /**
         * 👤 Get user initials for avatar fallback
         */
        function getInitials(user) {
            if (user.firstName && user.lastName) {
                return (user.firstName[0] + user.lastName[0]).toUpperCase();
            }
            return user.username ? user.username[0].toUpperCase() : '?';
        }

        /**
         * 🔒 Escape HTML to prevent XSS
         */
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * 📋 Load users from API with current filters
         */
        async function loadUsers(page = 1) {
            currentPage = page;
            const search = document.getElementById('searchInput').value.trim();
            const status = document.getElementById('statusFilter').value;
            const tier = document.getElementById('tierFilter').value;

            // ⏳ Show loading state
            document.getElementById('loadingOverlay').style.display = 'flex';

            try {
                const params = new URLSearchParams({
                    action: 'list_users',
                    page: page,
                    perPage: perPage
                });

                if (search) params.append('search', search);
                if (status) params.append('status', status);
                if (tier) params.append('tier', tier);

                const response = await fetch(`../api/user-actions.php?${params}`);
                const result = await response.json();

                if (result.success) {
                    renderUsers(result.users);
                    renderPagination(result.pagination);
                } else {
                    showAlert(result.message || 'Failed to load users', 'danger');
                }
            } catch (error) {
                showAlert('Error loading users. Please try again.', 'danger');
            } finally {
                document.getElementById('loadingOverlay').style.display = 'none';
            }
        }

        /**
         * 📋 Render user rows in the table
         */
        function renderUsers(users) {
            const tbody = document.getElementById('usersTableBody');

            if (users.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fas fa-search fa-3x mb-3 d-block"></i>
                            <h5>No Users Found</h5>
                            <p>Try adjusting your search or filter criteria</p>
                        </td>
                    </tr>
                `;
                return;
            }

            tbody.innerHTML = users.map(user => {
                const isCurrentUser = user.userID === currentUserID;
                const initials = getInitials(user);
                const avatar = user.profilePicture
                    ? `<img src="${escapeHtml(user.profilePicture)}" class="user-avatar" alt="">`
                    : `<span class="user-avatar">${initials}</span>`;

                const lastLogin = user.lastLoginAt
                    ? new Date(user.lastLoginAt).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
                    : '<span class="text-muted">Never</span>';

                return `
                    <tr>
                        <td class="align-middle">${avatar}</td>
                        <td class="align-middle">
                            <strong>${escapeHtml(user.username)}</strong>
                            ${user.isSuperAdmin ? '<span class="super-admin-badge ms-1">SUPER ADMIN</span>' : ''}
                            ${isCurrentUser ? '<span class="badge bg-secondary ms-1">You</span>' : ''}
                            ${user.displayName ? `<br><small class="text-muted">${escapeHtml(user.displayName)}</small>` : ''}
                        </td>
                        <td class="align-middle">
                            ${escapeHtml(user.email)}
                            ${user.emailVerified ? '<i class="fas fa-check-circle text-success ms-1" title="Verified"></i>' : '<i class="fas fa-times-circle text-muted ms-1" title="Not verified"></i>'}
                        </td>
                        <td class="align-middle">
                            <span class="badge ${statusColors[user.accountStatus] || 'bg-secondary'}">
                                ${escapeHtml(user.accountStatus)}
                            </span>
                            ${user.failedLoginAttempts > 0 ? `<br><small class="text-danger">${user.failedLoginAttempts} failed attempts</small>` : ''}
                        </td>
                        <td class="align-middle">
                            <span class="badge ${tierColors[user.accountTier] || 'bg-secondary'}">
                                ${escapeHtml(user.accountTier)}
                            </span>
                        </td>
                        <td class="align-middle">
                            <small>${lastLogin}</small>
                            ${user.lastLoginIP ? `<br><small class="text-muted">${escapeHtml(user.lastLoginIP)}</small>` : ''}
                        </td>
                        <td class="align-middle text-center">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="viewUserDetails(${user.userID})">
                                        <i class="fas fa-eye me-2 text-primary"></i>View Details
                                    </a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    ${!isCurrentUser ? `
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="openStatusModal(${user.userID}, '${escapeHtml(user.username)}', '${user.accountStatus}')">
                                        <i class="fas fa-exchange-alt me-2 text-warning"></i>Change Status
                                    </a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="openTierModal(${user.userID}, '${escapeHtml(user.username)}', '${user.accountTier}')">
                                        <i class="fas fa-tag me-2 text-info"></i>Change Tier
                                    </a></li>
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="resetPassword(${user.userID}, '${escapeHtml(user.username)}')">
                                        <i class="fas fa-key me-2 text-secondary"></i>Force Password Reset
                                    </a></li>
                                    ${user.accountStatus === 'locked' || user.failedLoginAttempts > 0 ? `
                                    <li><a class="dropdown-item" href="javascript:void(0)" onclick="unlockUser(${user.userID}, '${escapeHtml(user.username)}')">
                                        <i class="fas fa-unlock me-2 text-success"></i>Unlock Account
                                    </a></li>
                                    ` : ''}
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item ${user.isSuperAdmin ? 'text-danger' : 'text-warning'}" href="javascript:void(0)" onclick="toggleSuperAdmin(${user.userID}, '${escapeHtml(user.username)}', ${!user.isSuperAdmin})">
                                        <i class="fas fa-shield-alt me-2"></i>${user.isSuperAdmin ? 'Revoke Super Admin' : 'Grant Super Admin'}
                                    </a></li>
                                    ` : `
                                    <li><span class="dropdown-item text-muted"><i class="fas fa-info-circle me-2"></i>Cannot modify own account</span></li>
                                    `}
                                </ul>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        /**
         * 📄 Render pagination controls
         */
        function renderPagination(pagination) {
            const { total, page, perPage, totalPages } = pagination;
            const start = ((page - 1) * perPage) + 1;
            const end = Math.min(page * perPage, total);

            document.getElementById('resultCount').textContent = `${total} users`;
            document.getElementById('paginationInfo').textContent = total > 0
                ? `Showing ${start}-${end} of ${total} users`
                : 'No users found';

            const nav = document.getElementById('paginationNav');

            if (totalPages <= 1) {
                nav.innerHTML = '';
                return;
            }

            let html = '';

            // ◀️ Previous button
            html += `<li class="page-item ${page === 1 ? 'disabled' : ''}">
                <a class="page-link" href="javascript:void(0)" onclick="loadUsers(${page - 1})">&laquo;</a>
            </li>`;

            // 📄 Page numbers (show max 7 pages with ellipsis)
            const maxVisible = 7;
            let startPage = Math.max(1, page - Math.floor(maxVisible / 2));
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);
            startPage = Math.max(1, endPage - maxVisible + 1);

            if (startPage > 1) {
                html += `<li class="page-item"><a class="page-link" href="javascript:void(0)" onclick="loadUsers(1)">1</a></li>`;
                if (startPage > 2) {
                    html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                }
            }

            for (let i = startPage; i <= endPage; i++) {
                html += `<li class="page-item ${i === page ? 'active' : ''}">
                    <a class="page-link" href="javascript:void(0)" onclick="loadUsers(${i})">${i}</a>
                </li>`;
            }

            if (endPage < totalPages) {
                if (endPage < totalPages - 1) {
                    html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
                }
                html += `<li class="page-item"><a class="page-link" href="javascript:void(0)" onclick="loadUsers(${totalPages})">${totalPages}</a></li>`;
            }

            // ▶️ Next button
            html += `<li class="page-item ${page === totalPages ? 'disabled' : ''}">
                <a class="page-link" href="javascript:void(0)" onclick="loadUsers(${page + 1})">&raquo;</a>
            </li>`;

            nav.innerHTML = html;
        }

        /**
         * 👁️ View user details in modal
         */
        async function viewUserDetails(userID) {
            const modal = new bootstrap.Modal(document.getElementById('userDetailModal'));
            const body = document.getElementById('userDetailBody');
            body.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">Loading...</p></div>';
            modal.show();

            try {
                const response = await fetch(`../api/user-actions.php?action=get_user&userID=${userID}`);
                const result = await response.json();

                if (result.success) {
                    const u = result.user;
                    const lastLogin = u.lastLoginAt ? new Date(u.lastLoginAt).toLocaleString() : 'Never';
                    const created = new Date(u.createdAt).toLocaleString();

                    let html = `
                        <div class="row">
                            <div class="col-md-6">
                                <div class="detail-section">
                                    <h6><i class="fas fa-user me-1"></i>Account Information</h6>
                                    <div class="mb-2"><span class="detail-label">Username:</span> <span class="detail-value">${escapeHtml(u.username)}</span></div>
                                    <div class="mb-2"><span class="detail-label">UUID:</span> <span class="detail-value"><code>${escapeHtml(u.userUUID)}</code></span></div>
                                    <div class="mb-2"><span class="detail-label">Email:</span> <span class="detail-value">${escapeHtml(u.email)} ${u.emailVerified ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-times-circle text-danger"></i>'}</span></div>
                                    <div class="mb-2"><span class="detail-label">Display Name:</span> <span class="detail-value">${escapeHtml(u.displayName || '—')}</span></div>
                                    <div class="mb-2"><span class="detail-label">Phone:</span> <span class="detail-value">${escapeHtml(u.phoneNumber || '—')} ${u.phoneVerified ? '<i class="fas fa-check-circle text-success"></i>' : ''}</span></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="detail-section">
                                    <h6><i class="fas fa-shield-alt me-1"></i>Security & Access</h6>
                                    <div class="mb-2"><span class="detail-label">Status:</span> <span class="badge ${statusColors[u.accountStatus]}">${u.accountStatus}</span></div>
                                    <div class="mb-2"><span class="detail-label">Tier:</span> <span class="badge ${tierColors[u.accountTier]}">${u.accountTier}</span></div>
                                    <div class="mb-2"><span class="detail-label">Super Admin:</span> <span class="detail-value">${u.isSuperAdmin ? '<span class="super-admin-badge">Yes</span>' : 'No'}</span></div>
                                    <div class="mb-2"><span class="detail-label">Failed Logins:</span> <span class="detail-value ${u.failedLoginAttempts > 0 ? 'text-danger fw-bold' : ''}">${u.failedLoginAttempts}</span></div>
                                    <div class="mb-2"><span class="detail-label">Last Login:</span> <span class="detail-value">${lastLogin}</span></div>
                                    <div class="mb-2"><span class="detail-label">Last IP:</span> <span class="detail-value"><code>${escapeHtml(u.lastLoginIP || '—')}</code></span></div>
                                    <div class="mb-2"><span class="detail-label">Created:</span> <span class="detail-value">${created}</span></div>
                                </div>
                            </div>
                        </div>
                    `;

                    // 🔗 OAuth Accounts
                    if (result.oauthAccounts && result.oauthAccounts.length > 0) {
                        html += `<hr><div class="detail-section"><h6><i class="fas fa-link me-1"></i>Linked OAuth Accounts</h6>`;
                        result.oauthAccounts.forEach(oa => {
                            html += `<div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                <span><i class="fab fa-${oa.provider} me-2"></i><strong>${escapeHtml(oa.provider)}</strong> — ${escapeHtml(oa.email || '—')}</span>
                                <span class="badge bg-secondary">${escapeHtml(oa.accountType)}</span>
                            </div>`;
                        });
                        html += `</div>`;
                    }

                    // 🏢 Partner Memberships
                    if (result.memberships && result.memberships.length > 0) {
                        html += `<hr><div class="detail-section"><h6><i class="fas fa-building me-1"></i>Partner Memberships</h6>`;
                        result.memberships.forEach(m => {
                            html += `<div class="d-flex justify-content-between align-items-center mb-2 p-2 bg-light rounded">
                                <span><strong>${escapeHtml(m.companyName)}</strong></span>
                                <span>
                                    <span class="badge bg-primary">${escapeHtml(m.role)}</span>
                                    ${m.isRootAdmin ? '<span class="super-admin-badge ms-1">Owner</span>' : ''}
                                    <span class="badge bg-${m.status === 'active' ? 'success' : 'secondary'}">${escapeHtml(m.status)}</span>
                                </span>
                            </div>`;
                        });
                        html += `</div>`;
                    }

                    // 📜 Recent Activity
                    if (result.recentActivity && result.recentActivity.length > 0) {
                        html += `<hr><div class="detail-section"><h6><i class="fas fa-history me-1"></i>Recent Activity (Last 10)</h6>
                        <div class="table-responsive"><table class="table table-sm">
                            <thead><tr><th>Type</th><th>Result</th><th>IP</th><th>Time</th></tr></thead><tbody>`;
                        result.recentActivity.forEach(a => {
                            const resultBadge = {
                                'success': 'bg-success',
                                'failure': 'bg-danger',
                                'warning': 'bg-warning text-dark',
                                'info': 'bg-info'
                            }[a.activityResult] || 'bg-secondary';
                            html += `<tr>
                                <td>${escapeHtml(a.activityType)}</td>
                                <td><span class="badge ${resultBadge}">${escapeHtml(a.activityResult)}</span></td>
                                <td><code>${escapeHtml(a.ipAddress || '—')}</code></td>
                                <td><small>${new Date(a.createdAt).toLocaleString()}</small></td>
                            </tr>`;
                        });
                        html += `</tbody></table></div></div>`;
                    }

                    body.innerHTML = html;
                } else {
                    body.innerHTML = `<div class="alert alert-danger">${escapeHtml(result.message)}</div>`;
                }
            } catch (error) {
                body.innerHTML = '<div class="alert alert-danger">Error loading user details</div>';
            }
        }

        /**
         * ✏️ Open change status modal
         */
        function openStatusModal(userID, username, currentStatus) {
            document.getElementById('statusUserID').value = userID;
            document.getElementById('statusUsername').textContent = username;
            document.getElementById('newStatusSelect').value = currentStatus;
            new bootstrap.Modal(document.getElementById('changeStatusModal')).show();
        }

        /**
         * ✅ Confirm status change
         */
        async function confirmStatusChange() {
            const userID = document.getElementById('statusUserID').value;
            const newStatus = document.getElementById('newStatusSelect').value;

            try {
                const response = await fetch('../api/user-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'update_status', userID: parseInt(userID), status: newStatus, csrf_token: csrfToken })
                });
                const result = await response.json();

                bootstrap.Modal.getInstance(document.getElementById('changeStatusModal')).hide();

                if (result.success) {
                    showAlert(result.message, 'success');
                    loadUsers(currentPage);
                } else {
                    showAlert(result.message || 'Failed to update status', 'danger');
                }
            } catch (error) {
                showAlert('Error updating user status', 'danger');
            }
        }

        /**
         * 🏷️ Open change tier modal
         */
        function openTierModal(userID, username, currentTier) {
            document.getElementById('tierUserID').value = userID;
            document.getElementById('tierUsername').textContent = username;
            document.getElementById('newTierSelect').value = currentTier;
            new bootstrap.Modal(document.getElementById('changeTierModal')).show();
        }

        /**
         * ✅ Confirm tier change
         */
        async function confirmTierChange() {
            const userID = document.getElementById('tierUserID').value;
            const newTier = document.getElementById('newTierSelect').value;

            try {
                const response = await fetch('../api/user-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'update_tier', userID: parseInt(userID), tier: newTier, csrf_token: csrfToken })
                });
                const result = await response.json();

                bootstrap.Modal.getInstance(document.getElementById('changeTierModal')).hide();

                if (result.success) {
                    showAlert(result.message, 'success');
                    loadUsers(currentPage);
                } else {
                    showAlert(result.message || 'Failed to update tier', 'danger');
                }
            } catch (error) {
                showAlert('Error updating user tier', 'danger');
            }
        }

        /**
         * 🔑 Force password reset
         */
        async function resetPassword(userID, username) {
            if (!confirm(`Force password reset for "${username}"?\n\nThey will be required to change their password on next login.`)) {
                return;
            }

            try {
                const response = await fetch('../api/user-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reset_password', userID: userID, csrf_token: csrfToken })
                });
                const result = await response.json();

                if (result.success) {
                    showAlert(result.message, 'success');
                } else {
                    showAlert(result.message || 'Failed to reset password', 'danger');
                }
            } catch (error) {
                showAlert('Error triggering password reset', 'danger');
            }
        }

        /**
         * 🔓 Unlock user account
         */
        async function unlockUser(userID, username) {
            if (!confirm(`Unlock account for "${username}"?\n\nThis will reset failed login attempts and set status to active.`)) {
                return;
            }

            try {
                const response = await fetch('../api/user-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'unlock_user', userID: userID, csrf_token: csrfToken })
                });
                const result = await response.json();

                if (result.success) {
                    showAlert(result.message, 'success');
                    loadUsers(currentPage);
                } else {
                    showAlert(result.message || 'Failed to unlock user', 'danger');
                }
            } catch (error) {
                showAlert('Error unlocking user', 'danger');
            }
        }

        /**
         * 🛡️ Toggle super admin status
         */
        async function toggleSuperAdmin(userID, username, enable) {
            const action = enable ? 'grant Super Admin access to' : 'revoke Super Admin access from';
            if (!confirm(`WARNING: You are about to ${action} "${username}".\n\nThis is a critical action. Are you sure?`)) {
                return;
            }

            try {
                const response = await fetch('../api/user-actions.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'toggle_super_admin', userID: userID, enabled: enable, csrf_token: csrfToken })
                });
                const result = await response.json();

                if (result.success) {
                    showAlert(result.message, 'success');
                    loadUsers(currentPage);
                } else {
                    showAlert(result.message || 'Failed to update super admin status', 'danger');
                }
            } catch (error) {
                showAlert('Error updating super admin status', 'danger');
            }
        }

        /**
         * 🔍 Filter by status (click stat card)
         */
        function filterByStatus(status) {
            document.getElementById('statusFilter').value = status;
            loadUsers(1);
        }

        /**
         * 🧹 Clear all filters
         */
        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = '';
            document.getElementById('tierFilter').value = '';
            loadUsers(1);
        }

        // ⌨️ Search on Enter key
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                loadUsers(1);
            }
        });

        // 🚀 Initial load
        loadUsers(1);
    </script>
</body>
</html>
