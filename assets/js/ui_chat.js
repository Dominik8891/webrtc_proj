window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.uiChat = {
    openChatPopup: function(userId, partnerName) {
        let $container = $('#chat-popup-container');
        if (!$container.length) {
            $('body').append('<div id="chat-popup-container" style="position:fixed;bottom:0;left:0;z-index:9999;width:370px;"></div>');
            $container = $('#chat-popup-container');
        }
        fetch('?act=chat_start', {
            method: 'POST',
            body: new URLSearchParams({target_id: userId}),
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert(data.error || "Fehler beim Starten des Chats.");
                return;
            }
            const chatId = data.chat.id;
            const tabId = 'chat-tab-' + chatId;
            if ($('#' + tabId).length) {
                $('#' + tabId + ' .chat-tab-header').click();
                return;
            }
            const partnerUserName = partnerName || data.chat.partner_name || ('User ' + userId);
            const isActive = !!data.chat.is_active;
            const isEmpfaenger = (data.chat.pending_for == window.userId);
            

            // ChatManager: Chat anlegen
            if(window.webrtcApp.chatManager) {
                window.webrtcApp.chatManager.createChat(chatId, [window.userId, userId]);
                window.webrtcApp.chatManager.setActive(chatId, isActive);
            }

            const $tab = window.webrtcApp.uiChat.buildTab(tabId, userId, partnerUserName, false);

            $container.append($tab);
            $tab.find('.chat-popup-content').show();
            $tab.removeClass('minimized attention');


            window.webrtcApp.uiChat.setTabUiByActiveState($tab, isActive, isEmpfaenger, partnerUserName);
            window.webrtcApp.uiChat.bindTabEvents($tab, chatId, isEmpfaenger, partnerUserName);

            window.webrtcApp.uiChat.loadChatMessages(chatId, $tab);
        });
    },

    openInvitationTab: function(inviteData) {
        let $container = $('#chat-popup-container');
        if (!$container.length) {
            $('body').append('<div id="chat-popup-container" style="position:fixed;bottom:0;left:0;z-index:9999;width:370px;"></div>');
            $container = $('#chat-popup-container');
        }
        const tabId = 'chat-tab-' + inviteData.id;
        const partnerId = (inviteData.user1_id == window.userId) ? inviteData.user2_id : inviteData.user1_id;
        const partnerName = inviteData.partner_name || 'User ' + partnerId;
        const isEmpfaenger = (inviteData.pending_for == window.userId);
        const isActive = !!inviteData.is_active;

        if(window.webrtcApp.chatManager) {
            window.webrtcApp.chatManager.createChat(inviteData.id, [window.userId, partnerId]);
            window.webrtcApp.chatManager.setActive(inviteData.id, isActive);
        }

        const $tab = window.webrtcApp.uiChat.buildTab(tabId, partnerId, partnerName, true);

        $container.append($tab);

        window.webrtcApp.sound && window.webrtcApp.sound.play && window.webrtcApp.sound.play('notification_sound_msg', false, 0.25);

        window.webrtcApp.uiChat.setTabUiByActiveState($tab, isActive, isEmpfaenger, partnerName);
        window.webrtcApp.uiChat.bindTabEvents($tab, inviteData.id, isEmpfaenger, partnerName);

        // Kein loadChatMessages – Nachrichten kommen erst nach Annahme
    },

    buildTab: function(tabId, partnerId, partnerName, minimized) {
        // minimized (optional): true/false
        return $(`
            <div class="chat-popup-tab${minimized ? ' minimized attention' : ''}" id="${tabId}" data-partner-id="${partnerId}" data-partner-name="${partnerName}" style="background:#fff;border-radius:16px 16px 0 0;box-shadow:0 2px 10px #0002;margin-bottom:8px;">
                <div class="chat-tab-header" style="background:#eee;padding:8px 12px;cursor:pointer;font-weight:bold;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between;">
                    <span>Chat mit ${partnerName}<br></span>
                    <button class="close-chat-tab btn btn-sm btn-danger" title="Schließen" style="margin-left:4px;">×</button>
                </div>
                <div class="chat-popup-content" style="display:none;">
                    <div class="chat-popup-messages" style="height:200px;overflow-y:auto;padding:8px;border-bottom:1px solid #ccc;"></div>
                    <div class="chat-popup-actions" style="display:none;">
                        <input type="text" class="form-control chat-popup-input" placeholder="Nachricht..." style="flex:1 1 auto;">
                        <button class="btn btn-primary chat-popup-send" style="margin-left:4px;">Senden</button>
                    </div>
                    <div class="chat-popup-accept" style="display:none;padding:12px;text-align:center;"></div>
                </div>
            </div>
        `);
    },

    bindTabEvents: function($tab, chatId, isEmpfaenger, partnerName) {
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
                if (wasMinimized) {
                    const chatId = $tab.attr('id').split('-').pop();
                    fetch('?act=chat_set_seen', {
                        method: 'POST',
                        body: new URLSearchParams({chat_id: chatId, sender_id: window.userId}),
                        credentials: 'same-origin'
                    });
                }
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

        // Annehmen
        $tab.on('click', '.accept-chat-btn', function () {
            window.webrtcApp.uiChat.acceptChat($tab, chatId, isEmpfaenger, partnerName);
        });

        // Ablehnen
        $tab.on('click', '.decline-chat-btn', function () {
            window.webrtcApp.uiChat.declineChat($tab, chatId);
        });

        // Senden
        $tab.on('click', '.chat-popup-send', function () {
            window.webrtcApp.uiChat.sendMessage($tab, chatId);
        });

        $tab.on('keydown', '.chat-popup-input', function(e){
            if (e.key === "Enter") $tab.find('.chat-popup-send').click();
        });
    },

    acceptChat: function($tab, chatId, isEmpfaenger, partnerName) {
        fetch('?act=chat_accept', {
            method: 'POST',
            body: new URLSearchParams({chat_id: chatId}),
            credentials: 'same-origin'
        }).then(r => r.json()).then(data2 => {
            if (data2.success) {
                window.webrtcApp.uiChat.setTabUiByActiveState($tab, true, isEmpfaenger, partnerName);
                window.webrtcApp.uiChat.loadChatMessages(chatId, $tab);
                if(window.webrtcApp.chatManager) {
                    window.webrtcApp.chatManager.setActive(chatId, true);
                }
            }
        });
    },

    declineChat: function($tab, chatId) {
        fetch('?act=chat_decline', {
            method: 'POST',
            body: new URLSearchParams({chat_id: chatId}),
            credentials: 'same-origin'
        }).then(r => r.json()).then(data2 => {
            $tab.remove();
            if (!$('#chat-popup-container').children().length) $('#chat-popup-container').remove();
            if(window.webrtcApp.chatManager) {
                window.webrtcApp.chatManager.removeChat(chatId);
            }
        });
    },

    sendMessage: function($tab, chatId) {
        console.log("SENDEN-BUTTON geklickt!", {chatId, chatManager: window.webrtcApp.chatManager});
        if (window.webrtcApp.chatManager && !window.webrtcApp.chatManager.isActive(chatId)) return;
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
            }
        });
    },

    loadChatMessages: function(chatId, $tab) {
        fetch('?act=chat_get_messages&chat_id=' + chatId)
        .then(r => r.json()).then(data => {
            if (data.success) {
                $tab.find('.chat-popup-messages').empty();
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
            }
        });
    },

    addChatMessage: function($tab, msg) {
        const partnerUserId = $tab.data('partner-id');
        const isPartner = (msg.sender_id == partnerUserId);
        const cleanMsg = String(msg.msg).replace(/\n/g, '<br>');
        const msgHtml = `<div style="margin:2px 0;text-align:${isPartner ? 'left':'right'};">
            <span class="badge bg-${isPartner?'secondary':'primary'}">${isPartner?'Partner':'Du'}:</span><br>
            <span>${cleanMsg}</span>
            <small style="color:#888;">${msg.sent_at}</small>
        </div>`;
        $tab.find('.chat-popup-messages').append(msgHtml);
    },

    setTabUiByActiveState: function($tab, isActive, isEmpfaenger, partnerName) {
        $tab.find('.chat-popup-actions').hide();
        $tab.find('.chat-popup-accept').hide();

        if(!isActive && !isEmpfaenger) {
            $tab.find('.chat-popup-accept')
                .html('<span>Warte auf Annahme...</span>')
                .show();
            if(window.webrtcApp.chatManager) {
                const chatId = $tab.attr('id').split('-').pop();
                window.webrtcApp.chatManager.setActive(chatId, false);
            }
        }
        else if (!!isActive) {
            $tab.find('.chat-popup-actions').show();
            if(window.webrtcApp.chatManager) {
                const chatId = $tab.attr('id').split('-').pop();
                window.webrtcApp.chatManager.setActive(chatId, true);
            }
        } else if(isEmpfaenger) {
            $tab.find('.chat-popup-accept')
                .html(
                `<span>${partnerName || 'Partner'} möchte mit dir chatten.</span><br>
                <button class="btn btn-success accept-chat-btn">Chat annehmen</button>
                <button class="btn btn-danger decline-chat-btn">Ablehnen</button>`
                ).show();
            if(window.webrtcApp.chatManager) {
                const chatId = $tab.attr('id').split('-').pop();
                window.webrtcApp.chatManager.setActive(chatId, false);
            }
        }
    },

    globalChatPollingInterval: null,
    invitePollingInterval: null,

    startGlobalChatPolling() {
        if (window.webrtcApp.globalChatPollingInterval) return;
        window.webrtcApp.globalChatPollingInterval = setInterval(function() {
            fetch('?act=chat_get_chats')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.chats && Array.isArray(data.chats)) {
                        data.chats.forEach(chat => {
                            const tabId = 'chat-tab-' + chat.id;
                            if (chat.is_active && chat.unseen_count > 0 && $('#' + tabId).length === 0) {
                                // Aktiver Chat, ungelesene Nachrichten, kein Popup offen

                                const partnerId = (chat.user1_id == window.userId) ? chat.user2_id : chat.user1_id;
                                const partnerName = chat.partner_name || 'Partner';
                                const $tab = window.webrtcApp.uiChat.buildTab(tabId, partnerId, partnerName, true);

                                let $container = $('#chat-popup-container');
                                if (!$container.length) {
                                    $('body').append('<div id="chat-popup-container" style="position:fixed;bottom:0;left:0;z-index:9999;width:370px;"></div>');
                                    $container = $('#chat-popup-container');
                                }
                                $container.append($tab);

                                window.webrtcApp.sound && window.webrtcApp.sound.play && window.webrtcApp.sound.play('notification_sound_msg', false, 0.25);

                                // *** WICHTIG: Jetzt Status und Nachrichten nachladen ***
                                fetch('?act=chat_get_messages&chat_id=' + chat.id)
                                .then(r => r.json())
                                .then(data => {
                                        const isActive = !!data.is_active;
                                        const isEmpfaenger = (typeof data.pending_for !== "undefined") ? (data.pending_for == window.userId) : false;
                                        if(window.webrtcApp.chatManager) {
                                            window.webrtcApp.chatManager.createChat(chat.id, [window.userId, partnerId]);
                                            window.webrtcApp.chatManager.setActive(chat.id, isActive);
                                        }
                                        window.webrtcApp.uiChat.setTabUiByActiveState($tab, isActive, isEmpfaenger, partnerName);
                                        window.webrtcApp.uiChat.bindTabEvents($tab, chat.id, isEmpfaenger, partnerName);
                                        window.webrtcApp.uiChat.loadChatMessages(chat.id, $tab);
                                });
                            }
                        });
                    }
                });
        }, 10000);
    },

    stopGlobalChatPolling() {
        if (window.webrtcApp.globalChatPollingInterval) {
            clearInterval(window.webrtcApp.globalChatPollingInterval);
            window.webrtcApp.globalChatPollingInterval = null;
        }
    },

    startInvitePolling() {
        if (window.webrtcApp.invitePollingInterval) return;
        window.webrtcApp.invitePollingInterval = setInterval(function() {
            fetch('?act=chat_get_invitations')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.invitations && data.invitations.length > 0) {
                        data.invitations.forEach(function(invite) {
                            var tabId = 'chat-tab-' + invite.id;
                            if ($('#' + tabId).length === 0) {
                                window.webrtcApp.uiChat.openInvitationTab(invite);
                            }
                        });
                    }
                });
        }, 3000);
    },

    stopInvitePolling() {
        if (window.webrtcApp.invitePollingInterval) {
            clearInterval(window.webrtcApp.invitePollingInterval);
            window.webrtcApp.invitePollingInterval = null;
        }
    },

    updatePollingState() {
        if (window.webrtcApp.state.isCallActive) {
            window.webrtcApp.uiChat.stopGlobalChatPolling();
            window.webrtcApp.uiChat.stopInvitePolling();
        } else {
            window.webrtcApp.uiChat.startGlobalChatPolling();
            window.webrtcApp.uiChat.startInvitePolling();
        }
    },

};

