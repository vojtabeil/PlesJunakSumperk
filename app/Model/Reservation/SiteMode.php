<?php

declare(strict_types=1);

namespace App\Model\Reservation;


/**
 * Stage of the site, switched by hand in the administration (setting site_mode):
 * testing -> vip -> public -> closed -> after.
 */
enum SiteMode: string
{
	/** Only testers with the tester link (and administrators) can reserve; reservations are test ones. */
	case Testing = 'testing';

	/** Only holders of the VIP link (and administrators) can reserve. */
	case Vip = 'vip';

	/** Anybody can reserve. */
	case Public = 'public';

	/** Sale is over before the ball. */
	case Closed = 'closed';

	/** The ball is over. */
	case After = 'after';


	public function label(): string
	{
		return match ($this) {
			self::Testing => 'Testování',
			self::Vip => 'VIP prodej',
			self::Public => 'Veřejný prodej',
			self::Closed => 'Prodej ukončen',
			self::After => 'Po plese',
		};
	}


	/** What a visitor sees, in one sentence for the administration. */
	public function description(): string
	{
		return match ($this) {
			self::Testing => 'Kupovat mohou jen testeři s testerským odkazem; jejich rezervace jsou testovací. Ostatní vidí stránku „Testování“.',
			self::Vip => 'Kupovat mohou jen lidé s VIP odkazem. Ostatní vidí stránku „VIP prodej“.',
			self::Public => 'Lístky si může koupit kdokoli.',
			self::Closed => 'Nikdo už nekupuje, všichni vidí stránku „Prodej ukončen“. Stránka s platebními údaji a načítání plateb fungují dál.',
			self::After => 'Všichni vidí stránku „Po plese“, údaje o plese (datum, místo, kapela) se skryjí.',
		};
	}


	public function isSelling(): bool
	{
		return $this->channel() !== null;
	}


	/** Channel stored with reservations made in this mode; null = no sale. */
	public function channel(): ?string
	{
		return match ($this) {
			self::Testing => 'test',
			self::Vip => 'vip',
			self::Public => 'public',
			self::Closed, self::After => null,
		};
	}


	/** Settings key of the page (HTML) for visitors who cannot buy; null = nobody sees a page. */
	public function pageKey(): ?string
	{
		return $this === self::Public ? null : 'page_' . $this->value;
	}
}
