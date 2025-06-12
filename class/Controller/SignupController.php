<?php
namespace App\Controller;

use App\Model\User;
use App\Helper\Request;
use App\Helper\ViewHelper;

class SignupController
{
    /**
     * Zeigt das Registrierungsformular an.
     */
    public function showSignupForm(): void
    {
        $html = file_get_contents('assets/html/signup.html');
        // Fehler-Platzhalter leeren
        $html = str_replace('###ERROR###', '', $html);
        ViewHelper::output($html);
    }

    /**
     * Verarbeitet eine Nutzer-Registrierung.
     */
    public function handleSignup(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username   = trim(htmlspecialchars($this->g('username')));
            $email      = trim(htmlspecialchars($this->g('email')));
            $pwd        = $this->g('pwd');
            $pwd_scnd   = $this->g('pwd_scnd');

            $error = "";

            // Passwort-Doppelprüfung
            if ($pwd !== $pwd_scnd) {
                $error = "pw";
            } else {
                $user = new User();
                if ($user->usernameExists($username)) {
                    $error = "username";
                } elseif ($user->emailExists($email)) {
                    $error = "email";
                } else {
                    // User anlegen
                    $user_id = $user->register($username, $email, $pwd);
                    if ($user_id > 0) {
                        // Registrierung erfolgreich: Weiterleitung zum Login
                        header("Location: index.php?act=login_page");
                        exit;
                    } else {
                        $error = "unknown";
                    }
                }
            }

            // Fehlerfall: Formular mit Fehler anzeigen
            $html = file_get_contents('assets/html/signup.html');
            switch ($error) {
                case "username":
                    $msg = "Der Benutzername ist bereits vergeben.";
                    break;
                case "email":
                    $msg = "Die E-Mail-Adresse ist bereits vergeben.";
                    break;
                case "pw":
                    $msg = "Die Passwörter stimmen nicht überein.";
                    break;
                default:
                    $msg = "Ein unbekannter Fehler ist aufgetreten.";
            }
            $html = str_replace('###ERROR###', $msg, $html);
            ViewHelper::output($html);
        } else {
            // Wenn kein POST-Request: Zur Registrierungseite weiterleiten
            header("Location: index.php?act=signup_page");
            exit;
        }
    }

    /**
     * Holt einen Wert aus $_REQUEST (Utility, weil du es bisher so genutzt hast)
     */
    private function g($assoc_index)
    {
        return isset($_REQUEST[$assoc_index]) ? $_REQUEST[$assoc_index] : null;
    }

    /**
     * Gibt das HTML aus und beendet das Skript
     */
    private function output($in_content)
    {
        // Hier kannst du auch dein system.php-output nachbauen, falls du willst!
        $out = file_get_contents("assets/html/index.html");
        $out = str_replace("###CONTENT###", $in_content, $out);
        die($out);
    }
}
