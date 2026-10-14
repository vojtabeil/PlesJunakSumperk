<?php

declare(strict_types=1);

namespace App\Presentation\Dev\Status;

use Nette\Bridges\ApplicationLatte\Template;


final class StatusTemplate extends Template
{
	/** @var list<array{name: string, loaded: bool}> */
	public array $extensions;

	public ?string $dbVersion;

	public ?string $dbError;

	/** @var array<string, int> */
	public array $tables;
}
