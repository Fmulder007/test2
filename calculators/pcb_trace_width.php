<?php
declare(strict_types=1);

/*
 * Калькулятор ширины ВЧ-дорожки для заданного импеданса.
 * Модели: Hammerstad–Jensen (Microstrip), Wheeler (симметричная Stripline),
 * квазистатическая GCPW-модель с поправкой первого порядка на толщину меди.
 */

function pcb_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pcb_num(string $raw): ?float
{
    $raw = trim(str_replace(["\u{00A0}", "\u{202F}", ' '], '', $raw));
    if ($raw === '') return null;
    $raw = str_replace(',', '.', $raw);
    if (!is_numeric($raw)) return null;
    $value = (float)$raw;
    return is_finite($value) ? $value : null;
}

function pcb_fmt(float $value, int $decimals = 5): string
{
    if (!is_finite($value)) return '—';
    if ($value !== 0.0 && (abs($value) >= 1.0e9 || abs($value) < 1.0e-5)) {
        return str_replace('.', ',', sprintf('%.5g', $value));
    }
    $formatted = number_format($value, $decimals, ',', ' ');
    $formatted = rtrim(rtrim($formatted, '0'), ',');
    return ($formatted === '' || $formatted === '-0') ? '0' : $formatted;
}

function pcb_sech(float $x): float
{
    if ($x > 700.0) return 0.0;
    return 1.0 / cosh($x);
}

/* Hammerstad–Jensen: microstrip, finite copper thickness. Dimensions are in mm. */
function pcb_microstrip_z(float $w, float $h, float $t, float $er): ?float
{
    if ($w <= 0.0 || $h <= 0.0 || $t <= 0.0 || $er < 1.0) return null;
    $u = $w / $h;
    $tn = $t / $h;
    if (!is_finite($u) || !is_finite($tn) || $u <= 0.0 || $tn <= 0.0) return null;

    $tanhPart = tanh(sqrt(6.517 * $u));
    $deltaU1 = ($tn / M_PI) * log(1.0 + (4.0 * M_E / $tn) * $tanhPart * $tanhPart);
    $deltaUr = ($deltaU1 / 2.0) * (1.0 + pcb_sech(sqrt(max(0.0, $er - 1.0))));
    $u1 = $u + $deltaU1;
    $ur = $u + $deltaUr;
    if ($u1 <= 0.0 || $ur <= 0.0 || !is_finite($ur)) return null;

    $ur2 = $ur * $ur;
    $ur4 = $ur2 * $ur2;
    $a = 1.0
        + (1.0 / 49.0) * log(($ur4 + ($ur / 52.0) ** 2) / ($ur4 + 0.432))
        + (1.0 / 18.7) * log(1.0 + ($ur / 18.1) ** 3);
    $b = 0.564 * (($er - 0.9) / ($er + 3.0)) ** 0.053;
    $epsEff = ($er + 1.0) / 2.0 + (($er - 1.0) / 2.0) * ((1.0 + 10.0 / $ur) ** (-$a * $b));

    $zVac = 376.730313668;
    $fUr = 6.0 + (2.0 * M_PI - 6.0) * exp(-((30.666 / $ur) ** 0.7528));
    $zAir = ($zVac / (2.0 * M_PI)) * log(($fUr / $ur) + sqrt(1.0 + (2.0 / $ur) ** 2));
    $z = $zAir / sqrt($epsEff);
    return (is_finite($z) && $z > 0.0) ? $z : null;
}

/* Wheeler finite-thickness approximation for a symmetric stripline.
 * B is the total spacing between the facing surfaces of the ground planes.
 */
