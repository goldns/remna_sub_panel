<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// APCu-кэш (деградирует до no-op если APCu недоступен или APCU_CACHE=false)
// ---------------------------------------------------------------------------

function cacheGet(string $key): mixed
{
    if (!defined('APCU_CACHE') || !APCU_CACHE || !function_exists('apcu_fetch')) return null;
    $val = apcu_fetch($key, $ok);
    return $ok ? $val : null;
}

function cacheSet(string $key, mixed $value, int $ttl): void
{
    if (!defined('APCU_CACHE') || !APCU_CACHE || !function_exists('apcu_store') || $ttl <= 0) return;
    apcu_store($key, $value, $ttl);
}

function cacheDel(string $key): void
{
    if (function_exists('apcu_delete')) apcu_delete($key);
}

// GET-запрос с APCu-кэшем. Кэшируются только ответы со статусом 200.
// При cache hit устанавливает $GLOBALS['__cache_hit'] = true.
function cachedApiGet(string $cacheKey, string $url, array $headers, int $ttl): array
{
    $cached = cacheGet($cacheKey);
    if ($cached !== null) {
        $GLOBALS['__cache_hit'] = true;
        return $cached;
    }
    $result = apiGet($url, $headers);
    if ($result['code'] === 200) cacheSet($cacheKey, $result, $ttl);
    return $result;
}

// ---------------------------------------------------------------------------
// Проверяет, попадает ли IP клиента в список допустимых IP/CIDR-подсетей.
// $debugIp может быть строкой (один IP) или массивом (IP и/или CIDR-подсети).
function clientIpMatchesDebugList(array|string $debugIp): bool
{
    if (empty($debugIp)) return false;
    $entries  = is_array($debugIp) ? $debugIp : [$debugIp];
    $clientIp = clientIp();
    foreach ($entries as $entry) {
        $entry = trim((string) $entry);
        if ($entry === '') continue;
        if (str_contains($entry, '/')) {
            if (ipInCidr($clientIp, $entry)) return true;
        } elseif ($clientIp === $entry) {
            return true;
        }
    }
    return false;
}

// Проверяет, входит ли $ip в CIDR-подсеть (только IPv4).
function ipInCidr(string $ip, string $cidr): bool
{
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2) return false;
    [$subnet, $bits] = $parts;
    $bits       = max(0, min(32, (int) $bits));
    $ipLong     = ip2long($ip);
    $subnetLong = ip2long($subnet);
    if ($ipLong === false || $subnetLong === false) return false;
    $mask = $bits === 0 ? 0 : (~0 << (32 - $bits));
    return ($ipLong & $mask) === ($subnetLong & $mask);
}

// Проверяет, соответствует ли запрос одной из записей checkers в конфиге.
// Каждая запись — массив с необязательными ключами 'ip' (строка/массив IP или CIDR) и 'ua' (подстрока UA).
// Оба указанных условия должны совпасть (AND). Не указанное условие не проверяется.
function isCheckerRequest(array $config): bool
{
    $checkers = $config['checkers'] ?? [];
    if (empty($checkers)) return false;

    $clientIp  = clientIp();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    foreach ($checkers as $checker) {
        $ipOk = true;
        $uaOk = true;

        if (!empty($checker['ip'])) {
            $entries = is_array($checker['ip']) ? $checker['ip'] : [$checker['ip']];
            $ipOk = false;
            foreach ($entries as $entry) {
                $entry = trim((string) $entry);
                if ($entry === '') continue;
                if (str_contains($entry, '/')) {
                    if (ipInCidr($clientIp, $entry)) { $ipOk = true; break; }
                } elseif ($clientIp === $entry) {
                    $ipOk = true; break;
                }
            }
        }

        if (!empty($checker['ua'])) {
            $uaOk = str_contains($userAgent, $checker['ua']);
        }

        if ($ipOk && $uaOk) return true;
    }

    return false;
}

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
function apiGet(string $url, array $extraHeaders = [], int $timeout = 10): array
{
    $responseHeaders = [];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $extraHeaders,
        CURLOPT_TIMEOUT        => $timeout,
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

// POST-запрос к API Remnawave, возвращает код, заголовки, тело и время выполнения
function apiPost(string $url, string $payload, array $extraHeaders = []): array
{
    $responseHeaders = [];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $extraHeaders,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
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
