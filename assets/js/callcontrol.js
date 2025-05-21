function endCall() {
    // Stoppe Tracks und Connection
    if (window.localStream) {
        window.localStream.getTracks().forEach(track => track.stop());
        window.localStream = null;
    }
    if (window.localPeerConnection) {
        window.localPeerConnection.close();
        window.localPeerConnection = null;
    }
    // Videos leeren
    const remoteVideo = document.getElementById('remote-video');
    if (remoteVideo) remoteVideo.srcObject = null;
    const localVideo = document.getElementById('local-video');
    if (localVideo) localVideo.srcObject = null;
    // Benachrichtige den Partner!
    if (window.activeTargetUserId) {
        sendSignalMessage({ type: 'hangup', target: window.activeTargetUserId });
    }
    // Buttons zurücksetzen, falls nötig
    const acceptBtn = document.getElementById('accept-call-btn');
    if (acceptBtn) acceptBtn.style.display = 'none';
    console.log("Call beendet.");
}
