<?php
namespace App\Controller;

use App\Helper\ViewHelper;
use App\Helper\Request;
use App\Model\User;
use App\Model\Email;
use App\Model\PdoConnect;

class PasswordController
{
    // 1. Schritt: "Passwort vergessen?" - Formular anzeigen und E-Mail abfragen
    public function showForgotPwForm(): void
    {
        $html = file_get_contents('assets/html/forgot_pw.html');
        $html = str_replace('###PW_FORGOT_MSG###', '', $html);
        ViewHelper::output($html);
    }

    // 2. Schritt: Reset-Token erzeugen und Mail schicken
    public function handleForgotPassword(): void
    {
        $email = trim(Request::g('email'));
        $msg = "Falls diese E-Mail in unserem System hinterlegt ist, erhältst du eine Nachricht zum Zurücksetzen.";

        // User suchen (Antwort immer gleich, kein User-Enum möglich!)
        $stmt = PdoConnect::$connection->prepare("SELECT id FROM user WHERE email = :email AND deleted = 0");
        $stmt->bindParam(":email", $email);
        $stmt->execute();
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);

            // Vorherige Resets löschen
            $del = PdoConnect::$connection->prepare("DELETE FROM password_resets WHERE user_id = :uid");
            $del->bindParam(":uid", $user['id']);
            $del->execute();

            $ins = PdoConnect::$connection->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (:uid, :token, :exp)");
            $ins->bindParam(":uid", $user['id']);
            $ins->bindParam(":token", $token);
            $ins->bindParam(":exp", $expires);
            $ins->execute();

