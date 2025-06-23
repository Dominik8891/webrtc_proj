<?php

namespace App\Model;

/**
 * Model-Klasse zur Verwaltung von WebRTC-Signalen (RTC) zwischen zwei Benutzern.
 */
class WebRTCHandler {

    private $id;
    private $sender_id;
    private $receiver_id;
    private $type;
    private $sdp;
    private $candidate;
    private $createt_at;

    /**
     * Konstruktor: Lädt ein RTC-Signal anhand der ID oder initialisiert ein leeres Objekt.
     * @param int|string $in_id
     * @throws \Exception wenn das Signal nicht gefunden wird
     */
    public function __construct(string|int $in_id = 0)
    {
        if($in_id > 0) {
            try {
                $query = "SELECT * FROM rtc_signal WHERE id = :id;";
                $stmt  = PdoConnect::$connection->prepare($query);
                $stmt  ->bindParam(':id', $in_id);
                $stmt  ->execute();

                $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                if(count($result) == 1) {
                    $this->id           = $result[0]['id'];
                    $this->sender_id    = $result[0]['sender_id'];
                    $this->receiver_id  = $result[0]['receiver_id'];
                    $this->type         = $result[0]['type'];
                    $this->sdp          = $result[0]['sdp'];
                    $this->candidate    = $result[0]['candidate'];
                    $this->createt_at   = $result[0]['createt_at'];
                } else {
                    throw new \Exception("RTC Signal mit ID {$in_id} nicht gefunden.");
                }
            } catch (\PDOException $e) {
                error_log("Fehler beim Laden des RTC Signals: " . $e->getMessage());
                throw new \Exception("Fehler beim Laden des RTC Signals.");
            }
        } else {
            $this->id = 0;
        }
    }

    /**
     * Erstellt ein neues RTC-Signal in der Datenbank.
     * @return bool Erfolg
     */
    public function create() {
        try {
            $query = "INSERT INTO rtc_signal ( sender_id, receiver_id, type,  sdp,  candidate, created_at)
                                    VALUES ( :sender,   :receiver,  :type, :sdp, :candidate,    NOW()  )";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt ->bindParam(':sender', $this->sender_id);
            $stmt ->bindParam(':receiver', $this->receiver_id);
            $stmt ->bindParam(':type', $this->type);
            $stmt ->bindParam(':sdp', $this->sdp);
            $stmt ->bindParam(':candidate', $this->candidate);
            $stmt ->execute();
            return true;
        } catch (\PDOException $e) {
            error_log("Fehler beim Erstellen eines RTC Signals: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Gibt alle Signale für einen Empfänger der letzten 15 Sekunden zurück.
     * @param int $in_receiver
     * @return array Array der Signale
     */
    public function getAllSignalsForReceiver($in_receiver) {
        try {
            $query = "SELECT * FROM rtc_signal
                      WHERE receiver_id = :receiver
                      AND created_at > NOW() - INTERVAL 15 SECOND
                      ORDER BY created_at ASC";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':receiver', $in_receiver);
            $stmt->execute();
            $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            return $messages;
        } catch (\PDOException $e) {
            error_log("Fehler beim Abrufen der RTC Signale: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Löscht alle Signale für einen bestimmten Empfänger.
     * @param int $in_receiver
     * @return bool Erfolg
     */
    public function deleteSignalsForReceiver($in_receiver) {
        try {
            $queryDel = "DELETE FROM rtc_signal WHERE receiver_id = :receiver";
            $stmt = PdoConnect::$connection->prepare($queryDel);
            $stmt ->bindParam(':receiver', $in_receiver);
            $stmt ->execute();
            return true;
        } catch (\PDOException $e) {
            error_log("Fehler beim Löschen von RTC Signalen: " . $e->getMessage());
            return false;
        }
    }

    // Setter-Methoden 
    public function setSender($in_sender)       { $this->sender_id = $in_sender; }
    public function setReceiver($in_receiver)   { $this->receiver_id = $in_receiver; }
    public function setType($in_type)           { $this->type = $in_type; }
    public function setSdp($in_sdp)             { $this->sdp = $in_sdp; }
    public function setCandidate($in_candidate) { $this->candidate = $in_candidate; }
}
