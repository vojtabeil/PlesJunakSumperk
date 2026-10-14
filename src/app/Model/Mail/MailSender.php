<?php

declare(strict_types=1);

namespace App\Model\Mail;


interface MailSender
{
	/** @throws \Throwable when the message could not be handed over for delivery */
	public function send(MailMessage $message): void;
}
