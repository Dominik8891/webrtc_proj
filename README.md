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
    * E-Mail-Verifizierung (`email_verified`) und Passwort-Reset via SMTP.
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
```

`005` vergibt die Rollennummern neu (siehe unten), `006` ergänzt die Spalten für die Standortsperre, `007` legt die Tabelle `guide_profile` an und trägt die vorhandenen Guides darin nach, `008` speichert das Farbprofil je Konto, `009` merkt sich am Signal, von welchem Standort ein Anruf ausging — daran hängt die Rollenvergabe im Call, `010` ergänzt `user.available_until` und trennt damit "angemeldet" von "bereit" (siehe [Verfügbarkeit](#-verfügbarkeit-angemeldet-ist-nicht-bereit)), `011` gibt dem Standort Titel, ausführliche Beschreibung, Dauer und Sprachen und legt die Tabelle `location_image` an, `012` trennt Titelbild und Beispielbilder über die Spalte `location_image.role` und wählt in jedem vorhandenen Standort das erste Bild zum Titelbild (siehe [Der Standort und seine Seite](#-der-standort-und-seine-seite)), `013` legt die Tabelle `tour_request` an — die Anfrage und zugleich der erste Datensatz über stattgefundene Führungen (siehe [Die Anfrage](#-die-anfrage-statt-des-anrufs)), `014` gibt dem Standort seine **üblichen Zeiten** und seine **Zeitzone** (siehe [Übliche Zeiten](#übliche-zeiten-und-die-zeitzone-des-ortes)). Alle sind idempotent und löschen nichts.

**Nach `011` braucht die Anwendung ein Ablageverzeichnis für Bilder**, sonst lässt sich kein Bild hochladen; alles andere läuft unverändert weiter. Siehe [Bilder](#bilder-ablage-formate-größen).

**Nach `013` beginnt die Aufzeichnung bei null.** Vergangene Führungen sind nirgends festgehalten und lassen sich nicht nachtragen — es gab dafür keinen Datensatz, und genau deshalb gibt es die Tabelle.

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
| `stale_call` | 4 Std | wann eine begonnene Führung ohne Ende als beendet gilt |

Eine offene Anfrage läuft ab, **wenn einer der beiden Gründe eintritt** — der
frühere gewinnt: Eine Anfrage für „jetzt sofort" ist eine Viertelstunde später
gegenstandslos, eine für nächsten Samstag verfällt nach der Antwortfrist statt
eine Woche offen zu stehen. Der Ablaufzeitpunkt wird beim Anlegen gerechnet und
steht in der Zeile; er bleibt damit nachvollziehbar, auch wenn jemand die
Konfiguration ändert.

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
nicht weitergegeben. Was ein Gast nicht bekommt, ist die `user_id` des Guides —
ohne sie lässt sich von dort niemand anrufen. Anfragen kann er ebenfalls nicht:
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

Geprüft wird nie eine Rolle, sondern immer ein **benanntes Recht** (`user.delete`, `location.block`, `chat.read`, `request.answer`, …). Die vollständige Zuordnung steht in `class/Helper/Permission.php`; jede Rolle führt ihre Rechte selbst auf, es gibt **keine Vererbung**. Auch "nicht angemeldet" ist dort eine Rolle (`Permission::GUEST`) mit einer ausgeschriebenen Liste.

### Durchsetzung

Jeder Eintrag in `config/routes.php` hat vier Pflichtangaben:

```php
'delete_user' => [UserController::class, 'deleteUser', Permission::USER_DELETE, 'html'],
```

`index.php` prüft **die gesamte Tabelle** bei jedem Aufruf. Fehlt bei einer Route das Recht oder ist es unbekannt, antwortet die Anwendung gar nicht mehr, bis der Eintrag stimmt — eine Route ohne definiertes Recht ist ein Konfigurationsfehler, kein offener Zugang. Erst danach wird das Recht des Aufrufers geprüft: Seiten leiten zur Anmeldung, Schnittstellen antworten mit 401 bzw. 403 als JSON.

Was eine Rechtetabelle nicht wissen kann, prüfen weiterhin die Controller **und die Datenbankabfrage**: Standorte ändern und löschen tragen `AND user_id = :user_id` in der WHERE-Klausel, Chatnachrichten setzen die Beteiligung am Chat voraus.

### Moderation

Ein Admin **löscht keine fremden Standorte, er sperrt sie** (Recht `location.block`). Der gesperrte Standort verschwindet aus der Übersicht der anderen Nutzer, bleibt aber beim Guide bestehen — in seiner eigenen Standortliste sieht er die Sperre samt Grund. Gelöscht wird nur vom Eigentümer.

### Die Guide-Rolle

Guide wird man **auf Nachfrage, nicht nebenbei**. Früher genügte das Anlegen eines Standorts, um die Rolle stillschweigend zu bekommen; heute erklärt ein Dialog, was die Rolle bedeutet — Standorte anbieten und sich vor Ort vom Zuschauer steuern lassen — und fragt danach.

* **Gestellt** wird die Frage nach dem Login, solange die Rolle `Trial` ist (mit und ohne zweiten Faktor: `LoginController::continueAfterLogin`). Sie lässt sich mit *Später entscheiden* übergehen, dann kommt sie beim nächsten Login wieder.
* **Geändert** wird die Entscheidung jederzeit unter *Mein Account/Einstellungen*. Zurückgeben lässt sich die Rolle nur ohne eigene Standorte — ein Standort ohne Guide wäre ein Angebot, das niemand einlösen kann.
* **Vollzogen** wird jeder Rollenwechsel ausschließlich in `App\Model\GuideRole`. Wer zustimmt, bekommt eine Zeile in `guide_profile`: Zeitpunkt, Beginn und die Fassung der Bedingungen (`GuideRole::TERMS_VERSION`). Die Zeile bleibt beim Widerruf stehen.

**Vorbereitung auf die Abrechnung.** Führungen sind heute kostenlos und werden es nicht bleiben. Vorbereitet ist dafür dreierlei — mehr bewusst nicht, es wird nichts berechnet und kein Preis gespeichert:

1. `guide_profile` als eigene Tabelle. Die späteren Abrechnungstabellen hängen sich an `guide_profile.user_id`; `user` bleibt die Tabelle für das Konto, nicht für die Geschäftsbeziehung.
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
