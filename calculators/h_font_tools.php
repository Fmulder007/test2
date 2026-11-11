<?php
declare(strict_types=1);

function hf_page_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Создатель и редактор шрифтов .h — радиолюбительские калькуляторы</title>
    <meta name="description" content="Пиксельный редактор символов и генератор заголовочных файлов C/C++ для LCD, OLED, Arduino, U8g2 и Adafruit GFX.">
    <link rel="stylesheet" href="h_font_tools/editor.css?v=2">
    <script src="h_font_tools/editor.js?v=2" defer></script>
</head>
<body>
<main class="hf-main">
    <section class="hf-panel hf-heading">
        <div class="hf-heading-top">
            <div>
                <h1>Создатель / редактор шрифтов .h</h1>
                <p class="hf-subtle">Рисуй пиксельные символы, собирай собственный шрифт и экспортируй готовый заголовок для прошивки C/C++.</p>
            </div>
            <a class="hf-back" href="index.php">← К списку калькуляторов</a>
        </div>
    </section>

    <section class="hf-panel">
        <div class="hf-section-title">
            <div>
                <h2>Параметры шрифта</h2>
                <p class="hf-subtle">Размер относится к каждому символу. При изменении размеров уже нарисованные символы будут аккуратно масштабированы.</p>
            </div>
            <div class="hf-project-actions">
                <button type="button" class="hf-button hf-button-light" id="hf-new-project">Новый проект</button>
                <button type="button" class="hf-button hf-button-light" id="hf-save-project">Сохранить проект JSON</button>
                <button type="button" class="hf-button hf-button-light" id="hf-load-project">Открыть проект JSON</button>
                <input type="file" id="hf-project-file" accept=".json,application/json" hidden>
            </div>
        </div>

        <div class="hf-settings-grid">
            <label class="hf-field"><span>Ширина, px</span><input type="number" id="hf-width" min="5" max="32" value="8"></label>
            <label class="hf-field"><span>Высота, px</span><input type="number" id="hf-height" min="5" max="32" value="8"></label>
            <label class="hf-field"><span>Интервал, px</span><input type="number" id="hf-spacing" min="0" max="16" value="1"></label>
            <label class="hf-field"><span>Имя шрифта / префикс</span><input type="text" id="hf-name" maxlength="32" value="my_font" autocomplete="off"></label>
        </div>

        <div class="hf-selection-mode">
            <span class="hf-selection-label">Состав набора</span>
            <div class="hf-mode-tabs" role="group" aria-label="Способ выбора символов">
                <button type="button" class="hf-mode-tab is-active" data-selection-mode="range" aria-pressed="true">Диапазон</button>
                <button type="button" class="hf-mode-tab" data-selection-mode="manual" aria-pressed="false">Вручную</button>
            </div>
        </div>

        <div id="hf-range-controls">
            <div class="hf-range-row">
                <label class="hf-field hf-range-preset"><span>Набор символов</span>
                    <select id="hf-preset">
                        <option value="ascii">ASCII — 32…126</option>
                        <option value="latin">Расширенная Latin — 32…255</option>
                        <option value="cyrillic">Кириллица — U+0400…U+045F</option>
                        <option value="custom">Свой диапазон</option>
                    </select>
                </label>
                <label class="hf-field"><span>Первый код</span><input type="number" id="hf-range-start" min="0" max="65535" value="32"></label>
                <label class="hf-field"><span>Последний код</span><input type="number" id="hf-range-end" min="0" max="65535" value="126"></label>
                <button type="button" class="hf-button hf-button-secondary hf-range-apply" id="hf-apply-range">Применить диапазон</button>
            </div>
            <p class="hf-help">В диапазон попадут все символы между первым и последним кодом. Поддерживается до 512 позиций Unicode BMP.</p>
        </div>

        <div class="hf-manual-controls" id="hf-manual-controls" hidden>
            <div class="hf-manual-row">
                <label class="hf-field">
                    <span>Нужные символы</span>
                    <input type="text" id="hf-manual-chars" maxlength="512" placeholder="Например: UL или ABC123" autocomplete="off" spellcheck="false">
                </label>
                <button type="button" class="hf-button hf-button-secondary" id="hf-apply-manual">Применить символы</button>
            </div>
            <p class="hf-help">Просто введи знаки подряд — например <strong>UL</strong>. Коды определятся автоматически. Повторы будут убраны; пробел тоже считается символом. Поддерживаются символы Unicode BMP (до U+FFFF).</p>
            <div class="hf-manual-summary" id="hf-manual-summary">Набор пока не изменён. Введи символы и нажми «Применить символы».</div>
        </div>
    </section>

    <section class="hf-workspace">
        <div class="hf-panel hf-editor-panel">
            <div class="hf-section-title">
                <div>
                    <h2>Редактор символа</h2>
                </div>
                <div class="hf-current-code"><span id="hf-current-char">Пробел</span><small id="hf-current-code">Код: 32 (0x0020)</small></div>
            </div>

            <div class="hf-char-nav">
                <button type="button" class="hf-button hf-button-light hf-square" id="hf-prev-char" aria-label="Предыдущий символ">←</button>
                <label class="hf-field hf-code-field"><span>Код символа</span><input type="number" id="hf-char-code" min="0" max="65535" value="32"></label>
                <button type="button" class="hf-button hf-button-light hf-square" id="hf-next-char" aria-label="Следующий символ">→</button>
            </div>

            <div class="hf-tool-row">
                <button type="button" class="hf-tool is-active" data-tool="draw">Карандаш</button>
                <button type="button" class="hf-tool" data-tool="erase">Ластик</button>
                <span class="hf-tool-divider"></span>
                <button type="button" class="hf-button hf-button-light" id="hf-clear-glyph">Очистить</button>
                <button type="button" class="hf-button hf-button-light" id="hf-fill-glyph">Заполнить</button>
            </div>

            <div class="hf-canvas-wrap">
                <div class="hf-pixel-grid" id="hf-pixel-grid" role="grid" aria-label="Сетка редактирования символа"></div>
            </div>
            <div class="hf-grid-caption"><span id="hf-grid-dimensions">8 × 8 px</span><span id="hf-pixel-count">0 включённых пикселей</span></div>

            <div class="hf-transform-row">
                <button type="button" class="hf-button hf-button-light" id="hf-invert">Инвертировать</button>
                <button type="button" class="hf-button hf-button-light" id="hf-mirror-h">Отразить ↔</button>
                <button type="button" class="hf-button hf-button-light" id="hf-mirror-v">Отразить ↕</button>
                <span class="hf-tool-divider"></span>
                <button type="button" class="hf-button hf-button-light" id="hf-shift-up" title="Сдвинуть на один пиксель вверх">↑ Вверх</button>
                <button type="button" class="hf-button hf-button-light" id="hf-shift-down" title="Сдвинуть на один пиксель вниз">↓ Вниз</button>
                <button type="button" class="hf-button hf-button-light" id="hf-shift-left" title="Сдвинуть на один пиксель влево">← Влево</button>
                <button type="button" class="hf-button hf-button-light" id="hf-shift-right" title="Сдвинуть на один пиксель вправо">→ Вправо</button>
            </div>
        </div>

        <div class="hf-panel hf-library-panel">
            <div class="hf-section-title">
                <div>
                    <h2>Таблица символов</h2>
                    <p class="hf-subtle">Выбери символ для редактирования.</p>
                </div>
                <span class="hf-count" id="hf-char-count">95 символов</span>
            </div>
            <div class="hf-character-map" id="hf-character-map"></div>
            <div class="hf-map-legend"><span class="hf-legend-dot"></span> Выбранный символ</div>

            <div class="hf-separator"></div>
            <div class="hf-section-title">
                <div>
                    <h2>Быстро создать набор</h2>
                    <p class="hf-subtle">Преобразовать системный шрифт браузера в пиксельные символы.</p>
                </div>
            </div>
            <div class="hf-raster-row">
                <label class="hf-field"><span>Гарнитура</span>
                    <select id="hf-source-font">
                        <option value="monospace">Monospace</option>
                        <option value="sans-serif">Sans-serif</option>
                        <option value="serif">Serif</option>
                    </select>
                </label>
                <div class="hf-raster-actions">
                    <button type="button" class="hf-button hf-button-secondary" id="hf-rasterize">Создать весь набор</button>
                    <button type="button" class="hf-button hf-button-light" id="hf-rasterize-selected" disabled>Создать отмеченные символы</button>
                    <span class="hf-raster-selection-count" id="hf-raster-selection-count">Отмечено: 0</span>
                </div>
            </div>
            <p class="hf-help">Для выборочного создания отметь нужные знаки галочкой в таблице символов. Будут перерисованы только отмеченные знаки, остальные останутся без изменений.</p>
        </div>
    </section>

    <section class="hf-panel hf-preview-panel">
        <div class="hf-section-title">
            <div>
                <h2>Предварительный просмотр</h2>
                <p class="hf-subtle">Текст отображается из пикселей твоего шрифта, а не системным шрифтом.</p>
            </div>
        </div>
        <label class="hf-field hf-preview-input"><span>Текст для проверки</span><input type="text" id="hf-preview-text" value="HELLO 123" maxlength="80"></label>
        <div class="hf-preview-stage" id="hf-preview-stage" aria-live="polite"></div>
    </section>

    <section class="hf-panel hf-export-panel">
        <div class="hf-section-title">
            <div>
                <h2>Экспорт заголовочного файла</h2>
                <p class="hf-subtle">Выбери библиотеку или формат данных. Содержимое .h можно посмотреть, скопировать или скачать.</p>
            </div>
        </div>
        <div class="hf-export-controls">
            <label class="hf-field"><span>Формат</span>
                <select id="hf-export-format">
                    <option value="generic">Универсальный bitmap / PROGMEM</option>
                    <option value="u8g2">U8g2 — drawXBMP()</option>
                    <option value="adafruit">Adafruit GFX — GFXfont</option>
                    <option value="hd44780">HD44780 — CGRAM 5×8</option>
                </select>
            </label>
            <div class="hf-export-buttons">
                <button type="button" class="hf-button hf-button-secondary" id="hf-generate-code">Сформировать код</button>
                <button type="button" class="hf-button" id="hf-download-header">Скачать .h</button>
                <button type="button" class="hf-button hf-button-light" id="hf-copy-code">Копировать код</button>
            </div>
        </div>
        <div class="hf-export-note" id="hf-export-note">Универсальный формат хранит биты построчно, старший бит — левый пиксель. Для чтения из PROGMEM используй макрос H_FONT_READ_BYTE.</div>
        <textarea class="hf-code-output" id="hf-code-output" spellcheck="false" readonly aria-label="Сгенерированный код C++" placeholder="Нажми «Сформировать код»…"></textarea>
        <div class="hf-status" id="hf-status" role="status" aria-live="polite">Готово к редактированию.</div>
    </section>

    <section class="hf-panel hf-notes">
        <h2>Примечания к форматам</h2>
        <div class="hf-format-notes">
            <p><strong>Универсальный bitmap:</strong> подходит для собственных функций вывода. Байты хранят строки отдельно, ширина строки дополняется до целого числа байтов.</p>
            <p><strong>U8g2:</strong> экспортируются XBM-массивы с младшим битом слева и функция отрисовки отдельных символов через <code>drawXBMP()</code>.</p>
            <p><strong>Adafruit GFX:</strong> для диапазона создаётся структура <code>GFXfont</code>, а для разрозненного ручного набора — компактные bitmap-массивы и функция <code>drawBitmap()</code>. UTF-8 строка автоматически не декодируется.</p>
            <p><strong>HD44780:</strong> контроллер принимает не более восьми пользовательских знаков одновременно. При экспорте берутся первые восемь непустых символов выбранного набора и масштабируются до 5×8.</p>
        </div>
    </section>

    <footer class="hf-footer">Инструмент работает локально в браузере. Проекты и шрифты не отправляются на сервер.</footer>
</main>
</body>
</html>
