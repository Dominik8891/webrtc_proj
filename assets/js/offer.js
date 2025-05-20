function startCall(targetUserId) {
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
}
