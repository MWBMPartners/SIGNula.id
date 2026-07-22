<?php
/**
 * SIGNula - Universal Single Sign-On Authentication System
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 *
 * This software is proprietary and confidential. Unauthorized copying,
 * distribution, or use is strictly prohibited.
 *
 * @package SIGNula
 * @version 2.7.0-beta
 */

/**
 * ============================================================================
 * 🍪 SIGNula - Consent Category Service (G-004 Layer 2 — Consent-Management Surface)
 * ============================================================================
 *
 * Purpose: The data-driven SURFACE that sits on top of Layer 1's
 *          ConsentManager (append-only ledger). This class never writes a
 *          consent row itself outside of the two thin wrappers below — it
 *          ALWAYS delegates the actual INSERT to ConsentManager::record(),
 *          so there is exactly one consent-recording code path in the whole
 *          application (see ConsentManager's own docblock).
 * PHP Version: 8.3+
 *
 * Responsibilities (see .dev-team/specs/G-004.md §3.1-§3.5):
 * - getActiveCategories()/getCurrentPolicyVersion(): read the two Layer-2
 *   tables (migration 041) so the banner/preference-center render is fully
 *   data-driven — adding a 5th category or publishing a new policy version
 *   is a row insert, never a PHP change.
 * - issueOrGetVisitorID(): the pre-login anonymous identity cookie. Pure
 *   (no DB) — reads/writes $_COOKIE only.
 * - recordBannerChoices(): the ONE place that turns a set of category
 *   toggle decisions into ConsentManager::record() calls. Server-side
 *   enforces that 'necessary' (isStrictlyNecessary=1) can NEVER be recorded
 *   as not-granted, regardless of what the caller's input contains — this
 *   is the hard rule from the spec, and it is enforced HERE, not just via a
 *   disabled checkbox in the UI.
 * - userNeedsReconsent()/maybeReconsentRedirect(): versioned re-consent gate
 *   for the login flow. maybeReconsentRedirect() is an additional
 *   convenience wrapper (not explicitly named in the spec) that centralises
 *   the "is enforcement even on?" + "does this user need it?" + "what's the
 *   redirect URL?" logic so login.php and mfa/verify.php don't duplicate it.
 * - honorGpcSignalIfPresent(): Global Privacy Control auto-opt-out, guarded
 *   to run AT MOST ONCE PER SESSION and to never write a duplicate opt-out
 *   row (see the method's own docblock for the two-layer guard).
 *
 * @see _database/migrations/041_consent_categories_and_policy_versions.sql
 * @see web/private_html/compliance/ConsentManager.php — the append-only ledger this class delegates to
 * @see .dev-team/specs/G-004.md §3
 * @see https://globalprivacycontrol.org/ (Sec-GPC request header)
 * @see https://www.php.net/manual/en/function.setcookie.php
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class ConsentCategoryService
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /**
     * ✅ Allowed values for tblPolicyVersions.policyKey — MUST mirror the SQL
     * ENUM in migration 041 exactly.
     *
     * @var array<int,string>
     */
    private const VALID_POLICY_KEYS = ['terms', 'privacy', 'cookies'];

    /**
     * 🍪 Fallback visitor-cookie name, used only if the
     * consent.visitor_cookie.name setting has not been loaded (defensive —
     * migration 041 always seeds it).
     */
    private const DEFAULT_VISITOR_COOKIE_NAME = 'SIGNULA_VID';

    /** @var int Fallback visitor-cookie TTL (days), mirrors migration 041's seed. */
    private const DEFAULT_VISITOR_COOKIE_TTL_DAYS = 365;

    // ========================================================================
    // 🗂️ CATEGORIES / POLICY VERSIONS (data-driven reads)
    // ========================================================================

    /**
     * 🗂️ Get Active Consent Categories
     *
     * Returns every tblConsentCategories row with isActive=1, ordered for
     * display. This is the ONLY place the banner/preference-center pulls
     * its category list from — never a hardcoded PHP array.
     *
     * @return array<int,array<string,mixed>> Rows, ordered by displayOrder ASC
     */
    public static function getActiveCategories(): array
    {
        return Database::fetchAll(
            "SELECT * FROM tblConsentCategories
             WHERE isActive = 1
             ORDER BY displayOrder ASC, categoryID ASC"
        ) ?? [];
    }

    /**
     * 📜 Get The Current Policy Version Anchor For A Policy Key
     *
     * Returns the tblPolicyVersions row with isCurrent=1 for the given
     * policyKey (terms|privacy|cookies), or null if none is configured (or
     * the policyKey is not a recognised member of the ENUM).
     *
     * @param string $policyKey One of 'terms', 'privacy', 'cookies'
     * @return array<string,mixed>|null
     */
    public static function getCurrentPolicyVersion(string $policyKey): ?array
    {
        if (!in_array($policyKey, self::VALID_POLICY_KEYS, true)) {
            return null;
        }

        $row = Database::fetchOne(
            "SELECT * FROM tblPolicyVersions
             WHERE policyKey = ? AND isCurrent = 1
             ORDER BY policyVersionID DESC
             LIMIT 1",
            [$policyKey],
            's'
        );

        return $row ?: null;
    }

    // ========================================================================
    // 🍪 ANONYMOUS VISITOR IDENTITY
    // ========================================================================

    /**
     * 🍪 Issue Or Get The Anonymous Visitor ID
     *
     * Reads the visitor-identity cookie (name configurable via
     * consent.visitor_cookie.name) if present and a syntactically valid
     * UUID v4; otherwise generates a fresh one, sets the cookie (TTL from
     * consent.visitor_cookie.ttl_days), AND reflects it into $_COOKIE
     * immediately so a second call within the SAME request (e.g. the
     * banner render followed by a form POST handled later in the same
     * bootstrap) sees the same, stable ID rather than minting a new one —
     * setcookie() only takes effect on the NEXT request otherwise.
     *
     * Deliberately httponly=false (per spec §3.1) so client-side JS can read
     * it for the progressive-enhancement banner mirror; the SERVER row
     * (tblConsentRecords) remains the source of truth regardless.
     *
     * @return string A 36-character UUID v4 (existing or newly issued)
     * @see https://www.php.net/manual/en/function.setcookie.php
     */
    public static function issueOrGetVisitorID(): string
    {
        $cookieName = self::visitorCookieName();

        $existing = $_COOKIE[$cookieName] ?? null;
        if (is_string($existing) && self::isValidUuidV4($existing)) {
            return $existing;
        }

        $visitorID = self::generateUuidV4();
        $ttlDays = (int) getSetting('consent.visitor_cookie.ttl_days', self::DEFAULT_VISITOR_COOKIE_TTL_DAYS);
        if ($ttlDays < 1) {
            $ttlDays = self::DEFAULT_VISITOR_COOKIE_TTL_DAYS;
        }

        // 🛡️ Only attempt to send a header if none has gone out yet — mirrors
        // the guard config.php's own redirect() uses. A failed/suppressed
        // setcookie() must never abort visitor-ID issuance; the in-request
        // reflection below still lets THIS request behave correctly even if
        // the cookie can't reach the browser (e.g. output already started).
        if (!headers_sent()) {
            $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            @setcookie($cookieName, $visitorID, [
                'expires'  => time() + ($ttlDays * 86400),
                'path'     => '/',
                'secure'   => $isHttps,
                // 🔓 Deliberately readable by JS (spec §3.1) — the banner's
                // progressive-enhancement script mirrors it, NOT a security boundary.
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }

        // 🔁 Reflect immediately so subsequent calls THIS request are stable.
        $_COOKIE[$cookieName] = $visitorID;

        return $visitorID;
    }

    /**
     * 🔧 Resolve the configured visitor-cookie name, falling back to the
     * migration 041 default if the setting is somehow unavailable/blank.
     *
     * @return string
     */
    private static function visitorCookieName(): string
    {
        $name = trim((string) getSetting('consent.visitor_cookie.name', self::DEFAULT_VISITOR_COOKIE_NAME));

        return $name !== '' ? $name : self::DEFAULT_VISITOR_COOKIE_NAME;
    }

    /**
     * 🔎 Is this a syntactically valid UUID v4 string?
     *
     * @param string $value
     * @return bool
     */
    private static function isValidUuidV4(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $value
        );
    }

    /**
     * 🎲 Generate a fresh RFC 4122 version-4 UUID.
     *
     * @return string
     */
    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // RFC 4122 variant

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    // ========================================================================
    // 📝 RECORD BANNER / PREFERENCE-CENTER CHOICES
    // ========================================================================

    /**
     * 📝 Record A Full Set Of Banner/Preference-Center Category Decisions
     *
     * The ONE place a set of category toggle choices becomes
     * ConsentManager::record() calls (mechanism='banner'), one row per
     * ACTIVE category. 🔒 HARD RULE: any category with isStrictlyNecessary=1
     * is ALWAYS recorded granted=true — the caller's $categoryDecisions value
     * for that key (if any) is ignored. This is enforced here, server-side,
     * not merely by a disabled checkbox in the UI.
     *
     * @param array<string,mixed> $categoryDecisions categoryKey => truthy/falsy decision
     * @param int|null            $userID            Authenticated user, or null
     * @param string|null         $visitorID          Anonymous visitor UUID, or null
     * @return void
     */
    public static function recordBannerChoices(array $categoryDecisions, ?int $userID, ?string $visitorID): void
    {
        if (!class_exists('ConsentManager')) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConsentManager.php';
        }

        $categories = self::getActiveCategories();
        $cookiesPolicy = self::getCurrentPolicyVersion('cookies');
        $policyVersion = $cookiesPolicy['version'] ?? null;

        foreach ($categories as $category) {
            $key = (string) $category['categoryKey'];
            $isNecessary = (bool) $category['isStrictlyNecessary'];

            // 🔒 Server-side enforcement — see method docblock.
            $granted = $isNecessary ? true : self::isTruthyInput($categoryDecisions[$key] ?? false);

            ConsentManager::record(
                $userID,
                $visitorID,
                'cookies.' . $key,
                $granted,
                [
                    'mechanism'     => 'banner',
                    'policyVersion' => $policyVersion,
                ]
            );
        }
    }

    /**
     * 🔧 Normalise a decoded-input value (JSON bool, checkbox '1'/'on', form
     * string 'true', etc.) into a strict PHP bool. Pulled out as its own
     * pure method so callers (and this class's own tests) don't have to
     * reimplement checkbox/JSON truthiness parsing.
     *
     * @param mixed $value
     * @return bool
     */
    public static function isTruthyInput(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalised = strtolower(trim((string) $value));

        return in_array($normalised, ['1', 'true', 'on', 'yes'], true);
    }

    // ========================================================================
    // 🔄 VERSIONED RE-CONSENT (login-flow gate)
    // ========================================================================

    /**
     * 🔄 Does This User Need To Re-Consent?
     *
     * Compares the user's LATEST recorded 'terms' and 'privacy' consent
     * (via ConsentManager::getCurrent()) against the current policy version
     * anchor (tblPolicyVersions.isCurrent=1) for each. Returns true the
     * moment either one is stale (policyVersion mismatch).
     *
     * A user who has NEVER recorded a terms/privacy consent at all (no row
     * exists yet — e.g. a brand-new account created before this feature
     * existed, or a consent type the signup flow doesn't currently record)
     * is treated as NOT needing re-consent for that policy: this is a
     * deliberate "never trap a user in a loop" tolerance called out in the
     * spec — re-consent only fires on a KNOWN STALE version, never on
     * "we have no record at all".
     *
     * A policyKey with no current-version anchor configured (getCurrentPolicyVersion
     * returns null) is skipped entirely — there is nothing to compare against.
     *
     * @param int $userID
     * @return bool
     */
    public static function userNeedsReconsent(int $userID): bool
    {
        if (!class_exists('ConsentManager')) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConsentManager.php';
        }

        foreach (['terms', 'privacy'] as $policyKey) {
            $current = self::getCurrentPolicyVersion($policyKey);
            if ($current === null) {
                continue; // no anchor configured — nothing to compare against
            }

            $latestConsent = ConsentManager::getCurrent($userID, null, $policyKey);
            if ($latestConsent === null) {
                continue; // never consented at all — not a "stale version" case, see docblock
            }

            if ((string) $latestConsent['policyVersion'] !== (string) $current['version']) {
                return true;
            }
        }

        return false;
    }

    /**
     * 🚦 Compute The Re-Consent Redirect Target, If Any
     *
     * Convenience wrapper combining the enforcement gate (settings-only,
     * no DB touch) with userNeedsReconsent() (DB-bound) so login.php and
     * mfa/verify.php share one code path instead of duplicating the
     * gate+check+redirect-URL logic. Returns null whenever no redirect is
     * warranted — enforcement OFF, or the user does not need to re-consent —
     * so a caller can simply do `redirect($this->…() ?? $normalRedirect)`.
     *
     * 🔒 Deliberately checks the settings gate FIRST, before touching the
     * database at all — with consent.reconsent.enforce OFF (the shipped
     * default), this method is a single getSetting() lookup and returns
     * immediately, which is what keeps the existing login integration tests
     * inert (see .dev-team/specs/G-004.md §3, "MUST keep the login test
     * baseline green").
     *
     * @param int    $userID            Authenticated user
     * @param string $intendedRedirect  Where the user was headed before this gate
     * @return string|null The /legal/reconsent?redirect=... URL, or null if no redirect is needed
     */
    public static function maybeReconsentRedirect(int $userID, string $intendedRedirect): ?string
    {
        if (!self::isTruthySetting(getSetting('consent.reconsent.enforce', '0'))) {
            return null;
        }

        if (!self::userNeedsReconsent($userID)) {
            return null;
        }

        return '/legal/reconsent?redirect=' . urlencode($intendedRedirect);
    }

    // ========================================================================
    // 🌐 GLOBAL PRIVACY CONTROL (GPC)
    // ========================================================================

    /**
     * 🌐 Honor A Sec-GPC: 1 Signal, If Present — At Most Once Per Session
     *
     * Auto-records a 'do_not_sell' opt-out (granted=false, mechanism=
     * 'gpc_signal') when the request carries `Sec-GPC: 1` AND
     * consent.gpc.honor is truthy. Guarded on TWO independent levels so this
     * can be called from a high-frequency bootstrap path (e.g. config.php,
     * included by nearly every page) without spamming rows or adding
     * per-request DB load:
     *
     *  1. SESSION GUARD (cheap, no DB): once this method has SUCCESSFULLY
     *     evaluated GPC for the current session against a real identity
     *     (header present + setting on + at least a userID or visitorID to
     *     record against), it sets $_SESSION['gpc_honored'] = true and every
     *     subsequent call in that SAME session returns immediately without
     *     touching the database again. This is the primary defence against
     *     "config.php runs on every request" turning into "one query per
     *     request". Deliberately NOT set when neither identifier is
     *     available yet (see the ⚠️ note below) — that is a "try again next
     *     request", not a "never try again".
     *  2. DB IDEMPOTENCY (belt-and-braces): even on the FIRST evaluation,
     *     it checks ConsentManager::getCurrent() first and skips the INSERT
     *     if the subject's current effective do_not_sell decision is
     *     ALREADY an opt-out — covers the case where a different session
     *     (or an explicit settings/privacy.php toggle) already recorded the
     *     opt-out for this same user/visitor.
     *
     * Cheap guards (header check, setting check) run BEFORE either guard
     * above touches $_SESSION or the database, so a request with no GPC
     * header — the overwhelming majority — is a single array lookup.
     *
     * ⚠️ Ordering note: this is called from config.php's bootstrap, which
     * runs BEFORE a page has necessarily called
     * ConsentCategoryService::issueOrGetVisitorID() (that happens later, in
     * public-footer.php / the consent endpoint / /legal/consent). On an
     * anonymous visitor's very FIRST request, neither a session userID nor a
     * visitor-cookie visitorID may exist yet — in that case this method
     * returns WITHOUT setting the session flag, so it is naturally retried
     * on the visitor's NEXT request (by which time the visitor cookie
     * issued during THIS request's page render will have round-tripped back
     * from the browser). A logged-in user's userID, by contrast, is already
     * in $_SESSION by the time config.php runs, so is honoured on the very
     * first qualifying request.
     *
     * @param int|null    $userID    Authenticated user, or null
     * @param string|null $visitorID Anonymous visitor UUID, or null
     * @return void
     */
    public static function honorGpcSignalIfPresent(?int $userID, ?string $visitorID): void
    {
        // ⚡ Cheapest possible checks first — no $_SESSION, no DB.
        if (($_SERVER['HTTP_SEC_GPC'] ?? '') !== '1') {
            return;
        }

        if (!self::isTruthySetting(getSetting('consent.gpc.honor', '1'))) {
            return;
        }

        // 🔒 Session guard — at most once per session, checked BEFORE we
        // decide whether we can actually act (see ⚠️ ordering note above).
        if (!empty($_SESSION['gpc_honored'])) {
            return;
        }

        if ($userID === null && $visitorID === null) {
            // 🈳 Nothing to record against YET — deliberately do NOT set the
            // session flag, so a later request (once a visitorID cookie or a
            // login session exists) gets another chance.
            return;
        }

        // ✅ We have an identity to act on — this session's GPC evaluation
        // is now considered "done" regardless of the outcome below (already
        // opted out, or a fresh opt-out gets recorded).
        $_SESSION['gpc_honored'] = true;

        if (!class_exists('ConsentManager')) {
            require_once __DIR__ . DIRECTORY_SEPARATOR . 'ConsentManager.php';
        }

        // 🔁 DB-level idempotency — see method docblock, guard layer 2.
        $current = ConsentManager::getCurrent($userID, $visitorID, 'do_not_sell');
        if ($current !== null && (int) $current['granted'] === 0) {
            return; // already opted out — no duplicate row
        }

        ConsentManager::record($userID, $visitorID, 'do_not_sell', false, ['mechanism' => 'gpc_signal']);
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS
    // ========================================================================

    /**
     * 🔧 Interpret a tblSettings string value as a boolean.
     *
     * Mirrors ConsentManager::isTruthySetting() exactly (each class keeps
     * its own small copy per this codebase's established "self-contained
     * static utility class" convention — see ConsentManager's identical
     * private helper).
     *
     * @param mixed $value Raw setting value
     * @return bool
     */
    private static function isTruthySetting(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalised = strtolower(trim((string) $value));

        return $normalised === 'true' || $normalised === '1' || $normalised === 'yes' || $normalised === 'on';
    }
}
