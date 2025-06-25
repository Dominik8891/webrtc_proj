
/**
 * Modul für die Verwaltung und Anzeige der Locations-Tabelle.
 * Beinhaltet das dynamische Laden, die Anzeige auf Karte und die Bearbeitungs-/Löschfunktionen.
 */
window.webrtcApp.locationsTable = {
    /**
     * Lädt Locations aus dem Backend und baut die Tabelle dynamisch auf.
     * @param {Object} options - Einstellungen, z.B. nur eigene Locations, welche Aktionen erlaubt sind etc.
     */
    loadLocationsTable(options) {
        // Standardoptionen setzen & mit übergebenen überschreiben
        options = Object.assign({
            onlyOwn: false,                               // Nur eigene Locations laden?
            showActions: ["call"],                        // Mögliche Aktionen: ["call", "edit", "delete"]
            tableSelector: "#locationsTable"              // Wo soll die Tabelle befüllt werden?
        }, options || {});

        // Richtige API-URL wählen
        let apiUrl = options.onlyOwn ? 'index.php?act=get_my_locations' : 'index.php?act=get_locations';
        const $table = $(options.tableSelector);

        $.ajax({
            url: apiUrl,
            method: 'GET',
            dataType: 'json',
            success: function (data) {
                let rows = '';
                // Für jeden Datensatz eine Tabellenzeile erzeugen
                data.forEach(function (item, i) {
                    // Status-Icon & -Text je nach user_status bestimmen
                    let icon = "🔴";
                    let status = "Offline";
                    if (item.user_status === "in_call") {
                        icon = '<span class="badge rounded-pill bg-warning text-dark fs-4">&#x1F7E0;</span>';
                        status = "Befindet sich in Call";
                    } else if (item.user_status === "online") {
                        icon = "🟢";
                        status = "Online";
                    }

                    // Beschreibung als klickbaren Text (für Popup/Modal)
                    let descHtml = `
                        <span 
                            class="desc-hover fw-semibold text-primary text-decoration-underline"
                            data-lat="${item.latitude}" 
                            data-lng="${item.longitude}" 
                            data-country="${item.country_name ?? ''}" 
                            data-city="${item.city_name ?? ''}" 
                            style="cursor:pointer;">
                            ${item.description}
                        </span>
                    `;

                    // Aktions-Buttons je nach Option
                    let actionBtns = '';
                    if(options.showActions.includes("call")) {
                        actionBtns +=  `
                            <button type="button"
                                class="btn btn-success btn-sm start-call-btn"
                                data-userid="${item.user_id}"
                                ${item.user_status !== "online" ? "disabled aria-disabled='true'" : ""}
                                style="${item.user_status !== "online" ? "pointer-events:none;opacity:0.5;" : ""}"
                            >
                                Call
                            </button>
                        `;
                    }
                    if(options.showActions.includes("edit")) {
                        actionBtns += `
                            <button type="button" class="btn btn-warning btn-sm edit-location-btn" data-locationid="${item.id}">Ändern</button>
                        `;
                    }
                    if(options.showActions.includes("delete")) {
                        actionBtns += `
                            <button class="btn btn-danger delete-location-btn" data-locationid="${item.id}">Löschen</button>
                        `;
                    }

                    // Zusammenbauen der Tabellenzeile
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

                // Vor Initialisierung der DataTable immer eine evtl. bestehende Instanz zerstören!
                if ($.fn.DataTable.isDataTable($table)) {
                    $table.DataTable().destroy();
                }

                // Neue Zeilen in das <tbody> einsetzen
                $table.find('tbody').html(rows);

                // DataTable mit Responsive-Plugin neu initialisieren
                $table.DataTable({
                    responsive: true
                });
            },
            error: function () {
                // Fehlerausgabe in der Tabelle anzeigen
                $table.find('tbody').html('<tr><td colspan="7">Fehler beim Laden der Daten.</td></tr>');
            }
        });
    },

    /**
     * Zeigt einen kleinen Kartenausschnitt bei Hover über die Beschreibung an.
     */
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

    /**
     * Blendet das kleine Karten-Popup aus und entfernt Marker.
     */
    hideMapPopup() {
        $('#descMapPopup').hide();
        if(this.map) this.map.eachLayer(function (layer) {
            if(layer instanceof L.Marker) this.map.removeLayer(layer);
        }.bind(this));
    },

    /**
     * Zeigt eine große Karte im Modal an.
     */
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
            // Alle Marker entfernen
            this.modalMap.eachLayer(function (layer) {
                if(layer instanceof L.Marker) this.modalMap.removeLayer(layer);
            }.bind(this));
            L.marker([lat, lng]).addTo(this.modalMap).bindPopup(description).openPopup();
        }, 200);
    },

    map: null,                  // Leaflet-Map-Objekt für Popup
    modalMap: null,             // Leaflet-Map-Objekt für Modal
    mapInitialized: false,      // Flag: ist Popup-Map initialisiert?
    modalMapInitialized: false, // Flag: ist Modal-Map initialisiert?

    /**
     * Bindet sämtliche Events auf die Tabelle und initialisiert DataTables.
     * @param {Object} options
     */
    bindEvents(options = {onlyOwn: false, tableSelector: "#locationsTable"}) {
        if (!$(options.tableSelector).length) return;
                
        // Direkt laden
        this.loadLocationsTable(options);

        // Map Events (Mouseover, Klick etc.)
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
        // Call-Button-Click
        $(options.tableSelector).on('click', '.start-call-btn', function() {
            const userId = $(this).data('userid');
            if(typeof window.webrtcApp?.rtc?.startCall === 'function') {
                window.webrtcApp.rtc.startCall(userId);
                setTimeout(updateCallIcons(), 1000);
            } else {
                alert("Call-Funktion nicht verfügbar.");
            }
        });
        // Edit-Button für eigene Locations
        $(options.tableSelector)
        .off('click', '.edit-location-btn')
        .on('click', '.edit-location-btn', function() {
            const locationId = $(this).data('locationid');
            const $row = $(this).closest('tr');
            const currentDescription = $row.find('.desc-hover').text().trim();

            $('#editLocationId').val(locationId);
            $('#currentDescription').val(currentDescription);
            $('#newDescription').val('');
            $('#editDescModal').modal('show');
        });
        // Delete-Button für eigene Locations
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

// Initialisierung bei DOM-Ready
$(document).ready(function () {
    // Standard-Tabelle initialisieren
    window.webrtcApp.locationsTable.bindEvents();

    // Globale Tabelle auf der Übersichtsseite
    if($('#locationsTable').length && !$('#myLocationsSection').length) {
        window.webrtcApp.locationsTable.bindEvents({
            onlyOwn: false,
            showActions: ["call"],
            tableSelector: "#locationsTable"
        });
    }

    // Eigene Locations-Tabelle auf der settings.html
    if($('#myLocationsSection').length) {
        $('#showOwnLocationsBtn').show().on('click', function(e) {
            e.preventDefault();
            $('#myLocationsSection').toggle();
            window.webrtcApp.locationsTable.bindEvents({
                onlyOwn: true,
                showActions: ["edit", "delete"],
                tableSelector: "#locationsTable"
            });
        });
    }

    // Edit-Formular absenden
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
