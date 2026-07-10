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
 * 🚨 SIGNula - Breach Manager (G-004 Layer 4b §5.1 — the FINAL compliance slice)
 * ============================================================================
 *
 * Purpose: Track a data-breach incident through a simple operator-driven
 *          lifecycle (detected → assessing → contained → notifying → closed)
 *          and COMPUTE — never invent — the per-regime notification deadlines
 *          that follow from it.
 * PHP Version: 8.3+
 *
 * ----------------------------------------------------------------------------
 * 🔒 THE HARD RULE — machinery only, NEVER legal content or a hardcoded window
 * ----------------------------------------------------------------------------
 * This class computes deadlines and tracks status ONLY. It:
 *   - NEVER auto-files a notification with a regulator or a data subject.
 *   - NEVER writes breach notification COPY — `summary`/`remediation`/`note`
 *     are always operator/DPO-authored free text, stored verbatim (trimmed
 *     only — never fabricated, never templated).
 *   - NEVER hardcodes a regime-specific notification window as a PHP
 *     literal. Every `dueAt` is `detectedAt + RegimeResolver::breachWindowHours($regimeCode)`
 *     hours — the SAME resolver Layer 3 already exposes for exactly this
 *     purpose (see that method's own docblock: "a future BreachManager
 *     treats a null window conservatively itself" — this class IS that
 *     future consumer). When a regime has no configured window,
 *     `breachWindowHours()` returns null and this class degrades
 *     gracefully: `dueAt` stays NULL, `status` stays 'pending', and a
 *     `note` records that the window is not yet configured — it NEVER
 *     crashes and NEVER guesses a number.
 *
 * Database Tables (see migration 044):
 * - tblBreachIncidents     : one row per incident (operator-opened, no seeds)
 * - tblBreachNotifications : one row per (incident, regimeCode, audience) —
 *                            the computed deadline + status
 *
 * @see _database/migrations/044_breach_ropa_agegate.sql
 * @see web/private_html/compliance/RegimeResolver.php — breachWindowHours(), the ONLY source of any window number
 * @see web/private_html/compliance/DSARManager.php — sibling class / house style template
 * @see web/public_html/admin/compliance/breaches/index.php — the admin UI this backs
 * @see .dev-team/specs/G-004.md §5.1
 * @see https://gdpr-info.eu/art-33-gdpr/ (the kind of obligation a breachNotifyHours window encodes — this class names no such value itself)
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 🌍 RegimeResolver — web/private_html/compliance/ is not covered by
// config.php's spl_autoload_register() directory list (same situation as
// DSARManager.php/ConsentManager.php — see their identical notes). A missing
// RegimeResolver.php is NOT fatal here: computeNotificationDeadlines() is
// explicitly best-effort per regimeCode (see that method's docblock) — if
// this require can't find the file, class_exists('RegimeResolver') simply
// stays false and every window resolves to null (graceful degrade).
if (!class_exists('RegimeResolver')) {
    $regimeResolverPath = __DIR__ . DIRECTORY_SEPARATOR . 'RegimeResolver.php';
    if (file_exists($regimeResolverPath)) {
        require_once $regimeResolverPath;
    }
}

