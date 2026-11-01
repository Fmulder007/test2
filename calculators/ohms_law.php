<?php
/*
 * Универсальный калькулятор закона Ома и мощности для резистивной цепи.
 * Из любых двух известных величин U, I, R, P рассчитывает две остальные.
 */
declare(strict_types=1);

function oh_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function oh_number(string $raw): ?float
{
    $raw = str_replace([' ', "\u{00A0}"], '', trim($raw));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '' || !is_numeric($raw)) return null;
    $number = (float) $raw;
    return ($number >= 0.0 && is_finite($number)) ? $number : null;
}
function oh_num(float $value, int $decimals = 6): string
{
    if (!is_finite($value)) return '—';
    if ($value !== 0.0 && (abs($value) >= 1e12 || abs($value) < 1e-5)) {
        return str_replace('.', ',', sprintf('%.6g', $value));
    }
    $formatted = number_format($value, $decimals, ',', '');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    return ($formatted === '' || $formatted === '-0') ? '0' : $formatted;
}
function oh_selected(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

$quantityNames = ['u' => 'Напряжение U', 'i' => 'Ток I', 'r' => 'Сопротивление R', 'p' => 'Мощность P'];
$unitOptions = [
    'u' => ['pv' => 'пВ', 'nv' => 'нВ', 'uv' => 'мкВ', 'mv' => 'мВ', 'v' => 'В', 'kv' => 'кВ', 'mvlt' => 'МВ'],
    'i' => ['pa' => 'пА', 'na' => 'нА', 'ua' => 'мкА', 'ma' => 'мА', 'a' => 'А', 'ka' => 'кА'],
    'r' => ['uohm' => 'мкОм', 'mohm' => 'мОм', 'ohm' => 'Ом', 'kohm' => 'кОм', 'mohms' => 'МОм', 'gohm' => 'ГОм'],
    'p' => ['pw' => 'пВт', 'nw' => 'нВт', 'uw' => 'мкВт', 'mw' => 'мВт', 'w' => 'Вт', 'kw' => 'кВт', 'mwatt' => 'МВт'],
];
$unitFactors = [
    'u' => ['pv' => 1e-12, 'nv' => 1e-9, 'uv' => 1e-6, 'mv' => 1e-3, 'v' => 1.0, 'kv' => 1e3, 'mvlt' => 1e6],
    'i' => ['pa' => 1e-12, 'na' => 1e-9, 'ua' => 1e-6, 'ma' => 1e-3, 'a' => 1.0, 'ka' => 1e3],
    'r' => ['uohm' => 1e-6, 'mohm' => 1e-3, 'ohm' => 1.0, 'kohm' => 1e3, 'mohms' => 1e6, 'gohm' => 1e9],
    'p' => ['pw' => 1e-12, 'nw' => 1e-9, 'uw' => 1e-6, 'mw' => 1e-3, 'w' => 1.0, 'kw' => 1e3, 'mwatt' => 1e6],
];
$defaultUnits = ['u' => 'v', 'i' => 'ma', 'r' => 'ohm', 'p' => 'w'];
$defaultValues = ['u' => '12', 'i' => '100', 'r' => '50', 'p' => '5'];

$known1 = (string) ($_POST['known1'] ?? 'u');
$known2 = (string) ($_POST['known2'] ?? 'r');
if (!isset($quantityNames[$known1])) $known1 = 'u';
if (!isset($quantityNames[$known2])) $known2 = 'r';
if ($known1 === $known2) {
    $known2 = array_values(array_filter(array_keys($quantityNames), static fn(string $q): bool => $q !== $known1))[0];
}
$value1Raw = trim((string) ($_POST['value1'] ?? $defaultValues[$known1]));
$value2Raw = trim((string) ($_POST['value2'] ?? $defaultValues[$known2]));
$unit1 = (string) ($_POST['unit1'] ?? $defaultUnits[$known1]);
$unit2 = (string) ($_POST['unit2'] ?? $defaultUnits[$known2]);
if (!isset($unitOptions[$known1][$unit1])) $unit1 = $defaultUnits[$known1];
if (!isset($unitOptions[$known2][$unit2])) $unit2 = $defaultUnits[$known2];

$isSubmitted = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['calculate']);
$error = '';
$resultValues = null;
$formulaText = '';
if ($isSubmitted) {
    $input1 = oh_number($value1Raw);
    $input2 = oh_number($value2Raw);
    if ($input1 === null || $input2 === null) {
        $error = 'Оба значения должны быть конечными числами не меньше нуля. Можно использовать десятичную точку или запятую.';
    } else {
        $first = $input1 * $unitFactors[$known1][$unit1];
        $second = $input2 * $unitFactors[$known2][$unit2];
        if (!is_finite($first) || !is_finite($second)) {
            $error = 'Значения находятся за пределами вычислимого диапазона. Проверьте числа и единицы измерения.';
        } elseif (($known1 === 'r' && $first <= 0.0) || ($known2 === 'r' && $second <= 0.0)) {
            $error = 'Если сопротивление задано как исходная величина, оно должно быть больше нуля.';
        } else {
            $v = [$known1 => $first, $known2 => $second];
            $q = array_fill_keys([$known1, $known2], true);
            $ok = true;
            if (isset($q['u'], $q['i'])) {
                $u = $v['u']; $i = $v['i'];
                if ($i <= 0.0) {
                    $ok = false;
                    $error = 'При нулевом токе нельзя однозначно получить конечное сопротивление. Укажите ненулевой ток.';
                } else {
                    $r = $u / $i; $p = $u * $i;
                    $formulaText = 'R = U / I; P = U · I';
                }
            } elseif (isset($q['u'], $q['r'])) {
                $u = $v['u']; $r = $v['r'];
                $i = $u / $r; $p = $u * $u / $r;
                $formulaText = 'I = U / R; P = U² / R';
            } elseif (isset($q['u'], $q['p'])) {
                $u = $v['u']; $p = $v['p'];
                if ($u <= 0.0 || $p <= 0.0) {
                    $ok = false;
                    $error = 'Для пары U и P обе величины должны быть больше нуля, чтобы получить конечное сопротивление и ток.';
                } else {
                    $i = $p / $u; $r = ($u * $u) / $p;
                    $formulaText = 'I = P / U; R = U² / P';
                }
            } elseif (isset($q['i'], $q['r'])) {
                $i = $v['i']; $r = $v['r'];
                $u = $i * $r; $p = $i * $i * $r;
                $formulaText = 'U = I · R; P = I² · R';
            } elseif (isset($q['i'], $q['p'])) {
                $i = $v['i']; $p = $v['p'];
                if ($i <= 0.0) {
                    $ok = false;
                    $error = $p > 0.0 ? 'Нулевая сила тока не может обеспечивать заданную ненулевую мощность.' : 'При нулевых токе и мощности сопротивление определить невозможно.';
                } else {
                    $u = $p / $i; $r = $p / ($i * $i);
                    $formulaText = 'U = P / I; R = P / I²';
                }
            } elseif (isset($q['r'], $q['p'])) {
                $r = $v['r']; $p = $v['p'];
                $u = sqrt($p * $r); $i = sqrt($p / $r);
                $formulaText = 'U = √(P · R); I = √(P / R)';
            } else {
                $ok = false;
                $error = 'Не удалось распознать выбранную комбинацию величин.';
            }
            if ($ok) {
                $resultValues = ['u' => $u, 'i' => $i, 'r' => $r, 'p' => $p];
                foreach ($resultValues as $quantity => $number) {
                    if (!is_finite($number) || $number < 0.0) {
                        $resultValues = null;
                        $error = 'Результат находится за пределами вычислимого диапазона. Проверьте исходные значения.';
                        break;
                    }
                }
            }
        }
    }
}

