<?php
/*
 * Калькулятор трёхзвенного полосового LC-фильтра (ДПФ).
 * Два независимых расчёта: последовательная и параллельная топологии.
 * Формулы сохранены по исходным таблицам «3 звена.ods» и «3 звена2.ods».
 */
declare(strict_types=1);

require_once __DIR__ . '/cct_frequency_axis.php';

function dpf3_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dpf3_number(string $raw): ?float
{
    $raw = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($raw));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }

    $number = (float) $raw;
    return ($number > 0.0 && is_finite($number)) ? $number : null;
}

function dpf3_num(?float $value, int $decimals = 6): string
{
    if ($value === null || !is_finite($value)) {
        return 'не определено';
    }
    if ($value !== 0.0 && (abs($value) >= 1e12 || abs($value) < 1e-5)) {
        return str_replace('.', ',', sprintf('%.6g', $value));
    }

    $formatted = number_format($value, $decimals, ',', '');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    return ($formatted === '' || $formatted === '-0') ? '0' : $formatted;
}

function dpf3_division(float $numerator, float $denominator): ?float
{
    if ($denominator == 0.0 || !is_finite($numerator) || !is_finite($denominator)) {
        return null;
    }

    $value = $numerator / $denominator;
    return is_finite($value) ? $value : null;
}

/**
 * Восстанавливает ранее рассчитанные значения из скрытого поля формы.
 * Принимаются только известные ключи и конечные числовые значения.
 */
function dpf3_restore_state(string $raw, array $numberKeys): ?array
{
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $state = [];
    foreach ($numberKeys as $key) {
        $value = $decoded[$key] ?? null;
        if ($value === null) {
            $state[$key] = null;
            continue;
        }
        if (!is_numeric($value) || !is_finite((float) $value)) {
            return null;
        }
        $state[$key] = (float) $value;
    }

    foreach (['negative', 'undefined', 'coefficient_undefined'] as $flag) {
        $state[$flag] = !empty($decoded[$flag]);
    }

    return $state;
}

function dpf3_state_json(?array $state): string
{
    if ($state === null) {
        return '';
    }
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    return $json === false ? '' : $json;
}

function dpf3_output_value(?array $result, string $key): string
{
    if ($result === null) {
        return '—';
    }
    return dpf3_num(isset($result[$key]) ? (float) $result[$key] : null);
}

function dpf3_calculate_sequential(float $resistance, float $frequency, string $mode, float $value): array
{
    // Единицы исходной таблицы: R — Ом, Fc — МГц, ΔF — кГц, L1 — мкГн, C — пФ.
    if ($mode === 'bandwidth') {
        $deltaF = $value;
        $l1 = dpf3_division($resistance * 1000.0, 2.0 * M_PI * $deltaF);
    } else {
        $l1 = $value;
        $deltaF = dpf3_division($resistance * 1000.0, 2.0 * M_PI * $l1);
    }

    if ($l1 === null || $deltaF === null || $l1 <= 0.0 || $deltaF <= 0.0) {
        return ['error' => 'Не удалось получить конечные значения индуктивности или полосы. Проверьте исходные параметры.'];
    }

    // Формулы последовательной топологии из «3 звена.ods».
    $common = dpf3_division(1000000.0, 2.0 * M_PI * $frequency * $resistance);
    $ratio = ($frequency * 1000.0) / $deltaF;
    $c1 = $common === null ? null : dpf3_division($common, $ratio - (1.0 / sqrt(2.0)));
    $c2 = $common === null ? null : dpf3_division($common, $ratio - sqrt(2.0));
    $c3 = dpf3_division(1000000.0 * sqrt(2.0), 2.0 * M_PI * $frequency * $resistance);

    $negative = (($c1 !== null && $c1 < 0.0)
        || ($c2 !== null && $c2 < 0.0)
        || ($c3 !== null && $c3 < 0.0));
    $undefined = ($c1 === null || $c2 === null || $c3 === null);

    return [
        'l1' => $l1,
        'c1' => $c1,
        'c2' => $c2,
        'c3' => $c3,
        'f1' => $frequency - $deltaF / 2000.0,
        'f2' => $frequency + $deltaF / 2000.0,
        'negative' => $negative,
        'undefined' => $undefined,
        'coefficient_undefined' => false,
    ];
}

function dpf3_calculate_parallel(float $resistance, float $frequency, float $deltaF, float $l1): array
{
    // Формулы параллельной топологии перенесены из «3 звена2.ods».
    // R'' и Q используются только внутри вычислений и не выводятся пользователю.
    $rDouble = 2.0 * M_PI * $l1 * $deltaF / 1000.0;
    $ratio = ($frequency * 1000.0) / $deltaF;
    $common = dpf3_division(1000000.0, 2.0 * M_PI * $frequency * $rDouble);

    $qRadicand = ($resistance / $rDouble) - 1.0;
    $qUndefined = ($qRadicand <= 0.0 || !is_finite($qRadicand));
    $q = $qUndefined ? null : sqrt($qRadicand);

    $c1 = ($q === null)
        ? null
        : dpf3_division(1000000.0 * $q, 2.0 * M_PI * $frequency * $resistance);

    // Cs — промежуточная величина из исходной таблицы, не компонент схемы.
    $cs = ($q !== null && $c1 !== null && $q > 0.0)
        ? -$c1 * ((1.0 / ($q * $q)) + 1.0)
        : null;

    $c2DoublePrime = $common === null
        ? null
        : dpf3_division($common, $ratio - (1.0 / sqrt(2.0)));
    $c2InverseSum = ($c2DoublePrime !== null && $c2DoublePrime != 0.0 && $cs !== null && $cs != 0.0)
        ? (1.0 / $c2DoublePrime) + (1.0 / $cs)
        : null;
    $c2 = $c2InverseSum === null ? null : dpf3_division(1.0, $c2InverseSum);

    $c3 = $common === null
        ? null
        : dpf3_division($common, $ratio - sqrt(2.0));
    $c4 = dpf3_division(1000000.0 * sqrt(2.0), 2.0 * M_PI * $frequency * $rDouble);

    $negative = (($c1 !== null && $c1 < 0.0)
        || ($c2 !== null && $c2 < 0.0)
        || ($c3 !== null && $c3 < 0.0)
        || ($c4 !== null && $c4 < 0.0));
    $undefined = ($c1 === null || $c2 === null || $c3 === null || $c4 === null);

    return [
        'l1' => $l1,
        'c1' => $c1,
        'c2' => $c2,
        'c3' => $c3,
        'c4' => $c4,
        'negative' => $negative,
        'undefined' => $undefined,
        'coefficient_undefined' => $qUndefined,
    ];
}


/**
 * Формат числа RFSim99: мантисса и четырёхзначный показатель степени.
 * Значения передаются в СИ: Ф, Гн, Ом, Гц.
 */
function dpf3_cct_scientific(float $value): string
{
    if (!is_finite($value)) {
        throw new RuntimeException('В CCT нельзя записать бесконечное или неопределённое значение.');
    }

    $formatted = sprintf('%.14E', $value);
    $padded = preg_replace_callback(
        '/E([+-])(\d+)$/',
        static fn(array $match): string => 'E' . $match[1] . str_pad($match[2], 4, '0', STR_PAD_LEFT),
        $formatted
    );

    if (!is_string($padded)) {
        throw new RuntimeException('Не удалось отформатировать номинал для RFSim99.');
    }
    return $padded;
}

/**
 * Создаёт CCT для последовательной топологии из неизменяемого шаблона.
 * Меняются только номиналы компонентов по координатам, Zo портов и Xax min/max.
 */
