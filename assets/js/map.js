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

        this.initMap();
        this.loadCountries();
        this.initCitySelect2();
        this.bindEvents();

        // Erfolgsmeldung nach Save
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('success') === '1'){
            alert("Lokation erfolgreich gespeichert!");
        }
    },

    /**
     * Erzeugt die Leaflet-Map mit Standard-View.
     */
    initMap() {
        this.map = L.map('map').setView([51, 10], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(this.map);

        this.map.on('click', (e) => this.onMapClick(e));
    },

    /**
     * Lädt Länder vom Backend und initialisiert das Country-Select2.
     */
    loadCountries() {
        fetch('index.php?act=get_country')
            .then(response => response.json())
            .then(data => {
                $('#countrySelect').empty().append('<option value="">Land wählen...</option>');
                const filteredCountries = data
                    .filter(country => country.iso2 && this.allowedCountryCodes.includes(country.iso2.toUpperCase()))
                    .sort((a, b) => a.country_name.localeCompare(b.country_name));

                filteredCountries.forEach(country => {
                    $('#countrySelect').append(
                        $('<option>', {
                            value: country.id,
                            text: country.country_name,
                            'data-country-name': country.country_name,
                            'data-iso2': country.iso2,
                            code: country.emoji || ''
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
            });
    },

    /**
     * Stellt Länderoption in Select2 hübsch mit Flagge dar.
     */
    formatCountryOption(country) {
        if (!country.id) return country.text;
        let iso2 = $(country.element).data('iso2');
        if (!iso2) return country.text;
        let $img = $('<img>', {
            src: 'https://flagcdn.com/24x18/' + iso2.toLowerCase() + '.png',
            style: 'width:24px;height:18px;margin-right:7px;vertical-align:middle;'
        });
        return $('<span>').append($img).append(' ' + country.text);
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
    },

    /**
     * Wird ausgelöst, wenn ein Land gewählt wurde.
     */
    onCountryChange() {
        if (this.countryJustSetByLocation) {
            this.countryJustSetByLocation = false;
            return;
        }
        let selectedOption = $('#countrySelect').find('option:selected');
        let countryName = selectedOption.data('country-name');
        this.selectedCountryCode = selectedOption.val();
        let iso2 = selectedOption.data('iso2');

        if (!this.selectedCountryCode) {
            // Alle Felder resetten
            $('#citySelect').val('').trigger('change');
            this.clearCoordsAndOsmPlace();
            return;
        }

        // Map auf das gewählte Land zentrieren
        fetch('https://nominatim.openstreetmap.org/search?country=' + encodeURIComponent(countryName) + '&format=json')
            .then(resp => resp.json())
            .then(data => {
                if (data[0] && data[0].lat && data[0].lon) {
                    this.map.setView([data[0].lat, data[0].lon], 6);
                } else {
                    alert("Für dieses Land steht leider keine Kartenansicht zur Verfügung.");
                    $('#countrySelect').val('').trigger('change');
                }
            });

        $('#citySelect').val('').trigger('change');
        this.clearCoordsAndOsmPlace();
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
                delay: 300,
                transport: (params, success, failure) => {
                    let countryIso2 = $('#countrySelect option:selected').data('iso2');
                    if (!countryIso2) return success({ results: [] });
                    let query = params.data.q;
                    fetch(`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(query)}&countrycodes=${countryIso2}&format=json&addressdetails=1&limit=15`)
                        .then(r => r.json())
                        .then(data => {
                            success({ results: self.formatCityResults(data, query) });
                        }).catch(failure);
                },
                processResults: data => ({ results: data.results }),
            },
            templateResult: city => city.text,
            templateSelection: city => city.text,
            language: {
                inputTooShort: () => 'Bitte mindestens 3 Buchstaben eingeben.'
            }
        });

        // Fokus im Suchfeld, wenn Select2 geöffnet
        $('#citySelect').on('select2:open', function () {
            setTimeout(() => {
                document.querySelector('.select2-search__field').focus();
            }, 100);
        });

        // Nach Landwechsel alles zurücksetzen
        $('#countrySelect').on('change', () => {
            $('#citySelect').val('').trigger('change');
            $('#latitude, #longitude, #lat, #lon, #osm_place').val('').text('');
        });

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
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${data.lat}&lon=${data.lon}`)
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
     * Bereitet die Städte-Ergebnisse für das Select2 vor.
     * @param {Array} data - API-Daten von Nominatim
     * @param {string} query - Suchbegriff
     * @returns {Array} Gefilterte und eindeutige Städte
     */
    formatCityResults(data, query) {
        let lcQuery = (query || '').toLowerCase();
        let results = data.filter(item => {
            if (!item.address) return false;
            let fields = [
                item.address.city, item.address.town, item.address.village,
                item.address.hamlet, item.address.municipality, item.address.suburb
            ].filter(Boolean);
            return fields.some(f => f.toLowerCase().includes(lcQuery));
        }).map(item => {
            let cityName = item.address.city
                || item.address.town
                || item.address.village
                || item.address.hamlet
                || item.address.municipality
                || item.address.suburb
                || item.display_name.split(',')[0];
            return {
                id: cityName,
                text: cityName,
                lat: item.lat,
                lon: item.lon
            };
        });

        // Falls zu wenig Ergebnisse, noch weitere hinzufügen
        if (results.length < 3) {
            data.forEach(item => {
                if (!item.address) return;
                let cityName = item.address.city
                    || item.address.town
                    || item.address.village
                    || item.address.hamlet
                    || item.address.municipality
                    || item.address.suburb
                    || item.display_name.split(',')[0];
                if (cityName && !results.find(r => r.text === cityName)) {
                    results.push({
                        id: cityName,
                        text: cityName,
                        lat: item.lat,
                        lon: item.lon
                    });
                }
            });
        }
        // Nur eindeutige Namen (keine Dubletten)
        const unique = [];
        const map = {};
        for (const r of results) {
            if (!map[r.text]) {
                unique.push(r);
                map[r.text] = true;
            }
        }
        return unique;
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

        fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + e.latlng.lat + '&lon=' + e.latlng.lng)
            .then(resp => resp.json())
            .then(data => {
                $('#osm_place').text(data.display_name || '');

                // Land im Select2 setzen, falls erkannt
                if (data.address && data.address.country_code) {
                    let cc = data.address.country_code.toUpperCase();
                    let countryOption = $('#countrySelect option').filter(function () {
                        return $(this).data('iso2') && $(this).data('iso2').toUpperCase() === cc;
                    });
                    if (countryOption.length && this.getCurrentCountryIso2() !== cc) {
                        this.countryJustSetByLocation = true;
                        $('#countrySelect').val(countryOption.val()).trigger('change');
                    }
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
            alert("Ihr Browser unterstützt keine Geolokalisierung.");
            return;
        }
        navigator.geolocation.getCurrentPosition((pos) => {
            let lat = pos.coords.latitude, lon = pos.coords.longitude;
            fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lon)
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
                    if ($('#countrySelect').length && data.address && data.address.country_code) {
                        let cc = data.address.country_code.toUpperCase();
                        let countryOption = $('#countrySelect option').filter(function () {
                            return $(this).data('iso2') && $(this).data('iso2').toUpperCase() === cc;
                        });
                        if (countryOption.length && this.getCurrentCountryIso2() !== cc) {
                            this.countryJustSetByLocation = true;
                            $('#countrySelect').val(countryOption.val()).trigger('change');
                        }
                    }
                    setTimeout(() => {
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
                    }, 500);
                });
        }, function (err) {
            alert("Standort konnte nicht ermittelt werden: " + err.message);
        });
    }
};

/**
 * Initialisierung der locationMap beim Laden der Seite.
 */
$(document).ready(function () {
    window.webrtcApp.locationMap.init();
});
