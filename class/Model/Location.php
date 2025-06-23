<?php

namespace App\Model;

/**
 * Klasse zur Verwaltung von Locations (Orte) in der Datenbank.
 */
class Location
{
    private $id;
    private $country;
    private $city;
    private $latitude;
    private $longitude;
    private $description;

    /**
     * Lädt eine Location aus der Datenbank (falls ID > 0), sonst leeres Objekt.
     * @param int $in_id
     * @throws \Exception wenn keine Location mit der ID gefunden wird
     */
    public function __construct($in_id = 0)
    {
        if ($in_id > 0) {
            try {
                $query = "SELECT location.*, country.country_name, city.city_name 
                          FROM location
                          JOIN city    ON location.city_id = city.id 
                          JOIN country ON city.country_id = country.id
                          WHERE location.id = :id";
                $stmt  = PdoConnect::$connection->prepare($query);
                $stmt->bindParam(':id', $in_id, \PDO::PARAM_INT);
                $stmt->execute();
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($result) {
                    $this->id          = $result['id'];
                    $this->country     = $result['country_name'];
                    $this->city        = $result['city_name'];
                    $this->latitude    = $result['latitude'];
                    $this->longitude   = $result['longitude'];
                    $this->description = $result['description'];
                } else {
                    throw new \Exception("Location mit ID {$in_id} nicht gefunden.");
                }
            } catch (\PDOException $e) {
                error_log('Fehler beim Laden der Location: ' . $e->getMessage());
                throw new \Exception("Fehler beim Zugriff auf die Datenbank.");
            }
        } else {
            $this->id = 0;
        }
    }

    /**
     * Erstellt eine neue Location für einen User in einem Land (und ggf. Stadt).
     * @param int $user_id
     * @param int $country_id
     * @return int|false ID der neuen Location oder false bei Fehler
     * @throws \Exception bei fehlender Stadt
     */
    public function setNewLocation($user_id, $country_id)
    {
        if ($user_id > 0) {
            try {
                $result = $this->selectCity();
                if ($result === false) {
                    $city_id = $this->insertCityName($country_id);
                } else {
                    $city_id = $result['id'];
                }
                if (empty($city_id)) {
                    throw new \Exception("City konnte nicht bestimmt werden!");
                }
                return $this->insertLocation($user_id, $city_id);
            } catch (\Exception $e) {
                error_log('Fehler beim Anlegen einer Location: ' . $e->getMessage());
                return false;
            }
        }
        return false;
    }

