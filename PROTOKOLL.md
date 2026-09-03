# Steuerprotokoll — Version 2

Referenz für alles, was zwischen Zuschauer und Guide über die WebRTC-DataChannels
läuft. Diese Datei ist verbindlich: Ein Client — auch eine spätere mobile App —
ist genau dann kompatibel, wenn er sich an das hier Beschriebene hält.

Die maschinenlesbare Fassung derselben Tabelle steht in
[`assets/js/protocol.js`](assets/js/protocol.js). Beide werden zusammen geändert.

**Stand:** Protokollversion `2` · Branch `fix/call-rollen`

### Was Version 2 gegenüber Version 1 ändert

* **Blickrichtung.** `move` kennt zusätzlich `look_up` und `look_down`. Der
  Guide dreht dabei nur den Kopf beziehungsweise das Gerät, er geht nicht.
* **Rolle `peer`.** Ein Direktanruf, in dem niemand führt (Verwaltung ↔
  Nutzer). Beide Seiten senden Ton und Bild, niemand steuert. Ein Anruf, der
  von einem Standort ausgeht, bleibt auch mit einem Admin eine Führung.
* **Der Zuschauer sendet keine Medien mehr.** Das ist keine Protokolländerung im
  engeren Sinn — auf den DataChannels ändert sich dadurch nichts —, aber es
  gehört zum Bild und steht deshalb in Abschnitt 3.

Ein Client der Version 1 kennt weder die neuen Richtungen noch `peer` und würde
sie als ungültig verwerfen. Die Versionsprüfung ist **exakt** (Abschnitt 2), es
gibt also keine gemischten Calls: Beide Seiten sprechen Version 2 oder gar
nicht miteinander.

---

## 1. Überblick

Zwei Personen, ein Call, feste Rollen — der Regelfall ist die Führung:

| Rolle | Wer | Tut |
|---|---|---|
| `viewer` | Zuschauer, sitzt zu Hause | sendet Bewegungs- und Blickbefehle, sendet keine Medien |
| `guide` | Person vor Ort, trägt das Gerät | führt sie aus, bestätigt, kann sperren |
| `peer` | Direktanruf ohne Führung (Verwaltung ↔ Nutzer) | nichts davon — beide reden nur miteinander |

Zwei getrennte DataChannels:

| Label | Inhalt | Wer legt ihn an |
|---|---|---|
| `chat` | **nur Nutzerinhalt**: Textnachrichten, Dateien | Anrufer (`createDataChannel`) |
| `control` | **nur Protokoll**: Bewegung, Bestätigung, Sperre, Videozustand, Auflegen | Anrufer (`createDataChannel`) |

Beide werden mit den WebRTC-Voreinstellungen angelegt (`ordered: true`,
zuverlässig). Der Angerufene übernimmt sie über `ondatachannel` und ordnet sie
**anhand des Labels** zu — die Reihenfolge, in der sie ankommen, ist nicht
zugesichert. Ein Kanal mit unbekanntem Label wird geschlossen, nicht benutzt.

Warum getrennt: Vorher trug ein einziger Kanal `chat` beides. Ein in den Chat
getippter Text wie `__arrow_forward__` löste dadurch einen Steuerbefehl aus, und
umgekehrt landete jeder nicht erkannte Steuerbefehl stillschweigend als Chattext
im Fenster des Guides. Beides ist mit der Trennung konstruktiv ausgeschlossen.

---

## 2. Rahmenformat

Jede Textnachricht auf **beiden** Kanälen ist ein JSON-Objekt mit zwei
Pflichtfeldern:

```json
{ "v": 2, "type": "move", "dir": "forward", "seq": 3 }
```

| Feld | Typ | Pflicht | Bedeutung |
|---|---|---|---|
| `v` | Ganzzahl | ja | Protokollversion. Aktuell immer `2`. |
| `type` | Zeichenkette | ja | Nachrichtentyp aus der Tabelle in Abschnitt 4. |

Weitere Felder je Typ, siehe Abschnitt 4.

**Regeln**

* Es gibt keine Magic Strings mehr. Ein nackter String wie `__arrow_forward__`
  ist keine gültige Nachricht und wird verworfen.
