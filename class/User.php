<?php

/**
 * Klasse zur Verwaltung der Benutzerinformationen und Interaktionen mit der Datenbank.
 */
class User
{
    // Private Attribute für Benutzerinformationen
    private $id;
    private $status;
    private $last_aktive;
    private $username;
    private $email;
    private $pwd;
    private $type_id;
    private $deleted;

    /**
     * Konstruktor, der entweder einen bestehenden Benutzer aus der Datenbank lädt oder einen neuen Benutzer erstellt.
     *
     * @param string|int $in_id Benutzer-ID
     */
    public function __construct(string|int $in_id = 0)
    {
        // Wenn eine gültige Benutzer-ID übergeben wird, den Benutzer aus der Datenbank laden
        if($in_id > 0)
        {
            $query = "SELECT * FROM user 
                      WHERE id = :user_id;";
            $stmt  = PdoConnect::$connection->prepare($query);
            $stmt  ->bindParam(':user_id', $in_id);
            $stmt  ->execute();

            $result = $stmt->fetchAll();

            // Überprüfen, ob ein Ergebnis vorliegt
            if(count($result) == 1)
            {
                // Benutzerinformationen aus der Datenbank in die Klassenattribute laden
                $this->id           = $result[0]['id'];
                $this->username     = $result[0]['username'];
                $this->email        = $result[0]['email'];
                $this->pwd          = $result[0]['pwd'];
                $this->type_id      = $result[0]['type_id'];
                $this->deleted      = $result[0]['deleted'];
            }
            else
            {
                throw new Exception("Benutzer mit ID {$in_id} nicht gefunden.");
            }
        }
        else
        {
            // Neuer Benutzer wird angelegt
            $this->id = 0;
            $this->deleted = 0;
        }
    }

    /**
     * Erstellen eines neuen Benutzers in der Datenbank.
     */
    private function create()
{
    if ($this->id > 0) {
        echo "Fehler: Create mit ID > 0 versucht!";
        return;
    }

    // Prepare und Ausführen des INSERT-Statements
    $query = "INSERT INTO user (username, email, pwd, type_id) VALUES (:username, :email, :pwd, 3)";
    $stmt = PdoConnect::$connection->prepare($query);
    $stmt->bindParam(":username", $this->username);
    $stmt->bindParam(":email", $this->email);
    $stmt->bindParam(":pwd", $this->pwd);

    try {
        $stmt->execute();
    } catch (PDOException $e) {
        // Fehlerbehandlung, wenn das Einfügen in die Datenbank fehlschlägt
        echo "Fehler beim Erstellen des Benutzers: " . $e->getMessage();
        return;
    }

    // Benutzer erfolgreich eingefügt, jetzt den Benutzer abfragen
    $query = "SELECT * FROM user WHERE username = :username";
    $stmt = PdoConnect::$connection->prepare($query);
    $stmt->bindParam(':username', $this->username); // Verwende $this->username
    $stmt->execute();

    $result = $stmt->fetchAll();
}


    /**
     * Aktualisieren eines bestehenden Benutzers in der Datenbank.
     */
    private function update()
    {
        if($this->id < 1)
        {
            echo "Fehler: Update mit ID 0 versucht!";
            return;
        }

        $query = "UPDATE user  SET
                            username    = :username,
                            email       = :email,
                            pwd         = :pwd,
                            type_id     = :type_id,
                            deleted     = :deleted
                      WHERE id          = :user_id;";

        $stmt = PdoConnect::$connection->prepare($query);
        $stmt ->bindParam(":user_id"    , $this->id);
        $stmt ->bindParam(":username"   , $this->username);
        $stmt ->bindParam(":email"      , $this->email);
        $stmt ->bindParam(":pwd"        , $this->pwd);
        $stmt ->bindParam(":type_id"    , $this->type_id);
        $stmt ->bindParam(":deleted"    , $this->deleted);

        $stmt ->execute();
    }

    /**
     * Speichern eines Benutzers. Wenn die ID 0 ist, wird der Benutzer erstellt, sonst aktualisiert.
     */
    public function save()
    {
        if($this->id > 0)
        {
            $this->update();
        }
        else
        {
            $this->create();
        }
    }

