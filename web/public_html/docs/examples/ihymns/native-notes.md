<!--
============================================================================
iHymns × SIGNula.id reference integration — native-notes.md
============================================================================
COPY the guidance/snippets below into the iHymns native app repos
(github.com/MWBMPartners/iHymns — iOS + Android targets) — NOT a live
SIGNula runtime route. This adapts SIGNula.com/docs/oidc-native.php for
iHymns specifically (real iHymns placeholders instead of com.example.app).
-->

# iHymns native (iOS / Android) — "Sign in with SIGNula.id"

## Never use an embedded WebView

Always launch the SIGNula authorize URL in the **system browser** —
`ASWebAuthenticationSession` on iOS, Chrome Custom Tabs on Android. An
embedded `WKWebView`/`UIWebView`/Android `WebView` breaks the security
model PKCE relies on (the host app could intercept the `code` before the
callback even fires) and, on iOS, Apple's App Store review guidelines
generally require the system session API for third-party sign-in flows
anyway.

## Register iHymns as a public client

Native apps cannot hold a `client_secret` safely — it would ship inside
the app binary, which is reverse-engineerable. Register iHymns' native
apps as a **`public`** client (see README.md Step 1 for the full
registration walkthrough). Use these exact redirect URIs (substitute your
real bundle ID / package name once decided, then treat these strings as
fixed once registered — a mismatch is rejected before any redirect):

| Platform | `redirect_uri` |
|----------|------------------|
| iOS custom scheme | `com.mwbm.ihymns:/oauth/callback` |
| Android custom scheme | `com.mwbm.ihymns://oauth/callback` |
| Android/iOS **App Link / Universal Link** (preferred — see below) | `https://ihymns.app/auth/signula/callback` |

**Prefer the https App Link / Universal Link over a bare custom scheme**
where your platform supports it: a custom scheme (`com.mwbm.ihymns:`) can
in principle be claimed by another app on the same device, which could
intercept the OAuth callback (`code`+`state`) if PKCE somehow weren't
already mitigating it. An https App Link is domain-verified by the OS
(`apple-app-site-association` on iOS, Digital Asset Links on Android) and
can only be claimed by the app that controls `ihymns.app`. If you can't
set up App Links immediately, the custom scheme + PKCE is still
acceptably secure (PKCE is the actual defence against code interception —
the App Link is defence-in-depth), just register it and move on; add the
App Link in a follow-up pass.

## iOS — `ASWebAuthenticationSession`

```swift
import AuthenticationServices

let clientId = "IHYMNS_CLIENT_ID"                    // TODO(iHymns): your real client_id
let redirectUri = "com.mwbm.ihymns:/oauth/callback"

// 1) Generate PKCE — same algorithm as every other platform in this
//    reference (RFC 7636 S256): 32+ random bytes, base64url-encoded
//    verifier; SHA-256 + base64url for the challenge.
let codeVerifier = generateCodeVerifier()             // 43-128 char base64url string
let codeChallenge = try sha256(codeVerifier).base64url()

let state = generateRandomString(16)
let nonce = generateRandomString(16)
// TODO(iHymns): persist codeVerifier/state/nonce for Step 3 below —
// an in-memory property on the view controller/coordinator driving the
// sign-in flow is sufficient (ASWebAuthenticationSession's callback
// fires on the same in-process flow, unlike a server redirect).

var components = URLComponents(string: "https://signula.id/oauth/authorize-idp")!
components.queryItems = [
    URLQueryItem(name: "response_type", value: "code"),
    URLQueryItem(name: "client_id", value: clientId),
    URLQueryItem(name: "redirect_uri", value: redirectUri),
    URLQueryItem(name: "scope", value: "openid email profile offline_access"),
    URLQueryItem(name: "code_challenge", value: codeChallenge),
    URLQueryItem(name: "code_challenge_method", value: "S256"),
    URLQueryItem(name: "state", value: state),
    URLQueryItem(name: "nonce", value: nonce),
]

let session = ASWebAuthenticationSession(
    url: components.url!,
    callbackURLScheme: "com.mwbm.ihymns"
) { callbackURL, error in
    guard let url = callbackURL else {
        // TODO(iHymns): surface error?.localizedDescription to the user —
        // this also fires if the user cancels the system sheet.
        return
    }
    let cb = URLComponents(url: url, resolvingAgainstBaseURL: true)!
    let items = cb.queryItems ?? []

    if let oauthError = items.first(where: { $0.name == "error" })?.value {
        // TODO(iHymns): handle e.g. "access_denied" (user tapped Deny)
        return
    }
    guard
        let code = items.first(where: { $0.name == "code" })?.value,
        let returnedState = items.first(where: { $0.name == "state" })?.value,
        returnedState == state // CSRF check — reject on any mismatch
    else {
        return
    }

    self.exchangeCode(code: code, codeVerifier: codeVerifier, expectedNonce: nonce)
}

session.prefersEphemeralWebBrowserSession = false // keep SIGNula's SSO session (don't force a fresh login every time)
session.presentationContextProvider = self
session.start()
```

