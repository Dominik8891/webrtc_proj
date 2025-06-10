<?php
namespace App\Controller;

use App\Model\User;
use App\Helper\Request;

/**
 * SystemController – übernimmt alle ehemals globalen Systemfunktionen außer output.
 */
class SystemController
{
    /**
     * Gibt die Adminseite aus, optional mit Nachricht.
     */
    public function showAdmin($msg = "Willkommen im Admin Panel"): void
    {
        \App\Helper\ViewHelper::output($msg);
    }

    /**
     * Generiert ein HTML-Dropdown-Menü (Select-Optionen) basierend auf einem Array.
     */
    public static function generateHtmlOptions($dataArray, $selectedId, $addEmpty = false): string
    {
        $outOpt = "";

        if ($addEmpty) {
            $outOpt .= '<option value=0>  -- KEINE --  </option>';
        }

        foreach ($dataArray as $key => $val) {
            $sel = ((string)$key === (string)$selectedId) ? "selected" : "";
            $outOpt .= '<option value="' . htmlspecialchars($key) . '" ' . $sel . '>' . htmlspecialchars($val) . ' </option>';
        }
        return $outOpt;
    }

    /**
     * Gibt die Startseite (Backend) oder das Chatfenster (Frontend), wenn der Benutzer eingeloggt ist.
     */
    public static function home(): void
    {
        \App\Helper\ViewHelper::output('');
    }

    /**
     * Zeigt die Startseite oder das Chatfenster, abhängig vom Benutzerstatus.
     */
    public function showStart(): void
    {
        $html = file_get_contents("assets/html/frontend/home.html");

        if (isset($_SESSION['user']['user_id'])) {
            $html = file_get_contents("assets/html/frontend/goto_chat.html");
        }

        output_fe($html);
    }
}
