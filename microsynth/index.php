<?php
declare(strict_types=1);

/**
 * Конфигуратор EEPROM для «Синтезатора-МИКРО».
 *
 * Все операции с пользовательским eeprom.hex выполняются локально в браузере.
 * Файл пользователя не отправляется на сервер и не сохраняется на хостинге.
 */

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$firmwares = [
    [
        'synth' => '1freq-FM5',
        'mcu' => 'ATmega328PA',
        'low' => '62', 'high' => 'D1', 'extended' => 'FD',
        'file' => '1freq-FM5_ATmega328PA_firmware.hex',
    ],
    [
        'synth' => '1freq-FM5',
        'mcu' => 'ATmega168PA',
        'low' => '62', 'high' => 'D5', 'extended' => 'F9',
        'file' => '1freq-FM5_ATmega168PA_firmware.hex',
    ],
    [
        'synth' => '1freq-FM6',
        'mcu' => 'ATmega328PA',
        'low' => '62', 'high' => 'D1', 'extended' => 'FD',
        'file' => '1freq-FM6_ATmega328PA_firmware.hex',
    ],
    [
        'synth' => '1freq-FM6',
        'mcu' => 'ATmega168PA',
        'low' => '62', 'high' => 'D5', 'extended' => 'F9',
        'file' => '1freq-FM6_ATmega168PA_firmware.hex',
    ],
];

foreach ($firmwares as &$firmware) {
    $firmware['exists'] = is_file(__DIR__ . DIRECTORY_SEPARATOR . $firmware['file']);
}
unset($firmware);

