<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Browser-facing panel flow.
// ---------------------------------------------------------------------------

/**
 * @brief Loads and normalizes the optional external proxy-health feed for the browser panel.
 *
 * Malformed responses and entries are ignored without exposing upstream errors to regular users. Detailed
 * response data remains available only through the authorized, redacted debug panel.
 *
 * @param config Application configuration containing checker endpoint and display thresholds.
 * @return Normalized proxy data, or null when the checker is disabled, unavailable, or invalid.
 */
function fetchCheckerProxies(array $config): ?array
{
    $url = trim($config['checker_url'] ?? '');
    if ($url === '') {
        $GLOBALS['__checker_debug'] = null;
        return null;
    }

    $timeout = max(1, (int) ($config['checker_timeout'] ?? 2));
    $result  = apiGet($url, ['Accept: application/json'], $timeout, responseBodyLimit('checker'), 'checker_list');

    if ($result['code'] !== 200) {
        $GLOBALS['__checker_debug'] = [
            'url'     => $url,
            'code'    => $result['code'],
            'ms'      => $result['ms'],
            'body'    => $result['body'],
            'total'   => 0,
            'shown'   => 0,
            'hidden'  => 0,
            'success' => false,
        ];
        return null;
    }

    $data = json_decode($result['body'], true);
    if (!is_array($data) || !($data['success'] ?? false) || !is_array($data['data'] ?? null)) {
        logOperationalFailure('upstream', 'checker_invalid_payload', ['http_code' => 200]);
        $GLOBALS['__checker_debug'] = [
            'url'     => $url,
            'code'    => $result['code'],
            'ms'      => $result['ms'],
            'body'    => $result['body'],
            'total'   => 0,
            'shown'   => 0,
            'hidden'  => 0,
            'success' => false,
        ];
        return null;
    }

    $rawHideServers = is_array($config['checker_hide_servers'] ?? null)
        ? $config['checker_hide_servers']
        : [];
    $hideServers = [];
    foreach ($rawHideServers as $server) {
        if (is_string($server) || is_int($server)) $hideServers[(string) $server] = true;
    }

    $proxies = [];
    foreach ($data['data'] as $proxy) {
        if (!is_array($proxy)) continue;
        $server = is_string($proxy['server'] ?? null) || is_int($proxy['server'] ?? null)
            ? (string) $proxy['server']
            : '';
        if ($server !== '' && isset($hideServers[$server])) continue;
        // Extract and decode the optional serverDescription suffix from the display name.
        $name = is_string($proxy['name'] ?? null) ? $proxy['name'] : '';
        $desc = null;
        if (($qpos = strpos($name, '?serverDescription=')) !== false) {
            $encoded = substr($name, $qpos + strlen('?serverDescription='));
            $name    = substr($name, 0, $qpos);
            $decoded = base64_decode($encoded, true);
            if ($decoded !== false && $decoded !== '') {
                $desc = $decoded;
            }
        }
        $name = trim($name);
        $proxy['description'] = $desc;

        // Extract a flag emoji represented by two regional indicator code points.
        $chars = mb_str_split($name);
        $cp1   = isset($chars[0]) ? mb_ord($chars[0]) : 0;
        $cp2   = isset($chars[1]) ? mb_ord($chars[1]) : 0;
        $flagCode = null;
        if ($cp1 >= 0x1F1E6 && $cp1 <= 0x1F1FF && $cp2 >= 0x1F1E6 && $cp2 <= 0x1F1FF) {
            $flagCode = strtolower(chr($cp1 - 0x1F1E6 + 65) . chr($cp2 - 0x1F1E6 + 65));
            $name     = ltrim(mb_substr($name, 2));
        }

        $proxy['name']      = $name;
        $proxy['server']    = $server;
        $proxy['online']    = is_bool($proxy['online'] ?? null) ? $proxy['online'] : false;
        $proxy['latencyMs'] = is_numeric($proxy['latencyMs'] ?? null) ? (int) $proxy['latencyMs'] : 0;
        $proxy['flag_code'] = $flagCode;
        $proxies[] = $proxy;
    }

    $total  = count($data['data']);
    $shown  = count($proxies);

    $GLOBALS['__checker_debug'] = [
        'url'     => $url,
        'code'    => $result['code'],
        'ms'      => $result['ms'],
        'body'    => $result['body'],
        'total'   => $total,
        'shown'   => $shown,
        'hidden'  => $total - $shown,
        'success' => true,
    ];

    return [
        'proxies'      => $proxies,
        'latency_good' => max(1, (int) ($config['checker_latency_good'] ?? 500)),
        'latency_ok'   => max(1, (int) ($config['checker_latency_ok']   ?? 1000)),
    ];
}

