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
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');

if (empty($_SESSION['admin_authenticated'])) {
    header('Location: admin.php');
    exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
db();
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e((string) $_SESSION['csrf']) ?>">
    <title>Live-Plan – Reservierungsverwaltung</title>
    <link rel="stylesheet" href="assets/admin.css?v=3">
    <link rel="stylesheet" href="assets/live.css?v=1">
    <script src="assets/live.js?v=1" defer></script>
</head>
<body>
<header class="admin-header">
    <div class="admin-header__inner">
        <a class="admin-brand" href="live.php">Reservierungsverwaltung</a>
        <nav aria-label="Verwaltung">
            <a href="live.php" aria-current="page">Live-Plan</a>
            <a href="admin.php?view=reservations">Reservierungen</a>
            <a href="admin.php?view=settings">Einstellungen</a>
        </nav>
        <form method="post" action="admin.php" class="logout-form">
            <input type="hidden" name="csrf" value="<?= e((string) $_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="logout">
            <button type="submit">Abmelden</button>
        </form>
    </div>
</header>

<main class="live-shell" id="live-app" aria-busy="true">
    <div class="live-heading">
        <h1>Live-Plan</h1>
        <span id="live-clock" class="live-clock"></span>
        <span class="freshness"><i aria-hidden="true"></i><span id="freshness-text">Wird geladen …</span></span>
        <button type="button" class="button button--primary phone-open" id="phone-open">Telefonische Reservierung</button>
    </div>
    <div class="notice notice--error" id="live-error" role="alert" hidden></div>
    <div class="live-layout">
        <section class="floor-section" aria-label="Tischplan">
            <div class="live-legend" aria-label="Legende">
                <span><i class="legend-dot legend-dot--free"></i>Frei</span>
                <span><i class="legend-dot legend-dot--soon"></i>Bald reserviert</span>
                <span><i class="legend-dot legend-dot--reserved"></i>Reserviert</span>
                <span><i class="legend-dot legend-dot--manual"></i>Manuell belegt</span>
            </div>
            <div class="floor-scroll">
                <div class="live-floor" aria-label="Schematischer Raumplan">
                    <span class="floor-wall floor-wall--top" aria-hidden="true"></span>
                    <span class="floor-wall floor-wall--left" aria-hidden="true"></span>
                    <span class="floor-wall floor-wall--right" aria-hidden="true"></span>
                    <span class="floor-wall floor-wall--bottom-left" aria-hidden="true"></span>
                    <span class="floor-wall floor-wall--bottom-right" aria-hidden="true"></span>
                    <span class="floor-entrance">Eingang</span>
                    <div id="live-tables"></div>
                </div>
            </div>
        </section>

        <aside class="upcoming" aria-labelledby="upcoming-title">
            <h2 id="upcoming-title">Nächste Reservierungen</h2>
            <div id="upcoming-list" class="upcoming-list"></div>
        </aside>
    </div>

    <section class="selected-panel" id="selected-panel" aria-live="polite">
        <p>Tippen Sie auf einen Tisch, um Details und Aktionen zu sehen.</p>
    </section>
    <footer class="live-footer">
        <span id="refresh-countdown">Automatische Aktualisierung in 5:00</span>
        <button type="button" id="refresh-now">Jetzt aktualisieren</button>
    </footer>
</main>

<dialog class="live-dialog" id="occupy-dialog" aria-labelledby="occupy-title">
    <form id="occupy-form" novalidate>
        <div class="dialog-head"><h2 id="occupy-title">Tisch manuell belegen</h2><button type="button" class="close-dialog" data-close-dialog aria-label="Schließen">×</button></div>
        <p id="occupy-summary" class="dialog-summary"></p>
        <div class="notice notice--error dialog-error" id="occupy-error" role="alert" hidden></div>
        <div class="live-form-grid">
            <label class="field"><span>Personen *</span><input type="number" name="party_size" min="1" required></label>
            <label class="field"><span>Dauer *</span><select name="duration_minutes" required>
                <option value="30">30 Minuten</option><option value="60">60 Minuten</option><option value="90">90 Minuten</option>
                <option value="120" selected>120 Minuten</option><option value="150">150 Minuten</option><option value="180">180 Minuten</option><option value="240">240 Minuten</option>
            </select></label>
        </div>
        <label class="field"><span>Notiz (optional)</span><input type="text" name="note" maxlength="160" placeholder="z. B. Laufkundschaft"></label>
        <p class="dialog-info" id="occupy-next"></p>
        <div class="dialog-actions"><button type="button" class="button button--secondary" data-close-dialog>Abbrechen</button><button type="submit" class="button button--primary">Tisch belegen</button></div>
    </form>
</dialog>

<dialog class="live-dialog live-dialog--phone" id="phone-dialog" aria-labelledby="phone-title">
    <form id="phone-form" novalidate>
        <div class="dialog-head"><h2 id="phone-title">Telefonische Reservierung</h2><button type="button" class="close-dialog" data-close-dialog aria-label="Schließen">×</button></div>
        <div class="notice notice--error dialog-error" id="phone-error" role="alert" hidden></div>
        <div class="live-form-grid">
            <label class="field"><span>Datum *</span><select name="date" required id="phone-date"></select></label>
            <label class="field"><span>Uhrzeit *</span><select name="time" required id="phone-time"></select></label>
        </div>
        <div class="live-form-grid">
            <label class="field"><span>Tisch *</span><select name="table_id" required id="phone-table"></select></label>
            <label class="field"><span>Personen *</span><input type="number" name="party_size" id="phone-party" min="1" required></label>
        </div>
        <p class="dialog-info" id="phone-availability"></p>
        <label class="field"><span>Name *</span><input type="text" name="name" maxlength="120" autocomplete="name" required></label>
        <div class="live-form-grid">
            <label class="field"><span>Telefon</span><input type="tel" name="phone" maxlength="40" autocomplete="tel"></label>
            <label class="field"><span>E-Mail</span><input type="email" name="email" maxlength="160" autocomplete="email"></label>
        </div>
        <p class="contact-hint">Mindestens Telefon oder E-Mail angeben.</p>
        <label class="confirm-choice"><input type="checkbox" name="send_confirmation"> Bestätigung per E-Mail senden</label>
        <div class="dialog-actions"><button type="button" class="button button--secondary" data-close-dialog>Abbrechen</button><button type="submit" class="button button--primary">Reservierung eintragen</button></div>
    </form>
</dialog>

<div class="live-toast" id="live-toast" role="status" hidden></div>
</body>
</html>
