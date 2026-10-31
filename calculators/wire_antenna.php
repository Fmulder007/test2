<?php
/*
 * Калькулятор простых проволочных антенн.
 *
 * Самостоятельная страница: без базы данных, JavaScript и внешних библиотек.
 * Расчёты дают начальные размеры для изготовления и последующей настройки.
 *
 * Используемые практические формулы:
 *  - полуволновой диполь / EFHW: 468 / f (футы), общая длина;
 *  - четвертьволновый элемент: 234 / f (футы);
 *  - вертикал 5/8 волны: 585 / f (футы);
 *  - периметр полноразмерной волновой рамки: 1005 / f (футы).
 * f — частота в МГц. Эти формулы приблизительные; готовую антенну нужно подстроить.
 */
declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt(float $value, int $decimals = 3): string
{
    return number_format($value, $decimals, ',', '');
}

/** Формат длины в метрах, сантиметрах и футах с десятичными дюймами. */
function lengthText(float $meters): string
{
    $inchesTotal = $meters / 0.0254;
    $feet = (int) floor($inchesTotal / 12.0);
    $inches = round($inchesTotal - $feet * 12.0, 1);
    if ($inches >= 12.0) {
        $feet++;
        $inches = 0.0;
    }
    return fmt($meters, 3) . ' м (' . $feet . ' фут ' . fmt($inches, 1) . ' дюйм)';
}

$antennaTypes = [
    'dipole' => 'Полуволновой диполь',
    'inverted_v' => 'Диполь Inverted Vee',
    'efhw' => 'Полуволновая EFHW (питание с конца)',
    'vertical_quarter' => 'Вертикал 1/4 волны с радиалами',
    'vertical_five_eighths' => 'Вертикал 5/8 волны',
    'square_loop' => 'Полноволновая рамка — квадрат',
    'delta_loop' => 'Полноволновая рамка — равносторонняя дельта',
];

