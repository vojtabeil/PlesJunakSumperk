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
use Nette\Application\UI\Multiplier;
use Nette\Forms\Controls\SubmitButton;
use PDO;
use Throwable;


/**
 * All payments from the bank: import, filters, and resolving unmatched ones right in their row.
 * @property-read PaymentTemplate $template
 */
final class PaymentPresenter extends BasePresenter
{
	use ImportPaymentsForm;

	/** One of PaymentRepository::Filters. */
	#[Persistent]
	public string $filter = '';


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
		$filter = isset(PaymentRepository::Filters[$this->filter]) ? $this->filter : '';
		$t = $this->template;
		$t->filter = $filter;
		$t->filters = array_map(static fn(array $f): string => $f[0], PaymentRepository::Filters);
		$t->summary = $this->payments->summary();
		$t->payments = $this->payments->search($filter);
		$t->bankName = $this->bankSource->name();
		$t->waitSeconds = $this->importer->secondsUntilNextImport();
		$t->canRewind = $this->importer->canRewind();
		$t->lastImportAt = $this->importer->lastImportAt();
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


	/**
	 * Small form in the row of an unmatched payment: assign it to a reservation, or mark it as not ours.
	 * @return Multiplier<Form>
	 */
	protected function createComponentResolve(): Multiplier
	{
		return new Multiplier(function (string $transactionId): Form {
			$form = $this->formFactory->create();
			$form->addInteger('reservation', 'Číslo rezervace')
				->setHtmlAttribute('placeholder', 'č. rezervace')
				->addCondition($form::Filled)
				->addRule($form::Min, 'Zadejte číslo rezervace.', 1);
			$assign = $form->addSubmit('assign', 'Přiřadit');
			$ignore = $form->addSubmit('ignore', 'Nepatří k plesu');
			$ignore->setValidationScope([]);
			$form->onSuccess[] = function (Form $form, \stdClass $data) use ($transactionId, $assign, $ignore): void {
				$id = (int) $transactionId;
				// Only a known button does something (a submit without a button name changes nothing).
				if ($form->isSubmitted() === $assign) {
					$this->inTransaction(function () use ($id, $data): PaymentChange {
						if (!$data->reservation) {
							throw new PaymentError('Zadejte číslo rezervace.');
						}
						$change = $this->matcher->assign($id, (int) $data->reservation);
						$this->flashMessage("Platba je přiřazená k rezervaci č. {$change->reservationId}.", 'success');
						return $change;
					});
				} elseif ($form->isSubmitted() === $ignore) {
					$this->inTransaction(function () use ($id): null {
						$this->matcher->ignore($id);
						$this->flashMessage('Platba je označená jako nesouvisející s plesem.', 'success');
						return null;
					});
				}
				$this->redirect('this');
			};
			return $form;
		});
	}


	/**
	 * Button in the row of an underpaid or overpaid payment: the organizer has dealt with it.
	 * @return Multiplier<Form>
	 */
	protected function createComponentSettle(): Multiplier
	{
		return new Multiplier(function (string $transactionId): Form {
			$form = $this->formFactory->create();
			$form->addSubmit('settle', 'vyřízeno');
			$form->onSuccess[] = function () use ($transactionId): void {
				$this->inTransaction(function () use ($transactionId): null {
					$this->matcher->settle((int) $transactionId);
					$this->flashMessage('Platba je označená jako vyřízená.', 'success');
					return null;
				});
				$this->redirect('this');
			};
			return $form;
		});
	}


	private function notify(PaymentChange $change): void
	{
		if ($change->isNotable() && !$this->mailer->sendPaymentUpdate($change)) {
			$this->flashMessage('E-mail o platbě se nepodařilo odeslat.', 'error');
		}
	}


	/**
	 * Runs a matcher operation in a DB transaction; PaymentError becomes a flash message.
	 * The payment e-mail goes out only after the commit.
	 * @param callable(): ?PaymentChange $operation
	 */
	private function inTransaction(callable $operation): void
	{
		$this->db->beginTransaction();
		try {
			$change = $operation();
			$this->db->commit();
		} catch (PaymentError $e) {
			$this->db->rollBack();
			$this->flashMessage($e->getMessage(), 'error');
			return;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		if ($change !== null) {
			$this->notify($change);
		}
	}
}
