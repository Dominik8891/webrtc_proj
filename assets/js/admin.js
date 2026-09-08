window.webrtcApp = window.webrtcApp || {};

/**
 * Die Aktionen des Verwaltungsbereichs: Standorte sperren und freigeben,
 * Bewertungen entfernen.
 *
 * WARUM EIN EIGENES MODUL
 * -----------------------
 * Weil diese drei Handlungen vorher in Modulen standen, die der
 * Kundenoberflaeche gehoeren: Sperren und Freigeben in
 * assets/js/locations_table.js (dem Modul, das die Standortliste der Kunden
 * baut), das Entfernen einer Bewertung in assets/js/review.js (dem Modul,
 * das den Kunden nach der Fuehrung fragt). Beide Male hing eine
 * Verwaltungsaktion an einer Ansicht, die fuer jemand anderen gebaut ist -
 * und beide Male entschied ein window.userCan darueber, ob der Knopf
 * ueberhaupt erscheint.
 *
 * Jetzt gibt es die Knoepfe nur noch auf den Seiten des Bereichs, und dieses
 * Modul meldet sich nur dort: Ohne .adm im Dokument haengt es sich gar nicht
 * erst ein.
 *
 * WAS SICH NICHT GEAENDERT HAT: die Routen dahinter. block_location,
 * unblock_location und review_remove gab es vorher, sie pruefen ihre Rechte
 * selbst (index.php anhand von config/routes.php), und sie sind weiterhin die
 * eine Stelle, an der die jeweilige Aenderung passiert.
 *
 * NACH JEDER AENDERUNG WIRD DIE SEITE NEU GELADEN. Das ist hier richtig und
 * nicht faul: Eine Sperre aendert die Zeile, ihre Sortierung (gesperrte
 * stehen oben) und die Zahl im Kopf der Liste. Das im Browser nachzuziehen
 * hiesse, die Regeln des Servers ein zweites Mal aufzuschreiben.
 */
