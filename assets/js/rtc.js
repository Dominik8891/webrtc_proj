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
        window.webrtcApp.media.reset();    // Geraetewahl und eigenes Vorschaubild
        this.resetUI();              // Entfernt UI-Call-Status
        this.closePeerConnection();  // PeerConnection & DataChannels schließen
        this.clearMediaStreams();    // Lokalen & Remote MediaStream beenden
        this.hideChatAndArrow();     // Chat/Arrow-Bereich verstecken
        window.webrtcApp.chat.clearUnread();  // Zaehler am Chatknopf leeren

        // State zurücksetzen
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

        // Mobile Browser fix: reload nach Call-Ende.
        //
        // Der Neuaufbau wartet, bis ein noch stehender Hinweis gelesen werden
        // konnte. Vorher lud die Seite starr nach einer Sekunde neu - und
        // loeschte damit genau die Meldung, die den Abbruch erklaerte
        // ("Der Zugriff auf die Kamera wurde abgelehnt ..."). Auf einem
        // Telefon war das der Grund, warum bei verweigertem Medienzugriff
        // scheinbar gar nichts kam.
        if (/Android|iPhone|iPad|iPod|Mobile|Linux/i.test(navigator.userAgent)) {
            const wartezeit = Math.max(1000, window.webrtcApp.notify.pendingMs() + 400);
            setTimeout(() => location.reload(), wartezeit);
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
        // Der selbst gefuehrte Strom der Gegenseite (siehe attachRemoteTrack)
        // gehoert zu diesem Call und darf nicht in den naechsten laufen -
        // sonst haengt dort eine tote Spur im Videoelement.
        window.webrtcApp.refs.remoteStream = null;

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
     *
     * DER ANRUFER HOLT HIER KEINE MEDIEN MEHR.
     * -----------------------------------------
     * Ob er ueberhaupt etwas sendet, haengt an seiner Rolle - und die vergibt
     * der Server. Sie steht in der Antwort auf das Offer, also erst NACH
     * Schritt 4:
     *
     *   viewer  Der Zuschauer sendet nichts. Kein Bild, kein Ton, keine
     *           Freigabe, kein Kameralicht. Er empfaengt Bild und Ton des
     *           Guides und steuert ueber Tasten - so ist die Anwendung
     *           gedacht, und so funktioniert sie auch dort, wo Anrufer und
     *           Guide keine gemeinsame Sprache haben.
     *   peer    Anruf ohne Fuehrung (mit der Verwaltung). Dort gehoert das
     *           Gespraech in beide Richtungen, also wird das Mikrofon geholt
     *           und die Kamera bleibt zuschaltbar.
     *
     * Deshalb wird zuerst verbunden und danach entschieden. Die Sender fuer
     * beide Spurarten stehen von Anfang an bereit (reserveMediaSenders): Was
     * spaeter dazukommt, laeuft ueber replaceTrack und braucht keine
     * Neuaushandlung - die kann diese Anwendung mitten im Gespraech nur fuer
     * den ICE-Restart.
     *
     * DER ABLAUF IST DURCHGEHEND AWAIT. Vorher lief die Medien- und
     * Offer-Kette als unbeobachtete Promise nebenher, waehrend die
     * Call-Ansicht und das 25-Sekunden-Timeout schon starteten. Ein Fehler
     * landete in console.error, und der Nutzer bekam eine halbe Minute
     * spaeter "Der Anruf wurde nicht angenommen" - eine Meldung ueber etwas,
     * das nie stattgefunden hat. Jetzt bricht jeder Fehler den Anruf sofort
     * ab und wird benannt, und das Timeout laeuft erst, wenn das Offer
     * tatsaechlich beim Server liegt.
     *
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

        window.webrtcApp.state.activeTargetUserId = targetUserId;

        // 1. ICE-Server laden (für PeerConnection)
        //    Bei einer Notfallliste (kein TURN) wird erneut geladen, damit ein
        //    vorübergehender Ausfall des TURN-Dienstes nicht dauerhaft
        //    zwischengespeichert bleibt (Befund F-18).
        if (!window.webrtcApp.refs.iceServersLoaded || window.webrtcApp.refs.iceServersDegraded) {
            await window.webrtcApp.rtc.loadIceServers();
        }

        // 2. PeerConnection aufbauen und fuer beide Spurarten einen Sender
        //    freihalten - noch ohne Spur, denn ob wir senden, steht erst in
        //    Schritt 5 fest.
        //
        //    Hier stand frueher ein "Dummy-Selbstanruf": eine PeerConnection
        //    mit einem Offer, das sofort wieder verworfen wurde, um in Chrome
        //    die Geraeteliste zu wecken. Ohne eine Medienanforderung an dieser
        //    Stelle weckt es gar nichts mehr - es blieb ein zusaetzliches
        //    Offer ohne Inhalt und eine Zehntelsekunde Wartezeit.
        window.webrtcApp.rtc.createPeerConnection(true);
        window.webrtcApp.rtc.reserveMediaSenders();

        // 3. Call-Ansicht zeigen. Ab hier laeuft ein Anruf, den der Nutzer
        //    auch selbst wieder beenden kann.
        window.webrtcApp.state.targetUsername = await window.webrtcApp.uiRtc.getUsername(targetUserId);
        window.webrtcApp.uiRtc.setEndCallButtonVisible(true);
        window.webrtcApp.state.isCallActive = true;
        window.webrtcApp.uiChat.updatePollingState();
        document.body.classList.add('call-active');
        document.getElementById('call-view').style.display = '';
        document.getElementById('remote-username').textContent =
            'Rufe ' + window.webrtcApp.state.targetUsername + ' an';
        window.webrtcApp.sound.play('call_ringtone');

        // 4. Offer bauen und abschicken.
        let antwort;
        try {
            const offer = await window.webrtcApp.refs.localPeerConnection.createOffer();
            await window.webrtcApp.refs.localPeerConnection.setLocalDescription(offer);
            antwort = await window.webrtcApp.signaling.sendSignalMessage({
                type: 'offer',
                sdp: offer.sdp,
                target: targetUserId
            });
        } catch (fehler) {
            window.webrtcApp.rtc.abortCall('Der Anruf konnte nicht aufgebaut werden: '
                + (fehler && fehler.message ? fehler.message : 'unbekannter Fehler'));
            return;
        }

        // Der Server weist einen Anruf ab, dessen Ziel kein Guide ist
        // (WebRTCController::callAllowed). Das ist kein Netzfehler, sondern
        // eine Antwort - und sie gehoert dem Nutzer gesagt, statt ihn 25
        // Sekunden warten zu lassen.
        if (!antwort || antwort.status === 'error') {
            window.webrtcApp.rtc.abortCall(
                (antwort && antwort.msg)
                    ? antwort.msg
                    : 'Der Anruf konnte nicht zugestellt werden. Bitte später erneut versuchen.'
            );
            return;
        }

        // 5. Die Rolle in diesem Call vergibt der Server (siehe
        //    WebRTCController::roleForCall). Sie haengt an der Antwort auf das
        //    Offer, damit es dafuer keine zweite Anfrage und kein Zeitfenster
        //    ohne Rolle braucht. Bleibt sie aus, gilt die Rolle als unbekannt -
        //    dann steuert niemand und es wird nichts gesendet.
        window.webrtcApp.control.applyRole(antwort.role || null);

        // 6. Erst jetzt steht fest, ob wir ueberhaupt etwas senden. Der
        //    Zuschauer wird an dieser Stelle nach gar nichts gefragt.
        await window.webrtcApp.media.startOwnMedia();

        // Die Geraeteliste ist erst nach einer Freigabe brauchbar: Vorher
        // liefert enumerateDevices() Eintraege ohne Namen und ohne Kennung.
        window.webrtcApp.media.refreshDeviceLists();
        window.webrtcApp.media.updateIcons();

        // Erst jetzt laeuft die Frist bis "wurde nicht angenommen": Das Offer
        // liegt beim Server, es kann also tatsaechlich jemand abnehmen.
        window.webrtcApp.rtc.startTimeout();
    },

    /**
     * Haelt in der Aushandlung fuer Ton UND Bild je einen Sender frei, ohne
     * etwas einzuschalten.
     *
     * DAS IST DER UNTERSCHIED ZWISCHEN "AUS" UND "GIBT ES NICHT". Der Anrufer
     * weiss beim Bau des Angebots noch nicht, ob er ueberhaupt senden darf -
     * seine Rolle kommt erst mit der Antwort des Servers auf das Offer.
     * Stuende eine Spurart dann nicht im Angebot, liesse sie sich spaeter nur
     * mit einer Neuaushandlung zuschalten, und die kann diese Anwendung
     * mitten im Gespraech nur fuer den ICE-Restart.
     *
     * Ein leerer Transceiver kostet nichts und sendet nichts: Wer keine Spur
     * daran haengt, uebertraegt auch nichts. Er macht aber sowohl den
     * Kamera- als auch den Mikrofonknopf zu einem Schalter, der sofort wirkt
     * (assets/js/media.js, replaceTrack) - und beim Zuschauer bleibt beides
     * einfach unbenutzt.
     *
     * Frueher wurde hier nur die Kamera vorgehalten; das Mikrofon entstand
     * aus dem Strom, den startCall vorab holte. Der wird nicht mehr vorab
     * geholt, also muss auch der Tonsender vorgehalten werden.
     */
    reserveMediaSenders() {
        const pc = window.webrtcApp.refs.localPeerConnection;
        if (!pc || typeof pc.addTransceiver !== 'function') return;

        ['audio', 'video'].forEach(kind => {
            // Schon ausgehandelt? Dann nichts tun - sonst stuende eine
            // zweite, tote Spur im Angebot.
            const schonDa = pc.getTransceivers().some(t =>
                (t.sender   && t.sender.track   && t.sender.track.kind   === kind) ||
                (t.receiver && t.receiver.track && t.receiver.track.kind === kind)
            );
            if (schonDa) return;

            try {
                pc.addTransceiver(kind, { direction: 'sendrecv' });
            } catch (e) {
                // Kein Abbruchgrund: Der Anruf kommt auch ohne den Platzhalter
                // zustande, nur laesst sich diese Spurart dann nicht
                // nachtraeglich zuschalten.
                console.warn('Spur "' + kind + '" konnte nicht vorbereitet werden:', e);
            }
        });
    },

    /**
     * Nimmt einen eingehenden Anruf an.
     *
     * Stand vorher als anonymer Klickhandler in assets/js/main.js. Hier ist
     * er pruefbar und liegt neben startCall(), mit dem er sich die Haelfte
     * des Ablaufs teilt.
     *
     * ZWEI AENDERUNGEN AM ABLAUF
     * --------------------------
     * 1. Die eigenen Spuren werden erst NACH setRemoteDescription gelegt,
     *    ueber media.attachTracks(). Damit landen sie auf den
     *    Transceivern des Angebots, und der Videotransceiver bekommt
     *    ausdruecklich die Richtung "sendrecv" - auch dann, wenn die Kamera
     *    im Annahmedialog abgewaehlt wurde. Nur so laesst sie sich spaeter
     *    per replaceTrack zuschalten, ohne neu auszuhandeln. Vorher lief
     *    addTrack VOR setRemoteDescription; wer ohne Kamera annahm, konnte
     *    sie im Gespraech nicht mehr einschalten.
     * 2. Eine abgelehnte Kamera beendet den Anruf nicht mehr. Der Guide
     *    hoert und spricht weiter, und er erfaehrt, was fehlt.
     *
     * @param {Object} wahl - Auswahl aus dem Annahmedialog
     * @param {boolean} wahl.video - Bild senden
     * @param {boolean} wahl.audio - Ton senden
     * @param {string|null} [wahl.videoDeviceId] - gewaehlte Kamera
     * @param {string|null} [wahl.audioDeviceId] - gewaehltes Mikrofon
     */
    acceptCall: async function(wahl) {
        const state = window.webrtcApp.state;
        const media = window.webrtcApp.media;
        const data  = state.pendingOffer;

        if (!data) {
            window.webrtcApp.notify.error('Der Anruf ist nicht mehr da.');
            return;
        }

        window.webrtcApp.uiRtc.setEndCallButtonVisible(true);
        state.isCallActive = true;
        // Wir nehmen an, sind also nicht der Initiator: Bei einer Störung
        // handelt die Gegenseite neu aus, wir bitten sie nur darum.
        state.isInitiator = false;
        window.webrtcApp.rtc.setConnectionStatus('connecting');
        // Im Call langsamer weiterpollen - der Weg wird für Auflegen und
        // ICE-Restart gebraucht.
        window.webrtcApp.signaling.setPollInterval(window.webrtcApp.signaling.POLL_INTERVAL_IN_CALL);
        window.webrtcApp.uiChat.updatePollingState();

        state.activeTargetUserId = data.sender_id;
        // Die Rolle hat der Server an das Offer gestempelt (siehe
        // WebRTCController::roleForCall). Fehlt sie, gilt sie als unbekannt -
        // dann rendert kein Steuerkreuz und eingehende Bewegungsbefehle
        // werden abgelehnt.
        window.webrtcApp.control.applyRole(data.role || null);
        state.targetUsername = await window.webrtcApp.uiRtc.getUsername(data.sender_id);
        document.body.classList.add('call-active');
        const callView = document.getElementById('call-view');
        if (callView) callView.style.display = '';
        const name = document.getElementById('remote-username');
        if (name) name.textContent = 'Anruf mit ' + state.targetUsername;

        // Die im Dialog gewaehlten Geraete gelten fuer den ganzen Call - auch
        // dann, wenn die Kamera zwischendurch aus und wieder an geht.
        media.rememberDevice('video', wahl.videoDeviceId || null);
        media.rememberDevice('audio', wahl.audioDeviceId || null);

        const stream = await window.webrtcApp.rtc.acquireAcceptMedia(wahl);
        if (!stream) return;                // Meldung ist raus, Call abgebaut
        window.webrtcApp.refs.localStream = stream;

        await window.webrtcApp.rtc.loadIceServers();
        window.webrtcApp.rtc.createPeerConnection(false);
        const pc = window.webrtcApp.refs.localPeerConnection;
        if (!pc) {
            window.webrtcApp.rtc.sendCallFailedMsg('Die Verbindung konnte nicht aufgebaut werden.');
            return;
        }

        try {
            await pc.setRemoteDescription(new RTCSessionDescription({
                type: data.type,
                sdp: data.sdp
            }));
        } catch (e) {
            window.webrtcApp.rtc.sendCallFailedMsg(
                'Die Verbindung konnte nicht aufgebaut werden: ' + (e && e.message ? e.message : 'unbekannter Fehler')
            );
            return;
        }

        // Erst jetzt die eigenen Spuren - siehe Erklaerung oben.
        await media.attachTracks(stream);

        let answer;
        try {
            answer = await pc.createAnswer();
            await pc.setLocalDescription(answer);
        } catch (e) {
            window.webrtcApp.rtc.sendCallFailedMsg(
                'Die Verbindung konnte nicht aufgebaut werden: ' + (e && e.message ? e.message : 'unbekannter Fehler')
            );
            return;
        }

        window.webrtcApp.signaling.sendSignalMessage({
            type: 'answer',
            sdp: answer.sdp,
            target: data.sender_id
        });
        window.webrtcApp.rtc.flushPendingCandidates();

        // Jetzt liegt eine Freigabe vor: Erst ab hier haben die Geraete in
        // enumerateDevices() Namen und Kennungen.
        media.refreshDeviceLists();
        media.updateIcons();
    },

    /**
     * Holt die Medien fuer einen angenommenen Anruf.
     *
     * Faellt nur EINE der beiden Spurarten aus, laeuft das Gespraech mit der
     * anderen weiter - und der Nutzer erfaehrt, welche fehlt und warum.
     * Vorher endete jede Ablehnung den Anruf, und die Meldung nannte in jedem
     * Fall "Medien", nie das betroffene Geraet.
     *
     * Zuerst wird EIN gemeinsamer getUserMedia-Aufruf versucht: Der Browser
     * fragt dann auch nur einmal nach. Erst wenn der scheitert, wird je Spur
     * einzeln nachgefasst - nur so ist zu erkennen, welches Geraet abgelehnt
     * wurde.
     *
     * @param {Object} wahl - siehe acceptCall()
     * @returns {Promise<MediaStream|null>} null = Anruf ist bereits abgebaut
     */
    acquireAcceptMedia: async function(wahl) {
        const media = window.webrtcApp.media;

        const gesamt = {};
        if (wahl.audio) gesamt.audio = media.deviceConstraint('audio');
        if (wahl.video) gesamt.video = media.deviceConstraint('video');

        try {
            return await navigator.mediaDevices.getUserMedia(gesamt);
        } catch (fehler) {
            // Nur eine Spurart gewuenscht: Es gibt nichts aufzuteilen.
            if (!wahl.audio || !wahl.video) {
                window.webrtcApp.rtc.sendCallFailedMsg(
                    window.webrtcApp.rtc.mediaErrorText(fehler, wahl.video ? 'video' : 'audio')
                );
                return null;
            }
        }

        const einzeln = async (kind) => {
            const c = {};
            c[kind] = media.deviceConstraint(kind);
            try { return { stream: await navigator.mediaDevices.getUserMedia(c) }; }
            catch (e) { return { fehler: e }; }
        };

        const ton  = await einzeln('audio');
        const bild = await einzeln('video');

        if (!ton.stream && !bild.stream) {
            window.webrtcApp.rtc.sendCallFailedMsg(
                window.webrtcApp.rtc.mediaErrorText(ton.fehler, 'audio')
            );
            return null;
        }

        if (ton.stream && !bild.stream) {
            window.webrtcApp.notify.error(
                window.webrtcApp.rtc.mediaErrorText(bild.fehler, 'video')
                + ' Der Anruf läuft ohne Bild weiter.'
            );
            return ton.stream;
        }

        if (bild.stream && !ton.stream) {
            window.webrtcApp.notify.error(
                window.webrtcApp.rtc.mediaErrorText(ton.fehler, 'audio')
                + ' Der Anruf läuft ohne Ton weiter; der Chat bleibt nutzbar.'
            );
            return bild.stream;
        }

        // Beide einzeln erfolgreich - der gemeinsame Aufruf war ein Ausreisser.
        bild.stream.getVideoTracks().forEach(t => ton.stream.addTrack(t));
        return ton.stream;
    },

    /**
     * Bricht einen gerade aufgebauten Anruf ab und sagt dem Nutzer, warum.
     *
     * Gedacht fuer die Fehler VOR dem Zustandekommen des Gespraechs: kein
     * Mikrofon, kein Offer, eine Absage des Servers. Der Gegenseite wird
     * nichts geschickt - sie hat in keinem dieser Faelle ein Offer bekommen.
     *
     * @param {string} text - Meldung fuer den Nutzer
     */
    abortCall(text) {
        window.webrtcApp.rtc.stopTimeout();
        window.webrtcApp.sound.stop('call_ringtone');
        // Die Meldung steht VOR dem Abbau. endCall() laesst auf Mobilgeraeten
        // die Seite neu laden und richtet sich dafuer nach der Frist des
        // laengsten stehenden Hinweises (notify.pendingMs). Eine Meldung, die
        // erst danach abgesetzt wird, wuerde nicht mitgezaehlt und mit dem
        // Neuaufbau verschwinden.
        window.webrtcApp.notify.error(text);
        window.webrtcApp.rtc.endCall(false);
    },

    /**
     * Uebersetzt einen Fehler von getUserMedia in einen Satz, der dem Nutzer
     * sagt, was zu tun ist.
     *
     * Die Namen sind die der DOMException aus der Media-Capture-Spezifikation.
     * Ohne diese Uebersetzung stand dort zuletzt gar nichts - der Fehler ging
     * in console.error unter.
     *
     * Das Geraet steht als Parameter dabei: Der Text lautete frueher in jedem
     * Fall auf "Mikrofon", auch wenn die KAMERA abgelehnt worden war. Eine
     * Meldung, die das falsche Geraet nennt, ist schlimmer als keine - sie
     * schickt den Nutzer in die falsche Einstellung.
     *
     * @param {Error} fehler - Was getUserMedia abgelehnt hat
     * @param {string} [kind] - 'audio' (Vorgabe) oder 'video'
     * @returns {string}
     */
    mediaErrorText(fehler, kind) {
        const name = fehler && fehler.name ? fehler.name : '';
        const video = (kind === 'video');
        const geraet = video ? 'die Kamera' : 'das Mikrofon';
        const Geraet = video ? 'Die Kamera'  : 'Das Mikrofon';

        if (name === 'NotAllowedError' || name === 'SecurityError') {
            return 'Der Zugriff auf ' + geraet + ' wurde abgelehnt. Bitte erlauben Sie ihn '
                 + 'in den Einstellungen des Browsers und versuchen Sie es erneut.';
        }
        if (name === 'NotFoundError' || name === 'OverconstrainedError'
            || name === 'ConstraintNotSatisfiedError') {
            return video
                ? 'Es wurde keine Kamera gefunden. Ohne Kamera lässt sich kein Bild übertragen.'
                : 'Es wurde kein Mikrofon gefunden. Ohne Mikrofon lässt sich kein Gespräch führen.';
        }
        if (name === 'NotReadableError' || name === 'TrackStartError') {
            return Geraet + ' lässt sich nicht öffnen. Vermutlich benutzt '
                 + (video ? 'sie' : 'es') + ' gerade ein anderes Programm.';
        }
        return Geraet + ' konnte nicht verwendet werden: '
             + (fehler && fehler.message ? fehler.message : 'unbekannter Fehler');
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

        // Bild und Ton der Gegenseite anzeigen
        window.webrtcApp.refs.localPeerConnection.ontrack = event => {
            window.webrtcApp.rtc.attachRemoteTrack(event);
        };
    },

    /**
     * Haengt eine eingehende Spur an das Videoelement der Gegenseite.
     *
     * HIER LAG DIE SCHWARZE FLAECHE. Die Stelle nahm ausschliesslich
     * event.streams[0] - und dieser Strom ist LEER, wenn die Gegenseite ihre
     * Spuren mit replaceTrack an einen bereits ausgehandelten Sender gelegt
     * hat statt mit addTrack(track, strom). replaceTrack ordnet einem Sender
     * naemlich keinen MediaStream zu; im SDP fehlt dann die msid, und der
     * Browser meldet das Ereignis ohne Strom. Genau so legt der Guide seine
     * Spuren (media.attachTracks), seit der Geraetewechsel ohne
     * Neuaushandlung laeuft. Ergebnis: srcObject wurde nie gesetzt, das
     * Videoelement blieb leer, der Zuschauer sah Schwarz - und hoerte auch
     * nichts, denn am selben Element haengt der Ton.
     *
     * Deshalb zwei Wege: Liegt ein Strom bei, wird er genommen. Liegt keiner
     * bei, fuehrt diese Stelle selbst einen und sammelt die Spuren darin
     * ein. Der zweite Weg ist von der SDP-Beschriftung der Gegenseite
     * unabhaengig und funktioniert deshalb auch mit einem Client, der es
     * anders macht.
     *
     * @param {RTCTrackEvent} event - Ereignis der PeerConnection
     */
    attachRemoteTrack(event) {
        const refs = window.webrtcApp.refs;
        const remoteVideo = document.getElementById('remote-video');
        let strom = (event && event.streams && event.streams[0]) ? event.streams[0] : null;

        if (strom) {
            refs.remoteStream = strom;
        } else {
            // Kein Strom im Ereignis: selbst einen fuehren.
            if (!refs.remoteStream) {
                refs.remoteStream = (typeof MediaStream === 'function') ? new MediaStream() : null;
            }
            strom = refs.remoteStream;
            if (strom && event && event.track
                && strom.getTracks().indexOf(event.track) === -1) {
                strom.addTrack(event.track);
            }
        }

        if (remoteVideo && strom && remoteVideo.srcObject !== strom) {
            remoteVideo.srcObject = strom;
            window.webrtcApp.rtc.playRemoteVideo();
        }

        if (event && event.track && event.track.kind === 'video') {
            window.webrtcApp.rtc.bindRemoteVideoTrack(event.track);
        }
    },

    /**
     * Startet die Wiedergabe der Gegenseite und faengt ab, wenn der Browser
     * sie verweigert.
     *
     * Mobile Browser lassen eine Wiedergabe MIT TON nur nach einer Geste des
     * Nutzers zu. Der Anrufer hat auf "Anrufen" gedrueckt, der Angerufene auf
     * "Annehmen" - normalerweise reicht das. Wird es abgelehnt, bliebe sonst
     * genau dieselbe schwarze Flaeche stehen, nur aus einem anderen Grund.
     * Dann sagt ein Hinweis, was zu tun ist, und der naechste Druck auf die
     * Ansicht startet die Wiedergabe.
     *
     * Der Hinweis bleibt beim GUIDE aus: Bei ihm ist dieses Element gar nicht
     * zu sehen (assets/css/call.css), und der Zuschauer sendet ihm ohnehin
     * nichts. Ein "Bitte auf das Bild tippen" fuer ein Bild, das es dort nicht
     * gibt, waere schlicht falsch.
     */
    playRemoteVideo() {
        const remoteVideo = document.getElementById('remote-video');
        if (!remoteVideo || typeof remoteVideo.play !== 'function') return;

        const versuch = remoteVideo.play();
        if (!versuch || typeof versuch.catch !== 'function') return;

        versuch.catch(() => {
            if (window.webrtcApp.state.callRole === window.webrtcApp.protocol.ROLE_GUIDE) return;
            window.webrtcApp.rtc.showSystemNotice(
                'Bitte einmal auf das Bild tippen, damit Ton und Bild starten.'
            );
            const ansicht = document.getElementById('call-view');
            if (!ansicht || typeof ansicht.addEventListener !== 'function') return;
            const nachholen = () => {
                ansicht.removeEventListener && ansicht.removeEventListener('click', nachholen);
                const p = remoteVideo.play();
                if (p && typeof p.catch === 'function') p.catch(() => {});
            };
            ansicht.addEventListener('click', nachholen);
        });
    },

    /**
     * Haengt sich an die eingehende Videospur, um zu merken, wann die
     * Gegenseite ihr Bild abschaltet.
     *
     * DAS IST DER ZWEITE WEG. Der erste ist die Nachricht video_state ueber
     * den Steuerkanal - schnell und ausdruecklich, aber sie setzt voraus,
     * dass der Kanal steht. Eine Spur, auf der nichts mehr gesendet wird
     * (replaceTrack(null) beim Gegenueber), wird dagegen vom Browser selbst
     * als "muted" gemeldet. Darauf ist Verlass, auch bei gestoerter
     * Verbindung.
     *
     * Ohne das blieb beim Gegenueber das letzte Standbild stehen - eine
     * abgeschaltete Kamera war nicht von einem eingefrorenen Bild zu
     * unterscheiden.
     *
     * @param {MediaStreamTrack} track - Die eingehende Videospur
     */
    bindRemoteVideoTrack(track) {
        if (!track) return;

        const zeigen = () => window.webrtcApp.rtc.setRemoteVideoVisible(true);
        const verbergen = () => window.webrtcApp.rtc.setRemoteVideoVisible(false);

        track.onunmute = zeigen;
        track.onmute   = verbergen;
        track.onended  = verbergen;

        // Der Anfangszustand steht schon fest, bevor ein Ereignis kommt.
        window.webrtcApp.rtc.setRemoteVideoVisible(track.muted !== true);
    },

    /**
     * Blendet das Bild der Gegenseite ein oder den Platzhalter an seiner
     * Stelle.
     *
     * Die einzige Stelle, die daran dreht. Vorher tat es der ontrack-Handler
     * mit dem einen Satz Klassen und control.handleVideoState() mit einem
     * anderen - die beiden konnten sich gegenseitig ueberschreiben.
     *
     * @param {boolean} sichtbar - true = Bild, false = Platzhalter
     */
    setRemoteVideoVisible(sichtbar) {
        const remoteVideo = document.getElementById('remote-video');
        const platzhalter = document.getElementById('remote-video-placeholder');

        if (remoteVideo) remoteVideo.style.display = sichtbar ? 'block' : 'none';
        if (platzhalter) platzhalter.style.display = sichtbar ? 'none' : 'flex';
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
            // Der eigene Videozustand gehoert zur Begruessung: Ohne ihn wuesste
            // die Gegenseite nicht, ob gerade kein Bild kommt oder nur noch
            // keins angekommen ist.
            window.webrtcApp.media.announceVideoState();
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
        // Meldung vor dem Abbau - siehe abortCall().
        window.webrtcApp.notify.error(msg);
        window.webrtcApp.rtc.endCall(false);
    }
};
