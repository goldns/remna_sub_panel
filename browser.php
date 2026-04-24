<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Браузер — рендер панели пользователя
// ---------------------------------------------------------------------------

function fetchCheckerProxies(array $config): ?array
{
    $url = trim($config['checker_url'] ?? '');
    if ($url === '') {
        $GLOBALS['__checker_debug'] = null;
        return null;
    }

    $timeout = max(1, (int) ($config['checker_timeout'] ?? 2));
    $result  = apiGet($url, ['Accept: application/json'], $timeout);

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
    if (!($data['success'] ?? false) || !is_array($data['data'] ?? null)) {
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

    $hideServers = array_flip($config['checker_hide_servers'] ?? []);

    $proxies = [];
    foreach ($data['data'] as $proxy) {
        if (isset($hideServers[$proxy['server'] ?? ''])) continue;
        // Извлекаем ?serverDescription=<base64> из имени
        $name = (string) ($proxy['name'] ?? '');
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

        // Извлекаем флаг-эмодзи (пара региональных индикаторов U+1F1E6–U+1F1FF)
        $chars = mb_str_split($name);
        $cp1   = isset($chars[0]) ? mb_ord($chars[0]) : 0;
        $cp2   = isset($chars[1]) ? mb_ord($chars[1]) : 0;
        $flagCode = null;
        if ($cp1 >= 0x1F1E6 && $cp1 <= 0x1F1FF && $cp2 >= 0x1F1E6 && $cp2 <= 0x1F1FF) {
            $flagCode = strtolower(chr($cp1 - 0x1F1E6 + 65) . chr($cp2 - 0x1F1E6 + 65));
            $name     = ltrim(mb_substr($name, 2));
        }

        $proxy['name']      = $name;
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

function handleDeleteHwid(string $shortUuid, array $config): void
{
    header('Content-Type: application/json');

    $hwid = trim($_POST['hwid'] ?? '');
    if ($hwid === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing hwid']);
        return;
    }

    if (empty($config['api_token'])) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'No API token configured']);
        return;
    }

    $base       = rtrim($config['remnawave_url'], '/');
    $authHeader = 'Authorization: Bearer ' . $config['api_token'];
    $isWl       = !empty($_POST['wl']);

    $targetUuid = $isWl
        ? $shortUuid . ($config['wl_suffix'] ?? '_WL')
        : $shortUuid;

    $infoResult = apiGet(
        $base . '/api/sub/' . rawurlencode($targetUuid) . '/info',
        ['Accept: application/json', 'X-Forwarded-For: ' . clientIp(), $authHeader]
    );

    if ($infoResult['code'] !== 200) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'User not found']);
        return;
    }

    $info     = json_decode($infoResult['body'], true);
    $username = $info['response']['user']['username'] ?? '';

    if ($username === '') {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Username not found']);
        return;
    }

    $userDetailResult = apiGet(
        $base . '/api/users/by-username/' . rawurlencode($username),
        [$authHeader]
    );

    if ($userDetailResult['code'] !== 200) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'User detail not found']);
        return;
    }

    $userDetail = json_decode($userDetailResult['body'], true);
    $userUuid   = $userDetail['response']['uuid'] ?? '';

    if ($userUuid === '') {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'UUID not found']);
        return;
    }

    $deleteResult = apiPost(
        $base . '/api/hwid/devices/delete',
        json_encode(['userUuid' => $userUuid, 'hwid' => $hwid]),
        [$authHeader, 'Content-Type: application/json']
    );

    if ($deleteResult['code'] === 200) {
        cacheDel('rsb_hwid_' . $userUuid);
        echo json_encode(['ok' => true]);
    } else {
        http_response_code($deleteResult['code'] ?: 502);
        echo json_encode(['ok' => false, 'error' => 'Delete failed: ' . $deleteResult['code']]);
    }
}

