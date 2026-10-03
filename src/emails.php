<?php
// E-mail templates. Each returns ['subject' => ..., 'html' => ..., 'text' => ...].
// Texts are for end users, so they are in Czech.

declare(strict_types=1);

/**
 * @param array $reservation Row from ReservationService::findFinished()
 * @param array $settings    ReservationService::settings()
 */
function reservation_confirmation_email(array $reservation, array $settings): array
{
    $id = (int) $reservation['id'];
    $event = $settings['event_name'] ?? 'Skautský ples';
    $seats = array_map('format_seat_label', $reservation['seat_labels']);
    $standing = (int) $reservation['standing_tickets'];
    $total = format_price((int) $reservation['total_price']);

    $rows = [
        'Číslo rezervace' => (string) $id,
        'Jméno' => $reservation['name'],
        'Místa u stolu' => $seats ? implode('; ', $seats) : '–',
        'Lístky bez místenky' => (string) $standing,
        'Celkem lístků' => (string) (count($seats) + $standing),
        'K zaplacení' => $total,
        'Variabilní symbol' => (string) $id,
    ];
    $eventRows = [
        'Kdy' => $settings['event_date'] ?? '',
        'Kde' => $settings['venue'] ?? '',
    ];
    // TODO: replace with real payment instructions (account number, QR code) once payments exist.
    $paymentNote = 'Pokyny k platbě vám pošleme v samostatném e-mailu.';
    $organizer = $settings['organizer'] ?? '';

    $text = "Dobrý den,\n\n"
        . "děkujeme za rezervaci lístků na akci $event. Rezervaci jsme uložili:\n\n";
    foreach ($rows + $eventRows as $label => $value) {
        $text .= "$label: $value\n";
    }
    $text .= "\n$paymentNote\n\nTěšíme se na vás!\n" . ($organizer !== '' ? "$organizer, organizátor\n" : '');

    $htmlRows = '';
    foreach ($rows + $eventRows as $label => $value) {
        $htmlRows .= '<tr><td style="padding:4px 16px 4px 0;color:#5c5c57">' . h($label) . '</td>'
            . '<td style="padding:4px 0;font-weight:bold">' . h($value) . '</td></tr>';
    }
    $html = '<!DOCTYPE html><html lang="cs"><head><meta charset="UTF-8"><title>' . h($event) . '</title></head>'
        . '<body style="margin:0;padding:24px;background:#f7f3ea;font-family:Arial,Helvetica,sans-serif;color:#1d1d1b">'
        . '<div style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #ddd5c6;border-radius:10px;padding:24px">'
        . '<h1 style="margin:0 0 16px;font-size:24px;color:#287234">' . h($event) . '</h1>'
        . '<p>Dobrý den,</p>'
        . '<p>děkujeme za rezervaci lístků. Rezervaci jsme uložili:</p>'
        . '<table style="border-collapse:collapse;margin:16px 0">' . $htmlRows . '</table>'
        . '<p>' . h($paymentNote) . '</p>'
        . '<p>Těšíme se na vás!' . ($organizer !== '' ? '<br>' . h($organizer) . ', organizátor' : '') . '</p>'
        . '</div></body></html>';

    return [
        'subject' => "Potvrzení rezervace č. $id – $event",
        'html' => $html,
        'text' => $text,
    ];
}
