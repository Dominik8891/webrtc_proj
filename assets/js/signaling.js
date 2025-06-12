// assets/js/signaling.js
window.webrtcApp.signaling = {
    sendSignalMessage(msg) {
        console.log("Sende Signal-Nachricht:", msg); // <--- NEU
        fetch('index.php?act=getSignal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(msg)
        })
        .then(r => r.text())
        .catch(console.error);
    },
    pollSignaling() {
        if (window.webrtcApp.refs.pollingIntervalId !== null) return;
        console.log("Starte Polling..."); // <--- NEU
        window.webrtcApp.refs.pollingIntervalId = setInterval(() => {
            fetch('index.php?act=getSignal')
                .then(r => r.json())
                .then(msgArr => {
                    console.log("Signaling-Nachrichten erhalten:", msgArr); // <--- NEU
                    if (Array.isArray(msgArr)) {
                        msgArr.forEach(msg => window.webrtcApp.signaling.handleSignalingData(msg));
                    }
                });
        }, 1500);
    },
    stopPolling() {
        if (window.webrtcApp.refs.pollingIntervalId !== null) {
            clearInterval(window.webrtcApp.refs.pollingIntervalId);
            window.webrtcApp.refs.pollingIntervalId = null;
        }
    },
    handleSignalingData: async function(data) {
        console.log("Empfange Signaling-Daten:", data); // <--- NEU
        if (data.type === 'offer') {
            window.webrtcApp.state.pendingOffer = data;
            // UI für eingehenden Anruf anzeigen
            var dialog = document.getElementById('media-select-dialog');
            if (dialog) {
                dialog.style.display = '';
                window.webrtcApp.state.targetUsername     = await window.webrtcApp.ui.getUsername(data.sender_id);
                document.getElementById('calling_user').textContent = window.webrtcApp.state.targetUsername + ' ruft an';
            }
            var btn = document.getElementById('accept-call-btn');
            if (btn) btn.style.display = "none";
            window.webrtcApp.sound.play('incomming_call_ringtone');
        } else if (data.type === 'answer') {
            console.log('Stopped Timeout :' + window.webrtcApp.state.callTimeout);
            window.webrtcApp.rtc.stopTimeout();
            window.webrtcApp.refs.localPeerConnection.setRemoteDescription(new RTCSessionDescription({
                type: data.type,
                sdp: data.sdp
            })).then(() => {
                if (window.webrtcApp.refs.pendingCandidates && window.webrtcApp.refs.pendingCandidates.length) {
                    window.webrtcApp.refs.pendingCandidates.forEach(candidate =>
                        window.webrtcApp.refs.localPeerConnection.addIceCandidate(new RTCIceCandidate(candidate))
                    );
                    window.webrtcApp.refs.pendingCandidates = [];
                }
            });
        } else if (data.type === 'iceCandidate') {
            let candidateObj = data.candidate;
            if (!candidateObj) return;
            if (typeof candidateObj === "string") {
                try { candidateObj = JSON.parse(candidateObj); }
                catch(e) { return; }
            }
            if (
                !window.webrtcApp.refs.localPeerConnection ||
                !window.webrtcApp.refs.localPeerConnection.remoteDescription ||
                !window.webrtcApp.refs.localPeerConnection.remoteDescription.type
            ) {
                window.webrtcApp.refs.pendingCandidates.push(candidateObj);
            } else {
                window.webrtcApp.refs.localPeerConnection.addIceCandidate(new RTCIceCandidate(candidateObj));
            }
        } else if (data.type === 'hangup') {
            window.webrtcApp.rtc.stopTimeout();
            if (window.webrtcApp.state.isCallActive === true) {
                window.webrtcApp.rtc.endCall(false);
                window.webrtcApp.sound.stop('call_ringtone');
                var acceptBtn = document.getElementById('accept-call-btn');
                if (acceptBtn) acceptBtn.style.display = "none";
                window.webrtcApp.ui.setEndCallButtonVisible(false); 
                alert("Der andere Teilnehmer hat die Verbindung beendet.");
            } else if (window.webrtcApp.state.pendingOffer.sender_id === data.sender_id){
                var dialog = document.getElementById('media-select-dialog');
                if (dialog) dialog.style.display = 'none';
                window.webrtcApp.sound.stop('incomming_call_ringtone');
            }    
        } else if (data.type === 'call_failed') {
            window.webrtcApp.sound.stop('call_ringtone');
            window.webrtcApp.rtc.endCall(false);
            // Empfänger hat Call abgebrochen/abgelehnt/Fehler
            // Gib dem User einen Hinweis:
            alert('Der Anruf konnte nicht gestartet werden.\nGrund: ' +
                (data.reason === 'no_media_selected' ? 'Keine Medien ausgewählt.' : 'Fehler beim Aufbau der Verbindung.'));   
        }
    },
    sendHeartbeat(inCall) {
        fetch('index.php?act=heartbeat', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                in_call: inCall ? 1 : 0
            })
        });
    },

};
