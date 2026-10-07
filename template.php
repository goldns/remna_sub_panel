<?php
declare(strict_types=1);

/**
 * @brief Builds a public asset URL relative to the current application directory.
 *
 * @param path Relative path below the assets directory.
 * @return Normalized application-relative asset URL, using the site root for malformed server metadata.
 */
function assetUrl(string $path): string
{
    $scriptName = is_string($_SERVER['SCRIPT_NAME'] ?? null) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
    $base = rtrim(dirname($scriptName), '/');
    return $base . '/assets/' . ltrim($path, '/');
}

/**
 * @brief Stores the validated translation catalog for the current request.
 * @param strings Nested translation groups loaded from the bundled locale file.
 */
function initLang(array $strings): void
{
    $GLOBALS['__lang'] = $strings;
}

/**
 * @brief Resolves one translation string without exposing undefined-index notices.
 * @param group Translation group name.
 * @param key Translation key within the group.
 * @return Configured translation or an empty string when absent.
 */
function t(string $group, string $key): string
{
    return $GLOBALS['__lang'][$group][$key] ?? '';
}

/**
 * @brief Returns the configured HTML language code.
 * @return Locale code, defaulting to ru when the catalog omits it.
 */
function htmlLang(): string
{
    return $GLOBALS['__lang']['html_lang'] ?? 'ru';
}

/**
 * @brief Returns one complete translation group for structured UI sections.
 * @param group Translation group name.
 * @return Group array or an empty array when absent.
 */
function langGroup(string $group): array
{
    return $GLOBALS['__lang'][$group] ?? [];
}

/**
 * @brief Renders a safe HTML error page using an optional redacted debug context.
 * @param code Public HTTP status code.
 * @param message User-facing error message.
 * @param debug Optional redacted diagnostic data.
 */
function renderErrorPage(int $code, string $message, ?array $debug = null): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    include TEMPLATE_DIR . '/error-page.php';
}

/**
 * @brief Builds a renewal URL from an explicit case-insensitive field allowlist and URL-encoded replacements.
 * @param template Trusted configured URL template using shortUuid, userId, or approved B64 placeholders.
 * @param shortUuid Validated subscription identifier from the public route.
 * @param user Normalized subscription user data from API or persistent cache.
 * @param userId Optional positive upstream user identifier represented as a string.
 * @return Expanded URL, or an empty string for unsupported legacy or B64 placeholders.
 */
function buildRenewUrl(string $template, string $shortUuid, array $user, string $userId = ''): string
{
    if (str_contains($template, '{uuid}')) {
        logOperationalFailure('configuration', 'unsupported_renew_placeholder', ['placeholder' => 'uuid']);
        return '';
    }

    $result = str_replace(
        ['{shortUuid}', '{userId}'],
        [rawurlencode($shortUuid), rawurlencode($userId)],
        $template
    );
    $invalid = false;
    $fieldMap = renewPlaceholderFieldMap();
    $expanded = preg_replace_callback('/\{B64:([A-Za-z_]+)\}/i', function (array $match) use ($user, $fieldMap, &$invalid): string {
        $field = $fieldMap[strtoupper($match[1])] ?? null;
        if ($field === null) {
            $invalid = true;
            return '';
        }
        $value = $user[$field] ?? '';
        if (!is_scalar($value) && $value !== null) {
            $invalid = true;
            return '';
        }
        return rawurlencode(base64_encode((string) $value));
    }, $result);
    return $invalid || !is_string($expanded) ? '' : $expanded;
}

/**
 * @brief Accepts only explicitly supported external-link schemes for browser-panel anchors.
 *
 * @param url Candidate URL from trusted configuration or an upstream response header.
 * @return The unchanged URL for http, https, or tg schemes; otherwise an empty string.
 */
function sanitizeExternalUrl(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) return '';

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https', 'tg'], true) ? $url : '';
}

/**
 * @brief Prepares escaped subscription data and renders the browser-facing user panel.
 *
 * Unknown status values are escaped, external links are restricted to approved schemes, and the HWID delete
 * controls receive a short-lived token only when CSRF signing is configured.
 *
 * @param user Main subscription user data returned by the info endpoint.
 * @param debug Optional already-redacted diagnostic data for authorized debug clients.
 * @param wlUser Optional active WL user data for the secondary status card.
 * @param hwidInfo Optional main-account HWID summary and device list.
 * @param supportUrl Optional support URL.
 * @param wlHwidInfo Optional WL-account HWID summary and device list.
 * @param checkerProxies Optional normalized proxy-health data.
 * @param renewUrl Optional renewal URL for the primary button.
 * @param renewUrlTg Optional Telegram renewal URL.
 * @param csrfToken Signed HWID deletion token, or an empty string when deletion must remain disabled.
 * @param hwidDeleteQuota Optional rolling deletion quota for the main API username.
 * @param wlHwidDeleteQuota Optional rolling deletion quota for the WL API username.
 */
