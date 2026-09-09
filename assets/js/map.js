/**
 * Modul zur Verwaltung der Location-Auswahl, Map-Anzeige und Geocoding-Logik.
 * Bindet Select2 für Länder/Städte, Leaflet-Karte, setzt Marker und sorgt für konsistente Koordinaten/Adressen.
 */
window.webrtcApp = window.webrtcApp || {};
window.webrtcApp.locationMap = {
    allowedCountryCodes: [
    "AF","AX","AL","DZ","AS","AD","AO","AI","AQ","AG","AR","AM","AW","AU","AT",
    "AZ","BS","BH","BD","BB","BY","BE","BZ","BJ","BM","BT","BO","BQ","BA","BW",
    "BV","BR","IO","BN","BG","BF","BI","CV","KH","CM","CA","KY","CF","TD","CL",
    "CN","CX","CC","CO","KM","CG","CD","CK","CR","CI","HR","CU","CW","CY","CZ",
    "DK","DJ","DM","DO","EC","EG","SV","GQ","ER","EE","ET","FK","FO","FJ","FI",
    "FR","GF","PF","TF","GA","GM","GE","DE","GH","GI","GR","GL","GD","GP","GU",
    "GT","GG","GN","GW","GY","HT","HM","VA","HN","HK","HU","IS","IN","ID","IR",
    "IQ","IE","IM","IL","IT","JM","JP","JE","JO","KZ","KE","KI","KP","KR","KW",
    "KG","LA","LV","LB","LS","LR","LY","LI","LT","LU","MO","MK","MG","MW","MY",     // Unterstützte Country Codes von OpenStreetMap
    "MV","ML","MT","MH","MQ","MR","MU","YT","MX","FM","MD","MC","MN","ME","MS",
    "MA","MZ","MM","NA","NR","NP","NL","NC","NZ","NI","NE","NG","NU","NF","MP",
    "NO","OM","PK","PW","PS","PA","PG","PY","PE","PH","PN","PL","PT","PR","QA",
    "RE","RO","RU","RW","BL","SH","KN","LC","MF","PM","VC","WS","SM","ST","SA",
    "SN","RS","SC","SL","SG","SX","SK","SI","SB","SO","ZA","GS","SS","ES","LK",
    "SD","SR","SJ","SE","CH","SY","TW","TJ","TZ","TH","TL","TG","TK","TO","TT",
    "TN","TR","TM","TC","TV","UG","UA","AE","GB","US","UM","UY","UZ","VU","VE",
    "VN","VG","VI","WF","EH","YE","ZM","ZW"
    ],
    map: null,                   // Leaflet-Map-Objekt
    marker: null,                // Aktueller Marker auf der Map
    selectedCountryCode: null,   // Aktuell gewähltes Land (ID)
    countryJustSetByLocation: false, // Flag: wurde Land durch Geolocation gesetzt?

    // -----------------------------------------------------------------
    // Nominatim (Geocoding)
    // -----------------------------------------------------------------

    /** Der Dienst, der aus Koordinaten Ortsnamen macht und umgekehrt. */
    NOMINATIM: 'https://nominatim.openstreetmap.org/',

    /**
     * Die Sprache, in der Nominatim antworten soll.
     *
     * WARUM DAS HIER STEHEN MUSS
     * --------------------------
     * Ohne diese Angabe richtet sich Nominatim nach dem Accept-Language-
     * Header des BROWSERS. Das heisst: Wer den Standort mit einem deutschen
     * Browser anlegt, speichert "Lissabon"; wer denselben Punkt mit einem
     * englischen Browser anlegt, speichert "Lisbon". Beides landet in
     * city.city_name und location.osm_place - die Stadtnamen in der
     * Datenbank haengen also davon ab, wer sie eingetragen hat.
     *
     * Eine feste Sprache macht daraus einen bestimmbaren Wert. Englisch und
     * nicht Deutsch, weil die Anwendung weltweit laufen soll und der
     * gespeicherte Name der ist, den ein Kunde aus einem anderen Land
     * wiedererkennen muss.
     *
     * ACHTUNG: Das gilt ab jetzt fuer NEUE Eintraege. Der Bestand ist
     * gemischt und muss getrennt vereinheitlicht werden.
     */
    NOMINATIM_SPRACHE: 'en',

    /**
     * Der Abstand zwischen zwei Anfragen an Nominatim, in Millisekunden.
     *
     * Die Nutzungsregeln nennen ein absolutes Maximum von EINER Anfrage je
     * Sekunde, und zwar fuer alle Nutzer dieser Anwendung zusammen. Diese
     * Zahl ist also keine, an der man dreht, um die Suche flotter wirken zu
     * lassen: Darunter wird die Anwendung abgewiesen, und der Nutzer sieht
     * eine Suche, die manchmal nichts findet.
     */
    NOMINATIM_TAKT: 1000,

    /**
     * Wie viele Suchergebnisse gemerkt werden.
     *
     * Genug fuer das Tippen an einem Formular - wer eine Stadt sucht,
     * probiert eine Handvoll Schreibweisen. Es ist keine Ablage auf Dauer:
     * Mit dem Neuladen der Seite ist der Inhalt weg, und das ist richtig so.
     */
    STAEDTE_SPEICHER_MAX: 50,

    /** Suchbegriff => Ergebnisliste. Map, weil sie die Reihenfolge kennt. */
    staedteSpeicher: new Map(),

    /**
     * Der Schluessel, unter dem eine Suche gemerkt wird.
     *
     * Das Land gehoert hinein: "Valencia" in Spanien und "Valencia" in
     * Venezuela sind zwei Antworten, und ohne das Land bekaeme die zweite
     * Suche die erste zurueck.
     *
     * @param {string} in_iso2
     * @param {string} in_query
     * @returns {string}
     */
    staedteSchluessel(in_iso2, in_query) {
        return String(in_iso2).toUpperCase() + '\n' + String(in_query).trim().toLowerCase();
    },

    /**
     * Was zu dieser Suche schon bekannt ist.
     *
     * @param {string} in_iso2
     * @param {string} in_query
     * @returns {Array|null} null heisst "noch nie gefragt" - eine LEERE
     *                       Liste heisst "gefragt, nichts gefunden" und ist
     *                       eine gueltige Antwort
     */
    staedteAusSpeicher(in_iso2, in_query) {
        const schluessel = this.staedteSchluessel(in_iso2, in_query);
        return this.staedteSpeicher.has(schluessel)
            ? this.staedteSpeicher.get(schluessel)
            : null;
    },

    /**
     * Merkt sich das Ergebnis einer Suche.
     *
     * Laeuft der Speicher voll, faellt der aelteste Eintrag heraus - eine
     * Map gibt ihre Schluessel in der Reihenfolge des Einfuegens zurueck,
     * der erste ist also der aelteste.
     *
     * @param {string} in_iso2
     * @param {string} in_query
     * @param {Array}  in_treffer
     * @returns {void}
     */
    staedteMerken(in_iso2, in_query, in_treffer) {
        this.staedteSpeicher.set(this.staedteSchluessel(in_iso2, in_query), in_treffer);

        while (this.staedteSpeicher.size > this.STAEDTE_SPEICHER_MAX) {
            this.staedteSpeicher.delete(this.staedteSpeicher.keys().next().value);
        }
    },

    /**
     * Baut eine Nominatim-Adresse.
     *
     * DIE EINE STELLE, an der accept-language gesetzt wird. Sechs Aufrufe
     * verteilt ueber dieses Modul haetten sonst sechs Gelegenheiten, sie zu
     * vergessen - und ein vergessener Aufruf faellt nicht auf, er liefert
     * nur still einen Namen in einer anderen Sprache.
     *
     * @param {string} in_pfad     'search' oder 'reverse'
     * @param {Object} in_parameter Abfrageparameter; Werte werden kodiert
     * @returns {string} Vollstaendige Adresse
     */
    nominatimUrl(in_pfad, in_parameter) {
        const p = new URLSearchParams(in_parameter || {});
        p.set('format', 'json');
        p.set('accept-language', this.NOMINATIM_SPRACHE);
        return this.NOMINATIM + in_pfad + '?' + p.toString();
    },

    /**
     * Liest das aktuelle Land aus dem Country-Select.
     * @returns {string} Ländercode (ISO2), Großbuchstaben
     */
    getCurrentCountryIso2() {
        return $('#countrySelect option:selected').data('iso2') ? $('#countrySelect option:selected').data('iso2').toUpperCase() : '';
    },

    /**
     * Initialisiert Map, Länder-/Städteauswahl und bindet Events.
     */
    init() {
        if (!$('#map').length || !$('#countrySelect').length) return;

        // select2 kommt per CDN (assets/html/index.html). Ist es nicht
        // geladen - CDN blockiert, offline, Tippfehler in der URL -, wuerde
        // der erste .select2()-Aufruf einen TypeError werfen. Land- und
        // Stadtfeld waeren dann funktionslos, ohne dass der Nutzer erfaehrt
        // warum. Deshalb vorher pruefen und sauber abbrechen.
        if (!$.fn || typeof $.fn.select2 !== 'function') {
            this.zeigeHinweis(
                'Die Auswahlfelder konnten nicht geladen werden, weil eine ' +
                'benötigte Bibliothek (select2) fehlt. Bitte die Seite neu ' +
                'laden. Besteht das Problem weiter, ist vermutlich die ' +
                'Internetverbindung oder ein Werbeblocker die Ursache.'
            );
            return;
        }

        this.initMap();
        this.loadCountries();
        this.initCitySelect2();
        this.bindEvents();

        // Koordinaten und Marker haengen an nichts, was noch geladen wird -
        // die Werte stehen schon in den versteckten Feldern. Land und Stadt
        // kommen spaeter, wenn die Laenderliste da ist (loadCountries).
        this.stelleKoordinatenWiederHer();

        // Erfolgsmeldung nach Save
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('success') === '1'){
            window.webrtcApp.notify.success('Standort gespeichert.');
        }
    },

    /**
     * Zeigt dem Nutzer eine Fehlermeldung im Formular an.
     * Der Bereich #location-hinweis steckt in assets/html/set_location.html
     * und ist normalerweise ausgeblendet.
     *
     * @param {string} text Die anzuzeigende Meldung (reiner Text)
     */
    zeigeHinweis(text) {
        const $box = $('#location-hinweis');
        if ($box.length) {
            // .text() statt .html(), damit nichts als Markup interpretiert wird
            $box.text(text).show();
        } else {
            // Notnagel, falls der Meldungsbereich fehlt - besser als schweigen
            window.webrtcApp.notify.error(text);
        }
    },

    /**
     * Blendet eine zuvor gezeigte Meldung wieder aus.
     *
     * Ohne das bliebe der Hinweis "es fehlt der Punkt auf der Karte" stehen,
     * nachdem der Nutzer ihn befolgt hat - eine Meldung, die nicht mehr
     * zutrifft, ist schlimmer als keine.
     *
     * @returns {void}
     */
    versteckeHinweis() {
        const $box = $('#location-hinweis');
        if ($box.length) $box.text('').hide();
    },

    /**
     * Erzeugt die Leaflet-Map mit Standard-View.
     */
    initMap() {
        this.map = L.map('map').setView([51, 10], 5);
        window.webrtcApp.mapTiles.add(this.map);

        this.map.on('click', (e) => this.onMapClick(e));
    },

    /**
     * Lädt Länder vom Backend und initialisiert das Country-Select2.
     */
    loadCountries() {
        fetch('index.php?act=get_country')
            .then(response => {
                // Bei HTTP 500 liefert der Server die Fehlerseite als HTML.
                // response.json() wuerde daran scheitern und die Kette
                // wortlos abbrechen - deshalb den Status vorher pruefen.
                if (!response.ok) {
                    throw new Error('Server antwortete mit Status ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (!Array.isArray(data)) {
                    throw new Error('Unerwartetes Antwortformat');
                }

                $('#countrySelect').empty().append('<option value="">Land wählen...</option>');
                const filteredCountries = data
                    .filter(country => country.iso2 && this.allowedCountryCodes.includes(country.iso2.toUpperCase()))
                    .sort((a, b) => a.country_name.localeCompare(b.country_name));

                // Der Server hat geantwortet, aber es blieb kein Land uebrig.
                // Haeufigste Ursache: die Tabelle country ist leer oder die
                // Spalte iso2 fehlt (siehe migrations/003 und 004). Ohne Land
                // laesst sich auch keine Stadt suchen, deshalb hier melden.
                if (filteredCountries.length === 0) {
                    this.zeigeHinweis(
                        'Es konnten keine Länder geladen werden. Ohne Land ist ' +
                        'keine Städtesuche möglich. Bitte an den Administrator ' +
                        'wenden - die Länderdaten fehlen in der Datenbank.'
                    );
                }

                filteredCountries.forEach(country => {
                    $('#countrySelect').append(
                        $('<option>', {
                            value: country.id,
                            text: country.country_name,
                            // data-country-name stand hier und trug den
                            // deutschen Namen ein zweites Mal - allein, um
                            // ihn als Suchbegriff an Nominatim zu geben.
                            // Diesen Weg gibt es nicht mehr (siehe
                            // onCountryChange), also faellt das Attribut weg.
                            // Der ANGEZEIGTE Name bleibt: er steht in text.
                            'data-iso2': country.iso2
                        })
                    );
                });
                $('#countrySelect').on('select2:open', function () {
                    setTimeout(() => {
                        document.querySelector('.select2-search__field').focus();
                    }, 100);
                });

                $('#countrySelect').select2({
                    placeholder: "Land wählen...",
                    allowClear: true,
                    templateResult: this.formatCountryOption,
                    templateSelection: this.formatCountryOption
                });

                // Erst jetzt gibt es Optionen, in denen eine Auswahl stehen
                // kann - vorher waere .val() ins Leere gelaufen.
                this.stelleLandUndStadtWiederHer();
            })
            .catch(fehler => {
                // Ohne diesen Zweig blieb das Dropdown bei jedem Fehler
                // wortlos leer: die Promise-Kette brach ab, select2 wurde nie
                // initialisiert, und der Nutzer sah ein totes Feld.
                console.error('Länder konnten nicht geladen werden:', fehler);
                this.zeigeHinweis(
                    'Die Länderliste konnte nicht geladen werden. Bitte die ' +
                    'Seite neu laden. Besteht das Problem weiter, ist der ' +
                    'Server nicht erreichbar oder die Datenbank nicht verfügbar.'
                );
            });
    },

    /**
     * Stellt eine Laenderoption dar - mit dem Laenderkuerzel davor.
     *
     * DREI ANLAEUFE, UND WARUM ES JETZT TEXT IST
     * ------------------------------------------
     * 1. Ein <img> von flagcdn.com. Laedt das Bild nicht - Dienst nicht
     *    erreichbar, Inhaltsblocker, kein Netz -, zeichnet der Browser bei
     *    den festen 24x18 Pixeln das Ersatzbild: einen schmalen Strich vor
     *    dem Namen, "|Ägypten". Ausserdem ging bei jedem Aufklappen der
     *    Liste die IP jedes Nutzers an einen fremden Dienst.
     *
     * 2. Die Flagge als Zeichen, aus dem Kuerzel gebildet (zwei
     *    Regional-Indikatoren, AT -> Oesterreich). Das kann nicht
     *    fehlschlagen, weil nichts geladen wird - dachten wir. Es kann sehr
     *    wohl fehlschlagen, nur eben an der Schrift:
     *
     *      * WINDOWS HAT KEINE FLAGGEN. Segoe UI Emoji, die Emoji-Schrift
     *        des Systems, enthaelt die Regional-Indikatoren als EINZELNE
     *        Buchstabenzeichen, aber keine Verbindung der beiden zu einer
     *        Flagge. Microsoft liefert Laenderflaggen bewusst nicht mit.
     *        In Chrome und Edge steht dort deshalb "AT" statt der Flagge.
     *      * FIREFOX BRINGT EINE EIGENE MIT (Twemoji Mozilla) und zeigt die
     *        Flaggen deshalb meistens - aber eben nur meistens: Welche
     *        Schrift ein Zeichen bekommt, entscheidet die Rueckfallkette,
     *        und die waehlt auf Windows mal die eigene, mal die des
     *        Systems. Genau das war hier zu sehen: Afghanistan, Albanien
     *        und Algerien mit Flagge, Aethiopien und Oesterreich mit einem
     *        Strich - auf derselben Seite, in derselben Liste.
     *
     *    Aus dem Code ist das nicht ableitbar; die Zeichen sind fuer alle
     *    Laender gleich gebildet. Es ist eine Frage der Schriftauswahl im
     *    Browser, und darauf hat diese Anwendung keinen Zugriff.
     *
     * 3. DAS KUERZEL ALS TEXT. Zwei Buchstaben aus der normalen Schrift.
     *    Sie brauchen keine Emoji-Schrift, keine Rueckfallkette und keine
     *    Verbindung; sie sehen auf jedem System gleich aus. Das ist der
     *    einzige Unterschied zu Anlauf 2 - und es ist der Unterschied
     *    zwischen "sieht meistens gut aus" und "stimmt immer".
     *
     *    Es geht dabei nichts verloren, was die Auswahl braucht: Der
     *    Ländername steht daneben, das Kuerzel ordnet ihn nur ein. Wer die
     *    Flagge wiederhaben will, braucht mitgelieferte Grafiken - 248
     *    Dateien im Projekt, keine fremde Adresse. Das ist eine eigene
     *    Entscheidung und keine Zeile in dieser Funktion.
     *
     * @param {Object} country Eintrag von select2
     * @returns {string|jQuery}
     */
    formatCountryOption(country) {
        if (!country.id) return country.text;

        const iso2 = $(country.element).data('iso2');
        const kuerzel = window.webrtcApp.locationMap.kuerzelAusIso2(iso2);
        if (!kuerzel) return country.text;

        // Das Kuerzel steht in einem eigenen Element mit fester Breite, damit
        // die Laendernamen untereinander auf einer Linie beginnen.
        // aria-hidden, weil es dieselbe Auskunft ist wie der Name daneben -
        // vorgelesen waere es eine Wiederholung.
        return $('<span>')
            .append($('<span>', { 'class': 'land-kuerzel', 'aria-hidden': 'true', text: kuerzel }))
            .append(document.createTextNode(country.text));
    },

    /**
     * Prueft ein Laenderkuerzel und gibt es in Grossbuchstaben zurueck.
     *
     * Die Pruefung ist der Sinn der Funktion: Was aus der Datenbank kommt,
     * steht ungeprueft im data-Attribut, und was dort steht, landete frueher
     * unbesehen in einer Zeichenberechnung. Zwei Buchstaben A-Z oder nichts.
     *
     * @param {string} iso2 Zweibuchstabiges Kuerzel, z. B. 'at'
     * @returns {string} 'AT' - oder ein Leerstring bei ungueltiger Eingabe
     */
    kuerzelAusIso2(iso2) {
        if (typeof iso2 !== 'string') return '';
        const code = iso2.trim().toUpperCase();
        return /^[A-Z]{2}$/.test(code) ? code : '';
    },

    /**
     * Bindet Events an das Land-Select und den Standort-Button.
     */
    bindEvents() {
        // Land-Auswahl
        $('#countrySelect').on('change', () => this.onCountryChange());
        // Stadt-Auswahl siehe initCitySelect2()

        // Button für aktuellen Standort
        $('#current-location').on('click', () => this.onCurrentLocation());

        // Absenden erst, wenn ein Punkt gesetzt ist
        $('#location-form').on('submit', (e) => this.pruefeVorDemAbschicken(e));
    },

    /**
     * Haelt das Formular an, solange kein gueltiger Punkt gesetzt ist.
     *
     * WARUM HIER UND NICHT PER required
     * ---------------------------------
     * An #latitude und #longitude stand ein required. Es hatte keine
     * Wirkung: Ein <input type="hidden"> ist von der Pruefung des Browsers
     * ausgenommen. Das Formular ging also ohne Koordinaten raus, der Server
     * wies es ab, und der Nutzer landete eine Seite weiter bei einer Meldung,
     * die nicht mehr neben dem Feld stand, an dem es lag.
     *
     * Die Grenzen sind dieselben wie im LocationController. Verbindlich
     * bleibt die Pruefung dort: Diese hier kann jeder umgehen, der das
     * Formular ohne JavaScript abschickt.
     *
     * @param {Object} e Das submit-Ereignis
     * @returns {void}
     */
    pruefeVorDemAbschicken(e) {
        const lat = parseFloat($('#latitude').val());
        const lon = parseFloat($('#longitude').val());

        const gueltig = Number.isFinite(lat) && Number.isFinite(lon)
            && lat >= -90 && lat <= 90
            && lon >= -180 && lon <= 180;

        if (gueltig) {
            this.versteckeHinweis();
            return;
        }

        e.preventDefault();
        this.zeigeHinweis(
            'Es fehlt der Punkt auf der Karte. Bitte in die Karte klicken, ' +
            'eine Stadt wählen oder "Aktuellen Standort verwenden" - erst ' +
            'dann lässt sich der Standort speichern.'
        );

        // Der Hinweis steht oben im Formular, die Karte weiter unten. Ohne
        // das Scrollen sieht der Nutzer je nach Fenstergroesse weder das eine
        // noch das andere und haelt den Knopf fuer kaputt.
        const karte = document.getElementById('map');
        if (karte && karte.scrollIntoView) karte.scrollIntoView({ block: 'center' });
    },

    /**
     * Wird ausgeloest, wenn sich das Land geaendert hat - der EINZIGE Handler
     * dafuer.
     *
     * Er muss zwei Faelle auseinanderhalten, sonst loescht er Arbeit, die
     * gerade erst entstanden ist:
     *
     *   Der Nutzer waehlt ein Land. Dann ist alles bisher Eingetragene
     *   hinfaellig: Stadt, Koordinaten und Ortsname werden geleert und die
     *   Karte auf das Land zentriert.
     *
     *   Ein Klick auf die Karte (oder der Standortknopf) hat das Land aus den
     *   Koordinaten abgeleitet und hier eingetragen. Dann sind die
     *   Koordinaten das Ergebnis und duerfen nicht angeruehrt werden - sonst
     *   loescht die Seite genau den Punkt wieder, den der Nutzer eben gesetzt
     *   hat. Diesen Fall meldet landAusKoordinatenSetzen() ueber
     *   countryJustSetByLocation an.
     */
    onCountryChange() {
        const selectedOption = $('#countrySelect').find('option:selected');
        const iso2 = selectedOption.data('iso2');
        this.selectedCountryCode = selectedOption.val();

        // Das Stadtfeld haengt allein am Land und wird in BEIDEN Faellen
        // nachgezogen: die Staedtesuche ruft Nominatim mit countrycodes=<iso2>
        // auf, ohne Land gibt es keinen Code. Bliebe das Feld nach einem
        // Kartenklick gesperrt, schickte der Browser es beim Speichern gar
        // nicht erst mit - gesperrte Felder werden nicht abgeschickt - und der
        // Standort landete ohne Stadt in der Datenbank.
        this.setzeStadtfeldZustand(!!iso2);

        if (this.countryJustSetByLocation) {
            this.countryJustSetByLocation = false;
            return;
        }

        // Ab hier: der Nutzer hat selbst gewaehlt.
        $('#citySelect').val('').trigger('change');
        this.clearCoordsAndOsmPlace();

        if (!this.selectedCountryCode) return;

        // Map auf das gewählte Land zentrieren.
        //
        // UEBER DEN ISO-CODE UND NICHT UEBER DEN LAENDERNAMEN. Der Name kam
        // aus country.country_name und ist dort deutsch. Als Suchbegriff band
        // er die Kartenansicht an die Sprache des Datenbestandes: Sobald die
        // Ländernamen uebersetzt werden, faende Nominatim nichts mehr, und
        // der Nutzer bekaeme "keine Kartenansicht" fuer ein Land, das es sehr
        // wohl gibt. Der Code ist sprachunabhaengig.
        //
        // ZWEI PARAMETER, ZWEI AUFGABEN:
        //   country=      ist die Anfrage (strukturierte Suche). Ohne sie
        //                 haette Nominatim keinen Suchbegriff und lieferte
        //                 eine leere Liste - countrycodes allein ist nur ein
        //                 Filter und keine Frage.
        //   countrycodes= ist der Filter darauf. Er stellt sicher, dass ein
        //                 Treffer auch wirklich in diesem Land liegt.
        fetch(this.nominatimUrl('search', {
            country: iso2,
            countrycodes: iso2,
            limit: 1
        }))
            .then(resp => resp.json())
            .then(data => {
                if (data[0] && data[0].lat && data[0].lon) {
                    this.map.setView([data[0].lat, data[0].lon], 6);
                } else {
                    window.webrtcApp.notify.error('Für dieses Land steht keine Kartenansicht zur Verfügung.');
                    $('#countrySelect').val('').trigger('change');
                }
            });
    },

    /**
     * Traegt das Land ins Auswahlfeld ein, das zu einem Punkt auf der Karte
     * gehoert - ohne die Koordinaten dieses Punktes zu verwerfen.
     *
     * Der Aufruf ist absichtlich an einer Stelle gebuendelt: Kartenklick und
     * Standortknopf brauchen beide dasselbe, und beide muessen dabei die
     * Markierung countryJustSetByLocation setzen. Wer das an zwei Stellen
     * nachbaut, vergisst sie an einer.
     *
     * Passiert nichts, wenn kein Kuerzel kommt, das Land ohnehin schon
     * gewaehlt ist oder in der Liste gar nicht vorkommt (die Liste ist auf
     * die von OpenStreetMap unterstuetzten Laender begrenzt).
     *
     * @param {string} iso2 Zweibuchstabiges Laenderkuerzel aus dem Geocoding
     * @returns {void}
     */
    landAusKoordinatenSetzen(iso2) {
        if (typeof iso2 !== 'string' || !iso2) return;

        const cc = iso2.toUpperCase();
        if (this.getCurrentCountryIso2() === cc) return;

        const countryOption = $('#countrySelect option').filter(function () {
            return $(this).data('iso2') && $(this).data('iso2').toUpperCase() === cc;
        });
        if (!countryOption.length) return;

        this.landOhneZuruecksetzenSetzen(countryOption.val());
    },

    /**
     * Traegt ein Land ins Auswahlfeld ein, ohne die uebrigen Felder
     * zurueckzusetzen.
     *
     * Das ist die einzige Stelle, an der countryJustSetByLocation gesetzt
     * wird - jeder programmatische Landwechsel geht hier durch (Kartenklick,
     * Standortknopf, Wiederherstellen nach einem Ruecksprung). An zwei
     * Stellen nachgebaut wuerde die Markierung an einer davon fehlen, und
     * genau daran krankte der Kartenklick.
     *
     * @param {string} id Wert der Option, also country.id
     * @returns {void}
     */
    landOhneZuruecksetzenSetzen(id) {
        this.countryJustSetByLocation = true;
        $('#countrySelect').val(id).trigger('change');
        // Notnagel: .trigger() laeuft sofort, onCountryChange() nimmt die
        // Markierung also gleich wieder zurueck. Bliebe sie doch einmal
        // stehen - weil der Handler noch nicht gebunden war -, verschluckte
        // sie den naechsten echten Landwechsel des Nutzers.
        this.countryJustSetByLocation = false;
    },

    /**
     * Holt Land und Stadt zurueck, die der Server nach einer Ablehnung im
     * Formular hinterlegt hat (data-vorher-land, data-vorher-stadt aus
     * assets/html/set_location.html).
     *
     * Laeuft erst, wenn die Laenderliste steht - vorher gibt es keine Option,
     * die gewaehlt werden koennte.
     *
     * @returns {void}
     */
    stelleLandUndStadtWiederHer() {
        const $form = $('#location-form');
        if (!$form.length) return;

        // .attr() statt .data(): .data() macht aus einer Zahl im Attribut
        // eine Zahl, und die Optionswerte sind Zeichenketten.
        const land  = $form.attr('data-vorher-land');
        const stadt = $form.attr('data-vorher-stadt');

        // Ueber landOhneZuruecksetzenSetzen, nicht ueber .val().trigger():
        // Letzteres liefe als Landwechsel des Nutzers durch und loeschte die
        // Koordinaten, die gerade wiederhergestellt wurden.
        if (land) this.landOhneZuruecksetzenSetzen(land);

        // Die Stadt kommt sonst aus der Nominatim-Suche. Hier wird sie als
        // einzelne Option nachgereicht - dieselbe Vorgehensweise wie beim
        // Kartenklick.
        if (stadt) {
            const option = new Option(stadt, stadt, true, true);
            $('#citySelect').append(option).trigger('change');
        }
    },

    /**
     * Holt Marker und Koordinatenanzeige zurueck.
     *
     * Die Werte selbst hat der Server schon in die versteckten Felder
     * geschrieben - abgeschickt wuerde also auch ohne diese Methode das
     * Richtige. Was fehlt, ist das Sichtbare: ohne Marker und ohne Zahlen
     * unter der Karte saehe das Formular aus, als waere der Punkt weg, und
     * der Nutzer setzte ihn ein zweites Mal.
     *
     * @returns {void}
     */
    stelleKoordinatenWiederHer() {
        const lat = parseFloat($('#latitude').val());
        const lon = parseFloat($('#longitude').val());
        if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

        $('#lat').text(lat.toFixed(6));
        $('#lon').text(lon.toFixed(6));
        if (this.marker) this.map.removeLayer(this.marker);
        this.marker = L.marker([lat, lon]).addTo(this.map);
        this.map.setView([lat, lon], 12);

        // Den Ortsnamen traegt der Server nicht mit zurueck - er ist nur
        // Anzeige und stuende sonst als "-" neben einem gesetzten Marker.
        fetch(this.nominatimUrl('reverse', { lat: lat, lon: lon }))
            .then(resp => resp.json())
            .then(data => { $('#osm_place').text(data.display_name || ''); })
            .catch(() => { /* nur Anzeige - ein Fehlschlag darf nichts stoeren */ });
    },

    /**
     * Initialisiert das Select2 für Städteauswahl mit Nominatim-API.
     */
    initCitySelect2() {
        let self = this;
        $('#citySelect').select2({
            placeholder: "Stadt wählen...",
            allowClear: true,
            minimumInputLength: 3,
            ajax: {
                // EINE SEKUNDE, UND DAS IST KEINE BEQUEMLICHKEIT.
                // Die Nutzungsregeln von Nominatim nennen ein absolutes
                // Maximum von EINER Anfrage je Sekunde - und zwar fuer alle
                // Nutzer dieser Anwendung zusammen, nicht je Person. Hier
                // standen 300 ms: Wer "rhede" tippt, loeste damit nach "rhe",
                // "rhed" und "rhede" drei Anfragen in gut einer halben
                // Sekunde aus. Die mittlere lief in die Sperre - deshalb fand
                // "rhed" nichts, waehrend "rhe" und "rhede" richtig
                // antworteten.
                delay: self.NOMINATIM_TAKT,
                transport: (params, success, failure) => {
                    const countryIso2 = $('#countrySelect option:selected').data('iso2');
                    if (!countryIso2) return success({ results: [] });

                    const query = params.data.q;

                    // Der Zwischenspeicher. Die Nutzungsregeln verlangen ihn
                    // ausdruecklich ("results must be cached on your side"),
                    // und er ist auch fuer den Nutzer besser: Wer ein Zeichen
                    // loescht und wieder tippt, bekommt die Liste sofort und
                    // ohne neue Anfrage.
                    const gemerkt = self.staedteAusSpeicher(countryIso2, query);
                    if (gemerkt) return success({ results: gemerkt });

                    // namedetails=1 liefert die Namensvarianten des Ortes
                    // (name:de, name:en, ...). Sie sind der Grund, warum ein
                    // deutsch getippter Name eine englische Antwort noch
                    // findet - siehe passtZumSuchbegriff().
                    //
                    // limit=40 und nicht 15: Nominatim wendet die Grenze VOR
                    // dem Entfernen von Dubletten an und liefert danach
                    // weniger. Ein kurzes Bruchstueck trifft viele Strassen;
                    // bei 15 fiel die gesuchte Kleinstadt aus dem Fenster,
                    // noch bevor die Auswertung sie sehen konnte.
                    fetch(self.nominatimUrl('search', {
                        q: query,
                        countrycodes: countryIso2,
                        addressdetails: 1,
                        namedetails: 1,
                        limit: 40
                    }))
                        .then(r => {
                            // Ohne diese Pruefung wird eine Fehlerseite oder
                            // eine Absage wegen zu vieler Anfragen (429) in
                            // r.json() zu einem Syntaxfehler - und der landet
                            // im selben catch wie ein Netzausfall.
                            if (!r.ok) throw new Error('HTTP ' + r.status);
                            return r.json();
                        })
                        .then(data => {
                            const treffer = self.formatCityResults(data, query);
                            self.staedteMerken(countryIso2, query, treffer);
                            success({ results: treffer });
                        })
                        .catch(fehler => {
                            // HIER STAND NUR "failure". Damit zeigte select2
                            // seine englische Vorgabe, und eine gescheiterte
                            // Anfrage sah aus wie "keine Stadt gefunden" -
                            // zwei sehr verschiedene Auskuenfte in einem Satz.
                            console.error('Staedtesuche fehlgeschlagen:', fehler);
                            failure(fehler);
                        });
                },
                processResults: data => ({ results: data.results }),
            },
            templateResult: city => city.text,
            templateSelection: city => city.text,
            language: {
                inputTooShort: () => 'Bitte mindestens 3 Buchstaben eingeben.',
                // Ohne diese Zeile zeigt select2 sein englisches
                // "No results found" - irrefuehrend, weil die Suche ohne
                // ausgewaehltes Land gar nicht erst losgeschickt wird
                // (siehe transport weiter oben). Deshalb beide Faelle
                // unterscheiden.
                noResults: () => {
                    const iso2 = $('#countrySelect option:selected').data('iso2');
                    return iso2
                        ? 'Keine Stadt gefunden.'
                        : 'Bitte zuerst ein Land wählen.';
                },
                // Ohne diese Zeile stand hier select2s englisches "The
                // results could not be loaded." - und eine gescheiterte
                // Anfrage sah damit fast so aus wie "nichts gefunden". Das
                // sind zwei verschiedene Auskuenfte: Bei der einen gibt es
                // die Stadt nicht, bei der anderen weiss die Anwendung es
                // nicht. Der Hinweis auf das Warten steht dabei, weil die
                // haeufigste Ursache die Sperre wegen zu vieler Anfragen ist.
                errorLoading: () => 'Die Städtesuche ist gerade nicht erreichbar. '
                    + 'Bitte einen Moment warten und noch einmal tippen.'
            }
        });

        // Startzustand: ohne Land bleibt das Stadtfeld gesperrt
        this.setzeStadtfeldZustand(false);

        // Fokus im Suchfeld, wenn Select2 geöffnet
        $('#citySelect').on('select2:open', function () {
            setTimeout(() => {
                document.querySelector('.select2-search__field').focus();
            }, 100);
        });

        // HIER STAND EIN ZWEITER change-HANDLER AUF #countrySelect.
        //
        // Er leerte bei jedem Landwechsel #latitude, #longitude, #lat, #lon
        // und #osm_place - ohne jede Abfrage. Das war der Grund, warum sich
        // der Punkt nicht per Mausklick auf die Karte setzen liess:
        // onMapClick() fuellt die Koordinatenfelder, holt danach den Ortsnamen
        // bei Nominatim und setzt aus der Antwort das erkannte Land. Dieses
        // Setzen loeste 'change' aus - und der Handler hier loeschte die
        // gerade gesetzten Koordinaten wieder. Zurueck blieb ein Formular, das
        // vollstaendig aussah (Marker, Land, Stadt), aber ohne Koordinaten
        // abgeschickt wurde; der Server wies es mit success=2 ab.
        //
        // onCountryChange() macht dieselbe Arbeit und kennt den Unterschied
        // zwischen einem Landwechsel des Nutzers und einem, der aus einem
        // Kartenklick folgt. Beides an einer Stelle, damit es nicht wieder
        // auseinanderlaeuft.

        // Stadt gewählt → Felder & Marker setzen
        $('#citySelect').on('select2:select', (e) => {
            const data = e.params.data;
            $('#latitude').val(data.lat);
            $('#longitude').val(data.lon);
            $('#lat').text(parseFloat(data.lat).toFixed(6));
            $('#lon').text(parseFloat(data.lon).toFixed(6));
            // Marker auf Map setzen
            if (self.marker) self.map.removeLayer(self.marker);
            self.marker = L.marker([data.lat, data.lon]).addTo(self.map);
            self.map.setView([data.lat, data.lon], 12);
            // OSM Place Name holen
            fetch(self.nominatimUrl('reverse', { lat: data.lat, lon: data.lon }))
                .then(resp => resp.json())
                .then(r => {
                    $('#osm_place').text(r.display_name || '');
                });
        });

        // Clear-Event → alles zurücksetzen
        $('#citySelect').on('select2:clear', () => {
            $('#latitude, #longitude, #lat, #lon, #osm_place').val('').text('');
            if (self.marker) {
                self.map.removeLayer(self.marker);
                self.marker = null;
            }
        });
    },

    /**
     * Sperrt oder entsperrt das Stadtfeld und passt den Platzhalter an.
     *
     * Hintergrund: die Staedtesuche ruft Nominatim mit countrycodes=<iso2>
     * auf. Ohne ausgewaehltes Land gibt es keinen Code, und die Suche liefert
     * zwangslaeufig nichts. Bisher blieb das Feld trotzdem bedienbar, was den
     * Eindruck erweckte, die gesuchte Stadt existiere nicht.
     *
     * @param {boolean} aktiv true = Land gewaehlt, Feld freigeben
     */
    setzeStadtfeldZustand(aktiv) {
        const $stadt = $('#citySelect');
        if (!$stadt.length) return;

        const text = aktiv ? 'Stadt wählen...' : 'Bitte zuerst ein Land wählen';

        // Erst den Sperrzustand setzen und select2 neu zeichnen lassen ...
        $stadt.prop('disabled', !aktiv).trigger('change.select2');

        // ... danach den Platzhalter setzen, sonst ueberschreibt ihn das
        // Neuzeichnen wieder. Beide Varianten abdecken: den von select2
        // gerenderten Platzhalter und die Option des nativen <select>,
        // falls select2 (noch) nicht gerendert hat.
        $stadt.next('.select2-container')
              .find('.select2-selection__placeholder')
              .text(text);
        $stadt.find('option[value=""]').text(text);
    },

    /**
     * Die Objektarten, die selbst ein Ort sind.
     *
     * Nominatim stellt jedem Treffer class und type voran: Eine Stadt ist
     * class "place" mit type "city", eine Strasse ist class "highway".
     */
    ORTSARTEN: ['city', 'town', 'village', 'hamlet', 'municipality',
                'suburb', 'borough', 'quarter'],

    /**
     * Ab welchem place_rank ein Verwaltungsgebiet als Ort zaehlt.
     *
     * Nominatim ordnet jedem Treffer einen Rang zu, der sagt, wie fein er
     * ist: Land 4, Bundesland 8, Kreis 12, Stadt 16, Dorf 19, Stadtteil 20.
     * Ab 13 faengt an, was man als Ort einer Fuehrung angeben wuerde - alles
     * Groebere ist eine Verwaltungsebene und kein Treffpunkt.
     */
    ORTSRANG_AB: 13,

    /**
     * Ist der Treffer selbst ein Ort - oder nur etwas, das in einem liegt?
     *
     * DIE GEMEINDEGRENZE WAR DER BLINDE FLECK
     * ---------------------------------------
     * Hier stand nur "class ist place" oder "addresstype ist eine Ortsart".
     * Beides trifft auf deutsche Gemeinden haeufig NICHT zu: Sie kommen als
     * class "boundary" mit type "administrative" zurueck, und ihr
     * addresstype kann ebenfalls "administrative" heissen. Solche Treffer
     * galten damit als "kein Ort" - ihr eigener Name wurde nicht gelesen,
     * ihre Namensvarianten nicht geprueft, und aus der Adresshierarchie kam
     * die uebergeordnete Samtgemeinde. Sie fielen wortlos heraus.
     *
     * Der Rang haelt dabei die Verwaltungsebenen draussen, die keine Orte
     * sind: Ein Kreis oder ein Bundesland ist auch eine Grenze mit type
     * "administrative" - aber niemand trifft sich in einem Bundesland.
     *
     * @param {Object} in_treffer Ein Eintrag der Nominatim-Antwort
     * @returns {boolean} false auch dann, wenn die Angaben fehlen - im
     *                    Zweifel gilt der Treffer NICHT als Ort
     */
    istOrtSelbst(in_treffer) {
        if (!in_treffer) return false;

        // Zu grob, um ein Ort zu sein - greift nur, wenn der Rang dabeisteht.
        const rang = Number(in_treffer.place_rank);
        if (Number.isFinite(rang) && rang < this.ORTSRANG_AB) return false;

        if (in_treffer.class === 'place'
            && this.ORTSARTEN.includes(in_treffer.type)) return true;

        // Gemeinden, Staedte und Stadtteile kommen oft als Grenze zurueck.
        if (in_treffer.class === 'boundary'
            && in_treffer.type === 'administrative') return true;

        return this.ORTSARTEN.includes(in_treffer.addresstype);
    },

    /**
     * Der Name, unter dem ein Treffer in der Liste stehen wuerde.
     *
     * DER TREFFER SELBST ODER DER ORT DRUMHERUM - DAS IST DIE FRAGE
     * ------------------------------------------------------------
     * Hier stand die Adresshierarchie zuerst:
     *
     *     address.city || address.town || address.village || ...
     *
     * Das sah nach "vom Groesseren zum Kleineren" aus und war der Fehler.
     * Nominatim legt in `address` die GANZE Hierarchie eines Treffers ab -
     * den Ort selbst UND alle uebergeordneten. Fuer das Dorf Rhede, das zu
     * Bocholt gehoert, steht dort
     *
     *     { "village": "Rhede", "town": "Bocholt", ... }
     *
     * und die Reihenfolge oben griff sich "Bocholt". Wer "rhed" tippte,
     * bekam also entweder den falschen Namen angeboten oder gar nichts -
     * denn "Bocholt" enthaelt "rhed" nicht, und damit fiel der Treffer durch
     * die Pruefung in passtZumSuchbegriff().
     *
     * DER EIGENE NAME STEHT WOANDERS: im ersten Abschnitt von display_name.
     * Den nimmt diese Methode jetzt, wenn der Treffer selbst ein Ort ist -
     * und zwar den, nicht namedetails.name: display_name ist in der Sprache
     * der Anfrage (accept-language), namedetails.name traegt den rohen
     * Namen aus OpenStreetMap. Fuer Lissabon heisst das "Lisbon" und nicht
     * "Lisboa" - und "Lisbon" ist auch das, was gespeichert wird.
     *
     * IST DER TREFFER KEIN ORT - eine Strasse, ein Gebaeude -, dann ist die
     * Adresshierarchie genau richtig: Dort steht die Stadt, in der das Ding
     * liegt. Sie wird angezeigt, muss aber erst noch zum Suchbegriff passen;
     * genau daran scheitert die "Rhedener Strasse" in Saarlouis.
     *
     * @param {Object} in_treffer Ein Eintrag der Nominatim-Antwort
     * @returns {string} Leerstring, wenn sich kein Name ableiten laesst
     */
    stadtNameVon(in_treffer) {
        if (this.istOrtSelbst(in_treffer)) {
            const eigen = this.eigenerNameVon(in_treffer);
            if (eigen !== '') return eigen;
        }

        const adresse = (in_treffer && in_treffer.address) || {};
        const drumherum = adresse.city || adresse.town || adresse.village
            || adresse.hamlet || adresse.municipality || adresse.suburb;
        if (drumherum) return String(drumherum);

        // WEDER ORT NOCH STADT DRUMHERUM: dann nichts.
        //
        // Hier stand ein letzter Rueckfall auf den eigenen Namen. Er sah
        // harmlos aus und holte genau das zurueck, was istOrtSelbst() eben
        // aussortiert hatte: Ein Kreis ist eine Grenze mit type
        // "administrative", faellt am Rang durch - und stand ueber diesen
        // Rueckfall wieder in der Liste, weil in seiner Adresshierarchie
        // keine Stadt steht, sondern nur er selbst als "county".
        //
        // Ein Treffer, von dem sich weder sagen laesst, dass er ein Ort ist,
        // noch in welcher Stadt er liegt, gehoert nicht in eine Auswahl von
        // Staedten. formatCityResults() ueberspringt den leeren Namen.
        return '';
    },

    /**
     * Der Name des Treffers selbst.
     *
     * Der erste Abschnitt von display_name: Dort steht der Gegenstand, alles
     * dahinter ist seine Umgebung. Und er ist uebersetzt - siehe
     * stadtNameVon(). Fehlt display_name, bleiben die rohen Namensfelder als
     * Rueckfall; die sind dann eben nicht uebersetzt, aber besser als nichts.
     *
     * @param {Object} in_treffer
     * @returns {string}
     */
    eigenerNameVon(in_treffer) {
        if (!in_treffer) return '';

        const anzeige = in_treffer.display_name;
        if (typeof anzeige === 'string' && anzeige.trim() !== '') {
            return anzeige.split(',')[0].trim();
        }
        if (typeof in_treffer.name === 'string' && in_treffer.name.trim() !== '') {
            return in_treffer.name.trim();
        }

        const varianten = in_treffer.namedetails;
        if (varianten && typeof varianten.name === 'string') return varianten.name.trim();

        return '';
    },

    /**
     * Passt ein Treffer zu dem, was der Nutzer getippt hat?
     *
     * DIE EINE PRUEFUNG - UND WARUM ES SIE BRAUCHT
     * -------------------------------------------
     * Hier standen ZWEI Durchgaenge. Der erste verglich den Suchbegriff mit
     * den Adressfeldern. Der zweite hing an "sind es weniger als drei
     * Treffer?" und schob dann die GANZE Antwort ungefiltert nach - ohne den
     * Suchbegriff auch nur anzusehen.
     *
     * Das war der Fehler: Nominatim antwortet auf "rhed" auch mit Objekten,
     * die den Begriff im Strassennamen tragen, und deren Adressfeld nennt
     * die Stadt drumherum. Der erste Durchgang warf sie richtigerweise
     * hinaus, der zweite holte sie zurueck - und in der Liste stand
     * "Saarlouis", weil dort eine Strasse mit "rhed" liegt. Es gibt jetzt
     * nur noch eine Regel: DER NAME, DER ANGEZEIGT WUERDE, MUSS PASSEN.
     *
     * DIE NAMENSVARIANTEN sind der zweite Teil, und sie haben eine Bedingung.
     * Seit die Anfrage accept-language=en traegt, kommen die Adressfelder auf
     * Englisch zurueck - wer "Lissabon" tippt, faende "Lisbon" sonst nicht
     * mehr. namedetails (name, name:de, name:en, ...) traegt beide
     * Schreibweisen.
     *
     * ABER: namedetails beschreibt DEN GEFUNDENEN GEGENSTAND, nicht die
     * Stadt. Bei der "Rhedener Strasse" steht dort "Rhedener Strasse" - und
     * wer die Varianten ungeprueft mitzaehlt, laesst Saarlouis durch genau
     * dieselbe Tuer wieder herein, die eben zugemacht wurde. (Das ist beim
     * Nachbauen der Antwort aufgefallen, nicht beim Lesen.) Deshalb zaehlen
     * die Varianten nur, wenn der Treffer SELBST ein Ort ist - was Nominatim
     * in class/type mitliefert.
     *
     * NICHT gegen das GANZE display_name: Dort stehen Strasse, Kreis, Land
     * und Postleitzahl mit drin, und "rhed" faende darueber die Strasse in
     * Saarlouis wieder. Vom display_name zaehlt nur der erste Abschnitt, und
     * der steckt schon in stadtNameVon().
     *
     * @param {Object} in_treffer  Ein Eintrag der Nominatim-Antwort
     * @param {string} in_gesucht  Suchbegriff, bereits kleingeschrieben
     * @returns {boolean}
     */
    passtZumSuchbegriff(in_treffer, in_gesucht) {
        if (in_gesucht === '') return true;

        const kandidaten = [this.stadtNameVon(in_treffer)];

        const varianten = in_treffer && in_treffer.namedetails;
        if (this.istOrtSelbst(in_treffer) && varianten && typeof varianten === 'object') {
            for (const wert of Object.values(varianten)) {
                if (typeof wert === 'string') kandidaten.push(wert);
            }
        }

        return kandidaten.some(k => k && k.toLowerCase().includes(in_gesucht));
    },

    /**
     * Bereitet die Städte-Ergebnisse für das Select2 vor.
     *
     * Ein Durchgang, eine Regel (passtZumSuchbegriff), und jeder Name nur
     * einmal. Bleibt nichts uebrig, bleibt die Liste leer - select2 sagt
     * dann "Keine Stadt gefunden.", und das ist die richtige Auskunft. Eine
     * Liste mit Orten, die nicht gesucht waren, ist schlechter als keine.
     *
     * @param {Array} data - API-Daten von Nominatim
     * @param {string} query - Suchbegriff
     * @returns {Array} Gefilterte und eindeutige Städte
     */
    formatCityResults(data, query) {
        const gesucht = (query || '').toLowerCase();
        const treffer = Array.isArray(data) ? data : [];

        const ergebnis = [];
        const gesehen = new Set();

        for (const eintrag of treffer) {
            if (!this.passtZumSuchbegriff(eintrag, gesucht)) continue;

            const name = this.stadtNameVon(eintrag);
            if (name === '' || gesehen.has(name)) continue;

            gesehen.add(name);
            ergebnis.push({
                id: name,
                text: name,
                lat: eintrag.lat,
                lon: eintrag.lon
            });
        }

        return ergebnis;
    },

    /**
     * Setzt Koordinatenfelder und OSM-Place zurück & entfernt Marker.
     */
    clearCoordsAndOsmPlace() {
        $('#latitude, #longitude, #lat, #lon, #osm_place').val('').text('');
        if (this.marker) {
            this.map.removeLayer(this.marker);
            this.marker = null;
        }
    },

    /**
     * Wird ausgelöst, wenn auf die Karte geklickt wird. Setzt Marker & Felder.
     */
    onMapClick(e) {
        if (this.marker) this.map.removeLayer(this.marker);
        this.marker = L.marker(e.latlng).addTo(this.map);
        $('#lat').text(e.latlng.lat.toFixed(6));
        $('#lon').text(e.latlng.lng.toFixed(6));
        $('#latitude').val(e.latlng.lat);
        $('#longitude').val(e.latlng.lng);

        fetch(this.nominatimUrl('reverse', { lat: e.latlng.lat, lon: e.latlng.lng }))
            .then(resp => resp.json())
            .then(data => {
                $('#osm_place').text(data.display_name || '');

                // Land im Select2 setzen, falls erkannt. Die Koordinaten
                // oben ueberstehen das - siehe landAusKoordinatenSetzen().
                if (data.address) {
                    this.landAusKoordinatenSetzen(data.address.country_code);
                }
                let place = '';
                if (data.address) {
                    place = data.address.city || data.address.town || data.address.village ||
                        data.address.hamlet || data.address.municipality || data.address.suburb || data.address.county || '';
                }
                if (!place) {
                    place = 'keine Stadt am Standort';
                }

                // Stadt im Select2 wählen
                if (place) {
                    let option = new Option(place, place, true, true);
                    $('#citySelect').append(option).trigger('change');
                }
            });
    },

    /**
     * Holt per GPS den aktuellen Standort, setzt alles und zoomt die Map.
     */
    onCurrentLocation() {
        if (!navigator.geolocation) {
            window.webrtcApp.notify.error('Ihr Browser unterstützt keine Standortbestimmung.');
            return;
        }
        navigator.geolocation.getCurrentPosition((pos) => {
            let lat = pos.coords.latitude, lon = pos.coords.longitude;
            fetch(this.nominatimUrl('reverse', { lat: lat, lon: lon }))
                .then(resp => resp.json())
                .then(data => {
                    $('#osm_place').text(data.display_name || '');
                    let found = '';
                    if (data.address) {
                        found = data.address.city || data.address.town || data.address.village ||
                            data.address.hamlet || data.address.municipality || data.address.suburb || data.address.county || '';
                    }
                    if (!found) {
                        found = 'keine Stadt am Standort';
                    }
                    if ($('#countrySelect').length && data.address) {
                        this.landAusKoordinatenSetzen(data.address.country_code);
                    }

                    // HIER STAND EIN setTimeout(..., 500).
                    //
                    // Es war kein Warten auf irgendetwas, sondern ein Pflaster:
                    // Der Landwechsel eine Zeile darueber loeschte die
                    // Koordinatenfelder (siehe initCitySelect2), also wurden
                    // sie eine halbe Sekunde spaeter noch einmal gesetzt - erst
                    // dann standen sie wieder da. Das Loeschen gibt es nicht
                    // mehr, damit auch keinen Grund zu warten. Marker und
                    // Koordinaten erscheinen jetzt sofort.
                    $('#lat').text(lat.toFixed(6));
                    $('#lon').text(lon.toFixed(6));
                    $('#latitude').val(lat);
                    $('#longitude').val(lon);
                    if (this.marker) this.map.removeLayer(this.marker);
                    this.marker = L.marker([lat, lon]).addTo(this.map);
                    this.map.setView([lat, lon], 14);

                    // Stadt im Select2 programmatisch wählen
                    if (found) {
                        let option = new Option(found, found, true, true);
                        $('#citySelect').append(option).trigger('change');
                    }
                });
        }, function (err) {
            window.webrtcApp.notify.error('Standort konnte nicht ermittelt werden: ' + err.message);
        });
    }
};

/**
 * Initialisierung der locationMap beim Laden der Seite.
 */
$(document).ready(function () {
    window.webrtcApp.locationMap.init();
});
