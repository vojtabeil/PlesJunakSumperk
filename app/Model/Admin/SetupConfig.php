<?php

declare(strict_types=1);

namespace App\Model\Admin;


/** Configuration of the first-run wizard (parameter `setup` in config). */
final class SetupConfig
{
	/** @param array{password?: string} $config */
	public function __construct(
		private readonly array $config,
	) {
	}


	public function isValidPassword(string $password): bool
	{
		$expected = (string) ($this->config['password'] ?? '');
		return $expected !== '' && hash_equals($expected, $password);
	}
}
