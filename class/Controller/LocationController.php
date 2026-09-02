<?php
namespace App\Controller;

use App\Model\User;
use App\Model\Location;
use App\Helper\Auth;
use App\Helper\Permission;
use App\Helper\Request;
use App\Helper\Role;
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
     * Zeigt das Formular zum Setzen einer Location an.
     * Zugang: Recht location.create, geprüft in index.php.
     * @return void
     */
    public function setLocationPage()
    {
        $out = file_get_contents('assets/html/set_location.html');
        ViewHelper::output($out);
    }

    /**
     * Verarbeitet das Absenden des Location-Formulars.
     * Setzt die Location, prüft die Eingaben und ändert ggf. den User-Typ.
     * @return void
     */
    public function setLocation()
    {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $country_id  = Request::g('country');
            $city        = Request::g('city');
            $longitude   = Request::g('longitude');
            $latitude    = Request::g('latitude');
            $description = Request::g('description');
            $user_id     = Auth::userId();

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

            // Aufstieg Zuschauer -> Guide. Das ist kein Recht, sondern ein
            // Rollenwechsel: Wer einen Standort anbietet, ist ein Guide.
            $user = new User($user_id);
            if (Role::mayBecomeGuide($user->getRoleId())) {
                $user->setRoleId(Role::GUIDE);
                $user->save();

                // Die Session traegt die Rolle mit; ohne Auffrischung wuerde
                // der Nutzer bis zum naechsten Login weiter als Zuschauer
                // gefuehrt.
                Auth::refreshRole(Role::GUIDE);
            }

            $location = new Location();
            $location->setCountry($country_id);
            $location->setCity($city);
            $location->setLongitude($longitude);
            $location->setLatitude($latitude);
            $location->setDescription($description);
            $location->setNewLocation($user_id, $country_id);

            header("Location: index.php?success=1");
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
        $out = file_get_contents('assets/html/locations_table.html');
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
