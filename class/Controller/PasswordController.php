<?php
namespace App\Controller;

use App\Helper\Auth;
use App\Helper\ViewHelper;
use App\Helper\Request;
use App\Helper\LogHelper;
use App\Helper\Url;
use App\Model\User;
use App\Model\Email;
use App\Model\PdoConnect;

/**
 * Controller Passwort-Reset und Ändern 
 */
class PasswordController
{
    /**
     * Zeigt das "Passwort vergessen?"-Formular an.
     * @return void
     */
    public function showForgotPwForm(): void
    {
        $html = ViewHelper::template('assets/html/forgot_pw.html');
        $html = str_replace('###PW_FORGOT_MSG###', '', $html);
        ViewHelper::output($html);
    }

    /**
     * Verarbeitet das Absenden des "Passwort vergessen?"-Formulars, erzeugt Token und sendet Mail.
     * Antwort ist immer gleich, um Enumeration zu verhindern.
     * @return void
     */
    public function handleForgotPassword(): void
    {
        $email = trim(Request::g('email'));
        $msg = "Falls diese E-Mail in unserem System hinterlegt ist, erhältst du eine Nachricht zum Zurücksetzen.";

        // User suchen (Antwort immer gleich, kein User-Enum möglich!)
        $stmt = PdoConnect::$connection->prepare("SELECT id FROM user WHERE email = :email AND deleted = 0");
        $stmt->bindParam(":email", $email);
        $stmt->execute();
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Die Basisadresse steht in der Konfiguration (APP_BASE_URL, siehe
        // .env.example) und wird VOR dem Anlegen des Tokens geholt: Fehlt
        // sie, gibt es keinen brauchbaren Link - dann soll auch kein Token in
        // die Datenbank, das den bestehenden ueberschreibt und niemandem
        // nutzt. Nach aussen bleibt die Antwort dieselbe wie immer, der Grund
        // steht im Log.
        if ($user && Url::base() === null) {
            error_log("Passwort-Reset fuer UserID {$user['id']} nicht verschickt: "
                . 'APP_BASE_URL fehlt oder ist unbrauchbar.');
            // Hier endet der Vorgang - und nicht im Else-Zweig weiter unten,
            // der eine nicht vorhandene Adresse protokollieren wuerde. Nach
            // aussen ist kein Unterschied zu sehen: Die Seite mit der immer
            // gleichen Meldung kommt wie sonst auch.
            $html = ViewHelper::template('assets/html/forgot_pw.html');
            $html = str_replace('###PW_FORGOT_MSG###', $msg, $html);
            ViewHelper::output($html);
            return;
        }

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

            // Mail verschicken. Die Adresse kommt aus der Konfiguration und
            // ausdruecklich NICHT aus dem Host-Header der Anfrage - sonst
            // koennte der Anstossende den Link auf einen eigenen Server
            // umbiegen (App\Helper\Url).
            $resetLink = Url::to("index.php?act=reset_pw_page&token=$token");
            Email::sendMail($email, 
                "Hallo,\n\nKlicke auf den folgenden Link, um dein Passwort zu ändern:\n\n$resetLink\n\nDieser Link ist 1 Stunde gültig.", "Passwort zurücksetzen");
            // E-Mail-Adresse NICHT loggen - die UserID identifiziert den Vorgang eindeutig.
            error_log("Passwort-Reset angefordert für UserID {$user['id']} von IP {$_SERVER['REMOTE_ADDR']} um ".date('c'));
        } else {
            // Adresse nur maskiert loggen - hier existiert keine UserID.
            error_log("Passwort-Reset-Versuch für NICHT vorhandene E-Mail " . LogHelper::maskEmail($email) . " von IP {$_SERVER['REMOTE_ADDR']} um ".date('c'));
        }

        $html = ViewHelper::template('assets/html/forgot_pw.html');
        $html = str_replace('###PW_FORGOT_MSG###', $msg, $html);
        ViewHelper::output($html);
    }

    /**
     * Zeigt das Passwort-Zurücksetzen-Formular an (nur bei gültigem Token).
     * @return void
     */
    public function showResetForm(): void
    {
        $token = Request::g('token');
        $stmt = PdoConnect::$connection->prepare("SELECT user_id FROM password_resets WHERE token = :token AND expires_at > NOW()");
        $stmt->bindParam(":token", $token);
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $msg = "";
        if ($row) {
            $html = ViewHelper::template('assets/html/reset_pw.html');
            $html = str_replace('###TOKEN###', htmlspecialchars($token), $html);
            $html = str_replace('###PW_RESET_MSG###', $msg, $html);
            ViewHelper::output($html);
        } else {
            $msg = "Der Link ist ungültig oder abgelaufen.";
            $html = ViewHelper::template('assets/html/reset_pw.html');
            $html = str_replace('###TOKEN###', '', $html);
            $html = str_replace('###PW_RESET_MSG###', $msg, $html);
            ViewHelper::output($html);
        }
    }

