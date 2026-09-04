/**
 * Die Startseite: Karte als Einstieg.
 *
 * WAS DIESES MODUL TUT
 * --------------------
 * Es holt die angebotenen Standorte und setzt sie als Nadeln auf eine
 * Leaflet-Karte. Vier Arten:
 *
 *   live  - ein Guide ist gerade erreichbar.
 *   busy  - ein Guide ist da, aber in einem anderen Gespraech.
 *   idle  - der Standort wird angeboten, gerade ist niemand vor Ort. Er
 *           bleibt sichtbar, sonst waere die Karte nachts leer und der
 *           Eindruck entstuende, es gebe das Angebot gar nicht.
 *   mine  - ein eigener Standort. Erkennbar als solcher und ohne Anrufknopf:
 *           sich selbst ruft niemand an.
 *
 * VON HIER AUS WIRD NICHT MEHR ANGERUFEN
 * --------------------------------------
 * Jede Nadel fuehrt auf die Seite ihres Standorts
 * (index.php?act=location&id=...), und erst dort beginnt die Fuehrung. Vorher
 * stand im Kartenfenster ein Knopf "Fuehrung starten": Ein Kunde schickte
 * damit einen Fremden los, nachdem er einen Ortsnamen und eine Zeile Freitext
 * gelesen hatte. Auf der Standortseite stehen Bilder, die ausfuehrliche
 * Beschreibung, Dauer und Sprachen - und dort haengt der Anrufknopf, samt
 * Standortkennung fuer die Rollenvergabe des Servers.
 *
 * Das gilt auch fuer den Gast: Sein Weg fuehrt jetzt auf die Standortseite,
 * die er ebenfalls sehen darf, und die Anmeldung wird erst dort verlangt -
 * dann weiss er wenigstens, wofuer.
 *
 * ZWEI QUELLEN, JE NACH ANMELDUNG
 * -------------------------------
 * Nicht angemeldet:  get_map_locations
 *     Die oeffentliche Karte. Sie enthaelt Ort, Beschreibung und einen von
 *     drei Verfuegbarkeitswerten - keinen Benutzernamen, keine user_id.
 *     Fuer den Verweis auf die Standortseite reicht das: Sie braucht nur die
 *     Standortkennung.
 *
 * Angemeldet:  get_locations + get_my_locations
 *     Die fremden Standorte und die eigenen. Die eigenen kommen aus einer
 *     zweiten Route, weil
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
     * Zoomstufe fuer einen EINZELNEN Standort.
     *
     * Ein Punkt hat keine Ausdehnung. fitBounds() kann daraus keinen
     * Ausschnitt errechnen und nimmt die erlaubte Hoechststufe - stand die
     * auf 12, sah man um eine Nadel in Rheda den halben Landkreis. 15 zeigt
     * das Viertel drumherum: Strassen mit Namen, ein paar Querstrassen,
     * erkennbar wo man landet.
     */
    SINGLE_ZOOM: 15,

    /**
     * Hoechste Stufe, auf die fitBounds bei mehreren Standorten gehen darf.
     *
     * Etwas kleiner als SINGLE_ZOOM: Liegen zwei Standorte dicht beieinander,
     * sollen beide zu sehen sein und nicht einer bildschirmfuellend.
     */
    FIT_MAX_ZOOM: 14,

    /**
     * Kleinste Stufe, auf die fitBounds herausgehen darf.
     *
     * Ohne Untergrenze zieht ein einzelner weit entfernter Standort - eine
     * Nadel in Marokko neben einer in Leipzig - die Karte auf die halbe
     * Weltkugel heraus, und alle uebrigen Nadeln liegen als Haufen
     * uebereinander. Lieber ein Ausschnitt, in dem etwas zu erkennen ist,
     * und der Rest ist durch Ziehen erreichbar.
     */
    FIT_MIN_ZOOM: 4,

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
        // Hier hing der Anrufknopf des Kartenfensters. Er ist entfallen: Der
        // Weg ins Gespraech fuehrt ueber die Standortseite, und dorthin
        // fuehrt ein gewoehnlicher Verweis - der braucht keinen Handler.
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
            title      : item.title || '',
            description: item.description || '',
            status     : bekannt ? item.availability : 'idle',
            mine       : false,
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

        // DIE VERFUEGBARKEIT KOMMT FERTIG VOM SERVER - hier steht keine
        // eigene Auswertung mehr. Vorher wurde an dieser Stelle 'online' zu
        // 'live' gemacht, und 'online' setzte der Heartbeat schon dann, wenn
        // irgendein Tab offen war. Damit stand ein Guide gruen auf der Karte,
        // der davon nichts wusste.
        //
        // Jetzt entscheidet App\Model\Location::AVAILABILITY_SQL, und zwar
        // aus zwei Angaben: erreichbarer Browser UND eingeschaltete
        // Bereitschaft. Dieselbe Auswertung liefert auch die oeffentliche
        // Karte - siehe fromPublic() darueber, das damit dieselbe Form hat.
        //
        // Ein gesperrter Standort bleibt grau, unabhaengig davon: Die Sperre
        // ist eine Massnahme der Moderation und schlaegt jede Bereitschaft.
        const gemeldet = item.availability;
        const bekannt  = (gemeldet === 'live' || gemeldet === 'busy');
        const status   = (gesperrt || !bekannt) ? 'idle' : gemeldet;

        return {
            id         : String(item.id),
            lat        : parseFloat(item.latitude),
            lon        : parseFloat(item.longitude),
            city       : item.city_name || '',
            country    : item.country_name || '',
            title      : item.title || '',
            description: item.description || '',
            // KEINE userId mehr. Sie stand hier, um von der Karte aus
            // anzurufen; das tut sie jetzt nicht mehr, und ein Feld, das
            // niemand liest, wird beim naechsten Umbau doch wieder benutzt.
            // Wer anrufen will, geht auf die Standortseite - die bekommt die
            // Kennung vom Server, und zwar nur, wenn sie ihr zusteht.
            status     : status,
            mine       : !!mine,
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

            if (items.length === 1) {
                // Ein Punkt hat keine Ausdehnung - hier gibt es nichts
                // einzupassen, sondern eine Umgebung zu waehlen.
                this.map.setView([items[0].lat, items[0].lon], this.SINGLE_ZOOM);
            } else {
                this.map.fitBounds(
                    L.latLngBounds(items.map(item => [item.lat, item.lon])),
                    { padding: [60, 60], maxZoom: this.FIT_MAX_ZOOM }
                );
                // fitBounds kennt keine Untergrenze. Liegen die Standorte sehr
                // weit auseinander, wird danach zurueckgeholt.
                if (this.map.getZoom() < this.FIT_MIN_ZOOM) {
                    this.map.setZoom(this.FIT_MIN_ZOOM);
                }
            }
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
        // Der Titel steht oben, der Ort darunter: Er sagt, worum es geht,
        // und der Ortsname allein tut das nicht. Standorte aus der Zeit vor
        // migrations/011 haben keinen - dann tritt der Ort an seine Stelle,
        // so wie es vorher war.
        const titel = this.esc(item.title || item.city || 'Standort');
        const ort   = this.esc([item.city, item.country].filter(Boolean).join(', '));
        const desc  = this.esc(item.description);

        return `
            <div class="home-popup">
                <p class="home-popup__place">${titel}</p>
                ${ort ? `<p class="home-popup__country">${ort}</p>` : ''}
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
     * Der untere Teil des Kartenfensters: der Weg zur Standortseite und der
     * Satz, der den Zustand erklaert.
     *
     * FRUEHER STAND HIER DER ANRUFKNOPF. Ein Kunde schickte damit einen
     * Fremden los, nachdem er einen Ortsnamen und eine Zeile Freitext gelesen
     * hatte. Ein Kartenfenster ist auch der falsche Ort dafuer: Es ist ein
     * paar Zentimeter gross und verschwindet beim naechsten Klick daneben.
     *
     * Der Verweis fuehrt IMMER auf die Standortseite - auch bei einem
     * Standort ohne Guide und auch fuer einen Gast. Was von dort aus geht,
     * entscheidet die Seite; sie kennt den Zustand genauer als ein
     * Kartenfenster, das alle fuenfzehn Sekunden neu gezeichnet wird.
     *
     * @param {Object} item
     * @returns {string} HTML
     */
    popupAction(item) {
        const ziel = 'index.php?act=location&id=' + encodeURIComponent(item.id);

        if (item.mine) {
            const gesperrt = item.blocked
                ? `<p class="home-popup__note home-popup__note--danger">Gesperrt${item.blockedReason ? ': ' + this.esc(item.blockedReason) : ''}. Der Standort ist für andere nicht sichtbar.</p>`
                : '';
            const sichtbar = item.status === 'live'
                ? 'Sie sind bereit – für andere ist dieser Standort gerade hervorgehoben.'
                : 'Solange Sie nicht bereit sind, wird dieser Standort gedämpft angezeigt.';
            return `
                ${gesperrt}
                <p class="home-popup__note">${sichtbar}</p>
                <div class="home-popup__action">
                    <a class="btn btn-secondary" href="${ziel}">Standort ansehen und bearbeiten</a>
                </div>`;
        }

        // Der Hinweis steht UNTER dem Verweis und ersetzt ihn nicht: Auch
        // einen Standort ohne Guide will man sich ansehen - vielleicht kommt
        // man morgen wieder.
        let hinweis = '';
        if (item.status === 'busy') {
            hinweis = 'Der Guide ist gerade in einer anderen Führung.';
        } else if (item.blocked) {
            hinweis = 'Dieser Standort ist gesperrt und für andere Nutzer nicht sichtbar.';
        } else if (item.status !== 'live') {
            hinweis = 'Gerade ist niemand vor Ort. Der Standort bleibt buchbar, sobald der Guide bereit ist.';
        }

        return `
            <div class="home-popup__action">
                <a class="btn ${item.status === 'live' ? 'btn-success' : 'btn-secondary'}" href="${ziel}">
                    ${item.status === 'live' ? 'Führung ansehen' : 'Standort ansehen'}
                </a>
            </div>
            ${hinweis ? `<p class="home-popup__note">${this.esc(hinweis)}</p>` : ''}`;
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
