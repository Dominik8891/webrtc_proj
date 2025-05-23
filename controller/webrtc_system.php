<?php

function act_getSignal() {
    // Prüfen ob POST Daten da sind (Senden eines Signals)
    //file_put_contents('debug.txt', "getSignal gestartet\n", FILE_APPEND);
    try
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Nachricht als JSON lesen
            $input = file_get_contents('php://input');
            //$file_put_contents('debug.txt', "RAW INPUT: $input\n", FILE_APPEND);

            $data = json_decode($input, true);
            //file_put_contents('debug.txt', "DECODED: " . print_r($data, true) . "\n", FILE_APPEND);

            file_put_contents('debug.txt', "input###\n", FILE_APPEND);
            file_put_contents('debug.txt', print_r($input . "\n", true), FILE_APPEND);
            file_put_contents('debug.txt', "ganze_data datei:###\n", FILE_APPEND);
            file_put_contents('debug.txt', print_r($data, true) . "\n", FILE_APPEND);
            if (isset($data['type']) && isset($data['target'])) {
                $sender = $_SESSION['user_id'];

                $candidate = isset($data['candidate']) ? (is_array($data['candidate']) ? json_encode($data['candidate']) : $data['candidate']) : null;
                $sdp = isset($data['sdp']) ? $data['sdp'] : null;
                $type = $data['type'];
                $target = $data['target'];

                file_put_contents('debug.txt', "data_sdp:###", FILE_APPEND);
                file_put_contents('debug.txt', print_r($sdp, true), FILE_APPEND);
                $query = "INSERT INTO rtc_signal (sender_id, receiver_id, type, sdp, candidate, created_at)
                        VALUES (:sender, :receiver, :type, :sdp, :candidate, NOW())";
                $stmt = PdoConnect::$connection->prepare($query);
                $stmt->bindParam(':sender', $sender);
                $stmt->bindParam(':receiver', $target);
                $stmt->bindParam(':type', $type);
                $stmt->bindParam(':sdp', $sdp);
                $stmt->bindParam(':candidate', $candidate);
                $stmt->execute();

                echo json_encode(['status' => 'ok']);
                exit;
            }
        }

        // Jetzt der Abruf für den Empfänger:
        $receiver = $_SESSION['user_id'];
        $query = "SELECT * FROM rtc_signal WHERE receiver_id = :receiver ORDER BY created_at ASC";
        $stmt = PdoConnect::$connection->prepare($query);
        $stmt->bindParam(':receiver', $receiver);
        $stmt->execute();
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        file_put_contents('debug.txt', "User ID###\n", FILE_APPEND);
        file_put_contents('debug.txt', $_SESSION['user_id'] . "\n", FILE_APPEND);
        file_put_contents('debug.txt', "Decodierte Message:###\n", FILE_APPEND);
        file_put_contents('debug.txt', "DECODED: " . print_r($messages, true) . "\n", FILE_APPEND);
        // NEU: Leere ICE-Kandidaten rausfiltern und nur gültige zurückgeben!
        $filteredMessages = [];
        foreach ($messages as $msg) {
            if ($msg['type'] === 'iceCandidate') {
                if (empty($msg['candidate'])) continue; // Leere rausfiltern
                $msg['candidate'] = json_decode($msg['candidate'], true);
                if (empty($msg['candidate']) || empty($msg['candidate']['candidate'])) continue; // Nochmals auf leeres Objekt/candidate prüfen!
            }
            $filteredMessages[] = $msg;
        }

        // Optional: Nach dem Senden die Nachrichten löschen:
        $queryDel = "DELETE FROM rtc_signal WHERE receiver_id = :receiver";
        //$queryDel = "DELETE FROM rtc_signal;";
        $stmtDel = PdoConnect::$connection->prepare($queryDel);
        $stmtDel->bindParam(':receiver', $receiver);
        $stmtDel->execute();
        echo json_encode($filteredMessages);
        exit;
    }
     catch (Exception $e) {
        file_put_contents('debug.txt', "Fehler: " . $e->getMessage() . "\n", FILE_APPEND);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }
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