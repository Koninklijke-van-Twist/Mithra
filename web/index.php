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
        mithra_send_json(mithra_sync_one_chunk($company));
    } catch (Throwable $error) {
        mithra_runtime_error_payload($error, 'Synchroniseren van scanregels mislukt.');
    }
}

if (mithra_action_is('overview')) {
    $company = trim((string) ($_POST['company'] ?? $_GET['company'] ?? ''));
    mithra_validate_company($company, $companies);

    try {
        mithra_send_json(mithra_overview_payload($company));
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

        body {
            margin: 0;
            color: var(--text);
            background:
                radial-gradient(900px 500px at -10% -10%, rgba(51, 204, 255, 0.18), transparent 55%),
                radial-gradient(900px 500px at 110% 0%, rgba(0, 153, 204, 0.14), transparent 50%),
                var(--bg);
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
        }

        .user-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 16px;
            padding: 14px;
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: transform 140ms ease, box-shadow 140ms ease;
        }

        .user-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 44px rgba(0, 82, 155, 0.16);
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
            overflow: auto;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 28px 60px rgba(0, 0, 0, 0.22);
            padding: 18px;
        }

        .modal-body {
            display: flex;
            align-items: flex-start;
            gap: 18px;
        }

        .modal-main {
            flex: 1 1 auto;
            min-width: 0;
        }

        .modal-heatmap-panel {
            flex: 0 0 auto;
            position: sticky;
            top: 0;
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
                <button type="button" class="btn" id="syncBtn">Synchroniseren</button>
            </div>
        </div>

        <div class="status-bar" id="statusBar">
            <div class="status-text" id="statusText">Scanregels worden geladen…</div>
        </div>

        <div class="error-banner" id="errorBanner"></div>

        <div class="user-grid" id="userGrid"></div>
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
            const syncBtn = document.getElementById('syncBtn');
            const statusText = document.getElementById('statusText');
            const errorBanner = document.getElementById('errorBanner');
            const userGrid = document.getElementById('userGrid');
            const emptyState = document.getElementById('emptyState');
            const userModal = document.getElementById('userModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalContent = document.getElementById('modalContent');
            const modalClose = document.getElementById('modalClose');

            let heatmapIntensityMax = <?= (int) MITHRA_HEATMAP_INTENSITY_MAX ?>;
            let isSyncing = false;

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
                    + '<span class="user-card-stat-label">Gescand 28 dagen:</span>'
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
                    const scanLabel = count === 1 ? '1 scan' : (count + ' scans');
                    const title = formatDutchDate(day.date) + ' — ' + scanLabel;
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

            function renderOverview(payload)
            {
                heatmapIntensityMax = Number(payload.heatmap_intensity_max || heatmapIntensityMax);
                const users = Array.isArray(payload.users) ? payload.users : [];
                userGrid.innerHTML = '';

                if (users.length === 0)
                {
                    emptyState.hidden = false;
                    return;
                }

                emptyState.hidden = true;
                for (const user of users)
                {
                    const days = Array.isArray(user.days) ? user.days : [];
                    const card = document.createElement('article');
                    card.className = 'user-card';
                    card.dataset.username = String(user.username || '');
                    card.innerHTML = '<h3 class="user-card-name">' + escapeHtml(user.username || '') + '</h3>'
                        + '<div class="user-card-body">'
                        + renderHeatmap(days)
                        + renderUserCardStats(days)
                        + '</div>';
                    card.addEventListener('click', function ()
                    {
                        openUserModal(user.username);
                    });
                    userGrid.appendChild(card);
                }
            }

            function formatStatValue(label, value)
            {
                const avgLabels = ['Gem. per dag', 'Gem. per week', 'Gem. per maand'];
                if (avgLabels.indexOf(label) !== -1)
                {
                    return Math.round(Number(value || 0)).toLocaleString('nl-NL');
                }

                return String(value ?? '');
            }

            function renderStatsBlock(title, stats)
            {
                const items = [
                    ['Deze week', stats.week],
                    ['Deze maand', stats.month],
                    ['Totaal', stats.total],
                    ['Gem. per dag', stats.avg_day],
                    ['Gem. per week', stats.avg_week],
                    ['Gem. per maand', stats.avg_month]
                ];

                let html = '<section class="stats-section">';
                html += '<div class="stats-section-head">' + escapeHtml(title) + '</div>';
                html += '<div class="stats-grid">';
                for (const item of items)
                {
                    html += '<div class="stat-item"><span class="stat-label">' + escapeHtml(item[0]) + '</span><span class="stat-value">' + escapeHtml(formatStatValue(item[0], item[1])) + '</span></div>';
                }
                html += '</div></section>';
                return html;
            }

            function renderChart(chartDays)
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
                    const title = formatDutchDate(day.date) + ' — ' + count + ' scans';
                    bars += '<div class="chart-bar" style="height:' + height + '%" title="' + escapeHtml(title) + '"></div>';
                }

                const firstLabel = days.length > 0 ? formatDutchDate(days[0].date) : '';
                const lastLabel = days.length > 0 ? formatDutchDate(days[days.length - 1].date) : '';

                return '<div class="chart-wrap">'
                    + '<h3 class="chart-title">Scans afgelopen 28 dagen</h3>'
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
                        mainHtml += renderChart(payload.chart_30_days || []);
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

            async function syncChunks()
            {
                if (isSyncing)
                {
                    return;
                }

                isSyncing = true;
                syncBtn.disabled = true;
                showError('');

                const company = selectedCompany();
                let chunkCount = 0;
                let chunkTotalEstimate = null;

                try
                {
                    while (true)
                    {
                        const chunkDisplay = chunkCount + 1;
                        const totalDisplay = chunkTotalEstimate !== null
                            ? String(chunkTotalEstimate)
                            : '…';
                        statusText.innerHTML = '<strong>Synchroniseren…</strong> chunk ' + chunkDisplay + ' van ' + totalDisplay + ' voor ' + escapeHtml(company);
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

                        chunkCount++;
                        chunkTotalEstimate = Number(payload.chunk_total || chunkTotalEstimate || chunkCount);
                        const chunkCurrent = Number(payload.chunk_current || chunkCount);
                        const chunkTotal = Number(payload.chunk_total || chunkTotalEstimate);
                        const inserted = Number(payload.inserted_count || 0);
                        const fetched = Number(payload.fetched_count || 0);
                        statusText.innerHTML = 'Laatste chunk: <strong>' + chunkCurrent + ' van ' + chunkTotal + '</strong> ('
                            + escapeHtml(String(payload.mode || '')) + ', '
                            + escapeHtml(String(payload.from_date || '')) + ' t/m ' + escapeHtml(String(payload.to_date || '')) + '), '
                            + fetched + ' opgehaald, ' + inserted + ' nieuw opgeslagen.';

                        await fetchOverview();

                        if (!payload.needs_more_chunks)
                        {
                            statusText.innerHTML = 'Synchronisatie voltooid. <strong>' + chunkCurrent + ' van ' + chunkTotal + '</strong> chunks verwerkt.';
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
                    syncBtn.disabled = false;
                }
            }

            syncBtn.addEventListener('click', function ()
            {
                syncChunks();
            });

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
