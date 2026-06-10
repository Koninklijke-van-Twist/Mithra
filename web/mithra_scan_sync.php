<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/mithra_bc.php';
require_once __DIR__ . '/mithra_scan_store.php';
require_once __DIR__ . '/mithra_wh_store.php';
require_once __DIR__ . '/mithra_heatmap.php';

/**
 * Functies
 */
function mithra_sync_today_date(): string
{
    return date('Y-m-d');
}

function mithra_sync_date_shift(string $date, int $dayOffset): string
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

function mithra_sync_backfill_limit_date(): string
{
    return mithra_sync_date_shift(mithra_sync_today_date(), -MITHRA_BACKFILL_MAX_DAYS);
}

function mithra_sync_month_key(string $date): string
{
    $normalized = mithra_normalize_date_only($date);
    if ($normalized === '') {
        return '';
    }

    return substr($normalized, 0, 7);
}

function mithra_sync_previous_month_key(string $monthKey): string
{
    $monthKey = trim($monthKey);
    if (!preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
        return '';
    }

    $timestamp = strtotime($monthKey . '-01 00:00:00 -1 month');
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m', $timestamp);
}

function mithra_sync_decode_empty_months(mixed $value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return [];
    }

    $months = [];
    foreach ($decoded as $monthKey) {
        $monthKey = trim((string) $monthKey);
        if (preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            $months[$monthKey] = true;
        }
    }

    $result = array_keys($months);
    sort($result, SORT_STRING);
    return $result;
}

