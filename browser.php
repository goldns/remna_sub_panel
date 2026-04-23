<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Браузер — рендер панели пользователя
// ---------------------------------------------------------------------------

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

    $infoResult = apiGet(
        $base . '/api/sub/' . rawurlencode($shortUuid) . '/info',
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

    $result = apiGet($url, $sendHeaders);

    $wlUser = null;
    $wlSuffix = $config['wl_suffix'] ?? '_WL';
    $wlUrl    = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid . $wlSuffix) . '/info';
    $wlResult = apiGet($wlUrl, $sendHeaders);
    if ($wlResult['code'] === 200) {
        $wlData = json_decode($wlResult['body'], true);
        if (!empty($wlData['response']['isFound'])) {
            $wlUser = $wlData['response']['user'] ?? null;
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
            'wl_api_url'       => $wlUrl,
            'wl_api_status'    => $wlResult['code'],
            'wl_api_ms'        => $wlResult['ms'],
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
    $hwidInfo = null;
    if (!empty($config['api_token'])) {
        $authHeader      = 'Authorization: Bearer ' . $config['api_token'];
        $username        = $user['username'] ?? '';
        $userDetailUrl   = rtrim($config['remnawave_url'], '/') . '/api/users/by-username/' . rawurlencode($username);
        $userDetailResult = apiGet($userDetailUrl, [$authHeader]);

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
                $hwidUrl       = rtrim($config['remnawave_url'], '/') . '/api/hwid/devices/' . rawurlencode($fullUuid);
                $hwidResult    = apiGet($hwidUrl, [$authHeader]);
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

    renderUserPanel($user, $debug, $wlUser, $hwidInfo, $supportUrl);
}
