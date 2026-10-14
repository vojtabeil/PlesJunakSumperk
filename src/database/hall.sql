-- Demo hall layout (local and production) until the real layout of the venue is known.
-- Coordinates are map units of settings map_width x map_height.

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
