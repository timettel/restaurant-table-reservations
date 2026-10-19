<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header("Content-Security-Policy: default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self' data:; frame-ancestors *; base-uri 'self'; form-action 'self'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$publicId = (string) ($_POST['id'] ?? $_GET['id'] ?? '');
$token = (string) ($_POST['token'] ?? $_GET['token'] ?? '');
$service = reservationService();
$reservation = null;
$error = '';
$success = false;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ((string) ($_POST['action'] ?? '') !== 'cancel') {
            throw new InvalidArgumentException('Ungültige Anfrage.');
        }
        $reservation = $service->cancelByGuest($publicId, $token);
        $success = true;

        $config = appConfig();
        $mailer = new SmtpMailer($config['smtp']);
        if ($mailer->isConfigured()) {
            try {
                $recipient = $service->setting('recipient_email');
                if ($recipient !== '') {
                    $mailer->sendCancellationNotice($reservation, $recipient);
                }
                $mailer->sendCancellationConfirmation($reservation);
            } catch (Throwable $mailError) {
                appLog('Cancellation mail failed for reservation ' . $publicId . ': ' . $mailError->getMessage());
            }
        }
    } else {
        $reservation = $service->cancellationDetails($publicId, $token);
    }
} catch (Throwable $exception) {
    $error = $exception instanceof InvalidArgumentException || $exception instanceof DomainException
        ? $exception->getMessage()
        : 'Die Reservierung konnte momentan nicht geladen werden.';
    if (!$exception instanceof InvalidArgumentException && !$exception instanceof DomainException) {
        appLog('Cancellation page error: ' . $exception->getMessage());
    }
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservierung stornieren</title>
    <link rel="stylesheet" href="assets/admin.css?v=2">
</head>
<body class="cancel-page">
<main class="cancel-shell">
    <section class="cancel-card">
        <?php if ($success && $reservation !== null): ?>
            <div class="cancel-mark cancel-mark--success" aria-hidden="true">✓</div>
            <h1>Reservierung storniert</h1>
            <p>Der Tisch ist wieder freigegeben. Sofern eine E-Mail-Adresse hinterlegt ist, wird nach Möglichkeit eine Bestätigung versendet.</p>
        <?php elseif ($error !== ''): ?>
            <div class="cancel-mark cancel-mark--error" aria-hidden="true">!</div>
            <h1>Stornierung nicht möglich</h1>
            <p><?= e($error) ?></p>
        <?php elseif ($reservation !== null): ?>
            <h1>Reservierung stornieren</h1>
            <p class="cancel-intro">Bitte prüfen Sie die Reservierung. Die Stornierung kann anschließend nicht rückgängig gemacht werden.</p>

            <dl class="reservation-summary">
                <div><dt>Name</dt><dd><?= e($reservation['name']) ?></dd></div>
                <div><dt>Datum</dt><dd><?= e($reservation['date_label']) ?></dd></div>
                <div><dt>Uhrzeit</dt><dd><?= e($reservation['start_time']) ?>–<?= e($reservation['end_time']) ?> Uhr</dd></div>
                <div><dt>Tisch</dt><dd><?= e($reservation['table_label']) ?></dd></div>
                <div><dt>Personen</dt><dd><?= (int) $reservation['party_size'] ?></dd></div>
            </dl>

            <?php if ($reservation['can_cancel']): ?>
                <p class="cancel-deadline">Online stornierbar bis <?= e($reservation['cancellation_deadline_label']) ?> Uhr.</p>
                <form method="post" action="cancel.php">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="id" value="<?= e($publicId) ?>">
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <button class="button button--danger-solid" type="submit">Reservierung jetzt stornieren</button>
                </form>
            <?php else: ?>
                <div class="notice notice--error" role="status"><?= e($reservation['reason']) ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <a class="back-link" href="./">Zur Reservierungsseite</a>
    </section>
</main>
</body>
</html>
