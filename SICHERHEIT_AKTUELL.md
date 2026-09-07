# Sicherheitsbericht — Stand vor dem öffentlichen Betrieb

**Datum:** 7. September 2026
**Geprüfter Stand:** Branch `feat/bewertungen`, Commit `9c9859c`
**Anlass:** Die Anwendung soll öffentlich erreichbar werden.
**Art der Prüfung:** Statische Durchsicht des gesamten Quellstandes (PHP, JavaScript,
SQL, Konfiguration, Deployment-Dateien). Kein Penetrationstest, keine laufende
Instanz, keine Prüfung der Serverkonfiguration.

---

## 0. Ergebnis in einem Satz

**Die Anwendung ist in ihrer Fachlogik heute deutlich robuster als zum Zeitpunkt der
Bestandsaufnahme — alle vier Autorisierungslücken und beide XSS-Lücken von damals sind
geschlossen, und die neu gebauten Bereiche (Uploads, Anfragen, Führungen, Bewertungen)
sind sauber abgesichert. Was fehlt, ist fast ausschließlich das, was ein
*öffentlicher* Betrieb zusätzlich verlangt: CSRF-Schutz, Missbrauchsbremsen,
Sicherheitskopfzeilen, verifizierte Konten und die rechtlichen Pflichtseiten.**

| | Zahl |
|---|---|
| Alte Befunde (S-1…S-16) geschlossen | 10 |
| Alte Befunde offen oder teiloffen | 6 |
| Neue Befunde in dieser Prüfung | 17 |
| **Davon zwingend vor dem Livegang** | **11** |

Eine Freigabeempfehlung steht in **Abschnitt 7**.

---

## 1. Was seit der Bestandsaufnahme erledigt ist

Diese Punkte wurden nachgeprüft und sind **geschlossen**. Sie stehen hier, damit der
Bericht nicht den falschen Eindruck erweckt, es sei nichts passiert.

| ID | Befund von damals | Heutiger Stand |
|---|---|---|
| **S-1** | IDOR im Chat: `acceptChat`, `getMessages`, `setMessagesSeen` ohne Beteiligungsprüfung | **Geschlossen.** Alle drei prüfen die Beteiligung (`ChatController.php:113`, `:191`, `:346`) und antworten für „gibt es nicht" und „geht dich nichts an" gleich. |
| **S-2** | IDOR am Standort: fremde Standorte löschbar, per GET | **Geschlossen.** Das Eigentum steht in der WHERE-Klausel (`Location::deleteLocation`), `editLocationDesc` ist ersatzlos entfallen. Die GET-Erreichbarkeit besteht fort → siehe **N-1**. |
| **S-3** | Gespeichertes XSS in der Standortliste | **Geschlossen.** `locations_table.js` maskiert durchgängig über `this.esc()`; der Server liefert den Benutzernamen fremder Konten gar nicht mehr aus. |
| **S-4** | Gespeichertes XSS im Chat (`cleanMsg`) | **Geschlossen.** `ui_chat.js:435` — `this.esc(msg.msg).replace(/\n/g,'<br>')`. Der Name täuscht nicht mehr. |
| **S-6** | `listUser()` ohne `exit` nach Guard | **Geschlossen.** Die Rechteprüfung liegt jetzt zentral in `index.php:100-124`, vor dem Controller. |
| **S-8** | TOTP-Secrets im Klartext im Log | **Geschlossen.** `TwoFactorController.php:105-111`, `:201-205` loggen nur noch „vorhanden/fehlt". |
| **S-10** | Kein `session_regenerate_id()` nach 2FA | **Geschlossen.** `TwoFactorController.php:217`. |
| **S-12** | Passwortwechsel: Benutzername aus dem Request, Null-Zugriff | **Geschlossen.** `PasswordController.php:226` nimmt `Auth::userId()`, `:244` den Namen aus dem geladenen Datensatz, `:256` prüft `!$result` vor `password_verify`. |
| **S-13** | `window.userRole` unmaskiert in einen JS-String | **Geschlossen.** `ViewHelper.php` setzt alle Frontend-Variablen über `json_encode`. |
| **S-16** | Fehlerlog im Webroot | **Geschlossen.** `config/log_path.php` legt es oberhalb des Webroots ab, `LOG_PATH` überschreibt. **Aber:** die Altlast `<Webroot>/php-error.log` wird bewusst nicht automatisch gelöscht → siehe **N-9**. |

Ebenfalls erledigt, aus den Prioritätslisten: die Race Condition beim Signal-Löschen
(F-1), das nie ankommende Auflegen (F-2/F-3), der fehlende ICE-Restart, der
STUN-Fallback und das `turns:`-Filterproblem, der sich selbst aufhebende Cronjob
(F-16). Neu hinzugekommen und positiv zu vermerken:

* **Eine zentrale, vollständig geprüfte Rechtetabelle.** `index.php:60-68` prüft bei
  *jedem* Aufruf die **gesamte** Routentabelle und verweigert den Dienst, wenn eine
  Route kein oder ein unbekanntes Recht trägt. Eine vergessene Rechteangabe kann
  keine offene Route hinterlassen. Das ist die stärkste strukturelle Verbesserung
  im ganzen Projekt.
* **Zuständigkeit konsequent in der WHERE-Klausel.** Nachgeprüft für Standorte,
  Bilder, Chats, Anfragen, Führungen und Bewertungen — überall steht die
  Eigentums- oder Beteiligungsbedingung im Statement und nicht nur davor.
* **Durchgehend Prepared Statements**, `PDO::ATTR_EMULATE_PREPARES => false`
  (`PdoConnect.php:40`). **Keine SQL-Injection gefunden.** Die wenigen als Text
  eingesetzten Werte (LIMIT, INTERVAL, Tabellenaliase, ID-Listen) sind vorher auf
  `int` gecastet bzw. durch `preg_replace` gefiltert.

---

## 2. Alte Befunde, die noch offen sind

### S-5 — Kein CSRF-Schutz · **Kritisch · vor Livegang**
**Datei:** projektweit. Keine Zeile mit einem Token in 24 Templates, keine Prüfung in
16 Controllern (verifiziert per Volltextsuche).

Die einzige Verteidigung ist heute `SameSite=Strict` am Sitzungscookie
(`config/session.php:6`). Das ist wirksam, aber es ist *eine* Zeile, deren Wirkung
vollständig vom Browser des Opfers abhängt, und sie deckt Angriffe von einer
Subdomain derselben Site nicht ab. Verschärft wird das dadurch, dass zustandsändernde
Aktionen weiterhin per GET erreichbar sind (**N-1**).

