<?php
namespace App\Controller;

use App\Model\User;
use App\Model\Chat;
use App\Model\ChatMessage;
use App\Model\PdoConnect;
use App\Helper\Request;

class ChatController
{
    public function startChat(): void
    {
        $currentUserId = $_SESSION['user']['user_id'] ?? null;

        $targetId = (int)Request::g('target_id');
        if (!$currentUserId || !$targetId) {
            echo json_encode(['success' => false, 'error' => 'Invalid user']);
            return;
        }

        $chat = Chat::findOrCreate($currentUserId, $targetId);

        // USERNAME AUS DER DATENBANK LADEN:
        $stmt = PdoConnect::$connection->prepare(
            "SELECT id, username FROM user WHERE id IN (?, ?)"
        );
        $stmt->execute([$chat->getUser1Id(), $chat->getUser2Id()]);
        $usernames = [];
        while($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $usernames[$row['id']] = $row['username'];
        }

        // Wer ist der Partner?
        $partnerId = ($currentUserId == $chat->getUser1Id()) ? $chat->getUser2Id() : $chat->getUser1Id();
        $partnerName = $usernames[$partnerId] ?? ('User '.$partnerId);
        
        // Im JSON mitgeben:
        echo json_encode([
            'success' => true,
            'chat' => [
                'id' => $chat->getId(),
                'user1_id' => $chat->getUser1Id(),
                'user2_id' => $chat->getUser2Id(),
                'is_active' => $chat->isActive(),
                'last_msg_at' => $chat->getLastMsgAt(),
                'partner_name' => $partnerName,
                'pending_for' => $chat->getPendingFor(), 
            ]
        ]);
    }

    public function acceptChat(): void
    {
        $chatId = (int)Request::g('chat_id');
        if (!$chatId) {
            echo json_encode(['success' => false, 'error' => 'Invalid chat']);
            return;
        }
        $chat = new Chat(['id' => $chatId]);
        $chat->setActive();
        echo json_encode(['success' => true]);
    }

