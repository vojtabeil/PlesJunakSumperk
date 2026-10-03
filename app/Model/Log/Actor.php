<?php

declare(strict_types=1);

namespace App\Model\Log;


/**
 * Who is acting in the current request, so the model can log events without passing it around.
 * Set by the entry points: admin presenters (admin), the public site (customer), cron (cron).
 * Everything else (CLI, tests) is "system".
 */
final class Actor
{
	public const Customer = 'customer';
	public const Admin = 'admin';
	public const Cron = 'cron';
	public const System = 'system';

	private string $type = self::System;
	private ?int $adminId = null;


	public function asAdmin(int $adminId): void
	{
		$this->type = self::Admin;
		$this->adminId = $adminId;
	}


	public function asCustomer(): void
	{
		$this->type = self::Customer;
		$this->adminId = null;
	}


	public function asCron(): void
	{
		$this->type = self::Cron;
		$this->adminId = null;
	}


	public function type(): string
	{
		return $this->type;
	}


	public function adminId(): ?int
	{
		return $this->adminId;
	}
}
