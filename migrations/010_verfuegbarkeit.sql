-- ===========================================================================
-- Migration 010: Bereitschaft des Guides ("verfuegbar") als eigener Zustand
-- ===========================================================================
--
-- Ergaenzt die Tabelle `user` um die Spalte `available_until`.
--
-- WOZU
--   Bisher war ein Guide "online", solange irgendein Tab der Anwendung offen
--   stand. Das war ein Nebeneffekt des Heartbeats
--   (App\Controller\UserController::heartbeat) und keine Entscheidung: Wer die
--   Seite ueber Nacht offen liess, stand am naechsten Morgen gruen auf der
--   Karte und wurde angerufen, ohne fuehren zu wollen.
--
--   Angemeldet und bereit sind seitdem ZWEI verschiedene Dinge:
--
--     user_status       Ist ein Browser dieses Kontos gerade erreichbar?
--                       Kommt weiterhin vom Heartbeat ('online', 'in_call')
--                       und vom Cronjob ('offline').
--     available_until   Bis wann hat sich der Guide ausdruecklich auf bereit
--                       gestellt? NULL oder ein Zeitpunkt in der
--                       Vergangenheit heisst "nicht bereit".
--
--   Gruen auf der Karte und anrufbar ist nur, wo BEIDES zutrifft. Die
--   Auswertung steht als eine Zeichenkette in App\Model\Location
--   (::AVAILABILITY_SQL) und wird von jeder Standortabfrage benutzt.
--
-- WARUM EIN ZEITPUNKT UND KEIN JA/NEIN
--   Die Bereitschaft laeuft nach einer konfigurierbaren Frist ohne Bedienung
--   ab (config/presence.php, 'availability_timeout', Vorgabe zwei Stunden).
--   Mit einem Ablaufzeitpunkt ist "abgelaufen" allein aus der Zeile ablesbar -
--   es braucht keinen Cronjob, damit ein Standort wieder grau wird. Ein
--   Ja/Nein-Feld haette einen zweiten Zeitstempel gebraucht und waere ohne
--   laufenden Cron dauerhaft auf "ja" stehen geblieben.
--
--   Verlaengert wird der Zeitpunkt vom Heartbeat, aber nur wenn der Browser
--   seit dem letzten Takt ECHTE Bedienung gemeldet hat (Klick, Taste,
--   Beruehrung) oder ein Gespraech laeuft. Ein offener Tab allein verlaengert
--   nichts - genau darum geht es.
--
-- WARUM KEIN INDEX
--   Die Spalte wird nie allein gefiltert, sondern immer an einer Zeile
--   mitgelesen, die ueber `location.user_id` ohnehin schon geholt wird. Der
--   Cronjob raeumt einmal pro Minute abgelaufene Werte auf und trifft dabei
--   die wenigen Zeilen, die nicht NULL sind.
--
-- EIGENSCHAFTEN
--   * Idempotent: nutzt "IF NOT EXISTS" in ALTER TABLE (MariaDB).
--     Unter MySQL 8 laeuft diese Datei NICHT - dort die ALTER-Zeile ohne
--     "IF NOT EXISTS" ausfuehren und einen bereits vorhandenen Spaltennamen
--     als erledigt betrachten.
--   * Kein Datenverlust: es kommt nur eine Spalte hinzu.
--   * Bestehende Konten bekommen NULL, also "nicht bereit". Das ist Absicht:
--     Die Bereitschaft ist eine Entscheidung, und die hat noch niemand
--     getroffen. Kein Guide steht nach dem Einspielen ungefragt auf bereit.
--     Er legt den Schalter in der Kopfleiste um, sobald er das erste Mal die
--     Seite oeffnet.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/010_verfuegbarkeit.sql
-- ===========================================================================

-- Bis wann steht dieses Konto auf "bereit"? NULL = nicht bereit.
ALTER TABLE `user`
  ADD COLUMN IF NOT EXISTS `available_until` datetime DEFAULT NULL AFTER `user_status`;

-- Ergebnis zur Kontrolle: Wie viele Konten stehen gerade auf bereit?
SELECT COUNT(*) AS konten,
       SUM(available_until IS NOT NULL AND available_until > NOW()) AS bereit
FROM `user`;
