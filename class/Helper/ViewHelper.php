<?php
namespace App\Helper;

use App\Model\User;

/**
 * Hilfsklasse für die View-Generierung.  
 * Fügt Content in HTML-Layouts ein und ersetzt Platzhalter durch dynamische Inhalte.
 */
class ViewHelper
{
    /**
     * Prüft, ob das Template erfolgreich geladen wurde.
     * Gibt bei Fehler einen Log-Eintrag aus und beendet das Skript mit einer Fehlermeldung.
     *
     * @param mixed $out      Rückgabewert von file_get_contents
     * @param string $template Dateipfad des Templates
     */
    public static function checkTemplate($out, $template) {
        if ($out === false) {
            error_log('Template konnte nicht geladen werden: ' . $template);
            die('Interner Fehler. Bitte versuchen Sie es später erneut.');
        }
    }

    /**
     * Baut das Benutzermenue der Kopfleiste.
     *
     * Enthaelt die Eintraege, die zum eigenen Konto gehoeren. Welche Route
     * dahinter wirklich erlaubt ist, entscheidet weiterhin index.php - ein
     * Eintrag hier ist Anzeige, keine Berechtigung.
     *
     * @param string $username Der anzuzeigende Name (wird maskiert)
     * @return string HTML
     */
    private static function userMenu($username): string
    {
        $name = htmlspecialchars($username);

        $eintraege = [
            'index.php?act=settings'       => 'Mein Konto',
            'index.php?act=get_all_chats'  => 'Alle Chats',
            'index.php?act=list_user'      => 'Benutzerliste',
        ];

        $links = '';
        foreach ($eintraege as $ziel => $titel) {
            $links .= '<a class="app-menu__item" href="' . $ziel . '">' . $titel . '</a>';
        }

        return '<details class="app-menu" id="user-menu">'
             .   '<summary class="app-menu__button">'
             .     '<span class="app-menu__avatar" aria-hidden="true">'
             .        htmlspecialchars(mb_strtoupper(mb_substr($username, 0, 1)))
             .     '</span>'
             .     '<span class="app-menu__name">' . $name . '</span>'
             .     '<span class="app-menu__caret" aria-hidden="true"></span>'
             .   '</summary>'
             .   '<div class="app-menu__list" role="menu">'
             .     '<div class="app-menu__head">Angemeldet als <strong>' . $name . '</strong></div>'
             .     $links
             .     '<div class="app-menu__sep"></div>'
             .     '<a class="app-menu__item app-menu__item--danger" href="index.php?act=logout">Abmelden</a>'
             .   '</div>'
             . '</details>';
    }

