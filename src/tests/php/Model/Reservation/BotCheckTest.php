<?php

declare(strict_types=1);

namespace App\Tests\Model\Reservation;

use App\Model\Reservation\ReservationError;
use App\Tests\Support\DatabaseTestCase;


/** Protection against robots in ReservationService::confirm: the hidden field and the minimal time. */
final class BotCheckTest extends DatabaseTestCase
{
	public function testFilledHiddenFieldIsRefusedAndLogged(): void
	{
		$service = $this->reservations();
		$service->hold('robot', 101);

		try {
			$service->confirm('robot', 'Robot Robotí', '', true, 'robot@example.com', 'http://spam.example');
			self::fail('A filled hidden field must be refused');
		} catch (ReservationError $e) {
			self::assertStringContainsString('nepodařilo dokončit', $e->getMessage());
		}
		self::assertContains('reservation.bot_blocked', $this->loggedActions());
		self::assertSame('draft', $this->db->query('SELECT status FROM reservations')->fetchColumn(), 'Nothing confirmed');
	}


	public function testOrderConfirmedTooSoonIsRefusedUntilTheTimePasses(): void
	{
		$this->setSettings(['bot_min_seconds' => '3']);
		$service = $this->reservations();
		$service->hold('jana', 101);

		try {
			$service->confirm('jana', 'Jana Nováková', '', true, 'jana@example.com');
			self::fail('An order confirmed right after choosing must be refused');
		} catch (ReservationError) {
		}

		// A person takes longer: the same order goes through once the time has passed.
		$this->db->exec('UPDATE reservations SET created_at = NOW() - INTERVAL 10 SECOND');
		$id = $this->reservations()->confirm('jana', 'Jana Nováková', '', true, 'jana@example.com');
		self::assertSame('confirmed', $this->db->query("SELECT status FROM reservations WHERE id = $id")->fetchColumn());
	}
}
