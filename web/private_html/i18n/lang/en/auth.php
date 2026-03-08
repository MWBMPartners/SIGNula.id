<?php
/**
 * ============================================================================
 * SIGNula - English Translations: Authentication
 * ============================================================================
 *
 * Purpose: Authentication-related translation strings for the English (en) locale.
 *          Covers sign-in, sign-up, password management, MFA, and auth messages.
 *
 * Usage:   Loaded by I18n::translate('auth.key_name') - the 'auth' prefix
 *          maps to this file automatically.
 *
 * @package    SIGNula
 * @subpackage i18n
 * @version    1.0.0
 * @see        web/private_html/i18n/I18n.php - Translation engine
 * @see        web/private_html/auth/Auth.php - Authentication class
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

return [

    // ========================================================================
    // 🔐 Sign In / Sign Up / Sign Out
    // ========================================================================

    'sign_in'              => 'Sign In',
    'sign_up'              => 'Sign Up',
    'sign_out'             => 'Sign Out',
    'email_or_username'    => 'Email or Username',
    'password'             => 'Password',
    'confirm_password'     => 'Confirm Password',
    'current_password'     => 'Current Password',
    'new_password'         => 'New Password',
    'remember_me'          => 'Remember me',
    'forgot_password'      => 'Forgot password?',
    'reset_password'       => 'Reset Password',
    'change_password'      => 'Change Password',
    'create_account'       => 'Create Account',
    'already_have_account' => 'Already have an account?',
    'dont_have_account'    => "Don't have an account?",
    'sign_in_with'         => 'Sign in with :provider',
    'or_continue_with'     => 'or continue with',
    'magic_link'           => 'Send me a magic link instead',
    'back_to_login'        => 'Back to Sign In',
    'back_to_home'         => 'Back to Home',

    // ========================================================================
    // 🛡️ Multi-Factor Authentication (MFA)
    // ========================================================================

    'mfa.title'           => 'Two-Factor Authentication',
    'mfa.enter_code'      => 'Enter your authentication code',
    'mfa.use_backup_code' => 'Use a backup code',
    'mfa.verify'          => 'Verify',
    'mfa.setup'           => 'Set Up 2FA',
    'mfa.enabled'         => '2FA is enabled',
    'mfa.disabled'        => '2FA is not enabled',

    // ========================================================================
    // 🔑 Password Requirements
    // ========================================================================

    'password.requirements' => 'Password Requirements:',
    'password.min_length'   => 'At least :count characters long',
    'password.uppercase'    => 'At least one uppercase letter',
    'password.lowercase'    => 'At least one lowercase letter',
    'password.number'       => 'At least one number',
    'password.special'      => 'At least one special character',
    'password.history'      => 'Cannot reuse your last :count passwords',

    // ========================================================================
    // 💬 Auth Messages
    // ========================================================================

    'login_success'        => 'You have been signed in successfully.',
    'login_failed'         => 'Invalid email/username or password.',
    'logout_success'       => 'You have been signed out.',
    'register_success'     => 'Account created successfully! Please verify your email.',
    'password_changed'     => 'Your password has been changed successfully.',
    'password_reset_sent'  => 'Password reset instructions have been sent to your email.',
    'password_reset_success' => 'Your password has been reset successfully.',
    'account_locked'       => 'Your account has been temporarily locked due to too many failed attempts.',
    'email_verified'       => 'Your email has been verified successfully.',
    'verification_sent'    => 'A verification email has been sent.',

];
