<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Happ — проксирование подписки
// ---------------------------------------------------------------------------

function serveHapp(string $shortUuid, array $config): void
{
    $ignored = array_flip(ignoredRequestHeaders());

    $forwardHeaders = [];
    foreach (getallheaders() as $name => $value) {
        if (!isset($ignored[strtolower($name)])) {
            $forwardHeaders[] = $name . ': ' . $value;
        }
    }
    if (!empty($config['api_token'])) {
        $forwardHeaders[] = 'Authorization: Bearer ' . $config['api_token'];
    }
    $forwardHeaders[] = 'Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0';
    $forwardHeaders[] = 'Pragma: no-cache';
    $forwardHeaders[] = 'Expires: 0';
    $forwardHeaders[] = 'X-Forwarded-For: ' . clientIp();
    $forwardHeaders[] = 'Accept: */*';

    $url    = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid);
    $result = apiGet($url, $forwardHeaders);

    if ($result['code'] === 404) {
        renderErrorPage(404, 'Not Found');
        exit;
    }
    if ($result['code'] !== 200) {
        renderErrorPage(502, 'Bad Gateway');
        exit;
    }

    $ignoredResp    = array_flip(ignoredResponseHeaders());
    $overrideRouting = ($config['happ_routing'] ?? null) !== null;
    foreach ($result['headers'] as $name => $value) {
        if (!isset($ignoredResp[$name]) && !($overrideRouting && $name === 'routing')) {
            header($name . ': ' . $value);
        }
    }
    header('profile-web-page-url: ' . currentUrl());
    applyConfigHeaderOverrides($config);
    applyHappFlags($config);

    $wlUrl    = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid . '_WL');
    $wlResult = apiGet($wlUrl, $forwardHeaders);

    $contentType = $result['headers']['content-type'] ?? '';
    if (str_contains($contentType, 'text/plain')) {
        // Base64-формат: декодируем, сливаем с WL, перекодируем
        $main = base64_decode(trim($result['body']), true);
        if ($wlResult['code'] === 200) {
            $wl = base64_decode(trim($wlResult['body']), true);
            if ($main !== false && $wl !== false) {
                $main = rtrim($main) . "\n" . ltrim($wl);
            }
        }
        if (!empty($config['happ_routing']) && $main !== false) {
            $main = $config['happ_routing'] . "\n" . ltrim($main);
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $main !== false ? base64_encode($main) : $result['body'];
    } else {
        // JSON-формат: декодируем, сливаем массивы, кодируем обратно
        // json_decode без true: объекты {} остаются stdClass, иначе json_encode
        // превратит пустые объекты в массивы, что сломает xray-core (stats:{}, tcpSettings:{})
        $decoded = json_decode($result['body']);
        if ($wlResult['code'] === 200) {
            $wlData = json_decode($wlResult['body']);
            if (is_array($decoded) && is_array($wlData)) {
                $decoded = array_merge($decoded, $wlData);
            }
        }
        $responseBody = $decoded !== null
            ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : $result['body'];
        $routing = $config['happ_routing'] ?? null;
        if ($routing !== null) {
            if ($routing === '') {
                header_remove('routing');
            } else {
                header('routing: ' . $routing);
            }
        }
        header('Content-Type: application/json; charset=utf-8');
        echo $responseBody;
    }
}

// Применяет переопределения заголовков из конфига (null = не менять, '' = удалить)
function applyConfigHeaderOverrides(array $config): void
{
    $profileTitle = $config['profile_title'] ?? null;
    if ($profileTitle !== null) {
        if ($profileTitle === '') {
            header_remove('profile-title');
        } else {
            header('profile-title: base64:' . base64_encode($profileTitle));
        }
    }

    $supportUrl = $config['support_url'] ?? null;
    if ($supportUrl !== null) {
        if ($supportUrl === '') {
            header_remove('support-url');
        } else {
            header('support-url: ' . $supportUrl);
        }
    }

    $contentDispositionName = $config['content_disposition_name'] ?? null;
    if ($contentDispositionName !== null) {
        if ($contentDispositionName === '') {
            header_remove('content-disposition');
        } else {
            header('content-disposition: attachment; filename=' . $contentDispositionName);
        }
    }

    $announce = $config['announce'] ?? null;
    if ($announce !== null) {
        if ($announce === '') {
            header_remove('announce');
        } else {
            header('announce: base64:' . base64_encode($announce));
        }
    }

    $profileUpdateInterval = $config['profile_update_interval'] ?? null;
    if ($profileUpdateInterval !== null) {
        header('profile-update-interval: ' . $profileUpdateInterval);
    }
    // routing намеренно не здесь: для JSON — заголовок в serveHapp(), для base64 — в тело
}

