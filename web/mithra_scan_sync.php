<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/mithra_bc.php';
require_once __DIR__ . '/mithra_scan_store.php';
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
            'chunk_current' => $chunkCurrent,
            'chunk_total' => $chunkCurrent,
        ];
    }

    $estimatedTotal = mithra_sync_estimated_total_chunks();
    return [
        'chunk_current' => $chunkCurrent,
        'chunk_total' => max($estimatedTotal, $chunkCurrent),
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

function mithra_sync_one_chunk(string $company): array
{
    $state = mithra_store_get_sync_state($company);

    if ((int) ($state['backfill_complete'] ?? 0) === 1 && trim((string) ($state['backfill_to_date'] ?? '')) === '') {
        $oldestDate = mithra_normalize_date_only((string) ($state['oldest_scan_timestamp'] ?? ''));
        if ($oldestDate !== '' && strcmp($oldestDate, mithra_sync_backfill_limit_date()) > 0) {
            $state['backfill_complete'] = 0;
        }
    }

    $bounds = mithra_store_recompute_bounds($company);

    $chunksCompleted = (int) ($state['chunks_completed'] ?? 0);
    $chunkCurrent = $chunksCompleted + 1;

    if ($bounds['newest_scan_timestamp'] !== '') {
        $state['newest_scan_timestamp'] = $bounds['newest_scan_timestamp'];
    }
    if ($bounds['oldest_scan_timestamp'] !== '') {
        $state['oldest_scan_timestamp'] = $bounds['oldest_scan_timestamp'];
    }

    $modes = [];
    $fetchedEntries = [];
    $backfillEntries = [];
    $fromDate = '';
    $toDate = '';

    $newest = trim((string) ($state['newest_scan_timestamp'] ?? ''));
    if ($newest !== '') {
        $modes[] = 'forward';
        $fetchedEntries = array_merge(
            $fetchedEntries,
            mithra_fetch_scanposten_newer_than($company, $newest)
        );
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
            $modes[] = (string) ($backfillRange['mode'] ?? 'backfill');
            $backfillEntries = mithra_fetch_scanposten_range($company, $fromDate, $toDate);
            $fetchedEntries = array_merge($fetchedEntries, $backfillEntries);
        }
    }

    $inserted = mithra_store_insert_entries($company, $fetchedEntries);
    $bounds = mithra_store_recompute_bounds($company);

    $emptyMonths = mithra_sync_decode_empty_months($state['empty_backfill_months'] ?? '');

    $newState = [
        'newest_scan_timestamp' => $bounds['newest_scan_timestamp'],
        'oldest_scan_timestamp' => $bounds['oldest_scan_timestamp'],
        'backfill_to_date' => trim((string) ($state['backfill_to_date'] ?? '')),
        'backfill_complete' => (int) ($state['backfill_complete'] ?? 0),
        'chunks_completed' => $chunkCurrent,
        'empty_backfill_months' => mithra_sync_encode_empty_months($emptyMonths),
        'updated_at' => gmdate('c'),
    ];

    if ((int) $newState['backfill_complete'] !== 1 && $fromDate !== '' && $toDate !== '') {
        $newState['backfill_to_date'] = trim((string) ($backfillRange['next_backfill_to_date'] ?? ''));

        if ($backfillEntries === []) {
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

    mithra_store_save_sync_state($company, $newState);

    $modeLabel = $modes !== [] ? implode('+', array_values(array_unique($modes))) : 'idle';
    $chunkProgress = mithra_sync_chunk_progress($chunkCurrent, (int) $newState['backfill_complete'] === 1);

    return [
        'ok' => true,
        'company' => $company,
        'mode' => $modeLabel,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'fetched_count' => count($fetchedEntries),
        'inserted_count' => $inserted,
        'chunk_current' => $chunkProgress['chunk_current'],
        'chunk_total' => $chunkProgress['chunk_total'],
        'sync_state' => $newState,
        'sync_complete' => (int) $newState['backfill_complete'] === 1,
        'needs_more_chunks' => (int) $newState['backfill_complete'] !== 1,
    ];
}

function mithra_overview_payload(string $company): array
{
    $today = mithra_sync_today_date();
    $fromDate = mithra_heatmap_grid_from_date($today);

    $users = [];
    foreach (mithra_store_list_usernames($company) as $username) {
        $counts = mithra_store_daily_counts($company, $username, $fromDate, $today);
        $days = mithra_heatmap_build_grid_days($counts, $today);

        $users[] = [
            'username' => $username,
            'days' => $days,
        ];
    }

    usort($users, static function (array $a, array $b): int {
        $sumA = 0;
        foreach ($a['days'] as $day) {
            if (!empty($day['future'])) {
                continue;
            }
            $sumA += (int) ($day['count'] ?? 0);
        }
        $sumB = 0;
        foreach ($b['days'] as $day) {
            if (!empty($day['future'])) {
                continue;
            }
            $sumB += (int) ($day['count'] ?? 0);
        }

        if ($sumA !== $sumB) {
            return $sumB <=> $sumA;
        }

        return strcasecmp((string) ($a['username'] ?? ''), (string) ($b['username'] ?? ''));
    });

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
