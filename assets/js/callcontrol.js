window.isCallActive = false;

function endCall(sendSignal = true) {
    // PeerConnection und Video-Cleanup
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

    setEndCallButtonVisible(false);

    // Nur eine Hangup-Benachrichtigung senden
    if (!window.hangupReceived) {
        window.hangupReceived = true; // Direkt setzen, damit keine Dopplungen mehr möglich sind

        // Nur Signal senden, wenn User selbst beendet und Server verfügbar ist
        if (sendSignal && window.activeTargetUserId) {
            sendSignalMessage({ type: 'hangup', target: window.activeTargetUserId });
        }

        // Fallback: DataChannel nur verwenden, wenn sendSignalMessage fehlschlägt
        // Oder, falls du DataChannel immer noch willst:
        // if (window.dataChannel && window.dataChannel.readyState === "open") {
        //     window.dataChannel.send("__hangup__");
        // }
    }

    window.isCallActive = false;
}
