window.activeTargetUserId = null;
window.hangupReceived = false; // Verhindert doppelte Hangup-Meldung


window.handleHangupSource = function(source) {
    if (window.hangupReceived) return; // Meldung nur einmal anzeigen
    window.hangupReceived = true;
    var dialog = document.getElementById('media-select-dialog');
        if (dialog) {
            dialog.style.display = 'none';
        }
    window.stopSound("incomming_call_ringtone");
    endCall(false); // Beende lokal, aber kein neues Signal senden
    alert("Der andere Teilnehmer hat das Gespräch beendet." + (source ? " (" + source + ")" : ""));
};


window.addEventListener('DOMContentLoaded', function() {
    
    document.querySelectorAll('.start-call-btn').forEach(button => {
        let btn_id = button.getAttribute('id').replace('start-call-btn-', '');
        button.addEventListener("click", function() {
            console.log("Anruf mit User-ID:", btn_id);
            // Ziel-User ggf. speichern, wenn für die Signalisierung nötig
            window.dumpWebRTCState("Vor neuem Call");
            startCall(btn_id);
        });
    });
});

window.addEventListener('DOMContentLoaded', function() {
    window.dumpWebRTCState("App Start");
    setEndCallButtonVisible(false);
    if(window.isLoggedIn) {
        pollSignaling();
        console.log('test');
    }
    ringtone = 'incomming_call_ringtone';
    

    // 1. Anruf-Annehmen-Button zeigt nur noch Dialog an
    var acceptBtn = document.getElementById('accept-call-btn');
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function() {
            var dialog = document.getElementById('media-select-dialog');
            if (dialog) {
                dialog.style.display = '';
            }
        });
    }

    // 2. Medien-Dialog: Annehmen
    document.getElementById('media-accept-btn').addEventListener('click', function() {
        window.stopSound(ringtone);
        var dialog = document.getElementById('media-select-dialog');
        if (dialog) {
            dialog.style.display = 'none';
        }
        setEndCallButtonVisible(true);
        window.isCallActive = true;
        const data = window.pendingOffer;
        //document.getElementById('accept-call-btn').style.display = "none"; // Button ausblenden
        document.body.classList.add('call-active');
        document.getElementById('call-view').style.display = '';
        // Auswahl auswerten
        const useVideo = document.getElementById('media-video-checkbox').checked;
        const useAudio = document.getElementById('media-audio-checkbox').checked;
        const constraints = {};
        const videoDeviceId = document.getElementById('camera-select').value;
        const audioDeviceId = document.getElementById('mic-select').value;

        if (useVideo) {
            constraints.video = videoDeviceId ? { deviceId: { exact: videoDeviceId } } : true;
        }
        if (useAudio) {
            constraints.audio = audioDeviceId ? { deviceId: { exact: audioDeviceId } } : true;
        }

        let promise;
        if (constraints.video || constraints.audio) {
            promise = navigator.mediaDevices.getUserMedia(constraints)
                .then(stream => {
                    window.localStream = stream;
                    document.getElementById('local-video').srcObject = stream;
                    window.createPeerConnection(false);
                    window.addLocalTracks();
                });
        } else {
            window.localStream = null;
            window.createPeerConnection(false);
            promise = Promise.resolve();
        }

        promise.then(() => {
            return window.localPeerConnection.setRemoteDescription(new RTCSessionDescription({
                type: data.type,
                sdp: data.sdp
            }));
        })
        .then(() => {
            return window.localPeerConnection.createAnswer();
        })
        .then(answer => {
            return window.localPeerConnection.setLocalDescription(answer).then(() => answer);
        })
        .then(answer => {
            sendSignalMessage({
                type: 'answer',
                sdp: answer.sdp,
                target: data.sender_id
            });
        })
        .catch(e => {
            alert("Fehler beim Verarbeiten des Angebots: " + e.message);
        });
    });

    // 3. Medien-Dialog: Ablehnen
    document.getElementById('media-decline-btn').addEventListener('click', function() {
        console.log("Button: media-decline-btn gedrückt");
        var dialog = document.getElementById('media-select-dialog');
        if (dialog) {
            dialog.style.display = 'none';
        }
        var btn = document.getElementById('accept-call-btn');
        if (btn) btn.style.display = "none";
        setEndCallButtonVisible(false);
        endCall(true); // Beim Klick wird hangup gesendet
        window.dumpWebRTCState("Nach ablehnen des Selfcalls aber ohne endcall funktion");
        window.stopSound(ringtone);
    });

    // Dein bestehender End-Call-Button bleibt wie gehabt
    var endBtn = document.getElementById('end-call-btn');
    console.log(endBtn);
    if (endBtn) {
        endBtn.addEventListener('click', function() {
            console.log("Button: end-call-btn gedrückt");
            console.log(window.activeTargetUserId);
            endCall(true); // Beim Klick wird hangup gesendet
        });
    }
    
});


