<?php
/**
 * ============================================================================
 * 📧 SIGNula - Email Template Manager
 * ============================================================================
 *
 * Purpose: Manage email templates with preview and validation
 * PHP Version: 8.3+
 *
 * Features:
 * - Template creation and editing
 * - Variable validation
 * - Template preview with sample data
 * - Version control
 * - Attachment management
 * - Template rendering
 *
 * @package    SIGNula
 * @subpackage Email
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 📧 Email Template Manager
 *
 * Manages email templates with advanced features.
 */
class EmailTemplateManager
{
    /**
     * 📋 Get Template by Key
     *
     * Retrieves template by its unique key.
     *
     * @param string $templateKey Template key
     * @return array|null Template data or null if not found
     */
    public static function getTemplateByKey(string $templateKey): ?array
    {
        $query = "
            SELECT * FROM tblEmailTemplates
            WHERE templateKey = ? AND isActive = TRUE
            LIMIT 1
        ";

        return Database::fetchOne($query, [$templateKey], 's');
    }

    /**
     * 📋 Get Template by ID
     *
     * Retrieves template by its ID.
     *
     * @param int $templateID Template ID
     * @return array|null Template data or null if not found
     */
    public static function getTemplateByID(int $templateID): ?array
    {
        $query = "
            SELECT * FROM tblEmailTemplates
            WHERE templateID = ?
            LIMIT 1
        ";

        return Database::fetchOne($query, [$templateID], 'i');
    }

