<?php
/**
 * ============================================================================
 * 🛣️ SIGNula - API Router
 * ============================================================================
 *
 * RESTful API request router and dispatcher.
 * Handles routing, request parsing, and endpoint dispatching.
 *
 * Features:
 * - RESTful route matching (GET, POST, PUT, DELETE, PATCH)
 * - URL parameter extraction
 * - Request body parsing (JSON, form data)
 * - Middleware support
 * - Route groups and prefixes
 * - Method override support (_method parameter)
 *
 * Usage:
 * ```php
 * $router = new Router();
 * $router->get('/users', 'UsersController@index');
 * $router->post('/users', 'UsersController@create');
 * $router->get('/users/{id}', 'UsersController@show');
 * $router->dispatch();
 * ```
 *
 * @package    SIGNula
 * @subpackage API
 * @version    1.0.0
 * @link       https://SIGNula.id
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * Class Router
 *
 * Handles API routing and request dispatching
 */
class Router
{
    /**
     * 📋 Registered routes
     */
    private array $routes = [];

    /**
     * 🎯 Current route prefix
     */
    private string $prefix = '';

    /**
     * 🔧 Middleware stack
     */
    private array $middleware = [];

    /**
     * 📥 Parsed request body
     */
    private mixed $requestBody = null;

    /**
     * 🆔 Route parameters
     */
    private array $params = [];

    /**
     * 📝 Register GET route
     *
     * @param string $path Route path
     * @param string|callable $handler Handler (Controller@method or callable)
     * @return self
     */
    public function get(string $path, string|callable $handler): self
    {
        return $this->addRoute('GET', $path, $handler);
    }

    /**
     * ✍️ Register POST route
     *
     * @param string $path Route path
     * @param string|callable $handler Handler
     * @return self
     */
    public function post(string $path, string|callable $handler): self
    {
        return $this->addRoute('POST', $path, $handler);
    }

    /**
     * 🔄 Register PUT route
     *
     * @param string $path Route path
     * @param string|callable $handler Handler
     * @return self
     */
    public function put(string $path, string|callable $handler): self
    {
        return $this->addRoute('PUT', $path, $handler);
    }

    /**
     * 🔄 Register PATCH route
     *
     * @param string $path Route path
     * @param string|callable $handler Handler
     * @return self
     */
    public function patch(string $path, string|callable $handler): self
    {
        return $this->addRoute('PATCH', $path, $handler);
    }

    /**
     * 🗑️ Register DELETE route
     *
     * @param string $path Route path
     * @param string|callable $handler Handler
     * @return self
     */
    public function delete(string $path, string|callable $handler): self
    {
        return $this->addRoute('DELETE', $path, $handler);
    }

    /**
     * 📦 Register route group with prefix
     *
     * @param string $prefix Group prefix
     * @param callable $callback Callback containing route definitions
     * @return self
     */
    public function group(string $prefix, callable $callback): self
    {
        $previousPrefix = $this->prefix;
        $this->prefix .= $prefix;

        $callback($this);

        $this->prefix = $previousPrefix;

        return $this;
    }

    /**
     * 🔧 Add middleware to router
     *
     * @param callable $middleware Middleware callable
     * @return self
     */
    public function middleware(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /**
     * ➕ Add route to registry
     *
     * @param string $method HTTP method
     * @param string $path Route path
     * @param string|callable $handler Handler
     * @return self
     */
    private function addRoute(string $method, string $path, string|callable $handler): self
    {
        $fullPath = $this->prefix . $path;

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $fullPath,
            'pattern' => $this->compileRoutePattern($fullPath),
            'handler' => $handler,
            'middleware' => $this->middleware,
        ];

        return $this;
    }

    /**
     * 🔨 Compile route path to regex pattern
     *
     * Converts /users/{id}/posts to regex pattern
     *
     * @param string $path Route path
     * @return string Compiled regex pattern
     */
    private function compileRoutePattern(string $path): string
    {
        // Escape forward slashes
        $pattern = str_replace('/', '\/', $path);

        // Replace {param} with named capture groups
        $pattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '(?P<$1>[^\/]+)', $pattern);

