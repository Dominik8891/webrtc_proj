-- ===========================================================================
-- Migration 006: Standorte sperrbar machen
-- ===========================================================================
--
-- Ergaenzt die Tabelle `location` um die Spalten fuer die Moderation.
--
-- WOZU
--   Ein Admin soll einen unpassenden Standort aus der Uebersicht nehmen
--   koennen, ohne ihn zu loeschen. Loeschen bleibt dem Eigentuemer
--   vorbehalten - der Guide behaelt seinen Datensatz und sieht in seiner
--   eigenen Standortliste, dass und warum der Standort gesperrt ist.
--
--   Gelesen und geschrieben wird ueber App\Model\Location::block(),
--   ::unblock() und die beiden SELECTs; wer sperren darf, entscheidet das
--   Recht location.block (class/Helper/Permission.php).
--
-- EIGENSCHAFTEN
--   * Idempotent: nutzt "IF NOT EXISTS" in ALTER TABLE (MariaDB).
--     Unter MySQL 8 laeuft diese Datei NICHT - dort die ALTER-Zeilen ohne
--     "IF NOT EXISTS" ausfuehren und einen bereits vorhandenen Spaltennamen
--     als erledigt betrachten.
--   * Kein Datenverlust: es kommen nur Spalten hinzu. Bestehende Standorte
--     sind danach nicht gesperrt (blocked = 0).
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/006_location_sperre.sql
-- ===========================================================================

-- Sperrkennzeichen. NOT NULL mit Vorgabe 0, damit die Bedingung
-- "AND location.blocked = 0" in der Uebersicht keine Zeile wegen NULL
-- verliert.
ALTER TABLE `location`
  ADD COLUMN IF NOT EXISTS `blocked` tinyint(1) NOT NULL DEFAULT 0;

-- Grund der Sperre. Genau dieser Text wird dem Guide angezeigt.
ALTER TABLE `location`
  ADD COLUMN IF NOT EXISTS `blocked_reason` varchar(255) DEFAULT NULL;

-- Wer gesperrt hat. Bewusst OHNE Fremdschluessel auf user(id): Wird das Konto
-- des Moderators spaeter geloescht, soll die Sperre bestehen bleiben.
ALTER TABLE `location`
  ADD COLUMN IF NOT EXISTS `blocked_by` int(11) DEFAULT NULL;

ALTER TABLE `location`
  ADD COLUMN IF NOT EXISTS `blocked_at` datetime DEFAULT NULL;

-- Index: Die Standortuebersicht filtert bei jedem Aufruf ueber diese Spalte.
ALTER TABLE `location`
  ADD INDEX IF NOT EXISTS `blocked` (`blocked`);

-- Ergebnis zur Kontrolle
SELECT COUNT(*) AS standorte, SUM(blocked) AS davon_gesperrt FROM `location`;
