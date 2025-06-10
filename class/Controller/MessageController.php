<?php
namespace App\Controller;

use App\Model\User;
use App\Helper\Request;

class MessageController
{
    /**
     * Verarbeitet eine gesendete Nachricht vom Benutzer und gibt eine Antwort zurück.
     */
    public function processMessage()
    {
        if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['message'])) {
            $user_msg = trim(htmlspecialchars($_POST['message']));
            $history_date = date("d.m.Y H:i");

            $json_data = [
                'message' => $user_msg,
                'user_id' => $_SESSION['user_id']
            ];

            // LLM-Antwort holen
            $response = self::sendJsonToLlm($json_data);

            // Fallback: letzte Bot-Antwort aus DB, falls LLM nicht antwortet
            if (empty($response)) {
                $chat = new \ChatLog();
                $chat->setUserId($_SESSION['user_id']);
                $response = $chat->getLastAnswer();
            }

            // In Session-Chat-History speichern
            $_SESSION['chat_history'][] = [
                'role' => 'user',
                'content' => $user_msg,
                'timestamp' => $history_date
            ];
            $_SESSION['chat_history'][] = [
                'role' => 'bot',
                'content' => $response,
                'timestamp' => $history_date
            ];

            // Auch in DB schreiben
            self::writeMessageInDb($user_msg, 'user');
            self::writeMessageInDb($response, 'bot');

            // Antwort als JSON zurückgeben
            echo json_encode([
                'user_message' => $user_msg,
                'bot_message'  => $response,
                'time'         => $history_date,
                'now'          => date('m.d.Y')
            ]);
            exit;
        }
    }

    /**
     * Sendet JSON-Daten an das LLM und empfängt die Antwort.
     */
    private static function sendJsonToLlm($in_json)
    {
        $original_dir = getcwd();
        chdir('python');
        $command = 'python main.py ' . base64_encode(json_encode($in_json));
        $output = shell_exec(escapeshellcmd($command));
        chdir($original_dir);

        if (empty($output)) {
            return "";
        }
        $response = json_decode($output, true);

        // Falls $response ein Array mit Antwort ist, extrahiere String
        if (is_array($response) && isset($response['answer'])) {
            return $response['answer'];
        }
        // Falls Antwort direkt als String
        if (is_string($response)) {
            return $response;
        }
        return "";
    }

    /**
     * Zeigt das Chat-Interface an und füllt den Chatverlauf.
     */
    public function showChatbot()
    {
        $chat_history_tpl = file_get_contents("assets/html/frontend/chatbot.html");
        $bot_history_tpl  = file_get_contents("assets/html/frontend/history_bot.html");
        $user_history_tpl = file_get_contents("assets/html/frontend/history_user.html");

        $tmp_history = "";

        if (!empty($_SESSION['chat_history'])) {
            foreach ($_SESSION['chat_history'] as $message) {
                $time = $message['timestamp'];
                if (substr($time, 0, 10) == date('d.m.Y')) {
                    $time = "heute" . substr($time, 10);
                }
                if ($message['role'] === 'user') {
                    $msg_tpl = $user_history_tpl;
                } elseif ($message['role'] === 'bot') {
                    $msg_tpl = $bot_history_tpl;
                } else {
                    continue; // Unbekannte Rolle: ignorieren
                }
                $msg_tpl = str_replace("###MESSAGE###", htmlspecialchars($message['content']), $msg_tpl);
                $msg_tpl = str_replace("###TIME###", $time, $msg_tpl);
                $tmp_history .= $msg_tpl;
            }
        }
        $chat_history_tpl = str_replace("###HISTORY###", $tmp_history, $chat_history_tpl);

        return $chat_history_tpl;
    }

    /**
     * Leitet den Benutzer zum Chat weiter, falls er eingeloggt ist.
     */
    public function gotoChat()
    {
        if (!empty($_SESSION['user_id'])) {
            $out = $this->showChatbot();
            $out = str_replace("###LOGIN_SCRIPT###", "<script src='assets/js/scroll.js' defer></script>", $out);
            \App\Helper\ViewHelper::output_fe($out);
            return;
        }
        (new SystemController())->home();
    }

    /**
     * Sendet eine Begrüßungsnachricht an den Benutzer.
     */
    public static function sendGreeting($in_username)
    {
        $greeting = "Hallo " . htmlspecialchars($in_username) . ", was kann ich für dich tun?";
        $history_date = date("d.m.Y H:i");
        $_SESSION['chat_history'][] = [
            'role' => 'bot',
            'content' => $greeting,
            'timestamp' => $history_date
        ];
    }

    /**
     * Schreibt eine Nachricht in die Datenbank.
     */
    private static function writeMessageInDb($in_msg, $in_msg_type)
    {
        $chat = new \ChatLog();
        $chat->setUserId($_SESSION['user_id']);
        $chat->setMsg($in_msg);
        $chat->setMsgType($in_msg_type);
        $chat->save();
    }
}
