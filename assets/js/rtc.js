/**
 * WebRTC-Modul: Beinhaltet alle Funktionen rund um PeerConnection, Anrufsteuerung und Medienstrom.
 */
window.webrtcApp.rtc = {
    // ---------------------------------------------------------------------
    // Zeitkonstanten der Wiederverbindung
    // ---------------------------------------------------------------------

    // "disconnected" ist ein vorübergehender Zustand: Er tritt bei jedem
    // WLAN-/Mobilfunkwechsel und bei kurzen Paketverlusten auf und geht meist
    // von selbst zurück auf "connected". Deshalb wird erst nach Ablauf dieser
    // Frist neu ausgehandelt statt sofort aufzulegen.
    RECONNECT_GRACE_MS: 5000,

    // Gesamtfrist ab der ersten Störung. Ist die Verbindung bis dahin nicht
    // zurück, wird der Call endgültig beendet.
    RECONNECT_DEADLINE_MS: 30000,

    // Höchstzahl an ICE-Restart-Versuchen je Störung.
    MAX_ICE_RESTARTS: 3,

    // Abstand zwischen zwei Restart-Versuchen. Ein ICE-Restart braucht ein
    // paar Sekunden, bis die neuen Kandidaten ausgetauscht sind.
    RESTART_RETRY_MS: 8000,

    // Nach einer Wiederverbindung werden Steuerbefehle für diese Zeitspanne
    // noch verworfen. Grund: Der DataChannel ist zuverlässig und geordnet -
    // was während der Störung im SCTP-Puffer der Gegenseite lag, wird beim
    // Wiederanlaufen auf einen Schlag zugestellt. Ein Schwall alter
    // Richtungsbefehle ist für den Guide gefährlich; deshalb werden sie
    // verworfen und nicht ausgeführt.
    CONTROL_SETTLE_MS: 1000,

    // Obergrenze für den Sendepuffer des DataChannels, ab der keine
    // Steuerbefehle mehr angenommen werden. Staut sich etwas, ist der Kanal
    // nicht mehr in Echtzeit - dann darf nichts nachgeschoben werden.
    CONTROL_MAX_BUFFER: 4096,

    /**
     * Beendet einen aktiven Call und räumt alles auf (UI, Streams, PeerConnection, Chat etc.)
     * @param {boolean} sendSignal - Soll ein Hangup-Signal an den Partner gesendet werden?
     */
    endCall(sendSignal = true) {
        // Auflegen ZUERST senden, solange DataChannel und Signaling noch
        // stehen. Vorher wurde erst die PeerConnection geschlossen und danach
        // gesendet - der DataChannel war zu diesem Zeitpunkt bereits tot.
        if (sendSignal && !window.webrtcApp.state.hangupReceived
            && window.webrtcApp.state.activeTargetUserId) {
            this.sendHangup();
        }
        window.webrtcApp.state.hangupReceived = true;

        // Ab hier läuft der Abbau. Das Flag muss VOR dem Schließen der
        // PeerConnection fallen, sonst deutet handleConnectionStateChange()
        // den selbst ausgelösten "closed"-Zustand als Störung und ruft endCall
        // erneut auf.
        window.webrtcApp.state.isCallActive = false;

        this.stopReconnect();        // Alle Wiederverbindungs-Timer stoppen
        window.webrtcApp.control.reset();  // Rolle, Sequenznummern, Sperre, Anzeige
        this.resetUI();              // Entfernt UI-Call-Status
        this.closePeerConnection();  // PeerConnection & DataChannels schließen
        this.clearMediaStreams();    // Lokalen & Remote MediaStream beenden
        this.hideChatAndArrow();     // Chat/Arrow-Bereich verstecken

        // State zurücksetzen
        window.webrtcApp.state.tracksAdded = false;
        window.webrtcApp.state.activeTargetUserId = null;
        window.webrtcApp.state.hangupReceived = false;
        window.webrtcApp.state.pendingOffer = null;
        window.webrtcApp.state.isInitiator = false;
        window.webrtcApp.state.connectedSince = null;
        window.webrtcApp.refs.pendingCandidates = [];
        window.webrtcApp.uiRtc.setEndCallButtonVisible(false);
        window.webrtcApp.rtc.setConnectionStatus('idle');
        window.webrtcApp.uiChat.updatePollingState();

        // Polling zurück auf das schnellere Ruhe-Intervall: Wir warten wieder
        // auf eingehende Anrufe.
        window.webrtcApp.signaling.setPollInterval(window.webrtcApp.signaling.POLL_INTERVAL_IDLE);

        // Mobile Browser fix: reload nach Call-Ende
        if (/Android|iPhone|iPad|iPod|Mobile|Linux/i.test(navigator.userAgent)) {
            setTimeout(() => location.reload(), 1000);
        }
    },

    /**
     * Sendet das Auflegen auf beiden Wegen.
     *
     * Weg 1 ist der DataChannel: Er erreicht den Gegenüber sofort und ohne
     * Umweg über den Server. Weg 2 ist das HTTP-Signaling als Rückfallebene
     * für den Fall, dass der Kanal schon tot ist - genau der Fall, in dem das
     * Auflegen bisher nie ankam (Befund F-2/F-3).
     *
     * Beide Wege dürfen gefahrlos gleichzeitig laufen: Der Empfänger wertet
     * das Auflegen in handleRemoteHangup() nur einmal aus.
     */
    sendHangup() {
        // Über den Steuerkanal, nicht mehr über den Chatkanal: Auflegen ist
        // eine Protokollnachricht, kein Nutzerinhalt.
        window.webrtcApp.control.sendHangup();

        if (window.webrtcApp.state.activeTargetUserId) {
            window.webrtcApp.signaling.sendSignalMessage({
                type: 'hangup',
                target: window.webrtcApp.state.activeTargetUserId
            });
        }
    },

    /**
     * Verarbeitet ein Auflegen des Gegenübers - egal über welchen Weg es kam.
     *
     * Die Methode ist bewusst idempotent: Kommt dasselbe Auflegen erst über
     * den DataChannel und Sekunden später noch einmal über das Polling, wird
     * der zweite Aufruf verworfen (kein Call mehr aktiv, kein passendes
     * pendingOffer) - der Nutzer sieht nur eine Meldung.
     *
     * @param {number|string} [senderId] - Absender laut Signal (nur beim Server-Weg gesetzt)
     */
    handleRemoteHangup(senderId) {
        const state = window.webrtcApp.state;
        this.stopTimeout();

        if (state.isCallActive === true) {
            state.hangupReceived = true;
            this.setConnectionStatus('disconnected');
            window.webrtcApp.sound.stop('call_ringtone');
            const acceptBtn = document.getElementById('accept-call-btn');
            if (acceptBtn) acceptBtn.style.display = "none";
            window.webrtcApp.uiRtc.setEndCallButtonVisible(false);
            // Der Hinweis blockiert nicht mehr, also wird die Call-Ansicht
            // sofort abgebaut. Der Hinweis bleibt trotzdem stehen - er liegt
            // ueber der Seite, die danach zu sehen ist, und verschwindet von
            // selbst. Vorher hielt ein alert() die Ansicht mit dem roten
            // Verbindungsstatus so lange offen, bis jemand klickte.
            window.webrtcApp.notify.error('Der andere Teilnehmer hat die Verbindung beendet.');
            // sendSignal=false: Wir antworten nicht mit einem eigenen Hangup.
            this.endCall(false);
            return;
        }

        // Kein aktiver Call: Der Anrufer hat aufgelegt, bevor angenommen wurde.
        // Null-Prüfung auf pendingOffer, sonst wirft der Zugriff (Befund F-11).
        const pending = state.pendingOffer;
        if (pending && (senderId === undefined || pending.sender_id == senderId)) {
            const dialog = document.getElementById('media-select-dialog');
            if (dialog) dialog.style.display = 'none';
            window.webrtcApp.sound.stop('incomming_call_ringtone');
            state.pendingOffer = null;
        }
    },

    /**
     * Entfernt Call-spezifische UI-Elemente.
     */
    resetUI() {
        document.body.classList.remove('call-active');
        const callView = document.getElementById('call-view');
        if (callView) callView.style.display = 'none';
    },

    /**
     * Schließt die RTCPeerConnection und den DataChannel.
     */
    closePeerConnection() {
        const refs = window.webrtcApp.refs;
        if (refs.localPeerConnection) {
            // Handler abhängen, damit der Abbau keine Zustandsauswertung mehr
            // auslöst.
            refs.localPeerConnection.onconnectionstatechange = null;
            refs.localPeerConnection.oniceconnectionstatechange = null;
            refs.localPeerConnection.onicecandidate = null;
            refs.localPeerConnection.onicecandidateerror = null;
            refs.localPeerConnection.ondatachannel = null;
            refs.localPeerConnection.ontrack = null;
            refs.localPeerConnection.close();
            refs.localPeerConnection = null;
        }
        ['chatChannel', 'controlChannel'].forEach(key => {
            if (!refs[key]) return;
            try { refs[key].close(); } catch (e) {}
            refs[key] = null;
        });
    },

    /**
     * Beendet alle lokalen Media-Tracks (Webcam/Mic) und setzt Video-Elemente zurück.
     */
    clearMediaStreams() {
        const refs = window.webrtcApp.refs;
        if (refs.localStream) {
            refs.localStream.getTracks().forEach(track => {
                try { track.stop(); } catch(e) {}
            });
            refs.localStream = null;
        }
        const localVideo = document.getElementById('local-video');
        if (localVideo) localVideo.srcObject = null;
        const remoteVideo = document.getElementById('remote-video');
        if (remoteVideo) remoteVideo.srcObject = null;
    },

    /**
     * Versteckt Chat und Steuerungs-Pfeile.
     */
    hideChatAndArrow() {
        const chatArea = document.getElementById('chat-area');
        if (chatArea) chatArea.style.display = 'none';
        // Das Steuerkreuz haengt an der Rollenklasse auf #call-view; die
        // entfernt control.reset(). Der frueher hier abgefragte
        // 'arrow-control' war eine ID, die es im HTML nie gab (Befund F-4).
    },

    /**
     * Startet einen neuen Call mit dem angegebenen Ziel-User.
     * @param {number} targetUserId - Die ID des Gesprächspartners
     */
    startCall: async function(targetUserId) {
        // Wir bauen den Call auf, also sind wir der Initiator und damit im
        // Störungsfall für den ICE-Restart zuständig.
        window.webrtcApp.state.isInitiator = true;
        window.webrtcApp.rtc.setConnectionStatus('connecting');
        // Während des Calls langsamer pollen, aber weiterpollen - der Weg wird
        // für Auflegen und ICE-Restart gebraucht.
        window.webrtcApp.signaling.setPollInterval(window.webrtcApp.signaling.POLL_INTERVAL_IN_CALL);

        // 1. ICE-Server laden (für PeerConnection)
        //    Bei einer Notfallliste (kein TURN) wird erneut geladen, damit ein
        //    vorübergehender Ausfall des TURN-Dienstes nicht dauerhaft
        //    zwischengespeichert bleibt (Befund F-18).
        if (!window.webrtcApp.refs.iceServersLoaded || window.webrtcApp.refs.iceServersDegraded) {
            await window.webrtcApp.rtc.loadIceServers();
        }
        // 2. PeerConnection-Init (Dummy/Selbstanruf für Chrome-Bugfix)
        await window.webrtcApp.rtc.initFakeSelfCall();
        window.webrtcApp.state.activeTargetUserId = targetUserId;
        window.webrtcApp.state.targetUsername     = await window.webrtcApp.uiRtc.getUsername(targetUserId);

        // 3. Medien holen (Webcam/Mikro)
        navigator.mediaDevices.getUserMedia({ video: true, audio: true })
            .then(stream => {
                window.webrtcApp.refs.localStream = stream;
                updateCallIcons();
                document.getElementById('local-video').srcObject = stream;
                window.webrtcApp.rtc.createPeerConnection(true);
                window.webrtcApp.sound.play('call_ringtone');
                return new Promise(resolve => setTimeout(resolve, 100));
            })
            .then(() => {
                window.webrtcApp.rtc.addLocalTracks();
                updateCallIcons();
                return window.webrtcApp.refs.localPeerConnection.createOffer();
            })
            .then(offer => {
                return window.webrtcApp.refs.localPeerConnection.setLocalDescription(offer).then(() => offer);
            })
            .then(offer => {
                return window.webrtcApp.signaling.sendSignalMessage({
                    type: 'offer',
                    sdp: offer.sdp,
                    target: targetUserId
                });
            })
            .then(response => {
                // Die Rolle in diesem Call vergibt der Server (siehe
                // WebRTCController::roleForCall). Sie haengt an der Antwort
                // auf das Offer, damit es dafuer keine zweite Anfrage und
                // kein Zeitfenster ohne Rolle braucht. Bleibt sie aus, gilt
                // die Rolle als unbekannt - dann steuert niemand.
                window.webrtcApp.control.applyRole(response ? response.role : null);
            })
            .catch(console.error);

        window.webrtcApp.uiRtc.setEndCallButtonVisible(true);
        window.webrtcApp.state.isCallActive = true;
        window.webrtcApp.uiChat.updatePollingState();
        document.body.classList.add('call-active');
        document.getElementById('call-view').style.display = '';
        console.log('Geladener Username:', window.webrtcApp.state.targetUsername);
        document.getElementById('remote-username').textContent = 'Rufe ' + window.webrtcApp.state.targetUsername + ' an';

        window.webrtcApp.rtc.startTimeout();
    },

    /**
     * Erstellt eine neue RTCPeerConnection und setzt Eventhandler.
     * @param {boolean} isInitiator - True: wir rufen an, False: wir nehmen an
     */
    createPeerConnection(isInitiator = false) {
        // ICE-Server müssen vorher geladen sein!
        if (!window.webrtcApp.refs.iceServersLoaded) {
            setTimeout(() => window.webrtcApp.rtc.createPeerConnection(isInitiator), 200);
            return;
        }
        if (window.webrtcApp.refs.localPeerConnection) return;

        const config = { iceServers: window.webrtcApp.refs.meteredIceServers };
        window.webrtcApp.refs.localPeerConnection = new RTCPeerConnection(config);

        // Verbindungszustand überwachen. Beide Handler laufen in dieselbe
        // Auswertung: connectionState und iceConnectionState erreichen bei
        // einer Störung typischerweise beide einen kritischen Wert. Früher
        // führte das zu zwei blockierenden Dialogen hintereinander (F-12).
        window.webrtcApp.refs.localPeerConnection.onconnectionstatechange = function() {
            window.webrtcApp.rtc.handleConnectionStateChange();
        };
        window.webrtcApp.refs.localPeerConnection.oniceconnectionstatechange = function() {
            window.webrtcApp.rtc.handleConnectionStateChange();
        };
        // Fehler bei der Kandidatensuche sind kein Abbruchgrund: Fällt ein
        // STUN-/TURN-Server aus, wird über die übrigen weiter gesammelt.
        window.webrtcApp.refs.localPeerConnection.onicecandidateerror = function(event) {
            console.warn("ICE-Kandidatenfehler (" + event.errorCode + ") bei " + (event.url || 'unbekanntem Server'));
        };

        // Zwei DataChannels anlegen (nur der Initiator legt an, die Gegenseite
        // bekommt sie ueber ondatachannel). Getrennte Kanaele, damit
        // Nutzerinhalt und Steuerprotokoll nichts mehr miteinander zu tun
        // haben - siehe PROTOKOLL.md.
        const protocol = window.webrtcApp.protocol;
        if (isInitiator) {
            const pc = window.webrtcApp.refs.localPeerConnection;
            window.webrtcApp.rtc.attachChannel(pc.createDataChannel(protocol.CHANNEL_CHAT));
            window.webrtcApp.rtc.attachChannel(pc.createDataChannel(protocol.CHANNEL_CONTROL));
        }
        // Die Reihenfolge, in der die Kanaele beim Angerufenen ankommen, ist
        // nicht zugesichert. Zugeordnet wird deshalb ueber das Label.
        window.webrtcApp.refs.localPeerConnection.ondatachannel = (event) => {
            window.webrtcApp.rtc.attachChannel(event.channel);
        };

        // ICE-Candidates an Partner senden
        window.webrtcApp.refs.localPeerConnection.onicecandidate = event => {
            if (event.candidate && window.webrtcApp.state.activeTargetUserId) {
                window.webrtcApp.signaling.sendSignalMessage({
                    type: 'iceCandidate',
                    candidate: event.candidate,
                    target: window.webrtcApp.state.activeTargetUserId
                });
            }
        };

        // Remote-Stream anzeigen
        window.webrtcApp.refs.localPeerConnection.ontrack = event => {
            const remoteVideo = document.getElementById('remote-video');
            const placeholder = document.getElementById('remote-video-placeholder');
            if (remoteVideo) {
                remoteVideo.srcObject = event.streams[0];
                // Overlay verbergen, wenn Video kommt
                if (placeholder && event.streams[0].getVideoTracks().length > 0) {
                    placeholder.classList.remove('d-flex', 'show', 'align-items-center', 'justify-content-center');
                    placeholder.style.display = 'none';
                    placeholder.style.opacity = '0';
                    placeholder.style.visibility = 'hidden';
                    remoteVideo.style.display = "block";
                }
            }
        };
        window.webrtcApp.state.tracksAdded = false;
    },

    /**
     * Hängt lokale Audio/Video-Tracks an die PeerConnection an (nur 1x pro Call).
     */
    addLocalTracks() {
        if (!window.webrtcApp.refs.localStream || !window.webrtcApp.refs.localPeerConnection) return;
        if (window.webrtcApp.state.tracksAdded) return;
        window.webrtcApp.refs.localStream.getTracks().forEach(track => {
            window.webrtcApp.refs.localPeerConnection.addTrack(track, window.webrtcApp.refs.localStream);
        });
        window.webrtcApp.state.tracksAdded = true;
    },

    /**
     * Ordnet einen DataChannel anhand seines Labels zu und haengt die
     * Handler an.
     *
     * Ein Kanal mit unbekanntem Label wird geschlossen statt benutzt: Er
     * gehoert nicht zum Protokoll, und was darauf ankaeme, waere ungeprueft.
     *
     * @param {RTCDataChannel} dc - Der zuzuordnende Kanal
     */
    attachChannel(dc) {
        const protocol = window.webrtcApp.protocol;

        if (dc.label === protocol.CHANNEL_CHAT) {
            window.webrtcApp.refs.chatChannel = dc;
            window.webrtcApp.rtc.setupChatChannel(dc);
            return;
        }
        if (dc.label === protocol.CHANNEL_CONTROL) {
            window.webrtcApp.refs.controlChannel = dc;
            window.webrtcApp.rtc.setupControlChannel(dc);
            return;
        }

        console.warn('DataChannel mit unbekanntem Label verworfen: "' + dc.label + '"');
        try { dc.close(); } catch (e) {}
    },

    /**
     * Konfiguriert den Chatkanal. Er traegt ausschliesslich Nutzerinhalt.
     * @param {RTCDataChannel} dc - DataChannel "chat"
     */
    setupChatChannel(dc) {
        dc.onopen = () => {
            window.webrtcApp.sound.stop('call_ringtone');
            const chatArea = document.getElementById('chat-area');
            if (chatArea) chatArea.style.display = "";
            // Das Polling wird hier NICHT abgeschaltet. Es ist der einzige
            // Weg, über den Auflegen und ICE-Restart den Gegenüber erreichen,
            // wenn die Peer-Verbindung gestört ist (Befund F-3).
            window.webrtcApp.signaling.setPollInterval(window.webrtcApp.signaling.POLL_INTERVAL_IN_CALL);
            // Der Kanal steht - Verbindungszustand neu bewerten, damit der
            // Status auch dann auf "Verbunden" springt, wenn das
            // Zustandsereignis der PeerConnection schon durch war.
            window.webrtcApp.rtc.handleConnectionStateChange();
        };
        dc.onclose = () => {
            window.webrtcApp.sound.stop('call_ringtone');
            // Der Kanal ist weg: ab jetzt läuft alles über das Signaling.
            // Schneller pollen, damit ein Auflegen des Partners ankommt.
            window.webrtcApp.signaling.setPollInterval(window.webrtcApp.signaling.POLL_INTERVAL_IDLE);
        };
        dc.onmessage = (e) => {
            window.webrtcApp.chat.handleMessage(e.data);
        };
    },

    /**
     * Konfiguriert den Steuerkanal. Er traegt ausschliesslich
     * Protokollnachrichten; die Auswertung liegt vollstaendig in control.js.
     * @param {RTCDataChannel} dc - DataChannel "control"
     */
    setupControlChannel(dc) {
        dc.onopen = () => {
            // Steht der Kanal, bevor die Rolle vom Server da war, holt
            // control.applyRole() das "hello" nach.
            window.webrtcApp.control.sendHello();
            window.webrtcApp.control.updateRoleUi();
            window.webrtcApp.rtc.handleConnectionStateChange();
        };
        dc.onclose = () => {
            // Ohne Steuerkanal kann niemand mehr steuern. Das Steuerkreuz
            // wird gesperrt, damit kein Druck ins Leere geht.
            window.webrtcApp.control.updatePadState();
        };
        dc.onmessage = (e) => {
            window.webrtcApp.control.handleMessage(e.data);
        };
    },

    /**
     * Chrome-Workaround: Dummy-PeerConnection erzeugen, um MediaDevices zu aktivieren.
     */
    initFakeSelfCall: async function() {
        try {
            if (!window.webrtcApp.refs.localPeerConnection) {
                window.webrtcApp.rtc.createPeerConnection(true);
                await new Promise(resolve => setTimeout(resolve, 100));
                const stream = await navigator.mediaDevices.getUserMedia({video:true, audio:true});
                window.webrtcApp.refs.localStream = stream;
                updateCallIcons();

                window.webrtcApp.rtc.addLocalTracks();
                updateCallIcons();

                const offer = await window.webrtcApp.refs.localPeerConnection.createOffer();
                await window.webrtcApp.refs.localPeerConnection.setLocalDescription(offer);
            }
        } catch(e) {
            console.error("[FakeSelfCall] Fehler bei PeerConnection-Init:", e);
        }
    },

    /**
     * Letzte Rückfallebene, falls der Server gar nicht antwortet.
     *
     * Der Server liefert normalerweise selbst schon STUN-Fallbacks mit
     * (class/Model/IceServerConfig.php, über STUN_SERVERS konfigurierbar).
     * Diese Liste greift nur, wenn die Route get_turn_credentials überhaupt
     * nicht erreichbar ist - dann ist wenigstens ein Verbindungsaufbau in
     * einfachen Netzen möglich, statt dass gar nichts geht.
     */
    FALLBACK_STUN_SERVERS: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
        { urls: 'stun:stun.cloudflare.com:3478' }
    ],

    /**
     * Lädt die ICE-Server-Konfiguration vom Backend.
     *
     * Vorher gab es hier weder try/catch noch eine Prüfung der Antwort: Bei
     * einem Ausfall des TURN-Dienstes wurde das Fehlerobjekt als
     * ICE-Konfiguration an die RTCPeerConnection weitergereicht und dauerhaft
     * zwischengespeichert (Befund F-18). Jetzt wird jeder Fehlerfall
     * ausgewertet, es bleibt immer eine benutzbare Liste übrig, und ein
     * Notbehelf wird über iceServersDegraded für den nächsten Anruf verworfen.
     *
     * @returns {Promise<void>}
     */
    loadIceServers: async function() {
        let iceServers = [];
        let turnAvailable = false;
        let warning = null;

        try {
            const response = await fetch("index.php?act=get_turn_credentials", { cache: 'no-store' });

            let payload = null;
            try {
                payload = await response.json();
            } catch (parseError) {
                throw new Error("Antwort des Servers war kein gültiges JSON.");
            }

            if (!response.ok) {
                throw new Error("Server antwortete mit HTTP " + response.status);
            }

            // Sowohl ein nacktes Array als auch { iceServers: [...] } annehmen.
            const list = Array.isArray(payload)
                ? payload
                : (payload && Array.isArray(payload.iceServers) ? payload.iceServers : null);

            if (!list) {
                throw new Error("Antwort enthielt keine ICE-Server-Liste.");
            }

            iceServers = list.filter(window.webrtcApp.rtc.isValidIceServer);
            if (payload && typeof payload.warning === 'string') {
                warning = payload.warning;
            }
        } catch (e) {
            console.error("ICE-Server konnten nicht geladen werden:", e.message);
            warning = "Die Verbindungsdaten konnten nicht geladen werden.";
            iceServers = [];
        }

        // Eigene STUN-Fallbacks ergänzen, ohne Doppelungen.
        iceServers = window.webrtcApp.rtc.mergeIceServers(
            iceServers,
            window.webrtcApp.rtc.FALLBACK_STUN_SERVERS
        );

        // Auf ein handhabbares Maß kürzen: zu viele Server verlängern die
        // Kandidatensuche spürbar. turns: (TURN über TLS/443) wird jetzt
        // mitgenommen - genau die Variante, die restriktive Netze am ehesten
        // passieren lässt (Befund F-17).
        const stunServers = iceServers.filter(s => window.webrtcApp.rtc.hasScheme(s, ['stun:', 'stuns:'])).slice(0, 4);
        const turnServers = iceServers.filter(s => window.webrtcApp.rtc.hasScheme(s, ['turn:', 'turns:'])).slice(0, 3);
        iceServers = stunServers.concat(turnServers);

        turnAvailable = turnServers.length > 0;

        window.webrtcApp.refs.meteredIceServers = iceServers;
        window.webrtcApp.refs.iceServersLoaded = iceServers.length > 0;
        // Ohne TURN oder mit Warnung ist die Liste nur ein Notbehelf: beim
        // nächsten Anruf erneut laden, damit ein vorübergehender Ausfall nicht
        // für die ganze Sitzung hängen bleibt.
        window.webrtcApp.refs.iceServersDegraded = (!turnAvailable || warning !== null);

        // TURN-Credentials (username/credential) NICHT ausgeben - nur die Anzahl.
        console.log("ICE-Server geladen:", iceServers.length, "(TURN verfügbar:", turnAvailable + ")");

        if (!turnAvailable) {
            window.webrtcApp.rtc.showSystemNotice(
                "Hinweis: Es ist kein TURN-Server verfügbar. Der Anruf klappt nur, wenn "
                + "beide Seiten in einfachen Netzen sind." + (warning ? " (" + warning + ")" : "")
            );
        } else if (warning) {
            window.webrtcApp.rtc.showSystemNotice("Hinweis: " + warning);
        }
    },

    /**
     * Prüft, ob ein Eintrag als RTCIceServer taugt.
     * @param {*} server - Zu prüfender Eintrag
     * @returns {boolean}
     */
    isValidIceServer(server) {
        if (!server || typeof server !== 'object') return false;
        const urls = server.urls;
        if (typeof urls === 'string') return urls.length > 0;
        return Array.isArray(urls) && urls.length > 0;
    },

    /**
     * Prüft, ob die URLs eines Eintrags mit einem der Schemata beginnen.
     * @param {Object} server - ICE-Server-Eintrag
     * @param {string[]} schemes - z.B. ['turn:', 'turns:']
     * @returns {boolean}
     */
    hasScheme(server, schemes) {
        const urls = Array.isArray(server.urls) ? server.urls : [server.urls];
        return urls.some(url =>
            typeof url === 'string' && schemes.some(scheme => url.toLowerCase().startsWith(scheme))
        );
    },

    /**
     * Führt zwei ICE-Server-Listen zusammen und lässt keine URL doppelt zu.
     * @param {Array} primary - Liste vom Server
     * @param {Array} fallback - Eigene Rückfall-Liste
     * @returns {Array} Zusammengeführte Liste
     */
    mergeIceServers(primary, fallback) {
        const known = new Set();
        const collect = (server) => {
            const urls = Array.isArray(server.urls) ? server.urls : [server.urls];
            return urls.filter(u => typeof u === 'string').map(u => u.toLowerCase());
        };

        const merged = [];
        primary.forEach(server => {
            collect(server).forEach(u => known.add(u));
            merged.push(server);
        });
        fallback.forEach(server => {
            const urls = collect(server);
            if (urls.some(u => known.has(u))) return;
            urls.forEach(u => known.add(u));
            merged.push(server);
        });
        return merged;
    },

    // =====================================================================
    // Verbindungsüberwachung und Wiederverbindung
    // =====================================================================

    /**
     * Wertet connectionState und iceConnectionState gemeinsam aus.
     *
     * Wichtig ist die Reihenfolge der Prüfung: "failed" schlägt "disconnected",
     * "disconnected" schlägt "connected". Sonst würde ein nachlaufender
     * connectionState eine bereits erkannte Störung wieder überschreiben.
     */
    handleConnectionStateChange() {
        const pc = window.webrtcApp.refs.localPeerConnection;
        if (!pc) return;
        // Läuft der Abbau bereits, interessieren uns die Zustände nicht mehr.
        if (!window.webrtcApp.state.isCallActive) return;

        const cs  = pc.connectionState;
        const ics = pc.iceConnectionState;
        if (window.webrtcApp.debug) console.log("Verbindungszustand:", cs, "/", ics);

        if (cs === 'failed' || ics === 'failed') {
            window.webrtcApp.rtc.onConnectionFailed();
        } else if (cs === 'disconnected' || ics === 'disconnected') {
            window.webrtcApp.rtc.onConnectionUnstable();
        } else if (cs === 'connected' || ics === 'connected' || ics === 'completed') {
            window.webrtcApp.rtc.onConnectionRecovered();
        } else if (cs === 'closed' || ics === 'closed') {
            // Geschlossen wird nur von uns selbst oder endgültig durch den
            // Browser - hier gibt es nichts mehr zu retten.
            window.webrtcApp.rtc.giveUpReconnect("Die Verbindung zum Gesprächspartner wurde beendet.");
        }
    },

    /**
     * Verbindung steht (wieder). Alle Wiederverbindungs-Timer aufräumen.
     */
    onConnectionRecovered() {
        const state = window.webrtcApp.state;
        const wasDisturbed = (state.connectionStatus === 'unstable' || state.connectionStatus === 'reconnecting');

        window.webrtcApp.rtc.stopReconnect();
        state.connectedSince = Date.now();
        window.webrtcApp.rtc.setConnectionStatus('connected');
        // Verbindung steht wieder: zurück auf das schonendere In-Call-Intervall.
        window.webrtcApp.signaling.setPollInterval(window.webrtcApp.signaling.POLL_INTERVAL_IN_CALL);

        if (wasDisturbed) {
            window.webrtcApp.rtc.showSystemNotice("Verbindung wiederhergestellt.");
        }
    },

    /**
     * "disconnected": vorübergehend. Nicht abbrechen, sondern eine Frist
     * laufen lassen - meist erholt sich die Verbindung von selbst. Erst wenn
     * sie das nicht tut, wird neu ausgehandelt.
     */
    onConnectionUnstable() {
        const state = window.webrtcApp.state;

        // Läuft bereits ein Wiederverbindungsversuch, bleibt es dabei.
        if (state.reconnect.inProgress) return;
        if (state.reconnect.graceTimer !== null) return;

        window.webrtcApp.rtc.setConnectionStatus('unstable');
        window.webrtcApp.rtc.startReconnectDeadline();

        state.reconnect.graceTimer = setTimeout(function() {
            state.reconnect.graceTimer = null;
            if (!window.webrtcApp.state.isCallActive) return;
            // Nach Ablauf der Frist prüfen, ob sich die Verbindung erholt hat.
            if (window.webrtcApp.rtc.isConnectionUsable()) {
                window.webrtcApp.rtc.onConnectionRecovered();
                return;
            }
            window.webrtcApp.rtc.triggerReconnect();
        }, window.webrtcApp.rtc.RECONNECT_GRACE_MS);
    },

    /**
     * "failed": Der ICE-Transport ist endgültig gescheitert. Hier hilft keine
     * Wartezeit mehr, es muss sofort neu ausgehandelt werden.
     */
    onConnectionFailed() {
        const state = window.webrtcApp.state;
        if (state.reconnect.graceTimer !== null) {
            clearTimeout(state.reconnect.graceTimer);
            state.reconnect.graceTimer = null;
        }
        window.webrtcApp.rtc.startReconnectDeadline();
        window.webrtcApp.rtc.triggerReconnect();
    },

    /**
     * Prüft, ob die Verbindung im Moment nutzbar ist.
     * @returns {boolean}
     */
    isConnectionUsable() {
        const pc = window.webrtcApp.refs.localPeerConnection;
        if (!pc) return false;
        return pc.connectionState === 'connected'
            || pc.iceConnectionState === 'connected'
            || pc.iceConnectionState === 'completed';
    },

    /**
     * Startet die Gesamtfrist, nach der endgültig aufgelegt wird.
     * Mehrfachaufrufe verlängern sie nicht - sie zählt ab der ersten Störung.
     */
    startReconnectDeadline() {
        const state = window.webrtcApp.state;
        if (state.reconnect.deadlineTimer !== null) return;

        state.reconnect.deadlineTimer = setTimeout(function() {
            state.reconnect.deadlineTimer = null;
            if (!window.webrtcApp.state.isCallActive) return;
            if (window.webrtcApp.rtc.isConnectionUsable()) {
                window.webrtcApp.rtc.onConnectionRecovered();
                return;
            }
            window.webrtcApp.rtc.giveUpReconnect(
                "Die Verbindung zum Gesprächspartner konnte nicht wiederhergestellt werden."
            );
        }, window.webrtcApp.rtc.RECONNECT_DEADLINE_MS);
    },

    /**
     * Stößt einen Wiederverbindungsversuch an.
     *
     * Nur der Initiator (der Anrufer) handelt neu aus. Die Gegenseite bittet
     * ihn stattdessen darum. Würden beide gleichzeitig einen Offer schicken,
     * scheiterte die Aushandlung (Glare).
     */
    triggerReconnect() {
        const state = window.webrtcApp.state;
        if (!state.isCallActive) return;

        // Doppelauslösung abfangen: connectionState und iceConnectionState
        // melden dieselbe Störung kurz nacheinander. Läuft bereits ein Versuch
        // mit vorgemerktem Folgeversuch, ist nichts weiter zu tun - sonst
        // würden pro Störung zwei Restarts gezählt und gesendet.
        if (state.reconnect.inProgress && state.reconnect.retryTimer !== null) return;

        if (state.reconnect.attempts >= window.webrtcApp.rtc.MAX_ICE_RESTARTS) {
            window.webrtcApp.rtc.giveUpReconnect(
                "Die Verbindung zum Gesprächspartner konnte nicht wiederhergestellt werden."
            );
            return;
        }

        state.reconnect.attempts++;
        state.reconnect.inProgress = true;
        window.webrtcApp.rtc.setConnectionStatus('reconnecting');
        // Während der Neuaushandlung schneller pollen: Offer, Answer und die
        // neuen ICE-Kandidaten laufen jetzt alle über das Signaling, und das
        // In-Call-Intervall würde den Wiederaufbau spürbar ausbremsen.
        window.webrtcApp.signaling.setPollInterval(window.webrtcApp.signaling.POLL_INTERVAL_IDLE);

        if (state.isInitiator) {
            window.webrtcApp.rtc.performIceRestart();
        } else if (state.activeTargetUserId) {
            // Wir sind der Angerufene: Restart beim Initiator anfordern.
            window.webrtcApp.signaling.sendSignalMessage({
                type: 'restart_request',
                target: state.activeTargetUserId
            });
        }

        // Nächsten Versuch vormerken, falls dieser nichts bringt.
        if (state.reconnect.retryTimer !== null) clearTimeout(state.reconnect.retryTimer);
        state.reconnect.retryTimer = setTimeout(function() {
            state.reconnect.retryTimer = null;
            if (!window.webrtcApp.state.isCallActive) return;
            if (window.webrtcApp.rtc.isConnectionUsable()) {
                window.webrtcApp.rtc.onConnectionRecovered();
                return;
            }
            window.webrtcApp.rtc.triggerReconnect();
        }, window.webrtcApp.rtc.RESTART_RETRY_MS);
    },

    /**
     * Führt den ICE-Restart aus: neuer Offer mit frischen Kandidaten, über das
     * HTTP-Signaling verschickt. Medienspuren und DataChannel bleiben dabei
     * erhalten - es wird nur der Transportweg neu gesucht.
     */
    performIceRestart: async function() {
        const pc = window.webrtcApp.refs.localPeerConnection;
        const state = window.webrtcApp.state;
        if (!pc || !state.activeTargetUserId) return;

        // Nur aus einem stabilen Aushandlungszustand heraus starten, sonst
        // kollidiert der neue Offer mit einer laufenden Aushandlung.
        if (pc.signalingState !== 'stable') {
            console.warn("ICE-Restart übersprungen, Aushandlung läuft noch (" + pc.signalingState + ").");
            return;
        }

        try {
            const offer = await pc.createOffer({ iceRestart: true });
            await pc.setLocalDescription(offer);
            window.webrtcApp.signaling.sendSignalMessage({
                type: 'restart_offer',
                sdp: offer.sdp,
                target: state.activeTargetUserId
            });
            console.log("ICE-Restart gesendet (Versuch " + state.reconnect.attempts + ").");
        } catch (e) {
            console.error("ICE-Restart fehlgeschlagen:", e);
        }
    },

    /**
     * Gegenseite hat einen ICE-Restart eingeleitet: Offer übernehmen und
     * beantworten. Das ist kein neuer Anruf, der Annahme-Dialog bleibt weg.
     * @param {Object} data - Signalnachricht mit sdp
     */
    handleRestartOffer: async function(data) {
        const pc = window.webrtcApp.refs.localPeerConnection;
        const state = window.webrtcApp.state;

        if (!pc || !state.isCallActive) {
            console.warn("restart_offer ohne aktiven Call verworfen.");
            return;
        }

        state.reconnect.inProgress = true;
        window.webrtcApp.rtc.startReconnectDeadline();
        window.webrtcApp.rtc.setConnectionStatus('reconnecting');
        // Siehe triggerReconnect(): während der Aushandlung schneller pollen.
        window.webrtcApp.signaling.setPollInterval(window.webrtcApp.signaling.POLL_INTERVAL_IDLE);

        try {
            await pc.setRemoteDescription(new RTCSessionDescription({ type: 'offer', sdp: data.sdp }));
            const answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
            window.webrtcApp.signaling.sendSignalMessage({
                type: 'restart_answer',
                sdp: answer.sdp,
                target: data.sender_id
            });
            window.webrtcApp.rtc.flushPendingCandidates();
        } catch (e) {
            console.error("Fehler beim Beantworten des ICE-Restarts:", e);
        }
    },

    /**
     * Antwort auf unseren ICE-Restart einarbeiten.
     * @param {Object} data - Signalnachricht mit sdp
     */
    handleRestartAnswer: async function(data) {
        const pc = window.webrtcApp.refs.localPeerConnection;
        if (!pc) return;

        try {
            await pc.setRemoteDescription(new RTCSessionDescription({ type: 'answer', sdp: data.sdp }));
            window.webrtcApp.rtc.flushPendingCandidates();
        } catch (e) {
            console.error("Fehler beim Übernehmen der Restart-Antwort:", e);
        }
    },

    /**
     * Der Angerufene bittet um einen ICE-Restart. Nur der Initiator reagiert.
     * @param {Object} data - Signalnachricht
     */
    handleRestartRequest(data) {
        const state = window.webrtcApp.state;
        if (!state.isCallActive || !state.isInitiator) return;

        // Bewusst über triggerReconnect: Damit gelten dieselbe Versuchszählung
        // und dieselben Doppelauslösungs-Sperren wie bei einer selbst
        // erkannten Störung. Eine wiederholte Anfrage der Gegenseite löst
        // dadurch keinen zusätzlichen Restart aus.
        window.webrtcApp.rtc.startReconnectDeadline();
        window.webrtcApp.rtc.triggerReconnect();
    },

    /**
     * Gepufferte ICE-Kandidaten nachträglich einspielen.
     */
    flushPendingCandidates() {
        const pc = window.webrtcApp.refs.localPeerConnection;
        const pending = window.webrtcApp.refs.pendingCandidates;
        if (!pc || !pending || !pending.length) return;

        pending.forEach(candidate => {
            pc.addIceCandidate(new RTCIceCandidate(candidate))
              .catch(e => console.warn("ICE-Kandidat konnte nicht übernommen werden:", e));
        });
        window.webrtcApp.refs.pendingCandidates = [];
    },

    /**
     * Stoppt alle Wiederverbindungs-Timer und setzt den Zähler zurück.
     */
    stopReconnect() {
        const reconnect = window.webrtcApp.state.reconnect;
        ['graceTimer', 'deadlineTimer', 'retryTimer'].forEach(key => {
            if (reconnect[key] !== null) {
                clearTimeout(reconnect[key]);
                reconnect[key] = null;
            }
        });
        reconnect.attempts = 0;
        reconnect.inProgress = false;
    },

    /**
     * Wiederverbindung endgültig aufgeben: Status anzeigen, den Partner
     * informieren und auflegen. Genau eine Meldung für den Nutzer.
     * @param {string} message - Meldung für den Nutzer
     */
    giveUpReconnect(message) {
        if (!window.webrtcApp.state.isCallActive) return;

        window.webrtcApp.rtc.stopReconnect();
        window.webrtcApp.rtc.setConnectionStatus('disconnected');
        // Genau eine Meldung, und sie blockiert nicht: Der Abbau laeuft
        // sofort weiter, der Hinweis bleibt sichtbar.
        window.webrtcApp.notify.error(message);
        // sendSignal=true: Der Partner soll erfahren, dass hier Schluss ist -
        // über das Signaling, denn die Peer-Verbindung ist ohnehin hin.
        window.webrtcApp.rtc.endCall(true);
    },

    // =====================================================================
    // Sichtbarer Verbindungsstatus
    // =====================================================================

    /**
     * Textbausteine und CSS-Klasse je Zustand.
     */
    CONNECTION_STATUS_LABELS: {
        idle:         { text: '',                       cssClass: '' },
        connecting:   { text: 'Verbindung wird aufgebaut', cssClass: 'connection-status--connecting' },
        connected:    { text: 'Verbunden',              cssClass: 'connection-status--connected' },
        unstable:     { text: 'Verbindung instabil',    cssClass: 'connection-status--unstable' },
        reconnecting: { text: 'Wiederverbindung …',     cssClass: 'connection-status--reconnecting' },
        disconnected: { text: 'Verbindung getrennt',    cssClass: 'connection-status--disconnected' }
    },

    /**
     * Setzt den sichtbaren Verbindungsstatus (Desktop und Mobile).
     * @param {string} status - idle|connecting|connected|unstable|reconnecting|disconnected
     */
    setConnectionStatus(status) {
        const label = window.webrtcApp.rtc.CONNECTION_STATUS_LABELS[status];
        if (!label) return;

        window.webrtcApp.state.connectionStatus = status;

        // Ein Element. Es lag frueher zweimal im Markup - einmal im
        // Kopfbereich und einmal als Overlay ueber dem Video, weil der
        // Kopfbereich auf schmalen Geraeten zu schmal wurde. Der Kopfbereich
        // IST jetzt das Overlay.
        const el = document.getElementById('connection-status');
        if (el) {
            el.textContent = label.text;
            el.className = 'connection-status ' + label.cssClass;
            el.style.display = (status === 'idle') ? 'none' : '';
        }
    },

    /**
     * Zeigt einen Hinweis im Chatverlauf an.
     * Bewusst kein alert(): Hinweise dürfen den Guide nicht blockieren.
     * @param {string} text - Hinweistext
     */
    showSystemNotice(text) {
        const log = document.getElementById('chat-log');
        if (log) {
            const div = document.createElement('div');
            div.className = 'chat-system-notice';
            div.textContent = text;
            log.appendChild(div);
            log.scrollTop = log.scrollHeight;
        }
        console.log("[Hinweis]", text);
    },

    // =====================================================================
    // Steuerbefehle
    // =====================================================================

    /**
     * Darf gerade ein Steuerbefehl gesendet werden?
     *
     * Nein, solange die Verbindung nicht stabil ist. Befehle, die während
     * einer Unterbrechung entstehen, werden verworfen und ausdrücklich NICHT
     * gepuffert: Der DataChannel ist zuverlässig und geordnet, alles was
     * während der Störung im Puffer landet, würde beim Wiederanlaufen auf
     * einen Schlag beim Guide ankommen.
     *
     * @returns {boolean}
     */
    canSendControlCommand() {
        const state = window.webrtcApp.state;
        const dc = window.webrtcApp.refs.controlChannel;

        if (!state.isCallActive) return false;
        if (state.connectionStatus !== 'connected') return false;
        if (!window.webrtcApp.rtc.isConnectionUsable()) return false;
        if (!dc || dc.readyState !== 'open') return false;
        // Staut sich der Sendepuffer, ist der Kanal nicht mehr in Echtzeit.
        if (dc.bufferedAmount > window.webrtcApp.rtc.CONTROL_MAX_BUFFER) return false;
        return true;
    },

    /**
     * Darf ein empfangener Steuerbefehl ausgeführt werden?
     *
     * Zusätzlich zur Stabilitätsprüfung gilt direkt nach einer
     * Wiederverbindung eine kurze Sperre: Was in diesem Moment eintrifft, kann
     * aus dem Puffer von vor der Störung stammen. Im Zweifel wird verworfen -
     * ein nicht ausgeführter Befehl ist harmlos, ein verspäteter nicht.
     *
     * @returns {boolean}
     */
    mayExecuteControlCommand() {
        const state = window.webrtcApp.state;
        if (!state.isCallActive) return false;
        if (state.connectionStatus !== 'connected') return false;
        if (!state.connectedSince) return false;
        return (Date.now() - state.connectedSince) >= window.webrtcApp.rtc.CONTROL_SETTLE_MS;
    },

    /**
     * Startet Timeout: Wird Call nicht angenommen, beendet sich der Call nach 25 Sekunden.
     */
    startTimeout: function() {
        if (window.webrtcApp.state.callTimeout) clearTimeout(window.webrtcApp.state.callTimeout);
        window.webrtcApp.state.callTimeout = setTimeout(function() {
            window.webrtcApp.signaling.sendSignalMessage({
                type: 'hangup',
                target: window.webrtcApp.state.activeTargetUserId,
                reason: 'timeout'
            });
            window.webrtcApp.sound.stop('call_ringtone');
            window.webrtcApp.rtc.endCall(false);
            window.webrtcApp.notify.info('Der Anruf wurde nicht angenommen.');
        }, 25000);
        console.log('Start Timeout :' + window.webrtcApp.state.callTimeout);
    },

    /**
     * Stoppt das Call-Timeout (z.B. nach Annahme oder Beenden).
     */
    stopTimeout() {
        console.log('stop: ' + window.webrtcApp.state.callTimeout);
        if (window.webrtcApp.state.callTimeout) {
            clearTimeout(window.webrtcApp.state.callTimeout);
            window.webrtcApp.state.callTimeout = null;
        }
    },

    /**
     * Schickt eine Fehlermeldung (z.B. fehlende Medien) an den Partner und beendet den Call.
     * @param {string} msg - Fehlermeldung
     */
    sendCallFailedMsg(msg) {
        window.webrtcApp.signaling.sendSignalMessage({
            type: 'call_failed',
            target: window.webrtcApp.state.activeTargetUserId,
            reason: 'media_error'
        });
        window.webrtcApp.rtc.endCall(false);
        window.webrtcApp.notify.error(msg);
    }
};
