<?php

#################################### admin bereich ############################# 

/**
 * Verarbeitet den Login eines Administrators.
 * Prüft die Benutzereingaben und startet eine Session für einen validierten Benutzer.
 */
function act_login()
{
    if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['pwd']))
    {
        $error = "";

        // Verwenden von Variablen, um Redundanzen zu reduzieren
        $username = htmlspecialchars(g('username'));
        $pwd = htmlspecialchars(g('pwd'));

        // Falls der Login fehlschlägt, Fehlermeldung anzeigen
        if(!login($username, $pwd))
        {
            $error = "Ungültiger Benutzername oder Passwort.";
            $html_output = file_get_contents("assets/html/login.html");
            $out = str_replace("###LOGIN_ERROR###", $error, $html_output);
            output($out);
            die;
        }

        // Überprüfung, ob der Benutzer korrekt eingeloggt ist
        if(!isset($_SESSION['user_id']))
        {
            $html_output = file_get_contents("assets/html/login.html");
            $out = str_replace("###LOGIN_ERROR###", $error, $html_output);
            output($out);
            die();
        }
        
        {
            act_admin("Sie sind nun eingelogt!");
        }
        
    }
    else
    {
        // Falls kein POST-Request vorliegt, wird der Benutzer zur Startseite weitergeleitet
        header("Location: index.php");
        exit;
    } 
}

/**
 * Zeigt die Login-Seite an.
 */
function act_login_page()
{
    $html_output = file_get_contents("assets/html/login.html");
    $out = str_replace("###LOGIN_ERROR###", "", $html_output);
    output($out);
}

/**
 * Loggt den Benutzer aus, setzt die Session zurück und zeigt eine Abmeldebestätigung an.
 */
function act_logout()
{
    $tmp_user = new User($_SESSION['user_id']);
    $tmp_user->setUserStatus('offline');
    session_unset();
    session_destroy();
    output('Sie sind abgemeldet!');
}

############################################################################### 

################################### user bereich ############################## 

/**
 * Leitet einen nicht eingeloggten Benutzer zur Login-Seite weiter.
 * Sobald der Benutzer eingeloggt ist, wird er zum Chat weitergeleitet.
 */
function act_goto_login()
{
    if(!isset($_SESSION['user_id']))
    {
        $out = file_get_contents("assets/html/frontend/login.html");
        output_fe($out);
    }
    act_goto_chat();
}

/**
 * Verarbeitet den Login eines Frontend-Benutzers.
 * Führt die Login-Überprüfung durch und lädt bei erfolgreichem Login die Chat-Historie.
 */
function act_login_fe()
{
    if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['pwd']))
    {
        $username = htmlspecialchars(g('username'));
        $pwd = htmlspecialchars(g('pwd'));

        // Falls der Login fehlschlägt, wird der Benutzer zur Login-Seite zurückgeleitet
        if(!login($username, $pwd))
        {
            $_SESSION['login_error'] = "Ungültiger Benutzername oder Passwort";
            header("Location: index.php?act=login_fe");
            exit;
        }
        // Überprüfen, ob der Benutzer eingeloggt ist und ausreichende Rechte hat
        if(!isset($_SESSION['user_id']) || $_SESSION['role_id'] < 2)
        {
            $out = file_get_contents("assets/html/frontend/login.html");
            output_fe($out);
            exit;
        }
        // Lädt die Chat-Historie, falls diese nicht vorhanden ist
        if(!isset($_SESSION['chat_history']))
        {
            get_chat_history();
        } 
    }
    else
    {
        // Falls kein POST-Request vorliegt, wird der Benutzer zur Startseite weitergeleitet
        header("Location: index.php");
        exit;
    } 
    // Nach erfolgreichem Login wird der Benutzer zum Chat weitergeleitet
    act_goto_chat();
    exit;
}

/**
 * Loggt den Benutzer aus und setzt die Session zurück.
 */
function act_logout_fe()
{
    session_unset();
    session_destroy();
    home();
}

/**
 * Lädt die Chat-Historie des Benutzers aus der Datenbank.
 */
function get_chat_history()
{
    $history = new ChatLog();
    $history->set_user_id($_SESSION['user_id']);
    $chat_history_array = $history->get_history_as_array();
    // Falls die Chat-Historie nicht bereits in der Session vorhanden ist
    if(!isset($_SESSION['chat_history']))
    {
        // Generiert die Chat-Historie, falls vorhanden
        if($chat_history_array != null)
        {
            generate_chat_history($chat_history_array);
        } 
        // Begrüßt den Benutzer nach dem Login
        send_greeting(g('username'));
    }
}

/**
 * Generiert die Chat-Historie und speichert sie in der Session.
 *
 * @param array $in_chat_history_array Array der Chat-Nachrichten
 */
function generate_chat_history($in_chat_history_array)
{
    foreach($in_chat_history_array as $row)
    {
        $originalDate = substr($row[3], 0, 10);
        $time         = substr($row[3], 11);
        $dateObject   = new DateTime($originalDate);

        // Speichert jede Nachricht in der Session
        $_SESSION['chat_history'][] = array(
            'role' => $row[1],
            'content' => $row[2],
            'timestamp' => $dateObject->format('d.m.Y') . ' ' . $time
        );
    }
}

/**
 * Überprüft den Benutzernamen und das Passwort und startet eine Session für den Benutzer.
 *
 * @param string $in_username Der eingegebene Benutzername
 * @param string $in_pwd Das eingegebene Passwort
 * @return bool Gibt true zurück, wenn der Login erfolgreich ist, ansonsten false
 */
function login($in_username, $in_pwd)
{
    $user = new User();
            
    $user_id = $user->login($in_username, $in_pwd);

    if($user_id > 0)
    {
        $_SESSION['user_id'] = $user->get_id();
        
        return true;
    }
    else
    {
        return false;
    }
}