<?php
namespace App\Helper;

use App\Model\User;

class ViewHelper
{
    /**
     * Ersetzt die ###CONTENT###-Platzhalter im Hauptlayout mit dem übergebenen Content und gibt das HTML aus.
     */
    public static function output($in_content)
    {
        $out = file_get_contents("assets/html/index.html"); 
        $out = str_replace("###CONTENT###", $in_content, $out);

        // Standardlinks vorbereiten
        $sign = "<a href='index.php?act=signup_page'>Sign Up</a>";
        $user_txt = "";
        $text = "<a href='index.php?act=login_page'>Login</a>";
        $call = "";
        $inner_call = "";
        $media = "";

        $logged_in = 'false';
        $user_role = null;
        $user_id_script = null;

        if (isset($_SESSION['user'])) {
            $user = new User($_SESSION['user']['user_id']);
            $user_txt  = "| <span> Sie sind angemeldet als: <b>" . htmlspecialchars($user->getUsername()) . "</b> </span>";
            $text = "<a href='index.php?act=logout'>Logout</a>";
            $sign = "<a href='index.php?act=list_user'>Benutzerliste</a>";
            $logged_in = 'true';
            $user_role = $user->getUsertype();
            $call       = file_get_contents('assets/html/call_controll.html');
            $inner_call = file_get_contents('assets/html/inner_call_controll.html');
            $media      = file_get_contents('assets/html/media.html');
            $user_id_script   = '<script>window.userId = ' . $_SESSION['user']['user_id'] . ';</script>';
        }
        
        $logged_in_script = '<script>window.isLoggedIn = ' . $logged_in . ';</script>' . $user_id_script;
        $user_role_script = '<script>window.userRole = "' . $user_role . '";</script>' . $logged_in_script;

        $out = str_replace("###CALL_CONTROLL###"        , $call             , $out);
        $out = str_replace("###INNER_CALL_CONTROLL###"  , $inner_call       , $out);
        $out = str_replace("###MEDIA###"                , $media            , $out);
        $out = str_replace("###USERSTATUS###"           , $user_role_script , $out);
        $out = str_replace("###LOGOUT###"               , $text             , $out);
        $out = str_replace("###USER###"                 , $user_txt         , $out);
        $out = str_replace("###REGISTER###"             , $sign             , $out);

        die($out); 
    }

    /**
     * Optional: Ausgabe für das Frontend.
     */
    public static function output_fe($in_content)
    {
        $out = file_get_contents("assets/html/frontend/index.html");
        $out = str_replace("###CONTENT###", $in_content, $out);
        die($out);
    }
}
