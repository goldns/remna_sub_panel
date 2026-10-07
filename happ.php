<?php
declare(strict_types=1);

/**
 * @brief Selects the configured substitute subscription for a normalized inactive status.
 * @param status Lowercase effective status.
 * @param config Validated application configuration.
 * @return Substitute short identifier or an empty string when none is configured.
 */
function resolveSubstituteUuid(string $status, array $config): string
{
    return match($status) {
        'limited'  => $config['user_limited']  ?? '',
        'disabled' => $config['user_disabled'] ?? '',
        'expired'  => $config['user_expired']  ?? '',
        default    => '',
    };
}

/**
 * @brief Selects an optional status-specific announcement override.
 * @param status Lowercase effective status.
 * @param config Validated application configuration.
 * @return Configured announcement, an empty string to remove it, or null to preserve the general value.
 */
function resolveStatusAnnounce(string $status, array $config): ?string
{
    return match($status) {
        'limited'  => $config['announce_limited']  ?? null,
        'disabled' => $config['announce_disabled'] ?? null,
        'expired'  => $config['announce_expired']  ?? null,
        default    => null,
    };
}

/**
 * @brief Decodes the base64 profile-title convention while preserving malformed source values.
 * @param value Upstream profile-title header value.
 * @return Decoded title or the original value when it is plain text or invalid Base64.
 */
function decodeProfileTitleHeader(string $value): string
{
    $value = trim($value);
    if (str_starts_with($value, 'base64:')) {
        $decoded = base64_decode(substr($value, 7), true);
        return $decoded !== false ? $decoded : $value;
    }
    return $value;
}

/**
 * @brief Prefixes an upstream profile title and serializes it with the expected Base64 marker.
 * @param sourceValue Upstream profile-title header value.
 * @param prefix Trusted configured prefix.
 * @return Base64-marked combined profile title.
 */
function prefixedProfileTitleHeader(string $sourceValue, string $prefix): string
{
    return 'base64:' . base64_encode($prefix . decodeProfileTitleHeader($sourceValue));
}

/**
 * @brief Expands supported project-name placeholders inside a profile-title prefix.
 * @param prefix Configured prefix template.
 * @param config Validated application configuration.
 * @return Expanded prefix.
 */
function resolveProfileTitlePrefix(string $prefix, array $config): string
{
    $projectName = (string) ($config['project_name'] ?? '');
    return str_replace(['{project_name}', '{PROJECT_NAME}'], $projectName, $prefix);
}

/**
 * @brief Extracts a confirmed subscription status and expiry from a valid info response.
 *
 * @param result HTTP result returned for the subscription info endpoint.
 * @return Normalized status, expiry string, and days-left value, or null when the response cannot confirm them.
 */
function parseSubscriptionInfo(array $result): ?array
{
    if (($result['code'] ?? 0) !== 200 || !is_string($result['body'] ?? null)) return null;

    $data = json_decode($result['body'], true);
    if (!is_array($data)) return null;
    $user = is_array($data['response']['user'] ?? null) ? $data['response']['user'] : null;
    if (($data['response']['isFound'] ?? null) !== true || $user === null) return null;

    $rawStatus = $user['userStatus'] ?? null;
    if (!is_string($rawStatus)) return null;

    $status = strtolower($rawStatus);
    if (!in_array($status, ['active', 'limited', 'expired', 'disabled'], true)) return null;

    return [
        'status'     => $status,
        'expires_at' => is_string($user['expiresAt'] ?? null) ? $user['expiresAt'] : '',
        'days_left'  => is_numeric($user['daysLeft'] ?? null) ? (int) $user['daysLeft'] : -1,
    ];
}

/**
 * @brief Applies the HWID-device limit only to a confirmed ACTIVE subscription status.
 * @param info Parsed subscription info, or null when HTTP 200 did not contain a known status.
 * @param subscriptionHeaders Normalized lowercase response headers from the subscription body request.
 * @return Effective status and whether ACTIVE was changed to LIMITED by the HWID limit header.
 */
function resolveEffectiveSubscriptionStatus(?array $info, array $subscriptionHeaders): array
{
    $status = is_string($info['status'] ?? null) ? $info['status'] : 'unknown';
    $limitedByHwid = $status === 'active'
        && strcasecmp((string) ($subscriptionHeaders['x-hwid-max-devices-reached'] ?? ''), 'true') === 0;
    return [
        'status' => $limitedByHwid ? 'limited' : $status,
        'limited_by_hwid' => $limitedByHwid,
    ];
}

