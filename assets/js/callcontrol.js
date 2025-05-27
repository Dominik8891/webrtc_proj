window.isCallActive = false;

 window.endCall = function(sendSignal = true) {
    document.body.classList.remove('call-active');
    document.getElementById('call-view').style.display = 'none';

    // PeerConnection beenden
    if (window.localPeerConnection) {
        window.localPeerConnection.close();
        window.localPeerConnection = null;
    }

    // DataChannel schließen & auf null setzen
    if (window.dataChannel) {
        try { window.dataChannel.close(); } catch (e) {}
        window.dataChannel = null;
    }

    if (window.localStream) {
        window.localStream.getTracks().forEach(track => {
            try {
                track.stop();
                console.log("Track gestoppt:", track.kind, track.readyState);
            } catch(e) {
                console.warn('Track konnte nicht gestoppt werden:', e);
            }
        });
        window.localStream = null;
    }


    // Videos zurücksetzen
    const localVideo = document.getElementById('local-video');
    if (localVideo) localVideo.srcObject = null;
    const remoteVideo = document.getElementById('remote-video');
    if (remoteVideo) remoteVideo.srcObject = null;

    // Nur eine Hangup-Benachrichtigung senden
    if (!window.hangupReceived) {
        window.hangupReceived = true;
        if (sendSignal && window.activeTargetUserId) {
            sendSignalMessage({ type: 'hangup', target: window.activeTargetUserId });
            console.log("hangup");
        }
    }

    // State-Flags zurücksetzen
    window.tracksAdded = false;
    window.activeTargetUserId = null;
    window.hangupReceived = false;  // optional, je nach Flow
    window.pendingOffer = null;

    setEndCallButtonVisible(false);

    window.isCallActive = false;

    // UI zurücksetzen (z. B. Chat ausblenden)
    const chatArea = document.getElementById('chat-area');
    if (chatArea) chatArea.style.display = 'none';
    const arrowControl = document.getElementById('arrow-control');
    if (arrowControl) arrowControl.style.display = 'none';

    if (window.localStream) {
        window.localStream.getTracks().forEach(track => {
            try { 
                track.stop(); 
            } catch(e) {}
        });
        window.localStream = null;
    }

    // DEBUG: Teste, ob nach kurzem Delay alles wirklich weg ist
    setTimeout(() => {
        if (window.localStream) {
            window.localStream.getTracks().forEach(track => {
                try { 
                    track.stop(); 
                } catch(e) {}
            });
            window.localStream = null;
        }
        if (localVideo) localVideo.srcObject = null;
        if (remoteVideo) remoteVideo.srcObject = null;
    }, 2000); // 2 Sekunden später nochmal

    if (/Android|iPhone|iPad|iPod|Mobile|Linux/i.test(navigator.userAgent)) {
        setTimeout(function() {
            location.reload();
        }, 1000);
    }
}
