<?php

declare(strict_types=1);

namespace App\Tests\Model\Payment;

use App\Model\Payment\PaymentCandidates;
use App\Model\Payment\VariableSymbol;
use App\Tests\Support\DatabaseTestCase;


final class PaymentCandidatesTest extends DatabaseTestCase
{
	public function testSuggestsByAmountNameAndNumber(): void
	{
		$jana = $this->reservation('Jana Nováková', 'jana@example.com', 700);
		$petr = $this->reservation('Petr Dvořák', 'petr@example.com', 350);
		$this->reservation('Eva Malá', 'eva@example.com', 1050);

		$byAmountAndName = $this->candidates()->suggest(['amount' => '700.00', 'counter_name' => 'NOVAKOVA JANA', 'message' => '', 'variable_symbol' => null]);
		self::assertSame([$jana], array_column($byAmountAndName, 'id'));
		self::assertSame(['částka odpovídá doplatku', 'jméno plátce'], $byAmountAndName[0]['reasons']);

		$byNumber = $this->candidates()->suggest(['amount' => '100.00', 'counter_name' => 'Někdo', 'message' => "ples rez $petr", 'variable_symbol' => null]);
		self::assertSame([$petr], array_column($byNumber, 'id'));

		self::assertSame([], $this->candidates()->suggest(['amount' => '1.00', 'counter_name' => 'Xy', 'message' => '', 'variable_symbol' => null]));
	}


	public function testSearchesByNameEmailNumberOrVariableSymbol(): void
	{
		$jana = $this->reservation('Jana Nováková', 'jana@example.com', 700);
		$this->reservation('Petr Dvořák', 'petr@example.com', 350);

		self::assertSame([$jana], array_column($this->candidates()->search('nováková'), 'id'));
		self::assertSame([$jana], array_column($this->candidates()->search('jana@'), 'id'));
		self::assertSame($jana, $this->candidates()->search((string) $jana)[0]['id']);
		self::assertSame($jana, $this->candidates()->search(sprintf('2026%04d', $jana))[0]['id'], 'Variable symbol');
		self::assertSame(700, $this->candidates()->search('jana')[0]['remaining']);
		self::assertSame([], $this->candidates()->search(' '));
	}


	private function candidates(): PaymentCandidates
	{
		return new PaymentCandidates($this->db, new VariableSymbol($this->settings()));
	}


	private function reservation(string $name, string $email, int $price): int
	{
		$this->db->prepare("INSERT INTO reservations (email, name, status, total_price, confirmed_at) VALUES (?, ?, 'confirmed', ?, NOW())")
			->execute([$email, $name, $price]);
		return (int) $this->db->lastInsertId();
	}
}
