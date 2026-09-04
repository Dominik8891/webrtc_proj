<?php

namespace App\Model;

use App\Helper\ImageStore;

/**
 * Die Bilder eines Standorts - die Zeilen dazu, nicht die Dateien.
 *
 * ARBEITSTEILUNG
 * --------------
 *   App\Helper\ImageStore  faengt die hochgeladene Datei ab, prueft sie,
 *                          rechnet sie um und legt sie ausserhalb des
 *                          Webroots ab. Er kennt keine Datenbank.
 *   Diese Klasse           weiss, welche Bilder zu welchem Standort gehoeren
 *                          und in welcher Reihenfolge sie stehen. Sie fasst
 *                          keine Datei an.
 *
 * Zusammengefuehrt wird beides im LocationController - und zwar in dieser
 * Reihenfolge: erst die Datei, dann die Zeile. Scheitert das Schreiben der
 * Datei, entsteht gar keine Zeile; scheitert die Zeile, wird die Datei
 * wieder weggeraeumt. Der umgekehrte Weg haette Zeilen ohne Bild
 * hinterlassen, und die zeigt eine Seite als kaputtes Bild an.
 *
 * EIGENTUM STEHT IN DER WHERE-KLAUSEL - dieselbe Regel wie in
 * App\Model\Location: Jedes Statement, das ein Bild aendert oder loescht,
 * traegt den Eigentuemer des STANDORTS in der Bedingung. Ein Bild gehoert
 * niemandem fuer sich; es gehoert dem, dem sein Standort gehoert. Deshalb
 * steht in jeder dieser Abfragen ein JOIN auf location.
 */
class LocationImage
{
    /**
     * Das eine Bild, das die Kopfzeile der Standortseite fuellt.
     *
     * HOECHSTENS EINES JE STANDORT. Durchgesetzt wird das von setCover(), das
     * das bisherige Titelbild in derselben Transaktion zurueck in die Galerie
     * nimmt - nicht von der Datenbank: Ein Teilindex ueber "role = 'cover'"
     * gibt es in MariaDB nicht.
     */
    public const ROLE_COVER = 'cover';

    /**
     * Ein Beispielbild in der Galerie im Inhaltsbereich.
     *
     * DIE VORGABE, und das ist eine Entscheidung: Ein hochgeladenes Bild ist
     * erst einmal nur ein Bild. Zum Titelbild wird es, weil jemand es
     * auswaehlt. Waere 'cover' die Vorgabe, verdraengte jedes neue Bild das
     * Titelbild - genau das Verhalten, das abgeschafft werden sollte.
     */
    public const ROLE_GALLERY = 'gallery';

    /**
     * Teilt die Bilder eines Standorts in Titelbild und Galerie.
     *
     * REINE FUNKTION, absichtlich: Geladen wird EINMAL (forLocation), geteilt
     * wird hier. Zwei Abfragen - eine fuer das Titelbild, eine fuer die
     * Galerie - waeren zwei Wege zu denselben Zeilen und zwei Gelegenheiten,
     * sie auseinanderlaufen zu lassen. Es sind ohnehin nur eine Handvoll
     * Zeilen.
     *
     * Findet sich mehr als ein Titelbild - was setCover() verhindert, was
     * aber ein von Hand veraenderter Datenbestand hergeben koennte -, gilt
     * das erste. Die uebrigen landen in der Galerie, statt verlorenzugehen.
     *
     * @param array<int,array<string,mixed>> $in_bilder Aus forLocation()
     * @return array{cover: array<string,mixed>|null, gallery: array<int,array<string,mixed>>}
     */
    public static function teile(array $in_bilder): array
    {
        $cover   = null;
        $gallery = [];

        foreach ($in_bilder as $bild) {
            if ($cover === null && ($bild['role'] ?? '') === self::ROLE_COVER) {
                $cover = $bild;
                continue;
            }
            $gallery[] = $bild;
        }

        return ['cover' => $cover, 'gallery' => $gallery];
    }

