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
 * 🚼 SIGNula - Age Gate Service (G-004 Layer 4b §5.4 — COPPA-style scaffold)
 * ============================================================================
 *
 * Purpose: A CONFIG-GATED-OFF-BY-DEFAULT age check for signup, plus the
 *          parental-consent verification scaffold an underage signup routes
 *          into. This class provides MACHINERY only — the minimum-age
 *          THRESHOLD always comes from `RegimeResolver::minAgeFor()` (never
 *          a hardcoded number), and the verification METHOD's legal
 *          sufficiency under COPPA is left entirely to the operator/legal
 *          team; this class only implements the email-link scaffold.
 * PHP Version: 8.3+
 *
 * ----------------------------------------------------------------------------
 * 🔒 THE SHIPPED-STATE CONTRACT — keeps the register.php / Auth baseline green
 * ----------------------------------------------------------------------------
 * `compliance.age_gate.enabled` ships `'0'` (migration 044). {@see self::isEnabled()}
 * returns false against that shipped value, and `register.php`'s ENTIRE
 * age-gate block is gated behind that single call — with the setting OFF, no
 * date_of_birth field is ever read or required, and registration behaves
 * EXACTLY as before this migration existed. An operator must explicitly flip
 * the setting (after confirming the applicable minimum age with legal — see
 * the Compliance Regimes admin, `/admin/compliance/regimes`) before this
 * class's DOB logic is ever consulted by a live signup.
 *
 * ----------------------------------------------------------------------------
 * 🔐 THE TOKEN CONTRACT — mirrors DSARManager's identity-verification pattern
 * ----------------------------------------------------------------------------
 * {@see self::startParentalConsent()} stores ONLY a SHA-256 hash of the raw
 * verification token in `tblParentalConsents.verificationToken` — the exact
 * same pattern as `tblDataSubjectRequests.verificationToken` (migration 040)
 * / `tblDataExportRequests.downloadToken` (the C5 lesson this whole codebase
 * mirrors). The raw token is emailed to the parent and returned to the
 * IMMEDIATE PHP caller only (same same-process trust boundary
 * `DSARManager::startIdentityVerification()` already establishes) — it is
 * NEVER logged (`ActivityLogger` calls below only ever record the
 * `consentID`) and NEVER persisted anywhere except as its hash.
 *
 * Database Tables (see migration 044):
 * - tblParentalConsents : childUserID, parentEmail, status, verificationToken (SHA-256 hash), method, verifiedAt
 *
 * @see _database/migrations/044_breach_ropa_agegate.sql
 * @see web/private_html/compliance/RegimeResolver.php — minAgeFor(), the ONLY source of any age threshold number
 * @see web/private_html/compliance/DSARManager.php — startIdentityVerification()/verifyIdentity(), the pattern this mirrors
 * @see web/public_html/register.php — the signup hook this gates
 * @see web/public_html/legal/parental-verify/index.php — the verification landing page
 * @see .dev-team/specs/G-004.md §5.4
 * @see https://www.ftc.gov/business-guidance/resources/childrens-online-privacy-protection-rule-not-just-kids-sites (COPPA — the METHOD sufficiency is a legal call this class does not make)
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 🌍 RegimeResolver — web/private_html/compliance/ is not covered by
// config.php's spl_autoload_register() directory list (same situation as
// every sibling class in this directory — see DSARManager.php's identical
// note). A missing RegimeResolver.php is NOT fatal: thresholdAge() degrades
// to a hardcoded, jurisdiction-agnostic fallback rather than ever crashing.
if (!class_exists('RegimeResolver')) {
    $regimeResolverPath = __DIR__ . DIRECTORY_SEPARATOR . 'RegimeResolver.php';
    if (file_exists($regimeResolverPath)) {
        require_once $regimeResolverPath;
    }
}

