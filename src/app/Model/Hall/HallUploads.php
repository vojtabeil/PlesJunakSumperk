<?php

declare(strict_types=1);

namespace App\Model\Hall;

use Nette\Utils\FileSystem;


/**
 * An uploaded hall plan waiting for confirmation: a temporary file with a random name, never a second
 * version in the database. Files older than an hour are deleted.
 */
final class HallUploads
{
	private const MaxAge = 3600;


	public function __construct(
		private readonly string $dir,
	) {
	}


	/** Stores the uploaded SVG; returns the token that identifies it. */
	public function store(string $svg): string
	{
		$this->cleanUp();
		$token = bin2hex(random_bytes(16));
		FileSystem::write($this->path($token), $svg);
		return $token;
	}


	/** The uploaded SVG, or null when the token is wrong or the file expired. */
	public function load(string $token): ?string
	{
		if (!self::isToken($token)) {
			return null;
		}
		$path = $this->path($token);
		return is_file($path) && filemtime($path) > time() - self::MaxAge ? FileSystem::read($path) : null;
	}


	public function delete(string $token): void
	{
		FileSystem::delete($this->path($token));
	}


	private function cleanUp(): void
	{
		foreach (glob($this->dir . '/*.svg') ?: [] as $file) {
			if (filemtime($file) < time() - self::MaxAge) {
				@unlink($file); // may be gone already (another request)
			}
		}
	}


	private static function isToken(string $token): bool
	{
		return (bool) preg_match('~^[0-9a-f]{32}$~', $token);
	}


	private function path(string $token): string
	{
		if (!self::isToken($token)) {
			throw new \InvalidArgumentException('Invalid upload token.');
		}
		return $this->dir . "/$token.svg";
	}
}
