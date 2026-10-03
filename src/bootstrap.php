<?php
// Shared setup for every entry point in public/: config, database, session, helpers.

declare(strict_types=1);

require __DIR__ . '/ReservationService.php';
require __DIR__ . '/Mailer.php';
require __DIR__ . '/emails.php';
require __DIR__ . '/layout.php';

const ROOT_DIR = __DIR__ . '/..';

function config(): array
{
    static $config;
    if ($config === null) {
        $local = ROOT_DIR . '/config.local.php';
        $config = require (is_file($local) ? $local : ROOT_DIR . '/config.example.php');
    }
    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $c = config()['db'];
        $pdo = new PDO(
            "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}",
            $c['user'],
            $c['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    return $pdo;
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('ples_session');
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    // Random owner key binding a draft reservation to this browser; unlike session_id()
    // it survives session id regeneration.
    if (empty($_SESSION['owner'])) {
        $_SESSION['owner'] = bin2hex(random_bytes(16));
    }
}

function csrf_token(): string
{
    return $_SESSION['csrf_token'];
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function format_price(int $amount): string
{
    return number_format($amount, 0, ',', "\u{00A0}") . "\u{00A0}Kč";
}

/** "3/5" -> "stůl 3, místo 5" */
function format_seat_label(string $label): string
{
    [$table, $seat] = array_pad(explode('/', $label, 2), 2, '');
    return "stůl $table, místo $seat";
}

function mailer(): Mailer
{
    static $mailer;
    if ($mailer === null) {
        $mailer = new Mailer(config()['mail'] ?? []);
    }
    return $mailer;
}

/**
 * Sends the confirmation e-mail and records the outcome. Never throws: a failed e-mail
 * must not undo a reservation that is already stored.
 */
function send_reservation_confirmation(ReservationService $service, int $reservationId): bool
{
    $error = null;
    try {
        $reservation = $service->findFinished($reservationId);
        if (!$reservation) {
            throw new RuntimeException("Reservation $reservationId not found.");
        }
        $email = reservation_confirmation_email($reservation, $service->settings());
        mailer()->send($reservation['email'], $email['subject'], $email['html'], $email['text']);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        error_log("Confirmation e-mail for reservation $reservationId failed: $error");
    }
    try {
        $service->recordEmailResult($reservationId, $error);
    } catch (Throwable $e) {
        error_log("Recording e-mail result for reservation $reservationId failed: " . $e->getMessage());
    }
    return $error === null;
}

function reservation_service(): ReservationService
{
    static $service;
    if ($service === null) {
        $service = new ReservationService(db(), $_SESSION['owner']);
    }
    return $service;
}