### iOS token exchange + id_token verification

```swift
// POST https://signula.id/oauth/token
// grant_type=authorization_code&code=...&redirect_uri=com.mwbm.ihymns%3A%2Foauth%2Fcallback
// &code_verifier=...&client_id=IHYMNS_CLIENT_ID   (NO client_secret — public client)

// Response: { access_token, token_type: "Bearer", expires_in, id_token,
//             refresh_token? (present only if offline_access was granted), scope }

// Verify id_token before trusting anything in it:
//   1. Fetch https://signula.id/.well-known/jwks.json (cache — SIGNula
//      rotates keys infrequently), find the key matching the token's `kid`.
//   2. Verify the RS256 signature with a JWT library (e.g. JWTKit,
//      Auth0's swift-jwt) — reject any other `alg`.
//   3. assert claims.iss == "https://signula.id"
//   4. assert claims.aud == clientId
//   5. assert claims.nonce == expectedNonce   // the value generated above
//   6. assert claims.exp is in the future (library-enforced by most JWT libs)
//   7. claims.sub is iHymns' pairwise ID for this user — store it, it's
//      the durable key for Step 5/mapping (see README.md Step 4).
```

## Android — Chrome Custom Tabs

```kotlin
import androidx.browser.customtabs.CustomTabsIntent
import android.net.Uri

val clientId = "IHYMNS_CLIENT_ID"                      // TODO(iHymns): your real client_id
val redirectUri = "com.mwbm.ihymns://oauth/callback"   // or the https App Link, if configured

val codeVerifier = generateCodeVerifier()
val codeChallenge = sha256(codeVerifier).toBase64Url()
val state = generateRandomString(16)
val nonce = generateRandomString(16)
// TODO(iHymns): persist codeVerifier/state/nonce (e.g. a ViewModel field
// or EncryptedSharedPreferences keyed by `state`) — Android may recreate
// your Activity while Custom Tabs is in the foreground, so don't rely on
// a plain in-memory local variable surviving the round trip.

val authorizeUrl = Uri.Builder()
    .scheme("https").authority("signula.id").path("/oauth/authorize-idp")
    .appendQueryParameter("response_type", "code")
    .appendQueryParameter("client_id", clientId)
    .appendQueryParameter("redirect_uri", redirectUri)
    .appendQueryParameter("scope", "openid email profile offline_access")
    .appendQueryParameter("code_challenge", codeChallenge)
    .appendQueryParameter("code_challenge_method", "S256")
    .appendQueryParameter("state", state)
    .appendQueryParameter("nonce", nonce)
    .build().toString()

CustomTabsIntent.Builder().build().launchUrl(context, Uri.parse(authorizeUrl))
```

`AndroidManifest.xml` deep-link declaration (custom scheme shown; prefer
an `autoVerify` App Link to `ihymns.app` where possible):

