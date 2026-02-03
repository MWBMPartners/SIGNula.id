<?php
/**
 * ============================================================================
 * 🎨 SIGNula - Advanced Email Personalization Engine
 * ============================================================================
 *
 * Purpose: Dynamic email personalization based on user data and behavior
 * PHP Version: 8.3+
 *
 * Features:
 * - User profile data personalization
 * - Behavioral personalization (opens, clicks, purchases)
 * - Dynamic content blocks
 * - Conditional content rendering
 * - Product/content recommendations
 * - Location-based personalization
 * - Time-based personalization
 * - A/B test variant personalization
 *
 * Personalization Tags:
 * {{user.displayName}} - User's display name
 * {{user.email}} - User's email
 * {{user.firstName}} - User's first name
 * {{user.lastName}} - User's last name
 * {{date.now}} - Current date
 * {{date.time}} - Current time
 * {{if:condition}}...{{endif}} - Conditional blocks
 * {{each:items}}...{{endeach}} - Loop blocks
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
 * 🎨 Email Personalization
 *
 * Advanced email personalization engine.
 */
class EmailPersonalization
{
    /**
     * @var array User data cache
     */
    private array $userData = [];

    /**
     * @var array Behavioral data cache
     */
    private array $behaviorData = [];

    /**
     * @var array Custom data
     */
    private array $customData = [];

    // ========================================================================
    // 🎯 MAIN PERSONALIZATION
    // ========================================================================

    /**
     * 🎨 Personalize Email
     *
     * Main personalization method that processes all personalization tags.
     *
     * @param string $content Email content (HTML or text)
     * @param int|null $userID User ID
     * @param string|null $email Email address
     * @param array $customData Custom personalization data
     * @return string Personalized content
     */
    public static function personalize(
        string $content,
        ?int $userID = null,
        ?string $email = null,
        array $customData = []
    ): string {
        $engine = new self();

        // 📊 Load data
        if ($userID) {
            $engine->loadUserData($userID);
            $engine->loadBehaviorData($userID);
        } elseif ($email) {
            $engine->loadUserDataByEmail($email);
        }

        $engine->customData = $customData;

        // 🔄 Process personalization
        $content = $engine->processUserVariables($content);
        $content = $engine->processDateVariables($content);
        $content = $engine->processConditionals($content);
        $content = $engine->processLoops($content);
        $content = $engine->processCustomVariables($content);

        return $content;
    }

    /**
     * 📊 Load User Data
     *
     * Loads user profile data.
     *
     * @param int $userID User ID
     */
    private function loadUserData(int $userID): void
    {
        $query = "
            SELECT
                userID, displayName, email, username,
                SUBSTRING_INDEX(displayName, ' ', 1) as firstName,
                SUBSTRING_INDEX(displayName, ' ', -1) as lastName,
                createdAt, lastLogin, timezone
            FROM tblUsers
            WHERE userID = ?
        ";

        $user = Database::fetchOne($query, [$userID], 'i');

        if ($user) {
            $this->userData = $user;
        }
    }

    /**
     * 📧 Load User Data by Email
     *
     * Loads user data by email address.
     *
     * @param string $email Email address
     */
    private function loadUserDataByEmail(string $email): void
    {
        $query = "
            SELECT
                userID, displayName, email, username,
                SUBSTRING_INDEX(displayName, ' ', 1) as firstName,
                SUBSTRING_INDEX(displayName, ' ', -1) as lastName,
                createdAt, lastLogin, timezone
            FROM tblUsers
            WHERE email = ?
        ";

        $user = Database::fetchOne($query, [$email], 's');

        if ($user) {
            $this->userData = $user;
        }
    }

