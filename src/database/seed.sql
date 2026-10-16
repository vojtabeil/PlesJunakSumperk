-- Test data for local development (loaded after defaults.sql and the hall plan hall-plan.svg; seats are
-- looked up by their labels, e.g. A1/1). Never load into production.
-- Event info (date, venue, organizer, band) is the real one from defaults.sql; prices and capacities are made up.

UPDATE settings SET value = 'public' WHERE name = 'site_mode';
UPDATE settings SET value = 'ples@example.com' WHERE name = 'contact_email';

INSERT INTO reservations (id, email, name, standing_tickets, status, total_price, confirmed_at) VALUES
    (1, 'test@example.com', 'Testovací Rezervace', 1, 'confirmed', 950, NOW()),
    (2, 'platba@example.com', 'Zaplacená Rezervace', 0, 'paid', 1400, NOW());

-- Reservation 1: A1/1 and A1/2, reservation 2: B1/1-B1/4 (salonek).
INSERT INTO seat_bookings (seat_id, reservation_id, state)
    SELECT id, IF(label LIKE 'A1/%', 1, 2), 'reserved' FROM seats WHERE label IN ('A1/1', 'A1/2', 'B1/1', 'B1/2', 'B1/3', 'B1/4');

-- Tickets with fixed codes for trying the entrance check (/admin/kontrola): reservation 1 is unpaid
-- (2 seats + 1 standing), reservation 2 is paid. Print links: /vstupenka/<code>/<code repeated, lower case>.
INSERT INTO tickets (reservation_id, seat_id, code, print_token)
    SELECT t.reservation_id, s.id, t.code, t.print_token
    FROM (
        SELECT 1 AS reservation_id, 'A1/1' AS seat, 'A10101' AS code, 'a10101a10101a10101a10101a10101a1' AS print_token
        UNION ALL SELECT 1, 'A1/2', 'A10102', 'a10102a10102a10102a10102a10102a1'
        UNION ALL SELECT 1, NULL, 'A100F1', 'a100f1a100f1a100f1a100f1a100f1a1'
        UNION ALL SELECT 2, 'B1/1', 'B20501', 'b20501b20501b20501b20501b20501b2'
        UNION ALL SELECT 2, 'B1/2', 'B20502', 'b20502b20502b20502b20502b20502b2'
        UNION ALL SELECT 2, 'B1/3', 'B20503', 'b20503b20503b20503b20503b20503b2'
        UNION ALL SELECT 2, 'B1/4', 'B20504', 'b20504b20504b20504b20504b20504b2'
    ) t
    LEFT JOIN seats s ON s.label = t.seat;

-- Z-lístky of the crew with fixed codes for the entrance check (C00001-C00003; the first one is picked up).
-- Print links: /vstupenka/<code>/<code repeated, lower case>.
UPDATE settings SET value = 'Přípravy v pátek | 2. 2. | 16:00–18:30
Přípravy v sobotu | 3. 2. | 8:00–13:00
Úklid v neděli | 4. 2. | od 8:00' WHERE name = 'crew_schedule';
INSERT INTO crew_members (id, name, role, email, picked_up_at) VALUES
    (1, 'Pavla Barová', 'Bar', 'bar@example.com', NOW()),
    (2, 'Karel Kuchař', 'Kuchyně', NULL, NULL),
    (3, 'Šárka Šatnová', 'Šatna', 'satna@example.com', NULL);
INSERT INTO tickets (crew_member_id, code, print_token) VALUES
    (1, 'C00001', 'c00001c00001c00001c00001c00001c0'),
    (2, 'C00002', 'c00002c00002c00002c00002c00002c0'),
    (3, 'C00003', 'c00003c00003c00003c00003c00003c0');

-- Local administrator: login "admin", password "admin".
INSERT INTO admin_users (login, name, password_hash) VALUES
    ('admin', 'Testovací Organizátor', '$2y$12$0dzo6iQBXaEx2QIunet7P.rVyXI5H5K52wlJ/bG6umC0LijyaoSea');

UPDATE reservations SET paid_at = NOW(), paid_amount = total_price WHERE status = 'paid';
-- Links "Moje rezervace" of the test reservations: /rezervace/1/1111..., /rezervace/2/2222...
UPDATE reservations SET access_token = REPEAT(id, 32) WHERE id IN (1, 2);
