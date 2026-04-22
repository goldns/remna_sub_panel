<?php
declare(strict_types=1);

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
