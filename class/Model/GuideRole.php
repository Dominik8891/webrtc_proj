<?php

namespace App\Model;

use App\Helper\Role;

/**
 * Die Guide-Rolle: annehmen, zurueckgeben, nachschlagen.
 *
 * DIE EINZIGE STELLE, DIE DIE GUIDE-ROLLE VERGIBT
 * -----------------------------------------------
 * Vorher stand der Rollenwechsel mitten in
 * LocationController::setLocation(): Wer einen Standort anlegte, wurde
 * stillschweigend Guide. Das war eine Entscheidung ueber die Rolle, die
 * niemand getroffen hat, an einer Stelle, an der niemand sie sucht.
 *
 * Jetzt laeuft jeder Wechsel durch accept() und resign(). Das ist nicht nur
 * Ordnung: Kuenftig kostet jede Fuehrung Geld, und damit haengt an der
 * Guide-Rolle eine Abrechnung. Die Pruefungen, die dann dazukommen (sind
 * Zahlungsdaten hinterlegt, sind Abrechnungen offen), gehoeren in diese
 * beiden Methoden - und nur dorthin. Waere der Rollenwechsel weiter ueber den
 * Code verteilt, muesste man sie an jeder Stelle nachziehen.
 *
 * WAS HIER BEWUSST NOCH NICHT PASSIERT
 * ------------------------------------
 * Es wird nichts berechnet, nichts abgebucht und kein Preis gespeichert. Was
 * heute schon geschieht, ist ausschliesslich das Festhalten der Zustimmung:
 * wer, wann, zu welcher Fassung der Bedingungen. Genau das laesst sich
 * nachtraeglich nicht mehr herstellen - der Rest schon.
 *
 * ZUSTIMMUNG UND ROLLE SIND ZWEI DINGE
 * ------------------------------------
 * Die Rolle steht in `user.type_id` und entscheidet ueber Rechte. Die
 * Zustimmung steht in `guide_profile` und ueberdauert den Widerruf: Wer die
 * Rolle abgibt, behaelt seine Zeile mit `resigned_at`. Eine Abrechnung muss
 * auch fuer beendete Guide-Verhaeltnisse noch nachvollziehbar sein.
 */
class GuideRole
{
    /**
     * Fassung der Guide-Bedingungen, die der Dialog heute anzeigt.
     *
     * DER HEBEL FUER DIE SPAETERE ABRECHNUNG. Wird diese Zahl hochgezaehlt,
     * weil sich die Bedingungen aendern - etwa weil Fuehrungen kostenpflichtig
     * werden -, gilt jede aeltere Zustimmung als ueberholt: needsDecision()
     * meldet sich dann fuer diesen Guide.
     *
     * WO SICH DAS AUSWIRKT
     * --------------------
     *   1. SPERREND beim Anlegen eines Standorts. Das Formular fuehrt zur
     *      Frage, statt sich zu oeffnen
     *      (App\Controller\GuideController::requireCurrentTerms, aufgerufen
     *      aus App\Controller\LocationController). Das ist die Stelle, an
     *      der ein Guide seine Rolle wirklich benutzt - und die einzige, die
     *      nicht in Echtzeit laeuft.
     *   2. SICHTBAR in den Einstellungen ("Aktiv - neue Bedingungen offen")
     *      und auf dem Knopf der Kopfleiste ("Neue Bedingungen bestaetigen",
     *      ueber window.userCan.termsOutdated aus ViewHelper::output).
     *
     * NICHT im Signalweg: Waehrend es klingelt, kann der Guide nichts
     * entscheiden, und der Anrufer bekaeme eine Absage, die nicht stimmt.
     * Bestehende Standorte bleiben ebenfalls stehen - sie aus der Karte zu
     * nehmen waere still, der Guide merkte nur, dass niemand mehr anruft.
     * Das ist der richtige Schritt erst, wenn wirklich abgerechnet wird.
     *
     * Beim Hochzaehlen ist zweierlei zu tun:
     *   1. diese Konstante erhoehen,
     *   2. den Text in assets/html/guide_role.html anpassen.
     * Der Rest stellt sich von selbst ein - aber siehe den Hinweis bei
     * rememberAcceptance(): Vor der ersten neuen Fassung gehoert die
     * Verlaufstabelle angelegt, sonst geht die alte Zustimmung verloren.
     */
    public const TERMS_VERSION = 1;

