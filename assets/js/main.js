window.activeTargetUserId = null;

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

/*window.addEventListener('DOMContentLoaded', function() {
    // Button-Event-Handler etc. einrichten ...
    // Jetzt das Polling starten:
    pollSignaling();
});*/

/*window.addEventListener('DOMContentLoaded', function() {
    var endBtn = document.getElementById('end-call-btn');
    if (endBtn) {
        endBtn.addEventListener('click', function() {
            endCall(true); // Beim Klick wird hangup gesendet
        });
    }
});*/

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
            createPeerConnection();
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

function setEndCallButtonVisible(visible) {
    var btn = document.getElementById('end-call-btn');
    if (btn) btn.style.display = visible ? '' : 'none';
}
