<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/mithra_config.php';

/**
 * Functies
 */
function mithra_discover_companies(): array
{
    try {
        $result = auth_discover_companies_across_active_environments(MITHRA_ODATA_TTL);
        $companies = is_array($result['companies'] ?? null) ? $result['companies'] : [];
    } catch (Throwable $error) {
        $companies = [];
    }

    if ($companies === []) {
        $companies = [
            'Koninklijke van Twist',
            'Hunter van Twist',
            'KVT Gas',
        ];
    }

    return $companies;
}

function mithra_company_entity_url(string $company, array $query, ?string $environment = null, ?string $entity = null): string
{
    global $baseUrl;

    $companyName = trim($company);
    if ($companyName === '') {
        throw new RuntimeException('Geen bedrijf geselecteerd.');
    }

    $targetEnvironment = trim((string) ($environment ?? ''));
    if ($targetEnvironment === '') {
        $targetEnvironment = auth_get_environment_for_company($companyName, MITHRA_ODATA_TTL);
    }

    if ($targetEnvironment === '') {
        throw new RuntimeException('Geen environment beschikbaar.');
    }

    $base = trim((string) ($baseUrl ?? ''));
    if ($base === '') {
        throw new RuntimeException('baseUrl ontbreekt in auth.php.');
    }

    $entityName = trim((string) ($entity ?? MITHRA_BC_ENTITY));
    if ($entityName === '') {
        throw new RuntimeException('BC entity ontbreekt.');
    }

    $safeCompany = str_replace("'", "''", $companyName);
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($base, '/') . '/' . rawurlencode($targetEnvironment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entityName);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function mithra_normalize_date_only(string $value): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }

    $parts = preg_split('/[T\s]/', $text, 2);
    $dateOnly = trim((string) ($parts[0] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOnly)) {
        return '';
    }

    return $dateOnly;
}

function mithra_parse_starting_time(string $timeValue): string
{
    $text = trim($timeValue);
    if ($text === '') {
        return '00:00:00';
    }

    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.(\d+))?$/', $text, $matches)) {
        $normalized = sprintf('%02d:%02d:%02d', (int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 0));
        $fraction = trim((string) ($matches[4] ?? ''));
        if ($fraction !== '') {
            return $normalized . '.' . $fraction;
        }

        return $normalized;
    }

    if (preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', $text, $matches)) {
        $hours = (int) ($matches[1] ?? 0);
        $minutes = (int) ($matches[2] ?? 0);
        $seconds = (int) ($matches[3] ?? 0);
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    if (preg_match('/^\d+$/', $text)) {
        $numeric = (int) $text;
        if ($numeric >= 86400000) {
            $numeric = (int) floor($numeric / 1000);
        }
        $hours = (int) floor($numeric / 3600);
        $minutes = (int) floor(($numeric % 3600) / 60);
        $seconds = (int) ($numeric % 60);
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    return '00:00:00';
}

function mithra_row_scan_timestamp(array $row): string
{
    $date = mithra_normalize_date_only((string) ($row['Starting_Date'] ?? ''));
    if ($date === '') {
        return '';
    }

    $time = mithra_parse_starting_time((string) ($row['Starting_Time'] ?? ''));
    return $date . 'T' . $time;
}

function mithra_odata_quote_string(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

function mithra_scan_timestamp_time_only(string $scanTimestamp): string
{
    $text = trim($scanTimestamp);
    if ($text === '') {
        return '00:00:00';
    }

    $parts = preg_split('/[T\s]/', $text, 2);
    $timePart = trim((string) ($parts[1] ?? ''));

    return mithra_parse_starting_time($timePart);
}

/**
 * Twee OData-filters voor forward sync: latere dagen + rest van de laatste scandag.
 *
 * @return list<string>
 */
function mithra_forward_sync_odata_filters(string $scanTimestamp): array
{
    $date = mithra_normalize_date_only($scanTimestamp);
    if ($date === '') {
        return [];
    }

    $time = mithra_scan_timestamp_time_only($scanTimestamp);
    $timeLiteral = mithra_odata_quote_string($time);

    return [
        'Starting_Date gt ' . $date,
        'Starting_Date eq ' . $date . ' and Starting_Time gt ' . $timeLiteral,
    ];
}

/**
 * @param list<array<string, mixed>> ...$entryLists
 * @return list<array<string, mixed>>
 */
function mithra_merge_scan_entries(array ...$entryLists): array
{
    $byEntryNo = [];
    foreach ($entryLists as $list) {
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entryNo = (int) ($entry['entry_no'] ?? 0);
            if ($entryNo <= 0) {
                continue;
            }

            $byEntryNo[$entryNo] = $entry;
        }
    }

    $merged = array_values($byEntryNo);
    usort($merged, static function (array $a, array $b): int {
        return strcmp((string) ($a['scan_timestamp'] ?? ''), (string) ($b['scan_timestamp'] ?? ''));
    });

    return $merged;
}

/**
 * @param list<array<string, mixed>> $entries
 * @return list<array<string, mixed>>
 */
function mithra_filter_entries_newer_than(array $entries, string $scanTimestamp): array
{
    $timestamp = trim($scanTimestamp);
    if ($timestamp === '') {
        return [];
    }

    $result = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $entryTimestamp = trim((string) ($entry['scan_timestamp'] ?? ''));
        if ($entryTimestamp === '' || strcmp($entryTimestamp, $timestamp) <= 0) {
            continue;
        }

        $result[] = $entry;
    }

    return $result;
}

