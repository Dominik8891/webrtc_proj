/**
 * Die Texte im Browser.
 *
 * WOZU ES DAS UEBERHAUPT GIBT
 * ---------------------------
 * Den allergroessten Teil der Seite setzt der Server zusammen - dort werden
 * die Texte in App\Helper\I18n eingesetzt, und dieses Modul hat damit nichts
 * zu tun. Hier stehen die Saetze, die es beim Ausliefern der Seite noch gar
 * nicht gab: "Anruf abgelehnt", "Datei zu gross", "Nicht gespeichert". Sie
 * entstehen aus einem Ereignis, und ein Ereignis kennt der Server nicht.
 *
 * WOHER DER KATALOG KOMMT
 * -----------------------
 * Aus dem Dokument, als window.appI18n - genau so, wie window.locationPage
 * die Angaben einer Standortseite mitbringt (assets/js/location_page.js) und
 * window.reviewScale die Skala einer Bewertung. Der Server legt ihn im
 * <head> ab (App\Helper\I18n::bootScript). Kein zweiter Weg, keine zweite
 * Anfrage: Was der Server ohnehin hat, geht mit der Seite mit.
 *
 * DIE KETTE IST DIESELBE WIE IN PHP
 * ---------------------------------
 *   1. Katalog der aktiven Sprache
 *   2. Katalog der Vorgabesprache
 *   3. der Schluessel selbst
 *
 * Punkt 3 ist Absicht: Ein fehlender Text steht dann sichtbar als
 * "call.abgelehnt" auf dem Schirm. Eine leere Meldung faellt niemandem auf -
 * und damit faellt auch nicht auf, dass ein Text fehlt.
 *
 * WAS UEBER DIE EREIGNISSE HINAUS NOCH HIER LANDET
 * ------------------------------------------------
 * Die Sprachbloecke der beiden fremden Bibliotheken. DataTables und select2
 * sprechen ab Werk englisch und lassen sich nur dadurch uebersetzen, dass
 * man ihnen ihre Texte uebergibt - assets/js/locations_table.js und
 * assets/js/map.js tun das aus diesem Katalog. Die Marken darin (_MENU_,
 * _START_, _TOTAL_ ...) sind KEINE Platzhalter dieser Anwendung: Sie gehen
 * unveraendert durch, weil die Bibliothek sie selbst einsetzt.
 *
 * WAS DIESES MODUL NICHT TUT
 * --------------------------
 * Die Seite umschreiben. Wer die Sprache wechselt, holt die Seite neu; sie
 * kommt fertig in der neuen Sprache. Ein Modul, das nachtraeglich austauscht,
 * was schon dasteht, muesste jeden Satz der Anwendung ein zweites Mal kennen -
 * und waere damit die zweite Fassung jedes Textes.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.i18n = {

    /** Die Sprache, solange das Dokument nichts anderes sagt. */
    DEFAULT: 'en',

    /**
     * Die Angaben aus dem Dokument.
     *
     * Ein leerer Ersatz, falls window.appI18n fehlt - etwa in einem Test
     * oder wenn die Seite ohne das Skript im <head> ausgeliefert wurde. Dann
     * greift Punkt 3 der Kette, und jede Meldung zeigt ihren Schluessel. Das
     * ist haesslich und genau deshalb richtig: Es faellt auf.
     */
    get daten() {
        return window.appI18n || {};
    },

    /**
     * Die aktive Sprache.
     *
     * @returns {string}
     */
    lang() {
        return this.daten.lang || this.DEFAULT;
    },

    /**
     * Die Kennung fuer Intl - also fuer toLocaleDateString und Verwandte.
     *
     * WARUM SIE AUS DEM KATALOG KOMMT UND NICHT AUS lang(): Weil sie mehr
     * traegt als die Sprache. 'en' allein laesst den Browser die
     * amerikanische Reihenfolge waehlen (Monat vor Tag); gewollt ist 'en-GB'.
     * Welche Region zu einer Sprache gehoert, ist eine Entscheidung dieser
     * Anwendung und steht deshalb dort, wo ihre Texte stehen
     * (Schluessel datum.locale).
     *
     * FEHLT DER SCHLUESSEL, gilt das nackte Sprachkuerzel. Hier steht
     * ausnahmsweise nicht der Schluessel selbst wie bei jedem Text: Intl
     * bekaeme "datum.locale" als Kennung und wuerfe einen RangeError - eine
     * Ausnahme mitten in der Anzeige einer Nachricht, statt eines Datums in
     * der falschen Reihenfolge.
     *
     * @returns {string} z.B. 'de-DE'
     */
    locale() {
        const wert = window.webrtcApp.t('datum.locale');
        return wert === 'datum.locale' ? this.lang() : wert;
    },

    /**
     * Der unveraenderte Katalogeintrag - Zeichenkette oder Formenobjekt.
     *
     * @param {string} schluessel
     * @returns {string|Object|null} null, wenn kein Katalog ihn kennt
     */
    roh(schluessel) {
        const daten = this.daten;
        const eigen = daten.catalog || {};
        if (Object.prototype.hasOwnProperty.call(eigen, schluessel)) {
            return eigen[schluessel];
        }

        const vorgabe = daten.fallback || {};
        if (Object.prototype.hasOwnProperty.call(vorgabe, schluessel)) {
            return vorgabe[schluessel];
        }

        return null;
    },

    /**
     * Setzt Platzhalter der Form {name} ein.
     *
     * NICHT ERSETZT WIRD, WAS NICHT UEBERGEBEN WURDE - aus demselben Grund
     * wie in App\Helper\I18n::einsetzen(): Ein sichtbarer Rest ist ein
     * Fehler, den jemand meldet.
     *
     * @param {string} text
     * @param {Object} werte
     * @returns {string}
     */
    einsetzen(text, werte) {
        if (!werte) return text;

        let out = text;
        Object.keys(werte).forEach((name) => {
            const wert = werte[name];
            if (wert === undefined || wert === null || typeof wert === 'object') return;
            // split/join statt einer Regex: Ein Platzhaltername ist ein
            // gewoehnlicher Text, und in einer Regex waeren Zeichen wie "."
            // etwas anderes als sie selbst.
            out = out.split('{' + name + '}').join(String(wert));
        });
        return out;
    },

    /**
     * Maskiert, was in HTML als Text stehen soll.
     *
     * Es gibt diese Funktion in mehreren Modulen dieser Anwendung, und das
     * ist dort auch richtig - sie maskieren FREMDEN Text. Diese hier gehoert
     * zum Katalog und wird von tHtml() gebraucht; ein Modul, das nur einen
     * Satz aus dem Katalog braucht, soll dafuer keine eigene mitbringen
     * muessen.
     *
     * @param {*} wert
     * @returns {string}
     */
    esc(wert) {
        return String(wert ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    },

    /**
     * Welche Form gilt in dieser Sprache fuer diese Anzahl?
     *
     * Gleichlautend mit App\Helper\I18n::pluralForm(). Zwei Fassungen
     * derselben Regel sind eine zu viel - kommt eine Sprache mit drei Formen
     * dazu, wird sie an BEIDEN Stellen ergaenzt, und ein Test haelt fest,
     * dass sie dasselbe sagen.
     *
     * @param {string} lang
     * @param {number} n
     * @returns {string} 'one' oder 'other'
     */
    pluralForm(lang, n) {
        switch (lang) {
            case 'de':
            case 'en':
            default:
                return Math.abs(n) === 1 ? 'one' : 'other';
        }
    }
};

