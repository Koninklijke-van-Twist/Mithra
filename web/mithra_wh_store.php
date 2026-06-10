<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/mithra_scan_store.php';

/**
 * Functies
 */
function mithra_wh_store_insert_entries(string $company, array $entries): int
{
    if ($entries === []) {
        return 0;
    }

    $companyKey = mithra_store_company_key($company);
    $db = mithra_store_open_db();
    $db->exec('BEGIN IMMEDIATE');
    $stmt = $db->prepare('INSERT OR IGNORE INTO warehouse_entries (company, entry_no, username, entry_type, whse_document_no, action_label, activity_timestamp)
        VALUES (:company, :entry_no, :username, :entry_type, :whse_document_no, :action_label, :activity_timestamp)');

    $inserted = 0;
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $entryNo = (int) ($entry['entry_no'] ?? 0);
        if ($entryNo <= 0) {
            continue;
        }

        $stmt->bindValue(':company', $companyKey, SQLITE3_TEXT);
        $stmt->bindValue(':entry_no', $entryNo, SQLITE3_INTEGER);
        $stmt->bindValue(':username', mithra_normalize_username(trim((string) ($entry['username'] ?? ''))), SQLITE3_TEXT);
        $stmt->bindValue(':entry_type', trim((string) ($entry['entry_type'] ?? '')), SQLITE3_TEXT);
        $stmt->bindValue(':whse_document_no', trim((string) ($entry['whse_document_no'] ?? '')), SQLITE3_TEXT);
        $stmt->bindValue(':action_label', trim((string) ($entry['action_label'] ?? '')), SQLITE3_TEXT);
        $stmt->bindValue(':activity_timestamp', trim((string) ($entry['activity_timestamp'] ?? '')), SQLITE3_TEXT);
        $stmt->execute();

        if ($db->changes() > 0) {
            $inserted++;
        }
    }

    $db->exec('COMMIT');
    $db->close();

    return $inserted;
}

function mithra_wh_store_recompute_bounds(string $company): array
{
    $companyKey = mithra_store_company_key($company);
    $db = mithra_store_open_db();
    $escaped = SQLite3::escapeString($companyKey);
    $result = $db->query("SELECT MIN(substr(activity_timestamp, 1, 10)) AS oldest_date, MAX(substr(activity_timestamp, 1, 10)) AS newest_date FROM warehouse_entries WHERE company = '" . $escaped . "'");
    $row = $result instanceof SQLite3Result ? $result->fetchArray(SQLITE3_ASSOC) : null;
    if ($result instanceof SQLite3Result) {
        $result->finalize();
    }

    $newestDate = trim((string) ($row['newest_date'] ?? ''));
    $newestEntryNo = 0;
    if ($newestDate !== '') {
        $stmt = $db->prepare('SELECT MAX(entry_no) AS newest_entry_no FROM warehouse_entries WHERE company = :company AND substr(activity_timestamp, 1, 10) = :activity_date');
        $stmt->bindValue(':company', $companyKey, SQLITE3_TEXT);
        $stmt->bindValue(':activity_date', $newestDate, SQLITE3_TEXT);
        $newestResult = $stmt->execute();
        $newestRow = $newestResult instanceof SQLite3Result ? $newestResult->fetchArray(SQLITE3_ASSOC) : null;
        if ($newestResult instanceof SQLite3Result) {
            $newestResult->finalize();
        }
        $newestEntryNo = (int) ($newestRow['newest_entry_no'] ?? 0);
    }

    $db->close();

    return [
        'wh_oldest_activity_date' => trim((string) ($row['oldest_date'] ?? '')),
        'wh_newest_activity_date' => $newestDate,
        'wh_newest_entry_no' => $newestEntryNo,
    ];
}

function mithra_wh_store_list_usernames(string $company): array
{
    $companyKey = mithra_store_company_key($company);
    $db = mithra_store_open_db();
    $result = $db->query("SELECT DISTINCT username FROM warehouse_entries WHERE company = '" . SQLite3::escapeString($companyKey) . "' AND TRIM(username) <> '' ORDER BY username COLLATE NOCASE ASC");
    $usernames = [];
    if ($result instanceof SQLite3Result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['username'] ?? ''));
            if ($name !== '') {
                $usernames[] = $name;
            }
        }
        $result->finalize();
    }
    $db->close();

    return $usernames;
}

