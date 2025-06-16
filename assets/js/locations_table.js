window.webrtcApp.locationsTable = {
    /**
     * Konfigurierbares Laden für alle oder eigene Locations
     * options: {
     *    onlyOwn: bool,
     *    showActions: Array<"call"|"edit"|"delete">,
     *    tableSelector: string
     * }
     */
loadLocationsTable(options) {options = Object.assign({onlyOwn: false, showActions: ["call"], tableSelector: "#locationsTable"}, options || {});
            let apiUrl = options.onlyOwn ? 'index.php?act=get_my_locations' : 'index.php?act=get_locations';
        $.ajax({
            url: apiUrl,
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

                    // Beschreibung wie gehabt
                    let descHtml = `
                        <span 
                            class="desc-hover" 
                            data-lat="${item.latitude}" 
                            data-lng="${item.longitude}" 
                            data-country="${item.country_name ?? ''}" 
                            data-city="${item.city_name ?? ''}" 
                            style="cursor:pointer; color:#0366d6; text-decoration:underline;">
                            ${item.description}
                        </span>
                    `;

                    // Aktionen je nach Optionen
                    let actionBtns = '';
                    if(options.showActions.includes("call")) {
                        actionBtns += `
                            <button class="btn btn-success start-call-btn"
                                data-userid="${item.user_id}"
                                ${item.user_status !== "online" ? "disabled aria-disabled='true' style='pointer-events:none;opacity:0.5;'" : ""}
                            >
                                Call
                            </button>
                        `;
                    }
                    if(options.showActions.includes("edit")) {
                        actionBtns += `
                            <button class="btn btn-warning edit-location-btn" data-locationid="${item.id}">Ändern</button>
                        `;
                        console.log(item.id, item);

                    }
                    if(options.showActions.includes("delete")) {
                        actionBtns += `
                            <button class="btn btn-danger delete-location-btn" data-locationid="${item.id}">Löschen</button>
                        `;
                    }

                    rows += `<tr>
                        <td>${i + 1}</td>
                        <td>${status}</td>
                        ${options.onlyOwn ? "" : `<td>${icon} ${item.username}</td>`}
                        <td>${item.country_name ?? ''}</td>
                        <td>${item.city_name ?? ''}</td>
                        <td>${descHtml}</td>
                        <td>${actionBtns}</td>
                    </tr>`;
                });

                // Tabelle je nach Selector füllen
                $(options.tableSelector + ' tbody').html(rows);
            },
            error: function () {
                $(options.tableSelector + ' tbody').html('<tr><td colspan="7">Fehler beim Laden der Daten.</td></tr>');
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

    bindEvents(options = {onlyOwn: false, tableSelector: "#locationsTable"}) {
        if (!$(options.tableSelector).length) return;
        $(options.tableSelector).DataTable();
        // Initial laden:
        this.loadLocationsTable(options);

        // Map Events (wie gehabt, du kannst sie so lassen)
        $(options.tableSelector).on('mouseenter', '.desc-hover', (e) => {
            let $t = $(e.currentTarget);
            let lat = parseFloat($t.data('lat'));
            let lng = parseFloat($t.data('lng'));
            let country = $t.data('country');
            let city = $t.data('city');
            let description = $t.text();
            if(isNaN(lat) || isNaN(lng)) return;
            this.showMapPopup(e, lat, lng, country, city, description);
        });
        $(options.tableSelector).on('mousemove', '.desc-hover', (e) => {
            $('#descMapPopup').css({left: e.pageX + 15, top: e.pageY - 80});
        });
        $(options.tableSelector).on('mouseleave', '.desc-hover', () => {
            this.hideMapPopup();
        });
        $(options.tableSelector).on('click', '.desc-hover', (e) => {
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
        // Actions:
        $(options.tableSelector).on('click', '.start-call-btn', function() {
            const userId = $(this).data('userid');
            if(typeof window.webrtcApp?.rtc?.startCall === 'function') {
                window.webrtcApp.rtc.startCall(userId);
            } else {
                alert("Call-Funktion nicht verfügbar.");
            }
        });
        // Nur für eigene Locations
        $(options.tableSelector)
        .off('click', '.edit-location-btn')
        .on('click', '.edit-location-btn', function() {
            const locationId = $(this).data('locationid'); // <-- Muss eine echte Zahl sein, z.B. 7
            const $row = $(this).closest('tr');
            const currentDescription = $row.find('.desc-hover').text().trim();

            $('#editLocationId').val(locationId); // <-- Hier MUSS jetzt z.B. '7' stehen, NICHT 'undefined'
            $('#currentDescription').val(currentDescription);
            $('#newDescription').val('');
            $('#editDescModal').modal('show');
        });
        $(options.tableSelector)
            .off('click', '.delete-location-btn')
            .on('click', '.delete-location-btn', function() {
                const locationId = $(this).data('locationid');
                if (!locationId) {
                    alert("Fehler: Keine Location-ID gefunden!");
                    return;
                }
                if (confirm("Willst du diese Location wirklich löschen?")) {
                    $.ajax({
                        url: 'index.php?act=delete_location',
                        method: 'POST',
                        data: { id: locationId },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                // Tabelle neu laden
                                window.webrtcApp.locationsTable.loadLocationsTable({
                                    onlyOwn: true,
                                    showActions: ["edit", "delete"],
                                    tableSelector: "#locationsTable"
                                });
                            } else {
                                alert('Fehler: ' + (response.error || 'Unbekannter Fehler'));
                            }
                        },
                        error: function() {
                            alert('Fehler beim Löschen!');
                        }
                    });
                }
            });
    },
};

// Initialisierung beim DOM-Ready:
$(document).ready(function () {
    window.webrtcApp.locationsTable.bindEvents();
        // Für die globale Tabelle auf der Übersichtsseite:
    if($('#locationsTable').length && !$('#myLocationsSection').length) { // auf der Hauptseite
        window.webrtcApp.locationsTable.bindEvents({
            onlyOwn: false,
            showActions: ["call"],
            tableSelector: "#locationsTable"
        });
    }

    // Für die eigene Locations-Tabelle auf der settings.html
    if($('#myLocationsSection').length) {
        $('#showOwnLocationsBtn').show().on('click', function(e) {
            e.preventDefault();
            $('#myLocationsSection').toggle();
            // Tabelle initialisieren, falls noch nicht geladen
            window.webrtcApp.locationsTable.bindEvents({
                onlyOwn: true,
                showActions: ["edit", "delete"],
                tableSelector: "#locationsTable"
            });
        });
    }

    $('#editDescForm').off('submit').on('submit', function(e) {
        e.preventDefault();

        const locationId = $('#editLocationId').val();
        const newDesc = $('#newDescription').val().trim();

        if (!newDesc) {
            alert('Bitte eine neue Beschreibung eingeben!');
            return;
        }

        $.ajax({
            url: 'index.php?act=edit_location_desc',
            method: 'POST',
            data: {
                id: locationId,
                description: newDesc
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#editDescModal').modal('hide');
                    window.webrtcApp.locationsTable.loadLocationsTable({
                        onlyOwn: true,
                        showActions: ["edit", "delete"],
                        tableSelector: "#locationsTable"
                    });
                } else {
                    alert('Fehler: ' + (response.error || 'Unbekannter Fehler'));
                }
            },
            error: function() {
                alert('Fehler beim Ändern!');
            }
        });
    });

});
