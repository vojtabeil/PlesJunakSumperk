<?php

declare(strict_types=1);

namespace App\Model\Crew;

use App\Model\Log\EventLog;
use App\Model\Ticket\Tickets;
use Nette\Utils\Validators;
use PDO;
use Throwable;


/**
 * Z-lístky: the crew (people who prepare and run the ball) and their free passes. Each member has one
 * ticket (Tickets::issueCrew) checked at the entrance like any other; no seat, no price, no capacity.
 */
final class CrewMembers
{
	public const NameMaxLength = 150;
	public const RoleMaxLength = 100;
	public const NoteMaxLength = 500;

	private const Columns = 'c.id, c.name, c.role, c.email, c.note, c.picked_up_at, c.emailed_at, c.created_at,
		t.code, t.print_token, t.used_at, a.name AS used_by_name';

	private const Joins = 'JOIN tickets t ON t.crew_member_id = c.id LEFT JOIN admin_users a ON a.id = t.used_by';


	public function __construct(
		private readonly PDO $db,
		private readonly Tickets $tickets,
		private readonly EventLog $eventLog,
	) {
	}


	/**
	 * Members with their ticket (code, print_token, used_at), by role and name.
	 * @param string $role exact role, '' = all
	 * @param string $search part of the name, role or code
	 * @return list<array<string, mixed>>
	 */
	public function all(string $role = '', string $search = ''): array
	{
		$where = [];
		$params = [];
		if ($role !== '') {
			$where[] = 'c.role = ?';
			$params[] = $role;
		}
		$search = trim($search);
		if ($search !== '') {
			$where[] = '(c.name LIKE ? OR c.role LIKE ? OR t.code = ?)';
			$like = '%' . addcslashes($search, '%_\\') . '%';
			array_push($params, $like, $like, strtoupper($search));
		}
		$stmt = $this->db->prepare(
			'SELECT ' . self::Columns . ' FROM crew_members c ' . self::Joins
			. ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY c.role, c.name, c.id',
		);
		$stmt->execute($params);
		return $stmt->fetchAll();
	}


	/**
	 * Members by id in the given order (print and e-mail of a selection); unknown ids are skipped.
	 * @param list<int> $ids
	 * @return list<array<string, mixed>>
	 */
	public function byIds(array $ids): array
	{
		$ids = array_values(array_unique(array_map('intval', $ids)));
		if (!$ids) {
			return [];
		}
		$stmt = $this->db->prepare(
			'SELECT ' . self::Columns . ' FROM crew_members c ' . self::Joins
			. ' WHERE c.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY c.role, c.name, c.id',
		);
		$stmt->execute($ids);
		return $stmt->fetchAll();
	}


	/** @return array<string, mixed>|null */
	public function find(int $id): ?array
	{
		return $this->byIds([$id])[0] ?? null;
	}


	/** @return array<string, int> role => number of members, by role */
	public function roles(): array
	{
		$rows = $this->db->query('SELECT role, COUNT(*) AS members FROM crew_members GROUP BY role ORDER BY role')->fetchAll();
		return array_combine(
			array_map(static fn(array $r): string => (string) $r['role'], $rows),
			array_map(static fn(array $r): int => (int) $r['members'], $rows),
		);
	}


	/** @return array{total: int, picked_up: int, emailed: int, used: int} */
	public function stats(): array
	{
		$row = $this->db->query(
			'SELECT COUNT(*) AS total, COALESCE(SUM(c.picked_up_at IS NOT NULL), 0) AS picked_up,
				COALESCE(SUM(c.emailed_at IS NOT NULL), 0) AS emailed, COALESCE(SUM(t.used_at IS NOT NULL), 0) AS used
			FROM crew_members c ' . self::Joins,
		)->fetch();
		return array_map('intval', $row);
	}


	/** A new member with a Z-lístek. */
	public function create(string $name, string $role, string $email = '', string $note = ''): int
	{
		$member = self::validate($name, $role, $email, $note);
		$id = $this->transaction(fn(): int => $this->insert($member));
		$this->eventLog->record('crew.created', details: ['name' => $member['name'], 'role' => $member['role']]);
		return $id;
	}


	/**
	 * Many members at once, one per line: "name; role" or "name; role; e-mail" (also separated by tabs,
	 * as copied from a spreadsheet). Nothing is created when any line is wrong.
	 * @return int number of created members
	 * @throws CrewError listing the wrong lines
	 */
	public function import(string $text): int
	{
		$members = $errors = [];
		foreach (preg_split('~\R~u', $text) ?: [] as $number => $line) {
			if (trim($line) === '') {
				continue;
			}
			$parts = array_map('trim', preg_split('~\t|;~u', $line) ?: []);
			try {
				if (count($parts) < 2 || count($parts) > 3) {
					throw new CrewError('Zapište „jméno; funkce“ nebo „jméno; funkce; e-mail“.');
				}
				$members[] = self::validate($parts[0], $parts[1], $parts[2] ?? '', '');
			} catch (CrewError $e) {
				$errors[] = sprintf('Řádek %d: %s', $number + 1, $e->getMessage());
			}
		}
		if ($errors) {
			throw new CrewError(implode("\n", $errors));
		}
		if (!$members) {
			throw new CrewError('Vložte aspoň jeden řádek „jméno; funkce“.');
		}
		$this->transaction(function () use ($members): void {
			foreach ($members as $member) {
				$this->insert($member);
			}
		});
		$this->eventLog->record('crew.imported', details: ['count' => count($members)]);
		return count($members);
	}


