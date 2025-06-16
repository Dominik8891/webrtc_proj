// assets/js/locations_table.js

window.webrtcApp.locationsTable = {
    loadLocationsTable() {
        $.ajax({
            url: 'index.php?act=get_locations',
            method: 'GET',
            dataType: 'json',
            success: function (data) {
                let rows = '';
                data.forEach(function (item, i) {
                    let icon = "🔴";
                    let status = "Offline";
                    if (item.user_status === "in_call") {
                        icon = '<span style="color: orange; font-size: 1.5em;">&#x1F7E0;</span>';
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
    },

    showMapPopup(e, lat, lng, country, city, description) {
        $('#descMapHeader').text(`${country} ${city ? '– ' + city : ''}`);
        $('#descMapPopup').css({
            left: e.pageX + 15,
            top: e.pageY - 80
        }).show();
        if (!this.mapInitialized) {
            this.map = L.map('descMap').setView([lat, lng], 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(this.map);
            this.mapInitialized = true;
        } else {
            this.map.setView([lat, lng], 14);
        }
        L.marker([lat, lng]).addTo(this.map).bindPopup(description).openPopup();
    },

    hideMapPopup() {
        $('#descMapPopup').hide();
        if(this.map) this.map.eachLayer(function (layer) {
            if(layer instanceof L.Marker) this.map.removeLayer(layer);
        }.bind(this));
    },

    showModalMap(lat, lng, country, city, description) {
        $('#modalLocationInfo').text(`${country} ${city ? '– ' + city : ''}`);
        $('#mapModal').modal('show');
        setTimeout(() => {
            if (!this.modalMapInitialized) {
                this.modalMap = L.map('modalMap').setView([lat, lng], 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(this.modalMap);
                this.modalMapInitialized = true;
            } else {
                this.modalMap.setView([lat, lng], 16);
            }
            this.modalMap.eachLayer(function (layer) {
                if(layer instanceof L.Marker) this.modalMap.removeLayer(layer);
            }.bind(this));
            L.marker([lat, lng]).addTo(this.modalMap).bindPopup(description).openPopup();
        }, 200);
    },

    map: null,
    modalMap: null,
    mapInitialized: false,
    modalMapInitialized: false,

    bindEvents() {
        // Nur binden, wenn die Tabelle da ist!
        if (!$('#locationsTable').length) return;
        $('#locationsTable').DataTable();
        this.loadLocationsTable();
        setInterval(() => this.loadLocationsTable(), 10000);

        $('#locationsTable').on('mouseenter', '.desc-hover', (e) => {
            let $t = $(e.currentTarget);
            let lat = parseFloat($t.data('lat'));
            let lng = parseFloat($t.data('lng'));
            let country = $t.data('country');
            let city = $t.data('city');
            let description = $t.text();
            if(isNaN(lat) || isNaN(lng)) return;
            this.showMapPopup(e, lat, lng, country, city, description);
        });
        $('#locationsTable').on('mousemove', '.desc-hover', (e) => {
            $('#descMapPopup').css({left: e.pageX + 15, top: e.pageY - 80});
        });
        $('#locationsTable').on('mouseleave', '.desc-hover', () => {
            this.hideMapPopup();
        });
        $('#locationsTable').on('click', '.desc-hover', (e) => {
            e.stopPropagation();
            let $t = $(e.currentTarget);
            let lat = parseFloat($t.data('lat'));
            let lng = parseFloat($t.data('lng'));
            let country = $t.data('country');
            let city = $t.data('city');
            let description = $t.text();
            if(isNaN(lat) || isNaN(lng)) return;
            this.showModalMap(lat, lng, country, city, description);
        });
        $('#mapModal').on('hidden.bs.modal', () => {
            if(this.modalMap) this.modalMap.eachLayer(function (layer) {
                if(layer instanceof L.Marker) this.modalMap.removeLayer(layer);
            }.bind(this));
            setTimeout(function(){
                $('#locationsTable').focus();
            }, 10);
        });

        $('#locationsTable').on('click', '.start-call-btn', function() {
            const userId = $(this).data('userid');
            if(typeof window.webrtcApp?.rtc?.startCall === 'function') {
                window.webrtcApp.rtc.startCall(userId);
            } else {
                alert("Call-Funktion nicht verfügbar.");
            }
        });
    }
};

// Initialisierung beim DOM-Ready:
$(document).ready(function () {
    window.webrtcApp.locationsTable.bindEvents();
});
