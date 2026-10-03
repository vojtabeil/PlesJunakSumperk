<?php

declare(strict_types=1);

namespace App\Model\Payment;

use App\Model\Clock\Clock;
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
	) {
	}


	public function import(): ImportResult
	{
		$this->guardInterval();
		$this->settings->save(['bank_last_fetch_at' => $this->clock->now()->format('Y-m-d H:i:s')]);

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
				$result->unmatched += $transaction->amountHalers > 0 ? 1 : 0;
				continue;
			}
			$result->matched++;
			if ($change->isNotable()) {
				$this->mailer->sendPaymentUpdate($change);
			}
		}
		return $result;
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
