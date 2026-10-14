<?php

declare(strict_types=1);

namespace App\Tests\Model\Payment;

use App\Model\Http\HttpClient;
use App\Model\Http\HttpException;
use App\Model\Http\HttpResponse;
use App\Model\Payment\Fio\FioApiSource;
use App\Model\Payment\Fio\FioResponseParser;
use App\Model\Payment\PaymentError;
use App\Tests\FakeHttpClient;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;


/** The Fio API is never called; responses come from tests/php/fixtures. */
final class FioApiSourceTest extends TestCase
{
	private const Token = 'TestToken0123456789abcdefABCDEF0123456789xyz';


	public function testParsesStatement(): void
	{
		$transactions = FioResponseParser::parse($this->fixture());
		self::assertCount(4, $transactions);

		[$transfer, $cash, $fee, $interest] = $transactions;
		self::assertSame('26000000001', $transfer->externalId);
		self::assertSame('2026-02-01', $transfer->bookedOn, 'Milliseconds are converted in Prague time');
		self::assertSame(95000, $transfer->amountHalers);
		self::assertSame('0020260003', $transfer->variableSymbol, 'Leading zeros are removed by the matcher, not here');
		self::assertSame('123456789/0800', $transfer->counterAccount);
		self::assertSame('Novák Jan', $transfer->counterName);
		self::assertSame('SUMPERSKY SKAUTSKY PLES', $transfer->message);

		self::assertSame(45050, $cash->amountHalers);
		self::assertNull($cash->variableSymbol);
		self::assertNull($cash->counterAccount);
		self::assertSame('Platba za ples Dvořák', $cash->counterName, 'Falls back to the user identification');

		self::assertSame(-100, $fee->amountHalers);
		self::assertSame('2900233333/2010', $fee->counterAccount);

		self::assertSame('2026-02-02', $interest->bookedOn, 'Date as text is accepted too');
		self::assertSame(1, $interest->amountHalers);
	}


	public function testEmptyStatement(): void
	{
		self::assertSame([], FioResponseParser::parse('{"accountStatement":{"info":{},"transactionList":{"transaction":[]}}}'));
		self::assertSame([], FioResponseParser::parse('{"accountStatement":{"info":{},"transactionList":null}}'));
	}


	public function testFetchCallsLastEndpoint(): void
	{
		$http = $this->http(new HttpResponse(200, $this->fixture()));
		$source = new FioApiSource(self::Token, $http);

		self::assertCount(4, $source->fetchNew());
		self::assertSame(['https://fioapi.fio.cz/v1/rest/last/' . self::Token . '/transactions.json'], $http->urls);
		self::assertSame(30, $source->minIntervalSeconds());
	}


	public function testRewindCallsSetLastDate(): void
	{
		$http = $this->http(new HttpResponse(200, ''));
		(new FioApiSource(self::Token, $http))->rewind(new DateTimeImmutable('2026-01-25'));

		self::assertSame(['https://fioapi.fio.cz/v1/rest/set-last-date/' . self::Token . '/2026-01-25/'], $http->urls);
	}


	/** @return list<array{int, string}> */
	public static function errorResponses(): array
	{
		return [
			[409, 'jeden dotaz za 30 sekund'],
			[500, 'token odmítla'],
			[422, 'starší 90 dní'],
			[413, 'Příliš mnoho pohybů'],
			[404, 'HTTP 404'],
		];
	}


	#[\PHPUnit\Framework\Attributes\DataProvider('errorResponses')]
	public function testHttpErrorsBecomeReadableMessages(int $status, string $message): void
	{
		$source = new FioApiSource(self::Token, $this->http(new HttpResponse($status, 'error')));
		try {
			$source->fetchNew();
			self::fail('Error expected');
		} catch (PaymentError $e) {
			self::assertStringContainsString($message, $e->getMessage());
			self::assertStringNotContainsString(self::Token, $e->getMessage());
		}
	}


	public function testNetworkErrorNeverRevealsTheToken(): void
	{
		$http = new class implements HttpClient {
			public function get(string $url, int $timeoutSeconds = 30): HttpResponse
			{
				throw new HttpException("Could not resolve host for $url");
			}
		};
		try {
			(new FioApiSource(self::Token, $http))->fetchNew();
			self::fail('Error expected');
		} catch (PaymentError $e) {
			self::assertStringContainsString('Nepodařilo se spojit', $e->getMessage());
			self::assertStringNotContainsString(self::Token, $e->getMessage());
		}
	}


	public function testInvalidBodyIsReported(): void
	{
		$this->expectExceptionMessage('nerozumíme');
		(new FioApiSource(self::Token, $this->http(new HttpResponse(200, '<html>maintenance</html>'))))->fetchNew();
	}


	public function testMissingTokenIsReportedOnUse(): void
	{
		$source = new FioApiSource('', $this->http(new HttpResponse(200, '')));
		$this->expectExceptionMessage('Token pro Fio API chybí');
		$source->fetchNew();
	}


	private function fixture(): string
	{
		return (string) file_get_contents(__DIR__ . '/../../fixtures/fio-statement.json');
	}


	private function http(HttpResponse $response): FakeHttpClient
	{
		return new FakeHttpClient($response);
	}
}
