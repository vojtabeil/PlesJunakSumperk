<?php

declare(strict_types=1);

namespace App\Model\Reservation;

use Nette\Http\Session;
use Nette\Http\SessionSection;


/**
 * Per-browser data of the reservation flow: the owner key binding a draft to this browser,
 * the CSRF token of the JSON API and the reservations finished in this browser.
 */
final class ReservationSession
{
	public function __construct(
		private readonly Session $session,
	) {
	}


	/** Random key; unlike the session id it survives session id regeneration. */
	public function owner(): string
	{
		$section = $this->section();
		return $section->get('owner') ?? $this->store($section, 'owner', bin2hex(random_bytes(16)));
	}


	public function csrfToken(): string
	{
		$section = $this->section();
		return $section->get('csrf') ?? $this->store($section, 'csrf', bin2hex(random_bytes(32)));
	}


	public function isValidCsrfToken(string $token): bool
	{
		$expected = $this->section()->get('csrf');
		return is_string($expected) && hash_equals($expected, $token);
	}


	public function addFinished(int $reservationId): void
	{
		$section = $this->section();
		$ids = $section->get('finished') ?? [];
		$ids[] = $reservationId;
		$section->set('finished', array_values(array_unique($ids)));
	}


	public function isFinished(int $reservationId): bool
	{
		return in_array($reservationId, $this->section()->get('finished') ?? [], true);
	}


	private function section(): SessionSection
	{
		return $this->session->getSection('reservation');
	}


	private function store(SessionSection $section, string $key, string $value): string
	{
		$section->set($key, $value);
		return $value;
	}
}
