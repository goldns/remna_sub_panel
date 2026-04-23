<?php
$guide     = langGroup('install');
$subLink   = $GLOBALS['__sub_link'] ?? ('happ://add/' . currentUrl());
$platforms = $guide['platforms'] ?? [];

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
        <div class="guide-platform-row">
            <select class="guide-select" onchange="guideSwitch(this.value)">
                <?php foreach ($platforms as $pid => $platform): ?>
                <option value="<?= htmlspecialchars($pid) ?>"><?= htmlspecialchars($platform['label']) ?></option>
                <?php endforeach ?>
            </select>
        </div>

        <?php foreach ($platforms as $pid => $platform): ?>
        <div class="guide-platform" id="guide-<?= htmlspecialchars($pid) ?>">
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
                            <?php if ($btn['type'] === 'sub'): ?>
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

    function guideSwitch(pid) {
        document.querySelectorAll('.guide-platform').forEach(function (el) {
            el.classList.remove('active');
        });
        var target = document.getElementById('guide-' + pid);
        if (target) target.classList.add('active');
        var sel = document.querySelector('.guide-select');
        if (sel) sel.value = pid;
    }

    window.guideToggle = guideToggle;
    window.guideSwitch = guideSwitch;

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
        guideSwitch(detectPlatform());
    });
})();
</script>
