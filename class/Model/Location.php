<?php

namespace App\Model;

use App\Helper\Availability;

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
    private $title;
    private $description_long;
    private $duration_minutes;
    private $languages;

    /**
     * Die ueblichen Zeiten als 28-Zeichen-Muster (migrations/014).
     *
     * Gelesen und geschrieben ausschliesslich ueber App\Helper\Availability -
     * hier steht der rohe Wert, wie er in der Spalte liegt.
     */
    private $availability_slots;

    /** Zeitzone des Ortes, z. B. 'Europe/Lisbon'. NULL = noch nicht bestimmt. */
    private $timezone;

    /** Laendercode nach ISO 3166-1 alpha-2 - fuer die Ableitung der Zeitzone. */
    private $iso2;
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
                // country.iso2 kommt mit: Aus ihm und den Koordinaten leitet
                // App\Helper\Availability die Zeitzone des Ortes ab, wenn der
                // Standort noch keine traegt.
                $query = "SELECT location.*, country.country_name, country.iso2, city.city_name 
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
                    // Die Felder aus migrations/011. Sie koennen fehlen -
                    // aber nicht, weil die Migration ausbliebe (dann
                    // scheiterte die Abfrage selbst), sondern weil sie bei
                    // Bestandsdaten NULL sind. Ein leerer Titel ist ein
                    // gueltiger Zustand und kein Fehler.
                    $this->title            = $result['title'] ?? null;
                    $this->description_long = $result['description_long'] ?? null;
                    $this->duration_minutes = isset($result['duration_minutes'])
                        ? (int)$result['duration_minutes'] : null;
                    $this->languages        = $result['languages'] ?? null;
                    // Die Felder aus migrations/014. Wie oben: Sie duerfen
                    // fehlen, weil Bestandsdaten NULL tragen - "keine Angabe"
                    // ist ein gueltiger Zustand.
                    $this->availability_slots = $result['availability_slots'] ?? null;
                    $this->timezone           = $result['timezone'] ?? null;
                    $this->iso2               = $result['iso2'] ?? null;
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
            // availability_slots und timezone stehen mit in der Liste,
            // obwohl das Anlegeformular sie noch nicht anbietet: Sie kommen
            // dann als NULL an - "keine Angabe", der richtige Anfangszustand.
            // Stuenden sie nicht hier, ginge die Eingabe stillschweigend
            // verloren, sobald das Formular sie einmal anbietet.
            $query = "INSERT INTO location ( user_id,  city_id,  longitude,  latitude,
                                             description,  title,  description_long,
                                             duration_minutes,  languages,
                                             availability_slots,  timezone)
                                    VALUES (:user_id, :city_id, :longitude, :latitude,
                                            :description, :title, :description_long,
                                            :duration_minutes, :languages,
                                            :slots, :timezone)";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':city_id', $city_id, \PDO::PARAM_INT);
            $stmt->bindParam(':longitude', $this->longitude);
            $stmt->bindParam(':latitude', $this->latitude);
            $stmt->bindParam(':description', $this->description);
            $stmt->bindParam(':title', $this->title);
            $stmt->bindParam(':description_long', $this->description_long);
            // PARAM_INT waere hier falsch: Die Dauer darf NULL sein
            // ("nicht angegeben"), und PDO macht daraus mit PARAM_INT eine 0
            // - also die Aussage "dauert null Minuten".
            $stmt->bindParam(':duration_minutes', $this->duration_minutes);
            $stmt->bindParam(':languages', $this->languages);
            // Ohne Typangabe, aus demselben Grund wie bei der Dauer: NULL
            // soll NULL bleiben.
            $stmt->bindParam(':slots', $this->availability_slots);
            $stmt->bindParam(':timezone', $this->timezone);
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
                        longitude        = :longitude,
                        latitude         = :latitude,
                        description      = :description,
                        title            = :title,
                        description_long = :description_long,
                        duration_minutes = :duration_minutes,
                        languages        = :languages,
                        availability_slots = :slots,
                        timezone           = :timezone
                           WHERE id = :id
                             AND user_id = :user_id";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt ->bindParam(':longitude'       , $this->longitude       );
            $stmt ->bindParam(':latitude'        , $this->latitude        );
            $stmt ->bindParam(':description'     , $this->description     );
            $stmt ->bindParam(':title'           , $this->title           );
            $stmt ->bindParam(':description_long', $this->description_long);
            // Ohne Typangabe, damit NULL auch NULL bleibt - siehe
            // insertLocation().
            $stmt ->bindParam(':duration_minutes', $this->duration_minutes);
            $stmt ->bindParam(':languages'       , $this->languages       );
            // Ohne Typangabe, damit NULL NULL bleibt - siehe oben.
            $stmt ->bindParam(':slots'           , $this->availability_slots);
            $stmt ->bindParam(':timezone'        , $this->timezone        );
            $stmt ->bindParam(':id'              , $this->id              );
            $stmt ->bindParam(':user_id'         , $user_id               );
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
            // KEIN BENUTZERNAME MEHR. In der Spalte "Guide" dieser Liste
            // stand bisher die Anmeldekennung eines fremden Kontos -
            // ausgeliefert an jeden Angemeldeten, obwohl sie niemanden
            // etwas angeht und obwohl sie als Name nichts taugt. Was der
            // Kunde sieht, ist der Anzeigename aus dem Guide-Profil; nur
            // wenn es keinen gibt, steht dort weiterhin der Benutzername -
            // sonst haette ein Standort in der Liste gar keinen Anbieter.
            //
            // Entschieden wird das hier in SQL und nicht im Browser: Was
            // nicht ausgeliefert wird, kann auch nicht angezeigt werden.
            // Dieselbe Rueckfallregel steht in
            // App\Model\GuideProfile::anzeigename() - dort fuer Zeilen, die
            // schon geladen sind.
            $query = "SELECT user.id AS user_id,
                             COALESCE(NULLIF(guide_profile.display_name, ''), user.username)
                                 AS guide_name,
                             " . self::AVAILABILITY_SQL . " AS availability,
                             country.country_name, city.city_name, location.id,
                             location.latitude, location.longitude,
                             location.title, location.description,
                             location.blocked, location.blocked_reason,
                             -- DIE BEWERTUNG GEHOERT DORTHIN, WO GEWAEHLT
                             -- WIRD. Vorher stand sie nur auf der
                             -- Standortseite - also erst, nachdem ein Kunde
                             -- sich schon entschieden hatte, welchen Standort
                             -- er ansieht.
                             --
                             -- Der Durchschnitt kommt NUR mit, wenn es genug
                             -- Bewertungen gibt; darunter ist er NULL und die
                             -- Zahl der Fuehrungen tritt an seine Stelle.
                             -- Entschieden ist das in App\Model\TourReview
                             -- und nicht hier - und auch nicht im Browser.
                             " . TourReview::aggregateColumnsSql() . "
                      FROM location
                      LEFT JOIN user          ON location.user_id = user.id
                      LEFT JOIN guide_profile ON guide_profile.user_id = user.id
                      LEFT JOIN city          ON location.city_id = city.id
                      LEFT JOIN country       ON city.country_id = country.id
                      " . TourReview::aggregateJoinSql('location') . "
                      -- KEIN STANDORT EINES GELOESCHTEN KONTOS. Die Zeile
                      -- bleibt in der Datenbank stehen (User::del_it setzt
                      -- nur ein Kennzeichen), auf die Karte und in diese
                      -- Liste gehoert sie nicht mehr - siehe
                      -- App\Model\User::activeSql().
                      WHERE user.id != :user_id
                        AND " . User::activeSql('user') . $blocked_filter;
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
                             location.title,
                             location.description,
                             " . self::AVAILABILITY_SQL . " AS availability,
                             -- Auch fuer den GAST. Eine Bewertung ist eine
                             -- Auskunft ueber ein oeffentliches Angebot - sie
                             -- steht auf der Standortseite, die ein Gast
                             -- ebenfalls aufrufen darf, und sie verraet
                             -- nichts ueber einzelne Konten: nur Zahlen zu
                             -- einem Standort.
                             " . TourReview::aggregateColumnsSql() . "
                      FROM location
                      JOIN user    ON location.user_id = user.id
                      LEFT JOIN city    ON location.city_id = city.id
                      LEFT JOIN country ON city.country_id = country.id
                      " . TourReview::aggregateJoinSql('location') . "
                      WHERE location.blocked = 0
                        AND " . User::activeSql('user');
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden der oeffentlichen Karte: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Ist dieses Angebot UNVOLLSTAENDIG - als SQL-Bedingung?
     *
     * FUENF DINGE, und jedes einzelne kostet den Guide Kunden, ohne dass er
     * es merkt. Das ist der Grund fuer diesen Vorrat: Ein halb ausgefuelltes
     * Angebot sieht fuer seinen Eigentuemer fertig aus - er weiss ja, was er
     * anbietet. Was fehlt, sieht nur, wer davorsteht.
     *
     *   OHNE KOORDINATEN   Der Standort hat keine Nadel. Er ist auf der
     *                      Karte nicht zu finden, und die Karte ist der
     *                      Einstieg dieser Anwendung.
     *   OHNE TITEL         Altbestand vor migrations/011. In der Liste steht
     *                      dann die Beschreibung an seiner Stelle, auf der
     *                      Seite der Ort.
     *   OHNE AUSFUEHRLICHE BESCHREIBUNG
     *                      Die Standortseite ist die Seite, auf der ein Kunde
     *                      seine Entscheidung trifft. Ein Satz reicht dafuer
     *                      nicht.
     *   OHNE UEBLICHE ZEITEN
     *                      Dann weiss niemand, wann sich eine Anfrage lohnt -
     *                      und der Guide bekommt Anfragen zu Zeiten, zu denen
     *                      er nicht kann (migrations/014).
     *   OHNE BILD          Ein Angebot ohne Bild wird nicht gebucht. Geprueft
     *                      mit NOT EXISTS statt mit einem JOIN: Ein JOIN auf
     *                      location_image vervielfachte die Zeile, und die
     *                      Bedingung soll sich auch in ein WHERE einsetzen
     *                      lassen.
     *
     * WAS NICHT DAZUGEHOERT: die Dauer und die Sprachen. Beide haben eine
     * brauchbare Vorgabe, und ein Standort ohne sie ist nicht unauffindbar,
     * sondern nur etwas magerer.
     *
     * @param string $in_alias Tabellenalias in der Abfrage
     * @return string SQL-Bedingung
     */
    public static function unvollstaendigSql(string $in_alias = 'location'): string
    {
        $a = self::alias($in_alias);

        return "($a.latitude IS NULL OR $a.longitude IS NULL
                 OR $a.title IS NULL            OR $a.title = ''
                 OR $a.description_long IS NULL OR $a.description_long = ''
                 OR $a.availability_slots IS NULL
                 OR NOT EXISTS (SELECT 1 FROM location_image bild
                                 WHERE bild.location_id = $a.id))";
    }

    /**
     * Die einzelnen Maengel als Spalten - fuer die Zeile in der Liste.
     *
     * WARUM NEBEN unvollstaendigSql() UND NICHT DARIN: Die eine Bedingung
     * beantwortet "ist etwas offen" und taugt fuer WHERE und COUNT; diese
     * hier beantwortet "was genau", und das braucht fuenf Werte. Beide
     * beschreiben dasselbe, und deshalb stehen sie nebeneinander - faellt
     * eines der fuenf weg, faellt es in beiden auf.
     *
     * @param string $in_alias
     * @return string Spaltenliste (ohne fuehrendes Komma)
     */
    public static function maengelColumnsSql(string $in_alias = 'location'): string
    {
        $a = self::alias($in_alias);

        return "($a.latitude IS NULL OR $a.longitude IS NULL)          AS fehlt_ort,
                ($a.title IS NULL OR $a.title = '')                    AS fehlt_titel,
                ($a.description_long IS NULL OR $a.description_long = '') AS fehlt_text,
                ($a.availability_slots IS NULL)                        AS fehlt_zeiten,
                (NOT EXISTS (SELECT 1 FROM location_image bild
                              WHERE bild.location_id = $a.id))         AS fehlt_bild";
    }

    /**
     * Nur Buchstaben, Ziffern und Unterstriche im Tabellenalias.
     *
     * Der Alias kommt ausschliesslich aus diesem Projekt und nie von aussen.
     * Er geht aber als Textbaustein in eine Abfrage, und ein Textbaustein in
     * einer Abfrage wird geprueft - dieselbe Regel wie bei
     * App\Model\TourRequest::alias().
     *
     * @param string $in_alias
     * @return string
     */
    private static function alias(string $in_alias): string
    {
        $sauber = preg_replace('/[^a-zA-Z_]/', '', $in_alias);
        return $sauber === '' ? 'location' : $sauber;
    }

    /**
     * ALLE Standorte - die Liste des Verwaltungsbereichs.
     *
     * WARUM SIE NEBEN selectAllLocations() STEHT UND NICHT DARIN
     * ---------------------------------------------------------
     * Weil die beiden verschiedene Fragen beantworten, und das war vorher
     * derselbe Aufruf mit einem Schalter:
     *
     *   selectAllLocations()  "was kann ich als Kunde buchen" - fremde
     *                         Standorte, nie die eigenen, nie gesperrte.
     *   diese hier            "was gibt es" - alles, auch die eigenen und
     *                         gerade die gesperrten, samt Grund und
     *                         Zeitpunkt der Sperre.
     *
     * Der Schalter war eine Sonderfall-Ansicht mitten in der Kundenliste: Wer
     * moderieren durfte, bekam DIESELBE Tabelle mit zwei zusaetzlichen
     * Zeilenarten und zwei zusaetzlichen Knoepfen. Genau das soll mit dem
     * eigenen Bereich verschwinden - zwei Fragen, zwei Abfragen.
     *
     * WAS HIER MEHR DRINSTEHT als in der Kundenliste: der BENUTZERNAME des
     * Guides neben seinem Anzeigenamen, der Sperrgrund und wer wann gesperrt
     * hat. Der Benutzername ist die Anmeldekennung und geht Kunden nichts an
     * (siehe die Begruendung in selectAllLocations); die Verwaltung dagegen
     * verwaltet Konten und findet ein Konto ueber diesen Namen wieder.
     *
     * WAS FEHLT: die Bewertungszahlen. Sie gehoeren zur Auswahl eines
     * Standorts, nicht zu seiner Verwaltung - und sie kosten zwei Joins.
     *
     * @param string $in_filter         'alle', 'gesperrt' oder 'unvollstaendig'
     * @param int    $in_limit           Obergrenze der Zeilen
     * @param bool   $in_mit_geloeschten Standorte geloeschter Konten mitliefern
     * @return array
     */
    public function selectAllForAdmin(string $in_filter = 'alle', int $in_limit = 500,
                                      bool $in_mit_geloeschten = false)
    {
        // Wie ueberall in diesem Projekt: Ein Textbaustein in einer Abfrage
        // wird nachgeschlagen und nicht zusammengesetzt. Der Filter kommt aus
        // der Adresszeile.
        //
        // ZWEI UNABHAENGIGE ANGABEN, und deshalb ist "geloescht" KEIN vierter
        // Filterwert: "gesperrt" und "gehoert einem geloeschten Konto"
        // schliessen sich nicht aus. Waere es einer, liesse sich "gesperrte
        // Standorte geloeschter Konten" gar nicht mehr ansehen - und das ist
        // genau die Liste, die man nach einer Loeschung durchgeht.
        $wo = [
            'gesperrt'       => 'location.blocked = 1',
            'unvollstaendig' => self::unvollstaendigSql('location'),
        ];

        $bedingungen = [];
        if (isset($wo[$in_filter])) $bedingungen[] = $wo[$in_filter];

        // DIE VORGABE IST OHNE. Was einem geloeschten Konto gehoert, ist
        // ueberall sonst verschwunden (App\Model\User::activeSql); hier
        // bleibt es auffindbar, steht aber nicht im Weg. Wer danach sucht,
        // blendet es ein - und sieht die Zeilen dann gekennzeichnet.
        if (!$in_mit_geloeschten) $bedingungen[] = User::activeSql('user');

        $where = $bedingungen === [] ? '' : 'WHERE ' . implode(' AND ', $bedingungen);
        // LIMIT vertraegt in MySQL keinen gebundenen Parameter, solange PDO
        // nicht emuliert - deshalb als gepruefte Zahl in den Text.
        $limit = max(1, min(2000, $in_limit));

        try {
            $query = "SELECT location.id, location.title, location.description,
                             location.blocked, location.blocked_reason,
                             location.blocked_at,
                             user.id AS user_id, user.username,
                             -- IMMER MITGELIEFERT, auch wenn die Vorgabe
                             -- geloeschte Konten ausblendet: Sobald sie
                             -- eingeblendet sind, muss die Zeile sagen, warum
                             -- sie auf keine Seite mehr verweist.
                             user.deleted AS user_deleted,
                             COALESCE(NULLIF(guide_profile.display_name, ''), user.username)
                                 AS guide_name,
                             " . self::AVAILABILITY_SQL . " AS availability,
                             country.country_name, city.city_name,
                             -- WAS AN DIESEM ANGEBOT FEHLT - immer mitgeliefert
                             -- und nicht nur im Filter: Ein Standort kann
                             -- gesperrt UND unvollstaendig sein, und wer die
                             -- Sperrliste durchgeht, soll das Zweite nicht
                             -- uebersehen.
                             " . self::maengelColumnsSql('location') . "
                      FROM location
                      LEFT JOIN user          ON location.user_id = user.id
                      LEFT JOIN guide_profile ON guide_profile.user_id = user.id
                      LEFT JOIN city          ON location.city_id = city.id
                      LEFT JOIN country       ON city.country_id = country.id
                      $where
                      ORDER BY location.blocked DESC, location.id DESC
                      LIMIT $limit";
            $stmt = PdoConnect::$connection->query($query);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('Location::selectAllForAdmin: ' . $e->getMessage());
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
                             location.latitude, location.longitude,
                             location.title, location.description,
                             location.blocked, location.blocked_reason,
                             -- Auch in der EIGENEN Liste: Der Guide soll
                             -- sehen, was ein Kunde an dieser Stelle sieht -
                             -- dieselbe Regel wie beim Guide-Streifen auf der
                             -- Standortseite. Steht dort kein Durchschnitt,
                             -- steht bei seinen Kunden auch keiner.
                             " . TourReview::aggregateColumnsSql() . "
                      FROM location
                      LEFT JOIN user    ON location.user_id = user.id
                      LEFT JOIN city    ON location.city_id = city.id
                      LEFT JOIN country ON city.country_id = country.id
                      " . TourReview::aggregateJoinSql('location') . "
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
     * Die Standorte EINES Guides, so wie seine Profilseite sie zeigt.
     *
     * WARUM NICHT selectAllLocationsOfOneUser()
     * -----------------------------------------
     * Weil das die Liste ist, die ein Guide von SEINEN EIGENEN Standorten
     * sieht - mit Sperrgrund, mit Bearbeitungsknoepfen, fuer ihn allein.
     * Diese hier bekommt ein Kunde, und zwar auch ein nicht angemeldeter.
     * Sie enthaelt deshalb nichts, was ihn nichts angeht: keinen
     * Benutzernamen, keine user_id, keinen rohen Anwesenheitsstatus.
     * Dieselbe Ueberlegung wie bei selectPublicMapLocations() - wer beide
     * Faelle aus einer Abfrage bedient, entscheidet die Frage "was ist
     * oeffentlich" im Controller, und diese Entscheidung ist beim naechsten
     * Umbau als Erstes vergessen.
     *
     * DAS TITELBILD KOMMT MIT. Eine Liste von Angeboten ohne Bilder ist eine
     * Liste von Ueberschriften; die Profilseite soll zeigen, was dieser
     * Guide anbietet. Der Join traegt die Rolle aus
     * App\Model\LocationImage - eine Zeichenkette aus dem Programm, kein
     * Wert aus einer Anfrage.
     *
     * GESPERRTE STANDORTE BLEIBEN AUSSEN VOR, das ist der Sinn der Sperre.
     * Nur wer sie sehen darf - der Eigentuemer auf seinem eigenen Profil und
     * die Moderation - bekommt sie mitgeliefert; entschieden wird das im
     * Controller, der die Rechte kennt.
     *
     * @param int  $in_user_id
     * @param bool $in_with_blocked Gesperrte mitliefern
     * @return array<int,array<string,mixed>>
     */
    public function selectLocationsOfGuide($in_user_id, $in_with_blocked = false)
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return [];

        // Fester Textbaustein, kein Parameter: In die Abfrage kommt nichts,
        // was ein Aufrufer beeinflussen koennte.
        $blocked_filter = $in_with_blocked ? '' : ' AND location.blocked = 0';

        try {
            $query = "SELECT location.id, location.title, location.description,
                             location.duration_minutes, location.languages,
                             location.blocked,
                             country.country_name, city.city_name,
                             cover.id AS cover_image_id,
                             " . self::AVAILABILITY_SQL . " AS availability
                      FROM location
                      JOIN user         ON location.user_id = user.id
                      LEFT JOIN city    ON location.city_id = city.id
                      LEFT JOIN country ON city.country_id = country.id
                      LEFT JOIN location_image AS cover
                             ON cover.location_id = location.id
                            AND cover.role = '" . LocationImage::ROLE_COVER . "'
                      -- Auch hier: kein Standort eines geloeschten Kontos.
                      -- Diese Liste steht auf dem Guide-Profil, also auf
                      -- einer Seite, die jeder aufrufen kann.
                      WHERE location.user_id = :user_id
                        AND " . User::activeSql('user') . $blocked_filter . "
                      ORDER BY country.country_name ASC, city.city_name ASC, location.id ASC";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden der Standorte eines Guides: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * EINEN Standort mit allem, was seine eigene Seite zeigt.
     *
     * WARUM NICHT DER KONSTRUKTOR
     * ---------------------------
     * new Location($id) laedt die Zeile fuer die BEARBEITUNG - Felder in ein
     * Objekt, damit ein Setter sie aendern und updateLocation() sie
     * zurueckschreiben kann. Diese Methode laedt sie fuer die ANZEIGE, und
     * dafuer fehlen dort zwei Angaben, die gar nicht am Standort haengen:
     * der Name des Guides und seine Verfuegbarkeit. Beides nachtraeglich mit
     * einer zweiten Abfrage zu holen waere ein zweiter Weg zur selben Seite.
     *
     * Die Verfuegbarkeit kommt fertig ausgewertet aus AVAILABILITY_SQL -
     * derselbe Ausdruck wie in Karte und Liste. Eine Standortseite, die
     * "verfuegbar" anders beantwortet als die Nadel, von der aus man auf sie
     * geklickt hat, waere schlimmer als gar keine Angabe.
     *
     * KEIN FILTER AUF DIE SPERRE. Wer einen gesperrten Standort sehen darf -
     * sein Eigentuemer und die Moderation -, entscheidet der Controller; er
     * bekommt dafuer blocked und blocked_reason mitgeliefert. Hier zu
     * filtern hiesse, dass der Guide seinen eigenen gesperrten Standort nicht
     * mehr aufrufen koennte, um ihn zu ueberarbeiten.
     *
     * @param int $in_id
     * @return array<string,mixed>|null null, wenn es den Standort nicht gibt
     */
    public function selectOneForPage($in_id): ?array
    {
        $id = (int)$in_id;
        if ($id < 1) return null;

        try {
            $query = "SELECT location.id, location.user_id,
                             location.latitude, location.longitude,
                             location.title, location.description,
                             location.description_long,
                             location.duration_minutes, location.languages,
                             -- Die ueblichen Zeiten und die Zone, in der sie
                             -- gelten (migrations/014). country.iso2 kommt
                             -- mit, damit die Zone auch bei einem Standort
                             -- ohne Eintrag abgeleitet werden kann.
                             location.availability_slots, location.timezone,
                             location.blocked, location.blocked_reason,
                             country.country_name, country.iso2, city.city_name,
                             -- DER GUIDE ALS MENSCH. Er steht auf der Seite
                             -- mit Bild, Anzeigename und einem Satz aus
                             -- seiner Selbstbeschreibung - das ist Teil der
                             -- Entscheidungsgrundlage und keine Randnotiz.
                             --
                             -- Mitgeladen und nicht nachgefragt, aus dem
                             -- Grund, der oben im Kommentar steht: Eine
                             -- zweite Abfrage waere ein zweiter Weg zur
                             -- selben Seite. Der Benutzername kommt weiter
                             -- mit, aber nur noch als Rueckfall fuer ein
                             -- Profil ohne Anzeigenamen - angezeigt wird er
                             -- ueber App\Model\GuideProfile::anzeigename().
                             user.username,
                             guide_profile.display_name, guide_profile.about,
                             guide_profile.avatar_file,
                             " . self::AVAILABILITY_SQL . " AS availability
                      FROM location
                      JOIN user               ON location.user_id = user.id
                      LEFT JOIN guide_profile ON guide_profile.user_id = user.id
                      LEFT JOIN city          ON location.city_id = city.id
                      LEFT JOIN country       ON city.country_id = country.id
                      -- Ein geloeschtes Konto hat keine Standortseite mehr -
                      -- und die Antwort ist dieselbe wie fuer einen Standort,
                      -- den es nie gab. Ueber diese eine Zeile faellt auch
                      -- die Anfrage weg: App\Controller\RequestController
                      -- laedt den Standort mit genau dieser Methode.
                      WHERE location.id = :id
                        AND " . User::activeSql('user');
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $zeile ?: null;
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden der Standortseite: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Nur die Verfuegbarkeit eines Standorts.
     *
     * Die Standortseite fragt sie im Takt nach, damit der Knopf "Fuehrung
     * starten" nicht anbietet, was gerade nicht geht. Dafuer die ganze Seite
     * neu zu laden waere unverhaeltnismaessig, und die vollstaendige
     * Standortliste zu holen ebenso - es geht um ein Wort.
     *
     * Derselbe Ausdruck wie ueberall sonst: AVAILABILITY_SQL. Eine zweite
     * Auswertung waere eine zweite Antwort auf dieselbe Frage.
     *
     * @param int $in_id
     * @return string|null 'live', 'busy', 'idle' - null, wenn es den Standort nicht gibt
     */
    public function availabilityOf($in_id): ?string
    {
        $id = (int)$in_id;
        if ($id < 1) return null;

        try {
            $query = "SELECT " . self::AVAILABILITY_SQL . " AS availability,
                             location.blocked
                      FROM location
                      JOIN user ON location.user_id = user.id
                      -- Ohne diese Zeile meldete ein Standort, dessen Konto
                      -- geloescht ist, weiterhin 'live' - die Standortseite
                      -- gibt es dann zwar nicht mehr, aber diese Route wird
                      -- im Takt abgefragt und antwortete weiter.
                      WHERE location.id = :id
                        AND " . User::activeSql('user');
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$zeile) return null;

            // Ein gesperrter Standort ist nie verfuegbar - dieselbe Regel wie
            // auf der Karte (assets/js/home_map.js). Die Sperre schlaegt jede
            // Bereitschaft.
            return (int)$zeile['blocked'] === 1 ? 'idle' : (string)$zeile['availability'];
        } catch (\PDOException $e) {
            error_log('Fehler beim Lesen der Verfuegbarkeit: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Nur der Guide eines Standorts - seine Kontokennung.
     *
     * WOZU ES DIESE METHODE GIBT
     * --------------------------
     * Sie ist die Stelle, an der aus einem Standort ein Gegenueber wird: Wen
     * ein Kunde anschreiben darf, sagt ab jetzt der Standort und nicht mehr
     * die Anfrage (App\Controller\ChatController::startChat, Befund N-12).
     * Vorher nahm die Route eine beliebige Kontokennung entgegen.
     *
     * EIN GESPERRTER STANDORT GIBT NICHTS HERAUS - er meldet null wie ein
     * Standort, den es nicht gibt. Dieselbe Regel wie bei availabilityOf()
     * daneben und wie im Aktionsbereich der Standortseite: Von einem
     * gesperrten Standort aus beginnt nichts, auch kein Gespraech. Die Sperre
     * ist damit an EINER Stelle ausgewertet und nicht beim Aufrufer.
     *
     * Bewusst nicht selectOneForPage(): Fuer eine Kennung braucht es weder
     * fuenf Joins noch Bilder, Sprachen und Zeitraster - und diese Frage
     * stellt sich bei jedem Oeffnen eines Chatfensters.
     *
     * @param int $in_id
     * @return int|null null, wenn es den Standort nicht gibt oder er gesperrt ist
     */
    public function guideIdOf($in_id): ?int
    {
        $id = (int)$in_id;
        if ($id < 1) return null;

        try {
            // JOIN user statt eines schlichten SELECT auf location: Ohne
            // ihn liesse sich dem Konto eines geloeschten Guides weiter
            // schreiben - diese Methode ist der einzige Weg, auf dem ein
            // Kunde ein Gegenueber fuer einen Chat bekommt.
            $stmt = PdoConnect::$connection->prepare(
                "SELECT location.user_id, location.blocked
                   FROM location
                   JOIN user ON location.user_id = user.id
                  WHERE location.id = :id
                    AND " . User::activeSql('user')
            );
            $stmt->bindParam(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$zeile || (int)$zeile['blocked'] === 1) return null;

            $guide = (int)$zeile['user_id'];
            return $guide > 0 ? $guide : null;
        } catch (\PDOException $e) {
            error_log('Fehler beim Lesen des Guides zum Standort: ' . $e->getMessage());
            return null;
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
    public function setTitle($in_title)         { $this->title = $in_title; }
    public function setDescriptionLong($in_txt) { $this->description_long = $in_txt; }
    /**
     * Uebliche Dauer in Minuten.
     *
     * NULL und 0 sind hier NICHT dasselbe: NULL heisst "nicht angegeben" und
     * wird auf der Seite gar nicht erst erwaehnt, 0 waere die Aussage "dauert
     * keine Zeit". Deshalb geht ein leerer Wert als null durch und nicht als
     * (int)'' - das waere 0.
     *
     * @param mixed $in_minutes
     * @return void
     */
    public function setDurationMinutes($in_minutes)
    {
        $this->duration_minutes = ($in_minutes === null || $in_minutes === '')
            ? null : (int)$in_minutes;
    }
    /**
     * Sprachen als Kuerzelliste.
     *
     * Der Wert wird NICHT hier geprueft, sondern von
     * App\Helper\Languages::normalize() im Controller - dort steht, was ein
     * gueltiges Kuerzel ist, und dort steht es nur einmal.
     *
     * @param string|null $in_languages
     * @return void
     */
    public function setLanguages($in_languages) { $this->languages = $in_languages; }

    /**
     * Die ueblichen Zeiten als Muster.
     *
     * Ein leeres Muster (nur Nullen) wird zu NULL: "keine Angabe" und "alle
     * Felder abgewaehlt" sind dasselbe, und die Spalte soll nur eine Fassung
     * davon kennen.
     *
     * @param mixed $in_slots
     */
    public function setAvailabilitySlots($in_slots)
    {
        $muster = Availability::muster($in_slots);
        $this->availability_slots = Availability::istLeer($muster) ? null : $muster;
    }

    /**
     * Die Zeitzone des Ortes.
     *
     * Nur eine Zone, die PHP kennt - sonst NULL. Ein unbekannter Name in der
     * Spalte waere schlimmer als gar keiner: Er saehe aus wie eine Angabe.
     *
     * @param mixed $in_zone
     */
    public function setTimezone($in_zone)
    {
        $this->timezone = Availability::istZone($in_zone) ? (string)$in_zone : null;
    }

    // Getter 
    public function getId()            { return $this->id; }
    public function getIso2()          { return $this->iso2; }
    public function getAvailabilitySlots() { return $this->availability_slots; }
    public function getTimezone()      { return $this->timezone; }
    public function getUserId()        { return $this->user_id; }
    public function isBlocked()        { return (int)$this->blocked === 1; }
    public function getBlockedReason() { return $this->blocked_reason; }
    public function getCountry()     { return $this->country; }
    public function getCity()        { return $this->city; }
    public function getLatitude()    { return $this->latitude; }
    public function getLongitude()   { return $this->longitude; }
    public function getDescription() { return $this->description; }
    public function getTitle()            { return $this->title; }
    public function getDescriptionLong()  { return $this->description_long; }
    public function getDurationMinutes()  { return $this->duration_minutes; }
    public function getLanguages()        { return $this->languages; }
}
