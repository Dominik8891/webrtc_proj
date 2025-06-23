/**
 * Signaling-Modul: Steuert das Senden/Empfangen von Nachrichten für die WebRTC-Signalisierung.
 * (Offer, Answer, ICE-Candidate, Call-Events etc.)
 */
window.webrtcApp.signaling = {
    /**
     * Sendet eine Signalnachricht (z.B. offer/answer/candidate/hangup) per POST an das Backend.
     * @param {Object} msg - Zu sendende Nachricht (JSON)
     */
    sendSignalMessage(msg) {
        console.log("Sende Signal-Nachricht:", msg); // Debug-Log
        fetch('index.php?act=getSignal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(msg)
        })
        .then(r => r.text())
        .catch(console.error);
    },

    /**
     * Startet das regelmäßige Polling für eingehende Signalisierungsnachrichten.
     * (Wird als Backup für WebSockets verwendet.)
     */
    pollSignaling() {
        if (window.webrtcApp.refs.pollingIntervalId !== null) return;
        console.log("Starte Polling...");
        window.webrtcApp.refs.pollingIntervalId = setInterval(() => {
            fetch('index.php?act=getSignal')
                .then(r => r.json())
                .then(msgArr => {
                    console.log("Signaling-Nachrichten erhalten:", msgArr);
                    if (Array.isArray(msgArr)) {
                        msgArr.forEach(msg => window.webrtcApp.signaling.handleSignalingData(msg));
                    }
                });
        }, 1500);
    },

    /**
     * Beendet das Polling für Signalisierungsnachrichten.
     */
    stopPolling() {
        if (window.webrtcApp.refs.pollingIntervalId !== null) {
            clearInterval(window.webrtcApp.refs.pollingIntervalId);
            window.webrtcApp.refs.pollingIntervalId = null;
        }
    },

    /**
     * Verarbeitet empfangene Signalisierungsdaten (Offer, Answer, ICE, Hangup etc.).
     * @param {Object} data - Die erhaltene Nachricht
     */
    handleSignalingData: async function(data) {
        console.log("Empfange Signaling-Daten:", data);

        if (data.type === 'offer') {
            // Eingehender Anruf: UI vorbereiten
            window.webrtcApp.state.pendingOffer = data;
            var dialog = document.getElementById('media-select-dialog');
            if (dialog) {
                dialog.style.display = '';
                window.webrtcApp.state.targetUsername = await window.webrtcApp.uiRtc.getUsername(data.sender_id);
                document.getElementById('calling_user').textContent = window.webrtcApp.state.targetUsername + ' ruft an';
            }
            var btn = document.getElementById('accept-call-btn');
            if (btn) btn.style.display = "none";
            window.webrtcApp.sound.play('incomming_call_ringtone');

        } else if (data.type === 'answer') {
            // Antwort auf unseren Offer: Verbindung aufbauen
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
            // ICE-Kandidat erhalten (entweder sofort hinzufügen oder puffern)
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
            // Gespräch wurde beendet (vom Partner)
            window.webrtcApp.rtc.stopTimeout();
            if (window.webrtcApp.state.isCallActive === true) {
                window.webrtcApp.rtc.endCall(false);
                window.webrtcApp.sound.stop('call_ringtone');
                var acceptBtn = document.getElementById('accept-call-btn');
                if (acceptBtn) acceptBtn.style.display = "none";
                window.webrtcApp.uiRtc.setEndCallButtonVisible(false); 
                alert("Der andere Teilnehmer hat die Verbindung beendet.");
            } else if (window.webrtcApp.state.pendingOffer.sender_id === data.sender_id){
                var dialog = document.getElementById('media-select-dialog');
                if (dialog) dialog.style.display = 'none';
                window.webrtcApp.sound.stop('incomming_call_ringtone');
            }    

        } else if (data.type === 'call_failed') {
            // Call konnte nicht aufgebaut werden (Fehler, kein Media etc.)
            window.webrtcApp.sound.stop('call_ringtone');
            window.webrtcApp.rtc.endCall(false);
            window.webrtcApp.rtc.stopTimeout();
            alert('Der Anruf konnte nicht gestartet werden.\nGrund: ' +
                (data.reason === 'no_media_selected' ? 'Keine Medien ausgewählt.' : 'Fehler beim Aufbau der Verbindung.'));   
        }
    },

    /**
     * Sendet regelmäßig einen Heartbeat (User online, ggf. im Call).
     * @param {boolean} inCall - Ist der User aktuell in einem Call?
     */
    sendHeartbeat(inCall) {
        fetch('index.php?act=heartbeat', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                in_call: inCall ? 1 : 0
            })
        });
    }
};
