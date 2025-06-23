/**
 * WebRTC-Modul: Beinhaltet alle Funktionen rund um PeerConnection, Anrufsteuerung und Medienstrom.
 */
window.webrtcApp.rtc = {
    /**
     * Beendet einen aktiven Call und räumt alles auf (UI, Streams, PeerConnection, Chat etc.)
     * @param {boolean} sendSignal - Soll ein Hangup-Signal an den Partner gesendet werden?
     */
    endCall(sendSignal = true) {
        this.resetUI();              // Entfernt UI-Call-Status
        this.closePeerConnection();  // PeerConnection & DataChannel schließen
        this.clearMediaStreams();    // Lokalen & Remote MediaStream beenden
        this.hideChatAndArrow();     // Chat/Arrow-Bereich verstecken

        // Hangup nur senden, wenn es nicht schon empfangen wurde
        if (!window.webrtcApp.state.hangupReceived) {
            window.webrtcApp.state.hangupReceived = true;
            if (sendSignal && window.webrtcApp.state.activeTargetUserId) {
                window.webrtcApp.signaling.sendSignalMessage({
                    type: 'hangup',
                    target: window.webrtcApp.state.activeTargetUserId
                });
                console.log("hangup");
            }
        }
        // State zurücksetzen
        window.webrtcApp.state.tracksAdded = false;
        window.webrtcApp.state.activeTargetUserId = null;
        window.webrtcApp.state.hangupReceived = false;
        window.webrtcApp.state.pendingOffer = null;
        window.webrtcApp.uiRtc.setEndCallButtonVisible(false);
        window.webrtcApp.state.isCallActive = false;
        window.webrtcApp.uiChat.updatePollingState();

        // Mobile Browser fix: reload nach Call-Ende
        if (/Android|iPhone|iPad|iPod|Mobile|Linux/i.test(navigator.userAgent)) {
            setTimeout(() => location.reload(), 1000);
        }
    },

    /**
     * Entfernt Call-spezifische UI-Elemente.
     */
    resetUI() {
        document.body.classList.remove('call-active');
        const callView = document.getElementById('call-view');
        if (callView) callView.style.display = 'none';
    },

    /**
     * Schließt die RTCPeerConnection und den DataChannel.
     */
    closePeerConnection() {
        const refs = window.webrtcApp.refs;
        if (refs.localPeerConnection) {
            refs.localPeerConnection.close();
            refs.localPeerConnection = null;
        }
        if (refs.dataChannel) {
            try { refs.dataChannel.close(); } catch (e) {}
            refs.dataChannel = null;
        }
    },

    /**
     * Beendet alle lokalen Media-Tracks (Webcam/Mic) und setzt Video-Elemente zurück.
     */
    clearMediaStreams() {
        const refs = window.webrtcApp.refs;
        if (refs.localStream) {
            refs.localStream.getTracks().forEach(track => {
                try { track.stop(); } catch(e) {}
            });
            refs.localStream = null;
        }
        const localVideo = document.getElementById('local-video');
        if (localVideo) localVideo.srcObject = null;
        const remoteVideo = document.getElementById('remote-video');
        if (remoteVideo) remoteVideo.srcObject = null;
    },

    /**
     * Versteckt Chat und Steuerungs-Pfeile.
     */
    hideChatAndArrow() {
        const chatArea = document.getElementById('chat-area');
        if (chatArea) chatArea.style.display = 'none';
        const arrowControl = document.getElementById('arrow-control');
        if (arrowControl) arrowControl.style.display = 'none';
    },

    /**
     * Startet einen neuen Call mit dem angegebenen Ziel-User.
     * @param {number} targetUserId - Die ID des Gesprächspartners
     */
    startCall: async function(targetUserId) {
        // 1. ICE-Server laden (für PeerConnection)
        if (!window.webrtcApp.refs.iceServersLoaded) {
            await window.webrtcApp.rtc.loadIceServers();
        }
        // 2. PeerConnection-Init (Dummy/Selbstanruf für Chrome-Bugfix)
        await window.webrtcApp.rtc.initFakeSelfCall();
        window.webrtcApp.state.activeTargetUserId = targetUserId;
        window.webrtcApp.state.targetUsername     = await window.webrtcApp.uiRtc.getUsername(targetUserId);

        // 3. Medien holen (Webcam/Mikro)
        navigator.mediaDevices.getUserMedia({ video: true, audio: true })
            .then(stream => {
                window.webrtcApp.refs.localStream = stream;
                updateCallIcons();
                document.getElementById('local-video').srcObject = stream;
                window.webrtcApp.rtc.createPeerConnection(true);
                window.webrtcApp.sound.play('call_ringtone');
                return new Promise(resolve => setTimeout(resolve, 100));
            })
            .then(() => {
                window.webrtcApp.rtc.addLocalTracks();
                updateCallIcons();
                return window.webrtcApp.refs.localPeerConnection.createOffer();
            })
            .then(offer => {
                return window.webrtcApp.refs.localPeerConnection.setLocalDescription(offer).then(() => offer);
            })
            .then(offer => {
                window.webrtcApp.signaling.sendSignalMessage({
                    type: 'offer',
                    sdp: offer.sdp,
                    target: targetUserId
                });
            })
            .catch(console.error);

        window.webrtcApp.uiRtc.setEndCallButtonVisible(true);
        window.webrtcApp.state.isCallActive = true;
        window.webrtcApp.uiChat.updatePollingState();
        document.body.classList.add('call-active');
        document.getElementById('call-view').style.display = '';
        console.log('Geladener Username:', window.webrtcApp.state.targetUsername);
        document.getElementById('remote-username').textContent = 'Rufe ' + window.webrtcApp.state.targetUsername + ' an';

        window.webrtcApp.rtc.startTimeout();
    },

    /**
     * Erstellt eine neue RTCPeerConnection und setzt Eventhandler.
     * @param {boolean} isInitiator - True: wir rufen an, False: wir nehmen an
     */
    createPeerConnection(isInitiator = false) {
        // ICE-Server müssen vorher geladen sein!
        if (!window.webrtcApp.refs.iceServersLoaded) {
            setTimeout(() => window.webrtcApp.rtc.createPeerConnection(isInitiator), 200);
            return;
        }
        if (window.webrtcApp.refs.localPeerConnection) return;

        const config = { iceServers: window.webrtcApp.refs.meteredIceServers };
        window.webrtcApp.refs.localPeerConnection = new RTCPeerConnection(config);

        // Verbindung verloren?
        window.webrtcApp.refs.localPeerConnection.onconnectionstatechange = function() {
            if (["disconnected", "failed", "closed"].includes(window.webrtcApp.refs.localPeerConnection.connectionState)) {
                window.webrtcApp.rtc.endCall(false);
                alert("Die Verbindung zum Gesprächspartner ist abgebrochen.");
            }
        };
        // ICE-State verloren?
        window.webrtcApp.refs.localPeerConnection.oniceconnectionstatechange = function() {
            if (["disconnected", "failed", "closed"].includes(window.webrtcApp.refs.localPeerConnection.iceConnectionState)) {
                window.webrtcApp.rtc.endCall(false);
                alert("Die Verbindung zum Gesprächspartner ist unterbrochen (ICE).");
            }
        };

        // DataChannel initialisieren (nur Initiator)
        if (isInitiator) {
            window.webrtcApp.refs.dataChannel = window.webrtcApp.refs.localPeerConnection.createDataChannel("chat");
            window.webrtcApp.rtc.setupDataChannel(window.webrtcApp.refs.dataChannel);
        }
        // Remote DataChannel wird gesetzt
        window.webrtcApp.refs.localPeerConnection.ondatachannel = (event) => {
            window.webrtcApp.refs.dataChannel = event.channel;
            window.webrtcApp.rtc.setupDataChannel(window.webrtcApp.refs.dataChannel);
        };

        // ICE-Candidates an Partner senden
        window.webrtcApp.refs.localPeerConnection.onicecandidate = event => {
            if (event.candidate && window.webrtcApp.state.activeTargetUserId) {
                window.webrtcApp.signaling.sendSignalMessage({
                    type: 'iceCandidate',
                    candidate: event.candidate,
                    target: window.webrtcApp.state.activeTargetUserId
                });
            }
        };

        // Remote-Stream anzeigen
        window.webrtcApp.refs.localPeerConnection.ontrack = event => {
            const remoteVideo = document.getElementById('remote-video');
            const placeholder = document.getElementById('remote-video-placeholder');
            if (remoteVideo) {
                remoteVideo.srcObject = event.streams[0];
                // Overlay verbergen, wenn Video kommt
                if (placeholder && event.streams[0].getVideoTracks().length > 0) {
                    placeholder.style.display = "none";
                    remoteVideo.style.display = "block";
                }
            }
        };
        window.webrtcApp.state.tracksAdded = false;
    },

    /**
     * Hängt lokale Audio/Video-Tracks an die PeerConnection an (nur 1x pro Call).
     */
    addLocalTracks() {
        if (!window.webrtcApp.refs.localStream || !window.webrtcApp.refs.localPeerConnection) return;
        if (window.webrtcApp.state.tracksAdded) return;
        window.webrtcApp.refs.localStream.getTracks().forEach(track => {
            window.webrtcApp.refs.localPeerConnection.addTrack(track, window.webrtcApp.refs.localStream);
        });
        window.webrtcApp.state.tracksAdded = true;
    },

    /**
     * Konfiguriert den DataChannel (Message-Handler, onOpen/onClose).
     * @param {RTCDataChannel} dc - DataChannel-Objekt
     */
    setupDataChannel(dc) {
        dc.onopen = () => {
            window.webrtcApp.sound.stop('call_ringtone');
            document.getElementById('chat-area').style.display = "";
            document.getElementById('arrow-control').style.display = "";
            window.webrtcApp.signaling.stopPolling();
        };
        dc.onclose = () => {
            window.webrtcApp.sound.stop('call_ringtone');
            document.getElementById('chat-area').style.display = "none";
            document.getElementById('arrow-control').style.display = "none";
            window.webrtcApp.signaling.pollSignaling();
        };
        dc.onmessage = (e) => {
            // Spezielle Steuerzeichen auswerten
            if (e.data === "__hangup__" && window.webrtcApp.state.isCallActive === true) {
                window.webrtcApp.rtc.endCall(false);
                alert("Der andere Teilnehmer hat die Verbindung beendet");
                return;
            }
            if (e.data === "__video_off__") {
                const remoteVideo = document.getElementById('remote-video');
                const placeholder = document.getElementById('remote-video-placeholder');
                if (remoteVideo) remoteVideo.style.display = "none";
                if (placeholder) placeholder.style.display = "flex";
                return;
            }
            if (e.data === "__video_on__") {
                const remoteVideo = document.getElementById('remote-video');
                const placeholder = document.getElementById('remote-video-placeholder');
                if (remoteVideo) remoteVideo.style.display = "block";
                if (placeholder) placeholder.style.display = "none";
                return;
            }
            // Steuerpfeile als Sound abspielen
            if (e.data === "__arrow_forward__") {
                window.webrtcApp.sound.play("move_forward_sound", false);
                return;
            }
            if (e.data === "__arrow_backward__") {
                window.webrtcApp.sound.play("move_back_sound", false);
                return;
            }
            if (e.data === "__arrow_left__") {
                window.webrtcApp.sound.play("turn_left_sound", false);
                return;
            }
            if (e.data === "__arrow_right__") {
                window.webrtcApp.sound.play("turn_right_sound", false);
                return;
            }
            // Normale Chatnachricht
            if (typeof e.data === "string") {
                window.webrtcApp.chat.appendMsg("remote", e.data);
            } else {
                // Binärdaten = Datei empfangen
                const blob = new Blob([e.data]);
                const url = URL.createObjectURL(blob);
                const a = document.createElement("a");
                a.href = url;
                a.download = "empfangene_datei";
                a.textContent = "Datei herunterladen";
                document.getElementById("chat-log").appendChild(a);
            }
        };
    },

    /**
     * Chrome-Workaround: Dummy-PeerConnection erzeugen, um MediaDevices zu aktivieren.
     */
    initFakeSelfCall: async function() {
        try {
            if (!window.webrtcApp.refs.localPeerConnection) {
                window.webrtcApp.rtc.createPeerConnection(true);
                await new Promise(resolve => setTimeout(resolve, 100));
                const stream = await navigator.mediaDevices.getUserMedia({video:true, audio:true});
                window.webrtcApp.refs.localStream = stream;
                updateCallIcons();

                window.webrtcApp.rtc.addLocalTracks();
                updateCallIcons();

                const offer = await window.webrtcApp.refs.localPeerConnection.createOffer();
                await window.webrtcApp.refs.localPeerConnection.setLocalDescription(offer);
            }
        } catch(e) {
            console.error("[FakeSelfCall] Fehler bei PeerConnection-Init:", e);
        }
    },

    /**
     * Lädt ICE-Server/Turn-Server vom Backend, kürzt auf max. 4 und speichert sie.
     */
    loadIceServers: async function() {
        const response = await fetch("index.php?act=get_turn_credentials");
        let iceServers = await response.json();

        // Falls das Objekt ein "iceServers"-Feld hat, extrahiere es
        if (!Array.isArray(iceServers) && iceServers.iceServers) {
            iceServers = iceServers.iceServers;
        }
        // Auf max. 2 STUN und 2 TURN kürzen
        if (Array.isArray(iceServers) && iceServers.length > 4) {
            const stunServers = iceServers.filter(s => s.urls && s.urls.toString().startsWith('stun:')).slice(0,2);
            const turnServers = iceServers.filter(s => s.urls && s.urls.toString().startsWith('turn:')).slice(0,2);
            iceServers = stunServers.concat(turnServers);
        }

        window.webrtcApp.refs.meteredIceServers = iceServers;
        window.webrtcApp.refs.iceServersLoaded = true;
        console.log("ICE-Server (gekürzt):", iceServers);
    },

    /**
     * Startet Timeout: Wird Call nicht angenommen, beendet sich der Call nach 25 Sekunden.
     */
    startTimeout: function() {
        if (window.webrtcApp.state.callTimeout) clearTimeout(window.webrtcApp.state.callTimeout);
        window.webrtcApp.state.callTimeout = setTimeout(function() {
            window.webrtcApp.signaling.sendSignalMessage({
                type: 'hangup',
                target: window.webrtcApp.state.activeTargetUserId,
                reason: 'timeout'
            });
            window.webrtcApp.sound.stop('call_ringtone');
            window.webrtcApp.rtc.endCall(false);
            alert('Der Anruf wurde nicht angenommen.');
        }, 25000);
        console.log('Start Timeout :' + window.webrtcApp.state.callTimeout);
    },

    /**
     * Stoppt das Call-Timeout (z.B. nach Annahme oder Beenden).
     */
    stopTimeout() {
        console.log('stop: ' + window.webrtcApp.state.callTimeout);
        if (window.webrtcApp.state.callTimeout) {
            clearTimeout(window.webrtcApp.state.callTimeout);
            window.webrtcApp.state.callTimeout = null;
        }
    },

    /**
     * Schickt eine Fehlermeldung (z.B. fehlende Medien) an den Partner und beendet den Call.
     * @param {string} msg - Fehlermeldung
     */
    sendCallFailedMsg(msg) {
        window.webrtcApp.signaling.sendSignalMessage({
            type: 'call_failed',
            target: window.webrtcApp.state.activeTargetUserId,
            reason: 'media_error'
        });
        window.webrtcApp.rtc.endCall(false);
        alert(msg);
    }
};
