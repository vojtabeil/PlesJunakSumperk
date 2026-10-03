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
    ('event_date', ''),
    ('venue', 'Kulturní dům, Nový Malín'),
    ('venue_url', 'https://maps.app.goo.gl/aJU2PTvT3JSvg51V9'),
    ('band', 'Lucky Band'),
    ('band_url', 'https://www.luckyband.cz/'),
    ('organizer', ''),
    ('bank_account', '2501895120/2010'),
    -- Days to pay after the reservation (shown to visitors, in the QR code, overdue in the admin).
    ('payment_days', '2'),
    ('contact_email', ''),
    ('contact_phone', ''),
    ('payment_vs_prefix', '2026'),
    ('max_ticket', '10'),
    ('hold_seconds', '300'),
    ('price_seat', '350'),
    ('price_standing', '250'),
    ('standing_capacity', '50'),
    ('map_width', '1000'),
    ('map_height', '640');
