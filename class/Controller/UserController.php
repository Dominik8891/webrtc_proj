<?php
namespace App\Controller;

use App\Model\User;
use App\Helper\Request;
use \App\Helper\ViewHelper;

/**
 * UserController – Verwaltung und Anzeige von Benutzern im Adminbereich.
 */
class UserController
{
    /**
     * Verwaltung eines Benutzers (Anlegen/Bearbeiten).
     * Zeigt das User-Formular an, übernimmt Speichern bei "send".
     * Zugang nur für eingeloggte Admins (RoleId <= 1).
     *
     * @return void
     */
    public function manageUser()
    {
        if (!isset($_SESSION['user']['user_id'])) {
            SystemController::home();
            exit;
        }
        if ($_SESSION['user']['role_id'] > 1) {
            SystemController::home();
            exit;
        }

        $out = file_get_contents("assets/html/manage_user.html");

        $user_id  = Request::g('user_id');
        $send     = Request::g('send');

        $tmp_user = new User(intval($user_id));
        $role     = SystemController::generateHtmlOptions($tmp_user->getAllUsertypesAsArray(), $tmp_user->getRoleId());

        $user_info  = " neu anlegen";

        if ($user_id !== null && $send === null) {
            $user_info  = $tmp_user->getId() . " (" . htmlspecialchars($tmp_user->getUsername()) . ") bearbeiten ";
        }
        elseif ($send !== null) {
            $sel_user   = new User(Request::g('id'));
            $role       = Request::g('role');
            $username   = Request::g('username');
            $email      = Request::g('email');
            $pwd        = Request::g('pwd');

            $sel_user->setRoleId($role);
            if ($username !== null               ) $sel_user->setUsername($username);
            if ($email    !== null               ) $sel_user->setEmail($email);
            if ($pwd      !== null && $pwd !== '') $sel_user->setPwd(SystemController::pwdEncrypt($pwd));

            $sel_user->save();
            $this->listUser();
            return;
        }
        

        // Platzhalter ersetzen
        $out = str_replace("###ID###"       , $tmp_user->getId()                         , $out);
        $out = str_replace("###ROLE###"     , $role                                       , $out);
        $out = str_replace("###USERNAME###" , htmlspecialchars($tmp_user->getUsername()) , $out);
        $out = str_replace("###EMAIL###"    , htmlspecialchars($tmp_user->getEmail())    , $out);
        $out = str_replace("###PASSWORD###" , ""                                          , $out);
        $out = str_replace("###USER_INFO###", $user_info                                  , $out);

        ViewHelper::output($out);
    }

    /**
     * Zeigt eine Liste aller Benutzer im System an.
     * Nur sichtbar für eingeloggte Nutzer.
     *
     * @return void
     */
    public function listUser()
    {
        if (!isset($_SESSION['user']['user_id'])) 
        {
            SystemController::home();
        }
        $table_html = file_get_contents("assets/html/list_user.html");
        $user = new User($_SESSION['user']['user_id']);

        $action = "";
        $email  = "";
        $new    = "";
        if ($user->getRoleId() === 1) {
            $action = "<th>Aktion</th>";
            $email  = '<th class="user_table_desktop">Email</th>';
            $new    = '<a href="index.php?act=manage_user" class="btn btn-success btn-sm">Neuen Benutzer anlegen</a>';
        }

        $all_user_ids = $user->getAll();
        $all_rows = $this->generateUserRows($user, $all_user_ids);

        $out = str_replace("###EMAIL###"     , $email    , $table_html  );
        $out = str_replace("###ACTION###"    , $action   , $out         );
        $out = str_replace("###NEW###"       , $new      , $out         );
        $out = str_replace("###USER_ROWS###" , $all_rows , $out         );
        ViewHelper::output($out);
    }

    /**
     * Löscht einen Benutzer aus dem System (setzt gelöscht-Flag).
     *
     * @return void
     */
    public function deleteUser()
    {
        $tmp_user = new User(Request::g('user_id'));
        $tmp_user->del_it();
        $this->listUser();
    }

    /**
     * Heartbeat-Schnittstelle zum Setzen des Online-Status (AJAX).
     * Erwartet POST mit "in_call".
     *
     * @return void
     */
    public function heartbeat()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $user_id = (int)($_SESSION['user']['user_id'] ?? null);
            if (!$user_id) exit;

            $data = json_decode(file_get_contents("php://input"), true);
            $in_call = isset($data['in_call']) ? $data['in_call'] : false;

            $user_status = $in_call ? 'in_call' : 'online';

