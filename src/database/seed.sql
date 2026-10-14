-- Test data for local development (loaded after defaults.sql and the demo hall plan demo-hall.svg,
-- seats 1-96 at tables 1-12). Never load into production.
-- Event info comes from the recovered 2024 landing page; prices and capacities are made up.

UPDATE settings SET value = 'public' WHERE name = 'site_mode';
UPDATE settings SET value = '3. 2. 2024 od 19:00' WHERE name = 'event_date';
UPDATE settings SET value = 'Tomáš Slavický' WHERE name = 'organizer';
UPDATE settings SET value = 'ples@example.com' WHERE name = 'contact_email';

INSERT INTO reservations (id, email, name, standing_tickets, status, total_price, confirmed_at) VALUES
    (1, 'test@example.com', 'Testovací Rezervace', 1, 'confirmed', 950, NOW()),
    (2, 'platba@example.com', 'Zaplacená Rezervace', 0, 'paid', 1400, NOW());

INSERT INTO seat_bookings (seat_id, reservation_id, state) VALUES
    (1, 1, 'reserved'), (2, 1, 'reserved'),
    (33, 2, 'reserved'), (34, 2, 'reserved'), (35, 2, 'reserved'), (36, 2, 'reserved');

-- Tickets with fixed codes for trying the entrance check (/admin/kontrola): reservation 1 is unpaid
-- (2 seats + 1 standing), reservation 2 is paid. Print links: /vstupenka/<code>/<code repeated, lower case>.
INSERT INTO tickets (reservation_id, seat_id, code, print_token) VALUES
    (1, 1, 'A10101', 'a10101a10101a10101a10101a10101a1'),
    (1, 2, 'A10102', 'a10102a10102a10102a10102a10102a1'),
    (1, NULL, 'A100F1', 'a100f1a100f1a100f1a100f1a100f1a1'),
    (2, 33, 'B20501', 'b20501b20501b20501b20501b20501b2'),
    (2, 34, 'B20502', 'b20502b20502b20502b20502b20502b2'),
    (2, 35, 'B20503', 'b20503b20503b20503b20503b20503b2'),
    (2, 36, 'B20504', 'b20504b20504b20504b20504b20504b2');

-- Local administrator: login "admin", password "admin".
INSERT INTO admin_users (login, name, password_hash) VALUES
    ('admin', 'Testovací Organizátor', '$2y$12$0dzo6iQBXaEx2QIunet7P.rVyXI5H5K52wlJ/bG6umC0LijyaoSea');

UPDATE reservations SET paid_at = NOW(), paid_amount = total_price WHERE status = 'paid';
-- Links "Moje rezervace" of the test reservations: /rezervace/1/1111..., /rezervace/2/2222...
UPDATE reservations SET access_token = REPEAT(id, 32) WHERE id IN (1, 2);
