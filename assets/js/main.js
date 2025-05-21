window.activeTargetUserId = null;
window.hangupReceived = false; // Verhindert doppelte Hangup-Meldung

window.handleHangupSource = function(source) {
    if (window.hangupReceived) return; // Meldung nur einmal anzeigen
    window.hangupReceived = true;
    alert("Der andere Teilnehmer hat das Gespräch beendet." + (source ? " (" + source + ")" : ""));
    endCall(false); // Beende lokal, aber kein neues Signal senden
};


window.addEventListener('DOMContentLoaded', function() {
    
    document.querySelectorAll('.start-call-btn').forEach(button => {
        let btn_id = button.getAttribute('id').replace('start-call-btn-', '');
        button.addEventListener("click", function() {
            console.log("Anruf mit User-ID:", btn_id);
            // Ziel-User ggf. speichern, wenn für die Signalisierung nötig
            startCall(btn_id);
        });
    });
});

window.addEventListener('DOMContentLoaded', function() {
    setEndCallButtonVisible(false);
    pollSignaling();
    var acceptBtn = document.getElementById('accept-call-btn');
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function() {
            setEndCallButtonVisible(true);
            window.isCallActive = true;
            // Hole das Angebot aus window.pendingOffer
            const data = window.pendingOffer;
            document.getElementById('accept-call-btn').style.display = "none"; // Button ausblenden

            // KEIN getUserMedia, wenn du nichts senden willst!
            window.createPeerConnection(false);
            window.localStream = null;

            window.localPeerConnection.setRemoteDescription(new RTCSessionDescription({
                type: data.type,
                sdp: data.sdp
            }))
            .then(() => {
                // KEIN addLocalTracks(), wenn kein Stream!
                return window.localPeerConnection.createAnswer();
            })
            .then(answer => {
                return window.localPeerConnection.setLocalDescription(answer).then(() => answer);
            })
            .then(answer => {
                sendSignalMessage({
                    type: 'answer',
                    sdp: answer.sdp,
                    target: data.sender_id
                });
            })
            .catch(e => {
                alert("Fehler beim Verarbeiten des Angebots: " + e.message);
            });
        });
        
    }
    var endBtn = document.getElementById('end-call-btn');
    if (endBtn) {
        endBtn.addEventListener('click', function() {
            endCall(true); // Beim Klick wird hangup gesendet
        });
    }
});

window.addEventListener('DOMContentLoaded', function() {
    const chatBtn = document.getElementById('chat-send-btn');
    const chatInput = document.getElementById('chat-input');
    if (chatBtn && chatInput) {
        chatBtn.addEventListener('click', function() {
            if (window.dataChannel && window.dataChannel.readyState === "open") {
                window.dataChannel.send(chatInput.value);
                appendChatMsg("me", chatInput.value);
                chatInput.value = "";
            }
        });
    }
});

function setEndCallButtonVisible(visible) {
    var btn = document.getElementById('end-call-btn');
    if (btn) btn.style.display = visible ? '' : 'none';
}

window.appendChatMsg = function(who, msg) {
    const log = document.getElementById("chat-log");
    const div = document.createElement("div");
    div.textContent = (who === "remote" ? "Partner: " : "Du: ") + msg;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
};

window.sendChatMsg = function(msg) {
    if (window.dataChannel && window.dataChannel.readyState === "open") {
        window.dataChannel.send(msg);
        window.appendChatMsg("self", msg);
    }
};

window.sendFile = function(file) {
    if (window.dataChannel && window.dataChannel.readyState === "open") {
        // Dateien klein halten, sonst muss in Blöcke gesplittet werden!
        file.arrayBuffer().then(buffer => {
            window.dataChannel.send(buffer);
            window.appendChatMsg("self", "Datei gesendet: " + file.name);
        });
    }
};

window.addEventListener('DOMContentLoaded', function() {
    // Chat-Nachricht senden
    var sendBtn = document.getElementById("chat-send-btn");
    var chatInput = document.getElementById("chat-input");
    if (sendBtn && chatInput) {
        sendBtn.addEventListener('click', function() {
            const msg = chatInput.value;
            if (msg) {
                window.sendChatMsg(msg);
                chatInput.value = "";
            }
        });
        // Enter-Taste für Senden
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === "Enter") {
                sendBtn.click();
            }
        });
    }

    // Datei senden
    var fileInput = document.getElementById("file-input");
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            if (e.target.files.length) window.sendFile(e.target.files[0]);
        });
    }
});


