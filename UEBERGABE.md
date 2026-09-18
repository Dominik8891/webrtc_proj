# Übergabe

Für einen Entwickler, der dieses Projekt nicht kennt und es übernehmen soll.

Dieses Dokument ersetzt keine der vorhandenen Beschreibungen — es ordnet sie
ein und sagt, was davon heute noch gilt. Es ist bewusst ehrlich: Wo der Zustand
schwach ist, steht das hier, und nicht nur das, was fertig ist.

**Stand:** 2026-09-18 · Branch `claude/tender-gauss-fxhrl2` · Commit `c5dea46`

---

## Inhalt

1. [Was das Produkt ist](#1-was-das-produkt-ist)
2. [Architektur im Überblick](#2-architektur-im-überblick)
3. [Entwicklungsumgebung aufsetzen](#3-entwicklungsumgebung-aufsetzen)
4. [Was erledigt ist, was nicht](#4-was-erledigt-ist-was-nicht)
5. [Was vor einem Livegang zwingend fehlt](#5-was-vor-einem-livegang-zwingend-fehlt)
6. [Offene Produktentscheidungen](#6-offene-produktentscheidungen)
7. [Die geplante mobile App](#7-die-geplante-mobile-app)
8. [Konventionen des Projekts](#8-konventionen-des-projekts)

---

## 1. Was das Produkt ist

**In fünf Sätzen.** Ein Guide steht an einem echten Ort — einem Markt, einem
Museum, einer Altstadtgasse — und trägt sein Handy; ein Kunde sitzt zu Hause und
sieht durch dessen Kamera. Über ein Steuerkreuz sagt der Kunde, wohin es geht
(vorwärts, zurück, links, rechts, Blick hoch, Blick runter); beim Guide löst
jede Anweisung ein Tonsignal in seiner Sprache und eine bildschirmfüllende
Anzeige aus — gesteuert wird über Tasten und Töne, nicht über Sprache, damit das
weltweit funktioniert. Bild, Ton und Steuerbefehle laufen peer-to-peer über
WebRTC; der PHP-Server macht nur das Signaling, die Rechteprüfung und die
Verwaltung der Inhalte. Eine Führung beginnt nicht mit einem Anruf, sondern mit
einer **Anfrage samt Wunschzeitpunkt**, die der Guide annimmt oder ablehnt.
Danach ruft der Kunde an, der Guide beendet die Führung ausdrücklich, und der
Kunde wird gefragt, wie sie war.

### Die Rollen

Zwei Rollenbegriffe, die man nicht verwechseln darf.

**Kontorollen** (`usertype`, dauerhaft am Konto):

| `usertype.id` | Rolle | Bedeutung |
|---|---|---|
| 0 | Trial | frisch registriert, die Guide-Frage ist noch offen |
| 1 | User | Zuschauer, hat sich gegen die Guide-Rolle entschieden |
| 2 | Guide | bietet Standorte an, hat der Rolle zugestimmt |
| 10 | Admin | Benutzerverwaltung und Moderation |

Dazu kommt **Gast** — nicht angemeldet. Das ist im Code keine Ausnahme im
Ablauf, sondern eine ausgeschriebene Rechteliste (`Permission::GUEST`).

Die Nummern sind **Etiketten, keine Rangfolge**. Die Lücke zwischen 2 und 10 ist
Platz für weitere Rollen, die nicht gleich Admin sein sollen. Es gibt keine
Vererbung: Jede Rolle führt ihre Rechte selbst auf. Ein Vergleich wie
`role_id > 1` ist im Projekt **verboten** und wird von einem Test gemeldet —
genau solche Vergleiche waren die Ursache mehrerer Altfehler.

**Call-Rollen** (gelten für genau einen Anruf, kommen vom Server):

| Rolle | Wer | Sendet |
|---|---|---|
| `guide` | die Person vor Ort | Ton und Bild |
| `viewer` | der Zuschauer | **nichts** — er steuert nur |
| `peer` | Direktanruf ohne Führung (Verwaltung ↔ Nutzer) | Ton und Bild, niemand steuert |

Entscheidend ist, **woher der Anruf kam**: Von einem Standort aus führt der
Angerufene, auch wenn er Admin ist. Der Client kann sich keine Rolle selbst
geben — sie hängt am Offer, das der Server stempelt
(`WebRTCController::callRoles`).

### Wie eine Führung abläuft

1. **Der Kunde findet einen Standort** — über die Karte oder die Liste auf der
   Startseite. Jeder Standort hat eine eigene, teilbare Seite mit Bildern,
   Titel, Beschreibung, Dauer, Sprachen, üblichen Zeiten samt Zeitzone des Ortes
   und Karte.
2. **Er stellt eine Anfrage** mit Wunschzeitpunkt. Vier Vorgaben (*jetzt
   sofort*, *in 1 Stunde*, *in 3 Stunden*, *morgen um diese Zeit*), darunter ein
   freies Feld. „Jetzt sofort" ist dabei kein Sonderfall, sondern der
   Wunschzeitpunkt mit dem Abstand null — keine eigene Spalte, keine
   Verzweigung. Der Wunsch reist als **Abstand in Sekunden**, nicht als Datum;
   ein Abstand hat keine Zeitzone.
3. **Der Guide antwortet** — annehmen oder ablehnen, auf der Seite *Anfragen*.
   Dass etwas wartet, sieht er am Zähler in der Kopfleiste, die auf jeder Seite
   steht.
4. **Der Kunde startet die Führung** nach der Zusage — `rtc.startCall` mit der
   Standortkennung. Der Server vergibt die Call-Rollen.
5. **Der Call läuft**: WebRTC mit zwei getrennten DataChannels, `chat` für
   Nutzerinhalt und `control` für das Steuerprotokoll. Der Zuschauer sendet
   keine Medien.
6. **Der Guide beendet die Führung ausdrücklich.** Auflegen ist *nicht*
   beenden — bis zum Beenden können beide nach einem Verbindungsabbruch wieder
   einsteigen.
7. **Der Kunde bewertet** — Sterne plus freiwilliger Text, nur in diese
   Richtung. Ein Durchschnitt erscheint erst ab drei Bewertungen; darunter steht
   die Zahl der durchgeführten Führungen, damit wenige Bewertungen nicht wie ein
   Urteil aussehen.

Die sechs Zustände einer Anfrage: `open`, `accepted`, `declined`, `expired`,
`done`, `cancelled`. „Abgelaufen" steht in keiner Spalte — es ergibt sich aus
den Zeitpunkten und wird bei jeder Abfrage ausgerechnet
(`TourRequest::statusSql`). Damit wirkt ein Ablauf sofort, auch wenn der Cronjob
gar nicht läuft; der räumt nur auf. Die Fristen stehen an genau einer Stelle:
`config/requests.php`.

Eine zweite Bedingung neben der Zusage ist die **Bereitschaft**: Angemeldet ist
nicht bereit. Ein Guide legt in der Kopfleiste einen Schalter um, und der läuft
nach einer Frist von selbst ab. Grün auf der Karte steht ein Standort nur, wenn
der Guide online **und** bereit ist.

---

## 2. Architektur im Überblick

Klassisches PHP ohne Framework: ein einziger Einstiegspunkt, eine
Routing-Tabelle, Controller/Model/Helper nach PSR-4. Kein Build-Schritt, kein
npm, keine `package.json`. Frontend ist Vanilla-JS plus jQuery, Bootstrap,
Leaflet, DataTables und select2 — alles per CDN.

### Verzeichnisse

```
index.php              Der EINZIGE Einstiegspunkt. Alles läuft hierdurch.
.htaccess              Sperrt *.md, *.sql, *.log, .env, composer.* gegen HTTP.
                       Gilt nur unter Apache — unter nginx sind location-Regeln
                       nötig, die es hier nicht gibt.

config/                Konfiguration. Jede Datei ist die EINE Stelle für ihr Thema.
  routes.php             Routentabelle: act => [Klasse, Methode, Recht, Antwortart]
  error_handler.php      Fehler ins Log, nie in den Browser
  log_path.php           Logpfad (NICHT aus der .env — siehe unten)
  env.php                lädt die .env über phpdotenv
  session.php            Sitzungscookie samt secure/httponly/SameSite
  limits.php             die Bremse: Versuchszähler je Endpunkt
  presence.php           Heartbeat- und Offline-Fristen
  requests.php           Fristen rund um die Anfrage
  uploads.php            Bildablage, Obergrenzen, Maße

class/Controller/      17 Controller. Nehmen die Anfrage entgegen, prüfen fachlich,
                       rufen das Model, geben Text oder JSON aus.
class/Model/           16 Models. Datenbankzugriff, ausschließlich Prepared Statements.
class/Helper/          22 Helper. Auth, Permission, I18n, Views, Bildablage, …

assets/js/             28 Dateien Client-Logik
assets/html/           32 Templates mit ###MARKE###-Platzhaltern
assets/css/            10 Stylesheets
assets/img/, audio/    Symbole und die Richtungstöne

lang/de.php, en.php    Die beiden Sprachkataloge (flache Schlüssel-Wert-Listen)

migrations/            22 nummerierte SQL-Migrationen, idempotent
database.sql           Vollständiges Schema für eine NEUE Installation
cron/                  check_online_status.php — Pflicht im Betrieb
deploy/                backup.sh, logrotate-Konfiguration
tests/                 Zwei Prüfskripte ohne Framework, ohne DB, ohne Netz
```

### Wichtige Klassen

| Klasse | Wofür |
|---|---|
| `App\Helper\Auth` | Der angemeldete Nutzer. **Einzige** Stelle, die `$_SESSION['user']` liest und schreibt. Verwirft Sitzungen mit veraltetem Aufbau (`SESSION_SCHEME`) und die gelöschter Konten. |
| `App\Helper\Permission` | Die Rechtetabelle. Jede Rolle listet ihre Rechte aus, ohne Vererbung. Prüft auch die Routentabelle auf Vollständigkeit. |
| `App\Helper\Role` | Normalisiert Rollenwerte (aus der DB kommt je nach PDO `'2'` oder `2`). |
| `App\Helper\MailGate` | Die beiden E-Mail-Schalter und die Liste der Routen, die eine bestätigte Adresse voraussetzen. |
| `App\Helper\Env` | Die eine Stelle, an der ein Konfigurationswert aus der Umgebung kommt — `$_SERVER`, dann `$_ENV`, dann `getenv()`. |
| `App\Helper\I18n` | Sprache der Antwort: Konto → Cookie → `Accept-Language` → `en`. Liefert den Katalog auch ins JavaScript. |
| `App\Helper\Https` / `SecurityHeaders` | HTTPS-Weiterleitung, HSTS, CSP und die übrigen Sicherheitskopfzeilen. |
| `App\Helper\ImageStore` | Prüft, verkleinert, speichert und liefert Bilder aus. |
| `App\Model\PdoConnect` | Die eine Datenbankverbindung. `ATTR_EMULATE_PREPARES = false`. |
| `App\Model\WebRTCHandler` | Die Signalzeilen (`rtc_signal`) samt Herkunfts-Standort. |
| `App\Model\IceServerConfig` | Die STUN-Liste, die immer zusätzlich zu TURN ausgeliefert wird. |
| `App\Model\RateLimit` | Die serverseitige Bremse (Tabelle `rate_limit`), je Konto und je IP. |
| `App\Model\GuideRole` | **Einzige** Stelle, an der ein Rollenwechsel vollzogen wird. |
| `App\Model\TourRequest` | Die Anfrage — und zugleich der einzige Datensatz über stattgefundene Führungen. |

Die `*View`-Helper (`AdminView`, `GuideView`, `LocationView`, `ReviewView`,
`ViewHelper`) bauen HTML aus den Templates. Sie sind groß — `LocationView` hat
knapp 67 KB —, aber sie sind die einzige Stelle, an der serverseitig Markup
entsteht.

### Wie eine Anfrage durch `index.php` läuft

Die Reihenfolge ist keine Gewohnheit, sondern eine Aussage; sie steht als
Kommentarblock über jeder Zeile in `index.php`. Wer sie umsortiert, macht etwas
kaputt.

```
 1. config/error_handler.php   Fehler ins Log, nie in den Browser.
                               Zuerst, damit alles Folgende protokolliert wird.
                               Kommt ohne .env aus — sein Logpfad wird auf
                               Server- oder Systemebene gesetzt.
 2. vendor/autoload.php        Composer.
 3. config/env.php             Die .env. Fehlt die Datei, bricht die Anwendung
                               hier ab.
 4. config/session.php         Sitzung. ZULETZT, weil das 'secure'-Merkmal des
                               Cookies an FORCE_HTTPS hängt — und der Wert
                               kommt aus der .env.

 5. Https::erzwingen()         Weiterleitung auf https. Zuerst, damit eine
                               Anfrage, die ohnehin umgeleitet wird, nichts
                               weiter tut.
 6. SecurityHeaders::senden()  VOR JEDER AUSGABE. Weil index.php der einzige
                               Einstieg ist, trägt damit jede Antwort dieselben
                               Kopfzeilen — auch die Fehlerseiten.

 7. config/routes.php          Die Routentabelle wird geladen.
 8. PdoConnect::sicherstellen() Die Datenbankverbindung. Ab hier darf jede Zeile
                               sie benutzen. NACH der Weiterleitung, damit eine
                               umgeleitete Anfrage keine Verbindung öffnet.

 9. Permission::routeErrors()  Prüft die GESAMTE Routentabelle. Eine Route ohne
                               Recht oder mit unbekanntem Recht ist ein
                               Konfigurationsfehler: Die Anwendung antwortet
                               dann gar nicht mehr (HTTP 500), statt
                               vorsichtshalber zu sperren oder durchzulassen.
10. Auth::discardOutdatedSession()  Verwirft Sitzungen mit altem Aufbau und die
                               gelöschter Konten. FRAGT DIE DATENBANK, steht
                               deshalb hinter Schritt 8.
11. I18n::start()              Die Sprache dieser Antwort. Hinter Schritt 10,
                               weil erst dort feststeht, ob die Sitzung gilt;
                               vor dem Controller, weil der schon Text baut.

12. $act einlesen und prüfen   Nur [a-zA-Z0-9_]. Unbekannt → 404.
13. [$class, $method, $right, $kind] = $routes[$act];

14. Auth::can($right)          ►► DIE RECHTEPRÜFUNG ◄◄
                               Nicht angemeldet: Seiten → Login, JSON → 401.
                               Angemeldet ohne Recht: 403.
15. MailGate::sperrt($act)     Bestätigungspflicht. HINTER der Rechteprüfung:
                               „Darf diese ROLLE das" zuerst, „ist dieses KONTO
                               so weit" danach — sonst erführe ein Aufrufer aus
                               der Fehlermeldung, dass es die Route gibt.

16. new $class(); $controller->$method();
```

### Wo die Rechteprüfung sitzt

**In `index.php`, Zeile 14 der Liste oben — und nirgendwo sonst.** Das ist die
wichtigste Architekturentscheidung des Projekts.

Jeder Eintrag in `config/routes.php` hat vier Pflichtangaben:

```php
'delete_user' => [UserController::class, 'deleteUser', Permission::USER_DELETE, 'html'],
//                 Klasse               Methode        Recht                    Antwortart
```

Geprüft wird nie eine Rolle, sondern immer ein **benanntes Recht**
(`user.delete`, `location.block`, `chat.read`, `request.answer`, …). Die
Zuordnung steht in `class/Helper/Permission.php`.

Drei Dinge, die diese eine Stelle **nicht** leisten kann und die deshalb daneben
stehen:

1. **Eigentum** — es steht in der WHERE-Klausel. `updateLocation()` und
   `deleteLocation()` tragen `AND user_id = :user_id`; ein Recht allein sagt
   nicht, wem der Standort gehört.
2. **Beteiligung** — `Chat::hatTeilnehmer()`. Und *wen* jemand anschreiben darf,
   sagt der Standort, nicht die Anfrage.
3. **Zustand des Kontos** — die Bestätigungspflicht (`MailGate`) und die
   aktuelle Fassung der Guide-Bedingungen
   (`GuideController::requireCurrentTerms()`).

Ein Test prüft, dass jede Route ein bekanntes Recht trägt, und schlägt auch bei
einer erfundenen Antwortart an. Ein zweiter verbietet Vergleichsoperatoren auf
Rollenwerten im ganzen Code.

### Signaling

Der Client pollt `index.php?act=getSignal` — 1500 ms im Ruhezustand, 3000 ms im
laufenden Call. Kein WebSocket. Das ist die größte bekannte Schwäche der
Architektur: Bei 100 gleichzeitigen Nutzern sind das rund 70 Datenbankabfragen
pro Sekunde im Leerlauf, und jeder Signalisierungsschritt kostet im Mittel
750 ms zusätzliche Latenz.

Das Polling wird im Call **umgeschaltet, nicht abgeschaltet** — früher wurde es
bei `dc.onopen` beendet, und genau deshalb erreichte das Auflegen den Gegenüber
nie. Offer und Answer eines ICE-Restarts brauchen einen Weg, der von der
gestörten Verbindung unabhängig ist.

---

## 3. Entwicklungsumgebung aufsetzen

### Voraussetzungen

* **PHP 8.x** mit **GD** (in der `php.ini` bei `extension=gd` das Semikolon
  entfernen). Ohne GD kann kein Bild hochgeladen werden.
  `composer.json` hat **keinen** `php`-Constraint — die Installation gelingt
  auch auf PHP 7 und scheitert erst zur Laufzeit.
* **Composer**.
* **MariaDB.** Nicht MySQL 8: Die Migrationen benutzen `ALTER TABLE … IF NOT
  EXISTS`, das MySQL nicht kennt.
* **Node.js** — nur für den Client-Testlauf, nicht für den Betrieb.
* Für die Kamera braucht der Browser **HTTPS** oder `localhost`.

### Schritt für Schritt

```bash
# 1. Abhängigkeiten
composer install          # legt vendor/ an; ohne das bricht index.php sofort ab

# 2. Konfiguration
cp .env.example .env      # danach die Werte eintragen — siehe unten

# 3. Datenbank anlegen
mariadb -u root -p -e "CREATE DATABASE webrtc_proj CHARACTER SET utf8mb4;"

# 4. Schema einspielen  →  BEI EINER NEUEN INSTALLATION NUR DIESE DATEI
mariadb -u root -p webrtc_proj
```
```sql
SOURCE database.sql;
```

`database.sql` ist vollständig: alle 15 Tabellen inklusive `guide_profile`,
`tour_request`, `tour_review`, `rate_limit` und `user.lang`. **Die Migrationen
braucht eine neue Installation nicht.** Sie sind ausschließlich für eine
bestehende Datenbank da, die von einem älteren Stand hochgezogen werden soll.

```bash
# 5. Ablageverzeichnis für Bilder — außerhalb des Webroots
mkdir -p /var/lib/webrtc/uploads
chown www-data:www-data /var/lib/webrtc/uploads     # RHEL/CentOS: apache
chmod 750 /var/lib/webrtc/uploads
# und in der .env:  UPLOAD_PATH=/var/lib/webrtc/uploads

# 6. Cronjob (im Betrieb Pflicht, lokal hilfreich)
* * * * * /usr/bin/php /var/www/webrtc_proj/cron/check_online_status.php

# 7. Prüfen, dass alles läuft
php  tests/server_test.php     # erwartet: "410 Pruefungen bestanden."
node tests/client_test.js      # erwartet: "188 Pruefungen bestanden."
```

Beide Testläufe brauchen **keine Datenbank und kein Netz** und sind gefahrlos
jederzeit ausführbar. Sie sind der schnellste Weg festzustellen, ob eine
Änderung etwas kaputt gemacht hat.

### Die `.env` — das Minimum

```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=webrtc_proj
DB_USER=webrtc_user
DB_PW=…
APP_BASE_URL=https://localhost/webrtc_proj
PEPPER=…                      # 32 Byte Hex, einmalig erzeugt
UPLOAD_PATH=/var/lib/webrtc/uploads
FORCE_HTTPS=0                 # LOKAL — siehe Fallstrick 4
MAIL_ENABLED=0                # schreibt Mails samt Link ins Log statt sie zu senden
```

`.env.example` ist die ausführliche Fassung: 463 Zeilen, jeder Schlüssel mit
Begründung und mit der Codestelle, die ihn liest. Ein Test prüft, dass **jeder**
Schalter, den der Code über `Env::…` liest, dort und in der README vorkommt.

### Die Fallstricke, die uns begegnet sind

**1. Migrationen mit `SOURCE` einspielen, nicht mit `<`.**

Vier Migrationsdateien — `002`, `003`, `004` und `021` — enthalten
`DELIMITER`-Blöcke mit gespeicherten Prozeduren; sie prüfen darin
Voraussetzungen und brechen über `SIGNAL` ab, wenn der Bestand nicht passt.
`DELIMITER` ist eine Anweisung des Kommandozeilenclients und keine des Servers.
Über die Umleitung `mariadb … < datei.sql` ist das je nach Client und
Plattform unzuverlässig; die Datei läuft dann halb durch oder meldet einen
Syntaxfehler mitten im Prozedurrumpf. Zuverlässig ist der Weg über den Client:

```bash
mariadb -u <user> -p <datenbank>
```
```sql
SOURCE migrations/003_country_iso2.sql;
```

**Die README zeigt in Abschnitt „1. Datenbank" noch durchgehend die
Pipe-Form.** Das ist für die Dateien ohne `DELIMITER` in Ordnung und für die
vier genannten die Stelle, an der man hängenbleibt. Wer die Migrationen anfasst,
sollte die README dabei mitziehen.

**2. Das Upload-Verzeichnis existiert nicht von selbst.**

Es liegt bewusst **außerhalb des Document Root** — was unter dem Webroot liegt,
ist über HTTP abrufbar, und zwar auch dann, wenn es gar kein Bild ist. Eine
hochgeladene Datei ist Fremdeingabe. Ausgeliefert wird sie über
`index.php?act=location_image`, also durch einen Controller, der vorher prüft,
ob der Standort gesperrt ist.

Ohne `UPLOAD_PATH` greift der Fallback `../uploads` eine Ebene oberhalb des
Webroots. Das Verzeichnis **muss dem Webserver gehören** — sonst schlägt jeder
Upload fehl, und die Fehlermeldung im Browser ist die nichtssagende „Interner
Serverfehler."; der Grund steht im Log. Das war hier zweimal die Ursache, und
beide Male hat es länger gedauert als nötig.

Anders als `LOG_PATH` **darf** `UPLOAD_PATH` in der `.env` stehen:
`config/uploads.php` wird erst aus einem Controller heraus geladen, also lange
nachdem `config/env.php` gelaufen ist.

**3. `LOG_PATH` gehört NICHT in die `.env`.**

`config/log_path.php` wird von `index.php` geladen, **bevor** die `.env`
eingelesen ist. Ein `LOG_PATH` in der `.env` wirkt deshalb nicht — der Wert muss
auf Server- oder Systemebene gesetzt werden:

```
Apache : SetEnv LOG_PATH /var/log/webrtc/php-error.log
nginx  : fastcgi_param LOG_PATH /var/log/webrtc/php-error.log;
Docker : environment: LOG_PATH=/var/log/webrtc/php-error.log
```

Ohne den Wert greift der Fallback `../logs/php-error.log` oberhalb des
Webroots, und alles funktioniert. **Altlast:** Frühere Versionen schrieben nach
`<Webroot>/php-error.log`. Diese Datei kann noch existieren, ist über HTTP
abrufbar und enthält unter Umständen TOTP-Secrets und Reset-Tokens. Sie wird
bewusst nicht automatisch gelöscht — bitte von Hand entfernen.

**4. Ohne `FORCE_HTTPS=0` ist lokal niemand angemeldet.**

Das Sitzungscookie trägt `secure`, wenn die Anwendung über HTTPS läuft *oder*
ohnehin dorthin umleitet. Auf einem Entwicklungsrechner ohne Zertifikat heißt
das: Das Cookie wird gesetzt und nie zurückgeschickt, jede Anmeldung läuft ins
Leere, und **nichts sagt einem, warum**. `FORCE_HTTPS=0` schaltet beides ab, die
Weiterleitung und das Merkmal. Genau deshalb steht `config/session.php` in
`index.php` hinter `config/env.php`.

**5. Die Reihenfolge der Migrationen ist verbindlich, und zwei brechen mit
Absicht ab.**

Sie bauen aufeinander auf: `001` legt `location.user_id` NULLable und ohne
Fremdschlüssel an, **weil es keine Datenquelle gibt**, aus der sich die Zuordnung
Standort → Guide rekonstruieren ließe; erst `002` setzt die Spalte auf NOT NULL
und legt den Fremdschlüssel an. `003` legt nur die Spalte `country.iso2` an,
die Stammdaten kommen mit `004`.

`020` (Benutzername eindeutig) und `021` (eine Stadt je Land eindeutig) ziehen
eindeutige Indizes nach, die es vorher nicht gab. Wo der Bestand ihnen
widerspricht, ist das Zusammenführen eine Entscheidung, die keine Migration
treffen darf: Beide geben die betroffenen Zeilen aus und **brechen ab, ohne
etwas zu ändern**. Das ist ihr Zweck, kein Fehler. Was zu tun ist, steht im Kopf
der jeweiligen Datei.

`019` ist die einzige, die etwas löscht (`chat.is_active`, `chat.pending_for`).
Nachrichten gehen dabei keine verloren.

Hinter einigen Migrationen steht eine inhaltliche Folge, die man kennen muss:
Nach `005` müssen sich alle Nutzer neu anmelden (alte Sitzungen trügen die
falsche Rolle). Nach `010` steht kein Guide mehr auf bereit. Nach `013`, `016`
und `017` beginnt die jeweilige Aufzeichnung bei null — vergangene Führungen
lassen sich nicht nachtragen.

**6. `vendor/` ist nicht im Repository**, und `.gitignore` schließt `*.sql` und
`*.txt` aus. Zwei Ausnahmen sind eingetragen (`migrations/`,
`tests/i18n_grundstock.txt`), weil genau diese Regel schon einmal dafür gesorgt
hat, dass Migrationen committet zu sein *schienen* und im Arbeitsverzeichnis
eines anderen fehlten. **Lehre daraus:** `git status` zeigt ignorierte Dateien
nicht an; ein sauberer Arbeitsbaum ist kein Beleg. Bei neuen Dateien gehört
`git check-ignore -v <datei>` zur Kontrolle.

---

## 4. Was erledigt ist, was nicht

### Erledigt und belastbar

* **Rechtemodell.** Vier Rollen plus Gast, benannte Rechte ohne Vererbung, jede
  Route trägt ihr Recht als Pflichtfeld, geprüft an einer Stelle. Durch Tests
  abgesichert, inklusive eines Verbots von Rollenvergleichen im ganzen Code.
* **Der Fachablauf ist vollständig**: Standort anlegen mit Bildern, üblichen
  Zeiten und Zeitzone → Anfrage mit Wunschzeitpunkt → Annehmen/Ablehnen →
  Führung → ausdrückliches Beenden → Bewertung. Dazu Chat über einen Standort,
  Guide-Profil, Verwaltungsbereich mit fünf Seiten.
* **Verbindungsstabilität.** ICE-Restart mit Grace-Timer statt sofortigem
  Abbruch bei `disconnected`, Glare-Vermeidung, Auflegen über zwei Wege,
  STUN-Fallback, wenn der TURN-Dienst ausfällt. Das war der funktional
  wichtigste Posten und ist abgearbeitet.
* **Das Steuerprotokoll Version 2** — versioniert, mit Rollen, Bestätigung und
  Sperre, auf einem eigenen DataChannel getrennt vom Chat.
* **Bildablage** außerhalb des Webroots, Typ aus dem Inhalt erkannt, EXIF
  (inklusive GPS) wird beim Neuzeichnen entfernt, Grenzen an genau einer Stelle.
* **Sprachfundament.** Zwei Kataloge, Auflösungsreihenfolge Konto → Cookie →
  `Accept-Language` → `en`, derselbe Katalog im Browser. Die Kataloge und
  Formate sind umgezogen.
* **Betriebswerkzeug**: Backup-Skript, logrotate-Konfiguration, Cronjob, die
  Bremse gegen Missbrauch (Tabelle `rate_limit`), Sicherheitskopfzeilen,
  HTTPS-Erzwingung, `.env.example` mit 26 dokumentierten Schlüsseln.
* **598 Prüfungen**, die alle durchlaufen (410 Server, 188 Client), ohne
  Framework und ohne Datenbank.

### Nicht erledigt

* **Kein CSRF-Schutz.** Kein Token in einem einzigen Template, keine Prüfung in
  einem einzigen Controller. `SameSite=Strict` mildert es ab, ersetzt es nicht.
  Das ist die größte offene Sicherheitslücke.
* **Signaling per Polling.** Kein WebSocket. Skaliert nicht und kostet Latenz.
* **Kryptografische Mängel.** Das 2FA-Secret wird mit AES-256-CBC und einem
  **statischen, aus dem Schlüssel abgeleiteten IV** verschlüsselt
  (`TwoFactorController.php:341`). `PEPPER` dient doppelt — als Passwort-Pepper
  *und* als AES-Schlüssel; eine Rotation ist damit praktisch unmöglich.
* **CDN-Einbindungen ohne Subresource Integrity.** Acht externe Skripte und
  Stylesheets, kein einziges `integrity`-Attribut. In einer Seite mit Kamera-
  und Mikrofonzugriff wiegt das schwer.
* **Der Bestand der Seitentexte ist weiterhin deutsch.** Das Fundament steht,
  die Texte ziehen Schlüssel für Schlüssel nach; 130 bekannte deutsche Literale
  stehen noch im Grundstock.
* **Keine CI, kein Linting, keine statische Analyse.** Kein `.github/`, kein
  PHPStan, kein ESLint. Die Tests laufen nur, wenn jemand sie aufruft.
* **Keine Deployment-Beschreibung.** Kein Dockerfile, keine Webserver-Konfiguration
  außer der `.htaccess` — und die wirkt nur unter Apache.
* **Impressum und Datenschutzerklärung fehlen** — die Links im Seitenfuß zeigen
  auf `#`.

### Die vorhandenen Berichte — und welcher noch aktuell ist

| Datei | Umfang | Einschätzung |
|---|---|---|
| **`README.md`** | 163 KB | **Aktuell und die maßgebliche Beschreibung.** Sie wird mit jeder Änderung mitgezogen und erklärt nicht nur *was*, sondern *warum*. Einziger bekannter Rückstand: Die Migrationsbefehle zeigen die Pipe-Form (siehe Fallstrick 1). Wer das Produkt verstehen will, liest sie. |
| **`tests/README.md`** | 114 KB | **Aktuell.** Beschreibt jede einzelne der 598 Prüfungen und — wichtiger — *warum* es sie gibt. Der Abschnitt „Grenzen" am Ende ist die ehrlichste Seite des Projekts: Er sagt, was ein grüner Lauf **nicht** beweist. |
| **`PROTOKOLL.md`** | 30 KB | **Aktuell** für Version 2 des Steuerprotokolls. Verbindlich für jeden Client. Wird zusammen mit `assets/js/protocol.js` geändert. Siehe Abschnitt 7. |
| **`BERICHT_STANDORTSUCHE.md`** | 18 KB | **Weitgehend abgearbeitet, als Fehlerbericht überholt.** Die Ursachen (fehlende Spalte `country.iso2`, fehlende Stammdaten) sind durch die Migrationen `003`/`004` behoben; die offenen Punkte 5.2 und 5.3 (fehlende Fehlerbehandlung, ungeprüfter select2-Aufruf) sind in `map.js` inzwischen adressiert. Lesenswert bleibt **Abschnitt 4** — die Geschichte der Migrationen, die nie im Repository lagen. Das ist die Lehre, nicht der Befund. |
| **`BESTANDSAUFNAHME.md`** | 98 KB | **Der Stand vom 2026-09-01 und in weiten Teilen überholt.** Das ist keine Kritik an dem Dokument — es ist die Bestandsaufnahme, aus der die Arbeit danach hervorging. Konkret: Die Abschnitte 1–9 beschreiben einen Code, den es so nicht mehr gibt (Rollenmodell mit Admin=0, ein einziger DataChannel, keine Rollen im Call, fehlender `MessageController`). Von Abschnitt 10 sind Priorität 1 und 3 abgearbeitet, Priorität 5 überwiegend. **Offen aus dieser Liste sind: 2.4 (CSRF), 2.5 (Krypto), 2.7 (SRI) und 3.1 (WebSocket-Signaling).** Der Anhang „Positiv hervorzuheben" gilt weiterhin. |

**Kurz:** Für die Gegenwart lies `README.md` und `tests/README.md`. Für einen
Client lies `PROTOKOLL.md`. `BESTANDSAUFNAHME.md` ist Projektgeschichte — mit
vier Punkten darin, die noch zu erledigen sind.

---

## 5. Was vor einem Livegang zwingend fehlt

Jeweils mit der Stelle, an der der Schalter sitzt.

### 5.1 TURN — ein eigener Server oder ein bezahltes Kontingent

**Warum.** Ohne TURN scheitert jede Verbindung zwischen zwei restriktiven Netzen
— und der Guide ist per Definition draußen und im Mobilfunk. Heute kommt TURN
von **Metered.ca** über einen Server-Proxy; der API-Key verlässt den Server
nicht. Das Kontingent ist begrenzt und kostet Geld.

**Wo der Schalter sitzt.**

* `.env`: `METERED_APP_NAME`, `METERED_API_KEY`
* `.env`: `STUN_SERVERS` — kommagetrennt, ersetzt die eingebaute Liste. Damit
  lässt sich **ohne Codeänderung** auf einen eigenen coturn wechseln.
* `class/Model/MeteredTurnService.php` — der Abruf bei Metered
* `class/Model/IceServerConfig.php` — die STUN-Liste, die immer zusätzlich geht
* `class/Controller/TurnController.php` — die Zusammenführung; antwortet
  **immer HTTP 200** mit einer verwertbaren Liste und meldet über
  `turnAvailable: false` plus `warning`, wenn nur STUN übrig ist

**Was fehlt.** Für einen eigenen coturn gibt es heute **keinen Weg**, die
Zugangsdaten zu hinterlegen — `STUN_SERVERS` nimmt ausdrücklich nur `stun:` und
`stuns:` an und verwirft TURN-Einträge. Wer weg von Metered will, muss
`IceServerConfig` um eine TURN-Quelle erweitern.

### 5.2 Mailversand

**Warum.** Ohne ihn gibt es kein Zurücksetzen von Passwörtern und keine
Bestätigung von Adressen. Heute steht der Versand auf „aus"; die Mail wird
gebaut und samt Link ins Log geschrieben.

**Wo der Schalter sitzt.**

* `.env`: `MAIL_ENABLED=1` schaltet den Versand ein
* `.env`: `SMTP_SERVER`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`
* `.env`: `APP_BASE_URL` — **ohne diesen Wert wird keine Mail verschickt und
  kein Token angelegt.** Die Adresse kommt bewusst aus der Konfiguration und
  nicht aus dem Host-Header: Sonst ließe sich ein Reset-Link auf einen fremden
  Server umbiegen.
* `class/Helper/MailGate.php` — die Auswertung der Schalter
* `class/Model/Email.php` — der Versand über PHPMailer

**Reihenfolge.** `MAIL_ENABLED` zuerst und allein. `MAIL_VERIFY_REQUIRED` erst
Wochen später — siehe Abschnitt 6.3.

### 5.3 Zertifikat und HTTPS

**Warum.** Der Browser gibt Kamera und Mikrofon nur über HTTPS frei (außer auf
`localhost`). Ohne Zertifikat ist das Produkt funktionslos.

**Wo der Schalter sitzt.**

* `.env`: `FORCE_HTTPS=1` (Vorgabe) — leitet jede Klartextanfrage um und setzt
  `secure` am Sitzungscookie
* `.env`: `TRUST_PROXY=1` — **nur**, wenn ein Reverse-Proxy davorsteht, der
  `X-Forwarded-Proto` setzt. Sonst aus lassen: Ohne Proxy wäre der Header
  Fremdeingabe.
* `.env`: `HSTS_MAX_AGE` — Vorgabe `0`, also kein Header. Erst `300` zum Testen,
  dann `31536000`. **Ein gesendetes `max-age` lässt sich nicht zurückrufen.**
* `.env`: `HSTS_INCLUDE_SUBDOMAINS`
* `class/Helper/Https.php` — die gesamte Logik

Das Zertifikat selbst ist Sache des Webservers; das Projekt bringt dafür nichts
mit.

### 5.4 Cronjob

**Warum.** Der Browser meldet alle 10 Sekunden `heartbeat`. Auf `offline` setzt
einen Nutzer nur das ausdrückliche Abmelden — und der Cronjob. Ein geschlossener
Tab meldet sich nicht ab. **Läuft der Cronjob nicht, bleibt jeder jemals
eingeloggte Nutzer dauerhaft online**, und die Karte zeigt Guides als erreichbar
an, die niemand mehr erreicht.

**Wo der Schalter sitzt.**

* `cron/check_online_status.php` — das Skript. Braucht dieselbe `.env` und
  dasselbe `vendor/` wie die Web-Anwendung, aber keinen Webserver.
* `config/presence.php` — die Frist (45 Sekunden ohne Heartbeat)
* `config/requests.php` — derselbe Lauf räumt abgelaufene Anfragen auf und
  schließt Führungen ab, deren Ende nie angekommen ist

```
* * * * * /usr/bin/php /var/www/webrtc_proj/cron/check_online_status.php
```

Kleinster Takt von cron ist eine Minute; ein verwaister Nutzer verschwindet
damit nach 45 bis rund 105 Sekunden. Zwei Zeilen mit `sleep 30` halbieren das.
Die README beschreibt in Abschnitt 4 auch die Windows-Varianten.

### 5.5 Backup

**Warum.** Es gibt ein Skript, und es **wird nicht automatisch installiert**.

**Wo der Schalter sitzt.**

* `deploy/backup/backup.sh` — sichert die Datenbank als gzip-komprimierten
  `mysqldump` und den Upload-Baum als tar.gz
* `.env`: `BACKUP_PATH` (Vorgabe `../backups`, oberhalb des Webroots),
  `BACKUP_KEEP_DAYS` (Vorgabe 14, `0` = nichts löschen)

Einzurichten: einmal von Hand laufen lassen, dann in die crontab des
Webserver-Benutzers, **mit Umleitung der Ausgabe in eine Datei** — ohne sie
verschickt cron jede Ausgabe als Mail, und das ist die einfachste Überwachung,
die es gibt. Danach prüfen, dass das Zielverzeichnis `0700` hat und die Dateien
`0600`: Ein Dump enthält Adressen, Passworthashes, 2FA-Geheimnisse und
Chatverläufe — er ist dieselbe Datenbank, nur ohne Rechteprüfung.

Zwei Dinge, die noch fehlen und die niemand für einen erledigt:

1. **Eine Kopie auf ein anderes Gerät.** Ein Backup auf derselben Platte
   überlebt keinen Plattenausfall.
2. **Ein Rückspielversuch.** Eine Sicherung, die nie zurückgespielt wurde, ist
   keine Sicherung. Der Test gehört einmal auf eine leere Datenbank gemacht.

Die `.env` wird bewusst **nicht** mitgesichert — sie enthält Datenbankpasswort,
`PEPPER`, SMTP- und Metered-Zugangsdaten im Klartext.

### 5.6 CSP scharf schalten

**Warum.** Die Content-Security-Policy steht auf `melden`: Der Browser meldet
Verstöße an die Konsole und **lässt sie durch**. Scharf ist sie erst als
`Content-Security-Policy` statt `-Report-Only`.

**Wo der Schalter sitzt.**

* `.env`: `CSP_MODE` — `aus` | `melden` (Vorgabe) | `scharf`
* `class/Helper/SecurityHeaders.php` — die Richtlinie selbst

**Wie man dorthin kommt.** Ein vollständiger Durchgang mit offener
Browserkonsole, **und der Anruf über ein fremdes Netz gehört dazu** — die
CSP muss `stun:`, `turn:` und `turns:` in `connect-src` enthalten, weil Chrome
sie dort prüft. Erst wenn die Konsole über alle Wege schweigt, auf `scharf`.

Ein Test prüft, dass die CSP jede Adresse enthält, die im Code vorkommt.
**Er prüft nicht, ob Chrome damit eine WebRTC-Verbindung zustande bringt.** Das
bleibt Handarbeit.

### 5.7 Impressum und Datenschutzerklärung

**Warum.** In Deutschland und der EU rechtlich verpflichtend, sobald die Seite
öffentlich erreichbar ist. Die Anwendung verarbeitet dabei nicht wenig:
Standortkoordinaten, Bilder, Chatverläufe, Bewertungen, Live-Video von einem
öffentlichen Ort, und sie bindet Dritte ein (Metered, Nominatim,
Kartenkacheln, mehrere CDNs).

**Wo der Schalter sitzt.**

* `assets/html/index.html:227-228` — die beiden Links im Seitenfuß. Sie zeigen
  heute auf `href="#"`.
* `lang/de.php:1018-1019` und `lang/en.php:804-805` — die Beschriftungen
  (`fuss.impressum`, `fuss.datenschutz`) sind vorhanden.

**Was zu tun ist.** Zwei Routen in `config/routes.php` mit einem öffentlichen
Recht, zwei Templates, zwei Controllermethoden — technisch eine halbe Stunde.
Der Inhalt ist das Eigentliche, und der ist keine Entwicklerentscheidung.

---

## 6. Offene Produktentscheidungen

Diese drei löst kein Code. Sie brauchen jemanden, der sie entscheidet.

### 6.1 Der Produktname

Es gibt keinen. Der Browser-Tab sagt `WebRTC-App`
(`assets/html/index.html:6`), das Verzeichnis heißt `webrtc_proj`, die README
heißt „WebRTC Remote-Guidance & Location Platform" — das ist eine Beschreibung,
kein Name.

Daran hängt mehr als ein `<title>`: die Domain, die Absenderadresse der Mails,
der Text der Guide-Bedingungen, das Impressum, der Name im App-Store, falls die
mobile App kommt. **Je später, desto teurer** — ein Name, der erst nach den
ersten Nutzern kommt, muss durch alle Kataloge, alle Mails und alle Verträge.

Technisch vorbereitet ist das **nicht**: Es gibt keinen Schlüssel `app.name` im
Katalog und keine Umgebungsvariable dafür. Wer den Namen einführt, legt
sinnvollerweise beides an, bevor er ihn an dreißig Stellen einträgt.

### 6.2 Bezahlung

Führungen sind heute kostenlos, und sie werden es nicht bleiben. **Es wird
nichts berechnet und kein Preis gespeichert.** Vorbereitet ist bewusst nur
dreierlei:

1. **`guide_profile` als eigene Tabelle.** Die späteren Abrechnungstabellen
   hängen sich an `guide_profile.user_id`; `user` bleibt die Tabelle für das
   Konto, nicht für die Geschäftsbeziehung.
2. **`terms_version`** (`GuideRole::TERMS_VERSION`). Wird die Konstante
   hochgezählt, weil Führungen kostenpflichtig werden, gilt jede ältere
   Zustimmung als überholt und der Dialog erscheint erneut — mit dem neuen Text.
   Wer wem wann zugestimmt hat, lässt sich nachträglich nicht feststellen;
   deshalb steht es von Anfang an drin.
3. **`GuideRole::accept()` und `::resign()`** als einzige Stellen des
   Rollenwechsels. Die Prüfungen, die später dazukommen (Auszahlungsdaten
   hinterlegt? Beträge offen?), gehören dorthin und sonst nirgendwohin.

Die offenen Fragen: Wer bezahlt wen — Kunde an Plattform, Plattform an Guide,
oder Kunde an Guide mit Provision? Pro Führung, pro Minute oder als Abonnement?
Wer trägt die TURN-Kosten? Was passiert bei einer abgebrochenen Führung? Welcher
Zahlungsdienstleister, und mit welchen Pflichten (KYC, Rechnungen, Umsatzsteuer
über Ländergrenzen)? **Keine davon ist technisch.** Sie entscheiden aber, wie
die Abrechnungstabellen aussehen.

Eine Entscheidung hängt sichtbar daran: `config/uploads.php` hat
`max_images_per_location = 5` als **eine Zahl, nicht als Tabelle**. Gedacht ist,
dass ein Guide mit bezahltem Zugang mehr Bilder zeigen darf. Gelesen wird die
Grenze ausschließlich über `ImageStore::maxImages($user_id)` — kommt die
Staffelung, bekommt diese eine Methode ihre Abfrage, und kein Aufrufer ändert
sich.

### 6.3 Wird `email_verified` erzwungen?

Der Schalter ist gebaut und steht auf **aus** (`MAIL_VERIFY_REQUIRED=0`). Die
Spalte `user.email_verified` wird gepflegt und sonst nirgends gefragt.

Schaltet man ihn an, sperrt `MailGate::sperrt()` sechs Routen für Konten ohne
bestätigte Adresse:

| Gruppe | Routen |
|---|---|
| Anfragen | `request_create` |
| Chat | `chat_start`, `chat_start_direct`, `chat_send_message` |
| Hochladen | `upload_location_image`, `guide_profile_save` |

Bewusst **nicht** gesperrt: das **Lesen** im Chat (wer angeschrieben wurde, soll
die Antwort sehen können, auch während seine Bestätigung aussteht), die
**Anmeldung** selbst, und das **Beantworten** einer Anfrage — ein Guide, der
zusagt, lässt sich auf einen Termin ein, den ein anderer gesetzt hat; ihn dabei
zu sperren träfe den anfragenden Kunden.

**Die Entscheidung ist nicht „ja oder nein", sondern „wann und wie".** In dem
Moment, in dem der Versand eingeschaltet wird, hat **kein einziges
Bestandskonto** eine bestätigte Adresse — es wurde nie eine Bestätigungsmail
verschickt. Wer beide Schalter gleichzeitig umlegt, sperrt seine gesamte
Nutzerschaft aus. Genau deshalb sind es zwei Schalter und nicht einer.

Der gangbare Weg: `MAIL_ENABLED=1` einschalten, Bestandskonten anschreiben,
Wochen vergehen lassen, die Quote beobachten, dann `MAIL_VERIFY_REQUIRED=1`.

Zu entscheiden ist außerdem, ob die sechs Routen die richtigen sind. Wer das
anders sieht, trägt Routennamen in `MailGate::PFLICHTROUTEN` ein — außerhalb
dieser Liste ist dafür nichts anzufassen. Aufgezählt werden **Routen und keine
Rechte**, weil `location.edit_own` sowohl das Hochladen eines Bildes als auch
das Ändern eines Textes trägt; über das Recht gesperrt wäre ein unbestätigtes
Konto auch seine eigenen Texte nicht mehr los.

---

## 7. Die geplante mobile App

Der Guide trägt sein Handy — eine native App ist der naheliegende nächste
Schritt. Was dafür bereitliegt und was nicht.

### Was bereitliegt

**`PROTOKOLL.md` ist die Grundlage, und sie ist verbindlich.** Ein Client ist
genau dann kompatibel, wenn er sich daran hält. Darin steht:

* **Das Rahmenformat.** Jede Textnachricht auf beiden Kanälen ist ein
  JSON-Objekt mit den Pflichtfeldern `v` (Version, aktuell `2`) und `type`.
  Höchstens 4096 Byte je Frame. Zahlen sind Zahlen, keine Zeichenketten.
  Unbekannte Felder werden ignoriert — ein Sender darf innerhalb derselben
  Version zusätzliche mitschicken.
* **Die Versionierung.** Der Empfänger vergleicht `v` **exakt**. Keine
  Toleranz, keine Aushandlung. Version 1 und Version 2 verstehen einander gar
  nicht — mit Absicht, statt sich über eine unbekannte Blickrichtung halb zu
  verstehen.
* **Zwei getrennte DataChannels.** `chat` trägt nur Nutzerinhalt (Text,
  Dateien), `control` nur Protokoll. Beide legt der Anrufer an; der Angerufene
  ordnet sie **am Label** zu, nicht an der Reihenfolge des Eintreffens. Ein
  Kanal mit unbekanntem Label wird geschlossen. Der Grund für die Trennung: Ein
  in den Chat getippter Text löste früher Steuerbefehle aus.
* **Die Rollen** `guide`, `viewer`, `peer` — sie kommen vom Server, hängen am
  Offer und lassen sich vom Client nicht setzen. Der Client muss die
  Standortkennung mitschicken; der Server prüft sie, statt sie zu glauben.
* **Alle acht Nachrichtentypen** mit Feldern, Richtung und Bedeutung: `hello`,
  `move` (sechs Richtungen inklusive `look_up`/`look_down`), `ack`,
  `control_lock`, `video_state`, `hangup`, `chat`, Binärframe.
* **Die Prüfregeln** für eingehende Nachrichten und was mit einer abgelehnten
  passiert.
* **Der Zustand je Call** — `callRole`, `nextSeq`, `pendingSeq`,
  `lastRemoteSeq`, `locked`, `helloSent` — und dass alles davon beim Auflegen
  geleert wird.
* **Abschnitt 9: die Grenzen.** Kein Stopp, keine Geschwindigkeit, keine
  Schrittweite, keine Zielpunkt-Markierung, keine Steuerungsanfrage, strikt 1:1,
  keine empfangsseitige Ratenbegrenzung, keine Größen- oder Typbegrenzung für
  Dateien.

Dazu kommt `assets/js/protocol.js` — die **maschinenlesbare Fassung derselben
Tabelle**. Beide werden zusammen geändert. Für eine Portierung nach Kotlin oder
Swift ist das die Vorlage; abschreiben kann man sie nicht, aber die Struktur
steht.

Außerdem brauchbar: **33 der 62 Routen antworten bereits mit JSON** —
`get_map_locations`, `get_locations`, `get_requests`, `request_create`,
`request_accept`, `chat_get_messages`, `getSignal`, `get_turn_credentials`,
`heartbeat` und weitere. Der Fachablauf ist über diese Endpunkte erreichbar,
ohne dass HTML geparst werden muss.

### Was neu gebaut werden muss

**1. Authentifizierung.** Heute hängt alles an einem PHP-Sitzungscookie mit
`SameSite=Strict` und `httponly`. Eine native App hat keinen Cookie-Jar im
Browsersinn und keine Same-Site-Semantik. Nötig ist ein Token-Verfahren —
Ausgabe beim Login, Prüfung in `Auth`, Ablauf und Erneuerung. Das betrifft
`class/Helper/Auth.php`, `config/session.php` und jede Route. **Das ist der
größte Posten.**

**2. Ein Signaling, das ein Handy übersteht.** Polling im 1500-ms-Takt ist auf
einem Mobilgerät teuer: Es hält das Funkmodul wach und leert den Akku. Und ein
Hintergrundprozess, der pollt, wird von iOS und Android beendet. Nötig sind
WebSockets **und** Push (APNs/FCM) für den eingehenden Anruf — eine Anfrage, die
ankommt, während die App geschlossen ist, erreicht heute niemanden.

**3. Die Oberfläche komplett.** Der Server liefert fertiges HTML aus
`assets/html/` über die `*View`-Helper. Für eine App ist davon **nichts**
wiederverwendbar. Entweder die Ansichten werden nativ nachgebaut — dann brauchen
die verbleibenden 29 HTML-Routen JSON-Gegenstücke — oder man baut einen WebView,
und dann hat man die Vorteile einer App außer der Kamera nicht.

**4. Die Sprachkataloge.** `lang/de.php` und `lang/en.php` sind PHP-Arrays. Für
eine App müssen sie exportiert werden (ein Endpunkt oder ein Build-Schritt) oder
gedoppelt — und gedoppelt heißt, dass sie auseinanderlaufen.

**5. Medien und Berechtigungen.** `getUserMedia` im Browser und die
Kameraberechtigung einer nativen App sind zwei verschiedene Welten, mit
verschiedenen Fehlerfällen und verschiedenen Dialogen. Der ganze Pfad in
`media.js` ist Browser-Code.

### Wo die Fallstricke stehen

* **Der Zuschauer sendet keine Medien.** Das ist keine Protokollregel, sondern
  eine Anwendungsregel (`maySendMedia` in `media.js`) — und sie ist leicht zu
  übersehen, weil im Protokoll nichts davon steht. Eine App, die aus Gewohnheit
  beide Spuren anbietet, verhält sich anders als der Webclient.
* **Die exakte Versionsprüfung ist ein Bruch, kein Übergang.** Sobald die App
  eine Version 3 braucht, verstehen App und Webclient einander gar nicht mehr —
  es sei denn, Version 3 wird ausdrücklich abwärtskompatibel gebaut. Das muss
  **vor** der ersten Protokolländerung entschieden werden, nicht danach.
* **`ordered: true`** ist die Voreinstellung beider Kanäle. Wer in der App aus
  Performancegründen unordered aufmacht, bricht die Sequenznummernlogik.
* **Keine empfangsseitige Ratenbegrenzung.** Ein manipulierter Peer kann
  beliebig viele gültige `move`-Nachrichten schicken; gebremst wird nur beim
  Sender (100 ms je Schaltfläche plus die ausstehende Bestätigung). Ein nativer
  Client ohne diese Bremse flutet den Guide. Die Bremse gehört in jeden Client
  **und** perspektivisch auf die Empfangsseite.
* **Netzwechsel.** Der Guide bewegt sich per Definition draußen. Der Webclient
  hat dafür Grace-Timer und ICE-Restart (`rtc.js`); eine App braucht dasselbe,
  und sie braucht zusätzlich das, was der Browser ihr abnimmt: den Wechsel
  zwischen WLAN und Mobilfunk mitzubekommen.
* **Was die Tests nicht abdecken**, deckt auch die App nicht ab: „der echte
  Wechsel zwischen WLAN und Mobilfunk auf zwei Geräten" und „das tatsächliche
  Timing eines ICE-Restarts über einen TURN-Server" stehen ausdrücklich unter
  „Grenzen" in `tests/README.md`. **Ein grüner Durchlauf ersetzt keinen Test mit
  zwei echten Geräten** — für die App gilt das doppelt.
* **Dateien im Chat haben keine Grenze.** Keine Größen-, Typ- oder
  Anzahlbegrenzung, keine Metadaten. Auf einem Mobilgerät ist das ein Weg, den
  Speicher vollzuschreiben.

**Was in `PROTOKOLL.md` noch fehlt**, wenn eine App danach gebaut werden soll:
die Authentifizierung (dort steht nichts dazu, weil es im Browser das Cookie
gibt), das Signaling-Format über HTTP (`getSignal` ist nur im Code
beschrieben), das Verhalten beim Wechsel in den Hintergrund, und eine Aussage
dazu, wie ein Client sich beim Verbindungsverlust verhalten soll — der
Webclient tut das Richtige, aber es steht in `rtc.js` und nicht im Protokoll.

---

## 8. Konventionen des Projekts

Wer hier weiterarbeitet, sollte sich an vier Dinge halten. Sie sind nicht
verhandelbar in dem Sinne, dass ein Testlauf sie durchsetzt.

### Deutsche Kommentare, und sie sagen *warum*

Jede Klasse, jede nicht offensichtliche Methode und jede Reihenfolge, die eine
Aussage ist, trägt einen deutschen Kommentar. Der erklärt nicht, *was* die Zeile
tut — das steht da —, sondern **warum sie so ist und was passiert ist, als sie
anders war**. Beispiel aus `index.php`:

> *„Die Sitzung wird ZULETZT gestartet, nicht mehr an zweiter Stelle. Ihr Cookie
> trägt das Merkmal `secure`, und ob das gesetzt werden darf, hängt an
> FORCE_HTTPS — einem Wert aus der `.env`. Solange die Sitzung vor
> `config/env.php` stand, gab es diesen Wert zum Zeitpunkt der Entscheidung noch
> nicht; das Merkmal war deshalb fest verdrahtet und sperrte jede lokale
> Entwicklung über `http://` aus."*

Das ist der Ton. Er ist ausführlich, und das ist Absicht: Die
Bestandsaufnahme hob die Docblocks ausdrücklich als das hervor, was die Analyse
überhaupt möglich gemacht hat. Auch die Commit-Nachrichten folgen dem — sie sind
Sätze, keine Präfixe (`„Der Weg zum vergessenen Passwort — und eine Ratsche für
die nächste Sackgasse"`).

Ausgenommen sind `lang/` (dort gehören deutsche Sätze hin) und Logeinträge —
ein Logeintrag richtet sich an den Betreiber und wird nie übersetzt.

### Die eine Stelle für Konfiguration

Jede Zahl, jede Frist, jede Grenze steht **genau einmal**, in einer Datei unter
`config/`, mit einem Kommentar, der sie begründet:

| Datei | Was |
|---|---|
| `config/routes.php` | Routen samt Recht und Antwortart |
| `config/limits.php` | die Bremse: Versuche und Fenster je Endpunkt |
| `config/presence.php` | Heartbeat-Takt und Offline-Frist |
| `config/requests.php` | Antwortfrist, Kulanzzeit, Vorlaufzeit |
| `config/uploads.php` | Ablagepfad, Anzahl, Dateigröße, Kantenlängen, JPEG-Qualität |

Die Werte gehen von dort an ihre Verbraucher — **auch an den Browser**. Die
Upload-Grenzen erreichen `location_page.js` über `window.uploadLimits`, damit
eine zu große Datei gemeldet wird, *bevor* acht Megabyte übertragen sind. Eine
zweite Zahl im JavaScript wäre eine Zahl, die niemand mit der ersten zusammen
pflegt.

Dasselbe Muster bei den Schaltern: `App\Helper\Env` ist die eine Stelle, an der
ein Wert aus der Umgebung kommt — `$_SERVER`, dann `$_ENV`, dann `getenv()`. Ein
Tippfehler fällt auf die Vorgabe zurück **und wird protokolliert**;
stillschweigend `false` daraus zu machen hieße bei `FORCE_HTTPS`, dass ein
Vertipper die Weiterleitung abstellt und es niemandem auffällt.

Und bei den Lesestellen: Die Bildobergrenze wird ausschließlich über
`ImageStore::maxImages($user_id)` gelesen, nie direkt aus dem Array — damit die
spätere Staffelung je Konto genau eine Methode betrifft.

### Die Ratschen in den Tests, und warum sie da sind

Zwei Skripte, **ohne Framework, ohne Datenbank, ohne Netz**: keine
Composer-Dev-Abhängigkeit, kein npm-Paket, keine Konfigurationsdatei. Jedes ist
ein einzelner Aufruf, der durchläuft oder mit Exit-Code 1 abbricht. Geprüft wird
der **produktive Code**, nicht eine Nachbildung davon.

```bash
php  tests/server_test.php    # 410 Prüfungen
node tests/client_test.js     # 188 Prüfungen
```

Neben den gewöhnlichen Prüfungen enthalten sie **Ratschen** — Prüfungen, die
nicht eine Funktion absichern, sondern **einen Rückfall verhindern**. Jede
einzelne steht für einen Fehler, der schon einmal passiert ist:

* **Die Sprachratsche.** `tests/i18n_scan.php` durchsucht `class/`,
  `assets/js/` und `assets/html/` nach nackten deutschen Texten. Was es **heute**
  gibt, steht in `tests/i18n_grundstock.txt` (130 Stellen) und darf bleiben. Was
  **neu** dazukommt, lässt den Testlauf fehlschlagen.
  *Warum:* Ein Sprachkatalog ist schnell gebaut; was ihn kaputt macht, ist der
  Alltag. Jemand ergänzt einen Knopf, schreibt „Speichern" hinein, niemandem
  fällt es auf — der Test war ja grün. Nach einem halben Jahr sind es
  zweihundert Stellen und die Anwendung ist wieder halb deutsch.
  Der Grundstock darf neu geschrieben werden, **wenn Texte umgezogen sind** —
  und ausdrücklich nicht, um einen neuen deutschen Satz durchzulassen. Wer das
  tut, sieht es im Diff: Dort steht dann eine Zeile *mehr*.
* **Keine Vergleichsoperatoren auf Rollenwerten.** Ein Suchlauf über `class/`,
  `config/`, `cron/`, `index.php` und `assets/js` meldet jedes `<`, `>`, `==`,
  `===` auf `role_id`, `type_id`, `getRoleId()` und den übrigen Schreibweisen.
  *Warum:* `role_id > 1` unterstellte eine Rangfolge, die es nicht gibt, und
  `=== 1` traf den Guide statt den Admin. Das waren drei Fehler im
  `UserController` und der Grund, warum der Knopf „Neue Lokation hinzufügen" für
  **alle** Rollen unsichtbar war. Die Regel prüft sich zuerst selbst an sechs
  verbotenen und sechs erlaubten Beispielzeilen — schlägt sie dort nicht an, ist
  der Suchlauf wertlos und der Test bricht ab.
* **Jede Route trägt ein bekanntes Recht.** Und die Prüfung schlägt bei einer
  Route ohne Recht, mit leerem Recht, mit erfundenem Recht und mit unbekannter
  Antwortart auch wirklich an.
  *Warum:* Drei Endpunkte waren einmal völlig ungeschützt.
* **Jeder Platzhalter im Template wird auch gefüllt.** Jedes `###MARKE###` muss
  im zugehörigen Controller vorkommen.
  *Warum:* Ein vergessener Platzhalter steht sonst wörtlich auf der Seite.
* **Eigentum steht in der WHERE-Klausel.** `updateLocation()` und
  `deleteLocation()` setzen `AND user_id = :user_id` ab; ohne Standort-ID oder
  ohne Benutzer erreicht gar kein Statement die Datenbank.
* **Keine Sackgassen.** Die Navigation des Verwaltungsbereichs zeigt genau die
  erlaubten Reiter, der aktive ist kein Verweis auf sich selbst, und ein Guide
  bekäme keinen einzigen. Ebenso: Die Dialogseite muss dem Guide ein `accept`
  anbieten — sonst führt die Weiterleitung dorthin ins Nichts.
* **Keine Sonderfälle in der Kundenoberfläche.** An sieben Stellen wird geprüft,
  dass die Adminfälle **weg sind und sich nicht wiederbeleben lassen**: keine
  Sperrknöpfe in `locations_table.js`, kein `rev-remove` in `ReviewView`, kein
  `act=review_remove` in `review.js`.
* **Keine ausgeschriebene Farbe in `assets/css/admin.css`.** Weder `#rgb` noch
  `rgb()`.
  *Warum:* Eine feste Farbe bliebe beim Wechsel des Farbprofils stehen, und dann
  sähe genau eine Seite der Anwendung falsch aus.
* **Jeder Schalter steht in der `.env.example`.** Gesucht wird nach den
  **Aufrufen** (`Env::schalter(…)`, `Env::zahl(…)`, `Env::auswahl(…)`), nicht
  nach einer von Hand gepflegten Liste. Jeder gefundene Schlüssel muss in
  `.env.example` **und** in der README vorkommen.
  *Warum:* Ein Schalter, den der Code liest und den niemand dokumentiert, ist
  ein Schalter, den niemand findet.
* **Beide Sprachkataloge haben dieselben Schlüssel** und dieselben
  Pluralformen, und keiner enthält Markup.

**Das Muster dahinter:** Ein Fehler wird behoben, *und* es wird eine Prüfung
danebengestellt, die verhindert, dass er wiederkommt. Deshalb heißen sie
Ratschen — sie lassen sich in eine Richtung drehen und nicht zurück.

**Was sie nicht können**, steht in `tests/README.md` unter „Grenzen": kein
echtes Netzverhalten, keine echte Datenbank, kein Browser, kein Backup-Lauf,
keine tatsächliche Geometrie einer Seite. Geprüft wird die Regel, nicht das
Ergebnis. Ein grüner Durchlauf ersetzt keinen Test mit zwei echten Geräten.

### Kleinere Gepflogenheiten

* **Ein Einstiegspunkt.** Alles läuft über `index.php`. Keine zweite PHP-Datei
  im Webroot, die man direkt aufruft.
* **Ausschließlich Prepared Statements**, `ATTR_EMULATE_PREPARES = false`.
  Interpoliert wird nur mit codeseitigen Konstanten.
* **Löschen heißt ausblenden**, wo es um fremde Inhalte geht: Ein Admin löscht
  keinen fremden Standort, er sperrt ihn; eine Bewertung wird ausgeblendet, die
  Zeile bleibt samt Zeitpunkt, Entferner und Grund. Gelöscht wird nur vom
  Eigentümer.
* **Kein Weg hängt an JavaScript.** Was ohne JS wegfällt, darf kein Weg sein —
  ein eigener Abschnitt in der README.
* **Zustände als Text, nicht als Zahl.** In einem Dump soll lesbar sein, was
  passiert ist, ohne eine Codetabelle danebenzulegen.
* **Auskommentierter Code ist kein ausgeschalteter Code** — er ist Code, der
  nicht mehr geprüft und nicht mehr gepflegt wird. Wo etwas abschaltbar sein
  soll, steht ein Schalter in der `.env`, und der Code läuft immer.
