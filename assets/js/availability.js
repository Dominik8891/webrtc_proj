window.webrtcApp = window.webrtcApp || {};

/**
 * Der Bereitschaftsschalter: "ich bin bereit zu fuehren" - und zurueck.
 *
 * WORUM ES GEHT
 * -------------
 * Angemeldet zu sein und verfuegbar zu sein waren dasselbe, und das war ein
 * Nebeneffekt und keine Entscheidung: Wer die Seite offen liess, stand gruen
 * auf der Karte. Wer sie ueber Nacht offen liess, wurde nachts angerufen.
 *
 * Jetzt sind es zwei Dinge:
 *
 *   Der Heartbeat (assets/js/signaling.js) meldet "ein Browser dieses Kontos
 *   laeuft". Er laeuft weiter wie bisher und sagt nichts darueber, ob jemand
 *   fuehren will.
 *
 *   DIESER SCHALTER sagt "ich fuehre jetzt". Nur damit steht der Standort
 *   gruen auf der Karte, und nur damit laesst der Server einen Anruf
 *   ueberhaupt durch (App\Controller\WebRTCController::callRoles).
 *
 * WIE DIE BEREITSCHAFT ENDET
 * --------------------------
 * Auf drei Wegen, und alle drei sind hier oder auf dem Server verdrahtet:
 *
 *   1. Der Guide legt den Schalter um            -> toggle()
 *   2. Die Seite wird geschlossen                -> bindPageClose()
 *   3. Die Frist laeuft ohne Bedienung ab        -> der Server; dieser
 *      Schalter zeigt es an und meldet es, damit es nicht unbemerkt passiert
 *
 * Das Abmelden endet sie ebenfalls, aber das entscheidet der Server allein
 * (App\Controller\LoginController::handleLogout) - hier waere darauf kein
 * Verlass, weil das Abmelden ein normaler Verweis ist.
 *
 * DIE UHR DES SERVERS ENTSCHEIDET
 * -------------------------------
 * Angezeigt wird lokal heruntergezaehlt, damit die Restzeit jede Sekunde
 * stimmt, ohne dafuer zu fragen. Verbindlich ist aber die Antwort des Servers:
 * Jeder Heartbeat bringt die verbleibenden Sekunden mit, und sync() zieht die
 * Anzeige darauf nach. Ein Browser mit falsch gestellter Uhr oder ein
 * schlafender Rechner verschiebt damit nichts.
 */
