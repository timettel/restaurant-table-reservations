<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ReservationService.php';
require_once __DIR__ . '/LiveService.php';
require_once __DIR__ . '/SmtpMailer.php';

function appConfig(): array
{
    static $config;
    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        date_default_timezone_set($config['app']['timezone']);
    }
    return $config;
}

function db(): PDO
{
    static $pdo;
    if ($pdo === null) {
        $pdo = Database::connect(appConfig());
    }
    return $pdo;
}

function reservationService(): ReservationService
{
    static $service;
    if ($service === null) {
        $service = new ReservationService(db(), appConfig());
    }
    return $service;
}

function liveService(): LiveService
{
    static $service;
    if ($service === null) $service = new LiveService(db(), appConfig(), reservationService());
    return $service;
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function requestJson(): array
{
    $body = file_get_contents('php://input');
    if ($body === false || $body === '') {
        return [];
    }
    $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new InvalidArgumentException('Ungültige Anfrage.');
    }
    return $decoded;
}

function appLog(string $message): void
{
    $line = sprintf("[%s] %s\n", date(DATE_ATOM), str_replace(["\r", "\n"], ' ', $message));
    error_log($line, 3, dirname(__DIR__) . '/var/app.log');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