function mithra_odata_http_code_from_error(Throwable $error): ?int
{
    if (preg_match('/HTTP (\d{3}) from OData/i', $error->getMessage(), $matches) !== 1) {
        return null;
    }

    return (int) $matches[1];
}

function mithra_odata_error_is_retryable(Throwable $error): bool
{
    if (strpos(strtolower($error->getMessage()), 'curl error') !== false) {
        return true;
    }

    $httpCode = mithra_odata_http_code_from_error($error);
    if ($httpCode === 409) {
        return true;
    }

    if ($httpCode !== null && in_array($httpCode, [408, 423, 429, 500, 502, 503, 504], true)) {
        return true;
    }

    if (strpos(strtolower($error->getMessage()), 'please try again later') !== false) {
        return true;
    }

    return false;
}

function mithra_odata_get_all_with_retry(string $url, array $auth, ?int $ttlSeconds = null): array
{
    if (!function_exists('odata_get_all')) {
        throw new RuntimeException('OData helper ontbreekt.');
    }

    $ttl = $ttlSeconds ?? MITHRA_ODATA_TTL;
    $maxAttempts = max(1, MITHRA_ODATA_RETRY_ATTEMPTS);
    $delaySeconds = max(0, MITHRA_ODATA_RETRY_DELAY_SECONDS);
    $lastError = null;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        try {
            $rows = odata_get_all($url, $auth, $ttl);
            return is_array($rows) ? $rows : [];
        } catch (Throwable $error) {
            $lastError = $error;
            if ($attempt >= $maxAttempts || !mithra_odata_error_is_retryable($error)) {
                throw $error;
            }

            if ($delaySeconds > 0) {
                sleep($delaySeconds);
            }
        }
    }

    if ($lastError instanceof Throwable) {
        throw $lastError;
    }

    throw new RuntimeException('OData ophalen mislukt.');
}

function mithra_fetch_entity_with_filter(string $company, string $entity, string $selectFields, string $filter, string $orderBy): array
{
    $query = [
        '$select' => $selectFields,
        '$filter' => $filter,
        '$orderby' => $orderBy,
    ];

    $url = mithra_company_entity_url($company, $query, null, $entity);
    $auth = auth_get_auth_for_company($company, MITHRA_ODATA_TTL);

    return mithra_odata_get_all_with_retry($url, $auth, MITHRA_ODATA_TTL);
}

