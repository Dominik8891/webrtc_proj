<?php

/**
 * Klasse zur Verwaltung von Chat-Nachrichten in einem Chatprotokoll.
 * Diese Klasse bietet Funktionen zum Erstellen, Aktualisieren, Abrufen und Löschen von Chat-Nachrichten.
 */
class ChatLog
{
    // Private Attribute für die Eigenschaften des Chatlogs
    private $id;
    private $user_id;
    private $msg;
    private $msg_type;
    private $timestamp;
    private $deleted;

    /**
     * Konstruktor der ChatLog-Klasse.
     * Lädt ein Chatlog aus der Datenbank, wenn eine Log-ID übergeben wird.
     * Falls keine ID übergeben wird, wird ein neues leeres Chatlog erstellt.
     *
     * @param int $in_log_id Log-ID (optional)
     */
    public function __construct($in_log_id = 0)
    {
        if($in_log_id > 0)
        {
            // Lädt den Chatlog-Datensatz aus der Datenbank basierend auf der Log-ID
            $query = "SELECT * 
                      FROM chatlog
                      WHERE log_id = :log_id;";
            $stmt  = PdoConnect::$connection->prepare($query);
            $stmt  ->bindParam(':log_id', $in_log_id);
            $stmt  ->execute();

            $result = $stmt->fetchAll();
            // Falls genau ein Datensatz gefunden wird, werden die Attribute gesetzt
            if(count($result) == 1)
            {
                $this->id        = $result[0]['log_id'];
                $this->user_id   = $result[0]['user_id'];
                $this->msg       = $result[0]['msg'];
                $this->msg_type  = $result[0]['msg_type'];
                $this->timestamp = $result[0]['timestamp'];
                $this->deleted   = $result[0]['deleted'];
            }
            elseif(count($stmt->fetchAll()) >= 2)
            {
                // Fehlerbehandlung, wenn mehrere Datensätze gefunden werden
                die("Fehler: Mehr als einen Datensatz gefunden!");
            }
            else
            {
                // Fehlerbehandlung, wenn kein Datensatz gefunden wird
                die("Fehler: Keinen Datensatz trotz ID gefunden!");
            }
        }
        else
        {
            // Initialisiert ein neues Chatlog (zum späteren Speichern)
            $this->id = 0;
            $this->deleted = 0;
        }
    }

    /**
     * Erstellt eine neue Nachricht in der Datenbank.
     * Diese Methode wird nur aufgerufen, wenn das Chatlog noch keine ID hat (neue Nachricht).
     */
    private function create()
    {
        if($this->id > 0)
        {
            // Fehler, wenn versucht wird, eine Nachricht mit einer bestehenden ID zu erstellen
            echo "Fehler: Create mit ID > 0 versucht!";
            return; 
        }

        // Fügt eine neue Nachricht in die Datenbank ein
        $query = "INSERT INTO chatlog ( user_id,  msg,  msg_type)
                                VALUES(:user_id, :msg, :msg_type);";
        $stmt  = PdoConnect::$connection->prepare($query);
        $stmt  ->bindParam(':user_id', $this->user_id);
        $stmt  ->bindParam(':msg', $this->msg);
        $stmt  ->bindParam(':msg_type', $this->msg_type);

        $stmt  ->execute();
    }

    /**
     * Aktualisiert eine bestehende Nachricht in der Datenbank.
     * Diese Methode wird nur aufgerufen, wenn das Chatlog bereits eine ID hat (bestehende Nachricht).
     */
    private function update()
    {
        if($this->id < 1)
        {
            // Fehler, wenn versucht wird, eine Nachricht ohne ID zu aktualisieren
            echo "Fehler: Update mit ID 0 versucht!";
            return;
        }

        // Aktualisiert den Datensatz in der Datenbank (z.B. das `deleted`-Flag)
        $query = "UPDATE chatlog SET deleted = :deleted;";
        $stmt  = PdoConnect::$connection->prepare($query);
        $stmt  ->bindParam(':deleted', $this->deleted);
        $stmt  ->execute();
    }

    /**
     * Speichert das Chatlog.
     * Wenn es eine bestehende ID hat, wird es aktualisiert.
     * Andernfalls wird eine neue Nachricht erstellt.
     */
    public function save()
    {
        if($this->id > 0)
        {
            // Aktualisiere das bestehende Chatlog
            $this->update();
        }
        else
        {
            // Erstelle ein neues Chatlog
            $this->create();
        }
    }

