-- ===========================================================================
-- Migration 014: Uebliche Zeiten eines Standorts - und seine Zeitzone
-- ===========================================================================
--
-- WOZU
--   Ein Kunde sah bisher nur, ob ein Guide GERADE bereit ist. War er es
--   nicht, blieb offen, ob sich eine Anfrage fuer spaeter ueberhaupt lohnt
--   oder ob der Guide nur sonntags kann. Die ueblichen Zeiten sind die
--   Antwort darauf - eine ORIENTIERUNG, kein Kalender: "donnerstags abends",
--   "am Wochenende vormittags".
--
--   Sie haengen am STANDORT und nicht am Konto: Derselbe Guide kann in der
--   Altstadt abends und am Hafen sonntags frueh unterwegs sein.
--
-- WAS DAZUKOMMT
--   location.availability_slots  das Raster, 28 Zeichen aus '0' und '1'
--   location.timezone            die Zeitzone des Ortes, z. B. 'Europe/Lisbon'
--
-- DAS RASTER
--   Sieben Wochentage mal vier Tagesabschnitte:
--     nachts 22-6, vormittags 6-12, nachmittags 12-18, abends 18-22.
--   Die Stelle im Text ist der Platz im Raster: Wochentag * 4 + Abschnitt,
--   Montag zuerst. Stelle 15 (0-basiert) ist also "Donnerstag abends".
--
--   Vier Abschnitte und nicht drei, weil es Nachtfuehrungen gibt - bei drei
--   fiele die Nacht hinten herunter. Die Grenzen stehen in
--   App\Helper\Availability und NUR dort; diese Beschreibung ist eine Kopie
--   fuer den Leser des Dumps, keine zweite Quelle.
--
-- WARUM EINE SPALTE UND KEINE EIGENE TABELLE
--   Es sind 28 Ja/Nein-Angaben, die immer vollstaendig gelesen und
--   vollstaendig geschrieben werden - zusammen mit dem Standort, auf dessen
--   Seite sie stehen. Eine Tabelle mit 28 Zeilen je Standort waere ein Join
--   fuer etwas, das in eine Spalte passt. Eine spaetere Suche ("wer kann
--   samstagabends?") bleibt moeglich: SUBSTRING(availability_slots, 24, 1).
--
-- WARUM DIE ZEITZONE AM STANDORT STEHT
--   Die Zeiten gelten AM ORT DER FUEHRUNG. Ein Kunde in Tokio, der einen
--   Standort in Lissabon ansieht, muss "donnerstags abends" als Lissabonner
--   Abend lesen - sonst verabreden sich beide auf verschiedene Uhrzeiten.
--
--   Gefuellt wird die Spalte beim Speichern des Standorts, abgeleitet aus
--   Land und Koordinaten (App\Helper\Availability::zoneFor) - mit
--   PHP-Bordmitteln, ohne neue Abhaengigkeit und ohne Netzaufruf. Der Guide
--   kann sie im Formular ueberschreiben.
--
--   NULL heisst "noch nicht bestimmt". Die Anwendung leitet die Zone dann
--   beim Lesen ab; geschrieben wird sie beim naechsten Speichern. Ein
--   Nachtragen per UPDATE ist deshalb nicht noetig - und waere hier auch
--   nicht moeglich, denn SQL kennt die Ableitung nicht.
--
-- EIGENSCHAFTEN
--   * Idempotent: "ADD COLUMN IF NOT EXISTS" (MariaDB). Unter MySQL 8 gibt es
--     das bei ADD COLUMN nicht - dort die ALTER-Zeilen ohne diesen Zusatz
--     ausfuehren und einen bereits vorhandenen Spaltennamen als erledigt
--     betrachten.
--   * Kein Datenverlust: es kommen zwei Spalten hinzu.
--   * Der Bestand bekommt NULL in beiden Spalten, also "keine Angabe". Das
--     ist die Wahrheit: Kein Guide hat bisher Zeiten eingetragen. Auf der
--     Standortseite steht dann nichts dazu, und eine Anfrage ist zu jedem
--     Zeitpunkt moeglich - wie bisher.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/014_verfuegbarkeitszeiten.sql
-- ===========================================================================

ALTER TABLE `location`
  ADD COLUMN IF NOT EXISTS `availability_slots` char(28) DEFAULT NULL AFTER `languages`;

ALTER TABLE `location`
  ADD COLUMN IF NOT EXISTS `timezone` varchar(64) DEFAULT NULL AFTER `availability_slots`;

-- ---------------------------------------------------------------------------
-- Ergebnis zur Kontrolle
--
-- Direkt nach dem Einspielen ist "mit_zeiten" 0 - die Guides tragen ihre
-- Zeiten selbst ein.
-- ---------------------------------------------------------------------------
SELECT COUNT(*)                                              AS standorte,
       SUM(`availability_slots` IS NOT NULL
           AND `availability_slots` LIKE '%1%')               AS mit_zeiten,
       SUM(`timezone` IS NOT NULL)                            AS mit_zeitzone
  FROM `location`;
