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
            // Wir nehmen an, sind also nicht der Initiator: Bei einer Störung
            // handelt die Gegenseite neu aus, wir bitten sie nur darum.
            window.webrtcApp.state.isInitiator = false;
            window.webrtcApp.rtc.setConnectionStatus('connecting');
            // Im Call langsamer weiterpollen - der Weg wird für Auflegen und
            // ICE-Restart gebraucht.
            window.webrtcApp.signaling.setPollInterval(window.webrtcApp.signaling.POLL_INTERVAL_IN_CALL);
            window.webrtcApp.uiChat.updatePollingState();
            const data = window.webrtcApp.state.pendingOffer;
            window.webrtcApp.state.activeTargetUserId = data.sender_id;
            // Die Rolle hat der Server an das Offer gestempelt (siehe
            // WebRTCController::roleForCall). Fehlt sie, gilt sie als
            // unbekannt - dann rendert kein Steuerkreuz und eingehende
            // Bewegungsbefehle werden abgelehnt.
            window.webrtcApp.control.applyRole(data.role || null);
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

    // ---------- Steuerungsbuttons ----------
    // Es gibt sie nur noch einmal. Frueher lag daneben ein zweiter Satz mit
    // der Endung "-mobile", weil die Call-Ansicht zwei getrennte Layouts
    // hatte; jetzt liegt dasselbe Steuerkreuz als Overlay im Bild und die
    // Anordnung entscheidet allein assets/css/call.css.
    window.webrtcApp.control.ARROW_BUTTON_IDS.forEach(id => {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.addEventListener('click', function() {
            const now = Date.now();
            if (!lastClicked[id] || now - lastClicked[id] > throttleTime) {
                lastClicked[id] = now;
                const name = id.replace('btn-', '');
                // Steuerbefehle laufen über control.sendMove: Dort werden
                // Rolle, Sperre und ausstehende Bestätigung geprüft, und
                // bei instabiler Verbindung wird verworfen statt gepuffert
                // (siehe rtc.canSendControlCommand).
                window.webrtcApp.control.sendMove(name);
                setTimeout(() => btn.blur(), 150);
            }
        });
    });

    // ---------- Steuerung sperren/freigeben (nur Guide) ----------
    // Der Guide haelt die Steuerung kurz an, etwa beim Ueberqueren einer
    // Strasse. Die Schaltflaeche ist nur in seiner Rolle sichtbar (call.css).
    document.getElementById('control-lock-btn')?.addEventListener('click', function() {
        window.webrtcApp.control.toggleLock();
    });

    // ---------- Geräteauswahl (Kamera/Mikro) füllen (Setup für beide) ----------
    async function populateMediaDeviceLists() {
        const devices = await navigator.mediaDevices.enumerateDevices();
        // Je Geraeteart zwei Auswahlfelder: eines im Annahmedialog, eines in
        // der Call-Ansicht.
        const selects = [
            ['camera-select', 'camera-select-in-call'],
            ['mic-select', 'mic-select-in-call']
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
        const select = document.getElementById(
            type === 'video' ? 'camera-select-in-call' : 'mic-select-in-call'
        );
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

    // Geraetewechsel im laufenden Call
    document.getElementById('camera-select-in-call')
        ?.addEventListener('change', () => window.webrtcApp.init.handleMediaDeviceChange('video'));
    document.getElementById('mic-select-in-call')
        ?.addEventListener('change', () => window.webrtcApp.init.handleMediaDeviceChange('audio'));

    // ---------- ECHTER MUTE/UNMUTE & VIDEO ON/OFF für Desktop UND Mobile -----------
    function getSender(kind) {
        const pc = window.webrtcApp.refs.localPeerConnection;
        if (!pc) return null;
        let sender = pc.getSenders().find(s => s.track && s.track.kind === kind);
        if (!sender) sender = pc.getSenders().find(s => !s.track);
        return sender;
    }

    // Mikrofon und Kamera gibt es je einmal - die Bedienleiste der
    // Call-Ansicht ist dieselbe auf jedem Geraet. Hier stand vorher ein
    // Helfer, der Desktop- und Mobilknopf zu einem Paar zusammensuchte.
    const micBtns  = [document.getElementById('switch-mic-btn')].filter(Boolean);
    const micIcons = [document.getElementById('mic-icon')].filter(Boolean);
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
    const camBtns  = [document.getElementById('switch-cam-btn')].filter(Boolean);
    const camIcons = [document.getElementById('cam-icon')].filter(Boolean);
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
                window.webrtcApp.control.sendVideoState(false);
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
                window.webrtcApp.control.sendVideoState(true);
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

    // ---------- Kopfleiste ----------
    //
    // In der Leiste stehen nur noch die beiden haeufigen Aktionen. Konto,
    // Chats, Benutzerliste und Abmelden liegen im Benutzermenue, das der
    // Server mitliefert (App\Helper\ViewHelper::userMenu) - hier ist dafuer
    // nichts mehr ein- oder auszublenden. Frueher stand hier eine Abfrage auf
    // den aktuellen 'act', die auf den Kontoseiten die Standortknoepfe gegen
    // einen Chat-Knopf tauschte.
    window.webrtcApp.ui.showLocationButton();
    window.webrtcApp.ui.showAllLocationsButton();
    window.webrtcApp.ui.bindUserMenu();

    // ---------- Overlays der Call-Ansicht: Chat und Geraeteauswahl -------
    //
    // Beide sind aufklappbar und nie gleichzeitig offen: Der Chat legt sich
    // ueber das ganze Bild, die Geraeteauswahl sitzt ueber der Leiste. Zwei
    // offene Blaetter uebereinander waeren nur im Weg.
    //
    // Frueher gab es dafuer einen runden Chatknopf, der nur auf Mobilgeraeten
    // erschien, und einen zweiten Chat mit eigenen IDs. Jetzt ist es ein
    // Chat, ein Knopf, auf jedem Geraet.
    const chatOverlay  = document.getElementById('chat-overlay');
    const chatToggle   = document.getElementById('chat-toggle-btn');
    const deviceSheet  = document.getElementById('call-devices');
    const deviceToggle = document.getElementById('call-devices-btn');

    /**
     * Blendet eines der beiden Blaetter ein oder aus und haelt den Zustand
     * des zugehoerigen Knopfes (aria-expanded) nach.
     *
     * @param {HTMLElement|null} blatt
     * @param {HTMLElement|null} knopf
     * @param {boolean} offen
     */
    function setSheet(blatt, knopf, offen) {
        if (!blatt) return;
        blatt.hidden = !offen;
        if (knopf) knopf.setAttribute('aria-expanded', offen ? 'true' : 'false');

        // Das Chatblatt legt sich auf breiten Schirmen an die rechte Kante -
        // genau dort, wo das Steuerkreuz liegt. Die Klasse verschiebt es
        // (assets/css/call.css); auf schmalen Schirmen deckt das Blatt die
        // untere Haelfte ab, dort weicht das Steuerkreuz ganz.
        if (blatt === chatOverlay) {
            document.getElementById('call-view')?.classList.toggle('chat-open', !!offen);
        }
    }

    chatToggle?.addEventListener('click', function() {
        const offen = chatOverlay && chatOverlay.hidden;
        setSheet(deviceSheet, deviceToggle, false);
        setSheet(chatOverlay, chatToggle, !!offen);
        if (offen) document.getElementById('chat-input')?.focus();
    });

    deviceToggle?.addEventListener('click', function() {
        const offen = deviceSheet && deviceSheet.hidden;
        setSheet(chatOverlay, chatToggle, false);
        setSheet(deviceSheet, deviceToggle, !!offen);
    });

    document.getElementById('chat-close')?.addEventListener('click', function() {
        setSheet(chatOverlay, chatToggle, false);
    });

    // Klick neben das Chatblatt schliesst es.
    chatOverlay?.addEventListener('click', function(e) {
        if (e.target === chatOverlay) setSheet(chatOverlay, chatToggle, false);
    });

};

// ---------- DOMContentLoaded: Initialisierung & Intervall-Tasks ----------
window.addEventListener('DOMContentLoaded', function() {
    window.webrtcApp.init();
    if (window.isLoggedIn) {
        // Takt kommt vom Server (config/presence.php), damit er nicht gegen
        // den Offline-Timeout des Cronjobs laeuft. Der Rueckfallwert greift
        // nur, wenn die Seite ohne die Servervariable ausgeliefert wurde.
        const heartbeatMs = window.heartbeatIntervalMs || 10000;

        // Sofort einmal melden: Sonst gilt der Nutzer nach dem Anmelden bis
        // zum ersten Intervall als das, was zuletzt in der Datenbank stand -
        // nach einem Logout also als offline.
        window.webrtcApp.signaling.sendHeartbeat(window.webrtcApp.state.isCallActive);
        setInterval(function() {
            window.webrtcApp.signaling.sendHeartbeat(window.webrtcApp.state.isCallActive);
        }, heartbeatMs);

        // Browser bremsen Timer in ausgeblendeten Tabs auf etwa einen Aufruf
        // pro Minute aus. Wer den Tab wieder nach vorn holt, soll nicht erst
        // den naechsten Takt abwarten muessen, um wieder als erreichbar zu
        // gelten.
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) return;
            window.webrtcApp.signaling.sendHeartbeat(window.webrtcApp.state.isCallActive);
        });
    }
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '1', 'Lokation erfolgreich gespeichert!');
    // success=0 wird ausschliesslich von der Beschreibungspruefung ausgeloest
    // (LocationController::setLocation, strlen < 5). Die Stadt wird dort gar
    // nicht geprueft - der alte Text nannte einen Grund, den es nicht gab.
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '0', 'Speichern nicht erfolgreich. Die Beschreibung muss mindestens 5 Zeichen lang sein.');
    // success=2: die Koordinaten fehlten oder lagen ausserhalb des gueltigen
    // Bereichs (siehe LocationController::setLocation).
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '2', 'Speichern nicht erfolgreich. Bitte den Standort auf der Karte auswaehlen.');
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '5', 'Registrierung erfolgreich!');
    window.webrtcApp.utils.showSuccessAlertIfNeeded('change', '1', 'Passwort erfolgreich geändert!');
    // Hier stand ein Aufruf von ui.expandPanelForWideTableIfNeeded(): Er hat
    // auf breiten Seiten dem Inhaltsbereich und allen Karten darin ihre
    // Bootstrap-Klassen wieder abgenommen, weil zwei weisse Kaesten
    // ineinander standen - der Rahmen aus index.html und die Karte der Seite.
    // Den aeusseren Kasten gibt es nicht mehr (assets/css/theme.css: der
    // Inhaltsbereich ist nur noch Breite und Abstand), also gibt es auch
    // nichts mehr zurueckzunehmen.
});
