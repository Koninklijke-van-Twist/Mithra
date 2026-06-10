<?php

$webDir = dirname(__DIR__) . '/web';
require_once $webDir . '/mithra_config.php';
require_once $webDir . '/mithra_bc.php';
require_once $webDir . '/mithra_heatmap.php';
require_once $webDir . '/mithra_scan_store.php';
require_once $webDir . '/mithra_wh_store.php';

mithra_test('activity username groups mergen KVT-varianten', static function (): void {
    $groups = mithra_store_build_activity_username_groups(['KVT\\TIMF', 'TIMF', 'ALICE']);

    mithra_assert_count(2, $groups);
    mithra_assert_same('ALICE', $groups[0]['username']);
    mithra_assert_same('TIMF', $groups[1]['username']);
    mithra_assert_count(2, $groups[1]['variants']);
});

mithra_test('group daily counts combineert batch scan- en wh-maps', static function (): void {
    $group = [
        'username' => 'TIMF',
        'variants' => ['TIMF', 'KVT\\TIMF'],
    ];
    $scanByUser = [
        'TIMF' => ['2026-06-01' => 2],
        'KVT\\TIMF' => ['2026-06-01' => 1, '2026-06-02' => 4],
    ];
    $whByUser = [
        'TIMF' => ['2026-06-02' => 3],
    ];

    $counts = mithra_store_collect_group_daily_counts($group, $scanByUser, $whByUser);

    mithra_assert_same(3, (int) ($counts['2026-06-01'] ?? 0));
    mithra_assert_same(7, (int) ($counts['2026-06-02'] ?? 0));
});
