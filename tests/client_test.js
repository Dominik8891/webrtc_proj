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
        pendingOffer: null, tracksAdded: false, isInitiator: false,
        connectionStatus: 'idle', connectedSince: null, callTimeout: null
    });
    app.refs.localPeerConnection = null;
    app.refs.chatChannel = null;
    app.refs.controlChannel = null;
    app.refs.localStream = null;
    app.refs.pendingCandidates = [];
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

    console.error('\n' + passed + ' Pruefungen bestanden.');
    process.exit(0);
})().catch(e => { console.error('\nFEHLGESCHLAGEN:', e.message, '\n', e.stack); process.exit(1); });
