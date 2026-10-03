<?php

declare(strict_types=1);

namespace App\Core;

use Nette\Application\Routers\RouteList;


final class RouterFactory
{
	public static function createRouter(): RouteList
	{
		$router = new RouteList;
		$router->withModule('Admin')
			->addRoute('admin/<presenter=Dashboard>/<action=default>[/<id \d+>]');
		$router->addRoute('api/<op>', 'Front:Api:default');
		$router->addRoute('cron/payments', 'Front:Cron:payments');
		$router->addRoute('rezervace/<id \d+>/<token [0-9a-f]{32}>', 'Front:Reservation:default');
		$router->addRoute('tester/<token [0-9a-f]{32}>', 'Front:Access:tester');
		$router->addRoute('vip/<token [0-9a-f]{32}>', 'Front:Access:vip');
		// Dev tools are not part of the release build (dev/release.ps1 leaves app/Presentation/Dev out).
		if (class_exists(\App\Presentation\Dev\Status\StatusPresenter::class)) {
			$router->addRoute('dev/status', 'Dev:Status:default');
			$router->addRoute('dev/bank', 'Dev:Bank:default');
		}
		$router->addRoute('', 'Front:Home:default');
		return $router;
	}
}
