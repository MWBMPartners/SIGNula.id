<?php
/**
 * ============================================================================
 * ✅ SIGNula - API Request Validator
 * ============================================================================
 *
 * Validates API request data against defined rules.
 * Provides comprehensive validation for common data types and formats.
 *
 * Features:
 * - Field presence validation (required)
 * - Type validation (string, integer, email, etc.)
 * - Length validation (min, max)
 * - Format validation (email, URL, regex)
 * - Custom validation rules
 * - Detailed error messages
 *
 * Usage:
 * ```php
 * $validator = new Validator($data);
 * $validator->validate([
 *     'email' => ['required', 'email'],
 *     'password' => ['required', 'min:8', 'max:100'],
 *     'age' => ['required', 'integer', 'min:18'],
 * ]);
 *
 * if ($validator->fails()) {
 *     Response::validationError('Validation failed', $validator->errors());
 * }
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
 * Class Validator
 *
 * Validates request data against rules
 */
class Validator
{
    /**
     * 📥 Data to validate
     */
    private array $data;

    /**
     * ❌ Validation errors
     */
    private array $errors = [];

    /**
     * ✅ Validation passed
     */
    private bool $passed = false;

    /**
     * 🏗️ Constructor
     *
     * @param array $data Data to validate
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * ✅ Validate data against rules
     *
     * @param array $rules Validation rules
     * @return bool True if validation passed
     */
    public function validate(array $rules): bool
    {
        $this->errors = [];
        $this->passed = true;

        foreach ($rules as $field => $fieldRules) {
            // Convert string rules to array
            if (is_string($fieldRules)) {
                $fieldRules = explode('|', $fieldRules);
            }

            foreach ($fieldRules as $rule) {
                $this->applyRule($field, $rule);
            }
        }

        return $this->passed;
    }

    /**
     * 🔧 Apply single validation rule
     *
     * @param string $field Field name
     * @param string $rule Rule to apply
     * @return void
     */
    private function applyRule(string $field, string $rule): void
    {
        // Parse rule and parameters
        $params = [];
        if (str_contains($rule, ':')) {
            [$rule, $paramString] = explode(':', $rule, 2);
            $params = explode(',', $paramString);
        }

        $value = $this->data[$field] ?? null;

        // Apply validation rule
        switch ($rule) {
            case 'required':
                if (empty($value) && $value !== '0' && $value !== 0) {
                    $this->addError($field, "$field is required");
                }
                break;

            case 'email':
                if ($value && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "$field must be a valid email address");
                }
                break;

            case 'url':
                if ($value && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->addError($field, "$field must be a valid URL");
                }
                break;

            case 'integer':
            case 'int':
                if ($value && !filter_var($value, FILTER_VALIDATE_INT)) {
                    $this->addError($field, "$field must be an integer");
                }
                break;

            case 'numeric':
                if ($value && !is_numeric($value)) {
                    $this->addError($field, "$field must be numeric");
                }
                break;

            case 'boolean':
            case 'bool':
                if ($value !== null && !is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
                    $this->addError($field, "$field must be a boolean");
                }
                break;

            case 'string':
                if ($value && !is_string($value)) {
                    $this->addError($field, "$field must be a string");
                }
                break;

            case 'array':
                if ($value && !is_array($value)) {
                    $this->addError($field, "$field must be an array");
                }
                break;

            case 'min':
                $min = $params[0] ?? 0;
                if (is_string($value) && strlen($value) < $min) {
                    $this->addError($field, "$field must be at least $min characters");
                } elseif (is_numeric($value) && $value < $min) {
                    $this->addError($field, "$field must be at least $min");
                }
                break;

            case 'max':
                $max = $params[0] ?? 0;
                if (is_string($value) && strlen($value) > $max) {
                    $this->addError($field, "$field must not exceed $max characters");
                } elseif (is_numeric($value) && $value > $max) {
                    $this->addError($field, "$field must not exceed $max");
                }
                break;

            case 'between':
                $min = $params[0] ?? 0;
                $max = $params[1] ?? 0;
                if (is_string($value)) {
                    $len = strlen($value);
                    if ($len < $min || $len > $max) {
                        $this->addError($field, "$field must be between $min and $max characters");
                    }
                } elseif (is_numeric($value)) {
                    if ($value < $min || $value > $max) {
                        $this->addError($field, "$field must be between $min and $max");
                    }
                }
                break;

            case 'in':
                if ($value && !in_array($value, $params, true)) {
                    $allowed = implode(', ', $params);
                    $this->addError($field, "$field must be one of: $allowed");
                }
                break;

            case 'regex':
                $pattern = $params[0] ?? '';
                if ($value && !preg_match($pattern, $value)) {
                    $this->addError($field, "$field format is invalid");
                }
                break;

            case 'alpha':
                if ($value && !ctype_alpha($value)) {
                    $this->addError($field, "$field must contain only letters");
                }
                break;

            case 'alphanumeric':
                if ($value && !ctype_alnum($value)) {
                    $this->addError($field, "$field must contain only letters and numbers");
                }
                break;

            case 'date':
                if ($value && !strtotime($value)) {
                    $this->addError($field, "$field must be a valid date");
                }
                break;

            case 'json':
                if ($value) {
                    json_decode($value);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $this->addError($field, "$field must be valid JSON");
                    }
                }
                break;

