-- ===========================================================================
-- Migration 019: Der Chat haengt an einem Standort - und die Einladung faellt weg
-- ===========================================================================
--
-- ZWEI BEFUNDE, EINE ANTWORT
--
--   1. DER CHAT WAR NICHT ERREICHBAR. Ein Chat liess sich ausschliesslich
--      ueber die Benutzerliste beginnen, und die hat nur noch der Admin
--      (Recht user.list). Fuer einen Kunden gab es damit keinen Weg zu
--      seinem Guide - obwohl genau dort die Fragen entstehen, die vor einer
--      Fuehrung zu klaeren sind: "Wo genau ist der Treffpunkt?", "Ginge auch
--      Samstag frueh?".
--
--   2. DER CHAT WAR AUF NICHTS EINGESCHRAENKT (Befund N-12). Die Route
--      chat_start nahm eine beliebige Kontokennung entgegen. Wer die Route
--      kannte, konnte jedem Konto der Plattform eine Nachricht ins Postfach
--      legen; die Kennungen sind fortlaufend, ein Durchzaehlen genuegte. Die
--      Ratengrenze aus Migration 018 war dagegen eine Obergrenze gegen die
--      Masse, keine Antwort auf die Frage, WER wen ueberhaupt anschreiben
--      darf.
--
--   Beides loest dieselbe Regel: EIN CHAT ENTSTEHT UEBER EINEN STANDORT.
--   Der Kunde schreibt den Guide von dessen Standortseite aus an; wen er
--   anschreibt, sagt der Standort und nicht die Anfrage. Damit ist der Chat
--   von der Standortseite aus erreichbar (Befund 1) und zugleich auf eine
--   Beziehung eingeschraenkt (Befund 2) - die freie Wahl des Gegenuebers
--   entfaellt.
--
--   Der Admin behaelt seinen Direktzugang. Er ist der einzige, der die
--   Benutzerliste sieht, und er braucht den Weg zu jedem Konto - dafuer gibt
--   es ein eigenes Recht (chat.start_direct) und eine eigene Route. Ein
--   solcher Chat gehoert zu keinem Standort und traegt deshalb NULL.
--
-- ---------------------------------------------------------------------------
-- WAS DIE SPALTE location_id BEDEUTET
--
--   Der Standort, UEBER DEN der Chat zustande gekommen ist - die Herkunft,
--   nicht das Thema. Sie steht in der Zeile, damit ohne Ratespiel
--   nachvollziehbar bleibt, warum diese beiden Konten miteinander reden
--   duerfen.
--
--   EIN CHAT JE PAAR, NICHT JE STANDORT. Bietet derselbe Guide drei
--   Standorte an und fragt derselbe Kunde zu allen dreien, bleibt es EIN
--   Gespraech - die Spalte traegt dann den Standort des Erstkontakts.
--   Andernfalls haette ein Kunde drei Fenster mit demselben Menschen und
--   muesste raten, in welchem er zuletzt geschrieben hat.
--
--   NULL heisst "ohne Standort" und kommt in zwei Faellen vor: ein Chat des
--   Admins ueber die Benutzerliste, und die Chats aus der Zeit vor dieser
--   Migration. Die bleiben lesbar - eine Nachricht, die jemand geschrieben
--   hat, wird nicht dadurch ungeschehen, dass die Regel sich aendert.
--
--   ON DELETE SET NULL und nicht CASCADE: Loescht ein Guide seinen Standort,
--   verschwindet die Herkunft, aber nicht das Gespraech. Nachrichten sind
--   Inhalt der beiden Beteiligten und nicht Beiwerk des Standorts.
--
-- ---------------------------------------------------------------------------
-- WARUM is_active UND pending_for VERSCHWINDEN
--
--   Sie trugen eine EINLADUNG: Der Angeschriebene bekam "X moechte mit Ihnen
--   chatten" und musste annehmen oder ablehnen; erst danach gab es ein
--   Eingabefeld. Diese Mechanik stammt aus einer Anwendung, in der jeder
--   jeden anschreiben konnte - dort ist sie der Schutz vor Fremden.
--
--   HIER GIBT ES DIESEN FREMDEN NICHT MEHR. Ein Chat entsteht nur noch
--   zwischen einem Kunden und dem Guide eines Standorts, den dieser Guide
--   selbst oeffentlich angeboten hat. Wer Standorte anbietet, will
--   Rueckfragen bekommen.
--
--   SIE BLOCKIERTE AUSGERECHNET DAS, WAS SIE SCHUETZEN SOLLTE: Der Kunde
--   konnte erst schreiben, NACHDEM der Guide angenommen hatte. Der Guide
--   entschied also ueber einen blossen Namen, ohne zu wissen, worum es geht.
--   Eine Frage mit Inhalt ("Geht Samstag 14 Uhr?") laesst sich beurteilen,
--   ein Name allein nicht.
--
--   SIE WAR AUCH KEINE SPERRE: Chat::findOrCreate() reaktivierte einen
--   abgelehnten Chat beim naechsten Aufruf. "Ablehnen" kostete den anderen
--   einen Klick und sonst nichts. Eine echte Sperre gehoert auf die Ebene
--   "dieser Kunde nicht mehr" und nicht auf die erste Nachricht; sie gibt es
--   heute nicht und wird durch diese Migration auch nicht schlechter.
--
--   Und sie war die einzige Stelle der Anwendung, die so arbeitet: Eine
--   ANFRAGE (Migration 013) traegt einen Wunschzeitpunkt, wenn der Guide
--   ueber sie entscheidet. Die Chateinladung war dasselbe Gespraech ohne den
--   Inhalt.
--
--   MIT DEN SPALTEN ENTFALLEN: die Routen chat_accept und chat_decline, das
--   Recht chat.answer und die Methoden Chat::setActive(),
--   Chat::checkIfActive() und Chat::getInvitations().
--
-- WAS AUS DEM BESTAND WIRD
--   Jede vorhandene Zeile wird zu einem gewoehnlichen Chat. Eine Einladung,
--   die noch offen war, ist damit ein offenes Gespraech - der Angeschriebene
--   sieht sie ab jetzt im Zaehler der Kopfleiste statt in einem Fenster mit
--   zwei Knoepfen. Nichts geht verloren.
--
-- WARUM deleted BLEIBT
--   Es ist etwas anderes als die Einladung: "beendet/weggeraeumt" gegenueber
--   "noch nicht angenommen". Der Verlauf einer beendeten Unterhaltung steht
--   weiterhin unter "Alle Chats" (ChatController::getAllChats).
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/019_standort_chat.sql
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- 1. Die Herkunft: der Standort, ueber den der Chat entstanden ist.
--
-- Wie in den vorangegangenen Migrationen wird zuerst gefragt, ob die Spalte
-- schon da ist: Das Skript soll sich ein zweites Mal einspielen lassen, ohne
-- mit einem Fehler abzubrechen.
-- ---------------------------------------------------------------------------
SET @spalte_da := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'chat'
       AND COLUMN_NAME  = 'location_id'
);