```xml
<activity android:name=".SignulaCallbackActivity" android:exported="true">
    <intent-filter android:autoVerify="true">
        <action android:name="android.intent.action.VIEW" />
        <category android:name="android.intent.category.DEFAULT" />
        <category android:name="android.intent.category.BROWSABLE" />
        <data
            android:scheme="com.mwbm.ihymns"
            android:host="oauth"
            android:path="/callback" />
    </intent-filter>
</activity>
```

In `SignulaCallbackActivity`, read `code`/`state`/`error` from the
`Intent`'s data `Uri`, verify `state` against what you persisted before
launching Custom Tabs (reject on any mismatch — CSRF defence), then POST
the token exchange exactly as shown in `callback.js`'s Pattern B (or, if
iHymns has a backend, hand `code`+`codeVerifier` to it — Pattern A is
still preferable even from a native app if a backend is available, for
the same reasons given there).

## Verify the id_token (Android)

Same checks as iOS, using a JVM JWT library (e.g. `java-jwt`,
`nimbus-jose-jwt`) against `https://signula.id/.well-known/jwks.json`:
signature (RS256 only), `iss == "https://signula.id"`,
`aud == clientId`, `nonce == expectedNonce`, `exp` in the future. Extract
`sub` (pairwise, per-client — the durable key), `email`, `name`.

## Secure token storage

| Platform | Store in | Never store in |
|----------|-----------|------------------|
| iOS | Keychain (`kSecClassGenericPassword`) | `UserDefaults`, a plist, application support files |
| Android | `EncryptedSharedPreferences` (backed by Android Keystore) | plain `SharedPreferences`, external storage, logs |

```swift
// iOS — save
let query: [String: Any] = [
    kSecClass as String: kSecClassGenericPassword,
    kSecAttrAccount as String: "ihymns_signula_refresh_token",
    kSecValueData as String: refreshToken.data(using: .utf8)!,
]
SecItemDelete(query as CFDictionary) // clear any previous value first
SecItemAdd(query as CFDictionary, nil)
```

```kotlin
// Android — save
val masterKey = MasterKey.Builder(context)
    .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
    .build()
val encryptedPrefs = EncryptedSharedPreferences.create(
    context, "ihymns_signula_auth", masterKey,
    EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
    EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
)
encryptedPrefs.edit().putString("refresh_token", refreshToken).apply()
```

## Refresh-token rotation

Access tokens are short-lived (`expires_in`, 900s by default). Only
possible if iHymns requested and was granted `offline_access` at sign-in
time (tick it during client registration / include it in `scope`):

```
POST https://signula.id/oauth/token
grant_type=refresh_token
&refresh_token=STORED_REFRESH_TOKEN
&client_id=IHYMNS_CLIENT_ID
```

**Single-use rotation is mandatory to handle correctly**: every refresh
response contains a **new** `refresh_token` — the one you presented is now
spent. Overwrite the Keychain/Keystore entry with the new value
**immediately** on every successful refresh. If a refresh call ever fails
with an error indicating the token was already used/revoked, treat it as
a potential compromise: clear all locally stored tokens and force the user
to sign in again — do not retry with the same (now-dead) refresh token. A
new `id_token` is not re-minted on refresh (OIDC Core doesn't require it);
the one you verified at sign-in remains valid until its own `exp`.

## Logout

```
POST https://signula.id/oauth/revoke
token=STORED_REFRESH_TOKEN
&token_type_hint=refresh_token
&client_id=IHYMNS_CLIENT_ID
```

Then clear the Keychain/Keystore entries. Per RFC 7009, `/oauth/revoke`
always returns `200` with an empty body, whether the token was valid or
not — this is intentional (SIGNula never leaks whether a given token was
valid), not a bug in your integration.

## Cross-links

- Full endpoint/parameter reference: `/docs/signin-with-signula`
- Native guide this adapts: `/docs/oidc-native`
- Security checklist: `/docs/oidc-security`
- Server-side pairing (if iHymns' native apps talk to an iHymns backend
  that itself needs a SIGNula-issued token): `/docs/oidc-server`
