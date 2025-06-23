<?php

namespace App\Model;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Hilfsklasse für den E-Mail-Versand über SMTP mit PHPMailer.
 */
class Email
{
    /**
     * Versendet eine E-Mail an die angegebene Adresse.
     *
     * @param string $in_email   Empfängeradresse
     * @param string $in_msg     E-Mail-Text (Body)
     * @param string $in_subject Betreff der E-Mail
     * @return bool              true bei Erfolg, false bei Fehler
     */
    public static function sendMail($in_email, $in_msg, $in_subject): bool
    {
        try {
            $mail = new PHPMailer(true);

            $mail->CharSet   = 'UTF-8';        // Encoding für Umlaute/Sonderzeichen
            $mail->Encoding  = 'base64';       // E-Mail-Inhalt base64-kodiert

            // $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Debug-Ausgabe aktivieren (optional)

            $mail->isSMTP();
            $mail->SMTPAuth   = true;

            $mail->Host       = $_ENV['SMTP_SERVER'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $_ENV['SMTP_PORT'];

            $mail->Username   = $_ENV['SMTP_USERNAME'];
            $mail->Password   = $_ENV['SMTP_PASSWORD'];

            $mail->setFrom($_ENV['SMTP_USERNAME'], 'Test');
            $mail->addAddress($in_email, $in_email);

            $mail->Subject = $in_subject;
            $mail->Body    = $in_msg;

            $mail->send();
            return true;
        } catch (\Exception $e) {
            error_log('Fehler beim E-Mail-Versand an ' . $in_email . ': ' . $e->getMessage());
            return false;
        }
    }
}
