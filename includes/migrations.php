<?php
/**
 * RSH-LS – Schema-Migrationen
 *
 * Läuft bei JEDEM Request (nach der Verbindung) und bringt bereits
 * bestehende data/rsh-ls.sqlite-Dateien automatisch auf den neuesten
 * Stand, ohne Daten zu verlieren. Die Version wird in der SQLite-Datei
 * selbst gespeichert (PRAGMA user_version). Neue Migrationen werden
 * einfach mit der nächsten Versionsnummer im $migrations-Array ergänzt
 * – nie bestehende Einträge nachträglich ändern.
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

function run_migrations(PDO $pdo): void
{
    $migrations = [
        2 => function (PDO $pdo) {
            $pdo->exec("ALTER TABLE devices ADD COLUMN last_maintenance_date TEXT");
            $pdo->exec("ALTER TABLE devices ADD COLUMN next_maintenance_date TEXT");

            $pdo->exec("CREATE TABLE defects (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                device_id       INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
                reported_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
                problem         TEXT NOT NULL,
                priority        TEXT NOT NULL DEFAULT 'normal'
                                    CHECK (priority IN ('niedrig','normal','dringend')),
                status          TEXT NOT NULL DEFAULT 'offen'
                                    CHECK (status IN ('offen','in_bearbeitung','behoben')),
                resolution_note TEXT,
                resolved_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
                resolved_at     TEXT,
                created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE INDEX idx_defects_status ON defects(status)");
            $pdo->exec("CREATE INDEX idx_defects_device ON defects(device_id)");

            $pdo->exec("CREATE TABLE inventories (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                label           TEXT NOT NULL,
                location_id     INTEGER REFERENCES storage_locations(id) ON DELETE SET NULL,
                status          TEXT NOT NULL DEFAULT 'laufend'
                                    CHECK (status IN ('laufend','abgeschlossen')),
                started_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
                started_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                completed_at    TEXT
            )");
            $pdo->exec("CREATE TABLE inventory_items (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                inventory_id    INTEGER NOT NULL REFERENCES inventories(id) ON DELETE CASCADE,
                device_id       INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
                found           INTEGER,
                checked_at      TEXT,
                checked_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE (inventory_id, device_id)
            )");
            $pdo->exec("CREATE INDEX idx_inv_items_inventory ON inventory_items(inventory_id)");
        },
    ];

    $current = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $target = max(array_keys($migrations) + [$current]);

    if ($current >= $target) {
        return;
    }

    ksort($migrations);
    foreach ($migrations as $version => $migrate) {
        if ($version <= $current) {
            continue;
        }
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->beginTransaction();
        try {
            $migrate($pdo);
            $pdo->exec('PRAGMA user_version = ' . (int)$version);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('RSH-LS Migration ' . $version . ' fehlgeschlagen: ' . $e->getMessage());
            throw $e;
        }
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
}