function renderUserPanel(array $user, ?array $debug = null, ?array $wlUser = null, ?array $hwidInfo = null, string $supportUrl = '', ?array $wlHwidInfo = null, ?array $checkerProxies = null, string $renewUrl = '', string $renewUrlTg = '', string $csrfToken = '', ?array $hwidDeleteQuota = null, ?array $wlHwidDeleteQuota = null): void
{
    header('Content-Type: text/html; charset=utf-8');

    $usernameValue = is_string($user['username'] ?? null) ? $user['username'] : '—';
    $username   = htmlspecialchars($usernameValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $status     = is_string($user['userStatus'] ?? null) ? strtoupper($user['userStatus']) : 'UNKNOWN';
    $daysLeftValue = $user['daysLeft'] ?? 0;
    $daysLeft   = is_numeric($daysLeftValue) ? (int) $daysLeftValue : 0;
    $expiresAt  = is_string($user['expiresAt'] ?? null) ? $user['expiresAt'] : null;
    $trafficValue = is_string($user['trafficUsed'] ?? null) ? $user['trafficUsed'] : '—';
    $traffic    = htmlspecialchars($trafficValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $limitBytesValue = $user['trafficLimitBytes'] ?? 0;
    $limitBytes = is_numeric($limitBytesValue) ? (float) $limitBytesValue : 0.0;
    $limitValue = is_string($user['trafficLimit'] ?? null) ? $user['trafficLimit'] : '—';
    $limit      = $limitBytes == 0
        ? t('panel', 'unlimited')
        : htmlspecialchars($limitValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    [$statusText, $statusCss] = match($status) {
        'ACTIVE'   => [t('status', 'ACTIVE'),   'status-active'],
        'DISABLED' => [t('status', 'DISABLED'), 'status-disabled'],
        'LIMITED'  => [t('status', 'LIMITED'),  'status-limited'],
        'EXPIRED'  => [t('status', 'EXPIRED'),  'status-expired'],
        default    => [htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'status-unknown'],
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
        $wlStatus = is_string($wlUser['userStatus'] ?? null) ? strtoupper($wlUser['userStatus']) : 'UNKNOWN';
        [$wlStatusText, $wlStatusCss] = match($wlStatus) {
            'ACTIVE'   => [t('status', 'ACTIVE'),   'status-active'],
            'DISABLED' => [t('status', 'DISABLED'), 'status-disabled'],
            'LIMITED'  => [t('status', 'LIMITED'),  'status-limited'],
            'EXPIRED'  => [t('status', 'EXPIRED'),  'status-expired'],
            default    => [htmlspecialchars($wlStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'status-unknown'],
        };
        $wlUsedValue  = $wlUser['trafficUsedBytes'] ?? 0;
        $wlLimitValue = $wlUser['trafficLimitBytes'] ?? 0;
        $wlUsedBytes  = is_numeric($wlUsedValue) ? (float) $wlUsedValue : 0.0;
        $wlLimitBytes = is_numeric($wlLimitValue) ? (float) $wlLimitValue : 0.0;
        $wlRemaining  = $wlLimitBytes > 0
            ? formatBytes((int) max(0, $wlLimitBytes - $wlUsedBytes))
            : t('panel', 'unlimited');
        $wl = [
            'statusText'  => $wlStatusText,
            'statusCss'   => $wlStatusCss,
            'trafficUsed' => htmlspecialchars(
                is_string($wlUser['trafficUsed'] ?? null) ? $wlUser['trafficUsed'] : '—',
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ),
            'remaining'   => $wlRemaining,
        ];
    }

    $supportUrl = sanitizeExternalUrl($supportUrl);
    $renewUrl   = sanitizeExternalUrl($renewUrl);
    $renewUrlTg = sanitizeExternalUrl($renewUrlTg);

    include TEMPLATE_DIR . '/user-panel.php';
}

/**
 * @brief Renders the authorized Happ diagnostic view from redacted data.
 * @param data Redacted diagnostic model prepared by the shared Happ flow.
 */
function renderHappDebug(array $data): void
{
    header('Content-Type: text/html; charset=utf-8');
    include TEMPLATE_DIR . '/happ-debug.php';
}

/**
 * @brief Resolves a localized load-balancing strategy label.
 * @param strategy Strategy key returned by the checker.
 * @return Localized label or an escaped original key.
 */
function strategyLabel(string $strategy): string
{
    $label = t('strategy', $strategy);
    return $label !== '' ? $label : htmlspecialchars($strategy);
}

/**
 * @brief Formats a past date as a concise Russian relative interval.
 * @param datetime Date-time value accepted by strtotime().
 * @return Relative interval or an em dash for an invalid value.
 */
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

/**
 * @brief Selects the correct Russian plural form for an integer.
 * @param n Value whose grammatical form is required.
 * @param one Singular form.
 * @param few Paucal form.
 * @param many Plural form.
 * @return Selected word form.
 */
function plural(int $n, string $one, string $few, string $many): string
{
    $n  = abs($n) % 100;
    $n1 = $n % 10;
    if ($n >= 11 && $n <= 19)  return $many;
    if ($n1 === 1)             return $one;
    if ($n1 >= 2 && $n1 <= 4) return $few;
    return $many;
}

/**
 * @brief Returns a built-in platform icon without external asset requests.
 * @param platform Lowercase platform description.
 * @return Trusted inline SVG markup for the detected or generic device type.
 */
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

/**
 * @brief Converts an ISO 3166-1 alpha-2 country code to its Unicode flag sequence.
 * @param code Two ASCII letters; case is ignored.
 * @return Flag emoji for a valid code, or an empty string for invalid input.
 */
function countryCodeFlagEmoji(string $code): string
{
    $code = strtoupper(trim($code));
    if (preg_match('/^[A-Z]{2}$/D', $code) !== 1) return '';

    return mb_chr(0x1F1E6 + ord($code[0]) - 65, 'UTF-8')
        . mb_chr(0x1F1E6 + ord($code[1]) - 65, 'UTF-8');
}

/**
 * @brief Formats confirmed device metadata as escaped HTML with emphasized platform and application names.
 * @param userAgent Device user agent returned by the upstream API.
 * @param platform Device platform returned by the upstream API.
 * @param osVersion Optional operating-system version returned by the upstream API.
 * @return Safe HTML fragments separated by slashes, or an empty string when no metadata exists.
 */
function formatDeviceAgent(string $userAgent, string $platform, string $osVersion = ''): string
{
    $ua    = trim($userAgent);
    $parts = $ua !== ''
        ? array_values(array_filter(array_map('trim', explode('/', $ua)), fn($p) => $p !== ''))
        : [];

    $app = $parts[0] ?? '';
    // Treat the second user-agent segment as a version only when it starts with a digit.
    $ver = (isset($parts[1]) && preg_match('/^[0-9][0-9.]*$/', $parts[1])) ? $parts[1] : '';

    $segs = [];
    if ($platform !== '')         $segs[] = '<b>' . htmlspecialchars($platform) . '</b>';
    if ($app !== '')              $segs[] = '<b>' . htmlspecialchars(strtoupper($app)) . '</b>';
    if ($ver !== '')              $segs[] = htmlspecialchars($ver);
    if (trim($osVersion) !== '')  $segs[] = htmlspecialchars(trim($osVersion));

    return implode(' / ', $segs);
}

/**
 * @brief Formats a non-negative byte count using binary units.
 * @param bytes Byte count; non-positive values are displayed as zero.
 * @return Human-readable value from B through TB.
 */
function formatBytes(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
}

/**
 * @brief Requests an encrypted Happ import link with a bounded response and a deterministic plain-link fallback.
 * @param url Subscription URL to encrypt for the installation button.
 * @return Valid happ scheme returned by the service, or the plain happ import fallback.
 */
function encryptSubLink(string $url): string
{
    $fallback = 'happ://add/' . $url;
    $apiUrl   = 'https://crypto.happ.su/api-v2.php';
    $payload = json_encode(['url' => $url], JSON_UNESCAPED_SLASHES);
    $response = $payload === false
        ? ['code' => 0, 'body' => '', 'ms' => 0, 'error' => 'JSON encoding failed']
        : apiPost(
            $apiUrl,
            $payload,
            ['Content-Type: application/json'],
            5,
            responseBodyLimit('external'),
            'encrypt_subscription_link'
        );
    $body = is_string($response['body'] ?? null) ? $response['body'] : '';
    $code = is_numeric($response['code'] ?? null) ? (int) $response['code'] : 0;
    $ms   = is_numeric($response['ms'] ?? null) ? (int) $response['ms'] : 0;
    $err  = is_string($response['error'] ?? null) ? $response['error'] : '';

    $link         = $fallback;
    $usedFallback = true;

    if ($code === 200 && $body !== '') {
        $json      = json_decode($body, true);
        $candidateValue = is_array($json)
            ? ($json['encrypted_link'] ?? $json['url'] ?? $json['link'] ?? $json['encrypted'] ?? '')
            : trim((string) $body);
        $candidate = is_string($candidateValue) ? $candidateValue : '';

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
        'raw_body'     => $body,
        'result'       => $link,
        'used_fallback'=> $usedFallback,
    ];

    return $link;
}
