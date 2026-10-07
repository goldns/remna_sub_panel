<?php
declare(strict_types=1);

/**
 * @brief Identifies subscription clients from a validated HWID header or supported user-agent prefix.
 * @param userAgent Request User-Agent value, or an empty string when absent.
 * @param hwid Request X-HWID value, or an empty string when absent.
 * @return true for HWID-bearing requests or recognized Happ, INCY, V2RayTun, and xray clients.
 */
function isSubscriptionClientRequest(string $userAgent, string $hwid): bool
{
    if (trim($hwid) !== '') {
        return true;
    }

    return preg_match('/^Happ\/[\d.]+\//', $userAgent) === 1
        || str_starts_with($userAgent, 'INCY/')
        || str_starts_with($userAgent, 'v2raytun/')
        || strcasecmp($userAgent, 'xray') === 0;
}

// APCu caching degrades to a no-op when disabled or unavailable.

/**
 * @brief Reads one value from the optional APCu cache without producing errors when APCu is unavailable.
 * @param key Fully scoped cache key produced by scopedCacheKey().
 * @return Stored value, or null when caching is disabled, unavailable, or the key is absent.
 */
function cacheGet(string $key): mixed
{
    if (!defined('APCU_CACHE') || !APCU_CACHE || !function_exists('apcu_fetch')) return null;
    $val = apcu_fetch($key, $ok);
    return $ok ? $val : null;
}

/**
 * @brief Stores one value in the optional APCu cache for a positive number of seconds.
 * @param key Fully scoped cache key produced by scopedCacheKey().
 * @param value Serializable value to store.
 * @param ttl Positive lifetime in seconds; non-positive values are ignored.
 */
function cacheSet(string $key, mixed $value, int $ttl): void
{
    if (!defined('APCU_CACHE') || !APCU_CACHE || !function_exists('apcu_store') || $ttl <= 0) return;
    apcu_store($key, $value, $ttl);
}

/**
 * @brief Removes one fully scoped APCu entry when the extension is available.
 * @param key Fully scoped cache key produced by scopedCacheKey().
 */
function cacheDel(string $key): void
{
    if (function_exists('apcu_delete')) apcu_delete($key);
}

/**
 * @brief Performs an API GET with optional APCu caching of successful responses.
 *
 * The caller must provide a fully scoped key. A cache hit sets the request-scoped __cache_hit diagnostic flag.
 *
 * @param cacheKey Fully scoped cache key.
 * @param url Trusted absolute upstream URL.
 * @param headers Request headers in "Name: value" form.
 * @param ttl Positive cache lifetime in seconds.
 * @param maxBytes Maximum decompressed response size; zero selects the configured default.
 * @param operation Stable non-sensitive operation name used by the protected log.
 * @return Standard HTTP result returned by apiGet().
 */
function cachedApiGet(
    string $cacheKey,
    string $url,
    array $headers,
    int $ttl,
    int $maxBytes = 0,
    string $operation = 'cached_get'
): array
{
    $cached = cacheGet($cacheKey);
    if ($cached !== null) {
        $GLOBALS['__cache_hit'] = true;
        return $cached;
    }
    $result = apiGet($url, $headers, 10, $maxBytes, $operation);
    if ($result['code'] === 200) cacheSet($cacheKey, $result, $ttl);
    return $result;
}

/**
 * @brief Builds a non-reversible APCu namespace for one API installation and cache schema version.
 * @param config Application configuration containing the upstream base URL.
 * @return Stable namespace that does not expose the configured URL.
 */
function buildCacheNamespace(array $config): string
{
    $baseUrl = is_string($config['remnawave_url'] ?? null)
        ? strtolower(rtrim(trim($config['remnawave_url']), '/'))
        : '';
    return 'rsb:v3:' . substr(hash('sha256', $baseUrl), 0, 24);
}

/**
 * @brief Produces an installation-scoped APCu key without exposing subscription or user identifiers.
 * @param scope Short cache family name such as info, user-detail, or hwid.
 * @param identity Raw logical identifier that is hashed before it becomes part of the key.
 * @return Fully scoped APCu key.
 */
function scopedCacheKey(string $scope, string|int $identity): string
{
    $namespace = is_string($GLOBALS['__cache_namespace'] ?? null)
        ? $GLOBALS['__cache_namespace']
        : 'rsb:v3:unconfigured';
    $safeScope = preg_replace('/[^a-z0-9_-]/i', '_', $scope) ?: 'cache';
    return $namespace . ':' . strtolower($safeScope) . ':' . hash('sha256', (string) $identity);
}

/**
 * @brief Returns the configured decompressed response-body limit for one trusted request category.
 * @param category Known category such as info, user, hwid, checker, subscription, mutation, or external.
 * @return Positive byte limit, falling back to the configured default and then two MiB.
 */
