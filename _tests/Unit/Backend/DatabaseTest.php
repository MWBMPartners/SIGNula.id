<?php
/**
 * ============================================================================
 * 🧪 Database::getInstance() / DatabaseConnection Unit Tests (B-054)
 * ============================================================================
 *
 * DB-FREE. Pins the class SHAPE the B-054 fix depends on via genuine
 * ReflectionClass introspection — run inside a disposable, short-lived `php`
 * SUBPROCESS rather than by `require`-ing web/_config/database.php in this
 * (shared) PHPUnit Unit-suite process.
 *
 * ⚠️ Why a subprocess, and NOT a plain `requireSource('_config/database.php')`
 * at file scope (the pattern SessionManagerTest.php and others use)?
 *   PHPUnit's Unit test suite runs every test FILE in ONE shared PHP
 *   process (executionOrder="random", no process isolation). `class Database`
 *   is a process-wide symbol once defined — and
 *   _tests/Unit/Security/SecurityAlertManagerTest.php explicitly depends on
 *   `class Database` NOT existing in that shared process (see its own
 *   comment: "Database class is not loaded in unit test context, so
 *   create() should catch the exception and return false"). Loading
 *   web/_config/database.php here — even just to reflect on it — would
 *   define `Database` process-wide for the rest of the Unit suite run,
 *   silently flipping SecurityAlertManagerTest's "Database unavailable"
 *   assumption and breaking 6 of its assertions (reproduced and confirmed
 *   while writing this test, then reverted in favour of THIS approach).
 *   PHPUnit's `#[RunClassInSeparateProcess]` attribute does NOT help here
 *   either: PHPUnit must still `require` every test file in the PARENT
 *   process during suite collection (to reflect on which methods exist) —
 *   and this file previously put `requireSource()` calls at file scope
 *   (outside the class body), so they ran at collection time regardless of
 *   any per-class isolation attribute.
 *
 * ✅ Fix: reflect on `Database`/`DatabaseConnection` inside a genuinely
 * separate OS process (a one-shot `php -r` invocation via shell_exec()).
 * That child process loads web/_config/database.php, runs real
 * ReflectionClass checks, prints a small marker string, and exits — its
 * class table is discarded when the process exits, so the parent PHPUnit
 * process (and every other Unit test sharing it) is completely unaffected.
 * `Database::connect()` is lazy, and nothing here calls
 * getInstance()/getConnection(), so the child process never opens a mysqli
 * connection either — this stays fully DB-free.
 *
 * See _tests/Integration/Backend/DatabaseTest.php for the live, DB-backed
 * proof that the fix actually works end-to-end (real mysqli round-trips).
 *
 * @package    SIGNula\Tests\Unit\Backend
 * @version    2.7.0-beta
 * @see        web/_config/database.php   (Database::getInstance() + DatabaseConnection)
 * @see        web/_backend/Database.php  (B-054 shim)
 * @see        _tests/Unit/Security/SecurityAlertManagerTest.php (the process-sharing hazard this avoids)
 */

namespace SIGNula\Tests\Unit\Backend;

use SIGNula\Tests\TestCase;
use RuntimeException;

/**
 * Database::getInstance() / DatabaseConnection Unit Test Suite
 */
class DatabaseTest extends TestCase
{
    /**
     * 🧪 Run a small PHP snippet in a disposable child process that has
     * already loaded web/_config/database.php and web/_backend/Database.php
     * (in that real-world order), and return whatever it echoes.
     *
     * @param string $phpExpressionStatements PHP statements (no opening tag)
     *                                        to run after both source files
     *                                        are loaded. Must `echo` its result.
     * @return string Trimmed stdout from the child process
     */
    private function reflectInChildProcess(string $phpExpressionStatements): string
    {
        $configDatabasePath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR .
            '_config' . DIRECTORY_SEPARATOR . 'database.php';
        $backendDatabasePath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR .
            '_backend' . DIRECTORY_SEPARATOR . 'Database.php';

        // 🔧 Build a tiny, self-contained script: define SIGNULA_INIT (both
        // files' access guards require it), load the canonical impl then the
        // shim (the same order every one of the 74 dependent pages uses),
        // then run the caller's reflection statements.
        $script = 'define("SIGNULA_INIT", true);'
            . 'require_once ' . var_export($configDatabasePath, true) . ';'
            . 'require_once ' . var_export($backendDatabasePath, true) . ';'
            . $phpExpressionStatements;

        $command = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1';
        $output = shell_exec($command);

        if ($output === null || $output === false) {
            throw new RuntimeException('Child process reflection failed to execute.');
        }

        return trim($output);
    }