/**
 * @brief Converts an API UTC date-time value to a Unix timestamp using the documented ISO-8601 contract.
 *
 * @param value API date-time string that must end with the UTC "Z" designator.
 * @return Unix timestamp for a valid value, or null when the field is absent or malformed.
 */
function parseApiExpiresAt(string $value): ?int
{
    if (!preg_match(
        '/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d(?:\.\d+)?)?Z$/',
        $value,
        $parts
    )) {
        return null;
    }
    if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) return null;

    try {
        return (new DateTimeImmutable($value))->getTimestamp();
    } catch (Exception) {
        return null;
    }
}

/**
 * @brief Validates a successful subscription response and identifies its supported payload format.
 *
 * text/plain bodies must be strict Base64. application/json bodies must decode to an indexed list. All other
 * MIME types, failed HTTP responses, malformed bodies, and incompatible structures are rejected.
 *
 * @param result HTTP result containing code, headers, and body.
 * @return "base64" or "json" for a valid payload; otherwise null.
 */
function subscriptionPayloadFormat(array $result): ?string
{
    if (($result['code'] ?? 0) !== 200 || !is_array($result['headers'] ?? null) || !is_string($result['body'] ?? null)) {
        return null;
    }

    $contentType = strtolower(trim(explode(';', (string) ($result['headers']['content-type'] ?? ''), 2)[0]));
    if ($contentType === 'text/plain') {
        $encoded = trim($result['body']);
        return $encoded !== '' && base64_decode($encoded, true) !== false ? 'base64' : null;
    }
    if ($contentType === 'application/json') {
        $decoded = json_decode($result['body'], true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) && array_is_list($decoded)
            ? 'json'
            : null;
    }
    return null;
}

/**
 * @brief Maps a required substitute failure without misreporting it as absence of the main subscription.
 * @param result Failed HTTP result for the configured substitute subscription.
 * @return 503 for transport, rate-limit, and upstream 5xx failures; otherwise 502.
 */
function substituteFailureStatus(array $result): int
{
    return upstreamFailureStatus($result) === 503 ? 503 : 502;
}

/**
 * @brief Sends a body-less subscription error with a stable non-HTML content type and terminates the request.
 *
 * @param status Public 4xx or 5xx status chosen by the application error contract.
 */
function sendSubscriptionError(int $status): never
{
    header_remove();
    applyResponseSecurityHeaders();
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo '';
    exit;
}

/**
 * @brief Builds the application-owned request headers shared by production and Happ diagnostics.
 * @param config Validated application configuration.
 * @param forceHwid Optional configured checker or debug HWID.
 * @param debugProfile Whether to use the fixed diagnostic client metadata instead of incoming safe headers.
 * @return Header lines safe to send to the subscription endpoint.
 */
function buildHappForwardHeaders(array $config, string $forceHwid = '', bool $debugProfile = false): array
{
    $checkerMode = $forceHwid !== '';
    $headerMap = [];
    if (!$debugProfile) {
        $allowedRequest = forwardRequestHeaderAllowlist($config);
        $incomingHeaders = getallheaders();
        foreach (is_array($incomingHeaders) ? $incomingHeaders : [] as $name => $value) {
            if (!is_string($name) || !is_string($value)) continue;
            $lowerName = strtolower($name);
            if (!isset($allowedRequest[$lowerName]) || !isSafeHeaderValue($value)) continue;
            if ($checkerMode && in_array($lowerName, ['user-agent', 'x-hwid'], true)) continue;
            $headerMap[$lowerName] = $name . ': ' . $value;
        }
    }
    if ($debugProfile) {
        $headerMap = [
            'user-agent' => 'User-Agent: Happ/2.7.0/Windows/debug',
            'x-app-version' => 'X-App-Version: 2.7.0',
            'x-device-locale' => 'X-Device-Locale: RU',
            'x-device-os' => 'X-Device-OS: Windows',
            'x-device-model' => 'X-Device-Model: debug',
            'x-ver-os' => 'X-Ver-OS: 10_10.0.0',
            'accept-language' => 'Accept-Language: ru-RU,en,*',
        ];
    }
    if ($checkerMode) {
        if (!$debugProfile) $headerMap['user-agent'] = 'User-Agent: Happ/2.7.0/Windows/checker';
        $headerMap['x-hwid'] = 'X-HWID: ' . $forceHwid;
    }
    if (!empty($config['api_token'])) {
        $headerMap['authorization'] = 'Authorization: Bearer ' . $config['api_token'];
    }
    if (!empty($config['egames_cookie'])) {
        $headerMap['cookie'] = 'Cookie: ' . $config['egames_cookie'];
    }
    $headerMap['cache-control'] = 'Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0';
    $headerMap['pragma'] = 'Pragma: no-cache';
    $headerMap['expires'] = 'Expires: 0';
    $headerMap['x-forwarded-for'] = 'X-Forwarded-For: ' . clientIp();
    if (!isset($headerMap['accept'])) $headerMap['accept'] = 'Accept: */*';
    return array_values($headerMap);
}

