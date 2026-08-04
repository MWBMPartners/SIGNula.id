<?php
/**
 * ============================================================================
 * 🔐 SIGNula - Generic OpenID Connect (OIDC) Provider
 * ============================================================================
 *
 * Purpose: Config-driven connector for ANY standards-compliant OpenID
 *          Connect identity provider (Okta, Keycloak, Auth0, Azure AD B2C, a
 *          corporate SSO tenant, etc.) — issue #99 / FG-008, the "OpenID"
 *          bullet in the project brief's third-party-account-linkage list.
 * PHP Version: 8.3+ (requires ext-openssl for id_token signature checks)
 *
 * -----------------------------------------------------------------------
 * 🧭 WHY THIS CLASS EXISTS (vs. one-class-per-provider like GoogleOAuth.php)
 * -----------------------------------------------------------------------
 * Google/Microsoft/Yahoo/etc. each hardcode THEIR OWN fixed endpoints. A
 * generic OIDC identity provider has no fixed endpoints to hardcode — every
 * deployment (a company's Okta tenant, a self-hosted Keycloak realm, …) has
 * its own. This class instead takes only an `issuer` URL and performs
 * OIDC Discovery (see below) to learn the rest at runtime, so ANY compliant
 * IdP can be linked purely via settings — no new PHP class per IdP.
 *
 * -----------------------------------------------------------------------
 * 🔢 MULTIPLE NAMED INSTANCES
 * -----------------------------------------------------------------------
 * The constructor takes an optional `$providerKey` (default 'oidc'). Settings
 * are read from `oauth.{providerKey}.*` — exactly the same dot-notation the
 * base {@see OAuth} class already uses for client_id/client_secret/
 * redirect_uri (see OAuth::loadConfiguration()). This means a SIGNula
 * install can register any number of independent generic-OIDC identity
 * providers just by picking a new key and setting:
 *   - oauth.{key}.issuer          (required — the IdP's issuer URL)
 *   - oauth.{key}.client_id       (required)
 *   - oauth.{key}.client_secret   (required)
 *   - oauth.{key}.redirect_uri    (optional — falls back to app.url + '/oauth/callback')
 *   - oauth.{key}.scopes          (optional — default 'openid profile email')
 *   - oauth.{key}.display_name    (optional — button/label override)
 *   - oauth.{key}.icon_class      (optional — Font Awesome class override)
 * web/public_html/oauth/authorize.php and web/public_html/oauth/callback.php
 * both resolve a provider key to a PHP class by convention
 * (ucfirst($key) . 'OAuth'); when no DEDICATED class file exists for a key
 * they fall back to `new GenericOidcProvider($key)` — see the "Provider
 * class resolution" comment block added to both of those files. That fallback
 * is what makes additional named instances (e.g. 'lastpass', 'okta_acme')
 * work with ZERO extra PHP.
 *
 * -----------------------------------------------------------------------
 * 🔐 SECURITY: what this class actually verifies (not just "trusts the code")
 * -----------------------------------------------------------------------
 *   1. OIDC Discovery document — fetched from
 *      {issuer}/.well-known/openid-configuration, its own `issuer` field is
 *      cross-checked against the configured issuer (defeats a misconfigured/
 *      hijacked reverse proxy serving someone else's metadata at that URL).
 *   2. PKCE (RFC 7636) — S256 code_challenge/code_verifier on every
 *      authorization request, exactly like a public client, even though this
 *      is a confidential client with a client_secret. Defence-in-depth
 *      against authorization-code interception.
 *   3. state — CSRF protection, via the base {@see OAuth} class's existing
 *      generateState()/verifyState() (unchanged, reused as-is).
 *   4. nonce (OIDC Core 1.0 §3.1.2.1) — minted per authorization request and
 *      re-checked inside the returned id_token to block replay of a
 *      previously-issued token against a NEW login attempt.
 *   5. id_token signature — verified against the provider's OWN published
 *      JWKS (fetched from the discovery document's `jwks_uri`), using the
 *      project's already-vendored firebase/php-jwt library (see
 *      web/private_html/security/Jwt.php / JwtLibLoader.php — the SAME
 *      library SIGNula uses to sign+verify its OWN tokens as an IdP). RS256
 *      is pinned (never trusts the token header to choose the algorithm),
 *      which also defeats the classic `alg:none` and HMAC/RSA
 *      algorithm-confusion attack classes.
 *   6. id_token claims — iss/aud/azp/exp/iat/nbf per OIDC Core 1.0 §3.1.3.7,
 *      plus the nonce check from (4).
 *   7. userinfo `sub` — cross-checked against the id_token's `sub` per OIDC
 *      Core 1.0 §5.3.2, to reject a userinfo response describing a DIFFERENT
 *      subject than the one that was actually authenticated.
 *
 * -----------------------------------------------------------------------
 * 🗝️ LASTPASS — why there is NO bespoke "LastpassOAuth.php"
 * -----------------------------------------------------------------------
 * LastPass has NO public consumer OAuth/OIDC login API. Its SSO offering is
 * SAML 2.0, and only for LastPass Business/Enterprise accounts, where
 * LastPass itself acts as the SAML Identity Provider for OTHER apps — see
 * https://support.lastpass.com/help/lastpass-admin-toolkit-using-single-sign-on-sso
 * There is nothing to hardcode: no public authorize/token/userinfo endpoints
 * exist for it. Migration 049 seeds a DISABLED `oauth.lastpass.*` settings
 * template (blank issuer/client_id/client_secret) purely as a documented
 * starting point: if an organisation ever fronts LastPass Enterprise SSO
 * behind an OIDC-compatible bridge (or SIGNula's own SAML IdP — issue #100 —
 * is used instead), an admin can fill in that template's issuer/client_id/
 * client_secret and `new GenericOidcProvider('lastpass')` (wired
 * automatically via the provider-class fallback described above) starts
 * working immediately. Until those settings are filled in, isConfigured()
 * returns false and no "Sign in with LastPass" button is ever shown
 * (see login.php / connected-accounts.php — both gate on client_id being
 * non-empty).
 *
 * Standards implemented:
 * @see https://openid.net/specs/openid-connect-discovery-1_0.html (OIDC Discovery 1.0)
 * @see https://openid.net/specs/openid-connect-core-1_0.html      (OIDC Core 1.0)
 * @see https://datatracker.ietf.org/doc/html/rfc7636              (PKCE)
 * @see https://www.rfc-editor.org/rfc/rfc6749                     (OAuth 2.0)
 * @see https://datatracker.ietf.org/doc/html/rfc7009              (Token Revocation)
 *
 * @package    SIGNula
 * @subpackage Authentication
 * @version    1.0.0
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

// 📚 Require OAuth base class
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'OAuth.php';

// 🔑 firebase/php-jwt (vendored, no Composer runtime — see JwtLibLoader.php).
// Aliased exactly like web/private_html/security/Jwt.php does, so this file
// never has to fully-qualify \Firebase\JWT\* everywhere below.
use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\JWK as FirebaseJWK;
use Firebase\JWT\ExpiredException as FirebaseExpiredException;
use Firebase\JWT\SignatureInvalidException as FirebaseSignatureInvalidException;

/**
 * 🔐 Generic OpenID Connect Provider
 *
 * Config-driven OIDC Relying Party connector. See the file-level docblock
 * above for the full design rationale.
 */
