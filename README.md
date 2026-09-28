# Mithra-old

OData-lezingen gaan via Mímir als `$mimirApi` in `web/auth.php` staat. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Mithra dezelfde gegevens op via het oude Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment` en de lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-proces over.

Laat die BC-gegevens in `auth.php` naast `$mimirApi` staan (zie `web/auth_TEMPLATE.php`). Zonder BC-credentials wordt de oorspronkelijke Mímir-fout opnieuw gegooid. Zonder `$mimirApi` blijft alleen het directe BC-pad actief.

Dit geldt voor live webverzoeken (`web/index.php`, inclusief synchronisatie via `action=sync_chunk`) en voor CLI/cron. Bij `PHP_SAPI=cli` blijft de Mímir-timeout lang (600s); webverzoeken gebruiken ongeveer 90s, met een connect-timeout van 10s. De fallback laadt `auth.php` als de BC-variabelen in dat proces nog niet gezet zijn.