function responseBodyLimit(string $category): int
{
    $config = $GLOBALS['__storage_config'] ?? [];
    $limits = is_array($config) && is_array($config['response_body_limits'] ?? null)
        ? $config['response_body_limits']
        : [];
    $value = $limits[$category] ?? ($limits['default'] ?? 2097152);
    return is_int($value) && $value > 0 ? $value : 2097152;
}

/**
 * @brief Writes a non-sensitive structured operational failure to the configured PHP error log.
 * @param category Stable failure family such as upstream or storage.
 * @param operation Stable operation name that never contains a URL or user identifier.
 * @param context Scalar diagnostic fields already selected as non-sensitive by the caller.
 */
function logOperationalFailure(string $category, string $operation, array $context = []): void
{
    $safe = ['category' => $category, 'operation' => $operation];
    foreach ($context as $key => $value) {
        if (!is_string($key) || !preg_match('/^[a-z0-9_]{1,32}$/', $key)) continue;
        if (is_bool($value) || is_int($value) || is_float($value)) {
            $safe[$key] = $value;
        } elseif (is_string($value)) {
            $safe[$key] = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value) ?? '', 0, 80);
        }
    }
    $encoded = json_encode($safe, JSON_UNESCAPED_SLASHES);
    error_log($encoded !== false ? '[remna_sub_panel] ' . $encoded : '[remna_sub_panel] operational_failure');
}

/**
 * @brief Logs selected HTTP failure metadata without recording URLs, headers, credentials, or bodies.
 * @param operation Stable logical operation name.
 * @param result Standard HTTP result returned by apiGet() or apiPost().
 */
function logHttpFailure(string $operation, array $result): void
{
    logOperationalFailure('upstream', $operation, [
        'http_code' => (int) ($result['code'] ?? 0),
        'curl_errno' => (int) ($result['error_no'] ?? 0),
        'duration_ms' => (int) ($result['ms'] ?? 0),
        'body_too_large' => !empty($result['body_too_large']),
    ]);
}

/**
 * @brief Logs a storage exception using only its class and safe generic/SQLite numeric codes.
 * @param operation Stable logical storage operation name.
 * @param error Caught storage exception; its message is intentionally not logged.
 */
function logStorageFailure(string $operation, Throwable $error): void
{
    $context = [
        'exception' => get_class($error),
        'code' => (int) $error->getCode(),
    ];
    if ($error instanceof PDOException && is_array($error->errorInfo ?? null)
        && is_numeric($error->errorInfo[1] ?? null)) {
        $context['sqlite_code'] = (int) $error->errorInfo[1];
    }
    logOperationalFailure('storage', $operation, $context);
}

/**
 * @brief Returns the per-request CSP nonce created during bootstrap.
 * @return Base64 nonce, or an empty string before security initialization.
 */
function cspNonce(): string
{
    return is_string($GLOBALS['__csp_nonce'] ?? null) ? $GLOBALS['__csp_nonce'] : '';
}

/**
 * @brief Creates the CSP nonce and emits one consistent set of cache and browser security headers.
 */
function applyResponseSecurityHeaders(): void
{
    if (cspNonce() === '') $GLOBALS['__csp_nonce'] = base64_encode(random_bytes(18));
    header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    header("Content-Security-Policy: default-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'; "
        . "script-src 'self' 'nonce-" . cspNonce() . "'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; connect-src 'self'; font-src 'self'");
}

// ---------------------------------------------------------------------------
/**
 * @brief Checks whether the trusted client IP matches a configured IP or IPv4 CIDR entry.
 *
 * @param debugIp One IP/CIDR string or an array of scalar IP/CIDR entries.
 * @return true when a valid configured entry matches the client IP; otherwise false.
 */
function clientIpMatchesDebugList(array|string $debugIp): bool
{
    if (empty($debugIp)) return false;
    $entries  = is_array($debugIp) ? $debugIp : [$debugIp];
    $clientIp = clientIp();
    foreach ($entries as $entry) {
        if (!is_string($entry) && !is_int($entry)) continue;
        $entry = trim((string) $entry);
        if ($entry === '') continue;
        if (str_contains($entry, '/')) {
            if (ipInCidr($clientIp, $entry)) return true;
        } elseif (filter_var($entry, FILTER_VALIDATE_IP) !== false && $clientIp === $entry) {
            return true;
        }
    }
    return false;
}

/**
 * @brief Checks whether a valid IPv4 address belongs to a strictly formed IPv4 CIDR network.
 *
 * @param ip Candidate IPv4 address.
 * @param cidr Network in address/prefix form with a decimal prefix from 0 through 32.
 * @return true when the address belongs to the network; otherwise false for non-membership or malformed input.
 */
