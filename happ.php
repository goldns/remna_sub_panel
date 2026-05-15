<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Happ — проксирование подписки
// ---------------------------------------------------------------------------

function resolveSubstituteUuid(string $status, array $config): string
{
    return match($status) {
        'limited'  => $config['user_limited']  ?? '',
        'disabled' => $config['user_disabled'] ?? '',
        'expired'  => $config['user_expired']  ?? '',
        default    => '',
    };
}

// Возвращает статус-специфичный announce или null (= не переопределять)
function resolveStatusAnnounce(string $status, array $config): ?string
{
    return match($status) {
        'limited'  => $config['announce_limited']  ?? null,
        'disabled' => $config['announce_disabled'] ?? null,
        'expired'  => $config['announce_expired']  ?? null,
        default    => null,
    };
}

// Парсит expire=TIMESTAMP из заголовка subscription-userinfo. Возвращает 0 если не найден.
function parseSubscriptionExpire(string $header): int
{
    foreach (explode(';', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if (trim($k) === 'expire') {
            return (int) $v;
        }
    }
    return 0;
}

function decodeProfileTitleHeader(string $value): string
{
    $value = trim($value);
    if (str_starts_with($value, 'base64:')) {
        $decoded = base64_decode(substr($value, 7), true);
        return $decoded !== false ? $decoded : $value;
    }
    return $value;
}

function prefixedProfileTitleHeader(string $sourceValue, string $prefix): string
{
    return 'base64:' . base64_encode($prefix . decodeProfileTitleHeader($sourceValue));
}

function resolveProfileTitlePrefix(string $prefix, array $config): string
{
    $projectName = (string) ($config['project_name'] ?? '');
    return str_replace(['{project_name}', '{PROJECT_NAME}'], $projectName, $prefix);
}

function serveHapp(string $shortUuid, array $config, string $forceHwid = ''): void
{
    $ignored = array_flip(ignoredRequestHeaders());

    $checkerMode = $forceHwid !== '';
    $forwardHeaders = [];
    foreach (getallheaders() as $name => $value) {
        if (!isset($ignored[strtolower($name)])) {
            if ($checkerMode && strtolower($name) === 'user-agent') continue;
            $forwardHeaders[] = $name . ': ' . $value;
        }
    }
    if ($checkerMode) {
        $forwardHeaders[] = 'User-Agent: Happ/2.7.0/Windows/checker';
        $forwardHeaders[] = 'X-HWID: ' . $forceHwid;
    }
    if (!empty($config['api_token'])) {
        $forwardHeaders[] = 'Authorization: Bearer ' . $config['api_token'];
    }
    if (!empty($config['egames_cookie'])) {
        $forwardHeaders[] = 'Cookie: ' . $config['egames_cookie'];
    }
    $forwardHeaders[] = 'Cache-Control: no-cache, no-store, must-revalidate, private, max-age=0';
    $forwardHeaders[] = 'Pragma: no-cache';
    $forwardHeaders[] = 'Expires: 0';
    $forwardHeaders[] = 'X-Forwarded-For: ' . clientIp();
    $forwardHeaders[] = 'Accept: */*';

    $base = rtrim($config['remnawave_url'], '/');

    // 1. Статус пользователя (минимальные заголовки — как browser.php)
    $infoHeaders = ['Accept: application/json', 'X-Forwarded-For: ' . clientIp()];
    if (!empty($config['api_token'])) {
        $infoHeaders[] = 'Authorization: Bearer ' . $config['api_token'];
    }
    if (!empty($config['egames_cookie'])) {
        $infoHeaders[] = 'Cookie: ' . $config['egames_cookie'];
    }
    $infoResult = apiGet($base . '/api/sub/' . rawurlencode($shortUuid) . '/info', $infoHeaders);
    $status   = 'active';
    $daysLeft = -1;
    if ($infoResult['code'] === 200) {
        $infoData = json_decode($infoResult['body'], true);
        $status   = strtolower($infoData['response']['user']['userStatus'] ?? 'active');
        $daysLeft = (int) ($infoData['response']['user']['daysLeft'] ?? -1);
    }

    // 2. UUID подмены (пустая строка = не задан = работать как обычно)
    $substituteUuid = resolveSubstituteUuid($status, $config);
    $isSubstitute   = $substituteUuid !== '';

    // 3. Основной запрос (заголовки + тело оригинального пользователя)
    $url    = $base . '/api/sub/' . rawurlencode($shortUuid);
    $result = apiGet($url, $forwardHeaders);

    if ($result['code'] !== 200) {
        renderErrorPage(404, 'Not Found');
        exit;
    }

    // 4. Форвардим заголовки оригинального пользователя
    // Заголовки из wl_headers_forward глушатся здесь и выставляются позже из WL (или fallback на main)
    $ignoredResp     = array_flip(ignoredResponseHeaders());
    $overrideRouting = ($config['happ_routing'] ?? null) !== null;
    $wlInherit       = array_flip(array_map('strtolower', $config['wl_headers_forward'] ?? ['subscription-userinfo']));
    foreach ($result['headers'] as $name => $value) {
        if (!isset($ignoredResp[$name]) && !($overrideRouting && $name === 'routing') && !isset($wlInherit[$name])) {
            header($name . ': ' . $value);
        }
    }
    header('profile-web-page-url: ' . currentUrl());
    applyConfigHeaderOverrides($config, $result['headers']);
    applyHappFlags($config);

    // Статус-специфичный announce перекрывает глобальный из конфига
    $statusAnnounce = resolveStatusAnnounce($status, $config);
    if ($statusAnnounce !== null) {
        if ($statusAnnounce === '') {
            header_remove('announce');
        } else {
            header('announce: base64:' . base64_encode($statusAnnounce));
        }
    }

    // Grace-период для EXPIRED: если задан expired_grace_days > 0,
    // слияние с user_expired делается только пока не прошло N дней после expire-timestamp.
    // expire берётся из заголовка subscription-userinfo основного ответа.
    // {EXP_DAY} заменяется на кол-во дней, оставшихся до конца grace-периода.
    if ($isSubstitute && $status === 'expired') {
        $graceDays = (int) ($config['expired_grace_days'] ?? 0);
        if ($graceDays > 0) {
            $expireTs = parseSubscriptionExpire($result['headers']['subscription-userinfo'] ?? '');
            if ($expireTs > 0) {
                $secsRemaining = $expireTs + $graceDays * 86400 - time();
                if ($secsRemaining <= 0) {
                    $isSubstitute = false;
                    $daysLeft     = 0;
                } else {
                    $daysLeft = max(1, (int) ceil($secsRemaining / 86400));
                }
            }
        }
    }

    $shuffleMain = $status === 'active' && !empty($config['shuffle_servers']);

    if ($isSubstitute) {
        // 5a. Substitute: заголовки от оригинала, тело от substitute.
        // EXPIRED — полная замена тела; LIMITED/DISABLED — слияние (конкатенация/array_merge).
        // wl_headers_forward: WL не делается, берём из main
        foreach (array_keys($wlInherit) as $hname) {
            if (isset($result['headers'][$hname])) {
                header($hname . ': ' . $result['headers'][$hname]);
            }
        }
        $subResult   = apiGet($base . '/api/sub/' . rawurlencode($substituteUuid), $forwardHeaders);
        $replaceBody = ($status === 'expired');
        happOutputBody($result, $subResult['code'] === 200 ? $subResult : null, $config, false, $daysLeft, $replaceBody);
    } else {
        // 5b. Активный пользователь: оригинал + WL (если включено и статус active)
        $extra = null;
        if (($config['enable_wl'] ?? true) && $status === 'active') {
            $wlSuffix = $config['wl_suffix'] ?? '_WL';
            $wlResult = apiGet($base . '/api/sub/' . rawurlencode($shortUuid . $wlSuffix), $forwardHeaders);
            if ($wlResult['code'] === 200) {
                $extra = $wlResult;
            }
        }
        // wl_headers_forward: предпочитаем значение из WL, fallback на main
        foreach (array_keys($wlInherit) as $hname) {
            $val = ($extra !== null ? ($extra['headers'][$hname] ?? null) : null)
                ?? ($result['headers'][$hname] ?? null);
            if ($val !== null) {
                header($hname . ': ' . $val);
            }
        }
        happOutputBody($result, $extra, $config, $shuffleMain, $daysLeft);
    }
}

// Fisher-Yates с криптографической случайностью (random_int → /dev/urandom / CryptGenRandom).
// В отличие от shuffle(), не зависит от состояния Mersenne Twister.
function cryptoShuffle(array &$arr): void
{
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
    }
}

