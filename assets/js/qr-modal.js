(function () {
    'use strict';

    var EC_LEVEL_BITS = 1; // QR error correction level L.
    var QR_TABLE = [
        null,
        { ecc: 7, blocks: [19], align: [] },
        { ecc: 10, blocks: [34], align: [6, 18] },
        { ecc: 15, blocks: [55], align: [6, 22] },
        { ecc: 20, blocks: [80], align: [6, 26] },
        { ecc: 26, blocks: [108], align: [6, 30] },
        { ecc: 18, blocks: [68, 68], align: [6, 34] },
        { ecc: 20, blocks: [78, 78], align: [6, 22, 38] },
        { ecc: 24, blocks: [97, 97], align: [6, 24, 42] },
        { ecc: 30, blocks: [116, 116], align: [6, 26, 46] },
        { ecc: 18, blocks: [68, 68, 69, 69], align: [6, 28, 50] }
    ];

    var EXP = new Array(512);
    var LOG = new Array(256);
    (function initGaloisField() {
        var x = 1;
        for (var i = 0; i < 255; i++) {
            EXP[i] = x;
            LOG[x] = i;
            x <<= 1;
            if (x & 0x100) x ^= 0x11d;
        }
        for (var j = 255; j < EXP.length; j++) EXP[j] = EXP[j - 255];
    })();

    function gfMultiply(x, y) {
        if (x === 0 || y === 0) return 0;
        return EXP[LOG[x] + LOG[y]];
    }

    function rsDivisor(degree) {
        var result = new Array(degree).fill(0);
        result[degree - 1] = 1;
        var root = 1;
        for (var i = 0; i < degree; i++) {
            for (var j = 0; j < degree; j++) {
                result[j] = gfMultiply(result[j], root);
                if (j + 1 < degree) result[j] ^= result[j + 1];
            }
            root = gfMultiply(root, 2);
        }
        return result;
    }

    function rsRemainder(data, divisor) {
        var result = new Array(divisor.length).fill(0);
        data.forEach(function (value) {
            var factor = value ^ result.shift();
            result.push(0);
            for (var i = 0; i < divisor.length; i++) {
                result[i] ^= gfMultiply(divisor[i], factor);
            }
        });
        return result;
    }

    function utf8Bytes(text) {
        if (window.TextEncoder) return Array.from(new TextEncoder().encode(text));

        var encoded = unescape(encodeURIComponent(text));
        var bytes = [];
        for (var i = 0; i < encoded.length; i++) bytes.push(encoded.charCodeAt(i));
        return bytes;
    }

    function BitBuffer() {
        this.bits = [];
    }
    BitBuffer.prototype.append = function (value, length) {
        for (var i = length - 1; i >= 0; i--) this.bits.push((value >>> i) & 1);
    };
    BitBuffer.prototype.toBytes = function () {
        var bytes = [];
        for (var i = 0; i < this.bits.length; i += 8) {
            var value = 0;
            for (var j = 0; j < 8; j++) value = (value << 1) | (this.bits[i + j] || 0);
            bytes.push(value);
        }
        return bytes;
    };

    function selectVersion(bytes) {
        for (var version = 1; version < QR_TABLE.length; version++) {
            var dataCodewords = QR_TABLE[version].blocks.reduce(function (sum, value) {
                return sum + value;
            }, 0);
            var countBits = version < 10 ? 8 : 16;
            if (4 + countBits + bytes.length * 8 <= dataCodewords * 8) return version;
        }
        throw new Error('QR data is too long');
    }

    function makeDataCodewords(text, version) {
        var bytes = utf8Bytes(text);
        var table = QR_TABLE[version];
        var capacity = table.blocks.reduce(function (sum, value) { return sum + value; }, 0);
        var buffer = new BitBuffer();

        buffer.append(0x4, 4);
        buffer.append(bytes.length, version < 10 ? 8 : 16);
        bytes.forEach(function (value) { buffer.append(value, 8); });

        var remaining = capacity * 8 - buffer.bits.length;
        buffer.append(0, Math.min(4, remaining));
        while (buffer.bits.length % 8 !== 0) buffer.append(0, 1);

        var data = buffer.toBytes();
        for (var pad = 0; data.length < capacity; pad++) data.push(pad % 2 === 0 ? 0xec : 0x11);
        return data;
    }

    function makeCodewords(text) {
        var bytes = utf8Bytes(text);
        var version = selectVersion(bytes);
        var table = QR_TABLE[version];
        var data = makeDataCodewords(text, version);
        var divisor = rsDivisor(table.ecc);
        var blocks = [];
        var offset = 0;

        table.blocks.forEach(function (blockLength) {
            var blockData = data.slice(offset, offset + blockLength);
            offset += blockLength;
            blocks.push({ data: blockData, ecc: rsRemainder(blockData, divisor) });
        });

        var result = [];
        var maxDataLength = Math.max.apply(null, table.blocks);
        for (var i = 0; i < maxDataLength; i++) {
            blocks.forEach(function (block) {
                if (i < block.data.length) result.push(block.data[i]);
            });
        }
        for (var j = 0; j < table.ecc; j++) {
            blocks.forEach(function (block) { result.push(block.ecc[j]); });
        }

        return { version: version, codewords: result };
    }

    function createMatrix(size) {
        var modules = [];
        var isFunction = [];
        for (var y = 0; y < size; y++) {
            modules.push(new Array(size).fill(false));
            isFunction.push(new Array(size).fill(false));
        }
        return { modules: modules, isFunction: isFunction };
    }

    function setModule(matrix, x, y, dark, isFunction) {
        if (x < 0 || y < 0 || y >= matrix.modules.length || x >= matrix.modules.length) return;
        matrix.modules[y][x] = !!dark;
        if (isFunction) matrix.isFunction[y][x] = true;
    }

    function drawFinder(matrix, cx, cy) {
        for (var dy = -4; dy <= 4; dy++) {
            for (var dx = -4; dx <= 4; dx++) {
                var dist = Math.max(Math.abs(dx), Math.abs(dy));
                var dark = dist !== 2 && dist !== 4;
                setModule(matrix, cx + dx, cy + dy, dark, true);
            }
        }
    }

    function drawAlignment(matrix, cx, cy) {
        for (var dy = -2; dy <= 2; dy++) {
            for (var dx = -2; dx <= 2; dx++) {
                var dist = Math.max(Math.abs(dx), Math.abs(dy));
                setModule(matrix, cx + dx, cy + dy, dist !== 1, true);
            }
        }
    }

    function drawFunctionPatterns(matrix, version) {
        var size = matrix.modules.length;
        drawFinder(matrix, 3, 3);
        drawFinder(matrix, size - 4, 3);
        drawFinder(matrix, 3, size - 4);

        for (var i = 8; i < size - 8; i++) {
            setModule(matrix, 6, i, i % 2 === 0, true);
            setModule(matrix, i, 6, i % 2 === 0, true);
        }

        QR_TABLE[version].align.forEach(function (cy) {
            QR_TABLE[version].align.forEach(function (cx) {
                if (matrix.isFunction[cy][cx]) return;
                drawAlignment(matrix, cx, cy);
            });
        });

        setModule(matrix, 8, size - 8, true, true);
        drawFormatBits(matrix, 0);
        if (version >= 7) drawVersionBits(matrix, version);
    }

    function formatBits(mask) {
        var data = (EC_LEVEL_BITS << 3) | mask;
        var bits = data << 10;
        for (var i = 14; i >= 10; i--) {
            if (((bits >>> i) & 1) !== 0) bits ^= 0x537 << (i - 10);
        }
        return ((data << 10) | bits) ^ 0x5412;
    }

    function drawFormatBits(matrix, mask) {
        var size = matrix.modules.length;
        var bits = formatBits(mask);
        var bit = function (i) { return ((bits >>> i) & 1) !== 0; };

        for (var i = 0; i <= 5; i++) setModule(matrix, 8, i, bit(i), true);
        setModule(matrix, 8, 7, bit(6), true);
        setModule(matrix, 8, 8, bit(7), true);
        setModule(matrix, 7, 8, bit(8), true);
        for (var j = 9; j < 15; j++) setModule(matrix, 14 - j, 8, bit(j), true);

        for (var k = 0; k < 8; k++) setModule(matrix, size - 1 - k, 8, bit(k), true);
        for (var m = 8; m < 15; m++) setModule(matrix, 8, size - 15 + m, bit(m), true);
        setModule(matrix, 8, size - 8, true, true);
    }

    function versionBits(version) {
        var bits = version;
        for (var i = 0; i < 12; i++) bits = (bits << 1) ^ (((bits >>> 11) & 1) * 0x1f25);
        return (version << 12) | bits;
    }

    function drawVersionBits(matrix, version) {
        var size = matrix.modules.length;
        var bits = versionBits(version);
        for (var i = 0; i < 18; i++) {
            var dark = ((bits >>> i) & 1) !== 0;
            var a = size - 11 + (i % 3);
            var b = Math.floor(i / 3);
            setModule(matrix, a, b, dark, true);
            setModule(matrix, b, a, dark, true);
        }
    }

    function maskBit(mask, x, y) {
        switch (mask) {
            case 0: return (x + y) % 2 === 0;
            case 1: return y % 2 === 0;
            case 2: return x % 3 === 0;
            case 3: return (x + y) % 3 === 0;
            case 4: return (Math.floor(y / 2) + Math.floor(x / 3)) % 2 === 0;
            case 5: return ((x * y) % 2) + ((x * y) % 3) === 0;
            case 6: return (((x * y) % 2) + ((x * y) % 3)) % 2 === 0;
            case 7: return (((x + y) % 2) + ((x * y) % 3)) % 2 === 0;
            default: return false;
        }
    }

    function cloneMatrix(matrix) {
        return {
            modules: matrix.modules.map(function (row) { return row.slice(); }),
            isFunction: matrix.isFunction.map(function (row) { return row.slice(); })
        };
    }

    function drawCodewords(base, codewords, mask) {
        var matrix = cloneMatrix(base);
        var size = matrix.modules.length;
        var bitIndex = 0;
        var totalBits = codewords.length * 8;

        for (var right = size - 1; right >= 1; right -= 2) {
            if (right === 6) right--;
            for (var vert = 0; vert < size; vert++) {
                var y = (((right + 1) & 2) === 0) ? size - 1 - vert : vert;
                for (var j = 0; j < 2; j++) {
                    var x = right - j;
                    if (matrix.isFunction[y][x]) continue;
                    var dark = false;
                    if (bitIndex < totalBits) {
                        dark = (((codewords[bitIndex >>> 3] >>> (7 - (bitIndex & 7))) & 1) !== 0);
                        bitIndex++;
                    }
                    setModule(matrix, x, y, dark !== maskBit(mask, x, y), false);
                }
            }
        }

        drawFormatBits(matrix, mask);
        return matrix;
    }

    function penalty(matrix) {
        var modules = matrix.modules;
        var size = modules.length;
        var totalPenalty = 0;
        var darkCount = 0;

        function addRuns(values) {
            var runColor = values[0];
            var runLength = 1;
            for (var i = 1; i < values.length; i++) {
                if (values[i] === runColor) {
                    runLength++;
                } else {
                    if (runLength >= 5) totalPenalty += runLength - 2;
                    runColor = values[i];
                    runLength = 1;
                }
            }
            if (runLength >= 5) totalPenalty += runLength - 2;
        }

        for (var y = 0; y < size; y++) addRuns(modules[y]);
        for (var x = 0; x < size; x++) {
            var column = [];
            for (var cy = 0; cy < size; cy++) column.push(modules[cy][x]);
            addRuns(column);
        }

        for (var yy = 0; yy < size - 1; yy++) {
            for (var xx = 0; xx < size - 1; xx++) {
                var color = modules[yy][xx];
                if (color === modules[yy][xx + 1] && color === modules[yy + 1][xx] && color === modules[yy + 1][xx + 1]) {
                    totalPenalty += 3;
                }
            }
        }

        function addFinderPenalty(values) {
            var a = '10111010000';
            var b = '00001011101';
            var line = values.map(function (value) { return value ? '1' : '0'; }).join('');
            for (var i = 0; i <= line.length - 11; i++) {
                var chunk = line.slice(i, i + 11);
                if (chunk === a || chunk === b) totalPenalty += 40;
            }
        }

        for (var py = 0; py < size; py++) addFinderPenalty(modules[py]);
        for (var px = 0; px < size; px++) {
            var values = [];
            for (var vy = 0; vy < size; vy++) values.push(modules[vy][px]);
            addFinderPenalty(values);
        }

        for (var dy = 0; dy < size; dy++) {
            for (var dx = 0; dx < size; dx++) if (modules[dy][dx]) darkCount++;
        }
        totalPenalty += Math.floor(Math.abs(darkCount * 20 - size * size * 10) / (size * size)) * 10;

        return totalPenalty;
    }

    function makeQr(text) {
        var payload = makeCodewords(text);
        var size = 21 + (payload.version - 1) * 4;
        var base = createMatrix(size);
        var best = null;
        var bestPenalty = Infinity;

        drawFunctionPatterns(base, payload.version);
        for (var mask = 0; mask < 8; mask++) {
            var matrix = drawCodewords(base, payload.codewords, mask);
            var score = penalty(matrix);
            if (score < bestPenalty) {
                bestPenalty = score;
                best = matrix.modules;
            }
        }

        return best;
    }

    function drawQr(canvas, text) {
        var modules = makeQr(text);
        var quiet = 4;
        var size = modules.length;
        var maxCanvasSize = Math.min(320, Math.max(220, Math.floor(window.innerWidth - 80)));
        var scale = Math.floor(maxCanvasSize / (size + quiet * 2));
        var canvasSize = (size + quiet * 2) * scale;
        var ctx = canvas.getContext('2d');

        canvas.width = canvasSize;
        canvas.height = canvasSize;
        canvas.style.width = canvasSize + 'px';
        canvas.style.height = canvasSize + 'px';

        ctx.fillStyle = '#fff7ed';
        ctx.fillRect(0, 0, canvasSize, canvasSize);
        ctx.fillStyle = '#c2410c';
        for (var y = 0; y < size; y++) {
            for (var x = 0; x < size; x++) {
                if (modules[y][x]) ctx.fillRect((x + quiet) * scale, (y + quiet) * scale, scale, scale);
            }
        }
    }

    function initQrModal() {
        var button = document.getElementById('qr-btn');
        var modal = document.getElementById('qr-modal');
        var canvas = document.getElementById('qr-canvas');
        var closeButtons = document.querySelectorAll('[data-qr-close]');
        if (!button || !modal || !canvas) return;

        function close() {
            modal.hidden = true;
            document.body.classList.remove('qr-modal-open');
            button.focus();
        }

        function open() {
            try {
                drawQr(canvas, window.location.href);
                modal.hidden = false;
                document.body.classList.add('qr-modal-open');
                var closeButton = modal.querySelector('[data-qr-close]');
                if (closeButton) closeButton.focus();
            } catch (error) {
                window.alert('QR code cannot be generated for this URL.');
            }
        }

        button.addEventListener('click', open);
        closeButtons.forEach(function (closeButton) {
            closeButton.addEventListener('click', close);
        });
        modal.addEventListener('click', function (event) {
            if (event.target === modal) close();
        });
        document.addEventListener('keydown', function (event) {
            if (!modal.hidden && event.key === 'Escape') close();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initQrModal);
    } else {
        initQrModal();
    }
})();
