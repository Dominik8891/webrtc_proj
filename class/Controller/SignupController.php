<?php
namespace App\Controller;

use App\Model\User;
use App\Model\Email;
use App\Model\PdoConnect;
use App\Helper\Request;
use App\Helper\ViewHelper;
use App\Controller\EmailVerificationController;

/**
 * Controller für Signup und Signup-Fehlermeldungen.
 */
class SignupController
{
    /**
     * Zeigt das Registrierungsformular an.
     * @return void
     */
    public function showSignupForm(): void
    {
        $html = file_get_contents('assets/html/signup.html');
        // Fehler-Platzhalter leeren
        $html = str_replace('###ERROR###', '', $html);
        ViewHelper::output($html);
    }

    /**
     * Verarbeitet eine Nutzer-Registrierung (Validierung und Anlage).
     * Gibt Fehler zurück oder registriert den User, ggf. inkl. E-Mail-Verifikation.
     * @return void
     */
    public function handleSignup(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username   = trim(REQUEST::g('username'));
            $email      = trim(REQUEST::g('email'));
            $pwd        =      REQUEST::g('pwd');
            $pwd_scnd   =      REQUEST::g('pwd_scnd');

            $error = "";

            // --- Eingabe validieren ---
            if ($pwd !== $pwd_scnd) {
                $error = "pw";
            } elseif (!preg_match('/^[\w]{3,20}$/', $username)) {
                $error = "username_invalid";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = "email_invalid";
            } elseif (strlen($pwd) < 8) {
                $error = "pwd_short";
            } else {
                $user = new User();
                $user->setUsername($username);
                $user->setEmail($email);
                if ($user->usernameExists()) {
                    $error = "username";
                } elseif ($user->emailExists()) {
                    $error = "email";
                } else {
                    // User anlegen
                    $user_id = $user->register($username, $email, $pwd);
                    if ($user_id > 0) {
                        /*
                         *
                         * Viewhelper rausnehmen und Emailverification rein wenn auf Online Server
                         * da ich keinen SMTP service habe der das kann.
                         * Lokal läuft es aber
                         * 
                        */
                        ViewHelper::output($out);
                        //(new EmailVerificationController)::sendVerification($user_id);
                        exit;
                    } else {
                        $error = "unknown";
                        error_log("Fehler bei der Registrierung für User $username/$email");
                    }
                }
            }

            $this->outputSignupError($error);
        } else {
            // Wenn kein POST-Request: Zur Registrierungseite weiterleiten
            header("Location: index.php?act=signup_page");
            exit;
        }
    }

    /**
     * Gibt das Registrierungsformular mit Fehlerhinweis aus.
     * @param string $error Fehlercode für Fehlermeldung
     * @return void
     */
    public function outputSignupError($error): void
    {
        // Fehlerfall: Formular mit Fehler anzeigen
        $html = file_get_contents('assets/html/signup.html');
        switch ($error) {
            case "username":         $msg = "Der Benutzername ist bereits vergeben."; break;
            case "email":            $msg = "Die E-Mail-Adresse ist bereits vergeben."; break;
            case "pw":               $msg = "Die Passwörter stimmen nicht überein."; break;
            case "username_invalid": $msg = "Ungültiger Benutzername. Nur Buchstaben/Zahlen/Unterstrich, 3-20 Zeichen."; break;
            case "email_invalid":    $msg = "Bitte gib eine gültige E-Mail-Adresse ein."; break;
            case "pwd_short":        $msg = "Das Passwort muss mindestens 8 Zeichen lang sein."; break;
            default:                 $msg = "Ein unbekannter Fehler ist aufgetreten.";
        }
        $html = str_replace('###ERROR###', $msg, $html);
        ViewHelper::output($html);
    }
}
