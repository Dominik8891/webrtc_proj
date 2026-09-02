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
 * Beim Laden der Seite wird NICHTS gesetzt: Das Attribut steht bereits im
 * ausgelieferten HTML (App\Helper\ViewHelper::output). Wuerde es hier
 * nachgetragen, blitzte bei jedem Seitenwechsel kurz das helle Profil auf.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.themeSwitch = {

    /**
     * Setzt das Profil in der Anzeige.
     *
     * @param {string} profil
     */
    apply(profil) {
        document.documentElement.setAttribute('data-theme', profil);
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
                    window.webrtcApp.notify.success('Farbprofil gespeichert.');
                    return;
                }
                this.revert(bereich, bestaetigt,
                    (antwort && antwort.error) || 'Farbprofil konnte nicht gespeichert werden.');
            })
            .fail(() => {
                this.revert(bereich, bestaetigt,
                    'Farbprofil konnte nicht gespeichert werden. Bitte später erneut versuchen.');
            });
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
});
