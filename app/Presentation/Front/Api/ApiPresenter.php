<?php

declare(strict_types=1);

namespace App\Presentation\Front\Api;

use App\Model\Log\Actor;
use App\Model\Mail\ReservationMailer;
use App\Model\Reservation\ReservationError;
use App\Model\Reservation\ReservationService;
use App\Model\Reservation\ReservationSession;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\UI\Presenter;
use Nette\Http\IResponse;
use Nette\Utils\Json;
use Nette\Utils\JsonException;
use Throwable;
use Tracy\Debugger;
use Tracy\ILogger;


/**
 * JSON API of the seat picker.
 *   GET  /api/state
 *   POST /api/start|hold|release|standing|confirm|cancel  (JSON body, X-CSRF-Token header)
 * Every response is {ok: true, state: {...}} or {ok: false, error: "<message for the user>"}.
 */
final class ApiPresenter extends Presenter
{
	private const Commands = ['start', 'hold', 'release', 'standing', 'confirm', 'cancel'];


	public function __construct(
		private readonly ReservationService $reservations,
		private readonly ReservationSession $reservationSession,
		private readonly ReservationMailer $mailer,
		private readonly ILogger $logger,
		Actor $actor,
	) {
		parent::__construct();
		$actor->asCustomer();
	}


	public function actionDefault(string $op): void
	{
		$this->getHttpResponse()->setHeader('Cache-Control', 'no-store');
		$owner = $this->reservationSession->owner();

		try {
			$this->reservations->releaseExpiredHolds();
			$method = $this->getHttpRequest()->getMethod();

			if ($method === 'GET' && $op === 'state') {
				$this->respond(IResponse::S200_OK, ['ok' => true, 'state' => $this->reservations->state($owner)]);
			}
			if ($method !== 'POST' || !in_array($op, self::Commands, true)) {
				$this->respond(IResponse::S404_NotFound, ['ok' => false, 'error' => 'Neznámá akce.']);
			}
			if (!$this->reservationSession->isValidCsrfToken((string) $this->getHttpRequest()->getHeader('X-CSRF-Token'))) {
				$this->respond(IResponse::S403_Forbidden, ['ok' => false, 'error' => 'Platnost stránky vypršela, načtěte ji prosím znovu.']);
			}

			try {
				$input = Json::decode($this->getHttpRequest()->getRawBody() ?: '{}', forceArrays: true);
			} catch (JsonException) {
				$input = null;
			}
			if (!is_array($input)) {
				$this->respond(IResponse::S400_BadRequest, ['ok' => false, 'error' => 'Neplatný požadavek.']);
			}

			$extra = [];
			try {
				switch ($op) {
					case 'start':
						$this->reservations->start($owner, (string) ($input['email'] ?? ''));
						break;
					case 'hold':
						$this->reservations->hold($owner, (int) ($input['seat_id'] ?? 0));
						break;
					case 'release':
						$this->reservations->release($owner, (int) ($input['seat_id'] ?? 0));
						break;
					case 'standing':
						$this->reservations->setStanding($owner, (int) ($input['count'] ?? 0));
						break;
					case 'confirm':
						$id = $this->reservations->confirm(
							$owner,
							(string) ($input['name'] ?? ''),
							(string) ($input['phone'] ?? ''),
							($input['consent'] ?? false) === true,
						);
						$this->reservationSession->addFinished($id);
						$this->mailer->sendConfirmation($id);
						$extra['redirect'] = $this->link('Done:default', ['id' => $id]);
						break;
					case 'cancel':
						$this->reservations->cancel($owner);
						break;
				}
			} catch (ReservationError $e) {
				$this->respond(422, ['ok' => false, 'error' => $e->getMessage(), 'state' => $this->reservations->state($owner)]);
			}

			$this->respond(IResponse::S200_OK, ['ok' => true, 'state' => $this->reservations->state($owner)] + $extra);

		} catch (\Nette\Application\AbortException $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->log($e, ILogger::EXCEPTION);
			$message = Debugger::$productionMode ? 'Něco se pokazilo, zkuste to prosím znovu.' : $e->getMessage();
			$this->respond(IResponse::S500_InternalServerError, ['ok' => false, 'error' => $message]);
		}
	}


	/** @param array<string, mixed> $body */
	private function respond(int $code, array $body): never
	{
		$this->getHttpResponse()->setCode($code);
		$this->sendResponse(new JsonResponse($body));
	}
}