            $user = new User($user_id);
            $user->setStatus($user_status);
            $user->save();
            exit;
        }
    }

    /**
     * API: Gibt den Benutzernamen für eine User-ID zurück (JSON).
     * Erwartet POST mit user_id im Payload.
     *
     * @return void
     */
    public function getUsername()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents("php://input"), true);

            if ($data) {
                $user = new User($data);
                echo $user->getUsername();
                exit;
            }
            echo false;
        }
    }

    /**
     * API: Speichert die übermittelte Location für den aktuellen User (Latitude/Longitude).
     * Erwartet POST mit JSON {"lat":..., "lon":...}
     *
     * @return void
     */
    public function saveLocation()
    {
        if (!isset($_SESSION['user']['user_id'])) {
            http_response_code(401);
            exit('Nicht eingeloggt!');
        }

        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        $lat = isset($data['lat']) ? $data['lat'] : null;
        $lon = isset($data['lon']) ? $data['lon'] : null;
        error_log('kam was? ' . $lat . ' & ' . $lon);

        if ($lat !== null && $lon !== null && is_numeric($lat) && is_numeric($lon)) {
            $user = new User($_SESSION['user']['user_id']);
            $result = $user->saveLocation($lat, $lon);
            if ($result) {
                http_response_code(200);
                echo 'ok';
            } else {
                http_response_code(500);
                echo 'Fehler beim Speichern.';
            }
        } else {
            http_response_code(400);
            echo 'Ungültige Daten.';
        }
        exit;
    }

    /**
     * Generiert die HTML-Zeilen für die Benutzerliste (private Hilfsmethode).
     *
     * @param User $in_user
     * @param array $in_user_ids
     * @return string
     */
    private function generateUserRows($in_user, $in_user_ids)
    {
        $row_html = file_get_contents("assets/html/list_user_row.html");
        $all_rows = "";

        foreach ($in_user_ids as $one_user_id) {
            if ($one_user_id == $in_user->getId()) continue;

            $tmp_user = new User($one_user_id);
            $action  = "";
            $email   = "";
            $message = "<button class='btn btn-primary start-chat-btn' data-userid='{$one_user_id}'>Chat</button>";

            if ($in_user->getRoleId() === 1) {
                $action = $this->getAction($tmp_user);
                $email  = htmlspecialchars($tmp_user->getEmail());
            }
            $status = "Offline";
            $dot = '<span class="status-dot me-1" style="display:inline-block; width:14px; height:14px; border-radius:50%; background:#dc3545;"></span>';
            if($tmp_user->getUserStatus($tmp_user->getId()) === "online") {
                $status = "Online";
                $dot = '<span class="status-dot me-1" style="display:inline-block; width:14px; height:14px; border-radius:50%; background:#28a745;"></span>';
            } elseif ($tmp_user->getUserStatus($tmp_user->getId()) === "in_call") {
                $status = "In Call";
                $dot = '<span class="status-dot me-1" style="display:inline-block; width:14px; height:14px; border-radius:50%; background:#ffc107;"></span>';
            }
            $call_btn = $this->createCallBtn($tmp_user->getId());

            $tmp_row = str_replace("###ID###"       , $tmp_user->getId()                                , $row_html);
            $tmp_row = str_replace("###STATUS###"   , $status                                           , $tmp_row);
            $tmp_row = str_replace("###CALL###"     , $call_btn                                         , $tmp_row);
            $tmp_row = str_replace("###USERNAME###" , $dot . htmlspecialchars($tmp_user->getUsername()) , $tmp_row);
            $tmp_row = str_replace("###EMAIL###"    , $email                                            , $tmp_row);
            $tmp_row = str_replace("###ACTION###"   , $action                                           , $tmp_row);
            $tmp_row = str_replace("###MESSAGE###"  , $message                                          , $tmp_row);

            $all_rows .= $tmp_row;
        }
        return $all_rows;
    }

    /**
     * Erzeugt den "Call"-Button für einen Benutzer (private Hilfsmethode).
     *
     * @param int $btn_id
     * @return string
     */
    private function createCallBtn($btn_id)
    {
        return '<button class="btn btn-success start-call-btn btn-sm" id="start-call-btn-' . intval($btn_id) . '">Call</button>';
    }

    /**
     * Generiert die möglichen Aktionen (Ändern/Löschen) für jeden Benutzer (private Hilfsmethode).
     *
     * @param User $in_current_user
     * @return string
     */
    private function getAction($in_current_user)
    {
        return '<td>
                    <a href="index.php?act=manage_user&user_id=' . $in_current_user->getId() . '" class="btn btn-warning btn-sm me-2">Ändern</a>
                    <a href="#" onclick="window.webrtcApp.ui.confirmDelete(\'index.php?act=delete_user&user_id=' . $in_current_user->getId() . '\')" class="btn btn-danger btn-sm">Löschen</a>
                </td>';
    }
}
