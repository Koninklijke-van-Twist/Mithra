<?php

$webDir = dirname(__DIR__) . '/web';
require_once $webDir . '/mithra_config.php';
require_once $webDir . '/mithra_bc.php';
require_once $webDir . '/mithra_heatmap.php';
require_once $webDir . '/mithra_heatmap_image.php';

mithra_test('activity level volgt dezelfde drempels als de UI', static function (): void {
    mithra_assert_same('', mithra_heatmap_activity_level(0, 100));
    mithra_assert_same('level-1', mithra_heatmap_activity_level(1, 100));
    mithra_assert_same('level-2', mithra_heatmap_activity_level(25, 100));
    mithra_assert_same('level-3', mithra_heatmap_activity_level(50, 100));
    mithra_assert_same('level-4', mithra_heatmap_activity_level(75, 100));
    mithra_assert_same('level-max', mithra_heatmap_activity_level(100, 100));
    mithra_assert_same('level-over', mithra_heatmap_activity_level(101, 100));
});

mithra_test('kaart-PNG bron is 7x4 pixels, weergave 110x62', static function (): void {
    $source = mithra_heatmap_card_png_source_dimensions();
    $display = mithra_heatmap_card_display_dimensions();
    mithra_assert_same(7, $source['width']);
    mithra_assert_same(4, $source['height']);
    mithra_assert_same(110, $display['width']);
    mithra_assert_same(62, $display['height']);
    mithra_assert_same(28, mithra_heatmap_card_cell_count());
});

mithra_test('heatmap counts parser accepteert 28 waarden', static function (): void {
    $values = array_fill(0, 28, 3);
    $values[0] = 5;
    $values[1] = 2;
    $values[2] = -1;
    $days = mithra_heatmap_parse_counts_param(implode(',', $values));
    mithra_assert_count(28, $days);
    mithra_assert_same(5, (int) $days[0]['count']);
    mithra_assert_true((bool) $days[2]['future']);
});

mithra_test('heatmap etag wijzigt bij andere telling', static function (): void {
    $days = mithra_heatmap_build_grid_days(['2026-06-01' => 2], '2026-06-03', 4, 7);
    $etagA = mithra_heatmap_png_etag($days, 100);
    $etagB = mithra_heatmap_png_etag($days, 50);
    mithra_assert_true($etagA !== $etagB);
});

mithra_test('heatmap PNG renderer levert geldige 7x4 PNG op', static function (): void {
    if (!mithra_heatmap_png_gd_available()) {
        return;
    }

    $days = mithra_heatmap_build_grid_days(
        ['2026-06-01' => 120, '2026-06-02' => 50],
        '2026-06-03',
        MITHRA_HEATMAP_ROWS,
        MITHRA_HEATMAP_COLS
    );
    $png = mithra_heatmap_render_png($days, '2026-06-03', 100);
    mithra_assert_same("\x89PNG\r\n\x1a\n", substr($png, 0, 8));

    $image = imagecreatefromstring($png);
    mithra_assert_true($image !== false);
    if ($image !== false) {
        mithra_assert_same(7, imagesx($image));
        mithra_assert_same(4, imagesy($image));
        imagedestroy($image);
    }
});

mithra_test('toekomstige dag krijgt lichtgrijze celkleur', static function (): void {
    $rgb = mithra_heatmap_cell_rgb('', true);
    mithra_assert_same([246, 247, 249], $rgb);
});

mithra_test('boven limiet loopt PNG-kleur van geel naar oranje', static function (): void {
    mithra_assert_same([255, 255, 0], mithra_heatmap_limit_highlight_rgb(100, 100));
    mithra_assert_same([255, 136, 0], mithra_heatmap_limit_highlight_rgb(500, 100));
    mithra_assert_same([255, 136, 0], mithra_heatmap_limit_highlight_rgb(700, 100));

    $midOver = mithra_heatmap_limit_highlight_rgb(300, 100);
    mithra_assert_true($midOver[0] === 255);
    mithra_assert_true($midOver[1] > 136 && $midOver[1] < 255);
    mithra_assert_same([0, 153, 204], mithra_heatmap_cell_rgb('level-max', false, 99, 100));
});

mithra_test('heatmap cache max-age loopt tot middernacht', static function (): void {
    $today = date('Y-m-d');
    $maxAge = mithra_heatmap_cache_max_age($today);
    mithra_assert_true($maxAge >= 60);
    mithra_assert_true($maxAge <= 86400);
});
