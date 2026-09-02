/**
 * Meldungen und Rueckfragen im Erscheinungsbild der Anwendung.
 *
 * WOZU
 * ----
 * Die Anwendung benutzte an 34 Stellen alert(), confirm() und prompt().
 * Diese Dialoge kommen vom Browser: Sie sehen auf jedem System anders aus,
 * haben nichts mit der Anwendung zu tun, blockieren den ganzen Tab und lassen
 * sich nicht gestalten. Beim Sperren eines Standorts wurde sogar der Grund
 * ueber prompt() erfragt - mitten in einem Moderationsablauf ein Systemfeld.
 *
 * Hier steht der Ersatz, EINMAL:
 *
 *   notify.info / success / error(text)
 *       Ein kurzer Hinweis, der von selbst wieder verschwindet. Fuer alles,
 *       was der Nutzer zur Kenntnis nimmt und nicht beantworten muss.
 *
 *   notify.alert({ title, text })                 -> Promise<void>
 *       Eine Meldung, die bestaetigt werden muss. Nur fuer das, was nicht
 *       uebersehen werden darf.
 *
 *   notify.confirm({ title, text, confirmText, cancelText, danger })
 *                                                 -> Promise<boolean>
 *       Rueckfrage mit zwei Knoepfen.
 *
 *   notify.prompt({ title, text, label, value, placeholder, required })
 *                                                 -> Promise<string|null>
 *       Rueckfrage mit Eingabefeld. null heisst abgebrochen.
 *
 * WARUM <dialog>
 * --------------
 * Das Element bringt mit, was ein selbstgebauter Dialog nachbilden muesste:
 * Es liegt in der obersten Ebene des Browsers (also auch ueber der
 * Call-Ansicht, ohne dass an z-index gedreht werden muss), faengt den Fokus
 * ein, laesst sich mit Escape schliessen und legt einen Hintergrund darunter.
 *
 * KEINE NEUE ABHAENGIGKEIT
 * ------------------------
 * Reines DOM, kein jQuery, keine Bibliothek. Die Gestaltung steht in
 * assets/css/theme.css unter .app-toast und .app-dialog.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.notify = {

    /** Wie lange ein Hinweis stehen bleibt. */
    TOAST_MS: 4500,

    /** Fehler bleiben laenger - sie erklaeren meist, warum etwas nicht ging. */
    TOAST_MS_ERROR: 7000,

    /**
     * Der Bereich, in dem die Hinweise liegen. Wird beim ersten Hinweis
     * angelegt, damit auf Seiten ohne Meldung nichts im Dokument steht.
     *
     * @returns {HTMLElement}
     */
    toastArea() {
        let bereich = document.getElementById('app-toasts');
        if (!bereich) {
            bereich = document.createElement('div');
            bereich.id = 'app-toasts';
            bereich.className = 'app-toasts';
            // aria-live: Vorleseprogramme geben neue Hinweise aus, ohne dass
            // der Fokus dorthin springt.
            bereich.setAttribute('aria-live', 'polite');
            document.body.appendChild(bereich);
        }
        return bereich;
    },

    /**
     * Zeigt einen kurzen Hinweis.
     *
     * @param {string} text
     * @param {string} [art] 'info' (Vorgabe), 'success' oder 'error'
     */
    toast(text, art) {
        const sorte = (art === 'success' || art === 'error') ? art : 'info';
        const el = document.createElement('div');
        el.className = 'app-toast app-toast--' + sorte;
        el.setAttribute('role', sorte === 'error' ? 'alert' : 'status');

        const inhalt = document.createElement('span');
        inhalt.className = 'app-toast__text';
        // textContent: Die Texte kommen teils aus Serverantworten.
        inhalt.textContent = String(text ?? '');

        const zu = document.createElement('button');
        zu.type = 'button';
        zu.className = 'app-toast__close';
        zu.setAttribute('aria-label', 'Hinweis schließen');
        zu.textContent = '×';

        el.appendChild(inhalt);
        el.appendChild(zu);
        this.toastArea().appendChild(el);

        const weg = () => {
            if (!el.parentNode) return;
            el.classList.add('app-toast--out');
            // Erst nach dem Ausblenden entfernen. Kommt kein
            // transitionend - etwa weil der Tab im Hintergrund liegt -,
            // raeumt der Zeitgeber auf.
            setTimeout(() => el.remove(), 250);
        };

        zu.addEventListener('click', weg);
        setTimeout(weg, sorte === 'error' ? this.TOAST_MS_ERROR : this.TOAST_MS);
    },

    /** @param {string} text */
    info(text)    { this.toast(text, 'info'); },
    /** @param {string} text */
    success(text) { this.toast(text, 'success'); },
    /** @param {string} text */
    error(text)   { this.toast(text, 'error'); },

    /**
     * Baut das Grundgeruest eines Dialogs.
     *
     * @param {Object} opt
     * @returns {{dialog: HTMLElement, body: HTMLElement, actions: HTMLElement}}
     */
    buildDialog(opt) {
        const dialog = document.createElement('dialog');
        dialog.className = 'app-dialog' + (opt.danger ? ' app-dialog--danger' : '');

        const kopf = document.createElement('div');
        kopf.className = 'app-dialog__head';
        const titel = document.createElement('h2');
        titel.className = 'app-dialog__title';
        titel.textContent = opt.title || '';
        kopf.appendChild(titel);

        const body = document.createElement('div');
        body.className = 'app-dialog__body';
        if (opt.text) {
            const p = document.createElement('p');
            p.className = 'app-dialog__text';
            p.textContent = opt.text;
            body.appendChild(p);
        }

        const actions = document.createElement('div');
        actions.className = 'app-dialog__actions';

        dialog.appendChild(kopf);
        dialog.appendChild(body);
        dialog.appendChild(actions);
        document.body.appendChild(dialog);

        return { dialog, body, actions };
    },

    /**
     * Zeigt den Dialog und raeumt ihn nach dem Schliessen wieder weg.
     *
     * @param {HTMLElement} dialog
     * @param {Function} fertig Wird mit dem Rueckgabewert aufgerufen
     * @param {*} abbruchWert Wert bei Escape oder Klick daneben
     */
    openDialog(dialog, fertig, abbruchWert) {
        let ergebnis = abbruchWert;

        dialog.addEventListener('close', () => {
            dialog.remove();
            fertig(ergebnis);
        });

        // Klick auf den Hintergrund schliesst - das Element selbst fuellt bei
        // einem Klick daneben den ganzen Schirm, deshalb der Vergleich auf
        // das Ziel.
        dialog.addEventListener('click', (e) => {
            if (e.target === dialog) dialog.close();
        });

        dialog.__setzeErgebnis = (wert) => { ergebnis = wert; };

        // showModal gibt es seit Langem in allen aktuellen Browsern. Fehlt es
        // doch, wird der Dialog wenigstens sichtbar - besser als gar nichts.
        if (typeof dialog.showModal === 'function') dialog.showModal();
        else dialog.setAttribute('open', '');
    },

    /**
     * Ein Knopf im Dialog.
     *
     * @param {string} text
     * @param {string} klasse
     * @returns {HTMLButtonElement}
     */
    button(text, klasse) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn ' + klasse;
        b.textContent = text;
        return b;
    },

    /**
     * Meldung, die bestaetigt werden muss.
     *
     * @param {Object|string} opt Text oder { title, text, okText }
     * @returns {Promise<void>}
     */
    alert(opt) {
        const o = (typeof opt === 'string') ? { text: opt } : (opt || {});
        return new Promise(resolve => {
            const { dialog, actions } = this.buildDialog({
                title: o.title || 'Hinweis',
                text: o.text
            });
            const ok = this.button(o.okText || 'Verstanden', 'btn-primary');
            ok.addEventListener('click', () => dialog.close());
            actions.appendChild(ok);
            this.openDialog(dialog, () => resolve(), undefined);
            ok.focus();
        });
    },

    /**
     * Rueckfrage mit zwei Knoepfen.
     *
     * @param {Object|string} opt
     * @returns {Promise<boolean>}
     */
    confirm(opt) {
        const o = (typeof opt === 'string') ? { text: opt } : (opt || {});
        return new Promise(resolve => {
            const { dialog, actions } = this.buildDialog({
                title: o.title || 'Sind Sie sicher?',
                text: o.text,
                danger: !!o.danger
            });

            const abbrechen = this.button(o.cancelText || 'Abbrechen', 'btn-secondary');
            const bestaetigen = this.button(
                o.confirmText || 'Ja',
                o.danger ? 'btn-danger' : 'btn-primary'
            );

            abbrechen.addEventListener('click', () => dialog.close());
            bestaetigen.addEventListener('click', () => {
                dialog.__setzeErgebnis(true);
                dialog.close();
            });

            actions.appendChild(abbrechen);
            actions.appendChild(bestaetigen);

            this.openDialog(dialog, (wert) => resolve(wert === true), false);
            // Der Fokus liegt auf dem harmlosen Knopf: Wer blind die
            // Eingabetaste drueckt, loescht nichts.
            abbrechen.focus();
        });
    },

    /**
     * Rueckfrage mit Eingabefeld.
     *
     * @param {Object} opt { title, text, label, value, placeholder, required,
     *                       confirmText, cancelText, multiline }
     * @returns {Promise<string|null>} null bei Abbruch
     */
    prompt(opt) {
        const o = opt || {};
        return new Promise(resolve => {
            const { dialog, body, actions } = this.buildDialog({
                title: o.title || 'Eingabe',
                text: o.text
            });

            const feldId = 'app-dialog-input';
            const wrap = document.createElement('div');
            wrap.className = 'app-field';

            if (o.label) {
                const label = document.createElement('label');
                label.className = 'form-label';
                label.setAttribute('for', feldId);
                label.textContent = o.label;
                wrap.appendChild(label);
            }

            const feld = o.multiline
                ? document.createElement('textarea')
                : document.createElement('input');
            feld.id = feldId;
            feld.className = 'form-control';
            if (!o.multiline) feld.type = 'text';
            if (o.multiline) feld.rows = 3;
            feld.value = o.value || '';
            if (o.placeholder) feld.placeholder = o.placeholder;
            wrap.appendChild(feld);

            // Platz fuer den Hinweis, dass die Eingabe fehlt. Er steht im
            // Dialog und nicht in einem zweiten Fenster darueber.
            const hinweis = document.createElement('p');
            hinweis.className = 'app-dialog__hint';
            hinweis.hidden = true;
            wrap.appendChild(hinweis);

            body.appendChild(wrap);

            const abbrechen = this.button(o.cancelText || 'Abbrechen', 'btn-secondary');
            const senden    = this.button(o.confirmText || 'Speichern', 'btn-primary');

            const absenden = () => {
                const wert = feld.value.trim();
                if (o.required && wert === '') {
                    // Nicht schliessen, sondern sagen, was fehlt.
                    hinweis.textContent = o.requiredText || 'Bitte etwas eintragen.';
                    hinweis.hidden = false;
                    feld.focus();
                    return;
                }
                dialog.__setzeErgebnis(wert);
                dialog.close();
            };

            abbrechen.addEventListener('click', () => dialog.close());
            senden.addEventListener('click', absenden);

            // Eingabetaste im einzeiligen Feld sendet ab.
            feld.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !o.multiline) {
                    e.preventDefault();
                    absenden();
                }
            });

            actions.appendChild(abbrechen);
            actions.appendChild(senden);

            this.openDialog(dialog, (wert) => resolve(wert === undefined ? null : wert), undefined);
            feld.focus();
        });
    }
};
