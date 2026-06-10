<?php

/**
 * Voorkomt regressie: dubbele functiedefinities bij combined includes.
 */
$webDir = dirname(__DIR__) . '/web';

mithra_test('alle Mithra-modules laden zonder redeclare-fout', static function () use ($webDir): void {
    require_once $webDir . '/mithra_config.php';
    require_once $webDir . '/mithra_bc.php';
    require_once $webDir . '/mithra_heatmap.php';
    require_once $webDir . '/mithra_scan_store.php';
    require_once $webDir . '/mithra_stats.php';
    require_once $webDir . '/mithra_scan_sync.php';
    require_once $webDir . '/mithra_wh_store.php';

    mithra_assert_true(function_exists('mithra_normalize_date_only'));
    mithra_assert_true(function_exists('mithra_heatmap_build_grid_days'));
    mithra_assert_true(function_exists('mithra_stats_dual_block'));
    mithra_assert_true(function_exists('mithra_wh_row_to_entry'));
});

mithra_test('mithra_normalize_date_only staat alleen in mithra_bc.php', static function (): void {
    $bcFile = dirname(__DIR__) . '/web/mithra_bc.php';
    $heatmapFile = dirname(__DIR__) . '/web/mithra_heatmap.php';
    $storeFile = dirname(__DIR__) . '/web/mithra_scan_store.php';

    mithra_assert_same(1, substr_count((string) file_get_contents($bcFile), 'function mithra_normalize_date_only'));
    mithra_assert_same(0, substr_count((string) file_get_contents($heatmapFile), 'function mithra_normalize_date_only'));
    mithra_assert_same(0, substr_count((string) file_get_contents($storeFile), 'function mithra_normalize_date_only'));
});
