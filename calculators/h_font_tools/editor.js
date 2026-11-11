(function () {
    "use strict";

    var state = {
        width: 8,
        height: 8,
        spacing: 1,
        name: "my_font",
        first: 32,
        last: 126,
        codes: Array.from({ length: 95 }, function (_, index) { return index + 32; }),
        selectionMode: "range",
        manualText: "",
        rasterSelection: [],
        selected: 32,
        tool: "draw",
        format: "generic",
        glyphs: Object.create(null)
    };

    var byId = function (id) { return document.getElementById(id); };
    var elements = {
        width: byId("hf-width"), height: byId("hf-height"), spacing: byId("hf-spacing"), name: byId("hf-name"),
        preset: byId("hf-preset"), rangeStart: byId("hf-range-start"), rangeEnd: byId("hf-range-end"),
        rangeControls: byId("hf-range-controls"), manualControls: byId("hf-manual-controls"),
        manualChars: byId("hf-manual-chars"), manualSummary: byId("hf-manual-summary"),
        modeTabs: document.querySelectorAll("[data-selection-mode]"),
        rasterSelectionCount: byId("hf-raster-selection-count"),
        rasterizeSelected: byId("hf-rasterize-selected"),
        grid: byId("hf-pixel-grid"), charMap: byId("hf-character-map"), charCode: byId("hf-char-code"),
        currentChar: byId("hf-current-char"), currentCode: byId("hf-current-code"),
        dimensions: byId("hf-grid-dimensions"), pixelCount: byId("hf-pixel-count"),
        charCount: byId("hf-char-count"), previewText: byId("hf-preview-text"), previewStage: byId("hf-preview-stage"),
        format: byId("hf-export-format"), code: byId("hf-code-output"), note: byId("hf-export-note"),
        status: byId("hf-status"), projectFile: byId("hf-project-file"), sourceFont: byId("hf-source-font")
    };

    function setStatus(message, isError) {
        elements.status.textContent = message;
        elements.status.classList.toggle("is-error", !!isError);
    }

    function hex(value, digits) {
        return Number(value).toString(16).toUpperCase().padStart(digits || 4, "0");
    }

    function printable(code) {
        if (code === 32) return "␠";
        if (code >= 33 && code <= 126) return String.fromCharCode(code);
        if (code >= 0xA0 && code <= 0xFFFF && !(code >= 0xD800 && code <= 0xDFFF)) return String.fromCharCode(code);
        return "·";
    }

    function charName(code) {
        if (code === 32) return "Пробел";
        if (code < 32) return "Управляющий символ";
        if (code === 127) return "DEL";
        return String.fromCharCode(code);
    }

    function blankGlyph() {
        return new Array(state.width * state.height).fill(0);
    }

    function getGlyph(code) {
        var glyph = state.glyphs[String(code)];
        if (!Array.isArray(glyph) || glyph.length !== state.width * state.height) return blankGlyph();
        return glyph;
    }

    function nonEmpty(glyph) {
        return glyph.some(function (value) { return value === 1; });
    }

    function currentGlyph() {
        var key = String(state.selected);
        if (!Array.isArray(state.glyphs[key]) || state.glyphs[key].length !== state.width * state.height) {
            state.glyphs[key] = blankGlyph();
        }
        return state.glyphs[key];
    }

    function renderGrid() {
        var glyph = getGlyph(state.selected);
        elements.grid.innerHTML = "";
        elements.grid.style.gridTemplateColumns = "repeat(" + state.width + ", 29px)";
        elements.grid.style.setProperty("--grid-width", String(state.width));
        for (var i = 0; i < glyph.length; i += 1) {
            var cell = document.createElement("button");
            cell.type = "button";
            cell.className = "hf-pixel" + (glyph[i] ? " is-on" : "");
            cell.dataset.index = String(i);
            cell.setAttribute("role", "gridcell");
            cell.setAttribute("aria-label", "Строка " + (Math.floor(i / state.width) + 1) + ", столбец " + ((i % state.width) + 1));
            elements.grid.appendChild(cell);
        }
        elements.dimensions.textContent = state.width + " × " + state.height + " px";
        elements.pixelCount.textContent = glyph.reduce(function (sum, bit) { return sum + (bit ? 1 : 0); }, 0) + " включённых пикселей";
        elements.currentChar.textContent = charName(state.selected);
        elements.currentCode.textContent = "Код: " + state.selected + " (0x" + hex(state.selected) + ")";
        elements.charCode.value = String(state.selected);
    }

    function renderRasterSelectionStatus() {
        var count = state.rasterSelection.length;
        elements.rasterSelectionCount.textContent = "Отмечено: " + count;
        elements.rasterizeSelected.disabled = count === 0;
    }

    function renderMap() {
        elements.charMap.innerHTML = "";
        var fragment = document.createDocumentFragment();
        rangeCodes().forEach(function (code) {
            var item = document.createElement("div");
            item.className = "hf-character-item";

            var button = document.createElement("button");
            button.type = "button";
            button.className = "hf-character" + (code === state.selected ? " is-selected" : "");
            button.dataset.code = String(code);
            button.title = "Открыть символ " + charName(code) + ", код " + code + " (0x" + hex(code) + ")";
            var symbol = document.createElement("span");
            symbol.className = "hf-character-symbol";
            symbol.textContent = printable(code);
            var label = document.createElement("span");
            label.className = "hf-character-code";
            label.textContent = hex(code, 2);
            button.appendChild(symbol);
            button.appendChild(label);

            var mark = document.createElement("button");
            var marked = state.rasterSelection.indexOf(code) !== -1;
            mark.type = "button";
            mark.className = "hf-character-mark" + (marked ? " is-marked" : "");
            mark.dataset.code = String(code);
            mark.setAttribute("aria-pressed", marked ? "true" : "false");
            mark.setAttribute("aria-label", (marked ? "Убрать " : "Отметить ") + "символ " + charName(code) + " для автосоздания");
            mark.title = marked ? "Убрать из выборочного автосоздания" : "Отметить для выборочного автосоздания";
            mark.textContent = marked ? "✓" : "+";

            item.appendChild(button);
            item.appendChild(mark);
            fragment.appendChild(item);
        });
        elements.charMap.appendChild(fragment);
        elements.charCount.textContent = state.codes.length + " символов";
        renderRasterSelectionStatus();
    }

    function renderPreview() {
        var text = elements.previewText.value || "";
        elements.previewStage.innerHTML = "";
        var run = document.createElement("div");
        run.className = "hf-preview-run";
        run.style.setProperty("--glyph-gap", (state.spacing + 1) + "px");
        var scale = Math.max(2, Math.min(5, Math.floor(66 / state.height)));
        for (var i = 0; i < text.length; i += 1) {
            var code = text.charCodeAt(i);
            if (code === 32) {
                var space = document.createElement("span");
                space.className = "hf-preview-space";
                space.style.width = Math.max(2, (state.width * scale + state.spacing * scale)) + "px";
                run.appendChild(space);
                continue;
            }
            var glyph = getGlyph(code);
            var glyphElement = document.createElement("span");
            glyphElement.className = "hf-preview-glyph";
            glyphElement.style.gridTemplateColumns = "repeat(" + state.width + ", " + scale + "px)";
            for (var p = 0; p < glyph.length; p += 1) {
                var dot = document.createElement("span");
                dot.className = "hf-preview-dot" + (glyph[p] ? " is-on" : "");
                glyphElement.appendChild(dot);
            }
            run.appendChild(glyphElement);
        }
        elements.previewStage.appendChild(run);
    }

    function renderAll() {
        renderGrid();
        renderMap();
        renderPreview();
    }

    function selectCode(code) {
        code = Number(code);
        if (!Number.isInteger(code) || state.codes.indexOf(code) === -1) {
            setStatus("Этот символ не входит в текущий набор.", true);
            elements.charCode.value = String(state.selected);
            return;
        }
        state.selected = code;
        renderAll();
        var selectedButton = elements.charMap.querySelector('[data-code="' + code + '"]');
        if (selectedButton) selectedButton.scrollIntoView({ block: "nearest", inline: "nearest" });
    }

    function paintAt(cell) {
        if (!cell || !cell.dataset || cell.dataset.index === undefined) return;
        var index = Number(cell.dataset.index);
        var glyph = currentGlyph();
        if (index < 0 || index >= glyph.length) return;
        var value = state.tool === "draw" ? 1 : 0;
        if (glyph[index] === value) return;
        glyph[index] = value;
        cell.classList.toggle("is-on", !!value);
        elements.pixelCount.textContent = glyph.reduce(function (sum, bit) { return sum + (bit ? 1 : 0); }, 0) + " включённых пикселей";
        renderPreview();
    }

    var drawing = false;
    elements.grid.addEventListener("pointerdown", function (event) {
        var cell = event.target.closest(".hf-pixel");
        if (!cell) return;
        event.preventDefault();
        drawing = true;
        paintAt(cell);
    });
    document.addEventListener("pointermove", function (event) {
        if (!drawing) return;
        var target = document.elementFromPoint(event.clientX, event.clientY);
        if (target && target.closest) paintAt(target.closest(".hf-pixel"));
    });
    document.addEventListener("pointerup", function () { drawing = false; });
    document.addEventListener("pointercancel", function () { drawing = false; });
    elements.grid.addEventListener("contextmenu", function (event) { event.preventDefault(); });

    elements.charMap.addEventListener("click", function (event) {
        var mark = event.target.closest(".hf-character-mark");
        if (mark) {
            var code = Number(mark.dataset.code);
            var index = state.rasterSelection.indexOf(code);
            if (index === -1) state.rasterSelection.push(code);
            else state.rasterSelection.splice(index, 1);
            renderMap();
            return;
        }
        var button = event.target.closest(".hf-character");
        if (button) selectCode(button.dataset.code);
    });
    byId("hf-prev-char").addEventListener("click", function () {
        var index = state.codes.indexOf(state.selected);
        if (index > 0) selectCode(state.codes[index - 1]);
    });
    byId("hf-next-char").addEventListener("click", function () {
        var index = state.codes.indexOf(state.selected);
        if (index >= 0 && index < state.codes.length - 1) selectCode(state.codes[index + 1]);
    });
    elements.charCode.addEventListener("change", function () { selectCode(elements.charCode.value); });

    document.querySelectorAll("[data-tool]").forEach(function (button) {
        button.addEventListener("click", function () {
            state.tool = button.dataset.tool;
            document.querySelectorAll("[data-tool]").forEach(function (item) {
                item.classList.toggle("is-active", item === button);
            });
        });
    });

    function replaceCurrentGlyph(transform) {
        var glyph = currentGlyph().slice();
        state.glyphs[String(state.selected)] = transform(glyph);
        renderGrid();
        renderPreview();
    }

    byId("hf-clear-glyph").addEventListener("click", function () {
        state.glyphs[String(state.selected)] = blankGlyph();
        renderGrid(); renderPreview();
    });
    byId("hf-fill-glyph").addEventListener("click", function () {
        state.glyphs[String(state.selected)] = new Array(state.width * state.height).fill(1);
        renderGrid(); renderPreview();
    });
    byId("hf-invert").addEventListener("click", function () {
        replaceCurrentGlyph(function (glyph) { return glyph.map(function (bit) { return bit ? 0 : 1; }); });
    });
    byId("hf-mirror-h").addEventListener("click", function () {
        replaceCurrentGlyph(function (glyph) {
            var result = blankGlyph();
            for (var y = 0; y < state.height; y += 1) for (var x = 0; x < state.width; x += 1) {
                result[y * state.width + x] = glyph[y * state.width + (state.width - 1 - x)];
            }
            return result;
        });
    });
    byId("hf-mirror-v").addEventListener("click", function () {
        replaceCurrentGlyph(function (glyph) {
            var result = blankGlyph();
            for (var y = 0; y < state.height; y += 1) for (var x = 0; x < state.width; x += 1) {
                result[y * state.width + x] = glyph[(state.height - 1 - y) * state.width + x];
            }
            return result;
        });
    });

    function shiftCurrentGlyph(dx, dy) {
        replaceCurrentGlyph(function (glyph) {
            var result = blankGlyph();
            for (var y = 0; y < state.height; y += 1) {
                for (var x = 0; x < state.width; x += 1) {
                    var sourceX = x - dx;
                    var sourceY = y - dy;
                    if (sourceX >= 0 && sourceX < state.width && sourceY >= 0 && sourceY < state.height) {
                        result[y * state.width + x] = glyph[sourceY * state.width + sourceX];
                    }
                }
            }
            return result;
        });
    }
    byId("hf-shift-up").addEventListener("click", function () { shiftCurrentGlyph(0, -1); });
    byId("hf-shift-down").addEventListener("click", function () { shiftCurrentGlyph(0, 1); });
    byId("hf-shift-left").addEventListener("click", function () { shiftCurrentGlyph(-1, 0); });
    byId("hf-shift-right").addEventListener("click", function () { shiftCurrentGlyph(1, 0); });

    function resizeAllGlyphs(newWidth, newHeight) {
        var oldWidth = state.width;
        var oldHeight = state.height;
        Object.keys(state.glyphs).forEach(function (key) {
            var oldGlyph = state.glyphs[key];
            if (!Array.isArray(oldGlyph) || oldGlyph.length !== oldWidth * oldHeight) {
                state.glyphs[key] = new Array(newWidth * newHeight).fill(0);
                return;
            }
            var next = new Array(newWidth * newHeight).fill(0);
            for (var y = 0; y < newHeight; y += 1) {
                for (var x = 0; x < newWidth; x += 1) {
                    var sourceX = Math.min(oldWidth - 1, Math.floor(x * oldWidth / newWidth));
                    var sourceY = Math.min(oldHeight - 1, Math.floor(y * oldHeight / newHeight));
                    next[y * newWidth + x] = oldGlyph[sourceY * oldWidth + sourceX] ? 1 : 0;
                }
            }
            state.glyphs[key] = next;
        });
        state.width = newWidth;
        state.height = newHeight;
        renderAll();
    }

    function applyDimensionChange() {
        var width = Math.max(5, Math.min(32, parseInt(elements.width.value, 10) || state.width));
        var height = Math.max(5, Math.min(32, parseInt(elements.height.value, 10) || state.height));
        elements.width.value = String(width);
        elements.height.value = String(height);
        if (width !== state.width || height !== state.height) {
            var hasData = Object.keys(state.glyphs).some(function (key) { return nonEmpty(state.glyphs[key]); });
            if (hasData && !window.confirm("Изменить размер всех символов? Рисунки будут масштабированы под новую сетку.")) {
                elements.width.value = String(state.width);
                elements.height.value = String(state.height);
                return;
            }
            resizeAllGlyphs(width, height);
            setStatus("Размер символов изменён на " + width + " × " + height + " px.");
        }
    }
    elements.width.addEventListener("change", applyDimensionChange);
    elements.height.addEventListener("change", applyDimensionChange);
    elements.spacing.addEventListener("change", function () {
        state.spacing = Math.max(0, Math.min(16, parseInt(elements.spacing.value, 10) || 0));
        elements.spacing.value = String(state.spacing);
        renderPreview();
    });
    elements.name.addEventListener("change", function () {
        state.name = sanitizePrefix(elements.name.value);
        elements.name.value = state.name;
    });

    var presets = {
        ascii: [32, 126],
        latin: [32, 255],
        cyrillic: [0x0400, 0x045F]
    };

    function setSelectionView(mode) {
        var manual = mode === "manual";
        elements.rangeControls.hidden = manual;
        elements.manualControls.hidden = !manual;
        elements.modeTabs.forEach(function (button) {
            var active = button.dataset.selectionMode === mode;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-pressed", active ? "true" : "false");
        });
    }

    elements.modeTabs.forEach(function (button) {
        button.addEventListener("click", function () {
            setSelectionView(button.dataset.selectionMode);
        });
    });

    elements.preset.addEventListener("change", function () {
        if (presets[elements.preset.value]) {
            elements.rangeStart.value = String(presets[elements.preset.value][0]);
            elements.rangeEnd.value = String(presets[elements.preset.value][1]);
        }
    });

    byId("hf-apply-range").addEventListener("click", function () {
        var first = parseInt(elements.rangeStart.value, 10);
        var last = parseInt(elements.rangeEnd.value, 10);
        if (!Number.isInteger(first) || !Number.isInteger(last) || first < 0 || last > 65535 || last < first || last - first > 511) {
            setStatus("Укажи корректный диапазон Unicode от 0 до 65535, не более 512 символов.", true);
            return;
        }
        if (first <= 0xDFFF && last >= 0xD800) {
            setStatus("Диапазон не должен включать суррогатные коды Unicode D800…DFFF.", true);
            return;
        }
        state.first = first;
        state.last = last;
        state.codes = [];
        for (var code = first; code <= last; code += 1) state.codes.push(code);
        state.rasterSelection = state.rasterSelection.filter(function (code) { return state.codes.indexOf(code) !== -1; });
        state.selectionMode = "range";
        if (state.codes.indexOf(state.selected) === -1) state.selected = first;
        elements.preset.value = Object.keys(presets).find(function (key) {
            return presets[key][0] === first && presets[key][1] === last;
        }) || "custom";
        setSelectionView("range");
        renderAll();
        setStatus("Диапазон символов установлен: " + first + "… " + last + ".");
    });

    function parseManualCharacters(rawText) {
        var characters = Array.from(String(rawText || ""));
        var codes = [];
        var seen = Object.create(null);
        characters.forEach(function (character) {
            var code = character.codePointAt(0);
            if (code > 0xFFFF) throw new Error("Символ «" + character + "» находится за пределами BMP (выше U+FFFF).");
            if ((code >= 0xD800 && code <= 0xDFFF) || code < 32 || code === 127) {
                throw new Error("Управляющие символы нельзя добавить в набор. Пробел разрешён.");
            }
            if (!seen[String(code)]) {
                seen[String(code)] = true;
                codes.push(code);
            }
        });
        if (!codes.length) throw new Error("Введи хотя бы один символ.");
        if (codes.length > 512) throw new Error("В одном наборе можно использовать не более 512 разных символов.");
        return codes;
    }

    function updateManualSummary(codes) {
        var shown = codes.slice(0, 12).map(function (code) {
            return printable(code) + " — " + code + " (0x" + hex(code) + ")";
        });
        if (codes.length > 12) shown.push("ещё " + (codes.length - 12));
        elements.manualSummary.textContent = "Количество выбранных символов: " + codes.length + ". Коды: " + shown.join("; ");
    }

    byId("hf-apply-manual").addEventListener("click", function () {
        try {
            var codes = parseManualCharacters(elements.manualChars.value);
            state.codes = codes;
            state.rasterSelection = state.rasterSelection.filter(function (code) { return codes.indexOf(code) !== -1; });
            state.selectionMode = "manual";
            state.manualText = elements.manualChars.value;
            state.first = Math.min.apply(null, codes);
            state.last = Math.max.apply(null, codes);
            elements.rangeStart.value = String(state.first);
            elements.rangeEnd.value = String(state.last);
            elements.preset.value = "custom";
            state.selected = codes[0];
            setSelectionView("manual");
            updateManualSummary(codes);
            renderAll();
            setStatus("Создан точный набор из " + codes.length + " символов. Промежуточные коды не включены.");
        } catch (error) {
            setStatus(error.message, true);
        }
    });

    elements.previewText.addEventListener("input", renderPreview);

    function sanitizePrefix(value) {
        var clean = String(value || "").trim().replace(/[^A-Za-z0-9_]/g, "_").replace(/^[^A-Za-z_]+/, "");
        return clean.slice(0, 32) || "my_font";
    }

    function rangeCodes() {
        return state.codes.slice();
    }

    function packRows(glyph) {
        var bytes = [];
        var bytesPerRow = Math.ceil(state.width / 8);
        for (var y = 0; y < state.height; y += 1) {
            for (var byteIndex = 0; byteIndex < bytesPerRow; byteIndex += 1) {
                var value = 0;
                for (var bit = 0; bit < 8; bit += 1) {
                    var x = byteIndex * 8 + bit;
                    if (x < state.width && glyph[y * state.width + x]) value |= (1 << (7 - bit));
                }
                bytes.push(value);
            }
        }
        return bytes;
    }

    function packXbm(glyph) {
        var bytes = [];
        var bytesPerRow = Math.ceil(state.width / 8);
        for (var y = 0; y < state.height; y += 1) {
            for (var byteIndex = 0; byteIndex < bytesPerRow; byteIndex += 1) {
                var value = 0;
                for (var bit = 0; bit < 8; bit += 1) {
                    var x = byteIndex * 8 + bit;
                    if (x < state.width && glyph[y * state.width + x]) value |= (1 << bit);
                }
                bytes.push(value);
            }
        }
        return bytes;
    }

    function packContinuous(glyph) {
        var bytes = [];
        for (var i = 0; i < glyph.length; i += 8) {
            var value = 0;
            for (var bit = 0; bit < 8; bit += 1) {
                if (i + bit < glyph.length && glyph[i + bit]) value |= (1 << (7 - bit));
            }
            bytes.push(value);
        }
        return bytes;
    }

    function formatBytes(bytes, indent) {
        var lines = [];
        for (var i = 0; i < bytes.length; i += 12) {
            lines.push(indent + bytes.slice(i, i + 12).map(function (value) {
                return "0x" + value.toString(16).toUpperCase().padStart(2, "0");
            }).join(", "));
        }
        return lines.join(",\n");
    }

    function headerGuard(prefix) {
        return (prefix + "_H").toUpperCase().replace(/[^A-Z0-9_]/g, "_");
    }

    function generateGeneric(prefix, codes) {
        var bpr = Math.ceil(state.width / 8);
        var bytes = [];
        codes.forEach(function (code) { bytes = bytes.concat(packRows(getGlyph(code))); });
        var codeValues = codes.map(function (code) { return "0x" + hex(code); });
        var lines = [];
        lines.push("#ifndef " + headerGuard(prefix));
        lines.push("#define " + headerGuard(prefix));
        lines.push("");
        lines.push("#include <stdint.h>");
        lines.push("#if defined(ARDUINO)");
        lines.push("#include <Arduino.h>");
        lines.push("#define H_FONT_STORAGE PROGMEM");
        lines.push("#define H_FONT_READ_BYTE(address) pgm_read_byte(address)");
        lines.push("#define H_FONT_READ_WORD(address) pgm_read_word(address)");
        lines.push("#else");
        lines.push("#define H_FONT_STORAGE");
        lines.push("#define H_FONT_READ_BYTE(address) (*(address))");
        lines.push("#define H_FONT_READ_WORD(address) (*(address))");
        lines.push("#endif");
        lines.push("");
        lines.push("// Bitmap: строки сверху вниз, старший бит байта соответствует левому пикселю.");
        lines.push("#define " + prefix.toUpperCase() + "_WIDTH " + state.width);
        lines.push("#define " + prefix.toUpperCase() + "_HEIGHT " + state.height);
        lines.push("#define " + prefix.toUpperCase() + "_SPACING " + state.spacing);
        lines.push("#define " + prefix.toUpperCase() + "_FIRST_CODE 0x" + hex(Math.min.apply(null, codes)));
        lines.push("#define " + prefix.toUpperCase() + "_LAST_CODE 0x" + hex(Math.max.apply(null, codes)));
        lines.push("#define " + prefix.toUpperCase() + "_BYTES_PER_ROW " + bpr);
        lines.push("#define " + prefix.toUpperCase() + "_GLYPH_COUNT " + codes.length);
        lines.push("");
        lines.push("static const uint16_t " + prefix + "_codepoints[] H_FONT_STORAGE = { " + codeValues.join(", ") + " };");
        lines.push("static const uint8_t " + prefix + "_bitmap[] H_FONT_STORAGE = {");
        lines.push(formatBytes(bytes, "    "));
        lines.push("};");
        lines.push("");
        lines.push("typedef struct {");
        lines.push("    uint16_t firstCode;");
        lines.push("    uint16_t lastCode;");
        lines.push("    uint16_t glyphCount;");
        lines.push("    uint8_t width;");
        lines.push("    uint8_t height;");
        lines.push("    uint8_t spacing;");
        lines.push("    uint8_t bytesPerRow;");
        lines.push("    const uint16_t *codepoints;");
        lines.push("    const uint8_t *bitmap;");
        lines.push("} HFontBitmap;");
        lines.push("");
        lines.push("static const HFontBitmap " + prefix + " = {");
        lines.push("    " + prefix.toUpperCase() + "_FIRST_CODE, " + prefix.toUpperCase() + "_LAST_CODE,");
        lines.push("    " + prefix.toUpperCase() + "_GLYPH_COUNT, " + prefix.toUpperCase() + "_WIDTH,");
        lines.push("    " + prefix.toUpperCase() + "_HEIGHT, " + prefix.toUpperCase() + "_SPACING,");
        lines.push("    " + prefix.toUpperCase() + "_BYTES_PER_ROW, " + prefix + "_codepoints, " + prefix + "_bitmap");
        lines.push("};");
        lines.push("");
        lines.push("static inline int16_t " + prefix + "_find_glyph(uint16_t code) {");
        lines.push("    for (uint16_t i = 0; i < " + prefix.toUpperCase() + "_GLYPH_COUNT; ++i) {");
        lines.push("        if (H_FONT_READ_WORD(" + prefix + ".codepoints + i) == code) return (int16_t)i;");
        lines.push("    }");
        lines.push("    return -1;");
        lines.push("}");
        lines.push("");
        lines.push("// Для символа code найди индекс: int16_t g = " + prefix + "_find_glyph(code);");
        lines.push("// Смещение байта: g * height * bytesPerRow + y * bytesPerRow + byteX.");
        lines.push("// Чтение: uint8_t value = H_FONT_READ_BYTE(" + prefix + ".bitmap + offset);");
        lines.push("");
        lines.push("#endif // " + headerGuard(prefix));
        return lines.join("\n");
    }

    function generateU8g2(prefix, codes) {
        var lines = [];
        lines.push("#ifndef " + headerGuard(prefix));
        lines.push("#define " + headerGuard(prefix));
        lines.push("");
        lines.push("#include <Arduino.h>");
        lines.push("#include <U8g2lib.h>");
        lines.push("");
        lines.push("// XBM: младший бит байта соответствует левому пикселю строки.");
        codes.forEach(function (code) {
            var name = prefix + "_u" + hex(code);
            lines.push("static const uint8_t " + name + "[] U8X8_PROGMEM = {");
            lines.push(formatBytes(packXbm(getGlyph(code)), "    "));
            lines.push("};");
        });
        lines.push("");
        lines.push("static inline void draw_" + prefix + "(U8G2 &display, int16_t x, int16_t y, uint16_t code) {");
        lines.push("    switch (code) {");
        codes.forEach(function (code) {
            lines.push("        case 0x" + hex(code) + ": display.drawXBMP(x, y, " + state.width + ", " + state.height + ", " + prefix + "_u" + hex(code) + "); break;");
        });
        lines.push("        default: break;");
        lines.push("    }");
        lines.push("}");
        lines.push("");
        lines.push("#endif // " + headerGuard(prefix));
        return lines.join("\n");
    }

    function generateAdafruit(prefix, codes) {
        var lines = [];
        lines.push("#ifndef " + headerGuard(prefix));
        lines.push("#define " + headerGuard(prefix));
        lines.push("");
        lines.push("#include <Arduino.h>");
        lines.push("#include <Adafruit_GFX.h>");
        lines.push("");

        if (state.selectionMode === "manual") {
            lines.push("// Разреженный набор символов: каждый код хранится отдельно.");
            lines.push("// Для вывода используется стандартный метод Adafruit_GFX drawBitmap().");
            codes.forEach(function (code) {
                lines.push("static const uint8_t " + prefix + "_u" + hex(code) + "[] PROGMEM = {");
                lines.push(formatBytes(packRows(getGlyph(code)), "    "));
                lines.push("};");
            });
            lines.push("");
            lines.push("static inline bool draw_" + prefix + "(Adafruit_GFX &display, int16_t x, int16_t y, uint16_t code, uint16_t color) {");
            lines.push("    switch (code) {");
            codes.forEach(function (code) {
                lines.push("        case 0x" + hex(code) + ": display.drawBitmap(x, y, " + prefix + "_u" + hex(code) + ", " + state.width + ", " + state.height + ", color); return true;");
            });
            lines.push("        default: return false;");
            lines.push("    }");
            lines.push("}");
            lines.push("");
            lines.push("// Пример: draw_" + prefix + "(display, x, y, 'U', WHITE);");
        } else {
            var bitmaps = [];
            var glyphRows = [];
            codes.forEach(function (code) {
                var offset = bitmaps.length;
                bitmaps = bitmaps.concat(packContinuous(getGlyph(code)));
                glyphRows.push("    { " + offset + ", " + state.width + ", " + state.height + ", " + (state.width + state.spacing) + ", 0, -" + state.height + " } // U+" + hex(code));
            });
            lines.push("// GFXfont bitmap: непрерывная последовательность битов, старший бит первым.");
            lines.push("static const uint8_t " + prefix + "Bitmaps[] PROGMEM = {");
            lines.push(formatBytes(bitmaps, "    "));
            lines.push("};");
            lines.push("");
            lines.push("static const GFXglyph " + prefix + "Glyphs[] PROGMEM = {");
            lines.push(glyphRows.join(",\n"));
            lines.push("};");
            lines.push("");
            lines.push("static const GFXfont " + prefix + " PROGMEM = {");
            lines.push("    (uint8_t *)" + prefix + "Bitmaps,");
            lines.push("    (GFXglyph *)" + prefix + "Glyphs,");
            lines.push("    0x" + hex(state.first) + ", 0x" + hex(state.last) + ", " + (state.height + 1));
            lines.push("};");
            lines.push("");
            lines.push("// Использование: display.setFont(&" + prefix + ");");
        }
        lines.push("");
        lines.push("#endif // " + headerGuard(prefix));
        lines.push("");
        lines.push("// Для кириллицы передавай Unicode-код отдельно; обычный print() не декодирует UTF-8 автоматически.");
        return lines.join("\n");
    }

    function generateHd44780(prefix, codes) {
        var selected = codes.filter(function (code) { return nonEmpty(getGlyph(code)); }).slice(0, 8);
        var lines = [];
        lines.push("#ifndef " + headerGuard(prefix));
        lines.push("#define " + headerGuard(prefix));
        lines.push("");
        lines.push("#include <Arduino.h>");
        lines.push("#include <LiquidCrystal.h>");
        lines.push("");
        lines.push("// HD44780 CGRAM: максимум 8 пользовательских символов, размер 5x8.");
        if (!selected.length) lines.push("// В диапазоне пока нет непустых символов. Нарисуй знаки и сформируй файл повторно.");
        selected.forEach(function (code, slot) {
            var glyph = getGlyph(code);
            var rows = [];
            for (var y = 0; y < 8; y += 1) {
                var value = 0;
                for (var x = 0; x < 5; x += 1) {
                    var sx = Math.min(state.width - 1, Math.floor(x * state.width / 5));
                    var sy = Math.min(state.height - 1, Math.floor(y * state.height / 8));
                    if (glyph[sy * state.width + sx]) value |= (1 << (4 - x));
                }
                rows.push("0x" + value.toString(16).toUpperCase().padStart(2, "0"));
            }
            lines.push("static byte " + prefix + "_cgram_" + slot + "[8] = { " + rows.join(", ") + " }; // слот " + slot + ", U+" + hex(code) + " (" + printable(code) + ")");
        });
        lines.push("");
        lines.push("static inline void load_" + prefix + "_cgram(LiquidCrystal &lcd) {");
        selected.forEach(function (code, slot) {
            lines.push("    lcd.createChar(" + slot + ", " + prefix + "_cgram_" + slot + ");");
        });
        lines.push("}");
        lines.push("");
        lines.push("#endif // " + headerGuard(prefix));
        return lines.join("\n");
    }

    function generateCode() {
        var prefix = sanitizePrefix(elements.name.value);
        state.name = prefix;
        elements.name.value = prefix;
        var codes = rangeCodes();
        if (state.format === "u8g2") {
            elements.note.textContent = "U8g2 использует XBM-порядок битов (младший бит слева). Вызов draw_" + prefix + "(u8g2, x, y, code) рисует один символ.";
            return generateU8g2(prefix, codes);
        }
        if (state.format === "adafruit") {
            if (state.selectionMode === "manual") {
                elements.note.textContent = "Для ручного набора создана компактная таблица отдельных символов и функция draw_" + prefix + "(display, x, y, code, color). Она использует стандартный метод Adafruit_GFX drawBitmap().";
            } else {
                elements.note.textContent = "Создан объект GFXfont. Подключи Adafruit_GFX и используй setFont(&" + prefix + "). Высота шрифта " + (state.height + 1) + " px с учётом baseline.";
            }
            return generateAdafruit(prefix, codes);
        }
        if (state.format === "hd44780") {
            elements.note.textContent = "Из выбранного набора берутся первые восемь непустых символов. Они автоматически масштабируются до 5×8 и загружаются в CGRAM через load_" + prefix + "_cgram(lcd).";
            return generateHd44780(prefix, codes);
        }
        elements.note.textContent = "Универсальный формат хранит биты построчно, старший бит — левый пиксель. Для чтения из PROGMEM используй макрос H_FONT_READ_BYTE.";
        return generateGeneric(prefix, codes);
    }

    byId("hf-generate-code").addEventListener("click", function () {
        try {
            state.format = elements.format.value;
            elements.code.value = generateCode();
            setStatus("Код сформирован: " + elements.code.value.length.toLocaleString("ru-RU") + " символов.");
        } catch (error) {
            setStatus("Не удалось сформировать код: " + error.message, true);
        }
    });
    elements.format.addEventListener("change", function () {
        state.format = elements.format.value;
        elements.code.value = generateCode();
    });
    byId("hf-download-header").addEventListener("click", function () {
        try {
            state.format = elements.format.value;
            var code = generateCode();
            elements.code.value = code;
            var blob = new Blob([code], { type: "text/plain;charset=utf-8" });
            var url = URL.createObjectURL(blob);
            var link = document.createElement("a");
            link.href = url;
            link.download = state.name + ".h";
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
            setStatus("Файл " + state.name + ".h подготовлен.");
        } catch (error) {
            setStatus("Не удалось подготовить .h: " + error.message, true);
        }
    });
    byId("hf-copy-code").addEventListener("click", function () {
        if (!elements.code.value) elements.code.value = generateCode();
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(elements.code.value).then(function () {
                setStatus("Код скопирован в буфер обмена.");
            }).catch(function () {
                elements.code.select();
                setStatus("Автоматическое копирование недоступно. Выдели код и скопируй его вручную.", true);
            });
        } else {
            elements.code.select();
            setStatus("Выделен весь код. Нажми Ctrl+C для копирования.");
        }
    });

    function rasterizeCodes(codes, successPrefix) {
        var canvas = document.createElement("canvas");
        canvas.width = state.width;
        canvas.height = state.height;
        var context = canvas.getContext("2d", { willReadFrequently: true });
        if (!context) {
            setStatus("Браузер не смог создать холст для генерации шрифта.", true);
            return;
        }
        var family = elements.sourceFont.value;
        var fontSize = Math.max(5, Math.floor(state.height * 0.88));
        var font = fontSize + "px " + family;
        codes.forEach(function (code) {
            var character = String.fromCharCode(code);
            var glyph = blankGlyph();
            if (code >= 32 && code !== 127 && !(code >= 0xD800 && code <= 0xDFFF)) {
                context.clearRect(0, 0, state.width, state.height);
                context.font = font;
                context.textAlign = "center";
                context.textBaseline = "middle";
                context.fillStyle = "#fff";
                context.fillText(character, state.width / 2, state.height / 2, state.width + 2);
                var pixels = context.getImageData(0, 0, state.width, state.height).data;
                for (var i = 0; i < glyph.length; i += 1) {
                    glyph[i] = pixels[i * 4 + 3] > 90 ? 1 : 0;
                }
            }
            state.glyphs[String(code)] = glyph;
        });
        renderAll();
        setStatus(successPrefix + codes.length + " символов из гарнитуры " + family + ". Их можно редактировать вручную.");
    }
    byId("hf-rasterize").addEventListener("click", function () {
        var codes = rangeCodes();
        if (!window.confirm("Автоматически создать все " + codes.length + " символов текущего набора?")) return;
        rasterizeCodes(codes, "Создано ");
    });
    byId("hf-rasterize-selected").addEventListener("click", function () {
        var codes = state.codes.filter(function (code) { return state.rasterSelection.indexOf(code) !== -1; });
        if (!codes.length) {
            setStatus("Сначала отметь нужные символы галочкой в таблице.", true);
            return;
        }
        if (!window.confirm("Автоматически создать только отмеченные символы (" + codes.length + " шт.)? Остальные рисунки останутся без изменений.")) return;
        rasterizeCodes(codes, "Создано отмеченных: ");
    });

    function projectData() {
        return {
            format: "h-font-tools-project",
            version: 1,
            width: state.width,
            height: state.height,
            spacing: state.spacing,
            name: state.name,
            first: state.first,
            last: state.last,
            codes: state.codes,
            selectionMode: state.selectionMode,
            manualText: state.manualText,
            rasterSelection: state.rasterSelection,
            selected: state.selected,
            glyphs: state.glyphs
        };
    }

    byId("hf-save-project").addEventListener("click", function () {
        var json = JSON.stringify(projectData(), null, 2);
        var blob = new Blob([json], { type: "application/json;charset=utf-8" });
        var url = URL.createObjectURL(blob);
        var link = document.createElement("a");
        link.href = url;
        link.download = state.name + ".json";
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
        setStatus("Проект сохранён в JSON.");
    });

    byId("hf-load-project").addEventListener("click", function () { elements.projectFile.click(); });
    elements.projectFile.addEventListener("change", function () {
        var file = elements.projectFile.files && elements.projectFile.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function () {
            try {
                var data = JSON.parse(String(reader.result || ""));
                if (!data || data.format !== "h-font-tools-project" || data.version !== 1) throw new Error("Неподдерживаемый формат проекта.");
                var width = Number(data.width);
                var height = Number(data.height);
                var first = Number(data.first);
                var last = Number(data.last);
                if (!Number.isInteger(width) || width < 5 || width > 32 || !Number.isInteger(height) || height < 5 || height > 32) throw new Error("Некорректные размеры сетки.");
                if (!Number.isInteger(first) || !Number.isInteger(last) || first < 0 || last > 65535 || last < first) throw new Error("Некорректные границы набора.");
                var selectionMode = data.selectionMode === "manual" && Array.isArray(data.codes) ? "manual" : "range";
                var codes = [];
                if (selectionMode === "manual") {
                    var seenCodes = Object.create(null);
                    data.codes.forEach(function (code) {
                        if (!Number.isInteger(code) || code < 0 || code > 65535 || (code >= 0xD800 && code <= 0xDFFF)) throw new Error("В проекте найден некорректный код символа.");
                        if (!seenCodes[String(code)]) {
                            seenCodes[String(code)] = true;
                            codes.push(code);
                        }
                    });
                    if (!codes.length || codes.length > 512) throw new Error("Ручной набор должен содержать от 1 до 512 символов.");
                    first = Math.min.apply(null, codes);
                    last = Math.max.apply(null, codes);
                } else {
                    if (last - first > 511 || (first <= 0xDFFF && last >= 0xD800)) throw new Error("Некорректный диапазон символов.");
                    for (var code = first; code <= last; code += 1) codes.push(code);
                }
                var validCodes = Object.create(null);
                codes.forEach(function (code) { validCodes[String(code)] = true; });
                var glyphs = Object.create(null);
                if (data.glyphs && typeof data.glyphs === "object") {
                    Object.keys(data.glyphs).forEach(function (key) {
                        var code = Number(key);
                        var glyph = data.glyphs[key];
                        if (validCodes[String(code)] && Array.isArray(glyph) && glyph.length === width * height) {
                            glyphs[key] = glyph.map(function (bit) { return bit ? 1 : 0; });
                        }
                    });
                }
                state.width = width;
                state.height = height;
                state.spacing = Math.max(0, Math.min(16, Number(data.spacing) || 0));
                state.name = sanitizePrefix(data.name);
                state.first = first;
                state.last = last;
                state.codes = codes;
                state.selectionMode = selectionMode;
                state.manualText = typeof data.manualText === "string" ? data.manualText : "";
                state.rasterSelection = Array.isArray(data.rasterSelection) ? data.rasterSelection.filter(function (code, index, array) {
                    return Number.isInteger(code) && codes.indexOf(code) !== -1 && array.indexOf(code) === index;
                }) : [];
                state.selected = codes.indexOf(Number(data.selected)) !== -1 ? Number(data.selected) : codes[0];
                state.glyphs = glyphs;
                elements.width.value = String(width);
                elements.height.value = String(height);
                elements.spacing.value = String(state.spacing);
                elements.name.value = state.name;
                elements.rangeStart.value = String(first);
                elements.rangeEnd.value = String(last);
                elements.manualChars.value = state.manualText;
                elements.preset.value = Object.keys(presets).find(function (key) {
                    return presets[key][0] === first && presets[key][1] === last;
                }) || "custom";
                setSelectionView(selectionMode);
                if (selectionMode === "manual") updateManualSummary(codes);
                else elements.manualSummary.textContent = "Набор пока не изменён. Введи символы и нажми «Применить символы».";
                renderAll();
                elements.code.value = generateCode();
                setStatus("Проект " + file.name + " загружен.");
            } catch (error) {
                setStatus("Не удалось открыть проект: " + error.message, true);
            }
            elements.projectFile.value = "";
        };
        reader.onerror = function () { setStatus("Не удалось прочитать файл проекта.", true); };
        reader.readAsText(file, "UTF-8");
    });

    byId("hf-new-project").addEventListener("click", function () {
        if (!window.confirm("Создать новый пустой проект? Несохранённые изменения будут потеряны.")) return;
        state.width = 8; state.height = 8; state.spacing = 1; state.name = "my_font";
        state.first = 32; state.last = 126; state.codes = Array.from({ length: 95 }, function (_, index) { return index + 32; });
        state.selectionMode = "range"; state.manualText = ""; state.rasterSelection = []; state.selected = 32; state.glyphs = Object.create(null);
        elements.width.value = "8"; elements.height.value = "8"; elements.spacing.value = "1";
        elements.name.value = "my_font"; elements.rangeStart.value = "32"; elements.rangeEnd.value = "126";
        elements.manualChars.value = ""; elements.preset.value = "ascii"; elements.code.value = "";
        setSelectionView("range");
        elements.manualSummary.textContent = "Набор пока не изменён. Введи символы и нажми «Применить символы».";
        renderAll();
        setStatus("Создан новый пустой проект.");
    });

    renderAll();
    elements.code.value = generateCode();
})();