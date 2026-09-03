/**
 * Initialisiert alle Events und UI-Elemente rund um das WebRTC-Frontend.
 * Bindet Buttons, Devices, Call-Logik und Chat.
 */
window.webrtcApp.init = function() {
    // ---------- Call beenden-Button ----------
    ['end-call-btn'].forEach(id => {
        document.getElementById(id)?.addEventListener('click', function() {
            window.webrtcApp.rtc.endCall(true);
        });
    });

    // ---------- Initialisiere Chat-UI ----------
    window.webrtcApp.uiRtc.initChatUI();

    // ---------- Call-Annehmen/Ablehnen-Buttons ----------
    const acceptBtn = document.getElementById('accept-call-btn');
    if (acceptBtn) {
        acceptBtn.addEventListener('click', function() {
            const dialog = document.getElementById('media-select-dialog');
            if (dialog) dialog.style.display = '';
        });
    }

    const declineBtn = document.getElementById('media-decline-btn');
    if (declineBtn) {
        declineBtn.addEventListener('click', function() {
            const dialog = document.getElementById('media-select-dialog');
            if (dialog) dialog.style.display = 'none';
            if (acceptBtn) acceptBtn.style.display = "none";
            window.webrtcApp.uiRtc.setEndCallButtonVisible(false);
            const data = window.webrtcApp.state.pendingOffer;
            window.webrtcApp.state.activeTargetUserId = data.sender_id;
            window.webrtcApp.rtc.endCall(true);
            window.webrtcApp.sound.stop('incomming_call_ringtone');
        });
    }

    // ---------- Chat-Popup öffnen über Button ----------
    $(document).on('click', '.start-chat-btn', function () {
        const userId = $(this).data('userid');
        window.webrtcApp.uiChat.openChatPopup(userId);
    });

    // ---------- Starte Polling für Signaling nach Login ----------
    if (window.isLoggedIn) {
        window.webrtcApp.signaling.pollSignaling();
        window.webrtcApp.uiChat.updatePollingState();
    }

    // ---------- Medienauswahl für Call akzeptieren ----------
    //
    // Hier steht nur noch die Verdrahtung: Auswahl einlesen, Klingelton aus,
    // Dialog zu. Der Ablauf des Annehmens liegt in rtc.acceptCall().
    const acceptMediaBtn = document.getElementById('media-accept-btn');
    if (acceptMediaBtn) {
        acceptMediaBtn.addEventListener('click', async function() {
            const useVideo = !!document.getElementById('media-video-checkbox')?.checked;
            const useAudio = !!document.getElementById('media-audio-checkbox')?.checked;

            // Die Pruefung steht VOR dem Schliessen des Dialogs, und sie
            // beendet den Anruf nicht mehr: Wer beides abwaehlt, hat sich
            // vertan und soll die Auswahl nachholen koennen. Vorher wurde der
            // Dialog geschlossen, der Anruf abgebrochen und der Anrufer
            // benachrichtigt - wegen eines vergessenen Hakens.
            if (!useVideo && !useAudio) {
                window.webrtcApp.notify.error(
                    'Bitte mindestens Ton oder Video auswählen, um den Anruf anzunehmen.'
                );
                return;
            }

            window.webrtcApp.sound.stop('incomming_call_ringtone');
            const dialog = document.getElementById('media-select-dialog');
            if (dialog) dialog.style.display = 'none';

            await window.webrtcApp.rtc.acceptCall({
                video: useVideo,
                audio: useAudio,
                videoDeviceId: document.getElementById('camera-select')?.value || null,
                audioDeviceId: document.getElementById('mic-select')?.value || null
            });
        });
    }

    // ---------- Call per Button starten ----------
    document.querySelectorAll('.start-call-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const btnId = this.id || '';
            let userId = null;
            if (btnId.startsWith('start-call-btn-')) {
                userId = btnId.substring('start-call-btn-'.length);
            }
            window.webrtcApp.rtc.startCall(userId);
        });
    });

    const throttleTime = 100; // ms
    const lastClicked = {};

    // ---------- Steuerungsbuttons ----------
    // Es gibt sie nur noch einmal. Frueher lag daneben ein zweiter Satz mit
    // der Endung "-mobile", weil die Call-Ansicht zwei getrennte Layouts
    // hatte; jetzt liegt dasselbe Steuerkreuz als Overlay im Bild und die
    // Anordnung entscheidet allein assets/css/call.css.
    window.webrtcApp.control.ARROW_BUTTON_IDS.forEach(id => {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.addEventListener('click', function() {
            const now = Date.now();
            if (!lastClicked[id] || now - lastClicked[id] > throttleTime) {
                lastClicked[id] = now;
                // Aus der ID wird die Richtung: "btn-look-up" -> "look_up".
                // Die IDs tragen Bindestriche (so ist es im uebrigen Markup),
                // das Protokoll schreibt Unterstriche (PROTOKOLL.md).
                const name = id.replace('btn-', '').replace(/-/g, '_');
                // Steuerbefehle laufen über control.sendMove: Dort werden
                // Rolle, Sperre und ausstehende Bestätigung geprüft, und
                // bei instabiler Verbindung wird verworfen statt gepuffert
                // (siehe rtc.canSendControlCommand).
                window.webrtcApp.control.sendMove(name);
                setTimeout(() => btn.blur(), 150);
            }
        });
    });

    // ---------- Steuerung sperren/freigeben (nur Guide) ----------
    // Der Guide haelt die Steuerung kurz an, etwa beim Ueberqueren einer
    // Strasse. Die Schaltflaeche ist nur in seiner Rolle sichtbar (call.css).
    document.getElementById('control-lock-btn')?.addEventListener('click', function() {
        window.webrtcApp.control.toggleLock();
    });

    // ---------- Geraeteauswahl und Medienknoepfe ----------
    //
    // Der Inhalt liegt in assets/js/media.js. Hier steht nur, WANN er laeuft.
    //
    // Die Liste wird nicht mehr nur einmal beim Seitenaufbau gefuellt. Das
    // war der Grund, warum ein Geraetewechsel im Call nichts bewirkte: Vor
    // der ersten Freigabe liefert enumerateDevices() Eintraege ohne Namen und
    // mit LEERER Kennung. Genau die standen dann dauerhaft in der Auswahl,
    // und der Wechsel auf einen solchen Eintrag konnte gar nicht wirken.
    //
    // Neu gefuellt wird jetzt:
    //   - beim Seitenaufbau (fuer den Annahmedialog),
    //   - nach jeder erteilten Freigabe (media.acquireTrack, rtc.acceptCall,
    //     rtc.startCall),
    //   - wenn der Browser einen Geraetewechsel meldet (devicechange),
    //   - beim Aufklappen des Geraeteblatts im Call.
    window.webrtcApp.media.refreshDeviceLists();

    if (navigator.mediaDevices && navigator.mediaDevices.addEventListener) {
        navigator.mediaDevices.addEventListener('devicechange', function() {
            window.webrtcApp.media.refreshDeviceLists();
        });
    }

    // ---------- Kamera/Mikro im laufenden Call wechseln ----------
    // Der Wechsel laeuft ueber RTCRtpSender.replaceTrack(): Er wirkt sofort
    // bei der Gegenseite, ohne Offer und Answer. Siehe media.switchDevice().
    document.getElementById('camera-select-in-call')
        ?.addEventListener('change', function() {
            window.webrtcApp.media.switchDevice('video', this.value);
        });
    document.getElementById('mic-select-in-call')
        ?.addEventListener('change', function() {
            window.webrtcApp.media.switchDevice('audio', this.value);
        });

    // ---------- Mikrofon und Kamera an/aus ----------
    // Beide Knoepfe gibt es genau einmal - die Bedienleiste der Call-Ansicht
    // ist dieselbe auf jedem Geraet. Was passiert, steht in media.js; hier
    // steht nur, dass ein Druck es ausloest.
    document.getElementById('switch-mic-btn')?.addEventListener('click', function() {
        if (!window.webrtcApp.state.isCallActive) return;
        window.webrtcApp.media.toggleMic();
    });

    document.getElementById('switch-cam-btn')?.addEventListener('click', function() {
        if (!window.webrtcApp.state.isCallActive) return;
        window.webrtcApp.media.toggleCamera();
    });

    window.webrtcApp.media.updateIcons();

    // ---------- Kopfleiste ----------
    //
    // In der Leiste stehen nur noch die beiden haeufigen Aktionen. Konto,
    // Chats, Benutzerliste und Abmelden liegen im Benutzermenue, das der
    // Server mitliefert (App\Helper\ViewHelper::userMenu) - hier ist dafuer
    // nichts mehr ein- oder auszublenden. Frueher stand hier eine Abfrage auf
    // den aktuellen 'act', die auf den Kontoseiten die Standortknoepfe gegen
    // einen Chat-Knopf tauschte.
    window.webrtcApp.ui.showLocationButton();
    window.webrtcApp.ui.showAllLocationsButton();
    window.webrtcApp.ui.bindUserMenu();

    // ---------- Overlays der Call-Ansicht: Chat und Geraeteauswahl -------
    //
    // Beide sind aufklappbar und nie gleichzeitig offen: Der Chat legt sich
    // ueber das ganze Bild, die Geraeteauswahl sitzt ueber der Leiste. Zwei
    // offene Blaetter uebereinander waeren nur im Weg.
    //
    // Frueher gab es dafuer einen runden Chatknopf, der nur auf Mobilgeraeten
    // erschien, und einen zweiten Chat mit eigenen IDs. Jetzt ist es ein
    // Chat, ein Knopf, auf jedem Geraet.
    const chatOverlay  = document.getElementById('chat-overlay');
    const chatToggle   = document.getElementById('chat-toggle-btn');
    const deviceSheet  = document.getElementById('call-devices');
    const deviceToggle = document.getElementById('call-devices-btn');

    /**
     * Blendet eines der beiden Blaetter ein oder aus und haelt den Zustand
     * des zugehoerigen Knopfes (aria-expanded) nach.
     *
     * @param {HTMLElement|null} blatt
     * @param {HTMLElement|null} knopf
     * @param {boolean} offen
     */
    function setSheet(blatt, knopf, offen) {
        if (!blatt) return;
        blatt.hidden = !offen;
        if (knopf) knopf.setAttribute('aria-expanded', offen ? 'true' : 'false');

        // Das Chatblatt legt sich auf breiten Schirmen an die rechte Kante -
        // genau dort, wo das Steuerkreuz liegt. Die Klasse verschiebt es
        // (assets/css/call.css); auf schmalen Schirmen deckt das Blatt die
        // untere Haelfte ab, dort weicht das Steuerkreuz ganz.
        if (blatt === chatOverlay) {
            const ansicht = document.getElementById('call-view');
            if (ansicht) {
                if (offen) ansicht.classList.add('chat-open');
                else       ansicht.classList.remove('chat-open');
            }
            // Offen heisst gelesen: Der Zaehler am Chatknopf verschwindet in
            // dem Moment, in dem das Blatt aufgeht.
            if (offen) window.webrtcApp.chat.clearUnread();
        }
    }

    chatToggle?.addEventListener('click', function() {
        const offen = chatOverlay && chatOverlay.hidden;
        setSheet(deviceSheet, deviceToggle, false);
        setSheet(chatOverlay, chatToggle, !!offen);
        if (offen) document.getElementById('chat-input')?.focus();
    });

    deviceToggle?.addEventListener('click', function() {
        const offen = deviceSheet && deviceSheet.hidden;
        setSheet(chatOverlay, chatToggle, false);
        setSheet(deviceSheet, deviceToggle, !!offen);
        // Beim Aufklappen die Liste erneuern: Ein waehrend des Calls
        // angestecktes Headset soll darin stehen, und nach der Freigabe
        // haben die Eintraege jetzt Namen statt "Kamera 1".
        if (offen) window.webrtcApp.media.refreshDeviceLists();
    });

    document.getElementById('chat-close')?.addEventListener('click', function() {
        setSheet(chatOverlay, chatToggle, false);
    });

    // Klick neben das Chatblatt schliesst es.
    chatOverlay?.addEventListener('click', function(e) {
        if (e.target === chatOverlay) setSheet(chatOverlay, chatToggle, false);
    });

};

