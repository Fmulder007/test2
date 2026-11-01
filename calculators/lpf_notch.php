<?php
/*
 * Идеальный ФНЧ Баттерворта или Чебышёва 0,1 дБ с подавлением второй гармоники.
 * Топология: параллельная ёмкость на входе и выходе; последовательные катушки.
 * Для порядка N=5 используются 2 катушки и 3 шунтирующих конденсатора.
 * Для порядка N=7 используются 3 катушки и 4 шунтирующих конденсатора.
 * Cдоп подключён параллельно второй последовательной катушке L2 и настроен на 2Fc.
 * График рассчитывается для идеальных компонентов с одинаковыми Rs и RL.
 * Версия 6: сгруппированная таблица номиналов на всю ширину — катушки/Cдоп слева, ёмкости справа.
 */
declare(strict_types=1);

require_once __DIR__ . '/cct_frequency_axis.php';

function lpf_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function lpf_number(string $raw): ?float
{
    $raw = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($raw));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $value = (float) $raw;
    return ($value > 0.0 && is_finite($value)) ? $value : null;
}

function lpf_num(float $value, int $decimals = 6): string
{
    if (!is_finite($value)) {
        return '—';
    }
    if ($value !== 0.0 && (abs($value) >= 1.0e12 || abs($value) < 1.0e-5)) {
        return str_replace('.', ',', sprintf('%.6g', $value));
    }
    $formatted = number_format($value, $decimals, ',', '');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    return ($formatted === '' || $formatted === '-0') ? '0' : $formatted;
}

function lpf_checked(string $current, string $value): string
{
    return $current === $value ? ' checked' : '';
}

/** Строка компонента: расчётный номинал и необязательное измеренное значение. */
function lpf_manual_component_row(
    string $fieldKey,
    string $componentName,
    string $description,
    ?float $calculatedValue,
    string $unit,
    string $kind,
    int $index,
    array $manualValues
): string {
    $calculatedText = $calculatedValue === null ? '—' : lpf_num($calculatedValue) . ' ' . $unit;
    $manualValue = isset($manualValues[$fieldKey]) ? (string) $manualValues[$fieldKey] : '';
    $inputId = 'manual_' . str_replace('_', '-', $fieldKey);
    return '<div class="part-row manual-part-row">'
        . '<div class="part-label"><strong>' . lpf_h($componentName) . '</strong><span class="part-description">' . lpf_h($description) . '</span></div>'
        . '<div class="calculated-cell"><span class="column-caption">Расчёт</span><strong class="part-value calc-value">' . lpf_h($calculatedText) . '</strong></div>'
        . '<div class="manual-cell"><label for="' . lpf_h($inputId) . '"><span class="column-caption">Измерено, ' . lpf_h($unit) . '</span>'
        . '<input class="manual-nominal" id="' . lpf_h($inputId) . '" type="text" inputmode="decimal" autocomplete="off" data-key="' . lpf_h($fieldKey) . '" data-kind="' . lpf_h($kind) . '" data-index="' . $index . '" value="' . lpf_h($manualValue) . '" placeholder="не задано" aria-label="Измеренное значение ' . lpf_h($componentName) . ', ' . lpf_h($unit) . '"></label></div>'
        . '</div>';
}

/** Получить g-коэффициенты нормированного прототипа для нечётного порядка. */
function lpf_g_values(int $order, string $response, float $rippleDb = 0.1): array
{
    if ($response === 'butter') {
        $g = [];
        for ($k = 1; $k <= $order; $k++) {
            $g[] = 2.0 * sin(((2.0 * $k) - 1.0) * M_PI / (2.0 * $order));
        }
        return $g;
    }

    // Формулы классического прототипа Чебышёва с пульсацией в полосе 0,1 дБ.
    $beta = log(1.0 / tanh($rippleDb / 17.37));
    $gamma = sinh($beta / (2.0 * $order));
    $a = [];
    $b = [];
    for ($k = 1; $k <= $order; $k++) {
        $a[] = sin(((2.0 * $k) - 1.0) * M_PI / (2.0 * $order));
        $b[] = ($gamma * $gamma) + pow(sin($k * M_PI / $order), 2.0);
    }
    $g = array_fill(0, $order, 0.0);
    $g[0] = 2.0 * $a[0] / $gamma;
    for ($k = 2; $k <= $order; $k++) {
        $g[$k - 1] = (4.0 * $a[$k - 2] * $a[$k - 1]) / ($b[$k - 2] * $g[$k - 2]);
    }
    return $g;
}


/**
 * Профили экспорта RFSim99. Общий генератор использует описания профилей,
 * поэтому новые варианты фильтра добавляются настройками, а не копированием кода.
 */
function lpf_cct_export_profiles(): array
{
    // Общая конфигурация физической схемы 5-го порядка.
    // Для Баттерворта и Чебышёва используется один и тот же шаблон и генератор.
    $fifthOrderProfile = [
        'template' => 'LPF_5TH.cct',
        'components' => [
            [
                'symbol' => 'C1', 'x' => 160, 'y' => 180, 'ctype' => 2,
                'result_key' => 'capacitors_pf', 'result_index' => 0,
                'result_scale' => 1.0e-12, 'manual_key' => '5_C1',
                'allow_zero' => false,
            ],
            [
                'symbol' => 'C2', 'x' => 300, 'y' => 180, 'ctype' => 2,
                'result_key' => 'capacitors_pf', 'result_index' => 1,
                'result_scale' => 1.0e-12, 'manual_key' => '5_C2',
                'allow_zero' => false,
            ],
            [
                'symbol' => 'C3', 'x' => 440, 'y' => 180, 'ctype' => 2,
                'result_key' => 'capacitors_pf', 'result_index' => 2,
                'result_scale' => 1.0e-12, 'manual_key' => '5_C3',
                'allow_zero' => false,
            ],
            [
                'symbol' => 'Cnotch', 'x' => 370, 'y' => 60, 'ctype' => 2,
                'result_key' => 'c_notch_pf', 'result_index' => null,
                'result_scale' => 1.0e-12, 'manual_key' => '5_Cnotch',
                'allow_zero' => true,
            ],
            [
                'symbol' => 'L1', 'x' => 230, 'y' => 130, 'ctype' => 3,
                'result_key' => 'inductors_uh', 'result_index' => 0,
                'result_scale' => 1.0e-6, 'manual_key' => '5_L1',
                'allow_zero' => false,
            ],
            [
                'symbol' => 'L2', 'x' => 370, 'y' => 130, 'ctype' => 3,
                'result_key' => 'inductors_uh', 'result_index' => 1,
                'result_scale' => 1.0e-6, 'manual_key' => '5_L2',
                'allow_zero' => false,
            ],
        ],
        'ports' => ['P1' => 'resistance', 'P2' => 'resistance'],
        'axis_min_factor' => 1.0 / 3.0,
        'axis_max_factor' => 4.0,
    ];

    // Общая конфигурация физической схемы 7-го порядка.
    // Баттерворт и Чебышёв используют один шаблон LPF_7TH.cct.
    $seventhOrderProfile = [
        'template' => 'LPF_7TH.cct',
        'components' => [
            [
                'symbol' => 'C1', 'x' => 160, 'y' => 180, 'ctype' => 2,
                'result_key' => 'capacitors_pf', 'result_index' => 0,
                'result_scale' => 1.0e-12, 'manual_key' => '7_C1',
                'allow_zero' => false,
            ],
            [
                'symbol' => 'C2', 'x' => 300, 'y' => 180, 'ctype' => 2,
                'result_key' => 'capacitors_pf', 'result_index' => 1,
                'result_scale' => 1.0e-12, 'manual_key' => '7_C2',
                'allow_zero' => false,
            ],
            [
                'symbol' => 'C3', 'x' => 440, 'y' => 180, 'ctype' => 2,
                'result_key' => 'capacitors_pf', 'result_index' => 2,
                'result_scale' => 1.0e-12, 'manual_key' => '7_C3',
                'allow_zero' => false,
            ],
            [
                'symbol' => 'C4', 'x' => 620, 'y' => 180, 'ctype' => 2,
                'result_key' => 'capacitors_pf', 'result_index' => 3,
                'result_scale' => 1.0e-12, 'manual_key' => '7_C4',
                'allow_zero' => false,
            ],
            [
                'symbol' => 'Cnotch', 'x' => 370, 'y' => 60, 'ctype' => 2,
                'result_key' => 'c_notch_pf', 'result_index' => null,
                'result_scale' => 1.0e-12, 'manual_key' => '7_Cnotch',
                'allow_zero' => true,
            ],
            [
                'symbol' => 'L1', 'x' => 230, 'y' => 130, 'ctype' => 3,
                'result_key' => 'inductors_uh', 'result_index' => 0,
                'result_scale' => 1.0e-6, 'manual_key' => '7_L1',
                'allow_zero' => false,
            ],
            [
                'symbol' => 'L2', 'x' => 370, 'y' => 130, 'ctype' => 3,
                'result_key' => 'inductors_uh', 'result_index' => 1,
                'result_scale' => 1.0e-6, 'manual_key' => '7_L2',
                'allow_zero' => false,
            ],
            [
                'symbol' => 'L3', 'x' => 540, 'y' => 130, 'ctype' => 3,
                'result_key' => 'inductors_uh', 'result_index' => 2,
                'result_scale' => 1.0e-6, 'manual_key' => '7_L3',
                'allow_zero' => false,
            ],
        ],
        'ports' => ['P1' => 'resistance', 'P2' => 'resistance'],
        'axis_min_factor' => 1.0 / 3.0,
        'axis_max_factor' => 4.0,
    ];

    return [
        'butter:5' => $fifthOrderProfile + ['filename_prefix' => 'LPF_Butterworth_5'],
        'cheby:5' => $fifthOrderProfile + ['filename_prefix' => 'LPF_Chebyshev_5'],
        'butter:7' => $seventhOrderProfile + ['filename_prefix' => 'LPF_Butterworth_7'],
        'cheby:7' => $seventhOrderProfile + ['filename_prefix' => 'LPF_Chebyshev_7'],
    ];
}

