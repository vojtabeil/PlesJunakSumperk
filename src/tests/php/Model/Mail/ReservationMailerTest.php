<?php

declare(strict_types=1);

namespace App\Tests\Model\Mail;

use App\Model\Mail\MailMessage;
use App\Model\Mail\MailSender;
use App\Model\Mail\ReservationMailer;
use App\Model\Payment\PaymentChange;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\RecordingMailSender;
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
		self::assertStringContainsString('stůl A1, místo 1', $message->text);
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


	public function testTicketsAreSentOnlyForAPaidReservation(): void
	{
		$id = $this->confirmedReservation();
		$sender = new RecordingMailSender;
		self::assertFalse($this->mailerFor($sender)->sendTickets($id), 'Unpaid: no tickets yet');
		self::assertSame([], $sender->sent);
		self::assertContains('email.tickets_failed', $this->loggedActions());

		$this->reservationAdmin()->markPaid($id);
		$sender = new RecordingMailSender;
		self::assertTrue($this->mailerFor($sender)->sendTickets($id));

		$message = $sender->sent[0];
		self::assertSame("Vstupenky – rezervace č. $id – Testovací ples", $message->subject);
		$tickets = $this->tickets()->forReservation($id);
		self::assertCount(2, $tickets);
		foreach ($tickets as $ticket) {
			$code = $ticket['code'];
			self::assertStringContainsString("cid:ticket-$code", $message->html);
			self::assertStringStartsWith("\x89PNG", $message->inlineImages["ticket-$code"] ?? '');
			self::assertStringContainsString("/vstupenka/$code/{$ticket['print_token']}", $message->text);
		}
		self::assertStringContainsString('Stůl A1, místo 1', $message->text);
		self::assertStringContainsString('Bez místenky (na stání)', $message->text);
		self::assertContains('email.tickets_sent', $this->loggedActions());
	}


	public function testFullPaymentEmailCarriesTheTickets(): void
	{
		$id = $this->confirmedReservation();
		$sender = new RecordingMailSender;
		$mailer = $this->mailerFor($sender);

		$this->db->exec("UPDATE reservations SET status = 'partially_paid', paid_amount = 300 WHERE id = $id");
		self::assertTrue($mailer->sendPaymentUpdate(new PaymentChange($id, 'confirmed', 'partially_paid', 30000, 60000)));
		self::assertSame([], $sender->sent[0]->inlineImages, 'A partial payment has no tickets');

		$this->db->exec("UPDATE reservations SET status = 'paid', paid_amount = 600 WHERE id = $id");
		self::assertTrue($mailer->sendPaymentUpdate(new PaymentChange($id, 'partially_paid', 'paid', 60000, 60000)));
		self::assertCount(2, $sender->sent[1]->inlineImages);
		self::assertStringContainsString('/vstupenka/', $sender->sent[1]->html);
		self::assertContains('email.tickets_sent', $this->loggedActions());
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
