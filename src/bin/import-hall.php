<?php

/**
 * Replaces the hall plan in the local database with an SVG (same code as Nebezpečná zóna -> Plánek sálu).
 *
 *   ..\dev\php.cmd bin/import-hall.php <file.svg>           prints the changes; stops when reservations would be cancelled
 *   ..\dev\php.cmd bin/import-hall.php <file.svg> --cancel  also cancels the reservations of removed seats
 */

declare(strict_types=1);

use App\Model\Hall\HallPlanParser;
use App\Model\Hall\HallSync;

require __DIR__ . '/../vendor/autoload.php';

$file = array_values(array_filter(array_slice($argv, 1), fn(string $a): bool => !str_starts_with($a, '--')))[0] ?? null;
$cancel = in_array('--cancel', $argv, true);
if ($file === null || !is_file($file)) {
	fwrite(STDERR, "Usage: php bin/import-hall.php <file.svg> [--cancel]\n");
	exit(1);
}

$plan = HallPlanParser::parse((string) file_get_contents($file));
if (!$plan->isValid()) {
	fwrite(STDERR, "The plan has errors:\n  " . implode("\n  ", $plan->errors) . "\n");
	exit(1);
}

// Local tool: debug mode makes Nette recompile the container when the config changed.
$configurator = App\Bootstrap::boot(tracy: false);
$configurator->setDebugMode(true);
$sync = $configurator->createContainer()->getByType(HallSync::class);

$changes = $sync->preview($plan);
foreach ($changes->counts() as $what => $count) {
	echo "$what: $count\n";
}
if ($changes->affected && !$cancel) {
	fwrite(STDERR, 'Reservations that would be cancelled: ' . implode(', ', $changes->affectedIds()) . "\nRun again with --cancel.\n");
	exit(1);
}
$sync->apply($plan, basename($file), $changes->affectedIds());
printf("Hall plan replaced: %d tables, %d seats.\n", count($plan->tables), count($plan->seats));
