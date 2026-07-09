<?php
/**
 * ============================================================================
 * 🪪 SIGNula - OAuth2/OIDC Provider: Client Registration Manager (G-001 Stage A1)
 * ============================================================================
 *
 * Purpose:
 *   Owns the lifecycle of Relying-Party (RP) OAuth/OIDC clients — the apps that
 *   integrate "Sign in with SIGNula.id". Stage A1 ONLY: registration, secret
 *   hashing/rotation, the redirect-URI EXACT-MATCH allowlist, scope checks, and
 *   the pairwise-subject helper used by later stages. This class does NOT talk
 *   to /oauth/authorize or /oauth/token — those are Stage A2/A3 and consume the
 *   data this class manages.
 *
 * Security properties (see .dev-team/specs/G-001.md §5 — all mandatory):
 *   • client_secret is NEVER stored in plaintext — only hash('sha256', $secret),
 *     exactly the APIKeyManager::generateKey() precedent (mig 008). The
 *     plaintext is returned to the caller ONCE (at registration/rotation) and
 *     never persisted or logged again.
 *   • verifyClientSecret() uses hash_equals() (constant-time) and refuses to
 *     "verify" a secret against a PUBLIC client (confidential/public confusion
 *     defence — a public client can never authenticate with a secret).
 *   • validateRedirectUri() / isExactRedirectMatch() do a BYTE-EXACT PHP string
 *     comparison (strict ===) — no prefix/substring/wildcard/normalisation of
 *     any kind. This is the #1 OAuth IdP security control (covert-redirect /
 *     token-theft defence). The backing table also uses a byte-sensitive
 *     collation (utf8mb4_bin) as DB-level defence-in-depth, but correctness
 *     never depends on that — the PHP comparison is authoritative.
 *   • computeSubject() implements OIDC pairwise subject identifiers: a per-RP
 *     opaque `sub` so colluding RPs cannot correlate the same user via a shared
 *     raw userID. The salt is a server-side secret, minted at runtime (never
 *     hardcoded), encrypted at rest — the KeyManager::generateKey() precedent.
 *
 * Testability:
 *   registerClient()/rotateSecret()/getClient()/listClients()/deactivate() are
 *   DB-bound (Integration tests, DatabaseTestCase). isExactRedirectMatch(),
 *   scopesAllowed(), and computeSubject() (given an explicit $pairwiseSalt) are
 *   pure functions with NO database access — Unit-tested directly.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4).
 *
 * @package    SIGNula
 * @subpackage Auth
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Stage A1)
 * @link       https://datatracker.ietf.org/doc/html/rfc6749 (OAuth 2.0)
 * @link       https://openid.net/specs/openid-connect-core-1_0.html#PairwiseAlg (pairwise sub)
 *
 * @see web/private_html/api/APIKeyManager.php  (the manager-class idiom this mirrors)
 * @see web/private_html/security/KeyManager.php (the "mint a secret at runtime, never
 *      hardcode it in a migration" precedent this class follows for the pairwise salt)
 * @see _database/migrations/031_oauth_provider_clients.sql
 * @see .dev-team/specs/G-001.md §4, §5
 * @see .dev-team/specs/G-001-integration-plan.md §2 "A1"
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

/**
 * 🪪 OAuth2/OIDC Relying-Party client registration manager.
 *
 * All methods are static (matches the SecurityUtils / KeyManager / MFA house
 * style — see the classes referenced above).
 */
class OAuthClientManager
{
    /** @var int Random bytes for the public client_id — 16 bytes = 32 hex chars. */
    private const CLIENT_ID_BYTES = 16;

    /** @var int Random bytes for the plaintext client_secret — 32 bytes = 64 hex chars (256-bit). */
    private const CLIENT_SECRET_BYTES = 32;

    /** @var int Random bytes for the pairwise-subject server salt. */
    private const PAIRWISE_SALT_BYTES = 32;

