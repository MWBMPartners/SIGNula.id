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
 * 🛡️ SIGNula - Consent Manager (G-004 Layer 1 / FG-002a)
 * ============================================================================
 *
 * Purpose: Append-only consent audit trail for cookies/marketing/legal
 *          consent decisions, covering both authenticated users (userID) and
 *          pre-login/anonymous visitors (visitorID — a cookie-backed UUID).
 * PHP Version: 8.3+
 *
 * Design contract (see .dev-team/specs/G-004.md §2.1/§2.2/§2.4):
 * - `tblConsentRecords` is APPEND-ONLY. A decision is never UPDATEd to flip
 *   it — every grant/withdraw is a NEW row. `getCurrent()`/`getEffectiveConsents()`
 *   always resolve the LATEST row per consentType, so the table itself is the
 *   full audit history and "current state" is just "the newest row".
 * - No hard FK to tblUsers, and this class must never DELETE a consent row.
 *   A regulator may ask "prove this user consented" AFTER the account is
 *   erased — AccountManager::permanentlyDeleteAccount() anonymises
 *   (userID=NULL, ipAddress=NULL, userAgent='REDACTED') rather than
 *   cascading a delete. That anonymisation edit belongs to the DSAR cycle
 *   (Layer 1 / Cycle B), NOT to this class.
 * - `regimeCode` is a nullable soft column in THIS cycle. The regime
 *   configuration model (tblComplianceRegimes / RegimeResolver) is Layer 3
 *   (a later build cycle) — until then this class simply stores whatever the
 *   caller passes via $opts['regimeCode'] (typically nothing => NULL) and
 *   never invents a value.
 *
 * Database Tables:
 * - tblConsentRecords: append-only consent audit trail (see migration 040)
 *
 * @see _database/migrations/040_consent_and_dsar.sql
 * @see .dev-team/specs/G-004.md §2.1, §2.2, §2.4
 * @see https://gdpr-info.eu/art-7-gdpr/ (conditions for consent)
 * @see https://www.php.net/manual/en/function.inet-pton.php
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class ConsentManager
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /**
     * ✅ Allowed values for tblConsentRecords.mechanism — MUST mirror the SQL
     * ENUM in migration 040 exactly. Anything outside this set falls back to
     * 'banner' in record() rather than causing an SQL error.
     *
     * @var array<int,string>
     */
    private const VALID_MECHANISMS = [
        'banner',
        'preference_center',
        'signup',
        'api',
        'gpc_signal',
        'admin',
        'import',
    ];

    /** @var int Hard ceiling on getHistory()'s LIMIT, regardless of caller request. */
    private const MAX_HISTORY_LIMIT = 200;

    // ========================================================================
    // 📝 RECORD A CONSENT DECISION
    // ========================================================================

    /**
     * 📝 Record Consent Decision
     *
     * Inserts a NEW append-only row into tblConsentRecords. This method NEVER
     * updates an existing row to flip a decision — every call is a fresh
     * INSERT, so the full grant/withdraw history is always reconstructable.
     *
     * @param int|null    $userID      Authenticated user (null for anonymous visitors)
     * @param string|null $visitorID   Anonymous visitor UUID (null once authenticated,
     *                                 or when the caller only has a userID)
     * @param string      $consentType Free-but-conventional key, e.g. 'cookies.analytics'
     * @param bool        $granted     true = granted/opt-in, false = withdrawn/opt-out
     * @param array       $opts        Optional overrides:
     *                                 - 'mechanism'   string  how the decision was captured
     *                                                 (validated against VALID_MECHANISMS,
     *                                                 falls back to 'banner')
     *                                 - 'policyVersion' string|null policy/banner version
     *                                 - 'regimeCode'  string|null resolved jurisdiction
     *                                                 (L3 populates this later; NULL for now)
     * @return int New consentID (tblConsentRecords.consentID)
     *
     * @see https://gdpr-info.eu/art-7-gdpr/
     */
    public static function record(
        ?int $userID,
        ?string $visitorID,
        string $consentType,
        bool $granted,
        array $opts = []
    ): int {
        // 🎯 Resolve mechanism against the SQL ENUM allowlist — an unrecognised
        // value must never reach the database (it would throw a MySQL ENUM
        // truncation error under strict mode), so we fail safe to 'banner'.
        $mechanism = self::resolveMechanism($opts['mechanism'] ?? null);

        // 🌍 L3 (regime configuration model) is a later build cycle — leave
        // NULL unless the caller explicitly supplies a resolved regime code.
        $regimeCode = $opts['regimeCode'] ?? null;

        // 📜 Policy/banner version the decision was made against (L2 will
        // populate this from tblPolicyVersions; NULL is a valid "unversioned" state).
        $policyVersion = $opts['policyVersion'] ?? null;

        // 📦 Pack the client IP into VARBINARY(16) via inet_pton() — same
        // minimisation idiom as web/_config/config.php's ipInAnyCidr(). The
        // '@' suppresses the E_WARNING inet_pton() raises for a malformed
        // address; a failed pack degrades to NULL rather than throwing, since
        // consent capture must never hard-fail because of an IP-format quirk.
        $clientIP = getClientIP();
        $ipPacked = @inet_pton($clientIP);
        if ($ipPacked === false) {
            $ipPacked = null;
        }

        // 📱 User agent, truncated to the column width (VARCHAR(512)).
        $userAgent = substr(getUserAgent(), 0, 512);

        // 🕐 Single timestamp shared by the stored row AND the proof hash, so
        // the hash can always be re-derived and verified against the row it
        // describes (computed here in PHP rather than relying on the
        // column's DEFAULT CURRENT_TIMESTAMP, which the hash could not see).
        $now = date('Y-m-d H:i:s');

        // 🔏 Tamper-evidence hash — only computed when the operator has the
        // feature enabled (consent.proof_hash.enabled).
        $proofHashEnabled = self::isTruthySetting(getSetting('consent.proof_hash.enabled', 'true'));
        $proofHash = $proofHashEnabled
            ? self::computeProofHash($consentType, $granted, $policyVersion, $now, $ipPacked, $userAgent)
            : null;

        // 🔗 Explicit MySQLi type string, one character per bound param above,
        // in column order: userID(i) visitorID(s) consentType(s) granted(i)
        // policyVersion(s) mechanism(s) regimeCode(s) ipAddress(s) userAgent(s)
        // proofHash(s) createdAt(s). ipAddress is bound as 's' (not 'b') per
        // the house pattern for short packed-binary columns — mysqli's 's'
        // type sends the raw bytes as-is for a value this size, without
        // invoking the send_long_data() blob-streaming path. Nullable columns
        // (userID/visitorID/policyVersion/regimeCode/ipAddress/proofHash) are
        // still bound with their normal type character — mysqli accepts a
        // PHP null for any bound type and sends SQL NULL.
        $types = 'iss' . 'i' . 'sss' . 'sss' . 's';

        // 💾 Append-only INSERT — see class docblock: this table is NEVER
        // UPDATEd to flip a decision, only ever appended to.
        $consentID = Database::insert(
            "INSERT INTO tblConsentRecords
                (userID, visitorID, consentType, granted, policyVersion, mechanism,
                 regimeCode, ipAddress, userAgent, proofHash, createdAt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $userID,
                $visitorID,
                $consentType,
                $granted ? 1 : 0,
                $policyVersion,
                $mechanism,
                $regimeCode,
                $ipPacked,
                $userAgent,
                $proofHash,
                $now,
            ],
            $types
        );

        // 📝 Audit trail (privacy category) — every consent decision is a
        // security-relevant event regardless of whether userID is known yet.
        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $userID,
                'consent_' . ($granted ? 'grant' : 'withdraw'),
                'privacy',
                'info',
                'Consent ' . ($granted ? 'granted' : 'withdrawn') . ' for ' . $consentType,
                [
                    'consentType' => $consentType,
                    'mechanism'   => $mechanism,
                    'visitorID'   => $visitorID,
                ]
            );
        }

        return $consentID;
    }

    // ========================================================================
    // 🔍 READ CURRENT / EFFECTIVE STATE
    // ========================================================================

    /**
     * 🔍 Get Current Consent Decision
     *
     * Returns the LATEST row for the given consentType (append-only table,
     * so "current" == "most recent row"), scoped to a userID if given,
     * otherwise to a visitorID. Returns null if neither identifier resolves
     * any row, or if neither identifier is supplied at all.
     *
     * @param int|null    $userID      Authenticated user, or null
     * @param string|null $visitorID   Anonymous visitor UUID, or null
     * @param string      $consentType Consent key to look up
     * @return array|null Latest tblConsentRecords row, or null if none exists
     */
    public static function getCurrent(?int $userID, ?string $visitorID, string $consentType): ?array
    {
        [$where, $params, $types] = self::buildIdentityWhere($userID, $visitorID);

        if ($where === null) {
            return null;
        }

        $row = Database::fetchOne(
            "SELECT * FROM tblConsentRecords
             WHERE {$where} AND consentType = ?
             ORDER BY createdAt DESC, consentID DESC
             LIMIT 1",
            [...$params, $consentType],
            $types . 's'
        );

        return $row ?: null;
    }

    /**
     * 🗺️ Get Effective (Latest-Per-Type) Consents
     *
     * Returns a map of consentType => bool (granted/withdrawn), reflecting
     * only the most recent decision for each type. Rows are fetched oldest
     * first and folded into the map in order, so a later row always
     * overwrites an earlier one for the same consentType — the net effect
     * is "last write wins", i.e. the latest decision.
     *
     * @param int|null    $userID    Authenticated user, or null
     * @param string|null $visitorID Anonymous visitor UUID, or null
     * @return array<string,bool> consentType => granted
     */
    public static function getEffectiveConsents(?int $userID, ?string $visitorID): array
    {
        [$where, $params, $types] = self::buildIdentityWhere($userID, $visitorID);

        if ($where === null) {
            return [];
        }

        $rows = Database::fetchAll(
            "SELECT consentType, granted, createdAt, consentID
             FROM tblConsentRecords
             WHERE {$where}
             ORDER BY createdAt ASC, consentID ASC",
            $params,
            $types
        );

        $effective = [];
        foreach ($rows as $row) {
            // 🔄 Ascending order means each iteration overwrites the previous
            // value for the same key — the final value in the map is always
            // the chronologically latest decision.
            $effective[$row['consentType']] = (bool) $row['granted'];
        }

        return $effective;
    }

    /**
     * ✅ Has The Subject Granted This Consent?
     *
     * Convenience wrapper over getCurrent() for callers that only care about
     * the boolean state (e.g. gating a feature behind analytics consent).
     * A subject with NO recorded decision is treated as NOT granted
     * (fail-safe default — never assume consent that was never captured).
     *
     * @param int|null    $userID      Authenticated user, or null
     * @param string|null $visitorID   Anonymous visitor UUID, or null
     * @param string      $consentType Consent key to check
     * @return bool
     */
    public static function hasGranted(?int $userID, ?string $visitorID, string $consentType): bool
    {
        $current = self::getCurrent($userID, $visitorID, $consentType);

        return $current !== null && (bool) $current['granted'];
    }

    // ========================================================================
    // 🔗 VISITOR → USER RECONCILIATION
    // ========================================================================

    /**
     * 🔗 Reconcile Anonymous Visitor Consent To A New User
     *
     * On signup/login, stamps every prior anonymous (userID IS NULL) consent
     * row for a visitorID with the newly-known userID, so the consent trail
     * stays continuous across the pre-login → authenticated transition
     * instead of starting a fresh, disconnected history.
     *
     * Rows that already have a userID (should not normally occur for a
     * visitorID row, but guarded defensively) are left untouched.
     *
     * @param string $visitorID Anonymous visitor UUID to reconcile
     * @param int    $userID    The user the visitor has now authenticated as
     * @return int Number of rows updated
     */
    public static function reconcileVisitorToUser(string $visitorID, int $userID): int
    {
        Database::query(
            "UPDATE tblConsentRecords
             SET userID = ?
             WHERE visitorID = ? AND userID IS NULL",
            [$userID, $visitorID],
            'is'
        );

        return Database::getAffectedRows();
    }

    // ========================================================================
    // 🕓 HISTORY (PRIVATE — reserved for a future paginated consent-history UI)
    // ========================================================================

    /**
     * 🕓 Get Bounded Consent History
     *
     * Returns up to $limit of the subject's consent rows, newest first. The
     * limit is always clamped to [1, MAX_HISTORY_LIMIT] regardless of what
     * the caller requests, so this can never be used to pull an unbounded
     * result set (heeds the project's standing "no unbounded LIMIT/OFFSET"
     * lesson — see .dev-team/specs/G-004.md §2.7 item 7 / §6).
     *
     * Private for this build cycle: Layer 1 / Cycle A only needs the
     * latest-per-type view (getEffectiveConsents(), used by
     * settings/privacy.php's "My consent history" section). This helper is
     * scaffolding for a future paginated history endpoint (the admin DSAR
     * queue / a richer user-facing history view) added in a later cycle.
     *
     * @param int|null    $userID    Authenticated user, or null
     * @param string|null $visitorID Anonymous visitor UUID, or null
     * @param int         $limit     Requested row cap (clamped to MAX_HISTORY_LIMIT)
     * @return array<int,array<string,mixed>> Rows, newest first
     */
    private static function getHistory(?int $userID, ?string $visitorID, int $limit): array
    {
        [$where, $params, $types] = self::buildIdentityWhere($userID, $visitorID);

        if ($where === null) {
            return [];
        }

        // 🔒 Clamp to a sane, positive, bounded window before it ever touches SQL.
        $limit = max(1, min($limit, self::MAX_HISTORY_LIMIT));

        return Database::fetchAll(
            "SELECT consentID, consentType, granted, mechanism, policyVersion, createdAt
             FROM tblConsentRecords
             WHERE {$where}
             ORDER BY createdAt DESC, consentID DESC
             LIMIT {$limit}",
            $params,
            $types
        );
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS
    // ========================================================================

    /**
     * 🔧 Build the userID/visitorID WHERE fragment shared by getCurrent(),
     * getEffectiveConsents() and getHistory().
     *
     * userID takes priority when both are supplied (mirrors reconcileVisitorToUser()'s
     * post-login state, where a caller may still be holding a stale visitorID
     * cookie alongside the now-known userID). Returns [null, [], ''] when
     * neither identifier is available — callers treat that as "no result".
     *
     * @param int|null    $userID
     * @param string|null $visitorID
     * @return array{0:?string,1:array<int,mixed>,2:string} [whereFragment, params, types]
     */
    private static function buildIdentityWhere(?int $userID, ?string $visitorID): array
    {
        if ($userID !== null) {
            return ['userID = ?', [$userID], 'i'];
        }

        if ($visitorID !== null) {
            return ['visitorID = ?', [$visitorID], 's'];
        }

        return [null, [], ''];
    }

    /**
     * 🔧 Resolve And Validate A Requested Mechanism
     *
     * Pure, DB-free logic pulled out of record() so it can be unit-tested in
     * isolation (record() itself cannot run without a live Database
     * connection — see .dev-team/specs/G-004.md §8). Validates the caller's
     * requested mechanism against the SQL ENUM allowlist (VALID_MECHANISMS,
     * which MUST mirror tblConsentRecords.mechanism in migration 040
     * exactly); anything missing or unrecognised falls back to 'banner'
     * rather than letting an invalid value reach the database.
     *
     * @param string|null $requested Caller-supplied mechanism (or null)
     * @return string A value guaranteed to be a member of VALID_MECHANISMS
     */
    private static function resolveMechanism(?string $requested): string
    {
        if ($requested !== null && in_array($requested, self::VALID_MECHANISMS, true)) {
            return $requested;
        }

        return 'banner';
    }

    /**
     * 🔏 Compute The Tamper-Evidence Proof Hash
     *
     * Pure, DB-free logic pulled out of record() so it can be unit-tested in
     * isolation. SHA-256 over a pipe-delimited concatenation of the fields
     * that describe the consent decision — deterministic (same inputs always
     * produce the same hash) so an external auditor can re-derive and verify
     * it against the stored row, and sensitive to every input (changing any
     * single field changes the hash) so a tampered row is detectable.
     *
     * Field order is fixed and MUST match .dev-team/specs/G-004.md §2.2:
     * consentType | granted(1/0) | policyVersion | timestamp | packedIP | userAgent.
     *
     * @param string      $consentType   Consent key
     * @param bool        $granted       Grant/withdraw decision
     * @param string|null $policyVersion Policy/banner version (nullable)
     * @param string      $now           Timestamp the row is stored with ('Y-m-d H:i:s')
     * @param string|null $ipPacked      Packed IP bytes from inet_pton(), or null
     * @param string      $userAgent     User agent string
     * @return string 64-character lowercase hex SHA-256 digest
     */
    private static function computeProofHash(
        string $consentType,
        bool $granted,
        ?string $policyVersion,
        string $now,
        ?string $ipPacked,
        string $userAgent
    ): string {
        return hash(
            'sha256',
            $consentType . '|'
            . ($granted ? '1' : '0') . '|'
            . ($policyVersion ?? '') . '|'
            . $now . '|'
            . ($ipPacked ?? '') . '|'
            . $userAgent
        );
    }

    /**
     * 🔧 Interpret a tblSettings string value as a boolean.
     *
     * Settings are stored as TEXT ('true'/'false', '1'/'0', etc.) — this
     * mirrors the truthy-string handling already used elsewhere in the
     * codebase (e.g. AccountManager's `$notifyAdmin === 'true' || $notifyAdmin === '1'`)
     * so 'consent.proof_hash.enabled' behaves consistently with the rest of
     * the settings surface.
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
