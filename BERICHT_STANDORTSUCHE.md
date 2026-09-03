# Bericht: Leere Länder- und Städteauswahl beim Standortanlegen

Stand: 2026-09-01 · Branch `fix/lauffaehigkeit`

Dieser Bericht dokumentiert die Fehlersuche und Behebung an der Seite
„Neue Lokation hinzufügen" (`index.php?act=set_location_page`).

---

## 1. Symptom

Zwei Meldungen, die sich als ein Problem herausstellten:

1. Die Länderauswahl war leer — es ließ sich kein Land auswählen.
2. Die Städtesuche fand nichts, auch bei vollständig eingetipptem Namen.
   Im Netzwerk-Tab ging dabei **kein einziger Request** raus.

## 2. Ursachen

### 2.1 Fehlende Spalte `country.iso2`

`assets/js/map.js` erwartet an drei Stellen ein Feld `iso2`, das im Schema
nicht existierte:

| Stelle | Verwendung |
|---|---|
| `map.js:78` | filtert die Länderliste gegen `allowedCountryCodes` |
| `map.js:184` | Parameter `countrycodes=` der Nominatim-Städtesuche |
| `map.js:115` | Flaggengrafik von `flagcdn.com/24x18/<iso2>.png` |

Ohne die Spalte war die Filterbedingung `country.iso2 && …` für **jede**
Zeile falsch. Der Länder-Dropdown blieb dadurch leer — und wäre es auch bei
vollständig gefüllter Tabelle geblieben.

Die Städtesuche hing daran: `map.js:182` bricht mit
`if (!countryIso2) return success({ results: [] })` ab, bevor Nominatim
gefragt wird. Ohne wählbares Land gibt es kein `iso2`, also keinen Request.
**Ein fehlendes Feld hat beide Auswahlfelder lahmgelegt.**

Warum das bei der Schema-Rekonstruktion durchgerutscht ist: **kein PHP-Code
nennt die Spalte je beim Namen.** Sie fließt nur über `SELECT * FROM country`
durch bis ins JavaScript. Ein Abgleich, der die PHP-Spaltenreferenzen gegen
das Schema prüft, kann sie nicht finden.

### 2.2 Fehlende Stammdaten in `country`

Die Tabelle hat im Anwendungscode **keinen Schreibpfad** — weder `INSERT`
noch `UPDATE`, nur lesende Zugriffe (`Location::selectAllCountries`,
`Location.php:159`, sowie die Joins). Sie muss vorbefüllt sein.

Die Git-Historie wurde geprüft: `database.sql` wurde in genau drei Commits
angefasst (`ea37c97`, `467bbd7`, `41f07de`), und **jede** Fassung enthält
exakt ein `INSERT` — für `usertype`. Es gab **nie** Stammdaten für `country`
oder `city`. Bei früheren Änderungen ist also nichts verloren gegangen.

### 2.3 Nicht die Ursache: leere Tabelle `city`

Ein naheliegender Verdacht, der sich nicht bestätigt hat. Belege:

* **Keine der Routen, die auf der Seite feuern, fasst `city` an.**
  `get_country`, `getSignal`, `heartbeat` und die Chat-Routen enthalten kein
  `city` im SQL. Die einzigen beiden Routen mit `LEFT JOIN city`
  (`get_locations`, `get_my_locations`) laufen dort gar nicht — ihre Guards
  in `locations_table.js:271` und `:280` prüfen auf Elemente, die es in
  `set_location.html` nicht gibt.
* **Die Suche fragt kein Backend.** Der einzige Request geht an
  `nominatim.openstreetmap.org`. Es existiert keine Route für Städte, in
  keiner Fassung der `routes.php`.
* **Der Dropdown arbeitet mit Namen, nicht mit IDs.** Die echte Funktion
  `formatCityResults` liefert `{ id: "Karlsruhe", text: "Karlsruhe", … }` —
  der `<option>`-Wert ist der Stadtname als Freitext. Es gibt nichts,
  wogegen ein Schlüssel aufgelöst werden müsste.

`city` füllt sich beim Speichern selbst: `Location::setNewLocation()`
(Z. 62-82) sucht die Stadt per Namen und legt sie über `insertCityName()`
an, falls sie fehlt.

**Der Gegensatz der beiden Tabellen:**

