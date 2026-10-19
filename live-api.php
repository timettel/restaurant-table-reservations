<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$config = appConfig();
session_name('reservation_admin');
session_set_cookie_params([
    'lifetime' => 0, 'path' => $config['app']['base_path'],
    'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if (empty($_SESSION['admin_authenticated'])) jsonResponse(['ok' => false, 'error' => 'Bitte erneut anmelden.'], 401);

try {
    $action = (string) ($_GET['action'] ?? '');
    $service = liveService();
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'snapshot') {
        jsonResponse(['ok' => true, 'data' => $service->snapshot()]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'slots') {
        $date = (string) ($_GET['date'] ?? '');
        jsonResponse(['ok' => true, 'data' => ['slots' => reservationService()->slotsForDate($date)]]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'availability') {
        jsonResponse(['ok' => true, 'data' => reservationService()->availability((string) ($_GET['date'] ?? ''), (string) ($_GET['time'] ?? ''))]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['ok' => false, 'error' => 'Nicht unterstützte Anfrage.'], 404);
    $input = requestJson();
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $token)) {
        jsonResponse(['ok' => false, 'error' => 'Die Sitzung ist abgelaufen. Bitte neu laden.'], 403);
    }
    if ($action === 'occupy') {
        jsonResponse(['ok' => true, 'data' => $service->occupy($input)], 201);
    }
    if ($action === 'release') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false) throw new InvalidArgumentException('Ungültige Belegung.');
        $service->release((int) $id);
        jsonResponse(['ok' => true]);
    }
    if ($action === 'phone-reservation') {
        $sendConfirmation = ($input['send_confirmation'] ?? false) === true;
        if ($sendConfirmation && trim((string) ($input['email'] ?? '')) === '') {
            throw new InvalidArgumentException('Für eine Bestätigung per E-Mail bitte die E-Mail-Adresse angeben.');
        }
        $reservation = reservationService()->createAdminReservation($input);
        $confirmationSent = false;
        if (!$sendConfirmation) {
            reservationService()->markConfirmation($reservation['id'], 'not_applicable');
        } elseif ($reservation['email'] === '') {
            reservationService()->markConfirmation($reservation['id'], 'not_applicable');
        } else {
            $mailer = new SmtpMailer($config['smtp']);
            try {
                $cancellationUrl = rtrim((string) $config['app']['public_url'], '/') . '/cancel.php?' . http_build_query([
                    'id' => $reservation['public_id'], 'token' => $reservation['cancellation_token'],
                ]);
                $mailer->sendCustomerConfirmation($reservation, $cancellationUrl);
                reservationService()->markConfirmation($reservation['id'], 'sent');
                $confirmationSent = true;
            } catch (Throwable $mailError) {
                reservationService()->markConfirmation($reservation['id'], 'failed', $mailError->getMessage());
                appLog('Phone reservation confirmation mail failed for ' . $reservation['public_id'] . ': ' . $mailError->getMessage());
            }
        }
        unset($reservation['cancellation_token']);
        jsonResponse(['ok' => true, 'data' => $reservation + ['confirmation_sent' => $confirmationSent]], 201);
    }
    jsonResponse(['ok' => false, 'error' => 'Nicht unterstützte Anfrage.'], 404);
} catch (DomainException $exception) {
    jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 409);
} catch (InvalidArgumentException | JsonException $exception) {
    jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    appLog('Live API error: ' . $exception->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Die Anfrage konnte momentan nicht verarbeitet werden.'], 500);
}
