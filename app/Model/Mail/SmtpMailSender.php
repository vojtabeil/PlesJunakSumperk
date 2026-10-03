<?php

declare(strict_types=1);

namespace App\Model\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;


/** Sends e-mail over SMTP (Mailpit locally, Lebeda or Seznam SMTP in production). */
final class SmtpMailSender implements MailSender
{
	/**
	 * @param array{host?: string, port?: int, secure?: string, user?: string, password?: string,
	 *     from?: string, fromName?: string, replyTo?: string} $config
	 */
	public function __construct(
		private readonly array $config,
	) {
	}


	public function send(MailMessage $message): void
	{
		$c = $this->config;
		foreach (['host', 'port', 'from'] as $key) {
			if (empty($c[$key])) {
				throw new RuntimeException("Mail config is missing '$key' (see config/local.neon.example).");
			}
		}

		$mail = new PHPMailer(exceptions: true);
		$mail->isSMTP();
		$mail->Host = $c['host'];
		$mail->Port = (int) $c['port'];
		$mail->Timeout = 15;
		$mail->CharSet = PHPMailer::CHARSET_UTF8;
		$mail->Encoding = PHPMailer::ENCODING_BASE64;

		match ($c['secure'] ?? '') {
			'ssl' => $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS,
			'tls' => $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS,
			default => $mail->SMTPAutoTLS = false,
		};
		if (($c['user'] ?? '') !== '') {
			$mail->SMTPAuth = true;
			$mail->Username = $c['user'];
			$mail->Password = $c['password'] ?? '';
		}

		$mail->setFrom($c['from'], $c['fromName'] ?? '');
		if (($c['replyTo'] ?? '') !== '') {
			$mail->addReplyTo($c['replyTo']);
		}
		$mail->addAddress($message->to);
		$mail->Subject = $message->subject;
		$mail->isHTML(true);
		$mail->Body = $message->html;
		$mail->AltBody = $message->text;
		$mail->send();
	}
}