/**
 * @brief Builds the minimal authenticated headers used by subscription info requests.
 * @param config Validated application configuration.
 * @return Header lines for the JSON info endpoint.
 */
function buildHappInfoHeaders(array $config): array
{
    $headers = ['Accept: application/json', 'X-Forwarded-For: ' . clientIp()];
    if (!empty($config['api_token'])) $headers[] = 'Authorization: Bearer ' . $config['api_token'];
    if (!empty($config['egames_cookie'])) $headers[] = 'Cookie: ' . $config['egames_cookie'];
    return $headers;
}

/**
 * @brief Resolves the complete status, substitute, and WL plan used by production and Happ diagnostics.
 *
 * Required info/main/substitute failures are mapped once. Optional WL bodies are ignored only for non-503
 * failures or incompatible formats; all confirmed status and grace decisions are returned to the caller.
 *
 * @param shortUuid Validated main subscription identifier.
 * @param config Validated application configuration.
 * @param forwardHeaders Headers for subscription body requests.
 * @param infoHeaders Headers for info requests.
 * @param operationPrefix Non-sensitive log-operation prefix.
 * @return Execution plan containing results, decisions, and error_status (zero on success).
 */
function resolveHappPlan(
    string $shortUuid,
    array $config,
    array $forwardHeaders,
    array $infoHeaders,
    string $operationPrefix = 'subscription'
): array {
    $base = rtrim($config['remnawave_url'], '/');
    $infoResult = cachedSubscriptionInfo(
        scopedCacheKey('subscription_info', $shortUuid),
        $shortUuid,
        'main',
        $base . '/api/sub/' . rawurlencode($shortUuid) . '/info',
        $infoHeaders,
        CACHE_TTL
    );
    $emptyResult = ['code' => 0, 'headers' => [], 'body' => '', 'ms' => 0, 'error_no' => 0, 'error' => ''];
    $plan = [
        'error_status' => 0,
        'info_result' => $infoResult,
        'main_result' => $emptyResult,
        'extra_result' => null,
        'substitute_result' => null,
        'wl_info_result' => null,
        'wl_result' => null,
        'status' => 'unknown',
        'days_left' => -1,
        'expires_at' => '',
        'limited_by_hwid' => false,
        'is_substitute' => false,
        'replace_body' => false,
        'wl_status_active' => false,
        'main_format' => null,
    ];
    if (($infoResult['code'] ?? 0) !== 200) {
        $plan['error_status'] = upstreamFailureStatus($infoResult);
        return $plan;
    }

    $info = parseSubscriptionInfo($infoResult);
    $plan['status'] = $info['status'] ?? 'unknown';
    $plan['days_left'] = $info['days_left'] ?? -1;
    $plan['expires_at'] = $info['expires_at'] ?? '';

    $mainResult = apiGet(
        $base . '/api/sub/' . rawurlencode($shortUuid),
        $forwardHeaders,
        10,
        responseBodyLimit('subscription'),
        $operationPrefix . '_main'
    );
    $plan['main_result'] = $mainResult;
    if (($mainResult['code'] ?? 0) !== 200) {
        $plan['error_status'] = upstreamFailureStatus($mainResult);
        return $plan;
    }
    $mainFormat = subscriptionPayloadFormat($mainResult);
    if ($mainFormat === null) {
        logOperationalFailure('upstream', $operationPrefix . '_main_invalid_payload', ['http_code' => 200]);
        $plan['error_status'] = 502;
        return $plan;
    }
    $plan['main_format'] = $mainFormat;

    $effective = resolveEffectiveSubscriptionStatus($info, $mainResult['headers']);
    $status = $effective['status'];
    $plan['status'] = $status;
    $plan['limited_by_hwid'] = $effective['limited_by_hwid'];
    $substituteUuid = resolveSubstituteUuid($status, $config);
    $isSubstitute = $substituteUuid !== '';
    if (!$plan['limited_by_hwid'] && $status === 'expired') {
        $graceDays = max(0, (int) ($config['expired_grace_days'] ?? 0));
        $expiresTs = parseApiExpiresAt($plan['expires_at']);
        if ($graceDays === 0 || $expiresTs === null) {
            $isSubstitute = false;
            $plan['days_left'] = 0;
        } else {
            $secondsRemaining = $expiresTs + ($graceDays * 86400) - time();
            if ($secondsRemaining <= 0) {
                $isSubstitute = false;
                $plan['days_left'] = 0;
            } else {
                $plan['days_left'] = max(1, (int) ceil($secondsRemaining / 86400));
            }
        }
    }
    $plan['is_substitute'] = $isSubstitute;

    if ($isSubstitute) {
        $substitute = apiGet(
            $base . '/api/sub/' . rawurlencode($substituteUuid),
            $forwardHeaders,
            10,
            responseBodyLimit('subscription'),
            $operationPrefix . '_substitute'
        );
        $plan['substitute_result'] = $substitute;
        if (($substitute['code'] ?? 0) !== 200) {
            $plan['error_status'] = substituteFailureStatus($substitute);
            return $plan;
        }
        if (subscriptionPayloadFormat($substitute) !== $mainFormat) {
            logOperationalFailure('upstream', $operationPrefix . '_substitute_invalid_payload', ['http_code' => 200]);
            $plan['error_status'] = 502;
            return $plan;
        }
        $plan['extra_result'] = $substitute;
        $plan['replace_body'] = $status === 'expired';
        return $plan;
    }

    if (($config['enable_wl'] ?? true) && $status === 'active') {
        $wlSuffix = is_string($config['wl_suffix'] ?? null) ? $config['wl_suffix'] : '_WL';
        $wlUuid = $shortUuid . $wlSuffix;
        $wlInfoResult = cachedSubscriptionInfo(
            scopedCacheKey('subscription_info', $wlUuid),
            $wlUuid,
            'wl',
            $base . '/api/sub/' . rawurlencode($wlUuid) . '/info',
            $infoHeaders,
            CACHE_TTL
        );
        $plan['wl_info_result'] = $wlInfoResult;
        if (($wlInfoResult['code'] ?? 0) !== 200) {
            $plan['error_status'] = upstreamFailureStatus($wlInfoResult);
            return $plan;
        }
        $wlInfo = parseSubscriptionInfo($wlInfoResult);
        $plan['wl_status_active'] = ($wlInfo['status'] ?? '') === 'active';
        if ($plan['wl_status_active']) {
            $wlResult = apiGet(
                $base . '/api/sub/' . rawurlencode($wlUuid),
                $forwardHeaders,
                10,
                responseBodyLimit('subscription'),
                $operationPrefix . '_wl'
            );
            $plan['wl_result'] = $wlResult;
            if (($wlResult['code'] ?? 0) !== 200 && upstreamFailureStatus($wlResult) === 503) {
                $plan['error_status'] = 503;
                return $plan;
            }
            if (($wlResult['code'] ?? 0) === 200 && subscriptionPayloadFormat($wlResult) === $mainFormat) {
                $plan['extra_result'] = $wlResult;
            }
        }
    }
    return $plan;
}

