-- Provisional schema derived from the original client code (original/script.js).
-- Align it with the real structure once a database export from Lebeda is available.
-- Run by init-db.ps1 into a freshly created database.

CREATE TABLE settings (
    name  VARCHAR(64)  NOT NULL PRIMARY KEY,
    value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- One reservation per e-mail (the original site answered "error" for duplicates).
CREATE TABLE reservations (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email            VARCHAR(255) NOT NULL,
    standing_tickets INT UNSIGNED NOT NULL DEFAULT 0,
    status           ENUM('draft', 'confirmed', 'paid', 'cancelled') NOT NULL DEFAULT 'draft',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reservations_email (email)
) ENGINE=InnoDB;

-- Seats in the hall. state: free, book = temporarily held (120 s), reserved = confirmed.
CREATE TABLE seats (
    id             INT UNSIGNED NOT NULL PRIMARY KEY,
    label          VARCHAR(16)  NOT NULL,
    table_no       SMALLINT UNSIGNED NULL,
    state          ENUM('free', 'book', 'reserved') NOT NULL DEFAULT 'free',
    reservation_id INT UNSIGNED NULL,
    booked_at      DATETIME NULL,
    KEY ix_seats_state (state),
    CONSTRAINT fk_seats_reservation FOREIGN KEY (reservation_id)
        REFERENCES reservations (id) ON DELETE SET NULL
) ENGINE=InnoDB;