function mithra_fetch_scanposten_with_filter(string $company, string $filter): array
{
    $rows = mithra_fetch_entity_with_filter(
        $company,
        MITHRA_BC_ENTITY,
        MITHRA_BC_SELECT_FIELDS,
        $filter,
        'Starting_Date asc,Starting_Time asc,Entry_No asc'
    );

    $entries = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $entry = mithra_row_to_entry($row);
        if ($entry !== null) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

function mithra_username_from_user_id(string $userId): string
{
    $text = trim($userId);
    if ($text === '') {
        return '';
    }

    $at = strpos($text, '@');
    if ($at === false) {
        return mithra_normalize_username($text);
    }

    return mithra_normalize_username(trim(substr($text, 0, $at)));
}

function mithra_normalize_username(string $username): string
{
    $name = trim($username);
    if ($name === '') {
        return '';
    }

    if (preg_match('/^kvt\\\\(.+)$/i', $name, $matches) === 1) {
        return trim((string) ($matches[1] ?? ''));
    }

    return $name;
}

function mithra_username_match_key(string $username): string
{
    return strtolower(mithra_normalize_username($username));
}

function mithra_usernames_match(string $left, string $right): bool
{
    $leftKey = mithra_username_match_key($left);
    $rightKey = mithra_username_match_key($right);

    return $leftKey !== '' && $leftKey === $rightKey;
}

function mithra_wh_row_is_activity(array $row): bool
{
    $entryType = trim((string) ($row['Entry_Type'] ?? ''));
    if ($entryType === '') {
        return false;
    }

    if (strcasecmp($entryType, 'Verplaatsing') === 0) {
        return (float) ($row['Quantity'] ?? 0) > 0;
    }

    if (strcasecmp($entryType, 'Positieve correctie') === 0) {
        return true;
    }

    if (strcasecmp($entryType, 'Negatieve Correctie') === 0) {
        return true;
    }

    return false;
}

function mithra_wh_row_activity_timestamp(array $row): string
{
    $date = mithra_normalize_date_only((string) ($row['Registering_Date'] ?? ''));
    if ($date === '') {
        return '';
    }

    return $date . 'T00:00:00';
}

function mithra_wh_row_action_label(array $row): string
{
    $entryType = trim((string) ($row['Entry_Type'] ?? ''));
    $documentNo = trim((string) ($row['Whse_Document_No'] ?? ''));
    if ($documentNo === '') {
        return $entryType;
    }

    return trim($entryType . ' ' . $documentNo);
}

function mithra_wh_row_to_entry(array $row): ?array
{
    if (!mithra_wh_row_is_activity($row)) {
        return null;
    }

    $entryNo = (int) ($row['Entry_No'] ?? 0);
    if ($entryNo <= 0) {
        return null;
    }

    $username = mithra_username_from_user_id((string) ($row['User_ID'] ?? ''));
    if ($username === '') {
        return null;
    }

    $activityTimestamp = mithra_wh_row_activity_timestamp($row);
    if ($activityTimestamp === '') {
        return null;
    }

    $entryType = trim((string) ($row['Entry_Type'] ?? ''));

    return [
        'entry_no' => $entryNo,
        'username' => $username,
        'entry_type' => $entryType,
        'whse_document_no' => trim((string) ($row['Whse_Document_No'] ?? '')),
        'action_label' => mithra_wh_row_action_label($row),
        'activity_timestamp' => $activityTimestamp,
    ];
}

/**
 * @return list<string>
 */
function mithra_wh_forward_sync_odata_filters(string $activityDate, int $entryNoOnDate): array
{
    $date = mithra_normalize_date_only($activityDate);
    if ($date === '') {
        return [];
    }

    return [
        'Registering_Date gt ' . $date,
        'Registering_Date eq ' . $date . ' and Entry_No gt ' . max(0, $entryNoOnDate),
    ];
}

/**
 * @param list<array<string, mixed>> $entries
 * @return list<array<string, mixed>>
 */
function mithra_filter_wh_entries_newer_than(array $entries, string $activityDate, int $entryNoOnDate): array
{
    $date = mithra_normalize_date_only($activityDate);
    if ($date === '') {
        return [];
    }

    $result = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $entryDate = mithra_normalize_date_only((string) ($entry['activity_timestamp'] ?? ''));
        $entryNo = (int) ($entry['entry_no'] ?? 0);
        if ($entryDate === '' || $entryNo <= 0) {
            continue;
        }

        if (strcmp($entryDate, $date) > 0) {
            $result[] = $entry;
            continue;
        }

        if (strcmp($entryDate, $date) === 0 && $entryNo > $entryNoOnDate) {
            $result[] = $entry;
        }
    }

    return $result;
}

