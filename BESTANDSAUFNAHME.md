# Bestandsaufnahme — WebRTC Remote-Guidance & Location Platform

Stand: 2026-09-01 · Branch `claude/webrtc-platform-inventory-fmqm2c` · Commit `c7730f1`

Analysiert wurde der komplette Repo-Inhalt (89 Dateien, ~6.400 Zeilen Quellcode).
Es gibt **keine `package.json`** — das Projekt ist eine klassische PHP-Anwendung mit
Composer; die Frontend-Bibliotheken kommen per CDN. Abschnitt 2 behandelt daher
`composer.json`/`composer.lock` plus die CDN-Einbindungen.

Alle Angaben mit `Datei:Zeile`. Wo etwas nicht existiert, steht **nicht vorhanden**.

---

## 1. PROJEKTSTRUKTUR

### 1.1 Verzeichnisbaum

```
webrtc_proj/
├── index.php                     Front-Controller
├── composer.json / composer.lock Abhängigkeiten (vendor/ NICHT im Repo)
├── database.sql                  DB-Schema (rekonstruiert, s. Abschnitt 7)
├── README.md                     Projektbeschreibung / Setup
├── .gitignore
│
├── config/                       ── SERVER: Bootstrap ──
│   ├── error_handler.php
│   ├── session.php
│   ├── env.php
│   └── routes.php
│
├── class/                        ── SERVER: Anwendungslogik ──
│   ├── Controller/  (12 Dateien)
│   ├── Model/       (8 Dateien)
│   └── Helper/      (2 Dateien)
│
├── cron/                         ── SERVER: Hintergrundjob ──
│   └── check_online_status.php
│
└── assets/                       ── CLIENT ──
    ├── js/    (14 Dateien)
    ├── html/  (21 Templates)
    ├── css/   (6 Dateien)
    ├── img/   (5 Dateien)
    └── audio/ (9 Dateien)
```

**Shared Code:** Es gibt **keinen geteilten Code** zwischen Client und Server.
Kein gemeinsames Schema, keine gemeinsamen Konstanten, keine gemeinsame
Protokolldefinition. Die Steuerbefehle (`__arrow_forward__` etc.) sind als
String-Literale getrennt in `assets/js/main.js:136` (Sender) und
`assets/js/rtc.js:276-291` (Empfänger) dupliziert — und ein drittes Mal in
`assets/js/chat.js:15-18` und `:37-40` (Anzeige). Der Server kennt das
Steuerprotokoll überhaupt nicht.

### 1.2 SERVER — Einstieg & Konfiguration

| Datei | Zweck |
|---|---|
| `index.php` | Front-Controller. Lädt Error-Handler, Session, Autoloader, ENV; erzwingt HTTPS (Z. 18-22); validiert `act` gegen `/^[a-zA-Z0-9_]+$/` (Z. 31); dispatcht über `$routes` (Z. 43-46); sonst 404. |
| `config/error_handler.php` | `display_errors=0`, Logging nach `../php-error.log`; globaler Error- und Exception-Handler, die immer HTTP 500 + „Interner Serverfehler." ausgeben und `exit`. |
| `config/session.php` | Session-Cookie-Parameter: `httponly`, `secure`, `samesite=Strict`; `session_start()`. |
| `config/env.php` | Lädt `.env` via `vlucas/phpdotenv` (`createImmutable`) nach `$_ENV`. |
| `config/routes.php` | Routing-Tabelle: 45 Aktionen → `[ControllerClass, 'method']`. |

### 1.3 SERVER — Controller (`class/Controller/`)

| Datei | Zweck |
|---|---|
| `WebRTCController.php` | **Signaling-Endpunkt.** POST = Signal speichern, GET = Signale abholen + löschen; `signalMessageFilter()` (Z. 76) verwirft leere ICE-Kandidaten. |
| `TurnController.php` | Gibt TURN/STUN-Credentials als JSON aus (Proxy zu Metered). |
| `LoginController.php` | Login mit Brute-Force-Lockout (5 Versuche / 300 s, Z. 31-32), Session-Regeneration (Z. 80), 2FA-Weiche (Z. 75), Logout. |
| `SignupController.php` | Registrierung inkl. Validierung; **Erfolgspfad defekt** (Z. 71, s. Abschnitt 9). |
| `PasswordController.php` | Passwort-vergessen (Token + Mail), Reset, Ändern. |
| `EmailVerificationController.php` | E-Mail-Verifizierung per Token; Versand der Verifikationsmail. |
| `TwoFactorController.php` | TOTP-Setup mit QR-Code, Aktivierung, Login-Verifikation, Deaktivierung; AES-Verschlüsselung des Secrets (Z. 221-230). |
| `UserController.php` | Benutzerliste/-verwaltung (Admin), Soft-Delete, `heartbeat` (Online-Status), `getUsername`, `saveLocation`. |
| `LocationController.php` | Standort anlegen/anzeigen/bearbeiten/löschen; Länderliste als JSON. |
| `ChatController.php` | Persistenter DB-Chat (getrennt vom P2P-DataChannel-Chat): starten, annehmen, ablehnen, Nachrichten, Einladungen, Verlauf. |
| `SettingsController.php` | Account-Seite (Username, E-Mail, 2FA-Status). |
| `SystemController.php` | Home/Admin-Seiten, `generateHtmlOptions()`-Helfer; `showStart()` ist toter Code (s. Abschnitt 9). |
| `MessageController.php` | **nicht vorhanden** — wird aber in `config/routes.php:11,60,61` referenziert. |

### 1.4 SERVER — Models (`class/Model/`)

| Datei | Zweck |
|---|---|
| `PdoConnect.php` | PDO-Singleton über statisches `$connection`; DSN aus ENV; `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`. |
| `User.php` | Benutzer-Entity: Registrierung, Login (Pepper + Argon2i), Status, Rollen, TOTP-Felder, Geo-Position. 555 Zeilen, größte Datei. |
| `Location.php` | Standort-Entity: CRUD, Länder-/Städte-Auflösung (`selectCity`/`insertCityName`). |
| `WebRTCHandler.php` | Entity für Tabelle `rtc_signal`: `create()`, `getAllSignalsForReceiver()` (15-s-Fenster), `deleteSignalsForReceiver()`. |
| `MeteredTurnService.php` | cURL-Aufruf gegen `https://<app>.metered.live/api/v1/turn/credentials`. |
| `Chat.php` | Chat-Sitzung zwischen zwei Usern: `findOrCreate`, `setActive`, Soft-Delete, `getInvitations`. |
| `ChatMessage.php` | Einzelnachricht: laden, anlegen, ungelesene zählen. |
| `Email.php` | SMTP-Versand über PHPMailer (STARTTLS). |

### 1.5 SERVER — Helper (`class/Helper/`)

| Datei | Zweck |
|---|---|
| `Request.php` | `Request::g($key, $default)` — dünner Wrapper um `$_REQUEST`. Keine Typisierung, keine Sanitisierung. |
| `ViewHelper.php` | Template-Rendering: ersetzt `###CONTENT###` u. a. Platzhalter in `assets/html/index.html`; injiziert `window.isLoggedIn`, `window.userId`, `window.userRole` als Inline-`<script>` (Z. 75-80); endet immer mit `die()`. |

### 1.6 SERVER — Cron

| Datei | Zweck |
|---|---|
| `cron/check_online_status.php` | Setzt `user_status='offline'`, wenn `updated_at` älter als 20 s ist (Z. 18-22). |

### 1.7 CLIENT — JavaScript (`assets/js/`)

Ladereihenfolge festgelegt in `assets/html/index.html:33-49`.

| Datei | Zweck |
|---|---|
| `app.js` | Namespace `window.webrtcApp`: `state` (Call-Status, Z. 4-13) und `refs` (PeerConnection, DataChannel, Streams, ICE-Server, Z. 16-24). Reine Deklaration. |
| `rtc.js` | **Kern-WebRTC-Modul.** `startCall`, `createPeerConnection`, `setupDataChannel` (inkl. **Steuerprotokoll-Empfang**, Z. 244-305), `loadIceServers`, `endCall`, Call-Timeout. |
| `signaling.js` | Transport zum PHP-Signaling: `sendSignalMessage` (POST), `pollSignaling` (1500 ms), `handleSignalingData` (offer/answer/iceCandidate/hangup/call_failed), `sendHeartbeat`. |
| `main.js` | Bootstrap aller Event-Listener: Call-Buttons, Medienauswahl-Dialog, **Steuerpfeil-Buttons (Z. 129-141, Sender des Steuerprotokolls)**, Kamera-/Mikro-Umschaltung, Geräteauswahl. |
| `chat.js` | P2P-Chat über DataChannel: `send`, `sendFile`, `appendMsg` (übersetzt Steuerbefehle in Pfeilsymbole). |
| `ui_rtc.js` | Call-UI: End-Call-Button, Chat-Input-Bindings, `getUsername()`-Fetch. |
| `ui.js` | Allgemeine UI: Location-Buttons je Rolle (Z. 12-31), Lösch-Confirm, Layout-Anpassung für breite Tabellen. |
| `ui_chat.js` | DB-Chat-UI: Popups, Tabs, Polling (Z. 324, 383, 427), Nachrichtenrendering (Z. 259-274). 501 Zeilen. |
| `chat_manager.js` | Reine In-Memory-Registry für Chat-Zustände (`chats`-Map). |
| `locations_table.js` | Locations-Tabelle (DataTables): Laden, Rendern, Bearbeiten, Löschen. |
| `map.js` | Leaflet-Karte, Länder-/Städtesuche über OpenStreetMap Nominatim. 419 Zeilen. |
| `location_prompt.js` | `askLocation()` — Geolocation-Abfrage, POST an `save_location`. |
| `sound.js` | `play()`/`stop()` für `<audio>`-Elemente. |
| `utils.js` | `showSuccessAlertIfNeeded()` — Erfolgsmeldung aus URL-Parameter. |

### 1.8 CLIENT — HTML-Templates (`assets/html/`)

| Datei | Zweck |
|---|---|
| `index.html` | Layout-Rahmen mit CDN-Einbindungen und allen `###PLATZHALTER###`. |
| `inner_call_controll.html` | **Call-Vollbild-Ansicht:** Video, Chat, **Steuerkreuz Desktop (Z. 73-78) und Mobile (Z. 86-92)**, Geräteauswahl, mobiles Chat-Sheet. |
| `call_controll.html` | Eingehender-Anruf-Dialog / Medienauswahl. |
| `media.html` | 9 `<audio>`-Elemente (Klingeltöne + Richtungssounds). |
| `login.html`, `signup.html`, `signup_complete.html` | Auth-Formulare. |
| `forgot_pw.html`, `reset_pw.html`, `change_pw.html` | Passwort-Flows. |
| `email_verified.html`, `email_verified_error.html` | Ergebnisseiten E-Mail-Verifizierung. |
| `settings.html` | Account-Einstellungen. |
| `list_user.html`, `list_user_row.html`, `manage_user.html` | Benutzerverwaltung. |
| `set_location.html`, `locations_table.html`, `location_prompt.html` | Standortverwaltung. |
| `list_chat.html`, `list_chat_row.html`, `show_chat.html` | Chat-Übersicht/-Verlauf. |

### 1.9 CLIENT — CSS / Assets

| Datei | Status |
|---|---|
| `css/call.css`, `css/map.css` | Eingebunden (`index.html:16-17`). |
| `css/admin.css` | Einbindung **auskommentiert** (`index.html:15`) — tot. |
| `css/style.css`, `css/1call.css`, `css/dots.css` | **Nirgends referenziert** — tot. |
| `img/` | `camera.png`, `camera-off.png`, `mic.png`, `mic-off.png`, `chat.svg` — alle genutzt. |
| `audio/` | 7 von 9 genutzt. `look_up.mp3`/`look_down.mp3` haben **keinen Protokollbefehl** (s. Abschnitt 4.5). |

---

## 2. TECH-STACK

### 2.1 Vorbemerkung

`package.json` ist **nicht vorhanden**. Es gibt keinen Build-Schritt, keinen
Bundler, keinen Transpiler, keine Linter- oder Test-Konfiguration. JavaScript wird
als 14 einzelne, unminifizierte `<script>`-Tags geladen
(`assets/html/index.html:33-49`).

`vendor/` ist nicht im Repo (in Commit `6c1a91d` gelöscht) — `composer install` ist
Pflicht. `composer.lock` ist vorhanden und pinnt exakte Versionen.

### 2.2 PHP-Abhängigkeiten (`composer.json:18-25`, Versionen aus `composer.lock`)

| Paket | Constraint | Gelockt | Zweck im Projekt | Bewertung |
|---|---|---|---|---|
| `phpmailer/phpmailer` | `^6.10` | v6.10.0 | SMTP-Versand (`class/Model/Email.php:24`) | Aktiv gepflegt. De-facto-Standard. |
| `spomky-labs/otphp` | `^11.3` | 11.3.0 | TOTP-Erzeugung/-Prüfung (`TwoFactorController.php:38,44,101,180`) | Aktiv gepflegt. |
| `endroid/qr-code` | `^6.0` | 6.0.8 | QR-Code für 2FA-Setup (`TwoFactorController.php:51-53`) | Aktiv gepflegt. Benötigt **GD-Extension** (README). |
| `psr/clock` | `^1.0` | 1.0.0 | PSR-20-Interface | Stabiler Standard, ändert sich nicht. |
| `symfony/clock` | `^7.3` | v7.3.0 | `NativeClock` für TOTP-Zeitbasis (`TwoFactorController.php:35,91,171`) | Symfony 7.3 ist **keine LTS-Version**; Support-Fenster ist kurz. Für einen Produktivbetrieb wäre eine LTS-Linie sinnvoller. |
| `vlucas/phpdotenv` | `^5.6` | v5.6.2 | `.env`-Laden (`config/env.php:5-6`) | Aktiv gepflegt. |

