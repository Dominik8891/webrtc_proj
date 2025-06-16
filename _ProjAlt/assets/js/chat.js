// assets/js/chat.js
window.webrtcApp.chat = {
    appendMsg(who, msg) {
        const log = document.getElementById("chat-log");
        const div = document.createElement("div");
        let displayMsg = msg;
        // Ersetze Steuerzeichen durch Symbole:
        if (msg === "__arrow_forward__")      displayMsg = "↑";
        else if (msg === "__arrow_backward__") displayMsg = "↓";
        else if (msg === "__arrow_left__")     displayMsg = "←";
        else if (msg === "__arrow_right__")    displayMsg = "→";
        div.textContent = (who === "remote" ? "Partner: " : "Du: ") + displayMsg;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    },
    send(msg) {
        const dc = window.webrtcApp.refs.dataChannel;
        if (dc && dc.readyState === "open") {
            dc.send(msg);
            window.webrtcApp.chat.appendMsg("self", msg);
        }
    },
    sendFile(file) {
        const dc = window.webrtcApp.refs.dataChannel;
        if (dc && dc.readyState === "open") {
            file.arrayBuffer().then(buffer => {
                dc.send(buffer);
                window.webrtcApp.chat.appendMsg("self", "Datei gesendet: " + file.name);
            });
        }
    }
};
