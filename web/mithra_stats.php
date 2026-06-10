<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/mithra_config.php';
require_once __DIR__ . '/mithra_scan_store.php';
require_once __DIR__ . '/mithra_wh_store.php';
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

function mithra_stats_entry_matches_entry_type(array $entry, ?string $entryType): bool
{
    if ($entryType === null) {
        return true;
    }

    $value = trim((string) ($entry['entry_type'] ?? ''));
    if ($entryType === '(onbekend)') {
        return $value === '';
    }

    return strcasecmp($value, $entryType) === 0;
}

function mithra_stats_count_scan_entries_in_range(array $entries, string $fromDate, string $toDate, ?string $scanProcess = null): int
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

function mithra_stats_count_wh_entries_in_range(array $entries, string $fromDate, string $toDate, ?string $entryType = null): int
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

        if (!mithra_stats_entry_matches_entry_type($entry, $entryType)) {
            continue;
        }

        $date = mithra_normalize_date_only((string) ($entry['activity_timestamp'] ?? ''));
        if ($date === '') {
            continue;
        }

        if (strcmp($date, $from) >= 0 && strcmp($date, $to) <= 0) {
            $count++;
        }
    }

    return $count;
}

function mithra_stats_filter_scan_entries(array $entries, ?string $scanProcess): array
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

    return $filtered;
}

function mithra_stats_filter_wh_entries(array $entries, ?string $entryType): array
{
    $filtered = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        if (!mithra_stats_entry_matches_entry_type($entry, $entryType)) {
            continue;
        }

        $filtered[] = $entry;
    }

    return $filtered;
}

function mithra_stats_first_activity_date(array $scanEntries, array $whEntries): string
{
    $firstDate = '';

    foreach ($scanEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $date = mithra_normalize_date_only((string) ($entry['scan_timestamp'] ?? ''));
        if ($date === '') {
            continue;
        }

        if ($firstDate === '' || strcmp($date, $firstDate) < 0) {
            $firstDate = $date;
        }
    }

    foreach ($whEntries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $date = mithra_normalize_date_only((string) ($entry['activity_timestamp'] ?? ''));
        if ($date === '') {
            continue;
        }

        if ($firstDate === '' || strcmp($date, $firstDate) < 0) {
            $firstDate = $date;
        }
    }

    return $firstDate;
}

function mithra_stats_dual_block(array $scanEntries, array $whEntries, ?string $scanProcess = null, ?string $entryType = null): array
{
    $filteredScans = mithra_stats_filter_scan_entries($scanEntries, $scanProcess);
    $filteredWh = mithra_stats_filter_wh_entries($whEntries, $entryType);

    $today = date('Y-m-d');
    $weekStart = mithra_stats_period_start_week($today);
    $monthStart = mithra_stats_period_start_month($today);
    $totalScans = count($filteredScans);
    $totalWh = count($filteredWh);

    $firstDate = mithra_stats_first_activity_date($filteredScans, $filteredWh);
    $knownDays = $firstDate !== '' ? mithra_stats_calendar_days_between($firstDate, $today) : 0;
    $knownWeeks = $firstDate !== '' ? mithra_stats_calendar_weeks_between($firstDate, $today) : 0;
    $knownMonths = $firstDate !== '' ? mithra_stats_calendar_months_between($firstDate, $today) : 0;

    $weekScans = mithra_stats_count_scan_entries_in_range($filteredScans, $weekStart, $today);
    $weekWh = mithra_stats_count_wh_entries_in_range($filteredWh, $weekStart, $today);
    $monthScans = mithra_stats_count_scan_entries_in_range($filteredScans, $monthStart, $today);
    $monthWh = mithra_stats_count_wh_entries_in_range($filteredWh, $monthStart, $today);

    return [
        'week' => ['scans' => $weekScans, 'handelingen' => $weekWh],
        'month' => ['scans' => $monthScans, 'handelingen' => $monthWh],
        'total' => ['scans' => $totalScans, 'handelingen' => $totalWh],
        'avg_day' => [
            'scans' => $knownDays > 0 ? (int) round($totalScans / $knownDays) : 0,
            'handelingen' => $knownDays > 0 ? (int) round($totalWh / $knownDays) : 0,
        ],
        'avg_week' => [
            'scans' => $knownWeeks > 0 ? (int) round($totalScans / $knownWeeks) : 0,
            'handelingen' => $knownWeeks > 0 ? (int) round($totalWh / $knownWeeks) : 0,
        ],
        'avg_month' => [
            'scans' => $knownMonths > 0 ? (int) round($totalScans / $knownMonths) : 0,
            'handelingen' => $knownMonths > 0 ? (int) round($totalWh / $knownMonths) : 0,
        ],
        'first_activity_date' => $firstDate,
        'known_days' => $knownDays,
        'known_weeks' => $knownWeeks,
        'known_months' => $knownMonths,
    ];
}