    /**
     * Ersetzt die ###CONTENT###-Platzhalter im Hauptlayout mit dem übergebenen Content und gibt das HTML aus.
     * Ergänzt außerdem Benutzerstatus, Login/Logout-Links, Call- und Mediensteuerung sowie User-Infos.
     *
     * @param string $in_content Inhalt, der ins Layout eingesetzt wird.
     * 
     * Platzhalter im Template:
     *   ###CONTENT###, ###CALL_CONTROLL###, ###INNER_CALL_CONTROLL###, ###MEDIA###,
     *   ###USERSTATUS###, ###LOGOUT###, ###USER###, ###REGISTER###
     */
    public static function output($in_content)
    {
        // Hauptlayout laden (enthält die Platzhalter)
        $out = file_get_contents("assets/html/index.html"); 
        $out = str_replace("###CONTENT###", $in_content, $out);

        // Standardlinks (nicht angemeldet)
        // Gruen ist in dieser Anwendung das Zeichen fuer "ein Guide ist jetzt
        // erreichbar" (siehe assets/css/theme.css). Deshalb traegt die
        // Registrierung den Akzent und nicht die Live-Farbe.
        $sign      = "<a href='index.php?act=signup_page' class='btn btn-primary btn-sm'>Registrieren</a>";
        $user_txt  = "";
        $text      = "<a href='index.php?act=login_page' class='btn btn-secondary btn-sm'>Anmelden</a>";
        $menu_html = "";
        $call      = "";
        $inner_call= "";
        $media     = "";

        $logged_in     = 'false';
        $user_role     = null;
        $user_role_id  = null;
        $user_id_script= null;

        // Prüfen, ob ein Nutzer eingeloggt ist
        if (Auth::isLoggedIn()) {
            $user = new User(Auth::userId());
            $logged_in = 'true';

            // Das Benutzermenue. Es ersetzt die frueheren Einzelknoepfe
            // "Mein Account", "Benutzerliste" und "Abmelden" in der
            // Kopfleiste - das sind seltene Aktionen, und nebeneinander
            // gestellt sahen sie so wichtig aus wie das Anrufen.
            //
            // Gebaut aus <details>/<summary> und nicht mit JavaScript: So
            // laesst es sich mit der Tastatur bedienen und geht auch dann
            // auf, wenn ein Skript nicht geladen wurde. Das Abmelden darf
            // nicht daran haengen, dass eine Bibliothek erreichbar war.
            // assets/js/ui.js schliesst es nur zusaetzlich beim Klick
            // daneben.
            $menu_html = self::userMenu($user->getUsername());

            // Fuer Gaeste bleiben die beiden Knoepfe; angemeldet sind sie im
            // Menue aufgehoben.
            $text = '';
            $sign = '';

            // Die Rolle kommt als usertype.id aus dem geladenen Benutzer und
            // wird ueber den zentralen Helfer normalisiert. Frueher stand hier
            // getUsertype(), also der rohe Name aus der Datenbank - genau der
            // ging in ui.js gegen kleingeschriebene Literale und traf nie zu
            // (Befund F-5).
            //
            // Gelesen wird die Rolle aus der Sitzung und nicht aus dem eben
            // geladenen Datensatz: Beides ist derselbe Wert, aber die Sitzung
            // ist die Quelle, gegen die auch index.php prueft. Zwei Quellen
            // koennten auseinanderlaufen.
            $user_role_id = Auth::roleId();
            $user_role    = Role::name($user_role_id);

            // Zusätzliche Steuerelemente für eingeloggte User laden
            $call        = file_get_contents('assets/html/call_controll.html');
            self::checkTemplate($call, 'assets/html/call_controll.html');

            $inner_call  = file_get_contents('assets/html/inner_call_controll.html');
            self::checkTemplate($inner_call, 'assets/html/inner_call_controll.html');

            $media       = file_get_contents('assets/html/media.html');
            self::checkTemplate($media, 'assets/html/media.html');

            // User-ID als JS-Variable bereitstellen
            $user_id_script = '<script>window.userId = ' . Auth::userId() . ';</script>';

            // Heartbeat-Takt aus derselben Konfiguration, aus der sich auch
            // der Cronjob seinen Timeout holt (config/presence.php). Sonst
            // waeren Takt und Timeout zwei unabhaengige Zahlen in zwei
            // Dateien, die niemand zusammen pflegt.
            $presence = require __DIR__ . '/../../config/presence.php';
            $user_id_script .= '<script>window.heartbeatIntervalMs = '
                . ((int)$presence['heartbeat_interval'] * 1000) . ';</script>';
        }

        // JavaScript-Variablen für Frontend bereitstellen (Login-Status, User-ID, Rolle)
        //
        // Neben Name und ID der Rolle gehen die Rechte mit ins Frontend. Sie
        // kommen aus derselben Rechtetabelle, gegen die index.php prueft -
        // eine zweite Rollentabelle in JavaScript koennte auseinanderlaufen.
        //
        // window.userCan entscheidet nur ueber die ANZEIGE. Ein Knopf, der
        // hier nicht erscheint, ist keine Absicherung: Die verbindliche
        // Pruefung steht in index.php und passiert erneut, wenn die Route
        // wirklich aufgerufen wird.
        $can = [
            'offerLocation' => Auth::can(Permission::LOCATION_OFFER),
            'becomeGuide'   => Role::mayBecomeGuide($user_role_id),
            'blockLocation' => Auth::can(Permission::LOCATION_BLOCK),
            'manageUsers'   => Auth::can(Permission::USER_MANAGE),
        ];

        $logged_in_script = '<script>window.isLoggedIn = ' . $logged_in . ';</script>' . $user_id_script;
        $user_role_script = '<script>'
            . 'window.userRole = ' . json_encode($user_role) . ';'
            . 'window.userRoleId = ' . ($user_role_id === null ? 'null' : (int)$user_role_id) . ';'
            . 'window.userCan = ' . json_encode($can) . ';'
            . '</script>' . $logged_in_script;

        // Platzhalter im Template ersetzen
        $out = str_replace("###CALL_CONTROLL###"       , $call             , $out);
        $out = str_replace("###INNER_CALL_CONTROLL###" , $inner_call       , $out);
        $out = str_replace("###MEDIA###"               , $media            , $out);
        $out = str_replace("###USERSTATUS###"          , $user_role_script , $out);
        $out = str_replace("###LOGOUT###"              , $text             , $out);
        $out = str_replace("###USER###"                , $menu_html        , $out);
        $out = str_replace("###REGISTER###"            , $sign             , $out);

        // Ausgabe und Script-Beendigung
        die($out); 
    }

}
