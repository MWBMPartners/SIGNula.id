<?php
/**
 * ============================================================================
 * 📁 SIGNula Universal Login System - Database Connection
 *
 * 🐛 B-060 (found while smoke-testing requireLogin()): this file previously
 * started with TWO blank lines BEFORE the opening `<?php` tag. Those two
 * newline bytes are literal output — emitted the instant this file is
 * require_once'd (very first thing web/_config/config.php does after
 * defining path constants) — which flips headers_sent() to TRUE for the
 * REST of the request, on every single page. That silently defeated:
 *   - Every `if (!headers_sent()) { header(...); }` security header in
 *     config.php (X-Frame-Options, CSP, HSTS, Referrer-Policy, …never sent).
 *   - `redirect()` (web/_config/config.php), which no-ops instead of
 *     redirecting whenever headers_sent() is true — meaning requireLogin()
 *     (Auth::requireAuth() -> redirect('/login?...')) silently failed to
 *     bounce unauthenticated users away from gated pages; execution fell
 *     through into the page body with no authenticated user.
 * Removing the stray leading whitespace is the entire fix — no other line
 * in this file changed.
 * ============================================================================
 * ============================================================================
 *
 * Purpose: Secure database connection using MySQLi with prepared statements
 * PHP Version: 8.3+
 *
 * Security Features:
 * - MySQLi prepared statements for SQL injection protection
 * - Encrypted credentials support
 * - Connection pooling
 * - Error logging to database (when available)
 *
 * @package    SIGNula
 * @subpackage Database
 * @version    1.0.0
 * @link       https://SIGNula.id
 * ============================================================================
 */

// 🚫 Prevent direct access to this file
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * ============================================================================
 * 🔌 DatabaseConnection — thin instance adapter around the shared mysqli link
 * ============================================================================
 *
 * 🐛 B-054 fix. 74 pages under web/public_html do:
 *
 *     $db = Database::getInstance();
 *     $sessionManager = new SessionManager($db);
 *     ...
 *     $stmt = $db->prepare("SELECT ..."); $stmt->bind_param(...); $stmt->execute();
 *
 * i.e. they expect an OBJECT with an instance-style `prepare()`/`query()`/etc,
 * exactly like a raw mysqli link — NOT the static `Database` class above
 * (whose API is `Database::query()`, `Database::fetchOne()`, ... — no
 * `getInstance()`, and none of it is callable through `->`). This class is
 * the shape that closes that gap.
 *
 * Rather than re-implement every mysqli method, this is a minimal PROXY:
 * it holds the one shared mysqli connection (see Database::getConnection())
 * and forwards any method call / property read straight through to it via
 * PHP's magic __call()/__get(). That keeps a single real connection per
 * request (no second connection pool to manage) while giving callers the
 * exact `$db->prepare(...)`, `$db->insert_id`, `$db->getConnection()`,
 * `$db->rollback()`, `$db->commit()`, `$db->begin_transaction()`, `$db->ping()`,
 * `$db->error` surface verified in use across web/public_html.
 *
 * @see https://www.php.net/manual/en/book.mysqli.php
 * @see https://www.php.net/manual/en/language.oop5.overloading.php#object.call
 * @see https://www.php.net/manual/en/language.oop5.overloading.php#object.get
 */
