<?php

$webDir = dirname(__DIR__) . '/web';
require_once $webDir . '/mithra_config.php';
require_once $webDir . '/mithra_bc.php';

mithra_test('username uit User_ID email wordt local-part', static function (): void {
    mithra_assert_same('jan.jansen', mithra_username_from_user_id('jan.jansen@kvt.nl'));
    mithra_assert_same('scanner1', mithra_username_from_user_id('scanner1'));
});

mithra_test('magazijnpost activiteitsregels', static function (): void {
    mithra_assert_true(mithra_wh_row_is_activity([
        'Entry_Type' => 'Verplaatsing',
        'Quantity' => 1,
    ]));
    mithra_assert_false(mithra_wh_row_is_activity([
        'Entry_Type' => 'Verplaatsing',
        'Quantity' => 0,
    ]));
    mithra_assert_true(mithra_wh_row_is_activity([
        'Entry_Type' => 'Positieve correctie',
        'Quantity' => 0,
    ]));
    mithra_assert_true(mithra_wh_row_is_activity([
        'Entry_Type' => 'Negatieve Correctie',
        'Quantity' => -2,
    ]));
    mithra_assert_false(mithra_wh_row_is_activity([
        'Entry_Type' => 'Overig',
        'Quantity' => 5,
    ]));
});

mithra_test('magazijnpost action label combineert type en document', static function (): void {
    $entry = mithra_wh_row_to_entry([
        'Entry_No' => 100,
        'Entry_Type' => 'Negatieve Correctie',
        'Whse_Document_No' => 'PRJ260195',
        'Registering_Date' => '2026-06-03',
        'User_ID' => 'tfalken@kvt.nl',
        'Quantity' => 1,
    ]);

    mithra_assert_same('Negatieve Correctie PRJ260195', $entry['action_label']);
    mithra_assert_same('tfalken', $entry['username']);
    mithra_assert_same('2026-06-03T00:00:00', $entry['activity_timestamp']);
});

mithra_test('magazijnpost forward filters op datum en entry_no', static function (): void {
    $filters = mithra_wh_forward_sync_odata_filters('2026-06-03', 120);
    mithra_assert_count(2, $filters);
    mithra_assert_same('Registering_Date gt 2026-06-03', $filters[0]);
    mithra_assert_same('Registering_Date eq 2026-06-03 and Entry_No gt 120', $filters[1]);
});
