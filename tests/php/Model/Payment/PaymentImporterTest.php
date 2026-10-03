<?php

declare(strict_types=1);

namespace App\Tests\Model\Payment;

use App\Model\Mail\ReservationMailer;
use App\Model\Payment\BankTransaction;
use App\Model\Payment\BankTransactionSource;
use App\Model\Payment\Mock\MockBank;
use App\Model\Payment\Mock\MockBankSource;
use App\Model\Payment\PaymentError;
use App\Model\Payment\PaymentImporter;
use App\Model\Payment\PaymentMatcher;
use App\Model\Payment\QrPayment;
use App\Model\Payment\VariableSymbol;
use App\Tests\DatabaseTestCase;
use App\Model\Http\HttpResponse;
use App\Model\Payment\Fio\FioApiSource;
use App\Tests\FakeHttpClient;
use App\Tests\FrozenClock;
use App\Tests\RecordingMailSender;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use Tracy\ILogger;


final class PaymentImporterTest extends DatabaseTestCase
{
	private MockBank $bank;
	private PaymentMatcher $matcher;
	private FrozenClock $clock;

	private RecordingMailSender $sender;


	protected function setUp(): void
	{
		parent::setUp();
		$this->bank = new MockBank($this->db);
		$this->matcher = new PaymentMatcher($this->db, new VariableSymbol($this->settings()), $this->eventLog());
		$this->clock = new FrozenClock;
		$this->sender = new RecordingMailSender;
	}


	public function testExactPaymentMarksReservationPaidAndSendsEmail(): void
	{
		$id = $this->reservation(950);
		$this->bank->addPayment(95000, $this->vs($id));

		$result = $this->importer()->import();

		self::assertSame([1, 1, 1, 0], [$result->fetched, $result->stored, $result->matched, $result->unmatched]);
		self::assertSame(['paid', 950], $this->reservationState($id));
		self::assertSame('matched', $this->lastStatus());
		self::assertCount(1, $this->sender->sent);
		self::assertStringStartsWith('Platba přijata', $this->sender->sent[0]->subject);
	}


