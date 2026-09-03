/**
 * Signaling-Modul: Steuert das Senden/Empfangen von Nachrichten für die WebRTC-Signalisierung.
 * (Offer, Answer, ICE-Candidate, Call-Events etc.)
 */
window.webrtcApp.signaling = {
    // Poll-Intervall außerhalb eines Calls.
    POLL_INTERVAL_IDLE: 1500,

    // Poll-Intervall während eines laufenden Calls.
    // Früher wurde das Polling bei dc.onopen komplett abgeschaltet. Genau
    // deshalb erreichte das Auflegen den Gegenüber nie (Befund F-3), und ein
    // ICE-Restart wäre gar nicht aushandelbar gewesen - dafür braucht es einen
    // Weg für Offer/Answer, der unabhängig von der gestörten Verbindung ist.
    // Das Polling läuft jetzt durch, im Call mit halber Frequenz.
    POLL_INTERVAL_IN_CALL: 3000,

    /**
     * Sendet eine Signalnachricht (z.B. offer/answer/candidate/hangup) per POST an das Backend.
     *
     * Die Antwort wird zurueckgegeben, weil der Server beim Offer die
     * Call-Rolle mitschickt (Feld "role"). Aufrufer, die sie nicht brauchen,
     * ignorieren den Rueckgabewert wie bisher.
     *
     * @param {Object} msg - Zu sendende Nachricht (JSON)
     * @returns {Promise<Object|null>} Antwort des Servers oder null
     */
    sendSignalMessage(msg) {
        // Nachrichteninhalt (SDP, ICE-Kandidaten) nur bei aktiviertem Debug-Flag ausgeben
        if (window.webrtcApp.debug) console.log("Sende Signal-Nachricht:", msg);
        return fetch('index.php?act=getSignal', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(msg)
        })
        .then(r => r.text())
        .then(text => {
            // Eine Fehlerseite statt JSON darf hier nicht werfen - der
            // Signalweg laeuft sonst weiter, nur ohne auswertbare Antwort.
            try { return JSON.parse(text); } catch (e) { return null; }
        })
        .catch(err => { console.error(err); return null; });
    },

    /**
     * Startet das regelmäßige Polling für eingehende Signalisierungsnachrichten.
     * (Wird als Backup für WebSockets verwendet.)
     * @param {number} [intervalMs] - Poll-Intervall; ohne Angabe das Ruhe-Intervall
     */
    pollSignaling(intervalMs) {
        const interval = intervalMs || window.webrtcApp.signaling.POLL_INTERVAL_IDLE;
        if (window.webrtcApp.refs.pollingIntervalId !== null) {
            // Läuft bereits mit dem gewünschten Intervall - nichts zu tun.
            if (window.webrtcApp.refs.pollingIntervalMs === interval) return;
            window.webrtcApp.signaling.stopPolling();
        }
        console.log("Starte Polling (" + interval + " ms)...");
        window.webrtcApp.refs.pollingIntervalMs = interval;
        window.webrtcApp.refs.pollingIntervalId = setInterval(() => {
            fetch('index.php?act=getSignal')
                .then(r => {
                    // Ohne diese Prüfung wirft r.json() bei jeder Fehlerseite
                    // (z. B. HTML einer 500er-Antwort) im Sekundentakt.
                    if (!r.ok) throw new Error('Signaling-Abruf fehlgeschlagen: HTTP ' + r.status);
                    return r.json();
                })
                .then(msgArr => {
                    // Nur bei aktiviertem Debug-Flag, siehe app.js
                    if (window.webrtcApp.debug) console.log("Signaling-Nachrichten erhalten:", msgArr);
                    if (Array.isArray(msgArr)) {
                        msgArr.forEach(msg => window.webrtcApp.signaling.handleSignalingData(msg));
                    }
                })
                .catch(err => {
                    // Ein einzelner fehlgeschlagener Poll ist kein Grund
                    // abzubrechen - beim nächsten Durchlauf wird es erneut
                    // versucht. Nur protokollieren.
                    if (window.webrtcApp.debug) console.warn("Poll fehlgeschlagen:", err);
                });
        }, interval);
    },

    /**
     * Stellt das Poll-Intervall um (z. B. beim Call-Start auf das langsamere
     * In-Call-Intervall). Läuft noch kein Polling, wird es gestartet.
     * @param {number} intervalMs - Neues Intervall in Millisekunden
     */
    setPollInterval(intervalMs) {
        window.webrtcApp.signaling.pollSignaling(intervalMs);
    },

    /**
     * Beendet das Polling für Signalisierungsnachrichten.
     */
    stopPolling() {
        if (window.webrtcApp.refs.pollingIntervalId !== null) {
            clearInterval(window.webrtcApp.refs.pollingIntervalId);
            window.webrtcApp.refs.pollingIntervalId = null;
            window.webrtcApp.refs.pollingIntervalMs = null;
        }
    },

    /**
     * Verarbeitet empfangene Signalisierungsdaten (Offer, Answer, ICE, Hangup etc.).
     * @param {Object} data - Die erhaltene Nachricht
     */
    handleSignalingData: async function(data) {
        // Nur bei aktiviertem Debug-Flag, siehe app.js
        if (window.webrtcApp.debug) console.log("Empfange Signaling-Daten:", data);

        if (data.type === 'offer') {
            // Eingehender Anruf: UI vorbereiten
            window.webrtcApp.state.pendingOffer = data;

            // DIE ROLLE GILT AB JETZT, NICHT ERST AB DEM ANNEHMEN. Der Server
            // hat sie an das Offer gestempelt (WebRTCController::stampCallRoles),
            // sie steht also schon fest - und der Angerufene soll VOR seiner
            // Entscheidung sehen, worum es geht: eine Fuehrung, in der er
            // gesteuert wird, oder ein Anruf der Administration, in dem
            // niemand steuert. rtc.acceptCall() setzt sie danach noch einmal;
            // das ist derselbe Wert und schadet nicht.
            window.webrtcApp.control.applyRole(data.role || null);

            var dialog = document.getElementById('media-select-dialog');
            if (dialog) {
                dialog.style.display = '';
                window.webrtcApp.state.targetUsername = await window.webrtcApp.uiRtc.getUsername(data.sender_id);
                document.getElementById('calling_user').textContent = window.webrtcApp.state.targetUsername + ' ruft an';
            }
            var btn = document.getElementById('accept-call-btn');
            if (btn) btn.style.display = "none";
            window.webrtcApp.sound.play('incomming_call_ringtone');

        } else if (data.type === 'restart_offer') {
            // Neuaushandlung nach einem ICE-Restart des Gegenübers.
            // Das ist KEIN neuer Anruf - der Annahme-Dialog darf nicht kommen.
            await window.webrtcApp.rtc.handleRestartOffer(data);

        } else if (data.type === 'restart_answer') {
            // Antwort auf unseren ICE-Restart.
            await window.webrtcApp.rtc.handleRestartAnswer(data);

        } else if (data.type === 'restart_request') {
            // Der Gegenüber merkt die Störung, darf aber nicht selbst neu
            // aushandeln (Glare). Er bittet uns darum - wir sind der Initiator.
            window.webrtcApp.rtc.handleRestartRequest(data);

        } else if (data.type === 'answer') {
            // Antwort auf unseren Offer: Verbindung aufbauen
            console.log('Stopped Timeout :' + window.webrtcApp.state.callTimeout);
            window.webrtcApp.rtc.stopTimeout();
            if (!window.webrtcApp.refs.localPeerConnection) return;
            window.webrtcApp.refs.localPeerConnection.setRemoteDescription(new RTCSessionDescription({
                type: data.type,
                sdp: data.sdp
            })).then(() => {
                if (window.webrtcApp.refs.pendingCandidates && window.webrtcApp.refs.pendingCandidates.length) {
                    window.webrtcApp.refs.pendingCandidates.forEach(candidate =>
                        window.webrtcApp.refs.localPeerConnection.addIceCandidate(new RTCIceCandidate(candidate))
                    );
                    window.webrtcApp.refs.pendingCandidates = [];
                }
            });

        } else if (data.type === 'iceCandidate') {
            // ICE-Kandidat erhalten (entweder sofort hinzufügen oder puffern)
            let candidateObj = data.candidate;
            if (!candidateObj) return;
            if (typeof candidateObj === "string") {
                try { candidateObj = JSON.parse(candidateObj); }
                catch(e) { return; }
            }
            if (
                !window.webrtcApp.refs.localPeerConnection ||
                !window.webrtcApp.refs.localPeerConnection.remoteDescription ||
                !window.webrtcApp.refs.localPeerConnection.remoteDescription.type
            ) {
                window.webrtcApp.refs.pendingCandidates.push(candidateObj);
            } else {
                window.webrtcApp.refs.localPeerConnection.addIceCandidate(new RTCIceCandidate(candidateObj));
            }

        } else if (data.type === 'hangup') {
            // Gespräch wurde beendet (vom Partner). Die Auswertung liegt
            // zentral in rtc.handleRemoteHangup(), weil dasselbe Ereignis auch
            // über den DataChannel ankommen kann - es darf nur einmal wirken.
            window.webrtcApp.rtc.handleRemoteHangup(data.sender_id);

        } else if (data.type === 'call_failed') {
            // Call konnte nicht aufgebaut werden (Fehler, kein Media etc.)
            window.webrtcApp.sound.stop('call_ringtone');
            window.webrtcApp.rtc.stopTimeout();
            // Meldung vor dem Abbau: endCall() laesst auf Mobilgeraeten die
            // Seite neu laden und wartet dabei auf den laengsten stehenden
            // Hinweis (siehe rtc.abortCall).
            window.webrtcApp.notify.error('Der Anruf konnte nicht gestartet werden.\n'
                + (data.reason === 'no_media_selected'
                    ? 'Die Gegenseite hat weder Ton noch Bild ausgewählt.'
                    : 'Die Verbindung ließ sich nicht aufbauen.'));
            window.webrtcApp.rtc.endCall(false);
        }
    },

    /**
     * Sendet regelmäßig einen Heartbeat.
     *
     * ER MELDET DIE ANMELDUNG, NICHT DIE BEREITSCHAFT. Der Heartbeat sagt
     * "ein Browser dieses Kontos laeuft" - nicht mehr. Ob der Guide fuehren
     * will, entscheidet allein der Schalter in der Kopfleiste
     * (assets/js/availability.js). Vorher war beides dasselbe, und deshalb
     * machte ein offener Tab jemanden anrufbar.
     *
     * MITGESCHICKT WIRD, OB DER NUTZER SEIT DEM LETZTEN TAKT BEDIENT HAT.
     * Nur das verlaengert eine laufende Bereitschaft - der Takt selbst nicht.
     * Ein Gespraech zaehlt ebenfalls als Bedienung: Wer gerade fuehrt, soll
     * nicht mitten dabei von der Karte fallen.
     *
     * ZURUECK KOMMT DIE RESTZEIT. Damit haelt sich der Schalter am Server
     * nach, ohne dafuer eine zweite Anfrage zu brauchen - und der Guide merkt
     * es, wenn seine Bereitschaft abgelaufen ist.
     *
     * @param {boolean} inCall - Ist der User aktuell in einem Call?
     */
    sendHeartbeat(inCall) {
        // Die Bedienung holt sich der Heartbeat beim Schalter ab, weil der sie
        // sammelt. Fehlt das Modul - etwa in der Testumgebung, oder bei einem
        // Konto ohne Schalter -, gilt "keine Bedienung": Dann verlaengert
        // dieser Takt nichts, und das ist der harmlose Ausgang.
        const bereitschaft = window.webrtcApp.availability;
        const bedient = bereitschaft ? bereitschaft.takeActivity() : false;

        fetch('index.php?act=heartbeat', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                in_call: inCall ? 1 : 0,
                active:  (bedient || inCall) ? 1 : 0
            })
        })
        .then(antwort => antwort.json())
        .then(daten => {
            if (bereitschaft && daten) bereitschaft.sync(daten.available_seconds);
        })
        // Ein ausgefallener Heartbeat aendert nichts: Der naechste Takt kommt,
        // und bis dahin laeuft die Anzeige lokal weiter. Ohne diesen Zweig
        // stuende bei jedem Netzaussetzer ein Fehler in der Konsole.
        .catch(() => {});
    }
};
