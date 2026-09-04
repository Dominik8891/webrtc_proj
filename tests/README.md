# Tests

Zwei eigenständige Prüfskripte für die Verbindungsstabilität und das
Steuerprotokoll der WebRTC-Funktion. Bewusst **ohne Test-Framework**: keine Composer-Dev-Abhängigkeit,
kein npm-Paket, keine Konfigurationsdatei. Jedes Skript ist ein einzelner
Aufruf, der entweder durchläuft oder mit Exit-Code 1 abbricht.

## Ausführen

```bash
node tests/client_test.js     # Client-Logik (assets/js)
php  tests/server_test.php    # Serverlogik (class/)
```

Beide funktionieren aus jedem Verzeichnis heraus; die Pfade sind relativ zur
Skriptdatei aufgelöst. Voraussetzungen sind nur Node.js und PHP auf der
Kommandozeile.

Bei Erfolg endet die Ausgabe mit `N Pruefungen bestanden.` und Exit-Code 0.
Beim ersten Fehlschlag bricht das Skript ab und nennt die verletzte Annahme.

**Keine Datenbank und kein Netzwerk nötig.** Die PDO-Verbindung und alle
HTTP-Aufrufe sind durch Attrappen ersetzt. Die Skripte schreiben nichts und
verändern nichts — sie sind gefahrlos jederzeit ausführbar.

## Dateien

| Datei | Inhalt |
|---|---|
| `client_harness.js` | Stub-Umgebung für den Client: `document`, `fetch`, `alert`, `navigator.mediaDevices`, `RTCPeerConnection` und `RTCDataChannel` als Attrappen. Die Medien-Attrappe schreibt mit, welche Spuren angefordert wurden, kennt eine Geräteliste (`__devices`) samt `enumerateDevices` und `getSettings().deviceId` und lässt sich über `__mediaError` (alles) oder `__mediaErrorFor.video` / `.audio` (eine Spurart) zu einer Ablehnung zwingen. Die PeerConnection-Attrappe bildet Transceiver samt Richtung nach: `setRemoteDescription` eines Angebots legt sie als `recvonly` an, und `replaceTrack` weist eine Spur der falschen Art mit `TypeError` ab — so wie der Browser. Der DOM-Stub schreibt angehängte Kinder mit, damit prüfbar ist, dass eine verworfene Nachricht *nicht* im Chatlog landet; abgespielte Signaltöne werden ebenfalls mitgeschrieben. `navigator` wird über `Object.defineProperty` gesetzt: Node bringt seit Version 21 ein eigenes mit, und das ist ein Getter ohne Setter — eine einfache Zuweisung lief still ins Leere. Lädt danach `app.js`, `protocol.js`, `rtc.js`, `control.js`, `media.js`, `signaling.js`, `chat.js` und `ui.js` aus `assets/js`. Allein nicht ausführbar. |
| `client_test.js` | Die eigentlichen Client-Prüfungen. |

Für den Bereitschaftsschalter kommt im Harness dazu: `classList.toggle` mit
zweitem Argument, mitgeschriebene Ereignisse am Dokument und am Fenster
(`__fireDoc` / `__fireWin` lösen sie aus — ohne das wäre nicht prüfbar, was als
Bedienung zählt), eine `navigator.sendBeacon`-Attrappe (`__beacons`) und
Antworten für `heartbeat` und `set_availability` (`__presenceCalls`,
`__availableSeconds`, `__availabilityFail`). `window.addEventListener` wird
ausdrücklich gesetzt: Node bringt am globalen Objekt ein eigenes mit, und
dessen Handler ließen sich nicht auslösen.
| `server_test.php` | Die Serverprüfungen. Ersetzt `PdoConnect::$connection` durch eine Attrappe, die abgesetzte SQL-Statements nur mitschreibt statt sie auszuführen. |

Geprüft wird der **produktive Code**, nicht eine Nachbildung davon: Die
Testdateien laden `assets/js/*.js` und `class/**/*.php` direkt. Wird dort etwas
geändert, schlagen die Prüfungen an.

## Was `client_test.js` prüft (146 Prüfungen)

### Verbindungsstabilität (1–14)

1. **`disconnected` beendet den Call nicht sofort** — der Zustand tritt bei
   jedem Netzwechsel auf. Erholt sich die Verbindung innerhalb der Frist, wird
   gar nicht erst neu ausgehandelt.
2. **ICE-Restart nach Ablauf der Frist** — genau ein Restart, `restart_offer`
   geht an den richtigen Empfänger, danach stellt die Antwort den Status wieder her.
3. **`failed` handelt sofort neu aus**, ohne Wartefrist.
4. **Aufgeben erst nach der Gesamtfrist** — mit genau *einer* Meldung an den
   Nutzer (früher zwei, Befund F-12) und einem Hangup an den Partner.
5. **Kein Glare** — der Angerufene schickt keinen eigenen Offer, sondern
   `restart_request`; der Anrufer führt den angeforderten Restart aus.
6. **`restart_offer` öffnet keinen Anruf-Dialog** — eine Neuaushandlung wird
   nicht als eingehender Anruf missdeutet.
7. **Auflegen geht über beide Wege raus** — DataChannel *und* Server-Fallback.
8. **Doppelt zugestelltes Auflegen meldet nur einmal.**
9. **`hangup` ohne `pendingOffer` wirft nicht** (Befund F-11).
10. **Steuerbefehle werden bei Störung verworfen, nicht gepuffert** — nach der
    Erholung kommt nur der neue Befehl an, kein Schwall alter Richtungsbefehle.
11. **Gestauter Sendepuffer blockiert Steuerbefehle.**
12. **Empfangsseite führt nur taufrische Befehle aus** — auch unmittelbar nach
    der Wiederverbindung wird noch verworfen.
