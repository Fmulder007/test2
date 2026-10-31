<?php
/*
 * Пересчёт ёмкости или индуктивности LC-контура при изменении резонансной частоты.
 * Предполагается, что указанная ёмкость является полной эквивалентной ёмкостью контура.
 */
declare(strict_types=1);

function lc_retune_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function lc_retune_number(string $raw): ?float
{
    $raw = str_replace([' ', "\u{00A0}"], '', trim($raw));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '' || !is_numeric($raw)) return null;
    $number = (float) $raw;
    return ($number > 0.0 && is_finite($number)) ? $number : null;
}

function lc_retune_num(float $value): string
{
    if (!is_finite($value)) return '—';
    if ($value !== 0.0 && (abs($value) >= 1e12 || abs($value) < 1e-5)) {
        return str_replace('.', ',', sprintf('%.6g', $value));
    }
    $formatted = number_format($value, 6, ',', '');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    return ($formatted === '' || $formatted === '-0') ? '0' : $formatted;
}

function lc_retune_selected(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

$units = [
    'frequency' => ['hz' => 'Гц', 'khz' => 'кГц', 'mhz' => 'МГц', 'ghz' => 'ГГц'],
    'inductance' => ['nh' => 'нГн', 'uh' => 'мкГн', 'mh' => 'мГн', 'h' => 'Гн'],
    'capacitance' => ['pf' => 'пФ', 'nf' => 'нФ', 'uf' => 'мкФ', 'mf' => 'мФ', 'f' => 'Ф'],
];
$factors = [
    'frequency' => ['hz' => 1.0, 'khz' => 1e3, 'mhz' => 1e6, 'ghz' => 1e9],
    'inductance' => ['nh' => 1e-9, 'uh' => 1e-6, 'mh' => 1e-3, 'h' => 1.0],
    'capacitance' => ['pf' => 1e-12, 'nf' => 1e-9, 'uf' => 1e-6, 'mf' => 1e-3, 'f' => 1.0],
];
$defaultUnits = ['frequency' => 'mhz', 'inductance' => 'uh', 'capacitance' => 'pf'];

$mode = (string) ($_POST['mode'] ?? 'capacitance');
if (!in_array($mode, ['capacitance', 'inductance'], true)) $mode = 'capacitance';
$parameterType = $mode === 'capacitance' ? 'capacitance' : 'inductance';
$parameterDefault = $mode === 'capacitance' ? '100' : '5.03';
$parameterDefaultUnit = $mode === 'capacitance' ? 'pf' : 'uh';

$oldFrequencyRaw = trim((string) ($_POST['old_frequency'] ?? '7.1'));
$oldFrequencyUnit = (string) ($_POST['old_frequency_unit'] ?? 'mhz');
$parameterRaw = trim((string) ($_POST['old_parameter'] ?? $parameterDefault));
$parameterUnit = (string) ($_POST['old_parameter_unit'] ?? $parameterDefaultUnit);
$newFrequencyRaw = trim((string) ($_POST['new_frequency'] ?? '14.2'));
$newFrequencyUnit = (string) ($_POST['new_frequency_unit'] ?? 'mhz');

if (!isset($units['frequency'][$oldFrequencyUnit])) $oldFrequencyUnit = $defaultUnits['frequency'];
if (!isset($units[$parameterType][$parameterUnit])) $parameterUnit = $defaultUnits[$parameterType];
if (!isset($units['frequency'][$newFrequencyUnit])) $newFrequencyUnit = $defaultUnits['frequency'];

$isSubmitted = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['calculate']);
$error = '';
$result = null;

