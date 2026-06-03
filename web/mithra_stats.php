<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/mithra_config.php';
require_once __DIR__ . '/mithra_scan_store.php';
require_once __DIR__ . '/mithra_heatmap.php';

/**
 * Functies
 */
function mithra_stats_period_start_week(string $referenceDate = 'today'): string
{
    $reference = $referenceDate === 'today' ? date('Y-m-d') : mithra_normalize_date_only($referenceDate);
    if ($reference === '') {
        return date('Y-m-d');
    }

    return mithra_heatmap_monday_of_week($reference);
}

function mithra_stats_period_start_month(string $referenceDate = 'today'): string
{
    $reference = $referenceDate === 'today' ? date('Y-m-d') : mithra_normalize_date_only($referenceDate);
    if ($reference === '') {
        return date('Y-m-01');
    }

    $timestamp = strtotime($reference . ' 00:00:00');
    if ($timestamp === false) {
        return date('Y-m-01');
    }

    return date('Y-m-01', $timestamp);
}

function mithra_stats_calendar_days_between(string $fromDate, string $toDate): int
{
    $from = mithra_normalize_date_only($fromDate);
    $to = mithra_normalize_date_only($toDate);
    if ($from === '' || $to === '' || strcmp($from, $to) > 0) {
        return 0;
    }

    $startTs = strtotime($from . ' 00:00:00');
    $endTs = strtotime($to . ' 00:00:00');
    if ($startTs === false || $endTs === false || $endTs < $startTs) {
        return 0;
    }

    return (int) floor(($endTs - $startTs) / 86400) + 1;
}

function mithra_stats_calendar_weeks_between(string $fromDate, string $toDate): int
{
    $fromMonday = mithra_heatmap_monday_of_week($fromDate);
    $toMonday = mithra_heatmap_monday_of_week($toDate);
    if ($fromMonday === '' || $toMonday === '' || strcmp($fromMonday, $toMonday) > 0) {
        return 0;
    }

    $startTs = strtotime($fromMonday . ' 00:00:00');
    $endTs = strtotime($toMonday . ' 00:00:00');
    if ($startTs === false || $endTs === false || $endTs < $startTs) {
        return 0;
    }

    return (int) floor(($endTs - $startTs) / (7 * 86400)) + 1;
}

function mithra_stats_calendar_months_between(string $fromDate, string $toDate): int
{
    $from = mithra_normalize_date_only($fromDate);
    $to = mithra_normalize_date_only($toDate);
    if ($from === '' || $to === '' || strcmp($from, $to) > 0) {
        return 0;
    }

    $startYear = (int) substr($from, 0, 4);
    $startMonth = (int) substr($from, 5, 2);
    $endYear = (int) substr($to, 0, 4);
    $endMonth = (int) substr($to, 5, 2);
    if ($startYear <= 0 || $startMonth <= 0 || $endMonth <= 0 || $endYear <= 0) {
        return 0;
    }

    return (($endYear - $startYear) * 12) + ($endMonth - $startMonth) + 1;
}

function mithra_stats_entry_matches_process(array $entry, ?string $scanProcess): bool
{
    if ($scanProcess === null) {
        return true;
    }

    $value = trim((string) ($entry['scan_process'] ?? ''));
    if ($scanProcess === '(onbekend)') {
        return $value === '';
    }

    return $value === $scanProcess;
}

function mithra_stats_count_in_range(array $entries, string $fromDate, string $toDate, ?string $scanProcess = null): int
{
    $from = mithra_normalize_date_only($fromDate);
    $to = mithra_normalize_date_only($toDate);
    if ($from === '' || $to === '') {
        return 0;
    }

    $count = 0;
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (!mithra_stats_entry_matches_process($entry, $scanProcess)) {
            continue;
        }

        $date = mithra_normalize_date_only((string) ($entry['scan_timestamp'] ?? ''));
        if ($date === '') {
            continue;
        }

        if (strcmp($date, $from) >= 0 && strcmp($date, $to) <= 0) {
            $count++;
        }
    }

    return $count;
}

