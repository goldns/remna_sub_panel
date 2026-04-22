<?php
declare(strict_types=1);

// Публичный URL ассета относительно /assets/ (работает в подпапке)
function assetUrl(string $path): string
{
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $base . '/assets/' . ltrim($path, '/');
}

// Загружает массив строк перевода в глобальное хранилище
function initLang(array $strings): void
{
    $GLOBALS['__lang'] = $strings;
}

// Возвращает строку перевода по группе и ключу
function t(string $group, string $key): string
{
    return $GLOBALS['__lang'][$group][$key] ?? '';
}

// Значение html lang для тега <html>
function htmlLang(): string
{
    return $GLOBALS['__lang']['html_lang'] ?? 'ru';
}

// Возвращает группу строк целиком (например, для инструкции по установке)
function langGroup(string $group): array
{
    return $GLOBALS['__lang'][$group] ?? [];
}

function renderErrorPage(int $code, string $message, ?array $debug = null): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    include __DIR__ . '/templates/error-page.php';
}

function renderUserPanel(array $user, ?array $debug = null, ?array $wlUser = null, ?array $hwidInfo = null, string $supportUrl = ''): void
{
    header('Content-Type: text/html; charset=utf-8');

    $username   = htmlspecialchars($user['username'] ?? '—');
    $status     = $user['userStatus'] ?? 'UNKNOWN';
    $daysLeft   = (int) ($user['daysLeft'] ?? 0);
    $expiresAt  = $user['expiresAt'] ?? null;
    $traffic    = htmlspecialchars($user['trafficUsed'] ?? '—');
    $limitBytes = (float) ($user['trafficLimitBytes'] ?? 0);
    $limit      = $limitBytes == 0 ? t('panel', 'unlimited') : htmlspecialchars($user['trafficLimit'] ?? '—');

    [$statusText, $statusCss] = match($status) {
        'ACTIVE'   => [t('status', 'ACTIVE'),   'status-active'],
        'DISABLED' => [t('status', 'DISABLED'), 'status-disabled'],
        'LIMITED'  => [t('status', 'LIMITED'),  'status-limited'],
        'EXPIRED'  => [t('status', 'EXPIRED'),  'status-expired'],
        default    => [$status,                 'status-unknown'],
    };

    $expireStr = '—';
    if ($expiresAt) {
        $ts = strtotime($expiresAt);
        $expireStr = $ts ? date('d.m.Y H:i', $ts) : '—';
    }

    $daysLabel = $daysLeft > 0
        ? $daysLeft . t('panel', 'days_suffix')
        : ($daysLeft === 0 ? t('panel', 'today') : t('panel', 'expired_days'));

    $wl = null;
    if ($wlUser !== null) {
        $wlStatus = $wlUser['userStatus'] ?? 'UNKNOWN';
        [$wlStatusText, $wlStatusCss] = match($wlStatus) {
            'ACTIVE'   => [t('status', 'ACTIVE'),   'status-active'],
            'DISABLED' => [t('status', 'DISABLED'), 'status-disabled'],
            'LIMITED'  => [t('status', 'LIMITED'),  'status-limited'],
            'EXPIRED'  => [t('status', 'EXPIRED'),  'status-expired'],
            default    => [$wlStatus,               'status-unknown'],
        };
        $wlUsedBytes  = (float) ($wlUser['trafficUsedBytes']  ?? 0);
        $wlLimitBytes = (float) ($wlUser['trafficLimitBytes'] ?? 0);
        $wlRemaining  = $wlLimitBytes > 0
            ? formatBytes((int) max(0, $wlLimitBytes - $wlUsedBytes))
            : t('panel', 'unlimited');
        $wl = [
            'statusText'  => $wlStatusText,
            'statusCss'   => $wlStatusCss,
            'trafficUsed' => htmlspecialchars($wlUser['trafficUsed'] ?? '—'),
            'remaining'   => $wlRemaining,
        ];
    }

    include __DIR__ . '/templates/user-panel.php';
}

function renderHappDebug(array $data): void
{
    header('Content-Type: text/html; charset=utf-8');
    include __DIR__ . '/templates/happ-debug.php';
}

function strategyLabel(string $strategy): string
{
    $label = t('strategy', $strategy);
    return $label !== '' ? $label : htmlspecialchars($strategy);
}

function timeAgo(string $datetime): string
{
    $ts   = strtotime($datetime);
    if (!$ts) return '—';
    $diff = max(0, time() - $ts);

    if ($diff < 60)          return 'только что';
    if ($diff < 3600)        { $m  = (int)($diff / 60);           return $m  . ' ' . plural($m,  'минуту', 'минуты', 'минут')   . ' назад'; }
    if ($diff < 86400)       { $h  = (int)($diff / 3600);         return $h  . ' ' . plural($h,  'час',    'часа',  'часов')    . ' назад'; }
    if ($diff < 86400 * 30)  { $d  = (int)($diff / 86400);        return $d  . ' ' . plural($d,  'день',   'дня',   'дней')     . ' назад'; }
    if ($diff < 86400 * 365) { $mo = (int)($diff / (86400 * 30)); return $mo . ' ' . plural($mo, 'месяц',  'месяца','месяцев')  . ' назад'; }
    $y = (int)($diff / (86400 * 365));
    return $y . ' ' . plural($y, 'год', 'года', 'лет') . ' назад';
}

