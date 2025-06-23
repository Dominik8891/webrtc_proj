/**
 * Fragt den aktuellen Standort des Benutzers ab und sendet ihn per POST an den Server.
 * Falls die Geolocation-API nicht verfügbar ist oder die Ermittlung fehlschlägt, gibt es eine Fehlermeldung.
 */
function askLocation() {
    // Prüft, ob der Browser Geolocation unterstützt
    if (navigator.geolocation) {
        // Holt aktuelle Position (asynchron)
        navigator.geolocation.getCurrentPosition(
            function(position) {
                // Wenn erfolgreich: Sende die Koordinaten per AJAX (Fetch) an den Server
                fetch('index.php?act=save_location', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        lat: position.coords.latitude,   // Breitengrad
                        lon: position.coords.longitude   // Längengrad
                    })
                }).then(response => {
                    // Nach dem Senden: Leite zur Startseite weiter (z.B. um Map anzuzeigen)
                    window.location.href = "index.php";
                });
            },
            function() {
                // Fehlerfall: Standort konnte nicht ermittelt werden (z.B. abgelehnt)
                alert("Standort konnte nicht ermittelt werden.");
                window.location.href = "index.php";
            }
        );
    } else {
        // Browser unterstützt kein Geolocation
        alert("Geolocation wird von diesem Browser nicht unterstützt.");
        window.location.href = "index.php";
    }
}
