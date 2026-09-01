/**
 * Steuerkanal: Senden, Empfangen und Anzeigen der Steuernachrichten.
 *
 * Zuständig für alles, was über den DataChannel "control" läuft - also
 * Bewegungsbefehle, Bestätigungen, die Steuerungssperre, den Videozustand und
 * das Auflegen. Der Kanal "chat" wird hier nicht angefasst; er trägt
 * ausschließlich Nutzerinhalt (chat.js).
 *
 * Das Nachrichtenformat selbst steht in protocol.js, die Referenz für die
 * spätere App in PROTOKOLL.md.
 *
 * Rollen (siehe PROTOKOLL.md, Abschnitt "Rollen"):
 *   - viewer (Zuschauer) sendet "move" und empfängt "ack" und "control_lock".
 *   - guide  (Person vor Ort) sendet "ack" und "control_lock" und empfängt "move".
 * Die Rolle kommt vom Server (WebRTCController) und wird beim Verbindungsaufbau
 * mit dem Offer ausgeliefert. Der Client vergibt sie sich nicht selbst.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.control = {
    // Frist, innerhalb derer der Guide einen Bewegungsbefehl bestätigen muss.
    // Bis dahin bleibt das Steuerkreuz gesperrt, damit der Zuschauer bei
    // Latenz nicht mehrfach drückt. Läuft sie ab, wird wieder freigegeben -
    // sonst bliebe das Steuerkreuz nach einem verlorenen "ack" für immer tot.
    ACK_TIMEOUT_MS: 2000,

    // So lange bleibt die große Richtungsanzeige beim Guide stehen.
    INDICATOR_MS: 1400,

    /**
     * Darstellung je Richtung: Pfeil und Beschriftung für die Anzeige beim
     * Guide, dazu das zugehörige Audiosignal (media.html).
     */
    DIRECTIONS: {
        forward:  { arrow: '↑', label: 'VORWÄRTS',  sound: 'move_forward_sound' },
        backward: { arrow: '↓', label: 'ZURÜCK',    sound: 'move_back_sound'    },
        left:     { arrow: '←', label: 'LINKS',          sound: 'turn_left_sound'    },
        right:    { arrow: '→', label: 'RECHTS',         sound: 'turn_right_sound'   }
    },

    /**
     * Klartext zu den Ablehnungsgründen aus dem Protokoll, für die Anzeige
     * beim Zuschauer.
     */
    REJECT_TEXTS: {
        unstable:  'Verbindung war nicht stabil.',
        locked:    'Der Guide hat die Steuerung gesperrt.',
        duplicate: 'Befehl war eine Wiederholung.',
        no_role:   'Die Gegenseite kennt ihre Rolle nicht.',
        invalid:   'Befehl wurde als ungültig abgewiesen.'
    },

    // IDs aller Steuerkreuz-Schaltflächen (Desktop und Mobile).
    ARROW_BUTTON_IDS: [
        'btn-forward', 'btn-backward', 'btn-left', 'btn-right',
        'btn-forward-mobile', 'btn-backward-mobile', 'btn-left-mobile', 'btn-right-mobile'
    ],

    // =====================================================================
    // Rolle
    // =====================================================================

    /**
     * Übernimmt die vom Server vergebene Rolle für diesen Call.
     *
     * Ein unbekannter Wert wird zu null - dann rendert kein Steuerkreuz und
     * eingehende richtungsgebundene Nachrichten werden abgelehnt. Im Zweifel
     * steuert niemand.
     *
     * @param {string|null} role - 'guide', 'viewer' oder etwas anderes
     */
    applyRole(role) {
        const p = window.webrtcApp.protocol;
        const valid = (role === p.ROLE_GUIDE || role === p.ROLE_VIEWER) ? role : null;

        if (valid === null && role !== null && role !== undefined) {
            console.warn('[Steuerung] Unbekannte Rolle vom Server verworfen:', role);
        }
        window.webrtcApp.state.callRole = valid;
        console.log('[Steuerung] Rolle in diesem Call:', valid === null ? 'unbekannt' : valid);

        this.updateRoleUi();
        this.sendHello();
    },

    /**
     * Setzt die Rollenklasse auf #call-view. Alles Rollenabhängige in der
     * Oberfläche hängt an dieser Klasse (call.css), nicht an einzelnen
     * style-Zuweisungen - so gibt es genau eine Stelle, die entscheidet.
     */
    updateRoleUi() {
        const role = window.webrtcApp.state.callRole;
        const view = document.getElementById('call-view');
        if (view && view.classList) {
            view.classList.remove('role-guide', 'role-viewer');
            if (role) view.classList.add('role-' + role);
        }
        // updateLockUi() zieht updatePadState() nach sich.
        this.updateLockUi();
    },

    /**
     * Meldet die eigene Rolle an die Gegenseite.
     *
     * Beide Seiten fragen dieselbe Serverentscheidung ab, die Rollen können
     * also nicht auseinanderlaufen. "hello" dient der Gegenprobe: Meldet der
     * Partner dieselbe Rolle wie die eigene, stimmt etwas nicht, und das soll
     * im Log stehen statt still zu bleiben.
     */
    sendHello() {
        const state = window.webrtcApp.state;
        if (!state.callRole) return;
        if (state.control.helloSent) return;
        const dc = window.webrtcApp.refs.controlChannel;
        if (!dc || dc.readyState !== 'open') return;

        if (this.send('hello', { role: state.callRole })) {
            state.control.helloSent = true;
        }
    },

    // =====================================================================
    // Senden
    // =====================================================================

    /**
     * Baut eine Protokollnachricht und schickt sie über den Steuerkanal.
     *
     * Geprüft wird vor dem Senden dasselbe wie beim Empfangen: Rolle, Schema,
     * Größe. Was hier durchfällt, geht gar nicht erst auf die Leitung.
     *
     * @param {string} type - Nachrichtentyp aus protocol.SCHEMA
     * @param {Object} [payload] - Felder der Nachricht
     * @returns {boolean} true, wenn die Nachricht abgesetzt wurde
     */
    send(type, payload) {
        const p = window.webrtcApp.protocol;
        const role = window.webrtcApp.state.callRole;

        if (!p.maySend(type, role)) {
            console.warn('[Steuerung] "' + type + '" darf mit Rolle "' + role + '" nicht gesendet werden.');
            return false;
        }

        const raw = p.serialize(type, payload);
        if (raw === null) return false;

        const dc = window.webrtcApp.refs.controlChannel;
        if (!dc || dc.readyState !== 'open') {
            console.warn('[Steuerung] Steuerkanal ist nicht offen, "' + type + '" verworfen.');
            return false;
        }

        try {
            dc.send(raw);
            return true;
        } catch (e) {
            console.warn('[Steuerung] Senden fehlgeschlagen (' + type + '):', e);
            return false;
        }
    },

    /**
     * Sendet einen Bewegungsbefehl. Nur der Zuschauer darf das.
     *
     * Die Verbindungssperre aus rtc.canSendControlCommand() bleibt davor
     * bestehen: Bei instabiler Verbindung wird verworfen statt gepuffert.
     *
     * @param {string} direction - forward|backward|left|right
     * @returns {boolean} true, wenn der Befehl abgesetzt wurde
     */
    sendMove(direction) {
        const state = window.webrtcApp.state;
        const p = window.webrtcApp.protocol;

        if (state.callRole !== p.ROLE_VIEWER) {
            console.warn('[Steuerung] Bewegungsbefehl ohne Zuschauerrolle unterdrueckt.');
            return false;
        }
        if (!Object.prototype.hasOwnProperty.call(this.DIRECTIONS, direction)) {
            console.warn('[Steuerung] Unbekannte Richtung:', direction);
            return false;
        }
        if (state.control.locked) {
            window.webrtcApp.rtc.showSystemNotice('Steuerung ist gesperrt – der Guide hat sie angehalten.');
            return false;
        }
        // Solange eine Bestätigung aussteht, wird nichts nachgeschoben.
        if (state.control.pendingSeq !== null) return false;

        // Verbindungssperre: unverändert aus dem vorherigen Stand übernommen.
        if (!window.webrtcApp.rtc.canSendControlCommand()) {
            window.webrtcApp.rtc.showSystemNotice(
                'Steuerbefehl nicht gesendet – Verbindung ist gerade nicht stabil.'
            );
            return false;
        }

        const seq = state.control.nextSeq;
        if (!this.send('move', { dir: direction, seq: seq })) {
            window.webrtcApp.rtc.showSystemNotice('Steuerbefehl nicht gesendet – Übertragungsfehler.');
            return false;
        }

        state.control.nextSeq = seq + 1;
        state.control.pendingSeq = seq;
        this.startAckTimeout(seq);
        this.updatePadState();
        return true;
    },

    /**
     * Startet die Frist, nach der ohne Bestätigung wieder freigegeben wird.
     * @param {number} seq - Sequenznummer, auf die gewartet wird
     */
    startAckTimeout(seq) {
        const state = window.webrtcApp.state;
        this.clearAckTimeout();
        state.control.ackTimer = setTimeout(() => {
            state.control.ackTimer = null;
            // Nur freigeben, wenn immer noch auf genau diesen Befehl gewartet wird.
            if (state.control.pendingSeq !== seq) return;
            state.control.pendingSeq = null;
            window.webrtcApp.control.updatePadState();
            window.webrtcApp.rtc.showSystemNotice('Keine Bestätigung für den Steuerbefehl erhalten.');
        }, this.ACK_TIMEOUT_MS);
    },

    /** Stoppt die laufende Bestätigungsfrist. */
    clearAckTimeout() {
        const control = window.webrtcApp.state.control;
        if (control.ackTimer !== null) {
            clearTimeout(control.ackTimer);
            control.ackTimer = null;
        }
    },

    /**
     * Sperrt oder gibt die Steuerung frei. Nur der Guide darf das - etwa beim
     * Überqueren einer Straße.
     *
     * @param {boolean} locked - true = sperren
     * @param {string} [reason] - Kurzer Grund für die Anzeige beim Zuschauer
     * @returns {boolean} true, wenn die Nachricht abgesetzt wurde
     */
    setLock(locked, reason) {
        const state = window.webrtcApp.state;
        if (state.callRole !== window.webrtcApp.protocol.ROLE_GUIDE) {
            console.warn('[Steuerung] control_lock ohne Guide-Rolle unterdrueckt.');
            return false;
        }

        const sent = this.send('control_lock', { locked: !!locked, reason: reason });
        if (!sent) {
            window.webrtcApp.rtc.showSystemNotice('Sperre konnte nicht übermittelt werden.');
            return false;
        }

        state.control.locked = !!locked;
        this.updateLockUi();
        return true;
    },

    /** Schaltet die Sperre um (Schaltfläche beim Guide). */
    toggleLock() {
        return this.setLock(!window.webrtcApp.state.control.locked);
    },

    /**
     * Meldet der Gegenseite, ob das eigene Videobild gerade läuft.
     * @param {boolean} on - true = Kamera sendet
     */
    sendVideoState(on) {
        return this.send('video_state', { on: !!on });
    },

    /**
     * Meldet der Gegenseite das Auflegen über den Steuerkanal.
     * @param {string} [reason] - Kurzer Grund
     */
    sendHangup(reason) {
        return this.send('hangup', { reason: reason });
    },

    // =====================================================================
    // Empfangen
    // =====================================================================

    /**
     * Einstiegspunkt für jeden Frame des Steuerkanals.
     *
     * Alles läuft zuerst durch protocol.validate(). Was dort durchfällt, wird
     * verworfen und protokolliert - und niemals als Chattext angezeigt.
     *
     * @param {*} raw - Rohdaten aus dem DataChannel
     */
    handleMessage(raw) {
        const p = window.webrtcApp.protocol;
        const result = p.validate(raw, p.CHANNEL_CONTROL, window.webrtcApp.state.callRole);

        if (!result.ok) {
            this.logRejected(p.CHANNEL_CONTROL, result, raw);
            return;
        }

        const msg = result.message;
        switch (msg.type) {
            case 'hello':        this.handleHello(msg);       break;
            case 'move':         this.handleMove(msg);        break;
            case 'ack':          this.handleAck(msg);         break;
            case 'control_lock': this.handleControlLock(msg); break;
            case 'video_state':  this.handleVideoState(msg);  break;
            case 'hangup':       window.webrtcApp.rtc.handleRemoteHangup(); break;
            default:
                // Kann nur eintreten, wenn protocol.SCHEMA einen Typ kennt,
                // für den es hier keinen Zweig gibt.
                console.warn('[Steuerung] Kein Handler fuer Typ "' + msg.type + '".');
        }
    },

    /**
     * Schreibt eine verworfene Nachricht ins Log und zählt sie mit.
     *
     * Bewusst nur Konsole und Zähler: Ein Peer, der Unsinn schickt, darf die
     * Oberfläche des Nutzers nicht zumüllen. Der Zähler ist der Haken, an dem
     * die Tests und eine spätere Auswertung hängen.
     *
     * @param {string} channel - Kanal, auf dem die Nachricht ankam
     * @param {Object} result - Rückgabe von protocol.validate()
     * @param {*} raw - Rohdaten (nur gekürzt geloggt)
     */
    logRejected(channel, result, raw) {
        const control = window.webrtcApp.state.control;
        control.rejected++;
        control.lastRejectCode = result.code;

        const preview = (typeof raw === 'string') ? raw.slice(0, 120) : '[' + typeof raw + ']';
        console.warn(
            '[Protokoll] Nachricht auf Kanal "' + channel + '" verworfen (' + result.code + '): '
            + result.detail + ' | ' + preview
        );
    },

    /**
     * Rollenmeldung der Gegenseite gegenprüfen.
     * @param {Object} msg - hello-Nachricht
     */
    handleHello(msg) {
        const expected = window.webrtcApp.protocol.peerRole(window.webrtcApp.state.callRole);
        if (expected !== null && msg.role !== expected) {
            // Die eigene Rolle wird NICHT angepasst - sie kommt vom Server.
            console.warn(
                '[Steuerung] Gegenseite meldet Rolle "' + msg.role + '", erwartet war "' + expected + '".'
            );
        }
    },

    /**
     * Bewegungsbefehl des Zuschauers ausführen (nur beim Guide).
     *
     * Jeder Ausgang wird bestätigt - ausgeführt oder abgelehnt. Ohne
     * Bestätigung wüsste der Zuschauer nicht, ob sein Druck angekommen ist,
     * und würde nachdrücken.
     *
     * @param {Object} msg - move-Nachricht
     */
    handleMove(msg) {
        const state = window.webrtcApp.state;

        // Verbindungssperre: unverändert aus dem vorherigen Stand übernommen.
        // Ein Befehl aus der Zeit vor einer Unterbrechung ist keine gültige
        // Anweisung mehr.
        if (!window.webrtcApp.rtc.mayExecuteControlCommand()) {
            this.send('ack', { seq: msg.seq, status: 'rejected', reason: 'unstable' });
            window.webrtcApp.rtc.showSystemNotice('Steuerbefehl verworfen – Verbindung war unterbrochen.');
            return;
        }

        if (state.control.locked) {
            this.send('ack', { seq: msg.seq, status: 'rejected', reason: 'locked' });
            return;
        }

        // Sequenznummern laufen je Call streng aufwärts. Alles, was nicht
        // größer als die zuletzt ausgeführte Nummer ist, ist eine Wiederholung
        // und wird nicht ein zweites Mal ausgeführt.
        if (msg.seq <= state.control.lastRemoteSeq) {
            this.send('ack', { seq: msg.seq, status: 'rejected', reason: 'duplicate' });
            return;
        }
        state.control.lastRemoteSeq = msg.seq;

        const direction = this.DIRECTIONS[msg.dir];
        window.webrtcApp.sound.play(direction.sound, false);
        this.showDirection(msg.dir);
        this.send('ack', { seq: msg.seq, status: 'executed' });
    },

    /**
     * Bestätigung des Guides verarbeiten (nur beim Zuschauer).
     * @param {Object} msg - ack-Nachricht
     */
    handleAck(msg) {
        const control = window.webrtcApp.state.control;

        // Eine Bestätigung zu einem längst abgelaufenen Befehl ändert nichts
        // mehr - sie darf insbesondere keine neuere Sperre aufheben.
        if (control.pendingSeq !== msg.seq) {
            console.log('[Steuerung] Veraltete Bestätigung fuer seq ' + msg.seq + ' ignoriert.');
            return;
        }

        this.clearAckTimeout();
        control.pendingSeq = null;
        this.updatePadState();

        if (msg.status === 'rejected') {
            const text = this.REJECT_TEXTS[msg.reason] || 'Grund unbekannt.';
            window.webrtcApp.rtc.showSystemNotice('Steuerbefehl abgelehnt – ' + text);
        }
    },

    /**
     * Sperre des Guides übernehmen (nur beim Zuschauer).
     * @param {Object} msg - control_lock-Nachricht
     */
    handleControlLock(msg) {
        const control = window.webrtcApp.state.control;
        const changed = (control.locked !== msg.locked);
        control.locked = msg.locked;
        this.updateLockUi();
        this.updatePadState();

        if (!changed) return;
        if (msg.locked) {
            window.webrtcApp.rtc.showSystemNotice(
                'Der Guide hat die Steuerung gesperrt.' + (msg.reason ? ' (' + msg.reason + ')' : '')
            );
        } else {
            window.webrtcApp.rtc.showSystemNotice('Der Guide hat die Steuerung wieder freigegeben.');
        }
    },

    /**
     * Videozustand der Gegenseite anzeigen.
     * @param {Object} msg - video_state-Nachricht
     */
    handleVideoState(msg) {
        const remoteVideo = document.getElementById('remote-video');
        const placeholder = document.getElementById('remote-video-placeholder');

        if (remoteVideo) remoteVideo.style.display = msg.on ? 'block' : 'none';
        if (!placeholder) return;

        if (msg.on) {
            placeholder.classList.remove('d-flex', 'show', 'align-items-center', 'justify-content-center');
            placeholder.style.display = 'none';
            placeholder.style.opacity = '0';
            placeholder.style.visibility = 'hidden';
        } else {
            placeholder.classList.add('d-flex', 'show', 'align-items-center', 'justify-content-center');
            placeholder.style.display = 'flex';
            placeholder.style.opacity = '';
            placeholder.style.visibility = '';
        }
    },

    // =====================================================================
    // Anzeige
    // =====================================================================

    /**
     * Große Richtungsanzeige beim Guide.
     *
     * Der Guide läuft und schaut nicht auf das Display. Der Ton bleibt
     * deshalb das Hauptsignal; die Anzeige ist die zweite, laute Spur:
     * bildschirmfüllend, hoher Kontrast, ein Pfeil und ein Wort. Sie
     * verschwindet nach INDICATOR_MS von selbst, damit ein stehender Pfeil
     * nicht mit einem neuen Befehl verwechselt wird.
     *
     * @param {string} direction - forward|backward|left|right
     */
    showDirection(direction) {
        const info = this.DIRECTIONS[direction];
        if (!info) return;

        const box   = document.getElementById('direction-indicator');
        const arrow = document.getElementById('direction-indicator-arrow');
        const label = document.getElementById('direction-indicator-label');
        if (!box) return;

        if (arrow) arrow.textContent = info.arrow;
        if (label) label.textContent = info.label;

        // Klasse komplett neu setzen: Ein zweiter Befehl derselben Richtung
        // soll die Einblendung neu starten, nicht nur die Frist verlängern.
        box.className = 'direction-indicator direction-indicator--visible';
        box.style.display = 'flex';

        const control = window.webrtcApp.state.control;
        if (control.indicatorTimer !== null) clearTimeout(control.indicatorTimer);
        control.indicatorTimer = setTimeout(() => {
            control.indicatorTimer = null;
            window.webrtcApp.control.hideDirection();
        }, this.INDICATOR_MS);
    },

    /** Blendet die Richtungsanzeige aus. */
    hideDirection() {
        const box = document.getElementById('direction-indicator');
        if (!box) return;
        box.className = 'direction-indicator';
        box.style.display = 'none';
    },

    /**
     * Aktualisiert alles, was an der Sperre hängt: die Schaltfläche beim
     * Guide und den Hinweis beim Zuschauer.
     */
    updateLockUi() {
        const state  = window.webrtcApp.state;
        const locked = state.control.locked;
        const isGuide = (state.callRole === window.webrtcApp.protocol.ROLE_GUIDE);

        const btn = document.getElementById('control-lock-btn');
        if (btn) {
            btn.textContent = locked ? 'Steuerung freigeben' : 'Steuerung sperren';
            btn.className = 'btn fw-bold control-lock-btn '
                + (locked ? 'btn-success' : 'btn-warning');
        }

        const bar = document.getElementById('control-lock-bar');
        if (bar) bar.style.display = isGuide ? 'flex' : 'none';

        const notice = document.getElementById('control-lock-notice');
        if (notice) {
            notice.style.display = (!isGuide && locked) ? 'flex' : 'none';
        }

        this.updatePadState();
    },

    /**
     * Sperrt oder entsperrt das Steuerkreuz.
     *
     * Gesperrt wird, solange eine Bestätigung aussteht (gegen Mehrfachdrücken
     * bei Latenz) und solange der Guide die Steuerung angehalten hat.
     */
    updatePadState() {
        const state = window.webrtcApp.state;
        const dc = window.webrtcApp.refs.controlChannel;
        const isViewer = (state.callRole === window.webrtcApp.protocol.ROLE_VIEWER);
        const waiting  = (state.control.pendingSeq !== null);
        const channelUp = !!dc && dc.readyState === 'open';
        const disabled = !isViewer || !channelUp || state.control.locked || waiting;

        this.ARROW_BUTTON_IDS.forEach(id => {
            const btn = document.getElementById(id);
            if (!btn) return;
            btn.disabled = disabled;
            if (!btn.classList) return;
            if (waiting) btn.classList.add('is-waiting');
            else btn.classList.remove('is-waiting');
        });
    },

    // =====================================================================
    // Aufräumen
    // =====================================================================

    /**
     * Setzt den gesamten Steuerzustand zurück. Wird von rtc.endCall()
     * aufgerufen, damit kein Rest aus dem letzten Call in den nächsten läuft:
     * keine alte Sequenznummer, keine hängende Sperre, kein stehender Pfeil.
     */
    reset() {
        const state = window.webrtcApp.state;
        this.clearAckTimeout();
        if (state.control.indicatorTimer !== null) {
            clearTimeout(state.control.indicatorTimer);
            state.control.indicatorTimer = null;
        }
        this.hideDirection();

        state.callRole = null;
        state.control.nextSeq = 1;
        state.control.pendingSeq = null;
        state.control.lastRemoteSeq = 0;
        state.control.locked = false;
        state.control.helloSent = false;
        state.control.rejected = 0;
        state.control.lastRejectCode = null;

        this.updateRoleUi();
    }
};
