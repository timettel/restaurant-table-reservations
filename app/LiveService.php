<?php

declare(strict_types=1);

final class LiveService
{
    private DateTimeZone $timezone;

    public function __construct(private PDO $pdo, private array $config, private ReservationService $reservations)
    {
        $this->timezone = new DateTimeZone($config['app']['timezone']);
    }

    public function snapshot(): array
    {
        $now = new DateTimeImmutable('now', $this->timezone);
        $nowEpoch = $now->getTimestamp();
        $horizon = $nowEpoch + 120 * 60;
        $this->pdo->prepare("UPDATE manual_occupancies SET status = 'expired' WHERE status = 'active' AND expires_at <= :now")
            ->execute(['now' => $nowEpoch]);

        $fromDate = $now->format('Y-m-d');
        $toDate = $now->modify('+2 days')->format('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT id, table_id, reservation_date, start_time, end_time, guest_name, party_size, source FROM reservations WHERE status = 'confirmed' AND reservation_date BETWEEN :from_date AND :to_date ORDER BY reservation_date, start_time, table_id");
        $stmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
        $future = [];
        $byTable = [];
        foreach ($stmt->fetchAll() as $row) {
            $start = new DateTimeImmutable($row['reservation_date'] . ' ' . $row['start_time'], $this->timezone);
            $end = new DateTimeImmutable($row['reservation_date'] . ' ' . $row['end_time'], $this->timezone);
            $item = [
                'id' => (int) $row['id'],
                'table_id' => (int) $row['table_id'],
                'date' => (string) $row['reservation_date'],
                'time' => (string) $row['start_time'],
                'end_time' => (string) $row['end_time'],
                'start_epoch' => $start->getTimestamp(),
                'end_epoch' => $end->getTimestamp(),
                'name' => (string) $row['guest_name'],
                'party_size' => (int) $row['party_size'],
                'source' => (string) $row['source'],
            ];
            if ($item['end_epoch'] <= $nowEpoch) continue;
            $byTable[$item['table_id']][] = $item;
            if ($item['start_epoch'] >= $nowEpoch) $future[] = $item;
        }

        $manual = [];
        $stmt = $this->pdo->prepare("SELECT id, table_id, party_size, note, started_at, expires_at FROM manual_occupancies WHERE status = 'active' AND expires_at > :now ORDER BY started_at DESC");
        $stmt->execute(['now' => $nowEpoch]);
        foreach ($stmt->fetchAll() as $row) {
            $manual[(int) $row['table_id']] = [
                'id' => (int) $row['id'], 'party_size' => (int) $row['party_size'],
                'note' => (string) $row['note'], 'started_at' => (int) $row['started_at'],
                'expires_at' => (int) $row['expires_at'],
            ];
        }

        $tables = [];
        foreach ($this->config['tables'] as $table) {
            $id = (int) $table['id'];
            $current = null;
            $next = null;
            foreach ($byTable[$id] ?? [] as $reservation) {
                if ($reservation['start_epoch'] <= $nowEpoch && $reservation['end_epoch'] > $nowEpoch) $current = $reservation;
                elseif ($reservation['start_epoch'] > $nowEpoch && $next === null) $next = $reservation;
            }
            $occupancy = $manual[$id] ?? null;
            $status = $current !== null ? 'reserved' : ($occupancy !== null ? 'manual' : ($next !== null && $next['start_epoch'] <= $horizon ? 'soon' : 'free'));
            $tables[] = $table + ['status' => $status, 'current' => $current, 'next' => $next, 'occupancy' => $occupancy];
        }
        return [
            'now_epoch' => $nowEpoch,
            'now_label' => $now->format('d.m.Y · H:i'),
            'today' => $fromDate,
            'refresh_seconds' => 300,
            'duration_minutes' => $this->reservations->durationMinutes(),
            'tables' => $tables,
            'upcoming' => array_slice($future, 0, 12),
            'dates' => $this->reservations->bookableDates(),
        ];
    }

    public function occupy(array $input): array
    {
        $tableId = filter_var($input['table_id'] ?? null, FILTER_VALIDATE_INT);
        $partySize = filter_var($input['party_size'] ?? null, FILTER_VALIDATE_INT);
        $duration = filter_var($input['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
        $note = trim((string) ($input['note'] ?? ''));
        $table = $this->tableById((int) $tableId);
        if ($tableId === false || $table === null || $partySize === false || $partySize < 1 || $partySize > (int) $table['capacity']) {
            throw new InvalidArgumentException('Bitte Tisch und Gästezahl prüfen.');
        }
        if (!in_array($duration, [30, 60, 90, 120, 150, 180, 240], true) || mb_strlen($note) > 160) {
            throw new InvalidArgumentException('Bitte Dauer und Notiz prüfen.');
        }
        $now = time();
        $end = $now + $duration * 60;
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $active = $this->pdo->prepare("SELECT COUNT(*) FROM manual_occupancies WHERE table_id = :table_id AND status = 'active' AND expires_at > :now");
            $active->execute(['table_id' => $tableId, 'now' => $now]);
            if ((int) $active->fetchColumn() > 0) throw new DomainException('Dieser Tisch wurde inzwischen manuell belegt.');
            if ($this->reservationOverlaps((int) $tableId, $now, $end)) {
                throw new DomainException('In diesem Zeitraum liegt eine Reservierung für den Tisch. Bitte eine kürzere Dauer oder einen anderen Tisch wählen.');
            }
            $stmt = $this->pdo->prepare("INSERT INTO manual_occupancies (table_id, party_size, note, started_at, expires_at, created_at) VALUES (:table_id, :party_size, :note, :started_at, :expires_at, :created_at)");
            $stmt->execute(['table_id' => $tableId, 'party_size' => $partySize, 'note' => $note, 'started_at' => $now, 'expires_at' => $end, 'created_at' => date(DATE_ATOM)]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
            return ['id' => $id, 'table_id' => (int) $tableId, 'expires_at' => $end];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function release(int $id): void
    {
        if ($id < 1) throw new InvalidArgumentException('Ungültige Belegung.');
        $stmt = $this->pdo->prepare("UPDATE manual_occupancies SET status = 'released', released_at = :now WHERE id = :id AND status = 'active'");
        $stmt->execute(['now' => time(), 'id' => $id]);
        if ($stmt->rowCount() !== 1) throw new DomainException('Diese Belegung ist bereits beendet.');
    }

    private function reservationOverlaps(int $tableId, int $start, int $end): bool
    {
        $dateFrom = (new DateTimeImmutable('@' . $start))->setTimezone($this->timezone)->format('Y-m-d');
        $dateTo = (new DateTimeImmutable('@' . $end))->setTimezone($this->timezone)->format('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT reservation_date, start_time, end_time FROM reservations WHERE table_id = :table_id AND status = 'confirmed' AND reservation_date BETWEEN :from_date AND :to_date");
        $stmt->execute(['table_id' => $tableId, 'from_date' => $dateFrom, 'to_date' => $dateTo]);
        foreach ($stmt->fetchAll() as $row) {
            $reservationStart = (new DateTimeImmutable($row['reservation_date'] . ' ' . $row['start_time'], $this->timezone))->getTimestamp();
            $reservationEnd = (new DateTimeImmutable($row['reservation_date'] . ' ' . $row['end_time'], $this->timezone))->getTimestamp();
            if ($reservationStart < $end && $reservationEnd > $start) return true;
        }
        return false;
    }

    private function tableById(int $id): ?array
    {
        foreach ($this->config['tables'] as $table) if ((int) $table['id'] === $id) return $table;
        return null;
    }
}
