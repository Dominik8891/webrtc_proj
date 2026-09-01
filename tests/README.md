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
| `client_harness.js` | Stub-Umgebung für den Client: `document`, `fetch`, `alert`, `RTCPeerConnection` und `RTCDataChannel` als Attrappen. Der DOM-Stub schreibt angehängte Kinder mit, damit prüfbar ist, dass eine verworfene Nachricht *nicht* im Chatlog landet; abgespielte Signaltöne werden ebenfalls mitgeschrieben. Lädt danach `app.js`, `protocol.js`, `rtc.js`, `control.js`, `signaling.js` und `chat.js` aus `assets/js`. Allein nicht ausführbar. |
| `client_test.js` | Die eigentlichen Client-Prüfungen. |
| `server_test.php` | Die Serverprüfungen. Ersetzt `PdoConnect::$connection` durch eine Attrappe, die abgesetzte SQL-Statements nur mitschreibt statt sie auszuführen. |

Geprüft wird der **produktive Code**, nicht eine Nachbildung davon: Die
Testdateien laden `assets/js/*.js` und `class/**/*.php` direkt. Wird dort etwas
geändert, schlagen die Prüfungen an.

## Was `client_test.js` prüft (45 Prüfungen)

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

### Zeitkonstanten im Test

`client_test.js` setzt die Fristen aus `rtc.js` zu Beginn auf kurze Werte
herunter (Frist 200 ms statt 5 s, Gesamtfrist 1,2 s statt 30 s). Sonst liefe
ein Durchlauf über eine Minute. Geprüft wird dadurch das *Verhalten*, nicht die
konkrete Sekundenzahl — werden die Konstanten in `rtc.js` geändert, schlagen
die Tests nicht an. Das ist Absicht.

## Was `server_test.php` prüft (22 Prüfungen)

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

## Grenzen

Die Skripte prüfen Logik und Zustandsübergänge, **nicht das reale Netzverhalten**.
Nicht abgedeckt sind insbesondere:

- der echte Wechsel zwischen WLAN und Mobilfunk auf zwei Geräten,
- das tatsächliche Timing eines ICE-Restarts über einen TURN-Server,
- Medienwiedergabe, Kamera- und Mikrofonwechsel,
- das Aussehen der Richtungsanzeige und der Sperranzeige (geprüft wird nur,
  dass sie ein- und ausgeblendet werden, nicht wie sie aussehen),
- alles außerhalb von Verbindungsstabilität und Steuerprotokoll (Login, Chat,
  Standorte).

Ein grüner Durchlauf ersetzt daher keinen Test mit zwei echten Geräten.
