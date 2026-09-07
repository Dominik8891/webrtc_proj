window.webrtcApp = window.webrtcApp || {};

/**
 * Die laufende Fuehrung: wieder einsteigen oder beenden.
 *
 * DER BEFUND
 * ----------
 * Auflegen ist zweideutig. Es kann heissen "wir sind fertig" - oder "das Netz
 * ist weg". Vorher galt jedes Auflegen als das Ende der Fuehrung, und
 * gleichzeitig blieb der Startknopf beim Kunden stehen, solange das
 * Anruffenster lief: Die Fuehrung war abgeschlossen und liess sich trotzdem
 * beliebig oft neu starten. Die Frage nach der Bewertung kam, waehrend der
 * Guide noch zurueck in die Leitung wollte.
 *
 * WAS JETZT GILT
 * --------------
 * Beendet wird AUSDRUECKLICH, und zwar vom GUIDE: Er ist vor Ort und weiss,
 * ob die Fuehrung vorbei ist oder ob er gerade durch einen Tunnel faehrt. Bis
 * dahin gilt sie als unterbrochen; beide Seiten koennen wieder einsteigen,
 * bis die Frist aus config/requests.php verstrichen ist. Erst nach dem
 * Beenden verschwindet der Startknopf, und erst dann wird die Bewertung
 * faellig.
 *
 * DIESES MODUL IST DIE ANDERE HAELFTE VON review.js - spiegelverkehrt: Dort
 * wird der Kunde gefragt, hier der Guide. Beide haengen am selben Heartbeat,
 * beide sind eine Karte am Rand und kein Dialog, und beide ueberleben damit
 * das Neuladen der Seite, das auf Telefonen nach jedem Gespraech kommt
 * (assets/js/rtc.js).
 *
 * DREI KNOEPFE, und die Reihenfolge ist die Wahrscheinlichkeit:
 *
 *   Wieder einsteigen  Der haeufigste Fall nach einem Abbruch. Der Guide ruft
 *                      dabei SELBST an - die Rollen entscheidet die Zeile der
 *                      laufenden Fuehrung und nicht die Frage, wer gewaehlt
 *                      hat (App\Controller\WebRTCController::callRoles).
 *   Fuehrung beenden   Der Abschluss. Danach ist sie zu.
 *   Spaeter            Weder noch. Die Karte geht weg, die Fuehrung bleibt
 *                      offen - der Zaehler in der Kopfleiste fuehrt zurueck
 *                      auf die Anfragenseite, und die Frist schliesst sie
 *                      irgendwann von selbst.
 *
 * WAS DIESES MODUL NICHT TUT
 * --------------------------
 * Es entscheidet nichts. Ob eine Fuehrung noch laeuft und ob sie sich beenden
 * laesst, steht in der WHERE-Klausel des Servers
 * (App\Model\TourRequest::finish); ein Knopf, den es hier nicht gibt, ist
 * keine Absicherung.
 */
