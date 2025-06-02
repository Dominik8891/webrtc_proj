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
     * Konstruktor: Lädt existierenden User oder legt neuen an.
     */
    public function __construct($in_id = 0)
    {
        if ($in_id > 0) {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT * FROM user WHERE id = :user_id;"
            );
            $stmt->bindParam(':user_id', $in_id);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                $this->id        = $result['id'];
                $this->username  = $result['username'];
                $this->email     = $result['email'];
                $this->pwd       = $result['pwd'];
                $this->type_id   = $result['type_id'];
                $this->deleted   = $result['deleted'];
                $this->status    = $result['user_status'] ?? null;
            } else {
                throw new Exception("Benutzer mit ID {$in_id} nicht gefunden.");
            }
        } else {
            $this->id = 0;
            $this->deleted = 0;
        }
    }

    /**
     * Erstellt einen neuen Benutzer in der DB.
     */
    private function create()
    {
        if ($this->id > 0) return;
        $stmt = PdoConnect::$connection->prepare(
            "INSERT INTO user ( username,  email,  pwd, type_id) 
                      VALUES  (:username, :email, :pwd,    3   )"
        );
        $stmt->bindParam(":username", $this->username);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":pwd", $this->pwd);

        try {
            $stmt->execute();
        } catch (PDOException $e) {
            echo "Fehler beim Erstellen des Benutzers: " . $e->getMessage();
            return;
        }
    }

    /**
     * Aktualisiert den Benutzer in der DB.
     */
    private function update()
    {
        if ($this->id < 1) return;
        $stmt = PdoConnect::$connection->prepare(
            "UPDATE user SET
                username = :username,
                email = :email,
                pwd = :pwd,
                type_id = :type_id,
                deleted = :deleted
            WHERE id = :user_id;"
        );
        $stmt->bindParam(":user_id", $this->id);
        $stmt->bindParam(":username", $this->username);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":pwd", $this->pwd);
        $stmt->bindParam(":type_id", $this->type_id);
        $stmt->bindParam(":deleted", $this->deleted);
        $stmt->execute();
    }

    /**
     * Speichert (erstellt oder aktualisiert) einen Benutzer.
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
     * Markiert den Benutzer als gelöscht.
     */
    public function del_it()
    {
        if ($this->id < 1) return;
        $this->deleted = 1;
        $this->update();
    }

    /**
     * Authentifiziert einen Benutzer.
     */
    public function login($in_username, $in_pwd)
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM user WHERE username = :username AND deleted = 0"
        );
        $stmt->bindParam(":username", $in_username);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) return false;

        $config = include 'config/config.php';
        $pepper = $config['pepper'];
        $pwd_peppered = hash_hmac("sha256", $in_pwd, $pepper);

        if (password_verify($pwd_peppered, $result['pwd'])) {
            $update_stmt = PdoConnect::$connection->prepare(
                "UPDATE user SET updated_at = CURRENT_TIMESTAMP WHERE id = :id;"
            );
            $update_stmt->bindParam(':id', $result['id']);
            $update_stmt->execute();

            $this->id = $result['id'];
            $this->setUserStatus('online');
            return $this->id;
        } else {
            return false;
        }
    }

    public function setUserStatus($status)
    {
        $stmt = PdoConnect::$connection->prepare(
            "UPDATE user SET user_status = :status WHERE id = :id;"
        );
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':id', $this->id);
        $stmt->execute();
    }

    public function getUserStatus($userId)
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT user_status FROM user WHERE id = ?"
        );
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['user_status'] ?? null;
    }

    // Benutzer nach ID suchen
    public function getUserById($userId)
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM user WHERE id = ?"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Benutzerdaten aktualisieren (z.B. Status)
    public function updateUserStatus($userId, $status)
    {
        $stmt = PdoConnect::$connection->prepare(
            "UPDATE user SET user_status = ? WHERE id = ?"
        );
        $stmt->execute([$status, $userId]);
    }

    public function check_if_username_exists($in_username)
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT id FROM user WHERE username = :username"
        );
        $stmt->bindParam(":username", $in_username);
        $stmt->execute();
        return $stmt->fetch() ? true : false;
    }

    public function check_if_email_exists($in_email)
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT id FROM user WHERE email = :email"
        );
        $stmt->bindParam(":email", $in_email);
        $stmt->execute();
        return $stmt->fetch() ? true : false;
    }

    public function get_user_info_as_array()
    {
        if ($this->id < 0) die("Fehler: keine User Info vorhanden!");
        return [
            $this->status,
            $this->username,
            $this->email
        ];
    }

    public function getAll()
    {
        $result = [];
        $query = "SELECT id FROM user WHERE deleted = 0;";
        foreach (PdoConnect::$connection->query($query) as $row) {
            $result[] = $row['id'];
        }
        return $result;
    }

    public function get_usertype()
    {
        if ($this->id > 0) {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT * FROM usertype WHERE id = :type_id"
            );
            $stmt->bindParam(":type_id", $this->type_id);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['name'] : false;
        } else {
            return 'kein gültiger user';
        }
    }

    public function set_usertype($in_usertype)
    {
        $id = 0;
        if (!is_numeric($in_usertype)) {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT id FROM usertype WHERE `name` = :usertype"
            );
            $stmt->bindParam(":usertype", $in_usertype);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result) $id = $result['id'];
        } else {
            $id = $in_usertype;
        }
        if ($id > 0) $this->type_id = $id;
        else return false;
    }

    // Setter-Methoden
    public function set_id($in_id)           { $this->id = $in_id; }
    public function set_status($in_status)   { $this->status = $in_status; }
    public function set_username($in_username) { $this->username = $in_username; }
    public function set_email($in_email)     { $this->email = $in_email; }
    public function set_pwd($in_pwd)         { $this->pwd = $in_pwd; }

    // Getter-Methoden
    public function get_id()       { return $this->id; }
    public function get_status()   { return $this->status; }
    public function get_username() { return $this->username; }
    public function get_email()    { return $this->email; }
    public function get_pwd()      { return $this->pwd; }
    public function get_role_id()  { return $this->type_id; }
}
