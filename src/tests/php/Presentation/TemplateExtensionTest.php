<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Presentation\Accessory\TemplateExtension;
use PHPUnit\Framework\TestCase;


final class TemplateExtensionTest extends TestCase
{
	public function testFormatsPrice(): void
	{
		self::assertSame("1\u{A0}250\u{A0}Kč", TemplateExtension::formatPrice(1250));
		self::assertSame("0\u{A0}Kč", TemplateExtension::formatPrice(0));
	}


	public function testFormatsSeatLabel(): void
	{
		self::assertSame('stůl 3, místo 5', TemplateExtension::formatSeatLabel('3/5'));
	}
}
