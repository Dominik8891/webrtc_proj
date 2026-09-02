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
--
-- Die Nummern bilden KEINE Rangfolge. Sie sind Etiketten, mit denen die
-- Rechtetabelle in class/Helper/Permission.php arbeitet; eine hoehere Nummer
-- bedeutet nicht "darf mehr". Vergleiche wie type_id <= 1 sind deshalb immer
-- falsch - tests/server_test.php verbietet sie im PHP-Code dauerhaft.
--
-- Die Luecke zwischen 2 und 10 ist Absicht: Dort ist Platz fuer weitere
-- Rollen, die nicht gleich Admin sein sollen (etwa eine reine
-- Moderationsrolle). Eine neue Rolle braucht einen Eintrag hier und einen in
-- Permission.php - sonst nichts.
--
-- Bestehende Installationen bringt migrations/005_rollen_neu_nummeriert.sql
-- auf diese Nummern. Die Reihenfolge der INSERTs ist nicht beliebig: Bei
-- einer neuen Installation ist die Tabelle leer, INSERT IGNORE legt alle vier
-- an; bei einer alten haelt IGNORE die vorhandenen Zeilen fest, und erst die
-- Migration verschiebt sie.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usertype` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `usertype` (`id`, `name`) VALUES
( 0, 'Trial'),   -- frisch registriert, Guide-Frage noch offen
( 1, 'User'),    -- Zuschauer, hat sich gegen die Guide-Rolle entschieden
( 2, 'Guide'),   -- bietet Standorte an, hat der Rolle zugestimmt
(10, 'Admin');

-- --------------------------------------------------------
-- Tabelle: country
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `country` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `country_name` varchar(255) NOT NULL,

  -- Laendercode nach ISO 3166-1 alpha-2, z.B. 'DE'.
  -- Das Frontend braucht ihn zwingend (assets/js/map.js):
  --   Zeile  78: filtert die Laenderliste gegen allowedCountryCodes -
  --              ohne iso2 bleibt der Laender-Dropdown LEER
  --   Zeile 184: Parameter countrycodes= der Staedtesuche bei Nominatim
  --   Zeile 115: Flaggengrafik von flagcdn.com/24x18/<iso2>.png
  -- UNIQUE, weil der Code das fachliche Schluesselmerkmal ist und die
  -- Seed-Daten per INSERT IGNORE wiederholbar eingespielt werden koennen.
  `iso2` char(2) NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `iso2` (`iso2`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stammdaten: alle 248 Laender, die assets/js/map.js in
-- allowedCountryCodes (Zeile 7-25) zulaesst. Die Tabelle hat KEINEN
-- Schreibpfad im Anwendungscode - sie muss vorbefuellt sein, sonst bleibt
-- die Auswahl beim Standortanlegen leer.
-- INSERT IGNORE macht den Block wiederholbar: bereits vorhandene Codes
-- werden uebersprungen, es entstehen keine Dubletten.
-- Die Namen sind deutsch. Sie gehen als Parameter country= an Nominatim
-- (map.js:154), um die Karte auf das gewaehlte Land zu zentrieren.
INSERT IGNORE INTO `country` (`country_name`, `iso2`) VALUES
  ('Andorra'                                     , 'AD'),
  ('Vereinigte Arabische Emirate'                , 'AE'),
  ('Afghanistan'                                 , 'AF'),
  ('Antigua und Barbuda'                         , 'AG'),
  ('Anguilla'                                    , 'AI'),
  ('Albanien'                                    , 'AL'),
  ('Armenien'                                    , 'AM'),
  ('Angola'                                      , 'AO'),
  ('Antarktis'                                   , 'AQ'),
  ('Argentinien'                                 , 'AR'),
  ('Amerikanisch-Samoa'                          , 'AS'),
  ('Österreich'                                  , 'AT'),
  ('Australien'                                  , 'AU'),
  ('Aruba'                                       , 'AW'),
  ('Åland'                                       , 'AX'),
  ('Aserbaidschan'                               , 'AZ'),
  ('Bosnien und Herzegowina'                     , 'BA'),
  ('Barbados'                                    , 'BB'),
  ('Bangladesch'                                 , 'BD'),
  ('Belgien'                                     , 'BE'),
  ('Burkina Faso'                                , 'BF'),
  ('Bulgarien'                                   , 'BG'),
  ('Bahrain'                                     , 'BH'),
  ('Burundi'                                     , 'BI'),
  ('Benin'                                       , 'BJ'),
  ('Saint-Barthélemy'                            , 'BL'),
  ('Bermuda'                                     , 'BM'),
  ('Brunei Darussalam'                           , 'BN'),
  ('Bolivien'                                    , 'BO'),
  ('Bonaire, Sint Eustatius und Saba'            , 'BQ'),
  ('Brasilien'                                   , 'BR'),
  ('Bahamas'                                     , 'BS'),
  ('Bhutan'                                      , 'BT'),
  ('Bouvetinsel'                                 , 'BV'),
  ('Botsuana'                                    , 'BW'),
  ('Belarus'                                     , 'BY'),
  ('Belize'                                      , 'BZ'),
  ('Kanada'                                      , 'CA'),
  ('Kokosinseln'                                 , 'CC'),
  ('Kongo, Demokratische Republik'               , 'CD'),
  ('Zentralafrikanische Republik'                , 'CF'),
  ('Kongo, Republik'                             , 'CG'),
  ('Schweiz'                                     , 'CH'),
  ('Elfenbeinküste'                              , 'CI'),
  ('Cookinseln'                                  , 'CK'),
  ('Chile'                                       , 'CL'),
  ('Kamerun'                                     , 'CM'),
  ('China'                                       , 'CN'),
  ('Kolumbien'                                   , 'CO'),
  ('Costa Rica'                                  , 'CR'),
  ('Kuba'                                        , 'CU'),
  ('Cabo Verde'                                  , 'CV'),
  ('Curaçao'                                     , 'CW'),
  ('Weihnachtsinsel'                             , 'CX'),
  ('Zypern'                                      , 'CY'),
  ('Tschechien'                                  , 'CZ'),
  ('Deutschland'                                 , 'DE'),
  ('Dschibuti'                                   , 'DJ'),
  ('Dänemark'                                    , 'DK'),
  ('Dominica'                                    , 'DM'),
  ('Dominikanische Republik'                     , 'DO'),
  ('Algerien'                                    , 'DZ'),
  ('Ecuador'                                     , 'EC'),
  ('Estland'                                     , 'EE'),
  ('Ägypten'                                     , 'EG'),
  ('Westsahara'                                  , 'EH'),
  ('Eritrea'                                     , 'ER'),
  ('Spanien'                                     , 'ES'),
  ('Äthiopien'                                   , 'ET'),
  ('Finnland'                                    , 'FI'),
  ('Fidschi'                                     , 'FJ'),
  ('Falklandinseln'                              , 'FK'),
  ('Mikronesien'                                 , 'FM'),
  ('Färöer'                                      , 'FO'),
  ('Frankreich'                                  , 'FR'),
  ('Gabun'                                       , 'GA'),
  ('Vereinigtes Königreich'                      , 'GB'),
  ('Grenada'                                     , 'GD'),
  ('Georgien'                                    , 'GE'),
  ('Französisch-Guayana'                         , 'GF'),
  ('Guernsey'                                    , 'GG'),
  ('Ghana'                                       , 'GH'),
  ('Gibraltar'                                   , 'GI'),
  ('Grönland'                                    , 'GL'),
  ('Gambia'                                      , 'GM'),
  ('Guinea'                                      , 'GN'),
  ('Guadeloupe'                                  , 'GP'),
  ('Äquatorialguinea'                            , 'GQ'),
  ('Griechenland'                                , 'GR'),
  ('Südgeorgien und die Südlichen Sandwichinseln', 'GS'),
  ('Guatemala'                                   , 'GT'),
  ('Guam'                                        , 'GU'),
  ('Guinea-Bissau'                               , 'GW'),
  ('Guyana'                                      , 'GY'),
  ('Hongkong'                                    , 'HK'),
  ('Heard und McDonaldinseln'                    , 'HM'),
  ('Honduras'                                    , 'HN'),
  ('Kroatien'                                    , 'HR'),
  ('Haiti'                                       , 'HT'),
  ('Ungarn'                                      , 'HU'),
  ('Indonesien'                                  , 'ID'),
  ('Irland'                                      , 'IE'),
  ('Israel'                                      , 'IL'),
  ('Isle of Man'                                 , 'IM'),
  ('Indien'                                      , 'IN'),
  ('Britisches Territorium im Indischen Ozean'   , 'IO'),
  ('Irak'                                        , 'IQ'),
  ('Iran'                                        , 'IR'),
  ('Island'                                      , 'IS'),
  ('Italien'                                     , 'IT'),
  ('Jersey'                                      , 'JE'),
  ('Jamaika'                                     , 'JM'),
  ('Jordanien'                                   , 'JO'),
  ('Japan'                                       , 'JP'),
  ('Kenia'                                       , 'KE'),
  ('Kirgisistan'                                 , 'KG'),
  ('Kambodscha'                                  , 'KH'),
  ('Kiribati'                                    , 'KI'),
  ('Komoren'                                     , 'KM'),
  ('St. Kitts und Nevis'                         , 'KN'),
  ('Korea, Demokratische Volksrepublik'          , 'KP'),
  ('Korea, Republik'                             , 'KR'),
  ('Kuwait'                                      , 'KW'),
  ('Kaimaninseln'                                , 'KY'),
  ('Kasachstan'                                  , 'KZ'),
  ('Laos'                                        , 'LA'),
  ('Libanon'                                     , 'LB'),
  ('St. Lucia'                                   , 'LC'),
  ('Liechtenstein'                               , 'LI'),
  ('Sri Lanka'                                   , 'LK'),
  ('Liberia'                                     , 'LR'),
  ('Lesotho'                                     , 'LS'),
  ('Litauen'                                     , 'LT'),
  ('Luxemburg'                                   , 'LU'),
  ('Lettland'                                    , 'LV'),
  ('Libyen'                                      , 'LY'),
  ('Marokko'                                     , 'MA'),
  ('Monaco'                                      , 'MC'),
  ('Moldau'                                      , 'MD'),
  ('Montenegro'                                  , 'ME'),
  ('Saint-Martin'                                , 'MF'),
  ('Madagaskar'                                  , 'MG'),
  ('Marshallinseln'                              , 'MH'),
  ('Nordmazedonien'                              , 'MK'),
  ('Mali'                                        , 'ML'),
  ('Myanmar'                                     , 'MM'),
  ('Mongolei'                                    , 'MN'),
  ('Macau'                                       , 'MO'),
  ('Nördliche Marianen'                          , 'MP'),
  ('Martinique'                                  , 'MQ'),
  ('Mauretanien'                                 , 'MR'),
  ('Montserrat'                                  , 'MS'),
  ('Malta'                                       , 'MT'),
  ('Mauritius'                                   , 'MU'),
  ('Malediven'                                   , 'MV'),
  ('Malawi'                                      , 'MW'),
  ('Mexiko'                                      , 'MX'),
  ('Malaysia'                                    , 'MY'),
  ('Mosambik'                                    , 'MZ'),
  ('Namibia'                                     , 'NA'),
  ('Neukaledonien'                               , 'NC'),
  ('Niger'                                       , 'NE'),
  ('Norfolkinsel'                                , 'NF'),
  ('Nigeria'                                     , 'NG'),
  ('Nicaragua'                                   , 'NI'),
  ('Niederlande'                                 , 'NL'),
  ('Norwegen'                                    , 'NO'),
  ('Nepal'                                       , 'NP'),
  ('Nauru'                                       , 'NR'),
  ('Niue'                                        , 'NU'),
  ('Neuseeland'                                  , 'NZ'),
  ('Oman'                                        , 'OM'),
  ('Panama'                                      , 'PA'),
  ('Peru'                                        , 'PE'),
  ('Französisch-Polynesien'                      , 'PF'),
  ('Papua-Neuguinea'                             , 'PG'),
  ('Philippinen'                                 , 'PH'),
  ('Pakistan'                                    , 'PK'),
  ('Polen'                                       , 'PL'),
  ('Saint-Pierre und Miquelon'                   , 'PM'),
  ('Pitcairninseln'                              , 'PN'),
  ('Puerto Rico'                                 , 'PR'),
  ('Palästina'                                   , 'PS'),
  ('Portugal'                                    , 'PT'),
  ('Palau'                                       , 'PW'),
  ('Paraguay'                                    , 'PY'),
  ('Katar'                                       , 'QA'),
  ('Réunion'                                     , 'RE'),
  ('Rumänien'                                    , 'RO'),
  ('Serbien'                                     , 'RS'),
  ('Russland'                                    , 'RU'),
  ('Ruanda'                                      , 'RW'),
  ('Saudi-Arabien'                               , 'SA'),
  ('Salomonen'                                   , 'SB'),
  ('Seychellen'                                  , 'SC'),
  ('Sudan'                                       , 'SD'),
  ('Schweden'                                    , 'SE'),
  ('Singapur'                                    , 'SG'),
  ('St. Helena, Ascension und Tristan da Cunha'  , 'SH'),
  ('Slowenien'                                   , 'SI'),
  ('Svalbard und Jan Mayen'                      , 'SJ'),
  ('Slowakei'                                    , 'SK'),
  ('Sierra Leone'                                , 'SL'),
  ('San Marino'                                  , 'SM'),
  ('Senegal'                                     , 'SN'),
  ('Somalia'                                     , 'SO'),
  ('Suriname'                                    , 'SR'),
  ('Südsudan'                                    , 'SS'),
  ('São Tomé und Príncipe'                       , 'ST'),
  ('El Salvador'                                 , 'SV'),
  ('Sint Maarten'                                , 'SX'),
  ('Syrien'                                      , 'SY'),
  ('Turks- und Caicosinseln'                     , 'TC'),
  ('Tschad'                                      , 'TD'),
  ('Französische Südgebiete'                     , 'TF'),
  ('Togo'                                        , 'TG'),
  ('Thailand'                                    , 'TH'),
  ('Tadschikistan'                               , 'TJ'),
  ('Tokelau'                                     , 'TK'),
  ('Timor-Leste'                                 , 'TL'),
  ('Turkmenistan'                                , 'TM'),
  ('Tunesien'                                    , 'TN'),
  ('Tonga'                                       , 'TO'),
  ('Türkei'                                      , 'TR'),
  ('Trinidad und Tobago'                         , 'TT'),
  ('Tuvalu'                                      , 'TV'),
  ('Taiwan'                                      , 'TW'),
  ('Tansania'                                    , 'TZ'),
  ('Ukraine'                                     , 'UA'),
  ('Uganda'                                      , 'UG'),
  ('Amerikanische Überseeinseln'                 , 'UM'),
  ('Vereinigte Staaten'                          , 'US'),
  ('Uruguay'                                     , 'UY'),
  ('Usbekistan'                                  , 'UZ'),
  ('Vatikanstadt'                                , 'VA'),
  ('St. Vincent und die Grenadinen'              , 'VC'),
  ('Venezuela'                                   , 'VE'),
  ('Britische Jungferninseln'                    , 'VG'),
  ('Amerikanische Jungferninseln'                , 'VI'),
  ('Vietnam'                                     , 'VN'),
  ('Vanuatu'                                     , 'VU'),
  ('Wallis und Futuna'                           , 'WF'),
  ('Samoa'                                       , 'WS'),
  ('Jemen'                                       , 'YE'),
  ('Mayotte'                                     , 'YT'),
  ('Südafrika'                                   , 'ZA'),
  ('Sambia'                                      , 'ZM'),
  ('Simbabwe'                                    , 'ZW');

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

  -- Rolle des Kontos, siehe Tabelle usertype. Vorgabe ist Trial (0), die
  -- Rolle direkt nach der Registrierung - denselben Wert setzt
  -- User::create() ueber App\Helper\Role::TRIAL.
  --
  -- Trial heisst dabei "die Guide-Frage ist noch offen": Beim Login bekommt
  -- ein solches Konto den Dialog aus assets/html/guide_role.html. Danach ist
  -- es Guide (2) oder User (1) und wird nicht mehr gefragt. Wer der
  -- Guide-Rolle zugestimmt hat, steht in guide_profile.
  `type_id` int(11) DEFAULT 0,
  `email_verified` tinyint(1) DEFAULT 0,

  -- ACHTUNG: `last_aktive` wird vom Anwendungscode weder gelesen noch
  -- geschrieben. Als Aktivitätsmarker dient `updated_at`, das der Heartbeat
  -- (UserController::heartbeat) und der Cronjob auswerten. Spalte bleibt
  -- aus Kompatibilitätsgründen erhalten.
  `last_aktive` datetime DEFAULT NULL,

  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Gewaehltes Farbprofil der Oberflaeche (Migration 008). NULL heisst
  -- "noch nichts gewaehlt" - solche Konten bekommen die Vorgabe aus
  -- App\Helper\Theme. Absichtlich varchar und kein ENUM: Welche Profile es
  -- gibt, steht in Theme.php, damit ein weiteres Profil keine Migration
  -- braucht.
  `theme` varchar(20) DEFAULT NULL,

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
-- Tabelle: guide_profile
--
-- Je ein Datensatz fuer jedes Konto, das der Guide-Rolle jemals zugestimmt
-- hat. Geschrieben und gelesen ausschliesslich ueber App\Model\GuideRole -
-- dieselbe Klasse ist auch die einzige Stelle, an der die Guide-Rolle
-- vergeben oder zurueckgegeben wird.
--
-- WOZU DIE TABELLE UEBERHAUPT
--   Guide zu sein ist eine bewusste Entscheidung, kein Nebeneffekt des
--   Standortanlegens. Und kuenftig kostet jede Fuehrung Geld: Eine Abrechnung
--   braucht den Zeitpunkt der Zustimmung, die Fassung der Bedingungen und den
--   Beginn des Guide-Verhaeltnisses. Genau das steht hier.
--
-- WARUM NICHT ALS SPALTEN AN `user`
--   User::update() schreibt alle Spalten von `user` bei jedem Speichern mit;
--   ein Zustimmungsdatum waere dort nicht sicher vor der Benutzerverwaltung.
--   Ausserdem haengen sich die spaeteren Abrechnungstabellen an
--   guide_profile.user_id - `user` bleibt die Tabelle fuer das Konto, nicht
--   fuer die Geschaeftsbeziehung.
--
-- Bestehende Installationen: migrations/007_guide_rolle.sql.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `guide_profile` (

  -- Konto, zu dem das Profil gehoert; zugleich Primaerschluessel. Wer die
  -- Rolle abgibt und spaeter wieder annimmt, behaelt seine Zeile.
  `user_id` int(11) NOT NULL,

  -- Wann die Guide-Rolle zuletzt angenommen wurde. Beginn des Zeitraums, den
  -- eine spaetere Abrechnung betrachtet.
  `guide_since` datetime DEFAULT NULL,

  -- Fassung der Guide-Bedingungen, der zugestimmt wurde; aktueller Stand in
  -- App\Model\GuideRole::TERMS_VERSION. Steigt die Konstante, weil Fuehrungen
  -- kostenpflichtig werden, gilt die alte Zustimmung nicht mehr und der
  -- Dialog erscheint erneut - mit dem neuen Text.
  `terms_version` int(11) NOT NULL DEFAULT 0,

  -- Zeitpunkt eben dieser Zustimmung.
  `terms_accepted_at` datetime DEFAULT NULL,

  -- Wann die Rolle zurueckgegeben wurde, NULL bei einem aktiven Guide. Die
  -- Zeile bleibt beim Widerruf stehen - ein beendetes Guide-Verhaeltnis muss
  -- nachvollziehbar bleiben.
  `resigned_at` datetime DEFAULT NULL,

  PRIMARY KEY (`user_id`),
  CONSTRAINT `guide_profile_ibfk_1` FOREIGN KEY (`user_id`)
    REFERENCES `user` (`id`) ON DELETE CASCADE
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

  -- Moderation: Ein gesperrter Standort verschwindet aus der Uebersicht der
  -- anderen Nutzer (Location::selectAllLocations filtert blocked = 0), bleibt
  -- aber beim Guide stehen, der in seiner eigenen Liste den Grund sieht.
  -- Geloescht wird nichts - das bleibt dem Eigentuemer vorbehalten.
  -- Gesetzt von Location::block() / unblock(), Recht location.block.
  `blocked` tinyint(1) NOT NULL DEFAULT 0,
  `blocked_reason` varchar(255) DEFAULT NULL,

  -- Wer gesperrt hat. Kein Fremdschluessel auf user(id): Wird das Konto des
  -- Moderators geloescht, soll die Sperre bestehen bleiben.
  `blocked_by` int(11) DEFAULT NULL,
  `blocked_at` datetime DEFAULT NULL,

  PRIMARY KEY (`id`),
  KEY `city_id` (`city_id`),
  -- Index auf blocked: Die Uebersicht filtert bei jedem Aufruf darueber.
  KEY `blocked` (`blocked`),
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
