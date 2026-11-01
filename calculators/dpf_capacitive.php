<?php
/*
 * Двухконтурный полосовой LC-фильтр с ёмкостными делителями
 * и общей нижней точкой катушек, подключённой к земле через C5.
 * Расчёт идеализированный: потери и паразитные параметры не учитываются.
 */
declare(strict_types=1);

require_once __DIR__ . '/cct_frequency_axis.php';

function dpc_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function dpc_parse_number(string $raw): ?float
{
    $raw = str_replace([' ', "\u{00A0}", "\u{202F}"], '', trim($raw));
    $raw = str_replace(',', '.', $raw);
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    $value = (float) $raw;
    return ($value > 0.0 && is_finite($value)) ? $value : null;
}

function dpc_num(float $value, int $decimals = 3): string
{
    if (!is_finite($value)) {
        return '—';
    }
    $text = number_format($value, $decimals, ',', ' ');
    return rtrim(rtrim($text, '0'), ',');
}

function dpc_cap_pf(float $farads): string
{
    // Все ёмкости показываются в пФ и округляются до целого пикофарада.
    return number_format((int) round($farads * 1e12), 0, ',', ' ') . ' пФ';
}

function dpc_frequency_mhz(float $hz, int $decimals = 6): string
{
    return dpc_num($hz / 1e6, $decimals) . ' МГц';
}

/** Частота, соответствующая нормированной расстройке Омега. */
function dpc_frequency_for_omega(float $fc, float $bandwidth, float $omega): float
{
    $a = $omega * $bandwidth / $fc;
    $ratio = ($a + sqrt($a * $a + 4.0)) / 2.0;
    return $fc * $ratio;
}

/**
 * Возвращает S21 в дБ и комплексный S11 для точной идеальной модели
 * нашей топологии. Узлы: входной отвод, горячие точки L1/L2,
 * выходной отвод и общая нижняя точка катушек.
 *
 * Для удобства расчёта все узловые проводимости сведены к вещественным
 * коэффициентам при j. Комплексная арифметика вручную не требуется.
 */
function dpc_response(float $frequency, array $x, float $rin, float $rout): ?array
{
    [$inductance, $c1, $c2, $c3, $c4, $c5] = $x;
    if ($frequency <= 0.0 || $inductance <= 0.0 || min($c1, $c2, $c3, $c4, $c5) <= 0.0) {
        return null;
    }

    $w = 2.0 * M_PI * $frequency;
    $g = 1.0 / ($w * $inductance);
    $a = $w * $c1 - $g;
    $b = $w * $c3 - $g;
    $d = $w * $c5 - 2.0 * $g;
    $det = -$a * $b * $d + ($a + $b) * $g * $g;

    $scale = max(1.0, abs($a * $b * $d), abs(($a + $b) * $g * $g));
    if (!is_finite($det) || abs($det) < 1e-18 * $scale) {
        return null;
    }

    $wC1 = $w * $c1;
    $wC2 = $w * $c2;
    $wC3 = $w * $c3;
    $wC4 = $w * $c4;

    // Матрица проводимостей после исключения общего нижнего узла.
    $y11 = $wC1 + $wC2 + ($wC1 * $wC1) * ($b * $d - $g * $g) / $det;
    $y22 = $wC3 + $wC4 + ($wC3 * $wC3) * ($a * $d - $g * $g) / $det;
    $y12 = $wC1 * $wC3 * $g * $g / $det;

    $u = $rin * $y11;
    $v = $rout * $y22;
    $t = sqrt($rin * $rout) * $y12;

    // S-параметры для двух разных вещественных опорных сопротивлений.
    $denRe = 1.0 - $u * $v + $t * $t;
    $denIm = $u + $v;
    $den2 = $denRe * $denRe + $denIm * $denIm;
    if (!is_finite($den2) || $den2 < 1e-30) {
        return null;
    }

    $s21 = 2.0 * abs($t) / sqrt($den2);
    $numRe = 1.0 + $u * $v - $t * $t;
    $numIm = $v - $u;
    $s11Re = ($numRe * $denRe + $numIm * $denIm) / $den2;
    $s11Im = ($numIm * $denRe - $numRe * $denIm) / $den2;

    if (!is_finite($s21) || !is_finite($s11Re) || !is_finite($s11Im)) {
        return null;
    }

    $s21Db = 20.0 * log10(max($s21, 1e-15));
    $s11Db = 20.0 * log10(max(hypot($s11Re, $s11Im), 1e-15));

    // Для идеальной взаимной без потерь двухпортовой сети |S11| = |S22|.
    return [
        's21' => $s21Db,
        's11_re' => $s11Re,
        's11_im' => $s11Im,
        's11_db' => $s11Db,
        's22_db' => $s11Db,
    ];
}

/** Целевая характеристика двухполюсного Баттерворта, дБ. */
function dpc_butterworth_db(float $omega): float
{
    return -10.0 * log10(1.0 + $omega ** 4);
}