class GenericOidcProvider extends OAuth
{
    /**
     * @var string The IdP's issuer URL (no trailing slash), e.g.
     *             'https://accounts.example-idp.com'. Loaded from
     *             `oauth.{providerKey}.issuer`.
     */
    protected string $issuer = '';

    /**
     * @var string The IdP's JWKS endpoint (from OIDC discovery), used to
     *             verify id_token signatures.
     */
    protected string $jwksUri = '';

    /**
     * @var array<string,mixed>|null Validated id_token claims from the most
     *      recent {@see self::exchangeCodeForToken()} call. getUserInfo()
     *      folds these in as a fallback/cross-check alongside whatever the
     *      userinfo endpoint returns, since callback.php (unlike its Apple
     *      special-case) only ever passes getUserInfo() the access_token.
     */
    protected ?array $idTokenClaims = null;

    /**
     * @var int Default TTL (seconds) for the in-process/APCu discovery +
     *          JWKS caches when `oauth.oidc.discovery_cache_ttl` is unset.
     *          OIDC discovery documents and JWKS change rarely, so an hour
     *          is a conservative default that still limits blast radius on
     *          a genuine key-rotation at the IdP.
     */
    private const DEFAULT_DISCOVERY_CACHE_TTL = 3600;

    /**
     * @var array<string,array<string,mixed>> Per-request discovery-document
     *      cache, keyed by issuer URL. Shared by every GenericOidcProvider
     *      instance within the same PHP process/request (mirrors
     *      Entitlements::$requestCache's per-request-cache idiom).
     */
    private static array $discoveryCache = [];

    /**
     * @var array<string,array<string,mixed>> Per-request JWKS cache, keyed
     *      by jwks_uri.
     */
    private static array $jwksCache = [];

    // ========================================================================
    // 🏗️ CONSTRUCTOR
    // ========================================================================

