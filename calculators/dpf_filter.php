<?php
/*
 * Калькулятор двухзвенного полосового LC-фильтра (ДПФ).
 * Формулы перенесены из исходной таблицы «2 звена.ods».
 * Обе катушки L1 в каждой топологии имеют одинаковую индуктивность.
 */
declare(strict_types=1);

require_once __DIR__ . '/cct_frequency_axis.php';

function dpf_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dpf_number(string $raw): ?float
{
    $raw = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($raw));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $number = (float) $raw;
    return ($number > 0.0 && is_finite($number)) ? $number : null;
}

function dpf_num(float $value, int $decimals = 6): string
{
    if (!is_finite($value)) {
        return '—';
    }
    if ($value !== 0.0 && (abs($value) >= 1e12 || abs($value) < 1e-5)) {
        return str_replace('.', ',', sprintf('%.6g', $value));
    }
    $formatted = number_format($value, $decimals, ',', '');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    return ($formatted === '' || $formatted === '-0') ? '0' : $formatted;
}

function dpf_checked(string $current, string $value): string
{
    return $current === $value ? ' checked' : '';
}


/**
 * Формат числа RFSim99: мантисса и четырёхзначный показатель степени.
 * Значения передаются в СИ: Ф, Гн, Ом, Гц.
 */
function dpf_cct_scientific(float $value): string
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
 * Создаёт CCT из одного из двух шаблонов ДПФ.
 * Изменяются только номиналы заданных элементов, импедансы портов и Xax min/max.
 */
function dpf_build_cct(string $topology, array $result): string
{
    if ($topology === 'T') {
        $templateName = 'BPF2_T.cct';
        $componentMap = [
            '260,180' => ['key' => 'c1', 'ctype' => '2', 'scale' => 1e-12],
            '340,180' => ['key' => 'c1', 'ctype' => '2', 'scale' => 1e-12],
            '300,250' => ['key' => 'c2', 'ctype' => '2', 'scale' => 1e-12],
            '180,180' => ['key' => 'l1', 'ctype' => '3', 'scale' => 1e-6],
            '410,180' => ['key' => 'l1', 'ctype' => '3', 'scale' => 1e-6],
        ];
        $expectedCounts = ['c1' => 2, 'c2' => 1, 'l1' => 2];
    } elseif ($topology === 'P') {
        $templateName = 'BPF2_P.cct';
        $componentMap = [
            '150,230' => ['key' => 'l1', 'ctype' => '3', 'scale' => 1e-6],
            '340,230' => ['key' => 'l1', 'ctype' => '3', 'scale' => 1e-6],
            '250,230' => ['key' => 'cb', 'ctype' => '2', 'scale' => 1e-12],
            '200,300' => ['key' => 'ca', 'ctype' => '2', 'scale' => 1e-12],
            '300,300' => ['key' => 'ca', 'ctype' => '2', 'scale' => 1e-12],
        ];
        $expectedCounts = ['ca' => 2, 'cb' => 1, 'l1' => 2];
    } else {
        throw new RuntimeException('Неизвестная топология для экспорта CCT.');
    }

    $templatePath = __DIR__ . '/templates/' . $templateName;
    if (!is_file($templatePath) || !is_readable($templatePath)) {
        throw new RuntimeException('Не найден шаблон /calculators/templates/' . $templateName . ' или нет доступа на чтение.');
    }

    $template = file_get_contents($templatePath);
    if (!is_string($template) || $template === '') {
        throw new RuntimeException('Не удалось прочитать шаблон ' . $templateName . '.');
    }

    $resistance = (float) $result['resistance'];
    $frequencyMHz = (float) $result['frequency'];
    $bandwidthKHz = (float) $result['delta_f'];

    // Общее окно по частоте в пять раз шире полосы пропускания.
    $minHz = ($frequencyMHz - 2.5 * ($bandwidthKHz / 1000.0)) * 1e6;
    $maxHz = ($frequencyMHz + 2.5 * ($bandwidthKHz / 1000.0)) * 1e6;
    $minimumAllowedHz = null;
    if ($minHz <= 0.0) {
        $minimumAllowedHz = $frequencyMHz * 0.05 * 1e6;
        $minHz = $minimumAllowedHz;
    }
    [$minHz, $maxHz] = cct_frequency_axis_bounds($minHz, $maxHz, $minimumAllowedHz);
    if (!is_finite($minHz) || !is_finite($maxHz) || $maxHz <= $minHz) {
        throw new RuntimeException('Для заданных параметров получился недопустимый диапазон отображения АЧХ.');
    }

    $drawdatPosition = strpos($template, 'drawdat{');
    if ($drawdatPosition === false) {
        throw new RuntimeException('В шаблоне не найден блок drawdat.');
    }
    $prefix = substr($template, 0, $drawdatPosition);
    $drawdat = substr($template, $drawdatPosition);

    $prefix = preg_replace_callback(
        '/Xax\{([\s\S]*?)(\r?\n)\}/',
        static function (array $match) use ($minHz, $maxHz): string {
            $block = $match[1];
            $eol = $match[2];
            foreach (['max' => $maxHz, 'min' => $minHz] as $field => $value) {
                $block = preg_replace_callback(
                    '/^(' . preg_quote($field, '/') . '=\s*)[^\r\n]*/m',
                    static fn(array $line): string => $line[1] . dpf_cct_scientific($value),
                    $block,
                    1,
                    $count
                );
                if (!is_string($block) || $count !== 1) {
                    throw new RuntimeException('В блоке Xax не удалось однозначно заменить параметр ' . $field . '.');
                }
            }
            return 'Xax{' . $block . $eol . '}';
        },
        $prefix,
        -1,
        $xaxMatches
    );
    if (!is_string($prefix) || $xaxMatches !== 1) {
        throw new RuntimeException('В шаблоне должен находиться ровно один блок Xax.');
    }

    $componentCounts = array_fill_keys(array_keys($expectedCounts), 0);
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
                // В том числе оставляем вспомогательные компоненты ctype=4 без изменений.
                return $match[0];
            }

            $mapping = $componentMap[$coordinate];
            if ($ctypeMatch[1] !== $mapping['ctype']) {
                throw new RuntimeException('Тип компонента в координатах ' . $coordinate . ' не совпадает с шаблоном.');
            }

            $key = $mapping['key'];
            $value = $result[$key] ?? null;
            if (!is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0.0) {
                throw new RuntimeException('Для элемента ' . strtoupper($key) . ' нет положительного конечного номинала.');
            }

            $nominalText = dpf_cct_scientific((float) $value * $mapping['scale']);
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
            throw new RuntimeException('В шаблоне найдено неожиданное количество элементов ' . strtoupper($key) . '.');
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
                static fn(array $zoMatch): string => $zoMatch[1] . dpf_cct_scientific($resistance),
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

