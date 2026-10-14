<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Check;

use App\Presentation\Admin\BaseTemplate;


final class CheckTemplate extends BaseTemplate
{
	/** Initial data of the check app, JSON printed raw into <script type="application/json">. */
	public string $data;
}
