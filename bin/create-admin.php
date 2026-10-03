<?php

/**
 * Creates an organizer account with a random password.
 *
 *   php.cmd bin/create-admin.php <login> "<name>"         creates it in the local database
 *   php.cmd bin/create-admin.php <login> "<name>" --sql   only prints an INSERT for phpMyAdmin (production)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$args = array_values(array_filter(array_slice($argv, 1), fn(string $a): bool => $a !== '--sql'));
$sqlOnly = in_array('--sql', $argv, true);
[$login, $name] = $args + [null, null];
if ($login === null || $name === null || !preg_match('/^[a-z0-9._-]{3,64}$/', $login)) {
	fwrite(STDERR, "Usage: php bin/create-admin.php <login> \"<name>\" [--sql]\n"
		. "Login: 3-64 characters a-z 0-9 . _ -\n");
	exit(1);
}

$password = Nette\Utils\Random::generate(16, '0-9a-zA-Z');

if ($sqlOnly) {
	$hash = (new Nette\Security\Passwords)->hash($password);
	$quote = fn(string $s): string => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $s) . "'";
	echo "INSERT INTO admin_users (login, name, password_hash) VALUES ({$quote($login)}, {$quote($name)}, {$quote($hash)});\n";
} else {
	// Local tool: debug mode makes Nette recompile the container when the config changed.
	$configurator = App\Bootstrap::boot(tracy: false);
	$configurator->setDebugMode(true);
	$container = $configurator->createContainer();
	$container->getByType(App\Model\Admin\AdminUsers::class)->create($login, $name, $password);
	echo "Account '$login' created.\n";
}
echo "Password: $password\n(Change it after the first login: Můj účet -> Změna hesla.)\n";
