<?php

declare(strict_types=1);

final class ReservationService
{
    private PDO $pdo;
    private array $config;
    private DateTimeZone $timezone;

    public function __construct(PDO $pdo, array $config)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->timezone = new DateTimeZone($config['app']['timezone']);
    }

    public function publicBootstrap(): array
    {
        return [
            'duration_minutes' => $this->durationMinutes(),
            'advance_days' => $this->advanceDays(),
            'dates' => $this->bookableDates(),
            'tables' => $this->config['tables'],
            'booking_token' => $this->createBookingToken(),
            'generated_at' => (int) round(microtime(true) * 1000),
        ];
    }

    public function bookableDates(): array
    {
        $today = new DateTimeImmutable('today', $this->timezone);
        $result = [];
        for ($offset = 0; $offset <= $this->advanceDays(); $offset++) {
            $date = $today->modify('+' . $offset . ' days');
            $dateString = $date->format('Y-m-d');
            $closure = $this->closureForDate($dateString);
            $schedule = $this->scheduleForWeekday((int) $date->format('N'));
            $closed = $closure !== null || !$schedule || !(bool) $schedule['enabled'] || $schedule['blocks'] === [];
            $result[] = [
                'date' => $dateString,
                'label' => $this->dateLabel($date, $offset === 0),
                'long_label' => $this->longDateLabel($date),
                'closed' => $closed,
                'reason' => $closure['reason'] ?? ($closed ? 'Ruhetag' : ''),
            ];
        }
        return $result;
    }

    public function slotsForDate(string $date): array
    {
        $parsed = $this->validateBookableDate($date);
        if ($this->closureForDate($date) !== null) {
            return [];
        }
        $schedule = $this->scheduleForWeekday((int) $parsed->format('N'));
        if (!$schedule || !(bool) $schedule['enabled']) {
            return [];
        }

        $duration = $this->durationMinutes();
        $interval = max(5, (int) $schedule['interval_minutes']);
        $now = new DateTimeImmutable('now', $this->timezone);
        $slots = [];

        foreach ($schedule['blocks'] as $block) {
            $cursor = new DateTimeImmutable($date . ' ' . $block['start_time'], $this->timezone);
            $closing = new DateTimeImmutable($date . ' ' . $block['end_time'], $this->timezone);
            $latestStart = $closing->modify('-' . $duration . ' minutes');
            while ($cursor <= $latestStart) {
                if ($cursor > $now) {
                    $slots[$cursor->format('H:i')] = true;
                }
                $cursor = $cursor->modify('+' . $interval . ' minutes');
            }
        }
        $result = array_keys($slots);
        sort($result, SORT_STRING);
        return $result;
    }

    public function availability(string $date, string $time): array
    {
        $this->assertValidSlot($date, $time);
        $end = $this->endTime($date, $time);
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT table_id
FROM reservations
WHERE reservation_date = :date
  AND status = 'confirmed'
  AND start_time < :end_time
  AND end_time > :start_time
SQL);
        $stmt->execute(['date' => $date, 'start_time' => $time, 'end_time' => $end]);
        $occupied = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $startEpoch = (new DateTimeImmutable($date . ' ' . $time, $this->timezone))->getTimestamp();
        $endEpoch = $startEpoch + $this->durationMinutes() * 60;
        $manual = $this->pdo->prepare("SELECT table_id FROM manual_occupancies WHERE status = 'active' AND started_at < :end_epoch AND expires_at > :start_epoch");
        $manual->execute(['start_epoch' => $startEpoch, 'end_epoch' => $endEpoch]);
        return [
            'date' => $date,
            'time' => $time,
            'end_time' => $end,
            'occupied_table_ids' => array_values(array_unique(array_merge($occupied, array_map('intval', $manual->fetchAll(PDO::FETCH_COLUMN))))),
        ];
    }

    public function createReservation(array $input, string $clientIp): array
    {
        return $this->createReservationRecord($input, $clientIp, 'online', true);
    }

    public function createAdminReservation(array $input): array
    {
        return $this->createReservationRecord($input, 'admin', 'phone', false);
    }

    private function createReservationRecord(array $input, string $clientIp, string $source, bool $verifyPublicRequest): array
    {
        if ($verifyPublicRequest) {
            $this->verifyBookingToken((string) ($input['booking_token'] ?? ''));
            if (trim((string) ($input['website'] ?? '')) !== '') {
                throw new InvalidArgumentException('Die Reservierung konnte nicht verarbeitet werden.');
            }
        }

        $name = preg_replace('/\s+/u', ' ', trim((string) ($input['name'] ?? ''))) ?? '';
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $date = (string) ($input['date'] ?? '');
        $time = (string) ($input['time'] ?? '');
        $tableId = filter_var($input['table_id'] ?? null, FILTER_VALIDATE_INT);
        $partySize = filter_var($input['party_size'] ?? null, FILTER_VALIDATE_INT);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Bitte geben Sie einen gültigen Namen ein.');
        }
        if ($phone === '' && $email === '') {
            throw new InvalidArgumentException('Bitte geben Sie eine Telefonnummer oder E-Mail-Adresse an.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Bitte prüfen Sie die E-Mail-Adresse.');
        }
        if ($phone !== '' && (mb_strlen($phone) < 5 || mb_strlen($phone) > 40 || !preg_match('/^[0-9+() .\/-]+$/', $phone))) {
            throw new InvalidArgumentException('Bitte prüfen Sie die Telefonnummer.');
        }
        if ($tableId === false || $partySize === false) {
            throw new InvalidArgumentException('Tisch oder Gästezahl ist ungültig.');
        }

        $table = $this->tableById((int) $tableId);
        if ($table === null) {
            throw new InvalidArgumentException('Der gewählte Tisch existiert nicht.');
        }
        if ($partySize < 1 || $partySize > (int) $table['capacity']) {
            throw new InvalidArgumentException(sprintf('An Tisch %s sind höchstens %d Gäste möglich.', $table['label'], $table['capacity']));
        }

        $this->assertValidSlot($date, $time);
        $end = $this->endTime($date, $time);
        $publicId = bin2hex(random_bytes(12));
        $createdAt = (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM);
        $clientHash = hash_hmac('sha256', $clientIp, $this->config['app']['secret']);
        $cancellationDeadline = '';
        if ($this->cancellationEnabled()) {
            $cancellationDeadline = (new DateTimeImmutable($date . ' ' . $time, $this->timezone))
                ->modify('-' . $this->cancellationCutoffMinutes() . ' minutes')
                ->format(DATE_ATOM);
        }

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $overlap = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM reservations
WHERE reservation_date = :date
  AND table_id = :table_id
  AND status = 'confirmed'
  AND start_time < :end_time
  AND end_time > :start_time
