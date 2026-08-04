<?php
/**
 * ============================================================================
 * 🎨 SIGNula - Email Template Designer (Admin)
 * ============================================================================
 *
 * Purpose: Lets a Super Admin browse every row in `tblEmailTemplates`, edit a
 * template's subject / bodyHTML / bodyText using a variable picker, and see a
 * live preview rendered with sample data — the brief-explicit "ability for a
 * user/admin to design their own Templates" (issue #98 / FG-005).
 *
 * 🔗 GIRFT: this page performs NO template rendering or variable-substitution
 * logic of its own. Preview + persistence are 100% delegated to the existing
 * EmailTemplateManager backend (web/private_html/email/EmailTemplateManager.php);
 * sanitisation reuses EmailSecurityManager + the shared helpers defined in
 * admin/api/email-template-actions.php (required below for those helpers
 * only, so the no-JavaScript POST fallback below and the AJAX endpoint stay
 * in perfect agreement instead of drifting apart as two copies of the same
 * logic).
 *
 * 🧩 Progressive enhancement: every feature on this page (browsing, editing,
 * saving, and previewing) works with a plain HTML <form> POST/GET and a full
 * page reload if JavaScript is unavailable. When JavaScript IS available,
 * the save form is submitted via fetch() to admin/api/email-template-actions.php
 * instead, and the preview pane refreshes in place afterwards — see the
 * <script> block at the bottom of this file.
 *
 * @see https://getbootstrap.com/docs/5.3/ — Bootstrap 5.3 Documentation
 * @see https://fontawesome.com/icons — FontAwesome 6.4 Icons
 * @see web/private_html/email/EmailTemplateManager.php
 * @see web/public_html/admin/api/email-template-actions.php
 *
 * Copyright © 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * @package    SIGNula
 * @version    1.0.0
 * ============================================================================
 */

// ============================================================================
// 🚀 BOOTSTRAP
// ============================================================================
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'SessionManager.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . '_backend' . DIRECTORY_SEPARATOR . 'AccessControl.php';

// 📚 Load email backend classes (defensive require — file may not exist on
// every deployment stage; fail gracefully rather than a raw fatal error).
if (!class_exists('EmailTemplateManager')) {
    $templateManagerPath = SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . 'EmailTemplateManager.php';

    if (is_file($templateManagerPath)) {
        require_once $templateManagerPath;
    } else {
        http_response_code(500);
        die('Email template backend is not available. Please contact a system administrator.');
    }
}

if (!class_exists('EmailSecurityManager')) {
    $securityManagerPath = SIGNULA_ROOT . DIRECTORY_SEPARATOR . 'private_html'
        . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . 'EmailSecurityManager.php';

    if (is_file($securityManagerPath)) {
        require_once $securityManagerPath;
    }
}

// 🧰 Pull in the sanitisation/validation helper functions shared with the
// AJAX endpoint WITHOUT executing that file's own auth-gate/JSON routing —
// see the "EXECUTION GUARD" section near the bottom of that file.
define('SIGNULA_TEMPLATE_ACTIONS_INCLUDE_ONLY', true);
$templateActionsHelperPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'email-template-actions.php';
if (is_file($templateActionsHelperPath)) {
    require_once $templateActionsHelperPath;
} else {
    http_response_code(500);
    die('Email template designer helpers are not available. Please contact a system administrator.');
}

// ============================================================================
// 🔌 SESSION / ACCESS CONTROL
// ============================================================================
$db             = Database::getInstance();
$sessionManager = new SessionManager($db);
$accessControl  = new AccessControl($db, $sessionManager);

