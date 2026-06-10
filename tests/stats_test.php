<?php

$webDir = dirname(__DIR__) . '/web';
require_once $webDir . '/mithra_config.php';
require_once $webDir . '/mithra_bc.php';
require_once $webDir . '/mithra_heatmap.php';
require_once $webDir . '/mithra_scan_store.php';
require_once $webDir . '/mithra_wh_store.php';
require_once $webDir . '/mithra_stats.php';

mithra_test('dual stats tonen scans en handelingen', static function (): void {
    $scanEntries = [
        ['scan_timestamp' => '2026-06-02T08:00:00', 'scan_process' => 'Pick'],
        ['scan_timestamp' => '2026-06-02T09:00:00', 'scan_process' => 'Pick'],
    ];
    $whEntries = [
        ['activity_timestamp' => '2026-06-02T00:00:00', 'entry_type' => 'Verplaatsing'],
    ];

    $stats = mithra_stats_dual_block($scanEntries, $whEntries);

    mithra_assert_same(2, (int) ($stats['total']['scans'] ?? 0));
    mithra_assert_same(1, (int) ($stats['total']['handelingen'] ?? 0));
});

mithra_test('kalenderhelpers tellen bekende periodes inclusief', static function (): void {
    mithra_assert_same(3, mithra_stats_calendar_days_between('2026-06-01', '2026-06-03'));
    mithra_assert_same(2, mithra_stats_calendar_months_between('2026-05-15', '2026-06-03'));
    mithra_assert_same(2, mithra_stats_calendar_weeks_between('2026-05-26', '2026-06-03'));
});

mithra_test('dual stats gemiddelde per maand overschrijdt totaal niet', static function (): void {
    $entries = [
        ['scan_timestamp' => '2026-05-10T08:00:00', 'scan_process' => 'Pick'],
        ['scan_timestamp' => '2026-05-20T08:00:00', 'scan_process' => 'Pick'],
        ['scan_timestamp' => '2026-06-02T08:00:00', 'scan_process' => 'Pick'],
        ['scan_timestamp' => '2026-06-02T09:00:00', 'scan_process' => 'Pick'],
    ];

    $stats = mithra_stats_dual_block($entries, []);

    mithra_assert_less_or_equal((int) ($stats['total']['scans'] ?? 0), (int) ($stats['avg_month']['scans'] ?? 0));
    mithra_assert_true(array_key_exists('avg_month', $stats));
    mithra_assert_false(array_key_exists('avg_year', $stats));
});
