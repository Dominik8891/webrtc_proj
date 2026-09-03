<?php
namespace App\Controller;

use App\Model\Location;
use App\Helper\Auth;
use App\Helper\Permission;
use App\Helper\Request;
use App\Helper\ViewHelper;

/**
 * LocationController – Standorte anlegen, anzeigen, ändern, löschen, sperren.
 *
 * Der Zugang zu den Routen wird in index.php über die Rechte aus
 * config/routes.php entschieden. Was hier zusätzlich geprüft wird, ist das
 * EIGENTUM an einem Standort - eine Rechtetabelle kann nicht wissen, welcher
 * Datensatz wem gehört.
 *
 * Die Eigentumsprüfung findet zweimal statt, und das ist Absicht:
 *   1. Hier im Controller, damit der Aufrufer eine verständliche Antwort
 *      bekommt.
 *   2. In der WHERE-Klausel des Statements (App\Model\Location). Das ist die
 *      verbindliche Prüfung. Vorher standen dort nur `WHERE id = :id` -
 *      wer die Prüfung im Controller umging oder wer sie beim Ergänzen einer
 *      neuen Aufrufstelle vergaß, änderte fremde Standorte.
 */
class LocationController
{
    /**
     * Schluessel, unter dem die abgelehnten Formulareingaben bis zum
     * naechsten Aufruf des Formulars in der Sitzung liegen.
     */
    private const EINGABEN = 'set_location_eingaben';

    /**
     * Legt die Eingaben eines abgelehnten Formulars in der Sitzung ab.
     *
     * WARUM UEBERHAUPT
     * ----------------
     * setLocation() antwortet auf eine Ablehnung mit einer Weiterleitung
     * zurueck aufs Formular (Post/Redirect/Get - damit ein Neuladen den
     * Standort nicht ein zweites Mal anlegt). Der Preis dieses Musters ist,
     * dass der POST-Rumpf dabei verlorengeht: Der Nutzer stand vor einem
     * leeren Formular und musste die Beschreibung noch einmal tippen, obwohl
     * nur die Koordinaten gefehlt hatten. Land und Stadt traf es genauso -
     * beide Listen baut erst assets/js/map.js auf, eine Auswahl ueberlebte
     * den Ruecksprung nicht. Die Werte reisen deshalb ueber die Sitzung mit.
     *
     * Nicht ueber die URL: Eine Beschreibung gehoert nicht in die
     * Adresszeile, ins Server-Log und in den Verlauf.
     *
     * @param array<string,string> $werte Die Felder des Formulars
     * @return void
     */
    private static function merkeEingaben(array $werte): void
    {
        $_SESSION[self::EINGABEN] = $werte;
    }

    /**
     * Verwirft die gemerkten Eingaben.
     *
     * Noetig auf dem ERFOLGSWEG: Gemerkt wird vor den Pruefungen, damit
     * keine Ablehnung den Rueckweg vergessen kann. Ohne dieses Wegraeumen
     * laege der eben gespeicherte Standort noch in der Sitzung und stuende
     * beim naechsten, voellig unabhaengigen Aufruf des Formulars wieder
     * darin.
     *
     * @return void
     */
    private static function vergissEingaben(): void
    {
        unset($_SESSION[self::EINGABEN]);
    }

    /**
     * Holt die gemerkten Eingaben und loescht sie dabei.
     *
     * Das Loeschen gehoert zum Holen: Sonst haenge die alte Beschreibung
     * beim naechsten, voellig unabhaengigen Aufruf des Formulars wieder
     * darin - der Nutzer haette ein Feld vorbelegt, das er nie gefuellt hat.
     *
     * @return array<string,string> Leeres Array, wenn nichts gemerkt wurde
     */
    private static function holeEingaben(): array
    {
        $werte = $_SESSION[self::EINGABEN] ?? [];
        unset($_SESSION[self::EINGABEN]);

        return is_array($werte) ? $werte : [];
    }

