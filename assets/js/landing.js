/**
 * Die Karte der Landingpage - der Blickfang.
 *
 * WAS SIE ZEIGT UND WAS NICHT
 * ---------------------------
 * Sie zeigt die MOEGLICHKEIT: Nadeln erscheinen nach und nach ueber der
 * Weltkarte, bis das Bild voll ist. Sie zeigt NICHT den Bestand - keine
 * dieser Nadeln steht fuer einen Standort in der Datenbank, und dieses
 * Modul fragt den Server nach gar nichts.
 *
 * DASS DAS ERLAUBT IST, GILT NUR HIER. Auf der Startseite der Anwendung
 * (assets/js/home_map.js) steht auf der Karte, was es wirklich gibt, und
 * wenn dort nichts ist, sagt die Seite das auch. Eine erfundene Nadel waere
 * dort eine Falschauskunft. Auf einer Werbeseite ist sie das Bild zu einem
 * Satz - und unter der Karte steht, dass sie es ist
 * (landing.hero.karte_hinweis).
 *
 * WARUM DIE ORTE ECHTE ORTE SIND
 * ------------------------------
 * Zufaellige Koordinaten waeren einfacher, landen aber im Atlantik, in der
 * Antarktis und mitten in der Sahara - und eine Nadel im Meer laesst die
 * ganze Karte unglaubwuerdig aussehen. Die Liste unten sind deshalb
 * Stadtkoordinaten, ueber alle bewohnten Erdteile verteilt.
 *
 * OHNE NAMEN. Die Nadeln tragen keine Beschriftung, und das ist kein
 * Versehen: Ein Ortsname waere ein Versprechen ("dort gibt es das"), das
 * diese Karte nicht einloest. Ein Punkt verspricht nichts.
 *
 * WOHER DIE KACHELN KOMMEN
 * ------------------------
 * Aus assets/js/map_tiles.js - derselben Stelle wie fuer jede andere Karte
 * der Anwendung. Die Herkunftsangabe (OpenStreetMap, ODbL) kommt damit
 * automatisch mit; sie ist Pflicht und keine Zierde.
 *
 * WAS PASSIERT, WENN LEAFLET FEHLT
 * --------------------------------
 * Nichts. Die Flaeche bleibt leer, der Satz darueber steht trotzdem da, und
 * die Knoepfe funktionieren. Der Blickfang ist Schmuck; die Seite darf nicht
 * daran haengen.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.landing = {

    /** Wie viele Nadeln am Ende stehen. */
    NADELN: 100,

    /** Abstand zwischen zwei Nadeln, in Millisekunden. */
    TAKT_MS: 90,

    /**
     * Der Rand, den fitBounds um die Nadeln laesst, in Pixeln.
     *
     * DER AUSSCHNITT WIRD NICHT FEST EINGESTELLT, sondern aus den Nadeln
     * berechnet (fitBounds weiter unten). Eine feste Zoomstufe passt immer
     * nur zu einer Fenstergroesse: Auf einem breiten Bildschirm bliebe die
     * halbe Karte leer, auf einem schmalen faellt Suedamerika heraus. Aus
     * demselben Grund zoomSnap 0 - sonst rundet Leaflet auf ganze Stufen
     * und schneidet dabei wieder ab.
     */
    RAND: 40,

    /**
     * Orte, an denen eine Fuehrung stattfinden koennte.
     *
     * ECHTE STAEDTE, damit keine Nadel im Wasser steht - siehe der Kopf
     * dieser Datei. Ueber die Erdteile verteilt und nicht auf Europa
     * gehaeuft: Die Karte soll aussehen wie ein weltweites Angebot, denn
     * genau das ist die Behauptung des Produkts.
     *
     * Als [Breite, Laenge] und ohne Namen - angezeigt wird nur der Punkt.
     */
    ORTE: [
        [38.72, -9.13], [41.15, -8.61], [40.42, -3.70], [37.39, -5.99],
        [41.39, 2.17], [43.30, 5.37], [48.86, 2.35], [45.76, 4.84],
        [50.85, 4.35], [52.37, 4.90], [51.51, -0.13], [53.48, -2.24],
        [55.95, -3.19], [53.35, -6.26], [52.52, 13.40], [48.14, 11.58],
        [50.94, 6.96], [53.55, 9.99], [50.11, 8.68], [47.37, 8.54],
        [46.20, 6.14], [48.21, 16.37], [50.08, 14.44], [52.23, 21.01],
        [50.06, 19.94], [47.50, 19.04], [44.43, 26.10], [42.70, 23.32],
        [37.98, 23.73], [41.01, 28.98], [45.44, 12.34], [45.46, 9.19],
        [43.77, 11.26], [41.90, 12.50], [40.85, 14.27], [37.51, 15.09],
        [59.33, 18.07], [59.91, 10.75], [55.68, 12.57], [60.17, 24.94],
        [59.44, 24.75], [56.95, 24.11], [54.69, 25.28], [55.75, 37.62],
        [59.93, 30.34], [50.45, 30.52], [41.72, 44.78], [40.18, 44.51],
        [33.89, 35.50], [31.78, 35.22], [30.04, 31.24], [36.81, 10.18],
        [32.89, 13.19], [33.57, -7.59], [31.63, -7.99], [14.72, -17.47],
        [6.52, 3.38], [5.60, -0.19], [9.03, 38.75], [-1.29, 36.82],
        [-6.79, 39.21], [-26.20, 28.05], [-33.92, 18.42], [-22.57, 17.08],
        [-18.88, 47.51], [24.71, 46.68], [25.20, 55.27], [29.38, 47.98],
        [35.69, 51.39], [33.34, 44.40], [24.86, 67.01], [31.55, 74.34],
        [28.61, 77.21], [19.08, 72.88], [12.97, 77.59], [22.57, 88.36],
        [27.72, 85.32], [6.93, 79.86], [16.80, 96.15], [13.76, 100.50],
        [11.55, 104.92], [21.03, 105.85], [10.82, 106.63], [3.14, 101.69],
        [1.35, 103.82], [-6.21, 106.85], [-8.65, 115.22], [14.60, 120.98],
        [22.32, 114.17], [31.23, 121.47], [39.90, 116.41], [37.57, 126.98],
        [35.68, 139.69], [34.69, 135.50], [43.06, 141.35], [-33.87, 151.21],
        [-37.81, 144.96], [-36.85, 174.76], [-41.29, 174.78], [64.15, -21.94],
        [45.42, -75.70], [43.65, -79.38], [49.28, -123.12], [40.71, -74.01],
        [41.88, -87.63], [37.77, -122.42], [34.05, -118.24], [29.76, -95.37],
        [25.76, -80.19], [19.43, -99.13], [20.97, -89.62], [14.63, -90.51],
        [9.93, -84.09], [8.98, -79.52], [23.11, -82.37], [18.47, -69.90],
        [4.71, -74.07], [10.50, -66.92], [-0.18, -78.47], [-12.05, -77.04],
        [-16.50, -68.15], [-33.45, -70.67], [-34.60, -58.38], [-34.90, -56.19],
        [-23.55, -46.63], [-22.91, -43.17], [-12.97, -38.51], [-8.05, -34.88],
        [-3.73, -38.53], [-15.79, -47.88], [-25.43, -49.27], [-30.03, -51.23]
    ],

    /**
     * Baut die Karte, sobald ihre Flaeche im Dokument steht.
     *
     * Auf jeder anderen Seite tut dieses Modul nichts - genau wie
     * assets/js/location_page.js: Ein Skript, das im Layout jeder Seite
     * geladen wird, darf nur dort etwas tun, wo es hingehoert.
     */
    init() {
        const flaeche = document.getElementById('lp-map');
        if (!flaeche) return;

        // Die Seite braucht die volle Breite: Der Inhaltsbereich gibt seinen
        // Aussenabstand ab, und die Seitenbreite von 1200px wird aufgehoben.
        // DIESELBE KLASSE WIE DIE STARTSEITE (assets/css/home.css) - eine
        // zweite Fassung derselben Regel waere eine zu viel.
        document.getElementById('app-main')?.classList.add('app-main--flush');

        if (typeof L === 'undefined' || !window.webrtcApp.mapTiles) {
            // Ohne Leaflet bleibt die Flaeche leer. Der Satz darueber steht
            // trotzdem, die Knoepfe gehen - siehe der Kopf dieser Datei.
            return;
        }

        // KEINE BEDIENUNG. Die Karte ist ein Bild: Wer sie anfasst, soll
        // nichts kaputtmachen koennen, und wer scrollt, soll scrollen und
        // nicht versehentlich in Groenland hineinzoomen.
        const karte = L.map(flaeche, {
            zoomSnap: 0,
            zoomControl: false,
            attributionControl: true,
            dragging: false,
            scrollWheelZoom: false,
            doubleClickZoom: false,
            boxZoom: false,
            keyboard: false,
            touchZoom: false,
            // Ohne diese Zeile faengt die Flaeche Klicks ab, die eigentlich
            // dem Knopf darueber gelten.
            tap: false
        });

        // Der Ausschnitt: so weit, dass jede Nadel darauf Platz hat. Er wird
        // VOR den Kacheln gesetzt - eine Karte ohne Ausschnitt hat keine
        // Mitte, und Leaflet laedt dann Kacheln fuer nichts.
        karte.fitBounds(L.latLngBounds(this.ORTE), { padding: [this.RAND, this.RAND] });

        window.webrtcApp.mapTiles.add(karte);

        this.nadelnSetzen(karte);
    },

    /**
     * Setzt die Nadeln, eine nach der anderen.
     *
     * DIE REIHENFOLGE IST GEMISCHT und nicht die der Liste: Sonst wanderte
     * eine Linie von Europa nach Amerika ueber die Karte, und das saehe aus
     * wie ein Ladevorgang. Gemischt wirkt es wie ein Bild, das entsteht.
     *
     * ABGEBROCHEN WIRD BEIM VERLASSEN DER SEITE nicht eigens - die Timer
     * enden mit dem Dokument. Sie laufen ausserdem nur einmal durch und
     * nicht in einer Schleife: Wenn das Bild voll ist, ist es fertig.
     *
     * @param {Object} in_karte Leaflet-Map
     */
    nadelnSetzen(in_karte) {
        const orte = this.ORTE.slice();

        // Fisher-Yates. Die Liste selbst bleibt unveraendert - sie ist eine
        // Konstante, auch wenn JavaScript das nicht erzwingt.
        for (let i = orte.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [orte[i], orte[j]] = [orte[j], orte[i]];
        }

        const anzahl = Math.min(this.NADELN, orte.length);

        for (let i = 0; i < anzahl; i++) {
            setTimeout(() => {
                // JEDE ZEHNTE IST "LIVE" - gruen und mit einem Ring, der
                // einmal aufgeht. Gruen heisst in dieser Anwendung "ein
                // Guide ist jetzt erreichbar" (assets/css/theme.css), und
                // dieselbe Bedeutung gilt hier: So sieht es aus, wenn jemand
                // bereitsteht. Der Rest ist gedaempft - ein Ort, an dem
                // gerade niemand ist.
                const live = (i % 10 === 0);

                L.marker(orte[i], {
                    icon: L.divIcon({
                        className: '',
                        html: '<span class="lp-pin' + (live ? ' lp-pin--live' : '') + '"></span>',
                        iconSize: [14, 14],
                        iconAnchor: [7, 7]
                    }),
                    keyboard: false,
                    interactive: false
                }).addTo(in_karte);
            }, i * this.TAKT_MS);
        }
    }
};

document.addEventListener('DOMContentLoaded', function () {
    window.webrtcApp.landing.init();
});
