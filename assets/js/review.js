window.webrtcApp = window.webrtcApp || {};

/**
 * Bewertungen: die Frage nach der Fuehrung, das Formular und die Moderation.
 *
 * WORUM ES GEHT
 * -------------
 * Ein Kunde soll einem Fremden Geld dafuer geben, dass der ihn per Video
 * durch eine unbekannte Stadt fuehrt. Was ANDERE Kunden mit diesem Guide
 * erlebt haben, stand bisher nirgends. Nach einer durchgefuehrten Fuehrung
 * wird der Kunde deshalb gefragt - mit Sternen und, wenn er mag, ein paar
 * Saetzen.
 *
 * NUR DIESE RICHTUNG: Kunden bewerten Guides. Es gibt hier kein Formular fuer
 * den umgekehrten Weg, keine Route dafuer und im Schema keine Spalte, die ihn
 * darstellen koennte.
 *
 * WARUM DIE FRAGE UEBER DEN HEARTBEAT KOMMT
 * -----------------------------------------
 * Gefragt wird nach dem Auflegen - aber NICHT mitten im Abbau des
 * Gespraechs. Auf Telefonen laedt die Seite danach ohnehin neu (rtc.js), und
 * was in diesem Moment auf dem Bildschirm stand, waere weg. Der Heartbeat
 * laeuft alle zehn Sekunden, seine Antwort traegt die offene Frage mit
 * (App\Controller\UserController::heartbeat), und damit ueberlebt sie jeden
 * Seitenwechsel und jedes Neuladen. Sie erscheint hoechstens zehn Sekunden
 * spaeter - und das ist genau der Abstand, den "nicht aufdringlich" braucht.
 *
 * NICHT AUFDRINGLICH HEISST HIER DREIERLEI
 * ----------------------------------------
 *   1. Es ist eine KARTE und kein Dialog. Sie legt sich unten an den Rand,
 *      sperrt die Seite nicht und nimmt niemandem die Tastatur weg.
 *   2. Sie ist UEBERSPRINGBAR, und "später" heisst später: Wer sie wegklickt,
 *      bekommt sie in diesem Browser nicht wieder.
 *   3. Sie kommt NICHT WAEHREND EINES GESPRAECHS und nicht ueber der
 *      Anrufansicht.
 *
 * NACHHOLEN GEHT IMMER, und zwar dort, wo eine verpasste Sache in dieser
 * Anwendung immer wieder auftaucht: auf der Anfragenseite. Jede durchgefuehrte
 * Fuehrung hat dort einen Knopf, solange sie unbewertet ist (requests.js).
 * Das Ueberspringen ist deshalb eine Entscheidung DIESES BROWSERS - die
 * Fuehrung selbst bleibt bewertbar, denn sie gehoert zum Konto.
 *
 * WAS DIESES MODUL NICHT TUT
 * --------------------------
 * Es entscheidet nichts. Ob eine Fuehrung bewertet werden darf, steht in der
 * WHERE-Klausel des Servers (App\Model\TourReview::create); ein Knopf, den es
 * hier nicht gibt, ist keine Absicherung. Und die Skala kommt vom Server
 * (window.reviewScale, App\Helper\ViewHelper) - hier stehen keine Sternzahlen
 * und keine Woerter dafuer.
 */
