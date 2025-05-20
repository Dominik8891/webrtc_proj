function sendPostRequest(btnId) {
    fetch("index.php?act=requestUserId", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: "requestUserId=" + btnId
    })
    .then(response => {
        // Überprüfen, ob die Antwort erfolgreich war (Status 200)
        if (!response.ok) {
            throw new Error("Fehler: " + response.statusText); // Fehler auslösen, wenn der Status nicht OK ist
        }

        // Überprüfen, ob die Antwort im JSON-Format vorliegt
        return response.json()
            .then(data => {
                // Erfolgsfall: Antwort vom Server
                if (data.status === "success") {
                    console.log("Erfolg:", data.message); // Ausgabe der erfolgreichen Antwort
                    alert(data.message); // Erfolgsnachricht anzeigen
                } else {
                    // Fehlerfall: Fehlerstatus aus der Antwort
                    console.error("Fehler:", data.message);
                    alert("Fehler: " + data.message); // Fehlernachricht anzeigen
                }
            })
            .catch(err => {
                // Fehler beim Parsen der Antwort
                console.error("Fehler beim Verarbeiten der Antwort:", err);
                alert("Fehler beim Verarbeiten der Antwort.");
            });
    })
    .catch(error => {
        // Fehlerbehandlung
        console.error("Fehler:", error);
        alert("Ein Fehler ist aufgetreten: " + error.message); // Fehler anzeigen
    });
}