**Empfehlung:** Ein Token je Sitzung, als verstecktes Feld in jedem POST-Formular und
als Kopfzeile bei jedem `fetch`; Prüfung zentral in `index.php` für alle Routen, die
nicht ausdrücklich lesend sind. Zentral geprüft, nicht je Controller — sonst wird es
genau einmal vergessen, und das ist die Klasse Fehler, die dieses Projekt schon
zweimal hatte.

---

### S-7 — Signaling ohne Größen- und Ratenbegrenzung · **Hoch · vor Livegang**
**Datei:** `class/Controller/WebRTCController.php:57-79`

Die *Beziehungsprüfung* ist inzwischen vorbildlich (`callAllowed`/`callRoles`,
`:311-350`): Ein Offer erreicht sein Ziel nur, wenn eine laufende Führung, eine Zusage
oder eine eingeschaltete Bereitschaft es rechtfertigt. Zwei Teile des Befunds sind
aber offen:

1. **Keine Größenbegrenzung** für `sdp` und `candidate` (`:66-68`, gespeichert in
   `WebRTCHandler::create`). Ein Client kann beliebig große Zeichenketten in
   `rtc_signal` schreiben.
2. **Keine Ratenbegrenzung.** Ein angemeldetes Konto kann jeden Guide, der gerade
   auf „bereit" steht, beliebig oft anklingeln — mit Dialog und Klingelton. Die
   Bereitschaft ist eine öffentliche Angabe (grüne Nadel auf der Karte), das Ziel
   also trivial zu finden.
3. **`type` wird nicht gegen eine Liste geprüft** (`:64`). Beliebige Zeichenketten
   landen in der Spalte. Der Client ignoriert Unbekanntes, der Schaden bleibt bei
   Datenmüll — es ist trotzdem eine fehlende Eingabeprüfung an einer Stelle, an der
   sie billig wäre.

---

### S-9 — Statischer IV, PEPPER in Doppelnutzung · **Mittel · vor Livegang**
**Datei:** `class/Controller/TwoFactorController.php:256-264`

```php
$key = $_ENV['PEPPER'];
return openssl_encrypt($secret, 'aes-256-cbc', $key, 0, substr($key, 0, 16));
```

Unverändert seit der Bestandsaufnahme. Zwei Probleme in drei Zeilen:

* **Der IV ist aus dem Schlüssel abgeleitet und damit für alle Datensätze gleich.**
  Gleiche Klartexte ergeben gleiche Chiffrate. Bei TOTP-Secrets ist der praktische
  Schaden gering (sie sind zufällig und unterschiedlich), das Muster ist trotzdem
  falsch.
* **`PEPPER` ist gleichzeitig Passwort-Pepper und AES-Schlüssel.** Damit ist eine
  Rotation praktisch unmöglich: Wer den Pepper wechselt, macht alle Passwörter
  *und* alle 2FA-Secrets gleichzeitig unbrauchbar.

**Empfehlung:** Zufälliger IV je Datensatz, vor das Chiffrat gestellt; ein eigener
Schlüssel `TOTP_KEY` in der `.env`. Beides ist rückwärtskompatibel machbar (beim Lesen
beide Formen akzeptieren).

---

### S-11 — Zwei Passwortregeln, die schwächere ist die letzte · **Mittel · vor Livegang**
**Datei:** `class/Model/User.php:246-249` — `if (strlen($in_pwd) < 3)`

Der Controller prüft auf 8 Zeichen (`SignupController.php:50`), das Modell auf 3. Die
Modellmethode ist öffentlich und die letzte Verteidigungslinie. Solange nur der
Signup-Controller sie aufruft, ist nichts passiert — aber genau das ist die Annahme,
die beim nächsten Aufrufer nicht mehr stimmt.

---

### S-14 — `user.username` ohne UNIQUE · **Mittel · vor Livegang**
**Datei:** `database.sql:437-440` (nur `UNIQUE KEY email`), keine Migration ergänzt es.

Die Eindeutigkeit hängt allein an `usernameExists()`. Zwischen Prüfung
(`SignupController.php:56`) und Insert (`:63`) liegt ein TOCTOU-Fenster. Da
`User::login()` mit `WHERE username = :username` sucht (`User.php:274`) und `fetch()`
die *erste* Zeile nimmt, führen Duplikate zu einer nicht vorhersehbaren
Kontozuordnung. Bei öffentlicher Registrierung ist das Fenster gezielt treffbar.

**Empfehlung:** `ALTER TABLE user ADD UNIQUE KEY username (username)` als Migration —
vorher auf bestehende Duplikate prüfen.

---

### S-15 — CDN ohne SRI, zwei ohne Versionspin · **Hoch · vor Livegang**
**Datei:** `assets/html/index.html:13-52` — elf externe Ressourcen von fünf Anbietern
(googleapis, unpkg, jsdelivr, datatables), **keine einzige mit `integrity`**.

Zwei davon ohne Versionsangabe:
```html
<script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-pip@latest/leaflet-pip.min.js"></script>
```

Das ist der schwerwiegendste Befund, der *nicht* im eigenen Code liegt: Ein
Upstream-Release oder eine kompromittierte CDN führt fremdes JavaScript in eine Seite
ein, die Kamera- und Mikrofonzugriff hält und eine angemeldete Sitzung führt. Bei
`@latest` genügt dafür ein ganz normaler Release des Paketbetreibers.

**Empfehlung:** Versionen pinnen, `integrity` + `crossorigin` ergänzen — oder, besser
für eine Anwendung dieser Art, die Bibliotheken lokal ausliefern. Dann entfällt auch
die Frage, welche Daten (Referrer, IP) bei jedem Seitenaufruf an fünf Drittanbieter
gehen — siehe **N-16**.

---

## 3. Neue Bereiche seit der Bestandsaufnahme

### 3.1 Datei-Uploads (Standortbilder, Avatare)

**Gesamturteil: gut gebaut.** Das ist der am sorgfältigsten abgesicherte neue Bereich.
Nachgeprüft und in Ordnung:

