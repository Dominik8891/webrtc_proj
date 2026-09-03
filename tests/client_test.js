/**
 * Prüft die Verbindungsstabilität des WebRTC-Clients:
 * Wiederverbindung, Auflegen, Steuerbefehle und ICE-Konfiguration.
 *
 * Ausführen:  node tests/client_test.js
 * Siehe tests/README.md.
 */
const assert = require('assert');
const { app, FakePeerConnection, makeChannel } = require('./client_harness');

const sleep = (ms) => new Promise(r => setTimeout(r, ms));
let passed = 0;
function ok(name) { console.error('  ok  ' + name); passed++; }

// Testtempo: Fristen kurz setzen, sonst dauert der Lauf eine Minute.
app.rtc.RECONNECT_GRACE_MS = 200;
app.rtc.RECONNECT_DEADLINE_MS = 1200;
app.rtc.RESTART_RETRY_MS = 400;
app.rtc.CONTROL_SETTLE_MS = 50;
app.control.ACK_TIMEOUT_MS = 150;

const P = app.protocol;

function resetAll() {
    app.rtc.stopReconnect();
    app.control.reset();
    app.signaling.stopPolling();
    Object.assign(app.state, {
        activeTargetUserId: null, hangupReceived: false, isCallActive: false,
        pendingOffer: null, isInitiator: false,
        connectionStatus: 'idle', connectedSince: null, callTimeout: null
    });
    app.refs.localPeerConnection = null;
    app.refs.chatChannel = null;
    app.refs.controlChannel = null;
    app.refs.localStream = null;
    app.refs.remoteStream = null;
    app.refs.pendingCandidates = [];
    global.__offerRole = null;
    global.__mediaRequests.length = 0;
    app.sound.plays.length = 0;
    global.__signals.length = 0;
    global.__alerts.length = 0;
    global.__clearLogs();
}

/**
 * Baut einen "laufenden Call" auf, ohne die Media-Pfade zu benötigen.
 * @param {Object} [opts]
 * @param {boolean} [opts.initiator] - Anrufer (true) oder Angerufener
 * @param {string|null} [opts.role]  - Rolle, die der Server vergeben haette
 */
function fakeActiveCall({ initiator = true, role = 'viewer' } = {}) {
    resetAll();
    app.refs.iceServersLoaded = true;
    app.refs.meteredIceServers = [{ urls: 'stun:x' }];
    app.state.activeTargetUserId = 42;
    app.state.isInitiator = initiator;
    app.state.isCallActive = true;
    app.rtc.createPeerConnection(initiator);
    // Der Angerufene bekommt die Kanaele sonst erst ueber ondatachannel.
    if (!app.refs.chatChannel) app.rtc.attachChannel(makeChannel(P.CHANNEL_CHAT));
    if (!app.refs.controlChannel) app.rtc.attachChannel(makeChannel(P.CHANNEL_CONTROL));
    app.control.applyRole(role);
    const pc = app.refs.localPeerConnection;
    pc.setState('connected');
    return pc;
}

/** Was auf dem Steuerkanal rausging, als geparste Objekte. */
function controlSent() {
    return app.refs.controlChannel.sent.map(JSON.parse);
}

/** Spielt eine Nachricht der Gegenseite in den Steuerkanal ein. */
function receiveControl(obj) {
    app.control.handleMessage(typeof obj === 'string' ? obj : JSON.stringify(obj));
}

/** Spielt einen Rohframe (auch nicht-Text) in den Steuerkanal ein. */
function receiveControl_raw(raw) {
    app.control.handleMessage(raw);
}

/** Bestaetigt beim Zuschauer den zuletzt gesendeten Bewegungsbefehl. */
function ackLastMove(status = 'executed', reason) {
    const seq = app.state.control.pendingSeq;
    receiveControl({ v: P.VERSION, type: 'ack', seq, status, reason });
}

