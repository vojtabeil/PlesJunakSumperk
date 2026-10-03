<?php

declare(strict_types=1);

namespace App\Tests\Model\Reservation;

use App\Model\Reservation\ReservationError;
use App\Tests\DatabaseTestCase;


final class ReservationServiceTest extends DatabaseTestCase
{
	private const Alice = 'owner-alice';
	private const Bob = 'owner-bob';


	public function testRejectsInvalidEmail(): void
	{
		$this->expectExceptionObject(new ReservationError('Zadejte platný e-mail.'));
		$this->reservations()->start(self::Alice, 'not-an-email');
	}


	public function testRequiresEmailBeforeHolding(): void
	{
		$this->expectExceptionObject(new ReservationError('Nejdřív zadejte svůj e-mail.'));
		$this->reservations()->hold(self::Alice, 101);
	}


	public function testHoldsSeatOnlyForTheFirstOwner(): void
	{
		$service = $this->reservations();
		$service->start(self::Alice, 'Alice@Example.com');
		$service->start(self::Bob, 'bob@example.com');
		$service->hold(self::Alice, 101);

		$alice = $service->state(self::Alice);
		self::assertSame([101], $alice['mine']);
		self::assertSame('alice@example.com', $alice['reservation']['email']);
		self::assertSame(120, $alice['reservation']['expires_in']);

		$bob = $service->state(self::Bob);
		self::assertSame([101], $bob['taken'], 'Bob sees the seat as taken, without any reservation id');
		self::assertSame([], $bob['mine']);

		$this->expectExceptionObject(new ReservationError('Toto místo už je obsazené.'));
		$service->hold(self::Bob, 101);
	}


	public function testEnforcesTicketLimitAcrossSeatsAndStanding(): void
	{
		$this->setSettings(['max_ticket' => '3']);
		$service = $this->reservations();
		$service->start(self::Alice, 'alice@example.com');
		$service->hold(self::Alice, 101);
		$service->setStanding(self::Alice, 2);

		$this->expectExceptionObject(new ReservationError('Na jednu rezervaci lze koupit nejvýše 3 lístků.'));
		$service->hold(self::Alice, 102);
	}


	public function testReleasesExpiredHolds(): void
	{
		$service = $this->reservations();
		$service->start(self::Alice, 'alice@example.com');
		$service->hold(self::Alice, 101);
		$this->db->exec('UPDATE seats SET booked_at = NOW() - INTERVAL 121 SECOND');

		$service->releaseExpiredHolds();

		$state = $service->state(self::Alice);
		self::assertSame([], $state['mine']);
		self::assertNull($state['reservation']['expires_in']);
	}


	public function testConfirmReservesSeatsAndComputesPrice(): void
	{
		$service = $this->reservations();
		$service->start(self::Alice, 'alice@example.com');
		$service->hold(self::Alice, 101);
		$service->hold(self::Alice, 102);
		$service->setStanding(self::Alice, 1);

		$id = $service->confirm(self::Alice, '  Alice   Nováková ', '+420 777 123 456', true);

		$reservation = $service->findFinished($id);
		self::assertNotNull($reservation);
		self::assertSame('confirmed', $reservation['status']);
		self::assertSame('Alice Nováková', $reservation['name']);
		self::assertSame(950, (int) $reservation['total_price']);
		self::assertSame(['1/1', '1/2'], $reservation['seat_labels']);
		self::assertNull($service->state(self::Alice)['reservation'], 'The draft is gone after confirming');
		self::assertSame([101, 102], $service->state(self::Bob)['taken']);
	}


	/** @return list<array{string, string, bool, string}> */
	public static function invalidConfirmations(): array
	{
		return [
			['X', '', true, 'Vyplňte jméno a příjmení.'],
			['Alice Nováková', 'abc', true, 'Telefon zadejte jen jako čísla, např. +420 777 123 456.'],
			['Alice Nováková', '', false, 'Pro rezervaci je potřeba souhlas se zpracováním osobních údajů.'],
		];
	}


	#[\PHPUnit\Framework\Attributes\DataProvider('invalidConfirmations')]
	public function testValidatesConfirmation(string $name, string $phone, bool $consent, string $error): void
	{
		$service = $this->reservations();
		$service->start(self::Alice, 'alice@example.com');
		$service->hold(self::Alice, 101);

		$this->expectExceptionObject(new ReservationError($error));
		$service->confirm(self::Alice, $name, $phone, $consent);
	}


