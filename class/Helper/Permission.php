<?php
namespace App\Helper;

/**
 * Die Rechtetabelle der Anwendung.
 *
 * GRUNDSÄTZE
 * ----------
 * 1. BENANNTE RECHTE. Geprüft wird nie eine Rolle, sondern immer ein Recht:
 *    "darf dieser Nutzer Benutzer löschen" statt "ist dieser Nutzer Admin".
 *    Kommt später eine Rolle dazu, die nur Standorte sperren darf, bekommt
 *    sie genau dieses Recht - und kein Aufruf im übrigen Code ändert sich.
 *
 * 2. KEINE VERERBUNG. Jede Rolle führt alle ihre Rechte selbst auf. Der
 *    Admin erbt nichts vom Guide, der Guide nichts vom User. Das erzeugt
 *    Wiederholungen in dieser Datei - und genau die sind gewollt: Die Liste
 *    unten ist damit die vollständige, lesbare Antwort auf die Frage "was
 *    darf diese Rolle", ohne dass man Ketten verfolgen muss.
 *
 * 3. KEINE RANGFOLGE. Die Rollennummern (App\Helper\Role) sind Etiketten,
 *    keine Stufen. Es gibt keine Stelle, an der eine Rolle mit einer anderen
 *    verglichen wird.
 *
 * 4. VERBOTEN, SOLANGE NICHT ERLAUBT. Ein Recht, das hier bei einer Rolle
 *    nicht steht, hat sie nicht. Eine Route ohne eingetragenes Recht ist ein
 *    Konfigurationsfehler und wird von index.php abgewiesen, nicht
 *    durchgelassen.
 *
 * WO DAS GEPRÜFT WIRD
 * -------------------
 * Zentral in index.php, vor dem Aufruf des Controllers - siehe
 * config/routes.php, wo jede Route ihr Recht mitbringt. Controller prüfen nur
 * noch das, was eine Rechtetabelle nicht wissen kann: ob der Datensatz dem
 * Aufrufer gehört (Standorte) und ob er am Chat beteiligt ist.
 */
class Permission
{
    // -----------------------------------------------------------------
    // Rechte. Der Wert ist der Name, der in config/routes.php steht.
    // -----------------------------------------------------------------

    /** Startseite und öffentliche Einstiegsseiten. */
    public const SYSTEM_HOME = 'system.home';
    /** Adminbereich. */
    public const SYSTEM_ADMIN = 'system.admin';

    /** Anmeldeformular und Anmeldung. */
    public const AUTH_LOGIN = 'auth.login';
    /** Registrierung. */
    public const AUTH_SIGNUP = 'auth.signup';
    /** Abmelden. */
    public const AUTH_LOGOUT = 'auth.logout';
    /** Passwort vergessen / zurücksetzen (ohne Anmeldung). */
    public const AUTH_PASSWORD_RESET = 'auth.password_reset';
    /** Passwort ändern (angemeldet). */
    public const AUTH_PASSWORD_CHANGE = 'auth.password_change';
    /** Bestätigungslink aus der E-Mail aufrufen (ohne Anmeldung). */
    public const AUTH_EMAIL_VERIFY = 'auth.email_verify';
    /** Bestätigungsmail erneut anfordern (angemeldet). */
    public const AUTH_EMAIL_VERIFY_SEND = 'auth.email_verify_send';
    /** Zweiten Faktor beim Anmelden eingeben - läuft zwischen Login und Session. */
    public const AUTH_TWOFACTOR_VERIFY = 'auth.twofactor_verify';
    /** Zweiten Faktor einrichten oder abschalten. */
    public const AUTH_TWOFACTOR_MANAGE = 'auth.twofactor_manage';

    /** Benutzerliste sehen. */
    public const USER_LIST = 'user.list';
    /** Benutzer anlegen und bearbeiten (fremde Konten). */
    public const USER_MANAGE = 'user.manage';
    /** Benutzer löschen. */
    public const USER_DELETE = 'user.delete';
    /** Eigene Einstellungsseite. */
    public const USER_SETTINGS = 'user.settings';
    /** Online-Status melden (Heartbeat). */
    public const USER_PRESENCE = 'user.presence';
    /** Benutzernamen zu einer ID nachschlagen. */
    public const USER_READ_NAME = 'user.read_name';
    /**
     * Eigene Koordinaten übermitteln.
     *
     * Nur für Rollen, die Standorte anbieten. Für einen Zuschauer ist die
     * eigene Position ohne Bedeutung - er sucht sich einen Standort auf der
     * Karte aus, er wird nicht gefunden. Deshalb fragt der Login sie auch
     * nicht mehr bei jedem ab, sondern nur noch bei denen, die dieses Recht
     * haben (LoginController::continueAfterLogin).
     */
    public const USER_POSITION = 'user.position';