function pcb_stripline_z(float $w, float $b, float $t, float $er): ?float
{
    if ($w <= 0.0 || $b <= 0.0 || $t <= 0.0 || $er < 1.0) return null;
    $tau = $t / $b;
    if ($tau <= 0.0 || $tau >= 0.25) return null;

    $wr = $w / $b;
    if ($wr < 0.35) {
        $wEff = $wr - ((0.35 - $wr) ** 2) / (1.0 + 12.0 * $tau);
    } else {
        $wEff = $wr;
    }
    if (!is_finite($wEff) || $wEff <= 0.0) return null;

    if ($tau < 1.0e-8) {
        $cf = 2.0 * log(2.0) / M_PI;
    } else {
        $arg = (1.0 / ((1.0 - $tau) ** 2)) - 1.0;
        if ($arg <= 0.0) return null;
        $cf = (2.0 / M_PI) * log((1.0 / (1.0 - $tau)) + 1.0)
            - ($tau / M_PI) * log($arg);
    }
    $denom = $wEff + $cf;
    if ($denom <= 0.0 || !is_finite($denom)) return null;
    $z = (30.0 * M_PI / sqrt($er)) * (1.0 - $tau) / $denom;
    return (is_finite($z) && $z > 0.0) ? $z : null;
}

/* AGM gives R(k)=K(k)/K(sqrt(1-k^2)) without external libraries. */
function pcb_agm(float $a, float $b): float
{
    for ($i = 0; $i < 80; $i++) {
        $ar = ($a + $b) / 2.0;
        $br = sqrt(max(0.0, $a * $b));
        if (abs($ar - $br) <= 1.0e-14 * max(1.0, abs($ar))) return ($ar + $br) / 2.0;
        $a = $ar;
        $b = $br;
    }
    return ($a + $b) / 2.0;
}

function pcb_elliptic_ratio(float $k): ?float
{
    if (!is_finite($k) || $k <= 0.0 || $k >= 1.0) return null;
    $kp = sqrt(max(0.0, 1.0 - $k * $k));
    $denom = pcb_agm(1.0, $kp);
    if ($denom <= 0.0) return null;
    // K(k)/K(k') = AGM(1,k) / AGM(1,k')
    $ratio = pcb_agm(1.0, $k) / $denom;
    return (is_finite($ratio) && $ratio > 0.0) ? $ratio : null;
}

/* Grounded coplanar waveguide, wide side grounds and quasi-static conformal mapping.
 * First-order copper-thickness correction follows the published Gupta-type model.
 */
function pcb_gcpw_details(float $w, float $s, float $h, float $t, float $er): ?array
{
    if ($w <= 0.0 || $s <= 0.0 || $h <= 0.0 || $t <= 0.0 || $er < 1.0 || $w <= $t * 0.05) return null;

    $delta = (1.25 * $t / M_PI) * (1.0 + log((4.0 * M_PI * $w) / $t));
    if (!is_finite($delta) || $delta < 0.0) return null;
    $we = $w + $delta;
    $se = $s - $delta;
    if ($se <= 0.0) return null;

    $k1 = $we / ($we + 2.0 * $se);
    $den = tanh((M_PI * ($we + 2.0 * $se)) / (4.0 * $h));
    if ($den == 0.0) return null;
    $k3 = tanh((M_PI * $we) / (4.0 * $h)) / $den;
    $r1 = pcb_elliptic_ratio($k1);
    $r3 = pcb_elliptic_ratio($k3);
    if ($r1 === null || $r3 === null) return null;

    $sum = $r1 + $r3;
    if ($sum <= 0.0) return null;
    $q = $r3 / $sum;
    $epsEff = 1.0 + $q * ($er - 1.0);
    $epsEffT = $epsEff - (0.7 * ($epsEff - 1.0) * ($t / $s)) / ($r1 + 0.7 * ($t / $s));
    if ($epsEffT < 1.0 || !is_finite($epsEffT)) return null;

    $z = 60.0 * M_PI / (sqrt($epsEffT) * $sum);
    if (!is_finite($z) || $z <= 0.0) return null;
    return ['z' => $z, 'delta' => $delta, 'delta_ratio' => $delta / $s];
}