| | `country` | `city` |
|---|---|---|
| Schreibpfad im Code | keiner | `insertCityName()` |
| Quelle des Dropdowns | eigene Datenbank | Nominatim |
| Übermittelter Wert | Datenbank-**ID** | Stadt**name** |
| Muss vorbefüllt sein | **ja** | **nein** |

### 2.4 Ebenfalls nicht die Ursache: DB-Suche wurde nie ersetzt

Die Vermutung, die Städtesuche sei ursprünglich gegen die Datenbank gebaut
und später ersetzt worden, ließ sich nicht bestätigen:

* In **keiner** Fassung der `routes.php` gab es eine Route für Städte.
* Die einzige `FROM city`-Abfrage der gesamten Historie ist durchgehend
  `SELECT * FROM city WHERE city_name = :city` — ein Exact-Match-Lookup
  beim Speichern, keine Suche.
* Schon die früheste Autocomplete (`fetchCities` in Commit `e401678`) rief
  Nominatim auf. `git log -S "nominatim"` zeigt die API bereits im ersten
  Location-Commit `6ec8b32`.

Was der Erinnerung am nächsten kommt: `selectCity()` fragt die Datenbank
tatsächlich nach dem Stadtnamen ab — nur beim Speichern, nicht beim Suchen.

## 3. Behebung

| Was | Wo |
|---|---|
| Spalte `iso2 char(2) NOT NULL` mit UNIQUE-Index | `database.sql`, `migrations/003_country_iso2.sql` |
| 248 Länder mit deutschen Namen als Stammdaten | `database.sql`, `migrations/004_country_seed.sql` |

Der UNIQUE-Index ist Voraussetzung dafür, dass der Seed per `INSERT IGNORE`
wiederholbar läuft. Beide Migrationen prüfen ihre Voraussetzungen und
brechen ab, bevor sie etwas ändern: `003`, wenn `country` bereits Zeilen
ohne `iso2` enthält; `004`, wenn die Spalte fehlt.

Kein `emoji`-Feld: `map.js:88` übergibt es zwar an `code:`, wertet es aber
nie aus — die Flagge kommt über `iso2` vom CDN.

**Verifikation:** Die echte Filterlogik aus `map.js:78` wurde gegen die
geparsten Seed-Daten ausgeführt — 248 von 248 Ländern passieren den Filter.
Kein Code aus `allowedCountryCodes` ohne Datenbankeintrag, kein Eintrag ohne
Code, keine doppelten Codes oder Namen. Vom Nutzer in der laufenden
Anwendung bestätigt: Länderauswahl und Städtesuche funktionieren.

## 4. Fehler im Ablauf: Migrationen waren nie im Repository

Die vier Dateien unter `migrations/` lagen auf der Platte, waren aber nicht
versioniert. Ursache: `.gitignore` Zeile 11 enthält `*.sql`, was auch
`migrations/*.sql` erfasst hat — `git add -A` hat sie stillschweigend
übersprungen. `database.sql` blieb nur deshalb versioniert, weil sie bereits
vorher getrackt war; für getrackte Dateien greift `.gitignore` nicht.

Die betroffenen Commits beschreiben die Migrationen in ihren Nachrichten,
enthalten sie aber nicht. Aufgefallen ist es erst, als die Dateien im
Arbeitsverzeichnis des Nutzers fehlten.

Behoben in `8936fa0`: Dateien nachgetragen, `.gitignore` um eine Ausnahme
für `migrations/` erweitert. SQL-Dumps im Wurzelverzeichnis bleiben
ausgeschlossen — geprüft.

**Lehre:** `git status` zeigt ignorierte Dateien nicht an. Ein sauberer
Arbeitsbaum ist kein Beleg dafür, dass alles committet wurde. Bei neuen
Dateien gehört `git ls-tree -r HEAD --name-only` oder
`git check-ignore -v <datei>` zur Kontrolle.

## 5. Offene Punkte

### 5.1 Ohne ausgewähltes Land ist keine Städtesuche möglich

Die Abhängigkeit ist technisch begründet: Nominatim wird mit
`countrycodes=<iso2>` aufgerufen (`map.js:184`), der Ländercode ist also
Pflichtparameter. Der Guard in `map.js:182` ist insofern korrektes
Verhalten, kein Fehler.

**Problematisch ist die Nutzerführung**, denn die Abhängigkeit wird
nirgends kommuniziert:

