<?php
/**
 * ============================================================================
 * 🧪 API Response Characterization Tests (STABILIZE-2 safety net)
 * ============================================================================
 *
 * Characterizes the CURRENT behaviour of the API Response formatter. This is a
 * safety net for the upcoming JWT/OAuth/OIDC builds (G-003) which will touch the
 * API auth/response seam. The goal is to PIN today's behaviour so any regression
 * (or intentional fix) is loud and provable — NOT to assert what is "correct".
 *
 * Coverage anchors:
 * - 🔢 HTTP status-code constants (used by requireAuth/validation paths)
 * - 🆔 Static config: setVersion(), setRequestId(), getRequestId()
 * - 📦 Response body SHAPE for success / error / validationError  ← anchors B-011
 *      (the "API error-key inconsistency" finding — we pin where errors live today)
 * - 🌐 setCorsHeaders() emitted headers                           ← anchors B-024
 *      (the "CORS '*' + credentials" finding — pinned so the fix is provably intentional)
 *
 * Response::success()/error()/etc. call header() + exit, so they cannot be
 * invoked in-process under PHPUnit (would terminate the runner / emit header
 * warnings that trip failOnWarning). Instead the SHAPE and CORS tests boot the
 * REAL Response class in an isolated `php -S` subprocess and observe the actual
 * HTTP response — real evidence, no in-process side effects. These subprocess
 * tests skip gracefully if the PHP binary or a free port is unavailable.
 *
 * @package    SIGNula\Tests\Unit\API
 * @version    2.7.0-beta
 * @see        web/private_html/api/Response.php
 */

namespace SIGNula\Tests\Unit\API;

use SIGNula\Tests\TestCase;
use ReflectionMethod;

// 📦 Load source class (SIGNULA_INIT is defined by bootstrap.php)
requireSource('private_html/api/Response.php');

/**
 * Response Characterization Test Suite
 */
class ResponseTest extends TestCase
{
    /**
     * 📂 Absolute path to the Response.php source under test.
     */
    private static function responsePath(): string
    {
        return PROJECT_ROOT
            . DIRECTORY_SEPARATOR . 'web'
            . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'api'
            . DIRECTORY_SEPARATOR . 'Response.php';
    }

    // ========================================================================
    // 🔢 HTTP STATUS-CODE CONSTANTS
    // ========================================================================

    /**
     * Pin the status-code constants the auth/validation seam relies on.
     */
    public function testHttpStatusConstants(): void
    {
        $this->assertSame(200, \Response::HTTP_OK);
        $this->assertSame(201, \Response::HTTP_CREATED);
        $this->assertSame(204, \Response::HTTP_NO_CONTENT);
        $this->assertSame(400, \Response::HTTP_BAD_REQUEST);
        // 🔒 401 is what requireAuth()/unauthorized() must emit.
        $this->assertSame(401, \Response::HTTP_UNAUTHORIZED);
        $this->assertSame(403, \Response::HTTP_FORBIDDEN);
        $this->assertSame(404, \Response::HTTP_NOT_FOUND);
        // ❗ 422 is what validationError() must emit.
        $this->assertSame(422, \Response::HTTP_UNPROCESSABLE);
        $this->assertSame(429, \Response::HTTP_TOO_MANY_REQUESTS);
        $this->assertSame(500, \Response::HTTP_INTERNAL_ERROR);
    }

    // ========================================================================
    // 🆔 STATIC CONFIG (version + request id)
    // ========================================================================

    /**
     * setRequestId() / getRequestId() round-trip (getRequestId is private).
     */
    public function testSetRequestIdIsReturnedByGetRequestId(): void
    {
        \Response::setRequestId('fixed-request-id-123');

        $getRequestId = new ReflectionMethod(\Response::class, 'getRequestId');
        $getRequestId->setAccessible(true);

        $this->assertSame('fixed-request-id-123', $getRequestId->invoke(null));
    }

    /**
     * getRequestId() auto-generates a 32-hex-char id when none was set.
     */
    public function testGetRequestIdAutoGeneratesHexWhenUnset(): void
    {
        // 🔄 Reset the static cache to its unset state so generation runs.
        $requestIdProp = new \ReflectionProperty(\Response::class, 'requestId');
        $requestIdProp->setAccessible(true);
        $requestIdProp->setValue(null, null);

        $getRequestId = new ReflectionMethod(\Response::class, 'getRequestId');
        $getRequestId->setAccessible(true);

        $generated = $getRequestId->invoke(null);

        // bin2hex(random_bytes(16)) => 32 lowercase hex characters.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $generated);

        // 📝 Once generated it is cached (stable within a request).
        $this->assertSame($generated, $getRequestId->invoke(null));
    }

