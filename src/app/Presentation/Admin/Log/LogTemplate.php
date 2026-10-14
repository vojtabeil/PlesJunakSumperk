<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Log;

use App\Presentation\Admin\BaseTemplate;


final class LogTemplate extends BaseTemplate
{
	/** @var list<array<string, mixed>> */
	public array $events;

	public int $total;

	public int $page;

	public int $pages;

	/** @var array<string, string> */
	public array $categories;

	/** @var array<string, string> */
	public array $actors;

	public bool $filterActive;

	public bool $unknownReference;
}