(async () => {
    // -----------------------------------------------------------------
    console.error('\n1) ICE-Restart statt sofortigem Abbruch bei "disconnected"');
    {
        const pc = fakeActiveCall();
        assert.strictEqual(app.state.connectionStatus, 'connected');

        pc.setState('disconnected');
        assert.strictEqual(app.state.connectionStatus, 'unstable', 'sofort instabil, kein Abbruch');
        assert.strictEqual(app.state.isCallActive, true, 'Call laeuft weiter');
        assert.strictEqual(global.__alerts.length, 0, 'kein blockierender Dialog');
        ok('"disconnected" beendet den Call nicht sofort');

        // Erholt sich die Verbindung innerhalb der Frist, passiert nichts weiter.
        await sleep(80);
        pc.setState('connected');
        assert.strictEqual(app.state.connectionStatus, 'connected');
        assert.strictEqual(pc.iceRestarts, 0, 'kein unnoetiger Restart');
        ok('Erholung innerhalb der Frist loest keinen Restart aus');
    }

    // -----------------------------------------------------------------
    console.error('\n2) Nach Ablauf der Frist wird ICE neu ausgehandelt');
    {
        const pc = fakeActiveCall();
        pc.setState('disconnected');
        await sleep(300);
        assert.strictEqual(app.state.connectionStatus, 'reconnecting');
        assert.strictEqual(pc.iceRestarts, 1, 'genau ein ICE-Restart');
        const offer = global.__signals.find(s => s.type === 'restart_offer');
        assert.ok(offer && offer.target === 42, 'restart_offer verschickt');
        assert.strictEqual(app.state.isCallActive, true);
        ok('ICE-Restart nach 5-Sekunden-Frist (hier verkuerzt)');

        // Antwort der Gegenseite bringt die Verbindung zurueck.
        await app.rtc.handleRestartAnswer({ sdp: 'sdp-answer' });
        pc.setState('connected');
        assert.strictEqual(app.state.connectionStatus, 'connected');
        assert.strictEqual(app.state.reconnect.attempts, 0, 'Zaehler zurueckgesetzt');
        ok('Wiederverbindung stellt den Status wieder her');
    }

    // -----------------------------------------------------------------
    console.error('\n3) "failed" handelt sofort neu aus, ohne Wartefrist');
    {
        const pc = fakeActiveCall();
        pc.setState('failed');
        await sleep(20);
        assert.strictEqual(pc.iceRestarts, 1, 'Restart ohne Wartezeit');
        assert.strictEqual(app.state.connectionStatus, 'reconnecting');
        ok('"failed" loest den Restart sofort aus');
    }

    // -----------------------------------------------------------------
    console.error('\n4) Endgueltiges Beenden erst nach der Gesamtfrist');
    {
        const pc = fakeActiveCall();
        pc.setState('failed');
        await sleep(1500);
        assert.strictEqual(app.state.isCallActive, false, 'Call beendet');
        assert.strictEqual(global.__alerts.length, 1, 'genau EINE Meldung (frueher zwei)');
        assert.ok(/nicht wiederhergestellt/.test(global.__alerts[0]), global.__alerts[0]);
        const hangups = global.__signals.filter(s => s.type === 'hangup');
        assert.strictEqual(hangups.length, 1, 'Partner wird ueber das Signaling informiert');
        ok('Aufgeben erst nach Gesamtfrist, mit genau einer Meldung');
    }

    // -----------------------------------------------------------------
    console.error('\n5) Nicht-Initiator handelt nicht selbst aus (kein Glare)');
    {
        const pc = fakeActiveCall({ initiator: false });
        pc.setState('disconnected');
        await sleep(300);
        assert.strictEqual(pc.iceRestarts, 0, 'kein eigener Offer');
        const req = global.__signals.find(s => s.type === 'restart_request');
        assert.ok(req && req.target === 42, 'stattdessen restart_request');
        ok('Angerufener bittet nur um den Restart');

        // Und der Initiator reagiert auf so eine Anfrage.
        const pc2 = fakeActiveCall({ initiator: true });
        app.rtc.handleRestartRequest({ sender_id: 42 });
        await sleep(20);
        assert.strictEqual(pc2.iceRestarts, 1);
        ok('Initiator fuehrt den angeforderten Restart aus');
    }

    // -----------------------------------------------------------------
    console.error('\n6) restart_offer oeffnet keinen Anruf-Dialog');
    {
        const pc = fakeActiveCall({ initiator: false });
        global.__signals.length = 0;
        await app.signaling.handleSignalingData({ type: 'restart_offer', sdp: 'x', sender_id: 42 });
        assert.strictEqual(app.state.pendingOffer, null, 'kein pendingOffer gesetzt');
        const ans = global.__signals.find(s => s.type === 'restart_answer');
        assert.ok(ans, 'restart_answer verschickt');
        assert.strictEqual(app.state.connectionStatus, 'reconnecting');
        ok('Neuaushandlung wird nicht als neuer Anruf missdeutet');
    }

    // -----------------------------------------------------------------
    console.error('\n7) Auflegen erreicht den Gegenueber auf beiden Wegen');
    {
        const pc = fakeActiveCall();
        const dc = app.refs.controlChannel;
        app.rtc.endCall(true);
        const hangup = dc.sent.map(JSON.parse).find(m => m.type === 'hangup');
        assert.ok(hangup, 'ueber den Steuerkanal');
        assert.strictEqual(hangup.v, P.VERSION, 'mit Protokollversion');
        assert.strictEqual(global.__signals.filter(s => s.type === 'hangup').length, 1,
            'und als Server-Fallback');
        assert.strictEqual(app.state.isCallActive, false);
        ok('Auflegen geht ueber DataChannel UND Signaling raus');
    }

    // -----------------------------------------------------------------
    console.error('\n8) Doppelt zugestelltes Auflegen meldet nur einmal');
    {
        fakeActiveCall();
        app.rtc.handleRemoteHangup();              // Weg 1: DataChannel
        assert.strictEqual(global.__alerts.length, 1);
        assert.strictEqual(app.state.isCallActive, false);
        app.rtc.handleRemoteHangup(42);            // Weg 2: Server, kommt spaeter
        assert.strictEqual(global.__alerts.length, 1, 'keine zweite Meldung');
        ok('Auflegen wird genau einmal ausgewertet');
    }

    // -----------------------------------------------------------------
    console.error('\n9) Auflegen vor der Annahme wirft keinen Fehler (F-11)');
    {
        resetAll();
        app.state.pendingOffer = null;
        // Frueher: TypeError beim Zugriff auf pendingOffer.sender_id
        await app.signaling.handleSignalingData({ type: 'hangup', sender_id: 7 });
        ok('hangup ohne pendingOffer laeuft durch');

        app.state.pendingOffer = { sender_id: 7 };
        await app.signaling.handleSignalingData({ type: 'hangup', sender_id: 7 });
        assert.strictEqual(app.state.pendingOffer, null, 'Anruf-Dialog abgeraeumt');
        ok('hangup mit passendem pendingOffer schliesst den Dialog');
    }

    // -----------------------------------------------------------------
    console.error('\n10) Steuerbefehle werden bei Stoerung verworfen, nicht gepuffert');
    {
        const pc = fakeActiveCall({ role: 'viewer' });
        await sleep(60);                            // Settle-Fenster abwarten
        const moves = () => controlSent().filter(m => m.type === 'move');

        assert.strictEqual(app.control.sendMove('forward'), true);
        assert.deepStrictEqual(moves().map(m => m.dir), ['forward']);
        assert.strictEqual(moves()[0].seq, 1, 'Sequenznummer beginnt bei 1');
        ackLastMove();
        ok('bei stabiler Verbindung wird gesendet');

        pc.setState('disconnected');
        assert.strictEqual(app.control.sendMove('left'), false);
        assert.strictEqual(app.control.sendMove('right'), false);
        assert.deepStrictEqual(moves().map(m => m.dir), ['forward'], 'nichts nachgeschoben');
        ok('waehrend der Stoerung wird verworfen statt gepuffert');

        pc.setState('connected');
        await sleep(60);
        assert.strictEqual(app.control.sendMove('backward'), true);
        assert.deepStrictEqual(moves().map(m => m.dir), ['forward', 'backward'],
            'kein Schwall alter Befehle nach dem Reconnect');
        assert.deepStrictEqual(moves().map(m => m.seq), [1, 2], 'Sequenznummern laufen aufwaerts');
        ok('nach der Erholung nur der neue Befehl');
    }

    // -----------------------------------------------------------------
    console.error('\n11) Voller Sendepuffer blockiert Steuerbefehle');
    {
        const pc = fakeActiveCall({ role: 'viewer' });
        await sleep(60);
        app.refs.controlChannel.bufferedAmount = app.rtc.CONTROL_MAX_BUFFER + 1;
        assert.strictEqual(app.control.sendMove('forward'), false);
        ok('gestauter Kanal nimmt keine Steuerbefehle an');
    }

    // -----------------------------------------------------------------
    console.error('\n12) Empfangene Steuerbefehle aus der Stoerung werden verworfen');
    {
        const pc = fakeActiveCall();
        await sleep(60);
        assert.strictEqual(app.rtc.mayExecuteControlCommand(), true);
        pc.setState('disconnected');
        assert.strictEqual(app.rtc.mayExecuteControlCommand(), false, 'waehrend der Stoerung');
        pc.setState('connected');
        assert.strictEqual(app.rtc.mayExecuteControlCommand(), false,
            'auch unmittelbar nach der Erholung (Puffer-Schwall)');
        await sleep(60);
        assert.strictEqual(app.rtc.mayExecuteControlCommand(), true, 'danach wieder');
        ok('Empfangsseite fuehrt nur taufrische Befehle aus');
    }

    // -----------------------------------------------------------------
    console.error('\n13) Polling laeuft im Call weiter, nur langsamer');
    {
        resetAll();
        app.signaling.pollSignaling();
        assert.strictEqual(app.refs.pollingIntervalMs, app.signaling.POLL_INTERVAL_IDLE);
        app.signaling.setPollInterval(app.signaling.POLL_INTERVAL_IN_CALL);
        assert.strictEqual(app.refs.pollingIntervalMs, 3000);
        assert.notStrictEqual(app.refs.pollingIntervalId, null, 'Polling laeuft weiter');
        app.signaling.stopPolling();
        ok('Poll-Intervall wird umgeschaltet statt abgeschaltet');
    }

    // -----------------------------------------------------------------
    console.error('\n14) ICE-Server: Fehlerfall wird ausgewertet (F-18)');
    {
        resetAll();
        // Fall A: Server liefert HTTP 500 mit Fehlerobjekt - wie bisher.
        global.__turnResponse = async () => ({
            ok: false, status: 500, json: async () => ({ error: 'kaputt' })
        });
        await app.rtc.loadIceServers();
        const list = app.refs.meteredIceServers;
        assert.ok(Array.isArray(list) && list.length > 0, 'benutzbare Liste statt Fehlerobjekt');
        assert.ok(list.every(s => typeof s.urls === 'string'), 'nur gueltige Eintraege');
        assert.ok(list.every(s => s.urls.startsWith('stun:')), 'STUN-Fallback');
        assert.strictEqual(app.refs.iceServersDegraded, true, 'wird beim naechsten Call neu geladen');
        ok('HTTP 500 landet nicht mehr als ICE-Konfiguration in der PeerConnection');

        // Fall B: Netzwerkfehler
        global.__turnResponse = async () => { throw new Error('offline'); };
        await app.rtc.loadIceServers();
        assert.ok(app.refs.meteredIceServers.length >= 3, 'eingebaute Rueckfallebene greift');
        ok('Netzwerkfehler fuehrt zur eingebauten STUN-Liste');

        // Fall C: Normalbetrieb inkl. turns:
        global.__turnResponse = async () => ({
            ok: true,
            json: async () => ({
                iceServers: [
                    { urls: 'stun:stun.metered.ca:80' },
                    { urls: 'turn:turn.metered.ca:80', username: 'u', credential: 'c' },
                    { urls: 'turns:turn.metered.ca:443', username: 'u', credential: 'c' }
                ]
            })
        });
        await app.rtc.loadIceServers();
        const urls = app.refs.meteredIceServers.map(s => s.urls);
        assert.ok(urls.includes('turns:turn.metered.ca:443'), 'turns: bleibt erhalten (F-17)');
        assert.ok(urls.filter(u => u.startsWith('stun:')).length >= 3, 'mehrere STUN-Server');
        assert.strictEqual(app.refs.iceServersDegraded, false);
        assert.strictEqual(new Set(urls).size, urls.length, 'keine Doppelungen');
        ok('turns: ueberlebt die Kuerzung, STUN-Fallback wird ergaenzt');
    }

    // -----------------------------------------------------------------
    console.error('\n15) Chat und Steuerung laufen ueber getrennte Kanaele');
    {
        fakeActiveCall({ role: 'guide' });
        await sleep(60);

        // Der frueher ausloesende Magic String ist jetzt nur noch Text.
        app.chat.handleMessage(JSON.stringify({ v: P.VERSION, type: 'chat', text: '__arrow_forward__' }));
        assert.deepStrictEqual(global.__logLines('chat-log'), ['Partner: __arrow_forward__']);
        assert.deepStrictEqual(app.sound.plays, [], 'kein Signalton durch Chattext');
        ok('ein in den Chat getippter Magic String steuert nichts mehr');

        // Und umgekehrt: eine Steuernachricht auf dem Chatkanal ist ungueltig.
        global.__clearLogs();
        const before = app.state.control.rejected;
        app.chat.handleMessage(JSON.stringify({ v: P.VERSION, type: 'move', dir: 'forward', seq: 1 }));
        assert.strictEqual(app.state.control.lastRejectCode, 'wrong_channel');
        assert.strictEqual(app.state.control.rejected, before + 1);
        assert.deepStrictEqual(global.__logLines('chat-log'), [], 'nicht als Chattext angezeigt');
        assert.deepStrictEqual(app.sound.plays, [], 'und nicht ausgefuehrt');
        ok('Steuernachricht auf dem Chatkanal wird verworfen');

        // Binaerdaten bleiben auf dem Chatkanal eine Datei, auf dem
        // Steuerkanal sind sie es nie.
        global.__clearLogs();
        app.chat.handleMessage(new ArrayBuffer(8));
        assert.strictEqual(global.__logLines('chat-log').length, 1, 'Download-Link angeboten');
        receiveControl_raw(new ArrayBuffer(8));
        assert.strictEqual(app.state.control.lastRejectCode, 'not_text');
        ok('Binaerdaten nur auf dem Chatkanal');
    }

    // -----------------------------------------------------------------
    console.error('\n16) Ungueltige Nachrichten werden verworfen und geloggt');
    {
        fakeActiveCall({ role: 'guide' });
        await sleep(60);
        global.__clearLogs();

        const cases = [
            ['kein JSON',              '__arrow_forward__',                                              'not_json'],
            ['JSON-Array',             '[1,2,3]',                                                        'not_object'],
            ['Version fehlt',          JSON.stringify({ type: 'move', dir: 'left', seq: 1 }),            'bad_version'],
            ['fremde Version',         JSON.stringify({ v: 99, type: 'move', dir: 'left', seq: 1 }),     'bad_version'],
            ['unbekannter Typ',        JSON.stringify({ v: P.VERSION, type: 'look_up' }),                'unknown_type'],
            ['geerbter Name als Typ',  JSON.stringify({ v: P.VERSION, type: 'constructor' }),            'unknown_type'],
            ['Pflichtfeld fehlt',      JSON.stringify({ v: P.VERSION, type: 'move', seq: 1 }),           'bad_field'],
            ['unzulaessige Richtung',  JSON.stringify({ v: P.VERSION, type: 'move', dir: 'up', seq: 1 }), 'bad_field'],
            ['seq als Zeichenkette',   JSON.stringify({ v: P.VERSION, type: 'move', dir: 'left', seq: '1' }), 'bad_field'],
            ['zu grosser Frame',       JSON.stringify({ v: P.VERSION, type: 'move', dir: 'left', seq: 1, pad: 'x'.repeat(5000) }), 'too_large']
        ];

        cases.forEach(([name, raw, expected]) => {
            const before = app.state.control.rejected;
            receiveControl(raw);
            assert.strictEqual(app.state.control.lastRejectCode, expected, name + ': erwartet ' + expected);
            assert.strictEqual(app.state.control.rejected, before + 1, name + ': mitgezaehlt');
        });
        assert.deepStrictEqual(global.__logLines('chat-log'), [], 'nichts davon im Chatfenster');
        assert.deepStrictEqual(app.sound.plays, [], 'nichts davon ausgefuehrt');
        ok('alle zehn Faelle verworfen, geloggt und nie als Chattext angezeigt');
    }

    // -----------------------------------------------------------------
    console.error('\n17) Rollen: jede Richtung nur in ihrer Richtung');
    {
        // Guide: nimmt Bewegungsbefehle an und bestaetigt sie.
        fakeActiveCall({ role: 'guide' });
        await sleep(60);
        receiveControl({ v: P.VERSION, type: 'move', dir: 'forward', seq: 1 });
        assert.deepStrictEqual(app.sound.plays, ['move_forward_sound'], 'Signalton beim Guide');
        const ack = controlSent().find(m => m.type === 'ack');
        assert.ok(ack && ack.seq === 1 && ack.status === 'executed', 'Bestaetigung zurueck');
        ok('Guide fuehrt "move" aus und bestaetigt');

        // Guide darf selbst kein "move" senden.
        assert.strictEqual(app.control.sendMove('left'), false);
        assert.strictEqual(controlSent().filter(m => m.type === 'move').length, 0);
        ok('Guide kann keine Bewegungsbefehle senden');

        // Und lehnt eine Sperre der Gegenseite ab - die darf nur er selbst setzen.
        receiveControl({ v: P.VERSION, type: 'control_lock', locked: true });
        assert.strictEqual(app.state.control.lastRejectCode, 'forbidden_direction');
        assert.strictEqual(app.state.control.locked, false, 'Sperre nicht uebernommen');
        ok('Guide lehnt control_lock der Gegenseite ab');

        // Zuschauer: lehnt Bewegungsbefehle ab, darf keine Sperre setzen.
        fakeActiveCall({ role: 'viewer' });
        await sleep(60);
        receiveControl({ v: P.VERSION, type: 'move', dir: 'forward', seq: 1 });
        assert.strictEqual(app.state.control.lastRejectCode, 'forbidden_direction');
        assert.deepStrictEqual(app.sound.plays, [], 'kein Signalton beim Zuschauer');
        assert.strictEqual(app.control.setLock(true), false);
        assert.strictEqual(controlSent().filter(m => m.type === 'control_lock').length, 0);
        ok('Zuschauer lehnt "move" ab und kann nicht sperren');

        // Ohne bekannte Rolle steuert niemand.
        fakeActiveCall({ role: 'admin' });          // unbekannter Wert -> null
        await sleep(60);
        assert.strictEqual(app.state.callRole, null, 'unbekannte Rolle wird verworfen');
        receiveControl({ v: P.VERSION, type: 'move', dir: 'forward', seq: 1 });
        assert.strictEqual(app.state.control.lastRejectCode, 'no_role');
        assert.strictEqual(app.control.sendMove('forward'), false);
        assert.deepStrictEqual(app.sound.plays, []);
        ok('ohne Rolle wird weder gesendet noch ausgefuehrt');
    }

    // -----------------------------------------------------------------
    console.error('\n18) Steuerkreuz nur beim Zuschauer');
    {
        const callView = global.__el('call-view');

        fakeActiveCall({ role: 'viewer' });
        assert.ok(callView.classList.contains('role-viewer'));
        assert.strictEqual(global.__el('btn-forward').disabled, false, 'bedienbar');
        ok('Zuschauer bekommt ein bedienbares Steuerkreuz');

        fakeActiveCall({ role: 'guide' });
        assert.ok(callView.classList.contains('role-guide'));
        assert.ok(!callView.classList.contains('role-viewer'), 'keine Zuschauerklasse');
        assert.strictEqual(global.__el('btn-forward').disabled, true, 'gesperrt');
        assert.strictEqual(global.__el('control-lock-bar').style.display, 'flex', 'Sperrschalter sichtbar');
        ok('Guide bekommt kein Steuerkreuz, dafuer den Sperrschalter');

        app.rtc.endCall(false);
        assert.ok(!callView.classList.contains('role-guide'), 'Rolle beim Auflegen abgeraeumt');
        ok('Call-Ende raeumt die Rolle ab');
    }

    // -----------------------------------------------------------------
    console.error('\n19) Bestaetigung verhindert Mehrfachdruecken bei Latenz');
    {
        fakeActiveCall({ role: 'viewer' });
        await sleep(60);

        assert.strictEqual(app.control.sendMove('forward'), true);
        assert.strictEqual(app.state.control.pendingSeq, 1);
        assert.strictEqual(global.__el('btn-forward').disabled, true, 'Steuerkreuz wartet');
        assert.strictEqual(app.control.sendMove('forward'), false, 'zweiter Druck geht nicht raus');
        assert.strictEqual(controlSent().filter(m => m.type === 'move').length, 1);
        ok('waehrend die Bestaetigung aussteht, wird nichts nachgeschoben');

        ackLastMove();
        assert.strictEqual(app.state.control.pendingSeq, null);
        assert.strictEqual(global.__el('btn-forward').disabled, false, 'wieder frei');
        ok('Bestaetigung gibt das Steuerkreuz wieder frei');

        // Bleibt die Bestaetigung aus, gibt die Frist frei - sonst waere das
        // Steuerkreuz nach einem verlorenen "ack" dauerhaft tot.
        assert.strictEqual(app.control.sendMove('left'), true);
        assert.strictEqual(global.__el('btn-forward').disabled, true);
        await sleep(app.control.ACK_TIMEOUT_MS + 60);
        assert.strictEqual(app.state.control.pendingSeq, null);
        assert.strictEqual(global.__el('btn-forward').disabled, false);
        ok('ausbleibende Bestaetigung sperrt das Steuerkreuz nicht dauerhaft');

        // Eine veraltete Bestaetigung hebt eine neuere Sperre nicht auf.
        assert.strictEqual(app.control.sendMove('right'), true);
        const current = app.state.control.pendingSeq;
        receiveControl({ v: P.VERSION, type: 'ack', seq: current - 1, status: 'executed' });
        assert.strictEqual(app.state.control.pendingSeq, current, 'alte Bestaetigung ignoriert');
        ok('veraltete Bestaetigung wird nicht verwechselt');
    }

    // -----------------------------------------------------------------
    console.error('\n20) control_lock haelt die Steuerung an');
    {
        // Guide sperrt.
        fakeActiveCall({ role: 'guide' });
        await sleep(60);
        assert.strictEqual(app.control.setLock(true), true);
        const lock = controlSent().find(m => m.type === 'control_lock');
        assert.ok(lock && lock.locked === true && lock.v === P.VERSION);
        assert.strictEqual(global.__el('control-lock-btn').textContent, 'Steuerung freigeben');
        ok('Guide sendet die Sperre und sieht den Schalter umgestellt');

        // Waehrend der Sperre wird ein Bewegungsbefehl abgelehnt statt ausgefuehrt.
        receiveControl({ v: P.VERSION, type: 'move', dir: 'forward', seq: 1 });
        assert.deepStrictEqual(app.sound.plays, [], 'nicht ausgefuehrt');
        const nack = controlSent().find(m => m.type === 'ack' && m.status === 'rejected');
        assert.ok(nack && nack.reason === 'locked', 'mit Grund abgelehnt');
        ok('gesperrter Guide fuehrt keinen Bewegungsbefehl aus');

        // Zuschauer uebernimmt die Sperre und sendet nicht mehr.
        fakeActiveCall({ role: 'viewer' });
        await sleep(60);
        receiveControl({ v: P.VERSION, type: 'control_lock', locked: true, reason: 'Strasse' });
        assert.strictEqual(app.state.control.locked, true);
        assert.strictEqual(global.__el('control-lock-notice').style.display, 'flex', 'sichtbar angezeigt');
        assert.strictEqual(global.__el('btn-forward').disabled, true);
        assert.strictEqual(app.control.sendMove('forward'), false);
        assert.strictEqual(controlSent().filter(m => m.type === 'move').length, 0, 'nichts gesendet');
        ok('Zuschauer sieht die Sperre und sendet waehrenddessen nicht');

        receiveControl({ v: P.VERSION, type: 'control_lock', locked: false });
        assert.strictEqual(app.state.control.locked, false);
        assert.strictEqual(global.__el('control-lock-notice').style.display, 'none');
        assert.strictEqual(app.control.sendMove('forward'), true);
        ok('nach der Freigabe geht es weiter');
    }

    // -----------------------------------------------------------------
    console.error('\n21) Wiederholte Sequenznummern werden nicht doppelt ausgefuehrt');
    {
        fakeActiveCall({ role: 'guide' });
        await sleep(60);
        receiveControl({ v: P.VERSION, type: 'move', dir: 'forward', seq: 7 });
        receiveControl({ v: P.VERSION, type: 'move', dir: 'forward', seq: 7 });
        receiveControl({ v: P.VERSION, type: 'move', dir: 'left',    seq: 3 });
        assert.deepStrictEqual(app.sound.plays, ['move_forward_sound'], 'nur einmal ausgefuehrt');
        const rejected = controlSent().filter(m => m.type === 'ack' && m.status === 'rejected');
        assert.strictEqual(rejected.length, 2);
        assert.ok(rejected.every(m => m.reason === 'duplicate'));
        ok('Wiederholung und Rueckschritt werden abgelehnt, aber bestaetigt');
    }

    // -----------------------------------------------------------------
    console.error('\n22) Die Rolle kommt vom Server, nicht aus dem Client');
    {
        // Der Angerufene bekommt sie am ausgelieferten Offer.
        resetAll();
        await app.signaling.handleSignalingData({ type: 'offer', sdp: 'x', sender_id: 42, role: 'guide' });
        assert.strictEqual(app.state.pendingOffer.role, 'guide', 'Rolle haengt am Offer');
        ok('das Offer transportiert die Serverentscheidung');

        // Der Anrufer bekommt sie in der Antwort auf sein eigenes Offer.
        resetAll();
        const answer = await app.signaling.sendSignalMessage({ type: 'offer', sdp: 'x', target: 42 });
        assert.ok(answer && typeof answer === 'object', 'Antwort wird durchgereicht');
        ok('sendSignalMessage reicht die Serverantwort durch');
    }

    // -----------------------------------------------------------------
    console.error('\n23) Standort-Button richtet sich nach der Rolle');
    {
        // window.userCan kommt vom Server (ViewHelper::output, abgeleitet aus
        // App\\Helper\\Role). Frueher verglich ui.js selbst gegen 'admin',
        // 'guide' und 'tourist' - Werte, die usertype.name nie liefert. Der
        // Button war dadurch fuer jede Rolle unsichtbar (Befund F-5).
        const btn = () => __el('location-button');
        const show = (loggedIn, can) => {
            window.isLoggedIn = loggedIn;
            window.userCan = can;
            app.ui.showLocationButton();
            return btn().innerHTML;
        };

        const guide = show(true, { offerLocation: true, becomeGuide: false });
        assert.ok(guide.includes('Neue Lokation hinzuf'), 'Guide sieht den Anlege-Button');
        assert.ok(guide.includes('act=set_location_page'), 'und kommt zum Standortformular');
        assert.strictEqual(btn().style.display, '');
        ok('Guide und Admin sehen "Neue Lokation hinzufuegen"');

        // Die beiden Beschriftungen fuehren an verschiedene Stellen: Wer noch
        // kein Guide ist, bekommt die FRAGE nach der Rolle und nicht das
        // Standortformular. Frueher fuehrten beide zum Formular - und wer es
        // ausfuellte, war anschliessend Guide, ohne je gefragt worden zu sein.
        const zuschauer = show(true, { offerLocation: false, becomeGuide: true });
        assert.ok(zuschauer.includes('Tour-Guide werden'), 'Zuschauer sieht den Aufstiegs-Button');
        assert.ok(zuschauer.includes('act=guide_role_page'), 'und kommt zur Guide-Frage');
        assert.ok(!zuschauer.includes('act=set_location_page'),
            'ein Zuschauer kommt nicht mehr direkt an das Standortformular');
        ok('Zuschauer sieht "Jetzt Tour-Guide werden!" und wird gefragt, nicht befoerdert');

        assert.strictEqual(show(true, { offerLocation: false, becomeGuide: false }), '');
        assert.strictEqual(btn().style.display, 'none');
        ok('ohne Berechtigung bleibt der Button aus');

        assert.strictEqual(show(false, { offerLocation: true, becomeGuide: true }), '');
        assert.strictEqual(btn().style.display, 'none');
        ok('abgemeldet bleibt der Button aus, auch mit Rechten im Speicher');

        // Fehlt die Servervariable ganz, darf nichts angezeigt und nichts
        // geworfen werden.
        assert.strictEqual(show(true, undefined), '');
        ok('fehlendes window.userCan blendet aus, statt zu scheitern');

        // Ein Guide, dessen Zustimmung eine aeltere Fassung der Bedingungen
        // traegt: Der offene Punkt geht dem Anlege-Knopf VOR. Das Formular
        // dahinter wuerde ihn ohnehin zur Frage weiterleiten
        // (GuideController::requireCurrentTerms) - dann soll der Knopf nicht
        // so tun, als ginge es um einen Standort.
        const offen = show(true, { offerLocation: true, becomeGuide: false, termsOutdated: true });
        assert.ok(offen.includes('Neue Bedingungen'), 'der offene Punkt steht nicht auf dem Knopf');
        assert.ok(offen.includes('act=guide_role_page'), 'und fuehrt nicht zur Frage');
        assert.ok(!offen.includes('act=set_location_page'),
            'der Knopf fuehrt weiter an ein Formular, das ihn wegschickt');
        assert.strictEqual(btn().style.display, '');
        ok('offene Bedingungen gehen dem Anlege-Knopf vor');

        // Und nur dann: Ohne offenen Punkt bleibt es beim Anlege-Knopf.
        const normal = show(true, { offerLocation: true, becomeGuide: false, termsOutdated: false });
        assert.ok(normal.includes('Neue Lokation hinzuf'), 'der Regelfall hat sich veraendert');
        assert.ok(!normal.includes('Neue Bedingungen'), 'die Warnung erscheint ohne Anlass');
        ok('ohne offenen Punkt bleibt es beim Anlege-Knopf');

        window.isLoggedIn = true;
    }

    console.error('\n24) Symbolknoepfe sind ohne Text verstaendlich');
    {
        // Ein Knopf ohne Beschriftung ist nur dann benutzbar, wenn er sagt,
        // was er tut: aria-label fuer Vorleseprogramme, title als Tooltip.
        // Fehlt eines davon, ist der Knopf fuer einen Teil der Nutzer ein
        // leeres Kaestchen. Diese Pruefung haelt das fest, damit ein spaeter
        // ergaenzter Knopf nicht ohne durchrutscht.
        const tabelle = app.locationsTable;
        const eintrag = {
            id: 7, user_id: 3, user_status: 'online', blocked: 0,
            country_name: 'Portugal', city_name: 'Lissabon',
            description: 'Alfama.'
        };

        const zelle = tabelle.actionCellHtml(eintrag,
            { showActions: ['call', 'edit', 'delete', 'block'] });

        // Ein Symbolknopf je Nebenaktion, und die Hauptaktion behaelt Text.
        assert.ok(zelle.includes('>Anrufen</button>'),
            'die Hauptaktion hat ihre Beschriftung verloren');
        for (const symbol of ['edit', 'delete', 'block']) {
            assert.ok(zelle.includes('app-iconbtn--' + symbol),
                'Symbolknopf ' + symbol + ' fehlt');
        }
        ok('Nebenaktionen als Symbol, die Hauptaktion mit Text');

        // Jeder Symbolknopf traegt beides. Gezaehlt wird stur: so viele
        // aria-label und title wie Symbolknoepfe.
        const anzahl = (text, muster) => (text.match(muster) || []).length;
        assert.strictEqual(anzahl(zelle, /app-iconbtn /g), 3, 'drei Symbolknoepfe erwartet');
        assert.strictEqual(anzahl(zelle, /aria-label="/g), 3, 'nicht jeder Symbolknopf hat ein aria-label');
        assert.strictEqual(anzahl(zelle, /title="/g), 3, 'nicht jeder Symbolknopf hat einen Tooltip');
        ok('jeder Symbolknopf hat aria-label und title');

        // Das aria-label nennt den Standort - sonst meldet ein
        // Vorleseprogramm in einer langen Liste zwanzigmal "Bearbeiten"
        // ohne zu sagen, was bearbeitet wird.
        assert.ok(zelle.includes('aria-label="Standort Lissabon, Portugal bearbeiten"'),
            'das aria-label nennt den Standort nicht:\n' + zelle);
        ok('das aria-label nennt den Standort, nicht nur die Aktion');

        // Gesperrt: aus Sperren wird Freigeben, und das Symbol wechselt mit.
        const gesperrt = tabelle.actionCellHtml(
            Object.assign({}, eintrag, { blocked: 1 }), { showActions: ['block'] });
        assert.ok(gesperrt.includes('app-iconbtn--unblock'), 'kein Freigeben-Symbol');
        assert.ok(!gesperrt.includes('app-iconbtn--block '), 'Sperren-Symbol steht noch da');
        ok('der gesperrte Standort zeigt Freigeben statt Sperren');

        // Ohne Aktion keine leere Huelle im Markup.
        assert.strictEqual(tabelle.actionCellHtml(eintrag, { showActions: [] }), '');
        ok('ohne Aktion bleibt die Zelle leer');
    }

    console.error('\n25) Das Farbprofil wirkt sofort und wird bestaetigt');
    {
        // Die Anzeige darf nicht auf das Netz warten: Erst umstellen, dann
        // speichern. Geht das Speichern schief, wird zurueckgedreht - sonst
        // sieht der Nutzer ein Profil, das beim naechsten Anmelden weg ist.
        const schalter = app.themeSwitch;
        const html = document.documentElement;

        html.setAttribute('data-theme', 'indigo');
        schalter.apply('dunkel');
        assert.strictEqual(html.getAttribute('data-theme'), 'dunkel',
            'apply() setzt das Attribut nicht');
        ok('apply setzt data-theme am <html>-Element');

        // Beim Zurueckdrehen wird auch die Auswahl mitgenommen, nicht nur die
        // Farbe - sonst zeigt der Radioknopf etwas anderes an als der Schirm.
        const feldIndigo = { value: 'indigo', checked: false };
        const bereich = { querySelector: (sel) => sel.includes('indigo') ? feldIndigo : null };

        global.__alerts = [];
        schalter.revert(bereich, 'indigo', 'Ging nicht.');
        assert.strictEqual(html.getAttribute('data-theme'), 'indigo',
            'revert() dreht die Anzeige nicht zurueck');
        assert.strictEqual(feldIndigo.checked, true, 'revert() setzt den Radioknopf nicht zurueck');
        assert.strictEqual(global.__alerts.length, 1, 'genau eine Meldung erwartet');
        assert.ok(global.__alerts[0].includes('Ging nicht'), 'die Meldung fehlt');
        ok('revert dreht Anzeige, Auswahl und Meldung zusammen zurueck');

        // Beim Laden wird NICHTS gesetzt - das Attribut kommt vom Server.
        // Ein init() ohne Auswahlbereich darf nichts anfassen und nicht
        // scheitern (jede Seite ausser der Kontoseite).
        html.setAttribute('data-theme', 'neutral');
        const vorher = global.__themeAjax.length;
        assert.doesNotThrow(() => schalter.init(), 'init() ohne Auswahl wirft');
        assert.strictEqual(html.getAttribute('data-theme'), 'neutral',
            'init() hat das Profil veraendert');
        assert.strictEqual(global.__themeAjax.length, vorher,
            'init() hat ohne Auswahl eine Anfrage geschickt');
        ok('ohne Auswahlbereich passiert nichts');

        html.setAttribute('data-theme', 'indigo');
    }

    console.error('\n26) Die Laenderflagge kommt ohne Netz aus');
    {
        // Vorher stand vor jedem Land ein <img> von flagcdn.com. Laedt das
        // Bild nicht, zeichnet der Browser das Ersatzbild - bei 24x18 Pixeln
        // ein schmaler Strich: "|Ägypten". Die Flagge kommt jetzt aus dem
        // Laenderkuerzel und kann deshalb nicht fehlschlagen.
        const karte = app.locationMap;

        assert.strictEqual(karte.flaggeAusIso2('AT'), '\u{1F1E6}\u{1F1F9}', 'AT ergibt nicht die Flagge Oesterreichs');
        assert.strictEqual(karte.flaggeAusIso2('eg'), '\u{1F1EA}\u{1F1EC}', 'Kleinschreibung wird nicht erkannt');
        assert.strictEqual(karte.flaggeAusIso2(' de '), '\u{1F1E9}\u{1F1EA}', 'Leerzeichen werden nicht abgeschnitten');
        ok('das Kuerzel wird zur Flagge');

        // Alles, was kein Kuerzel ist, ergibt nichts - und nicht etwa ein
        // Zeichen aus einem falschen Block.
        for (const murks of ['', 'D', 'DEU', 'D1', null, undefined, 42, {}]) {
            assert.strictEqual(karte.flaggeAusIso2(murks), '',
                'ungueltige Eingabe ' + JSON.stringify(murks) + ' ergab etwas');
        }
        ok('ungueltige Kuerzel ergeben nichts');

        // Und im Code darf kein Verweis auf den fremden Bilddienst mehr sein.
        const quelle = require('fs').readFileSync(
            require('path').join(__dirname, '..', 'assets', 'js', 'map.js'), 'utf8');
        // Der Erklaertext darf ihn nennen, die Regeln nicht.
        const ohneKommentare = quelle.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
        assert.ok(!ohneKommentare.includes('flagcdn'), 'map.js laedt noch Bilder von flagcdn');
        assert.ok(!ohneKommentare.includes('country.emoji'),
            'der Rest des entfernten emoji-Feldes steht noch im Code');
        ok('kein Bilddienst und kein Rest des emoji-Feldes mehr');
    }

    console.error('\n27) Die Wahl wird auch im Browser gemerkt');
    {
        // Ohne das gilt das Profil erst nach der Anmeldung - Login,
        // Registrierung und Passwort-vergessen waeren immer hell.
        const schalter = app.themeSwitch;
        localStorage.__daten = {};

        schalter.apply('dunkel');
        assert.strictEqual(localStorage.getItem(schalter.STORAGE_KEY), 'dunkel',
            'apply() merkt die Wahl nicht im Browser');
        ok('apply merkt die Wahl im Browser');

        // Ein gesperrter Speicher (privates Fenster) darf nicht durchschlagen:
        // Die Farbe steht dann trotzdem, sie ueberlebt nur den Aufruf nicht.
        const echt = global.localStorage;
        global.localStorage = { setItem() { throw new Error('gesperrt'); }, getItem() { return null; } };
        assert.doesNotThrow(() => schalter.apply('neutral'), 'gesperrter Speicher wirft durch');
        assert.strictEqual(document.documentElement.getAttribute('data-theme'), 'neutral',
            'bei gesperrtem Speicher wurde die Farbe nicht gesetzt');
        global.localStorage = echt;
        ok('ein gesperrter Speicher haelt das Umschalten nicht auf');

        document.documentElement.setAttribute('data-theme', 'indigo');
    }

    // -----------------------------------------------------------------
    console.error('\n28) Der Anruf: wer sendet, entscheidet die Rolle');
    {
        // DER ZUSCHAUER SENDET NICHTS. Er wird beim Anrufen nach gar nichts
        // gefragt - keine Kamera, kein Mikrofon, keine Freigabe. Frueher
        // holte startCall unbedingt das Mikrofon, noch bevor ueberhaupt
        // feststand, welche Rolle der Server vergibt.
        resetAll();
        global.__mediaError = null;
        global.__offerRole = 'viewer';
        app.refs.iceServersLoaded = true;
        app.refs.iceServersDegraded = false;
        app.refs.meteredIceServers = [{ urls: 'stun:x' }];

        await app.rtc.startCall(42);

        assert.strictEqual(global.__mediaRequests.length, 0, 'gar keine Medienanforderung');
        assert.strictEqual(app.refs.localStream, null, 'es gibt keinen eigenen Strom');
        assert.strictEqual(app.state.callRole, 'viewer', 'die Rolle des Servers ist uebernommen');
        assert.strictEqual(app.media.isSending('audio'), false, 'es geht kein Ton raus');
        assert.strictEqual(app.media.isSending('video'), false, 'es geht kein Bild raus');
        ok('der Zuschauer wird nach nichts gefragt und sendet nichts');

        // Fuer BEIDE Spurarten steht trotzdem ein Sender bereit. Ohne ihn
        // liesse sich im Anruf ohne Fuehrung spaeter nichts zuschalten, ohne
        // neu auszuhandeln - und das kann diese Anwendung im Gespraech nur
        // fuer den ICE-Restart.
        ['audio', 'video'].forEach(art => {
            const platz = app.refs.localPeerConnection.getTransceivers()
                .filter(t => t.receiver && t.receiver.track && t.receiver.track.kind === art);
            assert.strictEqual(platz.length, 1, 'genau ein Sender fuer ' + art);
            assert.strictEqual(platz[0].sender.track, null, 'der Sender fuer ' + art + ' traegt nichts');
        });
        assert.strictEqual(app.state.callTimeout !== null, true, 'die Annahmefrist laeuft');
        app.rtc.endCall(false);
        ok('fuer Ton und Bild steht je ein leerer Sender bereit');

        // ANRUF OHNE FUEHRUNG (Rolle "peer", etwa mit der Verwaltung): Dort
        // gehoert das Gespraech in beide Richtungen, also wird das Mikrofon
        // geholt - und zwar erst NACH dem Offer, weil die Rolle erst mit
        // dessen Antwort kommt.
        resetAll();
        global.__offerRole = 'peer';
        app.refs.iceServersLoaded = true;
        app.refs.meteredIceServers = [{ urls: 'stun:x' }];

        await app.rtc.startCall(42);

        assert.strictEqual(app.state.callRole, 'peer', 'die Rolle "peer" ist uebernommen');
        assert.strictEqual(global.__mediaRequests.length, 1, 'genau eine Medienanforderung');
        assert.ok(global.__mediaRequests[0].audio, 'der Ton wird angefordert');
        assert.ok(!global.__mediaRequests[0].video, 'die Kamera wird NICHT angefordert');
        assert.strictEqual(app.media.isSending('audio'), true, 'der Ton geht raus');
        assert.strictEqual(app.media.isSending('video'), false, 'das Bild bleibt aus');
        app.rtc.endCall(false);
        ok('im Anruf ohne Fuehrung wird das Mikrofon geholt, die Kamera nicht');

        // Kein Mikrofon im Anruf ohne Fuehrung: Das Offer liegt bereits beim
        // Angerufenen, es klingelt dort. Ein stiller Abbruch wuerde ein
        // Telefon laeuten lassen, an dem niemand mehr dran ist - also laeuft
        // der Anruf weiter, und es wird gesagt, was fehlt.
        resetAll();
        global.__offerRole = 'peer';
        app.refs.iceServersLoaded = true;
        app.refs.meteredIceServers = [{ urls: 'stun:x' }];
        const abgelehnt = new Error('Permission denied');
        abgelehnt.name = 'NotAllowedError';
        global.__mediaError = abgelehnt;

        await app.rtc.startCall(42);
        global.__mediaError = null;

        assert.strictEqual(global.__alerts.length, 1, 'genau eine Meldung');
        assert.ok(/Mikrofon/.test(global.__alerts[0]), 'die Meldung nennt das Mikrofon');
        assert.ok(!/nicht angenommen/.test(global.__alerts[0]),
            'gemeldet wird der Fehler, nicht eine ausgebliebene Annahme');
        assert.strictEqual(app.state.isCallActive, true, 'der Anruf laeuft weiter');
        assert.ok(global.__logLines('chat-log').some(z => /ohne eigenen Ton/.test(z)),
            'im Verlauf steht, dass es ohne eigenen Ton weitergeht');
        app.rtc.endCall(false);
        ok('ein abgelehntes Mikrofon beendet den laufenden Anruf nicht mehr');

        // Der Server weist einen Anruf ab, der gar nicht zustande kommen darf.
        // Auch das ist eine Antwort und keine Stille: Sie wird durchgereicht.
        resetAll();
        app.refs.iceServersLoaded = true;
        app.refs.meteredIceServers = [{ urls: 'stun:x' }];
        const echterFetch = global.fetch;
        global.fetch = async (url, opts) => {
            if (String(url).includes('getSignal') && opts && opts.method === 'POST') {
                global.__signals.push(JSON.parse(opts.body));
                return { ok: true, text: async () => JSON.stringify({
                    status: 'error', msg: 'Dieser Benutzer bietet keine Führungen an.'
                }) };
            }
            return echterFetch(url, opts);
        };

        await app.rtc.startCall(7);
        global.fetch = echterFetch;

        assert.strictEqual(global.__alerts.length, 1, 'genau eine Meldung');
        assert.ok(/Führungen/.test(global.__alerts[0]), 'die Absage des Servers steht in der Meldung');
        assert.strictEqual(app.state.isCallActive, false, 'der Call wird abgeraeumt');
        assert.strictEqual(app.state.callTimeout, null, 'keine Frist auf einen Anruf, den es nicht gibt');
        assert.ok(!app.state.callRole, 'ohne Anruf auch keine Rolle');
        assert.strictEqual(global.__mediaRequests.length, 0, 'und niemand wurde nach Medien gefragt');
        ok('eine Absage des Servers wird sofort weitergegeben');
    }

    // -----------------------------------------------------------------
    // Gemeinsame Attrappe fuer die Seite "Standort anbieten" (29 und 30).
    //
    // Der Harness laedt map.js in einem eigenen Block mit einer sehr kargen
    // jQuery-Attrappe (fuer die Flaggenpruefung in 26 reicht sie). Hier wird
    // dieselbe Datei ein zweites Mal geladen - mit einer Attrappe, die
    // Feldwerte, Attribute, angehaengte Optionen und die gebundenen Handler
    // mitschreibt, und in einem eigenen Geltungsbereich, damit weder $ noch
    // window.webrtcApp global ueberschrieben werden.
    // -----------------------------------------------------------------
    const fs = require('fs'), path = require('path');
    const mapQuelle = fs.readFileSync(
        path.join(__dirname, '..', 'assets', 'js', 'map.js'), 'utf8');

    function ladeKarte(umgebung) {
        const bauen = new Function(
            'window', '$', 'jQuery', 'document', 'L', 'fetch', 'Option',
            mapQuelle + '\nreturn window.webrtcApp.locationMap;');

        // Das Dokument braucht nur, was map.js daran anfasst: den
        // Kartenbereich, zu dem der Hinweis beim Abschicken scrollt.
        const dokument = {
            ready() {},
            getElementById: (id) => (id === 'map' ? umgebung.kartenBereich : null)
        };

        return bauen(
            umgebung.fenster, umgebung.jq, umgebung.jq, dokument,
            umgebung.L, umgebung.fetch, umgebung.Option);
    }

    // Kleine jQuery-Attrappe: genug, dass der echte Code durchlaeuft.
    // Sie merkt sich Feldwerte, Beschriftungen und die gebundenen
    // Handler - mehr braucht diese Pruefung nicht.
    function baueSeite(landVorgewaehlt, vorbelegung) {
        const felder = {};
        const handler = {};
        // Was der Server nach einer Ablehnung ins Formular zurueckschreibt:
        // Koordinaten direkt in die versteckten Felder, Land und Stadt als
        // data-Attribute am Formular (assets/html/set_location.html).
        const vor = vorbelegung || {};
        felder['#location-form'] = { attribute: {
            'data-vorher-land':  vor.land  || '',
            'data-vorher-stadt': vor.stadt || ''
        } };
        felder['#latitude']  = { value: vor.latitude  || '' };
        felder['#longitude'] = { value: vor.longitude || '' };
        const gewaehlt = landVorgewaehlt
            ? { value: '7', daten: { iso2: 'de', 'country-name': 'Deutschland' } }
            : { value: '', daten: {} };
        felder['#countrySelect option:selected'] = gewaehlt;
        felder['#countrySelect'] = { value: gewaehlt.value, daten: {} };
        felder['#dieLandOption'] = { value: '7', daten: { iso2: 'de', 'country-name': 'Deutschland' } };

        const zerlege = (sel) => sel.split(',').map(t => t.trim()).filter(Boolean);
        function huelle(ids) {
            return {
                length: ids.length,
                val(v) {
                    if (v === undefined) return felder[ids[0]] ? felder[ids[0]].value : undefined;
                    ids.forEach(i => {
                        (felder[i] = felder[i] || {}).value = v;
                        // Im echten DOM zieht eine neue Auswahl die
                        // Option:selected mit. Ohne das liefe der Zweig
                        // "kein Landeszentrum gefunden" endlos im Kreis:
                        // Er setzt das Feld zurueck und loest wieder
                        // 'change' aus - und die Attrappe meldete weiter
                        // das alte Land.
                        if (i === '#countrySelect') {
                            felder['#countrySelect option:selected'] = v
                                ? { value: v, daten: { iso2: 'de', 'country-name': 'Deutschland' } }
                                : { value: '', daten: {} };
                        }
                    });
                    return this;
                },
                text(t) {
                    if (t === undefined) return felder[ids[0]] ? felder[ids[0]].text : '';
                    ids.forEach(i => { (felder[i] = felder[i] || {}).text = t; });
                    return this;
                },
                on(evt, fn) {
                    ids.forEach(i => { (handler[i + '|' + evt] = handler[i + '|' + evt] || []).push(fn); });
                    return this;
                },
                trigger(evt, ereignis) {
                    const e = ereignis || { params: {} };
                    ids.forEach(i => (handler[i + '|' + evt] || []).slice().forEach(fn => fn(e)));
                    return this;
                },
                attr(k, v) {
                    const f = (felder[ids[0]] = felder[ids[0]] || {});
                    f.attribute = f.attribute || {};
                    if (v === undefined) return f.attribute[k];
                    f.attribute[k] = v;
                    return this;
                },
                find(s) { return huelle([ids[0] + ' ' + s]); },
                // Gefiltert wird immer nach dem Laenderkuerzel; hier steht
                // genau eine Option zur Auswahl.
                filter() { return huelle(['#dieLandOption']); },
                data(k) { const f = felder[ids[0]]; return f && f.daten ? f.daten[k] : undefined; },
                prop() { return this; }, next() { return huelle([]); },
                append(was) {
                    const f = (felder[ids[0]] = felder[ids[0]] || {});
                    (f.angehaengt = f.angehaengt || []).push(was);
                    return this;
                },
                empty() { return this; },
                show() { return this; }, hide() { return this; },
                select2() { return this; }, each() { return this; }
            };
        }
        const jq = (sel) => typeof sel === 'string'
            ? huelle(zerlege(sel))
            : Object.assign(huelle([]), { ready() { return this; } });
        jq.fn = { select2() {} };

        // Was das Reverse-Geocoding antwortet, wechselt je Pruefung.
        const antwort = { wert: {
            display_name: 'Berlin, Deutschland',
            address: { country_code: 'de', city: 'Berlin' }
        } };

        const fenster = { webrtcApp: { notify: {
            error: (t) => global.__alerts.push(String(t)), success() {}, info() {}
        } } };

        // Mitschreiben, ob zur Karte gescrollt wurde.
        const kartenBereich = { gescrollt: 0, scrollIntoView() { this.gescrollt++; } };

        const karte = ladeKarte({
            fenster, jq, kartenBereich,
            L: { marker: () => ({ addTo() { return this; } }) },
            Option: function (text, value) { return { text, value }; },
            fetch: () => Promise.resolve({ ok: true, json: () => Promise.resolve(antwort.wert) })
        });

        // Statt initMap(): Leaflet wird hier nicht geladen.
        karte.map = { on() {}, removeLayer() {}, setView() {}, addLayer() {} };
        karte.initCitySelect2();
        karte.bindEvents();

        return { felder, handler, jq, karte, antwort, kartenBereich,
                 handlerAnzahl: (handler['#countrySelect|change'] || []).length };
    }

    console.error('\n29) Ein Klick auf die Karte setzt den Punkt und behaelt ihn');
    {
        // Der Befund: Auf "Standort anbieten" liess sich der Punkt nicht per
        // Mausklick setzen. Der Klick-Handler war gebunden und feuerte auch -
        // nur hingen auf #countrySelect ZWEI change-Handler. onMapClick()
        // fuellt die Koordinatenfelder, holt danach den Ortsnamen bei
        // Nominatim und traegt aus der Antwort das erkannte Land ein. Das
        // loeste 'change' aus, und der zweite Handler (aus initCitySelect2)
        // leerte #latitude, #longitude, #lat, #lon und #osm_place wieder -
        // ungefragt, waehrend onCountryChange() sich per Markierung
        // zurueckhielt. Zurueck blieb ein Formular, das mit Marker, Land und
        // Stadt vollstaendig aussah, aber ohne Koordinaten abgeschickt wurde;
        // der Server wies es mit success=2 ab.
        //
        // Sichtbar wurde es nur beim Landwechsel: Wer vorher Deutschland
        // waehlte und dann nach Deutschland klickte, blieb verschont - beim
        // allerersten Klick ist noch gar kein Land gewaehlt, der schlug immer
        // fehl. Deshalb wird hier BEIDES geprueft, und dazu die Gegenprobe:
        // waehlt der Nutzer selbst ein Land, muss weiterhin geleert werden.
        // Genau EIN change-Handler. Ein zweiter, ungeschuetzter waere der
        // Rueckfall in den Befund - deshalb wird die Zahl selbst geprueft und
        // nicht nur ihre Wirkung.
        const ohneLand = baueSeite(false);
        assert.strictEqual(ohneLand.handlerAnzahl, 1,
            'auf #countrySelect haengt mehr als ein change-Handler');
        ok('das Land hat genau einen change-Handler');

        ohneLand.karte.onMapClick({ latlng: { lat: 52.52, lng: 13.405 } });
        assert.strictEqual(String(ohneLand.felder['#latitude'].value), '52.52',
            'der Klick fuellt den Breitengrad nicht');
        await sleep(10);   // das Reverse-Geocoding abwarten

        assert.strictEqual(String(ohneLand.felder['#latitude'].value), '52.52',
            'der Breitengrad ueberlebt das Setzen des Landes nicht');
        assert.strictEqual(String(ohneLand.felder['#longitude'].value), '13.405',
            'der Laengengrad ueberlebt das Setzen des Landes nicht');
        assert.strictEqual(ohneLand.felder['#lat'].text, '52.520000',
            'die Anzeige des Breitengrads ist leer');
        assert.strictEqual(ohneLand.felder['#osm_place'].text, 'Berlin, Deutschland',
            'der Ortsname aus OpenStreetMap ist leer');
        ok('ohne vorgewaehltes Land bleibt der geklickte Punkt stehen');

        // Derselbe Klick mit bereits gewaehltem Land. Das ging auch vorher
        // schon, weil dann kein Landwechsel stattfindet - es muss weiter gehen.
        const mitLand = baueSeite(true);
        mitLand.karte.onMapClick({ latlng: { lat: 52.52, lng: 13.405 } });
        await sleep(10);
        assert.strictEqual(String(mitLand.felder['#latitude'].value), '52.52',
            'mit vorgewaehltem Land geht der Breitengrad verloren');
        assert.strictEqual(String(mitLand.felder['#longitude'].value), '13.405',
            'mit vorgewaehltem Land geht der Laengengrad verloren');
        ok('mit vorgewaehltem Land ebenso');

        // Gegenprobe: Waehlt der NUTZER ein Land, muss weiterhin alles
        // zurueckgesetzt werden. Ein Punkt in Deutschland darf nicht an einer
        // Auswahl "Frankreich" haengenbleiben.
        {
            const seite = baueSeite(true);
            seite.antwort.wert = [];   // Nominatim findet kein Landeszentrum
            seite.jq('#latitude, #longitude').val('52.52');
            seite.jq('#lat, #lon, #osm_place').text('irgendwas');

            seite.jq('#countrySelect').trigger('change');
            await sleep(10);

            assert.strictEqual(seite.felder['#latitude'].value, '',
                'ein Landwechsel des Nutzers loescht die alten Koordinaten nicht');
            assert.strictEqual(seite.felder['#osm_place'].text, '',
                'ein Landwechsel des Nutzers loescht den alten Ortsnamen nicht');
            ok('waehlt der Nutzer selbst ein Land, wird weiterhin geleert');
        }

        // Der Standortknopf setzt die Koordinaten jetzt sofort und nicht mehr
        // erst nach einer halben Sekunde: Das setTimeout(..., 500) war nur ein
        // Pflaster auf demselben Loeschen.
        const ohneKommentare = mapQuelle
            .replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
        assert.ok(!/setTimeout\([\s\S]{0,400}?\}, 500\)/.test(ohneKommentare),
            'die Wartezeit von 500 ms steht noch im Code');
        ok('kein Warten mehr auf ein Loeschen, das es nicht mehr gibt');
    }

    console.error('\n30) Kein Absenden ohne Punkt, und keine verlorene Eingabe');
    {
        // ZWEI BEFUNDE AUS DEMSELBEN FORMULAR
        //
        // (a) An #latitude und #longitude stand ein required. Es hatte keine
        //     Wirkung: Ein <input type="hidden"> ist von der Pruefung des
        //     Browsers ausgenommen. Das Formular ging ohne Koordinaten raus,
        //     der Server wies es ab - eine Seite weiter, weg von dem Feld, an
        //     dem es lag. Geprueft wird jetzt beim Abschicken.
        //
        // (b) Bei diesem Ruecksprung ging die eingetippte Beschreibung
        //     verloren, ebenso Land und Stadt. Der Server schreibt sie jetzt
        //     zurueck ins Formular (Beschreibung und Koordinaten direkt in die
        //     Felder, Land und Stadt als data-Attribute); map.js holt daraus
        //     Auswahl, Anzeige und Marker zurueck.

        // --- (a) Die Pruefung beim Abschicken ---------------------------
        {
            const seite = baueSeite(false);
            let angehalten = false;
            const ereignis = { preventDefault() { angehalten = true; }, params: {} };

            seite.jq('#location-form').trigger('submit', ereignis);
            assert.strictEqual(angehalten, true,
                'ohne Koordinaten wird das Formular trotzdem abgeschickt');
            assert.ok(/Karte/.test(seite.felder['#location-hinweis'].text || ''),
                'es wird kein Hinweis auf die Karte gezeigt');
            // Der Hinweis steht oben, die Karte weiter unten: Ohne das
            // Scrollen sieht der Nutzer je nach Fenstergroesse keines von
            // beidem und haelt den Knopf fuer kaputt.
            assert.strictEqual(seite.kartenBereich.gescrollt, 1,
                'es wird nicht zur Karte gescrollt');
            ok('ohne Punkt geht das Formular nicht raus');

            // Mit gueltigem Punkt darf nichts im Weg stehen - und der Hinweis
            // von eben muss weg sein, sonst steht eine Meldung da, die nicht
            // mehr zutrifft.
            angehalten = false;
            seite.jq('#latitude').val('52.52');
            seite.jq('#longitude').val('13.405');
            seite.jq('#location-form').trigger('submit', ereignis);
            assert.strictEqual(angehalten, false, 'mit Punkt wird das Absenden angehalten');
            assert.strictEqual(seite.felder['#location-hinweis'].text, '',
                'der alte Hinweis bleibt stehen');
            ok('mit Punkt geht es durch, und der Hinweis verschwindet');

            // Werte ausserhalb des gueltigen Bereichs sind so unbrauchbar wie
            // gar keine - dieselben Grenzen wie im LocationController.
            for (const [breite, laenge] of [['91', '0'], ['0', '181'], ['abc', '0']]) {
                angehalten = false;
                seite.jq('#latitude').val(breite);
                seite.jq('#longitude').val(laenge);
                seite.jq('#location-form').trigger('submit', ereignis);
                assert.strictEqual(angehalten, true,
                    'unbrauchbare Koordinaten ' + breite + '/' + laenge + ' gehen durch');
            }
            ok('unbrauchbare Koordinaten kommen nicht durch');
        }

        // --- (b) Der Ruecksprung mit gemerkten Eingaben ------------------
        {
            // So sieht das Formular aus, das der Server nach "Beschreibung zu
            // kurz" zurueckschickt: Der Punkt stand schon, nur der Text war zu
            // kurz. Genau hier ging vorher alles verloren.
            const seite = baueSeite(false, {
                land: '7', stadt: 'Berlin', latitude: '52.52', longitude: '13.405'
            });

            seite.karte.stelleKoordinatenWiederHer();
            assert.strictEqual((seite.felder['#lat'] || {}).text, '52.520000',
                'der Breitengrad wird nicht wieder angezeigt');
            assert.strictEqual((seite.felder['#lon'] || {}).text, '13.405000',
                'der Laengengrad wird nicht wieder angezeigt');
            assert.ok(seite.karte.marker, 'der Marker fehlt nach dem Ruecksprung');
            ok('Anzeige und Marker kommen zurueck');

            // Und jetzt Land und Stadt - der Schritt, der die Koordinaten
            // frueher wieder geloescht haette, weil er einen Landwechsel
            // ausloest.
            seite.karte.stelleLandUndStadtWiederHer();
            assert.strictEqual(seite.felder['#countrySelect'].value, '7',
                'das Land steht nicht wieder im Auswahlfeld');
            const stadtOptionen = seite.felder['#citySelect'].angehaengt || [];
            assert.ok(stadtOptionen.some(o => o && o.text === 'Berlin'),
                'die Stadt wird nicht nachgereicht');
            assert.strictEqual(String(seite.felder['#latitude'].value), '52.52',
                'das Wiederherstellen des Landes loescht die Koordinaten');
            assert.strictEqual((seite.felder['#lat'] || {}).text, '52.520000',
                'das Wiederherstellen des Landes loescht die Anzeige');
            ok('Land und Stadt kommen zurueck, ohne den Punkt zu loeschen');

            // Bis hierher wurden die beiden Methoden von Hand gerufen. Sie
            // muessen aber auch von selbst laufen: das Wiederherstellen von
            // Land und Stadt haengt an der Laenderliste und kann erst
            // loslaufen, wenn die da ist.
            const echt = baueSeite(false, {
                land: '7', stadt: 'Berlin', latitude: '52.52', longitude: '13.405'
            });
            echt.antwort.wert = [
                { id: '7', country_name: 'Deutschland', iso2: 'DE' },
                { id: '9', country_name: 'Frankreich',  iso2: 'FR' }
            ];
            echt.karte.loadCountries();
            await sleep(10);
            assert.strictEqual(echt.felder['#countrySelect'].value, '7',
                'loadCountries() holt das Land nicht zurueck');
            assert.strictEqual(String(echt.felder['#latitude'].value), '52.52',
                'die Koordinaten ueberstehen das Laden der Laender nicht');
            ok('das Wiederherstellen haengt an der geladenen Laenderliste');

            // Danach muss das Formular auch wirklich rausgehen duerfen.
            let angehalten = false;
            seite.jq('#location-form').trigger('submit',
                { preventDefault() { angehalten = true; }, params: {} });
            assert.strictEqual(angehalten, false,
                'das wiederhergestellte Formular laesst sich nicht abschicken');
            ok('das wiederhergestellte Formular ist absendebereit');
        }

        // --- Ohne gemerkte Eingaben passiert nichts ----------------------
        {
            const seite = baueSeite(false);
            seite.karte.stelleKoordinatenWiederHer();
            seite.karte.stelleLandUndStadtWiederHer();

            assert.strictEqual(seite.karte.marker, null,
                'im leeren Formular steht ein Marker');
            assert.strictEqual(seite.felder['#countrySelect'].value, '',
                'im leeren Formular ist ein Land gewaehlt');
            assert.ok(!(seite.felder['#citySelect'] || {}).angehaengt,
                'im leeren Formular wird eine Stadt nachgereicht');
            ok('ohne gemerkte Eingaben bleibt das Formular leer');
        }
    }

    // -----------------------------------------------------------------
    // Medien im laufenden Call (31-34).
    //
    // Gemeinsame Vorbereitung: ein Call, in dem beide Spurarten ausgehandelt
    // sind - einmal in der Rolle des Zuschauers (er ruft an) und einmal in der
    // des Guides (er nimmt an). Beide Wege muessen dasselbe leisten.
    // -----------------------------------------------------------------

    /** Setzt die Medien-Attrappe in den Ausgangszustand. */
    function medienZuruecksetzen() {
        global.__mediaRequests.length = 0;
        global.__mediaError = null;
        global.__mediaErrorFor.audio = null;
        global.__mediaErrorFor.video = null;
        app.media.reset();
    }

    /**
     * Baut einen laufenden Call als ANRUFER auf.
     *
     * @param {string} rolle - Rolle, die der Server auf das Offer zurueckgibt:
     *                         'viewer' (sendet nichts) oder 'peer' (sendet).
     */
    async function callAlsAnrufer(rolle) {
        resetAll();
        medienZuruecksetzen();
        global.__offerRole = rolle;
        app.refs.iceServersLoaded = true;
        app.refs.iceServersDegraded = false;
        app.refs.meteredIceServers = [{ urls: 'stun:x' }];

        await app.rtc.startCall(42);
        app.rtc.stopTimeout();
        app.state.connectionStatus = 'connected';
        app.state.connectedSince = Date.now() - 10000;
        return app.refs.localPeerConnection;
    }

    /** Ein laufender Call als ZUSCHAUER: Er empfaengt nur. */
    const callAlsZuschauer = () => callAlsAnrufer('viewer');

    /** Ein laufender Call OHNE FUEHRUNG (Rolle "peer"): beide senden. */
    const callAlsPeer = () => callAlsAnrufer('peer');

    /**
     * Baut einen laufenden Call als GUIDE (Angerufener) auf.
     * @param {Object} [wahl] - Auswahl im Annahmedialog
     */
    async function callAlsGuide(wahl) {
        resetAll();
        medienZuruecksetzen();
        app.refs.iceServersLoaded = true;
        app.refs.meteredIceServers = [{ urls: 'stun:x' }];
        app.state.pendingOffer = { sender_id: 7, type: 'offer', sdp: 'sdp-x', role: 'guide' };

        await app.rtc.acceptCall(Object.assign({ video: true, audio: true }, wahl || {}));

        // Der Angerufene bekommt die Kanaele sonst erst ueber ondatachannel.
        if (!app.refs.chatChannel)    app.rtc.attachChannel(makeChannel(P.CHANNEL_CHAT));
        if (!app.refs.controlChannel) app.rtc.attachChannel(makeChannel(P.CHANNEL_CONTROL));
        app.state.connectionStatus = 'connected';
        app.state.connectedSince = Date.now() - 10000;
        return app.refs.localPeerConnection;
    }

    /** Die zuletzt ueber den Steuerkanal gesendete Nachricht. */
    function letzteSteuernachricht() {
        const gesendet = app.refs.controlChannel.sent;
        if (!gesendet.length) return null;
        return JSON.parse(gesendet[gesendet.length - 1]);
    }

    console.error('\n31) Geraetewechsel im Call wirkt - ohne Neuaushandlung');
    {
        // Ein Wechsel von Kamera oder Mikrofon aendert nur die Quelle hinter
        // einer bereits ausgehandelten Spur. Dafuer ist replaceTrack da: Er
        // wirkt sofort bei der Gegenseite, ohne Offer und ohne Answer.
        // Vorher blieb der Wechsel wirkungslos - die Geraeteliste war beim
        // Seitenaufbau ohne Freigabe gefuellt worden und enthielt Eintraege
        // mit LEERER Kennung, an denen der Wechsel still abbrach.
        //
        // Geprueft wird in den beiden Rollen, die ueberhaupt senden: im Anruf
        // ohne Fuehrung (Anrufer) und beim Guide (Angerufener). Der Zuschauer
        // hat weder Kamera noch Mikrofon - bei ihm gibt es nichts zu wechseln.
        const pc = await callAlsPeer();
        const offersVorher = pc.offersCreated;

        // --- Mikrofon ---
        const altesMikro = app.media.localTrack('audio');
        assert.strictEqual(await app.media.switchDevice('audio', 'mic-2'), true,
            'der Mikrofonwechsel wurde nicht ausgefuehrt');
        assert.strictEqual(app.media.senderFor('audio').track.deviceId, 'mic-2',
            'am Sender haengt nicht das neue Mikrofon');
        assert.strictEqual(pc.offersCreated, offersVorher,
            'der Wechsel hat neu ausgehandelt statt replaceTrack zu benutzen');
        assert.strictEqual(altesMikro.stopped, true,
            'das alte Mikrofon laeuft weiter');
        assert.strictEqual(app.refs.localStream.getAudioTracks().length, 1,
            'im lokalen Strom liegen jetzt zwei Tonspuren');
        ok('das Mikrofon wechselt ueber replaceTrack, ohne Neuaushandlung');

        // --- Kamera ---
        assert.strictEqual(await app.media.setCamera(true), true, 'die Kamera ging nicht an');
        assert.strictEqual(await app.media.switchDevice('video', 'cam-2'), true,
            'der Kamerawechsel wurde nicht ausgefuehrt');
        assert.strictEqual(app.media.senderFor('video').track.deviceId, 'cam-2',
            'am Sender haengt nicht die neue Kamera');
        assert.strictEqual(pc.offersCreated, offersVorher,
            'der Kamerawechsel hat neu ausgehandelt');
        ok('die Kamera wechselt ueber replaceTrack, ohne Neuaushandlung');

        // --- Der Sender wird ueber den Transceiver gesucht, nicht ueber die
        //     Spur. Genau hier lag der zweite Fehler: Bei stummem Mikrofon
        //     lieferte die Frage nach dem VIDEOsender den AUDIOsender - der
        //     erste Sender ohne Spur -, und replaceTrack warf.
        await app.media.setMic(false);
        assert.strictEqual(app.media.senderFor('audio').track, null, 'das Mikrofon ist nicht stumm');
        assert.strictEqual(await app.media.switchDevice('video', 'cam-1'), true,
            'bei stummem Mikrofon scheitert der Kamerawechsel');
        assert.strictEqual(app.media.senderFor('video').track.deviceId, 'cam-1',
            'die Kamera wurde nicht gewechselt');
        assert.strictEqual(app.media.senderFor('audio').track, null,
            'der Kamerawechsel hat am Audiosender gedreht');
        ok('bei stummem Mikrofon wird der richtige Sender getroffen');

        // --- Ausgeschaltet: die Wahl wird gemerkt, aber nichts heimlich
        //     eingeschaltet. Wer die Kamera gesperrt hat, will sie nicht
        //     durch die Auswahl eines Geraets wieder anhaben.
        await app.media.setCamera(false);
        assert.strictEqual(await app.media.switchDevice('video', 'cam-2'), false,
            'die ausgeschaltete Kamera wurde durch die Auswahl eingeschaltet');
        assert.strictEqual(app.media.senderFor('video').track, null, 'es geht wieder Bild raus');
        assert.strictEqual(app.state.media.videoDeviceId, 'cam-2', 'die Wahl wurde nicht gemerkt');
        await app.media.setCamera(true);
        assert.strictEqual(app.media.senderFor('video').track.deviceId, 'cam-2',
            'beim Einschalten gilt die gemerkte Wahl nicht');
        ok('bei ausgeschalteter Kamera wird die Wahl nur gemerkt');
        app.rtc.endCall(false);

        // --- Der Zuschauer dagegen sendet nichts, also wechselt er auch
        //     nichts. Kamera und Mikrofon lassen sich bei ihm nicht einmal
        //     ueber einen direkten Aufruf einschalten.
        await callAlsZuschauer();
        assert.strictEqual(await app.media.setCamera(true), false,
            'der Zuschauer konnte seine Kamera einschalten');
        assert.strictEqual(await app.media.setMic(true), false,
            'der Zuschauer konnte sein Mikrofon einschalten');
        assert.strictEqual(global.__mediaRequests.length, 0,
            'der Zuschauer wurde doch nach einem Geraet gefragt');
        ok('der Zuschauer bekommt weder Kamera noch Mikrofon eingeschaltet');
        app.rtc.endCall(false);

        // --- Dieselbe Zusage in der anderen Rolle und damit in der anderen
        //     Richtung: Der Guide hat angenommen, nicht angerufen.
        const pcGuide = await callAlsGuide();
        const offersGuide = pcGuide.offersCreated;
        assert.strictEqual(await app.media.switchDevice('video', 'cam-2'), true,
            'der Guide kann die Kamera nicht wechseln');
        assert.strictEqual(app.media.senderFor('video').track.deviceId, 'cam-2',
            'beim Guide haengt nicht die neue Kamera am Sender');
        assert.strictEqual(await app.media.switchDevice('audio', 'mic-2'), true,
            'der Guide kann das Mikrofon nicht wechseln');
        assert.strictEqual(app.media.senderFor('audio').track.deviceId, 'mic-2',
            'beim Guide haengt nicht das neue Mikrofon am Sender');
        assert.strictEqual(pcGuide.offersCreated, offersGuide,
            'der Guide handelt beim Wechsel neu aus');
        ok('auch in der Guide-Rolle wirkt der Wechsel ohne Neuaushandlung');
        app.rtc.endCall(false);
    }

    console.error('\n32) Die eigene Kamera abschalten kommt beim Gegenueber an');
    {
        const pc = await callAlsGuide();
        const kanal = app.refs.controlChannel;
        const spurVorher = app.media.localTrack('video');
        kanal.sent.length = 0;

        // --- Abschalten ---
        assert.strictEqual(await app.media.toggleCamera(), true, 'die Kamera ging nicht aus');
        assert.strictEqual(app.media.senderFor('video').track, null,
            'es geht weiter Bild auf die Leitung');
        assert.deepStrictEqual(letzteSteuernachricht(), { v: P.VERSION, type: 'video_state', on: false },
            'der Gegenseite wird das Abschalten nicht gemeldet');
        // Wirklich aus: Ohne stop() bliebe die Kameraleuchte an, und "Video
        // sperren" waere ein Versprechen, das die Anwendung nicht haelt.
        assert.strictEqual(spurVorher.stopped, true, 'die Kamera laeuft weiter');
        assert.strictEqual(app.refs.localStream.getVideoTracks().length, 0,
            'die abgeschaltete Spur liegt noch im lokalen Strom');
        assert.strictEqual(__el('local-video-placeholder').style.display, 'flex',
            'der eigene Platzhalter erscheint nicht');
        ok('die Kamera geht wirklich aus und die Gegenseite erfaehrt es');

        // --- Wieder an ---
        assert.strictEqual(await app.media.toggleCamera(), true, 'die Kamera ging nicht wieder an');
        assert.ok(app.media.senderFor('video').track, 'nach dem Einschalten haengt keine Spur am Sender');
        assert.deepStrictEqual(letzteSteuernachricht(), { v: P.VERSION, type: 'video_state', on: true },
            'das Einschalten wird nicht gemeldet');
        assert.strictEqual(__el('local-video-placeholder').style.display, 'none',
            'der Platzhalter bleibt ueber dem laufenden Bild stehen');
        ok('das Einschalten wird ebenso gemeldet');
        app.rtc.endCall(false);

        // --- Die Empfangsseite ---
        // Weg 1: die Protokollnachricht. Sie laeuft durch dieselbe Pruefung
        // wie jede andere Steuernachricht.
        await callAlsZuschauer();
        app.control.handleMessage(JSON.stringify({ v: P.VERSION, type: 'video_state', on: false }));
        assert.strictEqual(__el('remote-video').style.display, 'none', 'das Bild steht noch');
        assert.strictEqual(__el('remote-video-placeholder').style.display, 'flex',
            'der Platzhalter der Gegenseite fehlt');
        app.control.handleMessage(JSON.stringify({ v: P.VERSION, type: 'video_state', on: true }));
        assert.strictEqual(__el('remote-video').style.display, 'block', 'das Bild kommt nicht zurueck');
        assert.strictEqual(__el('remote-video-placeholder').style.display, 'none',
            'der Platzhalter bleibt ueber dem Bild stehen');
        ok('video_state schaltet beim Empfaenger Bild und Platzhalter um');

        // Weg 2: die Empfangsspur wird stumm. Darauf ist auch dann Verlass,
        // wenn der Steuerkanal gerade nicht steht - vorher blieb in diesem
        // Fall das letzte Standbild stehen.
        const fernspur = { kind: 'video', muted: false, onmute: null, onunmute: null, onended: null };
        app.rtc.bindRemoteVideoTrack(fernspur);
        assert.strictEqual(__el('remote-video').style.display, 'block',
            'eine laufende Spur zeigt kein Bild');
        fernspur.muted = true;
        fernspur.onmute();
        assert.strictEqual(__el('remote-video-placeholder').style.display, 'flex',
            'die stumme Empfangsspur blendet den Platzhalter nicht ein');
        fernspur.muted = false;
        fernspur.onunmute();
        assert.strictEqual(__el('remote-video').style.display, 'block',
            'das Bild kommt nach dem Wiederanlaufen nicht zurueck');
        ok('auch ohne Steuerkanal wird die abgeschaltete Kamera erkannt');
        app.rtc.endCall(false);

        // --- Der Anfangszustand wird von selbst gemeldet ---
        // Wer ohne Kamera annimmt, sendete frueher NIE ein video_state. Beim
        // Zuschauer blieb eine schwarze Flaeche ohne Erklaerung stehen.
        await callAlsGuide({ video: false, audio: true });
        const frisch = makeChannel(P.CHANNEL_CONTROL);
        app.rtc.attachChannel(frisch);
        frisch.onopen();
        const typen = frisch.sent.map(r => JSON.parse(r).type);
        assert.ok(typen.includes('hello'), 'die Rolle wird nicht gemeldet');
        assert.ok(typen.includes('video_state'), 'der eigene Videozustand wird nicht gemeldet');
        const zustand = frisch.sent.map(r => JSON.parse(r)).find(m => m.type === 'video_state');
        assert.strictEqual(zustand.on, false, 'gemeldet wird ein Bild, das gar nicht gesendet wird');
        // Und der Platz fuer die Kamera steht trotzdem bereit: Ohne ihn
        // liesse sie sich nur mit einer Neuaushandlung zuschalten.
        assert.ok(app.media.senderFor('video'), 'ohne Kamera gibt es keinen Videosender');
        assert.strictEqual(app.media.transceiverFor('video').direction, 'sendrecv',
            'der Videotransceiver bleibt auf Empfang - die Kamera waere gesperrt');
        ok('der eigene Videozustand geht mit der Begruessung raus');
        app.rtc.endCall(false);
    }

    console.error('\n33) Guide und Zuschauer bekommen verschiedene Oberflaechen');
    {
        // Die Rolle steht als Klasse auf #call-view; alles Rollenabhaengige
        // haengt daran (assets/css/call.css) - es gibt genau eine Stelle, die
        // entscheidet.
        const ansicht = __el('call-view');

        app.control.applyRole('guide');
        assert.ok(ansicht.classList.contains('role-guide'), 'die Guide-Rolle steht nicht an der Ansicht');
        assert.ok(!ansicht.classList.contains('role-viewer'), 'die Zuschauerrolle steht noch daneben');
        assert.strictEqual(__el('control-lock-bar').style.display, 'flex',
            'der Guide sieht den Sperrschalter nicht');
        assert.strictEqual(__el('control-lock-notice').style.display, 'none',
            'der Guide bekommt den Sperrhinweis des Zuschauers');
        assert.strictEqual(__el('btn-forward').disabled, true,
            'der Guide kann steuern - das ist die Aufgabe des Zuschauers');

        app.control.applyRole('viewer');
        assert.ok(ansicht.classList.contains('role-viewer'), 'die Zuschauerrolle steht nicht an der Ansicht');
        assert.ok(!ansicht.classList.contains('role-guide'), 'die Guide-Rolle steht noch daneben');
        assert.strictEqual(__el('control-lock-bar').style.display, 'none',
            'der Zuschauer sieht den Sperrschalter des Guides');

        app.control.applyRole('peer');
        assert.ok(ansicht.classList.contains('role-peer'), 'die Rolle "peer" steht nicht an der Ansicht');
        assert.ok(!ansicht.classList.contains('role-viewer') && !ansicht.classList.contains('role-guide'),
            'im Anruf ohne Fuehrung steht noch eine Fuehrungsrolle daneben');
        assert.strictEqual(__el('control-lock-bar').style.display, 'none',
            'im Anruf ohne Fuehrung gibt es einen Sperrschalter');
        assert.strictEqual(__el('btn-forward').disabled, true,
            'im Anruf ohne Fuehrung laesst sich steuern - dort gibt es nichts zu steuern');

        app.control.applyRole(null);
        assert.ok(!ansicht.classList.contains('role-guide')
            && !ansicht.classList.contains('role-viewer')
            && !ansicht.classList.contains('role-peer'),
            'ohne Rolle bleibt eine Rollenklasse stehen');
        ok('die Rollenklasse schaltet die drei Oberflaechen um');

        // Die Aufteilung selbst steht in der Stilvorlage. Geprueft wird, dass
        // sie da ist: Ohne diese Regeln bekaeme der Guide wieder einen
        // Videobereich fuer ein Bild, das es bei ihm nicht gibt.
        const fsCss = require('fs'), pathCss = require('path');
        const css = fsCss.readFileSync(
            pathCss.join(__dirname, '..', 'assets', 'css', 'call.css'), 'utf8');
        const ohneLeerzeichen = css.replace(/\s+/g, ' ');

        assert.ok(/#call-view\.role-guide #remote-video,[^}]*display: none/.test(ohneLeerzeichen),
            'beim Guide bleibt der Empfangsbereich stehen');
        assert.ok(/#call-view\.role-guide #local-video[^{]*\{[^}]*inset: 0/.test(ohneLeerzeichen),
            'beim Guide fuellt das eigene Bild die Buehne nicht');
        assert.ok(/#call-view:not\(\.role-viewer\) #control-pad/.test(ohneLeerzeichen),
            'das Steuerkreuz ist nicht auf den Zuschauer beschraenkt');
        assert.ok(/#call-view:not\(\.role-guide\) #control-lock-bar/.test(ohneLeerzeichen),
            'der Sperrschalter ist nicht auf den Guide beschraenkt');

        // Der Zuschauer sendet nichts - also hat er auch nichts, womit man
        // sendet: keine Selbstansicht, keine Medienknoepfe, keine
        // Geraeteauswahl. Dasselbe gilt, SOLANGE DIE ROLLE NOCH NICHT DA IST:
        // Sie kommt beim Anrufer erst mit der Antwort auf das Offer, und die
        // Knoepfe erst zu zeigen und gleich wieder wegzunehmen waere das
        // Flackern, das jeder als Fehler liest.
        ['#local-video', '#switch-mic-btn', '#switch-cam-btn', '#call-devices-btn'].forEach(teil => {
            assert.ok(ohneLeerzeichen.includes(
                '#call-view:not(.role-guide):not(.role-peer) ' + teil),
                'beim Zuschauer bleibt "' + teil + '" stehen');
        });
        ok('die Stilvorlage trennt Buehne, Steuerung, Sperrschalter und Medienknoepfe nach Rolle');

        // Ein Markup, nicht zwei: Jede ID gibt es genau einmal. Zwei Vorlagen
        // haetten doppelte IDs bedeutet - genau das ist dieses Projekt gerade
        // erst losgeworden.
        const markup = fsCss.readFileSync(
            pathCss.join(__dirname, '..', 'assets', 'html', 'inner_call_controll.html'), 'utf8');
        const ids = (markup.match(/\sid="([^"]+)"/g) || []).map(t => t.split('"')[1]);
        const doppelt = ids.filter((id, i) => ids.indexOf(id) !== i);
        assert.deepStrictEqual(doppelt, [], 'diese IDs stehen mehrfach im Markup: ' + doppelt.join(', '));
        ['call-view', 'remote-video', 'local-video', 'local-video-placeholder',
         'direction-indicator', 'control-lock-bar', 'control-pad', 'connection-status',
         'btn-look-up', 'btn-look-down', 'chat-unread', 'call-purpose'
        ].forEach(id => {
            assert.ok(ids.includes(id), 'die ID "' + id + '" fehlt in der Call-Ansicht');
        });
        ok('jedes Bauteil steht genau einmal im Markup');
    }

    console.error('\n34) Ein verweigerter Medienzugriff wird gemeldet');
    {
        const abgelehnt = () => {
            const e = new Error('Permission denied');
            e.name = 'NotAllowedError';
            return e;
        };

        // --- Geraetewechsel. Hier stand vorher gar kein try/catch: Die
        //     Ablehnung endete als unbehandelte Promise-Ablehnung in der
        //     Konsole, und der Nutzer sah nichts.
        await callAlsGuide();
        global.__alerts.length = 0;
        global.__mediaErrorFor.video = abgelehnt();
        assert.strictEqual(await app.media.switchDevice('video', 'cam-2'), false,
            'der Wechsel gilt trotz Ablehnung als erfolgt');
        assert.strictEqual(global.__alerts.length, 1, 'genau eine Meldung erwartet');
        assert.ok(/Kamera/.test(global.__alerts[0]), 'die Meldung nennt die Kamera nicht');
        assert.ok(!/Mikrofon/.test(global.__alerts[0]),
            'die Meldung nennt das falsche Geraet: ' + global.__alerts[0]);
        assert.ok(app.media.senderFor('video').track, 'die bisherige Kamera wurde mit abgeraeumt');
        ok('eine abgelehnte Kamera beim Geraetewechsel wird benannt gemeldet');
        app.rtc.endCall(false);

        // --- Beim Annehmen. Eine abgelehnte Kamera beendet das Gespraech
        //     nicht mehr: Der Guide hoert und spricht weiter.
        resetAll();
        medienZuruecksetzen();
        global.__alerts.length = 0;
        app.refs.iceServersLoaded = true;
        app.refs.meteredIceServers = [{ urls: 'stun:x' }];
        app.state.pendingOffer = { sender_id: 7, type: 'offer', sdp: 'sdp-x', role: 'guide' };
        global.__mediaErrorFor.video = abgelehnt();

        await app.rtc.acceptCall({ video: true, audio: true });

        assert.strictEqual(app.state.isCallActive, true, 'der Anruf wurde wegen der Kamera abgebrochen');
        assert.strictEqual(global.__alerts.length, 1, 'genau eine Meldung erwartet');
        assert.ok(/Kamera/.test(global.__alerts[0]), 'die Meldung nennt die Kamera nicht');
        assert.ok(/ohne Bild/.test(global.__alerts[0]),
            'es wird nicht gesagt, wie es weitergeht: ' + global.__alerts[0]);
        assert.ok(app.media.isSending('audio'), 'ohne Kamera geht auch kein Ton raus');
        assert.ok(app.media.senderFor('video'), 'die Kamera bleibt nicht zuschaltbar');
        assert.ok(global.__signals.some(s => s.type === 'answer'), 'es geht keine Antwort raus');
        ok('eine abgelehnte Kamera beendet den Anruf nicht mehr');
        app.rtc.endCall(false);

        // --- Beides abgelehnt: Ohne Ton gibt es kein Gespraech. Genau EINE
        //     Meldung, und der Anrufer wird benachrichtigt.
        resetAll();
        medienZuruecksetzen();
        global.__alerts.length = 0;
        app.refs.iceServersLoaded = true;
        app.refs.meteredIceServers = [{ urls: 'stun:x' }];
        app.state.pendingOffer = { sender_id: 7, type: 'offer', sdp: 'sdp-x', role: 'guide' };
        global.__mediaErrorFor.audio = abgelehnt();
        global.__mediaErrorFor.video = abgelehnt();

        await app.rtc.acceptCall({ video: true, audio: true });

        assert.strictEqual(global.__alerts.length, 1, 'genau eine Meldung erwartet');
        assert.ok(/Mikrofon/.test(global.__alerts[0]), 'die Meldung nennt das Mikrofon nicht');
        assert.strictEqual(app.state.isCallActive, false, 'der Anruf laeuft ohne Medien weiter');
        assert.ok(global.__signals.some(s => s.type === 'call_failed'),
            'der Anrufer erfaehrt nicht, dass es nicht geht');
        ok('ohne Ton wird abgebrochen - mit einer Meldung und einer Nachricht an den Anrufer');
        medienZuruecksetzen();

        // --- Und die Meldung ueberlebt das Neuladen der Seite.
        //     endCall() laedt auf Mobilgeraeten neu. Vorher tat es das starr
        //     nach einer Sekunde - und loeschte damit genau die Meldung, die
        //     den Abbruch erklaerte. Auf einem Telefon war das der Grund,
        //     warum bei verweigertem Zugriff scheinbar nichts kam.
        const echterAgent = global.navigator.userAgent;
        const echteLocation = global.location;
        let neugeladenNach = null;
        const echterTimeout = global.setTimeout;
        global.navigator.userAgent = 'Mozilla/5.0 (Linux; Android 14)';
        global.location = { reload() {} };
        global.setTimeout = (fn, ms) => {
            // Nur den Neulade-Zeitgeber abfangen, alle anderen normal laufen
            // lassen - sonst stuende der Rest der Pruefungen still.
            if (String(fn).includes('reload')) { neugeladenNach = ms; return 0; }
            return echterTimeout(fn, ms);
        };

        resetAll();
        app.notify.toastUntil = 0;
        app.state.isCallActive = true;
        app.rtc.abortCall('Der Zugriff auf die Kamera wurde abgelehnt.');

        global.setTimeout = echterTimeout;
        global.navigator.userAgent = echterAgent;
        if (echteLocation === undefined) delete global.location; else global.location = echteLocation;

        assert.ok(neugeladenNach !== null, 'auf dem Telefon wird gar nicht mehr neu geladen');
        assert.ok(neugeladenNach > 4000,
            'die Seite laedt nach ' + neugeladenNach + ' ms neu - die Meldung ist dann weg');
        assert.strictEqual(global.__alerts[global.__alerts.length - 1],
            'Der Zugriff auf die Kamera wurde abgelehnt.', 'die Meldung fehlt');
        ok('der Neuaufbau wartet, bis die Meldung gelesen werden konnte');
    }

    console.error('\n35) Das Bild der Gegenseite kommt an, auch ohne msid');
    {
        // HIER LAG DIE SCHWARZE FLAECHE. Der Guide legt seine Spuren mit
        // replaceTrack an die bereits ausgehandelten Sender - und replaceTrack
        // ordnet dem Sender KEINEN MediaStream zu. Im SDP fehlt dann die
        // msid, und beim Zuschauer kommt das ontrack-Ereignis OHNE Strom an.
        // Die alte Stelle wertete nur event.streams[0] aus, setzte srcObject
        // also nie - Bild schwarz, und still war es auch, denn am selben
        // Element haengt der Ton.
        await callAlsZuschauer();
        const video = __el('remote-video');
        video.srcObject = null;

        const bildspur = { kind: 'video', muted: false, onmute: null, onunmute: null, onended: null };
        const tonspur  = { kind: 'audio', muted: false };
        app.refs.localPeerConnection.ontrack({ track: tonspur,  streams: [] });
        app.refs.localPeerConnection.ontrack({ track: bildspur, streams: [] });

        assert.ok(video.srcObject, 'ohne Strom im Ereignis bleibt das Videoelement leer');
        const arten = video.srcObject.getTracks().map(t => t.kind).sort();
        assert.deepStrictEqual(arten, ['audio', 'video'],
            'im selbst gefuehrten Strom fehlen Spuren: ' + arten.join(','));
        assert.strictEqual(__el('remote-video').style.display, 'block',
            'das eingetroffene Bild wird nicht angezeigt');
        ok('ohne Strom im Ereignis wird selbst einer gefuehrt');

        // Bringt das Ereignis einen Strom mit, wird der genommen - so wie
        // bisher. Der Notbehelf ist die zweite Ebene, nicht die erste.
        const eigener = makeStream([{ kind: 'video' }]);
        app.refs.localPeerConnection.ontrack({ track: eigener.getTracks()[0], streams: [eigener] });
        assert.strictEqual(video.srcObject, eigener, 'der mitgelieferte Strom wird nicht benutzt');
        ok('ein mitgelieferter Strom hat weiterhin Vorrang');

        // Und er ueberlebt den Call nicht: Sonst haengt im naechsten eine
        // tote Spur im Videoelement.
        app.rtc.endCall(false);
        assert.strictEqual(app.refs.remoteStream, null, 'der Strom der Gegenseite bleibt liegen');
        ok('der Strom der Gegenseite wird beim Auflegen abgeraeumt');

        // Die Sendeseite meldet ihren Strom jetzt ausdruecklich an, damit die
        // msid im SDP steht - ein fremder Client (die spaetere App) soll auf
        // den Notbehelf nicht angewiesen sein.
        await callAlsGuide();
        const sender = app.media.senderFor('video');
        assert.ok(sender.__streams, 'der Sender kennt seinen Strom nicht (setStreams fehlt)');
        assert.ok(sender.__streams.getVideoTracks().length > 0,
            'der angemeldete Strom traegt keine Videospur');
        ok('die Sendeseite meldet ihren Strom am Sender an');
        app.rtc.endCall(false);
    }

    console.error('\n36) Ungelesene Chatnachrichten sind am Knopf zu sehen');
    {
        // Das Chatblatt liegt im Call zugeklappt ueber dem Bild. Kam eine
        // Nachricht an, waehrend es zu war, geschah sichtbar nichts.
        await callAlsZuschauer();
        const badge = __el('chat-unread');
        const knopf = __el('chat-toggle-btn');
        __el('chat-overlay').hidden = true;
        app.chat.clearUnread();

        assert.strictEqual(badge.hidden, true, 'der Zaehler steht ohne Nachricht schon da');

        app.chat.handleMessage(JSON.stringify({ v: P.VERSION, type: 'chat', text: 'Hallo' }));
        app.chat.handleMessage(JSON.stringify({ v: P.VERSION, type: 'chat', text: 'Noch was' }));
        assert.strictEqual(badge.hidden, false, 'der Zaehler bleibt unsichtbar');
        assert.strictEqual(badge.textContent, '2', 'der Zaehler steht auf ' + badge.textContent);
        assert.ok(knopf.classList.contains('has-unread'), 'der Knopf ist nicht hervorgehoben');
        ok('eine Nachricht bei geschlossenem Chat wird am Knopf angezeigt');

        // Offener Chat: Es liest ja jemand mit.
        __el('chat-overlay').hidden = false;
        app.chat.clearUnread();
        app.chat.handleMessage(JSON.stringify({ v: P.VERSION, type: 'chat', text: 'Gelesen' }));
        assert.strictEqual(badge.hidden, true, 'bei offenem Chat wird trotzdem gezaehlt');
        assert.ok(!knopf.classList.contains('has-unread'), 'der Knopf bleibt hervorgehoben');
        ok('bei offenem Chat wird nichts gezaehlt');

        // Eine eigene Nachricht ist keine ungelesene.
        __el('chat-overlay').hidden = true;
        app.chat.send('Von mir');
        assert.strictEqual(badge.hidden, true, 'die eigene Nachricht wird als ungelesen gezaehlt');
        ok('die eigene Nachricht zaehlt nicht mit');

        // Und beim Auflegen ist Schluss - nichts laeuft in den naechsten Call.
        app.chat.handleMessage(JSON.stringify({ v: P.VERSION, type: 'chat', text: 'Letzte' }));
        assert.strictEqual(badge.hidden, false, 'nichts gezaehlt');
        app.rtc.endCall(false);
        assert.strictEqual(app.chat.unread, 0, 'der Zaehler laeuft in den naechsten Call');
        assert.strictEqual(badge.hidden, true, 'der Zaehler bleibt nach dem Auflegen stehen');
        ok('das Auflegen leert den Zaehler');

        // hidden allein genuegt im Browser NICHT: Das display der Klasse
        // schlaegt die eingebaute Regel fuer [hidden], und dann stuende dort
        // dauerhaft eine Null. Dieselbe Zeile brauchen .call-sheet und
        // .call-chat-overlay aus demselben Grund.
        const cssBadge = require('fs').readFileSync(
            require('path').join(__dirname, '..', 'assets', 'css', 'call.css'), 'utf8');
        assert.ok(/\.call-btn__badge\[hidden\]\s*\{[^}]*display:\s*none/.test(cssBadge),
            'ohne .call-btn__badge[hidden] bleibt der Zaehler sichtbar');
        ok('der ausgeblendete Zaehler ist auch im Browser weg');
    }

    console.error('\n37) Blickrichtung hoch und runter');
    {
        // look_up.mp3 und look_down.mp3 lagen ungenutzt im Projekt. Blick und
        // Bewegung laufen als dieselbe Nachricht "move": Fuer den Guide ist
        // beides eine Anweisung, ein Ton, ein Pfeil - und Sequenznummer,
        // Bestaetigung und Sperre gelten unveraendert.
        assert.ok(P.DIRECTIONS.includes('look_up') && P.DIRECTIONS.includes('look_down'),
            'die Blickrichtungen stehen nicht im Protokoll');
        assert.strictEqual(P.VERSION, 2, 'die Protokollversion wurde nicht erhoeht');

        // Beim Zuschauer: Es gibt eine Taste, und sie schickt die Richtung.
        fakeActiveCall({ role: 'viewer' });
        assert.ok(app.control.ARROW_BUTTON_IDS.includes('btn-look-up'),
            'die Taste "Blick hoch" ist nicht gebunden');
        assert.strictEqual(__el('btn-look-up').disabled, false,
            'die Blicktaste ist beim Zuschauer gesperrt');
        assert.strictEqual(app.control.sendMove('look_up'), true, 'look_up ging nicht raus');
        const raus = JSON.parse(app.refs.controlChannel.sent.pop());
        assert.strictEqual(raus.type, 'move', 'die Blickanweisung ist keine move-Nachricht');
        assert.strictEqual(raus.dir, 'look_up', 'die Richtung fehlt');
        assert.strictEqual(raus.v, P.VERSION, 'ohne Protokollversion');
        ok('der Zuschauer schickt die Blickrichtung als move');

        // Beim Guide: Ton und Anzeige. Die Anzeige unterscheidet sich sichtbar
        // von "vorwaerts" - zwei gleiche Pfeile mit verschiedener Bedeutung
        // waeren auf einem Display, auf das jemand im Gehen kurz schaut, der
        // schlechteste Fall.
        fakeActiveCall({ initiator: false, role: 'guide' });
        app.state.connectedSince = Date.now() - 10000;
        app.sound.plays.length = 0;
        receiveControl({ v: P.VERSION, type: 'move', dir: 'look_down', seq: 1 });
        assert.ok(app.sound.plays.includes('look_down_sound'),
            'das Tonsignal fuer "Blick runter" wurde nicht abgespielt');
        assert.strictEqual(__el('direction-indicator-label').textContent, 'BLICK RUNTER',
            'die Richtungsanzeige nennt die Blickrichtung nicht');
        assert.notStrictEqual(__el('direction-indicator-arrow').textContent,
            app.control.DIRECTIONS.backward.arrow,
            'Blick runter und Rueckwaerts zeigen denselben Pfeil');
        const bestaetigung = JSON.parse(app.refs.controlChannel.sent.pop());
        assert.strictEqual(bestaetigung.status, 'executed', 'die Blickanweisung wurde nicht bestaetigt');
        ok('der Guide hoert und sieht die Blickrichtung und bestaetigt sie');

        // Jede Richtung im Protokoll hat auch eine Anzeige - sonst wuerde
        // handleMove() beim Zugriff auf DIRECTIONS[msg.dir] werfen.
        P.DIRECTIONS.forEach(dir => {
            assert.ok(app.control.DIRECTIONS[dir], 'zur Richtung "' + dir + '" fehlt die Anzeige');
            assert.ok(app.control.DIRECTIONS[dir].sound, 'zur Richtung "' + dir + '" fehlt der Ton');
        });
        ok('zu jeder Richtung des Protokolls gibt es Ton und Anzeige');
        app.rtc.endCall(false);
    }

    console.error('\n38) Der Anruf sagt, von welchem Standort er ausgeht');
    {
        // WOHER DER ANRUF KOMMT, ENTSCHEIDET UEBER DIE ROLLEN. Karte und
        // Standortliste rufen von einem Ort aus an - dann fuehrt der
        // Angerufene, auch wenn er Admin ist. Die Benutzerverwaltung ruft
        // eine Person an und gibt keinen Ort mit; daraus wird mit einem Admin
        // ein Gespraech ohne Fuehrung. Entschieden wird das im Server, hier
        // wird die Kennung nur durchgereicht - aber wenn sie unterwegs
        // verloren geht, faellt jede Fuehrung ueber einen Admin-Standort um.
        resetAll();
        global.__offerRole = 'viewer';
        app.refs.iceServersLoaded = true;
        app.refs.meteredIceServers = [{ urls: 'stun:x' }];

        await app.rtc.startCall(42, 7);
        const mitOrt = global.__signals.find(s => s.type === 'offer');
        assert.strictEqual(mitOrt.location, 7, 'die Standortkennung fehlt im Offer');
        assert.strictEqual(typeof mitOrt.location, 'number', 'die Kennung geht als Text raus');
        app.rtc.endCall(false);
        ok('ein Anruf von einem Standort schickt dessen Kennung mit');

        // Ohne Standort steht das Feld GAR NICHT im Offer - eine 0 oder ein
        // leerer Wert waere eine Angabe, die keine ist.
        for (const nichts of [undefined, null, 0, '', 'abc']) {
            resetAll();
            global.__offerRole = 'peer';
            app.refs.iceServersLoaded = true;
            app.refs.meteredIceServers = [{ urls: 'stun:x' }];

            await app.rtc.startCall(42, nichts);
            const offer = global.__signals.find(s => s.type === 'offer');
            assert.ok(!('location' in offer),
                'aus ' + JSON.stringify(nichts) + ' wurde eine Standortangabe');
            app.rtc.endCall(false);
        }
        ok('ohne brauchbaren Standort bleibt das Feld weg');

        // Die Standortliste haengt die Kennung an ihren Anrufknopf. Ohne sie
        // wuesste der Klickhandler nicht, von welchem Ort aus angerufen wird.
        const zelle = app.locationsTable.actionCellHtml(
            { id: 7, user_id: 3, user_status: 'online', blocked: 0,
              country_name: 'Portugal', city_name: 'Lissabon', description: 'Alfama.' },
            { showActions: ['call'] });
        assert.ok(/start-call-btn[^>]*data-locationid="7"/.test(zelle),
            'am Anrufknopf der Standortliste fehlt die Standortkennung:\n' + zelle);
        ok('der Anrufknopf der Standortliste traegt seinen Standort');

        // Die Karte ebenso - und die Benutzerverwaltung ausdruecklich NICHT:
        // Sie ruft eine Person an, nicht einen Ort.
        const fsQ = require('fs'), pathQ = require('path');
        const karte = fsQ.readFileSync(pathQ.join(__dirname, '..', 'assets', 'js', 'home_map.js'), 'utf8');
        assert.ok(/home-call-btn[\s\S]{0,200}data-locationid=/.test(karte),
            'am Anrufknopf der Karte fehlt die Standortkennung');
        const benutzerliste = fsQ.readFileSync(
            pathQ.join(__dirname, '..', 'class', 'Controller', 'UserController.php'), 'utf8');
        assert.ok(!/start-call-btn[\s\S]{0,400}data-locationid/.test(benutzerliste),
            'die Benutzerverwaltung schickt einen Standort mit - dann waere sie kein Direktanruf');
        ok('die Karte gibt ihren Standort mit, die Benutzerverwaltung bewusst nicht');
    }

    console.error('\n39) Ein Anruf der Administration ist als solcher zu erkennen');
    {
        // Der Angerufene sah bisher nur "X ruft an" und wartete danach auf ein
        // Steuerkreuz, das es in dieser Rolle gar nicht gibt. Erkannt wird der
        // Fall an der ROLLE - nicht an einer zweiten Pruefung auf das Konto
        // des Anrufers. Die Rolle vergibt der Server, sie haengt am Offer und
        // steht damit schon fest, BEVOR angenommen wird.
        const dialog  = __el('media-select-dialog');
        const ansicht = __el('call-view');

        resetAll();
        await app.signaling.handleSignalingData(
            { type: 'offer', sender_id: 7, sdp: 'sdp-x', role: 'peer' });

        assert.strictEqual(app.state.callRole, 'peer',
            'die Rolle des Offers gilt erst nach dem Annehmen');
        assert.ok(dialog.classList.contains('role-peer'),
            'der Annahmedialog traegt die Rolle nicht');
        ok('die Rolle steht schon im Annahmedialog fest');

        // Und im laufenden Call bleibt sie stehen: Die Frage "warum kann ich
        // nichts steuern" stellt sich nicht nur in der ersten Sekunde.
        app.state.pendingOffer = { sender_id: 7, type: 'offer', sdp: 'sdp-x', role: 'peer' };
        app.refs.iceServersLoaded = true;
        app.refs.meteredIceServers = [{ urls: 'stun:x' }];
        await app.rtc.acceptCall({ video: true, audio: true });
        assert.ok(ansicht.classList.contains('role-peer'),
            'die Call-Ansicht traegt die Rolle nicht');
        assert.strictEqual(__el('btn-forward').disabled, true,
            'im Anruf der Administration laesst sich steuern');
        app.rtc.endCall(false);
        assert.ok(!dialog.classList.contains('role-peer'),
            'nach dem Auflegen haengt die Rolle noch am Dialog');
        ok('im laufenden Call steht sie ebenso, und das Auflegen raeumt sie ab');

        // Eine Fuehrung bekommt den Hinweis NICHT - sonst waere er wertlos.
        resetAll();
        await app.signaling.handleSignalingData(
            { type: 'offer', sender_id: 7, sdp: 'sdp-x', role: 'guide' });
        assert.ok(dialog.classList.contains('role-guide') && !dialog.classList.contains('role-peer'),
            'die Fuehrung wird als Anruf der Administration ausgewiesen');

        // Ohne Rolle wird nichts behauptet.
        resetAll();
        await app.signaling.handleSignalingData({ type: 'offer', sender_id: 7, sdp: 'sdp-x' });
        assert.ok(!dialog.classList.contains('role-peer') && !dialog.classList.contains('role-guide'),
            'ohne Rolle steht trotzdem eine am Dialog');
        ok('ohne die Rolle "peer" bleibt der Hinweis weg');

        // Legt der Anrufer auf, bevor angenommen wurde, faellt die Rolle mit
        // dem Offer - sonst stuende sie noch am Dialog, wenn der naechste
        // Anruf kommt.
        resetAll();
        await app.signaling.handleSignalingData(
            { type: 'offer', sender_id: 7, sdp: 'sdp-x', role: 'peer' });
        app.rtc.handleRemoteHangup(7);
        assert.strictEqual(app.state.callRole, null, 'die Rolle ueberlebt das Auflegen');
        assert.ok(!dialog.classList.contains('role-peer'), 'die Klasse bleibt am Dialog stehen');
        ok('ein zurueckgezogener Anruf nimmt seine Rolle mit');

        // Der Text selbst: Er muss die Administration benennen UND sagen,
        // dass nicht gesteuert wird. Nur eines von beidem beantwortet die
        // Frage nicht, um die es geht.
        const fsA = require('fs'), pathA = require('path');
        const lies = (...teile) => fsA.readFileSync(pathA.join(__dirname, '..', ...teile), 'utf8');
        [['assets', 'html', 'call_controll.html'], ['assets', 'html', 'inner_call_controll.html']]
            .forEach(datei => {
                const markup = lies(...datei);
                assert.ok(/Anruf der Administration/.test(markup),
                    datei.join('/') + ': der Hinweis nennt die Administration nicht');
                assert.ok(/nicht gesteuert/.test(markup),
                    datei.join('/') + ': der Hinweis sagt nicht, dass nicht gesteuert wird');
            });
        assert.ok(lies('assets', 'html', 'call_controll.html').includes('id="call-invite-purpose"'),
            'im Annahmedialog fehlt der Hinweis');
        ok('der Hinweis nennt die Administration und die fehlende Steuerung');

        // Sichtbar allein ueber die Rollenklasse - keine style-Zuweisung
        // irgendwo im Code, die daneben mitreden koennte.
        const css = lies('assets', 'css', 'call.css').replace(/\s+/g, ' ');
        assert.ok(css.includes('#call-view:not(.role-peer) #call-purpose'),
            'der Hinweis im Call haengt nicht an der Rolle');
        assert.ok(css.includes('#media-select-dialog:not(.role-peer) #call-invite-purpose'),
            'der Hinweis im Dialog haengt nicht an der Rolle');
        const js = lies('assets', 'js', 'control.js') + lies('assets', 'js', 'signaling.js')
                 + lies('assets', 'js', 'rtc.js') + lies('assets', 'js', 'main.js');
        assert.ok(!/call-(invite-)?purpose/.test(js),
            'der Hinweis wird irgendwo im Code direkt angefasst - er gehoert der Rollenklasse');
        ok('sichtbar wird der Hinweis allein ueber die Rollenklasse');
    }

    console.error('\n' + passed + ' Pruefungen bestanden.');
    process.exit(0);
})().catch(e => { console.error('\nFEHLGESCHLAGEN:', e.message, '\n', e.stack); process.exit(1); });
