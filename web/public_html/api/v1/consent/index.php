<?php
/**
 * ============================================================================
 * 🍪 SIGNula - Consent Banner / Preference-Center Endpoint (G-004 Layer 2)
 * ============================================================================
 *
 * Receives a set of per-category consent decisions from EITHER:
 *   - The progressive-enhancement banner script in
 *     web/private_html/layout/public-footer.php (fetch()/XHR, form-encoded
 *     or JSON body), or
 *   - A genuine no-JS <form method="POST"> submit — from the SAME banner
 *     markup (works natively without any JS at all — see the ⚠️ note in
 *     recordBannerChoices()) OR from the full-page fallback at
 *     /legal/consent (web/public_html/SIGNula.com/legal/consent/index.php).
 *
 * Response shape depends on how the client asked:
 *   - JSON body OR X-Requested-With: XMLHttpRequest → {success, data|error} JSON.
 *   - Plain form POST (no JS) → 303 redirect back to `return_to` with a
 *     `?consent=saved` / `?consent=error` query flag (PRG pattern — avoids a
 *     duplicate submission on refresh, and never requires JS to complete).
 *
 * Delegates ALL persistence to ConsentCategoryService::recordBannerChoices(),
 * which in turn delegates to ConsentManager::record() — this file contains
 * NO direct consent-table writes.
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage API\Consent
 * @version    1.0.0
 * @see        web/private_html/compliance/ConsentCategoryService.php
 * @see        web/private_html/compliance/ConsentManager.php
 * @see        web/private_html/layout/public-footer.php — the banner this endpoint serves
 * @see        web/public_html/SIGNula.com/legal/consent/index.php — the no-JS full-page fallback
 * @see        .dev-team/specs/G-004.md §3.1
 * ============================================================================
 */

// ============================================================================
// 🛡️ SIGNULA INIT GUARD
// ============================================================================
if (!defined('SIGNULA_INIT')) {
    define('SIGNULA_INIT', true);
}

// ============================================================================
// 🔧 LOAD APPLICATION CONFIGURATION
// ============================================================================
// ⚠️ PATH DEPTH: __DIR__ here is web/public_html/api/v1/consent/ — this file
// is ONE level deeper than web/public_html/api/v1/index.php (which uses
// dirname(__DIR__, 3) to reach web/), the SAME depth as
// web/public_html/api/v1/export/index.php (which uses dirname(__DIR__, 4) —
// confirmed against that file). Walk: consent/ -> v1/ -> api/ -> public_html/
// -> web/ = 4 levels up from __DIR__.
require_once dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// 📚 web/private_html/compliance/ is NOT covered by config.php's
// spl_autoload_register() directory list (auth/security/utils/api/
// api-controllers/email/database only) — explicit requires, guarded, mirror
// every other compliance-class call site in this codebase.
if (!class_exists('ConsentCategoryService')) {
    require_once ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'compliance'
        . DIRECTORY_SEPARATOR . 'ConsentCategoryService.php';
}
if (!class_exists('ConsentManager')) {
    require_once ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'compliance'
        . DIRECTORY_SEPARATOR . 'ConsentManager.php';
}

// ============================================================================
// 🛡️ RATE LIMITING (CAP-API Bucket B, item #3)
// ============================================================================
// Apply the SAME central rate limiter the /api/v1 router enforces to this
// standalone endpoint (previously bypassed entirely). Fails OPEN on any
// internal limiter error; a genuine over-limit request still gets HTTP 429
// exactly like a router-handled endpoint.
// @see web/private_html/api/RateLimitMiddleware.php::enforceStandalone()
if (!class_exists('RateLimitMiddleware')) {
    require_once ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'api'
        . DIRECTORY_SEPARATOR . 'RateLimitMiddleware.php';
}
RateLimitMiddleware::enforceStandalone();

