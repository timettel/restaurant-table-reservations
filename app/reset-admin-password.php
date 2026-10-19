#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "Fehler: {$message}\n");
    exit($code);
}

function readHidden(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    $mode = trim((string) shell_exec('stty -g 2>/dev/null'));
    if ($mode === '') {
        fail('Die verdeckte Passworteingabe benötigt ein interaktives Terminal.');
    }

    system('stty -echo');
    try {
        $line = fgets(STDIN);
    } finally {
        system('stty ' . escapeshellarg($mode));
        fwrite(STDOUT, "\n");
    }

    if ($line === false) {
        fail('Das Passwort konnte nicht gelesen werden.');
    }
    return rtrim($line, "\r\n");
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile) || !is_readable($configFile) || !is_writable($configFile)) {
    fail('config.php ist nicht les- und schreibbar. Bitte das Tool als root ausführen.');
}

$password = readHidden('Neues Verwaltungspasswort: ');
if (mb_strlen($password) < 12) {
    fail('Das Passwort muss mindestens 12 Zeichen lang sein.');
}
$confirmation = readHidden('Passwort wiederholen: ');
if (!hash_equals($password, $confirmation)) {
    fail('Die Passwörter stimmen nicht überein.');
}

$configSource = file_get_contents($configFile);
if ($configSource === false) {
    fail('config.php konnte nicht gelesen werden.');
}

$pattern = "/('admin_password_hash'\\s*=>\\s*)'[^']*'/";
if (preg_match_all($pattern, $configSource) !== 1) {
    fail('Der Eintrag admin_password_hash wurde nicht eindeutig gefunden.');
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false || !password_verify($password, $hash)) {
    fail('Der Passwort-Hash konnte nicht sicher erzeugt werden.');
}

$updatedSource = preg_replace_callback(
    $pattern,
    static fn(array $match): string => $match[1] . "'" . $hash . "'",
    $configSource,
    1,
    $replacementCount
);
if ($updatedSource === null || $replacementCount !== 1) {
    fail('config.php konnte nicht aktualisiert werden.');
}

$metadata = stat($configFile);
if ($metadata === false) {
    fail('Dateirechte von config.php konnten nicht ermittelt werden.');
}

$temporaryFile = __DIR__ . '/.config-reset-' . bin2hex(random_bytes(8));
$handle = @fopen($temporaryFile, 'x');
if ($handle === false) {
    fail('Temporäre Konfigurationsdatei konnte nicht erstellt werden.');
}

try {
    if (!flock($handle, LOCK_EX) || fwrite($handle, $updatedSource) !== strlen($updatedSource) || !fflush($handle)) {
        fail('Die neue Konfiguration konnte nicht vollständig geschrieben werden.');
    }
} finally {
    fclose($handle);
}

chmod($temporaryFile, $metadata['mode'] & 0777);
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    chown($temporaryFile, $metadata['uid']);
    chgrp($temporaryFile, $metadata['gid']);
}
if (!@rename($temporaryFile, $configFile)) {
    @unlink($temporaryFile);
    fail('Die neue Konfiguration konnte nicht aktiviert werden.');
}

unset($password, $confirmation, $configSource, $updatedSource, $hash);
fwrite(STDOUT, "Verwaltungspasswort wurde erfolgreich geändert.\n");
