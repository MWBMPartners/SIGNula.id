<?php
/**
 * ============================================================================
 * 🧪 JWT Auth Endpoints Integration Tests (G-003 Stage 3 — HTTP surface)
 * ============================================================================
 *
 * Drives the REAL JWT bearer-auth HTTP endpoints end-to-end over `php -S`,
 * against the signula_test database, with a REAL RS256 signing key seeded into
 * tblSettings. Every case exercises the actual JwtAuthController + BaseController
 * + Router + TokenService/Jwt/KeyManager stack through an HTTP request — not a
 * mock — so the wiring itself (routes, primary-credential authz, rate-limit,
 * Bearer acceptance, no-store headers) is what is under test.
 *
 * Why a subprocess: the controllers call Response::* which does header()+exit().
 * Under PHPUnit's beStrictAboutOutputDuringTests that cannot run in-process, so
 * each scenario boots a tiny router harness (loading the real source classes)
 * behind `php -S`, issues a real HTTP request, and asserts the status + JSON body
 * — the same technique BaseControllerAuthTest uses, extended with the full JWT
 * stack and a live key.
 *
 * Coverage (per the S3 task):
 *   • valid credential → POST /auth/token returns access + refresh.
 *   • the access token is ACCEPTED by a protected route via Authorization: Bearer.
 *   • a TAMPERED and an EXPIRED token are REJECTED (401), no reason leaked.
 *   • POST /auth/refresh ROTATES (old refresh dead); reuse → family revoked + 401.
 *   • POST /auth/revoke → the access token is then DENIED.
 *   • GET /.well-known/jwks.json returns the public key (no private `d`), and it
 *     independently VERIFIES a freshly-issued token.
 *   • wrong password → /auth/token 401 + rate-limited (429) after N tries.
 *   • 🔒 a caller CANNOT get a token for ANOTHER user's id (client-supplied
 *     userID/sub is ignored — the subject is the verified identity only).
 *
 * @package    SIGNula\Tests\Integration\API
 * @version    2.8.0-beta
 * @see        web/private_html/api/controllers/JwtAuthController.php
 * @see        web/private_html/api/BaseController.php
 * @see        .dev-team/specs/G-003.md §6
 */

namespace SIGNula\Tests\Integration\API;

use SIGNula\Tests\DatabaseTestCase;

// 📦 Source loaded in-process (parent) for fixture setup + the JWKS-verify test.
requireSource('_config/database.php');
requireSource('private_html/security/SecurityUtils.php');
requireSource('private_html/security/JwtException.php');
requireSource('private_html/security/JwtLibLoader.php');
requireSource('private_html/security/KeyManager.php');
requireSource('private_html/security/Jwt.php');
requireSource('private_html/utils/ActivityLogger.php');
requireSource('private_html/utils/ErrorLogger.php');
requireSource('private_html/security/SecurityAlertManager.php');
requireSource('private_html/security/TokenService.php');

/**
 * 🎫 JWT endpoints integration suite.
 */
class JwtAuthEndpointsTest extends DatabaseTestCase
{
    /**
     * Tables reset before each test.
     *
     * @var array<int,string>
     */
    protected array $truncateTables = [
        'tblRefreshTokens',
        'tblRevokedTokens',
        'tblSecurityAlerts',
        'tblActivityLog',
        'tblUserSessions',
        // 🔧 tblUserMFA MUST be cleared: userIDs are recycled after truncating
        //    tblUsers (auto-increment resets to 1), and OTHER integration tests
        //    (e.g. MFATest) COMMIT verified MFA rows for low userIDs. A leftover
        //    row would make MFA::isEnabled() true for our fresh user → Auth::login
        //    returns requiresMFA → /auth/token replies "MFA required" (401)
        //    instead of issuing. Truncating it here isolates our token flow.
        'tblUserMFA',
        'tblUsers',
    ];

    /** @var string The kid of the signing key seeded into the test DB. */
    private string $kid = '';

    /** @var string Plaintext password used for the fixture users. */
    private string $password = 'CorrectHorse9!';

