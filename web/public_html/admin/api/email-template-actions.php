<?php
/**
 * ============================================================================
 * 🎨 SIGNula - Email Template Designer API Actions
 * ============================================================================
 *
 * AJAX endpoint backing the admin "Email Template Designer" UI
 * (web/public_html/admin/email-templates/index.php). Lets a Super Admin
 * preview an email template rendered with sample data, and save edits to a
 * template's subject/bodyHTML/bodyText — all fully delegated to the existing
 * EmailTemplateManager backend (issue #98 / FG-005).
 *
 * Actions:
 *   - preview_template  — Render a template with sample data (READ-ONLY, GET)
 *   - save_template     — Sanitise + persist subject/bodyHTML/bodyText (POST)
 *
 * Authentication: Super Admin required (matches admin/api/user-actions.php
 * and admin/api/settings-actions.php — the SessionManager + AccessControl
 * pattern used by the majority of admin/api/*.php handlers).
 *
 * 🔗 Dual-purpose file:
 *   This file is also `require`'d directly by
 *   admin/email-templates/index.php's no-JavaScript POST fallback so both
 *   entry points share EXACTLY the same sanitisation/validation helpers
 *   (per CLAUDE.md's "minimise duplication via require" guidance) instead of
 *   maintaining two copies of the save logic. When the caller defines
 *   SIGNULA_TEMPLATE_ACTIONS_INCLUDE_ONLY *before* requiring this file, only
 *   the helper FUNCTIONS below are loaded — the JSON auth-gate/routing
 *   section at the bottom is skipped entirely, so requiring this file from a
 *   normal HTML-rendering page never emits a stray `Content-Type: application/json`
 *   header or a JSON body.
 *
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Admin\API
 * @version    1.0.0
 * @see        web/private_html/email/EmailTemplateManager.php — CRUD + preview/render (reused, not reimplemented)
 * @see        web/private_html/email/EmailSecurityManager.php — subject/bodyText content sanitisation (reused)
 * @see        https://www.php.net/manual/en/mysqli.quickstart.prepared-statements.php
 * @see        https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// ============================================================================
// 🛡️ CONFIG BOOTSTRAP
// ============================================================================
// 🚀 Bootstrap the application (safe no-op if the caller — e.g.
// admin/email-templates/index.php — already did this: guarded by
// require_once, and config.php itself is what define()s SIGNULA_INIT, so we
// deliberately do NOT pre-define it here — doing so would make config.php's
// own `define('SIGNULA_INIT', true)` raise an "already defined" E_WARNING).
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

// ============================================================================
// 📚 LOAD EMAIL BACKEND CLASSES (defensive require — see CLAUDE.md: "checks
// and error handling for REQUIRED/INCLUDED components should be done to
// avoid errors should files not exist")
// ============================================================================
if (!class_exists('EmailTemplateManager')) {
    $templateManagerPath = SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . 'EmailTemplateManager.php';

    if (is_file($templateManagerPath)) {
        require_once $templateManagerPath;
    }
}

if (!class_exists('EmailSecurityManager')) {
    $securityManagerPath = SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . 'EmailSecurityManager.php';

    if (is_file($securityManagerPath)) {
        require_once $securityManagerPath;
    }
}

// ============================================================================
// 🧰 SHARED HELPER FUNCTIONS
// ============================================================================
// These are pure functions with no side effects on request state, so they are
// safe to load unconditionally — whether this file is hit directly as an AJAX
// endpoint, or `require`'d purely for its functions by the designer page's
// no-JavaScript form-POST fallback.

if (!function_exists('signula_extractTemplateVariableTokens')) {
    /**
     * 🔎 Extract `{{variableName}}` Tokens From Template Content
     *
     * Mirrors the token pattern EmailTemplateManager::extractVariables() uses
     * internally (`/\{\{([a-zA-Z0-9_]+)\}\}/`), so that the "unknown variable"
     * warning computed here always agrees with what the manager itself will
     * derive into `requiredVariables` on save. This is plain text *parsing*
     * for validation purposes only — it performs no substitution/rendering,
     * so it does not duplicate any template-rendering logic.
     *
     * @param string $content Raw template content (subject, bodyHTML, or bodyText)
     * @return string[] Unique variable names referenced in $content
     */
    function signula_extractTemplateVariableTokens(string $content): array
    {
        $matches = [];
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $content, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }
}

