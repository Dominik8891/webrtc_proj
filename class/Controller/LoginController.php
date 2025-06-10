<?php
namespace App\Controller;

use App\Model\User;
use App\Helper\Request;

class LoginController
{
    /**
     * Zeigt das Loginformular an.
     */
    public function showLoginForm(): void
    {
        $html = file_get_contents('assets/html/login.html');
        $html = str_replace('###LOGIN_ERROR###', '', $html);
        \App\Helper\ViewHelper::output($html);
    }

    /**
     * Verarbeitet einen Login-Versuch.
     */
    public function handleLogin(): void
    {
        $user = new User();

        $username = trim(htmlspecialchars(Request::g('username')));
        $pwd = Request::g('pwd');

        if ($user->login($username, $pwd)) {
            $_SESSION['user'] = [
                'user_id'       => $user->getId(),
                'username'      => $user->getUsername(),
                'email'         => $user->getEmail(),
                'role_id'       => $user->getRoleId(),
            ];
            header("Location: index.php?act=home");
            exit;
        } 

        $html = file_get_contents('assets/html/login.html');
        $html = str_replace('###LOGIN_ERROR###', 'Benutzername oder Passwort falsch.', $html);
        \App\Helper\ViewHelper::output($html);
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

    /**
     * Gibt das HTML aus und beendet das Skript.
     */
    private function output($in_content): void
    {
        $out = file_get_contents("assets/html/index.html");
        $out = str_replace("###CONTENT###", $in_content, $out);
        die($out);
    }
}