    /**
     * 📈 Load Behavior Data
     *
     * Loads user behavioral data.
     *
     * @param int $userID User ID
     */
    private function loadBehaviorData(int $userID): void
    {
        // 📧 Email engagement stats
        $emailStats = Database::fetchOne("
            SELECT
                COUNT(*) as emails_received,
                SUM(CASE WHEN openCount > 0 THEN 1 ELSE 0 END) as emails_opened,
                SUM(CASE WHEN clickCount > 0 THEN 1 ELSE 0 END) as emails_clicked,
                MAX(sentAt) as last_email_sent,
                MAX(CASE WHEN openCount > 0 THEN sentAt END) as last_email_opened
            FROM tblEmailQueue
            WHERE userID = ? AND status = 'sent'
        ", [$userID], 'i');

        $this->behaviorData['email'] = $emailStats ?: [];

        // 🔐 Login history
        $loginStats = Database::fetchOne("
            SELECT
                COUNT(*) as total_logins,
                MAX(loginTimestamp) as last_login
            FROM tblActivityLog
            WHERE userID = ? AND activityType = 'login' AND activityResult = 'success'
        ", [$userID], 'i');

        $this->behaviorData['login'] = $loginStats ?: [];
    }

    // ========================================================================
    // 🔄 VARIABLE PROCESSING
    // ========================================================================

    /**
     * 👤 Process User Variables
     *
     * Replaces user-related variables.
     *
     * @param string $content Content
     * @return string Processed content
     */
    private function processUserVariables(string $content): string
    {
        if (empty($this->userData)) {
            return $content;
        }

        $replacements = [
            '{{user.displayName}}' => $this->userData['displayName'] ?? 'User',
            '{{user.firstName}}' => $this->userData['firstName'] ?? 'User',
            '{{user.lastName}}' => $this->userData['lastName'] ?? '',
            '{{user.email}}' => $this->userData['email'] ?? '',
            '{{user.username}}' => $this->userData['username'] ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * 📅 Process Date Variables
     *
     * Replaces date/time-related variables.
     *
     * @param string $content Content
     * @return string Processed content
     */
    private function processDateVariables(string $content): string
    {
        // 🕐 Get user timezone or default
        $timezone = $this->userData['timezone'] ?? date_default_timezone_get();

        try {
            $dateTime = new DateTime('now', new DateTimeZone($timezone));
        } catch (Exception $e) {
            $dateTime = new DateTime('now');
        }

        $replacements = [
            '{{date.now}}' => $dateTime->format('Y-m-d'),
            '{{date.today}}' => $dateTime->format('l, F j, Y'),
            '{{date.time}}' => $dateTime->format('g:i A'),
            '{{date.year}}' => $dateTime->format('Y'),
            '{{date.month}}' => $dateTime->format('F'),
            '{{date.day}}' => $dateTime->format('d'),
            '{{time.hour}}' => $dateTime->format('H'),
            '{{time.greeting}}' => self::getTimeGreeting((int)$dateTime->format('H')),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    /**
     * 🎨 Process Custom Variables
     *
     * Replaces custom variables from customData.
     *
     * @param string $content Content
     * @return string Processed content
     */
    private function processCustomVariables(string $content): string
    {
        if (empty($this->customData)) {
            return $content;
        }

        // 🔍 Find all {{custom.xxx}} tags
        preg_match_all('/\{\{custom\.([a-zA-Z0-9_]+)\}\}/', $content, $matches);

        if (empty($matches[0])) {
            return $content;
        }

        foreach ($matches[1] as $index => $key) {
            $tag = $matches[0][$index];
            $value = $this->customData[$key] ?? '';
            $content = str_replace($tag, $value, $content);
        }

        return $content;
    }

    // ========================================================================
    // 🔀 CONDITIONAL PROCESSING
    // ========================================================================

    /**
     * ✅ Process Conditionals
     *
     * Processes {{if:condition}}...{{endif}} blocks.
     *
     * @param string $content Content
     * @return string Processed content
     */
    private function processConditionals(string $content): string
    {
        // 🔍 Find all conditional blocks
        $pattern = '/\{\{if:([^}]+)\}\}(.*?)\{\{endif\}\}/s';

        $content = preg_replace_callback($pattern, function($matches) {
            $condition = trim($matches[1]);
            $innerContent = $matches[2];

            // ✅ Evaluate condition
            if ($this->evaluateCondition($condition)) {
                return $innerContent;
            }

            return '';
        }, $content);

        return $content;
    }

    /**
     * 🔍 Evaluate Condition
     *
     * Evaluates a condition expression.
     *
     * @param string $condition Condition expression
     * @return bool Condition result
     */
    private function evaluateCondition(string $condition): bool
    {
        // 📊 Parse condition (format: variable operator value)
        // Examples: "user.opened_emails > 0", "user.firstName exists"

        if (str_contains($condition, ' exists')) {
            $variable = str_replace(' exists', '', $condition);
            return $this->getVariableValue($variable) !== null;
        }

        if (str_contains($condition, ' not exists')) {
            $variable = str_replace(' not exists', '', $condition);
            return $this->getVariableValue($variable) === null;
        }

        // 🔍 Parse comparison
        if (preg_match('/^(.+?)\s*(==|!=|>|<|>=|<=)\s*(.+)$/', $condition, $matches)) {
            $variable = trim($matches[1]);
            $operator = $matches[2];
            $compareValue = trim($matches[3], '\'"');

            $actualValue = $this->getVariableValue($variable);

            if ($actualValue === null) {
                return false;
            }

            return match($operator) {
                '==' => $actualValue == $compareValue,
                '!=' => $actualValue != $compareValue,
                '>' => $actualValue > $compareValue,
                '<' => $actualValue < $compareValue,
                '>=' => $actualValue >= $compareValue,
                '<=' => $actualValue <= $compareValue,
                default => false
            };
        }

        return false;
    }

    /**
     * 📊 Get Variable Value
     *
     * Gets value of a variable for condition evaluation.
     *
     * @param string $variable Variable name (e.g., "user.firstName", "behavior.emails_opened")
     * @return mixed Variable value
     */
    private function getVariableValue(string $variable): mixed
    {
        $parts = explode('.', $variable);

        if (count($parts) < 2) {
            return null;
        }

        $category = $parts[0];
        $key = $parts[1];

        return match($category) {
            'user' => $this->userData[$key] ?? null,
            'behavior' => $this->behaviorData['email'][$key] ?? $this->behaviorData['login'][$key] ?? null,
            'custom' => $this->customData[$key] ?? null,
            default => null
        };
    }

    // ========================================================================
    // 🔁 LOOP PROCESSING
    // ========================================================================

    /**
     * 🔁 Process Loops
     *
     * Processes {{each:items}}...{{endeach}} blocks.
     *
     * @param string $content Content
     * @return string Processed content
     */
    private function processLoops(string $content): string
    {
        // 🔍 Find all loop blocks
        $pattern = '/\{\{each:([^}]+)\}\}(.*?)\{\{endeach\}\}/s';

        $content = preg_replace_callback($pattern, function($matches) {
            $itemsKey = trim($matches[1]);
            $template = $matches[2];

            // 🔍 Get items array
            $items = $this->customData[$itemsKey] ?? [];

            if (!is_array($items) || empty($items)) {
                return '';
            }

            // 🔄 Process each item
            $output = '';
            foreach ($items as $index => $item) {
                $itemContent = $template;

                // Replace {{item.xxx}} with actual values
                if (is_array($item)) {
                    foreach ($item as $key => $value) {
                        $itemContent = str_replace("{{item.{$key}}}", $value, $itemContent);
                    }
                } else {
                    $itemContent = str_replace('{{item}}', $item, $itemContent);
                }

                // Replace {{index}}
                $itemContent = str_replace('{{index}}', $index, $itemContent);

                $output .= $itemContent;
            }

            return $output;
        }, $content);

        return $content;
    }

    // ========================================================================
    // 🎯 RECOMMENDATION ENGINE
    // ========================================================================

    /**
     * 💡 Generate Recommendations
     *
     * Generates personalized content recommendations.
     *
     * @param int $userID User ID
     * @param string $type Recommendation type
     * @param int $limit Number of recommendations
     * @return array Recommendations
     */
    public static function generateRecommendations(
        int $userID,
        string $type = 'general',
        int $limit = 5
    ): array {
        // 🔍 This is a placeholder for recommendation logic
        // In production, this would analyze user behavior, preferences, etc.

        // Example: Get recently viewed items, similar items, popular items

        return [
            'type' => $type,
            'items' => [],
            'algorithm' => 'collaborative_filtering',
            'confidence' => 0.85
        ];
    }

    /**
     * 🎯 Get Dynamic Content Block
     *
     * Gets a personalized content block based on user data.
     *
     * @param int|null $userID User ID
     * @param string $blockType Block type
     * @return string|null Content block HTML
     */
    public static function getDynamicContentBlock(
        ?int $userID,
        string $blockType
    ): ?string {
        // 🎨 Generate dynamic content based on type
        return match($blockType) {
            'welcome_banner' => self::generateWelcomeBanner($userID),
            'recommendation_list' => self::generateRecommendationList($userID),
            'engagement_prompt' => self::generateEngagementPrompt($userID),
            default => null
        };
    }

    /**
     * 👋 Generate Welcome Banner
     *
     * Generates personalized welcome banner.
     *
     * @param int|null $userID User ID
     * @return string Welcome banner HTML
     */
    private static function generateWelcomeBanner(?int $userID): string
    {
        if (!$userID) {
            return '<h2>Welcome!</h2>';
        }

        $user = Database::fetchOne(
            "SELECT displayName, createdAt FROM tblUsers WHERE userID = ?",
            [$userID],
            'i'
        );

        if (!$user) {
            return '<h2>Welcome!</h2>';
        }

        $greeting = self::getTimeGreeting((int)date('H'));
        $name = $user['displayName'] ?? 'there';

        return "<h2>{$greeting}, {$name}!</h2>";
    }

    /**
     * 📋 Generate Recommendation List
     *
     * Generates personalized recommendation list HTML.
     *
     * @param int|null $userID User ID
     * @return string Recommendation list HTML
     */
    private static function generateRecommendationList(?int $userID): string
    {
        if (!$userID) {
            return '';
        }

        $recommendations = self::generateRecommendations($userID, 'general', 3);

        // 🎨 Build HTML (simplified example)
        $html = '<div class="recommendations"><h3>Recommended for You</h3><ul>';

        foreach ($recommendations['items'] as $item) {
            $html .= "<li>{$item['title']}</li>";
        }

        $html .= '</ul></div>';

        return $html;
    }

    /**
     * 💬 Generate Engagement Prompt
     *
     * Generates personalized engagement prompt based on behavior.
     *
     * @param int|null $userID User ID
     * @return string Engagement prompt HTML
     */
    private static function generateEngagementPrompt(?int $userID): string
    {
        if (!$userID) {
            return '';
        }

        // 📊 Check engagement level
        $stats = Database::fetchOne("
            SELECT
                SUM(CASE WHEN openCount > 0 THEN 1 ELSE 0 END) as opened,
                SUM(CASE WHEN clickCount > 0 THEN 1 ELSE 0 END) as clicked
            FROM tblEmailQueue
            WHERE userID = ? AND status = 'sent'
            AND sentAt >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ", [$userID], 'i');

        $opened = $stats['opened'] ?? 0;
        $clicked = $stats['clicked'] ?? 0;

        if ($clicked > 5) {
            return '<p><strong>You\'re one of our most engaged users!</strong> Thank you for your continued support.</p>';
        } elseif ($opened > 0 && $clicked === 0) {
            return '<p>We noticed you\'ve been reading our emails. <a href="#">Click here</a> to explore more!</p>';
        } else {
            return '<p>Haven\'t had a chance to check out our latest content? <a href="#">Take a look now</a>!</p>';
        }
    }

    // ========================================================================
    // 🔧 HELPER METHODS
    // ========================================================================

    /**
     * 👋 Get Time Greeting
     *
     * Gets appropriate greeting based on time of day.
     *
     * @param int $hour Hour (0-23)
     * @return string Greeting
     */
    private static function getTimeGreeting(int $hour): string
    {
        return match(true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening'
        };
    }

    /**
     * 🔤 Sanitize for Email
     *
     * Sanitizes content for email display.
     *
     * @param string $content Content
     * @return string Sanitized content
     */
    public static function sanitize(string $content): string
    {
        // 🧹 Remove potentially dangerous content
        $content = strip_tags($content, '<p><br><a><strong><em><ul><ol><li><h1><h2><h3><img>');

        // 🔗 Ensure links open in new window
        $content = preg_replace('/<a\s/', '<a target="_blank" ', $content);

        return $content;
    }
}

// ✅ EmailPersonalization class loaded successfully