    /**
     * Fügt Location in die Datenbank ein.
     * @param int $user_id
     * @param int $city_id
     * @return int|false Neue Location-ID oder false bei Fehler
     */
    public function insertLocation($user_id, $city_id)
    {
        try {
            $query = "INSERT INTO location ( user_id,  city_id,  longitude,  latitude,  description)
                                    VALUES (:user_id, :city_id, :longitude, :latitude, :description)";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':city_id', $city_id, \PDO::PARAM_INT);
            $stmt->bindParam(':longitude', $this->longitude);
            $stmt->bindParam(':latitude', $this->latitude);
            $stmt->bindParam(':description', $this->description);
            $stmt->execute();
            return PdoConnect::$connection->lastInsertId();
        } catch (\PDOException $e) {
            error_log('Fehler beim Einfügen einer Location: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Aktualisiert eine Location in der Datenbank.
     * @return bool
     */
    public function updateLocation()
    {
        try {
            $query = "UPDATE location SET
                        longitude   = :longitude,
                        latitude    = :latitude,
                        description = :description
                           WHERE id = :id";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt ->bindParam(':longitude'     , $this->longitude     );
            $stmt ->bindParam(':latitude'      , $this->latitude      );
            $stmt ->bindParam(':description'   , $this->description   );
            $stmt ->bindParam(':id'            , $this->id            );
            $stmt->execute();
            error_log('Lokation erfolgreich aktualisiert!');
            return true;
        } catch (\PDOException $e) {
            error_log('Fehler beim Aktualisieren der Lokation: ' . $e->getMessage());
            return false;
        } 
    }

    /**
     * Löscht die Location aus der Datenbank.
     * @return bool
     */
    public function deleteLocation()
    {
        try {
            $query = "DELETE FROM location WHERE id = :id";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':id', $this->id, \PDO::PARAM_INT);
            $stmt->execute();
            error_log('Lokation erfolgreich gelöscht!');
            return true;
        } catch (\PDOException $e) {
            error_log('Fehler beim Löschen der Lokation: ' . $e->getMessage());
            return false;
        } 
    }

    /**
     * Gibt alle Länder als Array zurück.
     * @return array
     */
    public function selectAllCountries()
    {
        try {
            $stmt = PdoConnect::$connection->prepare("SELECT * FROM country");
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden der Länder: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Gibt alle gespeicherten Locations als Array zurück.
     * @param int $in_user_id
     * @return array
     */
    public function selectAllLocations($in_user_id)
    {
        try {
            $query = "SELECT user.id AS user_id, user.username, user.user_status, 
                             country.country_name, city.city_name, 
                             location.latitude, location.longitude, location.description
                      FROM location
                      LEFT JOIN user    ON location.user_id = user.id
                      LEFT JOIN city    ON location.city_id = city.id
                      LEFT JOIN country ON city.country_id = country.id
                      WHERE user.id != :user_id";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt ->bindParam(":user_id", $in_user_id);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden aller Locations: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Gibt alle gespeicherten Locations eines Users als Array zurück.
     * @param int $in_user_id
     * @return array
     */
    public function selectAllLocationsOfOneUser($in_user_id)
    {
        try {
            $query = "SELECT user.id AS user_id, user.username, user.user_status, 
                             country.country_name, city.city_name, location.id,
                             location.latitude, location.longitude, location.description
                      FROM location
                      LEFT JOIN user    ON location.user_id = user.id
                      LEFT JOIN city    ON location.city_id = city.id
                      LEFT JOIN country ON city.country_id = country.id
                      WHERE user.id = :user_id";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt ->bindParam(":user_id", $in_user_id);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden der User-Locations: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Gibt die Stadt zurück, falls sie existiert.
     * @return array|false
     */
    public function selectCity()
    {
        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT * FROM city WHERE city_name = :city"
            );
            $stmt->bindParam(':city', $this->city);
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $result ?: false;
        } catch (\PDOException $e) {
            error_log('Fehler beim Suchen der Stadt: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Legt eine neue Stadt an (falls noch nicht vorhanden).
     * @param int $country_id
     * @return int|false Neue Stadt-ID oder false bei Fehler
     */
    public function insertCityName($country_id)
    {
        try {
            $query = "INSERT INTO city ( city_name,  country_id) 
                                VALUES (:city_name, :country_id)";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':city_name', $this->city);
            $stmt->bindParam(':country_id', $country_id, \PDO::PARAM_INT);
            $stmt->execute();
            return PdoConnect::$connection->lastInsertId();
        } catch (\PDOException $e) {
            error_log('Fehler beim Anlegen einer Stadt: ' . $e->getMessage());
            return false;
        }
    }

    // Setter
    public function setCountry($in_country)     { $this->country = $in_country; }
    public function setCity($in_city)           { $this->city = $in_city; }
    public function setLongitude($in_longitude) { $this->longitude = $in_longitude; }
    public function setLatitude($in_latitude)   { $this->latitude = $in_latitude; }
    public function setDescription($in_desc)    { $this->description = $in_desc; }

    // Getter 
    public function getId()          { return $this->id; }
    public function getCountry()     { return $this->country; }
    public function getCity()        { return $this->city; }
    public function getLatitude()    { return $this->latitude; }
    public function getLongitude()   { return $this->longitude; }
    public function getDescription() { return $this->description; }
}
