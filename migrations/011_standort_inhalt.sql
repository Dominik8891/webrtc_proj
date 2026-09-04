-- ===========================================================================
-- Migration 011: Ein Standort bekommt Inhalt - Titel, Beschreibung, Dauer,
--                Sprachen und Bilder
-- ===========================================================================
--
-- WOZU
--   Ein Standort bestand aus Land, Stadt, zwei Koordinaten und EINER Zeile
--   Freitext. Auf dieser Grundlage sollte ein Kunde entscheiden, ob er einen
--   Fremden losschickt, der ihn per Video durch eine Stadt fuehrt. Das
--   reicht nicht: Es fehlte alles, was eine solche Entscheidung traegt -
--   wovon die Fuehrung handelt, wie lange sie dauert, in welcher Sprache
--   gesprochen wird und wie es dort ueberhaupt aussieht.
--
-- WAS DAZUKOMMT
--   location.title             Ueberschrift des Angebots.
--   location.description_long  Die ausfuehrliche Beschreibung, mehrzeilig.
--   location.duration_minutes  Uebliche Dauer in Minuten.
--   location.languages         Sprachen des Guides als Kuerzelliste nach
--                              ISO 639-1, z. B. "de,en" (App\Helper\Languages).
--   location_image             Je Bild eine Zeile. Die DATEIEN liegen
--                              ausserhalb des Webroots und nicht hier.
--
-- WAS MIT DER BISHERIGEN BESCHREIBUNG PASSIERT
--   location.description BLEIBT UNVERAENDERT STEHEN und behaelt seine
--   Aufgabe: die eine Zeile, die im Kartenfenster und in der Standortliste
--   erscheint. Dort ist Kuerze richtig - ein Absatz in einem Kartenfenster
--   ist unlesbar. Aus dem Feld wird also nichts weggenommen; es bekommt nur
--   einen Namen fuer das, was es immer schon war: die Kurzbeschreibung.
--
--   UEBERFUEHRT wird der Bestand in den Titel: Jeder vorhandene Standort
--   bekommt seine bisherige Beschreibung, auf 120 Zeichen gekuerzt, als
--   Ueberschrift. Damit hat kein Standort nach dieser Migration eine leere
--   Seite, und es ist nichts erfunden - es steht dort genau das, was der
--   Guide selbst geschrieben hat.
--
--   Die ausfuehrliche Beschreibung bleibt bewusst LEER. Sie mit derselben
--   Zeile zu fuellen haette denselben Text dreimal auf die Seite gebracht;
--   dass ein Guide sie noch nicht geschrieben hat, ist die Wahrheit und wird
--   auf der Standortseite auch so gesagt.
--
-- WARUM DIE BILDER EINE EIGENE TABELLE BEKOMMEN
--   Weil es mehrere sind und ihre REIHENFOLGE eine Angabe ist, die der Guide
--   aendert. Fuenf Spalten bild1..bild5 waeren beim Umsortieren fuenf
--   Updates, beim Loeschen ein Nachruecken von Hand, und die konfigurierbare
--   Obergrenze (config/uploads.php) waere ein Schema-Aenderung statt einer
--   Zahl.
--
--   In der Zeile steht NUR der Dateiname, nicht der Pfad und nicht das Bild
--   selbst. Der Pfad kommt aus config/uploads.php und kann sich aendern,
--   ohne dass eine Zeile angefasst wird. Und ein BLOB in der Datenbank
--   haette jede Sicherung um Groessenordnungen aufgeblaeht, ohne dass die
--   Datenbank dafuer irgendetwas koennte, was das Dateisystem nicht besser
--   kann.
--
-- EIGENSCHAFTEN
--   * Idempotent: "ADD COLUMN IF NOT EXISTS" und "CREATE TABLE IF NOT
--     EXISTS" (MariaDB). Unter MySQL 8 gibt es "IF NOT EXISTS" bei ADD
--     COLUMN nicht - dort die ALTER-Zeilen ohne diesen Zusatz ausfuehren und
--     einen bereits vorhandenen Spaltennamen als erledigt betrachten.
--   * Kein Datenverlust: es kommen nur Spalten und eine Tabelle hinzu.
--     Keine bestehende Spalte wird geaendert, umbenannt oder geleert.
--   * Das UPDATE am Ende trifft nur Zeilen, deren Titel noch leer ist. Ein
--     zweiter Lauf ueberschreibt damit keinen Titel, den ein Guide inzwischen
--     selbst gesetzt hat.
--
-- NACH DEM EINSPIELEN
--   Das Ablageverzeichnis fuer die Bilder anlegen (siehe README, Abschnitt
--   "Bilder zu einem Standort"). Ohne es laesst sich kein Bild hochladen -
--   die uebrige Anwendung laeuft unveraendert weiter.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/011_standort_inhalt.sql
-- ===========================================================================