if (!class_exists('DatabaseConnection')) {
    final class DatabaseConnection
    {
        /**
         * 🏗️ Constructor
         *
         * @param mysqli $mysqli The shared connection returned by
         *                       Database::getConnection(). Stored via a
         *                       PHP 8 constructor property promotion
         *                       (readonly-by-convention — never reassigned).
         * @see https://www.php.net/manual/en/language.oop5.decon.php#language.oop5.decon.constructor.promotion
         */
        public function __construct(private readonly \mysqli $mysqli)
        {
        }

        /**
         * 🔗 Get Underlying mysqli Connection
         *
         * Explicit accessor for the 3 verified `$db->getConnection()` call
         * sites — returns the exact same connection object every other
         * $db-> call in this class proxies to (not a copy/clone).
         *
         * @return \mysqli The wrapped mysqli connection
         */
        public function getConnection(): \mysqli
        {
            return $this->mysqli;
        }

        /**
         * 🪄 Magic Method Proxy
         *
         * Forwards any method call not explicitly defined above (prepare,
         * query, rollback, commit, begin_transaction, ping, real_escape_string,
         * close, ...) straight through to the wrapped mysqli connection, so
         * `$db->prepare(...)` behaves exactly like `$mysqli->prepare(...)`.
         *
         * @param string $name Method name being called
         * @param array<int, mixed> $args Positional arguments to forward
         * @return mixed Whatever the underlying mysqli method returns
         * @see https://www.php.net/manual/en/mysqli-stmt.bind-param.php
         */
        public function __call(string $name, array $args): mixed
        {
            return $this->mysqli->{$name}(...$args);
        }

        /**
         * 🪄 Magic Property Proxy
         *
         * Forwards any property READ not explicitly defined above
         * (insert_id, error, errno, affected_rows, ...) straight through to
         * the wrapped mysqli connection, so `$db->insert_id` after an
         * `INSERT` reads the real mysqli::$insert_id.
         *
         * @param string $name Property name being read
         * @return mixed Whatever the underlying mysqli property holds
         * @see https://www.php.net/manual/en/mysqli.insert-id.php
         */
        public function __get(string $name): mixed
        {
            return $this->mysqli->{$name};
        }
    }
}

/**
 * 🗄️ Database Connection Class
 *
 * Manages database connections using MySQLi with singleton pattern
 * to ensure only one connection is maintained per request.
 *
 * @see https://www.php.net/manual/en/book.mysqli.php
 */
class Database
{
    /** @var mysqli|null Database connection instance */
    private static ?mysqli $connection = null;

    /** @var array Database configuration */
    private static array $config = [];

    /** @var bool Connection status */
    private static bool $connected = false;

    /**
     * @var int Rows affected by the most recent query().
     *
     * 🐛 B-040 fix: query() closes the mysqli_stmt (line ~247) before any caller
     * can inspect it. Under mysqlnd, reading $connection->affected_rows AFTER a
     * prepared statement is closed returns -1, NOT the real count — so any
     * getAffectedRows() call (e.g. verifyChallenge()'s atomic 0→1 compare-and-set,
     * B-030) saw -1 and treated a perfectly valid single-row UPDATE as "0 rows /
     * failed". We therefore capture $stmt->affected_rows INSIDE query() while the
     * statement is still open and surface it via getAffectedRows().
     *
     * @see https://www.php.net/manual/en/mysqli-stmt.affected-rows.php
     */
    private static int $lastAffectedRows = 0;

    /** @var array Connection statistics */
    private static array $stats = [
        'queries' => 0,
        'errors' => 0,
        'execution_time' => 0.0
    ];

    /**
     * 🔧 Initialize Database Configuration
     *
     * Loads database credentials from environment or configuration file.
     * Supports both direct credentials and encrypted credential files.
     *
     * @return void
     */
    private static function initConfig(): void
    {
        // 📝 Priority order for configuration:
        // 1. Environment variables (most secure, for production)
        // 2. Configuration file in _private directory
        // 3. Fallback to auth.php (legacy support)

        if (!empty(getenv('DB_HOST'))) {
            // 🔐 Load from environment variables (recommended for production)
            self::$config = [
                'host'     => getenv('DB_HOST'),
                'username' => getenv('DB_USER'),
                'password' => getenv('DB_PASS'),
                'database' => getenv('DB_NAME'),
                'port'     => getenv('DB_PORT') ?: 3306,
                'charset'  => getenv('DB_CHARSET') ?: 'utf8mb4',
                'socket'   => getenv('DB_SOCKET') ?: null
            ];
        } else {
            // 📁 Load from auth.php configuration file
            $authFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_private' . DIRECTORY_SEPARATOR . 'auth.php';

            if (file_exists($authFile) && is_readable($authFile)) {
                require_once $authFile;

                // Validate required constants are defined
                if (defined('DB_HOST') && defined('DB_USER') && defined('DB_PASS') && defined('DB_NAME')) {
                    self::$config = [
                        'host'     => DB_HOST,
                        'username' => DB_USER,
                        'password' => DB_PASS,
                        'database' => DB_NAME,
                        'port'     => defined('DB_PORT') ? DB_PORT : 3306,
                        'charset'  => defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4',
                        'socket'   => defined('DB_SOCKET') ? DB_SOCKET : null
                    ];
                } else {
                    self::logError('Database configuration incomplete in auth.php');
                    throw new RuntimeException('Database configuration not found');
                }
            } else {
                self::logError('Database configuration file not found or not readable');
                throw new RuntimeException('Database configuration file not accessible');
            }
        }
    }