function ipInCidr(string $ip, string $cidr): bool
{
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) return false;
    [$subnet, $bits] = $parts;
    if (!ctype_digit($bits)) return false;
    $bits = (int) $bits;
    if ($bits < 0 || $bits > 32) return false;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    $ipLong     = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) return false;
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

/**
 * @brief Checks whether the current request matches a non-empty configured checker rule.
 *
 * A rule may constrain the trusted client IP, the User-Agent substring, or both. Empty and malformed rules
 * never match, preventing an accidental unrestricted checker route.
 *
 * @param config Application configuration containing the optional checkers array.
 * @return true when at least one valid rule matches all of its configured constraints; otherwise false.
 */
function isCheckerRequest(array $config): bool
{
    $checkers = $config['checkers'] ?? [];
    if (!is_array($checkers) || $checkers === []) return false;

    $clientIp  = clientIp();
    $userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : '';

    foreach ($checkers as $checker) {
        if (!is_array($checker)) continue;

        $rawIpEntries = $checker['ip'] ?? [];
        $rawIpEntries = is_array($rawIpEntries) ? $rawIpEntries : [$rawIpEntries];
        $ipEntries = [];
        foreach ($rawIpEntries as $entry) {
            if (!is_string($entry) && !is_int($entry)) continue;
            $entry = trim((string) $entry);
            if ($entry !== '') $ipEntries[] = $entry;
        }

        $uaNeedle = is_string($checker['ua'] ?? null) ? trim($checker['ua']) : '';
        if ($ipEntries === [] && $uaNeedle === '') continue;

        $ipOk = $ipEntries === [];
        if ($ipEntries !== []) {
            $ipOk = false;
            foreach ($ipEntries as $entry) {
                if (str_contains($entry, '/')) {
                    if (ipInCidr($clientIp, $entry)) { $ipOk = true; break; }
                } elseif (filter_var($entry, FILTER_VALIDATE_IP) !== false && $clientIp === $entry) {
                    $ipOk = true; break;
                }
            }
        }

        $uaOk = $uaNeedle === '' || str_contains($userAgent, $uaNeedle);

        if ($ipOk && $uaOk) return true;
    }

    return false;
}

/**
 * @brief Builds the current public URL from trusted, externally normalized proxy headers.
 *
 * Non-string server values are ignored to prevent malformed request metadata from reaching string operations.
 *
 * @return Absolute URL used for profile links, falling back to local neutral request components.
 */
function currentUrl(): string
{
    $forwardedProto = is_string($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null)
        ? trim($_SERVER['HTTP_X_FORWARDED_PROTO'])
        : '';
    $https = is_string($_SERVER['HTTPS'] ?? null) ? $_SERVER['HTTPS'] : '';
    $proto = 'http';
    if ($forwardedProto !== '') {
        $proto = $forwardedProto;
    } elseif ($https !== '' && $https !== 'off') {
        $proto = 'https';
    }
    $host = is_string($_SERVER['HTTP_HOST'] ?? null)
        ? $_SERVER['HTTP_HOST']
        : (is_string($_SERVER['SERVER_NAME'] ?? null) ? $_SERVER['SERVER_NAME'] : 'localhost');
    $requestUri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
    return $proto . '://' . $host . $requestUri;
}

/**
 * @brief Returns the client IP from the trusted proxy header or the direct server address.
 *
 * @return First normalized X-Forwarded-For value, REMOTE_ADDR, or 0.0.0.0 for malformed metadata.
 */
function clientIp(): string
{
    $forwardedFor = is_string($_SERVER['HTTP_X_FORWARDED_FOR'] ?? null)
        ? trim($_SERVER['HTTP_X_FORWARDED_FOR'])
        : '';
    if ($forwardedFor !== '') {
        return trim(explode(',', $forwardedFor, 2)[0]);
    }
    return is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/**
 * @brief Creates a signed, short-lived token authorizing one HWID deletion action for a subscription.
 *
 * @param shortUuid Valid subscription identifier to bind to the token.
 * @param secret Installation secret of at least 32 bytes used only for CSRF signatures.
 * @param ttl Positive token lifetime in seconds.
 * @return Token in "expiry.signature" form, or an empty string when signing is disabled or invalid.
 */
function createDeleteHwidCsrfToken(string $shortUuid, string $secret, int $ttl = 600): string
{
    if (strlen($secret) < 32 || $ttl <= 0) return '';

    $expires   = time() + $ttl;
    $payload   = $shortUuid . '|delete_hwid|' . $expires;
    $signature = hash_hmac('sha256', $payload, $secret);
    return $expires . '.' . $signature;
}

/**
 * @brief Verifies the signature, action binding, subscription binding, and expiry of an HWID CSRF token.
 *
 * @param token Token received from the browser form.
 * @param shortUuid Valid subscription identifier expected by the current route.
 * @param secret Installation secret of at least 32 bytes used to verify the HMAC signature.
 * @return true only for a correctly signed token expiring within the next ten minutes; otherwise false.
 */
function verifyDeleteHwidCsrfToken(string $token, string $shortUuid, string $secret): bool
{
    if ($token === '' || strlen($secret) < 32) return false;

    $parts = explode('.', $token, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0]) || !preg_match('/^[a-f0-9]{64}$/', $parts[1])) {
        return false;
    }

    $expires = (int) $parts[0];
    $now     = time();
    if ($expires < $now || $expires > $now + 600) return false;

    $expected = hash_hmac('sha256', $shortUuid . '|delete_hwid|' . $expires, $secret);
    return hash_equals($expected, $parts[1]);
}