    /**
     * Setzt das neue Passwort nach Token-Prüfung.
     * Löscht das Token, zeigt Erfolg oder Fehler an.
     * @return void
     */
    public function handleResetPassword(): void
    {
        $token = Request::g('token');
        $pwd1 = Request::g('pwd1');
        $pwd2 = Request::g('pwd2');
        $msg = "";

        if ($pwd1 !== $pwd2 || strlen($pwd1) < 8) {
            $msg = "Die Passwörter stimmen nicht überein oder sind zu kurz.";
            $html = ViewHelper::template('assets/html/reset_pw.html');
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
            $html = ViewHelper::template('assets/html/reset_pw.html');
            $html = str_replace('###TOKEN###', '', $html);
            $html = str_replace('###PW_RESET_MSG###', $msg, $html);
            // Reset-Token NICHT loggen - wer ihn hat, uebernimmt das Konto.
            error_log("FEHLGESCHLAGENER Passwort-Reset (Token ungültig oder abgelaufen) von IP {$_SERVER['REMOTE_ADDR']} um ".date('c'));
            ViewHelper::output($html);
        }
    }

    /**
     * Zeigt das Passwort-Ändern-Formular für eingeloggte User an.
     *
     * Der angezeigte Name kommt aus der Sitzung. Vorher stand er in der
     * Adresse (index.php?act=change_pw_page&username=...) und wurde
     * ungeprüft in die Seite geschrieben - wer den Link verschickte,
     * bestimmte damit, welcher Name im Formular stand.
     *
     * @return void
     */
    public function showChangePwForm()
    {
        $username = Auth::username();
        $html = ViewHelper::template('assets/html/change_pw.html');
        $html = str_replace('###USERNAME###', htmlspecialchars($username), $html);
        $html = str_replace('###PW_CHANGE_MSG###', '', $html);
        ViewHelper::output($html);
    }

    /**
     * Verarbeitet die Änderung des Passworts durch den User.
     * Prüft altes Passwort, setzt neues, zeigt Fehler oder Erfolg.
     *
     * WER SEIN PASSWORT ÄNDERT, STEHT IN DER SITZUNG
     * ----------------------------------------------
     * Vorher kam der Benutzername aus dem Formular. Die Route verlangt zwar
     * eine Anmeldung (Recht auth.password_change), aber welches Konto
     * geändert werden sollte, bestimmte damit die Anfrage - nicht die
     * Sitzung. Wer angemeldet war, konnte einen fremden Benutzernamen
     * eintragen und Passwörter durchprobieren: Die Antwort "Das alte
     * Passwort ist nicht korrekt!" ist die Auskunft, ob geraten wurde.
     *
     * Ein Zähler stand dem nirgends entgegen: Der aus
     * LoginController::handleLogin() greift hier nicht - er hängt am
     * Anmeldeformular und zählt nur Anmeldeversuche. (Er taugt im Übrigen
     * auch dort wenig: Zähler und Sperre liegen in $_SESSION, wer pro
     * Versuch kein Cookie mitschickt, bekommt jedes Mal eine frische
     * Sitzung. Das ist ein eigener, hier nicht behobener Befund.)
     * Über diese Route ließ sich also unbegrenzt raten.
     *
     * Jetzt entscheidet die Sitzung, welches Konto gemeint ist. Der
     * Benutzername aus dem Formular wird nicht mehr gelesen; das versteckte
     * Feld in assets/html/change_pw.html ist deshalb entfallen. Geraten
     * werden kann damit nur noch das eigene Passwort - und das kennt man.
     *
     * @return void
     */
    public function handleChangePassword()
    {
        $userId     = Auth::userId();
        $pw_old     = Request::g('pw-old');
        $pwd1       = Request::g('pwd1');
        $pwd2       = Request::g('pwd2');
        $msg = "";

        if (!$userId) {
            header("Location: index.php?act=login_page");
            exit;
        }

        $stmt = PdoConnect::$connection->prepare("SELECT * FROM user WHERE id = :id AND deleted = 0");
        $stmt->bindParam(":id", $userId, \PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch();

        // Der Name fuer die Anzeige stammt aus dem geladenen Datensatz, nicht
        // aus dem Formular.
        $username = $result ? htmlspecialchars($result['username']) : '';

        $pepper = $_ENV['PEPPER'];
        if (!$pepper) {
            error_log("PEPPER nicht gesetzt!");
            return false;
        }
        $pwd_peppered = hash_hmac("sha256", $pw_old, $pepper);

        // Kein Datensatz: Das kann jetzt nur noch heissen, dass das Konto
        // waehrend der laufenden Sitzung geloescht wurde. Der Zugriff auf
        // $result['pwd'] loeste sonst eine Warning und damit HTTP 500 aus.
        if (!$result || !password_verify($pwd_peppered, $result['pwd'])) {
            $msg = "Das alte Passwort ist nicht korrekt!";
            // Nur noch das eigene Konto ist erreichbar - trotzdem ins Log:
            // Haeufige Fehlversuche auf dem eigenen Konto sind ein Hinweis
            // auf eine uebernommene Sitzung.
            error_log("Fehlgeschlagene Passwortaenderung fuer UserID {$userId} von IP "
                . "{$_SERVER['REMOTE_ADDR']} um " . date('c'));
            $html = ViewHelper::template('assets/html/change_pw.html');
            $html = str_replace('###USERNAME###', $username, $html);
            $html = str_replace('###PW_CHANGE_MSG###', $msg, $html);
            ViewHelper::output($html);
            return;
        }

        if ($pwd1 !== $pwd2 || strlen($pwd1) < 8) {
            $msg = "Die Passwörter stimmen nicht überein oder sind zu kurz.";
            $html = ViewHelper::template('assets/html/change_pw.html');
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
