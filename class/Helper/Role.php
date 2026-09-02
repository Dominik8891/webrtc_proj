<?php
namespace App\Helper;

/**
 * Zentrale Stelle für alles, was mit Benutzerrollen zu tun hat.
 *
 * Hintergrund (Befunde F-5/F-6 der Bestandsaufnahme): Die Tabelle `usertype`
 * kennt genau vier Namen - 'Admin', 'Guide', 'User', 'Trial' (database.sql).
 * An mehreren Stellen wurde stattdessen gegen kleingeschriebene Literale und
 * gegen ein nie existierendes 'tourist' verglichen. Diese Vergleiche konnten
 * nie zutreffen: Der Button "Neue Lokation hinzufügen" blieb für jede Rolle
 * unsichtbar und der Aufstieg Zuschauer -> Guide fand nie statt.
 *
 * Deshalb wird ab hier ausschließlich über die numerische `usertype.id`
 * entschieden. Namen aus der Datenbank oder aus Formularen werden von
 * self::id() vorher auf diese ID normalisiert - unabhängig von Groß- und
 * Kleinschreibung und unabhängig davon, ob die Rolle als int, als
 * Zahlenstring oder als Name ankommt (PDO liefert je nach Treiber-Einstellung
 * '1' statt 1, ein `=== 1` scheitert daran still).
 *
 * Wer eine Rollenprüfung braucht, benutzt eine der Prädikatsfunktionen. Neue
 * Vergleiche gegen Rollen-Strings gehören nicht mehr in den übrigen Code.
 */
class Role
{
    /** usertype.id laut database.sql */
    public const ADMIN = 0;
    public const GUIDE = 1;
    public const USER  = 2;
    public const TRIAL = 3;

    /**
     * Kanonische Schreibweise je ID, exakt wie in `usertype.name`.
     * Reihenfolge = ID, damit die Liste beim Lesen mit der Tabelle abgleichbar bleibt.
     */
    private const NAMES = [
        self::ADMIN => 'Admin',
        self::GUIDE => 'Guide',
        self::USER  => 'User',
        self::TRIAL => 'Trial',
    ];

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
     * @param mixed $role
     * @return bool
     */
    public static function isGuide($role)
    {
        return self::id($role) === self::GUIDE;
    }

    /**
     * Ist das die Admin-Rolle?
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
     * @param mixed $role
     * @return bool
     */
    public static function mayOfferLocation($role)
    {
        $id = self::id($role);
        return $id === self::ADMIN || $id === self::GUIDE;
    }

    /**
     * Ist das ein Zuschauerkonto, das durch das Anlegen eines Standorts zum
     * Guide aufsteigt? Das betrifft 'User' und 'Trial' - beide bieten bislang
     * keine Standorte an, und ein Standort ohne Guide-Rolle wäre nutzlos.
     *
     * @param mixed $role
     * @return bool
     */
    public static function mayBecomeGuide($role)
    {
        $id = self::id($role);
        return $id === self::USER || $id === self::TRIAL;
    }
}
