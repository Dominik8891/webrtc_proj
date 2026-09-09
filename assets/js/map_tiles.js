/**
 * Die Kartenkacheln - an EINER Stelle.
 *
 * WARUM ES DIESE DATEI GIBT
 * -------------------------
 * Die Kachel-Adresse stand fuenfmal woertlich im Code: in map.js,
 * home_map.js, location_page.js und zweimal in locations_table.js. Fuenf
 * Literale heissen fuenf Handgriffe bei jedem Wechsel des Anbieters - und
 * beim sechsten Aufrufer wird einer davon vergessen. Genauso lagen die
 * Nebenangaben auseinander: Drei Karten nannten die Herkunft, zwei nicht;
 * drei begrenzten den Zoom, zwei nicht.
 *
 * Hier steht beides einmal. Ein Anbieterwechsel ist ab jetzt eine Aenderung
 * in dieser Datei plus der Eintrag in App\Helper\SecurityHeaders (die CSP
 * muss den neuen Host kennen, sonst laedt der Browser die Kacheln nicht).
 *
 * DIE HERKUNFTSANGABE IST KEINE ZIERDE. OpenStreetMap steht unter der ODbL,
 * und die verlangt die Nennung. Sie gehoert deshalb an JEDE Karte, auch an
 * die kleine Vorschau, die beim Ueberfahren einer Tabellenzeile aufgeht -
 * dort fehlte sie bisher.
 *
 * WAS HIER (NOCH) NICHT STEHT
 * ---------------------------
 * Die Beschriftung der Kacheln ist die des jeweiligen Landes: Athen heisst
 * hier Αθήνα. Das aendert erst ein Anbieterwechsel, und der gehoert nicht in
 * diese Aenderung. Diese Datei ist die Stelle, an der er spaeter stattfindet.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.mapTiles = {

    /**
     * Die Kachel-Adresse.
     *
     * {s} wird von Leaflet zu a, b oder c. Die Content-Security-Policy
     * erlaubt deshalb 'https://*.tile.openstreetmap.org' und nicht drei
     * einzelne Namen - siehe App\Helper\SecurityHeaders::KARTEN_KACHELN.
     */
    URL: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',

    /** Die Herkunftsangabe, die Leaflet unten rechts in die Karte setzt. */
    ATTRIBUTION: '&copy; OpenStreetMap',

    /**
     * Hoechste Zoomstufe, fuer die es Kacheln gibt.
     *
     * Ohne die Angabe laesst Leaflet weiter hineinzoomen und bekommt ab
     * Stufe 20 nur noch Fehler - die Karte wird grau. Bisher stand die Zahl
     * an drei von fuenf Karten; die beiden ohne sie waren still im Nachteil.
     */
    MAX_ZOOM: 19,

    /**
     * Haengt die Kachelebene an eine Karte.
     *
     * @param {Object} in_karte     Leaflet-Map-Objekt
     * @param {Object} [in_extra]   Zusaetzliche Optionen fuer L.tileLayer.
     *                              Sie ueberschreiben die Vorgaben oben -
     *                              gedacht fuer den Einzelfall, nicht fuer
     *                              den Regelfall.
     * @returns {Object} Die erzeugte Kachelebene
     */
    add(in_karte, in_extra) {
        const optionen = Object.assign({
            attribution: this.ATTRIBUTION,
            maxZoom: this.MAX_ZOOM
        }, in_extra || {});

        return L.tileLayer(this.URL, optionen).addTo(in_karte);
    }
};