**Transitive Abhängigkeiten** (aus `composer.lock`, keine direkten Requires):
`bacon/bacon-qr-code` v3.0.1, `dasprid/enum` 1.0.6, `graham-campbell/result-type`
v1.1.3, `paragonie/constant_time_encoding` v3.0.0, `phpoption/phpoption` 1.9.3,
`symfony/deprecation-contracts` v3.6.0, `symfony/polyfill-{ctype,mbstring,php80,php83}`
v1.32.0.

**Kein Paket ist als abandoned markiert.** Ich kann offline nicht prüfen, ob
inzwischen neuere Releases existieren — die Constraints (`^`) erlauben aber
Minor-Updates, sodass ein `composer update` das Meiste abdeckt.

**Problem — fehlende PHP-Versionsanforderung:** `composer.json` enthält **keinen
`require: {"php": ...}`-Eintrag**. Der Code nutzt aber PHP-8-Syntax — Union-Types in
`class/Model/WebRTCHandler.php:23` (`string|int`) und `class/Model/Chat.php:41`
(`Chat|null`). Ohne Constraint installiert Composer auch auf PHP 7.x, und die
Anwendung bricht erst zur Laufzeit.

**Problem — toter Autoload-Eintrag:** `composer.json:6` mappt
`"Domin\\RtcPsr\\": "src/"`. Das Verzeichnis `src/` **existiert nicht**.

### 2.3 Frontend-Bibliotheken (CDN, `assets/html/index.html:8-29`)

| Bibliothek | Version | Quelle | Bewertung |
|---|---|---|---|
| jQuery | 3.6.0 | googleapis (Z. 8) | **Veraltet** (Release 2021). Aktuelle 3.x-Linie ist neuer; 3.6.0 enthält bekannte, später behobene Fehler. |
| Leaflet (CSS+JS) | **unpinned** | unpkg (Z. 11, 20) | **Kritisch**: `https://unpkg.com/leaflet/dist/leaflet.js` ohne Version → liefert immer die neueste Major-Version. Ein Leaflet-Major-Release bricht die Karte ohne jede Code-Änderung. |
| leaflet-pip | **`@latest`** | unpkg (Z. 21) | **Kritisch**: explizit `@latest`. Gleiches Problem, zusätzlich ist das Paket sehr wenig aktiv. |
| select2 | 4.1.0-**rc.0** | jsDelivr (Z. 12, 22) | **Release Candidate von 2020.** Es gab nie ein finales 4.1.0. Das Projekt gilt als kaum noch gepflegt. |
| Bootstrap | 5.3.6 | jsDelivr (Z. 13, 23) | Aktuell genug, gepinnt. OK. |
| DataTables | 1.13.8 | datatables.net (Z. 26, 28) | Gepinnt, aber 1.x ist die Vorgängerlinie (2.x existiert). Funktional stabil. |
| DataTables Responsive | 2.4.1 | datatables.net (Z. 27, 29) | Gepinnt. OK. |

**Übergreifend:** Bei **keiner** der acht CDN-Einbindungen ist ein
`integrity`-Attribut (Subresource Integrity) gesetzt. Bei zwei davon ist nicht
einmal die Version fixiert. Kombiniert bedeutet das: eine Kompromittierung von
unpkg/jsDelivr oder ein regulärer Upstream-Release führt fremden JavaScript-Code
in eine Seite ein, die Kamera, Mikrofon und Sessiondaten hält.

---

## 3. SIGNALING

### 3.1 Architektur

| Aspekt | Umsetzung |
|---|---|
| Server | **Der eigene PHP-Server.** Kein separater Signaling-Prozess. |
| Transport | **HTTP-Polling** (`fetch`) gegen `index.php?act=getSignal`. |
| WebSocket | **nicht vorhanden** |
| Socket.IO | **nicht vorhanden** |
| Server-Sent Events | **nicht vorhanden** |
| Zustandsspeicher | MySQL-Tabelle `rtc_signal` (`database.sql:120-133`) |
| Endpunkt | `WebRTCController::getSignal()` — POST = schreiben, GET = lesen+löschen |
| Poll-Intervall | 1500 ms (`assets/js/signaling.js:37`) |
| Signal-Gültigkeit | 15 s serverseitig (`class/Model/WebRTCHandler.php:85`) |
| Antwort-Timeout | 25 s clientseitig (`assets/js/rtc.js:368`) |
| Adressierung | über `user_id` — **es gibt keine Räume/Rooms.** Ein Call ist immer 1:1 zwischen zwei Benutzer-IDs. |

Der Begriff „Raum betreten" hat in diesem Code keine Entsprechung. Der Ablauf ist
ein direkter 1:1-Anruf: A wählt B aus einer Benutzer- oder Locations-Liste und
ruft an.

### 3.2 Ablauf vom Seitenaufruf bis zur stehenden Verbindung

**Vorbereitung (beide Seiten, direkt nach Login)**

1. `ViewHelper::output()` (`class/Helper/ViewHelper.php:75-80`) injiziert
   `window.isLoggedIn`, `window.userId`, `window.userRole` als Inline-Script in
   die Seite.
2. `DOMContentLoaded` → `webrtcApp.init()` (`assets/js/main.js:385-386`).
3. Ist der User eingeloggt, startet `signaling.pollSignaling()`
   (`main.js:47`): alle 1500 ms ein `GET index.php?act=getSignal`
   (`signaling.js:28-37`).
4. Parallel läuft alle 15 s ein Heartbeat (`main.js:388-390`) →
   `POST act=heartbeat` → `UserController::heartbeat()` (Z. 125-141) setzt
   `user_status` auf `online` bzw. `in_call`.

**Verbindungsaufbau (A = Anrufer, B = Angerufener)**

5. A klickt einen Call-Button (`#start-call-btn-<userId>`). Der Handler
   (`main.js:113-123`) extrahiert die Ziel-User-ID aus der Element-ID und ruft
   `rtc.startCall(targetUserId)` (`rtc.js:96`).
6. `startCall` lädt zuerst die ICE-Server: `GET index.php?act=get_turn_credentials`
   → `TurnController::getTurnCredentials()` → `MeteredTurnService` → Metered-API
   (`rtc.js:334-352`).
7. `initFakeSelfCall()` (`rtc.js:311-329`) legt eine Dummy-PeerConnection samt
   `getUserMedia` und Wegwerf-Offer an (dokumentiert als Chrome-Workaround).
8. `getUserMedia({video:true, audio:true})` → `createPeerConnection(true)`
   (`rtc.js:112`). Als Initiator legt A den **DataChannel `"chat"`** an
   (`rtc.js:176`) und registriert die Handler über `setupDataChannel`.
9. A: `createOffer()` → `setLocalDescription()` →
   `POST {type:'offer', sdp, target}` an `act=getSignal` (`rtc.js:125-129`).
10. Server: `WebRTCController::getSignal()` POST-Zweig (Z. 25-49) liest
    `sender` aus `$_SESSION['user']['user_id']`, `target` aus dem Body, und legt
    über `WebRTCHandler::create()` (`WebRTCHandler.php:58-74`) eine Zeile in
    `rtc_signal` an.
11. A startet das 25-s-Timeout (`rtc.js:141` → `startTimeout`, Z. 357-368) und
    spielt `call_ringtone` ab.
12. B's laufender Poll trifft den GET-Zweig (`WebRTCController.php:52-63`):
    `getAllSignalsForReceiver()` holt alle Signale der letzten 15 s,
    `signalMessageFilter()` (Z. 76-88) verwirft leere ICE-Kandidaten,
    `deleteSignalsForReceiver()` löscht **alle** Zeilen für B, dann JSON-Antwort.
13. B: `handleSignalingData()` erkennt `type === 'offer'` (`signaling.js:57-68`):
    speichert `state.pendingOffer`, zeigt `#media-select-dialog`, holt den
    Anrufernamen über `act=get_username` und spielt `incomming_call_ringtone`.
14. B klickt `#media-accept-btn` (`main.js:56-109`): `getUserMedia` gemäß den
    Checkboxen → `loadIceServers()` → `createPeerConnection(false)` →
    `addLocalTracks()` → `setRemoteDescription(offer)` → `createAnswer()` →
    `setLocalDescription()`.
15. B: `POST {type:'answer', sdp, target: data.sender_id}` (`main.js:103-107`).
16. A's Poll erhält `answer` (`signaling.js:70-84`): `stopTimeout()`,
    `setRemoteDescription()`, danach werden gepufferte ICE-Kandidaten aus
    `refs.pendingCandidates` nachgereicht (Z. 78-83).
17. Parallel und laufend auf beiden Seiten: `onicecandidate`
    (`rtc.js:186-194`) → `POST {type:'iceCandidate', candidate, target}`.
    Der Empfänger fügt den Kandidaten sofort hinzu, oder puffert ihn in
    `refs.pendingCandidates`, solange noch keine `remoteDescription` gesetzt ist
    (`signaling.js:86-102`).
18. B's PeerConnection feuert `ondatachannel` (`rtc.js:180-183`) — B übernimmt
    den von A angelegten Channel und ruft ebenfalls `setupDataChannel()`.
19. `dc.onopen` (`rtc.js:232-237`) auf beiden Seiten: Klingelton stoppen,
    `#chat-area` einblenden, **`signaling.stopPolling()`** — ab hier wird das
    HTTP-Signaling eingestellt.
20. `ontrack` (`rtc.js:197-211`) hängt den Remote-Stream an `#remote-video` und
    blendet den Platzhalter aus. **Verbindung steht** — Video/Audio P2P,
    Steuerbefehle und Chat über den DataChannel.

**Abbau:** `hangup` wird über das HTTP-Signaling gesendet (`rtc.js:19-22`),
`call_failed` bei Medienfehlern (`rtc.js:387-392`). Zum funktionalen Problem
dieses Pfades siehe Abschnitt 9, Punkt **9.2 / F-3**.

---

## 4. STEUERPROTOKOLL

### 4.1 Kanal

Ein einziger `RTCDataChannel` mit dem Label **`"chat"`**, angelegt vom Initiator
in `assets/js/rtc.js:176`:

```js
window.webrtcApp.refs.dataChannel =
    window.webrtcApp.refs.localPeerConnection.createDataChannel("chat");
```

Es werden **keine Optionen** übergeben — also die WebRTC-Defaults:
`ordered: true`, zuverlässig (kein `maxRetransmits`/`maxPacketLifeTime`), keine
Priorisierung. Steuerbefehle, Chat-Text und Dateiübertragungen teilen sich
**denselben Kanal**.

Empfangsseitig übernimmt der Angerufene den Channel über `ondatachannel`
(`rtc.js:180-183`).

### 4.2 Nachrichtenformat

**Es gibt kein strukturiertes Format.** Jede Nachricht ist ein **roher
UTF-8-String** ohne Envelope. Kein JSON, kein Header, kein Trennzeichen.

- **Typ auf der Leitung:** `string` (bzw. `ArrayBuffer` für Dateien)
- **Feldnamen:** keine — der komplette String *ist* der Typ
- **Konvention:** Steuerbefehle sind mit doppelten Unterstrichen eingerahmt:
  `__<name>__`. Alles, was nicht exakt einem bekannten Literal entspricht,
  gilt als Chat-Text.
- **Payload/Parameter:** nicht möglich. Ein Befehl kann keine Argumente
  transportieren (kein Winkel, keine Dauer, keine Schrittweite, keine Intensität).

### 4.3 Vollständige Nachrichtenliste

Alle Handler in `assets/js/rtc.js:244-305` (`dc.onmessage`), in genau dieser
Reihenfolge geprüft:

| # | Nachricht (exakter String) | Richtung | Sender (Datei:Zeile) | Empfänger (Datei:Zeile) | Wirkung beim Empfänger |
|---|---|---|---|---|---|
| 1 | `__hangup__` | — | **kein Sender vorhanden** | `rtc.js:246` | `endCall(false)` + Alert „Der andere Teilnehmer hat die Verbindung beendet" |
| 2 | `__video_off__` | bidirektional | `main.js:299` | `rtc.js:251` | `#remote-video` ausblenden, Platzhalter einblenden |
| 3 | `__video_on__` | bidirektional | `main.js:324` | `rtc.js:263` | `#remote-video` einblenden, Platzhalter ausblenden |
| 4 | `__arrow_forward__` | Zuschauer → Guide | `main.js:136` | `rtc.js:276` | `sound.play("move_forward_sound", false)` |
| 5 | `__arrow_backward__` | Zuschauer → Guide | `main.js:136` | `rtc.js:280` | `sound.play("move_back_sound", false)` |
| 6 | `__arrow_left__` | Zuschauer → Guide | `main.js:136` | `rtc.js:284` | `sound.play("turn_left_sound", false)` |
| 7 | `__arrow_right__` | Zuschauer → Guide | `main.js:136` | `rtc.js:288` | `sound.play("turn_right_sound", false)` |
| 8 | *jeder andere String* | bidirektional | `chat.js:52-58` | `rtc.js:293-294` | wird als Chat-Nachricht ins Log gehängt |
| 9 | *Binärdaten (`ArrayBuffer`)* | bidirektional | `chat.js:64-72` | `rtc.js:296-304` | Blob + Download-Link „empfangene\_datei" |

**Beispiel-Payloads** (genau so gehen sie über die Leitung, ohne Anführungszeichen):

