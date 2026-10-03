<?php
// JSON API for the reservation page (public/assets/app.js).
//   GET  api.php?action=state
//   POST api.php?action=start|hold|release|standing|confirm|cancel  (JSON body, X-CSRF-Token header)
// Every response is {ok: true, state: {...}} or {ok: false, error: "<message for the user>"}.

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    start_session();
    $action = $_GET['action'] ?? '';
    $service = reservation_service();
    $service->releaseExpiredHolds();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Reading does not need the session lock; let parallel requests through.
        session_write_close();
        if ($action !== 'state') {
            respond(404, ['ok' => false, 'error' => 'Neznámá akce.']);
        }
        respond(200, ['ok' => true, 'state' => $service->state()]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'Nepovolená metoda.']);
    }
    if (!hash_equals(csrf_token(), $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        respond(403, ['ok' => false, 'error' => 'Platnost stránky vypršela, načtěte ji prosím znovu.']);
    }

    $input = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($input)) {
        respond(400, ['ok' => false, 'error' => 'Neplatný požadavek.']);
    }

    $extra = [];
    switch ($action) {
        case 'start':
            $service->start((string) ($input['email'] ?? ''));
            break;
        case 'hold':
            $service->hold((int) ($input['seat_id'] ?? 0));
            break;
        case 'release':
            $service->release((int) ($input['seat_id'] ?? 0));
            break;
        case 'standing':
            $service->setStanding((int) ($input['count'] ?? 0));
            break;
        case 'confirm':
            $id = $service->confirm(
                (string) ($input['name'] ?? ''),
                (string) ($input['phone'] ?? ''),
                ($input['consent'] ?? false) === true
            );
            $_SESSION['finished_reservations'][] = $id;
            send_reservation_confirmation($service, $id);
            $extra['redirect'] = 'done.php?id=' . $id;
            break;
        case 'cancel':
            $service->cancel();
            break;
        default:
            respond(404, ['ok' => false, 'error' => 'Neznámá akce.']);
    }

    respond(200, ['ok' => true, 'state' => $service->state()] + $extra);
} catch (ReservationError $e) {
    $state = isset($service) ? $service->state() : null;
    respond(422, ['ok' => false, 'error' => $e->getMessage(), 'state' => $state]);
} catch (Throwable $e) {
    error_log('api.php: ' . $e);
    $message = (config()['debug'] ?? false) ? $e->getMessage() : 'Něco se pokazilo, zkuste to prosím znovu.';
    respond(500, ['ok' => false, 'error' => $message]);
}
