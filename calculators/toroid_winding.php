<?php
declare(strict_types=1);

/*
 * Калькулятор тороидальной катушки.
 * Справочник серийных сердечников находится отдельно: toroid_cores.php.
 */

$toroidCores = require __DIR__ . '/toroid_cores.php';

function tw_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tw_number(string $raw): ?float
{
    $raw = trim(str_replace(["\u{00A0}", "\u{202F}", ' '], '', $raw));
    if ($raw === '') return null;
    $raw = str_replace(',', '.', $raw);
    if (!is_numeric($raw)) return null;
    $value = (float)$raw;
    return is_finite($value) ? $value : null;
}

function tw_fmt(float $value, int $decimals = 5): string
{
    if (!is_finite($value)) return '—';
    if ($value != 0.0 && (abs($value) >= 1e10 || abs($value) < 1e-4)) {
        return str_replace('.', ',', sprintf('%.5g', $value));
    }
    $formatted = number_format($value, $decimals, ',', ' ');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    if ($formatted === '' || $formatted === '-0') return '0';
    return $formatted;
}

function tw_formatInductance(float $nH): string
{
    if ($nH >= 1e9) return tw_fmt($nH / 1e9) . ' Гн';
    if ($nH >= 1e6) return tw_fmt($nH / 1e6) . ' мГн';
    if ($nH >= 1e3) return tw_fmt($nH / 1e3) . ' мкГн';
    return tw_fmt($nH) . ' нГн';
}

function tw_selected(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

$inductanceUnits = [
    'nh' => ['label' => 'нГн', 'factor' => 1.0],
    'uh' => ['label' => 'мкГн', 'factor' => 1e3],
    'mh' => ['label' => 'мГн', 'factor' => 1e6],
    'h'  => ['label' => 'Гн', 'factor' => 1e9],
];

$coreId = (string)($_POST['core_id'] ?? 't50-2');
if ($coreId !== 'custom' && !isset($toroidCores[$coreId])) $coreId = 't50-2';
$isCustom = $coreId === 'custom';

$targetRaw = trim((string)($_POST['target_l'] ?? '1'));
$targetUnit = (string)($_POST['target_unit'] ?? 'uh');
if (!isset($inductanceUnits[$targetUnit])) $targetUnit = 'uh';

$customMode = (string)($_POST['custom_mode'] ?? 'mu');
if (!in_array($customMode, ['mu', 'measured'], true)) $customMode = 'mu';

$customRaw = [
    'od' => trim((string)($_POST['custom_od'] ?? '12.7')),
    'id' => trim((string)($_POST['custom_id'] ?? '7.7')),
    'h'  => trim((string)($_POST['custom_h'] ?? '4.83')),
    'mu' => trim((string)($_POST['custom_mu'] ?? '10')),
    'test_l' => trim((string)($_POST['custom_test_l'] ?? '25')),
    'test_unit' => (string)($_POST['custom_test_unit'] ?? 'uh'),
    'test_turns' => trim((string)($_POST['custom_test_turns'] ?? '10')),
];
if (!isset($inductanceUnits[$customRaw['test_unit']])) $customRaw['test_unit'] = 'uh';

$isSubmitted = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['calculate']);
$error = '';
$result = null;

$selectedCore = $isCustom ? null : $toroidCores[$coreId];

