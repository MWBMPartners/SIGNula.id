<?php
/**
 * ============================================================================
 * ❌ SIGNula - Error Logger
 * ============================================================================
 *
 * Purpose: Log application and PHP errors to database
 * PHP Version: 8.3+
 *
 * @package    SIGNula
 * @subpackage Utils
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🐛 Error Logger Class
 *
 * Logs errors and exceptions to database for debugging and monitoring.
 */
class ErrorLogger
{
    /**
     * 📝 Log Error
     *
     * @param string $errorType Error type
     * @param string $errorMessage Error message
     * @param string|null $errorFile File where error occurred
     * @param int|null $errorLine Line where error occurred
     * @param array $context Additional context data
     * @param string $severity Error severity
     * @return bool Success status
     */
    public static function logError(
        string $errorType,
        string $errorMessage,
        ?string $errorFile = null,
        ?int $errorLine = null,
        array $context = [],
        string $severity = 'error'
    ): bool {
        try {
            // Get current user and session if available
            $userID = Auth::getCurrentUserID() ?? null;
            $sessionID = $_SESSION['session_id'] ?? null;

            // Get backtrace
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $traceString = self::formatBacktrace($backtrace);

            // Get request information
            $requestURL = getCurrentURL();
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
            $requestHeaders = self::getRequestHeaders();
            $requestData = self::getRequestData();

            $query = "
                INSERT INTO tblErrorLog (
                    userID, sessionID, errorType, errorMessage,
                    errorFile, errorLine, errorTrace, severity,
                    context, requestURL, requestMethod,
                    requestHeaders, requestData,
                    ipAddress, userAgent, createdAt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ";

            Database::query($query, [
                $userID,
                $sessionID,
                $errorType,
                $errorMessage,
                $errorFile,
                $errorLine,
                $traceString,
                $severity,
                json_encode($context, JSON_UNESCAPED_UNICODE),
                $requestURL,
                $requestMethod,
                json_encode($requestHeaders, JSON_UNESCAPED_UNICODE),
                json_encode($requestData, JSON_UNESCAPED_UNICODE),
                getClientIP(),
                getUserAgent()
            ], 'iisssississssss');

            return true;

        } catch (Exception $e) {
            // Log to file as fallback
            error_log("Error logging failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🪵 Log a Throwable (convenience wrapper)
     *
     * Convenience entry point for the very common `catch (Throwable $e)` pattern
     * used throughout the codebase (API controllers, payment managers, etc.),
     * which call `ErrorLogger::log($e);`. It unpacks the throwable into the
     * fields expected by {@see self::logError()} so callers don't have to repeat
     * the `('Exception', $e->getMessage(), $e->getFile(), $e->getLine())` boilerplate.
     *
     * The error type is derived from the throwable's (short) class name so that,
     * e.g., a RuntimeException is logged with errorType "RuntimeException".
     *
     * @param \Throwable $e        The caught exception/error to log
     * @param array      $context  Additional context data (merged with the
     *                             throwable code under 'exceptionCode')
     * @param string     $severity Error severity (default 'error')
     * @return bool Success status (propagated from logError())
     */
    public static function log(
        \Throwable $e,
        array $context = [],
        string $severity = 'error'
    ): bool {
        // 🏷️ Derive a concise error type from the throwable's class name.
        //    e.g. "App\\Exceptions\\FooException" -> "FooException"
        $errorType = (new \ReflectionClass($e))->getShortName();

        // 📦 Preserve the throwable's numeric code alongside any caller context.
        $context['exceptionCode'] = $e->getCode();

        return self::logError(
            $errorType,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $context,
            $severity
        );
    }

    /**
     * 📋 Format Backtrace
     *
     * @param array $backtrace Debug backtrace array
     * @return string Formatted backtrace string
     */
    private static function formatBacktrace(array $backtrace): string
    {
        $trace = [];

        foreach ($backtrace as $i => $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? 'unknown';
            $class = $frame['class'] ?? '';

            $trace[] = "#{$i} {$file}({$line}): {$class}{$function}()";
        }

        return implode("\n", $trace);
    }

    /**
     * 📋 Get Request Headers
     *
     * @return array Request headers
     */
    private static function getRequestHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', substr($key, 5));
                // Don't log sensitive headers
                if (!in_array(strtolower($headerName), ['authorization', 'cookie'])) {
                    $headers[$headerName] = $value;
                }
            }
        }

        return $headers;
    }

    /**
     * 📦 Get Request Data
     *
     * @return array Request data (GET, POST, FILES)
     */
    private static function getRequestData(): array
    {
        $data = [
            'GET' => $_GET ?? [],
            'POST' => $_POST ?? [],
            'FILES' => $_FILES ?? []
        ];

        // Remove sensitive fields
        $sensitiveFields = ['password', 'token', 'secret', 'apikey', 'api_key'];

        foreach ($sensitiveFields as $field) {
            if (isset($data['POST'][$field])) {
                $data['POST'][$field] = '[REDACTED]';
            }
            if (isset($data['GET'][$field])) {
                $data['GET'][$field] = '[REDACTED]';
            }
        }

        return $data;
    }
}

// ✅ ErrorLogger loaded successfully