function serveBrowser(string $shortUuid, array $config): void
{
    $url = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid) . '/info';

    $sendHeaders = ['Accept: application/json', 'X-Forwarded-For: ' . clientIp()];
    if (!empty($config['api_token'])) {
        $sendHeaders[] = 'Authorization: Bearer ' . $config['api_token'];
    }

    $result = cachedApiGet('rsb_info_' . $shortUuid, $url, $sendHeaders, CACHE_TTL);

    $wlUser   = null;
    $wlUrl    = null;
    $wlResult = null;
    $wlSuffix = $config['wl_suffix'] ?? '_WL';
    if ($config['enable_wl'] ?? true) {
        $wlUrl    = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid . $wlSuffix) . '/info';
        $wlResult = cachedApiGet('rsb_info_' . $shortUuid . $wlSuffix, $wlUrl, $sendHeaders, CACHE_TTL);
        if ($wlResult['code'] === 200) {
            $wlData = json_decode($wlResult['body'], true);
            if (!empty($wlData['response']['isFound'])) {
                $wlUser = $wlData['response']['user'] ?? null;
            }
        }
    }

    $debug = null;
    if (DEBUG_MODE) {
        $safeConfig = $config;
        $safeConfig['api_token'] = empty($config['api_token']) ? '(not set)' : '[hidden]';

        $debugHeaders = array_map(
            fn($h) => str_starts_with($h, 'Authorization:') ? 'Authorization: Bearer [hidden]' : $h,
            $sendHeaders
        );

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
            'user_agent'       => $_SERVER['HTTP_USER_AGENT'] ?? '—',
            'short_uuid'       => $shortUuid,
            'request_url'      => currentUrl(),
            'req_headers'      => getallheaders(),
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
            'config'           => $safeConfig,
        ];
    }

    if ($result['code'] !== 200) {
        renderErrorPage(404, 'Not Found', $debug);
        return;
    }

    $data = json_decode($result['body'], true);
    $user = $data['response']['user'] ?? null;

    if (!$user || !($data['response']['isFound'] ?? false)) {
        renderErrorPage(404, 'Not Found', $debug);
        return;
    }

    // Загружаем информацию об HWID-устройствах (требуется api_token)
    $hwidInfo   = null;
    $wlHwidInfo = null;
    if (!empty($config['api_token'])) {
        $authHeader      = 'Authorization: Bearer ' . $config['api_token'];
        $base            = rtrim($config['remnawave_url'], '/');
        $username        = $user['username'] ?? '';
        $userDetailUrl   = $base . '/api/users/by-username/' . rawurlencode($username);
        $userDetailResult = cachedApiGet('rsb_udet_' . $username, $userDetailUrl, [$authHeader], CACHE_TTL * 5);

        if ($userDetailResult['code'] === 200) {
            $userDetail = json_decode($userDetailResult['body'], true);
            $fullUuid   = $userDetail['response']['uuid']            ?? null;
            $hwidLimit  = $userDetail['response']['hwidDeviceLimit'] ?? null;

            $hwidCount     = 0;
            $hwidDevices   = [];
            $hwidApiStatus = null;
            $hwidApiMs     = null;
            $hwidUrl       = null;

            if ($fullUuid) {
                $hwidUrl       = $base . '/api/hwid/devices/' . rawurlencode($fullUuid);
                $hwidResult    = cachedApiGet('rsb_hwid_' . $fullUuid, $hwidUrl, [$authHeader], CACHE_TTL);
                $hwidApiStatus = $hwidResult['code'];
                $hwidApiMs     = $hwidResult['ms'];
                if ($hwidResult['code'] === 200) {
                    $hwidData    = json_decode($hwidResult['body'], true);
                    $hwidCount   = (int) ($hwidData['response']['total']   ?? 0);
                    $hwidDevices = $hwidData['response']['devices'] ?? [];
                }
            }

            $hwidInfo = [
                'limit'   => $hwidLimit,
                'count'   => $hwidCount,
                'devices' => $hwidDevices,
            ];

            // Загружаем HWID-устройства WL-пользователя
            if ($wlUser !== null && ($config['enable_wl'] ?? true)) {
                $wlUsername        = $wlUser['username'] ?? '';
                $wlUserDetailResult = cachedApiGet('rsb_udet_' . $wlUsername, $base . '/api/users/by-username/' . rawurlencode($wlUsername), [$authHeader], CACHE_TTL * 5);
                if ($wlUserDetailResult['code'] === 200) {
                    $wlUserDetail  = json_decode($wlUserDetailResult['body'], true);
                    $wlFullUuid    = $wlUserDetail['response']['uuid']            ?? null;
                    $wlHwidLimit   = $wlUserDetail['response']['hwidDeviceLimit'] ?? null;
                    if ($wlFullUuid) {
                        $wlHwidResult = cachedApiGet('rsb_hwid_' . $wlFullUuid, $base . '/api/hwid/devices/' . rawurlencode($wlFullUuid), [$authHeader], CACHE_TTL);
                        if ($wlHwidResult['code'] === 200) {
                            $wlHwidData  = json_decode($wlHwidResult['body'], true);
                            $wlHwidInfo  = [
                                'limit'   => $wlHwidLimit,
                                'count'   => (int) ($wlHwidData['response']['total']   ?? 0),
                                'devices' => $wlHwidData['response']['devices'] ?? [],
                            ];
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
                $debug['hwid_uuid']         = $fullUuid;
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

    // Приоритет: конфиг → заголовок из ответа API
    $supportUrl = $config['support_url'] !== null
        ? (string) $config['support_url']
        : ($result['headers']['support-url'] ?? '');

    $checkerProxies = fetchCheckerProxies($config);

    if ($debug !== null) {
        $debug['checker'] = $GLOBALS['__checker_debug'] ?? null;
    }

    renderUserPanel($user, $debug, $wlUser, $hwidInfo, $supportUrl, $wlHwidInfo, $checkerProxies);
}
