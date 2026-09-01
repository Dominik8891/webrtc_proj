-- ===========================================================================
-- Migration 003: Spalte country.iso2 ergaenzen
-- ===========================================================================
--
-- Ergaenzt den ISO-3166-1-alpha-2-Code in der Tabelle country.
--
-- WARUM
--   Das Frontend erwartet das Feld an drei Stellen (assets/js/map.js):
--     Zeile  78  filtert die vom Server gelieferte Laenderliste gegen
--                allowedCountryCodes. Fehlt iso2, ist die Bedingung fuer
--                JEDE Zeile falsch und der Laender-Dropdown bleibt leer -
--                selbst bei vollstaendig gefuellter Tabelle.
--     Zeile 184  Parameter countrycodes= der Staedtesuche bei Nominatim.
--     Zeile 115  Flaggengrafik von flagcdn.com/24x18/<iso2>.png
--
-- REIHENFOLGE
--   Diese Migration NUR die Spalte an. Die Stammdaten kommen danach mit
--   migrations/004_country_seed.sql.
--
-- EIGENSCHAFTEN
--   * Idempotent, ein zweiter Lauf findet nichts mehr zu tun.
--   * Kein Datenverlust: es wird nichts geloescht.
--   * Setzt MariaDB voraus (nutzt "IF NOT EXISTS" in ALTER TABLE).
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/003_country_iso2.sql
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- Schutzpruefung
--
-- Die Spalte wird als NOT NULL ohne Default angelegt. Enthaelt die Tabelle
-- bereits Zeilen, laesst sich das nicht sinnvoll nachtragen - fuer einen
-- bestehenden Laendernamen kann kein Code erraten werden. In dem Fall bricht
-- die Migration ab, bevor sie irgendetwas aendert.
--
-- Der Normalfall ist eine leere Tabelle: country hat im Anwendungscode
-- keinen Schreibpfad, es gibt weder INSERT noch UPDATE.
--
-- DELIMITER ist eine Anweisung des mariadb-Kommandozeilenclients und noetig,
-- damit die Semikolons im Rumpf der Prozedur nicht vorzeitig ausgefuehrt werden.
-- ---------------------------------------------------------------------------
DELIMITER $$

DROP PROCEDURE IF EXISTS `pruefe_voraussetzungen_003`$$

CREATE PROCEDURE `pruefe_voraussetzungen_003`()
BEGIN
    DECLARE spalte_da INT DEFAULT 0;
    DECLARE anzahl    INT DEFAULT 0;

    SELECT COUNT(*) INTO spalte_da
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'country'
      AND COLUMN_NAME  = 'iso2';

    -- Nur pruefen, solange die Spalte noch fehlt. Bei einem zweiten Lauf
    -- ist sie vorhanden und die Pruefung entfaellt.
    IF spalte_da = 0 THEN
        SELECT COUNT(*) INTO anzahl FROM `country`;
        IF anzahl > 0 THEN
            SET @meldung = CONCAT(
                'Abbruch: country enthaelt bereits ', anzahl,
                ' Zeile(n) ohne iso2. Bitte Tabelle leeren oder iso2 manuell ergaenzen.'
            );
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @meldung;
        END IF;
    END IF;
END$$

DELIMITER ;

CALL `pruefe_voraussetzungen_003`();
DROP PROCEDURE `pruefe_voraussetzungen_003`;

-- ---------------------------------------------------------------------------
-- Spalte und eindeutigen Index anlegen
--
-- HINWEIS ZUR TRANSAKTION: DDL loest in MariaDB ein implizites COMMIT aus,
-- die Klammer schuetzt also nicht vor einem Teilzustand. Beide Schritte sind
-- aber idempotent und koennen wiederholt werden.
--
-- UNIQUE auf iso2 ist Voraussetzung dafuer, dass Migration 004 die
-- Stammdaten per INSERT IGNORE wiederholbar einspielen kann.
-- ---------------------------------------------------------------------------
START TRANSACTION;

ALTER TABLE `country`
    ADD COLUMN     IF NOT EXISTS `iso2` char(2) NOT NULL AFTER `country_name`,
    ADD UNIQUE KEY IF NOT EXISTS `iso2` (`iso2`);

COMMIT;

SELECT 'country.iso2 angelegt. Weiter mit migrations/004_country_seed.sql.' AS `Ergebnis`;
