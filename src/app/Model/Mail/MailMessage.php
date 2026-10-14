<?php

declare(strict_types=1);

namespace App\Model\Mail;


final class MailMessage
{
	/**
	 * @param array<string, string> $inlineImages content id => PNG data, referenced as <img src="cid:...">
	 */
	public function __construct(
		public readonly string $to,
		public readonly string $subject,
		public readonly string $html,
		public readonly string $text,
		public readonly array $inlineImages = [],
	) {
	}
}
