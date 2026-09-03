<?php
namespace App\Controller;

use App\Model\User;
use App\Model\Chat;
use App\Model\ChatMessage;
use App\Model\PdoConnect;
use App\Helper\Auth;
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
        $currentUserId = Auth::userId();
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
        $currentUserId = Auth::userId();
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
     *
     * Zugang: Recht chat.read, geprueft in index.php. Zusaetzlich muss der
     * Aufrufer an diesem Chat beteiligt sein - das kann keine Rechtetabelle
     * wissen.
     *
     * Vorher fand hier ueberhaupt keine Pruefung statt: weder auf eine
     * Anmeldung noch auf eine Beteiligung. Ein Aufruf mit einer beliebigen
     * chat_id gab den kompletten Nachrichtenverlauf zweier fremder Nutzer
     * heraus; die IDs sind fortlaufend, ein Durchzaehlen genuegte.
     * ChatController::showChat() prueft die Beteiligung seit jeher - diese
     * Methode liefert dieselben Daten und tut es jetzt auch.
     *
     * @return void
     */
    public function getMessages(): void
    {
        $chatId        = (int)Request::g('chat_id');
        $currentUserId = Auth::userId();
        if (!$chatId || !$currentUserId) {
            echo json_encode(['success' => false, 'error' => 'Invalid chat']);
            return;
        }
        $chat = Chat::findById($chatId);
        if (!$chat) {
            echo json_encode(['success'=>false, 'declined'=>true]);
            return;
        }

        // Beteiligung pruefen. Die Antwort unterscheidet nicht zwischen
        // "gibt es nicht" und "geht dich nichts an", damit sich ueber diese
        // Route keine fremden Chat-IDs abklopfen lassen.
        if ($chat->getUser1Id() != $currentUserId && $chat->getUser2Id() != $currentUserId) {
            error_log("getMessages: Benutzer #$currentUserId ist nicht an Chat #$chatId beteiligt");
            echo json_encode(['success' => false, 'error' => 'Kein Zugriff']);
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
        $currentUserId = Auth::userId();
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
        $currentUserId = Auth::userId();
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
        $currentUserId = Auth::userId();
        if (!$currentUserId) {
            echo json_encode(['success' => false, 'error' => 'Not logged in']);
            return;
        }
        // auch gelöschte (vergangene) Chats anzeigen:
        $chats = Chat::getAllForUser($currentUserId, true);

        // Die Zeilenvorlage einmal laden, nicht einmal pro Zeile.
        $rowVorlage = ViewHelper::template('assets/html/list_chat_row.html');

        $rowsHtml = '';
        foreach ($chats as $chat) {
            // Partner ermitteln
            $partnerId = ($chat->getUser1Id() == $currentUserId) ? $chat->getUser2Id() : $chat->getUser1Id();
            $partnerName = (new User($partnerId))->getUsername();

            // Status bestimmen
            $status = $chat->isActive() ? 'Aktiv' : ($chat->isDeleted() ? 'Beendet' : 'Offen');

            // Verlauf: eine Nebenaktion, also ein Symbol ohne Rahmen. Der
            // Partnername steht im aria-label - "Verlauf anzeigen" allein
            // wiederholt sich sonst in jeder Zeile ohne Bezug.
            $showChat = '<div class="app-actions-cell">'
                      . '<a href="index.php?act=show_chat&chat_id=' . intval($chat->getId()) . '"'
                      . ' class="app-iconbtn app-iconbtn--history"'
                      . ' aria-label="Verlauf mit ' . htmlspecialchars($partnerName) . ' anzeigen"'
                      . ' title="Verlauf anzeigen"></a>'
                      . '</div>';

            // Template füllen (list_chat_row.html)
            $rowTpl = str_replace(
                ['###STATUS###', '###PARTNER_NAME###', '###LAST_MSG###', '###SHOW_CHAT###'],
                [htmlspecialchars($status), htmlspecialchars($partnerName), $chat->getLastMsgAt(), $showChat],
                $rowVorlage
            );
            $rowsHtml .= $rowTpl;
        }

        // Gesamte Tabelle einbinden (list_chat.html)
        $tableTpl = ViewHelper::template('assets/html/list_chat.html');
        $tableTpl = str_replace('###CHAT_ROWS###', $rowsHtml, $tableTpl);

        ViewHelper::Output($tableTpl); // oder via JSON, je nach Frontend-Logik
    }

    public function showChat(): void
    {
        $chatId = (int)Request::g('chat_id');
        $currentUserId = Auth::userId();
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
        $tpl = ViewHelper::template('assets/html/show_chat.html');
        $messagesHtml = '';
        foreach ($messages as $msg) { 
            // Eine Nachricht ist eine Zeile im Verlauf und keine eigene Karte:
            // Absender, Text, Zeit. Die Gestaltung steht in
            // assets/css/theme.css unter .app-message.
            $messagesHtml .= '<div class="app-message">
                                <span class="app-message__from">'
                                    . htmlspecialchars((new User($msg->getSenderId()))->getUsername())
                                . '</span>
                                <span class="app-message__text">' . htmlspecialchars($msg->getMsg()) . '</span>
                                <span class="app-message__time">' . htmlspecialchars($msg->getSentAt()) . '</span>
                            </div>';
        }
        $tpl = str_replace('<!-- MESSAGES HERE -->', $messagesHtml, $tpl);
        $tpl = str_replace('###RETENTION_HINT###', self::retentionHint(), $tpl);
        ViewHelper::Output($tpl);
    }

    /**
     * Der Hinweis auf die Aufbewahrungsdauer fuer die Verlaufsseite.
     *
     * Dieselbe Aussage wie im Chatfenster (assets/js/ui_chat.js,
     * retentionNote) und aus derselben Quelle - hier serverseitig, weil die
     * Verlaufsseite ohne JavaScript fertig ausgeliefert wird.
     *
     * Ist die Aufbewahrung abgeschaltet (0 oder kleiner), bleibt die Stelle
     * leer: Ein Hinweis auf eine Loeschung, die nicht stattfindet, waere
     * schlimmer als keiner.
     *
     * @return string HTML oder ein leerer String
     */
    private static function retentionHint(): string
    {
        $retention = require __DIR__ . '/../../config/chat_retention.php';
        $tage      = (int)$retention['retention_days'];

        if ($tage <= 0) {
            return '';
        }

        return '<p class="app-hint app-hint--quiet">Nachrichten werden nach '
             . $tage . ' Tagen automatisch gel&ouml;scht.</p>';
    }

}
