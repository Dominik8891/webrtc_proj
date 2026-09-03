-- ===========================================================================
-- Migration 009: Index fuer die Chat-Aufbewahrung
-- ===========================================================================
--
-- Ergaenzt die Tabelle `chat_message` um einen Index auf `sent_at`.
--
-- WOZU
--   cron/cleanup_chat_messages.php loescht Nachrichten, die aelter sind als
--   die Aufbewahrungsdauer aus config/chat_retention.php:
--
--       DELETE FROM chat_message WHERE sent_at < (NOW() - INTERVAL 30 DAY)
--
--   Ohne Index auf `sent_at` liest diese Abfrage bei jedem Lauf die
--   vollstaendige Tabelle. Solange dort ein paar hundert Zeilen stehen,
--   faellt das nicht auf; bei laufendem Betrieb waechst die Tabelle stetig,
--   der Lauf wird immer teurer, und er haelt dabei Sperren auf einer
--   Tabelle, in die gerade geschrieben wird. Mit dem Index findet der Lauf
--   die alten Zeilen direkt und ruehrt die aktuellen nicht an.
--
-- EIGENSCHAFTEN
--   * Idempotent: nutzt "IF NOT EXISTS" in CREATE INDEX (MariaDB 10.6+).
--     Unter MySQL 8 laeuft diese Datei NICHT - dort die Zeile ohne
--     "IF NOT EXISTS" ausfuehren und einen bereits vorhandenen Indexnamen
--     als erledigt betrachten.
--   * Kein Datenverlust: es kommt nur ein Index hinzu, keine Zeile wird
--     veraendert oder geloescht.
--
--   Wer diese Migration NICHT einspielt, verliert nichts an Funktion - die
--   Loeschung laeuft auch ohne Index, nur langsamer.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/009_chat_aufbewahrung.sql
-- ===========================================================================

CREATE INDEX IF NOT EXISTS `idx_chat_message_sent_at`
    ON `chat_message` (`sent_at`);

-- Ergebnis zur Kontrolle: aelteste und juengste Nachricht, sowie wie viele
-- Zeilen ein Lauf mit 30 Tagen Aufbewahrung heute entfernen wuerde.
SELECT COUNT(*)                                                        AS nachrichten,
       MIN(sent_at)                                                    AS aelteste,
       MAX(sent_at)                                                    AS juengste,
       SUM(sent_at < (NOW() - INTERVAL 30 DAY))                        AS aelter_als_30_tage
  FROM `chat_message`;
