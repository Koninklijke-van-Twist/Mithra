<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/mithra_bc.php';

/**
 * Functies
 */
function mithra_ensure_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
}

function mithra_current_user_key(): string
{
    mithra_ensure_session();
    $email = strtolower(trim((string) ($_SESSION['user']['email'] ?? '')));
    return $email !== '' ? $email : '_anonymous';
}

function mithra_get_saved_company(array $companies): string
{
    mithra_ensure_session();

    $requested = trim((string) ($_GET['company'] ?? ''));
    if ($requested !== '' && in_array($requested, $companies, true)) {
        return $requested;
    }

    $userKey = mithra_current_user_key();
    $savedMap = $_SESSION['mithra_company'] ?? null;
    if (is_array($savedMap)) {
        $saved = trim((string) ($savedMap[$userKey] ?? ''));
        if ($saved !== '' && in_array($saved, $companies, true)) {
            return $saved;
        }
    }

    return (string) ($companies[0] ?? '');
}

function mithra_preferences_company_key(string $company): string
{
    return strtolower(trim($company));
}

function mithra_normalize_hidden_usernames(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $normalized = [];
    foreach ($value as $username) {
        $name = mithra_normalize_username(trim((string) $username));
        if ($name === '') {
            continue;
        }
        $normalized[mithra_username_match_key($name)] = $name;
    }

    $usernames = array_values($normalized);
    sort($usernames, SORT_NATURAL | SORT_FLAG_CASE);

    return $usernames;
}

function mithra_get_hidden_usernames(string $company): array
{
    mithra_ensure_session();

    $companyKey = mithra_preferences_company_key($company);
    if ($companyKey === '') {
        return [];
    }

    $userKey = mithra_current_user_key();
    $hiddenMap = $_SESSION['mithra_hidden_users'] ?? null;
    if (!is_array($hiddenMap) || !is_array($hiddenMap[$userKey] ?? null)) {
        return [];
    }

    return mithra_normalize_hidden_usernames($hiddenMap[$userKey][$companyKey] ?? []);
}

function mithra_set_user_hidden(string $company, string $username, bool $hidden): array
{
    mithra_ensure_session();

    $companyKey = mithra_preferences_company_key($company);
    $username = mithra_normalize_username(trim($username));
    if ($companyKey === '' || $username === '') {
        throw new RuntimeException('Bedrijf of gebruiker ontbreekt.');
    }

    $userKey = mithra_current_user_key();
    if (!is_array($_SESSION['mithra_hidden_users'] ?? null)) {
        $_SESSION['mithra_hidden_users'] = [];
    }
    if (!is_array($_SESSION['mithra_hidden_users'][$userKey] ?? null)) {
        $_SESSION['mithra_hidden_users'][$userKey] = [];
    }

    $current = mithra_normalize_hidden_usernames($_SESSION['mithra_hidden_users'][$userKey][$companyKey] ?? []);
    $filtered = [];
    foreach ($current as $existing) {
        if (!mithra_usernames_match($existing, $username)) {
            $filtered[] = $existing;
        }
    }

    if ($hidden) {
        $filtered[] = $username;
    }

    $_SESSION['mithra_hidden_users'][$userKey][$companyKey] = mithra_normalize_hidden_usernames($filtered);

    return $_SESSION['mithra_hidden_users'][$userKey][$companyKey];
}

function mithra_set_hidden_usernames(string $company, array $usernames): array
{
    mithra_ensure_session();

    $companyKey = mithra_preferences_company_key($company);
    if ($companyKey === '') {
        throw new RuntimeException('Bedrijf ontbreekt.');
    }

    $userKey = mithra_current_user_key();
    if (!is_array($_SESSION['mithra_hidden_users'] ?? null)) {
        $_SESSION['mithra_hidden_users'] = [];
    }
    if (!is_array($_SESSION['mithra_hidden_users'][$userKey] ?? null)) {
        $_SESSION['mithra_hidden_users'][$userKey] = [];
    }

    $_SESSION['mithra_hidden_users'][$userKey][$companyKey] = mithra_normalize_hidden_usernames($usernames);

    return $_SESSION['mithra_hidden_users'][$userKey][$companyKey];
}

function mithra_save_company_preference(string $company): void
{
    mithra_ensure_session();

    $companyName = trim($company);
    if ($companyName === '') {
        throw new RuntimeException('Geen bedrijf opgegeven.');
    }

    if (!is_array($_SESSION['mithra_company'] ?? null)) {
        $_SESSION['mithra_company'] = [];
    }

    $_SESSION['mithra_company'][mithra_current_user_key()] = $companyName;
}
