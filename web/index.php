<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/logincheck.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/mithra_config.php';
require_once __DIR__ . '/mithra_bc.php';
require_once __DIR__ . '/mithra_scan_store.php';
require_once __DIR__ . '/mithra_scan_sync.php';
require_once __DIR__ . '/mithra_stats.php';
require_once __DIR__ . '/mithra_preferences.php';

/**
 * Functies
 */
function mithra_action_is(string $expected): bool
{
    return (string) ($_GET['action'] ?? '') === $expected;
}

function mithra_selected_company(array $companies): string
{
    return mithra_get_saved_company($companies);
}

function mithra_send_json(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mithra_runtime_error_payload(Throwable $error, string $message, int $statusCode = 500): void
{
    mithra_send_json([
        'ok' => false,
        'error' => $message,
        'details' => $error->getMessage(),
    ], $statusCode);
}

function mithra_validate_company(string $company, array $companies): void
{
    if ($company === '' || !in_array($company, $companies, true)) {
        mithra_send_json(['ok' => false, 'error' => 'Kies een geldig bedrijf.'], 400);
    }
}

/**
 * Page load
 */
$companies = mithra_discover_companies();
$selectedCompany = mithra_selected_company($companies);
$cacheWidget = injectTimerHtml([
    'title' => 'OData cache',
    'label' => 'Cache',
    'css' => <<<'CSS'
{{root}} {
	position: relative;
	display: block;
	margin-top: 12px;
}

{{root}} .odata-cache-widget {
	position: static;
	margin-left: auto;
}

{{root}} .odata-cache-popout {
	left: 0;
	right: 0;
	width: min(760px, calc(100vw - 32px));
	margin-top: 10px;
}
CSS,
]);

if (mithra_action_is('save_hidden')) {
    $company = trim((string) ($_POST['company'] ?? $_GET['company'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? $_GET['username'] ?? ''));
    $hiddenRaw = trim((string) ($_POST['hidden'] ?? $_GET['hidden'] ?? '1'));
    mithra_validate_company($company, $companies);

    try {
        $hidden = in_array(strtolower($hiddenRaw), ['1', 'true', 'yes', 'on'], true);
        mithra_send_json([
            'ok' => true,
            'company' => $company,
            'username' => $username,
            'hidden' => $hidden,
            'hidden_usernames' => mithra_set_user_hidden($company, $username, $hidden),
        ]);
    } catch (Throwable $error) {
        mithra_runtime_error_payload($error, 'Zichtbaarheid opslaan mislukt.');
    }
}

if (mithra_action_is('save_company')) {
    $company = trim((string) ($_POST['company'] ?? $_GET['company'] ?? ''));
    mithra_validate_company($company, $companies);

    try {
        mithra_save_company_preference($company);
        mithra_send_json([
            'ok' => true,
            'company' => $company,
        ]);
    } catch (Throwable $error) {
        mithra_runtime_error_payload($error, 'Bedrijfskeuze opslaan mislukt.');
    }
}

if (mithra_action_is('sync_chunk')) {
    $company = trim((string) ($_POST['company'] ?? $_GET['company'] ?? ''));
    mithra_validate_company($company, $companies);

    try {
        auth_set_current_company_context($company, MITHRA_ODATA_TTL);
        mithra_send_json(mithra_sync_run($company));
    } catch (Throwable $error) {
        mithra_runtime_error_payload($error, 'Synchroniseren van scanregels mislukt.');
    }
}

if (mithra_action_is('overview')) {
    $company = trim((string) ($_POST['company'] ?? $_GET['company'] ?? ''));
    mithra_validate_company($company, $companies);

    try {
        $payload = mithra_overview_payload($company);
        $payload['hidden_usernames'] = mithra_get_hidden_usernames($company);
        mithra_send_json($payload);
    } catch (Throwable $error) {
        mithra_runtime_error_payload($error, 'Overzicht laden mislukt.');
    }
}

if (mithra_action_is('user_detail')) {
    $company = trim((string) ($_POST['company'] ?? $_GET['company'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? $_GET['username'] ?? ''));
    mithra_validate_company($company, $companies);

    try {
        mithra_send_json(mithra_user_detail_payload($company, $username));
    } catch (Throwable $error) {
        mithra_runtime_error_payload($error, 'Gebruikersdetails laden mislukt.', 404);
    }
}
?>
<!doctype html>
<html lang="nl">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="stylesheet" href="brand.css">
    <title>Mithra — Scanactiviteit</title>
    <style>
        :root {
            --bg: var(--kvt-page-bg);
            --panel: #ffffff;
            --text: var(--kvt-text);
            --muted: var(--kvt-muted);
            --line: var(--kvt-line);
            --brand: var(--kvt-main-blue);
            --brand-light: var(--kvt-light-blue);
            --brand-dark: var(--kvt-perkins-blue);
            --shadow: 0 18px 40px rgba(0, 82, 155, 0.12);
            --heat-empty: #ebedf0;
            --heat-future: #f6f7f9;
            --heat-today-border:rgb(230, 152, 152);
            --heat-over:rgb(255, 218, 71);
        }

        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
            background-color: var(--bg);
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background-color: var(--bg);
            background-image:
                radial-gradient(900px 500px at -10% -10%, rgba(51, 204, 255, 0.18), transparent 55%),
                radial-gradient(900px 500px at 110% 0%, rgba(0, 153, 204, 0.14), transparent 50%);
            background-repeat: no-repeat;
            background-attachment: fixed;
        }

        .page {
            max-width: 1280px;
            margin: 0 auto;
            padding: 16px;
        }

        .hero {
            position: relative;
            overflow: hidden;
            padding: 18px;
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(0, 82, 155, 0.98), rgba(0, 153, 204, 0.96));
            color: #fff;
            box-shadow: 0 20px 40px rgba(0, 82, 155, 0.24);
        }

        .hero-grid {
            display: grid;
            gap: 16px;
        }

        .hero-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .hero-logo {
            width: min(240px, 54vw);
            height: auto;
            display: block;
        }

        .hero-title {
            margin: 0;
            font-family: "Montserrat", sans-serif;
            font-weight: 700;
            font-size: clamp(1.4rem, 3vw, 2rem);
        }

        .hero-sub {
            margin: 4px 0 0;
            opacity: 0.92;
            font-size: 0.95rem;
        }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
            margin-top: 16px;
        }

        .toolbar-left {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        label.field-label {
            font-size: 0.85rem;
            color: var(--muted);
        }

        select,
        button {
            font: inherit;
        }

        .select-company {
            min-width: min(280px, 100%);
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--line);
            background: #fff;
        }

        .btn {
            border: 0;
            border-radius: 10px;
            padding: 10px 14px;
            cursor: pointer;
            background: var(--brand);
            color: #fff;
            font-weight: 700;
        }

        .btn:disabled {
            opacity: 0.55;
            cursor: default;
        }

        .status-bar {
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 12px;
            background: var(--panel);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }

        .status-text {
            font-size: 0.92rem;
            color: var(--muted);
        }

        .status-text strong {
            color: var(--text);
        }

        .error-banner {
            margin-top: 12px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #fff1f1;
            border: 1px solid #f0bcbc;
            color: #8f1f1f;
            display: none;
        }

        .user-grid {
            margin-top: 18px;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 14px;
            overflow: visible;
        }

        .user-card {
            position: relative;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px 42px 14px 14px;
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: box-shadow 140ms ease;
        }

        .user-card:hover {
            box-shadow: 0 22px 44px rgba(0, 82, 155, 0.16);
        }

        .user-card-visibility {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 30px;
            height: 30px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            color: var(--brand-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
            box-shadow: 0 4px 12px rgba(0, 82, 155, 0.08);
        }

        .user-card-visibility:hover {
            border-color: var(--brand);
            color: var(--brand);
        }

        .user-card-visibility svg {
            width: 16px;
            height: 16px;
            display: block;
        }

        .hidden-users-section {
            margin-top: 22px;
        }

        .hidden-users-section[hidden] {
            display: none !important;
        }

        .hidden-users-divider {
            border: 0;
            border-top: 1px solid var(--line);
            margin: 0 0 16px;
        }

        .hidden-users-panel {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px 16px;
            box-shadow: var(--shadow);
        }

        .hidden-users-title {
            margin: 0 0 10px;
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--brand-dark);
        }

        .hidden-users-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 8px;
        }

        .hidden-users-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 10px;
            background: #f7fbff;
        }

        .hidden-users-name {
            font-size: 0.9rem;
            color: var(--text);
            word-break: break-word;
        }

        .hidden-users-toggle {
            width: 30px;
            height: 30px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: #fff;
            color: var(--muted);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            padding: 0;
            flex: 0 0 auto;
        }

        .hidden-users-toggle:hover {
            border-color: var(--brand);
            color: var(--brand);
        }

        .hidden-users-toggle svg {
            width: 16px;
            height: 16px;
            display: block;
        }

        .user-card-name {
            margin: 0 0 8px;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--brand-dark);
            word-break: break-word;
        }

        .user-card-body {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .user-card-stats {
            flex: 1 1 auto;
            min-width: 0;
            display: grid;
            gap: 6px;
            font-size: 0.72rem;
            color: var(--muted);
            line-height: 1.2;
        }

        .user-card-stat + .user-card-stat {
            margin-top: 0;
        }

        .user-card-stat-label {
            display: block;
        }

        .user-card-stat-value {
            display: block;
            margin-top: 1px;
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--text);
            font-variant-numeric: tabular-nums;
        }

        .heatmap {
            display: grid;
            grid-template-columns: repeat(7, 14px);
            gap: 2px;
            width: fit-content;
        }

        .heat-cell {
            width: 14px;
            height: 14px;
            border-radius: 2px;
            background: var(--heat-empty);
            border: 1px solid rgba(0, 0, 0, 0.04);
        }

        .heat-cell.future {
            background: var(--heat-future);
            border: 1px solid rgba(0, 0, 0, 0.03);
        }

        .heat-cell.today {
            border-color: var(--heat-today-border);
        }

        .heat-cell.level-over.today {
            border: 1px solid var(--heat-today-border);
        }

        .heat-cell.level-1 {
            background: rgba(0, 153, 204, 0.22);
        }

        .heat-cell.level-2 {
            background: rgba(0, 153, 204, 0.42);
        }

        .heat-cell.level-3 {
            background: rgba(0, 153, 204, 0.62);
        }

        .heat-cell.level-4 {
            background: rgba(0, 153, 204, 0.82);
        }

        .heat-cell.level-max {
            background: var(--brand);
        }

        .heat-cell.level-over {
            background: var(--heat-over);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            overflow: hidden;
            border: none;
        }

        .heat-cell-medal {
            pointer-events: none;
            user-select: none;
            display: block;
            font-size: 12px;
            line-height: 1.3;
            margin: 0px;
            transform-origin: center center;
        }

        .empty-state {
            margin-top: 18px;
            padding: 28px;
            text-align: center;
            border-radius: 16px;
            background: var(--panel);
            border: 1px dashed var(--line);
            color: var(--muted);
        }

        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 28, 45, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 16px;
            z-index: 100;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal {
            width: min(1120px, 100%);
            max-height: calc(100vh - 32px);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 28px 60px rgba(0, 0, 0, 0.22);
            padding: 18px;
        }

        .modal-body {
            display: flex;
            align-items: flex-start;
            gap: 18px;
            flex: 1 1 auto;
            min-height: 0;
        }

        .modal-main {
            flex: 1 1 auto;
            min-width: 0;
            max-height: calc(100vh - 120px);
            overflow-y: auto;
            padding-right: 4px;
        }

        .modal-heatmap-panel {
            flex: 0 0 auto;
            position: sticky;
            top: 0;
            align-self: flex-start;
        }

        .modal-heatmap-caption {
            margin: 0 0 8px;
            font-size: 0.78rem;
            color: var(--muted);
            line-height: 1.35;
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .modal-title {
            margin: 0;
            font-size: 1.25rem;
            color: var(--brand-dark);
        }

        .modal-close {
            border: 1px solid var(--line);
            background: #fff;
            border-radius: 8px;
            width: 34px;
            height: 34px;
            cursor: pointer;
            font-size: 1rem;
        }

        .stats-section {
            margin-bottom: 16px;
            border: 1px solid var(--line);
            border-radius: 12px;
            overflow: hidden;
        }

        .stats-section-head {
            padding: 10px 12px;
            background: #f7fbff;
            border-bottom: 1px solid var(--line);
            font-weight: 700;
            color: var(--brand-dark);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 0;
        }

        .stat-item {
            padding: 10px 12px;
            border-right: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
        }

        .stat-label {
            display: block;
            font-size: 0.78rem;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .stat-value {
            font-size: 1.05rem;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .chart-wrap {
            margin-top: 8px;
            padding-top: 12px;
            border-top: 1px solid var(--line);
        }

        .chart-title {
            margin: 0 0 10px;
            font-size: 0.95rem;
            color: var(--brand-dark);
        }

        .chart-bars {
            display: grid;
            grid-template-columns: repeat(28, minmax(0, 1fr));
            gap: 3px;
            align-items: end;
            height: 120px;
        }

        .chart-bar {
            background: linear-gradient(180deg, var(--brand-light), var(--brand));
            border-radius: 3px 3px 0 0;
            min-height: 2px;
        }

        .chart-axis {
            margin-top: 6px;
            display: flex;
            justify-content: space-between;
            font-size: 0.72rem;
            color: var(--muted);
        }

        @media (max-width: 900px) {
            .modal-body {
                flex-direction: column;
            }

            .modal-heatmap-panel {
                position: static;
                width: 100%;
            }
        }

        @media (max-width: 720px) {
            .chart-bars {
                height: 90px;
            }
        }
    </style>
</head>

<body>
    <div class="page">
        <header class="hero">
            <div class="hero-grid">
                <div class="hero-brand">
                    <img class="hero-logo" src="logo-website.png" alt="KVT logo">
                    <div>
                        <h1 class="hero-title">Mithra</h1>
                        <p class="hero-sub">Scanactiviteit per scanner-gebruiker — afgelopen 28 dagen</p>
                    </div>
                </div>
                <?= $cacheWidget ?>
            </div>
        </header>

        <div class="toolbar">
            <div class="toolbar-left">
                <label class="field-label" for="companySelect">Bedrijf</label>
                <select id="companySelect" class="select-company">
                    <?php foreach ($companies as $company): ?>
                        <option value="<?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?>" <?= $company === $selectedCompany ? 'selected' : '' ?>>
                            <?= htmlspecialchars($company, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>

            </div>
        </div>

        <div class="status-bar" id="statusBar">
            <div class="status-text" id="statusText">Scanregels worden geladen…</div>
        </div>

        <div class="error-banner" id="errorBanner"></div>

        <div class="user-grid" id="userGrid"></div>
        <section class="hidden-users-section" id="hiddenUsersSection" hidden>
            <hr class="hidden-users-divider">
            <div class="hidden-users-panel">
                <h3 class="hidden-users-title">Verborgen:</h3>
                <ul class="hidden-users-list" id="hiddenUsersList"></ul>
            </div>
        </section>
        <div class="empty-state" id="emptyState" hidden>Nog geen scanregels gevonden. Start synchronisatie om data op te halen uit Business Central.</div>
    </div>

    <div class="modal-backdrop" id="userModal" aria-hidden="true">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
            <div class="modal-head">
                <h2 class="modal-title" id="modalTitle">Gebruiker</h2>
                <button type="button" class="modal-close" id="modalClose" aria-label="Sluiten">✕</button>
            </div>
            <div id="modalContent"></div>
        </div>
    </div>

    <script>
        (function ()
        {
            const companySelect = document.getElementById('companySelect');
            const statusText = document.getElementById('statusText');
            const errorBanner = document.getElementById('errorBanner');
            const userGrid = document.getElementById('userGrid');
            const hiddenUsersSection = document.getElementById('hiddenUsersSection');
            const hiddenUsersList = document.getElementById('hiddenUsersList');
            const emptyState = document.getElementById('emptyState');
            const userModal = document.getElementById('userModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalContent = document.getElementById('modalContent');
            const modalClose = document.getElementById('modalClose');

            let heatmapIntensityMax = <?= (int) MITHRA_HEATMAP_INTENSITY_MAX ?>;
            let isSyncing = false;
            let overviewUsers = [];
            let hiddenUsernames = new Set();
            let visibilityAnimating = false;

            const eyeOpenSvg = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 5C7 5 2.73 8.11 1 12c1.73 3.89 6 7 11 7s9.27-3.11 11-7c-1.73-3.89-6-7-11-7zm0 11a4 4 0 1 1 0-8 4 4 0 0 1 0 8z"/></svg>';
            const eyeClosedSvg = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 6.5c2.76 0 5.26 1.12 7.08 2.92L17.5 11l1.41 1.41 2.59-2.58C23.27 7.89 19 4.5 12 4.5c-1.4 0-2.68.2-3.85.54l1.53 1.53C10.4 6.53 11.17 6.5 12 6.5zM2.27 3.77 1 5.04l2.05 2.05C2.73 8.11 1 11 1 12c1.73 3.89 6 7 11 7 1.77 0 3.43-.4 4.92-1.09l2.2 2.2 1.27-1.27L2.27 3.77zM7.53 9.8 9.16 11.4C9.06 11.59 9 11.79 9 12a3 3 0 0 0 3 3c.21 0 .41-.06.6-.16l1.6 1.6A4.98 4.98 0 0 1 12 17a5 5 0 0 1-5-5c0-.79.19-1.53.53-2.2z"/></svg>';

            function usernameKey(value)
            {
                return String(value || '').trim().toLowerCase();
            }

            function isUserHidden(username)
            {
                return hiddenUsernames.has(usernameKey(username));
            }

            function setHiddenUsernames(list)
            {
                hiddenUsernames = new Set();
                for (const name of Array.isArray(list) ? list : [])
                {
                    const key = usernameKey(name);
                    if (key !== '')
                    {
                        hiddenUsernames.add(key);
                    }
                }
            }

            function findOverviewUser(username)
            {
                const key = usernameKey(username);
                for (const user of overviewUsers)
                {
                    if (usernameKey(user.username) === key)
                    {
                        return user;
                    }
                }

                return null;
            }

            function findUserCardElement(username)
            {
                for (const candidate of userGrid.querySelectorAll('.user-card'))
                {
                    if (String(candidate.dataset.username || '') === username)
                    {
                        return candidate;
                    }
                }

                return null;
            }

            function fadeOutCard(card)
            {
                card.style.pointerEvents = 'none';
                return card.animate([
                    { opacity: 1 },
                    { opacity: 0 }
                ], {
                    duration: 220,
                    easing: 'ease-out',
                    fill: 'forwards'
                }).finished;
            }

            function createVisibilityButton(isHidden, label)
            {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = isHidden ? 'hidden-users-toggle' : 'user-card-visibility';
                button.setAttribute('aria-label', label);
                button.innerHTML = isHidden ? eyeClosedSvg : eyeOpenSvg;
                return button;
            }

            function bindUserCard(card, user)
            {
                const username = String(user.username || '');
                card.dataset.username = username;

                card.addEventListener('click', function ()
                {
                    openUserModal(username);
                });

                const visibilityButton = createVisibilityButton(false, 'Gebruiker verbergen');
                visibilityButton.addEventListener('click', function (event)
                {
                    event.preventDefault();
                    event.stopPropagation();
                    hideUserCard(username);
                });
                card.appendChild(visibilityButton);
            }

            function buildUserCardElement(user)
            {
                const days = Array.isArray(user.days) ? user.days : [];
                const card = document.createElement('article');
                card.className = 'user-card';
                card.innerHTML = '<h3 class="user-card-name">' + escapeHtml(user.username || '') + '</h3>'
                    + '<div class="user-card-body">'
                    + renderHeatmap(days)
                    + renderUserCardStats(days)
                    + '</div>';
                bindUserCard(card, user);
                return card;
            }

            function renderHiddenUsersList()
            {
                hiddenUsersList.innerHTML = '';
                const hiddenNames = overviewUsers
                    .map(function (user) { return String(user.username || ''); })
                    .filter(function (username) { return isUserHidden(username); })
                    .sort(function (a, b) { return a.localeCompare(b, 'nl', { sensitivity: 'base' }); });

                if (hiddenNames.length === 0)
                {
                    hiddenUsersSection.hidden = true;
                    return;
                }

                hiddenUsersSection.hidden = false;
                for (const username of hiddenNames)
                {
                    const item = document.createElement('li');
                    item.className = 'hidden-users-item';

                    const name = document.createElement('span');
                    name.className = 'hidden-users-name';
                    name.textContent = username;

                    const button = createVisibilityButton(true, 'Gebruiker tonen');
                    button.addEventListener('click', function (event)
                    {
                        event.preventDefault();
                        showUserCard(username);
                    });

                    item.appendChild(name);
                    item.appendChild(button);
                    hiddenUsersList.appendChild(item);
                }
            }

            function renderVisibleUserGrid(options)
            {
                options = options || {};
                const skipHiddenList = !!options.skipHiddenList;

                userGrid.innerHTML = '';

                for (const user of overviewUsers)
                {
                    const username = String(user.username || '');
                    if (isUserHidden(username))
                    {
                        continue;
                    }

                    userGrid.appendChild(buildUserCardElement(user));
                }

                if (!skipHiddenList)
                {
                    renderHiddenUsersList();
                }
            }

            async function persistHiddenState(username, hidden)
            {
                const response = await fetch('index.php?action=save_hidden', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: new URLSearchParams({
                        company: selectedCompany(),
                        username: username,
                        hidden: hidden ? '1' : '0'
                    })
                });

                const payload = await response.json();
                if (!payload || !payload.ok)
                {
                    throw new Error((payload && payload.error) || 'Zichtbaarheid opslaan mislukt.');
                }

                setHiddenUsernames(payload.hidden_usernames || []);
                return payload;
            }

            async function hideUserCard(username)
            {
                if (visibilityAnimating || isUserHidden(username))
                {
                    return;
                }

                const card = findUserCardElement(username);
                if (!card)
                {
                    return;
                }

                visibilityAnimating = true;
                const previousHidden = new Set(hiddenUsernames);

                try
                {
                    await fadeOutCard(card);
                    hiddenUsernames.add(usernameKey(username));
                    renderVisibleUserGrid();
                    await persistHiddenState(username, true);
                }
                catch (error)
                {
                    hiddenUsernames = previousHidden;
                    showError(error.message || 'Verbergen mislukt.');
                    renderVisibleUserGrid();
                }
                finally
                {
                    visibilityAnimating = false;
                }
            }

            async function showUserCard(username)
            {
                if (visibilityAnimating || !isUserHidden(username) || !findOverviewUser(username))
                {
                    return;
                }

                visibilityAnimating = true;
                const previousHidden = new Set(hiddenUsernames);

                try
                {
                    await persistHiddenState(username, false);
                    renderVisibleUserGrid();
                }
                catch (error)
                {
                    hiddenUsernames = previousHidden;
                    showError(error.message || 'Tonen mislukt.');
                    renderVisibleUserGrid();
                }
                finally
                {
                    visibilityAnimating = false;
                }
            }

            function renderOverview(payload)
            {
                heatmapIntensityMax = Number(payload.heatmap_intensity_max || heatmapIntensityMax);
                overviewUsers = Array.isArray(payload.users) ? payload.users : [];
                setHiddenUsernames(payload.hidden_usernames || []);

                if (overviewUsers.length === 0)
                {
                    userGrid.innerHTML = '';
                    hiddenUsersSection.hidden = true;
                    emptyState.hidden = false;
                    return;
                }

                emptyState.hidden = true;
                renderVisibleUserGrid();
            }

            function selectedCompany()
            {
                return String(companySelect.value || '').trim();
            }

            function showError(message)
            {
                errorBanner.textContent = message;
                errorBanner.style.display = message ? 'block' : 'none';
            }

            function formatDutchDate(dateText)
            {
                const parts = String(dateText || '').split('-');
                if (parts.length !== 3)
                {
                    return dateText;
                }

                const date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
                return date.toLocaleDateString('nl-NL', {
                    day: 'numeric',
                    month: 'long',
                    year: 'numeric'
                });
            }

            function todayDateKey()
            {
                const now = new Date();
                const year = now.getFullYear();
                const month = String(now.getMonth() + 1).padStart(2, '0');
                const day = String(now.getDate()).padStart(2, '0');
                return year + '-' + month + '-' + day;
            }

            function heatLevel(count, max)
            {
                if (count <= 0)
                {
                    return '';
                }

                if (count > max)
                {
                    return 'level-over';
                }

                if (count >= max)
                {
                    return 'level-max';
                }

                if (count >= Math.ceil(max * 0.75))
                {
                    return 'level-4';
                }

                if (count >= Math.ceil(max * 0.5))
                {
                    return 'level-3';
                }

                if (count >= Math.ceil(max * 0.25))
                {
                    return 'level-2';
                }

                return 'level-1';
            }

            function computeUserCardStats(days)
            {
                const list = Array.isArray(days) ? days : [];
                let total = 0;
                let elapsedDays = 0;
                for (const day of list)
                {
                    if (day.future)
                    {
                        continue;
                    }

                    elapsedDays++;
                    total += Number(day.count || 0);
                }

                const avg = elapsedDays > 0 ? total / elapsedDays : 0;

                return {
                    total: total,
                    avg: Math.round(avg)
                };
            }

            function renderUserCardStats(days)
            {
                const stats = computeUserCardStats(days);
                return '<div class="user-card-stats">'
                    + '<div class="user-card-stat">'
                    + '<span class="user-card-stat-label">Activiteit 28 dagen:</span>'
                    + '<span class="user-card-stat-value">' + escapeHtml(stats.total.toLocaleString('nl-NL')) + '</span>'
                    + '</div>'
                    + '<div class="user-card-stat">'
                    + '<span class="user-card-stat-label">Gemiddelde per dag:</span>'
                    + '<span class="user-card-stat-value">' + escapeHtml(stats.avg.toLocaleString('nl-NL')) + '</span>'
                    + '</div>'
                    + '</div>';
            }

            function renderHeatmap(days, options)
            {
                options = options || {};
                const cols = Number(options.cols || <?= (int) MITHRA_HEATMAP_COLS ?>) || <?= (int) MITHRA_HEATMAP_COLS ?>;
                const extraClass = String(options.extraClass || '').trim();
                const todayKey = todayDateKey();
                let html = '<div class="heatmap' + (extraClass !== '' ? (' ' + extraClass) : '') + '" style="grid-template-columns:repeat(' + cols + ', 14px)">';
                for (const day of days)
                {
                    const isToday = String(day.date || '') === todayKey;
                    const todayClass = isToday ? ' today' : '';

                    if (day.future)
                    {
                        const futureTitle = formatDutchDate(day.date) + ' — nog niet bereikt';
                        html += '<div class="heat-cell future' + todayClass + '" title="' + escapeHtml(futureTitle) + '"></div>';
                        continue;
                    }

                    const count = Number(day.count || 0);
                    const level = heatLevel(count, heatmapIntensityMax);
                    const activityLabel = count === 1 ? '1 activiteit' : (count + ' activiteiten');
                    const title = formatDutchDate(day.date) + ' — ' + activityLabel;
                    const medal = level === 'level-over' ? '<span class="heat-cell-medal" aria-hidden="true">⭐</span>' : '';
                    html += '<div class="heat-cell ' + level + todayClass + '" title="' + escapeHtml(title) + '">' + medal + '</div>';
                }
                html += '</div>';
                return html;
            }

            function renderModalHeatmapPanel(payload)
            {
                const days = Array.isArray(payload.heatmap_days) ? payload.heatmap_days : [];
                const rows = Number(payload.heatmap_rows || <?= (int) MITHRA_MODAL_HEATMAP_ROWS ?>);
                const cols = Number(payload.heatmap_cols || <?= (int) MITHRA_HEATMAP_COLS ?>);
                return '<aside class="modal-heatmap-panel">'
                    + renderHeatmap(days, { cols: cols })
                    + '</aside>';
            }

            function escapeHtml(value)
            {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function formatDualStatValue(label, stats)
            {
                const avgLabels = ['Gem. per dag', 'Gem. per week', 'Gem. per maand'];
                const metricKey = label === 'Deze week'
                    ? 'week'
                    : (label === 'Deze maand'
                        ? 'month'
                        : (label === 'Totaal'
                            ? 'total'
                            : (label === 'Gem. per dag'
                                ? 'avg_day'
                                : (label === 'Gem. per week' ? 'avg_week' : 'avg_month'))));

                const metric = stats[metricKey] || {};
                const scans = avgLabels.indexOf(label) !== -1
                    ? Math.round(Number(metric.scans || 0))
                    : Number(metric.scans || 0);
                const handelingen = avgLabels.indexOf(label) !== -1
                    ? Math.round(Number(metric.handelingen || 0))
                    : Number(metric.handelingen || 0);

                return scans.toLocaleString('nl-NL') + ' scans, ' + handelingen.toLocaleString('nl-NL') + ' handelingen';
            }

            function renderStatsBlock(title, stats)
            {
                const items = [
                    'Deze week',
                    'Deze maand',
                    'Totaal',
                    'Gem. per dag',
                    'Gem. per week',
                    'Gem. per maand'
                ];

                let html = '<section class="stats-section">';
                html += '<div class="stats-section-head">' + escapeHtml(title) + '</div>';
                html += '<div class="stats-grid">';
                for (const label of items)
                {
                    html += '<div class="stat-item"><span class="stat-label">' + escapeHtml(label) + '</span><span class="stat-value">' + escapeHtml(formatDualStatValue(label, stats || {})) + '</span></div>';
                }
                html += '</div></section>';
                return html;
            }

            function renderChart(chartDays, title, unitLabel)
            {
                const days = Array.isArray(chartDays) ? chartDays : [];
                const maxCount = days.reduce(function (max, day)
                {
                    return Math.max(max, Number(day.count || 0));
                }, 0);

                let bars = '';
                for (const day of days)
                {
                    const count = Number(day.count || 0);
                    const height = maxCount > 0 ? Math.max(2, Math.round((count / maxCount) * 100)) : 2;
                    const titleText = formatDutchDate(day.date) + ' — ' + count + ' ' + unitLabel;
                    bars += '<div class="chart-bar" style="height:' + height + '%" title="' + escapeHtml(titleText) + '"></div>';
                }

                const firstLabel = days.length > 0 ? formatDutchDate(days[0].date) : '';
                const lastLabel = days.length > 0 ? formatDutchDate(days[days.length - 1].date) : '';

                return '<div class="chart-wrap">'
                    + '<h3 class="chart-title">' + escapeHtml(title) + '</h3>'
                    + '<div class="chart-bars">' + bars + '</div>'
                    + '<div class="chart-axis"><span>' + escapeHtml(firstLabel) + '</span><span>' + escapeHtml(lastLabel) + '</span></div>'
                    + '</div>';
            }

            function openUserModal(username)
            {
                const company = selectedCompany();
                fetch('index.php?action=user_detail', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: new URLSearchParams({
                        company: company,
                        username: username
                    })
                })
                    .then(function (response)
                    {
                        return response.json();
                    })
                    .then(function (payload)
                    {
                        if (!payload || !payload.ok)
                        {
                            throw new Error((payload && payload.error) || 'Details laden mislukt.');
                        }

                        modalTitle.textContent = payload.username || username;
                        heatmapIntensityMax = Number(payload.heatmap_intensity_max || heatmapIntensityMax);
                        let mainHtml = renderStatsBlock('Totaal', payload.overall || {});
                        const processes = Array.isArray(payload.by_process) ? payload.by_process : [];
                        for (const block of processes)
                        {
                            mainHtml += renderStatsBlock(String(block.scan_process || 'Scanproces'), block.stats || {});
                        }
                        mainHtml += renderChart(payload.chart_30_days_scans || [], 'Scans afgelopen 28 dagen', 'scans');
                        const entryTypes = Array.isArray(payload.by_entry_type) ? payload.by_entry_type : [];
                        for (const block of entryTypes)
                        {
                            mainHtml += renderStatsBlock(String(block.entry_type || 'Magazijnhandeling'), block.stats || {});
                        }
                        mainHtml += renderChart(payload.chart_30_days_wh || [], 'Magazijnhandelingen afgelopen 28 dagen', 'handelingen');
                        modalContent.innerHTML = '<div class="modal-body">'
                            + '<div class="modal-main">' + mainHtml + '</div>'
                            + renderModalHeatmapPanel(payload)
                            + '</div>';
                        userModal.classList.add('open');
                        userModal.setAttribute('aria-hidden', 'false');
                    })
                    .catch(function (error)
                    {
                        showError(error.message || 'Gebruikersdetails laden mislukt.');
                    });
            }

            function closeModal()
            {
                userModal.classList.remove('open');
                userModal.setAttribute('aria-hidden', 'true');
            }

            async function fetchOverview()
            {
                const company = selectedCompany();
                const response = await fetch('index.php?action=overview', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: new URLSearchParams({ company: company })
                });

                const payload = await response.json();
                if (!payload || !payload.ok)
                {
                    throw new Error((payload && payload.error) || 'Overzicht laden mislukt.');
                }

                renderOverview(payload);
                return payload;
            }

            async function saveCompanyPreference(company)
            {
                await fetch('index.php?action=save_company', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    body: new URLSearchParams({ company: company })
                });
            }

            function formatForwardSyncStatus(payload)
            {
                const inserted = Number(payload.inserted_count || 0);
                const fetched = Number(payload.fetched_count || 0);

                if (inserted > 0)
                {
                    return fetched + ' opgehaald, ' + inserted + ' nieuwe scans opgeslagen.';
                }

                return 'Geen nieuwe scans sinds laatste synchronisatie.';
            }

            function formatBackfillChunkStatus(payload, chunkCurrent, chunkTotal)
            {
                const inserted = Number(payload.inserted_count || 0);
                const fetched = Number(payload.fetched_count || 0);

                return 'Chunk <strong>' + chunkCurrent + ' van ' + chunkTotal + '</strong> ('
                    + escapeHtml(String(payload.mode || '')) + ', '
                    + escapeHtml(String(payload.from_date || '')) + ' t/m ' + escapeHtml(String(payload.to_date || '')) + '): '
                    + fetched + ' opgehaald, ' + inserted + ' nieuw opgeslagen.';
            }

            async function syncChunks()
            {
                if (isSyncing)
                {
                    return;
                }

                isSyncing = true;
                showError('');

                const company = selectedCompany();
                let chunkCount = 0;
                let chunkTotalEstimate = null;

                try
                {
                    while (true)
                    {
                        if (chunkCount > 0)
                        {
                            const chunkDisplay = chunkCount + 1;
                            const totalDisplay = chunkTotalEstimate !== null
                                ? String(chunkTotalEstimate)
                                : '…';
                            statusText.innerHTML = '<strong>Backfill…</strong> chunk ' + chunkDisplay + ' van ' + totalDisplay + ' voor ' + escapeHtml(company);
                        }
                        else
                        {
                            statusText.textContent = 'Synchroniseren voor ' + company + '…';
                        }

                        const response = await fetch('index.php?action=sync_chunk', {
                            method: 'POST',
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin',
                            body: new URLSearchParams({ company: company })
                        });

                        const payload = await response.json();
                        if (!payload || !payload.ok)
                        {
                            throw new Error((payload && payload.error) || 'Synchroniseren mislukt.');
                        }

                        if (payload.sync_phase === 'forward')
                        {
                            statusText.textContent = formatForwardSyncStatus(payload);
                            await fetchOverview();
                            break;
                        }

                        chunkCount++;
                        chunkTotalEstimate = Number(payload.chunk_total || chunkTotalEstimate || chunkCount);
                        const chunkCurrent = Number(payload.chunk_current || chunkCount);
                        const chunkTotal = Number(payload.chunk_total || chunkTotalEstimate);
                        statusText.innerHTML = formatBackfillChunkStatus(payload, chunkCurrent, chunkTotal);

                        await fetchOverview();

                        if (!payload.needs_more_chunks)
                        {
                            statusText.innerHTML = 'Gegevens geladen.';
                            break;
                        }

                        await new Promise(function (resolve)
                        {
                            setTimeout(resolve, 120);
                        });
                    }
                }
                catch (error)
                {
                    showError(error.message || 'Synchroniseren mislukt.');
                    statusText.textContent = 'Synchronisatie onderbroken.';
                }
                finally
                {
                    isSyncing = false;
                }
            }

            companySelect.addEventListener('change', async function ()
            {
                const company = selectedCompany();
                try
                {
                    await saveCompanyPreference(company);
                    const params = new URLSearchParams(window.location.search);
                    params.set('company', company);
                    window.history.replaceState({}, '', 'index.php?' + params.toString());
                    await fetchOverview();
                    syncChunks();
                }
                catch (error)
                {
                    showError(error.message || 'Bedrijf wisselen mislukt.');
                }
            });

            modalClose.addEventListener('click', closeModal);
            userModal.addEventListener('click', function (event)
            {
                if (event.target === userModal)
                {
                    closeModal();
                }
            });

            document.addEventListener('keydown', function (event)
            {
                if (event.key === 'Escape')
                {
                    closeModal();
                }
            });

            fetchOverview()
                .then(function ()
                {
                    statusText.textContent = 'Overzicht geladen. Synchronisatie loopt op de achtergrond.';
                    syncChunks();
                })
                .catch(function (error)
                {
                    showError(error.message || 'Laden mislukt.');
                    statusText.textContent = 'Overzicht kon niet geladen worden.';
                });
        })();
    </script>
</body>

</html>
