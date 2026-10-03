<?php

declare(strict_types=1);

namespace App\Model\Payment\Fio;

use App\Model\Http\HttpClient;
use App\Model\Http\HttpException;
use App\Model\Payment\PaymentError;
use App\Model\Payment\RewindableSource;
use DateTimeImmutable;
use UnexpectedValueException;


/**
 * Movements from the Fio API with a read-only token ("Sledování účtu").
 * /last/ returns movements since the previous successful download (the bank keeps the cursor).
 * The token is a secret: it is never put into exceptions or logs.
 */
final class FioApiSource implements RewindableSource
{
	private const BaseUrl = 'https://fioapi.fio.cz/v1/rest';


	public function __construct(
		private readonly string $token,
		private readonly HttpClient $http,
	) {
	}


	public function name(): string
	{
		return 'fio';
	}


	public function minIntervalSeconds(): int
	{
		return 30;
	}


	public function fetchNew(): array
	{
		$body = $this->request(self::BaseUrl . "/last/{$this->token}/transactions.json", timeout: 60);
		try {
			return FioResponseParser::parse($body);
		} catch (UnexpectedValueException $e) {
			throw new PaymentError('Banka vrátila odpověď, které nerozumíme: ' . $e->getMessage(), previous: $e);
		}
	}


	public function rewind(DateTimeImmutable $since): void
	{
		$this->request(self::BaseUrl . "/set-last-date/{$this->token}/{$since->format('Y-m-d')}/", timeout: 30);
	}


	private function request(string $url, int $timeout): string
	{
		// Checked here, not in the constructor, so a missing token does not break the admin pages.
		if (!preg_match('/^[A-Za-z0-9]{20,128}$/', $this->token)) {
			throw new PaymentError('Token pro Fio API chybí nebo má neplatný tvar (config/local.neon, bank.token).');
		}
		try {
			$response = $this->http->get($url, $timeout);
		} catch (HttpException $e) {
			throw new PaymentError('Nepodařilo se spojit s Fio bankou: ' . $this->redact($e->getMessage()));
		}

		return match (true) {
			$response->status === 200 => $response->body,
			$response->status === 409 => throw new PaymentError('Fio banka dovoluje jeden dotaz za 30 sekund. Zkuste to za chvíli znovu.'),
			$response->status === 500 => throw new PaymentError('Fio banka token odmítla. Zkontrolujte, že je platný a nevypršel (internetové bankovnictví → Nastavení → API).'),
			$response->status === 422 => throw new PaymentError('Data starší 90 dní vyžadují ve Fio bankovnictví dodatečné ověření.'),
			$response->status === 413 => throw new PaymentError('Příliš mnoho pohybů najednou. Načtěte je znovu od novějšího data.'),
			default => throw new PaymentError("Fio banka odpověděla neočekávaně (HTTP {$response->status})."),
		};
	}


	private function redact(string $text): string
	{
		return str_replace($this->token, '***', $text);
	}
}
