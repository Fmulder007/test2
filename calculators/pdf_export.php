<?php
declare(strict_types=1);

/**
 * Универсальный приёмник PDF-экспорта.
 * Получает готовые страницы JPEG и собирает из них настоящий многостраничный PDF.
 * Не требует Composer, GD, mbstring или сторонних PHP-библиотек.
 */

const PDF_EXPORT_CALCULATORS = [
    'wire_antenna.php',
    'ccdantenna.php',
    'passive_components.php',
    'rf_power.php',
    'lc_circuit.php',
    'toroid_winding.php',
    'pcb_trace_width.php',
    'dpf_filter.php',
    'dpf_filter_3.php',
    'lpf_notch.php',
    'ohms_law.php',
];

function pdf_export_error(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $message;
    exit;
}

function pdf_export_pdf(array $images, int $width, int $height, string $filename): string
{
    $pageWidth = 595.28;  // A4, points
    $pageHeight = 841.89;
    $objects = [];
    $pageIds = [];
    $nextId = 3;

    foreach ($images as $jpeg) {
        $pageId = $nextId++;
        $imageId = $nextId++;
        $contentId = $nextId++;
        $pageIds[] = $pageId;

        $content = "q\n" . $pageWidth . " 0 0 " . $pageHeight . " 0 0 cm\n/Im0 Do\nQ\n";

        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageWidth} {$pageHeight}] "
            . "/Resources << /XObject << /Im0 {$imageId} 0 R >> >> /Contents {$contentId} 0 R >>";

        $objects[$imageId] = "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} "
            . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpeg) . " >>\n"
            . "stream\n" . $jpeg . "\nendstream";

        $objects[$contentId] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream";
    }

    $kids = implode(' ', array_map(static fn(int $id): string => $id . ' 0 R', $pageIds));
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[2] = '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds) . ' >>';
    $infoId = $nextId++;
    $safeTitle = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $filename);
    $objects[$infoId] = '<< /Title (' . $safeTitle . ') /Producer (Karat3 calculator PDF export) >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $size = $infoId + 1;
    $pdf .= "xref\n0 {$size}\n0000000000 65535 f \n";
    for ($id = 1; $id < $size; $id++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
    }
    $pdf .= "trailer\n<< /Size {$size} /Root 1 0 R /Info {$infoId} 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";
    return $pdf;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    pdf_export_error('Метод запроса не поддерживается.', 405);
}

$contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
if (!str_starts_with($contentType, 'application/json')) {
    pdf_export_error('Ожидались данные экспорта в формате JSON.');
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '' || strlen($raw) > 32 * 1024 * 1024) {
    pdf_export_error('Пакет экспорта пуст или превышает допустимый размер.', 413);
}

try {
    $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    pdf_export_error('Не удалось прочитать данные PDF-экспорта.');
}

$calculator = (string) ($payload['calculator'] ?? '');
if (!in_array($calculator, PDF_EXPORT_CALCULATORS, true)) {
    pdf_export_error('Неизвестный калькулятор.');
}

$filename = (string) ($payload['filename'] ?? '');
$filenamePattern = '/^' . preg_quote($calculator, '/') . '_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.pdf$/D';
if (!preg_match($filenamePattern, $filename, $matches)) {
    pdf_export_error('Имя PDF-файла не соответствует заданному формату.');
}
$timestamp = DateTimeImmutable::createFromFormat('!Y-m-d_H-i-s', $matches[1]);
if (!$timestamp || $timestamp->format('Y-m-d_H-i-s') !== $matches[1]) {
    pdf_export_error('В имени файла указаны некорректные дата или время.');
}

$pages = $payload['pages'] ?? null;
if (!is_array($pages) || count($pages) < 1 || count($pages) > 20) {
    pdf_export_error('В PDF должно быть от 1 до 20 страниц.');
}

$width = filter_var($payload['width'] ?? null, FILTER_VALIDATE_INT);
$height = filter_var($payload['height'] ?? null, FILTER_VALIDATE_INT);
if ($width !== 1654 || $height !== 2339) {
    pdf_export_error('Неверный размер страницы экспорта.');
}

$images = [];
$totalBytes = 0;
foreach ($pages as $index => $dataUrl) {
    if (!is_string($dataUrl) || !preg_match('/^data:image\/jpeg;base64,([A-Za-z0-9+\/=]+)$/D', $dataUrl, $imageMatch)) {
        pdf_export_error('Страница ' . ($index + 1) . ' имеет неверный формат.');
    }
    $jpeg = base64_decode($imageMatch[1], true);
    if ($jpeg === false || strlen($jpeg) < 100 || substr($jpeg, 0, 2) !== "\xFF\xD8" || substr($jpeg, -2) !== "\xFF\xD9") {
        pdf_export_error('Не удалось проверить изображение страницы ' . ($index + 1) . '.');
    }
    if (strlen($jpeg) > 10 * 1024 * 1024) {
        pdf_export_error('Одна из страниц слишком велика.', 413);
    }
    $totalBytes += strlen($jpeg);
    if ($totalBytes > 26 * 1024 * 1024) {
        pdf_export_error('Общий объём страниц превышает допустимый размер.', 413);
    }
    $images[] = $jpeg;
}

$pdf = pdf_export_pdf($images, $width, $height, $filename);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
echo $pdf;