window.webrtcApp.availability = {

    /** Verbleibende Sekunden, so wie es der Schalter gerade anzeigt. */
    seconds: 0,

    /** Timer der Sekundenanzeige. */
    ticker: null,

    /** Laeuft gerade eine Anfrage? Verhindert Doppelklicks. */
    busy: false,

    /**
     * Hat der Nutzer die Anwendung seit dem letzten Heartbeat BEDIENT?
     *
     * Das ist die Angabe, die eine laufende Bereitschaft verlaengert - nicht
     * der Heartbeat selbst. Gesetzt wird sie von den Ereignissen in
     * bindActivity(), gelesen und zurueckgesetzt von takeActivity() beim
     * Absenden des naechsten Heartbeats.
     */
    hadActivity: false,

    /**
     * Ab wann die Anzeige warnt (Sekunden).
     *
     * Fuenf Minuten sind genug, um eine begonnene Fuehrung zu beenden und
     * nachzulegen, und selten genug, dass die Warnfarbe nicht zum Normalbild
     * wird.
     */
    WARN_SECONDS: 300,

    /**
     * Haengt den Schalter ein.
     *
     * Ohne Schalter im Dokument passiert nichts: Wer keine Standorte anbietet,
     * bekommt ihn vom Server nicht geliefert (App\Helper\ViewHelper), und dann
     * ist hier auch nichts zu tun.
     */
    init() {
        const knopf = document.getElementById('availability-toggle');
        if (!knopf) return;

        // Der Ausgangswert kommt vom Server und steht am Element. Er wird
        // NICHT aus einer lokalen Ablage gelesen: Die Bereitschaft gehoert
        // dem Konto und nicht diesem Browser.
        this.seconds = parseInt(knopf.getAttribute('data-seconds'), 10) || 0;

        knopf.addEventListener('click', () => this.toggle());

        this.bindActivity();
        this.bindPageClose();
        this.render();
        this.startTicker();
    },

    /**
     * Schaltet um: bereit -> nicht bereit und zurueck.
     *
     * Ohne Rueckfrage. Beides ist ein Klick und sofort umkehrbar; ein Dialog
     * waere im Weg, gerade beim Ausschalten - wer nicht mehr kann, will nicht
     * erst etwas bestaetigen.
     */
    toggle() {
        if (this.busy) return;
        const einschalten = !(this.seconds > 0);
        this.send(einschalten, true);
    },

    /**
     * Schickt den gewuenschten Zustand an den Server.
     *
     * @param {boolean} ready   true = bereit, false = beenden
     * @param {boolean} melden  Rueckmeldung an den Nutzer anzeigen?
     */
    send(ready, melden) {
        this.busy = true;

        fetch('index.php?act=set_availability', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ready: !!ready })
        })
        .then(antwort => antwort.json())
        .then(daten => {
            this.busy = false;

            // Uebernommen wird, was der SERVER sagt, und nicht, was angefragt
            // wurde. Weist er ab - fehlendes Recht, abgelaufene Sitzung -,
            // steht der Schalter danach auf dem wahren Zustand und nicht auf
            // dem gewuenschten.
            //
            // "gewollt" heisst: Diese Aenderung hat der Nutzer selbst
            // ausgeloest. sync() meldet dann keinen Ablauf - beim Ausschalten
            // waere "Ihre Bereitschaft ist abgelaufen" schlicht falsch, und
            // die richtige Rueckmeldung steht gleich darunter.
            this.sync(daten && daten.available_seconds, true);

            if (!melden) return;
            if (this.seconds > 0) {
                window.webrtcApp.notify.success(
                    'Sie sind jetzt als Guide anrufbar – ' + this.restText() + '.'
                );
            } else {
                window.webrtcApp.notify.info(
                    'Bereitschaft beendet. Ihre Standorte sind nicht mehr anrufbar.'
                );
            }
        })
        .catch(() => {
            this.busy = false;
            if (melden) {
                window.webrtcApp.notify.error(
                    'Die Bereitschaft konnte nicht geändert werden. Bitte erneut versuchen.'
                );
            }
        });
    },

    /**
     * Zieht die Anzeige auf den Wert des Servers nach.
     *
     * Aufgerufen nach jedem Heartbeat (assets/js/signaling.js) und nach jedem
     * Schaltvorgang. Faellt der Wert dabei von "laeuft" auf 0, ist die Frist
     * abgelaufen - und DAS MUSS GEMELDET WERDEN: Ein Guide, der es nicht
     * merkt, glaubt weiter, er sei anrufbar. Genau darum steht der Schalter in
     * der Kopfleiste und nicht in den Einstellungen.
     *
     * Gemeldet wird der UEBERGANG und nicht der Zustand: Der Heartbeat liefert
     * danach alle zehn Sekunden weiter 0, und daraus darf keine Meldungskette
     * werden.
     *
     * @param {number|undefined} sekunden Antwort des Servers
     * @param {boolean} [gewollt] Hat der Nutzer die Aenderung selbst
     *                            ausgeloest? Dann ist es kein Ablauf, und
     *                            send() gibt die passende Rueckmeldung.
     */
    sync(sekunden, gewollt) {
        const knopf = document.getElementById('availability-toggle');
        if (!knopf) return;

        const neu = parseInt(sekunden, 10);
        if (isNaN(neu)) return;

        const liefVorher = this.seconds > 0;
        this.seconds = Math.max(0, neu);

        if (!gewollt && liefVorher && this.seconds === 0) {
            window.webrtcApp.notify.info(
                'Ihre Bereitschaft ist abgelaufen – Sie sind nicht mehr anrufbar. '
                + 'Zum Weiterführen wieder auf „Bereit“ stellen.'
            );
        }

        this.render();
    },

    /**
     * Nimmt die gesammelte Bedienung ab und setzt den Merker zurueck.
     *
     * Vom Heartbeat aufgerufen. Zurueckgesetzt wird beim Abholen und nicht
     * beim Empfang der Antwort: Sonst zaehlte eine Bedienung, die waehrend der
     * laufenden Anfrage passiert, nicht mehr fuer den naechsten Takt.
     *
     * @returns {boolean}
     */
    takeActivity() {
        const war = this.hadActivity;
        this.hadActivity = false;
        return war;
    },

    /**
     * Merkt sich echte Bedienung.
     *
     * NUR ECHTE: Zeigerdruck, Tastendruck, Beruehrung, Radbewegung. Kein
     * Mauszeiger-Bewegen (das loest ein streifender Ärmel aus) und kein
     * Sichtbarwerden des Tabs (das macht ein Fensterwechsel).
     *
     * Alle Ereignisse haengen am Dokument und in der Erfassungsphase
     * (capture): So werden sie auch dann gezaehlt, wenn ein Handler weiter
     * innen die Weitergabe abbricht - etwa das Steuerkreuz der Call-Ansicht.
     *
     * "passive" sagt dem Browser, dass hier nichts abgebrochen wird; ohne den
     * Hinweis verzoegern manche Browser das Scrollen, solange ein
     * Beruehrungshandler haengt.
     */
    bindActivity() {
        const merken = () => { this.hadActivity = true; };
        ['pointerdown', 'keydown', 'touchstart', 'wheel'].forEach(art => {
            document.addEventListener(art, merken, { capture: true, passive: true });
        });
    },

    /**
     * Beendet die Bereitschaft, wenn die Seite geschlossen wird.
     *
     * WARUM sendBeacon UND NICHT fetch: Beim Schliessen bricht der Browser
     * laufende Anfragen ab. sendBeacon ist genau dafuer gemacht - der Browser
     * uebernimmt die Zustellung, auch wenn das Dokument schon weg ist.
     *
     * WARUM 'pagehide' UND NICHT 'beforeunload': Auf Mobilgeraeten wird
     * beforeunload haeufig gar nicht ausgeloest; pagehide ist der Ereignisname,
     * den auch iOS zuverlaessig meldet.
     *
     * WARUM NICHT BEI JEDEM SEITENWECHSEL: Innerhalb der Anwendung ist jeder
     * Klick auf einen Verweis ein Seitenwechsel - dabei darf die Bereitschaft
     * nicht abfallen. Unterschieden wird ueber persisted und darueber, dass
     * der Server beim naechsten Seitenaufbau ohnehin den wahren Zustand
     * liefert: Geht die Seite in den Zwischenspeicher (persisted), kommt sie
     * wieder, und es wird nichts beendet.
     *
     * Bleibt in einem Grenzfall die Bereitschaft doch stehen - ein
     * abgestuerzter Browser schickt kein Beacon -, faengt das die Frist auf:
     * Ohne Bedienung laeuft sie ab. Und gruen auf der Karte steht ein Standort
     * nur, wenn AUCH der Heartbeat frisch ist
     * (App\Model\Location::AVAILABILITY_SQL) - ein weggefallener Browser
     * verschwindet also binnen 45 Sekunden, ohne dass irgendetwas geschrieben
     * werden muesste.
     */
    bindPageClose() {
        window.addEventListener('pagehide', (e) => {
            if (e.persisted) return;
            if (!(this.seconds > 0)) return;
            if (!navigator.sendBeacon) return;

            // Als Blob mit Inhaltstyp, damit auf der Serverseite dasselbe
            // ankommt wie bei fetch: ein JSON-Rumpf, den
            // UserController::setAvailability aus php://input liest.
            navigator.sendBeacon(
                'index.php?act=set_availability',
                new Blob([JSON.stringify({ ready: false })], { type: 'application/json' })
            );
        });
    },

    /**
     * Laesst die Restzeit sekundenweise ablaufen.
     *
     * Der Zaehler laeuft LOKAL und ist nur die Anzeige. Verbindlich ist die
     * Antwort des Servers, die jeder Heartbeat mitbringt (sync). Ohne den
     * lokalen Zaehler stuende die Zahl zehn Sekunden lang still und spraenge
     * dann - mit ihm laeuft sie sichtbar ab, und genau das soll der Guide
     * bemerken.
     *
     * Bei 0 wird hier NICHT gemeldet: Das tut sync() anhand der Antwort des
     * Servers. Sonst gaebe es zwei Meldungen fuer ein Ereignis, und die
     * lokale koennte falsch sein, wenn der Rechner geschlafen hat.
     */
    startTicker() {
        if (this.ticker) clearInterval(this.ticker);
        this.ticker = setInterval(() => {
            if (this.seconds > 0) {
                this.seconds--;
                this.render();
            }
        }, 1000);
    },

    /**
     * Schreibt den Zustand an den Knopf.
     *
     * Drei Dinge werden nachgehalten, und alle drei sind noetig:
     *   die Klasse       fuer das Auge,
     *   aria-pressed     fuer Vorleseprogramme,
     *   das title-Feld   damit klar ist, was ein Klick tut.
     */
    render() {
        const knopf = document.getElementById('availability-toggle');
        if (!knopf) return;

        const text = document.getElementById('availability-text');
        const rest = document.getElementById('availability-rest');
        const an   = this.seconds > 0;

        knopf.classList.toggle('app-ready--on', an);
        knopf.classList.toggle('app-ready--soon', an && this.seconds <= this.WARN_SECONDS);
        knopf.setAttribute('aria-pressed', an ? 'true' : 'false');
        knopf.setAttribute('data-seconds', String(this.seconds));
        knopf.setAttribute('title', an
            ? 'Sie sind als Guide anrufbar (' + this.restText()
              + '). Klicken beendet die Bereitschaft.'
            : 'Sie sind nicht anrufbar. Klicken stellt Sie auf bereit.');

        if (text) text.textContent = an ? 'Bereit' : 'Nicht bereit';

        // Die Restzeit steht mit einem Trennpunkt am Text, nicht in Klammern:
        // In der schmalen Kopfleiste soll sie wie eine Fortsetzung gelesen
        // werden ("Bereit · noch 1:47 Std") und nicht wie eine Nebenbemerkung.
        if (rest) rest.textContent = an ? '· ' + this.restText() : '';
    },

    /**
     * Die Restzeit als Text.
     *
     * Die Einheit wechselt mit der Groessenordnung, weil sich mit ihr auch
     * aendert, was der Guide wissen will: Bei zwei Stunden interessiert die
     * Stunde, in der letzten Minute die Sekunde.
     *
     * @returns {string} z. B. "noch 1:47 Std", "noch 12 Min", "noch 40 Sek"
     */
    restText() {
        const s = this.seconds;
        if (s <= 0)  return 'nicht bereit';
        if (s < 60)  return 'noch ' + s + ' Sek';
        if (s < 3600) return 'noch ' + Math.floor(s / 60) + ' Min';

        const std = Math.floor(s / 3600);
        const min = Math.floor((s % 3600) / 60);
        return 'noch ' + std + ':' + String(min).padStart(2, '0') + ' Std';
    }
};

// Eingehaengt wird beim Seitenaufbau. Der Schalter kommt fertig vom Server;
// hier wird er lediglich bedienbar gemacht und beginnt zu zaehlen.
window.addEventListener('DOMContentLoaded', function() {
    window.webrtcApp.availability.init();
});
