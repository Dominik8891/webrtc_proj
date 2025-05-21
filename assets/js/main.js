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


/*// Füge das z.B. am Ende von rtc.js oder in main.js ein:
navigator.mediaDevices.getUserMedia({ video: true, audio: true })
    .then(stream => {
        localStream = stream;
        document.getElementById('local-video').srcObject = stream;
    })
    .catch(err => {
        alert("Kein Zugriff auf Kamera/Mikrofon: " + err);
    });*/

window.addEventListener('DOMContentLoaded', function() {
    // Button-Event-Handler etc. einrichten ...
    // Jetzt das Polling starten:
    pollSignaling();
});

window.addEventListener('DOMContentLoaded', function() {
    // ... dein anderer Code ...
    document.getElementById('end-call-btn').addEventListener('click', endCall);
});

/*window.addEventListener('DOMContentLoaded', function() {
    var acceptBtn = document.getElementById('accept-call-btn');
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function() {
            // Hole das Angebot aus window.pendingOffer
            const data = window.pendingOffer;
            document.getElementById('accept-call-btn').style.display = "none"; // Button ausblenden

            navigator.mediaDevices.getUserMedia({ video: true, audio: true })
                .then(stream => {
                    window.localStream = stream;
                    document.getElementById('local-video').srcObject = stream;
                    createPeerConnection();
                    // 1. Setze remote SDP (aus Offer)
                    return window.localPeerConnection.setRemoteDescription(new RTCSessionDescription({
                        type: data.type,
                        sdp: data.sdp
                    }));
                })
                .then(() => {
                    addLocalTracks();
                    // Jetzt die Antwort erzeugen
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
                    alert("Kamera/Mikrofon konnte nicht aktiviert werden: " + e.message);
                });
        });
    }
});*/

window.addEventListener('DOMContentLoaded', function() {
    var acceptBtn = document.getElementById('accept-call-btn');
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function() {
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
});

