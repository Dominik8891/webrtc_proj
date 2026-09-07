-- ===========================================================================
-- Migration 017: Der Guide beendet die Fuehrung ausdruecklich
-- ===========================================================================
--
-- DER BEFUND
--   Auflegen ist zweideutig. Es kann heissen "wir sind fertig" - oder "das
--   Netz ist weg". Bisher galt jedes Auflegen als das Ende: Der 'hangup'
--   setzte `status` auf 'done' und schrieb `ended_at`
--   (App\Controller\WebRTCController). Das hatte zwei Folgen, und beide waren
--   falsch:
--
--   1. DER STARTKNOPF BLIEB STEHEN. Anrufbar war eine Fuehrung im Zustand
--      'accepted' ODER 'done', solange das Zeitfenster um den Wunschzeitpunkt
--      lief (TourRequest::callableSql). Der Kunde konnte dieselbe Fuehrung
--      also nach dem Auflegen beliebig oft neu starten - zwei Stunden lang.
--   2. DIE BEWERTUNG WURDE SOFORT FAELLIG, obwohl die Fuehrung womoeglich nur
--      unterbrochen war. Der Kunde bekam die Frage, waehrend der Guide noch
--      auf dem Weg zurueck in die Leitung war.
--
--   Beides ist derselbe Fehler: Ein technisches Ereignis - der Abbau einer
--   Verbindung - wurde als fachliche Entscheidung gelesen.
--
-- WAS SICH AENDERT
--   Das Auflegen schreibt nur noch `ended_at` (den Zeitpunkt des letzten
--   Auflegens) und laesst den Zustand in Ruhe. BEENDET wird die Fuehrung vom
--   GUIDE, ausdruecklich (TourRequest::finish) - er ist die Seite, die weiss,
--   ob sie vorbei ist. Erst dann steht `status` auf 'done', erst dann
--   verschwindet der Startknopf, und erst dann wird die Bewertung faellig.
--
--   Bis dahin gilt die Fuehrung als UNTERBROCHEN, und beide Seiten koennen
--   wieder einsteigen - aber nicht unbegrenzt, sonst bliebe eine vergessene
--   Fuehrung fuer immer offen. Die Frist steht in config/requests.php
--   ('rejoin_window', eine halbe Stunde ab dem letzten Auflegen) und wird in
--   JEDER Abfrage ausgewertet (TourRequest::closedSql), nicht erst vom
--   Cronjob.
--
-- WAS DAZUKOMMT
--   tour_request.closed_at   Wann der Guide die Fuehrung beendet hat. NULL
--                            heisst: noch nicht beendet.
--
-- WARUM NEBEN ended_at UND NICHT STATT DESSEN
--   Weil es zwei verschiedene Zeitpunkte sind, und beide werden gebraucht:
--
--     ended_at   wann zuletzt aufgelegt wurde. Das ist das ehrliche Ende des
--                GESPRAECHS und die Grundlage einer spaeteren Abrechnung -
--                gerechnet wird, was geredet wurde.
--     closed_at  wann der Guide gesagt hat, dass es vorbei ist. Ein
--                Verwaltungsakt; er kann zehn Minuten spaeter kommen.
--
--   Eine Spalte fuer beides muesste sich fuer eine Bedeutung entscheiden -
--   und die andere waere still verloren. Dasselbe Muster wie bei
--   guide_profile.joined_at neben guide_since (migrations/015).
--
-- WARUM KEINE JA/NEIN-MARKE
--   Dieselbe Ueberlegung wie bei `expires_at` und `user.available_until`: Ein
--   ZEITPUNKT macht "beendet" allein aus der Zeile ablesbar und erlaubt es,
--   die Frist fuer den Wiedereinstieg daneben auszurechnen. Eine Marke
--   koennte nur sagen, DASS beendet wurde.
--
-- EIGENSCHAFTEN
--   * Idempotent: Die Spalte wird nur angelegt, wenn es sie nicht schon gibt
--     (siehe die Abfrage auf information_schema unten). MariaDB und MySQL
--     kennen kein "ADD COLUMN IF NOT EXISTS" in allen Fassungen; deshalb der
--     Umweg ueber ein vorbereitetes Statement.
--   * Kein Datenverlust: Es kommt eine Spalte hinzu, keine bestehende wird
--     angefasst.
--   * DER BESTAND: Alle vorhandenen Zeilen bekommen closed_at = NULL. Fuer
--     Fuehrungen, die schon auf 'done' stehen, aendert das nichts - 'done'
--     bleibt 'done' und bleibt bewertbar. Sie gelten damit als beendet, ohne
--     dass jemand nachtraeglich einen Zeitpunkt erfinden muesste.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/017_fuehrung_beenden.sql
-- ===========================================================================

SET @spalte_da := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tour_request'
       AND COLUMN_NAME  = 'closed_at'
);

SET @sql := IF(@spalte_da = 0,
    'ALTER TABLE `tour_request`
        ADD COLUMN `closed_at` datetime DEFAULT NULL AFTER `ended_at`',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Ergebnis zur Kontrolle
--
-- "offen" sind Fuehrungen, die begonnen haben und nicht beendet sind. Direkt
-- nach dem Einspielen sind das alle, die auf 'accepted' stehen und begonnen
-- haben - im Regelfall keine, denn bisher machte das Auflegen sie sofort zu
-- 'done'. Die Frist fuer den Wiedereinstieg raeumt sie ohnehin von selbst ab.
-- ---------------------------------------------------------------------------
SELECT COUNT(*)                                              AS fuehrungen,
       SUM(`status` = 'done')                                AS beendet,
       SUM(`status` = 'accepted' AND `started_at` IS NOT NULL
           AND `closed_at` IS NULL)                          AS offen
  FROM `tour_request`
 WHERE `started_at` IS NOT NULL;
