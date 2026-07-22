<?php
/**
 * ============================================================================
 * 🧪 getClientIP() Trusted-Proxy / Spoof-Defeat Tests (B-061)
 * ============================================================================
 *
 * Proves the B-061 fix in web/_config/config.php:
 *
 *   - DEFAULT (security.trusted_proxies EMPTY — the seeded default from
 *     migration 034, and the correct posture for this project's real
 *     deployment target: Dreamhost shared hosting, direct Apache, no
 *     reverse proxy in front): getClientIP() returns REMOTE_ADDR ONLY.
 *     A forged X-Forwarded-For / CF-Connecting-IP header is completely
 *     ignored, even though the OLD implementation would have trusted it.
 *   - A private/dev REMOTE_ADDR is still returned as-is in the default
 *     path (never discarded for being private/reserved — only headers
 *     resolved via the trusted-proxy path are filtered that way).
 *   - When REMOTE_ADDR (the immediate TCP peer) DOES match a configured
 *     `security.trusted_proxies` CIDR entry, getClientIP() walks
 *     X-Forwarded-For from the RIGHT, skips every hop that itself matches
 *     a trusted proxy, and returns the first hop that doesn't — the
 *     standard trusted-proxy-chain resolution.
 *   - A malformed/empty X-Forwarded-For on an otherwise-trusted peer fails
 *     SAFE back to REMOTE_ADDR (never throws, never guesses).
 *   - The same chain-walk works for IPv6 CIDRs, not just IPv4.
 *   - ipInCidr()/ipInAnyCidr() (the from-scratch CIDR matcher this fix
 *     introduces) correctly handle bit-boundary masks, bare-IP-as-/32,
 *     0.0.0.0/0, IPv4/IPv6 family mismatches, and malformed entries
 *     (skipped, never fatal).
 *
 * Why an isolated `php` SUBPROCESS rather than requiring config.php
 * in-process (mirrors _tests/Integration/Config/SanitizeRedirectUrlTest.php
 * and LoadSettingsResilienceTest.php exactly — see their header notes for
 * the full rationale):
 *   - config.php unconditionally `define('SIGNULA_INIT', true)` and
 *     unconditionally defines getSetting()/getClientIP()/etc. — bootstrap.php
 *     already defines STUB versions of both process-wide (its getClientIP()
 *     stub just returns REMOTE_ADDR unconditionally, and its getSetting()
 *     stub reads from $GLOBALS['test_settings'], never the database) — so
 *     requiring config.php in-process would fatal with "Cannot redeclare".
 *   A short-lived, isolated subprocess sidesteps this while still exercising
 *   the REAL, unmodified getClientIP() (and its real getTrustedProxyCidrs()/
 *   resolveForwardedClientIP()/ipInCidr()/ipInAnyCidr() helpers) against a
 *   real bootstrap + a real `security.trusted_proxies` row in the test
 *   database — no reimplementation/duplication of the logic under test.
 *
 * @package    SIGNula\Tests\Integration\Config
 * @version    2.9.0-beta
 * @see        web/_config/config.php (getClientIP(), getTrustedProxyCidrs(), resolveForwardedClientIP(), ipInCidr(), ipInAnyCidr())
 * @see        _database/migrations/034_trusted_proxies_setting.sql (seeds security.trusted_proxies = '')
 * @see        _tests/Integration/Config/SanitizeRedirectUrlTest.php (the subprocess pattern this mirrors)
 */

namespace SIGNula\Tests\Integration\Config;

use SIGNula\Tests\DatabaseTestCase;
use RuntimeException;

/**
 * getClientIP() trusted-proxy / spoof-defeat test suite.
 */
class GetClientIpTrustedProxyTest extends DatabaseTestCase
{
    /**
     * ⚠️ Do NOT use transaction rollback here.
     *
     * The subprocess spawned below opens its OWN, SEPARATE mysqli connection
     * to signula_test — it cannot see an UPDATE made on $this->db's
     * connection unless that UPDATE is actually committed. `security.
     * trusted_proxies` is a real, migration-seeded settings row (not test
     * fixture data this suite owns outright), so it's temporarily
     * overwritten via a committed UPDATE and explicitly restored to its
     * original value in tearDown() — mirrors LoadSettingsResilienceTest's
     * own note on why useTransactions = false here.
     */
    protected bool $useTransactions = false;

    private const SETTING_KEY = 'security.trusted_proxies';

