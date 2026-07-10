<?php
/**
 * Access Control System
 *
 * Purpose: Multi-tier role-based access control
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 */

class AccessControl {
    private $db;
    private $sessionManager;
    private $userID;
    private $userInfo;
    private $partnerMemberships = [];

    // Role hierarchy (higher = more permissions)
    const ROLE_HIERARCHY = [
        'super-admin' => 100,
        'root-admin' => 80,
        'admin' => 60,
        'developer' => 40,
        'support' => 30,
        'finance' => 20,
        'user' => 10
    ];

    /**
     * Constructor
     */
    public function __construct($db, $sessionManager) {
        $this->db = $db;
        $this->sessionManager = $sessionManager;

        if ($sessionManager->isLoggedIn()) {
            $this->userID = $sessionManager->getUserID();
            $this->userInfo = $sessionManager->getUserInfo();
            $this->loadPartnerMemberships();
        }
    }

    /**
     * Load user's partner memberships
     */
    private function loadPartnerMemberships() {
        // 🐛 B-051 fix: tblPartners has NO companyName / tier / status columns —
        // the real columns are partnerName / accountTier / isActive (verified
        // against _database/migrations). Alias them back to the legacy key
        // names so every downstream consumer of
        // $this->partnerMemberships[...]['companyName'/'tier'/'partnerStatus']
        // keeps working unchanged.
        $stmt = $this->db->prepare("
            SELECT tm.*, p.partnerName AS companyName, p.accountTier AS tier, p.isActive AS partnerStatus
            FROM tblPartnerTeamMembers tm
            JOIN tblPartners p ON tm.partnerID = p.partnerID
            WHERE tm.userID = ? AND tm.status = 'active'
        ");
        $stmt->bind_param('i', $this->userID);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $this->partnerMemberships[$row['partnerID']] = $row;
        }
    }

    /**
     * Check if user is Super Admin
     */
    public function isSuperAdmin() {
        return isset($this->userInfo['isSuperAdmin']) && $this->userInfo['isSuperAdmin'] == 1;
    }

    /**
     * Check if user is admin (any level)
     */
    public function isAdmin() {
        return $this->isSuperAdmin() || !empty($this->partnerMemberships);
    }

    /**
     * Check if user is root admin for specific partner
     */
    public function isPartnerRootAdmin($partnerID) {
        return isset($this->partnerMemberships[$partnerID]) &&
               $this->partnerMemberships[$partnerID]['isRootAdmin'] == 1;
    }

    /**
     * Check if user is partner admin (root or admin)
     */
    public function isPartnerAdmin($partnerID) {
        if ($this->isSuperAdmin()) return true;

        return isset($this->partnerMemberships[$partnerID]) &&
               in_array($this->partnerMemberships[$partnerID]['role'], ['root-admin', 'admin']);
    }

    /**
     * Check if user has specific role for partner
     */
    public function hasPartnerRole($partnerID, $requiredRole) {
        if ($this->isSuperAdmin()) return true;

        if (!isset($this->partnerMemberships[$partnerID])) {
            return false;
        }

        $userRole = $this->partnerMemberships[$partnerID]['role'];
        $userLevel = self::ROLE_HIERARCHY[$userRole] ?? 0;
        $requiredLevel = self::ROLE_HIERARCHY[$requiredRole] ?? 0;

        return $userLevel >= $requiredLevel;
    }

    /**
     * Get all partner memberships for user
     */
    public function getPartnerMemberships() {
        return $this->partnerMemberships;
    }

    /**
     * Get user's role for specific partner
     */
    public function getPartnerRole($partnerID) {
        return $this->partnerMemberships[$partnerID]['role'] ?? null;
    }

    /**
     * Check if user can access partner
     */
    public function canAccessPartner($partnerID) {
        return $this->isSuperAdmin() || isset($this->partnerMemberships[$partnerID]);
    }

