# Mithra-old

OData-lezingen gaan via Mímir als `$mimirApi` in `web/auth.php` staat. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Mithra dezelfde gegevens op via het oude Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment` en de lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over.

Laat die BC-gegevens in `auth.php` naast `$mimirApi` staan (zie `web/auth_TEMPLATE.php`). Zonder BC-credentials wordt de oorspronkelijke Mímir-fout opnieuw gegooid. Zonder `$mimirApi` blijft alleen het directe BC-pad actief. `web/odata.php` blijft verder onaangeroerd; de Mímir-fallback daarin is een goedgekeurde uitzondering van Tim Falken (2026-09-28).

Dit geldt voor live webverzoeken (`web/index.php`, inclusief synchronisatie via `action=sync_chunk`) en voor CLI/cron. Bij `PHP_SAPI=cli` blijft de Mímir-timeout lang (600s); webverzoeken gebruiken ongeveer 90s, met een connect-timeout van 10s. De fallback laadt `auth.php` als de BC-variabelen in dat proces nog niet gezet zijn.

`web/analytics/analytics.sqlite` (en `*.sqlite-*`) blijft op de server staan bij een deploy: de FTP-mirror slaat die bestanden al over. Dezelfde mirror slaat `.htaccess` over, dus een gecommit bestand komt niet mee en een bestaand bestand wordt niet gewist. `analytics.php` schrijft `web/analytics/.htaccess` als die ontbreekt (of vult hem aan als het woord `sqlite` er nog niet in staat), zodat Apache de database weigert en `analytics.php` zelf bereikbaar blijft.
