<?php
namespace App\Controller;

use App\Model\User;
use App\Helper\Request;
use App\Helper\ViewHelper;

/**
 * SystemController – übernimmt alle ehemals globalen Systemfunktionen außer output.
 */
class SystemController
{
    /**
     * Gibt die Adminseite aus, optional mit Nachricht.
     * @param string $msg Optional anzuzeigende Nachricht
     * @return void
     */
    public function showAdmin($msg = "Willkommen im Admin Panel"): void
    {
        ViewHelper::output($msg);
    }

    /**
     * Generiert ein HTML-Dropdown-Menü (Select-Optionen) basierend auf einem Array.
     * @param array $dataArray Array für Optionen (Key => Value)
     * @param mixed $selectedId Vorzubelegende ID
     * @param bool $addEmpty Optionale Leerauswahl hinzufügen
     * @return string HTML-Optionen
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
     * @return void
     */
    public static function home(): void
    {
        ViewHelper::output('');
    }

    /**
     * Zeigt die Startseite oder das Chatfenster, abhängig vom Benutzerstatus.
     * @return void
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
