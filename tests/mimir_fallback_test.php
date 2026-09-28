<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/mithra-mimir-fallback-test.log';
@unlink($logFile);
$mimirFallbackPreviousLog = ini_get('error_log');
$mimirFallbackPreviousLogErrors = ini_get('log_errors');
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['MITHRA_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/ODataV4/Company(?:\\?|$)#', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require_once dirname(__DIR__) . '/web/odata.php';
require_once dirname(__DIR__) . '/web/mithra_bc.php';
require_once dirname(__DIR__) . '/web/ratatoskr_orders.php';

$mimirFallbackAuthPath = dirname(__DIR__) . '/web/auth.php';
$mimirFallbackAuthExisted = is_file($mimirFallbackAuthPath);
$mimirFallbackAuthBackup = $mimirFallbackAuthExisted ? file_get_contents($mimirFallbackAuthPath) : null;
$mimirFallbackCreatedAuth = false;

function mimir_fallback_cleanup(): void
{
    global $mimirFallbackPreviousLog, $mimirFallbackPreviousLogErrors, $mimirFallbackAuthPath;
    global $mimirFallbackAuthExisted, $mimirFallbackAuthBackup, $mimirFallbackCreatedAuth;
    global $mimirApi, $mimirBase, $baseUrl, $environment, $auth, $auth_list;

    unset($GLOBALS['MITHRA_ODATA_BC_FETCH']);
    if (function_exists('odata_mimir_circuit_reset')) {
        odata_mimir_circuit_reset();
    }

    $mimirApi = '';
    $GLOBALS['mimirApi'] = '';
    unset($GLOBALS['mimirBase'], $GLOBALS['baseUrl'], $GLOBALS['environment'], $GLOBALS['auth_list'], $GLOBALS['auth']);
    $mimirBase = '';
    $baseUrl = '';
    $environment = '';
    $auth = [];
    $auth_list = [];

    if ($mimirFallbackCreatedAuth && !$mimirFallbackAuthExisted) {
        @unlink($mimirFallbackAuthPath);
    } elseif ($mimirFallbackAuthExisted && is_string($mimirFallbackAuthBackup)) {
        file_put_contents($mimirFallbackAuthPath, $mimirFallbackAuthBackup);
    }

    if (is_string($mimirFallbackPreviousLog)) {
        ini_set('error_log', $mimirFallbackPreviousLog);
    }
    if (is_string($mimirFallbackPreviousLogErrors)) {
        ini_set('log_errors', $mimirFallbackPreviousLogErrors);
    }
}

function mimir_fallback_fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    mimir_fallback_cleanup();
    exit(1);
}

