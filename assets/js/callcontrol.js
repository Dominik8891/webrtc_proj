function endCall() {
    if (localPeerConnection) {
        localPeerConnection.close();
        localPeerConnection = null;
    }
    tracksAdded = false;

    // Optional: Video-Element zurücksetzen
    const remoteVideo = document.getElementById('remote-video');
    if (remoteVideo) remoteVideo.srcObject = null;

    // Optional: An den Partner eine "hangup"-Nachricht senden
    sendSignalMessage({ type: 'hangup' });

    console.log("Call beendet.");
}