if ($isSubmitted) {
    $oldFrequencyInput = lc_retune_number($oldFrequencyRaw);
    $parameterInput = lc_retune_number($parameterRaw);
    $newFrequencyInput = lc_retune_number($newFrequencyRaw);

    if ($oldFrequencyInput === null || $parameterInput === null || $newFrequencyInput === null) {
        $error = 'Все три значения должны быть конечными числами больше нуля. Можно использовать точку или запятую.';
    } else {
        $oldFrequency = $oldFrequencyInput * $factors['frequency'][$oldFrequencyUnit];
        $oldParameter = $parameterInput * $factors[$parameterType][$parameterUnit];
        $newFrequency = $newFrequencyInput * $factors['frequency'][$newFrequencyUnit];

        if (!is_finite($oldFrequency) || !is_finite($oldParameter) || !is_finite($newFrequency)
            || $oldFrequency <= 0.0 || $oldParameter <= 0.0 || $newFrequency <= 0.0) {
            $error = 'Значения находятся за пределами вычислимого диапазона. Проверьте числа и единицы измерения.';
        } else {
            $frequencyRatio = $oldFrequency / $newFrequency;
            $ratioSquared = $frequencyRatio * $frequencyRatio;
            $omega = 2.0 * M_PI * $oldFrequency;
            $denominator = ($omega * $omega) * $oldParameter;

            if (!is_finite($frequencyRatio) || !is_finite($ratioSquared) || $ratioSquared <= 0.0
                || !is_finite($denominator) || $denominator <= 0.0) {
                $error = 'Результат выходит за пределы вычислимого диапазона. Проверьте исходные значения.';
            } else {
                $newParameter = $oldParameter * $ratioSquared;
                $unchangedParameter = 1.0 / $denominator;

                if (!is_finite($newParameter) || $newParameter <= 0.0
                    || !is_finite($unchangedParameter) || $unchangedParameter <= 0.0) {
                    $error = 'Результат выходит за пределы вычислимого диапазона. Проверьте исходные значения.';
                } else {
                    if ($mode === 'capacitance') {
                        $result = [
                            'main_label' => 'Новая ёмкость контура C₂',
                            'main_value' => lc_retune_formatCapacitance($newParameter),
                            'secondary_label' => 'Неизменная индуктивность катушки L',
                            'secondary_value' => lc_retune_formatInductance($unchangedParameter),
                            'formula' => 'C₂ = C₁ · (f₁ / f₂)²',
                            'secondary_formula' => 'L = 1 / ((2πf₁)² · C₁)',
                        ];
                    } else {
                        $result = [
                            'main_label' => 'Новая индуктивность катушки L₂',
                            'main_value' => lc_retune_formatInductance($newParameter),
                            'secondary_label' => 'Неизменная ёмкость контура C',
                            'secondary_value' => lc_retune_formatCapacitance($unchangedParameter),
                            'formula' => 'L₂ = L₁ · (f₁ / f₂)²',
                            'secondary_formula' => 'C = 1 / ((2πf₁)² · L₁)',
                        ];
                    }
                }
            }
        }
    }
}

function lc_retune_formatInductance(float $henry): string
{
    if ($henry >= 1.0) return lc_retune_num($henry) . ' Гн';
    if ($henry >= 1e-3) return lc_retune_num($henry / 1e-3) . ' мГн';
    if ($henry >= 1e-6) return lc_retune_num($henry / 1e-6) . ' мкГн';
    if ($henry >= 1e-9) return lc_retune_num($henry / 1e-9) . ' нГн';
    return lc_retune_num($henry / 1e-12) . ' пГн';
}