    /**
     * 📸 The row's value as found at the start of the test, so tearDown()
     * can put it back exactly (normally '' per migration 034, but this
     * suite must not assume that and must not permanently mutate shared
     * state other integration tests may depend on).
     */
    private ?string $originalTrustedProxiesValue = null;

    protected function setUp(): void
    {
        parent::setUp();

        $result = $this->db->query(
            "SELECT settingValue FROM tblSettings WHERE settingKey = '" . self::SETTING_KEY . "' LIMIT 1"
        );
        $row = $result ? $result->fetch_assoc() : null;
        $this->originalTrustedProxiesValue = $row['settingValue'] ?? '';

        // 🌱 Guarantee the row exists even if migration 034 somehow hasn't
        // run against this test DB yet (defensive — setup_test_db.sh always
        // applies it, but a stale test DB shouldn't hard-fail with a SQL
        // error on the UPDATE below finding zero rows).
        if ($row === null) {
            $this->db->query(
                "INSERT INTO tblSettings (settingKey, settingValue, settingType, settingCategory, isSensitive, isEditable)
                 VALUES ('" . self::SETTING_KEY . "', '', 'string', 'security', 0, 1)"
            );
            $this->originalTrustedProxiesValue = '';
        }
    }

    protected function tearDown(): void
    {
        $stmt = $this->db->prepare("UPDATE tblSettings SET settingValue = ? WHERE settingKey = ?");
        $key = self::SETTING_KEY;
        $stmt->bind_param('ss', $this->originalTrustedProxiesValue, $key);
        $stmt->execute();
        $stmt->close();

        parent::tearDown();
    }

