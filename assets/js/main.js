/**
 * Initialisiert alle Events und UI-Elemente rund um das WebRTC-Frontend.
 * Bindet Buttons, Devices, Call-Logik und Chat.
 */
window.webrtcApp.init = function() {
    // ---------- Call beenden-Button ----------
    ['end-call-btn'].forEach(id => {
        document.getElementById(id)?.addEventListener('click', function() {
            window.webrtcApp.rtc.endCall(true);
        });
    });

    // ---------- Initialisiere Chat-UI ----------
    window.webrtcApp.uiRtc.initChatUI();

    // ---------- Call-Annehmen/Ablehnen-Buttons ----------
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
            window.webrtcApp.uiRtc.setEndCallButtonVisible(false);
            const data = window.webrtcApp.state.pendingOffer;
            window.webrtcApp.state.activeTargetUserId = data.sender_id;
            window.webrtcApp.rtc.endCall(true);
            window.webrtcApp.sound.stop('incomming_call_ringtone');
        });
    }

    // ---------- Chat-Popup öffnen über Button ----------
    $(document).on('click', '.start-chat-btn', function () {
        const userId = $(this).data('userid');
        window.webrtcApp.uiChat.openChatPopup(userId);
    });

    // ---------- Starte Polling für Signaling nach Login ----------
    if (window.isLoggedIn) {
        window.webrtcApp.signaling.pollSignaling();
        const settings = document.getElementById('settings');
        if (settings) settings.style.display = '';
        window.webrtcApp.uiChat.updatePollingState();
    }

    // ---------- Medienauswahl für Call akzeptieren ----------
    const acceptMediaBtn = document.getElementById('media-accept-btn');
    if (acceptMediaBtn) {
        acceptMediaBtn.addEventListener('click', async function() {
            window.webrtcApp.sound.stop('incomming_call_ringtone');
            const dialog = document.getElementById('media-select-dialog');
            if (dialog) dialog.style.display = 'none';
            window.webrtcApp.uiRtc.setEndCallButtonVisible(true);
            window.webrtcApp.state.isCallActive = true;
            window.webrtcApp.uiChat.updatePollingState();
            const data = window.webrtcApp.state.pendingOffer;
            window.webrtcApp.state.activeTargetUserId = data.sender_id;
            window.webrtcApp.state.targetUsername = await window.webrtcApp.uiRtc.getUsername(data.sender_id);
            document.body.classList.add('call-active');
            document.getElementById('call-view').style.display = '';
            document.getElementById('remote-username').textContent = 'Anruf mit ' + window.webrtcApp.state.targetUsername;

            const useVideo = document.getElementById('media-video-checkbox').checked;
            const useAudio = document.getElementById('media-audio-checkbox').checked;
            let constraints = {};
            if (useVideo) constraints.video = true;
            if (useAudio) constraints.audio = true;
            if (!useVideo && !useAudio) {
                msg = 'Bitte mindestens Audio oder Video auswählen, um den Call zu starten!';
                window.webrtcApp.rtc.sendCallFailedMsg(msg)
                return;
            }
            let stream = null;
            try { stream = await navigator.mediaDevices.getUserMedia(constraints); }
            catch (e) {
                msg = 'Konnte Medien nicht holen: ' + e.message;
                window.webrtcApp.rtc.sendCallFailedMsg(msg)
                return;
            }
            window.webrtcApp.refs.localStream = stream;
            document.getElementById('local-video').srcObject = stream;
            await window.webrtcApp.rtc.loadIceServers();
            window.webrtcApp.rtc.createPeerConnection(false);
            window.webrtcApp.rtc.addLocalTracks();
            try {
                await window.webrtcApp.refs.localPeerConnection.setRemoteDescription(new RTCSessionDescription({
                    type: data.type,
                    sdp: data.sdp
                }));
            } catch (e) { alert("Fehler bei setRemoteDescription: " + e.message); return; }
            let answer;
            try {
                answer = await window.webrtcApp.refs.localPeerConnection.createAnswer();
                await window.webrtcApp.refs.localPeerConnection.setLocalDescription(answer);
            } catch (e) { alert("Fehler bei create/setLocalDescription: " + e.message); return; }
            window.webrtcApp.signaling.sendSignalMessage({
                type: 'answer',
                sdp: answer.sdp,
                target: data.sender_id
            });
            updateCallIcons();
        });
    }

    // ---------- Call per Button starten ----------
    document.querySelectorAll('.start-call-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const btnId = this.id || '';
            let userId = null;
            if (btnId.startsWith('start-call-btn-')) {
                userId = btnId.substring('start-call-btn-'.length);
            }
            window.webrtcApp.rtc.startCall(userId);
            setTimeout(updateCallIcons(), 1000);
        });
    });

    const throttleTime = 100; // ms
    const lastClicked = {};

    // ---------- Steuerungsbuttons (Desktop UND Mobile) ----------
    ['btn-forward', 'btn-backward', 'btn-left', 'btn-right', 'btn-forward-mobile', 'btn-backward-mobile', 'btn-left-mobile', 'btn-right-mobile'].forEach(id => {
        document.querySelectorAll(`#${id}`).forEach(btn => {
            btn.addEventListener('click', function() {
                const now = Date.now();
                if (!lastClicked[id] || now - lastClicked[id] > throttleTime) {
                    lastClicked[id] = now;
                    const name = id.replace('btn-', '').replace('-mobile', '');
                    window.webrtcApp.chat.send(`__arrow_${name}__`);
                    setTimeout(() => btn.blur(), 150);
                }
            });
        });
    });

    // ---------- Geräteauswahl (Kamera/Mikro) füllen (Setup für beide) ----------
    async function populateMediaDeviceLists() {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const selects = [
            ['camera-select', 'camera-select-in-call', 'camera-select-in-call-mobile'],
            ['mic-select', 'mic-select-in-call', 'mic-select-in-call-mobile']
        ];
        // Kameras
        selects[0].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            sel.innerHTML = "";
            devices.filter(d => d.kind === "videoinput").forEach((device, i) => {
                const option = document.createElement("option");
                option.value = device.deviceId;
                option.text = device.label || `Kamera ${i+1}`;
                sel.appendChild(option);
            });
        });
        // Mikrofone
        selects[1].forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;
            sel.innerHTML = "";
            const audios = devices.filter(d => d.kind === "audioinput");
            audios.forEach((device, i) => {
                const option = document.createElement("option");
                option.value = device.deviceId;
                option.text = device.label || `Mikrofon ${i+1}`;
                sel.appendChild(option);
            });
            if (audios.length === 0) {
                const option = document.createElement("option");
                option.text = "(Kein Mikrofon gefunden)";
                option.disabled = true;
                sel.appendChild(option);
            }
        });
    }
    populateMediaDeviceLists();

    // ---------- In-Call-Selects für Kamera/Mikro + Event-Handler ----------
    window.webrtcApp.init.updateMediaDeviceSelects = populateMediaDeviceLists;

    // ---------- Kamera/Mikro im laufenden Call wechseln ----------
    window.webrtcApp.init.handleMediaDeviceChange = async function(type) {
        // Hole alle passenden Selects
        let selects = [];
        if (type === 'video') {
            selects = [
                document.getElementById('camera-select-in-call'),
                document.getElementById('camera-select-in-call-mobile')
            ];
        } else {
            selects = [
                document.getElementById('mic-select-in-call'),
                document.getElementById('mic-select-in-call-mobile')
            ];
        }
        // Hole ersten Select mit Value
        let select = selects.find(sel => sel && sel.value);
        if (!select) return;
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

    // Event-Handler für ALLE Kamera/Mikro-Selects
    ['camera-select-in-call', 'camera-select-in-call-mobile'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => window.webrtcApp.init.handleMediaDeviceChange('video'));
    });
    ['mic-select-in-call', 'mic-select-in-call-mobile'].forEach(id => {
        document.getElementById(id)?.addEventListener('change', () => window.webrtcApp.init.handleMediaDeviceChange('audio'));
    });

    // ---------- ECHTER MUTE/UNMUTE & VIDEO ON/OFF für Desktop UND Mobile -----------
    function getSender(kind) {
        const pc = window.webrtcApp.refs.localPeerConnection;
        if (!pc) return null;
        let sender = pc.getSenders().find(s => s.track && s.track.kind === kind);
        if (!sender) sender = pc.getSenders().find(s => !s.track);
        return sender;
    }

    // Helper: alle Buttons/Icons holen
    function getBoth(desktopId, mobileId) {
        return [
            document.getElementById(desktopId),
            document.getElementById(mobileId)
        ].filter(Boolean);
    }

    // --- Mikro ---
    const micBtns  = getBoth('switch-mic-btn', 'switch-mic-btn-mobile');
    const micIcons = getBoth('mic-icon', 'mic-icon-mobile');
    function updateMicIcon() {
        const sender = getSender('audio');
        micIcons.forEach(icon => {
            icon.src = (sender && sender.track) ? 'assets/img/mic.png' : 'assets/img/mic-off.png';
        });
        micBtns.forEach(btn => {
            btn.title = (sender && sender.track) ? 'Mikrofon stummschalten' : 'Mikrofon einschalten';
        });
    }
    micBtns.forEach(btn => {
        btn.addEventListener('click', async function() {
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
    });

    // --- Kamera ---
    const camBtns  = getBoth('switch-cam-btn', 'switch-cam-btn-mobile');
    const camIcons = getBoth('cam-icon', 'cam-icon-mobile');
    function updateCamIcon() {
        const sender = getSender('video');
        camIcons.forEach(icon => {
            icon.src = (sender && sender.track) ? 'assets/img/camera.png' : 'assets/img/camera-off.png';
        });
        camBtns.forEach(btn => {
            btn.title = (sender && sender.track) ? 'Kamera ausschalten' : 'Kamera einschalten';
        });
    }
    camBtns.forEach(btn => {
        btn.addEventListener('click', async function() {
            if (!window.webrtcApp.state.isCallActive) return;
            const sender = getSender('video');
            const stream = window.webrtcApp.refs.localStream;
            if (sender && sender.track) {
                await sender.replaceTrack(null);
                if (window.webrtcApp.refs.dataChannel && window.webrtcApp.refs.dataChannel.readyState === "open") {
                    window.webrtcApp.refs.dataChannel.send("__video_off__");
                }
            } else {
                let newTrack = null;
                if (stream && stream.getVideoTracks().length > 0) {
                    newTrack = stream.getVideoTracks()[0];
                } else {
                    try {
                        const newStream = await navigator.mediaDevices.getUserMedia({ video: true });
                        newTrack = newStream.getVideoTracks()[0];
                        if (newTrack && stream) stream.addTrack(newTrack);
                    } catch (e) {
                        alert("Konnte Kamera nicht aktivieren: " + e.message);
                        return;
                    }
                }
                if (newTrack && sender) {
                    await sender.replaceTrack(newTrack);
                } else if (newTrack && stream && window.webrtcApp.refs.localPeerConnection) {
                    window.webrtcApp.refs.localPeerConnection.addTrack(newTrack, stream);
                }
                if (stream) {
                    document.getElementById('local-video').srcObject = stream;
                }
                if (window.webrtcApp.refs.dataChannel && window.webrtcApp.refs.dataChannel.readyState === "open") {
                    window.webrtcApp.refs.dataChannel.send("__video_on__");
                }
            }
            updateCamIcon();
        });
    });

    // Call-Icon-Status aktualisieren (wird mehrfach verwendet)
    function updateCallIcons() {
        updateMicIcon();
        updateCamIcon();
    }
    window.updateMicIcon = updateMicIcon;
    window.updateCamIcon = updateCamIcon;
    window.updateCallIcons = updateCallIcons;
    updateCallIcons();

    // Zeigen und verstecken der Buttons für Einstellungen, Locations usw.
    const params = new URLSearchParams(window.location.search);
    const act = params.get('act');
    if (act === 'settings' || act === 'get_all_chats' || act === 'show_chat') {
        window.webrtcApp.ui.setDisplay('chats', '');
    } else {
        window.webrtcApp.ui.showLocationButton();
        window.webrtcApp.ui.showAllLocationsButton();
    }
    if (window.isLoggedIn) window.webrtcApp.ui.setDisplay('settings', '');

    // Chat-FAB/Overlay für Mobile
    const fab = document.getElementById('chat-fab');
    const overlay = document.getElementById('mobile-chat-overlay'); // Overlay über dem ganzen Screen
    if (fab && overlay) {
        fab.onclick = function(e) {
            overlay.classList.add('active');
        };
        // Klick außerhalb des Bottom-Sheets schließt den Chat
        overlay.addEventListener('click', function(e) {
            if (e.target === overlay) {
                overlay.classList.remove('active');
            }
        });
    }

    // Nachrichten senden im mobilen Chat (setzt immer die .send()-Funktion des Chatmoduls ab)
    document.getElementById('chat-send-btn-mobile')?.addEventListener('click', function() {
        const input = document.getElementById('chat-input-mobile');
        const value = input?.value?.trim();
        if (value) {
            window.webrtcApp.chat.send(value);
            input.value = "";
        }
    });

    document.getElementById('mobile-chat-close')?.addEventListener('click', function() {
        const sheet = document.getElementById('mobile-chat-sheet');
        if (sheet) sheet.classList.remove('active'), sheet.style.display = 'none';
    });

};

// ---------- DOMContentLoaded: Initialisierung & Intervall-Tasks ----------
window.addEventListener('DOMContentLoaded', function() {
    window.webrtcApp.init();
    if (window.isLoggedIn) {
        setInterval(function() {
            window.webrtcApp.signaling.sendHeartbeat(window.webrtcApp.state.isCallActive);
        }, 15000);
    }
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '1', 'Lokation erfolgreich gespeichert!');
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '0', 'Speichern nicht erfolgreich. Stadt oder Beschreibung fehlt');
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '5', 'Registrierung erfolgreich!');
    window.webrtcApp.utils.showSuccessAlertIfNeeded('change', '1', 'Passwort erfolgreich geändert!');
    // Mobile Browser fix: reload nach Call-Ende
    if (/Android|iPhone|iPad|iPod|Mobile|Linux/i.test(navigator.userAgent)) {
        window.webrtcApp.ui.expandPanelForWideTableIfNeeded();
    }
});
