/**
 * Die Startseite: Karte als Einstieg.
 *
 * WAS DIESES MODUL TUT
 * --------------------
 * Es holt die angebotenen Standorte (Route get_locations, dieselbe Quelle wie
 * die Tabellenansicht) und setzt sie als zwei Arten von Nadeln auf eine
 * Leaflet-Karte:
 *
 *   - hervorgehoben: der Guide ist gerade erreichbar. Ein Klick fuehrt zum
 *     Anruf, ueber denselben Weg wie der Call-Knopf in der Tabelle
 *     (window.webrtcApp.rtc.startCall).
 *   - gedaempft: der Standort wird angeboten, es ist aber gerade niemand da.
 *     Er bleibt sichtbar - sonst waere die Karte nachts leer und der Eindruck
 *     entstuende, es gebe das Angebot gar nicht.
 *
 * Gibt es keinen einzigen Standort, tritt die Erklaerflaeche aus
 * assets/html/home.html an die Stelle der Karte. "Keine Einträge gefunden"
 * ist keine Startseite.
 *
 * WAS ES NICHT TUT
 * ----------------
 * Es entscheidet nichts ueber Berechtigungen. Wer die Standortliste abrufen
 * darf, steht in config/routes.php und wird in index.php geprueft. Ein Gast
 * hat das Recht location.list nicht; deshalb wird fuer ihn gar nicht erst
 * abgefragt, sondern gleich die Erklaerflaeche gezeigt.
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
    lastCount: null,           // Anzahl beim letzten Abruf, fuer die Zaehler

    /**
     * Einstiegspunkt. Tut nichts, wenn die Seite gar keine Startseite ist.
     */
    init() {
        const shell = document.getElementById('home');
        if (!shell) return;

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

        // Ein Gast darf die Standortliste nicht abrufen (Recht location.list).
        // Fuer ihn gaebe der Abruf eine 401 zurueck - also gar nicht erst
        // fragen, sondern erklaeren, worum es hier geht.
        if (!window.isLoggedIn) {
            this.showState('guest');
            return;
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

        // Im ausgeblendeten Tab wird nicht abgefragt, beim Zurueckkehren
        // dafuer sofort - sonst stuende dort ein Stand von vor einer Stunde.
        document.addEventListener('visibilitychange', () => {
            if (document.hidden || !window.isLoggedIn) return;
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

        $.ajax({
            url: 'index.php?act=get_locations',
            method: 'GET',
            dataType: 'json'
        })
        .done((data) => {
            this.setLoading(false);
            if (!Array.isArray(data)) {
                this.showState('error');
                return;
            }
            this.render(data);
        })
        .fail(() => {
            this.setLoading(false);
            // Steht schon eine Karte, bleibt der zuletzt bekannte Stand
            // stehen: ein einzelner fehlgeschlagener Zwischenabruf soll die
            // Nadeln nicht loeschen. Nur wenn noch nie etwas ankam, tritt die
            // Fehlerflaeche an ihre Stelle.
            if (this.lastCount === null) this.showState('error');
        });
    },

    /**
     * Setzt den Zeilenbestand der Karte.
     *
     * Bestehende Nadeln werden geaendert statt neu gebaut. Sonst waere jede
     * Aktualisierung im 15-Sekunden-Takt ein sichtbares Flackern, und ein
     * gerade geoeffnetes Kartenfenster wuerde zuklappen.
     *
     * @param {Array} data Datensaetze aus get_locations
     */
    render(data) {
        // Standorte ohne brauchbare Koordinaten koennen nicht auf die Karte.
        // Sie sind in der Listenansicht weiterhin zu sehen.
        const items = data.filter(item => this.hasCoords(item));

        this.lastCount = items.length;

        if (items.length === 0) {
            // Kein "0 Guides verfuegbar" ueber einer Flaeche, die genau das
            // schon in ganzen Saetzen erklaert.
            this.clearMarkers();
            document.getElementById('home-counts')?.setAttribute('hidden', '');
            this.showState('empty');
            return;
        }

        this.hideState();

        const seen = {};
        items.forEach(item => {
            const id = String(item.id);
            seen[id] = true;
            const existing = this.markers[id];
            if (existing) {
                this.updateMarker(existing, item);
            } else {
                this.markers[id] = this.createMarker(item);
            }
        });

        // Weggefallene Standorte entfernen.
        Object.keys(this.markers).forEach(id => {
            if (seen[id]) return;
            this.layer.removeLayer(this.markers[id]);
            delete this.markers[id];
        });

        this.updateCounts(
            items.filter(item => this.statusOf(item) === 'live').length,
            items.length
        );

        // Den Ausschnitt nur EINMAL setzen. Wer selbst gezoomt hat, soll nicht
        // alle 15 Sekunden zurueckgeworfen werden.
        if (!this.fitted) {
            this.fitted = true;
            const bounds = L.latLngBounds(items.map(item => [
                parseFloat(item.latitude), parseFloat(item.longitude)
            ]));
            this.map.fitBounds(bounds, { padding: [60, 60], maxZoom: 12 });
        }

        document.getElementById('home-legend')?.removeAttribute('hidden');
    },

    /**
     * Hat der Datensatz auswertbare Koordinaten?
     * @param {Object} item
     * @returns {boolean}
     */
    hasCoords(item) {
        const lat = parseFloat(item.latitude);
        const lon = parseFloat(item.longitude);
        return isFinite(lat) && isFinite(lon)
            && lat >= -90 && lat <= 90 && lon >= -180 && lon <= 180;
    },

    /**
     * Uebersetzt den Nutzerstatus in einen der drei Kartenzustaende.
     *
     * 'live'  - erreichbar, anrufbar
     * 'busy'  - da, aber in einem anderen Gespraech
     * 'idle'  - angeboten, gerade niemand vor Ort
     *
     * Ein gesperrter Standort gilt nie als anrufbar. Sichtbar ist er ohnehin
     * nur fuer die Moderation (LocationController::getLocations).
     *
     * @param {Object} item
     * @returns {string}
     */
    statusOf(item) {
        if (item.blocked == 1) return 'idle';
        if (item.user_status === 'online') return 'live';
        if (item.user_status === 'in_call') return 'busy';
        return 'idle';
    },

    /**
     * Legt eine Nadel an und haengt sie in die Karte.
     * @param {Object} item
     * @returns {Object} Leaflet-Marker
     */
    createMarker(item) {
        const status = this.statusOf(item);
        const marker = L.marker(
            [parseFloat(item.latitude), parseFloat(item.longitude)],
            {
                icon: this.icon(status),
                title: this.plainTitle(item),
                riseOnHover: true,
                // Verfuegbare Guides liegen ueber den ruhenden, damit sie an
                // dichten Stellen nicht verdeckt werden.
                zIndexOffset: status === 'live' ? 1000 : 0
            }
        );
        marker.bindPopup(this.popupHtml(item), { className: 'home-popup-wrap', maxWidth: 280 });
        marker.__status = status;
        marker.addTo(this.layer);
        return marker;
    },

    /**
     * Zieht eine bestehende Nadel auf den neuen Stand nach.
     * @param {Object} marker
     * @param {Object} item
     */
    updateMarker(marker, item) {
        const status = this.statusOf(item);
        if (marker.__status !== status) {
            marker.__status = status;
            marker.setIcon(this.icon(status));
            marker.setZIndexOffset(status === 'live' ? 1000 : 0);
        }
        marker.setPopupContent(this.popupHtml(item));
    },

    /**
     * Das Aussehen einer Nadel. Die Gestaltung steckt vollstaendig in
     * assets/css/home.css - hier steht nur, welche Klasse gilt.
     *
     * @param {string} status 'live', 'busy' oder 'idle'
     * @returns {Object} L.divIcon
     */
    icon(status) {
        const pulse = status === 'live' ? '<span class="home-pin__pulse"></span>' : '';
        return L.divIcon({
            className: 'home-pin-wrap',
            html: `<span class="home-pin home-pin--${status}">${pulse}<span class="home-pin__dot"></span></span>`,
            iconSize: [34, 34],
            iconAnchor: [17, 17],
            popupAnchor: [0, -14]
        });
    },

    /**
     * Beschriftung fuer den Mauszeiger (title-Attribut, reiner Text).
     * @param {Object} item
     * @returns {string}
     */
    plainTitle(item) {
        const ort = [item.city_name, item.country_name].filter(Boolean).join(', ');
        return ort || 'Standort';
    },

    /**
     * Inhalt des Kartenfensters einer Nadel.
     *
     * Alles, was aus der Datenbank kommt, wird maskiert: Beschreibung und
     * Benutzername sind Eingaben anderer Nutzer.
     *
     * @param {Object} item
     * @returns {string} HTML
     */
    popupHtml(item) {
        const status = this.statusOf(item);
        const ort    = this.esc(item.city_name || 'Standort');
        const land   = this.esc(item.country_name || '');
        const desc   = this.esc(item.description || '');
        const name   = this.esc(item.username || '');

        let tag, aktion;
        if (status === 'live') {
            tag = '<span class="app-tag app-tag--live"><span class="app-dot"></span>Jetzt verfügbar</span>';
            aktion = `
                <div class="home-popup__action">
                    <button type="button" class="btn btn-success home-call-btn" data-userid="${this.esc(item.user_id)}">
                        Führung starten
                    </button>
                </div>`;
        } else if (status === 'busy') {
            tag = '<span class="app-tag app-tag--warn"><span class="app-dot"></span>Im Gespräch</span>';
            aktion = '<p class="home-popup__note">Der Guide ist gerade in einer anderen Führung. Versuchen Sie es in ein paar Minuten noch einmal.</p>';
        } else if (item.blocked == 1) {
            tag = '<span class="app-tag app-tag--danger">Gesperrt</span>';
            aktion = '<p class="home-popup__note">Dieser Standort ist gesperrt und für andere Nutzer nicht sichtbar.</p>';
        } else {
            tag = '<span class="app-tag">Kein Guide vor Ort</span>';
            aktion = '<p class="home-popup__note">Dieser Ort wird angeboten, aber gerade ist niemand da. Sobald der Guide online ist, erscheint er hier hervorgehoben.</p>';
        }

        return `
            <div class="home-popup">
                <p class="home-popup__place">${ort}</p>
                ${land ? `<p class="home-popup__country">${land}</p>` : ''}
                <div class="home-popup__meta">
                    ${tag}
                    ${name ? `<span class="home-popup__guide">Guide: ${name}</span>` : ''}
                </div>
                ${desc ? `<p class="home-popup__desc">${desc}</p>` : ''}
                ${aktion}
            </div>`;
    },

    /**
     * Schreibt die beiden Zaehler ueber der Karte.
     * @param {number} live Guides, die gerade erreichbar sind
     * @param {number} alle Standorte insgesamt
     */
    updateCounts(live, alle) {
        const box = document.getElementById('home-counts');
        if (!box) return;

        const liveText = live === 1 ? '1 Guide verfügbar' : `${live} Guides verfügbar`;
        const alleText = alle === 1 ? '1 Standort' : `${alle} Standorte`;

        box.querySelector('#home-count-live [data-count-text]').textContent = liveText;
        box.querySelector('#home-count-idle [data-count-text]').textContent = alleText;
        box.removeAttribute('hidden');
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
     */
    showState(name) {
        const box = document.getElementById('home-state');
        if (!box) return;

        box.querySelectorAll('[data-state]').forEach(el => {
            if (el.getAttribute('data-state') === name) {
                el.removeAttribute('hidden');
            } else {
                el.setAttribute('hidden', '');
            }
        });

        // Beim Ladefehler haben die drei Schritte nichts zu suchen - dort
        // geht es nicht um das Produkt, sondern um einen Abruf.
        const steps = document.getElementById('home-steps');
        if (steps) steps.hidden = (name === 'error');

        box.removeAttribute('hidden');
    },

    /** Blendet die Erklaerflaeche aus. */
    hideState() {
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