| Frage | Antwort | Fundstelle |
|---|---|---|
| Typprüfung aus dem Inhalt? | Ja — `getimagesize()`, nicht Endung und nicht der gemeldete Content-Type | `ImageStore.php:375-383` |
| Wirklich ein Upload? | Ja — `is_uploaded_file()` | `ImageStore.php:362` |
| Größenbegrenzung? | Ja — 8 MB, zusätzlich zu `upload_max_filesize` | `ImageStore.php:369-374` |
| Dekompressionsbombe? | Ja — max. 6000 px Kantenlänge, **vor** dem Laden ins GD | `ImageStore.php:385-391` |
| Dateiname vom Nutzer? | Nein — 32 zufällige Hexzeichen, Originalname wird verworfen | `ImageStore.php:427` |
| Pfad-Traversal? | Nein — `isValidName()` prüft `^[0-9a-f]{32}$` vor jedem Pfadbau | `ImageStore.php:196-199`, `:209`, `:230` |
| Ablage im Webroot? | Nein — `../uploads` oberhalb, per `UPLOAD_PATH` konfigurierbar | `config/uploads.php:60-73` |
| Ausgeführt werden kann nichts? | Richtig — es wird ausschließlich JPEG geschrieben, Auslieferung mit `X-Content-Type-Options: nosniff` und festem `Content-Type` | `LocationController.php:1023-1032` |
| Wer darf löschen? | Nur der Eigentümer, geprüft **in der WHERE-Klausel** | `LocationImage.php:253-255`, `:315-317`, `:379-383`, `:458-463` |
| Gesperrte Standorte? | Bilder werden nur Eigentümer und Moderation ausgeliefert; `Cache-Control: private` | `LocationController.php:997-1001`, `:1028` |

Offene Punkte: **N-3** (kein Speicherlimit je Konto), **N-4** (Arbeitsspeicher),
**N-5** (TOCTOU beim Bilderlimit), **N-1** (GET-Erreichbarkeit von `delete_location_image`).

---

### 3.2 Anfragen und Führungen

**Kann jemand fremde Anfragen sehen, annehmen, beenden?** — Nachgeprüft: **nein.**

| Route | Zuständigkeit | Fundstelle |
|---|---|---|
| `get_requests` | Nur eigene Listen, `Auth::userId()` als einziger Filter | `RequestController.php:275-281` |
| `request_accept` / `_decline` | `WHERE guide_user_id = :guide` **und** Ablaufprüfung im Statement | `TourRequest.php:702-717` → `antwort()` `:727-765` |
| `request_cancel` | `WHERE (customer_user_id = :user OR guide_user_id = :user)` und `started_at IS NULL` | `TourRequest.php:771-810` |
| `request_finish` | `WHERE guide_user_id = :guide AND closed_at IS NULL` | `TourRequest.php:978-1000` |
| `request_create` | Der Guide kommt **aus dem Standort**, nicht aus der Anfrage | `RequestController.php:99-108` |

Die Rollenvergabe im Call (`WebRTCController::callRoles`) ist der sicherheitskritischste
Teil und wurde besonders geprüft. Die im letzten Commit ergänzte Regel „bei laufender
Führung entscheidet deren Zeile" (`:315-338`) weicht sie **nicht** auf: `guide_user_id`
wurde beim Anlegen aus dem Standort übernommen, die Führung muss begonnen haben, und
die Zeile gilt nur, solange sie läuft. Niemand bekommt dort eine Rolle, die er nicht
schon hatte. Bewertung: **korrekt**.

Offener Punkt: **N-6** (Anfragen-Spam ohne Bremse).

---

### 3.3 Bewertungen

**Kann jemand mehrfach bewerten, fremde Führungen bewerten, Sterne manipulieren?**
— Nachgeprüft: **nein**, mit einer Einschränkung (N-7).

* **Mehrfach:** `UNIQUE KEY eine_je_fuehrung (request_id)`
  (`migrations/016_bewertungen.sql:132`). Die Regel steht in der **Tabelle**, nicht nur
  im Controller — auch zwei gleichzeitige Anfragen ergeben nur eine Zeile. Eine
  entfernte Bewertung gibt den Platz bewusst nicht frei.
* **Fremde Führungen:** Geschrieben wird mit `INSERT ... SELECT` **aus**
  `tour_request` (`TourReview.php:211-222`). Guide, Kunde und Standort kommen aus der
  Aufzeichnung; der Browser steuert nur Sterne und Text bei. Die Bedingung
  `customer_user_id = :customer AND conductedSql(...)` steht im Statement.
* **Sterne:** `isValidStars()` (`TourReview.php:141-152`) weist Werte außerhalb 1–5
  ab und **auch nicht-ganzzahlige** — `(int)` allein hätte aus 3,5 klaglos eine 3
  gemacht. Der Wert wird als `PDO::PARAM_INT` gebunden.
* **Anzeige:** Der Text geht durch `ViewHelper::esc()` (`ReviewView.php:344`), das
  neben `htmlspecialchars` auch die drei Rauten des Platzhaltersystems entschärft.
  Stichprobe mit `<script>` und `###USER###` im Test bestanden (`server_test.php`,
  Abschnitt 35).
* **Entfernen:** Nur `review.remove`, das ausschließlich der Admin hat
  (`Permission.php:578`). Der Guide hat es ausdrücklich nicht.

Offener Punkt: **N-7** (Selbstbewertung über ein Zweitkonto).

---

### 3.4 Guide-Profile — was ist öffentlich sichtbar?

Öffentlich (auch für Gäste, Recht `guide.view`):

| Angabe | Bewertung |
|---|---|
| Anzeigename, Selbstbeschreibung, Sprachen, Bild | Gewollt. Der Guide gibt sie selbst an, sie sind der Zweck der Seite. |
| „Guide seit **Monat Jahr**" | Bewusst ohne Tag (`GuideView.php:283-307`). Angemessen. |
| Angebotene Standorte, Bewertungen | Gewollt. |
| **Benutzername als Rückfall, wenn kein Anzeigename gesetzt ist** | `GuideProfile::anzeigename()`. Dokumentierte Entscheidung, aber sie steht im Widerspruch zur ebenfalls dokumentierten Regel „der Benutzername ist die Anmeldekennung und geht Fremde nichts an". → **N-14** |

Nicht öffentlich und korrekt geschützt: E-Mail-Adresse, Rolle, `user_status`,
Koordinaten von Personen (die Karte zeigt Standorte, keine Nutzer), die
`user_id` auf der Standortseite für Gäste (`LocationController.php:826`).

Weitere Punkte: **N-8** (Benutzernamen-Enumeration über `get_username`), **N-13**
(Standorte gelöschter Konten bleiben sichtbar).

---

## 4. Die drei Fehlerklassen dieses Projekts — systematisch geprüft

### 4.1 Fehlende Beteiligungsprüfung (IDOR)

Alle 40 Routen aus `config/routes.php` durchgegangen. **Kein offener Fall gefunden.**
Jede Route, die einen fremden Datensatz adressieren könnte, prüft die Zuständigkeit
im Statement:

