<?php

/**
 * Minimale testhelper voor Mithra (geen externe testframeworks).
 */
function mithra_test_reset(): void
{
    $GLOBALS['mithra_test_results'] = [
        'passed' => 0,
        'failed' => 0,
        'errors' => [],
    ];
}

function mithra_test_results(): array
{
    return is_array($GLOBALS['mithra_test_results'] ?? null)
        ? $GLOBALS['mithra_test_results']
        : ['passed' => 0, 'failed' => 0, 'errors' => []];
}

function mithra_test(string $name, callable $callback): void
{
    try {
        $callback();
        $GLOBALS['mithra_test_results']['passed']++;
        echo "  OK   {$name}\n";
    } catch (Throwable $error) {
        $GLOBALS['mithra_test_results']['failed']++;
        $message = $name . ': ' . $error->getMessage();
        $GLOBALS['mithra_test_results']['errors'][] = $message;
        echo "  FAIL {$message}\n";
    }
}

function mithra_assert_true(bool $condition, string $message = 'Expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mithra_assert_false(bool $condition, string $message = 'Expected false'): void
{
    if ($condition) {
        throw new RuntimeException($message);
    }
}

function mithra_assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = $message !== '' ? ($message . ' — ') : '';
        throw new RuntimeException($detail . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function mithra_assert_count(int $expected, array $value, string $message = ''): void
{
    $actual = count($value);
    if ($actual !== $expected) {
        $detail = $message !== '' ? ($message . ' — ') : '';
        throw new RuntimeException($detail . "Expected count {$expected}, got {$actual}");
    }
}

function mithra_assert_greater_than(int $minimum, int $actual, string $message = ''): void
{
    if ($actual <= $minimum) {
        $detail = $message !== '' ? ($message . ' — ') : '';
        throw new RuntimeException($detail . "Expected value > {$minimum}, got {$actual}");
    }
}

function mithra_assert_less_or_equal(int $maximum, int $actual, string $message = ''): void
{
    if ($actual > $maximum) {
        $detail = $message !== '' ? ($message . ' — ') : '';
        throw new RuntimeException($detail . "Expected value <= {$maximum}, got {$actual}");
    }
}
