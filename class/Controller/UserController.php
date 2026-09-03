<?php
namespace App\Controller;

use App\Model\User;
use App\Helper\Auth;
use App\Helper\Permission;
use App\Helper\Request;
use \App\Helper\ViewHelper;

/**
 * UserController – Verwaltung und Anzeige von Benutzern im Adminbereich.
 *
 * Der Zugang zu den Routen dieses Controllers wird in index.php anhand der
 * Rechte aus config/routes.php entschieden. Hier steht nur noch, was eine
 * Rechtetabelle nicht wissen kann: welche Spalten und Schaltflächen der
 * Aufrufer in der Benutzerliste sehen darf.
 */
class UserController
{
    /**
     * Verwaltung eines Benutzers (Anlegen/Bearbeiten).
     * Zeigt das User-Formular an, übernimmt Speichern bei "send".
     *
     * Zugang: Recht user.manage, geprüft in index.php. Hier stand vorher ein
     * Größenvergleich auf der Rollennummer der Sitzung, der eine Rangfolge
     * unterstellte, die es nicht gibt. Er liess ausser dem Admin auch den
     * Guide herein und wäre mit der neuen Nummernvergabe (Admin = 10)
     * vollends falsch geworden.
     *
     * @return void
     */
    public function manageUser()
    {
        $out = ViewHelper::template("assets/html/manage_user.html");

        // null als Default: die Zweigunterscheidung weiter unten unterscheidet
        // "Parameter fehlt" (null) von "Parameter ist leer". Mit dem
        // Standard-Leerstring waere $send immer !== null und der
        // Speichern-Zweig liefe bei jedem Seitenaufruf.
        $user_id  = Request::g('user_id', null);
        $send     = Request::g('send',    null);

        $tmp_user = new User(intval($user_id));
        $role     = SystemController::generateHtmlOptions($tmp_user->getAllUsertypesAsArray(), $tmp_user->getRoleId());

        $user_info  = " neu anlegen";

        if ($user_id !== null && $send === null) {
            $user_info  = $tmp_user->getId() . " (" . htmlspecialchars($tmp_user->getUsername()) . ") bearbeiten ";
        }
        elseif ($send !== null) {
            $sel_user   = new User(Request::g('id'));
            // null als Default fuer role, username und email: die Werte werden
            // unten in die Datenbank geschrieben. Ein fehlendes Feld darf den
            // vorhandenen Wert NICHT mit einem Leerstring ueberschreiben.
            //   role:     '' wuerde als type_id gespeichert und von MySQL im
            //             Non-Strict-Mode zu 0 gewandelt - das ist die Rolle
            //             Admin. Ein fehlendes Feld darf keine Rechte vergeben.
            //   username: '' wuerde den Benutzernamen leeren.
            //   email:    '' wuerde die Adresse leeren und beim zweiten Fall
            //             gegen den UNIQUE-Index auf user.email laufen.
            // pwd braucht kein null - die Pruefung in Zeile 54 faengt den
            // Leerstring bereits ab.
            $role       = Request::g('role',     null);
            $username   = Request::g('username', null);
            $email      = Request::g('email',    null);
            $pwd        = Request::g('pwd');

            // setRoleId() weist eine unbekannte Rolle ab. Ohne diese
            // Rueckmeldung liefe ein verstellter Formularwert stumm ins Leere
            // und der Benutzer behielte die alte Rolle, ohne dass es jemand
            // merkt.
            if ($role !== null && !$sel_user->setRoleId($role)) {
                error_log('manageUser: ungueltige Rolle ' . var_export($role, true)
                    . ' fuer Benutzer #' . $sel_user->getId() . ' abgewiesen');
            }
            if ($username !== null               ) $sel_user->setUsername($username);
            if ($email    !== null               ) $sel_user->setEmail($email);
            // pwdEncrypt() liegt in App\Model\User, nicht im SystemController -
            // dort hat die Methode nie existiert. Aufruf wie in
            // PasswordController.php:132 und :212 ueber die User-Instanz.
            if ($pwd      !== null && $pwd !== '') $sel_user->setPwd($sel_user->pwdEncrypt($pwd));

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
     *
     * Zugang: Recht user.list, geprüft in index.php.
     *
     * Ob die Verwaltungsspalten erscheinen, entscheidet das Recht
     * user.manage. Vorher wurde die Rollennummer direkt mit der 1 verglichen
     * - das ist die Guide-Rolle und nicht der Admin, und der strikte
     * Vergleich scheiterte zusätzlich, sobald PDO die Nummer als Zeichenkette
     * lieferte. Die Spalten waren dadurch für niemanden sichtbar.
     *
     * @return void
     */
    public function listUser()
    {
        $table_html = ViewHelper::template("assets/html/list_user.html");
        $user = new User(Auth::userId());

        $action = "";
        $email  = "";
        $new    = "";
        if (Auth::can(Permission::USER_MANAGE)) {
            $action = '<th>Aktionen</th>';
            $email  = '<th class="user_table_desktop">E-Mail</th>';
            // Der Akzent, nicht die Live-Farbe: Gruen bedeutet in dieser
            // Anwendung "ein Guide ist gerade erreichbar" (assets/css/theme.css).
            $new    = '<a href="index.php?act=manage_user" class="btn btn-primary btn-sm">Neuer Benutzer</a>';
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
     * Zugang: Recht user.delete, geprüft in index.php. Vorher hatte diese
     * Methode überhaupt keine Prüfung - weder auf eine Rolle noch auf eine
     * Anmeldung. Ein einziger Aufruf von index.php?act=delete_user&user_id=N
     * genügte, um ein beliebiges Konto zu löschen.
     *
     * Zusätzlich zwei fachliche Prüfungen, die kein Recht abbilden kann:
     * die ID muss zu einem Konto gehören, und das eigene Konto lässt sich
     * hier nicht löschen - der Admin würde sich sonst mit noch gültiger
     * Sitzung selbst entfernen.
     *
     * @return void
     */
    public function deleteUser()
    {
        $user_id = (int)Request::g('user_id');

        if ($user_id < 1 || $user_id === Auth::userId()) {
            error_log('deleteUser: unzulaessige Ziel-ID ' . $user_id
                . ' (Aufrufer ' . Auth::userId() . ')');
            $this->listUser();
            return;
        }

        try {
            $tmp_user = new User($user_id);
        } catch (\Exception $e) {
            error_log('deleteUser: ' . $e->getMessage());
            $this->listUser();
            return;
        }

        $tmp_user->del_it();
        $this->listUser();
    }

    /**
     * Heartbeat-Schnittstelle: "ein Browser dieses Kontos ist erreichbar".
     *
     * ER MELDET DIE ANMELDUNG, NICHT DIE BEREITSCHAFT. Das ist der Kern der
     * Trennung: Frueher setzte dieser Aufruf den Nutzer auf 'online', und
     * 'online' war zugleich das Kriterium, nach dem ein Standort gruen auf
     * der Karte stand. Damit entschied ein offener Tab darueber, ob jemand
     * angerufen wird - eine Aussage, die niemand getroffen hatte. Ueber die
     * Bereitschaft entscheidet jetzt allein setAvailability().
     *
     * Erwartet POST mit zwei Angaben:
     *
     *   in_call  Laeuft gerade ein Gespraech? Bestimmt, ob der Status
     *            'in_call' oder 'online' lautet.
     *   active   Hat der Nutzer seit dem letzten Takt die Anwendung wirklich
     *            BEDIENT - geklickt, getippt, gewischt - oder laeuft ein
     *            Gespraech? Nur dann wird eine laufende Bereitschaft
     *            verlaengert (assets/js/main.js sammelt das ein).
     *
     * Der Heartbeat allein verlaengert nichts. Ein Tab, der ueber Nacht offen
     * steht, schickt zwar weiter Heartbeats - er meldet damit aber nur, dass
     * der Browser laeuft, und laesst die Frist ablaufen.
     *
     * DIE ANTWORT TRAEGT DIE RESTZEIT. Der Schalter in der Kopfleiste zaehlt
     * lokal herunter und wird mit jedem Takt gegen den Server nachgezogen -
     * ohne eine zweite Abfrage. Damit merkt der Guide auch dann, dass seine
     * Bereitschaft abgelaufen ist, wenn er die Seite nur offen liegen hat.
     *
     * @return void
     */
    public function heartbeat()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

        $user_id = Auth::userId();
        if (!$user_id) exit;

        $data    = json_decode(file_get_contents("php://input"), true);
        $in_call = !empty($data['in_call']);
        $active  = !empty($data['active']);

        $user = new User($user_id);
        $user->setStatus($in_call ? 'in_call' : 'online');
        $user->save();

        // Bedienung ODER laufendes Gespraech verlaengert. Ein Gespraech zaehlt
        // mit, weil ein fuehrender Guide gerade beide Haende voll hat und
        // nicht mitten in einer Fuehrung von der Karte fallen soll.
        //
        // extendAvailability() schaltet NICHT ein: Ist die Frist bereits
        // abgelaufen, bleibt sie abgelaufen. Ein Klick nach dem Ablauf wuerde
        // den Guide sonst unbemerkt wieder anrufbar machen.
        if ($active || $in_call) {
            User::extendAvailability($user_id, self::availabilityTimeout());
        }

        header('Content-Type: application/json');
        echo json_encode([
            'status'            => 'ok',
            'available_seconds' => User::availableSeconds($user_id),
        ]);
        exit;
    }

    /**
     * Der Bereitschaftsschalter: "ich bin bereit zu fuehren" - und zurueck.
     *
     * EINE AUSDRUECKLICHE ENTSCHEIDUNG, und nur sie faerbt einen Standort auf
     * der Karte gruen. Ohne sie ist ein Standort ein Angebot ohne Guide, auch
     * wenn das Konto angemeldet ist.
     *
     * Erwartet POST mit "ready" (true = bereit, false = beenden). Beim
     * Einschalten laeuft die Frist aus config/presence.php ab jetzt; beim
     * Ausschalten wird sie sofort geloescht.
     *
     * DIESELBE ROUTE BEENDET AUCH BEIM SCHLIESSEN DER SEITE. Der Browser
     * schickt dafuer eine Beacon-Nachricht (navigator.sendBeacon), die auch
     * dann noch rausgeht, wenn der Tab schon zugeht. Beacons kommen ohne
     * JSON-Content-Type an - gelesen wird deshalb der rohe Rumpf und nicht
     * $_POST.
     *
     * @return void
     */
    public function setAvailability()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

        $user_id = Auth::userId();
        if (!$user_id) exit;

        $data = json_decode(file_get_contents("php://input"), true);

        // Fehlt die Angabe, wird BEENDET und nicht eingeschaltet. Eine
        // unvollstaendige Anfrage darf niemanden auf die Karte stellen; sie
        // von der Karte zu nehmen ist der harmlose Ausgang.
        $ready = is_array($data) && !empty($data['ready']);

        if ($ready) {
            User::startAvailability($user_id, self::availabilityTimeout());
        } else {
            User::endAvailability($user_id);
        }

        header('Content-Type: application/json');
        echo json_encode([
            'status'            => 'ok',
            'available_seconds' => User::availableSeconds($user_id),
        ]);
        exit;
    }

    /**
     * Die Dauer einer Bereitschaft in Sekunden.
     *
     * Holt sie aus config/presence.php - der einen Stelle, an der sie steht.
     * Hier steht bewusst keine Zahl: Sonst waere die Frist im Controller eine
     * andere als die, die der Browser fuer seine Restzeitanzeige bekommt
     * (App\Helper\ViewHelper::output).
     *
     * @return int
     */
    private static function availabilityTimeout(): int
    {
        $presence = require __DIR__ . '/../../config/presence.php';
        return (int)$presence['availability_timeout'];
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
     * Generiert die HTML-Zeilen für die Benutzerliste (private Hilfsmethode).
     *
     * @param User $in_user
     * @param array $in_user_ids
     * @return string
     */
    private function generateUserRows($in_user, $in_user_ids)
    {
        $row_html = ViewHelper::template("assets/html/list_user_row.html");
        $all_rows = "";

        foreach ($in_user_ids as $one_user_id) {
            if ($one_user_id == $in_user->getId()) continue;

            $tmp_user      = new User($one_user_id);
            $tmp_user_name = $tmp_user->getUsername();
            $action  = "";
            $email   = "";
            // Nebenaktion, also Symbol statt Text. aria-label und title sind
            // Pflicht: Ohne sie ist der Knopf weder vorlesbar noch erratbar.
            $message = '<div class="app-actions-cell">'
                     . '<button type="button" class="app-iconbtn app-iconbtn--chat start-chat-btn"'
                     . ' data-userid="' . intval($one_user_id) . '"'
                     . ' aria-label="Chat mit ' . htmlspecialchars($tmp_user_name) . '"'
                     . ' title="Chat"></button>'
                     . '</div>';

            // Auch hier entscheidet das Recht und nicht die Rollennummer:
            // der Vergleich mit der 1 traf den Guide statt den Admin.
            //
            // E-Mail und Aktionen liefern die GANZE Zelle oder gar nichts.
            // Vorher stand in der Zeilenvorlage ein festes <td> um den
            // E-Mail-Platzhalter: Wer die Liste ohne Verwaltungsrecht ansah,
            // bekam eine leere Zelle mehr als der Kopf Spalten hatte.
            if (Auth::can(Permission::USER_MANAGE)) {
                $action = $this->getAction($tmp_user);
                $email  = '<td class="user_table_desktop">'
                        . htmlspecialchars($tmp_user->getEmail()) . '</td>';
            }

            // Einmal lesen, zweimal verwenden - vorher stand hier zweimal
            // derselbe SELECT. Dazu die Bereitschaft: Sie ist eine ANDERE
            // Auskunft als der Status und wird deshalb getrennt geholt.
            $tmp_status = $tmp_user->getUserStatus($tmp_user->getId());
            $tmp_bereit = User::isAvailable($tmp_user->getId());

            $status   = $this->stateHtml($tmp_status, $tmp_bereit);
            $call_btn = $this->createCallBtn($tmp_user->getId(), $tmp_status);

            $tmp_row = str_replace("###STATUS###"   , $status                                    , $row_html);
            $tmp_row = str_replace("###CALL###"     , $call_btn                                  , $tmp_row);
            $tmp_row = str_replace("###USERNAME###" , htmlspecialchars($tmp_user->getUsername()) , $tmp_row);
            $tmp_row = str_replace("###EMAIL###"    , $email                                     , $tmp_row);
            $tmp_row = str_replace("###ACTION###"   , $action                                    , $tmp_row);
            $tmp_row = str_replace("###MESSAGE###"  , $message                                   , $tmp_row);

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
    private function createCallBtn($btn_id, $status = null)
    {
        // KEINE STANDORTKENNUNG. Diese Liste ruft eine Person an, nicht einen
        // Ort - der Server erkennt daran den Direktanruf und vergibt bei
        // Beteiligung eines Admins keine Fuehrungsrollen, sondern zweimal
        // "peer" (WebRTCController::callRoles). Die Knoepfe der Standortliste
        // und der Karte tragen dagegen ein data-locationid.

        // Der Akzent statt Gruen: Gruen heisst auf der Karte "Guide gerade
        // verfuegbar". Derselbe Farbton fuer einen Knopf, den es in jeder
        // Zeile gibt, wuerde die Bedeutung dort entwerten.
        //
        // Wer nicht erreichbar ist, laesst sich auch nicht anrufen - der Knopf
        // sagt das, statt einen Anruf ins Leere anzubieten.
        //
        // GEPRUEFT WIRD DIE ERREICHBARKEIT, NICHT DIE BEREITSCHAFT. Das ist an
        // dieser einen Stelle Absicht: Dieser Knopf loest einen Direktanruf
        // der Verwaltung aus, und der ist keine Fuehrung - beide Seiten
        // bekommen die Rolle "peer" und niemand wird gesteuert
        // (WebRTCController::callRoles). Fuer eine Rueckfrage der Moderation
        // muss sich niemand vorher auf bereit gestellt haben. Ueberall dort,
        // wo es um FUEHRUNGEN geht - Karte, Standortliste, Anruf von einem
        // Standort aus -, entscheidet dagegen die Bereitschaft.
        $erreichbar = ($status === 'online');

        // Den Akzent traegt nur der Knopf, der auch etwas tut. Ein gesperrter
        // Knopf in Akzentfarbe in jeder zweiten Zeile faerbt die ganze Liste
        // ein, ohne dass irgendwo etwas moeglich waere.
        return '<button class="btn btn-sm start-call-btn '
             . ($erreichbar ? 'btn-primary' : 'btn-secondary') . '"'
             . ' id="start-call-btn-' . intval($btn_id) . '"'
             . ($erreichbar ? '' : ' disabled aria-disabled="true"')
             . '>Anrufen</button>';
    }

    /**
     * Baut die Zustandsanzeige einer Zeile.
     *
     * Unterschieden wird ueber Form und Gewicht, nicht ueber Gruen und Rot:
     * Diese Farben tragen auf der Karte eine feste Bedeutung ("Guide
     * verfuegbar", "kein Guide vor Ort"). Die Gestaltung steht in
     * assets/css/theme.css unter .app-state.
     *
     * ZWEI AUSKUENFTE, NICHT EINE. Der Punkt sagt, ob ein Browser des Kontos
     * erreichbar ist; die Marke daneben sagt, ob der Guide sich auf bereit
     * gestellt hat. Das sind verschiedene Dinge, und die Verwaltung ist die
     * Stelle, an der man beide sehen will: "angemeldet, aber nicht bereit"
     * ist die Antwort auf die Frage, warum ein Standort grau bleibt, obwohl
     * der Guide erreichbar ist.
     *
     * Die Marke erscheint nur bei tatsaechlicher Bereitschaft. Ein zweites
     * Etikett "nicht bereit" in jeder zweiten Zeile waere Laerm - das Fehlen
     * der Marke sagt dasselbe.
     *
     * @param string|null $status Wert aus user.user_status
     * @param bool        $bereit Laeuft eine Bereitschaft? (user.available_until)
     * @return string HTML
     */
    private function stateHtml($status, $bereit = false)
    {
        if ($status === 'online') {
            $art = 'online';  $text = 'Online';
        } elseif ($status === 'in_call') {
            $art = 'busy';    $text = 'Im Gespräch';
        } else {
            $art = 'offline'; $text = 'Offline';
        }

        // Die Beschriftung steht in einem eigenen Element, damit sie auf
        // sehr schmalen Schirmen ausgeblendet werden kann - dort traegt der
        // Punkt die Auskunft allein (assets/css/theme.css, .app-state__text).
        // Ausgeblendet wird sie nur fuer das AUGE: Vorleseprogramme lesen sie
        // weiter, sonst bliebe vom Zustand gar nichts uebrig.
        $marke = $bereit
            ? '<span class="app-tag app-tag--ready" title="Hat sich auf bereit gestellt">Bereit</span>'
            : '';

        return '<span class="app-state app-state--' . $art . '" title="' . $text . '">'
             . '<span class="app-state__dot" aria-hidden="true"></span>'
             . '<span class="app-state__text">' . $text . '</span>'
             . '</span>' . $marke;
    }

    /**
     * Generiert die möglichen Aktionen (Ändern/Löschen) für jeden Benutzer (private Hilfsmethode).
     *
     * @param User $in_current_user
     * @return string
     */
    private function getAction($in_current_user)
    {
        $id   = intval($in_current_user->getId());
        $name = htmlspecialchars($in_current_user->getUsername());

        // Bearbeiten und Loeschen sind Nebenaktionen: Symbol ohne Rahmen,
        // Rahmen und Flaeche erst beim Ueberfahren. Der Name steht im
        // aria-label, damit ein Vorleseprogramm nicht dreissigmal
        // "Bearbeiten" ohne Bezug meldet.
        //
        // <a> statt <button>: Bearbeiten fuehrt auf eine Seite. Das Loeschen
        // fragt vorher zurueck - deshalb der Verweis ins Leere und die
        // Entscheidung im Bestaetigungsdialog.
        return '<td>
                    <div class="app-actions-cell">
                        <a href="index.php?act=manage_user&user_id=' . $id . '"
                           class="app-iconbtn app-iconbtn--edit"
                           aria-label="Benutzer ' . $name . ' bearbeiten"
                           title="Bearbeiten"></a>
                        <a href="#"
                           onclick="window.webrtcApp.ui.confirmDelete(\'index.php?act=delete_user&user_id=' . $id . '\'); return false;"
                           class="app-iconbtn app-iconbtn--delete app-iconbtn--danger"
                           aria-label="Benutzer ' . $name . ' löschen"
                           title="Löschen"></a>
                    </div>
                </td>';
    }
}