* Das Stadtfeld ist **nicht deaktiviert**, solange kein Land gewählt ist
  (`set_location.html:19`). Es lässt sich öffnen und beschreiben.
* Bei fehlendem Land liefert der Guard ein leeres Ergebnis. select2 zeigt
  daraufhin seine Standardmeldung — und weil **keine deutsche Sprachdatei
  geladen wird** (`index.html:22` bindet nur `select2.min.js`, kein
  `i18n/de.js`), erscheint das englische **„No results found"**.
* Diese Meldung ist inhaltlich **irreführend**: Sie legt nahe, die Stadt
  existiere nicht, obwohl schlicht kein Land ausgewählt wurde.
* `language` in `initCitySelect2` definiert nur `inputTooShort`
  (`map.js:194-196`), nicht `noResults`.
* Im Formular gibt es keinen Hinweis auf die nötige Reihenfolge; beide
  Felder stehen gleichwertig untereinander.

Mögliche Verbesserungen, **nicht umgesetzt**:

1. `#citySelect` deaktivieren, solange `#countrySelect` leer ist, und beim
   `change`-Event freischalten.
2. `language.noResults` setzen — abhängig davon, ob ein Land gewählt ist:
   „Bitte zuerst ein Land wählen." statt „No results found".
3. Placeholder des Stadtfelds dynamisch anpassen
   („Erst Land wählen…" / „Stadt wählen…").
4. select2-Sprachdatei `i18n/de.js` einbinden, damit die restlichen
   Standardmeldungen nicht englisch erscheinen.

### 5.2 `loadCountries()` hat keine Fehlerbehandlung

`map.js:72-110` enthält **kein** `.catch()`. Liefert `get_country` einen
HTTP 500 mit HTML-Body — etwa bei nicht erreichbarer Datenbank —, wirft
`response.json()`, die Promise-Kette bricht ab, und
`$('#countrySelect').select2()` wird nie erreicht. Der Nutzer sieht ein
nacktes `<select>` ohne Hinweis auf die Ursache.

### 5.3 select2 wird ungeprüft aufgerufen

`map.js` prüft nirgends, ob die Bibliothek geladen ist. Ist das CDN
`cdn.jsdelivr.net` blockiert oder offline, wirft `initCitySelect2()` sofort
einen TypeError, `bindEvents()` läuft nicht mehr, und beide Auswahlfelder
bleiben funktionslos. Passend dazu: die CDN-Einbindungen haben kein
`integrity`-Attribut (siehe BESTANDSAUFNAHME.md, S-15).

### 5.4 Die Meldung „Stadt oder Beschreibung fehlt" prüft die Stadt nicht

`LocationController::setLocation()` löst `success=0` ausschließlich bei
`strlen($description) < 5` aus. `$city` wird **nirgends validiert**. Die
Meldung nennt also einen Grund, den sie gar nicht prüft.

## 6. Nicht verifiziert

* Die Migrationen wurden in der Entwicklungsumgebung **nicht gegen einen
  Server ausgeführt** — dort ist kein MySQL/MariaDB verfügbar. Geprüft sind
  Struktur und Daten, nicht die SQL-Syntax. Der erfolgreiche Import beim
  Nutzer bestätigt sie nachträglich.
* Ob der Browser das Formular bei leerem `#citySelect` wegen `required`
  überhaupt absendet, konnte nicht getestet werden: Das Playwright-Modul
  ist nicht installiert und das select2-CDN vom Proxy blockiert (HTTP 403).

---

## 7. Nachtrag: Der Punkt ließ sich nicht per Mausklick auf die Karte setzen

Stand: 2026-09-03 · Branch `claude/quirky-wright-m099dt`

### 7.1 Symptom

Auf „Standort anbieten" blieb ein Klick auf die Karte wirkungslos — als Guide
wie als Admin. Einmal hatte es funktioniert, danach nicht mehr. Die Eingabe
über Land und Stadt funktionierte durchgehend.

### 7.2 Ursache

Der Klick-Handler war gebunden (`initMap()`, `this.map.on('click', …)`) und
feuerte auch. Gelöscht wurde erst danach.

Auf `#countrySelect` hingen **zwei** `change`-Handler:

| # | registriert in | Verhalten |
|---|---|---|
| 1 | `initCitySelect2()` | leerte `#latitude, #longitude, #lat, #lon, #osm_place` — **ohne jede Abfrage** |
| 2 | `bindEvents()` → `onCountryChange()` | leerte ebenfalls, war aber durch `countryJustSetByLocation` geschützt |

