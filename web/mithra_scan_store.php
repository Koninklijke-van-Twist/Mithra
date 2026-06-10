<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/mithra_config.php';
require_once __DIR__ . '/mithra_bc.php';

/**
 * Functies
 */
function mithra_store_cache_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    return $dir;
}

function mithra_store_db_path(): string
{
    return mithra_store_cache_dir() . DIRECTORY_SEPARATOR . MITHRA_SCAN_DB_FILENAME;
}

function mithra_store_company_key(string $company): string
{
    return strtolower(trim($company));
}

function mithra_store_open_db(): SQLite3
{
    if (!class_exists('SQLite3')) {
        throw new RuntimeException('SQLite3 is niet beschikbaar in deze PHP-runtime.');
    }

    $db = new SQLite3(mithra_store_db_path());
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec('PRAGMA synchronous=NORMAL;');
    $db->exec('CREATE TABLE IF NOT EXISTS scan_entries (
        company TEXT NOT NULL,
        entry_no INTEGER NOT NULL,
        username TEXT NOT NULL,
        scan_process TEXT NOT NULL DEFAULT "",
        scan_timestamp TEXT NOT NULL,
        PRIMARY KEY (company, entry_no)
    )');
    $db->exec('CREATE TABLE IF NOT EXISTS sync_state (
        company TEXT NOT NULL PRIMARY KEY,
        newest_scan_timestamp TEXT NOT NULL DEFAULT "",
        oldest_scan_timestamp TEXT NOT NULL DEFAULT "",
        backfill_to_date TEXT NOT NULL DEFAULT "",
        backfill_complete INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT ""
    )');
    mithra_store_maybe_add_column($db, 'sync_state', 'backfill_to_date', 'TEXT NOT NULL DEFAULT ""');
    mithra_store_maybe_add_column($db, 'sync_state', 'chunks_completed', 'INTEGER NOT NULL DEFAULT 0');
    mithra_store_maybe_add_column($db, 'sync_state', 'empty_backfill_months', 'TEXT NOT NULL DEFAULT ""');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_scan_entries_user_ts ON scan_entries(company, username, scan_timestamp)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_scan_entries_ts ON scan_entries(company, scan_timestamp)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_scan_entries_process ON scan_entries(company, username, scan_process)');
    $db->exec('CREATE TABLE IF NOT EXISTS warehouse_entries (
        company TEXT NOT NULL,
        entry_no INTEGER NOT NULL,
        username TEXT NOT NULL,
        entry_type TEXT NOT NULL DEFAULT "",
        whse_document_no TEXT NOT NULL DEFAULT "",
        action_label TEXT NOT NULL DEFAULT "",
        activity_timestamp TEXT NOT NULL,
        PRIMARY KEY (company, entry_no)
    )');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_wh_entries_user_ts ON warehouse_entries(company, username, activity_timestamp)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_wh_entries_ts ON warehouse_entries(company, activity_timestamp)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_wh_entries_type ON warehouse_entries(company, username, entry_type)');
    mithra_store_maybe_add_column($db, 'sync_state', 'wh_newest_activity_date', 'TEXT NOT NULL DEFAULT ""');
    mithra_store_maybe_add_column($db, 'sync_state', 'wh_newest_entry_no', 'INTEGER NOT NULL DEFAULT 0');
    mithra_store_maybe_add_column($db, 'sync_state', 'wh_oldest_activity_date', 'TEXT NOT NULL DEFAULT ""');
    mithra_store_maybe_add_column($db, 'sync_state', 'wh_backfill_to_date', 'TEXT NOT NULL DEFAULT ""');
    mithra_store_maybe_add_column($db, 'sync_state', 'wh_backfill_complete', 'INTEGER NOT NULL DEFAULT 0');
    mithra_store_maybe_add_column($db, 'sync_state', 'wh_chunks_completed', 'INTEGER NOT NULL DEFAULT 0');
    mithra_store_maybe_add_column($db, 'sync_state', 'wh_empty_backfill_months', 'TEXT NOT NULL DEFAULT ""');

    return $db;
}