	public function testRejectsEmptyConfirmation(): void
	{
		$service = $this->reservations();
		$service->start(self::Alice, 'alice@example.com');

		$this->expectExceptionObject(new ReservationError('Vyberte alespoň jeden lístek. Pokud jste místa vybrali dříve, mohla vypršet.'));
		$service->confirm(self::Alice, 'Alice Nováková', '', true);
	}


	public function testOneEmailCanHaveMoreReservations(): void
	{
		$service = $this->reservations();
		$service->start(self::Alice, 'alice@example.com');
		$service->hold(self::Alice, 101);
		$first = $service->confirm(self::Alice, 'Alice Nováková', '', true);

		// Same person again from the same browser, and a third one from another browser.
		$service->start(self::Alice, 'alice@example.com');
		$service->hold(self::Alice, 102);
		$second = $service->confirm(self::Alice, 'Alice Nováková', '', true);
		$service->start(self::Bob, 'Alice@Example.com');
		$service->hold(self::Bob, 103);
		$third = $service->confirm(self::Bob, 'Alice pro rodiče', '', true);

		self::assertCount(3, array_unique([$first, $second, $third]));
		self::assertSame(3, (int) $this->db->query("SELECT COUNT(*) FROM reservations WHERE email = 'alice@example.com'")->fetchColumn());
	}


	public function testEmailOfAnotherReservationRevealsAndChangesNothing(): void
	{
		$service = $this->reservations();
		$service->start(self::Alice, 'alice@example.com');
		$service->hold(self::Alice, 101);
		$service->setStanding(self::Alice, 2);
		$service->start('owner-carol', 'carol@example.com');
		$service->hold('owner-carol', 201);
		$service->confirm('owner-carol', 'Carol Test', '', true);

		// Bob types the e-mails of Alice (draft) and Carol (confirmed): same answer as for anyone.
		$service->start(self::Bob, 'alice@example.com');
		$service->start(self::Bob, 'carol@example.com');

		$alice = $service->state(self::Alice);
		self::assertSame([101], $alice['mine'], "Alice's draft and held seat are untouched");
		self::assertSame(2, $alice['reservation']['standing']);
		$bob = $service->state(self::Bob);
		self::assertSame('carol@example.com', $bob['reservation']['email']);
		self::assertSame([], $bob['mine']);
		self::assertSame(0, $bob['reservation']['standing']);
	}


	public function testChangingEmailKeepsTheHeldSeats(): void
	{
		$service = $this->reservations();
		$service->start(self::Alice, 'alice@example.com');
		$service->hold(self::Alice, 101);

		$service->start(self::Alice, 'alice.novakova@example.com');

		$state = $service->state(self::Alice);
		self::assertSame('alice.novakova@example.com', $state['reservation']['email']);
		self::assertSame([101], $state['mine']);
	}


	public function testStandingCapacityCountsOnlyFinishedReservations(): void
	{
		$this->setSettings(['standing_capacity' => '2']);
		$service = $this->reservations();
		$service->start(self::Alice, 'alice@example.com');
		$service->setStanding(self::Alice, 2);
		$service->confirm(self::Alice, 'Alice Nováková', '', true);

		$service->start(self::Bob, 'bob@example.com');
		$this->expectExceptionObject(new ReservationError('Lístky bez místenky jsou vyprodané.'));
		$service->setStanding(self::Bob, 1);
	}


	public function testClosedSaleRejectsChanges(): void
	{
		$this->setSettings(['site_mode' => 'closed']);
		$this->expectExceptionObject(new ReservationError('Prodej lístků je ukončený.'));
		$this->reservations()->start(self::Alice, 'alice@example.com');
	}


	public function testCancelReleasesSeats(): void
	{
		$service = $this->reservations();
		$service->start(self::Alice, 'alice@example.com');
		$service->hold(self::Alice, 101);

		$service->cancel(self::Alice);

		self::assertNull($service->state(self::Alice)['reservation']);
		self::assertSame([], $service->state(self::Bob)['taken']);
	}


	public function testHallLayoutKeepsLabelsAsStrings(): void
	{
		$layout = $this->reservations()->hallLayout();
		self::assertSame('1', $layout['tables'][0]['label']);
		self::assertSame(101, $layout['seats'][0]['id']);
		self::assertCount(8, $layout['seats']);
	}
}