```
__arrow_forward__
__arrow_backward__
__arrow_left__
__arrow_right__
__video_off__
__video_on__
__hangup__
Hallo, kannst du nach links gehen?
```

**Zwei Sendewege für dieselbe Leitung:**

- Steuerpfeile gehen über `chat.send()` (`main.js:136`), das die Nachricht
  zusätzlich im **eigenen** Chatlog anzeigt (`chat.js:56`):
  ```js
  send(msg) {
      const dc = window.webrtcApp.refs.dataChannel;
      if (dc && dc.readyState === "open") {
          dc.send(msg);
          this.appendMsg("self", msg);   // chat.js:52-58
      }
  }
  ```
- Video-An/Aus geht **direkt** über `dataChannel.send()` (`main.js:299`, `:324`)
  mit eigener `readyState`-Prüfung — an `chat.send()` vorbei.

**Darstellung:** `chat.js:15-18` und `:37-40` übersetzen die vier
`__arrow_*__`-Literale beim Rendern in `↑ ↓ ← →`. Diese Zuordnung ist ein
drittes, unabhängiges Duplikat der Protokollkonstanten.

### 4.4 Versionierung

**Nicht vorhanden.** Es gibt keine Protokollversion, keine
Capability-Aushandlung, kein Feature-Flag, keine Kennung im DataChannel-Label
(`"chat"` ist konstant). Alte und neue Clients können nicht unterschieden werden.

### 4.5 Validierung eingehender Nachrichten

**Nicht vorhanden.** Konkret fehlt:

- **Keine Schema-/Formatprüfung.** Es wird ausschließlich per `===` gegen sieben
  Stringliterale verglichen (`rtc.js:246-291`).
- **Keine Längenbegrenzung.** Ein Peer kann beliebig große Strings senden; sie
  landen ungeprüft im Chatlog.
- **Keine Rate-Begrenzung auf der Empfangsseite.** Die einzige Drosselung ist ein
  100-ms-Throttle **beim Sender** (`main.js:125-138`) — rein clientseitig und
  damit für einen manipulierten Peer wirkungslos.
- **Keine Rollen-/Berechtigungsprüfung.** Der Empfänger prüft nicht, ob der
  Absender überhaupt steuern darf (siehe Abschnitt 5).
- **Keine Sequenznummern, keine IDs, kein Timestamp, keine Acknowledgements.**
  Verlorene oder doppelte Befehle sind nicht erkennbar. Es gibt keine Rückmeldung,
  ob ein Befehl ausgeführt wurde.
- **Keine Größenprüfung bei Binärdaten.** `rtc.js:296-304` baut aus jedem
  Nicht-String-Frame einen `Blob` und hängt einen Download-Link ins DOM — ohne
  Größen-, Typ- oder Anzahlbegrenzung.

Zusätzlich existieren die Audiodateien `assets/audio/look_up.mp3` und
`look_down.mp3` samt eingebundener Elemente `#look_up_sound` / `#look_down_sound`
(`assets/html/media.html:3-4`), aber **kein Protokollbefehl und keine
UI-Schaltfläche** löst sie aus. Die Blickrichtung nach oben/unten war offenbar
geplant, ist aber nicht implementiert.

### 4.6 Verhalten bei unbekannten Nachrichtentypen

Die Prüfkette in `rtc.js:244-305` ist eine Folge von `if (e.data === "...")` mit
`return`. Fällt eine Nachricht durch alle sieben Vergleiche, greift der
Auffangzweig:

```js
// assets/js/rtc.js:292-304
// Normale Chatnachricht
if (typeof e.data === "string") {
    window.webrtcApp.chat.appendMsg("remote", e.data);
} else {
    // Binärdaten = Datei empfangen
    const blob = new Blob([e.data]);
    ...
}
```

Das heißt konkret:

- Ein **Tippfehler oder ein Befehl aus einer neueren Client-Version** —
  z. B. `__arrow_up__` oder `__look_up__` — wird **stillschweigend als Chattext
  im Fenster des Guides angezeigt**. Kein Log, kein Fehler, keine Verwerfung.
- Ein **Zuschauer mit älterem Client** würde neue Befehle als kryptische Textzeilen
  sehen.
- Es ist **nicht unterscheidbar**, ob ein Steuerbefehl fehlschlug oder der
  Zuschauer schlicht Text geschrieben hat.

**Damit ist auch ein Injection-Pfad offen:** Sendet ein Peer den String
`__hangup__`, beendet er den Call des Gegenübers (`rtc.js:246`) — legitimer
Protokollteil. Sendet er stattdessen HTML wie
`<img src=x onerror=alert(1)>`, landet das im Auffangzweig. Im P2P-Chatlog
(`chat.js:20`, `:43`) wird `textContent` verwendet und ist damit sicher — im
**DB-Chat** dagegen wird per `.append()` mit Template-String gerendert
(`ui_chat.js:262-274`) und ist verwundbar (siehe Abschnitt 9, S-4).

### 4.7 Was das Protokoll nicht kann

Gemessen am Anwendungsfall „Zuschauer steuert den Guide" ist der Umfang
bemerkenswert klein — **der empfangene Steuerbefehl spielt beim Guide
ausschließlich eine Audiodatei ab** (`rtc.js:276-291`). Es gibt keine visuelle
Anzeige der Richtung, keine Bestätigung, keinen Zustand und keine Möglichkeit,
mehrere Befehle zu unterscheiden, wenn sie schnell hintereinander kommen.

Ebenfalls nicht vorhanden: Blick nach oben/unten (Assets liegen bereit, s. o.),
Stopp-Befehl, Geschwindigkeits- oder Dauerangabe, Zielpunkt-Markierung,
Steuerungsanfrage/-freigabe.

**Wichtige Abweichung zur README:** Die README beschreibt „Zuschauer navigieren
den Guide via **Pfeiltasten**". Ein `keydown`/`keyup`-Handler für
`ArrowUp`/`ArrowDown`/`ArrowLeft`/`ArrowRight` ist im gesamten Repo
**nicht vorhanden**. Die einzigen `keydown`-Listener sind
`assets/js/ui_rtc.js:36` (Enter im Chat-Input) und
`assets/js/ui_chat.js:165` (Enter im Chat-Popup). Gesteuert wird ausschließlich
über die vier Buttons `#btn-forward` / `#btn-backward` / `#btn-left` /
`#btn-right` bzw. deren `-mobile`-Varianten
(`assets/html/inner_call_controll.html:74-77`, `:88-91`; Handler
`assets/js/main.js:129-141`).

---

## 5. ROLLEN & BERECHTIGUNGEN

### 5.1 Rollenmodell

Vier Rollen, definiert in `database.sql:18-22`:

| `usertype.id` | `usertype.name` |
|---|---|
| 0 | `Admin` |
| 1 | `Guide` |
| 2 | `User` |
| 3 | `Trial` |

Die Rolle hängt am Benutzer (`user.type_id`, `database.sql:54`), **nicht** an der
Verbindung. Standard bei der Registrierung ist hartcodiert `type_id = 3` (Trial)
in `class/Model/User.php:73` — obwohl der Spaltendefault `2` ist
(`database.sql:54`).

### 5.2 Wie wird zwischen Guide und Zuschauer unterschieden?

**Zur Laufzeit eines Calls: gar nicht.**

Das ist der zentrale Befund dieses Abschnitts. Die WebRTC-Verbindung kennt keine
Rollen:

- `rtc.js:148` `createPeerConnection(isInitiator)` unterscheidet nur
  *Anrufer* vs. *Angerufener* — und das ausschließlich, um festzulegen, wer den
  DataChannel anlegt (Z. 175-178). Mit „Guide" oder „Zuschauer" hat das nichts zu tun.
- Der Empfangs-Handler `rtc.js:244-305` prüft **keine Rolle**, bevor er einen
  Steuerbefehl ausführt.
- Der Sende-Handler `main.js:129-141` prüft **keine Rolle**, bevor er einen
  Steuerbefehl sendet.
- Die Steuerkreuz-Buttons stehen in `inner_call_controll.html:73-78` und
  `:86-92` **unbedingt** im Template — sie werden für jeden Teilnehmer gerendert.

**Folge:** In jedem Call kann **jede der beiden Seiten die andere steuern**. Der
Guide kann dem Zuschauer Pfeilbefehle schicken; die spielen dort dieselben
Richtungs-Sounds ab. Es gibt keine technische Unterscheidung, wer „vor Ort" ist.

Die Rolle wird nur an drei Stellen überhaupt ausgewertet, alle außerhalb des Calls:

1. `class/Helper/ViewHelper.php:62,80` — `window.userRole` wird als String ins
   Frontend geschrieben.
2. `assets/js/ui.js:12-31` — steuert, ob der Button „Neue Lokation hinzufügen"
   bzw. „Jetzt Tour-Guide werden!" erscheint. **Diese Prüfung ist defekt**, siehe
   Abschnitt 9, F-5.
3. `class/Controller/UserController.php:26,91,219` — Admin-Funktionen der
   Benutzerliste. **Inkonsistent**, siehe Abschnitt 9, S-6.

### 5.3 Kann ein Raum mehrere Zuschauer haben?

**Nein.** Das System ist strikt 1:1, und die Beschränkung liegt nicht an einer
Stelle, sondern ist strukturell an sechs Stellen verankert:

| Ort | Warum es 1:1 erzwingt |
|---|---|
| `assets/js/app.js:17` | `refs.localPeerConnection` ist **ein einzelnes Feld**, kein Array/Map. |
| `assets/js/app.js:18` | `refs.dataChannel` ist ebenfalls **ein einzelnes Feld**. |
| `assets/js/rtc.js:154` | `if (window.webrtcApp.refs.localPeerConnection) return;` — ein zweiter Verbindungsaufbau wird **aktiv verhindert**. |
| `assets/js/app.js:5` | `state.activeTargetUserId` ist **eine einzelne ID**, kein Array. |
| `assets/js/rtc.js:186-194` | ICE-Kandidaten gehen an genau `state.activeTargetUserId` — es gibt keinen Verteilmechanismus. |
| `class/Model/WebRTCHandler.php:60-61` | `rtc_signal` hat genau ein `receiver_id`-Feld pro Zeile; ein Signal adressiert exakt einen Empfänger. |
| `assets/html/inner_call_controll.html:15` | Es existiert genau **ein** `<video id="remote-video">`. |

Es gibt **kein Raum-Konzept** im Datenmodell: keine `room`-Tabelle, keine
`session`-Tabelle, keine Teilnehmerliste. `database.sql` (Abschnitt 7) enthält
nichts dergleichen. Ein Call ist ein flüchtiger Zustand, der nur im Browser-RAM
beider Peers und in kurzlebigen `rtc_signal`-Zeilen existiert.

### 5.4 Wer darf steuern, und wie ist das geregelt?

**Es ist nicht geregelt.** Beide Peers dürfen jederzeit steuern, solange der
DataChannel offen ist (`chat.js:54`: `if (dc && dc.readyState === "open")`).

Es gibt **nicht vorhanden**:

- keine Steuerungsfreigabe / Erteilung von Kontrolle
- keine Sperre gegen Steuerung durch den Guide selbst
- keine serverseitige Autorisierung (der Server sieht die Steuerbefehle nie —
  sie laufen P2P)
- kein Kick / kein Entzug der Kontrolle
- keine Prüfung, ob der Steuernde Guide oder Zuschauer ist

### 5.5 Wer darf wen anrufen?

**Jeder eingeloggte Benutzer jeden anderen.** `WebRTCController::getSignal()`
(Z. 21-68) prüft ausschließlich, dass eine Session existiert. Es findet **keine**
Prüfung statt, ob

- der Ziel-Benutzer (`$data['target']`, Z. 32) überhaupt existiert,
- der Ziel-Benutzer den Anruf wünscht,
- eine Beziehung zwischen den beiden besteht,
- der Absender die Rolle für einen Anruf hat.

Ein Signal wird ungeprüft in die Datenbank geschrieben und beim nächsten Poll des
Opfers ausgeliefert — dort öffnet sich der Anrufdialog samt Klingelton
(`signaling.js:57-68`). Ein Skript kann so beliebige Benutzer-IDs mit
Anrufdialogen fluten. Rate-Limiting gibt es an diesem Endpunkt **nicht vorhanden**.

---

## 6. ICE / NAT-TRAVERSAL

### 6.1 STUN-Server

**Keine hartcodierten STUN-Server im Repo.** Ich habe das gesamte Repo nach
`stun:` durchsucht — es gibt **keinen einzigen Treffer** in einer
Konfiguration. Insbesondere ist **kein Fallback** auf einen öffentlichen
STUN-Server (etwa `stun:stun.l.google.com:19302`) hinterlegt.

Sämtliche ICE-Server kommen zur Laufzeit von Metered.ca:

```js
// assets/js/rtc.js:334-352
loadIceServers: async function() {
    const response = await fetch("index.php?act=get_turn_credentials");
    let iceServers = await response.json();
    ...
}
```

Die Liste wird bei mehr als 4 Einträgen auf max. 2 STUN + 2 TURN gekürzt
(`rtc.js:343-347`) und in `refs.meteredIceServers` gecacht; `iceServersLoaded`
verhindert erneutes Laden (`rtc.js:98`, `:150`).

Verwendet wird sie an genau einer Stelle:

```js
// assets/js/rtc.js:156-157
const config = { iceServers: window.webrtcApp.refs.meteredIceServers };
window.webrtcApp.refs.localPeerConnection = new RTCPeerConnection(config);
```

Es werden **keine** weiteren Optionen gesetzt: kein `iceTransportPolicy`,
kein `bundlePolicy`, kein `rtcpMuxPolicy`, kein `iceCandidatePoolSize`.

