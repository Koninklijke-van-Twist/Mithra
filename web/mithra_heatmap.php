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

function mithra_heatmap_counts_from_entries(array $entries): array
{
    $counts = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $date = mithra_normalize_date_only((string) ($entry['scan_timestamp'] ?? ''));
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
