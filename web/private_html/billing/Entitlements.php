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
 * @version 2.9.0-beta
 */

/**
 * ============================================================================
 * 🔓 SIGNula - Flexible Pricing Catalog: Entitlement Resolver (FG-011)
 * ============================================================================
 *
 * Purpose: A single, central, FAIL-OPEN resolver that answers "does account
 *          X currently get feature/limit Y?" by layering the flexible
 *          pricing catalog added in migration 046_flexible_pricing_catalog.sql
 *          (tblFeatureCatalog / tblPlanFeatures / tblCatalogPrices /
 *          tblAddOns / tblAccountAddOns / tblEntitlementOverrides) on top of
 *          the account's active subscription — WITHOUT touching the money
 *          side of billing at all (BillingMode/BillingScheduler/PaymentManager
 *          are untouched).
 * PHP Version: 8.3+ (developed/tested on 8.4)
 *
 * ── Why this class is fail-OPEN, not fail-closed (pricing_schema_design.md §8) ──
 * BillingMode (web/private_html/payments/BillingMode.php) is deliberately
 * fail-CLOSED: money must never move by accident, so an ambiguous state is
 * treated as "refuse". Entitlements is the mirror image on purpose: shipping
 * this class, this migration, and every controller that later calls
 * Entitlements::require() must NEVER take away access a user already had.
 * An ambiguous, missing, or errored state is therefore always treated as
 * "ALLOW" here — the dangerous direction for a feature-gate is "wrongly
 * denies a paying customer", not "wrongly grants a free one" while the
 * whole mechanism is still being rolled out. Concretely, has()/limit()/
 * withinLimit() ALL return "allowed" whenever ANY of the following is true:
 *
 *   1. `entitlements.enforcement_enabled` (tblSettings) is not exactly true,
 *   2. the `entitlement_enforcement` tblFeatureToggles row is not enabled
 *      (globally, or for the caller's partner via tblPartnerFeatures),
 *   3. the requested feature key is not a row in tblFeatureCatalog,
 *   4. `entitlements.log_only` is true (the post-flip "shadow week" — a
 *      would-be denial is logged to tblActivityLog but still allowed), or
 *   5. ANY exception/error is thrown anywhere in the resolution path — it
 *      is caught, logged via ErrorLogger, and the call still returns "allow".
 *
 * Because gates 1 and 2 both default OFF (migration 046 seeds
 * `entitlements.enforcement_enabled = 'false'` and
 * `tblFeatureToggles.entitlement_enforcement.isEnabledGlobally = 0`),
 * deploying this class changes NO current behaviour whatsoever: every
 * public method here short-circuits to "allow" before it ever runs a
 * catalog query, for every caller, until a human deliberately flips both
 * switches (and even then, `entitlements.log_only` keeps it non-enforcing
 * for a further "shadow week").
 *
 * ── Resolution algorithm (mirrors pricing_schema_design.md §9 exactly) ──────
 *   0. Gate check (see above) → if not actively enforcing, return the
 *      ALLOW-ALL sentinel without touching the database at all.
 *   1. Base layer: tblFeatureCatalog.defaultValue for every active feature
 *      — the "no subscription at all" floor.
 *   2. Plan layer: the account's active/trial/grace/past_due subscription
 *      (or the catalog's isDefault=1 free tier if none exists). If the
 *      subscription is grandfathered, overlay its frozen
 *      entitlementSnapshot JSON and skip 2a entirely.
 *      2a. Overlay active tblPlanFeatures rows for (planScope, planID)
 *          whose effective window covers today. If the plan has NO rows
 *          there yet, fall back to a best-effort read of the tier's legacy
 *          `featureLimits` JSON (see LEGACY_FEATURE_LIMIT_MAP below).
 *   3. Add-on layer: active tblAccountAddOns × tblAddOns rows raise/unlock
 *      entitlements on top of the plan (quota_pack sums, feature_unlock/
 *      connection turn on or add a unit, bundle applies grantsJSON).
 *   4. Override layer: unexpired tblEntitlementOverrides rows for
 *      (accountType, accountID) REPLACE whatever layers 2-3 computed for
 *      that key — an explicit `deny` override always wins.
 *   5. Ops kill-switch: if the feature's featureToggleKey maps to a
 *      tblFeatureToggles row that is off (globally, or for this partner via
 *      tblPartnerFeatures) → forced OFF regardless of payment. Ops trumps
 *      money.
 *
 * Caching: a per-request static array avoids recomputing resolveAll() more
 * than once per account per PHP process/request. An OPTIONAL APCu layer
 * (used only if the apcu extension is loaded — common on Dreamhost shared
 * hosting is APCu-less, so this degrades to the per-request cache alone)
 * is keyed with `entitlements.cache_ttl` / `entitlements.cache_epoch`
 * (tblSettings). The `tblEntitlementCache` DB-fallback table created by
 * migration 046 exists for a future iteration that needs cross-request
 * caching without APCu on shared hosting — it is intentionally NOT wired
 * into the hot path yet (documented simplification, not a missing table).
 *
 * @see pricing_schema_design.md (the design spec this class implements, §8-§9)
 * @see Pricing_Strategy.md (repo root — the pricing/tier ladder this resolves against)
 * @see _database/migrations/046_flexible_pricing_catalog.sql (the schema + dormant seed)
 * @see web/private_html/payments/BillingMode.php (the fail-CLOSED mirror-image guard)
 * @see web/private_html/api/Response.php (B-011 {success,data|error} envelope — callers of require() translate EntitlementDeniedException into this)
 * @see https://www.php.net/manual/en/function.apcu-fetch.php
 *
 * @package    SIGNula
 * @subpackage Billing
 * @version    1.0.0
 * @since      2.9.0-beta (FG-011)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access — all SIGNula files must be loaded via the bootstrap
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 📚 Require the dedicated exception type require() throws. Note:
// web/private_html/billing/ is NOT covered by the spl_autoload_register()
// directory list in web/_config/config.php (only security/api/auth/utils/
// email subdirectories of private_html are autoloaded — payments/ and
// billing/ classes are always explicitly required, mirroring how
// BillingMode.php requires BillingModeException.php immediately below its
// own SIGNULA_INIT guard).
$entitlementDeniedExceptionPath = __DIR__ . DIRECTORY_SEPARATOR . 'EntitlementDeniedException.php';
if (file_exists($entitlementDeniedExceptionPath)) {
    require_once $entitlementDeniedExceptionPath;
} else {
    // ⚠️ Critical dependency — log and halt if the exception type is missing
    error_log('🚨 [Entitlements] EntitlementDeniedException.php not found at: ' . $entitlementDeniedExceptionPath);
    throw new RuntimeException('Required dependency EntitlementDeniedException.php is not available');
}

/**
 * 🔓 Entitlements
 *
 * Static utility class — no instances, following the project-wide convention
 * (Auth, MFA, SecurityUtils, BillingMode, PaymentManager, UsageTracker, …).
 *
 * Configuration is read from tblSettings via getSetting():
 *   - entitlements.enforcement_enabled ('true'/'false', default 'false')
 *   - entitlements.log_only            ('true'/'false', default 'true')
 *   - entitlements.cache_ttl           (seconds, default 300)
 *   - entitlements.cache_epoch         (integer, bump to invalidate all caches)
 *
 * `$ctx` shape used throughout this class:
 * ```php
 * ['accountType' => 'user'|'org'|'partner', 'accountID' => int]
 * ```
 */