if (!function_exists('signula_getDeclaredTemplateVariables')) {
    /**
     * 📋 Get a Template's "Declared" Variable Contract
     *
     * tblEmailTemplates has TWO variable-related columns:
     *   - `variables`         — the curated, documented list of variables the
     *                           template is DESIGNED to accept (set at
     *                           creation/seed time — see
     *                           _database/migrations/045_missing_email_templates.sql).
     *   - `requiredVariables` — auto-DERIVED from the current subject/body
     *                           content by EmailTemplateManager itself every
     *                           time it is saved (see extractVariables()).
     *
     * For the variable picker + "unknown variable" save warning we want the
     * curated `variables` list where one exists (it is the intended contract
     * for the template), falling back to `requiredVariables` for templates
     * that predate the `variables` column being populated.
     *
     * @param array $template A row as returned by EmailTemplateManager::getTemplateByID()/getTemplateByKey()
     * @return string[] Declared variable names (may be empty)
     */
    function signula_getDeclaredTemplateVariables(array $template): array
    {
        foreach (['variables', 'requiredVariables'] as $column) {
            if (!empty($template[$column])) {
                $decoded = json_decode((string)$template[$column], true);

                if (is_array($decoded)) {
                    // 🧹 Re-index and coerce to strings defensively — the
                    // column is JSON we do not fully control the origin of.
                    return array_values(array_map('strval', $decoded));
                }
            }
        }

        return [];
    }
}

