<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Customer;

use App\Presentation\Admin\BaseTemplate;


final class CustomerTemplate extends BaseTemplate
{
	/** @var list<array{email: string, names: string, reservations: list<array<string, mixed>>, tickets: int, total: int, paid: int}> */
	public array $customers;
}