function mithra_wh_store_daily_counts(string $company, string $username, string $fromDate, string $toDate): array
{
    $companyKey = mithra_store_company_key($company);
    $from = mithra_normalize_date_only($fromDate);
    $to = mithra_normalize_date_only($toDate);
    if ($from === '' || $to === '') {
        return [];
    }

    $db = mithra_store_open_db();
    $stmt = $db->prepare('SELECT substr(activity_timestamp, 1, 10) AS activity_date, COUNT(*) AS activity_count
        FROM warehouse_entries
        WHERE company = :company
          AND username = :username
          AND substr(activity_timestamp, 1, 10) >= :from_date
          AND substr(activity_timestamp, 1, 10) <= :to_date
        GROUP BY substr(activity_timestamp, 1, 10)
        ORDER BY activity_date ASC');
    $stmt->bindValue(':company', $companyKey, SQLITE3_TEXT);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':from_date', $from, SQLITE3_TEXT);
    $stmt->bindValue(':to_date', $to, SQLITE3_TEXT);
    $result = $stmt->execute();

    $counts = [];
    if ($result instanceof SQLite3Result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $date = trim((string) ($row['activity_date'] ?? ''));
            if ($date === '') {
                continue;
            }
            $counts[$date] = (int) ($row['activity_count'] ?? 0);
        }
        $result->finalize();
    }
    $db->close();

    return $counts;
}

function mithra_wh_store_company_daily_counts(string $company, string $fromDate, string $toDate): array
{
    $companyKey = mithra_store_company_key($company);
    $from = mithra_normalize_date_only($fromDate);
    $to = mithra_normalize_date_only($toDate);
    if ($from === '' || $to === '') {
        return [];
    }

    $db = mithra_store_open_db();
    $stmt = $db->prepare('SELECT username, substr(activity_timestamp, 1, 10) AS activity_date, COUNT(*) AS activity_count
        FROM warehouse_entries
        WHERE company = :company
          AND substr(activity_timestamp, 1, 10) >= :from_date
          AND substr(activity_timestamp, 1, 10) <= :to_date
        GROUP BY username, substr(activity_timestamp, 1, 10)
        ORDER BY username ASC, activity_date ASC');
    $stmt->bindValue(':company', $companyKey, SQLITE3_TEXT);
    $stmt->bindValue(':from_date', $from, SQLITE3_TEXT);
    $stmt->bindValue(':to_date', $to, SQLITE3_TEXT);
    $result = $stmt->execute();

    $byUser = [];
    if ($result instanceof SQLite3Result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }

            $username = trim((string) ($row['username'] ?? ''));
            $date = trim((string) ($row['activity_date'] ?? ''));
            if ($username === '' || $date === '') {
                continue;
            }

            $byUser[$username][$date] = (int) ($row['activity_count'] ?? 0);
        }
        $result->finalize();
    }
    $db->close();

    return $byUser;
}

function mithra_wh_store_user_entries(string $company, string $username): array
{
    $companyKey = mithra_store_company_key($company);
    $db = mithra_store_open_db();
    $stmt = $db->prepare('SELECT activity_timestamp, entry_type, whse_document_no, action_label FROM warehouse_entries WHERE company = :company AND username = :username ORDER BY activity_timestamp ASC, entry_no ASC');
    $stmt->bindValue(':company', $companyKey, SQLITE3_TEXT);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $result = $stmt->execute();

    $entries = [];
    if ($result instanceof SQLite3Result) {
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $entries[] = [
                'activity_timestamp' => trim((string) ($row['activity_timestamp'] ?? '')),
                'entry_type' => trim((string) ($row['entry_type'] ?? '')),
                'whse_document_no' => trim((string) ($row['whse_document_no'] ?? '')),
                'action_label' => trim((string) ($row['action_label'] ?? '')),
            ];
        }
        $result->finalize();
    }
    $db->close();

    return $entries;
}