    /**
     * 🏗️ Constructor
     *
     * @param string $providerKey Settings namespace / tblOAuthAccounts
     *                            `provider` value for this instance. Default
     *                            'oidc' (the single generic slot shown in the
     *                            standard registries); pass a distinct key
     *                            (e.g. 'lastpass', 'okta_acme') to register
     *                            an independent named instance — see the
     *                            file-level docblock's "MULTIPLE NAMED
     *                            INSTANCES" section.
     * @throws RuntimeException If client_id/client_secret are missing
     *                          (thrown by the parent OAuth::loadConfiguration()
     *                          — identical hard-fail behaviour to
     *                          GoogleOAuth/MicrosoftOAuth/YahooOAuth) or if
     *                          the settings look nothing like OIDC at all.
     */
    public function __construct(string $providerKey = 'oidc')
    {
        // 🔑 Loads client_id/client_secret/redirect_uri from
        // oauth.{providerKey}.* and THROWS if client_id/client_secret are
        // blank — identical baseline behaviour to every other provider here.
        parent::__construct($providerKey);

        // 🔎 OIDC-specific config (issuer, scopes) + discovery. Kept as a
        // SEPARATE step called after parent::__construct() completes,
        // mirroring AppleOAuth's loadAppleConfiguration() pattern: the base
        // class already enforces the "must have credentials" hard-fail
        // above, so this second step only needs to worry about the
        // ADDITIONAL fields this connector alone requires.
        $this->loadOidcConfiguration();
    }

    /**
     * ⚙️ Load OIDC-Specific Configuration (issuer, scopes) + run Discovery
     *
     * Deliberately catches its own failures (logs + returns) rather than
     * letting them propagate, exactly like AppleOAuth::loadAppleConfiguration()
     * — the intent is that a not-yet-fully-configured or momentarily
     * unreachable instance is reported as "not configured" via
     * {@see self::isConfigured()} rather than fataling every single page
     * that merely instantiates it (e.g. the login page building its button
     * list).
     *
     * @return void
     */
    protected function loadOidcConfiguration(): void
    {
        try {
            // 🌐 Issuer URL — required, https-only (defence in depth; every
            // real-world OIDC IdP publishes over TLS, and OIDC Core 1.0 §2
            // requires it).
            $issuerSetting = rtrim(trim((string) getSetting("oauth.{$this->providerName}.issuer", '')), '/');

            if (empty($issuerSetting)) {
                throw new RuntimeException("OIDC issuer not configured for provider '{$this->providerName}' (setting: oauth.{$this->providerName}.issuer)");
            }

            if (!preg_match('#^https://#i', $issuerSetting)) {
                throw new RuntimeException("OIDC issuer must be an https:// URL for provider '{$this->providerName}'");
            }

            $this->issuer = $issuerSetting;

            // 📋 Scopes — OIDC Core 1.0 §3.1.2.1 REQUIRES 'openid'; default
            // to the common 'openid profile email' triad used by virtually
            // every OIDC IdP (Google/Microsoft/Okta/Auth0/Keycloak all
            // support it out of the box).
            $scopesSetting = trim((string) getSetting("oauth.{$this->providerName}.scopes", 'openid profile email'));
            $scopes = array_values(array_filter(preg_split('/[\s,]+/', $scopesSetting) ?: []));

            if (!in_array('openid', $scopes, true)) {
                array_unshift($scopes, 'openid');
            }

            $this->scopes = $scopes;

            // 🔎 OIDC Discovery 1.0 — resolves authorization_endpoint,
            // token_endpoint, userinfo_endpoint, jwks_uri, etc. from a single
            // issuer URL. Cached — see self::discover().
            // @see https://openid.net/specs/openid-connect-discovery-1_0.html
            $discovery = $this->discover();

            $this->authorizationEndpoint = (string) $discovery['authorization_endpoint'];
            $this->tokenEndpoint         = (string) $discovery['token_endpoint'];
            $this->userInfoEndpoint      = (string) ($discovery['userinfo_endpoint'] ?? '');
            $this->jwksUri               = (string) $discovery['jwks_uri'];

        } catch (Exception $e) {
            // 🚑 Log and swallow — see method docblock. isConfigured() below
            // will correctly report this instance as unusable.
            error_log("Generic OIDC configuration error ({$this->providerName}): " . $e->getMessage());
        }
    }