    /** @var string tblSettings key holding the (encrypted) pairwise-subject salt. */
    private const PAIRWISE_SALT_SETTING_KEY = 'oauth.pairwise_salt';

    /** @var string[] Valid tblOAuthClients.clientType ENUM values. */
    private const VALID_CLIENT_TYPES = ['confidential', 'public'];

    /** @var string[] Valid tblOAuthClients.subjectType ENUM values. */
    private const VALID_SUBJECT_TYPES = ['public', 'pairwise'];

    /** @var int Max length of tblOAuthClients.clientName (VARCHAR(150)). */
    private const MAX_CLIENT_NAME_LENGTH = 150;

    /** @var int Max length of a redirect_uri (VARCHAR(500) columns). */
    private const MAX_REDIRECT_URI_LENGTH = 500;

    // ========================================================================
    // 📝 REGISTRATION
    // ========================================================================

    /**
     * 📝 Register a new OAuth/OIDC client.
     *
     * Generates the public `client_id`, and — for confidential clients — a
     * plaintext `client_secret` (returned ONCE; only its SHA-256 hash is
     * persisted). Inserts the client row and its redirect-URI allowlist inside
     * a single transaction so the two can never be left inconsistent.
     *
     * @param int|null $partnerID     Owning partner, or null for a first-party/
     *                                global SIGNula-owned client.
     * @param string   $clientName    Human-readable name (shown on consent screen).
     * @param string   $clientType    'confidential' or 'public'.
     * @param string[] $redirectUris  One or more exact redirect_uri values
     *                                (at least one required).
     * @param string[] $allowedScopes One or more scope strings this client may
     *                                request (e.g. ['openid','profile','email']).
     * @param array<string,mixed> $opts Optional overrides:
     *   - isFirstParty     (bool)        default false
     *   - pkceRequired     (bool)        default true (ALWAYS true for public clients)
     *   - grantTypes       (string[])    default ['authorization_code','refresh_token']
     *   - subjectType      (string)      'public'|'pairwise', default 'pairwise'
     *   - sectorIdentifier (string|null) default: derived from the first redirect_uri's host
     *   - logoURI          (string|null)
     *   - clientURI        (string|null)
     *   - tosURI           (string|null)
     *   - policyURI        (string|null)
     *   - createdBy        (int|null)    userID performing the registration (audit)
     * @return array{clientID:int,clientIdentifier:string,clientSecret:?string,client:array}|false
     *         `clientSecret` is the PLAINTEXT secret (confidential clients
     *         only) — shown here ONCE and never retrievable again. `false` on
     *         any validation failure.
     */
    public static function registerClient(
        ?int $partnerID,
        string $clientName,
        string $clientType,
        array $redirectUris,
        array $allowedScopes,
        array $opts = []
    ): array|false {
        // 🛡️ Validate clientType.
        if (!in_array($clientType, self::VALID_CLIENT_TYPES, true)) {
            error_log("OAuthClientManager::registerClient — invalid clientType '{$clientType}'");
            return false;
        }

        // 🛡️ Validate + sanitise clientName.
        $clientName = trim(SecurityUtils::sanitizeString($clientName));
        if ($clientName === '' || strlen($clientName) > self::MAX_CLIENT_NAME_LENGTH) {
            error_log('OAuthClientManager::registerClient — invalid clientName');
            return false;
        }

        // 🛡️ Validate redirect URIs: at least one, each well-formed, deduped.
        $redirectUris = self::normaliseRedirectUris($redirectUris);
        if (empty($redirectUris)) {
            error_log('OAuthClientManager::registerClient — no valid redirect_uri supplied');
            return false;
        }

        // 🛡️ Validate scopes: at least one non-empty scope token.
        $scopeString = self::joinScopeList($allowedScopes);
        if ($scopeString === '') {
            error_log('OAuthClientManager::registerClient — no allowedScopes supplied');
            return false;
        }
        if (strlen($scopeString) > 512) {
            error_log('OAuthClientManager::registerClient — allowedScopes exceeds column length');
            return false;
        }

        // 🔧 Resolve options with house defaults.
        $isFirstParty = (bool) ($opts['isFirstParty'] ?? false);

        // 🔐 PKCE is ALWAYS required for public clients — no opt-out, per
        //    G-001.md §5.2 ("PKCE required... mandatory for public clients").
        $pkceRequired = $clientType === 'public' ? true : (bool) ($opts['pkceRequired'] ?? true);

        $grantTypesList = $opts['grantTypes'] ?? ['authorization_code', 'refresh_token'];
        $grantTypesString = self::joinScopeList((array) $grantTypesList);
        if ($grantTypesString === '') {
            $grantTypesString = 'authorization_code refresh_token';
        }

        $subjectType = (string) ($opts['subjectType'] ?? 'pairwise');
        if (!in_array($subjectType, self::VALID_SUBJECT_TYPES, true)) {
            $subjectType = 'pairwise';
        }

        // 🕵️ Default sector identifier: the host of the first redirect_uri
        //    (the OIDC-conventional default when no explicit
        //    sector_identifier_uri is registered) — falls back to null if the
        //    URI has no parseable host (e.g. a bare custom scheme).
        $sectorIdentifier = isset($opts['sectorIdentifier']) && $opts['sectorIdentifier'] !== null
            ? trim((string) $opts['sectorIdentifier'])
            : (parse_url($redirectUris[0], PHP_URL_HOST) ?: null);
        if ($sectorIdentifier === '') {
            $sectorIdentifier = null;
        }

        $logoURI   = self::normaliseOptionalUri($opts['logoURI'] ?? null);
        $clientURI = self::normaliseOptionalUri($opts['clientURI'] ?? null);
        $tosURI    = self::normaliseOptionalUri($opts['tosURI'] ?? null);
        $policyURI = self::normaliseOptionalUri($opts['policyURI'] ?? null);

        $createdBy = isset($opts['createdBy']) ? (int) $opts['createdBy'] : null;

        // 🆔 Generate the public client_id (collision-checked).
        $clientIdentifier = self::generateClientIdentifier();

        // 🔑 Confidential clients get a plaintext secret (shown once); public
        //    clients have NO usable secret at all — enforced at issuance, not
        //    just at verification time, per G-001.md §5.6/§3.A "public...no
        //    usable secret".
        $plainSecret = null;
        $secretHash  = null;
        if ($clientType === 'confidential') {
            $plainSecret = self::generateClientSecret();
            $secretHash  = self::hashSecret($plainSecret);
        }

        // 💾 Insert client + redirect URIs inside one transaction.
        Database::beginTransaction();
        try {
            Database::query(
                "INSERT INTO tblOAuthClients (
                    clientIdentifier, clientSecretHash, clientName, clientType,
                    partnerID, isFirstParty, pkceRequired, allowedScopes, grantTypes,
                    subjectType, sectorIdentifier, logoURI, clientURI, tosURI, policyURI,
                    createdBy
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $clientIdentifier,
                    $secretHash,
                    $clientName,
                    $clientType,
                    $partnerID,
                    $isFirstParty ? 1 : 0,
                    $pkceRequired ? 1 : 0,
                    $scopeString,
                    $grantTypesString,
                    $subjectType,
                    $sectorIdentifier,
                    $logoURI,
                    $clientURI,
                    $tosURI,
                    $policyURI,
                    $createdBy,
                ],
                'ssssiiissssssssi'
            );

            $clientID = Database::getLastInsertId();

            foreach ($redirectUris as $uri) {
                Database::query(
                    "INSERT INTO tblOAuthClientRedirectUris (clientID, redirectURI) VALUES (?, ?)",
                    [$clientID, $uri],
                    'is'
                );
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollback();
            error_log('OAuthClientManager::registerClient — transaction failed: ' . $e->getMessage());
            return false;
        }

        self::logEvent(
            'oauth_client_registered',
            $createdBy,
            "OAuth client \"{$clientName}\" registered ({$clientType})",
            ['clientID' => $clientID, 'clientIdentifier' => $clientIdentifier, 'partnerID' => $partnerID]
        );

        return [
            'clientID'         => $clientID,
            'clientIdentifier' => $clientIdentifier,
            'clientSecret'     => $plainSecret, // 🔒 PLAINTEXT — shown ONCE, never retrievable again.
            'client'           => self::getClientByID($clientID),
        ];
    }

