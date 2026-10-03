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
    'debug' => true,
];
