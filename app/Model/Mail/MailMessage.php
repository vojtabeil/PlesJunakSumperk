<?php

declare(strict_types=1);

namespace App\Model\Mail;


final class MailMessage
{
	public function __construct(
		public readonly string $to,
		public readonly string $subject,
		public readonly string $html,
		public readonly string $text,
	) {
	}
}
