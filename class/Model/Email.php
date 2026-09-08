<?php

namespace App\Model;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use App\Helper\LogHelper;
use App\Helper\MailGate;

/**
 * Hilfsklasse für den E-Mail-Versand über SMTP mit PHPMailer.
 *
 * DER SCHALTER STEHT HIER UND NICHT BEI DEN AUFRUFERN
 * ---------------------------------------------------
 * Vier Stellen verschicken Mails (Passwort-Reset, Bestaetigungsmail, und
 * beides je aus zwei Richtungen). Wuerde jede von ihnen selbst fragen, ob
 * verschickt werden darf, waere die Frage viermal beantwortet - und beim
 * fuenften Aufrufer vergessen. Sie steht deshalb an der einen Stelle, durch
 * die jede Mail muss.
 *
 * Der Aufrufer merkt vom ausgeschalteten Versand NICHTS: Er bekommt true wie
 * bei einer zugestellten Mail. Das ist Absicht - "verschickt" heisst an
 * dieser Schnittstelle "abgegeben", und ob ein Empfaengerserver die Mail
 * annimmt, weiss auch der eingeschaltete Versand nicht. Wer bei
 * ausgeschaltetem Versand false zurueckgaebe, wuerde den Passwort-Reset
 * abbrechen lassen, obwohl der Link im Logfile steht und benutzbar ist.
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
        // DER SCHALTER (MAIL_ENABLED, siehe App\Helper\MailGate). Aus heisst
        // nicht "der Aufruf faellt weg" - der ganze Weg davor laeuft
        // unveraendert: Token angelegt, Link gebaut, Seite ausgegeben. Nur die
        // Verbindung zum SMTP-Server unterbleibt.
        if (!MailGate::versandAktiv()) {
            self::insLog($in_email, $in_msg, $in_subject);
            return true;
        }

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
            // Empfaengeradresse nur maskiert loggen.
            error_log('Fehler beim E-Mail-Versand an ' . LogHelper::maskEmail($in_email) . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Schreibt die Mail ins Logfile, statt sie zu verschicken.
     *
     * WOZU DER GANZE TEXT UND NICHT NUR EINE ZEILE "haette verschickt":
     * Weil in diesem Text der LINK steht. Bestaetigung der Adresse und
     * Zuruecksetzen des Passworts sind ohne ihn nicht durchspielbar; ohne den
     * Text im Log muesste sich der Entwickler den Token aus der Datenbank
     * heraussuchen und den Link selbst zusammenbauen. Genau diese Umstaende
     * waren der Grund, aus dem der Versand einmal auskommentiert wurde.
     *
     * DIE ADRESSE BLEIBT MASKIERT, auch hier. Sie steht in keinem Log dieser
     * Anwendung im Klartext (App\Helper\LogHelper), und ein ausgeschalteter
     * Versand ist kein Grund, davon abzuweichen: Dasselbe Logfile kann auf
     * einem Server liegen, auf dem nur der Versand aus ist. Fuer den Link
     * braucht man die Adresse nicht.
     *
     * @param string $in_email
     * @param string $in_msg
     * @param string $in_subject
     * @return void
     */
    private static function insLog($in_email, $in_msg, $in_subject): void
    {
        error_log(
            "MAIL_ENABLED=aus - diese E-Mail wurde NICHT verschickt:\n"
            . '  An:      ' . LogHelper::maskEmail((string)$in_email) . "\n"
            . '  Betreff: ' . $in_subject . "\n"
            . "  Text:\n"
            . self::eingerueckt((string)$in_msg)
        );
    }

    /**
     * Rueckt den Mailtext ein, damit er im Log als ein Block zu erkennen ist.
     *
     * Ein mehrzeiliger Text landet sonst als lose Zeilen zwischen den
     * uebrigen Logeintraegen, und die Zeile mit dem Link sieht aus wie eine
     * Meldung fuer sich.
     *
     * @param  string $in_text
     * @return string
     */
    private static function eingerueckt(string $in_text): string
    {
        $zeilen = preg_split('/\R/', $in_text);
        return '    ' . implode("\n    ", $zeilen);
    }
}
