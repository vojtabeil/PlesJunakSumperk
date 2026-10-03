<?php

declare(strict_types=1);

namespace App\Tests\Model\Payment;

use App\Model\Payment\QrPayment;
use App\Tests\DatabaseTestCase;
use InvalidArgumentException;


final class QrPaymentTest extends DatabaseTestCase
{
	public function testConvertsCzechAccountsToIban(): void
	{
		// Reference example of the Czech National Bank.
		self::assertSame('CZ6508000000192000145399', QrPayment::czechAccountToIban('19-2000145399/0800'));
		// Account of the scout group (Fio).
		self::assertSame('CZ4720100000002501895120', QrPayment::czechAccountToIban('2501895120/2010'));
	}


	public function testRejectsInvalidAccount(): void
	{
		$this->expectException(InvalidArgumentException::class);
		QrPayment::czechAccountToIban('12345');
	}


	public function testBuildsSpaydAndPng(): void
	{
		$this->setSettings(['bank_account' => '2501895120/2010']);
		$qr = new QrPayment($this->settings());

		$spayd = $qr->spayd(950, '20260012', 'Šumperský skautský ples *2026*');

		self::assertSame('SPD*1.0*ACC:CZ4720100000002501895120*AM:950.00*CC:CZK*X-VS:20260012*MSG:SUMPERSKY SKAUTSKY PLES 2026', $spayd);
		self::assertStringStartsWith("\x89PNG", $qr->png($spayd));
		self::assertStringStartsWith('data:image/png;base64,', $qr->pngDataUri($spayd));
	}
}
