<?php

namespace App\Model;

/**
 * Klasse zur Verwaltung von Locations (Orte) in der Datenbank.
 *
 * EIGENTUM GEHOERT IN DIE WHERE-KLAUSEL
 * -------------------------------------
 * Jedes Statement, das einen vorhandenen Standort aendert oder loescht,
 * traegt die Bedingung `user_id = :user_id`. Vorher stand dort nur
 * `WHERE id = :id`; ob der Standort dem Aufrufer gehoert, entschied allein
 * der Controller. Das ist eine Prueflogik, die man beim Ergaenzen einer
 * zweiten Aufrufstelle vergessen kann - und im Fall von
 * LocationController::deleteLocation() war sie ueberhaupt nicht vorhanden.
 * Steht die Bedingung im Statement, trifft ein fremder Standort schlicht
 * keine Zeile.
 *
 * SPERRE STATT LOESCHUNG
 * ----------------------
 * Ein Standort kann von der Moderation (Recht location.block) gesperrt
 * werden. Er verschwindet damit aus der Uebersicht der anderen Nutzer,
 * bleibt aber beim Guide bestehen, der den Grund in seiner eigenen Liste
 * sieht. Loeschen darf weiterhin nur der Eigentuemer.
 */
class Location
{
    /**
     * Die Verfuegbarkeit eines Standorts als SQL-Ausdruck.
     *
     * DIE EINZIGE STELLE, an der steht, was "ein Guide ist jetzt da" heisst.
     * Jede Abfrage dieser Klasse setzt genau diesen Ausdruck ein; keine
     * Lesestelle bekommt user_status mehr roh in die Hand.
     *
     * ANGEMELDET UND BEREIT SIND ZWEI DINGE, und der Ausdruck fragt beide ab:
     *
     *   user.available_until  Der Guide hat sich ausdruecklich auf bereit
     *                         gestellt und die Frist laeuft noch. Ohne das ist
     *                         der Standort ein Angebot ohne Guide - auch wenn
     *                         das Konto angemeldet ist.
     *   user.user_status      Es ist tatsaechlich ein Browser erreichbar. Ohne
     *                         das haette ein abgestuerzter Client seine
     *                         Bereitschaft noch stundenlang stehen, obwohl ihn
     *                         niemand mehr erreicht.
     *
     * Die Reihenfolge ist Absicht: Die Bereitschaft wird ZUERST geprueft.
     * Damit ist "grau" die Vorgabe und "gruen" der Sonderfall, der beide
     * Bedingungen erfuellt.
     *
     * Drei Ergebniswerte, dieselben wie bisher - assets/js/home_map.js und
     * assets/js/locations_table.js kennen genau diese:
     *
     *   'live'  Guide ist da und anrufbar
     *   'busy'  Guide ist da, aber gerade im Gespraech
     *   'idle'  kein Guide vor Ort
     *
     * Der Ausdruck rechnet gegen NOW() der Datenbank. Damit entscheidet
     * dieselbe Uhr ueber den Ablauf, gegen die auch
     * App\Model\User::availableSeconds() die Restzeit rechnet.
     */
    public const AVAILABILITY_SQL = "CASE
                                 WHEN user.available_until IS NULL
                                   OR user.available_until <= NOW()  THEN 'idle'
                                 WHEN user.user_status = 'online'    THEN 'live'
                                 WHEN user.user_status = 'in_call'   THEN 'busy'
                                 ELSE 'idle'
                             END";

    private $id;
    private $user_id;
    private $country;
    private $city;
    private $latitude;
    private $longitude;
    private $description;
    private $blocked;
    private $blocked_reason;

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
                    $this->id             = $result['id'];
                    // Eigentuemer mitladen: Ohne ihn liesse sich hier gar
                    // nicht pruefen, wem der Standort gehoert.
                    $this->user_id        = $result['user_id'] ?? null;
                    $this->country        = $result['country_name'];
                    $this->city           = $result['city_name'];
                    $this->latitude       = $result['latitude'];
                    $this->longitude      = $result['longitude'];
                    $this->description    = $result['description'];
                    $this->blocked        = (int)($result['blocked'] ?? 0);
                    $this->blocked_reason = $result['blocked_reason'] ?? null;
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
     * Gehoert dieser Standort dem angegebenen Benutzer?
     *
     * @param int $in_user_id
     * @return bool false auch dann, wenn der Standort gar nicht geladen ist
     */
    public function belongsToUser($in_user_id)
    {
        $owner = (int)$this->user_id;
        $user  = (int)$in_user_id;
        return $owner > 0 && $user > 0 && $owner === $user;
    }