function mithra_sync_encode_empty_months(array $months): string
{
    $normalized = [];
    foreach ($months as $monthKey) {
        $monthKey = trim((string) $monthKey);
        if (preg_match('/^\d{4}-\d{2}$/', $monthKey)) {
            $normalized[$monthKey] = true;
        }
    }

    $result = array_keys($normalized);
    sort($result, SORT_STRING);

    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

function mithra_sync_months_in_range(string $fromDate, string $toDate): array
{
    $from = mithra_normalize_date_only($fromDate);
    $to = mithra_normalize_date_only($toDate);
    if ($from === '' || $to === '') {
        return [];
    }

    if (strcmp($from, $to) > 0) {
        $swap = $from;
        $from = $to;
        $to = $swap;
    }

    $months = [];
    $cursor = $from;
    while (strcmp($cursor, $to) <= 0) {
        $monthKey = mithra_sync_month_key($cursor);
        if ($monthKey !== '') {
            $months[$monthKey] = true;
        }
        $cursor = mithra_sync_date_shift($cursor, 1);
        if ($cursor === '') {
            break;
        }
    }

    $result = array_keys($months);
    sort($result, SORT_STRING);
    return $result;
}

function mithra_sync_has_consecutive_empty_months(array $emptyMonths, int $requiredRun): bool
{
    if ($requiredRun < 2 || count($emptyMonths) < $requiredRun) {
        return false;
    }

    $sorted = array_values($emptyMonths);
    sort($sorted, SORT_STRING);

    $run = 1;
    for ($index = 1, $count = count($sorted); $index < $count; $index++) {
        $expectedPrevious = mithra_sync_previous_month_key($sorted[$index]);
        if ($expectedPrevious !== '' && $expectedPrevious === $sorted[$index - 1]) {
            $run++;
            if ($run >= $requiredRun) {
                return true;
            }
            continue;
        }

        $run = 1;
    }

    return false;
}

function mithra_sync_estimated_total_chunks(): int
{
    $today = mithra_sync_today_date();
    $limit = mithra_sync_backfill_limit_date();
    $startTs = strtotime($today . ' 00:00:00');
    $limitTs = strtotime($limit . ' 00:00:00');
    if ($startTs === false || $limitTs === false || $startTs <= $limitTs) {
        return 1;
    }

    $days = (int) floor(($startTs - $limitTs) / 86400) + 1;
    return max(1, (int) ceil($days / MITHRA_SYNC_CHUNK_DAYS));
}

function mithra_sync_chunk_progress(int $chunkCurrent, bool $backfillComplete): array
{
    if ($backfillComplete) {
        return [
            'chunk_current' => 0,
            'chunk_total' => 0,
        ];
    }

    $estimatedTotal = mithra_sync_estimated_total_chunks();
    return [
        'chunk_current' => $chunkCurrent,
        'chunk_total' => max($estimatedTotal, $chunkCurrent),
    ];
}

function mithra_sync_refresh_backfill_state(array $state, string $company): array
{
    if ((int) ($state['backfill_complete'] ?? 0) === 1 && trim((string) ($state['backfill_to_date'] ?? '')) === '') {
        $oldestDate = mithra_normalize_date_only((string) ($state['oldest_scan_timestamp'] ?? ''));
        if ($oldestDate === '') {
            $bounds = mithra_store_recompute_bounds($company);
            $oldestDate = mithra_normalize_date_only((string) ($bounds['oldest_scan_timestamp'] ?? ''));
        }

        if ($oldestDate !== '' && strcmp($oldestDate, mithra_sync_backfill_limit_date()) > 0) {
            $state['backfill_complete'] = 0;
        }
    }

    if ((int) ($state['wh_backfill_complete'] ?? 0) === 1 && trim((string) ($state['wh_backfill_to_date'] ?? '')) === '') {
        $oldestDate = mithra_normalize_date_only((string) ($state['wh_oldest_activity_date'] ?? ''));
        if ($oldestDate === '') {
            $bounds = mithra_wh_store_recompute_bounds($company);
            $oldestDate = trim((string) ($bounds['wh_oldest_activity_date'] ?? ''));
        }

        if ($oldestDate !== '' && strcmp($oldestDate, mithra_sync_backfill_limit_date()) > 0) {
            $state['wh_backfill_complete'] = 0;
        }
    }

    return $state;
}

function mithra_sync_needs_backfill_chunks(array $state): bool
{
    return (int) ($state['backfill_complete'] ?? 0) !== 1
        || (int) ($state['wh_backfill_complete'] ?? 0) !== 1;
}

function mithra_sync_apply_wh_bounds(array $state, array $bounds): array
{
    if (trim((string) ($bounds['wh_newest_activity_date'] ?? '')) !== '') {
        $state['wh_newest_activity_date'] = trim((string) ($bounds['wh_newest_activity_date'] ?? ''));
        $state['wh_newest_entry_no'] = (int) ($bounds['wh_newest_entry_no'] ?? 0);
    }
    if (trim((string) ($bounds['wh_oldest_activity_date'] ?? '')) !== '') {
        $state['wh_oldest_activity_date'] = trim((string) ($bounds['wh_oldest_activity_date'] ?? ''));
    }

    return $state;
}

function mithra_sync_fetch_scan_forward(string $company, array $state): array
{
    $newest = trim((string) ($state['newest_scan_timestamp'] ?? ''));
    if ($newest === '') {
        return [];
    }

    return mithra_fetch_scanposten_newer_than($company, $newest);
}

function mithra_sync_fetch_wh_forward(string $company, array $state): array
{
    $newestDate = trim((string) ($state['wh_newest_activity_date'] ?? ''));
    if ($newestDate === '') {
        return [];
    }

    return mithra_fetch_magazijnposten_newer_than(
        $company,
        $newestDate,
        (int) ($state['wh_newest_entry_no'] ?? 0)
    );
}

function mithra_sync_resolve_wh_backfill_range(array $state, string $company): array
{
    $backfillToDate = trim((string) ($state['wh_backfill_to_date'] ?? ''));

    if ($backfillToDate === '') {
        $oldest = trim((string) ($state['wh_oldest_activity_date'] ?? ''));
        if ($oldest === '') {
            $bounds = mithra_wh_store_recompute_bounds($company);
            $oldest = trim((string) ($bounds['wh_oldest_activity_date'] ?? ''));
        }

        if ($oldest !== '') {
            $backfillToDate = mithra_sync_date_shift($oldest, -1);
            $mode = 'backfill';
        } else {
            $backfillToDate = mithra_sync_today_date();
            $mode = 'initial';
        }
    } else {
        $mode = 'backfill';
    }

    if ($backfillToDate === '') {
        return [
            'mode' => 'idle',
            'from_date' => '',
            'to_date' => '',
            'next_backfill_to_date' => '',
        ];
    }

    $fromDate = mithra_sync_date_shift($backfillToDate, -(MITHRA_SYNC_CHUNK_DAYS - 1));
    $nextBackfillToDate = mithra_sync_date_shift($fromDate, -1);

    return [
        'mode' => $mode,
        'from_date' => $fromDate,
        'to_date' => $backfillToDate,
        'next_backfill_to_date' => $nextBackfillToDate,
    ];
}

function mithra_sync_update_wh_backfill_state(array $newState, array $backfillRange, array $whBackfillEntries, string $fromDate, string $toDate, array $emptyMonths): array
{
    if ((int) ($newState['wh_backfill_complete'] ?? 0) === 1 || $fromDate === '' || $toDate === '') {
        return $newState;
    }

    $newState['wh_backfill_to_date'] = trim((string) ($backfillRange['next_backfill_to_date'] ?? ''));

    if ($whBackfillEntries === []) {
        foreach (mithra_sync_months_in_range($fromDate, $toDate) as $monthKey) {
            $emptyMonths[] = $monthKey;
        }
        $emptyMonths = mithra_sync_decode_empty_months(mithra_sync_encode_empty_months($emptyMonths));
        $newState['wh_empty_backfill_months'] = mithra_sync_encode_empty_months($emptyMonths);

        if (mithra_sync_has_consecutive_empty_months($emptyMonths, MITHRA_BACKFILL_EMPTY_MONTHS_STOP)) {
            $newState['wh_backfill_complete'] = 1;
            $newState['wh_backfill_to_date'] = '';
        }
    } else {
        $newState['wh_empty_backfill_months'] = '';
    }

    $limitDate = mithra_sync_backfill_limit_date();
    if ((int) ($newState['wh_backfill_complete'] ?? 0) !== 1 && strcmp($fromDate, $limitDate) <= 0) {
        $newState['wh_backfill_complete'] = 1;
        $newState['wh_backfill_to_date'] = '';
    }

    return $newState;
}

function mithra_sync_run(string $company): array
{
    $state = mithra_sync_refresh_backfill_state(mithra_store_get_sync_state($company), $company);

    if (!mithra_sync_needs_backfill_chunks($state)) {
        return mithra_sync_forward_only($company, $state);
    }

    return mithra_sync_backfill_chunk($company, $state);
}

function mithra_sync_forward_only(string $company, array $state): array
{
    $bounds = mithra_store_recompute_bounds($company);
    $whBounds = mithra_wh_store_recompute_bounds($company);

    if ($bounds['newest_scan_timestamp'] !== '') {
        $state['newest_scan_timestamp'] = $bounds['newest_scan_timestamp'];
    }
    if ($bounds['oldest_scan_timestamp'] !== '') {
        $state['oldest_scan_timestamp'] = $bounds['oldest_scan_timestamp'];
    }
    $state = mithra_sync_apply_wh_bounds($state, $whBounds);

    $scanEntries = mithra_sync_fetch_scan_forward($company, $state);
    $whEntries = mithra_sync_fetch_wh_forward($company, $state);

    $insertedScans = mithra_store_insert_entries($company, $scanEntries);
    $insertedWh = mithra_wh_store_insert_entries($company, $whEntries);

    $bounds = mithra_store_recompute_bounds($company);
    $whBounds = mithra_wh_store_recompute_bounds($company);

    $newState = [
        'newest_scan_timestamp' => $bounds['newest_scan_timestamp'],
        'oldest_scan_timestamp' => $bounds['oldest_scan_timestamp'],
        'backfill_to_date' => '',
        'backfill_complete' => 1,
        'chunks_completed' => (int) ($state['chunks_completed'] ?? 0),
        'empty_backfill_months' => '',
        'wh_newest_activity_date' => trim((string) ($whBounds['wh_newest_activity_date'] ?? '')),
        'wh_newest_entry_no' => (int) ($whBounds['wh_newest_entry_no'] ?? 0),
        'wh_oldest_activity_date' => trim((string) ($whBounds['wh_oldest_activity_date'] ?? '')),
        'wh_backfill_to_date' => '',
        'wh_backfill_complete' => 1,
        'wh_chunks_completed' => (int) ($state['wh_chunks_completed'] ?? 0),
        'wh_empty_backfill_months' => '',
        'updated_at' => gmdate('c'),
    ];

    mithra_store_save_sync_state($company, $newState);

    return [
        'ok' => true,
        'company' => $company,
        'sync_phase' => 'forward',
        'mode' => 'forward',
        'from_date' => '',
        'to_date' => '',
        'fetched_count' => count($scanEntries) + count($whEntries),
        'inserted_count' => $insertedScans + $insertedWh,
        'inserted_scans' => $insertedScans,
        'inserted_wh' => $insertedWh,
        'chunk_current' => 0,
        'chunk_total' => 0,
        'sync_state' => $newState,
        'sync_complete' => true,
        'needs_more_chunks' => false,
    ];
}

function mithra_sync_resolve_backfill_range(array $state, string $company): array
{
    $backfillToDate = trim((string) ($state['backfill_to_date'] ?? ''));

    if ($backfillToDate === '') {
        $oldest = trim((string) ($state['oldest_scan_timestamp'] ?? ''));
        if ($oldest === '') {
            $bounds = mithra_store_recompute_bounds($company);
            $oldest = trim((string) ($bounds['oldest_scan_timestamp'] ?? ''));
        }

        if ($oldest !== '') {
            $oldestDate = mithra_normalize_date_only($oldest);
            $backfillToDate = mithra_sync_date_shift($oldestDate, -1);
            $mode = 'backfill';
        } else {
            $backfillToDate = mithra_sync_today_date();
            $mode = 'initial';
        }
    } else {
        $mode = 'backfill';
    }

    if ($backfillToDate === '') {
        return [
            'mode' => 'idle',
            'from_date' => '',
            'to_date' => '',
            'next_backfill_to_date' => '',
        ];
    }

    $fromDate = mithra_sync_date_shift($backfillToDate, -(MITHRA_SYNC_CHUNK_DAYS - 1));
    $nextBackfillToDate = mithra_sync_date_shift($fromDate, -1);

    return [
        'mode' => $mode,
        'from_date' => $fromDate,
        'to_date' => $backfillToDate,
        'next_backfill_to_date' => $nextBackfillToDate,
    ];
}

function mithra_sync_backfill_chunk(string $company, array $state): array
{
    $bounds = mithra_store_recompute_bounds($company);
    $whBounds = mithra_wh_store_recompute_bounds($company);

    $chunksCompleted = max((int) ($state['chunks_completed'] ?? 0), (int) ($state['wh_chunks_completed'] ?? 0));
    $chunkCurrent = $chunksCompleted + 1;

    if ($bounds['newest_scan_timestamp'] !== '') {
        $state['newest_scan_timestamp'] = $bounds['newest_scan_timestamp'];
    }
    if ($bounds['oldest_scan_timestamp'] !== '') {
        $state['oldest_scan_timestamp'] = $bounds['oldest_scan_timestamp'];
    }
    $state = mithra_sync_apply_wh_bounds($state, $whBounds);

    $modes = [];
    $scanFetched = [];
    $whFetched = [];
    $scanBackfillEntries = [];
    $whBackfillEntries = [];
    $fromDate = '';
    $toDate = '';
    $whFromDate = '';
    $whToDate = '';

    $scanForward = mithra_sync_fetch_scan_forward($company, $state);
    if ($scanForward !== []) {
        $modes[] = 'forward-scan';
        $scanFetched = array_merge($scanFetched, $scanForward);
    }

    $whForward = mithra_sync_fetch_wh_forward($company, $state);
    if ($whForward !== []) {
        $modes[] = 'forward-wh';
        $whFetched = array_merge($whFetched, $whForward);
    }

    $backfillRange = [
        'mode' => 'idle',
        'from_date' => '',
        'to_date' => '',
        'next_backfill_to_date' => trim((string) ($state['backfill_to_date'] ?? '')),
    ];

    if ((int) ($state['backfill_complete'] ?? 0) !== 1) {
        $backfillRange = mithra_sync_resolve_backfill_range($state, $company);
        $fromDate = (string) ($backfillRange['from_date'] ?? '');
        $toDate = (string) ($backfillRange['to_date'] ?? '');

        if ($fromDate !== '' && $toDate !== '') {
            $modes[] = (string) ($backfillRange['mode'] ?? 'backfill-scan');
            $scanBackfillEntries = mithra_fetch_scanposten_range($company, $fromDate, $toDate);
            $scanFetched = array_merge($scanFetched, $scanBackfillEntries);
        }
    }

    $whBackfillRange = [
        'mode' => 'idle',
        'from_date' => '',
        'to_date' => '',
        'next_backfill_to_date' => trim((string) ($state['wh_backfill_to_date'] ?? '')),
    ];

    if ((int) ($state['wh_backfill_complete'] ?? 0) !== 1) {
        $whBackfillRange = mithra_sync_resolve_wh_backfill_range($state, $company);
        $whFromDate = (string) ($whBackfillRange['from_date'] ?? '');
        $whToDate = (string) ($whBackfillRange['to_date'] ?? '');

        if ($whFromDate !== '' && $whToDate !== '') {
            $modes[] = (string) ($whBackfillRange['mode'] ?? 'backfill-wh');
            $whBackfillEntries = mithra_fetch_magazijnposten_range($company, $whFromDate, $whToDate);
            $whFetched = array_merge($whFetched, $whBackfillEntries);
        }
    }

    $insertedScans = mithra_store_insert_entries($company, $scanFetched);
    $insertedWh = mithra_wh_store_insert_entries($company, $whFetched);
    $bounds = mithra_store_recompute_bounds($company);
    $whBounds = mithra_wh_store_recompute_bounds($company);

    $emptyMonths = mithra_sync_decode_empty_months($state['empty_backfill_months'] ?? '');
    $whEmptyMonths = mithra_sync_decode_empty_months($state['wh_empty_backfill_months'] ?? '');

    $newState = [
        'newest_scan_timestamp' => $bounds['newest_scan_timestamp'],
        'oldest_scan_timestamp' => $bounds['oldest_scan_timestamp'],
        'backfill_to_date' => trim((string) ($state['backfill_to_date'] ?? '')),
        'backfill_complete' => (int) ($state['backfill_complete'] ?? 0),
        'chunks_completed' => $chunkCurrent,
        'empty_backfill_months' => mithra_sync_encode_empty_months($emptyMonths),
        'wh_newest_activity_date' => trim((string) ($whBounds['wh_newest_activity_date'] ?? '')),
        'wh_newest_entry_no' => (int) ($whBounds['wh_newest_entry_no'] ?? 0),
        'wh_oldest_activity_date' => trim((string) ($whBounds['wh_oldest_activity_date'] ?? '')),
        'wh_backfill_to_date' => trim((string) ($state['wh_backfill_to_date'] ?? '')),
        'wh_backfill_complete' => (int) ($state['wh_backfill_complete'] ?? 0),
        'wh_chunks_completed' => $chunkCurrent,
        'wh_empty_backfill_months' => mithra_sync_encode_empty_months($whEmptyMonths),
        'updated_at' => gmdate('c'),
    ];

    if ((int) $newState['backfill_complete'] !== 1 && $fromDate !== '' && $toDate !== '') {
        $newState['backfill_to_date'] = trim((string) ($backfillRange['next_backfill_to_date'] ?? ''));

        if ($scanBackfillEntries === []) {
            foreach (mithra_sync_months_in_range($fromDate, $toDate) as $monthKey) {
                $emptyMonths[] = $monthKey;
            }
            $emptyMonths = mithra_sync_decode_empty_months(mithra_sync_encode_empty_months($emptyMonths));
            $newState['empty_backfill_months'] = mithra_sync_encode_empty_months($emptyMonths);

            if (mithra_sync_has_consecutive_empty_months($emptyMonths, MITHRA_BACKFILL_EMPTY_MONTHS_STOP)) {
                $newState['backfill_complete'] = 1;
                $newState['backfill_to_date'] = '';
            }
        } else {
            $newState['empty_backfill_months'] = '';
        }

        $limitDate = mithra_sync_backfill_limit_date();
        if ((int) $newState['backfill_complete'] !== 1 && strcmp($fromDate, $limitDate) <= 0) {
            $newState['backfill_complete'] = 1;
            $newState['backfill_to_date'] = '';
        }
    }

    $newState = mithra_sync_update_wh_backfill_state(
        $newState,
        $whBackfillRange,
        $whBackfillEntries,
        $whFromDate,
        $whToDate,
        $whEmptyMonths
    );

    mithra_store_save_sync_state($company, $newState);

    $modeLabel = $modes !== [] ? implode('+', array_values(array_unique($modes))) : 'idle';
    $chunkProgress = mithra_sync_chunk_progress(
        $chunkCurrent,
        (int) $newState['backfill_complete'] === 1 && (int) $newState['wh_backfill_complete'] === 1
    );
    $displayFromDate = $fromDate !== '' ? $fromDate : $whFromDate;
    $displayToDate = $toDate !== '' ? $toDate : $whToDate;

    return [
        'ok' => true,
        'company' => $company,
        'sync_phase' => 'backfill',
        'mode' => $modeLabel,
        'from_date' => $displayFromDate,
        'to_date' => $displayToDate,
        'fetched_count' => count($scanFetched) + count($whFetched),
        'inserted_count' => $insertedScans + $insertedWh,
        'inserted_scans' => $insertedScans,
        'inserted_wh' => $insertedWh,
        'chunk_current' => $chunkProgress['chunk_current'],
        'chunk_total' => $chunkProgress['chunk_total'],
        'sync_state' => $newState,
        'sync_complete' => (int) $newState['backfill_complete'] === 1 && (int) $newState['wh_backfill_complete'] === 1,
        'needs_more_chunks' => mithra_sync_needs_backfill_chunks($newState),
    ];
}

function mithra_sync_one_chunk(string $company): array
{
    return mithra_sync_run($company);
}

function mithra_overview_payload(string $company): array
{
    $today = mithra_sync_today_date();
    $fromDate = mithra_heatmap_grid_from_date($today);

    $users = [];
    foreach (mithra_store_list_activity_usernames($company) as $username) {
        $scanCounts = mithra_store_merged_scan_daily_counts($company, $username, $fromDate, $today);
        $whCounts = mithra_store_merged_wh_daily_counts($company, $username, $fromDate, $today);
        $counts = mithra_store_merge_daily_counts($scanCounts, $whCounts);
        $days = mithra_heatmap_build_grid_days($counts, $today);

        $users[] = [
            'username' => $username,
            'days' => $days,
        ];
    }

    usort($users, 'mithra_heatmap_compare_users_by_activity');

    return [
        'ok' => true,
        'company' => $company,
        'heatmap_days' => MITHRA_HEATMAP_DAYS,
        'heatmap_cols' => MITHRA_HEATMAP_COLS,
        'heatmap_rows' => MITHRA_HEATMAP_ROWS,
        'heatmap_intensity_max' => MITHRA_HEATMAP_INTENSITY_MAX,
        'sync_state' => mithra_store_get_sync_state($company),
        'users' => $users,
    ];
}