// Отправляет кастомные заголовки из конфига (только для Happ-клиента)
function applyHappFlags(array $config): void
{
    foreach ($config['custom_headers'] ?? [] as $name => $value) {
        if ($value !== null) {
            header($name . ': ' . $value);
        }
    }
}

// ---------------------------------------------------------------------------
// Симуляция Happ-запроса для debug_ip с ?happ в URL
// ---------------------------------------------------------------------------

function serveHappDebugView(string $shortUuid, array $config): void
{
    $hwid = $config['debug_hwid'] ?? '';
    if ($hwid === '') {
        http_response_code(400);
        exit('debug_hwid не задан в config.php');
    }

    $forwardHeaders = [
        'User-Agent: Happ/2.7.0/Windows/debug',
        'X-App-Version: 2.7.0',
        'X-Device-Locale: RU',
        'X-Device-OS: Windows',
        'X-Device-Model: debug',
        'X-Ver-OS: 10_10.0.0',
        'X-HWID: ' . $hwid,
        'Accept-Language: ru-RU,en,*',
        'Accept: */*',
        'Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0',
        'Pragma: no-cache',
        'Expires: 0',
        'X-Forwarded-For: ' . clientIp(),
    ];
    if (!empty($config['api_token'])) {
        $forwardHeaders[] = 'Authorization: Bearer ' . $config['api_token'];
    }

    $url    = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid);
    $result = apiGet($url, $forwardHeaders);

    $ignoredResp = array_flip(ignoredResponseHeaders());
    $outHeaders  = [];
    foreach ($result['headers'] as $k => $v) {
        if (!isset($ignoredResp[$k])) {
            $outHeaders[$k] = $v;
        }
    }
    $outHeaders['profile-web-page-url'] = currentUrl();

    // Симулируем applyConfigHeaderOverrides (routing — отдельно ниже, зависит от формата)
    $overrideRouting = ($config['happ_routing'] ?? null) !== null;
    if ($overrideRouting) {
        unset($outHeaders['routing']);
    }
    $overrides = [
        'profile_title'            => fn($v) => ['profile-title',          'base64:' . base64_encode($v)],
        'support_url'              => fn($v) => ['support-url',             $v],
        'content_disposition_name' => fn($v) => ['content-disposition',     'attachment; filename=' . $v],
        'announce'                 => fn($v) => ['announce',                'base64:' . base64_encode($v)],
        'profile_update_interval'  => fn($v) => ['profile-update-interval', $v],
    ];
    foreach ($overrides as $key => $fn) {
        if (($config[$key] ?? null) !== null) {
            if ($config[$key] === '') {
                unset($outHeaders[explode(':', $fn('x')[0])[0]]);
            } else {
                [$hName, $hVal] = $fn($config[$key]);
                $outHeaders[$hName] = $hVal;
            }
        }
    }
    // routing: для JSON — заголовок, для base64 — только в тело (в заголовке не показываем)
    $debugContentType = $result['headers']['content-type'] ?? '';
    $debugIsBase64    = str_contains($debugContentType, 'text/plain');
    if ($overrideRouting && !$debugIsBase64 && !empty($config['happ_routing'])) {
        $outHeaders['routing'] = $config['happ_routing'];
    }
    foreach ($config['custom_headers'] ?? [] as $k => $v) {
        if ($v !== null) $outHeaders[$k] = $v;
    }

    $rawReq = 'GET ' . parse_url($url, PHP_URL_PATH) . ' HTTP/1.1' . "\n"
        . 'Host: ' . (parse_url($url, PHP_URL_HOST) ?? '') . "\n";
    foreach ($forwardHeaders as $h) {
        $rawReq .= $h . "\n";
    }

    $rawResp = 'HTTP/1.1 ' . $result['code'] . "\n";
    foreach ($outHeaders as $k => $v) {
        $rawResp .= $k . ': ' . $v . "\n";
    }
    $rawResp .= "\n" . $result['body'];

    $wlUrl    = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid . '_WL');
    $wlResult = apiGet($wlUrl, $forwardHeaders);

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

    renderHappDebug([
        'api_status'      => $result['code'],
        'api_ms'          => $result['ms'],
        'api_url'         => $url,
        'out_headers'     => $outHeaders,
        'raw_request'     => $rawReq,
        'raw_response'    => $rawResp,
        'body'            => $result['body'],
        'wl_api_status'   => $wlResult['code'],
        'wl_api_ms'       => $wlResult['ms'],
        'wl_api_url'      => $wlUrl,
        'wl_raw_request'  => $wlRawReq,
        'wl_raw_response' => $wlRawResp,
    ]);
}
