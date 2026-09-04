-- ===========================================================================
-- Migration 012: Titelbild und Beispielbilder sind zweierlei
-- ===========================================================================
--
-- WOZU
--   Ein Bild musste bisher beides sein: Hintergrund der Kopfzeile UND
--   Beispielbild des Ortes. Das geht schlecht zusammen. Ein Titelbild braucht
--   ein sehr breites Format und ruhige Flaechen, auf denen die Schrift steht;
--   ein Beispielbild soll zeigen, was man dort sieht - eine Gasse, eine
--   Fassade, ein Detail. Das erste hochgeladene Bild wurde stillschweigend
--   zum Titelbild, ob es dafuer taugte oder nicht.
--
--   Ab jetzt hat jedes Bild eine ROLLE, und der Guide waehlt aus, welches
--   davon die Kopfzeile fuellt.
--
-- WAS DAZUKOMMT
--   location_image.role   'cover'   das eine Titelbild eines Standorts
--                         'gallery' Beispielbild in der Galerie darunter
--
-- WARUM EINE SPALTE UND KEINE ZWEITE TABELLE
--   Weil es dieselben Dateien sind, mit demselben Speicherweg, derselben
--   Pruefung und derselben Obergrenze. Zwei Tabellen haetten alles davon
--   verdoppelt - und die Obergrenze gilt ausdruecklich fuer die SUMME beider
--   Arten, also muesste sie ueber beide Tabellen zaehlen.
--
--   Ein Standort hat HOECHSTENS EIN Titelbild. Das laesst sich in MariaDB
--   nicht als Bedingung hinschreiben (ein Teilindex ueber "role = 'cover'"
--   fehlt), also setzt es App\Model\LocationImage::setCover() durch: Es nimmt
--   das bisherige Titelbild in derselben Transaktion zurueck in die Galerie.
--
-- WARUM 'gallery' DIE VORGABE IST
--   Weil ein hochgeladenes Bild erst einmal nur ein Bild ist. Zum Titelbild
--   wird es durch eine Entscheidung - entweder durch die des Guides oder,
--   solange es gar kein Titelbild gibt, durch die erste Ablage
--   (LocationController::uploadImage). Eine Vorgabe 'cover' haette das alte
--   Verhalten fortgeschrieben: das naechste Bild verdraengt das Titelbild.
--
-- WAS MIT DEM BESTAND PASSIERT
--   Jeder Standort, der noch kein Titelbild hat, bekommt sein ERSTES Bild als
--   Titelbild - genau das, was bis heute im Kopf stand. Damit sieht keine
--   Standortseite nach dem Einspielen anders aus als vorher, und der Guide
--   kann in Ruhe ein besseres auswaehlen.
--
-- EIGENSCHAFTEN
--   * Idempotent: "ADD COLUMN IF NOT EXISTS" (MariaDB), und das UPDATE fasst
--     nur Standorte an, die noch KEIN Titelbild haben. Ein zweiter Lauf
--     ueberschreibt also keine Wahl, die der Guide inzwischen getroffen hat.
--     Unter MySQL 8 gibt es "IF NOT EXISTS" bei ADD COLUMN nicht - dort die
--     ALTER-Zeile ohne diesen Zusatz ausfuehren und einen bereits vorhandenen
--     Spaltennamen als erledigt betrachten.
--   * Kein Datenverlust: es kommt eine Spalte hinzu, keine Datei wird
--     angefasst.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/012_titelbild.sql
-- ===========================================================================

-- --------------------------------------------------------------------------
-- 1. Die Rolle
-- --------------------------------------------------------------------------
ALTER TABLE `location_image`
  ADD COLUMN IF NOT EXISTS `role` varchar(16) NOT NULL DEFAULT 'gallery' AFTER `file_name`;

-- --------------------------------------------------------------------------
-- 2. Den Bestand ueberfuehren
--
-- Je Standort ohne Titelbild das erste Bild dazu machen. "Das erste" ist das
-- mit der kleinsten Reihenfolge; gibt es dort mehrere, das mit der kleinsten
-- Kennung - dieselbe Reihenfolge, in der die Seite sie bisher angezeigt hat.
--
-- Die verschachtelte Unterabfrage (SELECT ... FROM (SELECT ...) AS x) ist kein
-- Schmuck: MariaDB und MySQL lassen in einem UPDATE keine Unterabfrage auf
-- dieselbe Tabelle zu, die gerade geaendert wird. Die zusaetzliche Ebene legt
-- eine Zwischentabelle an und hebt die Einschraenkung damit auf.
-- --------------------------------------------------------------------------
UPDATE `location_image` AS li
  JOIN (
        SELECT MIN(a.id) AS id
          FROM `location_image` a
          JOIN (SELECT location_id, MIN(sort_order) AS kleinste
                  FROM `location_image`
                 GROUP BY location_id) b
            ON b.location_id = a.location_id
           AND b.kleinste    = a.sort_order
         WHERE a.location_id NOT IN (
               SELECT location_id FROM (
                     SELECT DISTINCT location_id
                       FROM `location_image`
                      WHERE `role` = 'cover'
               ) AS schon_gewaehlt)
         GROUP BY a.location_id
  ) AS erstes ON erstes.id = li.id
   SET li.`role` = 'cover';

-- --------------------------------------------------------------------------
-- Ergebnis zur Kontrolle
--
-- "mehr_als_eins" muss 0 sein: Ein Standort hat hoechstens ein Titelbild.
-- --------------------------------------------------------------------------
SELECT COUNT(*)                                AS bilder,
       SUM(`role` = 'cover')                   AS titelbilder,
       SUM(`role` = 'gallery')                 AS beispielbilder,
       (SELECT COUNT(*) FROM (
            SELECT location_id
              FROM `location_image`
             WHERE `role` = 'cover'
             GROUP BY location_id
            HAVING COUNT(*) > 1
        ) AS mehrfach)                         AS mehr_als_eins
  FROM `location_image`;
