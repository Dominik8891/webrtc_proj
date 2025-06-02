<?php

// ------------------------------------
// ADMIN-BEREICH
// ------------------------------------

/**
 * Verarbeitet den Login eines Administrators.
 */
function act_login()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['pwd'])) {
        $error = "";
        $username = htmlspecialchars(g('username'));
        $pwd      = htmlspecialchars(g('pwd'));

        if (!login($username, $pwd)) {
            $error = "Ungültiger Benutzername oder Passwort.";
            $out = file_get_contents("assets/html/login.html");
            $out = str_replace("###LOGIN_ERROR###", $error, $out);
            output($out);
            die;
        }

        if (!isset($_SESSION['user_id'])) {
            $out = file_get_contents("assets/html/login.html");
            $out = str_replace("###LOGIN_ERROR###", $error, $out);
            output($out);
            die();
        }

        act_admin("Sie sind nun eingelogt!");
    } else {
        header("Location: index.php");
        exit;
    }
}

/**
 * Zeigt die Login-Seite an.
 */
function act_login_page()
{
    $out = file_get_contents("assets/html/login.html");
    $out = str_replace("###LOGIN_ERROR###", "", $out);
    output($out);
}

/**
 * Loggt den Admin aus und zeigt eine Abmeldebestätigung.
 */
function act_logout()
{
    if (isset($_SESSION['user_id'])) {
        $tmp_user = new User($_SESSION['user_id']);
        $tmp_user->setUserStatus('offline');
    }
    session_unset();
    session_destroy();
    output('Sie sind abgemeldet!');
}

// ------------------------------------
// USER-BEREICH (Frontend)
// ------------------------------------

/**
 * Leitet einen nicht eingeloggten User zur Login-Seite weiter.
 */
function act_goto_login()
{
    if (!isset($_SESSION['user_id'])) {
        $out = file_get_contents("assets/html/frontend/login.html");
        output_fe($out);
        exit;
    }
    act_goto_chat();
}

/**
 * Verarbeitet den Login eines Frontend-Benutzers.
 */
function act_login_fe()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['pwd'])) {
        $username = htmlspecialchars(g('username'));
        $pwd      = htmlspecialchars(g('pwd'));

        if (!login($username, $pwd)) {
            $_SESSION['login_error'] = "Ungültiger Benutzername oder Passwort";
            header("Location: index.php?act=login_fe");
            exit;
        }
        if (!isset($_SESSION['user_id']) || ($_SESSION['role_id'] ?? 0) < 2) {
            $out = file_get_contents("assets/html/frontend/login.html");
            output_fe($out);
            exit;
        }
        if (!isset($_SESSION['chat_history'])) {
            get_chat_history();
        }
    } else {
        header("Location: index.php");
        exit;
    }
    act_goto_chat();
    exit;
}

/**
 * Loggt den User aus.
 */
function act_logout_fe()
{
    session_unset();
    session_destroy();
    home();
}

// ------------------------------------
// CHAT HISTORY & LOGIN-HILFEN
// ------------------------------------

/**
 * Lädt die Chat-Historie aus der DB.
 */
function get_chat_history()
{
    $history = new ChatLog();
    $history->set_user_id($_SESSION['user_id']);
    $chat_history_array = $history->get_history_as_array();

    if (!isset($_SESSION['chat_history'])) {
        if ($chat_history_array != null) {
            generate_chat_history($chat_history_array);
        }
        send_greeting(g('username'));
    }
}

/**
 * Generiert die Chat-Historie und speichert sie in der Session.
 */
function generate_chat_history($in_chat_history_array)
{
    foreach ($in_chat_history_array as $row) {
        $originalDate = substr($row[3], 0, 10);
        $time         = substr($row[3], 11);
        $dateObject   = new DateTime($originalDate);

        $_SESSION['chat_history'][] = [
            'role'      => $row[1],
            'content'   => $row[2],
            'timestamp' => $dateObject->format('d.m.Y') . ' ' . $time
        ];
    }
}

/**
 * Überprüft Benutzernamen und Passwort und setzt Session.
 */
function login($in_username, $in_pwd)
{
    $user = new User();
    $user_id = $user->login($in_username, $in_pwd);

    if ($user_id > 0) {
        $_SESSION['user_id'] = $user->get_id();
        // Zusätzliche Session-Attribute (z. B. Rechte/Rolle) nach Bedarf ergänzen!
        $_SESSION['username'] = $in_username;
        $_SESSION['usertype'] = $user->get_usertype();
        $_SESSION['role_id']  = $user->get_role_id();
        return true;
    } else {
        return false;
    }
}
