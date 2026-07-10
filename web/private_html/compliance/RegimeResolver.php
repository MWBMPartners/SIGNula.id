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
 * 🌍 SIGNula - Regime Resolver (G-004 Layer 3 §4 — the data-driven core)
 * ============================================================================
 *
 * Purpose: The SINGLE integration point that turns "which data-protection
 *          regime governs this user/request?" into a database read, never a
 *          PHP `if ($country === 'DE')`-style branch. This class NEVER names
 *          a country or a regime code as a PHP literal (see the class-level
 *          grep-gate proof recorded in the G-004 Layer 3 build report) — it
 *          only knows the SHAPE of a regime (it has an SLA, a minimum age, a
 *          breach window, a set of rights), and reads the VALUES for that
 *          shape out of tblComplianceRegimes / tblRegimeRights /
 *          tblRegimeResolution (migration 042). Adding a 20th regime is a
 *          row insert; this file does not change.
 * PHP Version: 8.3+
 *
 * Design contract (see .dev-team/specs/G-004.md §4.1-§4.4):
 * - **Never fail open.** Every accessor below degrades to the MOST
 *   PROTECTIVE default when a regime is unresolved, unknown, inactive, or
 *   has a NULL value for the field being asked about — never to a
 *   permissive/zero value. `slaDaysFor()`/`minAgeFor()` can never return 0
 *   or a negative number; an unresolved SLA/age always clamps to the
 *   configured (generic, country-agnostic) default.
 * - **Draft/inactive regimes are invisible to live resolution.** Every
 *   lookup here filters `isActive = 1 AND isDraft = 0` — a regime an
 *   operator has not yet confirmed with counsel can NEVER be returned by
 *   resolve()/slaDaysFor()/etc., even if a resolution rule points at it.
 * - **The shipped-state contract (CRITICAL — keeps the L1/L2 baseline
 *   green).** Migration 042 seeds 19 regimes, every one `isActive = 0`.
 *   With nothing active, resolve() ALWAYS falls through to the synthetic
 *   default below: `regimeCode => null`, `dsarSlaDays => 30` (from the
 *   pre-existing `dsar.default_sla_days` setting, migration 040). This is
 *   BY DESIGN — it is what makes the Layer 3 retro-wire into
 *   DSARManager::submit()/ConsentManager::record() a behavioural no-op
 *   today: those two call sites already default to regimeCode=NULL /
 *   SLA=30, so wiring them through this resolver changes nothing until an
 *   operator actually activates a regime.
 * - **Fail-safe, not fail-loud.** Every DB read in this class is wrapped so
 *   a connection error / missing table degrades to the most-protective
 *   default rather than throwing — resolving a regime must never be able to
 *   crash a consent-capture or DSAR-submission request that would otherwise
 *   succeed.
 * - **Cheap on the hot path.** `ConsentManager::record()` calls
 *   `resolve()` on every consent decision (banner clicks, GPC auto-opt-outs)
 *   — see resolve()'s own docblock for the per-request static memo that
 *   keeps a repeated call for the same (userID, partnerID, countryHint)
 *   tuple from re-querying the database.
 * - **No geo-IP dependency.** `resolve()`'s `$countryHint` parameter is
 *   whatever the caller already knows (e.g. a user's declared residency
 *   preference) — this class does not look up a visitor's IP address or
 *   call any external service. Passing no hint simply means fewer
 *   resolution rules can match, which degrades safely to the default.
 *
 * Database Tables (see migration 042):
 * - tblComplianceRegimes   : one row per regime — the values PHP never hardcodes
 * - tblRegimeRights        : which DSAR rights (tblDataSubjectRequests.requestType
 *                            union) a regime grants
 * - tblRegimeResolution    : the rule table resolve() walks (country/region/
 *                            partner/default → regimeID), ORDER BY priority ASC
 *
 * @see _database/migrations/042_compliance_regimes.sql
 * @see web/private_html/compliance/DSARManager.php   — retro-wired call site (submit())
 * @see web/private_html/compliance/ConsentManager.php — retro-wired call site (record())
 * @see .dev-team/specs/G-004.md §4.1, §4.2, §4.4
 * @see https://globalprivacycontrol.org/ (Sec-GPC / honorsGPC)
 * @see https://gdpr-info.eu/art-12-gdpr/ (the kind of SLA value a regime row encodes)
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class RegimeResolver
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /**
     * ⚙️ tblSettings key for the DSAR fallback SLA (already seeded by
     * migration 040 — this class does not introduce it, only reads it).
     */
    private const SETTING_DEFAULT_SLA_DAYS = 'dsar.default_sla_days';

    /**
     * 🛡️ Hardcoded fallback-of-fallback if the setting itself is somehow
     * missing/blank. This is a GENERIC number tied to no named country —
     * allowed under the class's "no regime literal" contract because it
     * names no jurisdiction, exactly like `dsar.default_sla_days`'s own
     * seeded value (migration 040) it mirrors.
     */
    private const FALLBACK_SLA_DAYS = 30;

    /**
     * ⚙️ tblSettings key for the generic most-protective minimum age
     * (seeded by migration 042 — this migration's own new setting).
     */
    private const SETTING_DEFAULT_MIN_AGE = 'compliance.regime.default_min_age';

    /**
     * 🛡️ Hardcoded fallback-of-fallback minimum age. Generic, names no
     * jurisdiction — the strictest commonly-seen digital-consent threshold,
     * used only if the tblSettings row itself is missing.
     */
    private const FALLBACK_MIN_AGE = 16;

    /**
     * ⚙️ tblSettings key for the master GPC-honoring switch (already seeded
     * by migration 041 — read here only as the most-protective default's
     * source, never written by this class).
     */
    private const SETTING_GPC_HONOR = 'consent.gpc.honor';

    /**
     * 🧠 Per-request memo cache for resolve(), keyed by a composite of its
     * three parameters. ConsentManager::record() calls resolve() on every
     * consent decision (see that class's retro-wired call site) — without
     * this memo, a page that records several consent categories in a row
     * (e.g. the banner "accept all" flow) would re-run the same
     * tblRegimeResolution walk once per category. Cleared automatically at
     * the start of every PHP process (this is a plain static array, not a
     * persistent cache) — never stale across requests.
     *
     * @var array<string,array<string,mixed>>
     */
    private static array $resolveMemo = [];

    // ========================================================================
    // 🗺️ RESOLVE — THE SINGLE INTEGRATION POINT
    // ========================================================================

    /**
     * 🗺️ Resolve The Governing Regime
     *
     * Walks `tblRegimeResolution` ORDER BY priority ASC (lower = evaluated
     * first). For each rule that APPLIES to the given inputs (see
     * ruleApplies()), if the rule's target regime resolves to an ACTIVE,
     * non-draft row, that regime is returned immediately. A rule that
     * applies but whose target regime is inactive/draft/missing is NOT
     * used — resolution keeps walking lower-priority rules rather than
     * ever surfacing an unconfirmed regime (never fail open).
     *
     * If NO rule yields an active regime (this is the shipped-state
     * behaviour today — every seed regime is `isActive = 0`), returns a
     * SYNTHETIC most-protective default array: `regimeCode => null`,
     * `dsarSlaDays => 30` (from `dsar.default_sla_days`), the strictest
     * configured minimum age, and `isDefault => true`.
     *
     * @param int|null    $userID      Authenticated user, or null. Reserved
     *                                 for a future user-level match rule
     *                                 (e.g. an admin-set per-user override);
     *                                 not consulted by any matchType seeded
     *                                 today — see ruleApplies().
     * @param int|null    $partnerID   Partner/tenant ID — matched against
     *                                 matchType='partner' rules (matchValue
     *                                 compared as a string).
     * @param string|null $countryHint ISO country code (or any value an
     *                                 operator's `country` rules use) — the
     *                                 CALLER supplies this (e.g. from a
     *                                 user's declared residency preference);
     *                                 this method never performs a geo-IP
     *                                 lookup itself. Compared case-
     *                                 insensitively against matchType='country'
     *                                 rules.
     * @return array<string,mixed> The resolved regime row (normalizeRegimeRow()
     *                             shape) or the synthetic default (see above)
     *
     * @see https://gdpr-info.eu/art-12-gdpr/
     */
    public static function resolve(?int $userID, ?int $partnerID, ?string $countryHint): array
    {
        $memoKey = self::memoKey($userID, $partnerID, $countryHint);
        if (array_key_exists($memoKey, self::$resolveMemo)) {
            return self::$resolveMemo[$memoKey];
        }

        $resolved = self::resolveUncached($userID, $partnerID, $countryHint);
        self::$resolveMemo[$memoKey] = $resolved;

        return $resolved;
    }

    /**
     * 🗺️ Uncached Resolution Walk (the actual DB-touching logic behind resolve())
     *
     * Split out from resolve() purely so the memo wrapper stays trivial to
     * read; not intended to be called directly by other classes (kept
     * private).
     *
     * @param int|null    $userID
     * @param int|null    $partnerID
     * @param string|null $countryHint
     * @return array<string,mixed>
     */
    private static function resolveUncached(?int $userID, ?int $partnerID, ?string $countryHint): array
    {
        $normalizedCountry = $countryHint !== null && $countryHint !== ''
            ? strtoupper(trim($countryHint))
            : null;

        try {
            $rules = Database::fetchAll(
                "SELECT ruleID, matchType, matchValue, regimeID, priority
                 FROM tblRegimeResolution
                 ORDER BY priority ASC, ruleID ASC",
                [],
                ''
            );
        } catch (Throwable $e) {
            // 🛡️ Best-effort — a DB error must never propagate out of a
            // consent-capture or DSAR-submission call. Fall through to the
            // most-protective synthetic default exactly as if no rule
            // existed at all.
            $rules = [];
        }

        foreach ($rules as $rule) {
            if (!self::ruleApplies($rule, $userID, $partnerID, $normalizedCountry)) {
                continue;
            }

            if ($rule['regimeID'] === null) {
                // 🌫️ The seeded 'default' rule (or any rule an operator has
                // deliberately left un-targeted) — documents that a
                // fallback exists but carries no active regime of its own.
                continue;
            }

            $regime = self::fetchActiveRegimeByID((int) $rule['regimeID']);
            if ($regime !== null) {
                return self::normalizeRegimeRow($regime);
            }

            // 🚫 Matched rule, but its target regime is not ACTIVE + non-
            // draft yet (or no longer exists) — never fail open by using
            // it. Keep walking lower-priority rules instead of returning.
        }

        return self::syntheticDefault();
    }

    /**
     * ✅ Does This Resolution Rule Apply? — pure, no DB access.
     *
     * @param array<string,mixed> $rule              One row from tblRegimeResolution
     * @param int|null            $userID            (reserved — see resolve()'s docblock)
     * @param int|null            $partnerID
     * @param string|null         $normalizedCountry Already upper-cased/trimmed, or null
     * @return bool
     */
    private static function ruleApplies(
        array $rule,
        ?int $userID,
        ?int $partnerID,
        ?string $normalizedCountry
    ): bool {
        return match ((string) $rule['matchType']) {
            'partner' => $partnerID !== null
                && $rule['matchValue'] !== null
                && (string) $rule['matchValue'] === (string) $partnerID,
            'country' => $normalizedCountry !== null
                && $rule['matchValue'] !== null
                && strtoupper((string) $rule['matchValue']) === $normalizedCountry,
            // 🌐 'region' matching needs a region hint this cycle's resolve()
            // signature does not carry (G-004 spec §4.2 lists a country hint
            // only) — no seeded rule uses matchType='region' today (migration
            // 042 seeds a single 'default' rule), so this simply never
            // matches rather than guessing at a country→region mapping.
            'region'  => false,
            'default' => true,
            default   => false,
        };
    }

    // ========================================================================
    // 📐 PER-CODE ACCESSORS — the day-to-day API most callers use
    // ========================================================================

    /**
     * 📐 DSAR Fulfilment SLA (In Days) For A Regime Code
     *
     * NEVER returns 0 or a negative number — an unresolved, unknown, or
     * inactive/draft regime, or a resolved regime whose `dsarSlaDays` is
     * itself NULL, all clamp to the most-protective default
     * (`dsar.default_sla_days`, migration 040 — 30 by default).
     *
     * Accepts `$regimeCode = null` (widened from the spec's pseudocode
     * `string $regimeCode` signature) because the documented shipped-state
     * caller contract is exactly that: DSARManager::submit() passes
     * `$resolved['regimeCode'] ?? null`, which — with every seed regime
     * inactive — IS null today. A non-nullable `string` parameter would
     * TypeError on that call regardless of strict_types.
     *
     * @param string|null $regimeCode A tblComplianceRegimes.regimeCode, or null
     * @return int Always >= 1
     */
    public static function slaDaysFor(?string $regimeCode): int
    {
        if ($regimeCode === null || $regimeCode === '') {
            return self::resolveDefaultSlaDays();
        }

        $regime = self::fetchActiveRegimeByCode($regimeCode);
        if ($regime === null || $regime['dsarSlaDays'] === null) {
            return self::resolveDefaultSlaDays();
        }

        $days = (int) $regime['dsarSlaDays'];

        return $days > 0 ? $days : self::resolveDefaultSlaDays();
    }

    /**
     * 📐 Digital-Consent Minimum Age For A Regime Code
     *
     * Same never-fail-open contract as slaDaysFor(): an unresolved/unknown/
     * inactive regime, or a NULL `minAge` on a resolved one, clamps to the
     * strictest configured default (`compliance.regime.default_min_age`,
     * migration 042 — 16 by default).
     *
     * @param string|null $regimeCode A tblComplianceRegimes.regimeCode, or null
     * @return int Always >= 1
     */
    public static function minAgeFor(?string $regimeCode): int
    {
        if ($regimeCode === null || $regimeCode === '') {
            return self::resolveDefaultMinAge();
        }

        $regime = self::fetchActiveRegimeByCode($regimeCode);
        if ($regime === null || $regime['minAge'] === null) {
            return self::resolveDefaultMinAge();
        }

        $age = (int) $regime['minAge'];

        return $age > 0 ? $age : self::resolveDefaultMinAge();
    }

    /**
     * 📐 Breach-Notification Window (In Hours) For A Regime Code
     *
     * Unlike slaDaysFor()/minAgeFor(), there is no universal "most
     * protective" numeric fallback for a breach window — computing and
     * tracking breach-notification deadlines is G-004 Layer 4 (not built by
     * this cycle) scope, so an unresolved/unknown regime, or one whose
     * `breachNotifyHours` is NULL, simply returns null ("no window is
     * currently known/mandated by this tool"), matching the spec's own
     * `?int` accessor contract. A future BreachManager treats a null window
     * conservatively itself.
     *
     * @param string|null $regimeCode A tblComplianceRegimes.regimeCode, or null
     * @return int|null
     */
    public static function breachWindowHours(?string $regimeCode): ?int
    {
        if ($regimeCode === null || $regimeCode === '') {
            return null;
        }

        $regime = self::fetchActiveRegimeByCode($regimeCode);
        if ($regime === null || $regime['breachNotifyHours'] === null) {
            return null;
        }

        $hours = (int) $regime['breachNotifyHours'];

        return $hours > 0 ? $hours : null;
    }

    /**
     * 📐 DSAR Rights Granted By A Regime Code
     *
     * Returns the `tblRegimeRights.right` list for the regime (empty array
     * if the regime is unresolved/unknown/inactive/draft, or simply has no
     * rights rows yet — every migration-042 seed regime ships with zero
     * rights, see the migration header).
     *
     * @param string|null $regimeCode A tblComplianceRegimes.regimeCode, or null
     * @return array<int,string> Values drawn from DSARManager::VALID_REQUEST_TYPES
     */
    public static function rightsFor(?string $regimeCode): array
    {
        if ($regimeCode === null || $regimeCode === '') {
            return [];
        }

        $regime = self::fetchActiveRegimeByCode($regimeCode);
        if ($regime === null) {
            return [];
        }

        try {
            $rows = Database::fetchAll(
                "SELECT `right` FROM tblRegimeRights WHERE regimeID = ? ORDER BY `right` ASC",
                [(int) $regime['regimeID']],
                'i'
            );
        } catch (Throwable $e) {
            return [];
        }

        return array_map(static fn(array $row): string => (string) $row['right'], $rows);
    }

    /**
     * 📐 Does This Regime Code Honor Global Privacy Control?
     *
     * An unresolved/unknown/inactive regime falls back to the master
     * `consent.gpc.honor` setting (migration 041) rather than a hardcoded
     * true/false — this IS the most-protective posture available without
     * inventing a per-regime legal judgement (honoring an opt-out signal
     * can never itself be the less-protective choice).
     *
     * @param string|null $regimeCode A tblComplianceRegimes.regimeCode, or null
     * @return bool
     */
    public static function honorsGPC(?string $regimeCode): bool
    {
        if ($regimeCode === null || $regimeCode === '') {
            return self::resolveDefaultHonorsGPC();
        }

        $regime = self::fetchActiveRegimeByCode($regimeCode);
        if ($regime === null) {
            return self::resolveDefaultHonorsGPC();
        }

        return (bool) $regime['honorsGPC'];
    }

    // ========================================================================
    // 🔧 INTERNAL HELPERS — DB reads (all fail-safe: never throw outward)
    // ========================================================================

    /**
     * 🔧 Fetch An ACTIVE, Non-Draft Regime Row By ID
     *
     * Intentionally active-only (no "ignore isActive" variant) — this
     * class serves LIVE resolution only. A future admin-CRUD layer that
     * needs to see draft/inactive regimes queries tblComplianceRegimes
     * directly; it is not this resolver's job to expose unconfirmed rows.
     *
     * @param int $regimeID
     * @return array<string,mixed>|null
     */
    private static function fetchActiveRegimeByID(int $regimeID): ?array
    {
        try {
            return Database::fetchOne(
                "SELECT * FROM tblComplianceRegimes WHERE regimeID = ? AND isActive = 1 AND isDraft = 0",
                [$regimeID],
                'i'
            ) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 🔧 Fetch An ACTIVE, Non-Draft Regime Row By Code
     *
     * @param string $regimeCode
     * @return array<string,mixed>|null
     */
    private static function fetchActiveRegimeByCode(string $regimeCode): ?array
    {
        if ($regimeCode === '') {
            return null;
        }

        try {
            return Database::fetchOne(
                "SELECT * FROM tblComplianceRegimes WHERE regimeCode = ? AND isActive = 1 AND isDraft = 0",
                [$regimeCode],
                's'
            ) ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 🔧 Normalize A Raw tblComplianceRegimes Row Into resolve()'s Return Shape
     *
     * Casts every column to its PHP-native type (int/bool/string/null) so
     * callers never have to guess whether a value arrived as a MySQL string.
     *
     * @param array<string,mixed> $row Raw row from fetchActiveRegimeByID()/ByCode()
     * @return array<string,mixed>
     */
    private static function normalizeRegimeRow(array $row): array
    {
        return [
            'regimeID'              => (int) $row['regimeID'],
            'regimeCode'            => (string) $row['regimeCode'],
            'displayName'           => (string) $row['displayName'],
            'jurisdiction'          => $row['jurisdiction'] !== null ? (string) $row['jurisdiction'] : null,
            'dsarSlaDays'           => $row['dsarSlaDays'] !== null ? (int) $row['dsarSlaDays'] : null,
            'dsarExtensionDays'     => $row['dsarExtensionDays'] !== null ? (int) $row['dsarExtensionDays'] : null,
            'breachNotifyHours'     => $row['breachNotifyHours'] !== null ? (int) $row['breachNotifyHours'] : null,
            'breachNotifyAuthority' => $row['breachNotifyAuthority'] !== null ? (string) $row['breachNotifyAuthority'] : null,
            'minAge'                => $row['minAge'] !== null ? (int) $row['minAge'] : null,
            'honorsGPC'             => (bool) $row['honorsGPC'],
            'requiresDoNotSell'     => (bool) $row['requiresDoNotSell'],
            'lawfulBasisRequired'   => (bool) $row['lawfulBasisRequired'],
            'isActive'              => (bool) $row['isActive'],
            'isDraft'               => (bool) $row['isDraft'],
            'isDefault'             => false,
        ];
    }

    /**
     * 🛡️ The Synthetic Most-Protective Default (never-fail-open contract)
     *
     * Returned by resolve() whenever no active regime resolves — which, in
     * the shipped state (every migration-042 seed regime is `isActive = 0`),
     * is EVERY call. This is what keeps the Layer 3 retro-wire a no-op
     * today: `regimeCode => null`, `dsarSlaDays => 30`.
     *
     * @return array<string,mixed>
     */
    private static function syntheticDefault(): array
    {
        return [
            'regimeID'              => null,
            'regimeCode'            => null,
            'displayName'           => null,
            'jurisdiction'          => null,
            'dsarSlaDays'           => self::resolveDefaultSlaDays(),
            'dsarExtensionDays'     => null,
            'breachNotifyHours'     => null,
            'breachNotifyAuthority' => null,
            'minAge'                => self::resolveDefaultMinAge(),
            'honorsGPC'             => self::resolveDefaultHonorsGPC(),
            'requiresDoNotSell'     => false,
            'lawfulBasisRequired'   => false,
            'isActive'              => false,
            'isDraft'               => false,
            'isDefault'             => true,
        ];
    }

    /**
     * 🔧 Resolve The Default DSAR SLA (Days) — pure, DB-free (settings are
     * loaded into $GLOBALS by loadSettings() long before this runs).
     *
     * @return int Always >= 1
     */
    private static function resolveDefaultSlaDays(): int
    {
        $days = (int) getSetting(self::SETTING_DEFAULT_SLA_DAYS, self::FALLBACK_SLA_DAYS);

        return $days > 0 ? $days : self::FALLBACK_SLA_DAYS;
    }

    /**
     * 🔧 Resolve The Default Minimum Age — pure, DB-free.
     *
     * @return int Always >= 1
     */
    private static function resolveDefaultMinAge(): int
    {
        $age = (int) getSetting(self::SETTING_DEFAULT_MIN_AGE, self::FALLBACK_MIN_AGE);

        return $age > 0 ? $age : self::FALLBACK_MIN_AGE;
    }

    /**
     * 🔧 Resolve The Default GPC-Honoring Posture — pure, DB-free.
     *
     * Identical truthy-string idiom to ConsentManager::isTruthySetting() /
     * DSARManager::isTruthySetting() — duplicated rather than shared,
     * matching this codebase's existing per-class convention for small
     * settings helpers.
     *
     * @return bool
     */
    private static function resolveDefaultHonorsGPC(): bool
    {
        return self::isTruthySetting(getSetting(self::SETTING_GPC_HONOR, true));
    }

    /**
     * 🔧 Interpret A tblSettings Value As A Boolean — pure, DB-free.
     *
     * @param mixed $value Raw setting value (already type-decoded by
     *                      loadSettings() in production; the test bootstrap's
     *                      getSetting() stub returns raw JSON-fixture values,
     *                      so this still handles a plain string defensively)
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
     * 🔧 Build resolve()'s Per-Request Memo Key — pure, no DB access.
     *
     * @param int|null    $userID
     * @param int|null    $partnerID
     * @param string|null $countryHint
     * @return string
     */
    private static function memoKey(?int $userID, ?int $partnerID, ?string $countryHint): string
    {
        return ($userID ?? 'null') . '|' . ($partnerID ?? 'null') . '|' . ($countryHint ?? 'null');
    }
}
