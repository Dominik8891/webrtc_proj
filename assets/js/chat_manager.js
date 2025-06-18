// assets/js/chat_manager.js

window.webrtcApp = window.webrtcApp || {};

/**
 * ChatManager: zentrale Verwaltung aller Chat-Instanzen und deren Status
 */
window.webrtcApp.chatManager = {
    chats: {}, // chatId => { isActive, teilnehmer: [userId, ...], lastMsgId }

    // Einen Chat initial anlegen
    createChat(chatId, teilnehmer) {
        if (!this.chats[chatId]) {
            this.chats[chatId] = {
                isActive: false,
                teilnehmer: teilnehmer || [],
                lastMsgId: 0
            };
        }
    },

    // Chat als aktiv markieren (z.B. nach Annahme)
    setActive(chatId, isActive) {
        if (this.chats[chatId]) {
            this.chats[chatId].isActive = !!isActive;
        }
    },

    // Teilnehmer setzen (oder aktualisieren)
    setTeilnehmer(chatId, teilnehmer) {
        if (this.chats[chatId]) {
            this.chats[chatId].teilnehmer = teilnehmer;
        }
    },

    // Teilnehmer holen
    getTeilnehmer(chatId) {
        return this.chats[chatId] ? this.chats[chatId].teilnehmer : [];
    },

    // Status abfragen
    isActive(chatId) {
        return !!(this.chats[chatId] && this.chats[chatId].isActive);
    },

    // Letzte Message ID setzen (für Notification/Sync)
    setLastMsgId(chatId, msgId) {
        if (this.chats[chatId]) {
            this.chats[chatId].lastMsgId = msgId;
        }
    },

    getLastMsgId(chatId) {
        return this.chats[chatId] ? this.chats[chatId].lastMsgId : 0;
    },

    // Existenz eines Chats prüfen
    hasChat(chatId) {
        return !!this.chats[chatId];
    },

    // Chat entfernen
    removeChat(chatId) {
        if (this.chats[chatId]) {
            delete this.chats[chatId];
        }
    }
};
