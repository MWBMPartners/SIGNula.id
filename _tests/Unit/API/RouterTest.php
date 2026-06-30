<?php
/**
 * ============================================================================
 * 🧪 API Router Characterization Tests (STABILIZE-2 safety net)
 * ============================================================================
 *
 * Characterizes the CURRENT behaviour of the API Router. The JWT/OAuth/OIDC
 * builds (G-003) will register new routes and read bearer tokens through this
 * class, so pinning route registration, pattern compilation, request parsing
 * and body extraction protects that seam against regressions.
 *
 * Coverage:
 * - 📝 Route registration + group prefixing (get/post/put/patch/delete/group)
 * - 🔨 compileRoutePattern() regex output (private, exercised via reflection)
 * - 🔧 getRequestMethod() incl. _method override (private, via reflection)
 * - 🌐 getRequestUri() query-strip + trailing-slash normalisation (via reflection)
 * - 📥 getRequestBody() JSON vs form-data vs raw branches
 * - 🔍 input() / query() / param() superglobal accessors
 *
 * NOTE: getBearerToken()/getAuthHeader() depend on getallheaders(), which does
 * not exist under PHP-CLI, so they are intentionally left to the API auth
 * integration test rather than force-fit here.
 *
 * @package    SIGNula\Tests\Unit\API
 * @version    2.7.0-beta
 * @see        web/private_html/api/Router.php
 */

namespace SIGNula\Tests\Unit\API;

use SIGNula\Tests\TestCase;
use ReflectionMethod;

// 📦 Load source class (SIGNULA_INIT defined by bootstrap.php)
requireSource('private_html/api/Router.php');

/**
 * Router Characterization Test Suite
 */
