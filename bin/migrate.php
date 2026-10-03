<?php

/**
 * Applies pending database migrations (migrations/*.sql).
 * Usage: php bin/migrate.php [--test]   (--test = the PHPUnit database from config/test.neon)
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$configurator = in_array('--test', $argv, true)
	? App\Bootstrap::bootForTests()
	: App\Bootstrap::boot();
$migrator = $configurator->createContainer()->getByType(App\Model\Database\Migrator::class);

$applied = $migrator->migrate();
echo $applied ? 'Applied: ' . implode(', ', $applied) . PHP_EOL : 'Database is up to date.' . PHP_EOL;
