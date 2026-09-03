/**
 * Lokale Medien im laufenden Call: Mikrofon, Kamera, Geraetewechsel.
 *
 * WARUM EIN EIGENES MODUL
 * -----------------------
 * Dasselbe lag vorher dreimal in assets/js/main.js verstreut: einmal fuer den
 * Geraetewechsel, einmal fuer den Mikrofonknopf, einmal fuer den Kameraknopf.
 * Jede Stelle suchte sich ihren Sender selbst, jede pflegte den lokalen Strom
 * anders, und keine sagte der Gegenseite Bescheid. Hier steht es EINMAL.
 *
 * DER KERN: replaceTrack STATT NEUAUSHANDLUNG
 * -------------------------------------------
 * Ein Wechsel von Kamera oder Mikrofon aendert nur, WAS durch eine bereits
 * ausgehandelte Spur laeuft - nicht, WELCHE Spuren es gibt. Dafuer ist
 * RTCRtpSender.replaceTrack() da: Der Wechsel wirkt sofort bei der
 * Gegenseite, ohne Offer, ohne Answer, ohne Aussetzer. Diese Anwendung kann
 * mitten im Gespraech ohnehin nur den ICE-Restart neu aushandeln - alles
 * andere MUSS ueber replaceTrack laufen.
 *
 * Voraussetzung ist, dass es fuer beide Spurarten ueberhaupt einen Sender
 * gibt, auch wenn gerade nichts gesendet wird. Dafuer sorgen
 * rtc.reserveVideoSender() beim Anrufer und rtc.attachAnswerTracks() beim
 * Angerufenen: Nach dem Verbindungsaufbau hat jede Seite je einen Audio- und
 * einen Videosender - notfalls ohne Spur.
 *
 * WARUM DIE SENDERSUCHE UEBER DIE TRANSCEIVER LAEUFT
 * -------------------------------------------------
 * Ein Sender ohne Spur (Kamera aus) verraet seine Art nicht mehr: sender.track
 * ist null, und damit ist auch sender.track.kind weg. Die alte Suche fiel
 * deshalb auf "der erste Sender ohne Spur" zurueck - bei stummem Mikrofon
 * lieferte die Frage nach dem Videosender den Audiosender, und replaceTrack
 * warf. Der Transceiver dagegen weiss seine Art immer: sein Empfaenger traegt
 * eine Spur der richtigen Art, ob etwas ankommt oder nicht.
 */
window.webrtcApp = window.webrtcApp || {};