// Формирует тело ответа из $main + $extra и отправляет клиенту.
// Формат (text/plain base64 или JSON) определяется по content-type основного ответа.
// $shuffleMain  — перемешать серверы основной подписки (только ACTIVE, не WL).
// $daysLeft     — заменить {EXP_DAY} в именах серверов (>= 0 = заменить).
// $replaceBody  — true: тело берётся целиком из $extra (EXPIRED); false: слияние (LIMITED/DISABLED/WL).
function happOutputBody(array $main, ?array $extra, array $config, bool $shuffleMain = false, int $daysLeft = -1, bool $replaceBody = false): void
{
    $contentType = $main['headers']['content-type'] ?? '';

    if (str_contains($contentType, 'text/plain')) {
        if ($replaceBody && $extra !== null) {
            $body = base64_decode(trim($extra['body']), true);
        } else {
            $body = base64_decode(trim($main['body']), true);
            if ($body !== false && $shuffleMain) {
                $lines = array_values(array_filter(explode("\n", $body), fn($l) => trim($l) !== ''));
                cryptoShuffle($lines);
                $body = implode("\n", $lines);
            }
            if (!$replaceBody && $extra !== null) {
                $extraBody = base64_decode(trim($extra['body']), true);
                if ($body !== false && $extraBody !== false) {
                    $body = rtrim($body) . "\n" . ltrim($extraBody);
                }
            }
        }
        if ($daysLeft >= 0 && $body !== false) {
            $body = str_replace(['{EXP_DAY}', '%7BEXP_DAY%7D'], (string) $daysLeft, $body);
        }
        if (!empty($config['happ_routing']) && $body !== false) {
            $body = $config['happ_routing'] . "\n" . ltrim($body);
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo $body !== false ? base64_encode($body) : ($replaceBody && $extra ? $extra['body'] : $main['body']);
    } else {
        if ($replaceBody && $extra !== null) {
            $decoded = json_decode($extra['body']);
        } else {
            $decoded = json_decode($main['body']);
            if ($shuffleMain && is_array($decoded)) {
                cryptoShuffle($decoded);
            }
            if (!$replaceBody && $extra !== null) {
                $extraDecoded = json_decode($extra['body']);
                if (is_array($decoded) && is_array($extraDecoded)) {
                    $decoded = array_merge($decoded, $extraDecoded);
                }
            }
        }
        $responseBody = $decoded !== null
            ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : ($replaceBody && $extra ? $extra['body'] : $main['body']);
        if ($daysLeft >= 0) {
            $responseBody = str_replace('{EXP_DAY}', (string) $daysLeft, $responseBody);
        }
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

// Применяет переопределения заголовков из конфига.
function applyConfigHeaderOverrides(array $config, array $sourceHeaders = []): void
{
    $profileTitlePrefix = $config['profile_title_prefix'] ?? null;
    if ($profileTitlePrefix !== null) {
        header('profile-title: ' . prefixedProfileTitleHeader(
            $sourceHeaders['profile-title'] ?? '',
            resolveProfileTitlePrefix((string) $profileTitlePrefix, $config)
        ));
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
    // routing намеренно не здесь: для JSON — заголовок в happOutputBody(), для base64 — в тело
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
    if (!empty($config['egames_cookie'])) {
        $forwardHeaders[] = 'Cookie: ' . $config['egames_cookie'];
    }

    $base = rtrim($config['remnawave_url'], '/');

    // Статус пользователя (минимальные заголовки — как browser.php)
    $infoHeaders = ['Accept: application/json', 'X-Forwarded-For: ' . clientIp()];
    if (!empty($config['api_token'])) {
        $infoHeaders[] = 'Authorization: Bearer ' . $config['api_token'];
    }
    if (!empty($config['egames_cookie'])) {
        $infoHeaders[] = 'Cookie: ' . $config['egames_cookie'];
    }
    $infoResult = apiGet($base . '/api/sub/' . rawurlencode($shortUuid) . '/info', $infoHeaders);
    $status = 'active';
    if ($infoResult['code'] === 200) {
        $infoData = json_decode($infoResult['body'], true);
        $status   = strtolower($infoData['response']['user']['userStatus'] ?? 'active');
    }

    $substituteUuid = resolveSubstituteUuid($status, $config);
    $isSubstitute   = $substituteUuid !== '';

    // Основной запрос
    $url    = $base . '/api/sub/' . rawurlencode($shortUuid);
    $result = apiGet($url, $forwardHeaders);

    // Grace-период для EXPIRED (debug-view)
    $graceExpireTs = 0;
    $graceActive   = null; // null = не применимо
    if ($isSubstitute && $status === 'expired') {
        $graceDays = (int) ($config['expired_grace_days'] ?? 0);
        if ($graceDays > 0) {
            $graceExpireTs = parseSubscriptionExpire($result['headers']['subscription-userinfo'] ?? '');
            if ($graceExpireTs > 0) {
                $secondsPassed = time() - $graceExpireTs;
                $graceActive   = $secondsPassed <= $graceDays * 86400;
                if (!$graceActive) {
                    $isSubstitute = false;
                }
            }
        }
    }

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
        'support_url'              => fn($v) => ['support-url',             $v],
        'content_disposition_name' => fn($v) => ['content-disposition',     'attachment; filename=' . $v],
        'announce'                 => fn($v) => ['announce',                'base64:' . base64_encode($v)],
        'profile_update_interval'  => fn($v) => ['profile-update-interval', $v],
    ];
    if (($config['profile_title_prefix'] ?? null) !== null) {
        $outHeaders['profile-title'] = prefixedProfileTitleHeader(
            $result['headers']['profile-title'] ?? '',
            resolveProfileTitlePrefix((string) $config['profile_title_prefix'], $config)
        );
    }
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

    // Статус-специфичный announce
    $statusAnnounce = resolveStatusAnnounce($status, $config);
    if ($statusAnnounce !== null) {
        if ($statusAnnounce === '') {
            unset($outHeaders['announce']);
        } else {
            $outHeaders['announce'] = 'base64:' . base64_encode($statusAnnounce);
        }
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

    $debugData = [
        'api_status'      => $result['code'],
        'api_ms'          => $result['ms'],
        'api_url'         => $url,
        'out_headers'     => $outHeaders,
        'raw_request'     => $rawReq,
        'raw_response'    => $rawResp,
        'body'            => $result['body'],
        'user_status'     => $status,
        'is_substitute'   => $isSubstitute,
        'grace_active'    => $graceActive,
        'grace_expire_ts' => $graceExpireTs,
        'info_api_status' => $infoResult['code'],
        'info_api_ms'     => $infoResult['ms'],
        'info_body'       => $infoResult['body'],
    ];

    if ($isSubstitute) {
        $subUrl    = $base . '/api/sub/' . rawurlencode($substituteUuid);
        $subResult = apiGet($subUrl, $forwardHeaders);

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
    } else if (($config['enable_wl'] ?? true) && $status === 'active') {
        $wlSuffix = $config['wl_suffix'] ?? '_WL';
        $wlUrl    = $base . '/api/sub/' . rawurlencode($shortUuid . $wlSuffix);
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

        $debugData['wl_api_status']   = $wlResult['code'];
        $debugData['wl_api_ms']       = $wlResult['ms'];
        $debugData['wl_api_url']      = $wlUrl;
        $debugData['wl_raw_request']  = $wlRawReq;
        $debugData['wl_raw_response'] = $wlRawResp;
    } else {
        $debugData['wl_api_status']   = 0;
        $debugData['wl_api_ms']       = 0;
        $debugData['wl_api_url']      = $status !== 'active'
            ? '(WL пропущен: статус ' . strtoupper($status) . ')'
            : '(WL отключён в конфиге)';
        $debugData['wl_raw_request']  = '';
        $debugData['wl_raw_response'] = '';
    }

    renderHappDebug($debugData);
}
