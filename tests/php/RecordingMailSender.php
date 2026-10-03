<?php

declare(strict_types=1);

namespace App\Tests;

use App\Model\Mail\MailMessage;
use App\Model\Mail\MailSender;


/** MailSender that only remembers the messages. */
final class RecordingMailSender implements MailSender
{
	/** @var list<MailMessage> */
	public array $sent = [];


	public function send(MailMessage $message): void
	{
		$this->sent[] = $message;
	}
}
