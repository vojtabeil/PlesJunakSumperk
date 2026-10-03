<?php
// Reservation page: e-mail -> pick seats on the hall map and/or standing tickets -> confirm.
// The interactive part is driven by assets/app.js through api.php.

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

start_session();
$service = reservation_service();
$service->releaseExpiredHolds();

// Static decorations of the hall map (map units, same as hall_tables).
const HALL_STAGE = ['x' => 350, 'y' => 18, 'width' => 300, 'height' => 56, 'label' => 'Pódium'];
const HALL_FLOOR = ['x' => 250, 'y' => 110, 'width' => 500, 'height' => 380, 'label' => 'Parket'];

$hall = $service->hallLayout();
$pct = static fn (int $value, int $total): string => rtrim(rtrim(number_format($value / $total * 100, 3, '.', ''), '0'), '.') . '%';

render_header($service->setting('event_name', 'Skautský ples'), $service);
?>

<?php if (!$service->isSaleOpen()): ?>
    <section class="panel closed">
        <h2>Prodej lístků je uzavřený</h2>
        <p><?= h($service->setting('closed_message')) ?></p>
    </section>
<?php else: ?>
    <section class="panel" id="reservation" aria-labelledby="reservation-title">
        <h2 id="reservation-title">Rezervace lístků</h2>

        <noscript><p class="message message--error">Rezervace vyžaduje zapnutý JavaScript.</p></noscript>

        <ol class="steps">
            <li>Zadejte svůj e-mail.</li>
            <li>Vyberte místa v plánku sálu nebo lístky bez místenky.</li>
            <li>Vyplňte jméno a rezervaci potvrďte.</li>
        </ol>

        <form id="email-form" class="email-form" novalidate>
            <label for="email">E-mail</label>
            <div class="inline-field">
                <input type="email" id="email" name="email" autocomplete="email" required maxlength="255" placeholder="jmeno@example.cz">
                <button type="submit" class="button">Pokračovat</button>
            </div>
        </form>

        <p id="message" class="message" role="status" aria-live="polite"></p>

        <div id="picker" class="picker" hidden>
            <div class="picker-map">
                <ul class="legend" aria-label="Legenda">
                    <li><span class="swatch swatch--free"></span> volné</li>
                    <li><span class="swatch swatch--mine"></span> vaše</li>
                    <li><span class="swatch swatch--taken"></span> obsazené</li>
                </ul>
                <div class="map-scroll">
                    <div class="map" style="aspect-ratio: <?= $hall['width'] ?> / <?= $hall['height'] ?>">
                        <svg class="map-plan" viewBox="0 0 <?= $hall['width'] ?> <?= $hall['height'] ?>" aria-hidden="true">
                            <?php foreach ([HALL_STAGE, HALL_FLOOR] as $i => $area): ?>
                                <rect class="<?= $i === 0 ? 'plan-stage' : 'plan-floor' ?>" x="<?= $area['x'] ?>" y="<?= $area['y'] ?>" width="<?= $area['width'] ?>" height="<?= $area['height'] ?>" rx="8"/>
                                <text class="plan-label" x="<?= $area['x'] + $area['width'] / 2 ?>" y="<?= $area['y'] + $area['height'] / 2 ?>"><?= h($area['label']) ?></text>
                            <?php endforeach; ?>
                            <?php foreach ($hall['tables'] as $t): ?>
                                <rect class="plan-table" x="<?= $t['x'] ?>" y="<?= $t['y'] ?>" width="<?= $t['width'] ?>" height="<?= $t['height'] ?>" rx="4"/>
                                <text class="plan-table-label" x="<?= $t['x'] + $t['width'] / 2 ?>" y="<?= $t['y'] + $t['height'] / 2 ?>">Stůl <?= h($t['label']) ?></text>
                            <?php endforeach; ?>
                        </svg>
                        <?php foreach ($hall['seats'] as $seat):
                            [$tableLabel, $seatNo] = array_pad(explode('/', $seat['label'], 2), 2, ''); ?>
                            <button type="button" class="seat" data-seat="<?= (int) $seat['id'] ?>"
                                    style="left: <?= $pct((int) $seat['x'], $hall['width']) ?>; top: <?= $pct((int) $seat['y'], $hall['height']) ?>"
                                    aria-label="Stůl <?= h($tableLabel) ?>, místo <?= h($seatNo) ?>" aria-pressed="false" disabled><?= h($seatNo) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <p class="hint">Na telefonu lze plánkem posouvat do stran.</p>
            </div>

            <aside class="summary" aria-labelledby="summary-title">
                <h3 id="summary-title">Vaše rezervace</h3>
                <p class="summary-email">E-mail: <strong id="summary-email"></strong>
                    <button type="button" id="cancel" class="link-button">změnit</button></p>

                <div class="standing">
                    <span id="standing-label">Lístky bez místenky</span>
                    <div class="stepper" role="group" aria-labelledby="standing-label">
                        <button type="button" class="stepper-button" data-step="-1" aria-label="Ubrat lístek bez místenky">−</button>
                        <output id="standing-count" aria-live="polite">0</output>
                        <button type="button" class="stepper-button" data-step="1" aria-label="Přidat lístek bez místenky">+</button>
                    </div>
                </div>

                <dl class="totals">
                    <dt>Místa u stolu</dt><dd id="summary-seats">–</dd>
                    <dt>Celkem lístků</dt><dd id="summary-count">0</dd>
                    <dt>Cena</dt><dd id="summary-price">0 Kč</dd>
                </dl>
                <p class="prices">Místenka <?= h(format_price($service->intSetting('price_seat'))) ?>,
                    bez místenky <?= h(format_price($service->intSetting('price_standing'))) ?>.
                    Nejvýše <?= $service->intSetting('max_ticket', 10) ?> lístků na rezervaci.</p>
                <p id="countdown" class="countdown" hidden></p>

                <form id="confirm-form" class="confirm-form" novalidate>
                    <label for="name">Jméno a příjmení</label>
                    <input type="text" id="name" name="name" autocomplete="name" required maxlength="255">
                    <label for="phone">Telefon <span class="optional">(nepovinné)</span></label>
                    <input type="tel" id="phone" name="phone" autocomplete="tel" maxlength="20">
                    <label class="checkbox">
                        <input type="checkbox" id="consent" name="consent" required>
                        Souhlasím se zpracováním osobních údajů pro účely rezervace.
                    </label>
                    <button type="submit" class="button button--primary" id="confirm-button">Závazně rezervovat</button>
                </form>
            </aside>
        </div>
    </section>
    <script src="assets/app.js?v=<?= h((string) filemtime(__DIR__ . '/assets/app.js')) ?>" defer></script>
<?php endif; ?>

<?php render_footer();
