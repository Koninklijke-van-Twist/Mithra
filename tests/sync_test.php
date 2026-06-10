<?php

$webDir = dirname(__DIR__) . '/web';
require_once $webDir . '/mithra_config.php';
require_once $webDir . '/mithra_scan_sync.php';

mithra_test('backfill-fase vereist chunk-loop', static function (): void {
    mithra_assert_true(mithra_sync_needs_backfill_chunks(['backfill_complete' => 0, 'wh_backfill_complete' => 1]));
    mithra_assert_true(mithra_sync_needs_backfill_chunks(['backfill_complete' => 1, 'wh_backfill_complete' => 0]));
    mithra_assert_false(mithra_sync_needs_backfill_chunks(['backfill_complete' => 1, 'wh_backfill_complete' => 1]));
});

mithra_test('forward-fase chunk progress is nul', static function (): void {
    $progress = mithra_sync_chunk_progress(99, true);
    mithra_assert_same(0, $progress['chunk_current']);
    mithra_assert_same(0, $progress['chunk_total']);
});

mithra_test('backfill-fase chunk progress telt door', static function (): void {
    $progress = mithra_sync_chunk_progress(3, false);
    mithra_assert_same(3, $progress['chunk_current']);
    mithra_assert_greater_than(0, $progress['chunk_total']);
});

mithra_test('backfill_complete blijft behouden na refresh-logica', static function (): void {
    $state = [
        'backfill_complete' => 1,
        'backfill_to_date' => '',
        'oldest_scan_timestamp' => '2010-01-15T10:00:00',
        'wh_backfill_complete' => 1,
        'wh_backfill_to_date' => '',
        'wh_oldest_activity_date' => '2010-01-15',
    ];

    $refreshed = mithra_sync_refresh_backfill_state($state, 'Test Company');
    mithra_assert_same(1, (int) ($refreshed['backfill_complete'] ?? 0));
    mithra_assert_same(1, (int) ($refreshed['wh_backfill_complete'] ?? 0));
});
