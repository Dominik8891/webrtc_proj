-- ===========================================================================
-- Migration 016: Die Bewertung einer Fuehrung
-- ===========================================================================
--
-- WOZU
--   Ein Kunde soll einem Fremden Geld dafuer geben, dass der ihn per Video
--   durch eine unbekannte Stadt fuehrt. Bisher konnte er sich dabei nur auf
--   das stuetzen, was der Guide ueber sich selbst schreibt. Was ANDERE
--   Kunden erlebt haben, stand nirgends.
--
--   Diese Tabelle ist diese Auskunft: Der Kunde bewertet den Guide nach
--   einer Fuehrung mit Sternen und, wenn er mag, mit ein paar Saetzen.
--
-- NUR DIESE RICHTUNG
--   Kunden bewerten Guides. Guides bewerten keine Zuschauer. Es gibt deshalb
--   keine Spalte "wer bewertet wen" und keine Richtungsmarke, sondern zwei
--   Spalten mit festen Rollen: guide_user_id ist der Bewertete,
--   customer_user_id der Bewertende. Eine Zeile in die andere Richtung ist in
--   diesem Schema nicht darstellbar - das ist der Sinn.
--
-- DIE GRUNDLAGE IST DIE DURCHGEFUEHRTE FUEHRUNG
--   Bewertet wird nicht ein Guide, sondern EINE FUEHRUNG. request_id zeigt
--   auf die Zeile in `tour_request`, die belegt, dass sie stattgefunden hat
--   (status 'done', started_at gesetzt - migrations/013). Ohne eine solche
--   Zeile entsteht hier keine: Der Schreibvorgang ist ein INSERT ... SELECT
--   aus `tour_request` mit genau dieser Bedingung
--   (App\Model\TourReview::create), und nicht ein INSERT mit Werten, die der
--   Browser mitschickt.
--
--   Der eindeutige Schluessel auf request_id macht daraus die zweite Regel:
--   JE FUEHRUNG HOECHSTENS EINE BEWERTUNG. Sie steht in der Tabelle und nicht
--   nur im Controller - eine zweite Bewertung derselben Fuehrung ist damit
--   auch dann unmoeglich, wenn zwei Anfragen gleichzeitig ankommen.
--
-- WARUM guide_user_id UND location_id DANEBEN STEHEN
--   Beides liesse sich ueber request_id ermitteln. Beides steht trotzdem
--   hier, aus demselben Grund, aus dem guide_user_id in `tour_request` neben
--   location_id steht: Diese Zeile wird nach Guide und nach Standort
--   ABGEFRAGT - fuer den Durchschnitt auf dem Profil und fuer den auf der
--   Standortseite. Ein Join fuer jede dieser Abfragen waere ein Join zu viel,
--   und die Kennungen sind zum Zeitpunkt des Schreibens ohnehin bekannt.
--   Geschrieben werden sie AUS der Anfragezeile, nicht aus der Anfrage des
--   Browsers.
--
-- KEINE FREMDSCHLUESSEL
--   Dieselbe Ueberlegung wie bei `tour_request`: Eine abgegebene Bewertung
--   bleibt abgegeben, auch wenn der Standort spaeter geloescht wird. Mit
--   ON DELETE CASCADE waere sie beim ersten geloeschten Standort weg, mit
--   RESTRICT liesse sich ein Standort nie wieder loeschen. Gelesen wird
--   deshalb mit LEFT JOIN, und die Anzeige rechnet damit, dass der Titel
--   eines Standorts fehlen kann (App\Model\TourReview).
--
-- WIE EINE BESCHWERDE BEHANDELT WIRD
--   Der Guide kann eine Bewertung weder aendern noch loeschen - sonst waere
--   sie keine Auskunft mehr ueber ihn, sondern eine von ihm. Fuer den Fall
--   einer Beleidigung oder einer offensichtlich falschen Zuordnung entfernt
--   ein Admin sie (Recht review.remove).
--
--   ENTFERNT HEISST AUSGEBLENDET, NICHT GELOESCHT: removed_at wird gesetzt,
--   die Zeile bleibt stehen. Sie zaehlt danach nirgends mehr mit - nicht im
--   Durchschnitt, nicht in der Anzahl, in keiner Liste. Aber es bleibt
--   nachvollziehbar, dass es sie gab und wer sie entfernt hat; dasselbe
--   Muster wie beim Sperren eines Standorts (location.blocked), wo auch
--   nichts geloescht wird.
--
--   Der eindeutige Schluessel gilt weiter: Nach dem Entfernen kann derselbe
--   Kunde dieselbe Fuehrung NICHT erneut bewerten. Andernfalls waere das
--   Entfernen eine Einladung, dasselbe noch einmal zu schreiben.
--
-- DIE SKALA
--   Ganze Sterne von 1 bis 5. Halbe Sterne gibt es nur in der ANZEIGE eines
--   Durchschnitts, nie in einer Abgabe: Wer bewertet, waehlt einen von fuenf
--   Werten. Der erlaubte Bereich steht in App\Model\TourReview und wird beim
--   Schreiben geprueft; die Spalte ist bewusst ein tinyint und kein enum,
--   damit eine spaetere Aenderung der Skala keine Tabellenaenderung braucht.
--
-- EIGENSCHAFTEN
--   * Idempotent: CREATE TABLE IF NOT EXISTS. Ein zweiter Lauf aendert
--     nichts.
--   * Kein Datenverlust: Es kommt eine Tabelle hinzu, keine bestehende wird
--     angefasst.
--   * Der Bestand bekommt nichts: Vergangene Fuehrungen lassen sich nicht
--     nachtraeglich bewerten lassen - gefragt wird der Kunde nach dem
--     Auflegen, und das ist bei ihnen vorbei. Bewertbar sind sie trotzdem:
--     Jede Zeile in `tour_request` mit status 'done' kann ihr Kunde ueber die
--     Anfragenseite noch bewerten.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/016_bewertungen.sql
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `tour_review` (
  `id` int(11) NOT NULL AUTO_INCREMENT,

  -- Die bewertete FUEHRUNG. Ohne Fremdschluessel, siehe Kopf.
  `request_id` int(11) NOT NULL,

  -- Wer bewertet wurde und wer bewertet hat. Feste Rollen, siehe Kopf:
  -- Kunden bewerten Guides, nie umgekehrt.
  `guide_user_id` int(11) NOT NULL,
  `customer_user_id` int(11) NOT NULL,

  -- Der Standort, an dem die Fuehrung stattfand. Er traegt den Durchschnitt
  -- auf der Standortseite.
  `location_id` int(11) NOT NULL,

  -- Ganze Sterne, 1 bis 5. Der Bereich wird beim Schreiben geprueft
  -- (App\Model\TourReview::STARS_MIN / STARS_MAX).
  `stars` tinyint(4) NOT NULL,

  -- Der freiwillige Text. NULL heisst "nichts geschrieben" - und nicht der
  -- Leerstring: "nicht angegeben" soll genau eine Schreibweise haben, sonst
  -- muss jede Lesestelle beide kennen. Dieselbe Regel wie bei
  -- guide_profile.about.
  `body` text DEFAULT NULL,

  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Von der Moderation entfernt: wann, durch wen, mit welcher Begruendung.
  -- Alle drei NULL heisst "sichtbar" - und das ist der Regelfall, deshalb
  -- ist es der Vorgabewert. Gelesen wird ausschliesslich `removed_at IS NULL`
  -- (App\Model\TourReview::SICHTBAR).
  `removed_at` datetime DEFAULT NULL,
  `removed_by` int(11) DEFAULT NULL,
  `removed_reason` varchar(200) DEFAULT NULL,

  PRIMARY KEY (`id`),

  -- JE FUEHRUNG HOECHSTENS EINE BEWERTUNG. Die Regel steht hier und nicht
  -- nur im Controller: Zwei gleichzeitige Anfragen sollen nicht zwei Zeilen
  -- ergeben.
  UNIQUE KEY `eine_je_fuehrung` (`request_id`),

  -- Die drei Abfragen, die es wirklich gibt:
  --   was steht bei diesem Guide     (Profilseite, Durchschnitt und Liste),
  --   was steht an diesem Standort   (Standortseite),
  --   was habe ich als Kunde bewertet (die Anfragenseite fragt danach).
  --
  -- removed_at steht in beiden Anzeigeschluesseln VOR created_at, weil jede
  -- Anzeige zuerst danach filtert.
  KEY `guide_sichtbar` (`guide_user_id`, `removed_at`, `created_at`),
  KEY `standort_sichtbar` (`location_id`, `removed_at`, `created_at`),
  KEY `kunde` (`customer_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Ergebnis zur Kontrolle
--
-- Direkt nach dem Einspielen sind alle Zahlen 0. "bewertbar" sagt, wie viele
-- durchgefuehrte Fuehrungen es gibt, deren Kunde jetzt ueber die
-- Anfragenseite eine Bewertung nachtragen koennte.
-- ---------------------------------------------------------------------------
SELECT (SELECT COUNT(*) FROM `tour_review`)                       AS bewertungen,
       (SELECT COUNT(*) FROM `tour_review` WHERE `removed_at` IS NOT NULL)
                                                                  AS entfernt,
       (SELECT COUNT(*) FROM `tour_request`
         WHERE `status` = 'done' AND `started_at` IS NOT NULL)     AS bewertbar;
