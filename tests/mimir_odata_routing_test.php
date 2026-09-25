<?php

/**
 * OData-routing: Mímir als $mimirApi gezet is, BC als de key ontbreekt.
 */

$webDir = dirname(__DIR__) . '/web';
require_once $webDir . '/odata.php';
require_once $webDir . '/auth_helper.php';
require_once $webDir . '/mithra_config.php';
require_once $webDir . '/mithra_bc.php';
require_once $webDir . '/ratatoskr_orders.php';

$GLOBALS['mimir_test_auth_path'] = $webDir . '/auth.php';
$GLOBALS['mimir_test_auth_backup'] = is_file($GLOBALS['mimir_test_auth_path'])
    ? file_get_contents($GLOBALS['mimir_test_auth_path'])
    : null;
$GLOBALS['mimir_test_mock_port'] = 18947;
$GLOBALS['mimir_test_mock_log'] = sys_get_temp_dir() . '/mithra-mimir-mock.log';
$GLOBALS['mimir_test_mock_script'] = sys_get_temp_dir() . '/mithra-mimir-mock.php';
$GLOBALS['mimir_test_server'] = null;

function mimir_test_reset_discovery_cache(): void
{
    unset(
        $GLOBALS['demeter_company_environment_map'],
        $GLOBALS['demeter_companies_by_environment'],
        $GLOBALS['demeter_active_environments']
    );
}

function mimir_test_cache_files(): array
{
    $files = glob(dirname(__DIR__) . '/web/cache/odata/*.json') ?: [];
    sort($files, SORT_STRING);
    return $files;
}

