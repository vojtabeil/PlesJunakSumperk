<?php

declare(strict_types=1);

namespace App\Presentation\Front;

use Nette\Application\UI\Control;
use Nette\Application\UI\Presenter;
use Nette\Bridges\ApplicationLatte\Template;


/**
 * Variables available in every public page (and in @layout.latte).
 * Nette fills the standard ones ($basePath, ...) only when they are declared here.
 */
abstract class BaseTemplate extends Template
{
	public string $basePath;

	public string $baseUrl;

	/** @var list<\stdClass> */
	public array $flashes;

	public Presenter $presenter;

	public Control $control;

	/** @var array<string, string> */
	public array $settings;
}
