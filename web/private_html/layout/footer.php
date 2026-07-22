<?php
/**
 * ============================================================================
 * 🎨 SIGNula - Shared Application Footer (account / settings pages)
 * ============================================================================
 * Paired 1:1 with header.php in this same directory — see that file's
 * doc-block for the full B-060 background and the nesting contract both
 * files share.
 *
 * This file renders a slim site footer, the Bootstrap JS bundle + site JS,
 * then closes the <body></html> that header.php opened. It does NOT close
 * any content wrapper — every consumer page opens AND closes its own
 * top-level `<div class="container">` (and any inline `<script>` blocks)
 * BEFORE including this file, so there is nothing left here for this file
 * to balance except header.php's own <body>/<header> tags.
 *
 * @package    SIGNula
 * @subpackage Components
 * @version    1.0.0
 * @see        web/private_html/layout/header.php (its required pair — nesting contract lives there)
 * ============================================================================
 */

// 📅 Copyright year — matches the pattern used by public-footer.php.
$footerYear = date('Y');
?>
    <!-- ============================================================ -->
    <!-- 🦶 Slim application footer (settings/account pages don't need -->
    <!--    the full 5-column marketing footer — that's public-footer.php) -->
    <!-- ============================================================ -->
    <footer class="app-footer border-top mt-5 py-4">
        <div class="container-fluid px-3 px-md-4 d-flex flex-wrap justify-content-between align-items-center gap-2 text-muted small">
            <div>
                &copy; <?php echo htmlspecialchars($footerYear, ENT_QUOTES, 'UTF-8'); ?> MWBMPartners.
                All rights reserved. &middot; SIGNula v<?php echo htmlspecialchars(defined('APP_VERSION') ? APP_VERSION : '2.0.1', ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div>
                <a href="/legal/privacy" class="text-muted me-3">Privacy</a>
                <a href="/legal/terms" class="text-muted me-3">Terms</a>
                <a href="/support" class="text-muted">Support</a>
            </div>
        </div>
    </footer>

    <!-- 🎨 Bootstrap 5.3.2 JS bundle (includes Popper) — loaded at the bottom per performance best practice.
         Safe to load here: every settings/auth page that reaches this file only uses Bootstrap's DECLARATIVE
         data-bs-toggle attributes (dropdowns, modals) — none call `new bootstrap.X(...)` imperatively in an
         inline <script> that would need Bootstrap loaded earlier. -->
    <script
        src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL"
        crossorigin="anonymous"
    ></script>

    <!-- 🎨 Site JS -->
    <script src="/assets/js/main.js"></script>

</body>
</html>