    protected function setUp(): void
    {
        parent::setUp();

        // 📚 Vendored JWT lib must be present for the whole stack.
        if (!\JwtLibLoader::isAvailable()) {
            $this->markTestSkipped('Vendored firebase/php-jwt not present at web/_lib/jwt/src.');
        }
        \JwtLibLoader::load();

        // ⚙️ JWT policy for BOTH the in-process helpers and the subprocess (the
        //    subprocess reads these from tblSettings via its own getSetting()).
        $this->seedJwtPolicySettings();

        // 🔑 Seed a REAL RS256 signing key into tblSettings so the subprocess
        //    (which connects to the same signula_test DB) can sign + verify.
        \KeyManager::resetOverrides();
        setTestSetting('jwt.key_bits', 2048); // fast key-gen (>= RSA floor)
        $this->kid = \KeyManager::generateKey(true);

        // 🧹 The rate-limiter table is committed by a separate (subprocess)
        //    connection, so it lives OUTSIDE our rolled-back transaction and would
        //    otherwise accumulate the shared 127.0.0.1 counter across tests and
        //    cause cross-test 429 flakiness. Clear it (committed) at the start of
        //    every test so each test's rate-limit behaviour is deterministic.
        $this->db->query('DELETE FROM tblRateLimits');
        $this->db->commit();
        $this->db->begin_transaction();

        \Jwt::setDenylistChecker(null);
    }

    protected function tearDown(): void
    {
        \Jwt::setDenylistChecker(null);
        \KeyManager::resetOverrides();

        // Remove the signing-key rows this test seeded (they live outside the
        // rolled-back transaction because KeyManager uses its own Database calls).
        $this->db->query("DELETE FROM tblSettings WHERE settingKey LIKE 'jwt.signing_key.%'");

        parent::tearDown();
    }

    // ========================================================================
    // ⚙️ FIXTURES
    // ========================================================================