/** Остатки для численного синтеза: согласование центра и форма Баттерворта. */
function dpc_residual(array $z, float $fc, float $bandwidth, float $rin, float $rout, float $lReference): ?array
{
    $x = array_map('exp', $z);
    foreach ($x as $value) {
        if (!is_finite($value) || $value <= 0.0) {
            return null;
        }
    }

    $centre = dpc_response($fc, $x, $rin, $rout);
    if ($centre === null) {
        return null;
    }

    $residual = [
        $centre['s11_re'],
        $centre['s11_im'],
    ];

    // Точки по нормированной полосе: границы -3,0103 дБ и две точки
    // около центра для получения гладкой, почти плоской вершины.
    foreach ([-1.0, -0.2, 0.2, 1.0] as $omega) {
        $frequency = dpc_frequency_for_omega($fc, $bandwidth, $omega);
        $response = dpc_response($frequency, $x, $rin, $rout);
        if ($response === null) {
            return null;
        }
        $residual[] = $response['s21'] - dpc_butterworth_db($omega);
    }

    // Выбор масштаба L: ориентир X_L около 300 Ом на центральной частоте.
    // Это устраняет произвольность абсолютного масштаба L/C и даёт
    // практичные номиналы. Остаток мягкий, а не жёсткое ограничение.
    $residual[] = 0.5 * log($x[0] / $lReference);
    return $residual;
}

/** Решение небольшой плотной системы линейных уравнений методом Гаусса. */
function dpc_linear_solve(array $matrix, array $rhs): ?array
{
    $n = count($rhs);
    for ($col = 0; $col < $n; $col++) {
        $pivot = $col;
        for ($row = $col + 1; $row < $n; $row++) {
            if (abs($matrix[$row][$col]) > abs($matrix[$pivot][$col])) {
                $pivot = $row;
            }
        }
        if (abs($matrix[$pivot][$col]) < 1e-18 || !is_finite($matrix[$pivot][$col])) {
            return null;
        }
        if ($pivot !== $col) {
            [$matrix[$pivot], $matrix[$col]] = [$matrix[$col], $matrix[$pivot]];
            [$rhs[$pivot], $rhs[$col]] = [$rhs[$col], $rhs[$pivot]];
        }

        $divisor = $matrix[$col][$col];
        for ($j = $col; $j < $n; $j++) {
            $matrix[$col][$j] /= $divisor;
        }
        $rhs[$col] /= $divisor;

        for ($row = 0; $row < $n; $row++) {
            if ($row === $col) {
                continue;
            }
            $factor = $matrix[$row][$col];
            if ($factor == 0.0) {
                continue;
            }
            for ($j = $col; $j < $n; $j++) {
                $matrix[$row][$j] -= $factor * $matrix[$col][$j];
            }
            $rhs[$row] -= $factor * $rhs[$col];
        }
    }
    foreach ($rhs as $value) {
        if (!is_finite($value)) {
            return null;
        }
    }
    return $rhs;
}

function dpc_vector_cost(array $values): float
{
    $sum = 0.0;
    foreach ($values as $value) {
        $sum += $value * $value;
    }
    return $sum;
}

/**
 * Один запуск метода Левенберга — Марквардта в логарифмах номиналов.
 * Все шесть компонентов положительны по определению.
 */
function dpc_lm_run(float $fc, float $bandwidth, float $rin, float $rout, array $initial, float $lReference): ?array
{
    $lower = array_map('log', [0.05e-6, 0.1e-12, 0.1e-12, 0.1e-12, 0.1e-12, 0.1e-12]);
    $upper = array_map('log', [500e-6, 10000e-12, 100000e-12, 10000e-12, 100000e-12, 100000e-12]);
    $z = array_map('log', $initial);
    foreach ($z as $i => $value) {
        $z[$i] = max($lower[$i], min($upper[$i], $value));
    }

    $r = dpc_residual($z, $fc, $bandwidth, $rin, $rout, $lReference);
    if ($r === null) {
        return null;
    }
    $cost = dpc_vector_cost($r);
    $lambda = 1e-2;
    $iterations = 0;

    for ($iteration = 0; $iteration < 180; $iteration++) {
        $iterations = $iteration + 1;
        $m = count($r);
        $n = 6;
        $jacobian = array_fill(0, $m, array_fill(0, $n, 0.0));
        $step = 1e-5;

        for ($column = 0; $column < $n; $column++) {
            $zPlus = $z;
            $zMinus = $z;
            $zPlus[$column] += $step;
            $zMinus[$column] -= $step;
            $rPlus = dpc_residual($zPlus, $fc, $bandwidth, $rin, $rout, $lReference);
            $rMinus = dpc_residual($zMinus, $fc, $bandwidth, $rin, $rout, $lReference);
            if ($rPlus === null || $rMinus === null) {
                return null;
            }
            for ($row = 0; $row < $m; $row++) {
                $jacobian[$row][$column] = ($rPlus[$row] - $rMinus[$row]) / (2.0 * $step);
            }
        }

        $normal = array_fill(0, $n, array_fill(0, $n, 0.0));
        $gradient = array_fill(0, $n, 0.0);
        for ($row = 0; $row < $m; $row++) {
            for ($i = 0; $i < $n; $i++) {
                $gradient[$i] += $jacobian[$row][$i] * $r[$row];
                for ($j = 0; $j < $n; $j++) {
                    $normal[$i][$j] += $jacobian[$row][$i] * $jacobian[$row][$j];
                }
            }
        }

        $damped = $normal;
        for ($i = 0; $i < $n; $i++) {
            $damped[$i][$i] += $lambda * max($normal[$i][$i], 1e-8);
            $gradient[$i] = -$gradient[$i];
        }
        $delta = dpc_linear_solve($damped, $gradient);
        if ($delta === null) {
            $lambda = min($lambda * 10.0, 1e12);
            continue;
        }

        $zTrial = $z;
        $maxDelta = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $delta[$i] = max(-0.5, min(0.5, $delta[$i]));
            $maxDelta = max($maxDelta, abs($delta[$i]));
            $zTrial[$i] = max($lower[$i], min($upper[$i], $z[$i] + $delta[$i]));
        }

        $rTrial = dpc_residual($zTrial, $fc, $bandwidth, $rin, $rout, $lReference);
        if ($rTrial === null) {
            $lambda = min($lambda * 10.0, 1e12);
            continue;
        }
        $trialCost = dpc_vector_cost($rTrial);
        if ($trialCost < $cost) {
            $improvement = $cost - $trialCost;
            $z = $zTrial;
            $r = $rTrial;
            $cost = $trialCost;
            $lambda = max($lambda / 3.0, 1e-9);
            if ($maxDelta < 1e-8 || $improvement < 1e-13 || $cost < 1e-9) {
                break;
            }
        } else {
            $lambda = min($lambda * 10.0, 1e12);
            if ($lambda >= 1e11) {
                break;
            }
        }
    }

    $values = array_map('exp', $z);
    return [
        'values' => $values,
        'residual' => $r,
        'cost' => $cost,
        'iterations' => $iterations,
    ];
}

