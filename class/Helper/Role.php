<?php
namespace App\Helper;

/**
 * Zentrale Stelle für alles, was mit Benutzerrollen zu tun hat.
 *
 * ROLLEN UND IHRE NUMMERN
 * -----------------------
 * Die Nummern sind mit dem Umbau auf das Berechtigungssystem neu vergeben
 * worden (Migration 005). Sie stehen so in `usertype.id`:
 *
 *      0  Trial   frisch registriert, Guide-Frage noch offen
 *      1  User    Zuschauer, hat sich gegen die Guide-Rolle entschieden
 *      2  Guide   bietet Standorte an, hat der Rolle zugestimmt
 *     10  Admin   Benutzerverwaltung und Moderation
 *
 * Die Lücke zwischen 2 und 10 ist Absicht: Dort ist Platz für weitere
 * Rollen, die nicht gleich Admin sein sollen (etwa eine reine
 * Moderationsrolle). Weil die Nummern KEINE Rangfolge bilden, kostet das
 * Einfügen einer neuen Rolle nichts weiter als einen Eintrag in dieser Liste
 * und eine Zeile in App\Helper\Permission.
 *
 * KEINE RANGFOLGE, KEINE VERERBUNG
 * --------------------------------
 * Eine höhere Nummer bedeutet nicht "darf mehr". Ein Vergleich wie
 * `$role_id <= 1` oder `$role_id > 2` ist deshalb immer falsch, auch wenn er
 * für die heutige Nummernvergabe zufällig das Richtige täte. Genau solche
 * Vergleiche waren die Befunde F-5/F-6 und die Fehler in UserController.
 * tests/server_test.php verbietet sie dauerhaft: Vergleichsoperatoren auf
 * Rollenwerten lassen die Prüfung fehlschlagen.
 *
 * Wer wissen will, ob jemand etwas darf, fragt App\Helper\Permission nach
 * einem benannten Recht - nicht nach der Rolle.
 *
 * WARUM ÜBER DIE ID UND NICHT ÜBER DEN NAMEN ENTSCHIEDEN WIRD
 * ----------------------------------------------------------
 * Namen aus der Datenbank oder aus Formularen werden von self::id() vorher
 * auf die ID normalisiert - unabhängig von Groß- und Kleinschreibung und
 * unabhängig davon, ob die Rolle als int, als Zahlenstring oder als Name
 * ankommt (PDO liefert je nach Treiber-Einstellung '1' statt 1, ein `=== 1`
 * scheitert daran still).
 */
class Role
{
    /** usertype.id laut database.sql / Migration 005 */
    public const TRIAL = 0;
    public const USER  = 1;
    public const GUIDE = 2;
    public const ADMIN = 10;

    /**
     * Kanonische Schreibweise je ID, exakt wie in `usertype.name`.
     * Diese Liste ist die einzige Aufzählung aller Rollen im PHP-Code.
     */
    private const NAMES = [
        self::TRIAL => 'Trial',
        self::USER  => 'User',
        self::GUIDE => 'Guide',
        self::ADMIN => 'Admin',
    ];

    /**
     * Alle bekannten Rollen-IDs.
     *
     * @return int[]
     */
    public static function all(): array
    {
        return array_keys(self::NAMES);
    }

    /**
     * Normalisiert eine Rollenangabe auf die usertype.id.
     *
     * Akzeptiert int, Zahlenstring und Rollenname in beliebiger
     * Groß-/Kleinschreibung. Alles andere - auch null, Leerstring und
     * unbekannte Namen - ergibt null. Ein null-Ergebnis heißt "Rolle
     * unbekannt" und darf nirgends als Berechtigung gelesen werden.
     *
     * @param mixed $role
     * @return int|null
     */
    public static function id($role)
    {
        if (is_int($role)) {
            return isset(self::NAMES[$role]) ? $role : null;
        }
        if (is_string($role)) {
            $trimmed = trim($role);
            if ($trimmed === '') return null;

            // Zahlenstring: '1' und 1 müssen dasselbe bedeuten.
            if (ctype_digit($trimmed)) {
                $numeric = (int)$trimmed;
                return isset(self::NAMES[$numeric]) ? $numeric : null;
            }

            foreach (self::NAMES as $id => $name) {
                if (strcasecmp($trimmed, $name) === 0) return $id;
            }
        }
        return null;
    }

    /**
     * Gibt die kanonische Schreibweise der Rolle zurück ('Admin', 'Guide', ...).
     *
     * @param mixed $role
     * @return string|null null, wenn die Rolle unbekannt ist
     */
    public static function name($role)
    {
        $id = self::id($role);
        return $id === null ? null : self::NAMES[$id];
    }

    /**
     * Ist das die Guide-Rolle?
     *
     * Wird vom Signaling gebraucht, um die Rolle im Call zu vergeben - das
     * ist keine Berechtigung, sondern die Frage "ist dieses Konto vor Ort".
     *
     * @param mixed $role
     * @return bool
     */
    public static function isGuide($role)
    {
        return self::id($role) === self::GUIDE;
    }

    /**
     * Ist das die Admin-Rolle?
     *
     * Nur für Anzeigezwecke und Protokolleinträge gedacht. Für die Frage
     * "darf dieser Nutzer X" ist Permission::has() zuständig, sonst wandert
     * die Rechtevergabe wieder aus der Tabelle in den übrigen Code zurück.
     *
     * @param mixed $role
     * @return bool
     */
    public static function isAdmin($role)
    {
        return self::id($role) === self::ADMIN;
    }

    /**
     * Darf diese Rolle Standorte anbieten, also den Button
     * "Neue Lokation hinzufügen" sehen?
     *
     * Die Antwort kommt aus der Rechtetabelle, damit es für die Frage nur
     * eine Quelle gibt.
     *
     * @param mixed $role
     * @return bool
     */
    public static function mayOfferLocation($role)
    {
        return Permission::has($role, Permission::LOCATION_OFFER);
    }

    /**
     * Steht die Guide-Frage bei diesem Konto noch offen?
     *
     * Genau das bedeutet die Rolle Trial: nicht "eingeschränktes Konto",
     * sondern "hat sich noch nicht entschieden". Ein frisch registriertes
     * Konto ist Trial, bis der Dialog beantwortet ist - danach ist es Guide
     * oder User und wird nicht mehr gefragt.
     *
     * Wer die Frage tatsächlich stellt und wann, entscheidet
     * App\Model\GuideRole::needsDecision(); dort kommt der Fall dazu, dass
     * ein Guide einer neueren Fassung der Bedingungen zustimmen muss.
     *
     * @param mixed $role
     * @return bool
     */
    public static function isUndecided($role)
    {
        return self::id($role) === self::TRIAL;
    }

    /**
     * Ist das ein Konto, das die Guide-Rolle annehmen kann? Das betrifft
     * Trial und User - beide bieten bislang keine Standorte an.
     *
     * Das ist bewusst KEIN Recht, sondern ein Rollenwechsel: Die Frage
     * lautet nicht "darf er etwas", sondern "welche Rolle bekommt er
     * danach". Vollzogen wird der Wechsel ausschließlich in
     * App\Model\GuideRole - früher stieg man stillschweigend auf, sobald man
     * einen Standort anlegte.
     *
     * Der Admin steht bewusst nicht in dieser Liste: Er würde beim Wechsel
     * seine Adminrechte verlieren, und ein Klick in den Einstellungen darf
     * kein Konto entmachten.
     *
     * @param mixed $role
     * @return bool
     */
    public static function mayBecomeGuide($role)
    {
        $id = self::id($role);
        return $id === self::TRIAL || $id === self::USER;
    }
}
