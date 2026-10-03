<?php

declare(strict_types=1);

namespace App\Tests\Presentation;

use App\Core\SetupGuard;
use App\Model\Admin\AdminUsers;
use App\Presentation\Admin\BasePresenter as AdminBasePresenter;
use App\Tests\DatabaseTestCase;
use Nette\Application\Application;
use Nette\Application\BadRequestException;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\Responses\RedirectResponse;
use Nette\Application\UI\Presenter;
use Nette\Http\IResponse;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tracy\Debugger;


/**
 * Deny by default: every presenter requires a logged-in administrator unless it is listed here.
 * A new public presenter must be added on purpose (and reviewed).
 */
final class PresenterAccessTest extends DatabaseTestCase
{
	/** Public by design, no login. */
	private const PublicPresenters = [
		'App\Presentation\Front\Home\HomePresenter',    // reservation page for visitors
		'App\Presentation\Front\Done\DonePresenter',    // confirmation, bound to the visitor's session
		'App\Presentation\Front\Api\ApiPresenter',      // JSON API of the seat picker
		'App\Presentation\Front\Cron\CronPresenter',    // protected by the cron key instead of a login
		'App\Presentation\Front\Access\AccessPresenter', // tester and VIP links, protected by their secret tokens
		'App\Presentation\Error\Error4xx\Error4xxPresenter',
		'App\Presentation\Error\Error5xx\Error5xxPresenter',
		'App\Presentation\Admin\Sign\SignPresenter',
		'App\Presentation\Admin\Setup\SetupPresenter',
	];

	/** Dev tools: public, but they must not exist in production mode. */
	private const DevNamespace = 'App\Presentation\Dev\\';


	public function testEveryPresenterRequiresLoginUnlessAllowed(): void
	{
		$checked = 0;
		foreach (self::presenterClasses() as $class) {
			$checked++;
			if (in_array($class, self::PublicPresenters, true) || str_starts_with($class, self::DevNamespace)) {
				continue;
			}
			self::assertTrue(
				is_subclass_of($class, AdminBasePresenter::class),
				"$class must extend Admin\\BasePresenter (login required) or be added to PublicPresenters on purpose.",
			);
			self::assertFalse(self::isPublic($class), "$class::isPublic() must not return true.");
		}
		self::assertGreaterThan(10, $checked, 'Presenters were found');
	}


	public function testAllowedAdminPresentersAreTheOnlyPublicOnes(): void
	{
		foreach (self::presenterClasses() as $class) {
			if (is_subclass_of($class, AdminBasePresenter::class) && self::isPublic($class)) {
				self::assertContains($class, self::PublicPresenters, "$class is public but not on the allowlist.");
			}
		}
	}


	public function testDevPresentersDoNotExistInProductionMode(): void
	{
		$factory = $this->service(IPresenterFactory::class);
		$previous = Debugger::$productionMode;
		Debugger::$productionMode = true;
		try {
			foreach (self::presenterClasses() as $class) {
				if (!str_starts_with($class, self::DevNamespace)) {
					continue;
				}
				$name = 'Dev:' . substr((string) strrchr($class, '\\'), 1, -strlen('Presenter'));
				$presenter = $factory->createPresenter($name);
				try {
					$presenter->run(new Request($name, 'GET', ['action' => 'default']));
					self::fail("$name must answer 404 in production mode");
				} catch (BadRequestException $e) {
					self::assertSame(404, $e->getHttpCode());
				}
			}
		} finally {
			Debugger::$productionMode = $previous;
		}
	}


	public function testWithoutAdministratorEverythingButTheWizardIsDisabled(): void
	{
		self::assertFalse($this->service(AdminUsers::class)->exists(), 'Fixture has no administrators');

		$page = $this->runGuarded('Front:Home');
		self::assertInstanceOf(RedirectResponse::class, $page);
		self::assertStringContainsString('/admin/setup', $page->getUrl());

		$api = $this->runGuarded('Front:Api', ['op' => 'state']);
		self::assertInstanceOf(JsonResponse::class, $api);
		self::assertSame(['ok' => false, 'error' => 'Web zatím není nastavený.'], $api->getPayload());
		self::assertSame(IResponse::S503_ServiceUnavailable, $this->service(IResponse::class)->getCode());
	}


	/** @param array<string, mixed> $params */
	private function runGuarded(string $name, array $params = []): \Nette\Application\Response
	{
		$presenter = $this->service(IPresenterFactory::class)->createPresenter($name);
		self::assertInstanceOf(Presenter::class, $presenter);
		$presenter->autoCanonicalize = false;
		$this->service(SetupGuard::class)->onPresenter($this->service(Application::class), $presenter);
		return $presenter->run(new Request($name, 'GET', $params + ['action' => 'default']));
	}


	/** @return list<class-string> */
	private static function presenterClasses(): array
	{
		$root = dirname(__DIR__, 3) . '/app/Presentation';
		$classes = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
			if (!str_ends_with($file->getFilename(), 'Presenter.php')) {
				continue;
			}
			$relative = substr($file->getPathname(), strlen($root) + 1, -4);
			$class = 'App\Presentation\\' . str_replace(['/', '\\'], '\\', $relative);
			if (class_exists($class) && !(new ReflectionClass($class))->isAbstract()) {
				$classes[] = $class;
			}
		}
		sort($classes);
		return $classes;
	}


	/** @param class-string $class */
	private static function isPublic(string $class): bool
	{
		$reflection = new ReflectionClass($class);
		if (!$reflection->hasMethod('isPublic')) {
			return false;
		}
		return (bool) $reflection->getMethod('isPublic')->invoke($reflection->newInstanceWithoutConstructor());
	}
}