// 🔒 Authentication check
if (!$sessionManager->isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

// 🛡️ Super Admin check — templates are a platform-wide, security-sensitive
// resource (every recipient of every SIGNula email is affected by this page).
$accessControl->requireSuperAdmin();

$currentUserID = (int)$sessionManager->getUserID();

// ============================================================================
// 📝 NO-JAVASCRIPT FORM-POST FALLBACK (progressive enhancement baseline)
// ============================================================================
// When JavaScript is available, the same "save_template" action is instead
// submitted via fetch() to admin/api/email-template-actions.php (see the
// <script> block below) — but the full logic here MUST keep working on its
// own for browsers/users without JavaScript, per the project brief.
$flashMessage = null;
$flashType    = 'info'; // success | warning | danger | info

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_template') {
    // 🛡️ Verify CSRF token
    if (!SecurityUtils::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $flashMessage = 'Invalid or expired security token. Please refresh the page and try again.';
        $flashType    = 'danger';
    } else {
        try {
            $postedTemplateID = (int)($_POST['templateID'] ?? 0);
            $postedSubject    = (string)($_POST['subject'] ?? '');
            $postedBodyHTML   = (string)($_POST['bodyHTML'] ?? '');
            $postedBodyText   = (string)($_POST['bodyText'] ?? '');

            if ($postedTemplateID <= 0) {
                throw new RuntimeException('Invalid template ID');
            }

            if (trim($postedSubject) === '' || trim($postedBodyHTML) === '' || trim($postedBodyText) === '') {
                throw new RuntimeException('Subject, HTML body, and plain-text body are all required');
            }

            $existingTemplate = EmailTemplateManager::getTemplateByID($postedTemplateID);
            if (!$existingTemplate) {
                throw new RuntimeException('Template not found');
            }

            // 🧼 Sanitise — identical helpers to admin/api/email-template-actions.php
            // (see that file's handleSaveTemplate()/signula_sanitizeTemplateBodyHTML()
            // doc-blocks for the full rationale).
            $sanitizedTextFields = EmailSecurityManager::sanitizeContent([
                'subject'  => $postedSubject,
                'bodyText' => $postedBodyText,
            ]);
            $postedSubject  = $sanitizedTextFields['subject'];
            $postedBodyText = $sanitizedTextFields['bodyText'];
            $postedBodyHTML = signula_sanitizeTemplateBodyHTML($postedBodyHTML);

            // ✅ Warn (do not block) on undeclared variables
            $usedVariables = array_unique(array_merge(
                signula_extractTemplateVariableTokens($postedSubject),
                signula_extractTemplateVariableTokens($postedBodyHTML),
                signula_extractTemplateVariableTokens($postedBodyText)
            ));
            $declaredVariablesForDiff = signula_getDeclaredTemplateVariables($existingTemplate);
            $unknownVariables         = array_values(array_diff($usedVariables, $declaredVariablesForDiff));

            $saveSuccess = EmailTemplateManager::updateTemplate(
                $postedTemplateID,
                [
                    'subject'  => $postedSubject,
                    'bodyHTML' => $postedBodyHTML,
                    'bodyText' => $postedBodyText,
                ],
                $currentUserID
            );

            if (!$saveSuccess) {
                throw new RuntimeException('Failed to save template');
            }

            // 📝 Admin audit trail — same call the AJAX endpoint makes.
            $accessControl->logAdminAction('update_email_template', 'email_template', $postedTemplateID, [
                'templateKey'      => $existingTemplate['templateKey'],
                'unknownVariables' => $unknownVariables,
            ]);

            if (!empty($unknownVariables)) {
                $flashMessage = 'Template saved successfully, but it references undeclared variable(s): '
                    . implode(', ', $unknownVariables);
                $flashType = 'warning';
            } else {
                $flashMessage = 'Template saved successfully.';
                $flashType    = 'success';
            }

            // 🔁 Keep editing the same template after the reload.
            $_GET['templateID'] = $postedTemplateID;

        } catch (Exception $e) {
            // 🛡️ Catch broadly (not just RuntimeException) — mirrors
            // admin/email-config.php's own inline POST-handler pattern, since
            // a lower-level DB/backend failure could surface as any Exception
            // subtype, not only the RuntimeException thrown directly above.
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            }
            $flashMessage = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            $flashType    = 'danger';
        }
    }
}

// ============================================================================
// 📋 LIST TEMPLATES (with optional search + category filter — GET, works
// with or without JavaScript since it is a plain query-string form)
// ============================================================================
$searchTerm      = trim((string)($_GET['search'] ?? ''));
$categoryFilter  = trim((string)($_GET['category'] ?? ''));

$allTemplates = EmailTemplateManager::getAllTemplates(
    $categoryFilter !== '' ? $categoryFilter : null,
    true // includeInactive — admins need to be able to re-enable/edit disabled templates too
);

// 🏷️ Build the category dropdown options directly from what actually exists
// in the data, rather than a hard-coded list (CLAUDE.md: avoid hard-coding
// what the database can tell us).
$availableCategories = array_values(array_unique(array_filter(array_map(
    static fn(array $t): string => (string)($t['category'] ?? ''),
    $allTemplates
))));
sort($availableCategories);

