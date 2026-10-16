<?php

declare(strict_types=1);

namespace App\Model\Log;


/** Catalogue of logged actions: Czech label and category for filtering. */
final class EventTypes
{
	public const Categories = [
		'reservation' => 'Rezervace',
		'payment' => 'Platby',
		'entrance' => 'Vstup na ples',
		'crew' => 'Z-lístky',
		'email' => 'E-maily',
		'admin' => 'Administrace',
		'login' => 'Přihlášení',
	];

	/** action => [category, label] */
	public const Types = [
		'reservation.confirmed' => ['reservation', 'Rezervace potvrzena'],
		'reservation.bot_blocked' => ['reservation', 'Objednávka zastavena jako robot'],
		'reservation.paid_manually' => ['reservation', 'Označeno jako zaplacené (hotově)'],
		'reservation.cancelled' => ['reservation', 'Rezervace zrušena'],
		'reservation.note' => ['reservation', 'Změna poznámky'],
		'reservation.test_deleted' => ['reservation', 'Smazána testovací rezervace'],

		'ticket.used' => ['entrance', 'Vstupenka použita (vstup)'],
		'ticket.unused' => ['entrance', 'Vrácen vstup vstupenky'],
		'ticket.snapshot' => ['entrance', 'Stažena data pro offline kontrolu'],
		'ticket.revealed' => ['entrance', 'Zobrazeny nezaplacené vstupenky (administrátor)'],

		'crew.created' => ['crew', 'Nový Z-lístek'],
		'crew.imported' => ['crew', 'Hromadné vložení Z-lístků'],
		'crew.updated' => ['crew', 'Úprava Z-lístku'],
		'crew.deleted' => ['crew', 'Smazání Z-lístku'],
		'crew.picked_up' => ['crew', 'Z-lístek vyzvednut'],
		'crew.pickup_undone' => ['crew', 'Vráceno vyzvednutí Z-lístku'],

		'email.confirmation_sent' => ['email', 'Odeslán potvrzovací e-mail'],
		'email.confirmation_failed' => ['email', 'Potvrzovací e-mail se nepodařilo odeslat'],
		'email.payment_sent' => ['email', 'Odeslán e-mail o platbě'],
		'email.payment_failed' => ['email', 'E-mail o platbě se nepodařilo odeslat'],
		'email.reminder_sent' => ['email', 'Odeslána připomínka platby'],
		'email.reminder_failed' => ['email', 'Připomínku platby se nepodařilo odeslat'],
		'email.tickets_sent' => ['email', 'Odeslány vstupenky'],
		'email.tickets_failed' => ['email', 'Vstupenky se nepodařilo odeslat'],
		'email.crew_sent' => ['email', 'Odeslán Z-lístek'],
		'email.crew_failed' => ['email', 'Z-lístek se nepodařilo odeslat'],

		'payment.matched' => ['payment', 'Platba přijata'],
		'payment.partial' => ['payment', 'Přijata částečná platba'],
		'payment.overpaid' => ['payment', 'Přeplatek'],
		'payment.unmatched' => ['payment', 'Platbu nelze přiřadit'],
		'payment.assigned' => ['payment', 'Platba přiřazena ručně'],
		'payment.ignored' => ['payment', 'Platba označena jako nesouvisející'],
		'payment.unignored' => ['payment', 'Platba vrácena k vyřízení'],
		'payment.unassigned' => ['payment', 'Zrušeno přiřazení platby k rezervaci'],
		'payment.settled' => ['payment', 'Nedoplatek / přeplatek vyřízen'],
		'payment.after_cancel' => ['payment', 'Platba na zrušenou rezervaci (k vrácení)'],
		'refund.recorded' => ['payment', 'Peníze vráceny'],
		'refund.deleted' => ['payment', 'Smazán zápis o vrácení peněz'],
		'payments.imported' => ['payment', 'Načtení plateb z banky'],
		'payments.rewound' => ['payment', 'Nové stažení pohybů od data'],

		'site.mode_changed' => ['admin', 'Změna stavu webu'],
		'hall.replaced' => ['admin', 'Nový plánek sálu'],
		'hall.availability' => ['admin', 'Stoly v prodeji / mimo prodej'],
		'settings.changed' => ['admin', 'Změna nastavení'],
		'tester.link_regenerated' => ['admin', 'Nový testerský odkaz'],
		'vip.link_regenerated' => ['admin', 'Nový VIP odkaz'],
		'admin.setup' => ['admin', 'Nastavení webu (první účet)'],
		'admin.created' => ['admin', 'Nový administrátor'],
		'admin.updated' => ['admin', 'Úprava administrátora'],
		'admin.password' => ['admin', 'Změna hesla administrátora'],
		'admin.unlocked' => ['admin', 'Odblokování administrátora'],
		'admin.deleted' => ['admin', 'Smazání administrátora'],

		'admin.login' => ['login', 'Přihlášení'],
		'admin.login_failed' => ['login', 'Neúspěšné přihlášení'],
		'admin.login_unknown' => ['login', 'Přihlášení neexistujícím účtem (nejvýš 1 záznam za minutu)'],
		'admin.locked' => ['login', 'Účet zablokován po špatných heslech'],
	];

	public const Actors = [
		Actor::Customer => 'zákazník',
		Actor::Admin => 'administrátor',
		Actor::Cron => 'automatické načítání',
		Actor::System => 'systém',
	];


	public static function label(string $action): string
	{
		return self::Types[$action][1] ?? $action;
	}


	/** @return list<string> */
	public static function actionsOf(string $category): array
	{
		return array_keys(array_filter(self::Types, static fn(array $t): bool => $t[0] === $category));
	}
}