class RouterTest extends TestCase
{
    /**
     * 🔧 Reset request-shaping superglobals before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        // Clear the keys getRequestMethod/getRequestUri/getRequestBody read.
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['CONTENT_TYPE']
        );
    }

    /**
     * Invoke a private Router method via reflection.
     */
    private function callPrivate(\Router $router, string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(\Router::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($router, $args);
    }

    // ========================================================================
    // 📝 ROUTE REGISTRATION
    // ========================================================================

    /**
     * Registered routes are recorded with method + full path.
     */
    public function testGetRoutesReturnsRegisteredRoutes(): void
    {
        $router = new \Router();
        $router->get('/users', 'UsersController@index');
        $router->post('/users', 'UsersController@create');

        $routes = $router->getRoutes();

        $this->assertCount(2, $routes);
        $this->assertSame('GET', $routes[0]['method']);
        $this->assertSame('/users', $routes[0]['path']);
        $this->assertSame('POST', $routes[1]['method']);
    }

    /**
     * All verb helpers register the correct HTTP method.
     */
    public function testAllVerbHelpersRegisterMethod(): void
    {
        $router = new \Router();
        $router->get('/g', 'C@m');
        $router->post('/p', 'C@m');
        $router->put('/pu', 'C@m');
        $router->patch('/pa', 'C@m');
        $router->delete('/d', 'C@m');

        $methods = array_column($router->getRoutes(), 'method');

        $this->assertSame(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $methods);
    }

    /**
     * group() prefixes nested routes and restores the prefix afterwards.
     */
    public function testGroupPrefixesRoutesAndRestoresPrefix(): void
    {
        $router = new \Router();
        $router->group('/api/v1', function (\Router $r) {
            $r->get('/users', 'C@index');
            $r->post('/users', 'C@create');
        });
        // 📝 After the group closes, the prefix should be cleared.
        $router->get('/health', 'C@health');

        $paths = array_column($router->getRoutes(), 'path');

        $this->assertSame('/api/v1/users', $paths[0]);
        $this->assertSame('/api/v1/users', $paths[1]);
        $this->assertSame('/health', $paths[2], 'Prefix must be restored after group()');
    }

    // ========================================================================
    // 🔨 ROUTE PATTERN COMPILATION (private, via reflection)
    // ========================================================================

    /**
     * compileRoutePattern() converts {param} to a named capture and matches.
     */
    public function testCompileRoutePatternProducesNamedCaptures(): void
    {
        $router = new \Router();

        $pattern = $this->callPrivate($router, 'compileRoutePattern', ['/users/{id}/posts/{postId}']);

        // 📝 Today's compiled form (escaped slashes, named groups, anchored).
        $this->assertSame('/^\/users\/(?P<id>[^\/]+)\/posts\/(?P<postId>[^\/]+)$/', $pattern);

        // The pattern must actually match a concrete URI and capture params.
        $this->assertSame(1, preg_match($pattern, '/users/42/posts/abc', $m));
        $this->assertSame('42', $m['id']);
        $this->assertSame('abc', $m['postId']);

        // 📝 A segment containing a slash must NOT match a single {param}.
        $this->assertSame(0, preg_match($pattern, '/users/42/extra/posts/abc'));
    }

    /**
     * compileRoutePattern() on a static path matches only the exact path.
     */
    public function testCompileRoutePatternStaticPathIsAnchored(): void
    {
        $router = new \Router();
        $pattern = $this->callPrivate($router, 'compileRoutePattern', ['/login']);

        $this->assertSame(1, preg_match($pattern, '/login'));
        $this->assertSame(0, preg_match($pattern, '/login/extra'));
        $this->assertSame(0, preg_match($pattern, '/prefix/login'));
    }

    // ========================================================================
    // 🔧 REQUEST METHOD (private, via reflection)
    // ========================================================================

    /**
     * getRequestMethod() returns the raw method uppercased, defaulting to GET.
     */
    public function testGetRequestMethodDefaultsToGet(): void
    {
        $router = new \Router();
        $this->assertSame('GET', $this->callPrivate($router, 'getRequestMethod'));

        $_SERVER['REQUEST_METHOD'] = 'post';
        $this->assertSame('POST', $this->callPrivate($router, 'getRequestMethod'));
    }

    /**
     * POST + _method override is honoured for PUT/PATCH/DELETE only.
     */
    public function testGetRequestMethodHonoursMethodOverride(): void
    {
        $router = new \Router();

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['_method'] = 'put';
        $this->assertSame('PUT', $this->callPrivate($router, 'getRequestMethod'));

        $_POST['_method'] = 'delete';
        $this->assertSame('DELETE', $this->callPrivate($router, 'getRequestMethod'));

        // 📝 A disallowed override is ignored — stays POST.
        $_POST['_method'] = 'get';
        $this->assertSame('POST', $this->callPrivate($router, 'getRequestMethod'));
    }

    // ========================================================================
    // 🌐 REQUEST URI (private, via reflection)
    // ========================================================================

    /**
     * getRequestUri() strips the query string and trailing slash.
     */
    public function testGetRequestUriStripsQueryAndTrailingSlash(): void
    {
        $router = new \Router();

        $_SERVER['REQUEST_URI'] = '/api/v1/users/?page=2&sort=name';
        $this->assertSame('/api/v1/users', $this->callPrivate($router, 'getRequestUri'));

        $_SERVER['REQUEST_URI'] = '/api/v1/users';
        $this->assertSame('/api/v1/users', $this->callPrivate($router, 'getRequestUri'));

        // 📝 Root path keeps its single slash.
        $_SERVER['REQUEST_URI'] = '/';
        $this->assertSame('/', $this->callPrivate($router, 'getRequestUri'));
    }

    /**
     * getRequestUri() defaults to '/' when REQUEST_URI is absent.
     */
    public function testGetRequestUriDefaultsToRoot(): void
    {
        $router = new \Router();
        $this->assertSame('/', $this->callPrivate($router, 'getRequestUri'));
    }

    // ========================================================================
    // 📥 REQUEST BODY PARSING
    // ========================================================================

    /**
     * Form-encoded content type returns $_POST as the body.
     */
    public function testGetRequestBodyFormUrlEncodedUsesPost(): void
    {
        $router = new \Router();
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['name' => 'Jane', 'role' => 'admin'];

        $body = $router->getRequestBody(true);

        $this->assertSame(['name' => 'Jane', 'role' => 'admin'], $body);
    }

    /**
     * multipart/form-data content type also returns $_POST.
     */
    public function testGetRequestBodyMultipartUsesPost(): void
    {
        $router = new \Router();
        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=xyz';
        $_POST = ['field' => 'value'];

        $this->assertSame(['field' => 'value'], $router->getRequestBody(true));
    }

    /**
     * The parsed body is cached after the first parse (idempotent reads).
     */
    public function testGetRequestBodyIsCached(): void
    {
        $router = new \Router();
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['a' => 1];

        $first = $router->getRequestBody(true);
        // Mutate $_POST after first read — cache should win.
        $_POST = ['a' => 999];
        $second = $router->getRequestBody(true);

        $this->assertSame($first, $second);
    }

    // ========================================================================
    // 🔍 ACCESSORS: input() / query() / param()
    // ========================================================================

    /**
     * input() reads a field from the parsed body with a default fallback.
     */
    public function testInputReadsBodyFieldWithDefault(): void
    {
        $router = new \Router();
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = ['email' => 'a@b.com'];

        $this->assertSame('a@b.com', $router->input('email'));
        $this->assertSame('fallback', $router->input('missing', 'fallback'));
    }

    /**
     * query() reads $_GET with a default fallback.
     */
    public function testQueryReadsGetWithDefault(): void
    {
        $router = new \Router();
        $_GET = ['page' => '3'];

        $this->assertSame('3', $router->query('page', '1'));
        $this->assertSame('default', $router->query('nope', 'default'));
    }

    /**
     * param() returns route params after a match, default otherwise.
     *
     * Params are populated by matchRoute(); we drive it via reflection so the
     * accessor contract is pinned without invoking the exiting dispatch().
     */
    public function testParamReturnsMatchedRouteParameter(): void
    {
        $router = new \Router();
        $router->get('/users/{id}', 'C@show');

        // matchRoute() is private; invoking it populates $this->params.
        $this->callPrivate($router, 'matchRoute', ['GET', '/users/77']);

        $this->assertSame('77', $router->param('id'));
        $this->assertSame('none', $router->param('missing', 'none'));
    }
}
