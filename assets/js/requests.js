window.webrtcApp = window.webrtcApp || {};

/**
 * Anfragen: der Zaehler in der Kopfleiste und die Anfragenseite.
 *
 * WORUM ES GEHT
 * -------------
 * Vorher rief ein Kunde den Guide unmittelbar an. Das verlangte, dass beide
 * zufaellig im selben Moment koennen - und der Guide ist die knappere Seite:
 * Er muss losgehen, sich Zeit nehmen, vielleicht hinfahren. Am Anfang steht
 * deshalb eine ANFRAGE mit einem Wunschzeitpunkt; der Guide nimmt an oder
 * lehnt ab, und erst danach wird angerufen - ueber dieselben Wege wie bisher
 * (rtc.startCall mit der Standortkennung).
 *
 * "Jetzt sofort" ist dabei kein Sonderfall, sondern der Wunschzeitpunkt mit
 * dem Abstand null.
 *
 * ZWEI AUFGABEN
 * -------------
 * 1. DER ZAEHLER, auf jeder Seite. Er sagt "hier wartet etwas auf dich" -
 *    fuer den Guide eine unbeantwortete Anfrage, fuer den Kunden eine
 *    Zusage. Seine Zahlen kommen mit der Antwort des Heartbeats mit
 *    (assets/js/signaling.js); eine eigene Schleife daneben waere derselbe
 *    Weg noch einmal.
 *
 * 2. DIE ANFRAGENSEITE, wenn man auf ihr steht. Sie ist der Ort, an dem eine
 *    verpasste Anfrage wieder auftaucht: Der Zaehler fuehrt hierher.
 *
 * WAS DIESES MODUL NICHT TUT
 * --------------------------
 * Es entscheidet nichts. Ob eine Anfrage noch gilt, ob sie beantwortet
 * werden darf und ob jetzt angerufen werden kann, steht in der Antwort des
 * Servers (App\Model\TourRequest) - hier wird sie nur gezeigt. Ein Knopf,
 * den es hier nicht gibt, ist keine Absicherung; die Routen pruefen alles
 * erneut.
 */
