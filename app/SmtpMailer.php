<?php

declare(strict_types=1);

final class SmtpMailer
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return trim((string) ($this->config['password'] ?? '')) !== '';
    }

    public function sendReservation(array $reservation, string $recipient): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Keine gültige Empfängeradresse konfiguriert.');
        }

        $subject = sprintf('Neue Tischreservierung: %s, %s Uhr, Tisch %s', $reservation['date_label'], $reservation['start_time'], $reservation['table_label']);
        $body = implode("\r\n", [
            'Eine neue Tischreservierung wurde eingetragen.',
            '',
            'Name: ' . $reservation['name'],
            'Datum: ' . $reservation['date_label'],
            'Uhrzeit: ' . $reservation['start_time'] . '–' . $reservation['end_time'] . ' Uhr',
            'Tisch: ' . $reservation['table_label'],
            'Personen: ' . $reservation['party_size'],
            'Telefon: ' . ($reservation['phone'] !== '' ? $reservation['phone'] : '–'),
            'E-Mail: ' . ($reservation['email'] !== '' ? $reservation['email'] : '–'),
            '',
            'Reservierungs-ID: ' . $reservation['public_id'],
        ]);
        $this->sendPlainText($recipient, $subject, $body, $reservation['public_id'] . '-new');
    }

    public function sendCustomerConfirmation(array $reservation, string $cancellationUrl): void
    {
        if (!filter_var($reservation['email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Keine gültige E-Mail-Adresse des Gastes vorhanden.');
        }
        $subject = sprintf('Ihre Tischreservierung am %s um %s Uhr', $reservation['date_label'], $reservation['start_time']);
        $lines = [
            'Guten Tag ' . $reservation['name'] . ',',
            '',
            'Ihre Tischreservierung wurde bestätigt.',
            '',
            'Datum: ' . $reservation['date_label'],
            'Uhrzeit: ' . $reservation['start_time'] . '–' . $reservation['end_time'] . ' Uhr',
            'Tisch: ' . $reservation['table_label'],
            'Personen: ' . $reservation['party_size'],
        ];
        if ($reservation['cancellation_deadline'] !== '') {
            $deadline = new DateTimeImmutable($reservation['cancellation_deadline']);
            $lines = array_merge($lines, [
                '',
                'Online-Stornierung möglich bis: ' . $deadline->format('d.m.Y, H:i') . ' Uhr',
                'Stornolink: ' . $cancellationUrl,
                '',
                'Nach Ablauf dieser Frist wenden Sie sich bitte direkt an das Restaurant.',
            ]);
        }
        $lines[] = '';
        $lines[] = 'Reservierungs-ID: ' . $reservation['public_id'];
        $this->sendPlainText((string) $reservation['email'], $subject, implode("\r\n", $lines), $reservation['public_id'] . '-confirmation');
    }

    public function sendCancellationNotice(array $reservation, string $recipient): void
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Keine gültige Empfängeradresse konfiguriert.');
        }
        $subject = sprintf('Tischreservierung storniert: %s, %s Uhr, Tisch %s', $reservation['date_label'], $reservation['start_time'], $reservation['table_label']);
        $body = implode("\r\n", [
            'Ein Gast hat seine Reservierung über den Stornolink storniert.',
            '',
            'Name: ' . $reservation['name'],
            'Datum: ' . $reservation['date_label'],
            'Uhrzeit: ' . $reservation['start_time'] . '–' . $reservation['end_time'] . ' Uhr',
            'Tisch: ' . $reservation['table_label'],
            'Personen: ' . $reservation['party_size'],
            '',
            'Reservierungs-ID: ' . $reservation['public_id'],
        ]);
        $this->sendPlainText($recipient, $subject, $body, $reservation['public_id'] . '-cancelled');
    }

    public function sendCancellationConfirmation(array $reservation): void
    {
        if (!filter_var($reservation['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $subject = sprintf('Stornierung Ihrer Tischreservierung am %s', $reservation['date_label']);
        $body = implode("\r\n", [
            'Guten Tag ' . $reservation['name'] . ',',
            '',
            'Ihre Tischreservierung wurde erfolgreich storniert.',
            '',
            'Datum: ' . $reservation['date_label'],
            'Uhrzeit: ' . $reservation['start_time'] . '–' . $reservation['end_time'] . ' Uhr',
            'Tisch: ' . $reservation['table_label'],
            'Personen: ' . $reservation['party_size'],
            '',
            'Reservierungs-ID: ' . $reservation['public_id'],
        ]);
        $this->sendPlainText((string) $reservation['email'], $subject, $body, $reservation['public_id'] . '-cancel-confirmation');
    }

    private function sendPlainText(string $recipient, string $subject, string $body, string $messageId): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('SMTP-Passwort ist noch nicht konfiguriert.');
        }

        $host = (string) $this->config['host'];
        $port = (int) $this->config['port'];
        $timeout = (int) ($this->config['timeout'] ?? 12);
        $transport = ($this->config['encryption'] ?? '') === 'ssl' ? 'tls' : 'tcp';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);

        $stream = @stream_socket_client(
            sprintf('%s://%s:%d', $transport, $host, $port),
            $errorNumber,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf('SMTP-Verbindung fehlgeschlagen (%d): %s', $errorNumber, $errorMessage));
        }
        stream_set_timeout($stream, $timeout);

        try {
            $this->expect($stream, [220]);
            $hostname = gethostname() ?: 'localhost';
            $this->command($stream, 'EHLO ' . $hostname, [250]);

            if (($this->config['encryption'] ?? '') === 'starttls') {
                $this->command($stream, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP-TLS konnte nicht aktiviert werden.');
                }
                $this->command($stream, 'EHLO ' . $hostname, [250]);
            }

            $this->command($stream, 'AUTH LOGIN', [334]);
            $this->command($stream, base64_encode((string) $this->config['username']), [334]);
            $this->command($stream, base64_encode((string) $this->config['password']), [235]);

            $from = (string) $this->config['from_email'];
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Ungültige SMTP-Absenderadresse konfiguriert.');
            }
            $messageDomain = substr(strrchr($from, '@'), 1);
            $this->command($stream, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($stream, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($stream, 'DATA', [354]);

            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $this->encodedHeader((string) $this->config['from_name']) . ' <' . $from . '>',
                'To: <' . $recipient . '>',
                'Subject: ' . $this->encodedHeader($subject),
                'Message-ID: <' . $messageId . '@' . $messageDomain . '>',
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
            ];
            $message = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
            $message = preg_replace('/^\./m', '..', $message) ?? $message;
            fwrite($stream, $message . "\r\n.\r\n");
            $this->expect($stream, [250]);
            $this->command($stream, 'QUIT', [221]);
        } finally {
            fclose($stream);
        }
    }

    private function command($stream, string $command, array $expectedCodes): string
    {
        if (fwrite($stream, $command . "\r\n") === false) {
            throw new RuntimeException('SMTP-Kommando konnte nicht gesendet werden.');
        }
        return $this->expect($stream, $expectedCodes);
    }

    private function expect($stream, array $expectedCodes): string
    {
        $response = '';
        $code = 0;
        while (($line = fgets($stream, 8192)) !== false) {
            $response .= $line;
            if (preg_match('/^(\d{3})([ -])/', $line, $matches)) {
                $code = (int) $matches[1];
                if ($matches[2] === ' ') {
                    break;
                }
            }
        }
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException(sprintf('Unerwartete SMTP-Antwort %d: %s', $code, trim($response)));
        }
        return $response;
    }

    private function encodedHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
