<?php

namespace App\Model;

use App\Helper\Languages;

/**
 * Das oeffentliche Profil eines Guides: lesen und schreiben.
 *
 * WOZU ES DAS GIBT
 * ----------------
 * Ein Kunde soll einem Fremden Geld dafuer geben, dass der ihn per Video
 * durch eine unbekannte Stadt fuehrt. Gesehen hat er von diesem Menschen
 * bisher: einen Benutzernamen und einen farbigen Punkt. Fuer "ich vertraue
 * dieser Person" reicht das nicht.
 *
 * ARBEITSTEILUNG MIT App\Model\GuideRole
 * --------------------------------------
 * Beide Klassen schreiben in dieselbe Zeile von `guide_profile`, aber nie in
 * dieselben Spalten:
 *
 *   GuideRole      die ZUSTIMMUNG und die Rolle - guide_since, joined_at,
 *                  terms_version, terms_accepted_at, resigned_at. Das ist
 *                  Vertragsstoff; er entsteht aus einer Entscheidung des
 *                  Nutzers auf der Guide-Seite und wird spaeter von der
 *                  Abrechnung gelesen.
 *   Diese Klasse   das PROFIL - display_name, about, languages, avatar_file.
 *                  Das sind Angaben, die der Guide jederzeit aendert, und
 *                  sie haben mit der Rolle nichts zu tun.
 *
 * Die Trennung ist keine Formsache: Wer die Rolle zurueckgibt, behaelt sein
 * Profil, und wer sein Profil aendert, stimmt damit keinen Bedingungen zu.
 * Beide Klassen zaehlen ihre Spalten deshalb einzeln auf und ersetzen die
 * Zeile nie als Ganzes.
 *
 * DER ANZEIGENAME ERSETZT DEN BENUTZERNAMEN
 * -----------------------------------------
 * Ueberall dort, wo ein KUNDE den Guide sieht - Standortliste, Standortseite,
 * Profilseite. Der Benutzername bleibt, was er immer war: die Kennung, mit
 * der man sich anmeldet. Er ist kein Anzeigename, er war nie einer, und er
 * gehoert niemandem vorgesetzt, der eine Kaufentscheidung treffen soll.
 *
 * Ist kein Anzeigename gesetzt, steht weiterhin der Benutzername da - das ist
 * die Wahrheit ueber ein unausgefuelltes Profil und keine Luecke. Entschieden
 * wird das an genau einer Stelle: anzeigename().
 *
 * DIE BILDDATEI STEHT NICHT HIER. In der Zeile steht nur ihr Basisname; sie
 * selbst liegt ausserhalb des Webroots (App\Helper\ImageStore) und wird ueber
 * einen Controller ausgeliefert.
 */
class GuideProfile
{
    /**
     * Groesste Laenge des Anzeigenamens in Zeichen.
     *
     * DIE EINE STELLE, gegen die geprueft wird - das Formular bekommt
     * dieselbe Zahl als maxlength (App\Helper\GuideView). Ein Feld, das mehr
     * annimmt, als der Server durchlaesst, gibt dem Nutzer eine Absage fuer
     * eine Eingabe, die es selbst ausdruecklich erlaubt hat.
     */
    public const NAME_MAX = 60;

    /** Groesste Laenge der Selbstbeschreibung in Zeichen. */
    public const ABOUT_MAX = 600;