    /**
     * ❓ Check if Provider is Configured
     *
     * Extends the base client_id/client_secret check with the OIDC-specific
     * fields that only successful discovery can populate.
     *
     * @return bool True if provider is fully configured and discovery succeeded
     */
    public function isConfigured(): bool
    {
        return parent::isConfigured()
            && !empty($this->issuer)
            && !empty($this->authorizationEndpoint)
            && !empty($this->tokenEndpoint)
            && !empty($this->jwksUri);
    }

    // ========================================================================
    // 🎨 DISPLAY
    // ========================================================================

    /**
     * 🎨 Get Provider Display Name
     *
     * Admin-overridable via `oauth.{providerKey}.display_name` (useful for a
     * branded label like "Acme Corp SSO"); otherwise a sensible per-key
     * default.
     *
     * @return string Display name
     */
    public function getDisplayName(): string
    {
        $custom = trim((string) getSetting("oauth.{$this->providerName}.display_name", ''));
        if ($custom !== '') {
            return $custom;
        }

        return match ($this->providerName) {
            'oidc'     => 'OpenID Connect',
            'lastpass' => 'LastPass',
            default    => ucfirst(str_replace(['_', '-'], ' ', $this->providerName)),
        };
    }

    /**
     * 🎨 Get Provider Icon Class
     *
     * Admin-overridable via `oauth.{providerKey}.icon_class`; otherwise a
     * generic OpenID glyph (Font Awesome brands ships `fa-openid`), or a
     * LastPass-branded glyph for that specific documented template.
     *
     * @return string Font Awesome icon class
     */
    public function getIconClass(): string
    {
        $custom = trim((string) getSetting("oauth.{$this->providerName}.icon_class", ''));
        if ($custom !== '') {
            return $custom;
        }

        return match ($this->providerName) {
            'lastpass' => 'fab fa-lastpass',
            default    => 'fab fa-openid',
        };
    }

    // ========================================================================
    // 🔗 AUTHORIZATION FLOW (PKCE + nonce)
    // ========================================================================

    /**
     * 🔗 Get Authorization URL
     *
     * Adds PKCE (RFC 7636, S256) and an OIDC nonce on top of the base
     * class's state/client_id/redirect_uri/scope handling.
     *
     * @param array $additionalParams Additional query parameters
     * @return string Authorization URL
     */
    public function getAuthorizationUrl(array $additionalParams = []): string
    {
        // 🔐 PKCE code_verifier — high-entropy random string, 43-128 chars
        // from the base64url alphabet (RFC 7636 §4.1). 64 random bytes ->
        // ~86 base64url chars, comfortably inside that range.
        $codeVerifier = KeyManager::base64UrlEncode(random_bytes(64));

        // code_challenge = BASE64URL(SHA256(code_verifier)) — the 'S256'
        // method (RFC 7636 §4.2). The weaker 'plain' method is never used.
        $codeChallenge = KeyManager::base64UrlEncode(hash('sha256', $codeVerifier, true));

        // 🪪 nonce (OIDC Core 1.0 §3.1.2.1) — bound into the id_token we get
        // back and re-checked in validateIdToken() to block replay.
        $nonce = SecurityUtils::generateToken(32);

        $oidcParams = array_merge($additionalParams, [
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
            'nonce'                 => $nonce,
        ]);

        // ➡️ parent::getAuthorizationUrl() calls $this->generateState()
        // internally, which writes $_SESSION['oauth_state'] =
        // ['token', 'provider', 'expires']. We deliberately do NOT extend
        // that bucket: web/public_html/oauth/callback.php clears it
        // immediately after verifying `state`, BEFORE the provider object
        // that needs the PKCE verifier + nonce is even constructed. PKCE
        // material therefore lives in its OWN session bucket
        // ($_SESSION['oidc_pkce']) that callback.php never touches, and
        // which survives long enough for exchangeCodeForToken() to consume
        // it below.
        $url = parent::getAuthorizationUrl($oidcParams);

        $_SESSION['oidc_pkce'] = [
            'code_verifier' => $codeVerifier,
            'nonce'         => $nonce,
            'provider'      => $this->providerName,
            'expires'       => time() + self::STATE_EXPIRATION,
        ];

        return $url;
    }

    // ========================================================================
    // 🎫 TOKEN EXCHANGE
    // ========================================================================