class Entitlements
{
    // ========================================================================
    // 🔧 CONSTANTS
    // ========================================================================

    /** @var string tblSettings key — the master dormant-shipping switch. */
    private const SETTING_ENFORCEMENT_ENABLED = 'entitlements.enforcement_enabled';

    /** @var string tblSettings key — post-flip "shadow week" logging-only mode. */
    private const SETTING_LOG_ONLY = 'entitlements.log_only';

    /** @var string tblSettings key — resolved-set cache TTL in seconds. */
    private const SETTING_CACHE_TTL = 'entitlements.cache_ttl';

    /** @var string tblSettings key — bump to invalidate every cached resolved set at once. */
    private const SETTING_CACHE_EPOCH = 'entitlements.cache_epoch';

    /** @var string tblFeatureToggles.featureKey — the ops kill-switch companion to the setting above. */
    private const TOGGLE_KEY = 'entitlement_enforcement';

    /** @var int Fallback cache TTL (seconds) used if the setting is missing. */
    private const DEFAULT_CACHE_TTL = 300;

    /** @var array<int,string> Subscription statuses that still carry entitlements. */
    private const ENTITLED_STATUSES = ['active', 'trial', 'grace', 'past_due'];

    /**
     * @var array<string,string> Best-effort bridge from the LEGACY
     * tblSubscriptionTiers.featureLimits JSON keys (migration 010/019 —
     * api_calls, team_members, storage_gb) to the new typed featureKey
     * vocabulary, used ONLY when a plan has zero tblPlanFeatures rows yet
     * (§9 step 2a). This is deliberately a small, documented, best-effort
     * map — not an attempt to reverse-engineer the free-text `features`
     * JSON array (display bullet strings, not data). A legacy key with no
     * mapping here is simply ignored — the fallback layer only ever
     * ENRICHES resolution, it never introduces a denial.
     */
    private const LEGACY_FEATURE_LIMIT_MAP = [
        'api_calls'    => 'api.calls_month',
        'team_members' => 'orgs.seats',
    ];

    /** @var array<string,array> Per-request resolved-set cache, keyed by "accountType:accountID". */
    private static array $requestCache = [];

    /** @var array<string,array>|null Per-request tblFeatureCatalog cache, keyed by featureKey. */
    private static ?array $catalogByKeyCache = null;

    /** @var array<int,array>|null Per-request tblFeatureCatalog cache, keyed by featureID. */
    private static ?array $catalogByIdCache = null;

    // ========================================================================
    // 🌍 PUBLIC API
    // ========================================================================

    /**
     * ✅ Does this account currently have a feature (boolean-style check)?
     *
     * Fail-open: returns true whenever enforcement is inactive, the feature
     * key is unknown, or any error occurs.
     *
     * @param array  $ctx        ['accountType' => 'user'|'org'|'partner', 'accountID' => int]
     * @param string $featureKey e.g. 'sso.enterprise_connections', 'branding.custom'
     *
     * @return bool
     */
    public static function has(array $ctx, string $featureKey): bool
    {
        try {
            $resolved = self::resolveAll($ctx);

            // 🔓 Fail-open sentinel: enforcement inactive → allow everything.
            if (($resolved['__enforced'] ?? false) === false) {
                return true;
            }

            // 🔓 Unknown feature key → allow (fail open, §8 point 3).
            if (!array_key_exists($featureKey, $resolved['values'])) {
                return true;
            }

            return self::isTruthy($resolved['values'][$featureKey]);

        } catch (\Throwable $e) {
            self::logResolutionFailure($e, 'has', $ctx, $featureKey);
            return true; // 🔓 fail open
        }
    }

    /**
     * 🔢 What numeric limit does this account have for a feature?
     *
     * @param array  $ctx        ['accountType' => 'user'|'org'|'partner', 'accountID' => int]
     * @param string $featureKey e.g. 'mau.included', 'apps.max'
     *
     * @return float|null NULL means unlimited (or unresolvable — fail open
     *                     treats "no known cap" the same as "no cap")
     */
    public static function limit(array $ctx, string $featureKey): ?float
    {
        try {
            $resolved = self::resolveAll($ctx);

            // 🔓 Fail-open sentinel or unknown key → no cap.
            if (($resolved['__enforced'] ?? false) === false) {
                return null;
            }
            if (!array_key_exists($featureKey, $resolved['values'])) {
                return null;
            }

            $value = $resolved['values'][$featureKey];

            if ($value === null) {
                return null; // explicit unlimited
            }

            if (is_numeric($value)) {
                return (float) $value;
            }

            // 🔓 Not a numeric value at all (e.g. a boolean/enum feature
            // queried via limit() by mistake) — fail open rather than
            // guessing at a meaningless cap.
            return null;

        } catch (\Throwable $e) {
            self::logResolutionFailure($e, 'limit', $ctx, $featureKey);
            return null; // 🔓 fail open (no cap)
        }
    }

