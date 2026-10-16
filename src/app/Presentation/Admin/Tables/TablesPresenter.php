<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Tables;

use App\Model\Hall\HallSync;
use App\Model\Hall\TableAvailability;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\UI\Form;


/**
 * Stoly v prodeji (/admin/stoly): which tables of the hall plan are for sale. The plan is shown inline;
 * admin.ts (admin/table-availability.ts) greys out the tables unticked in the form while choosing.
 * @property-read TablesTemplate $template
 */
final class TablesPresenter extends BasePresenter
{
	/** @var list<array{id: int, label: string, name: ?string, svg_id: string, available: bool, seats: int, reserved: int, held: int}> */
	private array $tables = [];


	public function __construct(
		private readonly TableAvailability $availability,
		private readonly HallSync $sync,
	) {
		parent::__construct();
	}


	public function actionDefault(): void
	{
		$this->tables = $this->availability->tables();
	}


	public function renderDefault(): void
	{
		$plan = $this->sync->plan();
		$this->template->tables = $this->tables;
		$this->template->planSvg = $plan !== null ? $plan['svg'] : null;
	}


	protected function createComponentAvailabilityForm(): Form
	{
		$labels = array_column($this->tables, 'label');
		$form = $this->formFactory->create();
		$form->addCheckboxList('available', 'Stoly v prodeji', array_combine($labels, $labels))
			->setDefaultValue(array_column(array_filter($this->tables, static fn(array $t): bool => $t['available']), 'label'));
		$form->addSubmit('save', 'Uložit');
		$form->onSuccess[] = function (Form $form, \stdClass $data) use ($labels): void {
			$available = array_map('strval', (array) $data->available);
			$changes = $this->availability->save(array_values(array_diff($labels, $available)));
			$parts = array_filter([
				$changes['unavailable'] ? 'mimo prodej: ' . implode(', ', $changes['unavailable']) : null,
				$changes['available'] ? 'znovu v prodeji: ' . implode(', ', $changes['available']) : null,
				$changes['released'] ? "uvolněno vybíraných míst: {$changes['released']}" : null,
			]);
			$this->flashMessage($parts ? 'Uloženo – ' . implode('; ', $parts) . '.' : 'Nic se nezměnilo.', $parts ? 'success' : 'info');
			$this->redirect('this');
		};
		return $form;
	}
}