	public function update(int $id, string $name, string $role, string $email = '', string $note = ''): void
	{
		$member = self::validate($name, $role, $email, $note);
		$this->require($id);
		$this->db->prepare('UPDATE crew_members SET name = ?, role = ?, email = ?, note = ? WHERE id = ?')
			->execute([$member['name'], $member['role'], $member['email'], $member['note'], $id]);
		$this->eventLog->record('crew.updated', details: ['name' => $member['name'], 'role' => $member['role']]);
	}


	/** Deletes a member with the Z-lístek; one that was used at the entrance stays for the record. */
	public function delete(int $id): void
	{
		$member = $this->require($id);
		if ($member['used_at'] !== null) {
			throw new CrewError('Z-lístek už prošel vstupem, nejde ho smazat. Nejdřív vraťte vstup v kontrole vstupenek.');
		}
		$this->db->prepare('DELETE FROM crew_members WHERE id = ?')->execute([$id]);
		$this->eventLog->record('crew.deleted', details: ['name' => $member['name'], 'role' => $member['role'], 'codes' => $member['code']]);
	}


	/**
	 * Marks the printed passes as handed over (or takes it back).
	 * @param list<int> $ids
	 * @return int number of changed members
	 */
	public function setPickedUp(array $ids, bool $pickedUp): int
	{
		$changed = 0;
		$stmt = $this->db->prepare($pickedUp
			? 'UPDATE crew_members SET picked_up_at = NOW() WHERE id = ? AND picked_up_at IS NULL'
			: 'UPDATE crew_members SET picked_up_at = NULL WHERE id = ? AND picked_up_at IS NOT NULL');
		foreach ($this->byIds($ids) as $member) {
			$stmt->execute([$member['id']]);
			if ($stmt->rowCount() > 0) {
				$changed++;
				$this->eventLog->record($pickedUp ? 'crew.picked_up' : 'crew.pickup_undone', details: ['name' => $member['name'], 'role' => $member['role']]);
			}
		}
		return $changed;
	}


	/** Remembers that the Z-lístek was e-mailed (CrewMailer). */
	public function markEmailed(int $id): void
	{
		$this->db->prepare('UPDATE crew_members SET emailed_at = NOW() WHERE id = ?')->execute([$id]);
	}


	/** @return array<string, mixed> */
	private function require(int $id): array
	{
		return $this->find($id) ?? throw new CrewError('Člen týmu neexistuje.');
	}


	/** @param array{name: string, role: string, email: ?string, note: ?string} $member */
	private function insert(array $member): int
	{
		$this->db->prepare('INSERT INTO crew_members (name, role, email, note) VALUES (?, ?, ?, ?)')
			->execute([$member['name'], $member['role'], $member['email'], $member['note']]);
		$id = (int) $this->db->lastInsertId();
		$this->tickets->issueCrew($id);
		return $id;
	}


	/**
	 * @template T
	 * @param callable(): T $work
	 * @return T
	 */
	private function transaction(callable $work): mixed
	{
		$this->db->beginTransaction();
		try {
			$result = $work();
			$this->db->commit();
			return $result;
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}


	/** @return array{name: string, role: string, email: ?string, note: ?string} */
	private static function validate(string $name, string $role, string $email, string $note): array
	{
		$name = self::clean($name);
		$role = self::clean($role);
		$email = trim($email);
		$note = trim($note);
		match (true) {
			$name === '' => throw new CrewError('Vyplňte jméno.'),
			mb_strlen($name) > self::NameMaxLength => throw new CrewError(sprintf('Jméno může mít nejvýše %d znaků.', self::NameMaxLength)),
			$role === '' => throw new CrewError("Vyplňte funkci (např. bar, kuchyně) u jména $name."),
			mb_strlen($role) > self::RoleMaxLength => throw new CrewError(sprintf('Funkce může mít nejvýše %d znaků.', self::RoleMaxLength)),
			$email !== '' && !Validators::isEmail($email) => throw new CrewError("Neplatný e-mail: $email"),
			mb_strlen($note) > self::NoteMaxLength => throw new CrewError(sprintf('Poznámka může mít nejvýše %d znaků.', self::NoteMaxLength)),
			default => null,
		};
		return ['name' => $name, 'role' => $role, 'email' => $email !== '' ? $email : null, 'note' => $note !== '' ? $note : null];
	}


	/** Trimmed, inner whitespace collapsed (names and roles typed or pasted by hand). */
	private static function clean(string $value): string
	{
		return trim((string) preg_replace('~\s+~u', ' ', $value));
	}
}