/**
 * @brief Builds the final safe response-header map shared by production and Happ diagnostics.
 * @param plan Successful execution plan returned by resolveHappPlan().
 * @param config Validated response configuration.
 * @param output Payload metadata returned by composeHappBody().
 * @return Lowercase response-header map excluding application-wide security headers.
 */
function buildHappResponseHeaders(array $plan, array $config, array $output): array
{
    $main = $plan['main_result'];
    $extra = $plan['extra_result'];
    $allowedResponse = forwardResponseHeaderAllowlist($config);
    $overrideRouting = ($config['happ_routing'] ?? null) !== null;
    $wlInherit = [];
    foreach ($config['wl_headers_forward'] ?? ['subscription-userinfo'] as $headerName) {
        if (!is_string($headerName)) continue;
        $lowerName = strtolower(trim($headerName));
        if (isset($allowedResponse[$lowerName])) $wlInherit[$lowerName] = true;
    }

    $headers = [];
    foreach ($main['headers'] as $name => $value) {
        if (isset($allowedResponse[$name]) && isSafeHeaderValue((string) $value)
            && !($overrideRouting && $name === 'routing') && !isset($wlInherit[$name])) {
            $headers[$name] = (string) $value;
        }
    }
    foreach (array_keys($wlInherit) as $name) {
        $value = !empty($plan['is_substitute'])
            ? ($main['headers'][$name] ?? null)
            : (($extra['headers'][$name] ?? null) ?? ($main['headers'][$name] ?? null));
        if ($value !== null && isSafeHeaderValue((string) $value)) $headers[$name] = (string) $value;
    }
    $headers['profile-web-page-url'] = currentUrl();

    if (($config['profile_title_prefix'] ?? null) !== null) {
        $headers['profile-title'] = prefixedProfileTitleHeader(
            $main['headers']['profile-title'] ?? '',
            resolveProfileTitlePrefix((string) $config['profile_title_prefix'], $config)
        );
    }
    $simpleOverrides = [
        'support_url' => 'support-url',
        'profile_update_interval' => 'profile-update-interval',
    ];
    foreach ($simpleOverrides as $key => $name) {
        if (($config[$key] ?? null) === null) continue;
        $value = (string) $config[$key];
        if ($value === '') unset($headers[$name]);
        elseif (isSafeHeaderValue($value)) $headers[$name] = $value;
    }
    if (($config['content_disposition_name'] ?? null) !== null) {
        $value = (string) $config['content_disposition_name'];
        if ($value === '') unset($headers['content-disposition']);
        elseif (isSafeHeaderValue($value)) $headers['content-disposition'] = 'attachment; filename=' . $value;
    }
    if (($config['announce'] ?? null) !== null) {
        $value = (string) $config['announce'];
        if ($value === '') unset($headers['announce']);
        else $headers['announce'] = 'base64:' . base64_encode($value);
    }

    $forbidden = array_fill_keys(forbiddenResponseHeaderNames(), true);
    foreach ($config['custom_headers'] ?? [] as $name => $value) {
        if (!is_string($name) || $value === null || (!is_scalar($value) && !$value instanceof Stringable)) continue;
        $lowerName = strtolower(trim($name));
        $textValue = (string) $value;
        if ($lowerName === '' || isset($forbidden[$lowerName])) continue;
        if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $lowerName) !== 1) continue;
        if (isSafeHeaderValue($textValue)) $headers[$lowerName] = $textValue;
    }

    $statusAnnounce = resolveStatusAnnounce((string) $plan['status'], $config);
    if ($statusAnnounce !== null) {
        if ($statusAnnounce === '') unset($headers['announce']);
        else $headers['announce'] = 'base64:' . base64_encode($statusAnnounce);
    }
    if (str_starts_with((string) $output['content_type'], 'application/json') && $overrideRouting) {
        if ($output['routing'] === null) unset($headers['routing']);
        else $headers['routing'] = (string) $output['routing'];
    }
    $headers['content-type'] = (string) $output['content_type'];
    return $headers;
}