            // Mail verschicken (ersetze durch dein Mail-Utility)
            $resetLink = "https://localhost/rctprojnew/index.php?act=reset_pw_page&token=$token";
            Email::sendMail($email, 
                "Hallo,\n\nKlicke auf den folgenden Link, um dein Passwort zu ändern:\n\n$resetLink\n\nDieser Link ist 1 Stunde gültig.", "Passwort zurücksetzen");
            error_log("Passwort-Reset angefordert für UserID {$user['id']} ({$email}) von IP {$_SERVER['REMOTE_ADDR']} um ".date('c'));
        } else {
            error_log("Passwort-Reset-Versuch für NICHT vorhandene E-Mail {$email} von IP {$_SERVER['REMOTE_ADDR']} um ".date('c'));
        }

        $html = file_get_contents('assets/html/forgot_pw.html');
        $html = str_replace('###PW_FORGOT_MSG###', $msg, $html);
        ViewHelper::output($html);
    }

    // 3. Schritt: Reset-Formular anzeigen
    public function showResetForm(): void
    {
        $token = Request::g('token');
        $stmt = PdoConnect::$connection->prepare("SELECT user_id FROM password_resets WHERE token = :token AND expires_at > NOW()");
        $stmt->bindParam(":token", $token);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $msg = "";
        if ($row) {
            $html = file_get_contents('assets/html/reset_pw.html');
            $html = str_replace('###TOKEN###', htmlspecialchars($token), $html);
            $html = str_replace('###PW_RESET_MSG###', $msg, $html);
            ViewHelper::output($html);
        } else {
            $msg = "Der Link ist ungültig oder abgelaufen.";
            $html = file_get_contents('assets/html/reset_pw.html');
            $html = str_replace('###TOKEN###', '', $html);
            $html = str_replace('###PW_RESET_MSG###', $msg, $html);
            ViewHelper::output($html);
        }
    }

    // 4. Schritt: Neues Passwort setzen
    public function handleResetPassword(): void
    {
        $token = Request::g('token');
        $pwd1 = Request::g('pwd1');
        $pwd2 = Request::g('pwd2');
        $msg = "";

        if ($pwd1 !== $pwd2 || strlen($pwd1) < 8) {
            $msg = "Die Passwörter stimmen nicht überein oder sind zu kurz.";
            $html = file_get_contents('assets/html/reset_pw.html');
            $html = str_replace('###TOKEN###', htmlspecialchars($token), $html);
            $html = str_replace('###PW_RESET_MSG###', $msg, $html);
            ViewHelper::output($html);
            return;
        }

        // Gültigen Token suchen
        $stmt = PdoConnect::$connection->prepare(
            "SELECT user_id FROM password_resets WHERE token = :token AND expires_at > NOW()");
        $stmt->bindParam(":token", $token);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row) {
            // Passwort ändern
            $user = new User($row['user_id']);
            $user->setPwd($user->pwdEncrypt($pwd1));
            $user->save();

            // Token löschen
            $del = PdoConnect::$connection->prepare("DELETE FROM password_resets WHERE user_id = :uid");
            $del->bindParam(":uid", $row['user_id']);
            $del->execute();

            error_log("Passwort erfolgreich zurückgesetzt für UserID {$row['user_id']} von IP {$_SERVER['REMOTE_ADDR']} um ".date('c'));
            // Weiterleitung mit Erfolgsmeldung
            header("Location: index.php?act=login_page&pw_reset=ok");
            exit;
        } else {
            $msg = "Der Link ist ungültig oder abgelaufen.";
            $html = file_get_contents('assets/html/reset_pw.html');
            $html = str_replace('###TOKEN###', '', $html);
            $html = str_replace('###PW_RESET_MSG###', $msg, $html);
            error_log("FEHLGESCHLAGENER Passwort-Reset mit ungültigem Token {$token} von IP {$_SERVER['REMOTE_ADDR']} um ".date('c'));
            ViewHelper::output($html);
        }
    }

    public function showChangePwForm() 
    {
        $username = Request::g('username');
        $html = file_get_contents('assets/html/change_pw.html');
        $html = str_replace('###USERNAME###', $username, $html);
        $html = str_replace('###PW_CHANGE_MSG###', '', $html);
        ViewHelper::output($html);
    }

    public function handleChangePassword()
    {
        $username   = Request::g('username');
        $pw_old     = Request::g('pw-old');
        $pwd1       = Request::g('pwd1');
        $pwd2       = Request::g('pwd2');
        $msg = "";
        

        $stmt = PdoConnect::$connection->prepare("SELECT * FROM user WHERE username = :username");
        $stmt->bindParam(":username", $username);
        $stmt->execute();
        $result = $stmt->fetch();

        $pepper = $_ENV['PEPPER'];
        if (!$pepper) {
            error_log("PEPPER nicht gesetzt!");
            return false;
        }
        $pwd_peppered = hash_hmac("sha256", $pw_old, $pepper);

        if (!password_verify($pwd_peppered, $result['pwd'])) {
            $msg = "Das alte Passwort ist nicht korrekt!";
            $html = file_get_contents('assets/html/change_pw.html');
            $html = str_replace('###USERNAME###', $username, $html);
            $html = str_replace('###PW_CHANGE_MSG###', $msg, $html);
            ViewHelper::output($html);
            return;
        }

        if ($pwd1 !== $pwd2 || strlen($pwd1) < 8) {
            $msg = "Die Passwörter stimmen nicht überein oder sind zu kurz.";
            $html = file_get_contents('assets/html/change_pw.html');
            $html = str_replace('###USERNAME###', $username, $html);
            $html = str_replace('###PW_CHANGE_MSG###', $msg, $html);
            ViewHelper::output($html);
            return;
        }

        $user = new User($result['id']);
        $user->setPwd($user->pwdEncrypt($pwd1));
        $user->save();

        error_log("Passwort erfolgreich geändert für Benutzer mit UserID {$result['id']} von IP {$_SERVER['REMOTE_ADDR']} um " .date('c'));
        // Weiterleitung mit Erfolgsmeldung
        header("Location: index.php?act=settings&change=1");
        exit;

        
    }
}
