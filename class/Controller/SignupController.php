<?php
namespace App\Controller;

use App\Model\User;
use App\Model\Email;
use App\Model\PdoConnect;
use App\Model\RateLimit;
use App\Helper\Request;
use App\Helper\ViewHelper;
use App\Helper\LogHelper;
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
        $html = ViewHelper::template('assets/html/signup.html');
        // Fehler-Platzhalter leeren
        $html = str_replace('###ERROR###', '', $html);
        ViewHelper::output($html);
    }

    /**
     * Verarbeitet eine Nutzer-Registrierung (Validierung und Anlage).
     * Gibt Fehler zurück oder registriert den User, ggf. inkl. E-Mail-Verifikation.
     *
     * DIE BREMSE, UND WARUM SIE ZWEITEILIG IST
     * ----------------------------------------
     * Bis hierher waren Konten unbegrenzt und kostenlos. Das ist nicht nur
     * fuer sich genommen ein Befund - es ist die Grundlage der uebrigen: Jede
     * Beschraenkung, die an einem Konto haengt (Anfragen, Bewertungen,
     * Nachrichten), ist wertlos, solange das naechste Konto einen Klick
     * entfernt ist.
     *
     * Gebremst wird deshalb an ZWEI Aktionen (App\Model\RateLimit,
     * config/limits.php):
     *
     *   'signup'           zaehlt ANGELEGTE KONTEN, und zwar erst NACH dem
     *                      Anlegen. Wuerde schon der Versuch zaehlen, koestete
     *                      jeder Tippfehler ein Konto aus dem Kontingent: Wer
     *                      sein Passwort dreimal falsch wiederholt, koennte
     *                      sich anschliessend nicht mehr registrieren.
     *
     *   'signup_formular'  zaehlt JEDES abgeschickte Formular, ob gueltig
     *                      oder nicht. Ohne diese zweite Aktion liesse sich
     *                      das Formular beliebig oft abschicken, solange nur
     *                      nie ein Konto entsteht - und genau das ist der
     *                      Weg, auf dem sich abfragen laesst, WELCHE
     *                      Benutzernamen und E-Mail-Adressen es schon gibt:
     *                      Die Antworten "bereits vergeben" unterscheiden
     *                      sich von allen anderen.
     *
     * Gezaehlt wird je IP - eine andere Angabe gibt es an dieser Stelle
     * nicht, denn wer sich registriert, hat noch kein Konto.
     *
     * @return void
     */
    public function handleSignup(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $teile = ['ip' => RateLimit::ip()];

            // Erst die Kontogrenze, dann die Formulargrenze: Die erste ist
            // die Aussage, um die es geht ("von hier kommen genug Konten"),
            // die zweite nur ihr Schutz gegen Haemmern.
            foreach (['signup', 'signup_formular'] as $aktion) {
                $rest = RateLimit::restsperre($aktion, $teile);
                if ($rest > 0) {
                    error_log("Registrierung gesperrt ($aktion) von IP {$teile['ip']}");
                    $this->outputSignupError('gesperrt', RateLimit::wartehinweis($rest));
                    return;
                }
            }

            // Der Versuch ist verbucht, bevor irgendetwas geprueft wird -
            // sonst waere die Grenze durch ungueltige Eingaben zu umgehen.
            RateLimit::verbuchen('signup_formular', $teile);

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
                        // Das Konto steht - jetzt zaehlt es gegen die
                        // Kontogrenze. Nicht frueher: siehe Methodenkopf.
                        RateLimit::verbuchen('signup', $teile);

                        /*
                         *
                         * Viewhelper rausnehmen und Emailverification rein wenn auf Online Server
                         * da ich keinen SMTP service habe der das kann.
                         * Lokal läuft es aber
                         * 
                        */
                        // Bestaetigungsseite laden. Genau das macht auch
                        // EmailVerificationController::sendVerification() in der
                        // auskommentierten Zeile darunter - nur zusaetzlich mit
                        // Mailversand. Beim Deaktivieren des Mailversands ist diese
                        // Zuweisung verlorengegangen, $out war dadurch undefiniert.
                        $out = ViewHelper::template('assets/html/signup_complete.html');
                        ViewHelper::output($out);
                        //(new EmailVerificationController)::sendVerification($user_id);
                        exit;
                    } else {
                        $error = "unknown";
                        // Adresse nur maskiert loggen - der User wurde nicht angelegt,
                        // eine UserID gibt es an dieser Stelle noch nicht.
                        error_log("Fehler bei der Registrierung für User $username/" . LogHelper::maskEmail($email));
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
     *
     * @param string $error   Fehlercode für Fehlermeldung
     * @param string $warten  Nur bei 'gesperrt': die Wartezeit als Satzteil
     *                        (App\Model\RateLimit::wartehinweis). Steht als
     *                        eigener Parameter und nicht als fertige Meldung
     *                        im Fehlercode, damit der Wortlaut aller
     *                        Meldungen an dieser einen Stelle bleibt.
     * @return void
     */
    public function outputSignupError($error, string $warten = ''): void
    {
        // Fehlerfall: Formular mit Fehler anzeigen
        $html = ViewHelper::template('assets/html/signup.html');
        switch ($error) {
            case "username":         $msg = "Der Benutzername ist bereits vergeben."; break;
            case "email":            $msg = "Die E-Mail-Adresse ist bereits vergeben."; break;
            case "pw":               $msg = "Die Passwörter stimmen nicht überein."; break;
            case "username_invalid": $msg = "Ungültiger Benutzername. Nur Buchstaben/Zahlen/Unterstrich, 3-20 Zeichen."; break;
            case "email_invalid":    $msg = "Bitte gib eine gültige E-Mail-Adresse ein."; break;
            case "pwd_short":        $msg = "Das Passwort muss mindestens 8 Zeichen lang sein."; break;
            // Nicht "zu viele Konten von deiner Adresse": Das erklaert dem,
            // der es darauf anlegt, woran die Bremse haengt.
            case "gesperrt":         $msg = "Zu viele Registrierungsversuche. Bitte " . $warten . " warten."; break;
            default:                 $msg = "Ein unbekannter Fehler ist aufgetreten.";
        }
        $html = str_replace('###ERROR###', $msg, $html);
        ViewHelper::output($html);
    }
}