/** Разбирает измеренное значение так же, как клиентская модель графика. */
function lpf_parse_manual_export_value(string $raw, bool $allowZero): array
{
    $normalized = preg_replace('/[\\s\\x{00A0}\\x{202F}]+/u', '', trim($raw));
    if ($normalized === '') {
        return ['empty' => true, 'valid' => true, 'value' => null];
    }
    $normalized = str_replace(',', '.', $normalized);
    if (!is_numeric($normalized)) {
        return ['empty' => false, 'valid' => false, 'value' => null];
    }
    $value = (float) $normalized;
    $valid = is_finite($value) && ($allowZero ? $value >= 0.0 : $value > 0.0);
    return ['empty' => false, 'valid' => $valid, 'value' => $valid ? $value : null];
}

/**
 * Возвращает эффективные номиналы в единицах, используемых в результате расчёта,
 * применяя измеренные значения только для заполненных полей активного профиля.
 */
function lpf_resolve_cct_component_values(array $result, array $manualValues, array $profile): array
{
    $values = [];

    foreach ($profile['components'] as $component) {
        $resultKey = $component['result_key'];
        $resultIndex = $component['result_index'];
        if (!array_key_exists($resultKey, $result)) {
            throw new RuntimeException('В расчёте отсутствует значение для ' . $component['symbol'] . '.');
        }
        $baseValue = $resultIndex === null ? $result[$resultKey] : ($result[$resultKey][$resultIndex] ?? null);
        if (!is_numeric($baseValue) || !is_finite((float) $baseValue)) {
            throw new RuntimeException('Расчётное значение ' . $component['symbol'] . ' некорректно.');
        }

        $effectiveValue = (float) $baseValue;
        $manualKey = $component['manual_key'];
        if (array_key_exists($manualKey, $manualValues)) {
            $parsed = lpf_parse_manual_export_value((string) $manualValues[$manualKey], (bool) $component['allow_zero']);
            if (!$parsed['valid']) {
                throw new InvalidArgumentException('Некорректное измеренное значение ' . $component['symbol'] . '. Исправьте поле «Измерено».');
            }
            if (!$parsed['empty']) {
                $effectiveValue = (float) $parsed['value'];
            }
        }

        if ($effectiveValue < 0.0 || !is_finite($effectiveValue) || (!$component['allow_zero'] && $effectiveValue <= 0.0)) {
            throw new RuntimeException('Эффективное значение ' . $component['symbol'] . ' недопустимо.');
        }
        $values[$component['symbol']] = $effectiveValue * (float) $component['result_scale'];
    }

    return ['values_si' => $values];
}

/** Проверяет дату/время локального компьютера, переданные браузером для имени файла. */
function lpf_validate_client_timestamp(string $timestamp): string
{
    if (!preg_match('/\A(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})\z/', $timestamp, $parts)) {
        throw new InvalidArgumentException('Не удалось получить дату и время компьютера. Повторите скачивание.');
    }

    $year = (int) $parts[1];
    $month = (int) $parts[2];
    $day = (int) $parts[3];
    $hour = (int) $parts[4];
    $minute = (int) $parts[5];
    $second = (int) $parts[6];

    if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
        throw new InvalidArgumentException('Дата и время компьютера имеют неверный формат. Повторите скачивание.');
    }

    return $timestamp;
}

/** Научная запись RFSim99 с четырёхзначным порядком степени. */
function lpf_cct_scientific(float $value): string
{
    if (!is_finite($value) || $value <= 0.0) {
        throw new RuntimeException('Для записи в CCT требуется положительный конечный номинал.');
    }
    $formatted = str_replace(',', '.', sprintf('%.14E', $value));
    return (string) preg_replace_callback(
        '/E([+-])(\\d+)$/',
        static fn(array $match): string => 'E' . $match[1] . str_pad($match[2], 4, '0', STR_PAD_LEFT),
        $formatted
    );
}

/** Заменяет только value[1].nom в компоненте, найденном по координатам и ctype. */
function lpf_cct_replace_component_nominal(string $block, float $valueSi, string $symbol): string
{
    $nominal = ($symbol === 'Cnotch' && $valueSi === 0.0)
        ? '1.00000000000000E-0020'
        : lpf_cct_scientific($valueSi);
    $count = 0;
    $updated = preg_replace_callback(
        '/(^value\\[1\\]=\\{nom= )[^\\r\\n;]+(?=;)/m',
        static fn(array $match): string => $match[1] . $nominal,
        $block,
        1,
        $count
    );
    if (!is_string($updated) || $count !== 1) {
        throw new RuntimeException('Не удалось заменить номинал ' . $symbol . ' в CCT-шаблоне.');
    }
    return $updated;
}

/** Универсальная подстановка номиналов, импедансов портов и диапазона Xax. */
function lpf_build_cct_from_profile(string $template, array $profile, array $valuesSi, array $portValues, float $cutoffHz): string
{
    $componentLookup = [];
    foreach ($profile['components'] as $component) {
        $coordinateKey = $component['x'] . ':' . $component['y'];
        if (isset($componentLookup[$coordinateKey]) || !array_key_exists($component['symbol'], $valuesSi)) {
            throw new RuntimeException('Некорректная конфигурация компонентов CCT-профиля.');
        }
        $componentLookup[$coordinateKey] = $component;
    }

    $seen = [];
    $componentPattern = '/component\\{\\r?\\n.*?^\\}/ms';
    $updated = preg_replace_callback($componentPattern, static function (array $match) use ($componentLookup, $valuesSi, &$seen): string {
        $block = $match[0];
        if (!preg_match('/^position\\.x=(\\d+)\\r?$/m', $block, $xMatch)
            || !preg_match('/^position\\.y=(\\d+)\\r?$/m', $block, $yMatch)) {
            return $block;
        }
        $key = $xMatch[1] . ':' . $yMatch[1];
        if (!isset($componentLookup[$key])) {
            return $block;
        }
        $component = $componentLookup[$key];
        if (!preg_match('/^ctype=(\\d+)\\r?$/m', $block, $typeMatch)
            || (int) $typeMatch[1] !== (int) $component['ctype']) {
            throw new RuntimeException('Тип компонента в CCT не совпадает с профилем для ' . $component['symbol'] . '.');
        }
        $symbol = $component['symbol'];
        $seen[$symbol] = ($seen[$symbol] ?? 0) + 1;
        return lpf_cct_replace_component_nominal($block, (float) $valuesSi[$symbol], $symbol);
    }, $template);

    if (!is_string($updated)) {
        throw new RuntimeException('Ошибка обработки компонентных блоков CCT.');
    }
    foreach ($profile['components'] as $component) {
        $symbol = $component['symbol'];
        if (($seen[$symbol] ?? 0) !== 1) {
            throw new RuntimeException('В CCT-шаблоне должен находиться ровно один компонент ' . $symbol . ' в заданных координатах.');
        }
    }

    foreach ($profile['ports'] as $portName => $portValueKey) {
        if (!array_key_exists($portValueKey, $portValues)) {
            throw new RuntimeException('Для порта ' . $portName . ' не задано значение импеданса.');
        }
        $portNominal = lpf_cct_scientific((float) $portValues[$portValueKey]);
        $portCount = 0;
        $portPattern = '/(^' . preg_quote($portName, '/') . '\\{\\r?\\n.*?^\\})/ms';
        $updated = preg_replace_callback($portPattern, static function (array $match) use ($portNominal, &$portCount): string {
            $block = $match[0];
            $blockCount = 0;
            $block = preg_replace_callback(
                '/(^Zo=\\s*)[^\\r\\n]+/m',
                static fn(array $zoMatch): string => $zoMatch[1] . $portNominal,
                $block,
                1,
                $blockCount
            );
            if (!is_string($block) || $blockCount !== 1) {
                throw new RuntimeException('В блоке порта отсутствует единственная строка Zo.');
            }
            $portCount++;
            return $block;
        }, $updated, -1, $replaceCount);
        if (!is_string($updated) || $replaceCount !== 1 || $portCount !== 1) {
            throw new RuntimeException('В CCT-шаблоне не найден единственный порт ' . $portName . '.');
        }
    }

    $axisMin = $cutoffHz * (float) $profile['axis_min_factor'];
    $axisMax = $cutoffHz * (float) $profile['axis_max_factor'];
    [$axisMin, $axisMax] = cct_frequency_axis_bounds($axisMin, $axisMax);
    $axisCount = 0;
    $updated = preg_replace_callback('/(^Xax\\{\\r?\\n.*?^\\})/ms', static function (array $match) use ($axisMin, $axisMax, &$axisCount): string {
        $block = $match[0];
        $minCount = 0;
        $maxCount = 0;
        $block = preg_replace_callback('/(^min=\\s*)[^\\r\\n]+/m', static fn(array $line): string => $line[1] . lpf_cct_scientific($axisMin), $block, 1, $minCount);
        $block = preg_replace_callback('/(^max=\\s*)[^\\r\\n]+/m', static fn(array $line): string => $line[1] . lpf_cct_scientific($axisMax), $block, 1, $maxCount);
        if (!is_string($block) || $minCount !== 1 || $maxCount !== 1) {
            throw new RuntimeException('Не удалось обновить границы Xax в CCT-шаблоне.');
        }
        $axisCount++;
        return $block;
    }, $updated, -1, $axisReplaceCount);
    if (!is_string($updated) || $axisReplaceCount !== 1 || $axisCount !== 1) {
        throw new RuntimeException('В CCT-шаблоне не найден единственный блок Xax.');
    }

    return $updated;
}

