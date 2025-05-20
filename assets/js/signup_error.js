// Wartet, bis das DOM vollständig geladen ist, bevor das Skript ausgeführt wird.
document.addEventListener("DOMContentLoaded", function() {
    
    // PHP ersetzt diesen Platzhalter durch den tatsächlichen Fehlercode.
    var error = "###ERROR###";

    // Wenn ein Fehler vorhanden ist, wird der entsprechende Alert ausgegeben.
    if (error) {
        if (error === "username") {
            alert("Username already exists. Please choose another one.");
        } else if (error === "email") {
            alert("Email already in use. Please choose another one.");
        } else if (error === "pw") {
            alert("Passwords do not match. Please try again.");
        } else {
            alert("An unknown error occurred. Please try again.");
        }
    }
});