    /**
     * Über die eigene Guide-Rolle entscheiden.
     *
     * Kein Recht auf eine Fähigkeit, sondern der Zugang zu genau einer Frage:
     * "möchtest du Guide werden?". Wer sie beantworten darf, ist damit auch
     * derjenige, dem sie beim Login gestellt wird.
     *
     * Der Admin hat dieses Recht bewusst NICHT: Er würde beim Annehmen der
     * Guide-Rolle seine Adminrechte verlieren.
     */
    public const USER_GUIDE_ROLE = 'user.guide_role';

    /** Standortübersicht aufrufen. */
    public const LOCATION_PAGE = 'location.page';
    /**
     * Die oeffentliche Karte der Startseite abrufen.
     *
     * Das EINZIGE Standortrecht, das auch der Gast hat - und es steht
     * bewusst neben location.list statt darin: Die beiden Routen liefern
     * nicht dieselben Daten. location.list gibt die volle Zeile samt
     * Benutzername und user_id heraus, location.map_public nur Ort,
     * Beschreibung und einen von drei Verfuegbarkeitswerten
     * (App\Model\Location::selectPublicMapLocations).
     *
     * Waere es dasselbe Recht, muesste der Controller entscheiden, wie viel
     * er herausgibt - und diese Entscheidung waere beim naechsten Umbau als
     * Erstes vergessen.
     */
    public const LOCATION_MAP_PUBLIC = 'location.map_public';
    /** Fremde Standorte auflisten. */
    public const LOCATION_LIST = 'location.list';
    /** Eigene Standorte auflisten. */
    public const LOCATION_LIST_OWN = 'location.list_own';
    /** Länderliste für die Standortauswahl. */
    public const LOCATION_COUNTRY_LIST = 'location.country_list';
    /**
     * Einen Standort anlegen.
     *
     * Nur für Guides. Früher durfte das jeder Angemeldete, und genau dieser
     * Schritt machte aus einem Zuschauer stillschweigend einen Guide. Die
     * Rolle ist jetzt eine bewusste Entscheidung (App\Model\GuideRole); wer
     * sie nicht getroffen hat, bekommt statt des Standortformulars die Frage
     * danach.
     */
    public const LOCATION_CREATE = 'location.create';
    /**
     * Bietet bereits Standorte an.
     *
     * Kein Zugriffsrecht auf eine Route, sondern die Entscheidung über die
     * Beschriftung des Buttons: "Neue Lokation hinzufügen" statt "Jetzt
     * Tour-Guide werden!" (ui.js, Befund F-5).
     */
    public const LOCATION_OFFER = 'location.offer';
    /** Beschreibung eines EIGENEN Standorts ändern. */
    public const LOCATION_EDIT_OWN = 'location.edit_own';
    /** Einen EIGENEN Standort löschen. */
    public const LOCATION_DELETE_OWN = 'location.delete_own';
    /**
     * Einen fremden Standort sperren und wieder freigeben.
     *
     * Bewusst kein Löschrecht: Der Admin nimmt einen Standort aus der
     * Übersicht, die Daten bleiben beim Guide stehen und dieser bekommt in
     * seiner Standortliste den Grund angezeigt.
     */
    public const LOCATION_BLOCK = 'location.block';

    /** Chat mit einem anderen Nutzer beginnen. */
    public const CHAT_START = 'chat.start';
    /** Einladung annehmen oder ablehnen. */
    public const CHAT_ANSWER = 'chat.answer';
    /** Eigene Chats und Einladungen auflisten. */
    public const CHAT_LIST = 'chat.list';
    /** Nachrichten eines Chats lesen, an dem man beteiligt ist. */
    public const CHAT_READ = 'chat.read';
    /** Nachricht schreiben. */
    public const CHAT_WRITE = 'chat.write';

