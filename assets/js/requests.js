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
    counts: { incoming_open: 0, outgoing_accepted: 0, tours_running: 0 },

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
                outgoing_accepted: parseInt(window.requestCounts.outgoing_accepted, 10) || 0,
                tours_running:     parseInt(window.requestCounts.tours_running, 10) || 0
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
            outgoing_accepted: parseInt(zahlen.outgoing_accepted, 10) || 0,
            tours_running:     parseInt(zahlen.tours_running, 10) || 0
        };

        const mehrEingehend = neu.incoming_open     > this.counts.incoming_open;
        const mehrZusagen   = neu.outgoing_accepted > this.counts.outgoing_accepted;
        // Eine neu hinzugekommene laufende Fuehrung wird NICHT gemeldet: Sie
        // entsteht in dem Moment, in dem das Gespraech beginnt - eine Meldung
        // darueber waere die Auskunft "Sie telefonieren gerade". Der Zaehler
        // zeigt sie trotzdem, und die Karte nach dem Auflegen sagt es
        // (assets/js/tour.js).
        const mehrFuehrungen = neu.tours_running > this.counts.tours_running;

        this.counts = neu;
        this.renderBadge();

        // Auf der Anfragenseite selbst genuegt die Liste - dort steht die
        // neue Zeile ohnehin gleich da.
        if (window.requestsPage) {
            if (mehrEingehend || mehrZusagen || mehrFuehrungen) this.load();
            return;
        }

        if (mehrEingehend) {
            window.webrtcApp.notify.info(window.webrtcApp.t('anfrage.neu'));
            this.ton();
        }
        if (mehrZusagen) {
            window.webrtcApp.notify.success(window.webrtcApp.t('anfrage.angenommen'));
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

        const ein   = this.counts.incoming_open;
        const aus   = this.counts.outgoing_accepted;
        const offen = this.counts.tours_running;
        const summe = ein + aus + offen;

        knopf.setAttribute('data-incoming', String(ein));
        knopf.setAttribute('data-outgoing', String(aus));
        knopf.setAttribute('data-running',  String(offen));
        knopf.classList.toggle('app-requests--on', summe > 0);

        // DIESELBEN SCHLUESSEL WIE IN DER KOPFLEISTE DES SERVERS
        // (App\Helper\ViewHelper): Es ist derselbe Knopf mit denselben
        // Zahlen, nur nachgezogen. Und es sind Formen und keine
        // Klammerloesung mehr - "Anfrage(n)" gibt es im Englischen nicht.
        const teile = [];
        if (ein > 0)   teile.push(window.webrtcApp.plural('kopf.anfragen.eingehend', ein));
        if (aus > 0)   teile.push(window.webrtcApp.plural('kopf.anfragen.ausgehend', aus));
        if (offen > 0) teile.push(window.webrtcApp.plural('kopf.anfragen.laufend', offen));
        knopf.setAttribute('title', teile.length
            ? teile.join(', ')
            : window.webrtcApp.t('kopf.anfragen.titel'));

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
        const wer     = this.esc(z.partner_name || window.webrtcApp.t('anfrage.partner_unbekannt'));

        // LAEUFT NOCH: begonnen und nicht beendet. Der Server rechnet das aus
        // (App\Model\TourRequest::runningSql) - hier wird es nur gezeigt. Die
        // Zeile bekommt dafuer eine eigene Marke: Sie ist die einzige in der
        // Liste, bei der noch etwas zu tun ist.
        const laeuft = this.wahr(z.running);

        return '<li class="req-item req-item--' + this.esc(zustand)
             +   (laeuft ? ' req-item--running' : '')
             +   '" data-id="' + (parseInt(z.id, 10) || 0) + '">'
             +   '<div class="req-item__head">'
             +     '<span class="req-item__title">' + titel + '</span>'
             +     (laeuft
                    ? '<span class="app-tag app-tag--live"><span class="app-dot"></span>'
                      + this.esc(window.webrtcApp.t('standort.anfrage.marke_laeuft')) + '</span>'
                    : this.zustandHtml(zustand))
             +   '</div>'
             +   '<p class="req-item__meta">'
             +     window.webrtcApp.tHtml(
                       eingehend ? 'anfrage.zeile.von' : 'anfrage.zeile.guide',
                       { name: wer })
             +     ' · ' + this.esc(window.webrtcApp.t(
                       'anfrage.zeile.wunschzeit', { zeit: this.zeitText(z) }))
             +     (laeuft ? '<br><span class="req-item__rest">' + this.esc(this.restText(z)) + '</span>' : '')
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
        const laeuft  = this.wahr(z.running);
        let html      = '';

        if (eingehend && zustand === 'open') {
            html += '<button type="button" class="btn btn-success btn-sm req-accept" data-id="' + id + '">Annehmen</button>'
                 +  '<button type="button" class="btn btn-secondary btn-sm req-decline" data-id="' + id + '">Ablehnen</button>';
        }

        // ANRUFEN. Zwei Faelle, ein Knopf mit zwei Beschriftungen:
        //
        //   Der KUNDE startet die Fuehrung, wie bisher - oder steigt nach
        //   einem Abbruch wieder ein. Angerufen wird der Guide.
        //   Der GUIDE steigt wieder ein, und zwar nur in eine LAUFENDE
        //   Fuehrung: Von sich aus anrufen kann er nicht, sonst waere der
        //   Kunde im Call der Guide. Bei einer laufenden Fuehrung entscheidet
        //   deren Zeile ueber die Rollen
        //   (App\Controller\WebRTCController::callRoles).
        //
        // Wer angerufen wird, ist jeweils die andere Seite; die
        // Standortkennung geht in beiden Faellen mit.
        if (!eingehend && this.wahr(z.callable) && z.guide_user_id) {
            html += '<button type="button" class="btn btn-success btn-sm req-call"'
                 +  ' data-userid="' + (parseInt(z.guide_user_id, 10) || 0) + '"'
                 +  ' data-locationid="' + (parseInt(z.location_id, 10) || 0) + '">'
                 +  this.esc(window.webrtcApp.t(laeuft
                        ? 'standort.anfrage.einsteigen' : 'standort.anfrage.starten')) + '</button>';
        }
        if (eingehend && laeuft && z.customer_user_id) {
            html += '<button type="button" class="btn btn-secondary btn-sm req-call"'
                 +  ' data-userid="' + (parseInt(z.customer_user_id, 10) || 0) + '"'
                 +  ' data-locationid="' + (parseInt(z.location_id, 10) || 0) + '">'
                 +  this.esc(window.webrtcApp.t('standort.anfrage.einsteigen')) + '</button>';
        }

        // BEENDEN - nur der Guide, und nur bei einer laufenden Fuehrung.
        // Auflegen tut das nicht: Es kann "wir sind fertig" heissen oder "das
        // Netz ist weg", und nur der Guide weiss, welches von beidem. Bis er
        // klickt, koennen beide wieder einsteigen.
        if (eingehend && laeuft) {
            html += '<button type="button" class="btn btn-primary btn-sm tour-finish"'
                 +  ' data-id="' + id + '">'
                 +  this.esc(window.webrtcApp.t('fuehrung.beenden')) + '</button>';
        }

        // Zuruecknehmen darf, wer beteiligt ist - solange die Fuehrung nicht
        // begonnen hat. Was stattgefunden hat, wird nicht nachtraeglich zu
        // "abgebrochen".
        if ((zustand === 'open' || zustand === 'accepted') && !z.started_at) {
            html += '<button type="button" class="btn btn-secondary btn-sm req-cancel" data-id="' + id + '">'
                 +  this.esc(window.webrtcApp.t(eingehend
                        ? 'standort.anfrage.absagen' : 'anfrage.zurueckziehen')) + '</button>';
        }

        // DIE BEWERTUNG - der Ort, an dem eine uebersprungene Frage wieder
        // auftaucht. Nach dem Auflegen wird der Kunde gefragt; wer damals
        // "später" gesagt hat, findet die Fuehrung hier, und zwar so lange,
        // wie sie unbewertet ist.
        //
        // NUR DER KUNDE bewertet. Der Guide sieht in seiner Liste, was er
        // bekommen hat - aendern kann er daran nichts, und einen Knopf dafuer
        // gibt es nirgends.
        if (!eingehend && zustand === 'done' && !laeuft && !this.wahr(z.reviewed)) {
            html += '<button type="button" class="btn btn-primary btn-sm rev-open"'
                 +  ' data-request="' + id + '"'
                 +  ' data-title="' + this.esc((z.title || '').trim()) + '"'
                 +  ' data-guide="' + this.esc(z.partner_name || '') + '">'
                 +  this.esc(window.webrtcApp.t('bewertung.frage.titel')) + '</button>';
        }

        if (html === '') {
            // Steht eine Bewertung dazu, sagt die Zeile das statt "nichts
            // mehr zu tun": Fuer den Guide ist es die Auskunft, wie diese
            // Fuehrung ankam, fuer den Kunden die Bestaetigung, dass seine
            // Bewertung angekommen ist.
            html = this.bewertungHtml(z, eingehend);
        }
        return html;
    },

    /**
     * Die abgegebene Bewertung einer Zeile - oder der Hinweis, dass nichts
     * mehr ansteht.
     *
     * ENTFERNTE BEWERTUNGEN stehen hier nicht: Der Server liefert die Sterne
     * dann gar nicht mit (App\Model\TourRequest). Fuer den Guide sieht die
     * Zeile danach aus wie eine unbewertete - und das ist richtig, denn
     * sichtbar ist die Bewertung nirgends mehr.
     *
     * @param {Object}  z
     * @param {boolean} eingehend
     * @returns {string} HTML
     */
    bewertungHtml(z, eingehend) {
        const sterne = parseInt(z.review_stars, 10);
        const modul  = window.webrtcApp.review;

        if (!isNaN(sterne) && sterne > 0 && modul) {
            // Die Sterne sind fertiges HTML und werden als Platzhalter in
            // den Satz gesetzt - der Katalog traegt den Doppelpunkt mit.
            return '<span class="req-item__done">'
                 + window.webrtcApp.tHtml(
                       eingehend ? 'anfrage.zeile.bewertet' : 'anfrage.zeile.bewertung_eigen',
                       { sterne: modul.sterneHtml(sterne) })
                 + '</span>';
        }

        return '<span class="req-item__done">'
             + this.esc(window.webrtcApp.t('anfrage.zeile.nichts_zu_tun')) + '</span>';
    },

    /**
     * Die Zustandsmarke.
     *
     * DIESELBEN WOERTER WIE AUF DEM SERVER, und ab jetzt auch aus derselben
     * Quelle: dem Sprachkatalog (anfrage.status.<wert>). Hier stand die Liste
     * ein zweites Mal - wortgleich mit der in App\Model\TourRequest, und
     * damit eine Gelegenheit, sie auseinanderlaufen zu lassen.
     *
     * DIE KLASSEN BLEIBEN HIER. Sie sind Gestaltung und kein Text: Welche
     * Marke gelb ist, hat mit der Sprache nichts zu tun.
     *
     * @param {string} zustand
     * @returns {string} HTML
     */
    zustandHtml(zustand) {
        const klassen = {
            open:     'app-tag app-tag--warn',
            accepted: 'app-tag app-tag--live',
            done:     'app-tag app-tag--accent'
        };
        const klasse = klassen[zustand] || 'app-tag';
        // Kennt der Katalog den Zustand nicht, steht sein Schluessel da -
        // dieselbe Entscheidung wie ueberall in webrtcApp.t(). Ein Zustand,
        // den der Server schickt und der Katalog nicht kennt, ist ein Fehler
        // und soll auffallen.
        return '<span class="' + klasse + '">'
             + this.esc(window.webrtcApp.t('anfrage.status.' + zustand)) + '</span>';
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
        if (isNaN(s)) return window.webrtcApp.t('zeit.unbekannt');
        if (s <= 60 && s >= -60) return window.webrtcApp.t('zeit.jetzt');

        // DIE RICHTUNG STECKT IM SCHLUESSEL und wird nicht vor die Dauer
        // gesetzt: Im Englischen steht sie hinten ("3 hours ago"). Dieselben
        // Schluessel wie in App\Helper\LocationView::wunschzeitText - es ist
        // derselbe Zeitpunkt, nur auf einer anderen Seite.
        const richtung = s > 0 ? 'zeit.in.' : 'zeit.vor.';

        const min = Math.round(Math.abs(s) / 60);
        if (min < 60) return window.webrtcApp.plural(richtung + 'minuten', min);

        const std = Math.round(min / 60);
        if (std < 24) return window.webrtcApp.plural(richtung + 'stunden', std);

        return window.webrtcApp.plural(richtung + 'tagen', Math.round(std / 24));
    },

    /**
     * Eine Dauer in Worten, abgekuerzt. Die Einheit wechselt mit der
     * Groessenordnung - bei drei Tagen interessiert niemanden die Minute.
     *
     * ABGEKUERZT (dauer.kurz.*), weil sie hier mitten in einem Satz steht und
     * ihn nicht auseinanderziehen soll. Der Wunschzeitpunkt darueber nimmt
     * die ausgeschriebene Fassung: Er IST die Angabe der Zeile.
     *
     * @param {number} sekunden
     * @returns {string}
     */
    dauerText(sekunden) {
        const min = Math.round(sekunden / 60);
        if (min < 60) return window.webrtcApp.t('dauer.kurz.minuten', { n: min });

        const std = Math.round(min / 60);
        if (std < 24) return window.webrtcApp.t('dauer.kurz.stunden', { n: std });

        return window.webrtcApp.t('dauer.kurz.tage', { n: Math.round(std / 24) });
    },

    /**
     * Wie lange der Wiedereinstieg noch offen steht.
     *
     * DIE FRIST KOMMT FERTIG GERECHNET VOM SERVER (rejoin_in, in Sekunden) -
     * hier wird keine zweite Uhr befragt und keine Frist nachgebaut. Fehlt
     * die Angabe, kam nie ein Auflegen an; dann laeuft die lange Reissleine,
     * und eine Zahl waere eine Erfindung.
     *
     * @param {Object} z
     * @returns {string} Unmaskiert - der Aufrufer maskiert
     */
    restText(z) {
        const s = parseInt(z.rejoin_in, 10);
        if (isNaN(s)) {
            return window.webrtcApp.t('anfrage.rest.ohne_frist');
        }
        if (s <= 0) return window.webrtcApp.t('anfrage.rest.abgelaufen');

        return window.webrtcApp.t('anfrage.rest.offen', { dauer: this.dauerText(s) });
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

        // Derselbe Schluessel wie auf der Standortseite: "Führung in {ort}"
        // ist dort die Ueberschrift und hier der Titel derselben Sache.
        const ort = [z.city_name, z.country_name].filter(t => t && t.trim() !== '').join(', ');
        return this.esc(ort !== ''
            ? window.webrtcApp.t('standort.titel.fuehrung_in', { ort: ort })
            : window.webrtcApp.t('anfrage.zeile.fuehrung'));
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
            // tour-finish steht bewusst NICHT in dieser Liste: Das Beenden
            // gehoert zu assets/js/tour.js - dort haengt es mit der Karte
            // nach dem Auflegen zusammen, und beide fragen dieselbe
            // Rueckfrage. Zwei Handler fuer denselben Knopf waeren zwei
            // Rueckfragen.
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
                    (antwort && antwort.error) || window.webrtcApp.t('allgemein.nicht_geklappt')
                );
                this.load();
                return;
            }
            if (antwort.requests) this.render(antwort.requests);

            if (route === 'request_accept') {
                window.webrtcApp.notify.success(window.webrtcApp.t('anfrage.zugesagt'));
            } else if (route === 'request_decline') {
                window.webrtcApp.notify.info(window.webrtcApp.t('anfrage.abgelehnt'));
            } else {
                window.webrtcApp.notify.info(window.webrtcApp.t('anfrage.zurueckgenommen'));
            }
        })
        .catch(() => {
            this.busy = false;
            window.webrtcApp.notify.error(window.webrtcApp.t('allgemein.keine_verbindung'));
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
            window.webrtcApp.notify.error(window.webrtcApp.t('gespraech.kein_anruf_hier'));
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
