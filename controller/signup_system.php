<?php

/**
 * Zeigt das Registrierungsformular an.
 */
function act_signup_page()
{
    $html = file_get_contents('assets/html/signup.html');
    // Fehler-Platzhalter leeren
    $html = str_replace('###ERROR###', '', $html);
    output($html);
}

/**
 * Verarbeitet eine Nutzer-Registrierung.
 */
function act_signup()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username   = trim(htmlspecialchars(g('username')));
        $email      = trim(htmlspecialchars(g('email')));
        $pwd        = g('pwd');
        $pwd_scnd   = g('pwd_scnd');

        $error = "";

        // Passwort-Doppelprüfung
        if ($pwd !== $pwd_scnd) {
            $error = "pw";
        } else {
            $user = new User();
            if ($user->username_exists($username)) {
                $error = "username";
            } elseif ($user->email_exists($email)) {
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
        output($html);
    } else {
        // Wenn kein POST-Request: Zur Registrierungseite weiterleiten
        header("Location: index.php?act=signup_page");
        exit;
    }
}
