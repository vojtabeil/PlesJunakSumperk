<?php

declare(strict_types=1);

namespace App\Model\Payment;

use App\Model\Clock\Clock;
use App\Model\Log\Actor;
use App\Model\Log\EventLog;
use App\Model\Mail\ReservationMailer;
use App\Model\Reservation\Settings;
use DateTimeImmutable;
use Nette\Utils\Json;
use PDO;
use Throwable;


/**
 * Downloads new bank movements, stores them, matches them to reservations
 * and tells customers about received payments.
 */
final class PaymentImporter
{
	public function __construct(
		private readonly BankTransactionSource $source,
		private readonly PaymentMatcher $matcher,
		private readonly ReservationMailer $mailer,
		private readonly Settings $settings,
		private readonly Clock $clock,
		private readonly PDO $db,
		private readonly EventLog $eventLog,
		private readonly Actor $actor,
	) {
	}


	public function import(): ImportResult
	{
		$this->guardInterval();
		$this->settings->remember('bank_last_fetch_at', $this->clock->now()->format('Y-m-d H:i:s'));

		$fetched = $this->source->fetchNew();
		$result = new ImportResult(fetched: count($fetched));

		foreach ($fetched as $transaction) {
			$this->db->beginTransaction();
			try {
				$id = $this->store($transaction);
				$change = $id !== null ? $this->matcher->apply($id) : null;
				$this->db->commit();
			} catch (Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			if ($id === null) {
				$result->duplicates++;
				continue;
			}
			$result->stored++;
			if ($change === null) {
				$status = $this->statusOf($id);
				$result->unmatched += in_array($status, PaymentRepository::ProblemStatuses, true) ? 1 : 0;
				$result->foreign += $status === 'foreign' ? 1 : 0;
				continue;
			}
			$result->matched++;
			if ($change->isNotable()) {
				$this->mailer->sendPaymentUpdate($change);
			}
		}
		// Cron runs every few minutes: log only imports that brought something.
		if ($result->fetched > 0 || $this->actor->type() !== Actor::Cron) {
			$this->eventLog->record('payments.imported', details: ['summary' => $result->summary()] + (array) $result);
		}
		return $result;
	}


	/** When the bank was asked last (by the cron job or an organizer); null = never. */
	public function lastImportAt(): ?DateTimeImmutable
	{
		$last = $this->settings->get('bank_last_fetch_at');
		return $last === '' ? null : new DateTimeImmutable($last);
	}


	/** True when nobody imported payments for a day (e.g. no cron job on the hosting). */
	public function isStale(int $maxAgeSeconds = 24 * 3600): bool
	{
		$last = $this->lastImportAt();
		return $last === null || $this->clock->now()->getTimestamp() - $last->getTimestamp() > $maxAgeSeconds;
	}


	public function canRewind(): bool
	{
		return $this->source instanceof RewindableSource;
	}


	/**
	 * Makes the bank deliver movements from the given day again (after an interrupted import).
	 * Movements stored before are skipped as duplicates by the next import.
	 */
	public function rewind(DateTimeImmutable $since): void
	{
		if (!$this->source instanceof RewindableSource) {
			throw new PaymentError('Tento zdroj plateb neumí stáhnout pohyby znovu.');
		}
		$today = $this->clock->now()->setTime(0, 0);
		$oldest = $today->modify('-' . RewindableSource::MaxRewindDays . ' days');
		if ($since > $today || $since < $oldest) {
			throw new PaymentError(sprintf('Zvolte datum mezi %s a dneškem.', $oldest->format('j. n. Y')));
		}
		$this->guardInterval();
		$this->settings->remember('bank_last_fetch_at', $this->clock->now()->format('Y-m-d H:i:s'));
		$this->source->rewind($since);
		$this->eventLog->record('payments.rewound', details: ['since' => $since->format('Y-m-d')]);
	}


	/** Seconds the organizer has to wait before the next import (0 = may import now). */
	public function secondsUntilNextImport(): int
	{
		$last = $this->settings->get('bank_last_fetch_at');
		if ($last === '' || $this->source->minIntervalSeconds() === 0) {
			return 0;
		}
		$elapsed = $this->clock->now()->getTimestamp() - (new DateTimeImmutable($last))->getTimestamp();
		return max(0, $this->source->minIntervalSeconds() - $elapsed);
	}


	private function guardInterval(): void
	{
		$wait = $this->secondsUntilNextImport();
		if ($wait > 0) {
			throw new PaymentError("Banka dovoluje stahovat pohyby nejvýš jednou za {$this->source->minIntervalSeconds()} sekund. Zkuste to znovu za $wait s.");
		}
	}


	private function statusOf(int $transactionId): string
	{
		$stmt = $this->db->prepare('SELECT match_status FROM bank_transactions WHERE id = ?');
		$stmt->execute([$transactionId]);
		return (string) $stmt->fetchColumn();
	}


	/** Returns the new row id, or null when the transaction was imported before. */
	private function store(BankTransaction $t): ?int
	{
		$stmt = $this->db->prepare(
			"INSERT IGNORE INTO bank_transactions
			(source, external_id, booked_on, amount, currency, variable_symbol, counter_account, counter_name, message, raw, match_status)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unknown_vs')",
		);
		$stmt->execute([
			$this->source->name(),
			$t->externalId,
			$t->bookedOn,
			number_format($t->amountHalers / 100, 2, '.', ''),
			$t->currency,
			$t->variableSymbol,
			$t->counterAccount,
			$t->counterName === null ? null : mb_substr($t->counterName, 0, 255),
			$t->message === null ? null : mb_substr($t->message, 0, 255),
			Json::encode($t->raw),
		]);
		return $stmt->rowCount() === 1 ? (int) $this->db->lastInsertId() : null;
	}
}