    /**
     * 🔌 Get Database Connection
     *
     * Returns singleton MySQLi connection instance.
     * Creates new connection if one doesn't exist.
     *
     * @return mysqli Database connection
     * @throws RuntimeException If connection fails
     */
    public static function getConnection(): mysqli
    {
        if (self::$connection === null || !self::$connected) {
            self::connect();
        }

        return self::$connection;
    }

    /**
     * 🏭 Get Instance-Style Database Handle
     *
     * 🐛 B-054 fix. 74 pages under web/public_html do
     * `$db = Database::getInstance(); new SessionManager($db);` and then call
     * instance-style methods on $db (`$db->prepare(...)`, `$db->insert_id`,
     * `$db->getConnection()`, ...) — but this class (Database) is 100% STATIC
     * and had no `getInstance()` at all, so every one of those pages fataled
     * with "Call to undefined method Database::getInstance()".
     *
     * Returns a lightweight {@see DatabaseConnection} adapter wrapping the
     * SAME shared mysqli connection self::getConnection() already manages —
     * this does NOT open a second connection; it is just an instance-shaped
     * proxy in front of the one connection this class maintains. A fresh
     * adapter object is handed back on every call (cheap — it holds only a
     * reference), but every adapter proxies to the identical underlying
     * mysqli link, so `Database::getInstance()->getConnection() === Database::getInstance()->getConnection()`
     * is always true within one request.
     *
     * If the connection is not yet configured/reachable, this surfaces the
     * exact same RuntimeException self::getConnection()/self::connect()
     * would throw for any other caller — no new error-swallowing is
     * introduced here.
     *
     * @return DatabaseConnection Instance-style handle proxying to the
     *                            shared mysqli connection
     * @throws RuntimeException If the underlying connection cannot be
     *                          established (propagated from self::connect())
     * @see DatabaseConnection
     */
    public static function getInstance(): DatabaseConnection
    {
        return new DatabaseConnection(self::getConnection());
    }

    /**
     * 🔗 Establish Database Connection
     *
     * Creates new MySQLi connection with proper error handling
     * and character set configuration.
     *
     * @return void
     * @throws RuntimeException If connection fails
     */
    private static function connect(): void
    {
        try {
            // 🔧 Initialize configuration if not already done
            if (empty(self::$config)) {
                self::initConfig();
            }

            // 📊 Enable MySQLi error reporting
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            // 🔌 Create connection
            self::$connection = new mysqli(
                self::$config['host'],
                self::$config['username'],
                self::$config['password'],
                self::$config['database'],
                self::$config['port'],
                self::$config['socket']
            );

            // ✅ Check connection
            if (self::$connection->connect_error) {
                throw new RuntimeException(
                    'Database connection failed: ' . self::$connection->connect_error
                );
            }

            // 🔤 Set character set to UTF-8 (4-byte Unicode support)
            // This is crucial for proper handling of emojis and special characters
            if (!self::$connection->set_charset(self::$config['charset'])) {
                throw new RuntimeException(
                    'Error setting charset: ' . self::$connection->error
                );
            }

            // ⏰ Set timezone to UTC for consistent timestamp handling
            self::$connection->query("SET time_zone = '+00:00'");

            // 🔒 Set SQL mode for strict data integrity
            self::$connection->query("SET sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'");

            self::$connected = true;

        } catch (mysqli_sql_exception $e) {
            self::logError('Database connection error: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed. Please check configuration.');
        }
    }

