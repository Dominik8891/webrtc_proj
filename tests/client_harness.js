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
    const attrs = {};
    return {
        id, style: {}, className: '', textContent: '', src: '', title: '',
        disabled: false, srcObject: null,
        classList: {
            add(...names) { names.forEach(n => classes.add(n)); },
            remove(...names) { names.forEach(n => classes.delete(n)); },
            contains(name) { return classes.has(name); }
        },
        children: [],
        appendChild(child) { this.children.push(child); },
        // innerHTML wird gelesen (ui.js prueft den erzeugten Knopf) UND
        // geschrieben. Auswahlfelder werden ueber innerHTML='' geleert
        // (media.fillSelects); dann fallen auch die mitgeschriebenen Kinder
        // weg, sonst waere nicht pruefbar, WELCHE Geraete zuletzt in der
        // Liste standen.
        __html: '',
        set innerHTML(w) {
            this.__html = (w === undefined || w === null) ? '' : String(w);
            if (this.__html === '') this.children.length = 0;
        },
        get innerHTML() { return this.__html; },
        setAttribute(n, w) { attrs[n] = String(w); },
        getAttribute(n) { return n in attrs ? attrs[n] : null; },
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
// navigator MUSS ueber defineProperty gesetzt werden: Node bringt seit
// Version 21 ein eigenes navigator mit, und das ist ein Getter ohne Setter.
// Eine einfache Zuweisung lief deshalb still ins Leere - navigator.userAgent
// blieb "Node.js/22", und jede Pruefung, die von einer Geraetekennung
// abhaengt (etwa das Neuladen nach dem Call), lief am eigentlichen Zweig
// vorbei.
Object.defineProperty(global, 'navigator', {
    value: { userAgent: 'Mozilla/5.0 (Windows NT 10.0)' },
    writable: true,
    configurable: true
});

// Medienzugriff. Die Attrappe schreibt mit, WELCHE Spuren angefordert wurden -
// genau daran haengt die Zusage, dass der Zuschauer beim Anrufen nicht mehr
// nach seiner Kamera gefragt wird. __mediaError erzwingt eine Ablehnung, wie
// sie ein Browser bei fehlendem oder gesperrtem Mikrofon liefert.
global.__mediaRequests = [];
global.__mediaError = null;
// Fehler NUR fuer eine Spurart. Damit ist pruefbar, dass eine abgelehnte
// Kamera das Gespraech nicht mitreisst und die Meldung das richtige Geraet
// nennt.
global.__mediaErrorFor = { audio: null, video: null };
let trackNr = 0;
function makeTrack(kind, deviceId) {
    const id = deviceId || (kind + '-standard');
    return {
        kind, id: 'track-' + (++trackNr), deviceId: id,
        readyState: 'live', stopped: false, muted: false,
        stop() { this.stopped = true; this.readyState = 'ended'; },
        getSettings() { return { deviceId: this.deviceId }; }
    };
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

// Der Browser-Konstruktor. rtc.attachRemoteTrack() fuehrt damit einen
// eigenen Strom, wenn das ontrack-Ereignis keinen mitbringt.
global.MediaStream = function (tracks) { return makeStream(tracks ? tracks.slice() : []); };
/** Die Geraetekennung aus einer Bedingung wie {deviceId:{exact:'cam-2'}}. */
function wunschGeraet(bedingung) {
    if (!bedingung || bedingung === true) return null;
    const d = bedingung.deviceId;
    if (!d) return null;
    return (typeof d === 'string') ? d : (d.exact || d.ideal || null);
}

// Welche Geraete es "gibt". Ein Wunsch nach einem unbekannten Geraet endet
// wie im Browser mit OverconstrainedError.
global.__devices = [
    { kind: 'videoinput', deviceId: 'cam-1', label: 'Frontkamera' },
    { kind: 'videoinput', deviceId: 'cam-2', label: 'Rueckkamera' },
    { kind: 'audioinput', deviceId: 'mic-1', label: 'Eingebautes Mikrofon' },
    { kind: 'audioinput', deviceId: 'mic-2', label: 'Headset' }
];

global.navigator.mediaDevices = {
    async getUserMedia(constraints) {
        global.__mediaRequests.push(constraints);
        if (global.__mediaError) throw global.__mediaError;

        const tracks = [];
        for (const kind of ['audio', 'video']) {
            const bedingung = constraints ? constraints[kind] : undefined;
            if (!bedingung) continue;
            if (global.__mediaErrorFor[kind]) throw global.__mediaErrorFor[kind];

            const wunsch = wunschGeraet(bedingung);
            const art = (kind === 'video') ? 'videoinput' : 'audioinput';
            if (wunsch && !global.__devices.some(d => d.kind === art && d.deviceId === wunsch)) {
                const e = new Error('Requested device not found');
                e.name = 'OverconstrainedError';
                throw e;
            }
            tracks.push(makeTrack(kind, wunsch));
        }
        return makeStream(tracks);
    },
    async enumerateDevices() { return global.__devices.slice(); },
    addEventListener() {}
};
global.alert = (msg) => { global.__alerts.push(msg); };
global.__alerts = [];
global.__signals = [];
// Rolle, die der Server auf ein Offer zurueckgibt: 'viewer', 'peer' oder null
// (keine Rolle im Feld). Die Tests setzen sie je Fall.
global.__offerRole = null;
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
    async setRemoteDescription(d) {
        this.remoteDescription = d;
        // Ein Angebot bringt seine Spurarten mit. Der Browser legt dafuer
        // Transceiver an - genau daran haengt, ob sich die Kamera spaeter
        // ohne Neuaushandlung zuschalten laesst.
        if (d && d.type === 'offer') this.__receiveOffer(this.__offerKinds || ['audio', 'video']);
    }
    async addIceCandidate() {}
    addTrack(track) {
        // replaceTrack pruegelt nicht: Eine Spur der falschen Art weist der
        // Browser mit einem TypeError ab. Genau darauf lief die alte
        // Sendersuche hinaus, wenn das Mikrofon stumm war.
        const sender = {
            track,
            replaceTrack: async (t) => {
                if (t && t.kind !== sender.__kind) throw new TypeError('Kind mismatch');
                sender.track = t;
            },
            // Der Browser merkt sich am Sender, zu welchem Strom seine Spur
            // gehoert - daraus entsteht die msid im SDP. replaceTrack allein
            // setzt das NICHT; dafuer gibt es setStreams.
            setStreams(...streams) { sender.__streams = streams[0] || null; },
            __streams: null,
            __kind: track.kind
        };
        this.senders.push(sender);
        this.transceivers.push({ sender, receiver: { track: { kind: track.kind } }, direction: 'sendrecv' });
        return sender;
    }
    addTransceiver(kind, init) {
        const sender = {
            track: null,
            replaceTrack: async (t) => {
                if (t && t.kind !== sender.__kind) throw new TypeError('Kind mismatch');
                sender.track = t;
            },
            setStreams(...streams) { sender.__streams = streams[0] || null; },
            __streams: null,
            __kind: kind
        };
        const tr = { sender, receiver: { track: { kind } }, direction: (init && init.direction) || 'sendrecv', kind };
        this.senders.push(sender);
        this.transceivers.push(tr);
        return tr;
    }
    /**
     * Baut nach, was setRemoteDescription(offer) mit einem Angebot macht:
     * Fuer jede angebotene Spurart entsteht ein Transceiver mit der Richtung
     * "recvonly", solange nichts eigenes daran haengt.
     * @param {string[]} kinds - Spurarten des Angebots
     */
    __receiveOffer(kinds) {
        kinds.forEach(kind => {
            if (this.transceivers.some(t => t.receiver.track.kind === kind)) return;
            const sender = {
                track: null,
                replaceTrack: async (t) => {
                    if (t && t.kind !== sender.__kind) throw new TypeError('Kind mismatch');
                    sender.track = t;
                },
                setStreams(...streams) { sender.__streams = streams[0] || null; },
                __streams: null,
                __kind: kind
            };
            this.senders.push(sender);
            this.transceivers.push({ sender, receiver: { track: { kind } }, direction: 'recvonly', kind });
        });
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
            const msg = JSON.parse(opts.body);
            global.__signals.push(msg);
            // Der Server haengt die Rolle an die Antwort auf das Offer
            // (WebRTCController::roleForCall). Welche das ist, entscheidet
            // in den Tests __offerRole - davon haengt ab, ob der Anrufer
            // ueberhaupt Medien holt.
            const antwort = { status: 'ok' };
            if (msg && msg.type === 'offer' && global.__offerRole) {
                antwort.role = global.__offerRole;
            }
            const roh = JSON.stringify(antwort);
            return { ok: true, text: async () => roh, json: async () => antwort };
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

for (const f of ['app.js', 'protocol.js', 'rtc.js', 'control.js', 'media.js', 'signaling.js', 'chat.js', 'ui.js']) {
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
    // Bis wann steht noch ein Hinweis. rtc.endCall() richtet das Neuladen der
    // Seite danach - siehe assets/js/notify.js.
    toastUntil: 0,
    pendingMs()   { const r = this.toastUntil - Date.now(); return r > 0 ? r : 0; },
    toast(text)   { global.__alerts.push(String(text)); this.toastUntil = Date.now() + 4500; },
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