SQL);
            $overlap->execute([
                'date' => $date,
                'table_id' => $tableId,
                'start_time' => $time,
                'end_time' => $end,
            ]);
            if ((int) $overlap->fetchColumn() > 0) {
                $this->pdo->exec('ROLLBACK');
                throw new DomainException('Dieser Tisch wurde gerade anderweitig reserviert. Bitte wählen Sie einen anderen Tisch.');
            }

            $startEpoch = (new DateTimeImmutable($date . ' ' . $time, $this->timezone))->getTimestamp();
            $endEpoch = $startEpoch + $this->durationMinutes() * 60;
            $manual = $this->pdo->prepare("SELECT COUNT(*) FROM manual_occupancies WHERE table_id = :table_id AND status = 'active' AND started_at < :end_epoch AND expires_at > :start_epoch");
            $manual->execute(['table_id' => $tableId, 'start_epoch' => $startEpoch, 'end_epoch' => $endEpoch]);
            if ((int) $manual->fetchColumn() > 0) {
                throw new DomainException('Dieser Tisch ist bereits manuell belegt. Bitte wählen Sie einen anderen Tisch.');
            }

            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO reservations (
    public_id, table_id, reservation_date, start_time, end_time,
    guest_name, phone, email, party_size, status, notification_status,
    client_hash, created_at, cancellation_deadline,
    confirmation_status, source
) VALUES (
    :public_id, :table_id, :reservation_date, :start_time, :end_time,
    :guest_name, :phone, :email, :party_size, 'confirmed', :notification_status,
    :client_hash, :created_at, :cancellation_deadline,
    :confirmation_status, :source
)
SQL);
            $insert->execute([
                'public_id' => $publicId,
                'table_id' => $tableId,
                'reservation_date' => $date,
                'start_time' => $time,
                'end_time' => $end,
                'guest_name' => $name,
                'phone' => $phone,
                'email' => $email,
                'party_size' => $partySize,
                'client_hash' => $clientHash,
                'created_at' => $createdAt,
                'cancellation_deadline' => $cancellationDeadline,
                'confirmation_status' => $email !== '' ? 'pending' : 'not_applicable',
                'notification_status' => $source === 'online' ? 'pending' : 'not_applicable',
                'source' => $source,
            ]);
            $reservationId = (int) $this->pdo->lastInsertId();
            $this->pdo->exec('COMMIT');
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $exception;
        }

        return [
            'id' => $reservationId,
            'public_id' => $publicId,
            'table_id' => (int) $tableId,
            'table_label' => (string) $table['label'],
            'date' => $date,
            'date_label' => $this->longDateLabel(new DateTimeImmutable($date, $this->timezone)),
            'start_time' => $time,
            'end_time' => $end,
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'party_size' => (int) $partySize,
            'cancellation_deadline' => $cancellationDeadline,
            'cancellation_token' => $this->cancellationToken($publicId),
            'source' => $source,
        ];
    }

    public function markNotification(int $reservationId, string $status, string $error = ''): void
    {
        $stmt = $this->pdo->prepare('UPDATE reservations SET notification_status = :status, notification_error = :error WHERE id = :id');
        $stmt->execute(['status' => $status, 'error' => mb_substr($error, 0, 1000), 'id' => $reservationId]);
    }

    public function markConfirmation(int $reservationId, string $status, string $error = ''): void
    {
        $stmt = $this->pdo->prepare('UPDATE reservations SET confirmation_status = :status, confirmation_error = :error WHERE id = :id');
        $stmt->execute(['status' => $status, 'error' => mb_substr($error, 0, 1000), 'id' => $reservationId]);
    }

    public function cancellationEnabled(): bool
    {
        return $this->setting('cancellation_enabled', '1') === '1';
    }

    public function cancellationCutoffMinutes(): int
    {
        return max(0, min(525600, (int) $this->setting('cancellation_cutoff_minutes', '1440')));
    }

    public function cancellationToken(string $publicId): string
    {
        return hash_hmac('sha256', 'cancel|' . $publicId, $this->config['app']['secret']);
    }

    public function cancellationDetails(string $publicId, string $token): array
    {
        $this->verifyCancellationToken($publicId, $token);
        $stmt = $this->pdo->prepare('SELECT * FROM reservations WHERE public_id = :public_id LIMIT 1');
        $stmt->execute(['public_id' => $publicId]);
        $reservation = $stmt->fetch();
        if (!$reservation) {
            throw new InvalidArgumentException('Die Reservierung wurde nicht gefunden.');
        }

        $deadline = (string) $reservation['cancellation_deadline'];
        $now = new DateTimeImmutable('now', $this->timezone);
        $deadlineDate = $deadline !== '' ? new DateTimeImmutable($deadline, $this->timezone) : null;
        $canCancel = $reservation['status'] === 'confirmed' && $deadlineDate !== null && $now <= $deadlineDate;
        $reason = '';
        if ($reservation['status'] === 'cancelled') {
            $reason = 'Diese Reservierung wurde bereits storniert.';
        } elseif ($deadlineDate === null) {
            $reason = 'Für diese Reservierung ist keine Online-Stornierung vorgesehen.';
        } elseif ($now > $deadlineDate) {
            $reason = 'Die Frist für eine Online-Stornierung ist abgelaufen.';
        }

        $table = $this->tableById((int) $reservation['table_id']);
        return [
            'id' => (int) $reservation['id'],
            'public_id' => (string) $reservation['public_id'],
            'name' => (string) $reservation['guest_name'],
            'email' => (string) $reservation['email'],
            'date' => (string) $reservation['reservation_date'],
            'date_label' => $this->longDateLabel(new DateTimeImmutable((string) $reservation['reservation_date'], $this->timezone)),
            'start_time' => (string) $reservation['start_time'],
            'end_time' => (string) $reservation['end_time'],
            'table_label' => (string) ($table['label'] ?? $reservation['table_id']),
            'party_size' => (int) $reservation['party_size'],
            'status' => (string) $reservation['status'],
            'cancellation_deadline' => $deadline,
            'cancellation_deadline_label' => $deadlineDate?->format('d.m.Y, H:i') ?? '',
            'can_cancel' => $canCancel,
            'reason' => $reason,
        ];
    }

    public function cancelByGuest(string $publicId, string $token): array
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $reservation = $this->cancellationDetails($publicId, $token);
            if (!$reservation['can_cancel']) {
                throw new DomainException($reservation['reason']);
            }
            $cancelledAt = (new DateTimeImmutable('now', $this->timezone))->format(DATE_ATOM);
            $stmt = $this->pdo->prepare(<<<'SQL'
UPDATE reservations
SET status = 'cancelled', cancelled_at = :cancelled_at, cancellation_source = 'guest'
WHERE id = :id AND status = 'confirmed'
SQL);
            $stmt->execute(['cancelled_at' => $cancelledAt, 'id' => $reservation['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new DomainException('Diese Reservierung wurde bereits storniert.');
            }
            $this->pdo->exec('COMMIT');
            $reservation['status'] = 'cancelled';
            $reservation['can_cancel'] = false;
            $reservation['reason'] = 'Die Reservierung wurde erfolgreich storniert.';
            return $reservation;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $exception;
        }
    }

    public function durationMinutes(): int
    {
        return max(30, (int) $this->setting('reservation_duration_minutes', '120'));
    }

    public function advanceDays(): int
    {
        return max(1, min(365, (int) $this->setting('advance_days', '14')));
    }

    public function setting(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    private function scheduleForWeekday(int $weekday): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM weekly_schedule WHERE weekday = :weekday');
        $stmt->execute(['weekday' => $weekday]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $blocks = $this->pdo->prepare('SELECT start_time, end_time FROM opening_blocks WHERE weekday = :weekday ORDER BY sort_order, start_time');
        $blocks->execute(['weekday' => $weekday]);
        $row['blocks'] = $blocks->fetchAll();
        return $row;
    }

    private function closureForDate(string $date): ?array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
SELECT * FROM closures
WHERE date_from <= :date AND date_to >= :date
ORDER BY date_from ASC
LIMIT 1
SQL);
        $stmt->execute(['date' => $date]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function validateBookableDate(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$parsed || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Ungültiges Reservierungsdatum.');
        }
        $today = new DateTimeImmutable('today', $this->timezone);
        $lastDay = $today->modify('+' . $this->advanceDays() . ' days');
        if ($parsed < $today || $parsed > $lastDay) {
            throw new InvalidArgumentException('Das Datum liegt außerhalb des buchbaren Zeitraums.');
        }
        return $parsed;
    }

    private function assertValidSlot(string $date, string $time): void
    {
        if (!preg_match('/^\d{2}:\d{2}$/', $time) || !in_array($time, $this->slotsForDate($date), true)) {
            throw new InvalidArgumentException('Dieser Zeitpunkt ist nicht buchbar.');
        }
    }

    private function endTime(string $date, string $time): string
    {
        return (new DateTimeImmutable($date . ' ' . $time, $this->timezone))
            ->modify('+' . $this->durationMinutes() . ' minutes')
            ->format('H:i');
    }

    private function tableById(int $id): ?array
    {
        foreach ($this->config['tables'] as $table) {
            if ((int) $table['id'] === $id) {
                return $table;
            }
        }
        return null;
    }

    private function createBookingToken(): string
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', (string) $timestamp, $this->config['app']['secret']);
        return $timestamp . '.' . $signature;
    }

    private function verifyBookingToken(string $token): void
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || !ctype_digit($parts[0])) {
            throw new InvalidArgumentException('Die Buchungsseite ist abgelaufen. Bitte laden Sie sie neu.');
        }
        $timestamp = (int) $parts[0];
        $expected = hash_hmac('sha256', (string) $timestamp, $this->config['app']['secret']);
        if (!hash_equals($expected, $parts[1]) || $timestamp < time() - 7200 || $timestamp > time() + 60) {
            throw new InvalidArgumentException('Die Buchungsseite ist abgelaufen. Bitte laden Sie sie neu.');
        }
    }

    private function verifyCancellationToken(string $publicId, string $token): void
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $publicId) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new InvalidArgumentException('Der Stornolink ist ungültig.');
        }
        if (!hash_equals($this->cancellationToken($publicId), $token)) {
            throw new InvalidArgumentException('Der Stornolink ist ungültig.');
        }
    }

    private function dateLabel(DateTimeImmutable $date, bool $today): string
    {
        $days = [1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So'];
        $prefix = $today ? 'Heute · ' : '';
        return sprintf('%s%s, %s', $prefix, $days[(int) $date->format('N')], $date->format('d.m.'));
    }

    private function longDateLabel(DateTimeImmutable $date): string
    {
        $days = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
        $months = [1 => 'Januar', 2 => 'Februar', 3 => 'März', 4 => 'April', 5 => 'Mai', 6 => 'Juni', 7 => 'Juli', 8 => 'August', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Dezember'];
        return sprintf('%s, %d. %s', $days[(int) $date->format('N')], (int) $date->format('j'), $months[(int) $date->format('n')]);
    }
}