    /**
     * 📊 Is current usage within the resolved limit for a feature?
     *
     * If $currentUsage is not supplied, this aggregates the account's usage
     * for the CURRENT billing period (as already assigned by UsageTracker::
     * recordUsage() at insert time — see tblUsageRecords.billingPeriodStart/
     * End) via the feature's tblFeatureCatalog.usageMetricID link.
     *
     * @param array      $ctx         ['accountType' => 'user'|'org'|'partner', 'accountID' => int]
     * @param string     $featureKey  e.g. 'mau.included'
     * @param float|null $currentUsage Optional pre-computed usage figure
     *
     * @return bool True if within limit (or fail-open/unlimited)
     */
    public static function withinLimit(array $ctx, string $featureKey, ?float $currentUsage = null): bool
    {
        try {
            $limitValue = self::limit($ctx, $featureKey);

            // 🔓 NULL = unlimited (or unknown/fail-open) → always within limit.
            if ($limitValue === null) {
                return true;
            }

            if ($currentUsage === null) {
                $currentUsage = self::aggregateCurrentUsage(self::normaliseContext($ctx), $featureKey);
            }

            return $currentUsage < $limitValue;

        } catch (\Throwable $e) {
            self::logResolutionFailure($e, 'withinLimit', $ctx, $featureKey);
            return true; // 🔓 fail open
        }
    }

    /**
     * 🚦 Guard helper for controllers/API endpoints.
     *
     * A one-line call — e.g. `Entitlements::require($ctx, 'sso.enterprise_connections');`
     * — that is a complete no-op (never throws) until a human has
     * deliberately: (a) flipped entitlements.enforcement_enabled AND the
     * entitlement_enforcement toggle both ON, AND (b) turned
     * entitlements.log_only OFF. Even then, it only throws when the
     * resolved value is genuinely denied for this specific feature/limit.
     *
     * Callers catch {@see EntitlementDeniedException} and translate it into
     * their own transport (JSON 402/403 via Response::error() per the
     * B-011 envelope, a redirect to an upgrade page, etc.) — this class
     * deliberately does not assume a transport.
     *
     * @param array      $ctx          ['accountType' => 'user'|'org'|'partner', 'accountID' => int]
     * @param string     $featureKey   The feature/limit key to require
     * @param float|null $currentUsage Optional pre-computed usage figure (limit-type features only)
     *
     * @return void
     *
     * @throws EntitlementDeniedException Only when genuinely enforced AND denied
     */
    public static function require(array $ctx, string $featureKey, ?float $currentUsage = null): void
    {
        try {
            $resolved = self::resolveAll($ctx);

            // 🔓 The base gate (entitlements.enforcement_enabled AND the
            // entitlement_enforcement toggle) is off — nothing was even
            // computed (`values` is empty by construction, see resolveAll()),
            // so there is nothing meaningful to check or log. Allow silently.
            if (!($resolved['gateActive'] ?? false)) {
                return;
            }

            if (!array_key_exists($featureKey, $resolved['values'])) {
                return; // 🔓 unknown key → allow
            }

            // ⚠️ Deliberately evaluate the RAW resolved value here rather
            // than delegating to has()/withinLimit() — those two public
            // methods short-circuit to "allowed" whenever `__enforced` is
            // false (i.e. during the log-only shadow week too), which is
            // correct for THEM (informational callers must never see a
            // surprise denial mid-rollout) but would make require() unable
            // to log a would-be denial during log-only mode, defeating the
            // whole point of the shadow week (§8: "record would-be denials
            // ... for a week"). require() needs the REAL answer to decide
            // whether to log, and only THEN applies the same fail-open
            // "still allow" behaviour while logOnly is true.
            $catalogFeature = self::getCatalogByKey()[$featureKey] ?? null;
            $isLimitType = $catalogFeature !== null && $catalogFeature['valueType'] === 'limit';

            $allowed = $isLimitType
                ? self::evaluateWithinLimitFromResolved($resolved, self::normaliseContext($ctx), $featureKey, $currentUsage)
                : self::isTruthy($resolved['values'][$featureKey]);

            if ($allowed) {
                return;
            }

            // 🚫 Genuinely denied by the real resolved value — record a
            // would-be/actual denial to the activity log either way (useful
            // both during the shadow week and once live).
            $logOnly = $resolved['logOnly'] ?? true;
            self::logDenial($ctx, $featureKey, $logOnly);

            if ($logOnly) {
                // 📝 Shadow week: logged, but still ALLOW (never blocks).
                return;
            }

            throw new EntitlementDeniedException(
                $featureKey,
                "Account is not entitled to '{$featureKey}' on its current plan."
            );

        } catch (EntitlementDeniedException $e) {
            // ↩️ Let a genuine, intentional denial propagate to the caller.
            throw $e;
        } catch (\Throwable $e) {
            // 🔓 Any OTHER failure (DB error, malformed row, etc.) fails
            // open — never blocks a caller due to an internal error.
            self::logResolutionFailure($e, 'require', $ctx, $featureKey);
            return;
        }
    }

    /**
     * 🔢 Evaluate "within limit" directly from an already-resolved envelope's
     * raw `values` map — used by require() so a shadow-week (log-only) run
     * can determine the REAL answer for logging purposes without going
     * through withinLimit()'s own "allow while not __enforced" short-circuit.
     *
     * @param array      $resolved     The envelope returned by resolveAll()
     * @param array      $normalisedCtx Normalised ['accountType'=>string,'accountID'=>int]
     * @param string     $featureKey
     * @param float|null $currentUsage Optional pre-computed usage figure
     *
     * @return bool
     */
    private static function evaluateWithinLimitFromResolved(
        array $resolved,
        array $normalisedCtx,
        string $featureKey,
        ?float $currentUsage
    ): bool {
        $value = $resolved['values'][$featureKey] ?? null;

        if ($value === null || !is_numeric($value)) {
            return true; // unlimited, or not meaningfully numeric — fail open
        }

        if ($currentUsage === null) {
            $currentUsage = self::aggregateCurrentUsage($normalisedCtx, $featureKey);
        }

        return $currentUsage < (float) $value;
    }

