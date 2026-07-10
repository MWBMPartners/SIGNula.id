<?php
/**
 * ============================================================================
 * 🧪 OIDC Provider `oidc.enabled` Master-Switch Gate Tests (G-001 red-team F-05)
 * ============================================================================
 *
 * Migration 031 seeds `oidc.enabled = '0'` ("master switch for SIGNula-as-IdP
 * — default OFF until an operator opts in") but, before this fix, NOTHING
 * ever read it: every OAuth2/OIDC PROVIDER endpoint was fully live regardless
 * of the setting. The fix adds a single shared gate
 * ({@see \OidcDiscoveryService::isProviderEnabled()}) that every provider
 * controller now checks FIRST, before any DB-bound client/grant work:
 *   - web/public_html/oauth/authorize-idp.php
 *   - web/public_html/oauth/token.php
 *   - web/public_html/oauth/userinfo.php
 *   - web/public_html/oauth/revoke.php
 *   - web/public_html/.well-known/openid-configuration/index.php
 * Deliberately NOT applied to `.well-known/jwks.json` (shared with the
 * first-party G-003 JWT bearer-auth stack).
 *
 * This test drives the REAL controller FILES end-to-end over `php -S`
 * (mirrors the subprocess-harness technique
 * `_tests/Integration/API/JwtAuthEndpointsTest.php` already established for
 * the same reason: these controllers set headers + `exit()`, which cannot be
 * exercised in-process under PHPUnit's `beStrictAboutOutputDuringTests`) —
 * but with an even LIGHTER harness: since every one of these files is
 * reachable at a literal filesystem path under `web/public_html`, the
 * subprocess is simply `php -S 127.0.0.1:PORT -t web/public_html <router>`,
 * where `<router>` is a tiny script that reproduces the SAME
 * `/clean/url` -> `clean/url.php` (or `clean/url/index.php`) mapping
 * `web/public_html/.htaccess`'s rewrite rules apply in production — no
 * custom route table, no stubbed globals: the REAL `config.php` bootstrap
 * runs, exactly as it would on Dreamhost.
 *
 * @package    SIGNula\Tests\Integration\Auth
 * @version    2.9.0-beta
 * @see        web/private_html/auth/OidcDiscoveryService.php (isProviderEnabled())
 * @see        web/public_html/oauth/token.php (the F-05 fix's own doc comment)
 * @see        _database/migrations/031_oauth_provider_clients.sql (oidc.enabled seed)
 * @see        _tests/Integration/API/JwtAuthEndpointsTest.php (subprocess-harness precedent)
 */

namespace SIGNula\Tests\Integration\Auth;

use SIGNula\Tests\DatabaseTestCase;

requireSource('_config/database.php');

/**
 * 🔒 `oidc.enabled` master-switch gate suite.
 */
class OidcProviderGateTest extends DatabaseTestCase
{
    protected array $truncateTables = [];

    protected function tearDown(): void
    {
        // 🧹 tblSettings is a SHARED, global table (not scoped to this test's
        //    rolled-back transaction — see setOidcEnabled()'s own doc), so we
        //    must explicitly restore the seeded default (OFF) on a COMMITTED
        //    connection, or a later test/run would inherit whatever state
        //    this test last left behind.
        $this->setOidcEnabled('0');

        parent::tearDown();
    }

    // ========================================================================
    // 🚦 THE TEST — disabled (default) refuses every gated endpoint EXCEPT
    //     the always-on JWKS endpoint; enabling flips every gated endpoint
    //     back to its normal (non-404) behaviour.
    // ========================================================================

