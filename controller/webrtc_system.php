<?php

  function act_requestUserId()
{
    // Benutzereingaben validieren
    if (isset($_POST['requestUserId'])) {
        $senderId = $_SESSION['user_id']; // Aktuellen Benutzer aus der Session holen
        $receiverId = $_POST['requestUserId'];
        $tmp_connection = new Connection();
        $answer = '';

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



    function act_acceptRequest()
    {
        // Wenn eine Anfrage angenommen wird
        if (isset($_POST['acceptRequest'])) {
            $senderId = $_POST['senderId'];
            $receiverId = $_SESSION['user_id'];
            updateRequestStatus($senderId, $receiverId, 'accepted');
            echo "Anfrage akzeptiert!";
        }
    }

    function act_declineRequest()
    {
        // Wenn eine Anfrage abgelehnt wird
        if (isset($_POST['declineRequest'])) {
            $senderId = $_POST['senderId'];
            $receiverId = $_SESSION['user_id'];
            updateRequestStatus($senderId, $receiverId, 'declined');
            echo "Anfrage abgelehnt!";
        }
    }