/**
 * @brief Proxies and assembles one subscription response using only explicitly confirmed status information.
 *
 * Unknown info status falls back to the original validated subscription without WL, shuffle, substitutes, or
 * configured extra servers. Required substitutes fail with 502, while optional WL is merged only when both the
 * main and WL accounts are confirmed ACTIVE and both payload formats match.
 *
 * @param shortUuid Validated main subscription identifier.
 * @param config Application configuration for upstream access and response composition.
 * @param forceHwid Optional configured checker HWID; a non-empty value enables checker mode.
 */
function serveHapp(string $shortUuid, array $config, string $forceHwid = ''): void
{
    $forwardHeaders = buildHappForwardHeaders($config, $forceHwid);
    $plan = resolveHappPlan($shortUuid, $config, $forwardHeaders, buildHappInfoHeaders($config));
    if ($plan['error_status'] !== 0) sendSubscriptionError((int) $plan['error_status']);

    $result = $plan['main_result'];
    $extra = $plan['extra_result'];
    $status = (string) $plan['status'];
    $daysLeft = (int) $plan['days_left'];
    $isSubstitute = (bool) $plan['is_substitute'];
    $replaceBody = (bool) $plan['replace_body'];
    $wlStatusIsActive = (bool) $plan['wl_status_active'];

    $shuffleMain = $status === 'active' && !empty($config['shuffle_servers']);
    $wlOnTop = !$isSubstitute && ($config['wl_position'] ?? 'bottom') === 'top';
    $allowMainExtra = !$isSubstitute && $status === 'active';
    $allowWlExtra = !$isSubstitute && $status === 'active' && $wlStatusIsActive && $extra !== null;
    $output = composeHappBody(
        $result,
        $extra,
        $config,
        $isSubstitute ? false : $shuffleMain,
        $daysLeft,
        $replaceBody,
        $wlOnTop,
        $allowMainExtra,
        $allowWlExtra
    );
    if ($output === null) sendSubscriptionError(502);
    foreach (buildHappResponseHeaders($plan, $config, $output) as $name => $value) {
        header($name . ': ' . $value);
    }
    header_remove('Content-Length');
    echo $output['body'];
}

