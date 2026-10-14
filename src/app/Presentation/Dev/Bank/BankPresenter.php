<?php

declare(strict_types=1);

namespace App\Presentation\Dev\Bank;

use App\Model\Payment\BankTransactionSource;
use App\Model\Payment\Mock\MockBank;
use App\Model\Payment\Mock\MockBankSource;
use App\Model\Payment\VariableSymbol;
use App\Model\Reservation\ReservationAdmin;
use App\Presentation\Accessory\FormFactory;
use Nette\Application\UI\Form;
use Nette\Application\UI\Presenter;
use Tracy\Debugger;


/**
 * Fake bank for trying payments locally: creates incoming payments that the admin then
 * imports with "Načíst platby" exactly like real Fio payments.
 * Exists only in debug mode with bank.driver = mock.
 * @property-read BankTemplate $template
 */
final class BankPresenter extends Presenter
{
	/** @var array<int, array<string, mixed>> payable reservations by id */
	private array $reservations = [];


	public function __construct(
		private readonly MockBank $bank,
		private readonly BankTransactionSource $source,
		private readonly ReservationAdmin $reservationAdmin,
		private readonly FormFactory $formFactory,
		private readonly VariableSymbol $variableSymbol,
	) {
		parent::__construct();
	}


	protected function startup(): void
	{
		parent::startup();
		if (Debugger::$productionMode || !$this->source instanceof MockBankSource) {
			$this->error();
		}
		foreach (['confirmed', 'partially_paid'] as $status) {
			foreach ($this->reservationAdmin->search($status) as $row) {
				$this->reservations[(int) $row['id']] = $row;
			}
		}
		ksort($this->reservations);
	}


	public function renderDefault(): void
	{
		$this->template->transactions = $this->bank->all();
	}


	protected function createComponentPayForm(): Form
	{
		$options = array_map(
			static fn(array $r): string => sprintf(
				'č. %d – %s – zbývá %d Kč',
				$r['id'],
				$r['name'],
				(int) $r['total_price'] - (int) $r['paid_amount'],
			),
			$this->reservations,
		);
		$form = $this->formFactory->create();
		$form->addSelect('reservation', 'Rezervace čekající na platbu', $options)
			->setPrompt($options ? '– vyberte –' : 'žádná rezervace nečeká na platbu')
			->setRequired('Vyberte rezervaci.');
		$buttons = [
			'exact' => 'Zaplatit přesně',
			'less' => 'Zaplatit polovinu',
			'more' => 'Zaplatit o 100 Kč víc',
			'twice' => 'Zaplatit dvakrát',
			'noVs' => 'Zaplatit bez VS',
		];
		foreach ($buttons as $name => $label) {
			$form->addSubmit($name, $label);
		}
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			$reservation = $this->reservations[(int) $data->reservation];
			$vs = $this->variableSymbol->forReservation((int) $reservation['id']);
			$id = (int) $reservation['id'];
			$remaining = ((int) $reservation['total_price'] - (int) $reservation['paid_amount']) * 100;
			$name = (string) $reservation['name'];
			$button = $form->isSubmitted();
			$scenario = $button instanceof \Nette\Forms\Controls\SubmitButton ? $button->getName() : 'exact';

			match ($scenario) {
				'less' => $this->bank->addPayment(max(100, intdiv($remaining, 2)), $vs, $name),
				'more' => $this->bank->addPayment($remaining + 10000, $vs, $name),
				'twice' => [$this->bank->addPayment($remaining, $vs, $name), $this->bank->addPayment($remaining, $vs, $name)],
				'noVs' => $this->bank->addPayment($remaining, null, $name, message: "Ples rezervace $id"),
				default => $this->bank->addPayment($remaining, $vs, $name),
			};
			$this->flashMessage('Platba je v bance. Načtěte ji v administraci tlačítkem „Načíst platby z banky“.');
			$this->redirect('this');
		};
		return $form;
	}


	protected function createComponentCustomForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addText('amount', 'Částka (Kč)')
			->setRequired()
			->addRule($form::Float, 'Zadejte číslo.');
		$form->addText('vs', 'Variabilní symbol')->setMaxLength(10)
			->addCondition($form::Filled)->addRule($form::Pattern, 'Jen číslice.', '\d{1,10}');
		$form->addText('name', 'Plátce')->setDefaultValue('Testovací Plátce');
		$form->addText('message', 'Zpráva pro příjemce');
		$form->addSubmit('send', 'Vytvořit platbu');
		$form->onSuccess[] = function (Form $form, \stdClass $data): void {
			$this->bank->addPayment(
				(int) round((float) str_replace(',', '.', (string) $data->amount) * 100),
				(string) $data->vs,
				(string) $data->name,
				message: (string) $data->message,
			);
			$this->flashMessage('Platba je v bance.');
			$this->redirect('this');
		};
		return $form;
	}


	protected function createComponentClearForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addSubmit('clear', 'Smazat nenačtené platby');
		$form->onSuccess[] = function (): void {
			$this->flashMessage('Smazáno nenačtených plateb: ' . $this->bank->clearPending());
			$this->redirect('this');
		};
		return $form;
	}
}
