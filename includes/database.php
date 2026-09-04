<?php
/**
 * RSH-LS – Datenbankverbindung (PDO Singleton)
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('RSH-LS DB-Verbindung fehlgeschlagen: ' . $e->getMessage());
            http_response_code(500);
            exit('Datenbankverbindung fehlgeschlagen. Bitte später erneut versuchen.');
        }
    }

    return $pdo;
}
