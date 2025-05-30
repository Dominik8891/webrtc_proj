<?php

class Location
{
    // Private Attribute für Benutzerinformationen
    private $id;
    private $country;
    private $city;
    private $latitude;
    private $longitude;
    private $description;

    public function __construct(string|int $in_id = 0)
    {
        if($in_id > 0)
        {
            $query = "SELECT `location`.*, country.country_name, city.city_name 
                      FROM `location`
                      JOIN city ON `location`.city_id = city.id 
                      JOIN country ON city.country_id = country.id
                      WHERE `location`.id = :id";
            $stmt  = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':id', $in_id, PDO::PARAM_INT);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if($result)
            {
                $this->id           = $result['id'];
                $this->country      = $result['country_name'];
                $this->city         = $result['city_name'];
                $this->latitude     = $result['latitude'];
                $this->longitude    = $result['longitude'];
                $this->description  = $result['description'];
            }
            else
            {
                throw new Exception("Location mit ID {$in_id} nicht gefunden.");
            }
        }
        else
        {
            $this->id = 0;
        }
    }

    public function set_new_location($user_id, $country_id) {
        if ($user_id > 0) {
            $result = $this->select_city();
            if ($result === false) {
                $city_id = $this->insert_city_name($country_id);
            } else {
                $city_id = $result['id'];
            }
            if (empty($city_id)) {
                throw new Exception("City konnte nicht bestimmt werden!");
            }
            return $this->insert_location($user_id, $city_id);
        }
        return false;
    }


    public function insert_location($user_id, $city_id) {
        $query = "INSERT INTO `location` (user_id, city_id, longitude, latitude, description)
                  VALUES (:user_id, :city_id, :longitude, :latitude, :description)";
        $stmt = PdoConnect::$connection->prepare($query);
        $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':city_id', $city_id, PDO::PARAM_INT);
        $stmt->bindParam(':longitude', $this->longitude);
        $stmt->bindParam(':latitude', $this->latitude);
        $stmt->bindParam(':description', $this->description);
        $stmt->execute();
        return PdoConnect::$connection->lastInsertId();
    }

    public function select_country() {
        $query = "SELECT * FROM country WHERE country_name = :country";
        $stmt = PdoConnect::$connection->prepare($query);
        $stmt->bindParam(':country', $this->country);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result : false;
    }

    public function select_all_countries() {
        try {
            $query = "SELECT * FROM country";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->execute();
            $data = [];
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach($result as $row) {
                $data[] = $row;
            }
            return $data;
        } catch (Exception $e) {
            // Fehlerbehandlung nach Bedarf
            return [];
        }
    }

    public function select_city() {
        $query = "SELECT * FROM city WHERE city_name = :city";
        $stmt = PdoConnect::$connection->prepare($query);
        $stmt->bindParam(':city', $this->city);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result : false;
    }

    public function insert_city_name($country_id) {
        $query = "INSERT INTO city (city_name, country_id)
                  VALUES (:city_name, :country_id)";
        $stmt = PdoConnect::$connection->prepare($query);
        $stmt->bindParam(':city_name', $this->city);
        $stmt->bindParam(':country_id', $country_id, PDO::PARAM_INT);
        $stmt->execute();
        return PdoConnect::$connection->lastInsertId();
    }

    // Setter
    public function set_country($in_country) {
        $this->country = $in_country;
    }
    public function set_city($in_city) {
        $this->city = $in_city;
    }
    public function set_longitude($in_longitude) {
        $this->longitude = $in_longitude;
    }
    public function set_latitude($in_latitude) {
        $this->latitude = $in_latitude;
    }
    public function set_description($in_description) {
        $this->description = $in_description;
    }
}
?>