    /**
     * 🔄 Exchange Authorization Code for Access Token
     *
     * Adds the PKCE code_verifier, adapts client authentication to whatever
     * the IdP's discovery document advertises, and fully validates the
     * returned id_token (signature via the IdP's own JWKS, iss/aud/azp/exp,
     * and our nonce).
     *
     * @param string $code Authorization code
     * @return array Token response (validated id_token claims are stashed on
     *               $this->idTokenClaims — see getUserInfo()).
     * @throws RuntimeException If exchange or id_token validation fails
     */
    public function exchangeCodeForToken(string $code): array
    {
        try {
            // 🔐 Retrieve the PKCE verifier + nonce stashed by
            // getAuthorizationUrl() — see that method's comment on why this
            // is a SEPARATE session bucket from $_SESSION['oauth_state'].
            $pkce = $_SESSION['oidc_pkce'] ?? null;

            if (empty($pkce) || empty($pkce['code_verifier']) || ($pkce['provider'] ?? null) !== $this->providerName) {
                throw new RuntimeException('Missing or mismatched PKCE session state — the login flow must be started via getAuthorizationUrl().');
            }

            if (time() > (int) ($pkce['expires'] ?? 0)) {
                unset($_SESSION['oidc_pkce']);
                throw new RuntimeException('OIDC PKCE session expired — please try signing in again.');
            }

            $discovery = $this->discover();

            // 🔑 Client authentication (RFC 6749 §2.3.1). Default is
            // 'client_secret_post' (credentials in the POST body — matches
            // the base OAuth class's own exchangeCodeForToken()); switch to
            // HTTP Basic ONLY when the IdP's discovery document says it does
            // NOT support client_secret_post but DOES support
            // client_secret_basic. This mirrors YahooOAuth's hand-coded
            // Basic-auth override, but derives the decision from the IdP's
            // OWN advertised metadata instead of hardcoded provider
            // knowledge — the whole point of a "generic" connector.
            $authMethods = $discovery['token_endpoint_auth_methods_supported'] ?? ['client_secret_post'];
            $useBasicAuth = in_array('client_secret_basic', $authMethods, true)
                && !in_array('client_secret_post', $authMethods, true);

            $params = [
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri,
                'grant_type'    => 'authorization_code',
                'code_verifier' => $pkce['code_verifier'], // RFC 7636 §4.5
            ];

            $headers = [];

            if ($useBasicAuth) {
                $headers[] = 'Authorization: Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret);
            } else {
                $params['client_id']     = $this->clientId;
                $params['client_secret'] = $this->clientSecret;
            }

            $response = $this->makeHttpRequest($this->tokenEndpoint, 'POST', $params, $headers);

            if (empty($response['access_token'])) {
                throw new RuntimeException('Invalid token response: missing access_token');
            }

            // OIDC Core 1.0 §3.1.3.3 — an id_token MUST be returned whenever
            // 'openid' was in the requested scope (always true here — see
            // loadOidcConfiguration()).
            if (empty($response['id_token'])) {
                throw new RuntimeException('Invalid token response: missing id_token (provider may not be OIDC-compliant)');
            }

            // ✅ Full id_token validation: signature (via the IdP's own
            // JWKS), iss/aud/azp/exp, and the nonce minted above.
            $this->idTokenClaims = $this->validateIdToken((string) $response['id_token'], $pkce['nonce'] ?? null);

            // 🧹 One-shot use — PKCE material must never be replayable.
            unset($_SESSION['oidc_pkce']);

            return $response;

        } catch (Exception $e) {
            // 🧹 Fail closed either way — never leave PKCE material sitting
            // in the session after a failed attempt.
            unset($_SESSION['oidc_pkce']);
            error_log("Generic OIDC ({$this->providerName}) token exchange error: " . $e->getMessage());
            throw new RuntimeException('Failed to exchange authorization code for token');
        }
    }

    // ========================================================================
    // 🪪 ID TOKEN VALIDATION
    // ========================================================================

