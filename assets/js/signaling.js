

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
        let candidateObj = data.candidate;
        if (typeof candidateObj === "string") {
            try {
                candidateObj = JSON.parse(candidateObj);
            } catch(e) {
                console.warn("Konnte ICE candidate nicht parsen:", candidateObj);
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
        endCall();
        alert("Der andere Teilnehmer hat das Gespräch beendet.");
    }
}



function pollSignaling() {
    console.log("Starte Polling!");
    setInterval(() => {
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

    }, 1500);
}
