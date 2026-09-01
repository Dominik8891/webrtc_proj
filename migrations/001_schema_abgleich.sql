-- ===========================================================================
-- Migration 001: Schema-Abgleich fuer bestehende Installationen
-- ===========================================================================
--
-- Bringt eine bestehende Datenbank auf den Stand von database.sql.
-- Behebt die in BESTANDSAUFNAHME.md dokumentierten Abweichungen zwischen
-- Schema und Anwendungscode.
--
-- EIGENSCHAFTEN
--   * Idempotent: kann mehrfach ausgefuehrt werden, ohne Schaden anzurichten.
--   * Kein Datenverlust: es wird nichts geloescht und nichts ueberschrieben.
--   * Setzt MariaDB voraus (nutzt "IF NOT EXISTS" in ALTER TABLE).
--     Unter MySQL 8 laeuft diese Datei NICHT.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/001_schema_abgleich.sql
--
-- WICHTIG
--   location.user_id wird hier bewusst NULLable und OHNE Foreign Key
--   angelegt. Grund: die Spalte hat nie existiert, es gibt also keine
--   Datenquelle, aus der sich die Zuordnung Standort -> Guide
--   rekonstruieren liesse. Am Ende dieser Datei wird ausgegeben, wie viele
--   Standorte betroffen sind. Erst danach setzt Migration 002 die Spalte
--   auf NOT NULL und legt den Foreign Key an.
-- ===========================================================================

-- HINWEIS ZUR TRANSAKTION: DDL-Befehle (ALTER TABLE, CREATE TABLE) loesen in
-- MariaDB ein implizites COMMIT aus. Die folgende Klammer schuetzt daher NICHT
-- vor einem halb angewendeten Schema. Sie steht nur der Einheitlichkeit halber.
-- Alle Schritte sind aber idempotent: bei einem Abbruch kann die Datei nach
-- Behebung der Ursache einfach erneut ausgefuehrt werden.
START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1. user.user_status
--    Online-Status. Der Code kennt genau drei Werte: 'online' und 'in_call'
--    (Heartbeat, UserController.php:134) sowie 'offline' (Cronjob,
--    cron/check_online_status.php:26).
--    Bestehende Zeilen erhalten den Default 'offline' - das ist korrekt,
--    denn beim Einspielen der Migration ist niemand eingeloggt. Der naechste
--    Heartbeat setzt aktive Nutzer ohnehin binnen 15 Sekunden auf 'online'.
-- ---------------------------------------------------------------------------
ALTER TABLE `user`
    ADD COLUMN IF NOT EXISTS `user_status` varchar(20) DEFAULT 'offline' AFTER `deleted`;

-- ---------------------------------------------------------------------------
-- 2. user.latitude / user.longitude / user.location_updated_at
--    Zuletzt per Browser-Geolocation gemeldete Position des Nutzers,
--    geschrieben von User::saveLocation() (User.php:494).
--    Bestehende Zeilen bleiben NULL, bis der Nutzer seinen Standort teilt.
-- ---------------------------------------------------------------------------
ALTER TABLE `user`
    ADD COLUMN IF NOT EXISTS `latitude`            decimal(10,8) DEFAULT NULL AFTER `user_status`,
    ADD COLUMN IF NOT EXISTS `longitude`           decimal(11,8) DEFAULT NULL AFTER `latitude`,
    ADD COLUMN IF NOT EXISTS `location_updated_at` datetime      DEFAULT NULL AFTER `longitude`;

-- ---------------------------------------------------------------------------
-- 3. location.user_id
--    Zuordnung Standort -> Guide. Typ identisch zu user.id (int(11)).
--    Hier NULLable und ohne Foreign Key, siehe Hinweis im Dateikopf.
-- ---------------------------------------------------------------------------
ALTER TABLE `location`
    ADD COLUMN IF NOT EXISTS `user_id` int(11) DEFAULT NULL AFTER `id`,
    ADD INDEX  IF NOT EXISTS `user_id` (`user_id`);

-- ---------------------------------------------------------------------------
-- 4. Tabelle password_resets
--    Einmal-Token fuer "Passwort vergessen".
--    Genutzt von PasswordController.php:48/52/81/124/136.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         int(11)     NOT NULL AUTO_INCREMENT,
  `user_id`    int(11)     NOT NULL,
  -- bin2hex(random_bytes(32)) liefert exakt 64 Hex-Zeichen
  `token`      varchar(64) NOT NULL,
  -- Gesetzt auf time() + 3600, also eine Stunde
  `expires_at` datetime    NOT NULL,
  PRIMARY KEY (`id`),
  -- UNIQUE dient zugleich als Index fuer die Suche per Token
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 5. Tabelle email_verifications
--    Einmal-Token fuer die Bestaetigung der E-Mail-Adresse.
--    Genutzt von EmailVerificationController.php:25/40/84.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id`         int(11)     NOT NULL AUTO_INCREMENT,
  `user_id`    int(11)     NOT NULL,
  -- bin2hex(random_bytes(32)) liefert exakt 64 Hex-Zeichen
  `token`      varchar(64) NOT NULL,
  -- Gesetzt auf time() + 86400, also 24 Stunden
  `expires_at` datetime    NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  -- Bewusst NICHT unique: sendVerificationMail() fuegt ohne vorheriges
  -- DELETE ein (EmailVerificationController.php:84).
  KEY `user_id` (`user_id`),
  CONSTRAINT `email_verifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- 6. user.status und user.last_aktive
--    Beide Spalten werden vom Anwendungscode nicht verwendet. Sie bleiben
--    bewusst erhalten - ein DROP wuerde moeglicherweise vorhandene Daten
--    vernichten. Hier ist also nichts zu tun; der Punkt steht nur der
--    Vollstaendigkeit halber, weil er in BESTANDSAUFNAHME.md aufgefuehrt ist.
-- ---------------------------------------------------------------------------

COMMIT;

-- ===========================================================================
-- Auswertung: verwaiste Standorte
-- ===========================================================================
-- Diese Migration loescht NICHTS. Die folgende Abfrage zeigt nur, wie viele
-- Standorte noch keinem Guide zugeordnet sind. Ist das Ergebnis groesser
-- als 0, muessen diese Zeilen zugeordnet oder entfernt werden, bevor
-- Migration 002 laufen kann. Das weitere Vorgehen steht im Kopf von
-- migrations/002_location_user_id_pflicht.sql.
-- ---------------------------------------------------------------------------
SELECT
    COUNT(*)                                   AS `Standorte ohne Guide-Zuordnung`,
    (SELECT COUNT(*) FROM `location`)          AS `Standorte gesamt`
FROM `location`
WHERE `user_id` IS NULL;
