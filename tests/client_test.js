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

function resetAll() {
    app.rtc.stopReconnect();
    app.signaling.stopPolling();
    Object.assign(app.state, {
        activeTargetUserId: null, hangupReceived: false, isCallActive: false,
        pendingOffer: null, tracksAdded: false, isInitiator: false,
        connectionStatus: 'idle', connectedSince: null, callTimeout: null
    });
    app.refs.localPeerConnection = null;
    app.refs.dataChannel = null;
    app.refs.localStream = null;
    app.refs.pendingCandidates = [];
    global.__signals.length = 0;
    global.__alerts.length = 0;
}

/** Baut einen "laufenden Call" auf, ohne die Media-Pfade zu benötigen. */
function fakeActiveCall({ initiator = true } = {}) {
    resetAll();
    app.refs.iceServersLoaded = true;
    app.refs.meteredIceServers = [{ urls: 'stun:x' }];
    app.state.activeTargetUserId = 42;
    app.state.isInitiator = initiator;
    app.state.isCallActive = true;
    app.rtc.createPeerConnection(initiator);
    app.refs.dataChannel = makeChannel();
    const pc = app.refs.localPeerConnection;
    pc.setState('connected');
    return pc;
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
        const dc = app.refs.dataChannel;
        app.rtc.endCall(true);
        assert.ok(dc.sent.includes('__hangup__'), 'ueber den DataChannel');
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
        const pc = fakeActiveCall();
        await sleep(60);                            // Settle-Fenster abwarten
        const dc = app.refs.dataChannel;
        assert.strictEqual(app.rtc.sendControlCommand('forward'), true);
        assert.deepStrictEqual(dc.sent, ['__arrow_forward__']);
        ok('bei stabiler Verbindung wird gesendet');

        pc.setState('disconnected');
        assert.strictEqual(app.rtc.sendControlCommand('left'), false);
        assert.strictEqual(app.rtc.sendControlCommand('right'), false);
        assert.deepStrictEqual(dc.sent, ['__arrow_forward__'], 'nichts nachgeschoben');
        ok('waehrend der Stoerung wird verworfen statt gepuffert');

        pc.setState('connected');
        await sleep(60);
        assert.strictEqual(app.rtc.sendControlCommand('backward'), true);
        assert.deepStrictEqual(dc.sent, ['__arrow_forward__', '__arrow_backward__'],
            'kein Schwall alter Befehle nach dem Reconnect');
        ok('nach der Erholung nur der neue Befehl');
    }

    // -----------------------------------------------------------------
    console.error('\n11) Voller Sendepuffer blockiert Steuerbefehle');
    {
        const pc = fakeActiveCall();
        await sleep(60);
        app.refs.dataChannel.bufferedAmount = app.rtc.CONTROL_MAX_BUFFER + 1;
        assert.strictEqual(app.rtc.sendControlCommand('forward'), false);
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

    console.error('\n' + passed + ' Pruefungen bestanden.');
    process.exit(0);
})().catch(e => { console.error('\nFEHLGESCHLAGEN:', e.message, '\n', e.stack); process.exit(1); });