    /**
     * 🗺️ Resolve the FULL entitlement map for an account.
     *
     * Returns an internal envelope (not just the bare feature map) so
     * has()/limit()/withinLimit()/require() can all cheaply tell whether
     * enforcement is actually active without re-checking settings:
     * ```php
     * [
     *     'gateActive' => bool,   // true when BOTH the setting AND the ops
     *                             // toggle are on — i.e. `values` was
     *                             // actually computed at all
     *     '__enforced' => bool,   // true only when genuinely gating right
     *                             // now: gateActive AND NOT logOnly
     *     'logOnly'    => bool,   // true during the post-flip shadow week
     *                             // (only meaningful when gateActive=true)
     *     'values'     => [featureKey => mixed, ...],
     * ]
     * ```
     * When `gateActive` is false, `values` is an EMPTY array and every
     * public method treats every key as allowed (the fail-open sentinel) —
     * this avoids running a single catalog query when the whole mechanism
     * is switched off, which is the common case for the lifetime of this
     * migration until a human opts in. When `gateActive` is true but
     * `logOnly` is also true, `values` IS fully computed (so require() can
     * log a real would-be denial for the shadow week — see require()'s own
     * doc-comment) even though `__enforced` stays false so has()/limit()/
     * withinLimit() still allow everything to ordinary callers.
     *
     * @param array $ctx ['accountType' => 'user'|'org'|'partner', 'accountID' => int]
     *
     * @return array{gateActive: bool, __enforced: bool, logOnly: bool, values: array<string,mixed>}
     */
    public static function resolveAll(array $ctx): array
    {
        try {
            // ── 0️⃣ Gate check — the ONLY code path every caller takes until
            // a human deliberately opts in. No database query above this
            // line except the two setting reads (already process-cached by
            // getSetting()/$GLOBALS['settings'] — see web/_config/config.php). ──
            if (!self::isSettingEnforcementEnabled()) {
                return ['gateActive' => false, '__enforced' => false, 'logOnly' => true, 'values' => []];
            }

            $normalisedCtx = self::normaliseContext($ctx);

            if (!self::isOpsToggleActive(self::TOGGLE_KEY, $normalisedCtx)) {
                // 🔧 Ops kill-switch for the WHOLE mechanism is off — belt
                // and braces even if the setting above was somehow flipped
                // without the toggle following (§8 point 2).
                return ['gateActive' => false, '__enforced' => false, 'logOnly' => true, 'values' => []];
            }

            $logOnly = self::isSettingLogOnly();

            // ── 🗄️ Per-request cache (and optional APCu layer) ──────────────
            // Both caches store the raw resolved `values` (which do not
            // depend on logOnly) but `logOnly`/`__enforced` are always
            // recomputed fresh from the CURRENT setting on every hit, since
            // that setting can legitimately change between calls within a
            // single request/process (e.g. during a shadow-week rollout, or
            // in tests) and a cached stale flag must never suppress logging.
            $cacheKey = $normalisedCtx['accountType'] . ':' . $normalisedCtx['accountID'];
            if (isset(self::$requestCache[$cacheKey])) {
                $cached = self::$requestCache[$cacheKey];
                $cached['logOnly'] = $logOnly;
                $cached['__enforced'] = ($cached['gateActive'] ?? false) && !$logOnly;
                return $cached;
            }

            $apcuKey = self::apcuCacheKey($normalisedCtx);
            if ($apcuKey !== null && function_exists('apcu_fetch')) {
                $apcuHit = apcu_fetch($apcuKey, $apcuSuccess);
                if ($apcuSuccess && is_array($apcuHit)) {
                    self::$requestCache[$cacheKey] = $apcuHit;
                    $apcuHit['logOnly'] = $logOnly;
                    $apcuHit['__enforced'] = ($apcuHit['gateActive'] ?? false) && !$logOnly;
                    return $apcuHit;
                }
            }

            // ── 1️⃣ Base layer — the catalog's defaultValue floor ───────────
            $catalog = self::getCatalog();
            $values = [];
            foreach ($catalog as $featureKey => $featureRow) {
                $values[$featureKey] = self::castDefaultValue($featureRow);
            }

            // ── 2️⃣ Plan layer (+ grandfather snapshot / legacy fallback) ───
            $subscription = self::resolveActiveSubscription(
                $normalisedCtx['accountType'],
                $normalisedCtx['accountID']
            );

            if ($subscription !== null) {
                if (!empty($subscription['isGrandfathered']) && !empty($subscription['entitlementSnapshot'])) {
                    $snapshot = json_decode((string) $subscription['entitlementSnapshot'], true);
                    if (is_array($snapshot)) {
                        foreach ($snapshot as $featureKey => $snapshotValue) {
                            if (array_key_exists($featureKey, $values)) {
                                $values[$featureKey] = $snapshotValue;
                            }
                        }
                    }
                } else {
                    $planRows = self::getPlanFeatureRows(
                        $subscription['planScope'],
                        (int) $subscription['planID']
                    );

                    if (!empty($planRows)) {
                        foreach ($planRows as $row) {
                            $featureRow = self::getCatalogById()[(int) $row['featureID']] ?? null;
                            if ($featureRow === null) {
                                continue; // unknown/inactive feature — ignore, never deny
                            }
                            $values[$featureRow['featureKey']] = self::castAssignedValue($row, $featureRow);
                        }
                    } else {
                        // 2a. No normalised rows yet for this plan — best-effort
                        // legacy JSON fallback (see LEGACY_FEATURE_LIMIT_MAP).
                        self::applyLegacyFallback(
                            $values,
                            $subscription['planScope'],
                            (int) $subscription['planID']
                        );
                    }

                    // ── 3️⃣ Add-on layer ─────────────────────────────────────
                    if (!empty($subscription['subscriptionID'])) {
                        $addOnRows = self::getActiveAddOnRows((int) $subscription['subscriptionID']);
                        foreach ($addOnRows as $addOn) {
                            self::mergeAddOn($values, $addOn);
                        }
                    }
                }
            }

            // ── 4️⃣ Override layer — most-specific wins, deny beats everything ──
            $overrides = self::getOverrideRows(
                $normalisedCtx['accountType'],
                $normalisedCtx['accountID']
            );
            foreach ($overrides as $override) {
                $featureRow = self::getCatalogById()[(int) $override['featureID']] ?? null;
                if ($featureRow === null) {
                    continue;
                }
                if ($override['overrideMode'] === 'deny') {
                    $values[$featureRow['featureKey']] = self::deniedValueFor($featureRow);
                } else {
                    $values[$featureRow['featureKey']] = self::castAssignedValue($override, $featureRow);
                }
            }

            // ── 5️⃣ Ops kill-switch — forced OFF regardless of payment ──────
            foreach ($catalog as $featureKey => $featureRow) {
                $toggleKey = $featureRow['featureToggleKey'] ?? null;
                if ($toggleKey !== null && $toggleKey !== ''
                    && !self::isOpsToggleActive($toggleKey, $normalisedCtx)
                ) {
                    $values[$featureKey] = self::deniedValueFor($featureRow);
                }
            }

            $result = ['gateActive' => true, '__enforced' => !$logOnly, 'logOnly' => $logOnly, 'values' => $values];

            self::$requestCache[$cacheKey] = $result;

            if ($apcuKey !== null && function_exists('apcu_store')) {
                $ttl = (int) (getSetting(self::SETTING_CACHE_TTL, self::DEFAULT_CACHE_TTL) ?: self::DEFAULT_CACHE_TTL);
                apcu_store($apcuKey, $result, $ttl);
            }

            return $result;

        } catch (\Throwable $e) {
            // 🔓 ANY failure anywhere above → fail open, ALLOW-ALL sentinel.
            self::logResolutionFailure($e, 'resolveAll', $ctx, null);
            return ['gateActive' => false, '__enforced' => false, 'logOnly' => true, 'values' => []];
        }
    }

