<?php
declare(strict_types=1);

/**
 * Округляет границы частотного окна для RFSim99 до удобных значений.
 *
 * Шаг выбирается из ряда 1, 2, 5 × 10^n по ближайшему масштабу
 * к ширине окна / 20. Нижняя и верхняя границы округляются наружу.
 * Нижняя граница округляется вниз, верхняя — вверх.
 *
 * Если задана minimumAllowedHz, округлённая нижняя граница не может
 * оказаться ниже этого ограничения.
 *
 * @return array{0: float, 1: float}
 */
function cct_frequency_axis_bounds(
    float $minimumHz,
    float $maximumHz,
    ?float $minimumAllowedHz = null
): array {
    if (!is_finite($minimumHz)
        || !is_finite($maximumHz)
        || $minimumHz <= 0.0
        || $maximumHz <= $minimumHz) {
        throw new RuntimeException('Для частотной оси CCT заданы недопустимые границы.');
    }

    if ($minimumAllowedHz !== null
        && (!is_finite($minimumAllowedHz) || $minimumAllowedHz <= 0.0 || $minimumAllowedHz > $maximumHz)) {
        throw new RuntimeException('Для частотной оси CCT задано недопустимое ограничение нижней границы.');
    }

    $targetStep = ($maximumHz - $minimumHz) / 20.0;
    if (!is_finite($targetStep) || $targetStep <= 0.0) {
        throw new RuntimeException('Не удалось определить шаг частотной оси CCT.');
    }

    $magnitude = 10.0 ** floor(log10($targetStep));
    if (!is_finite($magnitude) || $magnitude <= 0.0) {
        throw new RuntimeException('Не удалось определить порядок шага частотной оси CCT.');
    }

    $normalizedStep = $targetStep / $magnitude;
    if ($normalizedStep <= 1.4142135623730951) {
        $stepFactor = 1.0;
    } elseif ($normalizedStep <= 3.1622776601683795) {
        $stepFactor = 2.0;
    } elseif ($normalizedStep <= 7.0710678118654755) {
        $stepFactor = 5.0;
    } else {
        $stepFactor = 10.0;
    }

    $step = $stepFactor * $magnitude;
    $roundedMinimum = floor($minimumHz / $step) * $step;
    $roundedMaximum = ceil($maximumHz / $step) * $step;

    if ($minimumAllowedHz !== null && $roundedMinimum < $minimumAllowedHz) {
        $roundedMinimum = $minimumAllowedHz;
    } elseif ($roundedMinimum <= 0.0) {
        // Не допускаем нулевую частоту после округления положительной границы вниз.
        $roundedMinimum = $minimumHz;
    }

    if (!is_finite($roundedMinimum)
        || !is_finite($roundedMaximum)
        || $roundedMinimum <= 0.0
        || $roundedMaximum <= $roundedMinimum) {
        throw new RuntimeException('После округления получились недопустимые границы частотной оси CCT.');
    }

    return [$roundedMinimum, $roundedMaximum];
}
