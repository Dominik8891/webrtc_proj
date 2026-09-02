
/**
 * Modul für die Verwaltung und Anzeige der Locations-Tabelle.
 * Beinhaltet das dynamische Laden, die Anzeige auf Karte und die Bearbeitungs-/Löschfunktionen.
 */
window.webrtcApp.locationsTable = {

    /**
     * Takt der Statusaktualisierung in der Standortuebersicht.
     *
     * Etwas laenger als der Heartbeat (config/presence.php, 10 s), damit die
     * Uebersicht nicht schneller fragt, als sich die Daten ueberhaupt aendern
     * koennen.
     */
    STATUS_REFRESH_MS: 15000,

    /** Laufende Aktualisierungs-Timer, je Tabellen-Selektor einer. */
    refreshTimers: {},

    /** Optionen der zuletzt geladenen Tabelle, je Tabellen-Selektor. */
    refreshOptions: {},

    /** Ist der Listener fuer den Tabwechsel schon gesetzt? */
    visibilityHooked: false,

    /**
     * Icon und Text zu einem user_status.
     * @param {string} status - Wert aus der Spalte user.user_status
     * @returns {{icon: string, text: string, callable: boolean}}
     */
    statusView(status) {
        if (status === "in_call") {
            return {
                icon: '<span class="badge rounded-pill bg-warning text-dark fs-4">&#x1F7E0;</span>',
                text: "Befindet sich in Call",
                callable: false
            };
        }
        if (status === "online") {
            return { icon: "\u{1F7E2}", text: "Online", callable: true };
        }
        return { icon: "\u{1F534}", text: "Offline", callable: false };
    },

    /**
     * Baut den Call-Button einer Zeile. Nur ein erreichbarer Nutzer ist anrufbar.
     * @param {Object} item - Datensatz aus der API
     * @returns {string} HTML
     */
    callButtonHtml(item) {
        const view = this.statusView(item.user_status);
        return `
            <button type="button"
                class="btn btn-success btn-sm start-call-btn"
                data-userid="${item.user_id}"
                ${view.callable ? "" : "disabled aria-disabled='true'"}
                style="${view.callable ? "" : "pointer-events:none;opacity:0.5;"}"
            >
                Call
            </button>
        `;
    },

    /**
     * Baut die Aktionsspalte einer Zeile.
     * @param {Object} item
     * @param {Object} options
     * @returns {string} HTML
     */
    actionCellHtml(item, options) {
        let actionBtns = '';
        if (options.showActions.includes("call")) {
            actionBtns += this.callButtonHtml(item);
        }
        if (options.showActions.includes("edit")) {
            actionBtns += `
                <button type="button" class="btn btn-warning btn-sm edit-location-btn" data-locationid="${item.id}">Ändern</button>
            `;
        }
        if (options.showActions.includes("delete")) {
            actionBtns += `
                <button class="btn btn-danger delete-location-btn" data-locationid="${item.id}">Löschen</button>
            `;
        }
        // Moderation: sperren statt löschen. Der Knopf erscheint nur, wenn der
        // Server das Recht location.block mitgeschickt hat (window.userCan).
        // Das ist reine Anzeige - entschieden wird die Berechtigung erneut in
        // index.php, wenn die Route wirklich aufgerufen wird.
        if (options.showActions.includes("block")) {
            actionBtns += item.blocked == 1
                ? `<button class="btn btn-secondary btn-sm unblock-location-btn" data-locationid="${item.id}">Freigeben</button>`
                : `<button class="btn btn-outline-danger btn-sm block-location-btn" data-locationid="${item.id}">Sperren</button>`;
        }
        return actionBtns;
    },

    /**
     * Hinweis auf eine bestehende Sperre.
     *
     * Wird an die Beschreibung gehaengt, nicht in eine eigene Spalte: Die
     * Spaltennummern in statusColumns() haengen an der Tabellenform, eine
     * zusaetzliche Spalte muesste dort mitgepflegt werden.
     *
     * @param {Object} item
     * @returns {string} HTML oder Leerstring
     */
    blockedNoticeHtml(item) {
        if (item.blocked != 1) return '';
        const reason = item.blocked_reason ? String(item.blocked_reason) : '';
        const escaped = $('<div>').text(reason).html();
        return `
            <div class="mt-1">
                <span class="badge bg-danger">Gesperrt</span>
                ${escaped ? `<span class="small text-danger ms-1">${escaped}</span>` : ''}
            </div>
        `;
    },

    /**
     * Baut eine komplette Tabellenzeile.
     *
     * Die Zeile traegt ihre Location-ID und den zuletzt angezeigten Status als
     * Attribut. Beides braucht die Aktualisierung, um Zeilen wiederzufinden,
     * ohne die Tabelle neu aufzubauen.
     *
     * @param {Object} item
     * @param {number} index - laufende Nummer fuer die erste Spalte
     * @param {Object} options
     * @returns {string} HTML einer <tr>
     */
    rowHtml(item, index, options) {
        const view = this.statusView(item.user_status);

        // Beschreibung als klickbaren Text (für Popup/Modal)
        const descHtml = `
            <span 
                class="desc-hover fw-semibold text-primary text-decoration-underline"
                data-lat="${item.latitude}" 
                data-lng="${item.longitude}" 
                data-country="${item.country_name ?? ''}" 
                data-city="${item.city_name ?? ''}" 
                style="cursor:pointer;">
                ${item.description}
            </span>
            ${this.blockedNoticeHtml(item)}
        `;

        return `<tr data-locationid="${item.id}" data-status="${item.user_status ?? ''}">
            <td>${index + 1}</td>
            <td>${view.text}</td>
            ${options.onlyOwn ? "" : `<td>${view.icon} ${item.username}</td>`}
            <td>${item.country_name ?? ''}</td>
            <td>${item.city_name ?? ''}</td>
            <td>${descHtml}</td>
            <td>${this.actionCellHtml(item, options)}</td>
        </tr>`;
    },

    /**
     * Spaltennummern der Zellen, die sich mit dem Status aendern.
     * Die Uebersicht hat eine Spalte mehr ("User") als die Liste der eigenen
     * Standorte, deshalb haengen die Nummern an den Optionen.
     *
     * @param {Object} options
     * @returns {{status: number, user: number|null, actions: number}}
     */
    statusColumns(options) {
        return {
            status : 1,
            user   : options.onlyOwn ? null : 2,
            actions: options.onlyOwn ? 5 : 6
        };
    },

    /**
     * Vollständiger Api-Url zu den gewuenschten Datensaetzen.
     * @param {Object} options
     * @returns {string}
     */
    apiUrl(options) {
        return options.onlyOwn ? 'index.php?act=get_my_locations' : 'index.php?act=get_locations';
    },

    /**
     * Ergaenzt fehlende Optionen um die Vorgabewerte.
     * @param {Object} options
     * @returns {Object}
     */
    withDefaults(options) {
        return Object.assign({
            onlyOwn: false,                               // Nur eigene Locations laden?
            showActions: ["call"],                        // Mögliche Aktionen: ["call", "edit", "delete"]
            tableSelector: "#locationsTable",             // Wo soll die Tabelle befüllt werden?
            autoRefresh: null                             // null = automatisch (nur in der Uebersicht)
        }, options || {});
    },

    /**
     * Lädt Locations aus dem Backend und baut die Tabelle dynamisch auf.
     * @param {Object} options - Einstellungen, z.B. nur eigene Locations, welche Aktionen erlaubt sind etc.
     */
    loadLocationsTable(options) {
        options = this.withDefaults(options);
        const self = this;
        const $table = $(options.tableSelector);

        $.ajax({
            url: this.apiUrl(options),
            method: 'GET',
            dataType: 'json',
            success: function (data) {
                self.renderRows($table, data, options);
                self.startAutoRefresh(options);
            },
            error: function () {
                // Steht schon eine Tabelle, bleibt der zuletzt bekannte Stand
                // stehen - eine Fehlerzeile direkt ins tbody wuerde DataTables
                // aus dem Tritt bringen.
                if (!$.fn.DataTable.isDataTable($table)) {
                    $table.find('tbody').html('<tr><td colspan="7">Fehler beim Laden der Daten.</td></tr>');
                }
            }
        });
    },

    /**
     * Setzt den kompletten Zeilenbestand einer Tabelle.
     *
     * Beim ersten Aufruf wird DataTables initialisiert. Danach werden die
     * Zeilen ueber die DataTables-API ausgetauscht statt die Instanz zu
     * zerstoeren: Sortierung, Suche, Seitenlaenge und aktuelle Seite des
     * Nutzers bleiben so erhalten (`draw(false)` haelt die Seite).
     *
     * @param {jQuery} $table
     * @param {Array} data - Datensaetze aus der API
     * @param {Object} options
     */
    renderRows($table, data, options) {
        let rows = '';
        data.forEach((item, i) => {
            rows += this.rowHtml(item, i, options);
        });

        if ($.fn.DataTable.isDataTable($table)) {
            const dt = $table.DataTable();

            // Sicherheitsnetz: Die Uebersicht hat eine Spalte mehr als die
            // Liste der eigenen Standorte. Wechselt eine Seite zwischen
            // beiden Varianten, passen die neuen Zeilen nicht in die
            // bestehende Instanz - dann hilft nur ein Neuaufbau.
            const newCellCount = rows ? $(rows).first().children('td').length : dt.columns().count();
            if (newCellCount === dt.columns().count()) {
                dt.clear();
                if (rows) dt.rows.add($(rows));
                dt.draw(false);
                return;
            }
            dt.destroy();
        }

        $table.find('tbody').html(rows);
        $table.DataTable({
            responsive: true
        });
    },

    /**
     * Holt den aktuellen Verfuegbarkeitsstatus und schreibt ihn in die
     * bestehende Tabelle.
     *
     * Angefasst werden nur die Zellen, die sich tatsaechlich geaendert haben -
     * Statustext, Icon und der Call-Button. Die Tabelle wird dafuer nicht neu
     * aufgebaut, sondern ueber die DataTables-API veraendert und mit
     * `draw(false)` neu gezeichnet. Sortierung, Suchbegriff und Seite des
     * Nutzers bleiben dadurch stehen.
     *
     * Sind Standorte dazugekommen oder weggefallen, wird der Zeilenbestand
     * komplett ersetzt - ebenfalls ueber die API, also ebenfalls ohne die
     * Sortierung zu verlieren.
     *
     * @param {Object} options
     */
    refreshStatuses(options) {
        options = this.withDefaults(options);
        const $table = $(options.tableSelector);
        if (!$table.length || !$.fn.DataTable.isDataTable($table)) return;

        const self = this;
        $.ajax({
            url: this.apiUrl(options),
            method: 'GET',
            dataType: 'json',
            success: function (data) {
                const dt = $table.DataTable();
                const cols = self.statusColumns(options);

                // Datensaetze nach Location-ID greifbar machen.
                const byId = {};
                data.forEach(item => { byId[String(item.id)] = item; });

                // Stimmt der Zeilenbestand nicht mehr, hilft nur der
                // vollstaendige Austausch.
                let known = 0;
                let sameSet = true;
                dt.rows().every(function () {
                    const id = this.node().getAttribute('data-locationid');
                    if (!Object.prototype.hasOwnProperty.call(byId, String(id))) {
                        sameSet = false;
                        return;
                    }
                    known++;
                });
                if (!sameSet || known !== data.length) {
                    self.renderRows($table, data, options);
                    return;
                }

                // Gleicher Bestand: nur die Statuszellen nachziehen.
                let changed = false;
                dt.rows().every(function () {
                    const node = this.node();
                    const item = byId[String(node.getAttribute('data-locationid'))];
                    const status = item.user_status ?? '';
                    if (node.getAttribute('data-status') === status) return;

                    node.setAttribute('data-status', status);
                    const view = self.statusView(item.user_status);

                    dt.cell(node, cols.status).data(view.text);
                    if (cols.user !== null) {
                        dt.cell(node, cols.user).data(`${view.icon} ${item.username}`);
                    }
                    if (options.showActions.includes("call")) {
                        dt.cell(node, cols.actions).data(self.actionCellHtml(item, options));
                    }
                    changed = true;
                });

                if (changed) dt.draw(false);
            }
            // Kein error-Handler: Ein fehlgeschlagener Zwischenabruf laesst den
            // zuletzt bekannten Stand stehen, der naechste Takt versucht es
            // erneut.
        });
    },

    /**
     * Startet den Aktualisierungstakt fuer eine Tabelle.
     *
     * Je Tabelle laeuft hoechstens ein Timer; ein erneuter Aufruf ersetzt den
     * vorherigen. Im ausgeblendeten Tab wird nichts abgefragt - beim
     * Zurueckkehren dafuer sofort.
     *
     * @param {Object} options
     */
    startAutoRefresh(options) {
        options = this.withDefaults(options);

        // Vorgabe: Die Uebersicht fremder Standorte aktualisiert sich, die
        // Liste der eigenen Standorte nicht - dort steht ohnehin nur der
        // eigene Status.
        const wanted = options.autoRefresh === null ? !options.onlyOwn : options.autoRefresh;

        const key = options.tableSelector;
        if (this.refreshTimers[key]) {
            clearInterval(this.refreshTimers[key]);
            delete this.refreshTimers[key];
        }
        this.refreshOptions[key] = options;
        if (!wanted) return;

        const self = this;
        this.refreshTimers[key] = setInterval(function () {
            if (document.hidden) return;
            self.refreshStatuses(self.refreshOptions[key]);
        }, this.STATUS_REFRESH_MS);

        if (!this.visibilityHooked) {
            this.visibilityHooked = true;
            document.addEventListener('visibilitychange', function () {
                if (document.hidden) return;
                Object.keys(self.refreshTimers).forEach(function (selector) {
                    self.refreshStatuses(self.refreshOptions[selector]);
                });
            });
        }
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

        // Sperren (Moderation). Der Grund ist Pflicht - der Guide bekommt
        // genau diesen Text in seiner Standortliste angezeigt.
        $(options.tableSelector)
            .off('click', '.block-location-btn')
            .on('click', '.block-location-btn', function() {
                const locationId = $(this).data('locationid');
                if (!locationId) return;

                const reason = prompt("Warum soll dieser Standort gesperrt werden?\nDer Guide bekommt diesen Text zu sehen.");
                if (reason === null) return;              // abgebrochen
                if (!reason.trim()) {
                    alert("Bitte einen Grund angeben.");
                    return;
                }

                window.webrtcApp.locationsTable.moderate('index.php?act=block_location', {
                    id: locationId,
                    reason: reason.trim()
                }, options);
            });

        // Freigeben
        $(options.tableSelector)
            .off('click', '.unblock-location-btn')
            .on('click', '.unblock-location-btn', function() {
                const locationId = $(this).data('locationid');
                if (!locationId) return;
                if (!confirm("Sperre für diesen Standort aufheben?")) return;

                window.webrtcApp.locationsTable.moderate('index.php?act=unblock_location', {
                    id: locationId
                }, options);
            });
    },

    /**
     * Schickt eine Moderationsanfrage und laedt die Tabelle danach neu.
     *
     * @param {string} url - Ziel-Route
     * @param {Object} data - Nutzdaten
     * @param {Object} options - Optionen der aufrufenden Tabelle
     */
    moderate(url, data, options) {
        const self = this;
        $.ajax({
            url: url,
            method: 'POST',
            data: data,
            dataType: 'json',
            success: function(response) {
                if (response && response.success) {
                    self.loadLocationsTable(options);
                } else {
                    alert('Fehler: ' + ((response && response.error) || 'Unbekannter Fehler'));
                }
            },
            error: function(xhr) {
                alert(xhr.status === 403
                    ? 'Dafür fehlt Ihnen die Berechtigung.'
                    : 'Die Aktion ist fehlgeschlagen.');
            }
        });
    },
};

// Initialisierung bei DOM-Ready
$(document).ready(function () {
    // Globale Tabelle auf der Übersichtsseite
    //
    // Der vorher hier stehende zusaetzliche Aufruf ohne Optionen ist entfallen:
    // Er lud dieselbe Tabelle ein zweites Mal und griff auf settings.html mit
    // den Optionen der Uebersicht (eine Spalte mehr) auf die Liste der eigenen
    // Standorte zu.
    if($('#locationsTable').length && !$('#myLocationsSection').length) {
        // Wer moderieren darf, bekommt zusaetzlich Sperren/Freigeben. Die
        // Angabe kommt vom Server (ViewHelper::output) und steuert nur die
        // Anzeige; die Routen pruefen das Recht selbst.
        const actions = (window.userCan && window.userCan.blockLocation)
            ? ["call", "block"]
            : ["call"];
        window.webrtcApp.locationsTable.bindEvents({
            onlyOwn: false,
            showActions: actions,
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
