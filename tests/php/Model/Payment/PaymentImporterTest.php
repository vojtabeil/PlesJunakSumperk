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
use App\Tests\DatabaseTestCase;
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
		$this->matcher = new PaymentMatcher($this->db);
		$this->clock = new FrozenClock;
		$this->sender = new RecordingMailSender;
	}


	public function testExactPaymentMarksReservationPaidAndSendsEmail(): void
	{
		$id = $this->reservation(950);
		$this->bank->addPayment(95000, (string) $id);

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
		$this->bank->addPayment(50000, (string) $id);
		$this->importer()->import();
		self::assertSame(['partially_paid', 500], $this->reservationState($id));
		self::assertSame('underpaid', $this->lastStatus());
		self::assertStringContainsString("450\u{A0}Kč", $this->sender->sent[0]->text, 'E-mail tells the remaining amount');

		$this->bank->addPayment(45000, '000' . $id); // leading zeros in VS are fine
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
		$this->bank->addPayment(35000, (string) $id);
		$this->bank->addPayment(35000, (string) $id);

		$this->importer()->import();

		self::assertSame(['paid', 700], $this->reservationState($id));
		self::assertSame('overpaid', $this->lastStatus());
		self::assertCount(1, $this->sender->sent, 'Only the first full payment is announced');
	}


	public function testPaymentsThatCannotBeMatched(): void
	{
		$this->reservation(350);
		$this->bank->addPayment(35000, null);       // no VS
		$this->bank->addPayment(35000, '999');      // unknown reservation
		$this->bank->addPayment(-12000, '1');       // outgoing

		$result = $this->importer()->import();

		self::assertSame(2, $result->unmatched);
		self::assertSame(
			['no_vs', 'unknown_vs', 'outgoing'],
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
		$tx = new BankTransaction('fio-1', '2026-02-01', 35000, variableSymbol: (string) $id);
		$source = $this->fakeSource([$tx, $tx]);

		$result = $this->importer($source)->import();

		self::assertSame([2, 1, 1], [$result->fetched, $result->stored, $result->duplicates]);
		self::assertSame(['paid', 350], $this->reservationState($id));
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
		);
		return new PaymentImporter(
			$source ?? new MockBankSource($this->db),
			$this->matcher,
			$mailer,
			$this->settings(),
			$this->clock,
			$this->db,
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
