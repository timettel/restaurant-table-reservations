# Tischreservierung

Kleine, iframe-fähige Tischreservierung für Restaurants mit PHP, JavaScript und SQLite. Das Projekt enthält einen schematischen Raumplan; Tische, Plätze und Positionen lassen sich in der Konfiguration anpassen. Die Oberfläche ist deutschsprachig.

## Funktionen

- Gäste wählen Datum, Startzeit, Tisch und Personenzahl. Nur der Name ist Pflicht; Telefon und E-Mail sind freiwillig.
- Überlappende Buchungen werden serverseitig verhindert. Eine konfigurierbare Reservierungsdauer blockiert den Tisch.
- Die Verwaltung bietet mehrere Öffnungsblöcke pro Wochentag, Ruhetage, Betriebsferien, Buchungshorizont, Stornierungsfrist und eine Reservierungsliste mit manueller Stornierung.
- Eine Live-Ansicht für das Restaurant zeigt aktuelle und kommende Reservierungen. Dort sind auch telefonische Reservierungen und temporäre Tischbelegungen möglich. Sie aktualisiert sich alle fünf Minuten.
- SMTP benachrichtigt das Restaurant. Gäste mit E-Mail-Adresse erhalten eine Bestätigung und, falls aktiviert, einen signierten und zeitlich begrenzten Stornolink.
- Eine lokale Rechenfrage, einmalig nutzbare 15-Minuten-Tokens und ein SQLite-basiertes Rate-Limit schützen die öffentliche Buchung ohne externen CAPTCHA-Dienst.

## Voraussetzungen

PHP 8.1 oder neuer mit `pdo_sqlite`, `mbstring` und `openssl`; Apache 2.4 mit `mod_rewrite`, `mod_headers` und erlaubten `.htaccess`-Dateien (`AllowOverride All`); HTTPS; ein für den Webserver beschreibbares `var/`-Verzeichnis. Ein SMTP-Konto ist nur für den E-Mail-Versand erforderlich. Die Anwendung verwendet keine Composer- oder npm-Abhängigkeiten.

## Installation

1. Repository in einen eigenen URL-Pfad auschecken, beispielsweise `/reservation`. Die mitgelieferte `var/reservations.seed.sqlite` enthält das Schema und Beispiel-Öffnungszeiten, aber **keine Reservierungen oder Gastdaten**.
2. `app/config.example.php` nach `app/config.php` kopieren und `var/reservations.seed.sqlite` nach `var/reservations.sqlite` kopieren. Beide Zieldateien sind von Git ausgeschlossen.
3. In `app/config.php` mindestens `app.base_path`, `app.public_url`, `app.secret` und die Beispiel-Tische anpassen. SMTP ist optional, falls E-Mails versendet werden sollen. `public_url` muss die öffentliche HTTPS-Adresse *ohne abschließenden Slash* sein; sie wird für Stornolinks verwendet. Ein Geheimnis kann mit `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` erzeugt werden. Die Datei enthält Zugangsdaten und darf nicht ins Repository gelangen.
4. Das Verwaltungspasswort interaktiv mit `php app/reset-admin-password.php` setzen. Das Tool schreibt einen Passwort-Hash in `app/config.php`; das Passwort erscheint nicht in der Kommandozeile.
5. `var/` und die Laufzeit-DB für den Webserver beschreibbar machen, `app/config.php` nur für notwendige Benutzer lesbar halten. Apache muss die mitgelieferten `.htaccess`-Regeln anwenden: direkter HTTP-Zugriff auf `app/` und `var/` ist verboten. Bei anderen Webservern sind gleichwertige Regeln zwingend nötig.
6. `index.php` als Gast und `admin.php` als Verwaltung über HTTPS aufrufen. In der Verwaltung Öffnungszeiten und gegebenenfalls Empfängeradresse eintragen und eine Testbuchung prüfen. Ohne SMTP werden Buchungen gespeichert, aber keine Benachrichtigungen versendet.

Beispiel für das Kopieren nach dem Checkout:

```sh
cp app/config.example.php app/config.php
cp var/reservations.seed.sqlite var/reservations.sqlite
php app/reset-admin-password.php
```

`app/config.php` wird ausschließlich auf der Shell gepflegt, nicht in der Verwaltungsoberfläche. Insbesondere SMTP-Zugangsdaten und der Schlüssel für Stornotokens bleiben dort. Die Einstellungen aus `defaults` werden nur beim ersten Anlegen der Datenbank übernommen; spätere Änderungen an Öffnungszeiten und Empfängeradresse erfolgen über die Verwaltung und liegen in SQLite. Den Raumplan bearbeitet man im Array `tables` der Konfigurationsdatei (`id`, `label`, `capacity`, `shape`, `x`, `y`, `w`, `h`; Maße und Koordinaten in Prozent).

## Betrieb und Sicherheit

Die öffentliche Buchungsseite darf in fremde Seiten eingebettet werden. Die Verwaltungs- und Live-Seiten sind nur nach Anmeldung erreichbar und sind nicht für fremde Frames freigegeben. Die Anwendung setzt sichere Session-Cookies voraus und funktioniert für die Verwaltung daher nur über HTTPS.

`var/reservations.sqlite` enthält personenbezogene Daten und gehört in ein Backup-Konzept. SQLite verwendet hier bewusst den Journalmodus `DELETE`, da die ursprüngliche Installation auf NFS lag. Ein lokales Dateisystem ist für neue Installationen vorzuziehen; bei höherer Last oder mehreren Webservern ist eine Server-Datenbank sinnvoller. `var/app.log` kann Fehler und Reservierungskennungen enthalten und sollte ebenfalls geschützt und rotiert werden.

Die öffentliche Buchungs-API besitzt ein kurzlebiges Anfrage-Token, ein Honeypot-Feld und eine lokale Rechenfrage. Eine Frage kann nur einmal erfolgreich verwendet werden und läuft nach 15 Minuten ab. Pro IP sind standardmäßig höchstens 20 Buchungsversuche in 15 Minuten und 8 erfolgreiche Buchungen pro Stunde erlaubt; die Limits können in `app/config.php` angepasst werden. Die Datenbank speichert dafür nur einen HMAC der IP, nicht die IP selbst. Ohne E-Mail-Adresse gibt es keine Gastbestätigung und keinen Online-Stornolink; dann muss eine Stornierung über das Restaurant erfolgen.

Eine einfache Rechenfrage ist **kein Schutz gegen gezielte, CAPTCHA-lösende Bots**. Bei größerem öffentlichen Einsatz empfiehlt sich zusätzlich ein Rate-Limit am Webserver oder Reverse Proxy. Hinter einem Reverse Proxy muss `REMOTE_ADDR` die tatsächliche Client-IP enthalten, sonst teilen sich alle Gäste ein Limit. Der Admin-Login hat nur eine kurze Verzögerung bei Fehlversuchen; auch dort sollte ein vorgeschaltetes Rate-Limit oder ein zusätzlicher Zugangsschutz eingerichtet werden.

## Entwicklung

Alle PHP-Dateien lassen sich mit `php -l` prüfen. Es gibt derzeit keine automatisierte Testsuite. Änderungen an `app/Database.php` sollten sowohl mit einer frisch angelegten Datenbank als auch mit einer Kopie einer bestehenden Datenbank geprüft werden, da die Anwendung beim Start Schemaerweiterungen ausführt.