    // ========================================================================
    // 🔍 GATE CHECKS
    // ========================================================================

    /**
     * 🔒 Is `entitlements.enforcement_enabled` currently true?
     *
     * Deliberately INVERTED from BillingMode::isGuardEnabled()'s fail-CLOSED
     * default: a missing, ambiguous, or unrecognised value here means
     * "NOT enforcing" (fail OPEN), not "enforcing". This is the single most
     * important line in this class — it is what keeps the whole migration
     * dormant until a human explicitly opts in.
     *
     * @return bool
     */
    private static function isSettingEnforcementEnabled(): bool
    {
        $raw = getSetting(self::SETTING_ENFORCEMENT_ENABLED, false);

        if (is_bool($raw)) {
            return $raw;
        }

        $normalised = strtolower(trim((string) $raw));
        return in_array($normalised, ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * 📝 Is `entitlements.log_only` currently true (the shadow-week mode)?
     *
     * Defaults to true (log-only) when missing/ambiguous — the safer
     * direction is to log-not-enforce until an admin has explicitly turned
     * genuine enforcement on.
     *
     * @return bool
     */
    private static function isSettingLogOnly(): bool
    {
        $raw = getSetting(self::SETTING_LOG_ONLY, true);

        if (is_bool($raw)) {
            return $raw;
        }

        $normalised = strtolower(trim((string) $raw));
        // 🔓 Anything OTHER than an explicit "off" spelling stays log-only.
        return !in_array($normalised, ['0', 'false', 'off', 'no'], true);
    }

    /**
     * 🔧 Is the named tblFeatureToggles ops switch currently active for this ctx?
     *
     * Missing toggle row → true (no ops restriction defined at all, fail
     * open). Found row → isEnabledGlobally, overridden per-partner via
     * tblPartnerFeatures when the toggle allows partner overrides.
     *
     * @param string $toggleKey tblFeatureToggles.featureKey
     * @param array  $ctx       Normalised ['accountType'=>string,'accountID'=>int]
     *
     * @return bool
     */
    private static function isOpsToggleActive(string $toggleKey, array $ctx): bool
    {
        $toggle = Database::fetchOne(
            "SELECT featureID, isEnabledGlobally, canPartnersToggle
             FROM tblFeatureToggles WHERE featureKey = ?",
            [$toggleKey],
            's'
        );

        if ($toggle === null) {
            return true; // 🔓 no ops gate defined for this key → allow
        }

        $enabled = (bool) $toggle['isEnabledGlobally'];

        // 🏢 A partner-scoped context (or a subscription that happens to be
        // billed via a partner) may have its own override, but ONLY if the
        // toggle explicitly permits partner-level control.
        if ((bool) $toggle['canPartnersToggle'] && $ctx['accountType'] === 'partner' && $ctx['accountID'] > 0) {
            $partnerOverride = Database::fetchOne(
                "SELECT isEnabled FROM tblPartnerFeatures
                 WHERE partnerID = ? AND featureID = ?
                   AND (expiresAt IS NULL OR expiresAt > NOW())",
                [$ctx['accountID'], (int) $toggle['featureID']],
                'ii'
            );
            if ($partnerOverride !== null) {
                $enabled = (bool) $partnerOverride['isEnabled'];
            }
        }

        return $enabled;
    }

    // ========================================================================
    // 🗄️ DATA ACCESS HELPERS
    // ========================================================================

    /**
     * 📚 Load + cache (per request) the active tblFeatureCatalog, keyed by featureKey.
     *
     * @return array<string,array>
     */
    private static function getCatalog(): array
    {
        if (self::$catalogByKeyCache !== null) {
            return self::$catalogByKeyCache;
        }

        $rows = Database::fetchAll(
            "SELECT * FROM tblFeatureCatalog WHERE isActive = 1 ORDER BY sortOrder ASC",
            [],
            ''
        );

        $byKey = [];
        $byId = [];
        foreach ($rows as $row) {
            $byKey[$row['featureKey']] = $row;
            $byId[(int) $row['featureID']] = $row;
        }

        self::$catalogByKeyCache = $byKey;
        self::$catalogByIdCache = $byId;

        return $byKey;
    }

    /**
     * 📚 Same catalog, keyed by featureID (built alongside getCatalog()).
     *
     * @return array<int,array>
     */
    private static function getCatalogById(): array
    {
        if (self::$catalogByIdCache === null) {
            self::getCatalog(); // populates both caches together
        }

        return self::$catalogByIdCache ?? [];
    }

    /**
     * 🔍 Resolve the account's currently-entitled subscription.
     *
     * @param string $accountType 'user'|'org'|'partner'
     * @param int    $accountID   The ID within the matching table
     *
     * @return array{subscriptionID:?int,planScope:string,planID:int,isGrandfathered:bool,entitlementSnapshot:?string}|null
     */
    private static function resolveActiveSubscription(string $accountType, int $accountID): ?array
    {
        if ($accountID <= 0) {
            return self::defaultFreeTierPseudoSubscription();
        }

        $statusPlaceholders = implode(',', array_fill(0, count(self::ENTITLED_STATUSES), '?'));

        $sql = "SELECT subscriptionID, tierID, partnerTierID, isGrandfathered, entitlementSnapshot
                FROM tblSubscriptions
                WHERE subscriptionStatus IN ({$statusPlaceholders})";

        $params = self::ENTITLED_STATUSES;
        $types = str_repeat('s', count(self::ENTITLED_STATUSES));

        // 🔀 Scope filter per account type — see migration 046 header note:
        // tblSubscriptions already carries userID/partnerID/organizationID.
        switch ($accountType) {
            case 'org':
                $sql .= " AND organizationID = ?";
                $params[] = $accountID;
                $types .= 'i';
                break;

            case 'partner':
                $sql .= " AND partnerID = ? AND organizationID IS NULL";
                $params[] = $accountID;
                $types .= 'i';
                break;

            case 'user':
            default:
                $sql .= " AND userID = ?";
                $params[] = $accountID;
                $types .= 'i';
                break;
        }

        $sql .= " ORDER BY createdAt DESC LIMIT 1";

        $row = Database::fetchOne($sql, $params, $types);

        if ($row === null) {
            return self::defaultFreeTierPseudoSubscription();
        }

        // 🔑 tblSubscriptions.partnerTierID (migration 012) is the EXISTING
        // discriminator column reused here — see migration 046's header
        // note. NOT NULL ⇒ this subscription is on a partner-defined tier.
        $isPartnerTier = $row['partnerTierID'] !== null;

        return [
            'subscriptionID'      => (int) $row['subscriptionID'],
            'planScope'           => $isPartnerTier ? 'partner' : 'global',
            'planID'              => $isPartnerTier ? (int) $row['partnerTierID'] : (int) $row['tierID'],
            'isGrandfathered'     => (bool) ($row['isGrandfathered'] ?? false),
            'entitlementSnapshot' => $row['entitlementSnapshot'] ?? null,
        ];
    }

    /**
     * 🆓 No real subscription exists — fall back to the catalog's
     * isDefault=1 free tier so table-stakes features still resolve.
     *
     * @return array|null NULL if even the default tier cannot be found
     */
    private static function defaultFreeTierPseudoSubscription(): ?array
    {
        $freeTier = Database::fetchOne(
            "SELECT tierID FROM tblSubscriptionTiers
             WHERE isDefault = 1 AND isActive = 1
             ORDER BY displayOrder ASC LIMIT 1",
            [],
            ''
        );

        if ($freeTier === null) {
            return null; // 🔓 nothing to overlay — base layer floor still applies
        }

        return [
            'subscriptionID'      => null,
            'planScope'           => 'global',
            'planID'              => (int) $freeTier['tierID'],
            'isGrandfathered'     => false,
            'entitlementSnapshot' => null,
        ];
    }

    /**
     * 📋 Active, currently-effective tblPlanFeatures rows for a plan.
     *
     * @param string $planScope 'global'|'partner'
     * @param int    $planID    Tier PK in the table selected by planScope
     *
     * @return array<int,array>
     */
    private static function getPlanFeatureRows(string $planScope, int $planID): array
    {
        return Database::fetchAll(
            "SELECT * FROM tblPlanFeatures
             WHERE planScope = ? AND planID = ? AND isActive = 1
               AND (effectiveFrom IS NULL OR effectiveFrom <= CURDATE())
               AND (effectiveUntil IS NULL OR effectiveUntil >= CURDATE())",
            [$planScope, $planID],
            'si'
        );
    }

    /**
     * 🎁 Active tblAccountAddOns × tblAddOns rows for a subscription.
     *
     * @param int $subscriptionID
     *
     * @return array<int,array>
     */
    private static function getActiveAddOnRows(int $subscriptionID): array
    {
        return Database::fetchAll(
            "SELECT aao.quantity, ao.addOnType, ao.featureID, ao.quantityPerUnit, ao.grantsJSON
             FROM tblAccountAddOns aao
             JOIN tblAddOns ao ON ao.addOnID = aao.addOnID
             WHERE aao.subscriptionID = ?
               AND aao.status = 'active'
               AND (aao.endDate IS NULL OR aao.endDate >= CURDATE())",
            [$subscriptionID],
            'i'
        );
    }

    /**
     * 🔐 Unexpired tblEntitlementOverrides rows for an account.
     *
     * @param string $accountType 'user'|'org'|'partner'
     * @param int    $accountID
     *
     * @return array<int,array>
     */
    private static function getOverrideRows(string $accountType, int $accountID): array
    {
        if ($accountID <= 0) {
            return [];
        }

        return Database::fetchAll(
            "SELECT * FROM tblEntitlementOverrides
             WHERE accountType = ? AND accountID = ? AND isActive = 1
               AND (expiresAt IS NULL OR expiresAt > NOW())",
            [$accountType, $accountID],
            'si'
        );
    }

    /**
     * 📊 Sum tblUsageRecords for the CURRENT billing period (as already
     * assigned by UsageTracker at insert time) for a limit-type feature's
     * linked usage metric.
     *
     * @param array  $ctx        Normalised ['accountType'=>string,'accountID'=>int]
     * @param string $featureKey The limit-type feature key
     *
     * @return float 0.0 if the feature has no linked metric or no usage yet
     */
    private static function aggregateCurrentUsage(array $ctx, string $featureKey): float
    {
        $featureRow = self::getCatalogByKey()[$featureKey] ?? null;
        if ($featureRow === null || empty($featureRow['usageMetricID'])) {
            return 0.0; // 🔓 nothing to measure against → treat as no usage
        }

        $sql = "SELECT COALESCE(SUM(quantity), 0) AS total
                FROM tblUsageRecords
                WHERE metricID = ?
                  AND billingPeriodStart <= CURDATE()
                  AND billingPeriodEnd >= CURDATE()";
        $params = [(int) $featureRow['usageMetricID']];
        $types = 'i';

        if ($ctx['accountType'] === 'partner') {
            $sql .= " AND partnerID = ?";
        } else {
            $sql .= " AND userID = ?";
        }
        $params[] = $ctx['accountID'];
        $types .= 'i';

        $row = Database::fetchOne($sql, $params, $types);

        return (float) ($row['total'] ?? 0.0);
    }

    /**
     * 🔤 Convenience alias — getCatalog() by another name for readability
     * at call sites that are conceptually "looking a key up", not
     * "loading the whole table".
     *
     * @return array<string,array>
     */
    private static function getCatalogByKey(): array
    {
        return self::getCatalog();
    }

    // ========================================================================
    // 🧮 VALUE RESOLUTION HELPERS
    // ========================================================================

    /**
     * 🎯 Cast a catalog row's defaultValue string into its typed PHP value.
     *
     * @param array $featureRow tblFeatureCatalog row
     *
     * @return mixed
     */
    private static function castDefaultValue(array $featureRow): mixed
    {
        $raw = $featureRow['defaultValue'];

        return match ($featureRow['valueType']) {
            'boolean' => $raw !== null ? (bool) filter_var($raw, FILTER_VALIDATE_BOOLEAN) : false,
            'limit'   => $raw !== null ? (float) $raw : null,
            'json'    => $raw !== null ? json_decode((string) $raw, true) : null,
            default   => $raw, // 'enum' and anything unrecognised — keep as string
        };
    }

    /**
     * 🎯 Cast a tblPlanFeatures/tblEntitlementOverrides row's assigned value
     * columns (valueBool/valueLimit/isUnlimited/valueText/valueJSON) into a
     * typed PHP value, per the catalog feature's declared valueType.
     *
     * @param array $row         A row from tblPlanFeatures or tblEntitlementOverrides
     * @param array $featureRow  The matching tblFeatureCatalog row
     *
     * @return mixed NULL for a limit-type row means unlimited when
     *               isUnlimited=1; a bare NULL from a genuinely empty
     *               assignment falls back to the catalog defaultValue
     */
    private static function castAssignedValue(array $row, array $featureRow): mixed
    {
        return match ($featureRow['valueType']) {
            'boolean' => $row['valueBool'] !== null
                ? (bool) $row['valueBool']
                : self::castDefaultValue($featureRow),

            'limit' => !empty($row['isUnlimited'])
                ? null
                : ($row['valueLimit'] !== null ? (float) $row['valueLimit'] : self::castDefaultValue($featureRow)),

            'json' => $row['valueJSON'] !== null
                ? json_decode((string) $row['valueJSON'], true)
                : self::castDefaultValue($featureRow),

            default => $row['valueText'] ?? self::castDefaultValue($featureRow), // 'enum'
        };
    }

    /**
     * 🚫 The "hard off" value for a feature, used both for an explicit
     * `deny` override and for an ops kill-switch trip.
     *
     * @param array $featureRow tblFeatureCatalog row
     *
     * @return mixed
     */
    private static function deniedValueFor(array $featureRow): mixed
    {
        return match ($featureRow['valueType']) {
            'boolean' => false,
            'limit'   => 0.0,
            default   => null, // enum/json — "no value" rather than a fabricated one
        };
    }

    /**
     * 🎁 Merge one active add-on row into the resolving value map.
     *
     * quota_pack/connection RAISE a limit-type feature (SUM); feature_unlock
     * turns a boolean feature ON (logical OR); bundle applies its own
     * grantsJSON list of {featureKey, valueBool|valueLimit|isUnlimited|
     * valueText} entries using the same merge rules recursively.
     *
     * @param array $values (by reference) The in-progress resolved value map
     * @param array $addOn  A row from getActiveAddOnRows()
     *
     * @return void
     */
    private static function mergeAddOn(array &$values, array $addOn): void
    {
        $quantity = (int) ($addOn['quantity'] ?? 1);

        switch ($addOn['addOnType']) {
            case 'quota_pack':
            case 'connection':
                if (empty($addOn['featureID'])) {
                    return;
                }
                $featureRow = self::getCatalogById()[(int) $addOn['featureID']] ?? null;
                if ($featureRow === null || $featureRow['valueType'] !== 'limit') {
                    return;
                }
                $perUnit = $addOn['addOnType'] === 'quota_pack'
                    ? (float) ($addOn['quantityPerUnit'] ?? 0.0)
                    : 1.0; // one connection add-on unit = +1 connection
                $key = $featureRow['featureKey'];
                $current = $values[$key] ?? null;
                if ($current === null) {
                    // Already unlimited (or catalog default is unlimited) —
                    // adding more capacity on top of "unlimited" is a no-op.
                    return;
                }
                $values[$key] = (float) $current + ($perUnit * $quantity);
                break;

            case 'feature_unlock':
                if (empty($addOn['featureID'])) {
                    return;
                }
                $featureRow = self::getCatalogById()[(int) $addOn['featureID']] ?? null;
                if ($featureRow === null || $featureRow['valueType'] !== 'boolean') {
                    return;
                }
                $values[$featureRow['featureKey']] = true; // logical OR with whatever the plan already granted
                break;

            case 'bundle':
                $grants = !empty($addOn['grantsJSON']) ? json_decode((string) $addOn['grantsJSON'], true) : null;
                if (!is_array($grants)) {
                    return;
                }
                foreach ($grants as $grant) {
                    $grantKey = $grant['featureKey'] ?? null;
                    if ($grantKey === null || !array_key_exists($grantKey, $values)) {
                        continue;
                    }
                    if (array_key_exists('valueBool', $grant) && $grant['valueBool']) {
                        $values[$grantKey] = true;
                    } elseif (array_key_exists('isUnlimited', $grant) && $grant['isUnlimited']) {
                        $values[$grantKey] = null;
                    } elseif (array_key_exists('valueLimit', $grant) && is_numeric($values[$grantKey] ?? null)) {
                        $values[$grantKey] = (float) $values[$grantKey] + (float) $grant['valueLimit'];
                    } elseif (array_key_exists('valueText', $grant)) {
                        $values[$grantKey] = $grant['valueText'];
                    }
                }
                break;

            default:
                // Forward-compatible: an unrecognised addOnType is ignored,
                // never denied.
                break;
        }
    }

    /**
     * 🗃️ Best-effort legacy JSON fallback (§9 step 2a) — used ONLY when a
     * plan has ZERO tblPlanFeatures rows yet. Reads
     * tblSubscriptionTiers.featureLimits (or tblPartnerSubscriptionTiers'
     * equivalent) and applies the handful of well-known keys in
     * LEGACY_FEATURE_LIMIT_MAP. Deliberately conservative: an unmapped
     * legacy key is silently ignored.
     *
     * @param array  $values    (by reference) The in-progress resolved value map
     * @param string $planScope 'global'|'partner'
     * @param int    $planID    Tier PK in the table selected by planScope
     *
     * @return void
     */
    private static function applyLegacyFallback(array &$values, string $planScope, int $planID): void
    {
        // Both tblSubscriptionTiers and tblPartnerSubscriptionTiers use the
        // same PK column name (`tierID`) — only the TABLE differs by scope
        // (confirmed against migrations 010/012; see migration 046 header).
        $table = $planScope === 'partner' ? 'tblPartnerSubscriptionTiers' : 'tblSubscriptionTiers';

        $tier = Database::fetchOne(
            "SELECT featureLimits FROM `{$table}` WHERE `tierID` = ?",
            [$planID],
            'i'
        );

        if ($tier === null || empty($tier['featureLimits'])) {
            return;
        }

        $legacyLimits = json_decode((string) $tier['featureLimits'], true);
        if (!is_array($legacyLimits)) {
            return;
        }

        foreach (self::LEGACY_FEATURE_LIMIT_MAP as $legacyKey => $featureKey) {
            if (!array_key_exists($legacyKey, $legacyLimits) || !array_key_exists($featureKey, $values)) {
                continue;
            }
            $legacyValue = $legacyLimits[$legacyKey];
            // Legacy convention (see migration 010/019 seeds): a JSON null
            // value means unlimited for that legacy limit.
            $values[$featureKey] = $legacyValue === null ? null : (float) $legacyValue;
        }
    }

    // ========================================================================
    // 🧰 SMALL UTILITIES
    // ========================================================================

    /**
     * 🧹 Normalise a caller-supplied $ctx into a strict shape, defaulting
     * safely (never throwing) on malformed input.
     *
     * @param array $ctx Caller-supplied context
     *
     * @return array{accountType:string,accountID:int}
     */
    private static function normaliseContext(array $ctx): array
    {
        $accountType = strtolower(trim((string) ($ctx['accountType'] ?? 'user')));
        if (!in_array($accountType, ['user', 'org', 'partner'], true)) {
            $accountType = 'user';
        }

        $accountID = (int) ($ctx['accountID'] ?? 0);
        if ($accountID < 0) {
            $accountID = 0;
        }

        return ['accountType' => $accountType, 'accountID' => $accountID];
    }

    /**
     * 🔑 Build an APCu cache key for a normalised context, folding in the
     * current cache_epoch so bumping that setting invalidates every entry
     * at once without needing to enumerate/delete individual APCu keys.
     *
     * @param array $ctx Normalised ['accountType'=>string,'accountID'=>int]
     *
     * @return string|null NULL if accountID is not resolvable (do not cache)
     */
    private static function apcuCacheKey(array $ctx): ?string
    {
        if ($ctx['accountID'] <= 0) {
            return null;
        }

        $epoch = (int) (getSetting(self::SETTING_CACHE_EPOCH, 1) ?: 1);

        return "signula:entitlements:v{$epoch}:{$ctx['accountType']}:{$ctx['accountID']}";
    }

    /**
     * ✅ Interpret a resolved value as a boolean "is this allowed" answer
     * for has(). Limit-type features are "allowed" unless explicitly
     * capped at exactly zero; enum/json features are "allowed" whenever
     * they resolved to a non-null value.
     *
     * @param mixed $value
     *
     * @return bool
     */
    private static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return true; // unlimited / unset-but-present → allowed
        }

        if (is_numeric($value)) {
            return ((float) $value) !== 0.0;
        }

        // enum/json/string — present at all counts as "has" for the
        // purposes of a boolean-style check.
        return $value !== '' && $value !== false;
    }

