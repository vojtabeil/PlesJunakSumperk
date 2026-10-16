<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Statistics;

use App\Model\Export\Csv;
use App\Model\Reservation\ReservationAdmin;
use App\Model\Reservation\SeatOverview;
use App\Model\Stats\SalesStats;
use App\Presentation\Accessory\TemplateExtension;
use App\Presentation\Admin\Accessory\BarChart;
use App\Presentation\Admin\Accessory\TicketBars;
use App\Presentation\Admin\BasePresenter;
use Nette\Application\Responses\TextResponse;


/**
 * Statistiky (/admin/statistiky): tickets, sales by day, money, occupancy. Every block has its data as CSV
 * (/admin/statistiky/csv/<block>, Czech Excel). Test reservations are not counted (SalesStats).
 * @property-read StatisticsTemplate $template
 */
final class StatisticsPresenter extends BasePresenter
{
	/** CSV blocks => file names. */
	private const Blocks = ['listky' => 'listky', 'prodej' => 'prodej-po-dnech', 'trzby' => 'trzby', 'stoly' => 'obsazenost-stolu', 'mista' => 'mista'];


	public function __construct(
		private readonly SalesStats $stats,
		private readonly TicketBars $ticketBars,
		private readonly SeatOverview $seatOverview,
		private readonly ReservationAdmin $reservationAdmin,
	) {
		parent::__construct();
	}


	public function renderDefault(): void
	{
		$summary = $this->ticketBars->build();
		$t = $this->template;
		$t->bars = $summary['bars'];
		$t->entrance = $summary['entrance'];
		$t->seatsUnavailable = $summary['unavailable'];

		$days = $this->stats->daily();
		$t->days = $days;
		// Every few days a date under the columns, so that they do not overlap.
		$step = max(1, (int) ceil(count($days) / 12));
		$t->dailyChart = BarChart::build(array_map(static fn(array $day, int $i): array => [
			'label' => $i % $step === 0 ? date('j. n.', (int) strtotime($day['date'])) : '',
			'title' => date('j. n. Y', (int) strtotime($day['date'])) . ': ' . $day['seats'] . ' na místo, ' . $day['standing'] . ' na stání',
			'parts' => ['seat' => $day['seats'], 'standing' => $day['standing']],
		], $days, array_keys($days)), runningTotal: true);

		$t->revenue = $this->stats->revenue();
		$t->channels = $this->stats->byChannel();

		$tables = $this->stats->tables();
		$t->tables = $tables;
		$areas = [];
		foreach ($tables as $table) {
			$areas[$table['area']] ??= ['seats' => 0, 'sold' => 0, 'paid' => 0, 'held' => 0, 'free' => 0, 'unavailable' => 0];
			foreach (['seats', 'sold', 'paid', 'held', 'free', 'unavailable'] as $key) {
				$areas[$table['area']][$key] += $table[$key];
			}
		}
		$t->areas = $areas;
		$t->standing = $this->reservationAdmin->standingByPayment() + ['capacity' => $summary['stats']['standing_capacity']];
	}


	public function actionCsv(string $block): void
	{
		if (!isset(self::Blocks[$block])) {
			$this->error('Neznámá data.');
		}
		[$header, $rows] = match ($block) {
			'listky' => $this->ticketRows(),
			'prodej' => $this->dailyRows(),
			'trzby' => $this->moneyRows(),
			'stoly' => $this->tableRows(),
			default => $this->seatRows(),
		};
		$response = $this->getHttpResponse();
		$response->setContentType('text/csv', 'UTF-8');
		$response->setHeader('Content-Disposition', 'attachment; filename="statistiky-' . self::Blocks[$block] . '-' . date('Y-m-d') . '.csv"');
		$response->setHeader('Cache-Control', 'no-store');
		$this->sendResponse(new TextResponse(Csv::build($header, $rows)));
	}


	/** @return array{list<string>, list<list<mixed>>} */
	private function ticketRows(): array
	{
		$labels = ['paid' => 'zaplaceno', 'partial' => 'částečně zaplaceno', 'unpaid' => 'nezaplaceno', 'held' => 'právě se vybírá',
			'picked_up' => 'vyzvednuto', 'waiting' => 'k vyzvednutí'];
		$summary = $this->ticketBars->build();
		$rows = [];
		foreach ($summary['bars'] as $kind => $bar) {
			foreach ($bar['parts'] as $part) {
				$rows[] = [$bar['label'], $labels[$part['key']] ?? $part['key'], $part['value']];
			}
			if ($kind !== 'crew') {
				$rows[] = [$bar['label'], 'volné', $bar['free']];
			}
			if ($kind === 'seat') {
				$rows[] = [$bar['label'], 'mimo prodej', $summary['unavailable']];
			}
			$rows[] = [$bar['label'], 'prošlo vstupem', $summary['entrance']['kinds'][$kind]['used'] ?? 0];
		}
		return [['Druh lístku', 'Stav', 'Počet'], $rows];
	}


	/** @return array{list<string>, list<list<mixed>>} */
	private function dailyRows(): array
	{
		return [
			['Den', 'Nové rezervace', 'Lístky na místo', 'Lístky na stání', 'Hodnota objednávek Kč', 'Zrušené rezervace',
				'Lístky zrušených', 'Přijato převodem Kč', 'Přijato hotově Kč'],
			array_map(static fn(array $day): array => [
				$day['date'], $day['reservations'], $day['seats'], $day['standing'], $day['value'] / 100,
				$day['cancelled'], $day['cancelled_tickets'], $day['paid_bank'] / 100, $day['paid_cash'] / 100,
			], $this->stats->daily()),
		];
	}


	/** @return array{list<string>, list<list<mixed>>} */
	private function moneyRows(): array
	{
		return [
			['Číslo', 'Jméno', 'E-mail', 'Stav', 'Prodej', 'Potvrzeno', 'Splatnost', 'Cena Kč', 'Zaplaceno převodem Kč',
				'Zaplaceno celkem Kč', 'Vráceno Kč', 'K vrácení Kč'],
			array_map(static fn(array $r): array => [
				(int) $r['id'], $r['name'], $r['email'], TemplateExtension::formatStatus((string) $r['status']),
				['public' => 'veřejný', 'vip' => 'VIP'][$r['channel']] ?? $r['channel'], $r['confirmed_at'], $r['due_on'],
				(int) $r['total_price'], (float) $r['paid_bank'], (float) $r['paid_amount'], (float) $r['refunded'], (float) $r['to_refund'],
			], $this->stats->reservationMoney()),
		];
	}


	/** @return array{list<string>, list<list<mixed>>} */
	private function tableRows(): array
	{
		return [
			['Stůl', 'Část sálu', 'V prodeji', 'Míst', 'Prodáno', 'Zaplaceno', 'Právě se vybírá', 'Volné', 'Mimo prodej'],
			array_map(static fn(array $t): array => [
				$t['table'], $t['area'], $t['available'] ? 'ano' : 'ne', $t['seats'], $t['sold'], $t['paid'], $t['held'], $t['free'], $t['unavailable'],
			], $this->stats->tables()),
		];
	}


	/** @return array{list<string>, list<list<mixed>>} */
	private function seatRows(): array
	{
		return [
			['Místo', 'Stůl', 'Stav', 'Rezervace', 'Jméno'],
			array_map(static fn(array $s): array => [
				$s['label'], $s['table'], SeatOverview::Statuses[$s['status']] ?? $s['status'], $s['reservation_id'], $s['name'],
			], $this->seatOverview->seats()),
		];
	}
}
