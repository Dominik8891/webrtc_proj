/**
 * Die Startseite: Karte als Einstieg.
 *
 * WAS DIESES MODUL TUT
 * --------------------
 * Es holt die angebotenen Standorte und setzt sie als Nadeln auf eine
 * Leaflet-Karte. Vier Arten:
 *
 *   live  - ein Guide ist gerade erreichbar. Angemeldet fuehrt ein Klick zum
 *           Anruf, als Gast zur Anmeldung.
 *   busy  - ein Guide ist da, aber in einem anderen Gespraech.
 *   idle  - der Standort wird angeboten, gerade ist niemand vor Ort. Er
 *           bleibt sichtbar, sonst waere die Karte nachts leer und der
 *           Eindruck entstuende, es gebe das Angebot gar nicht.
 *   mine  - ein eigener Standort. Erkennbar als solcher und ohne Anrufknopf:
 *           sich selbst ruft niemand an.
 *
 * ZWEI QUELLEN, JE NACH ANMELDUNG
 * -------------------------------
 * Nicht angemeldet:  get_map_locations
 *     Die oeffentliche Karte. Sie enthaelt Ort, Beschreibung und einen von
 *     drei Verfuegbarkeitswerten - keinen Benutzernamen, keine user_id.
 *     Deshalb kann ein Gast von hier aus auch niemanden anrufen; der Klick
 *     fuehrt zur Anmeldung.
 *
 * Angemeldet:  get_locations + get_my_locations
 *     Die fremden Standorte (mit user_id, sonst liesse sich nicht anrufen)
 *     und die eigenen. Die eigenen kommen aus einer zweiten Route, weil
 *     get_locations sie ausdruecklich ausspart ("WHERE user.id != :user_id")
 *     - fuer die Tabelle richtig, fuer eine Karte falsch: Wer seinen eigenen
 *     Standort nicht sieht, kann nicht pruefen, ob er richtig liegt.
 *
 * WAS ES NICHT TUT
 * ----------------
 * Es entscheidet nichts ueber Berechtigungen. Welche Route wer aufrufen darf,
 * steht in config/routes.php und wird in index.php geprueft. Dass ein Gast
 * die oeffentliche Karte bekommt und nicht die vollstaendige Liste, ist eine
 * Entscheidung des Servers - hier wird sie nur beachtet, nicht getroffen.
 *
 * Bibliotheken: Leaflet und jQuery, beide sind bereits eingebunden. Es kommt
 * nichts Neues dazu.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.homeMap = {

    /** Kartenausschnitt, solange nichts anderes bekannt ist (Mitteleuropa). */
    DEFAULT_VIEW: { center: [50.5, 10.0], zoom: 4 },

    /**
     * Takt der Statusaktualisierung.
     *
     * Derselbe Wert wie in der Tabellenansicht (locations_table.js): etwas
     * laenger als der Heartbeat aus config/presence.php, damit nicht
     * haeufiger gefragt wird, als sich die Daten aendern koennen.
     */
    REFRESH_MS: 15000,

    map: null,
    layer: null,               // L.LayerGroup mit allen Nadeln
    markers: {},               // Nadeln je Standort-ID
    refreshTimer: null,
    fitted: false,             // Wurde der Ausschnitt schon einmal gesetzt?
    lastCount: null,           // Anzahl beim letzten Abruf; null = nie geladen
    hasOwn: false,             // Ist ein eigener Standort dabei?

    /**
     * Einstiegspunkt. Tut nichts, wenn die Seite gar keine Startseite ist.
     */
    init() {
        if (!document.getElementById('home')) return;

        // Leaflet kommt per CDN (assets/html/index.html). Fehlt es - Werbe-
        // blocker, kein Netz -, bleibt die Karte aus und der Nutzer bekommt
        // eine Erklaerung statt einer weissen Flaeche.
        if (typeof L === 'undefined') {
            this.showState('error');
            return;
        }

        // Die Startseite fuellt die Flaeche selbst aus; der Inhaltsbereich
        // gibt dafuer seinen Aussenabstand ab.
        document.getElementById('app-main')?.classList.add('app-main--flush');

        this.initMap();
        this.bindEvents();

        // Fuer einen Gast ist die Karte die Auslage und der Verweis daneben
        // die Erklaerung dazu.
        if (!window.isLoggedIn) {
            document.getElementById('home-explain')?.removeAttribute('hidden');
        }

        this.load(true);
        this.startAutoRefresh();
    },

    /**
     * Baut die Karte auf.
     */
    initMap() {
        this.map = L.map('home-map', {
            center: this.DEFAULT_VIEW.center,
            zoom: this.DEFAULT_VIEW.zoom,
            zoomControl: true,
            attributionControl: true
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap',
            maxZoom: 19
        }).addTo(this.map);

        this.layer = L.layerGroup().addTo(this.map);
    },

    /**
     * Bindet die Ereignisse, die nicht an einer einzelnen Nadel haengen.
     */
    bindEvents() {
        // Der Anrufknopf steckt im Kartenfenster einer Nadel. Leaflet baut das
        // Fenster erst beim Oeffnen und wirft es beim Schliessen weg - deshalb
        // ein Handler am Dokument statt einer je Knopf.
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.home-call-btn');
            if (!btn) return;
            e.preventDefault();
            this.startCall(btn.getAttribute('data-userid'));
        });

        document.getElementById('home-retry')?.addEventListener('click', () => {
            this.hideState();
            this.load(true);
        });

        // Gaeste koennen die Erklaerung jederzeit aufrufen, auch wenn die
        // Karte voll ist - fuer sie ist sie die Antwort auf "was ist das
        // ueberhaupt".
        document.getElementById('home-explain')?.addEventListener('click', (e) => {
            e.preventDefault();
            this.showState('guest', true);
        });

        document.getElementById('home-state-close')?.addEventListener('click', () => {
            this.hideState();
        });

        // Im ausgeblendeten Tab wird nicht abgefragt, beim Zurueckkehren
        // dafuer sofort - sonst stuende dort ein Stand von vor einer Stunde.
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) return;
            this.load(false);
        });
    },

    /**
     * Startet den Anruf zu einem Guide.
     *
     * Derselbe Weg wie in der Tabellenansicht: Die Karte entscheidet nichts
     * ueber den Ablauf des Calls, sie loest ihn nur aus.
     *
     * @param {string|number} userId
     */
    startCall(userId) {
        if (!userId) return;
        if (typeof window.webrtcApp?.rtc?.startCall !== 'function') {
            alert('Die Anruffunktion steht auf dieser Seite nicht zur Verfügung.');
            return;
        }
        this.map?.closePopup();
        window.webrtcApp.rtc.startCall(userId);
        // Die Symbole fuer Kamera und Mikrofon haengen an der Verbindung, die
        // erst aufgebaut wird. Kurz warten, dann nachziehen.
        setTimeout(() => {
            if (typeof window.updateCallIcons === 'function') window.updateCallIcons();
        }, 1000);
    },

    /**
     * Holt die Standorte und zeichnet die Karte neu.
     *
     * @param {boolean} showLoading true beim ersten Laden und nach einem
     *        Fehler - beim stillen Nachziehen im Takt bleibt der Hinweis weg.
     */
    load(showLoading) {
        if (showLoading) this.setLoading(true);

        const fertig = (eintraege) => {
            this.setLoading(false);
            this.render(eintraege);
        };
        const gescheitert = () => {
            this.setLoading(false);
            // Steht schon eine Karte, bleibt der zuletzt bekannte Stand
            // stehen: ein einzelner fehlgeschlagener Zwischenabruf soll die
            // Nadeln nicht loeschen. Nur wenn noch nie etwas ankam, tritt die
            // Fehlerflaeche an ihre Stelle.
            if (this.lastCount === null) this.showState('error');
        };

        if (!window.isLoggedIn) {
            $.ajax({ url: 'index.php?act=get_map_locations', method: 'GET', dataType: 'json' })
                .done((data) => {
                    if (!Array.isArray(data)) { gescheitert(); return; }
                    fertig(data.map(item => this.fromPublic(item)));
                })
                .fail(gescheitert);
            return;
        }

        // Angemeldet: fremde und eigene Standorte. $.when wartet auf beide.
        // Scheitert nur der eigene Abruf, waere die Karte ohne den eigenen
        // Standort immer noch brauchbar - deshalb wird er einzeln aufgefangen
        // und im Fehlerfall als leere Liste behandelt.
        //
        // Der Ersatzwert wird mit DREI Argumenten aufgeloest, genau wie
        // $.ajax es tut (Daten, Status, jqXHR). $.when reicht die Argumente
        // eines Aufrufs als Array weiter; kaeme hier nur eines an, saehe die
        // Antwort anders aus als im Erfolgsfall und der Zugriff unten waere
        // je nach Ausgang ein anderer.
        const fremde = $.ajax({ url: 'index.php?act=get_locations', method: 'GET', dataType: 'json' });
        const eigene = $.ajax({ url: 'index.php?act=get_my_locations', method: 'GET', dataType: 'json' })
            .then(null, () => $.Deferred().resolve([], 'error', null).promise());

        $.when(fremde, eigene)
            .done((a, b) => {
                const fremdeDaten = Array.isArray(a[0]) ? a[0] : null;
                if (fremdeDaten === null) { gescheitert(); return; }
                const eigeneDaten = Array.isArray(b[0]) ? b[0] : [];
                fertig(
                    fremdeDaten.map(item => this.fromList(item, false))
                        .concat(eigeneDaten.map(item => this.fromList(item, true)))
                );
            })
            .fail(gescheitert);
    },

    /**
     * Bringt einen Datensatz der oeffentlichen Karte in die interne Form.
     *
     * Die Verfuegbarkeit hat der Server schon uebersetzt; hier wird sie nur
     * gegen die drei bekannten Werte geprueft, damit ein unerwarteter Wert
     * nicht als "verfuegbar" durchrutscht.
     *
     * @param {Object} item
     * @returns {Object}
     */
    fromPublic(item) {
        const bekannt = (item.availability === 'live' || item.availability === 'busy');
        return {
            id         : String(item.id),
            lat        : parseFloat(item.latitude),
            lon        : parseFloat(item.longitude),
            city       : item.city_name || '',
            country    : item.country_name || '',
            description: item.description || '',
            status     : bekannt ? item.availability : 'idle',
            mine       : false,
            userId     : null,          // gibt es in der oeffentlichen Antwort nicht
            blocked    : false,
            blockedReason: ''
        };
    },

    /**
     * Bringt einen Datensatz aus get_locations bzw. get_my_locations in die
     * interne Form.
     *
     * @param {Object} item
     * @param {boolean} mine Stammt der Standort vom angemeldeten Benutzer?
     * @returns {Object}
     */
    fromList(item, mine) {
        const gesperrt = (item.blocked == 1);
        let status;
        if (gesperrt)                            status = 'idle';
        else if (item.user_status === 'online')  status = 'live';
        else if (item.user_status === 'in_call') status = 'busy';
        else                                     status = 'idle';

        return {
            id         : String(item.id),
            lat        : parseFloat(item.latitude),
            lon        : parseFloat(item.longitude),
            city       : item.city_name || '',
            country    : item.country_name || '',
            description: item.description || '',
            status     : status,
            mine       : !!mine,
            userId     : item.user_id ?? null,
            blocked    : gesperrt,
            blockedReason: item.blocked_reason || ''
        };
    },

    /**
     * Setzt den Nadelbestand der Karte.
     *
     * Bestehende Nadeln werden geaendert statt neu gebaut. Sonst waere jede
     * Aktualisierung im 15-Sekunden-Takt ein sichtbares Flackern, und ein
     * gerade geoeffnetes Kartenfenster wuerde zuklappen.
     *
     * @param {Array} eintraege Datensaetze in der internen Form
     */
    render(eintraege) {
        // Standorte ohne brauchbare Koordinaten koennen nicht auf die Karte.
        // Sie sind in der Listenansicht weiterhin zu sehen.
        const items = eintraege.filter(item => this.hasCoords(item));

        this.lastCount = items.length;
        this.hasOwn = items.some(item => item.mine);

        if (items.length === 0) {
            // Kein "0 Guides verfuegbar" ueber einer Flaeche, die genau das
            // schon in ganzen Saetzen erklaert.
            this.clearMarkers();
            document.getElementById('home-counts')?.setAttribute('hidden', '');
            this.showState(window.isLoggedIn ? 'empty' : 'guest');
            return;
        }

        // Steht die Erklaerflaeche noch, weil beim letzten Mal nichts da war,
        // verschwindet sie jetzt. Hat der Gast sie selbst geoeffnet, bleibt
        // sie stehen - er liest gerade.
        if (!this.stateDismissible) this.hideState();

        const gesehen = {};
        items.forEach(item => {
            gesehen[item.id] = true;
            const vorhanden = this.markers[item.id];
            if (vorhanden) {
                this.updateMarker(vorhanden, item);
            } else {
                this.markers[item.id] = this.createMarker(item);
            }
        });

        // Weggefallene Standorte entfernen.
        Object.keys(this.markers).forEach(id => {
            if (gesehen[id]) return;
            this.layer.removeLayer(this.markers[id]);
            delete this.markers[id];
        });

        this.updateCounts(
            items.filter(item => item.status === 'live' && !item.mine).length,
            items.length
        );

        // Den Ausschnitt nur EINMAL setzen. Wer selbst gezoomt hat, soll nicht
        // alle 15 Sekunden zurueckgeworfen werden.
        if (!this.fitted) {
            this.fitted = true;
            // Leaflet merkt sich die Groesse des Kartenfeldes beim Anlegen und
            // rechnet den Ausschnitt daraus aus. Die Karte sitzt in einer
            // Flexspalte zwischen Kopf- und Fusszeile; aendert sich deren
            // Hoehe nach dem Anlegen noch - etwa weil eine Schrift nachlaedt -,
            // waere der gespeicherte Wert veraltet. Einmal nachmessen kostet
            // nichts und macht den Ausschnitt unabhaengig davon.
            this.map.invalidateSize(false);
            this.map.fitBounds(
                L.latLngBounds(items.map(item => [item.lat, item.lon])),
                { padding: [60, 60], maxZoom: 12 }
            );
        }

        this.updateLegend();
    },

    /**
     * Hat der Datensatz auswertbare Koordinaten?
     * @param {Object} item
     * @returns {boolean}
     */
    hasCoords(item) {
        return isFinite(item.lat) && isFinite(item.lon)
            && item.lat >= -90 && item.lat <= 90
            && item.lon >= -180 && item.lon <= 180;
    },

    /**
     * Die Nadelart eines Eintrags. Ein eigener Standort ist immer "mine" -
     * ob sein Eigentuemer gerade online ist, steht im Kartenfenster.
     *
     * @param {Object} item
     * @returns {string} 'mine', 'live', 'busy' oder 'idle'
     */
    pinKind(item) {
        return item.mine ? 'mine' : item.status;
    },

    /**
     * Legt eine Nadel an und haengt sie in die Karte.
     * @param {Object} item
     * @returns {Object} Leaflet-Marker
     */
    createMarker(item) {
        const art = this.pinKind(item);
        const marker = L.marker([item.lat, item.lon], {
            icon: this.icon(art),
            title: [item.city, item.country].filter(Boolean).join(', ') || 'Standort',
            riseOnHover: true,
            // Verfuegbare Guides liegen ueber den ruhenden, damit sie an
            // dichten Stellen nicht verdeckt werden.
            zIndexOffset: art === 'live' ? 1000 : (art === 'mine' ? 500 : 0)
        });
        marker.bindPopup(this.popupHtml(item), { className: 'home-popup-wrap', maxWidth: 280 });
        marker.__kind = art;
        marker.addTo(this.layer);
        return marker;
    },

    /**
     * Zieht eine bestehende Nadel auf den neuen Stand nach.
     * @param {Object} marker
     * @param {Object} item
     */
    updateMarker(marker, item) {
        const art = this.pinKind(item);
        if (marker.__kind !== art) {
            marker.__kind = art;
            marker.setIcon(this.icon(art));
            marker.setZIndexOffset(art === 'live' ? 1000 : (art === 'mine' ? 500 : 0));
        }
        marker.setPopupContent(this.popupHtml(item));
    },

    /**
     * Das Aussehen einer Nadel. Die Gestaltung steckt vollstaendig in
     * assets/css/home.css - hier steht nur, welche Klasse gilt.
     *
     * @param {string} art 'live', 'busy', 'idle' oder 'mine'
     * @returns {Object} L.divIcon
     */
    icon(art) {
        const puls = art === 'live' ? '<span class="home-pin__pulse"></span>' : '';

        // Eine Nadelform, keine Scheibe: Ein gruener Punkt auf einer Karte,
        // die selbst aus Gruen- und Grautoenen besteht, verschwindet darin.
        // Die Tropfenform mit weissem Rand, weissem Kern und Schlagschatten
        // ist auch dann erkennbar, wenn die Farbe darunter dieselbe ist -
        // sie hebt sich durch FORM und Kontrast ab, nicht nur durch Farbe.
        //
        // Die Spitze sitzt unten in der Mitte; iconAnchor zeigt genau dorthin,
        // damit die Nadel auf ihren Punkt zeigt und nicht daneben.
        const svg =
            '<svg class="home-pin__shape" viewBox="0 0 28 36" aria-hidden="true">'
          +   '<path class="home-pin__body" d="M14 1.6C7.7 1.6 2.6 6.7 2.6 13c0 8.3 11.4 21.4 11.4 21.4S25.4 21.3 25.4 13C25.4 6.7 20.3 1.6 14 1.6z"/>'
          +   '<circle class="home-pin__eye" cx="14" cy="13" r="4.4"/>'
          + '</svg>';

        return L.divIcon({
            className: 'home-pin-wrap',
            html: `<span class="home-pin home-pin--${art}">${puls}${svg}</span>`,
            iconSize: [32, 40],
            iconAnchor: [16, 40],
            popupAnchor: [0, -34]
        });
    },

    /**
     * Inhalt des Kartenfensters einer Nadel.
     *
     * Alles, was aus der Datenbank kommt, wird maskiert: Beschreibungen sind
     * Eingaben anderer Nutzer.
     *
     * @param {Object} item
     * @returns {string} HTML
     */
    popupHtml(item) {
        const ort  = this.esc(item.city || 'Standort');
        const land = this.esc(item.country);
        const desc = this.esc(item.description);

        return `
            <div class="home-popup">
                <p class="home-popup__place">${ort}</p>
                ${land ? `<p class="home-popup__country">${land}</p>` : ''}
                <div class="home-popup__meta">${this.popupTag(item)}</div>
                ${desc ? `<p class="home-popup__desc">${desc}</p>` : ''}
                ${this.popupAction(item)}
            </div>`;
    },

    /**
     * Die Zustandsmarke im Kartenfenster.
     * @param {Object} item
     * @returns {string} HTML
     */
    popupTag(item) {
        if (item.mine) {
            return '<span class="app-tag app-tag--accent">Ihr Standort</span>';
        }
        if (item.status === 'live') {
            return '<span class="app-tag app-tag--live"><span class="app-dot"></span>Jetzt verfügbar</span>';
        }
        if (item.status === 'busy') {
            return '<span class="app-tag app-tag--warn"><span class="app-dot"></span>Im Gespräch</span>';
        }
        return '<span class="app-tag">Kein Guide vor Ort</span>';
    },

    /**
     * Der untere Teil des Kartenfensters: Knopf oder Hinweis.
     *
     * Vier Faelle, und der wichtigste ist der Gast: Ihm fehlt die user_id
     * (die oeffentliche Karte gibt keine heraus), also kann er von hier aus
     * gar nicht anrufen. Statt eines Knopfes, der nichts tut, steht dort der
     * Weg zur Anmeldung.
     *
     * @param {Object} item
     * @returns {string} HTML
     */
    popupAction(item) {
        if (item.mine) {
            const gesperrt = item.blocked
                ? `<p class="home-popup__note home-popup__note--danger">Gesperrt${item.blockedReason ? ': ' + this.esc(item.blockedReason) : ''}. Der Standort ist für andere nicht sichtbar.</p>`
                : '';
            const sichtbar = item.status === 'live'
                ? 'Sie sind online – für andere ist dieser Standort gerade hervorgehoben.'
                : 'Solange Sie offline sind, wird dieser Standort gedämpft angezeigt.';
            return `
                ${gesperrt}
                <p class="home-popup__note">${sichtbar}</p>
                <div class="home-popup__action">
                    <a class="btn btn-secondary" href="index.php?act=settings">Eigene Standorte bearbeiten</a>
                </div>`;
        }

        if (item.status === 'live') {
            if (!window.isLoggedIn) {
                return `
                    <p class="home-popup__note">Für eine Führung brauchen Sie ein Konto – die Verbindung läuft direkt zwischen Ihnen und dem Guide.</p>
                    <div class="home-popup__action">
                        <a class="btn btn-primary" href="index.php?act=login_page">Anmelden und starten</a>
                        <a class="btn btn-secondary" href="index.php?act=signup_page">Konto anlegen</a>
                    </div>`;
            }
            return `
                <div class="home-popup__action">
                    <button type="button" class="btn btn-success home-call-btn" data-userid="${this.esc(item.userId)}">
                        Führung starten
                    </button>
                </div>`;
        }

        if (item.status === 'busy') {
            return '<p class="home-popup__note">Der Guide ist gerade in einer anderen Führung. Versuchen Sie es in ein paar Minuten noch einmal.</p>';
        }

        if (item.blocked) {
            return '<p class="home-popup__note home-popup__note--danger">Dieser Standort ist gesperrt und für andere Nutzer nicht sichtbar.</p>';
        }

        return '<p class="home-popup__note">Dieser Ort wird angeboten, aber gerade ist niemand da. Sobald der Guide online ist, erscheint er hier hervorgehoben.</p>';
    },

    /**
     * Schreibt die beiden Zaehler ueber der Karte.
     * @param {number} live Guides, die gerade erreichbar sind
     * @param {number} alle Standorte insgesamt
     */
    updateCounts(live, alle) {
        const box = document.getElementById('home-counts');
        if (!box) return;

        box.querySelector('#home-count-live [data-count-text]').textContent =
            live === 1 ? '1 Guide verfügbar' : `${live} Guides verfügbar`;
        box.querySelector('#home-count-idle [data-count-text]').textContent =
            alle === 1 ? '1 Standort' : `${alle} Standorte`;
        box.removeAttribute('hidden');
    },

    /**
     * Zeigt die Legende und darin die Zeile fuer den eigenen Standort nur
     * dann, wenn es einen gibt.
     */
    updateLegend() {
        const legende = document.getElementById('home-legend');
        if (!legende) return;
        const eigene = legende.querySelector('[data-legend="mine"]');
        if (eigene) eigene.hidden = !this.hasOwn;
        legende.removeAttribute('hidden');
    },

    /** Entfernt alle Nadeln. */
    clearMarkers() {
        Object.keys(this.markers).forEach(id => this.layer.removeLayer(this.markers[id]));
        this.markers = {};
        document.getElementById('home-legend')?.setAttribute('hidden', '');
    },

    /**
     * Zeigt einen der Zustaende der Erklaerflaeche.
     *
     * Die Texte stehen in assets/html/home.html; hier wird nur umgeschaltet.
     *
     * @param {string} name 'guest', 'empty' oder 'error'
     * @param {boolean} [dismissible] true, wenn der Nutzer sie selbst
     *        geoeffnet hat und wieder schliessen koennen muss
     */
    showState(name, dismissible) {
        const box = document.getElementById('home-state');
        if (!box) return;

        box.querySelectorAll('[data-state]').forEach(el => {
            el.hidden = (el.getAttribute('data-state') !== name);
        });

        // Beim Ladefehler haben die drei Schritte nichts zu suchen - dort
        // geht es nicht um das Produkt, sondern um einen Abruf.
        const schritte = document.getElementById('home-steps');
        if (schritte) schritte.hidden = (name === 'error');

        this.stateDismissible = !!dismissible;
        const schliessen = document.getElementById('home-state-close');
        if (schliessen) schliessen.hidden = !dismissible;

        box.removeAttribute('hidden');
    },

    /** Blendet die Erklaerflaeche aus. */
    hideState() {
        this.stateDismissible = false;
        document.getElementById('home-state')?.setAttribute('hidden', '');
    },

    /** Zeigt oder verbirgt den Ladehinweis. */
    setLoading(an) {
        const el = document.getElementById('home-loading');
        if (!el) return;
        if (an) el.removeAttribute('hidden');
        else el.setAttribute('hidden', '');
    },

    /** Startet den Aktualisierungstakt. */
    startAutoRefresh() {
        if (this.refreshTimer) clearInterval(this.refreshTimer);
        this.refreshTimer = setInterval(() => {
            if (document.hidden) return;
            this.load(false);
        }, this.REFRESH_MS);
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

$(document).ready(function () {
    window.webrtcApp.homeMap.init();
});