function mithra_fetch_magazijnposten_with_filter(string $company, string $filter): array
{
    $rows = mithra_fetch_entity_with_filter(
        $company,
        MITHRA_WH_BC_ENTITY,
        MITHRA_WH_BC_SELECT_FIELDS,
        $filter,
        'Registering_Date asc,Entry_No asc'
    );

    $entries = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $entry = mithra_wh_row_to_entry($row);
        if ($entry !== null) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

function mithra_fetch_magazijnposten_range(string $company, string $fromDate, string $toDate): array
{
    $from = mithra_normalize_date_only($fromDate);
    $to = mithra_normalize_date_only($toDate);
    if ($from === '' || $to === '') {
        throw new RuntimeException('Ongeldige datumbereik voor Magazijnposten.');
    }

    if (strcmp($from, $to) > 0) {
        throw new RuntimeException('Datumbereik is ongeldig: vanaf ligt na tot.');
    }

    $filter = 'Registering_Date ge ' . $from . ' and Registering_Date le ' . $to;

    return mithra_fetch_magazijnposten_with_filter($company, $filter);
}

function mithra_fetch_magazijnposten_newer_than(string $company, string $activityDate, int $entryNoOnDate): array
{
    $date = mithra_normalize_date_only($activityDate);
    if ($date === '') {
        return [];
    }

    $filters = mithra_wh_forward_sync_odata_filters($date, $entryNoOnDate);
    if ($filters === []) {
        return [];
    }

    $fetched = [];
    foreach ($filters as $filter) {
        $fetched[] = mithra_fetch_magazijnposten_with_filter($company, $filter);
    }

    return mithra_filter_wh_entries_newer_than(mithra_merge_scan_entries(...$fetched), $date, $entryNoOnDate);
}

function mithra_row_to_entry(array $row): ?array
{
    $entryNo = (int) ($row['Entry_No'] ?? 0);
    if ($entryNo <= 0) {
        return null;
    }

    $username = mithra_normalize_username(trim((string) ($row['KVT_User_Name_Scanner'] ?? '')));
    if ($username === '') {
        return null;
    }

    $scanTimestamp = mithra_row_scan_timestamp($row);
    if ($scanTimestamp === '') {
        return null;
    }

    return [
        'entry_no' => $entryNo,
        'username' => $username,
        'scan_process' => trim((string) ($row['Scan_Process'] ?? '')),
        'scan_timestamp' => $scanTimestamp,
    ];
}

function mithra_fetch_scanposten_range(string $company, string $fromDate, string $toDate): array
{
    $from = mithra_normalize_date_only($fromDate);
    $to = mithra_normalize_date_only($toDate);
    if ($from === '' || $to === '') {
        throw new RuntimeException('Ongeldige datumbereik voor Scanposten.');
    }

    if (strcmp($from, $to) > 0) {
        throw new RuntimeException('Datumbereik is ongeldig: vanaf ligt na tot.');
    }

    $filter = "Starting_Date ge " . $from . " and Starting_Date le " . $to;

    return mithra_fetch_scanposten_with_filter($company, $filter);
}

function mithra_fetch_scanposten_newer_than(string $company, string $scanTimestamp): array
{
    $timestamp = trim($scanTimestamp);
    if ($timestamp === '') {
        return [];
    }

    $filters = mithra_forward_sync_odata_filters($timestamp);
    if ($filters === []) {
        return [];
    }

    $fetched = [];
    foreach ($filters as $filter) {
        $fetched[] = mithra_fetch_scanposten_with_filter($company, $filter);
    }

    return mithra_filter_entries_newer_than(mithra_merge_scan_entries(...$fetched), $timestamp);
}
