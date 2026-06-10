<?php

$webDir = dirname(__DIR__) . '/web';
require_once $webDir . '/mithra_preferences.php';

mithra_test('hidden usernames worden genormaliseerd en uniek gesorteerd', static function (): void {
    mithra_assert_same(
        ['alice', 'Bob'],
        mithra_normalize_hidden_usernames(['alice', 'Bob', 'alice', ''])
    );
});

mithra_test('hidden usernames zijn per ingelogde user en bedrijf', static function (): void {
    mithra_ensure_session();
    $_SESSION = [
        'user' => ['email' => 'test@kvt.nl'],
        'mithra_hidden_users' => [],
    ];

    mithra_assert_same(['alice'], mithra_set_user_hidden('Koninklijke van Twist', 'alice', true));
    mithra_assert_same(['alice', 'bob'], mithra_set_user_hidden('Koninklijke van Twist', 'bob', true));
    mithra_assert_same(['bob'], mithra_set_user_hidden('Koninklijke van Twist', 'alice', false));
    mithra_assert_same([], mithra_get_hidden_usernames('Ander bedrijf'));
});