-- --------------------------------------------------------------------------
-- 1. Die neuen Felder am Standort
-- --------------------------------------------------------------------------

-- Ueberschrift des Angebots. Kurz genug, dass sie in einer Kachel und in
-- einem Kartenfenster ganz dasteht.
ALTER TABLE `location`
  ADD COLUMN IF NOT EXISTS `title` varchar(120) DEFAULT NULL AFTER `description`;

-- Die ausfuehrliche Beschreibung. `text` fasst rund 65000 Zeichen - mehr,
-- als jemand ueber einen Treffpunkt schreibt, und weniger, als eine Seite
-- unlesbar macht (die Laenge wird zusaetzlich im Controller begrenzt).
ALTER TABLE `location`
  ADD COLUMN IF NOT EXISTS `description_long` text DEFAULT NULL AFTER `title`;

-- Uebliche Dauer in Minuten. smallint unsigned reicht bis 65535 Minuten;
-- der Controller laesst 5 bis 480 zu. NULL heisst "nicht angegeben" und ist
-- etwas anderes als 0 - deshalb keine Vorgabe.
ALTER TABLE `location`
  ADD COLUMN IF NOT EXISTS `duration_minutes` smallint(5) unsigned DEFAULT NULL AFTER `description_long`;

-- Sprachen des Guides als Kuerzel nach ISO 639-1, kommagetrennt: "de,en".
-- Geschrieben wird ausschliesslich ueber App\Helper\Languages::normalize(),
-- die unbekannte Kuerzel verwirft und die Reihenfolge festlegt. 64 Zeichen
-- fassen ueber zwanzig Sprachen - mehr, als der Katalog kennt.
ALTER TABLE `location`
  ADD COLUMN IF NOT EXISTS `languages` varchar(64) DEFAULT NULL AFTER `duration_minutes`;

-- --------------------------------------------------------------------------
-- 2. Die Bilder
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `location_image` (
  `id` int(11) NOT NULL AUTO_INCREMENT,

  -- Standort, zu dem das Bild gehoert.
  `location_id` int(11) NOT NULL,

  -- Der Dateiname OHNE Pfad und OHNE Endung: 32 Hexzeichen, vergeben von
  -- App\Helper\ImageStore. Der Name, unter dem hochgeladen wurde, wird
  -- verworfen - er ist Fremdeingabe und wird nirgends gebraucht.
  -- Aus diesem Namen entstehen zwei Dateien: <name>.jpg (Vollansicht) und
  -- <name>_t.jpg (Vorschau).
  `file_name` varchar(32) NOT NULL,

  -- Reihenfolge in der Bildleiste, aufsteigend. Das erste Bild ist zugleich
  -- das Titelbild des Standorts.
  `sort_order` int(11) NOT NULL DEFAULT 0,

  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- Zusammengesetzter Index: Jede Abfrage holt die Bilder EINES Standorts in
  -- ihrer Reihenfolge - genau diese beiden Spalten, genau in dieser Folge.
  KEY `location_sort` (`location_id`, `sort_order`),

  -- Derselbe Dateiname darf nicht zweimal vorkommen. Er ist zufaellig, eine
  -- Kollision also praktisch ausgeschlossen - aber wenn sie doch eintraete,
  -- zeigte ein Standort das Bild eines anderen, und das faellt niemandem auf.
  UNIQUE KEY `file_name` (`file_name`),

  -- Wird der Standort geloescht, verschwinden seine Bildzeilen mit.
  -- ACHTUNG: Die DATEIEN nimmt das nicht mit - die Datenbank kennt das
  -- Dateisystem nicht. Sie loescht App\Helper\ImageStore::deleteLocationDir(),
  -- aufgerufen aus LocationController::deleteLocation(), VOR dem DELETE.
  CONSTRAINT `location_image_ibfk_1` FOREIGN KEY (`location_id`)
    REFERENCES `location` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------------------------
-- 3. Den Bestand ueberfuehren
--
-- Jeder vorhandene Standort bekommt seine bisherige Beschreibung als Titel.
-- Nur dort, wo noch keiner steht - ein zweiter Lauf aendert nichts mehr.
-- --------------------------------------------------------------------------
UPDATE `location`
   SET `title` = LEFT(TRIM(`description`), 120)
 WHERE (`title` IS NULL OR `title` = '')
   AND `description` IS NOT NULL
   AND TRIM(`description`) <> '';

-- --------------------------------------------------------------------------
-- Ergebnis zur Kontrolle
-- --------------------------------------------------------------------------
SELECT COUNT(*)                                   AS standorte,
       SUM(`title` IS NOT NULL AND `title` <> '') AS mit_titel,
       SUM(`description_long` IS NOT NULL)        AS mit_langtext,
       SUM(`duration_minutes` IS NOT NULL)        AS mit_dauer,
       SUM(`languages` IS NOT NULL)               AS mit_sprachen
  FROM `location`;
