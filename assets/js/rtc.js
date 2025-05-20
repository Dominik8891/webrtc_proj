// Globale Variablen
let localPeerConnection = null;
let localStream = null;
let tracksAdded = false;

// Initialisiere PeerConnection (nur wenn noch nicht existent)
function createPeerConnection() {
    if (localPeerConnection) return; // Schon initialisiert
    const config = {
      iceServers: [
        { urls: 'stun:stun.l.google.com:19302' }
      ]
    };

    localPeerConnection = new RTCPeerConnection(config);

    localPeerConnection.onicecandidate = event => {
        if (event.candidate) sendSignalMessage({ type: 'iceCandidate', candidate: event.candidate , target: window.activeTargetUserId});
    };

    localPeerConnection.ontrack = event => {
        document.getElementById('remote-video').srcObject = event.streams[0];
    };

    console.log('Peer-Verbindung erstellt');
    tracksAdded = false;
}


function addLocalTracks() {
    if (!localStream) {
        console.warn("Kein lokaler Stream vorhanden!");
        return;
    }
    if (tracksAdded) return;
    localStream.getTracks().forEach(track => {
        localPeerConnection.addTrack(track, localStream);
    });
    tracksAdded = true;
}


// Sicherstellen, dass die Verbindung bereit ist
function ensurePeerConnection() {
    if (!localPeerConnection) createPeerConnection();
}


