<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Core\Http\HttpClient;
use App\Core\Http\HttpResponse;


/** Returns prepared responses and remembers the requested URLs. */
final class FakeHttpClient implements HttpClient
{
	/** @var list<string> */
	public array $urls = [];


	public function __construct(
		private HttpResponse $response,
	) {
	}


	public function get(string $url, int $timeoutSeconds = 30): HttpResponse
	{
		$this->urls[] = $url;
		return $this->response;
	}
}