function plural(int $n, string $one, string $few, string $many): string
{
    $n  = abs($n) % 100;
    $n1 = $n % 10;
    if ($n >= 11 && $n <= 19)  return $many;
    if ($n1 === 1)             return $one;
    if ($n1 >= 2 && $n1 <= 4) return $few;
    return $many;
}

function hwidPlatformIcon(string $platform): string
{
    return match(true) {
        str_contains($platform, 'android') =>
            '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M17.523 15.341A6.506 6.506 0 0 0 18.5 12a6.506 6.506 0 0 0-.977-3.341l1.203-1.204a.75.75 0 0 0-1.06-1.06L16.46 7.6A6.474 6.474 0 0 0 12 5.5a6.474 6.474 0 0 0-4.46 1.6L6.334 5.895a.75.75 0 0 0-1.06 1.06l1.203 1.204A6.506 6.506 0 0 0 5.5 12a6.506 6.506 0 0 0 .977 3.341l-1.203 1.203a.75.75 0 1 0 1.06 1.061L7.54 16.4A6.474 6.474 0 0 0 12 18.5a6.474 6.474 0 0 0 4.46-1.6l1.206 1.205a.75.75 0 0 0 1.06-1.06zM10 10a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm4 0a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/></svg>',
        str_contains($platform, 'ios') || str_contains($platform, 'iphone') || str_contains($platform, 'ipad') =>
            '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11"/></svg>',
        str_contains($platform, 'windows') =>
            '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M3 12V6.75l6-1.32v6.57H3zm17 0v-7l-9 1.68V12h9zM3 13h6v6.08l-6-1.2V13zm17 0h-9v6.9l9-1.68V13z"/></svg>',
        str_contains($platform, 'mac') || str_contains($platform, 'darwin') =>
            '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M18.71 19.5c-.83 1.24-1.71 2.45-3.05 2.47-1.34.03-1.77-.79-3.29-.79-1.53 0-2 .77-3.27.82-1.31.05-2.3-1.32-3.14-2.53C4.25 17 2.94 12.45 4.7 9.39c.87-1.52 2.43-2.48 4.12-2.51 1.28-.02 2.5.87 3.29.87.78 0 2.26-1.07 3.8-.91.65.03 2.47.26 3.64 1.98-.09.06-2.17 1.28-2.15 3.81.03 3.02 2.65 4.03 2.68 4.04-.03.07-.42 1.44-1.38 2.83M13 3.5c.73-.83 1.94-1.46 2.94-1.5.13 1.17-.34 2.35-1.04 3.19-.69.85-1.83 1.51-2.95 1.42-.15-1.15.41-2.35 1.05-3.11"/></svg>',
        str_contains($platform, 'linux') =>
            '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12.504 0c-.155 0-.315.008-.480.021C7.576.328 3.763 3.602 2.62 7.956a9.424 9.424 0 0 0-.258 2.19c0 3.861 2.057 7.301 5.175 9.188-.568.935-.894 1.98-.894 3.043v1.19c0 .221.18.4.4.4h10.02c.22 0 .4-.179.4-.4v-1.19c0-1.068-.329-2.112-.9-3.05C19.675 17.44 21.727 14 21.727 10.147c0-.757-.096-1.51-.28-2.244C20.304 3.6 16.502.327 12.504 0zm0 1.19c3.602.27 6.87 3.13 7.885 6.832.159.63.24 1.272.24 1.924 0 3.35-1.89 6.38-4.785 7.998l-.405.23.264.374c.614.87.962 1.873.962 2.913v.79H7.044v-.79c0-1.045.35-2.052.966-2.92l.265-.375-.406-.228C4.972 16.53 3.082 13.5 3.082 10.147c0-.648.08-1.286.236-1.908C4.312 4.337 7.586 1.48 11.166 1.19z"/></svg>',
        default =>
            '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/></svg>',
    };
}

function formatBytes(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
}

// Шифрует ссылку подписки через crypto.happ.su; при ошибке возвращает plain happ://add/{url}
function encryptSubLink(string $url): string
{
    $fallback = 'happ://add/' . $url;
    $apiUrl   = 'https://crypto.happ.su/api-v2.php';

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['url' => $url]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $t0   = microtime(true);
    $body = curl_exec($ch);
    $ms   = (int) round((microtime(true) - $t0) * 1000);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $link         = $fallback;
    $usedFallback = true;

    if ($code === 200 && $body) {
        $json      = json_decode($body, true);
        $candidate = is_array($json)
            ? ($json['encrypted_link'] ?? $json['url'] ?? $json['link'] ?? $json['encrypted'] ?? '')
            : trim((string) $body);

        if (str_starts_with($candidate, 'happ://')) {
            $link         = $candidate;
            $usedFallback = false;
        }
    }

    $GLOBALS['__encrypt_debug'] = [
        'api_url'      => $apiUrl,
        'input_url'    => $url,
        'code'         => $code,
        'ms'           => $ms,
        'curl_error'   => $err,
        'raw_body'     => (string) $body,
        'result'       => $link,
        'used_fallback'=> $usedFallback,
    ];

    return $link;
}
