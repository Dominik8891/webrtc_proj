<?php
namespace App\Model;

/**
 * Model-Klasse für einzelne Chat-Nachrichten.
 * Bietet Methoden zum Laden, Erstellen und als "gelesen" markieren von Nachrichten.
 */
class ChatMessage
{
    private $id;
    private $chat_id;
    private $sender_id;
    private $msg;
    private $sent_at;
    private $seen;

    /**
     * Konstruktor: Erstellt ein ChatMessage-Objekt aus einem Daten-Array.
     * @param array $data Assoziatives Array mit Nachrichten-Daten
     */
    public function __construct(array $data)
    {
        $this->id        = $data['id'] ?? null;
        $this->chat_id   = $data['chat_id'] ?? null;
        $this->sender_id = $data['sender_id'] ?? null;
        $this->msg       = $data['msg'] ?? '';
        $this->sent_at   = $data['sent_at'] ?? null;
        $this->seen      = $data['seen'] ?? 0;
    }

    /**
     * Holt alle Nachrichten für einen Chat, sortiert nach Sendezeit.
     * @param int $chat_id
     * @return ChatMessage[] Array von ChatMessage-Objekten (oder leer bei Fehler)
     */
    public static function getAllForChat(int $chat_id): array
    {
        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT * FROM chat_message WHERE chat_id = ? ORDER BY sent_at ASC"
            );
            $stmt->execute([$chat_id]);
            $messages = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $messages[] = new self($row);
            }
            return $messages;
        } catch (\PDOException $e) {
            error_log('Fehler in ChatMessage::getAllForChat: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fügt eine neue Nachricht zum Chat hinzu und aktualisiert das Last-Message-Datum im Chat.
     * @param int $chat_id
     * @param int $sender_id
     * @param string $msg
     * @return ChatMessage|null Gibt die neue Nachricht oder null bei Fehler zurück
     */
    public static function add(int $chat_id, int $sender_id, string $msg): ?ChatMessage
    {
        try {
            $stmt = PdoConnect::$connection->prepare(
                "INSERT INTO chat_message (chat_id, sender_id, msg) VALUES (?, ?, ?)"
            );
            $stmt->execute([$chat_id, $sender_id, $msg]);
            $id = PdoConnect::$connection->lastInsertId();
            
            // Aktualisiert das Datum der letzten Nachricht im Chat
            $stmt2 = PdoConnect::$connection->prepare(
                "UPDATE chat SET last_msg_at = NOW() WHERE id = ?"
            );
            $stmt2->execute([$chat_id]);

            return new self([
                'id'        => $id,
                'chat_id'   => $chat_id,
                'sender_id' => $sender_id,
                'msg'       => $msg,
                'sent_at'   => date('Y-m-d H:i:s'),
                'seen'      => 0
            ]);
        } catch (\PDOException $e) {
            error_log('Fehler in ChatMessage::add: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Markiert alle empfangenen Nachrichten eines Chats für einen Nutzer als gelesen.
     * @param int $chat_id
     * @param int $user_id
     * @return void
     */
    public static function markAsSeen(int $chat_id, int $user_id): void
    {
        try {
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE chat_message SET seen = 1 WHERE chat_id = ? AND sender_id != ?"
            );
            $stmt->execute([$chat_id, $user_id]);
        } catch (\PDOException $e) {
            error_log('Fehler in ChatMessage::markAsSeen: ' . $e->getMessage());
            // Optional: Weiterleitung, Anzeige etc. bei Fehler
        }
    }

    /**
     * Markiert alle empfangenen Nachrichten eines Chats für einen Nutzer als gelesen.
     * @param int $chat_id
     * @param int $user_id
     * @return int
     */
    public static function countUnseenForUser($chatId, $userId): int
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT COUNT(*) AS cnt FROM chat_message WHERE chat_id = ? AND sender_id != ? AND seen = 0"
        );
        $stmt->execute([$chatId, $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? (int)$row['cnt'] : 0;
    }

    // Getter 
    public function getId()         { return $this->id;         }
    public function getChatId()     { return $this->chat_id;    }
    public function getSenderId()   { return $this->sender_id;  }
    public function getMsg()        { return $this->msg;        }
    public function getSentAt()     { return $this->sent_at;    }
    public function isSeen()        { return (bool)$this->seen; }
}
