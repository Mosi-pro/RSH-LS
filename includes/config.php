<?php
/**
 * RSH-LS – zentrale Konfiguration
 * Diese Datei niemals direkt im Browser aufrufen.
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

// --- Umgebung -------------------------------------------------
define('APP_NAME', 'RSH Technik Lager System');
define('APP_SHORT', 'RSH-LS');
define('APP_ENV', getenv('RSH_ENV') ?: 'production'); // 'development' | 'production'

// --- Datenbank --------------------------------------------------
define('DB_HOST', getenv('RSH_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('RSH_DB_NAME') ?: 'rsh_ls');
define('DB_USER', getenv('RSH_DB_USER') ?: 'rsh_ls');
define('DB_PASS', getenv('RSH_DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

// --- Pfade --------------------------------------------------
define('ROOT_PATH', dirname(__DIR__));
define('BASE_URL', rtrim(getenv('RSH_BASE_URL') ?: '', '/')); // z.B. '' oder '/rsh-ls'
define('UPLOAD_PATH', ROOT_PATH . '/uploads');

// --- Sicherheit / Session --------------------------------------
define('SESSION_NAME', 'RSHLS_SESSION');
define('SESSION_TIMEOUT', 60 * 60);        // 60 Minuten Inaktivität -> Logout
define('TERMINAL_SESSION_TIMEOUT', 60 * 60 * 24 * 30); // Lager-Terminal bleibt lange angemeldet

// --- Format --------------------------------------------------
date_default_timezone_set('Europe/Berlin');
define('INVENTORY_PREFIX', 'RSH-');
define('INVENTORY_DIGITS', 4);

// --- Fehlerausgabe --------------------------------------------
if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