class BreachManager
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var array<int,string> tblBreachIncidents.severity ENUM — MUST mirror migration 044 exactly. */
    private const VALID_SEVERITIES = ['low', 'medium', 'high', 'critical'];

    /** @var array<int,string> tblBreachIncidents.status ENUM — MUST mirror migration 044 exactly. */
    private const VALID_STATUSES = ['detected', 'assessing', 'contained', 'notifying', 'closed'];

    /** @var array<int,string> tblBreachNotifications.audience ENUM — MUST mirror migration 044 exactly. */
    private const VALID_AUDIENCES = ['authority', 'data_subjects'];

    /** @var int Hard ceiling on getOverdueNotifications()'s result set. */
    private const MAX_OVERDUE_LIMIT = 200;

    /**
     * 🛡️ Matches `tblComplianceRegimes.regimeCode` / `tblBreachNotifications.regimeCode`'s
     * VARCHAR(16) column width (migrations 042/044) — see
     * computeNotificationDeadlines()'s length guard.
     */
    private const MAX_REGIME_CODE_LENGTH = 16;

    // ========================================================================
    // 🚨 OPEN A NEW INCIDENT
    // ========================================================================

    /**
     * 🚨 Open A New Breach Incident
     *
     * `reference` is auto-generated (BRCH-{year}-{sequence}) when not
     * supplied. `summary`/`remediation`/`discoveredBy` are trimmed only —
     * this class NEVER fabricates, templates, or pre-fills any of this text;
     * an empty submission stores NULL, not a placeholder.
     *
     * @param array $incident {
     *     @type string|null $reference          Human reference; auto-generated if omitted
     *     @type string      $severity            One of VALID_SEVERITIES (default 'medium')
     *     @type string      $status              One of VALID_STATUSES (default 'detected')
     *     @type string|null $detectedAt          'Y-m-d H:i:s'; defaults to now
     *     @type string|null $discoveredBy
     *     @type int|null    $affectedUserCount
     *     @type array|null  $dataCategories       JSON-encodable array
     *     @type array|null  $regimeCodes          JSON-encodable array of tblComplianceRegimes.regimeCode values
     *     @type string|null $summary              Operator/DPO-authored — stored verbatim (trimmed)
     *     @type string|null $remediation           Operator/DPO-authored — stored verbatim (trimmed)
     *     @type int|null    $assignedAdminID
     * }
     * @return int New incidentID
     *
     * @throws InvalidArgumentException If severity/status is supplied but not a recognised value
     */
    public static function open(array $incident): int
    {
        $severity = self::validateEnumOrDefault($incident['severity'] ?? null, self::VALID_SEVERITIES, 'medium', 'severity');
        $status = self::validateEnumOrDefault($incident['status'] ?? null, self::VALID_STATUSES, 'detected', 'status');

        $detectedAt = !empty($incident['detectedAt']) ? (string) $incident['detectedAt'] : date('Y-m-d H:i:s');

        $reference = !empty($incident['reference'])
            ? trim(SecurityUtils::sanitizeString((string) $incident['reference']))
            : self::generateReference();

        $discoveredBy = self::trimmedOrNull($incident['discoveredBy'] ?? null, true);
        $affectedUserCount = self::intOrNull($incident['affectedUserCount'] ?? null);
        $dataCategories = self::encodeJsonArray($incident['dataCategories'] ?? null);
        $regimeCodes = self::encodeJsonArray($incident['regimeCodes'] ?? null);
        $summary = self::trimmedOrNull($incident['summary'] ?? null, false);
        $remediation = self::trimmedOrNull($incident['remediation'] ?? null, false);
        $assignedAdminID = self::intOrNull($incident['assignedAdminID'] ?? null);

        $incidentID = Database::insert(
            "INSERT INTO tblBreachIncidents
                (reference, severity, status, detectedAt, discoveredBy, affectedUserCount,
                 dataCategories, regimeCodes, summary, remediation, assignedAdminID)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $reference, $severity, $status, $detectedAt, $discoveredBy, $affectedUserCount,
                $dataCategories, $regimeCodes, $summary, $remediation, $assignedAdminID,
            ],
            'sssssissssi'
        );

        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                $assignedAdminID,
                'breach_incident_opened',
                'privacy',
                'warning',
                "Breach incident opened: {$reference} (severity: {$severity})",
                ['incidentID' => $incidentID, 'reference' => $reference, 'severity' => $severity]
            );
        }

        return $incidentID;
    }

    // ========================================================================
    // 🔍 ASSESS / UPDATE AN INCIDENT
    // ========================================================================

    /**
     * 🔍 Update An Incident's Assessable Fields
     *
     * Builds a dynamic UPDATE ... SET clause, but ONLY from the fixed set of
     * column names handled below — the caller supplies VALUES keyed by these
     * known names, never a column NAME itself.
     *
     * @param int   $incidentID
     * @param array $fields Any subset of: severity, status, discoveredBy,
     *                      affectedUserCount, dataCategories, regimeCodes,
     *                      summary, remediation, assignedAdminID
     * @return bool True if the incident existed and at least one field was updated
     *
     * @throws InvalidArgumentException If severity/status is supplied but not a recognised value
     */
    public static function assess(int $incidentID, array $fields): bool
    {
        $existing = Database::fetchOne("SELECT incidentID FROM tblBreachIncidents WHERE incidentID = ?", [$incidentID], 'i');
        if (!$existing) {
            return false;
        }

        $setClauses = [];
        $params = [];
        $types = '';

        if (array_key_exists('severity', $fields)) {
            $severity = (string) $fields['severity'];
            if (!in_array($severity, self::VALID_SEVERITIES, true)) {
                throw new InvalidArgumentException("Invalid breach severity: '{$severity}'. Must be one of: " . implode(', ', self::VALID_SEVERITIES));
            }
            $setClauses[] = '`severity` = ?';
            $params[] = $severity;
            $types .= 's';
        }

        if (array_key_exists('status', $fields)) {
            $status = (string) $fields['status'];
            if (!in_array($status, self::VALID_STATUSES, true)) {
                throw new InvalidArgumentException("Invalid breach status: '{$status}'. Must be one of: " . implode(', ', self::VALID_STATUSES));
            }
            $setClauses[] = '`status` = ?';
            $params[] = $status;
            $types .= 's';
        }

        if (array_key_exists('discoveredBy', $fields)) {
            $setClauses[] = '`discoveredBy` = ?';
            $params[] = self::trimmedOrNull($fields['discoveredBy'], true);
            $types .= 's';
        }

        if (array_key_exists('affectedUserCount', $fields)) {
            $setClauses[] = '`affectedUserCount` = ?';
            $params[] = self::intOrNull($fields['affectedUserCount']);
            $types .= 'i';
        }

        if (array_key_exists('dataCategories', $fields)) {
            $setClauses[] = '`dataCategories` = ?';
            $params[] = self::encodeJsonArray($fields['dataCategories']);
            $types .= 's';
        }

        if (array_key_exists('regimeCodes', $fields)) {
            $setClauses[] = '`regimeCodes` = ?';
            $params[] = self::encodeJsonArray($fields['regimeCodes']);
            $types .= 's';
        }

        if (array_key_exists('summary', $fields)) {
            $setClauses[] = '`summary` = ?';
            $params[] = self::trimmedOrNull($fields['summary'], false);
            $types .= 's';
        }

        if (array_key_exists('remediation', $fields)) {
            $setClauses[] = '`remediation` = ?';
            $params[] = self::trimmedOrNull($fields['remediation'], false);
            $types .= 's';
        }

        if (array_key_exists('assignedAdminID', $fields)) {
            $setClauses[] = '`assignedAdminID` = ?';
            $params[] = self::intOrNull($fields['assignedAdminID']);
            $types .= 'i';
        }

        if (empty($setClauses)) {
            return false;
        }

        $params[] = $incidentID;
        $types .= 'i';

        Database::query(
            "UPDATE tblBreachIncidents SET " . implode(', ', $setClauses) . " WHERE incidentID = ?",
            $params,
            $types
        );

        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                null,
                'breach_incident_assessed',
                'privacy',
                'info',
                "Breach incident #{$incidentID} updated: " . implode(', ', array_keys($fields)),
                ['incidentID' => $incidentID, 'fieldsUpdated' => array_keys($fields)]
            );
        }

        return true;
    }

    // ========================================================================
    // ⏰ COMPUTE NOTIFICATION DEADLINES — the crux of this class
    // ========================================================================

    /**
     * ⏰ Compute (Or Refresh) Every Notification Deadline For An Incident
     *
     * For each regimeCode in the incident's `regimeCodes` and each of the two
     * audiences (authority, data_subjects), upserts a `tblBreachNotifications`
     * row with `dueAt = detectedAt + RegimeResolver::breachWindowHours($regimeCode)`
     * hours. When that resolver returns null (no window configured for the
     * regime, or RegimeResolver itself is unavailable), the row is written
     * with `dueAt = NULL`, `status = 'pending'`, and an explanatory `note` —
     * this NEVER throws and NEVER guesses a number.
     *
     * Safe to call repeatedly (e.g. after editing `regimeCodes` or once a
     * regime's `breachNotifyHours` is later confirmed) — a row already marked
     * `status = 'sent'` is left completely untouched (never silently reset to
     * pending), everything else is refreshed.
     *
     * @param int $incidentID
     * @return array<int,array<string,mixed>> The resulting tblBreachNotifications rows
     *
     * @throws InvalidArgumentException If the incident does not exist
     */
    public static function computeNotificationDeadlines(int $incidentID): array
    {
        $incident = Database::fetchOne(
            "SELECT incidentID, detectedAt, regimeCodes FROM tblBreachIncidents WHERE incidentID = ?",
            [$incidentID],
            'i'
        );

        if (!$incident) {
            throw new InvalidArgumentException("Breach incident #{$incidentID} not found.");
        }

        $regimeCodes = self::decodeJsonArray($incident['regimeCodes'] ?? null);
        $detectedAt = (string) $incident['detectedAt'];

        $results = [];
        foreach ($regimeCodes as $regimeCode) {
            $regimeCode = trim((string) $regimeCode);
            if ($regimeCode === '') {
                continue;
            }

            // 🛡️ Defensive length guard — `tblBreachNotifications.regimeCode`
            // (like `tblComplianceRegimes.regimeCode`) is VARCHAR(16). Every
            // REAL regime code is always <= 16 chars (the regime admin UI
            // enforces this), but this field is free-text operator input on
            // the breach admin screen — a typo or garbage string longer than
            // that would otherwise reach SQL as a raw "data too long" error.
            // Skip it (same as an unknown regime — it simply cannot resolve
            // to any real tblComplianceRegimes row) rather than let the whole
            // computeNotificationDeadlines() call fail for every OTHER
            // regime code on the incident.
            if (strlen($regimeCode) > self::MAX_REGIME_CODE_LENGTH) {
                if (class_exists('ActivityLogger')) {
                    ActivityLogger::log(
                        null,
                        'breach_regime_code_skipped',
                        'privacy',
                        'warning',
                        "Breach incident #{$incidentID}: regimeCode '{$regimeCode}' exceeds "
                            . self::MAX_REGIME_CODE_LENGTH . " characters and cannot match any real "
                            . "regime — skipped.",
                        ['incidentID' => $incidentID, 'regimeCode' => $regimeCode]
                    );
                }
                continue;
            }

            // 🔒 THE crux call — the ONLY source of any breach-notification
            // window number. Never a PHP literal, always a best-effort read
            // that degrades to null (never throws) if the resolver is
            // unavailable or the regime has no configured window.
            $windowHours = null;
            if (class_exists('RegimeResolver')) {
                try {
                    $windowHours = RegimeResolver::breachWindowHours($regimeCode);
                } catch (Throwable $e) {
                    $windowHours = null;
                }
            }

            foreach (self::VALID_AUDIENCES as $audience) {
                $results[] = self::upsertNotification($incidentID, $regimeCode, $audience, $detectedAt, $windowHours);
            }
        }

        return $results;
    }

    /**
     * 🔧 Upsert A Single (incidentID, regimeCode, audience) Notification Row
     *
     * Manual SELECT-then-INSERT/UPDATE rather than `INSERT ... ON DUPLICATE
     * KEY UPDATE` — this table's UNIQUE KEY includes a nullable `regimeCode`
     * column, and MySQL does not treat NULLs as duplicates under a UNIQUE
     * KEY, so an ON-DUPLICATE upsert would misbehave for a hypothetical
     * NULL-regimeCode row. Every regimeCode reaching this method has already
     * been validated non-empty by the caller, but the manual approach keeps
     * the contract explicit and matches this table's own semantics.
     *
     * @param int         $incidentID
     * @param string      $regimeCode
     * @param string      $audience
     * @param string      $detectedAt 'Y-m-d H:i:s'
     * @param int|null    $windowHours
     * @return array<string,mixed> The resulting tblBreachNotifications row
     */
    private static function upsertNotification(
        int $incidentID,
        string $regimeCode,
        string $audience,
        string $detectedAt,
        ?int $windowHours
    ): array {
        $existing = Database::fetchOne(
            "SELECT * FROM tblBreachNotifications WHERE incidentID = ? AND regimeCode = ? AND audience = ?",
            [$incidentID, $regimeCode, $audience],
            'iss'
        );

        // 🔒 Never silently reset an already-SENT notification back to pending.
        if ($existing && $existing['status'] === 'sent') {
            return $existing;
        }

        if ($windowHours !== null && $windowHours > 0) {
            $dueAt = date('Y-m-d H:i:s', strtotime($detectedAt . " +{$windowHours} hours"));
            $note = null;
        } else {
            $dueAt = null;
            $note = "No breachNotifyHours is configured for regime '{$regimeCode}' yet — confirm the "
                . "notification window with legal/DPO (via the Compliance Regimes admin) before a "
                . "deadline can be computed.";
        }

        if ($existing) {
            Database::query(
                "UPDATE tblBreachNotifications SET dueAt = ?, status = 'pending', note = ? WHERE notifyID = ?",
                [$dueAt, $note, $existing['notifyID']],
                'ssi'
            );
            $notifyID = (int) $existing['notifyID'];
        } else {
            $notifyID = Database::insert(
                "INSERT INTO tblBreachNotifications (incidentID, regimeCode, audience, dueAt, status, note)
                 VALUES (?, ?, ?, ?, 'pending', ?)",
                [$incidentID, $regimeCode, $audience, $dueAt, $note],
                'issss'
            );
        }

        return Database::fetchOne("SELECT * FROM tblBreachNotifications WHERE notifyID = ?", [$notifyID], 'i');
    }

    // ========================================================================
    // ✅ MARK NOTIFIED
    // ========================================================================

    /**
     * ✅ Mark A Notification As Sent
     *
     * `$note` is operator-entered filing detail (e.g. "filed with the ICO via
     * their online portal, reference #..."). This method NEVER files
     * anything itself — it only records that an operator did.
     *
     * @param int         $notifyID
     * @param string|null $note
     * @return bool True if the notification row existed and was updated
     */
    public static function markNotified(int $notifyID, ?string $note = null): bool
    {
        $row = Database::fetchOne(
            "SELECT notifyID, incidentID FROM tblBreachNotifications WHERE notifyID = ?",
            [$notifyID],
            'i'
        );

        if (!$row) {
            return false;
        }

        Database::query(
            "UPDATE tblBreachNotifications SET status = 'sent', sentAt = NOW(), note = ? WHERE notifyID = ?",
            [self::trimmedOrNull($note, false), $notifyID],
            'si'
        );

        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                null,
                'breach_notification_sent',
                'privacy',
                'info',
                "Breach notification #{$notifyID} (incident #{$row['incidentID']}) marked sent",
                ['notifyID' => $notifyID, 'incidentID' => (int) $row['incidentID']]
            );
        }

        return true;
    }

    // ========================================================================
    // 📋 OVERDUE NOTIFICATIONS
    // ========================================================================

    /**
     * 📋 Get Every Overdue, Still-Pending Notification
     *
     * `status = 'pending' AND dueAt IS NOT NULL AND dueAt < NOW()` — a row
     * with `dueAt = NULL` (no window configured) is NEVER reported as
     * overdue; it has no computable deadline to be overdue against.
     *
     * @return array<int,array<string,mixed>> Bounded to MAX_OVERDUE_LIMIT, soonest-overdue first
     */
    public static function getOverdueNotifications(): array
    {
        return Database::fetchAll(
            "SELECT bn.*, bi.reference, bi.severity, bi.status AS incidentStatus
             FROM tblBreachNotifications bn
             JOIN tblBreachIncidents bi ON bi.incidentID = bn.incidentID
             WHERE bn.status = 'pending' AND bn.dueAt IS NOT NULL AND bn.dueAt < NOW()
             ORDER BY bn.dueAt ASC
             LIMIT " . self::MAX_OVERDUE_LIMIT,
            [],
            ''
        );
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS — all pure/DB-free except where noted
    // ========================================================================

    /**
     * 🔧 Auto-Generate A Human Reference — BRCH-{year}-{4-digit sequence}
     *
     * Best-effort sequential numbering within the current year, with a
     * random-suffix fallback if a collision persists across several
     * attempts (defensive; the UNIQUE KEY on `reference` is the real
     * guarantee against a duplicate ever being persisted).
     *
     * @return string
     */
    private static function generateReference(): string
    {
        $year = date('Y');

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $countRow = Database::fetchOne(
                "SELECT COUNT(*) AS cnt FROM tblBreachIncidents WHERE reference LIKE ?",
                ["BRCH-{$year}-%"],
                's'
            );
            $sequence = ((int) ($countRow['cnt'] ?? 0)) + 1 + $attempt;
            $candidate = sprintf('BRCH-%s-%04d', $year, $sequence);

            $exists = Database::fetchOne("SELECT incidentID FROM tblBreachIncidents WHERE reference = ?", [$candidate], 's');
            if (!$exists) {
                return $candidate;
            }
        }

        // 🛡️ Extremely unlikely fallback — guarantees a value even under
        // pathological concurrent-collision conditions.
        return sprintf('BRCH-%s-%04d-%s', $year, random_int(1, 9999), bin2hex(random_bytes(2)));
    }

    /**
     * 🔧 Validate An Enum Value Or Fall Back To A Default — pure, DB-free.
     *
     * @param mixed              $value
     * @param array<int,string>  $allowed
     * @param string             $default
     * @param string             $fieldName Used only in the exception message
     * @return string
     *
     * @throws InvalidArgumentException If $value is supplied (non-null, non-empty) but not in $allowed
     */
    private static function validateEnumOrDefault(mixed $value, array $allowed, string $default, string $fieldName): string
    {
        if ($value === null || $value === '') {
            return $default;
        }

        $value = (string) $value;
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException("Invalid {$fieldName}: '{$value}'. Must be one of: " . implode(', ', $allowed));
        }

        return $value;
    }

    /**
     * 🔧 Trim A Caller-Supplied String, Returning NULL For Blank — pure, DB-free.
     *
     * When `$escape` is true (short operational fields like `discoveredBy`),
     * mirrors DSARManager's `SecurityUtils::sanitizeString()` convention.
     * When false (longer operator/DPO-authored free text — `summary`/
     * `remediation`/notification `note`), stores the trimmed text VERBATIM —
     * mirroring regime-actions.php's `handleSaveDisclosure()` convention —
     * so legitimate legal copy (quotation marks, ampersands, section
     * symbols) survives intact; escaping happens ONLY at output time in the
     * admin UI (`htmlspecialchars()`), never at storage time, avoiding a
     * double-escape.
     *
     * @param mixed $value
     * @param bool  $escape
     * @return string|null
     */
    private static function trimmedOrNull(mixed $value, bool $escape): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = $escape ? SecurityUtils::sanitizeString((string) $value) : trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * 🔧 Coerce A Caller-Supplied Value To Int-Or-NULL — pure, DB-free.
     *
     * @param mixed $value
     * @return int|null
     */
    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * 🔧 Encode A Caller-Supplied Array (or JSON string) Into A JSON TEXT Value — pure, DB-free.
     *
     * @param mixed $value array<int,mixed>|string|null
     * @return string|null
     */
    private static function encodeJsonArray(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? json_encode(array_values($decoded), JSON_UNESCAPED_UNICODE) : null;
        }

        if (is_array($value)) {
            $filtered = array_values(array_filter($value, static fn($v): bool => $v !== null && $v !== ''));
            return !empty($filtered) ? json_encode($filtered, JSON_UNESCAPED_UNICODE) : null;
        }

        return null;
    }

    /**
     * 🔧 Decode A `tblBreachIncidents` JSON TEXT Column Into An Array — pure, DB-free.
     *
     * Fails safe to an empty array on NULL/blank/malformed JSON — never throws.
     *
     * @param mixed $value
     * @return array<int,mixed>
     */
    private static function decodeJsonArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
