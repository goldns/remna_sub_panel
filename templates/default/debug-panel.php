<link rel="stylesheet" href="<?= assetUrl('css/debug.css') ?>">
<script src="<?= assetUrl('js/debug.js') ?>"></script>

<div id="dbg-wrap">
    <div id="dbg-btn-row">
        <button id="dbg-toggle" onclick="toggleDbg()">🛠 Debug</button>
        <a id="dbg-happ-link" href="?happ">📱 Happ</a>
    </div>
    <div id="dbg-panel">

        <div class="dbg-tabs">
            <button class="dbg-tab active" onclick="dbgTab(this,'dbg-pane-info')"><?= t('debug', 'tab_request') ?></button>
            <button class="dbg-tab" onclick="dbgTab(this,'dbg-pane-raw-req')"><?= t('debug', 'tab_raw_req') ?></button>
            <button class="dbg-tab" onclick="dbgTab(this,'dbg-pane-raw-resp')"><?= t('debug', 'tab_raw_resp') ?></button>
            <button class="dbg-tab" onclick="dbgTab(this,'dbg-pane-config')"><?= t('debug', 'tab_config') ?></button>
        </div>

        <!-- TAB: Request info -->
        <div id="dbg-pane-info" class="dbg-pane active">
            <div class="dbg-section">
                <div class="dbg-title"><?= t('debug', 'section_client') ?></div>
                <div class="dbg-row"><span>IP</span><code><?= htmlspecialchars($debug['client_ip']) ?></code></div>
                <div class="dbg-row"><span>UUID</span><code><?= htmlspecialchars($debug['short_uuid']) ?></code></div>
                <div class="dbg-row"><span>URL</span><code><?= htmlspecialchars($debug['request_url']) ?></code></div>
                <div class="dbg-row"><span>User-Agent</span><code><?= htmlspecialchars($debug['user_agent']) ?></code></div>
            </div>
            <div class="dbg-section">
                <div class="dbg-title"><?= t('debug', 'section_headers') ?></div>
                <?php foreach ($debug['req_headers'] as $k => $v): ?>
                <div class="dbg-row">
                    <span><?= htmlspecialchars($k) ?></span>
                    <code><?= htmlspecialchars($v) ?></code>
                </div>
                <?php endforeach ?>
            </div>
            <div class="dbg-section">
                <div class="dbg-title"><?= t('debug', 'section_api') ?></div>
                <?php $statusColor = $debug['api_status'] === 200 ? '#4ade80' : '#f87171' ?>
                <div class="dbg-row">
                    <span><?= t('debug', 'label_status') ?></span>
                    <code style="color:<?= $statusColor ?>"><?= $debug['api_status'] ?> · <?= $debug['api_ms'] ?> <?= t('debug', 'label_ms') ?></code>
                </div>
                <div class="dbg-row"><span>URL</span><code><?= htmlspecialchars($debug['api_url']) ?></code></div>
            </div>
            <div class="dbg-section">
                <div class="dbg-title"><?= t('debug', 'section_wl_api') ?></div>
                <?php $wlColor = $debug['wl_api_status'] === 200 ? '#4ade80' : '#f87171' ?>
                <div class="dbg-row">
                    <span><?= t('debug', 'label_status') ?></span>
                    <code style="color:<?= $wlColor ?>"><?= $debug['wl_api_status'] ?> · <?= $debug['wl_api_ms'] ?> <?= t('debug', 'label_ms') ?></code>
                </div>
                <div class="dbg-row"><span>URL</span><code><?= htmlspecialchars($debug['wl_api_url']) ?></code></div>
                <div class="dbg-row">
                    <span><?= t('debug', 'label_wl') ?></span>
                    <?php if ($debug['wl_found']): ?>
                    <code style="color:#4ade80"><?= t('debug', 'label_found') ?></code>
                    <?php else: ?>
                    <code style="color:#94a3b8"><?= t('debug', 'label_not_found') ?></code>
                    <?php endif ?>
                </div>
            </div>
            <?php if (isset($debug['hwid_user_url'])): ?>
            <div class="dbg-section">
                <div class="dbg-title">📱 HWID устройства</div>
                <?php $hUserColor = $debug['hwid_user_status'] === 200 ? '#4ade80' : '#f87171' ?>
                <div class="dbg-row">
                    <span>User API</span>
                    <code style="color:<?= $hUserColor ?>"><?= $debug['hwid_user_status'] ?> · <?= $debug['hwid_user_ms'] ?> мс</code>
                </div>
                <div class="dbg-row"><span>User URL</span><code><?= htmlspecialchars($debug['hwid_user_url']) ?></code></div>
                <?php if ($debug['hwid_uuid']): ?>
                <div class="dbg-row"><span>UUID</span><code><?= htmlspecialchars($debug['hwid_uuid']) ?></code></div>
                <?php $hDevColor = ($debug['hwid_api_status'] ?? null) === 200 ? '#4ade80' : '#f87171' ?>
                <div class="dbg-row">
                    <span>Devices API</span>
                    <code style="color:<?= $hDevColor ?>"><?= $debug['hwid_api_status'] ?? '—' ?> · <?= $debug['hwid_api_ms'] ?? '—' ?> мс</code>
                </div>
                <?php if (!empty($debug['hwid_api_url'])): ?>
                <div class="dbg-row"><span>Devices URL</span><code><?= htmlspecialchars($debug['hwid_api_url']) ?></code></div>
                <?php endif ?>
                <div class="dbg-row">
                    <span>Подключено / Лимит</span>
                    <code><?= $debug['hwid_count'] ?> / <?= $debug['hwid_limit'] !== null ? $debug['hwid_limit'] : '∞' ?></code>
                </div>
                <?php endif ?>
            </div>
            <?php endif ?>
            <?php if (isset($debug['checker'])): ?>
            <?php $chk = $debug['checker'] ?>
            <div class="dbg-section">
                <div class="dbg-title">🖧 xray-checker</div>
                <?php if ($chk === null): ?>
                <div class="dbg-row"><span>Статус</span><code style="color:#94a3b8">Отключён (checker_url пустой)</code></div>
                <?php else: ?>
                <?php $chkColor = $chk['success'] ? '#4ade80' : '#f87171' ?>
                <div class="dbg-row">
                    <span><?= t('debug', 'label_status') ?></span>
                    <code style="color:<?= $chkColor ?>"><?= $chk['code'] ?> · <?= $chk['ms'] ?> <?= t('debug', 'label_ms') ?> — <?= $chk['success'] ? 'OK' : 'FAIL' ?></code>
                </div>
                <div class="dbg-row"><span>URL</span><code><?= htmlspecialchars($chk['url']) ?></code></div>
                <?php if ($chk['success']): ?>
                <div class="dbg-row">
                    <span>Серверов</span>
                    <code>всего <?= $chk['total'] ?> · показано <?= $chk['shown'] ?> · скрыто <?= $chk['hidden'] ?></code>
                </div>
                <?php endif ?>
                <?php endif ?>
            </div>
            <?php endif ?>
            <?php if (isset($debug['encrypt'])): ?>
            <?php $enc = $debug['encrypt'] ?>
            <div class="dbg-section">
                <div class="dbg-title"><?= t('debug', 'section_encrypt') ?></div>
                <?php if ($enc['api_url']): ?>
                <div class="dbg-row">
                    <span><?= t('debug', 'label_status') ?></span>
                    <?php $encColor = (!$enc['used_fallback']) ? '#4ade80' : '#f87171' ?>
                    <code style="color:<?= $encColor ?>">
                        <?= $enc['code'] ?? '—' ?> · <?= $enc['ms'] ?? '—' ?> <?= t('debug', 'label_ms') ?>
                        — <?= $enc['used_fallback'] ? t('debug', 'label_encrypt_fallback') : t('debug', 'label_encrypt_ok') ?>
                    </code>
                </div>
                <div class="dbg-row"><span>API</span><code><?= htmlspecialchars($enc['api_url']) ?></code></div>
                <?php if ($enc['curl_error']): ?>
                <div class="dbg-row"><span>cURL</span><code style="color:#f87171"><?= htmlspecialchars($enc['curl_error']) ?></code></div>
                <?php endif ?>
                <?php else: ?>
                <div class="dbg-row"><span><?= t('debug', 'label_encrypt_disabled') ?></span><code style="color:#94a3b8">—</code></div>
                <?php endif ?>
                <div class="dbg-row" style="flex-direction:column;align-items:flex-start">
                    <span><?= t('debug', 'label_encrypt_result') ?></span>
                    <code style="word-break:break-all;padding-top:4px"><?= htmlspecialchars($enc['result']) ?></code>
                </div>
            </div>
            <?php endif ?>
        </div>

        <!-- TAB: Raw Request -->
        <div id="dbg-pane-raw-req" class="dbg-pane">
            <div class="dbg-label"><?= t('debug', 'label_main') ?></div>
            <div class="dbg-raw" id="dbg-raw-req" data-raw="<?= htmlspecialchars($debug['raw_request']) ?>"></div>
            <div class="dbg-label" style="margin-top:12px"><?= t('debug', 'label_wl') ?></div>
            <div class="dbg-raw" id="dbg-raw-req-wl" data-raw="<?= htmlspecialchars($debug['wl_raw_request']) ?>"></div>
            <?php if (!empty($debug['hwid_raw_request'])): ?>
            <div class="dbg-label" style="margin-top:12px">📱 HWID Devices</div>
            <div class="dbg-raw" id="dbg-raw-req-hwid" data-raw="<?= htmlspecialchars($debug['hwid_raw_request']) ?>"></div>
            <?php endif ?>
        </div>

        <!-- TAB: Raw Response -->
        <div id="dbg-pane-raw-resp" class="dbg-pane">
            <div class="dbg-label"><?= t('debug', 'label_main') ?></div>
            <div class="dbg-raw" id="dbg-raw-resp" data-raw="<?= htmlspecialchars($debug['raw_response']) ?>"></div>
            <div class="dbg-label" style="margin-top:12px"><?= t('debug', 'label_wl') ?></div>
            <div class="dbg-raw" id="dbg-raw-resp-wl" data-raw="<?= htmlspecialchars($debug['wl_raw_response']) ?>"></div>
            <?php if (!empty($debug['hwid_raw_response'])): ?>
            <div class="dbg-label" style="margin-top:12px">📱 HWID Devices</div>
            <div class="dbg-raw" id="dbg-raw-resp-hwid" data-raw="<?= htmlspecialchars($debug['hwid_raw_response']) ?>"></div>
            <?php endif ?>
            <?php if (!empty($debug['checker']['url'])): ?>
            <div class="dbg-label" style="margin-top:12px">🖧 xray-checker</div>
            <div class="dbg-raw" id="dbg-raw-resp-checker" data-raw="<?= htmlspecialchars($debug['checker']['body'] ?: '(empty)') ?>"></div>
            <?php endif ?>
            <?php if (!empty($debug['encrypt']['api_url'])): ?>
            <div class="dbg-label" style="margin-top:12px"><?= t('debug', 'label_encrypt_api') ?></div>
            <div class="dbg-raw" id="dbg-raw-resp-encrypt"><?= htmlspecialchars($debug['encrypt']['raw_body'] ?: '(empty)') ?></div>
            <?php endif ?>
        </div>

        <!-- TAB: Config -->
        <div id="dbg-pane-config" class="dbg-pane">
            <div class="dbg-section">
                <?php foreach ($debug['config'] as $k => $v): ?>
                <?php if (is_array($v)): ?>
                <div class="dbg-row" style="flex-direction:column;align-items:flex-start">
                    <span><?= htmlspecialchars($k) ?></span>
                    <?php foreach ($v as $hk => $hv): ?>
                    <code style="padding-left:8px"><?= htmlspecialchars($hk . ': ' . ($hv ?? 'null')) ?></code>
                    <?php endforeach ?>
                </div>
                <?php else: ?>
                <div class="dbg-row">
                    <span><?= htmlspecialchars($k) ?></span>
                    <code><?= htmlspecialchars(is_bool($v) ? ($v ? 'true' : 'false') : (string)($v ?? 'null')) ?></code>
                </div>
                <?php endif ?>
                <?php endforeach ?>
            </div>
        </div>

    </div>
</div>