| Bereich | Prüfung im SQL |
|---|---|
| Standorte | `AND location.user_id = :user_id` |
| Bilder | `JOIN location ... AND location.user_id = :user_id` |
| Chats | `getUser1Id()/getUser2Id()` bzw. `getPendingFor()` gegen `Auth::userId()` |
| Anfragen | `guide_user_id` / `customer_user_id` im WHERE |
| Bewertungen | `customer_user_id` im INSERT-SELECT |
| Guide-Profil | Immer `Auth::userId()`, nie eine ID aus der Anfrage |

Zwei Routen adressieren bewusst fremde Datensätze und **haben keine
Beziehungsprüfung**, weil sie nach heutigem Entwurf keine brauchen — beide sind
trotzdem Missbrauchsflächen: `get_username` (**N-8**) und `chat_start` (**N-12**).

### 4.2 Kennung aus der Anfrage statt aus der Session

Geprüft für alle Controller. Ergebnis:

* **Korrekt** (Kennung aus der Session): Passwortwechsel, Farbprofil, Guide-Profil
  speichern und löschen, Bereitschaft, Heartbeat, Anfragen, Bewertungen, Führung
  beenden, Chat.
* **Bewusst aus der Anfrage, mit Prüfung**: Standort-/Bild-IDs (Eigentum im SQL),
  Anfrage-IDs (Beteiligung im SQL), `guide`-Profilseite (nur öffentliche Felder).
* **Aus der Anfrage, ohne Prüfung**: `get_username` (**N-8**), `chat_start`
  (**N-12**). Bei `deleteUser` (`UserController.php:159`) kommt die Ziel-ID aus der
  Anfrage — das ist bei einer Adminfunktion richtig; geprüft wird nur, dass es nicht
  das eigene Konto ist. Fehlend: eine Rückfrage und POST (**N-1**).

### 4.3 Unmaskierte Ausgabe

Serverseitig läuft praktisch alles über `ViewHelper::esc()`, das zusätzlich zu
`htmlspecialchars(ENT_QUOTES)` die `###`-Platzhalter entschärft — das ist die
Besonderheit dieses Bauverfahrens und richtig gelöst.

**Ein Fund:** `SettingsController.php:94-95`

```php
$out = str_replace('###USERNAME###', $user->getUsername(), $out);
$out = str_replace('###EMAIL###',    $user->getEmail(),    $out);
```

Beide ohne Maskierung. Der Benutzername ist durch `^[\w]{3,20}$` unbedenklich. Die
**E-Mail-Adresse ist es nicht**: `FILTER_VALIDATE_EMAIL` lässt Quoted-Local-Parts zu,
und darin sind `<` und `>` erlaubt. Eine Adresse der Form `"<img src=x
onerror=…>"@example.com` passiert die Registrierung und landet unmaskiert auf der
Kontoseite. → **N-11**

Clientseitig: `requests.js`, `review.js`, `tour.js`, `home_map.js`,
`locations_table.js`, `ui_chat.js` und `location_page.js` maskieren durchgehend über
eine eigene `esc()`. Volltextsuche nach unmaskierter Interpolation
(`${item.…}`, `${z.…}`, `${msg.…}`) ergab **keinen Treffer** mit Nutzerdaten.

---

## 5. Neue Befunde

### Kritisch — vor dem Livegang zu schließen

---

**N-1 · Zustandsändernde Aktionen per GET erreichbar**
**Dateien:** `UserController.php:157` (`delete_user`), `TwoFactorController.php:234`
(`2fa_disable`), `SettingsController.php:221` (`set_theme`), `LocationController.php:664`
(`delete_location`), `:1240` (`delete_location_image`), `:1272` (`sort_location_images`),
`:1311` (`set_location_cover`), `:1338` (`unset_location_cover`),
`EmailVerificationController.php` (`send_email_verify`), `TwoFactorController.php`
(`2fa_activate`), `LoginController` (`logout`)

Elf zustandsändernde Routen prüfen `$_SERVER['REQUEST_METHOD']` nicht. Zusammen mit
dem fehlenden CSRF-Schutz (S-5) hängt der Schutz **ausschließlich** an
`SameSite=Strict`. Besonders unangenehm: `delete_user` löscht ein fremdes Konto und
`2fa_disable` schaltet den zweiten Faktor ab — beide ohne Rückfrage und ohne erneute
Passworteingabe.

**Empfehlung:** POST erzwingen (der Anwendung ist das Muster bereits geläufig — elf
andere Methoden tun es), plus CSRF-Token, plus Passwortabfrage vor `2fa_disable`.

---

**N-2 · Login-Bremse ist wirkungslos, weil sie in der Session steht**
**Datei:** `class/Controller/LoginController.php:44-58`, `:96-108`

```php
if (!isset($_SESSION['login_attempts'])) { $_SESSION['login_attempts'] = []; }
```

Zähler und Sperre liegen in `$_SESSION`, also am **Cookie des Angreifers**. Wer bei
jedem Versuch das Cookie verwirft — jedes Skript tut das von selbst —, hat **keinerlei
Begrenzung**. Der Schutz wirkt gegen den Nutzer, der sich vertippt, und gegen
niemanden sonst.

**Empfehlung:** Serverseitiger Zähler je Benutzername **und** je IP, in einer Tabelle
oder in Redis/APCu, mit ansteigender Verzögerung. Öffentlich erreichbar heißt: das ist
innerhalb von Stunden nach dem Livegang relevant, nicht irgendwann.

---

**N-3 · Kein 2FA-Ratelimit — der zweite Faktor ist durchprobierbar**
**Datei:** `class/Controller/TwoFactorController.php:185-228`

`handle2FAVerify()` zählt Fehlversuche **nicht**. `$_SESSION['2fa_userid']` bleibt
nach einem falschen Code stehen, es gibt keine Sperre, keine Verzögerung, keinen
Abbruch. Ein sechsstelliger TOTP-Code hat 10⁶ Möglichkeiten; das Zeitfenster beträgt
30 Sekunden, die Standardtoleranz von `TOTP::verify()` einen Schritt. Bei einigen
hundert Anfragen pro Sekunde ist das keine theoretische Rechnung.

**Das hebt die 2FA für ein gezielt angegriffenes Konto auf** — sobald das Passwort
bekannt ist, was ohne N-2 zusätzlich erleichtert wird.

**Empfehlung:** Höchstens 5 Versuche je `2fa_userid`, danach die 2FA-Sitzung
verwerfen und zum Login zurück. Serverseitig zählen, aus demselben Grund wie N-2.

