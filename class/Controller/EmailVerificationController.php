<?php

namespace App\Controller;

use App\Helper\Auth;
use App\Helper\I18n;
use App\Helper\Request;
use App\Helper\Url;
use App\Helper\ViewHelper;
use App\Model\PdoConnect;
use App\Model\Email;
use App\Model\RateLimit;

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
                $html = ViewHelper::template('assets/html/email_verified.html');
                ViewHelper::output($html);
            } else {
                // Verifikations-Token NICHT loggen - er bestaetigt eine fremde Adresse.
                error_log("FEHLGESCHLAGENE E-Mail-Bestätigung (Token ungültig oder abgelaufen) von IP {$_SERVER['REMOTE_ADDR']} um ".date('c'));
                $html = ViewHelper::template('assets/html/email_verified_error.html');
                ViewHelper::output($html);
            }
        } catch (\Exception $e) {
            error_log("Fehler in handleEmailVerification: " . $e->getMessage());
            $html = ViewHelper::template('assets/html/email_verified_error.html');
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
            // Die Basisadresse zuerst: Ohne sie gaebe es nur einen Link, der
            // ins Leere fuehrt - dann soll auch kein Token angelegt werden
            // (App\Helper\Url, Konfiguration APP_BASE_URL).
            if (Url::base() === null) {
                error_log("Bestaetigungsmail fuer UserID {$user_id} nicht verschickt: "
                    . 'APP_BASE_URL fehlt oder ist unbrauchbar.');
                return;
            }

            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 86400); // z.B. 24 Stunden gültig

            // Adresse UND Sprache des Kontos. Die Sprache geht mit, weil die
            // Mail an den EMPFAENGER geht und nicht an den, der sie ausloest:
            // Der Registrierungsablauf ruft diese Methode mit einer frisch
            // angelegten Kennung, und die Oberflaeche daneben kann in einer
            // anderen Sprache stehen.
            $stmtEmail = PdoConnect::$connection->prepare("SELECT email, lang FROM user WHERE id = :uid");
            $stmtEmail->bindParam(":uid", $user_id);
            $stmtEmail->execute();
            $konto = $stmtEmail->fetch(\PDO::FETCH_ASSOC);
            $email = $konto ? (string)$konto['email'] : '';

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

            // Mail senden. Die Adresse kommt aus der Konfiguration und
            // ausdruecklich NICHT aus dem Host-Header der Anfrage
            // (App\Helper\Url).
            $verifyLink = Url::to("index.php?act=verify_email&token=$token");

            // tIn() und nicht t() - der Text kommt in der Sprache des Kontos.
            $sprache = I18n::normalize($konto['lang'] ?? null);
            Email::sendMail(
                $email,
                I18n::tIn($sprache, 'mail.bestaetigung.text', ['link' => $verifyLink]),
                I18n::tIn($sprache, 'mail.bestaetigung.betreff')
            );
        } catch (\Exception $e) {
            error_log("Fehler beim Senden der Verifikations-Mail: " . $e->getMessage());
        }
    }

    /**
     * Sendet eine Verifikationsmail und zeigt die Bestätigungsseite an.
     *
     * Zwei Aufrufwege, deshalb der optionale Parameter:
     *   - als Route send_email_verify (index.php ruft ohne Argument auf);
     *     dann gilt das ANGEMELDETE Konto. Vorher hatte der Parameter keinen
     *     Vorgabewert - der Aufruf ueber die Route endete zwingend mit einem
     *     ArgumentCountError, also HTTP 500.
     *   - direkt aus dem Registrierungsablauf mit der frisch angelegten ID.
     *
     * Die Kennung kommt bewusst NICHT aus der Anfrage: Sonst liesse sich mit
     * einer fremden ID beliebig oft eine Mail an eine fremde Adresse
     * ausloesen.
     *
     * DIE BREMSE (Befund N-10)
     * ------------------------
     * Jeder Aufruf verschickt eine E-Mail. Ohne Grenze ist das ein
     * Mailversand-Verstaerker - und der schadet nicht nur diesem Server,
     * sondern seinem Ruf bei den Empfaengerservern; das laesst sich durch
     * Abschalten nicht wieder reparieren. Deshalb zaehlt dies neben dem
     * TURN-Abruf als einziger Endpunkt aus N-10 zusaetzlich je IP: Die Kosten
     * fallen draussen an, und dort interessiert nicht, ueber wie viele Konten
     * sie verteilt wurden.
     *
     * GEBREMST WIRD IN BEIDEN STELLUNGEN DES SCHALTERS. Ob wirklich eine Mail
     * hinausgeht, entscheidet MAIL_ENABLED (App\Helper\MailGate); bei
     * ausgeschaltetem Versand steht sie im Logfile. Eine Grenze, die dann
     * aussetzte, waere genau die, die beim Einschalten noch nie gelaufen ist -
     * und dann faengt sie in dem Moment an zu zaehlen, in dem es teuer wird.
     *
     * GEZAEHLT WIRD NUR DER WEG UEBER DIE ROUTE. Der Aufruf aus dem
     * Registrierungsablauf uebergibt eine $user_id und bremst nicht: Er ist
     * die Folge einer Registrierung, und die ist bereits begrenzt (Aktion
     * 'signup'). Zweimal fuer denselben Vorgang zu zaehlen hiesse, dass ein
     * frisch angelegtes Konto seine erste Mail unter Umstaenden gar nicht
     * bekommt.
     *
     * @param  int|null $user_id null = das angemeldete Konto
     * @return void
     */
    public function sendVerification($user_id = null)
    {
        $ueberRoute = ($user_id === null);
        $user_id = $ueberRoute ? Auth::userId() : (int)$user_id;

        if ($user_id < 1) {
            error_log('sendVerification: keine Benutzerkennung - weder uebergeben noch angemeldet.');
            header('Location: index.php?act=login_page');
            exit;
        }

        if ($ueberRoute) {
            $teile = ['konto' => RateLimit::konto($user_id), 'ip' => RateLimit::ip()];
            $rest  = RateLimit::restsperre('email_verify_send', $teile);
            if ($rest > 0) {
                // Ehrlich statt still: Der Aufrufer ist angemeldet und fragt
                // nach seiner EIGENEN Adresse - hier gibt es nichts zu
                // verbergen, und "die Mail ist unterwegs" zu behaupten,
                // waehrend keine unterwegs ist, laesst ihn weiter warten.
                error_log("sendVerification: gebremst (UserID {$user_id})");
                $this->outputVerificationHinweis(
                    I18n::t('mailbestaetigung.gebremst', [
                        'warten' => RateLimit::wartehinweis($rest),
                    ])
                );
                return;
            }
            RateLimit::verbuchen('email_verify_send', $teile);
        }

        $this->sendVerificationMail($user_id);

        // ZWEI WEGE, ZWEI SEITEN. Ueber die Route kommt ein ANGEMELDETES
        // Konto, das sich eine neue Mail schicken laesst; "Registrierung
        // erfolgreich - Sie koennen sich jetzt anmelden" waere dort zweimal
        // falsch. Aus dem Registrierungsablauf kommt die Bestaetigungsseite,
        // die es dort auch vorher gab.
        $out = ViewHelper::template($ueberRoute
            ? 'assets/html/verify_sent.html'
            : 'assets/html/signup_complete.html');
        ViewHelper::output($out);
    }

    /**
     * Gibt einen Hinweis statt der Bestaetigungsseite aus.
     *
     * Eigene Methode und keine zweite Vorlage: Der Text ist der einzige
     * Unterschied, und assets/html/signup_complete.html sagt "die Mail ist
     * unterwegs" - genau das, was hier nicht stimmt.
     *
     * @param  string $msg Der Hinweis. Kommt aus dem Code, nie aus der
     *                     Anfrage - trotzdem maskiert, damit das auch dann
     *                     noch gilt, wenn jemand hier einmal etwas
     *                     durchreicht.
     * @return void
     */
    private function outputVerificationHinweis(string $msg): void
    {
        $html = '
                <div class="app-result">
                    <div class="app-panel">
                        <div class="app-panel__body">
                            <div class="app-result__mark app-result__mark--danger" aria-hidden="true">!</div>
                            <h1 class="app-auth__title">'
                              . ViewHelper::esc(I18n::t('mailbestaetigung.keine_mail')) . '</h1>
                            <p class="app-result__text">' . htmlspecialchars($msg) . '</p>
                            <div class="app-actions app-actions--center">
                                <a href="index.php?act=home" class="btn btn-primary">'
                                  . ViewHelper::esc(I18n::t('mailbestaetigung.zur_startseite')) . '</a>
                            </div>
                        </div>
                    </div>
                </div>
                ';
        ViewHelper::output($html);
    }
}
