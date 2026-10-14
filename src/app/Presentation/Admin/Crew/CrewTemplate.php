<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Crew;

use App\Presentation\Admin\BaseTemplate;


final class CrewTemplate extends BaseTemplate
{
	/** @var list<array<string, mixed>> members with their Z-lístek (CrewMembers::all / byIds) */
	public array $members;

	/** @var array<string, int> role => number of members */
	public array $roles;

	/** @var array{total: int, picked_up: int, emailed: int, used: int} */
	public array $stats;

	/** The crew timetable is filled in the settings. */
	public bool $hasSchedule;

	/** @var array<string, mixed>|null */
	public ?array $member;

	/** Public print link of the member's Z-lístek. */
	public string $printUrl;

	/** @var list<string> QR codes (SVG data URIs) of the printed members, in the same order */
	public array $qrCodes;

	/** @var list<list<string>> crew timetable printed on the passes, empty = none */
	public array $schedule;

	/** @var array<string, string> */
	public array $settings;
}
