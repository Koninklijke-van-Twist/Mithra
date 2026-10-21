<?php

/**
 * analytics.php schrijft web/analytics/.htaccess als die ontbreekt, zonder
 * het endpoint zelf te sluiten. De FTP-mirror slaat .htaccess en de sqlite al over.
 */

$root = dirname(__DIR__);
$htaccess = $root . '/web/analytics/.htaccess';
$sqlite = $root . '/web/analytics/analytics.sqlite';
$workflow = $root . '/.github/workflows/deploy-ftp.yml';

function analytics_protect_run(): string
{
    $script = dirname(__DIR__) . '/web/analytics/analytics.php';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script);
    $output = shell_exec($command . ' 2>&1');

    return is_string($output) ? $output : '';
}

mithra_test('analytics.php schrijft sqlite-weigering en laat het endpoint open', static function () use ($htaccess, $sqlite): void {
    $existed = is_file($htaccess);
    $previous = $existed ? (string) file_get_contents($htaccess) : null;
    $sqliteExisted = is_file($sqlite);
    $sqlitePrevious = $sqliteExisted ? (string) file_get_contents($sqlite) : null;

    try {
        if ($existed) {
            unlink($htaccess);
        }
        if ($sqliteExisted) {
            unlink($sqlite);
        }

        $body = analytics_protect_run();
        $rules = is_file($htaccess) ? (string) file_get_contents($htaccess) : '';
        mithra_assert_true($rules !== '', 'ontbrekende .htaccess wordt aangemaakt');
        mithra_assert_true(str_contains($body, '"ok":false'), 'endpoint antwoordt nog JSON');
        mithra_assert_true(str_contains($rules, '<FilesMatch "\.sqlite">'), 'FilesMatch op .sqlite');
        mithra_assert_true(str_contains($rules, 'Require all denied'), 'Require all denied');
        mithra_assert_true(str_contains($rules, 'Deny from all'), 'Deny from all voor oudere Apache');
        mithra_assert_true(str_contains($rules, 'analytics.php blijft het endpoint'), 'endpoint blijft uitgelegd');
        mithra_assert_false(is_file($sqlite), 'ongeautoriseerd verzoek maakt geen sqlite');

        $again = (string) file_get_contents($htaccess);
        analytics_protect_run();
        mithra_assert_true(is_file($htaccess) && file_get_contents($htaccess) === $again, 'bestaande sqlite-regels blijven staan');

        $custom = "Options -Indexes\n# bestaand\n";
        file_put_contents($htaccess, $custom);
        analytics_protect_run();
        $appended = (string) file_get_contents($htaccess);
        mithra_assert_true(str_starts_with($appended, rtrim($custom)), 'bestaande regels zonder sqlite blijven staan');
        mithra_assert_true(str_contains($appended, '<FilesMatch "\.sqlite">'), 'sqlite-weigering wordt toegevoegd');

        $protected = $appended;
        analytics_protect_run();
        mithra_assert_same($protected, (string) file_get_contents($htaccess), 'na toevoegen niet nog een keer schrijven');
    } finally {
        if (is_file($htaccess)) {
            unlink($htaccess);
        }
        if ($previous !== null) {
            file_put_contents($htaccess, $previous);
        }
        if (is_file($sqlite)) {
            unlink($sqlite);
        }
        if ($sqlitePrevious !== null) {
            file_put_contents($sqlite, $sqlitePrevious);
        }
    }
});

mithra_test('deploy-mirror slaat analytics-sqlite over en wist .htaccess niet', static function () use ($workflow): void {
    $workflowText = (string) file_get_contents($workflow);
    mithra_assert_true(str_contains($workflowText, "--exclude-glob analytics/*.sqlite \\"), 'exclude analytics/*.sqlite');
    mithra_assert_true(str_contains($workflowText, "--exclude-glob analytics/*.sqlite-* \\"), 'exclude analytics/*.sqlite-*');

    $mirror = '';
    if (preg_match('/mirror -R --delete \\\\.*?\\.\\/web \\./s', $workflowText, $match) === 1) {
        $mirror = $match[0];
    }
    mithra_assert_true($mirror !== '', 'mirror-commando gevonden');
    mithra_assert_same(2, substr_count($mirror, 'analytics/*.sqlite'), 'alleen de twee sqlite-excludes in mirror');
    mithra_assert_true(str_contains($mirror, "--exclude-glob .htaccess \\"), 'mirror wist .htaccess niet');
});
