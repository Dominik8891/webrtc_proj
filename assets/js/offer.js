/*function startCall(targetUserId) {
    window.activeTargetUserId = targetUserId;
    console.log("startCall wurde aufgerufen mit targetUserId:", targetUserId);
    createPeerConnection();
    addLocalTracks();
    localPeerConnection.createOffer()
        .then(offer => {
            return localPeerConnection.setLocalDescription(offer).then(() => offer);
        })
        .then(offer => {
            console.log("Offer erstellt und lokal gesetzt");
            sendSignalMessage({
                type: 'offer',
                sdp: offer.sdp, // <-- nur das sdp-String!
                target: targetUserId
            });
        })
        .catch(console.error);
}*/

// offer.js
window.startCall = function(targetUserId) {
    window.activeTargetUserId = targetUserId;
    navigator.mediaDevices.getUserMedia({ video: true, audio: true })
        .then(stream => {
            window.localStream = stream;
            document.getElementById('local-video').srcObject = stream;
            window.createPeerConnection();
            window.addLocalTracks();
            return window.localPeerConnection.createOffer();
        })
        .then(offer => {
            return window.localPeerConnection.setLocalDescription(offer).then(() => offer);
        })
        .then(offer => {
            window.sendSignalMessage({
                type: 'offer',
                sdp: offer.sdp,
                target: targetUserId
            });
        })
        .catch(console.error);
};



