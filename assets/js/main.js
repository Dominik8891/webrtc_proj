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
            const data = window.webrtcApp.state.pendingOffer;
            window.webrtcApp.state.activeTargetUserId = data.sender_id;
            window.webrtcApp.rtc.endCall(true);
            window.webrtcApp.sound.stop('incomming_call_ringtone');
        });
    }

    // Optional: Signaling starten
    if (window.isLoggedIn) {
        window.webrtcApp.signaling.pollSignaling();
    }

    const acceptMediaBtn = document.getElementById('media-accept-btn');
    if (acceptMediaBtn) {
        acceptMediaBtn.addEventListener('click', async function() {
            window.webrtcApp.sound.stop('incomming_call_ringtone');
            const dialog = document.getElementById('media-select-dialog');
            if (dialog) dialog.style.display = 'none';
            window.webrtcApp.ui.setEndCallButtonVisible(true);
            window.webrtcApp.state.isCallActive = true;
            const data = window.webrtcApp.state.pendingOffer;
            window.webrtcApp.state.activeTargetUserId = data.sender_id;
            window.webrtcApp.state.targetUsername = await window.webrtcApp.ui.getUsername(data.sender_id);
            document.body.classList.add('call-active');
            document.getElementById('call-view').style.display = '';
            document.getElementById('remote-username').textContent = 'Anruf mit ' + window.webrtcApp.state.targetUsername;

            // Checkboxen holen
            const useVideo = document.getElementById('media-video-checkbox').checked;
            const useAudio = document.getElementById('media-audio-checkbox').checked;

            let constraints = {};
            if (useVideo) constraints.video = true;
            if (useAudio) constraints.audio = true;

            if (!useVideo && !useAudio) {
                // Dem Sender mitteilen, dass der Call abgelehnt wurde!
                window.webrtcApp.signaling.sendSignalMessage({
                    type: 'call_failed',
                    target: data.sender_id,
                    reason: 'no_media_selected'
                });
                window.webrtcApp.rtc.endCall(false);
                alert('Bitte mindestens Audio oder Video auswählen, um den Call zu starten!');
                return;
            }

            let stream = null;
            try {
                stream = await navigator.mediaDevices.getUserMedia(constraints);
            } catch (e) {
                window.webrtcApp.signaling.sendSignalMessage({
                    type: 'call_failed',
                    target: data.sender_id,
                    reason: 'media_error'
                });
                window.webrtcApp.rtc.endCall(false);
                alert('Konnte Medien nicht holen: ' + e.message);
                return;
            }

            window.webrtcApp.refs.localStream = stream;
            document.getElementById('local-video').srcObject = stream;


            // *** ICE-Server sicherstellen ***
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

            // Nach Call-Start Icons updaten!
            updateCallIcons();
        });
    }

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
            setTimeout(updateCallIcons, 1000); // Nach Call-Start Icons aktualisieren
        });
    });

    if (window.isLoggedIn) {
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
    }

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

    // IN-CALL Kamera/Mikrofon Selects
    window.webrtcApp.init.updateMediaDeviceSelects = async function() {
        const devices = await navigator.mediaDevices.enumerateDevices();

        // Kamera
        const videoSelect = document.getElementById('camera-select-in-call');
        if (videoSelect) {
            videoSelect.innerHTML = "";
            devices.filter(d => d.kind === "videoinput").forEach((device, i) => {
                const option = document.createElement("option");
                option.value = device.deviceId;
                option.text = device.label || `Kamera ${i+1}`;
                videoSelect.appendChild(option);
            });
            const currentVideoTrack = window.webrtcApp.refs.localStream?.getVideoTracks()[0];
            if (currentVideoTrack) {
                const settings = currentVideoTrack.getSettings();
                if (settings.deviceId)
                    videoSelect.value = settings.deviceId;
            }
        }

        // Mikrofon
        const micSelect = document.getElementById('mic-select-in-call');
        if (micSelect) {
            micSelect.innerHTML = "";
            devices.filter(d => d.kind === "audioinput").forEach((device, i) => {
                const option = document.createElement("option");
                option.value = device.deviceId;
                option.text = device.label || `Mikrofon ${i+1}`;
                micSelect.appendChild(option);
            });
            const currentAudioTrack = window.webrtcApp.refs.localStream?.getAudioTracks()[0];
            if (currentAudioTrack) {
                const settings = currentAudioTrack.getSettings();
                if (settings.deviceId)
                    micSelect.value = settings.deviceId;
            }
        }
    };

    window.webrtcApp.init.handleMediaDeviceChange = async function(type) {
        const select = document.getElementById(type === 'video' ? 'camera-select-in-call' : 'mic-select-in-call');
        if (!select || !select.value) return;
        const constraints = {};
        constraints[type] = { deviceId: { exact: select.value } };
        const stream = await navigator.mediaDevices.getUserMedia(constraints);
        const track = type === 'video' ? stream.getVideoTracks()[0] : stream.getAudioTracks()[0];
        const pc = window.webrtcApp.refs.localPeerConnection;
        const sender = pc?.getSenders().find(s => s.track && s.track.kind === type);
        if (sender && track) {
            await sender.replaceTrack(track);
            const localStream = window.webrtcApp.refs.localStream;
            if (localStream) {
                localStream.getTracks().filter(t => t.kind === type).forEach(t => localStream.removeTrack(t));
                localStream.addTrack(track);
            }
            if (type === 'video') {
                document.getElementById('local-video').srcObject = window.webrtcApp.refs.localStream;
            }
        }
        updateCallIcons();
    };

    // ========== ECHTER MUTE/UNMUTE & VIDEO ON/OFF ==========

    function getSender(kind) {
        const pc = window.webrtcApp.refs.localPeerConnection;
        if (!pc) return null;
        // Zuerst mit aktivem Track
        let sender = pc.getSenders().find(s => s.track && s.track.kind === kind);
        // Wenn nicht, dann der erste Sender ohne aktiven Track (nach Mute)
        if (!sender) {
            sender = pc.getSenders().find(s => !s.track);
        }
        return sender;
    }

    // Mikrofon-Button & Icon
    const micBtn = document.getElementById('switch-mic-btn');
    const micIcon = document.getElementById('mic-icon');
    function updateMicIcon() {
        const sender = getSender('audio');
        if (!micBtn || !micIcon) return;
        if (sender && sender.track) {
            micIcon.src = 'assets/img/mic.png';
            micBtn.title = 'Mikrofon stummschalten';
        } else {
            micIcon.src = 'assets/img/mic-off.png';
            micBtn.title = 'Mikrofon einschalten';
        }
    }
    if (micBtn && micIcon) {
        micBtn.addEventListener('click', async function() {
            if (!window.webrtcApp.state.isCallActive) return;
            const sender = getSender('audio');
            if (sender && sender.track) {
                await sender.replaceTrack(null);
            } else {
                const stream = window.webrtcApp.refs.localStream;
                if (stream) {
                    const newTrack = stream.getAudioTracks()[0];
                    if (newTrack) await sender.replaceTrack(newTrack);
                }
            }
            updateMicIcon();
        });
    }

    // Kamera-Button & Icon
    const camBtn = document.getElementById('switch-cam-btn');
    const camIcon = document.getElementById('cam-icon');
    function updateCamIcon() {
        const sender = getSender('video');
        if (!camBtn || !camIcon) return;
        if (sender && sender.track) {
            camIcon.src = 'assets/img/camera.png';
            camBtn.title = 'Kamera ausschalten';
        } else {
            camIcon.src = 'assets/img/camera-off.png';
            camBtn.title = 'Kamera einschalten';
        }
    }
    if (camBtn && camIcon) {
        camBtn.addEventListener('click', async function() {
            if (!window.webrtcApp.state.isCallActive) return;
            const sender = getSender('video');
            const stream = window.webrtcApp.refs.localStream;

            if (sender && sender.track) {
                // Kamera aus (Track entfernen)
                await sender.replaceTrack(null);
                if (window.webrtcApp.refs.dataChannel && window.webrtcApp.refs.dataChannel.readyState === "open") {
                    window.webrtcApp.refs.dataChannel.send("__video_off__");
                }
            } else {
                // Kamera an (Track wieder aktivieren oder neuen holen)
                let newTrack = null;
                // Versuche zuerst, ob im Stream schon ein Track ist (war vielleicht nur disabled)
                if (stream && stream.getVideoTracks().length > 0) {
                    newTrack = stream.getVideoTracks()[0];
                } else {
                    // Noch kein Track: Jetzt Kamera holen!
                    try {
                        const newStream = await navigator.mediaDevices.getUserMedia({ video: true });
                        newTrack = newStream.getVideoTracks()[0];
                        if (newTrack && stream) {
                            stream.addTrack(newTrack);
                        }
                    } catch (e) {
                        alert("Konnte Kamera nicht aktivieren: " + e.message);
                        return;
                    }
                }

                if (newTrack && sender) {
                    await sender.replaceTrack(newTrack);
                } else if (newTrack && stream && window.webrtcApp.refs.localPeerConnection) {
                    // Es gibt keinen Sender mehr? Dann neuen Sender erzeugen!
                    window.webrtcApp.refs.localPeerConnection.addTrack(newTrack, stream);
                }

                // Videoelement updaten
                if (stream) {
                    document.getElementById('local-video').srcObject = stream;
                }

                if (window.webrtcApp.refs.dataChannel && window.webrtcApp.refs.dataChannel.readyState === "open") {
                    window.webrtcApp.refs.dataChannel.send("__video_on__");
                }
            }
            updateCamIcon();
        });
    }

    function updateCallIcons() {
        updateMicIcon();
        updateCamIcon();
    }

    window.updateMicIcon = updateMicIcon;
    window.updateCamIcon = updateCamIcon;
    window.updateCallIcons = updateCallIcons;

    // Beim Start direkt Icons setzen
    updateCallIcons();

    // IN-CALL-Selects mit Event-Handlern
    window.webrtcApp.init.updateMediaDeviceSelects();
    document.getElementById('camera-select-in-call')?.addEventListener('change', () => window.webrtcApp.init.handleMediaDeviceChange('video'));
    document.getElementById('mic-select-in-call')?.addEventListener('change', () => window.webrtcApp.init.handleMediaDeviceChange('audio'));
};
window.addEventListener('DOMContentLoaded', function() {
    window.webrtcApp.init();
    window.webrtcApp.ui.showLocationButton();
    window.webrtcApp.ui.showAllLocationsButton();

    if (window.isLoggedIn) {
        setInterval(function() {
            window.webrtcApp.signaling.sendHeartbeat(window.webrtcApp.state.isCallActive);
        }, 15000);  
    }
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '1', 'Lokation erfolgreich gespeichert!');
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '0', 'Speichern nicht erfolgreich. Stadt oder Beschreibung fehlt');
});
