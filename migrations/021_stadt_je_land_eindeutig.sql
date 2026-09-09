-- ===========================================================================
-- Migration 021: Eine Stadt je Land nur einmal
-- ===========================================================================
--
-- DER BEFUND
--   Auf `city` lag kein eindeutiger Index, und die Suche im Anwendungscode
--   (App\Model\Location::selectCity) fragte allein nach dem NAMEN:
--
--       SELECT * FROM city WHERE city_name = :city
--
--   Ein Stadtname ist aber nicht weltweit eindeutig. Valencia gibt es in
--   Spanien und in Venezuela, Toledo in Spanien und in den USA, Santiago
--   gleich mehrfach. Daraus folgten zwei Dinge:
--
--   1. DER STANDORT LANDETE IM FALSCHEN LAND. Wer nach dem spanischen
--      Valencia einen Standort im venezolanischen anlegte, bekam die
--      vorhandene Zeile zurueck - samt der country_id VON SPANIEN. Sein
--      Standort haengt seither an einem Land, in dem er nicht liegt. Still:
--      Die Seite zeigt einen Ort, die Liste sortiert ihn unter dem falschen
--      Land ein, eine Fehlermeldung gibt es nicht.
--
--   2. DERSELBE ORT UNTER ZWEI NAMEN. Die Ortsnamen kamen von Nominatim,
--      und das antwortete bis vor Kurzem in der Sprache des BROWSERS: Wer
--      einen Standort mit einem deutschen Browser anlegte, speicherte
--      "Lissabon", wer denselben Punkt mit einem englischen anlegte,
--      "Lisbon". Der Schreibpfad fragt jetzt fest auf Englisch
--      (assets/js/map.js, locationMap.NOMINATIM_SPRACHE) - fuer NEUE
--      Eintraege ist das erledigt.
--
-- WAS DIESE MIGRATION TUT UND WAS NICHT
--   Sie zieht den fehlenden Index nach: (city_name, country_id) eindeutig.
--   Ab da gilt die Regel in der Datenbank und nicht mehr nur im Code.
--
--   SIE FASST DEN BESTAND NICHT AN. Doppelte Namen zusammenzufuehren heisst,
--   Standorte auf eine andere Stadtzeile umzuhaengen und die leer gewordene
--   zu loeschen - das ist eine eigene Aufgabe mit einer eigenen Vorschau und
--   gehoert nicht in dieselbe Datei wie ein ALTER TABLE. Findet Schritt 1
--   etwas, bricht Schritt 2 ab und es wird NICHTS geaendert.
--
-- WARUM DER NAME VORNE STEHT
--   Die Reihenfolge (city_name, country_id) und nicht umgekehrt: So ist der
--   Index derselbe, nach dem selectCity() fragt - Name und Land. KEY
--   `country_id` bleibt daneben bestehen, weil der Fremdschluessel einen
--   Index braucht, der mit country_id ANFAENGT.
--
-- GROSS- UND KLEINSCHREIBUNG
--   Der Index vergleicht mit der Sortierung der Spalte. `city` steht auf
--   utf8mb4 ohne eigene Angabe, also gilt die des Servers, und die
--   unterscheidet nicht zwischen "Lisbon" und "lisbon". Schritt 1 gruppiert
--   deshalb ohne LOWER() - GROUP BY benutzt dieselbe Sortierung und findet
--   damit genau das, woran der Index sich stossen wuerde. Ein LOWER() waere
--   nicht genauer, sondern nur eine zweite Regel neben der ersten.
--
-- EIGENSCHAFTEN
--   * Idempotent: ein zweiter Lauf findet den Index vor und meldet das,
--     statt zu scheitern.
--   * Kein Datenverlust: es wird nichts geloescht und nichts umgeschrieben.
--   * Setzt MariaDB voraus (nutzt "IF NOT EXISTS" in ALTER TABLE).
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/021_stadt_je_land_eindeutig.sql
--
--   Die Datei laeuft in einem Zug: Schritt 1 gibt die Liste aus, Schritt 2
--   bricht danach ab, falls die Liste nicht leer war. Man bekommt also
--   beides in derselben Ausgabe - die Namen und die Absage.
-- ===========================================================================