13. **Polling wird im Call umgeschaltet statt abgeschaltet** — sonst erreichte
    weder das Auflegen noch der ICE-Restart den Gegenüber (Befund F-3).
14. **ICE-Server im Fehlerfall** (Befund F-18) — HTTP 500 und Netzwerkfehler
    landen nicht mehr als ICE-Konfiguration in der `RTCPeerConnection`;
    `turns:`-Einträge überleben die Kürzung (F-17); keine doppelten URLs.

### Steuerprotokoll (15–22)

Referenz: [`PROTOKOLL.md`](../PROTOKOLL.md).

15. **Chat und Steuerung sind getrennt** — ein in den Chat getippter
    `__arrow_forward__` löst keinen Steuerbefehl mehr aus; eine Steuernachricht
    auf dem Chatkanal wird verworfen und erscheint nicht als Chattext;
    Binärdaten gelten nur auf dem Chatkanal als Datei.
16. **Zehn ungültige Nachrichten werden verworfen** — kein JSON, JSON-Array,
    fehlende und fremde Protokollversion, unbekannter Typ, geerbter Name als
    Typ (`constructor`), fehlendes Pflichtfeld, unzulässige Richtung,
    Sequenznummer als Zeichenkette, zu großer Frame. Jeder Fall mit dem
    erwarteten Fehlercode, mitgezählt, nie im Chatfenster, nie ausgeführt.
17. **Rollen halten die Richtung ein** — der Guide führt `move` aus und
    bestätigt, kann selbst keines senden und lehnt ein `control_lock` der
    Gegenseite ab; der Zuschauer lehnt `move` ab und kann nicht sperren; ohne
    bekannte Rolle wird weder gesendet noch ausgeführt.
18. **Steuerkreuz nur beim Zuschauer** — beim Guide gesperrt, dafür der
    Sperrschalter sichtbar; das Call-Ende räumt die Rolle ab.
19. **Bestätigung verhindert Mehrfachdrücken** — während eine Bestätigung
    aussteht, geht kein zweiter Befehl raus; die Bestätigung gibt frei; eine
    ausbleibende Bestätigung sperrt das Steuerkreuz nicht dauerhaft; eine
    veraltete Bestätigung hebt keine neuere Sperre auf.
20. **`control_lock` hält die Steuerung an** — der Guide sendet die Sperre und
    führt währenddessen keinen Befehl aus, sondern lehnt mit Grund ab; der
    Zuschauer sieht sie und sendet nicht; nach der Freigabe geht es weiter.
21. **Wiederholte Sequenznummern** werden abgelehnt statt doppelt ausgeführt —
    auch ein Rückschritt.
22. **Die Rolle kommt vom Server** — sie hängt am ausgelieferten Offer, und die
    Antwort auf das eigene Offer wird bis zum Aufrufer durchgereicht.

### Der Anruf des Zuschauers (28)

28. **Nur Ton, und Fehler sofort** — `startCall` fordert genau eine
    Medienspur an, und zwar das Mikrofon; die Kamera wird nicht angefragt und
    keine Videospur gesendet. Für sie steht ein leerer Videosender in der
    Aushandlung bereit, damit der Kamera-Knopf sie später ohne
    Neuaushandlung zuschalten kann. Ein abgelehntes Mikrofon erzeugt genau
    *eine* Meldung, die das Mikrofon benennt — nicht die frühere Meldung „Der
    Anruf wurde nicht angenommen" 25 Sekunden später —, es geht kein Offer
    raus und es läuft keine Annahmefrist. Weist der Server den Anruf ab (Ziel
    ist kein Guide), steht seine Begründung in der Meldung, der Call wird
    abgeräumt und es wird keine Rolle vergeben.

### Medien im laufenden Call (31–34)

