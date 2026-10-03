<?php

declare(strict_types=1);

namespace App\Model\Payment;

use App\Model\Reservation\Settings;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use InvalidArgumentException;
use Nette\Utils\Strings;


/**
 * Czech "QR Platba" (SPAYD 1.0, https://qr-platba.cz/pro-vyvojare/specifikace-formatu/).
 * Generated locally, no external service.
 */
final class QrPayment
{
	public function __construct(
		private readonly Settings $settings,
	) {
	}


	/** Account of the scout group from the settings, e.g. "2501895120/2010". */
	public function account(): string
	{
		return $this->settings->get('bank_account');
	}


	/** "[prefix-]number/bank" -> "CZkk bbbb pppp ppnn nnnn nnnn" without spaces. */
	public static function czechAccountToIban(string $account): string
	{
		if (!preg_match('~^(?:(\d{1,6})-)?(\d{2,10})/(\d{4})$~', trim($account), $m)) {
			throw new InvalidArgumentException("Invalid Czech account number '$account'.");
		}
		$bban = $m[3] . str_pad($m[1], 6, '0', STR_PAD_LEFT) . str_pad($m[2], 10, '0', STR_PAD_LEFT);
		// ISO 13616: move "CZ00" to the end, letters to numbers (C=12, Z=35), check = 98 - mod 97.
		$check = 98 - self::mod97($bban . '123500');
		return sprintf('CZ%02d%s', $check, $bban);
	}


	/** Remainder of a long decimal string divided by 97 (without bcmath, which the hosting may lack). */
	private static function mod97(string $digits): int
	{
		$remainder = 0;
		foreach (str_split($digits, 7) as $chunk) {
			$remainder = (int) ($remainder . $chunk) % 97;
		}
		return $remainder;
	}


	/** IBAN of the account, grouped by four for people ("CZ65 0800 ..."). */
	public function iban(): string
	{
		return trim(chunk_split(self::czechAccountToIban($this->account()), 4, ' '));
	}


	/** @param \DateTimeInterface|null $dueDate shown by banks as the payment date (DT) */
	public function spayd(int $amountCzk, string $variableSymbol, string $message, ?\DateTimeInterface $dueDate = null): string
	{
		$message = Strings::upper(Strings::toAscii($message));
		$message = Strings::truncate(str_replace('*', '', $message), 60, '');
		return implode('*', array_filter([
			'SPD',
			'1.0',
			'ACC:' . self::czechAccountToIban($this->account()),
			'AM:' . number_format($amountCzk, 2, '.', ''),
			'CC:CZK',
			$dueDate !== null && $dueDate > new \DateTimeImmutable('today') ? 'DT:' . $dueDate->format('Ymd') : null,
			'X-VS:' . $variableSymbol,
			'MSG:' . $message,
		]));
	}


	/**
	 * Payment of what is still to pay for a reservation (from ReservationService::findFinished),
	 * with its due date and "<event> - <name>" as the message for the organizers.
	 * @param array<string, mixed> $reservation
	 */
	public function forReservation(array $reservation, string $eventName): string
	{
		return $this->spayd(
			(int) $reservation['remaining'],
			(string) $reservation['variable_symbol'],
			trim($eventName . ' - ' . ($reservation['name'] ?? '')),
			$reservation['due_on'] ?? null,
		);
	}


	/** PNG image of the QR code. */
	public function png(string $spayd): string
	{
		$options = new QROptions([
			'outputInterface' => QRGdImagePNG::class,
			'outputBase64' => false,
			'scale' => 6,
			'quietzoneSize' => 2,
		]);
		return (new QRCode($options))->render($spayd);
	}


	public function pngDataUri(string $spayd): string
	{
		return 'data:image/png;base64,' . base64_encode($this->png($spayd));
	}
}
