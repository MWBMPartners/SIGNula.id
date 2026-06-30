<?php
/**
 * ============================================================================
 * 🧪 BaseController Auth-Seam Characterization Tests (STABILIZE-2 safety net)
 * ============================================================================
 *
 * Pins the CURRENT API authentication seam in BaseController — the exact code
 * the JWT/OIDC build (G-003) will replace. The two behaviours that matter:
 *
 *   1. 🎫 getUserByToken() is a STUB that returns null today (JWT not wired in).
 *      The build will implement real JWT validation here; this test makes the
 *      change loud. (Pure: no database, exercised via reflection.)
 *
 *   2. 🔒 requireAuth() emits a 401 (Response::unauthorized) when no user can be
 *      resolved. Because requireAuth() ultimately calls Response::* which does
 *      header()+exit(), it is driven in an isolated `php -S` subprocess and the
 *      real HTTP status + body are observed (no in-process termination).
 *
 * The database-backed resolvers (getUserById / getUserByApiKey lookups) and the
 * session-cookie path are covered in
 * Integration/API/BaseControllerLookupTest.php.
 *
 * @package    SIGNula\Tests\Unit\API
 * @version    2.7.0-beta
 * @see        web/private_html/api/BaseController.php
 */

namespace SIGNula\Tests\Unit\API;

use SIGNula\Tests\TestCase;
use ReflectionMethod;

// 📦 Load the API stack. Type hints (Database/Validator/ErrorLogger) resolve
// lazily, so loading these three is enough for the pure paths under test.
requireSource('private_html/api/Response.php');
requireSource('private_html/api/Router.php');
requireSource('private_html/api/BaseController.php');

/**
 * Concrete test double so the abstract BaseController can be instantiated
 * without booting a real Router.
 */
class TestableBaseController extends \BaseController
{
    public function __construct()
    {
        // Skip parent constructor (no router injection needed for pure paths).
    }
}

/**
 * BaseController Auth-Seam Characterization Test Suite
 */
class BaseControllerAuthTest extends TestCase
{
    /**
     * 📂 Absolute path to BaseController.php (and its deps) for subprocess use.
     */
    private static function apiDir(): string
    {
        return PROJECT_ROOT
            . DIRECTORY_SEPARATOR . 'web'
            . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'api';
    }

    // ========================================================================
    // 🎫 JWT STUB — getUserByToken() returns null today
    // ========================================================================

    /**
     * getUserByToken() is an unimplemented stub: any token resolves to null.
     *
     * ⚠️ This is the G-003 seam. When JWT auth is implemented this test MUST be
     * updated deliberately — its failure is the signal that the stub is gone.
     */
    public function testGetUserByTokenReturnsNullStub(): void
    {
        $controller = new TestableBaseController();

        $ref = new ReflectionMethod(\BaseController::class, 'getUserByToken');
        $ref->setAccessible(true);

        // A well-formed-looking JWT must still resolve to null today.
        $fakeJwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.signature';
        $this->assertNull($ref->invoke($controller, $fakeJwt));
        // An empty-ish / garbage token: also null.
        $this->assertNull($ref->invoke($controller, 'garbage'));
    }

    // ========================================================================
    // 🔒 requireAuth() → 401 when unauthenticated (subprocess)
    // ========================================================================

    /**
     * requireAuth() with no session / token / api key returns a 401 response
     * with success=false and the "Authentication required" message.
     */
    public function testRequireAuthEmits401WhenUnauthenticated(): void
    {
        $result = $this->runBaseControllerSubprocess();

        if ($result === null) {
            $this->markTestSkipped('Could not boot php -S subprocess to observe requireAuth().');
        }

        [$status, $body] = $result;

        $this->assertSame(401, $status, 'Unauthenticated requireAuth() must emit HTTP 401');

        $decoded = json_decode(trim($body), true);
        $this->assertIsArray($decoded, 'requireAuth() must emit a JSON body');
        $this->assertFalse($decoded['success']);
        $this->assertSame('Authentication required', $decoded['message']);
    }

    // ========================================================================
    // 🛠️ SUBPROCESS HELPER
    // ========================================================================

    /**
     * Boot a minimal controller behind `php -S`, hit it with NO auth headers /
     * session, and capture the HTTP status code + body of requireAuth().
     *
     * @return array{0:int,1:string}|null [status, body] or null if unavailable
     */
    private function runBaseControllerSubprocess(): ?array
    {
        $phpBinary = PHP_BINARY ?: 'php';
        $apiDir    = self::apiDir();

        // The harness defines the guard, stubs getSetting()/getallheaders()/
        // ErrorLogger, loads the API stack, builds a controller with an empty
        // session and no bearer token, then calls requireAuth().
        $harness = <<<'PHP'
<?php
define("SIGNULA_INIT", true);
function getSetting($k, $d = null) { return $k === "api.cors.allowed_origins" ? "*" : $d; }
// getallheaders() is absent under php -S router scripts in some builds; stub it
// to return no Authorization header so the bearer/api-key paths resolve to null.
if (!function_exists("getallheaders")) {
    function getallheaders() { return []; }
}
// Minimal ErrorLogger so the catch blocks have something to call.
class ErrorLogger { public static function log($e) {} public static function logError(...$a) {} }
require __DIR__ . DIRECTORY_SEPARATOR . "Response.php";
require __DIR__ . DIRECTORY_SEPARATOR . "Router.php";
require __DIR__ . DIRECTORY_SEPARATOR . "BaseController.php";

class _Probe extends BaseController {
    public function __construct() {
        $this->setRouter(new Router());
    }
    public function run() { $this->requireAuth(); }
}

$_SESSION = [];                 // no session-based auth
$_GET = [];                     // no ?api_key=
$_SERVER["HTTP_AUTHORIZATION"] = null; // no bearer token

(new _Probe())->run();
PHP;

        // The router script must live in the api dir so __DIR__ resolves deps.
        $routerScript = $apiDir . DIRECTORY_SEPARATOR . '_signula_probe_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($routerScript, $harness);

        try {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $port = random_int(9000, 9999);
                $cmd  = escapeshellarg($phpBinary) . ' -d error_reporting=0 -S 127.0.0.1:' . $port
                    . ' ' . escapeshellarg($routerScript);

                $proc = @proc_open(
                    $cmd,
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes
                );

                if (!is_resource($proc)) {
                    continue;
                }

                usleep(700000);

                $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true]]);
                $body = @file_get_contents('http://127.0.0.1:' . $port . '/', false, $ctx);
                $headers = $http_response_header ?? [];

                proc_terminate($proc);
                @fclose($pipes[0]);
                @fclose($pipes[1]);
                @fclose($pipes[2]);
                proc_close($proc);

                if ($body !== false) {
                    // Parse the status line, e.g. "HTTP/1.1 401 Unauthorized".
                    $status = 0;
                    if (isset($headers[0]) && preg_match('#\s(\d{3})\s#', $headers[0], $m)) {
                        $status = (int) $m[1];
                    }
                    return [$status, (string) $body];
                }
            }

            return null;
        } finally {
            @unlink($routerScript);
        }
    }
}
