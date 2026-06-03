<?php

$webDir = dirname(__DIR__) . '/web';
require_once $webDir . '/mithra_config.php';
require_once $webDir . '/mithra_bc.php';
require_once $webDir . '/mithra_heatmap.php';

mithra_test('kaart-grid is 7 kolommen x 4 rijen', static function (): void {
    $days = mithra_heatmap_build_grid_days([], '2026-06-03', MITHRA_HEATMAP_ROWS, MITHRA_HEATMAP_COLS);
    mithra_assert_count(MITHRA_HEATMAP_ROWS * MITHRA_HEATMAP_COLS, $days);
});

mithra_test('weekrijen beginnen op maandag', static function (): void {
    $dates = mithra_heatmap_grid_dates('2026-06-03', 4, 7);
    mithra_assert_count(28, $dates);
    mithra_assert_same('2026-05-11', $dates[0]);
    mithra_assert_same('2026-05-11', mithra_heatmap_monday_of_week($dates[0]));
    mithra_assert_same('2026-06-01', mithra_heatmap_monday_of_week('2026-06-03'));
});

mithra_test('toekomstige dagen in huidige week zijn gemarkeerd', static function (): void {
    $days = mithra_heatmap_build_grid_days([], '2026-06-03', 4, 7);
    $byDate = [];
    foreach ($days as $day) {
        $byDate[$day['date']] = $day;
    }

    mithra_assert_false((bool) ($byDate['2026-06-03']['future'] ?? true));
    mithra_assert_true((bool) ($byDate['2026-06-04']['future'] ?? false));
    mithra_assert_true((bool) ($byDate['2026-06-07']['future'] ?? false));
});

mithra_test('modal-grid gebruikt configureerbare hoogte', static function (): void {
    $days = mithra_heatmap_build_grid_days([], '2026-06-03', MITHRA_MODAL_HEATMAP_ROWS, MITHRA_HEATMAP_COLS);
    mithra_assert_count(MITHRA_MODAL_HEATMAP_ROWS * MITHRA_HEATMAP_COLS, $days);
});

mithra_test('heatmap telt scans per dag', static function (): void {
    $entries = [
        ['scan_timestamp' => '2026-06-01T08:00:00'],
        ['scan_timestamp' => '2026-06-01T09:15:00'],
        ['scan_timestamp' => '2026-06-02T10:00:00'],
    ];
    $counts = mithra_heatmap_counts_from_entries($entries);
    $days = mithra_heatmap_build_grid_days($counts, '2026-06-03', 4, 7);

    $byDate = [];
    foreach ($days as $day) {
        $byDate[$day['date']] = $day;
    }

    mithra_assert_same(2, (int) ($byDate['2026-06-01']['count'] ?? 0));
    mithra_assert_same(1, (int) ($byDate['2026-06-02']['count'] ?? 0));
});
