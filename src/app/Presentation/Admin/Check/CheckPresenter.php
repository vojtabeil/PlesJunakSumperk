<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Check;

use App\Model\Ticket\TicketCheck;
use App\Model\Ticket\TicketError;
use App\Presentation\Admin\BasePresenter;
use Nette\Http\IResponse;
use Nette\Http\Session;
use Nette\Utils\Json;
use Nette\Utils\JsonException;


/**
 * Entrance check for organizers on a phone: camera QR scanner (Preact app, assets/ts/check.tsx)
 * and manual input of a ticket code or reservation number.
 *   GET  /admin/kontrola[/<code>]          the app (a code from the QR link is looked up at once)
 *   GET  /admin/kontrola/api/lookup?code=  what the code means
 *   POST /admin/kontrola/api/use|useReservation|undo  (JSON body, X-CSRF-Token header)
 *   GET  /admin/kontrola/api/snapshot      all tickets for the offline mode {ok, snapshot}
 *   POST /admin/kontrola/api/sync          entries recorded offline {ok, sync, stats}
 *   GET  /admin/kontrola/sw.js             service worker of the installed app (scope /admin/kontrola)
 * Every other API response is {ok: true, result, stats} or {ok: false, error}.
 * @property-read CheckTemplate $template
 */
final class CheckPresenter extends BasePresenter
{
	private const Commands = ['use', 'useReservation', 'undo', 'sync'];


	public function __construct(
		private readonly TicketCheck $check,
		private readonly Session $session,
	) {
		parent::__construct();
	}


	protected function startup(): void
	{
		// The app calls the API with fetch(): answer in JSON instead of redirecting to the login page.
		if ($this->getAction() === 'api' && !$this->getUser()->isLoggedIn()) {
			$this->respond(IResponse::S401_Unauthorized, ['ok' => false, 'error' => 'Přihlášení vypršelo, načtěte stránku znovu.']);
		}
		parent::startup();
	}


	public function renderDefault(?string $code = null): void
	{
		// A full-screen app with its own HTML (default.latte), not the administration layout.
		$this->setLayout(false);
		$this->template->data = Json::encode([
			'api' => $this->link('api', ['op' => '__op__']),
			'csrf' => $this->csrfToken(),
			'code' => $code,
			'back' => $this->link('Dashboard:default'),
			'stats' => $this->check->entranceStats(),
			'sw' => $this->link('sw'),
			'scope' => $this->link('default'),
		], htmlSafe: true);
	}


	public function actionApi(string $op): void
	{
		$this->getHttpResponse()->setHeader('Cache-Control', 'no-store');
		$request = $this->getHttpRequest();
		try {
			if ($request->getMethod() === 'GET' && $op === 'lookup') {
				$this->respondResult($this->check->lookup((string) $request->getQuery('code')));
			}
			if ($request->getMethod() === 'GET' && $op === 'snapshot') {
				$this->respond(IResponse::S200_OK, ['ok' => true, 'snapshot' => $this->check->snapshot()]);
			}
			if ($request->getMethod() !== 'POST' || !in_array($op, self::Commands, true)) {
				$this->respond(IResponse::S404_NotFound, ['ok' => false, 'error' => 'Neznámá akce.']);
			}
			$expected = $this->session->getSection('ticketCheck')->get('csrf');
			if (!is_string($expected) || !hash_equals($expected, (string) $request->getHeader('X-CSRF-Token'))) {
				$this->respond(IResponse::S403_Forbidden, ['ok' => false, 'error' => 'Platnost stránky vypršela, načtěte ji prosím znovu.']);
			}
			try {
				$input = Json::decode($request->getRawBody() ?: '{}', forceArrays: true);
			} catch (JsonException) {
				$input = null;
			}
			if (!is_array($input)) {
				$this->respond(IResponse::S400_BadRequest, ['ok' => false, 'error' => 'Neplatný požadavek.']);
			}

			if ($op === 'sync') {
				$entries = is_array($input['entries'] ?? null) ? array_values(array_filter($input['entries'], 'is_array')) : [];
				$this->respond(IResponse::S200_OK, ['ok' => true, 'sync' => $this->check->sync($entries), 'stats' => $this->check->entranceStats()]);
			}
			$this->respondResult(match ($op) {
				'use' => $this->check->use((string) ($input['code'] ?? '')),
				'useReservation' => $this->check->useReservation((int) ($input['reservation'] ?? 0)),
				'undo' => $this->check->undo((string) ($input['code'] ?? '')),
			});
		} catch (TicketError $e) {
			$this->respond(422, ['ok' => false, 'error' => $e->getMessage()]);
		}
	}


	/**
	 * The service worker of the installed app. Served from /admin/kontrola/sw.js so that it may control
	 * /admin/kontrola (Service-Worker-Allowed), built from assets/ts/check-sw.ts.
	 */
	public function actionSw(): void
	{
		$file = dirname(__DIR__, 4) . '/www/build/check-sw.js';
		$response = $this->getHttpResponse();
		$response->setContentType('text/javascript', 'utf-8');
		$response->setHeader('Service-Worker-Allowed', $this->link('default'));
		$response->setHeader('Cache-Control', 'no-cache');
		$this->sendResponse(new \Nette\Application\Responses\TextResponse((string) @file_get_contents($file)));
	}


	/** @param array<string, mixed> $result */
	private function respondResult(array $result): never
	{
		$this->respond(IResponse::S200_OK, ['ok' => true, 'result' => $result, 'stats' => $this->check->entranceStats()]);
	}


	/** @param array<string, mixed> $payload */
	private function respond(int $code, array $payload): never
	{
		$this->getHttpResponse()->setCode($code);
		$this->sendJson($payload);
	}


	private function csrfToken(): string
	{
		$section = $this->session->getSection('ticketCheck');
		$token = $section->get('csrf');
		if (!is_string($token)) {
			$token = bin2hex(random_bytes(32));
			$section->set('csrf', $token);
		}
		return $token;
	}
}
