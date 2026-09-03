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
| `client_harness.js` | Stub-Umgebung für den Client: `document`, `fetch`, `alert`, `RTCPeerConnection` und `RTCDataChannel` als Attrappen. Der DOM-Stub schreibt angehängte Kinder mit, damit prüfbar ist, dass eine verworfene Nachricht *nicht* im Chatlog landet; abgespielte Signaltöne werden ebenfalls mitgeschrieben. Lädt danach `app.js`, `protocol.js`, `rtc.js`, `control.js`, `signaling.js`, `chat.js` und `ui.js` aus `assets/js`. Allein nicht ausführbar. |
| `client_test.js` | Die eigentlichen Client-Prüfungen. |
| `server_test.php` | Die Serverprüfungen. Ersetzt `PdoConnect::$connection` durch eine Attrappe, die abgesetzte SQL-Statements nur mitschreibt statt sie auszuführen. |

Geprüft wird der **produktive Code**, nicht eine Nachbildung davon: Die
Testdateien laden `assets/js/*.js` und `class/**/*.php` direkt. Wird dort etwas
geändert, schlagen die Prüfungen an.

## Was `client_test.js` prüft (63 Prüfungen)

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

### Rolle und Standort-Button (23)

23. **Der Standort-Button richtet sich nach der Rolle** (Befund F-5) — Guide und
    Admin sehen „Neue Lokation hinzufügen", ein Zuschauer „Jetzt Tour-Guide
    werden!", ein Konto ohne beides gar nichts. Abgemeldet bleibt der Button
    aus, und eine fehlende Servervariable blendet aus, statt zu scheitern.
    Entschieden wird über `window.userCan` aus `ViewHelper::output`; `ui.js`
    kennt selbst keine Rollennamen mehr.

    Geprüft wird zusätzlich das **Ziel** der beiden Beschriftungen: Der Guide
    kommt zum Standortformular (`act=set_location_page`), der Zuschauer zur
    Frage nach der Guide-Rolle (`act=guide_role_page`) — und ausdrücklich
    *nicht* mehr zum Formular. Früher führten beide dorthin, und wer es
    ausfüllte, war anschließend Guide, ohne je gefragt worden zu sein.

### Zeitkonstanten im Test

`client_test.js` setzt die Fristen aus `rtc.js` zu Beginn auf kurze Werte
herunter (Frist 200 ms statt 5 s, Gesamtfrist 1,2 s statt 30 s). Sonst liefe
ein Durchlauf über eine Minute. Geprüft wird dadurch das *Verhalten*, nicht die
konkrete Sekundenzahl — werden die Konstanten in `rtc.js` geändert, schlagen
die Tests nicht an. Das ist Absicht.

## Was `server_test.php` prüft (127 Prüfungen)

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
5. **Rollenvergabe für den Call** — der Zuschauer wird Zuschauer und der
   angerufene Guide wird Guide; beim Rückruf bleibt der Guide der Guide; bei
   zwei Guides und bei gar keinem Guide gilt der Angerufene als Guide; ein
   Admin gilt nicht als Guide (Befunde F-7/F-8); ein unbekannter Benutzer führt
   zu einer eindeutigen Rolle statt zu einem Fehler; beide Seiten bekommen
   zueinander passende Rollen und Dritte gar keine; gestempelt wird
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

## Grenzen

Die Skripte prüfen Logik und Zustandsübergänge, **nicht das reale Netzverhalten**.
Nicht abgedeckt sind insbesondere:

- der echte Wechsel zwischen WLAN und Mobilfunk auf zwei Geräten,
- das tatsächliche Timing eines ICE-Restarts über einen TURN-Server,
- Medienwiedergabe, Kamera- und Mikrofonwechsel,
- das Aussehen der Richtungsanzeige und der Sperranzeige (geprüft wird nur,
  dass sie ein- und ausgeblendet werden, nicht wie sie aussehen),
- alles außerhalb von Verbindungsstabilität, Steuerprotokoll und
  Berechtigungen (Login-Ablauf, Chatinhalte, Kartendarstellung),
- das Zusammenspiel mit einer echten Datenbank: Geprüft wird, welches SQL
  abgesetzt wird, nicht was MySQL daraus macht.

Ein grüner Durchlauf ersetzt daher keinen Test mit zwei echten Geräten.
