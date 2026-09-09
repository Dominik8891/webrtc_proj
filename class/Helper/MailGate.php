<?php
namespace App\Helper;

use App\Model\User;

/**
 * Die beiden Schalter rund um die E-Mail: VERSAND und BESTAETIGUNGSPFLICHT.
 *
 * WARUM ZWEI SCHALTER UND KEINER
 * ------------------------------
 * Vorher war beides dasselbe, und zwar in Form von Kommentarzeichen: Der
 * Versand der Bestaetigungsmail stand im SignupController auskommentiert, die
 * Bestaetigungspflicht im LoginController, beides mit derselben Begruendung
 * ("kein eigener SMTP-Server"). Auskommentierter Code ist aber kein
 * ausgeschalteter Code - er ist Code, der nicht mehr uebersetzt, nicht mehr
 * geprueft und nicht mehr mitgepflegt wird. Beim Wiedereinschalten faellt
 * dann auf, dass er inzwischen nicht mehr passt; genau das war hier der Fall
 * (der Aufruf "(new EmailVerificationController)::sendVerification(...)"
 * mischte Instanz- und statischen Aufruf, und der Link im Login-Hinweis
 * zeigte auf eine Route, die eine Anmeldung voraussetzt).
 *
 * Jetzt laeuft der Code immer, und die Konfiguration entscheidet, was er tut.
 *
 * GETRENNT, WEIL DIE BEIDEN ZU VERSCHIEDENEN ZEITPUNKTEN SCHARF WERDEN.
 * Wer den Versand einschaltet, will noch niemanden aussperren: In dem Moment
 * hat KEIN einziges Bestandskonto eine bestaetigte Adresse, denn bisher wurde
 * nie eine Bestaetigungsmail verschickt. Erst wenn die Bestandskonten Zeit
 * hatten, ihre Adresse zu bestaetigen, wird die zweite Einstellung
 * eingeschaltet. Ein gemeinsamer Schalter waere genau die Reihenfolge, die
 * nicht geht.
 *
 * DIE VORGABEN SIND "WIE BISHER"
 * ------------------------------
 * Fehlt ein Schluessel in der .env, verhaelt sich die Anwendung wie vor
 * dieser Aenderung: Es wird verschickt (MAIL_ENABLED), und niemand wird
 * ausgesperrt (MAIL_VERIFY_REQUIRED). Eine bestehende Installation, die ihre
 * .env nicht anfasst, merkt von den Schaltern nichts.
 */
class MailGate
{
    /**
     * Wird tatsaechlich verschickt?
     *
     * Aus wirkt nur auf den Versand, NICHT auf den uebrigen Ablauf: Der Token
     * wird angelegt, die Bestaetigungsseite kommt, der Zaehler zaehlt. Was
     * verschickt WORDEN WAERE, steht im Logfile - dort holt sich der
     * Entwickler den Bestaetigungs- bzw. Reset-Link, ohne dass ein
     * SMTP-Server erreichbar sein muss (App\Model\Email::sendMail).
     */
    public static function versandAktiv(): bool
    {
        // Vorgabe true: Wer den Schluessel nicht kennt, hat eine
        // Installation, die bisher verschickt hat - die soll das weiter tun.
        return self::schalter('MAIL_ENABLED', true);
    }

    /**
     * Muss die Adresse bestaetigt sein, bevor das Konto mitmachen darf?
     *
     * Aus heisst: alles wie bisher, die Spalte user.email_verified wird
     * gepflegt und sonst nirgends gefragt.
     */
    public static function bestaetigungPflicht(): bool
    {
        // Vorgabe false: Einschalten sperrt Bestandskonten aus, und das darf
        // nur passieren, wenn es jemand ausdruecklich hinschreibt.
        return self::schalter('MAIL_VERIFY_REQUIRED', false);
    }

    /**
     * DIE ROUTEN, DIE EINE BESTAETIGTE ADRESSE VORAUSSETZEN.
     *
     * Aufgezaehlt werden ROUTEN und keine Rechte, obwohl die Rechtetabelle
     * (App\Helper\Permission) der naheliegende Ort waere. Der Grund steht in
     * der Routentabelle: location.edit_own traegt sowohl das Hochladen eines
     * Bildes als auch das Aendern des Beschreibungstextes. Ueber das Recht
     * gesperrt waere ein unbestaetigtes Konto also auch seine eigenen Texte
     * nicht mehr los - gemeint ist aber nur das Hochladen.
     *
     * DREI GRUPPEN, und sie sind genau die drei, die etwas nach draussen
     * tragen:
     *
     *   ANFRAGEN   Eine Anfrage bindet einen Guide an einen Termin. Wer sie
     *              stellt, muss erreichbar sein - sonst steht der Guide vor
     *              einem Vorgang, zu dem es niemanden gibt.
     *
     *   CHAT       Schreiben und einen Chat eroeffnen. LESEN bleibt frei:
     *              Wer schon angeschrieben wurde, soll die Antwort sehen
     *              koennen, auch waehrend seine Bestaetigung noch aussteht.
     *
     *   HOCHLADEN  Bilder an einem Standort und das Guide-Profil. Das Profil
     *              steht mit in der Liste, weil sein Formular Text UND
     *              Avatarbild in einem Absenden traegt: Nur die Bilddatei
     *              abzuweisen hiesse, ein halb gespeichertes Formular
     *              zurueckzugeben.
     *
     * NICHT GESPERRT ist das Beantworten einer Anfrage (request_accept /
     * request_decline). Ein Guide, der zusagt, laesst sich auf einen Termin
     * ein, den ein anderer gesetzt hat - ihn dabei zu sperren, traefe den
     * anfragenden Kunden. Wer das anders sehen will, traegt die beiden Namen
     * hier ein; ausserhalb dieser Liste ist dafuer nichts anzufassen.
     */
    public const PFLICHTROUTEN = [
        // Anfragen
        'request_create',
        // Chat
        'chat_start',
        'chat_start_direct',
        'chat_send_message',
        // Hochladen
        'upload_location_image',
        'guide_profile_save',
    ];

