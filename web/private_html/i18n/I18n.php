<?php
/**
 * ============================================================================
 * 🌐 SIGNula — Internationalisation (i18n) Framework
 * ============================================================================
 *
 * Purpose: Provides a lightweight, file-based translation system for the
 *          SIGNula application. Translations are stored as PHP arrays in
 *          domain-specific files organised by locale.
 *
 * Features:
 *   - Domain-based translation loading (auth, errors, messages, settings, etc.)
 *   - Parameter substitution (:name, :count, etc.)
 *   - Simple pluralisation via pipe-separated strings
 *   - Automatic browser locale detection from Accept-Language header
 *   - User preference locale persistence (tblUserPreferences)
 *   - Fallback chain: requested locale -> fallback locale -> raw key
 *   - Lazy-loading of translation files (loaded on first access per domain)
 *
 * Directory structure:
 *   web/private_html/i18n/lang/{locale}/{domain}.php
 *
 * Usage:
 *   I18n::init();                                      // Auto-detect locale
 *   I18n::init('fr');                                   // Force French
 *   echo I18n::translate('welcome', ['name' => 'John']); // "Welcome, John!"
 *   echo I18n::translate('auth.sign_in');               // "Sign In"
 *   echo I18n::translate('errors.not_found');            // "Page not found."
 *
 * @package    SIGNula\i18n
 * @version    1.0.0
 * @see        https://www.php.net/manual/en/function.locale-accept-from-http.php
 * @see        https://www.rfc-editor.org/rfc/rfc7231#section-5.3.5 - Accept-Language
 * @see        https://www.w3.org/International/questions/qa-choosing-language-tags
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
// @see https://www.php.net/manual/en/function.defined.php
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

/**
 * I18n — Static internationalisation service
 *
 * All methods are static, consistent with the SIGNula utility class pattern.
 * Translation files are lazy-loaded on first access to minimise memory usage.
 *
 * Detection priority for locale (first match wins):
 *   1. Explicit parameter passed to init()
 *   2. User preference stored in tblUserPreferences (key: 'i18n.locale')
 *   3. Session variable ($_SESSION['locale'])
 *   4. Browser Accept-Language header (if i18n.detect_browser_locale is enabled)
 *   5. Default locale from settings (getSetting('i18n.default_locale', 'en'))
 */
class I18n
{
    // ========================================================================
    // 🔧 Properties
    // ========================================================================

    /**
     * @var string Current active locale code (e.g., 'en', 'fr', 'de')
     */
    private static string $locale = 'en';

    /**
     * @var string Fallback locale used when a translation key is missing
     *             in the current locale
     */
    private static string $fallbackLocale = 'en';

    /**
     * @var array<string, array<string, string>> Loaded translations indexed by
     *      "{locale}.{domain}" => ['key' => 'value', ...]
     */
    private static array $translations = [];

    /**
     * @var array<string, bool> Track which domain+locale combos have been loaded
     *      to avoid redundant file reads. Key format: "{locale}.{domain}"
     */
    private static array $loadedDomains = [];

    /**
     * @var bool Whether the i18n system has been initialised
     */
    private static bool $initialized = false;

    /**
     * @var array<string> Cached list of available locale codes
     */
    private static array $availableLocales = [];

    // ========================================================================
    // 🚀 Initialisation
    // ========================================================================

