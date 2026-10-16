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
			'A1' => ['svg_id' => 'table-A1', 'name' => 'Jaroslav Foglar Jestřáb'],
			'B2' => ['svg_id' => 'table-B2', 'name' => null],
		], $plan->tables);
		self::assertSame(['A1/1', 'A1/2', 'A1/3', 'B2/1', 'B2/2', 'B2/10'], array_keys($plan->seats), 'Hall order, numbers sorted naturally');
		self::assertSame(['svg_id' => 'seat-A1-3', 'table' => 'A1'], $plan->seats['A1/3']);
		self::assertSame('B2', $plan->seats['B2/10']['table']);
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

		self::assertMatchesRegularExpression('~<circle id="seat-A1-1" cx="60" cy="50" r="8" class="seat"/>~', $svg);
		self::assertStringContainsString('<g id="seat-A1-3" class="seat"><rect x="132" y="42" width="16" height="16"/><path d="M132 42h16"/></g>', $svg);
		self::assertStringContainsString('<rect class="fil0" x="40"', $svg, 'Other shapes keep their classes');
	}


	public function testJoinsTextsSplitByCorelDraw(): void
	{
		// CorelDRAW writes each line and each font run as a <text> with the same id.
		$plan = HallPlanParser::parse(<<<'SVG'
			<svg xmlns="http://www.w3.org/2000/svg">
				<g id="table-A36"><rect id="seat-A36-5"/></g>
				<text x="10" y="100" id="table-A36-name">Eduard Marek</text>
				<text x="10" y="120" id="table-A36-name">-Hrozný</text>
				<text x="40" y="120" id="table-A36-name">š-</text>
				<text id="label-seat-A36-5">5</text><text id="label-seat-A36-5">5</text>
			</svg>
			SVG);

		self::assertTrue($plan->isValid(), implode("\n", $plan->errors));
		self::assertSame('Eduard Marek -Hroznýš-', $plan->tables['A36']['name']);
	}


	public function testReportsProblems(): void
	{
		$plan = HallPlanParser::parse(<<<'SVG'
			<svg xmlns="http://www.w3.org/2000/svg">
				<g id="table-A1"><circle id="seat-A1-1"/><circle id="seat-A1-1"/><circle id="seat-A2-1"/></g>
				<circle id="seat-A1-2"/>
				<g id="table-A3"><rect/></g>
				<g id="table-A2"><circle id="seat-A2-2"/></g>
			</svg>
			SVG);

		self::assertFalse($plan->isValid());
		self::assertSame([
			'Id „seat-A1-1“ je v plánku víckrát.',
			'Místo A2/1 leží ve stole A1, ne ve stole A2.',
			'Místo A1/2 neleží v žádném stole (skupině s id table-A1).',
			'Stůl A3 nemá žádná místa.',
		], $plan->errors);
	}


	public function testRejectsWhatIsNotAnSvg(): void
	{
		foreach (['', 'not xml', '<html xmlns="http://www.w3.org/1999/xhtml"/>', '<svg/>'] as $input) {
			self::assertSame(['Soubor není platný obrázek SVG.'], HallPlanParser::parse($input)->errors, "Input: $input");
		}
		self::assertSame(
			['Plánek neobsahuje žádná místa (tvary s id seat-<stůl>-<číslo>, např. seat-A1-5).'],
			HallPlanParser::parse('<svg xmlns="http://www.w3.org/2000/svg"><rect/></svg>')->errors,
		);
	}


	private static function fixture(): string
	{
		return (string) file_get_contents(__DIR__ . '/../../fixtures/hall-small.svg');
	}
}
