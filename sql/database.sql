-- ============================================================
-- RSH Technik Lager System (RSH-LS)
-- Datenbankschema (MySQL / MariaDB, InnoDB, utf8mb4)
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Mitarbeiter / Benutzer
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id     VARCHAR(10)  NOT NULL UNIQUE,      -- z.B. 198
    name            VARCHAR(100) NOT NULL,
    role            ENUM('technikleitung','lagerleitung','mitarbeiter','veranstaltungsleitung','lager_terminal','gast')
                                 NOT NULL DEFAULT 'mitarbeiter',
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    profile_image   VARCHAR(255) NULL,
    notes           TEXT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Kategorien
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS device_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    parent_id   INT UNSIGNED NULL,
    CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES device_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Lagerorte (hierarchisch: Lager > Regal > Fach)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS storage_locations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    parent_id   INT UNSIGNED NULL,
    CONSTRAINT fk_loc_parent FOREIGN KEY (parent_id) REFERENCES storage_locations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Geräte
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS devices (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_number    VARCHAR(20)  NOT NULL UNIQUE,   -- RSH-0001
    name                VARCHAR(150) NOT NULL,
    category_id         INT UNSIGNED NULL,
    manufacturer        VARCHAR(100) NULL,
    model               VARCHAR(100) NULL,
    serial_number       VARCHAR(100) NULL,
    location_id         INT UNSIGNED NULL,
    condition_note      VARCHAR(255) NULL,
    status              ENUM('verfuegbar','reserviert','ausgegeben','defekt','wartung','verloren','aussortiert')
                                     NOT NULL DEFAULT 'verfuegbar',
    purchase_date       DATE NULL,
    purchase_price      DECIMAL(10,2) NULL,
    image               VARCHAR(255) NULL,
    description         TEXT NULL,
    accessories         TEXT NULL,
    is_bulk             TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = Mengenartikel
    quantity            INT UNSIGNED NOT NULL DEFAULT 1, -- Gesamtbestand bei Mengenartikeln
    barcode             VARCHAR(100) NULL,
    notes               TEXT NULL,
    current_order_id    INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_dev_cat  FOREIGN KEY (category_id) REFERENCES device_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_dev_loc  FOREIGN KEY (location_id) REFERENCES storage_locations(id) ON DELETE SET NULL,
    INDEX idx_dev_status (status),
    FULLTEXT INDEX ft_dev_search (name, manufacturer, model, serial_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Veranstaltungen
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(150) NOT NULL,
    event_date          DATE NULL,
    event_time          TIME NULL,
    location            VARCHAR(150) NULL,
    organizer           VARCHAR(150) NULL,
    contact_person      VARCHAR(150) NULL,
    setup_date          DATE NULL,
    teardown_date       DATE NULL,
    responsible_user_id INT UNSIGNED NULL,
    status              ENUM('geplant','vorbereitung','laeuft','abgeschlossen','storniert') NOT NULL DEFAULT 'geplant',
    notes               TEXT NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_evt_resp FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_evt_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Aufträge
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_number        VARCHAR(20) NOT NULL UNIQUE,   -- 2026-041
    title               VARCHAR(150) NOT NULL,
    description         TEXT NULL,
    customer            VARCHAR(150) NULL,
    contact_person      VARCHAR(150) NULL,
    location             VARCHAR(150) NULL,
    event_date          DATE NULL,
    setup_date          DATE NULL,
    teardown_date       DATE NULL,
    responsible_user_id INT UNSIGNED NULL,
    status              ENUM('entwurf','geplant','vorbereitung','bereit_zur_ausgabe','ausgegeben','im_einsatz','rueckgabe_ausstehend','abgeschlossen','storniert')
                                    NOT NULL DEFAULT 'entwurf',
    event_id            INT UNSIGNED NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ord_resp    FOREIGN KEY (responsible_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ord_event   FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL,
    CONSTRAINT fk_ord_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ord_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE devices
    ADD CONSTRAINT fk_dev_order FOREIGN KEY (current_order_id) REFERENCES orders(id) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- Geplante Technik pro Auftrag (Positionen)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NOT NULL,
    device_id   INT UNSIGNED NOT NULL,   -- Referenz-Gerät (bei Einzelgeräten das konkrete Gerät, bei Mengenartikeln der "Typ")
    quantity    INT UNSIGNED NOT NULL DEFAULT 1,
    note        VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_oi_order  FOREIGN KEY (order_id)  REFERENCES orders(id)  ON DELETE CASCADE,
    CONSTRAINT fk_oi_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Konkrete Einzelgeräte, die einem Auftrag zugeordnet/reserviert sind
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_devices (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    device_id       INT UNSIGNED NOT NULL,
    status          ENUM('reserviert','ausgegeben','zurueckgegeben') NOT NULL DEFAULT 'reserviert',
    checked_out_at  DATETIME NULL,
    checked_out_by  INT UNSIGNED NULL,
    returned_at     DATETIME NULL,
    returned_by     INT UNSIGNED NULL,
    complete        TINYINT(1) NULL,       -- NULL = offen, 1 = vollständig, 0 = nicht vollständig
    missing_notes   TEXT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_order_device (order_id, device_id),
    CONSTRAINT fk_od_order  FOREIGN KEY (order_id)  REFERENCES orders(id)  ON DELETE CASCADE,
    CONSTRAINT fk_od_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    CONSTRAINT fk_od_out_by FOREIGN KEY (checked_out_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_od_ret_by FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Ausgabe- / Rückgabevorgänge (Kopfdaten für Historie)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS checkouts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL,
    issued_by       INT UNSIGNED NULL,
    issued_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    item_count      INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_co_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_co_user  FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS returns (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id            INT UNSIGNED NOT NULL,
    returned_by         INT UNSIGNED NULL,
    returned_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total_devices       INT UNSIGNED NOT NULL DEFAULT 0,
    complete_devices    INT UNSIGNED NOT NULL DEFAULT 0,
    incomplete_devices  INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_re_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_re_user  FOREIGN KEY (returned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Audit-Log / Historie (nie überschreiben, nur anhängen)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    action       VARCHAR(255) NOT NULL,
    entity_type  VARCHAR(50)  NOT NULL,     -- device, order, event, user, checkout, return
    entity_id    INT UNSIGNED NULL,
    details      TEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_log_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_log_entity (entity_type, entity_id),
    INDEX idx_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Beispiel-Stammdaten
-- ------------------------------------------------------------
INSERT INTO users (employee_id, name, role, active) VALUES
    ('198', 'Moritz', 'technikleitung', 1),
    ('203', 'Mitarbeiter 203', 'mitarbeiter', 1),
    ('245', 'Mitarbeiter 245', 'mitarbeiter', 1),
    ('999', 'Lager-Terminal', 'lager_terminal', 1);

INSERT INTO device_categories (name, parent_id) VALUES
    ('Licht', NULL), ('Ton', NULL), ('Video', NULL), ('Kabel', NULL), ('Zubehör', NULL);

INSERT INTO storage_locations (name, parent_id) VALUES ('Technikraum', NULL);
INSERT INTO storage_locations (name, parent_id) VALUES ('Regal A', 1), ('Regal B', 1);
INSERT INTO storage_locations (name, parent_id) VALUES ('Fach 01', 2), ('Fach 02', 2), ('Fach 03', 2);
