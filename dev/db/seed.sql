-- Test data for local development. Never load into production.

INSERT INTO settings (name, value) VALUES
    ('max_ticket', '10'),
    ('hold_seconds', '120'),
    ('event_name', 'Šumperský skautský ples');

-- 12 tables with 8 seats each (id = table * 100 + seat, e.g. 305 = table 3, seat 5).
INSERT INTO seats (id, label, table_no)
WITH RECURSIVE
    t (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM t WHERE n < 12),
    s (n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM s WHERE n < 8)
SELECT t.n * 100 + s.n, CONCAT(t.n, '/', s.n), t.n
FROM t CROSS JOIN s;

INSERT INTO reservations (id, email, standing_tickets, status) VALUES
    (1, 'test@example.com', 1, 'confirmed'),
    (2, 'jan.novak@example.com', 0, 'draft');

UPDATE seats SET state = 'reserved', reservation_id = 1 WHERE id IN (101, 102);
UPDATE seats SET state = 'book', reservation_id = 2, booked_at = NOW() WHERE id = 205;
