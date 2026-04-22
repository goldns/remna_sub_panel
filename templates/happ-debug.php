<!DOCTYPE html>
<html lang="<?= htmlLang() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Happ Debug</title>
    <link rel="stylesheet" href="<?= assetUrl('css/panel.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/debug.css') ?>">
    <style>
        body { align-items: flex-start; padding: 24px 16px }
        .container { max-width: 860px }
        .hd-card {
            background: #1a1d27; border: 1px solid #2d3148;
            border-radius: 14px; padding: 20px; margin-bottom: 16px;
        }
        .hd-title {
            font-size: .7rem; text-transform: uppercase;
            letter-spacing: .08em; color: #6366f1; margin-bottom: 14px;
        }
        .hd-title.wl { color: #22d3ee }
        .hd-meta {
            display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 14px;
        }
        .hd-meta-item { font-size: .8rem }
        .hd-meta-item span { color: #64748b; margin-right: 4px }
        .hd-tabs { display: flex; gap: 2px; margin-bottom: 12px; flex-wrap: wrap }
        .hd-tab {
            padding: 5px 14px; font-size: .72rem; cursor: pointer;
            border-radius: 6px; color: #64748b;
            background: #111320; border: 1px solid #1e293b;
            transition: all .15s;
        }
        .hd-tab:hover { color: #94a3b8; background: #161b2e }
        .hd-tab.active { color: #e2e8f0; background: #1e293b; border-color: #6366f1 }
        .hd-pane { display: none }
        .hd-pane.active { display: block }
        .hd-not-found { font-size: .85rem; color: #64748b; padding: 8px 0 }
    </style>
</head>
<body>
<div class="container">

    <!-- Main subscription card -->
    <div class="hd-card">
        <div class="hd-title">🛠 <?= t('happ_debug', 'title') ?></div>
        <div class="hd-meta">
            <?php $sc = $data['api_status'] === 200 ? '#4ade80' : '#f87171' ?>
            <div class="hd-meta-item">
                <span><?= t('happ_debug', 'label_status') ?></span>
                <code style="color:<?= $sc ?>"><?= $data['api_status'] ?></code>
            </div>
            <div class="hd-meta-item">
                <span><?= t('happ_debug', 'label_time') ?></span>
                <code><?= $data['api_ms'] ?> <?= t('happ_debug', 'label_ms') ?></code>
            </div>
            <div class="hd-meta-item">
                <span><?= t('happ_debug', 'label_url') ?></span>
                <code><?= htmlspecialchars($data['api_url']) ?></code>
            </div>
        </div>

        <div class="hd-tabs">
            <button class="hd-tab active" onclick="hdTab(this,'hd-pane-headers','hd-main')"><?= t('happ_debug', 'tab_headers') ?></button>
            <button class="hd-tab" onclick="hdTab(this,'hd-pane-raw-req','hd-main')"><?= t('happ_debug', 'tab_raw_req') ?></button>
            <button class="hd-tab" onclick="hdTab(this,'hd-pane-raw-resp','hd-main')"><?= t('happ_debug', 'tab_raw_resp') ?></button>
        </div>

        <div id="hd-pane-headers" class="hd-pane active" data-group="hd-main">
            <?php foreach ($data['out_headers'] as $k => $v): ?>
            <div class="dbg-row">
                <span><?= htmlspecialchars($k) ?></span>
                <code><?= htmlspecialchars($v) ?></code>
            </div>
            <?php endforeach ?>
        </div>
        <div id="hd-pane-raw-req" class="hd-pane" data-group="hd-main">
            <div class="dbg-raw" id="hd-raw-req" data-raw="<?= htmlspecialchars($data['raw_request']) ?>"></div>
        </div>
        <div id="hd-pane-raw-resp" class="hd-pane" data-group="hd-main">
            <div class="dbg-raw" id="hd-raw-resp" data-raw="<?= htmlspecialchars($data['raw_response']) ?>"></div>
        </div>
    </div>

    <!-- WL subscription card -->
    <div class="hd-card">
        <div class="hd-title wl">🔒 <?= t('happ_debug', 'wl_title') ?></div>
        <?php $wlSc = $data['wl_api_status'] === 200 ? '#4ade80' : '#f87171' ?>
        <div class="hd-meta">
            <div class="hd-meta-item">
                <span><?= t('happ_debug', 'label_status') ?></span>
                <code style="color:<?= $wlSc ?>"><?= $data['wl_api_status'] ?></code>
            </div>
            <div class="hd-meta-item">
                <span><?= t('happ_debug', 'label_time') ?></span>
                <code><?= $data['wl_api_ms'] ?> <?= t('happ_debug', 'label_ms') ?></code>
            </div>
            <div class="hd-meta-item">
                <span><?= t('happ_debug', 'label_url') ?></span>
                <code><?= htmlspecialchars($data['wl_api_url']) ?></code>
            </div>
        </div>

        <?php if ($data['wl_api_status'] !== 200): ?>
        <div class="hd-not-found"><?= t('happ_debug', 'wl_not_found') ?></div>
        <?php else: ?>
        <div class="hd-tabs">
            <button class="hd-tab active" onclick="hdTab(this,'hd-wl-pane-raw-req','hd-wl')"><?= t('happ_debug', 'tab_raw_req') ?></button>
            <button class="hd-tab" onclick="hdTab(this,'hd-wl-pane-raw-resp','hd-wl')"><?= t('happ_debug', 'tab_raw_resp') ?></button>
        </div>
        <div id="hd-wl-pane-raw-req" class="hd-pane active" data-group="hd-wl">
            <div class="dbg-raw" id="hd-wl-raw-req" data-raw="<?= htmlspecialchars($data['wl_raw_request']) ?>"></div>
        </div>
        <div id="hd-wl-pane-raw-resp" class="hd-pane" data-group="hd-wl">
            <div class="dbg-raw" id="hd-wl-raw-resp" data-raw="<?= htmlspecialchars($data['wl_raw_response']) ?>"></div>
        </div>
        <?php endif ?>
    </div>

</div>
<script src="<?= assetUrl('js/debug.js') ?>"></script>
<script>
function hdTab(el, pane, group) {
    document.querySelectorAll('[data-group="' + group + '"]').forEach(function(p) { p.classList.remove('active'); });
    el.closest('.hd-card').querySelectorAll('.hd-tab').forEach(function(t) { t.classList.remove('active'); });
    el.classList.add('active');
    document.getElementById(pane).classList.add('active');
}
document.addEventListener('DOMContentLoaded', function() {
    [
        ['hd-raw-req',     hlRequest],
        ['hd-raw-resp',    hlResponse],
        ['hd-wl-raw-req',  hlRequest],
        ['hd-wl-raw-resp', hlResponse],
    ].forEach(function(pair) {
        var el = document.getElementById(pair[0]);
        if (el && el.dataset.raw) el.innerHTML = pair[1](el.dataset.raw);
    });
});
</script>
</body>
</html>
