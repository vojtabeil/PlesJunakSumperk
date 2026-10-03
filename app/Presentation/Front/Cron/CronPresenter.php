<?php

declare(strict_types=1);

namespace App\Presentation\Front\Cron;

use App\Model\Admin\AuditLog;
use App\Model\Payment\PaymentError;
use App\Model\Payment\PaymentImporter;
use App\Model\Payment\PaymentRepository;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Http\IResponse;


/**
 * Endpoint for a scheduler (hosting cron or an external service such as cron-job.org):
 *   GET /cron/payments?key=<cron.key>
 * Protected by the secret key instead of a login; returns 404 while no key is configured.
 */
final class CronPresenter extends Presenter
{
	/** @param array{key?: string} $config */
	public function __construct(
		private readonly array $config,
		private readonly PaymentImporter $importer,
		private readonly PaymentRepository $payments,
		private readonly AuditLog $auditLog,
	) {
		parent::__construct();
	}


	public function actionPayments(): void
	{
		$expected = (string) ($this->config['key'] ?? '');
		$given = (string) ($this->getParameter('key') ?? $this->getHttpRequest()->getHeader('X-Cron-Key') ?? '');
		if ($expected === '' || !hash_equals($expected, $given)) {
			$this->error();
		}

		$response = $this->getHttpResponse();
		$response->setContentType('text/plain', 'UTF-8');
		$response->setHeader('Cache-Control', 'no-store');
		try {
			$result = $this->importer->import();
			if ($result->fetched > 0) {
				$this->auditLog->record(null, 'payments.imported', null, (array) $result + ['by' => 'cron']);
			}
			$text = $result->summary() . "\nPlateb k vyřešení: " . $this->payments->problemCount() . "\n";
		} catch (PaymentError $e) {
			$response->setCode(IResponse::S503_ServiceUnavailable);
			$text = 'Chyba: ' . $e->getMessage() . "\n";
		}
		$this->sendResponse(new TextResponse($text));
	}
}
