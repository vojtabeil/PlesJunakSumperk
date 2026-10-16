-- Default settings for a new installation (local and production).
-- The site starts in the testing stage; organizers switch the stages in the administration (Stav webu).

INSERT INTO settings (name, value) VALUES
    -- testing | vip | public | closed | after (App\Model\Reservation\SiteMode)
    ('site_mode', 'testing'),
    -- Secret parts of the tester and VIP links, created on first use.
    ('tester_token', ''),
    ('vip_token', ''),
    -- Pages (HTML) for visitors who cannot buy in the given stage.
    ('page_testing', '<h2>Připravujeme prodej lístků</h2>\n<p>Rezervaci lístků na ples právě chystáme. Prodej spustíme v nejbližších dnech – datum oznámíme na našem webu a sociálních sítích.</p>'),
    ('page_vip', '<h2>Prodej lístků brzy začne</h2>\n<p>Veřejný prodej lístků začne <strong>[doplňte datum a čas]</strong>. Pak si tu vyberete místa v sále a zaplatíte převodem nebo QR kódem.</p>'),
    ('page_closed', '<h2>Prodej lístků skončil</h2>\n<p>Lístky jsou vyprodané nebo prodej skončil. Máte-li rezervaci, platební údaje a její stav najdete přes odkaz v potvrzovacím e-mailu.</p>'),
    ('page_after', '<h2>Děkujeme, že jste přišli!</h2>\n<p>Ples skončil a my děkujeme všem hostům, kapele i pomocníkům. Těšíme se na vás zase příští rok.</p>'),
    ('event_name', 'Šumperský skautský ples'),
    ('event_intro', 'Šumperští skauti si vás dovolují pozvat do víru tance a zábavy. Chybět nebude ani tradičně vynikající občerstvení, klasická i skautská tombola a bohatý program.'),
    -- Date of the ball (YYYY-MM-DD) and its start (HH:MM, empty = not shown).
    ('event_day', '2027-02-06'),
    ('event_time', ''),
    ('venue', 'Kulturní dům Rapotín'),
    ('venue_url', 'https://mapy.com/s/bemakumuna'),
    ('band', 'Lucky Band'),
    ('band_url', 'https://www.luckyband.cz/'),
    ('organizer', 'Lukáš Rozsíval'),
    ('bank_account', '2501895120/2010'),
    -- Days to pay after the reservation (shown to visitors, in the QR code, overdue in the admin).
    ('payment_days', '2'),
    ('contact_email', ''),
    ('contact_phone', ''),
    ('payment_vs_prefix', '2026'),
    ('max_ticket', '10'),
    -- How long the seats a visitor chose stay held for them (15 minutes).
    ('hold_seconds', '900'),
    ('price_seat', '350'),
    ('price_standing', '250'),
    ('standing_capacity', '50'),
    -- Timetable printed on Z-lístky and sent with them: one line per item, e.g. "Přípravy v pátek | 16:00–18:30".
    ('crew_schedule', '');