function dpf3_build_sequential_cct(
    array $result,
    float $resistance,
    float $frequencyMHz,
    float $bandwidthKHz
): string {
    $templatePath = __DIR__ . '/templates/BPF3_SER.cct';
    if (!is_file($templatePath) || !is_readable($templatePath)) {
        throw new RuntimeException('Не найден шаблон /calculators/templates/BPF3_SER.cct или нет доступа на чтение.');
    }

    $template = file_get_contents($templatePath);
    if (!is_string($template) || $template === '') {
        throw new RuntimeException('Не удалось прочитать шаблон BPF3_SER.cct.');
    }

    $componentMap = [
        '120,130' => ['key' => 'c1', 'ctype' => '2', 'scale' => 1e-12],
        '580,130' => ['key' => 'c1', 'ctype' => '2', 'scale' => 1e-12],
        '390,130' => ['key' => 'c2', 'ctype' => '2', 'scale' => 1e-12],
        '250,170' => ['key' => 'c3', 'ctype' => '2', 'scale' => 1e-12],
        '440,170' => ['key' => 'c3', 'ctype' => '2', 'scale' => 1e-12],
        '200,130' => ['key' => 'l1', 'ctype' => '3', 'scale' => 1e-6],
        '320,130' => ['key' => 'l1', 'ctype' => '3', 'scale' => 1e-6],
        '500,130' => ['key' => 'l1', 'ctype' => '3', 'scale' => 1e-6],
    ];
    $componentCounts = array_fill_keys(['c1', 'c2', 'c3', 'l1'], 0);
    $expectedCounts = ['c1' => 2, 'c2' => 1, 'c3' => 2, 'l1' => 3];

    $drawdatPosition = strpos($template, 'drawdat{');
    if ($drawdatPosition === false) {
        throw new RuntimeException('В шаблоне не найден блок drawdat.');
    }
    $prefix = substr($template, 0, $drawdatPosition);
    $drawdat = substr($template, $drawdatPosition);

    // Сначала меняем только две границы частотного окна, сохраняя остальные настройки Xax.
    $minHz = ($frequencyMHz - 2.5 * ($bandwidthKHz / 1000.0)) * 1e6;
    $maxHz = ($frequencyMHz + 2.5 * ($bandwidthKHz / 1000.0)) * 1e6;
    [$minHz, $maxHz] = cct_frequency_axis_bounds($minHz, $maxHz);
    if (!is_finite($minHz) || !is_finite($maxHz) || $minHz <= 0.0 || $maxHz <= $minHz) {
        throw new RuntimeException('Для заданных параметров получился недопустимый диапазон отображения АЧХ.');
    }

    $xaxCount = 0;
    $prefix = preg_replace_callback(
        '/Xax\{([\s\S]*?)(\r?\n)\}/',
        static function (array $match) use ($minHz, $maxHz, &$xaxCount): string {
            $block = $match[1];
            $eol = $match[2];
            foreach (['max' => $maxHz, 'min' => $minHz] as $field => $value) {
                $count = 0;
                $block = preg_replace_callback(
                    '/^(' . preg_quote($field, '/') . '=\s*)[^\r\n]*/m',
                    static fn(array $line): string => $line[1] . dpf3_cct_scientific($value),
                    $block,
                    1,
                    $count
                );
                if (!is_string($block) || $count !== 1) {
                    throw new RuntimeException('В блоке Xax не удалось однозначно заменить параметр ' . $field . '.');
                }
            }
            $xaxCount++;
            return 'Xax{' . $block . $eol . '}';
        },
        $prefix,
        -1,
        $xaxMatches
    );
    if (!is_string($prefix) || $xaxMatches !== 1 || $xaxCount !== 1) {
        throw new RuntimeException('В шаблоне должен находиться ровно один блок Xax.');
    }

    $drawdat = preg_replace_callback(
        '/component\{([\s\S]*?)(\r?\n)\}/',
        static function (array $match) use (&$componentCounts, $componentMap, $result): string {
            $block = $match[1];
            $eol = $match[2];

            if (!preg_match('/^ctype=(\d+)\s*$/m', $block, $ctypeMatch)
                || !preg_match('/^position\.x=(\d+)\s*$/m', $block, $xMatch)
                || !preg_match('/^position\.y=(\d+)\s*$/m', $block, $yMatch)) {
                return $match[0];
            }

            $coordinate = $xMatch[1] . ',' . $yMatch[1];
            if (!isset($componentMap[$coordinate])) {
                return $match[0];
            }

            $mapping = $componentMap[$coordinate];
            if ($ctypeMatch[1] !== $mapping['ctype']) {
                throw new RuntimeException('Тип компонента в координатах ' . $coordinate . ' не совпадает с согласованным шаблоном.');
            }

            $key = $mapping['key'];
            $value = $result[$key] ?? null;
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0.0) {
                throw new RuntimeException('Для элемента ' . strtoupper($key) . ' нет положительного конечного номинала.');
            }
            $nominalSi = (float) $value * $mapping['scale'];
            $nominalText = dpf3_cct_scientific($nominalSi);

            $updated = preg_replace_callback(
                '/(value\[1\]=\{nom=\s*)[^;]+/',
                static fn(array $nominalMatch): string => $nominalMatch[1] . $nominalText,
                $block,
                1,
                $nominalCount
            );
            if (!is_string($updated) || $nominalCount !== 1) {
                throw new RuntimeException('Не удалось заменить номинал компонента в координатах ' . $coordinate . '.');
            }

            $componentCounts[$key]++;
            return 'component{' . $updated . $eol . '}';
        },
        $drawdat,
        -1,
        $componentMatches
    );
    if (!is_string($drawdat) || $componentMatches < 1) {
        throw new RuntimeException('Не удалось обработать компоненты в блоке drawdat.');
    }
    foreach ($expectedCounts as $key => $expected) {
        if ($componentCounts[$key] !== $expected) {
            throw new RuntimeException('В шаблоне найдено неожиданное количество компонентов ' . strtoupper($key) . '.');
        }
    }

    $portCounts = ['1' => 0, '2' => 0];
    $drawdat = preg_replace_callback(
        '/P([12])\{([\s\S]*?)(\r?\n)\}/',
        static function (array $match) use (&$portCounts, $resistance): string {
            $port = $match[1];
            $block = $match[2];
            $eol = $match[3];
            $updated = preg_replace_callback(
                '/(^Zo=\s*)[^\r\n]*/m',
                static fn(array $zoMatch): string => $zoMatch[1] . dpf3_cct_scientific($resistance),
                $block,
                1,
                $zoCount
            );
            if (!is_string($updated) || $zoCount !== 1) {
                throw new RuntimeException('Не удалось изменить импеданс порта P' . $port . '.');
            }
            $portCounts[$port]++;
            return 'P' . $port . '{' . $updated . $eol . '}';
        },
        $drawdat,
        -1,
        $portMatches
    );
    if (!is_string($drawdat) || $portMatches !== 2 || $portCounts !== ['1' => 1, '2' => 1]) {
        throw new RuntimeException('В шаблоне должны находиться ровно по одному порту P1 и P2.');
    }

    return $prefix . $drawdat;
}



/**
 * Создаёт CCT для параллельной топологии из неизменяемого шаблона.
 * Меняются только номиналы компонентов по координатам, Zo портов и Xax min/max.
 */