    /**
     * 🔍 Execute Prepared Statement Query
     *
     * Executes a prepared statement with parameters for SQL injection protection.
     *
     * @param string $query SQL query with ? placeholders
     * @param array $params Parameters to bind
     * @param string $types Parameter types (i=integer, d=double, s=string, b=blob)
     * @return mysqli_result|bool Query result
     * @throws RuntimeException If query execution fails
     *
     * @example
     * ```php
     * $result = Database::query(
     *     "SELECT * FROM tblUsers WHERE email = ? AND accountStatus = ?",
     *     ['user@example.com', 'active'],
     *     'ss'
     * );
     * ```
     */
    public static function query(string $query, array $params = [], string $types = ''): mysqli_result|bool
    {
        $startTime = microtime(true);

        try {
            $conn = self::getConnection();

            // 📝 Prepare statement
            $stmt = $conn->prepare($query);

            if ($stmt === false) {
                throw new RuntimeException('Prepare failed: ' . $conn->error);
            }

            // 🔗 Bind parameters if provided
            if (!empty($params)) {
                if (empty($types)) {
                    // 🎯 Auto-detect parameter types if not provided
                    $types = self::detectParamTypes($params);
                }

                $stmt->bind_param($types, ...$params);
            }

            // ▶️ Execute statement
            $executed = $stmt->execute();

            if ($executed === false) {
                throw new RuntimeException('Execute failed: ' . $stmt->error);
            }

            // 📊 Get result
            $result = $stmt->get_result();

            // 📊 Capture affected rows WHILE the statement is still open. After
            //    $stmt->close() the connection's affected_rows reads back as -1
            //    under mysqlnd, so getAffectedRows() must return this snapshot,
            //    not $connection->affected_rows. (B-040 — see $lastAffectedRows.)
            self::$lastAffectedRows = $stmt->affected_rows;

            // 📈 Update statistics
            self::$stats['queries']++;
            self::$stats['execution_time'] += (microtime(true) - $startTime);

            $stmt->close();

            return $result !== false ? $result : true;

        } catch (mysqli_sql_exception $e) {
            self::$stats['errors']++;
            self::logError('Query error: ' . $e->getMessage() . ' | Query: ' . $query);
            throw new RuntimeException('Database query failed: ' . $e->getMessage());
        }
    }

