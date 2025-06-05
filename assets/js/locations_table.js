// Leaflet CSS und JS laden, falls nicht schon im HTML eingebunden
$.getScript('https://unpkg.com/leaflet/dist/leaflet.js');

function loadLocationsTable() {
    $.ajax({
        url: 'index.php?act=get_locations',
        method: 'GET',
        dataType: 'json',
        success: function (data) {
            let rows = '';
            data.forEach(function (item, i) {
                let icon = "🔴"; // default offline
                let status = "Offline";
                if (item.user_status === "in_call") {
                    icon = '<span style="color: orange; font-size: 1.5em;">&#x1F7E0;</span>'; // 🟠 orange circle
                    status = "Befindet sich in Call";
                } else if (item.user_status === "online") {
                    icon = "🟢";
                    status = "Online";
                }
                rows += `<tr>
                    <td>${i + 1}</td>
                    <td>${status}</td>
                    <td>${icon} ${item.username}</td>
                    <td>${item.country_name ?? ''}</td>
                    <td>${item.city_name ?? ''}</td>
                    <td>
                        <span 
                            class="desc-hover" 
                            data-lat="${item.latitude}" 
                            data-lng="${item.longitude}" 
                            data-country="${item.country_name ?? ''}" 
                            data-city="${item.city_name ?? ''}" 
                            style="cursor:pointer; color:#0366d6; text-decoration:underline;">
                            ${item.description}
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-success start-call-btn"
                            data-userid="${item.user_id}"
                            ${item.user_status !== "online" ? "disabled aria-disabled='true' style='pointer-events:none;opacity:0.5;'" : ""}
                        >
                        Call
                    </td>
                </tr>`;
            });
            $('#locationsTable tbody').html(rows);
        },
        error: function () {
            $('#locationsTable tbody').html('<tr><td colspan="7">Fehler beim Laden der Daten.</td></tr>');
        }
    });
}

$(document).ready(function () {
    // DataTable nur EINMAL initialisieren!
    $('#locationsTable').DataTable();

    // Initiales Laden
    loadLocationsTable();

    // Alle 10 Sekunden aktualisieren
    setInterval(loadLocationsTable, 10000);

    // ---- Events ----
    let map, modalMap;
    let mapInitialized = false, modalMapInitialized = false;

    function showMapPopup(e, lat, lng, country, city, description) {
        $('#descMapHeader').text(`${country} ${city ? '– ' + city : ''}`);
        $('#descMapPopup').css({
            left: e.pageX + 15,
            top: e.pageY - 80
        }).show();
        if (!mapInitialized) {
            map = L.map('descMap').setView([lat, lng], 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
            mapInitialized = true;
        } else {
            map.setView([lat, lng], 14);
        }
        L.marker([lat, lng]).addTo(map).bindPopup(description).openPopup();
    }

    function hideMapPopup() {
        $('#descMapPopup').hide();
        if(map) map.eachLayer(function (layer) {
            if(layer instanceof L.Marker) map.removeLayer(layer);
        });
    }

    function showModalMap(lat, lng, country, city, description) {
        $('#modalLocationInfo').text(`${country} ${city ? '– ' + city : ''}`);
        $('#mapModal').modal('show');
        setTimeout(function(){
            if (!modalMapInitialized) {
                modalMap = L.map('modalMap').setView([lat, lng], 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(modalMap);
                modalMapInitialized = true;
            } else {
                modalMap.setView([lat, lng], 16);
            }
            modalMap.eachLayer(function (layer) {
                if(layer instanceof L.Marker) modalMap.removeLayer(layer);
            });
            L.marker([lat, lng]).addTo(modalMap).bindPopup(description).openPopup();
        }, 200); // warten, bis modal sichtbar
    }

    // Events für Beschreibung
    $('#locationsTable').on('mouseenter', '.desc-hover', function(e){
        let lat = parseFloat($(this).data('lat'));
        let lng = parseFloat($(this).data('lng'));
        let country = $(this).data('country');
        let city = $(this).data('city');
        let description = $(this).text();
        if(isNaN(lat) || isNaN(lng)) return;
        showMapPopup(e, lat, lng, country, city, description);
    });
    $('#locationsTable').on('mousemove', '.desc-hover', function(e){
        $('#descMapPopup').css({left: e.pageX + 15, top: e.pageY - 80});
    });
    $('#locationsTable').on('mouseleave', '.desc-hover', function(){
        hideMapPopup();
    });
    // Klick auf Beschreibung für große Karte
    $('#locationsTable').on('click', '.desc-hover', function(e){
        e.stopPropagation();
        let lat = parseFloat($(this).data('lat'));
        let lng = parseFloat($(this).data('lng'));
        let country = $(this).data('country');
        let city = $(this).data('city');
        let description = $(this).text();
        if(isNaN(lat) || isNaN(lng)) return;
        showModalMap(lat, lng, country, city, description);
    });
    // Map Modal bei Schließen aufräumen
    $('#mapModal').on('hidden.bs.modal', function(){
        if(modalMap) modalMap.eachLayer(function (layer) {
            if(layer instanceof L.Marker) modalMap.removeLayer(layer);
        });
        setTimeout(function(){
            // Fokus auf das nächste sinnvolle Element setzen
            $('#locationsTable').focus();
        }, 10); // kleiner Delay, damit Bootstrap aria-hidden gesetzt hat
    });

    // Call-Button Handler
    $('#locationsTable').on('click', '.start-call-btn', function() {
        const userId = $(this).data('userid');
        if(typeof window.webrtcApp?.rtc?.startCall === 'function') {
            window.webrtcApp.rtc.startCall(userId);
        } else {
            alert("Call-Funktion nicht verfügbar.");
        }
    });
});