### 6.2 TURN-Server

**Ja — Metered.ca, dynamisch bezogen.**

Kette: `assets/js/rtc.js:335` → Route `get_turn_credentials`
(`config/routes.php:57`) → `TurnController::getTurnCredentials()`
(Z. 16-30) → `MeteredTurnService::fetch_turn_credentials()`
(`class/Model/MeteredTurnService.php:26-53`):

```php
// class/Model/MeteredTurnService.php:34-36
$appname = $_ENV['METERED_APP_NAME'];
$apikey  = $_ENV['METERED_API_KEY'];
$url = "https://$appname.metered.live/api/v1/turn/credentials?apiKey=$apikey";
```

Ein eigener TURN-Server (coturn o. ä.) ist **nicht vorhanden** — weder
Konfiguration noch Deployment-Artefakte.

### 6.3 Sind Credentials hartcodiert?

**Nein — und das ist sauber gelöst.** Positiv hervorzuheben:

- `METERED_API_KEY` und `METERED_APP_NAME` liegen in `.env`
  (`MeteredTurnService.php:34-35`); `.env` ist über `.gitignore` (`*.env`)
  ausgeschlossen.
- Der API-Key **verlässt den Server nie**: `TurnController` proxied den Aufruf
  und gibt nur die kurzlebigen, von Metered ausgestellten TURN-Credentials an den
  Browser weiter. Der Client sieht den Key nicht.
- Fehlende ENV-Variablen werden geprüft und führen zu einer Exception
  (`MeteredTurnService.php:30-32`).
- Es gibt einen cURL-Timeout von 10 s (`MeteredTurnService.php:40`).

Drei kleinere Schwächen an dieser Stelle:

1. **Kein Caching.** `loadIceServers()` wird pro Call aufgerufen
   (`rtc.js:99`, `main.js:89`); jeder Anruf erzeugt einen HTTP-Request gegen die
   Metered-API. Bei Kontingent-Überschreitung oder Metered-Ausfall schlägt jeder
   Anruf fehl — ohne Fallback (s. 6.1).
2. **Keine Fehlerbehandlung im Client.** `loadIceServers()` (`rtc.js:334-352`)
   hat kein `try/catch` und prüft nicht auf `{"error": ...}` (das
   `TurnController.php:24-28` bei Fehlern mit HTTP 500 liefert). Bei einem Fehler
   wird `meteredIceServers` auf das Fehlerobjekt gesetzt und
   `iceServersLoaded = true` — der `RTCPeerConnection`-Konstruktor bekommt dann
   Müll.
3. **Der Filter greift nur teilweise.** `rtc.js:344-345` filtert auf
   `startsWith('stun:')` und `startsWith('turn:')`. TURN-over-TLS-Einträge mit
   Schema `turns:` fallen bei der Kürzung **durch das Raster** und werden
   verworfen — ausgerechnet die Variante, die in restriktiven Netzen (Port 443)
   am zuverlässigsten durchkommt.

### 6.4 ICE-Restart bei Verbindungsabbruch

**Nicht vorhanden.** Weder `restartIce()` noch `createOffer({iceRestart: true})`
kommt im Repo vor.

Stattdessen wird bei jeder Störung sofort und endgültig aufgelegt:

```js
// assets/js/rtc.js:160-172
onconnectionstatechange = function() {
    if (["disconnected", "failed", "closed"].includes(...connectionState)) {
        window.webrtcApp.rtc.endCall(false);
        alert("Die Verbindung zum Gesprächspartner ist abgebrochen.");
    }
};
oniceconnectionstatechange = function() {
    if (["disconnected", "failed", "closed"].includes(...iceConnectionState)) {
        window.webrtcApp.rtc.endCall(false);
        alert("Die Verbindung zum Gesprächspartner ist unterbrochen (ICE).");
    }
};
```

Das ist aus mehreren Gründen problematisch:

- **`disconnected` ist ein transienter Zustand.** Er tritt regelmäßig bei einem
  WLAN-/Mobilfunk-Wechsel oder kurzen Paketverlusten auf und geht meist von selbst
  wieder in `connected` über. Hier führt er zum sofortigen, endgültigen Abbruch.
  Für eine Anwendung, deren Guide sich **per Definition draußen bewegt**, ist das
  der wahrscheinlichste Störfall überhaupt.
- **Beide Handler feuern.** `connectionState` und `iceConnectionState` erreichen
  bei einer Störung typischerweise beide einen der Zustände. Der Nutzer bekommt
  **zwei blockierende `alert()`-Dialoge** nacheinander.
- **Es gibt keinen Reconnect.** Kein automatischer Rückruf, keine
  Wiederherstellung, kein Zustand, der einen Wiederaufbau erlauben würde
  (`endCall` setzt `activeTargetUserId = null`, `rtc.js:28`).
- Auf Mobilgeräten wird zusätzlich nach 1 s die **ganze Seite neu geladen**
  (`rtc.js:36-38`).

Ebenfalls **nicht vorhanden**: `onicecandidateerror`-Handler,
`onnegotiationneeded`-Handler, Trickle-ICE-Ende-Signalisierung (`end-of-candidates`),
`getStats()`-Auswertung zur Qualitätsüberwachung.

---

## 7. DATENBANK

### 7.1 System

**MySQL/MariaDB** über PDO. Verbindung in `class/Model/PdoConnect.php:23-48`:

```php
$dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
```

Konfiguration (`PdoConnect.php:38-42`) ist solide:
`ATTR_ERRMODE = ERRMODE_EXCEPTION`, `ATTR_DEFAULT_FETCH_MODE = FETCH_ASSOC`,
`ATTR_EMULATE_PREPARES = false` (echte Prepared Statements). Alle Engines
InnoDB, Charset `utf8mb4`.

Kein ORM, kein Query-Builder, keine Migrationen — Schema ausschließlich als
`database.sql`. Kein Connection-Pooling, kein Retry.

### 7.2 Schema laut `database.sql`

**`usertype`** (`database.sql:12-22`) — Rollen-Stammdaten, vorbefüllt.

| Feld | Typ | Anmerkung |
|---|---|---|
| `id` | `int(11)` NOT NULL | PK, **kein AUTO_INCREMENT** (bewusst, da 0 = Admin) |
| `name` | `varchar(50)` NOT NULL | `Admin`(0), `Guide`(1), `User`(2), `Trial`(3) |

**`country`** (`database.sql:27-31`)

| Feld | Typ |
|---|---|
| `id` | `int(11)` AUTO_INCREMENT, PK |
| `country_name` | `varchar(255)` NOT NULL |

**`city`** (`database.sql:36-43`)

| Feld | Typ | Anmerkung |
|---|---|---|
| `id` | `int(11)` AUTO_INCREMENT, PK | |
| `city_name` | `varchar(255)` NOT NULL | **kein UNIQUE** |
| `country_id` | `int(11)` NOT NULL | FK → `country.id`, ON DELETE CASCADE |

**`user`** (`database.sql:48-66`)

| Feld | Typ | Default | Anmerkung |
|---|---|---|---|
| `id` | `int(11)` AI | | PK |
| `username` | `varchar(255)` NOT NULL | | **kein UNIQUE** (nur Applikationsprüfung, `User.php:240`) |
| `email` | `varchar(255)` NOT NULL | | UNIQUE KEY |
| `pwd` | `varchar(255)` NOT NULL | | Argon2i-Hash über HMAC-gepeppertes Passwort |
| `status` | `tinyint(4)` | 1 | **wird vom Code nie benutzt** (s. 7.4) |
| `type_id` | `int(11)` | 2 | FK → `usertype.id` |
| `email_verified` | `tinyint(1)` | 0 | |
| `last_aktive` | `datetime` | NULL | **wird vom Code nie benutzt** |
| `created_at` | `datetime` | CURRENT_TIMESTAMP | |
| `updated_at` | `datetime` | CURRENT_TIMESTAMP ON UPDATE | dient als Online-Heartbeat-Marker |
| `totp_secret` | `varchar(255)` | NULL | AES-256-CBC-verschlüsselt |
| `totp_enabled` | `tinyint(4)` | 0 | |
| `deleted` | `tinyint(4)` | 0 | Soft-Delete-Flag |

**`location`** (`database.sql:71-80`)

| Feld | Typ | Anmerkung |
|---|---|---|
| `id` | `int(11)` AI | PK |
| `city_id` | `int(11)` NOT NULL | FK → `city.id`, ON DELETE CASCADE |
| `latitude` | `decimal(10,8)` | |
| `longitude` | `decimal(11,8)` | |
| `description` | `text` | Beschreibung der Führung |

**`chat`** (`database.sql:85-98`)

| Feld | Typ | Anmerkung |
|---|---|---|
| `id` | `int(11)` AI | PK |
| `user1_id` / `user2_id` | `int(11)` NOT NULL | FK → `user.id`; per Konvention sortiert (`Chat.php:43-44`) |
| `is_active` | `tinyint(4)` | 0 = Einladung offen, 1 = angenommen |
| `last_msg_at` | `datetime` | |
| `pending_for` | `int(11)` | User-ID, die noch annehmen muss |
| `deleted` | `tinyint(4)` | Soft-Delete |

**`chat_message`** (`database.sql:103-115`)

| Feld | Typ | Anmerkung |
|---|---|---|
| `id` | `int(11)` AI | PK |
| `chat_id` | `int(11)` NOT NULL | FK → `chat.id`, ON DELETE CASCADE |
| `sender_id` | `int(11)` NOT NULL | FK → `user.id` |
| `msg` | `text` NOT NULL | |
| `sent_at` | `datetime` | CURRENT_TIMESTAMP |
| `seen` | `tinyint(4)` | 0 |

**`rtc_signal`** (`database.sql:120-133`) — die Signaling-Warteschlange

| Feld | Typ | Anmerkung |
|---|---|---|
| `id` | `int(11)` AI | PK |
| `sender_id` | `int(11)` NOT NULL | FK → `user.id`, ON DELETE CASCADE |
| `receiver_id` | `int(11)` NOT NULL | FK → `user.id`, ON DELETE CASCADE, KEY |
| `type` | `varchar(50)` | `offer` / `answer` / `iceCandidate` / `hangup` / `call_failed` |
| `sdp` | `text` | SDP bei offer/answer |
| `candidate` | `text` | ICE-Kandidat als JSON-String |
| `created_at` | `datetime` | CURRENT_TIMESTAMP — Basis für das 15-s-Fenster |

Kein Index auf `created_at`, obwohl jede Abfrage darauf filtert
(`WebRTCHandler.php:85`).

### 7.3 Wie werden Standorte gespeichert und abgefragt?

**Speichern** (`LocationController::setLocation()`, Z. 32-63):

1. POST aus `set_location.html`: `country` (ID), `city` (Name), `longitude`,
   `latitude`, `description`.
2. Validierung: **nur** `strlen($description) < 5` (Z. 42). Koordinaten werden
   **nicht** validiert.
3. `Location::setNewLocation($user_id, $country_id)` (`Location.php:62-82`):
   - `selectCity()` (Z. 226-240) sucht die Stadt **nur über den Namen**, ohne
     `country_id`-Bedingung. „Springfield" in Land A trifft damit „Springfield"
     in Land B.
   - Existiert sie nicht: `insertCityName($country_id)` (Z. 247-261) legt sie an.
   - `insertLocation($user_id, $city_id)` (Z. 90-107) schreibt die Zeile.

Die Koordinaten stammen aus der Leaflet-Karte bzw. der Nominatim-Suche
(`assets/js/map.js:154, 184, 224, 327, 370`).

**Abfragen** — drei Wege:

| Route | Controller | Model-Methode | SQL |
|---|---|---|---|
| `get_locations` | `LocationController.php:83-90` | `selectAllLocations($userId)` (`Location.php:175-194`) | `location` LEFT JOIN `user`/`city`/`country`, `WHERE user.id != :user_id` (fremde Standorte) |
| `get_my_locations` | `LocationController.php:96-103` | `selectAllLocationsOfOneUser($userId)` (`Location.php:201-220`) | dasselbe mit `WHERE user.id = :user_id` |
| — (Einzelsatz) | `new Location($id)` | Konstruktor (`Location.php:22-53`) | `location` JOIN `city` JOIN `country` WHERE `location.id = :id` |

Das Frontend rendert die Ergebnisse in `assets/js/locations_table.js:23-90`
(DataTables) und auf der Leaflet-Karte (`assets/js/map.js`).

Getrennt davon gibt es eine **zweite, unabhängige Positionsspeicherung**: die
Live-Position des Nutzers via Browser-Geolocation
(`assets/js/location_prompt.js:5-36` → `act=save_location` →
`UserController::saveLocation()` Z. 169-197 → `User::saveLocation()` Z. 488-501),
die in Spalten der **`user`**-Tabelle schreibt. Diese Spalten fehlen im Schema
(s. 7.4).

### 7.4 Schema stimmt nicht mit dem Code überein

Die README erklärt selbst, dass `database.sql` nachträglich aus den Models
rekonstruiert wurde. Die Rekonstruktion ist an sechs Stellen unvollständig — jede
davon führt zu einem SQL-Fehler zur Laufzeit:

