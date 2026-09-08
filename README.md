# WebRTC Remote-Guidance & Location Platform

Diese Web-Applikation ist ein interaktives **Remote-Guidance-System**. Es ermöglicht Guides, Standorte für Führungen anzubieten, bei denen der Zuschauer die Regie übernimmt. Über eine Peer-to-Peer-Verbindung steuert der Zuschauer den Guide vor Ort in Echtzeit über ein Steuerkreuz.

---

## 💡 Das Konzept
* **Interaktive Steuerung:** Der Zuschauer navigiert den Guide über ein Steuerkreuz (vorwärts, zurück, links, rechts) und ein Tastenpaar für die Blickrichtung. Beim Guide löst jede Anweisung ein Tonsignal in seiner Sprache und eine bildschirmfüllende Anzeige aus — gesteuert wird über Tasten und Töne, nicht über Sprache, damit die Anwendung weltweit funktioniert. Die Befehle laufen über einen eigenen WebRTC-Datenkanal, getrennt vom Chat, als versioniertes JSON-Protokoll mit Rollen, Bestätigung und Sperre — vollständig beschrieben in [`PROTOKOLL.md`](PROTOKOLL.md).
* **Geo-Präsenz:** Guides hinterlegen Standorte in der Datenbank, die für User sichtbar sind. Jeder Standort hat eine **eigene, teilbare Seite** mit Bildern, Titel, ausführlicher Beschreibung, Dauer, Sprachen, den **üblichen Zeiten samt Zeitzone des Ortes** und Karte — von dort aus beginnt die Führung, und dort bearbeitet der Guide sein Angebot (siehe [Der Standort und seine Seite](#-der-standort-und-seine-seite)).
* **Anfrage statt Anruf:** Eine Führung beginnt mit einer **Anfrage samt Wunschzeitpunkt**, die der Guide annimmt oder ablehnt — „jetzt sofort" ist dabei ein Zeitpunkt unter anderen und kein Sonderfall. Erst nach der Zusage wird angerufen. Damit müssen nicht mehr beide Seiten zufällig im selben Moment können (siehe [Die Anfrage](#-die-anfrage-statt-des-anrufs)).
* **Echtzeit-Kommunikation:** P2P-Video/Audio mit minimaler Latenz.

---

## 🚀 Key Features & Sicherheit
* **WebRTC Signalling:** PHP-basiertes Handshake-System zum Austausch von SDP-Daten und ICE-Kandidaten.
* **NAT Traversal:** Integration von **TURN-Servern** (Metered.ca) für stabile Verbindungen.
* **High-Security:** * Passwort-Hashing mit individuellem **Pepper**.
    * **Zwei-Faktor-Authentifizierung (2FA/TOTP)** inklusive QR-Code-Generierung.
    * E-Mail-Verifizierung (`email_verified`) und Passwort-Reset via SMTP —
      beides ueber zwei getrennte Schalter in der `.env` steuerbar
      (`MAIL_ENABLED`, `MAIL_VERIFY_REQUIRED`), statt im Code auskommentiert.
    * **Sicherheitskopfzeilen** auf jeder Antwort — CSP, Rahmenschutz,
      `nosniff`, Referrer- und Permissions-Policy. Der Rahmenschutz zählt hier
      besonders: Die Seite fragt Kamera und Mikrofon ab, und in einem fremden
      `iframe` stünde die Freigabeabfrage über einer fremden Seite. Details
      unter [Sicherheitskopfzeilen](#5-sicherheitskopfzeilen).
    * **HTTPS erzwungen** samt `secure`-Cookie, HSTS opt-in, und ein
      Backup-Skript für Datenbank und Uploads (`deploy/backup/backup.sh`).
* **Anfrage, Führung, Bewertung:** Am Anfang steht eine Anfrage mit Wunschzeitpunkt, die der Guide annimmt oder ablehnt; **beendet** wird die Führung ausdrücklich vom Guide (bis dahin können beide nach einem Verbindungsabbruch wieder einsteigen), und danach wird der Kunde gefragt, wie sie war — Sterne plus freiwilliger Text, **nur in diese Richtung**. Ein Durchschnitt erscheint erst ab drei Bewertungen; darunter steht die Zahl der durchgeführten Führungen statt einer Zahl, die wie ein Urteil aussieht. Details unter [Bewertungen](#-bewertungen).
* **Eigener Verwaltungsbereich:** Konten, Anfragen, Standorte und Bewertungen liegen hinter einer eigenen Route mit eigener Navigation — dicht und tabellarisch, aber im selben Erscheinungsbild und mit denselben Farbprofilen. Die Kundenoberfläche enthält dafür **keinen einzigen Adminfall mehr**: keine Sperrknöpfe in der Standortliste, kein *Entfernen* an einer Bewertung, kein Menüeintrag, den nur einer sieht. Der Einstieg ist eine Übersicht, die **zuerst zeigt, was Aufmerksamkeit braucht** — hängende Führungen, Anfragen ohne Antwort, gesperrte Standorte — und darunter erst den Bestand. Details unter [Der Verwaltungsbereich](#️-der-verwaltungsbereich).
* **Rollen- und Rechtesystem:** Vier Rollen (Trial, User, Guide, Admin) mit **benannten Rechten ohne Vererbung und ohne Rangfolge**. Jede Route in `config/routes.php` trägt ihr Recht als Pflichtfeld; `index.php` prüft es, bevor der Controller läuft. Details unten unter [Berechtigungen](#-berechtigungen). Im laufenden Call vergibt der Server zusätzlich die Rolle Guide, Zuschauer oder — bei einem Direktanruf aus der Benutzerverwaltung — Peer; der Client kann sie sich nicht selbst geben. Entscheidend ist, woher der Anruf kam: Von einem Standort aus führt der Angerufene, auch wenn er Admin ist, und der Zuschauer sendet dabei weder Bild noch Ton. Bei einem Direktanruf mit einem Admin gibt es nichts zu steuern, dort läuft die Übertragung in beide Richtungen.

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
mariadb -u <user> -p <datenbank> < migrations/007_guide_rolle.sql
mariadb -u <user> -p <datenbank> < migrations/008_farbprofil.sql
mariadb -u <user> -p <datenbank> < migrations/009_call_standort.sql
mariadb -u <user> -p <datenbank> < migrations/010_verfuegbarkeit.sql
mariadb -u <user> -p <datenbank> < migrations/011_standort_inhalt.sql
mariadb -u <user> -p <datenbank> < migrations/012_titelbild.sql
mariadb -u <user> -p <datenbank> < migrations/013_anfragen.sql
mariadb -u <user> -p <datenbank> < migrations/014_verfuegbarkeitszeiten.sql
mariadb -u <user> -p <datenbank> < migrations/015_guide_profil.sql
mariadb -u <user> -p <datenbank> < migrations/016_bewertungen.sql
mariadb -u <user> -p <datenbank> < migrations/017_fuehrung_beenden.sql
mariadb -u <user> -p <datenbank> < migrations/018_bremse.sql
mariadb -u <user> -p <datenbank> < migrations/019_standort_chat.sql
```

`005` vergibt die Rollennummern neu (siehe unten), `006` ergänzt die Spalten für die Standortsperre, `007` legt die Tabelle `guide_profile` an und trägt die vorhandenen Guides darin nach, `008` speichert das Farbprofil je Konto, `009` merkt sich am Signal, von welchem Standort ein Anruf ausging — daran hängt die Rollenvergabe im Call, `010` ergänzt `user.available_until` und trennt damit "angemeldet" von "bereit" (siehe [Verfügbarkeit](#-verfügbarkeit-angemeldet-ist-nicht-bereit)), `011` gibt dem Standort Titel, ausführliche Beschreibung, Dauer und Sprachen und legt die Tabelle `location_image` an, `012` trennt Titelbild und Beispielbilder über die Spalte `location_image.role` und wählt in jedem vorhandenen Standort das erste Bild zum Titelbild (siehe [Der Standort und seine Seite](#-der-standort-und-seine-seite)), `013` legt die Tabelle `tour_request` an — die Anfrage und zugleich der erste Datensatz über stattgefundene Führungen (siehe [Die Anfrage](#-die-anfrage-statt-des-anrufs)), `014` gibt dem Standort seine **üblichen Zeiten** und seine **Zeitzone** (siehe [Übliche Zeiten](#übliche-zeiten-und-die-zeitzone-des-ortes)), `015` macht aus der Zustimmungszeile ein **Profil** — Anzeigename, Selbstbeschreibung, Sprachen, Bild (siehe [Der Guide als Mensch](#-der-guide-als-mensch)), `016` legt die Tabelle `tour_review` an — die **Bewertung einer Führung** (siehe [Bewertungen](#-bewertungen)), `017` ergänzt `tour_request.closed_at`: Der Guide **beendet die Führung ausdrücklich**, statt dass das Auflegen sie abschließt (siehe [Auflegen ist nicht beenden](#auflegen-ist-nicht-beenden)), `018` legt die Tabelle `rate_limit` an — die **serverseitigen Versuchszähler**, die vorher in der Session des Aufrufers lagen, `019` gibt dem Chat seine **Herkunft** (`chat.location_id`) und nimmt ihm die **Einladung** (siehe [Der Chat](#-der-chat-über-einen-standort)). Alle sind idempotent.

**Nach `011` braucht die Anwendung ein Ablageverzeichnis für Bilder**, sonst lässt sich kein Bild hochladen; alles andere läuft unverändert weiter. Siehe [Bilder](#bilder-ablage-formate-größen).

**Nach `013` beginnt die Aufzeichnung bei null.** Vergangene Führungen sind nirgends festgehalten und lassen sich nicht nachtragen — es gab dafür keinen Datensatz, und genau deshalb gibt es die Tabelle.

**`019` löscht als einzige etwas** — die Spalten `chat.is_active` und `chat.pending_for`. Jede vorhandene Zeile wird dadurch zu einem gewöhnlichen Chat: Eine Einladung, die noch offen war, ist ab dann ein offenes Gespräch, und der Angeschriebene sieht sie im Zähler der Kopfleiste statt in einem Fenster mit zwei Knöpfen. Nachrichten gehen keine verloren.

**Nach `017` gilt keine bestehende Führung als offen.** Alle vorhandenen Zeilen bekommen `closed_at = NULL`; was schon auf `done` steht, bleibt beendet und bleibt bewertbar. Ein Nachtragen des Abschlusszeitpunkts gäbe es nicht — er wurde nie erfasst, und ein erfundener wäre schlechter als keiner.

**Nach `016` ist alles unbewertet, und das bleibt eine Weile so.** Nachträglich lässt sich nichts eintragen — gefragt wird der Kunde nach dem Auflegen, und bei vergangenen Führungen ist das vorbei. Bewertbar sind sie trotzdem: Jede durchgeführte Führung steht beim Kunden auf der Anfragenseite, solange sie unbewertet ist. Bis drei Bewertungen zusammenkommen, steht bei einem Guide **kein Durchschnitt**, sondern die Zahl seiner Führungen — siehe [Bewertungen](#-bewertungen).

**Nach `014` ist bei jedem Standort „keine Angabe" eingetragen** — kein Guide
hat bisher Zeiten hinterlegt, und erfunden wird nichts. Auf der Standortseite
steht dann nichts dazu, und eine Anfrage ist zu jedem Zeitpunkt möglich, wie
bisher.

**Nach `010` steht kein Guide mehr auf bereit.** Das ist Absicht: Die Bereitschaft ist eine Entscheidung, und die hat vorher niemand getroffen. Jeder Guide legt den Schalter in der Kopfleiste um, sobald er die Seite das nächste Mal öffnet. Nach `005` müssen sich alle Nutzer neu anmelden — die Anwendung verwirft alte Sitzungen von selbst, weil sie sonst die falsche Rolle trügen.

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

# Oeffentliche Adresse der Installation, ohne Schraegstrich am Ende.
# Daraus baut der Server die Links fuer Passwort-Reset und
# E-Mail-Bestaetigung. Ohne diesen Wert wird keine solche Mail verschickt.
# Bewusst aus der Konfiguration und nicht aus dem Host-Header der Anfrage:
# Sonst liesse sich der Reset-Link auf einen fremden Server umbiegen.
APP_BASE_URL=https://example.org/rctproj

# WebRTC TURN-Server (Metered.ca)
METERED_API_KEY=dein_api_key
METERED_APP_NAME=dein_api_name

# WebRTC STUN-Server (optional, kommagetrennt)
# Leer lassen fuer die eingebauten oeffentlichen Server. Diese Liste wird
# immer zusaetzlich zu den TURN-Zugangsdaten ausgeliefert, damit der Ausfall
# eines einzelnen Servers die Verbindung nicht verhindert.
STUN_SERVERS=

# E-Mail: die beiden Schalter (Vorgaben: Versand an, Pflicht aus)
# MAIL_ENABLED=0          verschickt nicht, schreibt die Mail samt Link ins Log
# MAIL_VERIFY_REQUIRED=0  ohne bestaetigte Adresse kein Anfragen/Chatten/Hochladen
# Getrennt, weil der Versand an muss, bevor die Pflicht an darf - siehe
# Abschnitt "Zwei Schalter fuer die E-Mail".
MAIL_ENABLED=0
MAIL_VERIFY_REQUIRED=0

# E-Mail (SMTP) - nur noetig, wenn MAIL_ENABLED an ist
SMTP_SERVER=dein.smtp-server.com
SMTP_PORT=587
SMTP_USERNAME=dein_login
SMTP_PASSWORD=dein_passwort

# HTTPS: Weiterleitung und Sitzungscookie (Vorgabe an - siehe Abschnitt 6).
# 0 nur fuer die lokale Entwicklung ohne Zertifikat.
FORCE_HTTPS=1
# Nur an, wenn ein Reverse-Proxy davorsteht, der X-Forwarded-Proto setzt.
#TRUST_PROXY=0

# HSTS in Sekunden, 0 = kein Header (Vorgabe). Erst 300 zum Testen, dann
# 31536000 - ein gesendetes max-age laesst sich nicht zurueckrufen.
HSTS_MAX_AGE=0
#HSTS_INCLUDE_SUBDOMAINS=0

# Content-Security-Policy: melden (Vorgabe) | scharf | aus - Abschnitt 5.
CSP_MODE=melden

# Backups (nur fuer deploy/backup/backup.sh) - Abschnitt 7.
#BACKUP_PATH=/var/backups/webrtc
#BACKUP_KEEP_DAYS=14
```

Die vollstaendige Fassung mit Begruendung zu jedem Schluessel steht in
[`.env.example`](.env.example).

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

**Der Heartbeat sagt "angemeldet", nicht "verfuegbar".** Ob ein Guide gerade
fuehren will, entscheidet allein sein Bereitschaftsschalter — siehe
[Verfügbarkeit](#-verfügbarkeit-angemeldet-ist-nicht-bereit). Gruen auf der
Karte steht ein Standort nur, wenn BEIDES zutrifft.

**Laeuft der Cronjob nicht, bleibt jeder jemals eingeloggte Nutzer dauerhaft
`online`.** Die Standortuebersicht zeigt dann Guides als erreichbar an, die
niemand mehr erreicht. Anrufbar werden sie dadurch nicht — dafuer braucht es
zusaetzlich eine laufende Bereitschaft —, aber die Anzeige stimmt nicht mehr.

Der Cronjob setzt alle Nutzer offline, deren letzter Heartbeat laenger als 45
Sekunden zurueckliegt (`config/presence.php`). Er braucht dieselbe `.env` und
dasselbe `vendor/`-Verzeichnis wie die Web-Anwendung, aber keinen Webserver.

Derselbe Lauf **raeumt abgelaufene Anfragen auf** und schliesst Fuehrungen ab,
deren Ende nie angekommen ist (`config/requests.php`). Auch das ist Aufraeumen
und keine Pruefung: Ob eine Anfrage noch gilt, entscheidet der Vergleich mit
`NOW()` in jeder einzelnen Abfrage — ohne den Cronjob laeuft alles genauso, die
Tabelle sammelt dann nur Karteileichen.

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
SELECT id, username, user_status, available_until, updated_at
FROM user ORDER BY updated_at DESC;
```

`user_status` ist die Erreichbarkeit, `available_until` die Bereitschaft. Ein
Guide steht genau dann grün auf der Karte, wenn `user_status` `online` lautet
**und** `available_until` in der Zukunft liegt. Anrufbar ist er darüber hinaus
für jeden, dem er eine Anfrage zugesagt hat — die Zusage gilt auch ohne
Bereitschaft, aber nur in ihrem Zeitfenster (siehe
[Die Anfrage](#-die-anfrage-statt-des-anrufs)).

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

### 5. Sicherheitskopfzeilen

Gesetzt werden sie an **einer** Stelle: `App\Helper\SecurityHeaders::senden()`,
gerufen in `index.php` ganz am Anfang. Weil `index.php` der einzige Einstieg
ist, tragen damit alle Antworten dieselben Kopfzeilen — die Seiten, die
JSON-Schnittstellen und die Fehlerseiten aus `deny()`.

| Kopfzeile | Wert | Wogegen |
|---|---|---|
| `X-Frame-Options` | `DENY` | Clickjacking |
| `X-Content-Type-Options` | `nosniff` | Dateityp-Raten des Browsers |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | Adressen, die nach draußen wandern |
| `Permissions-Policy` | siehe unten | Geräte, die die Seite nicht braucht |
| `Content-Security-Policy` | siehe unten | eingeschleuste und nachgeladene Skripte |
| `Strict-Transport-Security` | nur wenn `HSTS_MAX_AGE` gesetzt ist | Abschnitt 6 |

**Der Rahmenschutz ist hier kein Nebenpunkt.** Die Anwendung fragt Kamera und
Mikrofon ab. Stünde sie in einem fremden `iframe`, sähe der Benutzer die
Freigabeabfrage seines Browsers über einer fremden Seite — und gäbe seine
Kamera an etwas frei, das er für etwas anderes hält. Deshalb `DENY` und nicht
`SAMEORIGIN`: Die Anwendung baut selbst keinen einzigen `iframe`. Der Schutz
steht doppelt da (`X-Frame-Options` **und** `frame-ancestors 'none'` in der
CSP), und `X-Frame-Options` bewusst außerhalb der CSP — es wirkt damit auch,
solange die CSP nur meldet.

**Permissions-Policy.** Kamera, Mikrofon, Standort und Tonwiedergabe bleiben
für das eigene Dokument erlaubt (`camera=(self)`, `microphone=(self)`,
`geolocation=(self)`, `autoplay=(self)`) — ohne sie gibt es keine Führung.
Abgeschaltet wird, was die Anwendung nachweislich nicht benutzt:
`display-capture` (kein `getDisplayMedia` im ganzen Projekt), `payment`, `usb`,
`serial`, `midi` und die drei Lagesensoren.

#### Die CSP-Regel

```
default-src 'self';
script-src  'self' 'unsafe-inline' https://ajax.googleapis.com https://unpkg.com
                                   https://cdn.jsdelivr.net https://cdn.datatables.net;
style-src   'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net
                                   https://cdn.datatables.net;
img-src     'self' data: https://*.tile.openstreetmap.org;
font-src    'self';
media-src   'self' blob:;
connect-src 'self' stun: turn: turns:;
object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'
```

Vier Zeilen daran verdienen eine Erklärung:

* **Die vier CDNs** sind ausgezählt aus `assets/html/index.html` — jQuery von
  googleapis, Leaflet und leaflet-pip von unpkg, select2 und Bootstrap von
  jsdelivr, DataTables von datatables.net. Wer dort eine Bibliothek ergänzt,
  trägt sie in `SecurityHeaders::CDN_SKRIPTE` bzw. `CDN_STILE` ein, sonst
  blockiert der Browser sie. Der saubere Weg wäre, die Bibliotheken
  mitzuliefern und nur noch `'self'` zu erlauben — das ist ein Umbau und
  bewusst nicht Teil dieser Regel.
* **`'unsafe-inline'` bei den Skripten** ist der Ist-Zustand, keine
  Bequemlichkeit: Die Anwendung setzt acht Skriptblöcke direkt ins Dokument
  (Farbprofil vor dem ersten Zeichnen, `window.userId`,
  `heartbeatIntervalMs`, `requestCounts`, `chatCounts`, `reviewScale`, die
  Daten der Standortseite, `window.requestsPage`) und trägt ein
  `onclick`-Attribut in `UserController`. **Gegen eingeschleustes Inline-JS
  schützt die Regel damit nicht** — sie schützt gegen das Nachladen von einer
  fremden Adresse, den häufigeren Fall. Der Weg zur strengen Regel wäre ein
  Nonce je Anfrage; das ist eine Codeänderung an acht Stellen, und das
  `onclick` müsste einem Event-Listener weichen.
* **`connect-src` nennt `stun:`, `turn:` und `turns:`**, weil **Chrome die
  ICE-Server einer `RTCPeerConnection` gegen `connect-src` prüft**. Ohne diese
  drei käme über ein fremdes Netz keine Verbindung zustande, und der Fehler
  sähe aus wie ein Netzproblem. Einzelne Adressen stehen dort nicht: Welcher
  TURN-Host antwortet, holt der Server zur Laufzeit bei Metered ab — eine
  Liste hier wäre eine Liste, die irgendwann nicht mehr stimmt.
* **`img-src data:`** brauchen die Symbole in `assets/css/theme.css`; die
  liegen als SVG in den CSS-Variablen und nicht als Datei.

#### Von „melden" auf „scharf"

`CSP_MODE` in der `.env` hat drei Zustände; die **Vorgabe ist `melden`**:

| Wert | Kopfzeile | Wirkung |
|---|---|---|
| `melden` | `Content-Security-Policy-Report-Only` | blockiert nichts, meldet in die Browserkonsole |
| `scharf` | `Content-Security-Policy` | blockiert |
| `aus` | keine | der Notausgang |

Eine zu enge CSP legt die Anwendung **lautlos** lahm: Der Browser blockiert,
die Seite bleibt halb leer, im Serverlog steht nichts. Deshalb läuft die Regel
erst mit, bevor sie greift. Der Weg auf `scharf`:

1. `CSP_MODE=melden` (Vorgabe) und die Anwendung mit offener Browserkonsole
   (F12) durchklicken. **Vollständig heißt:** Startseite und Karte,
   Standortseite mit Bildern, Standort anlegen und bearbeiten, Anfrage stellen
   und annehmen, Chat, Verwaltungsbereich, Kontoeinstellungen mit allen vier
   Farbprofilen — **und ein echter Anruf über ein fremdes Netz**, denn dort
   entscheidet sich `connect-src`.
2. Jede Meldung `Refused to …` nennt die Regel und die Adresse. Fehlt eine
   Adresse in der Liste, gehört sie in `class/Helper/SecurityHeaders.php` —
   nicht in eine zweite Regel woanders.
3. Erst wenn nichts mehr gemeldet wird: `CSP_MODE=scharf`.

#### Was die Kopfzeilen nicht erreichen

Die Dateien unter `assets/` liefert der Webserver direkt aus, ohne PHP — sie
bekommen diese Kopfzeilen nicht. Das ist hinnehmbar: Es sind eigene,
unveränderliche Dateien, und die Regeln, die zählen, gelten für das
**Dokument**, nicht für die Datei, die es nachlädt. Wer sie trotzdem überall
haben will, setzt sie zusätzlich im Webserver.

Apache (`mod_headers`, in die `.htaccess` oder den vhost):

```apache
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>
```

nginx (im `server`-Block):

```nginx
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
```

**Nicht doppelt setzen, was PHP schon setzt.** `Header set` überschreibt die
Kopfzeile aus PHP; danach gäbe es zwei Stellen mit derselben Aussage, und die
laufen erfahrungsgemäß auseinander. Entweder die Kopfzeilen kommen aus der
Anwendung (Vorgabe) oder aus dem Webserver — und die CSP mit ihren vier CDNs
gehört ohnehin dorthin, wo sie begründet ist: in
`class/Helper/SecurityHeaders.php`.

### 6. HTTPS erzwingen und HSTS

Beides hängt an Schaltern in der `.env` — derselben Art wie beim Mailversand
(`MAIL_ENABLED`), und aus demselben Grund: Vorher stand die Weiterleitung fest
in `index.php`, und wer lokal ohne Zertifikat arbeiten wollte, musste vier
Zeilen auskommentieren. Auskommentierter Code ist kein ausgeschalteter Code —
er ist Code, den niemand mehr pflegt und der beim nächsten Commit versehentlich
mit hochgeht.

| Schlüssel | Vorgabe | Wirkung |
|---|---|---|
| `FORCE_HTTPS` | `1` | 301 von `http://` auf `https://`; Sitzungscookie `secure` |
| `TRUST_PROXY` | `0` | `X-Forwarded-Proto` auswerten |
| `HSTS_MAX_AGE` | `0` | Sekunden; `0` = kein Header |
| `HSTS_INCLUDE_SUBDOMAINS` | `0` | Zusatz `includeSubDomains` |

Ausgewertet wird alles in `class/Helper/Https.php`.

**`FORCE_HTTPS=0` ist der Entwicklungsfall** — und er schaltet zwei Dinge, nicht
eines: die Weiterleitung *und* das Merkmal `secure` am Sitzungscookie
(`config/session.php`). Ohne das zweite wäre der Schalter nur ein halber: Das
Cookie würde gesetzt und über `http://` nie zurückgeschickt, jede Anmeldung
liefe ins Leere, und niemand sähe warum. Die Regel lautet: `secure` genau dann,
wenn die Anwendung über HTTPS läuft **oder** ohnehin dorthin umleitet.

Deshalb wird `config/session.php` in `index.php` jetzt **nach** `config/env.php`
geladen — die Sitzung braucht eine Entscheidung, die in der `.env` steht.

**Kamera und Mikrofon bleiben trotzdem an HTTPS gebunden.** Browser geben beide
nur in einem *secure context* frei. `http://localhost` gilt als einer,
`http://192.168.x.x` nicht — ein Test vom Handy im selben WLAN braucht also ein
Zertifikat, auch wenn `FORCE_HTTPS=0` steht.

**`TRUST_PROXY` löst zwei entgegengesetzte Fehler**, und beide sind teuer. Steht
kein Proxy davor und der Schalter ist an, darf jeder Aufrufer behaupten, seine
Klartextverbindung sei sicher — die Weiterleitung unterbleibt. Steht einer
davor und der Schalter ist aus, sieht PHP nur `http://` und leitet auf
`https://` um, was der Proxy wieder als `http://` weitergibt: die
Endlosschleife, die man in jedem zweiten Deployment sieht.

#### HSTS: erst kurz, dann lang

`Strict-Transport-Security` sagt dem Browser, dass diese Domain nur über HTTPS
zu erreichen ist. Er merkt es sich für die volle Dauer, und **ein gesendetes
`max-age` lässt sich nicht zurückrufen** — läuft das Zertifikat ab oder zieht
die Anwendung um, sperrt der Header die eigenen Benutzer aus. Deshalb ist die
Vorgabe `0` und der Weg gestaffelt:

1. HTTPS läuft, das Zertifikat erneuert sich automatisch, `FORCE_HTTPS=1`.
2. `HSTS_MAX_AGE=300` — fünf Minuten. Fällt etwas auf, ist es nach einer
   Kaffeepause vorbei.
3. `HSTS_MAX_AGE=31536000` — ein Jahr, der übliche Wert.

`HSTS_INCLUDE_SUBDOMAINS=1` erst, wenn **wirklich jede** Subdomain ein gültiges
Zertifikat hat — auch die eine, an die gerade niemand denkt. Einen
`preload`-Schalter gibt es nicht: Wer in die Vorabliste der Browser will, trägt
seine Domain dort selbst ein und weiß dann, dass er Monate braucht, um wieder
herauszukommen.

Der Header wird nur über HTTPS gesendet — über `http://` muss ein Browser ihn
ohnehin ignorieren.

### 7. Backups

Es gibt ein Skript, und es wird **nicht automatisch installiert**:
`deploy/backup/backup.sh`. Es sichert zweierlei:

* die **Datenbank** als `mysqldump`, gzip-komprimiert → `db_<Zeitstempel>.sql.gz`
* den **Upload-Baum** (Standortbilder und Avatare) → `uploads_<Zeitstempel>.tar.gz`

Die Zugangsdaten holt es sich aus der `.env` (`DB_*`, `UPLOAD_PATH`), Ziel und
Aufbewahrung aus `BACKUP_PATH` (Vorgabe `../backups`, also **oberhalb** des
Webroots) und `BACKUP_KEEP_DAYS` (Vorgabe 14, `0` = nichts löschen). Ein Wert
aus der Umgebung sticht die `.env`, ein einzelner Lauf lässt sich also umlenken:

```bash
BACKUP_PATH=/mnt/usb bash deploy/backup/backup.sh
```

**Die `.env` wird nicht mitgesichert.** Sie enthält Datenbankpasswort, `PEPPER`,
SMTP- und Metered-Zugangsdaten im Klartext. In einem Backup, das irgendwann auf
einer zweiten Platte oder in einem Cloudspeicher landet, haben diese Werte
nichts zu suchen; sie gehören dorthin, wo auch das Zertifikat liegt. Was in ihr
stehen muss, steht in `.env.example`.

#### Einrichten

1. Einmal von Hand laufen lassen und die Ausgabe lesen:
   ```bash
   bash deploy/backup/backup.sh
   ```
   Im Erfolgsfall steht dort je eine Zeile mit Pfad und Größe. Jeder Fehler
   geht nach stderr, und das Skript endet mit einem Status ungleich 0.
2. In die crontab des Webserver-Benutzers eintragen — täglich um 3:20 Uhr:
   ```
   20 3 * * * /bin/bash /var/www/webrtc_proj/deploy/backup/backup.sh >> /var/log/webrtc/backup.log 2>&1
   ```
   Ohne Umleitung verschickt cron jede Ausgabe als Mail. Das ist kein Fehler,
   sondern die einfachste Überwachung, die es gibt: **Ein Backup, das
   stillschweigend nicht läuft, ist schlimmer als keines** — weil sich dann
   niemand mehr darum kümmert.
3. Nach dem ersten Lauf prüfen, dass das Zielverzeichnis `0700` hat und die
   Dateien `0600`. Ein Dump enthält Adressen, Passworthashes, 2FA-Geheimnisse
   und Chatverläufe — er ist dieselbe Datenbank, nur ohne Rechteprüfung.

**Ein Backup auf derselben Platte überlebt keinen Plattenausfall.** `BACKUP_PATH`
ist die halbe Miete; die andere Hälfte ist eine Kopie auf ein anderes Gerät,
etwa täglich nach dem Lauf:

```
40 3 * * * rsync -a --delete /var/backups/webrtc/ backup@anderer-server:/srv/webrtc/
```

#### Wiederherstellen

Datenbank (das Schema legt der Dump selbst an):

```bash
gunzip -c db_2026-05-01_032001.sql.gz | mysql -u webrtc_user -p webrtc_proj
```

Bilder — das Archiv enthält das Upload-Verzeichnis **samt seinem Namen**,
entpackt wird deshalb in das übergeordnete Verzeichnis:

```bash
tar -xzf uploads_2026-05-01_032001.tar.gz -C /var/lib/webrtc/
chown -R www-data:www-data /var/lib/webrtc/uploads
```

**Eine Sicherung, die nie zurückgespielt wurde, ist keine Sicherung.** Der
Testlauf gehört einmal auf eine leere Datenbank gemacht, bevor man sich auf das
Skript verlässt. Was es selbst prüfen kann, prüft es: `gzip -t` nach jedem
Archiv, und eine abgebrochene Sicherung wird gelöscht statt liegengelassen —
eine halbe Datei sieht im Verzeichnis aus wie ein Backup.

---

## 🟢 Verfügbarkeit: angemeldet ist nicht bereit

### Das Problem

Ein Guide war „online", solange **irgendein Tab der Anwendung offen stand**.
Das war ein Nebeneffekt des Heartbeats und keine Entscheidung. Wer die Seite
über Nacht offen ließ, stand am nächsten Morgen grün auf der Karte — und wurde
nachts angerufen. Ein Kunde rief damit Leute an, die gar nicht führen wollten.

### Zwei Zustände statt einem

| Frage | Spalte | Wer schreibt sie |
|---|---|---|
| Ist ein Browser dieses Kontos erreichbar? | `user.user_status` | Heartbeat (`online`/`in_call`), Cronjob und Logout (`offline`) |
| Will dieser Guide gerade führen? | `user.available_until` | ausschließlich der Bereitschaftsschalter |

**Grün auf der Karte und anrufbar ist nur, wo beides zutrifft.** Ausgewertet
wird das an genau einer Stelle: `App\Model\Location::AVAILABILITY_SQL`. Jede
Standortabfrage setzt diesen Ausdruck ein und liefert `live`, `busy` oder
`idle` — keine Lesestelle bekommt `user_status` mehr roh in die Hand.

Ohne Bereitschaft ist ein Standort **ein Angebot ohne Guide**: Er bleibt auf
der Karte sichtbar, aber grau und nicht anwählbar. Der Server weist einen
Anruf darauf ab, auch wenn er an der Oberfläche vorbei geschickt wird
(`App\Controller\WebRTCController::callRoles`).

### Der Schalter

Er sitzt in der **Kopfleiste**, neben dem Benutzermenü, und ist damit auf jeder
Seite der Anwendung zu sehen — Karte, Standortliste, Konto, Chat.

```
[W WebRTC-App]   [Neue Lokation] [Alle Standorte]   [● Bereit · noch 1:47 Std] [DK Dominik ▾]
```

Das ist Absicht und keine Platzfrage: Der Schalter hat zwei Aufgaben, und die
zweite verlangt ständige Sichtbarkeit. Er **schaltet** die Bereitschaft, und er
**zeigt sie samt Restzeit an**. In den Einstellungen könnte er nur das Erste —
ein Guide würde dort nie bemerken, dass seine Bereitschaft abgelaufen ist.

Ausgeliefert wird er fertig vom Server (`App\Helper\ViewHelper`), inklusive
Zustand. Wer die Seite ohne JavaScript öffnet, sieht immer noch richtig, ob er
bereit ist — nur der Sekundenzähler steht dann still. Sichtbar ist er für
Konten mit dem Recht `user.availability`, also dieselben, die auch Standorte
anbieten dürfen (Guide und Admin).

### Wie die Bereitschaft endet

1. **Der Guide legt den Schalter um.** Sofort, ohne Rückfrage.
2. **Die Seite wird geschlossen.** `assets/js/availability.js` schickt beim
   `pagehide` eine `navigator.sendBeacon`-Nachricht — die geht auch dann noch
   raus, wenn der Tab schon zugeht. Ein Seitenwechsel innerhalb der Anwendung
   zählt nicht.
3. **Die Frist läuft ab.** Vorgabe zwei Stunden, einstellbar als
   `availability_timeout` in `config/presence.php` — der **einen** Stelle, an
   der die Zahl steht. Von dort geht sie an den Controller, an den Browser
   (Restzeitanzeige) und an den Cronjob.
4. **Das Abmelden.** `App\Controller\LoginController::handleLogout` setzt
   `available_until` auf `NULL`, gemeinsam mit dem Status.

Fällt ein Browser weg, ohne sich abzumelden — Absturz, Netzausfall —, fängt das
die UND-Bedingung auf: Ohne frischen Heartbeat wird der Standort binnen 45
Sekunden grau, ganz ohne Schreibvorgang.

### Was die Frist verlängert

Nur **echte Bedienung**: Klick, Tastendruck, Berührung, Radbewegung — oder ein
laufendes Gespräch, damit ein führender Guide nicht mitten in der Arbeit von
der Karte fällt.

Der **Heartbeat allein verlängert nichts**, und ein wieder nach vorn geholter
Tab ebenso wenig. Genau das war der alte Fehler. Der Browser sammelt die
Bedienung und hängt sie als `active` an den nächsten Heartbeat; der Server
verlängert daraufhin eine **laufende** Bereitschaft. Eine abgelaufene schaltet
er nicht wieder ein — sonst würde ein Klick nach dem Ablauf den Guide unbemerkt
wieder anrufbar machen.

### Dass es abgelaufen ist, sieht der Guide

Jede Heartbeat-Antwort trägt die verbleibenden Sekunden. Der Schalter zählt
dazwischen lokal herunter, damit die Anzeige jede Sekunde stimmt; verbindlich
ist die Uhr des Servers. Fällt die Restzeit von „läuft" auf 0, wechselt der
Schalter auf „Nicht bereit" **und es kommt eine Meldung** — einmal, für den
Übergang, nicht bei jedem Takt. In den letzten fünf Minuten hebt sich der
Schalter zusätzlich ab.

### Was die Bereitschaft *nicht* sperrt

Eine **zugesagte Anfrage**. Sie ist die stärkere Aussage — sie gilt für genau
diesen Kunden, diesen Standort und dieses Zeitfenster —, und deshalb kommt der
Anruf dazu auch bei ausgeschaltetem Schalter durch (siehe
[Die Anfrage](#-die-anfrage-statt-des-anrufs)).

Den **Direktanruf der Verwaltung**. Ein Admin erreicht einen Guide auch dann,
wenn dieser nicht bereit ist — beide bekommen die Rolle `peer`, niemand wird
gesteuert. Für eine Rückfrage der Moderation muss sich niemand vorher bereit
gemeldet haben, und geführt wird dabei ohnehin nicht.

In der Benutzerverwaltung stehen deshalb **beide** Auskünfte nebeneinander: der
Zustandspunkt für die Erreichbarkeit und die Marke „Bereit" für die
Bereitschaft. „Angemeldet, aber nicht bereit" ist dort die Antwort auf die
Frage, warum ein Standort grau bleibt, obwohl der Guide erreichbar ist.

---

## 📨 Die Anfrage statt des Anrufs

### Das Problem

Ein Kunde rief den Guide **unmittelbar an**. Das verlangte, dass beide
zufällig im selben Moment können — und der Guide ist die knappere Seite: Er
muss losgehen, sich Zeit nehmen, vielleicht hinfahren. Er steht vielleicht
gerade im Supermarkt. Ein Anruf, der in diesem Moment klingelt, ist eine
Zumutung; einer, der zehn Minuten später gekommen wäre, wäre eine Führung
geworden.

Dazu kam ein zweiter Mangel, der erst auf den zweiten Blick auffällt: Es gab
**keinen Datensatz über stattgefundene Führungen**. Ein Anruf hinterließ ein
paar Signalzeilen, die nach 15 Sekunden gelöscht wurden. Danach war nicht mehr
feststellbar, dass überhaupt eine Führung stattgefunden hat — für Bewertungen
und für eine spätere Abrechnung fehlt genau das.

### Der Ablauf

1. **Der Kunde fragt an** — auf der Standortseite, mit einem Wunschzeitpunkt.
   Vier Vorgaben stehen bereit (*jetzt sofort*, *in 1 Stunde*, *in 3 Stunden*,
   *morgen um diese Zeit*), darunter ein Feld für jeden anderen Zeitpunkt.
   **Ein Klick auf eine Vorgabe trägt den gemeinten Zeitpunkt in das Feld ein**
   — die Wahl ist damit ablesbar, und beide Bedienelemente hängen sichtbar
   zusammen. Wer das Feld selbst anfasst, hebt die Markierung auf; dann gilt
   das Feld. Solange eine Vorgabe markiert ist, gilt *sie* — sonst verfiele
   „jetzt sofort", sobald der eingetragene Zeitpunkt ein paar Minuten alt ist.
2. **Der Guide antwortet** — annehmen oder ablehnen, auf der Seite *Anfragen*.
3. **Nach der Zusage startet der Kunde die Führung** — mit demselben Knopf und
   demselben Weg wie vorher: `rtc.startCall` mit der Standortkennung, der
   Server vergibt die Rollen (`WebRTCController::callRoles`).
4. **Beginn und Ende schreibt der Server mit** — am Offer und am Hangup, die
   ohnehin durch das Signaling laufen. Kein zusätzlicher Klick, den jemand
   vergessen kann.

**„Jetzt sofort" ist kein Sonderfall.** Es ist der Wunschzeitpunkt mit dem
Abstand null. Es gibt dafür keine Spalte, keine Marke und keine Verzweigung —
alles, was für eine Anfrage in drei Tagen gilt, gilt auch für eine sofortige.

**Der Wunschzeitpunkt reist als Abstand in Sekunden**, nicht als Datum. Ein
Abstand hat keine Zeitzone: Guide und Kunde sitzen womöglich in verschiedenen,
und „in einer Stunde" heißt für beide dasselbe. Die Datenbank rechnet daraus an
ihrer eigenen Uhr einen Zeitpunkt — derselben Uhr, an der auch alle Fristen
hängen.

### Wo der Guide die Anfragen sieht

**In der Kopfleiste**, gleich neben dem Bereitschaftsschalter, steht ein
Zähler; er führt auf die Seite *Anfragen*. Der Ort ist die Antwort auf die
Anforderung, dass eine Anfrage auch dann ankommt, wenn der Guide sie im Moment
des Eintreffens nicht bemerkt hat: Die Kopfleiste steht auf **jeder** Seite der
Anwendung — dieselbe Überlegung, aus der auch der Bereitschaftsschalter dort
sitzt und nicht in den Einstellungen.

Der Zähler meint immer dasselbe — *hier wartet etwas auf dich* — und zählt
deshalb beide Richtungen zusammen:

| | |
|---|---|
| eingehend | Anfragen an die eigenen Standorte, die noch keine Antwort haben |
| ausgehend | eigene Anfragen, die angenommen wurden — die Führung wartet |

Er steht bei **jedem angemeldeten Konto**, nicht nur bei Guides: Auch ein
Zuschauer muss sehen, dass seine Anfrage angenommen wurde, sonst müsste er die
Standortseite offen halten und hoffen. Seine Zahlen fahren auf dem Takt des
Heartbeats mit (`UserController::heartbeat`) — eine zweite Abfrageschleife
daneben wäre derselbe Weg noch einmal.

Die Seite *Anfragen* zeigt beide Listen: *An meine Standorte* mit den Knöpfen
zum Annehmen und Ablehnen, *Meine Anfragen* mit dem Zustand und, sobald die
Zusage gilt, dem Startknopf.

### Die sechs Zustände

| Wert in `status` | Bedeutung |
|---|---|
| `open` | gestellt, noch nicht beantwortet |
| `accepted` | der Guide hat zugesagt |
| `declined` | der Guide hat abgesagt |
| `expired` | unbeantwortet verstrichen **oder** angenommen und das Zeitfenster ungenutzt vorbei |
| `done` | die Führung hat stattgefunden |
| `cancelled` | zurückgezogen — vom Kunden oder vom Guide |

Sie stehen als Text in der Spalte und nicht als Zahl: In einem Dump soll
lesbar sein, was mit einer Anfrage passiert ist, ohne eine Codetabelle
danebenzulegen.

**„Abgelaufen" steht in keiner Spalte.** Es ergibt sich aus den Zeitpunkten und
wird bei **jeder** Abfrage ausgerechnet (`TourRequest::statusSql`) — dieselbe
Bauart wie bei der Bereitschaft. Damit wirkt ein Ablauf sofort und auch dann,
wenn der Cronjob gar nicht eingerichtet ist; der räumt nur auf.

### Die Fristen

Alle in `config/requests.php`, und dort stehen sie **einmal**:

| Schlüssel | Vorgabe | Bedeutung |
|---|---|---|
| `response_timeout` | 1 Std | wie lange eine offene Anfrage auf Antwort wartet |
| `wish_grace` | 15 Min | wie lange nach dem Wunschzeitpunkt eine offene Anfrage noch gilt |
| `lead_time_max` | 14 Tage | wie weit im Voraus sich anfragen lässt |
| `call_window_before` / `_after` | 15 Min / 2 Std | das Zeitfenster um den Wunschzeitpunkt, in dem eine Zusage anrufbar ist |
| `rejoin_window` | 30 Min | wie lange nach dem letzten Auflegen sich wieder einsteigen lässt |
| `stale_call` | 4 Std | Reißleine: wann eine begonnene Führung ohne jedes Auflegen als beendet gilt |

Eine offene Anfrage läuft ab, **wenn einer der beiden Gründe eintritt** — der
frühere gewinnt: Eine Anfrage für „jetzt sofort" ist eine Viertelstunde später
gegenstandslos, eine für nächsten Samstag verfällt nach der Antwortfrist statt
eine Woche offen zu stehen. Der Ablaufzeitpunkt wird beim Anlegen gerechnet und
steht in der Zeile; er bleibt damit nachvollziehbar, auch wenn jemand die
Konfiguration ändert.

### Auflegen ist nicht beenden

**Der Befund.** Auflegen ist zweideutig: Es kann „wir sind fertig" heißen — oder „das Netz ist weg". Bisher galt jedes Auflegen als Abschluss (`hangup` setzte `status = 'done'`), und das hatte zwei Folgen, die beide falsch waren:

1. **Der Startknopf blieb stehen.** Anrufbar war eine Führung im Zustand `accepted` *oder* `done`, solange das Zeitfenster um den Wunschzeitpunkt lief. Der Kunde konnte dieselbe Führung nach dem Auflegen beliebig oft neu starten — zwei Stunden lang.
2. **Die Bewertung wurde sofort fällig**, obwohl die Führung womöglich nur unterbrochen war. Der Kunde bekam die Frage, während der Guide noch auf dem Weg zurück in die Leitung war.

Beides ist derselbe Fehler: Ein technisches Ereignis — der Abbau einer Verbindung — wurde als fachliche Entscheidung gelesen.

**Was jetzt gilt.** Das Auflegen schreibt nur noch `ended_at`, den Zeitpunkt des letzten Auflegens, und lässt den Zustand in Ruhe. **Beendet wird die Führung vom Guide, ausdrücklich** (`TourRequest::finish`, Recht `request.finish`) — er ist vor Ort und weiß, ob sie vorbei ist oder ob er gerade durch einen Tunnel fährt. Der Kunde sieht in beiden Fällen dasselbe.

Erst danach steht `status` auf `done`, erst danach verschwindet der Startknopf, und erst danach wird die Bewertung fällig.

**Zwei Zeitpunkte, zwei Bedeutungen** — deshalb `closed_at` neben `ended_at` und nicht statt dessen:

| Spalte | Bedeutung |
|---|---|
| `ended_at` | wann zuletzt aufgelegt wurde — das ehrliche Ende des **Gesprächs** und die Grundlage einer späteren Abrechnung |
| `closed_at` | wann der Guide gesagt hat, dass es vorbei ist — ein Verwaltungsakt, der zehn Minuten später kommen kann |

### Wiedereinstieg — und wann er endet

Solange nicht beendet ist, gilt die Führung als **unterbrochen**, und beide Seiten können wieder einsteigen. Begrenzt ist das durch `rejoin_window` (30 Minuten):

* **Die Uhr läuft ab dem letzten Auflegen**, nicht ab dem Beginn — sonst wäre eine zweistündige Führung nach anderthalb Stunden nicht mehr zu retten. Ein zweites Auflegen setzt sie neu.
* **Kam nie ein Auflegen an** (Absturz, Netz weg), gibt es keinen Zeitpunkt, ab dem sie zählen könnte. Dann greift die bestehende Reißleine `stale_call` (4 Std) ab `started_at` — sie muss die längste Führung überdauern.

Ausgewertet wird das in **jeder Abfrage** (`TourRequest::closedSql`), nicht erst vom Cronjob: Eine vergessene Führung ist nach der Frist zu und damit bewertbar, auch wenn der Job gar nicht eingerichtet ist. Er schreibt nur fest, was ohnehin schon gilt — und erfindet dabei weder ein Ende noch einen Abschluss.

**Beim Wiedereinstieg entscheidet die Führung über die Rollen.** Das ist die einzige Stelle, an der nicht gilt „wer angerufen wird, führt". Der Grund: Meldet sich nach einem Abbruch der *Guide* zurück, wäre der Kunde der Angerufene und damit der Guide — samt Steuerkreuz auf den Falschen. Läuft zwischen den beiden eine begonnene, nicht beendete Führung, entscheidet deren Zeile (`TourRequest::runningBetween` in `WebRTCController::callRoles`).

Das weicht die Rollenvergabe nicht auf: `guide_user_id` wurde beim Anlegen der Anfrage **aus dem Standort** übernommen und nie behauptet, die Führung hat bereits begonnen, und die Zeile gilt nur, solange sie läuft. Niemand bekommt dort eine Rolle, die er nicht schon hatte.

### Wo der Guide den Knopf sieht

Dasselbe Muster wie bei der Bewertung, nur spiegelverkehrt — und aus demselben Grund: Auf Telefonen lädt die Seite nach dem Gespräch neu, ein Knopf, der in diesem Moment auf dem Bildschirm stand, wäre weg.

1. **Eine Karte nach dem Auflegen**, über den Heartbeat (`TourRequest::runningForGuide` → `assets/js/tour.js`): „Ihre Führung mit … ist noch nicht beendet", mit *Wieder einsteigen*, *Führung beenden* und *Später*, und mit der verbleibenden Frist. Sie kommt nicht während eines Gesprächs und ist wegklickbar.
2. **Die Anfragenseite**: Jede laufende Führung steht dort mit denselben Knöpfen und einer eigenen Marke („Läuft").
3. **Der Zähler in der Kopfleiste** zählt sie mit. Er meint ohnehin „hier wartet etwas auf dich" — und eine nicht beendete Führung hält den Startknopf beim Kunden offen.

Das Beenden fragt vorher nach: Es lässt sich nicht zurücknehmen und nimmt beiden Seiten den Wiedereinstieg.

### Die Zusage ersetzt die Bereitschaft

Der Bereitschaftsschalter **bleibt, was er ist**: „ich kann jetzt sofort". Er
färbt die Nadel auf der Karte und gilt für jeden.

Eine **angenommene Anfrage** ist die stärkere Aussage — sie gilt für genau
diesen Kunden, genau diesen Standort und genau dieses Zeitfenster. Deshalb
lässt der Server den Anruf zu einer Zusage auch dann durch, wenn der Schalter
aus ist (`WebRTCController::callRoles`, zweite Tür). Wer sich für 18 Uhr
verabredet hat, soll die Verabredung nicht daran verlieren, dass er um 18 Uhr
vergessen hat, den Schalter umzulegen.

Was dabei **nicht** aufgeweicht wird: Die Standortkennung ist weiterhin eine
Behauptung des Anrufers und wird geprüft — es muss ein Standort **des
Angerufenen** sein, und er darf nicht gesperrt sein. Eine Zusage für einen
anderen Standort desselben Guides öffnet nichts.

### Was die Tabelle sonst noch löst

`tour_request` ist der erste Datensatz über Führungen, und darauf stützt sich
später mehr als die Anfrage selbst:

* **Bewertungen** brauchen einen Beleg, dass die Führung stattgefunden hat.
  `started_at` und `ended_at` sind dieser Beleg.
* **Die Abrechnung** braucht Dauer und Beteiligte. Beides steht in der Zeile —
  ein Preis steht bewusst *nicht* darin, es wird nichts berechnet.
* **Ohne Fremdschlüssel**, und das ist Absicht: Eine durchgeführte Führung
  bleibt geschehen, auch wenn der Standort später gelöscht wird oder ein Konto
  verschwindet. Mit `ON DELETE CASCADE` wäre die Historie beim ersten
  gelöschten Standort weg. Gelesen wird deshalb über `LEFT JOIN`; fehlt der
  Standort, fehlt eben sein Titel.

Kommt das Ende einer Führung nie an — Absturz, Netzausfall —, schließt der
Cronjob die Zeile nach `stale_call` ab und lässt `ended_at` **leer**. Ein
geschätztes Ende wäre eine Erfindung, und an dieser Spalte hängt später eine
Abrechnung.

---

## 📍 Der Standort und seine Seite

### Das Problem

Ein Standort bestand aus Land, Stadt, zwei Koordinaten und **einer Zeile
Freitext**. Auf dieser Grundlage sollte ein Kunde entscheiden, ob er einen
Fremden losschickt, der ihn per Video durch eine ihm unbekannte Stadt führt.
Ein Klick auf eine Nadel begann sofort den Anruf — es gab nichts dazwischen,
auf dem eine Entscheidung hätte fußen können.

### Was ein Standort jetzt trägt

| Feld | Spalte | Wozu |
|---|---|---|
| Titel | `location.title` | Die Überschrift des Angebots. Steht im Kartenfenster, in der Liste und auf der Seite. |
| Kurzbeschreibung | `location.description` | **Unverändert** die eine Zeile für Kartenfenster und Liste. Dort ist Kürze richtig — ein Absatz in einem Kartenfenster ist unlesbar. |
| Ausführliche Beschreibung | `location.description_long` | Mehrzeilig, nur auf der Standortseite. |
| Typische Dauer | `location.duration_minutes` | In Minuten, **mit 5 vorbelegt** (`LocationController::DAUER_VORGABE`). Wer das Feld nicht anfasst, speichert fünf Minuten; `NULL` — "nicht angegeben", die Seite erwähnt die Dauer dann gar nicht — kommt nur zustande, wenn der Guide das Feld ausdrücklich leert. |
| Sprachen | `location.languages` | Kürzel nach ISO 639-1, kommagetrennt (`de,en`). Der Katalog steht in `App\Helper\Languages` und **nur dort**. |
| Bilder | Tabelle `location_image` | Je Bild eine Zeile mit Reihenfolge und **Verwendung** (`role`): ein `cover` füllt den Kopf der Seite, alle `gallery` stehen als Beispielbilder darunter. Die Dateien liegen außerhalb des Webroots. |
| Übliche Zeiten | `location.availability_slots` | 28 Zeichen aus `0` und `1` — sieben Wochentage mal vier Tagesabschnitte. Eine **Orientierung**, kein Kalender (siehe [Übliche Zeiten](#übliche-zeiten-und-die-zeitzone-des-ortes)). |
| Zeitzone | `location.timezone` | Die Zone **am Ort der Führung**, z. B. `Europe/Lisbon`. Wird beim Speichern aus Land und Koordinaten abgeleitet und lässt sich überschreiben. |

**Die bisherige Beschreibung ist nicht verlorengegangen und auch nicht
verschoben worden.** `location.description` steht unverändert an seinem Platz
und behält seine Aufgabe; es hat nur einen Namen für das bekommen, was es
immer schon war: die Kurzbeschreibung. Übernommen wurde der Bestand in den
**Titel** — jeder vorhandene Standort trägt seine bisherige Beschreibung, auf
120 Zeichen gekürzt, als Überschrift. Damit hat kein Standort nach der
Migration eine leere Seite, und es ist nichts erfunden.

Die ausführliche Beschreibung bleibt bewusst leer. Sie mit derselben Zeile zu
füllen hätte denselben Satz dreimal auf die Seite gebracht; dass ein Guide sie
noch nicht geschrieben hat, ist die Wahrheit und wird auf der Seite auch so
gesagt. Solange Titel und Kurzbeschreibung gleich sind, zeigt die Seite sie nur
einmal.

### Die Seite

```
index.php?act=location&id=<standort>
```

Eine Adresse, die sich verlinken und weitergeben lässt. Sie zeigt Bilder,
Titel, Beschreibung, Dauer, Sprachen, den Treffpunkt auf einer kleinen Karte
und den Verfügbarkeitszustand des Guides — **und von hier aus, und nur von
hier aus, beginnt die Führung**: mit einer Anfrage samt Wunschzeitpunkt (siehe
[Die Anfrage](#-die-anfrage-statt-des-anrufs)).

**Der Verfügbarkeitszustand sperrt hier nichts mehr.** Er ist eine Auskunft:
Steht der Guide gerade bereit, hat „jetzt sofort" gute Aussichten. Ein
Standort, an dem gerade niemand ist, lässt sich trotzdem für heute Abend
anfragen — genau darum ging es bei diesem Umbau.

**Auch ein Gast sieht sie** (Recht `location.view`, wie `location.map_public`).
Ein geteilter Link, der beim Empfänger auf dem Anmeldeformular endet, wird
nicht weitergegeben. Was ein Gast nicht bekommt, ist die `user_id` des Guides
**als Anrufziel** — ohne sie lässt sich von dort niemand anrufen. (Im Verweis
auf das [Guide-Profil](#-der-guide-als-mensch) steht die Kennung dagegen für
jeden: Die Profilseite ist öffentlich und hat sie als Adresse. Eine Kennung in
einer Adresse ist kein Anrufziel.) Anfragen kann er ebenfalls nicht:
Eine Anfrage gehört zu einem Konto, sonst gäbe es niemanden, dem der Guide
zusagen könnte. Statt eines Formulars, das nichts bewirkt, steht dort der Weg
zur Anmeldung. Dieselbe Entscheidung wie bei der
öffentlichen Karte.

**Ein gesperrter Standort ist auf dieser Seite nur für seinen Eigentümer und
die Moderation zu sehen.** Für alle anderen antwortet sie so wie für einen
Standort, den es nicht gibt: Zwei unterscheidbare Antworten wären eine Auskunft
darüber, welche IDs belegt sind.

### Der Aufbau der Seite

Die erste Fassung war eine Reihe **gleichrangiger Kästen**: Überschrift,
Bildkasten, Textkasten, Datenkasten, Kartenkasten — alle mit demselben Rahmen,
demselben Abstand, demselben Gewicht. Eine Seite ohne Anfang und ohne
Schwerpunkt, auf der ausgerechnet das Wichtigste unterging: das Bild und die
ausführliche Beschreibung, die ganz unten unter der Karte stand.

Jetzt hat die Seite eine Rangfolge:

```
←──────────────────── volle Fensterbreite ─────────────────────→
┌──────────────────────────────────────────────────────────────┐
│  [← Zurück zur Übersicht]                                    │  ← auf dem Bild
│                                                              │
│                    T I T E L B I L D                         │  ← randlos, in der
│                                                              │     Höhe gedeckelt
│  ● Jetzt verfügbar                                           │  ← Band: traegt die
│  Alfama bei Nacht – durch die ältesten Gassen Lissabons       │     Lesbarkeit
│  Lissabon, Portugal                                          │
└──────────────────────────────────────────────────────────────┘

     Die alten Gassen nach Sonnenuntergang …    ┌──────────────┐
                                                │ Wunschzeit-  │  ← die Anfrage
     Wir treffen uns am Miradouro de Santa      │ punkt wählen │     zuerst
     Luzia, wenn die Sonne gerade hinter …      │ Führung      │
                                                │  anfragen    │
                                                ├──────────────┤
                                                │ Dauer  1:30  │  ← Nebendaten darunter
     Sie bestimmen den Weg. Über das            │ Sprachen …   │
     Steuerkreuz schicken Sie mich …            │ Ort     …    │
                                                └──────────────┘
     Bilder vom Ort                              läuft beim
     ┌────────┐ ┌────────┐ ┌────────┐                Scrollen mit
     │  Foto  │ │  Foto  │ │  Foto  │              ← Beispielbilder, ueber
     └────────┘ └────────┘ └────────┘                 beide Spalten
     Treffpunkt
     ┌──────────────────────────────────────────────────────┐
     │                     Karte                            │  ← Beiwerk, unten,
     └──────────────────────────────────────────────────────┘     über beide Spalten
```

| | |
|---|---|
| **Das Titelbild führt** | Randlos über die volle **Fensterbreite**, ohne Kasten drumherum — und in der Höhe gedeckelt, damit darunter immer etwas vom Text steht. |
| **Titel, Ort und Zustand liegen darauf** | Sie gehören zum Bild, nicht in eine eigene Zeile daneben. Ein Verlauf zwischen Bild und Schrift sorgt für den Kontrast; ein Kasten hinter der Schrift wäre wieder ein Kasten. |
| **Der Weg zurück liegt ebenfalls darauf** | Über dem Bild steht nichts. |
| **Die Beschreibung folgt unmittelbar** | Sie ist der Grund, warum jemand die Seite liest. |
| **Die Anfrage steht oben in der schmalen Spalte** | Nicht zwischen den Datenzeilen, wo der frühere Knopf wie deren Fußnote aussah. Die Spalte läuft beim Scrollen mit: Wer unten in der Beschreibung angekommen ist, soll sie nicht wieder suchen müssen. An derselben Stelle steht später der Zustand der eigenen Anfrage und, nach der Zusage, der Startknopf. |
| **Dauer, Sprachen und Ort darunter** | Sie sind Auskunft, keine Handlung — abgesetzt durch eine Linie und einen ruhigeren Grund. |
| **Die Beispielbilder stehen unter dem Text, über beide Spalten** | Sie zeigen den Ort, sie führen die Seite nicht an — dafür ist das Titelbild da. |
| **Die Karte steht unten, über beide Spalten** | Beiwerk — aber nicht neben einem Loch: Vorher stand sie nur unter dem Text, und rechts daneben, unter dem Knopf, blieb Platz übrig, den nichts füllte. |

#### Volle Fensterbreite ohne `vw`

Das Bild soll über das ganze Fenster laufen, nicht nur über die
Inhaltsspalte — sonst stehen auf einem breiten Bildschirm links und rechts
Balken. Der übliche Weg dafür ist `width: 100vw` bzw.
`margin-inline: calc(50% - 50vw)`. **Beides ist hier falsch:** `vw` zählt den
senkrechten Rollbalken mit, und diese Seite scrollt. Das Bild wäre rund 15
Punkte breiter als das sichtbare Fenster und zöge einen waagerechten
Rollbalken nach sich.

Stattdessen zwei Regeln, die zusammengehören und in
`assets/css/location.css` beieinanderstehen:

1. `.app-page:has(> .loc-page) { max-width: none }` hebt die
   1200-Punkte-Grenze des Inhaltsbereichs auf — **nur für diese Seite**;
   `:has(> .loc-page)` trifft ausschließlich den Rahmen, in dem eine
   Standortseite steht.
2. Das Bild zieht mit `margin-inline: calc(var(--app-space-5) * -1)` den
   Innenabstand des Inhaltsbereichs wieder ab. Padding und negativer
   Außenabstand heben sich exakt auf: Das Bild endet genau an der
   Fensterkante, keinen Punkt weiter.

Fällt `:has()` aus (ein sehr alter Browser), bleibt die Grenze stehen und das
Bild läuft über die Inhaltsspalte — also so, wie es vorher war. Eine
Verbesserung, keine Voraussetzung.

#### Zwei Arten von Bildern

Vorher gab es **eine** Liste, und das erste Bild darin musste zweierlei
zugleich sein: Hintergrund der Kopfzeile und Beispielbild des Ortes. Das
kann ein Bild nicht. Ein Titelbild braucht ein sehr breites Format und ruhige
Flächen, auf denen Schrift stehen kann; ein Beispielbild soll zeigen, was man
an dem Ort zu sehen bekommt — meist genau das Gegenteil einer ruhigen Fläche.
Auf einem hellen Foto war der Titel kaum noch zu lesen.

Getrennt sind sie über **eine Spalte**, nicht über eine zweite Tabelle und
nicht über einen zweiten Upload-Weg:

| `location_image.role` | Wo es steht | Wie viele |
|---|---|---|
| `cover` | füllt den Kopf der Seite | höchstens eines je Standort |
| `gallery` | Streifen "Bilder vom Ort" unter der Beschreibung | der Rest |

Eine Tabelle bleibt eine Tabelle: Dieselbe Datei, derselbe Speicherort,
dieselbe Prüfung, dieselbe Auslieferung über `index.php?act=location_image`.
Was sich unterscheidet, ist allein die Verwendung — und die ändert sich per
Klick, ohne dass etwas neu hochgeladen oder gelöscht wird
(`set_location_cover` / `unset_location_cover`).

* **Das erste hochgeladene Bild wird von selbst Titelbild.** Wer eins hat,
  hat auch einen Kopf; wer die Wahl treffen will, klickt den Stern an einer
  anderen Kachel.
* **Ein Titelbild abwählen löscht es nicht**, es rutscht in die Galerie
  zurück. Wer sein Titelbild absetzt, will fast immer ein anderes wählen und
  nicht dieses Bild verlieren — deshalb gibt es auch keine Rückfrage.
* **Ein neues Titelbild stuft das alte zurück**, in einer Transaktion und mit
  dem Eigentümer in der `WHERE`-Klausel beider Anweisungen. Zwei Titelbilder
  gleichzeitig kann es dadurch nicht geben; und käme in alten Daten doch eines
  vor, entscheidet `LocationImage::teile()` — das erste `cover` gewinnt, jedes
  weitere fällt in die Galerie.
* **Die Obergrenze gilt für die Summe.** Fünf Bilder heißt fünf Bilder, egal
  wie sie verwendet werden; das Titelbild ist keins extra. Der Hinweis im
  Formular sagt das auch so: "Noch 2 von 5 Bildern möglich, Titelbild
  mitgezählt."

Die Beispielbilder öffnen sich im Großen in einem Lichtkasten, durch den man
blättern kann. Ohne JavaScript ist jede Kachel schlicht ein **Verweis auf das
Bild** — ein Klick öffnet es dann eben direkt, statt ins Leere zu greifen.

#### Die Höhe des Kopfes ist gedeckelt

Ein Bild ohne Höhenangabe wächst mit der Breite. Auf einem hohen Bildschirm
füllte der Kopf dadurch fast das ganze Fenster, und wer die Seite öffnete, sah
ein Foto und sonst nichts — die Beschreibung, um die es geht, und der Knopf,
um den es geht, lagen unter der Kante.

`.loc-hero__frame` bekommt deshalb `max-height: 56vh`. Der Wert ist kein
runder Zufall: Auf dem niedrigsten Fenster, das noch ein Fenster ist (rund
700 Punkte), bleiben darunter gut 300 Punkte — genug für die Überschrift des
Textes, die ersten Zeilen und den Knopf. Nach oben wächst der Kopf mit dem
Fenster mit, aber er nimmt es nie ganz.

**Die Lesbarkeit des Titels hängt nicht am Bild.** Sie hängt an dem Band, auf
dem er steht: ein Verlauf von 90 % Deckung unten auf 55 % am oberen Ende des
Textes, dann weich auf null. Er ist so hoch wie der **Text**, nicht ein fester
Anteil der **Bildhöhe** — ein dreizeiliger Titel bringt sein Band selbst mit.
Weiß darauf liegt in jedem Fall über 5:1 Kontrast, auch wenn das Bild an
dieser Stelle weiß ist. Geprüft wird das mit einem absichtlich überstrahlten
Testbild, nicht mit einem gefälligen.

#### Die Breiten sind ausgerechnet, nicht gewählt

Die Textspalte ist auf 75 Zeichen begrenzt — darüber findet das Auge den
Zeilenanfang nicht mehr wieder. Ist die Spalte daneben zu schmal, bleibt der
Überschuss als **Loch zwischen Text und Kasten** stehen; auf einem
2500-Punkte-Bildschirm waren das 234 Punkte.

| | Spalte | Kasten | Lücke | bleibt für den Text | 75 Zeichen brauchen |
|---|---|---|---|---|---|
| bis 1600 px | 1020 | 360 | 32 | 628 | ~626 |
| ab 1600 px | 1160 | 400 | 48 | 712 | ~710 |

Was auf einem großen Bildschirm wächst, ist der Betrachtungsabstand — also
die **Schrift**. Dass die Spalte dabei breiter wird, ist die Folge und nicht
der Zweck. Wer eine der drei Zahlen einer Zeile ändert, ändert die anderen
mit; `tests/server_test.php` rechnet sie nach.

**Auf schmalen Geräten fällt alles untereinander**, und zwar in dieser
Reihenfolge: Bild, Beschreibung, Knopf mit Angaben, Karte. Das ist auch die
Reihenfolge im Dokument; auf breiten Bildschirmen ordnet erst das Raster
(`grid-template-areas`) die Karte nach links unter den Text. Andersherum — die
Karte im Dokument vor der schmalen Spalte — stünden auf einem Telefon 260 Punkte
Beiwerk zwischen der Beschreibung und der Handlung, um die es geht.

**Ohne Titelbild** gibt es keinen leeren Fotokasten und auch keinen Satz
darüber, dass ein Bild fehlt: ein ruhiger Streifen, auf dem Titel, Ort und
Zustand trotzdem stehen. Dass keins da ist, sieht man; ein Satz macht daraus
eine Meldung. Der Guide erfährt es dort, wo er etwas dagegen tun kann — im
Bearbeitungsformular. **Ohne Beispielbilder** entfällt der Streifen "Bilder vom
Ort" ganz, mitsamt seiner Überschrift.

Seine Höhe steht mit **zwei Klassen** im Selektor
(`.loc-hero__frame.loc-hero__frame--empty`). Das ist kein Zufall: Die Höhe des
Bildrahmens wird in zwei Medienabfragen neu gesetzt, und eine Medienabfrage
erhöht die Spezifität nicht. Mit nur einer Klasse bekam der leere Streifen auf
breiten Bildschirmen die Höhe eines Fotos — 570 Punkte graue Fläche mit einem
Titel darin.

**Statt eines Umschalters ein Weg zurück.** Hier stand „Karte | Liste". Der
gehört auf die Startseite und auf die Standortliste: Dort schaltet er zwischen
zwei Ansichten *derselben* Menge um, und einer der beiden Einträge ist der, auf
dem man gerade steht. Auf dieser Seite stimmte beides nicht — man ist weder auf
der Karte noch in der Liste, sondern bei *einem* Standort. Ein Umschalter ohne
aktuellen Zustand ist keiner; er sah nur so aus. An seiner Stelle steht
„← Zurück zur Übersicht" und führt auf die Karte der Startseite.

### Übliche Zeiten und die Zeitzone des Ortes

#### Das Problem

Der Verfügbarkeitsschalter beantwortet genau eine Frage: *Kann der Guide
jetzt sofort?* Steht er auf aus — und das ist der Normalfall —, blieb offen,
ob sich eine Anfrage für später überhaupt lohnt oder ob der Guide nur
sonntags kann. Der Kunde konnte anfragen, aber er fragte ins Blaue.

#### Was der Guide einträgt

Ein Raster aus **sieben Wochentagen mal vier Tagesabschnitten**:

| Abschnitt | Uhrzeiten |
|---|---|
| nachts | 22–6 |
| vormittags | 6–12 |
| nachmittags | 12–18 |
| abends | 18–22 |

Vier Abschnitte und nicht drei, weil es Nachtführungen gibt — bei drei fiele
die Nacht hinten herunter. Die Nacht ist zugleich der einzige Abschnitt, der
über Mitternacht läuft; sie gehört dem Kalendertag, auf den die Uhrzeit
fällt. „Donnerstags nachts" heißt also Donnerstag 22–24 Uhr **und**
Donnerstag 0–6 Uhr.

Feiner wäre nicht besser: Wer eine Uhrzeit auf die Viertelstunde einträgt,
gibt eine Zusage ab — und genau das ist hier nicht gemeint. Die Angabe ist
eine **Orientierung**; verabredet wird über die Anfrage.

Die Grenzen stehen in `App\Helper\Availability` und **nur dort**. Der Browser
bekommt sie mit den Seitendaten (`hours.parts`) und führt keine zweite
Tabelle — sonst hieße „abends" im Browser bald etwas anderes als in der
Datenbank.

#### Warum am Standort und nicht am Konto

Derselbe Guide kann in der Altstadt abends und am Hafen sonntags früh
unterwegs sein. Die Zeiten stehen deshalb in `location` und nicht in
`guide_profile`.

Gespeichert wird **eine Spalte** und keine eigene Tabelle: Es sind 28
Ja/Nein-Angaben, die immer vollständig gelesen und vollständig geschrieben
werden — zusammen mit dem Standort, auf dessen Seite sie stehen. Eine Suche
bleibt trotzdem möglich (`SUBSTRING(availability_slots, 24, 1)` für
„samstagabends").

#### Was der Kunde sieht

Im selben Kasten wie das Anfrageformular, unter dem Knopf:

> **Meistens unterwegs**
> Sa+So vormittags, Mo–Fr abends
> Ortszeit: Europe/Lisbon (UTC+1) · dort ist es 8 Stunden früher als bei Ihnen

Aufeinanderfolgende Tage werden zusammengefasst (`Mo-Fr`, `Sa+So`, `Do`) —
fünf Zeilen wären keine Orientierung auf einen Blick.

Wählt der Kunde einen Zeitpunkt **außerhalb** dieser Zeiten, steht unter dem
Feld ein Hinweis:

> Das liegt außerhalb der üblichen Zeiten (Sa+So vormittags, Mo–Fr abends).
> Anfragen können Sie trotzdem – der Guide entscheidet.

**Er sperrt nichts.** Der Knopf bleibt offen, die Anfrage geht durch. Wer
außerhalb anfragt, weiß danach nur, dass er es tut.

**Ohne Angaben schweigt die Seite.** Ein Kasten „keine Zeiten angegeben" wäre
eine Auskunft über das Formular des Guides und nicht über den Standort. Der
Eigentümer bekommt an derselben Stelle einen Hinweis — er kann etwas daran
ändern.

#### Die Zeitzone: beide Zeiten, wenn sie auseinanderfallen

Die Zeiten gelten **am Ort der Führung**. Ein Kunde in Tokio, der einen
Standort in Lissabon ansieht, muss „donnerstags abends" als Lissabonner Abend
lesen — sonst verabreden sich beide auf verschiedene Uhrzeiten.

Deshalb steht die Zone am Standort, und deshalb steht beim Kunden **beides**:
im Kasten der Abstand zur eigenen Zone, am gewählten Zeitpunkt die Ortszeit
am Treffpunkt. Sind beide in derselben Zone, steht keins von beidem da — ein
Satz ohne Auskunft ist schlechter als keiner.

Woher die Zone kommt, in drei Stufen (`Availability::zoneFor`, beim
Speichern des Standorts):

1. Hat das Land **eine** Zone (Japan, Portugal ohne Inseln), ist sie es.
2. Haben alle Zonen des Landes **denselben Versatz** — jetzt und in einem
   halben Jahr, also auch über die Sommerzeit hinweg —, ist die Wahl
   gleichgültig; dann die erste. Das ist die geläufige: `Europe/Berlin` vor
   `Europe/Busingen`.
3. Sonst die Zone, deren **Bezugspunkt am nächsten** liegt. PHP liefert zu
   jeder Zone Koordinaten (`DateTimeZone::getLocation`); damit trifft es
   Denver gegen New York und Perth gegen Sydney.

Das ist **PHP-Bordmittel** — keine neue Abhängigkeit, kein Netzaufruf beim
Speichern, keine mehrere Megabyte großen Zonengrenzen. Der Preis: An einer
Zeitzonengrenze kann Stufe 3 danebenliegen. Deshalb steht die erkannte Zone
als Auswahlfeld im Bearbeitungsformular — **das letzte Wort hat der Guide.**

Ein Standort, der seit `014` noch nie gespeichert wurde, trägt keine Zone.
Die Seite leitet sie dann beim Lesen mit derselben Regel ab; geschrieben wird
sie beim nächsten Speichern. Ist auch das Land unbekannt, ist der Rückfall
**UTC** — bewusst nicht die Zeit des Servers: Eine unbekannte Zone soll
auffallen und nicht stillschweigend „wie bei uns" bedeuten.

#### Das Raster im Formular

Sieben Zeilen, vier Spalten, 28 Kästchen. Die Spaltenköpfe tragen die
Uhrzeiten mit („abends 18–22"), denn der Kunde liest später dieselben
Grenzen. Zeilen- und Spaltenköpfe sind Knöpfe: Ein Klick setzt „immer abends"
oder „donnerstags ganz". Das ist eine **Abkürzung, kein Ersatz** — ohne
JavaScript bleiben es 28 gewöhnliche Kästchen, und das Formular funktioniert
genauso, es dauert nur länger.

### Karte und Liste führen dorthin

Ein Klick auf eine Nadel oder eine Listenzeile führt auf die Standortseite
statt in den Anruf. In der Standortliste heißt die Hauptaktion deshalb
"Ansehen" statt "Anrufen", und sie ist ein **Verweis** statt eines Knopfes —
damit lässt sie sich in einem neuen Tab öffnen, kopieren und weitergeben.

Sie ist nie gesperrt, auch bei einem Standort ohne Guide: Ansehen kann man ihn
immer. Die Verfügbarkeit entscheidet nur noch über das Gewicht des Verweises.

**Die Standortkennung überlebt den längeren Weg.** Das ist der Punkt, an dem
dieser Umbau still hätte scheitern können: An der Kennung hängt beim Server die
Rollenvergabe — von einem Standort aus führt der Angerufene, auch wenn er Admin
ist (`WebRTCController::callRoles`). Ginge sie unterwegs verloren, käme der
Anruf trotzdem zustande, nur eben als Gespräch ohne Führung. Der Weg lautet
jetzt:

```
Nadel / Listenzeile  ──id──▶  index.php?act=location&id=7
                                        │
                                        ▼
                       Anfrage mit Wunschzeitpunkt
                                        │
                          Guide nimmt an (Seite "Anfragen")
                                        │
                                        ▼
                          Knopf "Führung starten"
                          data-userid, data-locationid
                                        │
                                        ▼
                    rtc.startCall(userId, locationId)  ──▶  Offer mit location
```

Zwischen Klick und Anruf stehen seit dem Umbau zwei Schritte mehr — die
Anfrage und die Zusage (siehe [Die Anfrage](#-die-anfrage-statt-des-anrufs)).
Was **nicht** dazwischenkommt, ist ein zweiter Weg: Auch die zugesagte Führung
startet über denselben Knopf mit denselben zwei Kennungen.

Jede Station davon ist in `tests/client_test.js` (Abschnitt 38) und
`tests/server_test.php` festgehalten.

### Wer entscheidet was

| Datei | Aufgabe |
|---|---|
| `class/Controller/LocationController.php` | **Wer** darf was sehen und ändern, und **was** ist eine gültige Eingabe. Hier steht auch die einzige Stelle, an der entschieden wird, ob ein Aufrufer die `user_id` des Guides bekommt. |
| `class/Helper/LocationView.php` | **Wie** die Seite aussieht. Reine Funktionen: Werte rein, HTML raus — kein Zugriff auf Sitzung, Anfrage oder Datenbank. Damit lässt sich die ganze Seite prüfen, ohne eine Anmeldung nachzustellen. |
| `class/Helper/ImageStore.php` | Dateien: prüfen, umrechnen, ablegen, löschen. Kennt keine Datenbank. |
| `class/Model/LocationImage.php` | Die Zeilen dazu: welche Bilder zu welchem Standort gehören und in welcher Reihenfolge. Fasst keine Datei an. |
| `class/Helper/Languages.php` | Der Sprachkatalog. Die einzige Stelle, an der er steht. |

Die Trennung der ersten beiden ist keine Formsache: Zusammen waren es 1700
Zeilen, und die Frage "darf er das sehen" stand in der Frage "wie sieht das
aus". Jetzt bekommt die Ansicht übergeben, was sie zeigen darf, und
entscheidet nichts.

### Bearbeiten — auf derselben Seite

Der Guide sieht auf seiner eigenen Standortseite den Knopf *Bearbeiten*; er
klappt das Formular an Ort und Stelle auf. Das ist Absicht: Reihenfolge und
Auswahl der Bilder beurteilt man an der Ansicht und nicht an einer Liste von
Dateinamen.

**Die Bilder stehen im Formular in zwei Blöcken**, so wie sie auf der Seite
auch in zwei Blöcken stehen: oben das Titelbild mit dem Knopf *Zurück in die
Galerie*, darunter "Bilder vom Ort" mit den Kacheln. An jeder Kachel steht
neben Verschieben und Löschen ein Stern — *Als Titelbild*. Hochgeladen wird
weiterhin über **einen** Knopf: Welche Verwendung ein neues Bild bekommt,
entscheidet der Server (das erste wird Titelbild, jedes weitere Galerie), und
nicht der Hochladende über eine Auswahl, die er beim Aussuchen noch gar nicht
treffen kann.

Nach dem Setzen oder Abwählen eines Titelbildes **lädt die Seite neu**. Das ist
Absicht und keine Bequemlichkeit: Der Wechsel ordnet Kopf und Galerie zugleich
neu. Das im Browser nachzubauen hieße, dieselbe Aufteilungsregel ein zweites
Mal zu schreiben — einmal in `App\Helper\LocationView` und einmal in
JavaScript. Zwei Fassungen derselben Regel laufen auseinander.

**Das alte Formular taugte dafür nicht.** `set_location.html` kennt keine
Standort-ID und schickt immer an ein `INSERT`; der Dialog "Beschreibung ändern"
in der Standortliste konnte genau ein Feld von fünf. Beide sind ersetzt: Das
Anlegeformular bleibt das Anlegeformular (um die neuen Felder erweitert), der
Dialog ist entfallen, und bearbeitet wird auf der Standortseite.

**Was sich dort nicht ändern lässt: Land, Stadt und Koordinaten.** Sie hängen
an `location.city_id`; ein Punkt, den man über die Landesgrenze zieht, machte
aus "Lissabon, Portugal" eine Zeile, die nicht mehr stimmt. Ein Standort an
einem anderen Ort ist ein anderer Standort und wird über *Standort anbieten*
angelegt — dort gibt es die Karte und die Länderauswahl dafür. (Zu ändern waren
sie vorher auch nicht: Der alte Dialog konnte nur die Beschreibung.)

### Bilder: Ablage, Formate, Größen

**Sie liegen außerhalb des Document Root**, aus demselben Grund wie das
Fehlerlog: Was unter dem Webroot liegt, ist über HTTP abrufbar — und zwar auch
dann, wenn es gar kein Bild ist. Eine hochgeladene Datei ist Fremdeingabe und
darf den Webserver nie direkt erreichen. Ausgeliefert wird sie über
`index.php?act=location_image`, also durch einen Controller, der vorher prüft,
ob der Standort gesperrt ist.

```
<UPLOAD_PATH>/locations/<standort-id>/<32 Hexzeichen>.jpg     Vollansicht
<UPLOAD_PATH>/locations/<standort-id>/<32 Hexzeichen>_t.jpg   Vorschau
```

Den Pfad bestimmt `UPLOAD_PATH`; ohne den Wert greift `../uploads` eine Ebene
oberhalb des Webroots. Anders als `LOG_PATH` darf er in der `.env` stehen —
`config/uploads.php` wird erst aus einem Controller heraus geladen. Das
Verzeichnis muss dem Webserver gehören:

```bash
mkdir -p /var/lib/webrtc/uploads
chown www-data:www-data /var/lib/webrtc/uploads   # unter RHEL/CentOS: apache
chmod 750 /var/lib/webrtc/uploads
```

**Der Dateiname kommt aus dem Programm**, nicht aus dem Upload: 32 zufällige
Hexzeichen. Der Name, unter dem hochgeladen wurde, wird verworfen — er ist
Fremdeingabe (`../`, `bild.php`, Steuerzeichen) und wird nirgends gebraucht. Zu
einer Datei führt allein die Zeile in `location_image`.

**Angenommen werden JPEG, PNG und WebP**, erkannt am *Inhalt* der Datei
(`getimagesize`) und nie an der Endung oder am gemeldeten Content-Type — beide
kommen vom Browser des Hochladenden und sagen nichts. SVG ist bewusst nicht
dabei: Das ist ein Dokument mit Skriptfähigkeit, kein Bild.

**Gespeichert wird ausschließlich JPEG**, und zwar neu gezeichnet: Die Datei
wird eingelesen, in ein GD-Bild verwandelt und daraus neu geschrieben. Zwei
Gründe, und der zweite wiegt schwerer:

1. Was dabei nicht Bildpunkt ist, überlebt es nicht — kein eingebetteter
   Kommentar, kein angehängter Datenblock, keine als Bild getarnte Datei mit
   HTML- oder PHP-Anteil.
2. **In den EXIF-Daten eines Handyfotos stehen GPS-Koordinaten.** Ein Guide,
   der zuhause ein Foto aussucht, würde sonst seine Wohnadresse
   mitveröffentlichen — an einem Standort, dessen Treffpunkt er bewusst
   woanders gesetzt hat. Die im EXIF vermerkte Drehung wird vorher angewandt,
   sonst läge jedes Hochkantfoto anschließend auf der Seite.

Bezahlt wird das mit der Transparenz eines PNG (JPEG kennt keine; der Grund
wird weiß) und mit etwas Schärfe bei Schrift. Für Ortsfotos ist beides ohne
Bedeutung.

**Die Grenzen stehen an genau einer Stelle: `config/uploads.php`.**

| Wert | Vorgabe | Warum |
|---|---|---|
| `max_images_per_location` | 5 | Die Obergrenze je Standort — für die **Summe** aus Titelbild und Beispielbildern. |
| `max_file_bytes` | 8 MB | Reichlich für ein Handyfoto, klein genug, dass paralleles Hochladen den Server nicht belegt. |
| `max_source_edge` | 6000 px | **Nicht wegen des Plattenplatzes, sondern wegen des Arbeitsspeichers**: Ein GD-Bild braucht rund vier Byte je Bildpunkt. Ein Bild mit 30000 × 30000 Punkten ist als Datei ein paar hundert Kilobyte — fällt also durch jede Größenprüfung — und bringt den Prozess um. |
| `full_edge` | 1600 px | Reicht für eine bildschirmfüllende Ansicht. Größer hieße längeres Laden für eine Auflösung, die niemand sieht. |
| `thumb_width` / `thumb_height` | 480 × 320 | Fester Ausschnitt statt Einpassen: In einer Reihe gleich großer Kacheln stört ein Hochformat mehr als ein beschnittener Rand. |
| `jpeg_quality` | 82 | Vom Original nicht zu unterscheiden, rund ein Drittel kleiner als 95. |

Von dort gehen die Werte an alle drei Verbraucher — den Bildspeicher, den
Controller und den Browser (`window.locationPage.upload`, damit eine zu große
Datei gemeldet wird, *bevor* acht Megabyte übertragen sind). Im JavaScript
steht keine zweite Zahl.

### Die Obergrenze soll später je Konto gelten

Gebaut ist das **noch nicht** — es gibt eine Zahl und keine Tabelle.
Vorbereitet ist der Weg dorthin trotzdem: Gelesen wird die Grenze
ausschließlich über `App\Helper\ImageStore::maxImages($user_id)`. Der
Parameter steht schon da und wird heute nicht beachtet. Kommt die Staffelung,
bekommt genau dieser Methodenrumpf seine Abfrage — und kein Aufrufer ändert
sich. Wer die Zahl stattdessen direkt aus `config/uploads.php` liest, macht das
kaputt; `tests/server_test.php` (Abschnitt 30) hält es fest.

Dasselbe gilt für die **Verwendung**: Dass ein Standort genau ein Titelbild
hat, steht in `App\Model\LocationImage` (`ROLE_COVER`, `ROLE_GALLERY`,
`teile()`) und nicht verteilt in Controller, Ansicht und Skript. Sollen später
je Kontoart mehrere Titelbilder erlaubt sein oder Beispielbilder getrennt von
Titelbildern gezählt werden, ist das dort und in `maxImages()` zu ändern — die
Spalte `role` trägt einen Text und keine Ja/Nein-Marke, gerade damit eine
dritte Verwendung dazukommen kann, ohne dass die Tabelle wandert.

---

## 👤 Der Guide als Mensch

### Das Problem

Ein Kunde sah vom Guide **einen Benutzernamen und einen farbigen Punkt**. Auf
dieser Grundlage sollte er einen Fremden losschicken, der ihn per Video durch
eine unbekannte Stadt führt — und ihm dafür Geld geben. Für „ich vertraue
dieser Person" reicht das nicht; es ist nicht einmal eine Person.

Der Benutzername war dabei doppelt falsch: Er ist die **Anmeldekennung**. Er
geht niemanden etwas an, und als Name taugt er nichts.

### Was am Profil steht

Die Tabelle `guide_profile` gibt es seit Migration 007. Sie hielt bisher
ausschließlich die Zustimmung zur Guide-Rolle fest; seit Migration 015 trägt
sie auch das, was ihr Name verspricht.

| Feld | Spalte | Wozu |
|---|---|---|
| Anzeigename | `guide_profile.display_name` | Der Name, unter dem Kunden den Guide sehen. **Ersetzt den Benutzernamen überall dort, wo ein Kunde hinschaut.** Nicht gesetzt: dann steht dort weiterhin der Benutzername — das ist die Wahrheit über ein unausgefülltes Profil und keine Lücke. |
| Selbstbeschreibung | `guide_profile.about` | Ein paar Sätze über sich, bis 600 Zeichen. Die Standortseite zeigt davon den **ersten Satz**, die Profilseite den ganzen Text. |
| Sprachen | `guide_profile.languages` | Kürzel nach ISO 639-1 (`de,en`), derselbe Katalog wie am Standort (`App\Helper\Languages`). |
| Avatarbild | `guide_profile.avatar_file` | 32 Hexzeichen — der Basisname der Datei, nicht die Datei. Sie liegt außerhalb des Webroots. **Eines je Guide.** |
| Dabei seit | `guide_profile.joined_at` | Wird beim **ersten** Annehmen der Rolle gesetzt und danach nie wieder angefasst. |

**Warum `joined_at` neben `guide_since` steht.** `guide_since` bedeutet etwas
anderes, als sein Name vermuten lässt: `GuideRole::rememberAcceptance()` setzt
es bei *jeder* Zustimmung neu — auch dann, wenn ein langjähriger Guide bloß
eine geänderte Fassung der Bedingungen bestätigt. Für seinen Zweck ist das
richtig (es ist der Beginn des Zeitraums, den eine spätere Abrechnung
betrachtet); als Angabe „Guide seit" auf einer Kundenseite wäre es eine Lüge —
am Tag eines Bedingungswechsels wären alle Guides neu.

**Warum es die Sprachen zweimal gibt.** `location.languages` sagt, in welchen
Sprachen **diese Führung** stattfindet. `guide_profile.languages` sagt, welche
Sprachen **der Mensch** spricht. Wer vier Sprachen kann, bietet eine bestimmte
Führung vielleicht nur auf zweien an.

**Zwei Klassen, eine Zeile, nie dieselben Spalten.** `App\Model\GuideRole`
schreibt die Zustimmung (Vertragsstoff), `App\Model\GuideProfile` schreibt
das Profil. Beide zählen ihre Spalten einzeln auf und ersetzen die Zeile nie
als Ganzes: Wer die Rolle zurückgibt, behält sein Profil, und wer sein Profil
ändert, stimmt damit keinen Bedingungen zu.

### Die Profilseite

```
index.php?act=guide&id=<benutzerkennung>
```

Eine Adresse, die sich verlinken und weitergeben lässt — **auch ein Gast sieht
sie** (Recht `guide.view`), aus demselben Grund wie bei der Standortseite: Ein
geteilter Link, der beim Empfänger auf dem Anmeldeformular endet, wird nicht
weitergegeben.

**In der Adresse steht die Kennung und nicht der Benutzername.** Er soll intern
bleiben; stünde er dort, wäre er öffentlich — und zwar an genau der Stelle, die
ein Guide selbst weitergibt.

Die Seite zeigt Bild, Anzeigenamen, „Guide seit" (Monat und Jahr, nicht
taggenau), die Sprachen, die Selbstbeschreibung — und **die Standorte, die
dieser Guide anbietet**. Das ist der zweite Grund für diese Seite: Von einem
Standort führte bisher nichts zum nächsten desselben Menschen.

Sie hat sie, wer Guide ist **oder** wer Standorte anbietet, ohne die Rolle zu
tragen (ein Admin). Die zweite Bedingung ist keine Feinheit: Von jedem Standort
führt ein Verweis auf das Profil seines Anbieters, und ein Verweis, der ins
Leere zeigt, ist schlimmer als keiner. Für alle anderen antwortet sie wie für
ein Konto, das es nicht gibt.

### Das Avatarbild

Denselben Weg wie ein Standortbild, mit denselben Prüfungen: außerhalb des
Document Root abgelegt (`<UPLOAD_PATH>/guides/<user_id>/`), über einen
Controller ausgeliefert (`index.php?act=guide_avatar`), **neu gezeichnet statt
gespeichert** — damit fällt das EXIF weg und mit ihm die GPS-Koordinaten, an
denen das Foto entstanden ist.

Ein Unterschied: **beide Größen sind quadratisch.** Ein Porträt ist mal
hochkant, mal quer aufgenommen; nebeneinandergestellt ergäbe das eine unruhige
Reihe. Geschnitten wird mittig — dort steht auf einem Porträt der Kopf.

Geprüft wird das über `ImageStore::pruefe()` und `::schreibe()`: Standortbild
und Avatar teilen sich die Prüfungen, weil ein zweiter Upload-Weg mit eigenen
Prüfungen ein zweiter Weg wäre, eine davon zu vergessen — und die vergessene
wäre die, die das EXIF entfernt.

**Wer kein Bild hochlädt, bekommt seine Initialen** in der Akzentfarbe
(`App\Helper\Avatar`). Ein leerer Kreis sagt „hier fehlt etwas", zwei
Buchstaben sagen „das ist diese Person". Dieselbe Darstellung wie im
Benutzermenü der Kopfleiste — sie stammt von dort, und seit diesem Umbau baut
sie *eine* Klasse für alle vier Stellen.

### Wo der Anzeigename den Benutzernamen ersetzt

| Ort | Vorher | Jetzt |
|---|---|---|
| Standortliste, Spalte „Guide" | `user.username`, ausgeliefert an jeden Angemeldeten | `guide_name` — Anzeigename aus dem Profil, verlinkt auf das Profil. Der Benutzername wird **gar nicht mehr ausgeliefert** (`Location::selectAllLocations`). |
| Standortseite | kam nicht vor | Ein Streifen unter der Beschreibung: Bild, Anzeigename, der erste Satz der Selbstbeschreibung, als Ganzes der Weg zum Profil. |
| Profilseite | gab es nicht | Anzeigename in der Kopfzeile. |

Entschieden wird das in SQL und nicht im Browser: **Was nicht ausgeliefert
wird, kann auch nicht angezeigt werden.** Die Rückfallregel („kein
Anzeigename → Benutzername") steht an genau zwei Stellen, und beide sind
dieselbe Regel: im `COALESCE` der Listenabfrage und in
`GuideProfile::anzeigename()` für bereits geladene Zeilen.

Unverändert bleiben der Benutzername im **Call** („Anruf mit …") und im
**Chat**. Beides sind Gespräche zwischen zwei Konten und nicht die Auslage, an
der ein Kunde sich entscheidet; sie umzustellen ist ein eigener Schritt.

### Der Guide auf der Standortseite

Er steht im Inhaltsbereich **unmittelbar unter der Beschreibung**, in voller
Breite — nicht in der schmalen Randspalte und nicht in einer Fußzeile. Ein
Kunde entscheidet auf dieser Seite, ob er einen Fremden losschickt; wer dieser
Fremde ist, gehört zu dieser Entscheidung.

Die Reihenfolge ist Absicht: erst das Angebot („was bekomme ich"), dann der
Mensch („von wem"). Andersherum stünde ein Porträt zwischen Titelbild und
Beschreibung und beantwortete eine Frage, die noch niemand gestellt hat.

Gezeigt werden **drei Angaben, mehr nicht**: Bild, Anzeigename, ein Satz. Alles
Weitere steht auf der Profilseite, und dorthin führt der Streifen. Wer hier
alles zeigt, baut eine zweite Profilseite in eine Standortseite hinein.

Die Angaben kommen aus **derselben Abfrage**, die auch den Standort beschreibt
(`Location::selectOneForPage`) — keine zweite Abfrage, kein zweiter Weg zu
denselben Daten.

### Bearbeitet wird über die Kontoeinstellungen

*Mein Konto → Mein Guide-Profil*. Ein Formular für alles, das Bild
eingeschlossen; nur das Entfernen des Bildes ist ein eigener Weg, weil es das
Gegenteil von „speichern" ist. Ohne JavaScript vollständig bedienbar (normales
POST, danach eine Weiterleitung zurück).

**Nicht auf der Profilseite selbst**: Die ist das, was ein Kunde sieht, und sie
soll für den Eigentümer genauso aussehen wie für alle anderen. Er bekommt dort
nur eine Marke „Ihr Profil" und den Weg zum Formular.

**Das Bild entfernen** geht über einen eigenen Knopf beim Bild — mit
Rückfrage, danach stehen wieder die Initialen da. Sein Formular steht
**hinter** dem Hauptformular und nicht darin; der Knopf findet es über sein
`form`-Attribut. Das sieht umständlich aus und ist der einzige Weg, der
funktioniert: HTML kennt keine verschachtelten Formulare. Der Parser verwirft
das innere `<form>` ersatzlos, und sein `</form>` schließt dann das äußere —
alles, was danach im Quelltext steht, gehört zu keinem Formular mehr und wird
beim Absenden nicht mitgeschickt. Zu sehen ist davon nichts; es fällt erst an
den Daten auf. `tests/server_test.php` prüft die Regel für alle Formulare
dieser Anwendung und alle Vorlagen.

Die Rückfrage selbst hängt als `data-confirm` am Formular und wird von
`assets/js/ui.js` (`bindConfirmForms`) durch denselben Dialog geschickt wie
das Löschen eines Standorts. Ein neues Formular, das nachfragen soll, braucht
damit ein Attribut und keine Zeile JavaScript.

**Wessen Profil bearbeitet wird, steht in der Sitzung** — eine Benutzerkennung
aus der Anfrage liest der Controller nicht. Beide Routen nehmen ausschließlich
POST an.

Beim Bild ist die Reihenfolge dieselbe wie bei den Standortbildern: erst die
Datei, dann die Zeile, **dann** das alte Bild löschen. Andersherum verwiese die
Zeile auf ein Bild, das es nicht mehr gibt.

---

## ⭐ Bewertungen

### Das Problem

Ein Kunde soll einem Fremden Geld dafür geben, dass der ihn per Video durch eine unbekannte Stadt führt. Was er über diesen Menschen wusste, stammte bis hierher **ausschließlich von ihm selbst**: Anzeigename, Selbstbeschreibung, Bild. Alles davon ist wahr, wenn der Guide es so meint — und keine einzige Angabe darin ist überprüfbar.

Was fehlte, ist die andere Stimme: Was haben Kunden erlebt, die schon dort waren.

### Nur eine Richtung

**Kunden bewerten Guides. Guides bewerten keine Zuschauer.**

Das ist keine Einstellung, sondern das Schema: `tour_review` hat keine Spalte "wer bewertet wen" und keine Richtungsmarke, sondern zwei Spalten mit festen Rollen — `guide_user_id` ist der Bewertete, `customer_user_id` der Bewertende. Eine Zeile in die andere Richtung ist darin nicht darstellbar. Es gibt entsprechend auch keine Route, kein Recht und kein Formular dafür.

Der Grund ist die Asymmetrie der Sache: Der Guide bietet öffentlich eine Leistung an, über die sich jemand vor dem Kauf informieren können muss. Der Zuschauer kauft sie. Eine Note für den Käufer wäre kein Gegenstück, sondern ein Druckmittel.

### Bewertet wird eine Führung, nicht ein Guide

Die Grundlage ist `tour_request` — die Tabelle, in der seit [der Anfrage](#-die-anfrage-statt-des-anrufs) steht, welche Führungen wirklich stattgefunden haben. Jede Bewertung hängt an genau einer solchen Zeile:

* **`status = 'done'` und `started_at` gesetzt.** Ohne stattgefundene Führung entsteht keine Bewertung.
* **`UNIQUE (request_id)`** — je Führung höchstens eine. Die Regel steht in der Tabelle und nicht nur im Controller: Zwei gleichzeitige Anfragen ergeben nicht zwei Zeilen.

Geschrieben wird mit `INSERT ... SELECT` **aus** `tour_request`:

```sql
INSERT INTO tour_review (request_id, guide_user_id, customer_user_id, location_id, stars, body)
SELECT r.id, r.guide_user_id, r.customer_user_id, r.location_id, :stars, :body
  FROM tour_request r
 WHERE r.id = :request AND r.customer_user_id = :customer
   AND r.status = 'done' AND r.started_at IS NOT NULL
```

Guide, Kunde und Standort kommen damit **aus der Aufzeichnung** und nicht aus der Anfrage des Browsers; der steuert Sterne und Text bei, sonst nichts. Ein Kunde kann so weder eine fremde Führung bewerten noch eine, die nie stattgefunden hat, noch einen anderen Guide eintragen — und die Prüfung steht in der WHERE-Klausel, also dort, wo sie sich nicht umgehen lässt. Dasselbe Muster wie beim Eigentum an einem Standort.

### Die Skala

**Ganze Sterne von 1 bis 5**, dazu ein freiwilliger Text (bis 1000 Zeichen).

| Sterne | steht am Formular |
|---|---|
| 1 | Enttäuschend |
| 2 | Weniger gut |
| 3 | In Ordnung |
| 4 | Gut |
| 5 | Großartig |

Die Wörter stehen dabei, weil "3 von 5" für jeden etwas anderes heißt. **Halbe Sterne gibt es nur in der Anzeige** eines Durchschnitts, nie in einer Abgabe — wer bewertet, wählt einen von fünf Werten. `TourReview::isValidStars()` weist 3,5 deshalb ausdrücklich ab; ein `(int)` allein hätte daraus klaglos eine 3 gemacht.

**Die Skala steht an einer Stelle.** Grenzen, Wörter und Textlänge stehen in `App\Model\TourReview` und gehen von dort als `window.reviewScale` an den Browser (`App\Helper\ViewHelper`). Das Formular baut JavaScript — es erscheint nach dem Auflegen auf irgendeiner Seite —, aber es kennt keine eigenen Sternzahlen und keine eigenen Wörter.

### Wenige Bewertungen dürfen nicht wie ein Urteil aussehen

Das ist der Punkt, an dem diese Funktion einem neuen Guide schaden könnte. **„1 Bewertung, 3 Sterne" sieht aus wie ein Befund und ist eine einzelne Stimme** — und es trifft genau den, der es am wenigsten verkraftet: den, der noch keine zweite Stimme sammeln konnte.

Deshalb:

* **Ein Durchschnitt erscheint erst ab drei Bewertungen** (`TourReview::MIN_FOR_AVERAGE`). Darunter gibt das Modell ihn als `null` heraus — die Entscheidung fällt in der Abfrage und nicht in der Ansicht. Ein Wert, der einmal aus dem Modell herauskommt, erscheint irgendwann auch auf einer Seite.
* **Davor steht eine Tatsache statt einer Wertung:** „4 Führungen durchgeführt, eine davon bewertet. Für einen Durchschnitt sind es noch zu wenige — er erscheint ab 3 Bewertungen." Die Zahl der Führungen steht ohnehin in `tour_request`, sie ist überprüfbar, und sie urteilt über niemanden.
* **Die einzelnen Bewertungen stehen trotzdem darunter** — mit Text und mit ihren eigenen Sternen. Ein geschriebener Satz ist eine Stimme, und dass es eine ist, sieht der Leser; verschwiegen würde damit nur die erste gute Rückmeldung, und die braucht ein neuer Guide am dringendsten.
* **Dem Guide wird der Grund gesagt.** Auf seinem Profil steht, dass noch *n* Bewertungen fehlen — sonst hält er die fehlende Zahl für einen Fehler.

Eine geglättete Zahl (bayessches Mittel gegen einen Startwert) wäre die Alternative gewesen. Sie hätte immer eine Zahl geliefert — aber eine gerechnete, die weder Kunde noch Guide nachvollziehen kann.

### Gefragt wird, wenn der Guide beendet hat

Nicht nach dem Auflegen: Das kann „wir sind fertig" heißen oder „das Netz ist weg", und im zweiten Fall käme die Frage, während der Guide noch zurück in die Leitung will. Bewertbar ist eine Führung erst, wenn sie **zu** ist — der Guide hat beendet, oder die Frist für den Wiedereinstieg ist verstrichen (`TourRequest::conductedSql`, siehe [Auflegen ist nicht beenden](#auflegen-ist-nicht-beenden)). Gerechnet, nicht aus der Spalte gelesen: Ein vergessener Abschluss soll die Bewertung nicht bis zum nächsten Lauf eines Cronjobs blockieren.

Die Frage kommt **nicht als Dialog im Moment des Auflegens**. Auf Telefonen lädt die Seite nach dem Gesprächsende ohnehin neu (`assets/js/rtc.js`), und was in diesem Moment auf dem Bildschirm stand, wäre weg.

Stattdessen fährt sie **auf dem Heartbeat mit** — dieselbe Bauart wie die Karte, mit der der Guide seine Führung beendet, nur spiegelverkehrt: Er läuft ohnehin alle zehn Sekunden, seine Antwort trägt die älteste unbewertete Führung mit (`UserController::heartbeat` → `TourReview::pendingForCustomer`), und damit übersteht die Frage jeden Seitenwechsel und jedes Neuladen. Sie erscheint höchstens zehn Sekunden später — und das ist genau der Abstand, den „nicht aufdringlich" braucht.

**Nicht aufdringlich heißt dreierlei:**

1. Es ist eine **Karte** und kein Dialog: unten am Rand, sperrt die Seite nicht, verdunkelt nichts, nimmt niemandem die Tastatur weg.
2. Sie ist **überspringbar**, und *später* heißt später — wer sie wegklickt, bekommt sie in diesem Browser nicht wieder.
3. Sie kommt **nicht während eines Gesprächs** und nicht über der Anrufansicht.

Es kommt außerdem immer nur **eine** Frage: Wer drei unbewertete Führungen hat, bekommt nicht drei Karten hintereinander.

### Nachholen — auf der Anfragenseite

Das Überspringen ist eine Entscheidung **dieses Browsers** (`localStorage`), die Führung selbst bleibt bewertbar: Sie gehört zum Konto. Wiederfinden lässt sie sich dort, wo in dieser Anwendung alles Verpasste wieder auftaucht — auf der [Anfragenseite](#wo-der-guide-die-anfragen-sieht). Jede durchgeführte Führung trägt dort einen Knopf *Führung bewerten*, solange sie unbewertet ist; die Liste bringt die Auskunft mit (`reviewed`, `review_stars` in `TourRequest`).

Der **Guide** sieht in seiner Liste dieselbe Zeile mit den Sternen, die er bekommen hat. Einen Knopf gibt es dort nicht — er kann daran nichts ändern.

### Wo die Bewertungen stehen

| Ort | Was dort steht |
|---|---|
| **Kartenfenster** einer Nadel | eine Zeile: Sterne, Zahl und Anzahl — oder „Neu · 3 Führungen" |
| **Standortliste**, eigene sortierbare Spalte | dieselbe Zeile |
| **Standortseite**, unter dem Guide-Streifen | Durchschnitt, Anzahl und die letzten Bewertungen **zu diesem Standort** |
| **Guide-Profil**, zwischen "Über mich" und den Standorten | dasselbe **über alle Standorte dieses Guides**, jede Bewertung mit der Führung, um die es ging |

**Karte und Liste sind der Ort, an dem gewählt wird** — nicht die Standortseite, die ein Kunde erst aufruft, nachdem er sich schon entschieden hat, welchen Standort er ansieht. Dort gilt dieselbe Regel wie überall: Unterhalb der Schwelle steht kein Durchschnitt, sondern die Zahl der durchgeführten Führungen. Entschieden ist das im SQL (`TourReview::aggregateColumnsSql`) — der Server liefert den Durchschnitt gar nicht erst mit, und der Browser kennt die Schwelle nicht einmal.

Die Zahlen kommen über **zwei gruppierte Teilabfragen** in die Listen (`TourReview::aggregateJoinSql`), nicht über einen Ausdruck je Zeile: Bei fünfzig Nadeln wären das sonst fünfzig Abfragen, alle fünfzehn Sekunden.

In der Standortliste ist es eine **eigene, sortierbare Spalte** — die Liste ist ausdrücklich die Ansicht „zum Durchsuchen und Sortieren". Sortiert wird über `data-order` mit dem Zahlenwert und nicht über die Sterne im Text; **unbewertete Standorte bekommen −1** und landen am Ende, statt sich zwischen die Ein-Stern-Bewertungen zu mischen: „noch keine Bewertung" ist nicht dasselbe wie „schlecht bewertet", und genau diese Verwechslung soll die Schwelle ja verhindern.

Beide Blöcke baut dieselbe Klasse (`App\Helper\ReviewView`) — „wie sieht eine Bewertung aus" und „ab wann steht dort eine Zahl" wird einmal beantwortet. Gezeigt werden die **letzten fünf** Bewertungen mit Text; die besten oder die schlechtesten auszuwählen wäre eine Meinung, und Bewertungen ohne Text stehen ohnehin schon in Durchschnitt und Anzahl.

**Der Guide sieht seine eigenen** in derselben Form wie ein Kunde — nur die Überschrift ist an ihn gerichtet. Eine Sonderansicht wäre eine zweite Wahrheit über dieselben Zeilen, und die eine, auf die es ankommt, ist die, die seine Kunden lesen.

### Kein Name dabei

Bei einer Bewertung stehen die Sterne, der Text, der **Monat** und — auf dem Guide-Profil — die Führung, um die es ging. **Kein Name.**

Ein Kunde hat in dieser Anwendung keinen Anzeigenamen, sondern nur einen Benutzernamen, und der ist die Anmeldekennung: Er gehört nicht auf eine Seite, die jeder aufrufen kann (dieselbe Regel wie beim [Guide-Profil](#-der-guide-als-mensch), dessen Adresse deshalb die Kennung trägt und nicht den Namen). Der Monat statt des Tages aus demselben Grund wie bei „Guide seit" — der Tag beantwortet keine Frage, die jemand hat.

Der Text selbst ist Fremdeingabe und geht durch `ViewHelper::esc()`: spitze Klammern **und die drei Rauten**, mit denen diese Anwendung ihre Platzhalter baut.

### Der Guide kann nichts löschen — die Moderation blendet aus

Eine Bewertung, die der Bewertete ändern oder löschen kann, ist keine Auskunft mehr über ihn, sondern eine von ihm. Der Guide hat deshalb **kein** Recht darauf, auch nicht auf seinem eigenen Profil.

Für eine Beleidigung oder eine offensichtlich falsche Zuordnung entfernt ein **Admin** sie (Recht `review.remove`). Der Knopf steht unauffällig an jeder Bewertung, dort wo sie ohnehin zu lesen ist — eine eigene Moderationsseite für einen Ausnahmefall wäre eine Seite zu viel.

**Entfernt heißt ausgeblendet, nicht gelöscht.** Gesetzt werden `removed_at`, `removed_by` und `removed_reason`; die Zeile bleibt stehen. Sie zählt danach nirgends mehr mit — nicht im Durchschnitt, nicht in der Anzahl, in keiner Liste —, aber es bleibt nachvollziehbar, dass es sie gab und wer sie entfernt hat. Dasselbe Muster wie beim [Sperren eines Standorts](#moderation), wo auch nichts verschwindet.

**Danach ist nicht wieder frei:** Der eindeutige Schlüssel gilt weiter, dieselbe Führung lässt sich nicht erneut bewerten. Sonst wäre das Entfernen eine Einladung, dasselbe noch einmal zu schreiben.

### Keine Fremdschlüssel

Wie bei `tour_request` und aus demselben Grund: Eine abgegebene Bewertung bleibt abgegeben, auch wenn der Standort später gelöscht wird. Mit `ON DELETE CASCADE` wäre sie beim ersten gelöschten Standort weg, mit `RESTRICT` ließe sich ein Standort nie wieder löschen. Gelesen wird deshalb mit `LEFT JOIN`, und die Anzeige rechnet damit, dass der Titel fehlen kann.

---

## 💬 Der Chat: über einen Standort

### Das Problem

Zwei Befunde, die derselbe Satz löst.

**Der Chat war für Kunden nicht erreichbar.** Ein Chat ließ sich ausschließlich über die **Benutzerliste** beginnen — und die sieht nur der Admin (Recht `user.list`). Ein Kunde hatte damit gar keinen Weg zu seinem Guide, obwohl genau dort die Fragen entstehen, die vor einer Führung zu klären sind: Wo genau ist der Treffpunkt? Ginge auch Samstag früh? Ist das mit einem Kinderwagen machbar?

**Und er war auf keine Beziehung eingeschränkt.** Die Route `chat_start` nahm eine **beliebige Kontokennung** entgegen. Wer die Route kannte, konnte jedem Konto der Plattform eine Nachricht ins Postfach legen; die Kennungen sind fortlaufend, ein Durchzählen genügte. Die Ratengrenze aus `config/limits.php` dagegen war eine Obergrenze gegen die Masse und keine Antwort auf die Frage, **wer wen überhaupt** anschreiben darf.

### Die Regel

**Ein Chat entsteht über einen Standort.** Der Kunde schreibt den Guide von dessen Standortseite aus an; wen er anschreibt, sagt der **Standort** und nicht die Anfrage. `startChat()` nimmt eine Standortkennung entgegen und holt sich den Guide selbst dazu (`Location::guideIdOf()`).

Damit ist beides zugleich gelöst: Der Chat ist von der Standortseite aus erreichbar, und es gibt keinen Parameter mehr, mit dem sich ein beliebiges Konto anschreiben ließe. Wer Standortkennungen durchzählt, landet bei den Guides öffentlich angebotener Standorte — also genau bei denen, die Rückfragen bekommen wollen.

Drei Dinge prüft der Server, und keines davon kann eine Rechtetabelle wissen:

| | |
|---|---|
| Gibt es den Standort, und ist er **nicht gesperrt**? | Beides meldet `guideIdOf()` mit `null`, und die Antwort lautet in beiden Fällen **wörtlich gleich** — auch Standortkennungen sind fortlaufend. |
| Ist der Aufrufer **nicht selbst der Guide**? | Sich selbst schreibt niemand an. |
| Die **Bremse** | Unverändert 60 je Stunde: `ui_chat.js` ruft die Route bei jedem Öffnen eines Fensters auf, es ist ein `findOrCreate`. |

### Der Direktzugang bleibt beim Admin

Er ist der einzige, der die Benutzerliste sieht, und er muss auch ein Konto erreichen können, das keinen Standort anbietet — einen Zuschauer etwa. Dafür gibt es eine **eigene Route** (`chat_start_direct`) mit einem **eigenen Recht** (`chat.start_direct`), und dieses Recht hat nur er. Ein Guide hat es nicht: Von sich aus ein fremdes Konto anzuschreiben ist genau das, was verschwinden sollte.

Eine eigene Route und kein Sonderfall im Controller: Über den Zugang entscheidet `index.php` anhand der Rechtetabelle, und ein „wenn Admin, dann anders" mitten im Controller wäre eine zweite Rechteentscheidung an einer Stelle, an der niemand sie sucht.

### Ein Chat je Paar, nicht je Standort

Bietet derselbe Guide drei Standorte an und fragt derselbe Kunde zu allen dreien, bleibt es **ein Gespräch**. Die Spalte `chat.location_id` trägt dann den Standort des Erstkontakts — sie ist die **Herkunft**, nicht das Thema, und beantwortet die Frage, warum diese beiden Konten miteinander reden dürfen.

`NULL` heißt „ohne Standort": ein Direktchat des Admins oder ein Chat aus der Zeit vor Migration `019`. Die bleiben lesbar — eine Nachricht, die jemand geschrieben hat, wird nicht dadurch ungeschehen, dass die Regel sich ändert. Der Fremdschlüssel ist `ON DELETE SET NULL`: Löscht ein Guide seinen Standort, verschwindet die Herkunft, aber nicht das Gespräch.

### Annehmen und Ablehnen entfallen

Ein Chat war vorher erst eine **Einladung**: Der Angeschriebene bekam ein Fenster mit „X möchte mit Ihnen chatten" und zwei Knöpfen, und erst nach dem Annehmen gab es überhaupt ein Eingabefeld. Vier Gründe, warum das hier weg ist:

1. **Sie schützte vor Fremden** — und genau diesen Fremden gibt es nicht mehr. Ein Chat entsteht nur zwischen einem Kunden und dem Guide eines Standorts, den dieser Guide selbst öffentlich angeboten hat. Wer Standorte anbietet, will Rückfragen bekommen.
2. **Sie blockierte, was sie schützen sollte.** Der Kunde konnte erst schreiben, *nachdem* der Guide angenommen hatte — der Guide entschied also über einen bloßen Namen, ohne zu wissen, worum es geht. Eine Frage mit Inhalt („Geht Samstag 14 Uhr?") lässt sich beurteilen, ein Name allein nicht.
3. **Sie war keine Sperre.** `Chat::findOrCreate()` belebte einen abgelehnten Chat beim nächsten Aufruf wieder. „Ablehnen" kostete den anderen einen Klick und sonst nichts. Eine echte Sperre gehört auf die Ebene „dieser Kunde nicht mehr" und nicht auf die erste Nachricht.
4. **Sie war die einzige Stelle, die so arbeitet.** Eine [Anfrage](#-die-anfrage-statt-des-anrufs) trägt einen Wunschzeitpunkt, wenn der Guide über sie entscheidet. Die Chateinladung war dasselbe Gespräch ohne den Inhalt.

Mit den Spalten `is_active` und `pending_for` sind entfallen: die Routen `chat_accept` und `chat_decline`, das Recht `chat.answer`, die Methoden `Chat::setActive()`, `::checkIfActive()` und `::getInvitations()` sowie das Einladungs-Polling im Browser.

`chat.deleted` **bleibt** — das ist etwas anderes: „beendet/weggeräumt" gegenüber „noch nicht angenommen". Der Verlauf einer beendeten Unterhaltung steht weiterhin unter „Alle Chats".

### Dass eine Nachricht da ist, sieht man in der Kopfleiste

Ein Guide sah eine Rückfrage bisher nur dann, wenn zufällig gerade ein Chatfenster offen war — die Fenster baut `ui_chat.js`, und wer die Seite gewechselt oder den Tab im Hintergrund liegen hatte, erfuhr nichts.

Der **Nachrichtenzähler** steht deshalb dort, wo auch der [Anfragenzähler](#wo-der-guide-die-anfragen-sieht) steht: in der Kopfleiste, auf jeder Seite. Er sagt dasselbe wie jener, nur über etwas anderes — „hier wartet etwas auf dich" — und führt auf die Chatübersicht.

* **Eine Zahl, keine drei.** Beim Anfragenzähler sind es drei, weil dort drei verschiedene Dinge warten. Hier wartet nur eines: ungelesene Nachrichten, über alle nicht beendeten Chats hinweg. Gezählt werden **Nachrichten** und keine Gespräche — „drei ungelesene" sagt mehr als „in einem Chat wartet etwas".
* **Für beide Seiten.** Er hängt am Recht `chat.list` und nicht an `location.offer`: Der Kunde bekommt die Antwort auf seine Frage, und die soll er genauso wenig verpassen wie der Guide die Frage.
* **Er fährt auf dem Heartbeat mit** (`UserController::heartbeat`), wie der Anfragenzähler und die Bereitschaft. Eine eigene Schleife daneben wäre derselbe Weg noch einmal.
* **Serverseitig mit seinem Stand ausgeliefert.** Wer die Seite ohne Skript öffnet, sieht trotzdem, dass etwas ansteht — nur nachgezogen wird die Zahl dann nicht.

Migration `019` legt dafür den Index `chat_message.ungelesen (chat_id, seen, sender_id)` an: Die Zählung läuft ab jetzt bei **jedem** Heartbeat, also alle zehn Sekunden je angemeldetem Konto, und nicht mehr nur beim Öffnen der Chatliste.

### Wo der Knopf steht

Auf der Standortseite, im Kasten der Handlung — **unter** dem Anfrageformular und über den üblichen Zeiten, abgesetzt durch dieselbe Linie. Der übliche Weg ist die Anfrage; wer vorher etwas wissen will, findet es an der zweiten Stelle, aber an derselben Stelle, an der er sich ohnehin entscheidet. Er ist deshalb `btn-secondary` und nicht `btn-primary`.

Er steht dort **unabhängig davon**, was darüber steht: ob noch gar nichts läuft, ob eine Anfrage offen ist oder ob der Guide zugesagt hat. Eine Rückfrage ist in allen drei Fällen sinnvoll, in den letzten beiden sogar am ehesten.

Wer ihn nicht bekommt: der **Eigentümer** (sich selbst schreibt niemand an), ein **gesperrter** Standort (von dort beginnt nichts, auch kein Gespräch) und der **Gast** (ihm fehlt die Kennung des Guides, und der Anmeldehinweis steht bereits unmittelbar darüber — zweimal derselbe Satz untereinander liest sich wie ein Fehler).

---

## 🛠️ Der Verwaltungsbereich

Die Verwaltungsfunktionen lagen bis zu diesem Umbau **als Sonderfälle in der Kundenoberfläche**. Das war an fünf Stellen sichtbar:

* Die **Standortübersicht** bekam für einen Betrachter mit dem Recht `location.block` zwei zusätzliche Symbolknöpfe *und* zusätzliche Zeilen — dieselbe Route `get_locations` lieferte ihm die gesperrten Standorte mit.
* An **jeder Bewertung** auf der Standortseite und auf dem Guide-Profil klebte für ihn ein *Entfernen*.
* Das **Guide-Profil** zeigte ihm gesperrte Standorte seines Anbieters, allen anderen nicht.
* Im **Kontomenü** stand ein Eintrag *Benutzerliste*, den sonst niemand sah.
* Die Route `admin` führte auf eine Seite, die eine Zeile Text ausgab (*„Willkommen im Admin Panel"*) und auf die nirgends verwiesen wurde.

Jede dieser Stellen war ein *„wenn Admin, dann anders"* mitten in einer Seite, die für Kunden gebaut ist — und damit eine Gelegenheit, beim nächsten Umbau etwas sichtbar zu machen, was niemand sehen sollte. **Die Kundenoberfläche kennt jetzt keinen Admin mehr.**

### Fünf Seiten hinter einer eigenen Adresse

| Route | Seite | Recht |
|---|---|---|
| `admin` | Übersicht — der Einstieg | `system.admin` |
| `list_user` / `manage_user` / `delete_user` | Benutzer, Direktchat und Direktanruf | `user.list` / `user.manage` / `user.delete` |
| `admin_requests` | Anfragen und Führungen — die Arbeitsvorräte | `request.list_all` |
| `admin_locations` | Standorte sperren und freigeben | `location.block` |
| `admin_reviews` | Bewertungen entfernen | `review.remove` |

**Ein Recht je Seite**, und das ist keine Umständlichkeit: Jede trägt genau das Recht, das man für die Handlung braucht, die dort stattfindet. Heute hat alle nur der Admin. Käme eine reine Moderationsrolle dazu, bekäme sie die Seite, zu der ihr Recht passt — und die Navigation zeigte ihr auch nur diese (`AdminView::navHtml`), ohne dass irgendwo etwas nachzuziehen wäre.

`request.list_all` ist dabei ausdrücklich **nicht** `request.list`: Das zweite hat jedes angemeldete Konto, weil jeder seine eigenen Anfragen sehen muss. Das erste gibt Einblick in fremde Vorgänge — wer mit wem wann verabredet war — und gehört nur dorthin, wo jemand dafür einen Grund hat. **Es erlaubt nur zu sehen:** Angenommen, abgelehnt und beendet wird weiterhin ausschließlich von den Beteiligten.

**Der Bereich zeigt; geschrieben wird über die Routen, die es schon gab** — `block_location`, `unblock_location`, `review_remove`, `manage_user`, `delete_user`. `AdminController` greift selbst nie zur Datenbank. Ein zweiter Schreibweg *„für den Adminbereich"* wäre genau die Doppelung, wegen der es den Bereich gibt.

### Die Übersicht: zuerst die Arbeit, dann der Bestand

**Zwei Blöcke, und die Reihenfolge ist der Punkt.** Oben steht, was Aufmerksamkeit braucht, darunter, wie groß der Laden ist. Andersherum sähe eine Aufgabe aus wie eine Bestandszahl: „42 Konten" nimmt man zur Kenntnis, „3 hängende Führungen" soll jemanden dazu bringen, etwas zu tun.

**Braucht Aufmerksamkeit** — fünf Arbeitsvorräte, und alle fünf fielen vorher an keiner Stelle auf:

| Vorrat | Was er bedeutet | Wo er sich abarbeiten lässt |
|---|---|---|
| **Führungen hängen** | Begonnen und von niemandem beendet. Solange das so bleibt, steht beim Kunden der Startknopf, und die Bewertung wird nicht fällig. Der Guide sieht das in seiner Kopfleiste — aber nur seine eigenen und nur, solange er die Seite offen hat. | `admin_requests&filter=haengend` |
| **Anfragen ohne Antwort** | Verfallen, ohne dass der Guide zu- oder abgesagt hat. Der Kunde hat gewartet und nichts bekommen; gemerkt hat das bisher nur er. | `admin_requests&filter=unbeantwortet` |
| **Standorte gesperrt** | Ein Vorgang, den jemand eröffnet hat und den jemand wieder schließen muss — oder bestätigen. | `admin_locations&filter=gesperrt` |
| **Angebote unvollständig** | Ohne Nadel auf der Karte, ohne Bild, ohne Titel, ohne ausführliche Beschreibung oder ohne übliche Zeiten. Für den Guide sieht das fertig aus — er weiß ja, was er anbietet. | `admin_locations&filter=unvollstaendig` |
| **Konten hängen auf „online"** | Seit über einer Viertelstunde kein Lebenszeichen, trotzdem nicht offline gesetzt: `cron/check_online_status.php` läuft nicht. | `list_user` |

**Der letzte ist kein Vorgang, sondern ein Befund über die Installation** — hier ist nichts abzuarbeiten, hier ist etwas einzurichten. Er steht deshalb zuletzt, und er steht überhaupt dort, weil er sonst nirgends auffällt: `check_online_status.php` ist die einzige Stelle, die `user_status` je auf `offline` setzt. Läuft der Job nicht, bleibt jedes Konto für immer online. Die **Karte** lügt dabei nicht mit — sie verlangt zusätzlich eine laufende Bereitschaft, und die läuft von selbst ab —, und genau deshalb merkt es niemand. Die **Benutzerliste** zeigt dann die ganze Plattform als erreichbar, mit offenem Anrufknopf; dorthin führt die Zahl.

Gerechnet wird gegen ein **Vielfaches** des Offline-Timeouts (`AdminStats::CRON_FAKTOR`, 20 × 45 s = 15 min) und nicht gegen den Timeout selbst: Der ist so knapp bemessen, dass zwischen zwei Läufen des Jobs ständig Konten darüber liegen. Eine Zahl, die bei laufendem Cronjob dauernd ungleich null ist, wäre kein Vorrat, sondern Rauschen.

**Was „unvollständig" heißt**, steht als eine Bedingung in `Location::unvollstaendigSql()` — fünf Dinge, und jedes einzelne kostet den Guide Kunden: keine Koordinaten (keine Nadel, und die Karte ist der Einstieg), kein Titel (Altbestand vor Migration 011), keine ausführliche Beschreibung (die Standortseite ist die Entscheidungsseite), keine üblichen Zeiten (dann weiß niemand, wann sich eine Anfrage lohnt) und kein Bild. Dauer und Sprachen zählen **nicht** mit: Beide haben eine brauchbare Vorgabe. Was an einem Standort fehlt, steht in **jeder** Zeile der Standortliste und nicht nur im dritten Filter — ein Standort kann gesperrt *und* unvollständig sein.

**Gezeigt wird nur, was offen ist.** Eine Zeile mit einer Null, die jeden Tag dasteht, erzieht dazu, den ganzen Block zu überlesen — und dann fällt die Vier daneben auch nicht mehr auf. Ist nichts offen, steht dort ein Satz, der aufzählt, was geprüft wurde: Erst damit ist die Leere eine Auskunft und nicht bloß ein leerer Kasten. Neben jeder Zahl steht, **warum** sie zählt; ohne das ist sie kein Auftrag, sondern ein Rätsel.

Ein Vorrat, der sich **nicht abhaken lässt**, bekommt ein Zeitfenster. Eine unbeantwortete Anfrage bleibt für immer unbeantwortet — ohne Fenster wäre die Zahl eine, die nur wächst und die dann niemand mehr ansieht; sie reicht deshalb 14 Tage zurück (`TourRequest::VORRAT_TAGE`, so lang wie der maximale Vorlauf einer Anfrage). Eine hängende Führung braucht keines: Sie löst sich nach der Frist von selbst auf.

**Bestand** — vier Kacheln, alle gleichrangig, keine hervorgehoben und keine mit einem Verweis: **Konten** (gesamt, nach Rollen, neu in 7 Tagen), **Standorte** (gesamt, wie viele Konten anbieten, wie viele gesperrt sind), **Führungen** (durchgeführt in 30 Tagen, insgesamt, wie viele laufen) und **Bewertungen** (sichtbare, Durchschnitt, entfernte).

Gezählt wird in `App\Model\AdminStats`, und die schwierigen Bedingungen kommen aus den Modellen, denen sie gehören: Was *durchgeführt* heißt, steht in `TourRequest::conductedSql()` — eine vergessene Beendigung zählt nach Ablauf der Frist trotzdem mit —, was *läuft gerade* in `::runningSql()`, was *unbeantwortet* heißt in `::unansweredSql()`. Eine zweite Fassung dieser Bedingungen wäre die, die beim nächsten Umbau vergessen wird und die Übersicht andere Zahlen zeigen ließe als die Anfragenseite.

### Die Anfragenliste — und was „abarbeiten" hier heißt

Vier Ansichten: *Hängende Führungen*, *Ohne Antwort*, *Offen* (was gerade auf eine Antwort wartet und noch kann — kein Vorrat, sondern der Blick nach vorn) und *Alle*. Die Vorgabe ist *Hängende Führungen*: Wer die Seite aufruft, kommt von der Übersicht und sucht die Arbeit, nicht das Archiv.

**Die Verwaltung greift in eine Verabredung nicht ein.** Sie nimmt keine Anfrage an, lehnt keine ab und beendet keine fremde Führung — dafür gibt es kein Recht und soll es keines geben: Was zwischen einem Guide und seinem Kunden ausgemacht ist, kann ein Dritter nicht abschließen, ohne zu wissen, ob es stattgefunden hat.

**Abgearbeitet wird durch Ansprechen.** Jede Zeile trägt den Chatknopf zum Guide — denselben wie die Benutzerliste, mit derselben Route (`chat_start_direct`). Das ist die Handlung, die einen dieser Vorgänge wirklich auflöst: *„Deine Führung von heute Mittag läuft noch, magst du sie beenden?"* oder *„Bei dir sind drei Anfragen verfallen — passt der Standort noch?"*.

Die Zeile zeigt **beide Seiten der Verabredung** mit Namen. In der Liste des Guides steht ein Partnername, weil er sein eines Gegenüber kennt; hier braucht es beide — sonst lässt sich nicht sehen, ob dieselben zwei Konten dreimal aneinander vorbeigelaufen sind.

### Die Listen für Standorte und Bewertungen

Beide sind **reine Serverseiten ohne DataTables**, mit einem Umschalter statt einer Volltextsuche:

* **Standorte** — alle, auch die eigenen des Betrachters, gesperrte zuerst, mit Sperrgrund und Zeitpunkt. Filter: *Alle* / *Nur gesperrte*. Der Titel führt auf die **normale Standortseite**: Wer über eine Freigabe entscheidet, soll den Standort so ansehen, wie er angeboten wird, und nicht in einer Sonderansicht. Dass ein gesperrter Standort für die Moderation überhaupt aufgeht, entscheidet weiterhin `LocationController::showLocationPage` anhand von `location.block` — das ist Zugang, keine Sonderanzeige.
* **Bewertungen** — auch die **ohne Text** und auch die **entfernten**, mit Guide *und Kunde* im Klartext. Filter: *Alle* / *Sichtbar* / *1–2 Sterne* / *Entfernt*.

Der Benutzername des Kunden ist der Unterschied zwischen einer öffentlichen Seite und einer Verwaltung: Auf der Standortseite steht er ausdrücklich nicht (er ist die Anmeldekennung), für eine Beschwerde ist *„kommen die drei Ein-Stern-Wertungen alle vom selben Konto"* aber genau die Frage, auf die es ankommt. Deshalb sind es **zwei Abfragen** — `TourReview::letzte()` für die öffentlichen Seiten, `TourReview::allForAdmin()` für die Verwaltung — und nicht eine mit einem Schalter.

### Wie er aussieht

**Dichter, aber nicht anders.** Schmale Zeilen, kleine Schrift, ein oben festklebender Tabellenkopf, Tabellen statt Karten. Jede Farbe, jeder Abstand und jede Schriftgröße in `assets/css/admin.css` kommt aus den Variablen von `theme.css` — der Bereich wechselt das **Farbprofil** des Kontos mit, ohne dass dafür eine einzige Regel dort stünde. Ein Test hält das fest: Eine ausgeschriebene Farbe in dieser Datei lässt ihn fehlschlagen.

**Grün und Gelb kommen nicht vor.** Sie bedeuten auf der Karte *„Guide verfügbar"* und *„im Gespräch"*; in einer Tabelle mit fünfhundert Zeilen wären sie ein Muster ohne Aussage. Zustände stehen als Wort, unterschieden über Form und Gewicht. Rot gibt es an genau zwei Stellen: an einer Sperre und an einer entfernten Bewertung.

Die **Kopfleiste bleibt** dieselbe wie überall — samt Anfragen- und Nachrichtenzähler: Ein Admin ist anderswo Kunde, und seine eigenen Anfragen soll er auch hier nicht verpassen. Darunter liegt die Navigation des Bereichs.

---

## 📧 Zwei Schalter für die E-Mail

### Das Problem

Mailversand und E-Mail-Bestätigung waren im Code **auskommentiert**. Die
Begründung stand jeweils daneben und war nachvollziehbar: *„Deaktiviert lassen
solange kein eigener SMTP SERVER"*. Der Preis dafür war es nicht.

Auskommentierter Code ist kein ausgeschalteter Code — er ist Code, den kein
Übersetzer mehr ansieht, kein Test mehr durchläuft und niemand mehr
mitpflegt. Was daraus wird, ließ sich an genau diesen drei Stellen ablesen:

* **`SignupController`** — `//(new EmailVerificationController)::sendVerification($user_id);`
  Der Aufruf war **syntaktisch falsch** (`::` auf einer Instanz für eine
  Instanzmethode). Er hätte sich beim Wiedereinschalten nicht einmal starten
  lassen. Dazu kam, dass beim Auskommentieren die Zuweisung `$out = …`
  mitverschwunden war — der Erfolgspfad der Registrierung gab eine
  undefinierte Variable aus.
* **`LoginController`** — die Verifizierungspflicht, samt Link
  *„Email erneut senden!"* auf `index.php?act=send_email_verify`. Dieser Link
  **konnte nicht funktionieren**: Die Route trägt das Recht
  `auth.email_verify_send`, und das hat die Rolle *Gast* nicht. Wer abgewiesen
  wurde, kam auch nicht an eine neue Mail — er kam an das Anmeldeformular
  zurück, von dem er gerade abgewiesen worden war.
* **`SettingsController`** — die Anzeige des Bestätigungsstands, abgesichert
  mit `method_exists($user, 'getEmailVerified')`. **Den Getter gab es nicht**,
  und der Konstruktor lud das Feld gar nicht erst. Eingeschaltet hätte die
  Zeile also weiterhin nichts angezeigt.

Drei Blöcke, drei Fehler, die niemandem auffielen — weil sie nie liefen.

### Zwei Einstellungen, nicht eine

```
MAIL_ENABLED=0          # Vorgabe: an
MAIL_VERIFY_REQUIRED=0  # Vorgabe: aus
```

Gelesen in [`class/Helper/MailGate.php`](class/Helper/MailGate.php). Erlaubt
sind `1/true/on/yes/ja` und `0/false/off/no/nein`; alles andere gilt als nicht
gesetzt und wird protokolliert — ein Vertipper stellt nicht stillschweigend
den Mailversand ab.

**Die Vorgaben sind „wie bisher".** Eine bestehende Installation, die ihre
`.env` nicht anfasst, merkt von den Schaltern nichts: Es wird verschickt, und
niemand wird ausgesperrt.

**`MAIL_ENABLED` — wird tatsächlich verschickt?**

Aus heißt *nicht* „der Weg fällt weg". Der gesamte Ablauf läuft unverändert:
Token anlegen, Link bauen, Bestätigungsseite ausgeben, Bremse zählen lassen.
Nur die Verbindung zum SMTP-Server unterbleibt — stattdessen landet die
**vollständige Mail im Logfile**, mitsamt Link:

```
MAIL_ENABLED=aus - diese E-Mail wurde NICHT verschickt:
  An:      d***k@example.com
  Betreff: E-Mail-Adresse bestätigen
  Text:
    Hallo,
    Bitte bestätige deine E-Mail durch Klick auf diesen Link:
    https://localhost/rctproj/index.php?act=verify_email&token=…
```

Genau dort holt sich der Entwickler den Bestätigungs- bzw. Reset-Link, ohne
dass ein SMTP-Server erreichbar sein muss. Die Empfängeradresse bleibt
**maskiert** — sie steht in keinem Log dieser Anwendung im Klartext, und ein
ausgeschalteter Versand ist kein Grund davon abzuweichen; für den Link braucht
man sie nicht.

Der Schalter sitzt in `Email::sendMail()` und damit an der **einen** Stelle,
durch die jede Mail muss. Vier Aufrufer, die ihn jeder für sich abfragen,
wären vier Antworten — und beim fünften vergessen. Die Aufrufer merken vom
ausgeschalteten Versand nichts: Sie bekommen `true` wie bei einer
abgegebenen Mail. Anders wäre der Passwort-Reset abgebrochen, obwohl der Link
benutzbar im Log steht.

**`MAIL_VERIFY_REQUIRED` — Pflicht zur Bestätigung?**

An heißt: Ein Konto mit unbestätigter Adresse darf sich anmelden und alles
lesen, aber **nicht anfragen, nicht chatten und nichts hochladen**.

| Route | |
|---|---|
| `request_create` | eine Führung anfragen |
| `chat_start`, `chat_start_direct`, `chat_send_message` | einen Chat eröffnen und schreiben |
| `upload_location_image`, `guide_profile_save` | Bilder hochladen |

Die Liste steht als `MailGate::PFLICHTROUTEN` an einer Stelle, geprüft wird
sie in `index.php` — **unmittelbar hinter der Rechteprüfung** und damit dort,
wo in dieser Anwendung jede Zugangsentscheidung fällt. Verteilt auf sechs
Controller wäre sie beim siebten Endpunkt vergessen.

Aufgezählt werden **Routen und keine Rechte**, obwohl die Rechtetabelle der
naheliegende Ort wäre: `location.edit_own` trägt sowohl das Hochladen eines
Bildes als auch das Ändern des Beschreibungstextes. Über das Recht gesperrt
wäre ein unbestätigtes Konto also auch seine eigenen Texte nicht mehr los —
gemeint ist aber nur das Hochladen.

**Lesen bleibt frei**, im Chat wie überall: Wer schon angeschrieben wurde,
soll die Antwort sehen können, während seine Bestätigung noch aussteht. Und
das **Beantworten** einer Anfrage steht bewusst nicht in der Liste: Ein Guide,
der zusagt, lässt sich auf einen Termin ein, den ein anderer gesetzt hat — ihn
dabei zu sperren träfe den anfragenden Kunden.

### Die Anmeldung sperrt sie ausdrücklich nicht

Der auskommentierte Block im `LoginController` brach die Anmeldung ab. Genau
das geht nicht, und der Grund steht oben: Der Weg zu einer neuen
Bestätigungsmail setzt eine Anmeldung voraus. Eine Sperre an der Anmeldung
wäre eine Sackgasse ohne Ausgang.

Stattdessen kommt der Nutzer herein und findet **auf jeder Seite** einen
Hinweisstreifen über dem Inhalt — mit dem Knopf *„Bestätigungsmail senden"*.
Er steht im Layout und nicht in den einzelnen Seiten, weil er auf jeder stehen
soll, auch auf denen, die mit Anfragen, Chat und Bildern nichts zu tun haben.
Auf der Einstellungsseite steht derselbe Knopf noch einmal neben dem Stand der
Adresse.

Der Login prüft trotzdem — er schreibt den Fall ins Log. Das beantwortet die
Frage, die beim Einschalten der Pflicht als erste kommt: *Wie viele
Bestandskonten sind eigentlich betroffen?*

### Warum getrennt

Weil die beiden zu verschiedenen Zeitpunkten scharf werden. In dem Moment, in
dem der Versand angeht, hat **kein einziges Bestandskonto** eine bestätigte
Adresse — es wurde ja nie eine Bestätigungsmail verschickt. Ein gemeinsamer
Schalter würde mit dem ersten Umlegen alle aussperren.

### Der Weg auf einen echten Server

1. `SMTP_*` und `APP_BASE_URL` eintragen, **`MAIL_ENABLED=1`**,
   `MAIL_VERIFY_REQUIRED` weiter aus. Ab jetzt bekommt jede neue Registrierung
   ihre Bestätigungsmail, und jedes Bestandskonto kann sich über die
   Einstellungsseite eine schicken lassen.
2. Eine Frist abwarten und den Stand prüfen:
   ```sql
   SELECT COUNT(*) FROM user WHERE deleted = 0 AND email_verified = 0;
   ```
   Im Log stehen die betroffenen Anmeldungen mit Kennung
   (*„Anmeldung ohne bestaetigte E-Mail-Adresse"*).
3. **Erst dann `MAIL_VERIFY_REQUIRED=1`.** Vorher prüfen, ob das eigene
   Administrationskonto bestätigt ist — **es gibt keine Ausnahme für Rollen**.
   Notfalls von Hand: `UPDATE user SET email_verified = 1 WHERE id = <ID>;`

`APP_BASE_URL` muss **auch bei ausgeschaltetem Versand** gesetzt sein: Ohne
brauchbare Basisadresse wird kein Token angelegt und folglich auch nichts
protokolliert — ein falscher Link ist schlimmer als keine Mail.

---

## 🧷 Was ohne JavaScript wegfällt, darf kein Weg sein

Auf der Kontoseite standen ein Knopf mit `style="display:none"` und ein Bereich mit `style="display:none"`. **Sichtbar** wurden beide erst, wenn ein Skript lief — `$('#showOwnLocationsBtn').show()`. Blieb das aus (eine Bibliothek, die nicht lädt; ein anderes Modul, das vorher wirft), verlor ein Guide den Weg zu seinen **eigenen Standorten** vollständig: kein Knopf, kein Bereich, keine Meldung. Er hätte nicht einmal gemerkt, dass etwas fehlt.

Das ist jetzt ein `<details>`/`<summary>` — dieselbe Antwort wie beim Benutzermenü der Kopfleiste: Es lässt sich mit der Tastatur bedienen und geht auch dann auf, wenn kein Skript geladen wurde. `locations_table.js` hängt sich nur noch in das `toggle`-Ereignis ein, um die **Zeilen** nachzuladen — und wenn es das nicht tut, steht dort eine leere Tabelle statt gar nichts.

**Die Regel dahinter** gilt in dieser Anwendung schon länger und ist hier nur nachgezogen: Was ein Skript einblenden muss, ist ohne Skript weg. Der Bereitschaftsschalter und die beiden Zähler der Kopfleiste werden deshalb serverseitig mit ihrem Zustand ausgeliefert; nachgezogen wird nur die Zahl. Ein Test hält das für die eigenen Standorte fest: kein `display:none` auf dem Weg dorthin, und im Initialisierungsblock von `locations_table.js` kein `.show()`.

---

## 🗑️ Ein gelöschtes Konto verschwindet

**Löschen setzt nur ein Kennzeichen.** `User::del_it()` schreibt `deleted = 1`; die Zeile bleibt stehen, damit vergangene Führungen, Bewertungen und spätere Abrechnungen nachvollziehbar bleiben. Der Fremdschlüssel hilft dabei nicht: `ON DELETE CASCADE` greift nur bei einem echten `DELETE`, und das findet nie statt.

**Daraus folgt eine Pflicht, die vorher an fast allen Stellen fehlte:** Jede Abfrage, die etwas an *andere* ausliefert, muss das Kennzeichen selbst prüfen. Ein gelöschtes Konto behielt sonst seine Nadeln auf der Karte, seine Standortseiten, seine Bilder und sein Profil — und anrufbar und anschreibbar war es auch noch. Das ist nicht nur falsch, es ist datenschutzrechtlich nicht haltbar.

### Ein Baustein, ein Kennzeichen

`App\Model\User::activeSql($alias)` liefert `<alias>.deleted = 0` — aus demselben Grund, aus dem es `Location::AVAILABILITY_SQL` gibt: Die Bedingung steht in einem Dutzend Abfragen, und ausgeschrieben wäre sie ein Dutzend Gelegenheiten, sie beim nächsten Umbau an einer Stelle zu vergessen. Der Alias ist ein Textbaustein in einer Abfrage und wird geprüft wie jeder andere.

Gefiltert wird jetzt in: der **öffentlichen Karte** (`selectPublicMapLocations`), der **Standortübersicht** (`selectAllLocations`), dem **Guide-Profil** (`selectLocationsOfGuide`), der **Standortseite** (`selectOneForPage` — und damit auch beim Anfragen, das den Standort mit derselben Methode lädt), ihrem **Takt** (`availabilityOf`), dem **Chatziel** (`guideIdOf`, das dafür erst einen JOIN bekommen hat), dem **Standortbild** (`LocationImage::findWithLocation`) und dem **Profilbild** (`GuideProfile::avatarOf`). Bild und Avatar sind der einzige Weg, auf dem eine hochgeladene Datei einen Browser erreicht — ohne Filter blieben sie abrufbar, nachdem die Seite verschwunden ist, und die Kennungen sind fortlaufend.

### Drei Stellen ohne WHERE-Klausel

Für sie gibt es `User::isDeleted($id)` (mit Zwischenspeicher für die Dauer der Anfrage; ein unbekanntes Konto gilt als gelöscht — die sichere Seite):

* **Der Anruf.** `WebRTCController::callRoles()` weist ihn ab, **vor allem anderen** — auch vor dem Wiedereinstieg in eine laufende Führung. Wer gelöscht ist, ist nicht mehr erreichbar, auch nicht über eine Zusage von gestern. Zu einem Anruf gehören zwei Kennungen aus dem Offer; es gibt keine WHERE-Klausel, in die sich das einsetzen ließe.
* **Der Chat.** Der Weg über einen Standort fällt schon in `guideIdOf()` weg; der Direktzugang der Verwaltung und das Senden prüfen selbst. **Der Verlauf bleibt lesbar** — er gehört beiden Seiten, und wer mit jemandem geschrieben hat, darf seine eigenen Nachrichten behalten. Was nicht mehr geht, ist etwas hinzuzufügen. Der **Benutzername** verschwindet trotzdem: Er ist die Anmeldekennung, und statt seiner steht `User::NAME_GELOESCHT`.
* **Die Sitzung.** `Auth::discardOutdatedSession()` verwirft sie. Vorher lief die Sitzung eines gelöschten Kontos weiter, bis sich jemand abmeldete — und solange sie lief, war das Konto angemeldet, erreichbar und konnte schreiben. Das kostet eine Abfrage je angemeldetem Aufruf auf den Primärschlüssel; für Gäste fällt sie nicht an.

### Was übrig bleibt, findet die Verwaltung — auf Nachfrage

Die Verwaltung ist der eine Ort, an dem die übriggebliebenen Zeilen überhaupt noch sichtbar sein können. **Ungefragt stehen sie trotzdem nicht in der Liste:** Alle drei Listen zeigen per Vorgabe nur lebende Konten, ein Umschalter blendet die gelöschten ein, und dann trägt die Zeile die Marke *Konto gelöscht* und keinen Verweis auf ein Profil, das es nicht mehr gibt.

**Ein Schalter, kein Filterwert**, und das ist der Punkt: *gesperrt* und *gehört einem gelöschten Konto* schließen sich nicht aus. Wäre das Gelöschte einer der Filterwerte, ließe sich „gesperrte Standorte gelöschter Konten" gar nicht mehr ansehen — und das ist genau die Liste, die man nach einer Löschung durchgeht. Der Schalter steht deshalb **neben** dem Filter, und beide erhalten einander: Ein Filterklick wirft den Schalter nicht weg und umgekehrt. Der Zustand steht in der Adresse (`&geloescht=1`), damit sich eine Ansicht weitergeben und mit dem Zurück-Knopf verlassen lässt; ausgeschaltet wird durch Weglassen, nicht durch `geloescht=0`.

| Liste | Wonach ausgeblendet wird | Und warum |
|---|---|---|
| **Standorte** | der Eigentümer | Seine Standorte sind überall sonst verschwunden. |
| **Bewertungen** | der **Guide**, nicht der Kunde | Ist der Guide gelöscht, steht die Bewertung nirgends mehr — sie zu entfernen ändert nichts. Ist der **Kunde** gelöscht, steht sie **weiterhin** öffentlich beim Guide: Sie ist eine Auskunft über *ihn* und trägt keinen Namen. Sie bleibt also sichtbar und moderierbar; gekennzeichnet wird nur der Name daneben. |
| **Benutzer** | das Konto selbst | Hier war der Mangel umgekehrt: `User::getAll()` filterte gelöschte Konten **fest** heraus — sie waren auch für den Admin unauffindbar, und auf die Frage *„ist das Konto von gestern wirklich weg"* gab es keine Antwort. Eingeblendet trägt die Zeile die Marke und bietet weder Anruf noch Nachricht noch Bearbeiten an: An einem gelöschten Konto gibt es nichts mehr zu tun. |

Die **Anfragenliste** bekommt bewusst keinen Schalter. Ihre Vorräte sind Vorgänge zwischen zwei Konten, und der Weg zum Abarbeiten führt über ein Gespräch mit dem Guide — mit einem gelöschten Konto gibt es keines. Was dort stehen bliebe, wäre eine Aufgabe, die niemand mehr erledigen kann.

> **Offen:** Was beim Löschen mit den Standorten selbst geschehen soll, ist damit nicht entschieden — sie bleiben stehen und sind nur unsichtbar. Die Bilddateien liegen weiter auf der Platte.

---

## 🔐 Berechtigungen

### Rollen

| `usertype.id` | Rolle | Bedeutung |
|---|---|---|
| 0 | Trial | frisch registriert, Guide-Frage noch offen |
| 1 | User | Zuschauer, hat sich gegen die Guide-Rolle entschieden |
| 2 | Guide | bietet Standorte an, hat der Rolle zugestimmt |
| 10 | Admin | Benutzerverwaltung und Moderation |

Die Nummern sind **Etiketten, keine Rangfolge**: Eine höhere Nummer bedeutet nicht "darf mehr". Die Lücke zwischen 2 und 10 ist Platz für weitere Rollen, die nicht gleich Admin sein sollen. Eine neue Rolle braucht genau zwei Einträge — einen in `usertype` und einen in `class/Helper/Permission.php`.

### Rechte

Geprüft wird nie eine Rolle, sondern immer ein **benanntes Recht** (`user.delete`, `location.block`, `chat.read`, `request.answer`, `request.finish`, `request.list_all`, `review.create`, …). Die vollständige Zuordnung steht in `class/Helper/Permission.php`; jede Rolle führt ihre Rechte selbst auf, es gibt **keine Vererbung**. Auch "nicht angemeldet" ist dort eine Rolle (`Permission::GUEST`) mit einer ausgeschriebenen Liste.

### Durchsetzung

Jeder Eintrag in `config/routes.php` hat vier Pflichtangaben:

```php
'delete_user' => [UserController::class, 'deleteUser', Permission::USER_DELETE, 'html'],
```

`index.php` prüft **die gesamte Tabelle** bei jedem Aufruf. Fehlt bei einer Route das Recht oder ist es unbekannt, antwortet die Anwendung gar nicht mehr, bis der Eintrag stimmt — eine Route ohne definiertes Recht ist ein Konfigurationsfehler, kein offener Zugang. Erst danach wird das Recht des Aufrufers geprüft: Seiten leiten zur Anmeldung, Schnittstellen antworten mit 401 bzw. 403 als JSON.

Was eine Rechtetabelle nicht wissen kann, prüfen weiterhin die Controller **und die Datenbankabfrage**: Standorte ändern und löschen tragen `AND user_id = :user_id` in der WHERE-Klausel, Chatnachrichten setzen die Beteiligung am Chat voraus (`Chat::hatTeilnehmer()`), und **wen** jemand anschreiben darf, sagt der Standort und nicht die Anfrage (siehe [Der Chat](#-der-chat-über-einen-standort)).

### Moderation

Ein Admin **löscht keine fremden Standorte, er sperrt sie** (Recht `location.block`). Der gesperrte Standort verschwindet aus der Übersicht der anderen Nutzer, bleibt aber beim Guide bestehen — in seiner eigenen Standortliste sieht er die Sperre samt Grund. Gelöscht wird nur vom Eigentümer.

Dasselbe Muster bei **Bewertungen** (Recht `review.remove`): Der Admin entfernt eine Bewertung, indem sie ausgeblendet wird — die Zeile bleibt samt Zeitpunkt, Entferner und Grund stehen. Der bewertete Guide hat dieses Recht ausdrücklich **nicht**; siehe [Bewertungen](#-bewertungen).

**Stattfinden tut beides im [Verwaltungsbereich](#️-der-verwaltungsbereich)** und nicht mehr in der Kundenoberfläche. Die beiden Rechte tragen dort zusätzlich die jeweilige Liste: Wer sperren darf, sieht die Standortliste; wer entfernen darf, die Bewertungsliste.

### Die Guide-Rolle

Guide wird man **auf Nachfrage, nicht nebenbei**. Früher genügte das Anlegen eines Standorts, um die Rolle stillschweigend zu bekommen; heute erklärt ein Dialog, was die Rolle bedeutet — Standorte anbieten und sich vor Ort vom Zuschauer steuern lassen — und fragt danach.

* **Gestellt** wird die Frage nach dem Login, solange die Rolle `Trial` ist (mit und ohne zweiten Faktor: `LoginController::continueAfterLogin`). Sie lässt sich mit *Später entscheiden* übergehen, dann kommt sie beim nächsten Login wieder.
* **Geändert** wird die Entscheidung jederzeit unter *Mein Account/Einstellungen*. Zurückgeben lässt sich die Rolle nur ohne eigene Standorte — ein Standort ohne Guide wäre ein Angebot, das niemand einlösen kann.
* **Vollzogen** wird jeder Rollenwechsel ausschließlich in `App\Model\GuideRole`. Wer zustimmt, bekommt eine Zeile in `guide_profile`: Zeitpunkt, Beginn und die Fassung der Bedingungen (`GuideRole::TERMS_VERSION`). Die Zeile bleibt beim Widerruf stehen.
* **Dieselbe Zeile trägt das öffentliche Profil** — Anzeigename, Selbstbeschreibung, Sprachen, Bild (siehe [Der Guide als Mensch](#-der-guide-als-mensch)). Geschrieben wird es von `App\Model\GuideProfile` und nie von `GuideRole`: Wer der neuen Fassung der Bedingungen zustimmt, verliert dabei nicht sein Profil.

**Vorbereitung auf die Abrechnung.** Führungen sind heute kostenlos und werden es nicht bleiben. Vorbereitet ist dafür dreierlei — mehr bewusst nicht, es wird nichts berechnet und kein Preis gespeichert:

1. `guide_profile` als eigene Tabelle. Die späteren Abrechnungstabellen hängen sich an `guide_profile.user_id`; `user` bleibt die Tabelle für das Konto, nicht für die Geschäftsbeziehung. Ein Profil, das einen Menschen zeigt, gehört ohnehin dorthin und nicht an `user` — `User::update()` schriebe es bei jedem Speichern mit.
2. `terms_version`. Wird die Konstante hochgezählt, weil Führungen kostenpflichtig werden, gilt jede ältere Zustimmung als überholt und der Dialog erscheint erneut — mit dem neuen Text. Wer wem wann zugestimmt hat, lässt sich nachträglich nicht mehr feststellen; deshalb steht es von Anfang an drin.
3. `GuideRole::accept()` und `::resign()` als einzige Stellen des Rollenwechsels. Die Prüfungen, die später dazukommen (Auszahlungsdaten hinterlegt? Beträge offen?), gehören dorthin und sonst nirgendwohin.

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
