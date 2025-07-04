<?php
namespace App\Controller;

use App\Model\User;
use App\Model\Location;
use App\Helper\Request;
use App\Helper\ViewHelper;

class LocationController
{
    /**
     * Zeigt das Formular zum Setzen einer Location an.
     */
    public function setLocationPage()
    {
        if (!empty($_SESSION['user']['user_id'])) {
            $out = file_get_contents('assets/html/set_location.html');
            ViewHelper::output($out);
        } else {
            // Home als Rückfall (achte auf deinen Controller/Utility!)
            (new SystemController())->home();
        }
    }

    /**
     * Verarbeitet das Absenden des Location-Formulars.
     */
    public function setLocation()
    {
        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $country_id  = Request::g('country');
            $city        = Request::g('city');
            $longitude   = Request::g('longitude');
            $latitude    = Request::g('latitude');
            $description = Request::g('description');
            $user_id     = $_SESSION['user']['user_id'];

            if (strlen($description) < 5) {
                header("Location: index.php?act=set_location_page&success=0");
                exit;
            }

            $user = new User($user_id);
            if ($user->getUsertype() === 'tourist') {
                $user->setUsertype('guide');
                $user->save();
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
     * Gibt alle Locations des aktuellen Benutzers als JSON zurück (API).
     */
    public function getLocations()
    {
        $location = new Location();
        $data = $location->selectAllLocations($_SESSION['user']['user_id']);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    /**
     * Zeigt die Seite mit der Locations-Tabelle an.
     */
    public function showLocationsPage()
    {
        if (!empty($_SESSION['user']['user_id'])) {
            $out = file_get_contents('assets/html/locations_table.html');
            ViewHelper::output($out);
        } else {
            (new SystemController())->home();
        }
    }
}