| # | Was der Code erwartet | Was `database.sql` liefert | Betroffene Stellen |
|---|---|---|---|
| 1 | `user.user_status` | nur `user.status` (Z. 53) | `User.php:47,98,283,302,306,362`; `Location.php:178,204`; `cron/check_online_status.php:21` |
| 2 | `user.latitude`, `user.longitude`, `user.location_updated_at` | **fehlen komplett** | `User.php:491` |
| 3 | `location.user_id` | **fehlt komplett** (Z. 71-80) | `Location.php:93,96,182,208` |
| 4 | Tabelle `password_resets` (`user_id`, `token`, `expires_at`) | **fehlt komplett** | `PasswordController.php:47,51,78,121,133` |
| 5 | Tabelle `email_verifications` (`user_id`, `token`, `expires_at`) | **fehlt komplett** | `EmailVerificationController.php:25,40,83` |
| 6 | — | `user.last_aktive` (Z. 56) wird von keiner Zeile Code gelesen oder geschrieben | (verwaistes Feld) |

**Auswirkung:** Mit dem gelieferten `database.sql` sind Login (`user_status`),
Standortanlage (`location.user_id`), Passwort-Reset und E-Mail-Verifizierung
sämtlich nicht lauffähig. Punkt 3 ist besonders gravierend, weil damit die
**Zuordnung Standort → Guide** — das Kernkonzept der Plattform — im gelieferten
Schema schlicht fehlt.

---

## 8. AUTHENTIFIZIERUNG

### 8.1 Gibt es überhaupt welche?

**Ja**, und für ein Projekt dieser Größe ist sie erstaunlich ausgebaut:
Registrierung, Login mit Brute-Force-Sperre, TOTP-2FA mit QR-Code,
Passwort-Reset per Token, E-Mail-Verifizierung.

**Passwort-Speicherung** (`class/Model/User.php:509-519`) — sauber gemacht:

```php
$pepper       = $_ENV['PEPPER'];
$pwd_peppered = hash_hmac("sha256", $in_pwd, $pepper);
$pwd_hashed   = password_hash($pwd_peppered, PASSWORD_ARGON2I);
```

HMAC-Pepper aus ENV plus Argon2i — das ist besser als in vielen Produktivsystemen.
Verifikation entsprechend über `password_verify()` (`User.php:208`).

**Brute-Force-Schutz** (`LoginController.php:31-32, 44-59, 103-115`): 5 Versuche,
dann 300 s Sperre. **Aber:** der Zähler liegt in `$_SESSION` (Z. 44-48). Ein
Angreifer, der pro Versuch kein Session-Cookie sendet, bekommt jedes Mal eine
frische Session — die Sperre ist damit **wirkungslos**. Zusätzlich ist sie
`$username`-indiziert, greift also nicht gegen Password-Spraying über viele
Konten.

**2FA** (`TwoFactorController.php`): TOTP über `spomky-labs/otphp`, SHA1, 6
Stellen, 30 s Periode (Z. 38). QR-Code als Data-URI (Z. 51-54). Kein Window/Drift
konfiguriert, **keine Backup-Codes**, **kein Replay-Schutz** (derselbe Code ist
30 s lang mehrfach verwendbar).

### 8.2 Wie werden Sessions gehalten?

Native **PHP-Sessions**, Konfiguration in `config/session.php:3-10`:

```php
session_set_cookie_params([
    'httponly'  => true,
    'secure'    => true,
    'samesite'  => 'Strict'
]);
session_start();
```

Alle drei Flags sind gesetzt — gut. Ergänzt durch `index.php:18-22`, das HTTP auf
HTTPS umleitet (301).

Der Session-Inhalt wird beim Login gesetzt (`LoginController.php:81-86`):
`$_SESSION['user'] = ['user_id', 'username', 'email', 'role_id']`.

Weitere Session-Schlüssel: `2fa_userid` (Z. 76), `2fa_temp_secret`
(`TwoFactorController.php:40`), `login_attempts` / `login_blocked_until`
(`LoginController.php:44-48`), `location_prompt_shown` (Z. 89).

**Was fehlt:**

- **Kein `session_regenerate_id()` nach 2FA.** `LoginController.php:80` macht es
  beim normalen Login korrekt — der 2FA-Pfad in
  `TwoFactorController::handle2FAVerify()` (Z. 185-189) setzt
  `$_SESSION['user']` aber **ohne** Regeneration. Genau der abgesicherte Pfad ist
  damit anfällig für Session-Fixation.
- **Keine Session-Lebensdauer / kein Idle-Timeout.** Weder
  `session.gc_maxlifetime` noch `cookie_lifetime` sind gesetzt, kein
  Last-Activity-Check.
- **Keine serverseitige Session-Invalidierung.** Ein Passwortwechsel
  (`PasswordController.php:207-214`) beendet andere Sessions nicht.
- **Kein einheitlicher Auth-Guard.** Jeder Controller prüft selbst — und mehrere
  vergessen es (siehe Abschnitt 9, S-1/S-2).
- **Keine Rollen-Prüfung im Session-Kontext.** `role_id` wird beim Login in die
  Session geschrieben (`LoginController.php:85`) und danach aus der Session
  gelesen (`UserController.php:26`). Eine Rollenänderung durch den Admin wirkt
  erst nach Neuanmeldung.
- **CSRF-Schutz: nicht vorhanden.** Kein Token in irgendeinem Formular, keine
  Prüfung in irgendeinem Controller. `SameSite=Strict` mildert das ab, ersetzt es
  aber nicht — besonders da zahlreiche zustandsändernde Aktionen per **GET**
  laufen (`delete_user`, `delete_location`, `2fa_disable`,
  `config/routes.php:38,51,80`).

### 8.3 Wo liegen die Secrets?

**Durchweg in Umgebungsvariablen** — im Code ist kein einziges Secret hartcodiert.
Geladen über `vlucas/phpdotenv` in `config/env.php:5-6` (`createImmutable`).

| Variable | Verwendung |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PW` | `PdoConnect.php:29-33` |
| `PEPPER` | Passwort-HMAC (`User.php:201,511`) **und** AES-Schlüssel für TOTP (`TwoFactorController.php:223,228`) |
| `METERED_API_KEY`, `METERED_APP_NAME` | `MeteredTurnService.php:30,34-35` |
| `SMTP_SERVER`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD` | `Email.php:34-41` |
| `APP_ENV` | in `.env` dokumentiert (README), **im Code nirgends ausgewertet** |

`.env` ist über `.gitignore` (`*.env`) ausgeschlossen. Eine `.env.example` als
Vorlage ist **nicht vorhanden** (die README dokumentiert die Variablen dafür
vollständig).

**Drei Probleme beim Umgang mit den Secrets:**

1. **`PEPPER` wird doppelt verwendet.** Er ist gleichzeitig HMAC-Pepper für
   Passwörter (`User.php:511`) **und** AES-256-CBC-Schlüssel für TOTP-Secrets
   (`TwoFactorController.php:223`). Ein Schlüssel für zwei kryptografische Zwecke;
   eine Rotation wäre nur um den Preis machbar, alle Passwörter **und** alle
   2FA-Secrets zugleich unbrauchbar zu machen.

2. **Statischer IV bei der TOTP-Verschlüsselung** (`TwoFactorController.php:224`,
   `:229`):
   ```php
   openssl_encrypt($secret, 'aes-256-cbc', $key, 0, substr($key, 0, 16));
   ```
   Der Initialisierungsvektor wird aus dem Schlüssel abgeleitet und ist damit für
   **alle** Benutzer identisch. Gleiche TOTP-Secrets ergeben gleiche Chiffrate;
   die Semantik von CBC ist gebrochen. Korrekt wäre ein zufälliger IV pro
   Datensatz, gespeichert neben dem Chiffrat — und AEAD (`aes-256-gcm`) statt CBC.

3. **TOTP-Secrets landen im Klartext im Logfile.** `TwoFactorController.php`
   schreibt an **sieben** Stellen `error_log()`-Zeilen mit dem entschlüsselten
   Secret: Z. 41, 45, 94, 107, 109, 115, 175, 177. Beispiel Z. 41:
   ```php
   error_log("// DEBUG: Neues Secret erzeugt: [$secret]");
   ```
   Ziel ist `php-error.log` **im Webroot** (`config/error_handler.php:7`:
   `__DIR__ . '/../php-error.log'`). Damit stehen sämtliche 2FA-Secrets im
   Klartext in einer Datei, die je nach Serverkonfiguration über HTTP abrufbar ist.
   `.gitignore` deckt `*.log` ab — der Webserver aber nicht.

---

## 9. ZUSTAND DES CODES

### 9.1 Fehlende Dateien / harte Fehler

| ID | Datei:Zeile | Befund |
|---|---|---|
| **H-1** | `config/routes.php:11,60,61` | `MessageController` wird importiert und für die Routen `process_message` und `goto_chat` registriert. **Die Klasse existiert nicht** (`class/Controller/` enthält kein `MessageController.php`). Aufruf von `index.php?act=process_message` → `new $class()` in `index.php:45` → Fatal Error → HTTP 500. |
| **H-2** | `class/Controller/SignupController.php:71` | `ViewHelper::output($out);` — **`$out` ist nie definiert.** Das ist der **Erfolgspfad der Registrierung**: Nutzer wird angelegt (Z. 62), dann bricht die Ausgabe ab. Nur `$out` ist undefiniert, weil der eigentliche Aufruf `sendVerification()` eine Zeile darunter (Z. 72) auskommentiert wurde. |
| **H-3** | `class/Controller/UserController.php:54` | `SystemController::pwdEncrypt($pwd)` — **diese Methode existiert in `SystemController` nicht** (die Klasse hat nur `showAdmin`, `generateHtmlOptions`, `home`, `showStart`). Das Passwort-Setzen durch den Admin läuft in einen Fatal Error. Die Methode liegt in `User::pwdEncrypt()` (`User.php:509`). |
| **H-4** | `class/Controller/SystemController.php:60,63` | `file_get_contents("assets/html/frontend/home.html")` und `.../goto_chat.html` — **das Verzeichnis `assets/html/frontend/` existiert nicht.** |
| **H-5** | `class/Controller/SystemController.php:66` | `output_fe($html);` — **diese Funktion ist nirgends definiert.** Zusammen mit H-4 ist die Route `start` (`routes.php:33`) komplett tot. |
| **H-6** | `composer.json:6` | PSR-4-Mapping `"Domin\\RtcPsr\\": "src/"` — **`src/` existiert nicht.** |

### 9.2 Funktionale Fehler

