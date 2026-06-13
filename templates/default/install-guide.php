<?php
$guide      = langGroup('install');
$clientsAll = $guide['clients'] ?? [];

// Список и порядок клиентов задаётся в config.php (ключ install_clients → константа INSTALL_CLIENTS).
// Первый в списке — по умолчанию. Если доступен один клиент — переключатель скрыт.
$allowed = (defined('INSTALL_CLIENTS') && !empty(INSTALL_CLIENTS)) ? INSTALL_CLIENTS : array_keys($clientsAll);
$clients = [];
foreach ($allowed as $cid) {
    $cid = strtolower(trim((string) $cid));
    if (isset($clientsAll[$cid])) $clients[$cid] = $clientsAll[$cid];
}
if (empty($clients)) $clients = $clientsAll;

// Ссылка «Добавить подписку» зависит от клиента.
// happ — зашифрованный happ:// (или fallback), incy — incy://import/{plain url}.
$happSubLink = $GLOBALS['__sub_link'] ?? ('happ://add/' . currentUrl());
$subLinkFor  = [
    'happ' => $happSubLink,
    'incy' => 'incy://import/' . currentUrl(),
];

$multiClient = count($clients) > 1;
$firstClient = (string) array_key_first($clients);

$icons = [
    'download' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 11l5 5l5 -5"/><path d="M12 4l0 12"/></svg>',
    'cloud'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M19 18a3.5 3.5 0 0 0 0 -7h-1a5 4.5 0 0 0 -11 -2a4.6 4.4 0 0 0 -2.1 8.4"/><path d="M12 13l0 9"/><path d="M9 19l3 3l3 -3"/></svg>',
    'check'    => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10"/></svg>',
    'gear'     => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z"/><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0"/></svg>',
    'plus'     => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5v14"/><path d="M5 12h14"/></svg>',
    'ext'      => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6"/><path d="M11 13l9 -9"/><path d="M15 4h5v5"/></svg>',
];

$iconColor = [
    'download' => 'icon-cyan',
    'cloud'    => 'icon-cyan',
    'check'    => 'icon-teal',
    'gear'     => 'icon-cyan',
];
?>

<div class="card guide-card">
    <button class="guide-toggle" onclick="guideToggle()" aria-expanded="false" aria-controls="guide-body">
        <span class="guide-title"><?= htmlspecialchars($guide['title'] ?? '') ?></span>
        <svg class="guide-chevron" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6l6 -6"/></svg>
    </button>

    <div class="guide-body" id="guide-body"><div class="guide-body-inner">

        <?php if ($multiClient): ?>
        <div class="guide-client-tabs" role="tablist">
            <?php foreach ($clients as $cid => $client): ?>
            <button type="button" class="guide-client-tab<?= $cid === $firstClient ? ' active' : '' ?>"
                    data-client="<?= htmlspecialchars($cid) ?>"
                    onclick="guideClientSwitch('<?= htmlspecialchars($cid) ?>')">
                <?= htmlspecialchars($client['label'] ?? $cid) ?>
            </button>
            <?php endforeach ?>
        </div>
        <?php endif ?>

        <?php foreach ($clients as $cid => $client): ?>
        <div class="guide-client<?= $cid === $firstClient ? ' active' : '' ?>" id="gc-<?= htmlspecialchars($cid) ?>">
            <div class="guide-platform-row">
                <select class="guide-select" onchange="guidePlatformSwitch('<?= htmlspecialchars($cid) ?>', this.value)">
                    <?php foreach (($client['platforms'] ?? []) as $pid => $platform): ?>
                    <option value="<?= htmlspecialchars($pid) ?>"><?= htmlspecialchars($platform['label']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>

            <?php foreach (($client['platforms'] ?? []) as $pid => $platform): ?>
            <div class="guide-platform" id="gp-<?= htmlspecialchars($cid) ?>-<?= htmlspecialchars($pid) ?>">
                <?php foreach ($platform['steps'] as $step): ?>
                <div class="guide-step">
                    <div class="guide-step-icon <?= $iconColor[$step['icon']] ?? 'icon-cyan' ?>">
                        <?= $icons[$step['icon']] ?? '' ?>
                    </div>
                    <div class="guide-step-body">
                        <div class="guide-step-title"><?= htmlspecialchars($step['title']) ?></div>
                        <div class="guide-step-desc"><?= htmlspecialchars($step['desc']) ?></div>
                        <?php if (!empty($step['btns'])): ?>
                        <div class="guide-step-btns">
                            <?php foreach ($step['btns'] as $btn): ?>
                                <?php if ($btn['type'] === 'sub'): $subLink = $subLinkFor[$cid] ?? $happSubLink; ?>
                                <a href="<?= htmlspecialchars($subLink) ?>" class="guide-btn guide-btn-sub">
                                    <?= $icons['plus'] ?> <?= htmlspecialchars($btn['text']) ?>
                                </a>
                                <?php else: ?>
                                <a href="<?= htmlspecialchars($btn['href']) ?>" target="_blank" rel="noopener noreferrer" class="guide-btn guide-btn-ext">
                                    <?= $icons['ext'] ?> <?= htmlspecialchars($btn['text']) ?>
                                </a>
                                <?php endif ?>
                            <?php endforeach ?>
                        </div>
                        <?php endif ?>
                    </div>
                </div>
                <?php endforeach ?>
            </div>
            <?php endforeach ?>
        </div>
        <?php endforeach ?>

    </div></div>
</div>

<script>
(function () {
    function guideToggle() {
        var btn  = document.querySelector('.guide-toggle');
        var body = document.getElementById('guide-body');
        var open = btn.getAttribute('aria-expanded') === 'true';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        body.classList.toggle('open', !open);
    }

    function guideClientSwitch(cid) {
        document.querySelectorAll('.guide-client').forEach(function (el) {
            el.classList.toggle('active', el.id === 'gc-' + cid);
        });
        document.querySelectorAll('.guide-client-tab').forEach(function (el) {
            el.classList.toggle('active', el.getAttribute('data-client') === cid);
        });
    }

    function guidePlatformSwitch(cid, pid) {
        var scope = document.getElementById('gc-' + cid);
        if (!scope) return;
        scope.querySelectorAll('.guide-platform').forEach(function (el) {
            el.classList.remove('active');
        });
        var target = document.getElementById('gp-' + cid + '-' + pid);
        if (target) target.classList.add('active');
        var sel = scope.querySelector('.guide-select');
        if (sel) sel.value = pid;
    }

    window.guideToggle        = guideToggle;
    window.guideClientSwitch  = guideClientSwitch;
    window.guidePlatformSwitch = guidePlatformSwitch;

    function detectPlatform() {
        var ua = navigator.userAgent;
        if (/Android/i.test(ua))              return 'android';
        if (/iPhone|iPad|iPod/i.test(ua))     return 'ios';
        if (/Windows/i.test(ua))              return 'windows';
        if (/Mac/i.test(ua))                  return 'macos';
        if (/Linux/i.test(ua))                return 'linux';
        return 'windows';
    }

    document.addEventListener('DOMContentLoaded', function () {
        var detected = detectPlatform();
        document.querySelectorAll('.guide-client').forEach(function (scope) {
            var cid = scope.id.replace(/^gc-/, '');
            // Если у клиента есть определённая платформа — выбираем её, иначе первую из списка.
            var pid = document.getElementById('gp-' + cid + '-' + detected) ? detected : null;
            if (!pid) {
                var firstOpt = scope.querySelector('.guide-select option');
                pid = firstOpt ? firstOpt.value : null;
            }
            if (pid) guidePlatformSwitch(cid, pid);
        });
    });
})();
</script>
