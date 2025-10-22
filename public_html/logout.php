<?php
/**
 * ============================================================================
 * 🚪 SIGNula - Logout Handler
 * ============================================================================
 *
 * Purpose: User logout and session termination
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// 🚀 Bootstrap the application
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 🔐 Logout user
Auth::logout();

// 🔄 Redirect to home page
redirect('/?logout=success');
