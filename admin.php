<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$config = appConfig();
session_name('reservation_admin');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $config['app']['base_path'],
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');

function redirectAdmin(string $view = 'reservations', string $message = ''): never
{
    $query = ['view' => $view];
    if ($message !== '') {
        $query['message'] = $message;
    }
    header('Location: admin.php?' . http_build_query($query));
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return (string) $_SESSION['csrf'];
}

function requireCsrf(): void
{
    $token = (string) ($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals(csrfToken(), $token)) {
        throw new RuntimeException('Die Sitzung ist abgelaufen. Bitte laden Sie die Seite neu.');
    }
}

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function upsertSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO settings (key, value) VALUES (:key, :value)
ON CONFLICT(key) DO UPDATE SET value = excluded.value
SQL);
    $stmt->execute(['key' => $key, 'value' => $value]);
}

$loginError = '';
$action = (string) ($_POST['action'] ?? '');

if (empty($_SESSION['admin_authenticated'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
        $password = (string) ($_POST['password'] ?? '');
        if (password_verify($password, $config['app']['admin_password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            csrfToken();
            redirectAdmin();
        }
        usleep(350000);
        $loginError = 'Das Passwort ist nicht korrekt.';
    }
    ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Anmeldung – Reservierungsverwaltung</title>
    <link rel="stylesheet" href="assets/admin.css?v=2">
</head>
<body class="login-page">
<main class="login-shell">
    <form class="login-form" method="post" action="admin.php">
        <input type="hidden" name="action" value="login">
        <h1>Reservierungsverwaltung</h1>
        <p>Bitte mit dem administrativen Passwort anmelden.</p>
        <?php if ($loginError !== ''): ?>
            <div class="notice notice--error" role="alert"><?= e($loginError) ?></div>
        <?php endif; ?>
        <label class="field">
            <span>Passwort</span>
            <input type="password" name="password" autocomplete="current-password" autofocus required>
        </label>
        <button class="button button--primary" type="submit">Anmelden</button>
        <a class="back-link" href="./">Zur Reservierungsseite</a>
    </form>
</main>
</body>
</html>
    <?php
    exit;
}

$pdo = db();
$view = in_array(($_GET['view'] ?? 'reservations'), ['reservations', 'settings'], true)
    ? (string) $_GET['view']
    : 'reservations';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireCsrf();

        if ($action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: admin.php');
            exit;
        }

        if ($action === 'save_settings') {
            $duration = filter_var($_POST['reservation_duration_minutes'] ?? null, FILTER_VALIDATE_INT);
            $advanceDays = filter_var($_POST['advance_days'] ?? null, FILTER_VALIDATE_INT);
            $cancellationEnabled = isset($_POST['cancellation_enabled']) ? 1 : 0;
            $cutoffValue = filter_var($_POST['cancellation_cutoff_value'] ?? null, FILTER_VALIDATE_INT);
            $cutoffUnit = (string) ($_POST['cancellation_cutoff_unit'] ?? 'hours');
            $recipient = trim((string) ($_POST['recipient_email'] ?? ''));
            if ($duration === false || $duration < 30 || $duration > 480) {
                throw new InvalidArgumentException('Die Reservierungsdauer muss zwischen 30 und 480 Minuten liegen.');
            }
            if ($advanceDays === false || $advanceDays < 1 || $advanceDays > 365) {
                throw new InvalidArgumentException('Der Buchungszeitraum muss zwischen 1 und 365 Tagen liegen.');
            }
            if ($recipient !== '' && !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Bitte prüfen Sie die Empfängeradresse.');
            }
            if ($cutoffValue === false || $cutoffValue < 0 || $cutoffValue > 8760 || !in_array($cutoffUnit, ['hours', 'days'], true)) {
                throw new InvalidArgumentException('Bitte prüfen Sie die Stornierungsfrist.');
            }
            $cutoffMinutes = $cutoffValue * ($cutoffUnit === 'days' ? 1440 : 60);
            if ($cutoffMinutes > 525600) {
                throw new InvalidArgumentException('Die Stornierungsfrist darf höchstens 365 Tage betragen.');
            }

            $pdo->beginTransaction();
            upsertSetting($pdo, 'reservation_duration_minutes', (string) $duration);
            upsertSetting($pdo, 'advance_days', (string) $advanceDays);
            upsertSetting($pdo, 'recipient_email', $recipient);
            upsertSetting($pdo, 'cancellation_enabled', (string) $cancellationEnabled);
            upsertSetting($pdo, 'cancellation_cutoff_minutes', (string) $cutoffMinutes);

            $updateSchedule = $pdo->prepare(<<<'SQL'
UPDATE weekly_schedule
SET enabled = :enabled,
    start_time = COALESCE(:start_time, start_time),
    end_time = COALESCE(:end_time, end_time),
    interval_minutes = :interval_minutes
WHERE weekday = :weekday
SQL);
            $deleteBlocks = $pdo->prepare('DELETE FROM opening_blocks WHERE weekday = :weekday');
            $insertBlock = $pdo->prepare(<<<'SQL'
INSERT INTO opening_blocks (weekday, sort_order, start_time, end_time)
VALUES (:weekday, :sort_order, :start_time, :end_time)
SQL);
            for ($weekday = 1; $weekday <= 7; $weekday++) {
                $enabled = isset($_POST['schedule'][$weekday]['enabled']) ? 1 : 0;
                $interval = filter_var($_POST['schedule'][$weekday]['interval'] ?? null, FILTER_VALIDATE_INT);
                if (!in_array($interval, [15, 30, 45, 60], true)) {
                    throw new InvalidArgumentException('Das Zeitintervall ist ungültig.');
                }

                $blocks = [];
                foreach ((array) ($_POST['schedule'][$weekday]['blocks'] ?? []) as $block) {
                    $start = (string) ($block['start'] ?? '');
                    $end = (string) ($block['end'] ?? '');
                    if ($start === '' && $end === '') {
                        continue;
                    }
                    if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start >= $end) {
                        throw new InvalidArgumentException('Bitte prüfen Sie die Öffnungsblöcke für ' . ([1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'][$weekday]) . '.');
                    }
                    $blocks[] = ['start' => $start, 'end' => $end];
                }
                usort($blocks, static fn(array $a, array $b): int => strcmp($a['start'], $b['start']));
                foreach ($blocks as $index => $block) {
                    if ($index > 0 && $block['start'] < $blocks[$index - 1]['end']) {
                        throw new InvalidArgumentException('Die Öffnungsblöcke eines Tages dürfen sich nicht überschneiden.');
                    }
                }
                if ($enabled === 1 && $blocks === []) {
                    throw new InvalidArgumentException('Für einen geöffneten Wochentag muss mindestens ein Öffnungsblock vorhanden sein.');
                }

                $updateSchedule->execute([
                    'enabled' => $enabled,
                    'start_time' => $blocks[0]['start'] ?? null,
                    'end_time' => $blocks[0]['end'] ?? null,
                    'interval_minutes' => $interval,
                    'weekday' => $weekday,
                ]);
                $deleteBlocks->execute(['weekday' => $weekday]);
                foreach ($blocks as $index => $block) {
                    $insertBlock->execute([
                        'weekday' => $weekday,
                        'sort_order' => $index,
                        'start_time' => $block['start'],
                        'end_time' => $block['end'],
                    ]);
                }
            }
            $pdo->commit();
            redirectAdmin('settings', 'saved');
        }

        if ($action === 'add_closure') {
            $dateFrom = (string) ($_POST['date_from'] ?? '');
            $dateTo = (string) ($_POST['date_to'] ?? '');
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if (!validDate($dateFrom) || !validDate($dateTo) || $dateFrom > $dateTo) {
                throw new InvalidArgumentException('Bitte prüfen Sie den Zeitraum der Ausnahme.');
            }
            if (mb_strlen($reason) > 160) {
                throw new InvalidArgumentException('Die Beschreibung ist zu lang.');
            }
            $stmt = $pdo->prepare('INSERT INTO closures (date_from, date_to, reason, created_at) VALUES (:date_from, :date_to, :reason, :created_at)');
            $stmt->execute([
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'reason' => $reason !== '' ? $reason : 'Geschlossen',
                'created_at' => date(DATE_ATOM),
            ]);
            redirectAdmin('settings', 'closure-added');
        }

        if ($action === 'delete_closure') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false) throw new InvalidArgumentException('Ungültige Ausnahme.');
            $stmt = $pdo->prepare('DELETE FROM closures WHERE id = :id');
            $stmt->execute(['id' => $id]);
            redirectAdmin('settings', 'closure-deleted');
        }

        if ($action === 'cancel_reservation') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if ($id === false) throw new InvalidArgumentException('Ungültige Reservierung.');
            $stmt = $pdo->prepare("UPDATE reservations SET status = 'cancelled', cancelled_at = :cancelled_at, cancellation_source = 'admin', confirmation_status = CASE WHEN confirmation_status = 'pending' THEN 'not_applicable' ELSE confirmation_status END WHERE id = :id AND status = 'confirmed'");
            $stmt->execute(['id' => $id, 'cancelled_at' => date(DATE_ATOM)]);
            redirectAdmin('reservations', 'cancelled');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception->getMessage();
        $view = in_array($action, ['save_settings', 'add_closure', 'delete_closure'], true) ? 'settings' : 'reservations';
    }
}

$service = reservationService();
$settings = [
    'reservation_duration_minutes' => $service->setting('reservation_duration_minutes', '120'),
    'advance_days' => $service->setting('advance_days', '14'),
    'recipient_email' => $service->setting('recipient_email', ''),
    'cancellation_enabled' => $service->setting('cancellation_enabled', '1'),
    'cancellation_cutoff_minutes' => $service->setting('cancellation_cutoff_minutes', '1440'),
];
$schedules = [];
foreach ($pdo->query('SELECT * FROM weekly_schedule ORDER BY weekday')->fetchAll() as $row) {
    $blocks = $pdo->prepare('SELECT start_time, end_time FROM opening_blocks WHERE weekday = :weekday ORDER BY sort_order, start_time');
    $blocks->execute(['weekday' => $row['weekday']]);
    $row['blocks'] = $blocks->fetchAll();
    $schedules[(int) $row['weekday']] = $row;
}
$closures = $pdo->query('SELECT * FROM closures ORDER BY date_from ASC, date_to ASC')->fetchAll();
$reservations = $pdo->query(<<<'SQL'
SELECT * FROM reservations
WHERE reservation_date >= date('now', '-7 days')
ORDER BY reservation_date ASC, start_time ASC, table_id ASC
LIMIT 500
SQL)->fetchAll();
$weekdayNames = [1 => 'Montag', 2 => 'Dienstag', 3 => 'Mittwoch', 4 => 'Donnerstag', 5 => 'Freitag', 6 => 'Samstag', 7 => 'Sonntag'];
$tableLabels = [];
foreach ($config['tables'] as $table) $tableLabels[(int) $table['id']] = (string) $table['label'];
$messageLabels = [
    'saved' => 'Die Einstellungen wurden gespeichert.',
    'closure-added' => 'Der Schließzeitraum wurde hinzugefügt.',
    'closure-deleted' => 'Der Schließzeitraum wurde entfernt.',
    'cancelled' => 'Die Reservierung wurde storniert und der Tisch wieder freigegeben.',
];
$message = $messageLabels[(string) ($_GET['message'] ?? '')] ?? '';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservierungsverwaltung</title>
    <link rel="stylesheet" href="assets/admin.css?v=2">
    <script src="assets/admin.js?v=2" defer></script>
</head>
<body>
<header class="admin-header">
    <div class="admin-header__inner">
        <a class="admin-brand" href="admin.php">Reservierungsverwaltung</a>
        <nav aria-label="Verwaltung">
            <a href="live.php">Live-Plan</a>
            <a href="admin.php?view=reservations" <?= $view === 'reservations' ? 'aria-current="page"' : '' ?>>Reservierungen</a>
            <a href="admin.php?view=settings" <?= $view === 'settings' ? 'aria-current="page"' : '' ?>>Einstellungen</a>
        </nav>
        <form method="post" action="admin.php" class="logout-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="logout">
            <button type="submit">Abmelden</button>
        </form>
    </div>
</header>

<main class="admin-shell">
    <?php if ($message !== ''): ?><div class="notice notice--success" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice notice--error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <?php if ($view === 'reservations'): ?>
        <div class="section-heading">
            <div>
                <h1>Reservierungen</h1>
                <p>Bestätigte und stornierte Reservierungen ab sieben Tagen in der Vergangenheit.</p>
            </div>
            <a class="button button--secondary" href="./" target="_blank" rel="noopener">Buchungsseite öffnen</a>
        </div>

        <div class="table-scroll">
            <table class="data-table reservation-table">
                <thead><tr><th>Datum</th><th>Zeit</th><th>Tisch</th><th>Name / Kontakt</th><th>Gäste</th><th>Quelle</th><th>Benachrichtigung</th><th>Status</th><th>Aktion</th></tr></thead>
                <tbody>
                <?php if ($reservations === []): ?>
                    <tr><td colspan="9" class="empty-row">Noch keine Reservierungen vorhanden.</td></tr>
                <?php endif; ?>
                <?php foreach ($reservations as $reservation): ?>
                    <tr class="<?= $reservation['status'] === 'cancelled' ? 'is-cancelled' : '' ?>">
                        <td data-label="Datum"><?= e(date('d.m.Y', strtotime($reservation['reservation_date']))) ?></td>
                        <td data-label="Zeit"><?= e($reservation['start_time']) ?>–<?= e($reservation['end_time']) ?></td>
                        <td data-label="Tisch"><?= e($tableLabels[(int) $reservation['table_id']] ?? (string) $reservation['table_id']) ?></td>
                        <td data-label="Name / Kontakt">
                            <strong><?= e($reservation['guest_name']) ?></strong>
                            <small><?= e($reservation['phone'] !== '' ? $reservation['phone'] : $reservation['email']) ?></small>
                        </td>
                        <td data-label="Gäste"><?= (int) $reservation['party_size'] ?></td>
                        <td data-label="Quelle"><?= ($reservation['source'] ?? 'online') === 'phone' ? 'Telefon' : 'Online' ?></td>
                        <td data-label="Benachrichtigung">
                            <small>Restaurant: <span class="status status--<?= e($reservation['notification_status']) ?>"><?= e($reservation['notification_status']) ?></span></small>
                            <small>Gast: <span class="status status--<?= e($reservation['confirmation_status'] ?? 'not_applicable') ?>"><?= e($reservation['confirmation_status'] ?? 'not_applicable') ?></span></small>
                        </td>
                        <td data-label="Status"><?= $reservation['status'] === 'confirmed' ? 'Bestätigt' : 'Storniert' ?></td>
                        <td class="actions-cell" data-label="Aktion">
                            <?php if ($reservation['status'] === 'confirmed'): ?>
                                <form method="post" action="admin.php?view=reservations" data-confirm-cancel>
                                    <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="cancel_reservation">
                                    <input type="hidden" name="id" value="<?= (int) $reservation['id'] ?>">
                                    <button class="button button--danger-outline" type="submit">Reservierung stornieren</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="section-heading">
            <div>
                <h1>Reservierung – Einstellungen</h1>
                <p>Öffnungszeiten, Buchungsdauer und Benachrichtigungen pflegen.</p>
            </div>
        </div>

        <form method="post" action="admin.php?view=settings" class="settings-form">
            <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="action" value="save_settings">

            <section class="settings-section">
                <h2>Allgemein</h2>
                <div class="form-grid">
                    <label class="field">
                        <span>Reservierungsdauer</span>
                        <select name="reservation_duration_minutes">
                            <?php foreach ([60, 90, 120, 150, 180, 240] as $minutes): ?>
                                <option value="<?= $minutes ?>" <?= (int) $settings['reservation_duration_minutes'] === $minutes ? 'selected' : '' ?>><?= $minutes ?> Minuten</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Buchbar im Voraus</span>
                        <select name="advance_days">
                            <?php foreach ([7, 14, 21, 30, 60, 90] as $days): ?>
                                <option value="<?= $days ?>" <?= (int) $settings['advance_days'] === $days ? 'selected' : '' ?>><?= $days ?> Tage</option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="field cancellation-setting">
                        <span>Online-Stornierungen</span>
                        <label class="switch switch--labelled">
                            <input type="checkbox" name="cancellation_enabled" value="1" <?= $settings['cancellation_enabled'] === '1' ? 'checked' : '' ?>>
                            <span aria-hidden="true"></span><em>Stornolink in der Bestätigungsmail anbieten</em>
                        </label>
                    </div>
                    <?php
                        $cutoffMinutes = (int) $settings['cancellation_cutoff_minutes'];
                        $cutoffUsesDays = $cutoffMinutes > 0 && $cutoffMinutes % 1440 === 0;
                        $cutoffValue = $cutoffUsesDays ? intdiv($cutoffMinutes, 1440) : intdiv($cutoffMinutes, 60);
                    ?>
                    <div class="field">
                        <span>Stornierbar bis vor Beginn</span>
                        <div class="joined-fields">
                            <input type="number" name="cancellation_cutoff_value" min="0" max="8760" value="<?= $cutoffValue ?>" required>
                            <select name="cancellation_cutoff_unit">
                                <option value="hours" <?= !$cutoffUsesDays ? 'selected' : '' ?>>Stunden</option>
                                <option value="days" <?= $cutoffUsesDays ? 'selected' : '' ?>>Tage</option>
                            </select>
                        </div>
                        <small>Die bei der Buchung geltende Frist wird fest gespeichert.</small>
                    </div>
                </div>
            </section>

            <section class="settings-section">
                <h2>Öffnungszeiten</h2>
                <p class="section-copy">Jeder Tag kann aus mehreren Öffnungsblöcken bestehen, zum Beispiel mittags und abends. Die Reservierungsdauer muss vollständig in einen Block passen.</p>
                <div class="schedule-table" role="table" aria-label="Öffnungszeiten nach Wochentag">
                    <div class="schedule-row schedule-head" role="row">
                        <span>Tag</span><span>Geöffnet</span><span>Startzeiten</span><span>Intervall</span>
                    </div>
                    <?php foreach ($weekdayNames as $weekday => $name): $schedule = $schedules[$weekday]; ?>
                        <div class="schedule-row" role="row" data-schedule-day="<?= $weekday ?>">
                            <strong class="schedule-day-name"><?= e($name) ?></strong>
                            <label class="switch schedule-enabled">
                                <input type="checkbox" name="schedule[<?= $weekday ?>][enabled]" value="1" <?= (int) $schedule['enabled'] === 1 ? 'checked' : '' ?>>
                                <span aria-hidden="true"></span><em>Geöffnet</em>
                            </label>
                            <div class="opening-blocks" data-opening-blocks>
                                <?php foreach ($schedule['blocks'] as $blockIndex => $block): ?>
                                    <div class="opening-block" data-opening-block>
                                        <label><span>Von</span><input type="time" name="schedule[<?= $weekday ?>][blocks][<?= $blockIndex ?>][start]" value="<?= e($block['start_time']) ?>"></label>
                                        <span class="opening-separator" aria-hidden="true">–</span>
                                        <label><span>Bis</span><input type="time" name="schedule[<?= $weekday ?>][blocks][<?= $blockIndex ?>][end]" value="<?= e($block['end_time']) ?>"></label>
                                        <button class="icon-remove" type="button" data-remove-block aria-label="Öffnungsblock entfernen">×</button>
                                    </div>
                                <?php endforeach; ?>
                                <button class="add-block" type="button" data-add-block>+ Zeitblock hinzufügen</button>
                            </div>
                            <label class="interval-field"><span class="mobile-label">Intervall</span><select name="schedule[<?= $weekday ?>][interval]">
                                <?php foreach ([15, 30, 45, 60] as $interval): ?>
                                    <option value="<?= $interval ?>" <?= (int) $schedule['interval_minutes'] === $interval ? 'selected' : '' ?>><?= $interval ?> Min.</option>
                                <?php endforeach; ?>
                            </select></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <template id="opening-block-template">
                    <div class="opening-block" data-opening-block>
                        <label><span>Von</span><input type="time" data-field="start"></label>
                        <span class="opening-separator" aria-hidden="true">–</span>
                        <label><span>Bis</span><input type="time" data-field="end"></label>
                        <button class="icon-remove" type="button" data-remove-block aria-label="Öffnungsblock entfernen">×</button>
                    </div>
                </template>
            </section>

            <section class="settings-section">
                <h2>Benachrichtigungen</h2>
                <div class="form-grid">
                    <label class="field">
                        <span>Empfängeradresse</span>
                        <input type="email" name="recipient_email" value="<?= e($settings['recipient_email']) ?>" placeholder="reservierungen@restaurant.de">
                    </label>
                    <div class="field readonly-field">
                        <span>Absenderadresse</span>
                        <output><?= e($config['smtp']['from_email']) ?></output>
                    </div>
                </div>
                <p class="settings-note">SMTP-Zugangsdaten werden ausschließlich in der Konfigurationsdatei gepflegt. SMTP ist <?= trim((string) $config['smtp']['password']) !== '' ? 'vollständig konfiguriert' : 'noch nicht vollständig konfiguriert' ?>.</p>
            </section>

            <div class="save-row"><button class="button button--primary" type="submit">Änderungen speichern</button></div>
        </form>

        <section class="settings-section closures-section">
            <h2>Ruhetage und Ausnahmen</h2>
            <p class="section-copy">Wöchentliche Ruhetage werden oben über „Geöffnet“ festgelegt. Hier können Feiertage und Betriebsferien als Zeiträume ergänzt werden.</p>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Von</th><th>Bis</th><th>Beschreibung</th><th></th></tr></thead>
                    <tbody>
                    <?php if ($closures === []): ?><tr><td colspan="4" class="empty-row">Keine besonderen Schließtage eingetragen.</td></tr><?php endif; ?>
                    <?php foreach ($closures as $closure): ?>
                        <tr>
                            <td data-label="Von"><?= e(date('d.m.Y', strtotime($closure['date_from']))) ?></td>
                            <td data-label="Bis"><?= e(date('d.m.Y', strtotime($closure['date_to']))) ?></td>
                            <td data-label="Beschreibung"><?= e($closure['reason']) ?></td>
                            <td class="actions-cell"><form method="post" action="admin.php?view=settings">
                                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                                <input type="hidden" name="action" value="delete_closure">
                                <input type="hidden" name="id" value="<?= (int) $closure['id'] ?>">
                                <button class="text-button text-button--danger" type="submit">Entfernen</button>
                            </form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <form method="post" action="admin.php?view=settings" class="closure-form">
                <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="add_closure">
                <label class="field"><span>Von</span><input type="date" name="date_from" required></label>
                <label class="field"><span>Bis</span><input type="date" name="date_to" required></label>
                <label class="field closure-reason"><span>Beschreibung</span><input type="text" name="reason" maxlength="160" placeholder="z. B. Betriebsferien"></label>
                <button class="button button--secondary" type="submit">Ausnahme hinzufügen</button>
            </form>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
