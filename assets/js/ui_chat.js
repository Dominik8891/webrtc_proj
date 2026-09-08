/**
 * Die Chatfenster: oeffnen, schliessen, minimieren, senden, empfangen.
 *
 * WIE EIN CHAT ENTSTEHT
 * ---------------------
 * UEBER EINEN STANDORT. Ein angemeldeter Kunde klickt auf der Standortseite
 * "Frage an den Guide"; das Fenster geht auf, und er schreibt. Wen er
 * anschreibt, sagt der Standort - dieses Modul schickt eine
 * STANDORTKENNUNG an den Server und keine Kontokennung
 * (App\Controller\ChatController::startChat).
 *
 * Das ist beides zugleich: der Weg zum Chat, den ein Kunde vorher gar nicht
 * hatte - er fuehrte nur ueber die Benutzerliste, und die sieht allein der
 * Admin -, und die Einschraenkung auf eine Beziehung (Befund N-12). Wer
 * vorher die Route kannte, konnte jedem Konto der Plattform schreiben.
 *
 * DER ADMIN HAT EINEN ZWEITEN WEG: openChatWithUser() aus der Benutzerliste,
 * gegen die Route chat_start_direct und das Recht chat.start_direct. Fuer
 * alle anderen Konten antwortet sie mit einer Ablehnung.
 *
 * WAS HIER NICHT MEHR STEHT: DIE EINLADUNG
 * ----------------------------------------
 * Ein Chat war vorher erst eine Einladung: Der Angeschriebene bekam ein
 * Fenster mit "X moechte mit Ihnen chatten" und zwei Knoepfen, und erst nach
 * dem Annehmen gab es ueberhaupt ein Eingabefeld. Damit sind entfallen:
 * openInvitationTab(), acceptChat(), declineChat(), setTabUiByActiveState()
 * und das Einladungs-Polling.
 *
 * Der Grund: Die Mechanik stammt aus einer Anwendung, in der jeder jeden
 * anschreiben konnte - dort ist sie der Schutz vor Fremden. Hier gibt es
 * diesen Fremden nicht mehr, und sie hielt ausgerechnet die erste Nachricht
 * zurueck, also den Inhalt, an dem der Guide seine Entscheidung haette
 * treffen koennen. Die Begruendung im Ganzen steht in
 * migrations/019_standort_chat.sql.
 *
 * DASS UEBERHAUPT ETWAS DA IST, sagt ausserdem der Zaehler in der Kopfleiste
 * (assets/js/chat_badge.js). Er steht auf jeder Seite; die Fenster hier gibt
 * es nur, solange man sie offen laesst.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.uiChat = {

    /**
     * Oeffnet den Chat mit dem Guide EINES STANDORTS.
     *
     * Der uebliche Weg. Aufgerufen vom Knopf auf der Standortseite
     * (assets/js/main.js); wer der Guide ist, entscheidet der Server.
     *
     * @param {number|string} locationId
     * @param {string}        [partnerName] Nur fuer die Beschriftung, solange
     *                        die Antwort noch nicht da ist
     */
    openChatForLocation: function(locationId, partnerName) {
        this.oeffne({ location_id: locationId }, 'chat_start', partnerName);
    },

    /**
     * Oeffnet den Chat mit einem BELIEBIGEN Konto - der Direktzugang der
     * Verwaltung aus der Benutzerliste.
     *
     * Nur mit dem Recht chat.start_direct; fuer alle anderen antwortet die
     * Route mit einer Ablehnung. Der Knopf steht ohnehin nur in der
     * Benutzerliste, und die sieht nur der Admin.
     *
     * @param {number|string} userId
     * @param {string}        [partnerName]
     */
    openChatWithUser: function(userId, partnerName) {
        this.oeffne({ target_id: userId }, 'chat_start_direct', partnerName);
    },

    /**
     * Der gemeinsame Ablauf beider Wege.
     *
     * Er steht hier und nicht zweimal ausgeschrieben: Was sich unterscheidet,
     * ist die Route und das eine Feld darin - alles danach ist dasselbe
     * Fenster.
     *
     * @param {Object} felder  Was mitgeschickt wird (location_id oder target_id)
     * @param {string} route   Die Aktion (chat_start oder chat_start_direct)
     * @param {string} [partnerName]
     */
    oeffne: function(felder, route, partnerName) {
        // Container anlegen, falls noch nicht vorhanden
        let $container = $('#chat-popup-container');
        if (!$container.length) {
            $('body').append('<div id="chat-popup-container"></div>');
            $container = $('#chat-popup-container');
        }

        fetch('?act=' + route, {
            method: 'POST',
            body: new URLSearchParams(felder),
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (!data || !data.success) {
                window.webrtcApp.notify.error(
                    (data && data.error) || 'Der Chat konnte nicht gestartet werden.'
                );
                return;
            }
            const chatId = data.chat.id;
            const tabId  = 'chat-tab-' + chatId;

            // Schon offen? Dann in den Vordergrund holen statt ein zweites
            // Fenster fuer dasselbe Gespraech aufzumachen.
            if ($('#' + tabId).length) {
                const $vorhanden = $('#' + tabId);
                if ($vorhanden.hasClass('minimized')) $vorhanden.find('.chat-tab-header').click();
                $vorhanden.find('.chat-popup-input').focus();
                return;
            }

            const partnerId       = (data.chat.user1_id == window.userId)
                                        ? data.chat.user2_id : data.chat.user1_id;
            const partnerUserName = partnerName || data.chat.partner_name || ('User ' + partnerId);

            if (window.webrtcApp.chatManager) {
                window.webrtcApp.chatManager.createChat(chatId, [window.userId, partnerId]);
            }

            const $tab = window.webrtcApp.uiChat.buildTab(tabId, partnerId, partnerUserName, false);
            $container.append($tab);
            $tab.find('.chat-popup-content').show();
            $tab.removeClass('minimized attention');
            window.webrtcApp.uiChat.bindTabEvents($tab, chatId);
            window.webrtcApp.uiChat.loadChatMessages(chatId, $tab);
            $tab.find('.chat-popup-input').focus();
        })
        .catch(() => {
            window.webrtcApp.notify.error('Der Chat konnte nicht gestartet werden.');
        });
    },

    /**
     * Erstellt ein neues Tab-Element (jQuery) für einen Chat.
     */
    buildTab: function(tabId, partnerId, partnerName, minimized) {
        // Die Gestaltung steht in assets/css/theme.css (.chat-pop). Hier
        // standen frueher Inline-Styles - Farben, Radien und Schatten, die an
        // keiner anderen Stelle der Anwendung vorkamen.
        //
        // WICHTIG: An .chat-popup-actions darf KEINE Bootstrap-Klasse wie
        // d-flex haengen. Die setzt "display: flex !important" und schlaegt
        // damit jedes spaetere jQuery .hide().
        //
        // DIE EINGABE IST VON ANFANG AN DA. Frueher stand darueber noch ein
        // Bereich mit "X moechte mit Ihnen chatten" und zwei Knoepfen, und
        // sichtbar war immer nur eines von beidem. Die Einladung ist mit
        // Migration 019 entfallen: Wer ein Fenster offen hat, kann schreiben.
        const nameEsc = this.esc(partnerName);
        return $(`
                <div class="chat-pop chat-popup-tab${minimized ? ' minimized attention' : ''}"
                     id="${tabId}" data-partner-id="${this.esc(partnerId)}" data-partner-name="${nameEsc}">
                    <div class="chat-pop__head chat-tab-header">
                        <span class="chat-pop__avatar" aria-hidden="true">${this.initials(partnerName)}</span>
                        <span class="chat-pop__who">
                            <span class="chat-pop__title">${nameEsc}</span>
                            <span class="chat-pop__sub">Chat</span>
                        </span>
                        <button class="chat-pop__close close-chat-tab" title="Schließen" aria-label="Chat schließen">&times;</button>
                    </div>
                    <div class="chat-popup-content" style="display:none;">
                        <div class="chat-pop__body chat-popup-messages"></div>
                        <div class="chat-pop__foot">
                            <div class="chat-pop__compose chat-popup-actions">
                                <input type="text" class="form-control chat-popup-input" placeholder="Nachricht">
                                <button class="chat-pop__send chat-popup-send" type="button" title="Senden" aria-label="Senden">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.6 21.4 23 12 2.6 2.6l-.1 7.3L17 12 2.5 14.1z"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                `);
    },

    /**
     * Die Initialen fuer das Zeichen im Kopf des Chatfensters.
     *
     * Hoechstens zwei Buchstaben: bei "anna" das A, bei "Anna Mustermann" AM.
     * Bleibt nichts uebrig - ein Name aus Sonderzeichen etwa -, steht dort
     * ein Fragezeichen statt einer leeren Scheibe.
     *
     * @param {string} name
     * @returns {string} maskierter Text
     */
    initials: function(name) {
        const teile = String(name ?? '').trim().split(/\s+/).filter(Boolean);
        const kurz = teile.slice(0, 2).map(t => t.charAt(0)).join('');
        return this.esc(kurz || '?');
    },

    /**
     * Maskiert Text fuer die Ausgabe in HTML.
     *
     * Namen und Nachrichten kommen von anderen Nutzern. Sie wurden hier
     * frueher unveraendert in Vorlagenzeichenketten eingesetzt - wer "<img
     * onerror=...>" schrieb, bekam es beim Gegenueber ausgefuehrt.
     *
     * @param {*} wert
     * @returns {string}
     */
    esc: function(wert) {
        return String(wert ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    },

    // =====================================================================
    // Zeitangaben
    //
    // Der Server liefert "2026-09-02 13:14:04" - das Format der Datenbank.
    // An einer Nachricht steht davon nur die Uhrzeit; das Datum steht einmal
    // als Trenner zwischen den Tagen. Wer heute schreibt, sieht kein Datum,
    // und wer einen langen Verlauf durchsieht, sieht es genau dort, wo es
    // wechselt.
    // =====================================================================

    /**
     * Wandelt den Zeitstempel des Servers in ein Date.
     *
     * Das Leerzeichen wird zu einem T: "2026-09-02 13:14:04" ist kein Format,
     * das jeder Browser versteht, "2026-09-02T13:14:04" schon - und es gilt
     * als Ortszeit, was hier richtig ist.
     *
     * @param {string} sentAt
     * @returns {Date|null} null, wenn sich nichts lesen laesst
     */
    parseTime: function(sentAt) {
        const roh = String(sentAt ?? '').trim();
        if (!roh) return null;
        const d = new Date(roh.replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    },

    /**
     * Die Uhrzeit einer Nachricht, "HH:MM".
     *
     * Laesst sich der Wert nicht lesen, wird er unveraendert angezeigt: Ein
     * unerwartetes Format ist besser sichtbar als verschwunden.
     *
     * @param {string} sentAt
     * @returns {string} maskierter Text
     */
    formatTime: function(sentAt) {
        const d = this.parseTime(sentAt);
        if (!d) return this.esc(sentAt);
        return this.esc(
            String(d.getHours()).padStart(2, '0') + ':' +
            String(d.getMinutes()).padStart(2, '0')
        );
    },

    /**
     * Der Tag einer Nachricht als Schluessel zum Vergleichen.
     *
     * Bewusst aus den lokalen Datumsteilen und nicht aus toISOString():
     * Letzteres rechnet auf UTC um und wuerde den Trenner am Abend einen Tag
     * zu frueh setzen.
     *
     * @param {string} sentAt
     * @returns {string|null} "YYYY-MM-DD"
     */
    dayKey: function(sentAt) {
        const d = this.parseTime(sentAt);
        if (!d) return null;
        return d.getFullYear() + '-'
             + String(d.getMonth() + 1).padStart(2, '0') + '-'
             + String(d.getDate()).padStart(2, '0');
    },

    /**
     * Die Beschriftung eines Datumstrenners.
     *
     * @param {Date} d
     * @returns {string} maskierter Text
     */
    dayLabel: function(d) {
        const heute = new Date();
        const gestern = new Date();
        gestern.setDate(heute.getDate() - 1);

        const gleich = (a, b) => a.getFullYear() === b.getFullYear()
                              && a.getMonth() === b.getMonth()
                              && a.getDate() === b.getDate();

        if (gleich(d, heute))   return 'Heute';
        if (gleich(d, gestern)) return 'Gestern';

        // Das Jahr nur, wenn es ein anderes ist.
        const optionen = { day: 'numeric', month: 'long' };
        if (d.getFullYear() !== heute.getFullYear()) optionen.year = 'numeric';
        return this.esc(d.toLocaleDateString('de-DE', optionen));
    },

    /**
     * Leert den Nachrichtenbereich eines Tabs.
     *
     * Dabei muss der zuletzt gesetzte Tag vergessen werden - sonst fehlte
     * nach einem Neuaufbau der erste Datumstrenner, weil der Vergleich noch
     * den alten Stand kennt.
     *
     * @param {jQuery} $tab
     */
    clearMessages: function($tab) {
        $tab.find('.chat-popup-messages').empty();
        $tab.removeData('chat-day');
    },

    /**
     * Bindet alle UI-Events für einen Chat-Tab.
     */
    bindTabEvents: function($tab, chatId) {
        // Minimieren/Maximieren
        $tab.find('.chat-tab-header').on('click', function(e) {
            if ($(e.target).hasClass('close-chat-tab')) return;
            const $content = $tab.find('.chat-popup-content');
            const wasMinimized = $tab.hasClass('minimized');
            $tab.toggleClass('minimized');
            if ($tab.hasClass('minimized')) {
                $content.hide();
            } else {
                $content.show();
                $tab.removeClass('attention');
                // *** Nur wenn gerade maximiert wurde ***
                if (wasMinimized) window.webrtcApp.uiChat.markiereGelesen(chatId);
            }
        });

        // Tab schließen
        $tab.find('.close-chat-tab').on('click', function (e) {
            e.stopPropagation();
            $tab.remove();
            if (!$('#chat-popup-container').children().length) $('#chat-popup-container').remove();
            if(window.webrtcApp.chatManager) {
                window.webrtcApp.chatManager.removeChat(chatId);
            }
        });

        // Senden
        $tab.on('click', '.chat-popup-send', function () {
            window.webrtcApp.uiChat.sendMessage($tab, chatId);
        });

        $tab.on('keydown', '.chat-popup-input', function(e){
            if (e.key === "Enter") $tab.find('.chat-popup-send').click();
        });
    },

    /**
     * Meldet dem Server, dass die Nachrichten dieses Chats gelesen sind.
     *
     * WER der Leser ist, sagt die Sitzung und nicht dieses Feld - der Server
     * liest sender_id nicht mehr (App\Controller\ChatController). Es geht
     * weiterhin mit, damit sich am Aufruf nichts aendert.
     *
     * SEIT ES DEN ZAEHLER IN DER KOPFLEISTE GIBT, faerbt dieser Aufruf ihn
     * ab: Was hier auf gelesen gesetzt wird, faellt beim naechsten Heartbeat
     * aus der Zahl heraus.
     *
     * @param {number|string} chatId
     */
    markiereGelesen: function(chatId) {
        fetch('?act=chat_set_seen', {
            method: 'POST',
            body: new URLSearchParams({chat_id: chatId, sender_id: window.userId}),
            credentials: 'same-origin'
        }).catch(() => {});
    },

    /**
     * Senden einer Nachricht aus dem Chat-Popup.
     */
    sendMessage: function($tab, chatId) {
        const $input = $tab.find('.chat-popup-input');
        const msg = $input.val();
        if (msg.trim().length === 0) return;
        fetch('?act=chat_send_message', {
            method: 'POST',
            body: new URLSearchParams({chat_id: chatId, msg}),
            credentials: 'same-origin'
        }).then(r => r.json()).then(data => {
            if (data.success) {
                window.webrtcApp.uiChat.addChatMessage($tab, data.message);
                $input.val('');
                $tab.data('last-msg-id', data.message.id);
                $tab.data('my-last-msg-id', data.message.id);
                var container = $tab.find('.chat-popup-messages')[0];
                if(container) container.scrollTop = container.scrollHeight;
                if(window.webrtcApp.chatManager) {
                    window.webrtcApp.chatManager.setLastMsgId(chatId, data.message.id);
                }
            } else if (data.error) {
                // Eine abgewiesene Nachricht - meist die Bremse - darf nicht
                // stillschweigend verschwinden: Der Text bleibt im Feld
                // stehen, damit er nicht neu getippt werden muss.
                window.webrtcApp.notify.error(data.error);
            }
        });
    },

    /**
     * Holt Nachrichten für einen Chat und füllt das Popup.
     */
    loadChatMessages: function(chatId, $tab) {
        fetch('?act=chat_get_messages&chat_id=' + chatId)
        .then(r => r.json()).then(data => {
            if (data.success) {
                window.webrtcApp.uiChat.clearMessages($tab);
                let maxMsgId = 0;
                data.messages.forEach(msg => {
                    window.webrtcApp.uiChat.addChatMessage($tab, msg);
                    if (msg.id > maxMsgId) maxMsgId = msg.id;
                });
                $tab.data('last-msg-id', maxMsgId);
                var container = $tab.find('.chat-popup-messages')[0];
                if(container) container.scrollTop = container.scrollHeight;
                if(window.webrtcApp.chatManager) {
                    window.webrtcApp.chatManager.setLastMsgId(chatId, maxMsgId);
                }
                // Wer den Verlauf offen vor sich hat, hat ihn gelesen.
                if (!$tab.hasClass('minimized')) {
                    window.webrtcApp.uiChat.markiereGelesen(chatId);
                }
            }
        });
    },

    /**
     * Fügt eine Nachricht in das Chat-Popup ein.
     */
    addChatMessage: function($tab, msg) {
        const partnerUserId = $tab.data('partner-id');
        const isPartner = (msg.sender_id == partnerUserId);
        const $log = $tab.find('.chat-popup-messages');

        // Wechselt der Tag, kommt zuerst ein Trenner. Er steht einmal
        // zwischen den Tagen und nicht an jeder Nachricht.
        const tag = this.dayKey(msg.sent_at);
        if (tag && $tab.data('chat-day') !== tag) {
            $tab.data('chat-day', tag);
            $log.append(
                '<div class="chat-daysep"><span>'
                + this.dayLabel(this.parseTime(msg.sent_at))
                + '</span></div>'
            );
        }

        // Erst maskieren, dann Zeilenumbrueche zu <br> machen - in dieser
        // Reihenfolge. Umgekehrt waere das <br> gleich wieder maskiert, und
        // ohne Maskierung landete fremdes Markup ungeprueft im Dokument.
        const text = this.esc(msg.msg).replace(/\n/g, '<br>');
        const wer  = isPartner ? 'partner' : 'self';

        $log.append(`
            <div class="chat-msg chat-msg--${wer}">
                <span class="chat-msg__text">${text}</span>
                <span class="chat-msg__time">${this.formatTime(msg.sent_at)}</span>
            </div>
        `);
    },

    // === Polling/Sync für alle offenen Popups ===

    globalChatPollingInterval: null,

    /**
     * Startet das periodische Polling für neue Nachrichten (nur wenn kein Call aktiv!).
     *
     * ES OEFFNET EIN FENSTER, wenn in einem Chat etwas Ungelesenes liegt und
     * keines offen ist - minimiert, mit Ton. Das ist die Zustellung; dass
     * ueberhaupt etwas da ist, sagt daneben der Zaehler in der Kopfleiste
     * (assets/js/chat_badge.js), und der ueberlebt auch einen Seitenwechsel.
     */
    startGlobalChatPolling() {
        if (window.webrtcApp.globalChatPollingInterval) return;
        window.webrtcApp.globalChatPollingInterval = setInterval(function() {
            fetch('?act=chat_get_chats')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.chats && Array.isArray(data.chats)) {
                        data.chats.forEach(chat => {
                            const tabId = 'chat-tab-' + chat.id;
                            if (chat.unseen_count > 0 && $('#' + tabId).length === 0) {
                                // Ungelesene Nachrichten, kein Popup offen.
                                const partnerId = (chat.user1_id == window.userId) ? chat.user2_id : chat.user1_id;
                                const partnerName = chat.partner_name || 'Partner';
                                const $tab = window.webrtcApp.uiChat.buildTab(tabId, partnerId, partnerName, true);

                                let $container = $('#chat-popup-container');
                                if (!$container.length) {
                                    $('body').append('<div id="chat-popup-container"></div>');
                                    $container = $('#chat-popup-container');
                                }
                                $container.append($tab);

                                window.webrtcApp.sound && window.webrtcApp.sound.play && window.webrtcApp.sound.play('notification_sound_msg', false, 0.25);

                                if (window.webrtcApp.chatManager) {
                                    window.webrtcApp.chatManager.createChat(chat.id, [window.userId, partnerId]);
                                }
                                window.webrtcApp.uiChat.bindTabEvents($tab, chat.id);
                                window.webrtcApp.uiChat.loadChatMessages(chat.id, $tab);
                            }
                        });
                    }
                });
        }, 10000);
    },

    /**
     * Beendet das Polling für Chat-Nachrichten.
     */
    stopGlobalChatPolling() {
        if (window.webrtcApp.globalChatPollingInterval) {
            clearInterval(window.webrtcApp.globalChatPollingInterval);
            window.webrtcApp.globalChatPollingInterval = null;
        }
    },

    /**
     * Aktualisiert, welche Pollings aktiv sein sollen (z.B. bei aktivem Call stoppen).
     */
    updatePollingState() {
        if (window.webrtcApp.state.isCallActive) {
            window.webrtcApp.uiChat.stopGlobalChatPolling();
        } else {
            window.webrtcApp.uiChat.startGlobalChatPolling();
        }
    },
};

