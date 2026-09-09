<?php

namespace App\Model;

use PDOException;
use App\Helper\LogHelper;
use App\Helper\Role;

/**
 * Klasse zur Verwaltung der Benutzerinformationen und Interaktionen mit der Datenbank.
 */
class User
{
    // Private Attribute für Benutzerinformationen
    private $id;
    private $status;
    private $last_aktive;
    private $username;
    private $email;
    private $pwd;
    private $type_id;
    private $deleted;
    private $verified;
    private $totp_secret;
    private $totp_enabled;
    private $theme;
    private $lang;
    private $available_until;

    /**
     * Konstruktor: Lädt existierenden User oder legt neuen an.
     * @param int $in_id User-ID
     * @throws \Exception wenn Benutzer nicht gefunden
     */
    public function __construct($in_id = 0)
    {
        if ($in_id > 0) {
            try {
                $stmt = PdoConnect::$connection->prepare(
                    "SELECT * FROM user WHERE id = :user_id;"
                );
                $stmt->bindParam(':user_id', $in_id);
                $stmt->execute();
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($result) {
                    $this->id           = $result['id'];
                    $this->username     = $result['username'];
                    $this->email        = $result['email'];
                    $this->pwd          = $result['pwd'];
                    $this->type_id      = $result['type_id'];
                    $this->deleted      = $result['deleted'];
                    $this->status       = $result['user_status'] ?? null;
                    $this->totp_secret  = $result['totp_secret'] ?? null;
                    $this->totp_enabled = $result['totp_enabled'] ?? 0;
                    // Der Bestaetigungsstand der Adresse. Er wurde hier bisher
                    // NICHT geladen - deshalb lief die Anzeige in den
                    // Einstellungen ins Leere, und deshalb stand sie
                    // auskommentiert da.
                    $this->verified     = $result['email_verified'] ?? 0;
                    // ?? null faengt die Installation ab, in der Migration
                    // 008 noch nicht eingespielt ist: Dann fehlt die Spalte,
                    // und das Konto bekommt einfach das Standardprofil.
                    $this->theme        = $result['theme'] ?? null;
                    // Und ebenso Migration 022. Fehlt die Spalte, bestimmt
                    // sich die Sprache aus Cookie und Accept-Language - die
                    // Anwendung laeuft weiter, nur ohne Kontosprache.
                    $this->lang         = $result['lang'] ?? null;
                    // Ebenso Migration 010. Fehlt die Spalte, ist das Konto
                    // schlicht nie bereit - die Anwendung laeuft weiter, nur
                    // ohne Bereitschaftsschalter.
                    $this->available_until = $result['available_until'] ?? null;
                } else {
                    throw new \Exception("Benutzer mit ID {$in_id} nicht gefunden.");
                }
            } catch (\PDOException $e) {
                error_log("Fehler beim Laden des Benutzers: " . $e->getMessage());
                throw new \Exception("Fehler beim Laden des Benutzers.");
            }
        } else {
            $this->id = 0;
            $this->deleted = 0;
        }
    }

    /**
     * Erstellt einen neuen Benutzer in der DB.
     *
     * Die Rolle ist fest die Einstiegsrolle Trial und kommt aus
     * App\Helper\Role - vorher stand die nackte 3 im SQL-Text. Mit der
     * Neuvergabe der Rollennummern (Migration 005) waere daraus stillschweigend
     * eine unbelegte Nummer geworden und der Fremdschluessel auf usertype
     * haette jede Registrierung abgewiesen.
     *
     * Aus einem Formularfeld kommt die Rolle bewusst NICHT: signup.html
     * schickte einmal ein verstecktes type_id mit. Ausgewertet wurde es nie,
     * aber ein selbstvergebener Rang beim Registrieren darf gar nicht erst
     * moeglich aussehen - das Feld ist deshalb entfernt.
     *
     * DIESE METHODE FAENGT NICHTS MEHR AB, UND DAS IST DER KERN
     * ----------------------------------------------------------
     * Vorher stand hier ein try/catch, das die PDOException protokollierte
     * und null zurueckgab. Den Rueckgabewert hat register() aber gar nicht
     * angesehen - es las danach unbedingt lastInsertId(). Und lastInsertId()
     * liefert nach einem GESCHEITERTEN INSERT nicht etwa 0, sondern den
     * letzten Wert, den DIESE VERBINDUNG erzeugt hat.
     *
     * WAS DARAUS WURDE: Im Registrierungsablauf laeuft unmittelbar vorher
     * RateLimit::verbuchen('signup_formular') - ein INSERT in eine Tabelle
     * mit AUTO_INCREMENT. War die Zaehlerzeile fuer diese IP neu, stand
     * anschliessend IHRE Kennung in $user_id. Die Registrierung meldete dann
     * Erfolg, buchte ein Konto, das es nicht gibt, und schickte eine
     * Bestaetigungsmail an das FREMDE Konto mit genau dieser Kennung. Gab es
     * die Zaehlerzeile schon, war lastInsertId() gleich 0, und der Nutzer sah
     * "ein unbekannter Fehler ist aufgetreten" - dieselbe Ursache, zwei
     * voellig verschiedene Auswirkungen, je nachdem, ob es der erste Versuch
     * von dieser Adresse war.
     *
     * Die Ausnahme geht deshalb an den Aufrufer. Er ist der einzige, der
     * entscheiden kann, ob ein doppelter Schluessel eine Stoerung ist oder
     * die normale Antwort auf eine schon vergebene Adresse (register()).
     *
     * lastInsertId() steht jetzt hinter dem geglueckten execute() und nur
     * dort - so wie an allen anderen Stellen des Projekts auch.
     *
     * @return int Die neue Benutzerkennung, immer groesser als 0
     * @throws \PDOException   wenn der INSERT scheitert - insbesondere
     *                         SQLSTATE 23000 bei doppelter E-Mail-Adresse
     * @throws \LogicException wenn das Objekt bereits eine Kennung traegt
     */
    private function create(): int
    {
        // Vorher ein stilles "return;". Ein Objekt, das schon eine Kennung
        // traegt, ein zweites Mal anlegen zu wollen ist ein Fehler im
        // Aufrufer und kein Zustand, den man wegschweigt - er kam als
        // "erfolgreich angelegt" beim Nutzer an.
        if ($this->id > 0) {
            throw new \LogicException('User::create() auf einem Konto, das es schon gibt (#' . $this->id . ').');
        }

        $stmt = PdoConnect::$connection->prepare(
            "INSERT INTO user ( username,  email,  pwd,  type_id) 
                      VALUES  (:username, :email, :pwd, :type_id)"
        );
        $default_role = Role::TRIAL;
        $stmt->bindParam(":username", $this->username);
        $stmt->bindParam(":email", $this->email);
        $stmt->bindParam(":pwd", $this->pwd);
        $stmt->bindParam(":type_id", $default_role, \PDO::PARAM_INT);
        $stmt->execute();

        $this->id = (int)PdoConnect::$connection->lastInsertId();
        return $this->id;
    }

