<?php
/**
 * Auth-template voor Mithra. Kopieer naar auth.php (niet in git).
 *
 * Mímir eerst, en houd het BC-blok als automatische fallback als Mímir eruit ligt:
 *   $mimirApi  = 'mimir_…';  // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *
 * Met $mimirApi gezet gaan fetches eerst naar Mímir en vallen terug op de BC-variabelen hieronder.
 * Zonder $mimirApi wordt alleen het BC-blok gebruikt.
 * De BC-gegevens ($baseUrl, $auth / $auth_list, $environment) moeten naast $mimirApi blijven staan.
 */

// --- Mímir ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (direct pad, én fallback als Mímir faalt) ---
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
];
$environment = 'Production';
$auth = $auth_list[$environment];
$baseUrl = 'https://my-bc-domain.com:7148/';

$allowedUsers = [
    'user@domain.nl',
];
