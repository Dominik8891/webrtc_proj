/**
 * Steuerprotokoll (Version 2) - Definition und Prüfung.
 *
 * Diese Datei ist die einzige Stelle, an der festgelegt ist, welche
 * Nachrichten es gibt, welche Felder sie tragen, über welchen DataChannel sie
 * laufen und wer sie senden darf. Sender und Empfänger benutzen dieselbe
 * Tabelle - die früheren drei unabhängigen Kopien der Magic Strings
 * (main.js / rtc.js / chat.js) sind damit ersetzt.
 *
 * Die menschenlesbare Fassung steht in PROTOKOLL.md. Beide müssen zusammen
 * geändert werden; PROTOKOLL.md ist die Referenz für die spätere App.
 *
 * Grundsätze:
 *   - Jede Nachricht ist JSON mit dem Pflichtfeld "v" (Protokollversion)
 *     und dem Pflichtfeld "type".
 *   - Zwei getrennte Kanäle: "chat" trägt ausschließlich Nutzerinhalt,
 *     "control" ausschließlich Maschinenprotokoll.
 *   - Was nicht in dieser Tabelle steht, wird verworfen und protokolliert -
 *     niemals als Chattext angezeigt.
 */
(function () {
    'use strict';

    // Version 2: Die Blickrichtungen "look_up" und "look_down" sind als
    // Bewegungsrichtungen dazugekommen, und neben "viewer" und "guide" gibt es
    // die Rolle "peer" fuer einen Anruf, in dem niemand fuehrt. Ein Client der
    // Version 1 kennt beides nicht und wuerde eine Blickanweisung als
    // ungueltig verwerfen - deshalb die neue Nummer.
    var VERSION = 2;

    var CHANNEL_CHAT    = 'chat';
    var CHANNEL_CONTROL = 'control';

    var ROLE_GUIDE  = 'guide';   // Person vor Ort, wird gesteuert
    var ROLE_VIEWER = 'viewer';  // Zuschauer, steuert

    // Anruf ohne Fuehrung: ein Gespraech unter Gleichen, wie es zwischen der
    // Verwaltung und einem Nutzer stattfindet. Beide senden Ton und Bild,
    // niemand steuert. Die Rolle steht ausdruecklich da, statt sie als
    // "keine Rolle" (null) auszudruecken: null heisst "noch nicht bekannt",
    // und solange nichts bekannt ist, muss die Oberflaeche vom Vorsichtigeren
    // ausgehen und darf nichts senden.
    var ROLE_PEER   = 'peer';

    // Bewegung und Blick. Beide laufen als "move": Es ist derselbe Vorgang -
    // der Zuschauer weist an, der Guide fuehrt aus und bestaetigt. Ein
    // eigener Nachrichtentyp haette Sequenznummer, Bestaetigung und Sperre
    // ein zweites Mal gebraucht.
    var DIRECTIONS = ['forward', 'backward', 'left', 'right', 'look_up', 'look_down'];

    // Gründe, aus denen ein Bewegungsbefehl abgelehnt wird. Sie gehen als
    // "reason" in der Bestätigung zurück, damit der Zuschauer weiß, warum
    // nichts passiert ist.
    var REJECT_REASONS = [
        'unstable',   // Verbindung war im Moment des Empfangs nicht stabil
        'locked',     // Der Guide hat die Steuerung gesperrt
        'duplicate',  // Sequenznummer schon gesehen (Wiederholung)
        'no_role',    // Empfänger kennt seine eigene Rolle nicht
        'invalid'     // Nachricht hat die Prüfung nicht bestanden
    ];

    // Obergrenze je Frame. Steuerbefehle sind wenige Dutzend Byte; alles
    // deutlich darüber ist entweder ein Fehler oder ein Angriffsversuch.
    var MAX_FRAME_BYTES = 4096;

    // Obergrenze für eine Chatnachricht. Der Kanal ist zuverlässig und
    // geordnet - ein sehr großer Text würde alles dahinter blockieren.
    var MAX_CHAT_TEXT = 2000;

    /**
     * Nachrichtentabelle.
     *
     * channel  Kanal, auf dem die Nachricht ausschließlich zulässig ist.
     * from     Wer sie senden darf: 'viewer', 'guide' oder 'any'.
     * fields   Erlaubte Felder samt Typ. Nicht aufgeführte Felder werden
     *          beim Empfang ignoriert (Vorwärtskompatibilität innerhalb
     *          derselben Version), fehlende Pflichtfelder führen zur
     *          Ablehnung.
     */
    var SCHEMA = {
        // ---- Kanal "control" -------------------------------------------
        hello: {
            channel: CHANNEL_CONTROL,
            from: 'any',
            fields: {
                role: { type: 'enum', values: [ROLE_GUIDE, ROLE_VIEWER, ROLE_PEER], required: true }
            }
        },
        move: {
            channel: CHANNEL_CONTROL,
            from: ROLE_VIEWER,
            fields: {
                dir: { type: 'enum', values: DIRECTIONS, required: true },
                seq: { type: 'int', min: 1, max: 2147483647, required: true }
            }
        },
        ack: {
            channel: CHANNEL_CONTROL,
            from: ROLE_GUIDE,
            fields: {
                seq:    { type: 'int',  min: 1, max: 2147483647, required: true },
                status: { type: 'enum', values: ['executed', 'rejected'], required: true },
                reason: { type: 'enum', values: REJECT_REASONS, required: false }
            }
        },
        control_lock: {
            channel: CHANNEL_CONTROL,
            from: ROLE_GUIDE,
            fields: {
                locked: { type: 'boolean', required: true },
                reason: { type: 'text', maxLength: 120, required: false }
            }
        },
        video_state: {
            channel: CHANNEL_CONTROL,
            from: 'any',
            fields: {
                on: { type: 'boolean', required: true }
            }
        },
        hangup: {
            channel: CHANNEL_CONTROL,
            from: 'any',
            fields: {
                reason: { type: 'text', maxLength: 120, required: false }
            }
        },

        // ---- Kanal "chat" ----------------------------------------------
        chat: {
            channel: CHANNEL_CHAT,
            from: 'any',
            fields: {
                text: { type: 'text', maxLength: MAX_CHAT_TEXT, required: true }
            }
        }
    };

    /**
     * Länge in Byte, nicht in Zeichen. Ein Emoji ist ein Zeichen, aber vier
     * Byte - für eine Obergrenze zählt die Byte-Zahl.
     * @param {string} text
     * @returns {number}
     */
    function byteLength(text) {
        if (typeof TextEncoder !== 'undefined') {
            return new TextEncoder().encode(text).length;
        }
        // Rückfallebene für Umgebungen ohne TextEncoder.
        return unescape(encodeURIComponent(text)).length;
    }

    /**
     * Prüft einen einzelnen Feldwert gegen seine Regel.
     * @param {*} value - Der zu prüfende Wert
     * @param {Object} rule - Eintrag aus SCHEMA[...].fields
     * @returns {string|null} Fehlertext oder null, wenn in Ordnung
     */
    function checkField(value, rule) {
        switch (rule.type) {
            case 'enum':
                if (typeof value !== 'string') return 'muss eine Zeichenkette sein';
                if (rule.values.indexOf(value) === -1) {
                    return 'unzulaessiger Wert "' + value + '"';
                }
                return null;

            case 'int':
                // Bewusst streng: "5" als Zeichenkette ist kein gültiger Wert.
                // Sonst müsste jede Empfangsstelle selbst umwandeln.
                if (typeof value !== 'number' || !isFinite(value)) return 'muss eine Zahl sein';
                if (Math.floor(value) !== value) return 'muss ganzzahlig sein';
                if (rule.min !== undefined && value < rule.min) return 'kleiner als ' + rule.min;
                if (rule.max !== undefined && value > rule.max) return 'groesser als ' + rule.max;
                return null;

            case 'boolean':
                if (typeof value !== 'boolean') return 'muss true oder false sein';
                return null;

            case 'text':
                if (typeof value !== 'string') return 'muss eine Zeichenkette sein';
                if (rule.maxLength !== undefined && value.length > rule.maxLength) {
                    return 'laenger als ' + rule.maxLength + ' Zeichen';
                }
                return null;

            default:
                return 'unbekannter Feldtyp im Schema';
        }
    }

    window.webrtcApp = window.webrtcApp || {};

    window.webrtcApp.protocol = {
        VERSION: VERSION,
        CHANNEL_CHAT: CHANNEL_CHAT,
        CHANNEL_CONTROL: CHANNEL_CONTROL,
        ROLE_GUIDE: ROLE_GUIDE,
        ROLE_VIEWER: ROLE_VIEWER,
        ROLE_PEER: ROLE_PEER,
        DIRECTIONS: DIRECTIONS,
        REJECT_REASONS: REJECT_REASONS,
        MAX_FRAME_BYTES: MAX_FRAME_BYTES,
        MAX_CHAT_TEXT: MAX_CHAT_TEXT,
        SCHEMA: SCHEMA,

        /**
         * Rolle der Gegenseite. In einem Call gibt es genau zwei Teilnehmer,
         * also ist die Rolle des Partners durch die eigene festgelegt.
         *
         * In einem Anruf ohne Fuehrung sind beide "peer" - die Gegenseite
         * eines peer ist wieder ein peer. Weil "peer" weder "viewer" noch
         * "guide" ist, faellt in einem solchen Call jede richtungsgebundene
         * Nachricht (move, ack, control_lock) von selbst durch die Pruefung in
         * validate(). Es braucht dafuer keine zweite Abfrage.
         *
         * @param {string|null} ownRole
         * @returns {string|null} 'guide', 'viewer', 'peer' oder null bei unbekannter Rolle
         */
        peerRole: function (ownRole) {
            if (ownRole === ROLE_GUIDE)  return ROLE_VIEWER;
            if (ownRole === ROLE_VIEWER) return ROLE_GUIDE;
            if (ownRole === ROLE_PEER)   return ROLE_PEER;
            return null;
        },

        /**
         * Darf ich diesen Nachrichtentyp mit meiner Rolle senden?
         * @param {string} type - Nachrichtentyp
         * @param {string|null} ownRole - Eigene Rolle
         * @returns {boolean}
         */
        maySend: function (type, ownRole) {
            var schema = SCHEMA[type];
            if (!schema) return false;
            if (schema.from === 'any') return true;
            return schema.from === ownRole;
        },

        /**
         * Baut eine Nachricht mit Versionsfeld.
         * @param {string} type - Nachrichtentyp aus SCHEMA
         * @param {Object} [payload] - Felder der Nachricht
         * @returns {Object} Nachrichtenobjekt
         */
        build: function (type, payload) {
            var msg = { v: VERSION, type: type };
            if (payload) {
                Object.keys(payload).forEach(function (key) {
                    if (payload[key] !== undefined) msg[key] = payload[key];
                });
            }
            return msg;
        },

        /**
         * Baut eine Nachricht und gibt sie als JSON-Zeichenkette zurück.
         *
         * Vor dem Senden wird gegen dasselbe Schema geprüft wie beim
         * Empfangen. Ein fehlerhafter Aufruf im eigenen Code fällt so sofort
         * auf und geht nicht als kaputte Nachricht auf die Leitung.
         *
         * @param {string} type - Nachrichtentyp
         * @param {Object} [payload] - Felder
         * @returns {string|null} JSON oder null, wenn die Nachricht ungültig ist
         */
        serialize: function (type, payload) {
            var schema = SCHEMA[type];
            if (!schema) {
                console.warn('[Protokoll] Unbekannter Nachrichtentyp beim Senden:', type);
                return null;
            }

            var msg = this.build(type, payload);
            var problem = this.checkFields(msg, schema);
            if (problem) {
                console.warn('[Protokoll] Eigene Nachricht ist ungueltig (' + type + '): ' + problem);
                return null;
            }

            var raw = JSON.stringify(msg);
            if (byteLength(raw) > MAX_FRAME_BYTES) {
                console.warn('[Protokoll] Eigene Nachricht ist zu gross (' + type + ').');
                return null;
            }
            return raw;
        },

        /**
         * Prüft alle Felder einer Nachricht gegen ihr Schema.
         * @param {Object} msg - Nachrichtenobjekt
         * @param {Object} schema - Eintrag aus SCHEMA
         * @returns {string|null} Fehlertext oder null
         */
        checkFields: function (msg, schema) {
            var names = Object.keys(schema.fields);
            for (var i = 0; i < names.length; i++) {
                var name = names[i];
                var rule = schema.fields[name];
                var value = msg[name];

                if (value === undefined || value === null) {
                    if (rule.required) return 'Pflichtfeld "' + name + '" fehlt';
                    continue;
                }
                var problem = checkField(value, rule);
                if (problem) return 'Feld "' + name + '": ' + problem;
            }
            return null;
        },

        /**
         * Prüft eine eingehende Nachricht vollständig.
         *
         * Reihenfolge der Prüfungen ist bewusst so gewählt, dass die
         * billigsten und die gefährlichsten zuerst kommen: Typ des Frames,
         * Größe, JSON, Version, Nachrichtentyp, Kanal, Senderichtung, Felder.
         *
         * @param {*} raw - Rohdaten aus dem DataChannel
         * @param {string} channel - Kanal, auf dem sie ankamen ('chat'|'control')
         * @param {string|null} ownRole - Eigene, vom Server vergebene Rolle
         * @returns {Object} {ok:true, message} oder {ok:false, code, detail}
         */
        validate: function (raw, channel, ownRole) {
            if (typeof raw !== 'string') {
                return { ok: false, code: 'not_text', detail: 'Frame ist kein Text' };
            }
            if (byteLength(raw) > MAX_FRAME_BYTES) {
                return { ok: false, code: 'too_large', detail: 'Frame ueberschreitet ' + MAX_FRAME_BYTES + ' Byte' };
            }

            var msg;
            try {
                msg = JSON.parse(raw);
            } catch (e) {
                return { ok: false, code: 'not_json', detail: 'kein gueltiges JSON' };
            }

            if (msg === null || typeof msg !== 'object' || Array.isArray(msg)) {
                return { ok: false, code: 'not_object', detail: 'kein JSON-Objekt' };
            }

            // Version vor dem Typ prüfen: Eine unbekannte Version darf nicht
            // in die Typprüfung laufen, sonst würde ein gleichnamiger Typ
            // einer anderen Version fälschlich akzeptiert.
            if (typeof msg.v !== 'number' || Math.floor(msg.v) !== msg.v) {
                return { ok: false, code: 'bad_version', detail: 'Feld "v" fehlt oder ist keine Ganzzahl' };
            }
            if (msg.v !== VERSION) {
                return { ok: false, code: 'bad_version', detail: 'Version ' + msg.v + ', erwartet ' + VERSION };
            }

            if (typeof msg.type !== 'string') {
                return { ok: false, code: 'unknown_type', detail: 'Feld "type" fehlt' };
            }
            // hasOwnProperty, damit "constructor" oder "toString" nicht als
            // Nachrichtentyp durchgehen.
            if (!Object.prototype.hasOwnProperty.call(SCHEMA, msg.type)) {
                return { ok: false, code: 'unknown_type', detail: 'unbekannter Typ "' + msg.type + '"' };
            }
            var schema = SCHEMA[msg.type];

            if (schema.channel !== channel) {
                return {
                    ok: false,
                    code: 'wrong_channel',
                    detail: '"' + msg.type + '" gehoert auf Kanal "' + schema.channel + '", kam auf "' + channel + '"'
                };
            }

            // Senderichtung: Absender ist immer die Gegenseite, deren Rolle
            // sich aus der eigenen ergibt. Ist die eigene Rolle unbekannt,
            // wird jede richtungsgebundene Nachricht abgelehnt - im Zweifel
            // steuert niemand.
            if (schema.from !== 'any') {
                var sender = this.peerRole(ownRole);
                if (sender === null) {
                    return { ok: false, code: 'no_role', detail: 'eigene Rolle unbekannt' };
                }
                if (sender !== schema.from) {
                    return {
                        ok: false,
                        code: 'forbidden_direction',
                        detail: '"' + msg.type + '" darf nur der ' + schema.from + ' senden, Gegenseite ist ' + sender
                    };
                }
            }

            var problem = this.checkFields(msg, schema);
            if (problem) {
                return { ok: false, code: 'bad_field', detail: problem };
            }

            return { ok: true, message: msg };
        }
    };
})();