/** Подбирает L и пять ёмкостей по идеальной двухполюсной характеристике. */
function dpc_synthesize(float $fc, float $bandwidth, float $rin, float $rout): ?array
{
    $lReference = 300.0 / (2.0 * M_PI * $fc);
    $cReference = 1.0 / ((2.0 * M_PI * $fc) ** 2 * $lReference);

    // Несколько детерминированных начальных наборов повышают устойчивость
    // численного подбора при разных отношениях входного/выходного импедансов.
    $seeds = [
        [$lReference, 1.1 * $cReference, 13.0 * $cReference, 1.2 * $cReference, 7.0 * $cReference, 35.0 * $cReference],
        [0.85 * $lReference, 0.9 * $cReference, 16.0 * $cReference, 1.0 * $cReference, 8.5 * $cReference, 42.0 * $cReference],
        [1.15 * $lReference, 1.3 * $cReference, 10.0 * $cReference, 1.4 * $cReference, 5.5 * $cReference, 29.0 * $cReference],
    ];

    $best = null;
    foreach ($seeds as $seed) {
        $candidate = dpc_lm_run($fc, $bandwidth, $rin, $rout, $seed, $lReference);
        if ($candidate !== null && ($best === null || $candidate['cost'] < $best['cost'])) {
            $best = $candidate;
        }
    }

    if ($best === null || !is_finite($best['cost'])) {
        return null;
    }
    // Если остаток слишком велик, не показываем сомнительный набор номиналов.
    if (sqrt($best['cost']) > 0.035) {
        return null;
    }

    $values = $best['values'];
    $centre = dpc_response($fc, $values, $rin, $rout);
    $f1 = dpc_frequency_for_omega($fc, $bandwidth, -1.0);
    $f2 = dpc_frequency_for_omega($fc, $bandwidth, 1.0);
    $lower = dpc_response($f1, $values, $rin, $rout);
    $upper = dpc_response($f2, $values, $rin, $rout);
    if ($centre === null || $lower === null || $upper === null) {
        return null;
    }

    return [
        'L' => $values[0], 'C1' => $values[1], 'C2' => $values[2],
        'C3' => $values[3], 'C4' => $values[4], 'C5' => $values[5],
        'f1' => $f1, 'f2' => $f2,
        'centre' => $centre, 'lower' => $lower, 'upper' => $upper,
        'fit_error' => sqrt($best['cost']),
        'iterations' => $best['iterations'],
    ];
}

