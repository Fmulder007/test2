<?php
/*
 * Калькулятор пассивных компонентов:
 * параллельное соединение двух резисторов и последовательное соединение
 * двух конденсаторов. Поддерживает расчёт общей величины и неизвестного элемента.
 * Не требует базы данных, внешних библиотек или JavaScript.
 */
declare(strict_types=1);

function pc_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Преобразует введённое число с точкой или запятой в положительное конечное число. */
function pc_positiveNumber(string $raw): ?float
{
    $raw = str_replace([' ', "\u{00A0}"], '', trim($raw));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $value = (float) $raw;
    return ($value > 0.0 && is_finite($value)) ? $value : null;
}

/** Коэффициенты перевода в омы. */
function pc_resistorFactor(string $unit): float
{
    return match ($unit) {
        'kohm' => 1_000.0,
        'mohm' => 1_000_000.0,
        default => 1.0,
    };
}

/** Коэффициенты перевода в фарады. */
function pc_capacitorFactor(string $unit): float
{
    return match ($unit) {
        'pf' => 1e-12,
        'nf' => 1e-9,
        'uf' => 1e-6,
        'mf' => 1e-3,
        default => 1.0,
    };
}

/** Число с русской запятой; без лишних нулей справа. */
function pc_formatNumber(float $value, int $maxDecimals = 6): string
{
    if (!is_finite($value)) {
        return '—';
    }
    $formatted = number_format($value, $maxDecimals, ',', '');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    return ($formatted === '' || $formatted === '-0') ? '0' : $formatted;
}

/** Выбирает удобный масштаб отображения сопротивления. */
function pc_formatResistance(float $ohms): string
{
    if ($ohms >= 1_000_000.0) {
        return pc_formatNumber($ohms / 1_000_000.0) . ' МОм';
    }
    if ($ohms >= 1_000.0) {
        return pc_formatNumber($ohms / 1_000.0) . ' кОм';
    }
    if ($ohms >= 1.0) {
        return pc_formatNumber($ohms) . ' Ом';
    }
    if ($ohms >= 0.001) {
        return pc_formatNumber($ohms * 1_000.0) . ' мОм';
    }
    return pc_formatNumber($ohms * 1_000_000.0) . ' мкОм';
}

/** Выбирает удобный масштаб отображения ёмкости. */
function pc_formatCapacitance(float $farads): string
{
    if ($farads >= 1.0) {
        return pc_formatNumber($farads) . ' Ф';
    }
    if ($farads >= 1e-3) {
        return pc_formatNumber($farads / 1e-3) . ' мФ';
    }
    if ($farads >= 1e-6) {
        return pc_formatNumber($farads / 1e-6) . ' мкФ';
    }
    if ($farads >= 1e-9) {
        return pc_formatNumber($farads / 1e-9) . ' нФ';
    }
    return pc_formatNumber($farads / 1e-12) . ' пФ';
}

$posted = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$activeCalculation = $posted ? (string) ($_POST['calculate'] ?? '') : '';
if (!in_array($activeCalculation, ['resistor', 'capacitor'], true)) {
    $activeCalculation = '';
}

$rMode = (string) ($_POST['r_mode'] ?? 'total');
if (!in_array($rMode, ['total', 'unknown'], true)) $rMode = 'total';
$rA = trim((string) ($_POST['r_a'] ?? '1'));
$rAUnit = (string) ($_POST['r_a_unit'] ?? 'kohm');
$rB = trim((string) ($_POST['r_b'] ?? '2,2'));
$rBUnit = (string) ($_POST['r_b_unit'] ?? 'kohm');
if (!in_array($rAUnit, ['ohm', 'kohm', 'mohm'], true)) $rAUnit = 'kohm';
if (!in_array($rBUnit, ['ohm', 'kohm', 'mohm'], true)) $rBUnit = 'kohm';