$frequencyInput = '7,1';
$antennaType = 'dipole';
$radialCountInput = '4';
$errors = [];
$result = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $frequencyInput = trim((string) ($_POST['frequency'] ?? ''));
    $normalizedFrequency = str_replace(',', '.', $frequencyInput);
    $postedType = (string) ($_POST['antenna_type'] ?? 'dipole');
    $antennaType = isset($antennaTypes[$postedType]) ? $postedType : 'dipole';
    $radialCountInput = trim((string) ($_POST['radial_count'] ?? '4'));

    if ($normalizedFrequency === '' || !is_numeric($normalizedFrequency)) {
        $errors[] = 'Введите частоту в МГц, например 7,1.';
    } else {
        $frequency = (float) $normalizedFrequency;
        if (!is_finite($frequency) || $frequency < 0.01 || $frequency > 30000.0) {
            $errors[] = 'Частота должна быть в диапазоне от 0,01 до 30 000 МГц.';
        }
    }

    if ($radialCountInput === '' || filter_var($radialCountInput, FILTER_VALIDATE_INT) === false) {
        $errors[] = 'Количество радиалов должно быть целым числом от 1 до 120.';
    } else {
        $radialCount = (int) $radialCountInput;
        if ($radialCount < 1 || $radialCount > 120) {
            $errors[] = 'Количество радиалов должно быть от 1 до 120.';
        }
    }

    if ($errors === []) {
        $frequency = (float) $normalizedFrequency;
        $radialCount = (int) $radialCountInput;
        $wavelength = 299.792458 / $frequency;
        // Практические коэффициенты заданы в футах; переводим в метры.
        $halfWaveMeters = (468.0 / $frequency) * 0.3048;
        $quarterWaveMeters = (234.0 / $frequency) * 0.3048;
        $fiveEighthsMeters = (585.0 / $frequency) * 0.3048;
        $loopPerimeterMeters = (1005.0 / $frequency) * 0.3048;

        switch ($antennaType) {
            case 'dipole':
                $result = [
                    'title' => $antennaTypes[$antennaType],
                    'frequency' => $frequency,
                    'wavelength' => $wavelength,
                    'formula' => 'Полная длина = 468 / f (футы)',
                    'items' => [
                        ['Общая длина провода', $halfWaveMeters],
                        ['Одно плечо диполя', $halfWaveMeters / 2.0],
                    ],
                    'notes' => [
                        'Формула даёт начальную длину полуволнового диполя для практической настройки.',
                        'При изготовлении оставьте запас для подстройки. Окончательную длину определяют измерением резонанса или КСВ на месте установки.',
                    ],
                ];
                break;

            case 'inverted_v':
                $result = [
                    'title' => $antennaTypes[$antennaType],
                    'frequency' => $frequency,
                    'wavelength' => $wavelength,
                    'formula' => 'Начальная общая длина = 468 / f (футы)',
                    'items' => [
                        ['Общая длина двух плеч', $halfWaveMeters],
                        ['Длина каждого плеча', $halfWaveMeters / 2.0],
                    ],
                    'notes' => [
                        'Размеры рассчитаны по той же начальной формуле, что и для обычного диполя. Угол между плечами, высота и окружающие предметы меняют резонанс.',
                        'Окончательную длину подберите измерением установленной антенны. Не считайте этот расчёт точным предсказанием КСВ.',
                    ],
                ];
                break;

            case 'efhw':
                $result = [
                    'title' => $antennaTypes[$antennaType],
                    'frequency' => $frequency,
                    'wavelength' => $wavelength,
                    'formula' => 'Начальная длина полуволнового излучателя = 468 / f (футы)',
                    'items' => [
                        ['Длина излучающего провода', $halfWaveMeters],
                        ['Ориентировочная длина свободного участка 0,05 λ (необязательный противовес)', $wavelength * 0.05],
                    ],
                    'notes' => [
                        'EFHW обычно требует согласующего трансформатора с высоким коэффициентом трансформации; часто применяют вариант около 49:1, но он не универсален для любой конструкции.',
                        'Длина противовеса 0,05 λ приведена как возможная отправная точка, а не как обязательное значение. Ток по оплётке коаксиала может потребовать дросселя.',
                        'Резонанс зависит от высоты, наклона, окружающих предметов, изоляции провода и конструкции трансформатора.',
                    ],
                ];
                break;

            case 'vertical_quarter':
                $result = [
                    'title' => $antennaTypes[$antennaType],
                    'frequency' => $frequency,
                    'wavelength' => $wavelength,
                    'formula' => 'Длина 1/4 волны = 234 / f (футы)',
                    'items' => [
                        ['Длина вертикального излучателя', $quarterWaveMeters],
                        ['Длина одного радиала (начальный размер)', $quarterWaveMeters],
                        ['Общая длина провода на излучатель и ' . $radialCount . ' радиалов', $quarterWaveMeters * (1 + $radialCount)],
                    ],
                    'notes' => [
                        'Для приподнятого GP радиалы обычно делают близкими к четверти волны; их наклон влияет на входное сопротивление.',
                        'Для вертикала у земли количество радиалов и потери в грунте имеют большое значение. Указанная четверть волны — размер для начала работы, а не гарантия резонанса или КПД.',
                        'Проверяйте готовую антенну измерителем. Длина зависит от диаметра проводника, высоты и окружающих металлических предметов.',
                    ],
                ];
                break;

            case 'vertical_five_eighths':
                $result = [
                    'title' => $antennaTypes[$antennaType],
                    'frequency' => $frequency,
                    'wavelength' => $wavelength,
                    'formula' => 'Длина 5/8 волны = 585 / f (футы)',
                    'items' => [
                        ['Длина вертикального излучателя 5/8 λ', $fiveEighthsMeters],
                        ['Ориентировочная длина одного радиала 1/4 λ', $quarterWaveMeters],
                        ['Общая длина провода на излучатель и ' . $radialCount . ' радиалов', $fiveEighthsMeters + $quarterWaveMeters * $radialCount],
                    ],
                    'notes' => [
                        'Вертикал 5/8 волны обычно требует согласующей цепи у точки питания; одной длины излучателя недостаточно для корректного согласования с 50 Ом.',
                        'Длины радиалов и способ их установки зависят от конструкции. Результат — приближённый стартовый размер, не готовая схема согласования.',
                    ],
                ];
                break;

            case 'square_loop':
                $result = [
                    'title' => $antennaTypes[$antennaType],
                    'frequency' => $frequency,
                    'wavelength' => $wavelength,
                    'formula' => 'Периметр = 1005 / f (футы)',
                    'items' => [
                        ['Полная длина провода (периметр)', $loopPerimeterMeters],
                        ['Длина одной стороны квадрата', $loopPerimeterMeters / 4.0],
                    ],
                    'notes' => [
                        'Использована традиционная практическая формула полноразмерной волновой рамки. Это приближение, а не электромагнитное моделирование.',
                        'Высота над землёй, форма, диаметр и изоляция провода, а также расположение точки питания влияют на резонанс и входное сопротивление.',
                    ],
                ];
                break;

            case 'delta_loop':
                $side = $loopPerimeterMeters / 3.0;
                $result = [
                    'title' => $antennaTypes[$antennaType],
                    'frequency' => $frequency,
                    'wavelength' => $wavelength,
                    'formula' => 'Периметр = 1005 / f (футы); стороны равностороннего треугольника равны',
                    'items' => [
                        ['Полная длина провода (периметр)', $loopPerimeterMeters],
                        ['Длина каждой из трёх сторон', $side],
                        ['Высота равностороннего треугольника', $side * sqrt(3.0) / 2.0],
                    ],
                    'notes' => [
                        'Использована традиционная практическая формула полного волнового контура. Для дельты это только начальная оценка.',
                        'Фактический резонанс и входное сопротивление зависят от высоты, ориентации, формы, точки питания и окружающих предметов.',
                    ],
                ];
                break;
        }
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Калькулятор проволочных антенн</title>
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #202124; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 920px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #d7dce1; border-radius: 10px; padding: 20px; margin-bottom: 18px; }
        h1 { margin: 0 0 8px; font-size: clamp(1.45rem, 3vw, 2rem); }
        h2 { margin: 0 0 14px; font-size: 1.2rem; }
        p { line-height: 1.5; }
        .subtle { color: #5f6368; }
        .form-grid { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 16px; align-items: end; }
        label { display: block; font-weight: 700; margin-bottom: 7px; }
        input, select, button { width: 100%; min-height: 43px; border: 1px solid #9aa0a6; border-radius: 6px; padding: 9px 11px; background: #fff; color: inherit; font: inherit; }
        button { border-color: #303f50; background: #303f50; color: #fff; font-weight: 700; cursor: pointer; }
        button:hover { background: #202d3a; }
        .button-row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        .button-row button { width: auto; min-width: 170px; }
        .error { border: 1px solid #c62828; background: #fff5f5; padding: 12px 16px; border-radius: 6px; margin: 0 0 16px; }
        .error p { margin: 4px 0; }
        .summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin: 16px 0; }
        .summary-item { border: 1px solid #d7dce1; border-radius: 7px; padding: 13px; }
        .summary-item span { display: block; color: #5f6368; font-size: .9rem; margin-bottom: 6px; }
        .summary-item strong { font-size: 1.05rem; }
        .notes { font-size: .92rem; color: #4f555b; }
        ul.notes-list { padding-left: 22px; }
        ul.notes-list li { margin: 8px 0; line-height: 1.5; }
        .formula { display: inline-block; padding: 8px 11px; background: #edf1f4; border-radius: 6px; font-family: Consolas, monospace; }
        .top-links { margin-top: 12px; font-size: .92rem; }
        .top-links a { color: #244f76; }
        .hero-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(250px, 390px); gap: 22px; align-items: center; margin-bottom: 18px; }
        .hero-image { display: block; width: 100%; height: auto; border: 1px solid #d7dce1; border-radius: 8px; background: #f6f9fb; }
        .back-link { display: inline-block; margin-top: 6px; color: #244f76; font-weight: 700; }
        @media (max-width: 700px) { .hero-layout { grid-template-columns: minmax(0, 1fr); } .hero-image { max-width: 560px; } }
        @media (max-width: 700px) {
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
            .screen-only { display: none !important; }
            .summary-item { break-inside: avoid; }
            h1 { font-size: 16pt; }
            h2 { font-size: 12pt; }
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <div class="hero-layout">
            <div>
                <h1>Калькулятор проволочных антенн</h1>
                <p class="subtle">Начальные размеры диполя, Inverted Vee, EFHW, вертикалов и полноразмерных рамок. Без базы данных и внешних библиотек.</p>
                <a class="back-link screen-only" href="index.php">← К списку калькуляторов</a>
            </div>
            <img class="hero-image" src="images/wire-antenna.svg" alt="Схема диполя с центральным питанием и условные изображения других проволочных антенн" width="760" height="260">
        </div>

        <?php if ($errors !== []): ?>
            <div class="error" role="alert">
                <?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <div class="form-grid">
                <div>
                    <label for="antenna_type">Тип антенны</label>
                    <select id="antenna_type" name="antenna_type">
                        <?php foreach ($antennaTypes as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $antennaType === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="frequency">Частота, МГц</label>
                    <input id="frequency" name="frequency" type="text" inputmode="decimal" autocomplete="off" required value="<?= h($frequencyInput) ?>" placeholder="7,1">
                </div>
                <div>
                    <label for="radial_count">Число радиалов (для вертикала)</label>
                    <input id="radial_count" name="radial_count" type="number" min="1" max="120" step="1" required value="<?= h($radialCountInput) ?>">
                </div>
            </div>
            <div class="button-row">
                <button type="submit">Рассчитать</button>
            </div>
        </form>
    </section>

    <?php if ($result !== null): ?>
        <section class="panel" id="results">
            <h2><?= h($result['title']) ?> на <?= fmt((float) $result['frequency'], 4) ?> МГц</h2>
            <p><span class="formula"><?= h($result['formula']) ?></span></p>
            <div class="summary">
                <div class="summary-item">
                    <span>Длина волны в свободном пространстве</span>
                    <strong><?= fmt((float) $result['wavelength'], 3) ?> м</strong>
                </div>
                <?php foreach ($result['items'] as [$label, $meters]): ?>
                    <div class="summary-item">
                        <span><?= h((string) $label) ?></span>
                        <strong><?= h(lengthText((float) $meters)) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <h2>Примечания</h2>
            <ul class="notes-list notes">
                <?php foreach ($result['notes'] as $note): ?><li><?= h($note) ?></li><?php endforeach; ?>
            </ul>
        </section>
    <?php else: ?>
        <section class="panel screen-only">
            <h2>Что рассчитывает страница</h2>
            <p>Введите рабочую частоту и выберите конструкцию. Для вертикальных антенн можно указать число радиалов — калькулятор посчитает примерную суммарную длину провода.</p>
            <p class="notes">Все размеры являются отправными оценками. Реальная настройка зависит от высоты, формы, проводника, изоляции, окружающих предметов и точки питания. Для точной настройки используйте измерения готовой антенны.</p>
        </section>
    <?php endif; ?>
    <p class="subtle screen-only" style="text-align:center;font-size:.85rem">Калькулятор проволочных антенн — автономный PHP-файл.</p>
</main>
</body>
</html>
