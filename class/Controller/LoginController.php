<?php
namespace App\Controller;

use App\Model\User;
use App\Helper\Request;
use App\Helper\ViewHelper;

class LoginController
{
    /**
     * Zeigt das Loginformular an.
     */
    public function showLoginForm(): void
    {
        $html = file_get_contents('assets/html/login.html');
        $html = str_replace('###LOGIN_ERROR###', '', $html);
        ViewHelper::output($html);
    }

    /**
     * Verarbeitet einen Login-Versuch.
     */
    public function handleLogin(): void
    {
        $maxAttempts = 5;
        $lockoutTime = 300; // 5 Minuten

        $username = trim(Request::g('username'));
        $pwd = Request::g('pwd');
        $ip = $_SERVER['REMOTE_ADDR'];

        /*if (strlen($username) < 3 || strlen($pwd) < 8) {
            $this->outputLoginError();
            return;
        }*/

        // --- Brute-Force Schutz initialisieren ---
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = [];
        }
        if (!isset($_SESSION['login_blocked_until'])) {
            $_SESSION['login_blocked_until'] = [];
        }

        // --- Blockierung prüfen ---
        if (
            isset($_SESSION['login_blocked_until'][$username]) &&
            $_SESSION['login_blocked_until'][$username] > time()
        ) {
            $wait = $_SESSION['login_blocked_until'][$username] - time();
            $this->outputLoginError("Zu viele Fehlversuche. Bitte warte $wait Sekunden.");
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
            $_SESSION['user'] = [
                'user_id'       => $user->getId(),
                'username'      => $user->getUsername(),
                'email'         => $user->getEmail(),
                'role_id'       => $user->getRoleId(),
            ];

            if ($user->getRoleId() < 3 && !isset($_SESSION['location_prompt_shown'])) {
                $_SESSION['location_prompt_shown'] = true;
                $html = file_get_contents('assets/html/location_prompt.html');
                ViewHelper::output($html);
                exit;
            }

            unset($_SESSION['login_attempts'][$username]);
            unset($_SESSION['login_blocked_until'][$username]);
            header("Location: index.php?act=home");
            exit;
        } else {
            // Logging jedes Fehlversuchs
            error_log("Fehlgeschlagener Loginversuch für $username von IP $ip");

            if (!isset($_SESSION['login_attempts'][$username])) {
                $_SESSION['login_attempts'][$username] = 1;
            } else {
                $_SESSION['login_attempts'][$username]++;
            }

            if ($_SESSION['login_attempts'][$username] >= $maxAttempts) {
                $_SESSION['login_blocked_until'][$username] = time() + $lockoutTime;
                $this->outputLoginError("Zu viele Fehlversuche. Account für 5 Minuten gesperrt.");
            } else {
                $rest = $maxAttempts - $_SESSION['login_attempts'][$username];
                $this->outputLoginError("Benutzername oder Passwort falsch. Noch $rest Versuch(e).");
            }
        }
    }

    public function outputLoginError($msg = 'Benutzername oder Passwort falsch.'): void
    {
        $html = file_get_contents('assets/html/login.html');
        $html = str_replace('###LOGIN_ERROR###', $msg, $html);
        ViewHelper::output($html);
    }

    /**
     * Loggt den Benutzer aus.
     */
    public function handleLogout(): void
    {
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
