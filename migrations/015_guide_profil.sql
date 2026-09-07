-- ===========================================================================
-- Migration 015: Aus dem Guide-Profil wird ein Profil
-- ===========================================================================
--
-- WOZU
--   Ein Kunde soll einem Fremden Geld dafuer geben, dass der ihn per Video
--   durch eine unbekannte Stadt fuehrt. Gesehen hat er von diesem Menschen
--   bisher: einen Benutzernamen und einen farbigen Punkt. Das ist keine
--   Grundlage fuer Vertrauen - es ist nicht einmal eine Person.
--
--   Die Tabelle `guide_profile` gibt es seit migrations/007. Sie hielt bisher
--   ausschliesslich die Zustimmung zur Guide-Rolle fest (wer, wann, zu
--   welcher Fassung der Bedingungen). Sie bekommt jetzt das, was ihr Name
--   verspricht: ein Profil.
--
-- WAS DAZUKOMMT
--   guide_profile.display_name  Der Name, unter dem der Guide seinen Kunden
--                               erscheint. Er ERSETZT den Benutzernamen
--                               ueberall dort, wo Kunden ihn sehen; der
--                               Benutzername bleibt, was er war - die
--                               Anmeldekennung, intern.
--   guide_profile.about         Kurze Selbstbeschreibung, mehrzeilig.
--   guide_profile.languages     Sprachen des GUIDES als Kuerzelliste nach
--                               ISO 639-1 (App\Helper\Languages), z. B.
--                               "de,en".
--   guide_profile.avatar_file   Basisname der Bilddatei, 32 Hexzeichen -
--                               dieselbe Form wie location_image.file_name.
--                               Die DATEI liegt ausserhalb des Webroots und
--                               nicht hier (siehe unten).
--   guide_profile.joined_at     Seit wann dieses Konto Guide ist.
--
-- WARUM joined_at NEBEN guide_since STEHT
--   Weil guide_since etwas anderes bedeutet, als sein Name vermuten laesst:
--   App\Model\GuideRole::rememberAcceptance() setzt es bei JEDER Zustimmung
--   neu - auch dann, wenn ein langjaehriger Guide bloss eine neue Fassung
--   der Bedingungen bestaetigt. Das ist fuer seinen Zweck richtig (es ist der
--   Beginn des Zeitraums, den eine spaetere Abrechnung betrachtet), als
--   Angabe "Guide seit" auf einer Kundenseite waere es eine Luege: Am Tag
--   eines Bedingungswechsels waeren alle Guides neu.
--
--   joined_at wird deshalb genau EINMAL gesetzt - beim ersten Annehmen der
--   Rolle - und danach nie wieder angefasst (COALESCE im ON DUPLICATE KEY
--   UPDATE, siehe GuideRole::rememberAcceptance). Wer die Rolle zurueckgibt
--   und spaeter erneut annimmt, behaelt sein urspruengliches Datum: Er war
--   ja wirklich schon einmal da.
--
-- WARUM DER SPRACHEN ZWEIMAL GIBT
--   location.languages (migrations/011) sagt, in welchen Sprachen DIESE
--   FUEHRUNG stattfindet. guide_profile.languages sagt, welche Sprachen der
--   MENSCH spricht. Das ist nicht dasselbe: Ein Guide, der vier Sprachen
--   kann, bietet eine bestimmte Fuehrung vielleicht nur auf zweien an. Die
--   Standortseite zeigt weiterhin die des Standorts; die Profilseite zeigt
--   die der Person.
--
-- WO DAS AVATARBILD LIEGT
--   NICHT in dieser Tabelle. Hier steht nur der Basisname; die Datei liegt
--   unter <UPLOAD_PATH>/guides/<user_id>/ und damit ausserhalb des Document
--   Root - aus demselben Grund wie die Standortbilder (config/uploads.php).
--   Ausgeliefert wird sie ueber index.php?act=guide_avatar, also durch einen
--   Controller. Ein BLOB haette jede Sicherung aufgeblaeht, ohne dass die
--   Datenbank dafuer irgendetwas koennte, was das Dateisystem nicht besser
--   kann.
--
--   EIN BILD JE GUIDE, deshalb eine Spalte und keine Tabelle: Es gibt keine
--   Reihenfolge, keine Auswahl und nichts zu sortieren. Wird ein neues
--   hochgeladen, ersetzt es das alte - und die alte Datei wird geloescht.
--
-- WAS HIER BEWUSST NICHT STEHT
--   Kein Ort, kein Geburtsdatum, keine Telefonnummer, keine Verweise auf
--   fremde Netzwerke. Das waeren Angaben, die niemand prueft, die niemand
--   braucht und die im Zweifel eine Person auffindbar machen, die nur eine
--   Stadtfuehrung anbieten wollte.
--
-- EIGENSCHAFTEN
--   * Idempotent: "ADD COLUMN IF NOT EXISTS" (MariaDB). Unter MySQL 8 gibt
--     es diesen Zusatz nicht - dort die ALTER-Zeilen ohne ihn ausfuehren und
--     einen bereits vorhandenen Spaltennamen als erledigt betrachten.
--   * Kein Datenverlust: es kommen nur Spalten hinzu.
--   * Laeuft unter MariaDB und MySQL 8.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/015_guide_profil.sql
-- ===========================================================================