// ---------- DOMContentLoaded: Initialisierung & Intervall-Tasks ----------
window.addEventListener('DOMContentLoaded', function() {
    window.webrtcApp.init();
    if (window.isLoggedIn) {
        // Takt kommt vom Server (config/presence.php), damit er nicht gegen
        // den Offline-Timeout des Cronjobs laeuft. Der Rueckfallwert greift
        // nur, wenn die Seite ohne die Servervariable ausgeliefert wurde.
        const heartbeatMs = window.heartbeatIntervalMs || 10000;

        // Sofort einmal melden: Sonst gilt der Nutzer nach dem Anmelden bis
        // zum ersten Intervall als das, was zuletzt in der Datenbank stand -
        // nach einem Logout also als offline.
        window.webrtcApp.signaling.sendHeartbeat(window.webrtcApp.state.isCallActive);
        setInterval(function() {
            window.webrtcApp.signaling.sendHeartbeat(window.webrtcApp.state.isCallActive);
        }, heartbeatMs);

        // Browser bremsen Timer in ausgeblendeten Tabs auf etwa einen Aufruf
        // pro Minute aus. Wer den Tab wieder nach vorn holt, soll nicht erst
        // den naechsten Takt abwarten muessen, um wieder als erreichbar zu
        // gelten.
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) return;
            window.webrtcApp.signaling.sendHeartbeat(window.webrtcApp.state.isCallActive);
        });
    }
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '1', 'Standort gespeichert.');
    // success=0 wird ausschliesslich von der Beschreibungspruefung ausgeloest
    // (LocationController::setLocation, strlen < 5). Die Stadt wird dort gar
    // nicht geprueft - der alte Text nannte einen Grund, den es nicht gab.
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '0', 'Nicht gespeichert: Die Beschreibung muss mindestens 5 Zeichen lang sein.', 'error');
    // success=2: die Koordinaten fehlten oder lagen ausserhalb des gueltigen
    // Bereichs (siehe LocationController::setLocation).
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '2', 'Nicht gespeichert: Bitte den Standort auf der Karte auswählen.', 'error');
    window.webrtcApp.utils.showSuccessAlertIfNeeded('success', '5', 'Registrierung erfolgreich.');
    window.webrtcApp.utils.showSuccessAlertIfNeeded('change', '1', 'Passwort geändert.');
    // Hier stand ein Aufruf von ui.expandPanelForWideTableIfNeeded(): Er hat
    // auf breiten Seiten dem Inhaltsbereich und allen Karten darin ihre
    // Bootstrap-Klassen wieder abgenommen, weil zwei weisse Kaesten
    // ineinander standen - der Rahmen aus index.html und die Karte der Seite.
    // Den aeusseren Kasten gibt es nicht mehr (assets/css/theme.css: der
    // Inhaltsbereich ist nur noch Breite und Abstand), also gibt es auch
    // nichts mehr zurueckzunehmen.
});