    /**
     * Check if feature is enabled for partner
     */
    public function isFeatureEnabled($featureKey, $partnerID = null) {
        // Get global feature setting
        $stmt = $this->db->prepare("
            SELECT isEnabledGlobally, canPartnersToggle
            FROM tblFeatureToggles
            WHERE featureKey = ?
        ");
        $stmt->bind_param('s', $featureKey);
        $stmt->execute();
        $feature = $stmt->get_result()->fetch_assoc();

        if (!$feature) return false;

        // If globally disabled, feature is off for everyone
        if (!$feature['isEnabledGlobally']) return false;

        // If no partner specified, return global setting
        if ($partnerID === null) return true;

        // Check partner-specific override
        $stmt = $this->db->prepare("
            SELECT isEnabled
            FROM tblPartnerFeatures
            WHERE partnerID = ? AND featureID = (
                SELECT featureID FROM tblFeatureToggles WHERE featureKey = ?
            )
        ");
        $stmt->bind_param('is', $partnerID, $featureKey);
        $stmt->execute();
        $override = $stmt->get_result()->fetch_assoc();

        return $override ? $override['isEnabled'] : true;
    }

    /**
     * Require super admin access
     */
    public function requireSuperAdmin() {
        if (!$this->isSuperAdmin()) {
            http_response_code(403);
            die('Super Admin access required');
        }
    }

    /**
     * Require partner admin access
     */
    public function requirePartnerAdmin($partnerID) {
        if (!$this->isPartnerAdmin($partnerID)) {
            http_response_code(403);
            die('Partner Admin access required');
        }
    }

    /**
     * Require specific partner role
     */
    public function requirePartnerRole($partnerID, $role) {
        if (!$this->hasPartnerRole($partnerID, $role)) {
            http_response_code(403);
            die("Insufficient permissions. Required: $role");
        }
    }

    /**
     * Log admin action
     */
    public function logAdminAction($action, $targetType = null, $targetID = null, $changes = null) {
        $adminLevel = $this->isSuperAdmin() ? 'super-admin' : 'partner-admin';
        $changesJSON = $changes ? json_encode($changes) : null;

        $stmt = $this->db->prepare("
            INSERT INTO tblAdminAuditLog
            (userID, adminLevel, action, targetType, targetID, changes, ipAddress, userAgent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt->bind_param('isssisss',
            $this->userID,
            $adminLevel,
            $action,
            $targetType,
            $targetID,
            $changesJSON,
            $ip,
            $ua
        );

        return $stmt->execute();
    }

    /**
     * Get max team members for partner tier
     */
    public function getMaxTeamMembers($tier) {
        $limits = [
            'free' => $this->getSetting('partners.max_team_members_free', 5),
            'basic' => $this->getSetting('partners.max_team_members_basic', 10),
            'premium' => $this->getSetting('partners.max_team_members_premium', 25),
            'enterprise' => $this->getSetting('partners.max_team_members_enterprise', 0) // 0 = unlimited
        ];

        return $limits[$tier] ?? 5;
    }

    // ========================================================================
    // 💰 PAYMENT & FINANCE ROLE CHECKS (v2.4.0)
    // ========================================================================

    /**
     * 💰 Check if user can manage partner payment settings
     *
     * Returns true if the user has finance role or higher for the specified partner,
     * OR if the user is a super admin.
     *
     * Payment management includes: configuring payment providers, managing API keys,
     * creating subscription tiers, managing discount codes, viewing transactions.
     *
     * @param int $partnerID Partner ID
     * @return bool True if user can manage partner payments
     *
     * @since 2.4.0-beta
     */
    public function canManagePartnerPayments($partnerID) {
        // 🔑 Super admins can manage all partner payments
        if ($this->isSuperAdmin()) {
            return true;
        }

        // 💼 Finance role or higher (finance=20, support=30, developer=40, admin=60, root-admin=80)
        return $this->hasPartnerRole($partnerID, 'finance');
    }

    /**
     * 📊 Check if user can view partner financial data
     *
     * Returns true if the user has finance role or higher for the specified partner,
     * OR if the user is a super admin.
     *
     * Financial data includes: earnings reports, fee transactions, remittances,
     * invoices, credit balances, payment history.
     *
     * @param int $partnerID Partner ID
     * @return bool True if user can view partner financials
     *
     * @since 2.4.0-beta
     */
    public function canViewPartnerFinancials($partnerID) {
        // 🔑 Super admins can view all financials
        if ($this->isSuperAdmin()) {
            return true;
        }

        // 💼 Finance role or higher has read access to financial data
        return $this->hasPartnerRole($partnerID, 'finance');
    }

    /**
     * 🔒 Require partner payment management access
     *
     * Dies with 403 if the user cannot manage partner payments.
     *
     * @param int $partnerID Partner ID
     *
     * @since 2.4.0-beta
     */
    public function requirePartnerPaymentAccess($partnerID) {
        if (!$this->canManagePartnerPayments($partnerID)) {
            http_response_code(403);
            die('Insufficient permissions to manage partner payment settings');
        }
    }

    /**
     * 📊 Require partner financial data access
     *
     * Dies with 403 if the user cannot view partner financials.
     *
     * @param int $partnerID Partner ID
     *
     * @since 2.4.0-beta
     */
    public function requirePartnerFinancialAccess($partnerID) {
        if (!$this->canViewPartnerFinancials($partnerID)) {
            http_response_code(403);
            die('Insufficient permissions to view partner financial data');
        }
    }

    /**
     * Get setting value
     */
    private function getSetting($key, $default = null) {
        $stmt = $this->db->prepare("SELECT settingValue FROM tblSettings WHERE settingKey = ?");
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return $result ? $result['settingValue'] : $default;
    }
}