    /**
     * Muss diesem Konto die Guide-Frage gestellt werden?
     *
     * Gefragt wird von GuideController::requireCurrentTerms (sperrend, vor dem
     * Standortformular) sowie von SettingsController und ViewHelper fuer die
     * Anzeige - siehe TERMS_VERSION.
     *
     * Zwei Faelle:
     *   1. Die Rolle ist Trial. Trial heisst "hat sich noch nicht
     *      entschieden" - das ist der Zustand direkt nach der Registrierung.
     *      Wer "Nein" sagt, wird User und wird nie wieder gefragt; wer "Ja"
     *      sagt, wird Guide.
     *   2. Das Konto ist Guide, hat aber einer aelteren Fassung der
     *      Bedingungen zugestimmt (siehe TERMS_VERSION).
     *
     * Die Rolle User bedeutet ausdruecklich "hat sich entschieden, kein Guide
     * zu sein". Sie wird nicht erneut gefragt - die Entscheidung laesst sich
     * jederzeit in den Einstellungen aendern.
     *
     * @param int   $in_user_id
     * @param mixed $in_role Rolle des Kontos (ID oder Name)
     * @return bool
     */
    public static function needsDecision($in_user_id, $in_role)
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return false;

        if (Role::isUndecided($in_role)) return true;

        if (Role::isGuide($in_role)) {
            $accepted = self::acceptedTermsVersion($user_id);
            // Ein Guide ohne Profil hat nie zugestimmt (Konto von Hand in der
            // Benutzerverwaltung auf Guide gesetzt). Auch der wird gefragt.
            return $accepted < self::TERMS_VERSION;
        }

