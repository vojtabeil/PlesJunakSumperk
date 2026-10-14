<?php

declare(strict_types=1);

namespace App\Presentation\Admin\User;

use App\Presentation\Admin\BaseTemplate;


final class UserTemplate extends BaseTemplate
{
	/** @var list<array<string, mixed>> */
	public array $accounts;

	/** @var array<string, mixed>|null */
	public ?array $account;

	public int $currentId;

	public bool $isLocked;
}
