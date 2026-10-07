<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

$_displayErrors = !empty($config['display_errors']) ? '1' : '0';
ini_set('display_errors', $_displayErrors);
ini_set('display_startup_errors', $_displayErrors);
error_reporting(E_ALL);

require __DIR__ . '/functions.php';
try {
    applyResponseSecurityHeaders();
    $config = validateAndNormalizeConfig($config);
} catch (Throwable $error) {
    logOperationalFailure('configuration', 'bootstrap_validation', ['exception' => get_class($error)]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Internal Server Error';
    exit;
}
require __DIR__ . '/storage.php';
require __DIR__ . '/template.php';
require __DIR__ . '/happ.php';
require __DIR__ . '/browser.php';

define('VERSION',          '1.15.0');
define('SHOW_VERSION',     (bool) ($config['show_version'] ?? false));
define('TEMPLATE_DIR',     __DIR__ . '/templates/' . ($config['template'] ?? 'default'));
$_projectName = (string) ($config['project_name'] ?? '');
$_copyright   = (string) ($config['copyright']    ?? '');
$_copyright   = str_replace(['{project_name}', '{PROJECT_NAME}'], $_projectName, $_copyright);

define('PROJECT_NAME',     $_projectName);
define('SHOW_QR',          (bool) ($config['show_qr']          ?? false));
define('COPYRIGHT',        $_copyright);
define('ENCRYPT_SUB_LINK', (bool) ($config['encrypt_sub_link'] ?? false));
define('DEBUG_MODE',       !empty($config['debug_ip']) && clientIpMatchesDebugList($config['debug_ip']));
define('ALLOW_DELETE_HWID', (bool) ($config['allow_delete_hwid'] ?? false));
define('INSTALL_CLIENTS',   (array) ($config['install_clients']  ?? ['incy', 'happ']));
define('APCU_CACHE',        (bool) ($config['apcu_cache']        ?? true));
define('CACHE_TTL',         max(1, (int) ($config['cache_ttl']   ?? 60)));

$GLOBALS['__cache_namespace'] = buildCacheNamespace($config);
$GLOBALS['__storage_config'] = $config;
$GLOBALS['__hwid_delete_limits'] = normalizeDeletionLimits(
    is_array($config['hwid_delete_limits'] ?? null) ? $config['hwid_delete_limits'] : []
);
$GLOBALS['__storage_repository'] = createStorageRepository($config);

$_langCode = $config['lang'] ?? 'ru';
$_langFile = __DIR__ . '/lang/lang_' . $_langCode . '.php';
initLang(file_exists($_langFile) ? require $_langFile : require __DIR__ . '/lang/lang_ru.php');

$requestMethod = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '';
if ($requestMethod === 'POST' && ($_GET['action'] ?? '') === 'delete_hwid') {
    $shortUuid = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
    if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $shortUuid)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid ID']);
        exit;
    }

    if (!ALLOW_DELETE_HWID && !DEBUG_MODE) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }

    $csrfSecret = is_string($config['csrf_secret'] ?? null) ? trim($config['csrf_secret']) : '';
    $csrfToken  = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if (!verifyDeleteHwidCsrfToken($csrfToken, $shortUuid, $csrfSecret)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid or expired action token']);
        exit;
    }

    handleDeleteHwid($shortUuid, $config);
    exit;
}

$shortUuid = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';

if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $shortUuid)) {
    renderErrorPage(404, 'Not Found');
    exit;
}

// Route strict checker rules before general subscription-client detection.
$userAgent = is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : '';
$hwidValue = $_SERVER['HTTP_X_HWID'] ?? '';
$hwid      = is_string($hwidValue) ? trim($hwidValue) : '';
$isChecker = isCheckerRequest($config);
$isHapp    = isSubscriptionClientRequest($userAgent, $hwid);

if (DEBUG_MODE && isset($_GET['happ'])) {
    serveHappDebugView($shortUuid, $config);
} elseif ($isChecker) {
    $checkerHwid = is_string($config['debug_hwid'] ?? null) ? $config['debug_hwid'] : '';
    serveHapp($shortUuid, $config, $checkerHwid);
} elseif ($isHapp) {
    if ($hwid === '') {
        renderErrorPage(403, 'Forbidden');
        exit;
    }
    serveHapp($shortUuid, $config);
} else {
    serveBrowser($shortUuid, $config);
}