| ID | Datei:Zeile | Befund |
|---|---|---|
| **F-1** | `class/Controller/WebRTCController.php:55-59` | **Race Condition im Signaling.** `getAllSignalsForReceiver()` liest, danach löscht `deleteSignalsForReceiver($receiver)` **alle** Zeilen des Empfängers — nicht nur die gerade gelesenen. Trifft zwischen SELECT und DELETE ein neues Signal ein (z. B. ein ICE-Kandidat), wird es gelöscht, **ohne je ausgeliefert worden zu sein**. Bei 1500 ms Poll-Intervall und Trickle-ICE ist dieses Fenster regelmäßig belegt. Das ist die wahrscheinlichste Ursache für sporadisch scheiternde Verbindungsaufbauten. |
| **F-2** | `assets/js/rtc.js:246` | `__hangup__` wird empfangen, aber **von keiner Stelle im Repo gesendet**. Toter Empfangspfad. |
| **F-3** | `assets/js/rtc.js:236` vs. `:19-22` | **Auflegen funktioniert im laufenden Call nicht.** `dc.onopen` ruft `signaling.stopPolling()` (Z. 236) — der Client fragt danach keine Signale mehr ab. `endCall()` sendet das `hangup` aber genau über dieses HTTP-Signaling (Z. 19-22). Der Gegenüber pollt nicht mehr und **erfährt vom Auflegen nie**. Er merkt es erst indirekt, wenn die PeerConnection auf `disconnected`/`closed` läuft (Z. 160-172) — mit der generischen Meldung „Die Verbindung … ist abgebrochen." Passenderweise existiert für genau diesen Zweck der ungenutzte `__hangup__`-Pfad (F-2) über den DataChannel. |
| **F-4** | `assets/js/rtc.js:88`, `:235`, `:241` | `document.getElementById('arrow-control')` — **ein Element mit dieser ID gibt es nirgends.** Im HTML heißt die *Klasse* `arrow-controls` (`inner_call_controll.html:73`, `:86`). Alle drei Zugriffe liefern `null`. Zwei davon sind ohnehin auskommentiert (Z. 235, 241) — d. h. das Steuerkreuz wird nie gezielt ein- oder ausgeblendet, sondern hängt allein an der Sichtbarkeit von `#call-view`. |
| **F-5** | `assets/js/ui.js:17,19` | **Rollenprüfung greift nie.** Verglichen wird gegen `'admin'`, `'guide'`, `'tourist'` (klein). `window.userRole` kommt aus `ViewHelper.php:62,80` → `User::getUsertype()` (`User.php:410-429`) → liefert `usertype.name` aus der DB, also `'Admin'`, `'Guide'`, `'User'`, `'Trial'` (groß, und `'tourist'` existiert gar nicht). Ergebnis: `text` bleibt leer, `location-button` wird immer ausgeblendet (Z. 26). **Der Button „Neue Lokation hinzufügen" ist für niemanden erreichbar** — bei einer Plattform, deren Kern das Anbieten von Standorten ist. |
| **F-6** | `class/Controller/LocationController.php:48-51` | Gleicher Fehler serverseitig: `if ($user->getUsertype() === 'tourist')` → `setUsertype('guide')`. Weder `'tourist'` noch `'guide'` sind gültige `usertype.name`-Werte (`database.sql:19-22`). Der automatische Aufstieg Zuschauer → Guide **findet nie statt**. |
| **F-7** | `class/Controller/UserController.php:91,219` | `if ($user->getRoleId() === 1)` schaltet Admin-Spalten und -Aktionen frei. `1` ist laut `database.sql:20` aber **`Guide`**, nicht Admin (`0`). Guides sehen E-Mail-Adressen und Löschen-Buttons aller Nutzer; **echte Admins (`0`) sehen sie nicht.** Zusätzlich ist `===` strikt: kommt `role_id` als String `"1"` aus der Session, schlägt der Vergleich fehl. |
| **F-8** | `class/Controller/UserController.php:26` | `if ($_SESSION['user']['role_id'] > 1)` lässt `0` (Admin) **und** `1` (Guide) in `manageUser()` — also in die Benutzerverwaltung mit Passwort-Änderung. Inkonsistent zu F-7 in derselben Klasse. |
| **F-9** | `class/Model/Location.php:226-240` | `selectCity()` sucht nur `WHERE city_name = :city`, **ohne `country_id`**. Gleichnamige Städte in verschiedenen Ländern werden zusammengeworfen; der Standort landet im falschen Land. |
| **F-10** | `class/Controller/ChatController.php:255` | Platzhalter-Array `['###STATUS', '###PARTNER_NAME', '###LAST_MSG###', '###SHOW_CHAT###']` — bei den ersten beiden fehlen die **schließenden `###`**. Der Ersatz greift nur, wenn das Template zufällig dieselbe verstümmelte Form nutzt. |
| **F-11** | `assets/js/signaling.js:114` | `window.webrtcApp.state.pendingOffer.sender_id` ohne Null-Prüfung. `pendingOffer` ist initial `null` (`app.js:8`) und wird von `endCall()` zurückgesetzt (`rtc.js:30`). Trifft ein `hangup` ein, ohne dass ein Call aktiv ist, wirft der Zugriff einen TypeError. |
| **F-12** | `assets/js/rtc.js:160-172` | Beide State-Change-Handler rufen `endCall(false)` **und** `alert()`. Bei einer echten Störung feuern typischerweise beide → **zwei blockierende Dialoge** hintereinander. |
| **F-13** | `assets/js/main.js:121` | `setTimeout(updateCallIcons(), 1000)` — die Funktion wird **sofort aufgerufen**, ihr Rückgabewert (`undefined`) an `setTimeout` übergeben. Gemeint war `setTimeout(updateCallIcons, 1000)`. |
| **F-14** | `assets/js/main.js:76,83` | `msg = '...'` ohne `var`/`let`/`const` → **implizite globale Variable**. |
| **F-15** | `class/Controller/UserController.php:155` | `new User($data)` — `$data` ist das dekodierte JSON. `ui_rtc.js:58` sendet `JSON.stringify(userId)`, also einen nackten Skalar. Funktioniert nur, weil `json_decode("5")` die Zahl `5` liefert; jede Änderung am Client-Format (etwa `{userId: 5}`) bricht es lautlos. |
| **F-16** | `cron/check_online_status.php:21` | `WHERE updated_at < (NOW() - INTERVAL 20 SECOND)` **ohne** `AND user_status != 'offline'`. Der Job schreibt bei jedem Lauf alle längst offline stehenden Nutzer erneut — und da `updated_at` `ON UPDATE CURRENT_TIMESTAMP` ist (`database.sql:58`), setzt der eigene UPDATE den Zeitstempel zurück. Der Timeout ist damit teilweise wirkungslos. |
| **F-17** | `assets/js/rtc.js:344-345` | Der ICE-Filter kennt nur `stun:` und `turn:`. **`turns:`-Einträge (TURN über TLS/443) werden verworfen** — genau die Variante, die restriktive Firewalls am ehesten passieren lässt. |
| **F-18** | `assets/js/rtc.js:334-352` | `loadIceServers()` hat **kein `try/catch`** und prüft die Antwort nicht auf das `{"error": …}`-Objekt, das `TurnController.php:24-28` im Fehlerfall liefert. Bei Metered-Ausfall wird das Fehlerobjekt als `iceServers` an `RTCPeerConnection` gereicht und `iceServersLoaded = true` gesetzt — der Fehler ist danach dauerhaft gecacht. |

### 9.3 Sicherheitsbefunde

| ID | Datei:Zeile | Befund |
|---|---|---|
| **S-1** | `class/Controller/ChatController.php:61-71`, `111-149`, `215-228` | **Fehlende Autorisierung (IDOR).** `acceptChat()`, `getMessages()` und `setMessagesSeen()` prüfen **nicht**, ob der eingeloggte Nutzer Teilnehmer des Chats ist. `acceptChat()` liest nicht einmal `$_SESSION`. Mit einer beliebigen `chat_id` lässt sich **jeder fremde Chatverlauf auslesen** (Z. 124) und jede fremde Einladung annehmen (Z. 69). `showChat()` (Z. 281) und `declineChat()` (Z. 202) machen die Prüfung korrekt — die drei anderen nicht. |
| **S-2** | `class/Controller/LocationController.php:124-145`, `152-179` | **Fehlende Autorisierung (IDOR).** `editLocationDesc()` und `deleteLocation()` prüfen weder Login noch Eigentum. Jeder kann per `index.php?act=delete_location&id=<n>` **jeden fremden Standort löschen** oder dessen Beschreibung überschreiben. `deleteLocation` ist zudem per GET erreichbar (`routes.php:51`) — ein `<img src>` genügt. |
| **S-3** | `assets/js/locations_table.js:48-51`, `84-86` | **Gespeichertes XSS.** `${item.description}`, `${item.username}`, `${item.country_name}`, `${item.city_name}` werden **ungefiltert** in einen HTML-String interpoliert und per DataTables ins DOM gehängt. Die Beschreibung stammt aus einem Formularfeld (`LocationController.php:39`), das serverseitig nur auf Mindestlänge geprüft wird (Z. 42) und beim Ausliefern (Z. 88) nicht escaped wird. Ein Guide kann Skriptcode in jeder Sitzung ausführen, die seine Location sieht. |
| **S-4** | `assets/js/ui_chat.js:262-274` | **Gespeichertes XSS.** `const cleanMsg = String(msg.msg).replace(/\n/g, '<br>')` — der Name täuscht, es wird nichts bereinigt. `${cleanMsg}` und `${msg.sent_at}` gehen per `$tab.find(...).append(msgHtml)` ins DOM. `ChatController::sendMessage()` (Z. 155-173) speichert die Nachricht ungefiltert. Jede Chatnachricht kann Skriptcode im Browser des Empfängers ausführen. |
| **S-5** | projektweit | **Kein CSRF-Schutz.** Kein Token in einem der 21 HTML-Templates, keine Prüfung in einem der 12 Controller. Verschärft dadurch, dass zustandsändernde Aktionen per GET erreichbar sind: `delete_user` (`routes.php:38`), `delete_location` (`:51`), `2fa_disable` (`:80`). |
| **S-6** | `class/Controller/UserController.php:79-84` | `listUser()` ruft bei fehlender Session `SystemController::home()` — **ohne `exit`**. Die Ausführung liefe weiter; nur weil `ViewHelper::output()` intern `die()` aufruft (`ViewHelper.php:92`), fällt das nicht auf. Die anderen Guards in derselben Datei (Z. 22-29) setzen `exit` korrekt. |
| **S-7** | `class/Controller/WebRTCController.php:29-30` | Der POST-Zweig prüft nur `isset($data['type'])` und `isset($data['target'])`, greift dann aber ungeprüft auf `$_SESSION['user']['user_id']` zu. Es wird **nicht validiert**, ob `target` eine existierende User-ID ist, ob eine Beziehung besteht, oder wie viele Signale ein Nutzer absetzen darf. Beliebige Nutzer lassen sich mit Anrufdialogen fluten (s. Abschnitt 5.5). |
| **S-8** | `class/Controller/TwoFactorController.php:41,45,94,107,109,115,175,177` | **TOTP-Secrets im Klartext im Log** (Details in Abschnitt 8.3, Punkt 3). Ziel ist `php-error.log` **im Webroot** (`config/error_handler.php:7`). |
| **S-9** | `class/Controller/TwoFactorController.php:224,229` | **Statischer IV**, aus dem Schlüssel abgeleitet (Details in Abschnitt 8.3, Punkt 2). |
| **S-10** | `class/Controller/TwoFactorController.php:185-189` | **Kein `session_regenerate_id()` nach erfolgreicher 2FA** — anders als beim normalen Login (`LoginController.php:80`). Session-Fixation ausgerechnet im abgesicherten Pfad. |
| **S-11** | `class/Model/User.php:165-167` | `register()` akzeptiert Passwörter ab **3 Zeichen**. Der Controller prüft davor auf 8 (`SignupController.php:50`) — die Model-Methode ist aber öffentlich und die schwächere Regel wäre die letzte Verteidigungslinie. Widerspruch zweier Validierungsebenen. |
| **S-12** | `class/Controller/PasswordController.php:189` | `password_verify($pwd_peppered, $result['pwd'])` ohne vorherige Prüfung, ob `$result` überhaupt gefunden wurde (Z. 180). Bei unbekanntem Benutzernamen → Zugriff auf `null['pwd']` → Fatal Error. Zusätzlich wird der Benutzername aus dem **Request** genommen (Z. 171), nicht aus der Session — die Bindung an den eingeloggten Nutzer fehlt. |
| **S-13** | `class/Helper/ViewHelper.php:80` | `'<script>window.userRole = "' . $user_role . '";</script>'` — Interpolation in einen JS-String ohne Escaping. Aktuell ungefährlich, da `$user_role` aus `usertype.name` (Stammdaten) kommt; als Muster aber fragil. Direkt darüber wird bei `$user->getUsername()` korrekt `htmlspecialchars()` verwendet (Z. 58). |
| **S-14** | `class/Model/User.php` / `database.sql:49-51` | `user.username` hat **kein UNIQUE-Constraint** (`database.sql:49`). Die Eindeutigkeit hängt allein an `usernameExists()` (`User.php:240-253`) — zwischen Prüfung (`SignupController.php:56`) und Insert (Z. 62) liegt ein klassisches TOCTOU-Fenster. Da `login()` per `WHERE username = :username` sucht (`User.php:193`), führen Duplikate zu unvorhersehbarer Kontozuordnung. |
| **S-15** | `assets/html/index.html:8-29` | **Keine Subresource Integrity** bei 8 CDN-Einbindungen; zwei davon (`leaflet`, `leaflet-pip@latest`) ohne Versionspin (Details in Abschnitt 2.3). |
| **S-16** | `config/error_handler.php:7` | Das Logfile liegt mit `__DIR__ . '/../php-error.log'` **im Webroot**. Zusammen mit S-8 (2FA-Secrets) und den Passwort-Reset-Logs (`PasswordController.php:61,137,146`) ist das eine direkt abrufbare Sammlung sensibler Daten, sofern der Webserver `.log` nicht sperrt. |

### 9.4 Auskommentierte Blöcke

| Datei:Zeile | Inhalt |
|---|---|
| `class/Controller/LoginController.php:38-41` | Eingabelängen-Validierung (`username < 3 \|\| pwd < 8`) — deaktiviert. |
| `class/Controller/LoginController.php:65-74` | **E-Mail-Verifizierungspflicht beim Login** — deaktiviert, mit Begründung „Deaktiviert lassen solange kein eigener SMTP SERVER". Die gesamte Verifizierungslogik existiert damit ohne Wirkung. |
| `class/Controller/SignupController.php:64-72` | Versand der Verifikationsmail — deaktiviert (`//(new EmailVerificationController)::sendVerification($user_id);`, Z. 72). Ursache von H-2. Der Aufruf wäre auch syntaktisch falsch: `::` auf einer Instanz für eine Instanzmethode. |
| `class/Controller/SettingsController.php:43-51` | Anzeige des E-Mail-Bestätigungsstatus — deaktiviert. `$mailConfirmed` (Z. 39) wird berechnet und nie verwendet; `$mailConfirm` bleibt leer (Z. 41). |
| `assets/js/rtc.js:235` | `//document.getElementById('arrow-control').style.display = "";` |
| `assets/js/rtc.js:240-241` | Zwei Zeilen zum Ausblenden von Chat und Steuerkreuz. |
| `assets/html/index.html:15` | `<!--<link rel="stylesheet" href="assets/css/admin.css">-->` |
| `class/Model/Email.php:29` | `// $mail->SMTPDebug = SMTP::DEBUG_SERVER;` |
| `cron/check_online_status.php:24-25` | Cron-Logging. |

**TODO-/FIXME-/HACK-Kommentare: nicht vorhanden.** Eine Volltextsuche über
`assets/`, `class/`, `config/`, `cron/` und `index.php` liefert keine Treffer
(nur zwei Zufallstreffer in MP3-Binärdateien). Die offenen Punkte stehen
stattdessen als auskommentierte Blöcke mit deutschen Erklärtexten im Code.

### 9.5 Tote Pfade und ungenutzter Code