---

**N-4 · Registrierung ohne jede Verifikation und ohne Bremse**
**Dateien:** `SignupController.php:63-80` (Mailversand auskommentiert),
`LoginController.php:65-73` (Prüfung auf `email_verified` auskommentiert)

Beide Blöcke sind mit dem Hinweis „solange kein eigener SMTP-Server" deaktiviert. Für
eine lokale Entwicklungsinstanz ist das nachvollziehbar; öffentlich heißt es:

* Konten mit **beliebigen, fremden** E-Mail-Adressen sind anlegbar.
* Kein Captcha, kein Ratelimit → automatisierte Massenregistrierung.
* Jedes so angelegte Konto darf sofort Standorte anfragen, chatten, Guides
  anklingeln, Bewertungen abgeben und (als Guide) Bilder hochladen.

Das ist der **Hebel unter den meisten anderen Befunden**: N-6 (Anfragen-Spam), N-7
(Selbstbewertung), N-12 (Chat-Spam) und N-3 (Speicherverbrauch) setzen alle voraus,
dass Konten billig sind.

**Empfehlung:** SMTP einrichten, beide Blöcke reaktivieren, Registrierung
ratenbegrenzen. Ohne bestätigte Adresse kein Anfragen, kein Chat, kein Upload.

---

**N-5 · Keine Sicherheitskopfzeilen**
**Datei:** projektweit — gesetzt werden nur `X-Content-Type-Options` an den beiden
Bildrouten (`LocationController.php:1031`, `GuideProfileController.php:177`).

Es fehlen:

| Kopfzeile | Warum sie hier zählt |
|---|---|
| `Content-Security-Policy` | Die zweite Verteidigungslinie gegen XSS. Ohne sie hängt alles am Escaping. Bei einer Anwendung mit Kamera-/Mikrofonzugriff ist das zu wenig. |
| `X-Frame-Options` / `frame-ancestors` | **Clickjacking.** Die Seite lässt sich in einen fremden Rahmen legen; der „Führung starten"-Knopf startet einen Anruf mit Kamerafreigabe. |
| `Strict-Transport-Security` | Der HTTPS-Zwang in `index.php:20-24` greift erst *nach* dem ersten unverschlüsselten Request. |
| `Referrer-Policy` | Sonst gehen Standort- und Profil-URLs samt IDs an fünf CDN-Anbieter. |
| `Permissions-Policy` | Kamera/Mikrofon auf die eigene Herkunft beschränken. |

**Empfehlung:** In der Serverkonfiguration setzen, nicht in PHP — dann gelten sie auch
für statische Dateien. Die CSP wird wegen der CDN-Einbindungen (S-15) unangenehm
weit; das ist ein weiteres Argument, die Bibliotheken lokal auszuliefern.

---

**N-6 · Kein Impressum, keine Datenschutzerklärung**
**Datei:** `assets/html/index.html:182-184`

```html
<a href="#">Impressum</a>
<a href="#">Datenschutz</a>
<a href="#">Kontakt</a>
```

Drei Verweise ins Leere. Für ein öffentlich erreichbares deutsches Angebot ist beides
Pflicht (Impressum nach § 5 DDG, Informationspflichten nach Art. 13 DSGVO) und
abmahnfähig. Details zum Inhalt in Abschnitt 6.2.

---

### Hoch

---

**N-7 · Unbegrenzter Speicherverbrauch je Konto**
**Datei:** `LocationController.php:365` (`setNewLocation`), `config/uploads.php:88`

Ein Standort darf **5 Bilder** tragen — das ist geprüft. Wie viele **Standorte** ein
Konto anlegen darf, ist **nirgends begrenzt**. Ein Guide-Konto kann damit beliebig
viele Standorte × 5 Bilder × je zwei Dateien anlegen. Zusammen mit N-4 (Konten sind
kostenlos und unbegrenzt) ist die Platte das Limit.

**Empfehlung:** Obergrenze je Konto — der Weg dorthin ist schon gebaut
(`ImageStore::maxImages($user_id)` als einzige Lesestelle, mit genau dieser Absicht
dokumentiert). Eine entsprechende `maxLocations($user_id)` wäre die passende
Ergänzung. Zusätzlich ein Gesamt-Byte-Kontingent.

---

**N-8 · Bildverarbeitung kann den PHP-Prozess sprengen**
**Datei:** `config/uploads.php:111` — `'max_source_edge' => 6000`

Die Grenze verhindert die klassische Dekompressionsbombe. Sie ist aber immer noch
großzügig: 6000 × 6000 Punkte × 4 Byte ≈ **144 MB** allein für das GD-Bild in
`imagecreatefromjpeg()` (`ImageStore.php:391-395` (`readImage`)). Bei einem üblichen
`memory_limit = 128M` bringt bereits **ein einzelnes** legitimes Bild an der
Obergrenze den Prozess um; bei 256 MB genügen zwei parallele Uploads.

Kein Sicherheitsloch, aber ein sehr billiger Denial-of-Service.

**Empfehlung:** `max_source_edge` auf 4000 senken (≈ 64 MB) **oder** `memory_limit`
für die Upload-Route hochsetzen und die Rechnung im Kommentar festhalten. Zusätzlich
begrenzen, wie viele Uploads ein Konto gleichzeitig laufen lassen darf.

---

**N-9 · Altlast `php-error.log` im Webroot**
**Datei:** `config/log_path.php:29-33` (dokumentiert, bewusst nicht automatisiert)

Das Logziel liegt heute richtig. Aus früheren Versionen kann aber noch eine Datei
`<Webroot>/php-error.log` existieren — **mit TOTP-Secrets und Reset-Tokens im
Klartext** (das war S-8/S-16). Die `.htaccess:17` sperrt `*.log`, aber nur unter
Apache und nur mit `AllowOverride`.

**Empfehlung:** Vor dem Livegang auf dem Zielsystem prüfen und löschen. Ein Punkt auf
der Deployment-Checkliste, kein Codefehler — aber einer mit dem größten Schaden pro
Aufwand.

---

**N-10 · Keine Ratenbegrenzung an sechs weiteren Endpunkten**
**Dateien:** `RequestController::create` (`:63`), `ReviewController::create` (`:57`),
`ChatController::sendMessage` (`:241`), `ChatController::startChat` (`:45`),
`TurnController::getTurnCredentials` (`:35`), `EmailVerificationController::sendVerification`

Keiner dieser Endpunkte begrenzt, wie oft ein Konto ihn aufruft. Konkrete Folgen:

