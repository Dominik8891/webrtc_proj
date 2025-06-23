
// Haupt-Chat-Modul im globalen webrtcApp-Objekt
window.webrtcApp.chat = {
    /**
     * Fügt eine Chat-Nachricht dem Log im UI hinzu.
     * @param {string} who  - "self" oder "remote"
     * @param {string} msg  - Der eigentliche Nachrichten-Text
     */
    appendMsg(who, msg) {
        const log = document.getElementById("chat-log"); // Container für den Chatverlauf
        const div = document.createElement("div");        // Neue Zeile für die Nachricht
        let displayMsg = msg;

        // Steuerzeichen durch Pfeil-Symbole ersetzen (falls nötig)
        if (msg === "__arrow_forward__")      displayMsg = "↑";
        else if (msg === "__arrow_backward__") displayMsg = "↓";
        else if (msg === "__arrow_left__")     displayMsg = "←";
        else if (msg === "__arrow_right__")    displayMsg = "→";

        // Formatierung: Wer hat die Nachricht gesendet?
        div.textContent = (who === "remote" ? "Partner: " : "Du: ") + displayMsg;

        // An Chat-Log anhängen und immer nach unten scrollen
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    },

    /**
     * Sendet eine Textnachricht über den DataChannel und zeigt sie im Log.
     * @param {string} msg  - Die zu sendende Nachricht
     */
    send(msg) {
        const dc = window.webrtcApp.refs.dataChannel; // DataChannel-Referenz
        if (dc && dc.readyState === "open") {
            dc.send(msg); // Nachricht senden (Peer-to-Peer)
            window.webrtcApp.chat.appendMsg("self", msg); // Direkt im eigenen Chatlog anzeigen
        }
    },

    /**
     * Sendet eine Datei als ArrayBuffer über den DataChannel.
     * Hinweis: Funktioniert nur mit "open" DataChannel.
     * @param {File} file  - Das zu sendende File-Objekt (z.B. von <input>)
     */
    sendFile(file) {
        const dc = window.webrtcApp.refs.dataChannel; // DataChannel-Referenz
        if (dc && dc.readyState === "open") {
            file.arrayBuffer().then(buffer => {
                dc.send(buffer); // Binärdaten senden
                window.webrtcApp.chat.appendMsg("self", "Datei gesendet: " + file.name);
            });
        }
    }
};