if ($isSubmitted) {
    $targetInput = tw_number($targetRaw);
    if ($targetInput === null || $targetInput <= 0.0) {
        $error = 'Укажи требуемую индуктивность больше нуля. Допускается точка или запятая в качестве десятичного разделителя.';
    } else {
        $targetNh = $targetInput * $inductanceUnits[$targetUnit]['factor'];
        if (!is_finite($targetNh) || $targetNh <= 0.0) {
            $error = 'Индуктивность находится за пределами вычислимого диапазона. Проверь единицы и число.';
        }
    }

    if ($error === '') {
        if ($isCustom) {
            $outer = tw_number($customRaw['od']);
            $inner = tw_number($customRaw['id']);
            $height = tw_number($customRaw['h']);

            if ($outer === null || $inner === null || $height === null ||
                $outer <= 0.0 || $inner <= 0.0 || $height <= 0.0 || $outer <= $inner) {
                $error = 'Проверь размеры пользовательского сердечника: D и H должны быть больше нуля, а D должен быть больше d.';
            } else {
                $aeMm2 = (($outer - $inner) / 2.0) * $height;
                $leMm = M_PI * (($outer + $inner) / 2.0);

                if ($customMode === 'measured') {
                    // A_L определяется непосредственно по измеренной индуктивности
                    // равномерно распределённой пробной обмотки: A_L = L / N².
                    $probeInput = tw_number($customRaw['test_l']);
                    $probeTurnsInput = tw_number($customRaw['test_turns']);
                    if ($probeInput === null || $probeInput <= 0.0 ||
                        $probeTurnsInput === null || $probeTurnsInput < 1.0 ||
                        $probeTurnsInput > 1000000.0 || abs($probeTurnsInput - round($probeTurnsInput)) > 1e-9) {
                        $error = 'Для измерительного режима укажи положительную измеренную индуктивность и целое число пробных витков от 1 до 1 000 000.';
                    } else {
                        $probeNh = $probeInput * $inductanceUnits[$customRaw['test_unit']]['factor'];
                        $probeTurns = (int)round($probeTurnsInput);
                        $alNh = $probeNh / ($probeTurns * $probeTurns);
                        if (!is_finite($probeNh) || $probeNh <= 0.0 || !is_finite($alNh) || $alNh <= 0.0) {
                            $error = 'Не удалось получить корректный параметр A_L из измерения. Проверь значение индуктивности и единицы.';
                        } else {
                            // Эффективная μ оценивается из A_L и размеров кольца.
                            // Она ориентировочная; дальнейший расчёт витков опирается на измеренный A_L.
                            $mu = ($alNh * $leMm) / (1.2566370614359172 * $aeMm2);
                            if (!is_finite($mu) || $mu <= 0.0) {
                                $error = 'Не удалось вычислить эффективную магнитную проницаемость. Проверь размеры кольца.';
                            } else {
                                $alSource = 'по измерению пробной обмотки';
                            }
                        }
                    }
                } else {
                    $mu = tw_number($customRaw['mu']);
                    if ($mu === null || $mu <= 0.0) {
                        $error = 'Укажи относительную магнитную проницаемость μ больше нуля.';
                    } else {
                        // Ae ≈ ((D-d)/2)*H; le ≈ π*(D+d)/2; AL ≈ μ0*μr*Ae/le.
                        $alNh = 1.2566370614359172 * $mu * $aeMm2 / $leMm;
                        $alSource = 'оценка по размерам и μ';
                    }
                }

                if ($error === '') {
                    $core = [
                        'name' => 'Пользовательский тороид',
                        'od_mm' => $outer,
                        'id_mm' => $inner,
                        'h_mm' => $height,
                        'mu_r' => $mu,
                        'al_nh' => null,
                        'color' => 'Не указан',
                        'frequency' => $customMode === 'measured'
                            ? 'A_L получен по измеренной пробной обмотке; μ оценена по геометрии'
                            : 'Параметры введены вручную',
                        'source' => '',
                    ];
                }
            }
        } else {
            $core = $toroidCores[$coreId];
            $outer = (float)$core['od_mm'];
            $inner = (float)$core['id_mm'];
            $height = (float)$core['h_mm'];
            $mu = (float)$core['mu_r'];
            $aeMm2 = (($outer - $inner) / 2.0) * $height;
            $leMm = M_PI * (($outer + $inner) / 2.0);
            if ($core['al_nh'] !== null) {
                $alNh = (float)$core['al_nh'];
                $alSource = 'паспортное значение A_L';
            } else {
                $alNh = 1.2566370614359172 * $mu * $aeMm2 / $leMm;
                $alSource = 'оценка по размерам и μ';
            }
        }

        if ($error === '') {
            if (!is_finite($alNh) || $alNh <= 0.0) {
                $error = 'Не удалось получить корректное значение A_L для выбранного сердечника.';
            } else {
                $exactTurns = sqrt($targetNh / $alNh);
                if (!is_finite($exactTurns) || $exactTurns > 1000000.0) {
                    $error = 'Для такой индуктивности получилось слишком много витков для надёжного расчёта. Проверь материал и единицы измерения.';
                } else {
                    // Округляем рекомендуемое число витков только вверх.
                    // Небольшой допуск устраняет ошибки двоичной арифметики у точного целого.
                    $turnTolerance = 1e-12 * max(1.0, abs($exactTurns));
                    $turns = max(1, (int)ceil($exactTurns - $turnTolerance));
                    $actualNh = $alNh * $turns * $turns;
                    $errorPercent = (($actualNh - $targetNh) / $targetNh) * 100.0;

                    // Для однослойной укладки ограничение по внутренней окружности:
                    // число витков ≈ окружность / наружный диаметр изолированного провода.
                    $innerCircumference = M_PI * $inner;
                    $pitchLimit = $innerCircumference / $turns;
                    // Провод должен помещаться в центральное отверстие; для очень
                    // малого числа витков это может стать более строгим пределом,
                    // чем расчётный шаг по внутренней окружности.
                    $absoluteWireLimit = min($pitchLimit, $inner);
                    $practicalWireLimit = $absoluteWireLimit * 0.80;

                    $result = [
                        'core' => $core,
                        'outer' => $outer,
                        'inner' => $inner,
                        'height' => $height,
                        'mu' => $mu,
                        'ae_mm2' => $aeMm2,
                        'le_mm' => $leMm,
                        'al_nh' => $alNh,
                        'al_source' => $alSource,
                        'target_nh' => $targetNh,
                        'exact_turns' => $exactTurns,
                        'turns' => $turns,
                        'actual_nh' => $actualNh,
                        'error_percent' => $errorPercent,
                        'inside_circumference' => $innerCircumference,
                        'pitch_limit_mm' => $pitchLimit,
                        'absolute_wire_limit_mm' => $absoluteWireLimit,
                        'practical_wire_limit_mm' => $practicalWireLimit,
                    ];
                }
            }
        }
    }
}

