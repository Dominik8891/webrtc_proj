<?php

    function act_getSignal() {
        // Prüfen ob POST Daten da sind (Senden eines Signals)
        file_put_contents('debug.txt', "getSignal gestartet\n", FILE_APPEND);
        try
        {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Nachricht als JSON lesen
            $input = file_get_contents('php://input');
            file_put_contents('debug.txt', "RAW INPUT: $input\n", FILE_APPEND);

            $data = json_decode($input, true);
            file_put_contents('debug.txt', "DECODED: " . print_r($data, true) . "\n", FILE_APPEND);


            // Prüfen ob alles Nötige da ist (type, sdp oder candidate, target)
            // Prüfen ob alles Nötige da ist (type, sdp oder candidate, target)
                if (isset($data['type']) && isset($data['target'])) {
                    $sender = $_SESSION['user_id'];

                    $candidate = isset($data['candidate']) ? (is_array($data['candidate']) ? json_encode($data['candidate']) : $data['candidate']) : null;
                    $sdp = isset($data['sdp']) ? $data['sdp'] : null;
                    $type = $data['type'];
                    $target = $data['target'];

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
            // Empfänger-ID aus Session:
            $receiver = $_SESSION['user_id'];
            $query = "SELECT * FROM rtc_signal WHERE receiver_id = :receiver ORDER BY created_at ASC";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':receiver', $receiver);
            $stmt->execute();
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Optional: Nach dem Senden die Nachrichten löschen:
            $queryDel = "DELETE FROM rtc_signal WHERE receiver_id = :receiver";
            $stmtDel = PdoConnect::$connection->prepare($queryDel);
            $stmtDel->bindParam(':receiver', $receiver);
            $stmtDel->execute();

            echo json_encode($messages);
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