function pcb_solve_width(callable $impedance, float $target, float $low, float $high, float $maxHigh = 1.0e6): array
{
    $zLow = $impedance($low);
    if ($zLow === null || !is_finite($zLow)) {
        return ['ok' => false, 'message' => 'Не удалось рассчитать начальную ширину для выбранной геометрии. Для GCPW попробуй увеличить боковой зазор S или уменьшить толщину меди.'];
    }
    if ($zLow < $target) {
        return ['ok' => false, 'message' => 'Для заданной геометрии целевой импеданс выше диапазона выбранной модели. Попробуй другую толщину диэлектрика или зазор.'];
    }

    $bracketed = false;
    for ($i = 0; $i < 80; $i++) {
        $zHigh = $impedance($high);
        if ($zHigh !== null && is_finite($zHigh)) {
            if ($zHigh <= $target) {
                $bracketed = true;
                break;
            }
            $high *= 2.0;
            if ($high > $maxHigh) break;
            continue;
        }

        // Некоторые геометрии (особенно GCPW с малым зазором) выходят
        // за область модели при большой ширине. Находим крайнюю допустимую ширину.
        $invalidHigh = $high;
        $validHigh = $low;
        for ($j = 0; $j < 70; $j++) {
            $midValid = ($validHigh + $invalidHigh) / 2.0;
            $zMidValid = $impedance($midValid);
            if ($zMidValid !== null && is_finite($zMidValid)) {
                $validHigh = $midValid;
            } else {
                $invalidHigh = $midValid;
            }
        }
        $zBoundary = $impedance($validHigh);
        if ($zBoundary !== null && is_finite($zBoundary) && $zBoundary <= $target) {
            $high = $validHigh;
            $bracketed = true;
        }
        break;
    }
    if (!$bracketed) {
        return ['ok' => false, 'message' => 'Не удалось найти ширину для заданного импеданса в надёжном диапазоне модели. Проверь целевой импеданс и размеры линии. Для GCPW попробуй увеличить боковой зазор S.'];
    }

    for ($i = 0; $i < 100; $i++) {
        $mid = ($low + $high) / 2.0;
        $zMid = $impedance($mid);
        if ($zMid === null || !is_finite($zMid)) {
            $high = $mid;
            continue;
        }
        if ($zMid > $target) $low = $mid;
        else $high = $mid;
    }
    $width = ($low + $high) / 2.0;
    return ['ok' => true, 'width' => $width];
}

$lineType = (string)($_POST['line_type'] ?? 'microstrip');
if (!in_array($lineType, ['microstrip', 'stripline', 'gcpw'], true)) $lineType = 'microstrip';

$raw = [
    'er' => trim((string)($_POST['er'] ?? '4.3')),
    'copper_t' => trim((string)($_POST['copper_t'] ?? '35')),
    'copper_unit' => (string)($_POST['copper_unit'] ?? 'um'),
    'target_z' => trim((string)($_POST['target_z'] ?? '50')),
    'micro_h' => trim((string)($_POST['micro_h'] ?? '1.6')),
    'strip_b' => trim((string)($_POST['strip_b'] ?? '0.7')),
    'gcpw_h' => trim((string)($_POST['gcpw_h'] ?? '0.8')),
    'gcpw_s' => trim((string)($_POST['gcpw_s'] ?? '0.22')),
];
if (!in_array($raw['copper_unit'], ['um', 'mm'], true)) $raw['copper_unit'] = 'um';