31. **Gerätewechsel wirkt — ohne Neuaushandlung** — ein Wechsel von Kamera oder
    Mikrofon geht über `RTCRtpSender.replaceTrack()`; geprüft wird, dass danach
    das neue Gerät am Sender hängt, dass **kein** neues Offer entstanden ist
    und dass die alte Spur gestoppt und aus dem lokalen Strom genommen wurde.
    Der Sender wird über den **Transceiver** gesucht, nicht über
    `sender.track.kind`: Bei stummem Mikrofon lieferte die alte Suche („der
    erste Sender ohne Spur") für Video den Audiosender, und `replaceTrack`
    warf. Ist eine Spurart abgeschaltet, wird die Wahl nur gemerkt und gilt
    beim Einschalten — nichts wird heimlich wieder angeschaltet. Alles davon
    in **beiden Rollen**: als Zuschauer (Anrufer) und als Guide (Angerufener).

32. **Die eigene Kamera abschalten kommt beim Gegenüber an** — die Spur wird
    vom Sender genommen *und* gestoppt (sonst bliebe die Kameraleuchte an), sie
    verlässt den lokalen Strom, der eigene Platzhalter erscheint, und die
    Gegenseite bekommt `video_state {on:false}`. Auf der Empfangsseite wird
    beides geprüft: die Protokollnachricht **und** das Stummwerden der
    Empfangsspur (`track.onmute`) — der zweite Weg trägt auch dann, wenn der
    Steuerkanal nicht steht; ohne ihn blieb das letzte Standbild stehen.
    Außerdem: Der eigene Videozustand geht **von selbst** mit der Begrüßung
    raus, und wer ohne Kamera annimmt, behält trotzdem einen `sendrecv`-
    Videotransceiver — sonst ließe sie sich später nur mit einer
    Neuaushandlung zuschalten.

33. **Guide und Zuschauer bekommen verschiedene Oberflächen** — die Rollenklasse
    auf `#call-view` schaltet um (Sperrschalter beim Guide, Steuerkreuz und
    Sperrhinweis beim Zuschauer, ohne Rolle nichts von beidem). Geprüft wird
    außerdem die Stilvorlage selbst: dass beim Guide der Empfangsbereich
    entfällt (er bekommt kein Bild — ein Videobereich dafür wäre eine schwarze
    Fläche) und das eigene Bild stattdessen die Bühne füllt. Und dass es
    **ein** Markup geblieben ist: Jede ID in `inner_call_controll.html` steht
    genau einmal.

34. **Ein verweigerter Medienzugriff wird gemeldet** — der Gerätewechsel hatte
    gar kein `try`/`catch`, die Ablehnung endete als unbehandelte
    Promise-Ablehnung in der Konsole. Jetzt: genau *eine* Meldung, die das
    **richtige** Gerät nennt (vorher stand in jedem Fall „Mikrofon", auch wenn
    die Kamera abgelehnt worden war). Beim Annehmen beendet eine abgelehnte
    Kamera das Gespräch nicht mehr — es läuft mit Ton weiter, die Kamera bleibt
    zuschaltbar; fällt auch der Ton aus, wird mit einer Meldung abgebrochen und
    der Anrufer benachrichtigt. Zuletzt: Das Neuladen der Seite nach dem Call
    (Mobilgeräte) wartet, bis die Meldung gelesen werden konnte — vorher lud es
    starr nach einer Sekunde neu und löschte damit genau den Satz, der den
    Abbruch erklärte.

### Standort anbieten (29, 30)

29. **Ein Klick auf die Karte setzt den Punkt und behält ihn** — auf
    `#countrySelect` hingen **zwei** `change`-Handler. `onMapClick()` füllt die
    Koordinatenfelder, holt danach den Ortsnamen bei Nominatim und trägt aus
    der Antwort das erkannte Land ein; dieses Eintragen löste `change` aus, und
    der zweite Handler (aus `initCitySelect2`) leerte `#latitude`,
    `#longitude`, `#lat`, `#lon` und `#osm_place` ungefragt wieder —
    `onCountryChange()` hielt sich per Markierung zurück, der andere kannte sie
    nicht. Zurück blieb ein Formular, das mit Marker, Land und Stadt
    vollständig aussah, aber ohne Koordinaten abgeschickt wurde; der Server
    wies es mit `success=2` ab. Wer vorher dasselbe Land wählte, in das er
    klickte, blieb verschont — beim ersten Klick ist noch gar kein Land
    gewählt, der schlug immer fehl. Geprüft wird deshalb die **Zahl** der
    Handler (genau einer), dass der geklickte Punkt beide Fälle übersteht, die
    Gegenprobe (wählt der *Nutzer* ein Land, wird weiterhin geleert) und dass
    das `setTimeout(..., 500)` im Standortknopf verschwunden ist — es war nur
    ein Pflaster auf demselben Löschen.

    Dafür wird `assets/js/map.js` ein zweites Mal geladen, in einem eigenen
    Geltungsbereich mit einer jQuery-Attrappe, die Feldwerte und gebundene
    Handler mitschreibt. Weder `$` noch `window.webrtcApp` werden dabei global
    überschrieben; Leaflet und Nominatim sind Attrappen.

30. **Kein Absenden ohne Punkt, und keine verlorene Eingabe** — zwei Befunde
    aus demselben Formular.

    (a) An `#latitude` und `#longitude` stand ein `required`. Es hatte **keine
    Wirkung**: Ein `<input type="hidden">` ist von der Prüfung des Browsers
    ausgenommen („barred from constraint validation"). Das Formular ging ohne
    Koordinaten raus, der Server wies es ab — eine Seite weiter, weg von dem
    Feld, an dem es lag. Geprüft wird jetzt beim Abschicken
    (`pruefeVorDemAbschicken`), mit denselben Grenzen wie im
    `LocationController`; verbindlich bleibt dessen Prüfung. Der Test deckt
    ab: ohne Punkt wird angehalten, ein Hinweis erscheint und es wird zur
    Karte gescrollt; mit Punkt geht es durch und der alte Hinweis verschwindet;
    `91`, `181` und `abc` gelten als kein Punkt.

    (b) Bei diesem Rücksprung gingen Beschreibung, Land und Stadt verloren. Der
    Server schreibt sie jetzt zurück (Beschreibung und Koordinaten direkt in
    die Felder, Land und Stadt als `data`-Attribute); `map.js` holt daraus
    Anzeige, Marker und Auswahl zurück. Geprüft wird, dass Anzeige und Marker
    wiederkommen, dass das Wiederherstellen des Landes die Koordinaten **nicht**
    löscht (derselbe Landwechsel, an dem der Kartenklick krankte), dass das über
    die echte `loadCountries()`-Kette läuft, dass das Formular danach absendbar
    ist — und dass ohne gemerkte Eingaben nichts vorbelegt wird.

### Rolle und Standort-Button (23)

23. **Der Standort-Button richtet sich nach der Rolle** (Befund F-5) — Guide und
    Admin sehen „Neue Lokation hinzufügen", ein Zuschauer „Jetzt Tour-Guide
    werden!", ein Konto ohne beides gar nichts. Abgemeldet bleibt der Button
    aus, und eine fehlende Servervariable blendet aus, statt zu scheitern.
    Entschieden wird über `window.userCan` aus `ViewHelper::output`; `ui.js`
    kennt selbst keine Rollennamen mehr.

    Geprüft wird zusätzlich das **Ziel** der Beschriftungen: Der Guide
    kommt zum Standortformular (`act=set_location_page`), der Zuschauer zur
    Frage nach der Guide-Rolle (`act=guide_role_page`) — und ausdrücklich
    *nicht* mehr zum Formular. Früher führten beide dorthin, und wer es
    ausfüllte, war anschließend Guide, ohne je gefragt worden zu sein.

    Und der dritte Fall: `termsOutdated` geht dem Anlege-Knopf **vor** —
    „Neue Bedingungen bestätigen" statt „Neue Lokation hinzufügen", weil das
    Formular dahinter ohnehin zur Frage weiterleitet. Ohne offenen Punkt
    bleibt es beim Anlege-Knopf.

### Bereitschaft des Guides (40)

40. **Angemeldet ist nicht bereit** — der Kern der Verfügbarkeitsregel. Ein
    Guide war „online", solange irgendein Tab offen stand; wer die Seite über
    Nacht offen ließ, wurde nachts angerufen. Geprüft wird die Trennung von
    beiden Seiten:

    *Der Schalter:* Er startet aus, auch bei angemeldetem Konto. Ein Klick
    schickt `ready:true` an `set_availability`, ein zweiter `ready:false` —
    derselbe Knopf. Übernommen wird, was der **Server** antwortet, nicht was
    angefragt wurde: Bleibt seine Restzeit 0, steht der Schalter danach auf
    „Nicht bereit". Ein Netzausfall lässt den Zustand stehen, meldet es und
    sperrt den Knopf nicht dauerhaft.

    *Die Restzeit ist sichtbar* — am Knopf und im Tooltip, in jeder
    Größenordnung lesbar (Sekunden, Minuten, `1:45 Std`). In den letzten fünf
    Minuten hebt sich der Schalter ab. **Der Ablauf wird gemeldet**, und zwar
    genau einmal: Der Übergang von „läuft" auf 0 löst den Hinweis aus, nicht
    der Zustand — sonst käme bei jedem Heartbeat einer.

    *Was verlängert:* Ein Heartbeat ohne Bedienung meldet `active: 0` — ein
    offener Tab verlängert nichts. Ein Zeigerdruck wird in der Erfassungsphase
    am Dokument eingesammelt, geht als `active: 1` mit dem nächsten Heartbeat
    raus und zählt **einmal** (der Merker wird beim Abholen geleert). Ein
    laufendes Gespräch zählt ebenfalls.

    *Das Seitenende:* `pagehide` schickt per `sendBeacon` ein `ready:false` an
    `set_availability`. Eine zwischengespeicherte Seite (`persisted`) nicht —
    das ist ein Seitenwechsel innerhalb der Anwendung —, und ohne laufende
    Bereitschaft geht gar nichts raus.

    *Die Standortliste* liest `availability` (`live`/`busy`/`idle`) und nicht
    mehr den Kontostatus. Anrufbar ist genau `live`; der Anrufknopf ist sonst
    gesperrt. Ausdrücklich geprüft wird, dass der **alte** Wert `online`
    **nicht** mehr anrufbar macht — sonst gälte weiter „offener Tab =
    anrufbar", nur an einer Stelle mehr.

    *Die Frist steht an einer Stelle:* `availability_timeout` in
    `config/presence.php`. Weder `availability.js` noch `UserController.php`
    dürfen die Zahl selbst enthalten.

### Die Standortseite (41)

Das Modul `assets/js/location_page.js` meldet sich nur, wenn der Server seine
Daten mitgeliefert hat (`window.locationPage`) — die Abfrage hängt bewusst
nicht an einem Element, denn eine id findet man auf jeder Seite, auf der
jemand dieselbe vergibt.

Die Zustandsmarke kennt dieselben drei Werte wie Karte und Server und fällt
bei allem anderen auf „kein Guide" zurück; insbesondere wird aus dem alten
`online` kein „verfügbar".

Die Dateiprüfung vor dem Hochladen benutzt **die Grenzen des Servers** und
keine eigenen: Ohne Angaben lehnt der Client von sich aus gar nichts ab — der
Server prüft ohnehin, und ein Client, der aus eigenem Antrieb sperrt, sperrt
irgendwann etwas Erlaubtes. SVG und PDF kommen nicht durch.

Abschnitt 38 ist mit dem Umbau gewachsen: Der Weg vom Klick zum Anruf ist
länger geworden — Nadel oder Listenzeile führen jetzt auf die Standortseite —,
und **die Standortkennung muss ihn überstehen**. Geprüft wird jede Station:
der Verweis in der Liste, der Verweis im Kartenfenster, der Anrufknopf der
Standortseite und die Übergabe an `rtc.startCall(userId, locationId)`.

### Zeitkonstanten im Test

`client_test.js` setzt die Fristen aus `rtc.js` zu Beginn auf kurze Werte
herunter (Frist 200 ms statt 5 s, Gesamtfrist 1,2 s statt 30 s). Sonst liefe
ein Durchlauf über eine Minute. Geprüft wird dadurch das *Verhalten*, nicht die
konkrete Sekundenzahl — werden die Konstanten in `rtc.js` geändert, schlagen
die Tests nicht an. Das ist Absicht.

## Was `server_test.php` prüft (174 Prüfungen)

1. **STUN-Fallback** — die Vorgabeliste greift ohne `STUN_SERVERS`; ein eigener
   Server ist über die ENV-Variable ohne Codeänderung eintragbar; ungültige
   Einträge (`turn:`, `javascript:`, Leerstrings) werden verworfen.
2. **Zusammenführen der ICE-Server** — STUN wird ergänzt, TURN bleibt erhalten,
   keine doppelten URLs; fehlendes TURN wird als solches gemeldet (davon hängt
   der Hinweis im Client ab); `urls` als Array wird unterstützt.
3. **Antwort des TURN-Dienstes** — ein Fehlerobjekt wird nicht als ICE-Server
   durchgereicht (Befund F-18); unbrauchbare Antworten liefern eine leere Liste;
   nacktes Array und Objektform werden beide verstanden.
4. **Löschen nur der ausgelieferten Signale** (Befund F-1) — gelöscht wird
   ausschließlich die gelesene Menge, gebunden an den Empfänger; nicht-numerische
   IDs werden aussortiert; eine leere Liste erzeugt keinen DB-Zugriff; das
   Aufräumen abgelaufener Signale löscht nie innerhalb des 15-Sekunden-Lesefensters.
5. **Rollenvergabe für den Call** — ein Anruf kommt nur zustande, wenn der
   Angerufene Standorte anbieten darf (Recht `location.offer`); dann ist er
   der Guide und der Anrufer der Zuschauer. Darf er es nicht — Trial, User
   oder ein unbekanntes Konto —, gibt es *keine* Rollen und der Anruf wird
   abgewiesen, statt den Angerufenen stillschweigend zum Guide zu erklären.
   Geprüft wird dabei ausdrücklich, dass Anrufbarkeit und `location.offer`
   **dieselbe Aussage** sind: Guide und Admin ja, Trial und User nein — eine
   Rollenabfrage hätte den Admin ausgeschlossen, obwohl sein Standort auf der
   Karte steht. Bei zwei Guides ist der Angerufene der Guide; beide Seiten
   bekommen zueinander passende Rollen und Dritte gar keine; gestempelt wird
   ausschließlich das Offer.

   Die Prüfung ersetzt die Benutzertabelle durch eine Attrappe im Speicher —
   auch hier ohne Datenbank.

6. **Rollen-Normalisierung** (`App\Helper\Role`, Befunde F-5/F-6) — Rollennamen
   werden unabhängig von der Groß-/Kleinschreibung erkannt, Zahl und
   Zahlenstring bedeuten dasselbe (PDO liefert je nach Einstellung `'2'` statt
   `2`), und alles Unbekannte — `null`, Leerstring, das nie existierende
   `'tourist'`, unbelegte IDs wie die frühere 3 — ergibt `null` statt
   versehentlich einer gültigen Rolle. Die Nummernvergabe selbst wird
   mitgeprüft (Trial 0, User 1, Guide 2, Admin 10), damit ein versehentliches
   Verschieben auffällt: An den Nummern hängen die Daten in `usertype`. Die
   `0` darf dabei nicht mit "keine Rolle" verwechselt werden. Darauf setzen
   die Rechte auf: `mayOfferLocation()` trifft genau Admin und Guide,
   `mayBecomeGuide()` genau Trial und User, keine Rolle ist beides zugleich,
   und das Signaling teilt sich mit dem Helfer dieselbe Guide-ID.

7. **Berechtigungstabelle und Routen** — jede Route in `config/routes.php`
   hat ein bekanntes Recht und eine Antwortart; die Prüfung schlägt bei einer
   Route ohne Recht, mit leerem Recht, mit erfundenem Recht und mit
   unbekannter Antwortart auch wirklich an. Die drei früher völlig
   ungeschützten Endpunkte (`delete_user`, `delete_location`,
   `chat_get_messages`) hängen an ihrem Recht. Ein Gast hat genau die
   öffentlichen Rechte und keines darüber hinaus; `user.manage`,
   `user.delete`, `location.block` und `system.admin` hat ausschließlich der
   Admin. Unbekannte Rolle, unbelegte Rollennummer und unbekanntes Recht
   heißen immer nein — auch nicht ersatzweise "die Gastrechte".

8. **Vergleichsoperatoren auf Rollenwerten sind verboten.** Ein Suchlauf über
   `class/`, `config/`, `cron/`, `index.php` und `assets/js` meldet jeden
   Vergleich (`<`, `>`, `<=`, `>=`, `==`, `===`, `!=`, `!==`), der auf einem
   Rollenwert steht — `role_id`, `type_id`, `getRoleId()`, `getUsertype()`,
   `Role::ADMIN` und die übrigen Schreibweisen. Erlaubt bleibt der Vergleich
   gegen `null` ("Rolle unbekannt"); ausgenommen sind nur `Role.php` und
   `Permission.php`, die das Rollenmodell selbst bilden.

   Der Grund: Genau solche Vergleiche waren die Befunde F-5/F-6 und die drei
   Fehler im `UserController` — `role_id > 1` unterstellte eine Rangfolge, die
   es nicht gibt, und `=== 1` traf den Guide statt den Admin. Wer eine
   Berechtigung braucht, fragt ein benanntes Recht ab (`Auth::can()`,
   `Permission::has()`).

   Kommentare werden vorher entfernt, Zeichenketten **nicht**: Der häufigste
   Rollenausdruck überhaupt steht in einer (`$_SESSION['user']['role_id']`).
   Die Regel prüft sich zuerst selbst an sechs verbotenen und sechs erlaubten
   Beispielzeilen — schlägt sie dort nicht an, ist der Suchlauf wertlos und
   der Test bricht ab.

9. **Eigentum steht in der WHERE-Klausel** — `updateLocation()` und
   `deleteLocation()` setzen `AND user_id = :user_id` ab und binden den
   Eigentümer; ohne Standort-ID oder ohne Benutzer erreicht gar kein Statement
   die Datenbank; trifft die Bedingung keine Zeile, meldet `deleteLocation()`
   Misserfolg statt wie früher pauschal Erfolg. Die Sperre fragt bewusst
   *nicht* nach dem Eigentümer (sie richtet sich gerade an fremde Standorte)
   und löscht nichts. Die Übersicht filtert gesperrte Standorte in der
   Abfrage heraus, die Moderation sieht sie weiterhin, und die eigene Liste
   des Guides enthält Sperre und Grund.

10. **Standort-Tabellen: id und Spaltenzahl** — die Übersicht
    (`locations_table.html`) und die Liste der eigenen Standorte
    (`settings.html`) haben verschiedene Tabellen-`id`s; keine `id` kommt in
    beiden Templates vor. Die Zahl der `<th>` im Template stimmt je Tabelle
    mit der Spaltenliste `columnKeys()` in `assets/js/locations_table.js`
    überein, und die Übersicht hat genau eine Spalte mehr ("User"). Der
    Selektor steht nur noch in `TABLES`, nirgends sonst als Literal.

    Der Grund: Beide Tabellen hießen `locationsTable`. Wurde die Tabelle der
    Einstellungsseite mit den Optionen der Übersicht geladen, kamen
    siebenzellige Zeilen in eine sechsspaltige Tabelle und DataTables brach
    mit „Incorrect column count" ab — ohne zu sagen, welche Tabelle gemeint
    war. Die Spaltenzahl stand dabei an drei Stellen gleichzeitig
    (`<thead>`, Zeilenaufbau, feste Zellennummern), die
    Tabellenkonfiguration ebenfalls an drei.

11a. **Die GPS-Abfrage ist entfallen** — die Route `save_location`, das Recht
    `user.position`, `UserController::saveLocation()`, `User::saveLocation()`,
    der Dialog und sein Skript sind weg, und das Layout lädt kein Skript mehr,
    das es nicht gibt. Die Spalten `user.latitude/longitude/location_updated_at`
    bleiben in `database.sql` stehen, dort aber als **UNGENUTZT** vermerkt.
    Die Guide-Frage wird nach dem Login nicht mehr gestellt; erreichbar bleibt
    sie über die Einstellungen und den Knopf der Kopfleiste.

11. **Die Guide-Rolle wird angenommen, nicht vergeben** — gefragt wird, wessen
    Entscheidung noch aussteht: ein `Trial`-Konto ohne jeden Datenbankzugriff,
    ein Guide nur dann, wenn seine Zustimmung eine ältere Fassung der
    Bedingungen trägt (`GuideRole::TERMS_VERSION`) oder ganz fehlt. `User` und
    `Admin` werden nicht gefragt.

    Beim Annehmen wird die Zustimmung genau einmal festgehalten — mit
    Zeitpunkt, Beginn und der Fassung, die im Dialog stand — *und* die Rolle
    gesetzt; ein Admin kommt dabei nicht durch, seine Rolle bleibt
    unangetastet. Beim Zurückgeben wird abgewiesen, wer noch Standorte
    anbietet (keine Rollenänderung, kein `DELETE`); sonst wird die Rolle
    wieder `User` und der Widerruf im Profil vermerkt — die Zustimmung von
    damals wird nicht gelöscht. Gezählt werden die Standorte per `COUNT(*)`
    mit `user_id` in der Bedingung, ohne Benutzer gar nicht erst.

    Der Sinn der Prüfung: Der Rollenwechsel stand früher mitten in
    `LocationController::setLocation()` und passierte als Nebenwirkung des
    Standortformulars. An `GuideRole::accept()`/`::resign()` hängt später die
    Abrechnung — diese beiden Methoden müssen die einzigen Stellen bleiben.

12. **Jeder Platzhalter im Template wird auch gefüllt** — jedes `###MARKE###`
    in `guide_role.html` und `settings.html` kommt im zugehörigen Controller
    vor. Ein vergessener Platzhalter steht sonst wörtlich auf der Seite.

12b. **Veraltete Guide-Bedingungen greifen dort, wo die Rolle benutzt wird** —
    `GuideRole::needsDecision()` stand eine Weile ohne Aufrufer da (der Login
    stellt die Frage nicht mehr), eine erhöhte `TERMS_VERSION` wirkte dadurch
    überhaupt nicht. Geprüft wird jetzt, dass `requireCurrentTerms()` existiert
    und nach der Zustimmung fragt, und dass **beide** Methoden des
    `LocationController` sie aufrufen — auch `setLocation()`, denn ein POST
    erreicht sie ohne den Umweg über das Formular.

    Dazu drei Bedingungen, die leicht zu übersehen sind: Der **Admin** darf
    Standorte anlegen, hat aber kein `user.guide_role` — `needsDecision()` muss
    für ihn `false` bleiben (und tut es ohne Datenbankzugriff), sonst liefe er
    in eine Weiterleitung auf eine Seite, die ihn abweist. Die Dialogseite muss
    dem Guide ein **`accept`** anbieten, sonst ist die Weiterleitung eine
    Sackgasse — vorher sah er dort nur „Guide-Rolle zurückgeben". Und der
    offene Punkt muss **sichtbar** sein, bevor jemand am gesperrten Formular
    ankommt: in `SettingsController` und über `termsOutdated` in
    `window.userCan` bis in `ui.js`.

24. **Chat: die Beteiligung wird geprüft** — ein Unbeteiligter schreibt nicht
    in einen fremden Chat, nimmt keine fremde Einladung an und setzt keine
    fremden Nachrichten auf gelesen. Geprüft wird dabei nicht nur die
    Fehlermeldung, sondern dass **gar kein schreibendes Statement** abgesetzt
    wird — eine Meldung nützt nichts, wenn die Nachricht trotzdem in der
    Datenbank landet. Die Ablehnung fällt für „gibt es nicht" und „geht dich
    nichts an" wörtlich gleich aus: Die Chat-IDs sind fortlaufend, ein
    Unterschied verriete beim Durchzählen, welche Chats existieren.
    `setMessagesSeen()` bindet die Kennung aus der **Sitzung**, auch wenn im
    Formular eine andere steht.

    Dazu die Regel für alles, was noch dazukommt: Jede Methode des
    `ChatController`, die eine `chat_id` aus der Anfrage entgegennimmt, muss
    `Auth::userId()` heranziehen und die Beteiligung prüfen. Ohne diese
    Prüfung fiele eine später ergänzte Methode still in dieselbe Lücke zurück.

25. **Die Adresse in E-Mail-Links kommt aus der Konfiguration** — weder aus
    dem Code (dort stand `https://localhost/rctprojnew/`, die Adresse eines
    Entwicklungsrechners) noch aus dem Host-Header der Anfrage: Wer den Reset
    anstößt, könnte den Link sonst auf einen eigenen Server umbiegen. Die
    Mail ginge an den richtigen Empfänger, der Klick an den Angreifer.
    `App\Helper\Url` setzt Basis und Ziel zu genau einer Adresse zusammen
    und weist Unbrauchbares ab (kein Schema, `javascript:`, Anfrage- oder
    Fragmentteil, Zeilenumbruch) — dann entsteht **kein** Link und es wird
    nichts verschickt. Der Schlüssel `APP_BASE_URL` muss in `.env.example`
    stehen und erklärt sein.

26. **Passwortwechsel: das Konto kommt aus der Sitzung** — der Benutzername
    aus dem Formular wird nicht mehr gelesen, gesucht wird über die Kennung
    (`WHERE id = :id`), und weder `change_pw.html` noch der Link in
    `settings.html` schicken eine Kennung mit.

    Der Grund: Vorher bestimmte die Anfrage, welches Konto geprüft wird. Wer
    angemeldet war, konnte einen fremden Namen eintragen und Passwörter
    durchprobieren — die Meldung „Das alte Passwort ist nicht korrekt!" ist
    die Auskunft, ob geraten wurde. Der Lockout aus
    `LoginController::handleLogin()` greift dort nicht: Er steht in
    `$_SESSION` und zählt nur Anmeldeversuche.

    Dieselbe Regel wird projektweit geprüft: Keine Methode, die am **eigenen**
    Konto arbeitet (Einstellungen, Farbprofil, 2FA, Heartbeat, eigene
    Position, Guide-Rolle, Bestätigungsmail), nimmt eine Kennung aus der
    Anfrage oder liest direkt aus `$_REQUEST`. Eine Kennung aus der Anfrage
    ist nur dort in Ordnung, wo bewusst ein *fremder* Datensatz gemeint ist
    (Benutzerverwaltung, Standortsperre, Chatpartner) — und dort steht eine
    eigene Prüfung daneben.

27. **Eine abgelehnte Eingabe geht nicht verloren** — `setLocation()`
    antwortet auf eine Ablehnung mit einer Weiterleitung zurück aufs Formular
    (Post/Redirect/Get, damit ein Neuladen den Standort nicht ein zweites Mal
    anlegt). Der POST-Rumpf geht dabei verloren: Der Nutzer stand vor einem
    leeren Feld und musste die Beschreibung noch einmal tippen, obwohl nur die
    Koordinaten gefehlt hatten. Land und Stadt traf es genauso — beide Listen
    baut erst `map.js` auf.

    Die Werte reisen jetzt über die **Sitzung** mit, nicht über die URL: Eine
    Beschreibung gehört nicht in die Adresszeile, ins Server-Log und in den
    Verlauf. Geprüft wird, dass sie genau **einen** Rücksprung überleben
    (`holeEingaben()` löscht beim Lesen — sonst hinge die alte Beschreibung
    beim nächsten, völlig unabhängigen Aufruf wieder darin), dass der
    Erfolgsweg sie wegräumt, dass gemerkt wird **vor** der ersten Ablehnung,
    und dass `fuelleFormular()` sie gegen die echte Vorlage einsetzt. Dazu die
    Maskierung: Die Beschreibung ist freier Text und landet in einem
    `value=""`-Attribut — `"><script>` darf dort kein Markup öffnen.

28. **Die Koordinatenfelder tragen kein wirkungsloses `required`** — an
    `#latitude` und `#longitude` stand eines. Ein `<input type="hidden">` ist
    von der Prüfung des Browsers ausgenommen; das Attribut sah nach Absicherung
    aus und war keine. Geprüft wird, dass es an beiden Feldern weg ist, dass
    die *sichtbaren* Pflichtfelder (`description`, `countrySelect`,
    `citySelect`) ihres behalten — dort greift es — und dass der Server die
    Koordinaten weiterhin selbst prüft: Wer ohne JavaScript abschickt, kommt an
    der Prüfung des Browsers ohnehin vorbei.

29. **Ohne Bereitschaft keine Führung** — Konto 6 ist Guide, hat
    `location.offer` und einen eigenen Standort, steht aber nicht auf bereit.
    Weder der Weg über den Standort noch der ohne Standort kommt zustande, und
    der Angerufene bekommt keine Guide-Rolle. Danebengestellt der bereite
    Guide mit demselben Recht: Der Unterschied liegt allein in
    `available_until`, nicht in der Rolle.

    Der **Direktanruf der Verwaltung bleibt möglich** — auch zu einem nicht
    bereiten Guide, auch mit Standortkennung, und zwar als `peer`/`peer`. Für
    eine Rückfrage der Moderation muss sich niemand bereit gemeldet haben, und
    geführt wird dabei ohnehin nicht.

    Dazu eine Quelltextprüfung: `User::update()` darf `available_until`
    **nicht** mitschreiben. Sonst verlängerte jeder `save()` — und der
    Heartbeat löst einen pro Takt aus — die Bereitschaft, und die Kopplung von
    „Tab offen" und „anrufbar" wäre durch die Hintertür zurück.

    Die PDO-Attrappe bildet dafür die Bereitschaftsabfrage nach: eine Spalte
    `rest` statt einer Benutzerzeile, und `available_until IS NOT NULL` im
    `WHERE`. Ohne das prüfte der Test gegen eine Antwort, die es so nie gibt.

30. **Ein Standort hat Inhalt — und die Seite dazu** (Abschnitt 29 im Skript). `Languages::normalize()`
    lässt nur bekannte Kürzel durch, verwirft Doppelungen und legt die
    Reihenfolge fest (die des Katalogs, nicht die der Eingabe); eine zweite
    Sprachliste in einer Vorlage oder im JavaScript fällt auf.
    `selectOneForPage()` liefert alle neuen Felder samt ausgewerteter
    Verfügbarkeit und filtert die Sperre **nicht** — sonst käme der Guide
    nicht mehr an seinen eigenen gesperrten Standort. `availabilityOf()`
    meldet für einen gesperrten Standort immer `idle`: Die Sperre schlägt
    jede Bereitschaft.

    Dann wird die **ganze Seite gebaut** — `App\Helper\LocationView` ist eine
    reine Funktion, also geht das ohne Datenbank und ohne nachgestellte
    Anmeldung. Geprüft werden drei Ansichten desselben Standorts:

    - **Angemeldeter Zuschauer:** Der Anrufknopf trägt *beide* Kennungen
      (`data-userid` und `data-locationid`). Geht die Standortkennung
      verloren, wäre jede Führung über einen Admin-Standort ein Gespräch ohne
      Führung — und das fiele nicht auf, weil der Anruf trotzdem zustande
      käme.
    - **Gast:** keine `data-userid`, keine `userId` in den Seitendaten,
      stattdessen der Weg zur Anmeldung.
    - **Eigentümer:** das Bearbeitungsformular; die beiden anderen bekommen
      es nicht. Und er kann sich nicht selbst anrufen.

    Dazu: kein Platzhalter überlebt in einer der drei Ansichten, und
    **Fremdeingabe löst keine Ersetzung des Servers aus** — ein Titel mit dem
    Text eines Platzhalternamens steht im fertigen Dokument nicht mehr als
    solcher da, weder im Markup noch im `<script>`-Block mit den Seitendaten.
    Diese Prüfung hat genau dort einen Fehler gefunden: Der Ortsname ging
    durch `json_encode` statt durch `esc`.

    Zuletzt eine Quelltextprüfung: `LocationView` darf nicht auf `Auth`,
    `Request`, `$_SESSION`, `$_REQUEST`, `PdoConnect` oder `ImageStore`
    zugreifen. Sobald sie das täte, entschiede sie mit — und dieselbe Frage
    stünde an zwei Stellen.

31. **Bilder: außerhalb des Webroots, geprüft, und ohne Reste** (Abschnitt 30 im Skript).
    `ImageStore::isValidName()` nimmt nur 32 Hexzeichen an; `../../etc/passwd`,
    ein Nullbyte oder Großbuchstaben werden abgewiesen, und `pathFor()` gibt
    dann *nichts* zurück statt eines Pfades, den ein Aufrufer versehentlich
    benutzt. Auch die Größenangabe aus der Anfrage kann keinen Pfad
    beeinflussen.

    Die **Obergrenze hat genau eine Lesestelle**: Keine Klasse außer
    `ImageStore` liest `max_images_per_location`, und im JavaScript steht
    keine eigene Zahl. Das hält die vorbereitete Staffelung je Konto offen —
    sie ist heute ein Parameter, der nicht beachtet wird, und soll später eine
    Abfrage in genau diesem Methodenrumpf werden.

    Der Vorgabepfad liegt eine Ebene **oberhalb** des Document Root, und SVG
    steht nicht in der Liste der angenommenen Formate: Das ist ein Dokument
    mit Skriptfähigkeit, kein Bild.

    Beim **Sortieren** stehen Standort *und* Eigentümer in der WHERE-Klausel,
    und alle Updates laufen in einer Transaktion — bricht es in der Mitte ab,
    stünden sonst zwei Bilder auf derselben Position.

    Die **Reihenfolge der Schritte** ist in beiden Richtungen festgehalten:
    Beim Hochladen erst die Datei, dann die Zeile (und die Datei wird
    weggeräumt, wenn die Zeile scheitert); beim Löschen erst die Zeile, dann
    die Datei. Eine Zeile ohne Bild zeigt die Seite als kaputtes Bild an, eine
    Datei ohne Zeile belegt nur Platz.

    Und: Beim Löschen eines Standorts wird `deleteLocationDir()` gerufen —
    *nachdem* feststeht, dass der Standort dem Aufrufer gehörte. Der
    Fremdschlüssel nimmt die Zeilen mit, die Dateien nicht; die Datenbank
    kennt das Dateisystem nicht.

    Die **Auslieferung** prüft die Sperre (sonst wäre sie wirkungslos, sobald
    jemand die Bild-ID kennt), den Dateinamen und schickt `nosniff` und
    `Cache-Control: private` mit.

## Grenzen

Die Skripte prüfen Logik und Zustandsübergänge, **nicht das reale Netzverhalten**.
Nicht abgedeckt sind insbesondere:

- der echte Wechsel zwischen WLAN und Mobilfunk auf zwei Geräten,
- das tatsächliche Timing eines ICE-Restarts über einen TURN-Server,
- Medienwiedergabe, Kamera- und Mikrofonwechsel (geprüft wird nur, *welche*
  Spuren beim Anrufen angefordert werden, nicht was der Browser daraus macht),
- das Aussehen der Richtungsanzeige und der Sperranzeige (geprüft wird nur,
  dass sie ein- und ausgeblendet werden, nicht wie sie aussehen),
- alles außerhalb von Verbindungsstabilität, Steuerprotokoll und
  Berechtigungen (Login-Ablauf, Chatinhalte, Kartendarstellung),
- das Zusammenspiel mit einer echten Datenbank: Geprüft wird, welches SQL
  abgesetzt wird, nicht was MySQL daraus macht.

Ein grüner Durchlauf ersetzt daher keinen Test mit zwei echten Geräten.
