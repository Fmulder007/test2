<?php
declare(strict_types=1);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$calculators = [
    [
        'title' => 'Простые проволочные антенны',
        'description' => 'Диполь, Inverted Vee, EFHW, вертикалы на 1/4 и 5/8 волны, квадратная и треугольная рамки.',
        'image' => 'images/wire-antenna.svg',
        'alt' => 'Схема диполя с центральным питанием и другие виды проволочных антенн',
        'file' => 'wire_antenna.php',
    ],
    [
        'title' => 'CCDANTEN — ёмкостно-нагруженные антенны',
        'description' => 'Число секций провода, ёмкость последовательных конденсаторов и расстояния между ними по исходной программе George Murphy, VE3ERP.',
        'image' => 'images/ccd-antenna.svg',
        'alt' => 'Схема диполя с последовательными конденсаторами в плечах',
        'file' => 'ccdantenna.php',
    ],
    [
        'title' => 'Резисторы и конденсаторы',
        'description' => 'Общее сопротивление двух параллельных резисторов или неизвестный резистор; общая ёмкость двух последовательных конденсаторов или неизвестный конденсатор.',
        'image' => 'images/passive-components.svg',
        'alt' => 'Схематичные изображения параллельных резисторов и последовательных конденсаторов',
        'file' => 'passive_components.php',
    ],
    [
        'title' => 'ВЧ-мощность по напряжению',
        'description' => 'Расчёт мощности синусоидального ВЧ-сигнала по размаху Vpp, амплитуде Vp или действующему напряжению Vrms и сопротивлению нагрузки. Результат в ваттах, милливаттах, микроваттах и dBm.',
        'image' => 'images/rf-sine-voltage.svg',
        'alt' => 'Синусоида с уровнями Vpp, Vp и Vrms',
        'file' => 'rf_power.php',
    ],
    [
        'title' => 'LC-контур и реактивное сопротивление',
        'description' => 'Резонансная частота по L и C, расчёт неизвестной индуктивности или ёмкости, реактивное сопротивление катушки или конденсатора на заданной частоте.',
        'image' => 'images/lc-circuit.svg',
        'alt' => 'Схема параллельного LC-контура с катушкой и конденсатором',
        'file' => 'lc_circuit.php',
    ],
    [
        'title' => 'Перестройка LC-контура по частоте',
        'description' => 'Расчёт новой ёмкости или индуктивности при изменении резонансной частоты с сохранением второго параметра контура.',
        'image' => 'images/lc-circuit.svg',
        'alt' => 'Схема LC-контура для пересчёта ёмкости или индуктивности при смене частоты',
        'file' => 'lc_retune.php',
    ],
    [
        'title' => 'Расчёт витков на тороидальном сердечнике',
        'description' => 'Подбор числа витков по требуемой индуктивности для T50-1, T50-2, T50-6, T68-1, T68-2, T68-6 или пользовательского кольца. Оценка диаметра провода для однослойной обмотки.',
        'image' => 'images/toroid-winding.svg',
        'alt' => 'Тороидальный сердечник с однослойной обмоткой и обозначениями D, d и H',
        'file' => 'toroid_winding.php',
    ],
    [
        'title' => 'Ширина ВЧ-дорожки на печатной плате',
        'description' => 'Расчёт ширины дорожки для заданного импеданса: микрополосковая линия, симметричная Stripline или копланарная GCPW. Параметры материала задаются через εr.',
        'images' => [
            ['src' => 'images/pcb-microstrip.svg', 'alt' => 'Микрополосковая линия'],
            ['src' => 'images/pcb-stripline.svg', 'alt' => 'Симметричная полосковая линия'],
            ['src' => 'images/pcb-gcpw.svg', 'alt' => 'Копланарная линия с нижней землёй'],
        ],
        'file' => 'pcb_trace_width.php',
    ],
    [
        'title' => 'Двухзвенный полосовой LC-фильтр',
        'description' => 'Расчёт ДПФ по требуемой полосе пропускания или по индуктивности имеющихся одинаковых катушек. Номиналы для Т- и П-образной топологии.',
        'image' => 'images/dpf-filter-topologies.png',
        'alt' => 'Две топологии двухзвенного полосового LC-фильтра с обозначениями L1, C1, C2, Ca и Cb',
        'file' => 'dpf_filter.php',
    ],
    [
        'title' => 'Трёхзвенный полосовой LC-фильтр',
        'description' => 'Независимый расчёт трёхзвенного ДПФ для последовательной и параллельной топологий. В каждом варианте свои исходные параметры и номиналы элементов.',
        'images' => [
            ['src' => 'images/dpf-filter-three-stage.png', 'alt' => 'Последовательная топология трёхзвенного полосового фильтра', 'label' => 'Последовательная топология'],
            ['src' => 'images/dpf-filter-three-stage-parallel.png', 'alt' => 'Параллельная топология трёхзвенного полосового фильтра', 'label' => 'Параллельная топология'],
        ],
        'image_group_label' => 'Две топологии трёхзвенного полосового фильтра',
        'image_layout' => 'stack',
        'file' => 'dpf_filter_3.php',
    ],
    [
        'title' => 'ФНЧ с подавлением второй гармоники',
        'description' => 'Расчёт идеального ФНЧ Баттерворта или Чебышёва 0,1 дБ 5-го или 7-го порядка. Три кривые АЧХ, подбор Cдоп на 2Fc и проверка измеренных номиналов компонентов.',
        'images' => [
            ['src' => 'images/lpf-5th-shunt.svg', 'alt' => 'ФНЧ с подавлением второй гармоники 5-го порядка', 'label' => 'ФНЧ 5-го порядка'],
            ['src' => 'images/lpf-7th-shunt.svg', 'alt' => 'ФНЧ с подавлением второй гармоники 7-го порядка', 'label' => 'ФНЧ 7-го порядка'],
        ],
		'image_group_label' => 'Две топологии ФНЧ с подавлением второй гармоники',
        'image_layout' => 'stack',
        'file' => 'lpf_notch.php',
    ],
	[
        'title' => 'Двухконтурный ДПФ с ёмкостной связью',
        'description' => 'Расчёт двух одинаковых катушек и пяти конденсаторов для ДПФ с независимыми входным и выходным ёмкостными делителями. Полоса задаётся по уровню −3 дБ.',
        'image' => 'images/dpf-capacitive-common.svg',
        'alt' => 'Два связанных LC-контура с ёмкостными делителями и общей нижней точкой катушек через конденсатор C5',
        'file' => 'dpf_capacitive.php',
    ],
    [
        'title' => 'Универсальный закон Ома U / I / R / P',
        'description' => 'Введи любые две известные величины из напряжения, тока, сопротивления и мощности. Калькулятор найдёт две остальные, с поддержкой микро-, милли- и кратных единиц.',
        'image' => 'images/ohms-law.svg',
        'alt' => 'Схема резистивной цепи для расчётов напряжения, тока, сопротивления и мощности',
        'file' => 'ohms_law.php',
    ],
    [
        'title' => 'Цветовая маркировка резисторов',
        'description' => 'Расшифровка цветных полос и подбор маркировки по номиналу сопротивления.',
        'image' => 'images/resistor-color-code.svg',
        'alt' => 'Резистор с цветными полосами: жёлтая, фиолетовая, зелёная и золотая',
        'file' => 'resistors.php',
    ],
];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Радиолюбительские калькуляторы</title>
    <meta name="description" content="Быстрые радиолюбительские калькуляторы для антенн, катушек, LC-контуров, закона Ома, пассивных компонентов и ВЧ-измерений.">
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #202124; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 1000px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #d7dce1; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: clamp(1.45rem, 3vw, 2rem); }
        h2 { margin: 0 0 8px; font-size: 1.2rem; }
        p { line-height: 1.5; }
        .subtle { color: #5f6368; }
        .calculator-card { display: grid; grid-template-columns: minmax(220px, 340px) minmax(0, 1fr); gap: 22px; align-items: center; }
        .calculator-image { display: block; width: 100%; height: auto; border: 1px solid #d7dce1; border-radius: 8px; background: #f6f9fb; }
        .calculator-image-group { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; width: 100%; align-items: start; }
        .calculator-image-item { min-width: 0; margin: 0; }
        .calculator-image-group img { display: block; width: 100%; height: 108px; object-fit: contain; border: 1px solid #d7dce1; border-radius: 7px; background: #fff; }
        /* Только для карточки трёхзвенного ДПФ: две схемы строго одна под другой. */
        .calculator-image-group.stacked { display: flex; flex-direction: column; align-items: stretch; gap: 10px; }
        .calculator-image-group.stacked .calculator-image-item { display: block; width: 100%; margin: 0; }
        .calculator-image-group.stacked img { width: 100%; height: auto; max-height: 150px; object-fit: contain; }
        .calculator-image-caption { margin-top: 3px; color: #5f6368; font-size: .78rem; line-height: 1.3; text-align: center; }
        .item-link { display: inline-block; margin-top: 5px; color: #244f76; font-weight: 700; }
        .item-link:focus-visible, a:focus-visible { outline: 3px solid #89bde5; outline-offset: 3px; }
        @media (max-width: 680px) {
            body { padding: 10px; }
            .panel { padding: 15px; }
            .calculator-card { grid-template-columns: minmax(0, 1fr); gap: 14px; }
            .calculator-image { max-width: 560px; }
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Радиолюбительские калькуляторы</h1>
        <p class="subtle">Небольшие быстрые инструменты для радиолюбителя. Каждый калькулятор работает отдельной страницей и не требует базы данных или внешних библиотек.</p>
    </section>

    <?php foreach ($calculators as $calculator): ?>
        <section class="panel calculator-card">
            <?php if (isset($calculator['images'])): ?>
                <div class="calculator-image-group<?= ($calculator['image_layout'] ?? '') === 'stack' ? ' stacked' : '' ?>" aria-label="<?= h($calculator['image_group_label'] ?? 'Схемы') ?>">
                    <?php foreach ($calculator['images'] as $image): ?>
                        <figure class="calculator-image-item">
                            <img src="<?= h($image['src']) ?>" alt="<?= h($image['alt']) ?>" width="360" height="<?= ($calculator['image_layout'] ?? '') === 'stack' ? '108' : '300' ?>" loading="lazy">
                            <?php if (!empty($image['label'])): ?><figcaption class="calculator-image-caption"><?= h($image['label']) ?></figcaption><?php endif; ?>
                        </figure>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <img class="calculator-image" src="<?= h($calculator['image']) ?>" alt="<?= h($calculator['alt']) ?>" width="760" height="260" loading="lazy">
            <?php endif; ?>
            <div>
                <h2><?= h($calculator['title']) ?></h2>
                <p><?= h($calculator['description']) ?></p>
                <a class="item-link" href="<?= h($calculator['file']) ?>">Открыть калькулятор →</a>
            </div>
        </section>
    <?php endforeach; ?>

    <p class="subtle" style="text-align:center;font-size:.85rem">Новые калькуляторы можно добавлять отдельными PHP-файлами и добавлять ссылку на них в этот список.</p>
</main>
</body>
</html>
