// rtc.js
window.localPeerConnection = null;
window.localStream = null;
window.tracksAdded = false;
window.dataChannel = null;

/*window.createPeerConnection = function() {
    if (window.localPeerConnection) return; // Schon initialisiert
    const config = {
      iceServers: [
        { urls: 'stun:stun.l.google.com:19302' }
      ]
    };
    window.localPeerConnection = new RTCPeerConnection(config);

    window.dataChannel = window.localPeerConnection.createDataChannel("chat");
    setuptDataChannel(window.dataChannel);

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
};*/

window.createPeerConnection = function(isInitiator = false) {
    if (window.localPeerConnection) return; // Schon initialisiert
    const config = {
      iceServers: [
        { urls: 'stun:stun.l.google.com:19302' }
      ]
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

window.setupDataChannel = function(dc) {
    dc.onopen = () => {
        document.getElementById('chat-area').style.display = "";
        console.log("DataChannel offen!");
    };
    dc.onclose = () => {
        document.getElementById('chat-area').style.display = "none";
        console.log("DataChannel geschlossen!");
    };
    dc.onmessage = (e) => {
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


