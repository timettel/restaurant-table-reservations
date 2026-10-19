<?php

declare(strict_types=1);

final class Database
{
    public static function connect(array $config): PDO
    {
        $databaseFile = dirname(__DIR__) . '/var/reservations.sqlite';
        $databaseDir = dirname($databaseFile);
        if (!is_dir($databaseDir) && !mkdir($databaseDir, 0770, true) && !is_dir($databaseDir)) {
            throw new RuntimeException('Database directory could not be created.');
        }

        $pdo = new PDO('sqlite:' . $databaseFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 8000');
        // DELETE mode is deliberately used because the requested database path is
        // on NFS; WAL mode is unsafe on network filesystems.
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('PRAGMA synchronous = FULL');

        self::migrate($pdo, $config);
        return $pdo;
    }

    private static function migrate(PDO $pdo, array $config): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS weekly_schedule (
    weekday INTEGER PRIMARY KEY CHECK (weekday BETWEEN 1 AND 7),
    enabled INTEGER NOT NULL DEFAULT 1,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    interval_minutes INTEGER NOT NULL DEFAULT 30
);

CREATE TABLE IF NOT EXISTS opening_blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    weekday INTEGER NOT NULL CHECK (weekday BETWEEN 1 AND 7),
    sort_order INTEGER NOT NULL DEFAULT 0,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    FOREIGN KEY (weekday) REFERENCES weekly_schedule(weekday) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS closures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date_from TEXT NOT NULL,
    date_to TEXT NOT NULL,
    reason TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS reservations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE,
    table_id INTEGER NOT NULL,
    reservation_date TEXT NOT NULL,
    start_time TEXT NOT NULL,
    end_time TEXT NOT NULL,
    guest_name TEXT NOT NULL,
    phone TEXT NOT NULL DEFAULT '',
    email TEXT NOT NULL DEFAULT '',
    party_size INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'confirmed',
    notification_status TEXT NOT NULL DEFAULT 'pending',
    notification_error TEXT NOT NULL DEFAULT '',
    client_hash TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS manual_occupancies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    table_id INTEGER NOT NULL,
    party_size INTEGER NOT NULL,
    note TEXT NOT NULL DEFAULT '',
    started_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    released_at INTEGER,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS public_booking_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    client_hash TEXT NOT NULL,
    attempted_at INTEGER NOT NULL,
    successful INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS used_captcha_challenges (
    nonce TEXT PRIMARY KEY,
    used_at INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS reservations_slot_idx
    ON reservations (reservation_date, table_id, status, start_time, end_time);
CREATE INDEX IF NOT EXISTS reservations_upcoming_idx
    ON reservations (reservation_date, start_time, status);
CREATE INDEX IF NOT EXISTS closures_dates_idx
    ON closures (date_from, date_to);
CREATE INDEX IF NOT EXISTS opening_blocks_weekday_idx
    ON opening_blocks (weekday, sort_order, start_time);
CREATE INDEX IF NOT EXISTS manual_occupancies_active_idx
    ON manual_occupancies (status, table_id, expires_at);
CREATE INDEX IF NOT EXISTS public_booking_attempts_client_idx
    ON public_booking_attempts (client_hash, attempted_at, successful);
CREATE INDEX IF NOT EXISTS public_booking_attempts_time_idx
    ON public_booking_attempts (attempted_at);
CREATE INDEX IF NOT EXISTS used_captcha_challenges_time_idx
    ON used_captcha_challenges (used_at);
SQL);

        self::ensureColumn($pdo, 'reservations', 'cancellation_deadline', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'reservations', 'cancelled_at', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'reservations', 'cancellation_source', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'reservations', 'confirmation_status', "TEXT NOT NULL DEFAULT 'pending'");
        self::ensureColumn($pdo, 'reservations', 'confirmation_error', "TEXT NOT NULL DEFAULT ''");
        self::ensureColumn($pdo, 'reservations', 'source', "TEXT NOT NULL DEFAULT 'online'");

        $insertSetting = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:key, :value)');
        foreach ([
            'reservation_duration_minutes' => (string) $config['defaults']['reservation_duration_minutes'],
            'advance_days' => (string) $config['defaults']['advance_days'],
            'recipient_email' => (string) $config['defaults']['recipient_email'],
            'cancellation_enabled' => (string) ($config['defaults']['cancellation_enabled'] ?? '1'),
            'cancellation_cutoff_minutes' => (string) ($config['defaults']['cancellation_cutoff_minutes'] ?? '1440'),
        ] as $key => $value) {
            $insertSetting->execute(['key' => $key, 'value' => $value]);
        }

        $insertSchedule = $pdo->prepare(<<<'SQL'
INSERT OR IGNORE INTO weekly_schedule (weekday, enabled, start_time, end_time, interval_minutes)
VALUES (:weekday, :enabled, :start_time, :end_time, :interval_minutes)
SQL);
        foreach ($config['defaults']['weekly_schedule'] as $weekday => $schedule) {
            $insertSchedule->execute([
                'weekday' => $weekday,
                'enabled' => $schedule['enabled'] ? 1 : 0,
                'start_time' => $schedule['start'],
                'end_time' => $schedule['end'],
                'interval_minutes' => $schedule['interval'],
            ]);
        }

        $migrationDone = (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key = 'opening_blocks_migrated_v1'")->fetchColumn() > 0;
        if (!$migrationDone) {
            if ((int) $pdo->query('SELECT COUNT(*) FROM opening_blocks')->fetchColumn() === 0) {
                $insertBlock = $pdo->prepare(<<<'SQL'
INSERT INTO opening_blocks (weekday, sort_order, start_time, end_time)
VALUES (:weekday, 0, :start_time, :end_time)
SQL);
                foreach ($pdo->query('SELECT weekday, start_time, end_time FROM weekly_schedule ORDER BY weekday')->fetchAll() as $schedule) {
                    $insertBlock->execute([
                        'weekday' => $schedule['weekday'],
                        'start_time' => $schedule['start_time'],
                        'end_time' => $schedule['end_time'],
                    ]);
                }
            }
            $insertSetting->execute(['key' => 'opening_blocks_migrated_v1', 'value' => '1']);
        }
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $columns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll();
        foreach ($columns as $existing) {
            if ((string) $existing['name'] === $column) {
                return;
            }
        }
        $pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }
}
