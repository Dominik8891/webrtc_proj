<?php
namespace App\Controller;

use App\Model\User;
use App\Model\Chat;
use App\Model\ChatMessage;
use App\Model\Location;
use App\Model\PdoConnect;
use App\Model\RateLimit;
use App\Helper\Auth;
use App\Helper\Request;
use App\Helper\ViewHelper;

/**
 * Controller für Chat-Funktionen (Starten, Nachrichten, Verlauf).
 *
 * WER MIT WEM - DIE ERSTE FRAGE (Befund N-12)
 * -------------------------------------------
 * Ein Chat entsteht UEBER EINEN STANDORT: Ein angemeldeter Kunde schreibt den
 * Guide von dessen Standortseite aus an, und wen er anschreibt, sagt der
 * Standort. Die frueher freie Wahl des Gegenuebers ist damit weg - startChat()
 * nimmt keine Kontokennung mehr entgegen, sondern eine Standortkennung, und
 * holt sich den Guide selbst dazu (App\Model\Location::guideIdOf).
 *
 * Vorher war der Chat auf gar keine Beziehung eingeschraenkt: Wer die Route
 * kannte, konnte jedem Konto der Plattform schreiben; die Kennungen sind
 * fortlaufend, ein Durchzaehlen genuegte.
 *
 * DER ADMIN BEHAELT SEINEN DIREKTZUGANG - startDirectChat(), Recht
 * chat.start_direct. Er ist der einzige, der die Benutzerliste sieht (Recht
 * user.list), und er braucht den Weg zu jedem Konto. Das ist eine eigene
 * Route mit einem eigenen Recht und nicht ein Sonderfall in startChat():
 * Ueber den Zugang entscheidet index.php anhand der Rechtetabelle, und ein
 * "wenn Admin, dann anders" mitten im Controller waere eine zweite
 * Rechteentscheidung an einer Stelle, an der niemand sie sucht.
 *
 * WER DARF WAS - DIE ZWEITE PRÜFUNG
 * ---------------------------------
 * Über den Zugang zu den Routen entscheidet index.php anhand der Rechte aus
 * config/routes.php. Ein Recht sagt aber nur "diese Rolle darf Chats lesen",
 * nicht "dieser Chat gehört diesem Nutzer". Deshalb prüft jede Methode, die
 * eine chat_id aus der Anfrage entgegennimmt, zusätzlich die Beteiligung:
 *
 *   getMessages()     nur die beiden Teilnehmer
 *   sendMessage()     nur die beiden Teilnehmer
 *   setMessagesSeen() nur die beiden Teilnehmer
 *   showChat()        nur die beiden Teilnehmer
 *
 * Gefragt wird ueberall dasselbe: Chat::hatTeilnehmer(). Eine viermal
 * ausgeschriebene Bedingung waere eine Gelegenheit, die fuenfte zu vergessen.
 *
 * Die übrigen Methoden nehmen gar keine chat_id entgegen: startChat(),
 * startDirectChat(), getChats() und getAllChats() arbeiten ausschließlich mit
 * der Kennung aus der Sitzung.
 *
 * Die Antwort auf einen unerlaubten Zugriff unterscheidet nirgends zwischen
 * "gibt es nicht" und "geht dich nichts an". Die Chat-IDs sind fortlaufend;
 * eine unterschiedliche Antwort verriete beim Durchzählen, welche Chats es
 * gibt.
 *
 * WAS HIER NICHT MEHR STEHT: ANNEHMEN UND ABLEHNEN
 * -----------------------------------------------
 * acceptChat() und declineChat() sind mit den Spalten is_active und
 * pending_for entfallen (Migration 019). Ein Chat war vorher erst eine
 * Einladung und wurde durch Annehmen zum Gespraech; erst danach gab es ein
 * Eingabefeld. Die Mechanik stammt aus einer Anwendung, in der jeder jeden
 * anschreiben konnte - hier gibt es diesen Fremden nicht mehr, und sie
 * blockierte ausgerechnet den Inhalt, an dem der Guide seine Entscheidung
 * haette treffen koennen. Die Begruendung im Ganzen steht in
 * migrations/019_standort_chat.sql.
 */