/** Строит SVG АЧХ S21 с отметками центральной частоты и границ -3 дБ. */
function dpc_build_chart(array $result, float $fc, float $bandwidth, float $rin, float $rout): string
{
    $x = [$result['L'], $result['C1'], $result['C2'], $result['C3'], $result['C4'], $result['C5']];
    $fMin = dpc_frequency_for_omega($fc, $bandwidth, -2.3);
    $fMax = dpc_frequency_for_omega($fc, $bandwidth, 2.3);
    $samples = [];
    $minimum = 0.0;
    for ($i = 0; $i <= 240; $i++) {
        $omega = -2.3 + 4.6 * $i / 240.0;
        $frequency = dpc_frequency_for_omega($fc, $bandwidth, $omega);
        $response = dpc_response($frequency, $x, $rin, $rout);
        $db = $response === null ? -40.0 : $response['s21'];
        $samples[] = [$frequency, $db];
        $minimum = min($minimum, $db);
    }

    $yMin = max(-40.0, floor(($minimum - 2.0) / 5.0) * 5.0);
    $yMax = 1.0;
    $left = 62.0; $right = 16.0; $top = 18.0; $bottom = 43.0;
    $width = 940.0; $height = 330.0;
    $plotW = $width - $left - $right;
    $plotH = $height - $top - $bottom;
    $xMap = static fn(float $f): float => $left + ($f - $fMin) / ($fMax - $fMin) * $plotW;
    $yMap = static fn(float $db): float => $top + ($yMax - $db) / ($yMax - $yMin) * $plotH;

    $svg = '<svg class="response-svg" xmlns="http://www.w3.org/2000/svg" width="940" height="330" viewBox="0 0 940 330" role="img" aria-label="Расчётная амплитудно-частотная характеристика S21">';
    $svg .= '<rect x="0" y="0" width="940" height="330" fill="#fff"/>';
    for ($db = 0.0; $db >= $yMin; $db -= 5.0) {
        $y = $yMap($db);
        $svg .= '<line x1="' . $left . '" y1="' . $y . '" x2="' . ($width - $right) . '" y2="' . $y . '" stroke="#e4e8eb" stroke-width="1"/>';
        $svg .= '<text x="' . ($left - 10) . '" y="' . ($y + 4) . '" font-size="12" text-anchor="end" fill="#5f6368">' . dpc_h((string) (int) $db) . '</text>';
    }

    $ticks = [$fMin, $result['f1'], $fc, $result['f2'], $fMax];
    foreach ($ticks as $index => $frequency) {
        $xPos = $xMap($frequency);
        $svg .= '<line x1="' . $xPos . '" y1="' . $top . '" x2="' . $xPos . '" y2="' . ($height - $bottom) . '" stroke="#eef0f2" stroke-width="1"/>';
        $anchor = $index === 0 ? 'start' : ($index === 4 ? 'end' : 'middle');
        $svg .= '<text x="' . $xPos . '" y="' . ($height - 17) . '" font-size="11" text-anchor="' . $anchor . '" fill="#5f6368">' . dpc_h(dpc_num($frequency / 1e6, 3)) . '</text>';
    }

    $y3 = $yMap(-3.0103);
    $svg .= '<line x1="' . $left . '" y1="' . $y3 . '" x2="' . ($width - $right) . '" y2="' . $y3 . '" stroke="#89939c" stroke-width="1.5" stroke-dasharray="6 5"/>';
    foreach ([$result['f1'], $result['f2']] as $boundaryFrequency) {
        $boundaryX = $xMap($boundaryFrequency);
        $svg .= '<line x1="' . $boundaryX . '" y1="' . $top . '" x2="' . $boundaryX . '" y2="' . ($height - $bottom) . '" stroke="#89939c" stroke-width="1.2" stroke-dasharray="4 5"/>';
    }
    $svg .= '<line x1="' . $xMap($fc) . '" y1="' . $top . '" x2="' . $xMap($fc) . '" y2="' . ($height - $bottom) . '" stroke="#c25b24" stroke-width="1.5" stroke-dasharray="4 5"/>';
    $svg .= '<line x1="' . $left . '" y1="' . ($height - $bottom) . '" x2="' . ($width - $right) . '" y2="' . ($height - $bottom) . '" stroke="#7b858d" stroke-width="1.2"/>';
    $svg .= '<line x1="' . $left . '" y1="' . $top . '" x2="' . $left . '" y2="' . ($height - $bottom) . '" stroke="#7b858d" stroke-width="1.2"/>';

    $points = [];
    foreach ($samples as [$frequency, $db]) {
        $points[] = sprintf('%.2f,%.2f', $xMap($frequency), $yMap(max($yMin, min($yMax, $db))));
    }
    $svg .= '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="#245c88" stroke-width="2.7" stroke-linejoin="round" stroke-linecap="round"/>';
    $svg .= '<text x="' . ($width / 2) . '" y="' . ($height - 2) . '" text-anchor="middle" font-size="12" fill="#5f6368">Частота, МГц</text>';
    $svg .= '<text x="16" y="' . ($height / 2) . '" text-anchor="middle" font-size="12" fill="#5f6368" transform="rotate(-90 16 ' . ($height / 2) . ')">S21, дБ</text>';
    $svg .= '</svg>';
    return $svg;
}

/** Форматирует число в научной записи RFSim99 (четыре цифры показателя). */
function dpc_rf_number(float $value): string
{
    if (!is_finite($value)) {
        throw new RuntimeException('В CCT передано некорректное числовое значение.');
    }

    $formatted = sprintf('%.14E', $value);
    if (!preg_match('/^(.*E)([+-])(\d+)$/', $formatted, $matches)) {
        throw new RuntimeException('Не удалось отформатировать число для RFSim99.');
    }

    return $matches[1] . $matches[2] . str_pad($matches[3], 4, '0', STR_PAD_LEFT);
}

/** Заменяет ровно одно поле внутри единственного блока с указанным именем. */
function dpc_replace_cct_block_field(string &$cct, string $blockName, string $fieldName, float $value): void
{
    $blockPattern = '/^' . preg_quote($blockName, '/') . '\\{\\r?\\n.*?^\\}/ms';
    $blockCount = 0;
    $fieldCount = 0;
    $formatted = dpc_rf_number($value);

    $updated = preg_replace_callback(
        $blockPattern,
        static function (array $matches) use ($fieldName, $formatted, &$fieldCount): string {
            $fieldPattern = '/(^[ \\t]*' . preg_quote($fieldName, '/') . '=[ \\t]*)[^\\r\\n]*/m';
            $fieldMatches = 0;
            $block = preg_replace_callback(
                $fieldPattern,
                static fn(array $fieldMatch): string => $fieldMatch[1] . $formatted,
                $matches[0],
                1,
                $fieldMatches
            );
            $fieldCount += $fieldMatches;
            return $block ?? $matches[0];
        },
        $cct,
        -1,
        $blockCount
    );

    if ($updated === null || $blockCount !== 1 || $fieldCount !== 1) {
        throw new RuntimeException('В шаблоне bpf2.cct не найдено единственное поле ' . $blockName . '.' . $fieldName . '.');
    }

    $cct = $updated;
}

