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
     * usertype.id des Guides (database.sql: 0=Admin, 1=Guide, 2=User, 3=Trial).
     *
     * Verglichen wird bewusst die ID und nicht der Name: Der Name kommt als
     * 'Guide' aus der Datenbank, und genau dieser Vergleich gegen
     * kleingeschriebene Literale ist an anderer Stelle schon schiefgegangen
     * (Befunde F-5/F-6 der Bestandsaufnahme).
     */
    public const USERTYPE_GUIDE = 1;

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

                    $response = ['status' => 'ok'];
                    // Beim Anruf legt der Server die Rollen fest und gibt dem
                    // Anrufer seine eigene direkt zurueck. Sie haengt damit am
                    // Offer statt an einer zweiten Anfrage: kein Zeitfenster,
                    // in dem ein Client ohne Rolle dasteht, und keine
                    // Gelegenheit, sich selbst eine zuzuweisen.
                    if ($type === 'offer') {
                        $response['role'] = self::roleForCall($sender, $target, $sender);
                    }

                    echo json_encode($response);
                    exit;
                }
            }

            // GET oder sonst: Nachrichten abrufen (alle für diesen Empfänger)
            if (isset($_SESSION['user']['user_id'])) {
                $receiver = $_SESSION['user']['user_id'];
                $rtc_handler = new WebRTCHandler();
                $messages = $rtc_handler->getAllSignalsForReceiver($receiver);

                // Genau die IDs merken, die dieser SELECT gelesen hat. Nur die
                // dürfen gelöscht werden - ein Signal, das zwischen SELECT und
                // DELETE eintrifft, muss liegen bleiben und wird beim nächsten
                // Poll ausgeliefert (Befund F-1).
                $delivered_ids = [];
                foreach ($messages as $msg) {
                    if (isset($msg['id'])) {
                        $delivered_ids[] = $msg['id'];
                    }
                }

                $filtered_messages = self::signalMessageFilter($messages);
                // Der Angerufene bekommt seine Rolle am ausgelieferten Offer.
                $filtered_messages = self::stampCallRoles($filtered_messages, $receiver);

                // Gelöscht wird die gesamte gelesene Menge, also auch die vom
                // Filter verworfenen kaputten Kandidaten: Die sind ausgewertet
                // und würden sonst bei jedem Poll erneut geliefert.
                $rtc_handler->deleteSignalsByIds($receiver, $delivered_ids);

                // Zeilen, die das 15-Sekunden-Lesefenster nie erreicht haben,
                // separat aufräumen, damit die Tabelle nicht wächst.
                $rtc_handler->deleteExpiredSignalsForReceiver($receiver);

                echo json_encode($filtered_messages);
                exit;
            }

            // Weder gültiger POST noch angemeldeter Empfänger: Bisher endete die
            // Methode hier ohne jede Ausgabe (HTTP 200, leerer Body) und der
            // Client warf beim Parsen. Jetzt gibt es eine auswertbare Antwort.
            echo json_encode(['status' => 'error', 'msg' => 'Ungültige Signaling-Anfrage.']);
            exit;
        } catch (\Exception $e) {
            // Interne Details nur ins Log, nicht in den Browser.
            error_log('WebRTCController::getSignal: ' . $e->getMessage());
            echo json_encode(['status' => 'error', 'msg' => 'Signaling-Fehler.']);
            exit;
        }
    }

    /**
     * Legt die Rollen für einen Call fest.
     *
     * Die Rolle gilt nur für diesen einen Call und wird nicht gespeichert.
     * Grundlage ist ausschließlich, was der Server ohnehin weiß: wer angerufen
     * hat und welchen Kontotyp die beiden haben. Der Client hat darauf keinen
     * Einfluss.
     *
     * Regel:
     *   1. Ist genau einer der beiden als Guide registriert, ist er der Guide.
     *   2. Sonst - beide oder keiner - ist der Angerufene der Guide. Das ist
     *      der Regelfall der Anwendung: Der Zuschauer sucht einen Standort und
     *      ruft den Guide an, der dort vor Ort ist.
     *
     * Beide Seiten rufen diese Funktion mit demselben Paar (Anrufer,
     * Angerufener) auf und bekommen deshalb zwingend zueinander passende
     * Rollen.
     *
     * @param int $callerId Wer angerufen hat
     * @param int $calleeId Wer angerufen wurde
     * @return array ['caller' => 'guide'|'viewer', 'callee' => 'guide'|'viewer']
     */
    public static function callRoles($callerId, $calleeId)
    {
        if (self::isGuideAccount($callerId) && !self::isGuideAccount($calleeId)) {
            return ['caller' => 'guide', 'callee' => 'viewer'];
        }
        return ['caller' => 'viewer', 'callee' => 'guide'];
    }

    /**
     * Rolle eines bestimmten Teilnehmers in diesem Call.
     *
     * @param int $callerId Wer angerufen hat
     * @param int $calleeId Wer angerufen wurde
     * @param int $userId   Für wen die Rolle gesucht ist
     * @return string|null 'guide', 'viewer' oder null, wenn der Nutzer nicht beteiligt ist
     */
    public static function roleForCall($callerId, $calleeId, $userId)
    {
        $roles = self::callRoles($callerId, $calleeId);
        if ((int)$userId === (int)$callerId) return $roles['caller'];
        if ((int)$userId === (int)$calleeId) return $roles['callee'];
        return null;
    }

    /**
     * Hängt an jedes ausgelieferte Offer die Rolle des Empfängers.
     *
     * Der Empfänger eines Offers ist immer der Angerufene, der Absender immer
     * der Anrufer - damit steht das Paar fest, ohne dass der Client etwas
     * dazu beitragen müsste.
     *
     * @param array $messages   Bereits gefilterte Signalnachrichten
     * @param int   $receiverId Empfänger, der gerade abfragt
     * @return array Nachrichten mit ergänztem Feld "role" bei Offers
     */
    public static function stampCallRoles($messages, $receiverId)
    {
        foreach ($messages as $index => $msg) {
            if (!isset($msg['type']) || $msg['type'] !== 'offer') continue;
            if (!isset($msg['sender_id'])) continue;
            $messages[$index]['role'] = self::roleForCall($msg['sender_id'], $receiverId, $receiverId);
        }
        return $messages;
    }

    /**
     * Ist dieses Konto als Guide registriert?
     *
     * Ein unbekannter oder nicht ladbarer Benutzer gilt als kein Guide. Die
     * Rollenvergabe fällt dadurch auf die zweite Regel zurück und bleibt
     * eindeutig, statt mit einer Ausnahme den Anruf zu verhindern.
     *
     * @param int $userId
     * @return bool
     */
    private static function isGuideAccount($userId)
    {
        $id = (int)$userId;
        if ($id < 1) return false;

        try {
            $user = new User($id);
            return (int)$user->getRoleId() === self::USERTYPE_GUIDE;
        } catch (\Exception $e) {
            error_log('WebRTCController::isGuideAccount: ' . $e->getMessage());
            return false;
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
