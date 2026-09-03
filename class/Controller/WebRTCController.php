<?php
namespace App\Controller;

use App\Model\User;
use App\Model\WebRTCHandler;
use App\Helper\Auth;
use App\Helper\Request;
use App\Helper\Role;

/**
 * WebRTCController – Steuert das Signaling für WebRTC-Verbindungen.
 * Übernimmt die Annahme und Verteilung von Offer/Answer/Candidate zwischen Nutzern.
 */
class WebRTCController
{
    /**
     * usertype.id des Guides.
     *
     * Der Wert steht jetzt in App\Helper\Role, der zentralen Stelle fuer
     * Rollen. Die Konstante bleibt als Name erhalten, damit die Aufrufe im
     * Signaling lesbar bleiben. Verglichen wird weiterhin die ID und nicht
     * der Name: Der Name kommt als 'Guide' aus der Datenbank, und genau
     * dieser Vergleich gegen kleingeschriebene Literale ist an anderer Stelle
     * schon schiefgegangen (Befunde F-5/F-6 der Bestandsaufnahme).
     */
    public const USERTYPE_GUIDE = Role::GUIDE;

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
                    $sender    = Auth::userId();
                    $type      = $data['type'];
                    $target    = $data['target'];
                    $sdp       = $data['sdp'] ?? null;
                    $candidate = isset($data['candidate']) ?
                        (is_array($data['candidate']) ? json_encode($data['candidate']) : $data['candidate'])
                        : null;

                    // DER ANRUF WIRD HIER ZUGELASSEN ODER GAR NICHT.
                    //
                    // Ein Offer erreicht den Angerufenen nur, wenn er als
                    // Guide registriert ist. Frueher wurde jedes Offer
                    // gespeichert und der Empfaenger dabei zum Guide erklaert
                    // - also durfte ein Anruf ueber eine Rolle entscheiden,
                    // der der Betroffene nie zugestimmt hatte. Abgewiesen
                    // wird VOR dem Speichern: Ein liegengebliebenes Offer
                    // wuerde beim naechsten Poll trotzdem klingeln.
                    //
                    // Nur 'offer' wird geprueft. Answer, Kandidaten, Hangup
                    // und die Restart-Nachrichten gehoeren zu einem Call, der
                    // diese Pruefung bereits bestanden hat.
                    if ($type === 'offer' && !self::callAllowed($sender, $target)) {
                        error_log('WebRTCController: Anruf abgewiesen, Benutzer #'
                            . (int)$target . ' ist kein Guide (Anrufer #' . (int)$sender . ').');
                        echo json_encode([
                            'status' => 'error',
                            'msg'    => 'Dieser Benutzer bietet keine Führungen an und kann '
                                      . 'deshalb nicht angerufen werden.',
                        ]);
                        exit;
                    }

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
            if (Auth::isLoggedIn()) {
                $receiver = Auth::userId();
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
     * ES GIBT NUR EINE REGEL: Der Angerufene muss als Guide registriert sein.
     * Dann ist er der Guide und der Anrufer der Zuschauer.
     *
     * Vorher fiel die Vergabe auf "im Zweifel ist der Angerufene der Guide"
     * zurück, wenn keiner der beiden ein Guide war. Damit genügte ein Anruf,
     * um ein beliebiges Konto zum Guide zu erklären - ohne dass der
     * Betroffene der Rolle je zugestimmt hätte, und mit einem Steuerkreuz auf
     * der Gegenseite, das ihn herumschickt. Die Guide-Rolle ist eine
     * ausdrückliche Entscheidung (App\Model\GuideRole); ein Anruf ist keine.
     *
     * Wer kein Guide ist, wird deshalb nicht zum Guide erklärt - der Anruf
     * kommt gar nicht erst zustande (siehe getSignal, Zweig 'offer').
     *
     * Beide Seiten rufen diese Funktion mit demselben Paar (Anrufer,
     * Angerufener) auf und bekommen deshalb zwingend zueinander passende
     * Rollen.
     *
     * @param int $callerId Wer angerufen hat
     * @param int $calleeId Wer angerufen wurde
     * @return array|null ['caller' => 'viewer', 'callee' => 'guide'] oder null,
     *                    wenn der Angerufene kein Guide ist
     */
    public static function callRoles($callerId, $calleeId)
    {
        if (!self::isGuideAccount($calleeId)) return null;

        return ['caller' => 'viewer', 'callee' => 'guide'];
    }

    /**
     * Darf dieser Anruf zustande kommen?
     *
     * Genau dann, wenn sich für das Paar Rollen vergeben lassen - also wenn
     * der Angerufene ein Guide ist. Die Frage steht bewusst neben callRoles()
     * und nicht daneben nachgebaut: Es gibt eine Bedingung, und sie steht an
     * einer Stelle.
     *
     * @param int $callerId Wer angerufen hat
     * @param int $calleeId Wer angerufen wurde
     * @return bool
     */
    public static function callAllowed($callerId, $calleeId)
    {
        return self::callRoles($callerId, $calleeId) !== null;
    }

    /**
     * Rolle eines bestimmten Teilnehmers in diesem Call.
     *
     * @param int $callerId Wer angerufen hat
     * @param int $calleeId Wer angerufen wurde
     * @param int $userId   Für wen die Rolle gesucht ist
     * @return string|null 'guide', 'viewer' oder null - wenn der Nutzer nicht
     *                     beteiligt ist ODER der Anruf gar nicht zulässig war
     */
    public static function roleForCall($callerId, $calleeId, $userId)
    {
        $roles = self::callRoles($callerId, $calleeId);
        if ($roles === null) return null;
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
     * Bleibt das Feld null, hat der Empfänger die Guide-Rolle zwischen dem
     * Anruf und diesem Abruf zurückgegeben. Dann steuert in diesem Call
     * niemand - der Client wertet ein fehlendes "role" als unbekannt aus.
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
            return Role::isGuide($user->getRoleId());
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
