$(document).ready(function () {
    // ========== VARIABLEN & KONSTANTEN ==========
    const allowedCountryCodes = [ /* wie gehabt, komplette Liste */ 
      "AD","AE","AF","AG","AI","AL","AM","AO","AQ","AR","AS","AT","AU",
      "AW","AX","AZ","BA","BB","BD","BE","BF","BG","BH","BI","BJ","BL",
      "BM","BN","BO","BQ","BR","BS","BT","BV","BW","BY","BZ","CA","CC",
      "CD","CF","CG","CH","CI","CK","CL","CM","CN","CO","CR","CU","CV",
      "CW","CX","CY","CZ","DE","DJ","DK","DM","DO","DZ","EC","EE","EG",
      "EH","ER","ES","ET","FI","FJ","FK","FM","FO","FR","GA","GB","GD",
      "GE","GF","GG","GH","GI","GL","GM","GN","GP","GQ","GR","GS","GT",
      "GU","GW","GY","HK","HM","HN","HR","HT","HU","ID","IE","IL","IM",
      "IN","IO","IQ","IR","IS","IT","JE","JM","JO","JP","KE","KG","KH",
      "KI","KM","KN","KP","KR","KW","KY","KZ","LA","LB","LC","LI","LK",
      "LR","LS","LT","LU","LV","LY","MA","MC","MD","ME","MF","MG","MH",
      "MK","ML","MM","MN","MO","MP","MQ","MR","MS","MT","MU","MV","MW",
      "MX","MY","MZ","NA","NC","NE","NF","NG","NI","NL","NO","NP","NR",
      "NU","NZ","OM","PA","PE","PF","PG","PH","PK","PL","PM","PN","PR",
      "PS","PT","PW","PY","QA","RE","RO","RS","RU","RW","SA","SB","SC",
      "SD","SE","SG","SH","SI","SJ","SK","SL","SM","SN","SO","SR","SS",
      "ST","SV","SX","SY","SZ","TC","TD","TF","TG","TH","TJ","TK","TL",
      "TM","TN","TO","TR","TT","TV","TW","TZ","UA","UG","UM","US","UY",
      "UZ","VA","VC","VE","VG","VI","VN","VU","WF","WS","YE","YT","ZA",
      "ZM","ZW"
    ];
    let map, marker = null, selectedCountryCode = null, countryJustSetByLocation = false;

    $('#city').prop('disabled', true);

    // ========== HILFSFUNKTIONEN ==========

    // Gibt den aktuellen ISO2-Code des gewählten Landes zurück (oder '')
    function getCurrentCountryIso2() {
        return $('#countrySelect option:selected').data('iso2') ? $('#countrySelect option:selected').data('iso2').toUpperCase() : '';
    }

    // ========== INITIALISIERUNG ==========

    function initMap() {
        map = L.map('map').setView([51, 10], 5);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        map.on('click', onMapClick);
    }

    function loadCountries() {
        fetch('index.php?act=get_country')
            .then(response => response.json())
            .then(data => {
                $('#countrySelect').empty().append('<option value="">Land wählen...</option>');
                const filteredCountries = data
                    .filter(country => country.iso2 && allowedCountryCodes.includes(country.iso2.toUpperCase()))
                    .sort((a, b) => a.country_name.localeCompare(b.country_name));

                filteredCountries.forEach(country => {
                    $('#countrySelect').append(
                        $('<option>', {
                            value: country.id, // <-- interne DB-ID!
                            text: country.country_name,
                            'data-country-name': country.country_name,
                            'data-iso2': country.iso2,
                            code: country.emoji || ''
                        })
                    );
                });

                $('#countrySelect').select2({
                    placeholder: "Land wählen...",
                    allowClear: true,
                    templateResult: formatCountryOption,
                    templateSelection: formatCountryOption
                });
            });
    }

    function formatCountryOption(country) {
        if (!country.id) return country.text;
        // Für die Flagge weiterhin ISO2 nutzen:
        let iso2 = $(country.element).data('iso2');
        if (!iso2) return country.text;
        let $img = $('<img>', {
            src: 'https://flagcdn.com/24x18/' + iso2.toLowerCase() + '.png',
            style: 'width:24px;height:18px;margin-right:7px;vertical-align:middle;'
        });
        return $('<span>').append($img).append(' ' + country.text);
    }

    // ========== EVENT HANDLER ==========

    $('#countrySelect').on('change', onCountryChange);

    function onCountryChange() {
        if (countryJustSetByLocation) {
            countryJustSetByLocation = false;
            return;
        }

        let selectedOption = $(this).find('option:selected');
        let countryName = selectedOption.data('country-name');
        selectedCountryCode = selectedOption.val();
        let iso2 = selectedOption.data('iso2');

        if (!selectedCountryCode) {
            // Kein Land gewählt → City sperren und leeren
            $('#city').val('').prop('disabled', true);
            hideCitySuggestions();
            clearCoordsAndOsmPlace();
            return;
        }

        // Land wurde gewählt → City aktivieren
        $('#city').prop('disabled', false);

        // Map auf Land zentrieren (sofern möglich)
        fetch('https://nominatim.openstreetmap.org/search?country=' + encodeURIComponent(countryName) + '&format=json')
            .then(resp => resp.json())
            .then(data => {
                if (data[0] && data[0].lat && data[0].lon) {
                    map.setView([data[0].lat, data[0].lon], 6);
                } else {
                    alert("Für dieses Land steht leider keine Kartenansicht zur Verfügung.");
                    $('#countrySelect').val('').trigger('change');
                }
            });

        $('#city').val('');
        hideCitySuggestions();
        clearCoordsAndOsmPlace();
    }

    $('#city').on('input', onCityInput);

    function onCityInput() {
        let query = $(this).val().trim();
        if (!selectedCountryCode || query.length < 3) {
            let list = $('#city-suggestions');
            list.empty();
            if (query.length > 0) {
                list.append(
                    $('<li>')
                        .text('Bitte mehr Buchstaben eingeben.')
                        .css({'color': '#888', 'cursor': 'default'})
                );
                list.show();
            } else {
                list.hide();
            }
            return;
        }
        fetchCities(query, selectedCountryCode);
    }

    $(document).on('mousedown', function (e) {
        if (!$(e.target).closest('#city, #city-suggestions').length) {
            hideCitySuggestions();
        }
    });

    $('#current-location').on('click', onCurrentLocation);

    $('#countrySelect').on('select2:open', function () {
        setTimeout(() => document.querySelector('.select2-search__field').focus(), 100);
    });

    // ========== FUNKTIONEN ==========

    function fetchCities(query, countryId) {
        // Finde den ISO2-Code zur ID für Nominatim!
        let iso2 = $('#countrySelect option:selected').data('iso2');
        if (!iso2) return;
        fetch(`https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(query)}&countrycodes=${iso2}&format=json&addressdetails=1&limit=15`)
            .then(r => r.json())
            .then(data => {
                let cityResults = filterCityResults(data, query);
                renderCitySuggestions(cityResults, query);
            });
    }

    function filterCityResults(data, query) {
        let lcQuery = query.toLowerCase();
        let results = data.filter(item => {
            if (!item.address) return false;
            let fields = [
                item.address.city, item.address.town, item.address.village,
                item.address.hamlet, item.address.municipality, item.address.suburb
            ].filter(Boolean);
            return fields.some(f => f.toLowerCase().includes(lcQuery));
        });

        if (results.length < 3) {
            data.forEach(item => {
                if (!item.address) return;
                let fields = [
                    item.address.city, item.address.town, item.address.village,
                    item.address.hamlet, item.address.municipality, item.address.suburb
                ].filter(Boolean);
                if (fields.length > 0 && !results.includes(item)) {
                    results.push(item);
                }
            });
        }
        return results;
    }

    function renderCitySuggestions(cityResults, query) {
        let list = $('#city-suggestions');
        list.empty();

        if (cityResults.length === 0) {
            list.append(
                $('<li>')
                    .text('Keine passenden Städte gefunden.')
                    .css({'color': '#888', 'cursor': 'default'})
            );
            list.show();
            return;
        }

        cityResults.forEach(item => {
            let cityName = item.address.city
                || item.address.town
                || item.address.village
                || item.address.hamlet
                || item.address.municipality
                || item.address.suburb
                || item.display_name.split(',')[0];

            $('<li>')
                .text(cityName)
                .css('cursor', 'pointer')
                .on('mousedown', function () {
                    selectCityFromSuggestions(item, cityName);
                })
                .appendTo(list);
        });

        positionCitySuggestions();
        list.show();
    }

    function positionCitySuggestions() {
        let cityInput = $('#city');
        let offset = cityInput.offset();
        $('#city-suggestions').css({
            top: offset.top + cityInput.outerHeight(),
            left: offset.left,
            width: cityInput.outerWidth(),
            position: 'absolute',
            zIndex: 999,
            background: '#fff',
            border: '1px solid #aaa',
            padding: 0,
            margin: 0,
            listStyle: 'none',
            maxHeight: '200px',
            overflowY: 'auto'
        });
    }

    function selectCityFromSuggestions(item, cityName) {
        $('#city').val(cityName);
        hideCitySuggestions();
        map.setView([item.lat, item.lon], 12);
        if (marker) map.removeLayer(marker);
        marker = L.marker([item.lat, item.lon]).addTo(map);
        $('#latitude').val(item.lat);
        $('#longitude').val(item.lon);
        $('#lat').text(parseFloat(item.lat).toFixed(6));
        $('#lon').text(parseFloat(item.lon).toFixed(6));
        fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + item.lat + '&lon=' + item.lon)
            .then(resp => resp.json())
            .then(data => {
                $('#osm_place').text(data.display_name || '');
            });
    }

    function hideCitySuggestions() {
        $('#city-suggestions').hide().empty();
    }

    function clearCoordsAndOsmPlace() {
        $('#latitude, #longitude, #lat, #lon, #osm_place').val('').text('');
    }

    function onMapClick(e) {
        if (marker) map.removeLayer(marker);
        marker = L.marker(e.latlng).addTo(map);
        $('#lat').text(e.latlng.lat.toFixed(6));
        $('#lon').text(e.latlng.lng.toFixed(6));
        $('#latitude').val(e.latlng.lat);
        $('#longitude').val(e.latlng.lng);

        // Reverse Geocoding für Stadt, OSM-Place, Land
        fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + e.latlng.lat + '&lon=' + e.latlng.lng)
            .then(resp => resp.json())
            .then(data => {
                $('#osm_place').text(data.display_name || '');

                // Land automatisch setzen (ISO2 → ID)
                if (data.address && data.address.country_code) {
                    let cc = data.address.country_code.toUpperCase();
                    let countryOption = $('#countrySelect option').filter(function () {
                        return $(this).data('iso2') && $(this).data('iso2').toUpperCase() === cc;
                    });
                    if (countryOption.length && getCurrentCountryIso2() !== cc) {
                        countryJustSetByLocation = true;
                        $('#countrySelect').val(countryOption.val()).trigger('change');
                    }
                }

                // Stadt setzen
                let place = '';
                if (data.address) {
                    place = data.address.city || data.address.town || data.address.village ||
                        data.address.hamlet || data.address.municipality || data.address.suburb || data.address.county || '';
                }
                if (!place) {
                    place = 'keine Stadt am Standort';
                }
                $('#city').val(place);
            });
    }

    function onCurrentLocation() {
        if (!navigator.geolocation) {
            alert("Ihr Browser unterstützt keine Geolokalisierung.");
            return;
        }
        navigator.geolocation.getCurrentPosition(function (pos) {
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
                    // Land automatisch setzen (ISO2 → ID)
                    if ($('#countrySelect').length && data.address && data.address.country_code) {
                        let cc = data.address.country_code.toUpperCase();
                        let countryOption = $('#countrySelect option').filter(function () {
                            return $(this).data('iso2') && $(this).data('iso2').toUpperCase() === cc;
                        });
                        if (countryOption.length && getCurrentCountryIso2() !== cc) {
                            countryJustSetByLocation = true;
                            $('#countrySelect').val(countryOption.val()).trigger('change');
                        }
                    }
                    // Nach kurzem Delay Stadt und Marker setzen
                    setTimeout(function () {
                        $('#city').val(found).prop('disabled', false);
                        $('#lat').text(lat.toFixed(6));
                        $('#lon').text(lon.toFixed(6));
                        $('#latitude').val(lat);
                        $('#longitude').val(lon);
                        if (marker) map.removeLayer(marker);
                        marker = L.marker([lat, lon]).addTo(map);
                        map.setView([lat, lon], 14);
                    }, 500);
                });
        }, function (err) {
            alert("Standort konnte nicht ermittelt werden: " + err.message);
        });
    }


    // ========== START ==========
    initMap();
    loadCountries();


    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('success') === '1'){
        alert("Lokation erfolgreich gespeichert!");
    }
});