window.webrtcApp.tour = {

    /**
     * Schluessel, unter dem die weggeklickten Fuehrungen liegen.
     *
     * IM BROWSER UND NICHT IM KONTO - dieselbe Ueberlegung wie beim
     * Ueberspringen der Bewertung: "Ich will jetzt nicht" ist eine Aussage
     * ueber diesen Moment und dieses Geraet. Die Fuehrung bleibt offen, und
     * der Zaehler in der Kopfleiste sagt es weiterhin.
     */
    SKIP_KEY: 'tourSkipped',

    /** Wie viele weggeklickte Kennungen gemerkt werden. */
    SKIP_MAX: 20,

    /** Laeuft gerade ein Beenden? Verhindert Doppelklicks. */
    busy: false,

    /** Die Kennung der Fuehrung, deren Karte gerade offen ist. */
    offen: null,

    /**
     * Haengt die Knoepfe ein.
     *
     * EIN HANDLER AM DOKUMENT: Die Karte entsteht erst zur Laufzeit, und die
     * Zeilen der Anfragenseite werden bei jedem Takt neu gebaut.
     */
    init() {
        document.addEventListener('click', (e) => {
            const beenden = e.target.closest('.tour-finish');
            if (beenden) {
                e.preventDefault();
                this.beende(parseInt(beenden.getAttribute('data-id'), 10) || 0);
                return;
            }

            const zurueck = e.target.closest('.tour-rejoin');
            if (zurueck) {
                e.preventDefault();
                this.steigeEin(zurueck);
            }
        });
    },

    // -----------------------------------------------------------------
    // Die Karte nach dem Auflegen
    // -----------------------------------------------------------------

    /**
     * Uebernimmt die laufende Fuehrung aus der Antwort des Heartbeats.
     *
     * @param {Object|null} fuehrung Die offene Fuehrung dieses Guides, oder null
     */
    sync(fuehrung) {
        // KEINE OFFENE FUEHRUNG MEHR heisst: Die Karte hat sich erledigt -
        // beendet, abgelaufen oder von der anderen Seite fortgesetzt. Sie
        // verschwindet dann von selbst, ohne dass jemand klicken muss.
        if (!fuehrung) {
            if (this.offen !== null) this.schliesse();
            return;
        }

        const id = parseInt(fuehrung.id, 10) || 0;
        if (id < 1) return;

        if (this.offen === id) return;
        if (this.weggeklickt(id)) return;
        // Waehrend des Gespraechs steht die Fuehrung nicht zur Debatte. Genau
        // dann laeuft sie ja.
        if (this.imGespraech()) return;

        this.oeffne(fuehrung);
    },

    /**
     * Laeuft gerade ein Gespraech - oder steht die Anrufansicht offen?
     *
     * Dieselbe Pruefung wie in review.js und aus demselben Grund: Zwischen
     * dem Ende der Verbindung und dem Abbau der Ansicht liegen ein paar
     * Sekunden.
     *
     * @returns {boolean}
     */
    imGespraech() {
        const state = window.webrtcApp.state;
        return !!(state && state.isCallActive)
            || document.body.classList.contains('call-active');
    },

    /**
     * Baut die Karte und zeigt sie.
     *
     * @param {Object} fuehrung Zeile aus App\Model\TourRequest::runningForGuide
     */
    oeffne(fuehrung) {
        this.schliesse();
        this.offen = parseInt(fuehrung.id, 10) || 0;

        const karte = document.createElement('section');
        // Die FLAECHE kommt aus .app-ask (theme.css) - dieselbe wie bei der
        // Frage nach der Bewertung.
        karte.className = 'app-ask tour-ask';
        karte.id = 'tour-ask';
        // Sie meldet sich, aber sie unterbricht nicht.
        karte.setAttribute('role', 'region');
        karte.setAttribute('aria-live', 'polite');
        karte.setAttribute('aria-label', 'Laufende Führung');
        karte.innerHTML = this.karteHtml(fuehrung);

        document.body.appendChild(karte);
    },

    /**
     * Nimmt die Karte weg.
     */
    schliesse() {
        const alt = document.getElementById('tour-ask');
        if (alt) alt.remove();
        this.offen = null;
    },

    /**
     * Der Inhalt der Karte.
     *
     * DER SATZ SAGT, WORUM ES GEHT - naemlich darum, dass die Fuehrung NOCH
     * LAEUFT. Das ist die Auskunft, die dem Guide fehlte: Er hat aufgelegt und
     * hielt die Sache fuer erledigt.
     *
     * WIE LANGE NOCH steht dabei, wenn der Server es mitgeliefert hat
     * (rejoin_in). Ohne diese Angabe waere "Sie können wieder einsteigen" ein
     * Versprechen ohne Frist - und der Guide erfuehre erst beim Klicken, dass
     * es zu spaet ist.
     *
     * @param {Object} fuehrung
     * @returns {string} HTML
     */
    karteHtml(fuehrung) {
        const wer  = fuehrung.partner_name ? this.esc(fuehrung.partner_name) : 'Ihrem Kunden';
        const titel = String(fuehrung.title || '').trim();
        const was  = titel ? ' – <span class="tour-ask__what">' + this.esc(titel) + '</span>' : '';
        const id   = parseInt(fuehrung.id, 10) || 0;

        const rest = this.restText(fuehrung.rejoin_in);

        return '<button type="button" class="app-ask__close" aria-label="Schließen">×</button>'
             + '<p class="app-ask__lead">Ihre Führung mit <strong>' + wer + '</strong>' + was
             +   ' ist noch nicht beendet.</p>'
             + '<p class="tour-ask__note">Aufgelegt heißt nicht beendet: Solange die Führung '
             +   'offen ist, können Sie und Ihr Kunde wieder einsteigen'
             +   (rest !== '' ? ' – ' + this.esc(rest) : '') + '.</p>'
             + '<div class="app-ask__actions">'
             +   '<button type="button" class="btn btn-secondary btn-sm tour-ask__later">Später</button>'
             +   '<button type="button" class="btn btn-secondary btn-sm tour-rejoin"'
             +     ' data-userid="' + (parseInt(fuehrung.customer_user_id, 10) || 0) + '"'
             +     ' data-locationid="' + (parseInt(fuehrung.location_id, 10) || 0) + '"'
             +     '>Wieder einsteigen</button>'
             +   '<button type="button" class="btn btn-primary btn-sm tour-finish"'
             +     ' data-id="' + id + '">Führung beenden</button>'
             + '</div>'
             + '<p class="app-ask__foot">Erst nach dem Beenden ist die Führung abgeschlossen. '
             +   'Ihr Kunde kann sie dann nicht mehr neu starten und wird nach einer '
             +   'Bewertung gefragt.</p>';
    },

    /**
     * "noch etwa 24 Minuten" - oder nichts.
     *
     * DIE FRIST KOMMT FERTIG GERECHNET VOM SERVER (rejoin_in, in Sekunden).
     * Hier wird keine zweite Uhr befragt und keine Frist nachgebaut: Sie steht
     * in config/requests.php und wird in App\Model\TourRequest ausgewertet.
     *
     * Fehlt die Angabe, kam nie ein Auflegen an - dann laeuft die lange
     * Reissleine, und eine Zahl waere hier eine Erfindung.
     *
     * @param {*} sekunden
     * @returns {string}
     */
    restText(sekunden) {
        const s = parseInt(sekunden, 10);
        if (isNaN(s) || s <= 0) return '';

        const min = Math.round(s / 60);
        if (min < 1)  return 'noch weniger als eine Minute';
        if (min === 1) return 'noch etwa eine Minute';
        if (min < 60) return 'noch etwa ' + min + ' Minuten';

        const std = Math.round(min / 60);
        return 'noch etwa ' + (std === 1 ? 'eine Stunde' : std + ' Stunden');
    },

    // -----------------------------------------------------------------
    // Die Aktionen
    // -----------------------------------------------------------------

    /**
     * Beendet die Fuehrung.
     *
     * MIT RUECKFRAGE, und mit derselben wie ueberall sonst (notify.confirm):
     * Der Schritt laesst sich nicht zuruecknehmen, und er nimmt beiden Seiten
     * den Wiedereinstieg. Ein Fehlklick auf einer Karte, die von selbst
     * aufgeht, waere teuer.
     *
     * @param {number} id
     */
    beende(id) {
        if (!id || this.busy) return;

        window.webrtcApp.notify.confirm({
            title: 'Führung beenden?',
            text: 'Danach ist die Führung abgeschlossen: Sie und Ihr Kunde können nicht '
                + 'mehr einsteigen, der Startknopf verschwindet, und Ihr Kunde wird nach '
                + 'einer Bewertung gefragt. Rückgängig geht das nicht.',
            confirmText: 'Beenden'
        }).then(ok => {
            if (!ok) return;
            this.busy = true;

            fetch('index.php?act=request_finish', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            })
            .then(r => r.json())
            .then(antwort => {
                this.busy = false;
                if (!antwort || !antwort.success) {
                    window.webrtcApp.notify.error(
                        (antwort && antwort.error) || 'Das hat nicht geklappt.'
                    );
                    return;
                }
                this.schliesse();
                window.webrtcApp.notify.success('Führung beendet.');

                // Auf der Anfragenseite steht die Zeile jetzt anders da. Die
                // Liste kommt vom Server; sie hier von Hand umzubauen waere
                // ein zweiter Bauort fuer dieselben Zeilen.
                if (window.requestsPage && window.webrtcApp.requests) {
                    if (antwort.requests) window.webrtcApp.requests.render(antwort.requests);
                    else                  window.webrtcApp.requests.load();
                }
            })
            .catch(() => {
                this.busy = false;
                window.webrtcApp.notify.error('Keine Verbindung. Bitte erneut versuchen.');
            });
        });
    },

    /**
     * Steigt wieder in die Fuehrung ein.
     *
     * DER GUIDE RUFT DABEI SELBST AN, und das ist neu: Bisher galt "wer
     * angerufen wird, fuehrt". Solange eine Fuehrung laeuft, entscheidet
     * stattdessen ihre Zeile ueber die Rollen - sonst waere der Kunde beim
     * Rueckruf des Guides der Guide (App\Controller\WebRTCController::callRoles).
     *
     * Die Standortkennung geht mit, wie ueberall: An ihr haengt beim Server
     * die Zuordnung des Anrufs zum Standort.
     *
     * @param {HTMLElement} knopf
     */
    steigeEin(knopf) {
        const userId     = knopf.getAttribute('data-userid');
        const locationId = knopf.getAttribute('data-locationid');
        if (!userId) return;

        if (typeof window.webrtcApp?.rtc?.startCall !== 'function') {
            window.webrtcApp.notify.error('Die Anruffunktion steht auf dieser Seite nicht zur Verfügung.');
            return;
        }

        this.schliesse();
        window.webrtcApp.rtc.startCall(userId, locationId);
    },

    // -----------------------------------------------------------------
    // Wegklicken
    // -----------------------------------------------------------------

    /**
     * Die Kennungen, zu denen in diesem Browser "später" gesagt wurde.
     *
     * Der Zugriff steht in try/catch: In einem privaten Fenster wirft
     * localStorage. Dann gilt "nichts weggeklickt" - der harmlose Ausgang,
     * denn die Karte laesst sich wegklicken.
     *
     * @returns {number[]}
     */
    weggeklickte() {
        try {
            const roh   = window.localStorage.getItem(this.SKIP_KEY);
            const liste = roh ? JSON.parse(roh) : [];
            return Array.isArray(liste) ? liste : [];
        } catch (e) {
            return [];
        }
    },

    /**
     * @param {number} id
     * @returns {boolean}
     */
    weggeklickt(id) {
        return this.weggeklickte().indexOf(id) !== -1;
    },

    /**
     * Merkt sich eine weggeklickte Fuehrung.
     *
     * Die Liste wird gedeckelt: Sie waechst sonst mit jeder Fuehrung. Die
     * aeltesten fallen heraus - und wenn eine davon wiederkaeme, waere das
     * kein Schaden, sondern eine zweite Erinnerung an etwas, das wirklich
     * offen ist.
     *
     * @param {number} id
     */
    merkeWeggeklickt(id) {
        try {
            const liste = this.weggeklickte().filter(x => x !== id);
            liste.push(id);
            while (liste.length > this.SKIP_MAX) liste.shift();
            window.localStorage.setItem(this.SKIP_KEY, JSON.stringify(liste));
        } catch (e) {
            // Kein Speicher, kein Merken. Die Karte ist trotzdem weg.
        }
    },

    /**
     * Maskiert Text fuer die Ausgabe.
     *
     * Titel und Namen sind Fremdeingabe. Dieselbe Fassung wie in
     * requests.js und review.js.
     *
     * @param {*} wert
     * @returns {string}
     */
    esc(wert) {
        return String(wert === null || wert === undefined ? '' : wert)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
};

// Eingehaengt wird beim Seitenaufbau. Die Karte kommt erst, wenn der
// Heartbeat eine offene Fuehrung mitbringt - auf jeder Seite, auf der ein
// Guide angemeldet ist.
window.addEventListener('DOMContentLoaded', function() {
    window.webrtcApp.tour.init();

    // Das Wegklicken haengt am Dokument wie die uebrigen Knoepfe, aber es
    // braucht die Kennung der gerade offenen Karte - deshalb hier und nicht
    // in der Knopfliste oben.
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.tour-ask__later, #tour-ask .app-ask__close')) return;
        e.preventDefault();
        const modul = window.webrtcApp.tour;
        if (modul.offen !== null) modul.merkeWeggeklickt(modul.offen);
        modul.schliesse();
    });
});
