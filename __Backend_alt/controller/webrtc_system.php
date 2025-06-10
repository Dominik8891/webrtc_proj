<?php

/**
 * Handhabt Signalisierung für WebRTC (Senden & Empfangen von Offer/Answer/ICE...).
 */
function act_getSignal()
{
    try {
        // POST = Neue Nachricht abspeichern (z.B. offer, answer, candidate)
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);

            if (isset($data['type']) && isset($data['target'])) {
                $sender   = $_SESSION['user_id'];
                $type     = $data['type'];
                $target   = $data['target'];
                $sdp      = $data['sdp'] ?? null;
                $candidate = isset($data['candidate']) ?
                             (is_array($data['candidate']) ? json_encode($data['candidate']) : $data['candidate'])
                             : null;

                $rtc_handler = new WebRTCHandler();
                $rtc_handler->set_sender($sender);
                $rtc_handler->set_receiver($target);
                $rtc_handler->set_type($type);
                $rtc_handler->set_sdp($sdp);
                $rtc_handler->set_candidate($candidate);
                $rtc_handler->create();

                echo json_encode(['status' => 'ok']);
                exit;
            }
        }

        // GET/sonst: Nachrichten abrufen (alle für diesen Empfänger)
        if (isset($_SESSION['user_id'])) {
            $receiver = $_SESSION['user_id'];
            $rtc_handler = new WebRTCHandler();
            $messages = $rtc_handler->get_all_signals_for_receiver($receiver);

            $filtered_messages = signal_message_filter($messages);

            $rtc_handler->delete_signals_for_receiver($receiver);

            echo json_encode($filtered_messages);
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }
}

/**
 * Filtert Nachrichten, damit keine leeren oder defekten ICE-Kandidaten ausgeliefert werden.
 *
 * @param array $messages
 * @return array
 */
function signal_message_filter($messages)
{
    $filteredMessages = [];
    foreach ($messages as $msg) {
        if ($msg['type'] === 'iceCandidate') {
            if (empty($msg['candidate'])) continue;
            $msg['candidate'] = json_decode($msg['candidate'], true);
            if (empty($msg['candidate']) || empty($msg['candidate']['candidate'])) continue;
        }
        $filteredMessages[] = $msg;
    }
    return $filteredMessages;
}