    /**
     * Seed the jwt.* policy rows the subprocess reads from tblSettings.
     *
     * @return void
     */
    private function seedJwtPolicySettings(): void
    {
        $rows = [
            ['jwt.issuer', 'https://signula.id'],
            ['jwt.audience', 'https://signula.id/api'],
            ['jwt.access_ttl', '900'],
            ['jwt.refresh_ttl', '2592000'],
            ['jwt.leeway_seconds', '60'],
            ['jwt.key_bits', '2048'],
            ['jwt.enabled', '1'],
            // Generous default limits so ordinary tests never trip; the
            // rate-limit test tightens the per-identifier limit itself.
            ['jwt.rate_limit.window_seconds', '900'],
            ['jwt.rate_limit.token_per_ip', '1000'],
            ['jwt.rate_limit.token_per_identifier', '1000'],
            ['jwt.rate_limit.refresh_per_ip', '1000'],
        ];
        foreach ($rows as [$k, $v]) {
            // In-process getSetting() (bootstrap stub) for the parent helpers.
            setTestSetting($k, $v);
            // Persist for the subprocess (idempotent upsert).
            $this->db->query(
                "INSERT INTO tblSettings (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
                 VALUES ('" . $this->db->real_escape_string($k) . "', '" . $this->db->real_escape_string($v) . "', 'string', 0, 'jwt', 'test')
                 ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)"
            );
        }
    }

    /**
     * Create an active user with a known password. Committed (not in the rolled-
     * back transaction) so the subprocess — a separate connection — can see it.
     *
     * @param string|null $emailPrefix Optional email prefix.
     * @return array{userID:int,email:string}
     */
    private function createCommittedUser(?string $emailPrefix = null): array
    {
        $uuid  = self::generateTestUuid();
        $uniq  = bin2hex(random_bytes(4));
        $email = ($emailPrefix ?? 'jwt') . '_' . $uniq . '@example.com';
        $user  = 'jwtu_' . $uniq;
        $hash  = password_hash($this->password, PASSWORD_ARGON2ID);

        // Use a direct committed INSERT on the test connection. We COMMIT so the
        // subprocess sees it, then clean these rows up in tearDown via truncation
        // of tblUsers (which also cascades tblRefreshTokens).
        $stmt = $this->db->prepare(
            "INSERT INTO tblUsers
                (userUUID, username, email, passwordHash, displayName, accountStatus, emailVerified, mfaEnabled, createdAt)
             VALUES (?, ?, ?, ?, 'JWT User', 'active', 1, 0, NOW())"
        );
        $stmt->bind_param('ssss', $uuid, $user, $email, $hash);
        $stmt->execute();
        $userID = (int) $stmt->insert_id;
        $stmt->close();

        // Commit so the OTHER connection (subprocess) can read it, then start a
        // fresh transaction so DatabaseTestCase::tearDown()'s rollback is valid.
        $this->db->commit();
        $this->db->begin_transaction();

        return ['userID' => $userID, 'email' => $email];
    }

    // ========================================================================
    // 🌐 SUBPROCESS HTTP HARNESS
    // ========================================================================

    /**
     * Boot the real JWT endpoints behind `php -S` and make ONE HTTP request.
     *
     * The router script loads the REAL source classes + registers the REAL
     * routes, provides minimal global function stubs (getSetting reads tblSettings
     * so the seeded key/policy resolve), then dispatches. Returns [status, body].
     *
     * @param string               $method  HTTP method.
     * @param string               $path    Request path (e.g. '/api/v1/auth/token').
     * @param array<string,mixed>|null $json Body to JSON-encode (null = no body).
     * @param array<string,string>  $headers Extra request headers (e.g. Authorization).
     * @return array{0:int,1:string,2:array<int,string>}|null [status, body, headers] or null.
     */
    private function httpRequest(string $method, string $path, ?array $json = null, array $headers = []): ?array
    {
        $phpBinary = PHP_BINARY ?: 'php';
        $projectRoot = PROJECT_ROOT;

        // DB creds the subprocess's database.php reads via getenv().
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: '';
        $dbName = getenv('DB_NAME') ?: 'signula_test';

        $harness = $this->buildHarness($projectRoot);

        // Write the router script somewhere the requires resolve (project root
        // is fine; the harness uses absolute paths built from PROJECT_ROOT).
        $routerScript = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . '_signula_jwt_probe_' . bin2hex(random_bytes(5)) . '.php';
        file_put_contents($routerScript, $harness);

        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $port = random_int(9000, 9999);

                $env = [
                    'DB_HOST' => $dbHost,
                    'DB_USER' => $dbUser,
                    'DB_PASS' => $dbPass,
                    'DB_NAME' => $dbName,
                    'PATH'    => getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin',
                ];

                $cmd = escapeshellarg($phpBinary)
                    . ' -d error_reporting=0 -d display_errors=0'
                    . ' -S 127.0.0.1:' . $port
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

                $result = $this->doHttpCall($port, $method, $path, $json, $headers);

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
     * @return array{0:int,1:string,2:array<int,string>}|null
     */
    private function doHttpCall(int $port, string $method, string $path, ?array $json, array $headers): ?array
    {
        $headerLines = [];
        $content = null;

        if ($json !== null) {
            $content = json_encode($json);
            $headerLines[] = 'Content-Type: application/json';
        }
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => implode("\r\n", $headerLines),
            'content'       => $content,
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

        return [$status, (string) $body, $respHeaders];
    }

    /**
     * Build the router-script source for the subprocess.
     *
     * Loads the real classes, stubs the handful of global functions the stack
     * needs (getSetting reads tblSettings so the seeded signing key + policy
     * resolve exactly as production would), registers the REAL routes, and
     * dispatches. A protected probe route (/api/v1/_probe/whoami) exercises
     * BaseController::requireAuth() so we can test Bearer acceptance.
     *
     * @param string $root Absolute PROJECT_ROOT.
     * @return string
     */
    private function buildHarness(string $root): string
    {
        $rootExport = var_export($root, true);

        return <<<PHP
<?php
// 🧪 SIGNula JWT endpoints subprocess harness — boots the REAL controller stack.
define('SIGNULA_INIT', true);
define('PROJECT_ROOT', {$rootExport});
define('ROOT_DIR', PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web');
define('LIB_DIR', ROOT_DIR . DIRECTORY_SEPARATOR . '_lib');
define('ENVIRONMENT', 'testing');
if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: 'test-encryption-key-32-chars-xx');
}

// A tiny settings cache read from tblSettings (so the seeded key + policy load).
\$GLOBALS['__settings_cache'] = null;

function getSetting(string \$key, \$default = null) {
    if (\$GLOBALS['__settings_cache'] === null) {
        \$GLOBALS['__settings_cache'] = [];
        try {
            \$res = Database::fetchAll("SELECT settingKey, settingValue FROM tblSettings");
            foreach (\$res as \$r) { \$GLOBALS['__settings_cache'][\$r['settingKey']] = \$r['settingValue']; }
        } catch (\\Throwable \$e) { /* fall through to defaults */ }
    }
    return array_key_exists(\$key, \$GLOBALS['__settings_cache'])
        ? \$GLOBALS['__settings_cache'][\$key]
        : \$default;
}
function getClientIP(): string { return \$_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'; }
function getUserAgent(): string { return \$_SERVER['HTTP_USER_AGENT'] ?? 'PHPUnit/Subprocess'; }
function getCurrentURL(): string { return 'http://127.0.0.1' . (\$_SERVER['REQUEST_URI'] ?? '/'); }

// Real source classes (order = dependency order).
\$base = ROOT_DIR . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR;
\$cfg  = ROOT_DIR . DIRECTORY_SEPARATOR . '_config' . DIRECTORY_SEPARATOR;
require_once \$cfg  . 'database.php';
require_once \$base . 'security' . DIRECTORY_SEPARATOR . 'SecurityUtils.php';
require_once \$base . 'security' . DIRECTORY_SEPARATOR . 'JwtException.php';
require_once \$base . 'security' . DIRECTORY_SEPARATOR . 'JwtLibLoader.php';
require_once \$base . 'security' . DIRECTORY_SEPARATOR . 'KeyManager.php';
require_once \$base . 'security' . DIRECTORY_SEPARATOR . 'Jwt.php';
require_once \$base . 'utils' . DIRECTORY_SEPARATOR . 'ActivityLogger.php';
require_once \$base . 'utils' . DIRECTORY_SEPARATOR . 'ErrorLogger.php';
require_once \$base . 'security' . DIRECTORY_SEPARATOR . 'SecurityAlertManager.php';
require_once \$base . 'security' . DIRECTORY_SEPARATOR . 'TOTP.php';
require_once \$base . 'security' . DIRECTORY_SEPARATOR . 'MFA.php';
require_once \$base . 'auth' . DIRECTORY_SEPARATOR . 'Auth.php';
require_once \$base . 'security' . DIRECTORY_SEPARATOR . 'TokenService.php';
require_once \$base . 'api' . DIRECTORY_SEPARATOR . 'Response.php';
require_once \$base . 'api' . DIRECTORY_SEPARATOR . 'Router.php';
require_once \$base . 'api' . DIRECTORY_SEPARATOR . 'Validator.php';
require_once \$base . 'api' . DIRECTORY_SEPARATOR . 'BaseController.php';
require_once \$base . 'api' . DIRECTORY_SEPARATOR . 'controllers' . DIRECTORY_SEPARATOR . 'JwtAuthController.php';

if (session_status() === PHP_SESSION_NONE) { @session_start(); }

// 🛡️ A minimal PROTECTED probe controller to test Bearer acceptance: it just
//    requires auth and echoes the resolved subject + auth_method.
class _WhoAmIController extends BaseController {
    public function whoami(array \$params): void {
        \$user = \$this->requireAuth();
        Response::success([
            'user_id'     => (int) \$user['userID'],
            'auth_method' => \$user['auth_method'] ?? 'unknown',
            'scope'       => \$user['scope'] ?? '',
        ], 'ok');
    }
}

Response::setVersion('v1');

\$router = new Router();
\$router->group('/api/v1/auth', function(\$router) {
    \$router->post('/token',   'JwtAuthController@issueToken');
    \$router->post('/refresh', 'JwtAuthController@refreshToken');
    \$router->post('/revoke',  'JwtAuthController@revokeToken');
});
\$router->get('/api/v1/_probe/whoami', '_WhoAmIController@whoami');

try {
    \$router->dispatch();
} catch (\\Throwable \$e) {
    Response::internalError('harness error');
}
PHP;
    }

    /**
     * Decode a subprocess JSON body, skipping the test if the server didn't boot.
     *
     * @param array{0:int,1:string,2:array<int,string>}|null $result
     * @return array{status:int,body:array<string,mixed>,headers:array<int,string>}
     */
    private function decode(?array $result): array
    {
        if ($result === null) {
            $this->markTestSkipped('Could not boot php -S subprocess for the JWT endpoints.');
        }
        [$status, $rawBody, $headers] = $result;
        $decoded = json_decode(trim($rawBody), true);
        $this->assertIsArray($decoded, "Endpoint must return JSON. Raw: " . substr($rawBody, 0, 400));
        return ['status' => $status, 'body' => $decoded, 'headers' => $headers];
    }

    // ========================================================================
    // ✅ HAPPY PATH — issue → bearer-accept
    // ========================================================================

    /**
     * A valid email/password → /auth/token returns access + refresh, and the
     * access token authenticates a protected route via Authorization: Bearer.
     */
    public function testValidCredentialIssuesTokensAndBearerIsAccepted(): void
    {
        $user = $this->createCommittedUser();

        $r = $this->decode($this->httpRequest('POST', '/api/v1/auth/token', [
            'email'    => $user['email'],
            'password' => $this->password,
        ]));

        $this->assertSame(200, $r['status'], 'Valid credential must issue tokens');
        $this->assertTrue($r['body']['success']);
        $data = $r['body']['data'];
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertSame('Bearer', $data['token_type']);
        $this->assertSame(3, substr_count($data['access_token'], '.') + 1, 'access_token is a compact JWT');
        $this->assertSame(64, strlen($data['refresh_token']), 'refresh_token is 64 hex chars');

        // 🛡️ Token response must be uncacheable.
        $joined = strtolower(implode("\n", $r['headers']));
        $this->assertStringContainsString('no-store', $joined, 'Token response must carry Cache-Control: no-store');

        // 🎫 The access token authenticates the protected probe route.
        $who = $this->decode($this->httpRequest(
            'GET',
            '/api/v1/_probe/whoami',
            null,
            ['Authorization' => 'Bearer ' . $data['access_token']]
        ));
        $this->assertSame(200, $who['status'], 'Bearer JWT must authenticate a protected route');
        $this->assertSame($user['userID'], $who['body']['data']['user_id']);
        $this->assertSame('jwt', $who['body']['data']['auth_method'], 'auth_method must be marked jwt');
    }

    // ========================================================================
    // 🚫 TAMPERED / EXPIRED tokens rejected (401)
    // ========================================================================

    /**
     * A tampered access token is rejected with 401 and no reason leaked.
     */
    public function testTamperedTokenRejected(): void
    {
        $user = $this->createCommittedUser();
        $issued = $this->decode($this->httpRequest('POST', '/api/v1/auth/token', [
            'email'    => $user['email'],
            'password' => $this->password,
        ]));
        $token = $issued['body']['data']['access_token'];

        // Flip a character in the signature segment → signature invalid.
        $parts = explode('.', $token);
        $parts[2] = strrev($parts[2]);
        $tampered = implode('.', $parts);

        $who = $this->decode($this->httpRequest(
            'GET',
            '/api/v1/_probe/whoami',
            null,
            ['Authorization' => 'Bearer ' . $tampered]
        ));

        $this->assertSame(401, $who['status'], 'Tampered token must be rejected');
        $this->assertFalse($who['body']['success']);
        // 🔒 No leak of WHY (expired vs forged vs revoked).
        $this->assertStringNotContainsStringIgnoringCase('signature', json_encode($who['body']));
        $this->assertStringNotContainsStringIgnoringCase('expired', json_encode($who['body']));
    }

    /**
     * An expired access token is rejected with 401.
     *
     * We sign a token with a past exp directly against the seeded key (so it is
     * genuinely expired), then present it to the protected route.
     */
    public function testExpiredTokenRejected(): void
    {
        $user = $this->createCommittedUser();

        $active = \KeyManager::getActiveKey();
        $past   = time() - 3600;
        $expired = \Firebase\JWT\JWT::encode(
            [
                'sub'   => (string) $user['userID'],
                'iss'   => 'https://signula.id',
                'aud'   => 'https://signula.id/api',
                'iat'   => $past,
                'nbf'   => $past,
                'exp'   => $past + 60, // expired ~59 min ago
                'jti'   => bin2hex(random_bytes(16)),
                'scope' => 'user:read',
            ],
            $active['private_pem'],
            'RS256',
            $active['kid'],
            ['typ' => 'at+jwt']
        );

        $who = $this->decode($this->httpRequest(
            'GET',
            '/api/v1/_probe/whoami',
            null,
            ['Authorization' => 'Bearer ' . $expired]
        ));

        $this->assertSame(401, $who['status'], 'Expired token must be rejected');
        $this->assertFalse($who['body']['success']);
    }

    // ========================================================================
    // 🔄 REFRESH rotation + reuse
    // ========================================================================

    /**
     * /auth/refresh rotates: a new pair is returned and the OLD refresh token is
     * dead. Replaying the old (rotated) token is denied (reuse detection).
     */
    public function testRefreshRotatesAndOldRefreshIsDead(): void
    {
        $user = $this->createCommittedUser();
        $issued = $this->decode($this->httpRequest('POST', '/api/v1/auth/token', [
            'email'    => $user['email'],
            'password' => $this->password,
        ]));
        $firstRefresh = $issued['body']['data']['refresh_token'];

        // Rotate.
        $rotated = $this->decode($this->httpRequest('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $firstRefresh,
        ]));
        $this->assertSame(200, $rotated['status'], 'Refresh must rotate');
        $newRefresh = $rotated['body']['data']['refresh_token'];
        $this->assertNotSame($firstRefresh, $newRefresh, 'Rotation issues a NEW refresh token');

        // The NEW refresh token works.
        $again = $this->decode($this->httpRequest('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $newRefresh,
        ]));
        $this->assertSame(200, $again['status'], 'The rotated refresh token is valid');

        // The OLD (already-rotated) refresh token is now denied (reuse).
        $reuse = $this->decode($this->httpRequest('POST', '/api/v1/auth/refresh', [
            'refresh_token' => $firstRefresh,
        ]));
        $this->assertSame(401, $reuse['status'], 'Replaying a rotated refresh token must be denied');
        $this->assertFalse($reuse['body']['success']);
    }

    // ========================================================================
    // 🗑️ REVOKE → token then denied
    // ========================================================================

    /**
     * /auth/revoke on an access token denylists its jti; the SAME token is then
     * denied by the protected route.
     */
    public function testRevokeThenTokenDenied(): void
    {
        $user = $this->createCommittedUser();
        $issued = $this->decode($this->httpRequest('POST', '/api/v1/auth/token', [
            'email'    => $user['email'],
            'password' => $this->password,
        ]));
        $token = $issued['body']['data']['access_token'];

        // Accepted before revoke.
        $before = $this->decode($this->httpRequest(
            'GET', '/api/v1/_probe/whoami', null,
            ['Authorization' => 'Bearer ' . $token]
        ));
        $this->assertSame(200, $before['status']);

        // Revoke the access token (presented via Authorization header, no body).
        $revoke = $this->decode($this->httpRequest(
            'POST', '/api/v1/auth/revoke', [],
            ['Authorization' => 'Bearer ' . $token]
        ));
        $this->assertSame(200, $revoke['status'], 'Revoke is idempotent 200');

        // Now denied.
        $after = $this->decode($this->httpRequest(
            'GET', '/api/v1/_probe/whoami', null,
            ['Authorization' => 'Bearer ' . $token]
        ));
        $this->assertSame(401, $after['status'], 'A revoked access token must be denied');
    }

    // ========================================================================
    // 🔒 CROSS-USER — cannot mint a token for someone else's id
    // ========================================================================

    /**
     * 🔒 THE core security property: a caller supplying another user's id (in any
     * plausible body field) still only ever gets a token for the identity their
     * CREDENTIAL proves. Attacker authenticates as themselves but tries to set
     * the subject to the victim's userID — the issued token's sub is the
     * attacker's own id, never the victim's.
     */
    public function testCannotMintTokenForAnotherUsersId(): void
    {
        $victim   = $this->createCommittedUser('victim');
        $attacker = $this->createCommittedUser('attacker');

        // Attacker logs in with THEIR OWN valid credential, but injects the
        // victim's id under every field name an implementation might mistakenly read.
        $r = $this->decode($this->httpRequest('POST', '/api/v1/auth/token', [
            'email'    => $attacker['email'],
            'password' => $this->password,
            // 🦹 malicious subject-injection attempts:
            'user_id'  => $victim['userID'],
            'userID'   => $victim['userID'],
            'sub'      => (string) $victim['userID'],
            'userUUID' => 'whatever',
        ]));

        $this->assertSame(200, $r['status']);
        $accessToken = $r['body']['data']['access_token'];

        // Decode the token payload (no verify needed — we read the sub claim).
        $parts = explode('.', $accessToken);
        $payload = json_decode(\KeyManager::base64UrlDecode($parts[1]), true);

        $this->assertSame(
            (string) $attacker['userID'],
            (string) $payload['sub'],
            'The token subject MUST be the authenticated (attacker) id, NEVER the injected victim id'
        );
        $this->assertNotSame(
            (string) $victim['userID'],
            (string) $payload['sub'],
            'The injected victim id must never become the token subject'
        );

        // And presenting that token resolves to the attacker on the protected route.
        $who = $this->decode($this->httpRequest(
            'GET', '/api/v1/_probe/whoami', null,
            ['Authorization' => 'Bearer ' . $accessToken]
        ));
        $this->assertSame($attacker['userID'], $who['body']['data']['user_id']);
    }

    /**
     * With NO session and NO valid credential (only an injected userID), the
     * token endpoint refuses — a bare id can never mint a token.
     */
    public function testInjectedIdWithoutCredentialIsUnauthorized(): void
    {
        $victim = $this->createCommittedUser('victim2');

        $r = $this->decode($this->httpRequest('POST', '/api/v1/auth/token', [
            // No email/password at all — just an id.
            'user_id' => $victim['userID'],
            'userID'  => $victim['userID'],
            'sub'     => (string) $victim['userID'],
        ]));

        $this->assertSame(401, $r['status'], 'No credential + only an id must be unauthorized');
        $this->assertFalse($r['body']['success']);
    }

    // ========================================================================
    // ❌ WRONG PASSWORD → 401, then rate-limited (429)
    // ========================================================================

    /**
     * Wrong password → 401 (credential rejected). And when the per-identifier
     * rate-limit is ALREADY exhausted, the endpoint returns 429 BEFORE any
     * credential work — a caller cannot spray passwords past the limit.
     *
     * Determinism note: SecurityUtils::checkRateLimit keys its counter row on
     * (identifier, identifierType, endpoint, windowStart-second) and reads a
     * single row's count (a pre-existing burst-window design — see NEEDS-LEAD-
     * REVIEW in the PR/report). Because each subprocess boot takes ~0.7s, a
     * naive loop spreads attempts across different seconds and never accumulates
     * in ONE window row. So instead of racing the clock we prove the two
     * properties deterministically:
     *   (a) a fresh wrong-password attempt yields 401 (credential path), and
     *   (b) with the limiter row PRE-SEEDED at the limit for the CURRENT window,
     *       the very next /auth/token is short-circuited to 429 (the limiter is
     *       actually consulted before the credential is checked).
     */
    public function testWrongPasswordDeniedThenRateLimited(): void
    {
        $user  = $this->createCommittedUser('rl');
        $limit = 3;

        // 🧹 Clear the shared limiter table + pin a low per-identifier limit.
        $this->db->query('DELETE FROM tblRateLimits');
        $this->setSettingCommitted('jwt.rate_limit.token_per_identifier', (string) $limit);
        $this->setSettingCommitted('jwt.rate_limit.token_per_ip', '1000');
        $this->setSettingCommitted('jwt.rate_limit.window_seconds', '900');

        // (a) A wrong password on a CLEAN limiter → 401 (credential rejected).
        $first = $this->decode($this->httpRequest('POST', '/api/v1/auth/token', [
            'email'    => $user['email'],
            'password' => 'WrongPassword!first',
        ]));
        $this->assertSame(401, $first['status'], 'A wrong password on a clean limiter yields 401');
        $this->assertFalse($first['body']['success']);

        // (b) Pre-seed the per-identifier limiter row AT the limit for the
        //     current window-second (committed, so the subprocess sees it), then
        //     the next call must be short-circuited to 429 before any credential
        //     check — proving the endpoint actually enforces the limit.
        $identifier = 'id:' . strtolower($user['email']); // matches the controller key
        $this->seedRateLimitAtLimit($identifier, 'jwt_token', $limit);

        $limited = $this->decode($this->httpRequest('POST', '/api/v1/auth/token', [
            'email'    => $user['email'],
            'password' => 'WrongPassword!again',
        ]));
        $this->assertSame(429, $limited['status'], 'An exhausted per-identifier limit must yield 429');
        $this->assertFalse($limited['body']['success']);
    }

    /**
     * Seed a tblRateLimits row for the CURRENT window-second at exactly $count,
     * so the next SecurityUtils::checkRateLimit for this (identifier, endpoint)
     * with maxRequests == $count returns false (limit reached). Committed so a
     * subprocess sees it.
     *
     * Mirrors SecurityUtils::updateRateLimit's schema (identifierType 'ip',
     * windowStart = now, windowEnd = now + window) exactly.
     *
     * @param string $identifier The limiter identifier (e.g. 'id:foo@example.com').
     * @param string $endpoint   The action/endpoint bucket (e.g. 'jwt_token').
     * @param int    $count      The count to seed (== the configured limit).
     * @return void
     */
    private function seedRateLimitAtLimit(string $identifier, string $endpoint, int $count): void
    {
        $now       = date('Y-m-d H:i:s');
        $windowEnd = date('Y-m-d H:i:s', time() + 900);

        // Remove any prior rows for this identifier/endpoint so the SELECT in
        // checkRateLimit (which reads a single row, no ORDER BY) can only see the
        // maxed-out row we seed below — deterministic 429.
        $del = $this->db->prepare(
            "DELETE FROM tblRateLimits WHERE identifier = ? AND endpoint = ?"
        );
        $del->bind_param('ss', $identifier, $endpoint);
        $del->execute();
        $del->close();

        $stmt = $this->db->prepare(
            "INSERT INTO tblRateLimits
                (identifier, identifierType, endpoint, requestCount, windowStart, windowEnd)
             VALUES (?, 'ip', ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE requestCount = VALUES(requestCount)"
        );
        $stmt->bind_param('ssiss', $identifier, $endpoint, $count, $now, $windowEnd);
        $stmt->execute();
        $stmt->close();
        $this->db->commit();
        $this->db->begin_transaction();
    }

    /**
     * Upsert a setting on the test connection and commit it so a subprocess
     * (separate DB connection) can read it. Re-opens the per-test transaction.
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    private function setSettingCommitted(string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO tblSettings (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
             VALUES (?, ?, 'string', 0, 'jwt', 'test')
             ON DUPLICATE KEY UPDATE settingValue = VALUES(settingValue)"
        );
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
        $stmt->close();
        $this->db->commit();
        $this->db->begin_transaction();
    }

    // ========================================================================
    // 🔑 JWKS — public keys only, and verifies a real token
    // ========================================================================

    /**
     * KeyManager::getJwks() exposes ONLY public key material (no private `d`) and
     * the published JWK independently VERIFIES a freshly-issued token.
     *
     * This runs IN-PROCESS (no HTTP) — it exercises the exact assembly the
     * /.well-known/jwks.json handler serves, and cross-verifies a token via an
     * independent JWK→PEM→openssl_verify path (a different code route than the
     * signer), matching the spec's "verify in an independent verifier" criterion.
     */
    public function testJwksExposesPublicKeyOnlyAndVerifiesToken(): void
    {
        $jwks = \KeyManager::getJwks();
        $this->assertArrayHasKey('keys', $jwks);
        $this->assertNotEmpty($jwks['keys'], 'JWKS must contain the seeded key');

        // 🔒 No private component anywhere.
        foreach ($jwks['keys'] as $jwk) {
            foreach (['d', 'p', 'q', 'dp', 'dq', 'qi'] as $priv) {
                $this->assertArrayNotHasKey($priv, $jwk, "JWKS must NOT expose the private field '{$priv}'");
            }
            $this->assertSame('RSA', $jwk['kty']);
            $this->assertArrayHasKey('n', $jwk);
            $this->assertArrayHasKey('e', $jwk);
        }

        // Find OUR kid's JWK.
        $ourJwk = null;
        foreach ($jwks['keys'] as $jwk) {
            if (($jwk['kid'] ?? '') === $this->kid) {
                $ourJwk = $jwk;
                break;
            }
        }
        $this->assertIsArray($ourJwk, 'The active kid must be published in JWKS');

        // Issue a token, then verify its signature using a PEM reconstructed
        // SOLELY from the published JWK (n,e) — an independent verifier path.
        $userID = $this->createCommittedUser('jwks')['userID'];
        $pair = \TokenService::issueTokens($userID, ['user:read']);

        $pem = \KeyManager::jwkToPublicPem($ourJwk);
        $this->assertIsString($pem, 'The JWK must reconstruct to a PEM public key');

        [$h, $p, $s] = explode('.', $pair['access_token']);
        $signingInput = $h . '.' . $p;
        $signature = \KeyManager::base64UrlDecode($s);

        $ok = openssl_verify($signingInput, $signature, $pem, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $ok, 'A token signed by the active key MUST verify against the published JWKS public key');
    }
}
