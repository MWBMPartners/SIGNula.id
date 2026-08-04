<?php
declare(strict_types=1);

/**
 * ============================================================================
 * 🔏 SIGNula - SAML HTTP-Redirect Binding Codec + Detached Signature (C1)
 * ============================================================================
 *
 * Purpose:
 *   Implements the SAML 2.0 HTTP-Redirect binding's two core primitives —
 *   NEITHER of which is XML-DSig (plan §3.1 "C1"):
 *     1. DEFLATE encoding: `SAMLRequest`/`SAMLResponse` = base64(raw
 *        DEFLATE(xml)) — RFC 1951 raw deflate, NOT gzip/zlib-wrapped.
 *     2. A DETACHED signature over the raw, percent-encoded query string
 *        octets (`openssl_sign`/`openssl_verify`, SHA-256) — the same
 *        primitive class as {@see WebAuthnHandler::verifyAssertionSignature()}
 *        already uses elsewhere in this codebase, NOT an XML signature.
 *
 *   Both are pure PHP-core primitives (ext-zlib + ext-openssl) — no vendored
 *   library needed, and (per the plan's risk-asymmetry argument, §3.4 item
 *   4) safe to build and exercise in the normal build cycle: this is the
 *   SAME primitive class already red-teamed via WebAuthn, not the
 *   attacker-XML-parsing class of risk XML-DSig verification (C3) carries.
 *
 * 🔒 Security-critical details (plan §6.3 "C1"):
 *   - The signed/verified octets are reconstructed from the RAW query
 *     string substrings, in the SPEC-MANDATED order
 *     (`{param}={value}&RelayState={value}&SigAlg={value}`, RelayState
 *     omitted if absent) — NEVER re-encoded from a parsed `$_GET` array,
 *     which is the classic interop/security pitfall (PHP's array parsing
 *     and re-`http_build_query()`-ing does not always round-trip
 *     percent-encoding byte-for-byte: case of hex digits, `+` vs `%20`,
 *     etc. — any drift there would make a genuinely-valid signature appear
 *     invalid, or worse, could be abused to slip a differently-interpreted
 *     value past validation on one path while another path reads a
 *     different decoded value).
 *   - `SigAlg` is compared via `hash_equals()` against an ALLOWLIST OF ONE
 *     (`…#rsa-sha256`) BEFORE any cryptography runs — SHA-1/DSA/HMAC
 *     downgrade attempts are rejected outright, never "verified" with a
 *     weaker algorithm.
 *   - `openssl_verify()`'s return value is checked with `=== 1` ONLY (never
 *     a loose/truthy check) — `-1` (error) casts to `true` in PHP's loose
 *     boolean context, the exact discipline {@see WebAuthnHandler} already
 *     documents and enforces.
 *
 * PHP Version: 8.3+ (developed/tested on 8.4). Requires ext-zlib, ext-openssl.
 *
 * @package    SIGNula
 * @subpackage Security
 * @version    1.0.0
 * @since      2.9.0-beta (G-001 Phase B, #100)
 * @link       https://docs.oasis-open.org/security/saml/v2.0/saml-bindings-2.0-os.pdf (SAML 2.0 Bindings — §3.4 HTTP Redirect Binding)
 * @link       https://www.rfc-editor.org/rfc/rfc1951 (DEFLATE Compressed Data Format)
 *
 * @see web/private_html/auth/WebAuthnHandler.php (the `=== 1` / raw-primitive discipline this mirrors)
 * @see web/private_html/auth/SamlAuthnRequestService.php (the primary caller — inbound AuthnRequest/LogoutRequest verification)
 * @see web/private_html/auth/SamlLogoutService.php (LogoutRequest/LogoutResponse — both directions use this codec)
 *
 * Copyright (c) 2025-2026 MWBM Partners Ltd (t/a MWservices). All rights reserved.
 * ============================================================================
 */

// 🚫 Prevent direct access
if (!defined('SIGNULA_INIT')) {
    http_response_code(403);
    die('Direct access not permitted');
}

class SamlRedirectBinding
{
    /** @var string The ONLY SigAlg this facade will ever sign with or accept on verify (allowlist of one). */
    public const SIG_ALG_RSA_SHA256 = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    /** @var int Defensive cap on a decoded RelayState (spec caps senders at 80 bytes; we tolerate more but never unbounded). */
    private const MAX_RELAY_STATE_LENGTH = 1024;

    // ========================================================================
    // 📦 DEFLATE CODEC
    // ========================================================================

