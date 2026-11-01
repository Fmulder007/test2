<?php
declare(strict_types=1);

/**
 * Create a PNG thumbnail for a supported raster image.
 * Returns null if GD is absent, decoding fails, or dimensions exceed a safe limit.
 * GIF is decoded as a single (first) frame.
 */
function chat_generate_thumbnail_png(string $path, string $expectedMime, int $maxSide): ?array
{
    $supported = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if ($maxSide < 1 || !in_array($expectedMime, $supported, true)) return null;
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor') || !function_exists('imagepng')) return null;

    $info = @getimagesize($path);
    if (!is_array($info) || !isset($info[0], $info[1]) || (string)($info['mime'] ?? '') !== $expectedMime) return null;
    $width = (int)$info[0];
    $height = (int)$info[1];
    // Avoid decoding unusually large images into a large in-memory GD surface.
    if ($width < 1 || $height < 1 || ($width * $height) > 12000000) return null;

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return null;
    $source = null;
    $thumb = null;
    try {
        $source = @imagecreatefromstring($raw);
        unset($raw);
        if ($source === false || $source === null) return null;

        // Honor EXIF orientation for JPEGs when the optional EXIF extension is available.
        if ($expectedMime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path, 'IFD0', true, false);
            $orientation = (int)($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1);
            if ($orientation === 2 && function_exists('imageflip')) {
                @imageflip($source, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 3) {
                $rotated = @imagerotate($source, 180, 0);
                if ($rotated !== false) { @imagedestroy($source); $source = $rotated; }
            } elseif ($orientation === 4 && function_exists('imageflip')) {
                @imageflip($source, IMG_FLIP_VERTICAL);
            } elseif ($orientation === 5) {
                $rotated = @imagerotate($source, 90, 0);
                if ($rotated !== false) { @imagedestroy($source); $source = $rotated; }
                if (function_exists('imageflip')) @imageflip($source, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 6) {
                $rotated = @imagerotate($source, -90, 0);
                if ($rotated !== false) { @imagedestroy($source); $source = $rotated; }
            } elseif ($orientation === 7) {
                $rotated = @imagerotate($source, -90, 0);
                if ($rotated !== false) { @imagedestroy($source); $source = $rotated; }
                if (function_exists('imageflip')) @imageflip($source, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 8) {
                $rotated = @imagerotate($source, 90, 0);
                if ($rotated !== false) { @imagedestroy($source); $source = $rotated; }
            }
        }

        $width = imagesx($source);
        $height = imagesy($source);
        if ($width < 1 || $height < 1) return null;
        $maxSide = max(16, min(1024, $maxSide));
        $scale = min(1.0, $maxSide / max($width, $height));
        $thumbWidth = max(1, (int)round($width * $scale));
        $thumbHeight = max(1, (int)round($height * $scale));

        $thumb = @imagecreatetruecolor($thumbWidth, $thumbHeight);
        if ($thumb === false || $thumb === null) return null;
        @imagealphablending($thumb, false);
        @imagesavealpha($thumb, true);
        $transparent = @imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        if ($transparent !== false) @imagefilledrectangle($thumb, 0, 0, $thumbWidth - 1, $thumbHeight - 1, $transparent);
        if (!@imagecopyresampled($thumb, $source, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height)) return null;

        $bufferLevel = ob_get_level();
        ob_start();
        $ok = @imagepng($thumb, null, 6);
        $png = ob_get_clean();
        while (ob_get_level() > $bufferLevel) @ob_end_clean();
        if (!$ok || !is_string($png) || $png === '') return null;

        return ['data' => $png, 'mime' => 'image/png', 'width' => $thumbWidth, 'height' => $thumbHeight];
    } catch (Throwable $e) {
        return null;
    } finally {
        if ($thumb !== null && $thumb !== false) @imagedestroy($thumb);
        if ($source !== null && $source !== false) @imagedestroy($source);
    }
}
