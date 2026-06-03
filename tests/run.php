<?php

/**
 * Voer alle Mithra unit-tests uit: php tests/run.php
 */
$testsDir = __DIR__;
require_once $testsDir . '/mithra_test_lib.php';

mithra_test_reset();

$files = glob($testsDir . '/*_test.php') ?: [];
sort($files, SORT_STRING);

if ($files === []) {
    echo "Geen testbestanden gevonden in tests/.\n";
    exit(1);
}

echo "Mithra tests\n";
echo str_repeat('-', 14) . "\n";

foreach ($files as $file) {
    echo basename($file) . "\n";
    require $file;
    echo "\n";
}

$results = mithra_test_results();
echo str_repeat('-', 14) . "\n";
echo 'Passed: ' . (int) ($results['passed'] ?? 0) . ', Failed: ' . (int) ($results['failed'] ?? 0) . "\n";

exit(((int) ($results['failed'] ?? 0)) > 0 ? 1 : 0);