/**
 * @brief Recursively masks credentials, device identifiers, URLs, and response bodies in diagnostics.
 *
 * Configuration keys and HTTP header names are matched case-insensitively. API URLs, request paths, response
 * bodies, and transport error text are hidden. Non-sensitive status codes, timings, header names, and decision
 * metadata remain available.
 *
 * @param value Arbitrarily nested debug value composed of arrays and scalar values.
 * @param key Optional parent key used to recognize sensitive fields during recursion.
 * @return A value with credentials, device identifiers, URLs, and response bodies masked.
 */
function redactDebugData(mixed $value, ?string $key = null): mixed
{
    $normalizedKey = strtolower(str_replace('-', '_', trim((string) $key)));
    $sensitiveKeys = [
        'api_key', 'api_token', 'x_api_key', 'authorization', 'proxy_authorization', 'http_authorization',
        'cookie', 'set_cookie', 'egames_cookie', 'http_cookie', 'x_hwid', 'http_x_hwid', 'debug_hwid',
        'csrf_secret', 'host', 'http_host', 'x_forwarded_host', 'error', 'curl_error',
    ];
    if ($key !== null && in_array($normalizedKey, $sensitiveKeys, true)) {
        return '[hidden]';
    }
    if ($key !== null && (str_ends_with($normalizedKey, '_url') || $normalizedKey === 'url')) {
        return '[hidden]';
    }
    if ($key !== null && in_array($normalizedKey, ['body', 'raw_body', 'info_body', 'wl_info_body'], true)) {
        return '[hidden]';
    }

    if (is_array($value)) {
        $redacted = [];
        foreach ($value as $childKey => $childValue) {
            $redacted[$childKey] = redactDebugData($childValue, is_string($childKey) ? $childKey : null);
        }
        return $redacted;
    }

    if (!is_string($value)) return $value;

    $value = (string) preg_replace(
        '/^(Authorization|Proxy-Authorization|Cookie|Set-Cookie|X-HWID|X-API-Key)\s*:[^\r\n]*$/mi',
        '$1: [hidden]',
        $value
    );
    $value = (string) preg_replace(
        '/(["\'](?:api[_-]?key|api[_-]?token|authorization|cookie|egames_cookie|x[_-]?hwid|debug_hwid|csrf_secret)["\']\s*[:=]\s*)["\'][^"\']*["\']/i',
        '$1"[hidden]"',
        $value
    );
    if ($key !== null && str_contains($normalizedKey, 'raw_request')) {
        $value = (string) preg_replace('/^([A-Z]+)\s+\S+(\s+HTTP\/\S+)$/m', '$1 [hidden]$2', $value);
        $value = (string) preg_replace('/^Host\s*:[^\r\n]*$/mi', 'Host: [hidden]', $value);
    }
    if ($key !== null && str_contains($normalizedKey, 'raw_response')) {
        $parts = preg_split('/\r?\n\r?\n/', $value, 2);
        $value = (string) ($parts[0] ?? '') . "\n\n[hidden]";
    }
    return (string) preg_replace('#\b(?:https?|tg)://[^\s<>"\']+#i', '[hidden-url]', $value);
}

/**
 * @brief Maps a failed upstream HTTP operation to the public gateway status used by this application.
 *
 * @param result HTTP result returned by apiGet() or apiPost().
 * @return 404 for confirmed absence, 503 for every unavailable, timed-out, or rate-limited upstream,
 *         or 502 for other invalid and unexpected responses.
 */
function upstreamFailureStatus(array $result): int
{
    if (!empty($result['body_too_large'])) return 502;
    $code    = (int) ($result['code'] ?? 0);
    $errorNo = (int) ($result['error_no'] ?? 0);

    if ($code === 404) return 404;
    if ($errorNo === 28 || $code === 0 || $code === 429 || $code >= 500) return 503;
    return 502;
}

