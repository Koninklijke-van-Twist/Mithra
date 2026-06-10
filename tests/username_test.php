<?php

$webDir = dirname(__DIR__) . '/web';
require_once $webDir . '/mithra_bc.php';

mithra_test('KVT-prefix wordt gestript voor normalisatie', static function (): void {
    mithra_assert_same('TIMF', mithra_normalize_username('KVT\\TIMF'));
    mithra_assert_same('TIMF', mithra_normalize_username('kvt\\TIMF'));
    mithra_assert_same('TIMF', mithra_normalize_username('TIMF'));
});

mithra_test('username match key behandelt TIMF en KVT\\TIMF als gelijk', static function (): void {
    mithra_assert_same('timf', mithra_username_match_key('TIMF'));
    mithra_assert_same('timf', mithra_username_match_key('KVT\\TIMF'));
    mithra_assert_true(mithra_usernames_match('TIMF', 'KVT\\TIMF'));
});

mithra_test('hidden usernames dedupliceren op genormaliseerde match key', static function () use ($webDir): void {
    require_once $webDir . '/mithra_preferences.php';

    mithra_assert_same(
        ['TIMF'],
        mithra_normalize_hidden_usernames(['TIMF', 'KVT\\TIMF'])
    );
});
