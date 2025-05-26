window.isCallActive = false;

 window.endCall = function(sendSignal = true) {
    document.body.classList.remove('call-active');
    document.getElementById('call-view').style.display = 'none';
    //document.getElementById('admin-panel').style.display = '';

    // PeerConnection beenden
    if (window.localPeerConnection) {
        window.localPeerConnection.close();
        window.localPeerConnection = null;
    }

    // DataChannel schließen & auf null setzen
    if (window.dataChannel) {
        try { window.dataChannel.close(); } catch (e) {}
        window.dataChannel = null;
    }

    // Eigene Streams wirklich stoppen
    if (window.localStream) {
        window.localStream.getTracks().forEach(track => track.stop());
        window.localStream = null;
    }

    // Videos zurücksetzen
    const localVideo = document.getElementById('local-video');
    const remoteVideo = document.getElementById('remote-video');
    if (localVideo) localVideo.srcObject = null;
    if (remoteVideo) remoteVideo.srcObject = null;

    // Nur eine Hangup-Benachrichtigung senden
    if (!window.hangupReceived) {
        window.hangupReceived = true;
        if (sendSignal && window.activeTargetUserId) {
            sendSignalMessage({ type: 'hangup', target: window.activeTargetUserId });
        }
    }

    // State-Flags zurücksetzen
    window.tracksAdded = false;
    window.activeTargetUserId = null;
    window.hangupReceived = false;  // optional, je nach Flow
    window.pendingOffer = null;

    setEndCallButtonVisible(false);

    

    window.isCallActive = false;

    // UI zurücksetzen (z. B. Chat ausblenden)
    const chatArea = document.getElementById('chat-area');
    if (chatArea) chatArea.style.display = 'none';
    const arrowControl = document.getElementById('arrow-control');
    if (arrowControl) arrowControl.style.display = 'none';
}

