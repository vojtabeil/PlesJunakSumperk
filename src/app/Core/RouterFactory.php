<?php

declare(strict_types=1);

namespace App\Core;

use Nette\Application\Routers\RouteList;


final class RouterFactory
{
	public static function createRouter(): RouteList
	{
		$router = new RouteList;
		// Entrance check: the QR code of a ticket holds /admin/kontrola/<code>.
		$router->addRoute('admin/kontrola/api/<op>', 'Admin:Check:api');
		$router->addRoute('admin/kontrola/sw.js', 'Admin:Check:sw');
		$router->addRoute('admin/kontrola[/<code [0-9A-Fa-f]{6}>]', 'Admin:Check:default');
		// Dangerous zone (replacing the hall plan, deleting test reservations).
		$router->addRoute('admin/nebezpecne/plan.svg', 'Admin:Danger:svg');
		$router->addRoute('admin/nebezpecne[/<action plan>]', 'Admin:Danger:default');
		// Z-lístky (free passes of the crew).
		$router->addRoute('admin/z-listky[/<action add|edit|print>][/<id \d+>]', 'Admin:Crew:default');
		$router->addRoute('admin/stoly', 'Admin:Tables:default');
		$router->withModule('Admin')
			->addRoute('admin/<presenter=Dashboard>/<action=default>[/<id \d+>]');
		$router->addRoute('api/<op>', 'Front:Api:default');
		$router->addRoute('cron/payments', 'Cron:payments');
		$router->addRoute('rezervace/<id \d+>/<token [0-9a-f]{32}>', 'Front:Reservation:default');
		$router->addRoute('plan-salu.svg', 'Front:Hall:svg');
		$router->addRoute('vstupenka/<code [0-9A-F]{6}>/<token [0-9a-f]{32}>', 'Front:Ticket:default');
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
