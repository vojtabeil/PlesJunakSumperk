<?php

declare(strict_types=1);

namespace App\Presentation\Front;

use App\Model\Reservation\SiteAccess;
use App\Model\Reservation\SiteMode;
use Nette\Http\IRequest;
use Nette\Http\Response;
use Nette\Security\User;


/**
 * Decides whether this visitor may buy tickets in the current stage of the site:
 * testing = tester cookie, vip = VIP cookie, public = anybody, closed/after = nobody.
 * Logged-in administrators may buy in every selling stage.
 */
final class VisitorGate
{
	public const TesterCookie = 'ples_tester';
	public const VipCookie = 'ples_vip';


	public function __construct(
		private readonly SiteAccess $access,
		private readonly IRequest $request,
		private readonly Response $response,
		private readonly User $user,
	) {
	}


	public function mode(): SiteMode
	{
		return $this->access->mode();
	}


	public function canBuy(): bool
	{
		$mode = $this->access->mode();
		return match ($mode) {
			SiteMode::Public => true,
			SiteMode::Testing => $this->user->isLoggedIn() || $this->access->isValid(SiteAccess::Tester, $this->request->getCookie(self::TesterCookie)),
			SiteMode::Vip => $this->user->isLoggedIn() || $this->access->isValid(SiteAccess::Vip, $this->request->getCookie(self::VipCookie)),
			SiteMode::Closed, SiteMode::After => false,
		};
	}


	/** Opens the testing stage for this browser for 30 days when the token of the tester link is right. */
	public function admitTester(string $token): bool
	{
		return $this->access->isValid(SiteAccess::Tester, $token) && $this->setCookie(self::TesterCookie, $token, '30 days');
	}


	/** Opens the VIP sale for this browser; the link may be sent out before the VIP stage starts. */
	public function admitVip(string $token): bool
	{
		return $this->access->isValid(SiteAccess::Vip, $token) && $this->setCookie(self::VipCookie, $token, '120 days');
	}


	private function setCookie(string $name, string $value, string $expire): bool
	{
		$this->response->setCookie(
			$name,
			$value,
			$expire,
			secure: $this->request->isSecured(),
			httpOnly: true,
			sameSite: Response::SameSiteLax,
		);
		return true;
	}
}
