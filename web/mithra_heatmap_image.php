<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/mithra_config.php';
require_once __DIR__ . '/mithra_bc.php';
require_once __DIR__ . '/mithra_heatmap.php';

/**
 * Functies
 */
function mithra_heatmap_png_gd_available(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagepng');
}

function mithra_heatmap_card_display_dimensions(?int $rows = null, ?int $cols = null): array
{
    return mithra_heatmap_png_dimensions(
        $rows,
        $cols,
        MITHRA_HEATMAP_CELL_PX,
        MITHRA_HEATMAP_CELL_GAP
    );
}

function mithra_heatmap_card_png_source_dimensions(?int $rows = null, ?int $cols = null): array
{
    $rowCount = mithra_heatmap_grid_rows($rows);
    $colCount = mithra_heatmap_grid_cols($cols);

    return [
        'width' => $colCount,
        'height' => $rowCount,
        'cell_px' => 1,
        'gap_px' => 0,
        'rows' => $rowCount,
        'cols' => $colCount,
    ];
}

function mithra_heatmap_card_cell_count(?int $rows = null, ?int $cols = null): int
{
    $dims = mithra_heatmap_card_png_source_dimensions($rows, $cols);

    return (int) ($dims['rows'] * $dims['cols']);
}

function mithra_heatmap_parse_counts_param(string $raw): array
{
    $expected = mithra_heatmap_card_cell_count();
    $raw = trim($raw);
    if ($raw === '') {
        throw new InvalidArgumentException('Heatmap-tellingen ontbreken.');
    }

    $parts = explode(',', $raw);
    if (count($parts) !== $expected) {
        throw new InvalidArgumentException('Heatmap-tellingen moeten ' . $expected . ' waarden bevatten.');
    }

    $days = [];
    foreach ($parts as $part) {
        $value = (int) trim($part);
        if ($value < 0) {
            $days[] = [
                'date' => '',
                'count' => 0,
                'future' => true,
            ];
            continue;
        }

        $days[] = [
            'date' => '',
            'count' => max(0, $value),
            'future' => false,
        ];
    }

    return $days;
}

function mithra_heatmap_png_etag(array $days, int $intensityMax): string
{
    $parts = [
        'max=' . $intensityMax,
        'over=' . MITHRA_HEATMAP_OVER_LIMIT_MULTIPLIER,
        'v=6',
    ];
    foreach ($days as $day) {
        if (!is_array($day)) {
            continue;
        }
        $parts[] = (string) ($day['date'] ?? '') . ':'
            . (int) ($day['count'] ?? 0) . ':'
            . (!empty($day['future']) ? '1' : '0');
    }

    return '"' . sha1(implode('|', $parts)) . '"';
}

function mithra_heatmap_png_etag_from_counts(string $countsRaw, int $intensityMax): string
{
    $days = mithra_heatmap_parse_counts_param($countsRaw);

    return mithra_heatmap_png_etag($days, $intensityMax);
}

function mithra_heatmap_cache_max_age(string $gridDate = ''): int
{
    $normalized = $gridDate !== ''
        ? mithra_normalize_date_only($gridDate)
        : mithra_heatmap_today_date();
    if ($normalized === '') {
        return 3600;
    }

    $expiresAt = strtotime($normalized . ' +1 day 00:00:00');
    if ($expiresAt === false) {
        return 3600;
    }

    return max(60, $expiresAt - time());
}

function mithra_heatmap_blend_on_white(int $red, int $green, int $blue, float $alpha): array
{
    $inverse = 1.0 - $alpha;

    return [
        (int) round($red * $alpha + 255 * $inverse),
        (int) round($green * $alpha + 255 * $inverse),
        (int) round($blue * $alpha + 255 * $inverse),
    ];
}

function mithra_heatmap_cell_rgb(string $level, bool $future, int $count = 0, int $intensityMax = MITHRA_HEATMAP_INTENSITY_MAX): array
{
    if ($future) {
        return [246, 247, 249];
    }

    if ($count >= $intensityMax) {
        return mithra_heatmap_limit_highlight_rgb($count, $intensityMax);
    }

    switch ($level) {
        case 'level-max':
            return [0, 153, 204];
        case 'level-4':
            return mithra_heatmap_blend_on_white(0, 153, 204, 0.82);
        case 'level-3':
            return mithra_heatmap_blend_on_white(0, 153, 204, 0.62);
        case 'level-2':
            return mithra_heatmap_blend_on_white(0, 153, 204, 0.42);
        case 'level-1':
            return mithra_heatmap_blend_on_white(0, 153, 204, 0.22);
        default:
            return [235, 237, 240];
    }
}

function mithra_heatmap_render_png(array $days, string $today = '', int $intensityMax = MITHRA_HEATMAP_INTENSITY_MAX): string
{
    if (!mithra_heatmap_png_gd_available()) {
        throw new RuntimeException('GD-extensie ontbreekt voor heatmap-PNG.');
    }

    $dims = mithra_heatmap_card_png_source_dimensions();
    $image = imagecreatetruecolor($dims['width'], $dims['height']);
    if ($image === false) {
        throw new RuntimeException('Heatmap-PNG kon niet worden aangemaakt.');
    }

    imagesavealpha($image, false);
    $background = imagecolorallocate($image, 255, 255, 255);
    imagefill($image, 0, 0, $background);

    $index = 0;
    for ($row = 0; $row < $dims['rows']; $row++) {
        for ($col = 0; $col < $dims['cols']; $col++) {
            $day = is_array($days[$index] ?? null) ? $days[$index] : [
                'date' => '',
                'count' => 0,
                'future' => false,
            ];
            $future = !empty($day['future']);
            $count = (int) ($day['count'] ?? 0);
            $level = $future ? '' : mithra_heatmap_activity_level($count, $intensityMax);
            $rgb = mithra_heatmap_cell_rgb($level, $future, $count, $intensityMax);
            $fillColor = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
            imagesetpixel($image, $col, $row, $fillColor);
            $index++;
        }
    }

    ob_start();
    imagepng($image, null, 9);
    imagedestroy($image);
    $binary = ob_get_clean();

    if (!is_string($binary) || $binary === '') {
        throw new RuntimeException('Heatmap-PNG genereren mislukt.');
    }

    return $binary;
}

function mithra_heatmap_send_counts_png(string $countsRaw, int $intensityMax = MITHRA_HEATMAP_INTENSITY_MAX): void
{
    try {
        $days = mithra_heatmap_parse_counts_param($countsRaw);
    } catch (InvalidArgumentException $error) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo $error->getMessage();
        exit;
    }

    $etag = mithra_heatmap_png_etag_from_counts($countsRaw, $intensityMax);
    $cacheMaxAge = mithra_heatmap_cache_max_age();
    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '' && hash_equals($etag, $ifNoneMatch)) {
        http_response_code(304);
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=' . $cacheMaxAge);
        exit;
    }

    $png = mithra_heatmap_render_png($days, '', $intensityMax);

    header('Content-Type: image/png');
    header('ETag: ' . $etag);
    header('Cache-Control: private, max-age=' . $cacheMaxAge);
    echo $png;
    exit;
}
