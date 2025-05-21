window.isCallActive = false;

function endCall(sendSignal = true) {
    if (window.localPeerConnection) {
        window.localPeerConnection.close();
        window.localPeerConnection = null;
    }

    document.getElementById('remote-video').srcObject = null;
    document.getElementById('local-video').srcObject = null;
    window.localStream = null;
    window.isCallActive = false;

    // **Nur senden, wenn explizit vom Nutzer ausgelöst!**
    if (sendSignal && window.activeTargetUserId) {
        sendSignalMessage({ type: 'hangup', target: window.activeTargetUserId });
    }
}
