<?php

#################################### Benutzerverwaltung #############################

/**
 * Verarbeitet die Verwaltung eines Benutzers.
 * Überprüft, ob der Benutzer ausreichend Berechtigungen hat und lädt das Benutzerformular zum Bearbeiten.
 */
function act_manage_user()
{
    // Wenn der User nicht eingelogt ist oder nur Umschüler oder niedriger ist, leitet zurück auf die Startseite
    if(!isset($_SESSION['user_id']))
    {
        home();
    }

    // Lädt das HTML-Template zur Benutzerverwaltung
    $out = file_get_contents("assets/html/manage_user.html");

    // Holt die User-ID und die 'send' Variable aus den GET-Parametern
    $user_id  = g('user_id');
    $send     = g('send');
    $tmp_user = new User(intval($user_id));

    // Standard-Informationen für neuen Benutzer setzen
    $user_info  = " neu anlegen";
    $status_opt = "";

    // Wenn eine User-ID übergeben wurde und der 'send' Parameter leer ist, wird der Benutzer geladen
    if($user_id != null && $send == null)
    {
        $status_opt = get_status($tmp_user);
        $user_info  = $tmp_user->get_id() . " (" . $tmp_user->get_username() . ")" . " bearbeiten ";
    }
    // Wenn 'send' gesetzt ist, wird das Formular verarbeitet und die Benutzerdaten gespeichert
    elseif($send != null)
    {
        $sel_user = new User(g('id'));
        $sel_user->set_id(g('id'));

        // Holen der Benutzerdaten aus dem Formular
        $status     = g('status');
        $username   = g('username');
        $email      = g('email');
        $pwd        = g('pwd');

        // Aktualisieren der Benutzerdaten, falls vorhanden
        if($status   != null) $sel_user->set_status           ($status);
        if($username != null) $sel_user->set_username       ($username);
        if($email    != null) $sel_user->set_email             ($email);
        if($pwd      != null) $sel_user->set_pwd    (pwd_encrypt($pwd));
        
        // Speichern der Änderungen
        $sel_user->save();

        // Leitet zur Benutzerliste weiter
        act_list_user();
    }
    
    

    // Platzhalter im HTML-Template ersetzen
    $out = str_replace("###ID###"       , $tmp_user->get_id()       , $out);
    $out = str_replace("###STATUS###"   , $status_opt               , $out);
    $out = str_replace("###USERNAME###" , $tmp_user->get_username() , $out);
    $out = str_replace("###EMAIL###"    , $tmp_user->get_email()    , $out);
    $out = str_replace("###PASSWORD###" , ""                        , $out);
    $out = str_replace("###USER_INFO###", $user_info                , $out);

    // Ausgabe des finalen HTML-Codes
    output($out);
}




/**
 * Zeigt eine Liste aller Benutzer im System an.
 */
function act_list_user()
{
    // Überprüft, ob der Benutzer eingeloggt ist und ob er berechtigt ist, die Benutzerliste zu sehen
    if(!isset($_SESSION['user_id']))
    {
        home();
    }
    // Lädt das HTML-Template für die Benutzerliste
    $table_html = file_get_contents("assets/html/list_user.html");

    // Holt den aktuell eingeloggten Benutzer und alle User-IDs
    $user = new User($_SESSION['user_id']);
    $all_user_ids = $user->getAll();

    // Generiert die Tabellenzeilen für alle Benutzer
    $all_rows = generate_user_rows($user, $all_user_ids);

    // Ersetzt den Platzhalter im Template durch die generierten Zeilen
    $out = str_replace("###USER_ROWS###", $all_rows, $table_html);

    // Ausgabe des finalen HTML-Codes
    output($out);
}

/**
 * Löscht einen Benutzer aus dem System.
 */
function act_delete_user()
{
    // Holt den Benutzer anhand der übergebenen User-ID und löscht diesen
    $tmp_user = new User(g('user_id'));
    $tmp_user->del_it();

    // Nach dem Löschen wird die Benutzerliste neu geladen
    act_list_user();
}

