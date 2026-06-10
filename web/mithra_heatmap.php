<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/mithra_config.php';
require_once __DIR__ . '/mithra_bc.php';

/**
 * Functies
 */
function mithra_heatmap_monday_of_week(string $date): string
{
    $normalized = mithra_normalize_date_only($date);
    if ($normalized === '') {
        return '';
    }

    $timestamp = strtotime($normalized . ' 00:00:00');
    if ($timestamp === false) {
        return '';
    }

    $dayOfWeek = (int) date('N', $timestamp);
    return date('Y-m-d', $timestamp - (($dayOfWeek - 1) * 86400));
}

function mithra_heatmap_date_shift(string $date, int $dayOffset): string
{
    $normalized = mithra_normalize_date_only($date);
    if ($normalized === '') {
        return '';
    }

    $timestamp = strtotime($normalized . ' ' . ($dayOffset >= 0 ? '+' : '') . $dayOffset . ' days');
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d', $timestamp);
}

function mithra_heatmap_today_date(): string
{
    return date('Y-m-d');
}

function mithra_heatmap_grid_rows(?int $rows = null): int
{
    $value = $rows ?? MITHRA_HEATMAP_ROWS;
    return max(1, $value);
}

function mithra_heatmap_grid_cols(?int $cols = null): int
{
    $value = $cols ?? MITHRA_HEATMAP_COLS;
    return max(1, $value);
}

function mithra_heatmap_grid_dates(string $today = '', ?int $rows = null, ?int $cols = null): array
{
    $today = $today !== '' ? mithra_normalize_date_only($today) : mithra_heatmap_today_date();
    if ($today === '') {
        return [];
    }

    $rowCount = mithra_heatmap_grid_rows($rows);
    $colCount = mithra_heatmap_grid_cols($cols);

    $mondayCurrentWeek = mithra_heatmap_monday_of_week($today);
    if ($mondayCurrentWeek === '') {
        return [];
    }

    $oldestMonday = mithra_heatmap_date_shift($mondayCurrentWeek, -(($rowCount - 1) * 7));
    if ($oldestMonday === '') {
        return [];
    }

    $dates = [];
    for ($row = 0; $row < $rowCount; $row++) {
        for ($col = 0; $col < $colCount; $col++) {
            $offset = ($row * $colCount) + $col;
            $date = mithra_heatmap_date_shift($oldestMonday, $offset);
            if ($date === '') {
                continue;
            }

            $dates[] = $date;
        }
    }

    return $dates;
}

function mithra_heatmap_grid_from_date(string $today = '', ?int $rows = null, ?int $cols = null): string
{
    $dates = mithra_heatmap_grid_dates($today, $rows, $cols);
    $rowCount = mithra_heatmap_grid_rows($rows);
    $colCount = mithra_heatmap_grid_cols($cols);
    $fallbackShift = -((($rowCount * $colCount) - 1));

    return (string) ($dates[0] ?? mithra_heatmap_date_shift($today !== '' ? $today : mithra_heatmap_today_date(), $fallbackShift));
}

function mithra_heatmap_counts_from_entries(array $entries, string $timestampField = 'scan_timestamp'): array
{
    $counts = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $date = mithra_normalize_date_only((string) ($entry[$timestampField] ?? ''));
        if ($date === '') {
            continue;
        }

        $counts[$date] = (int) ($counts[$date] ?? 0) + 1;
    }

    return $counts;
}

function mithra_heatmap_build_grid_days(array $countsByDate, string $today = '', ?int $rows = null, ?int $cols = null): array
{
    $today = $today !== '' ? mithra_normalize_date_only($today) : mithra_heatmap_today_date();
    $days = [];

    foreach (mithra_heatmap_grid_dates($today, $rows, $cols) as $date) {
        $isFuture = strcmp($date, $today) > 0;
        $days[] = [
            'date' => $date,
            'count' => $isFuture ? 0 : (int) ($countsByDate[$date] ?? 0),
            'future' => $isFuture,
        ];
    }

    return $days;
}

function mithra_heatmap_activity_total(array $days): int
{
    $total = 0;
    foreach ($days as $day) {
        if (!empty($day['future'])) {
            continue;
        }
        $total += (int) ($day['count'] ?? 0);
    }

    return $total;
}

function mithra_heatmap_compare_users_by_activity(array $a, array $b): int
{
    $sumA = mithra_heatmap_activity_total($a['days'] ?? []);
    $sumB = mithra_heatmap_activity_total($b['days'] ?? []);

    if ($sumA !== $sumB) {
        return $sumB <=> $sumA;
    }

    return strcasecmp((string) ($a['username'] ?? ''), (string) ($b['username'] ?? ''));
}

function mithra_heatmap_png_dimensions(?int $rows = null, ?int $cols = null, ?int $cellPx = null, ?int $gapPx = null): array
{
    $rowCount = mithra_heatmap_grid_rows($rows);
    $colCount = mithra_heatmap_grid_cols($cols);
    $cell = max(1, $cellPx ?? MITHRA_HEATMAP_CELL_PX);
    $gap = max(0, $gapPx ?? MITHRA_HEATMAP_CELL_GAP);

    return [
        'width' => ($colCount * $cell) + (max(0, $colCount - 1) * $gap),
        'height' => ($rowCount * $cell) + (max(0, $rowCount - 1) * $gap),
        'cell_px' => $cell,
        'gap_px' => $gap,
        'rows' => $rowCount,
        'cols' => $colCount,
    ];
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

function mithra_heatmap_activity_level(int $count, int $intensityMax = MITHRA_HEATMAP_INTENSITY_MAX): string
{
    if ($count <= 0) {
        return '';
    }

    if ($count > $intensityMax) {
        return 'level-over';
    }

    if ($count >= $intensityMax) {
        return 'level-max';
    }

    if ($count >= (int) ceil($intensityMax * 0.75)) {
        return 'level-4';
    }

    if ($count >= (int) ceil($intensityMax * 0.5)) {
        return 'level-3';
    }

    if ($count >= (int) ceil($intensityMax * 0.25)) {
        return 'level-2';
    }

    return 'level-1';
}

function mithra_heatmap_limit_highlight_blend_ratio(int $count, int $intensityMax = MITHRA_HEATMAP_INTENSITY_MAX): float
{
    if ($count < $intensityMax) {
        return 0.0;
    }

    $cap = $intensityMax * MITHRA_HEATMAP_OVER_LIMIT_MULTIPLIER;
    $range = $cap - $intensityMax;
    if ($range <= 0) {
        return 1.0;
    }

    return min(1.0, ($count - $intensityMax) / $range);
}

function mithra_heatmap_limit_highlight_rgb(int $count, int $intensityMax = MITHRA_HEATMAP_INTENSITY_MAX): array
{
    if ($count < $intensityMax) {
        return [255, 255, 0];
    }

    $ratio = mithra_heatmap_limit_highlight_blend_ratio($count, $intensityMax);
    $from = [255, 255, 0];
    $to = [255, 136, 0];

    return [
        (int) round($from[0] + (($to[0] - $from[0]) * $ratio)),
        (int) round($from[1] + (($to[1] - $from[1]) * $ratio)),
        (int) round($from[2] + (($to[2] - $from[2]) * $ratio)),
    ];
}
