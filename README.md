# WebRTC Remote-Guidance & Location Platform

Diese Web-Applikation ist ein interaktives **Remote-Guidance-System**. Es ermöglicht Guides, Standorte für Führungen anzubieten, bei denen der Zuschauer die Regie übernimmt. Über eine Peer-to-Peer-Verbindung steuert der Zuschauer den Guide vor Ort in Echtzeit über ein Steuerkreuz.

---

## 💡 Das Konzept
* **Interaktive Steuerung:** Der Zuschauer navigiert den Guide über ein Steuerkreuz. Die Befehle laufen über einen eigenen WebRTC-Datenkanal, getrennt vom Chat, als versioniertes JSON-Protokoll mit Rollen, Bestätigung und Sperre — vollständig beschrieben in [`PROTOKOLL.md`](PROTOKOLL.md).
* **Geo-Präsenz:** Guides hinterlegen Standorte in der Datenbank, die für User sichtbar sind.
* **Echtzeit-Kommunikation:** P2P-Video/Audio mit minimaler Latenz.

---

## 🚀 Key Features & Sicherheit
* **WebRTC Signalling:** PHP-basiertes Handshake-System zum Austausch von SDP-Daten und ICE-Kandidaten.
* **NAT Traversal:** Integration von **TURN-Servern** (Metered.ca) für stabile Verbindungen.
* **High-Security:** * Passwort-Hashing mit individuellem **Pepper**.
    * **Zwei-Faktor-Authentifizierung (2FA/TOTP)** inklusive QR-Code-Generierung.
    * E-Mail-Verifizierung (`email_verified`) und Passwort-Reset via SMTP.
