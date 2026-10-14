<?php

declare(strict_types=1);

namespace App\Tests\Model\Hall;

use App\Model\Hall\HallPlanParser;
use PHPUnit\Framework\TestCase;


final class HallPlanParserTest extends TestCase
{
	public function testReadsTablesAndSeats(): void
	{
		$plan = HallPlanParser::parse(self::fixture());

		self::assertTrue($plan->isValid(), implode("\n", $plan->errors));
		self::assertSame([
			1 => ['svg_id' => 'table-1', 'name' => 'Jaroslav Foglar Jestřáb'],
			2 => ['svg_id' => 'table-2', 'name' => null],
		], $plan->tables);
		self::assertSame([1, 2, 3, 4, 5, 10], array_keys($plan->seats));
		self::assertSame(['svg_id' => 'seat-3', 'table' => 1], $plan->seats[3]);
		self::assertSame(2, $plan->seats[10]['table']);
	}


	public function testRemovesEverythingThatCouldRunOrLoadSomething(): void
	{
		$svg = HallPlanParser::parse(self::fixture())->svg;

		foreach (['<script', 'alert', 'onload', 'onclick', 'foreignObject', 'evil.example', 'javascript:', '@import',
			'<metadata', 'corel:', 'xml-stylesheet', 'DOCTYPE', '<!--'] as $forbidden) {
			self::assertStringNotContainsStringIgnoringCase($forbidden, $svg, "Removed: $forbidden");
		}
		self::assertStringStartsWith('<svg', $svg);
		self::assertStringContainsString('data:image/png;base64,', $svg, 'Embedded raster images stay');
		self::assertStringContainsString('url(#grad)', $svg, 'References inside the document stay');
		self::assertStringContainsString('<title>Pódium</title>', $svg, 'Decoration stays');
	}


	public function testSeatsLoseTheDrawingsColours(): void
	{
		$svg = HallPlanParser::parse(self::fixture())->svg;

		self::assertMatchesRegularExpression('~<circle id="seat-1" cx="60" cy="50" r="8" class="seat"/>~', $svg);
		self::assertStringContainsString('<g id="seat-3" class="seat"><rect x="132" y="42" width="16" height="16"/><path d="M132 42h16"/></g>', $svg);
		self::assertStringContainsString('<rect class="fil0" x="40"', $svg, 'Other shapes keep their classes');
	}


	public function testReportsProblems(): void
	{
		$plan = HallPlanParser::parse(<<<'SVG'
			<svg xmlns="http://www.w3.org/2000/svg">
				<g id="table-1"><circle id="seat-1"/><circle id="seat-1"/></g>
				<circle id="seat-2"/>
				<g id="table-3"><rect/></g>
			</svg>
			SVG);

		self::assertFalse($plan->isValid());
		self::assertSame([
			'Id „seat-1“ je v plánku víckrát.',
			'Místo 2 neleží v žádném stole (skupině s id table-<číslo>).',
			'Stůl 3 nemá žádná místa.',
		], $plan->errors);
	}


	public function testRejectsWhatIsNotAnSvg(): void
	{
		foreach (['', 'not xml', '<html xmlns="http://www.w3.org/1999/xhtml"/>', '<svg/>'] as $input) {
			self::assertSame(['Soubor není platný obrázek SVG.'], HallPlanParser::parse($input)->errors, "Input: $input");
		}
		self::assertSame(
			['Plánek neobsahuje žádná místa (tvary s id seat-<číslo>).'],
			HallPlanParser::parse('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>')->errors,
		);
	}


	private static function fixture(): string
	{
		return (string) file_get_contents(__DIR__ . '/../../fixtures/hall-small.svg');
	}
}