    /**
     * Markiert den Benutzer als gelöscht.
     */
    public function del_it()
    {
        if($this->id < 1)
        {
            echo "Fehler: Delete mit ID = 0 versucht!";
            return;
        }
        $this->deleted = 1;

        $this->update();
    }

    /**
     * Versucht einen Benutzer zu authentifizieren.
     *
     * @param string $in_username Benutzername
     * @param string $in_pwd Passwort
     * @return mixed Benutzer-ID oder false bei Fehler
     */
    public function login($in_username, $in_pwd)
    {
        $query = "SELECT * FROM user 
                            WHERE username        = :username AND
                                  deleted         = 0";
        
        $stmt = PdoConnect::$connection->prepare($query);
        $stmt ->bindParam(":username", $in_username);

        $stmt ->execute();

        $result = $stmt->fetchAll();
        
        if(count($result) != 1)
        {
            return false;
        }
      

        $stored_hashed_pwd = $result[0]['pwd'];

        $config = include 'config/config.php';
        $pepper = $config['pepper'];
        $pwd_peppered = hash_hmac("sha256", $in_pwd, $pepper);

        if(password_verify($pwd_peppered, $stored_hashed_pwd))
        {
            $update_query = "UPDATE user SET updated_at = CURRENT_TIMESTAMP WHERE id = :id;";
            $update_stmt  = PdoConnect::$connection->prepare($update_query);
            $update_stmt  ->bindParam(':id', $result[0]['id']);
            $update_stmt  ->execute();
        
            $this->id       = $result[0]['id'];
            $this->setUserStatus('online');
            return $this->id;
        }
        else
        {
            
            return false;
        }   
        
    }

    public function setUserStatus($status)
    {
        $update_query = "UPDATE user SET user_status = :status WHERE id = :id;";
        $update_stmt  = PdoConnect::$connection->prepare($update_query);
        $update_stmt  ->bindParam(':status', $status);
        $update_stmt  ->bindParam(':id', $this->id);
        $update_stmt  ->execute();
    }

    public function getUserStatus($userId)
    {
        $stmt = PdoConnect::$connection->prepare("SELECT user_status FROM user WHERE id = ?");
        $stmt ->execute([$userId]);
        $result = $stmt->fetchAll();
        return $result[0]['user_status'];
    }


