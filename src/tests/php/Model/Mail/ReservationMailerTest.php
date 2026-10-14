<?php

declare(strict_types=1);

namespace App\Tests\Model\Mail;

use App\Model\Mail\MailMessage;
use App\Model\Mail\MailSender;
use App\Model\Mail\ReservationMailer;
use App\Tests\DatabaseTestCase;
use App\Tests\RecordingMailSender;
use RuntimeException;
use Tracy\ILogger;


final class ReservationMailerTest extends DatabaseTestCase
{
	public function testSendsConfirmationAndRecordsIt(): void
	{
		$id = $this->confirmedReservation();
		$sender = new RecordingMailSender;

		self::assertTrue($this->mailerFor($sender)->sendConfirmation($id));

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

		$token = (string) $this->db->query("SELECT access_token FROM reservations WHERE id = $id")->fetchColumn();
		self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
		self::assertStringContainsString("/rezervace/$id/$token", $message->text, 'Link to "Moje rezervace"');
		self::assertStringContainsString("/rezervace/$id/$token", $message->html);
		$due = (new \DateTimeImmutable('today'))->modify('+2 days')->format('j. n. Y');
		self::assertStringContainsString("do $due", $message->text, 'Due date = today + payment_days');
		self::assertStringContainsString('dotazy@example.com', $message->text, 'Contact of the organizers');

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

		self::assertFalse($this->mailerFor($sender)->sendConfirmation($id));

		$row = $this->db->query("SELECT email_sent_at, email_error FROM reservations WHERE id = $id")->fetch();
		self::assertNull($row['email_sent_at']);
		self::assertSame('SMTP down', $row['email_error']);
	}


	public function testReminderGoesOnlyToOverdueReservationsOnce(): void
	{
		$overdue = $this->confirmedReservation();
		$this->db->exec("UPDATE reservations SET confirmed_at = NOW() - INTERVAL 3 DAY WHERE id = $overdue");
		$admin = $this->reservationAdmin();
		self::assertSame([$overdue], $admin->toRemind());
		self::assertSame(1, $admin->problemCounts()['overdue']);

		$sender = new RecordingMailSender;
		self::assertTrue($this->mailerFor($sender)->sendReminder($overdue));

		self::assertSame("Připomínka platby – rezervace č. $overdue – Testovací ples", $sender->sent[0]->subject);
		self::assertStringContainsString("600\u{A0}Kč", $sender->sent[0]->text);
		self::assertSame([], $admin->toRemind(), 'Reminded now: not again before another payment period');
		self::assertContains('email.reminder_sent', $this->loggedActions());
	}


	public function testDueDayItselfIsNotOverdue(): void
	{
		$id = $this->confirmedReservation();
		$this->db->exec("UPDATE reservations SET confirmed_at = NOW() - INTERVAL 2 DAY WHERE id = $id");
		self::assertSame([], $this->reservationAdmin()->toRemind());
	}


	private function mailerFor(MailSender $sender): ReservationMailer
	{
		$logger = new class implements ILogger {
			/** @var list<mixed> */
			public array $logged = [];


			public function log(mixed $value, string $level = self::INFO): void
			{
				$this->logged[] = $value;
			}
		};
		return $this->mailer($sender, $logger);
	}


	private function confirmedReservation(): int
	{
		$service = $this->reservations();
		$service->setEmail('owner', 'jana@example.com');
		$service->hold('owner', 101);
		$service->setStanding('owner', 1);
		return $service->confirm('owner', 'Jana Nováková', '', true);
	}
}
