<?php
/**
 * ============================================================================
 * ⚠️ SIGNula - JWT Exception
 * ============================================================================
 *
 * Purpose:
 *   A single, dedicated exception type raised by the Jwt facade (and consumed
 *   by KeyManager/TokenService callers) so a JWT-specific failure can be caught
 *   and mapped to a generic 401 WITHOUT leaking the reason to the client.
 *
 *   Security note (G-003 §6): callers MUST NOT echo getMessage() to the
 *   response body — expired vs forged vs revoked must be indistinguishable to a
 *   client. The message is for the SERVER LOG (tblErrorLog/tblActivityLog) only.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4)
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.8.0-beta (G-003 Stage 1)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * ⚠️ Raised on any JWT signing or verification failure.
 *
 * Extends RuntimeException so existing catch(RuntimeException|Throwable) blocks
 * still handle it, while dedicated catch(JwtException) sites can special-case it.
 */
class JwtException extends RuntimeException
{
}

// ✅ JwtException loaded successfully