/**
 * Generiert ein HTML-Select-Element für den Status (Aktiv/Inaktiv) des Benutzers.
 *
 * @param User $in_user Der Benutzer, dessen Status generiert wird.
 * @return string Das generierte HTML für das Status-Select-Element.
 */
function get_status($in_user)
{
    // Optionen für den Status (Aktiv/Inaktiv)
    $status_arr = ["Inaktiv","Aktiv"];
    $status_out = gen_html_options($status_arr, $in_user->get_status(), false);

    // Status-Select-HTML generieren
    $get_status = "<label>Status:</label> <select name='status'> ###GET_STATUS### </select>";
    $status     = str_replace("###GET_STATUS###", $status_out, $get_status);
    return $status;
}



/**
 * Generiert die HTML-Zeilen für die Benutzerliste.
 *
 * @param User $in_user Der aktuell eingeloggte Benutzer.
 * @param array $in_user_ids Die IDs aller Benutzer im System.
 * @return string Die generierten HTML-Zeilen für die Benutzerliste.
 */
function generate_user_rows($in_user, $in_user_ids)
{
    // Lädt das Template für eine einzelne Zeile der Benutzerliste
    $row_html = file_get_contents("assets/html/list_user_row.html");

    $all_rows = "";

    // Iteriert über alle Benutzer-IDs und generiert die Zeilen
    foreach($in_user_ids as $one_user_id)
    {
        $tmp_user = new User($one_user_id);

        // Holt die Aktionen (z.B. Ändern/Löschen) für den Benutzer
        $action = get_action($in_user, $tmp_user);

        $status = $tmp_user->getUserStatus($tmp_user->get_id());
        $call_btn = create_call_btn($tmp_user->get_id());

        // Ersetzt die Platzhalter in der Zeile durch die Benutzerdaten
        $tmp_row = str_replace("###ID###"       , $tmp_user->get_id()       , $row_html);
        $tmp_row = str_replace("###STATUS###"   , $status                   , $tmp_row);
        $tmp_row = str_replace("###CALL###"     , $call_btn                 , $tmp_row);
        $tmp_row = str_replace("###USERNAME###" , $tmp_user->get_username() , $tmp_row);
        $tmp_row = str_replace("###EMAIL###"    , $tmp_user->get_email()    , $tmp_row);
        $tmp_row = str_replace("###ACTION###"   , $action                   , $tmp_row);

        // Fügt die generierte Zeile zur gesamten Ausgabe hinzu
        $all_rows .= $tmp_row;
    }
    return $all_rows;
}

function create_call_btn($btn_id)
{
    return '
    <button class="start-call-btn" id="start-call-btn-' . $btn_id . '">Call</button>';
}



/**
 * Generiert die möglichen Aktionen (z.B. Ändern/Löschen) für jeden Benutzer.
 *
 * @param User $in_user Der aktuell eingeloggte Benutzer.
 * @param User $in_current_user Der Benutzer, für den die Aktionen generiert werden.
 * @return string Die möglichen Aktionen als HTML-Links.
 */
function get_action($in_user, $in_current_user)
{
    // Wenn der eingeloggte Benutzer sich selbst bearbeitet
    if($in_user->get_id() == $in_current_user->get_id())
    {
        $action   = 'aktueller Benutzer | <a href="index.php?act=manage_user&user_id=' . $in_current_user->get_id() .'">Ändern</a>';
    }
    // Wenn der eingeloggte Benutzer ein Admin ist
    
    else
    {
        $action   = '<a href="index.php?act=manage_user&user_id=' . $in_current_user->get_id() .'">Ändern</a> | 
                     <a href="#" onclick="del(\'index.php?act=delete_user&user_id=' . $in_current_user->get_id() .'\')">Löschen</a>';
    }
    return $action;
}