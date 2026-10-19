<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors *; base-uri 'self'; form-action 'self'");
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tisch reservieren</title>
    <link rel="stylesheet" href="assets/app.css?v=2">
    <script src="assets/app.js?v=2" defer></script>
</head>
<body>
<main class="booking-shell" id="booking-app" aria-busy="true">
    <header class="page-heading">
        <div>
            <h1>Tisch reservieren</h1>
            <p>Datum und Uhrzeit wählen, freien Tisch anklicken und Reservierung abschließen.</p>
        </div>
        <a class="admin-link" href="admin.php" aria-label="Zur Verwaltung">Verwaltung</a>
    </header>

    <div class="app-message app-message--error" id="global-error" role="alert" hidden></div>

    <div class="booking-layout">
        <aside class="date-panel" aria-labelledby="date-heading">
            <h2 id="date-heading">Datum wählen</h2>
            <div class="date-list" id="date-list" role="list"></div>
        </aside>

        <section class="booking-main" aria-labelledby="time-heading">
            <div class="time-row">
                <div>
                    <h2 id="time-heading">Uhrzeit wählen</h2>
                    <div class="time-list" id="time-list" aria-label="Verfügbare Startzeiten"></div>
                </div>
                <div class="legend" aria-label="Legende">
                    <span><i class="legend-dot legend-dot--free"></i>Frei</span>
                    <span><i class="legend-dot legend-dot--occupied"></i>Belegt</span>
                    <span><i class="legend-dot legend-dot--selected"></i>Ausgewählt</span>
                </div>
            </div>

            <div class="room-wrap">
                <div class="room-plan" id="room-plan" aria-label="Schematischer Raumplan">
                    <span class="room-label room-label--windows">Fenster</span>
                    <span class="room-label room-label--entrance">Eingang</span>
                    <div class="service-zone" aria-hidden="true"><span>Küche<br><small>Service</small></span></div>
                    <div id="tables-layer"></div>
                </div>
            </div>

            <div class="selection-status" id="selection-status" aria-live="polite">
                Bitte zuerst Datum und Uhrzeit auswählen.
            </div>
        </section>
    </div>
</main>

<dialog class="reservation-dialog" id="reservation-dialog" aria-labelledby="dialog-title">
    <form id="reservation-form" method="dialog" novalidate>
        <div class="dialog-heading">
            <div>
                <h2 id="dialog-title">Tisch reservieren</h2>
                <p id="dialog-summary"></p>
            </div>
            <button class="icon-button" type="button" id="dialog-close" aria-label="Dialog schließen">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <div class="dialog-error" id="dialog-error" role="alert" hidden></div>

        <label class="field field--wide">
            <span>Name der Reservierung</span>
            <input type="text" name="name" autocomplete="name" maxlength="120" required>
        </label>

        <div class="field-grid">
            <label class="field">
                <span>Telefon</span>
                <input type="tel" name="phone" autocomplete="tel" maxlength="40" placeholder="z. B. 0176 12345678">
            </label>
            <label class="field">
                <span>E-Mail-Adresse</span>
                <input type="email" name="email" autocomplete="email" maxlength="160" placeholder="z. B. name@example.de">
            </label>
        </div>
        <p class="contact-help" id="contact-help">Telefon und E-Mail sind freiwillig. Eine Angabe hilft dem Restaurant bei Rückfragen; per E-Mail gibt es eine Bestätigung und einen Stornolink.</p>

        <div class="field guest-field">
            <span>Anzahl der Gäste</span>
            <div class="guest-stepper" aria-describedby="capacity-note">
                <button type="button" id="guest-minus" aria-label="Einen Gast weniger">−</button>
                <output id="guest-count" aria-live="polite">2</output>
                <button type="button" id="guest-plus" aria-label="Einen Gast mehr">+</button>
            </div>
            <small id="capacity-note"></small>
        </div>

        <div class="captcha-box">
            <div class="captcha-box__heading">
                <strong>Sicherheitsfrage *</strong>
                <button type="button" id="captcha-refresh">Neue Frage</button>
            </div>
            <label class="field" for="captcha-answer">
                <span id="captcha-question">Frage wird geladen …</span>
                <input type="text" id="captcha-answer" name="captcha_answer" inputmode="numeric" pattern="[0-9]+" maxlength="2" autocomplete="off" required>
            </label>
            <small>Zum Schutz vor automatisierten Reservierungen. Keine Verbindung zu einem externen Dienst.</small>
        </div>

        <label class="honeypot" aria-hidden="true">
            Webseite
            <input type="text" name="website" tabindex="-1" autocomplete="off">
        </label>

        <div class="dialog-actions">
            <button class="button button--secondary" type="button" id="dialog-cancel">Abbrechen</button>
            <button class="button button--primary" type="submit" id="submit-reservation">Verbindlich reservieren</button>
        </div>
        <p class="privacy-note">Freiwillig angegebene Kontaktdaten werden ausschließlich für diese Reservierung verwendet.</p>
    </form>

    <section class="success-state" id="success-state" hidden>
        <div class="success-mark" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M5 12.5l4.2 4.2L19 7"/></svg>
        </div>
        <h2>Reservierung bestätigt</h2>
        <p id="success-message"></p>
        <button class="button button--primary" type="button" id="success-close">Schließen</button>
    </section>
</dialog>

<noscript>Für die Tischreservierung muss JavaScript aktiviert sein.</noscript>
</body>
</html>
