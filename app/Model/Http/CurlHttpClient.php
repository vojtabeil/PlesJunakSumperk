<?php

declare(strict_types=1);

namespace App\Model\Http;


final class CurlHttpClient implements HttpClient
{
	public function get(string $url, int $timeoutSeconds = 30): HttpResponse
	{
		$curl = curl_init($url);
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => $timeoutSeconds,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_USERAGENT => 'ples-junak-sumperk',
		]);
		$body = curl_exec($curl);
		if ($body === false) {
			// The message never contains the URL (it may carry a secret token).
			throw new HttpException('HTTP request failed: ' . curl_error($curl));
		}
		return new HttpResponse((int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), (string) $body);
	}
}
