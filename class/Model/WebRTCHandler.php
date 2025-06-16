<?php

namespace App\Model;

class WebRTCHandler {

    private $id;
    private $sender_id;
    private $receiver_id;
    private $type;
    private $sdp;
    private $candidate;
    private $createt_at;

    public function __construct(string|int $in_id = 0)
    {
        // Wenn eine gültige Benutzer-ID übergeben wird, den Benutzer aus der Datenbank laden
        if($in_id > 0)
        {
            $query = "SELECT * FROM rtc_signal 
                      WHERE id = :id;";
            $stmt  = PdoConnect::$connection->prepare($query);
            $stmt  ->bindParam(':id', $in_id);
            $stmt  ->execute();

            $result = $stmt->fetchAll();

            // Überprüfen, ob ein Ergebnis vorliegt
            if(count($result) == 1)
            {
                // Benutzerinformationen aus der Datenbank in die Klassenattribute laden
                $this->id           = $result[0]['id'];
                $this->sender_id    = $result[0]['sender_id'];
                $this->receiver_id  = $result[0]['receiver_id'];
                $this->type         = $result[0]['type'];
                $this->sdp          = $result[0]['sdp'];
                $this->candidate    = $result[0]['candidate'];
                $this->createt_at   = $result[0]['createt_at'];
            }
            else
            {
                throw new \Exception("RTC Signal mit ID {$in_id} nicht gefunden.");
            }
        }
        else
        {
            // Neuer Benutzer wird angelegt
            $this->id = 0;
        }
    }

    public function create() {
        $query = "INSERT INTO rtc_signal ( sender_id, receiver_id, type,  sdp,  candidate, created_at)
                                  VALUES ( :sender,   :receiver,  :type, :sdp, :candidate,    NOW()  )";
        $stmt = PdoConnect::$connection->prepare($query);
        $stmt ->bindParam(':sender', $this->sender_id);
        $stmt ->bindParam(':receiver', $this->receiver_id);
        $stmt ->bindParam(':type', $this->type);
        $stmt ->bindParam(':sdp', $this->sdp);
        $stmt ->bindParam(':candidate', $this->candidate);
        $stmt ->execute();
    }

    public function getAllSignalsForReceiver($in_receiver) {
        $query = "SELECT * FROM rtc_signal WHERE receiver_id = :receiver ORDER BY created_at ASC";
        $stmt = PdoConnect::$connection->prepare($query);
        $stmt->bindParam(':receiver', $in_receiver);
        $stmt->execute();

        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return $messages;
    }

    public function deleteSignalsForReceiver($in_receiver) {
        $queryDel = "DELETE FROM rtc_signal WHERE receiver_id = :receiver";
        $stmt = PdoConnect::$connection->prepare($queryDel);
        $stmt ->bindParam(':receiver', $in_receiver);
        $stmt ->execute();
    }

    public function setSender($in_sender) {
        $this->sender_id = $in_sender;
    }

    public function setReceiver($in_receiver) {
        $this->receiver_id = $in_receiver;
    }

    public function setType($in_type) {
        $this->type = $in_type;
    }

    public function setSdp($in_sdp) {
        $this->sdp = $in_sdp;
    }

    public function setCandidate($in_candidate) {
        $this->candidate = $in_candidate;
    }
}




