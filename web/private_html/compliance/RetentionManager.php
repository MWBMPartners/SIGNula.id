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
 * 🗑️ SIGNula - Retention Manager (G-004 Layer 4a §5.3 — retention + auto-purge)
 * ============================================================================
 *
 * Purpose: Walk the operator-configured `tblRetentionPolicies` rows (migration
 *          043) and, for each ACTIVE one, either REPORT how many rows are due
 *          for anonymisation/deletion (the shipped default, forever, until an
 *          operator explicitly opts in) or actually anonymise/delete them in
 *          small, bounded batches.
 * PHP Version: 8.3+
 *
 * ----------------------------------------------------------------------------
 * 🔒 THE ALLOWLIST — why a DB-stored policy can never inject SQL
 * ----------------------------------------------------------------------------
 * `tblRetentionPolicies` rows are DATA, and this class treats them exactly
 * like any other untrusted input: `targetTable` / `dateColumn` /
 * `anonymiseColumns` from a policy row are NEVER concatenated into SQL
 * directly. Every one of them is first checked for EXACT string equality
 * against {@see self::ALLOWED_TARGETS} — a hardcoded PHP const, not
 * database-editable — via {@see self::isPolicyAllowlisted()}. Only after
 * that check passes does {@see self::runSinglePolicy()} build a query, and
 * even then the identifiers it interpolates are the allowlist's OWN literal
 * strings (verified equal to the policy's), never the policy row's raw text
 * used blind. A policy row that names an unlisted table, an unlisted date
 * column, or an unlisted anonymise column — including anything
 * injection-shaped, e.g. `tblUsers; DROP TABLE tblUsers;--` — simply fails
 * the exact-match check and the WHOLE policy is skipped (logged, never
 * executed). There is no partial-execution path: if even ONE requested
 * anonymiseColumn isn't allowlisted, nothing in that policy runs.
 *
 * ----------------------------------------------------------------------------
 * 🛡️ THE SAFETY DEFAULT — dry-run/disabled changes NOTHING
 * ----------------------------------------------------------------------------
 * Three independent gates must ALL be satisfied before a single row is ever
 * mutated:
 *   1. `tblRetentionPolicies.isActive = 1` for that specific policy row
 *      (every migration-043 seed ships `isActive = 0`).
 *   2. `retention.purge.enabled` setting = true (ships `'0'`/false).
 *   3. `retention.purge.dry_run` setting = false (ships `'1'`/true).
 * {@see self::resolvePurgeMode()} is the single source of truth for this:
 * `!enabled` always wins as `'disabled'`; `enabled && dryRun` is `'dry_run'`;
 * only `enabled && !dryRun` is `'live'`. In every mode except `'live'`,
 * {@see self::runSinglePolicy()} computes and returns the due-row COUNT but
 * issues no UPDATE/DELETE and never stamps `lastRunAt` — the row is
 * unconditionally unchanged.
 *
 * ----------------------------------------------------------------------------
 * 🧪 Pure, DB-free helpers — deliberately exposed as public statics so the
 * Unit suite can exercise the allowlist/mode logic without a live database
 * (mirrors RegimeResolver's `ruleApplies()`/DSARManager's `canTransition()`
 * "pure predicate first" pattern).
 * ----------------------------------------------------------------------------
 *
 * Database Tables:
 * - tblRetentionPolicies : the policy definitions this class reads (see migration 043)
 *
 * @see _database/migrations/043_retention_policies.sql
 * @see web/public_html/cron/compliance.php — the cron endpoint that calls runDuePolicies()
 * @see web/private_html/compliance/DSARManager.php — sibling class / house style template
 * @see web/private_html/compliance/RegimeResolver.php — sibling "data-driven, PHP-allowlisted" pattern
 * @see .dev-team/specs/G-004.md §5.3
 * @see https://gdpr-info.eu/art-5-gdpr/ (storage limitation principle)
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class RetentionManager
{
    // ========================================================================
    // 🔒 THE ALLOWLIST — the ONLY source of table/column identifiers ever
    // interpolated into SQL by this class. Adding a new purgeable table means
    // editing THIS const (a code review), never a database row.
    // ========================================================================

    /**
     * 🔐 Hardcoded map of every table/column combination RetentionManager is
     * PERMITTED to touch. A `tblRetentionPolicies` row whose targetTable /
     * dateColumn / anonymiseColumns are not an EXACT match against this
     * const is rejected wholesale by {@see self::isPolicyAllowlisted()}.
     *
     * Structure per table:
     *   - 'dateColumn'       : array<int,string>   — allowed retention-window columns
     *   - 'anonymiseColumns' : array<string,string> — allowed columns => strategy
     *                          ('null' sets the column to NULL; 'redact' sets it
     *                          to the literal string 'REDACTED' — used for columns
     *                          that are NOT NULL and therefore cannot take NULL)
     *
     * @var array<string,array{dateColumn:array<int,string>,anonymiseColumns:array<string,string>}>
     */
    private const ALLOWED_TARGETS = [
        'tblActivityLog' => [
            'dateColumn'       => ['createdAt'],
            'anonymiseColumns' => [
                // ipAddress/userAgent are nullable (see tblActivityLog schema) — NULL is the
                // minimisation strategy, mirroring AccountManager::permanentlyDeleteAccount()'s
                // own anonymisation UPDATE for this exact table (which uses 'REDACTED' instead —
                // either is a legitimate house choice; NULL is preferred here for a policy whose
                // whole point is "this data no longer needs to exist at all").
                'ipAddress' => 'null',
                'userAgent' => 'null',
                // userID is nullable too (COMMENT 'Reference to tblUsers (NULL for system events)')
                // — offered as an allowlisted option for an operator who wants to fully sever the
                // account link, at the cost of losing per-user audit continuity past the window.
                'userID'    => 'null',
            ],
        ],
    ];

    /** @var int Hard floor on retention.purge.batch_size, regardless of the stored setting. */
    private const MIN_BATCH_SIZE = 1;

    /** @var int Hard ceiling on retention.purge.batch_size, regardless of the stored setting. */
    private const MAX_BATCH_SIZE = 5000;

    // ========================================================================
    // 🧪 PURE, DB-FREE HELPERS
    // ========================================================================

    /**
     * ✅ Is This Policy Allowlisted? (pure predicate, no DB access)
     *
     * Validates a policy's targetTable/dateColumn/action/anonymiseColumns
     * against {@see self::ALLOWED_TARGETS} via EXACT string equality only —
     * never a substring/prefix/fuzzy match, which would be exploitable.
     *
     * @param array{targetTable?:string,dateColumn?:string,action?:string,anonymiseColumns?:array<int,string>} $policy
     *        A normalised policy shape — `anonymiseColumns` (if present) MUST
     *        already be a decoded array of column-name strings, not a raw
     *        JSON string (see {@see self::decodeAnonymiseColumns()} for the
     *        DB-row -> array step this method deliberately does not do
     *        itself, keeping this predicate pure/DB-free).
     * @return bool True only if EVERY identifier the policy names is an exact
     *              allowlist member; false (skip) otherwise.
     */
    public static function isPolicyAllowlisted(array $policy): bool
    {
        $table = (string) ($policy['targetTable'] ?? '');
        $dateColumn = (string) ($policy['dateColumn'] ?? '');
        $action = (string) ($policy['action'] ?? '');

        // 🔒 Exact key lookup — an injection-shaped string like
        // "tblActivityLog; DROP TABLE tblUsers;--" simply does not equal the
        // literal key "tblActivityLog" and fails here, before any SQL is built.
        if (!array_key_exists($table, self::ALLOWED_TARGETS)) {
            return false;
        }

        $allowedEntry = self::ALLOWED_TARGETS[$table];

        if (!in_array($dateColumn, $allowedEntry['dateColumn'], true)) {
            return false;
        }

        if (!in_array($action, ['anonymise', 'delete'], true)) {
            return false;
        }

        // 🧹 'delete' needs no column allowlist check — it never names columns.
        if ($action !== 'anonymise') {
            return true;
        }

        $requestedColumns = $policy['anonymiseColumns'] ?? [];
        if (!is_array($requestedColumns) || empty($requestedColumns)) {
            // 🛡️ An anonymise policy that names ZERO columns has nothing safe
            // to do — treat as not-allowlisted (skip) rather than silently
            // running a no-op UPDATE.
            return false;
        }

        $allowedColumns = array_keys($allowedEntry['anonymiseColumns']);
        foreach ($requestedColumns as $column) {
            // 🔒 Same exact-match discipline as the table/dateColumn checks
            // above — EVERY requested column must be allowlisted, or the
            // WHOLE policy is rejected (no partial execution).
            if (!in_array((string) $column, $allowedColumns, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 🚦 Resolve The Purge Mode (pure predicate, no DB access)
     *
     * Single source of truth for the "change nothing unless BOTH switches
     * are flipped" safety contract described in this class's docblock.
     *
     * @param bool $enabled Truthy value of the `retention.purge.enabled` setting
     * @param bool $dryRun  Truthy value of the `retention.purge.dry_run` setting
     * @return string One of 'disabled' | 'dry_run' | 'live'
     */
    public static function resolvePurgeMode(bool $enabled, bool $dryRun): string
    {
        if (!$enabled) {
            // 🛡️ Disabled always wins, regardless of the dry_run value —
            // there is no ambiguous state where a caller could read a
            // deceptive 'live' mode out of a mistakenly-flipped dry_run flag
            // while the master switch is still off.
            return 'disabled';
        }

        return $dryRun ? 'dry_run' : 'live';
    }

    // ========================================================================
    // ⏰ RUN DUE POLICIES
    // ========================================================================

    /**
     * ⏰ Run Every Active Retention Policy
     *
     * SELECTs `isActive = 1` policies and processes each in its own
     * try/catch — one bad/misconfigured policy can never abort the rest.
     * See the class docblock for the full allowlist + dry-run/disabled
     * safety contract.
     *
     * @return array<int,array<string,mixed>> One summary row per policy processed:
     *   {policyKey, wouldPurge, executed:0, mode:'dry_run'|'disabled'} when not live,
     *   {policyKey, purged, executed:1, mode:'live'} when a live run actually ran,
     *   {policyKey, skipped:true, reason, executed:0} when rejected by the allowlist, or
     *   {policyKey, error:true, message, executed:0} when the policy threw.
     */
    public static function runDuePolicies(): array
    {
        $results = [];

        try {
            $policies = Database::fetchAll(
                "SELECT * FROM tblRetentionPolicies WHERE isActive = 1",
                [],
                ''
            );
        } catch (\Throwable $e) {
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError(
                    'Exception',
                    'RetentionManager: failed to load active policies: ' . $e->getMessage(),
                    $e->getFile(),
                    $e->getLine()
                );
            }
            return $results;
        }

        foreach ($policies as $policyRow) {
            $policyKey = (string) ($policyRow['policyKey'] ?? 'unknown');

            try {
                $results[] = self::runSinglePolicy($policyRow);
            } catch (\Throwable $e) {
                // 🛡️ One bad policy must never abort the rest of the batch.
                if (class_exists('ErrorLogger')) {
                    ErrorLogger::logError(
                        'Exception',
                        "RetentionManager: policy '{$policyKey}' failed: " . $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    );
                }
                if (class_exists('ActivityLogger')) {
                    ActivityLogger::log(
                        null,
                        'retention_policy_failed',
                        'privacy',
                        'error',
                        "Retention policy '{$policyKey}' failed: " . $e->getMessage(),
                        ['policyKey' => $policyKey]
                    );
                }
                $results[] = [
                    'policyKey' => $policyKey,
                    'error'     => true,
                    'message'   => $e->getMessage(),
                    'executed'  => 0,
                ];
            }
        }

        return $results;
    }

    /**
     * ⚙️ Process A Single Policy Row
     *
     * @param array<string,mixed> $policyRow Raw tblRetentionPolicies row
     * @return array<string,mixed> The per-policy result shape (see runDuePolicies() docblock)
     */
    private static function runSinglePolicy(array $policyRow): array
    {
        $policyID = (int) $policyRow['policyID'];
        $policyKey = (string) $policyRow['policyKey'];
        $table = (string) $policyRow['targetTable'];
        $dateColumn = (string) $policyRow['dateColumn'];
        $action = (string) $policyRow['action'];
        $retentionDays = (int) $policyRow['retentionDays'];
        $requestedColumns = self::decodeAnonymiseColumns($policyRow['anonymiseColumns'] ?? null);

        // 🔒 THE gate — validated BEFORE any SQL is built against $table/$dateColumn.
        $normalisedPolicy = [
            'targetTable'      => $table,
            'dateColumn'       => $dateColumn,
            'action'           => $action,
            'anonymiseColumns' => $requestedColumns,
        ];

        if (!self::isPolicyAllowlisted($normalisedPolicy)) {
            if (class_exists('ActivityLogger')) {
                ActivityLogger::log(
                    null,
                    'retention_policy_skipped',
                    'privacy',
                    'warning',
                    "Retention policy '{$policyKey}' skipped: targetTable/dateColumn/action/anonymiseColumns "
                        . "are not an exact match against RetentionManager::ALLOWED_TARGETS.",
                    ['policyKey' => $policyKey, 'targetTable' => $table, 'dateColumn' => $dateColumn, 'action' => $action]
                );
            }
            return ['policyKey' => $policyKey, 'skipped' => true, 'reason' => 'not_allowlisted', 'executed' => 0];
        }

        // 🔢 Due-row count. $table/$dateColumn here are the SAME literal
        // strings just proven above to be EXACT members of ALLOWED_TARGETS —
        // never blind policy-row text. $retentionDays is a bound parameter.
        $dueRow = Database::fetchOne(
            "SELECT COUNT(*) AS cnt FROM `{$table}` WHERE `{$dateColumn}` < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$retentionDays],
            'i'
        );
        $dueCount = (int) ($dueRow['cnt'] ?? 0);

        $purgeEnabled = self::isTruthySetting(getSetting('retention.purge.enabled', '0'));
        $dryRun = self::isTruthySetting(getSetting('retention.purge.dry_run', '1'));
        $mode = self::resolvePurgeMode($purgeEnabled, $dryRun);

        if ($mode !== 'live') {
            // 🛡️ THE crux safety guarantee — change NOTHING. No UPDATE/DELETE
            // is built, lastRunAt is not touched, only the count is reported.
            return ['policyKey' => $policyKey, 'wouldPurge' => $dueCount, 'executed' => 0, 'mode' => $mode];
        }

        $batch = self::clampBatchSize((int) getSetting('retention.purge.batch_size', 500));

        if ($action === 'anonymise') {
            $executionResult = self::executeAnonymise($table, $dateColumn, $requestedColumns, $retentionDays, $batch);
        } else {
            // action === 'delete' (the only other value isPolicyAllowlisted() permits)
            Database::query(
                "DELETE FROM `{$table}` WHERE `{$dateColumn}` < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT ?",
                [$retentionDays, $batch],
                'ii'
            );
            $executionResult = Database::getAffectedRows();
        }

        // 🕒 Only a LIVE execution stamps lastRunAt.
        Database::query(
            "UPDATE tblRetentionPolicies SET lastRunAt = NOW() WHERE policyID = ?",
            [$policyID],
            'i'
        );

        if (class_exists('ActivityLogger')) {
            ActivityLogger::log(
                null,
                'retention_policy_executed',
                'privacy',
                'info',
                "Retention policy '{$policyKey}' executed ({$action}): {$executionResult} row(s) affected.",
                ['policyKey' => $policyKey, 'action' => $action, 'purged' => $executionResult, 'batch' => $batch]
            );
        }

        return ['policyKey' => $policyKey, 'purged' => $executionResult, 'executed' => 1, 'mode' => $mode];
    }

    /**
     * 🧽 Execute An Anonymise Action
     *
     * Builds the UPDATE's SET clause by iterating the ALLOWLIST's OWN column
     * list (not the policy's raw requested list) and keeping only the
     * intersection with what the policy asked for — column NAMES in the
     * final SQL always originate from {@see self::ALLOWED_TARGETS}, never
     * from the policy row's text.
     *
     * @param string        $table            Already-allowlisted table name
     * @param string        $dateColumn       Already-allowlisted date column
     * @param array<int,string> $requestedColumns Policy's requested column list (already allowlist-validated)
     * @param int           $retentionDays    Bound as a parameter
     * @param int           $batch            Bound as a parameter (LIMIT)
     * @return int Rows affected
     */
    private static function executeAnonymise(
        string $table,
        string $dateColumn,
        array $requestedColumns,
        int $retentionDays,
        int $batch
    ): int {
        $allowedColumnsForTable = self::ALLOWED_TARGETS[$table]['anonymiseColumns'];

        $setParts = [];
        foreach ($allowedColumnsForTable as $allowedColumn => $strategy) {
            if (!in_array($allowedColumn, $requestedColumns, true)) {
                continue; // policy didn't request this allowlisted column — leave it alone
            }
            $setParts[] = $strategy === 'redact'
                ? "`{$allowedColumn}` = 'REDACTED'"
                : "`{$allowedColumn}` = NULL";
        }

        if (empty($setParts)) {
            // 🛡️ Should be unreachable (isPolicyAllowlisted() already required
            // a non-empty, fully-allowlisted column set) — defensive no-op.
            return 0;
        }

        $setSql = implode(', ', $setParts);
        Database::query(
            "UPDATE `{$table}` SET {$setSql} WHERE `{$dateColumn}` < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT ?",
            [$retentionDays, $batch],
            'ii'
        );

        return Database::getAffectedRows();
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS
    // ========================================================================

    /**
     * 🔧 Decode `tblRetentionPolicies.anonymiseColumns` (JSON TEXT) Into An Array
     *
     * Fails safe to an empty array on NULL/blank/malformed JSON — never throws.
     *
     * @param string|null $json Raw column value
     * @return array<int,string>
     */
    private static function decodeAnonymiseColumns(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * 🔧 Clamp The Configured Batch Size Into [MIN_BATCH_SIZE, MAX_BATCH_SIZE]
     *
     * @param int $requested Raw value read from the retention.purge.batch_size setting
     * @return int
     */
    private static function clampBatchSize(int $requested): int
    {
        return max(self::MIN_BATCH_SIZE, min(self::MAX_BATCH_SIZE, $requested));
    }

    /**
     * 🔧 Interpret A tblSettings String Value As A Boolean.
     *
     * Identical idiom to ConsentManager::isTruthySetting()/DSARManager::isTruthySetting()
     * — duplicated rather than shared, matching this codebase's existing
     * per-class convention for small settings helpers.
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