    /**
     * 📝 Log a would-be/actual entitlement denial to tblActivityLog.
     *
     * @param array  $ctx        Normalised context
     * @param string $featureKey The denied feature key
     * @param bool   $logOnly    Whether this was a shadow-week log-only denial
     *
     * @return void
     */
    private static function logDenial(array $ctx, string $featureKey, bool $logOnly): void
    {
        try {
            if (!class_exists('ActivityLogger')) {
                return;
            }

            $userID = $ctx['accountType'] === 'user' && $ctx['accountID'] > 0 ? $ctx['accountID'] : null;

            ActivityLogger::log(
                $userID,
                $logOnly ? 'entitlement_would_deny' : 'entitlement_denied',
                'billing',
                'notice',
                ($logOnly ? 'Would deny' : 'Denied') . " feature '{$featureKey}' for "
                    . "{$ctx['accountType']} #{$ctx['accountID']} (FG-011 entitlement resolver)",
                ['accountType' => $ctx['accountType'], 'accountID' => $ctx['accountID'], 'featureKey' => $featureKey]
            );
        } catch (\Throwable $e) {
            // 🛑 Never let audit-logging failure affect the fail-open contract.
            error_log('🚨 [Entitlements] Failed to log denial: ' . $e->getMessage());
        }
    }

