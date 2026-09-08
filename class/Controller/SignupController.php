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

                // DREI ZUSTAENDE JE ANGABE, NICHT ZWEI (App\Model\User).
                //
                // Die Vorabpruefung fragte frueher nur nach LEBENDEN Konten
                // ("AND deleted = 0"). Der eindeutige Index in der Datenbank
                // kennt dieses Kennzeichen aber nicht: Die Adresse eines
                // geloeschten Kontos ist weiter belegt. Die Pruefung sagte
                // also "frei", der INSERT scheiterte, und beim Nutzer kam
                // "Ein unbekannter Fehler ist aufgetreten." an.
                //
                // Jetzt bekommt er die Auskunft, die zu seiner Lage passt -
                // und die ist bei einem geloeschten Konto eine andere: Gegen
                // eine vergebene Adresse hilft "Passwort vergessen", gegen die
                // eines geloeschten Kontos hilft nur der Betreiber.
                $nameStand = $user->usernameStand();
                // Die zweite Abfrage nur, wenn die erste nichts gefunden hat.
                // Steht der Name schon fest als Fehler, wird $emailStand unten
                // gar nicht mehr gelesen - dann waere sie eine Abfrage fuer
                // eine Antwort, die niemand ansieht.
                $emailStand = $nameStand === User::KENNUNG_FREI
                    ? $user->emailStand()
                    : User::KENNUNG_FREI;

                if ($nameStand === User::KENNUNG_VERGEBEN) {
                    $error = "username";
                } elseif ($nameStand === User::KENNUNG_GELOESCHT) {
                    $error = "username_geloescht";
                } elseif ($emailStand === User::KENNUNG_VERGEBEN) {
                    $error = "email";
                } elseif ($emailStand === User::KENNUNG_GELOESCHT) {
                    $error = "email_geloescht";
                } else {
                    // User anlegen. Der Grund eines Fehlschlags kommt als
                    // zweiter Wert zurueck - ohne ihn liesse sich "die Angabe
                    // ist vergeben" nicht von "der Server hat eine Stoerung"
                    // unterscheiden, und genau diese Unterscheidung fehlte.
                    $grund   = null;
                    $user_id = $user->register($username, $email, $pwd, $grund);
                    if ($user_id > 0) {
                        // Das Konto steht - jetzt zaehlt es gegen die
                        // Kontogrenze. Nicht frueher: siehe Methodenkopf.
                        RateLimit::verbuchen('signup', $teile);

                        // DIE BESTAETIGUNGSMAIL - WIEDER IN BETRIEB.
                        //
                        // Hier stand ein auskommentierter Aufruf mit der
                        // Begruendung, es gebe keinen SMTP-Server. Der Aufruf
                        // laeuft jetzt immer; ob dabei wirklich eine Mail
                        // hinausgeht, entscheidet MAIL_ENABLED - und ist
                        // ausgeschaltet, steht der Bestaetigungslink im
                        // Logfile (App\Model\Email::sendMail). Der Ablauf ist
                        // also in BEIDEN Faellen derselbe, und das ist der
                        // Punkt: Ein Weg, der nur in einer Betriebsart
                        // ueberhaupt ausgefuehrt wird, ist beim Umschalten
                        // ungeprueft.
                        //
                        // DER AUFRUF STAND AUCH SYNTAKTISCH FALSCH DA:
                        // "(new EmailVerificationController)::sendVerification()"
                        // mischt Instanz und statischen Aufruf. Genau so sieht
                        // Code aus, den seit Monaten kein Uebersetzer mehr
                        // angesehen hat.
                        //
                        // OHNE ARGUMENT WAERE ES DAS ANGEMELDETE KONTO - und
                        // angemeldet ist nach der Registrierung niemand.
                        // Deshalb die frische Kennung. Sie umgeht zugleich die
                        // Bremse fuer den Mailversand, und das ist richtig so:
                        // Die Registrierung selbst ist bereits begrenzt (siehe
                        // Methodenkopf), und ein frisch angelegtes Konto soll
                        // seine erste Mail in jedem Fall bekommen.
                        //
                        // sendVerification() gibt die Bestaetigungsseite selbst
                        // aus - dieselbe Vorlage, die hier vorher geladen
                        // wurde. Zwei Ausgaben waeren zwei Seiten in einer
                        // Antwort.
                        (new EmailVerificationController())->sendVerification($user_id);
                        exit;
                    } else {
                        // DER EINDEUTIGE INDEX HAT ZUGESCHLAGEN, obwohl die
                        // Pruefung ein paar Zeilen weiter oben "frei" sagte.
                        // Dazwischen liegt ein Fenster, in dem sich ein
                        // zweiter Aufruf denselben Namen sichern kann - genau
                        // dafuer ist der Index da. Das ist keine Stoerung,
                        // sondern die Regel, und deshalb bekommt der Nutzer
                        // hier auch keine Fehlermeldung, sondern die
                        // Aufforderung, es noch einmal zu versuchen.
                        //
                        // WELCHE der beiden Angaben es war, sagt die Meldung
                        // nicht: Das stuende nur im Klartext der
                        // Treibermeldung, und darauf eine Fallunterscheidung
                        // zu bauen hiesse, den Wortlaut einer fremden
                        // Fehlermeldung zur Schnittstelle zu erklaeren (siehe
                        // App\Model\User::register).
                        $error = ($grund === User::REG_VERGEBEN) ? "vergeben_rennen" : "unknown";

                        // Adresse nur maskiert loggen - der User wurde nicht angelegt,
                        // eine UserID gibt es an dieser Stelle noch nicht.
                        error_log("Fehler bei der Registrierung für User $username/"
                            . LogHelper::maskEmail($email) . " (Grund: " . ($grund ?? 'unbekannt') . ")");
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
            // DIE BEIDEN MELDUNGEN ZUM GELOESCHTEN KONTO SIND VERSCHIEDEN,
            // weil der Nutzer verschieden viel dagegen tun kann.
            //
            // Einen Benutzernamen sucht er sich einfach neu aus - dafuer
            // braucht er niemanden. Seine E-Mail-Adresse dagegen hat er nur
            // die eine; sagt man ihm dort nur "vergeben", steht er vor einer
            // Tuer ohne Klinke. Deshalb nennt diese Meldung als einzige den
            // Betreiber.
            //
            // KEINE ADRESSE UND KEIN FORMULAR IM TEXT: Wohin man sich wendet,
            // steht nicht im Code. Sobald es eine Kontaktseite gibt, gehoert
            // sie hierher verlinkt - bis dahin waere eine erfundene Adresse
            // schlechter als der allgemeine Hinweis.
            case "username_geloescht":
                $msg = "Dieser Benutzername gehört zu einem gelöschten Konto und lässt "
                     . "sich nicht neu vergeben. Bitte wählen Sie einen anderen.";
                break;
            case "email_geloescht":
                $msg = "Zu dieser E-Mail-Adresse gab es bereits ein Konto, das gelöscht "
                     . "wurde. Sie lässt sich deshalb nicht erneut verwenden. Bitte "
                     . "nutzen Sie eine andere Adresse oder wenden Sie sich an den Betreiber.";
                break;
            // Der Rennfall: Zwischen Pruefung und Anlage hat sich jemand
            // anderes die Angabe gesichert. Nicht "Fehler", sondern "gleich
            // noch einmal" - beim zweiten Versuch greift die Vorabpruefung und
            // sagt genau, welche der beiden Angaben es war.
            case "vergeben_rennen":
                $msg = "Benutzername oder E-Mail-Adresse wurde soeben vergeben. "
                     . "Bitte versuchen Sie es noch einmal.";
                break;
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