    /** WebRTC-Signalisierung. */
    public const RTC_SIGNAL = 'rtc.signal';
    /** ICE-/TURN-Zugangsdaten abrufen. */
    public const RTC_TURN = 'rtc.turn';

    /**
     * Schlüssel für "nicht angemeldet".
     *
     * Der Gast ist eine Rolle wie jede andere und führt seine Rechte unten
     * genauso auf. Damit gibt es keine Route, die "irgendwie ohne Prüfung"
     * erreichbar wäre: Auch die Startseite braucht ein Recht.
     */
    public const GUEST = 'guest';

    /**
     * Rechte je Rolle. Vollständig ausgeschrieben, keine Vererbung.
     *
     * Trial und User haben heute dieselben Rechte. Der Unterschied liegt
     * nicht in den Rechten, sondern in der Bedeutung: Trial heißt "die
     * Guide-Frage ist noch offen", User heißt "hat sich gegen die Guide-Rolle
     * entschieden". Nur ein Trial-Konto bekommt den Dialog beim Login
     * (App\Model\GuideRole::needsDecision).
     *
     * Die beiden Listen bleiben getrennt, damit sich Trial später
     * einschränken lässt, ohne dass irgendwo im Code etwas anderes
     * anzufassen wäre als diese Tabelle.
     */
    private const RIGHTS = [

        // -------------------------------------------------------------
        // Gast: nicht angemeldet.
        // -------------------------------------------------------------
        self::GUEST => [
            self::SYSTEM_HOME,
            // Die Startseite ist eine Karte. Ohne dieses Recht waere sie
            // fuer einen Gast leer - und das Angebot damit unsichtbar,
            // bevor er sich ueberhaupt entscheiden kann.
            self::LOCATION_MAP_PUBLIC,
            self::AUTH_LOGIN,
            self::AUTH_SIGNUP,
            self::AUTH_PASSWORD_RESET,
            self::AUTH_EMAIL_VERIFY,
            // Der zweite Faktor wird geprüft, wenn das Passwort schon stimmt,
            // die Session aber noch nicht steht. Formal ist das ein Gast.
            self::AUTH_TWOFACTOR_VERIFY,
        ],

        // -------------------------------------------------------------
        // Trial: frisch registriert, Guide-Frage noch offen.
        //
        // Kein location.create und kein user.position: Beides setzt voraus,
        // dass jemand Standorte anbietet - und genau das ist noch nicht
        // entschieden. Stattdessen user.guide_role, also der Zugang zu der
        // Frage.
        // -------------------------------------------------------------
        Role::TRIAL => [
            self::SYSTEM_HOME,
            self::AUTH_LOGOUT,
            self::AUTH_PASSWORD_CHANGE,
            self::AUTH_EMAIL_VERIFY_SEND,
            self::AUTH_TWOFACTOR_MANAGE,
            self::USER_LIST,
            self::USER_SETTINGS,
            self::USER_PRESENCE,
            self::USER_READ_NAME,
            self::USER_GUIDE_ROLE,
            self::LOCATION_PAGE,
            self::LOCATION_MAP_PUBLIC,
            self::LOCATION_LIST,
            self::LOCATION_LIST_OWN,
            self::LOCATION_COUNTRY_LIST,
            self::LOCATION_EDIT_OWN,
            self::LOCATION_DELETE_OWN,
            self::CHAT_START,
            self::CHAT_ANSWER,
            self::CHAT_LIST,
            self::CHAT_READ,
            self::CHAT_WRITE,
            self::RTC_SIGNAL,
            self::RTC_TURN,
        ],

        // -------------------------------------------------------------
        // User: Zuschauer, hat sich gegen die Guide-Rolle entschieden.
        //
        // Die eigene Position ist für ihn ohne Bedeutung (user.position
        // fehlt), Standorte legt er keine an (location.create fehlt). Seine
        // Entscheidung kann er jederzeit ändern - dafür user.guide_role.
        // -------------------------------------------------------------
        Role::USER => [
            self::SYSTEM_HOME,
            self::AUTH_LOGOUT,
            self::AUTH_PASSWORD_CHANGE,
            self::AUTH_EMAIL_VERIFY_SEND,
            self::AUTH_TWOFACTOR_MANAGE,
            self::USER_LIST,
            self::USER_SETTINGS,
            self::USER_PRESENCE,
            self::USER_READ_NAME,
            self::USER_GUIDE_ROLE,
            self::LOCATION_PAGE,
            self::LOCATION_MAP_PUBLIC,
            self::LOCATION_LIST,
            self::LOCATION_LIST_OWN,
            self::LOCATION_COUNTRY_LIST,
            self::LOCATION_EDIT_OWN,
            self::LOCATION_DELETE_OWN,
            self::CHAT_START,
            self::CHAT_ANSWER,
            self::CHAT_LIST,
            self::CHAT_READ,
            self::CHAT_WRITE,
            self::RTC_SIGNAL,
            self::RTC_TURN,
        ],

        // -------------------------------------------------------------
        // Guide: bietet Standorte an.
        //
        // Er hat der Rolle ausdrücklich zugestimmt (App\Model\GuideRole) und
        // kann sie über user.guide_role auch wieder zurückgeben.
        // -------------------------------------------------------------
        Role::GUIDE => [
            self::SYSTEM_HOME,
            self::AUTH_LOGOUT,
            self::AUTH_PASSWORD_CHANGE,
            self::AUTH_EMAIL_VERIFY_SEND,
            self::AUTH_TWOFACTOR_MANAGE,
            self::USER_LIST,
            self::USER_SETTINGS,
            self::USER_PRESENCE,
            self::USER_READ_NAME,
            self::USER_POSITION,
            self::USER_GUIDE_ROLE,
            self::LOCATION_PAGE,
            self::LOCATION_MAP_PUBLIC,
            self::LOCATION_LIST,
            self::LOCATION_LIST_OWN,
            self::LOCATION_COUNTRY_LIST,
            self::LOCATION_CREATE,
            self::LOCATION_OFFER,
            self::LOCATION_EDIT_OWN,
            self::LOCATION_DELETE_OWN,
            self::CHAT_START,
            self::CHAT_ANSWER,
            self::CHAT_LIST,
            self::CHAT_READ,
            self::CHAT_WRITE,
            self::RTC_SIGNAL,
            self::RTC_TURN,
        ],

        // -------------------------------------------------------------
        // Admin: Benutzerverwaltung und Moderation.
        //
        // Der Admin hat KEIN location.delete_own für fremde Standorte - er
        // sperrt sie (location.block). Löschen bleibt beim Eigentümer.
        //
        // Und er hat KEIN user.guide_role: Die Guide-Rolle anzunehmen würde
        // bedeuten, die Adminrechte abzugeben. Wer das wirklich will, lässt
        // die Rolle in der Benutzerverwaltung ändern.
        // -------------------------------------------------------------
        Role::ADMIN => [
            self::SYSTEM_HOME,
            self::SYSTEM_ADMIN,
            self::AUTH_LOGOUT,
            self::AUTH_PASSWORD_CHANGE,
            self::AUTH_EMAIL_VERIFY_SEND,
            self::AUTH_TWOFACTOR_MANAGE,
            self::USER_LIST,
            self::USER_MANAGE,
            self::USER_DELETE,
            self::USER_SETTINGS,
            self::USER_PRESENCE,
            self::USER_READ_NAME,
            self::USER_POSITION,
            self::LOCATION_PAGE,
            self::LOCATION_MAP_PUBLIC,
            self::LOCATION_LIST,
            self::LOCATION_LIST_OWN,
            self::LOCATION_COUNTRY_LIST,
            self::LOCATION_CREATE,
            self::LOCATION_OFFER,
            self::LOCATION_EDIT_OWN,
            self::LOCATION_DELETE_OWN,
            self::LOCATION_BLOCK,
            self::CHAT_START,
            self::CHAT_ANSWER,
            self::CHAT_LIST,
            self::CHAT_READ,
            self::CHAT_WRITE,
            self::RTC_SIGNAL,
            self::RTC_TURN,
        ],
    ];