    /**
     * Initialise the i18n system
     *
     * Determines the active locale using a priority chain:
     *   1. Explicit $locale parameter
     *   2. User preference from tblUserPreferences (key: 'i18n.locale')
     *   3. Session variable ($_SESSION['locale'])
     *   4. Browser Accept-Language header (if enabled in settings)
     *   5. Default locale from tblSettings ('i18n.default_locale')
     *
     * @param string|null $locale Optional locale code to force (e.g., 'en', 'fr')
     *
     * @return void
     *
     * @see https://www.php.net/manual/en/reserved.variables.session.php
     */
    public static function init(?string $locale = null): void
    {
        // 📦 Load configuration from database settings
        // @see web/_config/config.php - getSetting() function
        if (function_exists('getSetting')) {
            self::$fallbackLocale = getSetting('i18n.fallback_locale', 'en');

            // 🌍 Parse available locales from comma-separated setting
            $availableStr = getSetting('i18n.available_locales', 'en');
            self::$availableLocales = array_map(
                'trim',
                explode(',', $availableStr)
            );
        } else {
            // ⚠️ Fallback when getSetting is not available (e.g., during testing)
            self::$fallbackLocale = 'en';
            self::$availableLocales = ['en'];
        }

        // 🎯 Determine locale using priority chain
        $detectedLocale = null;

        // Priority 1: Explicit parameter
        if ($locale !== null && self::isValidLocale($locale)) {
            $detectedLocale = $locale;
        }

        // Priority 2: User preference from database
        // @see tblUserPreferences - key: 'i18n.locale'
        if ($detectedLocale === null && isset($_SESSION['userID']) && function_exists('getSetting')) {
            $detectedLocale = self::getUserPreferenceLocale((int) $_SESSION['userID']);
        }

        // Priority 3: Session variable
        if ($detectedLocale === null && isset($_SESSION['locale']) && self::isValidLocale($_SESSION['locale'])) {
            $detectedLocale = $_SESSION['locale'];
        }

        // Priority 4: Browser Accept-Language header
        // @see https://www.rfc-editor.org/rfc/rfc7231#section-5.3.5
        if ($detectedLocale === null) {
            $detectBrowser = function_exists('getSetting')
                ? getSetting('i18n.detect_browser_locale', '1')
                : '1';

            if ($detectBrowser === '1' || $detectBrowser === 'true') {
                $detectedLocale = self::detectBrowserLocale();
            }
        }

        // Priority 5: Default locale from settings
        if ($detectedLocale === null) {
            $detectedLocale = function_exists('getSetting')
                ? getSetting('i18n.default_locale', 'en')
                : 'en';
        }

        // ✅ Set the resolved locale
        self::$locale = $detectedLocale;

        // 💾 Persist locale in session for subsequent requests
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['locale'] = self::$locale;
        }