window.webrtcApp.admin = {

    /** Laeuft gerade eine Anfrage? Verhindert den Doppelklick. */
    busy: false,

    /**
     * Haengt die Knoepfe ein.
     *
     * EIN HANDLER AM DOKUMENT und nicht je Knopf: Die Tabellen kommen zwar
     * fertig vom Server, aber ein Handler am Dokument ueberlebt jedes
     * spaetere Nachladen - und er kostet nichts, solange nichts geklickt wird.
     */
    init() {
        if (!document.querySelector('.adm')) return;

        document.addEventListener('click', (e) => {
            const sperren = e.target.closest('.adm-block');
            if (sperren) {
                e.preventDefault();
                this.sperre(sperren);
                return;
            }

            const freigeben = e.target.closest('.adm-unblock');
            if (freigeben) {
                e.preventDefault();
                this.gib_frei(freigeben);
                return;
            }

            const bewertung = e.target.closest('.adm-review-remove');
            if (bewertung) {
                e.preventDefault();
                this.entferne_bewertung(bewertung);
            }
        });
    },

    /**
     * Einen Standort sperren.
     *
     * DER GRUND IST PFLICHT, und das ist keine Foermelei: Der Guide bekommt
     * genau diesen Text in seiner eigenen Standortliste zu sehen. Ohne ihn
     * verschwindet sein Angebot aus der Uebersicht, und er erfaehrt nicht,
     * warum.
     *
     * Der Standort steht mit Namen in der Rueckfrage - in einer Tabelle mit
     * fuenfhundert Zeilen ist "diesen hier" keine Auskunft.
     *
     * @param {HTMLElement} knopf
     */
    sperre(knopf) {
        const id = parseInt(knopf.getAttribute('data-id'), 10) || 0;
        if (!id || this.busy) return;

        const titel = knopf.getAttribute('data-title') || ('#' + id);

        window.webrtcApp.notify.prompt({
            title: 'Standort sperren',
            text: '„' + titel + '“ verschwindet aus Karte und Liste. Der Guide '
                + 'bekommt diesen Text in seiner Standortliste zu sehen. '
                + 'Gelöscht wird nichts.',
            label: 'Grund',
            placeholder: 'Warum wird gesperrt?',
            required: true,
            requiredText: 'Ohne Grund ist die Sperre für den Guide nicht nachvollziehbar.',
            confirmText: 'Sperren',
            multiline: true
        }).then(grund => {
            if (grund === null) return;           // abgebrochen
            this.schicke('index.php?act=block_location', { id: id, reason: grund },
                         'Gesperrt.', false);
        });
    },

    /**
     * Eine Sperre wieder aufheben.
     *
     * @param {HTMLElement} knopf
     */
    gib_frei(knopf) {
        const id = parseInt(knopf.getAttribute('data-id'), 10) || 0;
        if (!id || this.busy) return;

        const titel = knopf.getAttribute('data-title') || ('#' + id);

        window.webrtcApp.notify.confirm({
            title: 'Sperre aufheben?',
            text: '„' + titel + '“ erscheint danach wieder auf der Karte und in der Liste.',
            confirmText: 'Freigeben'
        }).then(ja => {
            if (!ja) return;
            this.schicke('index.php?act=unblock_location', { id: id }, 'Freigegeben.', false);
        });
    },

    /**
     * Eine Bewertung entfernen.
     *
     * ENTFERNT IST NICHT GELOESCHT: Die Zeile bleibt stehen und wird
     * ausgeblendet (App\Model\TourReview::remove). In dieser Liste ist sie
     * danach weiterhin zu sehen, gekennzeichnet - das ist der Unterschied zur
     * Standortseite, auf der sie verschwindet.
     *
     * MIT RUECKFRAGE, und der Text sagt die Folge, die man nicht sieht: Der
     * Kunde kann diese Fuehrung danach nicht erneut bewerten.
     *
     * @param {HTMLElement} knopf
     */
    entferne_bewertung(knopf) {
        const id = parseInt(knopf.getAttribute('data-id'), 10) || 0;
        if (!id || this.busy) return;

        window.webrtcApp.notify.confirm({
            title: 'Bewertung entfernen?',
            text: 'Die Bewertung verschwindet von der Standortseite und vom Profil des '
                + 'Guides und zählt nicht mehr im Durchschnitt. Gelöscht wird sie nicht – '
                + 'sie bleibt hier nachvollziehbar stehen. Der Kunde kann diese Führung '
                + 'danach nicht erneut bewerten.',
            confirmText: 'Entfernen',
            danger: true
        }).then(ja => {
            if (!ja) return;
            this.schicke('index.php?act=review_remove', { id: id }, 'Entfernt.', true);
        });
    },

    /**
     * Der gemeinsame Weg aller drei Aktionen.
     *
     * Er steht hier und nicht dreimal ausgeschrieben: Was sich unterscheidet,
     * ist die Route und die Meldung - alles davor und danach ist dasselbe.
     *
     * ALLE DREI ANTWORTEN ALS JSON (config/routes.php, Feld [3]). Eine
     * Ablehnung kommt damit als Objekt zurueck und nicht als Anmeldeformular
     * im JSON-Parser.
     *
     * GESCHICKT WIRD IN ZWEI FORMEN, und das ist kein Versehen: Die beiden
     * Standortrouten lesen ihre Angaben ueber App\Helper\Request aus
     * $_REQUEST, also aus einem Formularrumpf; review_remove liest den rohen
     * JSON-Rumpf (App\Controller\ReviewController::body). Beide Routen gab
     * es vorher, und beide werden hier genau so aufgerufen, wie sie gebaut
     * sind - dieselben Aufrufe wie vorher aus locations_table.js und
     * review.js. Sie anzugleichen waere richtig, ist aber eine Aenderung an
     * Routen, die mit diesem Bereich nichts zu tun hat.
     *
     * @param {string}  route
     * @param {Object}  daten
     * @param {string}  meldung  Was nach dem Erfolg kurz eingeblendet wird
     * @param {boolean} alsJson  true: JSON-Rumpf, false: Formularrumpf
     */
    schicke(route, daten, meldung, alsJson) {
        this.busy = true;

        const rumpf = alsJson
            ? { headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(daten) }
            : { body: new URLSearchParams(daten) };

        fetch(route, Object.assign({
            method: 'POST',
            credentials: 'same-origin'
        }, rumpf))
        .then(r => r.json())
        .then(antwort => {
            this.busy = false;
            if (!antwort || !antwort.success) {
                window.webrtcApp.notify.error(
                    (antwort && antwort.error) || 'Das hat nicht geklappt.'
                );
                return;
            }
            window.webrtcApp.notify.success(meldung);
            // Neu laden - siehe der Kommentar am Kopf dieses Moduls. Der
            // Filter in der Adresse bleibt dabei stehen.
            window.location.reload();
        })
        .catch(() => {
            this.busy = false;
            window.webrtcApp.notify.error('Keine Verbindung. Bitte erneut versuchen.');
        });
    },
};

window.addEventListener('DOMContentLoaded', function() {
    window.webrtcApp.admin.init();
});
