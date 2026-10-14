<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Model\Hall\HallPlanParser;
use App\Model\Hall\HallSync;
use App\Presentation\Accessory\SvgResponse;
use App\Tests\Support\DatabaseTestCase;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\UI\Presenter;


final class HallSvgTest extends DatabaseTestCase
{
	public function testNotFoundWithoutPlan(): void
	{
		$this->expectException(BadRequestException::class);
		$this->runHall();
	}


	public function testSendsThePlanAsAFile(): void
	{
		$svg = '<svg xmlns="http://www.w3.org/2000/svg"><g id="table-1"><circle id="seat-101" r="5"/></g></svg>';
		(new HallSync($this->db, $this->reservationAdmin(), $this->eventLog(), $this->actor()))
			->apply(HallPlanParser::parse($svg), 'sal.svg', []);

		$response = $this->runHall();
		self::assertInstanceOf(SvgResponse::class, $response);

		$http = new \Nette\Http\Response;
		ob_start();
		$response->send(new \Nette\Http\Request(new \Nette\Http\UrlScript('http://localhost/plan-salu.svg')), $http);
		$body = (string) ob_get_clean();
		self::assertStringStartsWith('<svg', $body);
		self::assertStringContainsString('class="seat"', $body);
	}


	private function runHall(): \Nette\Application\Response
	{
		$presenter = $this->service(IPresenterFactory::class)->createPresenter('Front:Hall');
		self::assertInstanceOf(Presenter::class, $presenter);
		$presenter->autoCanonicalize = false;
		return $presenter->run(new Request('Front:Hall', 'GET', ['action' => 'svg']));
	}
}
