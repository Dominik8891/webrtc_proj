<?php
namespace App\Controller;

use App\Model\User;
use App\Model\RateLimit;
use App\Helper\Auth;
use App\Helper\Request;
use App\Helper\ViewHelper;

/**
 * Controller für Login, Logout und Login-Fehlermeldungen.
 */
class LoginController
{
    /**
     * Zeigt das Loginformular an.
     * @return void
     */
    public function showLoginForm(): void
    {
        $html = ViewHelper::template('assets/html/login.html');
        $html = str_replace('###LOGIN_ERROR###', '', $html);
        ViewHelper::output($html);
    }

    /**
     * Verarbeitet einen Login-Versuch inkl. Brute-Force- und Lockout-Logik.
     * Bei Erfolg Weiterleitung; bei Fehler Anzeige mit Fehlermeldung.
     * @return void
     */
    public function handleLogin(): void
    {
        $username = trim(Request::g('username'));
        $pwd = Request::g('pwd');
        $ip = $_SERVER['REMOTE_ADDR'];

        /*if (strlen($username) < 3 || strlen($pwd) < 8) {
            $this->outputLoginError();
            return;
        }*/

        // --- Die Bremse ---------------------------------------------------
        //
        // WAS HIER FRUEHER STAND, UND WARUM ES WEG IST
        // --------------------------------------------
        // Zwei Zaehler in $_SESSION - $_SESSION['login_attempts'] und
        // $_SESSION['login_blocked_until'] - plus die Zahlen 5 und 300 als
        // lokale Variablen direkt darueber.
        //
        // EINE SESSION STEHT IM COOKIE DES AUFRUFERS. Wer es verwarf, fing
        // bei null an; ein Skript, das gar keine Cookies annimmt, bekam bei
        // jedem Versuch eine frische Session und hatte deshalb nie ein
        // Limit. Gezaehlt wurde damit nicht der Angreifer, sondern der
        // ehrliche Nutzer, der sein Passwort dreimal falsch tippt - die
        // Bremse traf ausschliesslich den, gegen den sie nicht gedacht war.
        //
        // Der Zaehler liegt jetzt in der Datenbank (App\Model\RateLimit,
        // migrations/018), und die Grenzen stehen in config/limits.php und
        // nicht mehr hier. Gebremst wird an DREI Schranken zugleich: eng auf
        // dem Paar aus Konto und IP, weit auf dem Konto und auf der IP
        // allein - warum, steht in der Konfiguration.
        $teile = ['konto' => $username, 'ip' => RateLimit::ip()];

        $rest = RateLimit::restsperre('login', $teile);
        if ($rest > 0) {
            $this->outputLoginError(
                'Zu viele Fehlversuche. Bitte ' . RateLimit::wartehinweis($rest) . ' warten.'
            );
            return;
        }

        $user = new User();

        if ($user->login($username, $pwd)) {
            $userDetails = $user->getUserDetails();
            /*
             *
             *  Deaktivier lassen solange kein eigener SMTP SERVER
             * 
             * if ($userDetails['email_verified'] != 1) {
             *   $error_msg = '<p>Bitte bestätige zuerst deine E-Mail-Adresse.</p>' . "<a href='index.php?act=send_email_verify'>Email erneut senden!</a>";
             *   $this->outputLoginError($error_msg);
             *   return;
             * }
            */ 
            if ($user->getTotpEnabled()) {
                $_SESSION['2fa_userid'] = $user->getId();
                header("Location: index.php?act=2fa_verify_page");
                exit;
            }
            session_regenerate_id(true); // Session-Fixation verhindern!
            // Einzige Stelle fuer den Aufbau der Sitzungsdaten - inklusive
            // normalisierter Rolle und Kennzeichnung des Aufbaus.
            Auth::establish($user);

            // Der Login war erfolgreich - der Fehlversuchszaehler gehoert
            // weg, bevor irgendein Weg diese Methode verlaesst.
            // continueAfterLogin() kehrt nicht zurueck, hinter dem Aufruf
            // duerfen also keine noetigen Zeilen mehr stehen.
            //
            // Weggeraeumt werden nur die Schranken am KONTO. Die IP-Schranke
            // bleibt stehen: Sie zaehlt die Adresse und nicht dieses Konto -
            // sonst koennte ein Angreifer mit einem einzigen eigenen Konto
            // seinen IP-Zaehler beliebig oft zuruecksetzen. Welche das sind,
            // entscheidet config/limits.php ('erfolg_loescht'), nicht diese
            // Zeile.
            RateLimit::zuruecksetzen('login', $teile);

            self::continueAfterLogin();
        } else {
            // Logging jedes Fehlversuchs
            error_log("Fehlgeschlagener Loginversuch für $username von IP $ip");

            $rest = RateLimit::verbuchen('login', $teile);

            // DIE MELDUNG NENNT DIE ZAHL DER RESTVERSUCHE NICHT MEHR.
            // "Noch 3 Versuch(e)" sagte dem, der durchprobiert, wie viel
            // Luft er hat und ab wann er die Verbindung wechseln muss - eine
            // Auskunft, die nur der Angreifer braucht. Der ehrliche Nutzer
            // erfaehrt, was er wissen muss: dass es falsch war, und im
            // Sperrfall, wie lange er warten muss.
            $this->outputLoginError($rest > 0
                ? 'Zu viele Fehlversuche. Bitte ' . RateLimit::wartehinweis($rest) . ' warten.'
                : 'Benutzername oder Passwort falsch.');
        }
    }