Ablauf eines Klicks (`onMapClick()`):

1. Marker setzen, `#latitude`/`#longitude` füllen — das ging.
2. Reverse-Geocoding bei Nominatim (asynchron).
3. In der Antwort: erkanntes Land ≠ gewähltes Land → `$('#countrySelect').val(…).trigger('change')`.
4. Handler 1 lief zuerst und leerte die eben gesetzten Felder. Handler 2 sah
   die Markierung und hielt sich zurück — sie schützte nur einen von beiden.

Der Marker blieb liegen, Land und Stadt waren gefüllt: Das Formular *sah*
vollständig aus. `required` auf den `hidden`-Feldern greift nicht (verborgene
Felder sind von der HTML-Validierung ausgenommen), also ging es ohne
Koordinaten an den Server, der es mit `success=2` abwies.

**Warum es „einmal geklappt" hat:** Schritt 3 findet nur bei einem
Landwechsel statt. Wer vorher Deutschland wählte und dann nach Deutschland
klickte, behielt seinen Punkt. Beim allerersten Klick ist noch kein Land
gewählt (`''` ≠ `DE`) — der schlug immer fehl. Mit der Rolle hatte es nichts
zu tun; der Fehler steckte ausschließlich im Client.

Der Knopf „Aktuellen Standort verwenden" entging dem nur durch ein
`setTimeout(…, 500)`, das die Koordinaten *nach* dem Löschen noch einmal
setzte — ein Pflaster auf demselben Fehler.

### 7.3 Behebung

* Handler 1 entfernt. Das Freigeben des Stadtfelds
  (`setzeStadtfeldZustand()`) ist nach `onCountryChange()` gewandert, wo es
  in **beiden** Fällen läuft — bliebe das Feld nach einem Kartenklick
  gesperrt, schickte der Browser es gar nicht erst mit und der Standort
  landete ohne Stadt in der Datenbank.
* `onCountryChange()` ist jetzt der einzige `change`-Handler und unterscheidet
  den Landwechsel des Nutzers (leeren, Karte zentrieren) von einem, der aus
  Koordinaten folgt (nichts anfassen).
* Neu: `landAusKoordinatenSetzen(iso2)`. Kartenklick und Standortknopf
  benutzen beide diese eine Stelle, statt die Logik samt Markierung doppelt
  nachzubauen.
* Das `setTimeout(…, 500)` ist entfernt; Marker und Koordinaten erscheinen
  sofort.

### 7.4 Prüfung

`tests/client_test.js`, Abschnitt 29 (`node tests/client_test.js`, 74
Prüfungen). Geprüft werden die Zahl der Handler, der überlebende Punkt mit und
ohne vorgewähltes Land, die Gegenprobe (wählt der Nutzer selbst ein Land, wird
weiterhin geleert) und das Verschwinden der 500 ms. Gegen den alten Stand von
`map.js` schlägt der Abschnitt fehl.

### 7.5 Nicht verifiziert

Nicht im echten Browser nachgestellt — das select2-CDN ist vom Proxy blockiert
(HTTP 403) und Nominatim von hier nicht erreichbar. Der Nachweis läuft über
`assets/js/map.js` selbst, geladen in einer Attrappe von jQuery, Leaflet und
Nominatim. Das `required` auf `#latitude`/`#longitude` bleibt wirkungslos
(verborgene Felder werden nicht validiert); aufgefangen wird das weiterhin
serverseitig über `success=2`.

---

## 8. Nachtrag: Das wirkungslose `required` und die verlorene Eingabe

Stand: 2026-09-03 · Branch `claude/quirky-wright-m099dt`

Zwei Restpunkte aus Abschnitt 7, beide am selben Formular.

### 8.1 Das `required` an den versteckten Feldern hatte keine Wirkung

