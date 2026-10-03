-- Test data for local development. Never load into production.
-- Event info comes from the recovered 2024 landing page; prices and capacities are made up.

INSERT INTO settings (name, value) VALUES
    ('sale_open', '1'),
    ('closed_message', 'Děkujeme za Vaši účast na našem plese.'),
    ('event_name', 'Šumperský skautský ples'),
    ('event_intro', 'Šumperští skauti si vás dovolují pozvat do víru tance a zábavy. Chybět nebude ani tradičně vynikající občerstvení, klasická i skautská tombola a bohatý program.'),
    ('event_date', '3. 2. 2024 od 19:00'),
    ('venue', 'Kulturní dům, Nový Malín'),
    ('venue_url', 'https://maps.app.goo.gl/aJU2PTvT3JSvg51V9'),
    ('band', 'Lucky Band'),
    ('band_url', 'https://www.luckyband.cz/'),
    ('organizer', 'Tomáš Slavický'),
    ('bank_account', '2501895120/2010'),
    ('max_ticket', '10'),
    ('hold_seconds', '120'),
    ('price_seat', '350'),
    ('price_standing', '250'),
    ('standing_capacity', '50'),
    ('map_width', '1000'),
    ('map_height', '640');

-- 12 tables of 160 x 40 around the dance floor: 4 on the left, 4 on the right, 4 at the bottom.
INSERT INTO hall_tables (id, label, x, y, width, height) VALUES
    (1,  '1',  40,  110, 160, 40),
    (2,  '2',  40,  210, 160, 40),
    (3,  '3',  40,  310, 160, 40),
    (4,  '4',  40,  410, 160, 40),
    (5,  '5',  800, 110, 160, 40),
    (6,  '6',  800, 210, 160, 40),
    (7,  '7',  800, 310, 160, 40),
    (8,  '8',  800, 410, 160, 40),
    (9,  '9',  170, 540, 160, 40),
    (10, '10', 350, 540, 160, 40),
    (11, '11', 530, 540, 160, 40),
    (12, '12', 710, 540, 160, 40);

-- 8 seats per table: 1-4 along the top edge, 5-8 along the bottom edge.
-- id = table * 100 + seat (e.g. 305 = table 3, seat 5).
INSERT INTO seats (id, label, table_id, x, y)
WITH RECURSIVE s (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 8)
SELECT
    t.id * 100 + s.n,
    CONCAT(t.label, '/', s.n),
    t.id,
    t.x + 20 + ((s.n - 1) % 4) * 40,
    CASE WHEN s.n <= 4 THEN t.y - 14 ELSE t.y + t.height + 14 END
FROM hall_tables t CROSS JOIN s;

INSERT INTO reservations (id, email, name, standing_tickets, status, total_price, confirmed_at) VALUES
    (1, 'test@example.com', 'Testovací Rezervace', 1, 'confirmed', 950, NOW()),
    (2, 'platba@example.com', 'Zaplacená Rezervace', 0, 'paid', 1400, NOW());

UPDATE seats SET state = 'reserved', reservation_id = 1 WHERE id IN (101, 102);
UPDATE seats SET state = 'reserved', reservation_id = 2 WHERE id IN (501, 502, 503, 504);

-- Local administrator: login "admin", password "admin".
INSERT INTO admin_users (login, name, password_hash) VALUES
    ('admin', 'Testovací Organizátor', '$2y$12$0dzo6iQBXaEx2QIunet7P.rVyXI5H5K52wlJ/bG6umC0LijyaoSea');

UPDATE reservations SET paid_at = NOW(), paid_amount = total_price WHERE status = 'paid';