    /**
     * 📦 Encode an XML message for the HTTP-Redirect binding.
     *
     * `gzdeflate()` produces RAW RFC 1951 deflate data (no zlib/gzip
     * header/trailer) — exactly the "DEFLATE Encoding" the SAML Bindings
     * spec requires, verified against this PHP runtime (not merely assumed).
     *
     * @param string $xml Serialized SAML XML message.
     * @return string Base64-encoded, deflate-compressed payload.
     * @throws \RuntimeException If compression fails (should not happen for well-formed input).
     */
    public static function encodeMessage(string $xml): string
    {
        $deflated = gzdeflate($xml, 9);
        if ($deflated === false) {
            throw new \RuntimeException('SamlRedirectBinding::encodeMessage — gzdeflate failed.');
        }

        return base64_encode($deflated);
    }

    /**
     * 📦 Decode + inflate an HTTP-Redirect-binding `SAMLRequest`/`SAMLResponse`
     * payload, with a HARD inflated-size cap — the DEFLATE-bomb guard
     * (plan §6.4 / §9 item 6).
     *
     * Verified empirically against this PHP runtime: `gzinflate($data,
     * $maxLength)`'s second argument is enforced as a genuine hard cap —
     * exceeding it returns `false` (with a PHP warning, suppressed here)
     * rather than silently truncating or allocating unbounded memory. A
     * malicious sender cannot use a small compressed payload to force a
     * huge decompression (a classic "zip bomb" DoS).
     *
     * @param string $base64Deflated  The raw `SAMLRequest`/`SAMLResponse` parameter value (already URL-decoded by the caller/PHP superglobal).
     * @param int    $maxInflatedBytes Hard cap on the INFLATED size, in bytes (from `saml.max_inflated_request_bytes`).
     * @return string|null The decoded XML string, or null on any decode/size failure (malformed base64, non-deflate data, or the cap being exceeded).
     */
    public static function decodeMessage(string $base64Deflated, int $maxInflatedBytes): ?string
    {
        // 🛡️ Strict base64 decode (reject non-alphabet characters outright
        //    rather than have base64_decode() silently skip them).
        $raw = base64_decode($base64Deflated, true);
        if ($raw === false || $raw === '') {
            return null;
        }

        $cap = max(1, $maxInflatedBytes);
        // @-suppressed: an over-cap or malformed-stream failure is expected,
        // adversarial input here, not a genuine application error — the
        // caller only cares about the null/string result.
        $xml = @gzinflate($raw, $cap);

        return $xml === false ? null : $xml;
    }

    // ========================================================================
    // ✍️ SIGN (build a redirect-binding URL, optionally detached-signed)
    // ========================================================================

    /**
     * ✍️ Build a complete HTTP-Redirect-binding URL:
     * `{baseUrl}?{paramName}={encodedMessage}[&RelayState=...][&SigAlg=...&Signature=...]`.
     *
     * @param string      $baseUrl        The SP's SSO/ACS or SLO endpoint (or, for a Response, effectively unused — Redirect-bound RESPONSES are rare; this is primarily for LogoutRequest/LogoutResponse, see SamlLogoutService).
     * @param string      $paramName      'SAMLRequest' or 'SAMLResponse'.
     * @param string      $encodedMessage The result of {@see self::encodeMessage()}.
     * @param string|null $relayState     Opaque RelayState to echo, or null.
     * @param string|null $privateKeyPem  SIGNula's SAML signing PRIVATE key PEM — pass null to build an UNSIGNED URL.
     * @return string
     * @throws \RuntimeException If signing was requested but the key is unusable, or the signing operation itself fails.
     */
    public static function buildRedirectUrl(
        string $baseUrl,
        string $paramName,
        string $encodedMessage,
        ?string $relayState,
        ?string $privateKeyPem
    ): string {
        $query = $paramName . '=' . urlencode($encodedMessage);

        if ($relayState !== null && $relayState !== '') {
            $query .= '&RelayState=' . urlencode(substr($relayState, 0, self::MAX_RELAY_STATE_LENGTH));
        }

        if ($privateKeyPem !== null) {
            $query .= '&SigAlg=' . urlencode(self::SIG_ALG_RSA_SHA256);

            $signatureB64 = self::signQueryString($query, $privateKeyPem);
            if ($signatureB64 === null) {
                throw new \RuntimeException('SamlRedirectBinding::buildRedirectUrl — failed to sign the redirect-binding query string.');
            }

            $query .= '&Signature=' . urlencode($signatureB64);
        }

        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . $query;
    }

    /**
     * ✍️ Sign an already-built query string (private helper, exposed at the
     * class level only via {@see self::buildRedirectUrl()}).
     *
     * @param string $query         The exact octets to sign (already in `key=value[&key=value]` form).
     * @param string $privateKeyPem SAML signing private key PEM.
     * @return string|null Base64-encoded signature, or null on any OpenSSL failure.
     */
    private static function signQueryString(string $query, string $privateKeyPem): ?string
    {
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            return null;
        }

        $signature = '';
        $ok = openssl_sign($query, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return $ok ? base64_encode($signature) : null;
    }

    // ========================================================================
    // 🔍 VERIFY (inbound detached signature)
    // ========================================================================

