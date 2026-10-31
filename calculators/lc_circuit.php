<?php
/*
 * Калькулятор резонансного LC-контура и реактивного сопротивления.
 * Расчёты выполняются для идеальных L и C без учёта потерь и паразитных параметров.
 */
declare(strict_types=1);

function lc_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function lc_number(string $raw): ?float
{
    $raw = str_replace([' ', "\u{00A0}"], '', trim($raw));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '' || !is_numeric($raw)) return null;
    $number = (float) $raw;
    return ($number > 0.0 && is_finite($number)) ? $number : null;
}

function lc_num(float $value, int $decimals = 6): string
{
    if (!is_finite($value)) return '—';
    if ($value !== 0.0 && (abs($value) >= 1e12 || abs($value) < 1e-5)) {
        return str_replace('.', ',', sprintf('%.6g', $value));
    }
    $formatted = number_format($value, $decimals, ',', '');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    return ($formatted === '' || $formatted === '-0') ? '0' : $formatted;
}

function lc_selected(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

$lcUnits = [
    'frequency' => ['hz' => 'Гц', 'khz' => 'кГц', 'mhz' => 'МГц', 'ghz' => 'ГГц'],
    'inductance' => ['nh' => 'нГн', 'uh' => 'мкГн', 'mh' => 'мГн', 'h' => 'Гн'],
    'capacitance' => ['pf' => 'пФ', 'nf' => 'нФ', 'uf' => 'мкФ', 'mf' => 'мФ', 'f' => 'Ф'],
];
$lcFactors = [
    'frequency' => ['hz' => 1.0, 'khz' => 1e3, 'mhz' => 1e6, 'ghz' => 1e9],
    'inductance' => ['nh' => 1e-9, 'uh' => 1e-6, 'mh' => 1e-3, 'h' => 1.0],
    'capacitance' => ['pf' => 1e-12, 'nf' => 1e-9, 'uf' => 1e-6, 'mf' => 1e-3, 'f' => 1.0],
];
$lcDefaultUnits = ['frequency' => 'mhz', 'inductance' => 'uh', 'capacitance' => 'pf'];
$lcDefaults = [
    'frequency' => ['a' => '10', 'b' => '100'],
    'inductance' => ['a' => '7.1', 'b' => '100'],
    'capacitance' => ['a' => '7.1', 'b' => '10'],
    'reactance_coil' => ['a' => '7.1', 'b' => '10'],
    'reactance_cap' => ['a' => '7.1', 'b' => '100'],
];

$mode = (string) ($_POST['mode'] ?? 'frequency');
if (!in_array($mode, ['frequency', 'inductance', 'capacitance', 'reactance'], true)) $mode = 'frequency';
$reactanceType = (string) ($_POST['reactance_type'] ?? 'coil');
if (!in_array($reactanceType, ['coil', 'capacitor'], true)) $reactanceType = 'coil';
$typeA = in_array($mode, ['inductance', 'capacitance', 'reactance'], true) ? 'frequency' : 'inductance';
$typeB = match ($mode) {
    'frequency', 'inductance' => 'capacitance',
    'capacitance' => 'inductance',
    default => $reactanceType === 'coil' ? 'inductance' : 'capacitance',
};
$defaultKey = $mode === 'reactance' ? ($reactanceType === 'coil' ? 'reactance_coil' : 'reactance_cap') : $mode;
$aRaw = trim((string) ($_POST['a_value'] ?? $lcDefaults[$defaultKey]['a']));
$bRaw = trim((string) ($_POST['b_value'] ?? $lcDefaults[$defaultKey]['b']));
$aUnit = (string) ($_POST['a_unit'] ?? $lcDefaultUnits[$typeA]);
$bUnit = (string) ($_POST['b_unit'] ?? $lcDefaultUnits[$typeB]);
if (!isset($lcUnits[$typeA][$aUnit])) $aUnit = $lcDefaultUnits[$typeA];
if (!isset($lcUnits[$typeB][$bUnit])) $bUnit = $lcDefaultUnits[$typeB];

$modeInfo = [
    'frequency' => ['a' => 'Индуктивность L', 'b' => 'Ёмкость C'],
    'inductance' => ['a' => 'Резонансная частота f', 'b' => 'Ёмкость C'],
    'capacitance' => ['a' => 'Резонансная частота f', 'b' => 'Индуктивность L'],
    'reactance' => ['a' => 'Рабочая частота f', 'b' => $reactanceType === 'coil' ? 'Индуктивность катушки L' : 'Ёмкость конденсатора C'],
];
$aLabel = $modeInfo[$mode]['a'];
$bLabel = $modeInfo[$mode]['b'];

$isSubmitted = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['calculate']);
$error = '';
$result = null;
$formulaText = '';

