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
        $sign      = "<a href='index.php?act=signup_page' class='btn btn-success btn-sm'>Sign Up</a>";
        $user_txt  = "";
        $text      = "<a href='index.php?act=login_page' class='btn btn-outline-primary btn-sm'>Login</a>";
        $call      = "";
        $inner_call= "";
        $media     = "";

        $logged_in     = 'false';
        $user_role     = null;
        $user_id_script= null;

        // Prüfen, ob ein Nutzer eingeloggt ist
        if (isset($_SESSION['user'])) {
            $user = new User($_SESSION['user']['user_id']);
            // Begrüßungstext mit Username (XSS-sicher)
            $user_txt  = '<span class="fw-bold ms-2">Sie sind angemeldet als: <span class="text-primary">' . htmlspecialchars($user->getUsername()) . '</span></span>';
            $text      = "<a href='index.php?act=logout' class='btn btn-outline-primary btn-sm'>Logout</a>";
            $sign      = "<a href='index.php?act=list_user' class='btn btn-outline-primary btn-sm'>Benutzerliste</a>";
            $logged_in = 'true';
            $user_role = $user->getUsertype();

            // Zusätzliche Steuerelemente für eingeloggte User laden
            $call        = file_get_contents('assets/html/call_controll.html');
            self::checkTemplate($call, 'assets/html/call_controll.html');

            $inner_call  = file_get_contents('assets/html/inner_call_controll.html');
            self::checkTemplate($inner_call, 'assets/html/inner_call_controll.html');

            $media       = file_get_contents('assets/html/media.html');
            self::checkTemplate($media, 'assets/html/media.html');

            // User-ID als JS-Variable bereitstellen
            $user_id_script = '<script>window.userId = ' . $_SESSION['user']['user_id'] . ';</script>';
        }

        // JavaScript-Variablen für Frontend bereitstellen (Login-Status, User-ID, Rolle)
        $logged_in_script = '<script>window.isLoggedIn = ' . $logged_in . ';</script>' . $user_id_script;
        $user_role_script = '<script>window.userRole = "' . $user_role . '";</script>' . $logged_in_script;

        // Platzhalter im Template ersetzen
        $out = str_replace("###CALL_CONTROLL###"       , $call             , $out);
        $out = str_replace("###INNER_CALL_CONTROLL###" , $inner_call       , $out);
        $out = str_replace("###MEDIA###"               , $media            , $out);
        $out = str_replace("###USERSTATUS###"          , $user_role_script , $out);
        $out = str_replace("###LOGOUT###"              , $text             , $out);
        $out = str_replace("###USER###"                , $user_txt         , $out);
        $out = str_replace("###REGISTER###"            , $sign             , $out);

        // Ausgabe und Script-Beendigung
        die($out); 
    }

}