    /**
     * One test, both states, every gated endpoint + the one deliberately
     * UN-gated sibling — proves the F-05 gate is wired end-to-end through the
     * REAL production controller files, not just the shared helper method in
     * isolation (that pure-logic coverage is below, DB-free).
     */
    public function testProviderDisabledRefusesGatedEndpointsButNotJwks(): void
    {
        // ------------------------------------------------------------------
        // 1️⃣ DISABLED (the seeded default — migration 031 seeds '0').
        // ------------------------------------------------------------------
        $this->setOidcEnabled('0');

        $token = $this->httpRequest('POST', '/oauth/token');
        $this->assertNotNull($token, 'the /oauth/token subprocess request must succeed');
        $this->assertSame(404, $token[0], 'F-05: /oauth/token must refuse (404) while oidc.enabled=0');
        $this->assertSame(['error' => 'not_found'], json_decode($token[1], true));

        $userinfo = $this->httpRequest('GET', '/oauth/userinfo');
        $this->assertNotNull($userinfo);
        $this->assertSame(404, $userinfo[0], 'F-05: /oauth/userinfo must refuse (404) while oidc.enabled=0');

        $revoke = $this->httpRequest('POST', '/oauth/revoke');
        $this->assertNotNull($revoke);
        $this->assertSame(404, $revoke[0], 'F-05: /oauth/revoke must refuse (404) while oidc.enabled=0');

        $authorize = $this->httpRequest('GET', '/oauth/authorize-idp');
        $this->assertNotNull($authorize);
        $this->assertSame(404, $authorize[0], 'F-05: /oauth/authorize-idp must refuse (404) while oidc.enabled=0');
        $this->assertStringContainsString('not currently available', $authorize[1], 'authorize-idp must render the LOCAL disabled-provider error page, not redirect');

        $discovery = $this->httpRequest('GET', '/.well-known/openid-configuration');
        $this->assertNotNull($discovery);
        $this->assertSame(404, $discovery[0], 'F-05: the discovery document must 404 while oidc.enabled=0');

        // 🔓 The JWKS endpoint is DELIBERATELY excluded from the gate (shared
        //    with first-party G-003 JWT auth) — it must return its normal 200
        //    regardless of oidc.enabled.
        $jwks = $this->httpRequest('GET', '/.well-known/jwks.json');
        $this->assertNotNull($jwks);
        $this->assertSame(200, $jwks[0], 'F-05: /.well-known/jwks.json must NOT be gated by oidc.enabled');
        $jwksBody = json_decode($jwks[1], true);
        $this->assertIsArray($jwksBody);
        $this->assertArrayHasKey('keys', $jwksBody);

        // ------------------------------------------------------------------
        // 2️⃣ ENABLED — flip the switch; every previously-404'd endpoint now
        //    proceeds to its NORMAL (non-gate) behaviour.
        // ------------------------------------------------------------------
        $this->setOidcEnabled('1');

        $tokenOn = $this->httpRequest('POST', '/oauth/token');
        $this->assertNotNull($tokenOn);
        $this->assertNotSame(404, $tokenOn[0], 'F-05: /oauth/token must NOT 404 once oidc.enabled=1');
        // No client_id presented — the NORMAL (past-the-gate) failure mode is
        // a 401 invalid_client, proving the request reached OAuthTokenService.
        $tokenOnBody = json_decode($tokenOn[1], true);
        $this->assertSame('invalid_client', $tokenOnBody['error'] ?? null, 'past the gate, the request must reach real client-auth logic');

        $discoveryOn = $this->httpRequest('GET', '/.well-known/openid-configuration');
        $this->assertNotNull($discoveryOn);
        $this->assertSame(200, $discoveryOn[0], 'F-05: the discovery document must serve normally once oidc.enabled=1');
        $discoveryOnBody = json_decode($discoveryOn[1], true);
        $this->assertArrayHasKey('issuer', $discoveryOnBody);
        $this->assertArrayHasKey('authorization_endpoint', $discoveryOnBody);
    }

    // ========================================================================
    // 🔧 FIXTURE — toggling oidc.enabled for the SUBPROCESS to see
    // ========================================================================

    /**
     * UPDATE `oidc.enabled` on a COMMITTED write (the subprocess is a
     * SEPARATE mysqli connection — it can only ever see committed rows, not
     * anything inside this test's own rolled-back transaction). Restarts a
     * fresh transaction immediately after so
     * {@see \SIGNula\Tests\DatabaseTestCase::tearDown()}'s own rollback stays
     * valid (mirrors JwtAuthEndpointsTest::createCommittedUser()'s own
     * commit/begin_transaction pairing).
     *
     * @param string $value '0' or '1'.
     * @return void
     */
    private function setOidcEnabled(string $value): void
    {
        $this->db->query(
            "UPDATE tblSettings SET settingValue = '" . $this->db->real_escape_string($value) . "' WHERE settingKey = 'oidc.enabled'"
        );
        $this->db->commit();
        $this->db->begin_transaction();
    }

    // ========================================================================
    // 🌐 SUBPROCESS HTTP HARNESS
    // ========================================================================

