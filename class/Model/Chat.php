<?php
namespace App\Model;

/**
 * Model-Klasse für Chat-Sitzungen zwischen zwei Benutzern.
 * Verwaltet Chat-Instanzen, ermöglicht das Erstellen, Finden und Aktivieren von Chats.
 */
class Chat
{
    private $id;
    private $user1_id;
    private $user2_id;
    private $is_active;
    private $last_msg_at;
    private $pending_for;
    private $deleted;

    /**
     * Konstruktor: Erstellt ein Chat-Objekt aus einem Daten-Array (DB-Row).
     * @param array $data Assoziatives Array mit Chat-Daten
     */
    public function __construct(array $data)
    {
        $this->id          = $data['id'         ] ?? null;
        $this->user1_id    = $data['user1_id'   ] ?? null;
        $this->user2_id    = $data['user2_id'   ] ?? null;
        $this->is_active   = $data['is_active'  ] ?? 0;
        $this->last_msg_at = $data['last_msg_at'] ?? null;
        $this->pending_for = $data['pending_for'] ?? null;
        $this->deleted     = $data['deleted'    ] ?? null;
    }

    /**
     * Sucht einen bestehenden Chat zwischen zwei Usern (unabhängig von Reihenfolge).
     * Falls nicht vorhanden, wird ein neuer angelegt.
     *
     * @param int $user1_id
     * @param int $user2_id
     * @return Chat|null Gibt das Chat-Objekt oder null bei Fehler zurück
     */
    public static function findOrCreate(int $user1_id, int $user2_id): Chat|null
    {
        $ids = [$user1_id, $user2_id];
        sort($ids);

        // 1. Gibt es schon einen Chat, egal ob deleted oder nicht?
        $stmt = PdoConnect::$connection->prepare("SELECT * FROM chat WHERE user1_id = ? AND user2_id = ? LIMIT 1");
        $stmt->execute([$ids[0], $ids[1]]);
        $chat = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($chat) {
            // Falls gelöscht, reaktiviere!
            if ($chat['deleted']) {
                $stmt2 = PdoConnect::$connection->prepare("UPDATE chat SET deleted = 0, is_active = 0, pending_for = ? WHERE id = ?");
                $stmt2->execute([$user2_id, $chat['id']]);
                $chat['deleted'] = 0;
                $chat['is_active'] = 0;
                $chat['pending_for'] = $user2_id;
            }
            // Falls nicht aktiv, pending_for aktualisieren
            if (!$chat['is_active']) {
                $stmt3 = PdoConnect::$connection->prepare("UPDATE chat SET pending_for = ? WHERE id = ?");
                $stmt3->execute([$user2_id, $chat['id']]);
                $chat['pending_for'] = $user2_id;
            }
            return new self($chat);
        }

        // Kein Chat vorhanden: Lege neuen an
        try {
            $stmt = PdoConnect::$connection->prepare(
                "INSERT INTO chat (user1_id, user2_id, is_active, last_msg_at, pending_for, deleted) VALUES (?, ?, 0, NULL, ?, 0)"
            );
            $stmt->execute([$ids[0], $ids[1], $user2_id]);
            $chat_id = PdoConnect::$connection->lastInsertId();
            return new self([
                "id"          => $chat_id,
                "user1_id"    => $ids[0],
                "user2_id"    => $ids[1],
                "is_active"   => 0,
                "last_msg_at" => null,
                "pending_for" => $user2_id,
                "deleted"     => 0
            ]);
        } catch (\PDOException $e) {
            error_log('Fehler in Chat::findOrCreate: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Setzt das Chat-Objekt und den DB-Eintrag auf gelöscht (deleted = 1).
     * @return bool
     */
    public function delete(): bool
    {
        try {
            $stmt = PdoConnect::$connection->prepare("UPDATE chat SET deleted = 1, pending_for = null WHERE id = ?");
            $stmt->execute([$this->id]);
            $this->deleted = 1;
            return true;
        } catch (\PDOException $e) {
            error_log('Fehler in Chat::delete: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gibt alle Chats zurück, an denen der User beteiligt ist, sortiert nach letztem Nachrichtenzeitpunkt.
     *
     * @param int $user_id
     * @param bool :optional ($includeDeleted) standard false
     * @return Chat[] Array von Chat-Objekten
     */
    public static function getAllForUser(int $user_id, bool $includeDeleted = false): array
    {
        $where = $includeDeleted ? '' : 'AND deleted = 0';
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM chat WHERE (user1_id = ? OR user2_id = ?) $where ORDER BY last_msg_at DESC"
        );
        $stmt->execute([$user_id, $user_id]);
        $chats = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $chats[] = new self($row);
        }
        return $chats;
    }

    /**
     * Sucht einen Chat anhand der ID.
     *
     * @param int $chatId
     * @return Chat|null Gibt das Chat-Objekt oder null zurück
     */
    public static function findById(int $chatId, bool $includeDeleted = false): ?Chat
    {
        $where = $includeDeleted ? '' : 'AND deleted = 0';
        $stmt = PdoConnect::$connection->prepare("SELECT * FROM chat WHERE id = ? $where");
        $stmt->execute([$chatId]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    /**
     * Setzt den Chat auf "aktiv" in der Datenbank und im Objekt.
     * @return bool
     */
    public function setActive()
    {
        try {
            $stmt = PdoConnect::$connection->prepare("UPDATE chat SET is_active = 1 WHERE id = ?");
            $stmt->execute([$this->id]);
            $this->is_active = 1;
            return true;
        } catch (\PDOException $e) {
            error_log('Fehler in Chat::setActive: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Prüft, ob der Chat in der Datenbank als aktiv markiert ist.
     * @return bool
     */
    public function checkIfActive(): bool
    {
        try {
            $stmt = PdoConnect::$connection->prepare("SELECT id FROM chat WHERE is_active = 1 AND id = ? AND deleted = 0");
            $stmt->execute([$this->id]);
            return (bool)$stmt->fetch();
        } catch (\PDOException $e) {
            error_log('Fehler in Chat::checkIfActive: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gibt ausstehende Einladungen zurück.
     * @return array
     */
    public static function getInvitations(): array 
    {
        $userId = $_SESSION['user']['user_id'];
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM chat WHERE pending_for = ? AND is_active = 0"
        );
        $stmt->execute([$userId]);
        $invitations = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($invitations as $row) {
            $partnerId = ($userId == $row['user1_id']) ? $row['user2_id'] : $row['user1_id'];
            $partnerName = (new User($partnerId))->getUsername() ?: ('User ' . $partnerId);
            $row['partner_name'] = $partnerName;
            $result[] = $row;
        }
        return $result;
    }

    // Getter-Methoden für die wichtigsten Eigenschaften
    public function getId(): int          { return $this->id;               }
    public function getUser1Id(): int     { return $this->user1_id;         }
    public function getUser2Id(): int     { return $this->user2_id;         }
    public function isActive(): bool      { return (bool)$this->is_active;  }
    public function isDeleted(): bool     { return (bool)$this->deleted;    }
    public function getLastMsgAt()        { return $this->last_msg_at;      }
    public function getPendingFor()       { return $this->pending_for;      }
}
