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
 * 📮 SIGNula - Public Data-Request Intake (G-004 Layer 1 / Cycle C)
 * ============================================================================
 *
 * Purpose: The ANTI-ENUMERATION SEAM behind the public "I can't log in" data
 *          request form (web/public_html/SIGNula.com/legal/data-request/index.php).
 *          This class exists so the single most important security property
 *          of that form — "the response is byte-identical whether or not the
 *          submitted email matches an account" — is enforced by CONSTRUCTION
 *          (one code path, one constant message, no branch the caller can
 *          observe) and is independently testable WITHOUT spinning up an HTTP
 *          server, rather than being something the page script has to get
 *          right by careful authorship.
 *
 * Design contract (see .dev-team/specs/G-004.md §2.6, §6, risk #4):
 * - process() ALWAYS returns void and NEVER throws — every failure path
 *   (account not found, DSARManager rejecting the request, identity-token
 *   issuance failing, the email provider being unreachable) is swallowed
 *   internally. The calling page therefore has exactly ONE possible response
 *   to render (UNIFORM_SUCCESS_MESSAGE), regardless of which branch executed
 *   — there is no "if submission failed, show a different message" branch to
 *   accidentally leak account existence through.
 * - The ONLY observable difference between an existing and a non-existing
 *   email is internal side effects (a DSAR row + a queued verification email
 *   for an existing account; nothing at all for a non-existing one) — never
 *   anything the HTTP response exposes.
 * - The raw identity-verification token is never logged, stored, or returned
 *   from this class — DSARManager::startIdentityVerification() hands it back
 *   once, this class emails it immediately, and it is discarded.
 *
 * @see web/public_html/SIGNula.com/legal/data-request/index.php — the caller
 * @see web/private_html/compliance/DSARManager.php — DSAR tracker this delegates to
 * @see .dev-team/specs/G-004.md §2.6 (endpoints/UI), §6 (security), risk #4
 * @see https://owasp.org/www-community/attacks/User_enumeration — the threat this class closes
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class DataRequestIntake
{
    /**
     * 🔒 The ONLY requestType values the PUBLIC (pre-auth) data-request form
     * may submit — deliberately the same narrower set as settings/privacy.php's
     * own $DSAR_TYPE_LABELS allowlist (DSARManager::VALID_REQUEST_TYPES is a
     * broader union across every channel; this is this channel's own
     * server-authoritative closed set).
     *
     * @var array<int,string>
     */
    public const VALID_REQUEST_TYPES = ['access', 'portability', 'erasure', 'rectification'];

    /**
     * 🔒 THE single uniform response message. The calling page renders this
     * EXACT string (and only this string) after calling process() —
     * regardless of whether the submitted email matched an account. Defined
     * once, here, so there is no risk of the success page's copy drifting
     * into two subtly different strings over time.
     *
     * @var string
     */
    public const UNIFORM_SUCCESS_MESSAGE = "If an account exists for that email address, "
        . "we've emailed a link to verify and continue your request. Please check your inbox "
        . "(and spam folder) for a message from us.";

    /**
     * ✅ Is This A Requestable Type On The Public Form? — pure, DB-free.
     *
     * @param string $requestType
     * @return bool
     */
    public static function isValidRequestType(string $requestType): bool
    {
        return in_array($requestType, self::VALID_REQUEST_TYPES, true);
    }

    /**
     * 📮 Process A Public Data-Request Submission (the anti-enumeration seam)
     *
     * Looks up whether an active tblUsers row matches $email; if — and ONLY
     * if — one exists, submits a DSAR on that user's behalf, starts identity
     * verification, and emails the raw token as a verification link. If no
     * match exists, or ANYTHING inside this method fails for any reason, this
     * method does nothing further and returns — silently, identically,
     * indistinguishably from the success case, from the caller's point of view.
     *
     * The caller MUST NOT branch on this method's return value to decide what
     * to render (there isn't one to branch on — return type is void precisely
     * to make that mistake impossible) — it must always render
     * UNIFORM_SUCCESS_MESSAGE after calling this.
     *
     * @param string      $email       Already-sanitised (SecurityUtils::sanitizeEmail()) requester email
     * @param string      $requestType One of VALID_REQUEST_TYPES (caller MUST pre-validate via isValidRequestType())
     * @param string|null $details     Optional free-text detail (sanitised by DSARManager::submit() itself)
     * @return void
     */
    public static function process(string $email, string $requestType, ?string $details = null): void
    {
        // 🛡️ Defensive re-check — this method must never act on an
        // out-of-allowlist type even if a caller forgets to pre-validate.
        if ($email === '' || !self::isValidRequestType($requestType)) {
            return;
        }

        try {
            // 🔍 THE lookup. This is the only place existence is learned —
            // and the ONLY thing that ever reads this result is the `if`
            // below, which either does more work or returns. No signal about
            // which branch was taken ever leaves this method.
            $user = Database::fetchOne(
                "SELECT userID, email FROM tblUsers WHERE email = ? LIMIT 1",
                [$email],
                's'
            );

            if (!$user) {
                // 🔒 No account — nothing further happens. No DSAR row, no
                // email, no exception, no distinguishable side effect.
                return;
            }

            self::ensureDependenciesLoaded();

            $requestID = DSARManager::submit([
                'userID'         => (int) $user['userID'],
                'requestType'    => $requestType,
                'requesterEmail' => $email,
                'details'        => $details,
                'actorType'      => 'user',
            ]);

            // 🔐 Fresh raw token — see DSARManager::startIdentityVerification()'s
            // own docblock: only its SHA-256 hash is ever persisted.
            $rawToken = DSARManager::startIdentityVerification($requestID);

            self::sendVerificationEmail($email, (int) $requestID, $rawToken, $requestType);
        } catch (\Throwable $e) {
            // 🔒 Swallow EVERYTHING — a DB hiccup, a DSARManager validation
            // exception, an email-provider failure — none of it may ever
            // change what the caller renders. Best-effort diagnostic logging
            // only (the exception message never contains the raw token — see
            // this class's docblock).
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            }
        }
    }

    /**
     * 📚 Require DSARManager (+ its own required exception type) if not
     * already loaded — web/private_html/compliance/ is NOT covered by
     * config.php's spl_autoload_register() directory list (confirmed:
     * auth/security/utils/api/api-controllers/email/database only — see
     * DSARManager.php's own docblock for the identical note), so this
     * mirrors that class's explicit guarded-require idiom.
     *
     * @return void
     */
    private static function ensureDependenciesLoaded(): void
    {
        if (!class_exists('DSARManager')) {
            $dsarManagerPath = __DIR__ . DIRECTORY_SEPARATOR . 'DSARManager.php';
            if (file_exists($dsarManagerPath)) {
                require_once $dsarManagerPath;
            } else {
                throw new \RuntimeException('Required dependency DSARManager.php is not available');
            }
        }
    }

    /**
     * 📧 Email The Raw Identity-Verification Token As A Link
     *
     * Builds a minimal, on-brand HTML email via EmailTemplateBuilder (no new
     * tblEmailTemplates row required for this cycle) and queues it through
     * EmailService::sendRichEmail() — the SAME queue every other transactional
     * email in this codebase uses (EmailQueueProcessor sends it; nothing here
     * talks to an SMTP/API provider directly).
     *
     * @param string $email       Verified-format recipient address
     * @param int    $requestID   The new DSAR's requestID (embedded in the link)
     * @param string $rawToken    The RAW verification token — used once, here, then discarded
     * @param string $requestType The DSAR requestType (for the email copy only)
     * @return void
     */
    private static function sendVerificationEmail(string $email, int $requestID, string $rawToken, string $requestType): void
    {
        // 📚 web/private_html/email/ IS autoloaded (config.php's directory
        // list includes 'email') — but this class may run under a test
        // bootstrap that has not loaded config.php's autoloader, so guard
        // exactly like ensureDependenciesLoaded() above.
        if (!class_exists('EmailTemplateBuilder')) {
            $builderPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . 'EmailTemplateBuilder.php';
            if (file_exists($builderPath)) {
                require_once $builderPath;
            }
        }
        if (!class_exists('EmailService')) {
            $servicePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'email' . DIRECTORY_SEPARATOR . 'EmailService.php';
            if (file_exists($servicePath)) {
                require_once $servicePath;
            } else {
                return; // best-effort — nothing to send with
            }
        }

        $baseURL = getSetting('url.base', 'https://SIGNula.id');
        // 🔐 rawurlencode() the token (mirrors EmailService::sendVerificationEmail()'s
        // own idiom) so reserved characters can't break the query string.
        $verifyURL = "{$baseURL}/legal/data-request/verify?requestID=" . (int) $requestID
            . '&token=' . rawurlencode($rawToken);

        $ttlMinutes = (int) getSetting('dsar.identity_token.ttl_minutes', 30);

        $bodyContent = '<h1 style="margin:0 0 16px;">Verify your data request</h1>'
            . '<p>We received a data-subject request (reference #' . (int) $requestID . ') for this email address. '
            . 'To continue, please verify it\'s really you by clicking the button below.</p>';

        if (class_exists('EmailTemplateBuilder')) {
            $bodyContent .= EmailTemplateBuilder::buildButton('Verify My Request', $verifyURL);
        } else {
            // 🛡️ Degrade gracefully to a plain link if the builder is unavailable.
            $bodyContent .= '<p><a href="' . htmlspecialchars($verifyURL, ENT_QUOTES, 'UTF-8') . '">Verify My Request</a></p>';
        }

        $bodyContent .= '<p>This link expires in ' . $ttlMinutes . ' minutes. '
            . 'If you did not submit this request, you can safely ignore this email — no action will be taken.</p>';

        $html = class_exists('EmailTemplateBuilder')
            ? EmailTemplateBuilder::wrapInLayout($bodyContent, 'Verify your SIGNula data request', 'Verify Your Data Request')
            : $bodyContent;

        EmailService::sendRichEmail(
            $email,
            'Verify Your Data Request - SIGNula',
            $html,
            null, // plainText — auto-generated from $html
            null, // ampBody
            null, // userID — intentionally not linked here; DSARManager::submit() already recorded it
            1     // priority — highest, this is a time-sensitive security email
        );
    }
}

// ✅ DataRequestIntake loaded successfully