/** Меняет номинал только у компонента с точно заданными координатами. */
function dpc_replace_cct_component_nominal(string &$cct, int $x, int $y, float $value): void
{
    $componentPattern = '/^component\\{\\r?\\n.*?^\\}/ms';
    $componentCount = 0;
    $targetCount = 0;
    $nominalCount = 0;
    $formatted = dpc_rf_number($value);

    $updated = preg_replace_callback(
        $componentPattern,
        static function (array $matches) use ($x, $y, $formatted, &$targetCount, &$nominalCount): string {
            $block = $matches[0];
            $xFound = preg_match('/^position\\.x=' . $x . '\\r?$/m', $block) === 1;
            $yFound = preg_match('/^position\\.y=' . $y . '\\r?$/m', $block) === 1;
            if (!$xFound || !$yFound) {
                return $block;
            }

            $targetCount++;
            $nominalMatches = 0;
            $updatedBlock = preg_replace_callback(
                '/(^[ \\t]*value\\[1\\]=\\{nom=[ \\t]*)[^;]+/m',
                static fn(array $nominalMatch): string => $nominalMatch[1] . $formatted,
                $block,
                1,
                $nominalMatches
            );
            $nominalCount += $nominalMatches;
            return $updatedBlock ?? $block;
        },
        $cct,
        -1,
        $componentCount
    );

    if ($updated === null || $componentCount < 1 || $targetCount !== 1 || $nominalCount !== 1) {
        throw new RuntimeException('В шаблоне bpf2.cct не найден единственный компонент в позиции (' . $x . ', ' . $y . ').');
    }

    $cct = $updated;
}

/** Формирует CCT, меняя только семь номиналов, два Zo и границы Xax. */
function dpc_build_cct(
    string $template,
    array $result,
    float $fcHz,
    float $bandwidthHz,
    float $rin,
    float $rout
): string {
    // Используем ту же точность, что и в таблице калькулятора:
    // L — до 0,001 мкГн, C — до 1 пФ.
    $inductance = round($result['L'] * 1e6, 3) * 1e-6;
    $capacitors = [
        'C1' => round($result['C1'] * 1e12) * 1e-12,
        'C2' => round($result['C2'] * 1e12) * 1e-12,
        'C3' => round($result['C3'] * 1e12) * 1e-12,
        'C4' => round($result['C4'] * 1e12) * 1e-12,
        'C5' => round($result['C5'] * 1e12) * 1e-12,
    ];

    // Позиции компонентов совпадают с проверенным шаблоном RFSim99.
    dpc_replace_cct_component_nominal($template, 90, 70, $capacitors['C1']);
    dpc_replace_cct_component_nominal($template, 90, 110, $capacitors['C2']);
    dpc_replace_cct_component_nominal($template, 560, 70, $capacitors['C3']);
    dpc_replace_cct_component_nominal($template, 560, 110, $capacitors['C4']);
    dpc_replace_cct_component_nominal($template, 320, 70, $capacitors['C5']);
    dpc_replace_cct_component_nominal($template, 220, 50, $inductance);
    dpc_replace_cct_component_nominal($template, 420, 50, $inductance);

    dpc_replace_cct_block_field($template, 'P1', 'Zo', $rin);
    dpc_replace_cct_block_field($template, 'P2', 'Zo', $rout);

    // Округляем границы окна АЧХ по общему правилу для всех CCT-экспортов.
    [$axisMin, $axisMax] = cct_frequency_axis_bounds(
        $fcHz - 2.0 * $bandwidthHz,
        $fcHz + 2.0 * $bandwidthHz
    );
    dpc_replace_cct_block_field($template, 'Xax', 'max', $axisMax);
    dpc_replace_cct_block_field($template, 'Xax', 'min', $axisMin);

    return $template;
}

/** Отдаёт понятную ошибку при запросе скачивания и прекращает выполнение. */
function dpc_cct_download_error(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $message;
    exit;
}

$defaultValues = [
    'fc' => '14',
    'bandwidth' => '500',
    'rin' => '50',
    'rout' => '200',
];
$values = $defaultValues;
$error = '';
$result = null;
$isPost = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST');
$isDownloadRequest = $isPost && (string) ($_POST['action'] ?? '') === 'download_cct';

