-- Test data for local development (loaded after defaults.sql and hall.sql). Never load into production.
-- Event info comes from the recovered 2024 landing page; prices and capacities are made up.

UPDATE settings SET value = '1' WHERE name = 'sale_open';
UPDATE settings SET value = '3. 2. 2024 od 19:00' WHERE name = 'event_date';
UPDATE settings SET value = 'Tomáš Slavický' WHERE name = 'organizer';
UPDATE settings SET value = 'Děkujeme za Vaši účast na našem plese.' WHERE name = 'closed_message';

INSERT INTO reservations (id, email, name, standing_tickets, status, total_price, confirmed_at) VALUES
    (1, 'test@example.com', 'Testovací Rezervace', 1, 'confirmed', 950, NOW()),
    (2, 'platba@example.com', 'Zaplacená Rezervace', 0, 'paid', 1400, NOW());

UPDATE seats SET state = 'reserved', reservation_id = 1 WHERE id IN (101, 102);
UPDATE seats SET state = 'reserved', reservation_id = 2 WHERE id IN (501, 502, 503, 504);

-- Local administrator: login "admin", password "admin".
INSERT INTO admin_users (login, name, password_hash) VALUES
    ('admin', 'Testovací Organizátor', '$2y$12$0dzo6iQBXaEx2QIunet7P.rVyXI5H5K52wlJ/bG6umC0LijyaoSea');

UPDATE reservations SET paid_at = NOW(), paid_amount = total_price WHERE status = 'paid';