    // ========================================================================
    // 🧱 NO REDECLARATION
    // ========================================================================

    /**
     * Loading web/_config/database.php then web/_backend/Database.php
     * back-to-back (the real bootstrap order) in a fresh process must not
     * redeclare `Database` and must leave both `Database` and
     * `DatabaseConnection` defined. If either file declared a second
     * `class Database`, the child process would fatal and print a PHP
     * Fatal error instead of our marker — the assertion below fails loudly
     * in that case rather than silently passing.
     */
    public function testBothFilesLoadTogetherWithoutRedeclaring(): void
    {
        $result = $this->reflectInChildProcess(
            'echo (class_exists("Database") && class_exists("DatabaseConnection")) ? "OK" : "MISSING";'
        );

        $this->assertSame('OK', $result, "Child process output was:\n{$result}");
    }

    // ========================================================================
    // 🏭 Database::getInstance()
    // ========================================================================

    /**
     * Database::getInstance() must exist, be PUBLIC and STATIC (callable as
     * `Database::getInstance()` with no instance, exactly how all 74
     * dependent pages call it), and be typed to return DatabaseConnection.
     */
    public function testGetInstanceIsPublicStaticAndReturnsDatabaseConnection(): void
    {
        $result = $this->reflectInChildProcess(<<<'PHP'
            $reflection = new ReflectionClass("Database");
            if (!$reflection->hasMethod("getInstance")) {
                echo "MISSING_METHOD";
                exit;
            }
            $method = $reflection->getMethod("getInstance");
            $returnType = $method->getReturnType();
            echo implode("|", [
                $method->isStatic() ? "static" : "instance",
                $method->isPublic() ? "public" : "not-public",
                $returnType === null ? "no-return-type" : ltrim((string) $returnType, "\\"),
            ]);
            PHP
        );

        $this->assertSame('static|public|DatabaseConnection', $result, "Child process output was:\n{$result}");
    }

    // ========================================================================
    // 🔌 DatabaseConnection SHAPE
    // ========================================================================

    /**
     * DatabaseConnection must be declared `final` (it is a thin, sealed
     * proxy — no dependent page subclasses it) and must exist.
     */
    public function testDatabaseConnectionClassExistsAndIsFinal(): void
    {
        $result = $this->reflectInChildProcess(<<<'PHP'
            if (!class_exists("DatabaseConnection")) {
                echo "MISSING_CLASS";
                exit;
            }
            $reflection = new ReflectionClass("DatabaseConnection");
            echo $reflection->isFinal() ? "final" : "not-final";
            PHP
        );

        $this->assertSame('final', $result, "Child process output was:\n{$result}");
    }

    /**
     * DatabaseConnection::getConnection() must exist, be public, and return
     * mysqli — the explicit accessor for the 3 verified `$db->getConnection()`
     * call sites.
     */
    public function testGetConnectionMethodIsPublicAndReturnsMysqli(): void
    {
        $result = $this->reflectInChildProcess(<<<'PHP'
            $reflection = new ReflectionClass("DatabaseConnection");
            if (!$reflection->hasMethod("getConnection")) {
                echo "MISSING_METHOD";
                exit;
            }
            $method = $reflection->getMethod("getConnection");
            $returnType = $method->getReturnType();
            echo implode("|", [
                $method->isPublic() ? "public" : "not-public",
                $returnType === null ? "no-return-type" : ltrim((string) $returnType, "\\"),
            ]);
            PHP
        );

        $this->assertSame('public|mysqli', $result, "Child process output was:\n{$result}");
    }

