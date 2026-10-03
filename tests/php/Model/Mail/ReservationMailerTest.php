<?php

declare(strict_types=1);

namespace App\Tests\Model\Mail;

use App\Model\Mail\MailMessage;
use App\Model\Mail\MailSender;
use App\Model\Mail\ReservationMailer;
use App\Model\Payment\QrPayment;
use App\Tests\DatabaseTestCase;
use App\Tests\RecordingMailSender;
use Nette\Bridges\ApplicationLatte\LatteFactory;
use RuntimeException;
use Tracy\ILogger;


final class ReservationMailerTest extends DatabaseTestCase
{
	public function testSendsConfirmationAndRecordsIt(): void
	{
		$id = $this->confirmedReservation();
		$sender = new RecordingMailSender;

		self::assertTrue($this->mailer($sender)->sendConfirmation($id));

		self::assertCount(1, $sender->sent);
		$message = $sender->sent[0];
		self::assertSame('jana@example.com', $message->to);
		self::assertSame("Potvrzení rezervace č. $id – Testovací ples", $message->subject);
		self::assertStringContainsString('stůl 1, místo 1', $message->text);
		self::assertStringContainsString("600\u{A0}Kč", $message->text);
		self::assertStringContainsString('Jana Nováková', $message->html);
		self::assertStringContainsString('Variabilní symbol: ' . sprintf('2026%04d', $id), $message->text);
		self::assertStringContainsString('2501895120/2010', $message->text);
		self::assertStringContainsString('cid:qr-platba', $message->html);
		self::assertStringStartsWith("\x89PNG", $message->inlineImages['qr-platba'] ?? '');

		$row = $this->db->query("SELECT email_sent_at, email_error FROM reservations WHERE id = $id")->fetch();
		self::assertNotNull($row['email_sent_at']);
		self::assertNull($row['email_error']);
	}


	public function testFailureIsRecordedAndDoesNotThrow(): void
	{
		$id = $this->confirmedReservation();
		$sender = new class implements MailSender {
			public function send(MailMessage $message): void
			{
				throw new RuntimeException('SMTP down');
			}
		};

		self::assertFalse($this->mailer($sender)->sendConfirmation($id));

		$row = $this->db->query("SELECT email_sent_at, email_error FROM reservations WHERE id = $id")->fetch();
		self::assertNull($row['email_sent_at']);
		self::assertSame('SMTP down', $row['email_error']);
	}


	private function mailer(MailSender $sender): ReservationMailer
	{
		$logger = new class implements ILogger {
			/** @var list<mixed> */
			public array $logged = [];


			public function log(mixed $value, string $level = self::INFO): void
			{
				$this->logged[] = $value;
			}
		};
		return new ReservationMailer(
			$this->reservations(),
			$this->settings(),
			$sender,
			$this->service(LatteFactory::class),
			new QrPayment($this->settings()),
			$logger,
		);
	}


	private function confirmedReservation(): int
	{
		$service = $this->reservations();
		$service->start('owner', 'jana@example.com');
		$service->hold('owner', 101);
		$service->setStanding('owner', 1);
		return $service->confirm('owner', 'Jana Nováková', '', true);
	}
}
