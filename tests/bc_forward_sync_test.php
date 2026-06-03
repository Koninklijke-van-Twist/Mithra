<?php

$webDir = dirname(__DIR__) . '/web';
require_once $webDir . '/mithra_config.php';
require_once $webDir . '/mithra_bc.php';

mithra_test('forward sync gebruikt twee OData-filters op datum en tijd', static function (): void {
    $filters = mithra_forward_sync_odata_filters('2026-06-03T14:30:00');

    mithra_assert_count(2, $filters);
    mithra_assert_same('Starting_Date gt 2026-06-03', $filters[0]);
    mithra_assert_same("Starting_Date eq 2026-06-03 and Starting_Time gt '14:30:00'", $filters[1]);
});

mithra_test('forward sync filter quote tijd met enkel aanhalingsteken', static function (): void {
    $filters = mithra_forward_sync_odata_filters("2026-06-03T14:30:00");

    mithra_assert_same("Starting_Date eq 2026-06-03 and Starting_Time gt '14:30:00'", $filters[1]);
    mithra_assert_same("'it''s'", mithra_odata_quote_string("it's"));
});

mithra_test('BC Starting_Time met milliseconden wordt correct geparsed', static function (): void {
    mithra_assert_same('10:26:27.287', mithra_parse_starting_time('10:26:27.287'));
    mithra_assert_same('10:20:21.1', mithra_parse_starting_time('10:20:21.1'));
    mithra_assert_same('2026-06-03T10:26:27.287', mithra_row_scan_timestamp([
        'Starting_Date' => '2026-06-03',
        'Starting_Time' => '10:26:27.287',
    ]));
    mithra_assert_same(
        "Starting_Date eq 2026-06-03 and Starting_Time gt '10:20:13'",
        mithra_forward_sync_odata_filters('2026-06-03T10:20:13')[1]
    );
});

mithra_test('merge van forward sync dedupliceert op entry_no', static function (): void {
    $sameDayTail = [
        [
            'entry_no' => 101,
            'username' => 'alice',
            'scan_process' => 'pick',
            'scan_timestamp' => '2026-06-03T15:00:00',
        ],
    ];
    $laterDays = [
        [
            'entry_no' => 101,
            'username' => 'alice',
            'scan_process' => 'pick',
            'scan_timestamp' => '2026-06-03T15:00:00',
        ],
        [
            'entry_no' => 202,
            'username' => 'bob',
            'scan_process' => 'pick',
            'scan_timestamp' => '2026-06-04T09:00:00',
        ],
    ];

    $merged = mithra_merge_scan_entries($sameDayTail, $laterDays);

    mithra_assert_count(2, $merged);
    mithra_assert_same(101, $merged[0]['entry_no']);
    mithra_assert_same(202, $merged[1]['entry_no']);
});

mithra_test('watermark filter laat alleen strikt nieuwere scans door', static function (): void {
    $entries = [
        ['entry_no' => 1, 'scan_timestamp' => '2026-06-03T14:30:00'],
        ['entry_no' => 2, 'scan_timestamp' => '2026-06-03T14:30:01'],
        ['entry_no' => 3, 'scan_timestamp' => '2026-06-04T08:00:00'],
    ];

    $filtered = mithra_filter_entries_newer_than($entries, '2026-06-03T14:30:00');

    mithra_assert_count(2, $filtered);
    mithra_assert_same(2, $filtered[0]['entry_no']);
    mithra_assert_same(3, $filtered[1]['entry_no']);
});
