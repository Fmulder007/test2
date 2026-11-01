<?php
/*
 * Калькулятор ВЧ-мощности для синусоидального сигнала.
 * По Vpp, Vp или Vrms и сопротивлению нагрузки рассчитывает
 * действующее напряжение, остальные уровни напряжения, мощность и dBm.
 * Не требует базы данных и внешних библиотек.
 */
declare(strict_types=1);

function rf_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Принимает десятичную точку или запятую, допускает ноль, отклоняет отрицательные и бесконечные значения. */
function rf_nonNegativeNumber(string $raw): ?float
{
    $raw = str_replace([' ', "\u{00A0}"], '', trim($raw));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $value = (float) $raw;
    return ($value >= 0.0 && is_finite($value)) ? $value : null;
}

/** Коэффициенты перевода напряжения в вольты. */
function rf_voltageFactor(string $unit): float
{
    return match ($unit) {
        'nv' => 1e-9,
        'uv' => 1e-6,
        'mv' => 1e-3,
        'kv' => 1e3,
        default => 1.0,
    };
}

/** Коэффициенты перевода сопротивления в омы. */
function rf_resistanceFactor(string $unit): float
{
    return match ($unit) {
        'mohm' => 1e-3,
        'kohm' => 1e3,
        'megaohm' => 1e6,
        default => 1.0,
    };
}

/** Форматирует число русской запятой без незначащих нулей. */
function rf_formatNumber(float $value, int $decimals = 6): string
{
    if (!is_finite($value)) {
        return '—';
    }
    if ($value !== 0.0 && (abs($value) >= 1e12 || abs($value) < 1e-6)) {
        return str_replace('.', ',', sprintf('%.5g', $value));
    }
    $formatted = number_format($value, $decimals, ',', '');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    return ($formatted === '' || $formatted === '-0') ? '0' : $formatted;
}

/** Возвращает напряжение в удобном масштабе. */
function rf_formatVoltage(float $volts): string
{
    $a = abs($volts);
    if ($a >= 1e3) return rf_formatNumber($volts / 1e3) . ' кВ';
    if ($a >= 1.0) return rf_formatNumber($volts) . ' В';
    if ($a >= 1e-3) return rf_formatNumber($volts * 1e3) . ' мВ';
    if ($a >= 1e-6) return rf_formatNumber($volts * 1e6) . ' мкВ';
    if ($a >= 1e-9) return rf_formatNumber($volts * 1e9) . ' нВ';
    return rf_formatNumber($volts) . ' В';
}

/** Возвращает мощность в удобном масштабе. */
function rf_formatPower(float $watts): string
{
    if ($watts >= 1e6) return rf_formatNumber($watts / 1e6) . ' МВт';
    if ($watts >= 1e3) return rf_formatNumber($watts / 1e3) . ' кВт';
    if ($watts >= 1.0) return rf_formatNumber($watts) . ' Вт';
    if ($watts >= 1e-3) return rf_formatNumber($watts * 1e3) . ' мВт';
    if ($watts >= 1e-6) return rf_formatNumber($watts * 1e6) . ' мкВт';
    if ($watts >= 1e-9) return rf_formatNumber($watts * 1e9) . ' нВт';
    if ($watts >= 1e-12) return rf_formatNumber($watts * 1e12) . ' пВт';
    return rf_formatNumber($watts) . ' Вт';
}

/** Форматирует сопротивление в удобном масштабе. */
function rf_formatResistance(float $ohms): string
{
    if ($ohms >= 1e6) return rf_formatNumber($ohms / 1e6) . ' МОм';
    if ($ohms >= 1e3) return rf_formatNumber($ohms / 1e3) . ' кОм';
    if ($ohms >= 1.0) return rf_formatNumber($ohms) . ' Ом';
    if ($ohms >= 1e-3) return rf_formatNumber($ohms * 1e3) . ' мОм';
    return rf_formatNumber($ohms * 1e6) . ' мкОм';
}

function rf_selected(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

$voltageType = (string) ($_POST['voltage_type'] ?? 'vpp');
if (!in_array($voltageType, ['vpp', 'vp', 'vrms'], true)) $voltageType = 'vpp';
$voltageRaw = trim((string) ($_POST['voltage'] ?? '1'));
$voltageUnit = (string) ($_POST['voltage_unit'] ?? 'v');
if (!in_array($voltageUnit, ['nv', 'uv', 'mv', 'v', 'kv'], true)) $voltageUnit = 'v';
$resistanceRaw = trim((string) ($_POST['resistance'] ?? '50'));
$resistanceUnit = (string) ($_POST['resistance_unit'] ?? 'ohm');
if (!in_array($resistanceUnit, ['mohm', 'ohm', 'kohm', 'megaohm'], true)) $resistanceUnit = 'ohm';

$isSubmitted = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['calculate']);
$error = '';
$result = null;