/**
 * @brief Returns a generic public error phrase for an upstream failure status.
 *
 * @param status Public HTTP status produced by upstreamFailureStatus().
 * @return A non-sensitive English phrase suitable for an HTML or JSON error response.
 */
function upstreamFailureMessage(int $status): string
{
    return match ($status) {
        404     => 'Not Found',
        503     => 'Service Unavailable',
        default => 'Bad Gateway',
    };
}

/**
 * @brief Performs an HTTPS GET request and captures transport diagnostics without throwing on cURL failure.
 *
 * @param url Absolute upstream URL from trusted application configuration.
 * @param extraHeaders Request headers in "Name: value" form.
 * @param timeout Positive total timeout in seconds.
 * @param maxBytes Maximum decompressed response size; zero selects the configured default.
 * @param operation Stable non-sensitive operation name used by the protected log.
 * @return Array containing HTTP code, normalized response headers, body, duration, cURL error number, and error
 *         message. Transport failures use HTTP code 0 and an empty body.
 */
function apiGet(string $url, array $extraHeaders = [], int $timeout = 10, int $maxBytes = 0, string $operation = 'http_get'): array
{
    $responseHeaders = [];
    $body = '';
    $bodyTooLarge = false;
    $limit = $maxBytes > 0 ? $maxBytes : responseBodyLimit('default');

    $ch = curl_init($url);
    if ($ch === false) {
        $result = ['code' => 0, 'headers' => [], 'body' => '', 'ms' => 0, 'error_no' => -1,
            'error' => 'cURL initialization failed', 'body_too_large' => false];
        logHttpFailure($operation, $result);
        return $result;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_HTTPHEADER     => $extraHeaders,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_WRITEFUNCTION  => function ($_, string $chunk) use (&$body, &$bodyTooLarge, $limit): int {
            if (strlen($body) + strlen($chunk) > $limit) {
                $bodyTooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => function ($_, $header) use (&$responseHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        },
    ]);

    $t0   = microtime(true);
    curl_exec($ch);
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errorNo = curl_errno($ch);
    $error   = curl_error($ch);
    curl_close($ch);
    $result = [
        'code'    => $bodyTooLarge ? 0 : $code,
        'headers' => $responseHeaders,
        'body'    => $bodyTooLarge ? '' : $body,
        'ms'      => $ms,
        'error_no'=> $errorNo,
        'error'   => $error,
        'body_too_large' => $bodyTooLarge,
    ];
    if ($result['code'] === 0 || $result['code'] >= 400 || $bodyTooLarge) logHttpFailure($operation, $result);
    return $result;
}

/**
 * @brief Performs an HTTPS POST request and captures transport diagnostics without throwing on cURL failure.
 *
 * @param url Absolute upstream URL from trusted application configuration.
 * @param payload Serialized request body.
 * @param extraHeaders Request headers in "Name: value" form.
 * @param timeout Positive total timeout in seconds.
 * @param maxBytes Maximum decompressed response size; zero selects the configured default.
 * @param operation Stable non-sensitive operation name used by the protected log.
 * @return Array containing HTTP code, normalized response headers, body, duration, cURL error number, and error
 *         message. Transport failures use HTTP code 0 and an empty body.
 */
function apiPost(
    string $url,
    string $payload,
    array $extraHeaders = [],
    int $timeout = 10,
    int $maxBytes = 0,
    string $operation = 'http_post'
): array
{
    $responseHeaders = [];
    $body = '';
    $bodyTooLarge = false;
    $limit = $maxBytes > 0 ? $maxBytes : responseBodyLimit('default');

    $ch = curl_init($url);
    if ($ch === false) {
        $result = ['code' => 0, 'headers' => [], 'body' => '', 'ms' => 0, 'error_no' => -1,
            'error' => 'cURL initialization failed', 'body_too_large' => false];
        logHttpFailure($operation, $result);
        return $result;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $extraHeaders,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_WRITEFUNCTION  => function ($_, string $chunk) use (&$body, &$bodyTooLarge, $limit): int {
            if (strlen($body) + strlen($chunk) > $limit) {
                $bodyTooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => function ($_, $header) use (&$responseHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        },
    ]);

    $t0   = microtime(true);
    curl_exec($ch);
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errorNo = curl_errno($ch);
    $error   = curl_error($ch);
    curl_close($ch);
    $result = [
        'code'    => $bodyTooLarge ? 0 : $code,
        'headers' => $responseHeaders,
        'body'    => $bodyTooLarge ? '' : $body,
        'ms'      => $ms,
        'error_no'=> $errorNo,
        'error'   => $error,
        'body_too_large' => $bodyTooLarge,
    ];
    if ($result['code'] === 0 || $result['code'] >= 400 || $bodyTooLarge) logHttpFailure($operation, $result);
    return $result;
}

/**
 * @brief Validates and normalizes a configured HTTP header allowlist while enforcing immutable forbidden names.
 * @param configured Candidate list of header names; non-array values select the supplied defaults.
 * @param defaults Safe default header names.
 * @param forbidden Header names that cannot be enabled by configuration.
 * @return Associative set keyed by lowercase allowed header name.
 */
function normalizeHeaderAllowlist(mixed $configured, array $defaults, array $forbidden): array
{
    $source = is_array($configured) ? $configured : $defaults;
    $forbiddenSet = array_fill_keys(array_map('strtolower', $forbidden), true);
    $allowed = [];
    foreach ($source as $name) {
        if (!is_string($name)) continue;
        $name = strtolower(trim($name));
        if ($name === '' || isset($forbiddenSet[$name])) continue;
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) !== 1) continue;
        $allowed[$name] = true;
    }
    return $allowed;
}

