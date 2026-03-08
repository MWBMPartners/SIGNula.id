<?php
/**
 * ============================================================================
 * SIGNula - English Translations: General Messages
 * ============================================================================
 *
 * Purpose: General UI translation strings for the English (en) locale.
 *          These are the default/base translations used across the application.
 *
 * Usage:   Loaded automatically by the I18n class when the 'messages' domain
 *          is requested (which is the default domain for all translations).
 *
 * Format:  Keys use dot notation for namespacing (e.g., 'nav.home').
 *          Parameters are denoted with :paramName (e.g., :name, :count).
 *          Pluralisation uses pipe separator (e.g., 'one item|:count items').
 *
 * @package    SIGNula
 * @subpackage i18n
 * @version    1.0.0
 * @see        web/private_html/i18n/I18n.php - Translation engine
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

return [

    // ========================================================================
    // 🏷️ General / Common Labels
    // ========================================================================

    'app_name'   => 'SIGNula',
    'tagline'    => 'Universal Authentication System',
    'welcome'    => 'Welcome, :name!',
    'loading'    => 'Loading...',
    'save'       => 'Save',
    'cancel'     => 'Cancel',
    'delete'     => 'Delete',
    'edit'       => 'Edit',
    'create'     => 'Create',
    'update'     => 'Update',
    'close'      => 'Close',
    'confirm'    => 'Confirm',
    'back'       => 'Back',
    'next'       => 'Next',
    'previous'   => 'Previous',
    'submit'     => 'Submit',
    'search'     => 'Search',
    'filter'     => 'Filter',
    'reset'      => 'Reset',
    'export'     => 'Export',
    'import'     => 'Import',
    'download'   => 'Download',
    'upload'     => 'Upload',
    'enable'     => 'Enable',
    'disable'    => 'Disable',
    'enabled'    => 'Enabled',
    'disabled'   => 'Disabled',
    'yes'        => 'Yes',
    'no'         => 'No',
    'or'         => 'or',
    'and'        => 'and',
    'none'       => 'None',
    'all'        => 'All',
    'actions'    => 'Actions',
    'status'     => 'Status',
    'details'    => 'Details',
    'view'       => 'View',
    'manage'     => 'Manage',
    'active'     => 'Active',
    'inactive'   => 'Inactive',
    'pending'    => 'Pending',
    'success'    => 'Success',
    'error'      => 'Error',
    'warning'    => 'Warning',
    'info'       => 'Info',

    // ========================================================================
    // 🧭 Navigation
    // ========================================================================

    'nav.home'      => 'Home',
    'nav.dashboard' => 'Dashboard',
    'nav.settings'  => 'Settings',
    'nav.profile'   => 'Profile',
    'nav.security'  => 'Security',
    'nav.logout'    => 'Logout',
    'nav.login'     => 'Sign In',
    'nav.register'  => 'Sign Up',

    // ========================================================================
    // 💬 Common Messages
    // ========================================================================

    'saved_successfully'   => 'Changes saved successfully.',
    'deleted_successfully' => 'Item deleted successfully.',
    'error_occurred'       => 'An error occurred. Please try again.',
    'unauthorized'         => 'You are not authorized to perform this action.',
    'not_found'            => 'The requested resource was not found.',
    'session_expired'      => 'Your session has expired. Please sign in again.',
    'csrf_invalid'         => 'Invalid security token. Please try again.',
    'rate_limited'         => 'Too many requests. Please wait before trying again.',

    // ========================================================================
    // 🕐 Dates and Time (Relative)
    // ========================================================================

    'just_now'    => 'just now',
    'minutes_ago' => ':count minute ago|:count minutes ago',
    'hours_ago'   => ':count hour ago|:count hours ago',
    'days_ago'    => ':count day ago|:count days ago',
    'yesterday'   => 'yesterday',
    'today'       => 'today',
    'tomorrow'    => 'tomorrow',

    // ========================================================================
    // 📄 Pagination
    // ========================================================================

    'showing_results' => 'Showing :from to :to of :total results',
    'no_results'      => 'No results found.',
    'load_more'       => 'Load More',

];
