<?php

declare(strict_types=1);

namespace App\Presentation\Accessory;

use Nette\Application\Response;
use Nette\Http\IRequest;
use Nette\Http\IResponse;


/**
 * An SVG sent as a file. The hall plan comes from an upload: even though it is sanitized, the headers
 * keep a browser from running anything in it when it is opened directly.
 */
final class SvgResponse implements Response
{
	/** @param int $maxAge seconds a browser may cache it (0 = always ask) */
	public function __construct(
		private readonly string $svg,
		private readonly string $fileName,
		private readonly bool $download = false,
		private readonly int $maxAge = 0,
	) {
	}


	public function send(IRequest $httpRequest, IResponse $httpResponse): void
	{
		$httpResponse->setContentType('image/svg+xml', 'utf-8');
		$httpResponse->setHeader('X-Content-Type-Options', 'nosniff');
		$httpResponse->setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
		$httpResponse->setHeader(
			'Content-Disposition',
			($this->download ? 'attachment' : 'inline') . '; filename="' . str_replace(['"', "\r", "\n"], '', $this->fileName) . '"',
		);
		$httpResponse->setHeader('Cache-Control', $this->maxAge > 0 ? "public, max-age=$this->maxAge, immutable" : 'no-cache');
		echo $this->svg;
	}
}
