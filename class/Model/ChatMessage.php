<?php
namespace App\Model;

class ChatMessage
{
    private $id;
    private $chat_id;
    private $sender_id;
    private $msg;
    private $sent_at;
    private $seen;

    public function __construct(array $data)
    {
        $this->id = $data['id'] ?? null;
        $this->chat_id = $data['chat_id'] ?? null;
        $this->sender_id = $data['sender_id'] ?? null;
        $this->msg = $data['msg'] ?? '';
        $this->sent_at = $data['sent_at'] ?? null;
        $this->seen = $data['seen'] ?? 0;
    }

    public static function getAllForChat(int $chat_id): array
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM chat_message WHERE chat_id = ? ORDER BY sent_at ASC"
        );
        $stmt->execute([$chat_id]);
        $messages = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $messages[] = new self($row);
        }
        return $messages;
    }

    public static function add(int $chat_id, int $sender_id, string $msg): ChatMessage
    {
        $stmt = PdoConnect::$connection->prepare(
            "INSERT INTO chat_message (chat_id, sender_id, msg) VALUES (?, ?, ?)"
        );
        $stmt->execute([$chat_id, $sender_id, $msg]);
        $id = PdoConnect::$connection->lastInsertId();
        
        // Chat-Tabelle aktualisieren
        $stmt2 = PdoConnect::$connection->prepare(
            "UPDATE chat SET last_msg_at = NOW() WHERE id = ?"
        );
        $stmt2->execute([$chat_id]);

        return new self([
            'id' => $id,
            'chat_id' => $chat_id,
            'sender_id' => $sender_id,
            'msg' => $msg,
            'sent_at' => date('Y-m-d H:i:s'),
            'seen' => 0
        ]);
    }

    public static function markAsSeen(int $chat_id, int $user_id): void
    {
        $stmt = PdoConnect::$connection->prepare(
            "UPDATE chat_message SET seen = 1 WHERE chat_id = ? AND sender_id != ?"
        );
        $stmt->execute([$chat_id, $user_id]);
    }

    // Getter
    public function getId(): int { return $this->id; }
    public function getChatId(): int { return $this->chat_id; }
    public function getSenderId(): int { return $this->sender_id; }
    public function getMsg(): string { return $this->msg; }
    public function getSentAt() { return $this->sent_at; }
    public function isSeen(): bool { return (bool)$this->seen; }
}
