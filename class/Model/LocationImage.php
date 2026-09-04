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
                "SELECT id, file_name, sort_order
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
     * @return int|false Neue Zeilen-ID oder false
     */
    public static function add($in_location_id, $in_file_name)
    {
        $id = (int)$in_location_id;
        if ($id < 1 || !ImageStore::isValidName($in_file_name)) {
            error_log('LocationImage::add: unbrauchbare Angaben.');
            return false;
        }

        try {
            $stmt = PdoConnect::$connection->prepare(
                "INSERT INTO location_image (location_id, file_name, sort_order)
                 SELECT :location_id, :file_name, COALESCE(MAX(sort_order), -1) + 1
                   FROM location_image
                  WHERE location_id = :location_id_pos"
            );
            $stmt->bindParam(':location_id'    , $id, \PDO::PARAM_INT);
            $stmt->bindParam(':location_id_pos', $id, \PDO::PARAM_INT);
            $stmt->bindParam(':file_name'      , $in_file_name);
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
                        location_image.location_id,
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
                    AND location.user_id           = :user_id"
            );

            $position = 0;
            foreach ($in_image_ids as $image_id) {
                $id = (int)$image_id;
                if ($id < 1) continue;
                $stmt->bindParam(':sort_order' , $position   , \PDO::PARAM_INT);
                $stmt->bindParam(':id'         , $id         , \PDO::PARAM_INT);
                $stmt->bindParam(':location_id', $location_id, \PDO::PARAM_INT);
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