-- ---------------------------------------------------------------------------
-- SCHRITT 1  (nur lesen)  Was wuerde den Index verhindern?
--
-- Gesucht sind Zeilen, die sich Name UND Land teilen - genau die Paare, die
-- der eindeutige Index nicht nebeneinander duldet.
--
-- IST DAS ERGEBNIS LEER, ist alles in Ordnung - Schritt 2 laeuft durch.
--
-- Ist es das nicht, steht in `kennungen`, welche Zeilen es betrifft, und in
-- `standorte`, wie viele Standorte an ihnen haengen. Das Zusammenfuehren ist
-- Handarbeit und in dieser Reihenfolge zu tun:
--
--     1. Eine Zeile aussuchen, die bleibt (im Zweifel die mit der
--        kleinsten id - sie ist die aelteste).
--     2. UPDATE `location` SET `city_id` = <bleibt> WHERE `city_id` IN (<rest>);
--     3. DELETE FROM `city` WHERE `id` IN (<rest>);
--     4. Diese Datei erneut ausfuehren.
--
-- Die loeschenden Befehle stehen hier absichtlich NICHT als ausfuehrbare
-- Zeile - so wie in Migration 002. Sie muessen bewusst eingegeben werden,
-- mit den Kennungen aus dieser Liste.
-- ---------------------------------------------------------------------------
SELECT c.`city_name`,
       co.`country_name`,
       co.`iso2`,
       COUNT(DISTINCT c.`id`)                      AS zeilen,
       GROUP_CONCAT(DISTINCT c.`id` ORDER BY c.`id`) AS kennungen,
       COUNT(l.`id`)                               AS standorte
  FROM `city`                c
  JOIN `country`             co ON co.`id` = c.`country_id`
  LEFT JOIN `location`       l  ON l.`city_id` = c.`id`
 GROUP BY c.`city_name`, c.`country_id`
HAVING COUNT(DISTINCT c.`id`) > 1
 ORDER BY co.`country_name`, c.`city_name`;


-- ---------------------------------------------------------------------------
-- SCHRITT 2  Schutzpruefung
--
-- Sie laeuft VOR jeder Aenderung. SIGNAL erzeugt einen Fehler, bei dem der
-- mariadb-Client die Ausfuehrung der Datei abbricht - der ALTER TABLE in
-- Schritt 3 wird dann gar nicht erst erreicht.
--
-- WARUM UEBERHAUPT, WO DOCH DER ALTER TABLE SELBST SCHEITERN WUERDE:
-- Er scheitert mit "Duplicate entry 'Valencia-42' for key 'city_name_country'"
-- und nennt damit EIN Paar. Was man braucht, ist die Liste aus Schritt 1 und
-- ein Satz, der sagt, was zu tun ist. Derselbe Grund wie in Migration 002.
--
-- DELIMITER ist eine Anweisung des mariadb-Kommandozeilenclients und noetig,
-- damit die Semikolons im Rumpf der Prozedur nicht vorzeitig ausgefuehrt
-- werden.
-- ---------------------------------------------------------------------------
DELIMITER $$

DROP PROCEDURE IF EXISTS `pruefe_voraussetzungen_021`$$

CREATE PROCEDURE `pruefe_voraussetzungen_021`()
BEGIN
    DECLARE paare INT DEFAULT 0;

    -- Wie viele (Name, Land)-Paare kommen mehr als einmal vor?
    SELECT COUNT(*) INTO paare
      FROM (SELECT 1
              FROM `city`
             GROUP BY `city_name`, `country_id`
            HAVING COUNT(*) > 1) AS doppelt;

    IF paare > 0 THEN
        SET @meldung = CONCAT(
            'Abbruch: ', paare,
            ' Stadt/Land-Paare kommen mehrfach vor. Die Liste steht in der ',
            'Ausgabe von Schritt 1. Es wurde nichts geaendert.'
        );
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @meldung;
    END IF;
END$$

DELIMITER ;

CALL `pruefe_voraussetzungen_021`();
DROP PROCEDURE `pruefe_voraussetzungen_021`;


-- ---------------------------------------------------------------------------
-- SCHRITT 3  Der Index
--
-- Ab hier ist sichergestellt, dass kein Paar zweimal vorkommt.
--
-- "IF NOT EXISTS" macht den Schritt bei einem zweiten Lauf zum No-Op - die
-- Datei ist damit wiederholbar, auch wenn der Index schon steht.
--
-- Der Index ist die letzte Verteidigungslinie hinter der Vorabfrage in
-- App\Model\Location::selectCity(). Zwischen dieser Frage und dem INSERT
-- liegt ein Fenster; legen zwei Guides im selben Augenblick einen Standort
-- in derselben Stadt an, sehen beide "gibt es noch nicht". Genau dafuer ist
-- er da - und genau deshalb behandelt Location::insertCityName() sein
-- Zuschlagen (SQLSTATE 23000) als Regelfall und benutzt die Zeile des
-- anderen, statt eine Fehlermeldung auszugeben.
-- ---------------------------------------------------------------------------
ALTER TABLE `city`
    ADD UNIQUE KEY IF NOT EXISTS `city_name_country` (`city_name`, `country_id`);


-- ---------------------------------------------------------------------------
-- Ergebnis zur Kontrolle
--
-- `zeilen` und `paare` muessen gleich sein - das ist die Aussage des Index,
-- noch einmal aus den Daten gelesen.
-- ---------------------------------------------------------------------------
SELECT COUNT(*)                                        AS zeilen,
       COUNT(DISTINCT `city_name`, `country_id`)       AS paare
  FROM `city`;
