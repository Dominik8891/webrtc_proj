/**
 * Die Seite eines einzelnen Standorts.
 *
 * WAS DIESES MODUL TUT
 * --------------------
 * Die Seite kommt FERTIG vom Server (App\Controller\LocationController).
 * Titel, Beschreibung, Dauer, Sprachen, Bilder und das Anfrageformular stehen
 * schon im Dokument, bevor dieses Skript laeuft - wer es abschaltet, sieht
 * den Standort trotzdem vollstaendig. Ergaenzt werden hier nur die fuenf
 * Dinge, die ohne Skript nicht gehen:
 *
 *   1. Die kleine Karte mit dem Treffpunkt.
 *   2. Die Grossansicht der Beispielbilder samt Blaettern. Ohne Skript ist
 *      jede Kachel ein Verweis auf das Bild selbst - ein Klick oeffnet es
 *      dann eben, statt es hier zu zeigen. Der KOPF der Seite braucht das
 *      nicht mehr: Dort steht ein einzelnes Bild, das Titelbild, und das
 *      liefert der Server fertig aus.
 *   3. Der Verfuegbarkeitszustand im Takt. Ohne ihn stuende auf einer lange
 *      offenen Seite ein "Jetzt verfuegbar" von vor einer Stunde.
 *   4. Das Bearbeiten samt Bildverwaltung - nur beim Eigentuemer, und nur,
 *      weil der Server das Formular nur ihm geliefert hat.
 *   5. Die ANFRAGE: sie abschicken, ihren Zustand im Takt nachziehen und -
 *      sobald der Guide zugesagt hat und das Zeitfenster laeuft - die
 *      Fuehrung starten.
 *
 * DIE ANFRAGE IST DER NEUE ANFANG
 * -------------------------------
 * Hier stand ein Knopf "Fuehrung starten", der sofort anrief. Das verlangte,
 * dass Kunde und Guide zufaellig im selben Moment koennen - und der Guide ist
 * die knappere Seite: Er muss losgehen, sich Zeit nehmen, vielleicht
 * hinfahren. Jetzt steht dort eine Anfrage mit einem Wunschzeitpunkt; "jetzt
 * sofort" ist einer davon und kein zweiter Weg. Angerufen wird erst nach der
 * Zusage - und dann genau wie vorher.
 *
 * DER ANRUF GIBT DIE STANDORTKENNUNG MIT. Das ist die eine Stelle, an der
 * dieses Modul in den Ablauf des Calls eingreift, und sie ist wichtig: Am
 * Standort haengt beim Server die Rollenvergabe - von einem Standort aus
 * fuehrt der Angerufene, auch wenn er Admin ist
 * (App\Controller\WebRTCController::callRoles). Ginge die Kennung hier
 * verloren, waere jede Fuehrung ueber einen Admin-Standort ein Gespraech
 * ohne Fuehrung.
 *
 * WAS ES NICHT TUT
 * ----------------
 * Es entscheidet nichts ueber Berechtigungen. Dass ein Gast keinen
 * Anrufknopf hat, ist eine Entscheidung des Servers - hier fehlt der Knopf
 * dann einfach. Und dass das Bearbeitungsformular nur dem Eigentuemer
 * gehoert, steht ebenfalls dort; die Routen dahinter pruefen es erneut.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.locationPage = {

    /**
     * Takt der Zustandsabfrage.
     *
     * Derselbe Wert wie in Karte und Liste (home_map.js,
     * locations_table.js): etwas laenger als der Heartbeat aus
     * config/presence.php, damit nicht haeufiger gefragt wird, als sich die
     * Daten aendern koennen.
     */
    REFRESH_MS: 15000,

    /** Zoomstufe der kleinen Karte - dasselbe Mass wie home_map.SINGLE_ZOOM. */
    ZOOM: 15,

    map: null,
    refreshTimer: null,

    /** Die Angaben, die der Server mitgeliefert hat (window.locationPage). */
    daten: null,

    /**
     * Einstiegspunkt.
     *
     * Bricht ab, wenn der Server keine Seitendaten mitgeliefert hat - dann
     * ist das hier keine Standortseite. Die Abfrage haengt bewusst an
     * window.locationPage und nicht an einem Element: Ein Element findet man
     * auf jeder Seite, auf der jemand dieselbe id vergibt.
     */
    init() {
        if (!window.locationPage) return;
        this.daten = window.locationPage;

        this.initMap();
        this.bindLightbox();
        this.bindCall();
        this.bindRequest();
        this.startAutoRefresh();

        if (this.daten.isOwn) this.bindEdit();
    },

    // -----------------------------------------------------------------
    // Karte
    // -----------------------------------------------------------------

    /**
     * Zeichnet den Treffpunkt.
     *
     * Ohne Leaflet (Werbeblocker, kein Netz) bleibt der Kasten leer statt
     * eine Fehlermeldung zu zeigen: Die Karte ist hier eine Zugabe, die
     * Angaben daneben stehen ohnehin.
     */
    initMap() {
        const el = document.getElementById('loc-map');
        if (!el || typeof L === 'undefined') return;
        // Auf den Typ pruefen und nicht nur auf isFinite: isFinite(null) ist
        // true, weil null zu 0 wird - und 0/0 ist ein gueltiger Punkt im
        // Atlantik. Ein Standort ohne Koordinaten bekommt keine Karte.
        if (typeof this.daten.lat !== 'number' || typeof this.daten.lon !== 'number') return;
        if (!isFinite(this.daten.lat) || !isFinite(this.daten.lon)) return;

        this.map = L.map(el, {
            center: [this.daten.lat, this.daten.lon],
            zoom: this.ZOOM,
            zoomControl: true,
            // Kein Zoomen mit dem Rad: Wer auf einer langen Seite scrollt und
            // dabei ueber die Karte faehrt, will weiterlesen und nicht
            // hineinzoomen.
            scrollWheelZoom: false,
            attributionControl: true
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap',
            maxZoom: 19
        }).addTo(this.map);

        L.marker([this.daten.lat, this.daten.lon], { title: this.daten.place || 'Treffpunkt' })
            .addTo(this.map);
    },

    // -----------------------------------------------------------------
    // Grossansicht der Beispielbilder
    // -----------------------------------------------------------------

    /** Nummer des gerade gezeigten Bildes. */
    slide: 0,

    /** Die Kacheln der Galerie, in ihrer Reihenfolge. */
    shots: [],

    /**
     * Haengt die Grossansicht ein.
     *
     * OHNE SIE FUNKTIONIERT DIE SEITE TROTZDEM: Jede Kachel ist ein Verweis
     * auf das Bild in voller Groesse. Was hier dazukommt, ist das Ansehen
     * OHNE die Seite zu verlassen - und das Blaettern, das ein einzelnes Bild
     * im Browser nicht kann.
     *
     * Der Handler haengt am Behaelter und nicht an jeder Kachel: Die Galerie
     * steht fest im ausgelieferten Dokument, aber ein Handler weniger ist ein
     * Handler weniger.
     */
    bindLightbox() {
        const streifen = document.getElementById('loc-shots');
        const kasten   = document.getElementById('loc-lightbox');
        if (!streifen || !kasten) return;

        this.shots = Array.from(streifen.querySelectorAll('.loc-shots__item'));
        if (this.shots.length === 0) return;

        streifen.addEventListener('click', (e) => {
            const kachel = e.target.closest('.loc-shots__item');
            if (!kachel) return;
            // Erst hier: Ohne Skript soll der Verweis das Bild oeffnen.
            e.preventDefault();
            this.openLightbox(this.shots.indexOf(kachel));
        });

        kasten.addEventListener('click', (e) => {
            const pfeil = e.target.closest('[data-slide-step]');
            if (pfeil) {
                this.showSlide(this.slide + parseInt(pfeil.getAttribute('data-slide-step'), 10));
                return;
            }
            // Ein Klick auf die Flaeche daneben schliesst - so wie man es von
            // jeder Grossansicht erwartet. Ein Klick auf das Bild selbst
            // nicht: Wer es ansieht, will es nicht versehentlich wegklicken.
            if (e.target === kasten || e.target.closest('.loc-lightbox__close')) {
                this.closeLightbox();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (kasten.hidden) return;
            if (e.key === 'Escape')     { this.closeLightbox(); return; }
            if (e.key === 'ArrowLeft')  { this.showSlide(this.slide - 1); return; }
            if (e.key === 'ArrowRight') { this.showSlide(this.slide + 1); }
        });
    },

    /**
     * Oeffnet die Grossansicht bei einem bestimmten Bild.
     *
     * Das Scrollen der Seite darunter wird gesperrt: Sonst rollt der Hintergrund
     * weg, waehrend man mit dem Rad durch die Bilder blaettern will, und beim
     * Schliessen steht man an einer anderen Stelle als vorher.
     *
     * @param {number} nr
     */
    openLightbox(nr) {
        const kasten = document.getElementById('loc-lightbox');
        if (!kasten || this.shots.length === 0) return;

        kasten.hidden = false;
        document.body.style.overflow = 'hidden';
        this.showSlide(nr);
        document.getElementById('loc-lightbox-close')?.focus();
    },

    /** Schliesst die Grossansicht und gibt das Scrollen wieder frei. */
    closeLightbox() {
        const kasten = document.getElementById('loc-lightbox');
        if (!kasten) return;
        kasten.hidden = true;
        document.body.style.overflow = '';
    },

    /**
     * Zeigt ein Bild in der Grossansicht.
     *
     * Die Nummer laeuft im Kreis: Hinter dem letzten Bild kommt wieder das
     * erste. Ein Pfeil, der am Ende nichts mehr tut, sieht kaputt aus, und
     * ihn dort zu sperren hiesse, bei jedem Wechsel zwei Knoepfe umzuschalten.
     *
     * @param {number} nr Kann ueber die Grenzen hinausgehen
     */
    showSlide(nr) {
        const anzahl = this.shots ? this.shots.length : 0;
        if (anzahl === 0) return;

        // Modulo zweimal: In JavaScript ist (-1 % 5) gleich -1 und nicht 4.
        this.slide = ((nr % anzahl) + anzahl) % anzahl;

        const bild = document.getElementById('loc-lightbox-image');
        const quelle = this.shots[this.slide];
        if (bild && quelle) {
            bild.src = quelle.getAttribute('data-full') || quelle.getAttribute('href') || '';
            bild.alt = quelle.querySelector('img')?.getAttribute('alt') || '';
        }

        // Bei einem einzelnen Bild gibt es nichts zu blaettern.
        document.querySelectorAll('.loc-lightbox__nav').forEach(
            el => { el.hidden = (anzahl < 2); });
    },

    // -----------------------------------------------------------------
    // Der Anruf
    // -----------------------------------------------------------------

    /**
     * Startet die Fuehrung.
     *
     * MIT DER STANDORTKENNUNG. Siehe der Kopf dieser Datei: Daran haengt die
     * Rollenvergabe des Servers.
     *
     * Der Handler haengt am Dokument, weil der Knopf ausgetauscht wird,
     * sobald sich die Verfuegbarkeit aendert (siehe applyState).
     */
    bindCall() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.loc-call-btn');
            if (!btn || btn.disabled) return;
            e.preventDefault();

            const userId     = btn.getAttribute('data-userid');
            const locationId = btn.getAttribute('data-locationid');
            if (!userId) return;

            if (typeof window.webrtcApp?.rtc?.startCall !== 'function') {
                window.webrtcApp.notify.error('Die Anruffunktion steht auf dieser Seite nicht zur Verfügung.');
                return;
            }
            window.webrtcApp.rtc.startCall(userId, locationId);
        });
    },

    // -----------------------------------------------------------------
    // Verfuegbarkeit
    // -----------------------------------------------------------------

    /** Fragt den Zustand im Takt nach - im ausgeblendeten Tab nicht. */
    startAutoRefresh() {
        if (this.refreshTimer) clearInterval(this.refreshTimer);
        this.refreshTimer = setInterval(() => {
            if (document.hidden) return;
            this.loadState();
        }, this.REFRESH_MS);

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) this.loadState();
        });
    },

    /**
     * Holt den aktuellen Zustand.
     *
     * Ein fehlgeschlagener Abruf aendert NICHTS: Der zuletzt bekannte Stand
     * bleibt stehen. Einen Guide wegen einer einzelnen verlorenen Antwort auf
     * "nicht verfuegbar" zu setzen waere schlechter als eine Angabe, die
     * fuenfzehn Sekunden alt ist.
     */
    loadState() {
        if (!this.daten) return;

        fetch('index.php?act=get_location_state&id=' + encodeURIComponent(this.daten.id),
              { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(antwort => {
                if (!antwort || !antwort.success) return;
                this.applyState(antwort.availability);
                this.applyRequest(antwort.request || null);
            })
            .catch(() => {});
    },

    /**
     * Uebernimmt eine neue Verfuegbarkeit.
     *
     * SIE SPERRT NICHTS MEHR. Frueher haing der Anrufknopf daran: Kein
     * Guide vor Ort, kein Knopf. Seit am Anfang eine Anfrage steht, ist das
     * falsch - ein Standort, an dem gerade niemand ist, laesst sich fuer
     * heute Abend anfragen. Die Verfuegbarkeit ist eine AUSKUNFT und faerbt
     * die Marke oben; ob jetzt gestartet werden darf, sagt allein die
     * Anfrage (applyRequest).
     *
     * Steht gerade das Formular da, wird es neu gezeichnet: Sein Hinweis
     * ("der Guide ist gerade bereit") gehoert zu dieser Auskunft.
     *
     * @param {string} zustand 'live', 'busy' oder 'idle'
     */
    applyState(zustand) {
        if (!this.daten || zustand === this.daten.availability) return;
        this.daten.availability = zustand;

        const marke = document.getElementById('loc-state');
        if (marke && !this.daten.isOwn) marke.innerHTML = this.stateTagHtml(zustand);

        if (!this.daten.request) this.renderAktion();
    },

    /**
     * Die Zustandsmarke als HTML.
     *
     * Dieselben drei Werte und dieselben Klassen wie im Kartenfenster
     * (home_map.popupTag) und auf der Serverseite
     * (LocationController::zustandHtml). Es sind drei Bauorte fuer dieselbe
     * Marke - eine Doppelung, die bleibt, solange die Seite serverseitig
     * ausgeliefert und im Browser nachgezogen wird.
     *
     * @param {string} zustand
     * @returns {string} HTML
     */
    stateTagHtml(zustand) {
        if (zustand === 'live') {
            return '<span class="app-tag app-tag--live"><span class="app-dot"></span>Jetzt verfügbar</span>';
        }
        if (zustand === 'busy') {
            return '<span class="app-tag app-tag--warn"><span class="app-dot"></span>Im Gespräch</span>';
        }
        return '<span class="app-tag">Kein Guide vor Ort</span>';
    },

    // -----------------------------------------------------------------
    // Die Anfrage
    // -----------------------------------------------------------------

    /**
     * Haengt das Anfrageformular ein.
     *
     * Ein Handler am Dokument und nicht je Knopf: Der Aktionsbereich wird neu
     * gebaut, sobald sich der Zustand aendert - ein Handler an einem
     * ersetzten Knopf waere weg.
     */
    bindRequest() {
        document.addEventListener('click', (e) => {
            const vorgabe = e.target.closest('.loc-req__preset');
            if (vorgabe) { e.preventDefault(); this.waehleVorgabe(vorgabe); return; }

            const senden = e.target.closest('.loc-req-submit');
            if (senden) { e.preventDefault(); this.stelleAnfrage(); return; }

            const zurueck = e.target.closest('.loc-req-cancel');
            if (zurueck) { e.preventDefault(); this.ziehZurueck(zurueck.getAttribute('data-id')); }
        });

        // Wer eine Uhrzeit eintraegt, hat sich gegen die Vorgaben entschieden.
        // Sie bleiben stehen, sind aber nicht mehr ausgewaehlt - sonst
        // stuenden zwei Antworten auf dieselbe Frage nebeneinander, und es
        // waere nicht mehr erkennbar, welche gilt.
        //
        // Nur ECHTE Eingaben: Ein per Skript gesetztes .value loest kein
        // input-Ereignis aus (zeigeWunschzeit).
        document.addEventListener('input', (e) => {
            if (!e.target || e.target.id !== 'loc-req-wish') return;
            this.loeseVorgaben();
            this.pruefeZeit();
        });

        // Das serverseitig gelieferte Formular traegt "Jetzt sofort" bereits
        // markiert, aber ein leeres Feld - der Server kennt die Uhr des
        // Browsers nicht. Einmal nachziehen, damit Markierung und Zeit von
        // Anfang an zusammenpassen.
        this.zeigeWunschzeit();
        this.zeigeZonenunterschied();
        this.pruefeZeit();
    },

    /**
     * Waehlt einen der vorgegebenen Abstaende.
     *
     * DIE WAHL WIRD SICHTBAR: Der Knopf hebt sich ab, UND der gemeinte
     * Zeitpunkt steht danach im Feld darunter. Vorher wurde das Feld beim
     * Klick geleert - die Vorgaben taten also etwas, wovon auf der Seite
     * nichts zu sehen war ausser einem dezenten Rahmen. Wer "In 1 Stunde"
     * drueckt, will lesen koennen, wann das ist; und die beiden
     * Bedienelemente gehoeren erkennbar zusammen, statt nebeneinanderher zu
     * laufen.
     *
     * @param {HTMLElement} knopf
     */
    waehleVorgabe(knopf) {
        this.loeseVorgaben();
        knopf.classList.add('loc-req__preset--on');
        knopf.setAttribute('aria-pressed', 'true');
        this.zeigeWunschzeit();
        this.pruefeZeit();
    },

    /** Nimmt allen Vorgaben die Auswahl. */
    loeseVorgaben() {
        document.querySelectorAll('.loc-req__preset').forEach(k => {
            k.classList.remove('loc-req__preset--on');
            k.setAttribute('aria-pressed', 'false');
        });
    },

    /**
     * Traegt den gewaehlten Abstand als Zeitpunkt in das Feld ein.
     *
     * Aufgerufen beim Klick auf eine Vorgabe und einmal beim Aufbau der
     * Seite: Der Server liefert "Jetzt sofort" vorgewaehlt aus, kann das Feld
     * aber nicht fuellen - er kennt die Uhr des Browsers nicht. Ohne diesen
     * Abgleich stuende beim ersten Blick eine Markierung ohne Zeit daneben.
     *
     * Ohne markierte Vorgabe passiert nichts: Dann hat der Nutzer selbst
     * etwas eingetragen, und das wird nicht ueberschrieben.
     *
     * Das Setzen von .value loest KEIN input-Ereignis aus - die Markierung,
     * die hier gerade gesetzt wurde, hebt sich also nicht selbst wieder auf
     * (siehe bindRequest).
     */
    zeigeWunschzeit() {
        const feld = document.getElementById('loc-req-wish');
        if (!feld) return;

        const gewaehlt = document.querySelector('.loc-req__preset--on');
        if (!gewaehlt) return;

        const sekunden = parseInt(gewaehlt.getAttribute('data-seconds'), 10) || 0;
        feld.value = this.zeitFeldWert(sekunden);
    },

    /**
     * Ein Abstand in Sekunden als Wert fuer ein datetime-local-Feld.
     *
     * ORTSZEIT, NICHT UTC. toISOString() waere der kurze Weg und der falsche:
     * Es rechnet nach UTC um, und das Feld zeigte dann in Mitteleuropa eine
     * bis zwei Stunden zu frueh. Zusammengesetzt wird deshalb aus den
     * getFullYear/getMonth/...-Werten, die alle in der Zeitzone des Browsers
     * stehen.
     *
     * Sekunden fallen weg - das Feld kennt nur Minuten.
     *
     * @param {number} sekunden Abstand von jetzt
     * @returns {string} "JJJJ-MM-TTTHH:MM"
     */
    zeitFeldWert(sekunden) {
        const ziel = new Date(Date.now() + (parseInt(sekunden, 10) || 0) * 1000);
        const zwei = (n) => String(n).padStart(2, '0');

        return ziel.getFullYear() + '-' + zwei(ziel.getMonth() + 1) + '-' + zwei(ziel.getDate())
             + 'T' + zwei(ziel.getHours()) + ':' + zwei(ziel.getMinutes());
    },

    /**
     * Der gewaehlte Wunschzeitpunkt als ABSTAND in Sekunden.
     *
     * Ein Abstand und kein Datum, und zwar an dieser einen Stelle umgerechnet:
     * Der Server rechnet daraus an SEINER Uhr einen Zeitpunkt. Damit spielt es
     * keine Rolle, in welcher Zeitzone der Kunde sitzt und ob seine Uhr
     * richtig geht - "in einer Stunde" heisst fuer beide Seiten dasselbe.
     *
     * WENN EINE VORGABE MARKIERT IST, GILT SIE - auch wenn im Feld derselbe
     * Zeitpunkt steht. Das Feld ZEIGT die Wahl, die Vorgabe TRAEGT sie. Der
     * Unterschied wird nach ein paar Minuten sichtbar: "Jetzt sofort" bleibt
     * "jetzt", waehrend der Zeitpunkt im Feld inzwischen in der Vergangenheit
     * laege und die Anfrage abgewiesen wuerde.
     *
     * Sobald der Nutzer das Feld selbst anfasst, ist keine Vorgabe mehr
     * markiert (siehe bindRequest) - dann gilt das Feld.
     *
     * @returns {number|null} Sekunden, oder null wenn nichts Gueltiges gewaehlt ist
     */
    wunschSekunden() {
        const gewaehlt = document.querySelector('.loc-req__preset--on');
        if (gewaehlt) return parseInt(gewaehlt.getAttribute('data-seconds'), 10) || 0;

        const feld = document.getElementById('loc-req-wish');
        if (!feld || !feld.value) return null;

        const ziel = Date.parse(feld.value);
        if (isNaN(ziel)) return null;

        const sekunden = Math.round((ziel - Date.now()) / 1000);
        // Ein Zeitpunkt in der Vergangenheit ist kein Wunsch, sondern ein
        // Vertipper. Ein paar Sekunden Rueckstand sind dagegen die Zeit
        // zwischen Auswahl und Klick - das ist "jetzt sofort".
        if (sekunden < -60) return null;
        return Math.max(0, sekunden);
    },

    /**
     * Schickt die Anfrage.
     *
     * Uebernommen wird, was der SERVER antwortet. Weist er ab - der Standort
     * ist gesperrt, es laeuft schon eine Anfrage, der Zeitpunkt liegt zu weit
     * voraus -, steht danach der wahre Zustand da und nicht der gewuenschte.
     */
    stelleAnfrage() {
        if (!this.daten || this.busy) return;

        const sekunden = this.wunschSekunden();
        if (sekunden === null) {
            window.webrtcApp.notify.error(
                'Bitte einen Wunschzeitpunkt wählen – "Jetzt sofort" ist auch einer.'
            );
            return;
        }

        this.busy = true;
        fetch('index.php?act=request_create', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ location: this.daten.id, wish_in: sekunden })
        })
        .then(r => r.json())
        .then(antwort => {
            this.busy = false;
            if (!antwort || !antwort.success) {
                window.webrtcApp.notify.error(
                    (antwort && antwort.error) || 'Die Anfrage konnte nicht gestellt werden.'
                );
                // Auch im Fehlerfall kann eine Anfrage mitkommen: "es laeuft
                // schon eine" ist eine Absage MIT Zustand.
                if (antwort && antwort.request) this.applyRequest(antwort.request);
                return;
            }
            this.applyRequest(antwort.request || null);
            window.webrtcApp.notify.success(
                'Anfrage gestellt. Der Guide antwortet – Sie sehen es hier und am Zähler oben.'
            );
        })
        .catch(() => {
            this.busy = false;
            window.webrtcApp.notify.error('Keine Verbindung. Bitte erneut versuchen.');
        });
    },

    /**
     * Zieht die eigene Anfrage zurueck.
     *
     * MIT RUECKFRAGE, anders als beim Bereitschaftsschalter: Der Guide hat
     * womoeglich schon zugesagt und sich die Zeit genommen. Ein versehentlicher
     * Klick soll das nicht wegwerfen.
     *
     * @param {string} id
     */
    ziehZurueck(id) {
        const nummer = parseInt(id, 10);
        if (!nummer || this.busy) return;

        window.webrtcApp.notify.confirm({
            title: 'Anfrage zurückziehen?',
            text: 'Der Guide sieht dann, dass die Führung nicht stattfindet.',
            confirmText: 'Zurückziehen'
        }).then(ja => {
            if (!ja) return;
            this.busy = true;

            fetch('index.php?act=request_cancel', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: nummer })
            })
            .then(r => r.json())
            .then(antwort => {
                this.busy = false;
                if (!antwort || !antwort.success) {
                    window.webrtcApp.notify.error(
                        (antwort && antwort.error) || 'Das hat nicht geklappt.'
                    );
                    this.loadState();
                    return;
                }
                // Direkt und nicht ueber applyRequest(): Das Verschwinden ist
                // hier kein Ereignis, das gemeldet werden muesste - der
                // Nutzer hat es selbst ausgeloest, und die Rueckmeldung
                // steht in der naechsten Zeile.
                this.daten.request = null;
                this.renderAktion();
                window.webrtcApp.notify.info('Anfrage zurückgezogen.');
            })
            .catch(() => {
                this.busy = false;
                window.webrtcApp.notify.error('Keine Verbindung. Bitte erneut versuchen.');
            });
        });
    },

    /**
     * Uebernimmt einen neuen Anfragezustand.
     *
     * Neu gezeichnet wird nur, wenn sich wirklich etwas geaendert hat: Wer
     * gerade eine Uhrzeit eintippt, soll sein Feld nicht alle fuenfzehn
     * Sekunden geleert bekommen.
     *
     * @param {Object|null} anfrage
     */
    applyRequest(anfrage) {
        if (!this.daten) return;
        if (this.kennung(anfrage) === this.kennung(this.daten.request)) return;

        // VERSCHWUNDEN heisst: abgelehnt, abgelaufen oder abgesagt - der
        // Server liefert hier nur LAUFENDE Anfragen. Welcher der drei Faelle
        // es war, steht auf der Anfragenseite; hier wuerde ein geratener Grund
        // falsch dastehen. Ohne diesen Hinweis taeuscht der wieder
        // auftauchende Kasten dem Kunden an, er haette nie angefragt.
        const verschwunden = this.daten.request && !anfrage;

        this.daten.request = anfrage;
        this.renderAktion();

        if (verschwunden) {
            window.webrtcApp.notify.info(
                'Ihre Anfrage ist nicht mehr offen. Was daraus geworden ist, steht unter "Anfragen".'
            );
        }
    },

    /**
     * Was an einer Anfrage sichtbar ist - als Zeichenkette zum Vergleichen.
     *
     * Nur diese drei Angaben aendern die Darstellung. Der Rest (Zeitpunkte,
     * Namen) steht fest, solange die Anfrage dieselbe ist.
     *
     * @param {Object|null} anfrage
     * @returns {string}
     */
    kennung(anfrage) {
        if (!anfrage) return 'keine';
        return [anfrage.id, anfrage.status, anfrage.callable ? 1 : 0].join(':');
    },

    /**
     * Zeichnet den Aktionsbereich neu.
     *
     * DIESELBEN FAELLE WIE SERVERSEITIG (App\Helper\LocationView::aktionHtml).
     * Es sind zwei Bauorte fuer dieselben Kaesten - eine Doppelung, die
     * bleibt, solange die Seite fertig ausgeliefert und im Browser
     * nachgezogen wird; dieselbe Lage wie bei der Zustandsmarke darueber.
     *
     * Was hier NICHT nachgebaut wird: der Fall des Gastes und der des
     * Eigentuemers. Beide aendern sich nicht, waehrend die Seite offen liegt -
     * und bei beiden gibt es nichts nachzuziehen.
     */
    renderAktion() {
        const bereich = document.getElementById('loc-action');
        if (!bereich || !this.daten || this.daten.isOwn || this.daten.blocked) return;
        if (!this.daten.userId) return;

        bereich.innerHTML = this.daten.request
            ? this.anfrageZustandHtml(this.daten.request)
            : this.anfrageFormularHtml();

        // Das frisch gebaute Formular traegt dieselbe Vorauswahl wie das des
        // Servers - und dasselbe leere Feld. Auch hier nachziehen; bei einem
        // Zustandskasten findet zeigeWunschzeit() kein Feld und tut nichts.
        this.zeigeWunschzeit();
        this.pruefeZeit();
    },

    /**
     * Das Anfrageformular als HTML.
     *
     * Die Abstaende stehen hier UND serverseitig - siehe renderAktion(). Wer
     * einen ergaenzt, ergaenzt ihn an beiden Stellen; der Wert, der beim
     * Server ankommt, ist derselbe.
     *
     * @returns {string} HTML
     */
    anfrageFormularHtml() {
        const live = this.daten.availability === 'live';
        const hinweis = live
            ? 'Der Guide ist gerade bereit – „jetzt sofort“ hat gute Aussichten.'
            : 'Gerade ist niemand vor Ort. Das ist kein Hindernis: Fragen Sie für '
            + 'später an, und der Guide sagt zu oder ab.';

        const vorgaben = [
            [0,     'Jetzt sofort'],
            [3600,  'In 1 Stunde'],
            [10800, 'In 3 Stunden'],
            [86400, 'Morgen um diese Zeit']
        ];

        const knoepfe = vorgaben.map(([sek, text], i) =>
            '<button type="button" class="btn btn-sm loc-req__preset'
            + (i === 0 ? ' loc-req__preset--on' : '') + '"'
            + ' data-seconds="' + sek + '"'
            + ' aria-pressed="' + (i === 0 ? 'true' : 'false') + '">'
            + this.esc(text) + '</button>').join('');

        return '<div class="loc-req" id="loc-request">'
             +   '<p class="loc__note">' + this.esc(hinweis) + '</p>'
             +   '<div class="loc-req__presets" role="group" aria-label="Wunschzeitpunkt">'
             +     knoepfe
             +   '</div>'
             +   '<div class="loc-req__custom">'
             +     '<label class="loc-req__label" for="loc-req-wish">Anderer Zeitpunkt</label>'
             +     '<input type="datetime-local" id="loc-req-wish" class="form-control loc-req__field">'
             +   '</div>'
             +   '<p class="loc-req__hint" id="loc-req-hint" hidden></p>'
             +   '<button type="button" class="btn btn-success loc-req-submit"'
             +     ' data-locationid="' + (parseInt(this.daten.id, 10) || 0) + '">Führung anfragen</button>'
             + '</div>';
    },

    /**
     * Der Zustand einer laufenden Anfrage als HTML.
     *
     * @param {Object} anfrage
     * @returns {string} HTML
     */
    anfrageZustandHtml(anfrage) {
        const id       = parseInt(anfrage.id, 10) || 0;
        const anrufbar = this.wahr(anfrage.callable) && !!this.daten.userId;
        const wann     = this.wunschzeitText(anfrage);

        if (anfrage.status === 'open') {
            return '<div class="loc-req loc-req--state" id="loc-request">'
                 +   '<span class="app-tag app-tag--warn">Anfrage offen</span>'
                 +   '<p class="loc__note">Ihre Anfrage für <strong>' + this.esc(wann)
                 +     '</strong> ist beim Guide. Sobald er antwortet, sehen Sie es hier '
                 +     'und am Zähler in der Kopfleiste.</p>'
                 +   '<button type="button" class="btn btn-secondary btn-sm loc-req-cancel"'
                 +     ' data-id="' + id + '">Anfrage zurückziehen</button>'
                 + '</div>';
        }

        const text = anrufbar
            ? 'Der Guide hat zugesagt. Sie können jetzt starten – er wird angerufen.'
            : 'Der Guide hat für ' + wann + ' zugesagt. Kurz vorher lässt sich die '
            + 'Führung von hier aus starten.';

        return '<div class="loc-req loc-req--state" id="loc-request">'
             +   '<span class="app-tag app-tag--live"><span class="app-dot"></span>Angenommen</span>'
             +   '<p class="loc__note">' + this.esc(text) + '</p>'
             +   '<button type="button" class="btn ' + (anrufbar ? 'btn-success' : 'btn-secondary')
             +     ' loc-call-btn"' + (anrufbar ? '' : ' disabled aria-disabled="true"')
             +     (anrufbar ? ' data-userid="' + (parseInt(this.daten.userId, 10) || 0) + '"'
                             + ' data-locationid="' + (parseInt(this.daten.id, 10) || 0) + '"' : '')
             +     '>Führung starten</button>'
             +   '<button type="button" class="btn btn-secondary btn-sm loc-req-cancel"'
             +     ' data-id="' + id + '">Absagen</button>'
             + '</div>';
    },

    // -----------------------------------------------------------------
    // Die ueblichen Zeiten
    // -----------------------------------------------------------------

    /**
     * Haelt den Wunschzeitpunkt gegen die ueblichen Zeiten des Standorts.
     *
     * ZWEI AUSKUENFTE, und beide sind Hinweise und keine Sperre:
     *
     *   1. DIE ORTSZEIT, wenn Standort und Kunde in verschiedenen Zonen
     *      liegen. "18 Uhr" heisst in Tokio etwas anderes als in Lissabon,
     *      und verabredet wird die Zeit AM ORT DER FUEHRUNG.
     *   2. DER HINWEIS, wenn der Zeitpunkt ausserhalb der ueblichen Zeiten
     *      liegt. Anfragen darf man trotzdem - der Guide entscheidet, nicht
     *      dieses Formular.
     *
     * Das RASTER kommt vom Server (daten.hours.parts) und steht nicht hier:
     * Die Grenzen der Tagesabschnitte gehoeren an eine Stelle
     * (App\Helper\Availability), sonst hiesse "abends" im Browser bald etwas
     * anderes als in der Datenbank.
     *
     * Fehlt die Zeitzonenunterstuetzung des Browsers, bleibt der Hinweis
     * leer: Er ist eine Zugabe, und eine falsche Zeit waere schlechter als
     * keine.
     */
    pruefeZeit() {
        const feld = document.getElementById('loc-req-hint');
        if (!feld || !this.daten) return;

        const zeiten = this.daten.hours;
        const wunsch = this.wunschZeitpunkt();
        if (!zeiten || !zeiten.timezone || !wunsch) { feld.hidden = true; return; }

        const teile = this.teileIn(wunsch, zeiten.timezone);
        if (!teile) { feld.hidden = true; return; }

        const saetze = [];

        // 1. Die Ortszeit - nur wenn sie sich von der des Kunden unterscheidet.
        if (this.zonenversatz(wunsch, zeiten.timezone) !== this.eigenerVersatz(wunsch)) {
            saetze.push('Das ist <strong>' + this.esc(teile.wochentag + ', ' + teile.stunde
                      + ':' + teile.minute) + '</strong> Ortszeit am Treffpunkt.');
        }

        // 2. Der Hinweis ausserhalb der ueblichen Zeiten. Ohne Angaben des
        //    Guides gibt es nichts anzumerken - aus fehlender Auskunft folgt
        //    kein Vorwurf.
        if (zeiten.slots && !this.imRaster(zeiten, teile)) {
            saetze.push('Das liegt außerhalb der üblichen Zeiten ('
                      + this.esc(zeiten.text) + '). Anfragen können Sie trotzdem – '
                      + 'der Guide entscheidet.');
        }

        feld.innerHTML = saetze.join(' ');
        feld.hidden = (saetze.length === 0);
    },

    /**
     * Der gewaehlte Wunschzeitpunkt als Date.
     *
     * Er kommt aus derselben Quelle wie beim Abschicken (wunschSekunden):
     * die markierte Vorgabe, sonst das Feld. Zwei Wege zur selben Frage
     * waeren zwei Antworten - der Hinweis muss den Zeitpunkt meinen, der
     * gleich beim Server ankommt.
     *
     * @returns {Date|null}
     */
    wunschZeitpunkt() {
        const sekunden = this.wunschSekunden();
        if (sekunden === null) return null;
        return new Date(Date.now() + sekunden * 1000);
    },

    /**
     * Wochentag, Stunde und Minute eines Zeitpunkts in einer Zeitzone.
     *
     * ueber Intl und nicht ueber eigene Rechnerei: Die Sommerzeit wechselt in
     * jeder Zone zu einem anderen Datum, und diese Tabelle bringt der Browser
     * mit. 'en-GB' und h23 stehen fest, damit die Teile ueberall gleich
     * heissen - uebersetzt wird erst bei der Ausgabe.
     *
     * @param {Date}   datum
     * @param {string} zone
     * @returns {Object|null} { tag, wochentag, stunde, minute } oder null
     */
    teileIn(datum, zone) {
        try {
            const f = new Intl.DateTimeFormat('en-GB', {
                timeZone: zone, hourCycle: 'h23',
                weekday: 'short', year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit'
            });
            const t = {};
            f.formatToParts(datum).forEach(teil => { t[teil.type] = teil.value; });

            const tage = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            const tag  = tage.indexOf(t.weekday);
            if (tag < 0) return null;

            return {
                tag:       tag,
                wochentag: this.TAGE_LANG[tag],
                stunde:    t.hour,
                minute:    t.minute,
                jahr:      t.year,
                monat:     t.month,
                datum:     t.day
            };
        } catch (e) {
            // Ein Browser ohne Zeitzonendaten. Kein Fehlerfall - nur keine
            // Auskunft.
            return null;
        }
    },

    /** Die Wochentage, wie sie im Hinweis stehen. */
    TAGE_LANG: ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'],

    /**
     * Faellt dieser Zeitpunkt in eines der angekreuzten Felder?
     *
     * Dieselbe Rechnung wie auf dem Server (Availability::passt): Stelle im
     * Muster ist Wochentag mal Zahl der Abschnitte plus Abschnitt.
     *
     * @param {Object} zeiten Aus den Seitendaten
     * @param {Object} teile  Aus teileIn()
     * @returns {boolean}
     */
    imRaster(zeiten, teile) {
        const abschnitte = zeiten.parts || [];
        const stunde = parseInt(teile.stunde, 10);

        let index = -1;
        abschnitte.forEach((a, i) => {
            if (index >= 0) return;
            // Der Abschnitt ueber Mitternacht (von > bis) gilt in zwei
            // Stuecken - dieselbe Regel wie in Availability.
            const passt = (a.von > a.bis)
                ? (stunde >= a.von || stunde < a.bis)
                : (stunde >= a.von && stunde < a.bis);
            if (passt) index = i;
        });
        if (index < 0) return false;

        return zeiten.slots.charAt(teile.tag * abschnitte.length + index) === '1';
    },

    /**
     * Wie viele Minuten liegt eine Zone vor UTC - zu diesem Zeitpunkt?
     *
     * "Zu diesem Zeitpunkt" ist der Kern: Im Juli steht Berlin anders zu UTC
     * als im Januar. Gerechnet wird ueber den Umweg der formatierten Teile -
     * die Zeitzonendaten hat nur der Browser.
     *
     * @param {Date}   datum
     * @param {string} zone
     * @returns {number|null}
     */
    zonenversatz(datum, zone) {
        const t = this.teileIn(datum, zone);
        if (!t) return null;

        // Sekunden und Millisekunden werden UNVERAENDERT uebernommen: Die
        // formatierten Teile reichen nur bis zur Minute, und wuerde hier auf
        // Null gesetzt, was der Zeitpunkt an Sekunden traegt, faehrt die
        // Differenz um bis zu einer Minute daneben - fast jede Sekunde einer
        // Minute ergaebe dann einen Versatz, der um eine Minute zu klein ist.
        // So bleibt der Unterschied ein glattes Vielfaches von 60000.
        const alsUtc = Date.UTC(parseInt(t.jahr, 10), parseInt(t.monat, 10) - 1,
                                parseInt(t.datum, 10), parseInt(t.stunde, 10),
                                parseInt(t.minute, 10), datum.getUTCSeconds(),
                                datum.getUTCMilliseconds());
        return Math.round((alsUtc - datum.getTime()) / 60000);
    },

    /**
     * Der Versatz der Zone des Kunden - also des Browsers.
     *
     * getTimezoneOffset() zaehlt andersherum (Minuten, die UTC voraus ist),
     * deshalb das Minus.
     *
     * @param {Date} datum
     * @returns {number}
     */
    eigenerVersatz(datum) {
        return -datum.getTimezoneOffset();
    },

    /**
     * Schreibt in den Kasten der ueblichen Zeiten, wie weit die Ortszeit von
     * der des Kunden abweicht.
     *
     * NUR WENN SIE ABWEICHT. Steht der Kunde in derselben Zone, waere der
     * Satz eine Zeile ohne Auskunft - und der haeufigste Fall ist, dass beide
     * in derselben Zone sind.
     */
    zeigeZonenunterschied() {
        const kasten = document.getElementById('loc-hours');
        const ziel   = document.getElementById('loc-hours-diff');
        if (!kasten || !ziel) return;

        const zone = kasten.getAttribute('data-timezone');
        if (!zone) return;

        const jetzt = new Date();
        const dort  = this.zonenversatz(jetzt, zone);
        if (dort === null) return;

        const unterschied = dort - this.eigenerVersatz(jetzt);
        if (unterschied === 0) return;

        const stunden = Math.abs(unterschied) / 60;
        // Halbe und dreiviertel Stunden gibt es wirklich (Indien, Nepal,
        // Teile Australiens) - deshalb keine ganzzahlige Rechnung.
        const text = (Number.isInteger(stunden) ? stunden : stunden.toFixed(2).replace(/0+$/, '').replace('.', ','))
                   + (stunden === 1 ? ' Stunde' : ' Stunden');

        ziel.textContent = ' · dort ist es ' + text + (unterschied > 0 ? ' später' : ' früher') + ' als bei Ihnen';
    },

    /**
     * Der Wunschzeitpunkt als Text - relativ, wie ueberall.
     *
     * Der Abstand kommt fertig gerechnet vom Server (wish_in). Er hat keine
     * Zeitzone, und genau darum steht hier keine Uhrzeit: Guide und Kunde
     * sitzen womoeglich in verschiedenen.
     *
     * @param {Object} anfrage
     * @returns {string}
     */
    wunschzeitText(anfrage) {
        const s = parseInt(anfrage.wish_in, 10);
        if (isNaN(s)) return 'den vereinbarten Zeitpunkt';
        if (s <= 60 && s >= -60) return 'jetzt';

        const minuten = Math.round(Math.abs(s) / 60);
        let dauer;
        if (minuten < 60) {
            dauer = minuten + ' Minuten';
        } else {
            const stunden = Math.round(minuten / 60);
            dauer = stunden < 24 ? stunden + ' Stunden' : Math.round(stunden / 24) + ' Tagen';
        }
        return (s > 0 ? 'in ' : 'vor ') + dauer;
    },

    /**
     * MySQL liefert Wahrheitswerte als 0/1 - je nach Treiber als Zahl oder als
     * Zeichenkette.
     *
     * @param {*} wert
     * @returns {boolean}
     */
    wahr(wert) {
        return wert === true || wert === 1 || wert === '1';
    },

    /**
     * Maskiert Text fuer die Ausgabe.
     *
     * @param {*} wert
     * @returns {string}
     */
    esc(wert) {
        return String(wert === null || wert === undefined ? '' : wert)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    },

    /** Laeuft gerade eine Anfrage an den Server? Verhindert Doppelklicks. */
    busy: false,

    // -----------------------------------------------------------------
    // Bearbeiten
    // -----------------------------------------------------------------

    /**
     * Haengt das Bearbeitungsformular ein.
     *
     * Wird nur aufgerufen, wenn der Server es geliefert hat - also beim
     * Eigentuemer.
     */
    bindEdit() {
        const bereich = document.getElementById('loc-edit-body');
        const knopf   = document.getElementById('loc-edit-toggle');
        if (!bereich || !knopf) return;

        knopf.addEventListener('click', () => this.toggleEdit(bereich.hidden));
        document.getElementById('loc-edit-cancel')
            ?.addEventListener('click', () => this.toggleEdit(false));

        // Aufgeklappt ankommen: Der Server schickt nach einer abgelehnten
        // Aenderung auf diese Seite zurueck (edit=1). Stuende das Formular
        // dann zu, saehe der Guide nur die Fehlermeldung und nicht das Feld,
        // um das es geht.
        if (new URLSearchParams(window.location.search).get('edit') === '1') {
            this.toggleEdit(true);
        }

        this.bindImages();
        this.bindZeitraster();
    },

    /**
     * Die Schnellwahl im Zeitraster.
     *
     * Ein Klick auf einen Wochentag setzt oder loescht die ganze Zeile, ein
     * Klick auf einen Tagesabschnitt die ganze Spalte. "Immer abends" sind
     * damit ein Klick statt sieben.
     *
     * GESETZT WIRD, WAS FEHLT: Ist die Reihe noch nicht vollstaendig, wird
     * sie vollstaendig; ist sie es schon, wird sie geleert. Das ist die
     * Erwartung an einen solchen Knopf, und sie kommt ohne einen dritten
     * Zustand aus.
     *
     * OHNE SKRIPT bleiben es 28 gewoehnliche Kaestchen: Das Formular
     * funktioniert genauso, es dauert nur laenger. Deshalb sind die Kaestchen
     * echte Eingabefelder und diese Knoepfe nur eine Abkuerzung.
     */
    bindZeitraster() {
        const raster = document.querySelector('.loc-grid');
        if (!raster) return;

        raster.addEventListener('click', (e) => {
            const knopf = e.target.closest('.loc-grid__all');
            if (!knopf) return;
            e.preventDefault();

            const zeile  = knopf.getAttribute('data-zeile');
            const spalte = knopf.getAttribute('data-spalte');

            const felder = Array.from(raster.querySelectorAll('input[type="checkbox"]'))
                .filter(feld => {
                    const wert = feld.value || '';
                    if (zeile)  return wert.indexOf(zeile + '-') === 0;
                    if (spalte) return wert.slice(-(spalte.length + 1)) === '-' + spalte;
                    return false;
                });
            if (felder.length === 0) return;

            const alleAn = felder.every(feld => feld.checked);
            felder.forEach(feld => { feld.checked = !alleAn; });
        });
    },

    /**
     * Klappt das Formular auf oder zu.
     * @param {boolean} auf
     */
    toggleEdit(auf) {
        const bereich = document.getElementById('loc-edit-body');
        const knopf   = document.getElementById('loc-edit-toggle');
        if (!bereich || !knopf) return;

        bereich.hidden = !auf;
        knopf.setAttribute('aria-expanded', auf ? 'true' : 'false');
        knopf.textContent = auf ? 'Schließen' : 'Bearbeiten';
    },

    // -----------------------------------------------------------------
    // Bilder verwalten
    // -----------------------------------------------------------------

    /** Haengt Hochladen, Loeschen und Sortieren ein. */
    bindImages() {
        const feld  = document.getElementById('loc-image-input');
        const knopf = document.getElementById('loc-image-add');
        const liste = document.getElementById('loc-image-list');
        if (!feld || !knopf || !liste) return;

        knopf.addEventListener('click', () => feld.click());
        feld.addEventListener('change', () => {
            const datei = feld.files && feld.files[0];
            // Das Feld wird geleert, damit dieselbe Datei ein zweites Mal
            // ausgewaehlt werden kann - sonst loest 'change' nicht aus.
            feld.value = '';
            if (datei) this.uploadImage(datei);
        });

        liste.addEventListener('click', (e) => {
            const kachel = e.target.closest('.loc-edit__image');
            if (!kachel) return;

            if (e.target.closest('.loc-img-cover')) { this.setCover(kachel); return; }
            if (e.target.closest('.loc-img-del'))   { this.deleteImage(kachel); return; }
            if (e.target.closest('.loc-img-up'))    { this.moveImage(kachel, -1); return; }
            if (e.target.closest('.loc-img-down'))  { this.moveImage(kachel,  1); return; }
        });

        // Das Titelbild zurueck in die Galerie. Der Knopf steht im
        // Titelbild-Abschnitt darueber und nicht an einer Kachel - er gehoert
        // zu dem einen Bild, das dort steht.
        document.getElementById('loc-cover-clear')
            ?.addEventListener('click', () => this.clearCover());

        this.updateImageHint();
    },

    /**
     * Waehlt eine Kachel als Titelbild aus.
     *
     * DIE SEITE WIRD DANACH NEU GELADEN, und das ist bewusst: Ein neues
     * Titelbild aendert den Kopf der Seite, nimmt das bisherige zurueck in die
     * Galerie und ordnet damit beide Listen neu. Das im Browser nachzubauen
     * hiesse, dieselbe Aufteilung ein zweites Mal zu programmieren - einmal
     * hier und einmal in App\Helper\LocationView. Zwei Fassungen derselben
     * Regel laufen auseinander.
     *
     * @param {Element} kachel
     */
    setCover(kachel) {
        const id = kachel.getAttribute('data-imageid');
        if (!id) return;

        fetch('index.php?act=set_location_cover&id=' + encodeURIComponent(id),
              { method: 'POST', credentials: 'same-origin' })
            .then(r => r.json())
            .then(antwort => {
                if (!antwort || !antwort.success) {
                    window.webrtcApp.notify.error(
                        (antwort && antwort.error) || 'Das Titelbild konnte nicht gesetzt werden.');
                    return;
                }
                window.location.reload();
            })
            .catch(() => window.webrtcApp.notify.error(
                'Das Titelbild konnte nicht gesetzt werden.'));
    },

    /**
     * Nimmt das Titelbild zurueck in die Galerie.
     *
     * Zurueckstufen, nicht loeschen: Wer sein Titelbild absetzt, will fast
     * immer ein anderes waehlen und nicht dieses Bild verlieren. Deshalb auch
     * keine Rueckfrage - es geht nichts verloren.
     */
    clearCover() {
        if (!this.daten) return;

        const daten = new URLSearchParams();
        daten.set('location_id', String(this.daten.id));

        fetch('index.php?act=unset_location_cover', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: daten.toString()
        })
            .then(r => r.json())
            .then(antwort => {
                if (!antwort || !antwort.success) {
                    window.webrtcApp.notify.error(
                        (antwort && antwort.error) || 'Das Titelbild konnte nicht geändert werden.');
                    return;
                }
                window.location.reload();
            })
            .catch(() => window.webrtcApp.notify.error(
                'Das Titelbild konnte nicht geändert werden.'));
    },

    /**
     * Prueft eine Datei, bevor sie hochgeladen wird.
     *
     * DIE GRENZEN KOMMEN VOM SERVER (window.locationPage.upload, gespeist aus
     * config/uploads.php). Hier steht keine zweite Zahl - sonst liefen zwei
     * Werte auseinander, und der Nutzer bekaeme erst nach acht Megabyte
     * Uebertragung zu hoeren, dass es zu viel war.
     *
     * Die Pruefung ist eine Hoeflichkeit, keine Absicherung: Verbindlich ist
     * die des Servers (App\Helper\ImageStore::store).
     *
     * @param {File} datei
     * @returns {string} Fehlertext, oder Leerstring wenn in Ordnung
     */
    checkFile(datei) {
        const grenzen = (this.daten && this.daten.upload) || {};

        if (grenzen.accept && datei.type && grenzen.accept.split(',').indexOf(datei.type) === -1) {
            return 'Dieses Bildformat wird nicht angenommen (JPEG, PNG oder WebP).';
        }
        if (grenzen.maxBytes && datei.size > grenzen.maxBytes) {
            return 'Die Datei ist zu groß – erlaubt sind '
                 + Math.round(grenzen.maxBytes / 1048576) + ' MB.';
        }
        return '';
    },

    /**
     * Laedt eine Datei hoch und haengt die neue Kachel an.
     *
     * @param {File} datei
     */
    uploadImage(datei) {
        const fehler = this.checkFile(datei);
        if (fehler) { window.webrtcApp.notify.error(fehler); return; }

        const knopf = document.getElementById('loc-image-add');
        if (knopf) knopf.disabled = true;

        const daten = new FormData();
        daten.append('location_id', String(this.daten.id));
        daten.append('image', datei);

        fetch('index.php?act=upload_location_image',
              { method: 'POST', body: daten, credentials: 'same-origin' })
            .then(r => r.json())
            .then(antwort => {
                if (!antwort || !antwort.success) {
                    window.webrtcApp.notify.error(
                        (antwort && antwort.error) || 'Das Bild konnte nicht gespeichert werden.');
                    return;
                }
                // Wurde das Bild zum TITELBILD - weil der Standort noch
                // keines hatte -, gehoert es nicht in die Galerie, sondern in
                // den Abschnitt darueber. Statt beide Listen hier nachzubauen,
                // laedt die Seite neu; der Server teilt sie ohnehin.
                if (antwort.image && antwort.image.role === 'cover') {
                    window.location.reload();
                    return;
                }
                this.appendImage(antwort.image);
                window.webrtcApp.notify.success('Bild hinzugefügt.');
            })
            .catch(() => window.webrtcApp.notify.error('Das Bild konnte nicht gespeichert werden.'))
            .then(() => {
                if (knopf) knopf.disabled = false;
                this.updateImageHint();
            });
    },

    /**
     * Haengt eine neue Kachel an die Liste.
     *
     * Ohne Neuladen der Seite: Wer drei Bilder nacheinander hochlaedt, soll
     * nicht dreimal eine Seite aufbauen und dreimal zum Formular
     * zurueckscrollen.
     *
     * @param {Object} bild { id, thumb, full }
     */
    appendImage(bild) {
        const liste = document.getElementById('loc-image-list');
        if (!liste || !bild) return;

        liste.querySelector('[data-empty]')?.remove();

        const nr = liste.querySelectorAll('.loc-edit__image').length + 1;
        const li = document.createElement('li');
        li.className = 'loc-edit__image';
        li.setAttribute('data-imageid', String(bild.id));
        li.innerHTML =
              '<img src="' + this.esc(bild.thumb) + '" alt="Bild ' + nr + '">'
            + '<div class="loc-edit__imageActions">'
            +   '<button type="button" class="app-iconbtn app-iconbtn--cover loc-img-cover"'
            +   ' aria-label="Bild ' + nr + ' als Titelbild verwenden" title="Als Titelbild"></button>'
            +   '<button type="button" class="app-iconbtn app-iconbtn--up loc-img-up"'
            +   ' aria-label="Bild ' + nr + ' nach vorne" title="Nach vorne"></button>'
            +   '<button type="button" class="app-iconbtn app-iconbtn--down loc-img-down"'
            +   ' aria-label="Bild ' + nr + ' nach hinten" title="Nach hinten"></button>'
            +   '<button type="button" class="app-iconbtn app-iconbtn--delete app-iconbtn--danger loc-img-del"'
            +   ' aria-label="Bild ' + nr + ' löschen" title="Löschen"></button>'
            + '</div>';
        liste.appendChild(li);
    },

    /**
     * Loescht ein Bild nach Rueckfrage.
     *
     * @param {Element} kachel
     */
    deleteImage(kachel) {
        const id = kachel.getAttribute('data-imageid');
        if (!id) return;

        window.webrtcApp.notify.confirm({
            title: 'Bild löschen?',
            text: 'Das Bild verschwindet von der Standortseite. Das lässt sich nicht rückgängig machen.',
            confirmText: 'Löschen',
            danger: true
        }).then(ja => {
            if (!ja) return;

            fetch('index.php?act=delete_location_image&id=' + encodeURIComponent(id),
                  { method: 'POST', credentials: 'same-origin' })
                .then(r => r.json())
                .then(antwort => {
                    if (!antwort || !antwort.success) {
                        window.webrtcApp.notify.error(
                            (antwort && antwort.error) || 'Das Bild konnte nicht gelöscht werden.');
                        return;
                    }
                    kachel.remove();
                    // War es das letzte, kommt der Hinweis zurueck. Eine
                    // leere Liste ohne Text sieht aus wie ein Fehler.
                    const liste = document.getElementById('loc-image-list');
                    if (liste && liste.querySelectorAll('.loc-edit__image').length === 0) {
                        liste.innerHTML =
                            '<p class="loc__empty" data-empty>Noch keine Beispielbilder hochgeladen.</p>';
                    }
                    this.updateImageHint();
                })
                .catch(() => window.webrtcApp.notify.error('Das Bild konnte nicht gelöscht werden.'));
        });
    },

    /**
     * Verschiebt eine Kachel um eine Position und speichert die Reihenfolge.
     *
     * Knoepfe statt Ziehen: Ziehen funktioniert auf einem Mobilgeraet
     * schlecht und mit der Tastatur gar nicht.
     *
     * @param {Element} kachel
     * @param {number}  richtung -1 nach vorn, 1 nach hinten
     */
    moveImage(kachel, richtung) {
        const nachbar = richtung < 0
            ? kachel.previousElementSibling
            : kachel.nextElementSibling;
        if (!nachbar || !nachbar.classList.contains('loc-edit__image')) return;

        if (richtung < 0) nachbar.before(kachel);
        else              nachbar.after(kachel);

        this.saveOrder();
    },

    /**
     * Die Reihenfolge, wie sie gerade in der Liste steht.
     *
     * Eigene Methode und ohne Seiteneffekte, damit sich die Reihenfolge
     * pruefen laesst, ohne etwas zu schicken.
     *
     * @param {Element} [liste]
     * @returns {string[]} Bild-IDs in ihrer Folge
     */
    currentOrder(liste) {
        const el = liste || document.getElementById('loc-image-list');
        if (!el) return [];
        return Array.from(el.querySelectorAll('.loc-edit__image'))
            .map(k => k.getAttribute('data-imageid'))
            .filter(Boolean);
    },

    /**
     * Schickt die vollstaendige neue Reihenfolge.
     *
     * Vollstaendig und nicht "dieses Bild eins nach vorn": Das Umsortieren
     * ist EINE Entscheidung des Nutzers und wird als eine gespeichert. Sonst
     * stuende die Reihenfolge zwischen zwei Aufrufen in einem Zustand, den
     * niemand gewollt hat.
     */
    saveOrder() {
        const folge = this.currentOrder();
        if (folge.length === 0) return;

        const daten = new URLSearchParams();
        daten.set('location_id', String(this.daten.id));
        daten.set('order', folge.join(','));

        fetch('index.php?act=sort_location_images', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: daten.toString()
        })
            .then(r => r.json())
            .then(antwort => {
                if (antwort && antwort.success) return;
                window.webrtcApp.notify.error(
                    (antwort && antwort.error) || 'Die Reihenfolge konnte nicht gespeichert werden.');
            })
            .catch(() => window.webrtcApp.notify.error(
                'Die Reihenfolge konnte nicht gespeichert werden.'));
    },

    /**
     * Schreibt, wie viele Bilder noch gehen, und sperrt den Knopf, wenn die
     * Obergrenze erreicht ist.
     *
     * Die Grenze kommt vom Server (config/uploads.php ueber
     * ImageStore::maxImages). Sie wird hier NICHT durchgesetzt - das tut der
     * Controller vor dem Annehmen; hier steht sie, damit der Nutzer den
     * Knopf nicht drueckt, um eine Absage zu bekommen.
     */
    updateImageHint() {
        const liste = document.getElementById('loc-image-list');
        const text  = document.getElementById('loc-image-count');
        const knopf = document.getElementById('loc-image-add');
        if (!liste || !this.daten) return;

        // DAS TITELBILD ZAEHLT MIT. Die Obergrenze gilt fuer die SUMME beider
        // Arten - sonst waere sie ueber den Umweg "als Titelbild markieren"
        // zu umgehen.
        const cover  = document.querySelector('.loc-edit__cover') ? 1 : 0;
        const anzahl = liste.querySelectorAll('.loc-edit__image').length + cover;
        const grenze = this.daten.maxImages || 0;
        const frei   = Math.max(0, grenze - anzahl);

        if (text) {
            text.textContent = frei === 0
                ? 'Die Obergrenze von ' + grenze + ' Bildern ist erreicht (Titelbild mitgezählt).'
                : 'Noch ' + frei + ' von ' + grenze + ' Bildern möglich, Titelbild mitgezählt.';
        }
        if (knopf) knopf.disabled = (frei === 0);
    },

    /**
     * Maskiert Text fuer die Ausgabe in HTML.
     * @param {*} wert
     * @returns {string}
     */
    esc(wert) {
        return String(wert ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }
};

document.addEventListener('DOMContentLoaded', function () {
    window.webrtcApp.locationPage.init();
});
