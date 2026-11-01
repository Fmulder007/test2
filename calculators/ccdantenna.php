<?php
/*
 * CCDANTEN — веб-калькулятор CCD-антенн.
 *
 * Самостоятельная страница: не требует базы данных, библиотек или JavaScript.
 * Расчётные формулы и наборы вариантов перенесены из CCDANTEN.BAS
 * (George Murphy, VE3ERP, версия 1994/1995).
 */
declare(strict_types=1);

/** Безопасное отображение текста в HTML. */
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Число с русским десятичным разделителем без разделителей тысяч. */
function fmt(float $value, int $decimals = 2): string
{
    return number_format($value, $decimals, ',', '');
}

/**
 * Выбирает ближайший стандартный номинал из ряда 8,2–82 пФ,
 * повторяемого по декадам. При равном расстоянии выбирается меньший номинал.
 */
function nearestStandardCapacitor(float $value): float
{
    $series = [8.2, 10.0, 12.0, 15.0, 18.0, 22.0, 27.0, 33.0, 39.0, 47.0, 56.0, 68.0, 82.0];
    $candidates = [];

    // Исходная программа начинает с множителя 0,01 и увеличивает его в 10 раз.
    for ($decade = -2; $decade <= 12; $decade++) {
        $multiplier = 10 ** $decade;
        foreach ($series as $base) {
            $candidates[] = $base * $multiplier;
        }
    }

    sort($candidates, SORT_NUMERIC);
    $candidates = array_values(array_unique($candidates, SORT_REGULAR));

    // Для значений ниже самого малого номинала используется нижняя граница ряда.
    $low = $candidates[0];
    $high = $candidates[1];

    foreach ($candidates as $candidate) {
        if ($candidate <= $value) {
            $low = $candidate;
            continue;
        }
        $high = $candidate;
        break;
    }

    // В исходнике при равной разнице побеждает нижний номинал.
    return (($value - $low) <= ($high - $value)) ? $low : $high;
}

/** Форматирование ёмкости по правилам таблицы старой программы. */
function fmtCap(float $value): string
{
    return fmt($value, $value < 10.0 ? 1 : 0);
}

