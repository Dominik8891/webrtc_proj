window.pollingIntervalId = null;


function sendSignalMessage(msg) {
    fetch('index.php?act=getSignal', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(msg)
    })
    .then(r => r.text())
    .then(console.log)
    .catch(console.error);
}
window.pendingCandidates = window.pendingCandidates || [];

function handleSignalingData(data) {
    console.log("Empfangene Nachricht:", data);
    if (data.type === 'offer') {
        handleOffer(data);
    } else if (data.type === 'answer') {
        localPeerConnection.setRemoteDescription(new RTCSessionDescription({
            type: data.type,
            sdp: data.sdp
        }))
        .then(() => {
            // Nach dem Setzen der RemoteDescription alle zwischengespeicherten ICE-Kandidaten hinzufügen
            if (window.pendingCandidates && window.pendingCandidates.length) {
                window.pendingCandidates.forEach(candidate =>
                    window.localPeerConnection.addIceCandidate(new RTCIceCandidate(candidate))
                );
                window.pendingCandidates = [];
            }
        });
    } else if (data.type === 'iceCandidate') {
        console.log("Empfange ICE-Kandidat:", data.candidate);

        let candidateObj = data.candidate;

        // IGNORIERE leere/null Kandidaten!
        if (!candidateObj) return;

        if (typeof candidateObj === "string") {
            try {
                candidateObj = JSON.parse(candidateObj);
            } catch(e) {
                console.warn("Konnte ICE candidate nicht parsen:", candidateObj);
                return; // Fehlerhaften String-Kandidaten ebenfalls ignorieren
            }
        }
        // NEU: Candidate speichern, wenn PeerConnection oder remoteDescription noch fehlt!
        if (
            !window.localPeerConnection ||
            !window.localPeerConnection.remoteDescription ||
            !window.localPeerConnection.remoteDescription.type
        ) {
            window.pendingCandidates.push(candidateObj);
        } else {
            window.localPeerConnection.addIceCandidate(new RTCIceCandidate(candidateObj));
        }
    } else if (data.type === 'hangup') {
        window.handleHangupSource("Server");
        var acceptBtn = document.getElementById('accept-call-btn');
        if (acceptBtn) acceptBtn.style.display = "none";
        setEndCallButtonVisible(false); // falls aktiv!
        window.endCall(true);
        window.stopSound('call_ringtone');
        // Kein zusätzliches alert() mehr!
    }
}




function pollSignaling() {
    if (window.pollingIntervalId !== null) return; // Schon aktiv

    console.log("Starte Polling!");
    window.pollingIntervalId = setInterval(() => {
        fetch('index.php?act=getSignal')
            .then(r => r.json())
            .then(msgArr => {
                console.log("Nachrichten vom Server:", msgArr);
                if (Array.isArray(msgArr)) {
                    msgArr.forEach(msg => handleSignalingData(msg));
                } else {
                    console.error("Server hat kein Array zurückgegeben!", msgArr);
                }
            })
            .catch(err => console.error("Polling-Fehler:", err));
    }, 500);
}


function stopPolling() {
    if (window.pollingIntervalId !== null) {
        clearInterval(window.pollingIntervalId);
        window.pollingIntervalId = null;
        console.log("Polling gestoppt!");
    }
}