function dpf3_build_parallel_cct(
    array $result,
    float $resistance,
    float $frequencyMHz,
    float $bandwidthKHz
): string {
    $templatePath = __DIR__ . '/templates/BPF3_PAR.cct';
    if (!is_file($templatePath) || !is_readable($templatePath)) {
        throw new RuntimeException('Не найден шаблон /calculators/templates/BPF3_PAR.cct или нет доступа на чтение.');
    }

    $template = file_get_contents($templatePath);
    if (!is_string($template) || $template === '') {
        throw new RuntimeException('Не удалось прочитать шаблон BPF3_PAR.cct.');
    }

    $componentMap = [
        '100,170' => ['key' => 'c1', 'ctype' => '2', 'scale' => 1e-12],
        '690,170' => ['key' => 'c1', 'ctype' => '2', 'scale' => 1e-12],
        '170,130' => ['key' => 'c2', 'ctype' => '2', 'scale' => 1e-12],
        '630,130' => ['key' => 'c2', 'ctype' => '2', 'scale' => 1e-12],
        '440,130' => ['key' => 'c3', 'ctype' => '2', 'scale' => 1e-12],
        '300,170' => ['key' => 'c4', 'ctype' => '2', 'scale' => 1e-12],
        '490,170' => ['key' => 'c4', 'ctype' => '2', 'scale' => 1e-12],
        '250,130' => ['key' => 'l1', 'ctype' => '3', 'scale' => 1e-6],
        '370,130' => ['key' => 'l1', 'ctype' => '3', 'scale' => 1e-6],
        '550,130' => ['key' => 'l1', 'ctype' => '3', 'scale' => 1e-6],
    ];
    $componentCounts = array_fill_keys(['c1', 'c2', 'c3', 'c4', 'l1'], 0);
    $expectedCounts = ['c1' => 2, 'c2' => 2, 'c3' => 1, 'c4' => 2, 'l1' => 3];

    $drawdatPosition = strpos($template, 'drawdat{');
    if ($drawdatPosition === false) {
        throw new RuntimeException('В шаблоне не найден блок drawdat.');
    }
    $prefix = substr($template, 0, $drawdatPosition);
    $drawdat = substr($template, $drawdatPosition);

    // Границы частотного окна задаются симметрично относительно центральной частоты.
    $minHz = ($frequencyMHz - 2.5 * ($bandwidthKHz / 1000.0)) * 1e6;
    $maxHz = ($frequencyMHz + 2.5 * ($bandwidthKHz / 1000.0)) * 1e6;
    [$minHz, $maxHz] = cct_frequency_axis_bounds($minHz, $maxHz);
    if (!is_finite($minHz) || !is_finite($maxHz) || $minHz <= 0.0 || $maxHz <= $minHz) {
        throw new RuntimeException('Для заданных параметров получился недопустимый диапазон отображения АЧХ.');
    }

    $xaxCount = 0;
    $prefix = preg_replace_callback(
        '/Xax\{([\s\S]*?)(\r?\n)\}/',
        static function (array $match) use ($minHz, $maxHz, &$xaxCount): string {
            $block = $match[1];
            $eol = $match[2];
            foreach (['max' => $maxHz, 'min' => $minHz] as $field => $value) {
                $count = 0;
                $block = preg_replace_callback(
                    '/^(' . preg_quote($field, '/') . '=\s*)[^\r\n]*/m',
                    static fn(array $line): string => $line[1] . dpf3_cct_scientific($value),
                    $block,
                    1,
                    $count
                );
                if (!is_string($block) || $count !== 1) {
                    throw new RuntimeException('В блоке Xax не удалось однозначно заменить параметр ' . $field . '.');
                }
            }
            $xaxCount++;
            return 'Xax{' . $block . $eol . '}';
        },
        $prefix,
        -1,
        $xaxMatches
    );
    if (!is_string($prefix) || $xaxMatches !== 1 || $xaxCount !== 1) {
        throw new RuntimeException('В шаблоне должен находиться ровно один блок Xax.');
    }

    $drawdat = preg_replace_callback(
        '/component\{([\s\S]*?)(\r?\n)\}/',
        static function (array $match) use (&$componentCounts, $componentMap, $result): string {
            $block = $match[1];
            $eol = $match[2];

            if (!preg_match('/^ctype=(\d+)\s*$/m', $block, $ctypeMatch)
                || !preg_match('/^position\.x=(\d+)\s*$/m', $block, $xMatch)
                || !preg_match('/^position\.y=(\d+)\s*$/m', $block, $yMatch)) {
                return $match[0];
            }

            $coordinate = $xMatch[1] . ',' . $yMatch[1];
            if (!isset($componentMap[$coordinate])) {
                return $match[0];
            }

            $mapping = $componentMap[$coordinate];
            if ($ctypeMatch[1] !== $mapping['ctype']) {
                throw new RuntimeException('Тип компонента в координатах ' . $coordinate . ' не совпадает с согласованным шаблоном.');
            }

            $key = $mapping['key'];
            $value = $result[$key] ?? null;
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0.0) {
                throw new RuntimeException('Для элемента ' . strtoupper($key) . ' нет положительного конечного номинала.');
            }
            $nominalSi = (float) $value * $mapping['scale'];
            $nominalText = dpf3_cct_scientific($nominalSi);

            $updated = preg_replace_callback(
                '/(value\[1\]=\{nom=\s*)[^;]+/',
                static fn(array $nominalMatch): string => $nominalMatch[1] . $nominalText,
                $block,
                1,
                $nominalCount
            );
            if (!is_string($updated) || $nominalCount !== 1) {
                throw new RuntimeException('Не удалось заменить номинал компонента в координатах ' . $coordinate . '.');
            }

            $componentCounts[$key]++;
            return 'component{' . $updated . $eol . '}';
        },
        $drawdat,
        -1,
        $componentMatches
    );
    if (!is_string($drawdat) || $componentMatches < 1) {
        throw new RuntimeException('Не удалось обработать компоненты в блоке drawdat.');
    }
    foreach ($expectedCounts as $key => $expected) {
        if ($componentCounts[$key] !== $expected) {
            throw new RuntimeException('В шаблоне найдено неожиданное количество компонентов ' . strtoupper($key) . '.');
        }
    }

    $portCounts = ['1' => 0, '2' => 0];
    $drawdat = preg_replace_callback(
        '/P([12])\{([\s\S]*?)(\r?\n)\}/',
        static function (array $match) use (&$portCounts, $resistance): string {
            $port = $match[1];
            $block = $match[2];
            $eol = $match[3];
            $updated = preg_replace_callback(
                '/(^Zo=\s*)[^\r\n]*/m',
                static fn(array $zoMatch): string => $zoMatch[1] . dpf3_cct_scientific($resistance),
                $block,
                1,
                $zoCount
            );
            if (!is_string($updated) || $zoCount !== 1) {
                throw new RuntimeException('Не удалось изменить импеданс порта P' . $port . '.');
            }
            $portCounts[$port]++;
            return 'P' . $port . '{' . $updated . $eol . '}';
        },
        $drawdat,
        -1,
        $portMatches
    );
    if (!is_string($drawdat) || $portMatches !== 2 || $portCounts !== ['1' => 1, '2' => 1]) {
        throw new RuntimeException('В шаблоне должны находиться ровно по одному порту P1 и P2.');
    }

    return $prefix . $drawdat;
}


// Два независимых набора исходных данных.
$seqResistanceRaw = trim((string) ($_POST['seq_resistance'] ?? '50'));
$seqFrequencyRaw = trim((string) ($_POST['seq_frequency'] ?? '7.1'));
$seqMode = (string) ($_POST['seq_mode'] ?? 'bandwidth');
if (!in_array($seqMode, ['bandwidth', 'inductance'], true)) {
    $seqMode = 'bandwidth';
}
$seqValueRaw = trim((string) ($_POST['seq_value'] ?? '500'));

$parResistanceRaw = trim((string) ($_POST['par_resistance'] ?? '50'));
$parFrequencyRaw = trim((string) ($_POST['par_frequency'] ?? '7.1'));
$parBandwidthRaw = trim((string) ($_POST['par_bandwidth'] ?? '500'));
$parL1Raw = trim((string) ($_POST['par_l1'] ?? '10'));

$seqResult = dpf3_restore_state((string) ($_POST['seq_state'] ?? ''), ['l1', 'c1', 'c2', 'c3', 'f1', 'f2']);
$parResult = dpf3_restore_state((string) ($_POST['par_state'] ?? ''), ['l1', 'c1', 'c2', 'c3', 'c4']);
$seqError = trim((string) ($_POST['seq_error_state'] ?? ''));
$parError = trim((string) ($_POST['par_error_state'] ?? ''));