/**
 * Separates Nachrichten-Polling für alle offenen Chat-Popups.
 * Holt regelmäßig neue Nachrichten und aktualisiert die UI.
 */
setInterval(function () {
    $('#chat-popup-container .chat-popup-tab').each(function () {
        const $tab = $(this);
        const chatId = $tab.attr('id').split('-').pop();
        const partnerId = $tab.data('partner-id');
        let lastMsgId = $tab.data('last-msg-id');
        let myLastMsgId = $tab.data('my-last-msg-id');
        if (typeof lastMsgId === "undefined") lastMsgId = 0;
        if (typeof myLastMsgId === "undefined") myLastMsgId = 0;
        fetch('?act=chat_get_messages&chat_id=' + chatId)
            .then(r => r.json())
            .then(data => {
                if (data.success ) {
                    let maxMsgId = lastMsgId;
                    let hasNewPartnerMsg = false;
                    let newPartnerMsgId = 0;
                    data.messages.forEach(msg => {
                        if (msg.id > lastMsgId && msg.sender_id == partnerId) {
                            hasNewPartnerMsg = true;
                            newPartnerMsgId = msg.id;
                        }
                        if (msg.id > maxMsgId) maxMsgId = msg.id;
                    });
                    if (hasNewPartnerMsg && newPartnerMsgId != myLastMsgId) {
                        if ($tab.hasClass('minimized')) {
                            window.webrtcApp.sound && window.webrtcApp.sound.play && window.webrtcApp.sound.play('notification_sound_msg', false, 0.25);
                            $tab.addClass('attention');
                        } else {
                            // Popup ist maximiert: Nachricht direkt als gelesen markieren!
                            window.webrtcApp.uiChat.markiereGelesen(chatId);
                        }
                    }
                    window.webrtcApp.uiChat.clearMessages($tab);
                    data.messages.forEach(msg => window.webrtcApp.uiChat.addChatMessage($tab, msg));
                    $tab.data('last-msg-id', maxMsgId);
                    var container = $tab.find('.chat-popup-messages')[0];
                    if(container) container.scrollTop = container.scrollHeight;

                    if(window.webrtcApp.chatManager) {
                        window.webrtcApp.chatManager.setLastMsgId(chatId, maxMsgId);
                    }
                } else if(!data.success && data.gone) {
                    // Den Chat gibt es nicht mehr - das Fenster steht ins
                    // Leere und wird abgeraeumt.
                    $tab.find('.chat-popup-actions').hide();
                    // Der Verlauf wird durch die Meldung ersetzt - damit ist
                    // auch der zuletzt gesetzte Tag hinfaellig.
                    $tab.removeData('chat-day');
                    $tab.find('.chat-popup-messages').html(
                        '<div class="alert alert-danger" role="alert">'
                        + window.webrtcApp.uiChat.esc(
                              data.error ? data.error : 'Dieser Chat existiert nicht mehr.'
                          )
                        + '</div>'
                    );
                    setTimeout(() => {
                        $tab.remove();
                        if (!$('#chat-popup-container').children().length) {
                            $('#chat-popup-container').remove();
                        }
                        if(window.webrtcApp.chatManager) {
                            window.webrtcApp.chatManager.removeChat(chatId);
                        }
                    }, 3000);
                    return;
                }
            })
            .catch(console.error);
    });
}, 3000);
