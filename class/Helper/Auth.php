<?php
namespace App\Helper;

use App\Model\User;

/**
 * Der angemeldete Benutzer und seine Rechte.
 *
 * Alles, was den Anmeldezustand aus der Session liest, läuft über diese
 * Klasse. Vorher stand `$_SESSION['user']['user_id']` an rund zwanzig
 * Stellen, jede mit ihrer eigenen Vorstellung davon, was ein fehlender Wert
 * bedeutet - drei Endpunkte prüften ihn gar nicht.
 *
 * Die Rechtefrage beantwortet App\Helper\Permission; hier wird nur die Rolle
 * des angemeldeten Nutzers dazugereicht.
 */
class Auth
{
    /**
     * Aufbau der Sitzungsdaten.
     *
     * Wird hochgezählt, wenn sich die Bedeutung der gespeicherten Werte
     * ändert. Eine Session mit einer anderen Nummer wird verworfen, der
     * Nutzer muss sich neu anmelden.
     *
     * Stand 2: Die Rollennummern sind mit Migration 005 neu vergeben worden
     * (aus Admin=0 wurde Trial=0, aus User=2 wurde Guide=2). Eine Session
     * von vorher trüge damit stillschweigend die falsche Rolle - und im Fall
     * User -> Guide sogar mehr Rechte als vorher. Deshalb gelten alte
     * Sessions als ungültig.
     */
    public const SESSION_SCHEME = 2;

    /**
     * Legt die Sitzungsdaten für einen erfolgreich angemeldeten Benutzer an.
     *
     * Einzige Stelle, an der `$_SESSION['user']` geschrieben wird. Die Rolle
     * wird dabei normalisiert: Was aus der Datenbank kommt, ist je nach
     * PDO-Einstellung '2' oder 2, und ein unbekannter Wert darf nicht als
     * Rolle in der Session landen.
     *
     * @param User $user Der angemeldete Benutzer
     * @return void
     */
    public static function establish(User $user): void
    {
        $_SESSION['auth_scheme'] = self::SESSION_SCHEME;
        $_SESSION['user'] = [
            'user_id'  => (int)$user->getId(),
            'username' => $user->getUsername(),
            'email'    => $user->getEmail(),
            'role_id'  => Role::id($user->getRoleId()),
        ];
    }

    /**
     * Verwirft Sitzungsdaten, die nicht mehr zum aktuellen Aufbau passen.
     *
     * Wird von index.php vor jeder Rechteprüfung aufgerufen. Betroffen sind
     * zwei Fälle:
     *   1. Die Session stammt aus einer Version mit anderen Rollennummern.
     *   2. Die gespeicherte Rolle lässt sich nicht auflösen (etwa weil die
     *      Rolle inzwischen entfernt wurde).
     * Beides führt zur Abmeldung und nicht etwa zu einer Notfallrolle: Ein
     * Konto ohne auflösbare Rolle darf nichts.
     *
     * @return void
     */
    public static function discardOutdatedSession(): void
    {
        if (!isset($_SESSION['user'])) return;

        $scheme = $_SESSION['auth_scheme'] ?? null;
        $role   = Role::id($_SESSION['user']['role_id'] ?? null);
        $userId = (int)($_SESSION['user']['user_id'] ?? 0);

        if ($scheme !== self::SESSION_SCHEME || $role === null || $userId < 1) {
            error_log('Auth: veraltete oder unvollstaendige Sitzung verworfen.');
            unset($_SESSION['user'], $_SESSION['auth_scheme']);
        }
    }

    /**
     * Ist jemand angemeldet?
     * @return bool
     */
    public static function isLoggedIn(): bool
    {
        return self::userId() > 0;
    }

    /**
     * ID des angemeldeten Benutzers.
     * @return int 0, wenn niemand angemeldet ist
     */
    public static function userId(): int
    {
        return (int)($_SESSION['user']['user_id'] ?? 0);
    }

    /**
     * Name des angemeldeten Benutzers.
     *
     * Nur für die Anzeige gedacht. Wer ein Konto ADRESSIERT - laden,
     * ändern, löschen -, nimmt userId(): Der Name ist zwar eindeutig, aber
     * die Kennung ist der Schlüssel, an dem die Daten hängen.
     *
     * @return string Leerstring, wenn niemand angemeldet ist
     */
    public static function username(): string
    {
        $name = $_SESSION['user']['username'] ?? '';
        return is_scalar($name) ? (string)$name : '';
    }

    /**
     * Rolle des angemeldeten Benutzers.
     * @return int|null null, wenn niemand angemeldet oder die Rolle unbekannt ist
     */
    public static function roleId()
    {
        if (!isset($_SESSION['user']['role_id'])) return null;
        return Role::id($_SESSION['user']['role_id']);
    }

    /**
     * Schlüssel für die Rechtetabelle: die Rolle oder "Gast".
     *
     * @return int|string
     */
    public static function roleKey()
    {
        $role = self::roleId();
        return ($role === null || !self::isLoggedIn()) ? Permission::GUEST : $role;
    }

    /**
     * Darf der aktuelle Aufrufer das?
     *
     * @param string $right Rechtename aus App\Helper\Permission
     * @return bool
     */
    public static function can(string $right): bool
    {
        return Permission::has(self::roleKey(), $right);
    }

    /**
     * Übernimmt eine geänderte Rolle in die laufende Sitzung.
     *
     * Gebraucht beim Aufstieg Zuschauer -> Guide: Ohne Auffrischung liefe
     * der Nutzer bis zum nächsten Login mit den alten Rechten weiter.
     *
     * @param mixed $role Neue Rolle (ID oder Name)
     * @return void
     */
    public static function refreshRole($role): void
    {
        if (!self::isLoggedIn()) return;
        $id = Role::id($role);
        if ($id === null) return;
        $_SESSION['user']['role_id'] = $id;
    }
}