$resistanceRaw = trim((string) ($_POST['resistance'] ?? '50'));
$cutoffRaw = trim((string) ($_POST['cutoff'] ?? '30'));
$response = (string) ($_POST['response'] ?? 'butter');
if (!in_array($response, ['butter', 'cheby'], true)) {
    $response = 'butter';
}
$orderChoice = (string) ($_POST['order'] ?? '5');
if (!in_array($orderChoice, ['5', '7'], true)) {
    $orderChoice = '5';
}
$order = (int) $orderChoice;
$isExportRequest = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['export_cct']);
$isSubmitted = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['calculate']);
$error = '';
$result = null;
$chartData = null;
$manualValuesJson = trim((string) ($_POST['manual_values_json'] ?? ''));
$manualInputValues = [];
if ($manualValuesJson !== '') {
    $decodedManualValues = json_decode($manualValuesJson, true);
    if (is_array($decodedManualValues)) {
        foreach ($decodedManualValues as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $manualInputValues[$key] = trim((string) $value);
            }
        }
    }
}

if ($isSubmitted || $isExportRequest) {
    $resistance = lpf_number($resistanceRaw);
    $cutoffMHz = lpf_number($cutoffRaw);

    if ($resistance === null || $cutoffMHz === null) {
        $error = 'Импеданс и частота среза должны быть конечными числами больше нуля. Десятичную часть можно отделять запятой или точкой.';
    } elseif ($resistance > 1.0e7 || $cutoffMHz > 1.0e6) {
        $error = 'Введённые значения слишком велики для надёжного расчёта. Проверьте импеданс и частоту среза.';
    } else {
        $fcHz = $cutoffMHz * 1.0e6;
        $omegaC = 2.0 * M_PI * $fcHz;
        $gValues = lpf_g_values($order, $response, 0.1);
        $inductorsH = [];
        $capacitorsF = [];

        // При шунтирующем первом звене нечётные g — C на землю, чётные g — последовательные L.
        foreach ($gValues as $index => $g) {
            $k = $index + 1;
            if (($k % 2) === 1) {
                $capacitorsF[] = $g / ($resistance * $omegaC);
            } else {
                $inductorsH[] = ($g * $resistance) / $omegaC;
            }
        }

        // Cдоп параллельно L2; настройка на частоту 2*Fc без коррекции остальных номиналов.
        if (count($inductorsH) < 2) {
            $error = 'Для установки резонансного конденсатора требуется как минимум две катушки.';
        } else {
            $l2H = $inductorsH[1];
            $resonanceHz = 2.0 * $fcHz;
            $cNotchF = 1.0 / (pow(2.0 * M_PI * $resonanceHz, 2.0) * $l2H);
            $allValues = array_merge([$resistance, $cutoffMHz, $cNotchF], $inductorsH, $capacitorsF);
            $valid = true;
            foreach ($allValues as $value) {
                if (!is_finite($value) || $value <= 0.0) {
                    $valid = false;
                    break;
                }
            }

            if (!$valid) {
                $error = 'Результат выходит за пределы вычислимого диапазона. Проверьте исходные значения.';
            } else {
                $inductorsUH = array_map(static fn(float $v): float => $v * 1.0e6, $inductorsH);
                $capacitorsPF = array_map(static fn(float $v): float => $v * 1.0e12, $capacitorsF);
                $result = [
                    'resistance' => $resistance,
                    'cutoff_mhz' => $cutoffMHz,
                    'order' => $order,
                    'response' => $response,
                    'ripple_db' => ($response === 'cheby') ? 0.1 : null,
                    'inductors_uh' => $inductorsUH,
                    'capacitors_pf' => $capacitorsPF,
                    'c_notch_pf' => $cNotchF * 1.0e12,
                    'resonance_mhz' => $resonanceHz / 1.0e6,
                    'g_values' => $gValues,
                ];
                $chartData = [
                    'resistance' => $resistance,
                    'cutoff_hz' => $fcHz,
                    'order' => $order,
                    'response' => $response,
                    'inductors_h' => $inductorsH,
                    'capacitors_f' => $capacitorsF,
                    'c_notch_f' => $cNotchF,
                ];
            }
        }
    }
}


$exportProfiles = lpf_cct_export_profiles();
$exportProfileKey = $response . ':' . $order;
$canExportCct = $result !== null && isset($exportProfiles[$exportProfileKey]);
$initialExportValuesValid = true;
if ($canExportCct) {
    try {
        // Проверяем измеренные значения на сервере ещё до показа кнопки.
        lpf_resolve_cct_component_values($result, $manualInputValues, $exportProfiles[$exportProfileKey]);
    } catch (Throwable $exception) {
        $initialExportValuesValid = false;
    }
}

if ($isExportRequest) {
    if ($result === null || $error !== '') {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $error !== '' ? $error : 'Не удалось выполнить расчёт для экспорта.';
        exit;
    }
    if (!isset($exportProfiles[$exportProfileKey])) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Экспорт CCT пока доступен только для фильтров Баттерворта и Чебышёва 5-го и 7-го порядков.';
        exit;
    }

    try {
        if ($manualValuesJson !== '' && !is_array(json_decode($manualValuesJson, true))) {
            throw new InvalidArgumentException('Не удалось прочитать измеренные номиналы. Обновите страницу и повторите экспорт.');
        }
        $manualForExport = [];
        foreach ($manualInputValues as $key => $value) {
            $manualForExport[$key] = $value;
        }
        $profile = $exportProfiles[$exportProfileKey];
        $effective = lpf_resolve_cct_component_values($result, $manualForExport, $profile);
        $clientTimestamp = lpf_validate_client_timestamp(trim((string) ($_POST['client_timestamp'] ?? '')));
        $downloadFilename = $profile['filename_prefix'] . '_' . $clientTimestamp . '.cct';
        $templatePath = __DIR__ . '/templates/' . $profile['template'];
        if (!is_file($templatePath) || !is_readable($templatePath)) {
            throw new RuntimeException('Не найден шаблон ' . $profile['template'] . ' или нет доступа на чтение. Проверьте каталог /calculators/templates/.');
        }
        $templateContents = file_get_contents($templatePath);
        if (!is_string($templateContents) || $templateContents === '') {
            throw new RuntimeException('Не удалось прочитать CCT-шаблон.');
        }
        $cctContents = lpf_build_cct_from_profile(
            $templateContents,
            $profile,
            $effective['values_si'],
            ['resistance' => (float) $result['resistance']],
            (float) $result['cutoff_mhz'] * 1.0e6
        );
    } catch (Throwable $exception) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Не удалось сформировать схему RFSim99: ' . $exception->getMessage();
        exit;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
    header('Content-Length: ' . strlen($cctContents));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $cctContents;
    exit;
}