    /**
     * 🔍 Does the raw query string carry BOTH `Signature` and `SigAlg`
     * parameters? Lets a caller distinguish "no signature at all" (a
     * policy decision — see plan §6.3's "volunteered signature" rule: even
     * when NOT required, a PRESENT signature must still be verified, never
     * silently ignored) from "signature present" (always verify).
     *
     * @param string $rawQueryString The raw, as-received `$_SERVER['QUERY_STRING']`.
     * @return bool
     */
    public static function hasSignatureParams(string $rawQueryString): bool
    {
        return self::extractRawParam($rawQueryString, 'Signature') !== null
            && self::extractRawParam($rawQueryString, 'SigAlg') !== null;
    }

    /**
     * 🔍 Extract + URL-decode the `RelayState` parameter, length-capped.
     * RelayState is OPAQUE — this facade never interprets it as anything
     * but a string to echo back verbatim (plan §9 item 7).
     *
     * @param string $rawQueryString The raw query string.
     * @return string|null
     */
    public static function getRelayState(string $rawQueryString): ?string
    {
        $raw = self::extractRawParam($rawQueryString, 'RelayState');
        if ($raw === null) {
            return null;
        }

        $decoded = urldecode($raw);

        return substr($decoded, 0, self::MAX_RELAY_STATE_LENGTH);
    }

    /**
     * 🔍 Verify a detached HTTP-Redirect-binding signature (C1) against a
     * PINNED (registered) SP certificate.
     *
     * Reconstructs the signed octets from the RAW query string substrings
     * — see the class header's security-critical-details section for why
     * this must never be done via a re-encoded `$_GET` array.
     *
     * @param string $rawQueryString   The raw, as-received `$_SERVER['QUERY_STRING']`.
     * @param string $messageParamName 'SAMLRequest' or 'SAMLResponse' — whichever this message actually used.
     * @param string $pinnedCertPem    The SP's REGISTERED X.509 certificate PEM. Never any key material embedded in the request itself.
     * @return bool True ONLY when the SigAlg is exactly the allowed one AND `openssl_verify()` returns exactly `1`.
     */
    public static function verifyDetachedSignature(string $rawQueryString, string $messageParamName, string $pinnedCertPem): bool
    {
        $rawMessage   = self::extractRawParam($rawQueryString, $messageParamName);
        $rawSigAlg    = self::extractRawParam($rawQueryString, 'SigAlg');
        $rawSignature = self::extractRawParam($rawQueryString, 'Signature');

        if ($rawMessage === null || $rawSigAlg === null || $rawSignature === null) {
            return false;
        }

        // 🛡️ Algorithm allowlist-of-one, enforced BEFORE any cryptography —
        //    rejects a SHA-1/DSA/HMAC SigAlg downgrade outright.
        $sigAlg = urldecode($rawSigAlg);
        if (!hash_equals(self::SIG_ALG_RSA_SHA256, $sigAlg)) {
            return false;
        }

        // 🧮 Reconstruct EXACTLY the spec-mandated octets, using the RAW
        //    (still percent-encoded) substrings as received — never
        //    re-encoded from a parsed array.
        $signedOctets = $messageParamName . '=' . $rawMessage;

        $rawRelayState = self::extractRawParam($rawQueryString, 'RelayState');
        if ($rawRelayState !== null) {
            $signedOctets .= '&RelayState=' . $rawRelayState;
        }

        $signedOctets .= '&SigAlg=' . $rawSigAlg;

        $signature = base64_decode(urldecode($rawSignature), true);
        if ($signature === false || $signature === '') {
            return false;
        }

        $publicKey = openssl_pkey_get_public($pinnedCertPem);
        if ($publicKey === false) {
            return false;
        }

        $result = openssl_verify($signedOctets, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        // openssl_verify(): 1 = valid, 0 = invalid, -1 = error. PHP casts -1
        // to `true` in a loose boolean context — accept ONLY the strict `1`
        // (the WebAuthnHandler::verifyAssertionSignature() discipline).
        return $result === 1;
    }

    /**
     * 🔧 Extract a parameter's RAW (still percent-encoded) value from a raw
     * query string, without going through PHP's `$_GET` array parsing
     * (which normalises/merges/reorders in ways that can silently diverge
     * from the exact bytes the sender transmitted).
     *
     * @param string $rawQueryString The raw query string.
     * @param string $paramName      Parameter name (matched literally, case-sensitively).
     * @return string|null The raw (still-encoded) value, or null if the parameter is absent.
     */
    private static function extractRawParam(string $rawQueryString, string $paramName): ?string
    {
        $pattern = '/(?:^|&)' . preg_quote($paramName, '/') . '=([^&]*)/';

        return preg_match($pattern, $rawQueryString, $matches) === 1 ? $matches[1] : null;
    }
}

// ✅ SamlRedirectBinding loaded successfully
