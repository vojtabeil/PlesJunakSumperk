-- Database schema (greenfield project: edit this file directly, then run dev\init-db.cmd).
-- Based on the reconstruction in doc/legacy/original-backend.md. Must work on MariaDB and MySQL 8.

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

-- Tables in the hall: id = number of the table in the plan, svg_id = id of its group in the SVG.
-- Removed from the plan = active 0 (kept for the history). Shapes and positions are only in the SVG.
CREATE TABLE hall_tables (
    id     SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    label  VARCHAR(16) NOT NULL,
    name   VARCHAR(255) NULL,
    svg_id VARCHAR(64) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Seats of the hall: the layout only, what is sold is in seat_bookings. id = number of the seat in the plan,
-- svg_id = id of its shape in the SVG; label = "<table>/<seat>" (e.g. "12/87"). Removed from the plan = active 0
-- (the event log still refers to it).
CREATE TABLE seats (
    id       INT UNSIGNED NOT NULL PRIMARY KEY,
    label    VARCHAR(16)  NOT NULL,
    table_id SMALLINT UNSIGNED NULL,
    svg_id   VARCHAR(64)  NULL,
    active   TINYINT(1) NOT NULL DEFAULT 1,
    -- Seat labels ("3/5") are unique; the admin search and the log filter look seats up by label.
    UNIQUE KEY uq_seats_label (label),
    CONSTRAINT fk_seats_table FOREIGN KEY (table_id) REFERENCES hall_tables (id)
) ENGINE=InnoDB;

-- Sale of seats. A row exists only while a seat is held or reserved; a free seat has none.
-- held = temporarily held by a draft (hold_seconds since held_at), reserved = in a confirmed reservation.
-- Holding a seat is an INSERT: the primary key lets only one reservation have it.
CREATE TABLE seat_bookings (
    seat_id        INT UNSIGNED NOT NULL PRIMARY KEY,
    reservation_id INT UNSIGNED NOT NULL,
    state          ENUM('held', 'reserved') NOT NULL,
    held_at        DATETIME NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Expired holds (every API call).
    KEY ix_seat_bookings_state (state, held_at),
    -- Seats of a reservation, and its held seats (reservation_id = ? AND state = 'held').
    KEY ix_seat_bookings_reservation (reservation_id, state),
    CONSTRAINT fk_seat_bookings_seat FOREIGN KEY (seat_id) REFERENCES seats (id),
    CONSTRAINT fk_seat_bookings_reservation FOREIGN KEY (reservation_id)
        REFERENCES reservations (id) ON DELETE CASCADE
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

-- The hall plan (SVG drawing): at most one row, there is only one version. Replacing it is a dangerous
-- action in the administration (HallSync); tables and seats below are synchronized from it.
CREATE TABLE hall_plan (
    id         TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    svg        MEDIUMTEXT   NOT NULL,
    file_name  VARCHAR(255) NOT NULL,
    applied_by INT UNSIGNED NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_hall_plan_admin FOREIGN KEY (applied_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Z-lístky: free passes for the crew (people who prepare and run the ball). Each person has exactly
-- one ticket (tickets.crew_member_id); no seat, no price, not counted in any capacity.
CREATE TABLE crew_members (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(150) NOT NULL,
    -- Free text: bar, kuchyně, šatna, ...
    role         VARCHAR(100) NOT NULL,
    email        VARCHAR(254) NULL,
    note         VARCHAR(500) NULL,
    -- The organizer handed the printed pass over.
    picked_up_at DATETIME NULL,
    emailed_at   DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_crew_members_role (role)
) ENGINE=InnoDB;

-- Tickets: one per seat and one per standing ticket of a reservation, created when it is confirmed.
-- code = 6 random hex characters, in the QR code and typed by hand at the entrance; it never opens
-- anything public. print_token = secret part of the public print link /vstupenka/<code>/<print_token>.
-- A cancelled reservation keeps its tickets, they are invalid by the reservation status.
CREATE TABLE tickets (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    -- Exactly one owner: a reservation (seat or standing ticket) or a crew member (Z-lístek).
    -- Kept by Tickets (MySQL forbids a CHECK on columns with ON DELETE CASCADE).
    reservation_id INT UNSIGNED NULL,
    crew_member_id INT UNSIGNED NULL,
    seat_id        INT UNSIGNED NULL,
    code           CHAR(6)  NOT NULL,
    print_token    CHAR(32) NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at        DATETIME NULL,
    used_by        INT UNSIGNED NULL,
    UNIQUE KEY uq_tickets_code (code),
    KEY ix_tickets_reservation (reservation_id),
    -- Entrance statistics (used tickets).
    KEY ix_tickets_used (used_at),
    UNIQUE KEY uq_tickets_crew_member (crew_member_id),
    CONSTRAINT fk_tickets_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id) ON DELETE CASCADE,
    CONSTRAINT fk_tickets_crew_member FOREIGN KEY (crew_member_id) REFERENCES crew_members (id) ON DELETE CASCADE,
    CONSTRAINT fk_tickets_seat FOREIGN KEY (seat_id) REFERENCES seats (id),
    CONSTRAINT fk_tickets_used_by FOREIGN KEY (used_by) REFERENCES admin_users (id) ON DELETE SET NULL
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
