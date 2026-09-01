// Globales webrtcApp-Objekt für sämtliche WebRTC/Chat-Funktionalität
window.webrtcApp = {
    // ----- Debug-Ausgaben -----
    // Standardmaessig AUS. Auf true setzen, um ausfuehrliche Signaling-Logs
    // (SDP, ICE-Kandidaten) in der Browser-Konsole zu sehen. Diese Daten
    // gehoeren nicht in eine produktive Konsole, deshalb der Schalter.
    // Zum Debuggen zur Laufzeit: window.webrtcApp.debug = true
    debug: false,

    // ----- Statusvariablen für die aktuelle Session -----
    state: {
        activeTargetUserId: null,          // User-ID des aktuell gewählten Chat-/Call-Partners
        hangupReceived: false,             // Wurde ein Auflegen ("Hangup") empfangen?
        isCallActive: false,               // Ist gerade ein Anruf aktiv?
        pendingOffer: null,                // Zwischengespeichertes Offer für WebRTC-Handshake
        tracksAdded: false,                // Wurden MediaTracks schon zu RTCPeerConnection hinzugefügt?
        targetUsername: null,              // Benutzername des Call-Partners (zur Anzeige)
        remoteVideoCheckInterval: null,    // Timer-ID für Überprüfung des Remote-Video-Streams
        callTimeout: null,                 // Timeout für eingehende Calls (z.B. Rufannahme abgelaufen)

        // ----- Verbindungsstabilität / Reconnect -----
        // Wer den ursprünglichen Offer gesendet hat, führt den ICE-Restart aus.
        // Ohne diese Festlegung würden bei einer Störung beide Seiten
        // gleichzeitig einen Offer schicken (Glare) und die Aushandlung
        // scheitern.
        isInitiator: false,
        // Sichtbarer Verbindungszustand:
        // 'idle' | 'connecting' | 'connected' | 'unstable' | 'reconnecting' | 'disconnected'
        connectionStatus: 'idle',
        // Zeitpunkt, an dem die Verbindung zuletzt als stabil galt. Steuerbefehle
        // werden kurz danach noch verworfen (siehe rtc.canSendControlCommand).
        connectedSince: null,

        // ----- Steuerprotokoll -----
        // Rolle in diesem Call: 'guide' | 'viewer' | null.
        // Sie kommt vom Server (WebRTCController stempelt sie an das Offer)
        // und wird hier NUR abgelegt - der Client vergibt sie sich nicht
        // selbst. null heisst: Rolle unbekannt, also steuert niemand.
        callRole: null,

        // Zustand des Steuerkanals. Wird von control.reset() bei jedem
        // Call-Ende geleert, damit nichts in den naechsten Call laeuft.
        control: {
            nextSeq: 1,             // Zuschauer: naechste zu vergebende Sequenznummer
            pendingSeq: null,       // Zuschauer: Befehl, dessen Bestaetigung aussteht
            ackTimer: null,         // Frist, nach der ohne Bestaetigung freigegeben wird
            lastRemoteSeq: 0,       // Guide: hoechste bereits ausgefuehrte Sequenznummer
            locked: false,          // Steuerung gesperrt (Guide hat angehalten)
            helloSent: false,       // Wurde die eigene Rolle schon gemeldet?
            indicatorTimer: null,   // Frist der grossen Richtungsanzeige
            rejected: 0,            // Zahl verworfener Protokollnachrichten
            lastRejectCode: null    // Code der zuletzt verworfenen Nachricht
        },
        reconnect: {
            graceTimer: null,              // 5-s-Frist bei "disconnected", bevor neu ausgehandelt wird
            deadlineTimer: null,           // Gesamtfrist, danach wird endgültig beendet
            retryTimer: null,              // Nächster Restart-Versuch, falls der letzte nichts brachte
            attempts: 0,                   // Bisherige ICE-Restart-Versuche in dieser Störung
            inProgress: false              // Läuft gerade ein Wiederverbindungsversuch?
        }
    },

    // ----- Referenzen auf aktuelle Verbindungen und Streams -----
    refs: {
        localPeerConnection: null,         // RTCPeerConnection-Objekt für die Verbindung

        // Zwei getrennte DataChannels statt eines gemeinsamen: "chat" traegt
        // ausschliesslich Nutzerinhalt (Text, Dateien), "control" ausschliesslich
        // Protokollnachrichten (Bewegung, Sperre, Bestaetigung, Videozustand,
        // Auflegen). Siehe PROTOKOLL.md.
        chatChannel: null,                 // RTCDataChannel "chat"
        controlChannel: null,              // RTCDataChannel "control"
        localStream: null,                 // Lokaler MediaStream (Webcam/Mikrofon)
        pollingIntervalId: null,           // ID des Polling-Intervalls für Fallback-Signalisierung
        meteredIceServers: null,           // ICE-Server-Konfiguration (z.B. von Metered TURN/STUN)
        iceServersLoaded: false,           // Wurden ICE-Server-Konfigurationen schon geladen?
        iceServersDegraded: false,         // true = Liste ist ein Notbehelf (kein TURN), beim nächsten Call neu laden
        pollingIntervalMs: null,           // Aktuell eingestelltes Poll-Intervall in ms
        pendingCandidates: []              // ICE-Kandidaten, die gepuffert werden bis PeerConnection bereit ist
    },

    // ----- Hauptmodule (werden im Verlauf befüllt) -----
    rtc: {},              // WebRTC-spezifische Logik und Methoden (Verbindungsaufbau, Handling etc.)
    protocol: {},         // Definition und Pruefung des Steuerprotokolls (protocol.js)
    control: {},          // Steuerkanal: Senden, Empfangen, Anzeige (control.js)
    ui: {},               // User-Interface-Logik (Fenstersteuerung, Anzeigen, UI-Interaktionen)
    sound: {},            // Sounds für Call (Klingeln, Auflegen etc.)
    chat: {},             // Chat-Logik (Nachrichtenverarbeitung, Verlauf, Rendering)
    signaling: {},        // Signal-Transport (Polling, AJAX, WebSocket etc.)
    utils: {},            // Hilfsfunktionen (z.B. Logging, Zeitformatierung)
    locationsTable: {},   // Modul zur Verwaltung der Locations-Tabelle (z.B. Daten-Rendering)
    locationMap: {},      // Modul für Kartenfunktionalität (z.B. Google Maps oder Leaflet)

    // ----- Weitere spezialisierte Module -----
    chatManager: {},      // (optional) Verwaltung von Chat-Sitzungen, mehrere parallele Chats etc.
    uiChat: {},           // (optional) UI-spezifische Methoden für Chat (z.B. Ein-/Ausblenden, Scrollen)
    uiRtc: {}             // (optional) UI-spezifische Methoden für WebRTC (z.B. Call-Buttons)
};
