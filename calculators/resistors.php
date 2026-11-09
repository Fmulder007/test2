<?php
declare(strict_types=1);

/*
 * Калькулятор цветовой маркировки резисторов.
 * Самостоятельная страница; расчёты выполняются в браузере.
 */
$pageTitle = 'Калькулятор цветовой маркировки резисторов';
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<style>
:root {
    --bg: #f4f6f8;
    --panel: #fff;
    --text: #263238;
    --muted: #667781;
    --border: #dce3e8;
    --blue: #1976d2;
    --blue-dark: #125fae;
    --green-bg: #eaf7ef;
    --green-border: #b9e2c7;
    --note-bg: #eef7fc;
    --note-border: #c9e3f2;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    padding: 18px;
    background: var(--bg);
    color: var(--text);
    font: 15px/1.5 Arial, Helvetica, sans-serif;
}
.page { max-width: 1080px; margin: 0 auto; }
h1 { margin: 0 0 8px; font-size: 27px; line-height: 1.25; }
h2 { margin: 0 0 14px; font-size: 19px; }
h3 { margin: 0 0 10px; font-size: 16px; }
.subtitle { color: var(--muted); margin: 0 0 18px; }
.panel {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 18px;
    margin-bottom: 16px;
}
.mode-row, .toolbar, .result-top {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 9px;
}
.mode-btn, .action-btn {
    appearance: none;
    border: 1px solid var(--blue);
    border-radius: 6px;
    padding: 9px 14px;
    background: #fff;
    color: var(--blue);
    font: inherit;
    cursor: pointer;
}
.mode-btn.active, .action-btn.primary {
    color: #fff;
    background: var(--blue);
}
.mode-btn:hover, .action-btn:hover { background: var(--blue-dark); color: #fff; }
.mode-btn.active:hover, .action-btn.primary:hover { background: var(--blue-dark); }
.layout {
    display: grid;
    grid-template-columns: minmax(0, 1.05fr) minmax(320px, .95fr);
    gap: 16px;
    align-items: start;
}
.field { margin-bottom: 13px; }
label { display: block; margin-bottom: 5px; font-weight: 600; }
select, input {
    width: 100%;
    min-height: 40px;
    padding: 8px 10px;
    border: 1px solid #cbd5dc;
    border-radius: 6px;
    background: #fff;
    color: var(--text);
    font: inherit;
}
select:focus, input:focus { outline: 2px solid #b8d9f5; border-color: var(--blue); }
.band-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}
.band-field { min-width: 0; }
.band-field label { font-size: 13px; }
.band-select { padding-left: 36px; }
.color-select-wrap { position: relative; }
.color-dot {
    position: absolute;
    z-index: 1;
    left: 11px;
    top: 50%;
    width: 15px;
    height: 15px;
    transform: translateY(-50%);
    border: 1px solid #8b969d;
    border-radius: 3px;
    pointer-events: none;
}
.resistor-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 155px;
    padding: 8px 0 2px;
}
.resistor-svg { width: 100%; max-width: 490px; height: auto; overflow: visible; }
.result {
    padding: 15px;
    border: 1px solid var(--green-border);
    border-radius: 8px;
    background: var(--green-bg);
}
.result-value { font-size: 29px; font-weight: 700; color: #176b3a; line-height: 1.2; }
.result-meta { margin-top: 7px; color: #365b45; }
.result-details { margin-top: 12px; padding-top: 10px; border-top: 1px solid #cce6d4; }
.result-details div { margin: 4px 0; }
.note {
    padding: 12px 14px;
    border: 1px solid var(--note-border);
    border-radius: 8px;
    background: var(--note-bg);
    color: #35566a;
}
.hidden { display: none !important; }
.small { color: var(--muted); font-size: 13px; }
.color-legend { display: grid; grid-template-columns: repeat(5, minmax(0,1fr)); gap: 7px; }
.legend-item { display: flex; align-items: center; gap: 6px; min-width: 0; font-size: 12px; }
.legend-swatch { width: 15px; height: 15px; flex: 0 0 15px; border: 1px solid #9aa4aa; border-radius: 3px; }
.formula { color: var(--muted); font-size: 13px; }
.actions { display: flex; gap: 9px; flex-wrap: wrap; margin-top: 15px; }
@media (max-width: 720px) {
    body { padding: 10px; }
    h1 { font-size: 23px; }
    .panel { padding: 14px; }
    .layout { grid-template-columns: 1fr; }
    .color-legend { grid-template-columns: repeat(2, minmax(0,1fr)); }
}
@media print {
    body { background: #fff; padding: 0; }
    .mode-row, .actions { display: none; }
    .panel { break-inside: avoid; }
}
</style>
</head>
<body>
<main class="page">
    <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="subtitle">Расшифровка цветных полос и подбор маркировки по номиналу сопротивления.</p>

    <section class="panel">
        <div class="mode-row" role="group" aria-label="Режим калькулятора">
            <button type="button" class="mode-btn active" id="decodeMode">Цвета → сопротивление</button>
            <button type="button" class="mode-btn" id="encodeMode">Сопротивление → цвета</button>
        </div>
    </section>

    <div class="layout">
        <section class="panel">
            <h2 id="leftTitle">Цветовая маркировка</h2>
            <div class="field">
                <label for="bandCount">Количество полос</label>
                <select id="bandCount">
                    <option value="3">3 полосы</option>
                    <option value="4" selected>4 полосы</option>
                    <option value="5">5 полос</option>
                    <option value="6">6 полос</option>
                </select>
            </div>

            <div id="decodeFields">
                <div class="band-grid" id="bandGrid"></div>
            </div>

            <div id="encodeFields" class="hidden">
                <div class="field">
                    <label for="resistance">Номинальное сопротивление</label>
                    <input id="resistance" type="number" min="0.01" step="any" value="4700" inputmode="decimal">
                </div>
                <div class="field">
                    <label for="unit">Единицы измерения</label>
                    <select id="unit">
                        <option value="1">Ом</option>
                        <option value="1000" selected>кОм</option>
                        <option value="1000000">МОм</option>
                    </select>
                </div>
                <div class="field">
                    <label for="tolerance">Допуск</label>
                    <select id="tolerance">
                        <option value="1">±1%</option>
                        <option value="2">±2%</option>
                        <option value="0.5">±0,5%</option>
                        <option value="0.25">±0,25%</option>
                        <option value="0.1">±0,1%</option>
                        <option value="0.05">±0,05%</option>
                        <option value="5" selected>±5%</option>
                        <option value="10">±10%</option>
                        <option value="20">±20%</option>
                    </select>
                </div>
                <p class="small" id="encodeHint">Для 3 полос допуск не задаётся отдельно: принимается ±20%.</p>
            </div>

            <div class="resistor-wrap">
                <svg class="resistor-svg" viewBox="0 0 500 150" role="img" aria-label="Резистор с цветными полосами">
                    <defs>
                        <linearGradient id="bodyGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#fff5d9"/>
                            <stop offset=".48" stop-color="#e7d3a5"/>
                            <stop offset="1" stop-color="#cbb27c"/>
                        </linearGradient>
                        <linearGradient id="leadGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0" stop-color="#d9dde0"/>
                            <stop offset=".5" stop-color="#858e94"/>
                            <stop offset="1" stop-color="#e3e6e8"/>
                        </linearGradient>
                    </defs>
                    <path d="M10 75 H135 M365 75 H490" stroke="url(#leadGrad)" stroke-width="8" fill="none"/>
                    <rect x="125" y="36" width="250" height="78" rx="34" fill="url(#bodyGrad)" stroke="#b9a477" stroke-width="1.5"/>
                    <g id="resistorBands"></g>
                </svg>
            </div>
            <div class="actions" id="decodeActions">
                <button type="button" class="action-btn primary" id="resetBtn">Сбросить</button>
            </div>
            <div class="actions hidden" id="encodeActions">
                <button type="button" class="action-btn primary" id="encodeBtn">Подобрать полосы</button>
            </div>
        </section>

        <section class="panel">
            <h2>Результат</h2>
            <div class="result" aria-live="polite">
                <div class="small">Номинальное сопротивление</div>
                <div class="result-value" id="resultValue">4,7 кОм</div>
                <div class="result-meta" id="resultTolerance">Допуск: ±5%</div>
                <div class="result-details" id="resultDetails">
                    <div>Минимум: 4,465 кОм</div>
                    <div>Максимум: 4,935 кОм</div>
                </div>
            </div>
            <div class="note" style="margin-top:14px" id="explanation">Первые две полосы задают значащие цифры, третья — множитель, четвёртая — допуск.</div>
            <div style="margin-top:16px">
                <h3>Цветовой код</h3>
                <div class="color-legend" id="legend"></div>
            </div>
        </section>
    </div>

    <section class="panel">
        <h2>Как читать маркировку</h2>
        <p>У резисторов с 3 полосами первые две полосы обозначают цифры, третья — множитель. Допуск для такой маркировки обычно принимается равным ±20%.</p>
        <p>У резисторов с 4 полосами первые две полосы — цифры, третья — множитель, четвёртая — допуск. В маркировке с 5 и 6 полосами используются три значащие цифры. Шестая полоса обозначает температурный коэффициент сопротивления.</p>
        <p class="formula">Сопротивление = значащие цифры × множитель. Границы допуска: Rmin = R × (1 − допуск), Rmax = R × (1 + допуск).</p>
    </section>
</main>

<script>
(() => {
    'use strict';

    const colors = [
        { name: 'Чёрный', hex: '#202124', digit: 0, mult: 1, tcr: 250 },
        { name: 'Коричневый', hex: '#8b4513', digit: 1, mult: 10, tolerance: 1, tcr: 100 },
        { name: 'Красный', hex: '#e53935', digit: 2, mult: 100, tolerance: 2, tcr: 50 },
        { name: 'Оранжевый', hex: '#f28c28', digit: 3, mult: 1000, tcr: 15 },
        { name: 'Жёлтый', hex: '#fdd835', digit: 4, mult: 10000, tcr: 25 },
        { name: 'Зелёный', hex: '#43a047', digit: 5, mult: 100000, tolerance: 0.5, tcr: 20 },
        { name: 'Синий', hex: '#1e88e5', digit: 6, mult: 1000000, tolerance: 0.25, tcr: 10 },
        { name: 'Фиолетовый', hex: '#8e24aa', digit: 7, mult: 10000000, tolerance: 0.1, tcr: 5 },
        { name: 'Серый', hex: '#9e9e9e', digit: 8, mult: 100000000, tolerance: 0.05, tcr: 1 },
        { name: 'Белый', hex: '#f5f5f5', digit: 9, mult: 1000000000 },
        { name: 'Золотой', hex: '#c9a227', mult: 0.1, tolerance: 5 },
        { name: 'Серебряный', hex: '#bfc5c9', mult: 0.01, tolerance: 10 }
    ];
    const tcrValues = [250,100,50,15,25,20,10,5,1];
    const toleranceColors = [1,2,0.5,0.25,0.1,0.05,5,10];
    const $ = id => document.getElementById(id);
    let mode = 'decode';
    let selected = [4, 7, 2, 10, 1, 0];

    const bandNames = count => {
        const n = Number(count);
        if (n === 3) return ['Первая цифра', 'Вторая цифра', 'Множитель'];
        if (n === 4) return ['Первая цифра', 'Вторая цифра', 'Множитель', 'Допуск'];
        if (n === 5) return ['Первая цифра', 'Вторая цифра', 'Третья цифра', 'Множитель', 'Допуск'];
        return ['Первая цифра', 'Вторая цифра', 'Третья цифра', 'Множитель', 'Допуск', 'Температурный коэффициент'];
    };

    function allowedForBand(pos, count) {
        if (count === 3) return pos < 2 ? colors.slice(0,10) : colors;
        if (count === 4) {
            if (pos < 2) return colors.slice(0,10);
            if (pos === 2) return colors;
            return colors.filter(c => c.tolerance !== undefined);
        }
        if (pos < 3) return colors.slice(0,10);
        if (pos === 3) return colors;
        if (pos === 4) return colors.filter(c => c.tolerance !== undefined);
        return colors.filter(c => c.tcr !== undefined);
    }

    function colorIndex(color) { return colors.indexOf(color); }
    function renderFields() {
        const count = Number($('bandCount').value);
        const names = bandNames(count);
        const grid = $('bandGrid');
        grid.innerHTML = '';
        for (let i = 0; i < count; i++) {
            const choices = allowedForBand(i, count);
            let current = choices.some(c => colorIndex(c) === selected[i]) ? selected[i] : colorIndex(choices[0]);
            selected[i] = current;
            const wrap = document.createElement('div');
            wrap.className = 'band-field';
            const label = document.createElement('label');
            label.htmlFor = 'band' + i;
            label.textContent = (i + 1) + '. ' + names[i];
            const selectWrap = document.createElement('div');
            selectWrap.className = 'color-select-wrap';
            const dot = document.createElement('span');
            dot.className = 'color-dot';
            dot.style.background = colors[current].hex;
            const select = document.createElement('select');
            select.id = 'band' + i;
            select.className = 'band-select';
            choices.forEach(c => {
                const option = document.createElement('option');
                option.value = colorIndex(c);
                option.textContent = c.name;
                option.selected = colorIndex(c) === current;
                select.appendChild(option);
            });
            select.addEventListener('change', () => {
                selected[i] = Number(select.value);
                dot.style.background = colors[selected[i]].hex;
                update();
            });
            selectWrap.append(dot, select);
            wrap.append(label, selectWrap);
            grid.appendChild(wrap);
        }
        update();
    }

    function formatResistance(value) {
        if (!Number.isFinite(value)) return '—';
        const abs = Math.abs(value);
        let v = value, unit = 'Ом';
        if (abs >= 1e6) { v = value / 1e6; unit = 'МОм'; }
        else if (abs >= 1e3) { v = value / 1e3; unit = 'кОм'; }
        const digits = Math.abs(v) >= 100 ? 2 : (Math.abs(v) >= 10 ? 3 : 4);
        return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: digits }).format(v) + ' ' + unit;
    }
    function formatPercent(v) {
        return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(v) + '%';
    }
    function drawBands(indices) {
        const count = indices.length;
        const g = $('resistorBands');
        g.innerHTML = '';
        const positions = count === 3 ? [175, 225, 325] :
            count === 4 ? [165, 215, 285, 335] :
            count === 5 ? [155, 195, 235, 305, 345] :
            [145, 185, 225, 285, 325, 355];
        indices.forEach((idx, i) => {
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('x', positions[i]);
            rect.setAttribute('y', '37');
            rect.setAttribute('width', count >= 5 ? '13' : '16');
            rect.setAttribute('height', '76');
            rect.setAttribute('rx', '2');
            rect.setAttribute('fill', colors[idx].hex);
            rect.setAttribute('stroke', '#756b58');
            rect.setAttribute('stroke-width', '.7');
            g.appendChild(rect);
        });
    }
    function decode() {
        const count = Number($('bandCount').value);
        const cs = selected.slice(0, count).map(i => colors[i]);
        const digitsCount = count >= 5 ? 3 : 2;
        const digits = Number(cs.slice(0, digitsCount).map(c => c.digit).join(''));
        const multiplier = cs[digitsCount].mult;
        const value = digits * multiplier;
        const tolerance = count === 3 ? 20 : cs[digitsCount + 1].tolerance;
        const tcr = count === 6 ? cs[5].tcr : null;
        $('resultValue').textContent = formatResistance(value);
        $('resultTolerance').textContent = 'Допуск: ±' + formatPercent(tolerance);
        $('resultDetails').innerHTML =
            '<div>Минимум: ' + formatResistance(value * (1 - tolerance / 100)) + '</div>' +
            '<div>Максимум: ' + formatResistance(value * (1 + tolerance / 100)) + '</div>' +
            (tcr !== null ? '<div>Температурный коэффициент: ' + tcr + ' ppm/°C</div>' : '');
        const explanation = cs.map((c, i) => (i + 1) + ' — ' + c.name).join('; ');
        $('explanation').textContent = explanation + (count === 3 ? '. Допуск принят равным ±20%.' : '.');
        drawBands(selected.slice(0, count));
    }
    function update() {
        if (mode === 'decode') decode();
        $('encodeHint').textContent = Number($('bandCount').value) === 3 ?
            'Для 3 полос допуск отдельно не кодируется: принимается ±20%.' :
            'Для выбранного количества полос будут подобраны ближайшие стандартные цвета.';
        $('tolerance').disabled = Number($('bandCount').value) === 3;
    }
    function setMode(next) {
        mode = next;
        $('decodeMode').classList.toggle('active', mode === 'decode');
        $('encodeMode').classList.toggle('active', mode === 'encode');
        $('decodeFields').classList.toggle('hidden', mode !== 'decode');
        $('encodeFields').classList.toggle('hidden', mode !== 'encode');
        $('decodeActions').classList.toggle('hidden', mode !== 'decode');
        $('encodeActions').classList.toggle('hidden', mode !== 'encode');
        $('leftTitle').textContent = mode === 'decode' ? 'Цветовая маркировка' : 'Подбор цветовой маркировки';
        if (mode === 'decode') update();
    }
    function encode() {
        const count = Number($('bandCount').value);
        const digitsCount = count >= 5 ? 3 : 2;
        const raw = Number($('resistance').value) * Number($('unit').value);
        if (!Number.isFinite(raw) || raw <= 0) {
            $('resultValue').textContent = 'Проверьте значение';
            $('resultTolerance').textContent = '';
            $('resultDetails').textContent = 'Сопротивление должно быть положительным числом.';
            return;
        }
        const tolerance = count === 3 ? 20 : Number($('tolerance').value);
        let best = null;
        for (let sig = Math.pow(10, digitsCount - 1); sig < Math.pow(10, digitsCount); sig++) {
            const multiplier = raw / sig;
            const multColor = colors.findIndex(c => c.mult !== undefined && Math.abs(c.mult - multiplier) / multiplier < 1e-9);
            if (multColor >= 0) {
                best = { digits: String(sig).split('').map(Number), multColor };
                break;
            }
        }
        if (!best) {
            for (let exp = -2; exp <= 9; exp++) {
                const mult = Math.pow(10, exp);
                const sig = Math.round(raw / mult);
                if (sig >= Math.pow(10, digitsCount - 1) && sig < Math.pow(10, digitsCount) &&
                    Math.abs(sig * mult - raw) / raw < 1e-8) {
                    best = { digits: String(sig).padStart(digitsCount, '0').split('').map(Number), multColor: colors.findIndex(c => c.mult === mult) };
                    break;
                }
            }
        }
        if (!best || best.multColor < 0) {
            $('resultValue').textContent = 'Нет точной маркировки';
            $('resultTolerance').textContent = '';
            $('resultDetails').textContent = 'Для этого номинала не найдено точное представление при выбранном количестве полос.';
            return;
        }
        const indices = best.digits.concat([best.multColor]);
        if (count >= 4) {
            const tolColor = colors.findIndex(c => c.tolerance === tolerance);
            if (tolColor < 0) {
                $('resultValue').textContent = 'Недоступный допуск';
                $('resultDetails').textContent = 'Выберите допуск, поддерживаемый цветовым кодом.';
                return;
            }
            indices.push(tolColor);
        }
        if (count === 6) {
            const tcrColor = colors.findIndex(c => c.tcr === 100);
            indices.push(tcrColor);
        }
        selected = indices;
        renderFields();
        const colorText = indices.map(i => colors[i].name).join(' · ');
        $('resultValue').textContent = formatResistance(raw);
        $('resultTolerance').textContent = 'Маркировка: ' + colorText;
        $('resultDetails').innerHTML =
            '<div>Допуск: ±' + formatPercent(tolerance) + '</div>' +
            '<div>Минимум: ' + formatResistance(raw * (1 - tolerance / 100)) + '</div>' +
            '<div>Максимум: ' + formatResistance(raw * (1 + tolerance / 100)) + '</div>';
        $('explanation').textContent = 'Подобранная последовательность полос: ' + colorText + '.';
        drawBands(indices);
    }
    function renderLegend() {
        const legend = $('legend');
        colors.forEach(c => {
            const item = document.createElement('div');
            item.className = 'legend-item';
            const swatch = document.createElement('span');
            swatch.className = 'legend-swatch';
            swatch.style.background = c.hex;
            const name = document.createElement('span');
            name.textContent = c.name;
            item.append(swatch, name);
            legend.appendChild(item);
        });
    }

    $('decodeMode').addEventListener('click', () => setMode('decode'));
    $('encodeMode').addEventListener('click', () => setMode('encode'));
    $('bandCount').addEventListener('change', renderFields);
    $('resetBtn').addEventListener('click', () => {
        selected = [4, 7, 2, 10, 1, 0];
        $('bandCount').value = '4';
        renderFields();
    });
    $('encodeBtn').addEventListener('click', encode);
    ['resistance', 'unit', 'tolerance'].forEach(id => $(id).addEventListener('change', () => {
        if (mode === 'encode') encode();
    }));
    renderLegend();
    renderFields();
})();
</script>
</body>
</html>