    /**
     * 🔄 Rotate a confidential client's secret (old secret is invalidated
     * immediately — the hash is overwritten, so the previous plaintext can
     * never verify again).
     *
     * @param int $clientID Internal clientID (PK) to rotate.
     * @return array{clientID:int,clientSecret:string,client:array}|false
     *         `false` if the client does not exist or is not confidential
     *         (public clients have no secret to rotate).
     */
    public static function rotateSecret(int $clientID): array|false
    {
        $client = self::getClientByID($clientID);
        if ($client === null) {
            return false;
        }
        if ($client['clientType'] !== 'confidential') {
            error_log("OAuthClientManager::rotateSecret — clientID {$clientID} is not confidential");
            return false;
        }

        $plainSecret = self::generateClientSecret();
        $secretHash  = self::hashSecret($plainSecret);

        Database::query(
            "UPDATE tblOAuthClients SET clientSecretHash = ?, updatedAt = NOW() WHERE clientID = ?",
            [$secretHash, $clientID],
            'si'
        );

        self::logEvent(
            'oauth_client_secret_rotated',
            null,
            "OAuth client secret rotated (clientID {$clientID})",
            ['clientID' => $clientID]
        );

        return [
            'clientID'     => $clientID,
            'clientSecret' => $plainSecret, // 🔒 PLAINTEXT — shown ONCE.
            'client'       => self::getClientByID($clientID),
        ];
    }

