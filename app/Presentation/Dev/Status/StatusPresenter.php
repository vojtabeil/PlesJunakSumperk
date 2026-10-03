<?php

declare(strict_types=1);

namespace App\Presentation\Dev\Status;

use Nette\Application\UI\Presenter;
use PDO;
use PDOException;
use Tracy\Debugger;


/**
 * Diagnostics of the local environment. Exists only in debug mode (localhost).
 * @property-read StatusTemplate $template
 */
final class StatusPresenter extends Presenter
{
	public function __construct(
		private readonly PDO $db,
	) {
		parent::__construct();
	}


	public function startup(): void
	{
		parent::startup();
		if (Debugger::$productionMode) {
			$this->error();
		}
	}


	public function renderDefault(): void
	{
		$tables = [];
		$dbVersion = null;
		$dbError = null;
		try {
			$dbVersion = (string) $this->db->query('SELECT VERSION()')->fetchColumn();
			foreach ($this->db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
				$tables[$table] = (int) $this->db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
			}
		} catch (PDOException $e) {
			$dbError = $e->getMessage();
		}

		$this->template->extensions = array_map(
			fn(string $ext): array => ['name' => $ext, 'loaded' => extension_loaded($ext)],
			['pdo_mysql', 'mbstring', 'intl', 'openssl', 'curl', 'gd', 'zip'],
		);
		$this->template->dbVersion = $dbVersion;
		$this->template->dbError = $dbError;
		$this->template->tables = $tables;
	}
}
