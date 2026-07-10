<?php
/**
 * ============================================================================
 * 🧪 B-066 — Settings-page query resolution tests (real schema)
 * ============================================================================
 *
 * B-066 (HIGH): 7 of the 13 `settings/*.php` pages, plus `admin/email-
 * webhooks.php`, 500'd on their OWN queries — each written against a
 * table/column name that either never existed or drifted from the real
 * migrations (verified against `information_schema` on the live
 * `signula_test` DB). This suite proves the FIXED queries — copied verbatim
 * from each page — actually execute against the real schema and return the
 * expected rows, for a real fixture user. It intentionally tests the SQL at
 * the `Database::` layer (not a full HTTP render) — see
 * `_tests/Integration/Layout/HeaderFooterRenderTest.php` for the separate
 * full-page-render coverage pattern (not reused here because `failOnWarning`
 * on undefined-array-key reads from mismatched columns would fail the SUITE
 * rather than surface the specific query bug under test).
 *
 * @package    SIGNula\Tests\Integration\Settings
 * @version    2.9.0-beta
 * @see        web/public_html/settings/profile.php (tblOAuthAccounts, no isActive)
 * @see        web/public_html/settings/security.php / activity.php (tblActivityLog.createdAt / success)
 * @see        web/public_html/settings/index.php (tblUserOAuthTokens)
 * @see        web/public_html/settings/connected-accounts.php (tblOAuthAccounts retarget)
 * @see        web/public_html/settings/privacy.php (tblUserPreferences EAV, tblOAuthConsents)
 * @see        web/public_html/settings/usage.php (tblSubscriptions.subscriptionStatus, tblUsageRecords JOINs)
 * @see        web/public_html/admin/email-webhooks.php (tblErrorLog real columns)
 */

namespace SIGNula\Tests\Integration\Settings;

use SIGNula\Tests\DatabaseTestCase;
use Database;

requireSource('_config/database.php');

/**
 * Settings-page query resolution test suite.
 */
class B066SettingsPageQueriesTest extends DatabaseTestCase
{
    /**
     * @var array<int, string> Tables touched by this suite's fixtures.
     */
    protected array $truncateTables = [
        'tblOAuthAccessGrants',
        'tblOAuthConsents',
        'tblOAuthClients',
        'tblUserOAuthTokens',
        'tblOAuthAccounts',
        'tblActivityLog',
        'tblUserPreferences',
        'tblUsageBillingSummary',
        'tblUsageRecords',
        'tblUsageRates',
        'tblUsageMetrics',
        'tblSubscriptions',
        'tblEmailQueue',
        'tblErrorLog',
        'tblUsers',
        'tblPartners',
    ];

    // ========================================================================
    // 🔧 FIXTURE HELPERS
    // ========================================================================

