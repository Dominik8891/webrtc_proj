/**
 * Stub-Umgebung, um die WebRTC-Client-Logik ohne Browser zu prüfen.
 *
 * Ersetzt document, fetch, alert und RTCPeerConnection durch Attrappen und
 * lädt danach die echten Dateien aus assets/js. Getestet wird also der
 * produktive Code, nicht eine Nachbildung davon.
 *
 * Wird von client_test.js benutzt und ist allein nicht ausführbar.
 * Siehe tests/README.md.
 */
const fs = require('fs');
const path = require('path');
const ROOT = path.join(__dirname, '..', 'assets', 'js');

function makeEl(id) {
    return {
        id, style: {}, className: '', textContent: '', src: '', title: '',
        classList: { add() {}, remove() {}, contains() { return false; } },
        appendChild() {}, scrollTop: 0, scrollHeight: 0,
        addEventListener() {}, value: '', checked: true
    };
}

const els = {};
global.document = {
    body: { classList: { add() {}, remove() {} } },
    getElementById(id) {
        if (!(id in els)) els[id] = makeEl(id);
        return els[id];
    },
    addEventListener() {}, querySelectorAll() { return []; },
    createElement(tag) { return makeEl(tag); }
};
global.navigator = { userAgent: 'Mozilla/5.0 (Windows NT 10.0)' };
global.alert = (msg) => { global.__alerts.push(msg); };
global.__alerts = [];
global.__signals = [];
global.console.log = () => {};
global.console.warn = () => {};

global.RTCSessionDescription = function (init) { return init; };
global.RTCIceCandidate = function (init) { return init; };

class FakePeerConnection {
    constructor(config) {
        this.config = config;
        this.connectionState = 'new';
        this.iceConnectionState = 'new';
        this.signalingState = 'stable';
        this.localDescription = null;
        this.remoteDescription = null;
        this.closed = false;
        this.offersCreated = 0;
        this.iceRestarts = 0;
        FakePeerConnection.last = this;
    }
    createDataChannel() { return makeChannel(); }
    async createOffer(opts) {
        this.offersCreated++;
        if (opts && opts.iceRestart) this.iceRestarts++;
        return { type: 'offer', sdp: 'sdp-offer-' + this.offersCreated };
    }
    async createAnswer() { return { type: 'answer', sdp: 'sdp-answer' }; }
    async setLocalDescription(d) { this.localDescription = d; }
    async setRemoteDescription(d) { this.remoteDescription = d; }
    async addIceCandidate() {}
    getSenders() { return []; }
    close() { this.closed = true; this.connectionState = 'closed'; }
    // Zustandswechsel simulieren
    setState(cs, ics) {
        this.connectionState = cs;
        this.iceConnectionState = ics === undefined ? cs : ics;
        if (this.onconnectionstatechange) this.onconnectionstatechange();
        if (this.oniceconnectionstatechange) this.oniceconnectionstatechange();
    }
}
global.RTCPeerConnection = FakePeerConnection;
global.FakePeerConnection = FakePeerConnection;

function makeChannel() {
    return {
        readyState: 'open', bufferedAmount: 0, sent: [],
        send(d) { this.sent.push(d); },
        close() { this.readyState = 'closed'; }
    };
}
global.makeChannel = makeChannel;

global.fetch = async (url, opts) => {
    if (String(url).includes('getSignal')) {
        if (opts && opts.method === 'POST') {
            global.__signals.push(JSON.parse(opts.body));
            return { ok: true, text: async () => '{"status":"ok"}', json: async () => ({ status: 'ok' }) };
        }
        return { ok: true, json: async () => [] };
    }
    if (String(url).includes('get_turn_credentials')) {
        return global.__turnResponse();
    }
    return { ok: true, text: async () => '', json: async () => ({}) };
};
global.__turnResponse = async () => ({
    ok: true,
    json: async () => ({
        iceServers: [
            { urls: 'stun:stun.metered.ca:80' },
            { urls: 'turn:turn.metered.ca:80', username: 'u', credential: 'c' },
            { urls: 'turns:turn.metered.ca:443', username: 'u', credential: 'c' }
        ],
        turnAvailable: true
    })
});

global.window = global;
global.updateCallIcons = () => {};
global.isLoggedIn = true;

for (const f of ['app.js', 'rtc.js', 'signaling.js', 'chat.js']) {
    eval(fs.readFileSync(path.join(ROOT, f), 'utf8'));
}

// Module, die von rtc.js benutzt werden, aber hier nicht geladen sind
window.webrtcApp.sound = { play() {}, stop() {} };
window.webrtcApp.uiRtc = { setEndCallButtonVisible() {}, getUsername: async () => 'Partner' };
window.webrtcApp.uiChat = { updatePollingState() {} };

module.exports = { app: window.webrtcApp, els, FakePeerConnection, makeChannel };