// === Nachrichten-Polling ===
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
                            const chatId = $tab.attr('id').split('-').pop();
                            fetch('?act=chat_set_seen', {
                                method: 'POST',
                                body: new URLSearchParams({chat_id: chatId, sender_id: window.userId}),
                                credentials: 'same-origin'
                            });
                        }
                    }
                    $tab.find('.chat-popup-messages').empty();
                    data.messages.forEach(msg => window.webrtcApp.uiChat.addChatMessage($tab, msg));
                    $tab.data('last-msg-id', maxMsgId);
                    var container = $tab.find('.chat-popup-messages')[0];
                    if(container) container.scrollTop = container.scrollHeight;

                    // Zentrale UI-Steuerung HIER:
                    const isActive = !!data.is_active;
                    let partnerName = $tab.data('partner-name');
                    const isEmpfaenger = (typeof data.pending_for !== "undefined") ? (data.pending_for == window.userId) : false;
                    window.webrtcApp.uiChat.setTabUiByActiveState($tab, isActive, isEmpfaenger, partnerName);
                    if(window.webrtcApp.chatManager) {
                        window.webrtcApp.chatManager.setLastMsgId(chatId, maxMsgId);
                    }
                } else if(!data.success && data.declined) {
                    $tab.find('.chat-popup-accept, .chat-popup-actions').hide();
                    $tab.find('.chat-popup-messages').html(
                        `<div style="text-align:center;color:#a00;padding:20px;">${
                          data.error ? data.error : 'Chat wurde abgelehnt oder existiert nicht mehr.'
                        }</div>`
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