    // Benutzer nach ID suchen
    public function getUserById($userId) {
        $stmt = PdoConnect::$connection->prepare("SELECT * FROM user WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch();
    }

    // Benutzerdaten aktualisieren (z.B. Status)
    public function updateUserStatus($userId, $status) {
        $stmt = PdoConnect::$connection->prepare("UPDATE user SET user_status = ? WHERE id = ?");
        $stmt->execute([$status, $userId]);
    }


    /**
     * Überprüft, ob ein Benutzername bereits in der Datenbank existiert.
     *
     * @param string $in_username Benutzername
     * @return bool true, wenn der Benutzername existiert, sonst false
     */
    public function check_if_username_exists($in_username)
    {
        $query = "SELECT * FROM user WHERE username = :username";
        $stmt  = PdoConnect::$connection->prepare($query);
        $stmt  ->bindParam(":username", $in_username);

        $stmt->execute();
        $result = $stmt->fetch();

        // Gibt true zurück, wenn ein Datensatz gefunden wurde, sonst false
        if($result)
        {
            return true;
        }
        return false;
    }

    /**
     * Überprüft, ob eine E-Mail-Adresse bereits in der Datenbank existiert.
     *
     * @param string $in_email E-Mail-Adresse
     * @return bool true, wenn die E-Mail existiert, sonst false
     */
    public function check_if_email_exists($in_email)
    {
        $query = "SELECT * FROM user WHERE email = :email";
        $stmt  = PdoConnect::$connection->prepare($query);
        $stmt  ->bindParam(":email", $in_email);

        $stmt->execute();
        $result = $stmt->fetch();

        // Gibt true zurück, wenn ein Datensatz gefunden wurde, sonst false
        if($result)
        {
            return true;
        }
        return false;
    }

    /**
     * Gibt die Benutzerinformationen als Array zurück.
     *
     * @return array Enthält Benutzerstatus, Benutzertyp, Benutzername und E-Mail
     */
    public function get_user_info_as_array()
    {
        // Überprüfung, ob der Benutzer existiert
        if($this->id < 0)
        {
            die("Fehler: keine User Info vorhanden!");
        }

        // Benutzerinformationen in ein Array laden und zurückgeben
        $tmp_array = [];
        array_push($tmp_array, $this->status);
        array_push($tmp_array, $this->username);
        array_push($tmp_array, $this->email);
        return $tmp_array;
    }

    /**
     * Gibt eine Liste aller Benutzer-IDs zurück, die nicht gelöscht wurden.
     *
     * @return array Enthält Benutzer-IDs
     */
    public function getAll()
    {
        $temp_array = [];
        $query = "SELECT id FROM user WHERE deleted = 0;";

        // Füge jede gefundene Benutzer-ID zum Array hinzu
        foreach(PdoConnect::$connection->query($query) as $row)
        {
            array_push($temp_array, $row[0]);
        }
        return $temp_array;
    }

    public function get_usertype() {
        if($this->id > 0) {
            $query = "SELECT * FROM usertype WHERE id = :type_id";
            $stmt  = PdoConnect::$connection->prepare($query);
            $stmt  ->bindParam(":type_id", $this->type_id);
            $stmt  ->execute();
            $result = $stmt->fetchAll();
            if($result) {
                return $result[0]['name'];
            } else {
                return false;
            }
        } else {
            return 'kein gültiger user';
        }
    }

    public function set_usertype($in_usertype) {
        $id = 0;
        if(!is_numeric($in_usertype)) {
            $query = "SELECT * FROM usertype WHERE `name` = :usertype";
            $stmt  = PdoConnect::$connection->prepare($query);
            $stmt  ->bindParam(":usertype", $in_usertype);
            $stmt  ->execute();
            $result = $stmt->fetchAll();
            if($result) {
                $id = $result[0]['id'];
            }
        } else {
            $id = $in_usertype;
        }
        if($id > 0) {
            $this->type_id = $id;
        } else {
            return false;
        }
        
    }

    // Setter-Methoden

    /**
     * Setzt die Benutzer-ID.
     *
     * @param int $in_id Benutzer-ID
     */
    public function set_id($in_id)
    {
        $this->id = $in_id;
    }

    /**
     * Setzt den Benutzerstatus.
     *
     * @param int $in_status Benutzerstatus
     */
    public function set_status($in_status)
    {
        $this->status = $in_status;
    }




    /**
     * Setzt den Benutzernamen.
     *
     * @param string $in_username Benutzername
     */
    public function set_username($in_username)
    {
        $this->username = $in_username;
    }

    /**
     * Setzt die E-Mail-Adresse.
     *
     * @param string $in_email E-Mail-Adresse
     */
    public function set_email($in_email)
    {
        $this->email = $in_email;
    }

    /**
     * Setzt das Passwort (Hash).
     *
     * @param string $in_pwd Passwort-Hash
     */
    public function set_pwd($in_pwd)
    {
        $this->pwd = $in_pwd;
    }

    // Getter-Methoden

    /**
     * Gibt die Benutzer-ID zurück.
     *
     * @return int Benutzer-ID
     */
    public function get_id()
    {
        return $this->id;
    }

    /**
     * Gibt den Benutzerstatus zurück.
     *
     * @return int Benutzerstatus
     */
    public function get_status()
    {
        return $this->status;
    }



    /**
     * Gibt den Benutzernamen zurück.
     *
     * @return string Benutzername
     */
    public function get_username()
    {
        return $this->username;
    }

    /**
     * Gibt die E-Mail-Adresse zurück.
     *
     * @return string E-Mail-Adresse
     */
    public function get_email()
    {
        return $this->email;
    }

    /**
     * Gibt das Passwort (Hash) zurück.
     *
     * @return string Passwort-Hash
     */
    public function get_pwd()
    {
        return $this->pwd;
    }
}