class ChatController
{
    /**
     * Öffnet den Chat mit dem Guide eines Standorts (findOrCreate).
     * Gibt Chat-Infos als JSON zurück.
     *
     * DAS GEGENUEBER KOMMT AUS DEM STANDORT und nicht aus der Anfrage. Das ist
     * die ganze Antwort auf Befund N-12: Es gibt keinen Parameter mehr, mit
     * dem sich ein beliebiges Konto anschreiben liesse. Wer eine
     * Standortkennung durchzaehlt, landet bei den Guides oeffentlich
     * angebotener Standorte - also genau bei denen, die Rueckfragen bekommen
     * wollen.
     *
     * DREI DINGE WERDEN GEPRUEFT, und keines davon kann eine Rechtetabelle
     * wissen:
     *   1. Gibt es den Standort, und ist er nicht gesperrt? Beides beantwortet
     *      Location::guideIdOf() mit null - von einem gesperrten Standort aus
     *      beginnt nichts, auch kein Gespraech.
     *   2. Ist der Aufrufer nicht selbst der Guide? Sich selbst schreibt
     *      niemand an.
     *   3. Die Bremse (Befund N-10).
     *
     * DIE BREMSE UND WARUM SIE SO WEIT IST
     * ------------------------------------
     * Sechzig je Stunde, und das ist auffaellig grosszuegig. Der Grund steht
     * im Client: assets/js/ui_chat.js ruft diese Route bei JEDEM Oeffnen eines
     * Chatfensters auf, nicht nur beim Anlegen. Es ist ein findOrCreate, und
     * der Normalfall ist das Find. Eine enge Grenze wuerde den wuergen, der
     * zwischen seinen Gespraechen wechselt, und nicht den, der die Plattform
     * absucht.
     *
     * Seit die Beziehung geprueft wird, ist sie auch nur noch das: eine
     * Obergrenze gegen die Masse. Die eigentliche Antwort auf N-12 steht
     * darueber.
     *
     * @return void
     */
    public function startChat(): void
    {
        $currentUserId = Auth::userId();
        $locationId    = (int)Request::g('location_id');
        if (!$currentUserId || $locationId < 1) {
            echo json_encode(['success' => false, 'error' => 'Invalid request']);
            return;
        }

        $teile = ['konto' => RateLimit::konto($currentUserId)];
        $rest  = RateLimit::restsperre('chat_start', $teile);
        if ($rest > 0) {
            echo json_encode(['success' => false,
                'error' => 'Zu viele Chats in kurzer Zeit. Bitte '
                         . RateLimit::wartehinweis($rest) . ' warten.']);
            return;
        }
        RateLimit::verbuchen('chat_start', $teile);

        // WEN MAN ANSCHREIBEN DARF, SAGT DER STANDORT. Ein gesperrter oder
        // nicht vorhandener Standort meldet null - beides mit derselben
        // nichtssagenden Antwort, damit sich ueber diese Route keine
        // Standortkennungen abklopfen lassen.
        $guideId = (new Location())->guideIdOf($locationId);
        if ($guideId === null) {
            error_log("startChat: Standort #$locationId gibt keinen Guide her");
            echo json_encode(['success' => false, 'error' => 'Zu diesem Standort ist kein Chat möglich.']);
            return;
        }

        // Sich selbst schreibt niemand an. Der Guide sieht auf seinem eigenen
        // Standort ohnehin keinen Knopf (App\Helper\LocationView) - das hier
        // ist die verbindliche Pruefung dazu.
        if ($guideId === (int)$currentUserId) {
            echo json_encode(['success' => false, 'error' => 'Das ist Ihr eigener Standort.']);
            return;
        }

        $this->antworteMitChat(Chat::findOrCreate($currentUserId, $guideId, $locationId),
                               $currentUserId);
    }

    /**
     * Öffnet den Chat mit einem beliebigen Konto - der Direktzugang der
     * Verwaltung.
     *
     * NUR MIT DEM RECHT chat.start_direct, und das hat allein der Admin
     * (App\Helper\Permission). Er ist der einzige, der die Benutzerliste sieht,
     * und von dort aus fuehrt dieser Weg: Ein Konto, das sich nicht ueber
     * einen Standort erreichen laesst - ein Zuschauer etwa -, muss fuer die
     * Verwaltung trotzdem ansprechbar sein.
     *
     * Der Chat traegt KEINE Herkunft (location_id bleibt NULL): Er ist ueber
     * keinen Standort zustande gekommen, und eine erfundene Herkunft waere
     * schlechter als keine.
     *
     * Dieselbe Bremse wie startChat() - es ist derselbe Vorgang, nur mit einer
     * anderen Quelle fuer das Gegenueber.
     *
     * @return void
     */
    public function startDirectChat(): void
    {
        $currentUserId = Auth::userId();
        $targetId      = (int)Request::g('target_id');
        if (!$currentUserId || $targetId < 1) {
            echo json_encode(['success' => false, 'error' => 'Invalid user']);
            return;
        }

        if ($targetId === (int)$currentUserId) {
            echo json_encode(['success' => false, 'error' => 'Mit sich selbst chattet niemand.']);
            return;
        }

        $teile = ['konto' => RateLimit::konto($currentUserId)];
        $rest  = RateLimit::restsperre('chat_start', $teile);
        if ($rest > 0) {
            echo json_encode(['success' => false,
                'error' => 'Zu viele Chats in kurzer Zeit. Bitte '
                         . RateLimit::wartehinweis($rest) . ' warten.']);
            return;
        }
        RateLimit::verbuchen('chat_start', $teile);

        $this->antworteMitChat(Chat::findOrCreate($currentUserId, $targetId, null),
                               $currentUserId);
    }

