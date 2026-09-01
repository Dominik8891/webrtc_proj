// Haupt-Chat-Modul im globalen webrtcApp-Objekt
//
// Zuständig ausschließlich für den DataChannel "chat". Der trägt nur noch
// Nutzerinhalt: Textnachrichten als JSON (Typ "chat") und Dateien als
// Binärframes. Steuerbefehle laufen getrennt über den Kanal "control"
// (control.js). Damit kann ein in den Chat getippter Text keinen Steuerbefehl
// mehr auslösen - und ein Steuerbefehl landet nicht mehr im Chatfenster.
window.webrtcApp.chat = {
    /**
     * Fügt eine Chat-Nachricht dem Log im UI hinzu.
     * @param {string} who  - "self" oder "remote"
     * @param {string} msg  - Der eigentliche Nachrichten-Text
     */
    appendMsg(who, msg) {
        // Standardtext-Log (Desktop)
        const log = document.getElementById("chat-log");
        const div = document.createElement("div");

        // textContent, nicht innerHTML: Der Text kommt von der Gegenseite und
        // wird nie als Markup ausgewertet.
        div.textContent = (who === "remote" ? "Partner: " : "Du: ") + msg;
        log?.appendChild(div);
        log && (log.scrollTop = log.scrollHeight);

        // Auch im mobilen Log anzeigen
        this.appendToMobileChatLog(who, msg);
    },

    /**
     * Fügt eine Nachricht dem mobilen Chatlog (Bottom-Sheet) hinzu, inklusive Absender.
     * @param {string} who
     * @param {string} msg
     */
    appendToMobileChatLog(who, msg) {
        const log = document.getElementById('chat-log-mobile');
        if (!log) return;
        const div = document.createElement('div');
        div.textContent = (who === "remote" ? "Partner: " : "Du: ") + msg;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
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
    }
};
