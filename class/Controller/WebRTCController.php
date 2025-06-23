<?php
namespace App\Controller;

use App\Model\User;
use App\Model\WebRTCHandler;
use App\Helper\Request;

/**
 * WebRTCController – Steuert das Signaling für WebRTC-Verbindungen.
 * Übernimmt die Annahme und Verteilung von Offer/Answer/Candidate zwischen Nutzern.
 */
class WebRTCController
{
    /**
     * Handhabt Signalisierung für WebRTC.
     * - POST: Neue Nachricht abspeichern (offer, answer, candidate)
     * - GET:  Nachrichten für diesen Empfänger abrufen
     *
     * @return void
     */
    public function getSignal()
    {
        try {
            // POST = Neue Nachricht abspeichern (z.B. offer, answer, candidate)
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);

                if (isset($data['type']) && isset($data['target'])) {
                    $sender    = $_SESSION['user']['user_id'];
                    $type      = $data['type'];
                    $target    = $data['target'];
                    $sdp       = $data['sdp'] ?? null;
                    $candidate = isset($data['candidate']) ?
                        (is_array($data['candidate']) ? json_encode($data['candidate']) : $data['candidate'])
                        : null;

                    $rtc_handler = new WebRTCHandler();
                    $rtc_handler->setSender($sender);
                    $rtc_handler->setReceiver($target);
                    $rtc_handler->setType($type);
                    $rtc_handler->setSdp($sdp);
                    $rtc_handler->setCandidate($candidate);
                    $rtc_handler->create();

                    echo json_encode(['status' => 'ok']);
                    exit;
                }
            }

            // GET oder sonst: Nachrichten abrufen (alle für diesen Empfänger)
            if (isset($_SESSION['user']['user_id'])) {
                $receiver = $_SESSION['user']['user_id'];
                $rtc_handler = new WebRTCHandler();
                $messages = $rtc_handler->getAllSignalsForReceiver($receiver);

                $filtered_messages = self::signalMessageFilter($messages);

                $rtc_handler->deleteSignalsForReceiver($receiver);

                echo json_encode($filtered_messages);
                exit;
            }
        } catch (\Exception $e) {
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
    public static function signalMessageFilter($messages)
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
}
