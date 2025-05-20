
        var localStream;
        var localPeerConnection;
        var remotePeerConnection;
        var userId = '<?php echo $_SESSION["user_id"]; ?>'; // Aktuelle Benutzer-ID

        // WebRTC Verbindung herstellen, sobald die Anfrage akzeptiert wird
        function startVideoCall(receiverId) {
            // Zugriff auf Kamera und Mikrofon
            navigator.mediaDevices.getUserMedia({ video: true, audio: true })
                .then(stream => {
                    document.getElementById('local-video').srcObject = stream;
                    localStream = stream;

                    // Peer-Verbindung erstellen und Anfrage senden
                    createPeerConnection();
                    addTracksToConnection(localStream);
                    createOffer();
                })
                .catch(error => console.log("Fehler bei getUserMedia: ", error));
        }

        function createPeerConnection() {
            var config = { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] };
            localPeerConnection = new RTCPeerConnection(config);
            remotePeerConnection = new RTCPeerConnection(config);

            // Verbindungsaufbau
            localPeerConnection.onicecandidate = function(event) {
                if (event.candidate) {
                    sendSignalMessage({ type: "iceCandidate", candidate: event.candidate });
                }
            };

            remotePeerConnection.ontrack = function(event) {
                document.getElementById('remote-video').srcObject = event.streams[0];
            };

            remotePeerConnection.onicecandidate = function(event) {
                if (event.candidate) {
                    sendSignalMessage({ type: "iceCandidate", candidate: event.candidate });
                }
            };
        }

        function addTracksToConnection(stream) {
            stream.getTracks().forEach(track => {
                localPeerConnection.addTrack(track, stream);
            });
        }

        function createOffer() {
            localPeerConnection.createOffer()
                .then(offer => {
                    return localPeerConnection.setLocalDescription(offer);
                })
                .then(() => {
                    sendSignalMessage({ type: "offer", sdp: localPeerConnection.localDescription });
                })
                .catch(error => console.error("Fehler bei Angebotserstellung: ", error));
        }

        function sendSignalMessage(message) {
            $.ajax({
                url: 'signal.php',
                type: 'POST',
                data: { message: JSON.stringify(message) },
                success: function(response) {
                    console.log("Signalisierung gesendet");
                }
            });
        }
   