/**
 * Der Text zu einem Schluessel.
 *
 * WARUM DIESE BEIDEN AM webrtcApp SELBST HAENGEN und nicht an
 * webrtcApp.i18n: Sie werden oefter gerufen als alles andere in dieser
 * Anwendung. "webrtcApp.t('call.abgelehnt')" liest sich noch wie ein Satz;
 * "webrtcApp.i18n.uebersetze('call.abgelehnt')" liest sich wie Technik und
 * steht dann irgendwann nicht da, wo es hingehoert.
 *
 * @param {string} schluessel  z.B. 'sprache.titel'
 * @param {Object} [werte]     Platzhalter, z.B. { name: 'Anna' }
 * @returns {string}
 */
window.webrtcApp.t = function (schluessel, werte) {
    const i18n = window.webrtcApp.i18n;
    const text = i18n.roh(schluessel);

    if (typeof text !== 'string') return schluessel;

    return i18n.einsetzen(text, werte);
};

/**
 * Derselbe Text, aber fuer eine Stelle, die HTML entgegennimmt.
 *
 * WOZU ES DAS BRAUCHT
 * -------------------
 * Manche Saetze tragen eine Hervorhebung mittendrin: "Ihre Anfrage fuer
 * <strong>Lissabon</strong> ist beim Guide." Im Katalog steht kein HTML
 * (lang/de.php, Regel 5), im Katalog steht der Satz mit einem Platzhalter -
 * und das Markup kommt vom Aufrufer.
 *
 * DIE REIHENFOLGE IST DER GANZE PUNKT, und sie ist dieselbe wie in
 * App\Helper\ViewHelper::tHtml(): Erst wird der KATALOGTEXT maskiert, dann
 * werden die Werte eingesetzt. Andersherum verloere ein uebergebenes
 * "<strong>" seine spitzen Klammern und stuende sichtbar in der Seite.
 *
 * WAS DER AUFRUFER SCHULDET: Jeder uebergebene Wert geht UNMASKIERT in die
 * Seite. Wer fremden Text einsetzt, maskiert ihn selbst - genau wie in PHP.
 *
 * @param {string} schluessel
 * @param {Object} [werte] Fertige HTML-Schnipsel
 * @returns {string} HTML
 */