* **Anfragen:** Je Standort ist nur eine laufende Anfrage möglich — aber es gibt
  beliebig viele Standorte. Ein Konto kann jeden Guide der Plattform gleichzeitig
  anfragen (Benachrichtigung + Ton + Zähler bei jedem).
* **TURN-Zugangsdaten:** Jeder Aufruf löst einen ausgehenden HTTPS-Request an Metered
  aus (`MeteredTurnService.php:41`). Ohne Zwischenspeicher ist das ein
  fremdfinanzierter Verstärker und verbrennt das Kontingent.
* **Verifikationsmails:** Sobald N-4 behoben ist, wird das zum Mailversand-Verstärker.

---

### Mittel

---

**N-11 · E-Mail-Adresse unmaskiert auf der Kontoseite**
**Datei:** `class/Controller/SettingsController.php:95`

Siehe 4.3. Die Auswirkung ist **auf die eigene Kontoseite beschränkt** (Self-XSS) und
damit begrenzt — der Angreifer führt Skript im eigenen Browser aus. Relevant wird es
in Kombination: Ein Admin, der sich eine fremde Adresse ansieht, oder eine spätere
Stelle, die dieselbe Adresse anderswo ausgibt. Zusätzlich kann eine Adresse mit
`###USERNAME###` im Quoted-Local-Part den Platzhaltermechanismus stören.

**Empfehlung:** `ViewHelper::esc()` an beiden Stellen. Zwei Zeichen Aufwand.

---

**N-12 · Chat mit beliebigen Kontokennungen**
**Datei:** `class/Controller/ChatController.php:45-78`

`startChat()` nimmt `target_id` aus der Anfrage und legt ohne Existenz- oder
Beziehungsprüfung eine Einladung an. Beim Empfänger geht sie automatisch als Fenster
auf (`ui_chat.js:569`). Da die Kennungen fortlaufend sind, lässt sich **jedes** Konto
der Plattform anschreiben.

Das widerspricht der Leitentscheidung, die im übrigen Projekt konsequent umgesetzt
ist: *„Der Weg ins Gespräch führt über einen Standort auf der Karte"* — die
Benutzerliste wurde genau deshalb für alle außer den Admin gesperrt. Der Chat ist die
verbliebene Tür zu einem beliebigen Konto.

**Empfehlung:** Einen Chat nur zulassen, wenn eine Beziehung besteht (laufende oder
vergangene Anfrage zwischen den beiden), oder wenn der Empfänger als Guide einen
Standort anbietet. Dazu Existenzprüfung und Ratenbegrenzung.

---

**N-13 · Benutzernamen-Enumeration über `get_username`**
**Datei:** `class/Controller/UserController.php:357-369`

```php
$data = json_decode(file_get_contents("php://input"), true);
if ($data) { $user = new User($data); echo $user->getUsername(); exit; }
```

Jedes angemeldete Konto kann zu **jeder** Kennung den Benutzernamen abrufen — es gibt
keine Beziehungsprüfung, und der Konstruktor filtert `deleted` nicht
(`User.php:34-72`), also auch von gelöschten Konten. In Sekunden ist die vollständige
Namensliste der Plattform abrufbar.

Der Benutzername ist in dieser Anwendung die **Anmeldekennung**. Zusammen mit N-2
(keine wirksame Login-Bremse) ist das die eine Hälfte jedes Zugangs.

**Empfehlung:** Den Namen dort mitliefern, wo er gebraucht wird (am Offer, wie schon
bei der Rolle), und die Route entfernen. Ersatzweise: nur für Konten beantworten, mit
denen eine Beziehung besteht.

---

**N-14 · Standorte und Profile gelöschter Konten bleiben sichtbar**
**Datei:** `class/Model/Location.php:545`, `:584` — die Joins auf `user` filtern
`deleted` nicht.

`User::del_it()` (`User.php:221-226`) setzt nur `deleted = 1`. Die Folge:

* Die Standorte des Kontos bleiben auf der öffentlichen Karte und in der Liste.
* Die Standortseite rendert weiter, mit Anzeigenamen und Bild des gelöschten Kontos.
* Die Sitzung des Kontos wird nicht beendet — es bleibt bis zum Ablauf angemeldet.
* Das Guide-Profil ist korrekt weg (`GuideProfile::forUser` filtert `deleted = 0`),
  die Standortseite verweist damit ins Leere.

Das ist zugleich ein **DSGVO-Punkt**: Ein „gelöschtes" Konto ist weiterhin mit Name
und Bild öffentlich sichtbar (Art. 17).

**Empfehlung:** `AND user.deleted = 0` in allen öffentlichen Standortabfragen,
Sitzung beim Löschen verwerfen, und einen dokumentierten Löschpfad (siehe 6.2).

---

**N-15 · Der Benutzername ist der öffentliche Rückfallname**
**Datei:** `class/Model/GuideProfile.php:121-127`