if (!function_exists('signula_getTemplateVariableDescriptions')) {
    /**
     * 📖 Get a Template's Variable Descriptions (for picker tooltips)
     *
     * @param array $template A row as returned by EmailTemplateManager
     * @return array<string,string> Map of variableName => human description
     */
    function signula_getTemplateVariableDescriptions(array $template): array
    {
        if (!empty($template['variableDescriptions'])) {
            $decoded = json_decode((string)$template['variableDescriptions'], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}

if (!function_exists('signula_sanitizeTemplateBodyHTML')) {
    /**
     * 🧼 Sanitise Admin-Authored Email HTML Before Saving (safe-save pass)
     *
     * 🔐 WHY NOT EmailSecurityManager::sanitizeContent()/sanitizeHTML()?
     * That method's `strip_tags()` allowlist
     * (`<p><br><a><b><strong><i><em><u><h1>-<h6><ul><ol><li><table><tr><td><th><img><span><div>`)
     * was written for sanitising short user-submitted HTML SNIPPETS (e.g. a
     * support message merged into a template). SIGNula's own `bodyHTML`
     * column, however, stores COMPLETE standalone HTML email documents —
     * `<!DOCTYPE html><html><head><meta>/<style>...</head><body>...` — exactly
     * as EmailTemplateBuilder::wrapInLayout() emits them and exactly as
     * `_database/migrations/045_missing_email_templates.sql` seeds them.
     * Running that allowlist over a full document strips the `<!DOCTYPE>`,
     * `<html>`, `<head>`, `<meta>`, `<style>` and `<body>` wrapper entirely —
     * verified empirically while building this feature — which would SILENTLY
     * CORRUPT every existing template the first time an admin opened and
     * re-saved it. That method is therefore reused as-is only for `subject`
     * and `bodyText` (see save_template below), which really are plain
     * strings, via EmailSecurityManager::sanitizeContent().
     *
     * For `bodyHTML` we instead strip only the genuinely dangerous
     * constructs — mirroring the exact techniques EmailSecurityManager and
     * EmailTemplateBuilder::sanitizeCustomCSS() already use elsewhere in this
     * codebase (event-handler attributes, javascript:/vbscript: URLs,
     * <script>/<iframe> blocks) — while leaving every structural/document
     * tag a real HTML email needs completely intact.
     *
     * @param string $html Raw bodyHTML as submitted by the admin
     * @return string Sanitised bodyHTML, safe to persist
     * @see https://owasp.org/www-community/attacks/xss/ — OWASP XSS reference
     */
    function signula_sanitizeTemplateBodyHTML(string $html): string
    {
        // 🚫 Remove <script>...</script> blocks outright (dotall so a
        //    multi-line script body is matched in one go).
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;

        // 🚫 Defensively strip any leftover/unclosed <script ...> open tag.
        $html = preg_replace('/<script\b[^>]*>/i', '', $html) ?? $html;

        // 🚫 Remove <iframe>...</iframe> — not a legitimate HTML-email
        //    element and a common script-smuggling vector.
        $html = preg_replace('/<iframe\b[^>]*>.*?<\/iframe>/is', '', $html) ?? $html;
        $html = preg_replace('/<iframe\b[^>]*>/i', '', $html) ?? $html;

        // 🚫 Remove inline event-handler attributes (onclick=, onload=,
        //    onerror=, ...) in both quoted and unquoted attribute-value form.
        //    Quoted-value variant mirrors EmailSecurityManager::sanitizeHTML().
        $html = preg_replace('/\s+on\w+\s*=\s*"[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace("/\\s+on\\w+\\s*=\\s*'[^']*'/i", '', $html) ?? $html;
        $html = preg_replace('/\s+on\w+\s*=\s*[^\s>]+/i', '', $html) ?? $html;

        // 🚫 Neutralise javascript:/vbscript: URLs used as an href/src/action
        //    target — scoped to the attribute so legitimate text that merely
        //    mentions the word "javascript" elsewhere in the email is left
        //    untouched (unlike EmailSecurityManager's blanket text removal).
        $html = preg_replace(
            '/(href|src|action|formaction|background)(\s*=\s*)(["\'])\s*(javascript|vbscript)\s*:/i',
            '$1$2$3',
            $html
        ) ?? $html;

        return $html;
    }
}

// ============================================================================
// 🚪 EXECUTION GUARD — everything below only runs for a real HTTP request to
// THIS file. When admin/email-templates/index.php requires this file purely
// to reuse the helper functions above (no-JS POST fallback), it defines
// SIGNULA_TEMPLATE_ACTIONS_INCLUDE_ONLY first, so we stop here.
// ============================================================================
if (defined('SIGNULA_TEMPLATE_ACTIONS_INCLUDE_ONLY')) {
    return;
}

// 📚 Session/access-control plumbing shared by admin/api/*.php handlers
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 📋 Set JSON response header
header('Content-Type: application/json; charset=utf-8');

// 🔌 Initialise core services
$db             = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl  = new AccessControl($db, $sessionManager);

// 🔒 Authentication check — must be logged in
if (!$sessionManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 🛡️ Permission check — Super Admin only (email templates are a
// platform-wide, security-sensitive resource — every recipient of every
// SIGNula email is affected by what is saved here).
$accessControl->requireSuperAdmin();

// 📖 Allowlist of READ-ONLY actions that may be served over GET — mirrors
// admin/api/user-actions.php's `$readOnlyGetActions` guard (issue #28): any
// action NOT in this list must arrive via POST so it is forced through the
// Content-Type + CSRF checks below.
$readOnlyGetActions = ['preview_template'];

// 📥 Get request data based on method (GET for reads, POST for writes)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if (!in_array($action, $readOnlyGetActions, true)) {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['success' => false, 'error' => 'This action requires a POST request.']);
        exit;
    }

    $input = [];
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🔐 Validate Content-Type for POST requests to prevent CSRF via
    // ordinary <form> submissions (a browser can only send
    // application/x-www-form-urlencoded or multipart/form-data cross-site).
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        http_response_code(415);
        echo json_encode(['success' => false, 'error' => 'Content-Type must be application/json']);
        exit;
    }

    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
} else {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use GET or POST.']);
    exit;
}

// 🛡️ Verify CSRF token for state-changing (POST) requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!SecurityUtils::verifyCSRFToken($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF token. Please refresh the page.']);
        exit;
    }
}

// 🎯 Route to appropriate handler
try {
    switch ($action) {
        case 'preview_template':
            handlePreviewTemplate($_GET);
            break;

        case 'save_template':
            handleSaveTemplate($input, $accessControl, $sessionManager);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    // 🔐 Never expose raw exception details in API responses (CWE-209)
    if (class_exists('ErrorLogger')) {
        ErrorLogger::logError('API_ERROR', $e->getMessage(), $e->getFile(), $e->getLine());
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'An error occurred processing your request.']);
}

/**
 * 👁️ Preview a Template Rendered With Sample Data
 *
 * 100% delegated to EmailTemplateManager::previewTemplate() — this handler
 * performs no substitution/rendering of its own. `sample` query parameters
 * let the admin override individual auto-generated sample values from the
 * variable picker (e.g. `?sample[displayName]=Jane+Doe`) to see how the
 * template looks with different data.
 *
 * GET parameters:
 * - templateID: int (required)
 * - sample: array<string,string> (optional) — variableName => override value
 */
function handlePreviewTemplate(array $get): void
{
    $templateID = (int)($get['templateID'] ?? 0);

    if ($templateID <= 0) {
        throw new Exception('Invalid template ID');
    }

    $sampleOverrides = $get['sample'] ?? [];
    if (!is_array($sampleOverrides)) {
        $sampleOverrides = [];
    }

    // 🎨 Reuse the manager's existing preview/render path verbatim.
    $preview = EmailTemplateManager::previewTemplate($templateID, $sampleOverrides);

    if ($preview === null) {
        throw new Exception('Template not found');
    }

    echo json_encode(['success' => true, 'message' => 'Preview generated.', 'data' => $preview]);
}

/**
 * 💾 Save Edits to a Template's subject / bodyHTML / bodyText
 *
 * Sanitises the submitted content (see signula_sanitizeTemplateBodyHTML()
 * above + EmailSecurityManager::sanitizeContent() for subject/bodyText),
 * checks the content only references the template's DECLARED variables
 * (warns — does not block — on anything unrecognised), then persists via
 * EmailTemplateManager::updateTemplate() exactly as every other caller of
 * that method does.
 *
 * Required POST (JSON) fields: templateID, subject, bodyHTML, bodyText
 */
function handleSaveTemplate(array $input, AccessControl $accessControl, SessionManager $sessionManager): void
{
    $templateID = (int)($input['templateID'] ?? 0);
    $subject    = (string)($input['subject'] ?? '');
    $bodyHTML   = (string)($input['bodyHTML'] ?? '');
    $bodyText   = (string)($input['bodyText'] ?? '');

    if ($templateID <= 0) {
        throw new Exception('Invalid template ID');
    }

    if (trim($subject) === '' || trim($bodyHTML) === '' || trim($bodyText) === '') {
        throw new Exception('Subject, HTML body, and plain-text body are all required');
    }

    // 🔍 Confirm the template exists before doing any sanitisation work.
    $existing = EmailTemplateManager::getTemplateByID($templateID);
    if (!$existing) {
        throw new Exception('Template not found');
    }

    // 🧼 Sanitise — subject/bodyText via the existing backend sanitiser,
    // bodyHTML via the document-safe helper defined above (see its doc-block
    // for why EmailSecurityManager::sanitizeHTML() is not used for bodyHTML).
    $sanitizedTextFields = EmailSecurityManager::sanitizeContent([
        'subject'  => $subject,
        'bodyText' => $bodyText,
    ]);
    $subject  = $sanitizedTextFields['subject'];
    $bodyText = $sanitizedTextFields['bodyText'];
    $bodyHTML = signula_sanitizeTemplateBodyHTML($bodyHTML);

    // ✅ Validate: warn (do not block) if the content references a variable
    // that is not part of the template's declared contract.
    $usedVariables = array_unique(array_merge(
        signula_extractTemplateVariableTokens($subject),
        signula_extractTemplateVariableTokens($bodyHTML),
        signula_extractTemplateVariableTokens($bodyText)
    ));
    $declaredVariables = signula_getDeclaredTemplateVariables($existing);
    $unknownVariables   = array_values(array_diff($usedVariables, $declaredVariables));

    // 💾 Persist via the existing manager — this recomputes requiredVariables,
    // bumps the version number, and logs to tblActivityLog internally.
    $userID  = (int)$sessionManager->getUserID();
    $success = EmailTemplateManager::updateTemplate(
        $templateID,
        [
            'subject'  => $subject,
            'bodyHTML' => $bodyHTML,
            'bodyText' => $bodyText,
        ],
        $userID
    );

    if (!$success) {
        throw new Exception('Failed to save template');
    }

    // 📝 Admin audit trail (tblAdminAuditLog) — same pattern every other
    // admin/api/*.php mutation handler follows (e.g. user-actions.php).
    $accessControl->logAdminAction('update_email_template', 'email_template', $templateID, [
        'templateKey'      => $existing['templateKey'],
        'unknownVariables' => $unknownVariables,
    ]);

    $message = empty($unknownVariables)
        ? 'Template saved successfully.'
        : 'Template saved successfully, but it references undeclared variable(s): ' . implode(', ', $unknownVariables);

    echo json_encode([
        'success' => true,
        'message' => $message,
        'data'    => [
            'templateID'       => $templateID,
            'unknownVariables' => $unknownVariables,
        ],
    ]);
}
