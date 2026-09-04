<?php
/**
 * RSH-LS – Datenbankverbindung (PDO Singleton, SQLite)
 *
 * Die komplette Anwendung speichert alle Daten in EINER einzigen Datei
 * (DB_PATH, siehe config.php). Es wird kein separater Datenbankserver
 * benötigt. Existiert die Datei noch nicht, wird sie beim ersten
 * Aufruf automatisch mit Schema und Beispiel-Mitarbeitern angelegt.
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $isNew = !file_exists(DB_PATH);
        $dir = dirname(DB_PATH);

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log('RSH-LS: Datenverzeichnis ist nicht beschreibbar: ' . $dir);
            http_response_code(500);
            exit('Datenverzeichnis ist nicht beschreibbar. Bitte Schreibrechte für "data/" setzen.');
        }

        try {
            $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON');

            if ($isNew) {
                require __DIR__ . '/schema.php';
            }
            require_once __DIR__ . '/migrations.php';
            run_migrations($pdo);
        } catch (Throwable $e) {
            error_log('RSH-LS DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
            http_response_code(500);
            exit('Datenbankverbindung fehlgeschlagen. Bitte später erneut versuchen.');
        }
    }

    return $pdo;
}
