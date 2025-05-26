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
        window.stopSound('call_ringtone');
        document.getElementById('chat-area').style.display = "";
        document.getElementById('arrow-control').style.display = "";
        console.log("DataChannel offen!");
        stopPolling();
    };
    dc.onclose = () => {
        window.stopSound('call_ringtone');
        document.getElementById('chat-area').style.display = "none";
        document.getElementById('arrow-control').style.display = "none";
        console.log("DataChannel geschlossen!");
        if (window.hangupReceived) {
            alert("Der andere Teilnehmer hat die Verbindung beendet.");
            endCall(false); // Kein neues Signal senden, einfach lokal beenden
        }
        pollSignaling();
    };
    dc.onmessage = (e) => {
        if (e.data === "__hangup__") {
            window.handleHangupSource("Peer");
            return;
        }
        // ARROW CONTROL: Sounds abspielen
        if (e.data === "__arrow_forward__") {
            window.playSound("move_forward_sound", false);
            return;
        }
        if (e.data === "__arrow_backward__") {
            window.playSound("move_back_sound", false);
            return;
        }
        if (e.data === "__arrow_left__") {
            window.playSound("turn_left_sound", false);
            return;
        }
        if (e.data === "__arrow_right__") {
            window.playSound("turn_right_sound", false);
            return;
        }
        if (typeof e.data === "string") {
            window.appendChatMsg("remote", e.data);
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

window.initFakeSelfCall = async function() {
    try {
        if (!window.localPeerConnection) {
            console.log("[FakeSelfCall] Starte Initialisierung...");
            window.createPeerConnection(true);

            // Warte einen Tick, damit die PeerConnection angelegt ist
            await new Promise(resolve => setTimeout(resolve, 100));

            // Media holen (Dummy-Stream)
            const stream = await navigator.mediaDevices.getUserMedia({video:true, audio:true});
            window.localStream = stream;
            window.addLocalTracks();

            // Offer erzeugen (NICHT senden!)
            const offer = await window.localPeerConnection.createOffer();
            await window.localPeerConnection.setLocalDescription(offer);

            console.log("[FakeSelfCall] PeerConnection-Init abgeschlossen:", window.localPeerConnection);
        }
    } catch(e) {
        console.error("[FakeSelfCall] Fehler bei PeerConnection-Init:", e);
    }
};

window.dumpWebRTCState = function(context = "") {
    console.log("-----[ WebRTC STATE: " + context + " ]-----");
    console.log("localPeerConnection:", window.localPeerConnection);
    if (window.localPeerConnection) {
        console.log("  - connectionState:", window.localPeerConnection.connectionState);
        console.log("  - signalingState:", window.localPeerConnection.signalingState);
        console.log("  - iceConnectionState:", window.localPeerConnection.iceConnectionState);
        console.log("  - localDescription:", window.localPeerConnection.localDescription);
        console.log("  - remoteDescription:", window.localPeerConnection.remoteDescription);
        console.log("  - senders:", window.localPeerConnection.getSenders());
        console.log("  - receivers:", window.localPeerConnection.getReceivers());
    }
    console.log("localStream:", window.localStream);
    if (window.localStream) {
        console.log("  - tracks:", window.localStream.getTracks());
    }
    console.log("dataChannel:", window.dataChannel);
    if (window.dataChannel) {
        console.log("  - readyState:", window.dataChannel.readyState);
        console.log("  - label:", window.dataChannel.label);
    }
    console.log("tracksAdded:", window.tracksAdded);
    console.log("pendingCandidates:", window.pendingCandidates);
    console.log("isCallActive:", window.isCallActive);
    console.log("activeTargetUserId:", window.activeTargetUserId);
    console.log("pendingOffer:", window.pendingOffer);
    console.log("--------------------------------------");
}

