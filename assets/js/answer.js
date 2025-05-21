/*
function handleOffer(data) {
    window.activeTargetUserId = data.sender_id;
    console.log('handleOffer aufgerufen mit:', data);

    ensurePeerConnection();
    addLocalTracks();

    localPeerConnection.setRemoteDescription(new RTCSessionDescription({
        type: data.type,
        sdp: data.sdp
    }))
    .then(() => {
        console.log("RemoteDescription gesetzt, erstelle Answer …");
        return localPeerConnection.createAnswer();
    })
    .then(answer => {
        return localPeerConnection.setLocalDescription(answer).then(() => answer);
    })
    .then(answer => {
        // GANZ WICHTIG: Sende die Answer zurück an den **Anrufer**
        sendSignalMessage({
            type: 'answer',
            sdp: answer.sdp,
            target: data.sender_id // Sende an den Anrufer!
        });
        console.log("Answer gesendet an:", data.sender_id);
    })
    .catch(err => {
        console.error('Fehler beim Verarbeiten der Offer:', err);
    });
}*/

/*function handleOffer(data) {
    window.activeTargetUserId = data.sender_id;
    navigator.mediaDevices.getUserMedia({ video: false, audio: false })
        .then(stream => {
            const emptyStream = new MediaStream();
            window.localStream = emptyStream;
            //window.localStream = stream;
            document.getElementById('local-video').srcObject = emptyStream;
            createPeerConnection();
            // 1. Setze remote SDP
            return window.localPeerConnection.setRemoteDescription(new RTCSessionDescription({
                type: data.type,
                sdp: data.sdp
            }));
        })
        .then(() => {
            addLocalTracks();
            // JETZT: ICE-Kandidaten nachholen
            if (window.pendingCandidates && window.pendingCandidates.length) {
                window.pendingCandidates.forEach(candidate =>
                    window.localPeerConnection.addIceCandidate(new RTCIceCandidate(candidate))
                );
                window.pendingCandidates = [];
            }
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
        .catch(console.error);
}*/

function handleOffer(data) {
    window.activeTargetUserId = data.sender_id;
    window.pendingOffer = data; // Merke dir das Angebot global

    // Zeige den Annehmen-Button an
    document.getElementById('accept-call-btn').style.display = "inline-block";
}