$cMode = (string) ($_POST['c_mode'] ?? 'total');
if (!in_array($cMode, ['total', 'unknown'], true)) $cMode = 'total';
$cA = trim((string) ($_POST['c_a'] ?? '100'));
$cAUnit = (string) ($_POST['c_a_unit'] ?? 'nf');
$cB = trim((string) ($_POST['c_b'] ?? '220'));
$cBUnit = (string) ($_POST['c_b_unit'] ?? 'nf');
if (!in_array($cAUnit, ['pf', 'nf', 'uf', 'mf', 'f'], true)) $cAUnit = 'nf';
if (!in_array($cBUnit, ['pf', 'nf', 'uf', 'mf', 'f'], true)) $cBUnit = 'nf';

$rResult = null;
$rError = '';
$rFormula = '';
$cResult = null;
$cError = '';
$cFormula = '';

if ($activeCalculation === 'resistor') {
    $a = pc_positiveNumber($rA);
    $b = pc_positiveNumber($rB);
    if ($a === null || $b === null) {
        $rError = 'Введите оба значения: каждое должно быть конечным числом больше нуля.';
    } else {
        $a *= pc_resistorFactor($rAUnit);
        $b *= pc_resistorFactor($rBUnit);
        if ($rMode === 'total') {
            $rResult = ($a * $b) / ($a + $b);
            $rFormula = 'Rобщ = R₁ × R₂ / (R₁ + R₂)';
        } else {
            // Здесь a — заданное общее сопротивление, b — известный резистор R₁.
            if ($a >= $b) {
                $rError = 'Общее сопротивление должно быть меньше сопротивления известного резистора. При равенстве конечного положительного решения нет.';
            } else {
                $denominator = $b - $a;
                if ($denominator <= 0.0) {
                    $rError = 'Для этих значений нет конечного положительного решения.';
                } else {
                    $rResult = ($a * $b) / $denominator;
                    $rFormula = 'R₂ = Rобщ × R₁ / (R₁ − Rобщ)';
                }
            }
        }
    }
}

if ($activeCalculation === 'capacitor') {
    $a = pc_positiveNumber($cA);
    $b = pc_positiveNumber($cB);
    if ($a === null || $b === null) {
        $cError = 'Введите обе ёмкости: каждое значение должно быть конечным числом больше нуля.';
    } else {
        $a *= pc_capacitorFactor($cAUnit);
        $b *= pc_capacitorFactor($cBUnit);
        if ($cMode === 'total') {
            $cResult = ($a * $b) / ($a + $b);
            $cFormula = 'Cобщ = C₁ × C₂ / (C₁ + C₂)';
        } else {
            // Здесь a — заданная общая ёмкость, b — известный конденсатор C₁.
            if ($a >= $b) {
                $cError = 'Общая ёмкость должна быть меньше ёмкости известного конденсатора. При равенстве конечного положительного решения нет.';
            } else {
                $denominator = $b - $a;
                if ($denominator <= 0.0) {
                    $cError = 'Для этих значений нет конечного положительного решения.';
                } else {
                    $cResult = ($a * $b) / $denominator;
                    $cFormula = 'C₂ = Cобщ × C₁ / (C₁ − Cобщ)';
                }
            }
        }
    }
}