/**
 * @brief Normalizes untrusted HWID device records before they reach the HTML template.
 *
 * @param devices Raw device list returned by the upstream API.
 * @return List containing only array records with scalar display fields converted to safe strings.
 */
function normalizeHwidDevices(array $devices): array
{
    $normalized = [];
    $fields = ['hwid', 'platform', 'deviceModel', 'osVersion', 'updatedAt', 'userAgent'];
    foreach ($devices as $device) {
        if (!is_array($device)) continue;
        $item = [];
        foreach ($fields as $field) {
            $value = $device[$field] ?? '';
            $item[$field] = is_string($value) || is_int($value) || is_float($value)
                ? (string) $value
                : '';
        }
        $normalized[] = $item;
    }
    return $normalized;
}

/**
 * @brief Resolves stale deletion reservations from a fresh authoritative device list.
 *
 * A reservation remains pending for at least sixty seconds. When the target is absent it is finalized as a
 * successful deletion; when it is still present it is finalized as failed and stops consuming quota.
 *
 * @param repository Persistent repository containing deletion reservations.
 * @param username Exact API username whose device list was refreshed.
 * @param userId Positive upstream user identifier associated with the refreshed list.
 * @param devices Normalized authoritative devices returned by the upstream API.
 * @param limits Configured rolling deletion limits used to calculate the resulting quota.
 */
function reconcilePendingHwidDeletions(
    StorageRepositoryInterface $repository,
    string $username,
    int $userId,
    array $devices,
    array $limits
): void {
    $byHwid = [];
    foreach ($devices as $device) {
        if (!is_array($device) || !is_string($device['hwid'] ?? null) || $device['hwid'] === '') continue;
        $byHwid[$device['hwid']] = $device;
    }
    foreach ($repository->getPendingDeletions($username, time() - 60) as $pending) {
        $pendingHwid = (string) ($pending['hwid'] ?? '');
        $stillPresent = isset($byHwid[$pendingHwid]);
        $device = $stillPresent
            ? $byHwid[$pendingHwid]
            : (is_array($pending['device'] ?? null) ? $pending['device'] : ['hwid' => $pendingHwid]);
        $device['user_id'] = $userId;
        $repository->finishDeletion(
            (int) ($pending['event_id'] ?? 0),
            !$stillPresent,
            200,
            $stillPresent ? 'reconciled_device_present' : '',
            $device,
            $limits
        );
    }
}

/**
 * @brief Deletes one validated HWID device after route authorization and CSRF verification have succeeded.
 *
 * Device metadata is accepted only from a fresh upstream list. This handler reconciles stale reservations,
 * atomically reserves the username quota, records every outcome, and never returns raw upstream or storage
 * details. The trusted debug address is audited but unlimited.
 *
 * @param shortUuid Validated subscription identifier from the current route.
 * @param config Application configuration containing API credentials and WL settings.
 */