    /**
     * Setzt die gemerkten Eingaben in die Vorlage ein.
     *
     * Oeffentlich und ohne Seiteneffekte, damit sich die Ersetzung samt
     * Maskierung pruefen laesst, ohne eine Seite auszuliefern
     * (tests/server_test.php).
     *
     * Jeder Wert geht durch htmlspecialchars(): Er landet in einem
     * value=""- bzw. data-""-Attribut, und dort beendet ein
     * Anfuehrungszeichen sonst das Attribut. Die Beschreibung ist freier
     * Text des Nutzers - genau der Fall, in dem das ausgenutzt wuerde.
     * ENT_QUOTES fasst auch das einfache Anfuehrungszeichen.
     *
     * @param string               $vorlage Inhalt von set_location.html
     * @param array<string,string> $werte   Rueckgabe von holeEingaben()
     * @return string Die Vorlage ohne Platzhalter
     */
    public static function fuelleFormular(string $vorlage, array $werte): string
    {
        $marken = [
            '###DESCRIPTION###' => 'description',
            '###LATITUDE###'    => 'latitude',
            '###LONGITUDE###'   => 'longitude',
            '###COUNTRY_ID###'  => 'country',
            '###CITY###'        => 'city',
        ];

        foreach ($marken as $marke => $feld) {
            $wert = $werte[$feld] ?? '';
            $wert = is_scalar($wert) ? (string)$wert : '';
            $vorlage = str_replace(
                $marke,
                htmlspecialchars($wert, ENT_QUOTES, 'UTF-8'),
                $vorlage
            );
        }

        return $vorlage;
    }

    /**
     * Zeigt das Formular zum Setzen einer Location an.
     *
     * Zugang: Recht location.create, geprüft in index.php. Zusätzlich die
     * Zustimmung zu den geltenden Guide-Bedingungen - das kann eine
     * Rechtetabelle nicht wissen, weil es nicht an der Rolle hängt, sondern
     * an der Fassung, der dieses Konto zugestimmt hat
     * (GuideController::requireCurrentTerms).
     *
     * @return void
     */
    public function setLocationPage()
    {
        GuideController::requireCurrentTerms();

        // Die Eingaben einer abgelehnten Eingabe zurueck ins Formular. Ist
        // nichts gemerkt - der Normalfall -, bleiben alle Platzhalter leer.
        $out = ViewHelper::template('assets/html/set_location.html');
        $out = self::fuelleFormular($out, self::holeEingaben());
        ViewHelper::output($out);
    }