$frequencyInput = '7.1';
$wireDiameterInput = '1.00';
$antennaType = 'dipole';
$capacitorMode = 'nearest';
$errors = [];
$calculation = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $frequencyInput = trim((string) ($_POST['frequency'] ?? ''));
    $frequencyNormalized = str_replace(',', '.', $frequencyInput);
    $wireDiameterInput = trim((string) ($_POST['wire_diameter'] ?? ''));
    $wireDiameterNormalized = str_replace(',', '.', $wireDiameterInput);
    $antennaType = (($_POST['antenna_type'] ?? '') === 'vertical') ? 'vertical' : 'dipole';
    $capacitorMode = (($_POST['capacitor_mode'] ?? '') === 'exact') ? 'exact' : 'nearest';

    if ($frequencyNormalized === '' || !is_numeric($frequencyNormalized)) {
        $errors[] = 'Введите рабочую частоту в МГц, например 7,1.';
    } else {
        $frequency = (float) $frequencyNormalized;
        if (!is_finite($frequency) || $frequency <= 0.0) {
            $errors[] = 'Частота должна быть числом больше нуля.';
        }
    }

    if ($wireDiameterNormalized === '' || !is_numeric($wireDiameterNormalized)) {
        $errors[] = 'Введите диаметр провода в диапазоне от 0,5 до 3,0 мм.';
    } else {
        $wireDiameterMm = (float) $wireDiameterNormalized;
        if (!is_finite($wireDiameterMm) || $wireDiameterMm < 0.5 || $wireDiameterMm > 3.0) {
            $errors[] = 'Диаметр провода должен быть от 0,5 до 3,0 мм.';
        }
    }

    if ($errors === []) {
        // Все линейные размеры и диаметр проводника задаются в метрической системе.
        $totalLengthMeters = 300.0 / $frequency;
        $diameterMeters = $wireDiameterMm / 1000.0;

        // Эквивалент исходной формулы индуктивности, непосредственно в метрах.
        $inductance = 0.2 * $totalLengthMeters
            * (log((4.0 * $totalLengthMeters) / $diameterMeters) - 0.75);

        if (!is_finite($inductance) || $inductance <= 0.0) {
            $errors[] = 'Для этой частоты и диаметра исходная формула не даёт физически корректного результата.';
        } else {
            // CTOT из исходника; результат выражается в пФ.
            $angularTerm = (6.2832 * $frequency) ** 2;
            $totalCapacitanceFactor = 2.0 * ((1.0 / ($angularTerm * $inductance)) * 1_000_000.0);

            if (!is_finite($totalCapacitanceFactor) || $totalCapacitanceFactor <= 0.0) {
                $errors[] = 'Для этой частоты и диаметра не удалось получить физически корректную ёмкость.';
            } else {
                $sectionStep = ($antennaType === 'dipole') ? -2 : -1;
                $capacitorOffset = ($antennaType === 'dipole') ? 2 : 1;
                $rows = [];

                for ($sections = 50; $sections >= 1; $sections += $sectionStep) {
                    $capacitors = $sections - $capacitorOffset;
                    $capValue = null;

                    if ($capacitors > 0) {
                        $capValue = $totalCapacitanceFactor * $capacitors;
                        if ($capacitorMode === 'nearest') {
                            $capValue = nearestStandardCapacitor($capValue);
                        }
                    }

                    $spacingMeters = $totalLengthMeters / $sections;
                    $rows[] = [
                        'sections' => $sections,
                        'capacitors' => $capacitors,
                        'cap_value' => $capValue,
                        'spacing_meters' => $spacingMeters,
                    ];
                }

                $calculation = [
                    'frequency' => $frequency,
                    'antenna_type' => $antennaType,
                    'capacitor_mode' => $capacitorMode,
                    'wire_diameter_mm' => $wireDiameterMm,
                    'wire_area_mm2' => M_PI * ($wireDiameterMm ** 2) / 4.0,
                    'wavelength_meters' => $totalLengthMeters,
                    'total_meters' => $totalLengthMeters,
                    'rows' => $rows,
                ];
            }
        }
    }
}