if ($isSubmitted) {
    $aInput = lc_number($aRaw);
    $bInput = lc_number($bRaw);
    if ($aInput === null || $bInput === null) {
        $error = 'Оба значения должны быть конечными числами больше нуля. Можно использовать точку или запятую.';
    } else {
        $a = $aInput * $lcFactors[$typeA][$aUnit];
        $b = $bInput * $lcFactors[$typeB][$bUnit];
        if (!is_finite($a) || !is_finite($b) || $a <= 0.0 || $b <= 0.0) {
            $error = 'Значения находятся за пределами вычислимого диапазона. Проверьте числа и единицы измерения.';
        } else {
            $twoPi = 2.0 * M_PI;
            switch ($mode) {
                case 'frequency':
                    $l = $a;
                    $c = $b;
                    $value = 1.0 / ($twoPi * sqrt($l * $c));
                    $label = 'Резонансная частота';
                    $formulaText = 'f₀ = 1 / (2π√(L·C))';
                    $formatted = null;
                    break;
                case 'inductance':
                    $frequency = $a;
                    $c = $b;
                    $value = 1.0 / (pow($twoPi * $frequency, 2) * $c);
                    $label = 'Необходимая индуктивность';
                    $formulaText = 'L = 1 / ((2πf₀)² · C)';
                    break;
                case 'capacitance':
                    $frequency = $a;
                    $l = $b;
                    $value = 1.0 / (pow($twoPi * $frequency, 2) * $l);
                    $label = 'Необходимая ёмкость';
                    $formulaText = 'C = 1 / ((2πf₀)² · L)';
                    break;
                default:
                    $frequency = $a;
                    if ($reactanceType === 'coil') {
                        $value = $twoPi * $frequency * $b;
                        $label = 'Индуктивное сопротивление катушки';
                        $formulaText = 'Xₗ = 2πfL';
                    } else {
                        $value = 1.0 / ($twoPi * $frequency * $b);
                        $label = 'Ёмкостное сопротивление конденсатора';
                        $formulaText = 'X꜀ = 1 / (2πfC)';
                    }
                    break;
            }

            if (!isset($value) || !is_finite($value) || $value <= 0.0) {
                $error = 'Результат выходит за пределы вычислимого диапазона. Проверьте исходные значения.';
            } else {
                $result = ['label' => $label, 'value' => $value, 'unit' => $mode === 'frequency' ? 'frequency' : ($mode === 'inductance' ? 'inductance' : ($mode === 'capacitance' ? 'capacitance' : 'reactance'))];
            }
        }
    }
}

function lc_formatFrequency(float $hz): string
{
    if ($hz >= 1e9) return lc_num($hz / 1e9) . ' ГГц';
    if ($hz >= 1e6) return lc_num($hz / 1e6) . ' МГц';
    if ($hz >= 1e3) return lc_num($hz / 1e3) . ' кГц';
    return lc_num($hz) . ' Гц';
}
function lc_formatInductance(float $henry): string
{
    if ($henry >= 1.0) return lc_num($henry) . ' Гн';
    if ($henry >= 1e-3) return lc_num($henry / 1e-3) . ' мГн';
    if ($henry >= 1e-6) return lc_num($henry / 1e-6) . ' мкГн';
    if ($henry >= 1e-9) return lc_num($henry / 1e-9) . ' нГн';
    return lc_num($henry / 1e-12) . ' пГн';
}
function lc_formatCapacitance(float $farad): string
{
    if ($farad >= 1.0) return lc_num($farad) . ' Ф';
    if ($farad >= 1e-3) return lc_num($farad / 1e-3) . ' мФ';
    if ($farad >= 1e-6) return lc_num($farad / 1e-6) . ' мкФ';
    if ($farad >= 1e-9) return lc_num($farad / 1e-9) . ' нФ';
    if ($farad >= 1e-12) return lc_num($farad / 1e-12) . ' пФ';
    return lc_num($farad / 1e-15) . ' фФ';
}
function lc_formatReactance(float $ohm): string
{
    if ($ohm >= 1e9) return lc_num($ohm / 1e9) . ' ГОм';
    if ($ohm >= 1e6) return lc_num($ohm / 1e6) . ' МОм';
    if ($ohm >= 1e3) return lc_num($ohm / 1e3) . ' кОм';
    if ($ohm >= 1.0) return lc_num($ohm) . ' Ом';
    if ($ohm >= 1e-3) return lc_num($ohm / 1e-3) . ' мОм';
    return lc_num($ohm / 1e-6) . ' мкОм';
}

