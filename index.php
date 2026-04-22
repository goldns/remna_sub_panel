<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

define('VERSION',      '1.3.0');
define('SHOW_VERSION', (bool) ($config['show_version'] ?? false));
define('PROJECT_NAME',     $config['project_name']     ?? '');
define('SHOW_QR',          (bool) ($config['show_qr']          ?? false));
define('COPYRIGHT',        $config['copyright']        ?? '');
define('ENCRYPT_SUB_LINK', (bool) ($config['encrypt_sub_link'] ?? true));
define('DEBUG_MODE',       !empty($config['debug_ip']) && clientIp() === $config['debug_ip']);

require __DIR__ . '/template.php';

$_langCode = $config['lang'] ?? 'ru';
$_langFile = __DIR__ . '/lang/lang_' . $_langCode . '.php';
initLang(file_exists($_langFile) ? require $_langFile : require __DIR__ . '/lang/lang_ru.php');

// ---------------------------------------------------------------------------
// 1. Извлекаем shortUuid из строки запроса (подставляется .htaccess rewrite)
// ---------------------------------------------------------------------------
$shortUuid = $_GET['id'] ?? '';

if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $shortUuid)) {
    renderErrorPage(404, 'Not Found');
    exit;
}

// ---------------------------------------------------------------------------
// 2. Определяем тип клиента и направляем запрос
// ---------------------------------------------------------------------------
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isHapp    = (bool) preg_match('/^Happ\/[\d.]+\//', $userAgent);
$hwid      = $_SERVER['HTTP_X_HWID'] ?? '';

if (DEBUG_MODE && isset($_GET['happ'])) {
    serveHappDebugView($shortUuid, $config);
} elseif ($isHapp) {
    if ($hwid === '') {
        renderErrorPage(403, 'Forbidden');
        exit;
    }
    serveHapp($shortUuid, $config);
} else {
    serveBrowser($shortUuid, $config);
}

// ---------------------------------------------------------------------------
// Вспомогательные функции
// ---------------------------------------------------------------------------

// Полный URL текущего запроса (используется для profile-web-page-url)
function currentUrl(): string
{
    $proto = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $proto = 'https';
    }
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return $proto . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
}

// Реальный IP клиента с учётом X-Forwarded-For от доверенного прокси
function clientIp(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// GET-запрос к API Remnawave, возвращает код, заголовки, тело и время выполнения
function apiGet(string $url, array $extraHeaders = []): array
{
    $responseHeaders = [];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $extraHeaders,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING       => '', // принять любое сжатие, распаковать автоматически
        CURLOPT_HEADERFUNCTION => function ($_, $header) use (&$responseHeaders) {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($header);
        },
    ]);

    $t0   = microtime(true);
    $body = curl_exec($ch);
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code'    => $code,
        'headers' => $responseHeaders,
        'body'    => (string) $body,
        'ms'      => $ms,
    ];
}

// Заголовки входящего Happ-запроса, которые НЕ нужно пробрасывать в Remnawave
function ignoredRequestHeaders(): array
{
    return [
        'accept-encoding', 'alt-svc', 'authorization', 'cache-control',
        'cf-access-client-id', 'cf-access-client-secret', 'cf-cache-status', 'cf-ray',
        'connection', 'content-length', 'content-security-policy',
        'cross-origin-opener-policy', 'cross-origin-resource-policy',
        'expires', 'host', 'keep-alive', 'nel', 'origin-agent-cluster', 'pragma',
        'proxy-authenticate', 'proxy-authorization', 'report-to', 'server', 'te',
        'trailer', 'transfer-encoding', 'upgrade',
        'x-api-key', 'x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-scheme',
    ];
}

// Заголовки ответа Remnawave, которые НЕ нужно отправлять клиенту (транспортный уровень)
function ignoredResponseHeaders(): array
{
    return [
        'server', 'date', 'vary', 'content-encoding', 'transfer-encoding',
        'connection', 'keep-alive',
        'strict-transport-security', 'x-content-type-options', 'x-dns-prefetch-control',
        'x-download-options', 'x-frame-options', 'x-permitted-cross-domain-policies',
        'x-xss-protection', 'cross-origin-opener-policy', 'cross-origin-resource-policy',
        'origin-agent-cluster', 'referrer-policy', 'access-control-allow-origin',
        'x-robots-tag', 'etag',
    ];
}

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

    $ignoredResp = array_flip(ignoredResponseHeaders());
    foreach ($result['headers'] as $name => $value) {
        if (!isset($ignoredResp[$name])) {
            header($name . ': ' . $value);
        }
    }
    header('profile-web-page-url: ' . currentUrl());
    applyConfigHeaderOverrides($config);
    applyHappFlags($config);

    // Декодируем основной ответ один раз; если есть WL — сливаем массивы
    // json_decode без true: объекты {} остаются stdClass, а не [], иначе json_encode
    // превратит пустые объекты в массивы, что сломает xray-core (stats:{}, tcpSettings:{})
    $decoded = json_decode($result['body']);

    $wlUrl    = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid . '_WL');
    $wlResult = apiGet($wlUrl, $forwardHeaders);
    if ($wlResult['code'] === 200) {
        $wlData = json_decode($wlResult['body']);
        if (is_array($decoded) && is_array($wlData)) {
            $decoded = array_merge($decoded, $wlData);
        }
    }

    $responseBody = $decoded !== null
        ? json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : $result['body'];

    header('Content-Type: application/json; charset=utf-8');
    echo $responseBody;
}

// Применяет переопределения заголовков из конфига (null = не менять, '' = удалить)
function applyConfigHeaderOverrides(array $config): void
{
    if ($config['profile_title'] !== null) {
        if ($config['profile_title'] === '') {
            header_remove('profile-title');
        } else {
            header('profile-title: base64:' . base64_encode($config['profile_title']));
        }
    }

    if ($config['support_url'] !== null) {
        if ($config['support_url'] === '') {
            header_remove('support-url');
        } else {
            header('support-url: ' . $config['support_url']);
        }
    }

    if ($config['content_disposition_name'] !== null) {
        if ($config['content_disposition_name'] === '') {
            header_remove('content-disposition');
        } else {
            header('content-disposition: attachment; filename=' . $config['content_disposition_name']);
        }
    }

    if ($config['announce'] !== null) {
        if ($config['announce'] === '') {
            header_remove('announce');
        } else {
            header('announce: base64:' . base64_encode($config['announce']));
        }
    }

    if ($config['profile_update_interval'] !== null) {
        header('profile-update-interval: ' . $config['profile_update_interval']);
    }
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
// Браузер — рендер панели пользователя
// ---------------------------------------------------------------------------

function serveBrowser(string $shortUuid, array $config): void
{
    $url = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid) . '/info';

    $sendHeaders = ['Accept: application/json', 'X-Forwarded-For: ' . clientIp()];
    if (!empty($config['api_token'])) {
        $sendHeaders[] = 'Authorization: Bearer ' . $config['api_token'];
    }

    $result = apiGet($url, $sendHeaders);

    $wlUser = null;
    $wlUrl  = rtrim($config['remnawave_url'], '/') . '/api/sub/' . rawurlencode($shortUuid . '_WL') . '/info';
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

        // Скрываем токен в debug-выводе
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

    // Симулируем applyConfigHeaderOverrides
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