        return '/^' . $pattern . '$/';
    }

    /**
     * 🚀 Dispatch request to appropriate handler
     *
     * @return void (exits via Response)
     */
    public function dispatch(): void
    {
        // 🔧 Handle OPTIONS preflight
        Response::handlePreflight();

        // 📥 Parse request
        $method = $this->getRequestMethod();
        $uri = $this->getRequestUri();

        // 🔍 Find matching route
        $matchedRoute = $this->matchRoute($method, $uri);

        if ($matchedRoute === null) {
            Response::notFound('Endpoint not found');
            return;
        }

        // 🔧 Execute middleware
        foreach ($matchedRoute['middleware'] as $middleware) {
            $middleware();
        }

        // 📋 Execute handler
        $this->executeHandler($matchedRoute['handler'], $this->params);
    }

    /**
     * 🔎 Find route matching request
     *
     * @param string $method HTTP method
     * @param string $uri Request URI
     * @return array|null Matched route or null
     */
    private function matchRoute(string $method, string $uri): ?array
    {
        foreach ($this->routes as $route) {
            // Check HTTP method
            if ($route['method'] !== $method) {
                continue;
            }

            // Check path pattern
            if (preg_match($route['pattern'], $uri, $matches)) {
                // Extract named parameters
                $this->params = array_filter(
                    $matches,
                    fn($key) => is_string($key),
                    ARRAY_FILTER_USE_KEY
                );

                return $route;
            }
        }

        return null;
    }

    /**
     * ⚙️ Execute route handler
     *
     * @param string|callable $handler Handler
     * @param array $params Route parameters
     * @return void
     */
    private function executeHandler(string|callable $handler, array $params): void
    {
        try {
            if (is_callable($handler)) {
                // Execute closure/function
                call_user_func($handler, $params);
            } else {
                // Execute controller method (Controller@method)
                [$controller, $method] = explode('@', $handler);

                if (!class_exists($controller)) {
                    throw new Exception("Controller class '$controller' not found");
                }

                $controllerInstance = new $controller();

                // 🔌 Inject THIS router so controllers can read the request body /
                //    query / route params / bearer token via BaseController's
                //    $this->router. Without this, any controller method that calls
                //    $this->input()/validate()/query()/requireAuth() fatals with
                //    "Typed property BaseController::$router must not be accessed
                //    before initialization" (the property is non-nullable and only
                //    set here). Guarded by method_exists so a controller that does
                //    not extend BaseController is unaffected.
                if (method_exists($controllerInstance, 'setRouter')) {
                    $controllerInstance->setRouter($this);
                }

                if (!method_exists($controllerInstance, $method)) {
                    throw new Exception("Method '$method' not found in controller '$controller'");
                }

                $controllerInstance->$method($params);
            }
        } catch (Exception $e) {
            // 💥 Handle execution errors
            ErrorLogger::log($e);
            Response::internalError('Request processing failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * 🔧 Get HTTP request method
     *
     * Supports method override via _method parameter
     *
     * @return string HTTP method
     */
    private function getRequestMethod(): string
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // Support method override for PUT/DELETE from forms
        if ($method === 'POST') {
            $override = $_POST['_method'] ?? $_GET['_method'] ?? null;
            if ($override && in_array(strtoupper($override), ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = strtoupper($override);
            }
        }

        return strtoupper($method);
    }

    /**
     * 🌐 Get request URI (path only, no query string)
     *
     * @return string Request URI
     */
    private function getRequestUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // Remove query string
        if (($pos = strpos($uri, '?')) !== false) {
            $uri = substr($uri, 0, $pos);
        }

        // Remove trailing slash (except for root)
        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }

        return $uri;
    }

    /**
     * 📥 Get parsed request body
     *
     * Parses JSON or form data
     *
     * @param bool $assoc Return as associative array (default: true)
     * @return mixed Parsed request body
     */
    public function getRequestBody(bool $assoc = true): mixed
    {
        if ($this->requestBody !== null) {
            return $this->requestBody;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        // Parse JSON
        if (str_contains($contentType, 'application/json')) {
            $rawBody = file_get_contents('php://input');
            $this->requestBody = json_decode($rawBody, $assoc);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Response::error('Invalid JSON in request body', Response::HTTP_BAD_REQUEST, [
                    'json_error' => json_last_error_msg(),
                ]);
            }
        }
        // Parse form data
        elseif (str_contains($contentType, 'application/x-www-form-urlencoded') ||
                str_contains($contentType, 'multipart/form-data')) {
            $this->requestBody = $_POST;
        }
        // Raw body
        else {
            $this->requestBody = file_get_contents('php://input');
        }

        return $this->requestBody;
    }

    /**
     * 🔧 Get specific field from request body
     *
     * @param string $field Field name
     * @param mixed $default Default value if not found
     * @return mixed Field value or default
     */
    public function input(string $field, mixed $default = null): mixed
    {
        $body = $this->getRequestBody(true);

        if (is_array($body)) {
            return $body[$field] ?? $default;
        }

        return $default;
    }

    /**
     * 🔍 Get query parameter
     *
     * @param string $param Parameter name
     * @param mixed $default Default value
     * @return mixed Parameter value or default
     */
    public function query(string $param, mixed $default = null): mixed
    {
        return $_GET[$param] ?? $default;
    }

    /**
     * 🆔 Get route parameter
     *
     * @param string $param Parameter name
     * @param mixed $default Default value
     * @return mixed Parameter value or default
     */
    public function param(string $param, mixed $default = null): mixed
    {
        return $this->params[$param] ?? $default;
    }

    /**
     * 🔑 Get authorization header
     *
     * @return string|null Authorization header value
     */
    public function getAuthHeader(): ?string
    {
        $headers = getallheaders();
        return $headers['Authorization'] ?? $headers['authorization'] ?? null;
    }

    /**
     * 🎫 Get bearer token from authorization header
     *
     * @return string|null Bearer token
     */
    public function getBearerToken(): ?string
    {
        $auth = $this->getAuthHeader();

        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }

        return null;
    }

    /**
     * 📋 Get all registered routes (for debugging)
     *
     * @return array All routes
     */
    public function getRoutes(): array
    {
        return array_map(function ($route) {
            return [
                'method' => $route['method'],
                'path' => $route['path'],
            ];
        }, $this->routes);
    }
}

// ✅ Router class loaded successfully