// 🔍 Client-independent text search (dataset is small — admin template count
// is in the tens, not the thousands — so filtering in PHP is appropriate
// without adding a new search parameter to EmailTemplateManager itself).
if ($searchTerm !== '') {
    $needle = mb_strtolower($searchTerm);
    $allTemplates = array_values(array_filter($allTemplates, static function (array $t) use ($needle): bool {
        return str_contains(mb_strtolower((string)$t['templateKey']), $needle)
            || str_contains(mb_strtolower((string)$t['templateName']), $needle);
    }));
}

// ============================================================================
// 🎯 SELECTED TEMPLATE (for the edit + preview panes)
// ============================================================================
$selectedTemplateID = (int)($_GET['templateID'] ?? 0);
$selectedTemplate   = null;
$notFoundNotice     = false;

if ($selectedTemplateID > 0) {
    $selectedTemplate = EmailTemplateManager::getTemplateByID($selectedTemplateID);
    if (!$selectedTemplate) {
        $notFoundNotice      = true;
        $selectedTemplateID  = 0;
    }
}

$declaredVariables      = [];
$variableDescriptions   = [];
$preview                = null;

if ($selectedTemplate !== null) {
    $declaredVariables    = signula_getDeclaredTemplateVariables($selectedTemplate);
    $variableDescriptions = signula_getTemplateVariableDescriptions($selectedTemplate);

    // 👁️ Reuse EmailTemplateManager's existing preview/render path verbatim —
    // this ALWAYS reflects the currently-saved content, so a normal page
    // reload after a no-JS save shows the freshly-saved preview automatically.
    $preview = EmailTemplateManager::previewTemplate($selectedTemplateID);
}

// 🛡️ Fresh CSRF token for this render (form + JS both use this one value).
$csrfToken = SecurityUtils::generateCSRFToken();

$pageTitle = 'Email Template Designer';