SET @sql := IF(@spalte_da = 0,
    'ALTER TABLE `chat`
        ADD COLUMN `location_id` int(11) DEFAULT NULL AFTER `user2_id`,
        ADD KEY `chat_standort` (`location_id`),
        ADD CONSTRAINT `chat_ibfk_3` FOREIGN KEY (`location_id`)
            REFERENCES `location` (`id`) ON DELETE SET NULL',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. Die Einladung faellt weg.
--
-- Zwei getrennte Abfragen und zwei getrennte ALTER: Eine Datenbank, in der
-- nur eine der beiden Spalten steht (halb eingespieltes Skript), soll sich
-- ebenfalls aufraeumen lassen.
-- ---------------------------------------------------------------------------
SET @aktiv_da := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'chat'
       AND COLUMN_NAME  = 'is_active'
);

SET @sql := IF(@aktiv_da = 1,
    'ALTER TABLE `chat` DROP COLUMN `is_active`',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @pending_da := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'chat'
       AND COLUMN_NAME  = 'pending_for'
);

SET @sql := IF(@pending_da = 1,
    'ALTER TABLE `chat` DROP COLUMN `pending_for`',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. Der Zaehler der Kopfleiste liest ungelesene Nachrichten je Empfaenger.
--
-- Die Abfrage lautet "alles, was in DIESEM Chat nicht von MIR ist und noch
-- nicht gesehen wurde" (App\Model\ChatMessage::countUnseenForUser und
-- ::countUnseenTotal). Der vorhandene Schluessel auf chat_id allein zwingt
-- dafuer zum Nachlesen jeder Zeile; mit sender_id und seen daneben beantwortet
-- der Index die Frage selbst.
--
-- Er wird gebraucht, weil diese Zaehlung ab jetzt bei JEDEM Heartbeat laeuft -
-- alle zehn Sekunden je angemeldetem Konto - und nicht mehr nur beim Oeffnen
-- der Chatliste.
-- ---------------------------------------------------------------------------
SET @index_da := (
    SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'chat_message'
       AND INDEX_NAME   = 'ungelesen'
);

SET @sql := IF(@index_da = 0,
    'ALTER TABLE `chat_message`
        ADD KEY `ungelesen` (`chat_id`, `seen`, `sender_id`)',
    'DO 0'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- Ergebnis zur Kontrolle
--
-- "ohne_standort" sind die Chats aus der Zeit vor dieser Migration plus die
-- Direktchats des Admins. Direkt nach dem Einspielen ist das die Gesamtzahl:
-- Eine Herkunft laesst sich nachtraeglich nicht erfinden. Die Zahl waechst
-- danach nicht mehr nennenswert - jeder neue Chat eines Kunden bringt seinen
-- Standort mit.
-- ---------------------------------------------------------------------------
SELECT COUNT(*)                             AS chats,
       SUM(`location_id` IS NULL)           AS ohne_standort,
       SUM(`location_id` IS NOT NULL)       AS ueber_standort,
       SUM(`deleted` = 1)                   AS beendet
  FROM `chat`;
