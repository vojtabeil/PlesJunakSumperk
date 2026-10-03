<?php
// Confirmation shown after a successful reservation (only to the browser that made it).

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

start_session();
$service = reservation_service();

$id = (int) ($_GET['id'] ?? 0);
$reservation = in_array($id, $_SESSION['finished_reservations'] ?? [], true) ? $service->findFinished($id) : null;
if (!$reservation) {
    header('Location: ./');
    exit;
}

$ticketCount = count($reservation['seat_labels']) + (int) $reservation['standing_tickets'];

render_header('Rezervace přijata', $service);
?>
<section class="panel done">
    <h2>Rezervace přijata</h2>
    <p>Děkujeme, <?= h($reservation['name']) ?>. Vaše rezervace č. <strong><?= $id ?></strong> je uložena.</p>

    <dl class="totals">
        <dt>E-mail</dt><dd><?= h($reservation['email']) ?></dd>
        <dt>Místa u stolu</dt>
        <dd><?= $reservation['seat_labels'] ? h(implode('; ', array_map('format_seat_label', $reservation['seat_labels']))) : '–' ?></dd>
        <dt>Bez místenky</dt><dd><?= (int) $reservation['standing_tickets'] ?></dd>
        <dt>Celkem lístků</dt><dd><?= $ticketCount ?></dd>
        <dt>K zaplacení</dt><dd><strong><?= h(format_price((int) $reservation['total_price'])) ?></strong></dd>
        <dt>Variabilní symbol</dt><dd><?= $id ?></dd>
    </dl>

    <?php if ($reservation['email_sent_at']): ?>
        <p class="message">Potvrzení jsme poslali na <?= h($reservation['email']) ?>.</p>
    <?php else: ?>
        <p class="message message--error">Potvrzovací e-mail se nepodařilo odeslat. Rezervace je ale uložená, údaje si prosím poznamenejte.</p>
    <?php endif; ?>
    <?php // TODO: payment instructions (bank account / QR payment). ?>
    <p>Pokyny k platbě zatím nejsou k dispozici.</p>

    <p><a class="button" href="./">Zpět na úvod</a></p>
</section>
<?php render_footer();