    /**
     * Verarbeitet das Absenden des Location-Formulars: prüft die Eingaben und
     * legt den Standort an.
     *
     * Zugang: Recht location.create, geprüft in index.php - das haben nur
     * Guide und Admin. Die Rolle ändert sich hier NICHT mehr; darüber
     * entscheidet der Dialog in App\Controller\GuideController.
     *
     * Die Zustimmung wird auch hier geprüft und nicht nur beim Anzeigen des
     * Formulars: Ein POST erreicht diese Methode auch ohne den Umweg über die
     * Seite davor. Eine Prüfung, die sich umgehen lässt, indem man das
     * Formular überspringt, ist keine.
     *
     * @return void
     */
    public function setLocation()
    {
        GuideController::requireCurrentTerms();

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $country_id  = Request::g('country');
            $city        = Request::g('city');
            $longitude   = Request::g('longitude');
            $latitude    = Request::g('latitude');
            $description = Request::g('description');
            $user_id     = Auth::userId();

            // Die Eingaben fuer den Fall merken, dass gleich abgelehnt wird.
            // Steht VOR den Pruefungen, damit keine davon den Rueckweg
            // vergessen kann: Wer spaeter eine dritte Pruefung ergaenzt, muss
            // dafuer nichts wissen. Der Erfolgsweg raeumt sie am Ende wieder
            // weg (vergissEingaben).
            self::merkeEingaben([
                'country'     => $country_id,
                'city'        => $city,
                'latitude'    => $latitude,
                'longitude'   => $longitude,
                'description' => $description,
            ]);

            if (strlen($description) < 5) {
                header("Location: index.php?act=set_location_page&success=0");
                exit;
            }

            // Koordinaten pruefen. Sie duerfen NICHT als Leerstring in die
            // Spalten location.latitude/longitude laufen: das sind
            // decimal-Spalten, MySQL wandelt '' im Non-Strict-Mode zu
            // 0.00000000 - und 0/0 ist ein gueltiger Punkt im Atlantik.
            // Ein Standort ohne Koordinaten ist in dieser Anwendung zudem
            // unbrauchbar, weil die Kartenansicht sie zwingend braucht
            // (assets/js/locations_table.js:46-47). Deshalb Abbruch statt
            // NULL-Speicherung.
            // Der Bereich ist zusaetzlich begrenzt, weil latitude als
            // decimal(10,8) nur Werte bis +/-99,99999999 aufnehmen kann.
            if (!is_numeric($latitude)  || $latitude  < -90  || $latitude  > 90 ||
                !is_numeric($longitude) || $longitude < -180 || $longitude > 180) {
                header("Location: index.php?act=set_location_page&success=2");
                exit;
            }

            // Hier stand frueher der stille Aufstieg Zuschauer -> Guide: Wer
            // einen Standort anlegte, bekam die Guide-Rolle dazu, ohne je
            // gefragt worden zu sein. Guide zu sein heisst aber, sich vor Ort
            // von Fremden steuern zu lassen, und kuenftig haengt daran eine
            // Abrechnung - das darf keine Nebenwirkung eines Formulars sein.
            //
            // Die Rolle wird jetzt im Dialog entschieden
            // (App\Controller\GuideController, App\Model\GuideRole). Wer
            // hier ankommt, ist bereits Guide: Das Recht location.create haben
            // nur noch Guide und Admin, und index.php prueft es vor dem
            // Aufruf dieser Methode.
            $location = new Location();
            $location->setCountry($country_id);
            $location->setCity($city);
            $location->setLongitude($longitude);
            $location->setLatitude($latitude);
            $location->setDescription($description);
            $location->setNewLocation($user_id, $country_id);

            // Gespeichert - die oben gemerkten Eingaben sind erledigt. Sonst
            // stuenden sie beim naechsten Aufruf des Formulars wieder darin.
            self::vergissEingaben();

            // MIT act. Ohne den Parameter landete die Weiterleitung bei
            // index.php ohne Aktion - und index.php leitet dann auf
            // index.php?act=home weiter, wobei success=1 verlorengeht. Die
            // Erfolgsmeldung, die assets/js/main.js daran haengt, erschien
            // deshalb nie.
            header("Location: index.php?act=home&success=1");
            exit;
        }
    }

    /**
     * Gibt alle Länder als JSON zurück (API).
     * @return void
     */
    public function getCountry()
    {
        $location = new Location();
        $data = $location->selectAllCountries();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    /**
     * Gibt alle fremden Locations als JSON zurück (API).
     *
     * Gesperrte Standorte sind hier nicht dabei - genau das ist der Zweck
     * der Sperre. Wer sie moderieren darf (Recht location.block), sieht sie
     * weiterhin, sonst könnte er sie nicht wieder freigeben.
     *
     * @return void
     */
    public function getLocations()
    {
        $may_moderate = Auth::can(Permission::LOCATION_BLOCK);

        $location = new Location();
        $data = $location->selectAllLocations(Auth::userId(), $may_moderate);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    /**
     * Gibt die Standorte fuer die Karte der Startseite als JSON zurueck.
     *
     * Zugang: Recht location.map_public - das hat auch der Gast. Deshalb
     * enthaelt die Antwort keine Personendaten: kein Benutzername, keine
     * user_id, kein roher Anwesenheitsstatus, sondern Ort, Beschreibung und
     * einen von drei Verfuegbarkeitswerten
     * (App\Model\Location::selectPublicMapLocations).
     *
     * Der Zuschnitt der Daten steht im Modell und nicht hier: Eine zweite
     * Stelle, an der entschieden wird, was oeffentlich ist, waere eine
     * Stelle zu viel.
     *
     * @return void
     */
    public function getMapLocations()
    {
        $location = new Location();
        $data = $location->selectPublicMapLocations();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    /**
     * Gibt alle eigenen Locations des aktuellen Benutzers als JSON zurück.
     *
     * Die Antwort enthält auch gesperrte Standorte samt Grund - der Guide
     * soll sehen, dass und warum sein Standort nicht mehr in der Übersicht
     * auftaucht.
     *
     * @return void
     */
    public function getMyLocations()
    {
        $location = new Location();
        $data = $location->selectAllLocationsOfOneUser(Auth::userId());
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    /**
     * Zeigt die Seite mit der Locations-Tabelle an.
     * @return void
     */
    public function showLocationsPage()
    {
        $out = ViewHelper::template('assets/html/locations_table.html');
        ViewHelper::output($out);
    }

    /**
     * Bearbeitet die Beschreibung einer eigenen Location.
     * Gibt ein JSON-Objekt mit Erfolg oder Fehler zurück.
     * @return void
     */
    public function editLocationDesc()
    {
        $location_id = (int)Request::g('id');
        $new_desc    = Request::g('description');
        $user_id     = Auth::userId();

        if (!$location_id || $new_desc === '') {
             // Fehler: Parameter fehlen
            self::json(['success' => false, 'error' => 'Fehlende Daten']);
        }

        try {
            $location = new Location($location_id);
        } catch (\Exception $e) {
            error_log('editLocationDesc: ' . $e->getMessage());
            self::json(['success' => false, 'error' => 'Standort nicht gefunden.']);
        }

        // Fremde Standorte gibt es hier nicht. Die Antwort unterscheidet
        // nicht zwischen "gibt es nicht" und "gehört jemand anderem", damit
        // sich über diese Route keine fremden Standort-IDs abklopfen lassen.
        if (!$location->belongsToUser($user_id)) {
            error_log("editLocationDesc: Standort #$location_id gehoert nicht zu Benutzer #$user_id");
            self::json(['success' => false, 'error' => 'Standort nicht gefunden.']);
        }

        $location->setDescription($new_desc);
        if ($location->updateLocation($user_id)) {
            self::json(['success' => true]);
        }
        self::json(['success' => false, 'error' => 'Update fehlgeschlagen']);
    }

    /**
     * Löscht eine eigene Location anhand der ID.
     *
     * Vorher hatte diese Methode keinerlei Prüfung - weder auf eine
     * Anmeldung noch auf das Eigentum. Eine beliebige ID im POST löschte
     * einen beliebigen fremden Standort.
     *
     * @return void
     */
    public function deleteLocation()
    {
        $location_id = (int)Request::g('id');
        $user_id     = Auth::userId();

        if (!$location_id) {
            self::json(['success' => false, 'error' => 'Keine Location-ID übergeben!']);
        }

        try {
            $location = new Location();
            if ($location->deleteLocation($location_id, $user_id)) {
                self::json(['success' => true]);
            }
            // Kein Treffer heißt: gibt es nicht oder gehört jemand anderem.
            // Beides ergibt dieselbe Antwort.
            error_log("deleteLocation: kein eigener Standort #$location_id fuer Benutzer #$user_id");
            self::json(['success' => false, 'error' => 'Standort nicht gefunden.']);
        } catch (\Exception $e) {
            error_log("Fehler beim Löschen der Location #$location_id: " . $e->getMessage());
            self::json(['success' => false, 'error' => 'Fehler beim Löschen.']);
        }
    }

    /**
     * Sperrt einen fremden Standort (Moderation).
     *
     * Zugang: Recht location.block. Gesperrte Standorte verschwinden aus der
     * Übersicht der anderen Nutzer; der Guide behält seinen Datensatz und
     * sieht in seiner eigenen Standortliste den hinterlegten Grund.
     * Gelöscht wird nichts - das bleibt dem Eigentümer vorbehalten.
     *
     * @return void
     */
    public function blockLocation()
    {
        $location_id = (int)Request::g('id');
        $reason      = trim(Request::g('reason'));

        if (!$location_id) {
            self::json(['success' => false, 'error' => 'Keine Location-ID übergeben!']);
        }
        if ($reason === '') {
            // Der Guide bekommt den Grund angezeigt - ohne Grund ist die
            // Sperre für ihn nicht nachvollziehbar.
            self::json(['success' => false, 'error' => 'Bitte einen Grund angeben.']);
        }
        if (mb_strlen($reason) > 255) {
            $reason = mb_substr($reason, 0, 255);
        }

        $location = new Location();
        if ($location->block($location_id, Auth::userId(), $reason)) {
            self::json(['success' => true]);
        }
        self::json(['success' => false, 'error' => 'Standort nicht gefunden.']);
    }

    /**
     * Hebt die Sperre eines Standorts wieder auf.
     * Zugang: Recht location.block.
     * @return void
     */
    public function unblockLocation()
    {
        $location_id = (int)Request::g('id');

        if (!$location_id) {
            self::json(['success' => false, 'error' => 'Keine Location-ID übergeben!']);
        }

        $location = new Location();
        if ($location->unblock($location_id)) {
            self::json(['success' => true]);
        }
        self::json(['success' => false, 'error' => 'Standort nicht gefunden.']);
    }

    /**
     * Gibt eine JSON-Antwort aus und beendet die Verarbeitung.
     *
     * @param array $payload
     * @return never
     */
    private static function json(array $payload)
    {
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}