    /**
     * 🪪 Validate an OIDC id_token
     *
     * Verifies the signature against the IdP's own JWKS (via the already
     * project-vendored firebase/php-jwt library — see file-level docblock),
     * then checks the OIDC-mandated registered claims.
     *
     * @param string      $idToken       Compact JWT id_token from the token endpoint
     * @param string|null $expectedNonce The nonce this RP sent in the
     *                                   authorization request (null skips
     *                                   the nonce check — never the case in
     *                                   this class's own flow, but the
     *                                   parameter stays optional for any
     *                                   future caller).
     * @return array<string,mixed> The validated id_token claims
     * @throws RuntimeException On ANY validation failure (fail-closed)
     */
    protected function validateIdToken(string $idToken, ?string $expectedNonce): array
    {
        // 🔑 Load the vendored firebase/php-jwt library. This is the SAME
        // library web/private_html/security/Jwt.php uses for SIGNula's OWN
        // token signing/verification when acting as an IdP; here it is used
        // the OTHER direction — as a Relying Party verifying a THIRD-PARTY
        // IdP's id_token against THAT IdP's own JWKS (never our own
        // KeyManager keys).
        try {
            JwtLibLoader::load();
        } catch (\Throwable $e) {
            throw new RuntimeException('JWT library unavailable for OIDC id_token validation: ' . $e->getMessage());
        }

        $discovery = $this->discover();

        if (empty($discovery['jwks_uri'])) {
            throw new RuntimeException('OIDC discovery document has no jwks_uri — cannot verify id_token signature');
        }

        $jwks = $this->getJwks((string) $discovery['jwks_uri']);

        try {
            // 🔒 RS256-pinned by default, matching SIGNula's own signing
            // policy (see Jwt::ALG's docblock on why algorithms are always
            // pinned rather than trusted from the token header — defeats
            // 'alg:none' and signature-algorithm-confusion attacks). A JWK
            // entry without its own "alg" member falls back to this default;
            // one that DOES specify a different alg is honoured by
            // parseKeySet() but JWT::decode() below still requires the
            // token's own header alg to match whatever that specific key
            // declares, so no confusion is possible either way.
            $keys = FirebaseJWK::parseKeySet($jwks, 'RS256');
        } catch (\Throwable $e) {
            throw new RuntimeException('Failed to parse OIDC provider JWKS: ' . $e->getMessage());
        }

        // ⏱️ Bounded clock-skew leeway — same setting (and reset-in-finally
        // idiom) Jwt::verify() already uses for SIGNula's OWN token
        // verification, reused here for a third-party IdP's clock drift.
        $leeway = (int) getSetting('jwt.leeway_seconds', 60);
        if ($leeway < 0) {
            $leeway = 0;
        }
        $previousLeeway = FirebaseJWT::$leeway;
        FirebaseJWT::$leeway = $leeway;

        try {
            // FirebaseJWT::decode() selects the key by the token's `kid`
            // header, verifies the signature against it, and checks
            // exp/iat/nbf. A `kid` with no matching key, or `alg:none`
            // (which can never match a pinned Key's algorithm), both fail
            // here.
            $decoded = FirebaseJWT::decode($idToken, $keys);
        } catch (FirebaseExpiredException $e) {
            throw new RuntimeException('OIDC id_token has expired');
        } catch (FirebaseSignatureInvalidException $e) {
            throw new RuntimeException('OIDC id_token signature verification failed');
        } catch (\Throwable $e) {
            throw new RuntimeException('OIDC id_token verification failed: ' . $e->getMessage());
        } finally {
            FirebaseJWT::$leeway = $previousLeeway;
        }

        // 📦 stdClass (possibly nested) -> plain associative array.
        $claims = json_decode((string) json_encode($decoded), true);

        if (!is_array($claims)) {
            throw new RuntimeException('OIDC id_token payload could not be decoded');
        }

        // 🔍 iss — MUST exactly match the issuer we ran discovery against
        // (OIDC Core 1.0 §2, §3.1.3.7).
        if (empty($claims['iss']) || rtrim((string) $claims['iss'], '/') !== $this->issuer) {
            throw new RuntimeException('OIDC id_token issuer (iss) mismatch');
        }

        // 🔍 aud — MUST contain our client_id (OIDC Core 1.0 §3.1.3.7); aud
        // may legally be a single string or an array of strings.
        $aud = $claims['aud'] ?? null;
        $audienceOk = is_string($aud)
            ? hash_equals($this->clientId, $aud)
            : (is_array($aud) && in_array($this->clientId, $aud, true));

        if (!$audienceOk) {
            throw new RuntimeException('OIDC id_token audience (aud) mismatch');
        }

        // 🔍 azp — OIDC Core §3.1.3.7: REQUIRED when aud has multiple
        // values, and if present it MUST equal our client_id.
        if (is_array($aud) && count($aud) > 1) {
            if (empty($claims['azp']) || !hash_equals($this->clientId, (string) $claims['azp'])) {
                throw new RuntimeException('OIDC id_token azp mismatch for a multi-audience token');
            }
        }

        // 🔍 nonce — replay protection (OIDC Core 1.0 §3.1.3.7). This class
        // always sends one via getAuthorizationUrl(), so it is always
        // expected back here.
        if ($expectedNonce !== null) {
            if (empty($claims['nonce']) || !hash_equals($expectedNonce, (string) $claims['nonce'])) {
                throw new RuntimeException('OIDC id_token nonce mismatch (possible replay attack)');
            }
        }

        return $claims;
    }

    // ========================================================================
    // 🔎 OIDC DISCOVERY + JWKS (cached)
    // ========================================================================

