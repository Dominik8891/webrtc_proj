// rtc.js
window.localPeerConnection = null;
window.localStream = null;
window.tracksAdded = false;

window.createPeerConnection = function() {
    if (window.localPeerConnection) return; // Schon initialisiert
    const config = {
      iceServers: [
        { urls: 'stun:stun.l.google.com:19302' }
      ]
    };
    window.localPeerConnection = new RTCPeerConnection(config);

    window.localPeerConnection.onicecandidate = event => {
        if (event.candidate && window.activeTargetUserId) {
            window.sendSignalMessage({ type: 'iceCandidate', candidate: event.candidate, target: window.activeTargetUserId });
        }
    };

    window.localPeerConnection.ontrack = event => {
        const remoteVideo = document.getElementById('remote-video');
        if (remoteVideo) remoteVideo.srcObject = event.streams[0];
    };

    console.log('Peer-Verbindung erstellt');
    window.tracksAdded = false;
};

window.addLocalTracks = function() {
    if (!window.localStream || !window.localPeerConnection) {
        console.warn("Kein lokaler Stream oder keine PeerConnection vorhanden!");
        return;
    }
    if (window.tracksAdded) return;
    window.localStream.getTracks().forEach(track => {
        window.localPeerConnection.addTrack(track, window.localStream);
    });
    window.tracksAdded = true;
};
