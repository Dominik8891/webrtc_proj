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
     *
     * ACHTUNG: Diese Methode ist für den Auslieferungspfad NICHT geeignet.
     * Sie löscht auch Signale, die zwischen SELECT und DELETE eingetroffen und
     * damit nie ausgeliefert worden sind (Befund F-1). Für das Polling wird
     * deshalb deleteSignalsByIds() verwendet. Die Methode bleibt für das
     * vollständige Aufräumen eines Empfängers erhalten (z. B. Call-Abbruch).
     *
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

    /**
     * Löscht genau die angegebenen Signale eines Empfängers.
     *
     * Das ist die Gegenmaßnahme zur Race Condition aus Befund F-1: Gelöscht
     * wird ausschließlich das, was der SELECT tatsächlich gelesen hat. Signale,
     * die danach eintreffen (bei Trickle-ICE im 1500-ms-Fenster der Regelfall),
     * bleiben liegen und werden beim nächsten Poll ausgeliefert.
     *
     * Die receiver_id bleibt in der WHERE-Klausel, damit eine fremde ID in der
     * Liste keine fremden Signale löschen kann.
     *
     * @param int   $in_receiver Empfänger, dem die Signale gehören
     * @param array $in_ids      IDs der gelesenen Signale
     * @return bool Erfolg
     */
    public function deleteSignalsByIds($in_receiver, array $in_ids) {
        // Nur echte Ganzzahlen zulassen; leere Liste = nichts zu tun.
        $ids = [];
        foreach ($in_ids as $id) {
            if (is_numeric($id) && (int)$id > 0) {
                $ids[] = (int)$id;
            }
        }
        if (empty($ids)) {
            return true;
        }

        try {
            // IDs sind hier bereits auf int gecastet und damit sicher; ein
            // IN-Platzhalter je ID wäre gleichwertig, aber unnötig umständlich.
            $idList = implode(',', $ids);
            $queryDel = "DELETE FROM rtc_signal
                         WHERE receiver_id = :receiver
                         AND id IN ($idList)";
            $stmt = PdoConnect::$connection->prepare($queryDel);
            $stmt ->bindParam(':receiver', $in_receiver);
            $stmt ->execute();
            return true;
        } catch (\PDOException $e) {
            error_log("Fehler beim Löschen ausgelieferter RTC Signale: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Räumt Signale weg, die zu alt sind, um noch ausgeliefert zu werden.
     *
     * getAllSignalsForReceiver() liest nur die letzten 15 Sekunden. Ohne diesen
     * Aufruf würden ältere Zeilen nach der Umstellung auf deleteSignalsByIds()
     * dauerhaft in der Tabelle liegen bleiben.
     *
     * @param int $in_receiver
     * @param int $in_max_age_seconds Alter in Sekunden, ab dem gelöscht wird
     * @return bool Erfolg
     */
    public function deleteExpiredSignalsForReceiver($in_receiver, $in_max_age_seconds = 60) {
        $maxAge = (int)$in_max_age_seconds;
        if ($maxAge < 15) {
            // Nie unterhalb des Lesefensters löschen - sonst verschwinden
            // Signale, die noch zugestellt werden müssten.
            $maxAge = 15;
        }

        try {
            $queryDel = "DELETE FROM rtc_signal
                         WHERE receiver_id = :receiver
                         AND created_at < NOW() - INTERVAL $maxAge SECOND";
            $stmt = PdoConnect::$connection->prepare($queryDel);
            $stmt ->bindParam(':receiver', $in_receiver);
            $stmt ->execute();
            return true;
        } catch (\PDOException $e) {
            error_log("Fehler beim Aufräumen abgelaufener RTC Signale: " . $e->getMessage());
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