window.addEventListener('DOMContentLoaded', function() {
    const chatBtn = document.getElementById('chat-send-btn');
    const chatInput = document.getElementById('chat-input');
    if (chatBtn && chatInput) {
        chatBtn.addEventListener('click', function() {
            if (window.dataChannel && window.dataChannel.readyState === "open") {
                window.dataChannel.send(chatInput.value);
                appendChatMsg("me", chatInput.value);
                chatInput.value = "";
            }
        });
    }
});

function setEndCallButtonVisible(visible) {
    var btn = document.getElementById('end-call-btn');
    if (btn) btn.style.display = visible ? '' : 'none';
}

window.appendChatMsg = function(who, msg) {
    const log = document.getElementById("chat-log");
    const div = document.createElement("div");
    div.textContent = (who === "remote" ? "Partner: " : "Du: ") + msg;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
};

window.sendChatMsg = function(msg) {
    if (window.dataChannel && window.dataChannel.readyState === "open") {
        window.dataChannel.send(msg);
        window.appendChatMsg("self", msg);
    }
};

window.sendFile = function(file) {
    if (window.dataChannel && window.dataChannel.readyState === "open") {
        // Dateien klein halten, sonst muss in Blöcke gesplittet werden!
        file.arrayBuffer().then(buffer => {
            window.dataChannel.send(buffer);
            window.appendChatMsg("self", "Datei gesendet: " + file.name);
        });
    }
};

window.addEventListener('DOMContentLoaded', function() {
    // Chat-Nachricht senden
    var sendBtn = document.getElementById("chat-send-btn");
    var chatInput = document.getElementById("chat-input");
    if (sendBtn && chatInput) {
        sendBtn.addEventListener('click', function() {
            const msg = chatInput.value;
            if (msg) {
                window.sendChatMsg(msg);
                chatInput.value = "";
            }
        });
        // Enter-Taste für Senden
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === "Enter") {
                sendBtn.click();
            }
        });
    }

    // Datei senden
    var fileInput = document.getElementById("file-input");
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            if (e.target.files.length) window.sendFile(e.target.files[0]);
        });
    }
});

window.addEventListener('beforeunload', function() {
    if (typeof endCall === 'function') {
        window.endCall(false);
    }
});

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('btn-forward').addEventListener('click', function() {
        console.log("Button: Forward");
        window.sendChatMsg("__arrow_forward__");
    });
    document.getElementById('btn-backward').addEventListener('click', function() {
        console.log("Button: Backward");
        window.sendChatMsg("__arrow_backward__");
    });
    document.getElementById('btn-left').addEventListener('click', function() {
        console.log("Button: Left");
        window.sendChatMsg("__arrow_left__");
    });
    document.getElementById('btn-right').addEventListener('click', function() {
        console.log("Button: Right");
        window.sendChatMsg("__arrow_right__");
    });
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


window.addEventListener('DOMContentLoaded', function() {
    var locationButtonDiv = document.getElementById('location-button');
    // Erst alles leeren, falls das Element aus Versehen irgendwo vorkommt
    locationButtonDiv.innerHTML = '';

    if (window.isLoggedIn && window.userRole) {
        let text = '';
        if (window.userRole === 'admin' || window.userRole === 'guide') {
            text = 'Neue Lokation hinzufügen';
        } else if (window.userRole === 'tourist') {
            text = 'Jetzt Tour-Guide werden!';
        }
        if (text) {
            locationButtonDiv.innerHTML = `<a href="index.php?act=set_location_page">${text}</a>`;
            locationButtonDiv.style.display = '';
        } else {
            locationButtonDiv.style.display = 'none';
        }
    } else {
        locationButtonDiv.style.display = 'none';
    }
});