    public function getChats(): void
    {
        $currentUserId = $_SESSION['user']['user_id'] ?? null;
        if (!$currentUserId) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            return;
        }
        $chats = Chat::getAllForUser($currentUserId);
        $result = [];
        foreach($chats as $chat) {
            // Bestimme den Partner (der andere User im Chat)
            $partnerId = ($chat->getUser1Id() == $currentUserId) ? $chat->getUser2Id() : $chat->getUser1Id();

            // Hole den Namen
            $partner = (new User)->getUserById($partnerId);
            $partnerName = $partner ? $partner['username'] : 'Unbekannt';

            // Zähle ungelesene Nachrichten 
            $stmt = PdoConnect::$connection->prepare(
                "SELECT COUNT(*) AS cnt FROM chat_message WHERE chat_id = ? AND sender_id != ? AND seen = 0"
            );
            $stmt->execute([$chat->getId(), $currentUserId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $unseenCount = $row ? (int)$row['cnt'] : 0;

            $result[] = [
                'id' => $chat->getId(),
                'user1_id' => $chat->getUser1Id(),
                'user2_id' => $chat->getUser2Id(),
                'is_active' => $chat->isActive(),
                'last_msg_at' => $chat->getLastMsgAt(),
                'partner_name' => $partnerName,
                'unseen_count' => $unseenCount
            ];
        }
        echo json_encode(['success' => true, 'chats' => $result]);
    }


    public function getMessages(): void
    {
        $chatId = (int)Request::g('chat_id');
        if (!$chatId) {
            echo json_encode(['success' => false, 'error' => 'Invalid chat']);
            return;
        }

        // Nutze sauber die Chat-Klasse
        $chat = Chat::findById($chatId);
        if (!$chat) {
            echo json_encode(['success'=>false, 'declined'=>true]);
            return;
        }

        $messages = ChatMessage::getAllForChat($chatId);

        $result = [];
        foreach($messages as $msg) {
            $result[] = [
                'id' => $msg->getId(),
                'chat_id' => $msg->getChatId(),
                'sender_id' => $msg->getSenderId(),
                'msg' => $msg->getMsg(),
                'sent_at' => $msg->getSentAt(),
                'seen' => $msg->isSeen()
            ];
        }

        $response = [
            'success' => true,
            'messages' => $result,
            'is_active' => $chat->isActive() ? 1 : 0
        ];

        // Solange der Chat nicht aktiv ist, sende pending_for + Teilnehmer
        if (!$chat->isActive()) {
            $response['pending_for'] = $chat->getPendingFor();
            $response['user1_id'] = $chat->getUser1Id();
            $response['user2_id'] = $chat->getUser2Id();
        }

        echo json_encode($response);
    }

    public function sendMessage(): void
    {
        $currentUserId = $_SESSION['user']['user_id'] ?? null;
        $chatId = (int)Request::g('chat_id');
        $msg = trim(Request::g('msg'));
        if (!$chatId || !$currentUserId || $msg === '') {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            return;
        }
        $newMsg = ChatMessage::add($chatId, $currentUserId, $msg);
        echo json_encode(['success' => true, 'message' => [
            'id' => $newMsg->getId(),
            'chat_id' => $newMsg->getChatId(),
            'sender_id' => $newMsg->getSenderId(),
            'msg' => $newMsg->getMsg(),
            'sent_at' => $newMsg->getSentAt(),
            'seen' => $newMsg->isSeen()
        ]]);
    }

    public function getChatInvitations(): void 
    {
        $userId = $_SESSION['user']['user_id'];
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM chat WHERE pending_for = ? AND is_active = 0"
        );
        $stmt->execute([$userId]);
        $invitations = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];
        foreach ($invitations as $row) {
            // Partner bestimmen
            $partnerId = ($userId == $row['user1_id']) ? $row['user2_id'] : $row['user1_id'];
            // Username laden
            $stmt2 = PdoConnect::$connection->prepare(
                "SELECT username FROM user WHERE id = ?"
            );
            $stmt2->execute([$partnerId]);
            $partnerName = $stmt2->fetchColumn() ?: ('User '.$partnerId);

            $row['partner_name'] = $partnerName;
            $result[] = $row;
        }
        echo json_encode(['success' => true, 'invitations' => $result]);
    }

    public function declineChat(): void
    {
        $currentUserId = $_SESSION['user']['user_id'] ?? null;
        $chatId = (int)Request::g('chat_id');

        // Hole den Chat aus der DB
        $stmt = PdoConnect::$connection->prepare("SELECT * FROM chat WHERE id = ?");
        $stmt->execute([$chatId]);
        $chat = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Sicherheit: Nur der eingeladene User (pending_for) darf ablehnen!
        if (!$chat || $chat['pending_for'] != $currentUserId) {
            echo json_encode(['success' => false, 'error' => 'Nicht erlaubt']);
            return;
        }

        // ENTWEDER: Chat komplett löschen, falls nie angenommen:
        $stmt = PdoConnect::$connection->prepare("DELETE FROM chat WHERE id = ?");
        $stmt->execute([$chatId]);

        // ALTERNATIV: Nur Einladung entfernen (falls du den Chat für spätere Zwecke behalten willst):
        // $stmt = PdoConnect::$connection->prepare("UPDATE chat SET pending_for = NULL WHERE id = ?");
        // $stmt->execute([$chatId]);

        echo json_encode(['success' => true]);
    }

    public function setMessagesSeen(): void
    {
        $chatId = (int)Request::g('chat_id');
        $senderId = (int)Request::g('sender_id');
        if (!$chatId || !$senderId) {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            return;
        }
        // Setze alle Nachrichten vom Partner, die noch nicht gesehen wurden, auf seen=1
        $stmt = PdoConnect::$connection->prepare(
            "UPDATE chat_message SET seen = 1 WHERE chat_id = ? AND sender_id != ? AND seen = 0"
        );
        $stmt->execute([$chatId, $senderId]);
        echo json_encode(['success' => true]);
    }

}