window.webrtcApp.review = {

    /**
     * Schluessel, unter dem die uebersprungenen Fuehrungen liegen.
     *
     * IM BROWSER UND NICHT IM KONTO, und das ist Absicht: "Ich will jetzt
     * nicht" ist eine Aussage ueber diesen Moment und dieses Geraet, nicht
     * ueber die Fuehrung. Wer die Anfragenseite aufruft, findet sie dort
     * weiterhin - dieselbe Trennung wie beim Zaehler in der Kopfleiste, der
     * ebenfalls nichts lokal merkt.
     */
    SKIP_KEY: 'reviewSkipped',

    /** Wie viele uebersprungene Kennungen gemerkt werden. */
    SKIP_MAX: 50,

    /** Laeuft gerade ein Absenden? Verhindert Doppelklicks. */
    busy: false,

    /** Die Kennung der Fuehrung, deren Karte gerade offen ist. */
    offen: null,

    /**
     * Haengt die Knoepfe ein.
     *
     * EIN HANDLER AM DOKUMENT und nicht je Knopf: Die Karte entsteht erst zur
     * Laufzeit, und die Zeilen der Anfragenseite werden bei jedem Takt neu
     * gebaut - ein Handler an einem ersetzten Knopf waere weg.
     */
    init() {
        document.addEventListener('click', (e) => {
            const nachholen = e.target.closest('.rev-open');
            if (nachholen) {
                e.preventDefault();
                this.oeffne({
                    id:    parseInt(nachholen.getAttribute('data-request'), 10) || 0,
                    title: nachholen.getAttribute('data-title') || '',
                    guide: nachholen.getAttribute('data-guide') || ''
                }, false);
                return;
            }

            const entfernen = e.target.closest('.rev-remove');
            if (entfernen) {
                e.preventDefault();
                this.entferne(entfernen);
            }
        });
    },

    // -----------------------------------------------------------------
    // Die Frage nach dem Auflegen
    // -----------------------------------------------------------------

    /**
     * Uebernimmt die offene Frage aus der Antwort des Heartbeats.
     *
     * @param {Object|null} fuehrung Die aelteste unbewertete Fuehrung, oder null
     */
    sync(fuehrung) {
        if (!fuehrung) return;

        const id = parseInt(fuehrung.id, 10) || 0;
        if (id < 1) return;

        // Schon offen, schon uebersprungen - oder mitten im Gespraech. Der
        // dritte Fall ist der wichtigste: Eine Frage nach der Fuehrung,
        // waehrend die Fuehrung laeuft, ist keine Frage, sondern eine
        // Stoerung.
        if (this.offen === id) return;
        if (this.uebersprungen(id)) return;
        if (this.imGespraech()) return;

        this.oeffne({
            id:    id,
            title: fuehrung.title || '',
            guide: fuehrung.display_name || fuehrung.username || ''
        }, true);
    },

    /**
     * Laeuft gerade ein Gespraech - oder steht die Anrufansicht offen?
     *
     * Gefragt wird beides: Das Flag sagt, ob eine Verbindung steht, die
     * Klasse am body, ob die Ansicht noch aufgebaut ist. Zwischen dem Ende
     * des einen und dem Abbau des anderen liegen ein paar Sekunden, und
     * genau in die faellt sonst die Frage.
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
     * @param {Object}  fuehrung  { id, title, guide }
     * @param {boolean} vonSelbst Kam die Karte von selbst (Heartbeat)?
     */
    oeffne(fuehrung, vonSelbst) {
        if (!fuehrung || !fuehrung.id) return;
        if (!this.skala()) return;

        this.schliesse();
        this.offen = fuehrung.id;

        const karte = document.createElement('section');
        // Die FLAECHE kommt aus .app-ask (theme.css) - dieselbe wie beim
        // Hinweis an den Guide. .rev-ask traegt nur, was diese Karte
        // ausmacht.
        karte.className = 'app-ask rev-ask';
        karte.id = 'rev-ask';
        // Die Karte meldet sich, aber sie unterbricht nicht: "polite" laesst
        // ein Vorleseprogramm den laufenden Satz zu Ende sprechen. Ein
        // Dialog waere hier "assertive" - und genau das soll sie nicht sein.
        karte.setAttribute('role', 'region');
        karte.setAttribute('aria-live', 'polite');
        karte.setAttribute('aria-label', 'Führung bewerten');
        karte.innerHTML = this.karteHtml(fuehrung, vonSelbst);

        document.body.appendChild(karte);
        this.bindeKarte(karte, fuehrung);
    },

    /**
     * Nimmt die Karte weg.
     */
    schliesse() {
        const alt = document.getElementById('rev-ask');
        if (alt) alt.remove();
        this.offen = null;
    },

    /**
     * Der Inhalt der Karte.
     *
     * DIE STERNE SIND DIE FRAGE, das Textfeld ist das Angebot: Es steht
     * darunter, ist leer und traegt kein Sternchen. Wer nur klicken will,
     * klickt einmal auf einen Stern und einmal auf "Absenden".
     *
     * @param {Object}  fuehrung
     * @param {boolean} vonSelbst
     * @returns {string} HTML
     */
    karteHtml(fuehrung, vonSelbst) {
        const skala = this.skala();
        const wer   = fuehrung.guide ? this.esc(fuehrung.guide) : 'Ihrem Guide';
        const was   = fuehrung.title
                    ? ' – <span class="rev-ask__what">' + this.esc(fuehrung.title) + '</span>'
                    : '';

        let sterne = '';
        for (let i = skala.min; i <= skala.max; i++) {
            const name = (skala.names && skala.names[i]) ? skala.names[i] : (i + ' Sterne');
            sterne += '<button type="button" class="rev-pick" data-stars="' + i + '"'
                   +  ' aria-pressed="false" title="' + this.esc(name) + '"'
                   +  ' aria-label="' + this.esc(name) + '"><span aria-hidden="true">★</span></button>';
        }

        return '<button type="button" class="app-ask__close" aria-label="Schließen">×</button>'
             + '<p class="app-ask__lead">Wie war die Führung mit <strong>' + wer + '</strong>?' + was + '</p>'
             + '<div class="rev-ask__stars" role="group" aria-label="Sterne">' + sterne + '</div>'
             + '<p class="rev-ask__word" id="rev-ask-word"></p>'
             + '<label class="rev-ask__label" for="rev-ask-text">Wenn Sie mögen, ein paar Sätze</label>'
             + '<textarea id="rev-ask-text" class="form-control rev-ask__text" rows="3"'
             +   ' maxlength="' + (parseInt(skala.bodyMax, 10) || 1000) + '"'
             +   ' placeholder="Was sollten andere wissen?"></textarea>'
             + '<div class="app-ask__actions">'
             +   '<button type="button" class="btn btn-secondary btn-sm rev-ask__later">'
             +     (vonSelbst ? 'Später' : 'Abbrechen')
             +   '</button>'
             +   '<button type="button" class="btn btn-primary btn-sm rev-ask__send" disabled>Absenden</button>'
             + '</div>'
             + '<p class="app-ask__foot">Ihr Name steht nicht dabei. Der Guide kann die '
             +   'Bewertung nicht ändern und nicht löschen.</p>';
    },

    /**
     * Haengt die Knoepfe EINER Karte ein.
     *
     * @param {HTMLElement} karte
     * @param {Object}      fuehrung
     */
    bindeKarte(karte, fuehrung) {
        let gewaehlt = 0;

        const wort   = karte.querySelector('#rev-ask-word');
        const senden = karte.querySelector('.rev-ask__send');
        const skala  = this.skala();

        karte.querySelectorAll('.rev-pick').forEach(knopf => {
            knopf.addEventListener('click', () => {
                gewaehlt = parseInt(knopf.getAttribute('data-stars'), 10) || 0;

                // Alle Sterne bis zum gewaehlten fuellen - so wie eine
                // Sternreihe gelesen wird. Der Zustand steht doppelt am
                // Element: als Klasse fuers Auge, als aria-pressed fuer
                // Vorleseprogramme.
                karte.querySelectorAll('.rev-pick').forEach(s => {
                    const wert = parseInt(s.getAttribute('data-stars'), 10) || 0;
                    const an   = wert <= gewaehlt;
                    s.classList.toggle('rev-pick--on', an);
                    s.setAttribute('aria-pressed', an ? 'true' : 'false');
                });

                if (wort) {
                    wort.textContent = (skala.names && skala.names[gewaehlt]) || '';
                }
                if (senden) senden.disabled = false;
            });
        });

        karte.querySelector('.app-ask__close')?.addEventListener('click', () => {
            this.ueberspringen(fuehrung.id);
        });
        karte.querySelector('.rev-ask__later')?.addEventListener('click', () => {
            this.ueberspringen(fuehrung.id);
        });

        senden?.addEventListener('click', () => {
            const feld = karte.querySelector('#rev-ask-text');
            this.sende(fuehrung.id, gewaehlt, feld ? feld.value : '');
        });
    },

    // -----------------------------------------------------------------
    // Absenden und Ueberspringen
    // -----------------------------------------------------------------

    /**
     * Schickt die Bewertung an den Server.
     *
     * UEBERNOMMEN WIRD, WAS DER SERVER ANTWORTET - nicht, was abgeschickt
     * wurde. Weist er ab (schon bewertet, gar nicht die eigene Fuehrung),
     * bleibt die Karte stehen und sagt es.
     *
     * @param {number} id     Kennung der Fuehrung (tour_request.id)
     * @param {number} sterne
     * @param {string} text
     */
    sende(id, sterne, text) {
        if (this.busy) return;
        if (!sterne) {
            window.webrtcApp.notify.info('Bitte wählen Sie zuerst die Sterne.');
            return;
        }
        this.busy = true;

        fetch('index.php?act=review_create', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ request: id, stars: sterne, body: text || '' })
        })
        .then(r => r.json())
        .then(antwort => {
            this.busy = false;
            if (!antwort || !antwort.success) {
                window.webrtcApp.notify.error(
                    (antwort && antwort.error) || 'Die Bewertung konnte nicht gespeichert werden.'
                );
                return;
            }

            this.schliesse();
            // Bewertet ist nicht uebersprungen - aber merken schadet nicht:
            // Sollte der naechste Heartbeat noch die alte Antwort tragen,
            // taucht die Frage nicht ein zweites Mal auf.
            this.merkeUebersprungen(id);
            window.webrtcApp.notify.success('Danke – Ihre Bewertung steht beim Guide.');

            // Auf der Anfragenseite verschwindet damit der Knopf. Die Liste
            // holt sich der Server; sie hier von Hand umzubauen waere ein
            // zweiter Bauort fuer dieselben Zeilen.
            if (window.requestsPage && window.webrtcApp.requests) {
                window.webrtcApp.requests.load();
            }
        })
        .catch(() => {
            this.busy = false;
            window.webrtcApp.notify.error('Keine Verbindung. Bitte erneut versuchen.');
        });
    },

    /**
     * "Später": Karte weg, Kennung gemerkt.
     *
     * OHNE MELDUNG. Wer wegklickt, hat entschieden - ein Hinweis darauf, dass
     * man es spaeter nachholen kann, waere die Fortsetzung genau der Frage,
     * die er gerade weggeklickt hat. Die Anfragenseite sagt es ihm, wenn er
     * dort ist.
     *
     * @param {number} id
     */
    ueberspringen(id) {
        this.merkeUebersprungen(id);
        this.schliesse();
    },

    /**
     * Die Kennungen, zu denen in diesem Browser "später" gesagt wurde.
     *
     * Der Zugriff steht in try/catch: In einem privaten Fenster wirft
     * localStorage. Dann gilt "nichts uebersprungen" - der harmlose Ausgang,
     * denn die Karte laesst sich wegklicken.
     *
     * @returns {number[]}
     */
    uebersprungene() {
        try {
            const roh = window.localStorage.getItem(this.SKIP_KEY);
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
    uebersprungen(id) {
        return this.uebersprungene().indexOf(id) !== -1;
    },

    /**
     * Merkt sich eine uebersprungene Fuehrung.
     *
     * Die Liste wird gedeckelt: Sie waechst sonst mit jeder Fuehrung und
     * stuende in ein paar Jahren als Kilobyte im Speicher des Browsers. Die
     * aeltesten fallen heraus - und wenn eine davon wiederkaeme, waere das
     * kein Schaden, sondern eine zweite Gelegenheit.
     *
     * @param {number} id
     */
    merkeUebersprungen(id) {
        try {
            const liste = this.uebersprungene().filter(x => x !== id);
            liste.push(id);
            while (liste.length > this.SKIP_MAX) liste.shift();
            window.localStorage.setItem(this.SKIP_KEY, JSON.stringify(liste));
        } catch (e) {
            // Kein Speicher, kein Merken. Die Karte ist trotzdem weg.
        }
    },

    // -----------------------------------------------------------------
    // Moderation
    // -----------------------------------------------------------------

    /**
     * Ein Admin entfernt eine Bewertung.
     *
     * MIT RUECKFRAGE, und mit derselben wie ueberall sonst in dieser
     * Anwendung (notify.confirm). Der Knopf steht auf einer Seite voller
     * fremder Texte; ein Fehlklick soll dort nicht die erste Handlung sein.
     *
     * ENTFERNT WIRD NICHT GELOESCHT: Die Zeile bleibt in der Datenbank stehen
     * und wird ausgeblendet (App\Model\TourReview::remove). Der Eintrag
     * verschwindet hier von der Seite, ohne sie neu zu laden - er ist weg,
     * und mehr hat der Server dazu nicht zu sagen.
     *
     * @param {HTMLElement} knopf
     */
    entferne(knopf) {
        const id = parseInt(knopf.getAttribute('data-id'), 10) || 0;
        if (!id || this.busy) return;

        window.webrtcApp.notify.confirm({
            title: 'Bewertung entfernen?',
            text: 'Die Bewertung verschwindet von dieser Seite und zählt nicht mehr '
                + 'im Durchschnitt. Gelöscht wird sie nicht – sie bleibt nachvollziehbar '
                + 'gespeichert. Der Kunde kann diese Führung danach nicht erneut bewerten.',
            confirmText: 'Entfernen',
            danger: true
        }).then(ok => {
            if (!ok) return;
            this.busy = true;

            fetch('index.php?act=review_remove', {
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
                knopf.closest('.rev-item')?.remove();
                // Der Durchschnitt daneben stimmt jetzt nicht mehr. Ihn hier
                // nachzurechnen waere eine zweite Fassung der Regel, ab wann
                // es ueberhaupt einen gibt (App\Model\TourReview) - die Seite
                // wird stattdessen beim naechsten Aufruf richtig gebaut.
                window.webrtcApp.notify.info(
                    'Entfernt. Der Durchschnitt stimmt nach dem nächsten Laden der Seite.'
                );
            })
            .catch(() => {
                this.busy = false;
                window.webrtcApp.notify.error('Keine Verbindung. Bitte erneut versuchen.');
            });
        });
    },

    // -----------------------------------------------------------------
    // Hilfen
    // -----------------------------------------------------------------

    /**
     * Die Skala, wie sie der Server ausgeliefert hat.
     *
     * SIE STEHT NICHT HIER. Grenzen, Woerter und Textlaenge kommen aus
     * App\Model\TourReview ueber window.reviewScale (App\Helper\ViewHelper) -
     * eine zweite Fassung derselben Skala waere eine zu viel. Fehlt sie, darf
     * dieses Konto nicht bewerten, und dann gibt es auch kein Formular.
     *
     * @returns {Object|null}
     */
    skala() {
        const s = window.reviewScale;
        if (!s || !s.max) return null;
        return s;
    },

    /**
     * Eine Sternreihe zum ANSEHEN.
     *
     * Sie ist die Zwillingsschwester von App\Helper\ReviewView::sterneHtml()
     * und steht aus demselben Grund hier wie die Zustandsmarken in
     * requests.js: Die Zeilen der Anfragenseite, die Kartenfenster und die
     * Standortliste baut der Browser, weil sie sich aendern, waehrend man
     * hinsieht.
     *
     * HALBE STERNE wie auf dem Server: Ein Durchschnitt wird auf halbe
     * gerundet, eine einzelne Bewertung ist ohnehin ganz und bleibt es dabei.
     *
     * @param {number} wert
     * @returns {string} HTML
     */
    sterneHtml(wert) {
        const max   = parseInt((window.reviewScale || {}).max, 10) || 5;
        const zahl  = Math.max(0, Math.min(max, parseFloat(wert) || 0));
        const halbe = Math.round(zahl * 2) / 2;

        let html = '';
        for (let i = 1; i <= max; i++) {
            let klasse = 'rev-star';
            if (halbe >= i)            klasse += ' rev-star--on';
            else if (halbe >= i - 0.5) klasse += ' rev-star--half';
            html += '<span class="' + klasse + '" aria-hidden="true">★</span>';
        }
        return '<span class="rev-stars" role="img" aria-label="'
             + this.esc(this.zahlText(zahl) + ' von ' + max + ' Sternen') + '">'
             + html + '</span>';
    },

    /**
     * Die Bewertung in EINER Zeile - fuer das Kartenfenster einer Nadel und
     * fuer die Standortliste.
     *
     * DORT, WO EIN KUNDE ZWISCHEN STANDORTEN WAEHLT, gehoert die Auskunft hin
     * - nicht erst auf die Seite, die er aufruft, nachdem er sich schon
     * entschieden hat, welche er ansieht.
     *
     * ES GILT DIESELBE REGEL WIE AUF DER STANDORTSEITE: Unterhalb der
     * Schwelle steht kein Durchschnitt, sondern die Zahl der durchgefuehrten
     * Fuehrungen. Entschieden ist das im Modell (App\Model\TourReview) - der
     * Server liefert den Durchschnitt dann gar nicht erst mit, und dieses
     * Modul rechnet nichts nach. Es kennt die Schwelle nicht einmal.
     *
     * @param {Object} zahlen { count, average, tours } aus der API
     * @returns {string} HTML - Leerstring, wenn es nichts zu sagen gibt
     */
    kurzHtml(zahlen) {
        if (!zahlen) return '';

        const anzahl  = parseInt(zahlen.count, 10)  || 0;
        const touren  = parseInt(zahlen.tours, 10)  || 0;
        const schnitt = (zahlen.average === null || zahlen.average === undefined
                         || zahlen.average === '')
                      ? null : parseFloat(zahlen.average);

        if (schnitt !== null && !isNaN(schnitt)) {
            return '<span class="rev-short">'
                 +   this.sterneHtml(schnitt)
                 +   '<span class="rev-short__value">' + this.esc(this.zahlText(schnitt)) + '</span>'
                 +   '<span>(' + anzahl + ')</span>'
                 + '</span>';
        }

        // Kein Durchschnitt. Was hier steht, ist eine TATSACHE und keine
        // Wertung - und bei einem Standort, an dem noch nie jemand gefuehrt
        // hat, steht gar nichts: "0 Fuehrungen" ist keine Auskunft.
        if (touren < 1) return '';

        const text = 'Neu · ' + (touren === 1 ? 'eine Führung' : touren + ' Führungen')
                   + (anzahl > 0
                      ? (anzahl === 1 ? ', eine Bewertung' : ', ' + anzahl + ' Bewertungen')
                      : '');

        return '<span class="rev-short rev-short--young">' + this.esc(text) + '</span>';
    },

    /**
     * Eine Zahl mit Komma statt Punkt - und ohne ",0".
     *
     * Dieselbe Regel wie in App\Helper\ReviewView::zahl(): "4,3" auf einer
     * deutschen Seite, und "5" statt "5,0" - die Nachkommastelle sagt nur
     * dann etwas, wenn dort etwas steht.
     *
     * @param {number} wert
     * @returns {string}
     */
    zahlText(wert) {
        const zahl = Math.round((parseFloat(wert) || 0) * 10) / 10;
        return Number.isInteger(zahl)
            ? String(zahl)
            : zahl.toFixed(1).replace('.', ',');
    },

    /**
     * Maskiert Text fuer die Ausgabe.
     *
     * Titel und Anzeigenamen sind Fremdeingabe - sie gehen durch diese
     * Funktion, bevor sie in einer Zeichenkette landen, die als HTML
     * eingesetzt wird. Dieselbe Fassung wie in requests.js.
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
// Heartbeat eine offene Frage mitbringt - auf jeder Seite, auf der jemand
// angemeldet ist.
window.addEventListener('DOMContentLoaded', function() {
    window.webrtcApp.review.init();
});
