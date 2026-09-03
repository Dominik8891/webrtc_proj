
/**
 * Modul für die Verwaltung und Anzeige der Locations-Tabelle.
 * Beinhaltet das dynamische Laden, die Anzeige auf Karte und die Bearbeitungs-/Löschfunktionen.
 */
window.webrtcApp.locationsTable = {

    /**
     * Die beiden Standort-Tabellen der Anwendung.
     *
     * Jede hat eine EIGENE id. Vorher hiessen beide `locationsTable`, und weil
     * die Uebersicht eine Spalte mehr hat ("User") als die Liste der eigenen
     * Standorte, landeten die siebenspaltigen Zeilen der Uebersicht in der
     * sechsspaltigen Tabelle der Einstellungsseite - DataTables brach mit
     * "Incorrect column count" ab. Verhindert wurde das zuletzt nur noch durch
     * eine Abfrage auf `#myLocationsSection` im Initialisierungsblock; das war
     * eine Umgehung der doppelten id, keine Loesung.
     *
     * Ausserdem ist das hier die EINZIGE Stelle, an der steht, wie eine der
     * beiden Tabellen zu laden ist. Die Angaben standen frueher dreimal im
     * Code (Initialisierung, Loeschen-Handler, Beschreibung-aendern-Formular)
     * und mussten von Hand gleichgehalten werden.
     */
    TABLES: {
        // Alle fremden Standorte, Route show_locations_page
        overview: {
            selector   : '#locationsTable',
            onlyOwn    : false,
            showActions: ['call']
        },
        // Die eigenen Standorte auf der Einstellungsseite
        own: {
            selector   : '#myLocationsTable',
            onlyOwn    : true,
            showActions: ['edit', 'delete']
        }
    },

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

    /** Tabellen, deren Klick- und Maus-Handler schon haengen. */
    boundTables: {},

    /** Ist der Listener fuer den Tabwechsel schon gesetzt? */
    visibilityHooked: false,

    /**
     * Anzeige und Anrufbarkeit zu einem user_status.
     *
     * KEINE EMOJI UND KEINE KARTENFARBEN MEHR.
     *
     * Hier standen 🟢, 🟠 und 🔴 - dieselben drei Farben, die auf der Karte
     * "Guide verfuegbar", "im Gespraech" und "kein Guide vor Ort" bedeuten.
     * In einer Tabelle daneben trugen sie dieselbe Farbe fuer etwas anderes.
     * Ausserdem sieht ein Emoji auf jedem System anders aus und laesst sich
     * nicht gestalten.
     *
     * Unterschieden wird jetzt ueber Form und Gewicht - gefuellt, geringelt,
     * hohl - genau wie in der Benutzerliste (assets/css/theme.css,
     * .app-state).
     *
     * @param {string} status - Wert aus der Spalte user.user_status
     * @returns {{icon: string, text: string, callable: boolean}}
     */
    statusView(status) {
        if (status === "in_call") {
            return { icon: this.stateHtml('busy', 'Im Gespräch'),
                     text: "Im Gespräch", callable: false };
        }
        if (status === "online") {
            return { icon: this.stateHtml('online', 'Online'),
                     text: "Online", callable: true };
        }
        return { icon: this.stateHtml('offline', 'Offline'),
                 text: "Offline", callable: false };
    },

    /**
     * Baut die Zustandsanzeige.
     *
     * @param {string} art  'online', 'busy' oder 'offline'
     * @param {string} text Beschriftung
     * @returns {string} HTML
     */
    stateHtml(art, text) {
        return '<span class="app-state app-state--' + art + '" title="' + this.esc(text) + '">'
             + '<span class="app-state__dot" aria-hidden="true"></span>'
             + '<span class="app-state__text">' + this.esc(text) + '</span>'
             + '</span>';
    },

    /**
     * Maskiert Text fuer die Ausgabe in HTML.
     *
     * @param {*} wert
     * @returns {string}
     */
    esc(wert) {
        return String(wert ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    },

    /**
     * Baut den Call-Button einer Zeile. Nur ein erreichbarer Nutzer ist anrufbar.
     * @param {Object} item - Datensatz aus der API
     * @returns {string} HTML
     */
    callButtonHtml(item) {
        const view = this.statusView(item.user_status);
        // Der Akzent statt Gruen, und gesperrt in Grau: Gruen heisst auf der
        // Karte "Guide gerade verfuegbar". Die frueheren Inline-Styles fuer
        // den gesperrten Zustand entfallen - das macht .btn:disabled selbst.
        return `
            <button type="button"
                class="btn btn-sm start-call-btn ${view.callable ? 'btn-primary' : 'btn-secondary'}"
                data-userid="${this.esc(item.user_id)}"
                ${view.callable ? "" : "disabled aria-disabled='true'"}
            >Anrufen</button>
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

        // Die Hauptaktion behaelt Text und Flaeche. Sie sagt, worum es in der
        // Liste ueberhaupt geht - als Symbol waere die Tabelle eine Reihe
        // gleich lauter Zeichen ohne Schwerpunkt.
        if (options.showActions.includes("call")) {
            actionBtns += this.callButtonHtml(item);
        }

        // Alles Weitere sind Nebenaktionen: Symbol ohne Rahmen, Rahmen und
        // Flaeche erst beim Ueberfahren. Der Ort steht jeweils im aria-label,
        // damit ein Vorleseprogramm nicht zwanzigmal "Bearbeiten" ohne Bezug
        // meldet.
        const ort = this.ortLabel(item);

        if (options.showActions.includes("edit")) {
            actionBtns += this.iconBtn({
                klasse: 'edit-location-btn',
                symbol: 'edit',
                titel:  'Bearbeiten',
                label:  'Standort ' + ort + ' bearbeiten',
                id:     item.id
            });
        }
        if (options.showActions.includes("delete")) {
            actionBtns += this.iconBtn({
                klasse: 'delete-location-btn',
                symbol: 'delete',
                titel:  'Löschen',
                label:  'Standort ' + ort + ' löschen',
                id:     item.id,
                warnend: true
            });
        }
        // Moderation: sperren statt löschen. Der Knopf erscheint nur, wenn der
        // Server das Recht location.block mitgeschickt hat (window.userCan).
        // Das ist reine Anzeige - entschieden wird die Berechtigung erneut in
        // index.php, wenn die Route wirklich aufgerufen wird.
        if (options.showActions.includes("block")) {
            actionBtns += (item.blocked == 1)
                ? this.iconBtn({
                    klasse: 'unblock-location-btn',
                    symbol: 'unblock',
                    titel:  'Freigeben',
                    label:  'Standort ' + ort + ' freigeben',
                    id:     item.id
                })
                : this.iconBtn({
                    klasse: 'block-location-btn',
                    symbol: 'block',
                    titel:  'Sperren',
                    label:  'Standort ' + ort + ' sperren',
                    id:     item.id,
                    warnend: true
                });
        }

        // Die Symbole in einen Behaelter, damit sie als eine Gruppe stehen und
        // nicht als drei einzelne Zeichen zerfliessen.
        return actionBtns
            ? `<div class="app-actions-cell">${actionBtns}</div>`
            : '';
    },

    /**
     * Kurzbezeichnung eines Standorts fuer Vorleseprogramme.
     *
     * @param {Object} item
     * @returns {string}
     */
    ortLabel(item) {
        return [item.city_name, item.country_name].filter(Boolean).join(', ')
            || ('#' + (item.id ?? ''));
    },

    /**
     * Baut einen Symbolknopf.
     *
     * Das Symbol selbst steht als Maske in assets/css/theme.css - hier wird
     * nur die Klasse gesetzt. aria-label und title sind nicht optional: Ohne
     * sie ist ein Knopf ohne Text weder vorlesbar noch erratbar.
     *
     * @param {Object} o { klasse, symbol, titel, label, id, warnend }
     * @returns {string} HTML
     */
    iconBtn(o) {
        const warn = o.warnend ? ' app-iconbtn--danger' : '';
        return `<button type="button"`
             + ` class="app-iconbtn app-iconbtn--${o.symbol}${warn} ${o.klasse}"`
             + ` data-locationid="${this.esc(o.id)}"`
             + ` aria-label="${this.esc(o.label)}"`
             + ` title="${this.esc(o.titel)}"></button>`;
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
        const grund = this.esc(item.blocked_reason || '');
        // Eine Marke im Erscheinungsbild der Anwendung statt eines roten
        // Bootstrap-Badges (assets/css/theme.css, .app-tag).
        return `
            <div class="app-locked">
                <span class="app-tag app-tag--danger">Gesperrt</span>
                ${grund ? `<span class="app-locked__reason">${grund}</span>` : ''}
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
        // Die Beschreibung stammt von einem anderen Nutzer und ging hier
        // frueher unmaskiert ins Dokument.
        const descHtml = `
            <span class="desc-hover app-linklike"
                data-lat="${this.esc(item.latitude)}"
                data-lng="${this.esc(item.longitude)}"
                data-country="${this.esc(item.country_name ?? '')}"
                data-city="${this.esc(item.city_name ?? '')}">${this.esc(item.description)}</span>
            ${this.blockedNoticeHtml(item)}
        `;

        return `<tr data-locationid="${item.id}" data-status="${item.user_status ?? ''}">
            <td>${index + 1}</td>
            <td>${view.icon}</td>
            ${options.onlyOwn ? "" : `<td>${this.esc(item.username ?? '')}</td>`}
            <td>${this.esc(item.country_name ?? '')}</td>
            <td>${this.esc(item.city_name ?? '')}</td>
            <td>${descHtml}</td>
            <td>${this.actionCellHtml(item, options)}</td>
        </tr>`;
    },

    /**
     * Die Spaltenfolge einer Tabelle - die einzige Stelle, an der sie steht.
     *
     * Frueher war die Spaltenzahl an drei Orten gleichzeitig hinterlegt: im
     * <thead> des Templates, in rowHtml() und noch einmal als feste
     * Zellennummern in statusColumns(). Liefen die auseinander, meldete
     * DataTables nur "Incorrect column count", ohne zu sagen, wo.
     *
     * Die Reihenfolge muss zum <thead> der jeweiligen Tabelle passen;
     * ensureColumnsMatch() prueft das vor der Initialisierung.
     *
     * @param {Object} options
     * @returns {string[]}
     */
    columnKeys(options) {
        return options.onlyOwn
            ? ['nr', 'status',         'country', 'city', 'description', 'actions']
            : ['nr', 'status', 'user', 'country', 'city', 'description', 'actions'];
    },

    /**
     * Spaltennummern der Zellen, die sich mit dem Status aendern.
     * Abgeleitet aus columnKeys(), damit sie nicht getrennt gepflegt werden.
     *
     * @param {Object} options
     * @returns {{status: number, user: number|null, actions: number}}
     */
    statusColumns(options) {
        const keys = this.columnKeys(options);
        const user = keys.indexOf('user');
        return {
            status : keys.indexOf('status'),
            user   : user === -1 ? null : user,
            actions: keys.indexOf('actions')
        };
    },

    /**
     * Prueft, ob das Template so viele Spalten hat, wie die Zeilen liefern.
     *
     * Stimmt es nicht, wird gar nicht erst initialisiert: DataTables wuerde
     * sonst mit "Incorrect column count" abbrechen und dabei weder die
     * Tabelle noch die erwartete Spaltenzahl nennen. Statt dessen eine
     * eindeutige Meldung in der Konsole und ein Hinweis in der Tabelle.
     *
     * @param {jQuery} $table
     * @param {Object} options
     * @returns {boolean} true, wenn geladen werden darf
     */
    ensureColumnsMatch($table, options) {
        const erwartet = this.columnKeys(options).length;
        const vorhanden = $table.find('thead tr').first().children('th').length;
        if (erwartet === vorhanden) return true;

        console.error(
            `Standort-Tabelle ${options.tableSelector}: Das Template hat ${vorhanden} Spalten, `
            + `die Zeilen liefern ${erwartet} (onlyOwn=${options.onlyOwn}). `
            + 'Entweder passt der <thead> nicht zur Tabelle oder die Tabelle wird mit '
            + 'den Einstellungen der anderen geladen.'
        );
        $table.find('tbody').html(
            `<tr><td colspan="${vorhanden || 1}">Diese Tabelle ist falsch konfiguriert.</td></tr>`
        );
        return false;
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
        // Die Vorgaben sind die der Uebersicht - sie kommen aus TABLES und
        // nicht noch einmal als Literal hierher.
        return Object.assign({
            onlyOwn      : this.TABLES.overview.onlyOwn,
            showActions  : this.TABLES.overview.showActions,
            tableSelector: this.TABLES.overview.selector,
            autoRefresh  : null                           // null = automatisch (nur in der Uebersicht)
        }, options || {});
    },

    /**
     * Baut die Optionen einer der beiden Tabellen aus TABLES.
     *
     * @param {string} name - 'overview' oder 'own'
     * @param {Object} [extra] - zusaetzliche Einstellungen, z.B. showActions
     * @returns {Object}
     */
    optionsFor(name, extra) {
        const tabelle = this.TABLES[name];
        if (!tabelle) {
            console.error(`Unbekannte Standort-Tabelle: ${name}`);
            return this.withDefaults({});
        }
        return this.withDefaults(Object.assign({
            onlyOwn      : tabelle.onlyOwn,
            showActions  : tabelle.showActions.slice(),
            tableSelector: tabelle.selector
        }, extra || {}));
    },

    /**
     * Lädt Locations aus dem Backend und baut die Tabelle dynamisch auf.
     * @param {Object} options - Einstellungen, z.B. nur eigene Locations, welche Aktionen erlaubt sind etc.
     */
    loadLocationsTable(options) {
        options = this.withDefaults(options);
        const self = this;
        const $table = $(options.tableSelector);

        if (!$table.length) return;

        // Passt die Tabelle nicht zu den Einstellungen, wird gar nicht erst
        // geladen - der Abruf waere umsonst und die Anzeige ohnehin kaputt.
        if (!this.ensureColumnsMatch($table, options)) return;

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
        // Auch hier pruefen: renderRows wird aus refreshStatuses heraus direkt
        // aufgerufen, also nicht nur ueber loadLocationsTable.
        if (!this.ensureColumnsMatch($table, options)) return;

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
            const newCellCount = rows
                ? $(rows).first().children('td').length
                : this.columnKeys(options).length;
            if (newCellCount === dt.columns().count()) {
                dt.clear();
                if (rows) dt.rows.add($(rows));
                dt.draw(false);
                return;
            }
            dt.destroy();
        }

        $table.find('tbody').html(rows);
        $table.DataTable(this.dataTablesOptions(options));
        this.bindControlHeader($table);
    },

    /**
     * Haelt die Kopfzeile buendig zur Aufklappspalte.
     *
     * DAS PROBLEM
     * -----------
     * Die Responsive-Erweiterung fuegt fuer das Aufklappzeichen KEINE eigene
     * Zelle ein - Kopf- und Datenzeilen haben immer gleich viele Zellen.
     * Sie zeichnet das Zeichen als :before in die erste sichtbare
     * DATENZELLE und schafft ihm Platz, indem sie dieser Zelle
     * padding-left: 30px gibt (responsive.dataTables.css,
     * td.dtr-control). Die zugehoerige KOPFZELLE bekommt diese Angabe
     * nicht - sie behaelt ihre 10px.
     *
     * Gemessen war der Versatz genau 20px, und zwar nur, solange die
     * Tabelle eingeklappt ist. Bei voller Breite standen Kopf und Inhalt
     * buendig.
     *
     * DIE LOESUNG
     * -----------
     * Die Erweiterung markiert nur die Datenzelle mit der Klasse
     * dtr-control. Hier wird dieselbe Marke auf die zugehoerige Kopfzelle
     * uebertragen; die Gestaltung in assets/css/theme.css gibt beiden
     * denselben Abstand.
     *
     * WARUM NICHT IN CSS ALLEIN
     * -------------------------
     * Die Aufklappspalte ist immer die erste SICHTBARE Spalte, und welche
     * das ist, aendert sich mit der Fensterbreite (die Erweiterung blendet
     * Zellen mit display:none aus). Ein th:first-child traefe deshalb oft
     * eine ausgeblendete Zelle.
     *
     * @param {jQuery} $table
     */
    bindControlHeader($table) {
        // Erst im naechsten Bild angleichen, nicht sofort. Die Ereignisse
        // laufen teils schon, WAEHREND die Erweiterung die Spalten umstellt:
        // Beim Verkleinern auf 560px wanderte die Aufklappspalte von "#" auf
        // "Status", der Handler las aber noch den alten Stand und markierte
        // die falsche Kopfzelle. Sichtbar war das als Versatz, der genau bei
        // dieser einen Fensterbreite stehen blieb.
        const angleichen = () => {
            if (typeof window.requestAnimationFrame === 'function') {
                window.requestAnimationFrame(() => this.syncControlHeader($table));
            } else {
                this.syncControlHeader($table);
            }
        };

        // draw: nach jedem Neuzeichnen (Sortieren, Suchen, Blaettern).
        // responsive-resize: wenn sich die Zahl der sichtbaren Spalten
        // aendert - genau dann wandert die Aufklappspalte.
        $table
            .off('draw.dt.appControl responsive-resize.dt.appControl')
            .on('draw.dt.appControl responsive-resize.dt.appControl', angleichen);

        angleichen();
    },

    /**
     * Setzt die Marke der Aufklappspalte auf die passende Kopfzelle.
     *
     * @param {jQuery} $table
     */
    syncControlHeader($table) {
        const $koepfe = $table.find('thead th');
        $koepfe.removeClass('dtr-control');

        // Dieselbe Spalte wie in der Datenzeile: Die Erweiterung markiert
        // dort genau eine Zelle. Ueber ihre Stellung unter den Geschwistern
        // laesst sich die Kopfzelle finden - unabhaengig davon, welche
        // Spalten gerade ausgeblendet sind.
        const $steuerZelle = $table.find('tbody tr').first().children('td.dtr-control').first();
        if (!$steuerZelle.length) return;

        const spalte = $steuerZelle.index();
        $koepfe.eq(spalte).addClass('dtr-control');
    },

    /**
     * Gibt jeder Spalte eine Klasse aus ihrer Kennung.
     *
     * WOZU
     * ----
     * Die Responsive-Erweiterung entscheidet ueber das Einklappen anhand
     * einer Mindestbreite je Spalte. Diese Breite misst sie NICHT am Inhalt
     * der Zellen, sondern an der KOPFZELLE: Sie baut die Tabelle in einem
     * 1px breiten, unsichtbaren Behaelter noch einmal auf und nimmt die
     * Breite jedes <th> als Minimum (dataTables.responsive.js,
     * _columnsMinWidth).
     *
     * Fuer die meisten Spalten stimmt das ungefaehr - "Land" ist so breit
     * wie "Portugal". Fuer zwei Spalten nicht:
     *
     *   Beschreibung  Der Kopf ist 123px breit, ein brauchbarer Freitext
     *                 braucht mehr. Ohne Angabe quetscht die Erweiterung ihn
     *                 auf vier Zeilen, statt die Spalte einzuklappen.
     *   Aktionen      Der Kopf ist 92px breit, darin stehen aber der Knopf
     *                 "Anrufen" und bis zu drei Symbolknoepfe nebeneinander.
     *
     * Ueber die Klasse laesst sich in theme.css eine Mindestbreite setzen.
     * Die Erweiterung misst sie beim Nachbau mit und klappt dann ein, sobald
     * es wirklich eng wird - und nicht erst, wenn der Inhalt schon zerdrueckt
     * ist.
     *
     * @param {Object} options
     * @returns {Array} columnDefs fuer DataTables
     */
    columnDefs(options) {
        return this.columnKeys(this.withDefaults(options)).map((key, i) => ({
            targets: i,
            className: 'col-' + key,
            responsivePriority: this.COLUMN_PRIORITY[key] ?? 100
        }));
    },

    /**
     * In welcher Reihenfolge Spalten weichen, wenn es eng wird.
     *
     * Kleinere Zahl heisst: bleibt laenger stehen. Ohne diese Angabe raeumt
     * die Responsive-Erweiterung von RECHTS nach links ab - und rechts steht
     * die Aktionsspalte. Bei 800px Fensterbreite verschwand dadurch als
     * Erstes der Knopf "Anrufen", also genau das, wofuer die Liste da ist.
     *
     * Die Reihenfolge ergibt sich aus der Frage, was man in dieser Liste
     * tut: anrufen. Dafuer braucht man den Knopf, den Zustand (ist jemand
     * da?) und den Ort. Der Freitext ist das Erste, worauf man verzichten
     * kann - er steht nach dem Aufklappen weiterhin vollstaendig da.
     */
    COLUMN_PRIORITY: {
        actions:     1,   // der Anruf - bleibt am laengsten
        status:      2,   // ist gerade jemand da?
        city:        3,
        country:     4,
        user:        5,   // der Name des Guides
        nr:          6,
        description: 10   // weicht als Erstes
    },

    /**
     * Die Einstellungen fuer DataTables.
     *
     * WAS SICH DAMIT ERREICHEN LAESST
     * -------------------------------
     * - language: DataTables spricht ab Werk englisch ("Search:", "Show 10
     *   entries", "Showing 1 to 3 of 3 entries"). Die Texte lassen sich
     *   vollstaendig ersetzen, und nur so werden sie deutsch.
     * - dom: bestimmt, welche Bedienelemente in welcher Reihenfolge und in
     *   welchen Behaeltern stehen. Damit liegen Suchfeld und Laengenauswahl
     *   in einer eigenen Leiste ueber der Tabelle und Anzahl und Blaetterleiste
     *   in einer darunter - beide mit unseren Klassen, sodass sie sich in
     *   assets/css/theme.css gestalten lassen.
     *
     * WO DIE GRENZE LIEGT
     * -------------------
     * Das Markup INNERHALB der Bedienelemente gibt DataTables 1.13 fest vor:
     * das Suchfeld steckt in einem <label>, die Blaetterleiste besteht aus
     * <a>-Elementen mit eigenen Klassen. Beides laesst sich einfaerben und
     * ausrichten, aber nicht durch unsere Knopf- und Feldbauteile ersetzen -
     * dafuer braeuchte es eine andere Tabellenbibliothek oder eine eigene
     * Bedienung mit abgeschalteten DataTables-Elementen.
     *
     * @returns {Object}
     */
    dataTablesOptions(options) {
        return {
            responsive: true,

            // Jede Spalte bekommt ihre Kennung als Klasse. Damit steht in
            // assets/css/theme.css, wie schmal eine Spalte werden darf -
            // siehe columnDefs weiter unten und die Erklaerung dort.
            columnDefs: this.columnDefs(options),

            // WARUM autoWidth AUS IST
            // -----------------------
            // Mit der Vorgabe (true) misst DataTables die Tabelle EINMAL beim
            // Aufbau und schreibt das Ergebnis als feste Breite in das
            // style-Attribut - hier waren das 1202px. Diese Zahl bleibt
            // stehen, auch wenn das Fenster kleiner wird: Die Tabelle war bei
            // 400px Fensterbreite immer noch 1202px breit und ragte aus ihrem
            // Bereich heraus.
            //
            // Die Responsive-Erweiterung merkt davon nichts. Sie rechnet mit
            // einer ganz anderen Groesse - der Summe der KOPFZELLEN-Breiten
            // (hier 623px) gegen die Breite des Behaelters. Solange die
            // Kopfzeilen passen, klappt sie nichts ein, auch wenn die Tabelle
            // laengst 450px uebersteht. Genau das war der Fehler.
            //
            // Mit autoWidth: false schreibt DataTables keine feste Breite
            // mehr. Die Tabelle bleibt bei 100% ihres Behaelters, die Spalten
            // schrumpfen mit, und die Rechnung der Erweiterung stimmt wieder
            // mit dem ueberein, was man sieht.
            autoWidth: false,
            // l = Laengenauswahl, f = Suchfeld, t = Tabelle,
            // i = Anzahlangabe, p = Blaetterleiste
            dom: '<"app-dt-top"lf>t<"app-dt-bottom"ip>',
            language: {
                search: '',
                searchPlaceholder: 'Suchen',
                lengthMenu: '_MENU_ Einträge',
                info: '_START_–_END_ von _TOTAL_',
                infoEmpty: 'Keine Einträge',
                infoFiltered: '(gefiltert aus _MAX_)',
                zeroRecords: 'Nichts gefunden.',
                emptyTable: 'Keine Standorte vorhanden.',
                paginate: {
                    first: 'Erste',
                    last: 'Letzte',
                    next: 'Weiter',
                    previous: 'Zurück'
                },
                aria: {
                    sortAscending: ': aufsteigend sortieren',
                    sortDescending: ': absteigend sortieren'
                }
            }
        };
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

                    dt.cell(node, cols.status).data(view.icon);
                    // Die Benutzerspalte traegt keinen Zustand mehr - er steht
                    // vollstaendig in der Statusspalte und wurde vorher an
                    // beiden Stellen gefuehrt.

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
     *
     * Die Handler werden je Tabelle nur EINMAL gesetzt. Auf der
     * Einstellungsseite ruft jeder Klick auf "Eigene Locations bearbeiten"
     * diese Methode erneut auf; ohne die Sperre haetten sich die
     * Maus-Handler der Kartenvorschau mit jedem Klick vervielfacht. Die
     * Aktionsknoepfe schuetzten sich schon vorher mit einem .off(), die
     * Karten-Handler nicht.
     *
     * Neu geladen wird dagegen bei jedem Aufruf.
     *
     * @param {Object} options
     */
    bindEvents(options) {
        options = this.withDefaults(options);
        if (!$(options.tableSelector).length) return;
                
        // Direkt laden
        this.loadLocationsTable(options);

        // Die Optionen einer Tabelle aendern sich zur Laufzeit nicht - sie
        // kommen aus TABLES. Die Handler duerfen sie deshalb festhalten.
        if (this.boundTables[options.tableSelector]) return;
        this.boundTables[options.tableSelector] = true;

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
                window.webrtcApp.notify.error('Die Anruffunktion steht auf dieser Seite nicht zur Verfügung.');
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
                    window.webrtcApp.notify.error('Der Standort konnte nicht zugeordnet werden.');
                    return;
                }
                window.webrtcApp.notify.confirm({
                    title: 'Standort löschen?',
                    text: 'Der Standort verschwindet von der Karte und aus allen Listen. Das lässt sich nicht rückgängig machen.',
                    confirmText: 'Löschen',
                    danger: true
                }).then(ja => {
                    if (!ja) return;
                    $.ajax({
                        url: 'index.php?act=delete_location',
                        method: 'POST',
                        data: { id: locationId },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                window.webrtcApp.notify.success('Standort gelöscht.');
                                // Tabelle neu laden - mit den Optionen, mit
                                // denen sie geladen wurde. Hier stand vorher
                                // eine zweite, von Hand gepflegte Kopie der
                                // Tabellenkonfiguration.
                                window.webrtcApp.locationsTable.loadLocationsTable(options);
                            } else {
                                window.webrtcApp.notify.error(response.error || 'Der Standort konnte nicht gelöscht werden.');
                            }
                        },
                        error: function() {
                            window.webrtcApp.notify.error('Der Standort konnte nicht gelöscht werden.');
                        }
                    });
                });
            });

        // Sperren (Moderation). Der Grund ist Pflicht - der Guide bekommt
        // genau diesen Text in seiner Standortliste angezeigt.
        $(options.tableSelector)
            .off('click', '.block-location-btn')
            .on('click', '.block-location-btn', function() {
                const locationId = $(this).data('locationid');
                if (!locationId) return;

                // Der Grund wird im Dialog erfragt, nicht im Systemfeld des
                // Browsers. Fehlt er, sagt das der Dialog selbst - frueher kam
                // dafuer ein zweites alert() ueber dem ersten.
                window.webrtcApp.notify.prompt({
                    title: 'Standort sperren',
                    text: 'Der Guide bekommt diesen Text in seiner Standortliste zu sehen.',
                    label: 'Grund',
                    placeholder: 'Warum wird gesperrt?',
                    required: true,
                    requiredText: 'Ohne Grund ist die Sperre für den Guide nicht nachvollziehbar.',
                    confirmText: 'Sperren',
                    multiline: true
                }).then(grund => {
                    if (grund === null) return;           // abgebrochen
                    window.webrtcApp.locationsTable.moderate('index.php?act=block_location', {
                        id: locationId,
                        reason: grund
                    }, options);
                });
            });

        // Freigeben
        $(options.tableSelector)
            .off('click', '.unblock-location-btn')
            .on('click', '.unblock-location-btn', function() {
                const locationId = $(this).data('locationid');
                if (!locationId) return;

                window.webrtcApp.notify.confirm({
                    title: 'Sperre aufheben?',
                    text: 'Der Standort erscheint danach wieder auf der Karte und in der Liste.',
                    confirmText: 'Freigeben'
                }).then(ja => {
                    if (!ja) return;
                    window.webrtcApp.locationsTable.moderate('index.php?act=unblock_location', {
                        id: locationId
                    }, options);
                });
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
                    window.webrtcApp.notify.error((response && response.error) || 'Die Aktion ist fehlgeschlagen.');
                }
            },
            error: function(xhr) {
                window.webrtcApp.notify.error(xhr.status === 403
                    ? 'Dafür fehlt Ihnen die Berechtigung.'
                    : 'Die Aktion ist fehlgeschlagen.');
            }
        });
    },
};

