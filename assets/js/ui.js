window.webrtcApp = window.webrtcApp || {};

/**
 * UI-Modul für allgemeine Buttons und Dialoge (z.B. Standort-Button, Alle Locations, Lösch-Dialog).
 */
window.webrtcApp.ui = {
    /**
     * Zeigt den "Neue Lokation hinzufügen"-Button (für Admin/Guide)
     * oder "Jetzt Tour-Guide werden!" (für Zuschauer) je nach Rolle an.
     * Blendet den Button bei fehlender Berechtigung oder wenn nicht eingeloggt aus.
     *
     * Entschieden wird über window.userCan, das der Server aus
     * App\Helper\Role ableitet (ViewHelper::output). Hier stand früher ein
     * Vergleich gegen 'admin'/'guide'/'tourist'; window.userRole trägt aber
     * die Schreibweise aus usertype.name ('Admin', 'Guide', 'User', 'Trial'),
     * und 'tourist' gibt es dort überhaupt nicht. Der Button war dadurch für
     * jede Rolle unsichtbar (Befund F-5).
     *
     * Die Beschriftungen führen an verschiedene Stellen, und das ist der Kern
     * der Sache: "Neue Lokation hinzufügen" öffnet das Standortformular,
     * "Jetzt Tour-Guide werden!" dagegen die Frage nach der Guide-Rolle.
     * Früher führten beide zum Formular - und wer es ausfüllte, war
     * anschließend Guide, ohne je gefragt worden zu sein.
     *
     * DER DRITTE FALL geht dem ersten vor: Ein Guide, dessen Zustimmung eine
     * ältere Fassung der Bedingungen trägt (userCan.termsOutdated), bekommt
     * "Neue Bedingungen bestätigen" statt des Anlege-Knopfes. Das Formular
     * dahinter würde ihn ohnehin zur Frage weiterleiten
     * (GuideController::requireCurrentTerms) - dann soll der Knopf ihn auch
     * gleich dorthin führen und nicht so tun, als ginge es um einen Standort.
     */
    showLocationButton: function() {
        // Beide Beschriftungen sitzen auf einem Sekundaerknopf. Der Akzent
        // war hier falsch: Einen Standort anzulegen ist die SELTENSTE
        // Handlung in dieser Anwendung - man tut es einmal und danach jahrelang
        // nicht mehr. Hervorgehoben gehoert das, was oft passiert.

        var locationButtonDiv = document.getElementById('location-button');
        locationButtonDiv.innerHTML = '';
        let text = '';
        let target = '';
        if (window.isLoggedIn && window.userCan) {
            if (window.userCan.termsOutdated) {
                text = window.webrtcApp.t('kopf.knopf.bedingungen');
                target = 'index.php?act=guide_role_page';
            } else if (window.userCan.offerLocation) {
                text = window.webrtcApp.t('kopf.knopf.standort_neu');
                target = 'index.php?act=set_location_page';
            } else if (window.userCan.becomeGuide) {
                text = window.webrtcApp.t('kopf.knopf.guide_werden');
                target = 'index.php?act=guide_role_page';
            }
            if (text) {
                locationButtonDiv.innerHTML = `<a href="${target}" class="btn btn-secondary btn-sm">${text}</a>`;
                locationButtonDiv.style.display = '';
            } else {
                locationButtonDiv.style.display = 'none';
            }
        } else {
            locationButtonDiv.style.display = 'none';
        }
    },

    /**
     * Zeigt den "Alle Locations durchsuchen"-Button, wenn eingeloggt.
     * Blendet ihn sonst aus.
     */
    showAllLocationsButton: function() {
        var browseLocationButtonDiv = document.getElementById('browse-locations-button');
        browseLocationButtonDiv.innerHTML = '';
        if (window.isLoggedIn) {
            // Der Text kommt aus dem Katalog, das Markup bleibt hier - im
            // Katalog steht kein HTML (siehe lang/de.php, Regel 5).
            const beschriftung = window.webrtcApp.t('kopf.knopf.alle_standorte');
            browseLocationButtonDiv.innerHTML = `<a href="index.php?act=show_locations_page" class="btn btn-secondary btn-sm">${beschriftung}</a>`;
            browseLocationButtonDiv.style.display = '';
        } else {
            browseLocationButtonDiv.style.display = 'none';
        }
    },

    /**
     * Fragt vor dem Löschen nach und leitet dann weiter.
     *
     * Der Abbruch wird nicht mehr gemeldet: Wer "Abbrechen" drueckt, weiss,
     * dass er abgebrochen hat - das frühere zweite alert() dafuer war ein
     * Klick, der nichts sagte.
     *
     * @param {string} in_url - Ziel-URL für das Löschen
     */
    confirmDelete: function(in_url) {
        window.webrtcApp.notify.confirm({
            title: window.webrtcApp.t('dialog.loeschen.titel'),
            text: window.webrtcApp.t('dialog.loeschen.text'),
            confirmText: window.webrtcApp.t('allgemein.loeschen'),
            danger: true
        }).then(ja => {
            if (ja) window.location.href = in_url;
        });
    },

    /**
     * Fragt vor dem Absenden nach - bei jedem Formular, das es verlangt.
     *
     * WOZU
     * ----
     * Ein Formular, das etwas loescht, soll vorher fragen. Bisher ging das
     * nur bei Knoepfen, hinter denen ein fetch-Aufruf steckt
     * (locations_table.js) - ein gewoehnliches Formular, das per POST
     * abgeschickt wird, hatte keine Stelle dafuer.
     *
     * WIE ES ANGEMELDET WIRD
     * ----------------------
     * Am Formular selbst, nicht hier:
     *
     *   data-confirm         der Text der Rueckfrage (PFLICHT - ohne ihn
     *                        passiert nichts)
     *   data-confirm-title   die Ueberschrift
     *   data-confirm-ok      Beschriftung des bestaetigenden Knopfes
     *   data-confirm-danger  gesetzt: roter Knopf, fuer alles Endgueltige
     *
     * Damit braucht ein neues Formular keine Zeile JavaScript mehr, sondern
     * ein Attribut - und die Rueckfrage sieht ueberall gleich aus, weil sie
     * durch denselben Dialog geht (notify.confirm).
     *
     * DELEGIERT AM DOKUMENT, damit auch Formulare erfasst werden, die erst
     * spaeter in die Seite kommen.
     *
     * WARUM DIE MARKE data-confirmed NOETIG IST
     * -----------------------------------------
     * Nach dem Bestaetigen wird dasselbe Formular erneut abgeschickt -
     * requestSubmit() loest das submit-Ereignis noch einmal aus, und ohne die
     * Marke fragte dieser Zuhoerer wieder. Die Marke faellt danach sofort
     * weg: Wer das Formular ein zweites Mal abschickt, wird ein zweites Mal
     * gefragt.
     *
     * OHNE JAVASCRIPT bleibt das Formular ein gewoehnliches Formular und wird
     * ohne Rueckfrage abgeschickt. Das ist die bewusste Reihenfolge: erst
     * muss es funktionieren, dann bequem sein.
     */
    bindConfirmForms: function() {
        document.addEventListener('submit', function(e) {
            const form = e.target;
            if (!form || form.tagName !== 'FORM') return;

            const text = form.getAttribute('data-confirm');
            if (!text) return;

            if (form.dataset.confirmed === 'ja') {
                delete form.dataset.confirmed;
                return;
            }

            e.preventDefault();
            window.webrtcApp.notify.confirm({
                title:       form.getAttribute('data-confirm-title') || window.webrtcApp.t('dialog.sicher'),
                text:        text,
                confirmText: form.getAttribute('data-confirm-ok') || window.webrtcApp.t('dialog.ja'),
                danger:      form.hasAttribute('data-confirm-danger')
            }).then(ja => {
                if (!ja) return;
                form.dataset.confirmed = 'ja';
                // requestSubmit() und nicht submit(): Es geht denselben Weg
                // wie ein Klick auf den Knopf und laesst die Pruefung der
                // Felder laufen. submit() ueberspringt beides.
                if (typeof form.requestSubmit === 'function') form.requestSubmit();
                else form.submit();
            });
        });
    },

    /**
     * Schliesst das Benutzermenue, wenn daneben geklickt oder Escape
     * gedrueckt wird.
     *
     * Das Menue selbst ist ein <details>-Element und funktioniert ohne
     * JavaScript (siehe App\Helper\ViewHelper::userMenu). Diese Funktion
     * ergaenzt nur die Bequemlichkeit - faellt sie aus, laesst sich das Menue
     * weiterhin ueber seinen eigenen Knopf schliessen.
     */
    bindUserMenu: function() {
        const menu = document.getElementById('user-menu');
        if (!menu) return;

        document.addEventListener('click', function(e) {
            if (!menu.open) return;
            if (menu.contains(e.target)) return;
            menu.open = false;
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && menu.open) menu.open = false;
        });
    },

    /**
     * Wechselt den Display zustand des Elements.
     * @param {number} id    - Id des Ziel Elements
     * @param {string} value - Display zustand des Elements
     */
    setDisplay: function(id, value) {
        const el = document.getElementById(id);
        if (el) el.style.display = value;
    }

};
