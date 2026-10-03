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
		$router->addRoute('hotovo/<id \d+>', 'Front:Done:default');
		$router->addRoute('dev/status', 'Dev:Status:default');
		$router->addRoute('', 'Front:Home:default');
		return $router;
	}
}
