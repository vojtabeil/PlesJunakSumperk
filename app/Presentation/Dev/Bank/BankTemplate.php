<?php

declare(strict_types=1);

namespace App\Presentation\Dev\Bank;

use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\Template;


final class BankTemplate extends Template
{
	public string $basePath;

	/** @var list<\stdClass> */
	public array $flashes;

	public Presenter $presenter;

	public Control $control;

	/** @var list<array<string, mixed>> */
	public array $transactions;
}