// Initialisierung bei DOM-Ready
//
// Jede der beiden Tabellen wird ueber ihre EIGENE id gefunden und mit ihren
// eigenen Optionen geladen. Frueher hiessen beide `locationsTable`; welche
// gemeint war, entschied eine Abfrage auf ein benachbartes Element
// (`#myLocationsSection`). Ein zusaetzlicher Aufruf ohne Optionen traf
// dadurch die Tabelle der Einstellungsseite mit den Einstellungen der
// Uebersicht - eine Spalte zu viel, DataTables brach ab.
$(document).ready(function () {
    const tabellen = window.webrtcApp.locationsTable;

    // Uebersicht aller fremden Standorte
    if ($(tabellen.TABLES.overview.selector).length) {
        // Wer moderieren darf, bekommt zusaetzlich Sperren/Freigeben. Die
        // Angabe kommt vom Server (ViewHelper::output) und steuert nur die
        // Anzeige; die Routen pruefen das Recht selbst.
        const extra = (window.userCan && window.userCan.blockLocation)
            ? { showActions: ['call', 'block'] }
            : {};
        tabellen.bindEvents(tabellen.optionsFor('overview', extra));
    }

    // Eigene Standorte auf der Einstellungsseite. Geladen wird erst beim
    // Aufklappen - die Tabelle ist bis dahin ausgeblendet.
    if ($(tabellen.TABLES.own.selector).length) {
        $('#showOwnLocationsBtn').show().on('click', function(e) {
            e.preventDefault();
            $('#myLocationsSection').toggle();
            tabellen.bindEvents(tabellen.optionsFor('own'));
        });
    }

    // Edit-Formular absenden
    $('#editDescForm').off('submit').on('submit', function(e) {
        e.preventDefault();

        const locationId = $('#editLocationId').val();
        const newDesc = $('#newDescription').val().trim();

        if (!newDesc) {
            window.webrtcApp.notify.error('Bitte eine neue Beschreibung eingeben.');
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
                    window.webrtcApp.notify.success('Beschreibung geändert.');
                    // Dritte Kopie derselben Tabellenkonfiguration - jetzt aus
                    // TABLES.
                    window.webrtcApp.locationsTable.loadLocationsTable(
                        window.webrtcApp.locationsTable.optionsFor('own')
                    );
                } else {
                    window.webrtcApp.notify.error(response.error || 'Die Beschreibung konnte nicht geändert werden.');
                }
            },
            error: function() {
                window.webrtcApp.notify.error('Die Beschreibung konnte nicht geändert werden.');
            }
        });
    });
});