* **Rollen- und Rechtesystem:** Vier Rollen (Trial, User, Guide, Admin) mit **benannten Rechten ohne Vererbung und ohne Rangfolge**. Jede Route in `config/routes.php` trägt ihr Recht als Pflichtfeld; `index.php` prüft es, bevor der Controller läuft. Details unten unter [Berechtigungen](#-berechtigungen). Im laufenden Call vergibt der Server zusätzlich die Rolle Guide bzw. Zuschauer — der Client kann sie sich nicht selbst geben.

---

## ⚙️ Installation & Konfiguration

### 1. Voraussetzungen
* **PHP 8.x** mit aktivierter **GD-Extension** (in der `php.ini` bei `extension=gd` das Semikolon entfernen).
* **Composer** für das Abhängigkeitsmanagement.
* **HTTPS** (erforderlich für den Kamerazugriff).

### 2. Composer Setup
Installiere die benötigten Libraries:
```bash
composer install
```

### 1. Datenbank
Importiere die mitgelieferte `database.sql` in deine MySQL-Instanz. Diese erstellt alle notwendigen Tabellen wie `user`, `location`, `rtc_signal` und `usertype`.

**Bestehende Installationen** brauchen zusätzlich die Migrationen aus `migrations/`, der Reihe nach:

```bash
mariadb -u <user> -p <datenbank> < migrations/005_rollen_neu_nummeriert.sql
mariadb -u <user> -p <datenbank> < migrations/006_location_sperre.sql
```

`005` vergibt die Rollennummern neu (siehe unten), `006` ergänzt die Spalten für die Standortsperre. Beide sind idempotent und löschen nichts. Nach `005` müssen sich alle Nutzer neu anmelden — die Anwendung verwirft alte Sitzungen von selbst, weil sie sonst die falsche Rolle trügen.

### 2. Umgebungsvariablen (`.env`)
Erstelle eine `.env`-Datei im Root-Verzeichnis und hinterlege deine Zugangsdaten:

```
.env
# Datenbank-Zugang
DB_HOST=
DB_PORT=
DB_USER=
DB_PW=
DB_NAME=

# Sicherheit
APP_ENV=
PEPPER=dein_geheimer_pepper_string

# WebRTC TURN-Server (Metered.ca)
METERED_API_KEY=dein_api_key
METERED_APP_NAME=dein_api_name

# WebRTC STUN-Server (optional, kommagetrennt)
# Leer lassen fuer die eingebauten oeffentlichen Server. Diese Liste wird
# immer zusaetzlich zu den TURN-Zugangsdaten ausgeliefert, damit der Ausfall
# eines einzelnen Servers die Verbindung nicht verhindert.
STUN_SERVERS=

# E-Mail (SMTP)
SMTP_SERVER=dein.smtp-server.com
SMTP_PORT=587
SMTP_USERNAME=dein_login
SMTP_PASSWORD=dein_passwort
```

### 3. Logging und Logrotation

Das PHP-Fehlerlog liegt **ausserhalb des Document Root**, damit es nicht ueber
HTTP abrufbar ist. Den Pfad bestimmt `config/log_path.php`:

* Ist die Umgebungsvariable `LOG_PATH` gesetzt, gilt dieser Pfad.
* Sonst greift der Fallback `../logs/php-error.log` — also eine Ebene
  oberhalb des Webroots. Das Verzeichnis wird beim ersten Schreiben
  automatisch mit den Rechten `0750` angelegt.

`LOG_PATH` muss auf Server- oder Systemebene gesetzt werden, **nicht in der
`.env`** — die wird erst nach dem Fehler-Handler geladen:

```
Apache : SetEnv LOG_PATH /var/log/webrtc/php-error.log
nginx  : fastcgi_param LOG_PATH /var/log/webrtc/php-error.log;
Docker : environment: LOG_PATH=/var/log/webrtc/php-error.log
```

**Altlast:** Frühere Versionen schrieben nach `<Webroot>/php-error.log`.
Diese Datei kann noch existieren, ueber HTTP erreichbar sein und Secrets aus
alten Versionen enthalten. Sie wird nicht automatisch geloescht — bitte
manuell entfernen:

```
rm <Webroot>/php-error.log
```

#### Logrotation einrichten

Eine fertige Konfiguration liegt unter `deploy/logrotate/webrtc-app`. Sie wird
**nicht automatisch installiert**. Zur Einrichtung:

1. Datei oeffnen und **zwei Werte anpassen**: den Logpfad in der ersten Zeile
   und den Webserver-Benutzer (`www-data`, unter RHEL/CentOS `apache`).
2. Nach `/etc/logrotate.d/` kopieren:
   ```
   sudo cp deploy/logrotate/webrtc-app /etc/logrotate.d/webrtc-app
   sudo chown root:root /etc/logrotate.d/webrtc-app
   sudo chmod 644 /etc/logrotate.d/webrtc-app
   ```
3. Konfiguration testen, ohne etwas zu rotieren:
   ```
   sudo logrotate -d /etc/logrotate.d/webrtc-app
   ```

Voreinstellung: woechentliche Rotation, acht Generationen, komprimiert.
Ein Neustart von PHP-FPM oder Apache ist nach der Rotation nicht noetig.

### 4. Cronjob fuer die Online-Erkennung (**Pflicht**)

Ohne diesen Cronjob ist die Anwendung nicht sinnvoll benutzbar.

Der Browser meldet alle 10 Sekunden `index.php?act=heartbeat` und setzt den
Nutzer damit auf `online` bzw. `in_call`. Auf `offline` setzt ihn nur zweierlei:
das ausdrueckliche Abmelden ueber den Logout-Button und der Cronjob
`cron/check_online_status.php`. Ein geschlossener Tab, ein abgestuerzter Browser
oder ein Netzausfall melden sich nicht ab.

**Laeuft der Cronjob nicht, bleibt jeder jemals eingeloggte Nutzer dauerhaft
`online`.** Die Standortuebersicht zeigt dann lauter gruene Punkte und
anwaehlbare Call-Buttons fuer Guides, die niemand erreicht.

Der Cronjob setzt alle Nutzer offline, deren letzter Heartbeat laenger als 45
Sekunden zurueckliegt (`config/presence.php`). Er braucht dieselbe `.env` und
dasselbe `vendor/`-Verzeichnis wie die Web-Anwendung, aber keinen Webserver.

#### Linux / macOS

`crontab -e` oeffnen und eine Zeile ergaenzen — Pfade anpassen, den PHP-Pfad
liefert `which php`:

```
* * * * * /usr/bin/php /var/www/webrtc_proj/cron/check_online_status.php
```

Cron kennt als kleinsten Takt eine Minute. Ein verwaister Nutzer verschwindet
damit fruehestens nach 45 und spaetestens nach rund 105 Sekunden aus der Liste.
Wer das halbieren will, nimmt zwei Zeilen:

```
* * * * * /usr/bin/php /var/www/webrtc_proj/cron/check_online_status.php
* * * * * sleep 30; /usr/bin/php /var/www/webrtc_proj/cron/check_online_status.php
```

#### Windows (lokales Testen)

Variante A — Aufgabenplanung, laeuft dauerhaft im Hintergrund (Pfade an die
eigene XAMPP-Installation anpassen):

```
schtasks /Create /TN "WebRTC Online-Status" /SC MINUTE /MO 1 ^
  /TR "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\webrtc_proj\cron\check_online_status.php\""
```

Wieder entfernen:

```
schtasks /Delete /TN "WebRTC Online-Status" /F
```

Variante B — PowerShell-Fenster, das nur waehrend der Entwicklung offen bleibt.
Das ist der Weg mit dem kuerzesten Takt und ohne Rechte am System:

```powershell
while ($true) {
    & "C:\xampp\php\php.exe" "C:\xampp\htdocs\webrtc_proj\cron\check_online_status.php"
    Start-Sleep -Seconds 30
}
```

#### Pruefen, ob es wirkt

Das Skript einmal von Hand starten — es gibt im Erfolgsfall nichts aus, Fehler
landen im Log aus Abschnitt 3:

```
php cron/check_online_status.php
```

Danach in der Datenbank nachsehen. Ein Nutzer, der laenger als 45 Sekunden
keinen Heartbeat geschickt hat, muss `offline` stehen:

```sql
SELECT id, username, user_status, updated_at FROM user ORDER BY updated_at DESC;
```

#### Taktung anpassen

Heartbeat-Takt und Offline-Timeout stehen zusammen in `config/presence.php`
(Vorgabe: 10 s Takt, 45 s Timeout). Der Browser liest den Takt von dort ueber
`window.heartbeatIntervalMs`, der Cronjob den Timeout — die beiden Werte koennen
also nicht auseinanderlaufen.

Das Verhaeltnis ist bewusst grosszuegig: Der Timeout vertraegt vier
ausgefallene Heartbeats in Folge. Wird er zu knapp gewaehlt (frueher: 15 s Takt
gegen 20 s Timeout), setzt schon eine einzelne verzoegerte Antwort einen
aktiven Guide offline. Als Faustregel sollte der Timeout mindestens das
Dreifache des Takts betragen.

**Bekannte Grenze:** Browser bremsen Timer in ausgeblendeten Tabs auf etwa
einen Aufruf pro Minute aus. Ein Guide, der die Seite nur im
Hintergrund-Tab offen haelt, kann dadurch zwischenzeitlich als offline
erscheinen; sobald der Tab wieder sichtbar wird, meldet sich der Client
sofort zurueck. Waehrend eines laufenden Calls tritt das nicht auf. Wer
Guides dauerhaft im Hintergrund erreichbar halten will, erhoeht den
`offline_timeout` auf mindestens 90 Sekunden.

---

## 🔐 Berechtigungen

### Rollen

| `usertype.id` | Rolle | Bedeutung |
|---|---|---|
| 0 | Trial | frisch registriert |
| 1 | User | Zuschauer |
| 2 | Guide | bietet Standorte an |
| 10 | Admin | Benutzerverwaltung und Moderation |

Die Nummern sind **Etiketten, keine Rangfolge**: Eine höhere Nummer bedeutet nicht "darf mehr". Die Lücke zwischen 2 und 10 ist Platz für weitere Rollen, die nicht gleich Admin sein sollen. Eine neue Rolle braucht genau zwei Einträge — einen in `usertype` und einen in `class/Helper/Permission.php`.

### Rechte

Geprüft wird nie eine Rolle, sondern immer ein **benanntes Recht** (`user.delete`, `location.block`, `chat.read`, …). Die vollständige Zuordnung steht in `class/Helper/Permission.php`; jede Rolle führt ihre Rechte selbst auf, es gibt **keine Vererbung**. Auch "nicht angemeldet" ist dort eine Rolle (`Permission::GUEST`) mit einer ausgeschriebenen Liste.

### Durchsetzung

Jeder Eintrag in `config/routes.php` hat vier Pflichtangaben:

```php
'delete_user' => [UserController::class, 'deleteUser', Permission::USER_DELETE, 'html'],
```

`index.php` prüft **die gesamte Tabelle** bei jedem Aufruf. Fehlt bei einer Route das Recht oder ist es unbekannt, antwortet die Anwendung gar nicht mehr, bis der Eintrag stimmt — eine Route ohne definiertes Recht ist ein Konfigurationsfehler, kein offener Zugang. Erst danach wird das Recht des Aufrufers geprüft: Seiten leiten zur Anmeldung, Schnittstellen antworten mit 401 bzw. 403 als JSON.

Was eine Rechtetabelle nicht wissen kann, prüfen weiterhin die Controller **und die Datenbankabfrage**: Standorte ändern und löschen tragen `AND user_id = :user_id` in der WHERE-Klausel, Chatnachrichten setzen die Beteiligung am Chat voraus.

### Moderation

Ein Admin **löscht keine fremden Standorte, er sperrt sie** (Recht `location.block`). Der gesperrte Standort verschwindet aus der Übersicht der anderen Nutzer, bleibt aber beim Guide bestehen — in seiner eigenen Standortliste sieht er die Sperre samt Grund. Gelöscht wird nur vom Eigentümer.

---

## 🧪 Tests

Zwei Pruefskripte fuer die Verbindungsstabilitaet und das Steuerprotokoll der WebRTC-Funktion:

```bash
node tests/client_test.js     # Client-Logik (assets/js)
php  tests/server_test.php    # Serverlogik (class/)
```

Ohne Test-Framework, ohne Datenbank, ohne Netzwerk - beide sind gefahrlos
jederzeit ausfuehrbar. Details in [`tests/README.md`](tests/README.md).

---

## 👤 Autor
**Dominik Kusber** *Angehender Fachinformatiker für Anwendungsentwicklung* [GitHub Profile](https://github.com/dominik8891) | [Portfolio/Kontakt](mailto:deine@email.de)

---
*Dieses Projekt entstand im Rahmen eines Praktikums zur Dokumentation der Kompetenzen in PHP, WebRTC und relationalem Datenbankdesign.*

---

## ⚠️ Hinweis zur Projekthistorie
Da die ursprünglichen Datenbank-Dumps des Projekts (Stand vor 6 Monaten) nicht mehr verfügbar waren, wurde das SQL-Schema für diese Version auf Basis der bestehenden Models und Logik rekonstruiert. 

Aufgrund dieser nachträglichen Erstellung können Abweichungen zwischen der ursprünglichen Testumgebung und dem aktuellen Schema bestehen. Die aktuelle `database.sql` wurde jedoch nach bestem Wissen auf die aktuelle Code-Basis (PHP-Models) optimiert.

---
