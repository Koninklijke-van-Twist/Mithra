<?php

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
