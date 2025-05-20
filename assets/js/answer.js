/*function handleOffer(data) {
    console.log("handleOffer aufgerufen mit:", data);
    ensurePeerConnection();
    console.log("handleOffer data:", data);

    if (!localPeerConnection) {
        alert("PeerConnection nicht vorhanden!");
        return;
    }

    if (!data.type || !data.sdp) {
        alert("Type oder SDP fehlt!");
        return;
    }

    localPeerConnection.setRemoteDescription(new RTCSessionDescription({
        type: data.type,
        sdp: data.sdp
    }))
    .then(() => localPeerConnection.createAnswer())
    .then(answer => localPeerConnection.setLocalDescription(answer))
    .then(() => sendSignalMessage({
        type: 'answer',
        sdp: localPeerConnection.localDescription.sdp,
        target: data.target
    }))
    .catch(console.error);
}*/
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
}

