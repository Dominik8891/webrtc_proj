<?php

namespace App\Controller;

use App\Helper\Request;
use App\Helper\ViewHelper;
use App\Model\PdoConnect;
use App\Model\Email;

/**
 * Controller für die E-Mail-Bestätigung und Verifizierungs-Mails.
 */
class EmailVerificationController
{
    /**
     * Prüft den übergebenen Verifikations-Token und bestätigt den User.
     * Zeigt Erfolgs- oder Fehlermeldung an.
     * @return void
     */
    public function handleEmailVerification()
    {
        $token = Request::g('token');
        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT user_id FROM email_verifications WHERE token = :token AND expires_at > NOW()"
            );
            $stmt->bindParam(":token", $token);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row) {
                // User auf 'bestätigt' setzen
                $stmt2 = PdoConnect::$connection->prepare(
                    "UPDATE user SET email_verified = 1 WHERE id = :uid"
                );
                $stmt2->bindParam(":uid", $row['user_id']);
                $stmt2->execute();

                // Token löschen
                $del = PdoConnect::$connection->prepare("DELETE FROM email_verifications WHERE user_id = :uid");
                $del->bindParam(":uid", $row['user_id']);
                $del->execute();

                error_log("E-Mail erfolgreich bestätigt für UserID {$row['user_id']} von IP {$_SERVER['REMOTE_ADDR']} um ".date('c'));
                $html = file_get_contents('assets/html/email_verified.html');
                ViewHelper::output($html);
            } else {
                error_log("FEHLGESCHLAGENE E-Mail-Bestätigung mit Token {$token} von IP {$_SERVER['REMOTE_ADDR']} um ".date('c'));
                $html = file_get_contents('assets/html/email_verified_error.html');
                ViewHelper::output($html);
            }
        } catch (\Exception $e) {
            error_log("Fehler in handleEmailVerification: " . $e->getMessage());
            $html = file_get_contents('assets/html/email_verified_error.html');
            ViewHelper::output($html);
        }
    }

    /**
     * Erstellt einen neuen Verifikationseintrag und sendet eine Bestätigungs-E-Mail an den Benutzer.
     * @param int $user_id
     * @return void
     */
    public function sendVerificationMail($user_id)
    {
        try {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 86400); // z.B. 24 Stunden gültig

            // Hole E-Mail-Adresse für den User
            $stmtEmail = PdoConnect::$connection->prepare("SELECT email FROM user WHERE id = :uid");
            $stmtEmail->bindParam(":uid", $user_id);
            $stmtEmail->execute();
            $email = $stmtEmail->fetchColumn();

            if (!$email) {
                error_log("Keine E-Mail für UserID {$user_id} gefunden.");
                return;
            }

            // Verifikations-Token eintragen
            $stmt = PdoConnect::$connection->prepare(
                "INSERT INTO email_verifications (user_id, token, expires_at) VALUES (:uid, :token, :exp)"
            );
            $stmt->bindParam(":uid", $user_id);
            $stmt->bindParam(":token", $token);
            $stmt->bindParam(":exp", $expires);
            $stmt->execute();

            // Mail senden
            $verifyLink = "https://localhost/rctprojnew/index.php?act=verify_email&token=$token";
            Email::sendMail($email, 
                "Hallo,\n\nBitte bestätige deine E-Mail durch Klick auf diesen Link:\n\n$verifyLink\n\nDieser Link ist 24 Stunden gültig.",
                "E-Mail-Adresse bestätigen");
        } catch (\Exception $e) {
            error_log("Fehler beim Senden der Verifikations-Mail: " . $e->getMessage());
        }
    }

    /**
     * Hilfsmethode: Sendet eine Verifikationsmail und zeigt Bestätigungsseite an.
     * @param int $user_id
     * @return void
     */
    public function sendVerification($user_id)
    {
        $this->sendVerificationMail($user_id);
        $out = file_get_contents('assets/html/signup_complete.html');
        ViewHelper::output($out);
    }
}
