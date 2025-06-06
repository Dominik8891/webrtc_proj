window.webrtcApp = window.webrtcApp || {};
window.webrtcApp.locationMap = {
    allowedCountryCodes: [
        // (Länderliste gekürzt für Übersichtlichkeit, bleibt wie bei dir)
        "DE","AT","CH","NL","FR","IT","ES","PL","BE","DK","CZ","SE","NO","FI","US","GB","CA","AU" // ...usw.
    ],
    map: null,
    marker: null,
    selectedCountryCode: null,
    countryJustSetByLocation: false,

    getCurrentCountryIso2() {
        return $('#countrySelect option:selected').data('iso2') ? $('#countrySelect option:selected').data('iso2').toUpperCase() : '';
    },

    init() {
        if (!$('#map').length || !$('#countrySelect').length) return;

        this.initMap();
        this.loadCountries();
        this.initCitySelect2();
        this.bindEvents();

        // Erfolgsmeldung, falls aus URL
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('success') === '1'){
            alert("Lokation erfolgreich gespeichert!");
        }
    },

    initMap() {
        this.map = L.map('map').setView([51, 10], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(this.map);

        this.map.on('click', (e) => this.onMapClick(e));
    },

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

    bindEvents() {
        // Land-Auswahl
        $('#countrySelect').on('change', () => this.onCountryChange());

        // Stadt-Auswahl wird in initCitySelect2() behandelt

        // Aktueller Standort Button
        $('#current-location').on('click', () => this.onCurrentLocation());
    },

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
            // Felder resetten
            $('#citySelect').val('').trigger('change');
            this.clearCoordsAndOsmPlace();
            return;
        }

        // Karte auf Land zoomen
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

        // Felder resetten
        $('#citySelect').val('').trigger('change');
        this.clearCoordsAndOsmPlace();
    },

    // === NEU: Stadt-Select2 initialisieren ===
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

        // Automatisch Fokus ins Suchfeld, wenn geöffnet
        $('#citySelect').on('select2:open', function () {
            setTimeout(() => {
                document.querySelector('.select2-search__field').focus();
            }, 100);
        });

        // Bei Landwechsel: Reset
        $('#countrySelect').on('change', () => {
            $('#citySelect').val('').trigger('change');
            $('#latitude, #longitude, #lat, #lon, #osm_place').val('').text('');
        });

        // Wenn Stadt gewählt wird, alles setzen
        $('#citySelect').on('select2:select', (e) => {
            const data = e.params.data;
            $('#latitude').val(data.lat);
            $('#longitude').val(data.lon);
            $('#lat').text(parseFloat(data.lat).toFixed(6));
            $('#lon').text(parseFloat(data.lon).toFixed(6));
            // Marker setzen
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

        // Bei Clear alles zurücksetzen
        $('#citySelect').on('select2:clear', () => {
            $('#latitude, #longitude, #lat, #lon, #osm_place').val('').text('');
            if (self.marker) {
                self.map.removeLayer(self.marker);
                self.marker = null;
            }
        });
    },


    // Hilfsfunktion: Formatiere City-API-Antworten für Select2
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

        // Wenn zu wenig Ergebnisse, erweitere
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
        // Nur eindeutige Namen
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

    clearCoordsAndOsmPlace() {
        $('#latitude, #longitude, #lat, #lon, #osm_place').val('').text('');
        if (this.marker) {
            this.map.removeLayer(this.marker);
            this.marker = null;
        }
    },

    // Wenn auf Karte geklickt wird
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

                // Versuche, die Stadt im Select2 vorzuwählen (falls vorhanden)
                if (place) {
                    // Select2 "programmgesteuert" setzen
                    let option = new Option(place, place, true, true);
                    $('#citySelect').append(option).trigger('change');
                }
            });
    },

    // Aktuellen Standort per GPS verwenden
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

                        // Stadtname ins Select2 setzen
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

// Init beim DOM-Ready
$(document).ready(function () {
    window.webrtcApp.locationMap.init();
});