$browserCoreData = [];
foreach ($toroidCores as $id => $coreInfo) {
    $browserCoreData[$id] = [
        'name' => $coreInfo['name'],
        'od_mm' => $coreInfo['od_mm'],
        'id_mm' => $coreInfo['id_mm'],
        'h_mm' => $coreInfo['h_mm'],
        'mu_r' => $coreInfo['mu_r'],
        'al_nh' => $coreInfo['al_nh'],
        'color' => $coreInfo['color'],
        'frequency' => $coreInfo['frequency'],
        'source' => $coreInfo['source'],
    ];
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Расчёт витков на тороидальном сердечнике — радиолюбительские калькуляторы</title>
    <meta name="description" content="Расчёт числа витков тороидальной катушки по индуктивности и материалу сердечника, с оценкой диаметра провода для однослойной укладки.">
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #202124; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 1000px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #d7dce1; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: clamp(1.45rem, 3vw, 2rem); }
        h2 { margin: 0 0 12px; font-size: 1.25rem; }
        h3 { margin: 0 0 8px; font-size: 1.05rem; }
        p { line-height: 1.5; }
        .subtle { color: #5f6368; }
        .back-link { display: inline-block; margin-top: 4px; color: #244f76; font-weight: 700; text-decoration: none; }
        .back-link:hover, .clear-link:hover { text-decoration: underline; }
        .diagram { display: block; width: 100%; height: auto; margin: 0 0 14px; border: 1px solid #d7dce1; border-radius: 8px; background: #fff; }
         .calculator-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 22px; align-items: start; }
         .diagram-column, .form-column { min-width: 0; }
        .note { padding: 12px 14px; border-left: 4px solid #3c7ca7; background: #f2f7fb; border-radius: 5px; line-height: 1.5; }
        .form-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; align-items: end; }
        .field { min-width: 0; }
        .field-label { display: block; font-weight: 700; margin-bottom: 6px; }
        select, input, button { font: inherit; }
        .value-row { display: grid; grid-template-columns: minmax(0, 1fr) 120px; gap: 8px; }
        .value-input, .unit-select, .mode-select { width: 100%; min-width: 0; border: 1px solid #b8c0c8; border-radius: 6px; padding: 10px; background: #fff; }
        .value-input:focus, .unit-select:focus, .mode-select:focus { outline: 3px solid #c5e2f8; border-color: #3976a8; }
        .mode-field { margin-bottom: 15px; }
        .radio-options { display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px; }
        .radio-option { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border: 1px solid #d7dce1; border-radius: 6px; background: #fff; cursor: pointer; line-height: 1.4; }
        .radio-option input { flex: 0 0 auto; margin: 3px 0 0; accent-color: #245c88; }
        .radio-option:has(input:checked) { border-color: #8aafcb; background: #f2f7fb; }
        .custom-core-fields { margin-top: 14px; padding: 14px; border: 1px solid #d7dce1; border-radius: 8px; background: #fafbfd; }
        .custom-core-fields[hidden], .custom-measured-fields[hidden] { display: none; }
        .core-info { margin-top: 12px; padding: 12px 14px; border: 1px solid #dbe4eb; border-radius: 8px; background: #f7fafc; line-height: 1.55; }
        .core-info[hidden] { display: none; }
        .action-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 17px; }
        button { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; border: 0; border-radius: 6px; padding: 11px 16px; color: #fff; background: #245c88; font-weight: 700; line-height: 1.25; cursor: pointer; }
        button:hover { background: #19476c; }
        button:focus-visible, a:focus-visible { outline: 3px solid #89bde5; outline-offset: 3px; }
        .result { margin-top: 18px; padding: 16px; border: 1px solid #bfd8c6; border-radius: 8px; background: #f3faf5; overflow-wrap: anywhere; }
        .result-heading { margin: 0 0 10px; color: #244b32; font-size: 1.1rem; }
        .result-value { font-size: clamp(1.8rem, 4vw, 2.5rem); line-height: 1.2; font-weight: 700; color: #173c25; }
        .result-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 14px; }
        .result-cell { padding: 12px; border-radius: 7px; background: rgba(255,255,255,.85); border: 1px solid #d8e7dc; }
        .result-label { margin-bottom: 4px; color: #5f6f63; font-size: .9rem; }
        .result-number { font-weight: 700; font-size: 1.2rem; color: #173c25; }
        .formula { margin-bottom: 0; color: #4d5b52; font-size: .95rem; line-height: 1.65; }
        .error { margin-top: 16px; padding: 12px; border: 1px solid #e4b7b7; border-radius: 8px; background: #fff5f5; color: #8b2323; line-height: 1.45; }
        .small { font-size: .9rem; }
        .clear-link { color: #244f76; }
        @media (max-width: 680px) { body { padding: 10px; } .panel { padding: 15px; } .calculator-layout { grid-template-columns: minmax(0, 1fr); gap: 14px; } .result-grid { grid-template-columns: minmax(0, 1fr); } .diagram { margin: 0 0 12px; } .value-row { grid-template-columns: minmax(0, 1fr) 105px; } .action-row { flex-direction: column; align-items: stretch; } .action-row button { width: 100%; } }
        @media print { body { padding: 0; background: #fff; } .panel { border-color: #aaa; break-inside: avoid; } .no-print, .back-link, button, .clear-link { display: none !important; } .result { border-color: #999; background: #fff; } }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Расчёт витков на тороидальном сердечнике</h1>
        <p class="subtle">Укажи сердечник и требуемую индуктивность — калькулятор подберёт целое число витков с округлением вверх и оценит максимальный диаметр провода для однослойной намотки.</p>
        <a class="back-link" href="index.php">← К списку калькуляторов</a>
    </section>

    <section class="panel">
        <div class="calculator-layout">
            <div class="diagram-column">
                <img class="diagram" src="images/toroid-winding.svg" alt="Тороидальный сердечник с однослойной обмоткой и обозначениями наружного, внутреннего диаметров и высоты" width="760" height="250">
                <div class="note"><strong>Важно о справочнике:</strong> для T50/T68 используется паспортный параметр A<sub>L</sub>. Для пользовательского кольца можно задать μ либо определить параметр A<sub>L</sub> по измеренной индуктивности равномерно распределённой пробной обмотки. Во втором случае эффективная μ также оценивается по размерам кольца.</div>
            </div>
            <div class="form-column">
                <h2>Исходные данные</h2>
                <form method="post" action="">
            <div class="mode-field">
                <label class="field-label" for="core_id">Тип сердечника</label>
                <select class="mode-select" id="core_id" name="core_id">
                    <optgroup label="Amidon — кольца из порошкового железа">
                        <?php foreach ($toroidCores as $id => $coreOption): ?>
                            <option value="<?= tw_h($id) ?>"<?= tw_selected($coreId, $id) ?>><?= tw_h($coreOption['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <option value="custom"<?= tw_selected($coreId, 'custom') ?>>Своё кольцо: задать D, d, H и μ</option>
                </select>
                <div class="core-info" id="core-info"<?= $isCustom ? ' hidden' : '' ?>>
                    <?php if ($selectedCore !== null): ?>
                        <strong><?= tw_h($selectedCore['name']) ?></strong><br>
                        D = <?= tw_h(tw_fmt((float)$selectedCore['od_mm'])) ?> мм; d = <?= tw_h(tw_fmt((float)$selectedCore['id_mm'])) ?> мм; H = <?= tw_h(tw_fmt((float)$selectedCore['h_mm'])) ?> мм.<br>
                        μ = <?= tw_h(tw_fmt((float)$selectedCore['mu_r'])) ?>; A<sub>L</sub> = <?= tw_h(tw_fmt((float)$selectedCore['al_nh'])) ?> нГн/виток²; <?= tw_h($selectedCore['color']) ?>.<br>
                        <span class="subtle"><?= tw_h($selectedCore['frequency']) ?></span><br>
                        <a id="core-source" href="<?= tw_h($selectedCore['source']) ?>" target="_blank" rel="noopener noreferrer">Паспортные данные производителя</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="custom-core-fields" id="custom-core-fields"<?= $isCustom ? '' : ' hidden' ?>>
                <h3>Размеры пользовательского кольца</h3>
                <p class="subtle small">Все размеры указываются в миллиметрах. Если μ неизвестна, можно определить A<sub>L</sub> по индуктивности пробной обмотки, равномерно распределённой по окружности кольца.</p>
                <div class="form-grid">
                    <div class="field">
                        <label class="field-label" for="custom_od">Наружный диаметр D, мм</label>
                        <input class="value-input" id="custom_od" name="custom_od" type="text" inputmode="decimal" value="<?= tw_h($customRaw['od']) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label" for="custom_id">Внутренний диаметр d, мм</label>
                        <input class="value-input" id="custom_id" name="custom_id" type="text" inputmode="decimal" value="<?= tw_h($customRaw['id']) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label" for="custom_h">Высота H, мм</label>
                        <input class="value-input" id="custom_h" name="custom_h" type="text" inputmode="decimal" value="<?= tw_h($customRaw['h']) ?>">
                    </div>
                </div>
                <div class="mode-field" style="margin-top:16px">
                    <span class="field-label" id="custom_mode_label">Как известны параметры материала?</span>
                    <div class="radio-options" role="radiogroup" aria-labelledby="custom_mode_label">
                        <label class="radio-option">
                            <input type="radio" name="custom_mode" value="mu"<?= $customMode === 'mu' ? ' checked' : '' ?>>
                            <span>Известна относительная магнитная проницаемость μ</span>
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="custom_mode" value="measured"<?= $customMode === 'measured' ? ' checked' : '' ?>>
                            <span>Измерена индуктивность пробной обмотки</span>
                        </label>
                    </div>
                </div>
                <div id="custom-mu-field" class="mode-field"<?= $customMode === 'measured' ? ' hidden' : '' ?>>
                    <label class="field-label" for="custom_mu">Относительная проницаемость μ</label>
                    <input class="value-input" id="custom_mu" name="custom_mu" type="text" inputmode="decimal" value="<?= tw_h($customRaw['mu']) ?>">
                </div>
                <div id="custom-measured-fields" class="custom-measured-fields"<?= $customMode !== 'measured' ? ' hidden' : '' ?>>
                    <div class="form-grid">
                        <div class="field">
                            <label class="field-label" for="custom_test_l">Измеренная индуктивность</label>
                            <div class="value-row">
                                <input class="value-input" id="custom_test_l" name="custom_test_l" type="text" inputmode="decimal" value="<?= tw_h($customRaw['test_l']) ?>">
                                <select class="unit-select" id="custom_test_unit" name="custom_test_unit" aria-label="Единица измеренной индуктивности">
                                    <?php foreach ($inductanceUnits as $unitKey => $unit): ?>
                                        <option value="<?= tw_h($unitKey) ?>"<?= tw_selected($customRaw['test_unit'], $unitKey) ?>><?= tw_h($unit['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="field">
                            <label class="field-label" for="custom_test_turns">Число пробных витков</label>
                            <input class="value-input" id="custom_test_turns" name="custom_test_turns" type="text" inputmode="numeric" value="<?= tw_h($customRaw['test_turns']) ?>">
                        </div>
                    </div>
                    <p class="subtle small" style="margin-bottom:0">Пробные витки должны быть распределены равномерно по окружности кольца. По измерению калькулятор определит A<sub>L</sub>, а затем оценит эффективную μ по размерам D, d и H.</p>
                </div>
            </div>

            <div class="mode-field" style="margin-top:16px">
                <label class="field-label" for="target_l">Требуемая индуктивность</label>
                <div class="value-row">
                    <input class="value-input" id="target_l" name="target_l" type="text" inputmode="decimal" autocomplete="off" value="<?= tw_h($targetRaw) ?>" required>
                    <select class="unit-select" id="target_unit" name="target_unit" aria-label="Единица индуктивности">
                        <?php foreach ($inductanceUnits as $unitKey => $unit): ?>
                            <option value="<?= tw_h($unitKey) ?>"<?= tw_selected($targetUnit, $unitKey) ?>><?= tw_h($unit['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="action-row">
                <button type="submit" name="calculate" value="1">Рассчитать</button>
                <a class="clear-link" href="toroid_winding.php">Сбросить</a>
            </div>
                </form>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="error" role="alert"><?= tw_h($error) ?></div><?php endif; ?>

        <?php if ($result !== null): ?>
            <div class="result" aria-live="polite">
                <h2 class="result-heading">Результат расчёта</h2>
                <div class="result-label">Рекомендуемое целое число витков</div>
                <div class="result-value"><?= tw_h((string)$result['turns']) ?> витков</div>
                <div class="result-grid">
                    <div class="result-cell">
                        <div class="result-label">Расчётное число витков до округления</div>
                        <div class="result-number"><?= tw_h(tw_fmt((float)$result['exact_turns'], 3)) ?></div>
                    </div>
                    <div class="result-cell">
                        <div class="result-label">Индуктивность при <?= tw_h((string)$result['turns']) ?> витках</div>
                        <div class="result-number"><?= tw_h(tw_formatInductance((float)$result['actual_nh'])) ?></div>
                    </div>
                    <div class="result-cell">
                        <div class="result-label">Отклонение от заданной индуктивности</div>
                        <div class="result-number"><?= tw_h(($result['error_percent'] > 0 ? '+' : '') . tw_fmt((float)$result['error_percent'], 3)) ?>%</div>
                    </div>
                    <div class="result-cell">
                        <div class="result-label">Параметр сердечника A<sub>L</sub></div>
                        <div class="result-number"><?= tw_h(tw_fmt((float)$result['al_nh'])) ?> нГн/виток²</div>
                        <div class="small subtle"><?= tw_h($result['al_source']) ?></div>
                    </div>
                </div>

                <h3 style="margin-top:18px">Провод для однослойной обмотки</h3>
                <div class="result-grid">
                    <div class="result-cell">
                        <div class="result-label">Предельный диаметр по внутренней окружности</div>
                        <div class="result-number"><?= tw_h(tw_fmt((float)$result['pitch_limit_mm'], 4)) ?> мм</div>
                        <div class="small subtle">π × d / N; учитывается шаг соседних витков на внутренней стороне кольца.</div>
                    </div>
                    <div class="result-cell">
                        <div class="result-label">Геометрический предел с учётом внутреннего отверстия</div>
                        <div class="result-number"><?= tw_h(tw_fmt((float)$result['absolute_wire_limit_mm'], 4)) ?> мм</div>
                        <div class="small subtle">Не больше диаметра внутреннего отверстия, чтобы провод физически проходил через кольцо.</div>
                    </div>
                    <div class="result-cell">
                        <div class="result-label">Рекомендуемый практический предел (20% запас)</div>
                        <div class="result-number"><?= tw_h(tw_fmt((float)$result['practical_wire_limit_mm'], 4)) ?> мм</div>
                        <div class="small subtle">Диаметр провода вместе с эмалью или другой изоляцией.</div>
                    </div>
                    <div class="result-cell">
                        <div class="result-label">Внутренняя окружность кольца</div>
                        <div class="result-number"><?= tw_h(tw_fmt((float)$result['inside_circumference'])) ?> мм</div>
                    </div>
                </div>

                <?php if ($result['exact_turns'] < 0.5): ?>
                    <div class="note" style="margin-top:14px"><strong>Обрати внимание:</strong> заданная индуктивность ниже A<sub>L</sub> одного витка. Минимально возможный вариант — один виток; его расчётная индуктивность может заметно превышать требуемую.</div>
                <?php endif; ?>

                <p class="formula" style="margin-top:14px">
                    Сердечник: <?= tw_h($result['core']['name']) ?>. Размеры: D = <?= tw_h(tw_fmt((float)$result['outer'])) ?> мм, d = <?= tw_h(tw_fmt((float)$result['inner'])) ?> мм, H = <?= tw_h(tw_fmt((float)$result['height'])) ?> мм; <?= $result['al_source'] === 'по измерению пробной обмотки' ? 'эффективная μ, оценённая по измерению' : 'μ' ?> = <?= tw_h(tw_fmt((float)$result['mu'])) ?>.<br>
                    Формула витков: N = √(L / A<sub>L</sub>). Для серийного сердечника используется паспортное A<sub>L</sub>; для пользовательского A<sub>L</sub> определяется <?= $result['al_source'] === 'по измерению пробной обмотки' ? 'по измеренной индуктивности пробной обмотки, а эффективная μ оценивается по геометрии' : 'по геометрии и заданной μ' ?>. Рекомендуемое число витков округляется вверх до целого.
                </p>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Как читать результат</h2>
        <p><strong>A<sub>L</sub></strong> — индуктивность на один виток в квадрате. Для серийных T50/T68 калькулятор использует значение производителя, выраженное в нГн/виток². Число витков рассчитывается как квадратный корень из отношения требуемой индуктивности к A<sub>L</sub>; затем оно округляется до целого, а итоговая индуктивность пересчитывается для этого целого числа витков.</p>
        <p><strong>Диаметр провода</strong> считается по наружному размеру изоляции. Предельная оценка использует внутреннюю окружность кольца, делённую на число витков; практический предел дополнительно оставляет 20% запаса и не превышает внутренний диаметр отверстия. Это геометрическая оценка укладки, а не расчёт допустимого тока или нагрева провода.</p>
        <p class="subtle small">Реальная индуктивность может отличаться из-за допуска A<sub>L</sub>, частоты, температуры, подмагничивания, разброса материала и паразитных параметров. Для ответственных ВЧ-цепей измерь готовую катушку прибором.</p>
    </section>
</main>
<script>
(() => {
    const selector = document.getElementById('core_id');
    const customFields = document.getElementById('custom-core-fields');
    const customModeOptions = document.querySelectorAll('input[name="custom_mode"]');
    const customMuField = document.getElementById('custom-mu-field');
    const customMeasuredFields = document.getElementById('custom-measured-fields');
    const info = document.getElementById('core-info');
    const browserCores = <?= json_encode($browserCoreData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function fmt(value, digits = 3) {
        return Number(value).toLocaleString('ru-RU', { maximumFractionDigits: digits });
    }

    function updateCoreInfo() {
        const custom = selector.value === 'custom';
        customFields.hidden = !custom;
        info.hidden = custom;
        if (custom) return;
        const core = browserCores[selector.value];
        if (!core) return;
        const source = document.getElementById('core-source');
        info.innerHTML = '<strong></strong><br>' +
            'D = ' + fmt(core.od_mm, 3) + ' мм; d = ' + fmt(core.id_mm, 3) + ' мм; H = ' + fmt(core.h_mm, 3) + ' мм.<br>' +
            'μ = ' + fmt(core.mu_r, 3) + '; A<sub>L</sub> = ' + (core.al_nh === null ? 'рассчитывается по геометрии и μ' : fmt(core.al_nh, 3) + ' нГн/виток²') + '; ' + core.color + '.<br>' +
            '<span class="subtle"></span><br><a id="core-source" target="_blank" rel="noopener noreferrer">Паспортные данные производителя</a>';
        info.querySelector('strong').textContent = core.name;
        info.querySelector('.subtle').textContent = core.frequency;
        const link = info.querySelector('a');
        if (core.source) {
            link.href = core.source;
            link.hidden = false;
        } else {
            link.hidden = true;
        }
    }

    function updateCustomMode() {
        const selectedMode = document.querySelector('input[name="custom_mode"]:checked');
        const measured = !!selectedMode && selectedMode.value === 'measured';
        customMuField.hidden = measured;
        customMeasuredFields.hidden = !measured;
    }

    selector.addEventListener('change', updateCoreInfo);
    customModeOptions.forEach(option => option.addEventListener('change', updateCustomMode));
    updateCoreInfo();
    updateCustomMode();
})();
</script>
</body>
</html>
