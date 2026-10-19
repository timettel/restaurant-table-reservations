# Tischreservierung

Kleine, iframe-fähige Tischreservierung für Restaurants mit PHP, JavaScript und SQLite. Das Projekt enthält einen schematischen Raumplan; Tische, Plätze und Positionen lassen sich in der Konfiguration anpassen. Die Oberfläche ist deutschsprachig.

## Funktionen

- Gäste wählen Datum, Startzeit, Tisch und Personenzahl. Name und mindestens Telefonnummer oder E-Mail-Adresse sind Pflicht.
- Überlappende Buchungen werden serverseitig verhindert. Eine konfigurierbare Reservierungsdauer blockiert den Tisch.
- Die Verwaltung bietet mehrere Öffnungsblöcke pro Wochentag, Ruhetage, Betriebsferien, Buchungshorizont, Stornierungsfrist und eine Reservierungsliste mit manueller Stornierung.
- Eine Live-Ansicht für das Restaurant zeigt aktuelle und kommende Reservierungen. Dort sind auch telefonische Reservierungen und temporäre Tischbelegungen möglich. Sie aktualisiert sich alle fünf Minuten.
- SMTP benachrichtigt das Restaurant. Gäste mit E-Mail-Adresse erhalten eine Bestätigung und, falls aktiviert, einen signierten und zeitlich begrenzten Stornolink.

## Voraussetzungen

PHP 8.1 oder neuer mit `pdo_sqlite`, `mbstring` und `openssl`; Apache 2.4 mit `mod_rewrite`, `mod_headers` und erlaubten `.htaccess`-Dateien (`AllowOverride All`); HTTPS; ein für den Webserver beschreibbares `var/`-Verzeichnis. Ein SMTP-Konto ist für den E-Mail-Versand erforderlich. Die Anwendung verwendet keine Composer- oder npm-Abhängigkeiten.

## Installation

1. Repository in einen eigenen URL-Pfad auschecken, beispielsweise `/reservation`. Die mitgelieferte `var/reservations.seed.sqlite` enthält das Schema und Beispiel-Öffnungszeiten, aber **keine Reservierungen oder Gastdaten**.
2. `app/config.example.php` nach `app/config.php` kopieren und `var/reservations.seed.sqlite` nach `var/reservations.sqlite` kopieren. Beide Zieldateien sind von Git ausgeschlossen.
3. In `app/config.php` mindestens `app.base_path`, `app.public_url`, `app.secret`, SMTP-Einstellungen und die Beispiel-Tische anpassen. `public_url` muss die öffentliche HTTPS-Adresse *ohne abschließenden Slash* sein; sie wird für Stornolinks verwendet. Ein Geheimnis kann mit `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'` erzeugt werden. Die Datei enthält Zugangsdaten und darf nicht ins Repository gelangen.
4. Das Verwaltungspasswort interaktiv mit `php app/reset-admin-password.php` setzen. Das Tool schreibt einen Passwort-Hash in `app/config.php`; das Passwort erscheint nicht in der Kommandozeile.
5. `var/` und die Laufzeit-DB für den Webserver beschreibbar machen, `app/config.php` nur für notwendige Benutzer lesbar halten. Apache muss die mitgelieferten `.htaccess`-Regeln anwenden: direkter HTTP-Zugriff auf `app/` und `var/` ist verboten. Bei anderen Webservern sind gleichwertige Regeln zwingend nötig.
6. `index.php` als Gast und `admin.php` als Verwaltung über HTTPS aufrufen. In der Verwaltung die Empfängeradresse und Öffnungszeiten eintragen und eine Testbuchung inklusive E-Mail-Versand prüfen.

Beispiel für das Kopieren nach dem Checkout:

```sh
cp app/config.example.php app/config.php
cp var/reservations.seed.sqlite var/reservations.sqlite
php app/reset-admin-password.php
```

`app/config.php` wird ausschließlich auf der Shell gepflegt, nicht in der Verwaltungsoberfläche. Insbesondere SMTP-Zugangsdaten und der Schlüssel für Stornotokens bleiben dort. Die Einstellungen aus `defaults` werden nur beim ersten Anlegen der Datenbank übernommen; spätere Änderungen an Öffnungszeiten und Empfängeradresse erfolgen über die Verwaltung und liegen in SQLite. Den Raumplan bearbeitet man im Array `tables` der Konfigurationsdatei (`id`, `label`, `capacity`, `shape`, `x`, `y`; Koordinaten in Prozent).

## Betrieb und Sicherheit

Die öffentliche Buchungsseite darf in fremde Seiten eingebettet werden. Die Verwaltungs- und Live-Seiten sind nur nach Anmeldung erreichbar und sind nicht für fremde Frames freigegeben. Die Anwendung setzt sichere Session-Cookies voraus und funktioniert für die Verwaltung daher nur über HTTPS.

`var/reservations.sqlite` enthält personenbezogene Daten und gehört in ein Backup-Konzept. SQLite verwendet hier bewusst den Journalmodus `DELETE`, da die ursprüngliche Installation auf NFS lag. Ein lokales Dateisystem ist für neue Installationen vorzuziehen; bei höherer Last oder mehreren Webservern ist eine Server-Datenbank sinnvoller. `var/app.log` kann Fehler und Reservierungskennungen enthalten und sollte ebenfalls geschützt und rotiert werden.

Die öffentliche Buchungs-API besitzt ein kurzlebiges Anfrage-Token und ein Honeypot-Feld, aber **keine vollwertige Bot- oder IP-Ratenbegrenzung**. Vor einem breit beworbenen öffentlichen Einsatz empfiehlt sich zusätzlich ein Rate-Limit am Reverse Proxy bzw. Webserver. Der Admin-Login hat nur eine kurze Verzögerung bei Fehlversuchen; auch dort sollte ein vorgeschaltetes Rate-Limit oder ein zusätzlicher Zugangsschutz eingerichtet werden.

## Entwicklung

Alle PHP-Dateien lassen sich mit `php -l` prüfen. Es gibt derzeit keine automatisierte Testsuite. Änderungen an `app/Database.php` sollten sowohl mit einer frisch angelegten Datenbank als auch mit einer Kopie einer bestehenden Datenbank geprüft werden, da die Anwendung beim Start Schemaerweiterungen ausführt.