    /**
     * Die gemeinsame Antwort der beiden Einstiege.
     *
     * Sie steht hier und nicht zweimal ausgeschrieben: Was ein Chatfenster
     * zum Aufbau braucht, ist dasselbe, egal ob es ueber einen Standort oder
     * ueber die Benutzerliste geoeffnet wurde. Zwei Fassungen waeren zwei
     * Gelegenheiten, ein Feld zu vergessen.
     *
     * @param Chat|null $in_chat
     * @param int       $in_user_id Der Angemeldete - aus SEINER Sicht wird der
     *                  Partner bestimmt
     * @return void
     */
    private function antworteMitChat(?Chat $in_chat, $in_user_id): void
    {
        if (!$in_chat) {
            echo json_encode(['success' => false, 'error' => 'Chat konnte nicht erstellt werden']);
            return;
        }

        $partnerId = $in_chat->partnerVon($in_user_id);
        $namen     = User::getUsernamesByIds([$partnerId]);

        echo json_encode([
            'success' => true,
            'chat' => [
                'id'           => $in_chat->getId(),
                'user1_id'     => $in_chat->getUser1Id(),
                'user2_id'     => $in_chat->getUser2Id(),
                'location_id'  => $in_chat->getLocationId(),
                'last_msg_at'  => $in_chat->getLastMsgAt(),
                'partner_name' => $namen[$partnerId] ?? ('User ' . $partnerId),
            ]
        ]);
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
            $partnerId = $chat->partnerVon($currentUserId);
            $partner = (new User)->getUserById($partnerId);
            $partnerName = $partner ? $partner['username'] : 'Unbekannt';

            $unseenCount = ChatMessage::countUnseenForUser($chat->getId(), $currentUserId);


            $result[] = [
                'id' => $chat->getId(),
                'user1_id' => $chat->getUser1Id(),
                'user2_id' => $chat->getUser2Id(),
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
            echo json_encode(['success'=>false, 'gone'=>true]);
            return;
        }

        // Beteiligung pruefen. Die Antwort unterscheidet nicht zwischen
        // "gibt es nicht" und "geht dich nichts an", damit sich ueber diese
        // Route keine fremden Chat-IDs abklopfen lassen.
        if (!$chat->hatTeilnehmer($currentUserId)) {
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

        echo json_encode(['success' => true, 'messages' => $result]);
    }

    /**
     * Sendet eine Nachricht in einen Chat.
     *
     * Zugang: Recht chat.write, geprueft in index.php. Zusaetzlich muss der
     * Absender an diesem Chat beteiligt sein - das kann keine Rechtetabelle
     * wissen.
     *
     * Vorher wurde nur geprueft, DASS jemand angemeldet ist. Die chat_id kam
     * ungeprueft aus der Anfrage und ging direkt in das INSERT: Jeder
     * Angemeldete konnte in jeden fremden Chat schreiben, unter seinem
     * eigenen Namen und ohne je eingeladen worden zu sein. Beim naechsten
     * Abruf stand die Nachricht im Verlauf der beiden Fremden.
     *
     * Es ist dieselbe Pruefung wie in getMessages(): Lesen und Schreiben
     * betreffen denselben Verlauf.
     *
     * GESCHRIEBEN WIRD OHNE VORHERIGE ZUSTIMMUNG DES ANDEREN. Das ist seit
     * Migration 019 so und ist der Punkt: Ein Chat entsteht nur ueber einen
     * Standort, also zwischen einem Kunden und einem Guide, der sein Angebot
     * selbst oeffentlich gemacht hat. Die frueher noetige Annahme haette die
     * erste Nachricht zurueckgehalten - also gerade den Inhalt, an dem der
     * Guide seine Entscheidung haette treffen koennen.
     *
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

        // --- Die Bremse (Befund N-10) -------------------------------------
        //
        // ZWEI SCHRANKEN, ZWEI FRAGEN (config/limits.php): wie schnell jemand
        // schreiben darf, und wie viel insgesamt. Die erste ist ein
        // "langsamer" mit einer Minute Sperre, die zweite begrenzt den
        // Speicherverbrauch, der sonst je Konto unbegrenzt waere (Befund
        // N-7).
        //
        // GEZAEHLT WIRD VOR DER BETEILIGUNGSPRUEFUNG. Der Aufruf in einen
        // fremden Chat ist kein Versehen, sondern das Abklopfen fremder
        // Kennungen - er soll mitzaehlen, nicht gratis sein.
        $teile = ['konto' => RateLimit::konto($currentUserId)];
        $rest  = RateLimit::restsperre('chat_message', $teile);
        if ($rest > 0) {
            echo json_encode(['success' => false,
                'error' => 'Zu viele Nachrichten in kurzer Zeit. Bitte '
                         . RateLimit::wartehinweis($rest) . ' warten.']);
            return;
        }
        RateLimit::verbuchen('chat_message', $teile);

        $chat = Chat::findById($chatId);

        // Beteiligung pruefen. Die Antwort unterscheidet nicht zwischen
        // "gibt es nicht" und "geht dich nichts an", damit sich ueber diese
        // Route keine fremden Chat-IDs abklopfen lassen.
        if (!$chat || !$chat->hatTeilnehmer($currentUserId)) {
            error_log("sendMessage: Benutzer #$currentUserId ist nicht an Chat #$chatId beteiligt");
            echo json_encode(['success' => false, 'error' => 'Kein Zugriff']);
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
     * Setzt alle empfangenen Nachrichten eines Chats auf 'gesehen'.
     *
     * Zugang: Recht chat.read, geprueft in index.php. Zusaetzlich muss der
     * Aufrufer an diesem Chat beteiligt sein - das kann keine Rechtetabelle
     * wissen.
     *
     * ZWEI AENDERUNGEN GEGENUEBER VORHER
     * 1. Wer der Leser ist, sagt die Sitzung und nicht mehr die Anfrage. Das
     *    Feld sender_id kam aus dem Formular; mit einer fremden ID darin
     *    liessen sich die Nachrichten eines Chats aus Sicht eines anderen
     *    als gelesen markieren. Der Browser schickt es weiterhin mit (siehe
     *    assets/js/ui_chat.js) - es wird hier nur nicht mehr gelesen.
     * 2. Die Beteiligung wird geprueft. Vorher genuegte eine beliebige
     *    chat_id, um in einem fremden Chat den Ungelesen-Zaehler des anderen
     *    zurueckzusetzen: Der Empfaenger sah nicht mehr, dass etwas Neues da
     *    war.
     *
     * SEIT ES DEN ZAEHLER IN DER KOPFLEISTE GIBT, faerbt dieser Aufruf ihn
     * ab: Was hier auf gesehen gesetzt wird, faellt aus
     * App\Model\ChatMessage::countUnseenTotal() heraus.
     *
     * @return void
     */
    public function setMessagesSeen(): void
    {
        $chatId = (int)Request::g('chat_id');
        // Der Leser ist immer der Angemeldete. Markiert werden die
        // Nachrichten der GEGENSEITE, deshalb steht er im "sender_id !="
        // der Bedingung.
        $currentUserId = Auth::userId();
        if (!$chatId || !$currentUserId) {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            return;
        }

        $chat = Chat::findById($chatId);

        // Beteiligung pruefen - dieselbe Bedingung und dieselbe
        // nichtssagende Antwort wie in getMessages().
        if (!$chat || !$chat->hatTeilnehmer($currentUserId)) {
            error_log("setMessagesSeen: Benutzer #$currentUserId ist nicht an Chat #$chatId beteiligt");
            echo json_encode(['success' => false, 'error' => 'Kein Zugriff']);
            return;
        }

        $stmt = PdoConnect::$connection->prepare(
            "UPDATE chat_message SET seen = 1 WHERE chat_id = ? AND sender_id != ? AND seen = 0"
        );
        $stmt->execute([$chatId, $currentUserId]);
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
            $partnerId = $chat->partnerVon($currentUserId);
            $partnerName = (new User($partnerId))->getUsername();

            // ZWEI ZUSTAENDE, NICHT MEHR DREI. "Offen" war die noch nicht
            // angenommene Einladung; die gibt es seit Migration 019 nicht
            // mehr. Ein Chat laeuft oder er ist weggeraeumt.
            $status = $chat->isDeleted() ? 'Beendet' : 'Aktiv';

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
        if (!$chat->hatTeilnehmer($currentUserId)) {
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
        ViewHelper::Output($tpl);
    }

}