/**
 * @brief Returns request header names that configuration can never forward upstream.
 * @return Lowercase forbidden names covering credentials, transport state, proxy identity, and app-owned cache fields.
 */
function forbiddenRequestHeaderNames(): array
{
    return [
        'authorization', 'proxy-authorization', 'cookie', 'host', 'connection', 'content-length',
        'transfer-encoding', 'te', 'trailer', 'upgrade', 'forwarded', 'x-forwarded-for',
        'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-scheme', 'x-real-ip', 'cf-connecting-ip',
        'true-client-ip', 'x-api-key', 'cache-control', 'pragma', 'expires',
    ];
}

/**
 * @brief Returns the effective allowlist for client headers forwarded to the subscription upstream.
 * @param config Application configuration containing forward_request_headers.
 * @return Associative set keyed by lowercase allowed header name.
 */
function forwardRequestHeaderAllowlist(array $config): array
{
    $defaults = [
        'accept', 'accept-language', 'user-agent', 'x-hwid', 'x-app-version', 'x-device-locale',
        'x-device-os', 'x-device-model', 'x-ver-os',
    ];
    return normalizeHeaderAllowlist($config['forward_request_headers'] ?? null, $defaults, forbiddenRequestHeaderNames());
}

/**
 * @brief Returns response header names that configuration can never expose to a public subscription client.
 * @return Lowercase forbidden header names covering credentials, transport state, and proxy identity.
 */
function forbiddenResponseHeaderNames(): array
{
    return [
        'authorization', 'proxy-authenticate', 'proxy-authorization', 'cookie', 'set-cookie', 'server', 'date',
        'content-length', 'content-encoding', 'transfer-encoding', 'connection', 'keep-alive', 'te', 'trailer',
        'upgrade', 'location', 'www-authenticate', 'x-api-key', 'access-control-allow-origin',
        'host', 'forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-scheme',
        'x-real-ip', 'cf-connecting-ip', 'true-client-ip',
    ];
}

/**
 * @brief Returns the effective allowlist for subscription headers forwarded to the public client.
 *
 * Safe WL inheritance names are added automatically, while authentication, Cookie, and transport headers remain
 * forbidden regardless of configuration.
 *
 * @param config Application configuration containing forward_response_headers and wl_headers_forward.
 * @return Associative set keyed by lowercase allowed header name.
 */
function forwardResponseHeaderAllowlist(array $config): array
{
    $defaults = [
        'content-type', 'profile-title', 'profile-update-interval', 'subscription-userinfo', 'support-url',
        'content-disposition', 'announce', 'routing', 'x-hwid-max-devices-reached',
    ];
    $forbidden = forbiddenResponseHeaderNames();
    $allowed = normalizeHeaderAllowlist($config['forward_response_headers'] ?? null, $defaults, $forbidden);
    $wlHeaders = is_array($config['wl_headers_forward'] ?? null)
        ? $config['wl_headers_forward']
        : ['subscription-userinfo'];
    $wlAllowed = normalizeHeaderAllowlist($wlHeaders, [], $forbidden);
    return $allowed + $wlAllowed;
}

/**
 * @brief Rejects control characters that could create malformed or injected HTTP headers.
 * @param value Candidate header value.
 * @return true when the value contains no ASCII control characters except horizontal tab.
 */
function isSafeHeaderValue(string $value): bool
{
    return preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) !== 1;
}