function mithra_stats_chart_30_days(array $entries, string $timestampField): array
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

        $date = mithra_normalize_date_only((string) ($entry[$timestampField] ?? ''));
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
    $username = mithra_normalize_username(trim($username));
    if ($username === '') {
        throw new RuntimeException('Gebruikersnaam ontbreekt.');
    }

    $scanEntries = [];
    foreach (mithra_store_activity_username_variants($company, $username) as $variant) {
        $scanEntries = array_merge($scanEntries, mithra_store_user_entries($company, $variant));
    }

    $whEntries = [];
    foreach (mithra_store_activity_username_variants($company, $username) as $variant) {
        $whEntries = array_merge($whEntries, mithra_wh_store_user_entries($company, $variant));
    }
    if ($scanEntries === [] && $whEntries === []) {
        throw new RuntimeException('Geen activiteit gevonden voor deze gebruiker.');
    }

    $processes = [];
    foreach ($scanEntries as $entry) {
        $process = trim((string) ($entry['scan_process'] ?? ''));
        if ($process === '') {
            $process = '(onbekend)';
        }
        $processes[$process] = true;
    }

    $entryTypes = [];
    foreach ($whEntries as $entry) {
        $entryType = trim((string) ($entry['entry_type'] ?? ''));
        if ($entryType === '') {
            $entryType = '(onbekend)';
        }
        $entryTypes[$entryType] = true;
    }

    $byProcess = [];
    foreach (array_keys($processes) as $process) {
        $byProcess[] = [
            'scan_process' => $process,
            'stats' => mithra_stats_dual_block($scanEntries, [], $process, null),
        ];
    }

    usort($byProcess, static function (array $a, array $b): int {
        $totalA = (int) (($a['stats']['total']['scans'] ?? 0));
        $totalB = (int) (($b['stats']['total']['scans'] ?? 0));
        if ($totalA !== $totalB) {
            return $totalB <=> $totalA;
        }

        return strcasecmp((string) ($a['scan_process'] ?? ''), (string) ($b['scan_process'] ?? ''));
    });

    $byEntryType = [];
    foreach (array_keys($entryTypes) as $entryType) {
        $byEntryType[] = [
            'entry_type' => $entryType,
            'stats' => mithra_stats_dual_block([], $whEntries, null, $entryType),
        ];
    }

    usort($byEntryType, static function (array $a, array $b): int {
        $totalA = (int) (($a['stats']['total']['handelingen'] ?? 0));
        $totalB = (int) (($b['stats']['total']['handelingen'] ?? 0));
        if ($totalA !== $totalB) {
            return $totalB <=> $totalA;
        }

        return strcasecmp((string) ($a['entry_type'] ?? ''), (string) ($b['entry_type'] ?? ''));
    });

    $combinedCounts = mithra_store_merge_daily_counts(
        mithra_heatmap_counts_from_entries($scanEntries, 'scan_timestamp'),
        mithra_heatmap_counts_from_entries($whEntries, 'activity_timestamp')
    );
    $heatmapDays = mithra_heatmap_build_grid_days(
        $combinedCounts,
        '',
        MITHRA_MODAL_HEATMAP_ROWS,
        MITHRA_HEATMAP_COLS
    );

    return [
        'ok' => true,
        'company' => $company,
        'username' => $username,
        'overall' => mithra_stats_dual_block($scanEntries, $whEntries),
        'by_process' => $byProcess,
        'by_entry_type' => $byEntryType,
        'chart_30_days_scans' => mithra_stats_chart_30_days($scanEntries, 'scan_timestamp'),
        'chart_30_days_wh' => mithra_stats_chart_30_days($whEntries, 'activity_timestamp'),
        'heatmap_days' => $heatmapDays,
        'heatmap_rows' => MITHRA_MODAL_HEATMAP_ROWS,
        'heatmap_cols' => MITHRA_HEATMAP_COLS,
        'heatmap_intensity_max' => MITHRA_HEATMAP_INTENSITY_MAX,
    ];
}
