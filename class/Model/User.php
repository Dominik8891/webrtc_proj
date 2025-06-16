<?php

namespace App\Model;

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
    private $verified;
    private $totp_secret;
    private $totp_enabled;

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
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($result) {
                $this->id           = $result['id'];
                $this->username     = $result['username'];
                $this->email        = $result['email'];
                $this->pwd          = $result['pwd'];
                $this->type_id      = $result['type_id'];
                $this->deleted      = $result['deleted'];
                $this->status       = $result['user_status'] ?? null;
                $this->totp_secret  = $result['totp_secret'] ?? null;
                $this->totp_enabled = $result['totp_enabled'] ?? 0;

            } else {
                throw new \Exception("Benutzer mit ID {$in_id} nicht gefunden.");
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
            $this->id = PdoConnect::$connection->lastInsertId();
            return $this->id;
        } catch (PDOException $e) {
            error_log("Fehler beim Erstellen des Benutzers: " . $e->getMessage());
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
                username        = :username,
                user_status     = :status,
                email           = :email,
                pwd             = :pwd,
                type_id         = :type_id,
                updated_at      = CURRENT_TIMESTAMP,
                deleted         = :deleted,
                totp_secret     = :totp_secret,
                totp_enabled    = :totp_enabled
            WHERE id = :user_id;"
        );
        $stmt->bindParam(":user_id"         , $this->id);
        $stmt->bindParam(":username"        , $this->username);
        $stmt->bindParam(":status"          , $this->status);
        $stmt->bindParam(":email"           , $this->email);
        $stmt->bindParam(":pwd"             , $this->pwd);
        $stmt->bindParam(":type_id"         , $this->type_id);
        $stmt->bindParam(":deleted"         , $this->deleted);
        $stmt->bindParam(":totp_secret"     , $this->totp_secret);
        $stmt->bindParam(":totp_enabled"    , $this->totp_enabled);
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

    public function register($in_username, $in_email, $in_pwd) {
        // Username validieren (z.B. 3-20 Zeichen, nur Buchstaben/Zahlen/_)
        if (!preg_match('/^[\w]{3,20}$/', $in_username)) {
            error_log("Ungültiger Username: $in_username");
            return null;
        }
        // E-Mail validieren
        if (!filter_var($in_email, FILTER_VALIDATE_EMAIL)) {
            error_log("Ungültige E-Mail: $in_email");
            return null;
        }
        // Passwort-Länge validieren 
        /*
         *
         *
         * wieder ändern auf 8 zeichen 
         * auch im js noch anpassen
         * 
        */
        if (strlen($in_pwd) < 3) {
            error_log("Zu kurzes Passwort für Benutzer: $in_username");
            return null;
        }
        
        try {
            $hased_pwd = $this->pwdEncrypt($in_pwd);
            $this->username = $in_username;
            $this->email    = $in_email;
            $this->pwd      = $hased_pwd;

            $this->create();
            $this->id = PdoConnect::$connection->lastInsertId();
            return $this->id;
        } catch (PDOException $e) {
            error_log("Fehler bei der Datenbankverbindung: " . $e->getMessage());
            return null;
        }
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
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$result) return false;

        $pepper = getenv('PEPPER');
        if (!$pepper) {
            error_log("PEPPER nicht gesetzt!");
            return false;
        }
        $pwd_peppered = hash_hmac("sha256", $in_pwd, $pepper);

        if (password_verify($pwd_peppered, $result['pwd'])) {
            $update_stmt = PdoConnect::$connection->prepare(
                "UPDATE user SET updated_at = CURRENT_TIMESTAMP WHERE id = :id;"
            );
            $update_stmt->bindParam(':id', $result['id']);
            $update_stmt->execute();

            // User-Objekt mit allen Daten füllen!
            $this->id        = $result['id'];
            $this->username  = $result['username'];
            $this->email     = $result['email'];
            $this->pwd       = $result['pwd'];
            $this->type_id   = $result['type_id'];
            $this->deleted   = $result['deleted'];
            $this->status    = $result['user_status'] ?? null;
            $this->verified  = $result['email_verified'];
            $this->totp_secret  = $result['totp_secret'] ?? null;
            $this->totp_enabled = $result['totp_enabled'] ?? 0;

            return true;
        } else {
            return false;
        }
    }

    public function usernameExists() {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM user WHERE username = :username AND deleted = 0"
        );
        $stmt->bindParam(":username", $this->username);
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return (bool)$result;
    }

    public function emailExists() {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM user WHERE email = :email AND deleted = 0"
        );
        $stmt->bindParam(":email", $this->email);
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return (bool)$result;
    }

    public function setUserStatus($status)
    {
        $stmt = PdoConnect::$connection->prepare(
            "UPDATE user SET user_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id;"
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
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result['user_status'] ?? null;
    }

    // Benutzer nach ID suchen
    public function getUserById($userId)
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM user WHERE id = ?"
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    // Benutzerdaten aktualisieren (z.B. Status)
    public function updateUserStatus($userId, $status)
    {
        $stmt = PdoConnect::$connection->prepare(
            "UPDATE user SET user_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
        );
        $stmt->execute([$status, $userId]);
    }

    public function get_user_info_as_array()
    {
        if ($this->id < 0) {
            error_log("Fehler: keine User Info vorhanden!");
            return null;
        }
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

    public function getUsertype()
    {
        if ($this->id > 0) {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT * FROM usertype WHERE id = :type_id"
            );
            $stmt->bindParam(":type_id", $this->type_id);
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result ? $result['name'] : false;
        } else {
            error_log('kein gültiger user');
            return null;
        }
    }

    public function getAllUsertypesAsArray() {
        $result = [];
        $query = "SELECT id, name FROM usertype";
        foreach (PdoConnect::$connection->query($query) as $row) {
            $result[$row['id']] = $row['name'];
        }
        return $result;
    }


    public function setUsertype($in_usertype)
    {
        $id = 0;
        if (!is_numeric($in_usertype)) {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT id FROM usertype WHERE `name` = :usertype"
            );
            $stmt->bindParam(":usertype", $in_usertype);
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($result) $id = $result['id'];
        } else {
            $id = $in_usertype;
        }
        if ($id > 0) $this->type_id = $id;
        else return false;
    }

    public function pwdEncrypt($in_pwd)
    {
        // Lädt pepper aus der .env DAtei
        $pepper = getenv('PEPPER');
        if (!$pepper) {
            error_log("PEPPER nicht gesetzt!");
            throw new \Exception("Fehlende Server-Konfiguration.");
        }

        // Kombiniert das Passwort mit dem "Pepper" und hasht es mit HMAC SHA-256
        $pwd_peppered = hash_hmac("sha256", $in_pwd, $pepper);

        // Verschlüsselt das gepefferte Passwort mit dem Argon2I-Algorithmus
        $pwd_hashed = password_hash($pwd_peppered, PASSWORD_ARGON2I);

        // Gibt das verschlüsselte Passwort zurück
        return $pwd_hashed;
    }

    public function getUserDetails() {
        return [
            'user_id'        => $this->id,
            'username'       => $this->username,
            'email'          => $this->email,
            'role_id'        => $this->type_id,
            'email_verified' => $this->verified
            // ggf. weitere Werte
        ];      
    }

    // Setter-Methoden
    public function setId($in_id)               { $this->id = $in_id;                       }                     
    public function setStatus($in_status)       { $this->status = $in_status;               }
    public function setUsername($in_username)   { $this->username = $in_username;           }
    public function setEmail($in_email)         { $this->email = $in_email;                 }
    public function setPwd($in_pwd)             { $this->pwd = $in_pwd;                     }
    public function setRoleId($in_role_id)      { $this->type_id = $in_role_id;             }
    public function setTotpSecret($secret)      { $this->totp_secret = $secret;             }
    public function setTotpEnabled($enabled)    { $this->totp_enabled = $enabled ? 1 : 0;   }

    // Getter-Methoden
    public function getId()             { return $this->id;             }
    public function getStatus()         { return $this->status;         }
    public function getUsername()       { return $this->username;       }
    public function getEmail()          { return $this->email;          }
    public function getPwd()            { return $this->pwd;            }
    public function getRoleId()         { return $this->type_id;        }
    public function getTotpSecret()     { return $this->totp_secret;    }
    public function getTotpEnabled()    { return $this->totp_enabled;   }
}
