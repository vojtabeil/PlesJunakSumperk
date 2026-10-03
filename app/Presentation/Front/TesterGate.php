<?php

declare(strict_types=1);

namespace App\Presentation\Front;

use App\Model\Reservation\TesterAccess;
use Nette\Http\IRequest;
use Nette\Http\Response;
use Nette\Security\User;


/**
 * Decides whether this visitor may see the public site: always when it is public, otherwise only
 * with the tester cookie (set by the tester link) or as a logged-in administrator.
 */
final class TesterGate
{
	public const Cookie = 'ples_tester';


	public function __construct(
		private readonly TesterAccess $access,
		private readonly IRequest $request,
		private readonly Response $response,
		private readonly User $user,
	) {
	}


	public function allows(): bool
	{
		return $this->access->isPublic()
			|| $this->user->isLoggedIn()
			|| $this->access->isValid($this->request->getCookie(self::Cookie));
	}


	/** Lets this browser in for 30 days when the token of the tester link is right. */
	public function admit(string $token): bool
	{
		if (!$this->access->isValid($token)) {
			return false;
		}
		$this->response->setCookie(
			self::Cookie,
			$token,
			'30 days',
			secure: $this->request->isSecured(),
			httpOnly: true,
			sameSite: Response::SameSiteLax,
		);
		return true;
	}
}
