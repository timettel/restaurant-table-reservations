<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header("Content-Security-Policy: default-src 'none'; frame-ancestors *");
header('X-Content-Type-Options: nosniff');

try {
    $action = (string) ($_GET['action'] ?? '');
    $service = reservationService();

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'bootstrap') {
        jsonResponse(['ok' => true, 'data' => $service->publicBootstrap()]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'slots') {
        $date = (string) ($_GET['date'] ?? '');
        jsonResponse(['ok' => true, 'data' => ['date' => $date, 'slots' => $service->slotsForDate($date)]]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'availability') {
        jsonResponse([
            'ok' => true,
            'data' => $service->availability((string) ($_GET['date'] ?? ''), (string) ($_GET['time'] ?? '')),
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reserve') {
        $reservation = $service->createReservation(requestJson(), (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $recipient = $service->setting('recipient_email');
        $mailer = new SmtpMailer(appConfig()['smtp']);
        $notificationSent = false;
        $confirmationSent = false;

        if ($recipient === '') {
            $service->markNotification($reservation['id'], 'not_configured', 'Keine Empfängeradresse konfiguriert.');
        } elseif (!$mailer->isConfigured()) {
            $service->markNotification($reservation['id'], 'not_configured', 'SMTP-Passwort ist noch nicht konfiguriert.');
        } else {
            try {
                $mailer->sendReservation($reservation, $recipient);
                $service->markNotification($reservation['id'], 'sent');
                $notificationSent = true;
            } catch (Throwable $mailError) {
                $service->markNotification($reservation['id'], 'failed', $mailError->getMessage());
                appLog('Mail delivery failed for reservation ' . $reservation['public_id'] . ': ' . $mailError->getMessage());
            }
        }

        if ($reservation['email'] !== '' && $mailer->isConfigured()) {
            $cancellationUrl = rtrim((string) appConfig()['app']['public_url'], '/') . '/cancel.php?' . http_build_query([
                'id' => $reservation['public_id'],
                'token' => $reservation['cancellation_token'],
            ]);
            try {
                $mailer->sendCustomerConfirmation($reservation, $cancellationUrl);
                $service->markConfirmation($reservation['id'], 'sent');
                $confirmationSent = true;
            } catch (Throwable $mailError) {
                $service->markConfirmation($reservation['id'], 'failed', $mailError->getMessage());
                appLog('Confirmation mail failed for reservation ' . $reservation['public_id'] . ': ' . $mailError->getMessage());
            }
        } elseif ($reservation['email'] !== '') {
            $service->markConfirmation($reservation['id'], 'not_configured', 'SMTP-Passwort ist noch nicht konfiguriert.');
        }

        unset($reservation['cancellation_token']);
        jsonResponse(['ok' => true, 'data' => $reservation + [
            'notification_sent' => $notificationSent,
            'confirmation_sent' => $confirmationSent,
        ]], 201);
    }

    jsonResponse(['ok' => false, 'error' => 'Nicht unterstützte Anfrage.'], 404);
} catch (DomainException $exception) {
    jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 409);
} catch (InvalidArgumentException | JsonException $exception) {
    jsonResponse(['ok' => false, 'error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    appLog('API error: ' . $exception->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Die Anfrage konnte momentan nicht verarbeitet werden.'], 500);
}