    /**
     * Alle vergebenen Rechtenamen.
     *
     * Grundlage der Prüfung in config/routes.php: Ein dort eingetragenes
     * Recht, das keine Rolle kennt, wäre ein Tippfehler - und ein Tippfehler
     * in einem Rechtenamen würde eine Route für alle sperren oder, schlimmer,
     * unbemerkt ins Leere prüfen.
     *
     * @return string[]
     */
    public static function allRights(): array
    {
        $all = [];
        foreach (self::RIGHTS as $rights) {
            foreach ($rights as $right) {
                $all[$right] = true;
            }
        }
        $names = array_keys($all);
        sort($names);
        return $names;
    }

    /**
     * Ist das ein bekannter Rechtename?
     *
     * @param mixed $right
     * @return bool
     */
    public static function isKnownRight($right): bool
    {
        return is_string($right) && in_array($right, self::allRights(), true);
    }

    /**
     * Die Rechte einer Rolle.
     *
     * @param mixed $role Rollen-ID, Rollenname oder self::GUEST
     * @return string[] leer, wenn die Rolle unbekannt ist
     */
    public static function rightsOf($role): array
    {
        $key = self::roleKey($role);
        return $key === null ? [] : self::RIGHTS[$key];
    }

    /**
     * Hat diese Rolle dieses Recht?
     *
     * Unbekannte Rolle, unbekanntes Recht oder beides ergibt false. Es gibt
     * keinen Fall, in dem eine nicht auflösbare Rolle irgendetwas darf - auch
     * nicht das, was ein Gast dürfte.
     *
     * @param mixed  $role  Rollen-ID, Rollenname oder self::GUEST
     * @param string $right Einer der Rechtenamen dieser Klasse
     * @return bool
     */
    public static function has($role, $right): bool
    {
        if (!is_string($right) || $right === '') return false;
        return in_array($right, self::rightsOf($role), true);
    }

