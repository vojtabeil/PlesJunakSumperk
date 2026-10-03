<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Log;

use App\Model\Log\EventFilter;
use App\Model\Log\EventLogRepository;
use App\Model\Log\EventTypes;
use App\Model\Payment\VariableSymbol;
use App\Presentation\Admin\BasePresenter;
use DateTimeImmutable;
use Nette\Application\Attributes\Persistent;
use PDO;


/**
 * Browsing the event log with filters (date, category, who, seat, reservation).
 * @property-read LogTemplate $template
 */
final class LogPresenter extends BasePresenter
{
	#[Persistent]
	public string $from = '';

	#[Persistent]
	public string $to = '';

	#[Persistent]
	public string $category = '';

	#[Persistent]
	public string $actorType = '';

	/** Seat label, e.g. "3/5". */
	#[Persistent]
	public string $seat = '';

	/** Reservation number or variable symbol. */
	#[Persistent]
	public string $reservation = '';


	public function __construct(
		private readonly EventLogRepository $repository,
		private readonly VariableSymbol $variableSymbol,
		private readonly PDO $db,
	) {
		parent::__construct();
	}


	public function renderDefault(int $page = 1): void
	{
		$seatId = $this->seatId();
		$reservationId = $this->reservationId();
		$unknown = ($this->seat !== '' && $seatId === null) || ($this->reservation !== '' && $reservationId === null);

		$filter = new EventFilter(
			from: self::date($this->from),
			to: self::date($this->to),
			category: $this->category ?: null,
			actor: $this->actorType ?: null,
			reservationId: $reservationId,
			seatId: $seatId,
		);
		$result = $unknown ? ['events' => [], 'total' => 0] : $this->repository->search($filter, $page);

		$t = $this->template;
		$t->events = $result['events'];
		$t->total = $result['total'];
		$t->page = max(1, $page);
		$t->pages = max(1, (int) ceil($result['total'] / EventLogRepository::PageSize));
		$t->categories = EventTypes::Categories;
		$t->actors = EventTypes::Actors;
		$t->filterActive = $this->from . $this->to . $this->category . $this->actorType . $this->seat . $this->reservation !== '';
		$t->unknownReference = $unknown;
	}


	private function seatId(): ?int
	{
		if ($this->seat === '') {
			return null;
		}
		$stmt = $this->db->prepare('SELECT id FROM seats WHERE label = ?');
		$stmt->execute([preg_replace('~\s+~', '', $this->seat)]); // "3 / 5" = "3/5"
		$id = $stmt->fetchColumn();
		return $id === false ? null : (int) $id;
	}


	private function reservationId(): ?int
	{
		$value = trim($this->reservation);
		if ($value === '' || !ctype_digit($value)) {
			return null;
		}
		return $this->variableSymbol->reservationId($value) ?? (int) $value;
	}


	private static function date(string $value): ?DateTimeImmutable
	{
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
	}
}
