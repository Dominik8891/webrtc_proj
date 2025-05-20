<?php

/**
 * Verarbeitet eine gesendete Nachricht vom Benutzer und gibt eine Antwort zurück.
 * Speichert die Nachrichten sowohl des Benutzers als auch der Antwort des Bots in der Session-Historie.
 */
function act_process_message()
{
    // Überprüft, ob die Methode POST ist und ob eine Nachricht gesendet wurde
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['message'])) {
    
        // Bereinigt die Nachricht des Benutzers vor der Verarbeitung
        $user_msg = htmlspecialchars($_POST['message']);
        // Holt das aktuelle Datum und die Uhrzeit für die Historie
        $history_date = date("d.m.Y H:i");

        // Erzeugt ein JSON-Array mit der Benutzer-ID und der Nachricht des Benutzers
        $json_data = array(
            'message' => $user_msg,
            'user_id' => $_SESSION['user_id']
        );

        // Sendet das JSON an das LLM und holt die Antwort
        $response = send_json_to_llm($json_data);

        // Falls keine Antwort vom LLM kommt, wird die letzte Antwort aus dem Chat-Log geholt
        if($response == null || $response == "")
        {
            $chat = new ChatLog();
            $chat->set_user_id($_SESSION['user_id']);
            $response = $chat->get_last_answer();
        }

        // Speichert die Nachricht des Benutzers in der Session-Historie
        $_SESSION['chat_history'][] = array(
            'role' => 'user',
            'content' => htmlspecialchars($user_msg),
            'timestamp' => $history_date
        );

        // Speichert die Antwort des Bots in der Session-Historie
        $_SESSION['chat_history'][] = array(
            'role' => 'bot',
            'content' => $response,
            'timestamp' => $history_date
        );

        // Gibt das Benutzer- und Bot-Nachrichtenpaar als JSON zurück
        echo json_encode(array(
            'user_message' => htmlspecialchars($user_msg),
            'bot_message' => htmlspecialchars($response),
            'time' => $history_date,
            'now' => date('m.d.Y')
        ));
    }
}

/**
 * Sendet JSON-Daten an das LLM und empfängt die Antwort.
 * 
 * @param array $in_json Das JSON, das an das LLM gesendet wird.
 * @return string Die Antwort vom LLM.
 */
function send_json_to_llm($in_json)
{
    $original_dir = getcwd(); // Speichert das aktuelle Verzeichnis
    chdir('python'); // Wechselt in das Python-Verzeichnis
    $command = 'python main.py ' . base64_encode(json_encode($in_json)); // Erzeugt den Befehl zum Aufruf des Python-Skripts
    $output = shell_exec(escapeshellcmd($command)); // Führt den Befehl aus
    chdir($original_dir); // Wechselt zurück ins ursprüngliche Verzeichnis

    // Falls keine Ausgabe von shell_exec kommt, wird eine leere Antwort zurückgegeben
    if ($output === null || $output === false) {
        return "";  // Fehlerbehandlung falls keine Ausgabe erfolgt
    }

    $response = json_decode($output); // Dekodiert die JSON-Antwort

    return $response; // Gibt die Antwort zurück
}

/**
 * Zeigt das Chat-Interface an und füllt den Chatverlauf.
 * 
 * @return string Der HTML-Code des Chat-Interfaces mit der Chat-Historie.
 */
function show_chatbot()
{
    // Holt das Chat-HTML-Template
    $chat_history = file_get_contents("assets/html/frontend/chatbot.html");
    $bot_history  = file_get_contents("assets/html/frontend/history_bot.html");
    $user_history = file_get_contents("assets/html/frontend/history_user.html");

    $tmp_history = ""; // Variable für die temporäre Chat-Historie

    // Falls es eine gespeicherte Chat-Historie in der Session gibt
    if(isset($_SESSION['chat_history']))
    {
        foreach ($_SESSION['chat_history'] as $message) {
            $time = $message['timestamp']; // Holt die Zeit des aktuellen Nachrichten-Items
            // Falls die Nachricht heute gesendet wurde, wird "today" anstelle des Datums angezeigt
            if (substr($message['timestamp'], 0, 10) == date('d.m.Y')) {
                $time = "heute" . substr($message['timestamp'], 10);
            }
            // Unterscheidet zwischen Benutzer- und Bot-Nachrichten
            if ($message['role'] === 'user') {
                $tmp_hist = str_replace("###MESSAGE###", $message['content'], $user_history);
            } elseif ($message['role'] === 'bot') {
                $tmp_hist = str_replace("###MESSAGE###", $message['content'], $bot_history);
            }
            // Ersetzt den Zeit-Platzhalter im Nachrichten-Template
            $tmp_hist = str_replace("###TIME###", $time, $tmp_hist);

            $tmp_history .= $tmp_hist; // Fügt die Nachricht der Historie hinzu
        }
    }
    // Ersetzt die Historie im Chat-HTML mit der generierten Historie
    $chat_history = str_replace("###HISTORY###", $tmp_history, $chat_history);

    return $chat_history;
}

/**
 * Leitet den Benutzer zum Chat weiter, falls er eingeloggt ist.
 * Falls der Benutzer nicht eingeloggt ist, wird die Home-Seite angezeigt.
 */
function act_goto_chat()
{
    // Falls der Benutzer eingeloggt ist
    if(isset($_SESSION['user_id']))
    {
        $out = show_chatbot(); // Holt das Chat-Interface
        $out = str_replace("###LOGIN_SCRIPT###", "<script src='assets/js/scroll.js' defer></script>", $out );
        output_fe($out); // Gibt das Frontend aus
        return; // Verhindert, dass die Home-Seite aufgerufen wird
    }
    home(); // Falls der Benutzer nicht eingeloggt ist, zur Startseite leiten
}

/**
 * Sendet eine Begrüßungsnachricht an den Benutzer.
 * 
 * @param string $in_username Der Benutzername des Benutzers.
 */
function send_greeting($in_username)
{
    // Erstellt eine Begrüßungsnachricht
    $greeting = "Hallo " . $in_username . ", was kann ich für dich tun?";
    $history_date = date("d.m.Y H:i");

    // Speichert die Begrüßungsnachricht in der Chat-Historie
    $_SESSION['chat_history'][] = array(
        'role' => 'bot',
        'content' => $greeting,
        'timestamp' => $history_date
    );
}

/**
 * Schreibt eine Nachricht in die Datenbank.
 * 
 * @param string $in_msg Die Nachricht, die gespeichert werden soll.
 * @param string $in_msg_type Der Typ der Nachricht (z.B. Benutzer- oder Bot-Nachricht).
 */
function write_message_in_db($in_msg, $in_msg_type)
{
    $chat = new ChatLog(); // Erstellt ein neues ChatLog-Objekt
    $chat->set_user_id($_SESSION['user_id']); // Setzt die Benutzer-ID
    $chat->set_msg($in_msg); // Setzt die Nachricht
    $chat->set_msg_type($in_msg_type); // Setzt den Nachrichtentyp
    $chat->save(); // Speichert die Nachricht in der Datenbank
}