| Datei:Zeile | Befund |
|---|---|
| `class/Controller/SystemController.php:58-67` | `showStart()` — Route `start` (`routes.php:33`). Tot durch H-4 + H-5. |
| `config/routes.php:60-61` | `process_message`, `goto_chat` — tot durch H-1. |
| `assets/js/rtc.js:246-250` | `__hangup__`-Empfangspfad — kein Sender (F-2). |
| `assets/audio/look_up.mp3`, `look_down.mp3` | Eingebunden als `#look_up_sound`/`#look_down_sound` (`media.html:3-4`), aber **kein Protokollbefehl und keine UI** löst sie aus. |
| `assets/css/style.css` (368 Z.), `assets/css/1call.css` (407 Z.), `assets/css/dots.css` (38 Z.) | **Von keinem Template referenziert.** `1call.css` ist erkennbar eine ältere Fassung von `call.css` (366 Z.) — beide definieren `.arrow-controls` (`1call.css:195`, `call.css:26`; auch `style.css:341`). |
| `assets/css/admin.css` | Einbindung auskommentiert (`index.html:15`). |
| `class/Model/User.php:15` | Feld `$last_aktive` — **deklariert, nie gelesen, nie geschrieben.** Passend zur verwaisten Spalte `user.last_aktive` (`database.sql:56`). |
| `class/Model/User.php:374-385` | `get_user_info_as_array()` — von keiner Stelle aufgerufen. |
| `class/Model/Chat.php:164-176` | `checkIfActive()` — von keiner Stelle aufgerufen (`isActive()` wird verwendet). |
| `class/Model/WebRTCHandler.php:23-52` | Der Konstruktor mit ID-Ladefunktion wird **nie mit einer ID aufgerufen** — `WebRTCController` instanziiert nur `new WebRTCHandler()` (Z. 38, 54). Die geladenen Felder (`$id`, `$createt_at` …) sind unerreichbar; Getter gibt es ohnehin keine. |
| `class/Model/MeteredTurnService.php:10,15-18` | `$configPath` wird gesetzt und **nie verwendet**. |
| `class/Model/WebRTCHandler.php:16` | Feld `$createt_at` — Tippfehler (`created`), und nie gelesen. |
| `class/Controller/SettingsController.php:39` | `$mailConfirmed` berechnet, nie verwendet (s. 9.4). |
| `index.php:25` | `$pdo_instance` — die Variable wird nie benutzt; der Effekt (Aufbau der statischen Verbindung) tritt als Nebenwirkung des Konstruktors ein. Funktioniert, ist aber irreführend. |
| `index.php:37-40` | `if (empty($act))` ist **unerreichbar**: Die Regex-Prüfung in Z. 31 hat leere Strings bereits abgefangen und umgeleitet. |
| `assets/js/rtc.js:311-329` | `initFakeSelfCall()` — legt eine vollständige Wegwerf-PeerConnection samt zweitem `getUserMedia` an, nur als Chrome-Workaround. Diese PeerConnection wird in `startCall()` (Z. 112) direkt danach durch `createPeerConnection(true)` … das wegen Z. 154 (`if (localPeerConnection) return;`) **gar nicht mehr greift**. Der Call läuft also auf der „Fake"-Verbindung weiter. Fragiler, schwer nachvollziehbarer Pfad. |
| `class/Model/User.php:279-291` vs. `:358-368` | **Doppelte Logik:** `setUserStatus($status)` (nutzt `$this->id`) und `updateUserStatus($userId, $status)` (nimmt die ID als Parameter) machen exakt dasselbe UPDATE. Dazu kommt der dritte Weg über `setStatus()` + `save()` → `update()` (Z. 91-123), den `UserController::heartbeat()` (Z. 137-138) tatsächlich verwendet — dieser schreibt allerdings **alle** Benutzerfelder neu, um einen Statuswert zu setzen. |
| `class/Model/Location.php:175-194` vs. `:201-220` | **Doppelte Logik:** `selectAllLocations()` und `selectAllLocationsOfOneUser()` sind bis auf `!=` vs. `=` in der WHERE-Klausel und ein zusätzlich selektiertes `location.id` identisch. |
| `assets/js/chat.js:8-26` vs. `:33-46` | **Doppelte Logik:** `appendMsg()` und `appendToMobileChatLog()` enthalten denselben vierzeiligen Übersetzungsblock für die Pfeilsymbole (Z. 15-18 / Z. 37-40). |
| `assets/js/ui_rtc.js:23-47` + `:66-69` | `initChatUI()` wird **zweimal** registriert: einmal aus `main.js:14` und einmal aus einem eigenen `DOMContentLoaded`-Listener in `ui_rtc.js:66-69`. Die Event-Listener auf `#chat-send-btn` und `#chat-input` werden dadurch **doppelt gebunden** — jede Chatnachricht wird zweimal gesendet, sobald beide Pfade greifen. |

### 9.6 Fehlende Fehlerbehandlung

| Datei:Zeile | Befund |
|---|---|
| `class/Helper/ViewHelper.php:39` | `file_get_contents("assets/html/index.html")` **ohne** anschließendes `checkTemplate()` — obwohl die Klasse diesen Helfer selbst bereitstellt (Z. 19-24) und für die drei anderen Templates (Z. 66, 69, 72) korrekt anwendet. Ausgerechnet das Haupt-Layout bleibt ungeprüft. |
| projektweit | `file_get_contents()` auf Templates wird in **allen Controllern** ohne Prüfung verwendet: `LoginController.php:19,126`; `SignupController.php:22,97`; `PasswordController.php:21,66,85,91,112,143,158,191,200`; `EmailVerificationController.php:45,49,54,108`; `SettingsController.php:42`; `UserController.php:31,85,208`; `LocationController.php:19,112`; `ChatController.php:253,263,288`. Bei fehlender Datei liefert `file_get_contents` `false`; `str_replace` macht daraus einen leeren String → **leere Seite ohne Fehlermeldung**. |
| `assets/js/signaling.js:29-36` | `fetch(...).then(r => r.json())` **ohne `.catch()`** und ohne `r.ok`-Prüfung. Der Poll läuft im 1500-ms-Intervall; liefert der Server HTML (etwa die 500-Seite aus `error_handler.php:18`), wirft `r.json()` bei **jedem** Tick eine unbehandelte Promise-Rejection. |
| `assets/js/rtc.js:334-352` | `loadIceServers()` ohne `try/catch` (identisch zu F-18). |
| `assets/js/ui_rtc.js:54-62` | `getUsername()` fängt zwar `.catch(console.error)`, gibt dann aber `undefined` zurück. Der Aufrufer (`rtc.js:104`, `main.js:65`) schreibt das ungeprüft ins DOM → „Rufe undefined an". |
| `assets/js/location_prompt.js:21-24` | `fetch(...).then(...)` ohne `.catch()`; die Antwort wird nicht ausgewertet — es wird **immer** weitergeleitet, auch wenn das Speichern mit HTTP 400/500 fehlschlug (`UserController.php:186-195`). |
| `assets/js/signaling.js:134-141` | `sendHeartbeat()` ohne `.catch()` — bei Netzwerkfehler eine unbehandelte Rejection alle 15 s. |
| `class/Controller/WebRTCController.php:64-67` | Der `catch`-Block gibt `$e->getMessage()` **an den Client** aus. Interne Details (SQL-Fehler, Pfade) landen im Browser. |
| `class/Controller/LocationController.php:176` | Ebenso: `'error' => 'Fehler beim Löschen: ' . $e->getMessage()` im JSON. |
| `class/Controller/WebRTCController.php:25-63` | Ist weder die POST-Bedingung (Z. 29) noch die GET-Bedingung (Z. 52) erfüllt, endet die Methode **ohne jede Ausgabe** — HTTP 200 mit leerem Body. Der Client tut `r.json()` darauf (`signaling.js:30`) und wirft. |
| `class/Controller/LocationController.php:32-63` | Der `setLocation()`-Block läuft nur bei POST. Bei GET fällt die Methode **stumm durch** — weiße Seite. |
| `class/Controller/UserController.php:125-141`, `149-161` | Gleiches Muster: `heartbeat()` und `getUsername()` haben nur einen POST-Zweig ohne `else`. |
| `class/Controller/LocationController.php:59` | Der Rückgabewert von `setNewLocation()` wird **nicht geprüft**, obwohl die Methode bei Fehler `false` liefert (`Location.php:78,81`). Es wird unabhängig davon `?success=1` weitergeleitet — der Nutzer sieht „Lokation erfolgreich gespeichert!" (`main.js:392`), auch wenn nichts gespeichert wurde. |
| `class/Controller/ChatController.php:164-172` | `ChatMessage::add()` kann `null` liefern; der Rückgabewert wird ungeprüft dereferenziert (`$newMsg->getId()`). |
| `class/Model/PdoConnect.php:29-33` | Direkter Zugriff auf `$_ENV['DB_HOST']` usw. **ohne `isset`**. Fehlt die `.env`, greift der globale Error-Handler → HTTP 500 ohne verwertbaren Hinweis auf die eigentliche Ursache. |
| `config/env.php:5-6` | `$dotenv->load()` ohne `try/catch`. Fehlt die `.env`, wirft phpdotenv — beim Bootstrap, vor jedem Routing. |
| `class/Controller/PasswordController.php:180-189` | Kein Null-Check auf `$result` vor `$result['pwd']` (identisch zu S-12). |

---

## 10. LÜCKEN

Priorisiert nach Schwere. Die Begründungen beziehen sich auf die Befunde oben.

### Priorität 1 — Blocker: Die Anwendung ist im Auslieferungszustand nicht lauffähig

**1.1 `database.sql` passt nicht zum Code (Abschnitt 7.4)**
Sechs Abweichungen, davon vier, die Kernfunktionen abschalten: `user.user_status`
fehlt (Login und Online-Status brechen), `location.user_id` fehlt (**die Zuordnung
Standort → Guide, also das Kernkonzept, existiert im Schema nicht**), die Tabellen
`password_resets` und `email_verifications` fehlen komplett. Wer das Repo klont und
der README folgt, bekommt eine Anwendung, die sich nicht bedienen lässt. Das ist
vor allem anderen zu korrigieren, weil kein anderer Punkt ohne lauffähige DB
verifizierbar ist.

**1.2 Fatal Errors auf regulären Pfaden (Abschnitt 9.1)**
`MessageController` fehlt trotz zweier registrierter Routen (H-1); die
Registrierung bricht im Erfolgsfall auf einer undefinierten Variablen ab (H-2);
die Admin-Passwortänderung ruft eine nicht existierende Methode auf (H-3). Jeder
dieser drei Punkte ist ein HTTP 500 auf einem Weg, den ein normaler Nutzer geht.

**1.3 Die zentrale Nutzerfunktion ist unerreichbar (F-5, F-6)**
Ein Groß-/Kleinschreibungsfehler zwischen `usertype.name` (`Admin`, `Guide`, `User`,
`Trial`) und den im Code verglichenen Werten (`admin`, `guide`, `tourist`) blendet
den Button „Neue Lokation hinzufügen" für **alle** Rollen aus und verhindert den
automatischen Aufstieg zum Guide. Bei einer Plattform, deren Geschäftszweck das
Anbieten von Standorten ist, ist das ein vollständiger Funktionsausfall — und
zugleich der billigste Fix der Liste.

### Priorität 2 — Sicherheit: vor jedem öffentlichen Betrieb zu schließen

**2.1 Fehlende Autorisierung an fünf Endpunkten (S-1, S-2)**
`acceptChat`, `getMessages`, `setMessagesSeen`, `editLocationDesc`,
`deleteLocation` prüfen weder Login noch Eigentum. Jeder fremde Chatverlauf ist
mit einer geratenen ID auslesbar; jeder fremde Standort ist löschbar — bei
`deleteLocation` sogar per GET, also über ein eingebettetes Bild. Das sind
fortlaufende Integer-IDs, die Enumeration ist trivial. Besonders zu betonen: die
korrekte Prüfung existiert in derselben Klasse (`ChatController.php:281`,
`:202`) — sie wurde an den anderen Stellen schlicht vergessen.

**2.2 Zwei gespeicherte XSS-Lücken (S-3, S-4)**
Standortbeschreibungen (`locations_table.js:51`) und Chatnachrichten
(`ui_chat.js:262-274`) gehen ungefiltert ins DOM. Beide Vektoren sind von jedem
registrierten Nutzer erreichbar und persistent. In einer Anwendung, die Kamera-
und Mikrofonzugriff hält und Sessions ohne Ablauf führt, ist das schwerwiegend:
ausgeführtes Fremd-JavaScript kann die Session übernehmen und eigene Calls
initiieren.

**2.3 2FA-Secrets im Klartext im Webroot-Log (S-8, S-16)**
Acht `error_log`-Zeilen in `TwoFactorController.php` schreiben entschlüsselte
TOTP-Secrets nach `php-error.log`, das per `config/error_handler.php:7` **im
Webroot** liegt. Das hebt den Nutzen der 2FA vollständig auf, sobald jemand die
Datei abrufen kann. Die Zeilen sind reine Entwicklungs-Diagnostik und ersatzlos zu
entfernen; das Log gehört außerhalb des Webroots.

**2.4 Kein CSRF-Schutz (S-5)**
Kein Token in 21 Templates, keine Prüfung in 12 Controllern. `SameSite=Strict`
mildert das ab, deckt aber die per GET erreichbaren Löschaktionen
(`delete_user`, `delete_location`, `2fa_disable`) nicht sinnvoll ab. Zustandsändernde
Aktionen gehören auf POST, mit Token.

**2.5 Kryptografische Mängel (S-9, S-10, Abschnitt 8.3)**
Statischer, aus dem Schlüssel abgeleiteter IV bei AES-256-CBC; `PEPPER` in
Doppelnutzung als Passwort-Pepper **und** AES-Schlüssel (Rotation praktisch
unmöglich); kein `session_regenerate_id()` nach 2FA. Alles behebbar, ohne die
Architektur anzufassen.

**2.6 Ungebremster Signaling-Endpunkt (S-7)**
`getSignal` schreibt jedes Signal für jede beliebige `target`-ID in die DB, ohne
Existenz-, Beziehungs- oder Ratenprüfung. Ein Skript kann alle Nutzer mit
Anrufdialogen und Klingeltönen fluten und die `rtc_signal`-Tabelle füllen. Nötig
sind: Existenzprüfung des Ziels, Rate-Limit pro Absender, Größenbegrenzung für
SDP/Candidate.

