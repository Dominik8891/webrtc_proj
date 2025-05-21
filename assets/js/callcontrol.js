window.isCallActive = false;

function endCall(sendSignal = true) {
    // Beispiel für PeerConnection und Video-Cleanup (anpassen!)
    if (window.localPeerConnection) {
        window.localPeerConnection.close();
        window.localPeerConnection = null;
    }
    // Eigene Streams leeren
    if (window.localStream) {
        window.localStream.getTracks().forEach(track => track.stop());
        window.localStream = null;
    }
    // Videos zurücksetzen
    const localVideo = document.getElementById('local-video');
    const remoteVideo = document.getElementById('remote-video');
    if (localVideo) localVideo.srcObject = null;
    if (remoteVideo) remoteVideo.srcObject = null;

    // End-Call-Button ausblenden
    setEndCallButtonVisible(false);

    // Nur Signal senden, wenn User wirklich den Call beendet!
    if (sendSignal && window.activeTargetUserId) {
        sendSignalMessage({ type: 'hangup', target: window.activeTargetUserId });
    }
    // Call-Status zurücksetzen (optional)
    window.isCallActive = false;
}

