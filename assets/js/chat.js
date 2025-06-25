// Haupt-Chat-Modul im globalen webrtcApp-Objekt
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
        let displayMsg = msg;

        // Steuerzeichen durch Pfeile ersetzen
        if (msg === "__arrow_forward__")      displayMsg = "↑";
        else if (msg === "__arrow_backward__") displayMsg = "↓";
        else if (msg === "__arrow_left__")     displayMsg = "←";
        else if (msg === "__arrow_right__")    displayMsg = "→";

        div.textContent = (who === "remote" ? "Partner: " : "Du: ") + displayMsg;
        log?.appendChild(div);
        log && (log.scrollTop = log.scrollHeight);

        // --- NEU: Auch im mobilen Log anzeigen ---
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
        let displayMsg = msg;
        if (msg === "__arrow_forward__")      displayMsg = "↑";
        else if (msg === "__arrow_backward__") displayMsg = "↓";
        else if (msg === "__arrow_left__")     displayMsg = "←";
        else if (msg === "__arrow_right__")    displayMsg = "→";
        // Optional: einheitliches Layout für mobile Nachrichten
        const div = document.createElement('div');
        div.textContent = (who === "remote" ? "Partner: " : "Du: ") + displayMsg;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    },

    /**
     * Sendet eine Textnachricht über den DataChannel und zeigt sie im Log.
     * @param {string} msg  - Die zu sendende Nachricht
     */
    send(msg) {
        const dc = window.webrtcApp.refs.dataChannel;
        if (dc && dc.readyState === "open") {
            dc.send(msg);
            this.appendMsg("self", msg);
        }
    },

    /**
     * Sendet eine Datei als ArrayBuffer über den DataChannel.
     * @param {File} file
     */
    sendFile(file) {
        const dc = window.webrtcApp.refs.dataChannel;
        if (dc && dc.readyState === "open") {
            file.arrayBuffer().then(buffer => {
                dc.send(buffer);
                this.appendMsg("self", "Datei gesendet: " + file.name);
            });
        }
    }
};
