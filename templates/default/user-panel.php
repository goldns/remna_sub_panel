<!DOCTYPE html>
<html lang="<?= htmlLang() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/svg+xml" href="<?= assetUrl('favicon.svg') ?>">
    <title><?= PROJECT_NAME !== '' ? htmlspecialchars(PROJECT_NAME) . ' · ' : '' ?><?= $username ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap">
    <link rel="stylesheet" href="<?= assetUrl('css/panel.css') ?>">
</head>
<body>
<div class="container">
    <?php if (PROJECT_NAME !== '' || SHOW_QR || $supportUrl !== ''): ?>
    <div class="site-header">
        <div class="site-title">
            <?= htmlspecialchars(PROJECT_NAME) ?>
            <?php if ($username !== ''): ?>
            <span class="site-username"> · <?= $username ?></span>
            <?php endif ?>
        </div>
        <div class="site-links">
            <?php if (SHOW_QR): ?>
            <button class="site-link-btn" id="qr-btn" title="QR-код" type="button">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                    <path d="M3 3h7v7H3V3zm2 2v3h3V5H5zm1 1h1v1H6V6zM3 14h7v7H3v-7zm2 2v3h3v-3H5zm1 1h1v1H6v-1zM14 3h7v7h-7V3zm2 2v3h3V5h-3zm1 1h1v1h-1V6zM14 14h2v2h-2v-2zm2 2h2v2h-2v-2zm2-2h2v2h-2v-2zm0 4h2v2h-2v-2zm-4 0h2v2h-2v-2zm2-2h2v2h-2v-2z"/>
                </svg>
            </button>
            <?php endif ?>
            <?php if ($supportUrl !== ''): ?>
            <a class="site-link-btn" href="<?= htmlspecialchars($supportUrl) ?>" target="_blank" rel="noopener noreferrer" title="Поддержка">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">
                    <path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/>
                </svg>
            </a>
            <?php endif ?>
        </div>
    </div>
    <?php endif ?>
    <div class="card">

        <div class="header">
            <div class="card-title"><?= t('panel', 'card_title') ?></div>
            <span class="badge <?= $statusCss ?>"><?= $statusText ?></span>
        </div>

        <div class="grid">
            <div class="item">
                <div class="label"><?= t('panel', 'traffic_used') ?></div>
                <div class="value"><?= $traffic ?></div>
            </div>
            <div class="item">
                <div class="label"><?= t('panel', 'traffic_limit') ?></div>
                <div class="value"><?= $limit ?></div>
            </div>
            <div class="item">
                <div class="label"><?= t('panel', 'expires_at') ?></div>
                <div class="value"><?= $expireStr ?></div>
            </div>
            <div class="item">
                <div class="label"><?= t('panel', 'days_left') ?></div>
                <div class="value"><?= $daysLabel ?></div>
            </div>
        </div>


        <?php if ($wl !== null): ?>
        <div class="wl-divider"></div>
        <div class="wl-header">
            <span class="wl-label"><?= t('wl', 'title') ?></span>
            <span class="badge <?= $wl['statusCss'] ?>"><?= $wl['statusText'] ?></span>
        </div>
        <div class="wl-grid">
            <div class="item">
                <div class="label"><?= t('wl', 'traffic_used') ?></div>
                <div class="value"><?= $wl['trafficUsed'] ?></div>
            </div>
            <div class="item">
                <div class="label"><?= t('wl', 'remaining') ?></div>
                <div class="value wl-remaining"><?= $wl['remaining'] ?></div>
            </div>
        </div>
        <?php endif ?>

    </div>
    <?php include __DIR__ . '/install-guide.php'; ?>

    <?php if ($hwidInfo !== null): ?>
    <div class="card guide-card">
        <button class="guide-toggle" onclick="hwidToggle()" aria-expanded="false" type="button">
            <span class="guide-title"><?= t('hwid', 'title') ?></span>
            <svg class="guide-chevron" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6l6 -6"/></svg>
        </button>
        <div class="guide-body" id="hwid-body">
            <div class="guide-body-inner">
                <div class="wl-grid hwid-summary-grid">
                    <div class="item hwid-item-count">
                        <div class="label"><?= t('hwid', 'count') ?></div>
                        <div class="value"><?= $hwidInfo['count'] ?></div>
                    </div>
                    <div class="item hwid-item-limit">
                        <div class="label"><?= t('hwid', 'limit') ?></div>
                        <div class="value"><?= $hwidInfo['limit'] !== null ? $hwidInfo['limit'] : t('hwid', 'unlimited') ?></div>
                    </div>
                </div>
                <?php if (!empty($hwidInfo['devices'])): ?>
                <div class="hwid-devices">
                    <?php
                    $sortedDevices = $hwidInfo['devices'];
                    usort($sortedDevices, fn($a, $b) =>
                        strtotime($b['updatedAt'] ?? '0') <=> strtotime($a['updatedAt'] ?? '0')
                    );
                    foreach ($sortedDevices as $device):
                        $platformRaw = trim($device['platform'] ?? '');
                        $platform    = strtolower($platformRaw);
                        $model       = $device['deviceModel'] ?? '';
                        $osVer       = $device['osVersion']   ?? '';
                        $updatedAt   = $device['updatedAt']   ?? '';
                        $lastSeen    = $updatedAt ? date('d.m.Y H:i', strtotime($updatedAt)) : '—';
                        $ago         = $updatedAt ? timeAgo($updatedAt) : '—';
                        $name        = $model !== '' ? $model : t('hwid', 'no_model');
                        $metaParts   = array_filter([$platformRaw, $osVer]);
                        $metaTop     = implode(' / ', $metaParts);
                        $metaBottom  = t('hwid', 'last_seen') . ': ' . $ago . ' (' . $lastSeen . ')';
                    ?>
                    <div class="hwid-device" data-hwid="<?= htmlspecialchars($device['hwid'] ?? '') ?>">
                        <div class="hwid-device-icon"><?= hwidPlatformIcon($platform) ?></div>
                        <div class="hwid-device-info">
                            <div class="hwid-device-name"><?= htmlspecialchars($name) ?></div>
                            <?php if ($metaTop !== ''): ?><div class="hwid-device-meta"><?= htmlspecialchars($metaTop) ?></div><?php endif ?>
                            <div class="hwid-device-meta hwid-device-seen"><?= htmlspecialchars($metaBottom) ?></div>
                        </div>
                        <?php if (ALLOW_DELETE_HWID || DEBUG_MODE): ?>
                        <button class="hwid-delete-btn" type="button" title="Удалить устройство" onclick="hwidDelete(this)">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                        <?php endif ?>
                    </div>
                    <?php endforeach ?>
                </div>
                <?php endif ?>
                <?php if (!empty($wlHwidInfo['devices'])): ?>
                <div class="hwid-wl-separator">
                    <span><?= t('wl', 'title') ?></span>
                </div>
                <div class="wl-grid hwid-summary-grid hwid-wl-summary-grid">
                    <div class="item hwid-item-count">
                        <div class="label"><?= t('hwid', 'count') ?></div>
                        <div class="value"><?= $wlHwidInfo['count'] ?></div>
                    </div>
                    <div class="item hwid-item-limit">
                        <div class="label"><?= t('hwid', 'limit') ?></div>
                        <div class="value"><?= $wlHwidInfo['limit'] !== null ? $wlHwidInfo['limit'] : t('hwid', 'unlimited') ?></div>
                    </div>
                </div>
                <div class="hwid-devices">
                    <?php
                    $wlSortedDevices = $wlHwidInfo['devices'];
                    usort($wlSortedDevices, fn($a, $b) =>
                        strtotime($b['updatedAt'] ?? '0') <=> strtotime($a['updatedAt'] ?? '0')
                    );
                    foreach ($wlSortedDevices as $device):
                        $platformRaw = trim($device['platform'] ?? '');
                        $platform    = strtolower($platformRaw);
                        $model       = $device['deviceModel'] ?? '';
                        $osVer       = $device['osVersion']   ?? '';
                        $updatedAt   = $device['updatedAt']   ?? '';
                        $lastSeen    = $updatedAt ? date('d.m.Y H:i', strtotime($updatedAt)) : '—';
                        $ago         = $updatedAt ? timeAgo($updatedAt) : '—';
                        $name        = $model !== '' ? $model : t('hwid', 'no_model');
                        $metaParts   = array_filter([$platformRaw, $osVer]);
                        $metaTop     = implode(' / ', $metaParts);
                        $metaBottom  = t('hwid', 'last_seen') . ': ' . $ago . ' (' . $lastSeen . ')';
                    ?>
                    <div class="hwid-device" data-hwid="<?= htmlspecialchars($device['hwid'] ?? '') ?>" data-wl="1">
                        <div class="hwid-device-icon"><?= hwidPlatformIcon($platform) ?></div>
                        <div class="hwid-device-info">
                            <div class="hwid-device-name"><?= htmlspecialchars($name) ?></div>
                            <?php if ($metaTop !== ''): ?><div class="hwid-device-meta"><?= htmlspecialchars($metaTop) ?></div><?php endif ?>
                            <div class="hwid-device-meta hwid-device-seen"><?= htmlspecialchars($metaBottom) ?></div>
                        </div>
                        <?php if (ALLOW_DELETE_HWID || DEBUG_MODE): ?>
                        <button class="hwid-delete-btn" type="button" title="Удалить устройство" onclick="hwidDelete(this)">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                        <?php endif ?>
                    </div>
                    <?php endforeach ?>
                </div>
                <?php endif ?>
            </div>
        </div>
    </div>
    <?php endif ?>

    <?php if (SHOW_VERSION || COPYRIGHT !== ''): ?>
    <div class="footer">
        <?php if (COPYRIGHT !== ''): ?>
        <span class="copyright"><?= htmlspecialchars(str_replace('{year}', date('Y'), COPYRIGHT)) ?></span>
        <?php endif ?>
        <?php if (SHOW_VERSION): ?>
        <span class="version"><?= ($GLOBALS['__cache_hit'] ?? false) ? '*' : '' ?>v<?= VERSION ?></span>
        <?php endif ?>
    </div>
    <?php endif ?>
</div>
<?php if ($debug !== null) include __DIR__ . '/debug-panel.php'; ?>
<script>
function hwidToggle() {
    var btn  = document.querySelector('[onclick="hwidToggle()"]');
    var body = document.getElementById('hwid-body');
    var open = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    body.classList.toggle('open', !open);
}
function hwidDelete(btn) {
    var card = btn.closest('[data-hwid]');
    var hwid = card ? card.getAttribute('data-hwid') : '';
    if (!hwid) return;
    btn.disabled = true;
    var fd = new FormData();
    fd.append('hwid', hwid);
    if (card.getAttribute('data-wl') === '1') fd.append('wl', '1');
    fetch(window.location.pathname + '?action=delete_hwid', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                card.style.transition = 'opacity .25s';
                card.style.opacity = '0';
                setTimeout(function() { card.remove(); }, 260);
            } else {
                btn.disabled = false;
                alert('Ошибка: ' + (d.error || 'неизвестно'));
            }
        })
        .catch(function() { btn.disabled = false; alert('Ошибка сети'); });
}
</script>
</body>
</html>
