<?php

declare(strict_types=1);

namespace App\Presentation\Admin\Accessory;

use App\Model\Payment\Bank\BankTransactionSource;
use App\Model\Payment\Bank\Mock\MockBankSource;
use Nette\Application\LinkGenerator;


/**
 * Links to the other applications of the site shown in the administration (apps.latte): the public site,
 * the entrance check and, only where they work (debug mode: locally and the portable version on its own
 * computer), the fake bank and the Mailpit web.
 */
final class AdminApps
{
	public function __construct(
		private readonly bool $debugMode,
		private readonly string $mailpitUrl,
		private readonly BankTransactionSource $bankSource,
		private readonly LinkGenerator $linkGenerator,
	) {
	}


	/** @return list<array{key: string, label: string, title: string, url: string}> */
	public function links(): array
	{
		$links = [
			['key' => 'web', 'label' => 'Web', 'title' => 'Úvodní stránka webu', 'url' => $this->linkGenerator->link('Front:Home:default')],
			['key' => 'check', 'label' => 'Kontrola', 'title' => 'Kontrola vstupenek (QR kódy)', 'url' => $this->linkGenerator->link('Admin:Check:default')],
		];
		if ($this->debugMode && $this->bankSource instanceof MockBankSource) {
			$links[] = ['key' => 'bank', 'label' => 'Banka', 'title' => 'Testovací banka (falešné platby)', 'url' => $this->linkGenerator->link('Dev:Bank:default')];
		}
		if ($this->debugMode && preg_match('~^https?://~', $this->mailpitUrl)) {
			$links[] = ['key' => 'mail', 'label' => 'E-maily', 'title' => 'Odeslané e-maily (Mailpit)', 'url' => $this->mailpitUrl];
		}
		return $links;
	}
}