// ============================================================================
// 🔎 DETECT REQUEST SHAPE
// ============================================================================
$contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
$isJsonRequest = str_contains(strtolower($contentType), 'application/json');
$wantsJsonResponse = $isJsonRequest || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($wantsJsonResponse) {
        jsonResponse(false, 'Method not allowed. Use POST.', [], 405);
    }
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// 📥 Parse input — JSON body OR ordinary form-encoded $_POST (both accepted,
// per spec §3.1 "banner choices POST ... (JSON or form-encoded)").
if ($isJsonRequest) {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    $input = is_array($decoded) ? $decoded : [];
} else {
    $input = $_POST;
}

/**
 * 🔀 Emit an error in whichever shape the caller asked for, then exit.
 * (redirect()/jsonResponse() both terminate the script themselves — this
 * wrapper just picks which one to call and builds the redirect target.)
 *
 * @param string $message
 * @param int    $statusCode
 * @return never
 */
$emitError = static function (string $message, int $statusCode) use ($wantsJsonResponse, $input): void {
    if ($wantsJsonResponse) {
        jsonResponse(false, $message, [], $statusCode);
    }

    $returnTo = sanitizeRedirectUrl((string) ($input['return_to'] ?? '/'), '/');
    $separator = str_contains($returnTo, '?') ? '&' : '?';
    redirect($returnTo . $separator . 'consent=error');
};

// ============================================================================
// 🛡️ CSRF
// ============================================================================
if (!SecurityUtils::verifyCSRFToken($input['csrf_token'] ?? '')) {
    $emitError('Invalid security token. Please try again.', 419);
    exit; // defensive — real redirect()/jsonResponse() already exit()
}

// ============================================================================
// 👤 RESOLVE IDENTITY — userID (if logged in) + visitorID (always)
// ============================================================================
$userID = null;
if (class_exists('Auth') && Auth::isAuthenticated()) {
    $currentUser = Auth::getCurrentUser();
    $userID = isset($currentUser['userID']) ? (int) $currentUser['userID'] : null;
}

$visitorID = ConsentCategoryService::issueOrGetVisitorID();

// ============================================================================
// 📝 BUILD THE PER-CATEGORY DECISION MAP
// ============================================================================
// acceptMode lets a single "Accept All" / "Necessary Only" submit button
// short-circuit the individual checkbox states — both are real, native HTML
// <button name="accept_mode" value="..."> submits, so this works identically
// with or without JavaScript (see public-footer.php's banner markup).
$acceptMode = is_string($input['accept_mode'] ?? null) ? $input['accept_mode'] : 'custom';
$categories = ConsentCategoryService::getActiveCategories();

$decisions = [];
if ($acceptMode === 'all') {
    foreach ($categories as $category) {
        $decisions[(string) $category['categoryKey']] = true;
    }
} elseif ($acceptMode === 'necessary_only') {
    // 🈳 Deliberately empty — recordBannerChoices() treats a missing key as
    // "not granted" for every non-necessary category, and forces 'necessary'
    // granted=true regardless either way.
} else {
    $rawCategories = $input['categories'] ?? [];
    if (is_array($rawCategories)) {
        foreach ($rawCategories as $key => $value) {
            $decisions[(string) $key] = ConsentCategoryService::isTruthyInput($value);
        }
    }
}

// ============================================================================
// 💾 PERSIST (the ONE call that writes consent rows — via ConsentManager)
// ============================================================================
ConsentCategoryService::recordBannerChoices($decisions, $userID, $visitorID);

// 🔗 If the subject is logged in, stitch any prior anonymous decisions under
// this visitorID onto their userID — keeps the audit trail continuous.
if ($userID !== null) {
    ConsentManager::reconcileVisitorToUser($visitorID, $userID);
}

// ============================================================================
// ✅ RESPOND
// ============================================================================
if ($wantsJsonResponse) {
    jsonResponse(true, 'Consent preferences saved.', ['visitorID' => $visitorID]);
}

$returnTo = sanitizeRedirectUrl((string) ($input['return_to'] ?? '/'), '/');
$separator = str_contains($returnTo, '?') ? '&' : '?';
redirect($returnTo . $separator . 'consent=saved');