    /**
     * 🔎 Fetch (and cache) the OIDC Discovery document
     *
     * @see https://openid.net/specs/openid-connect-discovery-1_0.html (OIDC Discovery 1.0 §4)
     *
     * @return array<string,mixed> The discovery document
     * @throws RuntimeException If the issuer is unset, the document cannot
     *                          be fetched, its `issuer` field does not match,
     *                          or a required endpoint is missing.
     */
    protected function discover(): array
    {
        if (empty($this->issuer)) {
            throw new RuntimeException('Cannot perform OIDC discovery without an issuer URL');
        }

        // 1️⃣ Per-request static cache — shared by every GenericOidcProvider
        // instance in this PHP process (mirrors Entitlements::$requestCache).
        if (isset(self::$discoveryCache[$this->issuer])) {
            return self::$discoveryCache[$this->issuer];
        }

        $ttl = (int) getSetting('oauth.oidc.discovery_cache_ttl', self::DEFAULT_DISCOVERY_CACHE_TTL);
        $cacheKey = 'signula_oidc_discovery_' . md5($this->issuer);

        // 2️⃣ Optional cross-request cache (APCu) — same function_exists()
        // guard idiom as Entitlements::resolveAll(), so hosts without APCu
        // (some Dreamhost shared-hosting configurations) simply skip this
        // layer and fall back to a per-request fetch.
        if ($ttl > 0 && function_exists('apcu_fetch')) {
            $hit = apcu_fetch($cacheKey, $success);
            if ($success && is_array($hit)) {
                self::$discoveryCache[$this->issuer] = $hit;
                return $hit;
            }
        }

        // 3️⃣ Fetch — per OIDC Discovery 1.0 §4.1, the document lives at
        // {issuer}/.well-known/openid-configuration (simple concatenation,
        // even when the issuer itself has a path component).
        $discoveryUrl = $this->issuer . '/.well-known/openid-configuration';
        $document = $this->makeHttpRequest($discoveryUrl, 'GET');

        // 🔒 §4.3 — the returned "issuer" MUST exactly match the issuer we
        // requested discovery for (protects against a misconfigured/
        // compromised reverse proxy serving different metadata at this URL).
        if (empty($document['issuer']) || rtrim((string) $document['issuer'], '/') !== $this->issuer) {
            throw new RuntimeException("OIDC discovery document issuer mismatch for provider '{$this->providerName}'");
        }

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $required) {
            if (empty($document[$required])) {
                throw new RuntimeException("OIDC discovery document missing required field '{$required}' for provider '{$this->providerName}'");
            }
        }

        self::$discoveryCache[$this->issuer] = $document;

        if ($ttl > 0 && function_exists('apcu_store')) {
            apcu_store($cacheKey, $document, $ttl);
        }

