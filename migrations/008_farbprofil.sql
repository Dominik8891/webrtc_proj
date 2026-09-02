-- ===========================================================================
-- Migration 008: Farbprofil je Benutzer
-- ===========================================================================
--
-- Ergaenzt die Tabelle `user` um die Spalte fuer das gewaehlte Farbprofil.
--
-- WOZU
--   Die Oberflaeche kennt vier Farbprofile (Indigo, Himmelblau, Dunkel,
--   Neutral). Die Auswahl steht unter "Mein Konto" und soll beim naechsten
--   Login wieder da sein - sie gehoert also zum Konto und nicht in den
--   Browserspeicher.
--
--   Geschrieben wird ueber App\Model\User::saveTheme(), gelesen im
--   Konstruktor. Welche Werte gueltig sind, entscheidet
--   App\Helper\Theme::isValid() - nicht die Datenbank. Deshalb varchar und
--   kein ENUM: Ein weiteres Profil ist dann eine Zeile in Theme.php und
--   nicht wieder eine Migration.
--
-- EIGENSCHAFTEN
--   * Idempotent: nutzt "IF NOT EXISTS" in ALTER TABLE (MariaDB).
--     Unter MySQL 8 laeuft diese Datei NICHT - dort die ALTER-Zeile ohne
--     "IF NOT EXISTS" ausfuehren und einen bereits vorhandenen Spaltennamen
--     als erledigt betrachten.
--   * Kein Datenverlust: es kommt nur eine Spalte hinzu.
--   * NULL ist erlaubt und bedeutet "noch nichts gewaehlt". Diese Konten
--     bekommen die Vorgabe aus App\Helper\Theme::DEFAULT. Ein DEFAULT in
--     der Spalte waere eine zweite Stelle, an der die Vorgabe steht.
--
--   Wer diese Migration NICHT einspielt, verliert nur die Farbwahl: Die
--   Anwendung faellt auf das Standardprofil zurueck, und alle uebrigen
--   Aenderungen an einem Benutzer werden weiterhin gespeichert. Dafuer
--   schreibt User::saveTheme() mit einem eigenen Statement und haengt sich
--   nicht in User::update() ein.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/008_farbprofil.sql
-- ===========================================================================

ALTER TABLE `user`
  ADD COLUMN IF NOT EXISTS `theme` varchar(20) DEFAULT NULL;

-- Ergebnis zur Kontrolle
SELECT COUNT(*) AS konten, COUNT(theme) AS davon_mit_farbprofil FROM `user`;
