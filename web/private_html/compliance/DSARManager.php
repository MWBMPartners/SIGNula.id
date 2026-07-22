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
 * 📨 SIGNula - Data Subject Access Request (DSAR) Manager (G-004 Layer 1 / Cycle B)
 * ============================================================================
 *
 * Purpose: Track a data subject's request (access/portability/erasure/
 *          rectification/etc) through a guarded lifecycle, from submission
 *          to fulfilment, while DELEGATING all actual data-movement work to
 *          the existing GDPR engine in AccountManager — this class is
 *          explicitly NOT a second export/delete implementation.
 * PHP Version: 8.3+
 *
 * Design contract (see .dev-team/specs/G-004.md §2.3/§2.4/§2.5):
 * - **Anti-duplication seam.** `fulfilPortability()`/`fulfilAccess()` call
 *   `AccountManager::exportUserData()` (the SAME engine `settings/privacy.php`'s
 *   "Export My Data" button already uses) and merely record the returned
 *   `export_id` against this DSAR. `fulfilErasure()` calls
 *   `AccountManager::requestAccountDeletion()` (the SAME grace-period +
 *   `processScheduledDeletions()` cron already in production) and merely
 *   tracks the DSAR alongside it. This class contains NO file-writing,
 *   NO JSON export generation, and no direct row-deletion against tblUsers —
 *   see the class-level grep proof recorded in the build report for G-004
 *   Layer 1 Cycle B.
 * - **Guarded state machine.** `transition()` only allows the edges listed in
 *   `VALID_TRANSITIONS` (mirrors `SubscriptionStateMachine`'s
 *   `VALID_TRANSITIONS`/`assertTransition()` pattern exactly — see
 *   `web/private_html/payments/SubscriptionStateMachine.php`). An illegal
 *   transition throws `InvalidDSARTransitionException` AFTER being logged to
 *   `tblActivityLog` (category `privacy`, severity `warning`) so a misuse
 *   attempt is always auditable, not just rejected silently.
 * - **Erasure-safety is NOT implemented here.** The anonymise-on-erasure edit
 *   (`UPDATE tblDataSubjectRequests SET userID=NULL, requesterEmail=NULL,
 *   ipAddress=NULL WHERE userID=?`) lives inside
 *   `AccountManager::permanentlyDeleteAccount()` itself (same transaction as
 *   the existing `tblActivityLog` anonymisation), NOT in this class — a
 *   DSAR must remain provable ("we honoured this erasure request") even
 *   after the account that filed it no longer exists.
 * - **No hard FK to tblUsers**, and `linkedExportID` has no hard FK to
 *   `tblDataExportRequests` either (by design — see migration 040's column
 *   comment) — a DSAR can legitimately outlive the export row it references
 *   once the export file itself expires/is purged by
 *   `AccountManager::cleanupExpiredExports()`.
 * - **`regimeCode` / per-regime SLA (G-004 Layer 3, migration 042).**
 *   `submit()` now resolves the governing regime via `RegimeResolver::resolve()`
 *   and computes `dueDate` from `RegimeResolver::slaDaysFor()` — see
 *   `resolveRegimeAndSla()`. This is a BEST-EFFORT retro-wire (a resolver
 *   failure degrades to the pre-L3 `dsar.default_sla_days` behaviour, never
 *   breaks submission) and, in the SHIPPED state (every migration-042 seed
 *   regime is `isActive = 0`), is a behavioural no-op: `regimeCode` still
 *   ends up NULL and the SLA still ends up the default 30 days, identical
 *   to pre-L3 behaviour, until an operator activates a regime.
 * - **No admin queue, no public request form, no regime resolver** ship in
 *   this cycle — `getQueue()`/`getComplianceReport()` are the DATA SOURCE a
 *   later Layer-1-Cycle-C admin queue will call; they have no UI yet.
 *
 * Database Tables:
 * - tblDataSubjectRequests       : the DSAR tracker (see migration 040)
 * - tblDataSubjectRequestEvents  : per-request status/audit timeline
 *
 * @see _database/migrations/040_consent_and_dsar.sql
 * @see web/private_html/compliance/ConsentManager.php — sibling class / house style template
 * @see web/private_html/auth/AccountManager.php — the export/erasure engine this DELEGATES to
 * @see web/private_html/payments/SubscriptionStateMachine.php — the guarded-state-machine pattern this mirrors
 * @see .dev-team/specs/G-004.md §2.3, §2.4, §2.5
 * @see https://gdpr-info.eu/art-12-gdpr/ (timeframes/formalities for exercising data subject rights)
 * @see https://www.php.net/manual/en/function.hash-equals.php (timing-safe token comparison)
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 📚 Require the dedicated exception type this class throws. web/private_html/compliance/
// is NOT covered by config.php's spl_autoload_register() directory list (confirmed against
// the autoloader's scanned dirs: auth/security/utils/api/api-controllers/email/database only),
// so — mirroring SubscriptionStateMachine.php requiring InvalidSubscriptionTransitionException.php —
// this dependency is always explicitly required with an existence check.
$dsarTransitionExceptionPath = __DIR__ . DIRECTORY_SEPARATOR . 'InvalidDSARTransitionException.php';
if (file_exists($dsarTransitionExceptionPath)) {
    require_once $dsarTransitionExceptionPath;
} else {
    error_log('🚨 [DSARManager] InvalidDSARTransitionException.php not found at: ' . $dsarTransitionExceptionPath);
    throw new RuntimeException('Required dependency InvalidDSARTransitionException.php is not available');
}

// 🌍 G-004 Layer 3 (migration 042): RegimeResolver — same "compliance/ is not
// autoloaded" situation as InvalidDSARTransitionException.php above. UNLIKE
// that exception class, a missing RegimeResolver.php is NOT fatal here:
// submit()'s regime resolution is explicitly best-effort (see
// resolveRegimeAndSla()'s docblock) — if this require can't find the file,
// class_exists('RegimeResolver') simply stays false everywhere below and
// submit() falls back to the exact pre-L3 behaviour (regimeCode=NULL,
// SLA=dsar.default_sla_days).
if (!class_exists('RegimeResolver')) {
    $regimeResolverPath = __DIR__ . DIRECTORY_SEPARATOR . 'RegimeResolver.php';
    if (file_exists($regimeResolverPath)) {
        require_once $regimeResolverPath;
    }
}