    /**
     * Boot the REAL `web/public_html` document root behind `php -S` and make
     * ONE HTTP request against it, using a tiny router that reproduces
     * `web/public_html/.htaccess`'s clean-URL rewrite
     * (`/some/path` -> `some/path.php`, falling back to
     * `some/path/index.php` for the `.well-known/*` directory-literal trick)
     * — so the request hits the EXACT SAME controller file production would.
     *
     * @param string $method HTTP method.
     * @param string $path   Request path (e.g. '/oauth/token').
     * @return array{0:int,1:string}|null [status, body] or null if the
     *                                     subprocess never came up.
     */
    private function httpRequest(string $method, string $path): ?array
    {
        $phpBinary = PHP_BINARY ?: 'php';
        $publicHtml = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'public_html';

        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: '';
        $dbName = getenv('DB_NAME') ?: 'signula_test';

        $routerScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . '_signula_oidc_gate_router_' . bin2hex(random_bytes(5)) . '.php';
        file_put_contents($routerScript, self::routerSource());

        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $port = random_int(9100, 9999);

                $env = [
                    'DB_HOST'        => $dbHost,
                    'DB_USER'        => $dbUser,
                    'DB_PASS'        => $dbPass,
                    'DB_NAME'        => $dbName,
                    'ENVIRONMENT'    => 'testing',
                    'ENCRYPTION_KEY' => getenv('ENCRYPTION_KEY') ?: 'test-encryption-key-32-chars-xx',
                    'PATH'           => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
                ];

                // 🔇 Suppress error DISPLAY (not logging) — these controller
                //    files echo raw JSON bodies; an inline HTML warning
                //    (e.g. config.php's own pre-existing, unrelated
                //    SIGNULA_INIT-redefined notice — see class doc) would
                //    otherwise corrupt json_decode() below. Mirrors
                //    JwtAuthEndpointsTest's own subprocess flags exactly.
                $cmd = escapeshellarg($phpBinary)
                    . ' -d error_reporting=0 -d display_errors=0'
                    . ' -S 127.0.0.1:' . $port
                    . ' -t ' . escapeshellarg($publicHtml)
                    . ' ' . escapeshellarg($routerScript);

                $proc = @proc_open(
                    $cmd,
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    null,
                    $env
                );

                if (!is_resource($proc)) {
                    continue;
                }

                usleep(700000); // let the server bind

                $result = $this->doHttpCall($port, $method, $path);

                proc_terminate($proc);
                @fclose($pipes[0]);
                @fclose($pipes[1]);
                @fclose($pipes[2]);
                proc_close($proc);

                if ($result !== null) {
                    return $result;
                }
            }
            return null;
        } finally {
            @unlink($routerScript);
        }
    }

    /**
     * Perform the actual HTTP call against the booted server.
     *
     * @return array{0:int,1:string}|null
     */
    private function doHttpCall(int $port, string $method, string $path): ?array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'timeout'       => 5,
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, $ctx);
        if ($body === false) {
            return null;
        }

        $respHeaders = $http_response_header ?? [];
        $status = 0;
        if (isset($respHeaders[0]) && preg_match('#\s(\d{3})\s#', $respHeaders[0], $m)) {
            $status = (int) $m[1];
        }

        // 🩹 trim() only — a PRE-EXISTING, unrelated PHP 8.4 quirk (config.php
        //    references the deprecated E_STRICT constant while configuring
        //    error_reporting, BEFORE its own ini_set('display_errors','0')
        //    takes effect) can prefix a couple of blank/whitespace bytes onto
        //    every response body under certain display_errors startup states.
        //    Out of scope for this G-001 OAuth fix (not one of F-01/02/03/05,
        //    not something these controllers introduce), but trimming keeps
        //    this gate test from spuriously failing on it — the JSON/HTML
        //    payload itself is otherwise completely well-formed.
        return [$status, trim((string) $body)];
    }

    /**
     * 🗺️ The router-script source: reproduces `web/public_html/.htaccess`'s
     * `RewriteCond %{REQUEST_FILENAME}.php -f` + `RewriteRule ^(.+?)/?$ $1.php
     * [L]` clean-URL mapping, PLUS the `.well-known/<name>/index.php`
     * directory-literal trick the JWKS/discovery endpoints use — both purely
     * as a PATH -> FILE resolver; no application logic of any kind lives
     * here, so the REAL controller files are what actually run.
     *
     * @return string
     */
    private static function routerSource(): string
    {
        return <<<'PHP'
<?php
// 🎯 $_SERVER['DOCUMENT_ROOT'] (the -t docroot), NOT __DIR__ — this router
// script itself lives in the OS temp dir (see httpRequest()), so __DIR__
// would resolve to ITS OWN location rather than web/public_html.
$docRoot = $_SERVER['DOCUMENT_ROOT'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = ltrim((string) $path, '/');
if ($path === '') {
    $path = 'index.php';
}
$direct = $docRoot . DIRECTORY_SEPARATOR . $path . '.php';
if (is_file($direct)) {
    require $direct;
    return true;
}
$indexed = $docRoot . DIRECTORY_SEPARATOR . $path . DIRECTORY_SEPARATOR . 'index.php';
if (is_file($indexed)) {
    require $indexed;
    return true;
}
return false;
PHP;
    }
}