window.webrtcApp.media = {

    // -----------------------------------------------------------------
    // Sender und Spuren finden
    // -----------------------------------------------------------------

    /**
     * Der Sender fuer eine Spurart - auch dann, wenn er gerade keine Spur
     * traegt.
     *
     * @param {string} kind - 'audio' oder 'video'
     * @returns {RTCRtpSender|null}
     */
    senderFor(kind) {
        const pc = window.webrtcApp.refs.localPeerConnection;
        if (!pc) return null;

        const tr = this.transceiverFor(kind);
        if (tr && tr.sender) return tr.sender;

        // Rueckfallebene fuer Umgebungen ohne getTransceivers: Dort geht es
        // nur ueber die Spur, also nur solange gesendet wird.
        if (typeof pc.getSenders !== 'function') return null;
        return pc.getSenders().find(s => s.track && s.track.kind === kind) || null;
    },

    /**
     * Der Transceiver fuer eine Spurart.
     *
     * Erkannt wird er am Empfaenger: Dessen Spur hat immer die richtige Art,
     * auch wenn nichts ankommt. Erst danach wird die eigene Sendespur
     * gefragt - sie kann null sein.
     *
     * @param {string} kind - 'audio' oder 'video'
     * @returns {RTCRtpTransceiver|null}
     */
    transceiverFor(kind) {
        const pc = window.webrtcApp.refs.localPeerConnection;
        if (!pc || typeof pc.getTransceivers !== 'function') return null;

        return pc.getTransceivers().find(t =>
            (t.receiver && t.receiver.track && t.receiver.track.kind === kind)
            || (t.sender && t.sender.track && t.sender.track.kind === kind)
        ) || null;
    },

    /**
     * Die lokale Spur einer Art aus dem eigenen Strom.
     * @param {string} kind - 'audio' oder 'video'
     * @returns {MediaStreamTrack|null}
     */
    localTrack(kind) {
        const stream = window.webrtcApp.refs.localStream;
        if (!stream) return null;
        const tracks = (kind === 'video') ? stream.getVideoTracks() : stream.getAudioTracks();
        return tracks.length ? tracks[0] : null;
    },

    /**
     * Geht diese Spurart gerade tatsaechlich auf die Leitung?
     *
     * Gefragt wird der Sender, nicht der lokale Strom: Was im Strom liegt,
     * sieht nur der Nutzer selbst. Beim Gegenueber kommt an, was am Sender
     * haengt.
     *
     * @param {string} kind - 'audio' oder 'video'
     * @returns {boolean}
     */
    isSending(kind) {
        const sender = this.senderFor(kind);
        return !!(sender && sender.track && sender.track.readyState !== 'ended');
    },

    // -----------------------------------------------------------------
    // Spuren an die Verbindung haengen
    // -----------------------------------------------------------------

    /**
     * Legt die eigenen Spuren beim Angerufenen auf die Transceiver des
     * Angebots.
     *
     * WARUM NICHT addTrack: Der Angerufene bekommt das Angebot mit einer
     * Audio- und einer Videospur. Wer danach addTrack aufruft, bekommt zwar
     * eine Zuordnung - aber wer eine Spurart NICHT anbietet (Kamera im
     * Annahmedialog abgewaehlt), dessen Videotransceiver entsteht aus dem
     * Angebot heraus als "recvonly". Ein spaeteres Einschalten der Kamera
     * braeuchte dann eine Neuaushandlung, und die kann diese Anwendung
     * mitten im Gespraech nicht. Deshalb wird die Richtung hier ausdruecklich
     * auf "sendrecv" gesetzt, bevor die Antwort gebaut wird: Der Platz fuer
     * die Kamera steht in der Antwort, auch wenn sie noch aus ist.
     *
     * Muss NACH setRemoteDescription und VOR createAnswer laufen.
     *
     * @param {MediaStream|null} stream - Der lokale Strom, kann Spuren fehlen
     */
    async attachAnswerTracks(stream) {
        const pc = window.webrtcApp.refs.localPeerConnection;
        if (!pc) return;

        for (const kind of ['audio', 'video']) {
            const track = stream
                ? ((kind === 'video') ? stream.getVideoTracks()[0] : stream.getAudioTracks()[0]) || null
                : null;
            const tr = this.transceiverFor(kind);

            if (tr) {
                // Richtung erst setzen, dann die Spur legen: Eine Spur auf
                // einem "recvonly"-Transceiver ginge nicht raus.
                try { tr.direction = 'sendrecv'; } catch (e) {}
                try {
                    await tr.sender.replaceTrack(track);
                } catch (e) {
                    console.warn('[Medien] Spur "' + kind + '" konnte nicht gelegt werden:', e);
                }
                continue;
            }

            // Kein passender Transceiver im Angebot - dann bleibt nur
            // addTrack. Kommt bei einer Gegenseite ohne diese Spurart vor.
            if (track) {
                try { pc.addTrack(track, stream); } catch (e) {
                    console.warn('[Medien] addTrack fuer "' + kind + '" fehlgeschlagen:', e);
                }
            }
        }
        window.webrtcApp.state.tracksAdded = true;
    },

    // -----------------------------------------------------------------
    // Mikrofon
    // -----------------------------------------------------------------

    /**
     * Schaltet das Mikrofon stumm oder wieder frei.
     *
     * Die Spur wird NICHT gestoppt, nur vom Sender genommen. Beim Mikrofon
     * ist das der richtige Tausch: Stummschalten muss im Gespraech sofort
     * wirken und sofort zuruecknehmbar sein, und ein neuer getUserMedia-Aufruf
     * kostet je nach Geraet eine spuerbare Verzoegerung. Gesendet wird
     * waehrenddessen nichts - replaceTrack(null) haelt die Spur an der Wurzel
     * an, nicht erst beim Empfaenger.
     *
     * @param {boolean} on - true = Mikrofon sendet
     * @returns {Promise<boolean>} true, wenn der Zustand jetzt stimmt
     */
    async setMic(on) {
        const sender = this.senderFor('audio');
        if (!sender) {
            window.webrtcApp.notify.error('Es ist kein Mikrofonkanal ausgehandelt.');
            return false;
        }

        if (!on) {
            try { await sender.replaceTrack(null); }
            catch (e) {
                window.webrtcApp.notify.error('Das Mikrofon liess sich nicht stummschalten: ' + this.errorDetail(e));
                return false;
            }
            this.updateIcons();
            return true;
        }

        // Einschalten: erst die vorhandene Spur, sonst eine neue holen.
        let track = this.localTrack('audio');
        if (!track || track.readyState === 'ended') {
            track = await this.acquireTrack('audio');
            if (!track) return false;
        }
        try { await sender.replaceTrack(track); }
        catch (e) {
            window.webrtcApp.notify.error('Das Mikrofon liess sich nicht einschalten: ' + this.errorDetail(e));
            return false;
        }
        this.updateIcons();
        return true;
    },

    /** Kehrt den Mikrofonzustand um. @returns {Promise<boolean>} */
    async toggleMic() {
        return this.setMic(!this.isSending('audio'));
    },

    // -----------------------------------------------------------------
    // Kamera
    // -----------------------------------------------------------------

    /**
     * Schaltet die eigene Kamera ein oder aus.
     *
     * AUS heisst hier wirklich aus: Die Spur wird vom Sender genommen UND
     * gestoppt und aus dem lokalen Strom entfernt. Nur so erlischt die
     * Kameraleuchte und gibt das Geraet frei - "Video sperren" waere sonst
     * ein Versprechen, das die Anwendung nicht haelt. Der Preis ist ein
     * neuer getUserMedia-Aufruf beim Wiedereinschalten; die Freigabe selbst
     * wird dabei nicht erneut erfragt.
     *
     * Beim Gegenueber kommt das auf ZWEI Wegen an:
     *   1. video_state ueber den Steuerkanal - sofort und ausdruecklich.
     *   2. Die Empfangsspur wird stumm ("muted"), weil nichts mehr gesendet
     *      wird. Darauf hoert rtc.bindRemoteVideoTrack(). Dieser Weg
     *      funktioniert auch dann, wenn der Steuerkanal gerade nicht steht.
     * Ohne beides blieb beim Gegenueber das letzte Standbild stehen.
     *
     * @param {boolean} on - true = Kamera sendet
     * @returns {Promise<boolean>} true, wenn der Zustand jetzt stimmt
     */
    async setCamera(on) {
        const sender = this.senderFor('video');
        if (!sender) {
            window.webrtcApp.notify.error(
                'Für die Kamera wurde beim Verbindungsaufbau kein Kanal ausgehandelt.'
            );
            return false;
        }

        if (!on) {
            try { await sender.replaceTrack(null); }
            catch (e) {
                window.webrtcApp.notify.error('Die Kamera liess sich nicht abschalten: ' + this.errorDetail(e));
                return false;
            }
            this.releaseLocalTracks('video');
            window.webrtcApp.control.sendVideoState(false);
            this.updateLocalPreview();
            this.updateIcons();
            return true;
        }

        const track = await this.acquireTrack('video');
        if (!track) return false;

        try { await sender.replaceTrack(track); }
        catch (e) {
            // Die eben geholte Spur nicht offen liegen lassen.
            try { track.stop(); } catch (e2) {}
            this.removeFromLocalStream(track);
            window.webrtcApp.notify.error('Die Kamera liess sich nicht einschalten: ' + this.errorDetail(e));
            return false;
        }

        window.webrtcApp.control.sendVideoState(true);
        this.updateLocalPreview();
        this.updateIcons();
        return true;
    },

    /** Kehrt den Kamerazustand um. @returns {Promise<boolean>} */
    async toggleCamera() {
        return this.setCamera(!this.isSending('video'));
    },

    // -----------------------------------------------------------------
    // Geraetewechsel im laufenden Call
    // -----------------------------------------------------------------

    /**
     * Wechselt Kamera oder Mikrofon, ohne die Verbindung neu auszuhandeln.
     *
     * Genau dafuer gibt es replaceTrack: Der Sender bleibt derselbe, nur die
     * Quelle dahinter wechselt. Die Gegenseite merkt nichts ausser dem neuen
     * Bild beziehungsweise Ton.
     *
     * Ist die Spurart gerade abgeschaltet, wird die Wahl nur gemerkt und
     * NICHT heimlich eingeschaltet: Wer die Kamera gesperrt hat, will sie
     * nicht durch die Auswahl eines anderen Geraets wieder anhaben.
     *
     * @param {string} kind - 'audio' oder 'video'
     * @param {string} deviceId - deviceId aus enumerateDevices()
     * @returns {Promise<boolean>} true, wenn gewechselt wurde
     */
    async switchDevice(kind, deviceId) {
        if (kind !== 'audio' && kind !== 'video') return false;
        if (!deviceId) {
            // Kommt vor, solange keine Freigabe erteilt wurde: Ohne Freigabe
            // liefert enumerateDevices() leere Kennungen. Frueher brach die
            // Funktion hier still ab - der Wechsel "tat einfach nichts".
            window.webrtcApp.notify.error(
                'Das Gerät lässt sich noch nicht auswählen. Bitte erlauben Sie den Zugriff '
                + 'auf Kamera und Mikrofon und öffnen Sie die Geräteliste erneut.'
            );
            return false;
        }

        this.rememberDevice(kind, deviceId);

        if (!window.webrtcApp.state.isCallActive) return false;

        // Ausgeschaltet: Wahl gemerkt, mehr nicht.
        if (!this.isSending(kind)) {
            window.webrtcApp.notify.info(
                kind === 'video'
                    ? 'Die Kamera ist aus. Die Auswahl gilt, sobald Sie sie einschalten.'
                    : 'Das Mikrofon ist stumm. Die Auswahl gilt, sobald Sie es einschalten.'
            );
            return false;
        }

        const sender = this.senderFor(kind);
        if (!sender) {
            window.webrtcApp.notify.error('Für dieses Gerät ist kein Kanal ausgehandelt.');
            return false;
        }

        const alt = this.localTrack(kind);
        const neu = await this.acquireTrack(kind);
        if (!neu) return false;

        try {
            await sender.replaceTrack(neu);
        } catch (e) {
            try { neu.stop(); } catch (e2) {}
            this.removeFromLocalStream(neu);
            window.webrtcApp.notify.error('Das Gerät liess sich nicht übernehmen: ' + this.errorDetail(e));
            return false;
        }

        // Erst jetzt die alte Quelle freigeben - vorher waere bei einem
        // Fehlschlag beides weg gewesen.
        if (alt && alt !== neu) {
            try { alt.stop(); } catch (e) {}
            this.removeFromLocalStream(alt);
        }

        if (kind === 'video') this.updateLocalPreview();
        this.updateIcons();
        return true;
    },

    /**
     * Holt eine einzelne Spur und haengt sie in den lokalen Strom.
     *
     * Der einzige Ort, an dem diese Anwendung waehrend eines Calls Medien
     * anfordert - und damit der einzige Ort, an dem eine Ablehnung entstehen
     * kann. Jeder Fehler wird hier benannt. Vorher lief der Geraetewechsel
     * ohne try/catch: Eine verweigerte Kamera endete als unbehandelte
     * Promise-Ablehnung in der Konsole, und der Nutzer sah nichts.
     *
     * @param {string} kind - 'audio' oder 'video'
     * @returns {Promise<MediaStreamTrack|null>} null bei Fehler (Meldung ist raus)
     */
    async acquireTrack(kind) {
        const constraints = {};
        constraints[kind] = this.deviceConstraint(kind);

        let stream;
        try {
            stream = await navigator.mediaDevices.getUserMedia(constraints);
        } catch (fehler) {
            // Ein festgehaltenes Geraet kann verschwunden sein (abgezogen,
            // von einem anderen Programm belegt). Dann noch einmal ohne
            // Geraetewunsch - irgendeine Kamera ist besser als keine.
            if (this.isDeviceConstraintError(fehler) && this.deviceConstraint(kind) !== true) {
                this.rememberDevice(kind, null);
                try {
                    const zweit = {};
                    zweit[kind] = true;
                    stream = await navigator.mediaDevices.getUserMedia(zweit);
                } catch (zweiterFehler) {
                    window.webrtcApp.notify.error(window.webrtcApp.rtc.mediaErrorText(zweiterFehler, kind));
                    return null;
                }
            } else {
                window.webrtcApp.notify.error(window.webrtcApp.rtc.mediaErrorText(fehler, kind));
                return null;
            }
        }

        const tracks = (kind === 'video') ? stream.getVideoTracks() : stream.getAudioTracks();
        const track = tracks.length ? tracks[0] : null;
        if (!track) {
            window.webrtcApp.notify.error(window.webrtcApp.rtc.mediaErrorText({ name: 'NotFoundError' }, kind));
            return null;
        }

        if (!window.webrtcApp.refs.localStream) {
            window.webrtcApp.refs.localStream = stream;
        } else if (window.webrtcApp.refs.localStream !== stream) {
            window.webrtcApp.refs.localStream.addTrack(track);
        }

        // Jetzt liegt eine Freigabe vor: Erst ab diesem Moment liefert
        // enumerateDevices() Namen und Kennungen der Geraete.
        this.refreshDeviceLists();
        return track;
    },

    /**
     * Ist der Fehler auf den Geraetewunsch zurueckzufuehren?
     * @param {Error} fehler
     * @returns {boolean}
     */
    isDeviceConstraintError(fehler) {
        const name = fehler && fehler.name ? fehler.name : '';
        return name === 'OverconstrainedError' || name === 'NotFoundError' || name === 'ConstraintNotSatisfiedError';
    },

    /**
     * Nimmt eine Spurart aus dem lokalen Strom und stoppt sie.
     * @param {string} kind - 'audio' oder 'video'
     */
    releaseLocalTracks(kind) {
        const stream = window.webrtcApp.refs.localStream;
        if (!stream) return;
        const tracks = (kind === 'video') ? stream.getVideoTracks() : stream.getAudioTracks();
        tracks.forEach(t => {
            try { t.stop(); } catch (e) {}
            try { stream.removeTrack(t); } catch (e) {}
        });
    },

    /**
     * Nimmt eine einzelne Spur aus dem lokalen Strom.
     * @param {MediaStreamTrack} track
     */
    removeFromLocalStream(track) {
        const stream = window.webrtcApp.refs.localStream;
        if (!stream || !track) return;
        try { stream.removeTrack(track); } catch (e) {}
    },

    // -----------------------------------------------------------------
    // Geraetewahl merken und anzeigen
    // -----------------------------------------------------------------

    /**
     * Merkt sich die gewaehlte Geraetekennung fuer diesen Call.
     * @param {string} kind - 'audio' oder 'video'
     * @param {string|null} deviceId
     */
    rememberDevice(kind, deviceId) {
        const media = window.webrtcApp.state.media;
        if (kind === 'video') media.videoDeviceId = deviceId || null;
        else                  media.audioDeviceId = deviceId || null;
    },

    /**
     * Die Bedingung fuer getUserMedia: das gemerkte Geraet, sonst irgendeins.
     * @param {string} kind - 'audio' oder 'video'
     * @returns {Object|boolean}
     */
    deviceConstraint(kind) {
        const media = window.webrtcApp.state.media;
        const id = (kind === 'video') ? media.videoDeviceId : media.audioDeviceId;
        if (!id) return true;
        return { deviceId: { exact: id } };
    },

    /**
     * Fuellt die vier Auswahlfelder (Annahmedialog und Call-Ansicht) neu.
     *
     * MUSS nach jeder erteilten Freigabe laufen. Ohne Freigabe gibt
     * enumerateDevices() Eintraege ohne Namen und mit LEERER Kennung heraus -
     * genau die standen bisher dauerhaft in der Liste, weil sie einmal beim
     * Seitenaufbau geholt und nie erneuert wurden. Ein Wechsel auf einen
     * solchen Eintrag konnte gar nicht wirken.
     *
     * Die bisherige Auswahl bleibt stehen, sofern es das Geraet noch gibt.
     *
     * @returns {Promise<void>}
     */
    async refreshDeviceLists() {
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function') return;

        let devices;
        try {
            devices = await navigator.mediaDevices.enumerateDevices();
        } catch (e) {
            console.warn('[Medien] Geräteliste konnte nicht gelesen werden:', e);
            return;
        }

        this.fillSelects(['camera-select', 'camera-select-in-call'],
            devices.filter(d => d.kind === 'videoinput'), 'Kamera', 'video');
        this.fillSelects(['mic-select', 'mic-select-in-call'],
            devices.filter(d => d.kind === 'audioinput'), 'Mikrofon', 'audio');
    },

    /**
     * Schreibt eine Geraeteliste in mehrere Auswahlfelder.
     *
     * @param {string[]} ids - IDs der Auswahlfelder
     * @param {Array} devices - Eintraege aus enumerateDevices()
     * @param {string} bezeichnung - "Kamera" oder "Mikrofon"
     * @param {string} kind - 'audio' oder 'video'
     */
    fillSelects(ids, devices, bezeichnung, kind) {
        const aktiv = this.activeDeviceId(kind);
        const gemerkt = (kind === 'video')
            ? window.webrtcApp.state.media.videoDeviceId
            : window.webrtcApp.state.media.audioDeviceId;

        ids.forEach(id => {
            const sel = document.getElementById(id);
            if (!sel) return;

            // Was vorher gewaehlt war, soll gewaehlt bleiben.
            const vorher = sel.value;
            sel.innerHTML = '';

            if (devices.length === 0) {
                const leer = document.createElement('option');
                leer.text = '(Kein ' + bezeichnung + ' gefunden)';
                leer.disabled = true;
                sel.appendChild(leer);
                return;
            }

            devices.forEach((device, i) => {
                const option = document.createElement('option');
                option.value = device.deviceId;
                // Ohne Freigabe ist das Label leer - dann eine Nummer.
                option.text = device.label || (bezeichnung + ' ' + (i + 1));
                sel.appendChild(option);
            });

            const wunsch = [aktiv, gemerkt, vorher].find(w =>
                w && devices.some(d => d.deviceId === w)
            );
            if (wunsch) sel.value = wunsch;
        });
    },

    /**
     * Die Kennung des Geraets, das gerade tatsaechlich sendet.
     * @param {string} kind - 'audio' oder 'video'
     * @returns {string|null}
     */
    activeDeviceId(kind) {
        const sender = this.senderFor(kind);
        const track = (sender && sender.track) ? sender.track : this.localTrack(kind);
        if (!track || typeof track.getSettings !== 'function') return null;
        const settings = track.getSettings() || {};
        return settings.deviceId || null;
    },

    // -----------------------------------------------------------------
    // Anzeige
    // -----------------------------------------------------------------

    /**
     * Haelt das eigene Vorschaubild und seinen Platzhalter nach.
     *
     * Der Platzhalter ist beim Guide keine Kleinigkeit: Bei ihm IST das
     * eigene Bild die Buehne (siehe assets/css/call.css, Rollenabschnitt).
     * Laeuft die Kamera nicht, muss dort etwas stehen, das das sagt - sonst
     * sieht er eine schwarze Flaeche und weiss nicht, ob es an ihm oder an
     * der Verbindung liegt.
     */
    updateLocalPreview() {
        const video = document.getElementById('local-video');
        const platzhalter = document.getElementById('local-video-placeholder');
        const an = this.isSending('video');

        if (video) {
            video.srcObject = an ? window.webrtcApp.refs.localStream : null;
            video.style.display = an ? '' : 'none';
        }
        if (platzhalter) platzhalter.style.display = an ? 'none' : 'flex';
    },

    /**
     * Setzt die Symbole der Bedienleiste auf den tatsaechlichen Sendezustand.
     *
     * Gefragt wird der Sender, nicht der lokale Strom: Das Symbol soll
     * zeigen, was beim Gegenueber ankommt.
     */
    updateIcons() {
        const mic = this.isSending('audio');
        const cam = this.isSending('video');

        const micIcon = document.getElementById('mic-icon');
        if (micIcon) micIcon.src = mic ? 'assets/img/mic.png' : 'assets/img/mic-off.png';
        const micBtn = document.getElementById('switch-mic-btn');
        if (micBtn) {
            micBtn.title = mic ? 'Mikrofon stummschalten' : 'Mikrofon einschalten';
            if (micBtn.setAttribute) micBtn.setAttribute('aria-pressed', mic ? 'false' : 'true');
        }

        const camIcon = document.getElementById('cam-icon');
        if (camIcon) camIcon.src = cam ? 'assets/img/camera.png' : 'assets/img/camera-off.png';
        const camBtn = document.getElementById('switch-cam-btn');
        if (camBtn) {
            camBtn.title = cam ? 'Kamera ausschalten' : 'Kamera einschalten';
            if (camBtn.setAttribute) camBtn.setAttribute('aria-pressed', cam ? 'false' : 'true');
        }

        this.updateLocalPreview();
    },

    /**
     * Meldet der Gegenseite den EIGENEN Videozustand, so wie er gerade ist.
     *
     * Laeuft, sobald der Steuerkanal offen ist. Ohne diese Meldung wusste die
     * Gegenseite beim Verbindungsaufbau gar nichts: Wer die Kamera im
     * Annahmedialog abwaehlte, sendete nie ein video_state - beim Zuschauer
     * blieb eine schwarze Flaeche ohne Erklaerung stehen, weil der
     * Platzhalter im Markup auf "aus" steht und nur durch eine Meldung
     * eingeschaltet wird.
     */
    announceVideoState() {
        if (!window.webrtcApp.state.isCallActive) return;
        window.webrtcApp.control.sendVideoState(this.isSending('video'));
    },

    /**
     * Kurztext eines Fehlers fuer eine Meldung.
     * @param {Error} e
     * @returns {string}
     */
    errorDetail(e) {
        if (!e) return 'unbekannter Fehler';
        return e.message || e.name || 'unbekannter Fehler';
    },

    /**
     * Setzt den Medienzustand zwischen zwei Calls zurueck.
     * Die Geraetewahl ueberlebt bewusst NICHT: Sie gehoert zu einem Call.
     */
    reset() {
        const media = window.webrtcApp.state.media;
        media.videoDeviceId = null;
        media.audioDeviceId = null;

        const video = document.getElementById('local-video');
        if (video) video.style.display = '';
        const platzhalter = document.getElementById('local-video-placeholder');
        if (platzhalter) platzhalter.style.display = 'none';
    }
};

// Ein Aufruf, den es schon gab: assets/js/locations_table.js und
// assets/js/home_map.js rufen updateCallIcons() ohne Modulpfad. Er wird hier
// gesetzt und nicht mehr erst in main.js - dann steht er ab dem Laden dieser
// Datei und nicht erst nach der Initialisierung.
window.updateCallIcons = function() { window.webrtcApp.media.updateIcons(); };