/**
 * @brief Shuffles an indexed list with random_int rather than shared PRNG state.
 * @param arr Indexed array modified in place.
 */
function cryptoShuffle(array &$arr): void
{
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
    }
}

/**
 * @brief Normalizes configured extra Base64 server lines.
 * @param config Application configuration containing a server-list key.
 * @param key Configuration key whose scalar entries are normalized.
 * @return Trimmed non-empty server lines in original order.
 */
function happExtraServers(array $config, string $key): array
{
    return array_values(array_filter(
        array_map('trim', (array) ($config[$key] ?? [])),
        fn($s) => $s !== ''
    ));
}

/**
 * @brief Composes the final validated subscription payload without sending headers or output.
 * @param main Validated main subscription response.
 * @param extra Optional validated WL or substitute response in the same format as main.
 * @param config Application response-composition settings.
 * @param shuffleMain Whether to cryptographically shuffle only the main server segment.
 * @param daysLeft Replacement value for EXP_DAY placeholders, or a negative value to leave them unchanged.
 * @param replaceBody Whether the extra payload fully replaces the main payload.
 * @param extraOnTop Whether the extra segment precedes the main segment when merging.
 * @param allowMainExtra Whether configured main extra servers may be appended.
 * @param allowWlExtra Whether configured WL extra servers may be appended.
 * @return Payload metadata containing body, content_type, and optional routing header, or null on invalid input.
 */
function composeHappBody(array $main, ?array $extra, array $config, bool $shuffleMain = false, int $daysLeft = -1, bool $replaceBody = false, bool $extraOnTop = false, bool $allowMainExtra = false, bool $allowWlExtra = false): ?array
{
    $format = subscriptionPayloadFormat($main);
    if ($format === null || ($extra !== null && subscriptionPayloadFormat($extra) !== $format)) return null;

    if ($format === 'base64') {
        $mainBody = base64_decode(trim($main['body']), true);
        $extraBody = $extra !== null ? base64_decode(trim($extra['body']), true) : null;
        if (!is_string($mainBody) || ($extra !== null && !is_string($extraBody))) return null;
        if ($replaceBody && $extraBody !== null) {
            $body = $extraBody;
        } else {
            if ($shuffleMain) {
                $lines = array_values(array_filter(explode("\n", $mainBody), fn($line) => trim($line) !== ''));
                cryptoShuffle($lines);
                $mainBody = implode("\n", $lines);
            }
            $mainExtra = $allowMainExtra ? happExtraServers($config, 'add_servers_base64_MAIN') : [];
            if ($mainExtra !== []) $mainBody = rtrim($mainBody) . "\n" . implode("\n", $mainExtra);
            $wlExtra = $allowWlExtra ? happExtraServers($config, 'add_servers_base64_WL') : [];
            if ($extraBody !== null && $wlExtra !== []) {
                $extraBody = rtrim($extraBody) . "\n" . implode("\n", $wlExtra);
            }
            $body = $extraBody === null
                ? $mainBody
                : ($extraOnTop
                    ? rtrim($extraBody) . "\n" . ltrim($mainBody)
                    : rtrim($mainBody) . "\n" . ltrim($extraBody));
        }
        if ($daysLeft >= 0) $body = str_replace(['{EXP_DAY}', '%7BEXP_DAY%7D'], (string) $daysLeft, $body);
        if (!empty($config['happ_routing'])) $body = $config['happ_routing'] . "\n" . ltrim($body);
        return ['body' => base64_encode($body), 'content_type' => 'text/plain; charset=utf-8', 'routing' => null];
    }

    if ($replaceBody && $extra !== null) {
        $decoded = json_decode($extra['body'], true);
    } else {
        $decoded = json_decode($main['body'], true);
        if (!is_array($decoded) || !array_is_list($decoded)) return null;
        if ($shuffleMain) cryptoShuffle($decoded);
        if ($extra !== null) {
            $extraDecoded = json_decode($extra['body'], true);
            if (!is_array($extraDecoded) || !array_is_list($extraDecoded)) return null;
            $decoded = $extraOnTop ? array_merge($extraDecoded, $decoded) : array_merge($decoded, $extraDecoded);
        }
    }
    $responseBody = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($responseBody === false) return null;
    if ($daysLeft >= 0) $responseBody = str_replace('{EXP_DAY}', (string) $daysLeft, $responseBody);
    $routing = $config['happ_routing'] ?? null;
    $routingHeader = $routing !== null && $routing !== '' && isSafeHeaderValue((string) $routing)
        ? (string) $routing
        : null;
    return ['body' => $responseBody, 'content_type' => 'application/json; charset=utf-8', 'routing' => $routingHeader];
}