    /**
     * Was nach einer erfolgreichen Anmeldung passiert.
     *
     * Steht hier und nicht zweimal ausgeschrieben, weil es zwei Wege in die
     * angemeldete Sitzung gibt: das Loginformular und - bei eingeschaltetem
     * zweitem Faktor - TwoFactorController::handle2FAVerify. Beide sollen
     * dasselbe tun, und das ist heute: auf die Startseite fuehren.
     *
     * WAS HIER FRUEHER STAND, UND WARUM ES WEG IST
     * --------------------------------------------
     * Zwei ganzseitige Fragen, noch bevor der Nutzer die Anwendung ueberhaupt
     * gesehen hatte.
     *
     *   1. DIE GUIDE-FRAGE. "Moechten Sie Guide werden?" ist eine
     *      Entscheidung ueber eine Rolle, in der man sich von Fremden vor Ort
     *      steuern laesst - und kuenftig haengt daran eine Abrechnung. Diese
     *      Frage direkt nach der ersten Anmeldung zu stellen, heisst sie zu
     *      stellen, bevor jemand weiss, worum es geht. Sie liegt jetzt dort,
     *      wo man sie sucht, wenn man sie sich stellt: in den Einstellungen
     *      und auf dem Knopf der Kopfleiste ("Jetzt Tour-Guide werden!",
     *      assets/js/ui.js). Die Seite selbst ist unveraendert
     *      (App\Controller\GuideController).
     *
     *   2. DIE STANDORTABFRAGE. Sie schrieb ueber die Route save_location
     *      nach user.latitude/longitude - Spalten, die keine einzige
     *      Lesestelle hat. Begruendet war sie mit einer Umkreissuche, die es
     *      nicht gibt. Dialog und Route sind entfallen.
     *
     * @return never
     */
    public static function continueAfterLogin(): void
    {
        header('Location: index.php?act=home');
        exit;
    }

    /**
     * Gibt das Loginformular mit einer Fehlermeldung aus.
     * @param string $msg
     * @return void
     */
    public function outputLoginError($msg = 'Benutzername oder Passwort falsch.'): void
    {
        $html = ViewHelper::template('assets/html/login.html');
        $html = str_replace('###LOGIN_ERROR###', $msg, $html);
        ViewHelper::output($html);
    }

    /**
     * Loggt den Benutzer aus und löscht die Session.
     * @return void
     */
    public function handleLogout(): void
    {
        // Vor dem Verwerfen der Session den Benutzer offline setzen. Ohne das
        // blieb er bis zum naechsten Durchlauf des Cronjobs
        // (cron/check_online_status.php) in der Standortuebersicht als online
        // stehen - und ohne eingerichteten Cron dauerhaft.
        //
        // ZWEI ZUSTAENDE, ZWEI SCHREIBVORGAENGE. Der Status sagt "kein
        // Browser mehr erreichbar", die Bereitschaft sagt "will gerade
        // fuehren" - und beides endet mit dem Abmelden. Die Bereitschaft
        // wuerde sonst bis zum Ablauf ihrer Frist stehen bleiben und einen
        // laengst abgemeldeten Guide anrufbar halten, sobald er sich das
        // naechste Mal anmeldet.
        $user_id = (int)($_SESSION['user']['user_id'] ?? 0);
        if ($user_id > 0) {
            try {
                (new User())->updateUserStatus($user_id, 'offline');
                User::endAvailability($user_id);
            } catch (\Exception $e) {
                // Ein Fehler beim Statuswechsel darf das Abmelden nicht
                // verhindern - die Session wird in jedem Fall verworfen.
                error_log('Logout: Status konnte nicht auf offline gesetzt werden: ' . $e->getMessage());
            }
        }

        // Session löschen und Benutzer abmelden
        session_destroy();
        // Optional: Session-Daten löschen (z. B. Cookies)
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        // Weiterleitung zur Login-Seite
        header("Location: index.php?act=login_page");
        exit;
    }
}
