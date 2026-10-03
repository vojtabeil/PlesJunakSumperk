<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Payment;

use App\Model\Mail\ReservationMailer;
use App\Model\Payment\BankTransactionSource;
use App\Model\Payment\PaymentChange;
use App\Model\Payment\PaymentError;
use App\Model\Payment\PaymentImporter;
use App\Model\Payment\PaymentMatcher;
use App\Model\Payment\PaymentRepository;
use App\Presentation\Admin\BasePresenter;
use App\Presentation\Admin\ImportPaymentsForm;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;
use PDO;
use Throwable;


/**
 * Bank payments: import from the bank, overview, manual assignment of unmatched payments.
 * @property-read PaymentTemplate $template
 */
final class PaymentPresenter extends BasePresenter
{
	use ImportPaymentsForm;

	#[Persistent]
	public bool $problems = false;


	public function __construct(
		private readonly PaymentImporter $importer,
		private readonly PaymentMatcher $matcher,
		private readonly PaymentRepository $payments,
		private readonly ReservationMailer $mailer,
		private readonly BankTransactionSource $bankSource,
		private readonly PDO $db,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->template->problems = $this->problems;
		$this->template->payments = $this->payments->search($this->problems);
		$this->template->problemCount = $this->payments->problemCount();
		$this->template->bankName = $this->bankSource->name();
		$this->template->waitSeconds = $this->importer->secondsUntilNextImport();
		$this->template->canRewind = $this->importer->canRewind();
		$this->template->lastImportAt = $this->importer->lastImportAt();
	}


	protected function paymentImporter(): PaymentImporter
	{
		return $this->importer;
	}


	/** Recovery after an interrupted import: the bank delivers movements from the given day again. */
	protected function createComponentRewindForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addDate('since', 'Stáhnout znovu pohyby od')
			->setRequired('Zadejte datum.')
			->setDefaultValue(new \DateTimeImmutable('-7 days'));
		$form->addSubmit('rewind', 'Připravit nové stažení');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			try {
				$since = \DateTimeImmutable::createFromInterface($data->since);
				$this->importer->rewind($since);
				$this->auditLog->record($this->adminId(), 'payments.rewound', null, ['since' => $since->format('Y-m-d')]);
				$this->flashMessage(sprintf(
					'Banka pošle pohyby od %s znovu. Klikněte na „Načíst platby z banky“%s; už uložené platby se nezdvojí.',
					$since->format('j. n. Y'),
					$this->importer->secondsUntilNextImport() > 0 ? ' za ' . $this->importer->secondsUntilNextImport() . ' s' : '',
				), 'success');
			} catch (PaymentError $e) {
				$this->flashMessage($e->getMessage(), 'error');
			}
			$this->redirect('this');
		};
		return $form;
	}


	protected function createComponentAssignForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addSelect('transaction', 'Platba', $this->payments->unassigned())
			->setPrompt('– vyberte platbu –')
			->setRequired('Vyberte platbu.');
		$form->addInteger('reservation', 'Číslo rezervace')
			->setRequired('Zadejte číslo rezervace.')
			->addRule($form::Min, 'Zadejte číslo rezervace.', 1);
		$form->addSubmit('assign', 'Přiřadit k rezervaci');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			$this->inTransaction(function () use ($data): void {
				$change = $this->matcher->assign((int) $data->transaction, (int) $data->reservation);
				$this->auditLog->record($this->adminId(), 'payment.assigned', $change->reservationId, ['transaction' => $data->transaction]);
				$this->notify($change);
				$this->flashMessage("Platba je přiřazená k rezervaci č. {$change->reservationId}.", 'success');
			});
		};
		return $form;
	}


	protected function createComponentIgnoreForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addSelect('transaction', 'Platba', $this->payments->unassigned())
			->setPrompt('– vyberte platbu –')
			->setRequired('Vyberte platbu.');
		$form->addSubmit('ignore', 'Ignorovat (nepatří k plesu)');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			$this->inTransaction(function () use ($data): void {
				$this->matcher->ignore((int) $data->transaction);
				$this->auditLog->record($this->adminId(), 'payment.ignored', null, ['transaction' => $data->transaction]);
				$this->flashMessage('Platba je označená jako ignorovaná.', 'success');
			});
		};
		return $form;
	}


	private function notify(PaymentChange $change): void
	{
		if ($change->isNotable() && !$this->mailer->sendPaymentUpdate($change)) {
			$this->flashMessage('E-mail o platbě se nepodařilo odeslat.', 'error');
		}
	}


	/** Runs a matcher operation in a DB transaction and redirects; PaymentError becomes a flash message. */
	private function inTransaction(callable $operation): void
	{
		$this->db->beginTransaction();
		try {
			$operation();
			$this->db->commit();
		} catch (PaymentError $e) {
			$this->db->rollBack();
			$this->flashMessage($e->getMessage(), 'error');
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		$this->redirect('this');
	}
}
