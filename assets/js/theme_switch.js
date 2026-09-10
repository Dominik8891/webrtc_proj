/**
 * Umschalten des Farbprofils auf der Kontoseite.
 *
 * ABLAUF
 * ------
 * 1. Klick auf ein Profil setzt data-theme am <html>-Element. Das wirkt
 *    sofort - alle Farben stehen als CSS-Variablen, es wird nichts neu
 *    geladen.
 * 2. Danach geht die Wahl an den Server und wird am Konto gespeichert.
 *
 * In dieser Reihenfolge, nicht umgekehrt: Die Anzeige darf nicht auf das
 * Netz warten. Geht das Speichern schief, wird die Anzeige zurueckgedreht
 * und gesagt, was los ist - sonst sieht der Nutzer ein Profil, das beim
 * naechsten Anmelden nicht mehr da ist.
 *
 * Beim Laden der Seite wird hier NICHTS gesetzt: Das erledigt schon das
 * kleine Skript im <head> (App\Helper\Theme::bootScript). Wuerde diese
 * Datei es nachtragen, blitzte bei jedem Seitenwechsel kurz das helle Profil
 * auf - sie wird erst geladen, wenn die Seite bereits gezeichnet wird.
 *
 * WARUM DIE WAHL AUCH IM BROWSER LIEGT
 * ------------------------------------
 * Das Konto kennt die Anwendung erst nach der Anmeldung. Login,
 * Registrierung und Passwort-vergessen waeren sonst immer hell, auch fuer
 * jemanden, der Dunkel gewaehlt hat. Deshalb wird die Wahl zusaetzlich im
 * Browser gemerkt. Beim Anmelden gewinnt weiterhin das Konto und schreibt
 * den lokalen Wert um - das entscheidet bootScript(), nicht diese Datei.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.themeSwitch = {

    /**
     * Setzt das Profil in der Anzeige.
     *
     * @param {string} profil
     */
    /** Schluessel im Browserspeicher. Gleichlautend in App\Helper\Theme. */
    STORAGE_KEY: 'webrtcapp.theme',

    apply(profil) {
        document.documentElement.setAttribute('data-theme', profil);
        this.remember(profil);
    },

    /**
     * Merkt die Wahl im Browser.
     *
     * Damit gilt sie auch dort, wo niemand angemeldet ist. Scheitert das
     * Speichern - privates Fenster, gesperrter Speicher -, ist das kein
     * Fehler, den der Nutzer sehen muesste: Die Farbe steht ja schon, sie
     * ueberlebt nur den naechsten Aufruf nicht.
     *
     * @param {string} profil
     */
    remember(profil) {
        try {
            localStorage.setItem(this.STORAGE_KEY, profil);
        } catch (e) {
            /* absichtlich still - siehe oben */
        }
    },

    /**
     * Haengt sich an die Auswahl auf der Kontoseite.
     */
    init() {
        const bereich = document.getElementById('theme-choices');
        if (!bereich) return;

        // Das zuletzt bestaetigte Profil. Es ist der Stand, auf den bei einem
        // Fehler zurueckgedreht wird - nicht das, was gerade angezeigt wird.
        let bestaetigt = document.documentElement.getAttribute('data-theme');

        bereich.addEventListener('change', (e) => {
            const feld = e.target;
            if (!feld || feld.name !== 'theme') return;

            const gewaehlt = feld.value;
            this.apply(gewaehlt);

            $.ajax({
                url: 'index.php?act=set_theme&theme=' + encodeURIComponent(gewaehlt),
                method: 'GET',
                dataType: 'json'
            })
            .done((antwort) => {
                if (antwort && antwort.success) {
                    bestaetigt = gewaehlt;
                    window.webrtcApp.notify.success(window.webrtcApp.t('konto.farbprofil.gespeichert'));
                    return;
                }
                this.revert(bereich, bestaetigt,
                    (antwort && antwort.error) || window.webrtcApp.t('konto.farbprofil.fehler'));
            })
            .fail(() => {
                this.revert(bereich, bestaetigt,
                    window.webrtcApp.t('konto.farbprofil.fehler_netz'));
            });
        });
    },

    /**
     * Die vier Punkte in der Kopfleiste der Landingpage.
     *
     * DER UNTERSCHIED ZUR AUSWAHL AUF DER KONTOSEITE ist nicht das Aussehen,
     * sondern WER hier steht: meist ein Gast. Ein Gast hat kein Konto, an dem
     * sich etwas speichern liesse, und die Route set_theme verlangt das Recht
     * user.settings - ein Aufruf von hier aus bekaeme eine Abfuhr und der
     * Besucher eine Fehlermeldung fuer etwas, das gerade sichtbar funktioniert
     * hat.
     *
     * Deshalb: anwenden und im Browser merken (apply() erledigt beides), und
     * NUR wer angemeldet ist, schickt die Wahl zusaetzlich ans Konto - sonst
     * stuende sie beim naechsten Aufruf wieder auf dem Kontowert, denn das
     * Konto gewinnt (App\Helper\Theme::bootScript).
     *
     * DIE MARKIERUNG SETZT DIESE DATEI UND NICHT DER SERVER: Fuer einen Gast
     * entscheidet erst der Browser, welches Profil gilt - aus dem lokalen
     * Speicher oder aus der Vorgabe des Betriebssystems. Der Server weiss es
     * nicht und duerfte es nicht behaupten.
     */
    initMini() {
        const bereich = document.getElementById('theme-mini');
        if (!bereich) return;

        this.markiere(bereich);

        bereich.addEventListener('click', (e) => {
            const knopf = e.target.closest('[data-theme-value]');
            if (!knopf) return;

            const gewaehlt = knopf.getAttribute('data-theme-value');
            this.apply(gewaehlt);
            this.markiere(bereich);

            // Nur angemeldet. Siehe oben - fuer einen Gast gaebe es hier eine
            // Abfuhr statt einer Speicherung.
            if (!window.isLoggedIn) return;

            $.ajax({
                url: 'index.php?act=set_theme&theme=' + encodeURIComponent(gewaehlt),
                method: 'GET',
                dataType: 'json'
            }).fail(() => {
                // Die Farbe steht bereits und bleibt auch stehen: Der lokale
                // Wert gilt bis zum naechsten Seitenaufruf. Gesagt wird es
                // trotzdem - sonst waere die Wahl beim naechsten Anmelden
                // wieder weg, ohne dass jemand weiss, warum.
                window.webrtcApp.notify.error(
                    window.webrtcApp.t('konto.farbprofil.fehler_netz'));
            });
        });
    },

    /**
     * Setzt die Markierung auf den Punkt, dessen Profil gerade gilt.
     *
     * Gelesen wird das Attribut am <html>-Element - dieselbe Quelle, aus der
     * auch die Farben kommen. Eine eigene Merkvariable waere eine zweite
     * Wahrheit neben der einen, die ohnehin dasteht.
     *
     * @param {HTMLElement} bereich
     */
    markiere(bereich) {
        const gilt = document.documentElement.getAttribute('data-theme');

        bereich.querySelectorAll('[data-theme-value]').forEach(knopf => {
            const an = knopf.getAttribute('data-theme-value') === gilt;
            knopf.classList.toggle('app-theme-dot--on', an);
            knopf.setAttribute('aria-pressed', an ? 'true' : 'false');
        });
    },

    /**
     * Dreht Anzeige und Auswahl auf den zuletzt bestaetigten Stand zurueck.
     *
     * @param {HTMLElement} bereich
     * @param {string} profil
     * @param {string} meldung
     */
    revert(bereich, profil, meldung) {
        this.apply(profil);
        const feld = bereich.querySelector('input[value="' + profil + '"]');
        if (feld) feld.checked = true;
        window.webrtcApp.notify.error(meldung);
    }
};

$(document).ready(function () {
    window.webrtcApp.themeSwitch.init();
    window.webrtcApp.themeSwitch.initMini();
});
