// rtc.js
window.localPeerConnection = null;
window.localStream = null;
window.tracksAdded = false;
window.dataChannel = null;

window.iceServersLoaded = false;
window.meteredIceServers = null;

// Async-Laden der Metered.live ICE-Server
window.loadIceServers = async function() {
    const response = await fetch("index.php?act=get_turn_credentials");
    const iceServers = await response.json();
    window.meteredIceServers = Array.isArray(iceServers) ? iceServers : iceServers.iceServers;
    window.iceServersLoaded = true;

}


window.loadIceServers();

window.createPeerConnection = function(isInitiator = false) {
    if (window.localPeerConnection) return; // Schon initialisiert

    if (!window.iceServersLoaded) {
        console.log("ICE-Server werden noch geladen, PeerConnection verzögert!");
        setTimeout(() => window.createPeerConnection(isInitiator), 200);
        return;
    }

    const config = {
      iceServers: window.meteredIceServers
        //{ urls: 'stun:stun.l.google.com:19302' },
        //{ urls: 'turn:openrelay.metered.ca:80', username: 'openrelayproject', credential: 'openrelayproject' }
    };
    window.localPeerConnection = new RTCPeerConnection(config);

    // Nur als Anrufer DataChannel erzeugen
    if (isInitiator) {
        window.dataChannel = window.localPeerConnection.createDataChannel("chat");
        window.setupDataChannel(window.dataChannel);
    }

    window.localPeerConnection.ondatachannel = (event) => {
        window.dataChannel = event.channel;
        window.setupDataChannel(window.dataChannel);
    };

    // HIER die Korrektur:
    window.localPeerConnection.onicecandidate = event => {
        console.log("Sende ICE-Kandidat:", event.candidate);
        if (event.candidate && window.activeTargetUserId) {
            window.sendSignalMessage({ type: 'iceCandidate', candidate: event.candidate, target: window.activeTargetUserId });
        }
    };

    window.localPeerConnection.ontrack = event => {
        console.log("ontrack-Event! Streams:", event.streams);
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

window.setupDataChannel = function(dc) {
    dc.onopen = () => {
        document.getElementById('chat-area').style.display = "";
        console.log("DataChannel offen!");
    };
    dc.onclose = () => {
        document.getElementById('chat-area').style.display = "none";
        console.log("DataChannel geschlossen!");
        if (window.isCallActive) {
            alert("Der andere Teilnehmer hat die Verbindung beendet.");
            endCall(false); // Kein neues Signal senden, einfach lokal beenden
        }
    };
    dc.onmessage = (e) => {
        if (e.data === "__hangup__") {
            window.handleHangupSource("Peer");
            return;
        }
        if (typeof e.data === "string") {
            window.appendChatMsg("remote", e.data);
        } else {
            // Datei empfangen!
            const blob = new Blob([e.data]);
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = url;
            a.download = "empfangene_datei";
            a.textContent = "Datei herunterladen";
            document.getElementById("chat-log").appendChild(a);
        }
    };
};