ALTER TABLE `guide_profile`
  -- 60 Zeichen. Ein Anzeigename ist ein Name und keine Anzeige: Er steht in
  -- Tabellenzellen, auf Kacheln und in einer Kopfzeile neben einem Bild.
  -- Was dort nicht mehr hinpasst, wird ohnehin abgeschnitten - besser das
  -- Feld sagt es vorher.
  ADD COLUMN IF NOT EXISTS `display_name` varchar(60) DEFAULT NULL AFTER `user_id`,

  -- Mehrzeilig, aber kurz gehalten (die Pruefung im Code laesst 600 Zeichen
  -- zu). TEXT und nicht VARCHAR, weil es ein Fliesstext ist und keine
  -- Angabe: Wer hier eine Obergrenze in Bytes braucht, sucht sie im Code -
  -- dort steht sie in Zeichen, und ein Umlaut ist zwei Bytes.
  ADD COLUMN IF NOT EXISTS `about` text DEFAULT NULL AFTER `display_name`,

  -- Dieselbe Form wie location.languages: Kuerzel nach ISO 639-1, durch
  -- Komma getrennt, normalisiert von App\Helper\Languages. Leerstring statt
  -- NULL, damit "nichts angegeben" nur eine Schreibweise hat.
  ADD COLUMN IF NOT EXISTS `languages` varchar(64) NOT NULL DEFAULT '' AFTER `about`,

  -- 32 Hexzeichen, vergeben von App\Helper\ImageStore. NULL heisst: kein
  -- Bild hochgeladen - dann zeigt die Oberflaeche die Initialen des
  -- Anzeigenamens (App\Helper\Avatar).
  ADD COLUMN IF NOT EXISTS `avatar_file` char(32) DEFAULT NULL AFTER `languages`,

  -- Seit wann dieses Konto Guide ist. Siehe oben: NICHT dasselbe wie
  -- guide_since.
  ADD COLUMN IF NOT EXISTS `joined_at` datetime DEFAULT NULL AFTER `guide_since`;

-- ---------------------------------------------------------------------------
-- Den Bestand nachtragen.
--
-- Fuer alle vorhandenen Profile ist guide_since das beste, was es an dieser
-- Stelle gibt: Bis heute wurde es nur einmal gesetzt, denn es gab erst eine
-- Fassung der Bedingungen (GuideRole::TERMS_VERSION = 1). Erfunden wird also
-- nichts - der Wert wird an die Spalte weitergereicht, die ihn kuenftig
-- festhaelt.
--
-- Ab jetzt laufen die beiden auseinander, und genau dafuer sind es zwei.
-- ---------------------------------------------------------------------------
UPDATE `guide_profile`
   SET `joined_at` = `guide_since`
 WHERE `joined_at` IS NULL;
