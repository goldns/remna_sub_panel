/**
 * @brief Toggles the browser debug panel and updates the button label.
 */
function toggleDbg() {
    var panel  = document.getElementById('dbg-panel');
    var btn    = document.getElementById('dbg-toggle');
    var isOpen = panel.classList.toggle('open');
    btn.textContent = isOpen ? '✕ Debug' : '🛠 Debug';
}

/**
 * @brief Activates one browser debug pane.
 * @param el Tab button that initiated the switch.
 * @param pane Identifier of the pane to activate.
 */
function dbgTab(el, pane) {
    document.querySelectorAll('.dbg-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.dbg-pane').forEach(function(p) { p.classList.remove('active'); });
    el.classList.add('active');
    document.getElementById(pane).classList.add('active');
}

/**
 * @brief Escapes and highlights a raw HTTP request for display.
 * @param text Untrusted raw request text.
 * @return Safe HTML containing syntax-highlight spans.
 */
function hlRequest(text) {
    var lines = text.split('\n');
    return lines.map(function(line, i) {
        if (i === 0) {
            return line.replace(/^(\w+)(\s+)(\S+)/, function(_, m, sp, u) {
                return '<span class="hl-method">' + esc(m) + '</span>' + sp +
                       '<span class="hl-url">' + esc(u) + '</span>';
            });
        }
        return hlHeader(line);
    }).join('\n');
}

/**
 * @brief Escapes and highlights a raw HTTP response for display.
 * @param text Untrusted raw response text.
 * @return Safe HTML containing syntax-highlight spans.
 */
function hlResponse(text) {
    var parts = text.split('\n\n');
    var headerBlock = parts[0] || '';
    var body = parts.slice(1).join('\n\n');

    var lines = headerBlock.split('\n');
    var highlighted = lines.map(function(line, i) {
        if (i === 0) {
            return line.replace(/^(HTTP\/\S+\s+)(\d+.*)/, function(_, proto, status) {
                var cls = status.startsWith('2') ? 'hl-status' : 'hl-status err';
                return esc(proto) + '<span class="' + cls + '">' + esc(status) + '</span>';
            });
        }
        return hlHeader(line);
    }).join('\n');

    if (body.trim()) {
        highlighted += '\n\n' + hlJson(body);
    }
    return highlighted;
}

/**
 * @brief Escapes and highlights one HTTP header line.
 * @param line Untrusted header line.
 * @return Safe HTML for the header line.
 */
function hlHeader(line) {
    return line.replace(/^([^:]+)(:\s*)(.*)/, function(_, k, sep, v) {
        return '<span class="hl-hkey">' + esc(k) + '</span>' + esc(sep) +
               '<span class="hl-hval">' + esc(v) + '</span>';
    });
}

/**
 * @brief Formats JSON when valid and otherwise escapes the original text.
 * @param text Untrusted response body.
 * @return Safe highlighted HTML.
 */
function hlJson(text) {
    try {
        var pretty = JSON.stringify(JSON.parse(text), null, 2);
        return pretty.replace(
            /("(?:[^"\\]|\\.)*")(\s*:)|("(?:[^"\\]|\\.)*")|(\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b)|(true|false)|(null)/g,
            function(m, key, colon, str, num, bool, nil) {
                if (key && colon)  return '<span class="hl-json-key">' + esc(key) + '</span>' + esc(colon);
                if (str)           return '<span class="hl-json-string">' + esc(str) + '</span>';
                if (num)           return '<span class="hl-json-number">' + esc(num) + '</span>';
                if (bool)          return '<span class="hl-json-bool">' + esc(bool) + '</span>';
                if (nil)           return '<span class="hl-json-null">' + esc(nil) + '</span>';
                return esc(m);
            }
        );
    } catch (e) {
        return esc(text);
    }
}

/**
 * @brief Escapes text before inserting it into generated debug HTML.
 * @param s Arbitrary value to stringify and escape.
 * @return HTML-safe string.
 */
function esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('dbg-toggle');
    if (toggle) toggle.addEventListener('click', toggleDbg);

    document.querySelectorAll('[data-debug-pane]').forEach(function(tab) {
        tab.addEventListener('click', function() { dbgTab(tab, tab.dataset.debugPane || ''); });
    });

    [
        ['dbg-raw-req',       hlRequest],
        ['dbg-raw-req-wl',    hlRequest],
        ['dbg-raw-req-hwid',  hlRequest],
        ['dbg-raw-resp',      hlResponse],
        ['dbg-raw-resp-wl',   hlResponse],
        ['dbg-raw-resp-hwid', hlResponse],
    ].forEach(function(pair) {
        var el = document.getElementById(pair[0]);
        if (el && el.dataset.raw) el.innerHTML = pair[1](el.dataset.raw);
    });
});