    /**
     * Set the `security.trusted_proxies` row to a specific value (committed,
     * visible to the subprocess's own connection).
     */
    private function setTrustedProxiesSetting(string $value): void
    {
        $stmt = $this->db->prepare("UPDATE tblSettings SET settingValue = ? WHERE settingKey = ?");
        $key = self::SETTING_KEY;
        $stmt->bind_param('ss', $value, $key);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Run a small harness script in an isolated `php` subprocess that
     * requires the REAL config.php (so getClientIP() and its helpers are
     * the genuine, unmodified production functions), applies a list of
     * named $_SERVER scenarios in sequence (each fully replacing the
     * relevant superglobal keys before calling getClientIP()), and returns
     * every result as a JSON map via a dedicated result file (never stdout —
     * config.php's own top-level code can print PHP notices on stdout
     * before display_errors is lowered, which would otherwise pollute a
     * stdout-parsing contract).
     *
     * @param array<string, array<string, string>> $scenarios scenario name => $_SERVER overrides
     *                                                          (only REMOTE_ADDR / HTTP_X_FORWARDED_FOR /
     *                                                          HTTP_CF_CONNECTING_IP are meaningful here;
     *                                                          each scenario starts from a clean slate for
     *                                                          those three keys)
     * @return array<string, string> scenario name => getClientIP() result
     */
    private function runGetClientIpScenarios(array $scenarios): array
    {
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: '';
        $dbName = getenv('DB_NAME') ?: 'signula_test';
        $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'test-encryption-key-32-chars-xx';

        $configPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

        $resultPath = tempnam(sys_get_temp_dir(), 'signula_getclientip_result_');
        if ($resultPath === false) {
            throw new RuntimeException('Could not create temp file for getClientIP() subprocess result');
        }
        $resultPathPhp = var_export($resultPath, true);
        $scenariosPhp = var_export($scenarios, true);

        // 🧪 Harness script — boots the REAL config.php ONCE (so
        // getTrustedProxyCidrs()'s static-cache reads the DB setting exactly
        // once, matching real single-request behaviour), then for each named
        // scenario: reset the 3 relevant $_SERVER keys, apply the scenario's
        // overrides, call getClientIP(), and record the result.
        $harnessScript = <<<PHP
<?php
putenv('DB_HOST={$dbHost}');
putenv('DB_USER={$dbUser}');
putenv('DB_PASS={$dbPass}');
putenv('DB_NAME={$dbName}');
putenv('ENCRYPTION_KEY={$encryptionKey}');

require '{$configPath}';

\$scenarios = {$scenariosPhp};
\$out = [];

foreach (\$scenarios as \$name => \$overrides) {
    unset(\$_SERVER['REMOTE_ADDR'], \$_SERVER['HTTP_X_FORWARDED_FOR'], \$_SERVER['HTTP_CF_CONNECTING_IP']);
    foreach (\$overrides as \$key => \$value) {
        \$_SERVER[\$key] = \$value;
    }
    \$out[\$name] = getClientIP();
}

file_put_contents({$resultPathPhp}, json_encode(\$out));
PHP;

        $scriptPath = tempnam(sys_get_temp_dir(), 'signula_getclientip_');
        if ($scriptPath === false) {
            @unlink($resultPath);
            throw new RuntimeException('Could not create temp file for getClientIP() subprocess harness');
        }
        file_put_contents($scriptPath, $harnessScript);

        try {
            $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            shell_exec($phpBinary . ' ' . escapeshellarg($scriptPath) . ' > /dev/null 2>&1');

            $this->assertFileExists(
                $resultPath,
                'getClientIP() subprocess harness did not produce a result file — it likely fataled before file_put_contents(). Re-run the harness script manually (without redirecting output) to see why.'
            );

            $rawResult = file_get_contents($resultPath);
        } finally {
            @unlink($scriptPath);
            @unlink($resultPath);
        }

        $decoded = json_decode((string) $rawResult, true);
        $this->assertIsArray(
            $decoded,
            'getClientIP() subprocess harness did not write valid JSON. Raw result file contents: ' . var_export($rawResult, true)
        );

        return $decoded;
    }

    /**
     * Same subprocess pattern, but for direct ipInCidr()/ipInAnyCidr() calls
     * against a battery of (ip, cidr-or-list) pairs — independent of the
     * `security.trusted_proxies` DB setting entirely, so this doesn't need
     * setTrustedProxiesSetting() at all.
     *
     * @param array<string, array{0: string, 1: string|array<string>, 2: 'single'|'any'}> $checks
     *        name => [ip, cidrOrList, 'single' for ipInCidr() / 'any' for ipInAnyCidr()]
     * @return array<string, bool>
     */
    private function runCidrMatcherChecks(array $checks): array
    {
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: '';
        $dbName = getenv('DB_NAME') ?: 'signula_test';
        $encryptionKey = getenv('ENCRYPTION_KEY') ?: 'test-encryption-key-32-chars-xx';

        $configPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR . 'config.php';

        $resultPath = tempnam(sys_get_temp_dir(), 'signula_cidr_result_');
        if ($resultPath === false) {
            throw new RuntimeException('Could not create temp file for CIDR-matcher subprocess result');
        }
        $resultPathPhp = var_export($resultPath, true);
        $checksPhp = var_export($checks, true);

        $harnessScript = <<<PHP
<?php
putenv('DB_HOST={$dbHost}');
putenv('DB_USER={$dbUser}');
putenv('DB_PASS={$dbPass}');
putenv('DB_NAME={$dbName}');
putenv('ENCRYPTION_KEY={$encryptionKey}');

require '{$configPath}';

\$checks = {$checksPhp};
\$out = [];

foreach (\$checks as \$name => \$args) {
    [\$ip, \$cidrOrList, \$mode] = \$args;
    \$out[\$name] = (\$mode === 'any') ? ipInAnyCidr(\$ip, \$cidrOrList) : ipInCidr(\$ip, \$cidrOrList);
}

file_put_contents({$resultPathPhp}, json_encode(\$out));
PHP;

        $scriptPath = tempnam(sys_get_temp_dir(), 'signula_cidr_');
        if ($scriptPath === false) {
            @unlink($resultPath);
            throw new RuntimeException('Could not create temp file for CIDR-matcher subprocess harness');
        }
        file_put_contents($scriptPath, $harnessScript);

        try {
            $phpBinary = PHP_BINARY !== '' ? PHP_BINARY : 'php';
            shell_exec($phpBinary . ' ' . escapeshellarg($scriptPath) . ' > /dev/null 2>&1');

            $this->assertFileExists(
                $resultPath,
                'CIDR-matcher subprocess harness did not produce a result file — it likely fataled before file_put_contents(). Re-run the harness script manually (without redirecting output) to see why.'
            );

            $rawResult = file_get_contents($resultPath);
        } finally {
            @unlink($scriptPath);
            @unlink($resultPath);
        }

        $decoded = json_decode((string) $rawResult, true);
        $this->assertIsArray(
            $decoded,
            'CIDR-matcher subprocess harness did not write valid JSON. Raw result file contents: ' . var_export($rawResult, true)
        );

        return $decoded;
    }

    // ========================================================================
    // ✅ DEFAULT PATH (security.trusted_proxies EMPTY) — spoofing defeated
    // ========================================================================

    /**
     * With the setting empty (the seeded default), a spoofed X-Forwarded-For
     * AND a spoofed CF-Connecting-IP are both completely ignored — only
     * REMOTE_ADDR is ever trusted. A private/dev REMOTE_ADDR is preserved
     * as-is (never discarded for being private — that filter only applies
     * to values resolved from forwarded headers on the trusted-proxy path).
     */
    public function testDefaultPathIgnoresSpoofedForwardedHeaders(): void
    {
        $this->setTrustedProxiesSetting(''); // explicit, even though this is the seeded default

        $results = $this->runGetClientIpScenarios([
            'xff_spoof' => [
                'REMOTE_ADDR' => '5.6.7.8',
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            ],
            'cf_spoof' => [
                'REMOTE_ADDR' => '5.6.7.8',
                'HTTP_CF_CONNECTING_IP' => '9.9.9.9',
            ],
            'both_spoofed_at_once' => [
                'REMOTE_ADDR' => '5.6.7.8',
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
                'HTTP_CF_CONNECTING_IP' => '9.9.9.9',
            ],
            'private_dev_remote_addr_preserved' => [
                'REMOTE_ADDR' => '10.1.2.3',
            ],
            'no_headers_at_all' => [
                'REMOTE_ADDR' => '203.0.113.55',
            ],
        ]);

        $this->assertSame('5.6.7.8', $results['xff_spoof'] ?? null, 'a spoofed X-Forwarded-For must be ignored when no trusted proxy is configured (B-061)');
        $this->assertSame('5.6.7.8', $results['cf_spoof'] ?? null, 'a spoofed CF-Connecting-IP must be ignored when no trusted proxy is configured (B-061)');
        $this->assertSame('5.6.7.8', $results['both_spoofed_at_once'] ?? null, 'both spoofed headers together must still be ignored');
        $this->assertSame('10.1.2.3', $results['private_dev_remote_addr_preserved'] ?? null, 'a private/dev REMOTE_ADDR must still be returned as-is (never filtered for being private)');
        $this->assertSame('203.0.113.55', $results['no_headers_at_all'] ?? null, 'REMOTE_ADDR alone (no forwarded headers present) must be returned unchanged');
    }

    // ========================================================================
    // ✅ TRUSTED-PROXY PATH — chain walk + fail-safe fallback
    // ========================================================================

    /**
     * When REMOTE_ADDR matches a configured trusted-proxy CIDR, getClientIP()
     * walks X-Forwarded-For from the right, skipping trusted hops, and
     * returns the first non-trusted (real client) hop. A malformed or empty
     * X-Forwarded-For on that same trusted peer fails safe back to
     * REMOTE_ADDR rather than guessing.
     */
    public function testTrustedProxyWalksForwardedChainAndFailsSafe(): void
    {
        // 🏗️ REMOTE_ADDR (10.0.0.5) and the injected internal hop (10.0.0.1)
        // both sit inside this trusted /8 — representing an internal
        // reverse-proxy/load-balancer chain. 1.2.3.4 is the genuine public
        // client and is NOT in this range, so it must be the address
        // returned.
        $this->setTrustedProxiesSetting('10.0.0.0/8');

        $results = $this->runGetClientIpScenarios([
            'xff_walk_skips_trusted_hops' => [
                'REMOTE_ADDR' => '10.0.0.5',
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 10.0.0.1',
            ],
            'malformed_xff_fails_safe' => [
                'REMOTE_ADDR' => '10.0.0.5',
                'HTTP_X_FORWARDED_FOR' => 'not-an-ip-address',
            ],
            'empty_xff_fails_safe' => [
                'REMOTE_ADDR' => '10.0.0.5',
                'HTTP_X_FORWARDED_FOR' => '',
            ],
            'xff_absent_entirely_fails_safe' => [
                'REMOTE_ADDR' => '10.0.0.5',
            ],
            'entire_chain_trusted_falls_back_to_remote_addr' => [
                // Every hop in XFF is itself inside the trusted /8 — there is
                // no untrusted hop to resolve to, so this must fail safe to
                // REMOTE_ADDR rather than returning a trusted proxy's own
                // address as if it were the client.
                'REMOTE_ADDR' => '10.0.0.5',
                'HTTP_X_FORWARDED_FOR' => '10.0.0.9, 10.0.0.1',
            ],
            'untrusted_peer_ignores_headers_even_with_setting_configured' => [
                // REMOTE_ADDR here is OUTSIDE the trusted 10.0.0.0/8 range —
                // even though a trusted-proxies list is configured, this
                // specific peer isn't on it, so forwarded headers must still
                // be ignored entirely (same as the fully-empty-setting case).
                'REMOTE_ADDR' => '198.51.100.9',
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            ],
        ]);

        $this->assertSame(
            '1.2.3.4',
            $results['xff_walk_skips_trusted_hops'] ?? null,
            'walking X-Forwarded-For from the right, skipping trusted hops, must resolve to the real (leftmost, non-trusted) client'
        );
        $this->assertSame('10.0.0.5', $results['malformed_xff_fails_safe'] ?? null, 'a malformed X-Forwarded-For on a trusted peer must fail safe to REMOTE_ADDR');
        $this->assertSame('10.0.0.5', $results['empty_xff_fails_safe'] ?? null, 'an empty X-Forwarded-For on a trusted peer must fail safe to REMOTE_ADDR');
        $this->assertSame('10.0.0.5', $results['xff_absent_entirely_fails_safe'] ?? null, 'no X-Forwarded-For at all on a trusted peer must fail safe to REMOTE_ADDR');
        $this->assertSame('10.0.0.5', $results['entire_chain_trusted_falls_back_to_remote_addr'] ?? null, 'an XFF chain made ENTIRELY of trusted-proxy hops must fail safe to REMOTE_ADDR, not return a proxy address as the client');
        $this->assertSame('198.51.100.9', $results['untrusted_peer_ignores_headers_even_with_setting_configured'] ?? null, 'a peer NOT on the trusted-proxies list must still be treated as untrusted, even while the setting is non-empty for OTHER peers');
    }

    /**
     * The same trusted-proxy chain-walk logic works for IPv6 CIDRs, not just
     * IPv4 — proves ipInCidr()'s inet_pton()-based matching handles the
     * 16-byte packed form correctly.
     */
    public function testTrustedProxyIpv6CidrMatchWorks(): void
    {
        // 2001:db8::/32 is the RFC 3849 documentation prefix — used here only
        // as the TRUSTED-PROXY range (never validated with
        // FILTER_FLAG_NO_RES_RANGE, so its "reserved" status doesn't matter
        // for matching). The actual resolved CLIENT address below is a real
        // public IPv6 address so it passes that later validation step.
        $this->setTrustedProxiesSetting('2001:db8::/32');

        $results = $this->runGetClientIpScenarios([
            'ipv6_walk_skips_trusted_hop' => [
                'REMOTE_ADDR' => '2001:db8::1',
                'HTTP_X_FORWARDED_FOR' => '2606:4700:4700::1111, 2001:db8::9',
            ],
            'ipv6_untrusted_peer_ignores_headers' => [
                // A public IPv6 peer, NOT inside 2001:db8::/32 — headers must
                // be ignored even though an IPv6 trusted range is configured.
                'REMOTE_ADDR' => '2606:4700:4700::9999',
                'HTTP_X_FORWARDED_FOR' => '2606:4700:4700::1111',
            ],
        ]);

        $this->assertSame(
            '2606:4700:4700::1111',
            $results['ipv6_walk_skips_trusted_hop'] ?? null,
            'an IPv6 X-Forwarded-For chain must resolve the same way as IPv4: skip trusted hops, return the first non-trusted one'
        );
        $this->assertSame(
            '2606:4700:4700::9999',
            $results['ipv6_untrusted_peer_ignores_headers'] ?? null,
            'an IPv6 peer outside the trusted range must still have forwarded headers ignored'
        );
    }

    // ========================================================================
    // ✅ ipInCidr() / ipInAnyCidr() — matcher correctness + malformed-entry safety
    // ========================================================================

    /**
     * Direct coverage of the from-scratch CIDR matcher: bit-boundary masks,
     * bare-IP-as-exact-match, 0.0.0.0/0 wildcard, IPv4/IPv6 family
     * mismatches (never match), and malformed entries (skipped safely,
     * never fatal — proven by mixing a garbage entry into a list alongside
     * a valid one and confirming the valid one still matches).
     */
    public function testIpInCidrMatcherCorrectness(): void
    {
        $checks = [
            // Non-byte-aligned mask boundary: 203.0.113.0/22 covers
            // 203.0.113.0 - 203.0.115.255 (mask splits mid-byte at bit 22).
            'nonaligned_mask_inside_range' => ['203.0.114.200', '203.0.112.0/22', 'single'],
            'nonaligned_mask_outside_range' => ['203.0.116.1', '203.0.112.0/22', 'single'],

            // Bare IP = exact match only (/32 semantics).
            'bare_ip_exact_match' => ['198.51.100.7', '198.51.100.7', 'single'],
            'bare_ip_no_match' => ['198.51.100.8', '198.51.100.7', 'single'],

            // 0.0.0.0/0 matches every IPv4 address.
            'wildcard_v4_matches_anything' => ['1.2.3.4', '0.0.0.0/0', 'single'],

            // IPv4 candidate against an IPv6 CIDR (and vice versa) must never match.
            'v4_against_v6_cidr_never_matches' => ['1.2.3.4', '2001:db8::/32', 'single'],
            'v6_against_v4_cidr_never_matches' => ['2001:db8::1', '10.0.0.0/8', 'single'],

            // Malformed CIDR entries must be REJECTED (skipped), not crash and not "match everything".
            'malformed_prefix_rejected' => ['10.0.0.5', '10.0.0.0/xyz', 'single'],
            'out_of_range_prefix_rejected' => ['10.0.0.5', '10.0.0.0/99', 'single'],
            'garbage_address_rejected' => ['10.0.0.5', 'not-an-ip/8', 'single'],
            'empty_entry_rejected' => ['10.0.0.5', '', 'single'],

            // ipInAnyCidr(): a malformed entry sitting alongside a valid one
            // must not prevent the valid one from matching.
            'any_cidr_skips_malformed_finds_valid' => ['10.0.0.5', ['not-a-cidr/abc', '10.0.0.0/24'], 'any'],
            'any_cidr_no_match_when_none_valid' => ['10.0.0.5', ['not-a-cidr/abc', '192.168.1.0/24'], 'any'],

            // IPv6 exact-match bare IP + a standard /64 boundary.
            'ipv6_bare_exact_match' => ['2001:db8::1', '2001:db8::1', 'single'],
            'ipv6_slash64_inside' => ['2001:db8:0:0:aaaa::1', '2001:db8::/64', 'single'],
            'ipv6_slash64_outside' => ['2001:db8:0:1::1', '2001:db8::/64', 'single'],
        ];

        $results = $this->runCidrMatcherChecks($checks);

        $this->assertTrue($results['nonaligned_mask_inside_range'] ?? null, '203.0.114.200 must match 203.0.112.0/22 (non-byte-aligned mask)');
        $this->assertFalse($results['nonaligned_mask_outside_range'] ?? null, '203.0.116.1 must NOT match 203.0.112.0/22');
        $this->assertTrue($results['bare_ip_exact_match'] ?? null, 'a bare IP entry must match the identical address');
        $this->assertFalse($results['bare_ip_no_match'] ?? null, 'a bare IP entry must NOT match any other address');
        $this->assertTrue($results['wildcard_v4_matches_anything'] ?? null, '0.0.0.0/0 must match any IPv4 address');
        $this->assertFalse($results['v4_against_v6_cidr_never_matches'] ?? null, 'an IPv4 address must never match an IPv6 CIDR');
        $this->assertFalse($results['v6_against_v4_cidr_never_matches'] ?? null, 'an IPv6 address must never match an IPv4 CIDR');
        $this->assertFalse($results['malformed_prefix_rejected'] ?? null, 'a non-numeric prefix must be rejected, not crash');
        $this->assertFalse($results['out_of_range_prefix_rejected'] ?? null, 'an out-of-range prefix (/99) must be rejected, not crash');
        $this->assertFalse($results['garbage_address_rejected'] ?? null, 'a syntactically invalid address in the CIDR entry must be rejected, not crash');
        $this->assertFalse($results['empty_entry_rejected'] ?? null, 'an empty CIDR entry must be rejected, not crash');
        $this->assertTrue($results['any_cidr_skips_malformed_finds_valid'] ?? null, 'ipInAnyCidr() must still find a valid match even when a malformed entry precedes it');
        $this->assertFalse($results['any_cidr_no_match_when_none_valid'] ?? null, 'ipInAnyCidr() must return false when no entry (malformed or otherwise) matches');
        $this->assertTrue($results['ipv6_bare_exact_match'] ?? null, 'a bare IPv6 entry must match the identical address');
        $this->assertTrue($results['ipv6_slash64_inside'] ?? null, 'an address inside a /64 must match');
        $this->assertFalse($results['ipv6_slash64_outside'] ?? null, 'an address outside a /64 must not match');
    }
}