window.webrtcApp.requests = {

    /**
     * Takt, in dem die Anfragenseite ihre Listen erneuert.
     *
     * Derselbe Wert wie auf der Standortseite und in der Karte: etwas laenger
     * als der Heartbeat, damit nicht haeufiger gefragt wird, als sich die
     * Daten aendern koennen.
     */
    REFRESH_MS: 15000,

    /** Zuletzt bekannte Zahlen des Zaehlers. */
    counts: { incoming_open: 0, outgoing_accepted: 0 },

    /** Timer der Anfragenseite. */
    refreshTimer: null,

    /** Laeuft gerade eine Antwort? Verhindert Doppelklicks. */
    busy: false,

    /**
     * Haengt Zaehler und Seite ein.
     *
     * Beides ist unabhaengig: Den Zaehler gibt es auf jeder Seite, die Liste
     * nur auf der Anfragenseite. Wer nicht angemeldet ist, hat weder das eine
     * noch das andere - dann ist hier nichts zu tun.
     */
    init() {
        // Der Startwert kommt vom Server und steht am Element bzw. in
        // window.requestCounts (App\Helper\ViewHelper). Er wird NICHT aus
        // einer lokalen Ablage gelesen: Anfragen gehoeren zum Konto und nicht
        // zu diesem Browser.
        if (window.requestCounts) {
            this.counts = {
                incoming_open:     parseInt(window.requestCounts.incoming_open, 10) || 0,
                outgoing_accepted: parseInt(window.requestCounts.outgoing_accepted, 10) || 0
            };
        }
        this.renderBadge();

        // AUF DER ANFRAGENSEITE? Gefragt wird window.requestsPage und nicht
        // ein Element: Ein Element findet man auf jeder Seite, auf der jemand
        // dieselbe id vergibt - dieselbe Ueberlegung wie bei
        // window.locationPage (assets/js/location_page.js).
        if (window.requestsPage) {
            this.bindPage();
            this.load();
            this.startAutoRefresh();
        }
    },

    // -----------------------------------------------------------------
    // Der Zaehler
    // -----------------------------------------------------------------

    /**
     * Uebernimmt die Zahlen aus der Antwort des Heartbeats.
     *
     * MELDET NUR DEN ZUWACHS. Eine Meldung bei jedem Takt waere Laerm: Die
     * Zahl steht ohnehin in der Leiste. Gemeldet wird, was NEU dazugekommen
     * ist - eine eingegangene Anfrage beim Guide, eine Zusage beim Kunden.
     *
     * @param {Object} zahlen  { incoming_open, outgoing_accepted }
     */
    sync(zahlen) {
        if (!zahlen) return;

        const neu = {
            incoming_open:     parseInt(zahlen.incoming_open, 10) || 0,
            outgoing_accepted: parseInt(zahlen.outgoing_accepted, 10) || 0
        };

        const mehrEingehend = neu.incoming_open     > this.counts.incoming_open;
        const mehrZusagen   = neu.outgoing_accepted > this.counts.outgoing_accepted;

        this.counts = neu;
        this.renderBadge();

        // Auf der Anfragenseite selbst genuegt die Liste - dort steht die
        // neue Zeile ohnehin gleich da.
        if (window.requestsPage) {
            if (mehrEingehend || mehrZusagen) this.load();
            return;
        }

        if (mehrEingehend) {
            window.webrtcApp.notify.info('Neue Anfrage für eine Ihrer Führungen.');
            this.ton();
        }
        if (mehrZusagen) {
            window.webrtcApp.notify.success('Ihre Anfrage wurde angenommen.');
            this.ton();
        }
    },

    /**
     * Der kurze Hinweiston.
     *
     * Derselbe wie bei einer Chatnachricht und mit denselben zwei Angaben:
     * NICHT in der Schleife und leise. Die Vorgabe von sound.play() ist
     * loop=true - ein Dauerton fuer eine Anfrage waere ein Klingeln, das
     * niemand abstellen kann.
     *
     * Fehlt das Audio-Element (nicht jede Seite bringt es mit), passiert
     * nichts: Der Hinweis daneben steht ohnehin.
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
     * (App\Helper\ViewHelper::requestsBadge) - die Zahl allein sagt nicht,
     * WAS wartet.
     */
    renderBadge() {
        const knopf = document.getElementById('requests-badge');
        if (!knopf) return;

        const ein  = this.counts.incoming_open;
        const aus  = this.counts.outgoing_accepted;
        const summe = ein + aus;

        knopf.setAttribute('data-incoming', String(ein));
        knopf.setAttribute('data-outgoing', String(aus));
        knopf.classList.toggle('app-requests--on', summe > 0);

        let titel = 'Ihre Anfragen';
        if (ein > 0 && aus > 0) {
            titel = ein + ' Anfrage(n) warten auf Ihre Antwort, '
                  + aus + ' Ihrer Anfragen wurde(n) angenommen';
        } else if (ein > 0) {
            titel = ein + ' Anfrage(n) warten auf Ihre Antwort';
        } else if (aus > 0) {
            titel = aus + ' Ihrer Anfragen wurde(n) angenommen';
        }
        knopf.setAttribute('title', titel);

        const zahl = document.getElementById('requests-count');
        if (zahl) {
            zahl.textContent = String(summe);
            zahl.hidden = (summe === 0);
        }
    },

    // -----------------------------------------------------------------
    // Die Anfragenseite
    // -----------------------------------------------------------------

    /** Fragt die Listen im Takt nach - im ausgeblendeten Tab nicht. */
    startAutoRefresh() {
        if (this.refreshTimer) clearInterval(this.refreshTimer);
        this.refreshTimer = setInterval(() => {
            if (document.hidden) return;
            this.load();
        }, this.REFRESH_MS);

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) this.load();
        });
    },

    /**
     * Holt beide Listen.
     *
     * Ein fehlgeschlagener Abruf laesst stehen, was da ist, und sagt es:
     * Eine leergeraeumte Seite waere die schlechtere Auskunft als eine, die
     * fuenfzehn Sekunden alt ist.
     */
    load() {
        fetch('index.php?act=get_requests', { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(antwort => {
                const fehler = document.getElementById('req-error');
                if (!antwort || !antwort.success) {
                    if (fehler) fehler.hidden = false;
                    return;
                }
                if (fehler) fehler.hidden = true;
                this.render(antwort.requests);
            })
            .catch(() => {
                const fehler = document.getElementById('req-error');
                if (fehler) fehler.hidden = false;
            });
    },

    /**
     * Zeichnet beide Listen.
     *
     * @param {Object} listen { incoming: [], outgoing: [] }
     */
    render(listen) {
        if (!listen) return;
        this.renderListe('req-incoming', 'req-in-empty',  listen.incoming || [], true);
        this.renderListe('req-outgoing', 'req-out-empty', listen.outgoing || [], false);
    },

    /**
     * Eine der beiden Listen.
     *
     * @param {string}  listenId   Ziel-Element
     * @param {string}  leerId     Hinweis, wenn nichts da ist
     * @param {Array}   zeilen     Anfragen vom Server
     * @param {boolean} eingehend  Sicht des Guides (true) oder des Kunden
     */
    renderListe(listenId, leerId, zeilen, eingehend) {
        const liste = document.getElementById(listenId);
        const leer  = document.getElementById(leerId);
        if (!liste) return;

        liste.innerHTML = zeilen.map(z => this.zeileHtml(z, eingehend)).join('');
        if (leer) leer.hidden = (zeilen.length > 0);
    },

    /**
     * Eine Zeile.
     *
     * @param {Object}  z
     * @param {boolean} eingehend
     * @returns {string} HTML
     */
    zeileHtml(z, eingehend) {
        const zustand = String(z.status || '');
        const titel   = this.titelVon(z);
        const wer     = z.partner_name ? this.esc(z.partner_name) : 'Unbekannt';

        return '<li class="req-item req-item--' + this.esc(zustand) + '" data-id="' + (parseInt(z.id, 10) || 0) + '">'
             +   '<div class="req-item__head">'
             +     '<span class="req-item__title">' + titel + '</span>'
             +     this.zustandHtml(zustand)
             +   '</div>'
             +   '<p class="req-item__meta">'
             +     (eingehend ? 'Angefragt von ' : 'Ihr Guide: ') + wer
             +     ' · Wunschzeitpunkt: ' + this.esc(this.zeitText(z))
             +   '</p>'
             +   '<div class="app-actions req-item__actions">' + this.aktionenHtml(z, eingehend) + '</div>'
             + '</li>';
    },

    /**
     * Die Knoepfe einer Zeile.
     *
     * WER WAS DARF, entscheidet der Server. Hier steht nur, was er
     * mitgeliefert hat: 'callable' sagt, ob das Zeitfenster laeuft, und der
     * Zustand sagt, ob noch etwas zu entscheiden ist.
     *
     * @param {Object}  z
     * @param {boolean} eingehend
     * @returns {string} HTML
     */
    aktionenHtml(z, eingehend) {
        const zustand = String(z.status || '');
        const id      = parseInt(z.id, 10) || 0;
        let html      = '';

        if (eingehend && zustand === 'open') {
            html += '<button type="button" class="btn btn-success btn-sm req-accept" data-id="' + id + '">Annehmen</button>'
                 +  '<button type="button" class="btn btn-secondary btn-sm req-decline" data-id="' + id + '">Ablehnen</button>';
        }

        // Die Fuehrung startet der KUNDE, wie bisher: Er ruft an, der Guide
        // wird angerufen. Der Knopf traegt dieselben zwei Kennungen wie auf
        // der Standortseite - an der Standortkennung haengt beim Server die
        // Rollenvergabe.
        if (!eingehend && this.wahr(z.callable) && z.guide_user_id) {
            html += '<button type="button" class="btn btn-success btn-sm req-call"'
                 +  ' data-userid="' + (parseInt(z.guide_user_id, 10) || 0) + '"'
                 +  ' data-locationid="' + (parseInt(z.location_id, 10) || 0) + '">Führung starten</button>';
        }

        // Zuruecknehmen darf, wer beteiligt ist - solange die Fuehrung nicht
        // begonnen hat. Was stattgefunden hat, wird nicht nachtraeglich zu
        // "abgebrochen".
        if ((zustand === 'open' || zustand === 'accepted') && !z.started_at) {
            html += '<button type="button" class="btn btn-secondary btn-sm req-cancel" data-id="' + id + '">'
                 +  (eingehend ? 'Absagen' : 'Zurückziehen') + '</button>';
        }

        if (html === '') {
            html = '<span class="req-item__done">Nichts mehr zu tun.</span>';
        }
        return html;
    },

    /**
     * Die Zustandsmarke.
     *
     * Dieselben Woerter wie auf dem Server (App\Model\TourRequest) und auf
     * der Standortseite.
     *
     * @param {string} zustand
     * @returns {string} HTML
     */
    zustandHtml(zustand) {
        const namen = {
            open:      'offen',
            accepted:  'angenommen',
            declined:  'abgelehnt',
            expired:   'abgelaufen',
            done:      'durchgeführt',
            cancelled: 'abgebrochen'
        };
        const klassen = {
            open:     'app-tag app-tag--warn',
            accepted: 'app-tag app-tag--live',
            done:     'app-tag app-tag--accent'
        };
        const klasse = klassen[zustand] || 'app-tag';
        return '<span class="' + klasse + '">' + this.esc(namen[zustand] || zustand) + '</span>';
    },

    /**
     * Der Wunschzeitpunkt als Text.
     *
     * RELATIV UND NICHT ALS DATUM. Der Server liefert den Abstand in Sekunden
     * mit (wish_in), und der ist zeitzonenfrei: Guide und Kunde sitzen
     * womoeglich in verschiedenen Zeitzonen, und "in 20 Minuten" heisst fuer
     * beide dasselbe. Ein Datum aus der Datenbank stuende in der Zeitzone des
     * Servers und waere fuer mindestens einen von beiden falsch.
     *
     * @param {Object} z
     * @returns {string}
     */
    zeitText(z) {
        const s = parseInt(z.wish_in, 10);
        if (isNaN(s)) return 'unbekannt';

        if (s <= 60 && s >= -60) return 'jetzt';
        if (s > 0)  return 'in ' + this.dauerText(s);
        return 'vor ' + this.dauerText(-s);
    },

    /**
     * Eine Dauer in Worten. Die Einheit wechselt mit der Groessenordnung -
     * bei drei Tagen interessiert niemanden die Minute.
     *
     * @param {number} sekunden
     * @returns {string}
     */
    dauerText(sekunden) {
        const min = Math.round(sekunden / 60);
        if (min < 60)   return min + ' Min';
        const std = Math.round(min / 60);
        if (std < 24)   return std + ' Std';
        return Math.round(std / 24) + ' Tagen';
    },

    /**
     * Der Titel eines Standorts - mit Rueckfall auf den Ort.
     *
     * Der Standort kann geloescht sein: Die Aufzeichnung einer Fuehrung
     * ueberlebt ihn (die Tabelle hat bewusst keinen Fremdschluessel), und
     * dann fehlt der Titel.
     *
     * @param {Object} z
     * @returns {string} HTML-sicher
     */
    titelVon(z) {
        const titel = (z.title || '').trim();
        if (titel !== '') return this.esc(titel);

        const ort = [z.city_name, z.country_name].filter(t => t && t.trim() !== '').join(', ');
        return ort !== '' ? this.esc('Führung in ' + ort) : 'Führung';
    },

    /**
     * MySQL liefert Wahrheitswerte als 0/1, und je nach Treiber als Zahl oder
     * als Zeichenkette. Beides heisst dasselbe.
     *
     * @param {*} wert
     * @returns {boolean}
     */
    wahr(wert) {
        return wert === true || wert === 1 || wert === '1';
    },

    /**
     * Maskiert Text fuer die Ausgabe.
     *
     * Titel, Ortsnamen und Benutzernamen sind Fremdeingabe - sie gehen durch
     * diese Funktion, bevor sie in einer Zeichenkette landen, die als HTML
     * eingesetzt wird.
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
    },

    // -----------------------------------------------------------------
    // Die Aktionen
    // -----------------------------------------------------------------

    /**
     * Haengt die Knoepfe ein.
     *
     * Ein Handler am Dokument und nicht je Knopf: Die Zeilen werden bei jedem
     * Takt neu gebaut, und ein Handler an einem ersetzten Knopf waere weg.
     */
    bindPage() {
        document.addEventListener('click', (e) => {
            const knopf = e.target.closest('.req-accept, .req-decline, .req-cancel, .req-call');
            if (!knopf) return;
            e.preventDefault();

            if (knopf.classList.contains('req-call')) {
                this.starteFuehrung(knopf);
                return;
            }

            const id = parseInt(knopf.getAttribute('data-id'), 10);
            if (!id) return;

            if (knopf.classList.contains('req-accept'))  this.antworte('request_accept',  id);
            if (knopf.classList.contains('req-decline')) this.antworte('request_decline', id);
            if (knopf.classList.contains('req-cancel'))  this.antworte('request_cancel',  id);
        });
    },

    /**
     * Schickt eine Entscheidung an den Server.
     *
     * Uebernommen wird, was der SERVER antwortet - nicht, was angefragt
     * wurde. Weist er ab (die Anfrage ist abgelaufen, jemand war schneller),
     * steht die Liste danach auf dem wahren Stand.
     *
     * @param {string} route 'request_accept', 'request_decline' oder 'request_cancel'
     * @param {number} id
     */
    antworte(route, id) {
        if (this.busy) return;
        this.busy = true;

        fetch('index.php?act=' + route, {
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
                this.load();
                return;
            }
            if (antwort.requests) this.render(antwort.requests);

            if (route === 'request_accept') {
                window.webrtcApp.notify.success(
                    'Angenommen. Der Kunde startet die Führung zum vereinbarten Zeitpunkt – '
                  + 'Sie werden dann angerufen.'
                );
            } else if (route === 'request_decline') {
                window.webrtcApp.notify.info('Abgelehnt.');
            } else {
                window.webrtcApp.notify.info('Zurückgenommen.');
            }
        })
        .catch(() => {
            this.busy = false;
            window.webrtcApp.notify.error('Keine Verbindung. Bitte erneut versuchen.');
        });
    },

    /**
     * Startet die Fuehrung zu einer angenommenen Anfrage.
     *
     * MIT DER STANDORTKENNUNG - wie auf der Standortseite. Daran haengt beim
     * Server die Rollenvergabe: Von einem Standort aus fuehrt der Angerufene
     * (App\Controller\WebRTCController::callRoles). Ginge sie hier verloren,
     * waere die Fuehrung ein Gespraech ohne Fuehrung.
     *
     * @param {HTMLElement} knopf
     */
    starteFuehrung(knopf) {
        const userId     = knopf.getAttribute('data-userid');
        const locationId = knopf.getAttribute('data-locationid');
        if (!userId) return;

        if (typeof window.webrtcApp?.rtc?.startCall !== 'function') {
            window.webrtcApp.notify.error('Die Anruffunktion steht auf dieser Seite nicht zur Verfügung.');
            return;
        }
        window.webrtcApp.rtc.startCall(userId, locationId);
    }
};

// Eingehaengt wird beim Seitenaufbau. Der Zaehler kommt fertig vom Server;
// hier wird er lediglich nachgezogen - und die Anfragenseite, falls man auf
// ihr steht, gefuellt.
window.addEventListener('DOMContentLoaded', function() {
    window.webrtcApp.requests.init();
});
