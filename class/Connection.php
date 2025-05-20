<?php
class Connection {

    // Anfrage an einen anderen Benutzer senden
    public function sendRequest($senderId, $receiverId) 
    {
        $query = "SELECT * FROM user WHERE id = :receiver AND user_status = 'online'";
        $stmt = PdoConnect::$connection->prepare($query);
        $stmt ->bindParam(':receiver', $receiverId);
        $stmt->execute();
        $receiver_online = $stmt->fetch();

        $query = "SELECT * FROM user_activity WHERE user_id = :receiver";
        $stmt = PdoConnect::$connection->prepare($query);
        $stmt ->bindParam(':receiver', $receiverId);
        $stmt->execute();
        $result = $stmt->fetch();

        $active = false;
        if ($result) 
        {
            $lastActivityTimestamp = $result['last_activity']; // Der Zeitstempel der letzten Aktivität (im Format 'Y-m-d H:i:s')
            $now = new DateTime(); // Aktueller Zeitpunkt
            $lastActivity = new DateTime($lastActivityTimestamp); // Letzte Aktivität
            $interval = $now->diff($lastActivity); // Differenz zwischen jetziger Zeit und letzter Aktivität

            // Überprüfen, ob die Differenz mehr als 10 Minuten beträgt
            if ($interval->i <= 10 && $interval->h == 0 && $interval->d == 0 && $interval->m == 0 && $interval->y == 0) 
            {
                // Wenn mehr als 10 Minuten vergangen sind
                $active = true;
            }
        }

        if ($receiver_online && $active) 
        {
            // Anfrage speichern
            $stmt = PdoConnect::$connection->prepare("INSERT INTO request (sender_id, receiver_id, request_status) VALUES (:sender, :receiver, 'pending')");
            $stmt->bindParam(':sender', $senderId);
            $stmt->bindParam(':receiver', $receiverId);
            $stmt->execute();

            return true; // Anfrage erfolgreich gesendet
        } else {
            return false; // Empfänger nicht online oder existiert nicht
        }
    }

    // Alle offenen Anfragen eines Benutzers abrufen
    public function getPendingRequests($userId) {
        $stmt = PdoConnect::$connection->prepare("SELECT sender_id FROM request WHERE receiver_id = ? AND request_status = 'pending'");
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    // Anfragen annehmen oder ablehnen
    public function updateRequestStatus($senderId, $receiverId, $status) {
        $stmt = PdoConnect::$connection->prepare("UPDATE request SET request_status = ? WHERE sender_id = ? AND receiver_id = ?");
        $stmt->execute([$status, $senderId, $receiverId]);
    }
}
?>
