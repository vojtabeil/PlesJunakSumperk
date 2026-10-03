-- Database schema. Based on the reconstruction in docs/legacy-backend.md;
-- align it with the real structure once a database export from Lebeda is available.
-- Run by init-db.ps1 into a freshly created database. Must work on MariaDB and MySQL 8.

-- Key/value configuration (event info, prices, limits, sale switch).
CREATE TABLE settings (
    name  VARCHAR(64)   NOT NULL PRIMARY KEY,
    value VARCHAR(1000) NOT NULL
) ENGINE=InnoDB;

-- One reservation per e-mail (the original site refused a second one).
-- A draft is bound to the PHP session that created it; id is the payment variable symbol.
CREATE TABLE reservations (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email            VARCHAR(255) NOT NULL,
    name             VARCHAR(255) NULL,
    phone            VARCHAR(32)  NULL,
    standing_tickets INT UNSIGNED NOT NULL DEFAULT 0,
    status           ENUM('draft', 'confirmed', 'paid', 'cancelled') NOT NULL DEFAULT 'draft',
    session_id       VARCHAR(128) NULL,
    total_price      INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    confirmed_at     DATETIME NULL,
    UNIQUE KEY uq_reservations_email (email),
    KEY ix_reservations_session (session_id)
) ENGINE=InnoDB;

-- Tables in the hall. Coordinates are in hall map units (see settings map_width / map_height).
CREATE TABLE hall_tables (
    id     SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    label  VARCHAR(16) NOT NULL,
    x      SMALLINT UNSIGNED NOT NULL,
    y      SMALLINT UNSIGNED NOT NULL,
    width  SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- Seats. x/y is the seat centre in map units.
-- state: free, book = temporarily held (hold_seconds), reserved = confirmed.
CREATE TABLE seats (
    id             INT UNSIGNED NOT NULL PRIMARY KEY,
    label          VARCHAR(16)  NOT NULL,
    table_id       SMALLINT UNSIGNED NULL,
    x              SMALLINT UNSIGNED NOT NULL,
    y              SMALLINT UNSIGNED NOT NULL,
    state          ENUM('free', 'book', 'reserved') NOT NULL DEFAULT 'free',
    reservation_id INT UNSIGNED NULL,
    booked_at      DATETIME NULL,
    KEY ix_seats_state (state, booked_at),
    KEY ix_seats_reservation (reservation_id),
    CONSTRAINT fk_seats_table FOREIGN KEY (table_id) REFERENCES hall_tables (id),
    CONSTRAINT fk_seats_reservation FOREIGN KEY (reservation_id)
        REFERENCES reservations (id) ON DELETE SET NULL
) ENGINE=InnoDB;
