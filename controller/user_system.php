<?php

#################################### Benutzerverwaltung #############################

/**
 * Verarbeitet die Verwaltung eines Benutzers (Anlegen/Bearbeiten).
 */
function act_manage_user()
{
    if (!isset($_SESSION['user_id'])) {
        home();
        exit;
    }
    if ($_SESSION['role_id'] > 1) {
        home();
        exit;
    }

    $out = file_get_contents("assets/html/manage_user.html");

    $user_id  = g('user_id');
    $send     = g('send');

    $tmp_user = new User(intval($user_id));
    $role     = gen_html_options($tmp_user->get_all_usertypes_as_array(),$tmp_user->get_role_id());

    $user_info  = " neu anlegen";

    if ($user_id !== null && $send === null) {
        $user_info  = $tmp_user->get_id() . " (" . htmlspecialchars($tmp_user->get_username()) . ") bearbeiten ";
    }
    elseif ($send !== null) {
        $sel_user   = new User(g('id'));
        $username   = g('username');
        $email      = g('email');
        $pwd        = g('pwd');

        if ($username !== null               ) $sel_user->set_username($username);
        if ($email    !== null               ) $sel_user->set_email($email);
        if ($pwd      !== null && $pwd !== '') $sel_user->set_pwd(pwd_encrypt($pwd));

        $sel_user->save();
        act_list_user();
        return;
    }

    // Platzhalter ersetzen
    $out = str_replace("###ID###"       , $tmp_user->get_id()                         , $out);
    $out = str_replace("###ROLE###"     , $role                                       , $out);
    $out = str_replace("###USERNAME###" , htmlspecialchars($tmp_user->get_username()) , $out);
    $out = str_replace("###EMAIL###"    , htmlspecialchars($tmp_user->get_email())    , $out);
    $out = str_replace("###PASSWORD###" , ""                                          , $out);
    $out = str_replace("###USER_INFO###", $user_info                                  , $out);

    output($out);
}

/**
 * Zeigt eine Liste aller Benutzer im System an.
 */
function act_list_user()
{
    if (!isset($_SESSION['user_id'])) {
        home();
    }
    $table_html = file_get_contents("assets/html/list_user.html");
    $user = new User($_SESSION['user_id']);
    
    $action = "";
    $new    = "";
    if ($user->get_role_id() === 1) {
        $action = "<th>Aktion</th>";
        $new    = '<a href="index.php?act=manage_user" class="button">NEU</a>';
    }

    $all_user_ids = $user->getAll();

    $all_rows = generate_user_rows($user, $all_user_ids);
    $out = str_replace("###ACTION###"    , $action   , $table_html);
    $out = str_replace("###NEW###"       , $new      , $out);
    $out = str_replace("###USER_ROWS###" , $all_rows , $out);
    output($out);
}

/**
 * Löscht einen Benutzer aus dem System.
 */
function act_delete_user()
{
    $tmp_user = new User(g('user_id'));
    $tmp_user->del_it();
    act_list_user();
}

/**
 * Generiert die HTML-Zeilen für die Benutzerliste.
 */
function generate_user_rows($in_user, $in_user_ids)
{
    $row_html = file_get_contents("assets/html/list_user_row.html");
    $all_rows = "";

    foreach ($in_user_ids as $one_user_id) {
        if ($one_user_id == $in_user->get_id()) continue;

        $tmp_user = new User($one_user_id);
        $action = "";

        if ($in_user->get_role_id() === 1) {
            $action = get_action($tmp_user);
            $status = "Offline";
        }
        if($tmp_user->getUserStatus($tmp_user->get_id()) === "online") {
            $status = "Online";
        } elseif ($tmp_user->getUserStatus($tmp_user->get_id()) === "in_call") {
            $status = "In Call";
        }
        $call_btn = create_call_btn($tmp_user->get_id());

        $tmp_row = str_replace("###ID###"       , $tmp_user->get_id()                         , $row_html);
        $tmp_row = str_replace("###STATUS###"   , $status                                     , $tmp_row);
        $tmp_row = str_replace("###CALL###"     , $call_btn                                   , $tmp_row);
        $tmp_row = str_replace("###USERNAME###" , htmlspecialchars($tmp_user->get_username()) , $tmp_row);
        $tmp_row = str_replace("###EMAIL###"    , htmlspecialchars($tmp_user->get_email())    , $tmp_row);
        $tmp_row = str_replace("###ACTION###"   , $action                                     , $tmp_row);

        $all_rows .= $tmp_row;
    }
    return $all_rows;
}

/**
 * Erzeugt den "Call"-Button für einen Benutzer.
 */
function create_call_btn($btn_id)
{
    return '<button class="start-call-btn" id="start-call-btn-' . intval($btn_id) . '">Call</button>';
}

/**
 * Generiert die möglichen Aktionen (Ändern/Löschen) für jeden Benutzer.
 */
function get_action($in_current_user)
{
    return '<td>
                <a href="index.php?act=manage_user&user_id=' . $in_current_user->get_id() .'">Ändern</a> | 
                <a href="#" onclick="window.webrtcApp.ui.confirmDelete(\'index.php?act=delete_user&user_id=' . $in_current_user->get_id() .'\')">Löschen</a>
            </td>';
}

function act_heartbeat() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        

        $user_id = (int)$_SESSION['user_id'] ?? null;
        if (!$user_id) exit;

        $data = json_decode(file_get_contents("php://input"), true);
        $in_call = isset($data['in_call']) ? $data['in_call'] : false;

        $user_status = $in_call ? 'in_call' : 'online';

        $user = new User($user_id);
        $user->set_status($user_status);
        $user->save();
        exit;
    }
}