    /**
     * 📋 Get All Templates
     *
     * Retrieves all active templates.
     *
     * @param string|null $category Filter by category
     * @param bool $includeInactive Include inactive templates
     * @return array Array of templates
     */
    public static function getAllTemplates(?string $category = null, bool $includeInactive = false): array
    {
        $conditions = [];
        $params = [];
        $types = '';

        if (!$includeInactive) {
            $conditions[] = 'isActive = TRUE';
        }

        if ($category !== null) {
            $conditions[] = 'category = ?';
            $params[] = $category;
            $types .= 's';
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $query = "
            SELECT * FROM tblEmailTemplates
            {$whereClause}
            ORDER BY category, templateName
        ";

        return Database::fetchAll($query, $params, $types) ?: [];
    }

    /**
     * ➕ Create Template
     *
     * Creates a new email template.
     *
     * @param array $templateData Template data
     * @param int|null $userID User creating the template
     * @return int|null Template ID or null on failure
     */
    public static function createTemplate(array $templateData, ?int $userID = null): ?int
    {
        try {
            // ✅ Validate required fields
            $required = ['templateKey', 'templateName', 'subject', 'bodyText'];
            foreach ($required as $field) {
                if (empty($templateData[$field])) {
                    throw new RuntimeException("Missing required field: {$field}");
                }
            }

            // 🔍 Check if template key already exists
            $existing = self::getTemplateByKey($templateData['templateKey']);
            if ($existing) {
                throw new RuntimeException("Template with key '{$templateData['templateKey']}' already exists");
            }

            // 📝 Extract variables from template
            $requiredVars = self::extractVariables($templateData['subject'] . ' ' . $templateData['bodyText'] . ' ' . ($templateData['bodyHTML'] ?? ''));

            $query = "
                INSERT INTO tblEmailTemplates (
                    templateKey, templateName, description,
                    subject, bodyHTML, bodyText,
                    fromEmail, fromName, replyTo,
                    defaultAttachments,
                    category, isActive, trackingEnabled,
                    requiredVariables,
                    createdBy
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $templateID = Database::insert($query, [
                $templateData['templateKey'],
                $templateData['templateName'],
                $templateData['description'] ?? null,
                $templateData['subject'],
                $templateData['bodyHTML'] ?? null,
                $templateData['bodyText'],
                $templateData['fromEmail'] ?? null,
                $templateData['fromName'] ?? null,
                $templateData['replyTo'] ?? null,
                !empty($templateData['defaultAttachments']) ? json_encode($templateData['defaultAttachments']) : null,
                $templateData['category'] ?? 'transactional',
                $templateData['isActive'] ?? true,
                $templateData['trackingEnabled'] ?? false,
                json_encode(array_values($requiredVars)),
                $userID
            ], 'sssssssssssissi');

            // 📝 Log activity
            if ($templateID && $userID) {
                ActivityLogger::log(
                    $userID,
                    'template_created',
                    'email',
                    'info',
                    "Created email template: {$templateData['templateName']}",
                    ['template_id' => $templateID, 'template_key' => $templateData['templateKey']]
                );
            }

            return $templateID;

        } catch (Exception $e) {
            error_log("Failed to create email template: " . $e->getMessage());
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return null;
        }
    }

    /**
     * ✏️ Update Template
     *
     * Updates an existing template and creates a new version.
     *
     * @param int $templateID Template ID
     * @param array $templateData Updated template data
     * @param int|null $userID User updating the template
     * @param bool $createVersion Create a new version (default: true)
     * @return bool Success status
     */
    public static function updateTemplate(int $templateID, array $templateData, ?int $userID = null, bool $createVersion = true): bool
    {
        try {
            // 🔍 Get existing template
            $existing = self::getTemplateByID($templateID);
            if (!$existing) {
                throw new RuntimeException("Template not found");
            }

            // 📝 Extract variables
            $subject = $templateData['subject'] ?? $existing['subject'];
            $bodyText = $templateData['bodyText'] ?? $existing['bodyText'];
            $bodyHTML = $templateData['bodyHTML'] ?? $existing['bodyHTML'];

            $requiredVars = self::extractVariables($subject . ' ' . $bodyText . ' ' . $bodyHTML);

            $query = "
                UPDATE tblEmailTemplates SET
                    templateName = ?,
                    description = ?,
                    subject = ?,
                    bodyHTML = ?,
                    bodyText = ?,
                    fromEmail = ?,
                    fromName = ?,
                    replyTo = ?,
                    defaultAttachments = ?,
                    category = ?,
                    isActive = ?,
                    trackingEnabled = ?,
                    requiredVariables = ?,
                    version = version + 1,
                    updatedBy = ?
                WHERE templateID = ?
            ";

            $success = Database::query($query, [
                $templateData['templateName'] ?? $existing['templateName'],
                $templateData['description'] ?? $existing['description'],
                $subject,
                $bodyHTML,
                $bodyText,
                $templateData['fromEmail'] ?? $existing['fromEmail'],
                $templateData['fromName'] ?? $existing['fromName'],
                $templateData['replyTo'] ?? $existing['replyTo'],
                isset($templateData['defaultAttachments']) ? json_encode($templateData['defaultAttachments']) : $existing['defaultAttachments'],
                $templateData['category'] ?? $existing['category'],
                $templateData['isActive'] ?? $existing['isActive'],
                $templateData['trackingEnabled'] ?? $existing['trackingEnabled'],
                json_encode(array_values($requiredVars)),
                $userID,
                $templateID
            ], 'ssssssssssissii');

            // 📝 Log activity
            if ($success && $userID) {
                ActivityLogger::log(
                    $userID,
                    'template_updated',
                    'email',
                    'info',
                    "Updated email template: {$existing['templateName']}",
                    ['template_id' => $templateID]
                );
            }

            return (bool)$success;

        } catch (Exception $e) {
            error_log("Failed to update email template: " . $e->getMessage());
            ErrorLogger::logError('Exception', $e->getMessage(), $e->getFile(), $e->getLine());
            return false;
        }
    }

    /**
     * 🗑️ Delete Template
     *
     * Soft deletes a template by marking it as inactive.
     *
     * @param int $templateID Template ID
     * @param int|null $userID User deleting the template
     * @return bool Success status
     */
    public static function deleteTemplate(int $templateID, ?int $userID = null): bool
    {
        try {
            // 🔍 Get template info for logging
            $template = self::getTemplateByID($templateID);

            $query = "UPDATE tblEmailTemplates SET isActive = FALSE WHERE templateID = ?";
            $success = Database::query($query, [$templateID], 'i');

            // 📝 Log activity
            if ($success && $userID && $template) {
                ActivityLogger::log(
                    $userID,
                    'template_deleted',
                    'email',
                    'info',
                    "Deleted email template: {$template['templateName']}",
                    ['template_id' => $templateID]
                );
            }

            return (bool)$success;

        } catch (Exception $e) {
            error_log("Failed to delete email template: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🎨 Render Template
     *
     * Renders template with provided variables.
     *
     * @param string $templateKey Template key
     * @param array $variables Variables to replace
     * @return array|null Rendered template with subject, bodyHTML, bodyText, or null on error
     */
    public static function renderTemplate(string $templateKey, array $variables): ?array
    {
        try {
            // 🔍 Get template
            $template = self::getTemplateByKey($templateKey);
            if (!$template) {
                throw new RuntimeException("Template not found: {$templateKey}");
            }

            // ✅ Validate required variables
            $requiredVars = !empty($template['requiredVariables']) ? json_decode($template['requiredVariables'], true) : [];
            $missingVars = array_diff($requiredVars, array_keys($variables));

            if (!empty($missingVars)) {
                throw new RuntimeException("Missing required variables: " . implode(', ', $missingVars));
            }

            // 🔄 Replace variables
            $rendered = [
                'subject' => self::replaceVariables($template['subject'], $variables),
                'bodyHTML' => $template['bodyHTML'] ? self::replaceVariables($template['bodyHTML'], $variables) : null,
                'bodyText' => self::replaceVariables($template['bodyText'], $variables),
                'fromEmail' => $template['fromEmail'],
                'fromName' => $template['fromName'],
                'replyTo' => $template['replyTo'],
                'trackingEnabled' => (bool)$template['trackingEnabled']
            ];

            return $rendered;

        } catch (Exception $e) {
            error_log("Failed to render template: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 👁️ Preview Template
     *
     * Generates a preview of the template with sample data.
     *
     * @param int $templateID Template ID
     * @param array $sampleData Sample variable data (optional)
     * @return array|null Preview data or null on error
     */
    public static function previewTemplate(int $templateID, array $sampleData = []): ?array
    {
        try {
            // 🔍 Get template
            $template = self::getTemplateByID($templateID);
            if (!$template) {
                return null;
            }

            // 📋 Get required variables
            $requiredVars = !empty($template['requiredVariables']) ? json_decode($template['requiredVariables'], true) : [];

            // 🎨 Generate sample data for missing variables
            $variables = $sampleData;
            foreach ($requiredVars as $var) {
                if (!isset($variables[$var])) {
                    $variables[$var] = self::generateSampleData($var);
                }
            }

            // 🔄 Replace variables
            $preview = [
                'templateID' => $templateID,
                'templateName' => $template['templateName'],
                'subject' => self::replaceVariables($template['subject'], $variables),
                'bodyHTML' => $template['bodyHTML'] ? self::replaceVariables($template['bodyHTML'], $variables) : null,
                'bodyText' => self::replaceVariables($template['bodyText'], $variables),
                'variables' => $variables,
                'requiredVariables' => $requiredVars
            ];

            return $preview;

        } catch (Exception $e) {
            error_log("Failed to preview template: " . $e->getMessage());
            return null;
        }
    }

    /**
     * 🔄 Replace Variables in String
     *
     * Replaces {{variable}} placeholders with values.
     *
     * @param string $content Content with variables
     * @param array $variables Variable values
     * @return string Processed content
     */
    private static function replaceVariables(string $content, array $variables): string
    {
        foreach ($variables as $key => $value) {
            // 🔐 Escape HTML in variables for security (unless in HTML body)
            $escapedValue = is_string($value) ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
            $content = str_replace("{{" . $key . "}}", $escapedValue, $content);
        }

        return $content;
    }

    /**
     * 📝 Extract Variables from Template
     *
     * Extracts all {{variable}} placeholders from template content.
     *
     * @param string $content Template content
     * @return array Array of unique variable names
     */
    private static function extractVariables(string $content): array
    {
        $matches = [];
        preg_match_all('/\{\{([a-zA-Z0-9_]+)\}\}/', $content, $matches);

        return array_unique($matches[1] ?? []);
    }

    /**
     * 🎨 Generate Sample Data
     *
     * Generates sample data for template variables.
     *
     * @param string $variableName Variable name
     * @return string Sample value
     */
    private static function generateSampleData(string $variableName): string
    {
        // 📋 Common variable mappings
        $sampleData = [
            'displayName' => 'John Doe',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'user@example.com',
            'username' => 'johndoe',
            'verificationUrl' => 'https://signulo.id/verify-email?token=sample_token',
            'verificationCode' => '123456',
            'resetUrl' => 'https://signulo.id/reset-password?token=sample_token',
            'resetCode' => '789012',
            'loginUrl' => 'https://signulo.id/passwordless-login?token=sample_token',
            'loginCode' => '345678',
            'mfaCode' => '901234',
            'expiryMinutes' => '30',
            'companyName' => 'SIGNula',
            'supportEmail' => 'support@signulo.id',
            'currentYear' => date('Y')
        ];

        // 🔍 Return sample data or generic placeholder
        return $sampleData[$variableName] ?? "[{$variableName}]";
    }

    /**
     * ✅ Validate Template Variables
     *
     * Validates that all required variables are provided.
     *
     * @param string $templateKey Template key
     * @param array $variables Variables to validate
     * @return array Validation result with 'valid' and 'errors' keys
     */
    public static function validateVariables(string $templateKey, array $variables): array
    {
        $template = self::getTemplateByKey($templateKey);

        if (!$template) {
            return [
                'valid' => false,
                'errors' => ['Template not found']
            ];
        }

        $requiredVars = !empty($template['requiredVariables']) ? json_decode($template['requiredVariables'], true) : [];
        $missingVars = array_diff($requiredVars, array_keys($variables));

        if (!empty($missingVars)) {
            return [
                'valid' => false,
                'errors' => ['Missing required variables: ' . implode(', ', $missingVars)]
            ];
        }

        return [
            'valid' => true,
            'errors' => []
        ];
    }
}

// ✅ EmailTemplateManager class loaded successfully