    /**
     * 🚨 Log an internal resolution failure (the trigger for fail-open) via
     * the standard ErrorLogger, without ever letting the logging call
     * itself throw back out to the caller.
     *
     * @param \Throwable  $e          The caught exception/error
     * @param string      $method     Which public method was executing
     * @param array       $ctx        The (possibly un-normalised) caller context
     * @param string|null $featureKey The feature key being resolved, if any
     *
     * @return void
     */
    private static function logResolutionFailure(\Throwable $e, string $method, array $ctx, ?string $featureKey): void
    {
        try {
            if (class_exists('ErrorLogger')) {
                ErrorLogger::logError(
                    'entitlements_resolution_error',
                    'Entitlements::' . $method . '() failed — failing OPEN: ' . $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    ['ctx' => $ctx, 'featureKey' => $featureKey],
                    'error'
                );
            } else {
                error_log('🚨 [Entitlements] ' . $method . '() failed — failing OPEN: ' . $e->getMessage());
            }
        } catch (\Throwable $loggingFailure) {
            // 🛑 Logging itself failed — last-resort fallback, still never rethrow.
            error_log('🚨 [Entitlements] Failed to log resolution failure: ' . $loggingFailure->getMessage());
        }
    }
}

// ✅ Entitlements loaded successfully
