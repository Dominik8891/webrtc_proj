$(document).ready(function() {
    // Map initialisieren
    let map = L.map('map').setView([51, 10], 5);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);
    let marker = null;

    // Länder per AJAX holen und in Select füllen
    fetch('index.php?act=get_country')
        .then(response => response.json())
        .then(data => {
            for (let country of data) {
                $('#countrySelect').append(
                    $('<option>', { 
                        value: country.id, 
                        text: country.country_name, 
                        'data-country-name': country.country_name 
                    })
                );
            }
            $('#countrySelect').select2({
                placeholder: "Land wählen...",
                allowClear: true
            });
        });

    // Land auswählen => Map zentrieren
    $('#countrySelect').on('change', function() {
        let selectedOption = $(this).find('option:selected');
        let countryName = selectedOption.data('country-name');
        if (!countryName) return;
        fetch('https://nominatim.openstreetmap.org/search?country=' + encodeURIComponent(countryName) + '&format=json')
            .then(resp => resp.json())
            .then(data => {
                if (data[0]) {
                    map.setView([data[0].lat, data[0].lon], 6);
                }
            });
    });

    // Marker setzen & Koordinaten anzeigen/speichern
    map.on('click', function(e) {
        if (marker) map.removeLayer(marker);
        marker = L.marker(e.latlng).addTo(map);
        document.getElementById('lat').textContent = e.latlng.lat.toFixed(6);
        document.getElementById('lon').textContent = e.latlng.lng.toFixed(6);
        // Falls du versteckte Felder im Formular hast:
        if (document.getElementById('latitude')) document.getElementById('latitude').value = e.latlng.lat;
        if (document.getElementById('longitude')) document.getElementById('longitude').value = e.latlng.lng;
        fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + e.latlng.lat + '&lon=' + e.latlng.lng)
            .then(resp => resp.json())
            .then(data => {
                document.getElementById('osm_place').textContent = data.display_name || '';
                // Wenn du das City-Feld automatisch setzen willst:
                if (document.getElementById('city')) {
                    let place = data.address && data.address.city ? data.address.city : (data.address && data.address.town ? data.address.town : '');
                    document.getElementById('city').value = place;
                }
            });
    });

    // Optional: Suchfeld von Select2 automatisch fokussieren
    $('#countrySelect').on('select2:open', function() {
        setTimeout(function() {
            document.querySelector('.select2-search__field').focus();
        }, 100);
    });
});
