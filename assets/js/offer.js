
window.startCall = function(targetUserId) {
    window.activeTargetUserId = targetUserId;
    navigator.mediaDevices.getUserMedia({ video: true, audio: true })
        .then(stream => {
        window.localStream = stream;
        document.getElementById('local-video').srcObject = stream;
        window.createPeerConnection(true);

        // 100ms Delay bevor Tracks hinzugefügt werden:
        return new Promise(resolve => setTimeout(resolve, 100));
        })
        .then(() => {
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
    setEndCallButtonVisible(true); // Zeige Button
    window.isCallActive = true; // Setze Call-Status global
};



