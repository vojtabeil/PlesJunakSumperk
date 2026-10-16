<?php

declare(strict_types=1);

namespace App\Presentation\Cron;

use App\Model\Log\Actor;
use App\Model\Payment\PaymentError;
use App\Model\Payment\PaymentImporter;
use App\Model\Payment\PaymentRepository;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Http\IResponse;


/**
 * Endpoint for a scheduler (hosting cron or an external service such as cron-job.org):
 *   GET /cron/payments with the header "X-Cron-Key: <cron.key>"
 * Protected by the secret key instead of a login. The key belongs in the header: a query parameter (?key=, still
 * accepted for schedulers without custom headers) ends up in access logs and Tracy logs. Returns 404 while no key
 * is configured or the configured one is shorter than MinKeyLength.
 */
final class CronPresenter extends Presenter
{
	public const MinKeyLength = 32;


	/** @param array{key?: string} $config */
	public function __construct(
		private readonly array $config,
		private readonly PaymentImporter $importer,
		private readonly PaymentRepository $payments,
		private readonly Actor $actor,
	) {
		parent::__construct();
	}


	public function actionPayments(): void
	{
		$expected = (string) ($this->config['key'] ?? '');
		$given = (string) ($this->getHttpRequest()->getHeader('X-Cron-Key') ?? $this->getParameter('key') ?? '');
		if (!self::keyMatches($expected, $given)) {
			$this->error();
		}

		$response = $this->getHttpResponse();
		$response->setContentType('text/plain', 'UTF-8');
		$response->setHeader('Cache-Control', 'no-store');
		try {
			$this->actor->asCron();
			$result = $this->importer->import();
			$text = $result->summary() . "\nPlateb k vyřešení: " . $this->payments->problemCount() . "\n";
		} catch (PaymentError $e) {
			$response->setCode(IResponse::S503_ServiceUnavailable);
			$text = 'Chyba: ' . $e->getMessage() . "\n";
		}
		$this->sendResponse(new TextResponse($text));
	}


	/** The configured key is long enough (a short one counts as not set) and the given one equals it. */
	public static function keyMatches(string $expected, string $given): bool
	{
		return strlen($expected) >= self::MinKeyLength && hash_equals($expected, $given);
	}
}