if ($result !== null) {
    $result['formatted'] = match ($result['unit']) {
        'frequency' => lc_formatFrequency($result['value']),
        'inductance' => lc_formatInductance($result['value']),
        'capacitance' => lc_formatCapacitance($result['value']),
        default => lc_formatReactance($result['value']),
    };
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Калькулятор LC-контура — радиолюбительские калькуляторы</title>
    <meta name="description" content="Расчёт резонансной частоты LC-контура, индуктивности, ёмкости и реактивного сопротивления катушки или конденсатора.">
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
        .back-link:hover, .clear-link:hover { text-decoration: underline; }
        .diagram { display: block; width: 100%; height: auto; margin: 16px 0; border: 1px solid #d7dce1; border-radius: 8px; background: #fff; }
        .note { padding: 12px 14px; border-left: 4px solid #3c7ca7; background: #f2f7fb; border-radius: 5px; line-height: 1.45; }
        .form-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; align-items: end; }
        .field { min-width: 0; }
        .field-label { display: block; font-weight: 700; margin-bottom: 6px; }
        select, input, button { font: inherit; }
        .value-row { display: grid; grid-template-columns: minmax(0, 1fr) 112px; gap: 8px; }
        .value-input, .unit-select, .mode-select { width: 100%; min-width: 0; border: 1px solid #b8c0c8; border-radius: 6px; padding: 10px; background: #fff; }
        .value-input:focus, .unit-select:focus, .mode-select:focus { outline: 3px solid #c5e2f8; border-color: #3976a8; }
        .mode-field { margin-bottom: 15px; }
        .action-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 17px; }
        button { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; border: 0; border-radius: 6px; padding: 11px 16px; color: #fff; background: #245c88; font-weight: 700; line-height: 1.25; cursor: pointer; }
        button:hover { background: #19476c; }
        button:focus-visible, a:focus-visible { outline: 3px solid #89bde5; outline-offset: 3px; }
        .result { margin-top: 18px; padding: 16px; border: 1px solid #bfd8c6; border-radius: 8px; background: #f3faf5; overflow-wrap: anywhere; }
        .result-heading { margin: 0 0 10px; color: #244b32; font-size: 1.1rem; }
        .result-value { font-size: clamp(1.8rem, 4vw, 2.5rem); line-height: 1.2; font-weight: 700; color: #173c25; }
        .formula { margin-bottom: 0; color: #4d5b52; font-size: .95rem; line-height: 1.65; }
        .error { margin-top: 16px; padding: 12px; border: 1px solid #e4b7b7; border-radius: 8px; background: #fff5f5; color: #8b2323; line-height: 1.45; }
        .stale-hint { display: none; margin-top: 12px; color: #7a5200; font-size: .93rem; }
        .small { font-size: .9rem; }
        .clear-link { color: #244f76; }
        @media (max-width: 680px) { body { padding: 10px; } .panel { padding: 15px; } .form-grid { grid-template-columns: minmax(0, 1fr); } .diagram { margin: 12px 0; } }
        @media print { body { padding: 0; background: #fff; } .panel { border-color: #aaa; break-inside: avoid; } .no-print, .back-link, button, .clear-link, .stale-hint { display: none !important; } .result { border-color: #999; background: #fff; } }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Калькулятор LC-контура</h1>
        <p class="subtle">Рассчитывает резонансную частоту идеального LC-контура, нужную индуктивность или ёмкость, а также реактивное сопротивление на заданной частоте.</p>
        <a class="back-link" href="index.php">← К списку калькуляторов</a>
        <img class="diagram" src="images/lc-circuit.svg" alt="Схематичный LC-контур с катушкой L и конденсатором C" width="760" height="240">
        <div class="note"><strong>Примечание:</strong> расчёт идеальный. Потери катушки, добротность, паразитные ёмкости, сопротивление источника и нагрузка не учитываются.</div>
    </section>

    <section class="panel">
        <h2>Что рассчитать?</h2>
        <form id="lc-form" method="post" action="">
            <div class="mode-field">
                <label class="field-label" for="mode">Режим расчёта</label>
                <select class="mode-select" id="mode" name="mode">
                    <option value="frequency"<?= lc_selected($mode, 'frequency') ?>>Резонансную частоту по L и C</option>
                    <option value="inductance"<?= lc_selected($mode, 'inductance') ?>>Индуктивность по частоте и ёмкости</option>
                    <option value="capacitance"<?= lc_selected($mode, 'capacitance') ?>>Ёмкость по частоте и индуктивности</option>
                    <option value="reactance"<?= lc_selected($mode, 'reactance') ?>>Реактивное сопротивление на заданной частоте</option>
                </select>
            </div>
            <div class="mode-field" id="reactance-kind-row"<?= $mode === 'reactance' ? '' : ' style="display:none"' ?>>
                <label class="field-label" for="reactance_type">Какой элемент рассчитываем?</label>
                <select class="mode-select" id="reactance_type" name="reactance_type">
                    <option value="coil"<?= lc_selected($reactanceType, 'coil') ?>>Катушка индуктивности — Xₗ</option>
                    <option value="capacitor"<?= lc_selected($reactanceType, 'capacitor') ?>>Конденсатор — X꜀</option>
                </select>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label class="field-label" for="a_value" id="a-label"><?= lc_h($aLabel) ?></label>
                    <div class="value-row">
                        <input class="value-input" id="a_value" name="a_value" type="text" inputmode="decimal" autocomplete="off" value="<?= lc_h($aRaw) ?>" required>
                        <select class="unit-select" id="a_unit" name="a_unit" data-type="<?= lc_h($typeA) ?>" aria-label="Единица первого значения">
                            <?php foreach ($lcUnits[$typeA] as $unit => $unitLabel): ?><option value="<?= lc_h($unit) ?>"<?= lc_selected($aUnit, $unit) ?>><?= lc_h($unitLabel) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="b_value" id="b-label"><?= lc_h($bLabel) ?></label>
                    <div class="value-row">
                        <input class="value-input" id="b_value" name="b_value" type="text" inputmode="decimal" autocomplete="off" value="<?= lc_h($bRaw) ?>" required>
                        <select class="unit-select" id="b_unit" name="b_unit" data-type="<?= lc_h($typeB) ?>" aria-label="Единица второго значения">
                            <?php foreach ($lcUnits[$typeB] as $unit => $unitLabel): ?><option value="<?= lc_h($unit) ?>"<?= lc_selected($bUnit, $unit) ?>><?= lc_h($unitLabel) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="action-row">
                <button type="submit" name="calculate" value="1">Рассчитать</button>
                <a class="clear-link" href="lc_circuit.php">Очистить результат</a>
            </div>
            <div class="stale-hint" id="stale-hint">Исходные данные изменены. Нажми «Рассчитать», чтобы обновить результат.</div>
        </form>
        <?php if ($error !== ''): ?><div class="error" id="calculation-error" role="alert"><?= lc_h($error) ?></div><?php endif; ?>
        <?php if ($result !== null): ?>
            <div class="result" id="calculation-result" aria-live="polite">
                <h2 class="result-heading"><?= lc_h($result['label']) ?></h2>
                <div class="result-value"><?= lc_h($result['formatted']) ?></div>
                <p class="formula">Использованная формула: <?= lc_h($formulaText) ?>.</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Формулы расчёта</h2>
        <p class="formula">
            Резонансная частота: f₀ = 1 / (2π√(L·C)).<br>
            Индуктивность: L = 1 / ((2πf₀)² · C).<br>
            Ёмкость: C = 1 / ((2πf₀)² · L).<br>
            Сопротивление катушки: Xₗ = 2πfL.<br>
            Сопротивление конденсатора: X꜀ = 1 / (2πfC).
        </p>
    </section>
</main>
<script>
(() => {
    const mode = document.getElementById('mode');
    const kind = document.getElementById('reactance_type');
    const kindRow = document.getElementById('reactance-kind-row');
    const aLabel = document.getElementById('a-label');
    const bLabel = document.getElementById('b-label');
    const aValue = document.getElementById('a_value');
    const bValue = document.getElementById('b_value');
    const aUnit = document.getElementById('a_unit');
    const bUnit = document.getElementById('b_unit');
    const result = document.getElementById('calculation-result');
    const error = document.getElementById('calculation-error');
    const hint = document.getElementById('stale-hint');
    const units = {
        frequency: [['hz','Гц'],['khz','кГц'],['mhz','МГц'],['ghz','ГГц']],
        inductance: [['nh','нГн'],['uh','мкГн'],['mh','мГн'],['h','Гн']],
        capacitance: [['pf','пФ'],['nf','нФ'],['uf','мкФ'],['mf','мФ'],['f','Ф']]
    };
    const defaults = { frequency: {value:'10', unit:'uh'}, capacitance: {value:'100', unit:'pf'}, inductance: {value:'7.1', unit:'mhz'} };
    const modes = {
        frequency: {a:'Индуктивность L', aType:'inductance', b:'Ёмкость C', bType:'capacitance'},
        inductance: {a:'Резонансная частота f', aType:'frequency', b:'Ёмкость C', bType:'capacitance'},
        capacitance: {a:'Резонансная частота f', aType:'frequency', b:'Индуктивность L', bType:'inductance'},
        reactance: {a:'Рабочая частота f', aType:'frequency', b:kind.value === 'coil' ? 'Индуктивность катушки L' : 'Ёмкость конденсатора C', bType:kind.value === 'coil' ? 'inductance' : 'capacitance'}
    };
    let previousAType = aUnit.dataset.type || '';
    let previousBType = bUnit.dataset.type || '';

    function setUnitOptions(select, type, desired) {
        const previous = select.value;
        select.replaceChildren();
        for (const [value, label] of units[type]) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            select.appendChild(option);
        }
        const valid = units[type].some(([value]) => value === previous);
        select.value = valid ? previous : desired;
        select.dataset.type = type;
    }
    function labelsForCurrentMode() {
        const cfg = {...modes[mode.value]};
        if (mode.value === 'reactance') {
            cfg.b = kind.value === 'coil' ? 'Индуктивность катушки L' : 'Ёмкость конденсатора C';
            cfg.bType = kind.value === 'coil' ? 'inductance' : 'capacitance';
        }
        return cfg;
    }
    function update(resetChangedFields) {
        const cfg = labelsForCurrentMode();
        kindRow.style.display = mode.value === 'reactance' ? '' : 'none';
        aLabel.textContent = cfg.a;
        bLabel.textContent = cfg.b;
        if (resetChangedFields && previousAType && previousAType !== cfg.aType) {
            aValue.value = cfg.aType === 'frequency' ? '7.1' : (cfg.aType === 'inductance' ? '10' : '100');
        }
        if (resetChangedFields && previousBType && previousBType !== cfg.bType) {
            bValue.value = cfg.bType === 'frequency' ? '7.1' : (cfg.bType === 'inductance' ? '10' : '100');
        }
        const aDefault = cfg.aType === 'frequency' ? 'mhz' : (cfg.aType === 'inductance' ? 'uh' : 'pf');
        const bDefault = cfg.bType === 'frequency' ? 'mhz' : (cfg.bType === 'inductance' ? 'uh' : 'pf');
        setUnitOptions(aUnit, cfg.aType, aDefault);
        setUnitOptions(bUnit, cfg.bType, bDefault);
        previousAType = cfg.aType;
        previousBType = cfg.bType;
    }
    mode.addEventListener('change', () => { update(true); markOutdated(); });
    kind.addEventListener('change', () => { update(true); markOutdated(); });
    if (result || error) {
        document.querySelectorAll('#lc-form input, #lc-form select').forEach(control => {
            control.addEventListener('input', markOutdated);
            control.addEventListener('change', markOutdated);
        });
    }
    function markOutdated() {
        if (result) result.hidden = true;
        if (error) error.hidden = true;
        if (hint) hint.style.display = 'block';
    }
    // Подтверждаем типы элементов, выведенные PHP, и актуализируем подписи без отправки формы.
    aUnit.dataset.type = previousAType;
    bUnit.dataset.type = previousBType;
    update(false);

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
