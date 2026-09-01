-- Datenbank-Struktur für WebRTC Remote Guidance
-- Erstellt für Dominik Kusber (Abschlussprojekt)
-- Letztes Update: 01.09.2026
--
-- Dieses Schema wurde mit dem Anwendungscode abgeglichen. Alle Spalten und
-- Tabellen, die der Code liest oder schreibt, sind hier enthalten.
-- Für bestehende Installationen siehe migrations/001_schema_abgleich.sql.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- --------------------------------------------------------
-- Tabelle: usertype
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usertype` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `usertype` (`id`, `name`) VALUES
(0, 'Admin'),
(1, 'Guide'),
(2, 'User'),
(3, 'Trial');

-- --------------------------------------------------------
-- Tabelle: country
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `country` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `country_name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabelle: city
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `city` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `city_name` varchar(255) NOT NULL,
  `country_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `country_id` (`country_id`),
  CONSTRAINT `city_ibfk_1` FOREIGN KEY (`country_id`) REFERENCES `country` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabelle: user (Haupttabelle)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `pwd` varchar(255) NOT NULL,

  -- ACHTUNG: `status` wird vom Anwendungscode NICHT verwendet. Die Spalte
  -- bleibt aus Kompatibilitätsgründen erhalten (bestehende Installationen
  -- könnten Daten enthalten). Der tatsächliche Online-Status steht in
  -- `user_status` weiter unten.
  `status` tinyint(4) DEFAULT 1,

  `type_id` int(11) DEFAULT 2,
  `email_verified` tinyint(1) DEFAULT 0,

  -- ACHTUNG: `last_aktive` wird vom Anwendungscode weder gelesen noch
  -- geschrieben. Als Aktivitätsmarker dient `updated_at`, das der Heartbeat
  -- (UserController::heartbeat) und der Cronjob auswerten. Spalte bleibt
  -- aus Kompatibilitätsgründen erhalten.
  `last_aktive` datetime DEFAULT NULL,

  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `totp_secret` varchar(255) DEFAULT NULL,
  `totp_enabled` tinyint(4) DEFAULT 0,
  `deleted` tinyint(4) DEFAULT 0,

  -- Online-Status des Nutzers. Der Code kennt genau drei Werte:
  --   'online'  - gesetzt vom Heartbeat  (UserController.php:134)
  --   'in_call' - gesetzt vom Heartbeat, wenn ein Call läuft (ebenda)
  --   'offline' - gesetzt vom Cronjob    (cron/check_online_status.php:26)
  -- Gelesen wird der Wert per Stringvergleich in UserController.php:224/227
  -- und in assets/js/locations_table.js:34/37/62.
  -- Typ varchar(20) statt ENUM: setUserStatus()/setStatus() nehmen beliebige
  -- Strings entgegen, und User::update() schreibt die Spalte bei jedem
  -- Speichern mit. Ein ENUM würde hier bei einem unerwarteten Wert im
  -- Strict-Mode einen Fehler werfen.
  -- NULL erlaubt, weil User::update() den Wert eines frisch registrierten
  -- Objekts (noch NULL) mitschreiben kann. Die Lesestellen behandeln NULL
  -- korrekt als "nicht online".
  `user_status` varchar(20) DEFAULT 'offline',

  -- Zuletzt per Browser-Geolocation gemeldete Position des Nutzers.
  -- Geschrieben von User::saveLocation() (User.php:494), aufgerufen über
  -- die Route save_location. Nicht zu verwechseln mit der Tabelle
  -- `location`, die die angebotenen Führungen enthält.
  -- Genauigkeit wie in `location`: 8 Nachkommastellen (~1,1 mm).
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `location_updated_at` datetime DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `type_id` (`type_id`),
  CONSTRAINT `user_ibfk_1` FOREIGN KEY (`type_id`) REFERENCES `usertype` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabelle: location
-- Von Guides angebotene Standorte/Führungen.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `location` (
  `id` int(11) NOT NULL AUTO_INCREMENT,

  -- Guide, dem dieser Standort gehört. Typ identisch zu `user`.`id`
  -- (int(11)). NOT NULL, weil ein Standort ohne Anbieter fachlich sinnlos
  -- ist und der Code die Spalte immer befüllt (Location.php:93/96).
  -- Gelesen über die Joins in Location.php:182 und :208.
  `user_id` int(11) NOT NULL,

  `city_id` int(11) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `city_id` (`city_id`),
  -- Index auf user_id: beide Abfragen in Location.php filtern darüber.
  KEY `user_id` (`user_id`),
  CONSTRAINT `location_ibfk_1` FOREIGN KEY (`city_id`) REFERENCES `city` (`id`) ON DELETE CASCADE,
  -- Wird ein Nutzer gelöscht, verschwinden seine Standorte mit.
  CONSTRAINT `location_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabelle: chat
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user1_id` int(11) NOT NULL,
  `user2_id` int(11) NOT NULL,
  `is_active` tinyint(4) DEFAULT 0,
  `last_msg_at` datetime DEFAULT NULL,
  `pending_for` int(11) DEFAULT NULL,
  `deleted` tinyint(4) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user1_id` (`user1_id`),
  KEY `user2_id` (`user2_id`),
  CONSTRAINT `chat_ibfk_1` FOREIGN KEY (`user1_id`) REFERENCES `user` (`id`),
  CONSTRAINT `chat_ibfk_2` FOREIGN KEY (`user2_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabelle: chat_message
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_message` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `msg` text NOT NULL,
  `sent_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `seen` tinyint(4) DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `chat_id` (`chat_id`),
  KEY `sender_id` (`sender_id`),
  CONSTRAINT `chat_message_ibfk_1` FOREIGN KEY (`chat_id`) REFERENCES `chat` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_message_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabelle: rtc_signal
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rtc_signal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `sdp` text DEFAULT NULL,
  `candidate` text DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  CONSTRAINT `rtc_signal_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rtc_signal_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabelle: password_resets
-- Einmal-Token für "Passwort vergessen".
-- Geschrieben und gelesen von PasswordController.php:48/52/81/124/136.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
  -- Surrogatschlüssel. Der Code selektiert die id nie, eine Tabelle braucht
  -- aber einen Primärschlüssel. user_id eignet sich dafür NICHT, siehe
  -- Kommentar beim Index weiter unten.
  `id` int(11) NOT NULL AUTO_INCREMENT,

  `user_id` int(11) NOT NULL,

  -- bin2hex(random_bytes(32)) erzeugt exakt 64 Hex-Zeichen
  -- (PasswordController.php:44).
  `token` varchar(64) NOT NULL,

  -- Ablaufzeitpunkt, gesetzt auf time()+3600, also eine Stunde
  -- (PasswordController.php:45). Beide Lesestellen filtern mit
  -- "expires_at > NOW()".
  `expires_at` datetime NOT NULL,

  PRIMARY KEY (`id`),
  -- UNIQUE auf dem Token: dient zugleich als Index für die Suche per Token
  -- und verhindert Kollisionen.
  UNIQUE KEY `token` (`token`),
  -- Nicht UNIQUE: PasswordController löscht zwar vor jedem INSERT die alten
  -- Einträge, ein UNIQUE würde die Anwendung aber bei einer künftigen
  -- Änderung dieser Reihenfolge hart brechen.
  KEY `user_id` (`user_id`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Tabelle: email_verifications
-- Einmal-Token für die Bestätigung der E-Mail-Adresse.
-- Geschrieben und gelesen von EmailVerificationController.php:25/40/84.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_verifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,

  -- bin2hex(random_bytes(32)) erzeugt exakt 64 Hex-Zeichen
  -- (EmailVerificationController.php:68).
  `token` varchar(64) NOT NULL,

  -- Ablaufzeitpunkt, gesetzt auf time()+86400, also 24 Stunden
  -- (EmailVerificationController.php:69).
  `expires_at` datetime NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  -- Nicht UNIQUE: sendVerificationMail() fügt ein, OHNE vorher zu löschen
  -- (EmailVerificationController.php:84). Ein zweiter Versand für denselben
  -- Nutzer würde sonst fehlschlagen.
  KEY `user_id` (`user_id`),
  CONSTRAINT `email_verifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

COMMIT;