$mode = (string) ($_POST['mode'] ?? 'bandwidth');
if (!in_array($mode, ['bandwidth', 'inductance'], true)) {
    $mode = 'bandwidth';
}

$resistanceRaw = trim((string) ($_POST['resistance'] ?? '50'));
$frequencyRaw = trim((string) ($_POST['frequency'] ?? '6'));
$thirdDefault = $mode === 'bandwidth' ? '4000' : '100';
$thirdRaw = trim((string) ($_POST['third_value'] ?? $thirdDefault));

$exportAction = (string) ($_POST['export'] ?? '');
$isExportRequest = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')
    && in_array($exportAction, ['T', 'P'], true);
$isSubmitted = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')
    && (isset($_POST['calculate']) || $isExportRequest);
$error = '';
$result = null;

if ($isSubmitted) {
    $resistance = dpf_number($resistanceRaw);
    $frequency = dpf_number($frequencyRaw);
    $third = dpf_number($thirdRaw);

    if ($resistance === null || $frequency === null || $third === null) {
        $error = 'Все три значения должны быть конечными числами больше нуля. Десятичную часть можно отделять запятой или точкой.';
    } else {
        $deltaF = 0.0;
        $l1 = 0.0;
        if ($mode === 'bandwidth') {
            // Исходные единицы таблицы: R — Ом, Fc — МГц, ΔF — кГц, L1 — мкГн.
            $deltaF = $third;
            if ($deltaF >= $frequency * 1000.0) {
                $error = 'Для формулы из исходной таблицы полоса пропускания должна быть меньше центральной частоты, выраженной в кГц.';
            } else {
                $l1 = ($resistance * 1000.0) / (2.0 * M_PI * $deltaF);
            }
        } else {
            $l1 = $third;
            $deltaF = (1000.0 * $resistance) / (2.0 * M_PI * $l1);
            if (!is_finite($deltaF) || $deltaF <= 0.0) {
                $error = 'Не удалось получить конечную полосу пропускания. Проверьте исходные значения.';
            } elseif ($deltaF >= $frequency * 1000.0) {
                $error = 'При заданной индуктивности формула исходной таблицы даёт недопустимое значение ёмкости. Увеличьте индуктивность или центральную частоту.';
            }
        }

        if ($error === '') {
            $f1 = $frequency - $deltaF / 2000.0;
            $f2 = $frequency + $deltaF / 2000.0;
            $c2 = 1000000.0 / (2.0 * M_PI * $frequency * $resistance);
            $denominator = ($frequency * 1000.0 / $deltaF) - 1.0;

            if ($f1 <= 0.0 || $denominator <= 0.0 || !is_finite($l1) || $l1 <= 0.0) {
                $error = 'Получены недопустимые параметры фильтра. Проверьте центральную частоту, полосу пропускания и индуктивность.';
            } else {
                // Эти выражения повторяют формулы в исходной таблице.
                $c1 = $c2 / $denominator;
                $transformDenominator = 2.0 * $c1 + $c2;
                $ca = ($c1 * $c2) / $transformDenominator;
                $cb = ($c1 * $c1) / $transformDenominator;
                $values = [$deltaF, $l1, $f1, $f2, $c1, $c2, $ca, $cb];
                $valid = true;
                foreach ($values as $value) {
                    if (!is_finite($value) || $value <= 0.0) {
                        $valid = false;
                        break;
                    }
                }

                if (!$valid) {
                    $error = 'Результат выходит за пределы вычислимого диапазона. Проверьте исходные значения.';
                } else {
                    $result = [
                        'mode' => $mode,
                        'resistance' => $resistance,
                        'frequency' => $frequency,
                        'delta_f' => $deltaF,
                        'l1' => $l1,
                        'f1' => $f1,
                        'f2' => $f2,
                        'c1' => $c1,
                        'c2' => $c2,
                        'ca' => $ca,
                        'cb' => $cb,
                    ];
                }
            }
        }
    }
}

