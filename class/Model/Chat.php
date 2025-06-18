<?php
namespace App\Model;

class Chat
{
    private $id;
    private $user1_id;
    private $user2_id;
    private $is_active;
    private $last_msg_at;
    private $pending_for;

    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? null;
        $this->user1_id = $data['user1_id'] ?? null;
        $this->user2_id = $data['user2_id'] ?? null;
        $this->is_active = $data['is_active'] ?? 0;
        $this->last_msg_at = $data['last_msg_at'] ?? null;
        $this->pending_for = $data['pending_for'] ?? null;
    }

    public static function findOrCreate(int $user1_id, int $user2_id): Chat
    {
        $ids = [$user1_id, $user2_id];
        sort($ids);
        $stmt = PdoConnect::$connection->prepare("SELECT * FROM chat WHERE user1_id = ? AND user2_id = ?");
        $stmt->execute([$ids[0], $ids[1]]);
        $chat = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($chat) {
            // Wenn Chat existiert und nicht aktiv, setze pending_for ggf. neu
            if (!$chat['is_active']) {
                $stmt = PdoConnect::$connection->prepare("UPDATE chat SET pending_for = ? WHERE id = ?");
                $stmt->execute([$ids[1], $chat['id']]);
            }
            return new self($chat);
        } else {
            $stmt = PdoConnect::$connection->prepare(
                "INSERT INTO chat (user1_id, user2_id, is_active, last_msg_at, pending_for) VALUES (?, ?, 0, NULL, ?)"
            );
            $stmt->execute([$ids[0], $ids[1], $ids[1]]);
            $chat_id = PdoConnect::$connection->lastInsertId();
            return new self([
                "id" => $chat_id,
                "user1_id" => $ids[0],
                "user2_id" => $ids[1],
                "is_active" => 0,
                "last_msg_at" => null,
                "pending_for" => $ids[1]
            ]);
        }
    }

    public static function getAllForUser(int $user_id): array
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM chat 
            WHERE user1_id = ? OR user2_id = ?
            ORDER BY last_msg_at DESC
        ");
        $stmt->execute([$user_id, $user_id]);
        $chats = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $chats[] = new self($row);
        }
        return $chats;
    }

    public static function findById(int $chatId): ?Chat
    {
        $stmt = PdoConnect::$connection->prepare("SELECT * FROM chat WHERE id = ?");
        $stmt->execute([$chatId]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $data ? new self($data) : null;
    }

    public function setActive(): void
    {
        $stmt = PdoConnect::$connection->prepare("UPDATE chat SET is_active = 1 WHERE id = ?");
        $stmt->execute([$this->id]);
        $this->is_active = 1;
    }

    public function checkIfActive()
    {
        $stmt = PdoConnect::$connection->prepare("SELECT * FROM chat WHERE is_active = 1 AND id = ?");
        $result = $stmt->execute([$this->id]);
        if ($result) {
            return true;
        } else {
            return false;
        } 
    }

    // Getter (Beispiel)
    public function getId(): int { return $this->id; }
    public function getUser1Id(): int { return $this->user1_id; }
    public function getUser2Id(): int { return $this->user2_id; }
    public function isActive(): bool { return (bool)$this->is_active; }
    public function getLastMsgAt() { return $this->last_msg_at; }
    public function getPendingFor() { return $this->pending_for; }
}