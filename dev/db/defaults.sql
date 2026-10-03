-- Default settings for a new installation (local and production).
-- The sale starts closed; organizers fill in the event and open it in the administration.

INSERT INTO settings (name, value) VALUES
    ('sale_open', '0'),
    -- testers = only visitors with the tester link see the site (a new installation starts so).
    ('public_access', 'testers'),
    ('tester_token', ''),
    ('closed_message', 'Prodej lístků zatím nezačal.'),
    ('event_name', 'Šumperský skautský ples'),
    ('event_intro', 'Šumperští skauti si vás dovolují pozvat do víru tance a zábavy. Chybět nebude ani tradičně vynikající občerstvení, klasická i skautská tombola a bohatý program.'),
    ('event_date', ''),
    ('venue', 'Kulturní dům, Nový Malín'),
    ('venue_url', 'https://maps.app.goo.gl/aJU2PTvT3JSvg51V9'),
    ('band', 'Lucky Band'),
    ('band_url', 'https://www.luckyband.cz/'),
    ('organizer', ''),
    ('bank_account', '2501895120/2010'),
    ('payment_vs_prefix', '2026'),
    ('max_ticket', '10'),
    ('hold_seconds', '120'),
    ('price_seat', '350'),
    ('price_standing', '250'),
    ('standing_capacity', '50'),
    ('map_width', '1000'),
    ('map_height', '640');