class DSARManager
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /**
     * ✅ Allowed values for tblDataSubjectRequests.requestType — MUST mirror
     * the SQL ENUM in migration 040 exactly. Note this is the FULL union of
     * rights across every regime this project may eventually support; the
     * NARROWER subset a logged-in user may self-initiate from
     * settings/privacy.php's own allowlist ($DSAR_TYPE_LABELS in that file)
     * is a deliberately smaller list — e.g. 'restriction'/'object'/
     * 'automated_decision_optout' are reserved for future admin-initiated or
     * public-form channels, not this cycle's user-facing surface.
     *
     * @var array<int,string>
     */
    private const VALID_REQUEST_TYPES = [
        'access',
        'portability',
        'erasure',
        'rectification',
        'restriction',
        'object',
        'do_not_sell',
        'withdraw_consent',
        'automated_decision_optout',
    ];

    /**
     * ✅ Allowed values for tblDataSubjectRequests.status — MUST mirror the
     * SQL ENUM in migration 040 exactly.
     *
     * @var array<int,string>
     */
    private const VALID_STATUSES = [
        'submitted',
        'identity_pending',
        'verified',
        'in_progress',
        'awaiting_user',
        'fulfilled',
        'rejected',
        'partially_fulfilled',
        'expired',
        'withdrawn',
    ];

    /**
     * 🛑 Terminal statuses — once here, a request has NO onward transition.
     * Used both by the transition map below (every terminal key maps to an
     * empty edge list) and by getQueue()/getComplianceReport()'s "still
     * open" / "overdue" filters.
     *
     * @var array<int,string>
     */
    private const TERMINAL_STATUSES = [
        'fulfilled',
        'rejected',
        'partially_fulfilled',
        'expired',
        'withdrawn',
    ];

    /**
     * 🗺️ The authoritative DSAR lifecycle adjacency map (G-004 spec §2.5).
     * Keys are the FROM status; values are the list of legal TO statuses.
     * Every TERMINAL_STATUSES member maps to an empty array (dead end).
     *
     * submitted → identity_pending → verified → in_progress →
     *   {fulfilled | partially_fulfilled | rejected}
     * Side branches: awaiting_user, withdrawn, expired — reachable from
     * multiple non-terminal states per the map below.
     *
     * @var array<string,array<int,string>>
     */
    private const VALID_TRANSITIONS = [
        'submitted'           => ['identity_pending', 'verified', 'in_progress', 'rejected', 'withdrawn', 'expired'],
        'identity_pending'    => ['verified', 'expired', 'withdrawn'],
        'verified'            => ['in_progress', 'rejected', 'withdrawn'],
        'in_progress'         => ['fulfilled', 'partially_fulfilled', 'rejected', 'awaiting_user', 'withdrawn'],
        'awaiting_user'       => ['in_progress', 'expired', 'withdrawn'],
        'fulfilled'           => [],
        'rejected'            => [],
        'partially_fulfilled' => [],
        'expired'             => [],
        'withdrawn'           => [],
    ];

    /**
     * ✅ Allowed values for tblDataSubjectRequestEvents.actorType — MUST
     * mirror the SQL ENUM in migration 040 exactly.
     *
     * @var array<int,string>
     */
    private const VALID_ACTOR_TYPES = ['user', 'admin', 'system', 'cron'];

    /** @var int Hard ceiling on getQueue()'s LIMIT, regardless of caller request. */
    private const MAX_QUEUE_LIMIT = 100;

    /**
     * 🔒 The ONLY tblUsers columns fulfilRectification() is permitted to
     * write. Never derived from caller input — this is a fixed allowlist;
     * any $approvedChanges key outside this set is silently rejected (see
     * filterRectifiableColumns()) rather than reaching SQL.
     *
     * @var array<int,string>
     */
    private const RECTIFIABLE_USER_COLUMNS = ['firstName', 'lastName', 'displayName', 'phoneNumber'];

    /**
     * 🛡️ Mirrors NotificationService::VALID_TYPES exactly (read directly
     * from web/private_html/notifications/NotificationService.php before
     * writing this class — see notifyAdminsOfNewRequest()'s docblock for
     * why this mirror exists rather than inventing a 'privacy'/'compliance'
     * notification type that NotificationService::create() would silently
     * reject).
     *
     * @var array<int,string>
     */
    private const NOTIFICATION_SERVICE_VALID_TYPES_MIRROR = ['security', 'account', 'system', 'billing', 'social'];

    // ========================================================================
    // 📝 SUBMIT A NEW REQUEST
    // ========================================================================

    /**
     * 📝 Submit A New Data Subject Request
     *
     * Validates requestType against the ENUM allowlist BEFORE touching the
     * database (so this guard is exercisable as a pure, DB-free check — see
     * the Unit test suite), sanitises free-text/email fields, resolves
     * dueDate from the flat SLA default setting (regime-aware SLA is L3),
     * INSERTs the request, appends the initial 'submitted' event row, logs
     * to tblActivityLog, and (best-effort, non-fatal) notifies compliance
     * admins.
     *
     * @param array $req {
     *     @type int|null    $userID        Authenticated user, or null for a pre-auth/email-channel request
     *     @type string      $requestType   One of VALID_REQUEST_TYPES (REQUIRED)
     *     @type string|null $requesterEmail For unauthenticated requests; sanitised via SecurityUtils::sanitizeEmail()
     *     @type string|null $subjectRef    On-behalf-of reference (e.g. parent for a child)
     *     @type string|null $details       Free-text detail, sanitised via SecurityUtils::sanitizeString()
     *     @type string|null $actorType     Who is submitting: user|admin|system|cron (default 'user')
     *     @type int|null    $actorID       Explicit actor ID for the audit event (defaults to $userID)
     *     @type int|null    $partnerID     G-004 L3: optional partner/tenant hint passed to
     *                                      RegimeResolver::resolve() (see resolveRegimeAndSla())
     *     @type string|null $countryHint   G-004 L3: optional ISO country hint passed to
     *                                      RegimeResolver::resolve() — the caller's own knowledge
     *                                      (e.g. a user's declared residency), never a geo-IP lookup
     * }
     * @return int New requestID (tblDataSubjectRequests.requestID)
     *
     * @throws InvalidArgumentException If requestType is missing or not in VALID_REQUEST_TYPES
     *
     * @see https://gdpr-info.eu/art-12-gdpr/
     */
    public static function submit(array $req): int
    {
        // 🛑 Validate FIRST, before any Database:: call — a bad requestType
        // must never reach SQL (would otherwise throw a raw ENUM-truncation
        // error under strict mode), and keeping this the very first
        // statement lets the Unit suite exercise it without a live DB.
        $requestType = (string) ($req['requestType'] ?? '');
        if (!in_array($requestType, self::VALID_REQUEST_TYPES, true)) {
            throw new InvalidArgumentException(
                "Unrecognised DSAR requestType: '{$requestType}'. Must be one of: "
                . implode(', ', self::VALID_REQUEST_TYPES)
            );
        }

        // 🆔 userID is optional — a request can arrive by email before
        // identity is matched to an account (migration 040 column comment).
        $userID = isset($req['userID']) ? (int) $req['userID'] : null;
        if ($userID === 0) {
            $userID = null;
        }

        // 📧 requesterEmail — sanitise/validate; an invalid address degrades
        // to NULL rather than hard-failing the whole submission (mirrors
        // ConsentManager's "never let a peripheral field block capture"
        // posture — the core fields (requestType/userID) are what matter).
        $requesterEmail = null;
        if (!empty($req['requesterEmail'])) {
            $sanitizedEmail = SecurityUtils::sanitizeEmail((string) $req['requesterEmail']);
            $requesterEmail = $sanitizedEmail !== false ? $sanitizedEmail : null;
        }

        $subjectRef = isset($req['subjectRef']) ? SecurityUtils::sanitizeString((string) $req['subjectRef']) : '';
        $subjectRef = $subjectRef !== '' ? $subjectRef : null;

        $details = isset($req['details']) ? SecurityUtils::sanitizeString((string) $req['details']) : '';
        $details = $details !== '' ? $details : null;

        $actorType = self::resolveActorType($req['actorType'] ?? null);
        $actorID = isset($req['actorID']) ? (int) $req['actorID'] : $userID;

        // 📦 Pack the client IP into VARBINARY(16) via inet_pton() — identical
        // fail-safe idiom to ConsentManager::record() (see that class for the
        // detailed rationale on the '@' suppression + false->null degrade).
        $clientIP = getClientIP();
        $ipPacked = @inet_pton($clientIP);
        if ($ipPacked === false) {
            $ipPacked = null;
        }

        // 🌍 G-004 Layer 3: resolve the governing regime + SLA ONCE via
        // RegimeResolver (migration 042) — see resolveRegimeAndSla()'s own
        // docblock for the best-effort/never-fail-open contract and the
        // "shipped state = no-op" guarantee.
        $partnerID = isset($req['partnerID']) ? (int) $req['partnerID'] : null;
        $countryHint = !empty($req['countryHint']) ? (string) $req['countryHint'] : null;
        ['regimeCode' => $regimeCode, 'slaDays' => $slaDays] = self::resolveRegimeAndSla($userID, $partnerID, $countryHint);

        // ⏰ dueDate = submission time + the resolved regime's SLA days.
        $now = date('Y-m-d H:i:s');
        $dueDate = self::computeDueDate($now, $slaDays);

        // 💾 INSERT the request, including regimeCode (migration 040 column,
        // now populated via the G-004 Layer 3 resolver above).
        $requestID = Database::insert(
            "INSERT INTO tblDataSubjectRequests
                (userID, requestType, status, regimeCode, requesterEmail, subjectRef, details, dueDate, ipAddress, submittedAt)
             VALUES (?, ?, 'submitted', ?, ?, ?, ?, ?, ?, ?)",
            [$userID, $requestType, $regimeCode, $requesterEmail, $subjectRef, $details, $dueDate, $ipPacked, $now],
            'issssssss'
        );

        // 📝 Append the initial 'submitted' audit event (fromStatus NULL —
        // this is the request's genesis, there is no prior status).
        self::appendEvent($requestID, null, 'submitted', $actorType, $actorID, 'DSAR submitted');

        // 📝 Audit trail (privacy category — widened onto tblActivityLog's
        // ENUM by migration 040, same fix class as migration 029).
        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $userID,
                'dsar_submitted',
                'privacy',
                'info',
                "DSAR submitted: {$requestType} (request #{$requestID}, due {$dueDate})",
                ['requestID' => $requestID, 'requestType' => $requestType, 'dueDate' => $dueDate]
            );
        }

        // 📣 Best-effort admin notify — see notifyAdminsOfNewRequest()'s
        // docblock for the NotificationService ENUM guardrail this respects.
        self::notifyAdminsOfNewRequest($requestID, $requestType);

        return $requestID;
    }

    // ========================================================================
    // 🔐 IDENTITY VERIFICATION
    // ========================================================================

    /**
     * 🔐 Start Identity Verification For A Request
     *
     * Generates a fresh raw token, stores ONLY its SHA-256 hash in
     * tblDataSubjectRequests.verificationToken (the raw value is returned to
     * the caller — who is responsible for emailing it — and is NEVER stored
     * or logged in cleartext), and either performs the guarded
     * submitted → identity_pending transition (first issuance) or, if a
     * token is being RE-issued while already identity_pending, appends a
     * same-status audit event directly (bypassing the transition map, which
     * has no self-edge for that state — see appendEvent()).
     *
     * TTL for the returned token is NOT stored as a dedicated expiry column
     * (tblDataSubjectRequests has none) — instead, verifyIdentity() re-derives
     * the issuance time from the most recent tblDataSubjectRequestEvents row
     * with toStatus='identity_pending', which this method's own transition/
     * event-append always (re)writes. See verifyIdentity()'s docblock.
     *
     * @param int $requestID The tblDataSubjectRequests.requestID to start verification for
     * @return string The RAW (unhashed) verification token — email this to the requester; never persist it
     *
     * @throws InvalidDSARTransitionException If the request does not exist, or its current
     *                                         status is neither 'submitted' nor 'identity_pending'
     */
    public static function startIdentityVerification(int $requestID): string
    {
        $request = Database::fetchOne(
            "SELECT status FROM tblDataSubjectRequests WHERE requestID = ?",
            [$requestID],
            'i'
        );

        if (!$request) {
            throw new InvalidDSARTransitionException("DSAR request #{$requestID} not found.");
        }

        $status = (string) $request['status'];

        if (!in_array($status, ['submitted', 'identity_pending'], true)) {
            throw new InvalidDSARTransitionException(
                "Cannot start identity verification for DSAR #{$requestID}: current status "
                . "'{$status}' is not 'submitted' or 'identity_pending'."
            );
        }

        // 🔐 Fresh raw token; store ONLY its SHA-256 hash.
        $rawToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $rawToken);

        Database::query(
            "UPDATE tblDataSubjectRequests SET verificationToken = ? WHERE requestID = ?",
            [$hashedToken, $requestID],
            'si'
        );

        if ($status === 'submitted') {
            // 🔄 First issuance IS the submitted -> identity_pending
            // transition; the resulting event row's createdAt doubles as
            // the TTL anchor verifyIdentity() reads back.
            self::transition($requestID, 'identity_pending', 'system', null, 'Identity verification token issued');
        } else {
            // 🔁 Re-issuance while already identity_pending — not a status
            // change, so this bypasses the guarded map (no self-edge exists
            // for identity_pending -> identity_pending) and appends a
            // same-status audit event directly, which also refreshes the
            // TTL anchor for the new token.
            self::appendEvent($requestID, 'identity_pending', 'identity_pending', 'system', null, 'Identity verification token re-issued');
        }

        return $rawToken;
    }

    /**
     * ✅ Verify Identity Via Emailed Token
     *
     * Timing-safe comparison of hash('sha256', $token) against the stored
     * hash, TTL-checked against the most recent identity_pending event row's
     * createdAt (see startIdentityVerification()'s docblock — there is no
     * dedicated tokenIssuedAt/expiresAt column on tblDataSubjectRequests).
     * On success, transitions submitted/identity_pending → verified and
     * stamps verifiedAt.
     *
     * Fails CLOSED (returns false, never throws) for every rejection reason
     * — not-found, wrong status, bad token, missing issuance record, or
     * expiry — so a caller can present a single generic "invalid or expired
     * link" message without leaking which specific reason applied.
     *
     * @param int    $requestID The tblDataSubjectRequests.requestID
     * @param string $token     The RAW token from the verification email/link
     * @return bool True if verification succeeded and the transition was applied
     */
    public static function verifyIdentity(int $requestID, string $token): bool
    {
        $request = Database::fetchOne(
            "SELECT userID, status, verificationToken FROM tblDataSubjectRequests WHERE requestID = ?",
            [$requestID],
            'i'
        );

        if (!$request || empty($request['verificationToken'])) {
            return false;
        }

        if (!in_array($request['status'], ['submitted', 'identity_pending'], true)) {
            return false;
        }

        if (!hash_equals((string) $request['verificationToken'], hash('sha256', $token))) {
            return false;
        }

        // ⏰ TTL — anchored to the most recent identity_pending event's createdAt.
        $lastIssued = Database::fetchOne(
            "SELECT createdAt FROM tblDataSubjectRequestEvents
             WHERE requestID = ? AND toStatus = 'identity_pending'
             ORDER BY createdAt DESC, eventID DESC LIMIT 1",
            [$requestID],
            'i'
        );

        if (!$lastIssued) {
            // 🛡️ No issuance record — cannot establish a TTL anchor at all;
            // fail closed rather than treat "unknown age" as "not expired".
            return false;
        }

        $ttlMinutes = (int) getSetting('dsar.identity_token.ttl_minutes', 30);
        if (self::isTokenExpired((string) $lastIssued['createdAt'], $ttlMinutes, date('Y-m-d H:i:s'))) {
            return false;
        }

        self::transition(
            $requestID,
            'verified',
            'user',
            isset($request['userID']) ? (int) $request['userID'] : null,
            'Identity verified via emailed token'
        );

        Database::query(
            "UPDATE tblDataSubjectRequests SET verifiedAt = NOW() WHERE requestID = ?",
            [$requestID],
            'i'
        );

        return true;
    }

    // ========================================================================
    // 🔄 GUARDED STATE MACHINE
    // ========================================================================

    /**
     * ✅ Can This Transition Happen? (pure predicate, no DB access)
     *
     * @param string $from Current status
     * @param string $to   Proposed status
     * @return bool True if VALID_TRANSITIONS permits from -> to
     */
    public static function canTransition(string $from, string $to): bool
    {
        if (!array_key_exists($from, self::VALID_TRANSITIONS)) {
            return false;
        }

        return in_array($to, self::VALID_TRANSITIONS[$from], true);
    }

    /**
     * 🚫 Assert A Transition Is Legal (throws on illegal) — pure, no DB access.
     *
     * @param string $from Current status
     * @param string $to   Proposed status
     * @return void
     *
     * @throws InvalidDSARTransitionException When the transition is not permitted, or either
     *                                         status string is unrecognised
     */
    public static function assertTransition(string $from, string $to): void
    {
        if (!in_array($from, self::VALID_STATUSES, true)) {
            throw new InvalidDSARTransitionException("Unknown current DSAR status: '{$from}'.");
        }

        if (!in_array($to, self::VALID_STATUSES, true)) {
            throw new InvalidDSARTransitionException("Unknown target DSAR status: '{$to}'.");
        }

        if (!self::canTransition($from, $to)) {
            throw new InvalidDSARTransitionException(
                "Illegal DSAR state transition: '{$from}' -> '{$to}'. "
                . "See DSARManager::VALID_TRANSITIONS (G-004 spec §2.5)."
            );
        }
    }

    /**
     * 🔄 Transition A Request To A New Status (validate + persist + log)
     *
     * Loads the CURRENT status, validates the requested transition against
     * VALID_TRANSITIONS, persists the new status (+ fulfilledAt when the
     * target is a fulfilment status), appends a tblDataSubjectRequestEvents
     * row, and logs via ActivityLogger. An illegal transition is logged
     * (category 'privacy', severity 'warning') BEFORE being (re-)thrown, so
     * every misuse attempt is auditable, not just rejected.
     *
     * Unlike SubscriptionStateMachine::transition(), re-applying the SAME
     * status is NOT special-cased as an idempotent no-op here — the DSAR map
     * has no self-edges, so from===to is always illegal and throws (this is
     * deliberate: a caller that thinks it's "re-transitioning" almost always
     * means something else, e.g. re-issuing an identity token, which has its
     * own dedicated non-transition path — see startIdentityVerification()).
     *
     * @param int         $requestID The tblDataSubjectRequests.requestID to transition
     * @param string      $toStatus  The target status (see VALID_STATUSES)
     * @param string      $actorType Who performed this transition: user|admin|system|cron
     * @param int|null    $actorID   The specific actor's ID (nullable for system/cron)
     * @param string|null $note      Optional human-readable note, persisted to the event row
     * @return bool True on success
     *
     * @throws InvalidDSARTransitionException When the request does not exist, or the
     *                                         transition is illegal
     */
    public static function transition(
        int $requestID,
        string $toStatus,
        string $actorType,
        ?int $actorID,
        ?string $note = null
    ): bool {
        $request = Database::fetchOne(
            "SELECT status FROM tblDataSubjectRequests WHERE requestID = ?",
            [$requestID],
            'i'
        );

        if (!$request) {
            throw new InvalidDSARTransitionException("DSAR request #{$requestID} not found.");
        }

        $from = (string) $request['status'];
        $actorType = self::resolveActorType($actorType);

        try {
            self::assertTransition($from, $toStatus);
        } catch (InvalidDSARTransitionException $e) {
            // 🚨 Log the illegal-transition ATTEMPT before re-throwing — this
            // is the "avoid a repeat of cycle-46's ENUM trap" audit guarantee:
            // a rejected transition must still leave a trace.
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    $actorID,
                    'dsar_illegal_transition',
                    'privacy',
                    'warning',
                    "Illegal DSAR transition attempted: '{$from}' -> '{$toStatus}' on request #{$requestID}",
                    ['requestID' => $requestID, 'from' => $from, 'to' => $toStatus, 'actorType' => $actorType]
                );
            }
            throw $e;
        }

        // 💾 Persist the new status.
        Database::query(
            "UPDATE tblDataSubjectRequests SET status = ? WHERE requestID = ?",
            [$toStatus, $requestID],
            'si'
        );

        // 🏁 Fulfilment statuses also stamp fulfilledAt.
        if (in_array($toStatus, ['fulfilled', 'partially_fulfilled'], true)) {
            Database::query(
                "UPDATE tblDataSubjectRequests SET fulfilledAt = NOW() WHERE requestID = ?",
                [$requestID],
                'i'
            );
        }

        self::appendEvent($requestID, $from, $toStatus, $actorType, $actorID, $note);

        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $actorID,
                'dsar_status_changed',
                'privacy',
                'info',
                "DSAR request #{$requestID} transitioned '{$from}' -> '{$toStatus}'" . ($note ? ": {$note}" : ''),
                ['requestID' => $requestID, 'from' => $from, 'to' => $toStatus, 'actorType' => $actorType]
            );
        }

        return true;
    }

    // ========================================================================
    // 📦 FULFILMENT DELEGATORS (the anti-duplication seam)
    // ========================================================================

    /**
     * 🌉 Ensure A Request Is 'in_progress' (bridges submitted/verified → in_progress)
     *
     * Every fulfilment delegator below needs the request in 'in_progress'
     * before it can transition to a terminal fulfilment status (the map only
     * allows in_progress -> {fulfilled|partially_fulfilled|...}). Rather than
     * requiring every caller to pre-transition through the not-yet-built
     * admin queue, this helper bridges automatically: a no-op if already
     * 'in_progress', otherwise a guarded transition() call (which throws, as
     * normal, if the current status has no legal edge to 'in_progress' — e.g.
     * identity not yet verified).
     *
     * @param int         $requestID The request to bridge
     * @param string      $actorType Attributed actor type for the bridging transition
     * @param int|null    $actorID   Attributed actor ID
     * @return void
     *
     * @throws InvalidDSARTransitionException If the request does not exist, or its current
     *                                         status has no legal edge to 'in_progress'
     */
    private static function ensureInProgress(int $requestID, string $actorType, ?int $actorID): void
    {
        $request = Database::fetchOne(
            "SELECT status FROM tblDataSubjectRequests WHERE requestID = ?",
            [$requestID],
            'i'
        );

        if (!$request) {
            throw new InvalidDSARTransitionException("DSAR request #{$requestID} not found.");
        }

        if ($request['status'] === 'in_progress') {
            return;
        }

        self::transition($requestID, 'in_progress', $actorType, $actorID, 'Auto-advanced to in_progress for fulfilment');
    }

    /**
     * 📦 Shared Export Delegator (portability + access)
     *
     * Delegates to AccountManager::exportUserData() — the SAME engine
     * settings/privacy.php's "Export My Data" button already uses (including
     * its built-in gdpr.export.rate_limit_hours throttle). On success, links
     * the resulting export_id and transitions to 'fulfilled'. On failure
     * (wrong password, rate-limited, etc), this NEVER crashes — it moves the
     * request to 'awaiting_user' with the failure reason noted, and returns
     * AccountManager's own failure array unchanged so the caller can surface
     * its message.
     *
     * @param int    $requestID The DSAR requestID
     * @param int    $userID    The user whose data is being exported
     * @param string $password  The user's current password (AccountManager verifies it)
     * @param string $framing   'portability' or 'access' — logged only, both use the identical engine
     * @return array Passthrough of AccountManager::exportUserData()'s return value
     */
    private static function fulfilExport(int $requestID, int $userID, string $password, string $framing): array
    {
        self::ensureInProgress($requestID, 'system', $userID);

        $result = AccountManager::exportUserData($userID, $password);

        if (!empty($result['success']) && !empty($result['export_id'])) {
            Database::query(
                "UPDATE tblDataSubjectRequests SET linkedExportID = ? WHERE requestID = ?",
                [(int) $result['export_id'], $requestID],
                'ii'
            );

            self::transition(
                $requestID,
                'fulfilled',
                'system',
                $userID,
                "Fulfilled via AccountManager::exportUserData() ({$framing}); export_id={$result['export_id']}"
            );
        } else {
            // 🛡️ Best-effort — never let a delegate failure (e.g. the
            // export engine's own 24h rate limit) crash the caller.
            try {
                self::transition(
                    $requestID,
                    'awaiting_user',
                    'system',
                    $userID,
                    'Export fulfilment failed: ' . ($result['message'] ?? 'unknown error')
                );
            } catch (InvalidDSARTransitionException $e) {
                // Already moved on by another actor concurrently — swallow;
                // the caller still gets AccountManager's own failure array below.
            }
        }

        return $result;
    }

    /**
     * 📦 Fulfil A Portability Request
     *
     * @param int    $requestID The DSAR requestID
     * @param int    $userID    The user whose data is being exported
     * @param string $password  The user's current password
     * @return array ['success'=>bool, 'message'=>string, 'export_id'=>int|null] — from AccountManager::exportUserData()
     *
     * @see https://gdpr-info.eu/art-20-gdpr/ — Right to Data Portability
     */
    public static function fulfilPortability(int $requestID, int $userID, string $password): array
    {
        return self::fulfilExport($requestID, $userID, $password, 'portability');
    }

    /**
     * 📖 Fulfil An Access Request
     *
     * Access and portability differ only in FRAMING (a machine-readable copy
     * IS the access record) — both are satisfied by the identical export
     * engine, so this is a thin wrapper over the same shared delegator.
     *
     * @param int    $requestID The DSAR requestID
     * @param int    $userID    The user whose data is being provided
     * @param string $password  The user's current password
     * @return array ['success'=>bool, 'message'=>string, 'export_id'=>int|null] — from AccountManager::exportUserData()
     *
     * @see https://gdpr-info.eu/art-15-gdpr/ — Right of Access
     */
    public static function fulfilAccess(int $requestID, int $userID, string $password): array
    {
        return self::fulfilExport($requestID, $userID, $password, 'access');
    }

    /**
     * 🗑️ Fulfil An Erasure Request
     *
     * Delegates to AccountManager::requestAccountDeletion() — the SAME
     * grace-period request already used by settings/privacy.php's "Delete
     * Your Account" flow. This method does NOT perform any deletion itself:
     * the existing AccountManager::processScheduledDeletions() cron performs
     * the actual erasure once the grace period lapses. On success, the DSAR
     * is left 'in_progress' (NOT 'fulfilled') with a resolutionNote recording
     * the scheduled date — a LATER cycle wires the cron's completion back
     * into a 'fulfilled' transition; that wiring is out of scope here (see
     * the class docblock's cycle-boundary notes).
     *
     * @param int    $requestID The DSAR requestID
     * @param int    $userID    The user requesting erasure
     * @param string $password  The user's current password
     * @return array ['success'=>bool, 'message'=>string, 'scheduled_for'=>string|null] — from AccountManager::requestAccountDeletion()
     *
     * @see https://gdpr-info.eu/art-17-gdpr/ — Right to Erasure
     */
    public static function fulfilErasure(int $requestID, int $userID, string $password): array
    {
        self::ensureInProgress($requestID, 'system', $userID);

        $result = AccountManager::requestAccountDeletion($userID, $password, "DSAR erasure request #{$requestID}");

        if (!empty($result['success'])) {
            $note = 'Erasure scheduled via AccountManager::requestAccountDeletion(); scheduled_for='
                . ($result['scheduled_for'] ?? 'unknown');

            Database::query(
                "UPDATE tblDataSubjectRequests SET resolutionNote = ? WHERE requestID = ?",
                [$note, $requestID],
                'si'
            );

            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    $userID,
                    'dsar_erasure_scheduled',
                    'privacy',
                    'info',
                    $note,
                    ['requestID' => $requestID]
                );
            }
        } else {
            try {
                self::transition(
                    $requestID,
                    'awaiting_user',
                    'system',
                    $userID,
                    'Erasure request failed: ' . ($result['message'] ?? 'unknown error')
                );
            } catch (InvalidDSARTransitionException $e) {
                // Best-effort only — swallow; caller still gets $result below.
            }
        }

        return $result;
    }

    /**
     * ✏️ Fulfil A Rectification Request
     *
     * Applies ONLY allowlisted tblUsers columns (RECTIFIABLE_USER_COLUMNS)
     * from admin-approved $approvedChanges — any other key is silently
     * filtered out and reported back under 'rejected', never reaching SQL
     * (see filterRectifiableColumns()). Records what was applied into
     * tblDataSubjectRequests.rectifyPayload (JSON) for audit, then
     * transitions the request to 'fulfilled' (bridging through 'in_progress'
     * first if needed — see ensureInProgress()).
     *
     * @param int   $requestID      The DSAR requestID
     * @param array $approvedChanges Column => new value pairs (only RECTIFIABLE_USER_COLUMNS are applied)
     * @return array ['success'=>bool, 'message'=>string, 'applied'=>array<int,string>, 'rejected'=>array<int,string>]
     *
     * @see https://gdpr-info.eu/art-16-gdpr/ — Right to Rectification
     */
    public static function fulfilRectification(int $requestID, array $approvedChanges): array
    {
        [$applied, $rejected] = self::filterRectifiableColumns($approvedChanges);

        if (empty($applied)) {
            return [
                'success'  => false,
                'message'  => 'No allowlisted fields were supplied to rectify.',
                'applied'  => [],
                'rejected' => $rejected,
            ];
        }

        $request = Database::fetchOne(
            "SELECT userID FROM tblDataSubjectRequests WHERE requestID = ?",
            [$requestID],
            'i'
        );

        if (!$request || empty($request['userID'])) {
            return [
                'success'  => false,
                'message'  => 'DSAR request not found or has no linked user account to rectify.',
                'applied'  => [],
                'rejected' => $rejected,
            ];
        }

        $targetUserID = (int) $request['userID'];

        // 💾 Every column NAME below came from RECTIFIABLE_USER_COLUMNS (a
        // fixed const), never directly from $approvedChanges' keys as raw
        // SQL text — filterRectifiableColumns() already threw away anything
        // not an exact allowlist match. Values are always bound parameters.
        $setClauses = [];
        $params = [];
        $types = '';
        foreach ($applied as $column => $value) {
            $setClauses[] = "`{$column}` = ?";
            $params[] = SecurityUtils::sanitizeString((string) $value);
            $types .= 's';
        }
        $params[] = $targetUserID;
        $types .= 'i';

        Database::query(
            "UPDATE tblUsers SET " . implode(', ', $setClauses) . " WHERE userID = ?",
            $params,
            $types
        );

        // 📋 Record what was applied (audit trail — mirrors the column's
        // documented purpose: "proposed field→value changes, admin-reviewed").
        Database::query(
            "UPDATE tblDataSubjectRequests SET rectifyPayload = ?, resolutionNote = ? WHERE requestID = ?",
            [
                json_encode($applied, JSON_UNESCAPED_UNICODE),
                'Rectified: ' . implode(', ', array_keys($applied)),
                $requestID,
            ],
            'ssi'
        );

        self::ensureInProgress($requestID, 'admin', null);
        self::transition($requestID, 'fulfilled', 'admin', null, 'Rectification applied: ' . implode(', ', array_keys($applied)));

        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $targetUserID,
                'dsar_rectification_applied',
                'privacy',
                'info',
                "DSAR rectification applied for request #{$requestID}: " . implode(', ', array_keys($applied)),
                ['requestID' => $requestID, 'appliedColumns' => array_keys($applied), 'rejectedColumns' => $rejected]
            );
        }

        return [
            'success'  => true,
            'message'  => 'Rectification applied successfully.',
            'applied'  => array_keys($applied),
            'rejected' => $rejected,
        ];
    }

    // ========================================================================
    // 📋 QUEUE + REPORTING (data source for a LATER admin queue — no UI yet)
    // ========================================================================

    /**
     * 📋 Get A Bounded, Filtered Page Of Requests
     *
     * BOUNDED pagination: $limit is always clamped to [1, MAX_QUEUE_LIMIT]
     * and $offset floored at 0, regardless of caller input — heeds the
     * project's standing "no unbounded LIMIT/OFFSET" lesson (mirrors
     * ConsentManager::getHistory()'s identical clamp).
     *
     * @param array $filters {
     *     @type string $status      Exact status match
     *     @type string $requestType Exact requestType match
     *     @type bool   $overdue     If truthy, restrict to dueDate < today AND status not terminal
     * }
     * @param int $limit  Requested page size (clamped to [1, MAX_QUEUE_LIMIT])
     * @param int $offset Requested offset (floored at 0)
     * @return array<int,array<string,mixed>> Rows, ordered soonest-due first
     */
    public static function getQueue(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min($limit, self::MAX_QUEUE_LIMIT));
        $offset = max(0, $offset);

        $where = [];
        $params = [];
        $types = '';

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = (string) $filters['status'];
            $types .= 's';
        }

        if (!empty($filters['requestType'])) {
            $where[] = 'requestType = ?';
            $params[] = (string) $filters['requestType'];
            $types .= 's';
        }

        if (!empty($filters['overdue'])) {
            // 🔒 Built ONLY from the hardcoded TERMINAL_STATUSES const — never
            // from caller input — so this string fragment is safe to inline.
            $where[] = "dueDate < CURDATE() AND status NOT IN ('" . implode("','", self::TERMINAL_STATUSES) . "')";
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // 🔒 $limit/$offset are integer-clamped above before ever reaching
        // the SQL text — the only interpolated fragments here.
        return Database::fetchAll(
            "SELECT * FROM tblDataSubjectRequests {$whereSql}
             ORDER BY dueDate ASC, requestID ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params,
            $types
        );
    }

    /**
     * 📊 Get A Compliance Report For A Date Range
     *
     * Counts by requestType + status, an SLA-met percentage among CLOSED
     * (fulfilled/partially_fulfilled) requests in range, and a bounded
     * overdue list (dueDate < today AND status not terminal).
     *
     * @param string $from Range start, 'Y-m-d' (bound as a parameter, never interpolated)
     * @param string $to   Range end, 'Y-m-d' (bound as a parameter, never interpolated)
     * @return array{
     *     from: string, to: string,
     *     countsByTypeAndStatus: array<int,array<string,mixed>>,
     *     totalClosed: int, slaMetCount: int, slaMetPercent: float|null,
     *     overdue: array<int,array<string,mixed>>
     * }
     */
    public static function getComplianceReport(string $from, string $to): array
    {
        $fromDateTime = $from . ' 00:00:00';
        $toDateTime = $to . ' 23:59:59';

        $countsByTypeAndStatus = Database::fetchAll(
            "SELECT requestType, status, COUNT(*) AS total
             FROM tblDataSubjectRequests
             WHERE submittedAt >= ? AND submittedAt <= ?
             GROUP BY requestType, status",
            [$fromDateTime, $toDateTime],
            'ss'
        );

        $slaRow = Database::fetchOne(
            "SELECT
                COUNT(*) AS totalClosed,
                SUM(CASE WHEN fulfilledAt IS NOT NULL AND fulfilledAt <= CONCAT(dueDate, ' 23:59:59')
                         THEN 1 ELSE 0 END) AS metSla
             FROM tblDataSubjectRequests
             WHERE submittedAt >= ? AND submittedAt <= ?
               AND status IN ('fulfilled', 'partially_fulfilled')",
            [$fromDateTime, $toDateTime],
            'ss'
        );

        $totalClosed = (int) ($slaRow['totalClosed'] ?? 0);
        $metSla = (int) ($slaRow['metSla'] ?? 0);
        $slaMetPercent = $totalClosed > 0 ? round(($metSla / $totalClosed) * 100, 2) : null;

        // 🔒 TERMINAL_STATUSES is a fixed hardcoded const — safe to inline;
        // MAX_QUEUE_LIMIT is a fixed int const — safe to inline as LIMIT.
        $overdue = Database::fetchAll(
            "SELECT requestID, requestType, status, dueDate, userID, requesterEmail
             FROM tblDataSubjectRequests
             WHERE dueDate < CURDATE() AND status NOT IN ('" . implode("','", self::TERMINAL_STATUSES) . "')
             ORDER BY dueDate ASC
             LIMIT " . self::MAX_QUEUE_LIMIT,
            [],
            ''
        );

        return [
            'from'                  => $from,
            'to'                    => $to,
            'countsByTypeAndStatus' => $countsByTypeAndStatus,
            'totalClosed'           => $totalClosed,
            'slaMetCount'           => $metSla,
            'slaMetPercent'         => $slaMetPercent,
            'overdue'               => $overdue,
        ];
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS
    // ========================================================================

    /**
     * 🔧 Append A Row To tblDataSubjectRequestEvents
     *
     * Shared by transition() (guarded status changes) and
     * startIdentityVerification() (same-status token re-issuance, which
     * deliberately bypasses the transition map).
     *
     * @param int         $requestID
     * @param string|null $fromStatus Nullable — NULL for the request's genesis 'submitted' event
     * @param string      $toStatus
     * @param string      $actorType
     * @param int|null    $actorID
     * @param string|null $note
     * @return void
     */
    private static function appendEvent(
        int $requestID,
        ?string $fromStatus,
        string $toStatus,
        string $actorType,
        ?int $actorID,
        ?string $note
    ): void {
        // 🕐 createdAt is written explicitly from PHP's clock (rather than
        // relying on the column's DEFAULT CURRENT_TIMESTAMP) — same
        // discipline as ConsentManager::record()'s explicit $now — so this
        // timestamp is guaranteed to be directly comparable against the
        // PHP-clock 'now' used by verifyIdentity()'s TTL check, regardless
        // of the MySQL session's configured time_zone.
        Database::query(
            "INSERT INTO tblDataSubjectRequestEvents (requestID, fromStatus, toStatus, actorType, actorID, note, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$requestID, $fromStatus, $toStatus, $actorType, $actorID, $note, date('Y-m-d H:i:s')],
            'isssiss'
        );
    }

    /**
     * 🌍 Resolve The Governing Regime + SLA (G-004 Layer 3 retro-wire)
     *
     * The ONLY place submit() touches RegimeResolver. Deliberately
     * best-effort at every step — a missing RegimeResolver.php (see the
     * guarded require at the top of this file), a resolve() failure, or a
     * slaDaysFor() failure ALL degrade to the exact pre-L3 behaviour
     * (`regimeCode => null`, `slaDays => dsar.default_sla_days`) rather than
     * ever letting a resolver problem break DSAR submission — per the class
     * docblock's "regimeCode / per-regime SLA" contract.
     *
     * @param int|null    $userID
     * @param int|null    $partnerID
     * @param string|null $countryHint
     * @return array{regimeCode: string|null, slaDays: int}
     */
    private static function resolveRegimeAndSla(?int $userID, ?int $partnerID, ?string $countryHint): array
    {
        if (!class_exists('RegimeResolver')) {
            return ['regimeCode' => null, 'slaDays' => (int) getSetting('dsar.default_sla_days', 30)];
        }

        $regimeCode = null;
        try {
            $resolved = RegimeResolver::resolve($userID, $partnerID, $countryHint);
            $regimeCode = $resolved['regimeCode'] ?? null;
        } catch (Throwable $e) {
            $regimeCode = null; // best-effort — never let a resolver failure break submission
        }

        try {
            $slaDays = RegimeResolver::slaDaysFor($regimeCode);
        } catch (Throwable $e) {
            $slaDays = (int) getSetting('dsar.default_sla_days', 30);
        }

        return ['regimeCode' => $regimeCode, 'slaDays' => $slaDays];
    }

    /**
     * 🔧 Compute A Due Date From An SLA Day Count — pure, DB-free.
     *
     * @param string $fromDateTime 'Y-m-d H:i:s' the SLA clock starts from
     * @param int    $slaDays      Number of days to add (negative values clamp to 0)
     * @return string 'Y-m-d'
     */
    private static function computeDueDate(string $fromDateTime, int $slaDays): string
    {
        $slaDays = max(0, $slaDays);

        return date('Y-m-d', strtotime($fromDateTime . " +{$slaDays} days"));
    }

    /**
     * 🔧 Resolve And Validate A Requested Actor Type — pure, DB-free.
     *
     * An unrecognised/missing value falls back to 'user' rather than letting
     * an invalid value reach the tblDataSubjectRequestEvents.actorType ENUM
     * (same fail-safe idiom as ConsentManager::resolveMechanism()).
     *
     * @param string|null $requested
     * @return string A value guaranteed to be a member of VALID_ACTOR_TYPES
     */
    private static function resolveActorType(?string $requested): string
    {
        if ($requested !== null && in_array($requested, self::VALID_ACTOR_TYPES, true)) {
            return $requested;
        }

        return 'user';
    }

    /**
     * 🔧 Is A Token Past Its TTL? — pure, DB-free.
     *
     * @param string $issuedAt   'Y-m-d H:i:s' the token was issued
     * @param int    $ttlMinutes Lifetime in minutes (negative values clamp to 0)
     * @param string $now        'Y-m-d H:i:s' the current comparison instant
     * @return bool True if $now is strictly after $issuedAt + $ttlMinutes
     */
    private static function isTokenExpired(string $issuedAt, int $ttlMinutes, string $now): bool
    {
        $ttlMinutes = max(0, $ttlMinutes);
        $expiry = strtotime($issuedAt . " +{$ttlMinutes} minutes");

        return strtotime($now) > $expiry;
    }

    /**
     * 🔧 Filter $approvedChanges Down To The tblUsers Rectification Allowlist
     * — pure, DB-free.
     *
     * @param array $approvedChanges Caller-supplied column => value pairs
     * @return array{0:array<string,mixed>,1:array<int,string>} [applied, rejectedColumnNames]
     */
    private static function filterRectifiableColumns(array $approvedChanges): array
    {
        $applied = [];
        $rejected = [];

        foreach ($approvedChanges as $column => $value) {
            if (is_string($column) && in_array($column, self::RECTIFIABLE_USER_COLUMNS, true)) {
                $applied[$column] = $value;
            } else {
                $rejected[] = (string) $column;
            }
        }

        return [$applied, $rejected];
    }

    /**
     * 🔧 Interpret A tblSettings String Value As A Boolean.
     *
     * Identical idiom to ConsentManager::isTruthySetting() — duplicated
     * rather than shared, matching this codebase's existing per-class
     * convention for small settings helpers.
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

    /**
     * 📣 Best-Effort Admin Notify On New DSAR
     *
     * ADMIN-NOTIFY GUARDRAIL (avoids a repeat of cycle-46's ENUM trap):
     * NotificationService::create() validates $type against its OWN
     * VALID_TYPES allowlist (security|account|system|billing|social — no
     * 'privacy'/'compliance' member exists). Inventing a new type here would
     * silently fail NotificationService's own validation (it fails-safe,
     * returning 0, not throwing — see NotificationService::create()).
     * 'system' IS a member of that allowlist and is a reasonable fit for a
     * "new compliance item needs review" notification, so this class uses
     * it — guarded by NOTIFICATION_SERVICE_VALID_TYPES_MIRROR (a literal
     * mirror of that ENUM, read directly from NotificationService.php before
     * writing this method) so a FUTURE edit that ever removes 'system' from
     * NotificationService causes this to auto-degrade to a no-op rather than
     * silently spam ErrorLogger. In every case, the rich admin-notify UX
     * (proper broadcast to a configurable admin group, in the queue UI) is
     * Layer 1 / Cycle C — this is deliberately best-effort: any failure here
     * is swallowed, and the tblActivityLog 'privacy'-category audit trail
     * written by submit() above is the reliable, durable signal regardless
     * of whether this in-app notification succeeds.
     *
     * There is no existing "list all admin userIDs" helper anywhere in this
     * codebase (AccessControl is a per-request instance class scoped to the
     * CURRENT user, not a directory of admins) — the query below mirrors the
     * closest existing precedent (CredentialResetService's direct
     * `tblUsers WHERE isSuperAdmin = 1` lookup).
     *
     * @param int    $requestID
     * @param string $requestType
     * @return void
     */
    private static function notifyAdminsOfNewRequest(int $requestID, string $requestType): void
    {
        if (!self::isTruthySetting(getSetting('dsar.admin.notify_on_new', 'true'))) {
            return;
        }

        $suitableType = 'system';
        if (!in_array($suitableType, self::NOTIFICATION_SERVICE_VALID_TYPES_MIRROR, true)) {
            // 🛡️ No suitable existing type — skip in-app notify entirely
            // rather than invent one; ActivityLogger's audit trail (written
            // by submit()) remains the reliable signal.
            return;
        }

        // 📚 web/private_html/notifications/ is NOT scanned by config.php's
        // spl_autoload_register() directory list (confirmed: only
        // auth/security/utils/api/api-controllers/email/database) — unlike
        // AccountManager (under auth/, autoloads automatically), this needs
        // an explicit guarded require, same idiom as this class's own
        // require of InvalidDSARTransitionException.php above.
        if (!class_exists('NotificationService')) {
            $notificationServicePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'notifications'
                . DIRECTORY_SEPARATOR . 'NotificationService.php';
            if (file_exists($notificationServicePath)) {
                require_once $notificationServicePath;
            } else {
                return; // best-effort — nothing to notify with
            }
        }

        try {
            $admins = Database::fetchAll(
                "SELECT userID FROM tblUsers WHERE isSuperAdmin = 1 AND accountStatus = 'active'",
                [],
                ''
            );
        } catch (Throwable $e) {
            return; // best-effort only — never let admin lookup fail the submission
        }

        foreach ($admins as $admin) {
            try {
                NotificationService::create(
                    (int) $admin['userID'],
                    $suitableType,
                    'New Data Subject Request',
                    "A new DSAR (#{$requestID}, type: {$requestType}) has been submitted and needs review.",
                    ['actionUrl' => '/admin/compliance/dsar', 'priority' => 'normal']
                );
            } catch (Throwable $e) {
                // 🛡️ Best-effort — one bad notification must never block
                // the rest, and never block the submission that already
                // succeeded above.
                continue;
            }
        }
    }
}