class AgeGateService
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var string tblSettings key for the master age-gate switch (migration 044). Ships '0'/OFF. */
    private const SETTING_ENABLED = 'compliance.age_gate.enabled';

    /** @var string tblSettings key for the parental-consent token TTL, in hours (migration 044). Ships '72'. */
    private const SETTING_TOKEN_TTL_HOURS = 'compliance.parental_consent.token_ttl_hours';

    /**
     * 🛡️ Hardcoded fallback-of-fallback minimum age, used ONLY if
     * RegimeResolver.php itself is unavailable (see the guarded require
     * above). Generic, names no jurisdiction — identical fallback value to
     * RegimeResolver::FALLBACK_MIN_AGE, which is the actual path consulted
     * whenever that class IS available (i.e. in every real deployment).
     */
    private const FALLBACK_MIN_AGE = 16;

    /** @var int Hardcoded fallback-of-fallback token TTL (hours), used only if the setting itself is unreadable. */
    private const FALLBACK_TOKEN_TTL_HOURS = 72;

    /** @var array<int,string> tblParentalConsents.status ENUM — MUST mirror migration 044 exactly. */
    private const VALID_STATUSES = ['pending', 'verified', 'denied'];

    // ========================================================================
    // 🚦 IS THE GATE ON?
    // ========================================================================

    /**
     * 🚦 Is The Signup Age-Gate Enabled?
     *
     * Reads `compliance.age_gate.enabled` (migration 044, ships `'0'`). This
     * is the SINGLE call register.php's entire age-gate block is gated
     * behind — see this class's own docblock for the shipped-state contract.
     *
     * @return bool
     */
    public static function isEnabled(): bool
    {
        return self::isTruthySetting(getSetting(self::SETTING_ENABLED, '0'));
    }

    // ========================================================================
    // 📐 THRESHOLD AGE — always resolved, never a PHP literal
    // ========================================================================

    /**
     * 📐 Digital-Consent Minimum Age Threshold
     *
     * Thin, guarded pass-through to `RegimeResolver::minAgeFor()` — the
     * ONLY source of any age-threshold number in this class. With every
     * regime shipped `isActive = 0` (the default state), this resolves to
     * RegimeResolver's own most-protective configured default (16 unless an
     * operator changes `compliance.regime.default_min_age`).
     *
     * @param string|null $regimeCode Optional specific regime code; null resolves the default
     * @return int Always >= 1
     */
    public static function thresholdAge(?string $regimeCode = null): int
    {
        if (class_exists('RegimeResolver')) {
            try {
                $age = RegimeResolver::minAgeFor($regimeCode);
                if ($age > 0) {
                    return $age;
                }
            } catch (\Throwable $e) {
                // 🛡️ Fall through to the hardcoded fallback below — never crash.
            }
        }

        return self::FALLBACK_MIN_AGE;
    }

    // ========================================================================
    // 🎂 IS UNDERAGE? — pure age math
    // ========================================================================

    /**
     * 🎂 Is This Date-Of-Birth Under The Minimum Age?
     *
     * The age computation itself is PURE and DB-free (see computeAge()) —
     * calling with an explicit `$threshold` (as every acceptance test for
     * this class does) exercises ONLY that pure path. Omitting `$threshold`
     * falls back to {@see self::thresholdAge()}, which IS DB-backed (via
     * RegimeResolver) — that fallback exists purely for caller convenience
     * (e.g. a future call site that doesn't already have a resolved
     * threshold in hand); register.php's own hook always has the option to
     * pass one explicitly.
     *
     * An unparseable/invalid DOB (wrong format, future date) fails CLOSED —
     * treated as underage — since it cannot be proven the subject is of
     * age. Callers should validate the DOB format themselves before relying
     * on this (register.php's hook does), but this is the safe default
     * regardless.
     *
     * @param string   $dob       'Y-m-d' date of birth
     * @param int|null $threshold Minimum age; null resolves via thresholdAge()
     * @return bool
     */
    public static function isUnderage(string $dob, ?int $threshold = null): bool
    {
        $age = self::computeAge($dob);

        if ($age === null) {
            return true; // 🛡️ fail closed — cannot prove of-age from an invalid DOB
        }

        $resolvedThreshold = $threshold ?? self::thresholdAge();

        return $age < $resolvedThreshold;
    }

    /**
     * 🔧 Compute Age (In Whole Years) From A 'Y-m-d' Date-Of-Birth — pure, DB-free.
     *
     * @param string $dob 'Y-m-d'
     * @return int|null Whole years, or null if $dob is not a valid, non-future 'Y-m-d' date
     */
    private static function computeAge(string $dob): ?int
    {
        $dob = trim($dob);

        if ($dob === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            return null;
        }

        try {
            $birthDate = new \DateTimeImmutable($dob);
        } catch (\Throwable $e) {
            return null;
        }

        // 🔍 DateTimeImmutable silently accepts some malformed strings (e.g.
        // overflowing '2024-02-30' rolls to March) — re-render and compare
        // to catch that class of drift rather than trusting the parse blind.
        if ($birthDate->format('Y-m-d') !== $dob) {
            return null;
        }

        $today = new \DateTimeImmutable('today');
        if ($birthDate > $today) {
            return null; // a future date of birth is not valid
        }

        return (int) $today->diff($birthDate)->y;
    }

    // ========================================================================
    // 👶 PARENTAL CONSENT — start
    // ========================================================================

    /**
     * 👶 Start Parental-Consent Verification For An Underage Signup
     *
     * Generates a fresh raw token, stores ONLY its SHA-256 hash, emails the
     * parent a verification link via `EmailService::sendRichEmail()`, and
     * returns the raw token to the IMMEDIATE PHP caller only — see this
     * class's own docblock ("THE TOKEN CONTRACT") for why this mirrors
     * `DSARManager::startIdentityVerification()`'s established, already
     * security-reviewed pattern rather than returning nothing.
     *
     * @param int    $childUserID The (already-created, restricted) child account's userID
     * @param string $parentEmail Parent/guardian email address — validated before use
     * @return string The RAW (unhashed) verification token
     *
     * @throws \InvalidArgumentException If $parentEmail is not a valid email address
     *
     * @see https://www.php.net/manual/en/function.hash-equals.php
     */
    public static function startParentalConsent(int $childUserID, string $parentEmail): string
    {
        $sanitizedEmail = SecurityUtils::sanitizeEmail($parentEmail);
        if ($sanitizedEmail === false) {
            throw new \InvalidArgumentException('A valid parentEmail is required to start parental consent.');
        }

        $rawToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $rawToken);

        $consentID = Database::insert(
            "INSERT INTO tblParentalConsents (childUserID, parentEmail, status, verificationToken, method, createdAt)
             VALUES (?, ?, 'pending', ?, 'email_link', ?)",
            [$childUserID, $sanitizedEmail, $hashedToken, date('Y-m-d H:i:s')],
            'isss'
        );

        self::sendParentalConsentEmail($sanitizedEmail, $consentID, $rawToken);

        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $childUserID,
                'parental_consent_started',
                'privacy',
                'info',
                "Parental consent verification started for child user #{$childUserID} (consent #{$consentID})",
                // 🔒 NEVER include the raw OR hashed token in the audit log.
                ['consentID' => $consentID]
            );
        }

        return $rawToken;
    }

    /**
     * 📧 Best-Effort: Email The Parent A Verification Link
     *
     * Never throws outward — a failure here must not undo the already-
     * persisted consent row / hashed token; a future cycle can add a
     * resend affordance if delivery genuinely fails.
     *
     * @param string $parentEmail Already-validated
     * @param int    $consentID
     * @param string $rawToken
     * @return void
     */
    private static function sendParentalConsentEmail(string $parentEmail, int $consentID, string $rawToken): void
    {
        if (!class_exists('EmailService')) {
            $emailServicePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . 'EmailService.php';
            if (file_exists($emailServicePath)) {
                require_once $emailServicePath;
            } else {
                return; // best-effort only — nothing to send with
            }
        }

        if (!class_exists('EmailTemplateBuilder')) {
            $builderPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . 'EmailTemplateBuilder.php';
            if (file_exists($builderPath)) {
                require_once $builderPath;
            }
        }

        $appName = (string) getSetting('app.name', 'SIGNula');
        $siteUrl = rtrim((string) getSetting('site.url', 'https://SIGNulo.id'), '/');
        $verifyUrl = $siteUrl . '/legal/parental-verify?consentID=' . $consentID . '&token=' . urlencode($rawToken);

        if (class_exists('EmailTemplateBuilder')) {
            $body = '<p>A child account on ' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8')
                . ' has listed you as a parent or guardian and requires your consent before the account can '
                . 'be used.</p>'
                . EmailTemplateBuilder::buildButton('Confirm Parental Consent', $verifyUrl)
                . '<p>If you did not expect this email, you can safely ignore it — no account access is '
                . 'granted until this link is used.</p>';
            $html = EmailTemplateBuilder::wrapInLayout($body, 'Parental consent required', 'Parental Consent Required');
        } else {
            $escapedUrl = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
            $html = '<p>Please confirm parental consent by visiting: <a href="' . $escapedUrl . '">' . $escapedUrl . '</a></p>';
        }

        try {
            if (class_exists('EmailService')) {
                EmailService::sendRichEmail(
                    $parentEmail,
                    'Parental Consent Required - ' . $appName,
                    $html
                );
            }
        } catch (\Throwable $e) {
            // 🛡️ Best-effort — never let an email-sending failure crash
            // startParentalConsent(); the consent row + hashed token remain persisted.
        }
    }

    // ========================================================================
    // ✅ PARENTAL CONSENT — verify
    // ========================================================================

    /**
     * ✅ Verify Parental Consent Via The Emailed Token
     *
     * Timing-safe comparison of `hash('sha256', $rawToken)` against the
     * stored hash, TTL-checked against `createdAt` +
     * `compliance.parental_consent.token_ttl_hours`. Fails CLOSED (returns
     * false, never throws) for every rejection reason — not-found, wrong
     * status, bad token, or expiry — so a caller can present a single
     * generic "invalid or expired link" message without leaking which
     * specific reason applied (mirrors `DSARManager::verifyIdentity()`).
     *
     * @param int    $consentID The tblParentalConsents.consentID
     * @param string $rawToken  The RAW token from the verification email/link
     * @return bool True if verification succeeded and status was set to 'verified'
     */
    public static function verifyParentalConsent(int $consentID, string $rawToken): bool
    {
        $row = Database::fetchOne(
            "SELECT consentID, status, verificationToken, createdAt FROM tblParentalConsents WHERE consentID = ?",
            [$consentID],
            'i'
        );

        if (!$row || empty($row['verificationToken'])) {
            return false;
        }

        if ((string) $row['status'] !== 'pending') {
            return false;
        }

        if (!hash_equals((string) $row['verificationToken'], hash('sha256', $rawToken))) {
            return false;
        }

        $ttlHours = (int) getSetting(self::SETTING_TOKEN_TTL_HOURS, self::FALLBACK_TOKEN_TTL_HOURS);
        if (self::isTokenExpired((string) $row['createdAt'], $ttlHours, date('Y-m-d H:i:s'))) {
            return false;
        }

        Database::query(
            "UPDATE tblParentalConsents SET status = 'verified', verifiedAt = NOW() WHERE consentID = ?",
            [$consentID],
            'i'
        );

        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                null,
                'parental_consent_verified',
                'privacy',
                'info',
                "Parental consent #{$consentID} verified",
                ['consentID' => $consentID]
            );
        }

        return true;
    }

    /**
     * 🚫 Deny Parental Consent (operator or parent-initiated decline)
     *
     * @param int         $consentID
     * @param string|null $note Reserved for a future audit note — not yet persisted (no column)
     * @return bool True if the consent row existed and was set to 'denied'
     */
    public static function denyParentalConsent(int $consentID, ?string $note = null): bool
    {
        $row = Database::fetchOne("SELECT consentID, status FROM tblParentalConsents WHERE consentID = ?", [$consentID], 'i');
        if (!$row || (string) $row['status'] !== 'pending') {
            return false;
        }

        Database::query("UPDATE tblParentalConsents SET status = 'denied' WHERE consentID = ?", [$consentID], 'i');

        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(null, 'parental_consent_denied', 'privacy', 'warning', "Parental consent #{$consentID} denied", ['consentID' => $consentID]);
        }

        return true;
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS
    // ========================================================================

    /**
     * 🔧 Is A Token Past Its TTL? — pure, DB-free.
     *
     * Identical idiom to DSARManager::isTokenExpired().
     *
     * @param string $issuedAt   'Y-m-d H:i:s' the token was issued (createdAt)
     * @param int    $ttlHours   Lifetime in hours (negative values clamp to 0)
     * @param string $now        'Y-m-d H:i:s' the current comparison instant
     * @return bool True if $now is strictly after $issuedAt + $ttlHours
     */
    private static function isTokenExpired(string $issuedAt, int $ttlHours, string $now): bool
    {
        $ttlHours = max(0, $ttlHours);
        $expiry = strtotime($issuedAt . " +{$ttlHours} hours");

        return strtotime($now) > $expiry;
    }

    /**
     * 🔧 Interpret A tblSettings String Value As A Boolean.
     *
     * Identical idiom to ConsentManager::isTruthySetting()/DSARManager::isTruthySetting()
     * /RetentionManager::isTruthySetting() — duplicated rather than shared,
     * matching this codebase's existing per-class convention.
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
