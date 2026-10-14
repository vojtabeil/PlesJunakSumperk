<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Payment;

use App\Model\Mail\ReservationMailer;
use App\Model\Payment\Bank\BankTransactionSource;
use App\Model\Payment\PaymentCandidates;
use App\Model\Payment\PaymentChange;
use App\Model\Payment\PaymentError;
use App\Model\Payment\PaymentImporter;
use App\Model\Payment\PaymentMatcher;
use App\Model\Payment\PaymentRepository;
use App\Presentation\Accessory\TemplateExtension;
use App\Presentation\Admin\Accessory\ImportPaymentsForm;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\Attributes\Persistent;
use Nette\Application\UI\Form;
use Nette\Application\UI\Multiplier;
use PDO;
use Throwable;


/**
 * Payments from the bank: import, and the payments to resolve first (each in its row: assign to a
 * suggested or searched reservation, or mark as not belonging to the ball); resolved ones in other tabs,
 * where a mistake can be taken back.
 * @property-read PaymentTemplate $template
 */
final class PaymentPresenter extends BasePresenter
{
	use ImportPaymentsForm;

	/** One of PaymentRepository::Filters. */
	#[Persistent]
	public string $filter = PaymentRepository::DefaultFilter;


	public function __construct(
		private readonly PaymentImporter $importer,
		private readonly PaymentMatcher $matcher,
		private readonly PaymentRepository $payments,
		private readonly ReservationMailer $mailer,
		private readonly BankTransactionSource $bankSource,
		private readonly PDO $db,
		private readonly PaymentCandidates $candidates,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$filter = isset(PaymentRepository::Filters[$this->filter]) ? $this->filter : PaymentRepository::DefaultFilter;
		$t = $this->template;
		$t->filter = $filter;
		$t->filters = array_map(static fn(array $f): string => $f[0], PaymentRepository::Filters);
		$t->summary = $this->payments->summary();
		$t->payments = $this->payments->search($filter);
		$t->suggestions = [];
		foreach ($t->payments as $payment) {
			if ($payment['reservation_id'] === null && in_array($payment['match_status'], ['unknown_vs', 'no_vs'], true)) {
				$t->suggestions[(int) $payment['id']] = $this->candidates->suggest($payment);
			}
		}
		$t->bankName = $this->bankSource->name();
		$t->waitSeconds = $this->importer->secondsUntilNextImport();
		$t->canRewind = $this->importer->canRewind();
		$t->lastImportAt = $this->importer->lastImportAt();
	}


	/** Reservations for the search box of an unmatched payment (JSON, read only). */
	public function actionReservations(string $q = ''): void
	{
		$this->sendJson(array_map(static fn(array $r): array => [
			'id' => (int) $r['id'],
			'name' => (string) $r['name'],
			'email' => (string) $r['email'],
			'status' => TemplateExtension::formatStatus((string) $r['status']),
			'total' => (int) $r['total_price'],
			'remaining' => (int) $r['remaining'],
		], $this->candidates->search($q)));
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
	 * Small form in the row of an unmatched payment: assign it to a suggested reservation (button "pick"),
	 * to a reservation found in the search box or typed by number, or mark it as not belonging to the ball.
	 * @return Multiplier<Form>
	 */
	protected function createComponentResolve(): Multiplier
	{
		return new Multiplier(function (string $transactionId): Form {
			$form = $this->formFactory->create();
			$form->addText('reservation', 'Rezervace')
				->setHtmlAttribute('placeholder', 'jméno, e-mail nebo číslo')
				->setHtmlAttribute('autocomplete', 'off')
				->setHtmlAttribute('data-reservation-search', $this->link('reservations'));
			$assign = $form->addSubmit('assign', 'Přiřadit');
			$ignore = $form->addSubmit('ignore', 'Nesouvisí s plesem');
			$ignore->setValidationScope([]);
			$form->onSuccess[] = function (Form $form, \stdClass $data) use ($transactionId, $ignore): void {
				$id = (int) $transactionId;
				$pick = (string) $form->getHttpData($form::DataLine, 'pick');
				if ($form->isSubmitted() === $ignore) {
					$this->inTransaction(function () use ($id): null {
						$this->matcher->ignore($id);
						$this->flashMessage('Platba je označená jako nesouvisející s plesem. Vrátit ji můžete v záložce „Nesouvisí s plesem“.', 'success');
						return null;
					});
				} else {
					$reservation = ctype_digit($pick) ? $pick : trim((string) $data->reservation);
					$this->inTransaction(function () use ($id, $reservation): PaymentChange {
						if (!ctype_digit($reservation) || (int) $reservation < 1) {
							throw new PaymentError('Vyberte rezervaci z nabídky, nebo zadejte její číslo.');
						}
						$change = $this->matcher->assign($id, (int) $reservation);
						$this->flashMessage("Platba je přiřazená k rezervaci č. {$change->reservationId}.", 'success');
						return $change;
					});
				}
				$this->redirect('this');
			};
			return $form;
		});
	}


	/**
	 * Taking back a mistake: a payment marked as not belonging to the ball, or assigned to a reservation,
	 * goes back to the payments to resolve.
	 * @return Multiplier<Form>
	 */
	protected function createComponentUndo(): Multiplier
	{
		return new Multiplier(function (string $transactionId): Form {
			$form = $this->formFactory->create();
			$form->addSubmit('undo', 'Vrátit k vyřízení');
			$form->onSuccess[] = function () use ($transactionId): void {
				$id = (int) $transactionId;
				$this->inTransaction(function () use ($id): null {
					$stmt = $this->db->prepare('SELECT reservation_id FROM bank_transactions WHERE id = ?');
					$stmt->execute([$id]);
					$reservationId = $stmt->fetchColumn();
					if ($reservationId) {
						$this->matcher->unassign($id);
						$this->flashMessage("Přiřazení k rezervaci č. $reservationId je zrušené, platba čeká na vyřízení.", 'success');
					} else {
						$this->matcher->unignore($id);
						$this->flashMessage('Platba je vrácená mezi platby k vyřízení.', 'success');
					}
					return null;
				});
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