if ($isExportRequest) {
    $timestamp = trim((string) ($_POST['export_timestamp'] ?? ''));

    if ($result === null || $error !== '') {
        http_response_code(400);
        exit('Невозможно экспортировать схему: проверьте исходные параметры фильтра.');
    }
    if (!preg_match('/^\d{8}_\d{6}$/', $timestamp)) {
        http_response_code(400);
        exit('Не удалось получить местные дату и время компьютера. Повторите скачивание с включённым JavaScript.');
    }

    try {
        $cctContent = dpf_build_cct($exportAction, $result);
    } catch (Throwable $exception) {
        http_response_code(500);
        exit('Не удалось сформировать CCT: ' . $exception->getMessage());
    }

    $downloadName = 'BPF2_' . $exportAction . '_' . $timestamp . '.cct';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . strlen($cctContent));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo $cctContent;
    exit;
}

?>
<!doctype html
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Расчёт двухзвенного полосового LC-фильтра — радиолюбительские калькуляторы</title>
    <meta name="description" content="Расчёт двухзвенного полосового LC-фильтра по заданной полосе пропускания или по индуктивности одинаковых катушек. Номиналы для Т- и П-образной топологии.">
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #202124; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 1000px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #d7dce1; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: clamp(1.45rem, 3vw, 2rem); line-height: 1.25; }
        h2 { margin: 0 0 12px; font-size: 1.22rem; }
        h3 { margin: 0 0 10px; font-size: 1.05rem; }
        p { line-height: 1.5; }
        .subtle { color: #5f6368; }
        .back-link { display: inline-block; margin-top: 4px; color: #244f76; font-weight: 700; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        a:focus-visible, button:focus-visible { outline: 3px solid #89bde5; outline-offset: 3px; }
        .note { padding: 12px 14px; border-left: 4px solid #3c7ca7; background: #f2f7fb; border-radius: 5px; line-height: 1.45; }
        .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; align-items: end; }
        .mode-third-grid { display: grid; grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr); gap: 14px; align-items: end; margin-top: 15px; }
        .field { min-width: 0; }
        .field-label { display: block; font-weight: 700; margin-bottom: 6px; line-height: 1.35; }
        input, button { font: inherit; }
        .value-input { display: block; width: 100%; min-width: 0; min-height: 43px; border: 1px solid #b8c0c8; border-radius: 6px; padding: 10px 11px; background: #fff; color: #202124; }
        .value-input:focus { outline: 3px solid #c5e2f8; border-color: #3976a8; }
        .field-hint { display: block; color: #69727b; font-size: .86rem; line-height: 1.4; margin-top: 5px; }
        .mode-switch { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 4px; background: #edf1f4; border: 1px solid #d7dce1; border-radius: 8px; padding: 4px; }
        .mode-choice { position: relative; min-width: 0; cursor: pointer; }
        .mode-choice input { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
        .mode-choice span { display: flex; align-items: center; justify-content: center; text-align: center; min-height: 40px; padding: 7px 9px; border-radius: 5px; color: #244f76; font-size: .94rem; font-weight: 700; line-height: 1.25; }
        .mode-choice input:checked + span { color: #fff; background: #245c88; box-shadow: 0 1px 2px rgb(0 0 0 / 12%); }
        .mode-choice input:focus-visible + span { outline: 3px solid #89bde5; outline-offset: 2px; }
        .action-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 18px; }
        button { display: inline-flex; align-items: center; justify-content: center; min-height: 43px; border: 0; border-radius: 6px; padding: 11px 17px; color: #fff; background: #245c88; font-weight: 700; line-height: 1.25; cursor: pointer; }
        button:hover { background: #19476c; }
        .clear-link { color: #244f76; }
        .result { overflow: hidden; }
        .result-heading { margin-bottom: 5px; }
        .result-intro { margin: 0 0 16px; }
        .result-layout { display: grid; grid-template-columns: minmax(230px, .82fr) minmax(0, 1.18fr); gap: 20px; align-items: start; }
        .diagram { display: block; width: 100%; height: auto; margin: 0; border: 1px solid #d7dce1; border-radius: 8px; background: #fff; }
        .diagram-caption { margin: 8px 1px 0; color: #5f6368; font-size: .88rem; line-height: 1.45; }
        .summary-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin-bottom: 13px; }
        .metric { min-width: 0; padding: 10px 11px; border: 1px solid #d7dce1; border-radius: 7px; background: #fafbfc; }
        .metric-label { display: block; margin-bottom: 4px; color: #5f6368; font-size: .88rem; line-height: 1.3; }
        .metric-value { display: block; overflow-wrap: anywhere; font-size: 1.08rem; font-weight: 700; color: #202124; line-height: 1.3; }
        .topology-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .topology { min-width: 0; border: 1px solid #d7dce1; border-radius: 8px; padding: 12px; }
        .topology h3 { margin-bottom: 8px; line-height: 1.3; }
        .part-row { display: flex; justify-content: space-between; align-items: baseline; gap: 9px; padding: 8px 0; border-top: 1px solid #e6e9ec; }
        .part-label { min-width: 0; }
        .part-description { display: block; color: #69727b; font-size: .82rem; line-height: 1.35; margin-top: 2px; }
        .part-value { flex: 0 0 auto; text-align: right; font-weight: 700; overflow-wrap: anywhere; }
        .cct-export-form { margin-top: 12px; }
        .cct-export-button { width: 100%; background: #28623f; }
        .cct-export-button:hover { background: #1f4e32; }
        .error { margin-top: 15px; padding: 12px 13px; border: 1px solid #e4b7b7; border-radius: 8px; background: #fff5f5; color: #8b2323; line-height: 1.45; }
        .stale-hint { display: none; margin-top: 12px; color: #7a5200; font-size: .93rem; line-height: 1.4; }
        .formula-list { margin: 0; padding-left: 20px; line-height: 1.7; }
        .formula-list li + li { margin-top: 4px; }
        .small { font-size: .9rem; }
        @media (max-width: 760px) {
            body { padding: 10px; }
            .panel { padding: 15px; }
            .mode-third-grid { grid-template-columns: minmax(0, 1fr); gap: 12px; margin-top: 13px; }
            .result-layout { grid-template-columns: minmax(0, 1fr); gap: 16px; }
            .diagram-wrap { max-width: 500px; margin: 0 auto; }
        }
        @media (max-width: 440px) {
            .form-grid { grid-template-columns: minmax(0, 1fr); gap: 12px; }
            .topology-grid { grid-template-columns: minmax(0, 1fr); }
            .mode-choice span { padding: 7px 6px; font-size: .88rem; }
            .summary-grid { gap: 7px; }
            .metric { padding: 9px; }
        }
        @media print {
            body { padding: 0; background: #fff; }
            .panel { border-color: #aaa; break-inside: avoid; }
            .no-print, .back-link, button, .clear-link, .stale-hint { display: none !important; }
            .result-layout { grid-template-columns: 34% minmax(0, 1fr); }
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Расчёт двухзвенного полосового LC-фильтра (ДПФ)</h1>
        <p class="subtle">Расчёт по формулам исходной таблицы. Можно задать требуемую полосу пропускания или известную индуктивность одинаковых катушек. Для каждого режима сразу рассчитываются номиналы обеих топологий.</p>
        <a class="back-link" href="index.php">← К списку калькуляторов</a>
    </section>

    <section class="panel">
        <h2>Исходные данные</h2>
        <form id="dpf-form" method="post" action="">
            <div class="form-grid">
                <div class="field">
                    <label class="field-label" for="resistance">Импеданс, Ом</label>
                    <input class="value-input" id="resistance" name="resistance" type="text" inputmode="decimal" autocomplete="off" value="<?= dpf_h($resistanceRaw) ?>" required>
                </div>
                <div class="field">
                    <label class="field-label" for="frequency">Центральная частота Fc, МГц</label>
                    <input class="value-input" id="frequency" name="frequency" type="text" inputmode="decimal" autocomplete="off" value="<?= dpf_h($frequencyRaw) ?>" required>
                </div>
            </div>

            <div class="mode-third-grid">
                <div class="field">
                    <span class="field-label" id="mode-label">Вариант расчёта</span>
                    <div class="mode-switch" role="radiogroup" aria-labelledby="mode-label">
                        <label class="mode-choice">
                            <input type="radio" name="mode" value="bandwidth"<?= dpf_checked($mode, 'bandwidth') ?>>
                            <span>По полосе</span>
                        </label>
                        <label class="mode-choice">
                            <input type="radio" name="mode" value="inductance"<?= dpf_checked($mode, 'inductance') ?>>
                            <span>По L1</span>
                        </label>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" id="third-label" for="third_value"><?= $mode === 'bandwidth' ? 'Полоса пропускания ΔF, кГц' : 'Индуктивность каждой катушки L1, мкГн' ?></label>
                    <input class="value-input" id="third_value" name="third_value" type="text" inputmode="decimal" autocomplete="off" value="<?= dpf_h($thirdRaw) ?>" data-mode="<?= dpf_h($mode) ?>" required>
                    <span class="field-hint" id="third-hint"><?= $mode === 'bandwidth' ? 'Задайте требуемую полосу пропускания.' : 'Задайте индуктивность одной катушки; обе катушки имеют этот номинал.' ?></span>
                </div>
            </div>

            <div class="action-row">
                <button type="submit" name="calculate" value="1">Рассчитать</button>
                <a class="clear-link" href="dpf_filter.php">Сбросить значения</a>
            </div>
            <div class="stale-hint" id="stale-hint">Исходные данные изменены. Нажмите «Рассчитать», чтобы обновить результаты.</div>
        </form>
        <?php if ($error !== ''): ?><div class="error" id="calculation-error" role="alert"><?= dpf_h($error) ?></div><?php endif; ?>
    </section>

    <section class="panel result" id="calculation-result" aria-live="polite">
        <h2 class="result-heading">Результаты расчёта</h2>
        <p class="subtle result-intro" id="result-intro"><?php if ($result !== null): ?><?= $result['mode'] === 'bandwidth' ? 'Расчёт по заданной полосе пропускания.' : 'Расчёт по заданной индуктивности катушек.' ?> Обозначения на рисунке соответствуют спискам номиналов.<?php else: ?>Введите исходные данные и нажмите «Рассчитать». Здесь появятся рассчитанные параметры и номиналы элементов.<?php endif; ?></p>
        <div class="result-layout">
            <div class="diagram-wrap">
                <img class="diagram" src="images/dpf-filter-topologies.png" alt="Две топологии двухзвенного полосового фильтра: Т-образная с C1 и C2, П-образная с Ca и Cb; в каждой схеме две одинаковые катушки L1" width="439" height="439">
                <p class="diagram-caption">Обе катушки L1 в каждой схеме имеют одинаковую индуктивность. Повторяющиеся обозначения конденсаторов также означают одинаковые номиналы.</p>
            </div>
            <div class="result-values" id="result-values">
                <div class="summary-grid" aria-label="Общие параметры фильтра">
                    <div class="metric"><span class="metric-label">Центральная частота Fc</span><strong class="metric-value"><span data-result-value><?= $result !== null ? dpf_h(dpf_num($result['frequency'])) : '—' ?></span> МГц</strong></div>
                    <div class="metric"><span class="metric-label">Полоса ΔF</span><strong class="metric-value"><span data-result-value><?= $result !== null ? dpf_h(dpf_num($result['delta_f'])) : '—' ?></span> кГц</strong></div>
                    <div class="metric"><span class="metric-label">Нижняя частота F1</span><strong class="metric-value"><span data-result-value><?= $result !== null ? dpf_h(dpf_num($result['f1'])) : '—' ?></span> МГц</strong></div>
                    <div class="metric"><span class="metric-label">Верхняя частота F2</span><strong class="metric-value"><span data-result-value><?= $result !== null ? dpf_h(dpf_num($result['f2'])) : '—' ?></span> МГц</strong></div>
                    <div class="metric"><span class="metric-label">Индуктивность каждой L1</span><strong class="metric-value"><span data-result-value><?= $result !== null ? dpf_h(dpf_num($result['l1'])) : '—' ?></span> мкГн</strong></div>
                </div>
                <div class="topology-grid">
                    <section class="topology">
                        <h3>Т-образная топология</h3>
                        <div class="part-row">
                            <div class="part-label"><strong>C1</strong><span class="part-description">Два последовательных конденсатора</span></div>
                            <span class="part-value"><span data-result-value><?= $result !== null ? dpf_h(dpf_num($result['c1'])) : '—' ?></span> пФ</span>
                        </div>
                        <div class="part-row">
                            <div class="part-label"><strong>C2</strong><span class="part-description">Шунтирующий конденсатор</span></div>
                            <span class="part-value"><span data-result-value><?= $result !== null ? dpf_h(dpf_num($result['c2'])) : '—' ?></span> пФ</span>
                        </div>
                        <?php if ($result !== null && $error === ''): ?>
                        <form class="cct-export-form" method="post" action="">
                            <input type="hidden" name="resistance" value="<?= dpf_h($resistanceRaw) ?>">
                            <input type="hidden" name="frequency" value="<?= dpf_h($frequencyRaw) ?>">
                            <input type="hidden" name="mode" value="<?= dpf_h($mode) ?>">
                            <input type="hidden" name="third_value" value="<?= dpf_h($thirdRaw) ?>">
                            <input type="hidden" name="export_timestamp" value="">
                            <button class="cct-export-button" type="submit" name="export" value="T">Скачать схему для RFSim99 (.cct)</button>
                        </form>
                        <?php endif; ?>
                    </section>
                    <section class="topology">
                        <h3>П-образная топология</h3>
                        <div class="part-row">
                            <div class="part-label"><strong>Ca</strong><span class="part-description">Два шунтирующих конденсатора</span></div>
                            <span class="part-value"><span data-result-value><?= $result !== null ? dpf_h(dpf_num($result['ca'])) : '—' ?></span> пФ</span>
                        </div>
                        <div class="part-row">
                            <div class="part-label"><strong>Cb</strong><span class="part-description">Последовательный конденсатор</span></div>
                            <span class="part-value"><span data-result-value><?= $result !== null ? dpf_h(dpf_num($result['cb'])) : '—' ?></span> пФ</span>
                        </div>
                        <?php if ($result !== null && $error === ''): ?>
                        <form class="cct-export-form" method="post" action="">
                            <input type="hidden" name="resistance" value="<?= dpf_h($resistanceRaw) ?>">
                            <input type="hidden" name="frequency" value="<?= dpf_h($frequencyRaw) ?>">
                            <input type="hidden" name="mode" value="<?= dpf_h($mode) ?>">
                            <input type="hidden" name="third_value" value="<?= dpf_h($thirdRaw) ?>">
                            <input type="hidden" name="export_timestamp" value="">
                            <button class="cct-export-button" type="submit" name="export" value="P">Скачать схему для RFSim99 (.cct)</button>
                        </form>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </div>
    </section>

    <section class="panel">
        <h2>Как выполняется расчёт</h2>
        <ul class="formula-list">
            <li>По заданной полосе: L1 = R·1000 / (2π·ΔF).</li>
            <li>По заданной индуктивности: ΔF = R·1000 / (2π·L1).</li>
            <li>Граничные частоты: F1 = Fc − ΔF/2000; F2 = Fc + ΔF/2000.</li>
            <li>C2 = 10⁶/(2π·Fc·R); C1 = C2/(Fc·1000/ΔF − 1).</li>
            <li>Для П-образной схемы: Ca = C1·C2/(2·C1 + C2); Cb = C1²/(2·C1 + C2).</li>
        </ul>
        <p class="note" style="margin-top:14px"><strong>Единицы исходного расчёта:</strong> импеданс — Ом, частота — МГц, полоса — кГц, индуктивность — мкГн, ёмкости — пФ. Дробные значения принимаются и с запятой, и с точкой.</p>
    </section>
</main>
<script>
(() => {
    const form = document.getElementById('dpf-form');
    const third = document.getElementById('third_value');
    const thirdLabel = document.getElementById('third-label');
    const thirdHint = document.getElementById('third-hint');
    const modeInputs = [...document.querySelectorAll('input[name="mode"]')];
    const result = document.getElementById('calculation-result');
    const error = document.getElementById('calculation-error');
    const staleHint = document.getElementById('stale-hint');
    const defaults = { bandwidth: '4000', inductance: '100' };
    const currentMode = () => modeInputs.find(input => input.checked)?.value || 'bandwidth';
    const values = { bandwidth: defaults.bandwidth, inductance: defaults.inductance };
    let activeMode = currentMode();
    values[activeMode] = third.value;

    function updateThirdField(nextMode, rememberCurrent = true) {
        if (rememberCurrent) values[activeMode] = third.value;
        activeMode = nextMode;
        if (nextMode === 'bandwidth') {
            thirdLabel.textContent = 'Полоса пропускания ΔF, кГц';
            thirdHint.textContent = 'Задайте требуемую полосу пропускания.';
        } else {
            thirdLabel.textContent = 'Индуктивность каждой катушки L1, мкГн';
            thirdHint.textContent = 'Задайте индуктивность одной катушки; обе катушки имеют этот номинал.';
        }
        third.value = values[nextMode] ?? defaults[nextMode];
        third.placeholder = nextMode === 'bandwidth' ? 'Например, 100' : 'Например, 10';
    }

    modeInputs.forEach(input => input.addEventListener('change', () => {
        updateThirdField(input.value);
        markOutdated();
    }));

    function markOutdated() {
        if (result) {
            result.querySelectorAll('[data-result-value]').forEach(value => {
                value.textContent = '—';
            });
            const intro = document.getElementById('result-intro');
            if (intro) intro.textContent = 'Исходные данные изменены. Нажмите «Рассчитать», чтобы получить актуальные результаты.';
        }
        if (error) error.hidden = true;
        if (staleHint && (result || error)) staleHint.style.display = 'block';
        document.querySelectorAll('.cct-export-form').forEach(exportForm => {
            exportForm.hidden = true;
        });
    }

    if (result || error) {
        form.querySelectorAll('input').forEach(control => {
            control.addEventListener('input', markOutdated);
        });
    }

    document.querySelectorAll('.cct-export-form').forEach(exportForm => {
        exportForm.addEventListener('submit', () => {
            const now = new Date();
            const pad = value => String(value).padStart(2, '0');
            const timestamp = [
                now.getFullYear(),
                pad(now.getMonth() + 1),
                pad(now.getDate())
            ].join('') + '_' + [
                pad(now.getHours()),
                pad(now.getMinutes()),
                pad(now.getSeconds())
            ].join('');
            const timestampInput = exportForm.querySelector('input[name="export_timestamp"]');
            if (timestampInput) timestampInput.value = timestamp;
        });
    });

    const shouldScrollToResults = <?= ($result !== null && $error === '' && !$isExportRequest) ? 'true' : 'false' ?>;
    if (shouldScrollToResults) {
        requestAnimationFrame(() => requestAnimationFrame(() => {
            const resultPanel = document.getElementById('calculation-result');
            if (resultPanel) {
                resultPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }));
    }
})();
</script>
</body>
</html>
