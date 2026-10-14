<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

// Links in e-mails are absolute; on the command line there is no host, so tests use this one.
$_SERVER['HTTP_HOST'] ??= 'ples.test';