function mithra_store_maybe_add_column(SQLite3 $db, string $table, string $column, string $definition): void
{
    $result = $db->query('PRAGMA table_info(' . $table . ')');
    if (!$result instanceof SQLite3Result) {
        return;
    }

    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        if (strcasecmp((string) ($row['name'] ?? ''), $column) === 0) {
            $result->finalize();
            return;
        }
    }
    $result->finalize();

    $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
}

function mithra_store_get_sync_state(string $company): array
{
    $companyKey = mithra_store_company_key($company);
    $db = mithra_store_open_db();
    $stmt = $db->prepare('SELECT newest_scan_timestamp, oldest_scan_timestamp, backfill_to_date, backfill_complete, chunks_completed, empty_backfill_months, wh_newest_activity_date, wh_newest_entry_no, wh_oldest_activity_date, wh_backfill_to_date, wh_backfill_complete, wh_chunks_completed, wh_empty_backfill_months, updated_at FROM sync_state WHERE company = :company LIMIT 1');
    $stmt->bindValue(':company', $companyKey, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row = null;
    if ($result instanceof SQLite3Result) {
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
    }
    $db->close();

    if (!is_array($row)) {
        return [
            'newest_scan_timestamp' => '',
            'oldest_scan_timestamp' => '',
            'backfill_to_date' => '',
            'backfill_complete' => 0,
            'chunks_completed' => 0,
            'empty_backfill_months' => '',
            'wh_newest_activity_date' => '',
            'wh_newest_entry_no' => 0,
            'wh_oldest_activity_date' => '',
            'wh_backfill_to_date' => '',
            'wh_backfill_complete' => 0,
            'wh_chunks_completed' => 0,
            'wh_empty_backfill_months' => '',
            'updated_at' => '',
        ];
    }

    return [
        'newest_scan_timestamp' => trim((string) ($row['newest_scan_timestamp'] ?? '')),
        'oldest_scan_timestamp' => trim((string) ($row['oldest_scan_timestamp'] ?? '')),
        'backfill_to_date' => trim((string) ($row['backfill_to_date'] ?? '')),
        'backfill_complete' => (int) ($row['backfill_complete'] ?? 0),
        'chunks_completed' => (int) ($row['chunks_completed'] ?? 0),
        'empty_backfill_months' => trim((string) ($row['empty_backfill_months'] ?? '')),
        'wh_newest_activity_date' => trim((string) ($row['wh_newest_activity_date'] ?? '')),
        'wh_newest_entry_no' => (int) ($row['wh_newest_entry_no'] ?? 0),
        'wh_oldest_activity_date' => trim((string) ($row['wh_oldest_activity_date'] ?? '')),
        'wh_backfill_to_date' => trim((string) ($row['wh_backfill_to_date'] ?? '')),
        'wh_backfill_complete' => (int) ($row['wh_backfill_complete'] ?? 0),
        'wh_chunks_completed' => (int) ($row['wh_chunks_completed'] ?? 0),
        'wh_empty_backfill_months' => trim((string) ($row['wh_empty_backfill_months'] ?? '')),
        'updated_at' => trim((string) ($row['updated_at'] ?? '')),
    ];
}

function mithra_store_save_sync_state(string $company, array $state): void
{
    $companyKey = mithra_store_company_key($company);
    $db = mithra_store_open_db();
    $stmt = $db->prepare('INSERT INTO sync_state (company, newest_scan_timestamp, oldest_scan_timestamp, backfill_to_date, backfill_complete, chunks_completed, empty_backfill_months, wh_newest_activity_date, wh_newest_entry_no, wh_oldest_activity_date, wh_backfill_to_date, wh_backfill_complete, wh_chunks_completed, wh_empty_backfill_months, updated_at)
        VALUES (:company, :newest, :oldest, :backfill_to_date, :backfill_complete, :chunks_completed, :empty_backfill_months, :wh_newest_activity_date, :wh_newest_entry_no, :wh_oldest_activity_date, :wh_backfill_to_date, :wh_backfill_complete, :wh_chunks_completed, :wh_empty_backfill_months, :updated_at)
        ON CONFLICT(company) DO UPDATE SET
            newest_scan_timestamp = excluded.newest_scan_timestamp,
            oldest_scan_timestamp = excluded.oldest_scan_timestamp,
            backfill_to_date = excluded.backfill_to_date,
            backfill_complete = excluded.backfill_complete,
            chunks_completed = excluded.chunks_completed,
            empty_backfill_months = excluded.empty_backfill_months,
            wh_newest_activity_date = excluded.wh_newest_activity_date,
            wh_newest_entry_no = excluded.wh_newest_entry_no,
            wh_oldest_activity_date = excluded.wh_oldest_activity_date,
            wh_backfill_to_date = excluded.wh_backfill_to_date,
            wh_backfill_complete = excluded.wh_backfill_complete,
            wh_chunks_completed = excluded.wh_chunks_completed,
            wh_empty_backfill_months = excluded.wh_empty_backfill_months,
            updated_at = excluded.updated_at');
    $stmt->bindValue(':company', $companyKey, SQLITE3_TEXT);
    $stmt->bindValue(':newest', trim((string) ($state['newest_scan_timestamp'] ?? '')), SQLITE3_TEXT);
    $stmt->bindValue(':oldest', trim((string) ($state['oldest_scan_timestamp'] ?? '')), SQLITE3_TEXT);
    $stmt->bindValue(':backfill_to_date', trim((string) ($state['backfill_to_date'] ?? '')), SQLITE3_TEXT);
    $stmt->bindValue(':backfill_complete', (int) ($state['backfill_complete'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':chunks_completed', (int) ($state['chunks_completed'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':empty_backfill_months', trim((string) ($state['empty_backfill_months'] ?? '')), SQLITE3_TEXT);
    $stmt->bindValue(':wh_newest_activity_date', trim((string) ($state['wh_newest_activity_date'] ?? '')), SQLITE3_TEXT);
    $stmt->bindValue(':wh_newest_entry_no', (int) ($state['wh_newest_entry_no'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':wh_oldest_activity_date', trim((string) ($state['wh_oldest_activity_date'] ?? '')), SQLITE3_TEXT);
    $stmt->bindValue(':wh_backfill_to_date', trim((string) ($state['wh_backfill_to_date'] ?? '')), SQLITE3_TEXT);
    $stmt->bindValue(':wh_backfill_complete', (int) ($state['wh_backfill_complete'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':wh_chunks_completed', (int) ($state['wh_chunks_completed'] ?? 0), SQLITE3_INTEGER);
    $stmt->bindValue(':wh_empty_backfill_months', trim((string) ($state['wh_empty_backfill_months'] ?? '')), SQLITE3_TEXT);
    $stmt->bindValue(':updated_at', gmdate('c'), SQLITE3_TEXT);
    $stmt->execute();
    $db->close();
}

function mithra_store_insert_entries(string $company, array $entries): int
{
    if ($entries === []) {
        return 0;
    }

    $companyKey = mithra_store_company_key($company);
    $db = mithra_store_open_db();
    $db->exec('BEGIN IMMEDIATE');
    $stmt = $db->prepare('INSERT OR IGNORE INTO scan_entries (company, entry_no, username, scan_process, scan_timestamp)
        VALUES (:company, :entry_no, :username, :scan_process, :scan_timestamp)');

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
        $stmt->bindValue(':scan_process', trim((string) ($entry['scan_process'] ?? '')), SQLITE3_TEXT);
        $stmt->bindValue(':scan_timestamp', trim((string) ($entry['scan_timestamp'] ?? '')), SQLITE3_TEXT);
        $stmt->execute();

        if ($db->changes() > 0) {
            $inserted++;
        }
    }

    $db->exec('COMMIT');
    $db->close();

    return $inserted;
}

function mithra_store_recompute_bounds(string $company): array
{
    $companyKey = mithra_store_company_key($company);
    $db = mithra_store_open_db();
    $result = $db->query("SELECT MIN(scan_timestamp) AS oldest_ts, MAX(scan_timestamp) AS newest_ts FROM scan_entries WHERE company = '" . SQLite3::escapeString($companyKey) . "'");
    $row = $result instanceof SQLite3Result ? $result->fetchArray(SQLITE3_ASSOC) : null;
    if ($result instanceof SQLite3Result) {
        $result->finalize();
    }
    $db->close();

    return [
        'oldest_scan_timestamp' => trim((string) ($row['oldest_ts'] ?? '')),
        'newest_scan_timestamp' => trim((string) ($row['newest_ts'] ?? '')),
    ];
}

function mithra_store_list_usernames(string $company): array
{
    $companyKey = mithra_store_company_key($company);
    $db = mithra_store_open_db();
    $result = $db->query("SELECT DISTINCT username FROM scan_entries WHERE company = '" . SQLite3::escapeString($companyKey) . "' AND TRIM(username) <> '' ORDER BY username COLLATE NOCASE ASC");
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

function mithra_store_daily_counts(string $company, string $username, string $fromDate, string $toDate): array
{
    $companyKey = mithra_store_company_key($company);
    $from = mithra_normalize_date_only($fromDate);
    $to = mithra_normalize_date_only($toDate);
    if ($from === '' || $to === '') {
        return [];
    }

    $db = mithra_store_open_db();
    $stmt = $db->prepare('SELECT substr(scan_timestamp, 1, 10) AS scan_date, COUNT(*) AS scan_count
        FROM scan_entries
        WHERE company = :company
          AND username = :username
          AND substr(scan_timestamp, 1, 10) >= :from_date
          AND substr(scan_timestamp, 1, 10) <= :to_date
        GROUP BY substr(scan_timestamp, 1, 10)
        ORDER BY scan_date ASC');
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
            $date = trim((string) ($row['scan_date'] ?? ''));
            if ($date === '') {
                continue;
            }
            $counts[$date] = (int) ($row['scan_count'] ?? 0);
        }
        $result->finalize();
    }
    $db->close();

    return $counts;
}

function mithra_store_company_scan_daily_counts(string $company, string $fromDate, string $toDate): array
{
    $companyKey = mithra_store_company_key($company);
    $from = mithra_normalize_date_only($fromDate);
    $to = mithra_normalize_date_only($toDate);
    if ($from === '' || $to === '') {
        return [];
    }

    $db = mithra_store_open_db();
    $stmt = $db->prepare('SELECT username, substr(scan_timestamp, 1, 10) AS scan_date, COUNT(*) AS scan_count
        FROM scan_entries
        WHERE company = :company
          AND substr(scan_timestamp, 1, 10) >= :from_date
          AND substr(scan_timestamp, 1, 10) <= :to_date
        GROUP BY username, substr(scan_timestamp, 1, 10)
        ORDER BY username ASC, scan_date ASC');
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
            $date = trim((string) ($row['scan_date'] ?? ''));
            if ($username === '' || $date === '') {
                continue;
            }

            $byUser[$username][$date] = (int) ($row['scan_count'] ?? 0);
        }
        $result->finalize();
    }
    $db->close();

    return $byUser;
}

function mithra_store_user_entries(string $company, string $username): array
{
    $companyKey = mithra_store_company_key($company);
    $db = mithra_store_open_db();
    $stmt = $db->prepare('SELECT scan_timestamp, scan_process FROM scan_entries WHERE company = :company AND username = :username ORDER BY scan_timestamp ASC');
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
                'scan_timestamp' => trim((string) ($row['scan_timestamp'] ?? '')),
                'scan_process' => trim((string) ($row['scan_process'] ?? '')),
            ];
        }
        $result->finalize();
    }
    $db->close();

    return $entries;
}