	public function testPartialPaymentsAddUp(): void
	{
		$id = $this->reservation(950);
		$this->bank->addPayment(50000, $this->vs($id));
		$this->importer()->import();
		self::assertSame(['partially_paid', 500], $this->reservationState($id));
		self::assertSame('underpaid', $this->lastStatus());
		self::assertStringContainsString("450\u{A0}Kč", $this->sender->sent[0]->text, 'E-mail tells the remaining amount');

		$this->bank->addPayment(45000, '00' . $this->vs($id)); // leading zeros in VS are fine
		$this->importer()->import();
		self::assertSame(['paid', 950], $this->reservationState($id));
		self::assertCount(2, $this->sender->sent);
		self::assertSame(
			['matched', 'matched'],
			$this->db->query('SELECT match_status FROM bank_transactions ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN),
			'The first partial payment is no longer a problem',
		);
	}


	public function testOverpaymentAndSecondPaymentAreFlagged(): void
	{
		$id = $this->reservation(350);
		$this->bank->addPayment(35000, $this->vs($id));
		$this->bank->addPayment(35000, $this->vs($id));

		$this->importer()->import();

		self::assertSame(['paid', 700], $this->reservationState($id));
		self::assertSame('overpaid', $this->lastStatus());
		self::assertCount(1, $this->sender->sent, 'Only the first full payment is announced');

		// The organizer refunds the difference and marks it as dealt with.
		$overpaid = (int) $this->db->query("SELECT id FROM bank_transactions WHERE match_status = 'overpaid'")->fetchColumn();
		$this->matcher->settle($overpaid);
		self::assertSame('matched', $this->lastStatus());
		self::assertContains('payment.settled', $this->loggedActions());

		$this->expectException(PaymentError::class);
		$this->matcher->settle($overpaid);
	}


	public function testPaymentsThatCannotBeMatched(): void
	{
		$this->reservation(350);
		$this->bank->addPayment(35000, null);           // no VS
		$this->bank->addPayment(35000, '20269999');     // ball prefix, but no such reservation
		$this->bank->addPayment(35000, '3');            // membership fee etc. - not the ball (no prefix)
		$this->bank->addPayment(-12000, $this->vs(1));  // outgoing

		$result = $this->importer()->import();

		self::assertSame([2, 1], [$result->unmatched, $result->foreign]);
		self::assertSame(
			['no_vs', 'unknown_vs', 'foreign', 'outgoing'],
			$this->db->query('SELECT match_status FROM bank_transactions ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN),
		);
		self::assertSame([], $this->sender->sent);
	}


	public function testManualAssignAndIgnore(): void
	{
		$id = $this->reservation(350);
		$this->bank->addPayment(35000, null);
		$this->bank->addPayment(20000, null);
		$this->importer()->import();
		[$first, $second] = array_map('intval', $this->db->query('SELECT id FROM bank_transactions ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));

		$change = $this->matcher->assign($first, $id);
		$this->matcher->ignore($second);

		self::assertSame('paid', $change->newStatus);
		self::assertTrue($change->isNotable());
		self::assertSame(['paid', 350], $this->reservationState($id));

		$this->expectException(PaymentError::class);
		$this->matcher->assign($first, $id);
	}


	public function testTransactionsAreImportedOnlyOnce(): void
	{
		$id = $this->reservation(350);
		$tx = new BankTransaction('fio-1', '2026-02-01', 35000, variableSymbol: $this->vs($id));
		$source = $this->fakeSource([$tx, $tx]);

		$result = $this->importer($source)->import();

		self::assertSame([2, 1, 1], [$result->fetched, $result->stored, $result->duplicates]);
		self::assertSame(['paid', 350], $this->reservationState($id));
	}


	public function testFioStatementIsImportedAndMatched(): void
	{
		for ($i = 0; $i < 3; $i++) {
			$id = $this->reservation(950); // ids 1..3; the fixture pays reservation 3 with VS 0020260003
		}
		$fio = new FioApiSource('TestToken0123456789abcdefABCDEF', new FakeHttpClient(
			new HttpResponse(200, (string) file_get_contents(__DIR__ . '/../../fixtures/fio-statement.json')),
		));

		$result = $this->importer($fio)->import();

		self::assertSame([4, 4, 1, 2], [$result->fetched, $result->stored, $result->matched, $result->unmatched]);
		self::assertSame(['paid', 950], $this->reservationState($id));
		self::assertSame(
			['matched', 'no_vs', 'outgoing', 'no_vs'],
			$this->db->query("SELECT match_status FROM bank_transactions WHERE source = 'fio' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN),
		);
	}


	public function testRewindDeliversMovementsAgainWithoutDuplicates(): void
	{
		$id = $this->reservation(350);
		$this->bank->addPayment(35000, $this->vs($id));
		$importer = $this->importer();
		$importer->import();
		$this->clock->now = new \DateTimeImmutable('today 12:00');

		$importer->rewind(new \DateTimeImmutable('today'));
		$result = $importer->import();

		self::assertSame([1, 0, 1], [$result->fetched, $result->stored, $result->duplicates]);
		self::assertSame(['paid', 350], $this->reservationState($id));
	}


	public function testRewindRange(): void
	{
		$this->clock->now = new \DateTimeImmutable('2026-02-01 12:00');
		$this->expectExceptionMessage('Zvolte datum mezi');
		$this->importer()->rewind(new \DateTimeImmutable('2025-10-01'));
	}


	public function testRemembersLastImportForManualMode(): void
	{
		$importer = $this->importer();
		self::assertNull($importer->lastImportAt());
		self::assertTrue($importer->isStale(), 'Never imported');

		$importer->import();
		self::assertEquals($this->clock->now(), $importer->lastImportAt());
		self::assertFalse($importer->isStale());

		$this->clock->advance(25 * 3600);
		self::assertTrue($importer->isStale(), 'Nobody imported for more than a day');
	}


	public function testRespectsTheMinimalIntervalOfTheBank(): void
	{
		$importer = $this->importer($this->fakeSource([], minInterval: 30));
		$importer->import();

		$this->clock->advance(10);
		self::assertSame(20, $importer->secondsUntilNextImport());
		try {
			$importer->import();
			self::fail('Second import within 30 s must fail');
		} catch (PaymentError $e) {
			self::assertStringContainsString('30 sekund', $e->getMessage());
		}

		$this->clock->advance(20);
		$importer->import();
		self::assertSame(30, $importer->secondsUntilNextImport());
	}


	private function importer(?BankTransactionSource $source = null): PaymentImporter
	{
		$logger = new class implements ILogger {
			public function log(mixed $value, string $level = self::INFO): void
			{
				throw new \LogicException('Unexpected log: ' . (is_string($value) ? $value : get_debug_type($value)));
			}
		};
		$mailer = new ReservationMailer(
			$this->reservations(),
			$this->settings(),
			$this->sender,
			$this->service(LatteFactory::class),
			new QrPayment($this->settings()),
			$logger,
			$this->eventLog(),
		);
		return new PaymentImporter(
			$source ?? new MockBankSource($this->db),
			$this->matcher,
			$mailer,
			$this->settings(),
			$this->clock,
			$this->db,
			$this->eventLog(),
			$this->actor(),
		);
	}


	/** @param list<BankTransaction> $transactions */
	private function fakeSource(array $transactions, int $minInterval = 0): BankTransactionSource
	{
		return new class ($transactions, $minInterval) implements BankTransactionSource {
			/** @param list<BankTransaction> $transactions */
			public function __construct(private array $transactions, private int $minInterval)
			{
			}


			public function name(): string
			{
				return 'fio';
			}


			public function minIntervalSeconds(): int
			{
				return $this->minInterval;
			}


			public function fetchNew(): array
			{
				[$batch, $this->transactions] = [$this->transactions, []];
				return $batch;
			}
		};
	}


	/** A confirmed reservation with the given price (seats at 350, standing at 250). */
	private function reservation(int $price): int
	{
		$this->setSettings(['price_seat' => (string) $price]);
		$service = $this->reservations();
		$owner = 'owner-' . uniqid();
		$service->start($owner, $owner . '@example.com');
		$service->hold($owner, 101 + (int) $this->db->query("SELECT COUNT(*) FROM seats WHERE state <> 'free'")->fetchColumn());
		return $service->confirm($owner, 'Platící Host', '', true);
	}


	private function vs(int $reservationId): string
	{
		return VariableSymbol::format('2026', $reservationId);
	}


	/** @return array{string, int} status and paid amount in CZK */
	private function reservationState(int $id): array
	{
		$row = $this->db->query("SELECT status, paid_amount FROM reservations WHERE id = $id")->fetch();
		return [(string) $row['status'], (int) $row['paid_amount']];
	}


	private function lastStatus(): string
	{
		return (string) $this->db->query('SELECT match_status FROM bank_transactions ORDER BY id DESC LIMIT 1')->fetchColumn();
	}
}