    /**
     * 🚫 Deactivate a client (soft-delete — existing grants are left alone;
     * Stage A2+ enforces `isActive` at /oauth/authorize).
     *
     * @param int $clientID Internal clientID.
     * @return bool Success.
     */
    public static function deactivate(int $clientID): bool
    {
        Database::query(
            "UPDATE tblOAuthClients SET isActive = 0, updatedAt = NOW() WHERE clientID = ?",
            [$clientID],
            'i'
        );
        $success = Database::getAffectedRows() > 0;

        if ($success) {
            self::logEvent(
                'oauth_client_deactivated',
                null,
                "OAuth client deactivated (clientID {$clientID})",
                ['clientID' => $clientID]
            );
        }

        return $success;
    }

    // ========================================================================
    // 🔍 RETRIEVAL
    // ========================================================================

    /**
     * 🔍 Fetch a client by its PUBLIC client_id (`clientIdentifier`).
     *
     * @param string $clientIdentifier Public client_id.
     * @return array|null Hydrated client row (with `redirectUris`,
     *                     `allowedScopesArray`, `grantTypesArray`), or null if
     *                     not found.
     */
    public static function getClient(string $clientIdentifier): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM tblOAuthClients WHERE clientIdentifier = ?",
            [$clientIdentifier],
            's'
        );

        return $row !== null ? self::hydrateClient($row) : null;
    }

    /**
     * 🔍 Fetch a client by its INTERNAL clientID (PK).
     *
     * @param int $clientID Internal clientID.
     * @return array|null Hydrated client row, or null if not found.
     */
    public static function getClientByID(int $clientID): ?array
    {
        $row = Database::fetchOne(
            "SELECT * FROM tblOAuthClients WHERE clientID = ?",
            [$clientID],
            'i'
        );

        return $row !== null ? self::hydrateClient($row) : null;
    }

    /**
     * 📋 List all clients owned by a partner (ownership-scoped — a partner can
     * only ever see ITS OWN clients via this method; callers must not accept
     * an attacker-supplied partnerID without first verifying the requester
     * actually belongs to it).
     *
     * @param int $partnerID Owning partner.
     * @return array<int,array> Hydrated client rows, newest first.
     */
    public static function listClients(int $partnerID): array
    {
        $rows = Database::fetchAll(
            "SELECT * FROM tblOAuthClients WHERE partnerID = ? ORDER BY createdAt DESC",
            [$partnerID],
            'i'
        );

        return array_map(static fn(array $row): array => self::hydrateClient($row), $rows);
    }

    // ========================================================================
    // 🛡️ VALIDATION — the security-critical checks
    // ========================================================================

    /**
     * 🎯 EXACT-MATCH redirect_uri validation (the #1 OAuth IdP security
     * control). Fetches the client's registered allowlist and delegates to
     * {@see self::isExactRedirectMatch()} for a byte-exact PHP comparison — no
     * prefix/substring/wildcard/normalisation of any kind.
     *
     * @param int    $clientID Internal clientID.
     * @param string $uri      Candidate redirect_uri from the request.
     * @return bool True only if $uri byte-exactly matches a registered URI.
     */
    public static function validateRedirectUri(int $clientID, string $uri): bool
    {
        $rows = Database::fetchAll(
            "SELECT redirectURI FROM tblOAuthClientRedirectUris WHERE clientID = ?",
            [$clientID],
            'i'
        );
        $registered = array_map(static fn(array $r): string => $r['redirectURI'], $rows);

        return self::isExactRedirectMatch($uri, $registered);
    }

    /**
     * 🎯 Pure, DB-free EXACT-MATCH comparison: is `$candidate` byte-identical
     * to one of `$registeredUris`?
     *
     * Deliberately uses `in_array(..., true)` (strict mode) — a PHP string
     * strict-equality comparison — so there is NO scheme/host/case
     * normalisation, NO trailing-slash tolerance, NO prefix or substring
     * matching, and NO dependence on the database's collation. This is the
     * authoritative check; {@see self::validateRedirectUri()} is a thin DB
     * wrapper around it.
     *
     * @param string   $candidate      Candidate redirect_uri.
     * @param string[] $registeredUris The client's registered allowlist.
     * @return bool
     */
    public static function isExactRedirectMatch(string $candidate, array $registeredUris): bool
    {
        return in_array($candidate, $registeredUris, true);
    }

    /**
     * 🔑 Verify a presented client_secret against the stored hash.
     *
     * Refuses to "verify" anything for a PUBLIC client or an unknown
     * client_id — a public client can never authenticate with a secret
     * (confidential/public confusion defence, G-001.md §5.6/§10 red-team
     * item). Uses hash_equals() for a constant-time comparison.
     *
     * @param string $clientIdentifier Public client_id.
     * @param string $secret           Presented plaintext secret.
     * @return bool
     */
    public static function verifyClientSecret(string $clientIdentifier, string $secret): bool
    {
        $client = self::getClient($clientIdentifier);

        if ($client === null
            || $client['clientType'] !== 'confidential'
            || empty($client['clientSecretHash'])
        ) {
            return false;
        }

        return hash_equals((string) $client['clientSecretHash'], self::hashSecret($secret));
    }

    /**
     * 🔒 Scope-subset check: is every scope in `$requestedScope` present in
     * `$allowedScope`? (Space-delimited scope strings, per RFC 6749 §3.3.)
     *
     * Used by Stage A2/A3 to enforce "granted scope ⊆ requested ⊆
     * allowedScopes" (G-001.md §5.7 — downgrade-only, never escalate).
     *
     * @param string $requestedScope Space-delimited requested scopes.
     * @param string $allowedScope   Space-delimited scopes the client MAY request.
     * @return bool True if every requested scope is allowed (vacuously true
     *              for an empty request).
     */
    public static function scopesAllowed(string $requestedScope, string $allowedScope): bool
    {
        $requested = self::splitScopeString($requestedScope);
        $allowed   = self::splitScopeString($allowedScope);

        foreach ($requested as $scope) {
            if (!in_array($scope, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    // ========================================================================
    // 🕵️ PAIRWISE SUBJECT IDENTIFIERS (OIDC)
    // ========================================================================

    /**
     * 🕵️ Compute the OIDC `sub` claim for a user + client.
     *
     * `public` subject_type returns the stable raw userID (as a string —
     * matches the `sub` claim's string type per OIDC Core §2). `pairwise`
     * returns a per-sector opaque value so two colluding RPs in different
     * sectors cannot correlate the same user via a shared identifier:
     *
     *     sub = SHA-256( sectorIdentifier . '|' . userID . '|' . pairwiseSalt )
     *
     * @param int        $userID        SIGNula internal userID.
     * @param array      $client        A hydrated client row (as returned by
     *                                  getClient()/getClientByID()) — reads
     *                                  `subjectType`, `sectorIdentifier`, and
     *                                  (as a fallback) `clientIdentifier`.
     * @param string|null $pairwiseSalt Explicit salt override — Unit tests
     *                                  pass this to stay DB-free; production
     *                                  callers omit it and the server-side
     *                                  salt is read/minted via
     *                                  {@see self::getPairwiseSalt()}.
     * @return string The `sub` claim value.
     */
    public static function computeSubject(int $userID, array $client, ?string $pairwiseSalt = null): string
    {
        $subjectType = (string) ($client['subjectType'] ?? 'pairwise');

        if ($subjectType !== 'pairwise') {
            return (string) $userID;
        }

        $sector = $client['sectorIdentifier'] ?? null;
        if ($sector === null || $sector === '') {
            // 🛟 Fallback sector: scope pairwise-ness to the client itself so
            //    two clients with no configured sector still get DIFFERENT
            //    subs (rather than silently degrading to a shared/public sub).
            $sector = 'client:' . (string) ($client['clientIdentifier'] ?? $client['clientID'] ?? '');
        }

        $salt = $pairwiseSalt ?? self::getPairwiseSalt();

        return hash('sha256', $sector . '|' . $userID . '|' . $salt);
    }

    // ========================================================================
    // 🔧 PRIVATE HELPERS
    // ========================================================================

    /**
     * 🆔 Generate a unique, non-guessable public client_id (DB uniqueness-
     * checked — loops on the vanishingly-unlikely event of a collision).
     *
     * @return string 32-char lowercase hex (16 random bytes).
     */
    private static function generateClientIdentifier(): string
    {
        do {
            $candidate = self::generateRawClientIdentifier();
            $exists = Database::fetchOne(
                "SELECT clientID FROM tblOAuthClients WHERE clientIdentifier = ?",
                [$candidate],
                's'
            );
        } while ($exists !== null);

        return $candidate;
    }

    /**
     * 🆔 Pure (DB-free) client_id generator — factored out of
     * {@see self::generateClientIdentifier()} so its FORMAT (32-char lowercase
     * hex) is Unit-testable without a database. Production code should call
     * generateClientIdentifier() (which adds the uniqueness check); this raw
     * form exists for testability and as the building block that method loops
     * on.
     *
     * @return string 32-char lowercase hex (16 random bytes).
     */
    public static function generateRawClientIdentifier(): string
    {
        return bin2hex(random_bytes(self::CLIENT_ID_BYTES));
    }

    /**
     * 🔑 Generate a high-entropy plaintext client_secret. Pure (DB-free) — no
     * uniqueness check is needed (256 bits of entropy makes a collision
     * cryptographically negligible, unlike the shorter 128-bit client_id).
     *
     * @return string 64-char lowercase hex (32 random bytes / 256 bits) —
     *                mirrors the refresh-token entropy convention (G-003).
     */
    public static function generateClientSecret(): string
    {
        return bin2hex(random_bytes(self::CLIENT_SECRET_BYTES));
    }

    /**
     * 🔒 Hash a client_secret for storage (never store plaintext).
     *
     * @param string $secret Plaintext secret.
     * @return string SHA-256 hex digest (64 chars).
     */
    private static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * 🧹 Validate + dedupe a candidate redirect_uri list, preserving order.
     *
     * @param string[] $uris Raw candidate URIs.
     * @return string[] Valid, trimmed, deduped URIs (possibly empty).
     */
    private static function normaliseRedirectUris(array $uris): array
    {
        $out = [];
        foreach ($uris as $uri) {
            if (!is_string($uri)) {
                continue;
            }
            $uri = trim($uri);
            if ($uri === '' || in_array($uri, $out, true)) {
                continue;
            }
            if (!self::isValidRedirectUriFormat($uri)) {
                continue;
            }
            $out[] = $uri;
        }

        return $out;
    }

    /**
     * 🔗 Light format sanity-check for a redirect_uri at REGISTRATION time
     * ONLY (rejects obviously malformed entries — no scheme, whitespace,
     * control characters, `javascript:` pseudo-protocol, over-length). This is
     * NOT the security-relevant check — that is the byte-exact match done by
     * {@see self::isExactRedirectMatch()} at authorize/token time. Native app
     * custom schemes (e.g. `com.example.app://oauth/callback`) are accepted —
     * FILTER_VALIDATE_URL only requires a `scheme://` shape, not a
     * well-known scheme.
     *
     * @param string $uri Candidate redirect_uri.
     * @return bool
     */
    private static function isValidRedirectUriFormat(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > self::MAX_REDIRECT_URI_LENGTH) {
            return false;
        }
        // 🚫 No control characters or whitespace of any kind.
        if (preg_match('/[\x00-\x1F\x7F\s]/', $uri) === 1) {
            return false;
        }

        return filter_var($uri, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 🔗 Validate + normalise an optional consent-screen URI field (logoURI/
     * clientURI/tosURI/policyURI). Returns null for an absent/blank/invalid
     * value rather than failing registration outright — these are cosmetic,
     * not security-relevant.
     *
     * @param mixed $value Raw option value.
     * @return string|null
     */
    private static function normaliseOptionalUri(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > self::MAX_REDIRECT_URI_LENGTH) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }

    /**
     * 🧵 Join a list of scope/grant tokens into a space-delimited string,
     * trimming/deduping/dropping empties.
     *
     * @param array $tokens
     * @return string
     */
    private static function joinScopeList(array $tokens): string
    {
        $clean = [];
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                continue;
            }
            $token = trim($token);
            if ($token === '' || in_array($token, $clean, true)) {
                continue;
            }
            $clean[] = $token;
        }

        return implode(' ', $clean);
    }

    /**
     * ✂️ Split a space-delimited scope string into a clean token array.
     *
     * @param string $scopeString
     * @return string[]
     */
    private static function splitScopeString(string $scopeString): array
    {
        $parts = preg_split('/\s+/', trim($scopeString), -1, PREG_SPLIT_NO_EMPTY);

        return $parts !== false ? $parts : [];
    }

    /**
     * 🧱 Hydrate a raw tblOAuthClients row: attach its redirect-URI allowlist
     * and parsed scope/grant arrays, and cast boolean-ish columns to bool.
     *
     * @param array $row Raw DB row.
     * @return array Hydrated row.
     */
    private static function hydrateClient(array $row): array
    {
        $uriRows = Database::fetchAll(
            "SELECT redirectURI FROM tblOAuthClientRedirectUris WHERE clientID = ? ORDER BY redirectUriID ASC",
            [$row['clientID']],
            'i'
        );

        $row['redirectUris']        = array_map(static fn(array $r): string => $r['redirectURI'], $uriRows);
        $row['allowedScopesArray']  = self::splitScopeString((string) ($row['allowedScopes'] ?? ''));
        $row['grantTypesArray']     = self::splitScopeString((string) ($row['grantTypes'] ?? ''));
        $row['isActive']            = (bool) ($row['isActive'] ?? false);
        $row['isFirstParty']        = (bool) ($row['isFirstParty'] ?? false);
        $row['pkceRequired']        = (bool) ($row['pkceRequired'] ?? false);

        return $row;
    }

    /**
     * 🔑 Read (or, on first use, mint + persist) the server-side pairwise-
     * subject salt.
     *
     * Mirrors KeyManager::generateKey()'s "never hardcode a secret in a
     * migration" pattern: the row is deliberately absent from migration 031
     * and is created here, encrypted (isSensitive), on first need. Uses
     * INSERT IGNORE + a re-read so a concurrent first-request race cannot
     * leave two different salts in play — whichever request's INSERT IGNORE
     * actually lands "wins", and every requester (including the loser of the
     * race) re-reads the row that is actually in the database.
     *
     * @return string Decrypted salt.
     * @throws \RuntimeException If the salt can neither be read nor created.
     */
    private static function getPairwiseSalt(): string
    {
        $existing = self::readPairwiseSaltRaw();
        if ($existing !== null) {
            return SecurityUtils::decrypt($existing);
        }

        $salt = bin2hex(random_bytes(self::PAIRWISE_SALT_BYTES));
        Database::query(
            "INSERT IGNORE INTO tblSettings
                (settingKey, settingValue, settingType, isSensitive, settingCategory, description)
             VALUES (?, ?, 'encrypted', 1, 'oauth', 'Server-side pairwise-subject salt (G-001) — auto-generated on first use')",
            [self::PAIRWISE_SALT_SETTING_KEY, SecurityUtils::encrypt($salt)],
            'ss'
        );

        $final = self::readPairwiseSaltRaw();
        if ($final === null) {
            // 🧯 Extremely unlikely (DB write silently failed) — fail closed
            //    rather than ever computing a pairwise sub without a real salt.
            throw new \RuntimeException('OAuthClientManager: failed to establish oauth.pairwise_salt');
        }

        return SecurityUtils::decrypt($final);
    }

    /**
     * 📖 Read the RAW (still-encrypted) stored pairwise-salt value, bypassing
     * the request-start $GLOBALS['settings'] cache (config.php's loadSettings()
     * runs once at bootstrap and would not see a salt minted later in THIS
     * request) — mirrors KeyManager::readSetting()'s freshness rationale.
     *
     * @return string|null Raw encrypted blob, or null if not yet created.
     */
    private static function readPairwiseSaltRaw(): ?string
    {
        $row = Database::fetchOne(
            "SELECT settingValue FROM tblSettings WHERE settingKey = ?",
            [self::PAIRWISE_SALT_SETTING_KEY],
            's'
        );

        if ($row === null || $row['settingValue'] === null || $row['settingValue'] === '') {
            return null;
        }

        return (string) $row['settingValue'];
    }

    /**
     * 📝 Best-effort activity-log write (never throws — mirrors
     * ActivityLogger::log()'s own internal try/catch).
     *
     * @param string   $activityType Short machine-readable event name.
     * @param int|null $userID       Acting user, or null for system/unknown.
     * @param string   $description  Human-readable description.
     * @param array    $metadata     Extra structured context.
     * @return void
     */
    private static function logEvent(string $activityType, ?int $userID, string $description, array $metadata = []): void
    {
        if (class_exists('ActivityLogger')) {
            // 🏷️ Category 'api_keys' is the closest existing
            //    tblActivityLog.activityCategory ENUM member (see migration
            //    029_activity_category_enum_widen.sql) — OAuth client
            //    credentials are the same semantic class of event
            //    (partner-managed API/integration credentials) as API-key
            //    generation/revocation. No 'oauth' ENUM member exists yet;
            //    flagged as a NEEDS-LEAD-REVIEW nit for a future migration if
            //    a dedicated category is wanted.
            ActivityLogger::log($userID, $activityType, 'api_keys', 'info', $description, $metadata);
        }
    }
}

// ✅ OAuthClientManager loaded successfully