    /**
     * Erlaubte Werte für die Antwortart einer Route (Feld [3]).
     * @return string[]
     */
    public static function responseKinds(): array
    {
        return ['html', 'json'];
    }

    /**
     * Prüft die Routing-Tabelle auf Vollständigkeit.
     *
     * Der Kern der Regel "eine Route ohne definiertes Recht darf es nicht
     * geben": Die Tabelle wird bei jedem Aufruf komplett geprüft, nicht nur
     * der gerade angeforderte Eintrag. Ein Tippfehler in einem Rechtenamen
     * fällt damit beim ersten Seitenaufruf auf und nicht erst, wenn jemand
     * die betroffene Route benutzt.
     *
     * Gibt die Fehler als Text zurück, statt selbst etwas auszugeben - so
     * benutzt tests/server_test.php dieselbe Prüfung wie index.php.
     *
     * @param mixed $routes Inhalt von config/routes.php
     * @return string[] leer, wenn die Tabelle in Ordnung ist
     */
    public static function routeErrors($routes): array
    {
        if (!is_array($routes) || $routes === []) {
            return ['Die Routing-Tabelle ist leer oder kein Array.'];
        }

        $errors = [];
        foreach ($routes as $act => $route) {
            if (!is_string($act) || $act === '') {
                $errors[] = 'Routenname ist kein nicht-leerer String.';
                continue;
            }
            if (!is_array($route) || count($route) !== 4) {
                $errors[] = "Route '$act': erwartet werden vier Angaben "
                          . '[Controller, Methode, Recht, Antwortart].';
                continue;
            }

            [$class, $method, $right, $kind] = $route;

            if (!is_string($class) || $class === '') {
                $errors[] = "Route '$act': keine Controller-Klasse.";
            }
            if (!is_string($method) || $method === '') {
                $errors[] = "Route '$act': keine Methode.";
            }
            if (!is_string($right) || $right === '') {
                $errors[] = "Route '$act': kein Recht eingetragen.";
            } elseif (!self::isKnownRight($right)) {
                $errors[] = "Route '$act': unbekanntes Recht '$right'.";
            }
            if (!in_array($kind, self::responseKinds(), true)) {
                $errors[] = "Route '$act': Antwortart muss 'html' oder 'json' sein.";
            }
        }
        return $errors;
    }

    /**
     * Bildet eine Rollenangabe auf den Schlüssel der Rechtetabelle ab.
     *
     * @param mixed $role
     * @return int|string|null null, wenn die Rolle unbekannt ist
     */
    private static function roleKey($role)
    {
        if ($role === self::GUEST) return self::GUEST;

        $id = Role::id($role);
        if ($id === null) return null;

        return isset(self::RIGHTS[$id]) ? $id : null;
    }
}
