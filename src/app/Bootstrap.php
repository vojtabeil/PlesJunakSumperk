<?php

declare(strict_types=1);

namespace App;

use Nette\Bootstrap\Configurator;

final class Bootstrap
{
	public static function boot(bool $tracy = true): Configurator
	{
		$rootDir = dirname(__DIR__);
		$configurator = new Configurator;

		// Debug mode only for requests from localhost (Nette's detection). Never in a release build
		// (it contains the file VERSION) - not even when a proxy makes visitors look local.
		// APP_ENV=production forces production mode too.
		$production = is_file($rootDir . '/VERSION') || getenv('APP_ENV') === 'production';
		$configurator->setDebugMode(!$production && Configurator::detectDebugMode());
		if ($tracy) {
			$configurator->enableTracy($rootDir . '/var/log');
		}
		$configurator->setTempDirectory($rootDir . '/var/temp');
		$configurator->addStaticParameters(['rootDir' => $rootDir]);

		$configurator->addConfig($rootDir . '/config/common.neon');
		$configurator->addConfig($rootDir . '/config/services.neon');
		$configurator->addConfig($rootDir . '/config/local.neon');
		return $configurator;
	}


	/** Container for PHPUnit: local config with the test database (config/test.neon). */
	public static function bootForTests(): Configurator
	{
		$configurator = self::boot(tracy: false); // PHPUnit handles errors itself
		$configurator->setDebugMode(true);
		$configurator->addConfig(dirname(__DIR__) . '/config/test.neon');
		return $configurator;
	}
}