if ($isPost) {
    foreach ($defaultValues as $key => $default) {
        $values[$key] = trim((string) ($_POST[$key] ?? $default));
    }
    $fcInput = dpc_parse_number($values['fc']);
    $bwInput = dpc_parse_number($values['bandwidth']);
    $rin = dpc_parse_number($values['rin']);
    $rout = dpc_parse_number($values['rout']);

    if ($fcInput === null || $fcInput < 0.5 || $fcInput > 100.0) {
        $error = 'Введите центральную частоту от 0,5 до 100 МГц.';
    } elseif ($bwInput === null || $bwInput < 1.0) {
        $error = 'Введите полосу пропускания не менее 1 кГц.';
    } elseif ($bwInput >= $fcInput * 1000.0 * 0.15) {
        $error = 'Для этой идеализированной узкополосной модели относительная полоса должна быть меньше 15%.';
    } elseif ($rin === null || $rin < 5.0 || $rin > 5000.0) {
        $error = 'Входной импеданс должен быть от 5 до 5000 Ом.';
    } elseif ($rout === null || $rout < 5.0 || $rout > 5000.0) {
        $error = 'Выходной импеданс должен быть от 5 до 5000 Ом.';
    } else {
        $fc = $fcInput * 1e6;
        $bandwidth = $bwInput * 1e3;
        $result = dpc_synthesize($fc, $bandwidth, $rin, $rout);
        if ($result === null) {
            $error = 'Не удалось подобрать устойчивый комплект номиналов для этих параметров. Попробуйте изменить полосу или импедансы.';
        }
    }
}

$chartSvg = '';
if ($result !== null) {
    $chartSvg = dpc_build_chart(
        $result,
        (float) $fcInput * 1e6,
        (float) $bwInput * 1e3,
        (float) $rin,
        (float) $rout
    );
}

