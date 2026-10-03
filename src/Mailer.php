<?php
// Thin wrapper around PHPMailer (SMTP only). Configured by the 'mail' section of config.

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/vendor/phpmailer/Exception.php';
require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/vendor/phpmailer/SMTP.php';

final class Mailer
{
    public function __construct(private readonly array $config)
    {
        foreach (['host', 'port', 'from'] as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException("Mail config is missing '$key' (see config.example.php).");
            }
        }
    }

    /** Sends one message; throws PHPMailer\PHPMailer\Exception on failure. */
    public function send(string $to, string $subject, string $html, string $text): void
    {
        $c = $this->config;
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $c['host'];
        $mail->Port = (int) $c['port'];
        $mail->Timeout = 15;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->Encoding = PHPMailer::ENCODING_BASE64;

        $secure = $c['secure'] ?? '';
        if ($secure === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($secure === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPAutoTLS = false;
        }
        if (($c['user'] ?? '') !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $c['user'];
            $mail->Password = $c['pass'] ?? '';
        }

        $mail->setFrom($c['from'], $c['from_name'] ?? '');
        if (($c['reply_to'] ?? '') !== '') {
            $mail->addReplyTo($c['reply_to']);
        }
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->AltBody = $text;
        $mail->send();
    }
}
