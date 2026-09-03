// Haupt-Chat-Modul im globalen webrtcApp-Objekt
//
// Zuständig ausschließlich für den DataChannel "chat". Der trägt nur noch
// Nutzerinhalt: Textnachrichten als JSON (Typ "chat") und Dateien als
// Binärframes. Steuerbefehle laufen getrennt über den Kanal "control"
// (control.js). Damit kann ein in den Chat getippter Text keinen Steuerbefehl
// mehr auslösen - und ein Steuerbefehl landet nicht mehr im Chatfenster.
window.webrtcApp.chat = {

    // -----------------------------------------------------------------
    // Ungelesene Nachrichten
    // -----------------------------------------------------------------
    //
    // Das Chatblatt liegt im Call zugeklappt ueber dem Bild. Kam eine
    // Nachricht an, waehrend es zu war, geschah SICHTBAR NICHTS: Die Zeile
    // landete im Verlauf, den gerade niemand sieht. Der Zaehler haengt
    // deshalb am Chatknopf der Bedienleiste - dort, wo man hinschaut, wenn
    // man den Chat oeffnen will.
    //
    // Gezaehlt wird nur, solange das Blatt zu ist. Beim Oeffnen wird auf null
    // gesetzt, denn ab dann liest der Nutzer mit.

    /** Zahl der Nachrichten, die seit dem letzten Oeffnen ankamen. */
    unread: 0,

    /**
     * Steht das Chatblatt offen?
     *
     * Gefragt wird das Blatt selbst und nicht ein eigener Merker: Es gibt
     * mehrere Wege, es zu schliessen (Knopf, Kreuz, Klick daneben), und ein
     * zweiter Zustand daneben waere der, der irgendwann nicht mehr stimmt.
     *
     * @returns {boolean}
     */
    isOpen() {
        const overlay = document.getElementById('chat-overlay');
        return !!overlay && overlay.hidden === false;
    },

    /** Zaehlt eine ungelesene Nachricht, wenn der Chat gerade zu ist. */
    noteUnread() {
        if (this.isOpen()) return;
        this.unread++;
        this.renderUnread();
    },

    /** Setzt den Zaehler zurueck - beim Oeffnen des Chats und am Call-Ende. */
    clearUnread() {
        this.unread = 0;
        this.renderUnread();
    },

    /**
     * Schreibt den Zaehler an den Chatknopf.
     *
     * Ueber 99 wird nicht mehr gezaehlt, sondern "99+" angezeigt: Eine
     * dreistellige Zahl sprengt den runden Knopf, und ab dieser Menge ist die
     * genaue Zahl ohnehin keine Auskunft mehr.
     */
    renderUnread() {
        const badge = document.getElementById('chat-unread');
        if (badge) {
            badge.textContent = (this.unread > 99) ? '99+' : String(this.unread);
            badge.hidden = (this.unread === 0);
        }
        const btn = document.getElementById('chat-toggle-btn');
        if (btn && btn.classList) {
            if (this.unread > 0) btn.classList.add('has-unread');
            else                 btn.classList.remove('has-unread');
        }
    },

    /**
     * Fügt eine Chat-Nachricht dem Log im UI hinzu.
     * @param {string} who  - "self" oder "remote"
     * @param {string} msg  - Der eigentliche Nachrichten-Text
     */
    appendMsg(who, msg) {
        // Es gibt genau einen Chatverlauf. Frueher wurde jede Nachricht
        // zusaetzlich in ein zweites Log fuer die mobile Ansicht geschrieben
        // (chat-log-mobile); die Call-Ansicht hat seit dem Umbau nur noch ein
        // Chatblatt, auf jedem Geraet dasselbe.
        const log = document.getElementById("chat-log");
        const div = document.createElement("div");

        // Eine Klasse je Absender, damit die Zeile in der Call-Ansicht als
        // Blase links oder rechts erscheint (assets/css/call.css). Der Text
        // bleibt derselbe - er steht weiterhin vollstaendig in EINEM Element,
        // ohne Verschachtelung.
        div.className = "chat-line chat-line--" + (who === "remote" ? "partner" : "self");

        // textContent, nicht innerHTML: Der Text kommt von der Gegenseite und
        // wird nie als Markup ausgewertet.
        div.textContent = (who === "remote" ? "Partner: " : "Du: ") + msg;
        log?.appendChild(div);
        log && (log.scrollTop = log.scrollHeight);
    },

    /**
     * Sendet eine Textnachricht über den Chatkanal und zeigt sie im Log.
     * @param {string} msg  - Die zu sendende Nachricht
     * @returns {boolean} true, wenn die Nachricht abgesetzt wurde
     */
    send(msg) {
        const dc = window.webrtcApp.refs.chatChannel;
        if (!dc || dc.readyState !== "open") return false;

        // Zu lange Nachrichten werden vom Empfänger ohnehin verworfen
        // (protocol.MAX_CHAT_TEXT), also hier gar nicht erst absenden.
        const raw = window.webrtcApp.protocol.serialize('chat', { text: String(msg) });
        if (raw === null) {
            window.webrtcApp.rtc.showSystemNotice("Nachricht nicht gesendet – sie ist zu lang.");
            return false;
        }

        try {
            dc.send(raw);
        } catch (e) {
            console.warn("Chatnachricht konnte nicht gesendet werden:", e);
            window.webrtcApp.rtc.showSystemNotice("Nachricht nicht gesendet – Übertragungsfehler.");
            return false;
        }
        this.appendMsg("self", msg);
        return true;
    },

    /**
     * Sendet eine Datei als ArrayBuffer über den Chatkanal.
     * @param {File} file
     */
    sendFile(file) {
        const dc = window.webrtcApp.refs.chatChannel;
        if (dc && dc.readyState === "open") {
            file.arrayBuffer().then(buffer => {
                dc.send(buffer);
                this.appendMsg("self", "Datei gesendet: " + file.name);
            });
        }
    },

    /**
     * Verarbeitet einen eingehenden Frame des Chatkanals.
     *
     * Textframes müssen gültige Protokollnachrichten vom Typ "chat" sein.
     * Alles andere wird verworfen und protokolliert - insbesondere wird ein
     * nicht verstandener Frame NICHT als Chattext angezeigt. Binärframes
     * gelten als Datei.
     *
     * @param {*} raw - Rohdaten aus dem DataChannel
     */
    handleMessage(raw) {
        const p = window.webrtcApp.protocol;

        if (typeof raw !== "string") {
            this.handleFile(raw);
            return;
        }

        const result = p.validate(raw, p.CHANNEL_CHAT, window.webrtcApp.state.callRole);
        if (!result.ok) {
            window.webrtcApp.control.logRejected(p.CHANNEL_CHAT, result, raw);
            return;
        }
        this.appendMsg("remote", result.message.text);
        this.noteUnread();
    },

    /**
     * Nimmt einen Binärframe als Datei entgegen und bietet sie zum Download an.
     * @param {ArrayBuffer} data
     */
    handleFile(data) {
        const log = document.getElementById("chat-log");
        if (!log) return;

        const blob = new Blob([data]);
        const url = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = url;
        a.download = "empfangene_datei";
        a.textContent = "Datei herunterladen";
        log.appendChild(a);
        this.noteUnread();
    }
};
