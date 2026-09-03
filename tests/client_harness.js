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
    // Angehaengte Kinder werden mitgeschrieben: Die Protokolltests pruefen,
    // dass eine verworfene Nachricht NICHT im Chatlog landet.
    const classes = new Set();
    return {
        id, style: {}, className: '', textContent: '', src: '', title: '',
        disabled: false,
        classList: {
            add(...names) { names.forEach(n => classes.add(n)); },
            remove(...names) { names.forEach(n => classes.delete(n)); },
            contains(name) { return classes.has(name); }
        },
        children: [],
        appendChild(child) { this.children.push(child); },
        scrollTop: 0, scrollHeight: 0,
        addEventListener() {}, value: '', checked: true
    };
}

const els = {};

/** Leert den mitgeschriebenen Chatlog zwischen zwei Pruefungen. */
global.__clearLogs = () => {
    if (els['chat-log']) els['chat-log'].children.length = 0;
};

/** Inhalt eines Logs als Textzeilen. */
global.__logLines = (id) =>
    (els[id] ? els[id].children : []).map(c => c.textContent);

global.__el = (id) => els[id];
global.document = {
    body: { classList: { add() {}, remove() {} } },
    // Das <html>-Element traegt das Farbprofil (data-theme). Mehr als
    // Attribute setzen und lesen braucht theme_switch.js davon nicht.
    documentElement: (() => {
        const attr = {};
        return {
            setAttribute(n, w) { attr[n] = String(w); },
            getAttribute(n)    { return n in attr ? attr[n] : null; }
        };
    })(),
    getElementById(id) {
        if (!(id in els)) els[id] = makeEl(id);
        return els[id];
    },
    addEventListener() {}, querySelectorAll() { return []; },
    createElement(tag) { return makeEl(tag); }
};
global.navigator = { userAgent: 'Mozilla/5.0 (Windows NT 10.0)' };

// Medienzugriff. Die Attrappe schreibt mit, WELCHE Spuren angefordert wurden -
// genau daran haengt die Zusage, dass der Zuschauer beim Anrufen nicht mehr
// nach seiner Kamera gefragt wird. __mediaError erzwingt eine Ablehnung, wie
// sie ein Browser bei fehlendem oder gesperrtem Mikrofon liefert.
global.__mediaRequests = [];
global.__mediaError = null;
function makeTrack(kind) {
    return { kind, readyState: 'live', stopped: false, stop() { this.stopped = true; } };
}
function makeStream(tracks) {
    return {
        tracks,
        getTracks()      { return this.tracks.slice(); },
        getAudioTracks() { return this.tracks.filter(t => t.kind === 'audio'); },
        getVideoTracks() { return this.tracks.filter(t => t.kind === 'video'); },
        addTrack(t)      { this.tracks.push(t); },
        removeTrack(t)   { this.tracks = this.tracks.filter(x => x !== t); }
    };
}
global.makeTrack  = makeTrack;
global.makeStream = makeStream;
global.navigator.mediaDevices = {
    async getUserMedia(constraints) {
        global.__mediaRequests.push(constraints);
        if (global.__mediaError) throw global.__mediaError;
        const tracks = [];
        if (constraints && constraints.audio) tracks.push(makeTrack('audio'));
        if (constraints && constraints.video) tracks.push(makeTrack('video'));
        return makeStream(tracks);
    }
};
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
        this.senders = [];
        this.transceivers = [];
        FakePeerConnection.last = this;
    }
    createDataChannel(label) { return makeChannel(label); }
    async createOffer(opts) {
        this.offersCreated++;
        if (opts && opts.iceRestart) this.iceRestarts++;
        return { type: 'offer', sdp: 'sdp-offer-' + this.offersCreated };
    }
    async createAnswer() { return { type: 'answer', sdp: 'sdp-answer' }; }
    async setLocalDescription(d) { this.localDescription = d; }
    async setRemoteDescription(d) { this.remoteDescription = d; }
    async addIceCandidate() {}
    addTrack(track) {
        const sender = { track, replaceTrack: async (t) => { sender.track = t; } };
        this.senders.push(sender);
        this.transceivers.push({ sender, receiver: { track: { kind: track.kind } } });
        return sender;
    }
    addTransceiver(kind, init) {
        const sender = { track: null, replaceTrack: async (t) => { sender.track = t; } };
        const tr = { sender, receiver: { track: { kind } }, direction: (init && init.direction) || 'sendrecv', kind };
        this.senders.push(sender);
        this.transceivers.push(tr);
        return tr;
    }
    getTransceivers() { return this.transceivers.slice(); }
    getSenders() { return this.senders.slice(); }
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

