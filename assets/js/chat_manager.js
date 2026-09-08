
// Falls das globale Objekt noch nicht existiert, initialisieren (Schutz für mehrfaches Einbinden)
window.webrtcApp = window.webrtcApp || {};

/**
 * ChatManager: zentrale Verwaltung aller Chat-Instanzen und deren Status.
 * Jeder Chat wird als Eintrag im Objekt "chats" gespeichert.
 * Key ist die Chat-ID, Value ist ein Objekt mit Status und Infos zum Chat.
 *
 * WAS HIER NICHT MEHR STEHT: setActive() und isActive(). Sie trugen den
 * Zustand der Einladung - "angenommen oder noch offen" -, und danach richtete
 * sich, ob es ueberhaupt ein Eingabefeld gab. Die Einladung ist mit Migration
 * 019 entfallen: Ein Chat entsteht nur noch zwischen einem Kunden und dem
 * Guide eines Standorts, und wer ein Fenster offen hat, kann schreiben.
 */
window.webrtcApp.chatManager = {
    /**
     * Speichert alle aktiven Chats als Mapping von chatId -> Chat-Objekt.
     * Jedes Chat-Objekt enthält:
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
                teilnehmer: teilnehmer || [], // Teilnehmer-IDs als Array
                lastMsgId: 0               // Letzte Nachrichten-ID (für Benachrichtigungen)
            };
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
     * Entfernt einen Chat aus dem Manager (z.B. beim Schliessen des Fensters).
     * @param {string|number} chatId   Die Chat-ID
     */
    removeChat(chatId) {
        if (this.chats[chatId]) {
            delete this.chats[chatId];
        }
    }
};
