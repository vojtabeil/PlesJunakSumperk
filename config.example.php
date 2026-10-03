<?php
// Example configuration. setup.cmd copies it to config.local.php (not committed).
// On Lebeda, config.local.php holds the credentials from the hosting administration.

return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3307,
        'name'    => 'ples',
        'user'    => 'ples',
        'pass'    => 'ples',
        'charset' => 'utf8mb4',
    ],
    // Outgoing e-mail over SMTP. Locally everything goes to Mailpit (http://127.0.0.1:8025).
    // Production example (Seznam): host smtp.seznam.cz, port 465, secure 'ssl', user/pass of the mailbox.
    'mail' => [
        'host'      => '127.0.0.1',
        'port'      => 1025,
        'secure'    => '',        // '', 'ssl' (SMTPS) or 'tls' (STARTTLS)
        'user'      => '',        // empty = no SMTP authentication
        'pass'      => '',
        'from'      => 'ples@junak-sumperk.cz',
        'from_name' => 'Šumperský skautský ples',
        'reply_to'  => '',
    ],
    'debug' => true,
];