    /**
     * Die Bilder eines Standorts in ihrer Reihenfolge.
     *
     * @param int $in_location_id
     * @return array<int,array<string,mixed>> je Eintrag id, file_name, sort_order
     */
    public static function forLocation($in_location_id): array
    {
        $id = (int)$in_location_id;
        if ($id < 1) return [];

        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT id, file_name, `role`, sort_order
                   FROM location_image
                  WHERE location_id = :location_id
                  ORDER BY sort_order ASC, id ASC"
            );
            $stmt->bindParam(':location_id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden der Standortbilder: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Wie viele Bilder hat dieser Standort schon?
     *
     * Gebraucht fuer die Obergrenze. Bewusst COUNT und nicht
     * count(forLocation()): Fuer die Frage "ist noch Platz" muss keine Zeile
     * geladen werden.
     *
     * @param int $in_location_id
     * @return int
     */
    public static function countForLocation($in_location_id): int
    {
        $id = (int)$in_location_id;
        if ($id < 1) return 0;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT COUNT(*) FROM location_image WHERE location_id = :location_id"
            );
            $stmt->bindParam(':location_id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (\PDOException $e) {
            error_log('Fehler beim Zaehlen der Standortbilder: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Traegt ein abgelegtes Bild ein.
     *
     * Die Reihenfolge wird nicht mitgegeben, sondern hier bestimmt: Ein neues
     * Bild kommt ans Ende. Der Ausdruck COALESCE(MAX(sort_order), -1) + 1
     * rechnet das in derselben Anweisung aus, in der eingefuegt wird - ein
     * vorheriges SELECT MAX haette ein Zeitfenster gelassen, in dem zwei
     * gleichzeitige Uploads dieselbe Position bekommen.
     *
     * @param int    $in_location_id
     * @param string $in_file_name Name aus ImageStore::store()
     * @param string $in_role      ROLE_COVER oder ROLE_GALLERY
     * @return int|false Neue Zeilen-ID oder false
     */
    public static function add($in_location_id, $in_file_name, string $in_role = self::ROLE_GALLERY)
    {
        $id = (int)$in_location_id;
        if ($id < 1 || !ImageStore::isValidName($in_file_name)) {
            error_log('LocationImage::add: unbrauchbare Angaben.');
            return false;
        }

        // Eine unbekannte Rolle wird zur Galerie und nicht zum Titelbild: Ein
        // Tippfehler soll nicht die Kopfzeile besetzen.
        $role = ($in_role === self::ROLE_COVER) ? self::ROLE_COVER : self::ROLE_GALLERY;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "INSERT INTO location_image (location_id, file_name, `role`, sort_order)
                 SELECT :location_id, :file_name, :role, COALESCE(MAX(sort_order), -1) + 1
                   FROM location_image
                  WHERE location_id = :location_id_pos"
            );
            $stmt->bindParam(':location_id'    , $id, \PDO::PARAM_INT);
            $stmt->bindParam(':location_id_pos', $id, \PDO::PARAM_INT);
            $stmt->bindParam(':file_name'      , $in_file_name);
            $stmt->bindParam(':role'           , $role);
            $stmt->execute();
            return (int)PdoConnect::$connection->lastInsertId();
        } catch (\PDOException $e) {
            error_log('Fehler beim Eintragen eines Standortbildes: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ein Bild samt der Angaben seines Standorts.
     *
     * Gebraucht beim AUSLIEFERN. Die Antwort enthaelt deshalb genau das, was
     * der Controller fuer seine Entscheidung braucht - wem der Standort
     * gehoert und ob er gesperrt ist -, und nichts weiter.
     *
     * @param int $in_image_id
     * @return array<string,mixed>|null
     */
    public static function findWithLocation($in_image_id): ?array
    {
        $id = (int)$in_image_id;
        if ($id < 1) return null;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT location_image.id, location_image.file_name,
                        location_image.location_id, location_image.`role`,
                        location.user_id, location.blocked
                   FROM location_image
                   JOIN location ON location_image.location_id = location.id
                  WHERE location_image.id = :id"
            );
            $stmt->bindParam(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $zeile ?: null;
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden eines Standortbildes: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Loescht ein Bild, das zu einem EIGENEN Standort gehoert.
     *
     * Der Eigentuemer steht im Statement und nicht nur im Controller - wie
     * ueberall dort, wo ein vorhandener Datensatz geaendert wird. Ein fremdes
     * Bild trifft damit keine Zeile.
     *
     * Der Dateiname wird VOR dem Loeschen zurueckgegeben, denn danach steht
     * er nirgends mehr: Ohne ihn liesse sich die Datei nicht mehr finden und
     * bliebe fuer immer liegen.
     *
     * @param int $in_image_id
     * @param int $in_user_id
     * @return array{location_id:int, file_name:string}|null null, wenn nichts getroffen wurde
     */
    public static function deleteOwned($in_image_id, $in_user_id): ?array
    {
        $id      = (int)$in_image_id;
        $user_id = (int)$in_user_id;
        if ($id < 1 || $user_id < 1) return null;

        $zeile = self::findWithLocation($id);
        if ($zeile === null || (int)$zeile['user_id'] !== $user_id) {
            error_log("LocationImage::deleteOwned: Bild #$id gehoert nicht zu Benutzer #$user_id");
            return null;
        }

        try {
            // Trotz der Pruefung oben steht der Eigentuemer auch im DELETE.
            // Die Pruefung ist die verstaendliche Antwort, das Statement die
            // verbindliche.
            $stmt = PdoConnect::$connection->prepare(
                "DELETE location_image
                   FROM location_image
                   JOIN location ON location_image.location_id = location.id
                  WHERE location_image.id = :id
                    AND location.user_id  = :user_id"
            );
            $stmt->bindParam(':id'     , $id     , \PDO::PARAM_INT);
            $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() < 1) return null;

            return [
                'location_id' => (int)$zeile['location_id'],
                'file_name'   => (string)$zeile['file_name'],
            ];
        } catch (\PDOException $e) {
            error_log('Fehler beim Loeschen eines Standortbildes: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Macht ein Bild zum Titelbild eines eigenen Standorts.
     *
     * ZWEI SCHRITTE IN EINER TRANSAKTION, und die Reihenfolge ist beliebig -
     * die Transaktion ist der Punkt: Zwischen "das alte zurueck in die
     * Galerie" und "das neue zum Titelbild" darf es keinen Zustand geben, in
     * dem ein Standort zwei Titelbilder hat oder gar keines. Genau diese
     * Bedingung kann die Datenbank hier nicht selbst durchsetzen: Ein
     * Teilindex ueber "role = 'cover'" gibt es in MariaDB nicht.
     *
     * DAS ALTE TITELBILD WIRD NICHT GELOESCHT, sondern zurueck in die Galerie
     * genommen. Ein Bild verschwindet in dieser Anwendung nur, wenn jemand es
     * loescht - eine Auswahl ist keine Loeschung, und der Guide will das
     * bisherige Titelbild meistens behalten.
     *
     * Das Eigentum steht in beiden Statements, nicht nur in einer Pruefung
     * davor: Ein fremdes Bild trifft damit keine Zeile.
     *
     * @param int $in_image_id
     * @param int $in_user_id
     * @return bool true, wenn das Bild danach das Titelbild ist
     */
    public static function setCover($in_image_id, $in_user_id): bool
    {
        $id      = (int)$in_image_id;
        $user_id = (int)$in_user_id;
        if ($id < 1 || $user_id < 1) return false;

        $bild = self::findWithLocation($id);
        if ($bild === null || (int)$bild['user_id'] !== $user_id) {
            error_log("LocationImage::setCover: Bild #$id gehoert nicht zu Benutzer #$user_id");
            return false;
        }
        $location_id = (int)$bild['location_id'];

        try {
            PdoConnect::$connection->beginTransaction();

            // 1. Das bisherige Titelbild zurueck in die Galerie. Trifft
            //    nichts, wenn es keines gab - das ist kein Fehler.
            $zurueck = PdoConnect::$connection->prepare(
                "UPDATE location_image
                   JOIN location ON location_image.location_id = location.id
                    SET location_image.`role` = :gallery
                  WHERE location_image.location_id = :location_id
                    AND location_image.`role`      = :cover
                    AND location.user_id           = :user_id"
            );
            $gallery = self::ROLE_GALLERY;
            $cover   = self::ROLE_COVER;
            $zurueck->bindParam(':gallery'    , $gallery);
            $zurueck->bindParam(':cover'      , $cover);
            $zurueck->bindParam(':location_id', $location_id, \PDO::PARAM_INT);
            $zurueck->bindParam(':user_id'    , $user_id    , \PDO::PARAM_INT);
            $zurueck->execute();

            // 2. Das gewaehlte Bild zum Titelbild.
            $hoch = PdoConnect::$connection->prepare(
                "UPDATE location_image
                   JOIN location ON location_image.location_id = location.id
                    SET location_image.`role` = :cover
                  WHERE location_image.id = :id
                    AND location.user_id    = :user_id"
            );
            $hoch->bindParam(':cover'  , $cover);
            $hoch->bindParam(':id'     , $id     , \PDO::PARAM_INT);
            $hoch->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $hoch->execute();
            $getroffen = $hoch->rowCount();

            PdoConnect::$connection->commit();

            // rowCount ist 0, wenn das Bild schon das Titelbild WAR - dann
            // hat Schritt 1 es gerade zurueckgenommen und Schritt 2 wieder
            // hochgesetzt, ohne den Wert zu aendern. Das ist kein Fehlschlag:
            // Der gewuenschte Zustand steht.
            return true;
        } catch (\PDOException $e) {
            if (PdoConnect::$connection->inTransaction()) {
                PdoConnect::$connection->rollBack();
            }
            error_log('Fehler beim Waehlen des Titelbildes: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Nimmt das Titelbild eines eigenen Standorts zurueck in die Galerie.
     *
     * NICHT LOESCHEN - zurueckstufen. Wer sein Titelbild "entfernt", will in
     * aller Regel ein anderes waehlen und nicht das Bild verlieren. Loeschen
     * kann er es weiterhin, dafuer gibt es deleteOwned().
     *
     * @param int $in_location_id
     * @param int $in_user_id
     * @return bool true, wenn danach kein Titelbild mehr gesetzt ist
     */
    public static function clearCover($in_location_id, $in_user_id): bool
    {
        $location_id = (int)$in_location_id;
        $user_id     = (int)$in_user_id;
        if ($location_id < 1 || $user_id < 1) return false;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE location_image
                   JOIN location ON location_image.location_id = location.id
                    SET location_image.`role` = :gallery
                  WHERE location_image.location_id = :location_id
                    AND location_image.`role`      = :cover
                    AND location.user_id           = :user_id"
            );
            $gallery = self::ROLE_GALLERY;
            $cover   = self::ROLE_COVER;
            $stmt->bindParam(':gallery'    , $gallery);
            $stmt->bindParam(':cover'      , $cover);
            $stmt->bindParam(':location_id', $location_id, \PDO::PARAM_INT);
            $stmt->bindParam(':user_id'    , $user_id    , \PDO::PARAM_INT);
            $stmt->execute();
            return true;
        } catch (\PDOException $e) {
            error_log('Fehler beim Zuruecknehmen des Titelbildes: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Hat dieser Standort schon ein Titelbild?
     *
     * Gebraucht beim Hochladen: Solange keines gewaehlt ist, wird das erste
     * abgelegte Bild eines - sonst stuende ein frischer Standort mit fuenf
     * Bildern unter einem leeren Kopf, und der Guide muesste erst merken,
     * dass er noch etwas auswaehlen soll.
     *
     * @param int $in_location_id
     * @return bool
     */
    public static function hasCover($in_location_id): bool
    {
        $id = (int)$in_location_id;
        if ($id < 1) return false;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "SELECT COUNT(*) FROM location_image
                  WHERE location_id = :location_id AND `role` = :cover"
            );
            $cover = self::ROLE_COVER;
            $stmt->bindParam(':location_id', $id, \PDO::PARAM_INT);
            $stmt->bindParam(':cover'      , $cover);
            $stmt->execute();
            return (int)$stmt->fetchColumn() > 0;
        } catch (\PDOException $e) {
            error_log('Fehler beim Pruefen des Titelbildes: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Setzt die Reihenfolge der Bilder eines eigenen Standorts neu.
     *
     * Uebergeben wird die vollstaendige Liste der Bild-IDs in ihrer neuen
     * Folge. Wer eine fremde ID einschmuggelt, aendert nichts: Jedes UPDATE
     * traegt sowohl die Standortkennung als auch den Eigentuemer in der
     * Bedingung, ein fremdes Bild trifft also keine Zeile.
     *
     * Alles in EINER Transaktion. Bricht es in der Mitte ab, stuenden sonst
     * zwei Bilder auf derselben Position und die Reihenfolge waere Zufall.
     *
     * @param int   $in_location_id
     * @param int   $in_user_id
     * @param int[] $in_image_ids Neue Reihenfolge
     * @return bool
     */
    public static function reorder($in_location_id, $in_user_id, array $in_image_ids): bool
    {
        $location_id = (int)$in_location_id;
        $user_id     = (int)$in_user_id;
        if ($location_id < 1 || $user_id < 1 || $in_image_ids === []) return false;

        try {
            PdoConnect::$connection->beginTransaction();

            $stmt = PdoConnect::$connection->prepare(
                "UPDATE location_image
                   JOIN location ON location_image.location_id = location.id
                    SET location_image.sort_order = :sort_order
                  WHERE location_image.id          = :id
                    AND location_image.location_id = :location_id
                    AND location_image.`role`      = :gallery
                    AND location.user_id           = :user_id"
            );
            // Sortiert wird die GALERIE. Das Titelbild steht nicht darin und
            // hat keine Position, die man verschieben koennte - eine
            // untergeschobene Kennung soll ihm auch keine geben.
            $gallery = self::ROLE_GALLERY;

            $position = 0;
            foreach ($in_image_ids as $image_id) {
                $id = (int)$image_id;
                if ($id < 1) continue;
                $stmt->bindParam(':sort_order' , $position   , \PDO::PARAM_INT);
                $stmt->bindParam(':id'         , $id         , \PDO::PARAM_INT);
                $stmt->bindParam(':location_id', $location_id, \PDO::PARAM_INT);
                $stmt->bindParam(':gallery'    , $gallery);
                $stmt->bindParam(':user_id'    , $user_id    , \PDO::PARAM_INT);
                $stmt->execute();
                $position++;
            }

            PdoConnect::$connection->commit();
            return true;
        } catch (\PDOException $e) {
            // inTransaction() gefragt, nicht angenommen: Schlaegt schon
            // beginTransaction fehl, waere ein rollBack() selbst ein Fehler.
            if (PdoConnect::$connection->inTransaction()) {
                PdoConnect::$connection->rollBack();
            }
            error_log('Fehler beim Sortieren der Standortbilder: ' . $e->getMessage());
            return false;
        }
    }
}
