<?php

declare(strict_types=1);

namespace App\Model\Http;


final class HttpResponse
{
	public function __construct(
		public readonly int $status,
		public readonly string $body,
	) {
	}
}
