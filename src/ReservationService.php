<?php
// Ticket reservation rules. All limits are enforced here, never only in the browser.
// See docs/legacy-backend.md for how this maps to the original site.

declare(strict_types=1);

/** An error caused by the user's action; the message is shown to the user (Czech). */
final class ReservationError extends RuntimeException
{
}

final class ReservationService
{
    private const PAID_STATUSES = "'confirmed', 'paid'";

    private ?array $settings = null;

    public function __construct(
        private readonly PDO $db,
        private readonly string $owner,
    ) {
    }

    // --- Settings ---------------------------------------------------------------

    public function settings(): array
    {
        if ($this->settings === null) {
            $rows = $this->db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            $this->settings = $rows;
        }
        return $this->settings;
    }

    public function setting(string $name, string $default = ''): string
    {
        return $this->settings()[$name] ?? $default;
    }

    public function intSetting(string $name, int $default = 0): int
    {
        return (int) $this->setting($name, (string) $default);
    }

    public function isSaleOpen(): bool
    {
        return $this->setting('sale_open') === '1';
    }

    // --- Read models --------------------------------------------------------------

    /** Tables and seats for rendering the hall map. */
    public function hallLayout(): array
    {
        return [
            'width' => $this->intSetting('map_width', 1000),
            'height' => $this->intSetting('map_height', 640),
            'tables' => $this->db->query('SELECT id, label, x, y, width, height FROM hall_tables ORDER BY id')->fetchAll(),
            'seats' => $this->db->query('SELECT id, label, x, y FROM seats ORDER BY id')->fetchAll(),
        ];
    }

    /** Everything the reservation page needs; never exposes other people's data. */
    public function state(): array
    {
        $draft = $this->currentDraft();
        $draftId = $draft['id'] ?? 0;

        $taken = [];
        $mine = [];
        $stmt = $this->db->prepare("SELECT id, reservation_id FROM seats WHERE state <> 'free'");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            if ($draftId && (int) $row['reservation_id'] === $draftId) {
                $mine[] = (int) $row['id'];
            } else {
                $taken[] = (int) $row['id'];
            }
        }

        $reservation = null;
        if ($draft) {
            $reservation = [
                'email' => $draft['email'],
                'standing' => (int) $draft['standing_tickets'],
                'seats' => $this->seatLabels($mine),
                'expires_in' => $this->holdExpiresIn($draftId),
            ];
        }