// Запрос скачивания повторяет тот же расчёт по значениям из скрытой формы.
// Поэтому файл всегда соответствует тем параметрам, которые показаны на странице.
if ($isDownloadRequest) {
    if ($error !== '' || $result === null) {
        dpc_cct_download_error(400, $error !== '' ? $error : 'Не удалось выполнить расчёт для формирования CCT.');
    }

    $clientTimestamp = trim((string) ($_POST['client_timestamp'] ?? ''));
    if (!preg_match('/^\\d{8}_\\d{6}$/', $clientTimestamp)) {
        dpc_cct_download_error(400, 'Не удалось получить местные дату и время компьютера. Повторите скачивание.');
    }
    $downloadFilename = 'BPF2_' . $clientTimestamp . '.cct';

    // dpf_capacitive.php расположен в /calculators/, шаблоны — templates/.
    $templatePath = __DIR__ . '/templates/bpf2.cct';
    if (!is_file($templatePath) || !is_readable($templatePath)) {
        dpc_cct_download_error(500, 'Не найден рабочий шаблон /templates/bpf2.cct или нет доступа на чтение.');
    }

    $template = file_get_contents($templatePath);
    if ($template === false || $template === '') {
        dpc_cct_download_error(500, 'Не удалось прочитать шаблон /templates/bpf2.cct.');
    }

    try {
        $cct = dpc_build_cct(
            $template,
            $result,
            (float) $fcInput * 1e6,
            (float) $bwInput * 1e3,
            (float) $rin,
            (float) $rout
        );
    } catch (Throwable $exception) {
        error_log('RFSim99 CCT generation failed: ' . $exception->getMessage());
        dpc_cct_download_error(500, 'Не удалось сформировать CCT: структура шаблона не соответствует ожидаемой. Проверьте /templates/bpf2.cct.');
    }

    header('Content-Type: application/octet-stream');
    header('Content-Description: File Transfer');
    header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
    header('Content-Length: ' . strlen($cct));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $cct;
    exit;
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Двухконтурный ДПФ с ёмкостной связью — радиолюбительские калькуляторы</title>
    <meta name="description" content="Расчёт двухконтурного полосового LC-фильтра с ёмкостными делителями и общей нижней точкой катушек.">
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #202124; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 1000px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #d7dce1; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: clamp(1.45rem, 3vw, 2rem); line-height: 1.25; }
        h2 { margin: 0 0 12px; font-size: 1.15rem; line-height: 1.3; }
        h3 { margin: 0 0 5px; font-size: .98rem; }
        p { line-height: 1.5; }
        .subtle { color: #5f6368; }
        .back-link { display: inline-block; margin-top: 4px; color: #244f76; font-weight: 700; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        a:focus-visible, button:focus-visible, input:focus-visible { outline: 3px solid #89bde5; outline-offset: 2px; }
        .work-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 20px; align-items: start; }
        .diagram-wrap { min-width: 0; padding: 8px; border: 1px solid #d7dce1; border-radius: 8px; background: #fff; }
        .diagram { display: block; width: 100%; height: auto; }
        .diagram-caption { margin: 8px 2px 1px; color: #69727b; font-size: .8rem; line-height: 1.4; }
        .input-area { min-width: 0; }
        .input-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
        .field { min-width: 0; }
        .field-label { display: block; margin-bottom: 5px; font-size: .82rem; font-weight: 700; line-height: 1.3; }
        input, button { font: inherit; }
        .value-input { display: block; width: 100%; min-width: 0; min-height: 38px; border: 1px solid #b8c0c8; border-radius: 6px; padding: 7px 9px; background: #fff; color: #202124; }
        .value-input:focus { border-color: #3976a8; outline: 3px solid #c5e2f8; }
        .input-hint { margin-top: 4px; color: #69727b; font-size: .74rem; line-height: 1.35; }
        .action-row { margin-top: 14px; }
        button { display: inline-flex; align-items: center; justify-content: center; width: 100%; min-height: 39px; border: 0; border-radius: 6px; padding: 8px 12px; color: #fff; background: #245c88; font-size: .9rem; font-weight: 700; cursor: pointer; }
        button:hover { background: #19476c; }
        .error { margin-top: 12px; padding: 10px 12px; border: 1px solid #e4b7b7; border-radius: 7px; background: #fff5f5; color: #8b2323; font-size: .88rem; line-height: 1.45; }
        .result-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 9px; margin-bottom: 14px; }
        .result-item { min-width: 0; padding: 9px 10px; border: 1px solid #e0e5e9; border-radius: 7px; background: #fff; }
        .result-label { display: block; margin-bottom: 5px; color: #626b73; font-size: .76rem; line-height: 1.25; }
        .result-value { display: block; color: #202124; font-size: 1rem; font-weight: 700; line-height: 1.25; overflow-wrap: anywhere; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { padding: 9px 10px; border-bottom: 1px solid #e0e5e9; text-align: left; vertical-align: middle; }
        th { color: #4e5962; font-size: .8rem; font-weight: 700; }
        td:nth-child(2) { font-weight: 700; white-space: nowrap; }
        .chart-panel { margin-top: 16px; }
        .chart-subtitle { margin: 0 0 10px; color: #69727b; font-size: .82rem; line-height: 1.4; }
        .chart-legend { display: flex; flex-wrap: wrap; gap: 8px 17px; margin: 0 0 7px; color: #5f6368; font-size: .8rem; }
        .legend-item { display: inline-flex; align-items: center; gap: 6px; }
        .swatch { display: inline-block; width: 23px; height: 0; border-top: 2.5px solid #245c88; }
        .swatch.limit { border-top: 2px dashed #89939c; }
        .swatch.centre { border-top: 2px dashed #c25b24; }
        .chart-frame { width: 100%; border: 1px solid #e0e5e9; border-radius: 7px; background: #fff; overflow: hidden; }
        .response-svg { display: block; width: 100%; height: auto; }
        .note { margin: 12px 2px 0; color: #69727b; font-size: .82rem; line-height: 1.45; }
        .download-form { margin-top: 18px; }
        .download-row { display: flex; justify-content: center; }
        button.download-button { width: 100%; max-width: 440px; background: #28623f; }
        button.download-button:hover { background: #1f4e32; }
        @media (max-width: 760px) {
            body { padding: 10px; }
            .panel { padding: 15px; }
            .work-layout { grid-template-columns: minmax(0, 1fr); gap: 14px; }
            .diagram-wrap { max-width: 100%; }
            .action-row { flex-direction: column; align-items: stretch; }
            .action-row button { width: 100%; }
            .diagram { max-height: 190px; object-fit: contain; }
            .result-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 480px) {
            .input-grid { grid-template-columns: minmax(0, 1fr); gap: 9px; }
            th, td { padding: 8px 6px; font-size: .82rem; }
            .chart-legend { font-size: .74rem; gap: 7px 12px; }
             .action-row { flex-direction: column; align-items: stretch; }
             .action-row button { width: 100%; }
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Двухконтурный полосовой LC-фильтр</h1>
        <p class="subtle">Синтез ДПФ с двумя одинаковыми катушками, независимыми ёмкостными делителями и общей нижней точкой катушек, подключённой к земле через C5.</p>
        <a class="back-link" href="index.php">← К списку калькуляторов</a>
    </section>

    <form method="post" action="dpf_capacitive.php">
        <section class="panel work-layout">
            <div class="diagram-wrap">
                <img class="diagram" src="images/dpf-capacitive-common.svg" alt="Двухконтурный полосовой фильтр с входным и выходным ёмкостными делителями и общим конденсатором C5 в нижней цепи катушек" width="720" height="480">
                <p class="diagram-caption">Катушки L1 и L2 одинаковы. Их нижние выводы объединены и подключены к земле через C5.</p>
            </div>
            <div class="input-area">
                <h2>Исходные данные</h2>
                <div class="input-grid">
                    <div class="field">
                        <label class="field-label" for="fc">Центральная частота, МГц</label>
                        <input class="value-input" id="fc" name="fc" type="text" inputmode="decimal" value="<?= dpc_h($values['fc']) ?>" required>
                    </div>
                    <div class="field">
                        <label class="field-label" for="bandwidth">Полоса пропускания, кГц</label>
                        <input class="value-input" id="bandwidth" name="bandwidth" type="text" inputmode="decimal" value="<?= dpc_h($values['bandwidth']) ?>" required>
                        <div class="input-hint">Между точками −3 дБ.</div>
                    </div>
                    <div class="field">
                        <label class="field-label" for="rin">Входной импеданс, Ом</label>
                        <input class="value-input" id="rin" name="rin" type="text" inputmode="decimal" value="<?= dpc_h($values['rin']) ?>" required>
                    </div>
                    <div class="field">
                        <label class="field-label" for="rout">Выходной импеданс, Ом</label>
                        <input class="value-input" id="rout" name="rout" type="text" inputmode="decimal" value="<?= dpc_h($values['rout']) ?>" required>
                    </div>
                </div>
                <div class="action-row"><button type="submit">Рассчитать ДПФ</button><a class="clear-link" href="dpf_capacitive.php">Сбросить</a></div>
                <?php if ($error !== ''): ?><div class="error" role="alert"><?= dpc_h($error) ?></div><?php endif; ?>
                <p class="note">Индуктивность выбирается автоматически; масштаб подбирается так, чтобы реактивное сопротивление катушки было около 300 Ом на центральной частоте. В расчёте используются идеальные компоненты без потерь и паразитных параметров.</p>
            </div>
        </section>
    </form>

    <?php if ($result !== null): ?>
        <section class="panel" id="calculation-result">
            <h2>Результаты расчёта</h2>
            <div class="result-grid">
                <div class="result-item"><span class="result-label">Центральная частота</span><span class="result-value"><?= dpc_frequency_mhz((float) $fcInput * 1e6, 4) ?></span></div>
                <div class="result-item"><span class="result-label">Нижняя граница −3 дБ</span><span class="result-value"><?= dpc_frequency_mhz($result['f1'], 4) ?></span></div>
                <div class="result-item"><span class="result-label">Верхняя граница −3 дБ</span><span class="result-value"><?= dpc_frequency_mhz($result['f2'], 4) ?></span></div>
                <div class="result-item"><span class="result-label">S21 в центре</span><span class="result-value"><?= dpc_num($result['centre']['s21'], 3) ?> дБ</span></div>
            </div>
            <table>
                <thead><tr><th>Элемент</th><th>Номинал</th><th>Назначение</th></tr></thead>
                <tbody>
                    <tr><td>L1</td><td><?= dpc_num($result['L'] * 1e6, 3) ?> мкГн</td><td>Первая катушка</td></tr>
                    <tr><td>L2</td><td><?= dpc_num($result['L'] * 1e6, 3) ?> мкГн</td><td>Вторая катушка (равна L1)</td></tr>
                    <tr><td>C1</td><td><?= dpc_cap_pf($result['C1']) ?></td><td>Верхний конденсатор входного делителя</td></tr>
                    <tr><td>C2</td><td><?= dpc_cap_pf($result['C2']) ?></td><td>Нижний конденсатор входного делителя</td></tr>
                    <tr><td>C3</td><td><?= dpc_cap_pf($result['C3']) ?></td><td>Верхний конденсатор выходного делителя</td></tr>
                    <tr><td>C4</td><td><?= dpc_cap_pf($result['C4']) ?></td><td>Нижний конденсатор выходного делителя</td></tr>
                    <tr><td>C5</td><td><?= dpc_cap_pf($result['C5']) ?></td><td>Общая нижняя точка катушек — земля</td></tr>
                </tbody>
            </table>
            <div class="result-grid" style="margin-top:14px;margin-bottom:0">
                <div class="result-item"><span class="result-label">S11 в центре</span><span class="result-value"><?= dpc_num($result['centre']['s11_db'], 2) ?> дБ</span></div>
                <div class="result-item"><span class="result-label">S22 в центре</span><span class="result-value"><?= dpc_num($result['centre']['s22_db'], 2) ?> дБ</span></div>
                <div class="result-item"><span class="result-label">S21 на нижней границе</span><span class="result-value"><?= dpc_num($result['lower']['s21'], 2) ?> дБ</span></div>
                <div class="result-item"><span class="result-label">S21 на верхней границе</span><span class="result-value"><?= dpc_num($result['upper']['s21'], 2) ?> дБ</span></div>
            </div>
            <p class="note">Номиналы конденсаторов округлены до 1 пФ. Расчёт даёт начальные значения; окончательную настройку выполняют на собранной плате с анализатором цепей, подбирая NP0/C0G-конденсаторы.</p>
        </section>

        <section class="panel chart-panel">
            <h2>Амплитудно-частотная характеристика</h2>
            <p class="chart-subtitle">Расчётная передача S21. Пунктиром отмечен уровень −3 дБ и центральная частота.</p>
            <div class="chart-legend">
                <span class="legend-item"><span class="swatch"></span>S21</span>
                <span class="legend-item"><span class="swatch limit"></span>Границы −3 дБ</span>
                <span class="legend-item"><span class="swatch centre"></span>Центральная частота</span>
            </div>
            <div class="chart-frame"><?= $chartSvg ?></div>

            <form class="download-form" method="post" action="dpf_capacitive.php">
                <input type="hidden" name="action" value="download_cct">
                <input type="hidden" name="fc" value="<?= dpc_h($values['fc']) ?>">
                <input type="hidden" name="bandwidth" value="<?= dpc_h($values['bandwidth']) ?>">
                <input type="hidden" name="rin" value="<?= dpc_h($values['rin']) ?>">
                <input type="hidden" name="rout" value="<?= dpc_h($values['rout']) ?>">
                <input type="hidden" name="client_timestamp" value="">
                <div class="download-row">
                    <button class="download-button" type="submit">Скачать схему для RFSim99 (.cct)</button>
                </div>
            </form>
        </section>
    <?php endif; ?>
</main>
<script>
(() => {
    const form = document.querySelector('.download-form');
    if (!form) return;

    const timestampInput = form.querySelector('input[name="client_timestamp"]');
    if (!timestampInput) return;

    form.addEventListener('submit', () => {
        // Имя файла использует локальные часы компьютера пользователя.
        const now = new Date();
        const pad = value => String(value).padStart(2, '0');
        timestampInput.value = String(now.getFullYear()).padStart(4, '0')
            + pad(now.getMonth() + 1)
            + pad(now.getDate())
            + '_'
            + pad(now.getHours())
            + pad(now.getMinutes())
            + pad(now.getSeconds());
    });
})();
</script>
</body>
</html>