$diagramSrc = $order === 5 ? 'images/lpf-5th-shunt.svg' : 'images/lpf-7th-shunt.svg';
$responseName = $response === 'butter' ? 'Баттерворта' : 'Чебышёва';
$cutoffHelp = $response === 'butter'
    ? 'Для Баттерворта Fc — частота уровня −3 дБ до добавления Cдоп.'
    : 'Для Чебышёва Fc — край полосы пульсаций 0,1 дБ до добавления Cдоп.';
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Калькулятор ФНЧ с подавлением второй гармоники — радиолюбительские калькуляторы</title>
    <meta name="description" content="Расчёт идеального ФНЧ Баттерворта или Чебышёва 0,1 дБ 5-го или 7-го порядка с дополнительным конденсатором параллельно второй катушке для подавления второй гармоники.">
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #202124; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 1100px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #d7dce1; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: clamp(1.45rem, 3vw, 2rem); line-height: 1.25; }
        h2 { margin: 0 0 12px; font-size: 1.22rem; }
        h3 { margin: 0 0 10px; font-size: 1.05rem; }
        p { line-height: 1.5; }
        .subtle { color: #5f6368; }
        .small { font-size: .9rem; }
        #results-section, #form-error { scroll-margin-top: 12px; }
        .back-link { display: inline-block; margin-top: 4px; color: #244f76; font-weight: 700; text-decoration: none; }
        .back-link:hover, .clear-link:hover { text-decoration: underline; }
        a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible { outline: 3px solid #89bde5; outline-offset: 3px; }
        .note { padding: 12px 14px; border-left: 4px solid #3c7ca7; background: #f2f7fb; border-radius: 5px; line-height: 1.45; }
        .calculator-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 22px; align-items: start; }
         .input-column { min-width: 0; }
         .form-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px; align-items: center; }
        .field { display: grid; grid-template-columns: minmax(145px, .85fr) minmax(0, 1.15fr); align-items: center; gap: 10px; min-width: 0; }
        .field-label { display: block; margin: 0; font-weight: 700; line-height: 1.3; }
        input, select, button { font: inherit; }
        .value-input, .form-select { display: block; width: 100%; min-width: 0; min-height: 39px; border: 1px solid #b8c0c8; border-radius: 6px; padding: 8px 10px; background: #fff; color: #202124; }
        .value-input:focus, .form-select:focus { outline: 3px solid #c5e2f8; border-color: #3976a8; }
        .field-hint { display: none; }
        .choice-group { margin-top: 0; }
        .choice-heading { display: block; font-weight: 700; margin: 0 0 8px; }
        .choice-list { display: grid; grid-template-columns: minmax(0, 1fr); gap: 8px; }
        .choice { position: relative; display: flex; align-items: flex-start; gap: 11px; min-width: 0; padding: 12px 13px; border: 1px solid #d7dce1; border-radius: 8px; background: #fff; cursor: pointer; }
        .choice:hover { border-color: #aebdca; background: #fbfcfd; }
        .choice.selected { border-color: #3976a8; background: #f2f7fb; box-shadow: inset 0 0 0 1px #3976a8; }
        .choice input { width: 18px; height: 18px; flex: 0 0 auto; margin: 2px 0 0; accent-color: #245c88; }
        .choice-text { display: block; min-width: 0; line-height: 1.4; }
        .choice-title { display: block; font-weight: 700; }
        .choice-description { display: block; color: #5f6368; font-size: .9rem; margin-top: 3px; }
        .action-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        button { display: inline-flex; align-items: center; justify-content: center; min-height: 43px; border: 0; border-radius: 6px; padding: 11px 17px; color: #fff; background: #245c88; font-weight: 700; line-height: 1.25; cursor: pointer; }
        button:hover { background: #19476c; }
        .export-cct-button { background: #28623f; }
        .export-cct-button:hover { background: #1f4e32; }
        .clear-link { color: #244f76; }
        .error { margin-top: 15px; padding: 12px; border: 1px solid #e4b7b7; border-radius: 8px; background: #fff5f5; color: #8b2323; line-height: 1.45; }
        .stale-hint { display: none; margin-top: 12px; color: #7a5200; font-size: .93rem; }
        .result-layout { display: block; }
        .diagram-wrap { min-width: 0; }
        .diagram { display: block; width: 100%; height: auto; margin: 0; border: 1px solid #d7dce1; border-radius: 8px; background: #fff; }
        .diagram-caption { margin: 8px 1px 0; color: #5f6368; font-size: .88rem; line-height: 1.45; }
        .summary-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 13px; }
        .metric { min-width: 0; padding: 10px 11px; border: 1px solid #d7dce1; border-radius: 7px; background: #fafbfc; }
        .metric-label { display: block; margin-bottom: 4px; color: #5f6368; font-size: .88rem; line-height: 1.3; }
        .metric-value { display: block; overflow-wrap: anywhere; font-size: 1.08rem; font-weight: 700; color: #202124; line-height: 1.3; }
        .parts-list { border: 1px solid #d7dce1; border-radius: 8px; padding: 14px; margin-top: 16px; }
        .parts-list h3 { margin-bottom: 8px; }
        .component-columns { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 22px; width: 100%; }
        .component-column { min-width: 0; }
        .component-column h4 { margin: 0 0 4px; padding: 0 0 8px; border-bottom: 2px solid #e6e9ec; color: #245c88; font-size: 1rem; line-height: 1.35; }
        .part-row { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; padding: 9px 0; border-top: 1px solid #e6e9ec; }
        .part-label { min-width: 0; }
        .part-description { display: block; color: #69727b; font-size: .82rem; line-height: 1.35; margin-top: 2px; }
        .part-value { flex: 0 0 auto; text-align: right; font-weight: 700; overflow-wrap: anywhere; }
        .warning-note { margin-top: 12px; padding: 11px 12px; border-left: 4px solid #3c7ca7; border-radius: 5px; background: #f2f7fb; color: #344b5d; font-size: .9rem; line-height: 1.45; }
        .chart-panel h2 { margin-bottom: 5px; }
        .chart-subtitle { margin: 0 0 12px; color: #5f6368; font-size: .92rem; }
        .legend { display: flex; flex-wrap: wrap; gap: 10px 18px; margin: 0 0 10px; font-size: .9rem; color: #3f474d; }
        .legend-item { display: inline-flex; align-items: center; gap: 7px; }
        .legend-line { display: inline-block; width: 25px; border-top: 3px solid #245c88; }
        .legend-line.modified { border-color: #c25b24; }
        .legend-line.manual { border-color: #20835b; }
        .manual-intro { margin: 0 0 10px; color: #5f6368; font-size: .9rem; line-height: 1.45; }
        .manual-part-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(108px, .75fr) minmax(128px, .9fr); align-items: center; gap: 10px; }
        .calculated-cell { min-width: 0; }
        .calculated-cell .part-value { display: block; text-align: left; }
        .column-caption { display: block; color: #69727b; font-size: .77rem; line-height: 1.25; margin-bottom: 4px; font-weight: 400; }
        .manual-cell { min-width: 0; }
        .manual-cell label { display: block; min-width: 0; }
        .manual-nominal { display: block; width: 100%; min-width: 0; min-height: 38px; border: 1px solid #b8c0c8; border-radius: 6px; padding: 8px; background: #fff; color: #202124; font: inherit; font-size: .92rem; }
        .manual-nominal:focus { outline: 3px solid #c5e2f8; border-color: #3976a8; }
        .manual-nominal.invalid { border-color: #b92c2c; background: #fff5f5; }
        .manual-instructions { margin: 10px 0 0; color: #5f6368; font-size: .86rem; line-height: 1.45; }
        .manual-status { margin: 10px 0 0; }
        .chart-box { position: relative; width: 100%; height: 390px; border: 1px solid #d7dce1; border-radius: 8px; overflow: hidden; background: #fff; }
        canvas.chart { display: block; width: 100%; height: 100%; }
        canvas.chart[hidden] { display: none !important; }
        .chart-placeholder { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; padding: 20px; text-align: center; color: #68737d; background: #fbfcfd; line-height: 1.5; }
        .chart-placeholder[hidden] { display: none !important; }
        .chart-stats { margin: 10px 1px 0; font-size: .92rem; line-height: 1.5; }
        .formula-list { margin: 0; padding-left: 20px; line-height: 1.7; }
        .formula-list li + li { margin-top: 4px; }
        [hidden] { display: none !important; }
        @media (max-width: 760px) {
            body { padding: 10px; }
            .panel { padding: 15px; }
            .calculator-layout { grid-template-columns: minmax(0, 1fr); gap: 14px; }
            .result-layout { display: block; }
            .component-columns { grid-template-columns: minmax(0, 1fr); gap: 18px; }
            .diagram { max-width: 100%; }
            .chart-box { height: 340px; }
            .choice { padding: 11px; }
            .field { grid-template-columns: minmax(130px, .85fr) minmax(0, 1.15fr); gap: 8px; }
        }
        @media (max-width: 440px) {
            .form-grid { grid-template-columns: minmax(0, 1fr); }
            .field { grid-template-columns: minmax(112px, .8fr) minmax(0, 1.2fr); gap: 7px; }
            .field-label { font-size: .9rem; }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .metric { padding: 9px; }
            .metric-value { font-size: 1rem; }
            .chart-box { height: 310px; }
            .manual-part-row { grid-template-columns: minmax(0, .8fr) minmax(83px, .75fr) minmax(105px, .95fr); gap: 7px; }
            .manual-nominal { padding: 7px 6px; font-size: .87rem; }
            .legend { font-size: .84rem; }
        }
        @media print {
            body { padding: 0; background: #fff; }
            .panel { border-color: #aaa; break-inside: avoid; }
            .no-print, .back-link, button, .clear-link, .stale-hint, .chart-placeholder { display: none !important; }
            .result-layout { grid-template-columns: 38% minmax(0, 1fr); }
            .chart-box { height: 330px; }
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Калькулятор ФНЧ с подавлением второй гармоники</h1>
        <p class="subtle">Идеальный низкочастотный фильтр Баттерворта или Чебышёва с дополнительным конденсатором параллельно второй катушке. Исходные номиналы не корректируются после добавления Cдоп.</p>
        <a class="back-link" href="index.php">← К списку калькуляторов</a>
        <div class="note" style="margin-top:14px"><strong>Модель:</strong> без потерь катушек и конденсаторов, паразитных параметров и монтажных эффектов. Импеданс источника и нагрузки считается одинаковым.</div>
    </section>

    <section class="panel">
        <div class="calculator-layout">
            <div class="diagram-wrap">
                <img class="diagram" id="filter-diagram" src="<?= lpf_h($diagramSrc) ?>" alt="Схема ФНЧ <?= (int) $order ?>-го порядка с шунтирующими конденсаторами на входе и выходе и Cдоп параллельно L2" width="900" height="330">
                <p class="diagram-caption" id="diagram-caption"><?= $order === 5 ? 'ФНЧ 5-го порядка: две последовательные катушки и три конденсатора на землю.' : 'ФНЧ 7-го порядка: три последовательные катушки и четыре конденсатора на землю.' ?> Cдоп включён параллельно L2.</p>
            </div>
            <div class="input-column no-print">
        <h2>Исходные данные</h2>
        <form id="lpf-form" method="post" action="">
            <input type="hidden" id="manual_values_json" name="manual_values_json" value="<?= lpf_h($manualValuesJson) ?>">
            <div class="form-grid">
                <div class="field">
                    <label class="field-label" for="resistance">Импеданс, Ом</label>
                    <input class="value-input" id="resistance" name="resistance" type="text" inputmode="decimal" autocomplete="off" value="<?= lpf_h($resistanceRaw) ?>" required>
                </div>
                <div class="field">
                    <label class="field-label" for="cutoff">Частота среза Fc, МГц</label>
                    <input class="value-input" id="cutoff" name="cutoff" type="text" inputmode="decimal" autocomplete="off" value="<?= lpf_h($cutoffRaw) ?>" required>
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="response">Тип АЧХ</label>
                <select class="form-select" id="response" name="response">
                    <option value="butter"<?= $response === 'butter' ? ' selected' : '' ?>>Баттерворта</option>
                    <option value="cheby"<?= $response === 'cheby' ? ' selected' : '' ?>>Чебышёва, 0,1 дБ</option>
                </select>
            </div>
            <div class="field">
                <label class="field-label" for="order">Топология</label>
                <select class="form-select" id="order" name="order">
                    <option value="5"<?= $orderChoice === '5' ? ' selected' : '' ?>>ФНЧ 5-го порядка</option>
                    <option value="7"<?= $orderChoice === '7' ? ' selected' : '' ?>>ФНЧ 7-го порядка</option>
                </select>
            </div>
            <div class="action-row">
                <button type="submit" name="calculate" value="1">Рассчитать</button>
                <a class="clear-link" href="lpf_notch.php">Сбросить</a>
            </div>
            <?php if ($error !== ''): ?><div class="error" id="form-error" role="alert"><?= lpf_h($error) ?></div><?php endif; ?>
            <div class="stale-hint" id="stale-hint">Исходные данные изменены. Нажмите «Рассчитать», чтобы обновить номиналы и график.</div>
        </form>
            </div>
        </div>
    </section>

    <section class="panel" id="results-section">
        <h2>Рассчитанные номиналы</h2>
        <p class="subtle small">Первая и последняя цепи — шунтирующие конденсаторы на землю. Cдоп подключается параллельно второй последовательной катушке L2 и настроен на 2Fc. Измеренные номиналы задаются отдельно для каждой физической детали.</p>
        <div class="result-layout">
            <div class="result-column">
                <div class="summary-grid">
                    <div class="metric"><span class="metric-label">Импеданс R</span><span class="metric-value calc-value"><?= $result !== null ? lpf_num($result['resistance']) . ' Ом' : '—' ?></span></div>
                    <div class="metric"><span class="metric-label">Исходная частота Fc</span><span class="metric-value calc-value"><?= $result !== null ? lpf_num($result['cutoff_mhz']) . ' МГц' : '—' ?></span></div>
                    <div class="metric"><span class="metric-label">Настройка Cдоп</span><span class="metric-value calc-value"><?= $result !== null ? lpf_num($result['resonance_mhz']) . ' МГц' : '—' ?></span></div>
                    <div class="metric"><span class="metric-label">Порядок фильтра</span><span class="metric-value" id="order-summary"><?= (int) $order ?>-й</span></div>
                </div>
            </div>
        </div>

        <?php
            $validOrder5 = $result !== null && $order === 5;
            $l5 = $validOrder5 ? $result['inductors_uh'] : [];
            $c5 = $validOrder5 ? $result['capacitors_pf'] : [];
            $validOrder7 = $result !== null && $order === 7;
            $l7 = $validOrder7 ? $result['inductors_uh'] : [];
            $c7 = $validOrder7 ? $result['capacitors_pf'] : [];
        ?>
        <div class="parts-list component-table" data-order="5"<?= $order === 5 ? '' : ' hidden' ?>>
            <h3>Номиналы компонентов — ФНЧ 5-го порядка</h3>
            <p class="manual-intro">Катушки и Cдоп собраны в левой колонке; конденсаторы основной схемы — в правой. Пустые поля «Измерено» используют расчётные номиналы.</p>
            <div class="component-columns">
                <div class="component-column coil-column">
                    <h4>Катушки и Cдоп</h4>
                    <?= lpf_manual_component_row('5_L1', 'L1', 'Первая последовательная катушка', $validOrder5 ? $l5[0] : null, 'мкГн', 'l', 0, $manualInputValues) ?>
                    <?= lpf_manual_component_row('5_L2', 'L2', 'Вторая катушка; параллельно подключён Cдоп', $validOrder5 ? $l5[1] : null, 'мкГн', 'l', 1, $manualInputValues) ?>
                    <?= lpf_manual_component_row('5_Cnotch', 'Cдоп', 'Параллельно L2; настройка на 2Fc', $validOrder5 ? $result['c_notch_pf'] : null, 'пФ', 'n', 0, $manualInputValues) ?>
                </div>
                <div class="component-column capacitor-column">
                    <h4>Конденсаторы на землю</h4>
                    <?= lpf_manual_component_row('5_C1', 'C1', 'Входной конденсатор на землю', $validOrder5 ? $c5[0] : null, 'пФ', 'c', 0, $manualInputValues) ?>
                    <?= lpf_manual_component_row('5_C2', 'C2', 'Средний конденсатор на землю', $validOrder5 ? $c5[1] : null, 'пФ', 'c', 1, $manualInputValues) ?>
                    <?= lpf_manual_component_row('5_C3', 'C3', 'Выходной конденсатор на землю', $validOrder5 ? $c5[2] : null, 'пФ', 'c', 2, $manualInputValues) ?>
                </div>
            </div>
        </div>

        <div class="parts-list component-table" data-order="7"<?= $order === 7 ? '' : ' hidden' ?>>
            <h3>Номиналы компонентов — ФНЧ 7-го порядка</h3>
            <p class="manual-intro">Катушки и Cдоп собраны в левой колонке; конденсаторы основной схемы — в правой. Пустые поля «Измерено» используют расчётные номиналы.</p>
            <div class="component-columns">
                <div class="component-column coil-column">
                    <h4>Катушки и Cдоп</h4>
                    <?= lpf_manual_component_row('7_L1', 'L1', 'Первая последовательная катушка', $validOrder7 ? $l7[0] : null, 'мкГн', 'l', 0, $manualInputValues) ?>
                    <?= lpf_manual_component_row('7_L2', 'L2', 'Средняя катушка; параллельно подключён Cдоп', $validOrder7 ? $l7[1] : null, 'мкГн', 'l', 1, $manualInputValues) ?>
                    <?= lpf_manual_component_row('7_L3', 'L3', 'Третья последовательная катушка', $validOrder7 ? $l7[2] : null, 'мкГн', 'l', 2, $manualInputValues) ?>
                    <?= lpf_manual_component_row('7_Cnotch', 'Cдоп', 'Параллельно L2; настройка на 2Fc', $validOrder7 ? $result['c_notch_pf'] : null, 'пФ', 'n', 0, $manualInputValues) ?>
                </div>
                <div class="component-column capacitor-column">
                    <h4>Конденсаторы на землю</h4>
                    <?= lpf_manual_component_row('7_C1', 'C1', 'Входной конденсатор на землю', $validOrder7 ? $c7[0] : null, 'пФ', 'c', 0, $manualInputValues) ?>
                    <?= lpf_manual_component_row('7_C2', 'C2', 'Первый внутренний конденсатор на землю', $validOrder7 ? $c7[1] : null, 'пФ', 'c', 1, $manualInputValues) ?>
                    <?= lpf_manual_component_row('7_C3', 'C3', 'Второй внутренний конденсатор на землю', $validOrder7 ? $c7[2] : null, 'пФ', 'c', 2, $manualInputValues) ?>
                    <?= lpf_manual_component_row('7_C4', 'C4', 'Выходной конденсатор на землю', $validOrder7 ? $c7[3] : null, 'пФ', 'c', 3, $manualInputValues) ?>
                </div>
            </div>
        </div>
        <p class="manual-instructions">Поля «Измерено» необязательны. Введённые значения влияют только на третью, зелёную кривую АЧХ. Для Cдоп можно ввести 0, чтобы полностью исключить дополнительный конденсатор из зелёной модели; пустое поле использует расчётный Cдоп. При выборе другой топологии ручные значения сохраняются отдельно для каждого порядка.</p>
        <p id="manual-status" class="error manual-status" role="alert" hidden></p>
        <div class="warning-note" id="model-note">Номиналы исходного фильтра не корректируются после подключения Cдоп. АЧХ рассчитывается для идеальных компонентов по полной электрической схеме.</div>
    </section>

    <section class="panel chart-panel">
        <h2>Амплитудно-частотная характеристика</h2>
        <p class="chart-subtitle">Синяя — исходный фильтр; оранжевая — рассчитанный фильтр с Cдоп; зелёная — фильтр с введёнными измеренными номиналами. Идеальная модель, без потерь.</p>
        <div class="legend" aria-label="Легенда графика">
            <span class="legend-item"><span class="legend-line"></span><span id="legend-base"><?= $response === 'butter' ? 'Исходный фильтр Баттерворта' : 'Исходный фильтр Чебышёва, 0,1 дБ' ?></span></span>
            <span class="legend-item"><span class="legend-line modified"></span>С расчётным Cдоп</span>
            <span class="legend-item"><span class="legend-line manual"></span>С измеренными номиналами</span>
        </div>
        <div class="chart-box">
            <canvas class="chart" id="responsePlot" aria-label="Расчётная АЧХ фильтра в децибелах в зависимости от частоты" role="img"<?= $chartData === null ? ' hidden' : '' ?>></canvas>
            <div class="chart-placeholder" id="chart-placeholder"<?= $chartData !== null ? ' hidden' : '' ?>>Введите импеданс и частоту, выберите характеристику и порядок фильтра, затем нажмите «Рассчитать».</div>
        </div>
        <p class="chart-stats" id="chart-stats" aria-live="polite"><?= $chartData !== null ? 'График рассчитывается по идеальной электрической модели фильтра.' : 'После расчёта появятся исходная АЧХ и характеристика с резонансным конденсатором. Зелёная кривая появится после ввода хотя бы одного измеренного значения.' ?></p>
        <?php if ($canExportCct): ?>
            <form id="export-cct-form" method="post" action="" class="no-print"<?= $initialExportValuesValid ? '' : ' hidden' ?>>
                <input type="hidden" name="export_cct" value="1">
                <input type="hidden" name="resistance" value="<?= lpf_h($resistanceRaw) ?>">
                <input type="hidden" name="cutoff" value="<?= lpf_h($cutoffRaw) ?>">
                <input type="hidden" name="response" value="<?= lpf_h($response) ?>">
                <input type="hidden" name="order" value="<?= lpf_h($orderChoice) ?>">
                <input type="hidden" name="manual_values_json" value="<?= lpf_h($manualValuesJson) ?>">
                <input type="hidden" name="client_timestamp" value="">
                <div class="action-row">
                    <button class="export-cct-button" type="submit">Скачать схему для RFSim99 (.cct)</button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Как выполняется расчёт</h2>
        <ol class="formula-list">
            <li>По выбранной характеристике рассчитываются нормированные коэффициенты Баттерворта либо Чебышёва с пульсацией 0,1 дБ.</li>
            <li>Коэффициенты масштабируются по заданным импедансу R и частоте Fc. Нечётные коэффициенты задают шунтирующие конденсаторы, чётные — последовательные катушки.</li>
            <li>Дополнительный конденсатор Cдоп подключается параллельно L2 и рассчитывается для резонанса на частоте 2Fc. Номиналы основной лестницы не корректируются.</li>
            <li>График вычисляется по полной идеальной цепи методом узловых напряжений. На частоте резонанса идеальный нуль передачи отображается у нижней границы графика (−100 дБ).</li>
        </ol>
    </section>
</main>
<script>
(() => {
    'use strict';
    const form = document.getElementById('lpf-form');
    const diagram = document.getElementById('filter-diagram');
    const diagramCaption = document.getElementById('diagram-caption');
    const componentTables = Array.from(document.querySelectorAll('.component-table'));
    const chart = document.getElementById('responsePlot');
    const chartPlaceholder = document.getElementById('chart-placeholder');
    const chartStats = document.getElementById('chart-stats');
    const staleHint = document.getElementById('stale-hint');
    const orderSummary = document.getElementById('order-summary');
    const legendBase = document.getElementById('legend-base');
    const manualInputs = Array.from(document.querySelectorAll('.manual-nominal'));
    const manualValuesHidden = document.getElementById('manual_values_json');
    const manualStatus = document.getElementById('manual-status');
    const exportForm = document.getElementById('export-cct-form');
    const exportSupported = <?= $canExportCct ? 'true' : 'false' ?>;
    const initialChartData = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION) ?>;
    const shouldScrollToResults = <?= ($isSubmitted && $result !== null) ? 'true' : 'false' ?>;
    const shouldScrollToError = <?= ($isSubmitted && $error !== '') ? 'true' : 'false' ?>;
    let currentChartData = initialChartData;
    let stale = false;

    function selectedValue(name) {
        const control = form.elements.namedItem(name);
        return control ? control.value : (name === 'order' ? '5' : 'butter');
    }

    function updateDiagram() {
        const order = selectedValue('order');
        const isFive = order === '5';
        diagram.src = isFive ? 'images/lpf-5th-shunt.svg' : 'images/lpf-7th-shunt.svg';
        diagram.alt = isFive
            ? 'ФНЧ пятого порядка: две последовательные катушки, три шунтирующих конденсатора и Cдоп параллельно L2'
            : 'ФНЧ седьмого порядка: три последовательные катушки, четыре шунтирующих конденсатора и Cдоп параллельно L2';
        diagramCaption.textContent = (isFive
            ? 'ФНЧ 5-го порядка: 2 последовательные катушки и 3 конденсатора на землю.'
            : 'ФНЧ 7-го порядка: 3 последовательные катушки и 4 конденсатора на землю.') + ' Cдоп подключён параллельно L2.';
        orderSummary.textContent = order + '-й';
        componentTables.forEach(table => { table.hidden = table.dataset.order !== order; });
        const response = selectedValue('response');
        legendBase.textContent = response === 'butter' ? 'Исходный фильтр Баттерворта' : 'Исходный фильтр Чебышёва, 0,1 дБ';
    }

    function markStale() {
        if (!initialChartData || stale) return;
        stale = true;
        document.querySelectorAll('.calc-value').forEach(node => { node.textContent = '—'; });
        currentChartData = null;
        if (exportForm) exportForm.hidden = true;
        chart.hidden = true;
        chartPlaceholder.hidden = false;
        if (manualStatus) { manualStatus.hidden = true; manualStatus.textContent = ''; }
        manualInputs.forEach(input => input.classList.remove('invalid'));
        chartStats.textContent = 'Параметры изменены. Нажмите «Рассчитать», чтобы обновить номиналы и АЧХ.';
        staleHint.style.display = 'block';
    }

    form.querySelectorAll('input:not([type="hidden"]), select').forEach(control => {
        control.addEventListener('input', markStale);
        control.addEventListener('change', () => { updateDiagram(); markStale(); });
    });

    // Комплексные числа представлены парой [Re, Im].
    const z = (re, im = 0) => [re, im];
    const add = (a, b) => [a[0] + b[0], a[1] + b[1]];
    const sub = (a, b) => [a[0] - b[0], a[1] - b[1]];
    const mul = (a, b) => [a[0] * b[0] - a[1] * b[1], a[0] * b[1] + a[1] * b[0]];
    function div(a, b) {
        const d = b[0] * b[0] + b[1] * b[1];
        if (d === 0) return [0, 0];
        return [(a[0] * b[0] + a[1] * b[1]) / d, (a[1] * b[0] - a[0] * b[1]) / d];
    }
    const abs = a => Math.hypot(a[0], a[1]);

    // Узловой расчёт идеальной цепи. Источник и нагрузка имеют сопротивление R.
    function transferDb(data, frequencyHz, includeNotch) {
        const R = data.resistance;
        const Ls = data.inductors_h;
        const Cs = data.capacitors_f;
        const nodeCount = Cs.length; // Все узлы лестницы, включая входной и выходной, имеют шунтирующие C.
        if (!(frequencyHz > 0)) return 0;
        const w = 2 * Math.PI * frequencyHz;
        const A = Array.from({length: nodeCount}, () => Array.from({length: nodeCount}, () => z(0, 0)));
        const b = Array.from({length: nodeCount}, () => z(0, 0));
        A[0][0] = add(A[0][0], z(1 / R));
        b[0] = z(1 / R); // Источник Тевенина принят равным 1 В.
        A[nodeCount - 1][nodeCount - 1] = add(A[nodeCount - 1][nodeCount - 1], z(1 / R));

        function addBranch(i, j, y) {
            A[i][i] = add(A[i][i], y);
            A[j][j] = add(A[j][j], y);
            A[i][j] = sub(A[i][j], y);
            A[j][i] = sub(A[j][i], y);
        }

        for (let k = 0; k < Ls.length; k++) {
            // Y_L = 1/(j*w*L) = -j/(w*L). На L2 добавляем проводимость Cдоп.
            let susceptance = -1 / (w * Ls[k]);
            if (includeNotch && k === 1) {
                const relativeResonance = w * w * Ls[k] * data.c_notch_f;
                if (Math.abs(relativeResonance - 1) < 1e-12) {
                    susceptance = 0; // Идеальный параллельный резонанс: ветвь разомкнута.
                } else {
                    susceptance += w * data.c_notch_f;
                }
            }
            addBranch(k, k + 1, z(0, susceptance));
        }
        for (let k = 0; k < Cs.length; k++) {
            A[k][k] = add(A[k][k], z(0, w * Cs[k]));
        }

        // Комплексное исключение Гаусса с частичным выбором ведущего элемента.
        for (let col = 0; col < nodeCount; col++) {
            let pivot = col;
            for (let row = col + 1; row < nodeCount; row++) {
                if (abs(A[row][col]) > abs(A[pivot][col])) pivot = row;
            }
            if (abs(A[pivot][col]) < 1e-30) return -120;
            if (pivot !== col) {
                [A[pivot], A[col]] = [A[col], A[pivot]];
                [b[pivot], b[col]] = [b[col], b[pivot]];
            }
            for (let row = col + 1; row < nodeCount; row++) {
                const factor = div(A[row][col], A[col][col]);
                for (let j = col; j < nodeCount; j++) A[row][j] = sub(A[row][j], mul(factor, A[col][j]));
                b[row] = sub(b[row], mul(factor, b[col]));
            }
        }
        const v = Array.from({length: nodeCount}, () => z(0, 0));
        for (let row = nodeCount - 1; row >= 0; row--) {
            let sum = b[row];
            for (let col = row + 1; col < nodeCount; col++) sum = sub(sum, mul(A[row][col], v[col]));
            v[row] = div(sum, A[row][row]);
        }
        // Нормируем к 0 дБ на низкой частоте: без реактивных падений Vout/Vs=1/2.
        const magnitude = 2 * abs(v[nodeCount - 1]);
        if (!Number.isFinite(magnitude) || magnitude <= 0) return -120;
        return 20 * Math.log10(magnitude);
    }

    function parseManualNumber(raw, allowZero = false) {
        const normalized = String(raw).replace(/[\s\u00a0\u202f]/g, '').replace(',', '.');
        if (normalized === '') return {empty: true, valid: true, value: null};
        const decimalNumber = /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i;
        if (!decimalNumber.test(normalized)) return {empty: false, valid: false, value: null};
        const value = Number(normalized);
        const inRange = allowZero ? value >= 0 : value > 0;
        return {empty: false, valid: Number.isFinite(value) && inRange, value};
    }

    function getManualChartData() {
        if (!currentChartData) return {valid: false, changed: false, data: null};
        const data = {
            ...currentChartData,
            inductors_h: currentChartData.inductors_h.slice(),
            capacitors_f: currentChartData.capacitors_f.slice()
        };
        let changed = false;
        let invalid = false;
        manualInputs.forEach(input => input.classList.remove('invalid'));
        const visibleInputs = manualInputs.filter(input => {
            const table = input.closest('.component-table');
            return table && !table.hidden;
        });
        for (const input of visibleInputs) {
            const kind = input.dataset.kind;
            const parsed = parseManualNumber(input.value, kind === 'n');
            if (parsed.empty) continue;
            changed = true;
            if (!parsed.valid) {
                input.classList.add('invalid');
                invalid = true;
                continue;
            }
            const index = Number(input.dataset.index);
            if (kind === 'l' && index >= 0 && index < data.inductors_h.length) {
                data.inductors_h[index] = parsed.value * 1e-6;
            } else if (kind === 'c' && index >= 0 && index < data.capacitors_f.length) {
                data.capacitors_f[index] = parsed.value * 1e-12;
            } else if (kind === 'n') {
                data.c_notch_f = parsed.value * 1e-12;
            }
        }
        if (manualStatus) {
            manualStatus.hidden = !invalid;
            manualStatus.textContent = invalid
                ? 'Для L и основных C введите положительные числа. Для Cдоп допускается 0 — он отключит дополнительный конденсатор в зелёной модели. Можно использовать запятую или точку; исправьте выделенные поля.'
                : '';
        }
        return {valid: !invalid, changed, data};
    }

    function collectManualValues() {
        const values = {};
        manualInputs.forEach(input => {
            const value = input.value.trim();
            if (value !== '') values[input.dataset.key] = value;
        });
        return values;
    }

    function syncManualValuesForSubmit() {
        if (manualValuesHidden) manualValuesHidden.value = JSON.stringify(collectManualValues());
    }

    function updateExportVisibility(manualModel) {
        if (!exportForm) return;
        exportForm.hidden = !(exportSupported && !stale && currentChartData && manualModel && manualModel.valid);
    }

    function localTimestampForFilename() {
        // Используем локальные часы компьютера пользователя, а не часовой пояс сервера.
        const now = new Date();
        const pad = value => String(value).padStart(2, '0');
        return String(now.getFullYear()).padStart(4, '0')
            + pad(now.getMonth() + 1)
            + pad(now.getDate())
            + '_'
            + pad(now.getHours())
            + pad(now.getMinutes())
            + pad(now.getSeconds());
    }

    function syncExportForm() {
        if (!exportForm) return;
        exportForm.querySelector('input[name="resistance"]').value = form.elements.resistance.value;
        exportForm.querySelector('input[name="cutoff"]').value = form.elements.cutoff.value;
        exportForm.querySelector('input[name="response"]').value = selectedValue('response');
        exportForm.querySelector('input[name="order"]').value = selectedValue('order');
        exportForm.querySelector('input[name="manual_values_json"]').value = JSON.stringify(collectManualValues());
        exportForm.querySelector('input[name="client_timestamp"]').value = localTimestampForFilename();
    }

    form.addEventListener('submit', syncManualValuesForSubmit);
    if (exportForm) {
        exportForm.addEventListener('submit', event => {
            const manualModel = getManualChartData();
            updateExportVisibility(manualModel);
            if (!exportSupported || stale || !currentChartData || !manualModel.valid) {
                event.preventDefault();
                return;
            }
            syncExportForm();
        });
    }
    manualInputs.forEach(input => {
        input.addEventListener('input', () => {
            if (currentChartData) drawPlot();
        });
        input.addEventListener('change', () => {
            if (currentChartData) drawPlot();
        });
    });

    function formatRu(value, digits = 2) {
        return value.toLocaleString('ru-RU', {minimumFractionDigits: digits, maximumFractionDigits: digits});
    }

    function drawPlot() {
        if (!currentChartData) return;
        chart.hidden = false;
        chartPlaceholder.hidden = true;
        const cssWidth = Math.max(300, chart.clientWidth);
        const cssHeight = Math.max(280, chart.clientHeight);
        const dpr = Math.max(1, window.devicePixelRatio || 1);
        chart.width = Math.round(cssWidth * dpr);
        chart.height = Math.round(cssHeight * dpr);
        const ctx = chart.getContext('2d');
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, cssWidth, cssHeight);
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, cssWidth, cssHeight);
        const left = 63, right = 17, top = 25, bottom = 64;
        const plotW = cssWidth - left - right;
        const plotH = cssHeight - top - bottom;
        const yMin = -100, yMax = 0.5;
        const xMap = x => left + x / 4 * plotW;
        const yMap = y => top + (yMax - y) / (yMax - yMin) * plotH;

        ctx.font = '12px Arial, Helvetica, sans-serif';
        ctx.lineWidth = 1;
        for (let db = 0; db >= -100; db -= 10) {
            const y = yMap(db);
            ctx.strokeStyle = '#e7ebef';
            ctx.beginPath(); ctx.moveTo(left, y); ctx.lineTo(left + plotW, y); ctx.stroke();
            ctx.fillStyle = '#5f6368'; ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
            ctx.fillText(String(db), left - 9, y);
        }
        // Промежуточные деления и подписи по частоте каждые 0,5 Fc.
        // Подписи чередуются в две строки, чтобы оставаться читаемыми и на смартфоне.
        for (let i = 0; i <= 8; i++) {
            const ratio = i / 2;
            const x = xMap(ratio);
            const major = Number.isInteger(ratio);
            ctx.strokeStyle = major ? '#e3e8ed' : '#f0f3f6';
            ctx.lineWidth = major ? 1 : 0.8;
            ctx.beginPath(); ctx.moveTo(x, top); ctx.lineTo(x, top + plotH); ctx.stroke();
            ctx.strokeStyle = '#9ba5ae';
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(x, top + plotH); ctx.lineTo(x, top + plotH + 4); ctx.stroke();
            const frequencyMHz = ratio * currentChartData.cutoff_hz / 1e6;
            const labelY = top + plotH + (i % 2 === 0 ? 6 : 22);
            ctx.fillStyle = '#5f6368'; ctx.textAlign = 'center'; ctx.textBaseline = 'top';
            ctx.fillText(frequencyMHz.toLocaleString('ru-RU', {maximumFractionDigits: 2}), x, labelY);
        }
        [[1, '#245c88', 'Fc'], [2, '#c25b24', '2Fc']].forEach(([ratio, color, label]) => {
            const x = xMap(ratio);
            ctx.save(); ctx.strokeStyle = color; ctx.globalAlpha = 0.75; ctx.setLineDash([5, 4]);
            ctx.beginPath(); ctx.moveTo(x, top); ctx.lineTo(x, top + plotH); ctx.stroke(); ctx.restore();
            ctx.fillStyle = color; ctx.textAlign = 'center'; ctx.textBaseline = 'bottom';
            ctx.fillText(label, x, top - 4);
        });

        // Равномерная сетка плюс дополнительные точки вокруг 2Fc: провал определяется сетью, не рисуется искусственно.
        const ratios = [];
        const total = 1200;
        for (let i = 0; i <= total; i++) ratios.push(4 * i / total);
        for (const d of [0.2, 0.1, 0.05, 0.02, 0.01, 0.005, 0.002, 0.001, 0.0005, 0.0002, 0.0001, 0.00005, 0.00002, 0.00001, 0.000001, 1e-7, 1e-8, 1e-9]) {
            ratios.push(2 - d, 2 + d);
        }
        ratios.push(2);
        ratios.sort((a, b) => a - b);
        const sampled = ratios.filter((v, i) => i === 0 || v - ratios[i - 1] > 1e-13);

        function plotCurve(data, includeNotch, color) {
            ctx.beginPath(); ctx.strokeStyle = color; ctx.lineWidth = 2.35; ctx.lineJoin = 'round'; ctx.lineCap = 'round';
            sampled.forEach((ratio, index) => {
                const f = ratio === 0 ? 0 : ratio * data.cutoff_hz;
                const db = Math.max(yMin, Math.min(yMax, transferDb(data, f, includeNotch)));
                const x = xMap(ratio), y = yMap(db);
                if (index === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
            });
            ctx.stroke();
        }
        const manualModel = getManualChartData();
        updateExportVisibility(manualModel);
        if (manualModel.valid && manualModel.changed) plotCurve(manualModel.data, true, '#20835b');
        plotCurve(currentChartData, false, '#245c88');
        plotCurve(currentChartData, true, '#c25b24');

        ctx.strokeStyle = '#9ba5ae'; ctx.lineWidth = 1.2; ctx.strokeRect(left, top, plotW, plotH);
        ctx.fillStyle = '#202124'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
        ctx.font = '13px Arial, Helvetica, sans-serif'; ctx.fillText('Частота, МГц', left + plotW / 2, cssHeight - 8);
        ctx.save(); ctx.translate(16, top + plotH / 2); ctx.rotate(-Math.PI / 2); ctx.fillText('Относительная передача, дБ', 0, 0); ctx.restore();

        const fcBase = transferDb(currentChartData, currentChartData.cutoff_hz, false);
        const fcNotched = transferDb(currentChartData, currentChartData.cutoff_hz, true);
        const secondBase = transferDb(currentChartData, 2 * currentChartData.cutoff_hz, false);
        const secondNotched = transferDb(currentChartData, 2 * currentChartData.cutoff_hz, true);
        const fcMeaning = currentChartData.response === 'butter' ? 'Fc (−3 дБ до Cдоп)' : 'Fc (край пульсаций 0,1 дБ до Cдоп)';
        const notchText = secondNotched <= -99.9 ? '≤ −100 дБ — идеальный нуль передачи' : formatRu(secondNotched, 2) + ' дБ';
        let stats = fcMeaning + ': без Cдоп ' + formatRu(fcBase, 2) + ' дБ, с Cдоп ' + formatRu(fcNotched, 2) + ' дБ. На 2Fc: без Cдоп ' + formatRu(secondBase, 2) + ' дБ, с Cдоп ' + notchText + '. График ограничен снизу уровнем −100 дБ.';
        if (manualModel.valid && manualModel.changed) {
            const manualSecond = transferDb(manualModel.data, 2 * currentChartData.cutoff_hz, true);
            const manualNotchText = manualSecond <= -99.9 ? '≤ −100 дБ' : formatRu(manualSecond, 2) + ' дБ';
            if (manualModel.data.c_notch_f === 0) {
                stats += ' Зелёная кривая: введённые номиналы без Cдоп (для него задано 0). Пустые поля используют расчётные значения.';
            } else {
                stats += ' С введёнными номиналами на 2Fc: ' + manualNotchText + '. Пустые поля используют расчётные значения.';
            }
        } else if (!manualModel.changed) {
            stats += ' Для зелёной кривой введите хотя бы один измеренный номинал.';
        }
        chartStats.textContent = stats;
    }

    updateDiagram();
    if (currentChartData) {
        drawPlot();
        window.addEventListener('resize', drawPlot);
    }

    // После отправки формы перемещаем страницу к полезному результату, а не в случайную позицию.
    // Первичное открытие без отправки формы не прокручивается.
    const scrollTarget = shouldScrollToResults
        ? document.getElementById('results-section')
        : (shouldScrollToError ? document.getElementById('form-error') : null);
    if (scrollTarget) {
        requestAnimationFrame(() => requestAnimationFrame(() => {
            scrollTarget.scrollIntoView({behavior: 'auto', block: 'start'});
        }));
    }
})();
</script>
</body>
</html>
