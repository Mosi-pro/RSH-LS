<?php
/**
 * RSH-LS – SQLite-Schema
 *
 * Wird von includes/database.php automatisch EINMALIG ausgeführt, wenn
 * die SQLite-Datei (DB_PATH) noch nicht existiert. Danach nie wieder.
 * Erwartet eine bereits verbundene PDO-Instanz in $pdo.
 */
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}

/** @var PDO $pdo */

$statements = [

    // -- Mitarbeiter / Benutzer -----------------------------------
    "CREATE TABLE users (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id     TEXT NOT NULL UNIQUE,
        name            TEXT NOT NULL,
        role            TEXT NOT NULL DEFAULT 'mitarbeiter'
                            CHECK (role IN ('technikleitung','lagerleitung','mitarbeiter','veranstaltungsleitung','lager_terminal','gast')),
        active          INTEGER NOT NULL DEFAULT 1,
        profile_image   TEXT,
        notes           TEXT,
        created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TRIGGER trg_users_updated_at AFTER UPDATE ON users
     BEGIN
        UPDATE users SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
     END",

    // -- Kategorien -------------------------------------------------
    "CREATE TABLE device_categories (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL,
        parent_id   INTEGER REFERENCES device_categories(id) ON DELETE SET NULL
    )",

    // -- Lagerorte (hierarchisch: Lager > Regal > Fach) --------------
    "CREATE TABLE storage_locations (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL,
        parent_id   INTEGER REFERENCES storage_locations(id) ON DELETE SET NULL
    )",

    // -- Geräte -------------------------------------------------------
    "CREATE TABLE devices (
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
                                CHECK (status IN ('verfuegbar','reserviert','ausgegeben','defekt','wartung','verloren','aussortiert')),
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
        updated_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX idx_dev_status ON devices(status)",
    "CREATE TRIGGER trg_devices_updated_at AFTER UPDATE ON devices
     BEGIN
        UPDATE devices SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
     END",

    // -- Veranstaltungen ----------------------------------------------
    "CREATE TABLE events (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        name                TEXT NOT NULL,
        event_date          TEXT,
        event_time          TEXT,
        location            TEXT,
        organizer           TEXT,
        contact_person      TEXT,
        setup_date          TEXT,
        teardown_date       TEXT,
        responsible_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        status              TEXT NOT NULL DEFAULT 'geplant'
                                CHECK (status IN ('geplant','vorbereitung','laeuft','abgeschlossen','storniert')),
        notes               TEXT,
        created_by          INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE TRIGGER trg_events_updated_at AFTER UPDATE ON events
     BEGIN
        UPDATE events SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
     END",

    // -- Aufträge ----------------------------------------------------
    "CREATE TABLE orders (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        order_number        TEXT NOT NULL UNIQUE,
        title               TEXT NOT NULL,
        description         TEXT,
        customer            TEXT,
        contact_person      TEXT,
        location            TEXT,
        event_date          TEXT,
        setup_date          TEXT,
        teardown_date       TEXT,
        responsible_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        status              TEXT NOT NULL DEFAULT 'entwurf'
                                CHECK (status IN ('entwurf','geplant','vorbereitung','bereit_zur_ausgabe','ausgegeben','im_einsatz','rueckgabe_ausstehend','abgeschlossen','storniert')),
        event_id            INTEGER REFERENCES events(id) ON DELETE SET NULL,
        created_by          INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX idx_ord_status ON orders(status)",
    "CREATE TRIGGER trg_orders_updated_at AFTER UPDATE ON orders
     BEGIN
        UPDATE orders SET updated_at = CURRENT_TIMESTAMP WHERE id = NEW.id;
     END",

    // -- Geplante Technik pro Auftrag (Positionen) --------------------
    "CREATE TABLE order_items (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id    INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        device_id   INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
        quantity    INTEGER NOT NULL DEFAULT 1,
        note        TEXT,
        created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",

    // -- Konkrete Einzelgeräte, die einem Auftrag zugeordnet sind -----
    "CREATE TABLE order_devices (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id        INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        device_id       INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
        status          TEXT NOT NULL DEFAULT 'reserviert'
                            CHECK (status IN ('reserviert','ausgegeben','zurueckgegeben')),
        checked_out_at  TEXT,
        checked_out_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        returned_at     TEXT,
        returned_by     INTEGER REFERENCES users(id) ON DELETE SET NULL,
        complete        INTEGER,
        missing_notes   TEXT,
        created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (order_id, device_id)
    )",

    // -- Ausgabe-/Rückgabevorgänge (Kopfdaten für Historie) -----------
    "CREATE TABLE checkouts (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id        INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        issued_by       INTEGER REFERENCES users(id) ON DELETE SET NULL,
        issued_at       TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        item_count      INTEGER NOT NULL DEFAULT 0
    )",
    "CREATE TABLE returns (
        id                  INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id            INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        returned_by         INTEGER REFERENCES users(id) ON DELETE SET NULL,
        returned_at         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        total_devices       INTEGER NOT NULL DEFAULT 0,
        complete_devices    INTEGER NOT NULL DEFAULT 0,
        incomplete_devices  INTEGER NOT NULL DEFAULT 0
    )",

    // -- Audit-Log / Historie (nie überschreiben, nur anhängen) -------
    "CREATE TABLE activity_log (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        action       TEXT NOT NULL,
        entity_type  TEXT NOT NULL,
        entity_id    INTEGER,
        details      TEXT,
        created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )",
    "CREATE INDEX idx_log_entity ON activity_log(entity_type, entity_id)",
    "CREATE INDEX idx_log_created ON activity_log(created_at)",

    // -- Beispiel-Stammdaten -------------------------------------------
    "INSERT INTO users (employee_id, name, role, active) VALUES
        ('198', 'Moritz', 'technikleitung', 1),
        ('203', 'Mitarbeiter 203', 'mitarbeiter', 1),
        ('245', 'Mitarbeiter 245', 'mitarbeiter', 1),
        ('999', 'Lager-Terminal', 'lager_terminal', 1)",

    "INSERT INTO device_categories (name, parent_id) VALUES
        ('Licht', NULL), ('Ton', NULL), ('Video', NULL), ('Kabel', NULL), ('Zubehör', NULL)",

    "INSERT INTO storage_locations (name, parent_id) VALUES ('Technikraum', NULL)",
    "INSERT INTO storage_locations (name, parent_id) VALUES ('Regal A', 1), ('Regal B', 1)",
    "INSERT INTO storage_locations (name, parent_id) VALUES ('Fach 01', 2), ('Fach 02', 2), ('Fach 03', 2)",
];

$pdo->exec('PRAGMA foreign_keys = OFF'); // während des Anlegens keine Reihenfolge-Probleme
$pdo->beginTransaction();
foreach ($statements as $sql) {
    $pdo->exec($sql);
}
$pdo->commit();
$pdo->exec('PRAGMA foreign_keys = ON');
