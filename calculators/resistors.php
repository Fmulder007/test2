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

.band-table { width:100%; min-width:0; table-layout:fixed; border-collapse:separate; border-spacing:3px 2px; margin-top:12px; }
.band-table th, .band-table td { padding:0; text-align:center; vertical-align:middle; }
.band-table th { background:#f7f9fa; border:1px solid var(--border); border-radius:5px; font-size:12px; font-weight:600; line-height:1.15; height:53px; padding:4px 2px; }
.band-table td { height:39px; }
.band-choice {
    display:block; width:100%; min-width:0; height:39px; padding:3px 2px;
    border:2px solid transparent; border-radius:4px; cursor:pointer;
    font:600 11px/1.1 Arial,Helvetica,sans-serif; white-space:normal;
}
.band-choice:hover { filter:brightness(.92); }
.band-choice.selected { border:6px solid #1268b3; box-shadow:0 0 0 1px #fff inset; }
.band-choice:focus-visible { outline:2px solid var(--blue); outline-offset:1px; }
@media (max-width:520px) {
    .band-table { border-spacing:2px 2px; }
    .band-table th { font-size:10px; height:50px; }
    .band-choice { height:38px; padding:2px 1px; font-size:9px; }
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
.formula { color: var(--muted); font-size: 13px; }
.actions { display: flex; gap: 9px; flex-wrap: wrap; margin-top: 15px; }
@media (max-width: 720px) {
    body { padding: 10px; }
    h1 { font-size: 23px; }
    .panel { padding: 14px; }
    .layout { grid-template-columns: 1fr; }
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
                <div style="overflow-x:auto">
                    <table class="band-table" id="bandTable" aria-label="Цветовая маркировка резистора">
                        <thead><tr id="bandHead"></tr></thead>
                        <tbody id="bandChoices"></tbody>
                    </table>
                </div>
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
            <div class="actions" id="decodeActions">
                <button type="button" class="action-btn primary" id="resetBtn">Сбросить</button>
            </div>
            <div class="actions hidden" id="encodeActions">
                <button type="button" class="action-btn primary" id="encodeBtn">Подобрать полосы</button>
            </div>
        </section>

        <section class="panel">
            <h2>Результат</h2>
            <div class="resistor-wrap hidden" id="resultResistor">
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
            <div class="result" aria-live="polite">
                <div class="small">Номинальное сопротивление</div>
                <div class="result-value" id="resultValue">4,7 кОм</div>
                <div class="result-meta" id="resultTolerance">Допуск: ±5%</div>
                <div class="result-details" id="resultDetails">
                    <div>Минимум: 4,465 кОм</div>
                    <div>Максимум: 4,935 кОм</div>
                </div>
            </div>

        </section>
    </div>

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
        return Array.from({ length: n }, (_, i) => {
            const labels = ['Первая полоса', 'Вторая полоса', 'Третья полоса', 'Четвёртая полоса', 'Пятая полоса', 'Шестая полоса'];
            return labels[i];
        });
    };

    function allowedForBand(pos, count) {
        const digitCount = count >= 5 ? 3 : 2;
        if (pos < digitCount) return colors.filter(c => c.digit !== undefined);
        if (pos === digitCount) return colors.filter(c => c.mult !== undefined);
        if (pos === digitCount + 1 && count >= 4) return colors.filter(c => c.tolerance !== undefined);
        if (pos === digitCount + 2 && count === 6) return colors.filter(c => c.tcr !== undefined);
        return [];
    }

    function colorIndex(color) { return colors.indexOf(color); }

    function renderFields() {
        const count = Number($('bandCount').value);
        const roles = bandNames(count);
        const head = $('bandHead');
        const choicesBody = $('bandChoices');
        head.innerHTML = '';
        choicesBody.innerHTML = '';
        $('bandTable').style.minWidth = (count * 78) + 'px';

        for (let i = 0; i < count; i++) {
            const choices = allowedForBand(i, count);
            if (!choices.some(c => colorIndex(c) === selected[i])) {
                selected[i] = colorIndex(choices[0]);
            }
            const th = document.createElement('th');
            th.scope = 'col';
            th.innerHTML = roles[i].replace(' ', '<br>');
            head.appendChild(th);
        }

        colors.forEach((color, rowIndex) => {
            const tr = document.createElement('tr');
            for (let i = 0; i < count; i++) {
                const td = document.createElement('td');
                const idx = colorIndex(color);
                const allowed = allowedForBand(i, count).some(c => colorIndex(c) === idx);
                if (allowed) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'band-choice' + (selected[i] === idx ? ' selected' : '');
                    button.style.backgroundColor = color.hex;
                    const hex = color.hex.replace('#', '');
                    const red = parseInt(hex.slice(0, 2), 16);
                    const green = parseInt(hex.slice(2, 4), 16);
                    const blue = parseInt(hex.slice(4, 6), 16);
                    const luminance = 0.299 * red + 0.587 * green + 0.114 * blue;
                    button.style.color = luminance < 145 ? '#fff' : '#202124';
                    button.style.borderColor = selected[i] === idx ? '#1268b3' : (rowIndex === 9 ? '#777' : 'transparent');

                    const digitCount = count >= 5 ? 3 : 2;
                    let code;
                    if (i < digitCount) code = color.digit;
                    else if (i === digitCount) code = color.mult;
                    else if (i === digitCount + 1) code = color.tolerance;
                    else code = color.tcr;
                    let label = '';
                    if (code !== undefined) {
                        if (i < digitCount) label = code + ' — ';
                        else if (i === digitCount) label = code > 1000 ? '10^' + Math.round(Math.log10(code)) + ' — ' : '×' + String(code).replace('.', ',') + ' — ';
                        else if (i === digitCount + 1) label = '±' + String(code).replace('.', ',') + '% — ';
                        else label = code + ' ppm — ';
                    }
                    button.textContent = label + color.name.toLowerCase();
                    button.setAttribute('aria-label', 'Выбрать ' + color.name + ' для ' + roles[i]);
                    button.setAttribute('aria-pressed', selected[i] === idx ? 'true' : 'false');
                    button.addEventListener('click', () => {
                        selected[i] = idx;
                        renderFields();
                    });
                    td.appendChild(button);
                }
                tr.appendChild(td);
            }
            choicesBody.appendChild(tr);
        });
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
        $('resultResistor').classList.toggle('hidden', mode !== 'encode');
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
        $('resultValue').textContent = formatResistance(raw);
        $('resultTolerance').textContent = 'Цвета полос показаны в таблице.';
        $('resultDetails').innerHTML =
            '<div>Допуск: ±' + formatPercent(tolerance) + '</div>' +
            '<div>Минимум: ' + formatResistance(raw * (1 - tolerance / 100)) + '</div>' +
            '<div>Максимум: ' + formatResistance(raw * (1 + tolerance / 100)) + '</div>';
        drawBands(indices);
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
    renderFields();
})();
</script>
</body>
</html>