    /**
     * Aktualisiert den Benutzer in der DB.
     *
     * `available_until` steht bewusst NICHT in diesem UPDATE. Die Bereitschaft
     * ist ein fluechtiger Zustand mit eigenen Schreibstellen
     * (startAvailability, extendAvailability, endAvailability); ein
     * beilaeufiges save() - etwa aus dem Heartbeat, der ohnehin jede Sekunde
     * laeuft - darf sie weder verlaengern noch loeschen. Aus demselben Grund
     * fehlen hier auch `theme` und `lang`: Beide Spalten kommen aus einer
     * Migration, die eine bestehende Installation vielleicht nicht eingespielt
     * hat, und dann duerfte deren Fehlen hoechstens die Einstellung kosten und
     * nicht jede Aenderung an einem Benutzer.
     *
     * @return bool Erfolg
     */
    private function update()
    {
        if ($this->id < 1) return false;
        try {
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE user SET
                    username        = :username,
                    user_status     = :status,
                    email           = :email,
                    pwd             = :pwd,
                    type_id         = :type_id,
                    updated_at      = CURRENT_TIMESTAMP,
                    deleted         = :deleted,
                    totp_secret     = :totp_secret,
                    totp_enabled    = :totp_enabled
                WHERE id = :user_id;"
            );
            $stmt->bindParam(":user_id"         , $this->id);
            $stmt->bindParam(":username"        , $this->username);
            $stmt->bindParam(":status"          , $this->status);
            $stmt->bindParam(":email"           , $this->email);
            $stmt->bindParam(":pwd"             , $this->pwd);
            $stmt->bindParam(":type_id"         , $this->type_id);
            $stmt->bindParam(":deleted"         , $this->deleted);
            $stmt->bindParam(":totp_secret"     , $this->totp_secret);
            $stmt->bindParam(":totp_enabled"    , $this->totp_enabled);
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            error_log("Fehler beim Aktualisieren des Benutzers: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Speichert (erstellt oder aktualisiert) einen Benutzer.
     *
     * Gibt zurueck, ob das geklappt hat. Vorher warf diese Methode das
     * Ergebnis von update() bzw. create() weg und lieferte immer null - ein
     * `if (!$user->save())` beim Aufrufer war damit stets wahr, und ein
     * fehlgeschlagener Rollenwechsel haette wie ein erfolgreicher ausgesehen.
     * Gebraucht wird die Antwort seit App\Model\GuideRole.
     *
     * @return bool true, wenn der Datensatz geschrieben wurde
     */
    public function save()
    {
        if ($this->id > 0) {
            return $this->update();
        }

        // create() reicht seine Ausnahme jetzt durch (siehe dort). Hier wird
        // sie aufgefangen, weil save() ein Ja/Nein verspricht - anders als
        // register(), das den GRUND braucht und ihn deshalb selbst auswertet.
        try {
            return $this->create() > 0;
        } catch (\Exception $e) {
            error_log('Fehler beim Erstellen des Benutzers: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Speichert das Farbprofil dieses Kontos.
     *
     * WARUM EIN EIGENES STATEMENT UND NICHT update()
     * ----------------------------------------------
     * update() schreibt alle Spalten des Benutzers in einem Zug. Stuende
     * `theme` dort mit drin, wuerde in einer Installation ohne Migration 008
     * JEDE Aenderung an einem Benutzer scheitern - Passwortwechsel,
     * Rollenwechsel, Heartbeat. Ein fehlendes Farbprofil darf hoechstens die
     * Farbwahl kosten und nicht die Anwendung.
     *
     * Der Wert wird vorher geprueft: In die Spalte kommt nur, was
     * App\Helper\Theme kennt.
     *
     * @param string $profil Schluessel aus App\Helper\Theme::PROFILE
     * @return bool true, wenn gespeichert wurde
     */
    public function saveTheme($profil)
    {
        if ($this->id < 1)                  return false;
        if (!\App\Helper\Theme::isValid($profil)) return false;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE user SET theme = :theme WHERE id = :user_id;"
            );
            $stmt->bindParam(':theme'  , $profil);
            $stmt->bindParam(':user_id', $this->id);
            $stmt->execute();
            $this->theme = $profil;
            return true;
        } catch (PDOException $e) {
            // Fehlt die Spalte, steht das hier im Log und sonst passiert
            // nichts. Der Nutzer sieht sein Profil bis zum naechsten Laden.
            error_log("Farbprofil konnte nicht gespeichert werden: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Speichert die Sprache dieses Kontos.
     *
     * Wortgleich zu saveTheme() darueber, und aus demselben Grund ein eigenes
     * Statement: Stuende `lang` in update(), wuerde in einer Installation
     * ohne Migration 022 JEDE Aenderung an einem Benutzer scheitern. Eine
     * fehlende Sprachspalte darf hoechstens die Sprachwahl kosten und nicht
     * die Anwendung.
     *
     * Der Wert wird vorher geprueft: In die Spalte kommt nur, was
     * App\Helper\I18n kennt.
     *
     * @param string $in_sprache Kuerzel aus App\Helper\I18n::SPRACHEN
     * @return bool true, wenn gespeichert wurde
     */
    public function saveLang($in_sprache)
    {
        if ($this->id < 1)                        return false;
        if (!\App\Helper\I18n::isValid($in_sprache)) return false;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE user SET lang = :lang WHERE id = :user_id;"
            );
            $stmt->bindParam(':lang'   , $in_sprache);
            $stmt->bindParam(':user_id', $this->id);
            $stmt->execute();
            $this->lang = $in_sprache;
            return true;
        } catch (PDOException $e) {
            // Fehlt die Spalte, steht das hier im Log und sonst passiert
            // nichts. Der Nutzer sieht seine Sprache bis zum naechsten Laden.
            error_log("Sprache konnte nicht gespeichert werden: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Die Sprache eines Kontos - ohne den ganzen Datensatz zu laden.
     *
     * WARUM EIN EIGENER LESEWEG. Gefragt wird das bei JEDER Anfrage, ganz am
     * Anfang (index.php ruft App\Helper\I18n::start), und zwar bevor
     * ueberhaupt feststeht, ob die Seite einen Benutzer braucht. Ein
     * "new User(...)" dafuer holte jedes Mal alle Spalten samt Passworthash
     * herbei, um ein Kuerzel mit zwei Zeichen zu lesen.
     *
     * Genau wie bei availableSeconds() darueber: eine Frage, eine Spalte.
     *
     * @param mixed $in_user_id
     * @return string|null Das Kuerzel, oder null wenn keines gesetzt ist,
     *                     die Spalte fehlt oder das Konto unbekannt ist
     */
    public static function lang($in_user_id): ?string
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return null;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT lang FROM user WHERE id = :id"
            );
            $stmt->bindParam(':id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $wert = ($row && isset($row['lang'])) ? $row['lang'] : null;
            return is_string($wert) && $wert !== '' ? $wert : null;
        } catch (PDOException $e) {
            // Fehlt die Spalte (Migration 022 nicht eingespielt), ist das
            // kein Fehler, den ein Besucher merken muesste: Die Sprache
            // kommt dann aus Cookie oder Browser.
            error_log("Sprache konnte nicht gelesen werden: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Markiert den Benutzer als gelöscht.
     * @return void
     */
    public function del_it()
    {
        if ($this->id < 1) return;
        $this->deleted = 1;
        $this->update();
    }

    /** Grund eines Fehlschlags in register(): Name oder Adresse ist vergeben. */
    public const REG_VERGEBEN  = 'vergeben';

    /** Grund eines Fehlschlags in register(): alles andere. */
    public const REG_UNBEKANNT = 'unbekannt';

    /**
     * Registriert einen neuen Benutzer nach Validierung.
     *
     * DER GRUND GEHT MIT ZURUECK, UND ZWAR ALS ZWEITER WERT
     * -----------------------------------------------------
     * Vorher gab es nur "Kennung oder null". Der Aufrufer konnte damit nicht
     * unterscheiden, ob die Adresse vergeben ist - etwas, das der Nutzer
     * selbst beheben kann - oder ob der Server eine Stoerung hat. Beides kam
     * als "Ein unbekannter Fehler ist aufgetreten." beim Nutzer an.
     *
     * Deshalb der Ausgabeparameter: Die Rueckgabe bleibt die Kennung (kein
     * Aufrufer muss sich aendern, der den Grund nicht braucht), und wer ihn
     * braucht, reicht eine Variable hinein.
     *
     * SQLSTATE 23000 IST DER DOPPELTE SCHLUESSEL. Dasselbe Vorgehen wie in
     * App\Model\TourReview::create(): Der doppelte Schluessel ist hier keine
     * Stoerung, sondern die Regel - der eindeutige Index auf user.email (und
     * seit Migration 020 auch auf user.username) ist die letzte
     * Verteidigungslinie hinter der Vorabpruefung im Controller. Zwischen
     * dieser Pruefung und dem INSERT liegt ein Fenster, in dem sich ein
     * zweiter Aufruf denselben Namen sichern kann; genau dafuer ist der Index
     * da, und genau deshalb darf sein Zuschlagen keine Fehlermeldung sein.
     *
     * WELCHER der beiden Schluessel zugeschlagen hat, wird bewusst NICHT
     * ausgewertet: Das stuende nur im Klartext der Treibermeldung
     * ("Duplicate entry '...' for key 'user.email'"), und darauf eine
     * Fallunterscheidung zu bauen hiesse, den Wortlaut einer fremden
     * Fehlermeldung zur Schnittstelle zu erklaeren. Der Controller nennt
     * deshalb im Rennfall beide Angaben.
     *
     * @param  string      $in_username
     * @param  string      $in_email
     * @param  string      $in_pwd
     * @param  string|null $out_grund Bei Fehlschlag REG_VERGEBEN oder
     *                                REG_UNBEKANNT, bei Erfolg null
     * @return int|null               User-ID oder null bei Fehler
     */
    public function register($in_username, $in_email, $in_pwd, &$out_grund = null) {
        // Zuerst zuruecksetzen: Der Aufrufer koennte dieselbe Variable ein
        // zweites Mal hineinreichen, und ein alter Grund neben einer neuen
        // Kennung waere schlimmer als gar keiner.
        $out_grund = null;

        // Die drei folgenden Pruefungen kann der Controller nicht ausloesen -
        // er prueft Name, Adresse und Passwortlaenge vorher und strenger.
        // Sie bleiben als Absicherung der oeffentlichen Methode stehen; wer
        // hier hereinlaeuft, hat einen Fehler im Aufrufer und keinen in der
        // Eingabe. Deshalb REG_UNBEKANNT und nicht etwa eine eigene Meldung.
        if (!preg_match('/^[\w]{3,20}$/', $in_username)) {
            error_log("Ungültiger Username: $in_username");
            $out_grund = self::REG_UNBEKANNT;
            return null;
        }
        if (!filter_var($in_email, FILTER_VALIDATE_EMAIL)) {
            // Eingabewert nur maskiert loggen. Er ist hier zwar ungueltig,
            // kann aber trotzdem eine echte Adresse mit Tippfehler sein.
            error_log("Ungültige E-Mail: " . LogHelper::maskEmail($in_email));
            $out_grund = self::REG_UNBEKANNT;
            return null;
        }
        if (strlen($in_pwd) < 3) {
            error_log("Zu kurzes Passwort für Benutzer: $in_username");
            $out_grund = self::REG_UNBEKANNT;
            return null;
        }

        try {
            $hased_pwd = $this->pwdEncrypt($in_pwd);
            $this->username = $in_username;
            $this->email    = $in_email;
            $this->pwd      = $hased_pwd;

            // DIE KENNUNG KOMMT AUS create() UND NICHT MEHR AUS EINEM ZWEITEN
            // lastInsertId(). Der zweite Aufruf war der Fehler: Er lief auch
            // dann, wenn der INSERT gescheitert war, und lieferte dann die
            // Kennung des zuletzt eingefuegten FREMDEN Datensatzes. Siehe
            // create().
            return $this->create();
        } catch (PDOException $e) {
            // PDOException VOR \Exception - sie ist eine davon, und PHP
            // nimmt den ersten passenden Zweig.
            if ($e->getCode() === '23000') {
                // Kein Eintrag mit der Adresse: Sie steht im Klartext in der
                // Treibermeldung, und das Log dieser Anwendung fuehrt keine
                // Adressen im Klartext (App\Helper\LogHelper).
                error_log('Registrierung abgewiesen: Benutzername oder E-Mail-Adresse '
                    . 'ist bereits vergeben (Benutzername ' . $in_username . ').');
                $out_grund = self::REG_VERGEBEN;
                return null;
            }
            error_log("Fehler bei der Benutzer-Registrierung: " . $e->getMessage());
            $out_grund = self::REG_UNBEKANNT;
            return null;
        } catch (\Exception $e) {
            // Hierher kommt vor allem der fehlende PEPPER aus pwdEncrypt().
            error_log("Fehler bei der Benutzer-Registrierung: " . $e->getMessage());
            $out_grund = self::REG_UNBEKANNT;
            return null;
        }
    }

    /**
     * Authentifiziert einen Benutzer anhand Username und Passwort.
     * @param string $in_username
     * @param string $in_pwd
     * @return bool Erfolg (true) oder Fehler (false)
     */
    public function login($in_username, $in_pwd)
    {
        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT * FROM user WHERE username = :username AND deleted = 0"
            );
            $stmt->bindParam(":username", $in_username);
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$result) return false;

            $pepper = $_ENV['PEPPER'];
            if (!$pepper) {
                error_log("PEPPER nicht gesetzt!");
                return false;
            }
            $pwd_peppered = hash_hmac("sha256", $in_pwd, $pepper);

            if (password_verify($pwd_peppered, $result['pwd'])) {
                $update_stmt = PdoConnect::$connection->prepare(
                    "UPDATE user SET updated_at = CURRENT_TIMESTAMP WHERE id = :id;"
                );
                $update_stmt->bindParam(':id', $result['id']);
                $update_stmt->execute();

                // User-Objekt mit allen Daten füllen!
                $this->id           = $result['id'];
                $this->username     = $result['username'];
                $this->email        = $result['email'];
                $this->pwd          = $result['pwd'];
                $this->type_id      = $result['type_id'];
                $this->deleted      = $result['deleted'];
                $this->verified     = $result['email_verified'];
                $this->totp_secret  = $result['totp_secret'] ?? null;
                $this->totp_enabled = $result['totp_enabled'] ?? 0;

                return true;
            } else {
                return false;
            }
        } catch (PDOException $e) {
            error_log("Fehler beim Login: " . $e->getMessage());
            return false;
        }
    }

    /** Kennung ist frei - es gibt keine Zeile dazu. */
    public const KENNUNG_FREI      = 'frei';

    /** Kennung gehoert einem bestehenden Konto. */
    public const KENNUNG_VERGEBEN  = 'vergeben';

    /** Kennung gehoert einem GELOESCHTEN Konto und bleibt trotzdem belegt. */
    public const KENNUNG_GELOESCHT = 'geloescht';

    /**
     * DIE BEIDEN VORABPRUEFUNGEN FRAGEN NICHT MEHR NACH `deleted`
     * ===========================================================
     * Hier stand "... AND deleted = 0", und genau das war der Fehler.
     *
     * Loeschen setzt in dieser Anwendung nur ein Kennzeichen (del_it()) - die
     * Zeile bleibt stehen, mit Benutzername und E-Mail-Adresse darin. Der
     * eindeutige Index auf user.email kennt das Kennzeichen aber nicht; fuer
     * ihn ist die Adresse belegt. Die Pruefung sagte also "frei", der INSERT
     * scheiterte, und der Nutzer bekam "Ein unbekannter Fehler ist
     * aufgetreten." - eine Meldung, mit der er nichts anfangen kann, weil die
     * Auskunft, die er braucht, im Logfile stand.
     *
     * Beim BENUTZERNAMEN war es schlimmer und stiller: Dort gab es gar keinen
     * eindeutigen Index (Befund S-14), also scheiterte auch nichts - es
     * entstand einfach ein zweites Konto mit demselben Namen. Migration 020
     * zieht den Index nach, damit hier und in der Datenbank dieselbe Regel
     * gilt.
     *
     * WARUM DREI ZUSTAENDE UND NICHT ZWEI: Weil der Nutzer bei einem
     * geloeschten Konto etwas anderes tun muss als bei einem bestehenden. Bei
     * einer vergebenen Adresse hilft "Passwort vergessen"; bei der Adresse
     * eines geloeschten Kontos hilft gar nichts, was der Nutzer selbst tun
     * kann - dann muss er den Betreiber fragen. Ein blosses true/false koennte
     * ihm das nicht sagen.
     *
     * ORDER BY deleted: Tragen zwei Zeilen dieselbe Kennung - beim
     * Benutzernamen bis Migration 020 moeglich -, entscheidet die des
     * LEBENDEN Kontos. "Vergeben" ist die Auskunft, die weiterhilft;
     * "geloescht" waere daneben falsch.
     *
     * @return string KENNUNG_FREI, KENNUNG_VERGEBEN oder KENNUNG_GELOESCHT
     */
    public function usernameStand(): string
    {
        return self::standVon('username', $this->username);
    }

    /**
     * Ist die E-Mail-Adresse noch zu haben? Siehe usernameStand().
     *
     * @return string KENNUNG_FREI, KENNUNG_VERGEBEN oder KENNUNG_GELOESCHT
     */
    public function emailStand(): string
    {
        return self::standVon('email', $this->email);
    }

    /**
     * Die gemeinsame Abfrage hinter usernameStand() und emailStand().
     *
     * EINE METHODE UND NICHT ZWEIMAL DERSELBE RUMPF: Die beiden unterschieden
     * sich vorher nur in einem Spaltennamen - und beide trugen denselben
     * Fehler. Genau so entstehen zwei Fassungen einer Regel, von denen die
     * zweite beim naechsten Mal vergessen wird.
     *
     * Der Spaltenname kommt aus dem Code und nie aus einer Anfrage; er wird
     * trotzdem gegen eine feste Liste geprueft, damit das auch dann noch gilt,
     * wenn hier einmal jemand etwas durchreicht.
     *
     * @param  string $in_spalte 'username' oder 'email'
     * @param  mixed  $in_wert   Der gesuchte Wert
     * @return string
     */
    private static function standVon(string $in_spalte, $in_wert): string
    {
        if (!in_array($in_spalte, ['username', 'email'], true)) {
            throw new \LogicException("User::standVon(): unbekannte Spalte '$in_spalte'.");
        }
        if (!is_scalar($in_wert) || (string)$in_wert === '') return self::KENNUNG_FREI;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT deleted FROM user WHERE `$in_spalte` = :wert ORDER BY deleted ASC LIMIT 1"
            );
            $wert = (string)$in_wert;
            $stmt->bindParam(':wert', $wert);
            $stmt->execute();
            $gefunden = $stmt->fetchColumn();

            if ($gefunden === false) return self::KENNUNG_FREI;
            return ((int)$gefunden === 1) ? self::KENNUNG_GELOESCHT : self::KENNUNG_VERGEBEN;
        } catch (PDOException $e) {
            error_log("Fehler bei der Pruefung von $in_spalte: " . $e->getMessage());
            // FREI und nicht VERGEBEN: Diese Abfrage ist die Bequemlichkeit,
            // nicht die Absicherung. Wer sie bei einer Stoerung auf "vergeben"
            // stellt, weist eine gueltige Registrierung mit einer Meldung ab,
            // die nicht stimmt. Die verbindliche Antwort gibt der eindeutige
            // Index, und der antwortet gleich darauf beim INSERT
            // (register(), SQLSTATE 23000).
            return self::KENNUNG_FREI;
        }
    }

    /**
     * Setzt den User-Status und aktualisiert updated_at.
     * @param string $status
     * @return void
     */
    public function setUserStatus($status)
    {
        try {
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE user SET user_status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id;"
            );
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $this->id);
            $stmt->execute();
        } catch (PDOException $e) {
            error_log("Fehler beim Setzen des User-Status: " . $e->getMessage());
        }
    }

    /**
     * Holt den Status eines Users.
     * @param int $userId
     * @return string|null
     */
    public function getUserStatus($userId)
    {
        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT user_status FROM user WHERE id = ?"
            );
            $stmt->execute([$userId]);
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result['user_status'] ?? null;
        } catch (PDOException $e) {
            error_log("Fehler beim Holen des User-Status: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Sucht einen User per ID.
     * @param int $userId
     * @return array|null
     */
    public function getUserById($userId)
    {
        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT * FROM user WHERE id = ?"
            );
            $stmt->execute([$userId]);
            return $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Fehler bei getUserById: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Sucht nach Usernames.
     * @param array $userIds
     * @return array
     */
    public static function getUsernamesByIds(array $userIds): array
    {
        if (empty($userIds)) return [];
        $in  = str_repeat('?,', count($userIds) - 1) . '?';
        // deleted kommt mit: Der Benutzername eines geloeschten Kontos wird
        // nicht mehr herausgegeben - er ist die Anmeldekennung und gehoert zu
        // einem Konto, das es nicht mehr gibt. Stehen bleibt ein Platzhalter,
        // damit ein Chatverlauf nicht namenlos wird (siehe nameGeloescht()).
        $stmt = PdoConnect::$connection->prepare(
            "SELECT id, username, deleted FROM user WHERE id IN ($in)"
        );
        $stmt->execute($userIds);
        $usernames = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $usernames[$row['id']] = ((int)$row['deleted'] === 1)
                ? self::nameGeloescht()
                : $row['username'];
        }
        return $usernames;
    }

    /**
     * Aktualisiert User-Status (Kurzversion).
     * @param int $userId
     * @param string $status
     * @return void
     */
    public function updateUserStatus($userId, $status)
    {
        try {
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE user SET user_status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $stmt->execute([$status, $userId]);
        } catch (PDOException $e) {
            error_log("Fehler bei updateUserStatus: " . $e->getMessage());
        }
    }

    // ===================================================================
    // Bereitschaft ("bereit zu fuehren")
    //
    // ANGEMELDET IST NICHT VERFUEGBAR. user_status sagt, ob ein Browser
    // dieses Kontos erreichbar ist - mehr nicht. Ob der Guide auch fuehren
    // WILL, steht allein in available_until, und dorthin kommt es nur durch
    // eine ausdrueckliche Entscheidung: den Schalter in der Kopfleiste.
    //
    // Alle vier Methoden schreiben und lesen ausschliesslich diese eine
    // Spalte. Sie rechnen mit NOW() der Datenbank und nicht mit time() aus
    // PHP: Sonst entschiede die Uhr des Webservers ueber einen Wert, den die
    // Datenbank spaeter gegen ihre eigene Uhr vergleicht.
    // ===================================================================

    /**
     * Stellt ein Konto auf bereit - oder verlaengert eine laufende
     * Bereitschaft auf die volle Frist.
     *
     * @param int $in_user_id
     * @param int $in_seconds Dauer ab jetzt (config/presence.php)
     * @return bool Erfolg
     */
    public static function startAvailability($in_user_id, $in_seconds): bool
    {
        $user_id = (int)$in_user_id;
        $seconds = (int)$in_seconds;
        if ($user_id < 1 || $seconds < 1) return false;

        try {
            // Die Dauer geht als gebundener Wert in INTERVAL ... SECOND und
            // nicht als Textbaustein in die Abfrage - auch wenn sie aus einer
            // Konfigurationsdatei stammt und nicht vom Aufrufer.
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE user SET available_until = DATE_ADD(NOW(), INTERVAL :seconds SECOND)
                 WHERE id = :id"
            );
            $stmt->bindParam(':seconds', $seconds, \PDO::PARAM_INT);
            $stmt->bindParam(':id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            error_log("Fehler beim Einschalten der Bereitschaft: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verlaengert eine LAUFENDE Bereitschaft.
     *
     * Der Unterschied zu startAvailability() steckt allein im WHERE: Eine
     * abgelaufene oder ausgeschaltete Bereitschaft wird hier NICHT wieder
     * angeschaltet. Sonst koennte ein Klick nach dem Ablauf einen Guide
     * unbemerkt wieder auf die Karte holen - er hat den Ablauf womoeglich gar
     * nicht bemerkt, und wieder bereit ist man nur auf Knopfdruck.
     *
     * Aufgerufen vom Heartbeat, wenn der Browser seit dem letzten Takt echte
     * Bedienung oder ein laufendes Gespraech gemeldet hat.
     *
     * @param int $in_user_id
     * @param int $in_seconds Neue Restdauer ab jetzt
     * @return bool true, wenn tatsaechlich verlaengert wurde
     */
    public static function extendAvailability($in_user_id, $in_seconds): bool
    {
        $user_id = (int)$in_user_id;
        $seconds = (int)$in_seconds;
        if ($user_id < 1 || $seconds < 1) return false;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE user SET available_until = DATE_ADD(NOW(), INTERVAL :seconds SECOND)
                 WHERE id = :id
                   AND available_until IS NOT NULL
                   AND available_until > NOW()"
            );
            $stmt->bindParam(':seconds', $seconds, \PDO::PARAM_INT);
            $stmt->bindParam(':id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Fehler beim Verlaengern der Bereitschaft: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Beendet die Bereitschaft sofort.
     *
     * Drei Anlaesse, und alle drei sind ein Ende und keine Pause: der Schalter
     * in der Kopfleiste, das Schliessen der Seite (assets/js/availability.js
     * schickt dafuer eine Beacon-Nachricht) und das Abmelden
     * (App\Controller\LoginController::handleLogout).
     *
     * Geschrieben wird auf NULL und nicht auf einen vergangenen Zeitpunkt:
     * NULL heisst "es liegt keine Entscheidung vor" und ist damit dasselbe wie
     * bei einem Konto, das noch nie bereit war.
     *
     * @param int $in_user_id
     * @return bool Erfolg
     */
    public static function endAvailability($in_user_id): bool
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return false;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE user SET available_until = NULL WHERE id = :id"
            );
            $stmt->bindParam(':id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            error_log("Fehler beim Beenden der Bereitschaft: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Wie lange steht dieses Konto noch auf bereit?
     *
     * Die Restzeit rechnet die DATENBANK aus (TIMESTAMPDIFF), nicht PHP: So
     * wird derselbe Zeitpunkt gegen dieselbe Uhr gehalten, gegen die ihn auch
     * jede Standortabfrage haelt (Location::AVAILABILITY_SQL).
     *
     * @param int $in_user_id
     * @return int Verbleibende Sekunden; 0 heisst "nicht bereit"
     */
    public static function availableSeconds($in_user_id): int
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return 0;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), available_until)) AS rest
                 FROM user
                 WHERE id = :id AND available_until IS NOT NULL"
            );
            $stmt->bindParam(':id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row ? (int)$row['rest'] : 0;
        } catch (PDOException $e) {
            error_log("Fehler beim Lesen der Bereitschaft: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Ist dieses Konto gerade bereit?
     *
     * NUR die Bereitschaft, nicht die Erreichbarkeit. Wer wissen will, ob ein
     * Standort gruen auf der Karte steht, braucht beides - und bekommt es
     * fertig ausgewertet aus Location::AVAILABILITY_SQL.
     *
     * @param int $in_user_id
     * @return bool
     */
    public static function isAvailable($in_user_id): bool
    {
        return self::availableSeconds($in_user_id) > 0;
    }

    /**
     * Gibt User-Info als Array zurück.
     * @return array|null
     */
    public function get_user_info_as_array()
    {
        if ($this->id < 0) {
            error_log("Fehler: keine User Info vorhanden!");
            return null;
        }
        return [
            $this->status,
            $this->username,
            $this->email
        ];
    }

    // =================================================================
    // GELOESCHTE KONTEN
    //
    // "Geloescht" heisst in dieser Anwendung: deleted = 1. Die Zeile bleibt
    // stehen (del_it() setzt nur das Kennzeichen), damit vergangene
    // Fuehrungen, Bewertungen und Abrechnungen nachvollziehbar bleiben.
    //
    // DARAUS FOLGT EINE PFLICHT, die vorher an vielen Stellen fehlte: JEDE
    // Abfrage, die etwas an andere Nutzer ausliefert, muss das Kennzeichen
    // selbst pruefen. Der Fremdschluessel hilft dabei nicht - ON DELETE
    // CASCADE greift nur bei einem echten DELETE, und das findet nie statt.
    // Ein geloeschtes Konto behielt deshalb seine Nadeln auf der Karte,
    // seine Standortseiten, seine Bilder und seine Erreichbarkeit.
    // =================================================================

    /**
     * "Dieses Konto gibt es noch" - als SQL-Bedingung.
     *
     * DIE EINE FASSUNG DIESER REGEL, aus demselben Grund wie
     * App\Model\Location::AVAILABILITY_SQL: Sie steht in einem Dutzend
     * Abfragen, und ausgeschrieben waere sie ein Dutzend Gelegenheiten, sie
     * beim naechsten Umbau an einer Stelle zu vergessen. Genau das ist
     * vorher passiert - nur eben von Anfang an.
     *
     * DER ALIAS IST PFLICHTPARAMETER MIT VORGABE: Dieselben Abfragen
     * verbinden die Tabelle mal als `user`, mal als `g`, `k` oder `partner`.
     * Geprueft wird er trotzdem, wie jeder Textbaustein in einer Abfrage
     * (dieselbe Regel wie App\Model\TourRequest::alias).
     *
     * @param string $in_alias Tabellenalias in der Abfrage
     * @return string SQL-Bedingung
     */
    public static function activeSql(string $in_alias = 'user'): string
    {
        $sauber = preg_replace('/[^a-zA-Z_]/', '', $in_alias);
        if ($sauber === '') $sauber = 'user';

        return "$sauber.deleted = 0";
    }

    /**
     * Ist dieses Konto geloescht?
     *
     * FUER DIE STELLEN, AN DENEN KEINE ABFRAGE STEHT, sondern eine
     * Entscheidung: Darf dieser Anruf zustande kommen, darf in diesen Chat
     * geschrieben werden. Dort gibt es keine WHERE-Klausel, in die sich
     * activeSql() einsetzen liesse.
     *
     * EIN UNBEKANNTES KONTO GILT ALS GELOESCHT. Das ist die sichere Seite:
     * Wer nicht gefunden wird, bekommt nichts - und nicht "im Zweifel doch".
     *
     * Der Zwischenspeicher lebt nur fuer die Dauer der Anfrage. Ohne ihn
     * fragte eine einzige Rollenvergabe im Signaling zweimal nach derselben
     * Zeile (Anrufer, Angerufener), und das bei jedem ausgelieferten Offer.
     *
     * @param int $in_user_id
     * @return bool
     */
    public static function isDeleted($in_user_id): bool
    {
        static $bekannt = [];

        $id = (int)$in_user_id;
        if ($id < 1) return true;
        if (array_key_exists($id, $bekannt)) return $bekannt[$id];

        // DIESE METHODE LAEUFT IM STARTPFAD - App\Helper\Auth::
        // discardOutdatedSession() ruft sie bei jedem angemeldeten Aufruf,
        // bevor irgendein Controller dran ist. Faellt sie aus, faellt die
        // ganze Anwendung aus, und zwar auf JEDER Seite; genau das ist einmal
        // passiert, weil die Verbindung erst drei Zeilen spaeter aufgebaut
        // wurde.
        //
        // Der Aufruf ist idempotent und kostet nichts, wenn die Verbindung
        // steht - und eine Testattrappe ueberschreibt er nicht. Er ist kein
        // Ersatz fuer die richtige Reihenfolge in index.php, sondern der
        // Gurt daneben.
        PdoConnect::sicherstellen();

        try {
            $stmt = PdoConnect::$connection->prepare(
                'SELECT deleted FROM user WHERE id = :id'
            );
            $stmt->bindParam(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $wert = $stmt->fetchColumn();

            $bekannt[$id] = ($wert === false) || ((int)$wert === 1);
        } catch (PDOException $e) {
            error_log('User::isDeleted: ' . $e->getMessage());
            // Im Fehlerfall die sichere Seite: nichts ausliefern.
            $bekannt[$id] = true;
        }
        return $bekannt[$id];
    }

    /**
     * Ist die E-Mail-Adresse dieses Kontos bestaetigt?
     *
     * WOZU: An dieser Frage haengt die Bestaetigungspflicht
     * (App\Helper\MailGate). Gefragt wird sie in index.php, aber NUR auf den
     * wenigen Routen, die eine bestaetigte Adresse voraussetzen - auf jeder
     * anderen Seite faellt keine Abfrage an.
     *
     * AUS DER DATENBANK UND NICHT AUS DER SITZUNG. Der Bestaetigungsstand
     * aendert sich MITTEN in einer laufenden Sitzung: Der Nutzer klickt den
     * Link in der Mail, und danach soll die Anwendung sofort wieder alles
     * erlauben. Ein Wert in $_SESSION wuerde bis zur naechsten Anmeldung das
     * Gegenteil behaupten - der Nutzer bestaetigt und bleibt trotzdem
     * gesperrt.
     *
     * Der Zwischenspeicher gilt fuer EINEN Aufruf, so wie bei isDeleted():
     * Innerhalb eines Seitenaufrufs kann sich der Wert nicht aendern.
     *
     * @param  int|string $in_user_id
     * @return bool
     */
    public static function isEmailVerified($in_user_id): bool
    {
        static $bekannt = [];

        $id = (int)$in_user_id;
        if ($id < 1) return false;
        if (array_key_exists($id, $bekannt)) return $bekannt[$id];

        PdoConnect::sicherstellen();

        try {
            $stmt = PdoConnect::$connection->prepare(
                'SELECT email_verified FROM user WHERE id = :id'
            );
            $stmt->bindParam(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $wert = $stmt->fetchColumn();

            $bekannt[$id] = ($wert !== false) && ((int)$wert === 1);
        } catch (PDOException $e) {
            error_log('User::isEmailVerified: ' . $e->getMessage());
            // Im Fehlerfall die sichere Seite - dieselbe Ueberlegung wie bei
            // isDeleted(): im Zweifel nicht durchlassen.
            $bekannt[$id] = false;
        }
        return $bekannt[$id];
    }

    /**
     * Der Name, der fuer ein geloeschtes Konto stehenbleibt.
     *
     * WARUM UEBERHAUPT ETWAS STEHENBLEIBT: Ein Chatverlauf gehoert BEIDEN
     * Seiten. Wer mit jemandem geschrieben hat, darf seine eigenen
     * Nachrichten behalten - und dann muss in der Liste etwas stehen, wo
     * vorher ein Name stand. Was dort NICHT mehr stehen darf, ist der
     * Benutzername: Er ist die Anmeldekennung und gehoert zu einem Konto,
     * das es nicht mehr gibt.
     *
     * EINE METHODE UND KEINE KONSTANTE MEHR. Der Platzhalter steht in einer
     * Chatliste und wird gelesen - er ist Oberflaeche und gehoert damit in
     * den Sprachkatalog. Eine Konstante kann I18n::t() nicht aufrufen: Der
     * Text haengt an der Sprache DIESER Anfrage und steht nicht schon beim
     * Laden der Klasse fest.
     *
     * @return string
     */
    public static function nameGeloescht(): string
    {
        return \App\Helper\I18n::t('konto.geloescht');
    }

    /**
     * Gibt alle User-IDs als Array zurück.
     *
     * OHNE DIE GELOESCHTEN, und das war hier schon immer so. Der Unterschied
     * zu den anderen Listen der Verwaltung ist nur, dass sie sich bisher
     * ueberhaupt nicht einblenden liessen: Ein geloeschtes Konto war
     * unauffindbar, auch fuer den Admin. Auf die Frage "ist das Konto von
     * gestern wirklich weg" gab es damit keine Antwort - und das ist genau
     * die Frage, die nach einer Loeschung gestellt wird.
     *
     * @param bool $in_mit_geloeschten Geloeschte Konten mitliefern
     * @return array
     */
    public function getAll(bool $in_mit_geloeschten = false)
    {
        // Fester Textbaustein, kein Parameter: In die Abfrage kommt nichts,
        // was ein Aufrufer beeinflussen koennte.
        $filter = $in_mit_geloeschten ? '' : ' WHERE ' . self::activeSql('user');

        $result = [];
        try {
            $query = "SELECT user.id FROM user" . $filter . ";";
            foreach (PdoConnect::$connection->query($query) as $row) {
                $result[] = $row['id'];
            }
            return $result;
        } catch (PDOException $e) {
            error_log("Fehler bei getAll: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Holt die Bezeichnung des User-Typs (Rolle) aus der Datenbank.
     *
     * Achtung: Der Rueckgabewert ist die Schreibweise aus usertype.name, also
     * 'Admin', 'Guide', 'User' oder 'Trial'. Fuer Rollenpruefungen ist er
     * nicht gedacht - genau solche Vergleiche gegen kleingeschriebene
     * Literale waren die Befunde F-5/F-6. Wer eine Rolle pruefen will,
     * benutzt getRoleId() zusammen mit App\Helper\Role.
     *
     * @return string|false
     */
    public function getUsertype()
    {
        if ($this->id > 0) {
            try {
                $stmt = PdoConnect::$connection->prepare(
                    "SELECT * FROM usertype WHERE id = :type_id"
                );
                $stmt->bindParam(":type_id", $this->type_id);
                $stmt->execute();
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                return $result ? $result['name'] : false;
            } catch (PDOException $e) {
                error_log("Fehler beim Holen des Usertypes: " . $e->getMessage());
                return false;
            }
        } else {
            error_log('kein gültiger user');
            return null;
        }
    }

    /**
     * Gibt alle Usertypen als Array zurück.
     * @return array
     */
    public function getAllUsertypesAsArray() {
        $result = [];
        try {
            $query = "SELECT id, name FROM usertype";
            foreach (PdoConnect::$connection->query($query) as $row) {
                $result[$row['id']] = $row['name'];
            }
            return $result;
        } catch (PDOException $e) {
            error_log("Fehler bei getAllUsertypesAsArray: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Setzt die Userrolle anhand Name oder ID.
     *
     * Aufgeloest wird ueber App\Helper\Role statt ueber eine eigene Abfrage.
     * Das ersetzt zwei Fehler auf einmal:
     *   - Die alte Fassung nahm jede Zahl an, auch eine, die es in usertype
     *     gar nicht gibt; gespeichert wurde sie trotzdem und erst der
     *     Fremdschluessel schlug beim save() fehl.
     *   - Sie verlangte `$id > 0` und lehnte damit die Rolle mit der Nummer 0
     *     ab - frueher Admin, heute Trial. Genau die Einstiegsrolle war so
     *     nicht setzbar.
     *
     * @param mixed $in_usertype Rollenname oder Rollen-ID
     * @return bool Erfolg
     */
    public function setUsertype($in_usertype)
    {
        $id = Role::id($in_usertype);
        if ($id === null) {
            error_log('setUsertype: unbekannte Rolle ' . var_export($in_usertype, true));
            return false;
        }
        $this->type_id = $id;
        return true;
    }

    // saveLocation() ist entfallen. Die Methode schrieb nach
    // user.latitude/longitude/location_updated_at - drei Spalten, die keine
    // einzige Abfrage im Projekt liest. Der Dialog, der sie fuellte, warb mit
    // einer Umkreissuche, die es nicht gibt. Die Spalten bleiben vorerst in
    // der Datenbank stehen (database.sql, dort als ungenutzt vermerkt);
    // geschrieben werden sie nicht mehr.

    /**
     * Verschlüsselt das Passwort mit Pepper und Argon2i.
     * @param string $in_pwd
     * @return string Hash
     * @throws \Exception wenn kein Pepper gesetzt
     */
    public function pwdEncrypt($in_pwd)
    {
        $pepper = $_ENV['PEPPER'];
        if (!$pepper) {
            error_log("PEPPER nicht gesetzt!");
            throw new \Exception("Fehlende Server-Konfiguration.");
        }
        $pwd_peppered = hash_hmac("sha256", $in_pwd, $pepper);
        $pwd_hashed = password_hash($pwd_peppered, PASSWORD_ARGON2I);
        return $pwd_hashed;
    }

    /**
     * Gibt die wichtigsten Userdaten als Array zurück.
     * @return array
     */
    public function getUserDetails() {
        return [
            'user_id'        => $this->id,
            'username'       => $this->username,
            'email'          => $this->email,
            // Normalisiert, damit ueberall dieselbe Darstellung ankommt: PDO
            // liefert die Nummer je nach Einstellung als '2' statt 2.
            'role_id'        => Role::id($this->type_id),
            'email_verified' => $this->verified
            // ggf. weitere Werte
        ];      
    }

    // Setter-Methoden 
    public function setId($in_id)               { $this->id = $in_id; }                     
    public function setStatus($in_status)       { $this->status = $in_status; }
    public function setUsername($in_username)   { $this->username = $in_username; }
    public function setEmail($in_email)         { $this->email = $in_email; }
    public function setPwd($in_pwd)             { $this->pwd = $in_pwd; }
    /**
     * Setzt die Rolle. Gleichbedeutend mit setUsertype() - beide Namen sind
     * im Code in Gebrauch, und beide muessen dieselbe Pruefung durchlaufen:
     * eine ungueltige Rolle wird abgelehnt, statt bis zum Fremdschluessel
     * durchzurutschen.
     *
     * @param mixed $in_role_id
     * @return bool Erfolg
     */
    public function setRoleId($in_role_id)      { return $this->setUsertype($in_role_id); }
    public function setTotpSecret($secret)      { $this->totp_secret = $secret; }
    public function setTotpEnabled($enabled)    { $this->totp_enabled = $enabled ? 1 : 0; }

    // Getter-Methoden 
    public function getId()             { return $this->id; }
    public function getStatus()         { return $this->status; }
    public function getUsername()       { return $this->username; }
    public function getEmail()          { return $this->email; }
    public function getPwd()            { return $this->pwd; }
    public function getRoleId()         { return $this->type_id; }
    public function getTotpSecret()     { return $this->totp_secret; }
    public function getTotpEnabled()    { return $this->totp_enabled; }

    /**
     * Ist die E-Mail-Adresse DIESES geladenen Kontos bestaetigt?
     *
     * Der Wert steht in der Zeile, die der Konstruktor ohnehin geladen hat -
     * anders als bei der statischen isEmailVerified(), die eine Kennung ohne
     * Datensatz beantwortet. Wer den Benutzer schon in der Hand hat, fragt
     * hier und spart die zweite Abfrage.
     *
     * @return bool
     */
    public function getEmailVerified(): bool { return (int)$this->verified === 1; }

    /**
     * Das gespeicherte Farbprofil, roh wie in der Datenbank.
     *
     * Kann null sein (nie gewaehlt) oder ein Profil nennen, das es nicht mehr
     * gibt. Wer einen benutzbaren Wert braucht, schickt das Ergebnis durch
     * App\Helper\Theme::normalize().
     *
     * @return string|null
     */
    public function getTheme()          { return $this->theme; }
    public function getLang()           { return $this->lang; }

    /**
     * Ist DIESES geladene Konto geloescht?
     *
     * Der Wert steht in der Zeile, die der Konstruktor ohnehin geladen hat -
     * anders als bei der statischen isDeleted(), die eine Kennung ohne
     * Objekt beantwortet und dafuer selbst fragt. Wer das Konto schon in der
     * Hand hat, soll dafuer keine zweite Abfrage ausloesen.
     */
    public function isGeloescht(): bool  { return (int)$this->deleted === 1; }
}
