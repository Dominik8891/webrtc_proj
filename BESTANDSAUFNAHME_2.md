# Bestandsaufnahme 2 — WebRTC Remote-Guidance & Location Platform

Stand: 2026-09-03 · Branch `claude/nifty-cray-yvftp9` · Commit `a0b09fd`

Fortschreibung von [`BESTANDSAUFNAHME.md`](BESTANDSAUFNAHME.md) (Stand 2026-09-01,
Commit `c7730f1`). Dazwischen liegen **41 Commits** aus mehreren Branches.

Dies ist ein **Bericht, kein Umbau** — an der Anwendung wurde für dieses Dokument
nichts geändert.

---

## 0. Vorbemerkung: welche Listen hier fortgeschrieben werden

Im Repository liegen genau zwei ältere Befundlisten. Eine Datei mit dem Namen
„Bericht zu den fehlenden Funktionen" gibt es nicht; gemeint ist damit
erkennbar das Lückenkapitel der alten Bestandsaufnahme. Fortgeschrieben werden
deshalb:

| Quelle | Was darin steht |
|---|---|
| `BESTANDSAUFNAHME.md`, Abschnitte 9.1–9.6 | Einzelbefunde H-1…H-6, F-1…F-18, S-1…S-16, tote Pfade, fehlende Fehlerbehandlung |
| `BESTANDSAUFNAHME.md`, Abschnitt 10 | die fünf Prioritätsblöcke („LÜCKEN") — das ist die Liste der fehlenden Funktionen |
| `BERICHT_STANDORTSUCHE.md`, Abschnitt 5 | vier offene Punkte an der Standortauswahl |

Falls mit „Bericht zu den fehlenden Funktionen" ein Dokument gemeint war, das
nur im Chat geliefert und nie eingecheckt wurde: Es ist im Repository und in der
gesamten Git-Historie nicht auffindbar (`git log --all -- "*.md"` kennt nur die
oben genannten Dateien plus `README.md`, `PROTOKOLL.md`, `tests/README.md`).

### Kennzahlen heute

| | |
|---|---|
| Versionierte Dateien | 126 |
| PHP | 8.996 Zeilen |
| JavaScript | 6.995 Zeilen |
| CSS | 4.503 Zeilen |
| Routen | 52, jede mit Pflicht-Recht |
| Migrationen | 9 |
| Automatische Prüfungen | 178 (63 Client, 115 Server) |

### Aufwandsschätzung — Maßstab

| Kürzel | Bedeutung |
|---|---|
| **XS** | unter 2 Stunden |
| **S** | ein halber bis ein Tag |
| **M** | ein bis drei Tage |
| **L** | eine bis zwei Wochen |
| **XL** | mehr als zwei Wochen |

Die Schätzungen gelten für eine Person, die den Code kennt, und schließen Test
und Dokumentation im hier üblichen Umfang ein.

---

## 1. Erledigt

### 1.1 Priorität 1 der alten Liste — vollständig erledigt

| Alt | Befund | Wo es heute steht |
|---|---|---|
| 1.1 | `database.sql` passt nicht zum Code | Schema abgeglichen (`41f07de`), sechs Migrationen nachgetragen, `country.iso2` samt 248 Ländern ergänzt (`d34bc32`) |
| 1.2 / H-1 | `MessageController` fehlt, zwei tote Routen | Routen entfernt (`de81bb7`), keine Referenz mehr in `config/routes.php` |
| 1.2 / H-2 | Registrierung bricht auf undefinierter Variable ab | `class/Controller/SignupController.php:77` weist `$out` wieder zu |
| 1.2 / H-3 | `SystemController::pwdEncrypt()` existiert nicht | `class/Controller/UserController.php:82` ruft `User::pwdEncrypt()` |
| 1.2 / H-4, H-5 | `showStart()`, `output_fe()`, Verzeichnis `assets/html/frontend/` | Route `start` entfernt; nur noch der Kommentar in `config/routes.php:56-59` erinnert daran |
| 1.3 / F-5, F-6 | Groß-/Kleinschreibung bei Rollennamen blendet den Standort-Button für alle aus | Rollen laufen über `App\Helper\Role` (IDs statt Namen), Anzeige über `window.userCan` aus `class/Helper/ViewHelper.php:249-254` |

### 1.2 Priorität 2 — Sicherheit: sechs von sieben Blöcken ganz oder überwiegend erledigt

| Alt | Befund | Stand |
|---|---|---|
| 2.1 / S-2 | Fremde Standorte löschbar/änderbar | **erledigt.** `LocationController.php:215` prüft `belongsToUser()`, `:247` gibt die `user_id` bis in die WHERE-Klausel weiter |
| 2.1 / S-1 | Fremde Chats lesbar | **teilweise.** `getMessages()` prüft die Beteiligung (`ChatController.php:141`), `acceptChat()` und `setMessagesSeen()` **nicht** — siehe Abschnitt 2.1 |
| 2.2 / S-3 | Gespeichertes XSS in der Standortliste | **erledigt.** `assets/js/locations_table.js:111` `esc()`, an 12 Stellen angewandt |
| 2.2 / S-4 | Gespeichertes XSS im Chat | **erledigt.** `assets/js/ui_chat.js:190` `esc()`, Nachrichtentext über `:463` |
| 2.3 / S-8, S-16 | 2FA-Secrets im Klartext im Webroot-Log | **erledigt.** 13 Logzeilen entfernt (`beff61f`); Log liegt über `config/log_path.php` außerhalb des Webroots, `.htaccess` sperrt zusätzlich `*.log`, `*.sql`, `*.md`, `.env`, `composer.*` |
| 2.5 / S-10 | Kein `session_regenerate_id()` nach 2FA | **erledigt.** `class/Controller/TwoFactorController.php:217` |
| 2.4 / S-5 | Kein CSRF-Schutz | **offen** — siehe Abschnitt 2.1 |
| 2.5 / S-9 | Statischer IV bei AES-256-CBC | **offen** — siehe Abschnitt 2.1 |
| 2.6 / S-7 | Ungebremster Signaling-Endpunkt | **offen** — siehe Abschnitt 2.1 |
| 2.7 / S-15 | CDN ohne SRI, teils ohne Version | **offen** — siehe Abschnitt 2.1 |

Nicht in der alten Liste, aber im selben Zug erledigt: **eine zentrale
Rechteprüfung**. Vorher prüfte jeder Controller selbst und mehrere vergaßen es.
Heute trägt jede der 52 Routen ihr Recht als Pflichtfeld
(`config/routes.php`), `index.php:60-64` prüft die **gesamte** Tabelle bei jedem
Aufruf und verweigert den Dienst bei einem Konfigurationsfehler, statt die Route
durchzulassen. Das ist die strukturell wichtigste Änderung seit der letzten
Bestandsaufnahme — sie macht ein Wiederauftreten von S-1/S-2 als *vergessene*
Prüfung praktisch unmöglich.

### 1.3 Priorität 3 — Betriebsfähigkeit: vier von sechs erledigt

| Alt | Befund | Stand |
|---|---|---|
| 3.2 / F-1 | Race Condition verliert ICE-Kandidaten | **erledigt.** `WebRTCController.php:80-88` merkt die gelesenen IDs, `WebRTCHandler::deleteSignalsByIds()` löscht nur diese |
| 3.3 / F-3, F-2 | Auflegen erreicht die Gegenseite nicht | **erledigt.** `hangup` läuft über den Steuerkanal (`assets/js/control.js:290`) **und** über das Polling (`assets/js/rtc.js:106`), doppelte Zustellung wird über `state.hangupReceived` abgefangen |
| 3.4 | Kein ICE-Restart, Abbruch bei `disconnected` | **erledigt.** Grace-Timer (`assets/js/rtc.js:684-720`), `createOffer({ iceRestart: true })` (`:830`), Zustandsanzeige mit sechs Stufen (`:971`) |
| 3.5 / F-17, F-18 | Kein STUN-Fallback, `turns:` verworfen | **erledigt.** `class/Model/IceServerConfig.php` mit drei Vorgabeservern und `STUN_SERVERS` aus der `.env`; `turns:` im Filter (`assets/js/rtc.js:554`); Fehlerbehandlung mit `iceServersDegraded` (`:233`) |
| 3.6 / F-16 | Cronjob hebt seine eigene Wirkung auf | **erledigt.** `cron/check_online_status.php:32` `AND user_status <> 'offline'`; Takt und Timeout zentral in `config/presence.php` (10 s / 45 s) |
| 3.1 | Polling-Signaling skaliert nicht | **offen** — siehe Abschnitt 2.3 |

### 1.4 Priorität 4 — Steuerprotokoll: fünf von sechs erledigt

Der inhaltliche Kern der alten Bestandsaufnahme ist weitgehend abgearbeitet und
in [`PROTOKOLL.md`](PROTOKOLL.md) (Version 1, 528 Zeilen) beschrieben.

| Alt | Befund | Stand |
|---|---|---|
| 4.1 | Kein Rollenmodell im Call | **erledigt.** Der Server vergibt Guide/Zuschauer und stempelt sie ans Offer (`WebRTCController::roleForCall()`, `::stampCallRoles()`); der Client kann sich keine Rolle geben |
| 4.2 | Unbekannte Befehle werden zu Chattext | **erledigt.** `assets/js/protocol.js` als einzige Quelle, Allowlist mit Verwerfen und Protokollieren (`control.js:logRejected`) |
| 4.3 | Keine Protokollversionierung | **erledigt.** Versionsfeld im Rahmenformat, `PROTOKOLL.md` Abschnitt 2 |
| 4.4 | Steuerung und Nutzdaten auf einem Kanal | **erledigt.** Zwei Kanäle: `control` (unzuverlässig, kurze Lebensdauer) und `chat` |
| 4.5 | Keine Rückmeldung, kein Zustand | **überwiegend erledigt.** `ack`, `control_lock`, `video_state`, Richtungsanzeige. Offen bleiben Blick oben/unten, Stopp, Geschwindigkeit, Dauer — bewusst, siehe `PROTOKOLL.md` Abschnitt 9 |
| 4.6 | README beschreibt Pfeiltasten, die es nicht gibt | **halb erledigt.** Die README nennt jetzt korrekt das Steuerkreuz. Eine Tastatursteuerung existiert weiterhin nicht — siehe Abschnitt 3.6 |

### 1.5 Priorität 5 — Produktivbetrieb: vier von acht erledigt

| Alt | Befund | Stand |
|---|---|---|
| 5.1 | Keine Tests | **erledigt.** 178 Prüfungen in zwei Skripten ohne Framework, DB und Netz (`tests/`) |
| 5.3 | Keine Migrationen | **erledigt.** `migrations/001`–`009`, idempotent, jeweils mit Begründung und Ausführungsbefehl im Kopf |
| 5.4 | Kein strukturiertes Logging | **teilweise.** Logpfad außerhalb des Webroots (`config/log_path.php`), Logrotation vorbereitet (`deploy/logrotate/webrtc-app`), Secrets aus dem Log entfernt. Log-Level, Korrelation über einen Call und `getStats()`-Auswertung fehlen weiter |
| 5.6 | Keine `.env.example` | **erledigt.** `.env.example` mit allen im Code gelesenen Schlüsseln (`2c1e82c`) |
| 5.7 | Keine Aufräumstrategie für `rtc_signal` | **teilweise.** `deleteExpiredSignalsForReceiver()` räumt beim Poll auf; Zeilen für Empfänger, die nie wieder pollen, bleiben liegen — siehe Abschnitt 2.3 |
| 5.2 | Keine CI, kein Linting | **offen** — siehe Abschnitt 2.4 |
| 5.5 | Keine Deployment-Beschreibung | **teilweise** — `.htaccess` und Logrotation da, sonst offen; siehe Abschnitt 2.4 |
| 5.6b | Kein PHP-Versions-Constraint | **offen.** `composer.json` hat weiterhin kein `"php": ">=8.1"` |
| 5.8 | Doppelte Logik, tote Dateien | **überwiegend erledigt.** Drei nicht eingebundene Stylesheets gelöscht (`c5e35a1`), doppelte Registrierung von `initChatUI()` behoben, Pfeil-Übersetzung zentralisiert. Reste siehe Abschnitt 2.5 |

### 1.6 Einzelbefunde 9.1–9.6 im Überblick

**Erledigt (34):** H-1, H-2, H-3, H-4, H-5 · F-1, F-2, F-3, F-4, F-5, F-6, F-7,
F-8, F-10, F-11, F-12, F-16, F-17, F-18 · S-1 (teilweise), S-2, S-3, S-4, S-6,
S-8, S-10, S-12 (teilweise), S-13, S-16 · sowie die Fehlerbehandlung in
`ViewHelper::template()`, `signaling.js`, `loadIceServers()`, `map.js`,
`PdoConnect`, `WebRTCController` (kein `getMessage()` mehr an den Client).

**Offen (11):** H-6 · F-9, F-13, F-14, F-15 · S-5, S-7, S-9, S-11, S-14, S-15.
Im Einzelnen unten.

---

## 2. Noch offen

Sortiert nach Schwere. Jeder Punkt mit Datei, Zeile und Aufwand.

### 2.1 Sicherheit — vor jedem öffentlichen Betrieb zu schließen

#### S-NEU-1 · Jeder Angemeldete kann in jeden fremden Chat schreiben — **XS**

`class/Controller/ChatController.php:178-196`

`sendMessage()` nimmt die `chat_id` aus der Anfrage und schreibt, ohne zu
prüfen, ob der Aufrufer an diesem Chat beteiligt ist. Die IDs sind
fortlaufend; ein Durchzählen genügt. Jedes angemeldete Konto hat das Recht
`chat.write` (`class/Helper/Permission.php`), also **jedes** Konto.

Das ist **nicht** derselbe Befund wie das alte S-1: Die alte Liste nannte
`acceptChat`, `getMessages` und `setMessagesSeen`. `sendMessage` stand dort
nicht und ist beim Nachziehen der Prüfungen übersehen worden — `getMessages`
hat die Prüfung inzwischen (`:141`), die Schreibmethode daneben nicht.

Nebenbefund an derselben Stelle: `ChatMessage::add()` kann `null` liefern
(`:187`), der Rückgabewert wird `:189` ungeprüft dereferenziert → HTTP 500.
Und der Text hat serverseitig keine Längenbegrenzung; die Spalte ist `text`,
also bis 64 KB je Nachricht. Der Call-Chat kennt eine Grenze
(`protocol.MAX_CHAT_TEXT`), der HTTP-Chat nicht.

**Fix:** dieselben vier Zeilen wie in `getMessages()`, plus Null-Prüfung und
Längenbegrenzung.

#### S-1 (Rest) · `acceptChat` und `setMessagesSeen` ohne Beteiligungsprüfung — **XS**

`class/Controller/ChatController.php:62-72` und `:238-251`

`acceptChat()` erzeugt ein Chat-Objekt allein aus der ID und setzt es auf
aktiv — ohne die Zeile überhaupt zu laden. Jede fremde Einladung lässt sich
damit annehmen. `setMessagesSeen()` markiert Nachrichten in beliebigen Chats
als gelesen; die `sender_id` kommt zudem aus der Anfrage statt aus der
Sitzung.

#### S-NEU-2 · Passwort-Änderung ist ein ungebremstes Rate-Orakel — **S**

`class/Controller/PasswordController.php:175` und `:198`

Der Benutzername kommt aus der Anfrage, nicht aus der Sitzung. Die
Fehlermeldung „Das alte Passwort ist nicht korrekt!" unterscheidet damit für
**jedes** Konto zwischen richtigem und falschem Passwort — ohne Zähler, ohne
Sperre, ohne Bindung an den Angemeldeten. Wer das alte Passwort errät, setzt
das fremde Konto direkt auf ein neues.

Die Lockout-Logik des Logins (`LoginController.php:47-62`) greift hier nicht;
sie hängt am Formular `login`, nicht am Passwort.

Die alte Bestandsaufnahme hat diesen Punkt als Nebensatz von S-12 genannt
(„die Bindung an den eingeloggten Nutzer fehlt"). Behoben wurde nur die andere
Hälfte, der Null-Check. **Fix:** `Auth::userId()` statt `Request::g('username')`.

#### S-NEU-3 · Der Brute-Force-Schutz des Logins ist wirkungslos — **S**

`class/Controller/LoginController.php:47-62`, `:103-113`

Zähler und Sperre liegen in `$_SESSION`. Ein Angreifer, der pro Versuch kein
Session-Cookie mitschickt, bekommt jedes Mal eine frische Session — die Sperre
greift nie. Sie ist zudem nach `$username` indiziert, also wirkungslos gegen
Password-Spraying über viele Konten.

Der Befund stand in der alten Bestandsaufnahme in Abschnitt 8.1 ausdrücklich
so drin („die Sperre ist damit **wirkungslos**"), hat es aber **nie in
Kapitel 10 und damit nie auf eine Aufgabenliste geschafft**. Er gehört
deshalb zugleich in Abschnitt 3.

**Fix:** Zähler in eine Tabelle `login_attempt` (Schlüssel: Konto **und** IP),
mit Aufräumlauf im vorhandenen Cron-Mechanismus.

#### S-5 · Kein CSRF-Schutz — **M**

projektweit; besonders `config/routes.php` — `delete_user`, `2fa_disable`,
`logout`, `delete_location`, `guide_role`

Kein Token in einem der 24 Templates, keine Prüfung in einem der 13
Controller. `SameSite=Strict` (`config/session.php:6`) mildert das ab, deckt
aber die per GET erreichbaren zustandsändernden Routen nicht: Ein `<img
src="…index.php?act=delete_user&user_id=5">` in einer Seite, die ein Admin
öffnet, löscht das Konto.

`GuideController` (`:95`) und `SettingsController` erzwingen inzwischen POST —
das ist die Ausnahme, nicht die Regel.

**Fix:** Token im `ViewHelper` erzeugen, in eine gemeinsame Prüfung in
`index.php` (dort steht bereits die Rechteprüfung), zustandsändernde Routen auf
POST umstellen. Der zentrale Prüfpunkt existiert bereits — das ist der Grund,
warum dieser Punkt heute deutlich billiger ist als vor 45 Commits.

#### S-7 · Ungebremster Signaling-Endpunkt — **M**

`class/Controller/WebRTCController.php:39-71`

Geprüft werden weiterhin nur `isset($data['type'])` und `isset($data['target'])`.
Es fehlen: Existenzprüfung des Ziels, Beziehungsprüfung, Ratenbegrenzung,
Größenbegrenzung für `sdp` und `candidate`. Ein Skript kann jeden Nutzer mit
Anrufdialogen und Klingeltönen fluten und `rtc_signal` füllen.

Teilweise entschärft: Der Fremdschlüssel `rtc_signal_ibfk_2` fängt eine
nicht existierende `target`-ID ab — allerdings erst in der Datenbank, als
gefangene `PDOException`.

#### S-9 · Statischer IV bei der TOTP-Verschlüsselung — **S**

`class/Controller/TwoFactorController.php:259` und `:264`

```php
openssl_encrypt($secret, 'aes-256-cbc', $key, 0, substr($key, 0, 16));
```

Der IV wird aus dem Schlüssel abgeleitet und ist für alle Konten identisch.
Gleiche TOTP-Secrets ergeben gleiche Chiffrate. Korrekt wäre `aes-256-gcm` mit
zufälligem IV, gespeichert neben dem Chiffrat.

Dazu unverändert: `PEPPER` ist gleichzeitig Passwort-Pepper
(`class/Model/User.php:579`) **und** AES-Schlüssel (`:258`). Eine Rotation ist
nur um den Preis machbar, alle Passwörter **und** alle 2FA-Secrets zugleich
unbrauchbar zu machen. **Fix:** eigener `TOTP_KEY` in der `.env`, Migration der
vorhandenen Secrets. Zusammen **M**.

#### S-15 · CDN ohne SRI, zwei Einbindungen ohne Version — **S**

`assets/html/index.html:13-39` (zwölf Einbindungen)

Zwölf externe Skripte und Stylesheets ohne `integrity`. `leaflet` und
`leaflet-pip@latest` (`:30`, `:31`) weiterhin **ohne Versionspin** — `@latest`
heißt: jedes Upstream-Release landet ungeprüft in einer Seite mit Kamera- und
Mikrofonzugriff.

#### S-11 · `User::register()` akzeptiert Passwörter ab 3 Zeichen — **XS**

`class/Model/User.php:233`

Der Controller prüft davor auf 8 (`SignupController.php:51`). Die
Model-Methode ist öffentlich und wäre die letzte Verteidigungslinie.

#### S-14 · `user.username` ohne UNIQUE-Constraint — **XS**

`database.sql:340`

Die Eindeutigkeit hängt allein an `usernameExists()` (`User.php`), aufgerufen
in `SignupController.php:56` — zwischen Prüfung und Insert liegt ein
TOCTOU-Fenster. `login()` sucht per `WHERE username = :username`; Duplikate
führen zu unvorhersehbarer Kontozuordnung. Eine Migration `010` mit
`ADD UNIQUE KEY` genügt (vorher auf vorhandene Duplikate prüfen).

#### S-NEU-4 · Die Cron-Skripte sind über HTTP aufrufbar — **XS**

`.htaccess:16` · `cron/check_online_status.php`, `cron/cleanup_chat_messages.php`

Die `.htaccess` sperrt `*.log`, `*.sql`, `*.md`, `.env` und `composer.*` — aber
keine `.php` in Unterverzeichnissen. `https://<host>/cron/cleanup_chat_messages.php`
führt den Löschlauf aus, unangemeldet und ohne Recht. Dasselbe gilt für
`vendor/` und `config/`.

Folgenschwer ist das heute nicht — beide Skripte tun nur, was sie ohnehin
planmäßig tun, und beide sind idempotent. Wer sie in Schleife aufruft, erzeugt
allerdings beliebig viele Schreibvorgänge auf `user` bzw. `chat_message`, und
die Fläche wächst mit jedem weiteren Cronjob. **Fix:** `Deny` für `cron/`, `class/`,
`config/`, `vendor/` in der `.htaccess`, plus `php_sapi_name() === 'cli'` als
Riegel in den Skripten selbst.

#### S-NEU-5 · Kein Sitzungsablauf, kein Abmelden bei Passwortwechsel — **S**

`config/session.php` · `class/Controller/PasswordController.php:217-219`

Weder `gc_maxlifetime` noch `cookie_lifetime` gesetzt, kein
Last-Activity-Check. Ein Passwortwechsel beendet andere Sitzungen nicht — wer
sein Passwort ändert, weil er einen Fremdzugriff vermutet, sperrt den
Angreifer damit nicht aus.

Stand ebenfalls schon in Abschnitt 8.2 der alten Bestandsaufnahme, ohne je auf
eine Liste zu kommen.

---

### 2.2 Blocker für den ersten echten Betrieb

#### P1-NEU-1 · Passwort-Reset und E-Mail-Bestätigung zeigen auf `localhost` — **XS**

`class/Controller/PasswordController.php:59`
`class/Controller/EmailVerificationController.php:92`

```php
$resetLink   = "https://localhost/rctprojnew/index.php?act=reset_pw_page&token=$token";
$verifyLink  = "https://localhost/rctprojnew/index.php?act=verify_email&token=$token";
```

Beide Links sind hart auf eine lokale XAMPP-Installation verdrahtet, samt
Verzeichnisnamen `rctprojnew`, den es in diesem Repository nicht mehr gibt.
Auf einem Server bekommt **jeder** Nutzer einen Link, der ins Leere führt.
Passwort-Reset und E-Mail-Bestätigung sind damit produktiv nicht benutzbar.

Das ist der einzige Punkt dieses Berichts, der eine Kernfunktion vollständig
abschaltet — und er stand in **keiner** der beiden alten Listen.

**Fix:** Basis-URL aus der `.env` (`APP_URL`), Fallback auf
`$_SERVER['HTTP_HOST']`. Eine Zeile je Controller plus ein Eintrag in
`.env.example`.

#### P1-NEU-2 · E-Mail-Bestätigung ist vorhanden, aber abgeschaltet — **S**

`class/Controller/SignupController.php:63-79` · `class/Controller/LoginController.php:68-75`

Die Verifikationsmail wird nicht verschickt (auskommentiert, Begründung im
Code: kein eigener SMTP-Server), und der Login prüft `email_verified` nicht.
Jede beliebige, auch fremde E-Mail-Adresse lässt sich registrieren. Für ein
Produkt, das Rechnungen und Zahlungsbestätigungen verschickt, ist das die
Grundlage — und Voraussetzung dafür, dass der Passwort-Reset überhaupt Sinn
ergibt.

Aufwand ist Konfiguration, nicht Code: SMTP-Zugang eintragen, zwei
Kommentarblöcke entfernen, den Ablauf einmal durchspielen.

#### F-9 · Gleichnamige Städte landen im falschen Land — **XS**

`class/Model/Location.php:468-481`, aufgerufen `:92`

```sql
SELECT * FROM city WHERE city_name = :city
```

Ohne `country_id`. Wer einen Standort in „Paris, Texas" anlegt, während
„Paris, Frankreich" schon in `city` steht, bekommt die französische
`city_id` — der Standort erscheint auf der Karte im falschen Land. Der
Datensatz ist danach nicht mehr eindeutig reparierbar, weil die Koordinaten
stimmen und die Zuordnung nicht.

**Fix:** `AND country_id = :country_id` in die WHERE-Klausel und den Parameter
durchreichen. Zwei Zeilen — der Aufwand liegt im Bereinigen bereits falsch
zugeordneter Zeilen.

#### 9.6-Rest · „Erfolgreich gespeichert" auch bei fehlgeschlagenem Speichern — **XS**

`class/Controller/LocationController.php:97-99`

Der Rückgabewert von `setNewLocation()` wird nicht geprüft, obwohl die Methode
bei Fehler `false` liefert. Es wird unabhängig davon auf `?success=1`
weitergeleitet. Bei GET statt POST fällt die Methode zudem stumm durch —
weiße Seite.

---

### 2.3 Betrieb und Skalierung

#### 3.1 · Polling-Signaling skaliert nicht — **XL**

`assets/js/signaling.js` · `class/Controller/WebRTCController.php`

Unverändert der größte Posten der alten Liste. Jeder eingeloggte Nutzer
erzeugt dauerhaft Last, allein für das Signaling, dazu Heartbeat alle 10 s
(`config/presence.php`) und die Chat-Polls. Zusätzlich kostet das Polling im
Mittel eine halbe Poll-Periode Latenz je Signalisierungsschritt.

Der eigentliche Fix ist WebSocket-Signaling (Ratchet oder ein eigener
Node-Prozess). Das ist kein Nachmittag: Es betrifft Signaling, Heartbeat,
Chat-Benachrichtigung und die Anrufannahme gleichzeitig, und es verändert das
Betriebsmodell — bisher braucht die Anwendung nichts als PHP und einen
Cronjob.

Als Zwischenschritt für ein Zehnfaches der heutigen Nutzerzahl: zusammengesetzter
Index `(receiver_id, created_at)` auf `rtc_signal` und Long-Polling. **M** für
den Zwischenschritt.

#### 5.7 (Rest) · `rtc_signal` wächst für Empfänger, die nie wieder pollen — **XS**

`class/Model/WebRTCHandler.php:170-192`

Aufgeräumt wird nur, wenn der Empfänger selbst abfragt. Wer angerufen wird und
den Tab schließt, hinterlässt Zeilen, die niemand mehr anfasst. Der
Cron-Mechanismus steht seit dieser Woche für genau solche Läufe bereit — ein
drittes Skript nach dem Muster von `cron/cleanup_chat_messages.php` genügt.

#### Heartbeat schreibt die ganze Benutzerzeile — **S**

`class/Controller/UserController.php:195-197`

```php
$user = new User($user_id);
$user->setStatus($user_status);
$user->save();
```

`save()` → `update()` schreibt **alle** Benutzerfelder neu, um einen
Statuswert zu setzen — alle 10 Sekunden, je angemeldetem Nutzer. Daneben
existieren mit `setUserStatus()` und `updateUserStatus()` zwei Methoden, die
genau dieses eine Feld schreiben und nicht benutzt werden. Das ist der Rest
des alten Befunds 5.8 („drei Wege, den Benutzerstatus zu setzen").

Nebenwirkung: Ein Heartbeat überschreibt konkurrierende Änderungen an
demselben Konto — etwa eine Rollenänderung durch den Admin, die eine Sekunde
zuvor gespeichert wurde.

---

### 2.4 Grundlagen für den Produktivbetrieb

#### 5.2 · Keine CI, kein Linting, keine statische Analyse — **M**

Kein `.github/`, kein PHPStan, kein ESLint. Die 178 eigenen Prüfungen laufen
heute nur, wenn jemand sie von Hand startet. Ein Workflow, der
`node tests/client_test.js`, `php tests/server_test.php`, `php -l` und
`node --check` über alle Dateien laufen lässt, ist eine Datei und wäre der
größte Gewinn je investierter Stunde in diesem Bericht.

#### 5.5 · Keine reproduzierbare Installation — **M**

Kein Dockerfile, keine `docker-compose.yml`, keine Webserver-Konfiguration für
nginx. Die README beschreibt die Installation vollständig und sorgfältig,
aber nichts davon ist automatisiert; die `.htaccess` wirkt nur unter Apache
und nur bei aktiviertem `AllowOverride` (steht dort auch so).

#### H-6 / 5.6b · `composer.json` — **XS**

PSR-4-Mapping `"Domin\\RtcPsr\\": "src/"` auf ein Verzeichnis, das es nicht
gibt. Kein `"require": {"php": ">=8.1"}`, obwohl der Code PHP-8-Syntax nutzt
(`Chat.php:41` `Chat|null`) — die Installation gelingt auf PHP 7.x und
scheitert erst zur Laufzeit.

#### 5.4 (Rest) · Kein Log-Level, keine Korrelation — **M**

`error_log()` in eine flache Datei, ohne Level, ohne Call-ID. Ein
Verbindungsproblem im Feld lässt sich damit nicht rekonstruieren: Es gibt
keine Möglichkeit, die Zeilen zweier Teilnehmer eines Calls
zusammenzubringen. `getStats()` der `RTCPeerConnection` wird nirgends
ausgewertet.

---

### 2.5 Kleinkram mit Nebenwirkung

| Befund | Ort | Aufwand |
|---|---|---|
| **F-13** `setTimeout(updateCallIcons(), 1000)` — ruft sofort auf und übergibt `undefined`. Steht inzwischen an **zwei** Stellen | `assets/js/main.js:137`, `assets/js/locations_table.js:945` | XS |
| **F-14** `msg = '…'` ohne `let` → implizite globale Variable | `assets/js/main.js:86`, `:93` | XS |
| **F-15** `new User($data)` mit dem dekodierten JSON als Konstruktorargument; funktioniert nur, weil der Client einen nackten Skalar sendet | `class/Controller/UserController.php:214` | XS |
| Keine Längenbegrenzung für die Standortbeschreibung (nur Minimum 5) | `class/Controller/LocationController.php:59` | XS |
| `Chat::checkIfActive()` weiterhin von nirgends aufgerufen | `class/Model/Chat.php` | XS |
| `WebRTCHandler`-Konstruktor mit ID-Ladefunktion wird nie mit einer ID aufgerufen; Feld `$createt_at` (Tippfehler) nie gelesen | `class/Model/WebRTCHandler.php:23-52` | XS |

---

## 3. Beim Bauen aufgefallen, nie auf einer Liste gelandet

Das sind Punkte, die in Commit-Nachrichten, Codekommentaren oder der README als
Nebensatz stehen — sachlich richtig festgehalten, aber nie in eine
Aufgabenliste übernommen. Sie sind hier zum ersten Mal versammelt.

### 3.1 Der Dateiversand des Chats ist halb vorhanden — **XS bis M**

`assets/js/chat.js:67-74` (`sendFile`) · `assets/js/ui_rtc.js:48-53` · `assets/js/chat.js:107-118` (`handleFile`)

`ui_rtc.js` hängt einen `change`-Listener an `#file-input`. **Dieses Element
existiert in keinem Template** (Volltextsuche über `assets/html/`: kein
Treffer). Der Sendeweg ist damit toter Code.

Der *Empfangs*weg lebt: Jeder Binärframe auf dem Chatkanal wird als Datei
angenommen, in ein Blob gepackt und als Download „empfangene_datei" angeboten
— **ohne Größenbegrenzung, ohne Typprüfung, ohne Dateinamen**. Ein
manipulierter Peer kann beliebig große Frames schicken.

`PROTOKOLL.md` Abschnitt 9 nennt das („Dateien: keine Größen-, Typ- oder
Anzahlbegrenzung, keine Metadaten") — als *Grenze dieser Version*, nicht als
Fehler. Dass es **gar keinen Sender** gibt, steht dort nicht.

Zwei Wege: den Empfangsweg abschalten, bis es einen Sender gibt (**XS**), oder
den Dateiversand fertigbauen mit Chunking, Grenzen und Metadaten (**M**). Der
heutige Zustand — empfangen ja, senden nein, keine Grenzen — ist der einzige,
der nicht sinnvoll ist.

### 3.2 Das Verhalten im Browser wurde nie geprüft — **L**

Commit `280ba4a`: „Das Verhalten im Browser wurde nicht getestet — Playwright
ist hier nicht installiert und das select2-CDN vom Proxy blockiert."
`BERICHT_STANDORTSUCHE.md` Abschnitt 6 nennt dasselbe für das Formularverhalten.
`tests/README.md` Abschnitt „Grenzen" nennt es für Medien, Kartendarstellung und
Login-Ablauf.

Zusammengenommen: **Es existiert kein einziger Test, der die Anwendung
tatsächlich im Browser öffnet.** Die 178 Prüfungen lesen Quelltext und prüfen
Logik; sie stellen fest, *welches SQL abgesetzt wird*, nicht *was MySQL daraus
macht*, und *dass* eine Anzeige ein- und ausgeblendet wird, nicht *wie sie
aussieht*.

Für eine Anwendung, deren Kern zwei Browser mit Kamera, Mikrofon und
P2P-Verbindung sind, ist das die größte Prüflücke. Ein Playwright-Aufbau mit
zwei Browserkontexten, der einen Call aufbaut, einen Steuerbefehl schickt und
das `ack` abwartet, deckt mehr ab als alle bestehenden Prüfungen zusammen.

### 3.3 Reales Netzverhalten ist ungeprüft — **M**

`tests/README.md`, Abschnitt „Grenzen": „der echte Wechsel zwischen WLAN und
Mobilfunk auf zwei Geräten", „das tatsächliche Timing eines ICE-Restarts über
einen TURN-Server".

Genau dafür wurde in `dc401aa` der ICE-Restart eingebaut — für einen Guide,
der sich per Definition draußen bewegt und das Netz wechselt. Der Mechanismus
ist getestet, sein Anwendungsfall nicht. Ein Feldtest mit zwei Geräten und
einem echten Netzwechsel ist die einzige Prüfung, die das leisten kann.

### 3.4 `LOG_PATH` wirkt nicht aus der `.env` — **XS**

Commit `beff61f`: „Bekannte Einschraenkung: index.php laedt error_handler.php
vor env.php, daher wirkt LOG_PATH nur auf Server-/Systemebene." Die README
sagt es ebenfalls (`config/log_path.php:14`).

Sachlich richtig, aber eine Stolperstelle: Wer `LOG_PATH` in die `.env`
schreibt — der naheliegende Ort, alle anderen Werte stehen dort — bekommt
keinen Fehler, sondern stillschweigend den Fallback. Ein `error_log()`-Hinweis
beim Start würde das sichtbar machen.

### 3.5 Die alte `php-error.log` im Webroot wird nicht gelöscht — **XS**

`config/log_path.php:24`, README Abschnitt 3: „Sie wird nicht automatisch
geloescht — bitte manuell entfernen."

Diese Datei kann 2FA-Secrets und Reset-Token aus der Zeit vor `beff61f`
enthalten. Der Hinweis steht an zwei Stellen; ob er auf der Zielinstallation
befolgt wurde, weiß niemand. Das gehört auf eine Deployment-Checkliste, nicht
in einen Codekommentar.

### 3.6 Keine Tastatursteuerung — **S**

Die README beschrieb früher eine Steuerung per Pfeiltasten, die es nie gab; der
Text ist korrigiert. Die Funktion fehlt weiter: `grep -n "ArrowUp"` über
`assets/js/` liefert keinen Treffer, gesteuert wird ausschließlich per Klick.

Für eine Anwendung, deren Alleinstellungsmerkmal die Fernsteuerung ist, sind
das wenige Zeilen mit großer Wirkung — und der einzige Weg, das Steuerkreuz
per Tastatur bedienbar zu machen. Barrierefreiheit ist bei einem Produkt mit
Zahlungspflicht keine Kür.

### 3.7 Nominatim wird direkt aus dem Browser als Autovervollständigung benutzt — **M**

`assets/js/map.js:298` (Städtesuche), `:268`, `:357`, `:489`, `:532` (Reverse
Geocoding)

Die Nutzungsbedingungen von OSM/Nominatim untersagen Autovervollständigung
ausdrücklich und begrenzen auf eine Anfrage pro Sekunde. Entschärfend wirken
`delay: 300` und `minimumInputLength: 3`; die Anfragen laufen zudem aus dem
Browser des Nutzers, die Last verteilt sich also über viele IPs — was die
Regel formal nicht besser erfüllt, sondern die Verantwortung nur verschiebt.

Dasselbe gilt für die Kartenkacheln von `tile.openstreetmap.org`
(`home_map.js:139`, `map.js:95`, `locations_table.js:841`, `:868`): Die
Tile-Usage-Policy schließt kommerzielle Nutzung in nennenswertem Umfang aus.

Für ein zahlungspflichtiges Produkt ist beides zu klären, bevor es startet —
entweder ein bezahlter Anbieter (MapTiler, Geoapify, eigenes Nominatim) oder
serverseitiges Caching mit eigenem Kontingent. Das ist kein Codeproblem,
sondern eine Beschaffungsentscheidung mit Codefolge.

### 3.8 Jeder Aufruf der Karte geht an Dritte — **XS bis M**

Damit hängt zusammen: Jeder Seitenaufruf schickt die IP des Nutzers an
`cdn.jsdelivr.net`, `ajax.googleapis.com`, `unpkg.com`, `cdn.datatables.net`
und `tile.openstreetmap.org`. Bei `flagcdn.com` wurde genau das schon einmal
abgestellt (Commit `9b6de08`, dort ausdrücklich als Begründung genannt:
„schickt nicht bei jedem Aufklappen die IP jedes Nutzers an einen fremden
Dienst") — für die übrigen fünf Dienste ist dieselbe Überlegung nie
angestellt worden.

Ohne Einwilligung ist das in der EU heikel. Bibliotheken lokal ausliefern ist
**XS** und schlägt zwei Fliegen: Es erledigt zugleich S-15 (SRI).

### 3.9 Weitere Nebensätze, kurz

| Punkt | Quelle | Aufwand |
|---|---|---|
| Guide im Hintergrund-Tab erscheint offline, weil Browser Timer ausbremsen | README, Abschnitt „Bekannte Grenze" | S (Page Visibility API) |
| Der Pfeil im `<select>` liegt als feste Grafik doppelt im CSS, hell und dunkel — „Das ist die Grenze" | Commit `ad051d0` | — (bewusst) |
| Migrationen wurden nie gegen einen echten Server ausgeführt; geprüft sind Struktur und Daten, nicht die SQL-Syntax | `BERICHT_STANDORTSUCHE.md` Abschnitt 6 | XS (einmal einspielen) |
| Migrationen `008`/`009` nutzen `IF NOT EXISTS` in `ALTER`/`CREATE INDEX` — **unter MySQL 8 laufen diese Dateien nicht** | Kopf der Migrationsdateien | S (MySQL-Variante nachliefern) |
| Rollenänderung durch den Admin wirkt erst nach Neuanmeldung, weil `role_id` in der Sitzung steht | alte Bestandsaufnahme 8.2, nie auf einer Liste | S |
| `select2` lädt keine deutsche Sprachdatei; Standardmeldungen außer `noResults` und `inputTooShort` bleiben englisch | `BERICHT_STANDORTSUCHE.md` 5.1, Punkt 4 | XS |
| Das Stadtfeld ist nicht deaktiviert, solange kein Land gewählt ist | `BERICHT_STANDORTSUCHE.md` 5.1, Punkt 1 | XS |

Vom Standortbericht sind damit **5.2** (Fehlerbehandlung in `loadCountries`,
heute `map.js:105-115`), **5.3** (select2-Prüfung, `map.js:50`) und **5.4**
(irreführende Meldung) erledigt; von **5.1** ist die Meldung erledigt, das
deaktivierte Feld und die Sprachdatei nicht.

---

## 4. Vom heutigen Stand zu „Kunde bucht und bezahlt"

Heute kann ein Zuschauer einen Guide anrufen, der zufällig gerade online ist.
Alles, was aus diesem Anruf ein gebuchtes und bezahltes Produkt macht, fehlt.

Vorbereitet ist bewusst dreierlei und sonst nichts (README, Abschnitt
„Vorbereitung auf die Abrechnung"): die Tabelle `guide_profile` als Anker für
spätere Abrechnungstabellen, `terms_version` als Hebel für neue Bedingungen,
und `GuideRole::accept()`/`::resign()` als einzige Stellen des Rollenwechsels,
an denen später die Prüfungen sitzen. Das ist die richtige Vorbereitung — sie
ersetzt aber keinen der folgenden Punkte.

### 4.1 Was zwingend fehlt

| # | Fehlt | Warum es nicht ohne geht | Aufwand |
|---|---|---|---|
| **B1** | **Angebot mit Preis.** `location` kennt Land, Stadt, Koordinaten, Beschreibung — keinen Preis, keine Dauer, keine Sprache, kein Bild | Ohne Preis am Angebot gibt es nichts zu buchen | M |
| **B2** | **Verfügbarkeit und Termin.** Es gibt nur „grüner Punkt = jetzt online" (`config/presence.php`). Kein Kalender, keine Zeitfenster, keine Buchung auf morgen 15 Uhr | Ein Guide, der zufällig online sein muss, ist kein buchbares Angebot. Das ist der größte Einzelposten | L |
| **B3** | **Buchung als Datensatz.** Keine Tabelle `booking`, kein Zustand (angefragt/bestätigt/durchgeführt/storniert), keine Zuordnung Zuschauer↔Guide↔Zeitfenster | Alles Weitere hängt daran: Zahlung, Stornierung, Auszahlung, Support | L |
| **B4** | **Zahlung.** Kein Zahlungsdienstleister, keine Vorautorisierung, keine Erfassung, keine Rückerstattung | — | L |
| **B5** | **Auszahlung an den Guide.** Keine Bankverbindung, kein Auszahlungslauf, keine Gutschrift. Der Kommentar in `GuideRole.php:100` markiert die Stelle („HIER GEHOERT SPAETER DIE ABRECHNUNG HIN"), mehr nicht | Ohne Auszahlung gibt es keine Guides | L |
| **B6** | **Rechnung und Steuer.** Keine Rechnungsnummern, keine Umsatzsteuer, keine Aufbewahrung. Bei grenzüberschreitenden Führungen: Leistungsort, OSS-Verfahren | Rechtlich nicht verhandelbar | L |
| **B7** | **AGB, Widerruf, Datenschutzerklärung, Impressum.** Im Repository nicht vorhanden. `guide_role.html` enthält Bedingungen **für Guides**, nicht für Kunden | Impressumspflicht ab dem ersten Angebot; eine fehlende Widerrufsbelehrung verlängert die Widerrufsfrist erheblich | M (juristisch begleitet) |
| **B8** | **Vertragsrolle klären: Marktplatz oder Anbieter?** Vermittelt die Plattform zwischen Guide und Kunde, oder verkauft sie selbst? Davon hängt alles ab — Rechnungsteller, Umsatzsteuer, Haftung, ob DAC7-Meldepflichten greifen | Diese Entscheidung geht allen anderen Punkten voraus. Sie kostet keine Entwicklungszeit und blockiert B4 bis B7 vollständig | XS (Entscheidung), L (Folgen) |
| **B9** | **Stornierung und Nichterscheinen.** Was passiert, wenn der Guide nicht auftaucht? Wenn das Netz vor Ort ausfällt? Heute: nichts, weil es keine Buchung gibt | Der häufigste Supportfall eines Produkts, das von der Mobilfunkabdeckung eines Menschen abhängt | M |
| **B10** | **Bewertung und Vertrauen.** Keine Bewertungen, keine Verifizierung von Guides, kein Melden, kein Blockieren. Ein Admin kann Standorte sperren (`location.block`) — Nutzer nicht melden oder blockieren | Zahlende Kunden buchen keinen Fremden ohne jedes Vertrauenssignal | M |
| **B11** | **Kontolöschung und Datenauskunft.** `del_it()` setzt `deleted = 1` (`class/Model/User.php:208`) und ist nur dem Admin zugänglich. Kein Selbstlöschen, kein Datenexport, keine echte Löschung | DSGVO Art. 15/17. Mit der Chat-Aufbewahrung ist der erste Schritt getan, mehr nicht | M |

### 4.2 Was zusätzlich für zahlende Kunden nötig ist

| # | Fehlt | Aufwand |
|---|---|---|
| **B12** | **Kaufentscheidung ohne Konto.** Ein Gast sieht heute die Karte und sonst nichts — keine Preise, keine Profile, keine Beispiele. Registrierung vor Information | M |
| **B13** | **Mobile Nutzung des Guides.** Der Guide steht draußen, mit einem Telefon. Geprüft ist die Darstellung bis 340px; ob die Call-Ansicht auf einem echten Telefon bei wechselndem Netz und im Hochformat bedienbar ist, weiß niemand (siehe Abschnitt 3.2) | L |
| **B14** | **Zahlen zum Betrieb.** Keine Auswertung, wie viele Calls zustande kommen, wie viele scheitern, woran. `getStats()` wird nirgends ausgewertet | M |
| **B15** | **Support-Zugang.** Kein Kontaktweg, kein Ticketsystem, keine Möglichkeit für einen Betreiber, einen Vorfall nachzuvollziehen (siehe fehlende Log-Korrelation, Abschnitt 2.4) | S |

### 4.3 Ehrliche Gesamteinschätzung

Die technische Grundlage ist heute deutlich besser als vor 41 Commits: Rechte
sind zentral, das Steuerprotokoll trägt, die Verbindung übersteht einen
Netzwechsel, es gibt Migrationen und 178 Prüfungen. Ein Prototyp, der seinen
Zweck erfüllt.

Der Weg zum bezahlten Produkt ist trotzdem **kein Ausbau, sondern ein zweites
Projekt in vergleichbarer Größenordnung**. Der Grund ist nicht die Zahlung —
die ist bei einem Dienstleister eingekauft. Der Grund ist B2 und B3: Eine
Anwendung, in der man *jetzt* jemanden anruft, der zufällig online ist, und
eine Anwendung, in der man *für Donnerstag 15 Uhr* eine Führung bucht, sind
verschiedene Anwendungen. Buchung, Zustand, Kalender, Stornierung und
Erinnerung durchziehen jede Ansicht.

Als Reihenfolge, jede Stufe für sich lauffähig:

1. **Sofort** (Abschnitte 2.1 und 2.2, zusammen ≈ **M**): die beiden `localhost`-Links,
   die vier fehlenden Chat-Prüfungen, das Passwort-Orakel, F-9. Das sind
   kleine Eingriffe mit großer Wirkung — ohne sie sollte die Anwendung nicht
   öffentlich stehen.
2. **Vor dem ersten echten Nutzer** (≈ **L**): CSRF, Brute-Force in die
   Datenbank, SRI und lokale Bibliotheken, Sitzungsablauf, E-Mail-Bestätigung
   scharf schalten, CI-Workflow.
3. **Vor dem ersten zahlenden Nutzer** (≈ **XL**): B8 entscheiden, dann B1–B7
   und B11, dazu Browsertests (Abschnitt 3.2) und die Klärung der Kartendienste
   (Abschnitt 3.7).
4. **Danach** (≈ **XL**): B9, B10, B12–B15 und, sobald mehr als ein paar
   Dutzend Nutzer gleichzeitig online sind, das WebSocket-Signaling (Abschnitt 2.3).

---

## 5. Positiv hervorzuheben

Was seit der letzten Bestandsaufnahme entstanden ist und bei jedem weiteren
Umbau erhalten bleiben sollte:

- **Rechte statt Rollen, zentral geprüft.** Eine Route ohne Recht ist ein
  Konfigurationsfehler, der die Anwendung anhält, statt eine offene Route zu
  hinterlassen (`index.php:60-64`). Das ist die seltenere und richtige
  Voreinstellung.
- **Ein Steuerprotokoll mit eigener Beschreibung.** `PROTOKOLL.md` nennt
  Version, Rollen, Prüfregeln — und in Abschnitt 9 ausdrücklich, was es nicht
  kann.
- **Konfigurationswerte an einer Stelle.** `config/presence.php` und
  `config/chat_retention.php` versorgen Server, Cronjob und Frontend aus
  derselben Datei. Keine Zahl steht doppelt.
- **Migrationen mit Begründung.** Jede Datei erklärt im Kopf, wozu sie da ist,
  ob sie idempotent ist und was passiert, wenn man sie auslässt.
- **Tests, die den Grund festhalten.** Die Prüfungen sichern nicht nur das
  Verhalten, sondern beschreiben in den Kommentaren den Fehler, den sie
  verhindern sollen. Das ist Dokumentation, die nicht veralten kann.
- **Kommentare, die das Warum erklären.** Durchgehend, auf Deutsch, mit dem
  verworfenen Alternativweg. Diese Bestandsaufnahme war deutlich schneller zu
  erstellen als die erste.

---

## 6. Nicht verifiziert

Damit klar ist, worauf sich dieser Bericht **nicht** stützt:

- **Keine laufende Datenbank.** In dieser Umgebung ist kein MySQL/MariaDB
  verfügbar. Alle Aussagen zum Schema stammen aus `database.sql` und den
  Migrationen, nicht aus einer Instanz.
- **Kein Browser.** Für diesen Bericht wurde die Anwendung nicht in einem
  Browser geöffnet; ohne Datenbank ließe sie sich auch nicht bedienen. Kein
  Punkt dieses Berichts wurde in einer laufenden Oberfläche nachgestellt.
  (Das Zitat in Abschnitt 3.2 stammt aus einer früheren Sitzung und beschreibt
  deren Umgebung, nicht diese.)
- **Keine Ausnutzung.** Die Sicherheitsbefunde sind aus dem Quelltext
  hergeleitet und nicht praktisch ausgenutzt worden. Bei S-NEU-1, S-1 (Rest)
  und S-NEU-2 ist die fehlende Prüfung im Code unmittelbar sichtbar; bei S-7
  und S-NEU-4 hängt die tatsächliche Auswirkung von der Serverkonfiguration ab.
- **Keine Rechtsberatung.** Abschnitt 4 benennt, was fehlt, und ordnet den
  Aufwand ein. Ob eine konkrete Ausgestaltung genügt, kann nur jemand
  beurteilen, der dafür haftet.
