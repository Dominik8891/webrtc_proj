
window.startCall = async function(targetUserId) {
    await window.initFakeSelfCall();
    window.activeTargetUserId = targetUserId;
    navigator.mediaDevices.getUserMedia({ video: true, audio: true })
        .then(stream => {
        window.localStream = stream;
        document.getElementById('local-video').srcObject = stream;
        window.createPeerConnection(true);
        window.playSound('call_ringtone');

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
    window.dumpWebRTCState("Nach Self-Call oder Outgoing Call");
    document.body.classList.add('call-active');
    document.getElementById('call-view').style.display = '';
    //document.getElementById('admin-panel').style.display = 'none'; // Adminpanel ausblenden

};