function mimir_test_write_mock(): void
{
    $log = var_export($GLOBALS['mimir_test_mock_log'], true);
    $php = <<<'PHP'
<?php
$log = LOG_PATH;
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if ($authorization === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    if (is_array($headers)) {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $authorization = (string) $value;
                break;
            }
        }
    }
}
file_put_contents($log, json_encode([
    'uri' => $uri,
    'method' => $method,
    'ua' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    'authorization' => $authorization,
    'api_key' => (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''),
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
header('Content-Type: application/json');

if (str_contains($uri, '/mimir-dup/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Overlap BV', 'environment' => 'Production'],
        ['name' => 'Overlap BV', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/companies.php')) {
    echo json_encode(['value' => [
        ['name' => 'Koninklijke van Twist', 'environment' => 'Production'],
        ['name' => "Van Twist's", 'environment' => 'Production'],
        ['name' => 'Hunter van Twist', 'environment' => 'Sandbox'],
    ]]);
    exit;
}

if (str_contains($uri, '/mimir/api/query.php')) {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    echo json_encode(['value' => [[
        'No' => 'PRJ1',
        'company' => (string) ($body['company'] ?? ''),
        'table' => (string) ($body['table'] ?? ''),
        'select' => $body['select'] ?? [],
        'filter' => (string) ($body['filter'] ?? ''),
        'max_age' => $body['max_age'] ?? null,
    ]]]);
    exit;
}

$user = '';
if (str_starts_with($authorization, 'Basic ')) {
    $decoded = base64_decode(substr($authorization, 6), true);
    if (is_string($decoded) && str_contains($decoded, ':')) {
        $user = explode(':', $decoded, 2)[0];
    }
}
echo json_encode(['value' => [[
    'Name' => 'BC Company',
    'via' => 'bc',
    'user' => $user,
]]]);
PHP;
    file_put_contents($GLOBALS['mimir_test_mock_script'], str_replace('LOG_PATH', $log, $php));
}

function mimir_test_mock_requests(): array
{
    $log = $GLOBALS['mimir_test_mock_log'];
    if (!is_file($log)) {
        return [];
    }
    $rows = [];
    foreach (file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }
    return $rows;
}

function mimir_test_restore_auth_file(): void
{
    $path = (string) ($GLOBALS['mimir_test_auth_path'] ?? '');
    if ($path === '') {
        return;
    }
    $backup = $GLOBALS['mimir_test_auth_backup'] ?? null;
    if (!is_string($backup)) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }
    file_put_contents($path, $backup);
}

function mimir_test_stop_server(): void
{
    $server = $GLOBALS['mimir_test_server'] ?? null;
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $GLOBALS['mimir_test_server'] = null;
    @unlink((string) ($GLOBALS['mimir_test_mock_script'] ?? ''));
    @unlink((string) ($GLOBALS['mimir_test_mock_log'] ?? ''));
}

register_shutdown_function(static function (): void {
    mimir_test_stop_server();
    mimir_test_restore_auth_file();
});

mithra_test('mimir uit zonder key', static function (): void {
    $GLOBALS['mimirApi'] = '';
    mithra_assert_false(odata_mimir_enabled());
    mithra_assert_same('https://sleutels.kvt.nl/mimir/api', odata_mimir_base_url());
});

mithra_test('entity-URL met spatie in bedrijfsnaam', static function (): void {
    $GLOBALS['mimirApi'] = '';
    $GLOBALS['baseUrl'] = 'https://bc.example';
    $url = mithra_company_entity_url('Koninklijke van Twist', [
        '$select' => 'Entry_No,Scan_Process',
        '$filter' => "Starting_Date ge 2026-01-01",
    ], 'Production', 'Scanposten');
    $parsed = odata_mimir_parse_entity_url($url);
    mithra_assert_true(is_array($parsed));
    mithra_assert_same('Koninklijke van Twist', $parsed['company'] ?? null);
    mithra_assert_same('Scanposten', $parsed['entity'] ?? null);
    mithra_assert_same('Entry_No,Scan_Process', $parsed['query']['$select'] ?? null);
    mithra_assert_same('Starting_Date ge 2026-01-01', $parsed['query']['$filter'] ?? null);
    mithra_assert_same(null, odata_mimir_parse_companies_url($url));
});

mithra_test('lege baseUrl en apostrof in bedrijfsnaam', static function (): void {
    $GLOBALS['mimirApi'] = 'mimir_test_key';
    $GLOBALS['baseUrl'] = '';
    $url = ratatoskr_company_entity_url_with_query("Van Twist's", 'PurchaseOrders', [
        '$select' => 'No,Buy_from_Vendor_Name',
    ], 'Production');
    $parsed = odata_mimir_parse_entity_url($url);
    mithra_assert_true(is_array($parsed), json_encode($parsed, JSON_UNESCAPED_UNICODE));
    mithra_assert_same("Van Twist's", $parsed['company'] ?? null);
    mithra_assert_same('PurchaseOrders', $parsed['entity'] ?? null);
    mithra_assert_same('No,Buy_from_Vendor_Name', $parsed['query']['$select'] ?? null);
    $GLOBALS['mimirApi'] = '';
});

mithra_test('companies-URL levert environment', static function (): void {
    $url = 'https://bc.example/Production/ODataV4/Companies?$select=Name';
    $parsed = odata_mimir_parse_companies_url($url);
    mithra_assert_true(is_array($parsed) && ($parsed['environment'] ?? '') === 'Production');
    mithra_assert_same(null, odata_mimir_parse_entity_url($url));
});

mithra_test('BC-auth ontbreekt blijft exception zonder Mímir', static function (): void {
    $GLOBALS['mimirApi'] = '';
    unset($GLOBALS['auth_list']);
    $threw = false;
    try {
        auth_get_auth_for_environment('Production');
    } catch (RuntimeException $error) {
        $threw = str_contains($error->getMessage(), 'Geen auth-configuratie');
    }
    mithra_assert_true($threw);
});

mithra_test('company-discovery faalt snel zonder BC-config en zonder Mímir', static function (): void {
    $GLOBALS['mimirApi'] = '';
    unset($GLOBALS['auth_list'], $GLOBALS['environment'], $GLOBALS['auth'], $GLOBALS['baseUrl']);
    mimir_test_reset_discovery_cache();
    $threw = false;
    try {
        auth_discover_companies_across_active_environments(30);
    } catch (RuntimeException $error) {
        $threw = str_contains($error->getMessage(), 'Geen actieve environments');
    }
    mithra_assert_true($threw);
});

mithra_test('URL-builder eist baseUrl zonder Mímir', static function (): void {
    $GLOBALS['mimirApi'] = '';
    $GLOBALS['baseUrl'] = '';
    $threw = false;
    try {
        mithra_company_entity_url('Koninklijke van Twist', [], 'Production', 'Scanposten');
    } catch (RuntimeException $error) {
        $threw = str_contains($error->getMessage(), 'baseUrl ontbreekt');
    }
    mithra_assert_true($threw);
});

mimir_test_write_mock();
@unlink($GLOBALS['mimir_test_mock_log']);
$mimirTestServer = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $GLOBALS['mimir_test_mock_port'], $GLOBALS['mimir_test_mock_script']],
    [
        1 => ['file', sys_get_temp_dir() . '/mithra-mimir-mock.out', 'w'],
        2 => ['file', sys_get_temp_dir() . '/mithra-mimir-mock.err', 'w'],
    ],
    $mimirTestPipes,
    sys_get_temp_dir()
);
$GLOBALS['mimir_test_server'] = $mimirTestServer;
$mimirTestReady = false;
if (is_resource($mimirTestServer)) {
    for ($mimirTestAttempt = 0; $mimirTestAttempt < 50; $mimirTestAttempt++) {
        $mimirTestSocket = @fsockopen('127.0.0.1', (int) $GLOBALS['mimir_test_mock_port'], $mimirTestErrno, $mimirTestErrstr, 0.1);
        if (is_resource($mimirTestSocket)) {
            fclose($mimirTestSocket);
            $mimirTestReady = true;
            break;
        }
        usleep(100000);
    }
}