* Ein Frame darf höchstens **4096 Byte** groß sein (UTF-8, nicht Zeichen).
* Zahlen sind Zahlen, keine Zeichenketten: `"seq": 3` ist gültig, `"seq": "3"`
  nicht.
* Felder, die in Abschnitt 4 nicht aufgeführt sind, werden beim Empfang
  **ignoriert**. Innerhalb derselben Version darf ein Sender also zusätzliche
  Felder mitschicken, ohne ältere Empfänger zu brechen.
* **Binärframes** sind ausschließlich auf dem Kanal `chat` zulässig und gelten
  dort als Datei. Auf dem Kanal `control` werden sie verworfen.

### Versionierung

Der Empfänger vergleicht `v` **exakt** mit seiner eigenen Version. Weicht sie
ab, wird die Nachricht verworfen und protokolliert — es gibt keine Toleranz nach
oben oder unten und keine Aushandlung. Version 2 wurde deshalb mit einem Bruch
eingeführt: Ein Client der Version 1 und einer der Version 2 verstehen einander
gar nicht, statt sich über eine unbekannte Blickrichtung halb zu verstehen. Eine
spätere Version 3 muss entweder abwärtskompatibel neben Version 2 laufen (beide
Versionen akzeptieren) oder denselben Bruch machen.

`hello` (Abschnitt 4.1) ist die Nachricht, an der ein Client eine
Versionsabweichung früh erkennt: Sie geht als erstes über den Steuerkanal, und
ihre Ablehnung fällt im Log auf.

---

## 3. Rollen

Die Rolle gilt **für genau einen Call** und wird nicht gespeichert.

**Sie kommt vom Server, nicht aus dem Client.** Der Client hat keinen Weg, sich
selbst eine Rolle zuzuweisen.

| Rolle | Wer | Tut | Sendet |
|---|---|---|---|
| `viewer` | Zuschauer, sitzt zu Hause | steuert | **nichts** |
| `guide` | Person vor Ort | wird gesteuert | Ton und Bild |
| `peer` | Direktanruf ohne Führung (Verwaltung ↔ Nutzer) | nichts davon | Ton und Bild |

Entscheidend ist, **woher der Anruf kam**. Eine Führung beginnt an einem
Standort: Der Zuschauer sucht sich auf der Karte oder in der Liste einen Ort aus
und ruft den an, der dort ist. Ein Direktanruf aus der Benutzerverwaltung meint
etwas anderes — dort steht kein Ort, sondern eine Person.

Der Anrufer schickt deshalb mit seinem `offer` die Standortkennung mit:

```json
{ "type": "offer", "sdp": "…", "target": 7, "location": 91 }
```

Das Feld `location` ist optional. Fehlt es, ist es ein Direktanruf. Es ist eine
**Behauptung des Clients** und wird als solche behandelt (siehe unten).

Vergeben wird die Rolle in
[`WebRTCController::callRoles()`](class/Controller/WebRTCController.php) nach
drei Regeln, in dieser Reihenfolge:

1. Geht der Anruf **von einem Standort des Angerufenen** aus, führt der
   Angerufene — ohne Ausnahme, auch als Admin. Wer einen Standort anbietet,
   lässt sich dort steuern; dafür steht das Angebot auf der Karte.
2. Sonst: Ist **einer der beiden ein Admin**, ist es keine Führung. Beide
   bekommen `peer`. Ein Direktanruf mit der Verwaltung hat einen anderen
   Zweck — Rückfrage, Unterstützung, Moderation. Dort gibt es nichts zu
   steuern, und beide sollen einander sehen und hören. Der Admin darf in
   diesem Fall auch jemanden anrufen, der keine Standorte anbietet.
3. Sonst muss der **Angerufene Standorte anbieten dürfen** (Recht
   `location.offer`). Dann ist er der Guide, der Anrufer der Zuschauer.

Darf der Angerufene nichts anbieten und ist kein Admin beteiligt, **kommt der
Anruf gar nicht zustande** — das Offer wird abgewiesen, bevor es gespeichert
wird. Ein Anruf allein macht niemanden zum Guide; das ist eine ausdrückliche
Entscheidung des Betroffenen.

Gefragt wird in Regel 3 das *Recht* und nicht die Rollennummer: Es ist dasselbe
Kriterium, über das ein Standort überhaupt erst auf die Karte kommt. Wer dort
steht, ist anrufbar.