    /**
     * 🎯 Auto-detect Parameter Types
     *
     * Automatically determines MySQLi parameter types based on PHP variable types.
     *
     * @param array $params Parameters array
     * @return string Type string (e.g., "ssi" for string, string, integer)
     */
    private static function detectParamTypes(array $params): string
    {
        $types = '';

        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i'; // Integer
            } elseif (is_float($param)) {
                $types .= 'd'; // Double
            } elseif (is_string($param)) {
                $types .= 's'; // String
            } else {
                $types .= 'b'; // Blob (binary)
            }
        }

        return $types;
    }

    /**
     * 📝 Fetch Single Row
     *
     * Executes query and returns single row as associative array.
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @param string $types Parameter types
     * @return array|null Row data or null if not found
     */
    public static function fetchOne(string $query, array $params = [], string $types = ''): ?array
    {
        $result = self::query($query, $params, $types);

        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $result->free();
            return $row ?: null;
        }

        return null;
    }

    /**
     * 📋 Fetch All Rows
     *
     * Executes query and returns all rows as array of associative arrays.
     *
     * @param string $query SQL query
     * @param array $params Query parameters
     * @param string $types Parameter types
     * @return array Array of rows
     */
    public static function fetchAll(string $query, array $params = [], string $types = ''): array
    {
        $result = self::query($query, $params, $types);

        if ($result instanceof mysqli_result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            return $rows;
        }

        return [];
    }

    /**
     * 🔢 Get Last Insert ID
     *
     * Returns the auto-generated ID from the last INSERT query.
     *
     * @return int Last insert ID
     */
    public static function getLastInsertId(): int
    {
        return self::getConnection()->insert_id;
    }

    /**
     * 🆔 Get Last Insert ID (alias)
     *
     * Convenience alias for {@see self::getLastInsertId()}. Provided because a
     * number of call sites (e.g. AccountManager::queueDataExport) call
     * Database::insertId() directly. Keeping it as a thin alias avoids
     * duplicating the accessor logic.
     *
     * @return int Last insert ID
     */
    public static function insertId(): int
    {
        return self::getLastInsertId();
    }

    /**
     * ➕ Execute INSERT and Return New Row ID
     *
     * Runs a prepared INSERT statement (via {@see self::query()}) and returns
     * the auto-generated primary key produced by it. This is the house idiom
     * for inserts — several classes (WebAuthnHandler, PasswordlessLoginHandler,
     * EmailScheduler, EmailABTesting, EmailDripCampaign, EmailTemplateManager)
     * call Database::insert() and expect the new id back.
     *
     * Because {@see self::query()} throws on failure (it converts MySQLi errors
     * into a RuntimeException), a returned value here always reflects a
     * successful INSERT. The new id is read immediately after execution from the
     * same connection, so it is the id created by THIS statement.
     *
     * @param string $query INSERT statement with ? placeholders
     * @param array  $params Parameters to bind
     * @param string $types  Optional MySQLi type string (auto-detected if empty)
     * @return int Auto-generated insert id (0 for tables without AUTO_INCREMENT)
     * @throws RuntimeException If the INSERT fails (propagated from query())
     *
     * @see https://www.php.net/manual/en/mysqli.insert-id.php
     *
     * @example
     * ```php
     * $newId = Database::insert(
     *     "INSERT INTO tblExample (name, createdAt) VALUES (?, NOW())",
     *     ['Widget'],
     *     's'
     * );
     * ```
     */
    public static function insert(string $query, array $params = [], string $types = ''): int
    {
        // ▶️ Execute the INSERT; query() throws (RuntimeException) on any failure,
        //    so reaching the next line means the row was written successfully.
        self::query($query, $params, $types);

        // 🔢 Return the id generated by this INSERT on the same connection.
        return self::getLastInsertId();
    }

    /**
     * 📊 Get Affected Rows
     *
     * Returns number of rows affected by last UPDATE/DELETE query.
     *
     * @return int Number of affected rows
     */
    public static function getAffectedRows(): int
    {
        // 🐛 B-040: return the count captured inside query() BEFORE the statement
        //    was closed. Reading $connection->affected_rows here would return -1
        //    for the (now-closed) prepared statement under mysqlnd.
        return self::$lastAffectedRows;
    }

    /**
     * 🔄 Begin Transaction
     *
     * Starts a new database transaction for atomic operations.
     *
     * @return bool Success status
     */
    public static function beginTransaction(): bool
    {
        return self::getConnection()->begin_transaction();
    }

    /**
     * ✅ Commit Transaction
     *
     * Commits the current transaction.
     *
     * @return bool Success status
     */
    public static function commit(): bool
    {
        return self::getConnection()->commit();
    }

    /**
     * ↩️ Rollback Transaction
     *
     * Rolls back the current transaction.
     *
     * @return bool Success status
     */
    public static function rollback(): bool
    {
        return self::getConnection()->rollback();
    }

    /**
     * 🧹 Escape String
     *
     * Escapes special characters in a string for use in SQL.
     * Note: Prepared statements are preferred over manual escaping.
     *
     * @param string $string String to escape
     * @return string Escaped string
     */
    public static function escape(string $string): string
    {
        return self::getConnection()->real_escape_string($string);
    }

    /**
     * 📈 Get Connection Statistics
     *
     * Returns connection statistics for monitoring and debugging.
     *
     * @return array Statistics array
     */
    public static function getStats(): array
    {
        return self::$stats;
    }

    /**
     * ❌ Log Error
     *
     * Logs database errors to file or database (if connection available).
     *
     * @param string $message Error message
     * @return void
     */
    private static function logError(string $message): void
    {
        $logFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_private' . DIRECTORY_SEPARATOR .
                   'logs' . DIRECTORY_SEPARATOR . 'database_errors.log';

        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;

        error_log($logMessage, 3, $logFile);
    }

    /**
     * 🔌 Close Connection
     *
     * Closes the database connection.
     * Usually not needed as PHP automatically closes connections,
     * but useful for long-running scripts.
     *
     * @return void
     */
    public static function close(): void
    {
        if (self::$connection !== null && self::$connected) {
            self::$connection->close();
            self::$connection = null;
            self::$connected = false;
        }
    }

    /**
     * 🔍 Check Connection Status
     *
     * Checks if database connection is active.
     *
     * @return bool Connection status
     */
    public static function isConnected(): bool
    {
        return self::$connected && self::$connection !== null;
    }

    /**
     * 🏓 Ping Connection
     *
     * Pings the server to check if connection is alive.
     * Reconnects if connection was lost.
     *
     * @return bool Ping success status
     */
    public static function ping(): bool
    {
        try {
            if (!self::isConnected()) {
                self::connect();
                return true;
            }

            if (!self::$connection->ping()) {
                // 🔄 Connection lost, try to reconnect
                self::$connected = false;
                self::connect();
                return true;
            }

            return true;
        } catch (Exception $e) {
            self::logError('Ping failed: ' . $e->getMessage());
            return false;
        }
    }
}

// ✅ Connection class loaded successfully