?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="Подготовка EEPROM для синтезатора-МИКРО на Si5351: FM5 и FM6.">
<title>Конфигуратор EEPROM — Синтезатор-МИКРО</title>
<style>
    :root {
        color-scheme: light;
        --text: #222;
        --muted: #666;
        --line: #ccc;
        --soft-line: #e5e5e5;
        --link: #075a9c;
        --green: #27843b;
        --green-hover: #206d31;
        --green-border: #1f7132;
        --error: #b3261e;
        --success: #247a35;
    }

    * { box-sizing: border-box; }

    body {
        margin: 0;
        background: #fff;
        color: var(--text);
        font-family: Arial, Helvetica, sans-serif;
        font-size: 15px;
        line-height: 1.5;
    }

    .page {
        width: min(920px, calc(100% - 32px));
        margin: 22px auto 48px;
    }

    a { color: var(--link); text-decoration: underline; }
    a:hover { text-decoration: none; }

    h1 {
        margin: 0 0 7px;
        font-size: 25px;
        line-height: 1.25;
        font-weight: 600;
    }

    h2 {
        margin: 22px 0 9px;
        font-size: 19px;
        line-height: 1.3;
        font-weight: 600;
    }

    h3 {
        margin: 0 0 5px;
        font-size: 16px;
        font-weight: 600;
    }

    p { margin: 7px 0 11px; }
    code, .mono { font-family: Consolas, "Courier New", monospace; }

    .intro { margin: 0 0 17px; }
    .section { margin-top: 17px; padding-top: 11px; border-top: 1px solid var(--line); }
    .field { margin: 11px 0 14px; }
    .field-label, label { display: block; margin-bottom: 4px; font-weight: 600; }
    .hint, .muted, .small { color: var(--muted); font-size: 13px; }
    .hint { margin-top: 4px; }

    select,
    input[type="text"],
    input[type="number"] {
        min-height: 35px;
        padding: 6px 8px;
        border: 1px solid #aaa;
        border-radius: 2px;
        background: #fff;
        color: var(--text);
        font: inherit;
    }

    select:focus,
    input:focus,
    button:focus-visible,
    a:focus-visible {
        outline: 2px solid #8ab8df;
        outline-offset: 1px;
    }

    .model-select { width: min(100%, 440px); }
    .model-description { margin: 8px 0 12px; }
    .model-description p { margin: 4px 0; }

    .upload-control { display: block; max-width: 100%; font: inherit; }
    .file-tools { margin-top: 5px; }
    .text-link { font-size: 13px; }

    .param { margin: 11px 0 15px; max-width: 470px; }
    .param-title { margin-bottom: 4px; font-weight: 600; }
    .input-unit { display: flex; width: 100%; max-width: 430px; gap: 6px; }
    .input-unit input { width: 100%; min-width: 0; }
    .input-unit select { flex: 0 0 88px; width: 88px; }
    .step-input { width: 170px; max-width: 100%; }

    .form-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 13px; margin-top: 15px; }

    .action-button {
        display: inline-block;
        min-height: 36px;
        padding: 7px 14px;
        border: 1px solid #07558e;
        border-radius: 3px;
        background: #0869a9;
        color: #fff;
        font: inherit;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
    }
    .action-button:hover { background: #07598f; text-decoration: none; }

    .reset-link { font-size: 14px; }
    .status { min-height: 0; margin-top: 7px; font-size: 13px; }
    .status:empty { display: none; }
    .status.ok { color: var(--success); }
    .status.error { color: var(--error); }

    .result-section { margin-top: 23px; padding-top: 12px; border-top: 1px solid var(--line); }
    .result-empty { margin: 7px 0 10px; }
    .result-ready { display: none; margin-top: 9px; }
    .result-ready.visible { display: block; }
    .result-heading { margin: 0 0 5px; font-weight: 600; }
    .result-summary { margin: 5px 0 10px; font-size: 14px; }
    .hex-preview {
        overflow-x: auto;
        margin: 9px 0 12px;
        padding: 9px 10px;
        border: 1px solid var(--line);
        background: #fafafa;
        color: #333;
        font: 13px/1.55 Consolas, "Courier New", monospace;
        white-space: pre;
    }
    .download-button {
        display: inline-block;
        padding: 8px 15px;
        border: 1px solid var(--green-border);
        border-radius: 3px;
        background: var(--green);
        color: #fff;
        font-weight: 600;
        text-decoration: none;
    }
    .download-button:hover { background: var(--green-hover); text-decoration: none; }

    .firmware-section { margin-top: 25px; padding-top: 12px; border-top: 1px solid var(--line); }
    .firmware-intro { margin-bottom: 9px; }
    .table-wrap {
        width: 100%;
        overflow-x: auto;
        overscroll-behavior-x: contain;
        scrollbar-width: thin;
        scrollbar-color: #777 #e8e8e8;
    }
    .table-wrap::-webkit-scrollbar { height: 10px; }
    .table-wrap::-webkit-scrollbar-track { background: #e8e8e8; border-radius: 3px; }
    .table-wrap::-webkit-scrollbar-thumb {
        background: #888;
        border: 1px solid #777;
        border-radius: 3px;
    }
    .table-scrollbar-wrap { margin-top: 7px; }
    .table-scrollbar-wrap[hidden] { display: none; }
    .table-scrollbar-label { margin: 0 0 4px; color: var(--muted); font-size: 12px; }
    .table-scrollbar-track {
        position: relative;
        width: 100%;
        height: 13px;
        overflow: hidden;
        border: 1px solid #c7c7c7;
        border-radius: 3px;
        background: #e8e8e8;
        cursor: pointer;
        touch-action: pan-y;
        user-select: none;
        -webkit-user-select: none;
    }
    .table-scrollbar-track:focus-visible { outline: 2px solid #8ab8df; outline-offset: 2px; }
    .table-scrollbar-thumb {
        position: absolute;
        top: 0;
        left: 0;
        height: 11px;
        min-width: 40px;
        border: 1px solid #777;
        border-radius: 2px;
        background: #888;
        cursor: grab;
    }
    .table-scrollbar-thumb:active { background: #666; cursor: grabbing; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th, td { padding: 8px 9px; border: 1px solid var(--line); text-align: left; vertical-align: top; }
    th { background: #f2f2f2; font-weight: 600; }
    .firmware-name { overflow-wrap: anywhere; }
    .file-missing { color: var(--muted); font-size: 13px; }
    .fuses { white-space: nowrap; }
    .important-warning {
        margin: 0 0 16px;
        padding: 10px 12px;
        border: 1px solid #e00000;
        border-left: 5px solid #e00000;
        background: #fff7f7;
        color: #e00000;
        font-size: 17px;
        font-weight: 700;
        line-height: 1.45;
    }
    .important-warning strong { font-weight: 800; }
    .warning-bottom { margin-top: 18px; }
    .page-nav {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 6px 18px;
        margin: 0 0 12px;
    }
    .page-nav .project-link { margin: 0; font-size: 13px; }
    .page-nav-bottom {
        margin-top: 18px;
        padding-top: 10px;
        border-top: 1px solid var(--soft-line);
    }

    @media (max-width: 650px) {
        .page { width: calc(100% - 24px); margin-top: 16px; }
        h1 { font-size: 22px; }
        h2 { font-size: 18px; }
        .firmware-table { min-width: 690px; }
        .hex-preview { font-size: 12px; }
    }
</style>
</head>
<body>
<main class="page">
    <p class="important-warning"><strong>Внимание!</strong> Микроконтроллер и Si5351 на плате питаются от 3,3 В. Перед подключением программатора USBASP установите на нём питание 3,3 В. Для прошивки используйте AVRDUDE_PROG версии 5. Порядок программирования: сначала установите фьюзы из соответствующей строки таблицы, затем запишите прошивку во Flash, после этого отдельно запишите EEPROM. При Low = 62 системная частота МК составляет 1 МГц, поэтому частота ISP/SCK программатора должна быть ниже 250 кГц.</p>

    <!-- Ссылка на описание платы и проекта; продублирована внизу страницы. -->
    <nav class="page-nav" aria-label="Описание проекта">
        <a class="project-link" href="https://karat3.ru/synth_micro/">← Описание платы и проекта «Синтезатор-МИКРО»</a>
    </nav>

    <h1>Конфигуратор EEPROM — Синтезатор-МИКРО</h1>
    <p class="intro">Подготовка EEPROM для платы синтезатора на Si5351. Плата выполнена в размере большого кварцевого резонатора и предназначена для установки в «Карат-М» или «Карат-2Н» вместо кварцев на редкие частоты.</p>

    <section class="section" aria-labelledby="source-title">
        <h2 id="source-title" style="margin-top:0">Исходные данные</h2>

        <div class="field">
            <label for="model">Тип синтезатора</label>
            <select id="model" class="model-select">
                <option value="FM5">1freq-FM5 — переключение двух частот</option>
                <option value="FM6">1freq-FM6 — перестройка в диапазоне</option>
            </select>
            <div id="model-description" class="model-description">
                <h3>1freq-FM5 — две фиксированные частоты</h3>
                <p>Генерирует одну из двух заранее заданных частот. Выбор выполняется кнопкой или тумблером по входу «C», замыкаемому на землю; вход имеет внутреннюю подтяжку к 3,3 В. Подходит для замены двух кварцевых частот.</p>
            </div>
        </div>

        <div class="field">
            <label for="hex-file">Загрузить имеющийся файл <code>eeprom.hex</code> (необязательно)</label>
            <input class="upload-control" type="file" id="hex-file" accept=".hex,.eep,.txt,text/plain">
            <div class="hint">Если файл есть, страница прочитает его и подставит найденные значения в поля ниже. Если файла нет — просто введите новые параметры вручную. Файл обрабатывается в браузере и не отправляется на сервер.</div>
            <div id="file-status" class="status" aria-live="polite"></div>
            <div id="file-tools" class="file-tools" hidden><a href="#" id="clear-file" class="text-link">Убрать загруженный файл</a></div>
        </div>
    </section>

    <section class="section" aria-labelledby="params-title">
        <h2 id="params-title" style="margin-top:0">Параметры EEPROM</h2>

        <div id="fm5-fields">
            <div class="param">
                <div class="param-title"><label for="up">UP — частота при замыкании входа «C» на GND</label></div>
                <div class="input-unit">
                    <input id="up" type="text" inputmode="decimal" autocomplete="off" placeholder="Например, 7,1">
                    <select id="up-unit" aria-label="Единицы измерения UP">
                        <option value="1">Гц</option><option value="1000">кГц</option><option value="1000000" selected>МГц</option>
                    </select>
                </div>
            </div>
            <div class="param">
                <div class="param-title"><label for="dn">DN — частота при свободном входе «C»</label></div>
                <div class="input-unit">
                    <input id="dn" type="text" inputmode="decimal" autocomplete="off" placeholder="Например, 7,2">
                    <select id="dn-unit" aria-label="Единицы измерения DN">
                        <option value="1">Гц</option><option value="1000">кГц</option><option value="1000000" selected>МГц</option>
                    </select>
                </div>
            </div>
        </div>

        <div id="fm6-fields" hidden>
            <div class="param">
                <div class="param-title"><label for="fr">FR — центральная частота</label></div>
                <div class="input-unit">
                    <input id="fr" type="text" inputmode="decimal" autocomplete="off" placeholder="Например, 14">
                    <select id="fr-unit" aria-label="Единицы измерения FR">
                        <option value="1">Гц</option><option value="1000">кГц</option><option value="1000000" selected>МГц</option>
                    </select>
                </div>
            </div>
            <div class="param">
                <div class="param-title"><label for="bw">BW — полная ширина диапазона перестройки</label></div>
                <div class="input-unit">
                    <input id="bw" type="text" inputmode="decimal" autocomplete="off" placeholder="Например, 200">
                    <select id="bw-unit" aria-label="Единицы измерения BW">
                        <option value="1">Гц</option><option value="1000" selected>кГц</option><option value="1000000">МГц</option>
                    </select>
                </div>
                <div class="hint">BW = 0 допустимо: генератор работает на фиксированной частоте FR. Для BW нет отдельного ограничения 8 кГц: проверяются нижняя и верхняя частоты, получаемые из FR и BW.</div>
            </div>
            <div class="param">
                <div class="param-title"><label for="st">ST — количество шагов перестройки</label></div>
                <input id="st" class="step-input" type="text" inputmode="numeric" autocomplete="off" placeholder="От 0 до 1024">
                <div class="hint">0 — прямой режим, 1024 шага. Допустимые значения: от 0 до 1024.</div>
            </div>
        </div>

        <p class="hint">Допустимые частоты: от 8 000 Гц до 160 000 000 Гц включительно. Для FM6 проверяются нижняя и верхняя границы диапазона. Частоты в EEPROM записываются целым числом герц.</p>

        <div class="form-actions">
            <button type="button" class="action-button" id="generate">Сформировать eeprom.hex</button>
            <a href="#" id="reset-fields" class="reset-link">Сбросить поля</a>
        </div>
        <div id="validation" class="status" aria-live="polite"></div>
    </section>

    <section class="result-section" id="result-area" aria-labelledby="result-title">
        <h2 id="result-title" style="margin-top:0">Результат — файл EEPROM</h2>
        <p id="result-empty" class="result-empty">После ввода параметров нажмите «Сформировать eeprom.hex». Будет подготовлен новый дамп Intel HEX для записи в память EEPROM микроконтроллера.</p>
        <div id="result-ready" class="result-ready" aria-live="polite">
            <div class="result-heading">Файл <code>eeprom.hex</code> готов</div>
            <div id="result-summary" class="result-summary"></div>
            <pre id="hex-preview" class="hex-preview"></pre>
            <a id="download-eeprom" class="download-button" href="#" download="eeprom.hex">Скачать eeprom.hex</a>
        </div>
    </section>

    <section class="firmware-section" aria-labelledby="firmware-title">
        <h2 id="firmware-title" style="margin-top:0">Готовые прошивки микроконтроллера</h2>
        <p class="firmware-intro">Выберите файл под установленный тип синтезатора и микроконтроллер. Сначала установите фьюзы из соответствующей строки таблицы, затем запишите прошивку во Flash, после этого отдельно запишите сформированный выше <code>eeprom.hex</code> в EEPROM.</p>

        <div class="table-wrap" id="firmware-table-wrap" tabindex="0" role="region" aria-label="Таблица готовых прошивок; на узком экране прокручивается по горизонтали">
            <table class="firmware-table" id="firmware-table">
                <thead>
                    <tr><th>Синтезатор</th><th>Микроконтроллер</th><th>Фьюзы</th><th>Прошивка</th></tr>
                </thead>
                <tbody>
                <?php foreach ($firmwares as $firmware): ?>
                    <tr>
                        <td><?= h($firmware['synth']) ?></td>
                        <td><?= h($firmware['mcu']) ?></td>
                        <td class="fuses">Low <code><?= h($firmware['low']) ?></code><br>High <code><?= h($firmware['high']) ?></code><br>Extended <code><?= h($firmware['extended']) ?></code></td>
                        <td class="firmware-name">
                            <?php if ($firmware['exists']): ?>
                                <a href="<?= h(rawurlencode($firmware['file'])) ?>" download><?= h($firmware['file']) ?></a>
                            <?php else: ?>
                                <span class="file-missing">Файл ещё не загружен: <code><?= h($firmware['file']) ?></code></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="table-scrollbar-wrap" id="firmware-scrollbar-wrap" hidden>
            <div class="table-scrollbar-label">Таблица шире экрана — прокрутите вправо, чтобы увидеть остальные столбцы:</div>
            <div class="table-scrollbar-track" id="firmware-scrollbar" role="scrollbar"
                 aria-label="Горизонтальная прокрутка таблицы прошивок" aria-controls="firmware-table-wrap"
                 aria-orientation="horizontal" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" tabindex="0">
                <div class="table-scrollbar-thumb" id="firmware-scrollbar-thumb"></div>
            </div>
        </div>

        <p class="important-warning warning-bottom"><strong>Внимание!</strong> Микроконтроллер и Si5351 на плате питаются от 3,3 В. Перед подключением программатора USBASP установите на нём питание 3,3 В. Для прошивки используйте AVRDUDE_PROG версии 5. Порядок программирования: сначала установите фьюзы из соответствующей строки таблицы, затем запишите прошивку во Flash, после этого отдельно запишите EEPROM. При Low = 62 системная частота МК составляет 1 МГц, поэтому частота ISP/SCK программатора должна быть ниже 250 кГц.</p>
    </section>

    <nav class="page-nav page-nav-bottom" aria-label="Описание проекта внизу страницы">
        <a class="project-link" href="https://karat3.ru/synth_micro/">← Описание платы и проекта «Синтезатор-МИКРО»</a>
    </nav>
</main>

<script>
(() => {
    'use strict';

    const $ = id => document.getElementById(id);
    const MIN_HZ = 8000;
    const MAX_HZ = 160000000;
    let generatedUrl = null;

    const modelDescriptions = {
        FM5: {
            title: '1freq-FM5 — две фиксированные частоты',
            text: 'Генерирует одну из двух заранее заданных частот. Выбор выполняется кнопкой или тумблером по входу «C», замыкаемому на землю; вход имеет внутреннюю подтяжку к 3,3 В. Подходит для замены двух кварцевых частот.'
        },
        FM6: {
            title: '1freq-FM6 — перестройка в заданном диапазоне',
            text: 'Позволяет перестраивать частоту в пределах заданной ширины диапазона. Управление — потенциометром либо переключателем с набором резисторов между 0 и 3,3 В. Подходит, когда требуется плавная или ступенчатая перестройка, а не только выбор одной из двух частот.'
        }
    };

    function setStatus(id, message, kind = '') {
        const node = $(id);
        node.textContent = message;
        node.className = 'status' + (kind ? ' ' + kind : '');
    }

    function clearGeneratedResult() {
        if (generatedUrl) {
            URL.revokeObjectURL(generatedUrl);
            generatedUrl = null;
        }
        $('result-ready').classList.remove('visible');
        $('result-empty').hidden = false;
        $('hex-preview').textContent = '';
        $('result-summary').textContent = '';
    }

    function rememberSelectedUnits() {
        ['up-unit', 'dn-unit', 'fr-unit', 'bw-unit'].forEach(id => {
            $(id).dataset.previousUnit = $(id).value;
        });
    }

    function clearParameterFields() {
        ['up', 'dn', 'fr', 'bw', 'st'].forEach(id => { $(id).value = ''; });
        $('up-unit').value = '1000000';
        $('dn-unit').value = '1000000';
        $('fr-unit').value = '1000000';
        $('bw-unit').value = '1000';
        rememberSelectedUnits();
    }

    function clearSelectedFile() {
        $('hex-file').value = '';
        $('file-tools').hidden = true;
        setStatus('file-status', '');
    }

    function resetPageFields() {
        clearParameterFields();
        clearSelectedFile();
        setStatus('validation', '');
        clearGeneratedResult();
    }

    function updateModel() {
        const model = $('model').value;
        const description = modelDescriptions[model];
        $('model-description').innerHTML = '';
        const heading = document.createElement('h3');
        heading.textContent = description.title;
        const paragraph = document.createElement('p');
        paragraph.textContent = description.text;
        $('model-description').append(heading, paragraph);
        $('fm5-fields').hidden = model !== 'FM5';
        $('fm6-fields').hidden = model !== 'FM6';
        resetPageFields();
    }

    function parseDecimalInput(id, description) {
        const raw = $(id).value.trim().replace(',', '.');
        if (!raw || !/^\d+(?:\.\d+)?$/.test(raw)) {
            throw new Error('Введите корректное значение: ' + description + '.');
        }
        const value = Number(raw);
        if (!Number.isFinite(value)) throw new Error('Некорректное значение: ' + description + '.');
        return value;
    }

    function toIntegerHz(inputId, unitId, description, allowZero = false, checkFrequencyLimits = true) {
        const value = parseDecimalInput(inputId, description) * Number($(unitId).value);
        if (!Number.isFinite(value) || value > Number.MAX_SAFE_INTEGER) {
            throw new Error('Слишком большое значение: ' + description + '.');
        }
        const hz = Math.round(value);
        if (allowZero && hz === 0) return hz;
        // Для отдельных частот действует диапазон Si5351. Для BW проверяются
        // не сама ширина, а фактические нижняя и верхняя частоты диапазона ниже.
        if (checkFrequencyLimits && (hz < MIN_HZ || hz > MAX_HZ)) {
            throw new Error(description + ': допустимо от 8 000 Гц до 160 000 000 Гц включительно.');
        }
        return hz;
    }

    function makeRecord(address, data) {
        const record = [data.length, (address >> 8) & 0xFF, address & 0xFF, 0, ...data];
        const sum = record.reduce((total, byte) => total + byte, 0) & 0xFF;
        const checksum = (-sum) & 0xFF;
        return ':' + [...record, checksum].map(byte => byte.toString(16).padStart(2, '0')).join('').toUpperCase();
    }

    function makeParameterBlock(label, value) {
        const text = label + '=' + String(value);
        const bytes = Array.from(text, character => character.charCodeAt(0));
        if (bytes.length >= 16) throw new Error('Значение ' + label + ' не помещается в область EEPROM.');
        bytes.push(0);
        while (bytes.length < 16) bytes.push(0);
        return bytes;
    }

    function buildIntelHex(model, values) {
        let lines;
        if (model === 'FM5') {
            lines = [
                makeRecord(0x0000, makeParameterBlock('UP', values.up)),
                makeRecord(0x0010, makeParameterBlock('DN', values.dn))
            ];
        } else {
            lines = [
                makeRecord(0x0000, makeParameterBlock('FR', values.fr)),
                makeRecord(0x0010, makeParameterBlock('BW', values.bw)),
                makeRecord(0x0020, makeParameterBlock('ST', values.st))
            ];
        }
        lines.push(':00000001FF');
        return lines.join('\r\n') + '\r\n';
    }

    function formatHz(value) {
        return value.toLocaleString('ru-RU') + ' Гц';
    }

    function generateEeprom() {
        try {
            const model = $('model').value;
            let values;
            let summary;

            if (model === 'FM5') {
                const up = toIntegerHz('up', 'up-unit', 'частота UP');
                const dn = toIntegerHz('dn', 'dn-unit', 'частота DN');
                values = { up, dn };
                summary = 'UP = ' + formatHz(up) + '\nDN = ' + formatHz(dn);
            } else {
                const fr = toIntegerHz('fr', 'fr-unit', 'центральная частота FR');
                const bw = toIntegerHz('bw', 'bw-unit', 'ширина диапазона BW', true, false);
                const stepText = $('st').value.trim();
                if (!/^\d+$/.test(stepText)) throw new Error('Введите целое число шагов ST от 0 до 1024.');
                const st = Number(stepText);
                if (!Number.isSafeInteger(st) || st < 0 || st > 1024) {
                    throw new Error('Количество шагов ST должно быть целым числом от 0 до 1024.');
                }

                // Прошивка использует FreqWidth / 2 с целочисленным делением,
                // поэтому при нечётном BW верхняя граница на 1 Гц шире нижней.
                const low = fr - Math.floor(bw / 2);
                const high = low + bw;
                if (low < MIN_HZ || high > MAX_HZ) {
                    throw new Error('Диапазон FM6 выходит за пределы 8 000–160 000 000 Гц. Нижняя граница: ' + formatHz(low) + ', верхняя: ' + formatHz(high) + '.');
                }
                values = { fr, bw, st };
                summary = 'FR = ' + formatHz(fr) + '\nBW = ' + formatHz(bw) + '\nST = ' + st + (st === 0 ? ' (1024 шага)' : ' шагов') + '\nДиапазон: ' + formatHz(low) + ' — ' + formatHz(high);
            }

            const hex = buildIntelHex(model, values);
            if (generatedUrl) URL.revokeObjectURL(generatedUrl);
            generatedUrl = URL.createObjectURL(new Blob([hex], { type: 'text/plain;charset=us-ascii' }));
            $('download-eeprom').href = generatedUrl;
            $('result-summary').textContent = summary;
            $('hex-preview').textContent = hex.trimEnd();
            $('result-empty').hidden = true;
            $('result-ready').classList.add('visible');
            setStatus('validation', 'Параметры проверены. Новый файл eeprom.hex сформирован.', 'ok');
        } catch (error) {
            setStatus('validation', error.message, 'error');
            clearGeneratedResult();
        }
    }

    function parseIntelHex(source) {
        const lines = source.replace(/^\uFEFF/, '').split(/\r?\n/);
        const memory = new Map();
        let upperAddress = 0;
        let eof = false;
        let recordCount = 0;

        for (const originalLine of lines) {
            const line = originalLine.trim();
            if (!line) continue;
            if (eof) throw new Error('В файле обнаружены данные после завершающей записи EOF.');
            if (!/^:[0-9a-fA-F]+$/.test(line) || line.length < 11 || line.length % 2 !== 1) {
                throw new Error('Неверная строка Intel HEX: ' + line.slice(0, 36));
            }

            const bytes = line.slice(1).match(/.{2}/g).map(pair => parseInt(pair, 16));
            const byteCount = bytes[0];
            const address = (bytes[1] << 8) | bytes[2];
            const type = bytes[3];
            if (bytes.length !== byteCount + 5) throw new Error('Не совпадает длина записи Intel HEX.');
            if ((bytes.reduce((sum, byte) => sum + byte, 0) & 0xFF) !== 0) {
                throw new Error('Ошибка контрольной суммы Intel HEX.');
            }
            recordCount++;

            if (type === 0x00) {
                const absoluteAddress = upperAddress + address;
                if (absoluteAddress < 0 || absoluteAddress + byteCount > 0x10000) {
                    throw new Error('Адрес данных выходит за пределы EEPROM микроконтроллера.');
                }
                for (let index = 0; index < byteCount; index++) {
                    const key = absoluteAddress + index;
                    if (memory.has(key)) throw new Error('В Intel HEX повторяется адрес данных 0x' + key.toString(16).toUpperCase() + '.');
                    memory.set(key, bytes[4 + index]);
                }
            } else if (type === 0x01) {
                if (byteCount !== 0 || address !== 0) throw new Error('Некорректная завершающая запись EOF.');
                eof = true;
            } else if (type === 0x02) {
                if (byteCount !== 2 || address !== 0) throw new Error('Некорректная запись расширенного сегментного адреса.');
                upperAddress = ((bytes[4] << 8) | bytes[5]) << 4;
            } else if (type === 0x04) {
                if (byteCount !== 2 || address !== 0) throw new Error('Некорректная запись расширенного линейного адреса.');
                upperAddress = ((bytes[4] << 8) | bytes[5]) << 16;
            } else if (type === 0x03 || type === 0x05) {
                if (byteCount !== 4 || address !== 0) throw new Error('Некорректная запись начального адреса Intel HEX.');
                // Начальный адрес программы для EEPROM-конфигуратора не используется.
            } else {
                throw new Error('Неподдерживаемый тип записи Intel HEX: ' + type.toString(16).toUpperCase());
            }
        }

        if (!recordCount || !eof) throw new Error('Файл не содержит корректной завершающей записи EOF.');
        return memory;
    }

    function readParameterBlock(memory, address, label) {
        const bytes = [];
        for (let index = 0; index < 16; index++) {
            const key = address + index;
            if (!memory.has(key)) throw new Error('В файле отсутствует область EEPROM ' + label + ' (адрес 0x' + address.toString(16).toUpperCase() + ').');
            bytes.push(memory.get(key));
        }

        const expected = label + '=';
        const actual = String.fromCharCode(bytes[0], bytes[1], bytes[2]);
        if (actual !== expected) throw new Error('По адресу 0x' + address.toString(16).toUpperCase() + ' не найдена метка ' + expected + '.');

        let digits = '';
        let terminated = false;
        for (const byte of bytes.slice(3)) {
            if (byte === 0) {
                terminated = true;
                continue;
            }
            if (terminated || byte < 0x30 || byte > 0x39) {
                throw new Error('Область ' + label + ' содержит повреждённые данные.');
            }
            digits += String.fromCharCode(byte);
        }
        if (!digits) throw new Error('Параметр ' + label + ' пуст.');
        if (digits.length > 10 || !Number.isSafeInteger(Number(digits))) throw new Error('Параметр ' + label + ' слишком велик.');
        return digits;
    }

    function setFrequencyFromFile(inputId, unitId, hzText) {
        $(inputId).value = hzText;
        $(unitId).value = '1';
        $(unitId).dataset.previousUnit = '1';
    }

    // При смене единиц пересчитываем отображаемое число, сохраняя физическую
    // частоту. Внутри страницы и в EEPROM частота по-прежнему хранится в герцах.
    function convertFrequencyUnit(inputId, unitId) {
        const input = $(inputId);
        const select = $(unitId);
        const previousUnit = Number(select.dataset.previousUnit || select.value);
        const newUnit = Number(select.value);

        if (previousUnit === newUnit) return;

        const raw = input.value.trim();
        if (!raw) {
            select.dataset.previousUnit = String(newUnit);
            return;
        }

        try {
            const displayedValue = parseDecimalInput(inputId, 'частоту');
            const hz = Math.round(displayedValue * previousUnit);
            const convertedValue = hz / newUnit;
            const precision = newUnit === 1 ? 0 : (newUnit === 1000 ? 3 : 6);
            let formatted = convertedValue.toFixed(precision);
            if (formatted.includes('.')) {
                formatted = formatted.replace(/0+$/, '').replace(/\.$/, '');
            }
            formatted = formatted.replace('.', ',');
            input.value = formatted;
            select.dataset.previousUnit = String(newUnit);
        } catch (error) {
            // Не меняем смысл некорректно введённого значения: возвращаем
            // предыдущую единицу и просим сначала исправить поле.
            select.value = String(previousUnit);
            setStatus('validation', error.message + ' Сначала исправьте значение, затем смените единицы.', 'error');
        }
    }

    async function loadEepromFile(file) {
        clearParameterFields();
        clearGeneratedResult();
        setStatus('validation', '');
        try {
            if (file.size > 65536) throw new Error('Файл EEPROM слишком большой. Ожидается небольшой дамп Intel HEX (не более 64 КБ).');
            const memory = parseIntelHex(await file.text());
            const firstLabel = [memory.get(0), memory.get(1), memory.get(2)].map(value => value === undefined ? '' : String.fromCharCode(value)).join('');
            let detectedModel;
            let values;

            if (firstLabel === 'UP=') {
                detectedModel = 'FM5';
                values = {
                    up: readParameterBlock(memory, 0x0000, 'UP'),
                    dn: readParameterBlock(memory, 0x0010, 'DN')
                };
            } else if (firstLabel === 'FR=') {
                detectedModel = 'FM6';
                values = {
                    fr: readParameterBlock(memory, 0x0000, 'FR'),
                    bw: readParameterBlock(memory, 0x0010, 'BW'),
                    st: readParameterBlock(memory, 0x0020, 'ST')
                };
            } else {
                throw new Error('Не удалось определить тип EEPROM. В начале файла не найдена метка UP= или FR=.');
            }

            if ($('model').value !== detectedModel) {
                throw new Error('В загруженном файле обнаружен ' + detectedModel + ', а выбран ' + $('model').value + '. Выберите соответствующий тип синтезатора и загрузите файл повторно.');
            }

            if (detectedModel === 'FM5') {
                setFrequencyFromFile('up', 'up-unit', values.up);
                setFrequencyFromFile('dn', 'dn-unit', values.dn);
            } else {
                setFrequencyFromFile('fr', 'fr-unit', values.fr);
                setFrequencyFromFile('bw', 'bw-unit', values.bw);
                $('st').value = values.st;
            }

            setStatus('file-status', 'Файл «' + file.name + '» прочитан. Найденные значения загружены в поля — их можно изменить.', 'ok');
            $('file-tools').hidden = false;
        } catch (error) {
            setStatus('file-status', error.message, 'error');
            $('file-tools').hidden = false;
        }
    }

    // Постоянно видимая полоса прокрутки для таблицы прошивок.
    // На мобильных браузерах системная полоса часто скрывается автоматически,
    // поэтому этот индикатор остаётся видимым, пока таблица шире области просмотра.
    function initFirmwareTableScrollbar() {
        const tableWrap = $('firmware-table-wrap');
        const table = $('firmware-table');
        const scrollbarWrap = $('firmware-scrollbar-wrap');
        const scrollbar = $('firmware-scrollbar');
        const thumb = $('firmware-scrollbar-thumb');
        if (!tableWrap || !table || !scrollbarWrap || !scrollbar || !thumb) return;

        let dragState = null;

        function metrics() {
            const maxScroll = Math.max(0, tableWrap.scrollWidth - tableWrap.clientWidth);
            const trackWidth = scrollbar.clientWidth;
            const thumbWidth = Math.min(trackWidth, Math.max(40, trackWidth * tableWrap.clientWidth / Math.max(1, tableWrap.scrollWidth)));
            const maxThumbLeft = Math.max(0, trackWidth - thumbWidth);
            return { maxScroll, trackWidth, thumbWidth, maxThumbLeft };
        }

        function updateScrollbar() {
            const m = metrics();
            const hasOverflow = m.maxScroll > 1;
            scrollbarWrap.hidden = !hasOverflow;
            if (!hasOverflow) return;

            const scrollRatio = tableWrap.scrollLeft / m.maxScroll;
            const thumbLeft = Math.max(0, Math.min(m.maxThumbLeft, scrollRatio * m.maxThumbLeft));
            thumb.style.width = m.thumbWidth + 'px';
            thumb.style.transform = 'translateX(' + thumbLeft + 'px)';
            scrollbar.setAttribute('aria-valuenow', String(Math.round(scrollRatio * 100)));
        }

        function setScrollFromThumb(left) {
            const m = metrics();
            if (m.maxThumbLeft <= 0 || m.maxScroll <= 0) {
                tableWrap.scrollLeft = 0;
                return;
            }
            const clampedLeft = Math.max(0, Math.min(m.maxThumbLeft, left));
            tableWrap.scrollLeft = (clampedLeft / m.maxThumbLeft) * m.maxScroll;
        }

        tableWrap.addEventListener('scroll', updateScrollbar, { passive: true });
        window.addEventListener('resize', updateScrollbar);

        scrollbar.addEventListener('pointerdown', event => {
            if (event.pointerType === 'mouse' && event.button !== 0) return;
            if (scrollbarWrap.hidden) return;

            const trackRect = scrollbar.getBoundingClientRect();
            const thumbRect = thumb.getBoundingClientRect();
            const onThumb = event.target === thumb || thumb.contains(event.target);
            const m = metrics();
            const currentLeft = m.maxScroll > 0
                ? (tableWrap.scrollLeft / m.maxScroll) * m.maxThumbLeft
                : 0;
            const pointerOffset = onThumb ? event.clientX - thumbRect.left : m.thumbWidth / 2;
            const startLeft = onThumb
                ? currentLeft
                : Math.max(0, Math.min(m.maxThumbLeft, event.clientX - trackRect.left - pointerOffset));

            if (!onThumb) setScrollFromThumb(startLeft);
            dragState = { pointerId: event.pointerId, startX: event.clientX, startLeft };
            scrollbar.setPointerCapture(event.pointerId);
            event.preventDefault();
        });

        scrollbar.addEventListener('pointermove', event => {
            if (!dragState || dragState.pointerId !== event.pointerId) return;
            setScrollFromThumb(dragState.startLeft + event.clientX - dragState.startX);
            event.preventDefault();
        });

        function stopDragging(event) {
            if (!dragState || (event && dragState.pointerId !== event.pointerId)) return;
            dragState = null;
        }
        scrollbar.addEventListener('pointerup', stopDragging);
        scrollbar.addEventListener('pointercancel', stopDragging);
        scrollbar.addEventListener('lostpointercapture', stopDragging);

        scrollbar.addEventListener('keydown', event => {
            const page = Math.max(80, tableWrap.clientWidth * 0.8);
            if (event.key === 'ArrowLeft') tableWrap.scrollLeft -= 40;
            else if (event.key === 'ArrowRight') tableWrap.scrollLeft += 40;
            else if (event.key === 'PageUp') tableWrap.scrollLeft -= page;
            else if (event.key === 'PageDown') tableWrap.scrollLeft += page;
            else if (event.key === 'Home') tableWrap.scrollLeft = 0;
            else if (event.key === 'End') tableWrap.scrollLeft = tableWrap.scrollWidth;
            else return;
            event.preventDefault();
            updateScrollbar();
        });

        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(updateScrollbar);
            observer.observe(tableWrap);
            observer.observe(table);
        }
        updateScrollbar();
    }

    initFirmwareTableScrollbar();

    $('model').addEventListener('change', updateModel);
    $('hex-file').addEventListener('change', event => {
        const file = event.target.files && event.target.files[0];
        if (file) loadEepromFile(file);
    });
    $('clear-file').addEventListener('click', event => {
        event.preventDefault();
        clearSelectedFile();
        clearGeneratedResult();
        setStatus('validation', '');
        setStatus('file-status', 'Загруженный файл отключён. Значения в полях сохранены.', 'ok');
    });
    $('generate').addEventListener('click', generateEeprom);

    $('reset-fields').addEventListener('click', event => {
        event.preventDefault();
        resetPageFields();
    });

    // После любого изменения параметров ранее сформированный файл скрывается,
    // чтобы пользователь случайно не скачал результат от предыдущих значений.
    ['up', 'dn', 'fr', 'bw', 'st', 'up-unit', 'dn-unit', 'fr-unit', 'bw-unit'].forEach(id => {
        $(id).addEventListener('input', () => {
            clearGeneratedResult();
            setStatus('validation', '');
        });
        $(id).addEventListener('change', () => {
            clearGeneratedResult();
            setStatus('validation', '');
        });
    });

    // Единицы UP, DN, FR и BW независимы: смена единиц в одном поле
    // пересчитывает только его значение, не затрагивая остальные параметры.
    [
        ['up', 'up-unit'],
        ['dn', 'dn-unit'],
        ['fr', 'fr-unit'],
        ['bw', 'bw-unit']
    ].forEach(([inputId, unitId]) => {
        $(unitId).addEventListener('change', () => convertFrequencyUnit(inputId, unitId));
    });

    // При первом открытии параметры намеренно пустые: файл EEPROM необязателен.
    updateModel();
})();
</script>
</body>
</html>
