<?php
namespace App\Helper;

/**
 * Die öffentliche Adresse dieser Installation.
 *
 * WARUM DAS HIER STEHT
 * --------------------
 * Passwort-Reset und E-Mail-Bestätigung verschicken Links, die der Empfänger
 * in einem fremden Browser öffnet. Diese Links müssen absolut sein - eine
 * relative Adresse hat in einer E-Mail keinen Bezugspunkt. Bis hierher stand
 * die Adresse als Literal im Code:
 *
 *     https://localhost/rctprojnew/index.php?act=reset_pw_page&token=...
 *
 * Das ist die Adresse eines Entwicklungsrechners. Auf jedem echten Server
 * verwies der Link damit ins Leere: Der Empfänger landete auf SEINEM eigenen
 * localhost oder auf gar nichts, und der Reset war nicht durchführbar. Eine
 * Installation unter einem anderen Namen oder in einem anderen Verzeichnis
 * hätte zwei Dateien im Code ändern müssen.
 *
 * WARUM NICHT AUS DEM REQUEST
 * ---------------------------
 * Naheliegend wäre $_SERVER['HTTP_HOST'] - und genau das wäre ein Fehler.
 * Der Host-Header kommt vom Aufrufer. Wer "Passwort vergessen" für ein
 * fremdes Konto anstößt und dabei einen eigenen Host mitschickt, bekäme einen
 * Reset-Link auf den eigenen Server geschrieben; die Mail ginge an den
 * richtigen Empfänger, der Klick aber an den Angreifer. Deshalb kommt die
 * Adresse ausschließlich aus der Konfiguration, die nur der Betreiber setzt.
 *
 * WENN DER WERT FEHLT
 * -------------------
 * Dann gibt es keinen Link, der stimmt - und ein falscher Link ist schlimmer
 * als keine Mail. base() und to() liefern in dem Fall null und schreiben eine
 * Zeile ins Log; die Aufrufer verschicken dann nichts. Nach außen bleibt die
 * Antwort unverändert (beim Passwort-Reset ist sie ohnehin für jede Eingabe
 * dieselbe), die Ursache steht im Logfile.
 */
class Url
{
    /**
     * Basisadresse der Installation, ohne Schrägstrich am Ende.
     *
     * Gelesen wird APP_BASE_URL aus der .env (siehe .env.example). Erlaubt
     * ist eine http(s)-Adresse mit optionalem Unterverzeichnis, also
     * "https://example.org" ebenso wie "https://example.org/rctproj".
     *
     * @return string|null null, wenn der Wert fehlt oder unbrauchbar ist
     */
    public static function base(): ?string
    {
        $roh = isset($_ENV['APP_BASE_URL']) && is_scalar($_ENV['APP_BASE_URL'])
             ? trim((string)$_ENV['APP_BASE_URL'])
             : '';

        if ($roh === '') {
            error_log('APP_BASE_URL ist nicht gesetzt - es lassen sich keine Links '
                . 'fuer E-Mails bilden (siehe .env.example).');
            return null;
        }

        // Schema und Host sind Pflicht, Anfrage- und Fragmentteil verboten:
        // An die Basis wird angehaengt, ein "?" darin wuerde den Token in den
        // falschen Teil der Adresse schieben.
        if (!preg_match('#^https?://[^\s/?\#]+(?:/[^\s?\#]*)?$#', $roh)) {
            error_log('APP_BASE_URL ist unbrauchbar: ' . var_export($roh, true)
                . ' - erwartet wird z.B. https://example.org/rctproj');
            return null;
        }

        return rtrim($roh, '/');
    }

    /**
     * Absolute Adresse für einen Pfad innerhalb der Anwendung.
     *
     * @param  string $ziel z.B. 'index.php?act=reset_pw_page&token=abc'
     * @return string|null  null, wenn keine Basisadresse konfiguriert ist
     */
    public static function to(string $ziel): ?string
    {
        $basis = self::base();
        if ($basis === null) return null;

        return $basis . '/' . ltrim($ziel, '/');
    }
}
