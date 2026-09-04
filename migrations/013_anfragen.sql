-- ===========================================================================
-- Migration 013: Die Anfrage - und damit der erste Datensatz ueber Fuehrungen
-- ===========================================================================
--
-- WOZU
--   Bisher rief ein Kunde den Guide unmittelbar an. Das verlangte, dass beide
--   zufaellig im selben Moment koennen. Der Guide ist dabei die knappere
--   Seite: Er muss losgehen, sich Zeit nehmen, vielleicht hinfahren - und er
--   steht vielleicht gerade im Supermarkt.
--
--   Zwischen Wunsch und Fuehrung steht jetzt eine ANFRAGE: Der Kunde stellt
--   sie von der Standortseite aus mit einem Wunschzeitpunkt, der Guide nimmt
--   an oder lehnt ab. Erst danach wird angerufen - wie bisher, mit derselben
--   Rollenvergabe und derselben Standortkennung
--   (App\Controller\WebRTCController::callRoles).
--
--   "Jetzt sofort" ist dabei KEIN Sonderfall: Es ist der Wunschzeitpunkt
--   NOW(). Deshalb gibt es dafuer keine Spalte und keine Verzweigung.
--
-- WAS DIESE TABELLE AUSSERDEM LOEST
--   Es gab bislang KEINEN Datensatz ueber stattgefundene Fuehrungen. Ein
--   Anruf hinterliess ein paar Signalzeilen, die nach 15 Sekunden geloescht
--   wurden - danach war nicht mehr feststellbar, dass ueberhaupt eine
--   Fuehrung stattgefunden hat. Fuer Bewertungen und fuer eine spaetere
--   Abrechnung fehlt genau das. Die Anfrage ist dieser Datensatz: Sie traegt,
--   wer wen wann wo angefragt hat, ob es zustande kam und wie lange es
--   gedauert hat.
--
-- DIE ZUSTAENDE
--   'open'       offen        - gestellt, noch nicht beantwortet
--   'accepted'   angenommen   - der Guide hat zugesagt
--   'declined'   abgelehnt    - der Guide hat abgesagt
--   'expired'    abgelaufen   - unbeantwortet verstrichen ODER angenommen und
--                               das Zeitfenster ungenutzt vorbei
--   'done'       durchgefuehrt- die Fuehrung hat stattgefunden
--   'cancelled'  abgebrochen  - vom Kunden oder vom Guide zurueckgezogen
--
--   Sie stehen als Text und nicht als Zahl in der Spalte: In einem Dump soll
--   lesbar sein, was mit einer Anfrage passiert ist, ohne eine Codetabelle
--   danebenzulegen. Die erlaubten Werte stehen in App\Model\TourRequest.
--
-- WARUM ZWEI ZEITPUNKTE UND KEINE JA/NEIN-MARKE FUER "ABGELAUFEN"
--   Dieselbe Ueberlegung wie bei der Bereitschaft (Migration 010):
--   `expires_at` macht "abgelaufen" allein aus der Zeile ablesbar. Jede
--   Abfrage wertet den Vergleich mit NOW() selbst aus; der Cronjob ist
--   AUFRAEUMEN und keine Pruefung. Eine abgelaufene Anfrage wirkt also auch
--   dann nicht mehr, wenn der Job gar nicht eingerichtet ist.
--
--   Gerechnet wird `expires_at` beim Anlegen aus zwei Fristen
--   (config/requests.php), der fruehere Zeitpunkt gewinnt:
--     created_at + response_timeout   die Antwortfrist,
--     wish_at    + wish_grace         der verstrichene Wunschzeitpunkt.
--
-- WARUM KEINE FREMDSCHLUESSEL
--   Weil dieser Datensatz die Loeschung ueberleben muss, auf die er zeigt.
--   Eine durchgefuehrte Fuehrung bleibt geschehen, auch wenn der Guide
--   spaeter seinen Standort loescht oder ein Konto verschwindet - und genau
--   darauf sollen Bewertung und Abrechnung sich stuetzen koennen. Mit
--   ON DELETE CASCADE waere die Historie beim ersten geloeschten Standort
--   weg, mit RESTRICT liesse sich ein Standort nie wieder loeschen.
--
--   Dasselbe Muster wie bei `location`.`blocked_by`: kein Fremdschluessel,
--   weil die Angabe den Bezug ueberdauern soll. Wer die Zeilen liest, joint
--   mit LEFT JOIN und rechnet damit, dass Standort oder Konto fehlen koennen
--   (App\Model\TourRequest).
--
-- WARUM guide_user_id NEBEN location_id
--   Das waere ueber den Standort zu ermitteln - aber nur, solange es ihn
--   gibt. Der Guide ist die Seite, die annimmt, absagt und abgerechnet wird;
--   seine Kennung gehoert deshalb in die Zeile und nicht in einen Join, der
--   ins Leere laufen kann. Geschrieben wird sie beim Anlegen aus dem
--   Standort - der Kunde behauptet sie nicht (RequestController::create).
--
-- EIGENSCHAFTEN
--   * Idempotent: CREATE TABLE IF NOT EXISTS. Ein zweiter Lauf aendert
--     nichts.
--   * Kein Datenverlust: Es kommt eine Tabelle hinzu, keine bestehende wird
--     angefasst.
--   * Der Bestand bekommt nichts: Vergangene Fuehrungen sind nirgends
--     aufgezeichnet und lassen sich nicht nachtragen. Die Aufzeichnung
--     beginnt mit der ersten Anfrage nach dem Einspielen.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/013_anfragen.sql
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `tour_request` (
  `id` int(11) NOT NULL AUTO_INCREMENT,

  -- Der angefragte Standort. Ohne Fremdschluessel, siehe Kopf.
  `location_id` int(11) NOT NULL,

  -- Wer fuehren soll (Eigentuemer des Standorts zum Zeitpunkt der Anfrage)
  -- und wer fragt.
  `guide_user_id` int(11) NOT NULL,
  `customer_user_id` int(11) NOT NULL,

  -- Einer der sechs Zustaende aus dem Kopf dieser Datei.
  `status` varchar(16) NOT NULL DEFAULT 'open',

  -- Der Wunschzeitpunkt. "Jetzt sofort" ist hier der Zeitpunkt des Stellens.
  `wish_at` datetime NOT NULL,

  -- Ab wann eine OFFENE Anfrage nicht mehr gilt. Beim Anlegen aus den beiden
  -- Fristen in config/requests.php gerechnet, der fruehere gewinnt.
  `expires_at` datetime NOT NULL,

  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Wann der Guide geantwortet hat (angenommen oder abgelehnt), NULL solange
  -- die Anfrage offen ist oder unbeantwortet abgelaufen ist.
  `decided_at` datetime DEFAULT NULL,

  -- Beginn und Ende der tatsaechlichen Fuehrung. Gesetzt vom Signaling: der
  -- Beginn am Offer mit dieser Standortkennung, das Ende am 'hangup'
  -- (App\Controller\WebRTCController). Beide NULL heisst: es kam nie zum
  -- Gespraech. started_at gesetzt und ended_at NULL nach langer Zeit heisst:
  -- das Ende ist nie angekommen; der Cronjob schliesst solche Zeilen ab, ohne
  -- ein Ende zu erfinden.
  `started_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,

  PRIMARY KEY (`id`),

  -- Die drei Abfragen, die es wirklich gibt:
  --   was liegt bei diesem Guide an   (Liste und Zaehler der Kopfleiste),
  --   was habe ich als Kunde gestellt (Liste und Standortseite),
  --   was ist abgelaufen              (Cronjob).
  KEY `guide_status` (`guide_user_id`, `status`, `wish_at`),
  KEY `customer_status` (`customer_user_id`, `status`, `wish_at`),
  KEY `location_status` (`location_id`, `status`),
  KEY `ablauf` (`status`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Ergebnis zur Kontrolle
--
-- Direkt nach dem Einspielen sind alle Zahlen 0 - die Aufzeichnung beginnt
-- mit der ersten Anfrage.
-- ---------------------------------------------------------------------------
SELECT COUNT(*)                        AS anfragen,
       SUM(`status` = 'open')          AS offen,
       SUM(`status` = 'accepted')      AS angenommen,
       SUM(`status` = 'done')          AS durchgefuehrt
  FROM `tour_request`;
