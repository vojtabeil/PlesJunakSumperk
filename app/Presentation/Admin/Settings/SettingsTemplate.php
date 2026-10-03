<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Settings;

use App\Presentation\Admin\BaseTemplate;


final class SettingsTemplate extends BaseTemplate
{
	public bool $isPublic;

	public string $testerLink;

	public int $testCount;
}
