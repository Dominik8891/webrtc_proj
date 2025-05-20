window.activeTargetUserId = null;

window.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.start-call-btn').forEach(button => {
        let btn_id = button.getAttribute('id').replace('start-call-btn-', '');
        button.addEventListener("click", function() {
            console.log("Anruf mit User-ID:", btn_id);
            // Ziel-User ggf. speichern, wenn für die Signalisierung nötig
            startCall(btn_id);
        });
    });
});


// Füge das z.B. am Ende von rtc.js oder in main.js ein:
navigator.mediaDevices.getUserMedia({ video: true, audio: true })
    .then(stream => {
        localStream = stream;
        document.getElementById('local-video').srcObject = stream;
    })
    .catch(err => {
        alert("Kein Zugriff auf Kamera/Mikrofon: " + err);
    });

window.addEventListener('DOMContentLoaded', function() {
    // Button-Event-Handler etc. einrichten ...
    // Jetzt das Polling starten:
    pollSignaling();
});

window.addEventListener('DOMContentLoaded', function() {
    // ... dein anderer Code ...
    document.getElementById('end-call-btn').addEventListener('click', endCall);
});

