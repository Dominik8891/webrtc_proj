<?php
namespace App\Controller;

use App\Model\User;
use App\Model\Chat;
use App\Model\ChatMessage;
use App\Model\PdoConnect;
use App\Helper\Request;
use App\Helper\ViewHelper;

/**
 * Controller für Chat-Funktionen (Starten, Nachrichten, Einladungen, etc.).
 */
class ChatController
{
    /**
     * Startet einen Chat mit einem anderen Benutzer (findOrCreate).
     * Gibt Chat-Infos als JSON zurück.
     * @return void
     */
    public function startChat(): void
    {
        $currentUserId = $_SESSION['user']['user_id'] ?? null;
        $targetId = (int)Request::g('target_id');
        if (!$currentUserId || !$targetId) {
            echo json_encode(['success' => false, 'error' => 'Invalid user']);
            return;
        }

        $chat = Chat::findOrCreate($currentUserId, $targetId);

        if (!$chat) {
            echo json_encode(['success' => false, 'error' => 'Chat konnte nicht erstellt werden']);
            return;
        }

        $usernames = User::getUsernamesByIds([$chat->getUser1Id(), $chat->getUser2Id()]);

        // Wer ist der Partner?
        $partnerId = ($currentUserId == $chat->getUser1Id()) ? $chat->getUser2Id() : $chat->getUser1Id();
        $partnerName = $usernames[$partnerId] ?? ('User '.$partnerId);
        
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

    /**
     * Akzeptiert eine Chat-Einladung und setzt Chat auf aktiv.
     * @return void
     */
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

    /**
     * Gibt alle Chats des aktuellen Users zurück (inkl. Partnernamen und ungelesenen Nachrichten).
     * @return void
     */
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
            $partnerId = ($chat->getUser1Id() == $currentUserId) ? $chat->getUser2Id() : $chat->getUser1Id();
            $partner = (new User)->getUserById($partnerId);
            $partnerName = $partner ? $partner['username'] : 'Unbekannt';

            $unseenCount = ChatMessage::countUnseenForUser($chat->getId(), $currentUserId);


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

    /**
     * Gibt alle Nachrichten eines Chats zurück.
     * @return void
     */
    public function getMessages(): void
    {
        $chatId = (int)Request::g('chat_id');
        if (!$chatId) {
            echo json_encode(['success' => false, 'error' => 'Invalid chat']);
            return;
        }
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

        if (!$chat->isActive()) {
            $response['pending_for'] = $chat->getPendingFor();
            $response['user1_id'] = $chat->getUser1Id();
            $response['user2_id'] = $chat->getUser2Id();
        }
        echo json_encode($response);
    }

    /**
     * Sendet eine Nachricht in einen Chat.
     * @return void
     */
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

    /**
     * Gibt alle offenen Chat-Einladungen für den aktuellen User zurück.
     * @return void
     */
    public function getChatInvitations(): void 
    {
        try 
        {
            $invitations = Chat::getInvitations();
            echo json_encode(['success' => true, 'invitations' => $invitations]);
        } catch (\Exception $e)
        {
            error_log('Fehler: ' . $e->getMessage() . ' beim laden der Chat invitations.');
            echo json_encode(['success' => false]);
        }
    }

    /**
     * Lehnt eine Chateinladung ab (nur der pending_for-User darf das).
     * @return void
     */
    public function declineChat(): void
    {
        $currentUserId = $_SESSION['user']['user_id'] ?? null;
        $chatId = (int)Request::g('chat_id');
        $chat = Chat::findById($chatId);

        if (!$chat || $chat->getPendingFor() != $currentUserId) {
            echo json_encode(['success' => false, 'error' => 'Nicht erlaubt']);
            return;
        }
        // Soft-Delete über das Model
        $success = $chat->delete();
        echo json_encode(['success' => $success]);
    }

    /**
     * Setzt alle empfangenen Nachrichten eines Chats auf 'gesehen'.
     * @return void
     */
    public function setMessagesSeen(): void
    {
        $chatId = (int)Request::g('chat_id');
        $senderId = (int)Request::g('sender_id');
        if (!$chatId || !$senderId) {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            return;
        }
        $stmt = PdoConnect::$connection->prepare(
            "UPDATE chat_message SET seen = 1 WHERE chat_id = ? AND sender_id != ? AND seen = 0"
        );
        $stmt->execute([$chatId, $senderId]);
        echo json_encode(['success' => true]);
    }

    public function getAllChats(): void
    {
        $currentUserId = $_SESSION['user']['user_id'] ?? null;
        if (!$currentUserId) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            return;
        }
        // auch gelöschte (vergangene) Chats anzeigen:
        $chats = Chat::getAllForUser($currentUserId, true);

        $rowsHtml = '';
        foreach ($chats as $chat) {
            // Partner ermitteln
            $partnerId = ($chat->getUser1Id() == $currentUserId) ? $chat->getUser2Id() : $chat->getUser1Id();
            $partnerName = (new User($partnerId))->getUsername();

            // Status bestimmen
            $status = $chat->isActive() ? 'Aktiv' : ($chat->isDeleted() ? 'Beendet' : 'Offen');

            // Button/Link für Verlauf
            $showChat = '<a href="index.php?act=show_chat&chat_id='.$chat->getId().'">Verlauf anzeigen</a>';

            // Template füllen (list_chat_row.html)
            $rowTpl = file_get_contents('assets/html/list_chat_row.html');
            $rowTpl = str_replace(
                ['###STATUS', '###PARTNER_NAME', '###LAST_MSG###', '###SHOW_CHAT###'],
                [$status, htmlspecialchars($partnerName), $chat->getLastMsgAt(), $showChat],
                $rowTpl
            );
            $rowsHtml .= $rowTpl;
        }

        // Gesamte Tabelle einbinden (list_chat.html)
        $tableTpl = file_get_contents('assets/html/list_chat.html');
        $tableTpl = str_replace('###CHAT_ROWS###', $rowsHtml, $tableTpl);

        ViewHelper::Output($tableTpl); // oder via JSON, je nach Frontend-Logik
    }

    public function showChat(): void
    {
        $chatId = (int)Request::g('chat_id');
        $currentUserId = $_SESSION['user']['user_id'] ?? null;
        $chat = Chat::findById($chatId, true); // Methode ohne deleted=0-Filter!

        if (!$chat) {
            ViewHelper::Output("Chat nicht gefunden.");
            return;
        }

        // Rechteprüfung: ist User Teilnehmer?
        if ($chat->getUser1Id() != $currentUserId && $chat->getUser2Id() != $currentUserId) {
            ViewHelper::Output("Kein Zugriff.");
            return;
        }

        $messages = ChatMessage::getAllForChat($chatId); // Du kannst hier ggf. auch gelöschte Nachrichten unterscheiden
        // Nun HTML bauen (assets/html/show_chat.html als Basis)
        $tpl = file_get_contents('assets/html/show_chat.html');
        $messagesHtml = '';
        foreach ($messages as $msg) {
            $messagesHtml .= '<div><b>'.(new User($msg->getSenderId()))->getUsername().':</b> '
                        .htmlspecialchars($msg->getMsg())
                        .' <span style="color: #aaa;">['.$msg->getSentAt().']</span></div>';
        }
        $tpl = str_replace('<!-- MESSAGES HERE -->', $messagesHtml, $tpl);
        ViewHelper::Output($tpl);
    }

}