function makeChannel(label) {
    return {
        label: label || 'chat',
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

global.Blob = global.Blob || function () {};
global.URL = global.URL || { createObjectURL: () => 'blob:x' };

for (const f of ['app.js', 'protocol.js', 'rtc.js', 'control.js', 'signaling.js', 'chat.js', 'ui.js']) {
    eval(fs.readFileSync(path.join(ROOT, f), 'utf8'));
}

// locations_table.js baut die Zellen der Standortlisten. Geprueft werden hier
// nur die reinen Baumethoden (actionCellHtml und was daran haengt) - die
// brauchen kein DOM. Die Datei setzt am Ende aber einen $(document).ready-
// Block ab, deshalb steht hier gerade so viel jQuery, dass sie durchlaeuft.
// Absichtlich karg: Was mehr braucht als das, gehoert nicht in diese Tests.
{
    const leer = {
        length: 0,
        ready(fn) { return this; },
        on() { return this; }, off() { return this; },
        show() { return this; }, hide() { return this; }, toggle() { return this; },
        find() { return this; }, html() { return this; }, text() { return ''; },
        each() { return this; }, data() { return undefined; }, attr() { return undefined; }
    };
    const $ = () => leer;
    $.fn = { DataTable: { isDataTable: () => false } };
    $.ajax = () => ({ done: () => ({ fail: () => {} }) });
    $.extend = Object.assign;
    global.$ = global.jQuery = $;
    eval(fs.readFileSync(path.join(ROOT, 'locations_table.js'), 'utf8'));

    // Das Umschalten des Farbprofils. Braucht ein <html>-Element mit
    // Attributen und einen Bereich mit den Radioknoepfen - beides steht hier
    // gerade so weit nachgebaut, wie die Pruefungen es anfassen.
    global.__themeAjax = [];
    $.ajax = (opt) => {
        global.__themeAjax.push(opt);
        const kette = {
            done(fn) { kette.__done = fn; return kette; },
            fail(fn) { kette.__fail = fn; return kette; }
        };
        global.__letzteKette = kette;
        return kette;
    };
    eval(fs.readFileSync(path.join(ROOT, 'theme_switch.js'), 'utf8'));

    // map.js: geprueft wird nur die Flaggenbildung, die kein DOM braucht.
    // Die Datei ruft beim Laden nichts auf - $(document).ready reicht als
    // Attrappe oben aus.
    global.localStorage = {
        __daten: {},
        getItem(k) { return k in this.__daten ? this.__daten[k] : null; },
        setItem(k, w) { this.__daten[k] = String(w); }
    };
    eval(fs.readFileSync(path.join(ROOT, 'map.js'), 'utf8'));
}

// Module, die von rtc.js benutzt werden, aber hier nicht geladen sind
// Abgespielte Signaltoene mitschreiben: Die Protokolltests pruefen, dass ein
// ausgefuehrter Bewegungsbefehl beim Guide hoerbar wird.
window.webrtcApp.sound = { plays: [], play(id) { this.plays.push(id); }, stop() {} };

// Meldungen laufen nicht mehr ueber alert(), sondern ueber webrtcApp.notify
// (assets/js/notify.js). Das echte Modul baut Elemente im Dokument auf; hier
// steht eine Attrappe, die die Texte in dieselbe Liste schreibt, die die
// Pruefungen schon vorher gelesen haben. So bleibt die Aussage "genau EINE
// Meldung" pruefbar, ohne dass ein DOM nachgebaut werden muss.
//
// Wer die Schnittstelle von notify erweitert, ergaenzt sie hier mit.
window.webrtcApp.notify = {
    toast(text)   { global.__alerts.push(String(text)); },
    info(text)    { this.toast(text); },
    success(text) { this.toast(text); },
    error(text)   { this.toast(text); },
    alert(opt)    { this.toast(typeof opt === 'string' ? opt : (opt && opt.text)); return Promise.resolve(); },
    confirm()     { return Promise.resolve(true); },
    prompt()      { return Promise.resolve(''); }
};
window.webrtcApp.uiRtc = { setEndCallButtonVisible() {}, getUsername: async () => 'Partner' };
window.webrtcApp.uiChat = { updatePollingState() {} };

module.exports = { app: window.webrtcApp, els, FakePeerConnection, makeChannel };
