<?php

/**
 * Klasse zur Verwaltung von Chat-Nachrichten in einem Chatprotokoll.
 */
class ChatLog
{
    private $id;
    private $user_id;
    private $msg;
    private $msg_type;
    private $timestamp;
    private $deleted;

    /**
     * Lädt einen Chatlog-Datensatz anhand der ID oder erzeugt ein leeres Objekt.
     */
    public function __construct($in_log_id = 0)
    {
        if ($in_log_id > 0) {
            $stmt = PdoConnect::$connection->prepare("SELECT * FROM chatlog WHERE log_id = :log_id;");
            $stmt->bindParam(':log_id', $in_log_id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $this->id        = $result['log_id'];
                $this->user_id   = $result['user_id'];
                $this->msg       = $result['msg'];
                $this->msg_type  = $result['msg_type'];
                $this->timestamp = $result['timestamp'];
                $this->deleted   = $result['deleted'];
            } else {
                die("Fehler: Keinen Datensatz trotz ID gefunden!");
            }
        } else {
            $this->id = 0;
            $this->deleted = 0;
        }
    }

    /**
     * Erstellt einen neuen Log-Eintrag.
     */
    private function create()
    {
        if ($this->id > 0) return;
        $stmt = PdoConnect::$connection->prepare(
            "INSERT INTO chatlog ( user_id,  msg,  msg_type) 
                          VALUES (:user_id, :msg, :msg_type);"
        );
        $stmt->bindParam(':user_id', $this->user_id, PDO::PARAM_INT);
        $stmt->bindParam(':msg', $this->msg);
        $stmt->bindParam(':msg_type', $this->msg_type);
        $stmt->execute();
    }

    /**
     * Aktualisiert einen Log-Eintrag (nur deleted-Flag!).
     */
    private function update()
    {
        if ($this->id < 1) return;
        $stmt = PdoConnect::$connection->prepare(
            "UPDATE chatlog SET deleted = :deleted WHERE log_id = :log_id;"
        );
        $stmt->bindParam(':deleted', $this->deleted, PDO::PARAM_INT);
        $stmt->bindParam(':log_id', $this->id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Speichert (erstellt oder aktualisiert) einen Log-Eintrag.
     */
    public function save()
    {
        if ($this->id > 0) {
            $this->update();
        } else {
            $this->create();
        }
    }

    /**
     * Löscht eine Nachricht (setzt das `deleted`-Flag auf 1).
     */
    public function del_it()
    {
        if ($this->id < 1) return;
        $this->deleted = 1;
        $this->update();
    }

    /**
     * Gibt den Nachrichtenverlauf (Chat-Historie) als Array zurück.
     * @param int $in_limit Anzahl der Nachrichten (default 5)
     * @return array|null
     */
    public function get_history_as_array($in_limit = 5)
    {
        $in_limit = intval($in_limit);

        $query = "WITH last_user_msg AS (
                    SELECT * FROM chatlog
                    WHERE msg_type = 'user' AND user_id = :user_id AND deleted = 0
                    ORDER BY timestamp DESC LIMIT $in_limit
                  ),
                  first_user_timestamp AS (
                    SELECT MIN(timestamp) AS min_user_timestamp FROM last_user_msg
                  )
                  SELECT * FROM chatlog
                  WHERE msg_type = 'bot' AND user_id = :user_id AND deleted = 0
                    AND timestamp >= (SELECT min_user_timestamp FROM first_user_timestamp)
                  UNION ALL
                  SELECT * FROM last_user_msg
                  WHERE user_id = :user_id AND deleted = 0
                  ORDER BY timestamp ASC;";

        $stmt = PdoConnect::$connection->prepare($query);
        $stmt->bindParam(':user_id', $this->user_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($result) {
            $tmp_array = [];
            foreach ($result as $row) {
                $tmp_arr = [
                    $row['log_id'],
                    $row['msg_type'],
                    $row['msg'],
                    $row['timestamp']
                ];
                $tmp_array[] = $tmp_arr;
            }
            return $tmp_array;
        }
        return null;
    }

    /**
     * Gibt die letzte Bot-Antwort zurück.
     * @return string
     */
    public function get_last_answer()
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT msg FROM chatlog WHERE user_id = :user_id AND msg_type = 'bot' ORDER BY timestamp DESC LIMIT 1;"
        );
        $stmt->bindParam(':user_id', $this->user_id, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['msg'] : "Fehler!!!";
    }

    // Setter-Methoden
    public function set_user_id($in_user_id)   { $this->user_id = $in_user_id; }
    public function set_msg($in_msg)           { $this->msg = $in_msg; }
    public function set_msg_type($in_msg_type) { $this->msg_type = $in_msg_type; }

    // Getter-Methoden
    public function get_msg()        { return $this->msg; }
    public function get_msg_type()   { return $this->msg_type; }
    public function get_timestamp()  { return $this->timestamp; }
}
