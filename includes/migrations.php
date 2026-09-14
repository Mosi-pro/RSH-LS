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

        3 => function (PDO $pdo) {
            // -- users: Rolle "werkstatt" zur CHECK-Constraint hinzufügen -----
            // SQLite kann CHECK-Constraints nicht per ALTER TABLE ändern -> Tabelle neu aufbauen.
            $pdo->exec("CREATE TABLE users_new (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                employee_id     TEXT NOT NULL UNIQUE,
                name            TEXT NOT NULL,
                role            TEXT NOT NULL DEFAULT 'mitarbeiter'
                                    CHECK (role IN ('technikleitung','lagerleitung','mitarbeiter','veranstaltungsleitung','lager_terminal','werkstatt','gast')),
                active          INTEGER NOT NULL DEFAULT 1,
                profile_image   TEXT,
                notes           TEXT,
                created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("INSERT INTO users_new (id, employee_id, name, role, active, profile_image, notes, created_at, updated_at)
                         SELECT id, employee_id, name, role, active, profile_image, notes, created_at, updated_at FROM users");
            $pdo->exec("DROP TABLE users");
            $pdo->exec("ALTER TABLE users_new RENAME TO users");
            $pdo->exec("CREATE TRIGGER trg_users_updated_at AFTER UPDATE ON users
                        BEGIN
                            UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
                        END");

            // Werkstatt-Sammelaccount anlegen (Mitarbeiter-ID per Mitarbeiterverwaltung änderbar).
            $exists = $pdo->query("SELECT COUNT(*) FROM users WHERE employee_id = '355'")->fetchColumn();
            if (!$exists) {
                $pdo->exec("INSERT INTO users (employee_id, name, role, active) VALUES ('355', 'Werkstatt', 'werkstatt', 1)");
            }

            // -- devices: Status "in_reparatur" zur CHECK-Constraint hinzufügen -----
            $pdo->exec("CREATE TABLE devices_new (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                inventory_number    TEXT NOT NULL UNIQUE,
                name                TEXT NOT NULL,
                category_id         INTEGER REFERENCES device_categories(id) ON DELETE SET NULL,
                manufacturer        TEXT,
                model               TEXT,
                serial_number       TEXT,
                location_id         INTEGER REFERENCES storage_locations(id) ON DELETE SET NULL,
                condition_note      TEXT,
                status              TEXT NOT NULL DEFAULT 'verfuegbar'
                                        CHECK (status IN ('verfuegbar','reserviert','ausgegeben','defekt','wartung','in_reparatur','verloren','aussortiert')),
                purchase_date       TEXT,
                purchase_price      REAL,
                image               TEXT,
                description         TEXT,
                accessories         TEXT,
                is_bulk             INTEGER NOT NULL DEFAULT 0,
                quantity            INTEGER NOT NULL DEFAULT 1,
                barcode             TEXT,
                notes               TEXT,
                current_order_id    INTEGER REFERENCES orders(id) ON DELETE SET NULL,
                created_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_maintenance_date TEXT,
                next_maintenance_date TEXT
            )");
            $pdo->exec("INSERT INTO devices_new (id, inventory_number, name, category_id, manufacturer, model, serial_number,
                    location_id, condition_note, status, purchase_date, purchase_price, image, description, accessories,
                    is_bulk, quantity, barcode, notes, current_order_id, created_at, updated_at,
                    last_maintenance_date, next_maintenance_date)
                SELECT id, inventory_number, name, category_id, manufacturer, model, serial_number,
                    location_id, condition_note, status, purchase_date, purchase_price, image, description, accessories,
                    is_bulk, quantity, barcode, notes, current_order_id, created_at, updated_at,
                    last_maintenance_date, next_maintenance_date
                FROM devices");
            $pdo->exec("DROP TABLE devices");
            $pdo->exec("ALTER TABLE devices_new RENAME TO devices");
            $pdo->exec("CREATE INDEX idx_dev_status ON devices(status)");
            $pdo->exec("CREATE TRIGGER trg_devices_updated_at AFTER UPDATE ON devices
                        BEGIN
                            UPDATE devices SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
                        END");

            // -- Benachrichtigungen (z.B. für den Melder, wenn ein Defekt behoben wurde) -----
            $pdo->exec("CREATE TABLE notifications (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                title       TEXT NOT NULL,
                message     TEXT NOT NULL,
                url         TEXT,
                read_at     TEXT,
                created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE INDEX idx_notifications_user ON notifications(user_id, read_at)");
        },

        4 => function (PDO $pdo) {
            // -- Schnellausgaben (Ausleihen ohne Auftrag) – eigenständig, kein Auftrag -----
            $pdo->exec("CREATE TABLE quick_checkouts (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                code         TEXT NOT NULL UNIQUE,
                employee_id  INTEGER REFERENCES users(id) ON DELETE SET NULL,
                issued_by    INTEGER REFERENCES users(id) ON DELETE SET NULL,
                status       TEXT NOT NULL DEFAULT 'offen'
                                 CHECK (status IN ('offen','zurueckgegeben')),
                created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                returned_at  TEXT
            )");
            $pdo->exec("CREATE INDEX idx_qco_code ON quick_checkouts(code)");
            $pdo->exec("CREATE INDEX idx_qco_status ON quick_checkouts(status)");

            $pdo->exec("CREATE TABLE quick_checkout_items (
                id                 INTEGER PRIMARY KEY AUTOINCREMENT,
                quick_checkout_id  INTEGER NOT NULL REFERENCES quick_checkouts(id) ON DELETE CASCADE,
                device_id          INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
                quantity           INTEGER NOT NULL DEFAULT 1,
                is_bulk            INTEGER NOT NULL DEFAULT 0,
                returned_at        TEXT,
                returned_by        INTEGER REFERENCES users(id) ON DELETE SET NULL,
                created_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("CREATE INDEX idx_qci_checkout ON quick_checkout_items(quick_checkout_id)");
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