function handleDeleteHwid(string $shortUuid, array $config): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $hwidValue = $_POST['hwid'] ?? '';
    $hwid = is_string($hwidValue) ? trim($hwidValue) : '';
    if (!preg_match('/^[A-Za-z0-9=-]{10,64}$/', $hwid)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid hwid']);
        return;
    }
    if (empty($config['api_token'])) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No API token configured']);
        return;
    }

    $repository = storageRepository();
    if ($repository === null) {
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Service Unavailable']);
        return;
    }

    $base = rtrim($config['remnawave_url'], '/');
    $authHeaders = ['Authorization: Bearer ' . $config['api_token']];
    if (!empty($config['egames_cookie'])) $authHeaders[] = 'Cookie: ' . $config['egames_cookie'];
    $wlValue = $_POST['wl'] ?? '';
    $isWl = is_string($wlValue) && $wlValue === '1';
    $role = $isWl ? 'wl' : 'main';
    $wlSuffix = is_string($config['wl_suffix'] ?? null) ? $config['wl_suffix'] : '_WL';
    $targetShortUuid = $isWl ? $shortUuid . $wlSuffix : $shortUuid;
    $targetHash = subscriptionHash($targetShortUuid);
    $limits = is_array($GLOBALS['__hwid_delete_limits'] ?? null)
        ? $GLOBALS['__hwid_delete_limits']
        : normalizeDeletionLimits([]);
    $requestUserAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null)
        ? substr($_SERVER['HTTP_USER_AGENT'], 0, 512)
        : '';

    $recordFailure = static function (
        string $username,
        ?int $userId,
        array $device,
        int $upstreamCode,
        string $category
    ) use ($repository, $targetHash, $role, $hwid, $requestUserAgent): void {
        $unlimited = ['day' => 0, 'week' => 0, 'month' => 0];
        try {
            $reservation = $repository->reserveDeletion([
                'username' => $username,
                'user_id' => $userId,
                'subscription_hash' => $targetHash,
                'account_role' => $role,
                'hwid' => $hwid,
                'device' => $device,
                'request_ip' => clientIp(),
                'request_user_agent' => $requestUserAgent,
            ], $unlimited, false);
            $repository->finishDeletion(
                (int) ($reservation['event_id'] ?? 0), false, $upstreamCode, $category, $device, $unlimited
            );
        } catch (Throwable $error) {
            logStorageFailure('record_deletion_failure', $error);
        }
    };

    $infoResult = cachedSubscriptionInfo(
        scopedCacheKey('subscription_info', $targetShortUuid),
        $targetShortUuid,
        $role,
        $base . '/api/sub/' . rawurlencode($targetShortUuid) . '/info',
        array_merge(['Accept: application/json', 'X-Forwarded-For: ' . clientIp()], $authHeaders),
        CACHE_TTL
    );
    if ($infoResult['code'] !== 200) {
        $status = upstreamFailureStatus($infoResult);
        $recordFailure('unresolved:' . substr($targetHash, 0, 16), null, ['hwid' => $hwid], (int) $infoResult['code'], 'subscription_lookup_failed');
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => upstreamFailureMessage($status)]);
        return;
    }

    $info = json_decode($infoResult['body'], true);
    $infoResponse = is_array($info) && is_array($info['response'] ?? null) ? $info['response'] : [];
    $infoUser = is_array($infoResponse['user'] ?? null) ? $infoResponse['user'] : [];
    $username = is_string($infoUser['username'] ?? null) ? $infoUser['username'] : '';
    if ($username === '') {
        $recordFailure('unresolved:' . substr($targetHash, 0, 16), null, ['hwid' => $hwid], 200, 'invalid_subscription_identity');
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Bad Gateway']);
        return;
    }

    $userDetailResult = apiGet(
        $base . '/api/users/by-username/' . rawurlencode($username),
        $authHeaders,
        10,
        responseBodyLimit('user'),
        'delete_user_lookup'
    );
    if ($userDetailResult['code'] !== 200) {
        $status = upstreamFailureStatus($userDetailResult);
        $recordFailure($username, null, ['hwid' => $hwid], (int) $userDetailResult['code'], 'user_lookup_failed');
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => upstreamFailureMessage($status)]);
        return;
    }
    $userDetail = json_decode($userDetailResult['body'], true);
    $userResponse = is_array($userDetail) && is_array($userDetail['response'] ?? null) ? $userDetail['response'] : [];
    $userId = $userResponse['id'] ?? null;
    if (!is_int($userId) || $userId <= 0) {
        $recordFailure($username, null, ['hwid' => $hwid], 200, 'invalid_user_id');
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Bad Gateway']);
        return;
    }

    $devicesResult = apiGet(
        $base . '/api/hwid/devices/' . rawurlencode((string) $userId),
        $authHeaders,
        10,
        responseBodyLimit('hwid'),
        'delete_device_list'
    );
    if ($devicesResult['code'] !== 200) {
        $recordFailure($username, $userId, ['hwid' => $hwid], (int) $devicesResult['code'], 'device_lookup_failed');
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Service Unavailable']);
        return;
    }
    $devicesData = json_decode($devicesResult['body'], true);
    $devicesResponse = is_array($devicesData) && is_array($devicesData['response'] ?? null)
        ? $devicesData['response']
        : [];
    if (!is_array($devicesResponse['devices'] ?? null)) {
        $recordFailure($username, $userId, ['hwid' => $hwid], 200, 'invalid_device_list');
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'Bad Gateway']);
        return;
    }
    $devices = normalizeHwidDevices($devicesResponse['devices']);
    try {
        reconcilePendingHwidDeletions($repository, $username, $userId, $devices, $limits);
    } catch (Throwable $error) {
        logStorageFailure('reconcile_deletion', $error);
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Service Unavailable']);
        return;
    }

    $device = null;
    foreach ($devices as $candidate) {
        if (hash_equals((string) ($candidate['hwid'] ?? ''), $hwid)) {
            $device = $candidate;
            break;
        }
    }
    if ($device === null) {
        $recordFailure($username, $userId, ['hwid' => $hwid], 200, 'device_not_found');
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Device not found']);
        return;
    }
    $device['user_id'] = $userId;

    try {
        $reservation = $repository->reserveDeletion([
            'username' => $username,
            'user_id' => $userId,
            'subscription_hash' => $targetHash,
            'account_role' => $role,
            'hwid' => $hwid,
            'device' => $device,
            'request_ip' => clientIp(),
            'request_user_agent' => $requestUserAgent,
        ], $limits, DEBUG_MODE);
    } catch (Throwable $error) {
        logStorageFailure('reserve_deletion', $error);
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Service Unavailable']);
        return;
    }
    if (empty($reservation['allowed'])) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Deletion limit exceeded', 'quota' => $reservation['quota'] ?? null]);
        return;
    }
    $eventId = (int) ($reservation['event_id'] ?? 0);

    $deleteResult = apiPost(
        $base . '/api/hwid/devices/delete',
        json_encode(['userId' => $userId, 'hwid' => $hwid]),
        array_merge($authHeaders, ['Content-Type: application/json']),
        10,
        responseBodyLimit('mutation'),
        'delete_device'
    );
    if ($deleteResult['code'] !== 200) {
        $status = upstreamFailureStatus($deleteResult);
        try {
            $quota = $repository->finishDeletion($eventId, false, (int) $deleteResult['code'], 'delete_failed', $device, $limits);
        } catch (Throwable $error) {
            logStorageFailure('finalize_failed_deletion', $error);
            $quota = null;
        }
        http_response_code($status);
        echo json_encode(['ok' => false, 'error' => upstreamFailureMessage($status), 'quota' => $quota]);
        return;
    }

    cacheDel(scopedCacheKey('hwid_devices', $userId));
    try {
        $quota = $repository->finishDeletion($eventId, true, 200, '', $device, $limits);
    } catch (Throwable $error) {
        logStorageFailure('finalize_successful_deletion', $error);
        try {
            $freshRepository = reconnectStorageRepository();
            if ($freshRepository === null) throw new RuntimeException('Storage reconnect failed');
            $quota = $freshRepository->finishDeletion($eventId, true, 200, '', $device, $limits);
        } catch (Throwable $retryError) {
            logStorageFailure('retry_finalize_successful_deletion', $retryError);
            echo json_encode([
                'ok' => true,
                'audit_pending' => true,
                'warning' => 'Device deleted; audit reconciliation is pending',
                'quota' => null,
            ]);
            return;
        }
    }
    echo json_encode(['ok' => true, 'audit_pending' => false, 'quota' => $quota]);
}

