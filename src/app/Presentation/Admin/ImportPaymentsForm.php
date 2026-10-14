<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Model\Payment\PaymentError;
use App\Model\Payment\PaymentImporter;
use Nette\Application\UI\Form;


/**
 * "Načíst platby z banky" button, used on the dashboard and on the Payments page.
 * Without a cron job on the hosting this is how payments get imported.
 * The presenter provides $this->importer.
 */
trait ImportPaymentsForm
{
	protected function createComponentImportForm(): Form
	{
		$form = $this->formFactory->create();
		$form->addSubmit('import', 'Načíst platby z banky');
		$form->onSuccess[] = function (): void {
			try {
				$result = $this->paymentImporter()->import();
				$this->flashMessage($result->summary(), $result->unmatched ? 'info' : 'success');
			} catch (PaymentError $e) {
				$this->flashMessage($e->getMessage(), 'error');
			}
			$this->redirect('this');
		};
		return $form;
	}


	abstract protected function paymentImporter(): PaymentImporter;
}