function pc_unitOptions(string $selected, array $options): string
{
    $html = '';
    foreach ($options as $value => $label) {
        $isSelected = ($value === $selected) ? ' selected' : '';
        $html .= '<option value="' . pc_h($value) . '"' . $isSelected . '>' . pc_h($label) . '</option>';
    }
    return $html;
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Резисторы и конденсаторы — радиолюбительский калькулятор</title>
    <meta name="description" content="Расчёт параллельного соединения резисторов и последовательного соединения конденсаторов.">
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #202124; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 1100px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #d7dce1; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: clamp(1.45rem, 3vw, 2rem); }
        h2 { margin: 0 0 12px; font-size: 1.25rem; }
        p { line-height: 1.5; }
        .subtle { color: #5f6368; }
        .topline { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .back-link { color: #244f76; font-weight: 700; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .calculators { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; align-items: start; }
         .calculator-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 22px; align-items: start; }
         .diagram-column, .form-column { min-width: 0; }
        .calculator { min-width: 0; }
        .diagram { display: block; width: 100%; height: 150px; object-fit: contain; margin: 4px 0 14px; border: 1px solid #d7dce1; border-radius: 8px; background: #fff; }
        .mode-label, .field-label { display: block; font-weight: 700; margin-bottom: 6px; }
        select, input { font: inherit; }
        .mode-select { width: 100%; padding: 10px; border: 1px solid #b8c0c8; border-radius: 6px; background: #fff; margin-bottom: 14px; }
        .fields { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
        .value-row { display: grid; grid-template-columns: minmax(0, 1fr) 104px; gap: 8px; }
        .value-input, .unit-select { width: 100%; min-width: 0; border: 1px solid #b8c0c8; border-radius: 6px; padding: 10px; background: #fff; }
        .value-input:focus, .unit-select:focus, .mode-select:focus { outline: 3px solid #c5e2f8; border-color: #3976a8; }
        .action-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 16px; }
        button { border: 0; border-radius: 6px; padding: 11px 16px; color: #fff; background: #245c88; font: inherit; font-weight: 700; cursor: pointer; }
        button:hover { background: #19476c; }
        button:focus-visible, a:focus-visible { outline: 3px solid #89bde5; outline-offset: 3px; }
        .result { margin-top: 16px; padding: 14px; border: 1px solid #bfd8c6; border-radius: 8px; background: #f3faf5; overflow-wrap: anywhere; }
        .result-label { color: #385744; font-size: .92rem; }
        .result-value { margin: 5px 0; font-size: clamp(1.45rem, 3vw, 1.9rem); font-weight: 700; color: #173c25; }
        .formula { color: #4d5b52; font-size: .95rem; margin-bottom: 0; }
        .error { margin-top: 16px; padding: 12px; border: 1px solid #e4b7b7; border-radius: 8px; background: #fff5f5; color: #8b2323; line-height: 1.45; }
        .notes { font-size: .9rem; }
        .clear-link { color: #244f76; white-space: nowrap; }
        .compact-mode, .fields > div { display: grid; grid-template-columns: minmax(145px, .9fr) minmax(0, 1.1fr); align-items: center; gap: 10px; }
        .compact-mode { margin-bottom: 8px; }
        .mode-label, .field-label { margin: 0; }
        .mode-select { margin: 0; min-height: 39px; }
        .fields { gap: 8px; }
        .value-row { grid-template-columns: minmax(0, 1fr) 86px; gap: 6px; }
        .value-input, .unit-select { min-height: 39px; }
        @media (max-width: 760px) {
            .compact-mode, .fields > div { grid-template-columns: minmax(120px, .85fr) minmax(0, 1.15fr); gap: 8px; }
        }
        @media (max-width: 760px) {
            body { padding: 10px; }
            .panel { padding: 15px; }
            .calculators { grid-template-columns: minmax(0, 1fr); }
            .diagram { height: 135px; }
        }
        @media print {
            :root { background: #fff; }
            body { padding: 0; }
            .panel { border: 1px solid #aaa; break-inside: avoid; }
            .screen-only, button { display: none !important; }
            .calculators { grid-template-columns: minmax(0, 1fr); }
             .calculator-layout { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Резисторы и конденсаторы</h1>
        <p class="subtle">Два простых расчёта: параллельное соединение резисторов и последовательное соединение конденсаторов. Можно найти общую величину или вычислить неизвестный элемент по общей величине и известному элементу.</p>
        <p class="screen-only"><a class="back-link" href="index.php">← К списку калькуляторов</a></p>
    </section>

    <form method="post" action="">
        <div class="calculators">
            <section class="panel calculator" id="resistor-calculator">
                <h2>Параллельные резисторы</h2>
                <div class="calculator-layout">
                    <div class="diagram-column">
                        <img class="diagram" src="images/parallel-resistors.svg" alt="Схема двух резисторов R1 и R2, соединённых параллельно" width="640" height="260">
                    </div>
                    <div class="form-column">
                <div class="compact-mode">
                    <label class="mode-label" for="r_mode">Что рассчитать</label>
                    <select class="mode-select" id="r_mode" name="r_mode">
                    <option value="total"<?= $rMode === 'total' ? ' selected' : '' ?>>Общее сопротивление по R₁ и R₂</option>
                    <option value="unknown"<?= $rMode === 'unknown' ? ' selected' : '' ?>>Неизвестный R₂ по Rобщ и R₁</option>
                    </select>
                </div>
                <div class="fields">
                    <div>
                        <label class="field-label" id="r_a_label" for="r_a"><?= $rMode === 'total' ? 'Сопротивление R₁' : 'Общее сопротивление Rобщ' ?></label>
                        <div class="value-row">
                            <input class="value-input" id="r_a" name="r_a" type="text" inputmode="decimal" autocomplete="off" value="<?= pc_h($rA) ?>" placeholder="Например, 1">
                            <select class="unit-select" name="r_a_unit" aria-label="Единица первого сопротивления"><?= pc_unitOptions($rAUnit, ['ohm' => 'Ом', 'kohm' => 'кОм', 'mohm' => 'МОм']) ?></select>
                        </div>
                    </div>
                    <div>
                        <label class="field-label" id="r_b_label" for="r_b"><?= $rMode === 'total' ? 'Сопротивление R₂' : 'Известное сопротивление R₁' ?></label>
                        <div class="value-row">
                            <input class="value-input" id="r_b" name="r_b" type="text" inputmode="decimal" autocomplete="off" value="<?= pc_h($rB) ?>" placeholder="Например, 2,2">
                            <select class="unit-select" name="r_b_unit" aria-label="Единица второго сопротивления"><?= pc_unitOptions($rBUnit, ['ohm' => 'Ом', 'kohm' => 'кОм', 'mohm' => 'МОм']) ?></select>
                        </div>
                    </div>
                </div>
                <div class="action-row">
                    <button type="submit" name="calculate" value="resistor">Рассчитать сопротивление</button>
                    <a class="clear-link screen-only" href="#resistor-calculator" data-reset-scope="resistor">Сбросить</a>
                </div>
                    </div>
                </div>
                <?php if ($activeCalculation === 'resistor' && $rError !== ''): ?>
                    <div class="error" role="alert"><?= pc_h($rError) ?></div>
                <?php elseif ($activeCalculation === 'resistor' && $rResult !== null): ?>
                    <div class="result" role="status" aria-live="polite">
                        <div class="result-label"><?= $rMode === 'total' ? 'Общее сопротивление Rобщ' : 'Неизвестное сопротивление R₂' ?></div>
                        <div class="result-value"><?= pc_h(pc_formatResistance($rResult)) ?></div>
                        <p class="formula">Формула: <?= pc_h($rFormula) ?></p>
                    </div>
                <?php endif; ?>
            </section>

            <section class="panel calculator" id="capacitor-calculator">
                <h2>Последовательные конденсаторы</h2>
                <div class="calculator-layout">
                    <div class="diagram-column">
                        <img class="diagram" src="images/series-capacitors.svg" alt="Схема двух конденсаторов C1 и C2, соединённых последовательно" width="640" height="260">
                    </div>
                    <div class="form-column">
                <div class="compact-mode">
                    <label class="mode-label" for="c_mode">Что рассчитать</label>
                    <select class="mode-select" id="c_mode" name="c_mode">
                    <option value="total"<?= $cMode === 'total' ? ' selected' : '' ?>>Общую ёмкость по C₁ и C₂</option>
                    <option value="unknown"<?= $cMode === 'unknown' ? ' selected' : '' ?>>Неизвестный C₂ по Cобщ и C₁</option>
                    </select>
                </div>
                <div class="fields">
                    <div>
                        <label class="field-label" id="c_a_label" for="c_a"><?= $cMode === 'total' ? 'Ёмкость C₁' : 'Общая ёмкость Cобщ' ?></label>
                        <div class="value-row">
                            <input class="value-input" id="c_a" name="c_a" type="text" inputmode="decimal" autocomplete="off" value="<?= pc_h($cA) ?>" placeholder="Например, 100">
                            <select class="unit-select" name="c_a_unit" aria-label="Единица первой ёмкости"><?= pc_unitOptions($cAUnit, ['pf' => 'пФ', 'nf' => 'нФ', 'uf' => 'мкФ', 'mf' => 'мФ', 'f' => 'Ф']) ?></select>
                        </div>
                    </div>
                    <div>
                        <label class="field-label" id="c_b_label" for="c_b"><?= $cMode === 'total' ? 'Ёмкость C₂' : 'Известная ёмкость C₁' ?></label>
                        <div class="value-row">
                            <input class="value-input" id="c_b" name="c_b" type="text" inputmode="decimal" autocomplete="off" value="<?= pc_h($cB) ?>" placeholder="Например, 220">
                            <select class="unit-select" name="c_b_unit" aria-label="Единица второй ёмкости"><?= pc_unitOptions($cBUnit, ['pf' => 'пФ', 'nf' => 'нФ', 'uf' => 'мкФ', 'mf' => 'мФ', 'f' => 'Ф']) ?></select>
                        </div>
                    </div>
                </div>
                <div class="action-row">
                    <button type="submit" name="calculate" value="capacitor">Рассчитать ёмкость</button>
                    <a class="clear-link screen-only" href="#capacitor-calculator" data-reset-scope="capacitor">Сбросить</a>
                </div>
                    </div>
                </div>
                <?php if ($activeCalculation === 'capacitor' && $cError !== ''): ?>
                    <div class="error" role="alert"><?= pc_h($cError) ?></div>
                <?php elseif ($activeCalculation === 'capacitor' && $cResult !== null): ?>
                    <div class="result" role="status" aria-live="polite">
                        <div class="result-label"><?= $cMode === 'total' ? 'Общая ёмкость Cобщ' : 'Неизвестная ёмкость C₂' ?></div>
                        <div class="result-value"><?= pc_h(pc_formatCapacitance($cResult)) ?></div>
                        <p class="formula">Формула: <?= pc_h($cFormula) ?></p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </form>

    <section class="panel notes">
        <p><strong>Важно.</strong> Расчёт предполагает идеальные компоненты. Для резисторов используются Ом, кОм и МОм; для конденсаторов — пФ, нФ, мкФ, мФ и Ф. Вводить десятичные значения можно как с точкой, так и с запятой. Результат автоматически выводится в удобных единицах.</p>
    </section>
</main>

<script>
/* Подписи полей меняются сразу при выборе режима — без отправки формы. */
(() => {
    function bindMode(selectId, firstLabelId, secondLabelId, labels) {
        const select = document.getElementById(selectId);
        const firstLabel = document.getElementById(firstLabelId);
        const secondLabel = document.getElementById(secondLabelId);
        if (!select || !firstLabel || !secondLabel) return;

        const updateLabels = () => {
            const labelsForMode = select.value === 'unknown' ? labels.unknown : labels.total;
            firstLabel.textContent = labelsForMode[0];
            secondLabel.textContent = labelsForMode[1];
        };

        select.addEventListener('change', updateLabels);
        updateLabels();
    }

    bindMode('r_mode', 'r_a_label', 'r_b_label', {
        total: ['Сопротивление R₁', 'Сопротивление R₂'],
        unknown: ['Общее сопротивление Rобщ', 'Известное сопротивление R₁']
    });

    bindMode('c_mode', 'c_a_label', 'c_b_label', {
        total: ['Ёмкость C₁', 'Ёмкость C₂'],
        unknown: ['Общая ёмкость Cобщ', 'Известная ёмкость C₁']
    });

    const form = document.querySelector('form[method="post"]');
    const resetValues = {
        resistor: {r_mode: 'total', r_a: '1', r_a_unit: 'kohm', r_b: '2,2', r_b_unit: 'kohm'},
        capacitor: {c_mode: 'total', c_a: '100', c_a_unit: 'nf', c_b: '220', c_b_unit: 'nf'}
    };
    document.querySelectorAll('[data-reset-scope]').forEach(link => {
        link.addEventListener('click', event => {
            event.preventDefault();
            const scope = link.dataset.resetScope;
            const card = document.getElementById(scope + '-calculator');
            const defaults = resetValues[scope];
            if (!form || !card || !defaults) return;

            Object.entries(defaults).forEach(([name, value]) => {
                const control = form.elements.namedItem(name);
                if (control) {
                    control.value = value;
                    control.dispatchEvent(new Event('change', {bubbles: true}));
                }
            });
            card.querySelectorAll('.result, .error').forEach(output => {
                output.hidden = true;
            });
        });
    });
})();
</script>
</body>
</html>
