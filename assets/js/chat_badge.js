window.webrtcApp = window.webrtcApp || {};

/**
 * Der Nachrichtenzaehler der Kopfleiste.
 *
 * WORUM ES GEHT
 * -------------
 * Ein Guide bietet Standorte an, also bekommt er Rueckfragen: "Wo genau ist
 * der Treffpunkt?", "Ginge auch Samstag frueh?". Bisher sah er sie nur, wenn
 * zufaellig gerade ein Chatfenster offen war - die Fenster baut
 * assets/js/ui_chat.js, und wer die Seite gewechselt oder den Tab im
 * Hintergrund liegen hatte, erfuhr nichts.
 *
 * Der Zaehler steht deshalb dort, wo auch der Anfragenzaehler steht: in der
 * Kopfleiste, auf jeder Seite. Er sagt dasselbe wie jener, nur ueber etwas
 * anderes - "hier wartet etwas auf dich".
 *
 * ER GILT FUER BEIDE SEITEN. Nicht nur der Guide bekommt Nachrichten: Der
 * Kunde bekommt die Antwort auf seine Frage, und die soll er genauso wenig
 * verpassen.
 *
 * EINE ZAHL, KEINE DREI. Beim Anfragenzaehler sind es drei, weil dort drei
 * verschiedene Dinge warten (eingehend, zugesagt, laufend). Hier wartet nur
 * eines: ungelesene Nachrichten.
 *
 * WAS DIESES MODUL NICHT TUT
 * --------------------------
 * Es holt nichts von selbst. Die Zahl faehrt mit der Antwort des Heartbeats
 * mit (assets/js/signaling.js) - eine eigene Schleife daneben waere derselbe
 * Weg noch einmal, fuer eine Auskunft, die sich seltener aendert als die
 * Bereitschaft.
 *
 * Und es zeigt keine Nachrichten an. Das tun die Fenster in ui_chat.js; ein
 * Klick auf den Zaehler fuehrt auf die Chatuebersicht, damit auch dann etwas
 * zu sehen ist, wenn gerade kein Fenster offen steht.
 */
window.webrtcApp.chatBadge = {

    /** Zuletzt bekannte Zahl. */
    counts: { unread: 0 },

    /**
     * Uebernimmt den Startwert des Servers und zeichnet den Zaehler.
     *
     * Der Startwert steht in window.chatCounts (App\Helper\ViewHelper) und
     * wird NICHT aus einer lokalen Ablage gelesen: Nachrichten gehoeren zum
     * Konto und nicht zu diesem Browser.
     *
     * Wer nicht angemeldet ist, hat kein Element in der Leiste - dann ist
     * hier nichts zu tun.
     */
    init() {
        if (window.chatCounts) {
            this.counts = { unread: parseInt(window.chatCounts.unread, 10) || 0 };
        }
        this.render();
    },

    /**
     * Uebernimmt die Zahl aus der Antwort des Heartbeats.
     *
     * MELDET NUR DEN ZUWACHS - dieselbe Regel wie beim Anfragenzaehler. Eine
     * Meldung bei jedem Takt waere Laerm: Die Zahl steht ohnehin in der
     * Leiste. Gemeldet wird, was NEU dazugekommen ist.
     *
     * NICHT GEMELDET WIRD, WENN EIN FENSTER OFFEN IST. ui_chat.js klingelt
     * dann selbst und markiert die Nachricht gegebenenfalls sofort als
     * gelesen; zwei Toene fuer dieselbe Nachricht sind einer zu viel.
     *
     * @param {Object} zahlen  { unread }
     */
    sync(zahlen) {
        if (!zahlen) return;

        const neu = { unread: parseInt(zahlen.unread, 10) || 0 };
        const mehr = neu.unread > this.counts.unread;

        this.counts = neu;
        this.render();

        if (mehr && !this.fensterOffen()) {
            window.webrtcApp.notify.info(window.webrtcApp.t('chat.neue_nachricht'));
            this.ton();
        }
    },

    /**
     * Steht gerade ein Chatfenster offen?
     *
     * Gefragt wird der Container, den ui_chat.js anlegt. Ohne ihn gibt es
     * kein Fenster - und dann ist dieser Zaehler die einzige Stelle, an der
     * eine neue Nachricht auffaellt.
     *
     * @returns {boolean}
     */
    fensterOffen() {
        const container = document.getElementById('chat-popup-container');
        return !!(container && container.children.length > 0);
    },

    /**
     * Der kurze Hinweiston.
     *
     * Derselbe wie bei einer Anfrage und mit denselben zwei Angaben: NICHT in
     * der Schleife und leise. Die Vorgabe von sound.play() ist loop=true - ein
     * Dauerton fuer eine Nachricht waere ein Klingeln, das niemand abstellen
     * kann.
     */
    ton() {
        const sound = window.webrtcApp.sound;
        if (sound && typeof sound.play === 'function') {
            sound.play('notification_sound_msg', false, 0.25);
        }
    },

    /**
     * Zeichnet den Zaehler neu.
     *
     * Der Titel wird nach derselben Regel gebaut wie serverseitig
     * (App\Helper\ViewHelper::chatBadge) - die Zahl allein sagt nicht, WAS
     * wartet.
     */
    render() {
        const knopf = document.getElementById('chats-badge');
        if (!knopf) return;

        const offen = this.counts.unread;

        knopf.setAttribute('data-unread', String(offen));
        knopf.classList.toggle('app-chats--on', offen > 0);
        // Derselbe Schluessel wie in der Kopfleiste des Servers
        // (App\Helper\ViewHelper): Es ist derselbe Knopf mit derselben
        // Zahl - nur eine Sekunde spaeter.
        knopf.setAttribute('title', offen > 0
            ? window.webrtcApp.plural('kopf.nachrichten.ungelesen', offen)
            : window.webrtcApp.t('kopf.nachrichten.titel'));

        const zahl = document.getElementById('chats-count');
        if (zahl) {
            zahl.textContent = String(offen);
            zahl.hidden = (offen === 0);
        }
    },
};

window.addEventListener('DOMContentLoaded', function() {
    window.webrtcApp.chatBadge.init();
});
