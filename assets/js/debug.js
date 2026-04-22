function toggleDbg() {
    var panel  = document.getElementById('dbg-panel');
    var btn    = document.getElementById('dbg-toggle');
    var isOpen = panel.classList.toggle('open');
    btn.textContent = isOpen ? '✕ Debug' : '🛠 Debug';
}

function dbgTab(el, pane) {
    document.querySelectorAll('.dbg-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.dbg-pane').forEach(function(p) { p.classList.remove('active'); });
    el.classList.add('active');
    document.getElementById(pane).classList.add('active');
}

/* Syntax highlight: HTTP request block */
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

/* Syntax highlight: HTTP response block */
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

function hlHeader(line) {
    return line.replace(/^([^:]+)(:\s*)(.*)/, function(_, k, sep, v) {
        return '<span class="hl-hkey">' + esc(k) + '</span>' + esc(sep) +
               '<span class="hl-hval">' + esc(v) + '</span>';
    });
}

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

function esc(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

document.addEventListener('DOMContentLoaded', function() {
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
