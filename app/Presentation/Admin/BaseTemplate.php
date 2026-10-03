<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\Template;
use Nette\Security\User;


/** Variables of every admin page (and of Admin/@layout.latte). */
abstract class BaseTemplate extends Template
{
	public string $basePath;

	public string $baseUrl;

	/** @var list<\stdClass> */
	public array $flashes;

	public Presenter $presenter;

	public Control $control;

	public User $user;

	public string $adminName;
}