    /**
     * Aktualisiert eine Location in der Datenbank.
     *
     * Der Eigentuemer ist Pflichtparameter und steht in der WHERE-Klausel.
     * Ein fremder Standort trifft damit keine Zeile, auch wenn die Pruefung
     * im Controller einmal ausbleibt.
     *
     * @param int $in_user_id Eigentuemer, in dessen Namen geaendert wird
     * @return bool
     */
    public function updateLocation($in_user_id)
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1 || (int)$this->id < 1) {
            error_log('updateLocation: ohne Standort-ID oder ohne Benutzer aufgerufen.');
            return false;
        }

        try {
            $query = "UPDATE location SET
                        longitude   = :longitude,
                        latitude    = :latitude,
                        description = :description
                           WHERE id = :id
                             AND user_id = :user_id";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt ->bindParam(':longitude'     , $this->longitude     );
            $stmt ->bindParam(':latitude'      , $this->latitude      );
            $stmt ->bindParam(':description'   , $this->description   );
            $stmt ->bindParam(':id'            , $this->id            );
            $stmt ->bindParam(':user_id'       , $user_id             );
            $stmt->execute();
            return true;
        } catch (\PDOException $e) {
            error_log('Fehler beim Aktualisieren der Lokation: ' . $e->getMessage());
            return false;
        } 
    }

    /**
     * Loescht eine eigene Location aus der Datenbank.
     *
     * Beide Angaben sind Pflicht und stehen in der WHERE-Klausel. Der
     * Rueckgabewert sagt, ob wirklich eine Zeile getroffen wurde - vorher
     * meldete die Methode auch dann Erfolg, wenn gar nichts geloescht wurde,
     * und der Aufrufer bekam ein "erledigt" fuer einen fremden Standort.
     *
     * @param int $in_id      Standort
     * @param int $in_user_id Eigentuemer
     * @return bool true, wenn genau dieser Standort dieses Benutzers geloescht wurde
     */
    public function deleteLocation($in_id, $in_user_id)
    {
        $id      = (int)$in_id;
        $user_id = (int)$in_user_id;
        if ($id < 1 || $user_id < 1) {
            error_log('deleteLocation: ohne Standort-ID oder ohne Benutzer aufgerufen.');
            return false;
        }

        try {
            $query = "DELETE FROM location WHERE id = :id AND user_id = :user_id";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':id'      , $id     , \PDO::PARAM_INT);
            $stmt->bindParam(':user_id' , $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('Fehler beim Löschen der Lokation: ' . $e->getMessage());
            return false;
        } 
    }

    /**
     * Sperrt einen Standort (Moderation).
     *
     * Kein user_id in der Bedingung: Gesperrt werden gerade FREMDE Standorte.
     * Wer das darf, entscheidet das Recht location.block in index.php.
     *
     * @param int    $in_id       Standort
     * @param int    $in_admin_id Wer gesperrt hat (fuer die Nachvollziehbarkeit)
     * @param string $in_reason   Grund, den der Guide zu sehen bekommt
     * @return bool true, wenn ein Standort getroffen wurde
     */
    public function block($in_id, $in_admin_id, $in_reason)
    {
        $id       = (int)$in_id;
        $admin_id = (int)$in_admin_id;
        if ($id < 1 || $admin_id < 1) return false;

        try {
            $query = "UPDATE location SET
                        blocked        = 1,
                        blocked_reason = :reason,
                        blocked_by     = :admin_id,
                        blocked_at     = CURRENT_TIMESTAMP
                      WHERE id = :id";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':reason'  , $in_reason);
            $stmt->bindParam(':admin_id', $admin_id , \PDO::PARAM_INT);
            $stmt->bindParam(':id'      , $id       , \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('Fehler beim Sperren der Lokation: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Hebt die Sperre eines Standorts auf.
     *
     * @param int $in_id
     * @return bool true, wenn ein gesperrter Standort getroffen wurde
     */
    public function unblock($in_id)
    {
        $id = (int)$in_id;
        if ($id < 1) return false;

        try {
            $query = "UPDATE location SET
                        blocked        = 0,
                        blocked_reason = NULL,
                        blocked_by     = NULL,
                        blocked_at     = NULL
                      WHERE id = :id";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('Fehler beim Freigeben der Lokation: ' . $e->getMessage());
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
     * Gibt alle fremden Locations als Array zurück.
     *
     * Gesperrte Standorte bleiben aussen vor - das ist der Sinn der Sperre.
     * Nur wer moderieren darf, bekommt sie mitgeliefert, sonst koennte er
     * sie nicht wieder freigeben.
     *
     * @param int  $in_user_id      Eigene ID (die eigenen Standorte fehlen in dieser Liste)
     * @param bool $in_with_blocked Gesperrte Standorte mitliefern (Recht location.block)
     * @return array
     */
    public function selectAllLocations($in_user_id, $in_with_blocked = false)
    {
        // Die Bedingung wird nicht aus einem Parameter zusammengesetzt,
        // sondern aus einem festen Textbaustein: In die Abfrage kommt nichts,
        // was ein Aufrufer beeinflussen koennte.
        $blocked_filter = $in_with_blocked ? '' : ' AND location.blocked = 0';

        try {
            // KEIN user_status mehr. Die Liste bekommt die fertig
            // ausgewertete Verfuegbarkeit - dieselbe, die auch die Karte
            // bekommt. Wer den rohen Status herausgibt, laedt dazu ein, ihn
            // an der naechsten Lesestelle wieder mit "verfuegbar"
            // gleichzusetzen; genau das war der alte Fehler.
            $query = "SELECT user.id AS user_id, user.username,
                             " . self::AVAILABILITY_SQL . " AS availability,
                             country.country_name, city.city_name, location.id,
                             location.latitude, location.longitude, location.description,
                             location.blocked, location.blocked_reason
                      FROM location
                      LEFT JOIN user    ON location.user_id = user.id
                      LEFT JOIN city    ON location.city_id = city.id
                      LEFT JOIN country ON city.country_id = country.id
                      WHERE user.id != :user_id" . $blocked_filter;
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
     * Die Standorte fuer die oeffentliche Karte der Startseite.
     *
     * WARUM EINE EIGENE ABFRAGE
     * -------------------------
     * Diese Liste bekommt auch, wer nicht angemeldet ist. Sie enthaelt
     * deshalb NUR, was auf der Karte zu sehen sein muss:
     *
     *   id            - damit der Browser eine Nadel wiederfindet
     *   country_name  - Ort
     *   city_name     - Ort
     *   latitude,
     *   longitude     - der angebotene Standort, nicht die Position eines
     *                   Menschen: Er wurde vom Guide selbst als Treffpunkt
     *                   eingetragen (LocationController::setLocation)
     *   description   - die Beschreibung des Angebots
     *   availability  - 'live', 'busy' oder 'idle'
     *
     * Kein Benutzername, keine user_id, kein roher user_status, keine
     * Sperrangaben. Die Verfuegbarkeit wird schon hier in einen der drei
     * Werte uebersetzt, damit ueber diese Route keine Anwesenheitsdaten
     * einzelner Konten abfliessen koennen: Wer die Antwort mitschneidet,
     * sieht "an diesem Ort ist jemand erreichbar" und nicht "Benutzer X ist
     * online".
     *
     * Ohne user_id laesst sich ueber diese Antwort auch niemand anrufen -
     * genau so ist es gemeint. Ein Gast, der auf eine verfuegbare Nadel
     * klickt, wird zur Anmeldung geschickt (assets/js/home_map.js).
     *
     * Gesperrte Standorte sind nie dabei. Sie sind fuer die Moderation
     * gesperrt worden, und die arbeitet angemeldet in der Uebersicht.
     *
     * @return array
     */
    public function selectPublicMapLocations()
    {
        try {
            $query = "SELECT location.id,
                             country.country_name,
                             city.city_name,
                             location.latitude,
                             location.longitude,
                             location.description,
                             " . self::AVAILABILITY_SQL . " AS availability
                      FROM location
                      JOIN user    ON location.user_id = user.id
                      LEFT JOIN city    ON location.city_id = city.id
                      LEFT JOIN country ON city.country_id = country.id
                      WHERE location.blocked = 0";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden der oeffentlichen Karte: ' . $e->getMessage());
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
            // Auch die eigene Liste bekommt die ausgewertete Verfuegbarkeit
            // und nicht den rohen Status. Fuer den Guide ist gerade das die
            // nuetzliche Auskunft: Steht hier 'idle', obwohl er angemeldet
            // ist, sieht ihn auf der Karte niemand - er ist nicht auf bereit.
            $query = "SELECT user.id AS user_id, user.username,
                             " . self::AVAILABILITY_SQL . " AS availability,
                             country.country_name, city.city_name, location.id,
                             location.latitude, location.longitude, location.description,
                             location.blocked, location.blocked_reason
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
     * Zaehlt die Standorte eines Benutzers.
     *
     * Gebraucht von App\Model\GuideRole: Wer die Guide-Rolle zurueckgeben
     * will, darf keine Standorte mehr anbieten - ein Standort ohne Guide
     * waere ein Angebot, das niemand einloesen kann.
     *
     * Bewusst COUNT und nicht count(selectAllLocationsOfOneUser()): Fuer die
     * Frage "gibt es ueberhaupt welche" muessen weder Zeilen noch drei Joins
     * geladen werden.
     *
     * Gesperrte Standorte zaehlen mit. Eine Sperre ist eine Massnahme der
     * Moderation und keine Loeschung - der Datensatz gehoert weiterhin dem
     * Guide.
     *
     * @param int $in_user_id
     * @return int 0, wenn es keine gibt oder die Abfrage fehlschlaegt
     */
    public function countLocationsOfUser($in_user_id)
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return 0;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT COUNT(*) FROM location WHERE user_id = :user_id"
            );
            $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log('Fehler beim Zaehlen der User-Locations: ' . $e->getMessage());
            return 0;
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
    public function getId()            { return $this->id; }
    public function getUserId()        { return $this->user_id; }
    public function isBlocked()        { return (int)$this->blocked === 1; }
    public function getBlockedReason() { return $this->blocked_reason; }
    public function getCountry()     { return $this->country; }
    public function getCity()        { return $this->city; }
    public function getLatitude()    { return $this->latitude; }
    public function getLongitude()   { return $this->longitude; }
    public function getDescription() { return $this->description; }
}