function oh_formatVoltage(float $v): string
{
    if ($v == 0.0) return '0 В';
    if ($v >= 1e6) return oh_num($v / 1e6) . ' МВ';
    if ($v >= 1e3) return oh_num($v / 1e3) . ' кВ';
    if ($v >= 1.0) return oh_num($v) . ' В';
    if ($v >= 1e-3) return oh_num($v / 1e-3) . ' мВ';
    if ($v >= 1e-6) return oh_num($v / 1e-6) . ' мкВ';
    if ($v >= 1e-9) return oh_num($v / 1e-9) . ' нВ';
    return oh_num($v / 1e-12) . ' пВ';
}
function oh_formatCurrent(float $i): string
{
    if ($i == 0.0) return '0 А';
    if ($i >= 1e3) return oh_num($i / 1e3) . ' кА';
    if ($i >= 1.0) return oh_num($i) . ' А';
    if ($i >= 1e-3) return oh_num($i / 1e-3) . ' мА';
    if ($i >= 1e-6) return oh_num($i / 1e-6) . ' мкА';
    if ($i >= 1e-9) return oh_num($i / 1e-9) . ' нА';
    return oh_num($i / 1e-12) . ' пА';
}
function oh_formatResistance(float $r): string
{
    if ($r == 0.0) return '0 Ом';
    if ($r >= 1e9) return oh_num($r / 1e9) . ' ГОм';
    if ($r >= 1e6) return oh_num($r / 1e6) . ' МОм';
    if ($r >= 1e3) return oh_num($r / 1e3) . ' кОм';
    if ($r >= 1.0) return oh_num($r) . ' Ом';
    if ($r >= 1e-3) return oh_num($r / 1e-3) . ' мОм';
    if ($r >= 1e-6) return oh_num($r / 1e-6) . ' мкОм';
    return oh_num($r / 1e-9) . ' нОм';
}
function oh_formatPower(float $p): string
{
    if ($p == 0.0) return '0 Вт';
    if ($p >= 1e6) return oh_num($p / 1e6) . ' МВт';
    if ($p >= 1e3) return oh_num($p / 1e3) . ' кВт';
    if ($p >= 1.0) return oh_num($p) . ' Вт';
    if ($p >= 1e-3) return oh_num($p / 1e-3) . ' мВт';
    if ($p >= 1e-6) return oh_num($p / 1e-6) . ' мкВт';
    if ($p >= 1e-9) return oh_num($p / 1e-9) . ' нВт';
    return oh_num($p / 1e-12) . ' пВт';
}
$formatters = ['u' => 'oh_formatVoltage', 'i' => 'oh_formatCurrent', 'r' => 'oh_formatResistance', 'p' => 'oh_formatPower'];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Универсальный калькулятор закона Ома — радиолюбительские калькуляторы</title>
    <meta name="description" content="Универсальный расчёт напряжения, тока, сопротивления и мощности по любым двум известным величинам с удобными единицами измерения.">
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
        .calculator-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 22px; align-items: start; }
         .diagram-column, .form-column { min-width: 0; }
         .diagram { margin: 0 0 14px; }
         .form-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; align-items: end; }
        .field { min-width: 0; }
        .field-label { display: block; font-weight: 700; margin-bottom: 6px; }
        select, input, button { font: inherit; }
        .value-row { display: grid; grid-template-columns: minmax(0, 1fr) 112px; gap: 8px; }
        .value-input, .unit-select, .quantity-select { width: 100%; min-width: 0; border: 1px solid #b8c0c8; border-radius: 6px; padding: 10px; background: #fff; }
        .value-input:focus, .unit-select:focus, .quantity-select:focus { outline: 3px solid #c5e2f8; border-color: #3976a8; }
        .mode-field { margin-bottom: 15px; }
        .action-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 17px; }
        button { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; border: 0; border-radius: 6px; padding: 11px 16px; color: #fff; background: #245c88; font-weight: 700; line-height: 1.25; cursor: pointer; }
        button:hover { background: #19476c; }
        button:focus-visible, a:focus-visible { outline: 3px solid #89bde5; outline-offset: 3px; }
        .result { margin-top: 18px; padding: 16px; border: 1px solid #bfd8c6; border-radius: 8px; background: #f3faf5; overflow-wrap: anywhere; }
        .result-heading { margin: 0 0 12px; color: #244b32; font-size: 1.2rem; }
        .results-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .result-item { min-width: 0; border: 1px solid #d5e6da; border-radius: 7px; padding: 12px; background: rgba(255,255,255,.72); }
        .result-label { display: block; color: #385744; font-size: .92rem; margin-bottom: 6px; }
        .result-value { font-weight: 700; font-size: clamp(1.25rem, 3vw, 1.6rem); overflow-wrap: anywhere; color: #173c25; }
        .source-tag { color: #5f6368; font-size: .82rem; font-weight: 400; }
        .formula { margin-bottom: 0; color: #4d5b52; font-size: .95rem; line-height: 1.65; }
        .error { margin-top: 16px; padding: 12px; border: 1px solid #e4b7b7; border-radius: 8px; background: #fff5f5; color: #8b2323; line-height: 1.45; }
        .stale-hint { display: none; margin-top: 12px; color: #7a5200; font-size: .93rem; }
        .small { font-size: .9rem; }
        .clear-link { color: #244f76; }
        @media (max-width: 680px) { body { padding: 10px; } .panel { padding: 15px; } .calculator-layout { grid-template-columns: minmax(0, 1fr); gap: 14px; } .results-grid { grid-template-columns: minmax(0, 1fr); } .diagram { margin: 0 0 12px; } }
        @media print { body { padding: 0; background: #fff; } .panel { border-color: #aaa; break-inside: avoid; } .no-print, .back-link, button, .clear-link, .stale-hint { display: none !important; } .result { border-color: #999; background: #fff; } }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Универсальный калькулятор U / I / R / P</h1>
        <p class="subtle">Выбери любые две известные величины из напряжения, тока, сопротивления и мощности — калькулятор найдёт две остальные. Подходит для расчётов резистивных цепей постоянного тока.</p>
        <a class="back-link" href="index.php">← К списку калькуляторов</a>
    </section>

    <section class="panel">
        <div class="calculator-layout">
            <div class="diagram-column">
                <img class="diagram" src="images/ohms-law.svg" alt="Схема резистивной цепи с напряжением U, током I, сопротивлением R и мощностью P" width="760" height="240">
                <div class="note"><strong>Область применения:</strong> формулы рассчитаны на резистивную цепь. Для ВЧ- или другой переменнотоковой цепи с реактивной нагрузкой нужны дополнительные данные об импедансе и фазе.</div>
            </div>
            <div class="form-column">
                <h2>Исходные данные</h2>
                <form id="ohm-form" method="post" action="">
            <div class="form-grid">
                <div class="field">
                    <label class="field-label" for="known1">Известная величина № 1</label>
                    <select class="quantity-select" id="known1" name="known1">
                        <?php foreach ($quantityNames as $key => $label): ?><option value="<?= oh_h($key) ?>"<?= oh_selected($known1, $key) ?>><?= oh_h($label) ?></option><?php endforeach; ?>
                    </select>
                    <div class="value-row" style="margin-top:8px">
                        <input class="value-input" id="value1" name="value1" type="text" inputmode="decimal" autocomplete="off" value="<?= oh_h($value1Raw) ?>" required>
                        <select class="unit-select" id="unit1" name="unit1" aria-label="Единица первой величины">
                            <?php foreach ($unitOptions[$known1] as $unit => $label): ?><option value="<?= oh_h($unit) ?>"<?= oh_selected($unit1, $unit) ?>><?= oh_h($label) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="known2">Известная величина № 2</label>
                    <select class="quantity-select" id="known2" name="known2">
                        <?php foreach ($quantityNames as $key => $label): ?><option value="<?= oh_h($key) ?>"<?= oh_selected($known2, $key) ?>><?= oh_h($label) ?></option><?php endforeach; ?>
                    </select>
                    <div class="value-row" style="margin-top:8px">
                        <input class="value-input" id="value2" name="value2" type="text" inputmode="decimal" autocomplete="off" value="<?= oh_h($value2Raw) ?>" required>
                        <select class="unit-select" id="unit2" name="unit2" aria-label="Единица второй величины">
                            <?php foreach ($unitOptions[$known2] as $unit => $label): ?><option value="<?= oh_h($unit) ?>"<?= oh_selected($unit2, $unit) ?>><?= oh_h($label) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="action-row">
                <button type="submit" name="calculate" value="1">Рассчитать</button>
                <a class="clear-link" href="ohms_law.php">Сбросить</a>
            </div>
            <div class="stale-hint" id="stale-hint">Исходные данные изменены. Нажми «Рассчитать», чтобы обновить результат.</div>
                </form>
            </div>
        </div>
        <?php if ($error !== ''): ?><div class="error" id="calculation-error" role="alert"><?= oh_h($error) ?></div><?php endif; ?>
        <?php if ($resultValues !== null): ?>
            <div class="result" id="calculation-result" aria-live="polite">
                <h2 class="result-heading">Результат расчёта</h2>
                <div class="results-grid">
                    <?php foreach (['u' => 'oh_formatVoltage', 'i' => 'oh_formatCurrent', 'r' => 'oh_formatResistance', 'p' => 'oh_formatPower'] as $key => $formatter): ?>
                        <div class="result-item">
                            <span class="result-label"><?= oh_h($quantityNames[$key]) ?> <span class="source-tag"><?= ($key === $known1 || $key === $known2) ? 'введено' : 'рассчитано' ?></span></span>
                            <div class="result-value"><?= oh_h($formatter($resultValues[$key])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="formula" style="margin-top:12px">Использованные соотношения: <?= oh_h($formulaText) ?>.</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Используемые соотношения</h2>
        <p class="formula">
            U = I · R; I = U / R; R = U / I.<br>
            P = U · I = I² · R = U² / R.<br>
            Если известны R и P: U = √(P · R), I = √(P / R).
        </p>
        <p class="subtle small">Допускаются десятичная точка и запятая. Нулевые напряжение, ток или мощность разрешены там, где результат можно определить однозначно. Сопротивление в исходных данных должно быть больше нуля.</p>
    </section>
</main>
<script>
(() => {
    const q1 = document.getElementById('known1');
    const q2 = document.getElementById('known2');
    const v1 = document.getElementById('value1');
    const v2 = document.getElementById('value2');
    const u1 = document.getElementById('unit1');
    const u2 = document.getElementById('unit2');
    const result = document.getElementById('calculation-result');
    const error = document.getElementById('calculation-error');
    const hint = document.getElementById('stale-hint');
    const names = {u:'Напряжение U', i:'Ток I', r:'Сопротивление R', p:'Мощность P'};
    const units = {
        u: [['pv','пВ'],['nv','нВ'],['uv','мкВ'],['mv','мВ'],['v','В'],['kv','кВ'],['mvlt','МВ']],
        i: [['pa','пА'],['na','нА'],['ua','мкА'],['ma','мА'],['a','А'],['ka','кА']],
        r: [['uohm','мкОм'],['mohm','мОм'],['ohm','Ом'],['kohm','кОм'],['mohms','МОм'],['gohm','ГОм']],
        p: [['pw','пВт'],['nw','нВт'],['uw','мкВт'],['mw','мВт'],['w','Вт'],['kw','кВт'],['mwatt','МВт']]
    };
    const defaults = {u:{value:'12',unit:'v'},i:{value:'100',unit:'ma'},r:{value:'50',unit:'ohm'},p:{value:'5',unit:'w'}};
    function setUnits(select, quantity) {
        const prior = select.value;
        select.replaceChildren();
        for (const [value,label] of units[quantity]) {
            const option = document.createElement('option');
            option.value = value; option.textContent = label; select.appendChild(option);
        }
        select.value = units[quantity].some(([value]) => value === prior) ? prior : defaults[quantity].unit;
    }
    function updateLabels(reset1 = false, reset2 = false) {
        document.querySelector('label[for="known1"]').textContent = 'Известная величина № 1 — ' + names[q1.value];
        document.querySelector('label[for="known2"]').textContent = 'Известная величина № 2 — ' + names[q2.value];
        setUnits(u1, q1.value); setUnits(u2, q2.value);
        if (reset1) v1.value = defaults[q1.value].value;
        if (reset2) v2.value = defaults[q2.value].value;
    }
    function handleQuantityChange(changed) {
        if (q1.value === q2.value) {
            const other = changed === 1 ? q2 : q1;
            other.value = ['u','i','r','p'].find(item => item !== (changed === 1 ? q1.value : q2.value));
            updateLabels(true, true);
        } else {
            updateLabels(changed === 1, changed === 2);
        }
        markOutdated();
    }
    q1.addEventListener('change', () => handleQuantityChange(1));
    q2.addEventListener('change', () => handleQuantityChange(2));
    if (result || error) {
        document.querySelectorAll('#ohm-form input, #ohm-form select').forEach(control => {
            control.addEventListener('input', markOutdated);
            control.addEventListener('change', markOutdated);
        });
    }
    function markOutdated() {
        if (result) result.hidden = true;
        if (error) error.hidden = true;
        if (hint) hint.style.display = 'block';
    }
    updateLabels(false, false);
})();
</script>
</body>
</html>