function mithra_store_build_activity_username_groups(array $usernames): array
{
    $groupsByKey = [];
    foreach ($usernames as $username) {
        $name = trim((string) $username);
        if ($name === '') {
            continue;
        }

        $matchKey = mithra_username_match_key($name);
        if ($matchKey === '') {
            continue;
        }

        $canonical = mithra_normalize_username($name);
        if ($canonical === '') {
            $canonical = $name;
        }

        if (!isset($groupsByKey[$matchKey])) {
            $groupsByKey[$matchKey] = [
                'match_key' => $matchKey,
                'username' => $canonical,
                'variants' => [],
            ];
        }

        $groupsByKey[$matchKey]['variants'][$name] = $name;
    }

    $groups = array_values($groupsByKey);
    foreach ($groups as &$group) {
        $group['variants'] = array_values($group['variants']);
    }
    unset($group);

    usort($groups, static function (array $a, array $b): int {
        return strnatcasecmp((string) ($a['username'] ?? ''), (string) ($b['username'] ?? ''));
    });

    return $groups;
}

function mithra_store_activity_username_groups(string $company): array
{
    static $cache = [];
    $companyKey = mithra_store_company_key($company);
    if (isset($cache[$companyKey])) {
        return $cache[$companyKey];
    }

    $cache[$companyKey] = mithra_store_build_activity_username_groups(array_merge(
        mithra_store_list_usernames($company),
        mithra_wh_store_list_usernames($company)
    ));

    return $cache[$companyKey];
}

function mithra_store_collect_group_daily_counts(array $group, array $scanByUser, array $whByUser): array
{
    $counts = [];
    foreach ($group['variants'] ?? [] as $variant) {
        $variantKey = trim((string) $variant);
        if ($variantKey === '') {
            continue;
        }

        if (isset($scanByUser[$variantKey])) {
            $counts = mithra_store_merge_daily_counts($counts, $scanByUser[$variantKey]);
        }

        if (isset($whByUser[$variantKey])) {
            $counts = mithra_store_merge_daily_counts($counts, $whByUser[$variantKey]);
        }
    }

    return $counts;
}

function mithra_store_list_activity_usernames(string $company): array
{
    $usernames = [];
    foreach (mithra_store_activity_username_groups($company) as $group) {
        $username = trim((string) ($group['username'] ?? ''));
        if ($username !== '') {
            $usernames[] = $username;
        }
    }

    return $usernames;
}

function mithra_store_activity_username_variants(string $company, string $username): array
{
    $targetKey = mithra_username_match_key($username);
    if ($targetKey === '') {
        return [];
    }

    foreach (mithra_store_activity_username_groups($company) as $group) {
        if ((string) ($group['match_key'] ?? '') === $targetKey) {
            return is_array($group['variants'] ?? null) ? $group['variants'] : [];
        }
    }

    return [];
}

function mithra_store_merged_scan_daily_counts(string $company, string $username, string $fromDate, string $toDate): array
{
    $merged = [];
    foreach (mithra_store_activity_username_variants($company, $username) as $variant) {
        $merged = mithra_store_merge_daily_counts($merged, mithra_store_daily_counts($company, $variant, $fromDate, $toDate));
    }

    return $merged;
}

function mithra_store_merged_wh_daily_counts(string $company, string $username, string $fromDate, string $toDate): array
{
    $merged = [];
    foreach (mithra_store_activity_username_variants($company, $username) as $variant) {
        $merged = mithra_store_merge_daily_counts($merged, mithra_wh_store_daily_counts($company, $variant, $fromDate, $toDate));
    }

    return $merged;
}

function mithra_store_merge_daily_counts(array ...$countMaps): array
{
    $merged = [];
    foreach ($countMaps as $countMap) {
        foreach ($countMap as $date => $count) {
            $dateKey = trim((string) $date);
            if ($dateKey === '') {
                continue;
            }
            $merged[$dateKey] = (int) ($merged[$dateKey] ?? 0) + (int) $count;
        }
    }

    ksort($merged, SORT_STRING);

    return $merged;
}