$isSubmitted = (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') && isset($_POST['calculate']);
$error = '';
$warnings = [];
$result = null;

if ($isSubmitted) {
    $er = pcb_num($raw['er']);
    $copperInput = pcb_num($raw['copper_t']);
    $targetZ = pcb_num($raw['target_z']);
    $tMm = ($copperInput === null) ? null : $copperInput * ($raw['copper_unit'] === 'um' ? 0.001 : 1.0);

    if ($er === null || $er < 1.0 || $er > 100.0) {
        $error = 'Укажи относительную диэлектрическую проницаемость εr от 1 до 100.';
    } elseif ($tMm === null || $tMm <= 0.0 || $tMm > 2.0) {
        $error = 'Укажи толщину меди больше нуля и не более 2 мм.';
    } elseif ($targetZ === null || $targetZ < 1.0 || $targetZ > 1000.0) {
        $error = 'Укажи целевой импеданс от 1 до 1000 Ом.';
    }

    if ($error === '' && $lineType === 'microstrip') {
        $h = pcb_num($raw['micro_h']);
        if ($h === null || $h <= 0.0 || $h > 100.0) {
            $error = 'Укажи толщину диэлектрика H больше нуля и не более 100 мм.';
        } elseif ($tMm >= $h) {
            $error = 'Толщина меди T должна быть меньше толщины диэлектрика H.';
        } else {
            $solver = static fn(float $w): ?float => pcb_microstrip_z($w, $h, $tMm, $er);
            $solved = pcb_solve_width($solver, $targetZ, max(1.0e-6, $tMm * 1.0e-4), max(0.1, $h * 2.0));
            if (!$solved['ok']) $error = $solved['message'];
            else {
                $width = $solved['width'];
                $ratio = $width / $h;
                if ($ratio < 0.01 || $ratio > 100.0) $warnings[] = 'Полученное отношение W/H выходит за обычный рабочий диапазон модели Hammerstad–Jensen; считай результат ориентировочным.';
                $result = ['width' => $width, 'line' => 'Микрополосковая линия (Microstrip)', 'target' => $targetZ, 'model' => 'Hammerstad–Jensen, с поправкой на толщину меди'];
            }
        }
    } elseif ($error === '' && $lineType === 'stripline') {
        $b = pcb_num($raw['strip_b']);
        if ($b === null || $b <= 0.0 || $b > 100.0) {
            $error = 'Укажи расстояние B между плоскостями земли больше нуля и не более 100 мм.';
        } elseif ($tMm >= 0.25 * $b) {
            $error = 'Для выбранной модели толщина меди T должна быть меньше четверти расстояния B между плоскостями земли.';
        } else {
            $tau = $tMm / $b;
            if ($tau >= 0.025) $warnings[] = 'Толщина меди превышает 2,5% расстояния между плоскостями; приближение для Stripline может быть менее точным.';
            $minRatio = 0.0;
            $maxRatio = 0.35;
            for ($i = 0; $i < 70; $i++) {
                $mid = ($minRatio + $maxRatio) / 2.0;
                $weff = $mid - ((0.35 - $mid) ** 2) / (1.0 + 12.0 * $tau);
                if ($weff > 0.0) $maxRatio = $mid;
                else $minRatio = $mid;
            }
            $low = $b * ($maxRatio + 1.0e-5);
            $solver = static fn(float $w): ?float => pcb_stripline_z($w, $b, $tMm, $er);
            $solved = pcb_solve_width($solver, $targetZ, $low, max($b, 0.2));
            if (!$solved['ok']) $error = $solved['message'];
            else {
                $width = $solved['width'];
                $ratio = $width / $b;
                if ($ratio < 0.08) $warnings[] = 'Получилась очень узкая дорожка относительно B; формула для конечной толщины меди становится менее надёжной.';
                $result = ['width' => $width, 'line' => 'Симметричная полосковая линия (Stripline)', 'target' => $targetZ, 'model' => 'Wheeler, симметричная Stripline с конечной толщиной меди'];
            }
        }
    } elseif ($error === '' && $lineType === 'gcpw') {
        $h = pcb_num($raw['gcpw_h']);
        $s = pcb_num($raw['gcpw_s']);
        if ($h === null || $h <= 0.0 || $h > 100.0) {
            $error = 'Укажи толщину диэлектрика H больше нуля и не более 100 мм.';
        } elseif ($s === null || $s <= 0.0 || $s > 100.0) {
            $error = 'Укажи боковой зазор S больше нуля и не более 100 мм.';
        } else {
            $low = max(1.0e-6, 3.0 * $tMm);
            $solver = static function (float $w) use ($s, $h, $tMm, $er): ?float {
                $details = pcb_gcpw_details($w, $s, $h, $tMm, $er);
                return $details['z'] ?? null;
            };
            $solved = pcb_solve_width($solver, $targetZ, $low, max($h, $s, $low * 2.0));
            if (!$solved['ok']) $error = $solved['message'];
            else {
                $width = $solved['width'];
                $details = pcb_gcpw_details($width, $s, $h, $tMm, $er);
                if ($details === null) {
                    $error = 'Не удалось проверить найденную ширину для GCPW. Попробуй увеличить зазор S.';
                } else {
                    if ($details['delta_ratio'] > 0.35) $warnings[] = 'Поправка на конечную толщину меди велика относительно зазора S. Для такой геометрии результат GCPW ориентировочный; попробуй увеличить S и проверь конструкцию электромагнитным решателем.';
                    $result = ['width' => $width, 'line' => 'Копланарная линия с нижней землёй (GCPW)', 'target' => $targetZ, 'model' => 'Квазистатическая модель конформного отображения с поправкой на толщину меди'];
                }
            }
        }
    }
}

function pcb_checked(string $current, string $value): string
{
    return $current === $value ? ' checked' : '';
}

?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Расчёт ширины ВЧ-дорожки на печатной плате</title>
    <meta name="description" content="Расчёт ширины микрополосковой, полосковой или копланарной ВЧ-дорожки по целевому импедансу и параметрам платы.">
    <style>
        :root { color-scheme: light; font-family: Arial, Helvetica, sans-serif; color: #202124; background: #f4f6f8; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; }
        main { max-width: 900px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #d7dce1; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
        h1 { margin: 0 0 8px; font-size: clamp(1.45rem, 3vw, 2rem); }
        h2 { margin: 0 0 10px; font-size: 1.18rem; }
        p { line-height: 1.5; }
        .subtle { color: #5f6368; }
        .back-link { display: inline-block; margin-top: 6px; color: #244f76; font-weight: 700; text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .choices { display: grid; grid-template-columns: 1fr; gap: 10px; }
        .line-type-row { display: grid; grid-template-columns: minmax(85px, .65fr) minmax(0, 1.35fr); align-items: center; gap: 8px; margin-bottom: 12px; }
        .line-panel .field-label { display: block; margin: 0; font-weight: 700; }
        .line-type-select { width: 100%; min-width: 0; min-height: 39px; border: 1px solid #b9c1c8; border-radius: 6px; padding: 8px 10px; font: inherit; background: #fff; color: #202124; }
        .line-diagram { display: block; width: 100%; max-height: 260px; object-fit: contain; border: 1px solid #e0e5e9; border-radius: 6px; background: #fff; }
        .line-caption { margin: 8px 0 0; color: #5f6368; font-size: .88rem; line-height: 1.4; }
        .field { display: grid; grid-template-columns: minmax(155px, .9fr) minmax(0, 1.1fr); align-items: center; gap: 10px; }
        .field-label { margin: 0; }
        .form-grid { gap: 8px; }
        .field-help { display: none; }
        .input-unit input, .field > input, .field select { min-height: 39px; }
        .line-choice { display: grid; grid-template-columns: 22px minmax(130px, 205px) minmax(0, 1fr); gap: 12px; align-items: center; border: 1px solid #d7dce1; border-radius: 8px; padding: 10px; cursor: pointer; background: #fff; }
        .line-choice:has(input:checked) { border-color: #8aafcb; background: #f2f7fb; }
        .line-choice input { margin: 0; accent-color: #245c88; width: 18px; height: 18px; }
        .line-choice img { display: block; width: 100%; height: auto; border: 1px solid #e0e5e9; border-radius: 5px; background: #fff; }
        .choice-title { display: block; font-weight: 700; margin-bottom: 5px; }
        .choice-desc { display: block; color: #5f6368; font-size: .92rem; line-height: 1.4; }
        .calculator-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }
         .input-column { min-width: 0; }
         .form-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; align-items: end; }
        .field { min-width: 0; }
        .field-label { display: block; font-weight: 700; margin-bottom: 6px; }
        .field-help { display: block; margin-top: 5px; color: #5f6368; font-size: .86rem; line-height: 1.4; }
        input[type="text"], select { width: 100%; min-width: 0; border: 1px solid #b9c1c8; border-radius: 6px; padding: 10px 11px; font: inherit; background: #fff; color: #202124; }
        input:focus, select:focus { outline: 2px solid #b7d5ed; border-color: #245c88; }
        .input-unit { display: grid; grid-template-columns: minmax(0, 1fr) 100px; gap: 8px; }
        .geo-group[hidden] { display: none !important; }
        .geo-title { font-size: 1rem; margin: 0 0 12px; }
        .button-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 18px; }
         .clear-link { color: #244f76; }
        button { appearance: none; border: 1px solid #244f76; border-radius: 6px; padding: 11px 18px; font: inherit; font-weight: 700; color: #fff; background: #245c88; cursor: pointer; }
        button:hover { background: #1d4b70; }
        .result { background: #f1f7fb; border: 1px solid #c8dce9; border-radius: 9px; padding: 18px; }
        .result-label { color: #52616d; font-weight: 700; margin-bottom: 6px; }
        .result-value { color: #204e70; font-size: clamp(1.8rem, 5vw, 2.6rem); font-weight: 700; line-height: 1.15; overflow-wrap: anywhere; }
        .result-sub { margin-top: 8px; color: #52616d; line-height: 1.45; }
        .error { background: #fff5f4; border: 1px solid #e6b8b4; color: #8c2e27; border-radius: 8px; padding: 12px 14px; margin-bottom: 14px; }
        .warning { background: #fff9e8; border: 1px solid #ead8a6; color: #6c5310; border-radius: 8px; padding: 12px 14px; margin-top: 12px; line-height: 1.45; }
        .small-note { color: #5f6368; font-size: .9rem; line-height: 1.5; }
        @media (max-width: 680px) {
            body { padding: 10px; }
            .panel { padding: 15px; }
            .form-grid { grid-template-columns: minmax(0, 1fr); }
            .field { grid-template-columns: minmax(125px, .85fr) minmax(0, 1.15fr); gap: 8px; }
            .line-type-row { grid-template-columns: minmax(80px, .65fr) minmax(0, 1.35fr); gap: 7px; }
            .line-choice { grid-template-columns: 22px minmax(95px, 125px) minmax(0, 1fr); gap: 8px; padding: 8px; }
            .choice-desc { font-size: .85rem; }
            .input-unit { grid-template-columns: minmax(0, 1fr) 94px; }
        }
        @media print {
            body { padding: 0; background: #fff; }
            .panel { border-color: #aaa; break-inside: avoid; }
            .no-print, .back-link, button, .clear-link { display: none !important; }
            .result { background: #fff; border-color: #999; }
        }
    </style>
</head>
<body>
<main>
    <section class="panel">
        <h1>Расчёт ширины ВЧ-дорожки на печатной плате</h1>
        <p class="subtle">Подбор ширины медной дорожки для заданного характеристического импеданса. Выбери тип линии по схеме, укажи εr, толщину меди и геометрию платы.</p>
        <a class="back-link" href="index.php">← К списку калькуляторов</a>
    </section>

    <?php if ($error !== ''): ?>
        <div class="error" role="alert"><?= pcb_h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="pcb_trace_width.php" id="pcb-form">
        <div class="calculator-layout">
        <section class="panel no-print line-panel">
            <h2>Тип линии</h2>
            <div class="line-type-row">
                <label class="field-label" for="line_type">Тип линии</label>
                <select id="line_type" name="line_type" class="line-type-select">
                <option value="microstrip"<?= $lineType === 'microstrip' ? ' selected' : '' ?>>Микрополосковая (Microstrip)</option>
                <option value="stripline"<?= $lineType === 'stripline' ? ' selected' : '' ?>>Полосковая (Stripline)</option>
                <option value="gcpw"<?= $lineType === 'gcpw' ? ' selected' : '' ?>>Копланарная (GCPW)</option>
                </select>
            </div>
            <img class="line-diagram" id="line-diagram" src="images/pcb-<?= $lineType ?>.svg" alt="Схема выбранного типа ВЧ-линии" width="360" height="300">
            <p class="line-caption" id="line-caption"><?= $lineType === 'microstrip' ? 'Дорожка на наружном слое; земля снизу.' : ($lineType === 'stripline' ? 'Дорожка расположена между двумя плоскостями земли.' : 'Боковые земляные участки и нижняя плоскость земли.') ?></p>
        </section>

        <div class="input-column">
        <section class="panel">
            <h2>2. Общие параметры</h2>
            <div class="form-grid">
                <div class="field">
                    <label class="field-label" for="er">Относительная диэлектрическая проницаемость εr</label>
                    <input type="text" inputmode="decimal" id="er" name="er" value="<?= pcb_h($raw['er']) ?>" autocomplete="off">
                </div>
                <div class="field">
                    <label class="field-label" for="copper_t">Толщина меди T</label>
                    <div class="input-unit">
                        <input type="text" inputmode="decimal" id="copper_t" name="copper_t" value="<?= pcb_h($raw['copper_t']) ?>" autocomplete="off">
                        <select id="copper_unit" name="copper_unit" aria-label="Единицы толщины меди">
                            <option value="um"<?= $raw['copper_unit'] === 'um' ? ' selected' : '' ?>>мкм</option>
                            <option value="mm"<?= $raw['copper_unit'] === 'mm' ? ' selected' : '' ?>>мм</option>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="target_z">Требуемый импеданс Z₀, Ом</label>
                    <input type="text" inputmode="decimal" id="target_z" name="target_z" value="<?= pcb_h($raw['target_z']) ?>" autocomplete="off">
                </div>
            </div>
        </section>

        <section class="panel">
            <h2>3. Геометрия выбранной линии</h2>
            <div class="geo-group" data-geometry="microstrip"<?= $lineType === 'microstrip' ? '' : ' hidden' ?>>
                <h3 class="geo-title">Микрополосковая линия</h3>
                <div class="field">
                    <label class="field-label" for="micro_h">H — толщина диэлектрика от дорожки до нижней земли, мм</label>
                    <input type="text" inputmode="decimal" id="micro_h" name="micro_h" value="<?= pcb_h($raw['micro_h']) ?>" autocomplete="off">
                </div>
            </div>
            <div class="geo-group" data-geometry="stripline"<?= $lineType === 'stripline' ? '' : ' hidden' ?>>
                <h3 class="geo-title">Симметричная полосковая линия</h3>
                <div class="field">
                    <label class="field-label" for="strip_b">B — полное расстояние между плоскостями земли, мм</label>
                    <input type="text" inputmode="decimal" id="strip_b" name="strip_b" value="<?= pcb_h($raw['strip_b']) ?>" autocomplete="off">
                    <span class="field-help">Дорожка размещена точно посередине: от неё до каждой плоскости примерно B/2.</span>
                </div>
            </div>
            <div class="geo-group" data-geometry="gcpw"<?= $lineType === 'gcpw' ? '' : ' hidden' ?>>
                <h3 class="geo-title">Копланарная линия с нижней землёй</h3>
                <div class="form-grid">
                    <div class="field">
                        <label class="field-label" for="gcpw_h">H — толщина диэлектрика до нижней земли, мм</label>
                        <input type="text" inputmode="decimal" id="gcpw_h" name="gcpw_h" value="<?= pcb_h($raw['gcpw_h']) ?>" autocomplete="off">
                    </div>
                    <div class="field">
                        <label class="field-label" for="gcpw_s">S — боковой зазор с каждой стороны, мм</label>
                        <input type="text" inputmode="decimal" id="gcpw_s" name="gcpw_s" value="<?= pcb_h($raw['gcpw_s']) ?>" autocomplete="off">
                    </div>
                </div>
                <span class="field-help">Предполагаются достаточно широкие боковые полигоны земли, соединённые с нижней землёй переходными отверстиями.</span>
            </div>
            <div class="button-row">
                <button type="submit" name="calculate" value="1">Рассчитать ширину дорожки</button>
                <a class="clear-link" href="pcb_trace_width.php">Сбросить</a>
            </div>
        </section>
        </div>
        </div>
    </form>

    <?php if ($result !== null): ?>
        <section class="panel">
            <h2>Результат расчёта</h2>
            <div class="result">
                <div class="result-label">Рекомендуемая ширина медной дорожки W</div>
                <div class="result-value"><?= pcb_h(pcb_fmt($result['width'], 6)) ?> мм</div>
                <div class="result-sub"><?= pcb_h(pcb_fmt($result['width'] * 1000.0, 4)) ?> мкм · Целевой импеданс: <?= pcb_h(pcb_fmt($result['target'], 4)) ?> Ом<br><?= pcb_h($result['line']) ?></div>
            </div>
            <?php foreach ($warnings as $warning): ?>
                <div class="warning" role="status"><?= pcb_h($warning) ?></div>
            <?php endforeach; ?>
            <p class="small-note">Модель: <?= pcb_h($result['model']) ?>.</p>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Важные замечания</h2>
        <p class="small-note">Это приближённый квазистатический расчёт. Паяльная маска не учитывается. Реальный импеданс зависит от фактического stack-up, εr на рабочей частоте, формы вытравленной дорожки и допусков изготовления.</p>
        <p class="small-note">Для GCPW важно соединять боковые земляные полигоны с нижней землёй переходными отверстиями. Результат лучше подтвердить данными производителя платы или тестовым купоном.</p>
    </section>
</main>
<script>
(() => {
  const selector = document.getElementById('line_type');
  const groups = document.querySelectorAll('[data-geometry]');
  const diagram = document.getElementById('line-diagram');
  const caption = document.getElementById('line-caption');
  const diagramData = {
    microstrip: {
      src: 'images/pcb-microstrip.svg',
      alt: 'Микрополосковая линия: дорожка на наружном слое, земля снизу',
      caption: 'Дорожка на наружном слое; земля снизу.'
    },
    stripline: {
      src: 'images/pcb-stripline.svg',
      alt: 'Симметричная полосковая линия между двумя плоскостями земли',
      caption: 'Дорожка расположена между двумя плоскостями земли.'
    },
    gcpw: {
      src: 'images/pcb-gcpw.svg',
      alt: 'Копланарная линия с боковыми земляными участками и нижней землёй',
      caption: 'Боковые земляные участки и нижняя плоскость земли.'
    }
  };
  function updateGeometry() {
    const selected = selector.value;
    groups.forEach(group => {
      // Только скрываем неактивные поля: значения сохраняются при переключении.
      group.hidden = group.dataset.geometry !== selected;
    });
    const data = diagramData[selected];
    if (data) {
      diagram.src = data.src;
      diagram.alt = data.alt;
      caption.textContent = data.caption;
    }
  }
  selector.addEventListener('change', updateGeometry);
  updateGeometry();
})();
</script>
</body>
</html>