mithra_test('mock-server start', static function () use ($mimirTestReady): void {
    mithra_assert_true($mimirTestReady);
});

if ($mimirTestReady) {
    $GLOBALS['mimirApi'] = 'mimir_test_key';
    $GLOBALS['mimirBase'] = 'http://127.0.0.1:' . $GLOBALS['mimir_test_mock_port'] . '/mimir/api';
    $GLOBALS['baseUrl'] = '';
    unset($GLOBALS['auth_list'], $GLOBALS['environment'], $GLOBALS['auth']);
    mimir_test_reset_discovery_cache();

    mithra_test('Mímir company-discovery zonder BC-creds', static function (): void {
        $discovered = auth_discover_companies_across_active_environments(30);
        mithra_assert_same(
            ['Hunter van Twist', 'Koninklijke van Twist', "Van Twist's"],
            $discovered['companies'] ?? null
        );
        mithra_assert_same('Production', $discovered['map']['Koninklijke van Twist'] ?? null);
        mithra_assert_same('Sandbox', $discovered['map']['Hunter van Twist'] ?? null);
        mithra_assert_same('Production', $discovered['primary_environment'] ?? null);
        mithra_assert_same(
            ['Hunter van Twist', 'Koninklijke van Twist', "Van Twist's"],
            mithra_discover_companies()
        );
    });

    mithra_test('lege auth-sentinel in Mímir-modus', static function (): void {
        mithra_assert_same([], auth_get_auth_for_environment('Production'));
    });

    mithra_test('company-context zonder BC-auth', static function (): void {
        $context = auth_set_current_company_context('Hunter van Twist', 30);
        mithra_assert_same('Sandbox', $context['environment'] ?? null);
        mithra_assert_same([], $context['auth'] ?? null);
    });

    mithra_test('environments uit Mímir als auth_list ontbreekt', static function (): void {
        mimir_test_reset_discovery_cache();
        $active = auth_get_active_environments();
        mithra_assert_same(['Production', 'Sandbox'], $active);
        auth_discover_companies_across_active_environments(30);
    });

    mithra_test('Scanposten via Mímir zonder baseUrl en zonder filecache', static function (): void {
        $GLOBALS['baseUrl'] = '';
        unset($GLOBALS['auth_list'], $GLOBALS['auth']);
        $beforeCache = mimir_test_cache_files();
        $rows = mithra_fetch_entity_with_filter(
            'Koninklijke van Twist',
            'Scanposten',
            MITHRA_BC_SELECT_FIELDS,
            'Starting_Date ge 2026-01-01',
            'Starting_Date asc,Entry_No asc'
        );
        $row = $rows[0] ?? null;
        mithra_assert_true(is_array($row));
        mithra_assert_same('Koninklijke van Twist', $row['company'] ?? null);
        mithra_assert_same('Scanposten', $row['table'] ?? null);
        mithra_assert_same('Starting_Date ge 2026-01-01', $row['filter'] ?? null);
        mithra_assert_same(MITHRA_ODATA_TTL, $row['max_age'] ?? null);
        mithra_assert_true(in_array('Entry_No', $row['select'] ?? [], true));
        mithra_assert_true(in_array('KVT_User_Name_Scanner', $row['select'] ?? [], true));
        mithra_assert_same($beforeCache, mimir_test_cache_files());
    });

    mithra_test('ratatoskr uncached en filecache-pad gaan naar Mímir', static function (): void {
        $GLOBALS['baseUrl'] = '';
        $authPath = (string) ($GLOBALS['mimir_test_auth_path'] ?? '');
        if ($authPath !== '' && !is_file($authPath)) {
            file_put_contents($authPath, "<?php\n");
        }
        $url = ratatoskr_company_entity_url_with_query('Koninklijke van Twist', 'PurchaseOrders', [
            '$select' => 'No',
            '$filter' => "No eq 'PO1'",
        ], 'Production');
        $cacheKey = build_cache_key($url, []);
        $cachePath = cache_path_for_key($cacheKey);
        write_cache_json($cachePath, [['No' => 'STALE']], 3600, $url);
        $beforeCache = mimir_test_cache_files();

        mithra_assert_false(ratatoskr_odata_is_valid_cache_entry($url, [], 60));
        $uncached = ratatoskr_odata_get_all_uncached($url, []);
        mithra_assert_same('PurchaseOrders', $uncached[0]['table'] ?? null);
        mithra_assert_same(0, $uncached[0]['max_age'] ?? null);
        mithra_assert_same("No eq 'PO1'", $uncached[0]['filter'] ?? null);

        $cachedFlag = ratatoskr_odata_get_all_with_cache_flag($url, [], 60);
        mithra_assert_false((bool) ($cachedFlag['from_cache'] ?? true));
        mithra_assert_same(60, $cachedFlag['rows'][0]['max_age'] ?? null);
        mithra_assert_same('PurchaseOrders', $cachedFlag['rows'][0]['table'] ?? null);
        mithra_assert_false(($cachedFlag['rows'][0]['No'] ?? '') === 'STALE');
        mithra_assert_same($beforeCache, mimir_test_cache_files());
        @unlink($cachePath);
    });

    mithra_test('company-discovery-URL gaat naar Mímir, niet naar BC-host', static function (): void {
        $companyRows = odata_get_all('https://bc.example/Sandbox/ODataV4/Company?$select=Name', [], 30);
        $companyNames = array_map(static function (array $row): string {
            return (string) ($row['Name'] ?? '');
        }, $companyRows);
        mithra_assert_same(['Hunter van Twist'], $companyNames);
    });

    mithra_test('geen request naar de BC-host en Mímir-client user-agent', static function (): void {
        $hitBcHost = false;
        $sawMimirUa = false;
        foreach (mimir_test_mock_requests() as $request) {
            if (str_contains((string) ($request['uri'] ?? ''), 'bc.example')) {
                $hitBcHost = true;
            }
            if (($request['ua'] ?? '') === 'Mithra-MimirClient/1.0' && str_contains((string) ($request['uri'] ?? ''), '/mimir/api/')) {
                $sawMimirUa = true;
            }
        }
        mithra_assert_false($hitBcHost);
        mithra_assert_true($sawMimirUa);
    });

    mithra_test('overlap tussen Mímir-environments blijft een fout', static function (): void {
        mimir_test_reset_discovery_cache();
        $GLOBALS['mimirBase'] = 'http://127.0.0.1:' . $GLOBALS['mimir_test_mock_port'] . '/mimir-dup/api';
        $overlapThrew = false;
        try {
            auth_discover_companies_across_active_environments(30);
        } catch (RuntimeException $error) {
            $overlapThrew = str_contains($error->getMessage(), 'Bedrijfsnaam-overlap');
        }
        mithra_assert_true($overlapThrew);
        $GLOBALS['mimirBase'] = 'http://127.0.0.1:' . $GLOBALS['mimir_test_mock_port'] . '/mimir/api';
        mimir_test_reset_discovery_cache();
    });

    mithra_test('zonder Mímir blijft BC-fetch werken', static function (): void {
        $port = (int) $GLOBALS['mimir_test_mock_port'];
        $GLOBALS['mimirApi'] = '';
        $GLOBALS['baseUrl'] = 'http://127.0.0.1:' . $port;
        $GLOBALS['environment'] = 'Production';
        $GLOBALS['auth_list'] = [
            'Production' => [
                'mode' => 'basic',
                'user' => 'bcuser',
                'pass' => 'bcpass',
            ],
        ];
        $GLOBALS['auth'] = $GLOBALS['auth_list']['Production'];
        file_put_contents(
            (string) $GLOBALS['mimir_test_auth_path'],
            "<?php\n\$baseUrl = " . var_export($GLOBALS['baseUrl'], true) . ";\n\$environment = 'Production';\n\$auth_list = " . var_export($GLOBALS['auth_list'], true) . ";\n\$mimirApi = '';\n"
        );
        mimir_test_reset_discovery_cache();
        @unlink($GLOBALS['mimir_test_mock_log']);

        mithra_assert_false(odata_mimir_enabled());
        $bcRows = odata_get_all($GLOBALS['baseUrl'] . '/Production/ODataV4/Companies?$select=Name', $GLOBALS['auth'], 30);
        mithra_assert_same('bc', $bcRows[0]['via'] ?? null);
        mithra_assert_same('bcuser', $bcRows[0]['user'] ?? null);

        $bcHitMimir = false;
        foreach (mimir_test_mock_requests() as $request) {
            if (str_contains((string) ($request['uri'] ?? ''), '/mimir/')) {
                $bcHitMimir = true;
            }
        }
        mithra_assert_false($bcHitMimir);

        $cacheKey = build_cache_key($GLOBALS['baseUrl'] . '/Production/ODataV4/Companies?$select=Name', $GLOBALS['auth']);
        @unlink(cache_path_for_key($cacheKey));
    });
}

$GLOBALS['mimirApi'] = '';
unset($GLOBALS['mimirBase'], $GLOBALS['baseUrl'], $GLOBALS['environment'], $GLOBALS['auth_list'], $GLOBALS['auth']);
mimir_test_reset_discovery_cache();
mimir_test_stop_server();
mimir_test_restore_auth_file();
