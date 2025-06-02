<?php

##############################  ADMIN-BEREICH  ##############################

/**
 * Gibt den HTML-Inhalt der Admin-Seite aus und beendet die PHP-Ausführung.
 */
function output($in_content)
{
    $out = file_get_contents("assets/html/index.html"); 
    $out = str_replace("###CONTENT###", $in_content, $out);

    // Standardlinks vorbereiten
    $sign = "<a href='index.php?act=signup_page'>Sign Up</a>";
    $user_txt = "";
    $text = "<a href='index.php?act=login_page'>Login</a>";

    $logged_in = 'false';
    $user_role = null;

    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        $user = new User($_SESSION['user_id']);
        $user_txt  = "| <span> Sie sind angemeldet als: <b>" . htmlspecialchars($user->get_username()) . "</b> </span>";
        $text = "<a href='index.php?act=logout'>Logout</a>";
        $sign = "<a href='index.php?act=list_user'>Benutzerliste</a>";
        $logged_in = 'true';
        $user_role = $user->get_usertype();
    }

    $logged_in_script = '<script>window.isLoggedIn = ' . $logged_in . ';</script>';
    $user_role_script = '<script>window.userRole = "' . $user_role . '";</script>' . $logged_in_script;
    $out = str_replace("###USERSTATUS###", $user_role_script, $out);
    $out = str_replace("###LOGOUT###", $text, $out);
    $out = str_replace("###USER###", $user_txt, $out);
    $out = str_replace("###REGISTER###", $sign, $out);

    die($out); 
}

/**
 * Gibt eine Admin-Seite aus, optional mit einer Nachricht.
 */
function act_admin($in_msg = "Willkommen im Admin Panel")
{
    output($in_msg);
}

##############################  ADMIN-BEREICH ENDE  ##############################


/**
 * Holt einen Wert aus dem $_REQUEST-Array.
 */
function g($assoc_index)
{
    return isset($_REQUEST[$assoc_index]) ? $_REQUEST[$assoc_index] : null;
}

/**
 * Generiert ein HTML-Dropdown-Menü (Select-Optionen) basierend auf einem Array.
 */
function gen_html_options($in_data_array, $in_selected_id, $in_add_empty)
{   
    $out_opt = "";

    if ($in_add_empty) {
        $out_opt .= '<option value=0>  -- KEINE --  </option>';
    }   

    foreach ($in_data_array as $key => $val) {        
        $sel = ($key == $in_selected_id) ? "selected" : "";
        $out_opt .= '<option value="' . htmlspecialchars($key) . '" ' . $sel . '>' . htmlspecialchars($val) . ' </option>';
    }   

    return $out_opt;
}

##############################  USER-BEREICH  ##############################

/**
 * Gibt den HTML-Inhalt der Benutzeroberfläche aus und beendet die PHP-Ausführung.
 */
function output_fe($in_content)
{
    $html = file_get_contents("assets/html/frontend/index.html");
    $logout = "";

    if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
        $logout = file_get_contents("assets/html/frontend/logout.html");
        $user = new User($_SESSION['user_id']);
        $logout = str_replace("###USERNAME###", htmlspecialchars($user->get_username()), $logout);
    }

    $logout = str_replace("###LOGOUT###", $logout, $html);
    $out = str_replace("###CONTENT###", $in_content, $logout);

    die($out); 
}

/**
 * Zeigt die Startseite (Backend) oder das Chatfenster (Frontend), wenn der Benutzer eingeloggt ist.
 */
function home()
{
    output('');
}

/**
 * Zeigt die Startseite oder das Chatfenster, abhängig vom Benutzerstatus.
 */
function act_start()
{
    $html = file_get_contents("assets/html/frontend/home.html");

    if (isset($_SESSION['user_id'])) {
        $html = file_get_contents("assets/html/frontend/goto_chat.html");
    }
    
    output_fe($html);
}