$action = (string) ($_POST['calculate'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'sequential') {
    $seqResult = null;
    $seqError = '';

    $resistance = dpf3_number($seqResistanceRaw);
    $frequency = dpf3_number($seqFrequencyRaw);
    $value = dpf3_number($seqValueRaw);

    if ($resistance === null || $frequency === null || $value === null) {
        $seqError = 'Заполните все поля последовательного фильтра положительными числами. Десятичную часть можно отделять запятой или точкой.';
    } else {
        $calculation = dpf3_calculate_sequential($resistance, $frequency, $seqMode, $value);
        if (isset($calculation['error'])) {
            $seqError = $calculation['error'];
        } else {
            $seqResult = $calculation;
        }
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $action === 'parallel') {
    $parResult = null;
    $parError = '';

    $resistance = dpf3_number($parResistanceRaw);
    $frequency = dpf3_number($parFrequencyRaw);
    $deltaF = dpf3_number($parBandwidthRaw);
    $l1 = dpf3_number($parL1Raw);

    if ($resistance === null || $frequency === null || $deltaF === null || $l1 === null) {
        $parError = 'Заполните все поля параллельного фильтра положительными числами. Десятичную часть можно отделять запятой или точкой.';
    } else {
        $parResult = dpf3_calculate_parallel($resistance, $frequency, $deltaF, $l1);
    }
}


// Экспорт последовательной топологии. Сервер повторно рассчитывает параметры
// по исходным полям формы и не доверяет значениям из скрытого JSON состояния.
$exportAction = (string) ($_POST['export'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $exportAction === 'sequential') {
    $exportResistance = dpf3_number($seqResistanceRaw);
    $exportFrequency = dpf3_number($seqFrequencyRaw);
    $exportValue = dpf3_number($seqValueRaw);
    $timestamp = trim((string) ($_POST['seq_export_timestamp'] ?? ''));

    if ($exportResistance === null || $exportFrequency === null || $exportValue === null) {
        http_response_code(400);
        exit('Невозможно экспортировать схему: проверьте входные параметры последовательной топологии.');
    }
    if (!preg_match('/^\d{8}_\d{6}$/', $timestamp)) {
        http_response_code(400);
        exit('Не удалось получить местные дату и время компьютера. Повторите скачивание с включённым JavaScript.');
    }

    $exportResult = dpf3_calculate_sequential($exportResistance, $exportFrequency, $seqMode, $exportValue);
    if (isset($exportResult['error'])
        || !empty($exportResult['negative'])
        || !empty($exportResult['undefined'])
        || !isset($exportResult['l1'], $exportResult['c1'], $exportResult['c2'], $exportResult['c3'])
        || !is_finite((float) $exportResult['l1'])
        || !is_finite((float) $exportResult['c1'])
        || !is_finite((float) $exportResult['c2'])
        || !is_finite((float) $exportResult['c3'])
        || (float) $exportResult['l1'] <= 0.0
        || (float) $exportResult['c1'] <= 0.0
        || (float) $exportResult['c2'] <= 0.0
        || (float) $exportResult['c3'] <= 0.0) {
        http_response_code(400);
        exit('Экспорт отменён: расчёт содержит отрицательные или неопределённые номиналы.');
    }

    $exportBandwidthKHz = ((float) $exportResult['f2'] - (float) $exportResult['f1']) * 1000.0;
    if (!is_finite($exportBandwidthKHz) || $exportBandwidthKHz <= 0.0) {
        http_response_code(400);
        exit('Экспорт отменён: не удалось определить расчётную полосу фильтра.');
    }

    try {
        $cctContent = dpf3_build_sequential_cct(
            $exportResult,
            $exportResistance,
            $exportFrequency,
            $exportBandwidthKHz
        );
    } catch (Throwable $exception) {
        http_response_code(500);
        exit('Не удалось сформировать CCT: ' . $exception->getMessage());
    }

    $downloadName = 'BPF3_SER_' . $timestamp . '.cct';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . strlen($cctContent));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $cctContent;
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $exportAction === 'parallel') {
    $exportResistance = dpf3_number($parResistanceRaw);
    $exportFrequency = dpf3_number($parFrequencyRaw);
    $exportBandwidth = dpf3_number($parBandwidthRaw);
    $exportL1 = dpf3_number($parL1Raw);
    $timestamp = trim((string) ($_POST['par_export_timestamp'] ?? ''));

    if ($exportResistance === null || $exportFrequency === null || $exportBandwidth === null || $exportL1 === null) {
        http_response_code(400);
        exit('Невозможно экспортировать схему: проверьте входные параметры параллельной топологии.');
    }
    if (!preg_match('/^\d{8}_\d{6}$/', $timestamp)) {
        http_response_code(400);
        exit('Не удалось получить местные дату и время компьютера. Повторите скачивание с включённым JavaScript.');
    }

    $exportResult = dpf3_calculate_parallel($exportResistance, $exportFrequency, $exportBandwidth, $exportL1);
    if (!empty($exportResult['coefficient_undefined'])
        || !empty($exportResult['negative'])
        || !empty($exportResult['undefined'])
        || !isset($exportResult['l1'], $exportResult['c1'], $exportResult['c2'], $exportResult['c3'], $exportResult['c4'])
        || !is_finite((float) $exportResult['l1'])
        || !is_finite((float) $exportResult['c1'])
        || !is_finite((float) $exportResult['c2'])
        || !is_finite((float) $exportResult['c3'])
        || !is_finite((float) $exportResult['c4'])
        || (float) $exportResult['l1'] <= 0.0
        || (float) $exportResult['c1'] <= 0.0
        || (float) $exportResult['c2'] <= 0.0
        || (float) $exportResult['c3'] <= 0.0
        || (float) $exportResult['c4'] <= 0.0) {
        http_response_code(400);
        exit('Экспорт отменён: расчёт содержит отрицательные или неопределённые номиналы.');
    }

    try {
        $cctContent = dpf3_build_parallel_cct(
            $exportResult,
            $exportResistance,
            $exportFrequency,
            $exportBandwidth
        );
    } catch (Throwable $exception) {
        http_response_code(500);
        exit('Не удалось сформировать CCT: ' . $exception->getMessage());
    }

    $downloadName = 'BPF3_PAR_' . $timestamp . '.cct';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . strlen($cctContent));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $cctContent;
    exit;
}

$seqStateJson = dpf3_state_json($seqResult);
$parStateJson = dpf3_state_json($parResult);
$seqExportAvailable = ($seqResult !== null
    && $seqError === ''
    && empty($seqResult['negative'])
    && empty($seqResult['undefined'])
    && isset($seqResult['l1'], $seqResult['c1'], $seqResult['c2'], $seqResult['c3'])
    && (float) $seqResult['l1'] > 0.0
    && (float) $seqResult['c1'] > 0.0
    && (float) $seqResult['c2'] > 0.0
    && (float) $seqResult['c3'] > 0.0
    && ((((float) $seqResult['f1'] + (float) $seqResult['f2']) / 2.0) - 2.5 * ((float) $seqResult['f2'] - (float) $seqResult['f1'])) > 0.0);
$parFrequencyForExport = dpf3_number($parFrequencyRaw);
$parBandwidthForExport = dpf3_number($parBandwidthRaw);
$parExportAvailable = ($parResult !== null
    && $parError === ''
    && empty($parResult['negative'])
    && empty($parResult['undefined'])
    && empty($parResult['coefficient_undefined'])
    && isset($parResult['l1'], $parResult['c1'], $parResult['c2'], $parResult['c3'], $parResult['c4'])
    && (float) $parResult['l1'] > 0.0
    && (float) $parResult['c1'] > 0.0
    && (float) $parResult['c2'] > 0.0
    && (float) $parResult['c3'] > 0.0
    && (float) $parResult['c4'] > 0.0
    && $parFrequencyForExport !== null
    && $parBandwidthForExport !== null
    && ($parFrequencyForExport - 2.5 * ($parBandwidthForExport / 1000.0)) > 0.0);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Трёхзвенный полосовой LC-фильтр — радиолюбительские калькуляторы</title>
    <meta name="description" content="Независимый расчёт трёхзвенного полосового LC-фильтра для последовательной и параллельной топологий.">
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #202124; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 1000px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #d7dce1; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: clamp(1.45rem, 3vw, 2rem); line-height: 1.25; }
        h2 { margin: 0 0 14px; font-size: 1.2rem; line-height: 1.3; }
        h3 { margin: 0; font-size: .98rem; }
        p { line-height: 1.5; }
        .subtle { color: #5f6368; }
        .back-link { display: inline-block; margin-top: 4px; color: #244f76; font-weight: 700; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        a:focus-visible, button:focus-visible, input:focus-visible { outline: 3px solid #89bde5; outline-offset: 2px; }
        .topology-heading { margin: 0 0 5px; }
        .topology-description { margin: 0 0 15px; color: #5f6368; font-size: .91rem; line-height: 1.45; }
        .topology-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 20px; align-items: start; }
        .diagram-wrap { min-width: 0; padding: 8px; border: 1px solid #d7dce1; border-radius: 8px; background: #fff; }
        .diagram { display: block; width: 100%; height: auto; }
        .diagram-caption { margin: 8px 2px 1px; color: #69727b; font-size: .82rem; line-height: 1.4; }
        .right-area { min-width: 0; }
        .grid-three { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px 11px; align-items: end; }
         .input-fields { grid-template-columns: minmax(0, 1fr); gap: 8px; }
         .input-fields .field { display: grid; grid-template-columns: minmax(145px, .85fr) minmax(0, 1.15fr); align-items: center; gap: 10px; }
         .input-fields .field-label { margin: 0; }
         .input-fields .value-input { min-height: 37px; }
         .input-fields .mode-switch { min-width: 0; }
         .clear-link { color: #244f76; white-space: nowrap; }
         .action-cell { display: flex; align-items: center; gap: 12px; }
         .action-cell button { width: auto; }
        .field { min-width: 0; }
        .field-label { display: block; margin-bottom: 5px; font-size: .82rem; font-weight: 700; line-height: 1.3; }
        input, button { font: inherit; }
        .value-input { display: block; width: 100%; min-width: 0; min-height: 37px; border: 1px solid #b8c0c8; border-radius: 6px; padding: 7px 9px; background: #fff; color: #202124; }
        .value-input:focus { border-color: #3976a8; outline: 3px solid #c5e2f8; }
        .mode-switch { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 3px; padding: 3px; border: 1px solid #d7dce1; border-radius: 7px; background: #edf1f4; }
        .mode-choice { position: relative; min-width: 0; cursor: pointer; }
        .mode-choice input { position: absolute; width: 1px; height: 1px; opacity: 0; }
        .mode-choice span { display: flex; align-items: center; justify-content: center; min-height: 29px; padding: 4px 3px; border-radius: 4px; color: #244f76; text-align: center; font-size: .72rem; font-weight: 700; line-height: 1.15; }
        .mode-choice input:checked + span { color: #fff; background: #245c88; }
        .mode-choice input:focus-visible + span { outline: 3px solid #89bde5; outline-offset: 1px; }
        .action-cell { display: flex; align-items: end; min-width: 0; }
        button { display: inline-flex; align-items: center; justify-content: center; width: 100%; min-height: 37px; border: 0; border-radius: 6px; padding: 8px 10px; color: #fff; background: #245c88; font-size: .88rem; font-weight: 700; line-height: 1.2; cursor: pointer; }
        button:hover { background: #19476c; }
        .subsection-title { margin: 15px 0 9px; padding-top: 12px; border-top: 1px solid #e2e6e9; font-size: .92rem; font-weight: 700; }
        .calculation-output, .error { scroll-margin-top: 16px; }
        .result-item { min-width: 0; min-height: 67px; padding: 8px 9px; border: 1px solid #e0e5e9; border-radius: 7px; background: #f8fafb; }
        .result-label { display: block; margin-bottom: 5px; color: #626b73; font-size: .76rem; line-height: 1.25; }
        .result-value { display: block; color: #202124; font-size: 1rem; font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
        .result-unit { color: #4e5962; font-size: .78rem; font-weight: 600; }
        .error, .warning, .stale-hint { margin-top: 11px; padding: 10px 12px; border-radius: 7px; font-size: .88rem; line-height: 1.45; }
        .error { border: 1px solid #e4b7b7; background: #fff5f5; color: #8b2323; }
        .warning { border: 1px solid #dfbc70; border-left: 4px solid #bd811b; background: #fff8e8; color: #68460a; }
        .stale-hint { border: 1px solid #d6e2ec; background: #f2f7fb; color: #38566e; }
        .error[hidden], .warning[hidden], .stale-hint[hidden] { display: none; }
        .response-chart { margin-top: 18px; padding-top: 15px; border-top: 1px solid #e2e6e9; }
        .response-chart h3 { margin: 0 0 5px; font-size: .98rem; }
        .chart-subtitle { margin: 0 0 9px; color: #69727b; font-size: .82rem; line-height: 1.4; }
        .chart-legend { display: flex; flex-wrap: wrap; gap: 9px 18px; margin: 0 0 5px; color: #5f6368; font-size: .8rem; }
        .legend-item { display: inline-flex; align-items: center; gap: 6px; }
        .legend-swatch { display: inline-block; width: 23px; height: 0; border-top: 2.5px solid #245c88; }
        .legend-swatch.band { border-top: 2px dashed #89939c; }
        .legend-swatch.center { border-top: 2px dashed #c25b24; }
        .chart-canvas-wrap { position: relative; width: 100%; min-width: 0; min-height: 290px; border: 1px solid #e0e5e9; border-radius: 7px; background: #fff; overflow: hidden; }
        .response-canvas { display: block; width: 100%; height: 320px; }
        .chart-placeholder { display: flex; align-items: center; justify-content: center; min-height: 290px; padding: 22px; color: #69727b; text-align: center; font-size: .88rem; line-height: 1.45; }
        .chart-placeholder[hidden], .response-canvas[hidden] { display: none; }
        .chart-stats { margin: 7px 1px 0; color: #69727b; font-size: .8rem; line-height: 1.4; }
        .export-action { margin-top: 13px; max-width: 360px; }
        .export-action button { background: #28623f; }
        .export-action button:hover { background: #1f4e32; }
        .footer-note { margin: 2px 2px 0; color: #69727b; font-size: .84rem; line-height: 1.45; }
        @media (max-width: 760px) {
            body { padding: 10px; }
            .panel { padding: 15px; }
            .topology-layout { grid-template-columns: minmax(0, 1fr); gap: 14px; }
            .diagram-wrap { max-width: 100%; }
            .diagram { max-height: 170px; object-fit: contain; }
        }
        @media (max-width: 500px) {
            .grid-three { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 9px; }
            .mode-choice span { font-size: .7rem; }
            .response-canvas { height: 275px; }
            .chart-canvas-wrap, .chart-placeholder { min-height: 250px; }
            .chart-legend { gap: 7px 12px; font-size: .74rem; }
            .input-fields .field { grid-template-columns: minmax(120px, .85fr) minmax(0, 1.15fr); gap: 8px; }
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Трёхзвенный полосовой LC-фильтр</h1>
        <p class="subtle">Два независимых расчёта: последовательная и параллельная топологии. В каждом блоке свои исходные данные и отдельная кнопка расчёта.</p>
        <a class="back-link" href="index.php">← К списку калькуляторов</a>
    </section>

    <form id="dpf3-form" method="post" action="dpf_filter_3.php">
        <input type="hidden" id="seq_state" name="seq_state" value="<?= dpf3_h($seqStateJson) ?>">
        <input type="hidden" id="par_state" name="par_state" value="<?= dpf3_h($parStateJson) ?>">
        <input type="hidden" id="seq_error_state" name="seq_error_state" value="<?= dpf3_h($seqError) ?>">
        <input type="hidden" id="par_error_state" name="par_error_state" value="<?= dpf3_h($parError) ?>">
        <input type="hidden" id="seq_export_timestamp" name="seq_export_timestamp" value="">
        <input type="hidden" id="par_export_timestamp" name="par_export_timestamp" value="">

        <section class="panel topology-card" id="sequential-block">
            <h2 class="topology-heading">Вариант № 1 — последовательная топология</h2>
            <p class="topology-description">Три одинаковые последовательные катушки L1, два концевых последовательных конденсатора C1, центральный C2 и два шунтирующих C3.</p>
            <div class="topology-layout">
                <div class="diagram-wrap">
                    <img class="diagram" src="images/dpf-filter-three-stage.png" alt="Последовательная топология трёхзвенного ДПФ: три катушки L1, два конденсатора C1, один C2 и два шунтирующих C3" width="614" height="197">
                </div>

                <div class="right-area">
                    <div class="grid-three input-fields" aria-label="Исходные данные последовательной топологии">
                        <div class="field">
                            <label class="field-label" for="seq_resistance">Импеданс R, Ом</label>
                            <input class="value-input" id="seq_resistance" name="seq_resistance" type="text" inputmode="decimal" autocomplete="off" value="<?= dpf3_h($seqResistanceRaw) ?>" data-group="sequential">
                        </div>
                        <div class="field">
                            <label class="field-label" for="seq_frequency">Частота Fc, МГц</label>
                            <input class="value-input" id="seq_frequency" name="seq_frequency" type="text" inputmode="decimal" autocomplete="off" value="<?= dpf3_h($seqFrequencyRaw) ?>" data-group="sequential">
                        </div>
                        <div class="field">
                            <label class="field-label" for="seq_mode">Способ задания</label>
                            <select class="value-input" id="seq_mode" name="seq_mode" data-group="sequential">
                                <option value="bandwidth"<?= $seqMode === 'bandwidth' ? ' selected' : '' ?>>По полосе</option>
                                <option value="inductance"<?= $seqMode === 'inductance' ? ' selected' : '' ?>>По L1</option>
                            </select>
                        </div>
                        <div class="field">
                            <label class="field-label" id="seq-value-label" for="seq_value"><?= $seqMode === 'bandwidth' ? 'Полоса ΔF, кГц' : 'Индуктивность L1, мкГн' ?></label>
                            <input class="value-input" id="seq_value" name="seq_value" type="text" inputmode="decimal" autocomplete="off" value="<?= dpf3_h($seqValueRaw) ?>" data-group="sequential">
                        </div>
                        <div class="action-cell">
                            <button type="submit" name="calculate" value="sequential" formnovalidate>Рассчитать</button>
                            <a class="clear-link" href="#sequential-block" data-reset-group="sequential">Сбросить</a>
                        </div>
                    </div>

                    <div class="calculation-output" id="seq-output">
                        <div class="subsection-title">Номиналы элементов</div>
                        <div class="grid-three" id="seq-results">
                        <div class="result-item">
                            <span class="result-label">L1</span>
                            <strong class="result-value"><span data-result-group="sequential" data-result-key="l1"><?= dpf3_h(dpf3_output_value($seqResult, 'l1')) ?></span> <span class="result-unit">мкГн</span></strong>
                        </div>
                        <div class="result-item">
                            <span class="result-label">C1</span>
                            <strong class="result-value"><span data-result-group="sequential" data-result-key="c1"><?= dpf3_h(dpf3_output_value($seqResult, 'c1')) ?></span> <span class="result-unit">пФ</span></strong>
                        </div>
                        <div class="result-item">
                            <span class="result-label">C2</span>
                            <strong class="result-value"><span data-result-group="sequential" data-result-key="c2"><?= dpf3_h(dpf3_output_value($seqResult, 'c2')) ?></span> <span class="result-unit">пФ</span></strong>
                        </div>
                        <div class="result-item">
                            <span class="result-label">C3</span>
                            <strong class="result-value"><span data-result-group="sequential" data-result-key="c3"><?= dpf3_h(dpf3_output_value($seqResult, 'c3')) ?></span> <span class="result-unit">пФ</span></strong>
                        </div>
                        <div class="result-item">
                            <span class="result-label">Нижняя частота F1</span>
                            <strong class="result-value"><span data-result-group="sequential" data-result-key="f1"><?= dpf3_h(dpf3_output_value($seqResult, 'f1')) ?></span> <span class="result-unit">МГц</span></strong>
                        </div>
                        <div class="result-item">
                            <span class="result-label">Верхняя частота F2</span>
                            <strong class="result-value"><span data-result-group="sequential" data-result-key="f2"><?= dpf3_h(dpf3_output_value($seqResult, 'f2')) ?></span> <span class="result-unit">МГц</span></strong>
                        </div>
                    </div>
                    <div class="error" id="seq-error" role="alert"<?= $seqError === '' ? ' hidden' : '' ?>><?= dpf3_h($seqError) ?></div>
                    <div class="warning" id="seq-warning" role="status"<?= ($seqResult !== null && ($seqResult['negative'] || $seqResult['undefined'])) ? '' : ' hidden' ?>>
                        <?php if ($seqResult !== null && $seqResult['negative']): ?>Получены отрицательные значения ёмкости. Обычные конденсаторы не могут иметь отрицательную ёмкость; проверьте полосу пропускания или индуктивность.<?php else: ?>Для одной из ёмкостей нет конечного значения. Проверьте исходные параметры.<?php endif; ?>
                    </div>
                        <div class="stale-hint" id="seq-stale" hidden>Исходные данные изменены. Нажмите «Рассчитать», чтобы обновить номиналы этой топологии.</div>
                    </div>
                </div>
            </div>
            <div class="response-chart" id="seq-chart-panel">
                <h3>Расчётная АЧХ последовательной топологии</h3>
                <p class="chart-subtitle">Идеальная модель LC-цепи при сопротивлении источника и нагрузки R. Потери катушек, ESR конденсаторов и паразитные параметры монтажа не учитываются.</p>
                <div class="chart-legend" aria-label="Обозначения графика">
                    <span class="legend-item"><i class="legend-swatch"></i> Передача S21</span>
                    <span class="legend-item"><i class="legend-swatch band"></i> Границы полосы F1 и F2</span>
                    <span class="legend-item"><i class="legend-swatch center"></i> Центральная частота Fc</span>
                </div>
                <div class="chart-canvas-wrap">
                    <canvas class="response-canvas" id="seq-response-chart" aria-label="Расчётная амплитудно-частотная характеристика последовательного полосового фильтра" hidden></canvas>
                    <div class="chart-placeholder" id="seq-chart-placeholder">Выполните расчёт последовательной топологии, чтобы построить АЧХ.</div>
                </div>
                <p class="chart-stats" id="seq-chart-stats" aria-live="polite"></p>
                <?php if ($seqExportAvailable): ?>
                    <div class="export-action">
                        <button type="submit" name="export" value="sequential" id="seq-export-button" formnovalidate>Скачать схему для RFSim99 (.cct)</button>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel topology-card" id="parallel-block">
            <h2 class="topology-heading">Вариант № 2 — параллельная топология</h2>
            <p class="topology-description">Три одинаковые последовательные катушки L1, два концевых шунтирующих конденсатора C1, два последовательных C2, центральный C3 и два внутренних шунтирующих C4.</p>
            <div class="topology-layout">
                <div class="diagram-wrap">
                    <img class="diagram" src="images/dpf-filter-three-stage-parallel.png" alt="Параллельная топология трёхзвенного ДПФ: три катушки L1, два конденсатора C2, центральный C3, два шунтирующих C4 и два концевых C1" width="657" height="197">
                </div>

                <div class="right-area">
                    <div class="grid-three input-fields" aria-label="Исходные данные параллельной топологии">
                        <div class="field">
                            <label class="field-label" for="par_resistance">Импеданс R, Ом</label>
                            <input class="value-input" id="par_resistance" name="par_resistance" type="text" inputmode="decimal" autocomplete="off" value="<?= dpf3_h($parResistanceRaw) ?>" data-group="parallel">
                        </div>
                        <div class="field">
                            <label class="field-label" for="par_frequency">Частота Fc, МГц</label>
                            <input class="value-input" id="par_frequency" name="par_frequency" type="text" inputmode="decimal" autocomplete="off" value="<?= dpf3_h($parFrequencyRaw) ?>" data-group="parallel">
                        </div>
                        <div class="field">
                            <label class="field-label" for="par_bandwidth">Полоса ΔF, кГц</label>
                            <input class="value-input" id="par_bandwidth" name="par_bandwidth" type="text" inputmode="decimal" autocomplete="off" value="<?= dpf3_h($parBandwidthRaw) ?>" data-group="parallel">
                        </div>
                        <div class="field">
                            <label class="field-label" for="par_l1">Индуктивность L1, мкГн</label>
                            <input class="value-input" id="par_l1" name="par_l1" type="text" inputmode="decimal" autocomplete="off" value="<?= dpf3_h($parL1Raw) ?>" data-group="parallel">
                        </div>
                        <div class="action-cell">
                            <button type="submit" name="calculate" value="parallel" formnovalidate>Рассчитать</button>
                            <a class="clear-link" href="#parallel-block" data-reset-group="parallel">Сбросить</a>
                        </div>
                    </div>

                    <div class="calculation-output" id="par-output">
                        <div class="subsection-title">Номиналы элементов</div>
                        <div class="grid-three" id="par-results">
                        <div class="result-item">
                            <span class="result-label">L1</span>
                            <strong class="result-value"><span data-result-group="parallel" data-result-key="l1"><?= dpf3_h(dpf3_output_value($parResult, 'l1')) ?></span> <span class="result-unit">мкГн</span></strong>
                        </div>
                        <div class="result-item">
                            <span class="result-label">C1</span>
                            <strong class="result-value"><span data-result-group="parallel" data-result-key="c1"><?= dpf3_h(dpf3_output_value($parResult, 'c1')) ?></span> <span class="result-unit">пФ</span></strong>
                        </div>
                        <div class="result-item">
                            <span class="result-label">C2</span>
                            <strong class="result-value"><span data-result-group="parallel" data-result-key="c2"><?= dpf3_h(dpf3_output_value($parResult, 'c2')) ?></span> <span class="result-unit">пФ</span></strong>
                        </div>
                        <div class="result-item">
                            <span class="result-label">C3</span>
                            <strong class="result-value"><span data-result-group="parallel" data-result-key="c3"><?= dpf3_h(dpf3_output_value($parResult, 'c3')) ?></span> <span class="result-unit">пФ</span></strong>
                        </div>
                        <div class="result-item">
                            <span class="result-label">C4</span>
                            <strong class="result-value"><span data-result-group="parallel" data-result-key="c4"><?= dpf3_h(dpf3_output_value($parResult, 'c4')) ?></span> <span class="result-unit">пФ</span></strong>
                        </div>
                    </div>
                    <div class="error" id="par-error" role="alert"<?= $parError === '' ? ' hidden' : '' ?>><?= dpf3_h($parError) ?></div>
                    <div class="warning" id="par-warning" role="status"<?= ($parResult !== null && ($parResult['coefficient_undefined'] || $parResult['negative'] || $parResult['undefined'])) ? '' : ' hidden' ?>>
                        <?php if ($parResult !== null && $parResult['coefficient_undefined']): ?>Для этой комбинации R, полосы и L1 не удаётся получить конечные номиналы всех конденсаторов. Проверьте исходные параметры.<?php elseif ($parResult !== null && $parResult['negative']): ?>Получены отрицательные значения ёмкости. Обычные конденсаторы не могут иметь отрицательную ёмкость; проверьте полосу пропускания и индуктивность L1.<?php else: ?>Для одной или нескольких ёмкостей нет конечного значения. Проверьте исходные параметры.<?php endif; ?>
                    </div>
                        <div class="stale-hint" id="par-stale" hidden>Исходные данные изменены. Нажмите «Рассчитать», чтобы обновить номиналы этой топологии.</div>
                    </div>
                </div>
            </div>
            <div class="response-chart" id="par-chart-panel">
                <h3>Расчётная АЧХ параллельной топологии</h3>
                <p class="chart-subtitle">Идеальная модель LC-цепи при сопротивлении источника и нагрузки R. Потери катушек, ESR конденсаторов и паразитные параметры монтажа не учитываются.</p>
                <div class="chart-legend" aria-label="Обозначения графика">
                    <span class="legend-item"><i class="legend-swatch"></i> Передача S21</span>
                    <span class="legend-item"><i class="legend-swatch band"></i> Границы полосы F1 и F2</span>
                    <span class="legend-item"><i class="legend-swatch center"></i> Центральная частота Fc</span>
                </div>
                <div class="chart-canvas-wrap">
                    <canvas class="response-canvas" id="par-response-chart" aria-label="Расчётная амплитудно-частотная характеристика параллельного полосового фильтра" hidden></canvas>
                    <div class="chart-placeholder" id="par-chart-placeholder">Выполните расчёт параллельной топологии, чтобы построить АЧХ.</div>
                </div>
                <p class="chart-stats" id="par-chart-stats" aria-live="polite"></p>
                <?php if ($parExportAvailable): ?>
                    <div class="export-action">
                        <button type="submit" name="export" value="parallel" id="par-export-button" formnovalidate>Скачать схему для RFSim99 (.cct)</button>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </form>

    <p class="footer-note">Все три катушки L1 в каждой схеме имеют одинаковую индуктивность. Повторяющиеся обозначения конденсаторов также означают одинаковые номиналы. Промежуточные расчётные величины используются внутри формул и в списке элементов не отображаются.</p>
</main>
<script>
(() => {
    const form = document.getElementById('dpf3-form');
    if (!form) return;


    const exportButton = document.getElementById('seq-export-button');
    const exportTimestamp = document.getElementById('seq_export_timestamp');
    if (exportButton && exportTimestamp) {
        exportButton.addEventListener('click', () => {
            const now = new Date();
            const pad = value => String(value).padStart(2, '0');
            exportTimestamp.value = String(now.getFullYear())
                + pad(now.getMonth() + 1)
                + pad(now.getDate())
                + '_'
                + pad(now.getHours())
                + pad(now.getMinutes())
                + pad(now.getSeconds());
        });
    }

    const parallelExportButton = document.getElementById('par-export-button');
    const parallelExportTimestamp = document.getElementById('par_export_timestamp');
    if (parallelExportButton && parallelExportTimestamp) {
        parallelExportButton.addEventListener('click', () => {
            const now = new Date();
            const pad = value => String(value).padStart(2, '0');
            parallelExportTimestamp.value = String(now.getFullYear())
                + pad(now.getMonth() + 1)
                + pad(now.getDate())
                + '_'
                + pad(now.getHours())
                + pad(now.getMinutes())
                + pad(now.getSeconds());
        });
    }

    const resultKeys = {
        sequential: ['l1', 'c1', 'c2', 'c3'],
        parallel: ['l1', 'c1', 'c2', 'c3', 'c4']
    };

    function readPositiveNumber(id) {
        const element = document.getElementById(id);
        if (!element) return null;
        const normalized = String(element.value).replace(/[\s\u00a0\u202f]/g, '').replace(',', '.');
        if (normalized === '') return null;
        const value = Number(normalized);
        return Number.isFinite(value) && value > 0 ? value : null;
    }

    function readSavedResult(group) {
        const field = document.getElementById(group === 'sequential' ? 'seq_state' : 'par_state');
        if (!field || !field.value) return null;
        try {
            const state = JSON.parse(field.value);
            if (!state || typeof state !== 'object' || state.negative || state.undefined || state.coefficient_undefined) return null;
            for (const key of resultKeys[group]) {
                if (!Number.isFinite(Number(state[key])) || Number(state[key]) <= 0) return null;
            }
            return state;
        } catch (_) {
            return null;
        }
    }

    // Расчёт частотной характеристики каскадом ABCD-матриц идеальных L и C.
    // На входе и выходе учитываются одинаковые активные сопротивления R.
    function complexAdd(a, b) { return [a[0] + b[0], a[1] + b[1]]; }
    function complexMultiply(a, b) {
        return [a[0] * b[0] - a[1] * b[1], a[0] * b[1] + a[1] * b[0]];
    }
    function scaleComplex(a, factor) { return [a[0] * factor, a[1] * factor]; }

    function circuitTransmissionDb(group, state, resistance, frequencyHz) {
        const omega = 2 * Math.PI * frequencyHz;
        if (!(omega > 0) || !Number.isFinite(omega)) return -120;

        let A = [1, 0], B = [0, 0], C = [0, 0], D = [1, 0];
        function seriesImpedance(z) {
            B = complexAdd(B, complexMultiply(A, z));
            D = complexAdd(D, complexMultiply(C, z));
        }
        function shuntAdmittance(y) {
            A = complexAdd(A, complexMultiply(B, y));
            C = complexAdd(C, complexMultiply(D, y));
        }
        function seriesL(valueMicroHenry) {
            seriesImpedance([0, omega * valueMicroHenry * 1e-6]);
        }
        function seriesC(valuePicoFarad) {
            seriesImpedance([0, -1 / (omega * valuePicoFarad * 1e-12)]);
        }
        function shuntC(valuePicoFarad) {
            shuntAdmittance([0, omega * valuePicoFarad * 1e-12]);
        }

        if (group === 'sequential') {
            // C1 — L1 — узел C3 — L1 — C2 — узел C3 — L1 — C1.
            seriesC(state.c1);
            seriesL(state.l1);
            shuntC(state.c3);
            seriesL(state.l1);
            seriesC(state.c2);
            shuntC(state.c3);
            seriesL(state.l1);
            seriesC(state.c1);
        } else {
            // Узел C1 — C2 — L1 — узел C4 — L1 — C3 — узел C4 — L1 — C2 — узел C1.
            shuntC(state.c1);
            seriesC(state.c2);
            seriesL(state.l1);
            shuntC(state.c4);
            seriesL(state.l1);
            seriesC(state.c3);
            shuntC(state.c4);
            seriesL(state.l1);
            seriesC(state.c2);
            shuntC(state.c1);
        }

        // S21 = 2 / (A + B/R + C*R + D), при опорном сопротивлении R.
        const denominator = complexAdd(
            complexAdd(A, scaleComplex(B, 1 / resistance)),
            complexAdd(scaleComplex(C, resistance), D)
        );
        const magnitude = 2 / Math.hypot(denominator[0], denominator[1]);
        if (!(magnitude > 0) || !Number.isFinite(magnitude)) return -120;
        return 20 * Math.log10(magnitude);
    }

    function chartInputs(group, state) {
        if (!state) return null;
        if (group === 'sequential') {
            const resistance = readPositiveNumber('seq_resistance');
            const frequencyMHz = readPositiveNumber('seq_frequency');
            const value = readPositiveNumber('seq_value');
            const mode = form.elements.namedItem('seq_mode')?.value || 'bandwidth';
            if (!resistance || !frequencyMHz || !value) return null;
            const deltaFkHz = mode === 'bandwidth' ? value : resistance * 1000 / (2 * Math.PI * value);
            return deltaFkHz > 0 && Number.isFinite(deltaFkHz)
                ? {resistance, frequencyHz: frequencyMHz * 1e6, deltaFHz: deltaFkHz * 1e3}
                : null;
        }
        const resistance = readPositiveNumber('par_resistance');
        const frequencyMHz = readPositiveNumber('par_frequency');
        const deltaFkHz = readPositiveNumber('par_bandwidth');
        if (!resistance || !frequencyMHz || !deltaFkHz) return null;
        return {resistance, frequencyHz: frequencyMHz * 1e6, deltaFHz: deltaFkHz * 1e3};
    }

    function formatFrequencyMHz(valueHz) {
        return (valueHz / 1e6).toLocaleString('ru-RU', {maximumFractionDigits: 3});
    }

    function drawResponseChart(group) {
        const prefix = group === 'sequential' ? 'seq' : 'par';
        const canvas = document.getElementById(prefix + '-response-chart');
        const placeholder = document.getElementById(prefix + '-chart-placeholder');
        const stats = document.getElementById(prefix + '-chart-stats');
        const state = readSavedResult(group);
        const inputs = chartInputs(group, state);
        if (!canvas || !placeholder || !stats) return;

        if (!state || !inputs) {
            canvas.hidden = true;
            placeholder.hidden = false;
            stats.textContent = '';
            return;
        }

        // Сначала показываем canvas: у скрытого элемента clientWidth равен нулю.
        canvas.hidden = false;
        placeholder.hidden = true;
        const cssWidth = Math.max(300, canvas.clientWidth);
        const cssHeight = Math.max(250, canvas.clientHeight);
        const dpr = Math.max(1, window.devicePixelRatio || 1);
        canvas.width = Math.round(cssWidth * dpr);
        canvas.height = Math.round(cssHeight * dpr);
        const ctx = canvas.getContext('2d');
        if (!ctx) return;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, cssWidth, cssHeight);
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, cssWidth, cssHeight);

        const fc = inputs.frequencyHz;
        const bandwidth = inputs.deltaFHz;
        const fMin = Math.max(fc * 0.05, fc - 4 * bandwidth);
        const fMax = fc + 4 * bandwidth;
        if (!(fMax > fMin)) return;
        const f1 = fc - bandwidth / 2;
        const f2 = fc + bandwidth / 2;
        const left = cssWidth < 480 ? 51 : 61;
        const right = 14, top = 27, bottom = cssWidth < 480 ? 58 : 62;
        const plotW = cssWidth - left - right;
        const plotH = cssHeight - top - bottom;
        const yMin = -60, yMax = 3;
        const xMap = frequency => left + (frequency - fMin) / (fMax - fMin) * plotW;
        const yMap = db => top + (yMax - db) / (yMax - yMin) * plotH;

        ctx.font = '12px Arial, Helvetica, sans-serif';
        ctx.lineWidth = 1;
        for (let db = 0; db >= -60; db -= 10) {
            const y = yMap(db);
            ctx.strokeStyle = '#e7ebef';
            ctx.beginPath(); ctx.moveTo(left, y); ctx.lineTo(left + plotW, y); ctx.stroke();
            ctx.fillStyle = '#5f6368'; ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
            ctx.fillText(String(db), left - 8, y);
        }
        const tickCount = cssWidth < 480 ? 4 : 8;
        for (let i = 0; i <= tickCount; i++) {
            const frequency = fMin + (fMax - fMin) * i / tickCount;
            const x = xMap(frequency);
            ctx.strokeStyle = '#eef1f4';
            ctx.beginPath(); ctx.moveTo(x, top); ctx.lineTo(x, top + plotH); ctx.stroke();
            ctx.fillStyle = '#5f6368'; ctx.textAlign = 'center'; ctx.textBaseline = 'top';
            ctx.fillText(formatFrequencyMHz(frequency), x, top + plotH + 7);
        }

        // Граничные частоты F1/F2 и центральная частота Fc.
        [[f1, '#89939c', [4, 4]], [f2, '#89939c', [4, 4]], [fc, '#c25b24', [6, 4]]].forEach(([frequency, color, dash]) => {
            const x = xMap(frequency);
            if (x < left || x > left + plotW) return;
            ctx.save();
            ctx.strokeStyle = color; ctx.lineWidth = 1.3; ctx.setLineDash(dash);
            ctx.beginPath(); ctx.moveTo(x, top); ctx.lineTo(x, top + plotH); ctx.stroke();
            ctx.restore();
        });

        const samples = Math.max(900, Math.round(plotW * 2));
        ctx.beginPath();
        ctx.strokeStyle = '#245c88';
        ctx.lineWidth = 2.25;
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        for (let i = 0; i <= samples; i++) {
            const frequency = fMin + (fMax - fMin) * i / samples;
            const db = circuitTransmissionDb(group, state, inputs.resistance, frequency);
            const boundedDb = Math.max(yMin, Math.min(yMax, db));
            const x = xMap(frequency), y = yMap(boundedDb);
            if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
        }
        ctx.stroke();
        ctx.strokeStyle = '#9ba5ae'; ctx.lineWidth = 1.1;
        ctx.strokeRect(left, top, plotW, plotH);

        ctx.fillStyle = '#202124'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
        ctx.font = '13px Arial, Helvetica, sans-serif';
        ctx.fillText('Частота, МГц', left + plotW / 2, cssHeight - 7);
        ctx.save(); ctx.translate(15, top + plotH / 2); ctx.rotate(-Math.PI / 2);
        ctx.fillText('Передача S21, дБ', 0, 0); ctx.restore();

        stats.textContent = 'F1 = ' + formatFrequencyMHz(f1) + ' МГц; Fc = ' + formatFrequencyMHz(fc) + ' МГц; F2 = ' + formatFrequencyMHz(f2) + ' МГц. Расчёт выполнен для идеальных компонентов и согласованных сопротивлений источника и нагрузки ' + inputs.resistance.toLocaleString('ru-RU') + ' Ом.';
    }

    function markOutdated(group) {
        const state = document.getElementById(group === 'sequential' ? 'seq_state' : 'par_state');
        const errorState = document.getElementById(group === 'sequential' ? 'seq_error_state' : 'par_error_state');
        const hadResult = state && state.value !== '';

        if (state) state.value = '';
        if (errorState) errorState.value = '';
        if (group === 'sequential' && exportButton) exportButton.closest('.export-action')?.remove();
        if (group === 'parallel' && parallelExportButton) parallelExportButton.closest('.export-action')?.remove();

        document.querySelectorAll(`[data-result-group="${group}"]`).forEach(output => {
            output.textContent = '—';
        });

        const error = document.getElementById(group === 'sequential' ? 'seq-error' : 'par-error');
        const warning = document.getElementById(group === 'sequential' ? 'seq-warning' : 'par-warning');
        const stale = document.getElementById(group === 'sequential' ? 'seq-stale' : 'par-stale');
        if (error) { error.textContent = ''; error.hidden = true; }
        if (warning) warning.hidden = true;
        if (stale) stale.hidden = !hadResult;
        drawResponseChart(group);
    }

    form.querySelectorAll('[data-group="sequential"]').forEach(control => {
        const eventName = control.tagName === 'SELECT' || control.type === 'radio' ? 'change' : 'input';
        control.addEventListener(eventName, () => markOutdated('sequential'));
    });
    form.querySelectorAll('[data-group="parallel"]').forEach(control => {
        control.addEventListener('input', () => markOutdated('parallel'));
    });

    const modeSelect = document.getElementById('seq_mode');
    const valueInput = document.getElementById('seq_value');
    const valueLabel = document.getElementById('seq-value-label');
    const modeValues = {
        bandwidth: '500',
        inductance: '4.7'
    };
    let activeMode = modeSelect?.value || 'bandwidth';
    if (valueInput) modeValues[activeMode] = valueInput.value;

    modeSelect?.addEventListener('change', () => {
        if (valueInput) {
            modeValues[activeMode] = valueInput.value;
            activeMode = modeSelect.value;
            valueInput.value = modeValues[activeMode] || (activeMode === 'bandwidth' ? '500' : '4.7');
            valueLabel.textContent = activeMode === 'bandwidth' ? 'Полоса ΔF, кГц' : 'Индуктивность L1, мкГн';
        }
    });

    // Сброс каждого варианта затрагивает только его поля, номиналы и график.
    form.querySelectorAll('[data-reset-group]').forEach(link => {
        link.addEventListener('click', event => {
            event.preventDefault();
            const group = link.dataset.resetGroup;
            const prefix = group === 'sequential' ? 'seq' : 'par';
            if (group === 'sequential') {
                document.getElementById('seq_resistance').value = '50';
                document.getElementById('seq_frequency').value = '7.1';
                if (modeSelect) modeSelect.value = 'bandwidth';
                activeMode = 'bandwidth';
                modeValues.bandwidth = '500';
                modeValues.inductance = '4.7';
                if (valueInput) valueInput.value = '500';
                if (valueLabel) valueLabel.textContent = 'Полоса ΔF, кГц';
            } else if (group === 'parallel') {
                document.getElementById('par_resistance').value = '50';
                document.getElementById('par_frequency').value = '7.1';
                document.getElementById('par_bandwidth').value = '500';
                document.getElementById('par_l1').value = '10';
            } else {
                return;
            }
            markOutdated(group);
            const stale = document.getElementById(prefix + '-stale');
            if (stale) stale.hidden = true;
        });
    });

    // Восстанавливаем оба графика после перезагрузки страницы, включая расчёт соседней топологии.
    drawResponseChart('sequential');
    drawResponseChart('parallel');
    window.addEventListener('resize', () => {
        drawResponseChart('sequential');
        drawResponseChart('parallel');
    });
})();
</script>
<?php if ($action === 'sequential' || $action === 'parallel'): ?>
<script>
(() => {
    const group = <?= json_encode($action, JSON_UNESCAPED_UNICODE) ?>;
    const hasError = <?= json_encode($action === 'sequential' ? $seqError !== '' : $parError !== '') ?>;
    const prefix = group === 'sequential' ? 'seq' : 'par';
    const target = document.getElementById(hasError ? prefix + '-error' : prefix + '-output');
    if (!target) return;

    // После отправки формы прокручиваем страницу к результату только выбранной топологии.
    // Фокус на блок не переводим: так сохраняется прокрутка без видимой рамки вокруг результатов.
    // Два кадра ожидания дают браузеру завершить восстановление DOM и отрисовку графиков.
    requestAnimationFrame(() => requestAnimationFrame(() => {
        target.scrollIntoView({behavior: 'smooth', block: 'start'});
    }));
})();
</script>
<?php endif; ?>
</body>
</html>