An `#latitude` und `#longitude` stand `required`. Ein `<input type="hidden">`
ist von der Prüfung des Browsers ausgenommen (HTML-Standard, „barred from
constraint validation") — das Attribut sah nach Absicherung aus und war keine.
Das Formular ging ohne Koordinaten raus, der Server wies es ab, und der Nutzer
landete eine Seite weiter bei einer Meldung, die nicht mehr neben dem Feld
stand, an dem es lag.

**Behebung:** Das Attribut ist an beiden Feldern weg. `map.js` prüft jetzt beim
Abschicken (`pruefeVorDemAbschicken`), zeigt den Hinweis oben im Formular und
scrollt zur Karte. Die Grenzen sind dieselben wie im `LocationController`;
verbindlich bleibt dessen Prüfung, denn wer ohne JavaScript abschickt, kommt an
der Prüfung des Browsers ohnehin vorbei. Die *sichtbaren* Pflichtfelder
behalten ihr `required` — dort greift es.

### 8.2 Der Rücksprung verlor die Eingaben

`setLocation()` antwortet auf eine Ablehnung mit einer Weiterleitung zurück aufs
Formular (Post/Redirect/Get, damit ein Neuladen den Standort nicht ein zweites
Mal anlegt). Der Preis dieses Musters ist, dass der POST-Rumpf verlorengeht.

Betroffen war nicht nur die Beschreibung:

| Feld | vorher | jetzt |
|---|---|---|
| Beschreibung | leer, neu tippen | steht wieder im Feld |
| Land | leer | wieder gewählt |
| Stadt | leer | wieder gewählt |
| Koordinaten | leer, Marker weg | Felder, Anzeige und Marker wieder da |

Land und Stadt traf es aus einem eigenen Grund: Beide Listen baut erst `map.js`
auf — das Land aus `index.php?act=get_country`, die Stadt aus der
Nominatim-Suche. Eine `<option>`, die der Server ins Formular schreibt, würde
davon überschrieben.

**Behebung:** Die Eingaben liegen bis zum nächsten Aufruf des Formulars in der
Sitzung (`merkeEingaben` / `holeEingaben` / `vergissEingaben`). Nicht in der
URL: Eine Beschreibung gehört nicht in die Adresszeile, ins Server-Log und in
den Verlauf. Gemerkt wird **vor** den Prüfungen, damit keine Ablehnung den
Rückweg vergessen kann; der Erfolgsweg räumt sie weg, sonst stünde der eben
gespeicherte Standort beim nächsten Aufruf wieder im Formular. `holeEingaben()`
löscht beim Lesen — die Werte überleben genau einen Rücksprung.

Ins Formular kommen sie über fünf Platzhalter, die `fuelleFormular()` besetzt:
Beschreibung und Koordinaten direkt in die Felder, Land und Stadt als
`data`-Attribute am Formular, aus denen `map.js` Auswahl, Anzeige und Marker
zurückholt. Jeder Wert geht durch `htmlspecialchars(…, ENT_QUOTES)`: Die
Beschreibung ist freier Text und landet in einem `value=""`-Attribut, wo ein
Anfuehrungszeichen sonst das Attribut beendet.

Das Wiederherstellen von Land und Stadt läuft über
`landOhneZuruecksetzenSetzen()` — dieselbe Stelle, über die auch Kartenklick
und Standortknopf gehen. Ein gewöhnliches `.val().trigger('change')` liefe als
Landwechsel des Nutzers durch und löschte die eben wiederhergestellten
Koordinaten; das ist genau der Fehler aus Abschnitt 7.

### 8.3 Prüfung

* `tests/client_test.js`, Abschnitt 30 (82 Prüfungen): Absenden ohne Punkt wird
  angehalten, mit Punkt nicht; `91`, `181` und `abc` gelten als kein Punkt;
  Anzeige, Marker, Land und Stadt kommen zurück, ohne die Koordinaten zu
  löschen — auch über die echte `loadCountries()`-Kette; ohne gemerkte
  Eingaben bleibt das Formular leer.
* `tests/server_test.php`, Abschnitte 27 und 28 (140 Prüfungen): die Eingaben
  überleben genau einen Rücksprung, der Erfolgsweg räumt sie weg, beide Wege
  durch `setLocation()` sind bedacht, eingesetzte Werte können kein Markup
  öffnen, und an den versteckten Feldern steht kein `required` mehr.

Jede dieser Prüfungen schlägt gegen den Stand davor fehl.

### 8.4 Nicht verifiziert

Weiterhin nicht im echten Browser nachgestellt (select2-CDN vom Proxy
blockiert, Nominatim nicht erreichbar). Der Ortsname aus OpenStreetMap wird
nach einem Rücksprung neu abgefragt statt mitgeschickt — er ist reine Anzeige;
schlägt die Abfrage fehl, bleibt das Feld leer und sonst nichts hängt daran.