        return $document;
    }

    /**
     * 🔎 Fetch (and cache) a provider's JWKS document
     *
     * @param string $jwksUri The `jwks_uri` from the discovery document
     * @return array<string,mixed> The JWKS document (a `{"keys": [...]}` shape)
     * @throws RuntimeException If the document cannot be fetched or is malformed
     */
    protected function getJwks(string $jwksUri): array
    {
        if (isset(self::$jwksCache[$jwksUri])) {
            return self::$jwksCache[$jwksUri];
        }

        $ttl = (int) getSetting('oauth.oidc.discovery_cache_ttl', self::DEFAULT_DISCOVERY_CACHE_TTL);
        $cacheKey = 'signula_oidc_jwks_' . md5($jwksUri);

        if ($ttl > 0 && function_exists('apcu_fetch')) {
            $hit = apcu_fetch($cacheKey, $success);
            if ($success && is_array($hit)) {
                self::$jwksCache[$jwksUri] = $hit;
                return $hit;
            }
        }

        $jwks = $this->makeHttpRequest($jwksUri, 'GET');

        if (empty($jwks['keys']) || !is_array($jwks['keys'])) {
            throw new RuntimeException('OIDC provider JWKS document is missing/malformed (no "keys" array)');
        }

        self::$jwksCache[$jwksUri] = $jwks;

        if ($ttl > 0 && function_exists('apcu_store')) {
            apcu_store($cacheKey, $jwks, $ttl);
        }

        return $jwks;
    }

    // ========================================================================
    // 👤 USER INFO
    // ========================================================================

    /**
     * 👤 Get User Info
     *
     * Calls the IdP's userinfo endpoint (if one was advertised by
     * discovery), folds in the ALREADY-VALIDATED id_token claims from
     * {@see self::exchangeCodeForToken()} as a base/fallback (some IdPs
     * return a minimal userinfo payload), and cross-checks `sub` between the
     * two per OIDC Core 1.0 §5.3.2.
     *
     * @param string $accessToken Access token
     * @return array Normalized user data
     * @throws RuntimeException If retrieval/validation fails
     */
    public function getUserInfo(string $accessToken): array
    {
        try {
            $discovery = $this->discover();

            $rawData = [];

            if (!empty($discovery['userinfo_endpoint'])) {
                $headers = ['Authorization: Bearer ' . $accessToken];
                $rawData = $this->makeHttpRequest((string) $discovery['userinfo_endpoint'], 'GET', [], $headers);
            }

            $idTokenClaims = $this->idTokenClaims ?? [];

            // 🔍 OIDC Core 1.0 §5.3.2 — "the sub Claim in the UserInfo
            // Response MUST be verified to exactly match the sub Claim in
            // the ID Token". Reject a userinfo response describing a
            // DIFFERENT subject than the one that was actually authenticated
            // (defends against a compromised/misbehaving userinfo endpoint).
            if (!empty($rawData['sub']) && !empty($idTokenClaims['sub'])
                && !hash_equals((string) $idTokenClaims['sub'], (string) $rawData['sub'])
            ) {
                throw new RuntimeException('OIDC userinfo "sub" does not match id_token "sub" — possible token substitution');
            }

            // 🧺 id_token claims are the base layer (always present and
            // already verified); userinfo values — where present — take
            // priority, since the userinfo endpoint is typically the
            // richer/fresher source of profile data (OIDC Core 1.0 §5.3.1).
            $merged = array_merge(
                $idTokenClaims,
                array_filter($rawData, static fn ($value) => $value !== null && $value !== '')
            );

            return $this->normalizeUserData($merged);

        } catch (Exception $e) {
            error_log("Generic OIDC ({$this->providerName}) getUserInfo error: " . $e->getMessage());
            throw new RuntimeException('Failed to retrieve OIDC user information');
        }
    }

    /**
     * 🔄 Normalize User Data
     *
     * Maps standard OIDC claims (OIDC Core 1.0 §5.1 "Standard Claims") to
     * SIGNula's common provider-user shape.
     *
     * Standard claim shape:
     * {
     *   "sub": "248289761001",
     *   "email": "user@example.com",
     *   "email_verified": true,
     *   "name": "Jane Doe",
     *   "given_name": "Jane",
     *   "family_name": "Doe",
     *   "picture": "https://idp.example.com/avatar.jpg",
     *   "locale": "en-US"
     * }
     *
     * @param array $rawData Merged id_token claims + userinfo response
     * @return array Normalized user data
     * @throws RuntimeException If required fields (sub, email) are missing
     */
    protected function normalizeUserData(array $rawData): array
    {
        $givenName  = (string) ($rawData['given_name'] ?? '');
        $familyName = (string) ($rawData['family_name'] ?? '');

        $normalized = [
            'provider_id'    => (string) ($rawData['sub'] ?? ''),
            'email'          => (string) ($rawData['email'] ?? ''),
            'email_verified' => filter_var($rawData['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name'           => (string) ($rawData['name'] ?? trim($givenName . ' ' . $familyName)),
            'first_name'     => $givenName,
            'last_name'      => $familyName,
            'avatar'         => $rawData['picture'] ?? null,
            'locale'         => $rawData['locale'] ?? null,
        ];

        // ✅ Validate required fields
        if (empty($normalized['provider_id']) || empty($normalized['email'])) {
            throw new RuntimeException("Invalid user data from OIDC provider '{$this->providerName}' (missing sub or email — check requested scopes include 'email')");
        }

        return $normalized;
    }

    // ========================================================================
    // 🔄 REVOCATION
    // ========================================================================

    /**
     * 🔄 Revoke Access
     *
     * Best-effort token revocation via RFC 7009, ONLY if the IdP's discovery
     * document advertises a `revocation_endpoint` — unlike the hardcoded
     * per-provider classes, a generic connector cannot assume one exists.
     *
     * @param string $accessToken Access token to revoke
     * @return bool True if the revocation request succeeded
     */
    public function revokeAccess(string $accessToken): bool
    {
        try {
            $discovery = $this->discover();

            if (empty($discovery['revocation_endpoint'])) {
                error_log("Generic OIDC ({$this->providerName}): provider does not advertise a revocation_endpoint; skipping.");
                return false;
            }

            $this->makeHttpRequest((string) $discovery['revocation_endpoint'], 'POST', [
                'token'         => $accessToken,
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            return true;

        } catch (Exception $e) {
            error_log("Generic OIDC ({$this->providerName}) revoke access error: " . $e->getMessage());
            return false;
        }
    }
}

// ✅ GenericOidcProvider class loaded successfully