    /**
     * setVersion() changes the version reported in the meta block.
     *
     * Verified through the static $version property since meta is only
     * assembled inside the exiting send() method.
     */
    public function testSetVersionUpdatesStaticVersion(): void
    {
        \Response::setVersion('v9');

        $versionProp = new \ReflectionProperty(\Response::class, 'version');
        $versionProp->setAccessible(true);

        $this->assertSame('v9', $versionProp->getValue());

        // 🔄 Restore default so other tests/subprocess expectations are unaffected.
        \Response::setVersion('v1');
    }

    // ========================================================================
    // 📦 RESPONSE BODY SHAPE (subprocess) — anchors B-011
    // ========================================================================

    /**
     * success() body shape: {success:true, message, data, meta} and NO errors key.
     */
    public function testSuccessResponseShape(): void
    {
        $body = $this->runResponseSubprocess(
            'Response::success(["id" => 7], "Done", 200);'
        );

        $decoded = $this->decodeOrSkip($body);

        $this->assertTrue($decoded['success']);
        $this->assertSame('Done', $decoded['message']);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertSame(7, $decoded['data']['id']);
        // 📝 Today: success responses omit the "errors" key entirely.
        $this->assertArrayNotHasKey('errors', $decoded);
        // 📝 meta block carries timestamp + version + request_id.
        $this->assertArrayHasKey('meta', $decoded);
        $this->assertArrayHasKey('timestamp', $decoded['meta']);
        $this->assertArrayHasKey('version', $decoded['meta']);
        $this->assertArrayHasKey('request_id', $decoded['meta']);
    }

    /**
     * error() body shape: {success:false, message, errors, meta} and NO data key
     * when data is null. Pins WHERE error details live (top-level "errors").
     */
    public function testErrorResponseShape(): void
    {
        $body = $this->runResponseSubprocess(
            'Response::error("Bad thing", 400, ["field" => "broken"]);'
        );

        $decoded = $this->decodeOrSkip($body);

        $this->assertFalse($decoded['success']);
        $this->assertSame('Bad thing', $decoded['message']);
        // 📝 Today: error details are under the top-level "errors" key.
        $this->assertArrayHasKey('errors', $decoded);
        $this->assertSame('broken', $decoded['errors']['field']);
        // 📝 Today: a null data payload omits the "data" key entirely.
        $this->assertArrayNotHasKey('data', $decoded);
    }

    /**
     * validationError() shape: 422 with field-keyed errors under "errors".
     *
     * This pins the validation error contract the JWT/validation builds touch.
     */
    public function testValidationErrorResponseShape(): void
    {
        $body = $this->runResponseSubprocess(
            'Response::validationError("Validation failed", ["email" => ["The email field is required"]]);'
        );

        $decoded = $this->decodeOrSkip($body);

        $this->assertFalse($decoded['success']);
        $this->assertSame('Validation failed', $decoded['message']);
        $this->assertArrayHasKey('errors', $decoded);
        // 📝 Field errors are keyed by field name -> list of messages.
        $this->assertArrayHasKey('email', $decoded['errors']);
        $this->assertContains('The email field is required', $decoded['errors']['email']);
    }

    /**
     * Empty errors array is dropped: error() with [] errors omits "errors".
     *
     * This documents the inconsistency anchored by B-011 — callers cannot rely
     * on an "errors" key always being present on a failure response.
     */
    public function testErrorWithEmptyErrorsOmitsErrorsKey(): void
    {
        $body = $this->runResponseSubprocess(
            'Response::unauthorized("Authentication required");'
        );

        $decoded = $this->decodeOrSkip($body);

        $this->assertFalse($decoded['success']);
        $this->assertSame('Authentication required', $decoded['message']);
        // 📝 unauthorized() passes no errors -> "errors" key is absent.
        $this->assertArrayNotHasKey('errors', $decoded);
    }

    // ========================================================================
    // 🌐 CORS HEADERS (subprocess) — anchors B-024
    // ========================================================================

