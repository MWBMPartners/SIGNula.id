<?php
/**
 * ============================================================================
 * 📱 SIGNula OIDC — Native / Mobile App Integration Guide
 * ============================================================================
 * Platform-specific guidance for iOS and Android apps. Covers system browser
 * usage (ASWebAuthenticationSession, Custom Tabs), public client registration,
 * PKCE, redirect URI schemes, and secure token storage (Keychain, Keystore).
 *
 * For the full OAuth/OIDC reference (all endpoints, parameters, security),
 * see /docs/signin-with-signula.
 *
 * @package    SIGNula
 * @subpackage Documentation
 * @version    1.0.0
 * @since      2.9.0-beta (G-001)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */
$pageTitle = 'OIDC — Native / Mobile App Integration - SIGNula Documentation';
$metaDescription = 'Integrate "Sign in with SIGNula.id" in iOS/Android apps. System browser, PKCE, app schemes, Keychain/Keystore, public clients.';
$currentPage = 'docs';
$currentDoc = 'oidc-native';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-header.php';
?>
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-3">
                <?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'docs-sidebar.php'; ?>
            </div>
            <div class="col-lg-9">
                <h1 class="display-5 fw-bold mb-4">Native / Mobile App Integration</h1>
                <p class="lead">
                    Add "Sign in with SIGNula.id" to your iOS or Android app
                    using the system browser and secure token storage.
                </p>

                <div class="alert alert-info">
                    <strong>This is the mobile guide.</strong> If you&#39;re building
                    a web app or SPA, see
                    <a href="/docs/oidc-web" class="alert-link">Web / SPA / PWA</a>.
                    For server-backend details, see
                    <a href="/docs/oidc-server" class="alert-link">Server-side</a>.
                </div>

                <div class="alert alert-warning">
                    <strong>Never use a WebView!</strong> Always use the system
                    browser (iOS: <code>ASWebAuthenticationSession</code>, Android:
                    Chrome Custom Tabs). Embedded WebViews break PKCE and expose
                    tokens to your app&#39;s JavaScript scope.
                </div>

                <!-- ============================================================ -->
                <!-- CLIENT REGISTRATION -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Register as a public client</h2>
                <p>
                    Native apps cannot securely store a <code>client_secret</code>
                    (it&#39;s in the binary, which is reverse-engineerable). Instead,
                    register your app as a <code>public</code> client.
                </p>
                <ol>
                    <li>Sign into your Partner Dashboard</li>
                    <li>Go to <strong>Dashboard &rarr; OAuth / OIDC Clients</strong></li>
                    <li>Click <strong>Register New OAuth Client</strong></li>
                    <li>Fill in:
                        <ul>
                            <li><strong>Client Name</strong>: Shown to users</li>
                            <li><strong>Client Type</strong>: <code>public</code> (no secret)</li>
                            <li><strong>Redirect URI(s)</strong>: Enter your app scheme(s)
                                exactly as registered:
                                <ul>
                                    <li><strong>iOS:</strong> <code>com.example.app:/oauth/callback</code></li>
                                    <li><strong>Android:</strong> <code>com.example.app://oauth/callback</code>
                                        or an https App Link <code>https://app.example.com/auth/callback</code></li>
                                </ul>
                            </li>
                            <li><strong>Scopes</strong>: Tick <code>profile</code>, <code>email</code></li>
                        </ul>
                    </li>
                    <li><strong>Copy your <code>client_id</code></strong> (no secret is issued).
                        Store it in your app binary; it&#39;s a public value.</li>
                </ol>

                <!-- ============================================================ -->
                <!-- PKCE + SYSTEM BROWSER -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Open the authorize endpoint in the system browser</h2>
                <p>
                    PKCE is <strong>mandatory</strong>. Generate a
                    <code>code_verifier</code> (43–128 chars, high-entropy),
                    compute the SHA-256 hash, and send it to SIGNula.
                </p>

                <h3 class="h5 mt-4">iOS (Swift)</h3>
                <pre class="bg-dark text-white p-3 rounded"><code>import AuthenticationServices

let clientId = "YOUR_CLIENT_ID"
let redirectUri = "com.example.app:/oauth/callback"

// Generate PKCE
let codeVerifier = generateCodeVerifier()  // 128 random bytes, base64url
let codeChallenge = try sha256(codeVerifier).base64url()

let state = generateRandomString(16)
let nonce = generateRandomString(16)

// Build the authorize URL
var components = URLComponents(string: "https://signula.id/oauth/authorize-idp")!
components.queryItems = [
    URLQueryItem(name: "client_id", value: clientId),
    URLQueryItem(name: "redirect_uri", value: redirectUri),
    URLQueryItem(name: "response_type", value: "code"),
    URLQueryItem(name: "scope", value: "openid email profile"),
    URLQueryItem(name: "code_challenge", value: codeChallenge),
    URLQueryItem(name: "code_challenge_method", value: "S256"),
    URLQueryItem(name: "state", value: state),
    URLQueryItem(name: "nonce", value: nonce),
]

let authURL = components.url!

// Launch the system browser
let session = ASWebAuthenticationSession(
    url: authURL,
    callbackURLScheme: "com.example.app"
) { callbackURL, error in
    guard let url = callbackURL else {
        print("Auth failed: \(error?.localizedDescription ?? "unknown")")
        return
    }

    // Parse the callback
    let components = URLComponents(url: url, resolvingAgainstBaseURL: true)!
    guard let code = components.queryItems?.first(where: { $0.name == "code" })?.value else {
        print("Missing code in callback")
        return
    }

    // Exchange code for tokens
    self.exchangeCode(code: code, codeVerifier: codeVerifier)
}

session.prefersEphemeralWebBrowserSession = false
session.presentationContextProvider = self
session.start()
</code></pre>

                <h3 class="h5 mt-4">Android (Kotlin)</h3>
                <pre class="bg-dark text-white p-3 rounded"><code>import androidx.browser.customtabs.CustomTabsIntent
import android.net.Uri

val clientId = "YOUR_CLIENT_ID"
val redirectUri = "com.example.app://oauth/callback"

// Generate PKCE
val codeVerifier = generateCodeVerifier()
val codeChallenge = sha256(codeVerifier).toBase64Url()

val state = generateRandomString(16)
val nonce = generateRandomString(16)

// Build the authorize URL
val authorizeUrl = Uri.Builder()
    .scheme("https")
    .authority("signula.id")
    .path("/oauth/authorize-idp")
    .appendQueryParameter("client_id", clientId)
    .appendQueryParameter("redirect_uri", redirectUri)
    .appendQueryParameter("response_type", "code")
    .appendQueryParameter("scope", "openid email profile")
    .appendQueryParameter("code_challenge", codeChallenge)
    .appendQueryParameter("code_challenge_method", "S256")
    .appendQueryParameter("state", state)
    .appendQueryParameter("nonce", nonce)
    .build()
    .toString()

// Launch Custom Tabs
val customTabsIntent = CustomTabsIntent.Builder().build()
customTabsIntent.launchUrl(context, Uri.parse(authorizeUrl))

// Your app scheme deep-link handler will receive the callback:
// adb shell am start -a android.intent.action.VIEW \
//   -d "com.example.app://oauth/callback?code=...&amp;state=..."
</code></pre>

                <!-- ============================================================ -->
                <!-- TOKEN EXCHANGE -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Exchange the code in-app</h2>
                <p>
                    In your deep-link handler, extract the <code>code</code> and
                    exchange it server-side (or in-app if your backend is unavailable).
                </p>

                <h3 class="h5 mt-4">Exchange endpoint call</h3>
                <pre class="bg-dark text-white p-3 rounded"><code>// POST https://signula.id/oauth/token
// Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&amp;code=AUTH_CODE
&amp;redirect_uri=com.example.app%3A%2F%2Foauth%2Fcallback
&amp;code_verifier=CODE_VERIFIER_FROM_STEP_1
&amp;client_id=YOUR_CLIENT_ID
// Note: NO client_secret (this is a public client)</code></pre>

                <p>
                    <strong>Success response:</strong>
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>{
  "access_token": "eyJhbGc...",
  "token_type": "Bearer",
  "expires_in": 900,
  "id_token": "eyJhbGc...",
  "refresh_token": "a3f8c2..."
}</code></pre>

                <!-- ============================================================ -->
                <!-- VERIFY ID_TOKEN -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Verify the id_token</h2>
                <p>
                    The <code>id_token</code> is an RS256-signed JWT. Verify it
                    before trusting any claims:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>// 1. Fetch the public keys
let jwks = try JSONDecoder().decode(
    JWKSet.self,
    from: URLSession.shared.data(from: URL(string: "https://signula.id/.well-known/jwks.json")!).0
)

// 2. Verify the signature (using a library like JWTKit or Auth0's swift-jwt)
let key = try JWK(data: jwks.keys.first { $0.kid == idTokenHeader.kid }!)
let verified = try JWT&lt;IDTokenClaims&gt;.verify(
    idToken,
    using: key
)

// 3. Check the essential claims
assert(verified.iss == "https://signula.id",      "bad issuer")
assert(verified.aud == clientId,                  "bad audience")
assert(verified.nonce == expectedNonce,          "bad nonce — replay attack?")
assert(verified.exp &gt; Date(),                     "token expired")

// 4. Use the user ID
let userId = verified.sub  // stable, pairwise ID for this app
let email  = verified.email
let name   = verified.name

// Save to Keychain and proceed
saveToKeychain(token: accessToken, key: "auth_token")
</code></pre>

                <!-- ============================================================ -->
                <!-- SECURE TOKEN STORAGE -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Secure token storage</h2>

                <h3 class="h5 mt-4">iOS — Keychain</h3>
                <p>
                    Store tokens in the iOS Keychain, NOT in UserDefaults or a local file.
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>import Security

func saveToKeychain(token: String, key: String) {
    let data = token.data(using: .utf8)!
    let query: [String: Any] = [
        kSecClass as String: kSecClassGenericPassword,
        kSecAttrAccount as String: key,
        kSecValueData as String: data,
    ]

    SecItemDelete(query as CFDictionary)  // Delete old value
    SecItemAdd(query as CFDictionary, nil)
}

func getFromKeychain(key: String) -&gt; String? {
    let query: [String: Any] = [
        kSecClass as String: kSecClassGenericPassword,
        kSecAttrAccount as String: key,
        kSecReturnData as String: true,
    ]

    var result: AnyObject?
    let status = SecItemCopyMatching(query as CFDictionary, &amp;result)
    guard status == errSecSuccess, let data = result as? Data else {
        return nil
    }
    return String(data: data, encoding: .utf8)
}
</code></pre>

                <h3 class="h5 mt-4">Android — Keystore / EncryptedSharedPreferences</h3>
                <p>
                    Use <code>EncryptedSharedPreferences</code> from the Android
                    Security library (backed by KeyStore).
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

val masterKey = MasterKey.Builder(context)
    .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
    .build()

val encryptedPrefs = EncryptedSharedPreferences.create(
    context,
    "auth_prefs",
    masterKey,
    EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
    EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
)

// Save tokens
encryptedPrefs.edit().apply {
    putString("auth_token", accessToken)
    putString("refresh_token", refreshToken)
    putString("id_token", idToken)
    apply()
}

// Retrieve tokens
val token = encryptedPrefs.getString("auth_token", null)
</code></pre>

                <!-- ============================================================ -->
                <!-- REFRESH TOKENS -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Token refresh</h2>
                <p>
                    Access tokens are short-lived (900 seconds / 15 min). When
                    they expire, use the <code>refresh_token</code> to get a new one:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>// POST https://signula.id/oauth/token
// Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token
&amp;refresh_token=REFRESH_TOKEN
&amp;client_id=YOUR_CLIENT_ID
</code></pre>
                <p class="small text-secondary">
                    <strong>Single-use rotation:</strong> The response contains a
                    NEW <code>refresh_token</code>; the old one is now spent. Update
                    your Keychain/Keystore with the new token. If refresh fails
                    (token revoked or compromised), prompt the user to sign in again.
                </p>

                <!-- ============================================================ -->
                <!-- LOGOUT -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Logout</h2>
                <p>
                    When the user logs out, revoke the refresh token and clear storage:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>// POST https://signula.id/oauth/revoke
// Content-Type: application/x-www-form-urlencoded

token=REFRESH_TOKEN
&amp;token_type_hint=refresh_token
&amp;client_id=YOUR_CLIENT_ID
</code></pre>
                <p>Then clear the Keychain/Keystore:</p>
                <pre class="bg-dark text-white p-3 rounded"><code>// iOS
SecItemDelete([
    kSecClass as String: kSecClassGenericPassword,
    kSecAttrAccount as String: "auth_token",
] as CFDictionary)

// Android
encryptedPrefs.edit().apply {
    remove("auth_token")
    remove("refresh_token")
    apply()
}
</code></pre>

                <!-- ============================================================ -->
                <!-- REDIRECT URI SCHEMES -->
                <!-- ============================================================ -->
                <h2 class="h3 mt-5 mb-3">Redirect URI schemes (registration + config)</h2>

                <h3 class="h5 mt-4">iOS URL scheme</h3>
                <p>
                    Register your app scheme in <code>Info.plist</code>:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;key&gt;CFBundleURLTypes&lt;/key&gt;
&lt;array&gt;
    &lt;dict&gt;
        &lt;key&gt;CFBundleURLSchemes&lt;/key&gt;
        &lt;array&gt;
            &lt;string&gt;com.example.app&lt;/string&gt;
        &lt;/array&gt;
    &lt;/dict&gt;
&lt;/array&gt;
</code></pre>
                <p>
                    Then handle the callback in your app delegate:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>func application(
    _ app: UIApplication,
    open url: URL,
    options: [UIApplication.OpenURLOptionsKey: Any] = [:]
) -&gt; Bool {
    if url.scheme == "com.example.app" {
        NotificationCenter.default.post(name: NSNotification.Name("OAuthCallbackReceived"), object: url)
        return true
    }
    return false
}
</code></pre>

                <h3 class="h5 mt-4">Android App Links (preferred) or custom scheme</h3>
                <p>
                    For better security, register an Android App Link (https-based) instead
                    of a custom scheme. This prevents other apps from intercepting the callback.
                </p>
                <p>
                    Or, if using a custom scheme, declare it in <code>AndroidManifest.xml</code>:
                </p>
                <pre class="bg-dark text-white p-3 rounded"><code>&lt;activity android:name=".OAuthCallbackActivity"&gt;
    &lt;intent-filter android:autoVerify="true"&gt;
        &lt;action android:name="android.intent.action.VIEW" /&gt;
        &lt;category android:name="android.intent.category.DEFAULT" /&gt;
        &lt;category android:name="android.intent.category.BROWSABLE" /&gt;
        &lt;data
            android:scheme="com.example.app"
            android:host="oauth"
            android:path="/callback" /&gt;
    &lt;/intent-filter&gt;
&lt;/activity&gt;
</code></pre>

                <!-- ============================================================ -->
                <!-- CROSS-LINKS -->
                <!-- ============================================================ -->
                <div class="alert alert-secondary mt-5">
                    <strong>Full reference:</strong>
                    <a href="/docs/signin-with-signula" class="alert-link">Sign in with SIGNula.id</a>
                    (all parameters, error responses, OIDC spec details).
                    <br>
                    <strong>For web/SPA?</strong>
                    <a href="/docs/oidc-web" class="alert-link">Web / SPA / PWA guide</a>.
                    <br>
                    <strong>Security best practices:</strong>
                    <a href="/docs/oidc-security" class="alert-link">Security Checklist</a>.
                </div>
            </div>
        </div>
    </div>
</section>
<?php require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'private_html' . DIRECTORY_SEPARATOR . 'layout' . DIRECTORY_SEPARATOR . 'public-footer.php'; ?>
