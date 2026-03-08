<?php
/**
 * ============================================================================
 * SIGNula - English Translations: Error Messages
 * ============================================================================
 *
 * Purpose: User-facing error message translations for the English (en) locale.
 *          Covers HTTP errors, validation errors, and system error messages.
 *
 * Usage:   Loaded by I18n::translate('errors.key_name') - the 'errors' prefix
 *          maps to this file automatically.
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
    // 🚨 General Errors
    // ========================================================================

    'generic'          => 'An unexpected error occurred. Please try again later.',
    'not_found'        => 'Page not found.',
    'forbidden'        => 'Access denied.',
    'server_error'     => 'Internal server error.',
    'maintenance'      => 'The system is currently under maintenance. Please try again later.',
    'invalid_request'  => 'Invalid request.',
    'invalid_input'    => 'Please check your input and try again.',
    'connection_error' => 'Could not connect to the server.',
    'timeout'          => 'The request timed out. Please try again.',

    // ========================================================================
    // 📝 Validation Errors
    // ========================================================================

    'required_field'     => 'This field is required.',
    'invalid_email'      => 'Please enter a valid email address.',
    'invalid_password'   => 'Password does not meet requirements.',
    'passwords_mismatch' => 'Passwords do not match.',
    'email_taken'        => 'This email address is already registered.',
    'username_taken'     => 'This username is already taken.',

    // ========================================================================
    // 🔗 Token / Link Errors
    // ========================================================================

    'token_expired' => 'This link has expired. Please request a new one.',
    'token_invalid' => 'Invalid or expired link.',

    // ========================================================================
    // 📁 File / Upload Errors
    // ========================================================================

    'file_too_large'    => 'File size exceeds the maximum allowed (:max).',
    'file_type_invalid' => 'This file type is not allowed.',
    'upload_failed'     => 'File upload failed. Please try again.',

    // ========================================================================
    // 🔒 Access / Permission Errors
    // ========================================================================

    'permission_denied' => 'You do not have permission to perform this action.',
    'account_disabled'  => 'Your account has been disabled. Please contact support.',
    'ip_blocked'        => 'Access from your IP address has been blocked.',
    'feature_disabled'  => 'This feature is currently disabled.',
    'quota_exceeded'    => 'You have exceeded your quota. Please upgrade your plan.',

];