/**
 * @brief Loads subscription, WL, HWID, checker, and presentation data for the browser-facing panel.
 *
 * Upstream failures are mapped to gateway statuses, untrusted links are scheme-validated, and detailed debug
 * data is recursively redacted before rendering.
 *
 * @param shortUuid Validated subscription identifier from the current route.
 * @param config Application configuration containing API, presentation, and optional integration settings.
 */
function serveBrowser(string $shortUuid, array $config): void
{
    $url = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid) . '/info';

    $sendHeaders = ['Accept: application/json', 'X-Forwarded-For: ' . clientIp()];
    if (!empty($config['api_token'])) {
        $sendHeaders[] = 'Authorization: Bearer ' . $config['api_token'];
    }
    if (!empty($config['egames_cookie'])) {
        $sendHeaders[] = 'Cookie: ' . $config['egames_cookie'];
    }

    $result = cachedSubscriptionInfo(scopedCacheKey('subscription_info', $shortUuid), $shortUuid, 'main', $url, $sendHeaders, CACHE_TTL);

    $wlUser   = null;
    $wlUrl    = null;
    $wlResult = null;
    $wlSuffix = is_string($config['wl_suffix'] ?? null) ? $config['wl_suffix'] : '_WL';
    if ($config['enable_wl'] ?? true) {
        $wlUrl    = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid . $wlSuffix) . '/info';
        $wlResult = cachedSubscriptionInfo(
            scopedCacheKey('subscription_info', $shortUuid . $wlSuffix),
            $shortUuid . $wlSuffix,
            'wl',
            $wlUrl,
            $sendHeaders,
            CACHE_TTL
        );
        if ($wlResult['code'] === 200) {
            $wlData = json_decode($wlResult['body'], true);
            $wlResponse = is_array($wlData) && is_array($wlData['response'] ?? null)
                ? $wlData['response']
                : [];
            if (($wlResponse['isFound'] ?? null) === true && is_array($wlResponse['user'] ?? null)) {
                $wlUser = $wlResponse['user'];
            }
        }
    }

    $debug = null;
    if (DEBUG_MODE) {
        $debugHeaders = $sendHeaders;
        $requestHeaders = [];
        $incomingHeaders = getallheaders();
        foreach (is_array($incomingHeaders) ? $incomingHeaders : [] as $name => $value) {
            if (is_string($name) && is_string($value)) $requestHeaders[$name] = $value;
        }

        $rawReq = 'GET ' . parse_url($url, PHP_URL_PATH) . ' HTTP/1.1' . "\n"
            . 'Host: ' . (parse_url($url, PHP_URL_HOST) ?? '') . "\n";
        foreach ($debugHeaders as $h) {
            $rawReq .= $h . "\n";
        }

        $rawResp = 'HTTP/1.1 ' . $result['code'] . "\n";
        foreach ($result['headers'] as $k => $v) {
            $rawResp .= $k . ': ' . $v . "\n";
        }
        $rawResp .= "\n" . $result['body'];

        $wlRawReq  = '';
        $wlRawResp = '';
        if ($wlUrl !== null && $wlResult !== null) {
            $wlRawReq = 'GET ' . parse_url($wlUrl, PHP_URL_PATH) . ' HTTP/1.1' . "\n"
                . 'Host: ' . (parse_url($wlUrl, PHP_URL_HOST) ?? '') . "\n";
            foreach ($debugHeaders as $h) {
                $wlRawReq .= $h . "\n";
            }
            $wlRawResp = 'HTTP/1.1 ' . $wlResult['code'] . "\n";
            foreach ($wlResult['headers'] as $k => $v) {
                $wlRawResp .= $k . ': ' . $v . "\n";
            }
            $wlRawResp .= "\n" . $wlResult['body'];
        }

        $debug = [
            'client_ip'        => clientIp(),
            'user_agent'       => is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : '—',
            'short_uuid'       => $shortUuid,
            'request_url'      => currentUrl(),
            'req_headers'      => $requestHeaders,
            'api_url'          => $url,
            'api_req_headers'  => $debugHeaders,
            'api_status'       => $result['code'],
            'api_ms'           => $result['ms'],
            'api_resp_headers' => $result['headers'],
            'raw_request'      => $rawReq,
            'raw_response'     => $rawResp,
            'wl_api_url'       => $wlUrl ?? '(WL отключён)',
            'wl_api_status'    => $wlResult['code'] ?? 0,
            'wl_api_ms'        => $wlResult['ms'] ?? 0,
            'wl_found'         => $wlUser !== null,
            'wl_raw_request'   => $wlRawReq,
            'wl_raw_response'  => $wlRawResp,
            'config'           => $config,
        ];
        $debug = redactDebugData($debug);
    }

    if ($result['code'] !== 200) {
        $status = upstreamFailureStatus($result);
        renderErrorPage($status, upstreamFailureMessage($status), $debug);
        return;
    }
    if ($wlResult !== null && $wlResult['code'] !== 200 && upstreamFailureStatus($wlResult) === 503) {
        renderErrorPage(503, upstreamFailureMessage(503), $debug);
        return;
    }

    $data = json_decode($result['body'], true);
    if (!is_array($data) || !is_array($data['response'] ?? null)) {
        renderErrorPage(502, 'Bad Gateway', $debug);
        return;
    }
    $response = $data['response'];
    $user = is_array($response['user'] ?? null) ? $response['user'] : null;

    if (($response['isFound'] ?? null) === false) {
        renderErrorPage(404, 'Not Found', $debug);
        return;
    }
    if ($user === null || ($response['isFound'] ?? null) !== true) {
        renderErrorPage(502, 'Bad Gateway', $debug);
        return;
    }

    // Load HWID device details only when API authorization is configured.
    $hwidInfo            = null;
    $wlHwidInfo          = null;
    $hwidDeleteQuota     = null;
    $wlHwidDeleteQuota   = null;
    $limits = is_array($GLOBALS['__hwid_delete_limits'] ?? null)
        ? $GLOBALS['__hwid_delete_limits']
        : normalizeDeletionLimits([]);
    $username            = is_string($user['username'] ?? null) ? $user['username'] : '';
    $wlUsername          = $wlUser !== null && is_string($wlUser['username'] ?? null) ? $wlUser['username'] : '';
    if (!empty($config['api_token'])) {
        $authHeaders = ['Authorization: Bearer ' . $config['api_token']];
        if (!empty($config['egames_cookie'])) {
            $authHeaders[] = 'Cookie: ' . $config['egames_cookie'];
        }
        $base            = rtrim($config['remnawave_url'], '/');
        $userDetailUrl   = $base . '/api/users/by-username/' . rawurlencode($username);
        $userDetailResult = $username !== ''
            ? cachedApiGet(scopedCacheKey('user_detail', $username), $userDetailUrl, $authHeaders, CACHE_TTL * 5,
                responseBodyLimit('user'), 'browser_user_lookup')
            : ['code' => 0, 'headers' => [], 'body' => '', 'ms' => 0, 'error_no' => 0, 'error' => ''];

        if ($username !== '' && $userDetailResult['code'] !== 200) {
            $status = upstreamFailureStatus($userDetailResult);
            renderErrorPage($status, upstreamFailureMessage($status), $debug);
            return;
        }

        if ($userDetailResult['code'] === 200) {
            $userDetail = json_decode($userDetailResult['body'], true);
            $userDetailResponse = is_array($userDetail) && is_array($userDetail['response'] ?? null)
                ? $userDetail['response']
                : [];
            $userId     = $userDetailResponse['id'] ?? null;
            $rawHwidLimit = $userDetailResponse['hwidDeviceLimit'] ?? null;
            $hwidLimit  = is_numeric($rawHwidLimit) ? (int) $rawHwidLimit : null;
            if (!is_int($userId) || $userId <= 0) {
                renderErrorPage(502, upstreamFailureMessage(502), $debug);
                return;
            }

            $hwidCount     = 0;
            $hwidDevices   = [];
            $hwidApiStatus = null;
            $hwidApiMs     = null;
            $hwidUrl       = null;

            if (is_int($userId) && $userId > 0) {
                $hwidUrl       = $base . '/api/hwid/devices/' . rawurlencode((string) $userId);
                $hwidResult    = cachedApiGet(scopedCacheKey('hwid_devices', $userId), $hwidUrl, $authHeaders,
                    CACHE_TTL, responseBodyLimit('hwid'), 'browser_device_list');
                $hwidApiStatus = $hwidResult['code'];
                $hwidApiMs     = $hwidResult['ms'];
                if ($hwidResult['code'] !== 200) {
                    $status = upstreamFailureStatus($hwidResult);
                    renderErrorPage($status, upstreamFailureMessage($status), $debug);
                    return;
                }
                if ($hwidResult['code'] === 200) {
                    $hwidData    = json_decode($hwidResult['body'], true);
                    $hwidResponse = is_array($hwidData) && is_array($hwidData['response'] ?? null)
                        ? $hwidData['response']
                        : [];
                    $hwidCountValue = $hwidResponse['total'] ?? 0;
                    if (!is_array($hwidResponse['devices'] ?? null) || !is_numeric($hwidResponse['total'] ?? null)) {
                        renderErrorPage(502, upstreamFailureMessage(502), $debug);
                        return;
                    }
                    $hwidCount   = is_numeric($hwidCountValue) ? (int) $hwidCountValue : 0;
                    $hwidDevices = normalizeHwidDevices(
                        is_array($hwidResponse['devices'] ?? null) ? $hwidResponse['devices'] : []
                    );
                    $repository = storageRepository();
                    if ($repository !== null) {
                        try {
                            $pending = $repository->getPendingDeletions($username, time() - 60);
                            if ($pending !== []) {
                                $freshHwidResult = apiGet($hwidUrl, $authHeaders, 10,
                                    responseBodyLimit('hwid'), 'reconcile_main_devices');
                                if ($freshHwidResult['code'] !== 200) {
                                    renderErrorPage(503, upstreamFailureMessage(503), $debug);
                                    return;
                                }
                                $freshHwidData = json_decode($freshHwidResult['body'], true);
                                $freshHwidResponse = is_array($freshHwidData) && is_array($freshHwidData['response'] ?? null)
                                    ? $freshHwidData['response']
                                    : [];
                                if (!is_array($freshHwidResponse['devices'] ?? null)
                                    || !is_numeric($freshHwidResponse['total'] ?? null)) {
                                    renderErrorPage(502, upstreamFailureMessage(502), $debug);
                                    return;
                                }
                                $hwidCount = (int) $freshHwidResponse['total'];
                                $hwidDevices = normalizeHwidDevices($freshHwidResponse['devices']);
                                reconcilePendingHwidDeletions($repository, $username, $userId, $hwidDevices, $limits);
                                cacheSet(scopedCacheKey('hwid_devices', $userId), $freshHwidResult, CACHE_TTL);
                                $hwidResult = $freshHwidResult;
                                $hwidApiStatus = $freshHwidResult['code'];
                                $hwidApiMs = $freshHwidResult['ms'];
                            }
                            $repository->updateSubscriptionDevices(
                                subscriptionHash($shortUuid),
                                'main',
                                $userId,
                                $hwidLimit,
                                $hwidCount
                            );
                        } catch (Throwable $error) {
                            logStorageFailure('update_main_devices', $error);
                            $GLOBALS['__storage_error'] = 'storage_unavailable';
                        }
                    }
                }
            }

            $hwidInfo = [
                'limit'   => $hwidLimit,
                'count'   => $hwidCount,
                'devices' => $hwidDevices,
            ];

            // Load the WL user's HWID devices when a WL account was resolved.
            if ($wlUser !== null && ($config['enable_wl'] ?? true)) {
                $wlUserDetailResult = $wlUsername !== ''
                    ? cachedApiGet(scopedCacheKey('user_detail', $wlUsername),
                        $base . '/api/users/by-username/' . rawurlencode($wlUsername), $authHeaders, CACHE_TTL * 5,
                        responseBodyLimit('user'), 'browser_wl_user_lookup')
                    : ['code' => 0, 'headers' => [], 'body' => '', 'ms' => 0, 'error_no' => 0, 'error' => ''];
                if ($wlUsername !== '' && $wlUserDetailResult['code'] !== 200) {
                    $status = upstreamFailureStatus($wlUserDetailResult);
                    renderErrorPage($status, upstreamFailureMessage($status), $debug);
                    return;
                }
                if ($wlUserDetailResult['code'] === 200) {
                    $wlUserDetail  = json_decode($wlUserDetailResult['body'], true);
                    $wlDetailResponse = is_array($wlUserDetail) && is_array($wlUserDetail['response'] ?? null)
                        ? $wlUserDetail['response']
                        : [];
                    $wlUserId      = $wlDetailResponse['id'] ?? null;
                    $rawWlHwidLimit = $wlDetailResponse['hwidDeviceLimit'] ?? null;
                    $wlHwidLimit   = is_numeric($rawWlHwidLimit) ? (int) $rawWlHwidLimit : null;
                    if (!is_int($wlUserId) || $wlUserId <= 0) {
                        renderErrorPage(502, upstreamFailureMessage(502), $debug);
                        return;
                    }
                    if (is_int($wlUserId) && $wlUserId > 0) {
                        $wlHwidResult = cachedApiGet(scopedCacheKey('hwid_devices', $wlUserId),
                            $base . '/api/hwid/devices/' . rawurlencode((string) $wlUserId), $authHeaders,
                            CACHE_TTL, responseBodyLimit('hwid'), 'browser_wl_device_list');
                        if ($wlHwidResult['code'] !== 200) {
                            $status = upstreamFailureStatus($wlHwidResult);
                            renderErrorPage($status, upstreamFailureMessage($status), $debug);
                            return;
                        }
                        if ($wlHwidResult['code'] === 200) {
                            $wlHwidData  = json_decode($wlHwidResult['body'], true);
                            $wlHwidResponse = is_array($wlHwidData) && is_array($wlHwidData['response'] ?? null)
                                ? $wlHwidData['response']
                                : [];
                            $wlHwidCountValue = $wlHwidResponse['total'] ?? 0;
                            if (!is_array($wlHwidResponse['devices'] ?? null) || !is_numeric($wlHwidResponse['total'] ?? null)) {
                                renderErrorPage(502, upstreamFailureMessage(502), $debug);
                                return;
                            }
                            $wlHwidInfo  = [
                                'limit'   => $wlHwidLimit,
                                'count'   => is_numeric($wlHwidCountValue) ? (int) $wlHwidCountValue : 0,
                                'devices' => normalizeHwidDevices(
                                    is_array($wlHwidResponse['devices'] ?? null) ? $wlHwidResponse['devices'] : []
                                ),
                            ];
                            $repository = storageRepository();
                            if ($repository !== null) {
                                try {
                                    $pending = $repository->getPendingDeletions($wlUsername, time() - 60);
                                    if ($pending !== []) {
                                        $wlHwidUrl = $base . '/api/hwid/devices/' . rawurlencode((string) $wlUserId);
                                        $freshWlHwidResult = apiGet($wlHwidUrl, $authHeaders, 10,
                                            responseBodyLimit('hwid'), 'reconcile_wl_devices');
                                        if ($freshWlHwidResult['code'] !== 200) {
                                            renderErrorPage(503, upstreamFailureMessage(503), $debug);
                                            return;
                                        }
                                        $freshWlHwidData = json_decode($freshWlHwidResult['body'], true);
                                        $freshWlHwidResponse = is_array($freshWlHwidData)
                                            && is_array($freshWlHwidData['response'] ?? null)
                                            ? $freshWlHwidData['response']
                                            : [];
                                        if (!is_array($freshWlHwidResponse['devices'] ?? null)
                                            || !is_numeric($freshWlHwidResponse['total'] ?? null)) {
                                            renderErrorPage(502, upstreamFailureMessage(502), $debug);
                                            return;
                                        }
                                        $wlHwidInfo['count'] = (int) $freshWlHwidResponse['total'];
                                        $wlHwidInfo['devices'] = normalizeHwidDevices($freshWlHwidResponse['devices']);
                                        reconcilePendingHwidDeletions(
                                            $repository,
                                            $wlUsername,
                                            $wlUserId,
                                            $wlHwidInfo['devices'],
                                            $limits
                                        );
                                        cacheSet(scopedCacheKey('hwid_devices', $wlUserId), $freshWlHwidResult, CACHE_TTL);
                                    }
                                    $repository->updateSubscriptionDevices(
                                        subscriptionHash($shortUuid . $wlSuffix),
                                        'wl',
                                        $wlUserId,
                                        $wlHwidLimit,
                                        (int) $wlHwidInfo['count']
                                    );
                                } catch (Throwable $error) {
                                    logStorageFailure('update_wl_devices', $error);
                                    $GLOBALS['__storage_error'] = 'storage_unavailable';
                                }
                            }
                        }
                    }
                }
            }

            if ($debug !== null) {
                $hwidRawReq = 'GET ' . parse_url($hwidUrl ?? $userDetailUrl, PHP_URL_PATH) . ' HTTP/1.1' . "\n"
                    . 'Host: ' . (parse_url($hwidUrl ?? $userDetailUrl, PHP_URL_HOST) ?? '') . "\n"
                    . 'Authorization: Bearer [hidden]' . "\n";

                $hwidRawResp = 'HTTP/1.1 ' . ($hwidApiStatus ?? '—') . "\n\n"
                    . (isset($hwidResult) ? $hwidResult['body'] : '');

                $debug['hwid_user_url']     = $userDetailUrl;
                $debug['hwid_user_status']  = $userDetailResult['code'];
                $debug['hwid_user_ms']      = $userDetailResult['ms'];
                $debug['hwid_user_id']      = $userId;
                $debug['hwid_limit']        = $hwidLimit;
                $debug['hwid_api_url']      = $hwidUrl;
                $debug['hwid_api_status']   = $hwidApiStatus;
                $debug['hwid_api_ms']       = $hwidApiMs;
                $debug['hwid_count']        = $hwidCount;
                $debug['hwid_raw_request']  = $hwidRawReq;
                $debug['hwid_raw_response'] = $hwidRawResp;
            }
        }
    }

    $repository = storageRepository();
    if ($repository !== null) {
        try {
            if ($username !== '') $hwidDeleteQuota = $repository->getDeletionQuota($username, $limits, DEBUG_MODE);
            if ($wlUsername !== '') $wlHwidDeleteQuota = $repository->getDeletionQuota($wlUsername, $limits, DEBUG_MODE);
        } catch (Throwable $error) {
            logStorageFailure('load_deletion_quota', $error);
            $GLOBALS['__storage_error'] = 'storage_unavailable';
            $hwidDeleteQuota = null;
            $wlHwidDeleteQuota = null;
        }
    }

    if (ENCRYPT_SUB_LINK) {
        $GLOBALS['__sub_link'] = encryptSubLink(currentUrl());
    } else {
        $GLOBALS['__sub_link'] = 'happ://add/' . currentUrl();
    }

    if ($debug !== null) {
        $debug['encrypt'] = $GLOBALS['__encrypt_debug'] ?? [
            'api_url'      => null,
            'input_url'    => currentUrl(),
            'code'         => null,
            'ms'           => null,
            'curl_error'   => '',
            'raw_body'     => '',
            'result'       => $GLOBALS['__sub_link'],
            'used_fallback'=> !ENCRYPT_SUB_LINK,
        ];
    }

    $configuredSupportUrl = $config['support_url'] ?? null;
    $supportUrl = $configuredSupportUrl !== null
        ? (is_string($configuredSupportUrl) ? $configuredSupportUrl : '')
        : ($result['headers']['support-url'] ?? '');
    $supportUrl = sanitizeExternalUrl(is_string($supportUrl) ? $supportUrl : '');

    $checkerProxies = fetchCheckerProxies($config);

    if ($debug !== null) {
        $debug['checker'] = $GLOBALS['__checker_debug'] ?? null;
    }

    $renewUserId = isset($userDetailResponse) && is_int($userDetailResponse['id'] ?? null)
        ? (string) $userDetailResponse['id']
        : '';

    $renewUrl = '';
    $paymentUrl = is_string($config['payment_url'] ?? null) ? $config['payment_url'] : '';
    if ($paymentUrl !== '') {
        $renewUrl = buildRenewUrl($paymentUrl, $shortUuid, $user, $renewUserId);
    }

    $renewUrlTg = '';
    $paymentUrlTg = is_string($config['payment_url_tg'] ?? null) ? $config['payment_url_tg'] : '';
    if ($paymentUrlTg !== '') {
        $renewUrlTg = buildRenewUrl($paymentUrlTg, $shortUuid, $user, $renewUserId);
    }

    $csrfSecret = is_string($config['csrf_secret'] ?? null) ? trim($config['csrf_secret']) : '';
    $csrfToken  = createDeleteHwidCsrfToken($shortUuid, $csrfSecret, 600);
    if ($debug !== null) {
        $debug = redactDebugData($debug);
    }

    renderUserPanel(
        $user,
        $debug,
        $wlUser,
        $hwidInfo,
        $supportUrl,
        $wlHwidInfo,
        $checkerProxies,
        $renewUrl,
        $renewUrlTg,
        $csrfToken,
        $hwidDeleteQuota,
        $wlHwidDeleteQuota
    );
}