/**
 * @brief Validates the complete configuration contract before request routing without exposing configured values.
 *
 * @param config Raw array returned by config.php.
 * @return The validated configuration with list-like settings reindexed.
 * @throws InvalidArgumentException When a known setting has an unsafe or unsupported type or value.
 */
function validateAndNormalizeConfig(array $config): array
{
    $fail = static function (string $key): never {
        throw new InvalidArgumentException('Invalid configuration key: ' . $key);
    };
    $boolKeys = [
        'show_version', 'show_qr', 'allow_delete_hwid', 'encrypt_sub_link', 'apcu_cache', 'display_errors',
        'enable_wl', 'shuffle_servers',
    ];
    foreach ($boolKeys as $key) {
        if (array_key_exists($key, $config) && !is_bool($config[$key])) $fail($key);
    }
    $stringKeys = [
        'csrf_secret', 'lang', 'template', 'storage_driver', 'storage_path', 'debug_hwid', 'checker_url',
        'remnawave_url', 'api_token', 'egames_cookie', 'wl_suffix', 'wl_position', 'user_limited',
        'user_expired', 'user_disabled',
    ];
    foreach ($stringKeys as $key) {
        if (array_key_exists($key, $config) && !is_string($config[$key])) $fail($key);
    }
    $nullableScalarKeys = [
        'project_name', 'copyright', 'announce_limited', 'announce_expired', 'announce_disabled',
        'profile_title_prefix', 'support_url', 'payment_url', 'payment_url_tg', 'content_disposition_name',
        'announce', 'profile_update_interval', 'happ_routing',
    ];
    foreach ($nullableScalarKeys as $key) {
        $value = $config[$key] ?? null;
        if ($value !== null && !is_string($value) && !is_int($value) && !is_float($value)) $fail($key);
    }
    foreach (['cache_ttl', 'checker_timeout', 'checker_latency_good', 'checker_latency_ok', 'expired_grace_days'] as $key) {
        if (array_key_exists($key, $config) && (!is_int($config[$key]) || $config[$key] < 0)) $fail($key);
    }
    if (($config['remnawave_url'] ?? '') === ''
        || filter_var($config['remnawave_url'], FILTER_VALIDATE_URL) === false
        || !in_array(strtolower((string) parse_url($config['remnawave_url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
        $fail('remnawave_url');
    }
    if (preg_match('/^[a-z0-9_-]+$/D', (string) ($config['lang'] ?? '')) !== 1) $fail('lang');
    if (preg_match('/^[A-Za-z0-9_-]+$/D', (string) ($config['template'] ?? '')) !== 1) $fail('template');
    if (preg_match('#^data/cache_[0-9]{12,}\.sqlite$#D', str_replace('\\', '/', (string) ($config['storage_path'] ?? ''))) !== 1) {
        $fail('storage_path');
    }
    if (($config['storage_driver'] ?? '') !== 'sqlite') $fail('storage_driver');
    if (!in_array($config['wl_position'] ?? '', ['top', 'bottom'], true)) $fail('wl_position');
    $debugHwid = (string) ($config['debug_hwid'] ?? '');
    if ($debugHwid !== '' && preg_match('/^[A-Za-z0-9=-]{10,64}$/D', $debugHwid) !== 1) $fail('debug_hwid');
    foreach (['checker_url', 'support_url', 'payment_url', 'payment_url_tg'] as $key) {
        $value = $config[$key] ?? null;
        if ($value === null || $value === '') continue;
        $text = (string) $value;
        $scheme = strtolower((string) parse_url($text, PHP_URL_SCHEME));
        $allowedSchemes = in_array($key, ['support_url', 'payment_url_tg'], true)
            ? ['http', 'https', 'tg']
            : ['http', 'https'];
        if (preg_match('/[\x00-\x1F\x7F]/', $text) || !in_array($scheme, $allowedSchemes, true)) $fail($key);
    }
    foreach (['content_disposition_name', 'profile_update_interval', 'happ_routing'] as $key) {
        $value = $config[$key] ?? null;
        if ($value !== null && !isSafeHeaderValue((string) $value)) $fail($key);
    }
    foreach (['install_clients', 'checker_hide_servers', 'forward_request_headers', 'forward_response_headers',
        'wl_headers_forward', 'add_servers_base64_MAIN', 'add_servers_base64_WL'] as $key) {
        if (!array_key_exists($key, $config)) continue;
        if (!is_array($config[$key])) $fail($key);
        foreach ($config[$key] as $value) {
            if (!is_string($value)) $fail($key);
        }
        $config[$key] = array_values($config[$key]);
    }
    foreach ($config['install_clients'] ?? [] as $client) {
        if (!in_array($client, ['incy', 'happ'], true)) $fail('install_clients');
    }
    $headerLists = [
        'forward_request_headers' => forbiddenRequestHeaderNames(),
        'forward_response_headers' => forbiddenResponseHeaderNames(),
        'wl_headers_forward' => forbiddenResponseHeaderNames(),
    ];
    foreach ($headerLists as $key => $forbiddenHeaders) {
        $values = $config[$key] ?? [];
        if (count(normalizeHeaderAllowlist($values, [], $forbiddenHeaders)) !== count(array_unique(array_map(
            static fn(string $name): string => strtolower(trim($name)),
            $values
        )))) {
            $fail($key);
        }
    }
    $debugIp = $config['debug_ip'] ?? '';
    if (!is_string($debugIp) && !is_array($debugIp)) $fail('debug_ip');
    if (is_array($debugIp)) {
        foreach ($debugIp as $value) if (!is_string($value)) $fail('debug_ip');
        $config['debug_ip'] = array_values($debugIp);
    }
    $limits = $config['hwid_delete_limits'] ?? [];
    if (!is_array($limits)) $fail('hwid_delete_limits');
    foreach (['day', 'week', 'month'] as $period) {
        if (isset($limits[$period]) && (!is_int($limits[$period]) || $limits[$period] < 0)) $fail('hwid_delete_limits');
    }
    $bodyLimits = $config['response_body_limits'] ?? [];
    if (!is_array($bodyLimits)) $fail('response_body_limits');
    foreach (['info', 'user', 'hwid', 'checker', 'subscription', 'mutation', 'external', 'default'] as $category) {
        if (!isset($bodyLimits[$category]) || !is_int($bodyLimits[$category]) || $bodyLimits[$category] <= 0) {
            $fail('response_body_limits');
        }
    }
    $checkers = $config['checkers'] ?? [];
    if (!is_array($checkers)) $fail('checkers');
    foreach ($checkers as $checker) {
        if (!is_array($checker)) $fail('checkers');
        if (isset($checker['ua']) && !is_string($checker['ua'])) $fail('checkers');
        if (isset($checker['ip']) && !is_string($checker['ip']) && !is_array($checker['ip'])) $fail('checkers');
        if (is_array($checker['ip'] ?? null)) {
            foreach ($checker['ip'] as $ip) if (!is_string($ip)) $fail('checkers');
        }
    }
    $customHeaders = $config['custom_headers'] ?? [];
    if (!is_array($customHeaders)) $fail('custom_headers');
    $forbidden = array_fill_keys(forbiddenResponseHeaderNames(), true);
    foreach ($customHeaders as $name => $value) {
        if (!is_string($name) || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) !== 1) $fail('custom_headers');
        if (isset($forbidden[strtolower($name)])) $fail('custom_headers');
        if ($value !== null && !is_scalar($value)) $fail('custom_headers');
        if ($value !== null && !isSafeHeaderValue((string) $value)) $fail('custom_headers');
    }
    return $config;
}

/**
 * @brief Defines the case-insensitive public field contract supported by B64 renewal placeholders.
 * @return Map from uppercase placeholder names to canonical API user-field names.
 */
function renewPlaceholderFieldMap(): array
{
    return [
        'ID' => 'id',
        'USERNAME' => 'username',
        'EMAIL' => 'email',
        'TELEGRAMID' => 'telegramId',
        'STATUS' => 'userStatus',
        'USERSTATUS' => 'userStatus',
        'EXPIRESAT' => 'expiresAt',
        'DAYSLEFT' => 'daysLeft',
        'TRAFFICUSED' => 'trafficUsed',
        'TRAFFICUSEDBYTES' => 'trafficUsedBytes',
        'TRAFFICLIMIT' => 'trafficLimit',
        'TRAFFICLIMITBYTES' => 'trafficLimitBytes',
        'HWIDDEVICELIMIT' => 'hwidDeviceLimit',
    ];
}

/**
 * @brief Finds optional personal API fields that must persist because configured payment templates consume them.
 * @param config Application configuration containing payment_url and payment_url_tg templates.
 * @return Associative set of approved canonical field names, limited to optional contact identifiers.
 */
function configuredPersistentPersonalFields(array $config): array
{
    $needed = [];
    $fieldMap = renewPlaceholderFieldMap();
    foreach (['payment_url', 'payment_url_tg'] as $key) {
        $template = is_string($config[$key] ?? null) ? $config[$key] : '';
        if ($template === '' || preg_match_all('/\{B64:([A-Za-z_]+)\}/i', $template, $matches) === false) continue;
        foreach ($matches[1] ?? [] as $name) {
            $field = $fieldMap[strtoupper((string) $name)] ?? null;
            if (in_array($field, ['email', 'telegramId'], true)) $needed[$field] = true;
        }
    }
    return $needed;
}
