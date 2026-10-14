<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Customer;

use App\Model\Reservation\ReservationAdmin;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\Attributes\Persistent;


/**
 * Customers = people grouped by e-mail with all their reservations.
 * @property-read CustomerTemplate $template
 */
final class CustomerPresenter extends BasePresenter
{
	#[Persistent]
	public string $q = '';


	public function __construct(
		private readonly ReservationAdmin $reservationAdmin,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$this->template->customers = $this->reservationAdmin->customers($this->q);
	}
}
