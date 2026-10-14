<?php

declare(strict_types=1);

namespace App\Tests\Model\Payment;

use App\Model\Payment\VariableSymbol;
use PHPUnit\Framework\TestCase;


final class VariableSymbolTest extends TestCase
{
	public function testFormat(): void
	{
		self::assertSame('20260003', VariableSymbol::format('2026', 3));
		self::assertSame('202612345', VariableSymbol::format('2026', 12345));
	}


	public function testParse(): void
	{
		self::assertSame(3, VariableSymbol::parse('2026', '20260003'));
		self::assertSame(3, VariableSymbol::parse('2026', '0020260003'), 'Leading zeros added by banks');
		self::assertSame(12345, VariableSymbol::parse('2026', '202612345'));
		self::assertNull(VariableSymbol::parse('2026', '3'), 'Without the prefix it is another payment');
		self::assertNull(VariableSymbol::parse('2026', '2026'));
		self::assertNull(VariableSymbol::parse('2026', '20260000'));
		self::assertNull(VariableSymbol::parse('2026', '2026003'), 'Id must have 4 digits');
		self::assertNull(VariableSymbol::parse('2026', '20250003'));
		self::assertNull(VariableSymbol::parse('2026', 'abc'));
		self::assertNull(VariableSymbol::parse('2026', null));
	}
}
