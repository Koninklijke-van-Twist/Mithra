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

function mithra_company_entity_url(string $company, array $query, ?string $environment = null): string
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

    $safeCompany = str_replace("'", "''", $companyName);
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($base, '/') . '/' . rawurlencode($targetEnvironment) . '/ODataV4/' . $companySegment . '/' . rawurlencode(MITHRA_BC_ENTITY);

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

    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $text, $matches)) {
        return sprintf('%02d:%02d:%02d', (int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 0));
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

function mithra_row_to_entry(array $row): ?array
{
    $entryNo = (int) ($row['Entry_No'] ?? 0);
    if ($entryNo <= 0) {
        return null;
    }

    $username = trim((string) ($row['KVT_User_Name_Scanner'] ?? ''));
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
    $query = [
        '$select' => MITHRA_BC_SELECT_FIELDS,
        '$filter' => $filter,
        '$orderby' => 'Starting_Date asc,Starting_Time asc,Entry_No asc',
    ];

    $url = mithra_company_entity_url($company, $query);
    $auth = auth_get_auth_for_company($company, MITHRA_ODATA_TTL);
    $rows = odata_get_all($url, $auth, MITHRA_ODATA_TTL);

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

function mithra_fetch_scanposten_newer_than(string $company, string $scanTimestamp): array
{
    $timestamp = trim($scanTimestamp);
    if ($timestamp === '') {
        return [];
    }

    $date = mithra_normalize_date_only($timestamp);
    if ($date === '') {
        return [];
    }

    $filter = "Starting_Date ge " . $date;
    $query = [
        '$select' => MITHRA_BC_SELECT_FIELDS,
        '$filter' => $filter,
        '$orderby' => 'Starting_Date asc,Starting_Time asc,Entry_No asc',
    ];

    $url = mithra_company_entity_url($company, $query);
    $auth = auth_get_auth_for_company($company, MITHRA_ODATA_TTL);
    $rows = odata_get_all($url, $auth, MITHRA_ODATA_TTL);

    $entries = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $entry = mithra_row_to_entry($row);
        if ($entry === null) {
            continue;
        }

        if (strcmp($entry['scan_timestamp'], $timestamp) <= 0) {
            continue;
        }

        $entries[] = $entry;
    }

    return $entries;
}