    /**
     * Das Profil eines Kontos, so wie eine Seite es zeigt.
     *
     * AUSGANGSPUNKT IST `user` UND NICHT `guide_profile`: Ein Konto kann
     * Standorte anbieten, ohne je eine Profilzeile bekommen zu haben - etwa
     * ein Admin oder ein Konto, dessen Rolle von Hand in der
     * Benutzerverwaltung gesetzt wurde. Mit einem INNER JOIN haetten genau
     * diese Guides keine Seite, obwohl ihre Standorte auf sie verweisen.
     * Die Profilspalten sind dann NULL, und die Anzeige faellt auf den
     * Benutzernamen zurueck.
     *
     * Geloeschte Konten gibt es nicht - dieselbe Bedingung wie ueberall
     * sonst in App\Model\User.
     *
     * @param int $in_user_id
     * @return array<string,mixed>|null null, wenn es das Konto nicht gibt
     */
    public static function forUser($in_user_id): ?array
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return null;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT user.id AS user_id, user.username, user.type_id,
                        guide_profile.display_name, guide_profile.about,
                        guide_profile.languages, guide_profile.avatar_file,
                        guide_profile.joined_at, guide_profile.resigned_at
                   FROM user
                   LEFT JOIN guide_profile ON guide_profile.user_id = user.id
                  WHERE user.id = :user_id AND user.deleted = 0"
            );
            $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $zeile === false ? null : $zeile;
        } catch (\PDOException $e) {
            error_log('GuideProfile::forUser: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Der Name, den ein Kunde sieht.
     *
     * DIE EINE STELLE, an der aus "Profilzeile" ein Name wird. Sie steht
     * hier und nicht in der Ansicht, weil dieselbe Entscheidung an vier
     * Orten faellt: Standortliste, Standortseite, Profilseite und die
     * Kopfzeile darauf. Vier Fassungen davon liefen beim ersten Sonderfall
     * auseinander.
     *
     * @param mixed  $in_display_name Wert aus guide_profile (darf NULL sein)
     * @param mixed  $in_username     Benutzername als Rueckfallebene
     * @return string Leerstring nur, wenn es beides nicht gibt
     */
    public static function anzeigename($in_display_name, $in_username): string
    {
        $name = is_scalar($in_display_name) ? trim((string)$in_display_name) : '';
        if ($name !== '') return $name;

        return is_scalar($in_username) ? trim((string)$in_username) : '';
    }

    /**
     * Derselbe Name, aber aus einer geladenen Zeile.
     *
     * Bequemlichkeit fuer die Ansichten - und eine Stelle weniger, an der
     * jemand die beiden Spaltennamen von Hand zusammensucht.
     *
     * @param array<string,mixed>|null $in_zeile Aus forUser() oder einer
     *        Standortabfrage (dort heissen die Spalten genauso)
     * @return string
     */
    public static function nameAus(?array $in_zeile): string
    {
        if ($in_zeile === null) return '';
        return self::anzeigename($in_zeile['display_name'] ?? null, $in_zeile['username'] ?? null);
    }

    /**
     * Speichert die Angaben, die der Guide selbst macht.
     *
     * DREI FELDER, EIN SCHREIBVORGANG. Das Avatarbild geht einen eigenen Weg
     * (setAvatar): Es kommt als Datei und wird erst geschrieben, wenn die
     * Datei wirklich auf der Platte liegt.
     *
     * INSERT ... ON DUPLICATE KEY UPDATE, weil ein Guide ohne Profilzeile
     * moeglich ist (siehe forUser). Die neu entstehende Zeile bekommt KEINE
     * Zustimmung - terms_version bleibt auf der Vorgabe 0, und damit gilt
     * dieses Konto weiterhin als "muss den Bedingungen noch zustimmen"
     * (GuideRole::needsDecision). Das ist richtig so: Ein ausgefuelltes
     * Profil ist keine Zustimmung.
     *
     * Was hier ankommt, ist Fremdeingabe und wird zurechtgeschnitten, nicht
     * abgewiesen: Ein zu langer Name wird gekuerzt, unbekannte Sprachkuerzel
     * fallen weg (App\Helper\Languages). Ein Profil ist keine
     * Rechenaufgabe - wer 61 Zeichen eintippt, soll nicht vor einer
     * Fehlerseite stehen.
     *
     * @param int                 $in_user_id
     * @param array<string,mixed> $in_werte 'display_name', 'about', 'languages'
     * @return bool
     */
    public static function save($in_user_id, array $in_werte): bool
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return false;

        $name  = self::kuerze($in_werte['display_name'] ?? '', self::NAME_MAX);
        $about = self::kuerze($in_werte['about'] ?? '', self::ABOUT_MAX, true);
        $lang  = Languages::normalize($in_werte['languages'] ?? '');

        // Leeres Feld heisst NULL und nicht Leerstring: "nicht angegeben"
        // soll genau eine Schreibweise haben, sonst muss jede Lesestelle
        // beide kennen. Bei den Sprachen ist es umgekehrt - die Spalte ist
        // NOT NULL und traegt dort den Leerstring.
        $name_db  = $name  === '' ? null : $name;
        $about_db = $about === '' ? null : $about;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "INSERT INTO guide_profile (user_id, display_name, about, languages)
                 VALUES (:user_id, :display_name, :about, :languages)
                 ON DUPLICATE KEY UPDATE
                        display_name = VALUES(display_name),
                        about        = VALUES(about),
                        languages    = VALUES(languages)"
            );
            $stmt->bindParam(':user_id',      $user_id,  \PDO::PARAM_INT);
            $stmt->bindParam(':display_name', $name_db);
            $stmt->bindParam(':about',        $about_db);
            $stmt->bindParam(':languages',    $lang);
            $stmt->execute();
            return true;
        } catch (\PDOException $e) {
            error_log('GuideProfile::save: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Traegt das Avatarbild ein - oder nimmt es heraus.
     *
     * Geschrieben wird NUR der Basisname. Die Datei liegt da schon; der
     * Aufrufer (App\Controller\GuideProfileController) legt sie zuerst ab und
     * traegt sie erst danach ein. Andersherum entstuende eine Zeile, die auf
     * ein Bild verweist, das es nicht gibt - und das zeigt jede Seite als
     * kaputtes Bild.
     *
     * @param int         $in_user_id
     * @param string|null $in_name 32 Hexzeichen, oder null zum Entfernen
     * @return bool
     */
    public static function setAvatar($in_user_id, ?string $in_name): bool
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return false;

        // Dieselbe Pruefung wie beim Ablegen: Zwischen einem Namen und der
        // Datenbank soll keine Annahme stehen, sondern eine Pruefung.
        if ($in_name !== null && !\App\Helper\ImageStore::isValidName($in_name)) {
            error_log('GuideProfile::setAvatar: unbrauchbarer Dateiname fuer Benutzer #' . $user_id);
            return false;
        }

        try {
            $stmt = PdoConnect::$connection->prepare(
                "INSERT INTO guide_profile (user_id, avatar_file)
                 VALUES (:user_id, :avatar_file)
                 ON DUPLICATE KEY UPDATE avatar_file = VALUES(avatar_file)"
            );
            $stmt->bindParam(':user_id',     $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':avatar_file', $in_name);
            $stmt->execute();
            return true;
        } catch (\PDOException $e) {
            error_log('GuideProfile::setAvatar: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Der Basisname des Avatarbildes eines Kontos.
     *
     * Gebraucht an zwei Stellen: beim Ausliefern (welche Datei?) und beim
     * Ersetzen (welche Datei ist danach zu loeschen?).
     *
     * @param int $in_user_id
     * @return string|null null, wenn es kein Bild gibt
     */
    public static function avatarOf($in_user_id): ?string
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return null;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT avatar_file FROM guide_profile WHERE user_id = :user_id"
            );
            $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            $wert = $stmt->fetchColumn();

            return (is_string($wert) && $wert !== '') ? $wert : null;
        } catch (\PDOException $e) {
            error_log('GuideProfile::avatarOf: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Schneidet eine Eingabe auf ihre Laenge zurecht.
     *
     * mb_substr und nicht substr: Ein auf halber Strecke abgeschnittener
     * Umlaut ist kein Zeichen mehr, sondern ein Fragezeichen im Kasten.
     *
     * @param mixed $in_wert
     * @param int   $in_max
     * @param bool  $in_mehrzeilig Zeilenumbrueche behalten?
     * @return string
     */
    private static function kuerze($in_wert, int $in_max, bool $in_mehrzeilig = false): string
    {
        $text = is_scalar($in_wert) ? (string)$in_wert : '';

        // Steuerzeichen raus - in einem Namen hat ein Wagenruecklauf nichts
        // zu suchen, und in einem Fliesstext nichts ausser dem Zeilenumbruch.
        $text = $in_mehrzeilig
            ? preg_replace('/[^\P{C}\n]+/u', '', str_replace("\r\n", "\n", $text))
            : preg_replace('/\p{C}+/u', ' ', $text);

        $text = trim((string)$text);

        // Mehr als zwei Leerzeilen hintereinander sind Gestaltung mit der
        // Eingabetaste und blaehen jede Seite auf, die den Text zeigt.
        if ($in_mehrzeilig) {
            $text = preg_replace("/\n{3,}/", "\n\n", $text);
        }

        return mb_substr((string)$text, 0, $in_max);
    }
}
