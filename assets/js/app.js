// assets/js/app.js
window.webrtcApp = {
    state: {
        activeTargetUserId: null,
        hangupReceived: false,
        isCallActive: false,
        pendingOffer: null,
        tracksAdded: false
    },
    refs: {
        localPeerConnection: null,
        dataChannel: null,
        localStream: null,
        pollingIntervalId: null,
        meteredIceServers: null,
        iceServersLoaded: false,
        pendingCandidates: []
    },
    rtc: {},
    ui: {},
    sound: {},
    chat: {},
    signaling: {},
    utils: {}
};
