# Entscheidungsleitfaden: die mobile App

Dieses Dokument beantwortet **eine** Frage: Auf welchem Weg entsteht aus dieser
Webanwendung eine App auf dem Handy des Guides — und was kostet jeder Weg?

Es trifft die Entscheidung nicht. Es legt sie so hin, dass sie sich in einem
Nachmittag treffen lässt, statt in drei Wochen Vorarbeit.

**Stand:** 18. September 2026, Protokollversion 2, Branch `main`.
**Geschrieben für:** den Nachfolger, der die Anwendung kennt (sonst zuerst
[`UEBERGABE.md`](UEBERGABE.md)), aber weder den Protokollstand noch die
Store-Regeln im Kopf hat.

**Was dieses Dokument nicht ist:** kein Architekturentwurf, kein Pflichtenheft,
keine Aufwandszusage. Die Zahlen in Abschnitt 2 und 9 sind Schätzungen mit
einer Fehlerbreite, die dort auch dabeisteht. Abschnitt 10 sammelt alles, was
ich nicht weiß, an einer Stelle — **lies ihn, bevor du planst, nicht danach.**

---

## Inhalt

1. [Die Ausgangslage](#1-die-ausgangslage)
2. [Die drei Wege](#2-die-drei-wege)
3. [Was für diese Anwendung besonders zählt](#3-was-für-diese-anwendung-besonders-zählt)
4. [Was bereitliegt — und in welcher Reihenfolge eine App es anspricht](#4-was-bereitliegt--und-in-welcher-reihenfolge-eine-app-es-anspricht)
5. [Was neu gebaut werden muss](#5-was-neu-gebaut-werden-muss)
6. [Die Fallstricke aus dem Web, die mobil wiederkommen](#6-die-fallstricke-aus-dem-web-die-mobil-wiederkommen)
7. [Store-Veröffentlichung](#7-store-veröffentlichung)
8. [Die Empfehlung](#8-die-empfehlung)
9. [Aufwand im Überblick](#9-aufwand-im-überblick)
10. [Was ich nicht weiß](#10-was-ich-nicht-weiß)

---

## 1. Die Ausgangslage

Drei Tatsachen bestimmen alles, was danach kommt:

**Erstens: Es sind zwei verschiedene Geräte mit zwei verschiedenen Problemen.**
Der **Zuschauer** sitzt zu Hause, meist am großen Bildschirm, und sendet
*nichts* — kein Bild, kein Ton (`media.maySendMedia()`, `PROTOKOLL.md`
Abschnitt 3). Er drückt Tasten und schaut. Der **Guide** läuft draußen herum,
hält das Gerät in der Hand, sendet Bild und Ton und bekommt Anweisungen über
Töne und eine große Richtungsanzeige.

Für den Zuschauer ist eine App fast überflüssig. Für den Guide ist sie der
ganze Punkt. **Wer „die App" plant, plant in Wahrheit die Guide-App** — und
sollte das früh trennen, statt eine Anwendung zu bauen, die beides halb kann.

**Zweitens: Der Fachablauf ist fertig und läuft.** Anfragen, Rollenvergabe,
Führung, Beenden, Bewertung — alles steht, ist getestet und ist dokumentiert.
Die App muss nichts davon neu erfinden; sie muss es *ansprechen*.

**Drittens: Der Transport ist nicht fertig.** Signaling über HTTP-Polling,
Anmeldung über ein PHP-Sitzungscookie, keine Benachrichtigung von außen. Das
sind genau die drei Dinge, die auf einem Handy anders sein müssen — und sie
liegen alle auf dem **Server**, nicht in der App.

Daraus folgt der wichtigste Satz dieses Dokuments:

> **Ein großer Teil der Arbeit ist von der Wegentscheidung unabhängig.**
> Token-Anmeldung, ein Signalisierungsweg, der ein Handy übersteht, und eine
> Push-Zustellung braucht *jeder* der drei Wege. Wer zuerst diese Vorarbeit
> macht, verliert nichts, egal wie er sich später entscheidet — und kann die
> Entscheidung mit Messwerten statt mit Vermutungen treffen.

---

## 2. Die drei Wege

Kurzfassung zuerst, Begründung darunter.

| | PWA | Plattformübergreifend | Nativ |
|---|---|---|---|
| **Wiederverwendung Client** | ~90 % | ~10 % (Protokoll-Logik) | ~0 % (nur als Vorlage) |
| **Wiederverwendung Server** | 100 % | 100 % | 100 % |
| **Aufwand Client** | 2–4 Wochen | 10–16 Wochen | 16–26 Wochen (2 Plattformen) |
| **Aufwand Server (gemeinsam)** | 6–10 Wochen | 6–10 Wochen | 6–10 Wochen |
| **Push für Anfragen** | eingeschränkt | ja | ja |
| **Klingeln wie ein Telefon** | nein | ja | ja |
| **Call im Hintergrund / bei Bildschirmsperre** | nein | ja | ja |
| **Kamerawechsel zuverlässig** | wackelig auf iOS | ja | ja |
| **Store-Pflicht** | nein | ja | ja |
| **Zwei Codebasen für iOS/Android** | nein | nein | ja |

Die Aufwände sind **grobe Schätzungen für eine erfahrene Person**, ohne
Store-Verfahren, ohne Moderationswerkzeuge und ohne Bezahlung. Die Fehlerbreite
ist nach oben offen: Der Posten „Server" hat mit dem Push-Teil eine Unbekannte,
die je nach gewähltem Dienst zwischen zwei Wochen und zwei Monaten liegt.

---

### 2.1 Der erste Weg: installierbare PWA

**Was das heißt.** Die bestehende Webanwendung bekommt ein Manifest, ein
Symbol, einen Service Worker und einen Installationshinweis. Der Nutzer legt
sie auf den Startbildschirm; sie startet ohne Browserleiste und sieht aus wie
eine App. Kein Store, keine zweite Codebasis, kein zweiter Client.

**Aufwand.** 2–4 Wochen für die Hülle: Manifest, Symbole in allen geforderten
Größen, Service Worker (mindestens für die Offline-Seite und die statischen
Mittel), Installationsführung, Anpassung der Ansichten an schmale Displays,
Bildschirmsperre verhindern (Screen Wake Lock), Test auf echten Geräten.

Dazu die Server-Vorarbeit aus Abschnitt 5, von der die PWA **das Wenigste**
braucht: Die Anmeldung über das Sitzungscookie funktioniert weiter, weil eine
PWA im Browserkontext läuft. Polling bleibt möglich, ist aber der Akkufresser
aus Abschnitt 3.

**Was wiederverwendbar ist.** Fast alles. `rtc.js`, `media.js`, `control.js`,
`protocol.js`, `chat.js`, `signaling.js`, die Ansichten, die Sprachkataloge,
die Rechteprüfung, das Erscheinungsbild — es ist dieselbe Anwendung. Genau das
ist der Grund, warum dieser Weg zuerst kommt: **Er kostet am wenigsten und sagt
am meisten darüber, ob die übrigen Probleme überhaupt welche sind.**

**Die Grenzen — und sie sind hart:**

* **Kein Anruf im Hintergrund.** Sperrt der Guide den Bildschirm oder wechselt
  er die App, hält iOS die Seite an. Die Kamera liefert nichts mehr, die Timer
  stehen, die Verbindung bricht ab. Android ist großzügiger, aber nicht
  verlässlich. Für eine Führung, in der jemand zwanzig Minuten läuft, heißt
  das: **Der Bildschirm muss an bleiben und die App im Vordergrund.** Screen
  Wake Lock hält den Bildschirm wach, aber es hindert niemanden daran, die App
  zu verlassen oder den Sperrknopf zu drücken.
* **Kein Klingeln.** Web Push gibt es auf iOS seit 16.4 — aber nur für eine
  Anwendung, die der Nutzer *ausdrücklich* auf den Startbildschirm gelegt hat,
  und nur als gewöhnliche Mitteilung. Es gibt keinen Vollbild-Anrufbildschirm,
  keinen Klingelton über die Bildschirmsperre hinweg, keine Priorität vor
  anderen Mitteilungen. Eine Anfrage, die nachts eintrifft, ist eine
  Mitteilung unter anderen.
* **Der Installationsschritt ist eine Hürde.** Auf iOS muss der Nutzer über das
  Teilen-Menü „Zum Home-Bildschirm" wählen — es gibt keinen
  Installations-Knopf, den man ihm hinstellen kann. Ein messbarer Teil der
  Nutzer kommt dort nicht an. Wer Web Push auf iOS will, braucht diesen
  Schritt trotzdem.
* **Kamerawechsel auf iOS ist wackelig.** Siehe Abschnitt 6.2 — das ist kein
  PWA-Problem im engeren Sinn, sondern ein Safari-Problem, und es trifft die
  PWA voll.
* **Sie ist nicht im Store.** Das ist je nach Sichtweise Grenze oder Vorteil.
  Kein Prüfverfahren, keine Altersfreigabe, keine 30 % — aber auch keine
  Auffindbarkeit und keine Glaubwürdigkeit durch einen Store-Eintrag.

**Für wen dieser Weg reicht.** Für den **Zuschauer**: vollständig. Er sendet
nichts, er läuft nicht herum, sein Gerät steht auf dem Tisch. Für den **Guide**:
nur, wenn er die App bewusst offen hält und den Bildschirm nicht sperrt —
das ist eine Zumutung, aber keine unmögliche. Ob sie tragbar ist, sagt ein
Feldtest mit drei echten Guides in einer Woche. Keine Schätzung ersetzt ihn.

---

### 2.2 Der zweite Weg: plattformübergreifend (React Native, Flutter)

**Was das heißt.** Eine Codebasis, zwei Apps. Die Oberfläche wird neu gebaut,
die WebRTC-Anbindung kommt aus einer Bibliothek (`react-native-webrtc` bzw.
`flutter_webrtc`), die beide unter der Haube dasselbe `libwebrtc` benutzen wie
der Browser.

**Aufwand.** 10–16 Wochen für den Client. Was darin steckt:

* die Oberfläche komplett neu — Karte, Standortseite, Anfragenliste,
  Annahmedialog, Call-Ansicht mit Steuerkreuz und Richtungsanzeige, Chat,
  Einstellungen, Guide-Profil, Bewertungen;
* die Protokollschicht aus `protocol.js` nach TypeScript/Dart übersetzt —
  das ist überschaubar, weil die Tabelle in `PROTOKOLL.md` vollständig ist;
* die Zustandslogik aus `rtc.js` und `control.js` nachgebaut: Sequenznummern,
  Bestätigungsfristen, Sperre, Grace-Timer, ICE-Restart;
* der Medienpfad neu — Berechtigungen, Kameraauswahl, Vordergrunddienst;
* Push-Anbindung, Klingelbildschirm (CallKit auf iOS,
  Vollbild-Benachrichtigung auf Android);
* zwei Store-Verfahren.

**Was wiederverwendbar ist.** Vom Client: die *Struktur*, nicht der Code. Die
Protokolltabelle, die Zustandsgrößen aus `PROTOKOLL.md` Abschnitt 8, die
Konstanten aus `rtc.js` (5 s Grace, 30 s Frist, 3 Restarts, 1 s Settle, 4096
Byte Puffer, 2000 ms Bestätigungsfrist) und die Begründungen in den
Kommentaren. Das ist mehr wert, als es klingt: **Jede dieser Zahlen ist aus
einem Fehler entstanden**, und wer sie übernimmt, spart sich den Fehler.

Vom Server: alles. Dieselben Routen, dieselbe Rollenvergabe, dieselbe
Datenbank.

**Die Grenzen:**

* **Die Bibliothek ist die Abhängigkeit, die man nicht kontrolliert.** Beide
  WebRTC-Bibliotheken sind lebendig und gut gepflegt, aber sie hinken
  `libwebrtc` hinterher, und sie bilden nicht jede Eigenschaft ab. Welche
  genau, steht in Abschnitt 6 — insbesondere `setStreams()` und
  `bufferedAmount` sind zu prüfen, *bevor* der Weg gewählt wird. Das ist eine
  Recherche von einem Tag und sie kann die Entscheidung kippen.
* **Bei einem Fehler in der Brücke sitzt man fest.** Ein Bug zwischen
  JavaScript/Dart und dem nativen WebRTC-Stack ist schwer zu diagnostizieren
  und man kann ihn nicht selbst beheben — außer man geht in die Bibliothek.
* **Die Oberfläche ist trotzdem zweimal zu testen.** „Eine Codebasis" heißt
  nicht „ein Testlauf". Kamera, Berechtigungen, Hintergrundverhalten und
  Klingeln unterscheiden sich pro Plattform so stark, dass genau der Teil, um
  den es hier geht, ohnehin plattformweise gebaut wird.
* **Zwei Clients, ein Protokoll.** Ab dem Tag, an dem die App existiert, muss
  jede Protokolländerung in zwei Implementierungen. Die exakte Versionsprüfung
  (Abschnitt 6.5) macht daraus einen Bruch, keinen Übergang.

**Wann dieser Weg der richtige ist.** Wenn die App gebraucht wird, das Budget
für zwei native Apps aber nicht da ist — also im Regelfall. Von den drei Wegen
ist er der pragmatischste, und der Abstand zu „nativ" ist bei einer 1:1-Video-
anwendung kleiner, als der Ruf es nahelegt: Der teure Teil (WebRTC, Kamera,
Hintergrund, Push) liegt in beiden Fällen in nativen Bibliotheken.

---

### 2.3 Der dritte Weg: nativ (Swift/Kotlin)

**Was das heißt.** Zwei Apps, zwei Sprachen, zwei Teams oder eine Person
zweimal. WebRTC direkt über `libwebrtc` (`WebRTC.framework` bzw. das
Android-Artefakt), CallKit und PushKit auf iOS, `ConnectionService` und
Vordergrunddienst auf Android.

**Aufwand.** 16–26 Wochen für beide Plattformen. Der Aufschlag gegenüber 2.2
ist nicht die doppelte Oberfläche allein — er ist die doppelte *Pflege*: zwei
Release-Zyklen, zwei Abhängigkeitsbäume, zwei Wege, auf denen ein
Betriebssystem-Update etwas kaputtmacht.

**Was wiederverwendbar ist.** Vom Client nichts außer `PROTOKOLL.md` als
Vorlage. Das steht schon in `UEBERGABE.md` und bleibt richtig.

**Was dieser Weg dafür kann, und nur er ganz:**

* **Die Anrufannahme wie beim Telefon.** CallKit gibt dem eingehenden Anruf den
  Systembildschirm: Es klingelt über die Bildschirmsperre, der Anruf steht in
  der Anrufliste, Bluetooth und Freisprechen funktionieren ohne Zutun. Für eine
  Anwendung, bei der der Guide das Gerät in der Tasche hat, ist das der
  Unterschied zwischen „Führung kommt zustande" und „Führung kommt nicht
  zustande".
* **Verlässliches Verhalten im Hintergrund.** Ein Vordergrunddienst mit dem
  richtigen Typ (Android) bzw. der VoIP-Hintergrundmodus (iOS) hält den Call
  am Leben, wenn der Guide die App verlässt oder das Display abschaltet.
* **Sensorik und Feinheiten.** Nähe-Sensor, Lautstärkeregelung als Anruf statt
  als Medien, Audio-Routing beim Aufstecken von Kopfhörern, Unterdrückung des
  Kamerawechsel-Flackerns — lauter Kleinigkeiten, die zusammen den Unterschied
  zwischen „Prototyp" und „Produkt" ausmachen.
* **Die volle Kontrolle über WebRTC.** Bitrate, Codec-Wahl,
  Hardware-Encoder, Umgang mit thermischer Drosselung. Bei einem Guide, der
  bei 30 Grad in der Sonne eine Stunde filmt, ist das kein Luxus.

**Die Grenzen:** Kosten und Zeit. Sonst nichts.

**Wann dieser Weg der richtige ist.** Wenn die Führung das Produkt ist und der
Guide bezahlt wird — dann ist sein Gerät Arbeitsmittel, und Arbeitsmittel
dürfen nicht „meistens" funktionieren. Bis dahin: nicht.

---

## 3. Was für diese Anwendung besonders zählt

Die allgemeine Abwägung oben gilt für jede App. Hier steht, was *diese*
Anwendung besonders macht — und das ist der Teil, an dem die Entscheidung
tatsächlich hängt.

### 3.1 WebRTC auf Mobilgeräten

Das Protokoll läuft. `libwebrtc` ist unter allen drei Wegen dieselbe
Bibliothek, und eine 1:1-Verbindung mit zwei Spuren und zwei DataChannels ist
für sie der einfachste denkbare Fall. **Das ist nicht das Problem.**

Die Probleme liegen daneben:

* **TURN wird auf dem Handy zur Regel, nicht zur Ausnahme.** Mobilfunknetze
  arbeiten fast durchgängig mit Carrier-Grade NAT; eine direkte Verbindung
  zwischen zwei Mobilfunkteilnehmern kommt selten zustande. Heute liefert
  `get_turn_credentials` bei einem Ausfall STUN-Fallbacks und meldet
  `turnAvailable: false` — im Browser hinter einem gewöhnlichen Heimrouter ist
  das oft noch brauchbar. **Auf dem Handy ist es das nicht.** Wer die App baut,
  braucht TURN als zugesicherten Dienst, nicht als Kontingent (siehe
  `UEBERGABE.md` 5.1) — und er braucht eine ehrliche Meldung in der App, wenn
  es fehlt, statt eines Anrufs, der einfach nicht zustande kommt.
* **Netzwechsel sind der Normalfall.** Der Guide läuft, das Handy wechselt
  Funkzellen und pendelt zwischen WLAN und Mobilfunk. `rtc.js` hat dafür den
  vollständigen Apparat (5 s Grace, ICE-Restart, 3 Versuche, 30 s Frist), und
  er ist gut. Was der Browser abnimmt und eine native App selbst tun muss: den
  Wechsel *mitbekommen* und den Restart auslösen, statt auf den Timer zu
  warten. Das ist ein kleiner Gewinn an Code und ein großer an Gefühl.
* **Die Kamera ist die heißeste Komponente im Gerät.** Dazu Abschnitt 3.5.

### 3.2 Hintergrundverhalten — hier trennen sich die Wege

Das ist neben Push der zweite Punkt, an dem PWA und nativ auseinandergehen, und
er ist unterschätzt.

**Was passiert, wenn der Guide den Bildschirm sperrt:**

| | PWA (iOS) | PWA (Android) | Nativ / plattformübergreifend |
|---|---|---|---|
| Kamera sendet weiter | nein | nein | ja (mit Vordergrunddienst) |
| Ton läuft weiter | nein | eingeschränkt | ja |
| Timer laufen weiter | nein | gedrosselt | ja |
| Verbindung überlebt | meist nicht | oft nicht | ja |

**Und jetzt der Teil, der in keinem Handbuch steht, weil er aus *dieser*
Anwendung kommt:** Die Anwendung verwirft Steuerbefehle, die nach einer
Unterbrechung eintreffen — 1000 ms lang, gerechnet ab `state.connectedSince`
(`rtc.mayExecuteControlCommand()`). Diese Sperre schützt den Guide davor, dass
ihn ein Schwall gepufferter Bewegungsbefehle auf einen Schlag durch die Gegend
schickt.

Sie greift, **wenn die Verbindung sichtbar gestört war**. Wird die App dagegen
vom Betriebssystem nur *eingefroren* — Bildschirm gesperrt, App gewechselt —
und die ICE-Verbindung überlebt das, dann ändert sich `connectionStatus` nicht,
`connectedSince` bleibt alt, und beim Aufwachen laufen die gepufferten Befehle
**ohne** Settle-Fenster durch. Der Guide bekommt sechs Anweisungen in einer
Sekunde.

Ich weiß nicht, ob das in der Praxis eintritt — es hängt daran, ob eine
eingefrorene Seite ihre ICE-Verbindung lange genug hält. **Es ist zu messen,
nicht zu vermuten.** Wenn es eintritt, ist die Abhilfe klein und gehört in
jeden Client: zusätzlich zur Verbindungslage die *Sichtbarkeit* auswerten und
`connectedSince` beim Zurückkehren in den Vordergrund neu setzen. Auf einer
nativen Plattform ist die Entsprechung der Lebenszyklus-Rückruf.

Das gehört in `PROTOKOLL.md`, sobald es geklärt ist. Heute steht dort nichts
zum Wechsel in den Hintergrund — das ist in `UEBERGABE.md` bereits als Lücke
vermerkt, und dies ist der konkrete Fall dazu.

### 3.3 Kamerawechsel

Heute wechselt die Anwendung die Kamera über `enumerateDevices()` und eine
gemerkte `deviceId` (`media.switchDevice`, `media.rememberDevice`). Auf dem
Desktop ist das richtig: Dort haben Kameras Namen, und der Nutzer wählt eine
aus einer Liste.

**Auf dem Handy ist es der falsche Ansatz**, aus drei Gründen:

1. **Der Nutzer will keine Liste, er will umschalten.** „Vorne/hinten" ist ein
   Knopf, kein Auswahlfeld. Im Web heißt dieser Knopf `facingMode`
   (`user`/`environment`); die Anwendung benutzt ihn heute nirgends.
2. **`deviceId` ist auf iOS nicht stabil.** Safari vergibt die Kennungen pro
   Sitzung neu. Eine gemerkte Kennung von gestern trifft heute nichts. Die
   Anwendung fängt das ab — `acquireTrack` erkennt den Fehler und holt
   ersatzweise *irgendeine* Kamera (`isDeviceConstraintError`) —, aber
   „irgendeine" ist beim Wechsel zwischen Front- und Rückkamera die falsche
   Antwort: Der Guide drückt auf „Rückkamera" und bekommt sein Gesicht.
3. **Ohne erteilte Freigabe sind die Namen leer.** Das ist behandelt (es werden
   Nummern angezeigt), aber „Kamera 1 / Kamera 2" ist für den, der gerade
   entscheiden soll, keine Auskunft.

Dazu kommt die Reihenfolge in `switchDevice`: Erst wird die **neue** Spur
geholt, dann umgehängt, dann die alte gestoppt. Das ist bewusst so — bei einem
Fehlschlag wäre sonst beides weg, und die Begründung steht im Code. **Auf iOS
ist genau diese Reihenfolge der Problemfall:** Das Gerät gibt in der Regel
keine zweite Kamera frei, solange die erste läuft. Der Wechsel schlägt dann
fehl, obwohl er möglich wäre.

Für eine App heißt das: Der Kamerawechsel ist **neu zu bauen**, nicht zu
portieren — als Umschaltknopf, mit „alte Spur anhalten, dann neue holen" auf
iOS, und mit einem Rückweg, der die alte Kamera wiederholt, wenn die neue nicht
kommt. Nativ ist es der bequemste dieser Fälle: Dort ist der Kamerawechsel eine
Zeile in der Aufnahmequelle, ohne `getUserMedia` und ohne `replaceTrack`.

### 3.4 Berechtigungen

Im Browser ist eine Berechtigung ein Dialog, der beim ersten `getUserMedia`
kommt und den man wiederholen kann. In einer App ist sie ein Systemdialog, den
es **einmal** gibt: Wer „Nein" sagt, wird nie wieder gefragt — er muss in die
Systemeinstellungen.

Das ändert den Umgang grundlegend:

* **Vorher erklären, dann fragen.** Eine App, die beim ersten Start nach Kamera,
  Mikrofon, Standort und Mitteilungen fragt, verliert einen Teil der Nutzer
  sofort. Gefragt wird an dem Punkt, an dem die Berechtigung gebraucht wird —
  Kamera und Mikrofon beim ersten Annehmen, Mitteilungen bei der ersten
  Anfrage.
* **Jeder Berechtigungstext ist Store-Material.** Apple verlangt für Kamera,
  Mikrofon und Standort jeweils eine Begründung im App-Bundle; sie wird im
  Dialog angezeigt und im Prüfverfahren gelesen. Ein Platzhalter dort ist ein
  Ablehnungsgrund.
* **Der Weg zurück muss in der App stehen.** „Du hast die Kamera abgelehnt,
  hier geht es zu den Einstellungen" — im Browser gibt es das nicht, in einer
  App muss es das geben.
* **Der Zuschauer wird nichts davon gefragt.** Das ist heute schon so
  (`maySendMedia`) und bleibt ein echter Vorteil: Ein Zuschauer, der nie nach
  seiner Kamera gefragt wird, ist ein Zuschauer, der die Anwendung nicht
  abbricht. **Die App darf diesen Vorteil nicht verspielen**, indem sie aus
  Gewohnheit beide Spuren anfordert.

### 3.5 Akku

Hier sind die Zahlen, die heute gelten:

| Was | Takt | Aufrufe je Stunde |
|---|---|---|
| Signaling-Polling, im Ruhezustand | 1500 ms | 2400 |
| Signaling-Polling, im Call | 3000 ms | 1200 |
| Heartbeat (Anwesenheit) | 10 s | 360 |
| Anfragenliste (nur sichtbarer Tab) | 15 s | 240 |

Im Leerlauf — App offen, niemand ruft an — sind das **rund 2800 HTTP-Anfragen
pro Stunde**. Jede davon weckt das Funkmodul. Auf einem Desktop ist das
unschön; auf einem Handy ist es der größte Akkuposten außerhalb des Calls
selbst, und er fällt an, *ohne dass irgendetwas passiert*.

Das ist kein App-Problem, sondern ein Serverproblem, und es steht in
`UEBERGABE.md` schon als „größte bekannte Schwäche der Architektur". Für die App
wird es zum Ausschlusskriterium: **Ein Guide, der auf „bereit" steht und auf
Anfragen wartet, kann nicht pollen.** Er braucht eine Verbindung, die schläft
(WebSocket), oder eine Zustellung von außen (Push) — und im Regelfall beides.

Der zweite Akkuposten ist der Call selbst, und den bekommt man nicht weg:
Kamera, Encoder, Funk und Bildschirm gleichzeitig, bei einer Führung eine
halbe bis eine Stunde lang. Realistisch sind **15–25 % Akku pro halber Stunde**,
je nach Gerät und Sonne, dazu spürbare Wärme und ab einem gewissen Punkt eine
thermische Drosselung, die die Bildrate senkt. Eine App kann das mildern
(niedrigere Auflösung als Voreinstellung, Hardware-Encoder, Bildschirm dunkler
schalten, wenn der Guide ihn nicht braucht — er braucht ihn meistens nicht, er
hört ja die Anweisungen); die PWA kann das alles nicht.

Daraus folgt eine Produktfrage, die niemand bisher gestellt hat: **Wie lange
soll eine Führung dauern?** Die Antwort bestimmt, ob eine Powerbank Zubehör
oder Voraussetzung ist. `config/requests.php` sagt: Das Anruffenster reicht bis
zwei Stunden nach dem Wunschzeitpunkt. Zwei Stunden Führung am Stück überlebt
kein heutiges Telefon.

### 3.6 Push für Anfragen — hier liegt der eigentliche Unterschied

Alles bisher war eine Abwägung. Dieser Punkt ist eine Entscheidung.

**Das Problem, konkret an dieser Anwendung.** Eine Führung beginnt mit einer
Anfrage (`request_create`). Der Guide sieht sie heute, wenn er die Seite offen
hat — der Zähler in der Kopfleiste, die Anfragenliste im 15-Sekunden-Takt. Hat
er die Seite nicht offen, sieht er sie beim nächsten Besuch. `response_timeout`
gibt ihm eine Stunde. **Eine Anfrage, die eintrifft, während die App
geschlossen ist, erreicht heute niemanden** — das steht schon in
`UEBERGABE.md`, und es ist der Grund, warum die Anwendung als Produkt bisher
nicht funktionieren kann: Der Kunde fragt, niemand antwortet, der Kunde kommt
nicht wieder.

Und der zweite Fall ist schärfer: Nach einer angenommenen Anfrage **ruft der
Kunde an**. Der Klingelton läuft im Browser des Guides — wenn der Browser offen
ist. Sonst klingelt niemand, und das Anruffenster läuft ab.

**Was die drei Wege hier können:**

| | Anfrage (kann warten) | Anruf (klingelt jetzt) |
|---|---|---|
| **PWA, iOS** | Web Push ab iOS 16.4, **nur wenn installiert**; gewöhnliche Mitteilung | keine Chance — kein Vollbild, kein Ton über die Sperre, kein Klingeln |
| **PWA, Android** | Web Push, funktioniert | Mitteilung mit Ton; kein Anrufbildschirm, kein Durchbruch bei „Nicht stören" |
| **Nativ / plattformübergreifend, iOS** | APNs, gewöhnliche Mitteilung | PushKit + CallKit: klingelt wie ein Telefon |
| **Nativ / plattformübergreifend, Android** | FCM | FCM mit hoher Priorität + Vollbild-Benachrichtigung + Vordergrunddienst |

**Die Zeile, auf die es ankommt, ist die erste rechte.** Für den *Anruf* gibt
es auf iOS in einer PWA keinen Weg, der wie ein Anruf wirkt. Nicht „schlechter",
sondern: gar nicht. Wenn die Anwendung so funktionieren soll, dass ein Kunde
jemanden anruft und der Guide das Gerät aus der Tasche zieht, dann **braucht sie
auf iOS eine Store-App**, und alles andere in diesem Dokument ist nachgeordnet.

**Der Ausweg, wenn man den Anruf nicht klingeln lassen kann.** Man kann den
Ablauf so bauen, dass der spontane Anruf gar nicht vorkommt: Anfragen mit
Zeitpunkt (gibt es), eine Mitteilung „in 10 Minuten geht es los" (gibt es
nicht), und der Guide öffnet die App von sich aus. Damit verlöre man „jetzt
sofort" als Weg — der aber heute ausdrücklich vorgesehen ist
(`config/requests.php`, `wish_grace`). **Das ist eine Produktentscheidung, keine
technische**, und sie gehört neben 6.1 und 6.2 in `UEBERGABE.md`.

**Und eine Warnung zu CallKit, bevor sie Geld kostet:** Seit iOS 13 muss eine
App, die einen VoIP-Push empfängt, *unverzüglich* einen eingehenden Anruf bei
CallKit melden. Tut sie es nicht — etwa weil der Push in Wahrheit „neue Anfrage"
hieß und gar kein Anruf ist —, beendet das System die App, und nach mehreren
Verstößen werden die VoIP-Pushes ganz eingestellt. Für diese Anwendung heißt
das: **zwei getrennte Zustellwege.** Anfragen über gewöhnliches APNs, Anrufe
über PushKit. Wer beides über einen Weg schickt, baut sich eine Falle, die erst
in Produktion zuschnappt.

---

## 4. Was bereitliegt — und in welcher Reihenfolge eine App es anspricht

### 4.1 Die drei Stücke, nach denen gefragt wurde

**`PROTOKOLL.md` — verbindlich, vollständig, benutzbar.** 693 Zeilen, die
Rahmenformat, Versionierung, Rollen, alle acht Nachrichtentypen, die zehn
Prüfschritte, die Verbindungsregeln, den Ablauf und den Zustand je Call
festhalten. Dazu `assets/js/protocol.js` als maschinenlesbare Fassung derselben
Tabelle. **Eine App, die sich daran hält, ist kompatibel** — das ist der Sinn
des Dokuments, und es löst dieses Versprechen ein.

Was darin **fehlt** und für eine App ergänzt werden muss (teils schon in
`UEBERGABE.md` vermerkt, hier vollständig):

* die Anmeldung (im Browser gibt es das Cookie, in einer App nicht),
* das Signalisierungsformat über HTTP — `getSignal` ist nur im Code beschrieben,
* das Verhalten beim Wechsel in den Hintergrund (Abschnitt 3.2),
* was ein Client beim Verbindungsverlust tun soll — `rtc.js` tut das Richtige,
  aber es steht nicht im Protokoll,
* dass der Zuschauer keine Medien sendet. Das ist eine **Anwendungsregel**
  (`maySendMedia`), keine Protokollregel, und es steht deshalb in Abschnitt 3
  statt bei den Nachrichten. Eine App, die aus Gewohnheit beide Spuren
  anbietet, verhält sich anders als der Webclient, ohne gegen das Protokoll zu
  verstoßen.

**Die Rollenlogik in `callRoles()` — anfassen musst du sie nicht.** Sie steht in
`WebRTCController::callRoles()` und entscheidet über *jeden* Anruf, in dieser
Reihenfolge:

| # | Prüfung | Ergebnis |
|---|---|---|
| 1 | Ist eines der beiden Konten gelöscht? | Anruf kommt nicht zustande |
| 2 | Läuft zwischen beiden eine begonnene, nicht beendete Führung? | Die Rollen **aus dieser Zeile** — nicht die Frage, wer gewählt hat (Wiedereinstieg) |
| 3 | Steht der Angerufene auf „bereit" **oder** gibt es eine angenommene Anfrage im Zeitfenster? Und geht der Anruf von einem *geprüften* Standort des Angerufenen aus? | Führung: Anrufer `viewer`, Angerufener `guide` |
| 4 | Ist einer der beiden Admin? | beide `peer` |
| 5 | Ist der Angerufene bereit **und** darf er Standorte anbieten? | Führung |
| 6 | sonst | Anruf kommt nicht zustande |

**Was die App davon wissen muss:** Sie schickt beim `offer` die
Standortkennung mit und bekommt ihre Rolle in der Antwort. Mehr nicht. Sie
darf sich **keine Rolle selbst geben**, sie darf die Rolle **nicht raten**, und
sie muss mit `null` umgehen können: kein Steuerkreuz, keine
richtungsgebundenen Nachrichten, **keine Medien**. Dass diese Logik komplett
auf dem Server liegt, ist das größte Geschenk an den App-Bauer in diesem
Projekt — es gibt keinen Weg, sie im Client falsch zu machen.

**Die Signaling-Routen — zwei, und sie tragen alles.**

| Route | Was sie tut |
|---|---|
| `POST index.php?act=getSignal` | Offer, Answer, Kandidat, Hangup, Restart absetzen. Beim `offer` kommt die **Rolle** in der Antwort zurück. Beim `offer` wird der Anruf **zugelassen oder abgewiesen** — vor dem Speichern. |
| `GET index.php?act=getSignal` | Alle Signale für mich abholen. Das ausgelieferte `offer` trägt die **Rolle des Angerufenen**. Gelesenes wird gelöscht; ein Signal, das zwischen Lesen und Löschen eintrifft, bleibt liegen. |
| `GET index.php?act=get_turn_credentials` | ICE-Server. Antwortet **immer** verwertbar: `iceServers` nie leer, dazu `turnAvailable` und ggf. `warning`. |

Dazu **80 Routen insgesamt, davon 33 mit JSON-Antwort**. Der gesamte
Fachablauf ist über JSON erreichbar, ohne HTML zu parsen. Die übrigen 47
liefern fertige Seiten — für eine App ist davon nichts verwendbar (Abschnitt
5.4).

### 4.2 Die Reihenfolge — was eine App wann anspricht

Dies ist die Bauanleitung. Jede Stufe ist für sich lauffähig und für sich
prüfbar; wer sie umsortiert, baut länger.

**Stufe 0 — Anmeldung.** `login`, danach jede weitere Anfrage mit einem Token
statt einem Cookie. **Existiert nicht** (Abschnitt 5.1). Ohne diese Stufe geht
gar nichts, und sie ist der größte Einzelposten.

**Stufe 1 — Anwesenheit und Bereitschaft.** `heartbeat` (alle 10 s),
`set_availability` (der Schalter „ich kann jetzt führen"). Zwei verschiedene
Aussagen, deshalb zwei Routen — angemeldet zu sein ist keine Zusage. Die
Bereitschaft verfällt nach zwei Stunden ohne Bedienung.

**Stufe 2 — Finden.** `get_map_locations` (die Karte, auch für Gäste, gibt
weder Namen noch IDs heraus), `get_locations`, `location` /
`get_location_state` (Verfügbarkeit einzeln nachfragen). Für den Zuschauer der
Einstieg; für den Guide nur, um die eigenen Standorte zu sehen
(`get_my_locations`).

**Stufe 3 — Anfragen.** `request_create` (Kunde), `get_requests` (beide),
`request_accept` / `request_decline` (Guide), `request_cancel`. **Hier gehört
die erste Push-Zustellung hin** — und wenn nur eine Stufe Push bekommt, dann
diese.

**Stufe 4 — Der Anruf.** `get_turn_credentials`, dann `POST getSignal` mit
`offer` **und Standortkennung**, Rolle aus der Antwort übernehmen, `GET
getSignal` im Takt für Answer und Kandidaten. Die Gegenseite bekommt ihr Offer
mitsamt Rolle über denselben `GET`.

**Stufe 5 — Die Verbindung.** Zwei DataChannels vom Anrufer (`chat` und
`control`, Zuordnung beim Angerufenen **am Label**), Medien nach Rolle, für
beide Spurarten einen leeren Sender vorhalten. Dann `hello` und `video_state`,
sobald Rolle und Steuerkanal beide da sind.

**Stufe 6 — Die Führung.** `move` / `ack` mit Sequenznummern,
`control_lock`, `video_state`, Chat, Dateien. Das ist `PROTOKOLL.md`
Abschnitt 4 und 5, eins zu eins.

**Stufe 7 — Das Ende.** `hangup` **auf beiden Wegen** (DataChannel *und*
`POST getSignal`) — der Kanal ist schneller, der Server erreicht auch einen
toten Kanal. Auflegen beendet die Führung **nicht**: Dafür gibt es
`request_finish`, und nur der Guide darf es. Bis dahin (30 Minuten) können
beide Seiten wieder einsteigen, und Stufe 4 läuft erneut — mit dem Unterschied,
dass jetzt Regel 2 der Rollenvergabe greift und der Guide auch selbst anrufen
darf.

**Stufe 8 — Danach.** `review_create` (nur Kunde bewertet Guide, nie umgekehrt),
Chat außerhalb des Calls (`chat_start` über einen **Standort**, nicht über eine
Kontokennung), `requests_page`.

**Eine App, die bei Stufe 7 aufhört, ist brauchbar.** Eine App, die bei Stufe 4
aufhört, ist ein Prototyp.

---

## 5. Was neu gebaut werden muss

Nach Größe sortiert. Die ersten drei Posten sind **wegunabhängig** — sie fallen
bei PWA, plattformübergreifend und nativ gleichermaßen an und liegen auf dem
Server.

### 5.1 Anmeldung mit Token (der größte Posten)

Heute: PHP-Sitzung, Cookie mit `httponly`, `secure` und `SameSite=Strict`. Eine
App hat keinen Cookie-Jar im Browsersinn und keine Same-Site-Semantik.

Gebraucht wird: Ausgabe beim Login, Prüfung in `Auth`, Ablauf, Erneuerung,
Widerruf beim Abmelden und beim Löschen des Kontos, sichere Ablage auf dem
Gerät (Keychain bzw. Keystore, **nicht** in einer Datei). Betroffen sind
`class/Helper/Auth.php`, `config/session.php` und jede Route — wobei „jede
Route" milder ist, als es klingt: Die Rechteprüfung sitzt an einer Stelle
(`index.php` gegen die Tabelle in `config/routes.php`), und dort wird auch der
Token geprüft.

**Aufwand: 2–4 Wochen.** Die Unsicherheit steckt nicht im Bauen, sondern im
Nicht-Kaputtmachen: Beide Wege — Cookie für den Browser, Token für die App —
müssen nebeneinander laufen, solange es beide Clients gibt.

Eine PWA braucht das streng genommen **nicht**. Sie läuft im Browserkontext und
behält das Cookie. Das ist ein weiterer Grund, mit ihr anzufangen — und ein
Grund, den Posten trotzdem früh anzugehen, sobald absehbar ist, dass eine
Store-App kommt.

### 5.2 Ein Signalisierungsweg, der ein Handy übersteht

Polling im 1,5-Sekunden-Takt hält das Funkmodul wach (Abschnitt 3.5), und ein
Hintergrundprozess, der pollt, wird von beiden Betriebssystemen beendet.

Gebraucht wird ein **WebSocket** — für Signale während des Calls und für
Anfragen, solange die App offen ist. Das löst zugleich die Latenz (heute im
Mittel 750 ms je Signalisierungsschritt) und die Serverlast (bei 100 Nutzern
rund 70 Datenbankabfragen pro Sekunde im Leerlauf).

**Wichtig und leicht zu übersehen:** Das Polling darf dabei **nicht
verschwinden**. Es ist heute schon der Weg, der unabhängig von der gestörten
Verbindung funktioniert — deshalb wird es im Call umgeschaltet und nicht
abgeschaltet, und deshalb erreicht das Auflegen den Gegenüber auch dann, wenn
der DataChannel tot ist. Der WebSocket tritt daneben, nicht an die Stelle.

**Aufwand: 3–5 Wochen**, einschließlich Betriebsthemen (ein zusätzlicher
dauerhafter Prozess neben PHP, Reverse-Proxy, Wiederverbindung,
Authentifizierung am Socket).

### 5.3 Push-Zustellung

Zwei getrennte Wege, aus dem Grund in Abschnitt 3.6:

* **Anfragen** — gewöhnliche Mitteilung, APNs bzw. FCM, in der PWA Web Push.
* **Anrufe** — auf iOS PushKit mit sofortiger CallKit-Meldung, auf Android FCM
  mit hoher Priorität und Vollbild-Benachrichtigung. **In der PWA gibt es das
  nicht.**

Dazu auf dem Server: Geräte-Token je Konto verwalten (ein Konto, mehrere
Geräte), Zustellung anstoßen, wo heute nur die Datenbank geschrieben wird
(`RequestController::create`, `accept`, und der Offer-Zweig in
`WebRTCController::getSignal`), abgelaufene Token aufräumen, und eine Ablage
für die Zertifikate bzw. Schlüssel.

**Aufwand: 2–8 Wochen.** Die Spanne ist ehrlich und kommt daher, dass „Push
funktioniert" und „Push funktioniert verlässlich, auch wenn das Gerät seit zwei
Tagen aus war" zwei verschiedene Projekte sind.

### 5.4 Die Oberfläche (nur für 2.2 und 2.3)

Der Server liefert fertiges HTML aus `assets/html/` über die `*View`-Helper.
Für eine App ist davon **nichts** verwendbar. Entweder werden die Ansichten
nativ nachgebaut — dann brauchen die 47 HTML-Routen JSON-Gegenstücke — oder man
baut einen WebView, und dann hat man die Vorteile einer App außer der Kamera
nicht (und ein Store-Problem, siehe 7.1).

Das ist der Posten, der die 10–16 bzw. 16–26 Wochen aus Abschnitt 2 füllt.

### 5.5 Die Sprachkataloge

`lang/de.php` und `lang/en.php` sind PHP-Arrays; die Auflösung läuft über
`App\Helper\I18n`. Für eine App müssen sie exportiert werden — ein Endpunkt
oder ein Bauschritt. **Gedoppelt heißt: sie laufen auseinander**, und das merkt
man erst, wenn ein Guide einen halben Satz auf Deutsch sieht.

`tests/i18n_scan.php` prüft heute schon, dass kein Schlüssel fehlt. Dieser Test
muss den App-Katalog mit prüfen, sonst ist er nur noch die halbe Ratsche.

**Aufwand: 3–5 Tage**, wenn man es als Export baut. Wochen, wenn man es
vergisst und später aufräumt.

### 5.6 Der Medienpfad

`media.js` ist Browser-Code von der ersten bis zur letzten Zeile:
`getUserMedia`, `enumerateDevices`, `replaceTrack`, `setStreams`,
Fehlerbehandlung an Browser-Fehlercodes. In einer App ist das die
Aufnahmequelle der WebRTC-Bibliothek, die Berechtigungsschicht des
Betriebssystems und der Vordergrunddienst — drei verschiedene Dinge, und keines
davon sieht aus wie `getUserMedia`.

Was **übernommen** werden muss, weil es Anwendungslogik ist und keine Technik:
`maySendMedia()` (der Zuschauer sendet nichts), das Vorhalten leerer Sender für
beide Spurarten, und dass eine abgelehnte Kamera den Anruf **nicht** beendet.

### 5.7 Was auch das Protokoll noch braucht

Aus `PROTOKOLL.md` Abschnitt 9 und 4.8, mit App-Brille gelesen:

* **Dateien haben keine Grenze** — keine Größe, kein Typ, keine Anzahl, keine
  Metadaten. Im Browser ist das unschön; auf einem Handy ist es ein Weg, den
  Speicher vollzuschreiben, und zwar von außen. Das gehört als
  `file_meta`-Nachricht nachgeholt, **bevor** eine App existiert.
* **Keine empfangsseitige Ratenbegrenzung.** Gebremst wird heute nur beim
  Sender (100 ms je Schaltfläche plus die ausstehende Bestätigung). Ein
  nativer Client ohne diese Bremse flutet den Guide. Die Bremse gehört in jeden
  Client **und** auf die Empfangsseite.
* **Das Hintergrundverhalten** (Abschnitt 3.2) — sobald gemessen.

---

## 6. Die Fallstricke aus dem Web, die mobil wiederkommen

Diese vier haben uns im Browser schon einmal Zeit gekostet. Jeder von ihnen
kommt in einer App wieder, und drei davon kommen **schlimmer** wieder.

### 6.1 `msid` bei `replaceTrack`

**Was passiert ist.** `replaceTrack()` ordnet einem Sender *keinen*
`MediaStream` zu — anders als `addTrack(track, strom)`. Ohne Zuordnung fehlt im
SDP die `msid`, und beim Gegenüber kommt `ontrack` **ohne Strom** an. Ein
Empfänger, der nur `event.streams[0]` auswertet, zeigt dann nichts: schwarzes
Bild, keine Fehlermeldung, nichts im Log.

**Was wir getan haben.** `media.announceStream()` ruft `sender.setStreams()`
nach jedem `replaceTrack`. Und `rtc.attachRemoteTrack()` kommt zusätzlich ohne
Strom aus — der Notbehelf auf der Empfangsseite. Der Kommentar im Code sagt
ausdrücklich, warum beides da ist: *„Ein fremder Client — etwa die spätere App
— soll nicht auf denselben Notbehelf angewiesen sein."*

**Warum es mobil wiederkommt, und zwar schlimmer.** `setStreams()` ist **nicht
überall da**. Safari kennt es meines Wissens nicht, und ob die
plattformübergreifenden Bibliotheken es durchreichen, ist zu prüfen (Abschnitt
10). Das heißt:

* In der **PWA auf iOS** greift die Zuordnung nicht. Gerettet wird die Lage
  heute allein durch den Notbehelf auf der Empfangsseite — der funktioniert,
  aber er funktioniert nur, weil *unser* Empfänger ihn hat.
* In einer **App** ist die Empfangsseite neu geschrieben. Wer dort nur
  `streams[0]` auswertet — und das ist der naheliegende Weg, weil jedes
  Beispiel es so macht — bekommt beim Umschalten der Kamera ein schwarzes Bild.
  Der Anruf steht, der Ton läuft, das Bild fehlt. Das ist der teuerste Fehler
  in dieser Liste, weil er nach „Netzproblem" aussieht und keines ist.

**Die Regel für die App:** *Werte auf der Empfangsseite immer die Spur aus, nie
nur den Strom.* Und auf der Sendeseite die Stromzuordnung setzen, wo es geht —
aber sich nicht darauf verlassen.

### 6.2 Die Sender-Suche über die Transceiver

**Was passiert ist.** Ein Sender ohne Spur (Kamera aus) verrät seine Art nicht
mehr: `sender.track` ist `null`, und damit ist `sender.track.kind` weg. Die
alte Suche fiel deshalb auf „der erste Sender ohne Spur" zurück — **bei stummem
Mikrofon lieferte die Frage nach dem Videosender den Audiosender**, und
`replaceTrack` warf.

**Was wir getan haben.** `media.transceiverFor()` erkennt die Art am
*Empfänger*: Dessen Spur hat immer die richtige Art, ob etwas ankommt oder
nicht. `senderFor()` geht darüber und fällt nur dort auf `getSenders()` zurück,
wo `getTransceivers()` fehlt.

**Warum es mobil wiederkommt.** Weil es genau dann zuschlägt, wenn jemand
Kamera oder Mikrofon aus hat — und auf einem Handy hat man öfter etwas aus als
am Schreibtisch: Der Guide schaltet die Kamera ab, während er über die Straße
geht; er schaltet das Mikrofon stumm, während er mit jemandem spricht.

In einer nativen Anbindung ist das Bild anders, aber nicht besser: Dort hält man
die Sender-Referenzen üblicherweise selbst und hat das Problem nicht — **außer
man baut die Web-Logik nach**, was der naheliegende Weg ist, wenn man `media.js`
als Vorlage nimmt. Die Regel: *Merke dir den Sender je Spurart beim Aufbau.
Suche ihn nicht zur Laufzeit.* Das ist einfacher als das, was wir im Browser
tun mussten, und es ist richtiger.

**Und der verwandte Fall, der in der App neu ist:** die Reihenfolge beim
Kamerawechsel (Abschnitt 3.3). Im Browser holen wir erst die neue Spur und
stoppen dann die alte — damit bei einem Fehlschlag nicht beides weg ist. Auf
iOS ist das die Reihenfolge, die fehlschlägt.

### 6.3 Das Verwerfen von Steuerbefehlen bei Unterbrechung

**Was passiert ist.** Die DataChannels sind zuverlässig und geordnet
(`ordered: true`, die Voreinstellung). Alles, was während einer Störung im
Puffer landet, wird beim Wiederanlaufen **auf einen Schlag** zugestellt. Für
Chat ist das richtig. Für Bewegungsbefehle ist es gefährlich: Der Guide bekommt
sechs Anweisungen in einer Sekunde und läuft los.

**Was wir getan haben.** Zwei Sperren, an beiden Enden:

* **Beim Senden** (`rtc.canSendControlCommand()`): Ein `move` geht nur raus,
  wenn Call aktiv, Zustand `connected`, Verbindung nutzbar, Steuerkanal offen
  und Puffer unter 4096 Byte. Sonst wird der Befehl **verworfen und
  ausdrücklich nicht gepuffert.**
* **Beim Empfangen** (`rtc.mayExecuteControlCommand()`): Ein `move` wird nur
  ausgeführt, wenn die Verbindung seit mindestens **1000 ms** wieder steht
  (`CONTROL_SETTLE_MS`). Was in diesem Fenster eintrifft, wird mit
  `ack` / `rejected` / `unstable` beantwortet.

Und die Gegenprobe: Für `ack`, `control_lock`, `video_state`, `hangup` und
`hello` gilt die Sperre **nicht**. Eine Sperre, die den Guide nicht mehr
erreicht, wäre das genaue Gegenteil von sicher.

**Warum es mobil wiederkommt, und zwar schlimmer.** Der Grundsatz *„ein nicht
ausgeführter Befehl ist harmlos, ein verspäteter nicht"* gilt unverändert — und
er gilt auf dem Handy härter, weil dort jemand wirklich läuft. Drei neue Fälle:

1. **Unterbrechungen sind häufiger.** Funkzellenwechsel, WLAN-Übergabe, Tunnel.
   Der Fall, für den die Sperre gebaut wurde, ist auf dem Handy der Normalfall.
2. **Das Einfrieren durch das Betriebssystem ändert die Verbindungslage
   möglicherweise gar nicht** — siehe Abschnitt 3.2. Dann greift die
   Settle-Sperre nicht, obwohl genau ihr Fall vorliegt. **Das ist die
   gefährlichste offene Frage in diesem Dokument.**
3. **Die Timer selbst werden gedrosselt.** Die 5 s Grace, die 30 s Frist, die
   2000 ms Bestätigungsfrist — sie alle hängen an `setTimeout`, und im
   Hintergrund laufen Timer gedrosselt oder gar nicht. Eine Frist, die nicht
   abläuft, gibt das Steuerkreuz nie wieder frei; eine, die auf einen Schlag
   abläuft, feuert alles gleichzeitig. In einer nativen App gehören diese
   Fristen an eine monotone Uhr und an die Lebenszyklus-Rückrufe, nicht an
   einen Timer.

**Die Regel für die App:** *Beide Sperren übernehmen, die Zahlen übernehmen —
und zusätzlich beim Zurückkehren in den Vordergrund so behandeln, als wäre die
Verbindung gerade neu aufgebaut worden.*

### 6.4 Der SCTP-Puffer

**Was passiert ist.** Ein DataChannel nimmt beliebig viel an, auch wenn nichts
mehr durchgeht. Staut sich der Sendepuffer, ist der Kanal nicht mehr in
Echtzeit — und was man dann noch hineinschiebt, kommt später auf einmal an. Das
ist dieselbe Wurzel wie 6.3, von der anderen Seite gesehen.

**Was wir getan haben.** `CONTROL_MAX_BUFFER: 4096`. Liegt mehr als 4096 Byte
im Puffer, geht kein Steuerbefehl mehr raus. Bei einem `move` von rund 50 Byte
heißt das: **etwa achtzig ungesendete Befehle, dann ist Schluss.** Die Zahl ist
großzügig und trotzdem richtig, weil ein Steuerkanal im Normalbetrieb *leer*
ist. Ist er es nicht, stimmt etwas nicht.

**Warum es mobil wiederkommt.**

* **Der Chat teilt sich die SCTP-Verbindung mit dem Steuerkanal.** Das ist der
  Fall, den ich für den wahrscheinlichsten halte: Jemand schickt im Call ein
  Foto — und Dateien haben heute **keine Größenbegrenzung** (`PROTOKOLL.md`
  4.8). Ein 8-MB-Bild über eine Mobilfunkverbindung blockiert die gemeinsame
  Zuordnung minutenlang. Der Steuerkanal ist formal offen, sein eigener Puffer
  vielleicht sogar leer — und trotzdem kommt nichts durch, weil der Chat davor
  liegt. Die Prüfung auf `bufferedAmount` des *Steuerkanals* fängt das **nicht**
  ab. Der Zuschauer drückt, die Bestätigung bleibt aus, nach 2000 ms gibt das
  Steuerkreuz wieder frei, und er drückt erneut. Abhilfe: Größengrenze für Dateien (5.7), Dateien in Stücken
  senden und den Chatkanal zurückhalten, wenn *er* sich staut.
* **`bufferedAmount` ist nicht überall verfügbar.** Im Browser ist es
  Standard. Ob die plattformübergreifenden Bibliotheken es durchreichen, ist zu
  prüfen (Abschnitt 10). Fehlt es, muss die App **selbst zählen** — gesendete
  Bytes gegen bestätigte —, und das ist deutlich unangenehmer, als es klingt.
* **Und der Klassiker, den wir nicht getroffen haben, weil wir ihn nie
  gebraucht haben:** Wer den Kanal in der App aus Leistungsgründen
  `unordered` öffnet, **bricht die Sequenznummernlogik**. Das steht schon in
  `UEBERGABE.md`, und es gehört hierher, weil es in genau dem Moment passiert,
  in dem jemand den Puffer-Stau bemerkt und ihn falsch behebt.

### 6.5 Drei kleinere, die dazugehören

* **Die exakte Versionsprüfung ist ein Bruch, kein Übergang.** Der Empfänger
  vergleicht `v` **exakt**; es gibt keine Toleranz und keine Aushandlung.
  Sobald die App eine Version 3 braucht, verstehen App und Webclient einander
  **gar nicht mehr** — es sei denn, Version 3 wird ausdrücklich
  abwärtskompatibel gebaut (beide Versionen akzeptieren). **Das muss vor der
  ersten Protokolländerung entschieden werden, nicht danach.** Und es ist auf
  dem Handy schärfer als im Web: Eine Webseite ist nach einem Neuladen aktuell,
  eine App erst, wenn der Nutzer sie aktualisiert — und das tun manche nie.
  Wer eine App ausliefert, muss ab dem ersten Tag damit leben, dass alte
  Versionen draußen bleiben. Version 3 sollte deshalb **abwärtskompatibel**
  sein, oder es braucht einen Weg, eine zu alte App zum Aktualisieren zu
  zwingen, bevor sie einen Anruf versucht.
* **Der Zuschauer sendet nichts.** Steht in `PROTOKOLL.md` Abschnitt 3, ist aber
  eine Anwendungsregel und keine Protokollregel — eine App kann protokollkonform
  sein und sich trotzdem falsch verhalten.
* **Was die Tests nicht abdecken, deckt auch die App nicht ab.** „Der echte
  Wechsel zwischen WLAN und Mobilfunk auf zwei Geräten" und „das tatsächliche
  Timing eines ICE-Restarts über einen TURN-Server" stehen ausdrücklich unter
  „Grenzen" in `tests/README.md`. **Ein grüner Durchlauf ersetzt keinen Test
  mit zwei echten Geräten** — für die App gilt das doppelt, und für den Guide,
  der dabei wirklich um einen Block läuft, dreifach.

---

## 7. Store-Veröffentlichung

**Vorbemerkung, und sie ist wichtig:** Store-Regeln ändern sich, meine Kenntnis
davon hat ein Datum, und die Auslegung im Einzelfall liegt bei einem Menschen
im Prüfteam. Was hier steht, ist der Stand, den ich kenne, und es ist als
**Rechercheliste** brauchbar, nicht als Zusage. Prüfe jede Zeile gegen die
aktuellen Richtlinien, bevor du planst.

Der Kern in einem Satz: **Eine App, in der Fremde sich per Video begegnen, ist
für beide Stores eine Risikokategorie**, und das Prüfverfahren dauert deshalb
länger und fragt mehr, als man erwartet.

### 7.1 Apple

**Nutzergenerierte Inhalte (Richtlinie 1.2).** Das ist der Abschnitt, an dem
diese App hängt. Verlangt werden — meiner Kenntnis nach — vier Dinge:

1. eine Methode, **anstößige Inhalte zu filtern**,
2. ein Weg, Inhalte oder Personen **zu melden**, mit **zeitnaher Reaktion**,
3. die Möglichkeit, **missbräuchliche Nutzer zu blockieren**,
4. veröffentlichte **Kontaktdaten** des Anbieters.

**Davon hat die Anwendung heute: nichts für Personen.** Es gibt Sperren von
*Standorten* (`block_location`) und Entfernen von *Bewertungen*
(`review_remove`) — beides Moderation an Inhalten, nicht an Menschen. Es gibt
**keinen Meldeweg** aus einem laufenden Call, **keine Blockierliste**, und die
Kontaktseite ist eine Pflichtseite, die heute ausdrücklich sagt, dass sie leer
ist (`UEBERGABE.md` 5.7).

**Das ist der größte Posten des gesamten Store-Kapitels** und er ist kein
Formalismus: In einer 1:1-Videoanwendung mit Fremden *braucht* man einen
Melde-Knopf, der während des Calls erreichbar ist. Rechne mit **2–4 Wochen**
für Meldeweg, Blockierliste, Moderationsansicht und die Verfahrensbeschreibung
— zusätzlich zu allem in Abschnitt 5.

**Mindestfunktionalität (4.2).** Eine App, die im Wesentlichen eine Webseite in
einem WebView ist, wird abgelehnt. **Das schließt den bequemsten Weg aus** —
„wir packen die PWA in eine Hülle" ist keine Abkürzung in den App Store.

**Datenschutz (5.1).** Datenschutzerklärung Pflicht (heute leer),
Datenschutz-Label im Store-Eintrag (Kamera, Mikrofon, Standort, Kontaktdaten,
Nutzungsdaten), und **Löschen des Kontos aus der App heraus** — das gibt es
immerhin (`delete_user`), es muss nur erreichbar sein.

**Berechtigungstexte.** Für Kamera, Mikrofon und Standort je eine Begründung im
Bundle. Sie wird im Systemdialog angezeigt *und* im Prüfverfahren gelesen. Ein
Platzhalter ist ein Ablehnungsgrund.

**Hintergrundmodi und CallKit.** Der VoIP-Hintergrundmodus und PushKit sind
gebunden an die Zusage, bei jedem VoIP-Push **sofort** einen Anruf zu melden
(Abschnitt 3.6). Wer das nicht einhält, verliert die Pushes.

**Das Prüfverfahren selbst.** Eine 1:1-Videoanwendung lässt sich **nicht allein
prüfen** — es braucht eine Gegenstelle. Apple sagt für solche Fälle: Demokonto
*und* ein Video, das den vollen Ablauf zeigt. **Plane das ein**, sonst
verlierst du eine Woche pro Prüfrunde an einer Rückfrage, die nur heißt „wir
konnten es nicht ausprobieren".

**Altersfreigabe.** Unmoderiertes Live-Video mit Fremden landet erfahrungsgemäß
hoch — 17+ oder was der aktuelle Rahmen dafür vorsieht. Das ist keine Schande,
aber es ist eine Produktentscheidung mit Folgen für die Auffindbarkeit.

**Und zwei Dinge, die *nicht* zutreffen, damit niemand danach sucht:** „Mit
Apple anmelden" ist nur Pflicht, wenn die App Anmeldung über andere
Drittanbieter anbietet — diese tut das nicht. Und zum Bezahlen: Nach meiner
Kenntnis dürfen **Eins-zu-eins-Erlebnisse in Echtzeit** andere Zahlungswege als
den In-App-Kauf benutzen; Eins-zu-viele nicht. Diese Anwendung ist **strikt
1:1** (`PROTOKOLL.md` Abschnitt 9) und fiele damit in die günstigere Kategorie.
Das ist für die offene Entscheidung „Bezahlung" (`UEBERGABE.md` 6.2) relevant
genug, um es vor der Entscheidung zu prüfen — und zu unsicher, um es zu
glauben.

### 7.2 Google

**Nutzergenerierte Inhalte.** Inhaltlich dieselben vier Anforderungen wie bei
Apple: Moderation, Meldeweg in der App, Blockieren, Reaktion. Die Arbeit aus
7.1 zahlt auf beide Stores ein.

**Datensicherheits-Formular.** Ausführlicher als Apples Label und detailliert zu
belegen: welche Daten, wozu, an wen, verschlüsselt, löschbar. Es muss zu dem
passen, was die App wirklich tut — Abweichungen sind ein Ablehnungsgrund.
**Löschweg auch außerhalb der App** ist gefordert, also eine Webseite dafür.

**Berechtigungen und Vordergrunddienste.** Ein Call im Hintergrund braucht einen
Vordergrunddienst, und seit Android 14 muss dessen **Typ** erklärt werden
(Kamera, Mikrofon) — samt Begründung im Store-Eintrag. Mitteilungen brauchen
seit Android 13 eine Laufzeit-Berechtigung, und wer sie nicht bekommt, bekommt
auch keine Anfrage zugestellt. **Das ist der Punkt, an dem Abschnitt 3.6 und
Abschnitt 7 sich treffen: Die Push-Zustellung ist nur so gut wie der Dialog,
in dem der Guide sie erlaubt hat.**

**Ziel-API-Stand.** Google zieht ihn jährlich nach; eine App, die nicht
nachzieht, verschwindet aus der Suche und irgendwann aus dem Store. **Das ist
laufender Aufwand, kein einmaliger** — ein bis zwei Wochen pro Jahr, für immer.

**Altersfreigabe.** Über den IARC-Fragebogen; Live-Kommunikation mit Fremden
führt zu einer hohen Einstufung und zu Pflichtangaben über die Moderation.

### 7.3 Was in beiden Fällen zuerst passieren muss

Unabhängig vom Weg und vor jeder Einreichung:

1. **Impressum, Datenschutz und Kontakt mit echtem Text** (`UEBERGABE.md` 5.7).
   Heute sagen die drei Seiten ehrlich, dass sie leer sind. Das ist für einen
   Prototyp in Ordnung und für eine Einreichung das sichere Aus.
2. **Meldeweg und Blockieren.** Siehe 7.1. Der größte einzelne Posten.
3. **Ein Moderationsverfahren, das man beschreiben kann.** Nicht nur ein Knopf,
   sondern: Wer schaut sich Meldungen an, in welcher Frist, mit welchen Folgen?
   Beide Stores fragen danach, und beide fragen nach, wenn die Antwort dünn ist.
4. **HTTPS und ein gültiges Zertifikat** (`UEBERGABE.md` 5.3). Beide
   Plattformen verbieten Klartextverkehr in der Voreinstellung.

**Zeitplan, grob und mit Vorsicht zu genießen:** Vom fertigen Binärprogramm bis
zur Freigabe rechne mit **2–6 Wochen** bei Apple, wenn es Rückfragen zur
Moderation gibt — und die gibt es bei dieser Kategorie fast immer. Google ist
meist schneller, prüft dafür beim Datensicherheits-Formular genauer nach.

---

## 8. Die Empfehlung

### Was ich empfehle

**In drei Stufen, in dieser Reihenfolge:**

**Stufe A — Die Serverarbeit, jetzt und unabhängig von allem.**
Token-Anmeldung, WebSocket neben dem Polling, Push-Zustellung für **Anfragen**.
6–10 Wochen. Diese Arbeit fällt bei jedem der drei Wege an, sie liegt komplett
in einer Sprache und einem Projekt, das der Nachfolger schon kennt, und sie
verbessert die Webanwendung auch dann, wenn nie eine App kommt: Das
Akkuproblem, das Latenzproblem und „eine Anfrage erreicht niemanden" sind heute
**Webprobleme**, nicht App-Probleme.

**Stufe B — Die PWA, als Produkt und als Messgerät.**
2–4 Wochen. Sie bringt zwei Dinge:

* Für den **Zuschauer** ist sie fertig und richtig. Er sendet nichts, sitzt am
  Tisch und braucht keinen Store.
* Für den **Guide** ist sie der Feldtest, den keine Schätzung ersetzt. Drei
  echte Guides, eine Woche, echte Führungen. Danach weiß man, was heute
  niemand weiß: Hält die Verbindung, wenn jemand wirklich läuft? Ist die
  Bildschirmsperre eine Zumutung oder ein Ausschluss? Reicht der Akku? Und
  tritt der Fall aus Abschnitt 3.2 ein — kommt ein Schwall alter Befehle an,
  wenn die App aus dem Hintergrund zurückkehrt?

**Stufe C — Die Guide-App, plattformübergreifend, erst danach.**
10–16 Wochen, plus 2–4 Wochen Moderationswerkzeuge für die Stores. Gebaut wird
**nur die Guide-Seite** — Anfragen, Bereitschaft, Anrufannahme, Führung. Der
Zuschauer bleibt im Web. Das halbiert den Umfang, halbiert die zu portierenden
Ansichten und trifft genau den Teil, an dem die PWA scheitert.

### Warum

**Weil der Unterschied zwischen PWA und nativ an genau zwei Stellen liegt** —
Klingeln und Hintergrund —, und beide betreffen **nur den Guide**. Eine
Entscheidung „PWA oder App" für die ganze Anwendung ist eine Entscheidung, die
den halben Umfang unnötig mitnimmt.

**Weil die Serverarbeit ohnehin fällig ist** und weil sie die Entscheidung
nicht vorwegnimmt. Wer sie zuerst macht, hat in zehn Wochen eine bessere
Webanwendung und eine Entscheidungsgrundlage. Wer sie überspringt und gleich
eine App baut, baut zwölf Wochen lang gegen ein Polling an, das er am Ende
doch ersetzen muss.

**Weil plattformübergreifend statt nativ die ehrliche Antwort auf ein Budget
ist, das für zwei native Apps nicht reicht** — und bei einer 1:1-Videoanwendung
verliert man dabei wenig: Der teure Teil liegt in beiden Fällen in `libwebrtc`,
in CallKit und im Vordergrunddienst, und die spricht man aus React Native oder
Flutter genauso an. Zwischen Flutter und React Native würde ich nicht nach der
Technik entscheiden, sondern danach, was der Nachfolger kann. Beide
WebRTC-Bibliotheken sind brauchbar; die Prüfung aus Abschnitt 10 (Punkt 1 und 2)
entscheidet notfalls.

**Weil der Store-Teil unterschätzt wird.** Meldeweg, Blockieren, Moderation,
Rechtstexte, Altersfreigabe, Demovideo — das sind Wochen, die in keiner
Aufwandsschätzung für „die App" stehen und die trotzdem anfallen, bevor auch
nur ein Nutzer sie herunterlädt. Stufe A und B brauchen davon **nichts**.

### Wann diese Empfehlung falsch ist

Sie kippt, und zwar sauber, in drei Fällen:

1. **Wenn „jetzt sofort anrufen" der Kern des Produkts ist** und nicht die
   verabredete Führung. Dann braucht es auf iOS CallKit, dann ist die PWA für
   den Guide von vornherein kein Weg, und dann fängt man nach Stufe A direkt
   mit Stufe C an. **Diese Frage sollte vor allem anderen beantwortet werden**
   — sie ist eine Produktfrage, sie dauert eine halbe Stunde, und sie
   entscheidet über zwölf Wochen.
2. **Wenn die Guides bezahlte Mitarbeiter sind** und nicht Gelegenheitsanbieter.
   Dann ist ihr Gerät Arbeitsmittel, dann zählt Verlässlichkeit mehr als
   Aufwand, und dann ist nativ vertretbar.
3. **Wenn der Feldtest in Stufe B klar ausgeht** — in beide Richtungen. Hält
   die PWA zwanzig Minuten Führung durch, kann Stufe C warten, bis es Nutzer
   gibt. Bricht sie nach fünf Minuten ab, ist Stufe C nicht mehr optional.

### Was ich nicht empfehle

**Den WebView-Wrapper.** Er sieht aus wie eine Abkürzung und ist zwei Sackgassen
in einer: Apple lehnt ihn unter 4.2 ab, und die Probleme, wegen derer man die
App baut — Hintergrund, Klingeln, Kamera —, löst er alle nicht.

**Nativ auf beiden Plattformen als ersten Schritt.** Nicht weil es falsch wäre,
sondern weil es die Entscheidung vorwegnimmt, bevor irgendjemand gemessen hat,
ob die billigere reicht.

**Eine App vor Stufe A.** Eine App, die pollt, ist eine App, die der Nutzer
nach drei Tagen deinstalliert, weil sie den Akku leert.

---

## 9. Aufwand im Überblick

Alle Angaben in Wochen, für **eine erfahrene Person in Vollzeit**, ohne
Urlaub, ohne Betrieb und ohne die Einarbeitung in dieses Projekt. Die Spalte
„Verlässlichkeit" sagt, wie sehr ich der Zahl traue.

| Posten | Aufwand | Verlässlichkeit | Fällt an bei |
|---|---|---|---|
| Token-Anmeldung | 2–4 | mittel | App (PWA: nein) |
| WebSocket neben dem Polling | 3–5 | mittel | allen |
| Push: Anfragen | 2–4 | **niedrig** | allen |
| Push: Anrufe (CallKit/Vollbild) | 2–4 | **niedrig** | App |
| PWA-Hülle und Mobil-Ansichten | 2–4 | hoch | PWA |
| Client plattformübergreifend | 10–16 | niedrig | 2.2 |
| Client nativ, beide Plattformen | 16–26 | **sehr niedrig** | 2.3 |
| JSON-Gegenstücke für die HTML-Routen | 3–5 | mittel | 2.2 / 2.3 |
| Sprachkataloge exportieren | 0,5–1 | hoch | 2.2 / 2.3 |
| Protokoll ergänzen (Datei-Metadaten, Ratenbegrenzung, Hintergrund) | 1–2 | mittel | allen |
| Moderation: Melden, Blockieren, Ansicht | 2–4 | mittel | Store |
| Rechtstexte, Store-Einträge, Einreichung | 1–3 | niedrig | Store |

**Summen, grob:**

* **Stufe A + B** (Server + PWA): **9–15 Wochen**
* **Stufe A + B + C** (dazu die plattformübergreifende Guide-App): **25–40
  Wochen**
* **Stufe A + nativ auf beiden Plattformen**: **35–55 Wochen**

Wo die Zahlen am ehesten reißen: **Push** (die Spanne ist echt), der
**plattformübergreifende Client** (hängt daran, wie viel Oberfläche wirklich
gebraucht wird) und das **Store-Verfahren** (hängt an Menschen).

---

## 10. Was ich nicht weiß

Ehrlich und vollständig. Die ersten drei sind **vor** der Entscheidung zu
klären; sie kosten zusammen etwa eine Woche und können den empfohlenen Weg
umwerfen.

**1. Reichen die WebRTC-Bibliotheken?** Konkret: Bieten
`react-native-webrtc` und `flutter_webrtc` in der heutigen Fassung
`sender.setStreams()` (oder einen anderen Weg, die Stromzuordnung zu setzen —
Abschnitt 6.1) und `bufferedAmount` am DataChannel (Abschnitt 6.4)? Ich glaube
ja, in beiden Fällen, aber ich weiß es nicht sicher, und beides ist tragend.
**Ein Tag Prüfung, bevor 2.2 gewählt wird.**

**2. Tritt der Hintergrund-Fall aus Abschnitt 3.2 wirklich ein?** Überlebt eine
eingefrorene Seite ihre ICE-Verbindung lange genug, dass beim Aufwachen ein
Schwall alter Befehle **ohne** Settle-Fenster durchläuft? Das ist die
gefährlichste offene Frage in diesem Dokument, weil sie einen Menschen betrifft,
der gerade läuft. **Messbar in einem halben Tag** mit zwei Geräten und dem
bestehenden Webclient.

**3. Hält die PWA eine echte Führung durch?** Zwanzig Minuten, draußen,
Bildschirm an, WLAN-zu-Mobilfunk-Wechsel inklusive. Ich vermute: auf Android
ja, auf iOS knapp. **Eine Woche Feldtest mit drei Guides**, und die Empfehlung
in Abschnitt 8 steht oder fällt damit.

**Und die übrigen, in absteigender Wichtigkeit:**

**4. Die Store-Regeln im Detail.** Abschnitt 7 ist mein Kenntnisstand und hat
ein Datum. Insbesondere die Zahlen und Kategorien bei den Altersfreigaben, die
genaue Fassung der Anforderungen an nutzergenerierte Inhalte und die Regel zu
Eins-zu-eins-Echtzeiterlebnissen beim Bezahlen (7.1) sind **gegen die aktuellen
Richtlinien zu prüfen**, nicht gegen dieses Dokument.

**5. Der iOS-Kamerawechsel.** Dass iOS keine zweite Kamera freigibt, solange
die erste läuft (Abschnitt 3.3), ist mein Kenntnisstand und war lange so.
Ob es in der aktuellen Safari-Fassung noch gilt, weiß ich nicht. Der
Unterschied ist eine umgedrehte Reihenfolge, also klein — aber man findet ihn
nur, wenn man ihn sucht.

**6. Die Akkuzahlen in 3.5.** Die Aufrufzahlen sind gerechnet und stimmen. Die
15–25 % pro halber Stunde sind eine Erfahrungsgröße, keine Messung an diesem
Produkt. **Sie ist im Feldtest kostenlos mitzumessen.**

**7. Die Aufwände in Abschnitt 9.** Sie stammen aus dem Vergleich mit ähnlichen
Vorhaben, nicht aus diesem. Sie kennen weder die Person, die es baut, noch das
Tempo, in dem hier bisher gearbeitet wurde. **Nimm sie als Größenordnung, nicht
als Plan.**

**8. Ob eine App überhaupt der nächste Schritt ist.** Das steht mir nicht zu,
aber es gehört gesagt: Vor der App stehen in `UEBERGABE.md` fünf Punkte, ohne
die kein Livegang stattfindet (TURN, Mailversand, Zertifikat, Cronjob, Backup,
CSP, Rechtstexte), und drei offene Produktentscheidungen. **Eine App auf einer
Anwendung, die nicht live ist, ist eine App ohne Nutzer** — und der Feldtest
aus Stufe B braucht echte Guides, also eine laufende Anwendung. Die Reihenfolge
in Abschnitt 8 setzt das voraus.

---

## Anhang: Die Zahlen, die eine App übernehmen muss

Alle aus `assets/js/rtc.js`, `assets/js/control.js` und `PROTOKOLL.md`. Jede
ist aus einem Fehler entstanden; wer sie übernimmt, spart sich den Fehler.

| Größe | Wert | Wo | Wofür |
|---|---|---|---|
| `RECONNECT_GRACE_MS` | 5000 ms | `rtc.js` | `disconnected` ist vorübergehend — erst danach neu aushandeln |
| `RECONNECT_DEADLINE_MS` | 30000 ms | `rtc.js` | Gesamtfrist ab der ersten Störung, dann Ende |
| `MAX_ICE_RESTARTS` | 3 | `rtc.js` | Versuche je Störung |
| `RESTART_RETRY_MS` | 8000 ms | `rtc.js` | Abstand zwischen zwei Versuchen |
| `CONTROL_SETTLE_MS` | 1000 ms | `rtc.js` | Sperre für Steuerbefehle nach der Wiederverbindung |
| `CONTROL_MAX_BUFFER` | 4096 Byte | `rtc.js` | Puffergrenze, ab der nichts mehr rausgeht |
| `ACK_TIMEOUT_MS` | 2000 ms | `control.js` | Danach gibt das Steuerkreuz wieder frei |
| Frame-Grenze | 4096 Byte | `PROTOKOLL.md` | je Nachricht, UTF-8 |
| Chattext | 2000 Zeichen | `PROTOKOLL.md` | |
| `reason` | 120 Zeichen | `PROTOKOLL.md` | bei `control_lock` und `hangup` |
| Richtungsanzeige | 1400 ms | `PROTOKOLL.md` | dann wieder weg |
| Sendebremse | 100 ms | `control.js` | je Schaltfläche |
| Anruf-Zeitüberschreitung | 25000 ms | `rtc.js` | nicht angenommen |
| Heartbeat | 10 s | `config/presence.php` | |
| Offline nach | 45 s | `config/presence.php` | |
| Bereitschaft verfällt nach | 2 h | `config/presence.php` | |
| Anruffenster | −15 min / +2 h | `config/requests.php` | um den Wunschzeitpunkt |
| Wiedereinstieg | 30 min | `config/requests.php` | nach dem Auflegen |
| Antwortfrist für Anfragen | 1 h | `config/requests.php` | |

---

*Dieses Dokument ersetzt nichts. `PROTOKOLL.md` bleibt die verbindliche
Referenz für das Steuerprotokoll, `UEBERGABE.md` die für alles andere. Was hier
steht, ist die Abwägung dazwischen — und sie ist eine Momentaufnahme vom
18. September 2026.*
