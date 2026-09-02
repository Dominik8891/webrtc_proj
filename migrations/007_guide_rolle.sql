-- ===========================================================================
-- Migration 007: Die Guide-Rolle wird eine bewusste Entscheidung
-- ===========================================================================
--
-- Legt die Tabelle `guide_profile` an: je ein Datensatz fuer jedes Konto, das
-- der Guide-Rolle jemals zugestimmt hat.
--
-- WOZU
--   Bisher wurde man stillschweigend Guide, sobald man einen Standort anlegte
--   (LocationController::setLocation, alter Stand). Es gab damit keinen
--   Zeitpunkt, keine Zustimmung und keinen Text, dem jemand zugestimmt haette.
--   Kuenftig kostet jede Fuehrung Geld - und eine Abrechnung braucht genau
--   diese drei Angaben.
--
--   Geschrieben und gelesen wird die Tabelle ausschliesslich ueber
--   App\Model\GuideRole. Das ist auch die einzige Stelle, an der die
--   Guide-Rolle vergeben oder zurueckgegeben wird.
--
-- WARUM EINE EIGENE TABELLE UND KEINE SPALTEN AN `user`
--   1. App\Model\User::update() schreibt saemtliche Spalten von `user` bei
--      jedem Speichern mit. Ein Zustimmungsdatum, das die Benutzerverwaltung
--      beim Aendern einer E-Mail-Adresse mit ueberschreibt, waere wertlos.
--   2. Die Abrechnung kommt spaeter mit eigenen Tabellen (Auszahlungskonto,
--      Einzelabrechnung je Fuehrung). Die haengen sich an
--      guide_profile.user_id und nicht an `user` - `user` bleibt die Tabelle
--      fuer das Konto, nicht fuer die Geschaeftsbeziehung.
--
-- WAS HIER BEWUSST NICHT STEHT
--   Keine Preise, keine Betraege, kein Zahlungsstatus. Solche Spalten haetten
--   heute niemanden, der sie fuellt, und stuenden als Karteileichen im
--   Schema. Jede der vier Spalten unten wird vom laufenden Code geschrieben.
--
-- EIGENSCHAFTEN
--   * Idempotent: CREATE TABLE IF NOT EXISTS, das Nachtragen bestehender
--     Guides laeuft ueber INSERT IGNORE.
--   * Kein Datenverlust: es kommt nur eine Tabelle hinzu.
--   * Laeuft unter MariaDB und MySQL 8.
--
-- NACH DER MIGRATION
--   Konten mit der Rolle Trial (0) werden beim naechsten Login gefragt, ob
--   sie Guide werden moechten. Konten mit der Rolle User (1) werden NICHT
--   gefragt - sie koennen die Rolle in den Einstellungen jederzeit annehmen.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/007_guide_rolle.sql
-- ===========================================================================

CREATE TABLE IF NOT EXISTS `guide_profile` (

  -- Konto, zu dem dieses Guide-Profil gehoert. Zugleich Primaerschluessel:
  -- ein Konto hat hoechstens ein Profil. Wer die Rolle abgibt und spaeter
  -- wieder annimmt, behaelt seine Zeile - `guide_since` wird dann neu
  -- gesetzt, `resigned_at` wieder geleert.
  `user_id` int(11) NOT NULL,

  -- Wann die Guide-Rolle zuletzt angenommen wurde. Beginn des Zeitraums, den
  -- eine spaetere Abrechnung betrachtet.
  `guide_since` datetime DEFAULT NULL,

  -- Fassung der Guide-Bedingungen, der zugestimmt wurde. Der aktuelle Stand
  -- steht als Konstante im Code (App\Model\GuideRole::TERMS_VERSION).
  --
  -- Das ist der Hebel fuer die spaetere Abrechnung: Wird die Konstante
  -- hochgezaehlt, weil Fuehrungen kostenpflichtig werden, gilt die alte
  -- Zustimmung nicht mehr und der Dialog erscheint erneut - mit dem neuen
  -- Text. Ohne dieses Feld liesse sich nachtraeglich nicht mehr feststellen,
  -- wem welcher Text vorgelegen hat.
  `terms_version` int(11) NOT NULL DEFAULT 0,

  -- Zeitpunkt eben dieser Zustimmung.
  `terms_accepted_at` datetime DEFAULT NULL,

  -- Wann die Rolle zurueckgegeben wurde, NULL bei einem aktiven Guide. Die
  -- Zeile bleibt beim Widerruf stehen: Eine Abrechnung muss auch fuer
  -- beendete Guide-Verhaeltnisse noch nachvollziehbar sein.
  `resigned_at` datetime DEFAULT NULL,

  PRIMARY KEY (`user_id`),

  -- Wird das Konto geloescht, verschwindet das Profil mit. Solange es offene
  -- Abrechnungen gaebe, duerfte das Konto ohnehin nicht geloescht werden -
  -- das zu entscheiden ist Sache der spaeteren Abrechnung, nicht dieser
  -- Tabelle.
  CONSTRAINT `guide_profile_ibfk_1` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Bestehende Guides nachtragen.
--
-- Sie sind ueber den alten, stillschweigenden Weg Guide geworden. Damit sie
-- am Tag der Umstellung nicht alle vor einem Dialog stehen, gelten sie als
-- der aktuellen Fassung zustimmend (terms_version = 1, dieselbe Zahl wie
-- GuideRole::TERMS_VERSION). Als Zeitpunkt dient das Anlagedatum des Kontos -
-- der genaue Tag des Aufstiegs ist rueckwirkend nicht mehr feststellbar.
--
-- Sobald die Fassung wegen der Abrechnung auf 2 steigt, werden auch sie
-- gefragt. Genau dafuer ist das Feld da.
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO `guide_profile`
    (`user_id`, `guide_since`, `terms_version`, `terms_accepted_at`, `resigned_at`)
SELECT `id`, `created_at`, 1, `created_at`, NULL
FROM `user`
WHERE `type_id` = 2;