### Die Standortkennung wird geprüft, nicht geglaubt

Sie kommt vom Anrufer. Regel 1 greift deshalb nur, wenn **alles** davon gilt:

* Den Standort gibt es.
* Er **gehört dem Angerufenen**. Sonst könnte jeder Anrufer eine beliebige
  fremde Kennung mitschicken und damit ein Steuerkreuz auf jemanden richten,
  der davon nichts weiß.
* Er ist **nicht gesperrt**. Ein gesperrter Standort ist aus der Übersicht
  genommen; über ihn beginnt keine Führung mehr.
* Der Angerufene hat das Recht `location.offer`.

Hält eines davon nicht, wird die Kennung schlicht ignoriert und es gelten die
Regeln 2 und 3.

**Der Server merkt sich den Standort an der Signalzeile**
(`rtc_signal.location_id`, Migration 009). Der Angerufene holt sein Offer erst
Sekunden später über das Polling ab und hätte die Angabe sonst nicht mehr —
dann käme dieselbe Verbindung beim Anrufer als Führung und beim Angerufenen als
Gespräch unter Gleichen heraus. Ausgeliefert wird die Kennung nicht; sie ist
eine Sache zwischen Offer und Rollenvergabe.

Ausgeliefert wird die Rolle über den Signalisierungsweg, an das `offer`
gehängt:

* **Anrufer:** Die Antwort auf sein `POST index.php?act=getSignal` mit
  `type: "offer"` enthält zusätzlich `role`.

  ```json
  { "status": "ok", "role": "viewer" }
  ```

* **Angerufener:** Das über das Polling ausgelieferte Offer-Signal enthält
  zusätzlich `role`.

  ```json
  { "id": 91, "type": "offer", "sender_id": 42, "sdp": "…", "role": "guide" }
  ```

Beide Seiten rechnen über dieselben Angaben — Anrufer, Angerufener und
Standort — und bekommen deshalb zwingend zueinander passende Rollen. Ein
zweiter Aufruf ist nicht nötig und es gibt kein Zeitfenster, in dem ein Client
ohne Rolle dasteht.

Nur `offer` wird gestempelt. `restart_offer` nach einem ICE-Restart nicht — die
Rolle steht seit dem Anruf fest und wird clientseitig gehalten.

### Rolle unbekannt

Kommt keine oder eine unbekannte Rolle an, gilt sie als `null`. Dann:

* wird **kein Steuerkreuz** gerendert,
* wird **kein** richtungsgebundener Typ gesendet,
* wird **jeder** eingehende richtungsgebundene Typ mit dem Code `no_role`
  abgelehnt,
* werden **keine Medien angefordert** — kein Mikrofon, keine Kamera.

Im Zweifel steuert niemand und sendet niemand.

Dasselbe gilt in der Rolle `peer` für alles Richtungsgebundene: Die Gegenseite
eines `peer` ist wieder ein `peer`, und weil weder `viewer` noch `guide`
beteiligt ist, fallen `move`, `ack` und `control_lock` in einem solchen Call von
selbst durch die Prüfung aus Abschnitt 5. Es braucht dafür keine zweite Regel.

### Wer Medien sendet

| Rolle | Mikrofon | Kamera |
|---|---|---|
| `viewer` | nein | nein |
| `guide` | ja | ja (abschaltbar) |
| `peer` | ja | zuschaltbar |
| unbekannt | nein | nein |


**Der Zuschauer sendet nichts.** Er wird nicht einmal nach einer Freigabe
gefragt: keine Kamera, kein Mikrofon, kein Kameralicht. Gesteuert wird über
Tasten und die lokalisierten Tonsignale beim Guide — die Anwendung soll
weltweit funktionieren, und Sprache ist dafür kein verlässliches Steuermittel.
Seine Oberfläche zeigt deshalb weder eine Selbstansicht noch Knöpfe für Kamera
und Mikrofon.

Der Anrufer erfährt seine Rolle erst mit der Antwort auf sein `offer`. Er baut
die Verbindung deshalb **ohne Medien** auf und hält für beide Spurarten je einen
leeren Sender bereit (`addTransceiver`, Richtung `sendrecv`). Was seine Rolle
danach vorsieht, wird über `replaceTrack` eingehängt — ohne Neuaushandlung.

---

## 4. Nachrichten