    /**
     * Ist DIESE Route fuer DIESEN Aufrufer gerade gesperrt?
     *
     * Gefragt wird in index.php, unmittelbar hinter der Rechtepruefung und
     * vor dem Controller - aus demselben Grund, aus dem die Rechtepruefung
     * dort steht: Eine Entscheidung ueber den Zugang gehoert an EINE Stelle
     * und nicht in jeden Controller einzeln.
     *
     * Vier Bedingungen, in der Reihenfolge ihrer Kosten - die
     * Datenbankabfrage steht zuletzt und faellt auf allen anderen Routen
     * ueberhaupt nicht an:
     *
     * @param  string $in_act Der Routenname aus config/routes.php
     * @return bool
     */
    public static function sperrt(string $in_act): bool
    {
        if (!self::bestaetigungPflicht())               return false;
        if (!in_array($in_act, self::PFLICHTROUTEN, true)) return false;
        // Ein Gast kommt auf keine dieser Routen - die Rechtepruefung hat ihn
        // vorher zum Anmeldeformular geschickt. Die Zeile ist der Gurt
        // daneben und verhindert vor allem die Abfrage mit der Kennung 0.
        if (!Auth::isLoggedIn())                        return false;

        return !User::isEmailVerified(Auth::userId());
    }

    /**
     * Der Hinweis, der statt der gesperrten Handlung erscheint.
     *
     * EIN TEXT FUER ALLE SPERREN, und er nennt den Weg heraus: die Route
     * send_email_verify. Sie ist erreichbar, weil das Konto ANGEMELDET ist -
     * genau deshalb sperrt die Bestaetigungspflicht die Anmeldung nicht (siehe
     * App\Controller\LoginController).
     *
     * @return string
     */
    public static function hinweis(): string
    {
        return I18n::t('mailhinweis.gesperrt');
    }

    /**
     * Der Hinweisstreifen ueber dem Seiteninhalt.
     *
     * WOZU EIN STREIFEN AUF JEDER SEITE: Weil die Sperre sonst als
     * Fehlermeldung kaeme, und zwar erst in dem Moment, in dem der Nutzer
     * etwas tun will. Der Streifen sagt es ihm vorher und - vor allem - nennt
     * den Weg heraus. Ohne ihn gaebe es genau einen Ort, an dem sich eine neue
     * Bestaetigungsmail anfordern laesst: die Einstellungsseite. Wer nicht
     * weiss, dass er sie suchen muss, sucht sie nicht.
     *
     * Leer, wenn die Pflicht aus ist oder die Adresse bestaetigt - dann kostet
     * er auch keine Abfrage.
     *
     * @param  bool $in_bestaetigt Der bereits bekannte Stand des Kontos. Der
     *                             Aufrufer hat den Benutzer meistens schon
     *                             geladen (App\Helper\ViewHelper::output);
     *                             ohne Angabe wird er erfragt.
     * @return string HTML, oder Leerstring
     */
    public static function streifen(?bool $in_bestaetigt = null): string
    {
        if (!self::bestaetigungPflicht()) return '';
        if (!Auth::isLoggedIn())          return '';

        $bestaetigt = $in_bestaetigt ?? User::isEmailVerified(Auth::userId());
        if ($bestaetigt) return '';

        // Drei Stuecke, drei Schluessel: Die Hervorhebung steht als eigener
        // Satz AM ANFANG und nicht mitten im Text - deshalb genuegen hier
        // drei nebeneinanderstehende Texte und kein tHtml().
        return '
                <div class="alert alert-warning app-mailhint" role="status">
                    <strong>' . ViewHelper::esc(I18n::t('mailhinweis.streifen.titel')) . '</strong>
                    ' . ViewHelper::esc(I18n::t('mailhinweis.streifen.text')) . '
                    <a href="index.php?act=send_email_verify" class="btn btn-outline-primary btn-sm">
                        ' . ViewHelper::esc(I18n::t('mailhinweis.streifen.knopf')) . '
                    </a>
                </div>
                ';
    }

    /**
     * Liest einen Schalter aus der Umgebung.
     *
     * DIE ARBEIT MACHT App\Helper\Env. Diese Methode bleibt stehen, weil
     * sie die beiden Aufrufer oben lesbar haelt - und weil dort, wo ein
     * Schalter gelesen wird, der Name des Schluessels stehen soll und nicht
     * eine Klasse mit drei Argumenten.
     *
     * Frueher stand die Auswertung hier ausgeschrieben. Als mit FORCE_HTTPS,
     * TRUST_PROXY und HSTS die naechsten Schalter dazukamen, waere sie ein
     * zweites Mal entstanden - mit der Gefahr, dass "yes" dann an einer
     * Stelle gilt und an der anderen nicht.
     *
     * @param  string $in_name    Name des Schluessels in der .env
     * @param  bool   $in_vorgabe Wert, wenn nichts Brauchbares dasteht
     * @return bool
     */
    private static function schalter(string $in_name, bool $in_vorgabe): bool
    {
        return Env::schalter($in_name, $in_vorgabe);
    }
}
