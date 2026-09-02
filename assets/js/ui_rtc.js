// assets/js/ui_rtc.js

window.webrtcApp = window.webrtcApp || {};

/**
 * Modul für alle UI-Funktionen rund um RTC/Call (vorher in ui.js).
 * Beinhaltet: Sichtbarkeit der Call-Buttons, Initialisierung der Call-Chat-UI und Username-Auflösung.
 */
window.webrtcApp.uiRtc = {
    /**
     * Zeigt oder versteckt den "End Call"-Button.
     * @param {boolean} visible - true = anzeigen, false = verstecken
     */
    setEndCallButtonVisible: function(visible) {
        const btn = document.getElementById('end-call-btn');
        if (btn) btn.style.display = visible ? '' : 'none';
    },

    /**
     * Initialisiert die UI für den Call-Chat (Textnachrichten & Datei-Upload).
     * Fügt Eventlistener für Send-Button, Enter-Taste und Datei-Upload hinzu.
     */
    initChatUI: function() {
        // Nur einmal binden. Diese Methode wird an zwei Stellen aufgerufen:
        // von window.webrtcApp.init() (assets/js/main.js) und noch einmal aus
        // dem DOMContentLoaded-Handler am Ende dieser Datei. Beide laufen bei
        // jedem Seitenaufruf - ohne diese Sperre haengen zwei Handler am
        // Senden-Knopf und jede Nachricht ginge doppelt raus.
        if (this.chatUiReady) return;
        this.chatUiReady = true;

        const sendBtn = document.getElementById("chat-send-btn");
        const chatInput = document.getElementById("chat-input");
        if (sendBtn && chatInput) {
            // Textnachricht senden
            sendBtn.addEventListener('click', function() {
                const msg = chatInput.value;
                if (msg) {
                    window.webrtcApp.chat.send(msg);
                    chatInput.value = "";
                }
            });
            // Enter = Senden
            chatInput.addEventListener('keydown', function(e) {
                if (e.key === "Enter") sendBtn.click();
            });
        }
        // Datei senden
        const fileInput = document.getElementById("file-input");
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                if (e.target.files.length) window.webrtcApp.chat.sendFile(e.target.files[0]);
            });
        }
    },

    /**
     * Holt den Usernamen für die Anzeige im Call.
     * @param {number} userId - ID des Users
     * @returns {Promise<string>} Usernamen (oder leere Zeichenkette bei Fehler)
     */
    getUsername: function(userId) {
        return fetch('index.php?act=get_username', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(userId)
        })
        .then(r => r.text())
        .catch(console.error);
    },
};

// Bei DOM-Ready Chat-UI initialisieren (sofern vorhanden)
document.addEventListener('DOMContentLoaded', function() {
    if(document.getElementById("chat-send-btn") || document.getElementById("chat-input")) {
        window.webrtcApp.uiRtc.initChatUI();
    }
});
