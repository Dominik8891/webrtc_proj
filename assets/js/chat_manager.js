
// Falls das globale Objekt noch nicht existiert, initialisieren (Schutz für mehrfaches Einbinden)
window.webrtcApp = window.webrtcApp || {};

/**
 * ChatManager: zentrale Verwaltung aller Chat-Instanzen und deren Status.
 * Jeder Chat wird als Eintrag im Objekt "chats" gespeichert.
 * Key ist die Chat-ID, Value ist ein Objekt mit Status und Infos zum Chat.
 */
window.webrtcApp.chatManager = {
    /**
     * Speichert alle aktiven Chats als Mapping von chatId -> Chat-Objekt.
     * Jedes Chat-Objekt enthält:
     * - isActive: Ob der Chat aktiv ist (angenommen/gestartet)
     * - teilnehmer: Array der User-IDs, die an diesem Chat beteiligt sind
     * - lastMsgId: ID der letzten Nachricht (für Benachrichtigung/Synchronisierung)
     */
    chats: {},

    /**
     * Legt einen neuen Chat an (falls nicht vorhanden).
     * @param {string|number} chatId   Eindeutige ID des Chats (meist von der DB vergeben)
     * @param {Array} teilnehmer       Array der User-IDs der Teilnehmer (optional)
     */
    createChat(chatId, teilnehmer) {
        if (!this.chats[chatId]) {
            this.chats[chatId] = {
                isActive: false,           // Standardmäßig noch nicht aktiv
                teilnehmer: teilnehmer || [], // Teilnehmer-IDs als Array
                lastMsgId: 0               // Letzte Nachrichten-ID (für Benachrichtigungen)
            };
        }
    },

    /**
     * Setzt den Aktiv-Status eines Chats (z.B. nach Annahme der Einladung).
     * @param {string|number} chatId   Die Chat-ID
     * @param {boolean} isActive       Soll der Chat als aktiv markiert werden?
     */
    setActive(chatId, isActive) {
        if (this.chats[chatId]) {
            this.chats[chatId].isActive = !!isActive;
        }
    },

    /**
     * Setzt die Teilnehmerliste eines Chats neu (z.B. nach Nachladen der Daten).
     * @param {string|number} chatId   Die Chat-ID
     * @param {Array} teilnehmer       Neue Teilnehmer-IDs als Array
     */
    setTeilnehmer(chatId, teilnehmer) {
        if (this.chats[chatId]) {
            this.chats[chatId].teilnehmer = teilnehmer;
        }
    },

    /**
     * Gibt die Teilnehmer eines Chats zurück.
     * @param {string|number} chatId   Die Chat-ID
     * @returns {Array}                Array der User-IDs der Teilnehmer
     */
    getTeilnehmer(chatId) {
        return this.chats[chatId] ? this.chats[chatId].teilnehmer : [];
    },

    /**
     * Gibt zurück, ob ein Chat aktiv ist.
     * @param {string|number} chatId   Die Chat-ID
     * @returns {boolean}              true, wenn aktiv, sonst false
     */
    isActive(chatId) {
        return !!(this.chats[chatId] && this.chats[chatId].isActive);
    },

    /**
     * Setzt die ID der letzten Nachricht in diesem Chat.
     * Kann z.B. genutzt werden, um neue Nachrichten zu erkennen (Benachrichtigungen).
     * @param {string|number} chatId   Die Chat-ID
     * @param {number} msgId           Die Nachrichten-ID
     */
    setLastMsgId(chatId, msgId) {
        if (this.chats[chatId]) {
            this.chats[chatId].lastMsgId = msgId;
        }
    },

    /**
     * Gibt die letzte Nachrichten-ID eines Chats zurück.
     * @param {string|number} chatId   Die Chat-ID
     * @returns {number}               ID der letzten Nachricht oder 0
     */
    getLastMsgId(chatId) {
        return this.chats[chatId] ? this.chats[chatId].lastMsgId : 0;
    },

    /**
     * Prüft, ob ein Chat-Objekt für die angegebene Chat-ID existiert.
     * @param {string|number} chatId   Die Chat-ID
     * @returns {boolean}              true, wenn Chat vorhanden, sonst false
     */
    hasChat(chatId) {
        return !!this.chats[chatId];
    },

    /**
     * Entfernt einen Chat aus dem Manager (z.B. nach Löschen oder Ablehnen).
     * @param {string|number} chatId   Die Chat-ID
     */
    removeChat(chatId) {
        if (this.chats[chatId]) {
            delete this.chats[chatId];
        }
    }
};