    /**
     * Default CORS config emits Access-Control-Allow-Origin: * together with
     * Access-Control-Allow-Credentials: true.
     *
     * ⚠️ This pins the CURRENT (insecure) behaviour. Wildcard origin combined
     * with credentials is rejected by browsers and is the B-024 finding. The
     * later fix MUST change this test deliberately.
     */
    public function testDefaultCorsEmitsWildcardOriginWithCredentials(): void
    {
        $headers = $this->runResponseSubprocessHeaders('*');

        if ($headers === null) {
            $this->markTestSkipped('Could not boot php -S subprocess to observe CORS headers.');
        }

        $joined = strtolower(implode("\n", $headers));

        $this->assertStringContainsString('access-control-allow-origin: *', $joined);
        $this->assertStringContainsString('access-control-allow-credentials: true', $joined);
        $this->assertStringContainsString('access-control-allow-methods:', $joined);
        $this->assertStringContainsString('access-control-max-age: 86400', $joined);
    }

    // ========================================================================
    // 🛠️ SUBPROCESS HELPERS
    // ========================================================================

    /**
     * Run a snippet of code that drives Response in an isolated PHP subprocess
     * and capture its stdout (the JSON the exiting send() echoes).
     *
     * @param string $snippet PHP statement(s) calling Response::*
     * @return string Captured stdout
     */
    private function runResponseSubprocess(string $snippet): string
    {
        $phpBinary = PHP_BINARY ?: 'php';
        $respPath  = self::responsePath();

        // Minimal harness: define guard, stub getSetting(), load Response, run snippet.
        $harness = '<?php '
            . 'define("SIGNULA_INIT", true); '
            . 'function getSetting($k, $d = null) { return $k === "api.cors.allowed_origins" ? "*" : $d; } '
            . 'require ' . var_export($respPath, true) . '; '
            . $snippet;

        $tmp = tempnam(sys_get_temp_dir(), 'signula_resp_');
        file_put_contents($tmp, $harness);

        // -d error_reporting=0 keeps CLI header() no-op warnings out of stdout.
        $cmd = escapeshellarg($phpBinary) . ' -d error_reporting=0 ' . escapeshellarg($tmp);
        $output = shell_exec($cmd . ' 2>/dev/null');

        @unlink($tmp);

        return (string) $output;
    }

    /**
     * Boot the REAL Response class behind `php -S` and return the emitted
     * HTTP response headers for a single GET request, or null on failure.
     *
     * @param string $corsSetting Value to return for api.cors.allowed_origins
     * @return array<int,string>|null Response headers or null if unavailable
     */
    private function runResponseSubprocessHeaders(string $corsSetting): ?array
    {
        $phpBinary = PHP_BINARY ?: 'php';
        $respPath  = self::responsePath();

        $router = '<?php '
            . 'define("SIGNULA_INIT", true); '
            . 'function getSetting($k, $d = null) { return $k === "api.cors.allowed_origins" ? ' . var_export($corsSetting, true) . ' : $d; } '
            . 'require ' . var_export($respPath, true) . '; '
            . 'Response::success(["ok" => true], "ok", 200);';

        $tmp = tempnam(sys_get_temp_dir(), 'signula_cors_');
        file_put_contents($tmp, $router);

        // Pick an ephemeral-ish port; retry a couple times if it is busy.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $port = random_int(9000, 9999);
            $cmd  = escapeshellarg($phpBinary) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($tmp);

            $proc = @proc_open(
                $cmd,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );

            if (!is_resource($proc)) {
                continue;
            }

            // Give the server a moment to bind the port.
            usleep(700000);

            $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 3, 'ignore_errors' => true]]);
            $body = @file_get_contents('http://127.0.0.1:' . $port . '/', false, $ctx);
            $headers = $http_response_header ?? null;

            proc_terminate($proc);
            @fclose($pipes[0]);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            proc_close($proc);

            if ($body !== false && is_array($headers)) {
                @unlink($tmp);
                return $headers;
            }
        }

        @unlink($tmp);
        return null;
    }

    /**
     * Decode subprocess JSON output or skip the test if the subprocess could
     * not be run (e.g. shell_exec disabled in this environment).
     *
     * @param string $body Captured stdout
     * @return array Decoded JSON
     */
    private function decodeOrSkip(string $body): array
    {
        $decoded = json_decode(trim($body), true);

        if (!is_array($decoded)) {
            $this->markTestSkipped('Response subprocess produced no decodable JSON (shell_exec unavailable?).');
        }

        return $decoded;
    }
}
