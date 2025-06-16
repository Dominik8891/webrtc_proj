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
     */
    public function __construct($in_id = 0)
    {
        if ($in_id > 0) {
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
        } else {
            $this->id = 0;
        }
    }

    /**
     * Erstellt eine neue Location für einen User in einem Land (und ggf. Stadt).
     */
    public function setNewLocation($user_id, $country_id)
    {
        if ($user_id > 0) {
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
        }
        return false;
    }

    /**
     * Fügt Location in die Datenbank ein.
     */
    public function insertLocation($user_id, $city_id)
    {
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
    }

    /**
     * Gibt alle Länder als Array zurück.
     */
    public function selectAllCountries()
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM country"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Gibt alle gespeicherten Locations als Array zurück.
     */
    public function selectAllLocations($in_user_id)
    {
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
    }

    /**
     * Gibt die Stadt zurück, falls sie existiert.
     */
    public function selectCity()
    {
        $stmt = PdoConnect::$connection->prepare(
            "SELECT * FROM city WHERE city_name = :city"
        );
        $stmt->bindParam(':city', $this->city);
        $stmt->execute();
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: false;
    }

    /**
     * Legt eine neue Stadt an (falls noch nicht vorhanden).
     */
    public function insertCityName($country_id)
    {
        $query = "INSERT INTO city ( city_name,  country_id) 
                            VALUES (:city_name, :country_id)";
        $stmt = PdoConnect::$connection->prepare($query);
        $stmt->bindParam(':city_name', $this->city);
        $stmt->bindParam(':country_id', $country_id, \PDO::PARAM_INT);
        $stmt->execute();
        return PdoConnect::$connection->lastInsertId();
    }

    // Setter
    public function setCountry($in_country)     { $this->country = $in_country; }
    public function setCity($in_city)           { $this->city = $in_city; }
    public function setLongitude($in_longitude) { $this->longitude = $in_longitude; }
    public function setLatitude($in_latitude)   { $this->latitude = $in_latitude; }
    public function setDescription($in_desc)    { $this->description = $in_desc; }

    // Optional: Getter falls gewünscht
    public function getId()          { return $this->id; }
    public function getCountry()     { return $this->country; }
    public function getCity()        { return $this->city; }
    public function getLatitude()    { return $this->latitude; }
    public function getLongitude()   { return $this->longitude; }
    public function getDescription() { return $this->description; }
}
