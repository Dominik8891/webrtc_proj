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
        dataChannel: null,                 // RTCDataChannel für Textnachrichten etc.
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