    /**
     * DatabaseConnection must implement __call() — the magic method PHP
     * invokes for any method call not explicitly defined on the class
     * (prepare, query, rollback, commit, begin_transaction, ping,
     * real_escape_string, close, ...), forwarding it to the wrapped mysqli
     * connection.
     *
     * @see https://www.php.net/manual/en/language.oop5.overloading.php#object.call
     */
    public function testImplementsCallMagicMethodForInstanceMethodProxying(): void
    {
        $result = $this->reflectInChildProcess(<<<'PHP'
            $reflection = new ReflectionClass("DatabaseConnection");
            if (!$reflection->hasMethod("__call")) {
                echo "MISSING_METHOD";
                exit;
            }
            echo $reflection->getMethod("__call")->isPublic() ? "public" : "not-public";
            PHP
        );

        $this->assertSame('public', $result, "DatabaseConnection must implement a public __call() to proxy prepare()/query()/rollback()/commit()/begin_transaction()/ping()/etc. Child process output:\n{$result}");
    }

    /**
     * DatabaseConnection must implement __get() — the magic method PHP
     * invokes for any property READ not explicitly defined on the class
     * (insert_id, error, errno, affected_rows, ...), forwarding it to the
     * wrapped mysqli connection.
     *
     * @see https://www.php.net/manual/en/language.oop5.overloading.php#object.get
     */
    public function testImplementsGetMagicMethodForInstancePropertyProxying(): void
    {
        $result = $this->reflectInChildProcess(<<<'PHP'
            $reflection = new ReflectionClass("DatabaseConnection");
            if (!$reflection->hasMethod("__get")) {
                echo "MISSING_METHOD";
                exit;
            }
            echo $reflection->getMethod("__get")->isPublic() ? "public" : "not-public";
            PHP
        );

        $this->assertSame('public', $result, "DatabaseConnection must implement a public __get() to proxy insert_id/error/errno/affected_rows/etc. Child process output:\n{$result}");
    }

    /**
     * The constructor must accept exactly one mysqli argument — the shared
     * connection Database::getInstance() passes in from
     * self::getConnection().
     */
    public function testConstructorAcceptsAMysqliArgument(): void
    {
        $result = $this->reflectInChildProcess(<<<'PHP'
            $reflection = new ReflectionClass("DatabaseConnection");
            $constructor = $reflection->getConstructor();
            if ($constructor === null) {
                echo "NO_CONSTRUCTOR";
                exit;
            }
            $params = $constructor->getParameters();
            if (count($params) !== 1) {
                echo "PARAM_COUNT_" . count($params);
                exit;
            }
            $paramType = $params[0]->getType();
            echo $paramType === null ? "no-type" : ltrim((string) $paramType, "\\");
            PHP
        );

        $this->assertSame('mysqli', $result, "Child process output was:\n{$result}");
    }

    /**
     * web/_backend/Database.php must remain a pure require-guard shim — it
     * must never declare `class Database` itself (would fatal-redeclare
     * against web/_config/database.php). Verified via genuine token
     * analysis of the shim's OWN source text (not by loading it), so this
     * assertion runs safely in-process with zero risk of defining `Database`
     * in the shared Unit-suite process.
     *
     * @see https://www.php.net/manual/en/function.token-get-all.php
     */
    public function testBackendDatabasePhpShimNeverDeclaresClassDatabase(): void
    {
        $shimPath = PROJECT_ROOT . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR .
            '_backend' . DIRECTORY_SEPARATOR . 'Database.php';
        $tokens = token_get_all(file_get_contents($shimPath));

        $declaresDatabaseClass = false;
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            // 🔎 Skip whitespace/comment tokens to find the next meaningful
            // token after `class`, which is the class name. Using the
            // tokenizer (rather than a naive string/regex search) means this
            // can never false-positive on this file's own doc-comment prose,
            // which repeatedly discusses "class Database" in English —
            // token_get_all() folds an entire comment into a single
            // T_COMMENT/T_DOC_COMMENT token, so a real `class Database { }`
            // statement is the ONLY way T_CLASS is immediately followed by
            // a T_STRING('Database') token in the stream.
            for ($lookahead = $index + 1; $lookahead < count($tokens); $lookahead++) {
                $next = $tokens[$lookahead];
                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($next) && $next[0] === T_STRING && strcasecmp($next[1], 'Database') === 0) {
                    $declaresDatabaseClass = true;
                }
                break;
            }
        }

        $this->assertFalse(
            $declaresDatabaseClass,
            'web/_backend/Database.php must remain a pure require-guard shim — it must never declare `class Database` itself (would fatal-redeclare against web/_config/database.php).'
        );
    }
}
