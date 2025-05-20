<?


function act_requestUserId()
{
    session_start(); // Session starten, falls noch nicht geschehen

    // Benutzereingaben validieren
    if (isset($_POST['requestUserId'])) {
        $senderId = $_SESSION['user_id']; // Aktuellen Benutzer aus der Session holen
        
        // Stelle sicher, dass der Benutzer eingeloggt ist
        if (!$senderId) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Benutzer nicht eingeloggt.'
            ]);
            exit();
        }

        $receiverId = $_POST['requestUserId'];
        
        // Prüfen, ob Empfänger-ID eine gültige Zahl ist
        if (!is_numeric($receiverId)) {
            echo json_encode([
                'status' => 'error',
                'message' => 'Ungültige Empfänger-ID.'
            ]);
            exit();
        }

        // Stelle sicher, dass die Connection-Klasse korrekt eingebunden ist
        require_once 'Connection.php'; // Beispiel für das Einbinden der Klasse

        // Instanziiere die Verbindung
        $tmp_connection = new Connection();

        // Anfrage an den Empfänger senden
        if ($tmp_connection->sendRequest($senderId, $receiverId)) {
            // Anfrage erfolgreich gesendet
            echo json_encode([
                'status' => 'success',
                'message' => 'Anfrage erfolgreich gesendet!'
            ]);
        } else {
            // Empfänger ist offline oder nicht vorhanden
            echo json_encode([
                'status' => 'error',
                'message' => 'Empfänger ist offline oder nicht vorhanden!'
            ]);
        }
    } else {
        // Falls 'requestUserId' nicht gesetzt ist
        echo json_encode([
            'status' => 'error',
            'message' => 'Keine Benutzer-ID gesendet.'
        ]);
    }
    exit(); // Stelle sicher, dass das Skript nach der Antwort endet
}
