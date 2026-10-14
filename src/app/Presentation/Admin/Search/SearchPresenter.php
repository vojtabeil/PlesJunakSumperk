<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Search;

use App\Model\Payment\VariableSymbol;
use App\Model\Reservation\ReservationAdmin;
use App\Presentation\Admin\BasePresenter;
use PDO;


/**
 * Search box in the admin header: seat "3/5", reservation number, variable symbol,
 * name or e-mail. A single match opens it directly.
 */
final class SearchPresenter extends BasePresenter
{
	public function __construct(
		private readonly ReservationAdmin $reservationAdmin,
		private readonly VariableSymbol $variableSymbol,
		private readonly PDO $db,
	) {
		parent::__construct();
	}


	public function actionDefault(string $q = ''): void
	{
		$q = trim($q);
		if ($q === '') {
			$this->redirect('Dashboard:default');
		}

		if (preg_match('~^\d{1,3}\s*/\s*\d{1,3}$~', $q)) {
			$label = (string) preg_replace('~\s+~', '', $q);
			$stmt = $this->db->prepare("SELECT reservation_id, state FROM seats WHERE label = ?");
			$stmt->execute([$label]);
			$seat = $stmt->fetch();
			if ($seat && $seat['reservation_id'] !== null && $seat['state'] === 'reserved') {
				$this->redirect('Reservation:detail', (int) $seat['reservation_id']);
			}
			$this->flashMessage($seat ? "Místo $label nemá rezervaci." : "Místo $label neexistuje.", 'info');
			$this->redirect('Dashboard:default', ['seatSearch' => $label, 'seats' => '']);
		}

		if (ctype_digit($q)) {
			$id = $this->variableSymbol->reservationId($q) ?? (int) $q;
			if ($this->reservationAdmin->get($id) !== null) {
				$this->redirect('Reservation:detail', $id);
			}
		}

		$found = $this->reservationAdmin->search(null, $q);
		if (count($found) === 1) {
			$this->redirect('Reservation:detail', (int) $found[0]['id']);
		}
		$this->redirect('Reservation:default', ['q' => $q, 'status' => '', 'problem' => '']);
	}
}