Spalte **Richtung** nennt, wer senden darf. Der Empfänger prüft das: Er kennt
seine eigene Rolle, damit die der Gegenseite, und lehnt alles ab, was aus der
falschen Richtung kommt.

| Typ | Kanal | Richtung | Zweck |
|---|---|---|---|
| [`hello`](#41-hello) | `control` | beide | Rollenmeldung zur Gegenprobe |
| [`move`](#42-move) | `control` | Zuschauer → Guide | Bewegungs- oder Blickbefehl |
| [`ack`](#43-ack) | `control` | Guide → Zuschauer | Bestätigung eines Bewegungsbefehls |
| [`control_lock`](#44-control_lock) | `control` | Guide → Zuschauer | Steuerung sperren/freigeben |
| [`video_state`](#45-video_state) | `control` | beide | eigenes Videobild an/aus |
| [`hangup`](#46-hangup) | `control` | beide | Auflegen |
| [`chat`](#47-chat) | `chat` | beide | Textnachricht |
| [Binärframe](#48-binärframe-datei) | `chat` | beide | Datei |

---

### 4.1 `hello`

Meldet der Gegenseite die eigene, vom Server vergebene Rolle. Wird einmal je
Call gesendet, sobald Rolle **und** Steuerkanal beide da sind.

| Feld | Typ | Pflicht | Werte |
|---|---|---|---|
| `role` | Zeichenkette | ja | `"guide"` \| `"viewer"` \| `"peer"` |

**Richtung:** beide · **Kanal:** `control`

```json
{ "v": 2, "type": "hello", "role": "viewer" }
```

Der Empfänger **übernimmt die gemeldete Rolle nicht** — seine eigene kommt vom
Server. `hello` dient allein der Gegenprobe: Meldet der Partner dieselbe Rolle
wie die eigene, stimmt etwas nicht, und das gehört ins Log statt still zu
bleiben. Ein Client darf `hello` ignorieren, muss es aber senden.

---

### 4.2 `move`

Eine Anweisung an den Guide: gehen, sich wenden oder den Blick heben und
senken. Der einzige Typ, der beim Guide etwas auslöst.

| Feld | Typ | Pflicht | Werte |
|---|---|---|---|
| `dir` | Zeichenkette | ja | siehe Tabelle unten |
| `seq` | Ganzzahl | ja | 1 … 2147483647, je Call streng aufsteigend |

| `dir` | Bedeutung | Tonsignal beim Guide |
|---|---|---|
| `forward` | vorwärts gehen | `move_forward.mp3` |
| `backward` | zurückgehen | `move_back.mp3` |
| `left` | nach links wenden | `turn_left.mp3` |
| `right` | nach rechts wenden | `turn_right.mp3` |
| `look_up` | Blick nach oben — **nicht gehen** | `look_up.mp3` |
| `look_down` | Blick nach unten — **nicht gehen** | `look_down.mp3` |

Blick und Bewegung laufen bewusst als **dieselbe** Nachricht: Für den Guide ist
beides eine Anweisung, ein Ton, ein Pfeil, und Sequenznummer, Bestätigung und
Sperre gelten unverändert. Ein eigener Nachrichtentyp hätte all das ein zweites
Mal gebraucht.

In der Anzeige unterscheiden sich die Blickrichtungen trotzdem deutlich von
`forward`/`backward`: doppelte Pfeile (`⇑`/`⇓`) statt einfacher und die
Beschriftung „BLICK HOCH“ / „BLICK RUNTER“. Zwei gleiche Pfeile mit
verschiedener Bedeutung wären auf einem Display, auf das jemand im Gehen kurz
schaut, der schlechteste Fall.

**Richtung:** Zuschauer → Guide · **Kanal:** `control`

```json
{ "v": 2, "type": "move", "dir": "forward", "seq": 1 }
```

**Sequenznummern** beginnen bei jedem Call wieder bei `1` und werden je
gesendetem Befehl um eins erhöht. Der Guide merkt sich die höchste bereits
ausgeführte Nummer und lehnt alles ab, was nicht größer ist — eine Wiederholung
oder ein Rückschritt wird also nie ein zweites Mal ausgeführt.

Beim Guide löst ein ausgeführter Befehl zwei Dinge aus:

* das zugehörige Audiosignal (`assets/audio/`, Hauptsignal beim Gehen),
* die **große Richtungsanzeige**: bildschirmfüllend über dem Videobild, ein
  Pfeil und ein Wort, hoher Kontrast, nach 1,4 s wieder weg. Sie ist die zweite
  Spur für laute Umgebungen und ohne Hinsehen erfassbar.

Jeder `move`, der die Prüfung aus Abschnitt 5 besteht, wird beantwortet — siehe
`ack`. Ein `move`, der schon an der Prüfung scheitert, wird nur verworfen und
protokolliert; der Sender läuft dann in die Frist aus Abschnitt 4.3.

---

### 4.3 `ack`

Bestätigung. Genau eine je empfangenem `move`, ausgeführt oder abgelehnt.

| Feld | Typ | Pflicht | Werte |
|---|---|---|---|
| `seq` | Ganzzahl | ja | die `seq` des bestätigten `move` |
| `status` | Zeichenkette | ja | `"executed"` \| `"rejected"` |
| `reason` | Zeichenkette | nein | nur bei `"rejected"`, siehe unten |

**Richtung:** Guide → Zuschauer · **Kanal:** `control`

```json
{ "v": 2, "type": "ack", "seq": 1, "status": "executed" }
```

```json
{ "v": 2, "type": "ack", "seq": 2, "status": "rejected", "reason": "locked" }
```

Mögliche `reason`-Werte:

| Wert | Bedeutung | von diesem Client gesendet |
|---|---|---|
| `unstable` | Verbindung war im Moment des Empfangs nicht stabil (Abschnitt 6) | ja |
| `locked` | Der Guide hat die Steuerung gesperrt | ja |
| `duplicate` | `seq` war nicht größer als die zuletzt ausgeführte | ja |
| `no_role` | Der Empfänger kennt seine eigene Rolle nicht | nein, reserviert |
| `invalid` | Nachricht hat die Prüfung nicht bestanden | nein, reserviert |

Die beiden reservierten Gründe werden **akzeptiert**, aber von diesem Client
nicht gesendet: Wer eine Nachricht schon an der Prüfung aus Abschnitt 5 ablehnt,
hat keine verlässliche `seq`, auf die er sich beziehen könnte. Eine
Implementierung, die das anders löst, darf sie benutzen — deshalb stehen sie im
Schema.

**Wozu die Bestätigung.** Solange sie aussteht, ist das Steuerkreuz beim
Zuschauer gesperrt und ein weiterer Druck geht nicht raus. Ohne das würde der
Zuschauer bei Latenz nachdrücken, und der Guide bekäme drei Schritte statt
einem. Bleibt die Bestätigung länger als **2000 ms** aus, wird das Steuerkreuz
wieder freigegeben und ein Hinweis angezeigt — sonst wäre es nach einem
verlorenen `ack` dauerhaft tot.

Eine Bestätigung, deren `seq` nicht zum aktuell ausstehenden Befehl passt, wird
ignoriert. Eine verspätete alte Bestätigung darf keine neuere Sperre aufheben.

---

### 4.4 `control_lock`

Der Guide hält die Steuerung kurz an — etwa beim Überqueren einer Straße — und
gibt sie danach wieder frei.

| Feld | Typ | Pflicht | Werte |
|---|---|---|---|
| `locked` | Wahrheitswert | ja | `true` = gesperrt, `false` = frei |
| `reason` | Zeichenkette | nein | höchstens 120 Zeichen, wird dem Zuschauer angezeigt |

**Richtung:** Guide → Zuschauer · **Kanal:** `control`

```json
{ "v": 2, "type": "control_lock", "locked": true, "reason": "Straße" }
```

```json
{ "v": 2, "type": "control_lock", "locked": false }
```

Beim Zuschauer:

* deutlich sichtbarer Hinweis über dem Videobild,
* Steuerkreuz gesperrt,
* **während der Sperre wird kein `move` gesendet.**

Beim Guide:

* Ein trotzdem eintreffender `move` — etwa einer, der die Sperre gekreuzt hat —
  wird **nicht ausgeführt** und mit `ack` / `rejected` / `locked` beantwortet.

Die Sperre wird immer angewendet, auch unmittelbar nach einer Störung. Ein
verspätetes `locked: false` ist ungefährlich, weil Bewegungsbefehle in dieser
Zeit ohnehin nach Abschnitt 6 abgelehnt werden.

---

### 4.5 `video_state`

Meldet, ob das eigene Videobild gerade läuft. Ersetzt die früheren
`__video_on__` / `__video_off__`.

| Feld | Typ | Pflicht | Werte |
|---|---|---|---|
| `on` | Wahrheitswert | ja | `true` = Kamera sendet |

**Richtung:** beide · **Kanal:** `control`

```json
{ "v": 2, "type": "video_state", "on": false }
```

Der Empfänger blendet das Remote-Video ein bzw. aus und zeigt sonst den
Platzhalter.

**Wird gesendet:**

1. **Einmal beim Verbindungsaufbau**, zusammen mit [`hello`](#41-hello), sobald
   der Steuerkanal offen ist — mit dem Zustand, der dann gerade gilt. Ohne
   diese Meldung wüsste die Gegenseite bei einem Teilnehmer ohne Kamera gar
   nichts: Sie sähe eine schwarze Fläche, ohne zu erfahren, ob noch kein Bild
   angekommen ist oder gar keins kommt.
2. **Bei jedem Umschalten** der eigenen Kamera.

**Zweiter Weg ohne Protokoll.** Wer seine Kamera abschaltet, nimmt die Spur mit
`RTCRtpSender.replaceTrack(null)` vom Sender. Die Empfangsspur der Gegenseite
wird dadurch vom Browser selbst als `muted` gemeldet. Der Empfänger wertet
beides aus (`assets/js/rtc.js`, `bindRemoteVideoTrack`): Die Nachricht ist
schneller und ausdrücklich, das Stummwerden funktioniert auch dann, wenn der
Steuerkanal gerade nicht steht. Ohne den zweiten Weg bliebe in diesem Fall das
letzte Standbild stehen — nicht zu unterscheiden von einem eingefrorenen Bild.

---

### 4.6 `hangup`

Auflegen. Ersetzt das frühere `__hangup__`.

| Feld | Typ | Pflicht | Werte |
|---|---|---|---|
| `reason` | Zeichenkette | nein | höchstens 120 Zeichen |

**Richtung:** beide · **Kanal:** `control`

```json
{ "v": 2, "type": "hangup" }
```

Das Auflegen geht **zusätzlich** über den Signalisierungsweg des Servers raus
(`POST … type: "hangup"`). Der DataChannel erreicht den Gegenüber sofort, der
Server-Weg auch dann noch, wenn der Kanal schon tot ist. Der Empfänger wertet
das Auflegen nur einmal aus, egal auf welchem Weg es zuerst ankommt.

---

### 4.7 `chat`

Eine Textnachricht des Nutzers. Der einzige Typ auf dem Kanal `chat`.

| Feld | Typ | Pflicht | Werte |
|---|---|---|---|
| `text` | Zeichenkette | ja | höchstens 2000 Zeichen |

**Richtung:** beide · **Kanal:** `chat`

```json
{ "v": 2, "type": "chat", "text": "Kannst du kurz stehen bleiben?" }
```

Der Text wird als reiner Text dargestellt (`textContent`), nie als Markup. Er
löst unter keinen Umständen einen Steuerbefehl aus — auch dann nicht, wenn er
zufällig wie einer aussieht.

---

### 4.8 Binärframe (Datei)

Ein Frame, der kein Text ist, gilt auf dem Kanal `chat` als Datei und wird dem
Empfänger zum Download angeboten. Es gibt dafür keinen Envelope und keine
Metadaten; der Dateiname ist beim Empfänger fest.

Auf dem Kanal `control` wird ein Binärframe mit dem Code `not_text` verworfen.

> **Offen für Version 2:** Größenbegrenzung, Dateiname und Typ als eigene
> `file_meta`-Nachricht. Heute ist die Übertragung ungeprüft.

---

## 5. Prüfung eingehender Nachrichten

Jede eingehende Nachricht durchläuft
[`protocol.validate(raw, channel, ownRole)`](assets/js/protocol.js) — in dieser
Reihenfolge:

| # | Prüfung | Code bei Fehlschlag |
|---|---|---|
| 1 | Frame ist Text | `not_text` |
| 2 | höchstens 4096 Byte | `too_large` |
| 3 | gültiges JSON | `not_json` |
| 4 | ein Objekt, kein Array und kein `null` | `not_object` |
| 5 | `v` ist eine Ganzzahl und gleich der eigenen Version | `bad_version` |
| 6 | `type` ist eine Zeichenkette und steht in der Tabelle | `unknown_type` |
| 7 | Typ gehört auf den Kanal, auf dem er ankam | `wrong_channel` |
| 8 | eigene Rolle bekannt (nur bei richtungsgebundenen Typen) | `no_role` |
| 9 | Absender darf diesen Typ senden | `forbidden_direction` |
| 10 | alle Pflichtfelder da, alle Felder typrichtig und im erlaubten Bereich | `bad_field` |

Die Version wird **vor** dem Typ geprüft. Sonst würde ein gleichnamiger Typ
einer anderen Version fälschlich als bekannt durchgehen.

Nachschlagen des Typs geschieht über `hasOwnProperty`, damit geerbte Namen wie
`constructor` oder `toString` nicht als Nachrichtentyp gelten.

### Was mit einer abgelehnten Nachricht passiert

* Sie wird **verworfen** — nicht ausgeführt, nicht gepuffert, nicht nachgeholt.
* Sie wird **protokolliert**: Konsole mit Code, Klartextgrund und den ersten
  120 Zeichen des Frames, dazu ein Zähler (`state.control.rejected`) und der
  zuletzt aufgetretene Code (`state.control.lastRejectCode`).
* Sie wird **niemals als Chattext angezeigt.** Das gilt ausdrücklich auch für
  unbekannte Typen und unbekannte Versionen.
* Sie erzeugt **keine** Meldung in der Oberfläche. Ein Peer, der Unsinn
  schickt, darf das Fenster des Nutzers nicht zumüllen.

Ausgenommen ist der Sonderfall in Abschnitt 6: Ein formal gültiger `move`, der
wegen der Verbindungslage abgelehnt wird, erzeugt einen `ack` und einen Hinweis
— der Zuschauer soll wissen, warum nichts passiert ist.

Vor dem **Senden** läuft dieselbe Schemaprüfung. Was dabei durchfällt, geht gar
nicht erst auf die Leitung.

---

## 6. Verbindungslage und Steuerbefehle

Diese Regeln stammen aus der Arbeit an der Verbindungsstabilität und gelten
unverändert weiter. Sie liegen **vor** dem Protokoll: Ein Bewegungsbefehl wird
verworfen, bevor er das Protokoll überhaupt erreicht.

**Beim Senden** (`rtc.canSendControlCommand()`) wird ein `move` nur abgesetzt,
wenn alle Punkte zutreffen:

* ein Call ist aktiv,
* der sichtbare Verbindungszustand ist `connected`,
* die `RTCPeerConnection` ist tatsächlich nutzbar,
* der Steuerkanal ist offen,
* sein Sendepuffer ist kleiner als 4096 Byte.

Trifft etwas davon nicht zu, wird der Befehl **verworfen und nicht gepuffert**.
Der DataChannel ist zuverlässig und geordnet — alles, was während einer Störung
im Puffer landet, käme beim Wiederanlaufen auf einen Schlag beim Guide an.

**Beim Empfangen** (`rtc.mayExecuteControlCommand()`) wird ein `move` nur
ausgeführt, wenn der Call aktiv, der Zustand `connected` und die Verbindung seit
mindestens **1000 ms** wieder stabil ist. Was in diesem Fenster eintrifft, kann
aus der Zeit vor der Störung stammen. Es wird abgelehnt und mit
`ack` / `rejected` / `unstable` beantwortet.

Ein nicht ausgeführter Befehl ist harmlos, ein verspäteter nicht.

Für `ack`, `control_lock`, `video_state`, `hangup` und `hello` gilt diese Sperre
**nicht** — sie sind Zustandsmeldungen und müssen auch bei wackliger Verbindung
durchkommen. Eine Sperre, die den Guide nicht mehr erreicht, wäre das genaue
Gegenteil von sicher.

---

## 7. Ablauf eines Calls

```
Zuschauer (viewer)                Server                 Guide (guide)
      │                             │                          │
      │ POST offer ────────────────►│                          │
      │◄──────── {status,role:"viewer"}                        │
      │                             │──── offer (role:"guide") ►│
      │                             │                          │
      │◄════════ DataChannel "chat" und "control" stehen ══════►│
      │                                                        │
      │ ──── {v:2,type:"hello",role:"viewer"} ────────────────► │
      │ ◄─── {v:2,type:"hello",role:"guide"}  ───────────────── │
      │                                                        │
      │ ──── {v:2,type:"move",dir:"forward",seq:1} ──────────► │  Ton + Anzeige
      │ ◄─── {v:2,type:"ack",seq:1,status:"executed"} ───────── │
      │                                                        │
      │ ◄─── {v:2,type:"control_lock",locked:true,             │  Guide sperrt
      │       reason:"Straße"} ──────────────────────────────── │
      │   (Steuerkreuz gesperrt, kein move)                     │
      │ ◄─── {v:2,type:"control_lock",locked:false} ─────────── │
      │                                                        │
      │ ──── {v:2,type:"move",dir:"left",seq:2} ─────────────► │
      │ ◄─── {v:2,type:"ack",seq:2,status:"executed"} ───────── │
      │                                                        │
      │ ──── {v:2,type:"move",dir:"look_up",seq:3} ──────────► │  nur der Blick
      │ ◄─── {v:2,type:"ack",seq:3,status:"executed"} ───────── │
      │                                                        │
      │ ──── {v:2,type:"hangup"} ───────────────────────────► │
```

---

## 8. Zustand je Call

Was ein Client mitführen muss, und was beim Call-Ende zurückgesetzt wird:

| Größe | Wer | Bedeutung |
|---|---|---|
| `callRole` | beide | `"guide"`, `"viewer"`, `"peer"` oder `null` |
| `nextSeq` | Zuschauer | nächste zu vergebende Sequenznummer, beginnt bei 1 |
| `pendingSeq` | Zuschauer | Befehl, dessen Bestätigung aussteht, sonst `null` |
| `lastRemoteSeq` | Guide | höchste bereits ausgeführte Sequenznummer, beginnt bei 0 |
| `locked` | beide | Steuerung gesperrt |
| `helloSent` | beide | wurde die eigene Rolle schon gemeldet |

Alles davon ist **call-lokal**. Nach dem Auflegen wird es geleert, damit keine
alte Sequenznummer und keine hängende Sperre in den nächsten Call laufen.

---

## 9. Grenzen dieser Version

Bewusst nicht enthalten, für eine spätere Version vorgemerkt:

* **Stopp, Geschwindigkeit, Schrittweite, Dauer.** `move` trägt nur eine
  Richtung.
* **Zielpunkt-Markierung** auf dem Videobild.
* **Steuerungsanfrage.** Der Zuschauer kann nicht um Freigabe bitten; nur der
  Guide sperrt und gibt frei.
* **Mehr als zwei Teilnehmer.** Das Protokoll und die darunterliegende
  Anwendung sind strikt 1:1.
* **Empfangsseitige Ratenbegrenzung.** Ein manipulierter Peer kann beliebig
  viele gültige `move`-Nachrichten schicken; begrenzt wird bisher nur beim
  Sender (100 ms Sperre je Schaltfläche, dazu die ausstehende Bestätigung).
* **Dateien**: keine Größen-, Typ- oder Anzahlbegrenzung, keine Metadaten.

---

## 10. Dateien

| Datei | Rolle |
|---|---|
| `assets/js/protocol.js` | Version, Nachrichtentabelle, Prüfung. Einzige Quelle der Wahrheit im Client. |
| `assets/js/control.js` | Steuerkanal: Senden, Empfangen, Bestätigung, Sperre, Richtungsanzeige |
| `assets/js/chat.js` | Chatkanal: Text und Dateien |
| `assets/js/rtc.js` | Anlegen und Zuordnen der beiden Kanäle, Verbindungssperre |
| `class/Controller/WebRTCController.php` | Rollenvergabe, Prüfung der Standortkennung, Stempeln am Offer |
| `class/Model/WebRTCHandler.php` | Signalzeilen samt `location_id` |
| `assets/js/media.js` | Wer sendet (`maySendMedia`), Spuren an die Sender legen |
| `tests/client_test.js` | Prüfungen 15–22 und 37 decken dieses Protokoll ab |
| `tests/server_test.php` | Prüfung 5 deckt die Rollenvergabe ab |