    /**
     * Löscht eine Nachricht (setzt das `deleted`-Flag auf 1).
     */
    public function del_it()
    {
        if($this->id < 1)
        {
            // Fehler, wenn versucht wird, eine Nachricht ohne ID zu löschen
            echo "Fehler: Delete mit ID = 0 versucht!";
            return;
        }

        // Setzt das `deleted`-Flag auf 1 und aktualisiert den Datensatz
        $this->deleted = 1;
        $this->update();
    }

    /**
     * Gibt den Nachrichtenverlauf (Chat-Historie) als Array zurück.
     * Begrenzt die Anzahl der zurückgegebenen Nachrichten auf $in_limit.
     *
     * @param int $in_limit Anzahl der zurückgegebenen Nachrichten (optional, Standardwert 5)
     * @return array Enthält den Nachrichtenverlauf
     */
    public function get_history_as_array($in_limit = 5)
    {
        $in_limit = intval($in_limit); // Sicherstellen, dass das Limit eine Ganzzahl ist

        // SQL-Abfrage zur Auswahl der letzten Benutzer- und Bot-Nachrichten
        $query = "WITH last_user_msg AS (
                    SELECT *
                    FROM chatlog
                    WHERE msg_type = 'user' AND user_id = :user_id AND deleted = 0
                    ORDER BY timestamp DESC
                    LIMIT $in_limit
                  ),
                  first_user_timestamp AS (
                    SELECT MIN(timestamp) AS min_user_timestamp
                    FROM last_user_msg
                  )
                  
                  SELECT *
                  FROM chatlog
                  WHERE msg_type = 'bot'
                  AND  user_id = :user_id
                  AND deleted = 0
                  AND timestamp >= (SELECT min_user_timestamp FROM first_user_timestamp)
                  
                  UNION ALL
                  
                  SELECT * 
                  FROM last_user_msg
                  WHERE user_id = :user_id AND deleted = 0
                  ORDER BY timestamp ASC;";
        $stmt  = PdoConnect::$connection->prepare($query);
        $stmt  ->bindParam(':user_id', $this->user_id);
        $stmt  ->execute();

        $result = $stmt->fetchAll();
        if($result != null)
        {
            $tmp_array = [];
            foreach($result as $row)
            {
                $tmp_arr = [];
                array_push($tmp_arr, $row['log_id']);
                array_push($tmp_arr, $row['msg_type']);
                array_push($tmp_arr, $row['msg']);
                array_push($tmp_arr, $row['timestamp']);
                array_push($tmp_array, $tmp_arr);
            }
            return $tmp_array;
        }
    }

    /**
     * Gibt die letzte Antwort des Bots an den Benutzer zurück.
     *
     * @return string Die letzte Bot-Antwort oder "Fehler!!!" bei Problemen.
     */
    public function get_last_answer()
    {
        // SQL-Abfrage zur Auswahl der letzten Bot-Nachricht
        $query = "SELECT msg FROM chatlog WHERE user_id = :user_id AND msg_type = 'bot' ORDER BY timestamp DESC LIMIT 1;";
        $stmt  = PdoConnect::$connection->prepare($query);
        $stmt  ->bindParam(':user_id', $this->user_id);
        $stmt  ->execute();
        
        $result = $stmt->fetch();
        if($result)
        {
            return $result['msg'];
        }
        else 
        {
            return "Fehler!!!";
        }
    }

    // Setter-Methoden

    /**
     * Setzt die Benutzer-ID.
     *
     * @param int $in_user_id Benutzer-ID
     */
    public function set_user_id($in_user_id)
    {
        $this->user_id = $in_user_id;
    }

    /**
     * Setzt die Nachricht.
     *
     * @param string $in_msg Nachricht
     */
    public function set_msg($in_msg)
    {
        $this->msg = $in_msg;
    }

    /**
     * Setzt den Nachrichtentyp (z.B. 'user' oder 'bot').
     *
     * @param string $in_msg_type Nachrichtentyp
     */
    public function set_msg_type($in_msg_type)
    {
        $this->msg_type = $in_msg_type;
    }

    // Getter-Methoden

    /**
     * Gibt die Nachricht zurück.
     *
     * @return string Nachricht
     */
    public function get_msg()
    {
        return $this->msg;
    }

    /**
     * Gibt den Nachrichtentyp zurück.
     *
     * @return string Nachrichtentyp
     */
    public function get_msg_type()
    {
        return $this->msg_type;
    }

    /**
     * Gibt den Zeitstempel der Nachricht zurück.
     *
     * @return string Zeitstempel der Nachricht
     */
    public function get_timestamp()
    {
        return $this->timestamp;
    }
}