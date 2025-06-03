
window.webrtcApp.rtc.startCall = async function(targetUserId) {
    await window.webrtcApp.rtc.initFakeSelfCall();
    window.webrtcApp.state.activeTargetUserId = targetUserId;
    navigator.mediaDevices.getUserMedia({ video: true, audio: true })
        .then(stream => {
            window.webrtcApp.refs.localStream = stream;
            document.getElementById('local-video').srcObject = stream;
            window.webrtcApp.rtc.createPeerConnection(true);
            window.webrtcApp.sound.play('call_ringtone');
            return new Promise(resolve => setTimeout(resolve, 100));
        })
        .then(() => {
            window.webrtcApp.rtc.addLocalTracks();
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
    window.webrtcApp.ui.setEndCallButtonVisible(true);
    window.webrtcApp.state.isCallActive = true;
    document.body.classList.add('call-active');
    document.getElementById('call-view').style.display = '';
};