function mithra_stats_block(array $entries, ?string $scanProcess = null): array
{
    $filtered = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (!mithra_stats_entry_matches_process($entry, $scanProcess)) {
            continue;
        }

        $filtered[] = $entry;
    }

    $today = date('Y-m-d');
    $weekStart = mithra_stats_period_start_week($today);
    $monthStart = mithra_stats_period_start_month($today);
    $total = count($filtered);

    $firstDate = '';
    foreach ($filtered as $entry) {
        $date = mithra_normalize_date_only((string) ($entry['scan_timestamp'] ?? ''));
        if ($date === '') {
            continue;
        }
        if ($firstDate === '' || strcmp($date, $firstDate) < 0) {
            $firstDate = $date;
        }
    }

    $knownDays = $firstDate !== '' ? mithra_stats_calendar_days_between($firstDate, $today) : 0;
    $knownWeeks = $firstDate !== '' ? mithra_stats_calendar_weeks_between($firstDate, $today) : 0;
    $knownMonths = $firstDate !== '' ? mithra_stats_calendar_months_between($firstDate, $today) : 0;

    $avgDay = $knownDays > 0 ? (int) round($total / $knownDays) : 0;
    $avgWeek = $knownWeeks > 0 ? (int) round($total / $knownWeeks) : 0;
    $avgMonth = $knownMonths > 0 ? (int) round($total / $knownMonths) : 0;

    return [
        'week' => mithra_stats_count_in_range($filtered, $weekStart, $today),
        'month' => mithra_stats_count_in_range($filtered, $monthStart, $today),
        'total' => $total,
        'avg_day' => $avgDay,
        'avg_week' => $avgWeek,
        'avg_month' => $avgMonth,
        'first_scan_date' => $firstDate,
        'known_days' => $knownDays,
        'known_weeks' => $knownWeeks,
        'known_months' => $knownMonths,
    ];
}

function mithra_stats_chart_30_days(array $entries): array
{
    $today = mithra_heatmap_today_date();
    $counts = [];

    foreach (mithra_heatmap_grid_dates($today) as $date) {
        if (strcmp($date, $today) > 0) {
            continue;
        }
        $counts[$date] = 0;
    }

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $date = mithra_normalize_date_only((string) ($entry['scan_timestamp'] ?? ''));
        if ($date === '' || !isset($counts[$date])) {
            continue;
        }

        $counts[$date]++;
    }

    $chart = [];
    foreach (mithra_heatmap_grid_dates($today) as $date) {
        if (strcmp($date, $today) > 0) {
            continue;
        }
        $chart[] = [
            'date' => $date,
            'count' => (int) ($counts[$date] ?? 0),
        ];
    }

    return $chart;
}

function mithra_user_detail_payload(string $company, string $username): array
{
    $username = trim($username);
    if ($username === '') {
        throw new RuntimeException('Gebruikersnaam ontbreekt.');
    }

    $entries = mithra_store_user_entries($company, $username);
    if ($entries === []) {
        throw new RuntimeException('Geen scanregels gevonden voor deze gebruiker.');
    }

    $processes = [];
    foreach ($entries as $entry) {
        $process = trim((string) ($entry['scan_process'] ?? ''));
        if ($process === '') {
            $process = '(onbekend)';
        }
        $processes[$process] = true;
    }

    $byProcess = [];
    foreach (array_keys($processes) as $process) {
        $byProcess[] = [
            'scan_process' => $process,
            'stats' => mithra_stats_block($entries, $process),
        ];
    }

    usort($byProcess, static function (array $a, array $b): int {
        $totalA = (int) (($a['stats']['total'] ?? 0));
        $totalB = (int) (($b['stats']['total'] ?? 0));
        if ($totalA !== $totalB) {
            return $totalB <=> $totalA;
        }

        return strcasecmp((string) ($a['scan_process'] ?? ''), (string) ($b['scan_process'] ?? ''));
    });

    $countsByDate = mithra_heatmap_counts_from_entries($entries);
    $heatmapDays = mithra_heatmap_build_grid_days(
        $countsByDate,
        '',
        MITHRA_MODAL_HEATMAP_ROWS,
        MITHRA_HEATMAP_COLS
    );

    return [
        'ok' => true,
        'company' => $company,
        'username' => $username,
        'overall' => mithra_stats_block($entries, null),
        'by_process' => $byProcess,
        'chart_30_days' => mithra_stats_chart_30_days($entries),
        'heatmap_days' => $heatmapDays,
        'heatmap_rows' => MITHRA_MODAL_HEATMAP_ROWS,
        'heatmap_cols' => MITHRA_HEATMAP_COLS,
        'heatmap_intensity_max' => MITHRA_HEATMAP_INTENSITY_MAX,
    ];
}