// ---------------------------------------------------------------------------
// Happ request simulation available only to the configured debug client addresses.
// ---------------------------------------------------------------------------

/**
 * @brief Renders a redacted simulation of the Happ proxy flow for an authorized debug client.
 *
 * The diagnostic view follows the production status, grace, substitute, WL, body, and header rules. URLs,
 * response bodies, API credentials, cookies, CSRF secrets, and device identifiers are masked before rendering.
 *
 * @param shortUuid Validated main subscription identifier.
 * @param config Application configuration for upstream access and response composition.
 */
function serveHappDebugView(string $shortUuid, array $config): void
{
    $hwid = is_string($config['debug_hwid'] ?? null) ? trim($config['debug_hwid']) : '';
    if ($hwid === '') {
        http_response_code(400);
        exit('debug_hwid не задан в config.php');
    }

    $forwardHeaders = buildHappForwardHeaders($config, $hwid, true);
    $infoHeaders = buildHappInfoHeaders($config);
    $plan = resolveHappPlan($shortUuid, $config, $forwardHeaders, $infoHeaders, 'debug_subscription');
    $base = rtrim($config['remnawave_url'], '/');
    $url = $base . '/api/sub/' . rawurlencode($shortUuid);
    $infoResult = $plan['info_result'];
    $result = $plan['main_result'];
    $status = (string) $plan['status'];
    $isSubstitute = (bool) $plan['is_substitute'];
    $parsedExpires = parseApiExpiresAt((string) $plan['expires_at']);
    $graceExpireTs = $parsedExpires ?? 0;
    $graceActive = $status === 'expired' && resolveSubstituteUuid($status, $config) !== ''
        ? $isSubstitute
        : null;

    $output = null;
    $outHeaders = [];
    if ($plan['error_status'] === 0) {
        $shuffleMain = $status === 'active' && !empty($config['shuffle_servers']);
        $wlOnTop = !$isSubstitute && ($config['wl_position'] ?? 'bottom') === 'top';
        $output = composeHappBody(
            $result,
            $plan['extra_result'],
            $config,
            $isSubstitute ? false : $shuffleMain,
            (int) $plan['days_left'],
            (bool) $plan['replace_body'],
            $wlOnTop,
            !$isSubstitute && $status === 'active',
            !$isSubstitute && $status === 'active' && !empty($plan['wl_status_active'])
                && $plan['extra_result'] !== null
        );
        if ($output === null) $plan['error_status'] = 502;
        else $outHeaders = buildHappResponseHeaders($plan, $config, $output);
    }

    $rawReq = 'GET ' . parse_url($url, PHP_URL_PATH) . ' HTTP/1.1' . "\n"
        . 'Host: ' . (parse_url($url, PHP_URL_HOST) ?? '') . "\n";
    foreach ($forwardHeaders as $h) {
        $rawReq .= $h . "\n";
    }

    $effectiveHttpStatus = $plan['error_status'] !== 0 ? (int) $plan['error_status'] : 200;
    $rawResp = 'HTTP/1.1 ' . $effectiveHttpStatus . "\n";
    foreach ($outHeaders as $k => $v) {
        $rawResp .= $k . ': ' . $v . "\n";
    }
    $rawResp .= "\n" . ($output['body'] ?? '');

    $debugData = [
        'api_status'      => $effectiveHttpStatus,
        'api_ms'          => $result['ms'],
        'api_url'         => $url,
        'out_headers'     => $outHeaders,
        'raw_request'     => $rawReq,
        'raw_response'    => $rawResp,
        'body'            => $output['body'] ?? '',
        'user_status'     => $status,
        'is_substitute'   => $isSubstitute,
        'grace_active'    => $graceActive,
        'grace_expire_ts' => $graceExpireTs,
        'info_api_status' => $infoResult['code'],
        'info_api_ms'     => $infoResult['ms'],
        'info_body'       => $infoResult['body'],
    ];

    if ($isSubstitute) {
        $substituteUuid = resolveSubstituteUuid($status, $config);
        $subUrl    = $base . '/api/sub/' . rawurlencode($substituteUuid);
        $subResult = is_array($plan['substitute_result'])
            ? $plan['substitute_result']
            : ['code' => 0, 'headers' => [], 'body' => '', 'ms' => 0];

        $subRawReq = 'GET ' . parse_url($subUrl, PHP_URL_PATH) . ' HTTP/1.1' . "\n"
            . 'Host: ' . (parse_url($subUrl, PHP_URL_HOST) ?? '') . "\n";
        foreach ($forwardHeaders as $h) {
            $subRawReq .= $h . "\n";
        }

        $subRawResp = 'HTTP/1.1 ' . $subResult['code'] . "\n";
        foreach ($subResult['headers'] as $k => $v) {
            $subRawResp .= $k . ': ' . $v . "\n";
        }
        $subRawResp .= "\n" . $subResult['body'];

        $debugData['sub_api_status']   = $subResult['code'];
        $debugData['sub_api_ms']       = $subResult['ms'];
        $debugData['sub_api_url']      = $subUrl;
        $debugData['sub_raw_request']  = $subRawReq;
        $debugData['sub_raw_response'] = $subRawResp;

        $debugData['wl_api_status']   = 0;
        $debugData['wl_api_ms']       = 0;
        $debugData['wl_api_url']      = '';
        $debugData['wl_raw_request']  = '';
        $debugData['wl_raw_response'] = '';
    } elseif (($config['enable_wl'] ?? true) && $status === 'active' && is_array($plan['wl_info_result'])) {
        $wlSuffix = is_string($config['wl_suffix'] ?? null) ? $config['wl_suffix'] : '_WL';
        $wlUuid   = $shortUuid . $wlSuffix;
        $wlUrl    = $base . '/api/sub/' . rawurlencode($wlUuid);
        $wlInfoUrl = $wlUrl . '/info';
        $wlInfoResult = is_array($plan['wl_info_result'])
            ? $plan['wl_info_result']
            : ['code' => 0, 'headers' => [], 'body' => '', 'ms' => 0];
        $wlInfo = parseSubscriptionInfo($wlInfoResult);
        $wlStatusValue = $wlInfo['status'] ?? 'unknown';
        $wlResult = is_array($plan['wl_result'])
            ? $plan['wl_result']
            : ['code' => 0, 'headers' => [], 'body' => '', 'ms' => 0, 'error_no' => 0, 'error' => ''];

        $wlRawReq = 'GET ' . parse_url($wlUrl, PHP_URL_PATH) . ' HTTP/1.1' . "\n"
            . 'Host: ' . (parse_url($wlUrl, PHP_URL_HOST) ?? '') . "\n";
        foreach ($forwardHeaders as $h) {
            $wlRawReq .= $h . "\n";
        }

        $wlRawResp = 'HTTP/1.1 ' . $wlResult['code'] . "\n";
        foreach ($wlResult['headers'] as $k => $v) {
            $wlRawResp .= $k . ': ' . $v . "\n";
        }
        $wlRawResp .= "\n" . $wlResult['body'];

        $debugData['wl_api_status']   = $wlResult['code'];
        $debugData['wl_api_ms']       = $wlResult['ms'];
        $debugData['wl_api_url']      = $wlUrl . ' (WL userStatus: ' . strtoupper($wlStatusValue) . ')';
        $debugData['wl_info_api_url'] = $wlInfoUrl;
        $debugData['wl_info_status']  = $wlInfoResult['code'];
        $debugData['wl_info_body']    = $wlInfoResult['body'];
        $debugData['wl_raw_request']  = $wlRawReq;
        $debugData['wl_raw_response'] = $wlRawResp;
    } else {
        $debugData['wl_api_status']   = 0;
        $debugData['wl_api_ms']       = 0;
        $debugData['wl_api_url']      = $plan['error_status'] !== 0
            ? '(WL не выполнялся: основной поток завершился ошибкой)'
            : ($status !== 'active'
                ? '(WL пропущен: статус ' . strtoupper($status) . ')'
                : '(WL отключён в конфиге)');
        $debugData['wl_raw_request']  = '';
        $debugData['wl_raw_response'] = '';
    }

    renderHappDebug(redactDebugData($debugData));
}