if ($isSubmitted) {
    $inputVoltage = rf_nonNegativeNumber($voltageRaw);
    $inputResistance = rf_nonNegativeNumber($resistanceRaw);

    if ($inputVoltage === null) {
        $error = 'Введите корректное напряжение — конечное число не меньше нуля. Можно использовать точку или запятую.';
    } elseif ($inputResistance === null || $inputResistance <= 0.0) {
        $error = 'Сопротивление нагрузки должно быть конечным числом больше нуля.';
    } else {
        $inputVoltage *= rf_voltageFactor($voltageUnit);
        $resistanceOhms = $inputResistance * rf_resistanceFactor($resistanceUnit);

        if (!is_finite($inputVoltage) || !is_finite($resistanceOhms) || $resistanceOhms <= 0.0) {
            $error = 'Значения находятся за пределами вычислимого диапазона. Проверьте величины и единицы измерения.';
        } else {
            if ($voltageType === 'vpp') {
                $vpp = $inputVoltage;
                $vp = $vpp / 2.0;
                $vrms = $vpp / (2.0 * sqrt(2.0));
            } elseif ($voltageType === 'vp') {
                $vp = $inputVoltage;
                $vpp = 2.0 * $vp;
                $vrms = $vp / sqrt(2.0);
            } else {
                $vrms = $inputVoltage;
                $vp = $vrms * sqrt(2.0);
                $vpp = 2.0 * $vp;
            }

            $powerW = ($vrms * $vrms) / $resistanceOhms;
            if (!is_finite($vpp) || !is_finite($vp) || !is_finite($vrms) || !is_finite($powerW)) {
                $error = 'Получился слишком большой результат для расчёта. Введите меньшие значения напряжения или проверьте сопротивление.';
            } else {
                $dbm = $powerW > 0.0 ? 10.0 * log10($powerW / 1e-3) : null;
                $result = [
                    'vpp' => $vpp,
                    'vp' => $vp,
                    'vrms' => $vrms,
                    'resistance' => $resistanceOhms,
                    'power_w' => $powerW,
                    'dbm' => $dbm,
                ];
            }
        }
    }
}