function mimir_fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function mimir_fallback_count(): int
{
    return substr_count(mimir_fallback_log(), '[Mithra] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    mimir_fallback_fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    mimir_fallback_fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    mimir_fallback_fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    mimir_fallback_fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$syntheticCompanyUrl = mithra_company_entity_url('KVT Gas', ['$select' => 'No'], 'Production', 'AppWerkorders');
if (strpos($syntheticCompanyUrl, 'https://mimir.invalid/Production/ODataV4/Company(') !== 0) {
    mimir_fallback_fail('met Mímir aan moet de company-URL synthetisch zijn, kreeg: ' . $syntheticCompanyUrl);
}
$syntheticRatatoskrUrl = ratatoskr_company_entity_url_with_query('KVT Gas', 'PurchaseOrders', ['$select' => 'No'], 'Production');
if (strpos($syntheticRatatoskrUrl, 'https://mimir.invalid/Production/ODataV4/Company(') !== 0) {
    mimir_fallback_fail('ratatoskr-URL moet synthetisch zijn zolang Mímir de proxy is, kreeg: ' . $syntheticRatatoskrUrl);
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    mimir_fallback_fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    mimir_fallback_fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    mimir_fallback_fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    mimir_fallback_fail('company-fallback gebruikte niet de BC-credentials');
}

$directCompanyUrl = mithra_company_entity_url('KVT Gas', ['$select' => 'No'], 'Production', 'AppWerkorders');
if (strpos($directCompanyUrl, 'https://bc.example:7148/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?') !== 0) {
    mimir_fallback_fail('na de circuit-open moet de company-URL de oude BC-URL bouwen, kreeg: ' . $directCompanyUrl);
}
if (strpos($directCompanyUrl, 'mimir.invalid') !== false) {
    mimir_fallback_fail('synthetische host bleef staan na fallback');
}
$directRatatoskrUrl = ratatoskr_company_entity_url_with_query('KVT Gas', 'PurchaseOrders', ['$select' => 'No'], 'Production');
if (strpos($directRatatoskrUrl, 'https://bc.example:7148/Production/ODataV4/Company(\'KVT%20Gas\')/PurchaseOrders?') !== 0) {
    mimir_fallback_fail('ratatoskr moet na de circuit-open de BC-URL bouwen, kreeg: ' . $directRatatoskrUrl);
}

$mimirBase = 'http://192.0.2.1:9';
$GLOBALS['mimirBase'] = $mimirBase;
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    mimir_fallback_fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    mimir_fallback_fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[1] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    mimir_fallback_fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (mimir_fallback_count() < 2) {
    mimir_fallback_fail('elke fallback moet gelogd worden, log=' . mimir_fallback_log());
}
$log = mimir_fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    mimir_fallback_fail('log bevat een geheim');
}
if (strpos($log, '[Mithra] Mímir failed, falling back to direct OData:') === false) {
    mimir_fallback_fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$GLOBALS['mimirBase'] = $mimirBase;
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    mimir_fallback_fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    mimir_fallback_fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    mimir_fallback_fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    mimir_fallback_fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    mimir_fallback_fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

$loggedBeforeRethrow = mimir_fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$GLOBALS['mimirBase'] = $mimirBase;
$baseUrl = 'https://mimir.invalid/';
$GLOBALS['baseUrl'] = $baseUrl;
$environment = 'mimir';
$GLOBALS['environment'] = $environment;
$auth = [];
$GLOBALS['auth'] = $auth;
$auth_list = [];
$GLOBALS['auth_list'] = $auth_list;
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    mimir_fallback_fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    mimir_fallback_fail('zonder BC-credentials kwam er geen fout terug');
}
if (strpos($rethrown->getMessage(), 'Mímir') === false) {
    mimir_fallback_fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    mimir_fallback_fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    mimir_fallback_fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (mimir_fallback_count() !== $loggedBeforeRethrow) {
    mimir_fallback_fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

if (!$mimirFallbackAuthExisted) {
    odata_mimir_circuit_reset();
    $mimirApi = 'mimir_test_key_should_not_leak';
    $GLOBALS['mimirApi'] = $mimirApi;
    $mimirBase = 'http://127.0.0.1:9';
    $GLOBALS['mimirBase'] = $mimirBase;
    unset($GLOBALS['baseUrl'], $GLOBALS['environment'], $GLOBALS['auth'], $GLOBALS['auth_list']);
    $baseUrl = '';
    $environment = '';
    $auth = [];
    $auth_list = [];
    file_put_contents(
        $mimirFallbackAuthPath,
        "<?php\n"
        . "\$mimirApi = 'mimir_from_file_should_not_win';\n"
        . "\$mimirBase = 'http://127.0.0.1:9';\n"
        . "\$baseUrl = 'https://bc.example:7148/';\n"
        . "\$environment = 'Production';\n"
        . "\$auth_list = ['Production' => ['mode' => 'basic', 'user' => 'fileuser', 'pass' => 'file-secret']];\n"
        . "\$auth = \$auth_list['Production'];\n"
    );
    $mimirFallbackCreatedAuth = true;
    $beforeLazy = count($calls);
    $lazyNames = odata_mimir_list_companies(null);
    if ($lazyNames !== $expectedNames) {
        mimir_fallback_fail('lazy auth.php-fallback gaf ' . json_encode($lazyNames));
    }
    $lazyCall = $calls[$beforeLazy] ?? null;
    if (!is_array($lazyCall) || $lazyCall['user'] !== 'fileuser' || strpos($lazyCall['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
        mimir_fallback_fail('lazy auth.php leverde de BC-credentials niet: ' . json_encode($lazyCall));
    }
    $lazyLog = mimir_fallback_log();
    if (strpos($lazyLog, 'file-secret') !== false || strpos($lazyLog, 'mimir_from_file_should_not_win') !== false) {
        mimir_fallback_fail('lazy-load log bevat een geheim');
    }
    @unlink($mimirFallbackAuthPath);
    $mimirFallbackCreatedAuth = false;
}

odata_mimir_circuit_reset();
$mimirApi = '';
$GLOBALS['mimirApi'] = '';
$mimirBase = 'http://127.0.0.1:9';
$GLOBALS['mimirBase'] = $mimirBase;
$baseUrl = 'https://bc.example:7148/';
$GLOBALS['baseUrl'] = $baseUrl;
$environment = 'Production';
$GLOBALS['environment'] = $environment;
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$GLOBALS['auth'] = $auth;
$auth_list = ['Production' => $auth];
$GLOBALS['auth_list'] = $auth_list;
$loggedBeforeDirect = mimir_fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    mimir_fallback_fail('lege $mimirApi mag Mímir niet proberen');
}
if (mimir_fallback_count() !== $loggedBeforeDirect) {
    mimir_fallback_fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    mimir_fallback_fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

mimir_fallback_cleanup();
echo "OK\n";