$typeLabel = ($antennaType === 'dipole') ? 'Диполь' : 'Вертикальная антенна или Long Wire/Zepp';
$modeLabel = ($capacitorMode === 'exact') ? 'точные расчётные значения' : 'ближайшие стандартные номиналы';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CCDANTEN — расчёт CCD-антенн</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Arial, Helvetica, sans-serif;
            color: #202124;
            background: #f4f6f8;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 1180px; margin: 0 auto; }
        .panel {
            background: #fff;
            border: 1px solid #d7dce1;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 18px;
        }
        h1 { margin: 0 0 8px; font-size: clamp(1.45rem, 3vw, 2rem); }
        h2 { margin: 0 0 14px; font-size: 1.2rem; }
        p { line-height: 1.5; }
        .subtle { color: #5f6368; }
        .form-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 16px;
            align-items: end;
        }
        label { display: block; font-weight: 700; margin-bottom: 7px; }
        input, select, button {
            width: 100%;
            min-height: 43px;
            border: 1px solid #9aa0a6;
            border-radius: 6px;
            padding: 9px 11px;
            background: #fff;
            color: inherit;
            font: inherit;
        }
        button {
            border-color: #303f50;
            background: #303f50;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }
        button:hover { background: #202d3a; }
        .button-row { display: flex; align-items: center; gap: 12px; margin-top: 16px; }
         .clear-link { color: #244f76; }
        .button-row button { width: auto; min-width: 170px; }
        .error {
            border: 1px solid #c62828;
            background: #fff5f5;
            padding: 12px 16px;
            border-radius: 6px;
            margin: 0 0 16px;
        }
        .error p { margin: 4px 0; }
        .summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin: 16px 0;
        }
        .summary-item { border: 1px solid #d7dce1; border-radius: 7px; padding: 12px; }
        .summary-item span { display: block; color: #5f6368; font-size: .9rem; margin-bottom: 6px; }
        .summary-item strong { font-size: 1.05rem; }
        .table-wrap { overflow-x: auto; border: 1px solid #d7dce1; border-radius: 6px; }
        table { border-collapse: collapse; width: 100%; min-width: 640px; table-layout: fixed; background: #fff; font-size: .94rem; }
        th, td { width: 25%; border-bottom: 1px solid #d7dce1; border-right: 1px solid #e4e7eb; padding: 8px 7px; text-align: center; }
        th { background: #edf1f4; font-weight: 700; white-space: normal; overflow-wrap: normal; word-break: normal; vertical-align: middle; }
        td { white-space: nowrap; }
        .wire-spec { margin: 0 0 10px; font-weight: 700; color: #4f555b; }
        tbody tr:nth-child(even) { background: #f8fafb; }
        tbody tr:last-child td { border-bottom: 0; }
        .left { text-align: left; }
        .notes { font-size: .92rem; color: #4f555b; }
        .hero-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(250px, 390px); gap: 22px; align-items: center; margin-bottom: 18px; }
        .hero-image { display: block; width: 100%; height: auto; border: 1px solid #d7dce1; border-radius: 8px; background: #f6f9fb; }
        .calculator-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 22px; align-items: start; }
        .diagram-column, .form-column { min-width: 0; }
        .back-link { display: inline-block; margin-top: 6px; color: #244f76; font-weight: 700; }
        @media (max-width: 760px) { .calculator-layout { grid-template-columns: minmax(0, 1fr); gap: 14px; } .hero-image { max-width: 100%; } }
        @media (max-width: 760px) {
            body { padding: 10px; }
            .panel { padding: 15px; }
            .form-grid, .summary { grid-template-columns: 1fr; }
            .button-row { flex-direction: column; }
            .button-row button { width: 100%; }
        }
        @media print {
            :root { background: #fff; }
            body { padding: 0; background: #fff; }
            main { max-width: none; }
            .panel { border: 0; padding: 0; margin: 0 0 12px; border-radius: 0; }
            .screen-only, .clear-link { display: none !important; }
            .table-wrap { overflow: visible; border: 0; }
            table { min-width: 0; font-size: 8pt; }
            th, td { padding: 4px; }
            th { background: #eee !important; print-color-adjust: exact; }
            .summary-item { break-inside: avoid; }
            h1 { font-size: 16pt; }
            h2 { font-size: 12pt; }
        }
    
        form .form-grid > div { display: grid; grid-template-columns: minmax(155px, .9fr) minmax(0, 1.1fr); align-items: center; gap: 10px; }
        form .form-grid > div > label { display: block; margin: 0; font-weight: 700; }
        form .form-grid > div > input, form .form-grid > div > select { min-height: 39px; }
        @media (max-width: 760px) { .calculator-layout { grid-template-columns: minmax(0, 1fr); gap: 14px; } }
        @media (max-width: 700px) { form .form-grid > div { grid-template-columns: minmax(135px, .9fr) minmax(0, 1.1fr); gap: 8px; } }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>CCDANTEN — расчёт CCD-антенн</h1>
        <p class="subtle">Калькулятор по программе George Murphy, VE3ERP. Расчёт количества секций провода, ёмкости конденсаторов и расстояния между ними.</p>
        <a class="back-link screen-only" href="index.php">← К списку калькуляторов</a>
    </section>

    <section class="panel">
        <div class="calculator-layout">
            <div class="diagram-column">
                <img class="hero-image" src="images/ccd-antenna.svg" alt="Упрощённая схема CCD-антенны с последовательными конденсаторами в проволочных плечах" width="760" height="260">
            </div>
            <div class="form-column">
        <h2>Исходные данные</h2>
        <?php if ($errors !== []): ?>
            <div class="error" role="alert">
                <?php foreach ($errors as $error): ?>
                    <p><?= h($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-grid">
                <div>
                    <label for="antenna_type">Тип антенны</label>
                    <select id="antenna_type" name="antenna_type">
                        <option value="dipole" <?= $antennaType === 'dipole' ? 'selected' : '' ?>>Диполь</option>
                        <option value="vertical" <?= $antennaType === 'vertical' ? 'selected' : '' ?>>Вертикальная / Long Wire / Zepp</option>
                    </select>
                </div>
                <div>
                    <label for="wire_diameter">Диаметр провода, мм</label>
                    <input id="wire_diameter" name="wire_diameter" type="number" inputmode="decimal" min="0.5" max="3.0" step="0.01" required value="<?= h($wireDiameterInput) ?>" placeholder="Например, 1,00">
                </div>
                <div>
                    <label for="frequency">Нижняя частота диапазона, МГц</label>
                    <input id="frequency" name="frequency" type="number" inputmode="decimal" min="0.000001" step="any" required value="<?= h($frequencyInput) ?>" placeholder="Например, 7,1">
                </div>
                <div>
                    <label for="capacitor_mode">Отображение ёмкостей</label>
                    <select id="capacitor_mode" name="capacitor_mode">
                        <option value="exact" <?= $capacitorMode === 'exact' ? 'selected' : '' ?>>Точные расчётные значения</option>
                        <option value="nearest" <?= $capacitorMode === 'nearest' ? 'selected' : '' ?>>Ближайшие стандартные номиналы</option>
                    </select>
                </div>
            </div>
            <div class="button-row">
                <button type="submit">Рассчитать антенну</button>
                <a class="clear-link" href="ccdantenna.php">Сбросить</a>
            </div>
        </form>
            </div>
        </div>
    </section>

    <?php if ($calculation !== null): ?>
        <section class="panel" id="results">
            <h2><?= h($typeLabel) ?> на частоту <?= fmt($calculation['frequency'], 4) ?> МГц</h2>
            <p class="subtle">Ёмкости: <?= h($modeLabel) ?>. В таблице приведены все варианты, формируемые исходной программой.</p>

            <div class="summary">
                <div class="summary-item">
                    <span>Длина волны</span>
                    <strong><?= fmt($calculation['wavelength_meters'], 3) ?> м</strong>
                </div>
                <div class="summary-item">
                    <span>Полная длина провода</span>
                    <strong><?= fmt($calculation['total_meters'], 3) ?> м</strong>
                </div>
                <div class="summary-item">
                    <span>Вариантов в таблице</span>
                    <strong><?= count($calculation['rows']) ?></strong>
                </div>
            </div>

            <p class="wire-spec">Провод: Ø <?= fmt($calculation['wire_diameter_mm'], 2) ?> мм · сечение <?= fmt($calculation['wire_area_mm2'], 2) ?> мм²</p>
            <div class="table-wrap">
                <table>
                    <colgroup>
                        <col style="width:25%">
                        <col style="width:25%">
                        <col style="width:25%">
                        <col style="width:25%">
                    </colgroup>
                    <thead>
                    <tr>
                        <th>Секций провода</th>
                        <th>Конденсаторов</th>
                        <th>Ёмкость каждого конденсатора, пФ</th>
                        <th>Расстояние между конденсаторами, м</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($calculation['rows'] as $row): ?>
                        <tr>
                            <td><?= (int) $row['sections'] ?></td>
                            <td><?= (int) $row['capacitors'] ?></td>
                            <td><?= $row['cap_value'] === null ? '—' : h(fmtCap((float) $row['cap_value'])) ?></td>
                            <td><?= fmt((float) $row['spacing_meters'], 3) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="notes">Расчёт повторяет формулы исходной программы. Он не моделирует диаграмму направленности, входное сопротивление, напряжение на конденсаторах или КСВ готовой антенны. Используйте расчёт как отправную точку для практической конструкции.</p>
        </section>
    <?php else: ?>
        <section class="panel screen-only">
            <h2>Как пользоваться</h2>
            <p>Выберите тип антенны, укажите диаметр провода и нижнюю частоту рабочего диапазона, затем выберите формат ёмкостей. Калькулятор сформирует таблицу конфигураций для выбранного диаметра.</p>
            <p class="notes">В исходной программе для диполя показываются 25 конфигураций, а для вертикальной антенны или Long Wire — 50. Рекомендуется проектировать антенну для нижней частоты диапазона. Для конденсаторов автор исходной программы рекомендовал серебряно-слюдяные с допуском 5%.</p>
        </section>
    <?php endif; ?>

    <p class="subtle screen-only" style="text-align:center;font-size:.85rem">CCDANTEN — автономный PHP-файл, без базы данных и внешних библиотек.</p>
</main>
</body>
</html>
