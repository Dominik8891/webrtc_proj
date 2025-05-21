<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

#################################################################################################### 
################################## signup backend ################################################## 

/**
 * Funktion zur Verarbeitung der Benutzeranmeldung.
 * Diese Funktion registriert einen neuen Benutzer, nachdem überprüft wurde, ob Benutzername und E-Mail-Adresse
 * bereits existieren und ob die eingegebenen Passwörter übereinstimmen.
 */
function act_signup()
{
    $username = htmlspecialchars(g('username'));
    $pwd      = htmlspecialchars(g('pwd'));

    // Wenn Benutzername und Passwort vorhanden sind, wird der Anmeldeprozess gestartet
    if($username != null && $pwd != null)
    {
        // Holt die Benutzerinformationen aus den GET-Parametern und filtert sie, um XSS-Angriffe zu verhindern
        $email    = htmlspecialchars(g('email'));
        $pwd_scnd = htmlspecialchars(g('pwd_scnd'));

        // Erstellt ein neues User-Objekt
        $user = new User();

        // Überprüft, ob der Benutzername bereits existiert
        if($user->check_if_username_exists($username))
        {
            // Wenn der Benutzername existiert, wird der Benutzer zur Anmeldeseite mit einem Fehler weitergeleitet
            header("Location: index.php?act=signup_page&error=username");
            die;
        }
        // Überprüft, ob die E-Mail-Adresse bereits existiert
        if($user->check_if_email_exists($email))
        {
            // Wenn die E-Mail-Adresse existiert, wird der Benutzer zur Anmeldeseite mit einem Fehler weitergeleitet
            header("Location: index.php?act=signup_page&error=email");
            die;
        }
        // Überprüft, ob die beiden eingegebenen Passwörter übereinstimmen
        if($pwd == $pwd_scnd)
        {
            // Setzt die Benutzerdaten im User-Objekt
            $user->set_username($username);
            $user->set_email($email);
            // Verschlüsselt das Passwort und speichert es
            $user->set_pwd(pwd_encrypt($pwd));
            // Speichert den neuen Benutzer in der Datenbank
            $user->save();
        } 
        else
        {
            // Wenn die Passwörter nicht übereinstimmen, wird der Benutzer zur Anmeldeseite mit einem Fehler weitergeleitet

            header("Location: index.php?act=signup_page&error=pw");
            die;
        }
    }
    // Nach erfolgreicher Anmeldung wird der Benutzer zum Chat weitergeleitet
    home();
}

/**
 * Funktion zur Anzeige der Anmeldeseite.
 * Wenn ein Fehler aufgetreten ist, wird ein Fehler-Skript generiert, um dem Benutzer den Fehler anzuzeigen.
 */
function act_signup_page()
{
    // Lädt das HTML-Template für die Anmeldeseite und überprüft, ob das Laden erfolgreich war
    $out = @file_get_contents("assets/html/signup.html");
    if ($out === false) {
        die("Fehler: Die Anmeldeseite konnte nicht geladen werden.");
    }

    // Initialisiert eine leere Fehlernachricht
    $error = "";

    // Holt den Fehlercode aus den GET-Parametern, wenn vorhanden
    $error_msg = g('error');

    // Wenn eine Fehlermeldung vorhanden ist, wird das entsprechende Skript generiert
    if (!empty($error_msg)) {
        $error = "<script>" . generateErrorScript($error_msg) . "</script>";
    }

    // Ersetzt den Fehler-Platzhalter im HTML-Template mit dem generierten Fehler-Skript (falls vorhanden)
    $out = str_replace("###ERROR###", $error, $out);

    // Gibt die finale Seite aus
    output($out);
}


/**
 * Verschlüsselt ein Passwort mit einem "Pepper" und dem Argon2I-Algorithmus.
 *
 * @param string $in_pwd Das Passwort, das verschlüsselt werden soll.
 * @return string Das verschlüsselte Passwort.
 */
function pwd_encrypt($in_pwd)
{
    // Lädt die Konfigurationsdatei, die den "Pepper" enthält
    $config = include 'config/config.php';
    $pepper = $config['pepper'];

    // Kombiniert das Passwort mit dem "Pepper" und hasht es mit HMAC SHA-256
    $pwd_peppered = hash_hmac("sha256", $in_pwd, $pepper);

    // Verschlüsselt das gepefferte Passwort mit dem Argon2I-Algorithmus
    $pwd_hashed = password_hash($pwd_peppered, PASSWORD_ARGON2I);

    // Gibt das verschlüsselte Passwort zurück
    return $pwd_hashed;
}

/**
 * Funktion zur Generierung des Fehler-Skripts.
 * Ersetzt den Platzhalter "###ERROR###" im JavaScript mit der tatsächlichen Fehlermeldung.
 */
function generateErrorScript($error_msg)
{
    $script = file_get_contents("assets/js/signup_error.js");
    return str_replace("###ERROR###", htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'), $script);
}