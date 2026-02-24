<?php
/**
 * Payment Management API (Admin)
 *
 * Purpose: Admin API for managing payments, subscriptions, tiers, and discounts
 *
 * Actions: stats, payments, subscriptions, tiers, update_tier, create_discount,
 *          refund, update_subscription_status
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * @since      2.3.0-beta
 */

require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

header('Content-Type: application/json');

$db = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl = new AccessControl($db, $sessionManager);

// 🔐 Check authentication
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 🔐 Require super admin
$userInfo = $sessionManager->getUserInfo();
if (!isset($userInfo['isSuperAdmin']) || $userInfo['isSuperAdmin'] != 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Super admin access required']);
    exit;
}

// 📥 Get request data
$input = json_decode(file_get_contents('php://input'), true);
$action = $_SERVER['REQUEST_METHOD'] === 'GET'
    ? ($_GET['action'] ?? '')
    : ($input['action'] ?? '');

// 🛡️ CSRF for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

try {
    switch ($action) {
        case 'stats':
            handleStats();
            break;

        case 'payments':
            handleListPayments();
            break;

        case 'subscriptions':
            handleListSubscriptions();
            break;

        case 'tiers':
            handleListTiers();
            break;

        case 'update_tier':
            handleUpdateTier($input);
            break;

        case 'create_discount':
            handleCreateDiscount($input);
            break;

        case 'list_discounts':
            handleListDiscounts();
            break;

        case 'refund':
            handleRefund($input);
            break;

        case 'update_subscription_status':
            handleUpdateSubscriptionStatus($input);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * 📊 Get payment statistics
 */
function handleStats() {
    $days = (int)($_GET['days'] ?? 30);
    $stats = PaymentManager::getAdminStats($days);

    // 📈 Also get subscription stats
    $subStats = Database::fetchOne(
        "SELECT
            COUNT(*) as totalSubscriptions,
            SUM(CASE WHEN subscriptionStatus = 'active' THEN 1 ELSE 0 END) as activeCount,
            SUM(CASE WHEN subscriptionStatus = 'trial' THEN 1 ELSE 0 END) as trialCount,
            SUM(CASE WHEN subscriptionStatus = 'cancelled' THEN 1 ELSE 0 END) as cancelledCount,
            SUM(CASE WHEN subscriptionStatus = 'paused' THEN 1 ELSE 0 END) as pausedCount,
            SUM(CASE WHEN subscriptionStatus = 'active' THEN amount ELSE 0 END) as monthlyRecurringRevenue
         FROM tblSubscriptions",
        [],
        ''
    );

    echo json_encode(['success' => true, 'data' => [
        'payments' => $stats,
        'subscriptions' => $subStats ?: [],
    ]]);
}

/**
 * 💳 List all payments with filtering
 */
function handleListPayments() {
    $status = $_GET['status'] ?? '';
    $search = trim($_GET['search'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;

    $conditions = [];
    $params = [];
    $types = '';

    if ($status !== '' && $status !== 'all') {
        $conditions[] = 'p.status = ?';
        $params[] = $status;
        $types .= 's';
    }

    if ($search !== '') {
        $searchLike = '%' . $search . '%';
        $conditions[] = '(u.email LIKE ? OR u.displayName LIKE ? OR p.invoiceNumber LIKE ? OR p.transactionID LIKE ?)';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $types .= 'ssss';
    }

    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    // 📊 Get total count
    $countResult = Database::fetchOne(
        "SELECT COUNT(*) as total FROM tblPayments p LEFT JOIN tblUsers u ON p.userID = u.userID $whereClause",
        $params,
        $types
    );
    $total = $countResult ? (int)$countResult['total'] : 0;

    // 📋 Get paginated results
    $params[] = $offset;
    $params[] = $perPage;
    $types .= 'ii';

    $payments = Database::fetchAll(
        "SELECT p.*, u.email, u.displayName
         FROM tblPayments p
         LEFT JOIN tblUsers u ON p.userID = u.userID
         $whereClause
         ORDER BY p.createdAt DESC
         LIMIT ?, ?",
        $params,
        $types
    );

    echo json_encode([
        'success' => true,
        'data' => $payments,
        'total' => $total,
        'pages' => ceil($total / $perPage),
        'currentPage' => $page,
    ]);
}

/**
 * 📦 List all subscriptions with filtering
 */
function handleListSubscriptions() {
    $status = $_GET['status'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 25;
    $offset = ($page - 1) * $perPage;

    $conditions = [];
    $params = [];
    $types = '';

    if ($status !== '' && $status !== 'all') {
        $conditions[] = 's.subscriptionStatus = ?';
        $params[] = $status;
        $types .= 's';
    }

    $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $params[] = $offset;
    $params[] = $perPage;
    $types .= 'ii';

    $subscriptions = Database::fetchAll(
        "SELECT s.*, t.tierName, t.tierSlug, u.email, u.displayName
         FROM tblSubscriptions s
         JOIN tblSubscriptionTiers t ON s.tierID = t.tierID
         LEFT JOIN tblUsers u ON s.userID = u.userID
         $whereClause
         ORDER BY s.createdAt DESC
         LIMIT ?, ?",
        $params,
        $types
    );

    echo json_encode(['success' => true, 'data' => $subscriptions]);
}

/**
 * 🏷️ List subscription tiers
 */
function handleListTiers() {
    $tiers = PaymentManager::getTiers(true);

    foreach ($tiers as &$tier) {
        $tier['features'] = json_decode($tier['features'], true) ?: [];
        $tier['featureLimits'] = json_decode($tier['featureLimits'], true) ?: [];
    }

    echo json_encode(['success' => true, 'data' => $tiers]);
}

/**
 * ✏️ Update a subscription tier
 */
function handleUpdateTier($input) {
    $tierID = (int)($input['tierID'] ?? 0);

    if ($tierID <= 0) {
        throw new Exception('Valid tier ID required');
    }

    $setClauses = [];
    $params = [];
    $types = '';

    $allowedFields = ['tierName', 'tierDescription', 'monthlyPrice', 'yearlyPrice', 'isActive', 'trialDays', 'badge', 'badgeColor', 'displayOrder'];

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $setClauses[] = "`$field` = ?";
            if (in_array($field, ['monthlyPrice', 'yearlyPrice'])) {
                $params[] = (float)$input[$field];
                $types .= 'd';
            } elseif (in_array($field, ['isActive', 'trialDays', 'displayOrder'])) {
                $params[] = (int)$input[$field];
                $types .= 'i';
            } else {
                $params[] = $input[$field];
                $types .= 's';
            }
        }
    }

    if (isset($input['features'])) {
        $setClauses[] = 'features = ?';
        $params[] = json_encode($input['features']);
        $types .= 's';
    }

    if (isset($input['featureLimits'])) {
        $setClauses[] = 'featureLimits = ?';
        $params[] = json_encode($input['featureLimits']);
        $types .= 's';
    }

    if (empty($setClauses)) {
        throw new Exception('No valid fields to update');
    }

    $params[] = $tierID;
    $types .= 'i';

    Database::query(
        "UPDATE tblSubscriptionTiers SET " . implode(', ', $setClauses) . " WHERE tierID = ?",
        $params,
        $types
    );

    ActivityLogger::log(null, 'tier_updated', 'admin', 'info', 'Subscription tier #' . $tierID . ' updated', ['tierID' => $tierID, 'fields' => array_keys($input)]);

    echo json_encode(['success' => true, 'message' => 'Tier updated']);
}

/**
 * 🎟️ Create a discount code
 */
function handleCreateDiscount($input) {
    $code = strtoupper(trim($input['code'] ?? ''));
    $discountType = $input['discountType'] ?? 'percentage';
    $discountValue = (float)($input['discountValue'] ?? 0);

    if (empty($code)) {
        throw new Exception('Discount code is required');
    }

    if ($discountValue <= 0) {
        throw new Exception('Discount value must be greater than 0');
    }

    // 🔍 Check for duplicate
    $existing = Database::fetchOne("SELECT discountID FROM tblDiscountCodes WHERE code = ?", [$code], 's');
    if ($existing) {
        throw new Exception('Discount code already exists');
    }

    $userInfo = $GLOBALS['sessionManager']->getUserInfo();

    Database::query(
        "INSERT INTO tblDiscountCodes
            (code, description, discountType, discountValue, currency, applicableTiers,
             applicablePaymentMethods, minAmount, maxUses, maxUsesPerUser, validFrom, validUntil, isActive, createdBy)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)",
        [
            $code,
            $input['description'] ?? null,
            $discountType,
            $discountValue,
            $input['currency'] ?? 'GBP',
            isset($input['applicableTiers']) ? json_encode($input['applicableTiers']) : null,
            isset($input['applicablePaymentMethods']) ? json_encode($input['applicablePaymentMethods']) : null,
            $input['minAmount'] ?? null,
            $input['maxUses'] ?? null,
            $input['maxUsesPerUser'] ?? 1,
            $input['validFrom'] ?? date('Y-m-d H:i:s'),
            $input['validUntil'] ?? null,
            (int)$userInfo['userID'],
        ],
        'sssdsssdiissi'
    );

    $discountID = Database::getLastInsertId();

    ActivityLogger::log(null, 'discount_created', 'admin', 'info', 'Discount code created: ' . $code, ['discountID' => $discountID, 'code' => $code, 'type' => $discountType, 'value' => $discountValue]);

    echo json_encode(['success' => true, 'discountID' => $discountID, 'message' => 'Discount code created']);
}

/**
 * 📋 List discount codes
 */
function handleListDiscounts() {
    $discounts = Database::fetchAll(
        "SELECT d.*, u.displayName as createdByName
         FROM tblDiscountCodes d
         LEFT JOIN tblUsers u ON d.createdBy = u.userID
         ORDER BY d.createdAt DESC",
        [],
        ''
    );

    foreach ($discounts as &$d) {
        $d['applicableTiers'] = json_decode($d['applicableTiers'], true);
        $d['applicablePaymentMethods'] = json_decode($d['applicablePaymentMethods'], true);
    }

    echo json_encode(['success' => true, 'data' => $discounts]);
}

/**
 * 💸 Process a refund
 */
function handleRefund($input) {
    $paymentID = (int)($input['paymentID'] ?? 0);
    $amount = isset($input['amount']) ? (float)$input['amount'] : null;
    $reason = trim($input['reason'] ?? '');

    if ($paymentID <= 0) {
        throw new Exception('Valid payment ID required');
    }

    $result = PaymentManager::refundPayment($paymentID, $amount, $reason);

    if ($result['success']) {
        echo json_encode(['success' => true, 'message' => $result['message']]);
    } else {
        throw new Exception($result['message']);
    }
}

/**
 * 📦 Update subscription status
 */
function handleUpdateSubscriptionStatus($input) {
    $subscriptionID = (int)($input['subscriptionID'] ?? 0);
    $newStatus = $input['status'] ?? '';

    if ($subscriptionID <= 0) {
        throw new Exception('Valid subscription ID required');
    }

    $validStatuses = ['active', 'cancelled', 'paused', 'expired'];
    if (!in_array($newStatus, $validStatuses, true)) {
        throw new Exception('Invalid status. Valid options: ' . implode(', ', $validStatuses));
    }

    Database::query(
        "UPDATE tblSubscriptions SET subscriptionStatus = ? WHERE subscriptionID = ?",
        [$newStatus, $subscriptionID],
        'si'
    );

    ActivityLogger::log(null, 'subscription_status_changed', 'admin', 'info', 'Subscription #' . $subscriptionID . ' status changed to ' . $newStatus, ['subscriptionID' => $subscriptionID, 'newStatus' => $newStatus]);

    echo json_encode(['success' => true, 'message' => 'Subscription status updated to ' . $newStatus]);
}