            case 'unique':
                // Requires table:column format
                if (count($params) >= 2) {
                    [$table, $column] = $params;
                    $ignoreId = $params[2] ?? null;

                    if ($value && $this->valueExists($table, $column, $value, $ignoreId)) {
                        $this->addError($field, "$field already exists");
                    }
                }
                break;

            case 'exists':
                // Requires table:column format
                if (count($params) >= 2) {
                    [$table, $column] = $params;

                    if ($value && !$this->valueExists($table, $column, $value)) {
                        $this->addError($field, "$field does not exist");
                    }
                }
                break;

            default:
                // Unknown rule - skip
                break;
        }
    }

    /**
     * ➕ Add validation error
     *
     * @param string $field Field name
     * @param string $message Error message
     * @return void
     */
    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }

        $this->errors[$field][] = $message;
        $this->passed = false;
    }

    /**
     * 🔍 Check if value exists in database
     *
     * @param string $table Table name
     * @param string $column Column name
     * @param mixed $value Value to check
     * @param mixed $ignoreId ID to ignore (for updates)
     * @return bool True if value exists
     */
    private function valueExists(string $table, string $column, mixed $value, mixed $ignoreId = null): bool
    {
        try {
            $query = "SELECT COUNT(*) as count FROM `$table` WHERE `$column` = ?";
            $params = [$value];
            $types = 's';

            if ($ignoreId !== null) {
                $query .= " AND id != ?";
                $params[] = $ignoreId;
                $types .= 'i';
            }

            $result = Database::query($query, $params, $types);

            if ($result && $row = $result->fetch_assoc()) {
                return $row['count'] > 0;
            }

            return false;
        } catch (Exception $e) {
            ErrorLogger::log($e);
            return false;
        }
    }

    /**
     * ❌ Check if validation failed
     *
     * @return bool True if validation failed
     */
    public function fails(): bool
    {
        return !$this->passed;
    }

    /**
     * ✅ Check if validation passed
     *
     * @return bool True if validation passed
     */
    public function passes(): bool
    {
        return $this->passed;
    }

    /**
     * 📋 Get all validation errors
     *
     * @return array Validation errors
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * 🔧 Get errors for specific field
     *
     * @param string $field Field name
     * @return array Field errors
     */
    public function fieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * 📝 Get first error message
     *
     * @return string|null First error message
     */
    public function firstError(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            if (!empty($fieldErrors)) {
                return $fieldErrors[0];
            }
        }

        return null;
    }

    /**
     * 🧹 Get validated data (only fields that passed validation)
     *
     * @return array Validated data
     */
    public function validated(): array
    {
        if ($this->fails()) {
            return [];
        }

        return $this->data;
    }
}

// ✅ Validator class loaded successfully
