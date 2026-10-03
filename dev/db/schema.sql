-- Database schema (greenfield project: edit this file directly, then run init-db.cmd).
-- Based on the reconstruction in docs/legacy-backend.md. Must work on MariaDB and MySQL 8.

-- Key/value configuration (event info, prices, limits, stage of the site, pages in HTML).
-- TEXT: the pages for visitors who cannot buy may be long (up to 20000 characters).
CREATE TABLE settings (
    name  VARCHAR(64) NOT NULL PRIMARY KEY,
    value TEXT        NOT NULL
) ENGINE=InnoDB;

-- Reservations. One e-mail may have several (e.g. tickets bought for different groups).
-- A draft is bound to the browser (owner key in session_id) that created it and is never looked
-- up by e-mail, so the public API reveals nothing about other people's reservations.
-- The draft starts with the first chosen ticket; the e-mail comes with the confirmation.
CREATE TABLE reservations (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email            VARCHAR(255) NULL,
    name             VARCHAR(255) NULL,
    phone            VARCHAR(32)  NULL,
    standing_tickets INT UNSIGNED NOT NULL DEFAULT 0,
    status           ENUM('draft', 'confirmed', 'partially_paid', 'paid', 'cancelled') NOT NULL DEFAULT 'draft',
    session_id       VARCHAR(128) NULL,
    total_price      INT UNSIGNED NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    confirmed_at     DATETIME NULL,
    email_sent_at    DATETIME NULL,
    email_error      VARCHAR(1000) NULL,
    paid_at          DATETIME NULL,
    paid_amount      DECIMAL(12, 2) NOT NULL DEFAULT 0,
    note             VARCHAR(1000) NULL,
    -- Stage of the site when the reservation was started: test (tester link), vip (VIP link), public.
    channel          ENUM('test', 'vip', 'public') NOT NULL DEFAULT 'public',
    -- Secret of the link "Moje rezervace" (/rezervace/<id>/<token>) sent in the e-mails.
    access_token     CHAR(32) NULL,
    -- Last payment reminder (sent by hand from the administration).
    reminded_at      DATETIME NULL,
    UNIQUE KEY uq_reservations_token (access_token),
    -- The draft of a browser, looked up on every API call. (No index on email: it is only
    -- searched with LIKE '%...%' in the administration, and the table stays small.)
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
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Seat labels ("3/5") are unique; the admin search and the log filter look seats up by label.
    UNIQUE KEY uq_seats_label (label),
    -- Expired holds (every API call).
    KEY ix_seats_state (state, booked_at),
    -- Seats of a reservation, and its held seats (reservation_id = ? AND state = 'book').
    KEY ix_seats_reservation (reservation_id, state),
    CONSTRAINT fk_seats_table FOREIGN KEY (table_id) REFERENCES hall_tables (id),
    CONSTRAINT fk_seats_reservation FOREIGN KEY (reservation_id)
        REFERENCES reservations (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Organizers with access to the administration. Passwords via password_hash().
CREATE TABLE admin_users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    login          VARCHAR(64)  NOT NULL,
    name           VARCHAR(255) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    failed_logins  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_failed_at DATETIME NULL,
    last_login_at  DATETIME NULL,
    -- Incremented on password change; sessions with an older value are logged out.
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admin_users_login (login)
) ENGINE=InnoDB;

-- Log of everything that happens with reservations, payments, e-mails and the administration.
-- actor_type: who caused it (customer = visitor of the site, admin, cron, system = automatic consequence).
CREATE TABLE event_log (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actor_type     ENUM('customer', 'admin', 'cron', 'system') NOT NULL,
    admin_user_id  INT UNSIGNED NULL,
    action         VARCHAR(64)  NOT NULL,
    reservation_id INT UNSIGNED NULL,
    details        VARCHAR(2000) NULL,
    -- The log page is ordered by id (= time); these serve its filters.
    KEY ix_event_log_created (created_at),
    KEY ix_event_log_action (action, created_at),
    KEY ix_event_log_actor (actor_type),
    KEY ix_event_log_reservation (reservation_id),
    CONSTRAINT fk_event_log_admin FOREIGN KEY (admin_user_id) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Seats an event refers to (e.g. all seats of a confirmed reservation), for filtering by seat.
CREATE TABLE event_log_seats (
    event_id INT UNSIGNED NOT NULL,
    seat_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, seat_id),
    KEY ix_event_log_seats_seat (seat_id),
    CONSTRAINT fk_event_log_seats_event FOREIGN KEY (event_id) REFERENCES event_log (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Incoming and outgoing payments downloaded from the bank (Fio) or the mock bank.
-- Stored before matching, so nothing is lost when matching fails; (source, external_id) is unique.
CREATE TABLE bank_transactions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source          ENUM('fio', 'mock') NOT NULL,
    external_id     VARCHAR(64)  NOT NULL,
    booked_on       DATE NOT NULL,
    amount          DECIMAL(12, 2) NOT NULL,
    currency        CHAR(3) NOT NULL DEFAULT 'CZK',
    variable_symbol VARCHAR(10)  NULL,
    counter_account VARCHAR(64)  NULL,
    counter_name    VARCHAR(255) NULL,
    message         VARCHAR(255) NULL,
    raw             TEXT NULL,
    imported_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reservation_id  INT UNSIGNED NULL,
    match_status    ENUM('matched', 'underpaid', 'overpaid', 'unknown_vs', 'no_vs', 'foreign', 'outgoing', 'ignored') NOT NULL,
    UNIQUE KEY uq_bank_transactions_external (source, external_id),
    -- Payments of a reservation (matching sums by status).
    KEY ix_bank_transactions_reservation (reservation_id, match_status),
    -- The shared scout account brings many unrelated movements: the Payments page filters by
    -- status and orders by date, the dashboard and cron count problems.
    KEY ix_bank_transactions_status (match_status, booked_on),
    KEY ix_bank_transactions_booked (booked_on),
    CONSTRAINT fk_bank_transactions_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id)
) ENGINE=InnoDB;

-- Fake bank for local testing (/dev/bank); read by MockBankSource like the Fio API.
CREATE TABLE mock_bank_transactions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    booked_on       DATE NOT NULL,
    amount          DECIMAL(12, 2) NOT NULL,
    currency        CHAR(3) NOT NULL DEFAULT 'CZK',
    variable_symbol VARCHAR(10)  NULL,
    counter_account VARCHAR(64)  NULL,
    counter_name    VARCHAR(255) NULL,
    message         VARCHAR(255) NULL,
    fetched         TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