**2.7 CDN-Abhängigkeiten ohne SRI, teils ohne Version (S-15)**
Acht externe Skripte/Stylesheets ohne `integrity`; `leaflet` und
`leaflet-pip@latest` zusätzlich ohne Versionspin. Ein Upstream-Release oder eine
CDN-Kompromittierung führt fremden Code in eine Seite mit Kamera- und
Mikrofonzugriff ein. Versionen pinnen, SRI ergänzen — oder lokal ausliefern.

### Priorität 3 — Betriebsfähigkeit der Kernfunktion

**3.1 Polling-Signaling skaliert nicht (Abschnitt 3.1)**
Jeder eingeloggte Nutzer erzeugt dauerhaft 0,67 DB-Anfragen/s allein für das
Signaling (`signaling.js:37`), plus Heartbeat alle 15 s, plus die Chat-Polls
(`ui_chat.js:324,383,427`). Bei 100 gleichzeitigen Nutzern sind das ~70 Queries/s
im Leerlauf. Gleichzeitig kostet das Polling im Mittel 750 ms zusätzliche Latenz
pro Signalisierungsschritt — bei drei bis vier Schritten bis zum Verbindungsaufbau
summiert sich das spürbar. Ein WebSocket-Signaling (Ratchet, oder ein separater
Node-Prozess) ist der eigentliche Fix; als Zwischenschritt hilft ein Index auf
`rtc_signal.created_at` und Long-Polling.

**3.2 Race Condition verliert Signale (F-1)**
`deleteSignalsForReceiver()` löscht **alle** Zeilen des Empfängers, nicht nur die
gelesenen. Zwischen SELECT und DELETE eintreffende ICE-Kandidaten gehen
ersatzlos verloren. Bei Trickle-ICE und 1500-ms-Takt ist dieses Fenster
regelmäßig belegt — das ist die plausibelste Ursache für sporadisch scheiternde
Verbindungen und schwer zu diagnostizieren, weil es ohne Fehlermeldung passiert.
Fix: nur die per ID gelesenen Zeilen löschen (oder Transaktion mit `FOR UPDATE`).

**3.3 Auflegen funktioniert im laufenden Call nicht (F-3)**
`dc.onopen` stoppt das Polling (`rtc.js:236`), `endCall()` sendet das `hangup`
aber genau darüber (Z. 19-22). Der Gegenüber erfährt vom Auflegen nie und sieht
stattdessen die generische Abbruchmeldung. Der passende Mechanismus liegt
ungenutzt bereit: der `__hangup__`-Empfangspfad über den DataChannel (F-2) hat
nur keinen Sender. Ein einzeiliger `dc.send("__hangup__")` in `endCall()` schließt
die Lücke.

**3.4 Kein ICE-Restart, aggressives Auflegen bei `disconnected` (Abschnitt 6.4)**
`disconnected` ist ein transienter Zustand, der bei jedem Netzwechsel auftritt und
sich meist von selbst erholt. Hier führt er zum sofortigen, endgültigen Abbruch —
in einer Anwendung, deren Guide sich **per Definition draußen bewegt und das Netz
wechselt**. Das ist funktional der wichtigste Punkt nach den Blockern: Nötig sind
ein Grace-Timer auf `disconnected`, `restartIce()` und ein Reconnect-Pfad; `failed`
und `closed` bleiben Abbruchgründe.

**3.5 Kein STUN-Fallback, `turns:` wird verworfen (Abschnitt 6.1, F-17, F-18)**
Fällt Metered aus oder ist das Kontingent erschöpft, gibt es **keinen einzigen**
ICE-Server — und im Client keine Fehlerbehandlung, die das erkennen würde (der
Fehlerzustand wird sogar dauerhaft gecacht). Zusätzlich filtert die Kürzungslogik
`turns:`-Einträge heraus, also ausgerechnet TURN über TLS/443. Nötig: statischer
STUN-Fallback, Fehlerbehandlung in `loadIceServers()`, `turns:` im Filter,
serverseitiges Caching der Credentials.

**3.6 Cron-Job hebt seine eigene Wirkung auf (F-16)**
`check_online_status.php:21` schreibt bei jedem Lauf alle bereits offline
stehenden Nutzer erneut; weil `updated_at` `ON UPDATE CURRENT_TIMESTAMP` ist,
setzt der eigene UPDATE den Zeitstempel zurück. Fix: `AND user_status != 'offline'`
ergänzen. Zudem sind 20 s Timeout bei 15 s Heartbeat-Takt zu eng — ein einziger
verzögerter Request setzt einen aktiven Nutzer offline.

### Priorität 4 — Das Steuerprotokoll trägt den Anwendungsfall nicht

Dieser Block ist der inhaltliche Kern der Bestandsaufnahme, siehe Abschnitt 4.

**4.1 Kein Rollenmodell im Call — beide Seiten können steuern (Abschnitt 5.2)**
Die Verbindung kennt keine Rollen. Der Guide kann dem Zuschauer Pfeilbefehle
schicken, und beide Steuerkreuze werden unbedingt gerendert
(`inner_call_controll.html:73-78`, `:86-92`). Für eine Plattform, deren Modell
„Zuschauer steuert Guide" lautet, fehlt damit die Grundunterscheidung. Nötig ist
eine explizite Rolle pro Verbindung (wer den Standort anbietet, ist Guide) und
darauf aufbauend: Steuerkreuz nur beim Zuschauer, Befehlsannahme nur beim Guide.

**4.2 Keine Validierung, unbekannte Befehle werden zu Chattext (Abschnitt 4.5/4.6)**
Sieben `===`-Vergleiche, danach ein Auffangzweig, der **alles** als Chatnachricht
anzeigt. Ein Tippfehler oder ein Befehl aus einer neueren Client-Version erscheint
stillschweigend als Text im Fenster des Guides — kein Log, keine Verwerfung, keine
Unterscheidbarkeit von echtem Chat. Nötig: Allowlist mit explizitem
`default`-Zweig, der unbekannte Befehle verwirft und protokolliert.

**4.3 Keine Protokollversionierung (Abschnitt 4.4)**
Kein Versionsfeld, keine Capability-Aushandlung, Channel-Label konstant `"chat"`.
Sobald ein Client aktualisiert wird und ein alter (etwa im Cache) noch läuft,
lässt sich das weder erkennen noch behandeln. Da das Protokoll ohnehin
überarbeitet werden muss (4.1/4.2/4.5), ist jetzt der günstigste Zeitpunkt, ein
Versionsfeld einzuführen.

**4.4 Steuerbefehle und Nutzdaten teilen einen ungetrennten Kanal (Abschnitt 4.1/4.2)**
Steuerung, Chat und Dateiübertragung laufen über denselben zuverlässigen,
geordneten DataChannel, unterschieden nur durch String-Vergleich. Eine größere
Dateiübertragung (`chat.js:64-72`, ohne Chunking und ohne Größenlimit) blockiert
damit die Steuerbefehle. Sinnvoll wären getrennte Kanäle — Steuerung
unzuverlässig/ungeordnet mit kurzer Lebensdauer, Chat und Dateien zuverlässig —
und ein JSON-Envelope statt Magic Strings.

**4.5 Keine Rückmeldung, kein Zustand, keine Parameter (Abschnitt 4.7)**
Ein empfangener Steuerbefehl spielt beim Guide **ausschließlich eine Audiodatei ab**
(`rtc.js:276-291`). Es gibt keine visuelle Anzeige, keine Bestätigung an den
Zuschauer, keine Angabe von Richtung, Dauer oder Intensität, keinen Stopp-Befehl.
Blick nach oben/unten ist als Asset vorbereitet (`media.html:3-4`), aber ohne
Befehl und ohne Button. Für ein Produkt, dessen Alleinstellungsmerkmal die
Fernsteuerung ist, ist das der dünnste Teil des Systems.

**4.6 Die README beschreibt eine Steuerung, die es nicht gibt (Abschnitt 4.7)**
„Zuschauer navigieren den Guide via **Pfeiltasten**" — ein Tastatur-Handler für
`ArrowUp`/`ArrowDown`/`ArrowLeft`/`ArrowRight` ist **nicht vorhanden**. Gesteuert
wird ausschließlich per Mausklick auf vier Buttons. Entweder ist die
Tastatursteuerung nachzurüsten (wenige Zeilen, sie würde die Bedienung deutlich
verbessern) oder die README zu korrigieren.

### Priorität 5 — Fehlende Grundlagen für den Produktivbetrieb

**5.1 Keine Tests**
Kein Test-Framework, kein Testverzeichnis, keine einzige Testdatei im Repo.
Bei einem System mit derart vielen Zustandsübergängen (Signaling, Call-Lifecycle,
2FA, Chat-Einladungen) bedeutet jede Änderung manuelles Durchklicken.

**5.2 Keine CI, kein Linting, kein Static Analysis**
Kein `.github/`, kein PHPStan/Psalm, kein PHP_CodeSniffer, kein ESLint. PHPStan
auf Level 5 hätte H-2 (undefinierte Variable), H-3 (fehlende Methode) und H-1
(fehlende Klasse) **allesamt** vor dem Commit gefunden.

**5.3 Keine Migrationen**
Schema nur als einmalige `database.sql`, die zudem nachträglich rekonstruiert
wurde und nicht passt (1.1). Ohne Migrationswerkzeug ist jede Schemaänderung ein
manueller, nicht nachvollziehbarer Eingriff.

**5.4 Kein strukturiertes Logging, keine Metriken**
Alles geht über `error_log()` in eine flache Datei im Webroot (S-16), gemischt mit
Debug-Ausgaben (S-8). Keine Log-Level, keine Rotation, keine Korrelation über
einen Call hinweg, keine `getStats()`-Auswertung der WebRTC-Verbindungen. Ein
Verbindungsproblem im Feld ist so praktisch nicht diagnostizierbar.

**5.5 Keine Deployment-Beschreibung**
Kein Dockerfile, keine `docker-compose.yml`, keine Webserver-Konfiguration. Die
README beschreibt die Installation, aber nichts davon ist reproduzierbar. Es fehlt
insbesondere die Konfiguration, die `php-error.log`, `.env`, `composer.json`,
`database.sql` und `cron/` gegen HTTP-Zugriff sperrt.

**5.6 Keine `.env.example`, kein PHP-Versions-Constraint**
Die Variablen sind in der README dokumentiert, eine Vorlagedatei fehlt.
`composer.json` hat keinen `require: {"php": ">=8.1"}`-Eintrag, obwohl der Code
PHP-8-Syntax nutzt (Abschnitt 2.2) — die Installation gelingt auf PHP 7.x und
scheitert erst zur Laufzeit.

**5.7 Keine Aufräumstrategie für `rtc_signal`**
Signale werden beim Abholen gelöscht (`WebRTCController.php:59`) — aber nur für
Empfänger, die tatsächlich pollen. Signale an Nutzer, die die Seite geschlossen
haben, bleiben **dauerhaft** in der Tabelle. Nötig: ein Cron-Job, der Zeilen älter
als 15 s löscht, sowie ein Index auf `created_at`.

**5.8 Doppelte Logik und tote Dateien (Abschnitt 9.5)**
Drei Wege, den Benutzerstatus zu setzen (`User.php:279-291`, `:358-368`, `save()`);
zwei fast identische Location-Abfragen; die Pfeil-Übersetzung an drei Stellen
dupliziert; `1call.css` als unreferenzierte Vorgängerversion von `call.css`; drei
CSS-Dateien ohne jede Einbindung. Dazu die doppelte Registrierung von
`initChatUI()` (`main.js:14` und `ui_rtc.js:66-69`), die Chat-Sendevorgänge
doppelt auslöst. Nichts davon ist dringend, aber jeder Punkt erhöht die
Wahrscheinlichkeit, dass eine Korrektur nur eine von mehreren Kopien trifft.

---

## Anhang: Positiv hervorzuheben

Bei aller Länge der Mängelliste — mehrere Dinge sind für ein Projekt dieser Art
überdurchschnittlich sauber gelöst und sollten bei einer Überarbeitung erhalten
bleiben:

- **Passwort-Hashing** (`User.php:509-519`): HMAC-Pepper aus ENV plus Argon2i.
- **Konsequente Prepared Statements** mit `ATTR_EMULATE_PREPARES = false`
  (`PdoConnect.php:41`). Ich habe **keine SQL-Injection** gefunden — in
  `cron/check_online_status.php:21` und `Chat.php:132,140` wird interpoliert, aber
  ausschließlich mit codeseitigen Konstanten.
- **Session-Cookie-Härtung**: `httponly` + `secure` + `SameSite=Strict`
  (`config/session.php:3-7`), dazu HTTPS-Erzwingung (`index.php:18-22`).
- **TURN-Credentials als Server-Proxy** (`TurnController.php`): Der Metered-API-Key
  verlässt den Server nie.
- **Konsistente Route-Validierung** (`index.php:31`): Whitelist-Regex vor dem
  Dispatch statt dynamischer Klassennamen aus dem Request.
- **Durchgängige, gute deutschsprachige Docblocks** in praktisch jeder Klasse und
  Methode — die Analyse war dadurch deutlich schneller, als es der Zustand des
  Codes sonst erlaubt hätte.
- **`htmlspecialchars()` wird serverseitig konsequent verwendet**
  (`ViewHelper.php:58`, `UserController.php:42,65,66,221,237`,
  `ChatController.php:256,295-297`, `SystemController.php:40`). Die beiden
  XSS-Lücken (S-3, S-4) entstehen ausschließlich im clientseitigen Rendering.