// 🔧 Small local helper — keeps the markup below readable.
$h = static fn(?string $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $h($pageTitle) ?> - SIGNula Admin</title>

    <!-- 🎨 Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <!-- 🎨 Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet" integrity="sha512-z3gLpd7yknf1YoNbCzqRKc4qyor8gaKU1qmn+CShxbuBusANI9QpRohGBreCFkKxLhei6S9CQXFEbbKuqLg0DA==" crossorigin="anonymous">

    <style>
        body { background-color: #f8f9fa; }

        /* 🎨 Gradient header — mirrors admin/users/index.php's palette so the
           template designer visually belongs to the same admin suite. */
        .page-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }

        .designer-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        /* 🏷️ Variable picker chips */
        .var-chip {
            font-family: SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.8rem;
        }

        /* ✏️ Monospace editing surfaces for markup */
        textarea.code-editor {
            font-family: SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.85rem;
            min-height: 260px;
        }

        /* 👁️ Preview surfaces */
        #previewFrame {
            width: 100%;
            min-height: 420px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            background: #ffffff;
        }
        #previewText {
            white-space: pre-wrap;
            word-break: break-word;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 0.75rem;
            max-height: 260px;
            overflow-y: auto;
        }

        .table-templates td { vertical-align: middle; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">

        <!-- 🎨 Page Header -->
        <div class="page-header">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <a href="../email-dashboard.php" class="text-white text-decoration-none d-inline-flex align-items-center mb-3 opacity-75">
                        <i class="fas fa-arrow-left me-2" aria-hidden="true"></i> Back to Email Dashboard
                    </a>
                    <h1><i class="fas fa-palette me-2" aria-hidden="true"></i><?= $h($pageTitle) ?></h1>
                    <p class="mb-0 opacity-75">Design and edit SIGNula's transactional and marketing email templates.</p>
                </div>
                <div class="col-md-4 text-end">
                    <a href="../index.php" class="btn btn-light btn-sm">
                        <i class="fas fa-th-large me-1" aria-hidden="true"></i> Admin Dashboard
                    </a>
                </div>
            </div>
        </div>

        <!-- 📋 Flash / alert container (server-rendered here; JS-driven saves
             append additional alerts into the same container below). -->
        <div id="alertContainer">
            <?php if ($flashMessage !== null): ?>
                <div class="alert alert-<?= $h($flashType) ?> alert-dismissible fade show" role="alert">
                    <?= $flashMessage /* already escaped/composed above */ ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($notFoundNotice): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i>
                    The requested template could not be found. It may have been removed.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
        </div>

        <!-- 🔍 Search & Filter (plain GET form — works with or without JS) -->
        <div class="designer-card p-3 mb-4">
            <form method="get" class="row g-3 align-items-end" aria-label="Filter templates">
                <div class="col-md-5">
                    <label for="searchInput" class="form-label fw-semibold">
                        <i class="fas fa-search me-1" aria-hidden="true"></i>Search
                    </label>
                    <input type="text" id="searchInput" name="search" class="form-control"
                           placeholder="Template key or name…" value="<?= $h($searchTerm) ?>">
                </div>
                <div class="col-md-4">
                    <label for="categoryFilter" class="form-label fw-semibold">
                        <i class="fas fa-tags me-1" aria-hidden="true"></i>Category
                    </label>
                    <select id="categoryFilter" name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php foreach ($availableCategories as $cat): ?>
                            <option value="<?= $h($cat) ?>" <?= $cat === $categoryFilter ? 'selected' : '' ?>>
                                <?= $h(ucwords(str_replace('_', ' ', $cat))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-1" aria-hidden="true"></i>Apply
                    </button>
                </div>
                <div class="col-md-1">
                    <a href="./" class="btn btn-outline-secondary w-100" aria-label="Clear filters">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </a>
                </div>
            </form>
        </div>

        <!-- 📋 Templates list -->
        <div class="designer-card mb-4">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-list me-2" aria-hidden="true"></i>Templates</h5>
                <span class="badge bg-light text-dark"><?= count($allTemplates) ?> found</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-templates mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Template Key</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Locale</th>
                            <th>Status</th>
                            <th>Version</th>
                            <th>Updated</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($allTemplates)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No templates match your filters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($allTemplates as $template): ?>
                                <?php $isSelected = ((int)$template['templateID'] === $selectedTemplateID); ?>
                                <tr class="<?= $isSelected ? 'table-primary' : '' ?>">
                                    <td><code><?= $h($template['templateKey']) ?></code></td>
                                    <td><?= $h($template['templateName']) ?></td>
                                    <td><?= $h($template['category'] ?? '—') ?></td>
                                    <td><?= $h($template['locale'] ?? 'en_US') ?></td>
                                    <td>
                                        <?php if (!empty($template['isActive'])): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>v<?= (int)($template['version'] ?? 1) ?></td>
                                    <td><small><?= $h(!empty($template['updatedAt']) ? date('M j, Y g:i A', strtotime($template['updatedAt'])) : '—') ?></small></td>
                                    <td class="text-center">
                                        <a href="?templateID=<?= (int)$template['templateID'] ?>"
                                           class="btn btn-sm <?= $isSelected ? 'btn-primary' : 'btn-outline-primary' ?>">
                                            <i class="fas fa-pen me-1" aria-hidden="true"></i>Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($selectedTemplate !== null): ?>
        <!-- ============================================================ -->
        <!-- ✏️ EDIT + 👁️ LIVE PREVIEW                                    -->
        <!-- ============================================================ -->
        <div class="row g-4">

            <!-- ✏️ Edit pane -->
            <div class="col-lg-7">
                <div class="designer-card p-4">
                    <h5 class="mb-1">
                        <i class="fas fa-pen-to-square me-2" aria-hidden="true"></i>
                        Edit: <?= $h($selectedTemplate['templateName']) ?>
                    </h5>
                    <p class="text-muted small mb-3">
                        Key: <code><?= $h($selectedTemplate['templateKey']) ?></code>
                        &middot; Category: <?= $h($selectedTemplate['category'] ?? '—') ?>
                        &middot; Locale: <?= $h($selectedTemplate['locale'] ?? 'en_US') ?>
                        &middot; Version: v<?= (int)($selectedTemplate['version'] ?? 1) ?>
                    </p>

                    <?php if (!empty($declaredVariables)): ?>
                    <!-- 🏷️ Variable picker — inserts {{var}} tokens into the
                         last-focused field below. Fully informative even
                         without JavaScript (the token text itself is visible
                         to copy/type manually), just not click-to-insert. -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold d-block" id="variablePickerLabel">
                            <i class="fas fa-code me-1" aria-hidden="true"></i>Available Variables
                            <small class="text-muted">(click to insert into the last-focused field)</small>
                        </label>
                        <div class="d-flex flex-wrap gap-2" id="variablePicker" role="group" aria-labelledby="variablePickerLabel">
                            <?php foreach ($declaredVariables as $varName): ?>
                                <?php
                                    $sampleHint  = (string)($preview['variables'][$varName] ?? '');
                                    $description = (string)($variableDescriptions[$varName] ?? '');
                                    $tooltipBits = array_filter([$description, $sampleHint !== '' ? 'e.g. "' . $sampleHint . '"' : '']);
                                    $tooltip     = implode(' — ', $tooltipBits);
                                ?>
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary var-chip"
                                        data-var="<?= $h($varName) ?>"
                                        title="<?= $h($tooltip !== '' ? $tooltip : $varName) ?>">{{<?= $h($varName) ?>}}</button>
                            <?php endforeach; ?>
                        </div>
                        <noscript>
                            <div class="form-text text-warning mt-1">
                                <i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i>
                                JavaScript is disabled, so click-to-insert is unavailable — manually type the
                                variable tokens shown above (e.g. <code>{{<?= $h($declaredVariables[0]) ?>}}</code>)
                                where you need them.
                            </div>
                        </noscript>
                    </div>
                    <?php endif; ?>

                    <!-- 💾 Save form — plain POST to this same page by default;
                         progressively enhanced to an AJAX save via fetch()
                         when JavaScript is available (see script below). -->
                    <form method="post" action="?templateID=<?= (int)$selectedTemplate['templateID'] ?>" id="templateEditForm">
                        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_template">
                        <input type="hidden" name="templateID" value="<?= (int)$selectedTemplate['templateID'] ?>">

                        <div class="mb-3">
                            <label for="fieldSubject" class="form-label fw-semibold">Subject</label>
                            <input type="text" id="fieldSubject" name="subject" class="form-control"
                                   value="<?= $h($selectedTemplate['subject']) ?>" required maxlength="500">
                        </div>

                        <div class="mb-3">
                            <label for="fieldBodyHTML" class="form-label fw-semibold">HTML Body</label>
                            <textarea id="fieldBodyHTML" name="bodyHTML" class="form-control code-editor"
                                      spellcheck="false" required><?= $h($selectedTemplate['bodyHTML']) ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="fieldBodyText" class="form-label fw-semibold">Plain-Text Body</label>
                            <textarea id="fieldBodyText" name="bodyText" class="form-control code-editor"
                                      spellcheck="false" required><?= $h($selectedTemplate['bodyText']) ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-success" id="saveButton">
                            <i class="fas fa-floppy-disk me-1" aria-hidden="true"></i>Save Template
                        </button>
                        <span class="text-muted small ms-2" id="saveStatus"></span>
                    </form>
                </div>
            </div>

            <!-- 👁️ Preview pane -->
            <div class="col-lg-5">
                <div class="designer-card p-4">
                    <h5 class="mb-3"><i class="fas fa-eye me-2" aria-hidden="true"></i>Live Preview</h5>
                    <p class="text-muted small">
                        Rendered with sample data via <code>EmailTemplateManager::previewTemplate()</code>.
                        <span id="previewFreshnessNote">Reflects the currently saved version — save your
                        changes above to update it.</span>
                    </p>

                    <div class="mb-3">
                        <div class="form-label fw-semibold mb-1">Subject</div>
                        <div class="border rounded p-2 bg-light" id="previewSubject"><?= $h($preview['subject'] ?? '') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="form-label fw-semibold mb-1">HTML</div>
                        <iframe id="previewFrame" title="Rendered HTML email preview" sandbox
                                srcdoc="<?= $h($preview['bodyHTML'] ?? '') ?>"></iframe>
                    </div>

                    <div class="mb-1">
                        <div class="form-label fw-semibold mb-1">Plain Text</div>
                        <div id="previewText"><?= $h($preview['bodyText'] ?? '') ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- 🎨 Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

    <?php if ($selectedTemplate !== null): ?>
    <!-- ============================================================ -->
    <!-- ⚡ PROGRESSIVE ENHANCEMENT — AJAX save + live preview refresh -->
    <!-- Everything above already works without this script; this only -->
    <!-- upgrades the experience (no full page reload) when JS runs.   -->
    <!-- ============================================================ -->
    <script>
        const csrfToken       = '<?= $h($csrfToken) ?>';
        const currentTemplateID = <?= (int)$selectedTemplate['templateID'] ?>;

        /**
         * 📢 Show a dismissible Bootstrap alert in the shared alert container.
         */
        function showAlert(message, type = 'info') {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show`;
            alert.setAttribute('role', 'alert');
            alert.innerHTML = `${message} <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`;
            container.appendChild(alert);
            alert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // ------------------------------------------------------------------
        // 🏷️ Variable picker — insert {{var}} into whichever of the three
        // fields last had focus (defaults to the HTML body).
        // ------------------------------------------------------------------
        const subjectField  = document.getElementById('fieldSubject');
        const bodyHtmlField = document.getElementById('fieldBodyHTML');
        const bodyTextField = document.getElementById('fieldBodyText');
        let lastFocusedField = bodyHtmlField;

        [subjectField, bodyHtmlField, bodyTextField].forEach((field) => {
            if (field) {
                field.addEventListener('focus', () => { lastFocusedField = field; });
            }
        });

        document.querySelectorAll('#variablePicker .var-chip').forEach((chip) => {
            chip.addEventListener('click', () => {
                const token = `{{${chip.dataset.var}}}`;
                const field = lastFocusedField || bodyHtmlField;
                const start = field.selectionStart ?? field.value.length;
                const end   = field.selectionEnd ?? field.value.length;

                field.value = field.value.slice(0, start) + token + field.value.slice(end);
                field.focus();
                field.selectionStart = field.selectionEnd = start + token.length;
            });
        });

        // ------------------------------------------------------------------
        // 💾 AJAX save — intercepts the plain <form> POST so the page does
        // not reload; falls back to the real POST automatically if fetch()
        // throws (e.g. offline) by simply letting the alert explain the
        // failure — the underlying <form> remains fully valid on its own.
        // ------------------------------------------------------------------
        const editForm  = document.getElementById('templateEditForm');
        const saveButton = document.getElementById('saveButton');
        const saveStatus = document.getElementById('saveStatus');

        if (editForm && window.fetch) {
            editForm.addEventListener('submit', async (event) => {
                event.preventDefault();

                saveButton.disabled = true;
                saveStatus.textContent = 'Saving…';

                try {
                    const response = await fetch('../api/email-template-actions.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'save_template',
                            csrf_token: csrfToken,
                            templateID: currentTemplateID,
                            subject: subjectField.value,
                            bodyHTML: bodyHtmlField.value,
                            bodyText: bodyTextField.value,
                        }),
                    });
                    const result = await response.json();

                    if (result.success) {
                        const hasWarnings = Array.isArray(result.data?.unknownVariables) && result.data.unknownVariables.length > 0;
                        showAlert(result.message, hasWarnings ? 'warning' : 'success');
                        await refreshPreview();
                    } else {
                        showAlert(result.message || 'Failed to save template.', 'danger');
                    }
                } catch (error) {
                    showAlert('A network error occurred while saving. Please try again.', 'danger');
                } finally {
                    saveButton.disabled = false;
                    saveStatus.textContent = '';
                }
            });
        }

        // ------------------------------------------------------------------
        // 👁️ Refresh the preview pane in place after a successful AJAX save
        // — reuses the SAME preview_template action (and therefore the SAME
        // EmailTemplateManager::previewTemplate() backend call) the initial,
        // server-rendered preview above was built from.
        // ------------------------------------------------------------------
        async function refreshPreview() {
            try {
                const response = await fetch(`../api/email-template-actions.php?action=preview_template&templateID=${currentTemplateID}`);
                const result = await response.json();

                if (result.success) {
                    document.getElementById('previewSubject').textContent = result.data.subject ?? '';
                    document.getElementById('previewFrame').srcdoc = result.data.bodyHTML ?? '';
                    document.getElementById('previewText').textContent = result.data.bodyText ?? '';

                    const freshnessNote = document.getElementById('previewFreshnessNote');
                    if (freshnessNote) {
                        freshnessNote.textContent = 'Updated just now from your last save.';
                    }
                }
            } catch (error) {
                // 🤷 Non-fatal — the preview simply stays as the last known
                // good render; the save itself already succeeded.
            }
        }
    </script>
    <?php endif; ?>
</body>
</html>
