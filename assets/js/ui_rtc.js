// assets/js/ui_rtc.js

window.webrtcApp = window.webrtcApp || {};

/**
 * Alle UI-Funktionen rund um RTC/Call – vorher in ui.js
 */
window.webrtcApp.uiRtc = {
    /**
     * Zeigt/Versteckt den End-Call-Button.
     */
    setEndCallButtonVisible: function(visible) {
        const btn = document.getElementById('end-call-btn');
        if (btn) btn.style.display = visible ? '' : 'none';
    },

    /**
     * Initialisiert die Call-Chat-UI (Text und Datei).
     */
    initChatUI: function() {
        const sendBtn = document.getElementById("chat-send-btn");
        const chatInput = document.getElementById("chat-input");
        if (sendBtn && chatInput) {
            sendBtn.addEventListener('click', function() {
                const msg = chatInput.value;
                if (msg) {
                    window.webrtcApp.chat.send(msg);
                    chatInput.value = "";
                }
            });
            chatInput.addEventListener('keydown', function(e) {
                if (e.key === "Enter") sendBtn.click();
            });
        }
        const fileInput = document.getElementById("file-input");
        if (fileInput) {
            fileInput.addEventListener('change', function(e) {
                if (e.target.files.length) window.webrtcApp.chat.sendFile(e.target.files[0]);
            });
        }
    },

    /**
     * Holt den Usernamen für die Call-UI.
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

document.addEventListener('DOMContentLoaded', function() {
    // Nur initialisieren, wenn die Call-View/Chat-UI existiert
    if(document.getElementById("chat-send-btn") || document.getElementById("chat-input")) {
        window.webrtcApp.uiRtc.initChatUI();
    }
});
