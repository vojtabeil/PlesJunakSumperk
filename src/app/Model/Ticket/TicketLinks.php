<?php

declare(strict_types=1);

namespace App\Model\Ticket;

use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Nette\Application\LinkGenerator;


/**
 * Absolute links of a ticket and its QR code. The QR code holds the link of the entrance check
 * (/admin/kontrola/<code>): the check app reads the code from it, and a phone camera opens the
 * check after the organizer logs in. A visitor learns nothing from it.
 */
final class TicketLinks
{
	public function __construct(
		private readonly LinkGenerator $linkGenerator,
	) {
	}


	public function checkUrl(string $code): string
	{
		return $this->linkGenerator->link('Admin:Check:default', ['code' => $code]);
	}


	/** Public page with one printable ticket (needs the secret print token). */
	public function printUrl(string $code, string $printToken): string
	{
		return $this->linkGenerator->link('Front:Ticket:default', ['code' => $code, 'token' => $printToken]);
	}


	/** PNG of the QR code (for e-mails, as an inline image). */
	public function png(string $code): string
	{
		return (new QRCode($this->options(QRGdImagePNG::class, base64: false)))->render($this->checkUrl($code));
	}


	/** SVG data URI of the QR code (for pages: sharp when printed). */
	public function svgDataUri(string $code): string
	{
		return (new QRCode($this->options(QRMarkupSVG::class, base64: true)))->render($this->checkUrl($code));
	}


	private function options(string $output, bool $base64): QROptions
	{
		return new QROptions([
			'outputInterface' => $output,
			'outputBase64' => $base64,
			'scale' => 6,
			'quietzoneSize' => 2,
		]);
	}
}
