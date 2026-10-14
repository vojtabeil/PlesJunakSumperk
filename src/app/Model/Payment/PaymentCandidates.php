<?php

declare(strict_types=1);

namespace App\Model\Payment;

use PDO;


/**
 * Which reservation a payment the bank could not match belongs to: suggestions by amount, payer's name
 * and the message, and a search by number, name, e-mail or variable symbol (Payments page).
 */
final class PaymentCandidates
{
	private const Columns = 'r.id, r.name, r.email, r.status, r.total_price, r.paid_amount, r.channel';

	/** Statuses that can still receive a payment. */
	private const OpenStatuses = "'confirmed', 'partially_paid'";


	public function __construct(
		private readonly PDO $db,
		private readonly VariableSymbol $variableSymbol,
	) {
	}


	/**
	 * Reservations a payment most likely belongs to, best first (only those with at least one clue).
	 * @param array<string, mixed> $payment row of bank_transactions
	 * @return list<array<string, mixed>> reservation rows with remaining (CZK) and reasons (Czech)
	 */
	public function suggest(array $payment, int $limit = 3): array
	{
		$amount = (int) round((float) $payment['amount']);
		$payer = self::words((string) ($payment['counter_name'] ?? ''));
		$message = self::normalize((string) ($payment['message'] ?? ''));
		$digits = preg_replace('~\D+~', ' ', (string) ($payment['variable_symbol'] ?? '') . ' ' . $message) ?? '';

		$scored = [];
		foreach ($this->db->query('SELECT ' . self::Columns . ' FROM reservations r WHERE r.status IN (' . self::OpenStatuses . ')')->fetchAll() as $r) {
			$r = self::withRemaining($r);
			$score = 0;
			$reasons = [];
			if ($amount === $r['remaining']) {
				$score += 3;
				$reasons[] = 'částka odpovídá doplatku';
			} elseif ($amount === (int) $r['total_price']) {
				$score += 2;
				$reasons[] = 'částka odpovídá ceně';
			}
			$name = self::words((string) $r['name']);
			$common = array_intersect($payer, $name);
			if ($common) {
				$score += 2 * count($common);
				$reasons[] = 'jméno plátce';
			} elseif ($name && array_intersect($name, self::words($message))) {
				$score += 2;
				$reasons[] = 'jméno ve zprávě';
			}
			if (preg_match('~(^|\s)0*' . (int) $r['id'] . '(\s|$)~', $digits)) {
				$score += 2;
				$reasons[] = 'číslo rezervace v platbě';
			}
			if ($score >= 2) {
				$scored[] = $r + ['score' => $score, 'reasons' => $reasons];
			}
		}
		usort($scored, static fn(array $a, array $b): int => [$b['score'], $b['id']] <=> [$a['score'], $a['id']]);
		return array_slice($scored, 0, $limit);
	}


	/**
	 * Finished reservations by number, variable symbol, part of the name or e-mail (open ones first).
	 * @return list<array<string, mixed>> reservation rows with remaining (CZK)
	 */
	public function search(string $query, int $limit = 8): array
	{
		$query = trim($query);
		if ($query === '') {
			return [];
		}
		$id = ctype_digit($query) ? ($this->variableSymbol->reservationId($query) ?? (int) $query) : 0;
		$like = '%' . addcslashes($query, '%_\\') . '%';
		$stmt = $this->db->prepare(
			'SELECT ' . self::Columns . " FROM reservations r
			WHERE r.status IN ('confirmed', 'partially_paid', 'paid') AND (r.id = ? OR r.name LIKE ? OR r.email LIKE ?)
			ORDER BY r.id = ? DESC, r.status = 'paid', r.id DESC LIMIT " . max(1, $limit),
		);
		$stmt->execute([$id, $like, $like, $id]);
		return array_map(self::withRemaining(...), $stmt->fetchAll());
	}


	/**
	 * @param array<string, mixed> $reservation
	 * @return array<string, mixed>
	 */
	private static function withRemaining(array $reservation): array
	{
		$reservation['remaining'] = max(0, (int) $reservation['total_price'] - (int) round((float) $reservation['paid_amount']));
		return $reservation;
	}


	/** @return list<string> words of at least 3 letters, without diacritics, lower case */
	private static function words(string $text): array
	{
		return array_values(array_filter(
			preg_split('~[^a-z0-9]+~', self::normalize($text)) ?: [],
			static fn(string $word): bool => strlen($word) >= 3,
		));
	}


	private static function normalize(string $text): string
	{
		return strtolower(\Nette\Utils\Strings::toAscii($text));
	}
}
