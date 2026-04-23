<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

$_displayErrors = !empty($config['display_errors']) ? '1' : '0';
ini_set('display_errors', $_displayErrors);
ini_set('display_startup_errors', $_displayErrors);
error_reporting(!empty($config['display_errors']) ? E_ALL : 0);

require __DIR__ . '/functions.php';
require __DIR__ . '/template.php';
require __DIR__ . '/happ.php';
require __DIR__ . '/browser.php';

define('VERSION',          '1.5.0');
define('SHOW_VERSION',     (bool) ($config['show_version'] ?? false));
define('TEMPLATE_DIR',     __DIR__ . '/templates/' . ($config['template'] ?? 'default'));
define('PROJECT_NAME',     $config['project_name']     ?? '');
define('SHOW_QR',          (bool) ($config['show_qr']          ?? false));
define('COPYRIGHT',        $config['copyright']        ?? '');
define('ENCRYPT_SUB_LINK', (bool) ($config['encrypt_sub_link'] ?? true));
define('DEBUG_MODE',       !empty($config['debug_ip']) && clientIpMatchesDebugList($config['debug_ip']));
define('ALLOW_DELETE_HWID', (bool) ($config['allow_delete_hwid'] ?? false));
define('APCU_CACHE',        (bool) ($config['apcu_cache']        ?? true));
define('CACHE_TTL',         max(1, (int) ($config['cache_ttl']   ?? 60)));

$_langCode = $config['lang'] ?? 'ru';
$_langFile = __DIR__ . '/lang/lang_' . $_langCode . '.php';
initLang(file_exists($_langFile) ? require $_langFile : require __DIR__ . '/lang/lang_ru.php');

// ---------------------------------------------------------------------------
// Извлекаем shortUuid из строки запроса (подставляется .htaccess rewrite)
// ---------------------------------------------------------------------------
$shortUuid = $_GET['id'] ?? '';

if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $shortUuid)) {
    renderErrorPage(404, 'Not Found');
    exit;
}

// ---------------------------------------------------------------------------
// AJAX: удаление HWID-устройства (POST ?action=delete_hwid)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete_hwid') {
    if (!ALLOW_DELETE_HWID && !DEBUG_MODE) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    handleDeleteHwid($shortUuid, $config);
    exit;
}

// ---------------------------------------------------------------------------
// Определяем тип клиента и направляем запрос
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