window.webrtcApp.tHtml = function (schluessel, werte) {
    const i18n = window.webrtcApp.i18n;
    const text = i18n.roh(schluessel);

    if (typeof text !== 'string') return i18n.esc(schluessel);

    return i18n.einsetzen(i18n.esc(text), werte);
};

/**
 * Der Text zu einem Schluessel in der Form, die zu n passt.
 *
 * n WIRD UEBERGEBEN UND NICHT NUR EINGESETZT. Der Grund steht ausfuehrlich
 * in App\Helper\I18n::plural(): "1 Nachricht" und "2 Nachrichten" sind zwei
 * Saetze und nicht einer mit einer anderen Zahl darin.
 *
 * {n} steht immer zur Verfuegung; ein uebergebenes 'n' hat Vorrang.
 *
 * @param {string} schluessel
 * @param {number} n
 * @param {Object} [werte]
 * @returns {string}
 */
window.webrtcApp.plural = function (schluessel, n, werte) {
    const i18n = window.webrtcApp.i18n;
    const roh  = i18n.roh(schluessel);

    if (roh === null || roh === undefined) return schluessel;

    let text;
    if (typeof roh === 'object') {
        const form = i18n.pluralForm(i18n.lang(), n);
        // Faellt auf 'other' zurueck: Ein Katalog, dem eine Form fehlt, soll
        // den Satz nicht verlieren.
        text = (typeof roh[form] === 'string') ? roh[form] : roh.other;
        if (typeof text !== 'string') return schluessel;
    } else if (typeof roh === 'string') {
        // Ein Schluessel ohne Formen. Kommt vor, wenn ein Text erst spaeter
        // zaehlbar wurde - dann ist er wenigstens da.
        text = roh;
    } else {
        return schluessel;
    }

    const alle = Object.assign({ n: n }, werte || {});
    return i18n.einsetzen(text, alle);
};
