<?php

declare(strict_types=1);

namespace App\Presentation\Error\Error4xx;

use App\Presentation\Front\BaseTemplate;


final class Error4xxTemplate extends BaseTemplate
{
	public int $httpCode;
}
