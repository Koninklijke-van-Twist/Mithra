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
$mimirFallbackTempAuths = [];

function mimir_fallback_cleanup(): void
{
    global $mimirFallbackPreviousLog, $mimirFallbackPreviousLogErrors, $mimirFallbackAuthPath;
    global $mimirFallbackAuthExisted, $mimirFallbackAuthBackup, $mimirFallbackCreatedAuth, $mimirFallbackTempAuths;
    global $mimirApi, $mimirBase, $baseUrl, $environment, $auth, $auth_list;

    unset($GLOBALS['MITHRA_ODATA_BC_FETCH']);
    unset($GLOBALS['MITHRA_AUTH_PHP_PATH'], $GLOBALS['MITHRA_AUTH_PHP_INCLUDED']);
    unset($GLOBALS['demeter_company_environment_map'], $GLOBALS['base']);
    if (is_array($mimirFallbackTempAuths)) {
        foreach ($mimirFallbackTempAuths as $tempAuthPath) {
            if (is_string($tempAuthPath) && $tempAuthPath !== '') {
                @unlink($tempAuthPath);
            }
        }
    }
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

function mimir_fallback_seen_global_base_url(): string
{
    global $baseUrl;
    return is_string($baseUrl) ? $baseUrl : '';
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
if (mimir_fallback_count() !== 1) {
    mimir_fallback_fail('alleen de eerste Mímir-fout mag een fallback loggen, log=' . mimir_fallback_log());
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
$mimirApi = 'mimir_test_key_should_not_leak';
$GLOBALS['mimirApi'] = $mimirApi;
$mimirBase = 'http://127.0.0.1:9';
$GLOBALS['mimirBase'] = $mimirBase;
$baseUrl = 'https://bc.example:7148/';
$GLOBALS['baseUrl'] = $baseUrl;
$environment = 'Production';
$GLOBALS['environment'] = $environment;
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$GLOBALS['auth'] = $auth;
$auth_list = [
    'Production' => $auth,
    'Sandbox' => ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'],
    'My Env' => ['mode' => 'basic', 'user' => 'space-user', 'pass' => 'space-secret'],
];
$GLOBALS['auth_list'] = $auth_list;
$GLOBALS['demeter_company_environment_map'] = [
    'KVT Gas' => 'Production',
    'Hunter van Twist' => 'Sandbox',
    'Space Co' => 'My Env',
];
$loggedBeforeCaller = mimir_fallback_count();
$callsBeforeCaller = count($calls);
$callerCaught = null;
try {
    odata_mimir_or_direct(
        static function (): array {
            throw new RuntimeException('caller-bug-not-mimir');
        },
        static function (): array {
            return [['No' => 'SHOULD-NOT']];
        }
    );
    mimir_fallback_fail('een fout van de aanroeper moet blijven staan');
} catch (RuntimeException $exception) {
    $callerCaught = $exception;
}
if (!$callerCaught instanceof RuntimeException || $callerCaught->getMessage() !== 'caller-bug-not-mimir') {
    mimir_fallback_fail('caller-fout is niet ongewijzigd doorgekomen');
}
if (odata_mimir_circuit_open()) {
    mimir_fallback_fail('een fout van de aanroeper mag het circuit niet openen');
}
if (count($calls) !== $callsBeforeCaller || mimir_fallback_count() !== $loggedBeforeCaller) {
    mimir_fallback_fail('een fout van de aanroeper mag geen fallback starten');
}

$loggedBeforeSandbox = mimir_fallback_count();
$beforeSandbox = count($calls);
$sandboxRows = odata_mimir_companies_as_rows('Sandbox');
if (($sandboxRows[0]['Name'] ?? '') === '' || ($sandboxRows[0]['environment'] ?? '') !== 'Sandbox') {
    mimir_fallback_fail('Sandbox-companylijst gebruikte niet het gevraagde environment: ' . json_encode($sandboxRows));
}
$sandboxCall = $calls[$beforeSandbox] ?? null;
if (!is_array($sandboxCall) || $sandboxCall['user'] !== 'sandbox-user' || strpos($sandboxCall['url'], 'https://bc.example:7148/Sandbox/ODataV4/Company') !== 0) {
    mimir_fallback_fail('Sandbox-companylijst gebruikte niet de Sandbox-credentials: ' . json_encode($sandboxCall));
}
if (count($calls) !== $beforeSandbox + 1) {
    mimir_fallback_fail('een gefilterd environment mag niet ook het primaire environment ophalen');
}
if (mimir_fallback_count() !== $loggedBeforeSandbox + 1) {
    mimir_fallback_fail('de eerste Sandbox-fallback moet één keer gelogd worden');
}

$beforeHunter = count($calls);
$hunterRows = odata_mimir_query('Hunter van Twist', 'AppResource', ['$select' => 'No'], 30);
if (($hunterRows[0]['No'] ?? '') !== 'WO-1') {
    mimir_fallback_fail('query voor een Sandbox-bedrijf viel niet terug');
}
$hunterCall = $calls[$beforeHunter] ?? null;
$hunterUrl = is_array($hunterCall) ? (string) $hunterCall['url'] : '';
if (!is_array($hunterCall) || $hunterCall['user'] !== 'sandbox-user' || strpos($hunterUrl, "/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/") === false) {
    mimir_fallback_fail('bedrijf uit de environment-map hield het primaire environment: ' . json_encode($hunterCall));
}
if (strpos($hunterUrl, '/Production/') !== false) {
    mimir_fallback_fail('Sandbox-bedrijf werd naar Production gestuurd: ' . $hunterUrl);
}
if (mimir_fallback_count() !== $loggedBeforeSandbox + 1) {
    mimir_fallback_fail('na het openen van het circuit mag niet opnieuw gelogd worden');
}

$beforeSegment = count($calls);
$segmentRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?\$select=No",
    $auth,
    10
);
$segmentCall = $calls[$beforeSegment] ?? null;
if (($segmentRows[0]['No'] ?? '') !== 'WO-1' || !is_array($segmentCall) || $segmentCall['user'] !== 'sandbox-user') {
    mimir_fallback_fail('URL-segment Sandbox verloor het van het meegegeven Production-auth: ' . json_encode($segmentCall));
}
if (!is_array($segmentCall) || strpos($segmentCall['url'], 'https://bc.example:7148/Sandbox/ODataV4/Company(') !== 0) {
    mimir_fallback_fail('mimir.invalid-URL met Sandbox-segment werd niet herschreven: ' . json_encode($segmentCall));
}

$spacedUrl = odata_bc_url_from_odata_url("https://mimir.invalid/My%20Env/ODataV4/Company('X')/T?\$select=No");
if ($spacedUrl !== "https://bc.example:7148/My%20Env/ODataV4/Company('X')/T?\$select=No" || strpos($spacedUrl, 'My%2520Env') !== false) {
    mimir_fallback_fail('environment met spatie werd niet precies één keer gecodeerd: ' . $spacedUrl);
}
$beforeSpace = count($calls);
$spaceRows = odata_mimir_query('Space Co', 'AppResource', ['$select' => 'No'], 10);
$spaceCall = $calls[$beforeSpace] ?? null;
$spaceUrl = is_array($spaceCall) ? (string) $spaceCall['url'] : '';
if (($spaceRows[0]['No'] ?? '') !== 'WO-1' || !is_array($spaceCall) || $spaceCall['user'] !== 'space-user' || strpos($spaceUrl, '/My%20Env/') === false || strpos($spaceUrl, 'My%2520Env') !== false) {
    mimir_fallback_fail('tweede environment met spatie gebruikte niet zijn eigen auth: ' . json_encode($spaceCall));
}

$mappedUrl = odata_bc_url_from_odata_url("https://mimir.invalid/mimir/ODataV4/Company('Hunter%20van%20Twist')/AppResource");
if (strpos($mappedUrl, 'https://bc.example:7148/Sandbox/ODataV4/Company(') !== 0 || strpos($mappedUrl, '/mimir/') !== false) {
    mimir_fallback_fail('placeholder-segment mimir moet het bedrijf in de map volgen: ' . $mappedUrl);
}

$beforeUnknown = count($calls);
$unknownRows = odata_mimir_query('Onbekend BV', 'AppResource', ['$select' => 'No'], 10);
$unknownCall = $calls[$beforeUnknown] ?? null;
if (($unknownRows[0]['No'] ?? '') !== 'WO-1' || !is_array($unknownCall) || $unknownCall['user'] !== 'bcuser' || strpos((string) $unknownCall['url'], 'https://bc.example:7148/Production/ODataV4/Company(') !== 0) {
    mimir_fallback_fail('onbekend bedrijf moet op het primaire environment terugvallen: ' . json_encode($unknownCall));
}

$GLOBALS['environment'] = 'mimir';
$cacheKey = build_cache_key("https://bc.example:7148/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource", $auth_list['Sandbox']);
if (strpos($cacheKey, '|sandbox-user|Sandbox') === false || strpos($cacheKey, 'mimir') !== false) {
    mimir_fallback_fail('cache-key moet het echte BC-environment gebruiken: ' . $cacheKey);
}
$GLOBALS['environment'] = 'Production';
$environment = 'Production';
if (strpos(mimir_fallback_log(), 'sandbox-secret') !== false || strpos(mimir_fallback_log(), 'space-secret') !== false) {
    mimir_fallback_fail('log bevat een environment-wachtwoord');
}

$mimirFailureMessages = [
    'Mímir HTTP 400: bad request',
    'Mímir HTTP 401: unauthorized',
    'Mímir HTTP 404: missing',
    'Mímir gaf ongeldige JSON terug.',
    'Mímir error: upstream said no',
    "Mímir query-antwoord mist 'value'.",
    "Mímir companies-antwoord mist 'value'.",
];
foreach ($mimirFailureMessages as $mimirFailureMessage) {
    odata_mimir_circuit_reset();
    $loggedBeforeFailure = mimir_fallback_count();
    $failureRows = odata_mimir_or_direct(
        static function () use ($mimirFailureMessage): array {
            throw new Exception($mimirFailureMessage);
        },
        static function (): array {
            return [['No' => 'WO-FAIL']];
        }
    );
    if (($failureRows[0]['No'] ?? '') !== 'WO-FAIL' || !odata_mimir_circuit_open() || mimir_fallback_count() !== $loggedBeforeFailure + 1) {
        mimir_fallback_fail('Mímir-fout moet terugvallen en het circuit openen: ' . $mimirFailureMessage);
    }
}

$callerErrorMessages = [
    'Mímir: OData-URL kon niet worden vertaald naar company/table: https://bc.example/x',
    'Mímir request JSON encode mislukt.',
];
foreach ($callerErrorMessages as $callerErrorMessage) {
    odata_mimir_circuit_reset();
    $loggedBeforeCallerError = mimir_fallback_count();
    $beforeCallerError = count($calls);
    $callerErrorRows = odata_mimir_or_direct(
        static function () use ($callerErrorMessage): array {
            throw new Exception($callerErrorMessage);
        },
        static function () use (&$calls): array {
            $calls[] = ['url' => 'direct-caller-error', 'user' => 'bcuser', 'ttl' => 1];
            return [['No' => 'WO-CALLER']];
        }
    );
    $callerErrorCall = $calls[count($calls) - 1] ?? null;
    if (($callerErrorRows[0]['No'] ?? '') !== 'WO-CALLER' || !is_array($callerErrorCall) || $callerErrorCall['url'] !== 'direct-caller-error') {
        mimir_fallback_fail('aanroeperfout moet voor deze call terugvallen op BC: ' . $callerErrorMessage);
    }
    if (odata_mimir_circuit_open() || mimir_fallback_count() !== $loggedBeforeCallerError || count($calls) !== $beforeCallerError + 1) {
        mimir_fallback_fail('aanroeperfout mag het circuit niet openen of een fallback loggen: ' . $callerErrorMessage);
    }
}

odata_mimir_circuit_reset();
$loggedBeforeUntranslated = mimir_fallback_count();
$beforeUntranslated = count($calls);
$untranslatedUrl = 'https://bc.example:7148/Production/ODataV4/CustomEndpoint';
$untranslatedRows = odata_get_all($untranslatedUrl, $auth, 12);
$untranslatedCall = $calls[$beforeUntranslated] ?? null;
if (($untranslatedRows[0]['No'] ?? '') !== 'WO-1' || !is_array($untranslatedCall) || $untranslatedCall['url'] !== $untranslatedUrl || $untranslatedCall['user'] !== 'bcuser') {
    mimir_fallback_fail('onvertaalbare URL viel niet terug op de oorspronkelijke BC-URL: ' . json_encode($untranslatedCall));
}
if (odata_mimir_circuit_open() || mimir_fallback_count() !== $loggedBeforeUntranslated) {
    mimir_fallback_fail('een onvertaalbare URL mag het circuit niet openen');
}

odata_mimir_circuit_reset();
$auth_list['Sandbox'] = ['mode' => 'basic', 'user' => '', 'pass' => 'sandbox-secret'];
$GLOBALS['auth_list'] = $auth_list;
$loggedBeforeUnusable = mimir_fallback_count();
$beforeUnusable = count($calls);
$unusableCaught = null;
try {
    odata_get_all(
        "https://mimir.invalid/Sandbox/ODataV4/Company('Hunter%20van%20Twist')/AppResource?\$select=No",
        $auth,
        10
    );
    mimir_fallback_fail('Sandbox zonder bruikbare credentials mag niet met Production-auth worden opgehaald');
} catch (Throwable $exception) {
    $unusableCaught = $exception;
}
if (!$unusableCaught instanceof Throwable || strpos($unusableCaught->getMessage(), 'Mímir') === false) {
    mimir_fallback_fail('Sandbox zonder credentials gooide niet de Mímir-fout terug');
}
if (count($calls) !== $beforeUnusable) {
    mimir_fallback_fail('Sandbox zonder credentials stuurde toch een directe fetch: ' . json_encode($calls[$beforeUnusable] ?? null));
}
if (!odata_mimir_circuit_open() || mimir_fallback_count() !== $loggedBeforeUnusable + 1) {
    mimir_fallback_fail('de Mímir-fout zelf moet het circuit nog wel openen');
}
$auth_list['Sandbox'] = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$GLOBALS['auth_list'] = $auth_list;

$globalsAuthPath = sys_get_temp_dir() . '/mithra-auth-globals-' . getmypid() . '.php';
$baseOnlyAuthPath = sys_get_temp_dir() . '/mithra-auth-base-only-' . getmypid() . '.php';
$mimirFallbackTempAuths = [$globalsAuthPath, $baseOnlyAuthPath];
file_put_contents(
    $globalsAuthPath,
    "<?php\n"
    . "\$baseUrl = 'https://from-file.example/';\n"
    . "\$base = 'https://from-base.example/';\n"
    . "\$environment = 'Sandbox';\n"
    . "\$auth = ['mode' => 'basic', 'user' => 'globals-user', 'pass' => 'file-secret'];\n"
    . "\$auth_list = ['Sandbox' => \$auth];\n"
);
unset($GLOBALS['baseUrl'], $GLOBALS['base'], $GLOBALS['environment'], $GLOBALS['auth'], $GLOBALS['auth_list'], $GLOBALS['MITHRA_AUTH_PHP_INCLUDED']);
$baseUrl = '';
$environment = '';
$auth = [];
$auth_list = [];
$GLOBALS['MITHRA_AUTH_PHP_PATH'] = $globalsAuthPath;
odata_ensure_bc_auth_loaded();
if (mimir_fallback_seen_global_base_url() !== 'https://from-file.example/' || ($GLOBALS['baseUrl'] ?? '') !== 'https://from-file.example/') {
    mimir_fallback_fail('auth.php-load maakte baseUrl niet globaal zichtbaar: ' . mimir_fallback_seen_global_base_url());
}
if (($GLOBALS['base'] ?? '') !== 'https://from-base.example/' || ($GLOBALS['environment'] ?? '') !== 'Sandbox') {
    mimir_fallback_fail('closure-load kopieerde base of environment niet naar $GLOBALS');
}
if (!is_array($GLOBALS['auth'] ?? null) || ($GLOBALS['auth']['user'] ?? '') !== 'globals-user' || !isset($GLOBALS['auth_list']['Sandbox'])) {
    mimir_fallback_fail('closure-load kopieerde auth niet naar $GLOBALS');
}

$GLOBALS['baseUrl'] = 'https://already.example/';
$GLOBALS['environment'] = 'Production';
unset($GLOBALS['auth'], $GLOBALS['auth_list'], $GLOBALS['base']);
unset($GLOBALS['MITHRA_AUTH_PHP_INCLUDED']);
odata_ensure_bc_auth_loaded();
if (mimir_fallback_seen_global_base_url() !== 'https://already.example/' || ($GLOBALS['environment'] ?? '') !== 'Production') {
    mimir_fallback_fail('al gezette baseUrl of environment werd overschreven');
}
if (!is_array($GLOBALS['auth'] ?? null) || ($GLOBALS['auth']['user'] ?? '') !== 'globals-user') {
    mimir_fallback_fail('ontbrekende auth werd niet uit het bestand aangevuld');
}

file_put_contents(
    $baseOnlyAuthPath,
    "<?php\n"
    . "\$base = 'https://base-only.example/';\n"
    . "\$environment = 'Production';\n"
    . "\$auth = ['mode' => 'basic', 'user' => 'base-user', 'pass' => 'file-secret'];\n"
    . "\$auth_list = ['Production' => \$auth];\n"
);
unset($GLOBALS['baseUrl'], $GLOBALS['base'], $GLOBALS['auth'], $GLOBALS['auth_list'], $GLOBALS['environment'], $GLOBALS['MITHRA_AUTH_PHP_INCLUDED']);
$GLOBALS['MITHRA_AUTH_PHP_PATH'] = $baseOnlyAuthPath;
odata_ensure_bc_auth_loaded();
if (($GLOBALS['baseUrl'] ?? '') !== 'https://base-only.example/' || ($GLOBALS['base'] ?? '') !== 'https://base-only.example/') {
    mimir_fallback_fail('\$base vulde baseUrl niet aan: ' . (string) ($GLOBALS['baseUrl'] ?? ''));
}
unset($GLOBALS['MITHRA_AUTH_PHP_PATH'], $GLOBALS['MITHRA_AUTH_PHP_INCLUDED']);
@unlink($globalsAuthPath);
@unlink($baseOnlyAuthPath);
$mimirFallbackTempAuths = [];

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
