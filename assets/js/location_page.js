/**
 * Die Seite eines einzelnen Standorts.
 *
 * WAS DIESES MODUL TUT
 * --------------------
 * Die Seite kommt FERTIG vom Server (App\Controller\LocationController).
 * Titel, Beschreibung, Dauer, Sprachen, Bilder und der Anrufknopf stehen
 * schon im Dokument, bevor dieses Skript laeuft - wer es abschaltet, sieht
 * den Standort trotzdem vollstaendig. Ergaenzt werden hier nur die vier
 * Dinge, die ohne Skript nicht gehen:
 *
 *   1. Die kleine Karte mit dem Treffpunkt.
 *   2. Die Grossansicht der Beispielbilder samt Blaettern. Ohne Skript ist
 *      jede Kachel ein Verweis auf das Bild selbst - ein Klick oeffnet es
 *      dann eben, statt es hier zu zeigen. Der KOPF der Seite braucht das
 *      nicht mehr: Dort steht ein einzelnes Bild, das Titelbild, und das
 *      liefert der Server fertig aus.
 *   3. Der Verfuegbarkeitszustand im Takt. Ohne ihn stuende auf einer lange
 *      offenen Seite ein "Jetzt verfuegbar" von vor einer Stunde.
 *   4. Das Bearbeiten samt Bildverwaltung - nur beim Eigentuemer, und nur,
 *      weil der Server das Formular nur ihm geliefert hat.
 *
 * DER ANRUF GIBT DIE STANDORTKENNUNG MIT. Das ist die eine Stelle, an der
 * dieses Modul in den Ablauf des Calls eingreift, und sie ist wichtig: Am
 * Standort haengt beim Server die Rollenvergabe - von einem Standort aus
 * fuehrt der Angerufene, auch wenn er Admin ist
 * (App\Controller\WebRTCController::callRoles). Ginge die Kennung hier
 * verloren, waere jede Fuehrung ueber einen Admin-Standort ein Gespraech
 * ohne Fuehrung.
 *
 * WAS ES NICHT TUT
 * ----------------
 * Es entscheidet nichts ueber Berechtigungen. Dass ein Gast keinen
 * Anrufknopf hat, ist eine Entscheidung des Servers - hier fehlt der Knopf
 * dann einfach. Und dass das Bearbeitungsformular nur dem Eigentuemer
 * gehoert, steht ebenfalls dort; die Routen dahinter pruefen es erneut.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.locationPage = {

    /**
     * Takt der Zustandsabfrage.
     *
     * Derselbe Wert wie in Karte und Liste (home_map.js,
     * locations_table.js): etwas laenger als der Heartbeat aus
     * config/presence.php, damit nicht haeufiger gefragt wird, als sich die
     * Daten aendern koennen.
     */
    REFRESH_MS: 15000,

    /** Zoomstufe der kleinen Karte - dasselbe Mass wie home_map.SINGLE_ZOOM. */
    ZOOM: 15,

    map: null,
    refreshTimer: null,

    /** Die Angaben, die der Server mitgeliefert hat (window.locationPage). */
    daten: null,

    /**
     * Einstiegspunkt.
     *
     * Bricht ab, wenn der Server keine Seitendaten mitgeliefert hat - dann
     * ist das hier keine Standortseite. Die Abfrage haengt bewusst an
     * window.locationPage und nicht an einem Element: Ein Element findet man
     * auf jeder Seite, auf der jemand dieselbe id vergibt.
     */
    init() {
        if (!window.locationPage) return;
        this.daten = window.locationPage;

        this.initMap();
        this.bindLightbox();
        this.bindCall();
        this.startAutoRefresh();

        if (this.daten.isOwn) this.bindEdit();
    },

    // -----------------------------------------------------------------
    // Karte
    // -----------------------------------------------------------------

    /**
     * Zeichnet den Treffpunkt.
     *
     * Ohne Leaflet (Werbeblocker, kein Netz) bleibt der Kasten leer statt
     * eine Fehlermeldung zu zeigen: Die Karte ist hier eine Zugabe, die
     * Angaben daneben stehen ohnehin.
     */
    initMap() {
        const el = document.getElementById('loc-map');
        if (!el || typeof L === 'undefined') return;
        // Auf den Typ pruefen und nicht nur auf isFinite: isFinite(null) ist
        // true, weil null zu 0 wird - und 0/0 ist ein gueltiger Punkt im
        // Atlantik. Ein Standort ohne Koordinaten bekommt keine Karte.
        if (typeof this.daten.lat !== 'number' || typeof this.daten.lon !== 'number') return;
        if (!isFinite(this.daten.lat) || !isFinite(this.daten.lon)) return;

        this.map = L.map(el, {
            center: [this.daten.lat, this.daten.lon],
            zoom: this.ZOOM,
            zoomControl: true,
            // Kein Zoomen mit dem Rad: Wer auf einer langen Seite scrollt und
            // dabei ueber die Karte faehrt, will weiterlesen und nicht
            // hineinzoomen.
            scrollWheelZoom: false,
            attributionControl: true
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap',
            maxZoom: 19
        }).addTo(this.map);

        L.marker([this.daten.lat, this.daten.lon], { title: this.daten.place || 'Treffpunkt' })
            .addTo(this.map);
    },

    // -----------------------------------------------------------------
    // Grossansicht der Beispielbilder
    // -----------------------------------------------------------------

    /** Nummer des gerade gezeigten Bildes. */
    slide: 0,

    /** Die Kacheln der Galerie, in ihrer Reihenfolge. */
    shots: [],

    /**
     * Haengt die Grossansicht ein.
     *
     * OHNE SIE FUNKTIONIERT DIE SEITE TROTZDEM: Jede Kachel ist ein Verweis
     * auf das Bild in voller Groesse. Was hier dazukommt, ist das Ansehen
     * OHNE die Seite zu verlassen - und das Blaettern, das ein einzelnes Bild
     * im Browser nicht kann.
     *
     * Der Handler haengt am Behaelter und nicht an jeder Kachel: Die Galerie
     * steht fest im ausgelieferten Dokument, aber ein Handler weniger ist ein
     * Handler weniger.
     */
    bindLightbox() {
        const streifen = document.getElementById('loc-shots');
        const kasten   = document.getElementById('loc-lightbox');
        if (!streifen || !kasten) return;

        this.shots = Array.from(streifen.querySelectorAll('.loc-shots__item'));
        if (this.shots.length === 0) return;

        streifen.addEventListener('click', (e) => {
            const kachel = e.target.closest('.loc-shots__item');
            if (!kachel) return;
            // Erst hier: Ohne Skript soll der Verweis das Bild oeffnen.
            e.preventDefault();
            this.openLightbox(this.shots.indexOf(kachel));
        });

        kasten.addEventListener('click', (e) => {
            const pfeil = e.target.closest('[data-slide-step]');
            if (pfeil) {
                this.showSlide(this.slide + parseInt(pfeil.getAttribute('data-slide-step'), 10));
                return;
            }
            // Ein Klick auf die Flaeche daneben schliesst - so wie man es von
            // jeder Grossansicht erwartet. Ein Klick auf das Bild selbst
            // nicht: Wer es ansieht, will es nicht versehentlich wegklicken.
            if (e.target === kasten || e.target.closest('.loc-lightbox__close')) {
                this.closeLightbox();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (kasten.hidden) return;
            if (e.key === 'Escape')     { this.closeLightbox(); return; }
            if (e.key === 'ArrowLeft')  { this.showSlide(this.slide - 1); return; }
            if (e.key === 'ArrowRight') { this.showSlide(this.slide + 1); }
        });
    },

    /**
     * Oeffnet die Grossansicht bei einem bestimmten Bild.
     *
     * Das Scrollen der Seite darunter wird gesperrt: Sonst rollt der Hintergrund
     * weg, waehrend man mit dem Rad durch die Bilder blaettern will, und beim
     * Schliessen steht man an einer anderen Stelle als vorher.
     *
     * @param {number} nr
     */
    openLightbox(nr) {
        const kasten = document.getElementById('loc-lightbox');
        if (!kasten || this.shots.length === 0) return;

        kasten.hidden = false;
        document.body.style.overflow = 'hidden';
        this.showSlide(nr);
        document.getElementById('loc-lightbox-close')?.focus();
    },

    /** Schliesst die Grossansicht und gibt das Scrollen wieder frei. */
    closeLightbox() {
        const kasten = document.getElementById('loc-lightbox');
        if (!kasten) return;
        kasten.hidden = true;
        document.body.style.overflow = '';
    },

    /**
     * Zeigt ein Bild in der Grossansicht.
     *
     * Die Nummer laeuft im Kreis: Hinter dem letzten Bild kommt wieder das
     * erste. Ein Pfeil, der am Ende nichts mehr tut, sieht kaputt aus, und
     * ihn dort zu sperren hiesse, bei jedem Wechsel zwei Knoepfe umzuschalten.
     *
     * @param {number} nr Kann ueber die Grenzen hinausgehen
     */
    showSlide(nr) {
        const anzahl = this.shots ? this.shots.length : 0;
        if (anzahl === 0) return;

        // Modulo zweimal: In JavaScript ist (-1 % 5) gleich -1 und nicht 4.
        this.slide = ((nr % anzahl) + anzahl) % anzahl;

        const bild = document.getElementById('loc-lightbox-image');
        const quelle = this.shots[this.slide];
        if (bild && quelle) {
            bild.src = quelle.getAttribute('data-full') || quelle.getAttribute('href') || '';
            bild.alt = quelle.querySelector('img')?.getAttribute('alt') || '';
        }

        // Bei einem einzelnen Bild gibt es nichts zu blaettern.
        document.querySelectorAll('.loc-lightbox__nav').forEach(
            el => { el.hidden = (anzahl < 2); });
    },

    // -----------------------------------------------------------------
    // Der Anruf
    // -----------------------------------------------------------------

    /**
     * Startet die Fuehrung.
     *
     * MIT DER STANDORTKENNUNG. Siehe der Kopf dieser Datei: Daran haengt die
     * Rollenvergabe des Servers.
     *
     * Der Handler haengt am Dokument, weil der Knopf ausgetauscht wird,
     * sobald sich die Verfuegbarkeit aendert (siehe applyState).
     */
    bindCall() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.loc-call-btn');
            if (!btn || btn.disabled) return;
            e.preventDefault();

            const userId     = btn.getAttribute('data-userid');
            const locationId = btn.getAttribute('data-locationid');
            if (!userId) return;

            if (typeof window.webrtcApp?.rtc?.startCall !== 'function') {
                window.webrtcApp.notify.error('Die Anruffunktion steht auf dieser Seite nicht zur Verfügung.');
                return;
            }
            window.webrtcApp.rtc.startCall(userId, locationId);
        });
    },

    // -----------------------------------------------------------------
    // Verfuegbarkeit
    // -----------------------------------------------------------------

    /** Fragt den Zustand im Takt nach - im ausgeblendeten Tab nicht. */
    startAutoRefresh() {
        if (this.refreshTimer) clearInterval(this.refreshTimer);
        this.refreshTimer = setInterval(() => {
            if (document.hidden) return;
            this.loadState();
        }, this.REFRESH_MS);

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) this.loadState();
        });
    },

    /**
     * Holt den aktuellen Zustand.
     *
     * Ein fehlgeschlagener Abruf aendert NICHTS: Der zuletzt bekannte Stand
     * bleibt stehen. Einen Guide wegen einer einzelnen verlorenen Antwort auf
     * "nicht verfuegbar" zu setzen waere schlechter als eine Angabe, die
     * fuenfzehn Sekunden alt ist.
     */
    loadState() {
        if (!this.daten) return;

        fetch('index.php?act=get_location_state&id=' + encodeURIComponent(this.daten.id),
              { credentials: 'same-origin' })
            .then(r => r.ok ? r.json() : null)
            .then(antwort => {
                if (!antwort || !antwort.success) return;
                this.applyState(antwort.availability);
            })
            .catch(() => {});
    },

    /**
     * Uebernimmt einen neuen Zustand in Marke und Knopf.
     *
     * @param {string} zustand 'live', 'busy' oder 'idle'
     */
    applyState(zustand) {
        if (!this.daten || zustand === this.daten.availability) return;
        this.daten.availability = zustand;

        const marke = document.getElementById('loc-state');
        if (marke && !this.daten.isOwn) marke.innerHTML = this.stateTagHtml(zustand);

        const knopf = document.querySelector('.loc-call-btn');
        if (!knopf) return;

        const anrufbar = (zustand === 'live') && !!this.daten.userId;
        knopf.disabled = !anrufbar;
        knopf.classList.toggle('btn-success', anrufbar);
        knopf.classList.toggle('btn-secondary', !anrufbar);

        if (anrufbar) {
            // Die Kennungen stehen erst jetzt am Knopf: Der Server liefert
            // einen gesperrten Knopf ohne sie aus, damit ein nachgebauter
            // Klick auf einem grauen Knopf ins Leere geht.
            knopf.setAttribute('data-userid', String(this.daten.userId));
            knopf.setAttribute('data-locationid', String(this.daten.id));
            knopf.removeAttribute('aria-disabled');
        } else {
            knopf.setAttribute('aria-disabled', 'true');
        }
    },

    /**
     * Die Zustandsmarke als HTML.
     *
     * Dieselben drei Werte und dieselben Klassen wie im Kartenfenster
     * (home_map.popupTag) und auf der Serverseite
     * (LocationController::zustandHtml). Es sind drei Bauorte fuer dieselbe
     * Marke - eine Doppelung, die bleibt, solange die Seite serverseitig
     * ausgeliefert und im Browser nachgezogen wird.
     *
     * @param {string} zustand
     * @returns {string} HTML
     */
    stateTagHtml(zustand) {
        if (zustand === 'live') {
            return '<span class="app-tag app-tag--live"><span class="app-dot"></span>Jetzt verfügbar</span>';
        }
        if (zustand === 'busy') {
            return '<span class="app-tag app-tag--warn"><span class="app-dot"></span>Im Gespräch</span>';
        }
        return '<span class="app-tag">Kein Guide vor Ort</span>';
    },

    // -----------------------------------------------------------------
    // Bearbeiten
    // -----------------------------------------------------------------

    /**
     * Haengt das Bearbeitungsformular ein.
     *
     * Wird nur aufgerufen, wenn der Server es geliefert hat - also beim
     * Eigentuemer.
     */
    bindEdit() {
        const bereich = document.getElementById('loc-edit-body');
        const knopf   = document.getElementById('loc-edit-toggle');
        if (!bereich || !knopf) return;

        knopf.addEventListener('click', () => this.toggleEdit(bereich.hidden));
        document.getElementById('loc-edit-cancel')
            ?.addEventListener('click', () => this.toggleEdit(false));

        // Aufgeklappt ankommen: Der Server schickt nach einer abgelehnten
        // Aenderung auf diese Seite zurueck (edit=1). Stuende das Formular
        // dann zu, saehe der Guide nur die Fehlermeldung und nicht das Feld,
        // um das es geht.
        if (new URLSearchParams(window.location.search).get('edit') === '1') {
            this.toggleEdit(true);
        }

        this.bindImages();
    },

    /**
     * Klappt das Formular auf oder zu.
     * @param {boolean} auf
     */
    toggleEdit(auf) {
        const bereich = document.getElementById('loc-edit-body');
        const knopf   = document.getElementById('loc-edit-toggle');
        if (!bereich || !knopf) return;

        bereich.hidden = !auf;
        knopf.setAttribute('aria-expanded', auf ? 'true' : 'false');
        knopf.textContent = auf ? 'Schließen' : 'Bearbeiten';
    },

    // -----------------------------------------------------------------
    // Bilder verwalten
    // -----------------------------------------------------------------

    /** Haengt Hochladen, Loeschen und Sortieren ein. */
    bindImages() {
        const feld  = document.getElementById('loc-image-input');
        const knopf = document.getElementById('loc-image-add');
        const liste = document.getElementById('loc-image-list');
        if (!feld || !knopf || !liste) return;

        knopf.addEventListener('click', () => feld.click());
        feld.addEventListener('change', () => {
            const datei = feld.files && feld.files[0];
            // Das Feld wird geleert, damit dieselbe Datei ein zweites Mal
            // ausgewaehlt werden kann - sonst loest 'change' nicht aus.
            feld.value = '';
            if (datei) this.uploadImage(datei);
        });

        liste.addEventListener('click', (e) => {
            const kachel = e.target.closest('.loc-edit__image');
            if (!kachel) return;

            if (e.target.closest('.loc-img-cover')) { this.setCover(kachel); return; }
            if (e.target.closest('.loc-img-del'))   { this.deleteImage(kachel); return; }
            if (e.target.closest('.loc-img-up'))    { this.moveImage(kachel, -1); return; }
            if (e.target.closest('.loc-img-down'))  { this.moveImage(kachel,  1); return; }
        });

        // Das Titelbild zurueck in die Galerie. Der Knopf steht im
        // Titelbild-Abschnitt darueber und nicht an einer Kachel - er gehoert
        // zu dem einen Bild, das dort steht.
        document.getElementById('loc-cover-clear')
            ?.addEventListener('click', () => this.clearCover());

        this.updateImageHint();
    },

    /**
     * Waehlt eine Kachel als Titelbild aus.
     *
     * DIE SEITE WIRD DANACH NEU GELADEN, und das ist bewusst: Ein neues
     * Titelbild aendert den Kopf der Seite, nimmt das bisherige zurueck in die
     * Galerie und ordnet damit beide Listen neu. Das im Browser nachzubauen
     * hiesse, dieselbe Aufteilung ein zweites Mal zu programmieren - einmal
     * hier und einmal in App\Helper\LocationView. Zwei Fassungen derselben
     * Regel laufen auseinander.
     *
     * @param {Element} kachel
     */
    setCover(kachel) {
        const id = kachel.getAttribute('data-imageid');
        if (!id) return;

        fetch('index.php?act=set_location_cover&id=' + encodeURIComponent(id),
              { method: 'POST', credentials: 'same-origin' })
            .then(r => r.json())
            .then(antwort => {
                if (!antwort || !antwort.success) {
                    window.webrtcApp.notify.error(
                        (antwort && antwort.error) || 'Das Titelbild konnte nicht gesetzt werden.');
                    return;
                }
                window.location.reload();
            })
            .catch(() => window.webrtcApp.notify.error(
                'Das Titelbild konnte nicht gesetzt werden.'));
    },

    /**
     * Nimmt das Titelbild zurueck in die Galerie.
     *
     * Zurueckstufen, nicht loeschen: Wer sein Titelbild absetzt, will fast
     * immer ein anderes waehlen und nicht dieses Bild verlieren. Deshalb auch
     * keine Rueckfrage - es geht nichts verloren.
     */
    clearCover() {
        if (!this.daten) return;

        const daten = new URLSearchParams();
        daten.set('location_id', String(this.daten.id));

        fetch('index.php?act=unset_location_cover', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: daten.toString()
        })
            .then(r => r.json())
            .then(antwort => {
                if (!antwort || !antwort.success) {
                    window.webrtcApp.notify.error(
                        (antwort && antwort.error) || 'Das Titelbild konnte nicht geändert werden.');
                    return;
                }
                window.location.reload();
            })
            .catch(() => window.webrtcApp.notify.error(
                'Das Titelbild konnte nicht geändert werden.'));
    },

    /**
     * Prueft eine Datei, bevor sie hochgeladen wird.
     *
     * DIE GRENZEN KOMMEN VOM SERVER (window.locationPage.upload, gespeist aus
     * config/uploads.php). Hier steht keine zweite Zahl - sonst liefen zwei
     * Werte auseinander, und der Nutzer bekaeme erst nach acht Megabyte
     * Uebertragung zu hoeren, dass es zu viel war.
     *
     * Die Pruefung ist eine Hoeflichkeit, keine Absicherung: Verbindlich ist
     * die des Servers (App\Helper\ImageStore::store).
     *
     * @param {File} datei
     * @returns {string} Fehlertext, oder Leerstring wenn in Ordnung
     */
    checkFile(datei) {
        const grenzen = (this.daten && this.daten.upload) || {};

        if (grenzen.accept && datei.type && grenzen.accept.split(',').indexOf(datei.type) === -1) {
            return 'Dieses Bildformat wird nicht angenommen (JPEG, PNG oder WebP).';
        }
        if (grenzen.maxBytes && datei.size > grenzen.maxBytes) {
            return 'Die Datei ist zu groß – erlaubt sind '
                 + Math.round(grenzen.maxBytes / 1048576) + ' MB.';
        }
        return '';
    },

    /**
     * Laedt eine Datei hoch und haengt die neue Kachel an.
     *
     * @param {File} datei
     */
    uploadImage(datei) {
        const fehler = this.checkFile(datei);
        if (fehler) { window.webrtcApp.notify.error(fehler); return; }

        const knopf = document.getElementById('loc-image-add');
        if (knopf) knopf.disabled = true;

        const daten = new FormData();
        daten.append('location_id', String(this.daten.id));
        daten.append('image', datei);

        fetch('index.php?act=upload_location_image',
              { method: 'POST', body: daten, credentials: 'same-origin' })
            .then(r => r.json())
            .then(antwort => {
                if (!antwort || !antwort.success) {
                    window.webrtcApp.notify.error(
                        (antwort && antwort.error) || 'Das Bild konnte nicht gespeichert werden.');
                    return;
                }
                // Wurde das Bild zum TITELBILD - weil der Standort noch
                // keines hatte -, gehoert es nicht in die Galerie, sondern in
                // den Abschnitt darueber. Statt beide Listen hier nachzubauen,
                // laedt die Seite neu; der Server teilt sie ohnehin.
                if (antwort.image && antwort.image.role === 'cover') {
                    window.location.reload();
                    return;
                }
                this.appendImage(antwort.image);
                window.webrtcApp.notify.success('Bild hinzugefügt.');
            })
            .catch(() => window.webrtcApp.notify.error('Das Bild konnte nicht gespeichert werden.'))
            .then(() => {
                if (knopf) knopf.disabled = false;
                this.updateImageHint();
            });
    },

    /**
     * Haengt eine neue Kachel an die Liste.
     *
     * Ohne Neuladen der Seite: Wer drei Bilder nacheinander hochlaedt, soll
     * nicht dreimal eine Seite aufbauen und dreimal zum Formular
     * zurueckscrollen.
     *
     * @param {Object} bild { id, thumb, full }
     */
    appendImage(bild) {
        const liste = document.getElementById('loc-image-list');
        if (!liste || !bild) return;

        liste.querySelector('[data-empty]')?.remove();

        const nr = liste.querySelectorAll('.loc-edit__image').length + 1;
        const li = document.createElement('li');
        li.className = 'loc-edit__image';
        li.setAttribute('data-imageid', String(bild.id));
        li.innerHTML =
              '<img src="' + this.esc(bild.thumb) + '" alt="Bild ' + nr + '">'
            + '<div class="loc-edit__imageActions">'
            +   '<button type="button" class="app-iconbtn app-iconbtn--cover loc-img-cover"'
            +   ' aria-label="Bild ' + nr + ' als Titelbild verwenden" title="Als Titelbild"></button>'
            +   '<button type="button" class="app-iconbtn app-iconbtn--up loc-img-up"'
            +   ' aria-label="Bild ' + nr + ' nach vorne" title="Nach vorne"></button>'
            +   '<button type="button" class="app-iconbtn app-iconbtn--down loc-img-down"'
            +   ' aria-label="Bild ' + nr + ' nach hinten" title="Nach hinten"></button>'
            +   '<button type="button" class="app-iconbtn app-iconbtn--delete app-iconbtn--danger loc-img-del"'
            +   ' aria-label="Bild ' + nr + ' löschen" title="Löschen"></button>'
            + '</div>';
        liste.appendChild(li);
    },

    /**
     * Loescht ein Bild nach Rueckfrage.
     *
     * @param {Element} kachel
     */
    deleteImage(kachel) {
        const id = kachel.getAttribute('data-imageid');
        if (!id) return;

        window.webrtcApp.notify.confirm({
            title: 'Bild löschen?',
            text: 'Das Bild verschwindet von der Standortseite. Das lässt sich nicht rückgängig machen.',
            confirmText: 'Löschen',
            danger: true
        }).then(ja => {
            if (!ja) return;

            fetch('index.php?act=delete_location_image&id=' + encodeURIComponent(id),
                  { method: 'POST', credentials: 'same-origin' })
                .then(r => r.json())
                .then(antwort => {
                    if (!antwort || !antwort.success) {
                        window.webrtcApp.notify.error(
                            (antwort && antwort.error) || 'Das Bild konnte nicht gelöscht werden.');
                        return;
                    }
                    kachel.remove();
                    // War es das letzte, kommt der Hinweis zurueck. Eine
                    // leere Liste ohne Text sieht aus wie ein Fehler.
                    const liste = document.getElementById('loc-image-list');
                    if (liste && liste.querySelectorAll('.loc-edit__image').length === 0) {
                        liste.innerHTML =
                            '<p class="loc__empty" data-empty>Noch keine Beispielbilder hochgeladen.</p>';
                    }
                    this.updateImageHint();
                })
                .catch(() => window.webrtcApp.notify.error('Das Bild konnte nicht gelöscht werden.'));
        });
    },

    /**
     * Verschiebt eine Kachel um eine Position und speichert die Reihenfolge.
     *
     * Knoepfe statt Ziehen: Ziehen funktioniert auf einem Mobilgeraet
     * schlecht und mit der Tastatur gar nicht.
     *
     * @param {Element} kachel
     * @param {number}  richtung -1 nach vorn, 1 nach hinten
     */
    moveImage(kachel, richtung) {
        const nachbar = richtung < 0
            ? kachel.previousElementSibling
            : kachel.nextElementSibling;
        if (!nachbar || !nachbar.classList.contains('loc-edit__image')) return;

        if (richtung < 0) nachbar.before(kachel);
        else              nachbar.after(kachel);

        this.saveOrder();
    },

    /**
     * Die Reihenfolge, wie sie gerade in der Liste steht.
     *
     * Eigene Methode und ohne Seiteneffekte, damit sich die Reihenfolge
     * pruefen laesst, ohne etwas zu schicken.
     *
     * @param {Element} [liste]
     * @returns {string[]} Bild-IDs in ihrer Folge
     */
    currentOrder(liste) {
        const el = liste || document.getElementById('loc-image-list');
        if (!el) return [];
        return Array.from(el.querySelectorAll('.loc-edit__image'))
            .map(k => k.getAttribute('data-imageid'))
            .filter(Boolean);
    },

    /**
     * Schickt die vollstaendige neue Reihenfolge.
     *
     * Vollstaendig und nicht "dieses Bild eins nach vorn": Das Umsortieren
     * ist EINE Entscheidung des Nutzers und wird als eine gespeichert. Sonst
     * stuende die Reihenfolge zwischen zwei Aufrufen in einem Zustand, den
     * niemand gewollt hat.
     */
    saveOrder() {
        const folge = this.currentOrder();
        if (folge.length === 0) return;

        const daten = new URLSearchParams();
        daten.set('location_id', String(this.daten.id));
        daten.set('order', folge.join(','));

        fetch('index.php?act=sort_location_images', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: daten.toString()
        })
            .then(r => r.json())
            .then(antwort => {
                if (antwort && antwort.success) return;
                window.webrtcApp.notify.error(
                    (antwort && antwort.error) || 'Die Reihenfolge konnte nicht gespeichert werden.');
            })
            .catch(() => window.webrtcApp.notify.error(
                'Die Reihenfolge konnte nicht gespeichert werden.'));
    },

    /**
     * Schreibt, wie viele Bilder noch gehen, und sperrt den Knopf, wenn die
     * Obergrenze erreicht ist.
     *
     * Die Grenze kommt vom Server (config/uploads.php ueber
     * ImageStore::maxImages). Sie wird hier NICHT durchgesetzt - das tut der
     * Controller vor dem Annehmen; hier steht sie, damit der Nutzer den
     * Knopf nicht drueckt, um eine Absage zu bekommen.
     */
    updateImageHint() {
        const liste = document.getElementById('loc-image-list');
        const text  = document.getElementById('loc-image-count');
        const knopf = document.getElementById('loc-image-add');
        if (!liste || !this.daten) return;

        // DAS TITELBILD ZAEHLT MIT. Die Obergrenze gilt fuer die SUMME beider
        // Arten - sonst waere sie ueber den Umweg "als Titelbild markieren"
        // zu umgehen.
        const cover  = document.querySelector('.loc-edit__cover') ? 1 : 0;
        const anzahl = liste.querySelectorAll('.loc-edit__image').length + cover;
        const grenze = this.daten.maxImages || 0;
        const frei   = Math.max(0, grenze - anzahl);

        if (text) {
            text.textContent = frei === 0
                ? 'Die Obergrenze von ' + grenze + ' Bildern ist erreicht (Titelbild mitgezählt).'
                : 'Noch ' + frei + ' von ' + grenze + ' Bildern möglich, Titelbild mitgezählt.';
        }
        if (knopf) knopf.disabled = (frei === 0);
    },

    /**
     * Maskiert Text fuer die Ausgabe in HTML.
     * @param {*} wert
     * @returns {string}
     */
    esc(wert) {
        return String(wert ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }
};

document.addEventListener('DOMContentLoaded', function () {
    window.webrtcApp.locationPage.init();
});