Dokumentierte und bewusste Entscheidung: Ohne Anzeigenamen steht der Benutzername auf
Standortseite, Profilseite und in der Standortliste — für **Gäste** sichtbar. Sie
steht im Widerspruch zur ebenso dokumentierten Begründung, warum die Profil-URL die
Kennung trägt und nicht den Namen („Der Benutzername ist die Anmeldekennung").

Kein Fehler, sondern eine offene Abwägung. Für den öffentlichen Betrieb würde ich sie
neu treffen: Beim Annehmen der Guide-Rolle einen Anzeigenamen **verlangen**, oder
ersatzweise „Guide #17" anzeigen.

---

**N-16 · Offener Redirect / Host-Header im HTTPS-Zwang**
**Datei:** `index.php:20-24`

```php
$httpsUrl = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
header('Location: ' . $httpsUrl, true, 301);
```

`HTTP_HOST` kommt aus der Anfrage. Bei fehlender oder zu weiter `ServerName`-/
`server_name`-Konfiguration lässt sich der Nutzer auf einen fremden Host umleiten —
und weil es ein **301** ist, merkt der Browser sich das. Bemerkenswert: An der einen
Stelle, an der es wirklich zählt (Links in Reset-Mails), ist es richtig gelöst und
sogar begründet — `Url.php:24`: *„Naheliegend wäre `$_SERVER['HTTP_HOST']` — und
genau das wäre ein Fehler."*

**Empfehlung:** Dieselbe Quelle verwenden (`APP_URL` über `Url`), oder den Redirect in
den Webserver verlegen, wo er ohnehin hingehört.

---

### Niedrig

---

**N-17 · Sonstiges**

| Punkt | Datei | Anmerkung |
|---|---|---|
| Keine Längenbegrenzung für Chatnachrichten | `ChatController.php:241-271`, `ChatMessage.php:61-68` | Spalte ist `TEXT`; ein Client kann Megabyte-Nachrichten speichern. |
| `Request::g()` liest `$_REQUEST` | `Request.php:47` | Bei `request_order = "GPC"` können Cookies GET/POST überschreiben. Serverabhängig, billig zu härten (`$_GET`/`$_POST` gezielt). |
| Kein absolutes Sitzungsende, keine Re-Auth | `config/session.php` | Es gibt nur PHPs Standard-Leerlaufzeit. Für Kontolöschung, 2FA-Abschaltung und Passwortwechsel wäre eine erneute Passworteingabe angemessen (beim Passwortwechsel vorhanden). |
| Kein `session_regenerate_id()` beim Rollenwechsel | `Auth::refreshRole()` | Beim Aufstieg Zuschauer → Guide. Geringes Risiko, gängige Praxis. |
| `rtc_signal` wird nur beim Abrufen aufgeräumt | `WebRTCHandler.php:191` | Zeilen für Konten, die nie pollen, bleiben liegen. Ein Cron-Eintrag für alte Zeilen fehlt. |
| TOCTOU beim Bilderlimit | `LocationController.php:1173-1176` | Parallele Uploads können die 5 überschreiten. Kosmetisch. |
| Argon2**i** statt Argon2**id** | `User.php:748` (`pwdEncrypt`) | Argon2id ist seit PHP 7.3 verfügbar und die heutige Empfehlung. |
| Selbstbewertung über ein Zweitkonto | konzeptuell | Ein Guide kann sich mit einem zweiten Konto eine Führung anfragen, annehmen, durchführen und 5 Sterne geben. Nicht per Code lösbar; die Schwelle von 3 Bewertungen und eine verifizierte E-Mail (N-4) erhöhen den Aufwand. Für später: Auffälligkeitsprüfung (gleiche IP, Konto ohne andere Aktivität). |

---

## 6. Was für den öffentlichen Betrieb fehlt und kein Codefehler ist

### 6.1 Betrieb und Infrastruktur

| Thema | Stand | Zu tun |
|---|---|---|
| **HTTPS** | Erzwungen in `index.php:20-24`, Cookie mit `secure` + `httponly` + `SameSite=Strict`. | Zertifikat (Let's Encrypt), Umleitung in den Webserver verlegen, **HSTS** setzen (N-5). Erst danach ist der Zwang lückenlos. |
| **Sicherheitskopfzeilen** | Fehlen (N-5). | In der Serverkonfiguration, nicht in PHP. |
| **Rate Limiting** | Fehlt vollständig (N-2, N-3, N-10, S-7). | Zweistufig: grob im Webserver (`mod_evasive`, `limit_req`) gegen Fluten, fein in der Anwendung gegen Login und 2FA. Die grobe Stufe ist am Tag des Livegangs wichtiger. |
| **Backups** | **Nicht vorhanden** — kein Skript, kein Dokument, kein Hinweis im README. | Täglicher `mysqldump` **plus** das Upload-Verzeichnis (die Bilder liegen *nicht* in der Datenbank; ein DB-Backup allein stellt einen Standort nicht wieder her). Offsite, verschlüsselt, und **einmal probeweise zurückspielen** — ein ungetestetes Backup ist keins. |
| **Logrotation** | Vorbereitet, aber **nicht installiert**: `deploy/logrotate/webrtc-app` mit dem ausdrücklichen Hinweis „wird NICHT automatisch installiert". | Datei nach `/etc/logrotate.d/` kopieren, Pfad und Benutzer anpassen. Sonst wächst das Log unbegrenzt. |
| **Cronjob** | Pflicht laut README; räumt Präsenz, Bereitschaft, Anfragen und Führungen auf. | Einrichten **und überwachen**. Läuft er nicht, bleiben Standorte grün und Führungen offen. |
| **Monitoring** | Fehlt. | Mindestens: Plattenplatz (wegen N-7), Fehlerlograte, Erreichbarkeit, TURN-Kontingent. |
| **`.env`-Rechte** | Ungeprüft. | `chmod 600`, Eigentümer der Webserver-Benutzer. `.htaccess` sperrt sie nur unter Apache. |
| **`display_errors`** | Korrekt aus (`error_handler.php:3`). | — |
| **nginx** | Die `.htaccess` wirkt **nur unter Apache** — sie sagt das selbst. | Bei nginx entsprechende `location`-Regeln für `*.log`, `*.sql`, `*.md`, `.env`, `.git` schreiben. Sonst sind Quelltext, Dumps und Konfiguration abrufbar. |
| **Skalierung** | Polling: 0,67 Signal-Abfragen/s je Nutzer, dazu Heartbeat alle 10 s mit inzwischen **drei** Abfragen (Zähler, offene Bewertung, laufende Führung), dazu Chat-Polls. | Bei 100 gleichzeitigen Nutzern grob 90–100 Queries/s im Leerlauf. Kein Sicherheitsproblem, aber die Grenze für den Start. Index auf `rtc_signal.created_at` prüfen. |

### 6.2 DSGVO

| Pflicht | Stand | Zu tun |
|---|---|---|
| **Impressum** | Fehlt (N-6). | § 5 DDG. |
| **Datenschutzerklärung** | Fehlt (N-6). | Muss benennen: E-Mail, Benutzername, Passwort-Hash, **IP-Adressen im Log**, **Standortkoordinaten der angebotenen Führungen**, Profilbilder, Chatinhalte, Bewertungstexte, Aufzeichnung durchgeführter Führungen (`tour_request`), sowie die Weitergabe an **Metered.ca** (TURN, Drittland!) und die fünf CDN-Anbieter. |
| **Auftragsverarbeitung** | Fehlt. | AV-Verträge mit Hoster, SMTP-Anbieter und Metered.ca. Bei Metered zusätzlich die Drittlandübermittlung prüfen. |
| **Cookie-Hinweis** | Nicht nötig für das Sitzungscookie (technisch erforderlich). | Aber: `localStorage` wird für Farbprofil, übersprungene Bewertungen und weggeklickte Führungen benutzt. Auch technisch erforderlich — sollte in der Erklärung stehen. |
| **Auskunft (Art. 15)** | Kein Weg vorhanden. | Zumindest ein manueller, dokumentierter Ablauf. |
| **Löschung (Art. 17)** | **Nur Soft-Delete, unvollständig** (N-14). | Ein Konzept ist nötig, das drei Dinge trennt: was gelöscht wird (Profil, Bild, Chats), was anonymisiert wird (Bewertungstexte — sie gehören zur Auskunft über den *Guide*), und was aufbewahrt bleibt (`tour_request` als Abrechnungsgrundlage, mit Aufbewahrungsfrist). Die Tabellen sind dafür bewusst ohne Fremdschlüssel gebaut — das war eine gute Entscheidung, aber sie verlangt jetzt eine ausdrückliche Regel. |
| **Speicherbegrenzung** | Logs: 8 Wochen (Logrotate). Alles andere: unbegrenzt. | Fristen für `rtc_signal`, alte Anfragen, Chatverläufe festlegen. |
| **Bewertungen** | Anonym gegenüber der Öffentlichkeit, aber `customer_user_id` steht in der Zeile. | Richtig so. In der Datenschutzerklärung erwähnen, dass der Betreiber die Zuordnung kennt. |
| **Ungenutzte Positionsspalten** | `user.latitude/longitude` werden weder gelesen noch geschrieben; im Schema als „ungenutzt" markiert. | Sauber. Für den Livegang: leeren oder entfernen — nicht benötigte personenbezogene Spalten sind Datenminimierung. |

---

## 7. Freigabeempfehlung

### 7.1 Zwingend vor dem Livegang

Nach Aufwand sortiert — die ersten fünf sind zusammen ein Tag Arbeit.

| # | Punkt | Aufwand |
|---|---|---|
| 1 | **N-9** — `<Webroot>/php-error.log` auf dem Zielsystem prüfen und löschen | Minuten |
| 2 | **N-11** — `ViewHelper::esc()` in `SettingsController.php:94-95` | Minuten |
| 3 | **S-11** — Passwort-Mindestlänge im Modell auf 8 | Minuten |
| 4 | **N-5** — Sicherheitskopfzeilen in der Serverkonfiguration | Stunde |
| 5 | **S-15** — CDN-Versionen pinnen und SRI ergänzen (besser: lokal ausliefern) | Stunde |
| 6 | **N-6** — Impressum und Datenschutzerklärung | Halber Tag + Rechtsprüfung |
| 7 | **S-14** — UNIQUE auf `user.username` (Migration, vorher Duplikate prüfen) | Halber Tag |
| 8 | **N-2 + N-3** — Serverseitige Bremsen für Login und 2FA | Tag |
| 9 | **N-1 + S-5** — POST erzwingen und CSRF-Token, zentral in `index.php` | 1–2 Tage |
| 10 | **N-4** — SMTP, E-Mail-Verifikation reaktivieren, Registrierung bremsen | 1–2 Tage |
| 11 | **Backups** einrichten **und einmal zurückspielen** | Tag |

**Begründung der Auswahl:** Die Punkte 1–3 sind fast umsonst und schließen echte
Lücken. 4–5 schützen gegen Angriffe, die nichts mit dem eigenen Code zu tun haben.
6 ist rechtlich zwingend. 7–10 schließen die Wege, über die ein öffentlich
erreichbares System als Erstes angegriffen wird: Konten raten, Konten massenhaft
anlegen, Aktionen im Namen eines Angemeldeten auslösen. 11 ist kein Sicherheits-,
sondern ein Existenzpunkt.

### 7.2 Kurz nach dem Livegang (erste zwei Wochen)

* **N-13** — `get_username` schließen oder auf Beziehungen beschränken
* **N-12** — Chat auf bestehende Beziehungen einschränken
* **N-7** — Standortzahl je Konto begrenzen, Speicherkontingent
* **N-8** — `max_source_edge` senken oder `memory_limit` anpassen
* **S-7** — Größen- und Ratengrenze fürs Signaling, `type` gegen eine Liste prüfen
* **N-10** — Ratenbegrenzung für Anfragen, Bewertungen, Chat, TURN
* **N-14** — `user.deleted` in allen öffentlichen Abfragen
* **Logrotate** installieren, Monitoring für Plattenplatz und Fehlerrate

### 7.3 Danach

* **S-9** — Zufälliger IV, eigener Schlüssel für TOTP-Secrets
* **N-15** — Anzeigename beim Annehmen der Guide-Rolle verlangen
* **N-16** — HTTPS-Umleitung auf `APP_URL` umstellen
* **N-17** — die Sammelpunkte, Argon2id
* DSGVO-Löschkonzept und Auskunftsablauf ausformulieren
* Aufbewahrungsfristen für `rtc_signal`, Chats, alte Anfragen
* WebSocket-Signaling statt Polling (Skalierung, kein Sicherheitspunkt)

---

## 8. Was diese Prüfung nicht abdeckt

Damit der Bericht nicht mehr behauptet, als er belegen kann:

* **Keine laufende Instanz.** Alle Aussagen stammen aus dem Quelltext. Ob eine
  Schutzmaßnahme im Betrieb wirklich greift — insbesondere `.htaccess`,
  HTTPS-Umleitung, `memory_limit`, `upload_max_filesize` — ist ungeprüft.
* **Kein Penetrationstest.** Kein Ausnutzungsversuch, keine Fuzzing-Läufe, keine
  Prüfung der tatsächlichen Ausnutzbarkeit von N-8 oder N-3.
* **Keine Serverkonfiguration geprüft** (Apache/nginx, PHP-Version, `php.ini`,
  Dateirechte, Datenbankbenutzer und dessen Rechte). Mehrere Befunde hängen davon ab.
* **`vendor/` nicht geprüft.** Die Abhängigkeiten (PHPMailer, phpdotenv,
  spomky-labs/otphp, endroid/qr-code) wurden nicht gegen bekannte Schwachstellen
  abgeglichen. **Vor dem Livegang `composer audit` ausführen.**
* **Frontend-Bibliotheken nicht auf Schwachstellen geprüft.** jQuery 3.6.0, Bootstrap
  5.3.6, DataTables 1.13.8, select2 4.1.0-rc.0 (ein **Release Candidate** in einer
  Produktivanwendung), Leaflet ohne Version.
* **Das Steuerprotokoll und die WebRTC-Medienebene** wurden nur dort betrachtet, wo
  sie die Rollenvergabe berühren. Die Frage, was ein Client mit einer übernommenen
  Rolle im DataChannel anrichten kann, ist in PROTOKOLL.md behandelt und hier nicht
  wiederholt.
* **Keine Prüfung auf Geschäftslogik-Missbrauch** über die genannten Fälle hinaus
  (etwa: koordinierte Falschbewertungen mehrerer Konten).