    private function createFixtureUser(): int
    {
        $uuid     = self::generateTestUuid();
        $suffix   = bin2hex(random_bytes(4));

        return Database::insert(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'B066 Test User', 'active', 1, 0, NOW())",
            [$uuid, 'b066_' . $suffix, 'b066_' . $suffix . '@example.com', password_hash('x', PASSWORD_ARGON2ID)],
            'ssss'
        );
    }

    private function createOAuthClient(bool $isFirstParty = false): int
    {
        $suffix = bin2hex(random_bytes(8));

        return Database::insert(
            "INSERT INTO tblOAuthClients (clientIdentifier, clientName, clientType, isFirstParty, allowedScopes, isActive)
             VALUES (?, ?, 'confidential', ?, 'openid profile', 1)",
            [$suffix, 'B066 Test Client ' . $suffix, $isFirstParty ? 1 : 0],
            'ssi'
        );
    }

    // ========================================================================
    // ✅ profile.php — tblOAuthAccounts (no isActive predicate)
    // ========================================================================

    /**
     * profile.php's linked-account-avatar query used to filter on
     * `isActive = 1`, a column tblOAuthAccounts does not have — it fataled
     * with "Unknown column 'isActive'" on every load for any authenticated
     * user. The fixed query (no isActive predicate) must both resolve
     * without error AND correctly filter to only rows with a non-empty
     * profilePicture.
     */
    public function testProfilePageOAuthAccountsQueryResolvesAndFiltersByProfilePicture(): void
    {
        $userID = $this->createFixtureUser();

        Database::insert(
            "INSERT INTO tblOAuthAccounts (userID, provider, providerUserID, email, profilePicture, isPrimary, linkedAt)
             VALUES (?, 'google', 'g-123', 'a@example.com', 'https://example.com/avatar.png', 1, NOW())",
            [$userID],
            'i'
        );
        Database::insert(
            "INSERT INTO tblOAuthAccounts (userID, provider, providerUserID, email, profilePicture, isPrimary, linkedAt)
             VALUES (?, 'microsoft', 'm-456', 'b@example.com', NULL, 0, NOW())",
            [$userID],
            'i'
        );

        $rows = Database::fetchAll(
            "SELECT oauthAccountID, provider, profilePicture, displayName, isPrimary
             FROM tblOAuthAccounts
             WHERE userID = ? AND profilePicture IS NOT NULL AND profilePicture != ''
             ORDER BY isPrimary DESC, provider ASC",
            [$userID],
            'i'
        );

        $this->assertIsArray($rows, 'query must resolve without a fatal SQL error');
        $this->assertCount(1, $rows, 'only the row WITH a profilePicture should be returned');
        $this->assertSame('google', $rows[0]['provider']);
    }

    // ========================================================================
    // ✅ security.php / activity.php — tblActivityLog.createdAt + success
    // ========================================================================

    /**
     * security.php's "recent logins" query and activity.php's default listing
     * both `ORDER BY createdAt` — tblActivityLog has no `loggedAt` column, so
     * the pre-fix `ORDER BY loggedAt` fataled unconditionally. Proves the
     * fixed ORDER BY resolves and returns rows in the expected (most-recent-
     * first) order.
     */
    public function testActivityLogOrderByCreatedAtResolvesInDescendingOrder(): void
    {
        $userID = $this->createFixtureUser();

        $this->insertActivity($userID, 'login', '2026-01-01 10:00:00', 1);
        $this->insertActivity($userID, 'login', '2026-01-03 10:00:00', 1);
        $this->insertActivity($userID, 'login', '2026-01-02 10:00:00', 0);

        $rows = Database::fetchAll(
            "SELECT * FROM tblActivityLog
             WHERE userID = ? AND activityType = 'login'
             ORDER BY createdAt DESC
             LIMIT 5",
            [$userID],
            'i'
        );

        $this->assertCount(3, $rows);
        $this->assertSame('2026-01-03 10:00:00', $rows[0]['createdAt']);
        $this->assertSame('2026-01-02 10:00:00', $rows[1]['createdAt']);
        $this->assertSame('2026-01-01 10:00:00', $rows[2]['createdAt']);
    }

    /**
     * activity.php's stats query used `activityResult = 'failed'` — a column
     * that does not exist (real column: `success`, tinyint). This ran
     * UNCONDITIONALLY on every page load, so it was the actual 500 cause.
     * The fixed query (`success = 0`) must resolve and count correctly.
     */
    public function testActivityStatsFailedLoginsCountUsesSuccessColumn(): void
    {
        $userID = $this->createFixtureUser();

        $this->insertActivity($userID, 'login', '2026-01-01 10:00:00', 1);
        $this->insertActivity($userID, 'login', '2026-01-01 11:00:00', 0);
        $this->insertActivity($userID, 'login', '2026-01-01 12:00:00', 0);
        $this->insertActivity($userID, 'profile_update', '2026-01-01 13:00:00', 1);

        $stats = Database::fetchOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN activityType = 'login' AND success = 0 THEN 1 ELSE 0 END) as failed_logins
             FROM tblActivityLog WHERE userID = ?",
            [$userID],
            'i'
        );

        $this->assertNotNull($stats, 'stats query must resolve without a fatal SQL error');
        $this->assertSame('4', (string) $stats['total']);
        $this->assertSame('2', (string) $stats['failed_logins'], 'exactly the 2 login rows with success=0 must be counted');
    }

    /**
     * Direct insert into tblActivityLog with an explicit createdAt/success,
     * bypassing ActivityLogger::log() (which always writes success=TRUE) so
     * this suite can construct both successful AND failed fixture rows.
     */
    private function insertActivity(int $userID, string $activityType, string $createdAt, int $success): void
    {
        Database::query(
            "INSERT INTO tblActivityLog (userID, activityType, activityCategory, severity, description, success, createdAt)
             VALUES (?, ?, 'auth', 'info', 'B066 fixture activity', ?, ?)",
            [$userID, $activityType, $success, $createdAt],
            'isis'
        );
    }

    // ========================================================================
    // ✅ index.php — tblUserOAuthTokens (not tblOAuthTokens)
    // ========================================================================

    /**
     * index.php's "connected accounts" stat used to count from
     * `tblOAuthTokens`, a table that does not exist at all — every load
     * fataled with "Table ... doesn't exist". The real table is
     * `tblUserOAuthTokens` (which DOES have isActive, unlike tblOAuthAccounts).
     */
    public function testIndexPageUserOAuthTokensCountQueryResolvesAndFiltersByIsActive(): void
    {
        $userID = $this->createFixtureUser();

        Database::query(
            "INSERT INTO tblUserOAuthTokens (userID, provider, accessToken, tokenType, mailboxEmail, isActive, createdAt)
             VALUES (?, 'microsoft_graph', 'tok-1', 'Bearer', 'mailbox1@example.com', 1, NOW())",
            [$userID],
            'i'
        );
        Database::query(
            "INSERT INTO tblUserOAuthTokens (userID, provider, accessToken, tokenType, mailboxEmail, isActive, createdAt)
             VALUES (?, 'gmail_api', 'tok-2', 'Bearer', 'mailbox2@example.com', 0, NOW())",
            [$userID],
            'i'
        );

        $result = Database::fetchOne(
            "SELECT COUNT(*) as count FROM tblUserOAuthTokens WHERE userID = ? AND isActive = 1",
            [$userID],
            'i'
        );

        $this->assertNotNull($result);
        $this->assertSame('1', (string) $result['count'], 'only the isActive=1 token row should be counted');
    }

    // ========================================================================
    // ✅ connected-accounts.php — tblOAuthAccounts retarget
    // ========================================================================

    /**
     * connected-accounts.php used to target `tblUserLinkedAccounts` (does not
     * exist at all) with an `accountID` PK and `createdAt` ordering column.
     * The real store is `tblOAuthAccounts`, whose PK is `oauthAccountID` and
     * whose "linked" timestamp column is `linkedAt`.
     */
    public function testConnectedAccountsPageOAuthAccountsQueryResolvesWithRealColumns(): void
    {
        $userID = $this->createFixtureUser();

        $olderID = Database::insert(
            "INSERT INTO tblOAuthAccounts (userID, provider, providerUserID, email, isPrimary, linkedAt)
             VALUES (?, 'github', 'gh-1', 'gh@example.com', 0, '2026-01-01 00:00:00')",
            [$userID],
            'i'
        );
        $newerID = Database::insert(
            "INSERT INTO tblOAuthAccounts (userID, provider, providerUserID, email, isPrimary, linkedAt)
             VALUES (?, 'google', 'g-1', 'g@example.com', 1, '2026-02-01 00:00:00')",
            [$userID],
            'i'
        );

        $rows = Database::fetchAll(
            "SELECT * FROM tblOAuthAccounts
             WHERE userID = ?
             ORDER BY isPrimary DESC, linkedAt DESC",
            [$userID],
            'i'
        );

        $this->assertCount(2, $rows);
        $this->assertArrayHasKey('oauthAccountID', $rows[0], 'the real PK column name must be present');
        $this->assertSame($newerID, (int) $rows[0]['oauthAccountID'], 'isPrimary=1 row must sort first regardless of linkedAt');
        $this->assertSame($olderID, (int) $rows[1]['oauthAccountID']);

        // 🔍 The unlink/set_primary handlers' ownership-check SELECT must also resolve.
        $existing = Database::fetchOne(
            "SELECT oauthAccountID FROM tblOAuthAccounts WHERE userID = ? AND provider = ? AND providerUserID = ?",
            [$userID, 'github', 'gh-1'],
            'iss'
        );
        $this->assertNotNull($existing);
        $this->assertSame($olderID, (int) $existing['oauthAccountID']);
    }

    // ========================================================================
    // ✅ privacy.php — tblUserPreferences EAV round-trip
    // ========================================================================

    /**
     * privacy.php used to read/write tblUserPreferences as a WIDE table
     * (one column per preference) — the real schema is a generic per-user
     * key/value store. Proves the fixed upsert-per-key write, and the
     * fixed "load all privacy.* rows into a short-name map" read, round-trip
     * correctly (mirrors AvatarService's own EAV usage of this same table).
     */
    public function testPrivacyPagePreferencesEavUpsertAndReadRoundTrip(): void
    {
        $userID = $this->createFixtureUser();

        // 💾 Upsert, exactly like savePrivacyPreference() does.
        $upsert = function (int $userID, string $key, string $value): void {
            Database::query(
                "INSERT INTO tblUserPreferences (userID, preferenceKey, preferenceValue)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE preferenceValue = ?, updatedAt = CURRENT_TIMESTAMP",
                [$userID, $key, $value, $value],
                'isss'
            );
        };

        $upsert($userID, 'privacy.profileVisibility', 'public');
        $upsert($userID, 'privacy.marketingEmails', '1');
        // Also seed an UNRELATED preference key (as AvatarService would) to
        // prove the 'privacy.%' LIKE filter does not leak other features' keys.
        Database::query(
            "INSERT INTO tblUserPreferences (userID, preferenceKey, preferenceValue) VALUES (?, 'avatar.fallback_priority', '[\"gravatar\"]')",
            [$userID],
            'i'
        );

        // 🔄 Re-upsert profileVisibility — proves ON DUPLICATE KEY UPDATE works
        // (requires a real UNIQUE KEY on (userID, preferenceKey)).
        $upsert($userID, 'privacy.profileVisibility', 'private');

        $rows = Database::fetchAll(
            "SELECT preferenceKey, preferenceValue FROM tblUserPreferences
             WHERE userID = ? AND preferenceKey LIKE 'privacy.%'",
            [$userID],
            'i'
        );

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[$row['preferenceKey']] = $row['preferenceValue'];
        }

        $this->assertCount(2, $rows, 'only the 2 privacy.* rows must be returned, not the unrelated avatar.* row');
        $this->assertSame('private', $byKey['privacy.profileVisibility'], 'the second upsert must have overwritten the first (ON DUPLICATE KEY UPDATE)');
        $this->assertSame('1', $byKey['privacy.marketingEmails']);
    }

    // ========================================================================
    // ✅ privacy.php — tblOAuthConsents / tblOAuthClients (was tblAPIKeys)
    // ========================================================================

    /**
     * privacy.php's "Third-Party App Access" section used to query
     * `tblAPIKeys WHERE userID = ?` — tblAPIKeys has NO userID column (API
     * keys belong to a partner, not an individual user); this fataled on
     * every load. Retargeted to tblOAuthConsents/tblOAuthClients (the real
     * per-user, revocable OAuth-client grant model). Proves the fixed query
     * resolves, excludes first-party clients and revoked consents.
     */
    public function testPrivacyPageThirdPartyAppsConsentsQueryResolvesAndFilters(): void
    {
        $userID = $this->createFixtureUser();

        $thirdPartyClientID = $this->createOAuthClient(false);
        $firstPartyClientID = $this->createOAuthClient(true);

        // ✅ Active, third-party — MUST be returned.
        Database::insert(
            "INSERT INTO tblOAuthConsents (userID, clientID, scope, grantedAt, revokedAt)
             VALUES (?, ?, 'openid profile', NOW(), NULL)",
            [$userID, $thirdPartyClientID],
            'ii'
        );
        // 🚫 Revoked — must NOT be returned.
        $revokedClientID = $this->createOAuthClient(false);
        Database::insert(
            "INSERT INTO tblOAuthConsents (userID, clientID, scope, grantedAt, revokedAt)
             VALUES (?, ?, 'openid', NOW(), NOW())",
            [$userID, $revokedClientID],
            'ii'
        );
        // 🚫 First-party — must NOT be returned ("Third-Party App Access" only).
        Database::insert(
            "INSERT INTO tblOAuthConsents (userID, clientID, scope, grantedAt, revokedAt)
             VALUES (?, ?, 'openid', NOW(), NULL)",
            [$userID, $firstPartyClientID],
            'ii'
        );

        $apps = Database::fetchAll(
            "SELECT c.consentID, c.clientID, c.scope, c.grantedAt,
                    cl.clientName, cl.logoURI
             FROM tblOAuthConsents c
             INNER JOIN tblOAuthClients cl ON cl.clientID = c.clientID
             WHERE c.userID = ? AND c.revokedAt IS NULL AND cl.isFirstParty = 0
             ORDER BY c.grantedAt DESC",
            [$userID],
            'i'
        );

        $this->assertCount(1, $apps, 'only the active, third-party consent must be returned');
        $this->assertSame($thirdPartyClientID, (int) $apps[0]['clientID']);
        $this->assertArrayHasKey('clientName', $apps[0]);

        // 🗑️ Revoke handler's ownership-check SELECT must also resolve.
        $consent = Database::fetchOne(
            "SELECT c.consentID, cl.clientName
             FROM tblOAuthConsents c
             INNER JOIN tblOAuthClients cl ON cl.clientID = c.clientID
             WHERE c.consentID = ? AND c.userID = ? AND c.revokedAt IS NULL",
            [$apps[0]['consentID'], $userID],
            'ii'
        );
        $this->assertNotNull($consent);
    }

    // ========================================================================
    // ✅ usage.php — tblSubscriptions.subscriptionStatus (was `status`)
    // ========================================================================

    /**
     * usage.php's active-subscription lookup used `WHERE status = 'active'`
     * — tblSubscriptions has no `status` column (real column:
     * `subscriptionStatus`). Fataled with "Unknown column 'status'" on every
     * load. Proves the fixed filter resolves and picks only the active row.
     */
    public function testUsagePageSubscriptionStatusFilterResolves(): void
    {
        $userID = $this->createFixtureUser();
        $tierID = $this->createSubscriptionTier();

        Database::insert(
            "INSERT INTO tblSubscriptions (userID, tierID, subscriptionStatus, billingMode, startDate, currentPeriodStart, currentPeriodEnd, createdAt)
             VALUES (?, ?, 'cancelled', 'fixed', '2025-12-01', '2025-12-01', '2025-12-31', '2025-12-01 00:00:00')",
            [$userID, $tierID],
            'ii'
        );
        $activeID = Database::insert(
            "INSERT INTO tblSubscriptions (userID, tierID, subscriptionStatus, billingMode, startDate, currentPeriodStart, currentPeriodEnd, createdAt)
             VALUES (?, ?, 'active', 'fixed', '2026-01-01', '2026-01-01', '2026-01-31', '2026-01-01 00:00:00')",
            [$userID, $tierID],
            'ii'
        );

        $subscription = Database::fetchOne(
            "SELECT * FROM tblSubscriptions
             WHERE userID = ? AND subscriptionStatus = 'active'
             ORDER BY createdAt DESC
             LIMIT 1",
            [$userID],
            'i'
        );

        $this->assertNotNull($subscription, 'query must resolve without a fatal SQL error');
        $this->assertSame($activeID, (int) $subscription['subscriptionID']);
    }

    // ========================================================================
    // ✅ usage.php — tblUsageRecords JOIN tblUsageMetrics/tblUsageRates
    // ========================================================================

    /**
     * usage.php's current-period-usage query used metricName/freeAllowance/
     * unitRate/unitLabel directly on tblUsageRecords — NONE of those columns
     * exist there (they live on tblUsageMetrics/tblUsageRates, joined via
     * metricID). Proves the fixed 3-table JOIN resolves and aggregates
     * correctly (billable quantity after the free allowance, subtotal at the
     * per-unit rate).
     */
    public function testUsagePageCurrentUsageJoinResolvesAndAggregatesCorrectly(): void
    {
        $userID = $this->createFixtureUser();
        $tierID = $this->createSubscriptionTier();
        $metricID = Database::insert(
            "INSERT INTO tblUsageMetrics (metricCode, metricName, unitLabel, aggregationType, isActive, createdAt)
             VALUES ('api_calls', 'API Calls', 'calls', 'sum', 1, NOW())"
        );
        Database::insert(
            "INSERT INTO tblUsageRates (metricID, tierID, tierType, ratePerUnit, freeAllowance, currency, isActive, effectiveFrom)
             VALUES (?, ?, 'global', 0.0100, 100, 'GBP', 1, '2025-01-01')",
            [$metricID, $tierID],
            'ii'
        );

        Database::query(
            "INSERT INTO tblUsageRecords (userID, metricID, quantity, recordedAt, billingPeriodStart, billingPeriodEnd)
             VALUES (?, ?, 250, '2026-01-15 12:00:00', '2026-01-01', '2026-01-31')",
            [$userID, $metricID],
            'ii'
        );

        $rows = Database::fetchAll(
            "SELECT
                m.metricName,
                SUM(ur.quantity) AS totalUsage,
                COALESCE(MAX(r.freeAllowance), 0) AS freeAllowance,
                GREATEST(SUM(ur.quantity) - COALESCE(MAX(r.freeAllowance), 0), 0) AS billableQty,
                COALESCE(MAX(r.ratePerUnit), 0) AS unitRate,
                GREATEST(SUM(ur.quantity) - COALESCE(MAX(r.freeAllowance), 0), 0) * COALESCE(MAX(r.ratePerUnit), 0) AS subtotal,
                MAX(m.unitLabel) AS unitLabel
             FROM tblUsageRecords ur
             INNER JOIN tblUsageMetrics m ON m.metricID = ur.metricID
             LEFT JOIN tblUsageRates r
                    ON r.metricID = ur.metricID
                   AND r.isActive = 1
                   AND r.tierID = ?
                   AND r.tierType = ?
                   AND r.effectiveFrom <= CURDATE()
                   AND (r.effectiveUntil IS NULL OR r.effectiveUntil >= CURDATE())
             WHERE ur.userID = ?
               AND ur.recordedAt >= ?
               AND ur.recordedAt <= ?
             GROUP BY m.metricName
             ORDER BY m.metricName ASC",
            [$tierID, 'global', $userID, '2026-01-01', '2026-01-31'],
            'isiss'
        );

        $this->assertCount(1, $rows);
        $this->assertSame('API Calls', $rows[0]['metricName']);
        $this->assertEqualsWithDelta(250.0, (float) $rows[0]['totalUsage'], 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $rows[0]['freeAllowance'], 0.0001);
        $this->assertEqualsWithDelta(150.0, (float) $rows[0]['billableQty'], 0.0001, '250 used - 100 free allowance = 150 billable');
        $this->assertEqualsWithDelta(1.5, (float) $rows[0]['subtotal'], 0.0001, '150 billable * 0.01/unit = 1.50');
    }

    // ========================================================================
    // ✅ usage.php — tblUsageBillingSummary.billingPeriodEnd (was `periodEnd`)
    // ========================================================================

    public function testUsagePageBillingHistoryOrderByBillingPeriodEndResolves(): void
    {
        $userID = $this->createFixtureUser();
        $tierID = $this->createSubscriptionTier();
        $subscriptionID = Database::insert(
            "INSERT INTO tblSubscriptions (userID, tierID, subscriptionStatus, billingMode, startDate, currentPeriodStart, currentPeriodEnd, createdAt)
             VALUES (?, ?, 'active', 'fixed', '2026-01-01', '2026-01-01', '2026-01-31', NOW())",
            [$userID, $tierID],
            'ii'
        );

        Database::query(
            "INSERT INTO tblUsageBillingSummary (subscriptionID, userID, billingPeriodStart, billingPeriodEnd, billingMode, totalUsageCost, tierFixedPrice, cappedAmount, savingsAmount, currency, lineItems, status, calculatedAt)
             VALUES (?, ?, '2025-12-01', '2025-12-31', 'fixed', 5.00, 10.00, 5.00, 0.00, 'GBP', '[]', 'paid', NOW())",
            [$subscriptionID, $userID],
            'ii'
        );
        Database::query(
            "INSERT INTO tblUsageBillingSummary (subscriptionID, userID, billingPeriodStart, billingPeriodEnd, billingMode, totalUsageCost, tierFixedPrice, cappedAmount, savingsAmount, currency, lineItems, status, calculatedAt)
             VALUES (?, ?, '2026-01-01', '2026-01-31', 'fixed', 8.00, 10.00, 8.00, 0.00, 'GBP', '[]', 'pending', NOW())",
            [$subscriptionID, $userID],
            'ii'
        );

        $history = Database::fetchAll(
            "SELECT * FROM tblUsageBillingSummary
             WHERE userID = ?
             ORDER BY billingPeriodEnd DESC
             LIMIT 12",
            [$userID],
            'i'
        );

        $this->assertCount(2, $history);
        $this->assertSame('2026-01-31', (string) $history[0]['billingPeriodEnd'], 'most recent billing period must sort first');
    }

    private function createSubscriptionTier(): int
    {
        $suffix = bin2hex(random_bytes(4));

        return Database::insert(
            "INSERT INTO tblSubscriptionTiers (tierName, tierSlug, monthlyPrice, yearlyPrice, currency, features, isActive, isDefault, createdAt)
             VALUES (?, ?, 10.00, 100.00, 'GBP', '[]', 1, 0, NOW())",
            ['B066 Tier ' . $suffix, 'b066-tier-' . $suffix],
            'ss'
        );
    }

    // ========================================================================
    // ✅ admin/email-webhooks.php — tblErrorLog real columns
    // ========================================================================

    /**
     * admin/email-webhooks.php's getWebhookLogs()/getWebhookStatistics()
     * queried tblErrorLog.contextData/errorContext/loggedAt/errorSeverity —
     * NONE of those columns exist (real columns: context/errorType/
     * createdAt/severity). Fataled on every load once past the missing-
     * admin-header.php fatal. Proves the fixed queries resolve and the
     * JSON_EXTRACT()-based provider grouping works.
     */
    public function testEmailWebhooksLogsAndStatisticsQueriesResolve(): void
    {
        $emailID = Database::insert(
            "INSERT INTO tblEmailQueue (recipientEmail, subject, bodyText, fromEmail, status, createdAt)
             VALUES ('recipient@example.com', 'Test Subject', 'body', 'from@example.com', 'sent', NOW())"
        );

        Database::query(
            "INSERT INTO tblErrorLog (errorType, errorMessage, severity, context, createdAt)
             VALUES ('email_webhook', 'Webhook received', 'info', ?, NOW())",
            [json_encode(['provider' => 'sendgrid', 'event' => 'delivered', 'email_id' => $emailID])],
            's'
        );
        Database::query(
            "INSERT INTO tblErrorLog (errorType, errorMessage, severity, context, createdAt)
             VALUES ('email_webhook', 'Webhook bounce', 'error', ?, NOW())",
            [json_encode(['provider' => 'sendgrid', 'event' => 'bounced'])],
            's'
        );
        // 🚫 Unrelated error row — must not appear in webhook-scoped results.
        Database::query(
            "INSERT INTO tblErrorLog (errorType, errorMessage, severity, createdAt)
             VALUES ('Exception', 'Unrelated error', 'error', NOW())"
        );

        $logs = Database::fetchAll(
            "SELECT
                el.*,
                eq.recipientEmail,
                eq.subject
             FROM tblErrorLog el
             LEFT JOIN tblEmailQueue eq ON JSON_EXTRACT(el.context, '$.email_id') = eq.emailID
             WHERE el.errorType = 'email_webhook'
             ORDER BY el.createdAt DESC
             LIMIT ?",
            [50],
            'i'
        );

        $this->assertCount(2, $logs, 'only the 2 email_webhook rows must be returned');
        // 🔍 Find the "delivered" row explicitly rather than assuming array
        // order — both fixture rows can land on the identical DATETIME-
        // granularity NOW() value, making their relative ORDER BY position
        // non-deterministic.
        $deliveredRow = null;
        foreach ($logs as $log) {
            if (str_contains((string) $log['context'], 'delivered')) {
                $deliveredRow = $log;
                break;
            }
        }
        $this->assertNotNull($deliveredRow, 'the delivered-event fixture row must be present');
        $this->assertSame('recipient@example.com', $deliveredRow['recipientEmail'], 'the delivered-event row must JOIN to its tblEmailQueue row via context->>email_id');

        $stats = Database::fetchAll(
            "SELECT
                JSON_EXTRACT(context, '$.provider') as provider,
                COUNT(*) as total_received,
                SUM(CASE WHEN severity = 'info' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN severity IN ('warning', 'error', 'critical') THEN 1 ELSE 0 END) as failed
             FROM tblErrorLog
             WHERE errorType = 'email_webhook'
             AND createdAt >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY JSON_EXTRACT(context, '$.provider')"
        );

        $this->assertCount(1, $stats, 'both fixture rows share the same provider, so exactly one group');
        $this->assertSame('2', (string) $stats[0]['total_received']);
        $this->assertSame('1', (string) $stats[0]['successful']);
        $this->assertSame('1', (string) $stats[0]['failed']);
    }
}
