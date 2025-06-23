// Globales webrtcApp-Objekt für sämtliche WebRTC/Chat-Funktionalität
window.webrtcApp = {
    // ----- Statusvariablen für die aktuelle Session -----
    state: {
        activeTargetUserId: null,          // User-ID des aktuell gewählten Chat-/Call-Partners
        hangupReceived: false,             // Wurde ein Auflegen ("Hangup") empfangen?
        isCallActive: false,               // Ist gerade ein Anruf aktiv?
        pendingOffer: null,                // Zwischengespeichertes Offer für WebRTC-Handshake
        tracksAdded: false,                // Wurden MediaTracks schon zu RTCPeerConnection hinzugefügt?
        targetUsername: null,              // Benutzername des Call-Partners (zur Anzeige)
        remoteVideoCheckInterval: null,    // Timer-ID für Überprüfung des Remote-Video-Streams
        callTimeout: null                  // Timeout für eingehende Calls (z.B. Rufannahme abgelaufen)
    },

    // ----- Referenzen auf aktuelle Verbindungen und Streams -----
    refs: {
        localPeerConnection: null,         // RTCPeerConnection-Objekt für die Verbindung
        dataChannel: null,                 // RTCDataChannel für Textnachrichten etc.
        localStream: null,                 // Lokaler MediaStream (Webcam/Mikrofon)
        pollingIntervalId: null,           // ID des Polling-Intervalls für Fallback-Signalisierung
        meteredIceServers: null,           // ICE-Server-Konfiguration (z.B. von Metered TURN/STUN)
        iceServersLoaded: false,           // Wurden ICE-Server-Konfigurationen schon geladen?
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