        self::$initialized = true;
    }

    // ========================================================================
    // 🔤 Translation
    // ========================================================================

    /**
     * Translate a key with optional parameter substitution
     *
     * Key format supports domain prefixing:
     *   - 'auth.sign_in'     -> loads auth.php, returns key 'sign_in'
     *   - 'errors.not_found' -> loads errors.php, returns key 'not_found'
     *   - 'welcome'          -> loads messages.php (default domain), returns key 'welcome'
     *   - 'auth.mfa.title'   -> loads auth.php, returns key 'mfa.title'
     *
     * Fallback chain:
     *   1. Current locale translation
     *   2. Fallback locale translation
     *   3. Raw key string (as last resort)
     *
     * @param string $key    Translation key, optionally prefixed with domain
     * @param array  $params Associative array of parameters to substitute
     *                       e.g., ['name' => 'John'] replaces :name
     *
     * @return string Translated string with parameters replaced
     *
     * @example I18n::translate('welcome', ['name' => 'John']) // "Welcome, John!"
     * @example I18n::translate('auth.sign_in')                // "Sign In"
     * @example I18n::translate('errors.not_found')            // "Page not found."
     */
    public static function translate(string $key, array $params = []): string
    {
        // 🔄 Auto-initialise if not yet done
        if (!self::$initialized) {
            self::init();
        }

        // 🗂️ Parse domain and translation key
        // Known domains are checked first; unrecognised prefixes fall through
        // to the default 'messages' domain
        [$domain, $translationKey] = self::parseDomainKey($key);

        // 🔍 Attempt translation in current locale
        $translation = self::findTranslation($domain, $translationKey, self::$locale);

        // 🔄 Fall back to fallback locale if not found
        if ($translation === null && self::$locale !== self::$fallbackLocale) {
            $translation = self::findTranslation($domain, $translationKey, self::$fallbackLocale);
        }

        // ⚠️ If still not found, return the raw key as a last resort
        if ($translation === null) {
            return $key;
        }

        // 📊 Handle simple pluralisation via pipe separator
        // Format: "one item|:count items" - uses :count param to select form
        // @see https://laravel.com/docs/localization#pluralization (similar concept)
        if (str_contains($translation, '|') && isset($params['count'])) {
            $translation = self::handlePluralisation($translation, (int) $params['count']);
        }

        // 🔀 Replace parameters in the translation string
        if (!empty($params)) {
            $translation = self::replaceParams($translation, $params);
        }

        return $translation;
    }

    // ========================================================================
    // 🌍 Locale Management
    // ========================================================================

    /**
     * Set the current locale
     *
     * Validates the locale against the list of available locales before setting.
     * Also persists the choice to the session.
     *
     * @param string $locale Locale code to set (e.g., 'en', 'fr', 'de')
     *
     * @return void
     *
     * @throws \InvalidArgumentException If the locale is not in the available list
     */
    public static function setLocale(string $locale): void
    {
        // ✅ Validate against available locales
        if (!self::isValidLocale($locale)) {
            throw new \InvalidArgumentException(
                "Locale '{$locale}' is not available. Available locales: "
                . implode(', ', self::getAvailableLocales())
            );
        }

        self::$locale = $locale;

        // 💾 Persist to session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['locale'] = $locale;
        }
    }

    /**
     * Get the current active locale code
     *
     * @return string Current locale (e.g., 'en')
     */
    public static function getLocale(): string
    {
        return self::$locale;
    }

    /**
     * Get the fallback locale code
     *
     * @return string Fallback locale (e.g., 'en')
     */
    public static function getFallbackLocale(): string
    {
        return self::$fallbackLocale;
    }

    /**
     * Get list of available locale codes
     *
     * @return array<string> Array of locale codes (e.g., ['en', 'fr', 'de'])
     */
    public static function getAvailableLocales(): array
    {
        // 🔄 Initialise if needed to load settings
        if (empty(self::$availableLocales)) {
            if (function_exists('getSetting')) {
                $availableStr = getSetting('i18n.available_locales', 'en');
                self::$availableLocales = array_map('trim', explode(',', $availableStr));
            } else {
                self::$availableLocales = ['en'];
            }
        }

        return self::$availableLocales;
    }

    /**
     * Check if a locale code is valid (exists in available locales)
     *
     * @param string $locale Locale code to validate
     *
     * @return bool True if the locale is available
     */
    public static function isValidLocale(string $locale): bool
    {
        return in_array($locale, self::getAvailableLocales(), true);
    }

    // ========================================================================
    // 📂 Translation Loading (Private)
    // ========================================================================

    /**
     * Load a translation file for a given domain and locale
     *
     * Translation files are PHP files that return associative arrays.
     * Path format: SIGNULA_ROOT/private_html/i18n/lang/{locale}/{domain}.php
     *
     * Files are loaded lazily (on first access) and cached in memory for the
     * duration of the request to avoid redundant file reads.
     *
     * @param string $domain Domain name (e.g., 'messages', 'auth', 'errors')
     * @param string $locale Locale code (e.g., 'en', 'fr')
     *
     * @return void
     *
     * @see https://www.php.net/manual/en/function.is-file.php
     */
    private static function loadTranslations(string $domain, string $locale): void
    {
        // 🔑 Generate a unique cache key for this domain+locale pair
        $cacheKey = $locale . '.' . $domain;

        // ⏭️ Skip if already loaded
        if (isset(self::$loadedDomains[$cacheKey])) {
            return;
        }

        // 📁 Build the path to the translation file
        // Uses DIRECTORY_SEPARATOR for cross-platform compatibility
        // @see https://www.php.net/manual/en/dir.constants.php
        $basePath = defined('SIGNULA_ROOT')
            ? SIGNULA_ROOT
            : dirname(__DIR__, 2);

        $filePath = $basePath
            . DIRECTORY_SEPARATOR . 'private_html'
            . DIRECTORY_SEPARATOR . 'i18n'
            . DIRECTORY_SEPARATOR . 'lang'
            . DIRECTORY_SEPARATOR . $locale
            . DIRECTORY_SEPARATOR . $domain . '.php';

        // 📖 Load the translation array from the file
        if (is_file($filePath) && is_readable($filePath)) {
            $translations = require $filePath;

            // ✅ Validate that the file returned an array
            if (is_array($translations)) {
                // 🔀 Merge into the translations store under the cache key
                if (!isset(self::$translations[$cacheKey])) {
                    self::$translations[$cacheKey] = [];
                }
                self::$translations[$cacheKey] = array_merge(
                    self::$translations[$cacheKey],
                    $translations
                );
            }
        }

        // 📝 Mark this domain+locale as loaded (even if file didn't exist,
        // to prevent repeated filesystem lookups)
        self::$loadedDomains[$cacheKey] = true;
    }

    /**
     * Find a translation for a key in a specific locale
     *
     * Ensures the relevant domain file is loaded, then looks up the key.
     *
     * @param string $domain Domain name (e.g., 'messages', 'auth')
     * @param string $key    Translation key within the domain
     * @param string $locale Locale code to search in
     *
     * @return string|null The translation string, or null if not found
     */
    private static function findTranslation(string $domain, string $key, string $locale): ?string
    {
        // 📂 Ensure translations for this domain+locale are loaded
        self::loadTranslations($domain, $locale);

        $cacheKey = $locale . '.' . $domain;

        // 🔍 Look up the key in the loaded translations
        return self::$translations[$cacheKey][$key] ?? null;
    }

    // ========================================================================
    // 🔧 Parsing & Substitution (Private)
    // ========================================================================

    /**
     * Parse a translation key into domain and key components
     *
     * Known domains (auth, errors, settings, admin, email, payments, api)
     * are detected from the first segment. Everything else defaults to
     * the 'messages' domain.
     *
     * Examples:
     *   'auth.sign_in'      -> ['auth', 'sign_in']
     *   'errors.not_found'  -> ['errors', 'not_found']
     *   'auth.mfa.title'    -> ['auth', 'mfa.title']
     *   'welcome'           -> ['messages', 'welcome']
     *   'nav.home'          -> ['messages', 'nav.home']
     *
     * @param string $key Full translation key
     *
     * @return array{0: string, 1: string} [domain, translationKey]
     */
    private static function parseDomainKey(string $key): array
    {
        // 📋 List of known translation domains
        // These correspond to files in the lang/{locale}/ directory
        $knownDomains = [
            'auth',
            'errors',
            'settings',
            'admin',
            'email',
            'payments',
            'api',
        ];

        // 🔍 Check if the key starts with a known domain prefix
        $dotPos = strpos($key, '.');
        if ($dotPos !== false) {
            $possibleDomain = substr($key, 0, $dotPos);
            if (in_array($possibleDomain, $knownDomains, true)) {
                // ✂️ Split into domain and remaining key
                return [$possibleDomain, substr($key, $dotPos + 1)];
            }
        }

        // 📌 Default to 'messages' domain
        return ['messages', $key];
    }

    /**
     * Replace parameter placeholders in a translation string
     *
     * Parameters are denoted with a colon prefix (e.g., :name, :count).
     * Both the original case and uppercase versions are replaced:
     *   :name  -> value
     *   :NAME  -> VALUE (uppercased)
     *
     * @param string $string Translation string with placeholders
     * @param array  $params Associative array of parameter values
     *
     * @return string String with all placeholders replaced
     *
     * @example replaceParams('Welcome, :name!', ['name' => 'John']) -> 'Welcome, John!'
     */
    private static function replaceParams(string $string, array $params): string
    {
        // 🔄 Build replacement pairs for both lowercase and uppercase variants
        $replacements = [];
        foreach ($params as $paramKey => $paramValue) {
            // Cast value to string for safe replacement
            $value = (string) $paramValue;

            // :name -> value (original case)
            $replacements[':' . $paramKey] = $value;

            // :NAME -> VALUE (uppercase version)
            $replacements[':' . strtoupper($paramKey)] = strtoupper($value);
        }

        // 🔀 Perform all replacements in one pass
        // @see https://www.php.net/manual/en/function.strtr.php
        return strtr($string, $replacements);
    }

    /**
     * Handle simple pluralisation using pipe-separated forms
     *
     * Format: "singular form|plural form"
     * The singular form is used when count === 1, plural form otherwise.
     *
     * @param string $string Pipe-separated translation string
     * @param int    $count  The count value for pluralisation
     *
     * @return string The appropriate plural form
     *
     * @example handlePluralisation(':count minute ago|:count minutes ago', 1) -> ':count minute ago'
     * @example handlePluralisation(':count minute ago|:count minutes ago', 5) -> ':count minutes ago'
     */
    private static function handlePluralisation(string $string, int $count): string
    {
        $parts = explode('|', $string);

        // 📊 Simple two-form pluralisation (singular|plural)
        if (count($parts) === 2) {
            return $count === 1 ? trim($parts[0]) : trim($parts[1]);
        }

        // 📊 Three-form pluralisation (zero|singular|plural)
        if (count($parts) === 3) {
            if ($count === 0) {
                return trim($parts[0]);
            }
            return $count === 1 ? trim($parts[1]) : trim($parts[2]);
        }

        // ⚠️ Fallback: return the last form
        return trim(end($parts));
    }

    // ========================================================================
    // 🌐 Browser Locale Detection (Private)
    // ========================================================================

    /**
     * Detect the best matching locale from the browser's Accept-Language header
     *
     * Parses the Accept-Language header, sorts by quality value (q-factor),
     * and returns the first locale that matches an available locale.
     *
     * Supports both full locale codes (e.g., 'en-GB') and language-only
     * codes (e.g., 'en'), matching against available locales.
     *
     * @return string|null Detected locale code, or null if no match found
     *
     * @see https://www.rfc-editor.org/rfc/rfc7231#section-5.3.5
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Accept-Language
     */
    private static function detectBrowserLocale(): ?string
    {
        // 🌐 Get the Accept-Language header from the request
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (empty($acceptLanguage)) {
            return null;
        }

        // 📝 Parse the header into locale => quality pairs
        // Format examples: "en-GB,en;q=0.9,fr;q=0.8", "de", "en-US,en;q=0.5"
        $locales = [];
        $entries = explode(',', $acceptLanguage);

        foreach ($entries as $entry) {
            $entry = trim($entry);
            if (empty($entry)) {
                continue;
            }

            // 🔍 Split locale from quality value
            $parts = explode(';', $entry);
            $localeCode = trim($parts[0]);
            $quality = 1.0; // Default quality is 1.0

            // 📊 Parse quality value if present (e.g., "q=0.9")
            if (isset($parts[1])) {
                $qPart = trim($parts[1]);
                if (str_starts_with($qPart, 'q=')) {
                    $quality = (float) substr($qPart, 2);
                }
            }

            $locales[$localeCode] = $quality;
        }

        // 📉 Sort by quality (highest first)
        arsort($locales);

        // 🔍 Find the first matching available locale
        $availableLocales = self::getAvailableLocales();

        foreach (array_keys($locales) as $browserLocale) {
            // ✅ Exact match (e.g., 'en' matches 'en')
            $normalised = strtolower(str_replace('-', '_', $browserLocale));
            if (in_array($normalised, $availableLocales, true)) {
                return $normalised;
            }

            // ✅ Language-only match (e.g., 'en-GB' matches 'en')
            $langOnly = strtolower(explode('-', $browserLocale)[0]);
            if (in_array($langOnly, $availableLocales, true)) {
                return $langOnly;
            }
        }

        return null;
    }

    // ========================================================================
    // 👤 User Preference (Private)
    // ========================================================================

    /**
     * Get the user's locale preference from tblUserPreferences
     *
     * Queries the database for the user's stored locale preference.
     * Returns null if no preference is set or if the stored locale
     * is not in the available locales list.
     *
     * @param int $userID The user's ID
     *
     * @return string|null The user's preferred locale, or null
     *
     * @see tblUserPreferences - key: 'i18n.locale'
     */
    private static function getUserPreferenceLocale(int $userID): ?string
    {
        try {
            // 📊 Query user preference from database
            // Uses the Database singleton if available
            if (class_exists('Database')) {
                $result = Database::fetchOne(
                    'SELECT preferenceValue FROM tblUserPreferences '
                    . 'WHERE userID = ? AND preferenceKey = ?',
                    [$userID, 'i18n.locale']
                );

                if ($result && !empty($result['preferenceValue'])) {
                    $userLocale = $result['preferenceValue'];
                    if (self::isValidLocale($userLocale)) {
                        return $userLocale;
                    }
                }
            }
        } catch (\Exception $e) {
            // ⚠️ Silently fail — locale detection should not break the app
            // The fallback chain will handle this gracefully
        }

        return null;
    }

    // ========================================================================
    // 🧹 Utility Methods
    // ========================================================================

    /**
     * Reset the i18n system state
     *
     * Clears all loaded translations and resets to defaults.
     * Primarily useful for testing purposes.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$locale = 'en';
        self::$fallbackLocale = 'en';
        self::$translations = [];
        self::$loadedDomains = [];
        self::$initialized = false;
        self::$availableLocales = [];
    }

    /**
     * Check if the i18n system has been initialised
     *
     * @return bool True if init() has been called
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Check if a translation key exists in the current locale
     *
     * Checks both the current locale and the fallback locale.
     *
     * @param string $key Translation key (with optional domain prefix)
     *
     * @return bool True if a translation exists for the key
     */
    public static function has(string $key): bool
    {
        if (!self::$initialized) {
            self::init();
        }

        [$domain, $translationKey] = self::parseDomainKey($key);

        // 🔍 Check current locale
        if (self::findTranslation($domain, $translationKey, self::$locale) !== null) {
            return true;
        }

        // 🔍 Check fallback locale
        if (self::$locale !== self::$fallbackLocale) {
            return self::findTranslation($domain, $translationKey, self::$fallbackLocale) !== null;
        }

        return false;
    }
}
