// assets/js/main.js
window.webrtcApp.init = function() {
    // End Call Button
    const endCallBtn = document.getElementById('end-call-btn');
    if (endCallBtn) {
        endCallBtn.addEventListener('click', function() {
            window.webrtcApp.rtc.endCall(true);
        });
    }

    // Chat-UI
    window.webrtcApp.ui.initChatUI();

    // Call annehmen/ablehnen Buttons
    const acceptBtn = document.getElementById('accept-call-btn');
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function() {
            const dialog = document.getElementById('media-select-dialog');
            if (dialog) dialog.style.display = '';
        });
    }
    
    const declineBtn = document.getElementById('media-decline-btn');
    if (declineBtn) {
        declineBtn.addEventListener('click', function() {
            const dialog = document.getElementById('media-select-dialog');
            if (dialog) dialog.style.display = 'none';
            if (acceptBtn) acceptBtn.style.display = "none";
            window.webrtcApp.ui.setEndCallButtonVisible(false);
            window.webrtcApp.rtc.endCall(true);
            window.webrtcApp.sound.stop('incomming_call_ringtone');
        });
    }

    // Optional: Signaling starten
    if (window.isLoggedIn) {
        window.webrtcApp.signaling.pollSignaling();
    }

    document.getElementById('media-accept-btn').addEventListener('click', async function() {
        window.webrtcApp.sound.stop('incomming_call_ringtone');
        const dialog = document.getElementById('media-select-dialog');
        if (dialog) dialog.style.display = 'none';
        window.webrtcApp.ui.setEndCallButtonVisible(true);
        window.webrtcApp.state.isCallActive = true;
        const data = window.webrtcApp.state.pendingOffer;
        window.webrtcApp.state.activeTargetUserId = data.sender_id;
        document.body.classList.add('call-active');
        document.getElementById('call-view').style.display = '';

        // Medien-Auswahl lesen
        const useVideo = document.getElementById('media-video-checkbox').checked;
        const useAudio = document.getElementById('media-audio-checkbox').checked;
        const videoDeviceId = document.getElementById('camera-select').value;
        const audioDeviceId = document.getElementById('mic-select').value;

        const constraints = {};
        if (useVideo) constraints.video = videoDeviceId ? { deviceId: { exact: videoDeviceId } } : true;
        if (useAudio) constraints.audio = audioDeviceId ? { deviceId: { exact: audioDeviceId } } : true;

        let stream = null;
        if (constraints.video || constraints.audio) {
            try {
                stream = await navigator.mediaDevices.getUserMedia(constraints);
            } catch (e) {
                alert('Konnte Medien nicht holen: ' + e.message);
                return;
            }
            window.webrtcApp.refs.localStream = stream;
            document.getElementById('local-video').srcObject = stream;
        } else {
            window.webrtcApp.refs.localStream = null;
        }

        // *** WICHTIG: ICE-Server sicherstellen ***
        await window.webrtcApp.rtc.loadIceServers();

        // *** PeerConnection erzeugen ***
        window.webrtcApp.rtc.createPeerConnection(false);

        // *** Jetzt Local Tracks hinzufügen ***
        window.webrtcApp.rtc.addLocalTracks();

        // *** Jetzt setRemoteDescription machen ***
        try {
            await window.webrtcApp.refs.localPeerConnection.setRemoteDescription(new RTCSessionDescription({
                type: data.type,
                sdp: data.sdp
            }));
        } catch (e) {
            alert("Fehler bei setRemoteDescription: " + e.message);
            return;
        }

        // *** Jetzt Answer erzeugen ***
        let answer;
        try {
            answer = await window.webrtcApp.refs.localPeerConnection.createAnswer();
            await window.webrtcApp.refs.localPeerConnection.setLocalDescription(answer);
        } catch (e) {
            alert("Fehler bei create/setLocalDescription: " + e.message);
            return;
        }

        // *** Answer an den Anrufer senden ***
        window.webrtcApp.signaling.sendSignalMessage({
            type: 'answer',
            sdp: answer.sdp,
            target: data.sender_id
        });
    });

    document.querySelectorAll('.start-call-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            // Button-ID hat das Format "start-call-btn-2"
            const btnId = this.id || '';
            let userId = null;
            if (btnId.startsWith('start-call-btn-')) {
                userId = btnId.substring('start-call-btn-'.length);
            }
            console.log("Starte Call mit User:", userId);
            window.webrtcApp.rtc.startCall(userId);
        });
    });

    // Am besten NACH dem DOMContentLoaded!
    document.getElementById('btn-forward').addEventListener('click', function() {
        window.webrtcApp.chat.send('__arrow_forward__');
    });
    document.getElementById('btn-backward').addEventListener('click', function() {
        window.webrtcApp.chat.send('__arrow_backward__');
    });
    document.getElementById('btn-left').addEventListener('click', function() {
        window.webrtcApp.chat.send('__arrow_left__');
    });
    document.getElementById('btn-right').addEventListener('click', function() {
        window.webrtcApp.chat.send('__arrow_right__');
    });


    navigator.mediaDevices.enumerateDevices().then(function(devices) {
        // Kamera
        const videoSelect = document.getElementById('camera-select');
        if (videoSelect) {
            videoSelect.innerHTML = "";
            devices.filter(d => d.kind === "videoinput").forEach(function(device, i) {
                const option = document.createElement("option");
                option.value = device.deviceId;
                option.text = device.label || `Kamera ${i+1}`;
                videoSelect.appendChild(option);
            });
        }
        // Mikrofon
        const micSelect = document.getElementById('mic-select');
        if (micSelect) {
            micSelect.innerHTML = "";
            const audios = devices.filter(d => d.kind === "audioinput");
            audios.forEach(function(device, i) {
                const option = document.createElement("option");
                option.value = device.deviceId;
                option.text = device.label || `Mikrofon ${i+1}`;
                micSelect.appendChild(option);
            });
            if (audios.length === 0) {
                const option = document.createElement("option");
                option.text = "(Kein Mikrofon gefunden)";
                option.disabled = true;
                micSelect.appendChild(option);
            }
        }
    });
};
window.addEventListener('DOMContentLoaded', function() {
    window.webrtcApp.init();
    window.webrtcApp.ui.showLocationButton();
    window.webrtcApp.ui.showAllLocationsButton();
});