        return false;
    }

    /**
     * Nimmt die Guide-Rolle an.
     *
     * Erlaubt fuer Trial und User - also fuer genau die Rollen, die
     * Role::mayBecomeGuide() nennt. Ein Admin wird ueber diesen Weg NICHT zum
     * Guide: Er wuerde dabei seine Adminrechte verlieren, und ein Klick in den
     * Einstellungen darf kein Konto entmachten.
     *
     * HIER GEHOERT SPAETER DIE ABRECHNUNG HIN: Ab dem Zeitpunkt, an dem eine
     * Fuehrung Geld kostet, ist vor dem Rollenwechsel zu pruefen, ob
     * Auszahlungsdaten hinterlegt sind. Schlaegt die Pruefung fehl, gibt diese
     * Methode false zurueck - der Aufrufer behandelt das schon heute als
     * Fehlerfall.
     *
     * @param int   $in_user_id
     * @param mixed $in_role Aktuelle Rolle des Kontos
     * @return bool true, wenn die Rolle jetzt Guide ist
     */
    public static function accept($in_user_id, $in_role)
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return false;

        // Ein Guide, der einer neuen Fassung der Bedingungen zustimmt,
        // wechselt die Rolle nicht - nur sein Profil wird aufgefrischt.
        if (Role::isGuide($in_role)) {
            return self::rememberAcceptance($user_id);
        }

        if (!Role::mayBecomeGuide($in_role)) {
            error_log('GuideRole::accept: Rolle ' . var_export($in_role, true)
                . ' kann die Guide-Rolle nicht annehmen (Benutzer #' . $user_id . ')');
            return false;
        }

        if (!self::rememberAcceptance($user_id)) return false;

        return self::writeRole($user_id, Role::GUIDE);
    }

    /**
     * Gibt die Guide-Rolle zurueck; das Konto wird wieder Zuschauer.
     *
     * Nur fuer Guides. Ein Konto mit noch vorhandenen Standorten wird
     * abgewiesen: Ein Standort ohne Guide waere ein Angebot, das niemand
     * einloesen kann - er stuende weiter in der Uebersicht und liesse sich
     * anwaehlen. Geloescht wird an dieser Stelle nichts; das bleibt eine
     * ausdrueckliche Handlung des Eigentuemers in seiner Standortliste.
     *
     * HIER GEHOERT SPAETER DIE ABRECHNUNG HIN: offene Betraege abrechnen oder
     * den Widerruf verweigern, solange etwas offen ist.
     *
     * @param int   $in_user_id
     * @param mixed $in_role Aktuelle Rolle des Kontos
     * @return bool true, wenn die Rolle jetzt User ist
     */
    public static function resign($in_user_id, $in_role)
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return false;

        if (!Role::isGuide($in_role)) {
            error_log('GuideRole::resign: Benutzer #' . $user_id . ' ist gar kein Guide.');
            return false;
        }

        if (self::hasLocations($user_id)) return false;

        if (!self::writeRole($user_id, Role::USER)) return false;

        // Das Profil bleibt stehen und bekommt nur den Zeitpunkt. Die
        // Zustimmung von damals ist eine Tatsache und wird nicht geloescht.
        try {
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE guide_profile SET resigned_at = CURRENT_TIMESTAMP WHERE user_id = :user_id"
            );
            $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
        } catch (\PDOException $e) {
            // Die Rolle ist bereits abgegeben - daran aendert ein Fehler beim
            // Vermerken nichts mehr. Gemeldet wird er trotzdem, weil dem
            // Profil dann der Zeitpunkt fehlt.
            error_log('GuideRole::resign: resigned_at nicht gesetzt: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * Hat dieses Konto noch Standorte?
     *
     * @param int $in_user_id
     * @return bool
     */
    public static function hasLocations($in_user_id)
    {
        return (new Location())->countLocationsOfUser($in_user_id) > 0;
    }

    /**
     * Das Guide-Profil eines Kontos.
     *
     * @param int $in_user_id
     * @return array|null null, wenn das Konto der Rolle nie zugestimmt hat
     */
    public static function profile($in_user_id)
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return null;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT user_id, guide_since, terms_version, terms_accepted_at, resigned_at
                   FROM guide_profile WHERE user_id = :user_id"
            );
            $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $row === false ? null : $row;
        } catch (\PDOException $e) {
            error_log('GuideRole::profile: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fassung der Bedingungen, der dieses Konto zugestimmt hat.
     *
     * @param int $in_user_id
     * @return int 0, wenn es keine Zustimmung gibt
     */
    private static function acceptedTermsVersion($in_user_id)
    {
        $profile = self::profile($in_user_id);
        return $profile === null ? 0 : (int)$profile['terms_version'];
    }

    /**
     * Haelt die Zustimmung fest: Zeitpunkt, Fassung, Beginn.
     *
     * Legt das Profil an oder frischt es auf. `resigned_at` wird dabei
     * geleert - wer die Rolle erneut annimmt, ist wieder aktiver Guide.
     *
     * ES BLEIBT EINE ZEILE JE KONTO, und sie wird ueberschrieben. Stimmt ein
     * Guide einer neuen Fassung zu, sind die alte Fassungsnummer, ihr
     * Zeitpunkt und das urspruengliche `guide_since` danach weg. Fuer die
     * heutige Frage ("hat er der geltenden Fassung zugestimmt") reicht das.
     * Fuer eine Abrechnung reicht es NICHT: Dort muss nachvollziehbar sein,
     * unter welchen Bedingungen eine bestimmte Fuehrung stattfand. Wer die
     * Abrechnung baut, braucht davor eine Verlaufstabelle
     * (guide_terms_acceptance: user_id, terms_version, accepted_at) - und
     * zwar bevor die erste neue Fassung erscheint, nicht danach: Was hier
     * ueberschrieben wurde, laesst sich nicht wiederherstellen.
     *
     * @param int $in_user_id
     * @return bool
     */
    private static function rememberAcceptance($in_user_id)
    {
        $user_id = (int)$in_user_id;
        $version = self::TERMS_VERSION;

        try {
            // Ein Konto hat hoechstens ein Profil (Primaerschluessel auf
            // user_id). ON DUPLICATE KEY UPDATE spart die Fallunterscheidung
            // "gibt es schon" und ist gegen zwei gleichzeitige Anfragen sicher.
            $stmt = PdoConnect::$connection->prepare(
                "INSERT INTO guide_profile
                        (user_id, guide_since, terms_version, terms_accepted_at, resigned_at)
                 VALUES (:user_id, CURRENT_TIMESTAMP, :version, CURRENT_TIMESTAMP, NULL)
                 ON DUPLICATE KEY UPDATE
                        guide_since       = CURRENT_TIMESTAMP,
                        terms_version     = VALUES(terms_version),
                        terms_accepted_at = CURRENT_TIMESTAMP,
                        resigned_at       = NULL"
            );
            $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':version', $version, \PDO::PARAM_INT);
            $stmt->execute();
            return true;
        } catch (\PDOException $e) {
            error_log('GuideRole::rememberAcceptance: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Schreibt die neue Rolle in die Datenbank.
     *
     * Ueber App\Model\User und nicht mit eigenem SQL: Dort liegt die
     * Pruefung, ob die Rolle ueberhaupt bekannt ist (User::setUsertype).
     *
     * @param int   $in_user_id
     * @param int   $in_new_role
     * @return bool
     */
    private static function writeRole($in_user_id, $in_new_role)
    {
        try {
            $user = new User((int)$in_user_id);
        } catch (\Exception $e) {
            error_log('GuideRole::writeRole: ' . $e->getMessage());
            return false;
        }

        if (!$user->setRoleId($in_new_role)) {
            error_log('GuideRole::writeRole: Rolle ' . var_export($in_new_role, true) . ' abgewiesen.');
            return false;
        }
        return (bool)$user->save();
    }
}