$voltageTypeLabels = [
    'vpp' => 'Размах напряжения Vpp',
    'vp' => 'Амплитуда Vp',
    'vrms' => 'Действующее напряжение Vrms',
];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Калькулятор ВЧ-мощности — радиолюбительские калькуляторы</title>
    <meta name="description" content="Расчёт мощности синусоидального ВЧ-сигнала по Vpp, Vp или Vrms и сопротивлению нагрузки. Результат в Вт, мВт, мкВт и dBm.">
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
        .diagram { display: block; width: 100%; height: auto; margin: 16px 0; border: 1px solid #d7dce1; border-radius: 8px; background: #fff; }
        .note { padding: 12px 14px; border-left: 4px solid #3c7ca7; background: #f2f7fb; border-radius: 5px; line-height: 1.45; }
        .calculator-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 22px; align-items: start; }
        .diagram-column, .form-column { min-width: 0; }
        .diagram { margin: 0 0 14px; }
        .form-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; }
        .field { min-width: 0; }
        .field-label { display: block; font-weight: 700; margin-bottom: 6px; }
        select, input, button { font: inherit; }
        .value-row { display: grid; grid-template-columns: minmax(0, 1fr) 92px; gap: 8px; max-width: 280px; }
        .value-input, .unit-select, .mode-select { width: 100%; min-width: 0; border: 1px solid #b8c0c8; border-radius: 6px; padding: 10px; background: #fff; }
        .value-input:focus, .unit-select:focus, .mode-select:focus { outline: 3px solid #c5e2f8; border-color: #3976a8; }
        .mode-select { margin-bottom: 14px; }
        .mode-field { margin-bottom: 15px; }
        .action-row { display: flex; align-items: center; gap: 12px; margin-top: 17px; }
        button { display: inline-flex; align-items: center; justify-content: center; min-height: 42px; border: 0; border-radius: 6px; padding: 11px 16px; color: #fff; background: #245c88; font-weight: 700; line-height: 1.25; cursor: pointer; }
        button:hover { background: #19476c; }
        button:focus-visible, a:focus-visible { outline: 3px solid #89bde5; outline-offset: 3px; }
        .result { margin-top: 18px; padding: 16px; border: 1px solid #bfd8c6; border-radius: 8px; background: #f3faf5; overflow-wrap: anywhere; }
        .result-heading { margin: 0 0 12px; color: #244b32; font-size: 1.2rem; }
        .power-main { font-size: clamp(1.8rem, 4vw, 2.5rem); line-height: 1.2; font-weight: 700; color: #173c25; }
        .power-secondary { margin-top: 5px; color: #385744; }
        .dbm { margin-top: 10px; font-size: 1.3rem; font-weight: 700; color: #173c25; }
        .results-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-top: 15px; }
        .result-item { min-width: 0; border: 1px solid #d5e6da; border-radius: 7px; padding: 10px; background: rgba(255,255,255,.72); }
        .result-label { display: block; color: #385744; font-size: .9rem; margin-bottom: 5px; }
        .result-value { font-weight: 700; font-size: 1.12rem; overflow-wrap: anywhere; }
        .formula { margin-bottom: 0; color: #4d5b52; font-size: .95rem; line-height: 1.65; }
        .error { margin-top: 16px; padding: 12px; border: 1px solid #e4b7b7; border-radius: 8px; background: #fff5f5; color: #8b2323; line-height: 1.45; }
        .stale-hint { display: none; margin-top: 12px; color: #7a5200; font-size: .93rem; }
        .small { font-size: .9rem; }
        .clear-link { color: #244f76; }
        @media (max-width: 680px) {
            .calculator-layout { grid-template-columns: minmax(0, 1fr); }
        }
        @media (max-width: 680px) {
            body { padding: 10px; }
            .panel { padding: 15px; }
            .form-grid { grid-template-columns: minmax(0, 1fr); }
            .results-grid { grid-template-columns: minmax(0, 1fr); }
            .diagram { margin: 0 0 12px; }
            .action-row { flex-direction: column; align-items: flex-start; }
            .action-row button { width: 100%; }
        }
        @media print {
            body { padding: 0; background: #fff; }
            .panel { border-color: #aaa; break-inside: avoid; }
            .no-print, .back-link, button, .clear-link, .stale-hint { display: none !important; }
            .result { border-color: #999; background: #fff; }
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Калькулятор ВЧ-мощности</h1>
        <p class="subtle">Расчёт мощности синусоидального сигнала по измеренному напряжению на активной нагрузке. Подходит для оценки ВЧ-мощности по показаниям осциллографа.</p>
        <a class="back-link" href="index.php">← К списку калькуляторов</a>
    </section>

    <section class="panel">
        <div class="calculator-layout">
            <div class="diagram-column">
                <img class="diagram" src="images/rf-sine-voltage.svg" alt="Синусоида с обозначениями размаха Vpp, амплитуды Vp и действующего напряжения Vrms" width="760" height="260">
                <div class="note"><strong>Важно:</strong> расчёт предназначен только для синусоидального сигнала и активной резистивной нагрузки. Для прямоугольного, искажённого сигнала или реактивной нагрузки этих формул недостаточно.</div>
            </div>
            <div class="form-column">
                <h2>Исходные данные</h2>
                <form id="rf-form" method="post" action="">
            <div class="mode-field">
                <label class="field-label" for="voltage_type">Какое напряжение измерено?</label>
                <select class="mode-select" id="voltage_type" name="voltage_type">
                    <option value="vpp"<?= rf_selected($voltageType, 'vpp') ?>>Размах Vpp — от нижнего пика до верхнего</option>
                    <option value="vp"<?= rf_selected($voltageType, 'vp') ?>>Амплитуда Vp — от нулевого уровня до пика</option>
                    <option value="vrms"<?= rf_selected($voltageType, 'vrms') ?>>Действующее напряжение Vrms</option>
                </select>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label class="field-label" for="voltage" id="voltage-label"><?= rf_h($voltageTypeLabels[$voltageType]) ?></label>
                    <div class="value-row">
                        <input class="value-input" id="voltage" name="voltage" type="text" inputmode="decimal" autocomplete="off" value="<?= rf_h($voltageRaw) ?>" aria-describedby="voltage-help" required>
                        <select class="unit-select" name="voltage_unit" aria-label="Единица напряжения">
                            <option value="nv"<?= rf_selected($voltageUnit, 'nv') ?>>нВ</option>
                            <option value="uv"<?= rf_selected($voltageUnit, 'uv') ?>>мкВ</option>
                            <option value="mv"<?= rf_selected($voltageUnit, 'mv') ?>>мВ</option>
                            <option value="v"<?= rf_selected($voltageUnit, 'v') ?>>В</option>
                            <option value="kv"<?= rf_selected($voltageUnit, 'kv') ?>>кВ</option>
                        </select>
                    </div>
                    <div id="voltage-help" class="subtle small">Можно использовать десятичную точку или запятую.</div>
                </div>
                <div class="field">
                    <label class="field-label" for="resistance">Сопротивление нагрузки</label>
                    <div class="value-row">
                        <input class="value-input" id="resistance" name="resistance" type="text" inputmode="decimal" autocomplete="off" value="<?= rf_h($resistanceRaw) ?>" required>
                        <select class="unit-select" name="resistance_unit" aria-label="Единица сопротивления">
                            <option value="mohm"<?= rf_selected($resistanceUnit, 'mohm') ?>>мОм</option>
                            <option value="ohm"<?= rf_selected($resistanceUnit, 'ohm') ?>>Ом</option>
                            <option value="kohm"<?= rf_selected($resistanceUnit, 'kohm') ?>>кОм</option>
                            <option value="megaohm"<?= rf_selected($resistanceUnit, 'megaohm') ?>>МОм</option>
                        </select>
                    </div>
                    <div class="subtle small">Для ВЧ обычно используют 50 Ом; значение можно изменить.</div>
                </div>
            </div>

            <div class="action-row">
                <button type="submit" name="calculate" value="1">Рассчитать</button>
                <a class="clear-link" href="rf_power.php">Сбросить</a>
            </div>
            <div class="stale-hint" id="stale-hint">Параметры изменены. Нажми «Рассчитать», чтобы обновить результат.</div>
        </form>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="error" id="calculation-error" role="alert"><?= rf_h($error) ?></div>
        <?php endif; ?>

        <?php if ($result !== null): ?>
            <div class="result" id="calculation-result" aria-live="polite">
                <h2 class="result-heading">Результат расчёта</h2>
                <span class="result-label">Мощность на нагрузке</span>
                <div class="power-main"><?= rf_h(rf_formatPower($result['power_w'])) ?></div>
                <div class="power-secondary"><?= rf_h(rf_formatNumber($result['power_w'], 9)) ?> Вт</div>
                <div class="dbm"><?= $result['dbm'] === null ? 'Уровень dBm не определён при нулевой мощности' : rf_h(rf_formatNumber($result['dbm'], 5)) . ' dBm' ?></div>

                <div class="results-grid">
                    <div class="result-item"><span class="result-label">Размах Vpp</span><div class="result-value"><?= rf_h(rf_formatVoltage($result['vpp'])) ?></div></div>
                    <div class="result-item"><span class="result-label">Амплитуда Vp</span><div class="result-value"><?= rf_h(rf_formatVoltage($result['vp'])) ?></div></div>
                    <div class="result-item"><span class="result-label">Действующее Vrms</span><div class="result-value"><?= rf_h(rf_formatVoltage($result['vrms'])) ?></div></div>
                </div>
                <p class="formula">Нагрузка: <?= rf_h(rf_formatResistance($result['resistance'])) ?>. Формула мощности: P = Vrms² / R.</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Используемые соотношения</h2>
        <p class="formula">
            По размаху: Vrms = Vpp / (2√2).<br>
            По амплитуде: Vrms = Vp / √2.<br>
            По действующему значению: Vrms вводится напрямую.<br>
            Мощность: P = Vrms² / R.<br>
            Уровень мощности: dBm = 10 · log₁₀(P / 1 мВт).
        </p>
        <p class="subtle small">dBm отсчитывается относительно 1 мВт. При нулевой мощности уровень в dBm не определён.</p>
    </section>
</main>
<script>
(() => {
    const typeSelect = document.getElementById('voltage_type');
    const voltageLabel = document.getElementById('voltage-label');
    const labels = {
        vpp: 'Размах напряжения Vpp',
        vp: 'Амплитуда Vp',
        vrms: 'Действующее напряжение Vrms'
    };
    const result = document.getElementById('calculation-result');
    const error = document.getElementById('calculation-error');
    const hint = document.getElementById('stale-hint');
    const form = document.getElementById('rf-form');

    typeSelect.addEventListener('change', () => {
        voltageLabel.textContent = labels[typeSelect.value] || labels.vpp;
    });

    if (result || error) {
        form.querySelectorAll('input, select').forEach((control) => {
            control.addEventListener('input', markOutdated);
            control.addEventListener('change', markOutdated);
        });
    }

    function markOutdated() {
        if (result) result.hidden = true;
        if (error) error.hidden = true;
        if (hint) hint.style.display = 'block';
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
