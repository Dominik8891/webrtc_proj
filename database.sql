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

  -- ANGEMELDET, NICHT VERFUEGBAR. Diese Spalte beantwortet genau eine Frage:
  -- Ist gerade ein Browser dieses Kontos erreichbar? Ob der Guide auch
  -- FUEHREN will, steht in `available_until` weiter unten - das sind zwei
  -- verschiedene Dinge, und sie wurden frueher verwechselt.
  --
  -- Der Code kennt genau drei Werte:
  --   'online'  - gesetzt vom Heartbeat  (UserController::heartbeat)
  --   'in_call' - gesetzt vom Heartbeat, wenn ein Call läuft (ebenda)
  --   'offline' - gesetzt vom Cronjob    (cron/check_online_status.php)
  --               und beim Abmelden      (LoginController::handleLogout)
  -- Typ varchar(20) statt ENUM: setUserStatus()/setStatus() nehmen beliebige
  -- Strings entgegen, und User::update() schreibt die Spalte bei jedem
  -- Speichern mit. Ein ENUM würde hier bei einem unerwarteten Wert im
  -- Strict-Mode einen Fehler werfen.
  -- NULL erlaubt, weil User::update() den Wert eines frisch registrierten
  -- Objekts (noch NULL) mitschreiben kann. Die Lesestellen behandeln NULL
  -- korrekt als "nicht online".
  `user_status` varchar(20) DEFAULT 'offline',

  -- BEREITSCHAFT des Guides (Migration 010). Bis zu diesem Zeitpunkt hat er
  -- sich ausdruecklich auf "bereit" gestellt; NULL oder ein Zeitpunkt in der
  -- Vergangenheit heisst "nicht bereit".
  --
  -- Gruen auf der Karte und anrufbar ist nur, wo BEIDES zutrifft: ein
  -- erreichbarer Browser (`user_status`) UND eine laufende Bereitschaft. Die
  -- Auswertung steht an genau einer Stelle im Code, als
  -- App\Model\Location::AVAILABILITY_SQL.
  --
  -- Geschrieben wird die Spalte von App\Model\User::startAvailability()
  -- (Schalter in der Kopfleiste), ::extendAvailability() (Heartbeat mit
  -- gemeldeter Bedienung) und ::endAvailability() (Schalter aus, Seite
  -- geschlossen, Abmelden). Ein Zeitpunkt statt eines Ja/Nein-Feldes: So ist
  -- "abgelaufen" allein aus der Zeile ablesbar und braucht keinen Cronjob.
  --
  -- Bewusst NICHT in User::update(): Die Bereitschaft ist ein fluechtiger
  -- Zustand und wird ausschliesslich mit gezielten UPDATEs gesetzt. Ein
  -- beilaeufiges save() darf sie weder verlaengern noch loeschen.
  `available_until` datetime DEFAULT NULL,

  -- UNGENUTZT. Zuletzt per Browser-Geolocation gemeldete Position des
  -- Nutzers. Es gibt weder eine schreibende noch eine lesende Stelle mehr:
  -- Der Dialog nach dem Login und die Route save_location sind entfallen,
  -- weil sie eine Umkreissuche begruendeten, die es in dieser Anwendung nie
  -- gab. Gesucht wird ueber die Karte, und die zeigt die Tabelle `location`
  -- - also angebotene Fuehrungen, nicht Nutzer.
  --
  -- Die Spalten bleiben vorerst stehen, damit bestehende Installationen
  -- nichts verlieren und ein spaeteres Umkreis-Feature nicht bei null
  -- anfaengt. Wer sie wieder benutzt, braucht dafuer eine ausdrueckliche
  -- Einwilligung: Der Aufenthaltsort einer Person ist ein personenbezogenes
  -- Datum, kein Nebenprodukt einer Anmeldung.
  --
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
  -- Die KURZE Beschreibung: die eine Zeile, die im Kartenfenster und in der
  -- Standortliste steht. Dort ist Kuerze richtig - ein Absatz in einem
  -- Kartenfenster ist unlesbar. Der ausfuehrliche Text steht in
  -- description_long und erscheint nur auf der Standortseite.
  `description` text DEFAULT NULL,

  -- Ueberschrift des Angebots. Bestehende Standorte haben sie mit
  -- migrations/011 aus ihrer bisherigen Beschreibung bekommen.
  `title` varchar(120) DEFAULT NULL,

  -- Die ausfuehrliche, mehrzeilige Beschreibung. Nur auf der Standortseite
  -- (index.php?act=location&id=...) zu sehen.
  `description_long` text DEFAULT NULL,

  -- Uebliche Dauer einer Fuehrung in Minuten. NULL heisst "nicht angegeben"
  -- und ist etwas anderes als 0 - deshalb keine Vorgabe. Der Controller
  -- laesst 5 bis 480 zu.
  `duration_minutes` smallint(5) unsigned DEFAULT NULL,

  -- Sprachen des Guides als Kuerzel nach ISO 639-1, kommagetrennt ("de,en").
  -- Geschrieben ausschliesslich ueber App\Helper\Languages::normalize().
  `languages` varchar(64) DEFAULT NULL,

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
-- Tabelle: location_image
-- Die Bilder eines Standorts. Je Bild eine Zeile.
--
-- HIER STEHT KEIN BILD DRIN, sondern nur sein Dateiname. Die Dateien liegen
-- AUSSERHALB des Document Root (Pfad aus config/uploads.php) und werden
-- ausschliesslich ueber index.php?act=location_image ausgeliefert - ein
-- Controller, der vorher prueft, ob der Standort gesperrt ist. Was unter dem
-- Webroot liegt, ist ueber HTTP abrufbar, und eine hochgeladene Datei ist
-- Fremdeingabe.
--
-- Ein BLOB waere die Alternative gewesen. Dagegen spricht die Sicherung: Sie
-- waere um Groessenordnungen gewachsen, ohne dass die Datenbank mit den Daten
-- irgendetwas anfangen koennte, was das Dateisystem nicht besser kann.
--
-- Bestehende Installationen: migrations/011_standort_inhalt.sql.
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `location_image` (
  `id` int(11) NOT NULL AUTO_INCREMENT,

  `location_id` int(11) NOT NULL,

  -- 32 Hexzeichen, vergeben von App\Helper\ImageStore. OHNE Pfad und OHNE
  -- Endung; daraus entstehen zwei Dateien, <name>.jpg und <name>_t.jpg.
  -- Der Name, unter dem hochgeladen wurde, wird verworfen: Er ist
  -- Fremdeingabe ("../", "bild.php") und wird nirgends gebraucht.
  `file_name` varchar(32) NOT NULL,

  -- WOFUER das Bild da ist:
  --   'cover'    das eine Titelbild, das die Kopfzeile der Standortseite
  --              fuellt. Hoechstens eines je Standort - durchgesetzt von
  --              App\Model\LocationImage::setCover(), nicht von der Datenbank:
  --              Ein Teilindex ueber "role = 'cover'" gibt es in MariaDB
  --              nicht.
  --   'gallery'  Beispielbild in der Galerie im Inhaltsbereich.
  --
  -- Ein Titelbild braucht ein sehr breites Format und ruhige Flaechen fuer
  -- die Schrift, ein Beispielbild soll den Ort zeigen. Vorher musste EIN Bild
  -- beides sein.
  --
  -- Vorgabe 'gallery': Ein hochgeladenes Bild ist erst einmal nur ein Bild.
  -- Zum Titelbild wird es durch eine Entscheidung.
  --
  -- Bestehende Installationen: migrations/012_titelbild.sql.
  `role` varchar(16) NOT NULL DEFAULT 'gallery',

  -- Reihenfolge in der Bildleiste, aufsteigend. Das erste Bild ist zugleich
  -- das Titelbild des Standorts.
  `sort_order` int(11) NOT NULL DEFAULT 0,

  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),

  -- Zusammengesetzt und in dieser Reihenfolge: Jede Abfrage holt die Bilder
  -- EINES Standorts sortiert.
  KEY `location_sort` (`location_id`, `sort_order`),

  -- Ein zufaelliger Name kollidiert praktisch nie - aber wenn doch, zeigte
  -- ein Standort das Bild eines anderen, und das faellt niemandem auf.
  UNIQUE KEY `file_name` (`file_name`),

  -- Wird der Standort geloescht, verschwinden seine Bildzeilen mit. Die
  -- DATEIEN nimmt das nicht mit - dafuer ruft
  -- LocationController::deleteLocation() vorher ImageStore::deleteLocationDir().
  CONSTRAINT `location_image_ibfk_1` FOREIGN KEY (`location_id`)
    REFERENCES `location` (`id`) ON DELETE CASCADE
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

  -- Standort, von dem der Anruf ausging. NULL = Direktanruf ohne Standort,
  -- wie ihn die Benutzerverwaltung ausloest.
  --
  -- Daran haengt die Rollenvergabe: Von einem Standort aus fuehrt der
  -- Angerufene (auch ein Admin), ohne Standort ist ein Anruf mit einem Admin
  -- keine Fuehrung. Der Anrufer schickt die Kennung mit seinem Offer, der
  -- Angerufene holt sie Sekunden spaeter mit derselben Zeile ab - anders
  -- kaemen die beiden Seiten nicht zur selben Antwort
  -- (WebRTCController::callRoles / ::stampCallRoles).
  --
  -- Bewusst OHNE Fremdschluessel auf location(id): Eine Zeile hier lebt 15
  -- Sekunden. Geprueft wird beim Lesen - Eigentuemer und Sperre -, nicht
  -- ueber die Datenbank. Nur an Zeilen vom Typ 'offer' belegt.
  `location_id` int(11) DEFAULT NULL,

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
