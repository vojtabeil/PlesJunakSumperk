<?php

declare(strict_types=1);

namespace App\Model\Http;


/** Minimal HTTP client, an interface so that tests never call real services. */
interface HttpClient
{
	/** @throws HttpException when no response was received (network, TLS, timeout) */
	public function get(string $url, int $timeoutSeconds = 30): HttpResponse;
}