        return [
            'sale_open' => $this->isSaleOpen(),
            'reservation' => $reservation,
            'taken' => $taken,
            'mine' => $mine,
            'limits' => [
                'max_tickets' => $this->intSetting('max_ticket', 10),
                'standing_left' => $this->standingLeft(),
                'hold_seconds' => $this->holdSeconds(),
            ],
            'prices' => [
                'seat' => $this->intSetting('price_seat'),
                'standing' => $this->intSetting('price_standing'),
            ],
        ];
    }

    /** A finished reservation with its seats, for the confirmation page. */
    public function findFinished(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM reservations WHERE id = ? AND status IN (' . self::PAID_STATUSES . ')');
        $stmt->execute([$id]);
        $reservation = $stmt->fetch();
        if (!$reservation) {
            return null;
        }
        $seats = $this->db->prepare('SELECT label FROM seats WHERE reservation_id = ? ORDER BY id');
        $seats->execute([$id]);
        $reservation['seat_labels'] = $seats->fetchAll(PDO::FETCH_COLUMN);
        return $reservation;
    }

    // --- Commands -------------------------------------------------------------------

    /** Frees seats whose temporary hold has run out. Called at the start of every request. */
    public function releaseExpiredHolds(): void
    {
        $stmt = $this->db->prepare(
            "UPDATE seats SET state = 'free', reservation_id = NULL, booked_at = NULL
             WHERE state = 'book' AND booked_at < NOW() - INTERVAL ? SECOND"
        );
        $stmt->execute([$this->holdSeconds()]);
    }

    /** Creates or resumes the draft reservation for an e-mail (one reservation per e-mail). */
    public function start(string $email): void
    {
        $this->assertSaleOpen();
        $email = mb_strtolower(trim($email));
        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ReservationError('Zadejte platný e-mail.');
        }

        $this->transaction(function () use ($email): void {
            $current = $this->currentDraft(true);
            if ($current && $current['email'] !== $email) {
                $this->abandonDraft((int) $current['id']);
            }

            $stmt = $this->db->prepare('SELECT * FROM reservations WHERE email = ? FOR UPDATE');
            $stmt->execute([$email]);
            $existing = $stmt->fetch();

            if (!$existing) {
                $this->db->prepare('INSERT INTO reservations (email, session_id) VALUES (?, ?)')
                    ->execute([$email, $this->owner]);
                return;
            }
            if (in_array($existing['status'], ['confirmed', 'paid'], true)) {
                throw new ReservationError('Na tento e-mail už rezervace existuje. Pro změnu kontaktujte organizátora.');
            }
            if ($existing['session_id'] !== $this->owner) {
                // Draft left in another browser (or cancelled): take it over with a clean slate.
                $this->releaseHolds((int) $existing['id']);
                $this->db->prepare(
                    "UPDATE reservations SET status = 'draft', session_id = ?, standing_tickets = 0 WHERE id = ?"
                )->execute([$this->owner, $existing['id']]);
            }
        });
    }

    /** Temporarily holds a seat for the current draft. */
    public function hold(int $seatId): void
    {
        $this->assertSaleOpen();
        $this->transaction(function () use ($seatId): void {
            $draft = $this->requireDraft();
            $draftId = (int) $draft['id'];
            $held = $this->heldSeatIds($draftId);
            if (in_array($seatId, $held, true)) {
                return;
            }

            $max = $this->intSetting('max_ticket', 10);
            if (count($held) + (int) $draft['standing_tickets'] + 1 > $max) {
                throw new ReservationError("Na jednu rezervaci lze koupit nejvýše $max lístků.");
            }

            $stmt = $this->db->prepare(
                "UPDATE seats SET state = 'book', reservation_id = ?, booked_at = NOW()
                 WHERE id = ? AND state = 'free'"
            );
            $stmt->execute([$draftId, $seatId]);
            if ($stmt->rowCount() === 0) {
                throw new ReservationError('Toto místo už je obsazené.');
            }
            $this->refreshHolds($draftId);
        });
    }

    public function release(int $seatId): void
    {
        $this->transaction(function () use ($seatId): void {
            $draftId = (int) $this->requireDraft()['id'];
            $this->db->prepare(
                "UPDATE seats SET state = 'free', reservation_id = NULL, booked_at = NULL
                 WHERE id = ? AND reservation_id = ? AND state = 'book'"
            )->execute([$seatId, $draftId]);
            $this->refreshHolds($draftId);
        });
    }

    /** Sets the number of tickets without a seat. */
    public function setStanding(int $count): void
    {
        $this->assertSaleOpen();
        $this->transaction(function () use ($count): void {
            $draft = $this->requireDraft();
            $draftId = (int) $draft['id'];
            $max = $this->intSetting('max_ticket', 10);

            if ($count < 0) {
                throw new ReservationError('Neplatný počet lístků.');
            }
            if ($count > (int) $draft['standing_tickets'] && $count + count($this->heldSeatIds($draftId)) > $max) {
                throw new ReservationError("Na jednu rezervaci lze koupit nejvýše $max lístků.");
            }
            if ($count > (int) $draft['standing_tickets'] && $count > $this->standingLeft()) {
                throw new ReservationError('Lístky bez místenky jsou vyprodané.');
            }

            $this->db->prepare('UPDATE reservations SET standing_tickets = ? WHERE id = ?')
                ->execute([$count, $draftId]);
            $this->refreshHolds($draftId);
        });
    }

    /** Turns the draft into a binding reservation. Returns the reservation id. */
    public function confirm(string $name, string $phone, bool $consent): int
    {
        $this->assertSaleOpen();
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        $phone = trim($phone);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 255) {
            throw new ReservationError('Vyplňte jméno a příjmení.');
        }
        if ($phone !== '' && !preg_match('/^\+?[0-9 ]{9,20}$/', $phone)) {
            throw new ReservationError('Telefon zadejte jen jako čísla, např. +420 777 123 456.');
        }
        if (!$consent) {
            throw new ReservationError('Pro rezervaci je potřeba souhlas se zpracováním osobních údajů.');
        }

        return $this->transaction(function () use ($name, $phone): int {
            // Serializes confirmations so the standing capacity cannot be oversold.
            $this->db->query("SELECT value FROM settings WHERE name = 'standing_capacity' FOR UPDATE")->fetch();

            $draft = $this->requireDraft();
            $draftId = (int) $draft['id'];
            $seats = count($this->heldSeatIds($draftId));
            $standing = (int) $draft['standing_tickets'];

            if ($seats + $standing === 0) {
                throw new ReservationError('Vyberte alespoň jeden lístek. Pokud jste místa vybrali dříve, mohla vypršet.');
            }
            if ($standing > $this->standingLeft()) {
                throw new ReservationError('Lístky bez místenky jsou vyprodané.');
            }

            $total = $seats * $this->intSetting('price_seat') + $standing * $this->intSetting('price_standing');
            $this->db->prepare(
                "UPDATE seats SET state = 'reserved', booked_at = NULL WHERE reservation_id = ? AND state = 'book'"
            )->execute([$draftId]);
            $this->db->prepare(
                "UPDATE reservations
                 SET status = 'confirmed', name = ?, phone = ?, total_price = ?, confirmed_at = NOW(), session_id = NULL
                 WHERE id = ?"
            )->execute([$name, $phone === '' ? null : $phone, $total, $draftId]);

            return $draftId;
        });
    }

    /** Gives up the current draft and frees its seats. */
    public function cancel(): void
    {
        $this->transaction(function (): void {
            $draft = $this->currentDraft(true);
            if ($draft) {
                $this->abandonDraft((int) $draft['id']);
            }
        });
    }

    // --- Internals ----------------------------------------------------------------------

    private function holdSeconds(): int
    {
        return $this->intSetting('hold_seconds', 120);
    }

    private function assertSaleOpen(): void
    {
        if (!$this->isSaleOpen()) {
            throw new ReservationError('Prodej lístků je uzavřený.');
        }
    }

    private function currentDraft(bool $lock = false): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM reservations WHERE session_id = ? AND status = 'draft' LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$this->owner]);
        return $stmt->fetch() ?: null;
    }

    /** Must be called inside a transaction; locks the draft row to serialize changes. */
    private function requireDraft(): array
    {
        $draft = $this->currentDraft(true);
        if (!$draft) {
            throw new ReservationError('Nejdřív zadejte svůj e-mail.');
        }
        return $draft;
    }

    private function abandonDraft(int $draftId): void
    {
        $this->releaseHolds($draftId);
        $this->db->prepare('UPDATE reservations SET session_id = NULL, standing_tickets = 0 WHERE id = ?')
            ->execute([$draftId]);
    }

    private function releaseHolds(int $reservationId): void
    {
        $this->db->prepare(
            "UPDATE seats SET state = 'free', reservation_id = NULL, booked_at = NULL
             WHERE reservation_id = ? AND state = 'book'"
        )->execute([$reservationId]);
    }

    /** Any change restarts the hold timer for all seats of the reservation. */
    private function refreshHolds(int $reservationId): void
    {
        $this->db->prepare("UPDATE seats SET booked_at = NOW() WHERE reservation_id = ? AND state = 'book'")
            ->execute([$reservationId]);
    }

    /** @return int[] */
    private function heldSeatIds(int $reservationId): array
    {
        $stmt = $this->db->prepare("SELECT id FROM seats WHERE reservation_id = ? AND state = 'book' FOR UPDATE");
        $stmt->execute([$reservationId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function holdExpiresIn(int $reservationId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(booked_at) + INTERVAL ? SECOND)
             FROM seats WHERE reservation_id = ? AND state = 'book'"
        );
        $stmt->execute([$this->holdSeconds(), $reservationId]);
        $value = $stmt->fetchColumn();
        return $value === null ? null : max(0, (int) $value);
    }

    private function standingLeft(): int
    {
        $sold = (int) $this->db->query(
            'SELECT COALESCE(SUM(standing_tickets), 0) FROM reservations WHERE status IN (' . self::PAID_STATUSES . ')'
        )->fetchColumn();
        return max(0, $this->intSetting('standing_capacity') - $sold);
    }

    /** @param int[] $seatIds */
    private function seatLabels(array $seatIds): array
    {
        if (!$seatIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($seatIds), '?'));
        $stmt = $this->db->prepare("SELECT id, label FROM seats WHERE id IN ($placeholders) ORDER BY id");
        $stmt->execute($seatIds);
        return $stmt->fetchAll();
    }

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
}
