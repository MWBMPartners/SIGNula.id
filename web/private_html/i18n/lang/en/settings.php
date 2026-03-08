<?php
/**
 * ============================================================================
 * SIGNula - English Translations: Settings / Account Management
 * ============================================================================
 *
 * Purpose: Translation strings for the account settings and management pages
 *          in the English (en) locale.
 *
 * Usage:   Loaded by I18n::translate('settings.key_name') - the 'settings'
 *          prefix maps to this file automatically.
 *
 * @package    SIGNula
 * @subpackage i18n
 * @version    1.0.0
 * @see        web/private_html/i18n/I18n.php - Translation engine
 * @see        web/public_html/account/ - Account settings pages
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

return [

    // ========================================================================
    // 📋 Settings Navigation / Sections
    // ========================================================================

    'title'                    => 'Account Settings',
    'profile'                  => 'Profile',
    'security'                 => 'Security',
    'passkeys'                 => 'PassKeys',
    'two_factor'               => 'Two-Factor Auth',
    'recovery_keys'            => 'Recovery Keys',
    'connected_accounts'       => 'Connected Accounts',
    'activity_log'             => 'Activity Log',
    'privacy'                  => 'Privacy',
    'notifications'            => 'Notifications',
    'notification_preferences' => 'Notification Preferences',
    'export_data'              => 'Export Data',
    'delete_account'           => 'Delete Account',
    'danger_zone'              => 'Danger Zone',

    // ========================================================================
    // 👤 Profile Settings
    // ========================================================================

    'profile.display_name' => 'Display Name',
    'profile.email'        => 'Email Address',
    'profile.username'     => 'Username',
    'profile.avatar'       => 'Profile Picture',
    'profile.save'         => 'Save Profile',
    'profile.updated'      => 'Profile updated successfully.',

    // ========================================================================
    // 🔒 Security Settings
    // ========================================================================

    'security.score'              => 'Security Score',
    'security.score_excellent'    => 'Excellent! Your account security is strong.',
    'security.score_good'         => 'Good, but there\'s room for improvement.',
    'security.score_weak'         => 'Weak - Please strengthen your account security.',
    'security.change_password'    => 'Change Password',
    'security.auth_methods'       => 'Authentication Methods',
    'security.recent_activity'    => 'Recent Login Activity',
    'security.view_all_activity'  => 'View All Activity',

];