function lc_retune_formatCapacitance(float $farad): string
{
    if ($farad >= 1.0) return lc_retune_num($farad) . ' Ф';
    if ($farad >= 1e-3) return lc_retune_num($farad / 1e-3) . ' мФ';
    if ($farad >= 1e-6) return lc_retune_num($farad / 1e-6) . ' мкФ';
    if ($farad >= 1e-9) return lc_retune_num($farad / 1e-9) . ' нФ';
    if ($farad >= 1e-12) return lc_retune_num($farad / 1e-12) . ' пФ';
    return lc_retune_num($farad / 1e-15) . ' фФ';
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Перестройка LC-контура по частоте — радиолюбительские калькуляторы</title>
    <meta name="description" content="Расчёт новой ёмкости или индуктивности LC-контура при изменении резонансной частоты с сохранением второго параметра.">
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #202124; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 1000px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #d7dce1; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: clamp(1.45rem, 3vw, 2rem); }
        h2 { margin: 0 0 12px; font-size: 1.25rem; }
        p { line-height: 1.5; }
        .subtle { color: #5f6368; }
        .back-link { display: inline-block; margin-top: 4px; color: #244f76; font-weight: 700; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .diagram { display: block; width: 100%; height: auto; margin: 0 0 14px; border: 1px solid #d7dce1; border-radius: 8px; background: #fff; }
        .top-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 20px; align-items: stretch; margin-top: 18px; }
        .diagram-column { min-width: 0; display: flex; flex-direction: column; justify-content: center; }
        .form-column { min-width: 0; }
        .form-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; align-items: end; }
        .note { padding: 12px 14px; border-left: 4px solid #3c7ca7; background: #f2f7fb; border-radius: 5px; line-height: 1.45; }
        .mode-field { margin: 0 0 17px; padding: 0; border: 0; }
        .field-label, .mode-field legend { display: block; font-weight: 700; margin-bottom: 8px; }
        .mode-options { display: flex; flex-wrap: wrap; gap: 12px 24px; }
        .mode-options label { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
        .mode-options input { width: 17px; height: 17px; margin: 0; accent-color: #245c88; }
        .field { min-width: 0; }
        select, input, button { font: inherit; }
        .value-row { display: grid; grid-template-columns: minmax(0, 1fr) 112px; gap: 8px; }
        .value-input, .unit-select { width: 100%; min-width: 0; border: 1px solid #b8c0c8; border-radius: 6px; padding: 10px; background: #fff; }
        .value-input:focus, .unit-select:focus { outline: 3px solid #c5e2f8; border-color: #3976a8; }
        .action-row { display: flex; flex-direction: column; align-items: stretch; gap: 10px; margin-top: 17px; }
        .action-row button, .action-row .clear-button { width: 100%; }
        button { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; border: 0; border-radius: 6px; padding: 11px 16px; color: #fff; background: #245c88; font-weight: 700; line-height: 1.25; cursor: pointer; }
        button:hover { background: #19476c; }
        button:focus-visible, a:focus-visible { outline: 3px solid #89bde5; outline-offset: 3px; }
        .result { margin-top: 18px; padding: 16px; border: 1px solid #bfd8c6; border-radius: 8px; background: #f3faf5; overflow-wrap: anywhere; }
        .result-heading { margin: 0 0 10px; color: #244b32; font-size: 1.1rem; }
        .result-value { font-size: clamp(1.8rem, 4vw, 2.5rem); line-height: 1.2; font-weight: 700; color: #173c25; }
        .secondary-result { margin: 12px 0 0; color: #244b32; font-size: 1.05rem; }
        .formula { margin: 10px 0 0; color: #4d5b52; font-size: .95rem; line-height: 1.65; }
        .error { margin-top: 16px; padding: 12px; border: 1px solid #e4b7b7; border-radius: 8px; background: #fff5f5; color: #8b2323; line-height: 1.45; }
        .stale-hint { display: none; margin-top: 12px; color: #7a5200; font-size: .93rem; }
        .clear-button { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; border: 1px solid #b8c0c8; border-radius: 6px; padding: 10px 16px; color: #244f76; background: #fff; font-weight: 700; line-height: 1.25; text-decoration: none; }
        .clear-button:hover { background: #f4f6f8; text-decoration: none; }
        @media (max-width: 680px) { body { padding: 10px; } .panel { padding: 15px; } .top-layout { grid-template-columns: minmax(0, 1fr); } .diagram { margin: 0 0 12px; } }
        @media print { body { padding: 0; background: #fff; } .panel { border-color: #aaa; break-inside: avoid; } .back-link, button, .clear-button, .stale-hint { display: none !important; } .result { border-color: #999; background: #fff; } }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Перестройка LC-контура по частоте</h1>
        <p class="subtle">Рассчитывает новую ёмкость или индуктивность контура при переходе на другую резонансную частоту, сохраняя второй параметр неизменным.</p>
        <a class="back-link" href="index.php">← К списку калькуляторов</a>

        <div class="top-layout">
            <div class="diagram-column">
                <img class="diagram" src="images/lc-circuit.svg" alt="Схематичный LC-контур с катушкой L и конденсатором C" width="760" height="240">
                <div class="note"><strong>Примечание:</strong> в расчёте указанная ёмкость считается полной эквивалентной ёмкостью контура. Потери катушки, добротность и паразитные параметры не учитываются.</div>
            </div>

            <div class="form-column">
                <h2>Исходные данные</h2>
                <form id="retune-form" method="post" action="">
                    <fieldset class="mode-field mode-options">
                        <legend>Что пересчитываем?</legend>
                        <label><input type="radio" name="mode" value="capacitance"<?= $mode === 'capacitance' ? ' checked' : '' ?>> Пересчитать ёмкость</label>
                        <label><input type="radio" name="mode" value="inductance"<?= $mode === 'inductance' ? ' checked' : '' ?>> Пересчитать индуктивность</label>
                    </fieldset>

                    <div class="form-grid">
                        <div class="field">
                            <label class="field-label" for="old_frequency">Старая резонансная частота f₁</label>
                            <div class="value-row">
                                <input class="value-input" id="old_frequency" name="old_frequency" type="text" inputmode="decimal" autocomplete="off" value="<?= lc_retune_h($oldFrequencyRaw) ?>" required>
                                <select class="unit-select" id="old_frequency_unit" name="old_frequency_unit" aria-label="Единица старой частоты">
                                    <?php foreach ($units['frequency'] as $unit => $label): ?><option value="<?= lc_retune_h($unit) ?>"<?= lc_retune_selected($oldFrequencyUnit, $unit) ?>><?= lc_retune_h($label) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="old_parameter" id="parameter-label"><?= $mode === 'capacitance' ? 'Старая ёмкость контура C₁' : 'Старая индуктивность катушки L₁' ?></label>
                            <div class="value-row">
                                <input class="value-input" id="old_parameter" name="old_parameter" type="text" inputmode="decimal" autocomplete="off" value="<?= lc_retune_h($parameterRaw) ?>" required>
                                <select class="unit-select" id="old_parameter_unit" name="old_parameter_unit" data-type="<?= lc_retune_h($parameterType) ?>" aria-label="Единица старого параметра">
                                    <?php foreach ($units[$parameterType] as $unit => $label): ?><option value="<?= lc_retune_h($unit) ?>"<?= lc_retune_selected($parameterUnit, $unit) ?>><?= lc_retune_h($label) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="new_frequency">Новая резонансная частота f₂</label>
                            <div class="value-row">
                                <input class="value-input" id="new_frequency" name="new_frequency" type="text" inputmode="decimal" autocomplete="off" value="<?= lc_retune_h($newFrequencyRaw) ?>" required>
                                <select class="unit-select" id="new_frequency_unit" name="new_frequency_unit" aria-label="Единица новой частоты">
                                    <?php foreach ($units['frequency'] as $unit => $label): ?><option value="<?= lc_retune_h($unit) ?>"<?= lc_retune_selected($newFrequencyUnit, $unit) ?>><?= lc_retune_h($label) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="action-row">
                        <button type="submit" name="calculate" value="1">Рассчитать</button>
                        <a class="clear-button" href="lc_retune.php">Очистить результаты</a>
                    </div>
                    <div class="stale-hint" id="stale-hint">Исходные данные изменены. Нажми «Рассчитать», чтобы обновить результат.</div>
                </form>
            </div>
        </div>
    </section>

    <?php if ($error !== ''): ?><div class="error" id="calculation-error" role="alert"><?= lc_retune_h($error) ?></div><?php endif; ?>
    <?php if ($result !== null): ?>
        <div class="result" id="calculation-result" aria-live="polite">
            <h2 class="result-heading"><?= lc_retune_h($result['main_label']) ?></h2>
            <div class="result-value"><?= lc_retune_h($result['main_value']) ?></div>
            <p class="secondary-result"><strong><?= lc_retune_h($result['secondary_label']) ?>:</strong> <?= lc_retune_h($result['secondary_value']) ?></p>
            <p class="formula">Использованные формулы: <?= lc_retune_h($result['formula']) ?>; <?= lc_retune_h($result['secondary_formula']) ?>.</p>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>Формулы расчёта</h2>
        <p class="formula">
            При пересчёте ёмкости: C₂ = C₁ · (f₁ / f₂)².<br>
            При пересчёте индуктивности: L₂ = L₁ · (f₁ / f₂)².<br>
            Неизменный параметр определяется по исходной частоте и исходному значению второго параметра.
        </p>
    </section>
</main>
<script>
(() => {
    const form = document.getElementById('retune-form');
    const parameter = document.getElementById('old_parameter');
    const parameterUnit = document.getElementById('old_parameter_unit');
    const parameterLabel = document.getElementById('parameter-label');
    const result = document.getElementById('calculation-result');
    const error = document.getElementById('calculation-error');
    const hint = document.getElementById('stale-hint');
    const units = {
        capacitance: [['pf', 'пФ'], ['nf', 'нФ'], ['uf', 'мкФ'], ['mf', 'мФ'], ['f', 'Ф']],
        inductance: [['nh', 'нГн'], ['uh', 'мкГн'], ['mh', 'мГн'], ['h', 'Гн']]
    };
    let previousType = parameterUnit.dataset.type;

    function selectedMode() {
        return form.querySelector('input[name="mode"]:checked').value;
    }

    function updateParameterType(resetValue) {
        const mode = selectedMode();
        const type = mode === 'capacitance' ? 'capacitance' : 'inductance';
        const defaultValue = mode === 'capacitance' ? '100' : '5.03';
        const defaultUnit = mode === 'capacitance' ? 'pf' : 'uh';
        if (resetValue && previousType !== type) parameter.value = defaultValue;
        parameterLabel.textContent = mode === 'capacitance'
            ? 'Старая ёмкость контура C₁'
            : 'Старая индуктивность катушки L₁';
        if (previousType !== type) {
            parameterUnit.replaceChildren();
            for (const [value, label] of units[type]) {
                const option = document.createElement('option');
                option.value = value;
                option.textContent = label;
                parameterUnit.appendChild(option);
            }
            parameterUnit.value = defaultUnit;
        }
        parameterUnit.dataset.type = type;
        previousType = type;
    }

    function markOutdated() {
        if (result) result.hidden = true;
        if (error) error.hidden = true;
        if (hint) hint.style.display = 'block';
    }

    form.querySelectorAll('input[name="mode"]').forEach(radio => {
        radio.addEventListener('change', () => {
            updateParameterType(true);
            markOutdated();
        });
    });
    if (result || error) {
        form.querySelectorAll('input, select').forEach(control => {
            control.addEventListener('input', markOutdated);
            control.addEventListener('change', markOutdated);
        });
    }

    // После отправки формы прокручиваем страницу к результату или ошибке.
    // Фокус на блок не переводим: поэтому рамка фокуса вокруг результата не появляется.
    // Два кадра ожидания дают браузеру завершить восстановление страницы и отрисовку.
    const shouldScrollToResult = <?= ($isSubmitted && $result !== null) ? 'true' : 'false' ?>;
    const shouldScrollToError = <?= ($isSubmitted && $error !== '') ? 'true' : 'false' ?>;
    const scrollTarget = shouldScrollToResult
        ? result
        : (shouldScrollToError ? error : null);
    if (scrollTarget) {
        requestAnimationFrame(() => requestAnimationFrame(() => {
            scrollTarget.scrollIntoView({behavior: 'smooth', block: 'start'});
        }));
    }
})();
</script>
</body>
</html>
