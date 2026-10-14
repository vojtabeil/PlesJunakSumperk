<?php

declare(strict_types=1);

namespace App\Model\Database;

use PDO;


final class ConnectionFactory
{
	/** @param array{host: string, port: int, name: string, user: string, password: string} $config */
	public static function create(array $config): PDO
	{
		return new PDO(
			"mysql:host={$config['host']};port={$config['port']};dbname={$config['name']};charset=utf8mb4",
			$config['user'],
			$config['password'],
			[
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES => false,
			],
		);
	}
}
