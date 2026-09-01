-- ===========================================================================
-- Migration 002: location.user_id auf Pflichtfeld setzen
-- ===========================================================================
--
-- Setzt location.user_id auf NOT NULL und legt den Foreign Key auf user(id)
-- an. Erst danach entspricht das Schema vollstaendig der database.sql.
--
-- VORAUSSETZUNG
--   Migration 001 muss gelaufen sein UND es darf keine Standorte mehr ohne
--   Guide-Zuordnung geben. Ist noch mindestens ein solcher Datensatz
--   vorhanden, bricht diese Datei mit einer Fehlermeldung ab und aendert
--   NICHTS.
--
-- WARUM EIN MANUELLER ZWISCHENSCHRITT NOETIG IST
--   Die Spalte location.user_id hat in frueheren Versionen nie existiert.
--   Es gibt daher keine Datenquelle, aus der sich rekonstruieren liesse,
--   welcher Guide welchen Standort angelegt hat. Eine automatische
--   Zuordnung waere geraten und wuerde falsche Daten erzeugen.
--
-- WAS ZU TUN IST, WENN 001 VERWAISTE STANDORTE MELDET
--   Diese Datei enthaelt bewusst KEINEN loeschenden Befehl - auch keinen
--   auskommentierten. Es gibt zwei Wege, und die Entscheidung liegt beim
--   Betreiber:
--
--   a) Zuordnen (Daten bleiben erhalten)
--      Die betroffenen Zeilen ansehen und einzeln dem richtigen Guide
--      zuweisen. Zum Ansehen:
--          SELECT l.id, c.city_name, l.description
--          FROM location l LEFT JOIN city c ON l.city_id = c.id
--          WHERE l.user_id IS NULL;
--      Danach je Zeile ein UPDATE mit der passenden User-ID setzen.
--
--   b) Entfernen (Daten gehen verloren)
--      Wenn die Standorte nicht mehr zuzuordnen oder nicht mehr relevant
--      sind, koennen sie geloescht werden. Der dafuer noetige Befehl lautet
--      DELETE FROM location mit der Bedingung user_id IS NULL. Er ist hier
--      absichtlich nicht als ausfuehrbare Zeile hinterlegt, damit er nicht
--      versehentlich mitlaeuft - er muss bewusst von Hand eingegeben werden.
--
-- EIGENSCHAFTEN
--   * Idempotent: ein zweiter Lauf findet nichts mehr zu tun und meldet
--     keinen Fehler.
--   * Setzt MariaDB voraus (nutzt "IF NOT EXISTS" / "IF EXISTS").
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/002_location_user_id_pflicht.sql
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- Schutzpruefung
-- Bricht ab, wenn Migration 001 fehlt oder noch verwaiste Standorte
-- existieren. Die Pruefung laeuft VOR jeder Aenderung: SIGNAL erzeugt einen
-- Fehler, bei dem der mariadb-Client die Ausfuehrung der Datei abbricht, sodass
-- die nachfolgenden ALTER TABLE gar nicht erst erreicht werden.
-- DELIMITER ist eine Anweisung des mariadb-Kommandozeilenclients und noetig,
-- damit die Semikolons im Rumpf der Prozedur nicht vorzeitig ausgefuehrt werden.
-- ---------------------------------------------------------------------------
DELIMITER $$

DROP PROCEDURE IF EXISTS `pruefe_voraussetzungen_002`$$

CREATE PROCEDURE `pruefe_voraussetzungen_002`()
BEGIN
    DECLARE spalte_da INT DEFAULT 0;
    DECLARE verwaist   INT DEFAULT 0;

    -- Gibt es die Spalte ueberhaupt schon?
    SELECT COUNT(*) INTO spalte_da
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'location'
      AND COLUMN_NAME  = 'user_id';

    IF spalte_da = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Abbruch: Spalte location.user_id fehlt. Bitte zuerst Migration 001 ausfuehren.';
    END IF;

    -- Sind noch Standorte ohne Zuordnung vorhanden?
    SELECT COUNT(*) INTO verwaist FROM `location` WHERE `user_id` IS NULL;

    IF verwaist > 0 THEN
        SET @meldung = CONCAT(
            'Abbruch: ', verwaist,
            ' Standort(e) ohne Guide-Zuordnung. Siehe Kopf von 002. Es wurde nichts geaendert.'
        );
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @meldung;
    END IF;
END$$

DELIMITER ;

CALL `pruefe_voraussetzungen_002`();
DROP PROCEDURE `pruefe_voraussetzungen_002`;

-- ---------------------------------------------------------------------------
-- Ab hier ist sichergestellt, dass jede location-Zeile einen Guide hat.
-- ---------------------------------------------------------------------------
-- HINWEIS ZUR TRANSAKTION: wie in 001 loesen die folgenden DDL-Befehle ein
-- implizites COMMIT aus. Die Klammer schuetzt nicht vor einem Teilzustand,
-- beide Schritte sind aber idempotent und koennen wiederholt werden.
START TRANSACTION;

-- Spalte auf Pflichtfeld umstellen.
-- "IF EXISTS" macht den Schritt bei einem zweiten Lauf zum No-Op.
ALTER TABLE `location`
    MODIFY COLUMN IF EXISTS `user_id` int(11) NOT NULL;

-- Foreign Key nachziehen. Wird ein Nutzer geloescht, verschwinden seine
-- Standorte mit - identisch zu database.sql.
ALTER TABLE `location`
    ADD CONSTRAINT IF NOT EXISTS `location_ibfk_2`
        FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE;

COMMIT;

-- ---------------------------------------------------------------------------
-- Bestaetigung
-- ---------------------------------------------------------------------------
SELECT 'location.user_id ist jetzt NOT NULL und per Foreign Key an user(id) gebunden.'
    AS `Ergebnis`;
