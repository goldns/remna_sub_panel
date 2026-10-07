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
    /**
     * @brief Initializes exponent and logarithm tables for QR Reed-Solomon arithmetic.
     */
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

    /**
     * @brief Multiplies two bytes in the QR Galois field.
     * @param x First field element.
     * @param y Second field element.
     * @return Product field element.
     */
    function gfMultiply(x, y) {
        if (x === 0 || y === 0) return 0;
        return EXP[LOG[x] + LOG[y]];
    }

    /**
     * @brief Builds a Reed-Solomon generator polynomial.
     * @param degree Number of error-correction codewords.
     * @return Generator coefficients.
     */
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

    /**
     * @brief Calculates Reed-Solomon remainder codewords for one data block.
     * @param data Data codewords.
     * @param divisor Generator polynomial coefficients.
     * @return Error-correction codewords.
     */
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

    /**
     * @brief Encodes a string as UTF-8 bytes with a legacy-browser fallback.
     * @param text Input string.
     * @return Array of byte values.
     */
    function utf8Bytes(text) {
        if (window.TextEncoder) return Array.from(new TextEncoder().encode(text));

        var encoded = unescape(encodeURIComponent(text));
        var bytes = [];
        for (var i = 0; i < encoded.length; i++) bytes.push(encoded.charCodeAt(i));
        return bytes;
    }

    /**
     * @brief Creates a mutable bit buffer for QR payload serialization.
     */
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

    /**
     * @brief Selects the smallest supported QR version that can contain the payload.
     * @param bytes UTF-8 payload bytes.
     * @return Supported QR version from 1 through 10.
     */
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

    /**
     * @brief Serializes text and padding into QR data codewords.
     * @param text Text payload.
     * @param version Selected QR version.
     * @return Data codewords sized for the selected version.
     */
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

    /**
     * @brief Creates interleaved data and error-correction codewords.
     * @param text Text payload.
     * @return Object containing the selected version and final codewords.
     */
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

    /**
     * @brief Allocates QR module and function-reservation matrices.
     * @param size Matrix width and height.
     * @return Matrix state with modules and function masks.
     */
    function createMatrix(size) {
        var modules = [];
        var isFunction = [];
        for (var y = 0; y < size; y++) {
            modules.push(new Array(size).fill(false));
            isFunction.push(new Array(size).fill(false));
        }
        return { modules: modules, isFunction: isFunction };
    }

    /**
     * @brief Writes one in-bounds QR module and optionally reserves it.
     * @param matrix Mutable QR matrix state.
     * @param x Horizontal module coordinate.
     * @param y Vertical module coordinate.
     * @param dark Whether the module is dark.
     * @param isFunction Whether data placement must skip the module.
     */
    function setModule(matrix, x, y, dark, isFunction) {
        if (x < 0 || y < 0 || y >= matrix.modules.length || x >= matrix.modules.length) return;
        matrix.modules[y][x] = !!dark;
        if (isFunction) matrix.isFunction[y][x] = true;
    }

    /**
     * @brief Draws one finder pattern centered at the supplied coordinate.
     * @param matrix Mutable QR matrix state.
     * @param cx Horizontal center.
     * @param cy Vertical center.
     */
    function drawFinder(matrix, cx, cy) {
        for (var dy = -4; dy <= 4; dy++) {
            for (var dx = -4; dx <= 4; dx++) {
                var dist = Math.max(Math.abs(dx), Math.abs(dy));
                var dark = dist !== 2 && dist !== 4;
                setModule(matrix, cx + dx, cy + dy, dark, true);
            }
        }
    }

    /**
     * @brief Draws one alignment pattern centered at the supplied coordinate.
     * @param matrix Mutable QR matrix state.
     * @param cx Horizontal center.
     * @param cy Vertical center.
     */
    function drawAlignment(matrix, cx, cy) {
        for (var dy = -2; dy <= 2; dy++) {
            for (var dx = -2; dx <= 2; dx++) {
                var dist = Math.max(Math.abs(dx), Math.abs(dy));
                setModule(matrix, cx + dx, cy + dy, dist !== 1, true);
            }
        }
    }

    /**
     * @brief Draws all fixed patterns required by a QR version.
     * @param matrix Mutable QR matrix state.
     * @param version Selected QR version.
     */
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

    /**
     * @brief Calculates BCH-protected format bits for error level L and one mask.
     * @param mask QR mask identifier from 0 through 7.
     * @return Encoded 15-bit format value.
     */
    function formatBits(mask) {
        var data = (EC_LEVEL_BITS << 3) | mask;
        var bits = data << 10;
        for (var i = 14; i >= 10; i--) {
            if (((bits >>> i) & 1) !== 0) bits ^= 0x537 << (i - 10);
        }
        return ((data << 10) | bits) ^ 0x5412;
    }

    /**
     * @brief Writes format bits into both reserved matrix locations.
     * @param matrix Mutable QR matrix state.
     * @param mask QR mask identifier.
     */
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

    /**
     * @brief Calculates BCH-protected version information.
     * @param version QR version of at least 7.
     * @return Encoded 18-bit version value.
     */
    function versionBits(version) {
        var bits = version;
        for (var i = 0; i < 12; i++) bits = (bits << 1) ^ (((bits >>> 11) & 1) * 0x1f25);
        return (version << 12) | bits;
    }

    /**
     * @brief Writes version information into both reserved matrix locations.
     * @param matrix Mutable QR matrix state.
     * @param version QR version of at least 7.
     */
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

    /**
     * @brief Evaluates one standard QR mask formula.
     * @param mask Mask identifier from 0 through 7.
     * @param x Horizontal module coordinate.
     * @param y Vertical module coordinate.
     * @return Whether the data module must be inverted.
     */
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

    /**
     * @brief Deep-copies the two-dimensional QR matrix state.
     * @param matrix Source QR matrix.
     * @return Independent matrix state.
     */
    function cloneMatrix(matrix) {
        return {
            modules: matrix.modules.map(function (row) { return row.slice(); }),
            isFunction: matrix.isFunction.map(function (row) { return row.slice(); })
        };
    }

    /**
     * @brief Places codeword bits into a cloned base matrix using one mask.
     * @param base Matrix containing fixed function patterns.
     * @param codewords Interleaved QR codewords.
     * @param mask Mask identifier from 0 through 7.
     * @return Completed candidate matrix.
     */
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

    /**
     * @brief Scores a QR candidate using the standard visual penalty rules.
     * @param matrix Completed QR matrix state.
     * @return Non-negative penalty score; lower is better.
     */
    function penalty(matrix) {
        var modules = matrix.modules;
        var size = modules.length;
        var totalPenalty = 0;
        var darkCount = 0;

        /**
         * @brief Adds penalties for long runs of equal modules.
         * @param values One matrix row or column.
         */
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

        /**
         * @brief Adds penalties for finder-like sequences in one row or column.
         * @param values One matrix row or column.
         */
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

    /**
     * @brief Generates the best-scoring QR module grid for the text.
     * @param text Text payload supported by QR versions 1 through 10.
     * @return Two-dimensional boolean module grid.
     */
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

    /**
     * @brief Renders a QR module grid into the supplied canvas.
     * @param canvas Target HTMLCanvasElement.
     * @param text Text payload to encode.
     */
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

    /**
     * @brief Binds accessible QR modal controls when all required elements exist.
     */
    function initQrModal() {
        var button = document.getElementById('qr-btn');
        var modal = document.getElementById('qr-modal');
        var canvas = document.getElementById('qr-canvas');
        var closeButtons = document.querySelectorAll('[data-qr-close]');
        if (!button || !modal || !canvas) return;

        /**
         * @brief Closes the QR modal and restores focus to the trigger.
         */
        function close() {
            modal.hidden = true;
            document.body.classList.remove('qr-modal-open');
            button.focus();
        }

        /**
         * @brief Generates the current page QR code and opens the modal.
         */
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
