-- ===========================================================================
-- Migration 004: Stammdaten fuer die Tabelle country
-- ===========================================================================
--
-- Spielt alle 248 Laender ein, die assets/js/map.js in allowedCountryCodes
-- (Zeile 7-25) zulaesst - die von OpenStreetMap unterstuetzten Codes.
--
-- WARUM
--   Die Tabelle country hat im Anwendungscode KEINEN Schreibpfad: es gibt
--   weder ein INSERT noch ein UPDATE, nur lesende Zugriffe
--   (Location::selectAllCountries, Location.php:159, sowie die Joins).
--   Ohne Stammdaten bleibt die Laenderauswahl beim Standortanlegen leer,
--   und ohne gewaehltes Land liefert auch die Staedtesuche nichts
--   (map.js:182 gibt bei fehlendem iso2 ein leeres Ergebnis zurueck).
--
--   Die Tabelle city braucht KEINE Stammdaten - sie fuellt sich beim
--   Anlegen eines Standorts selbst (Location::insertCityName,
--   Location.php:248).
--
-- VORAUSSETZUNG
--   Migration 003 muss gelaufen sein. Fehlt die Spalte iso2, bricht diese
--   Datei mit einer Fehlermeldung ab und aendert nichts.
--
-- EIGENSCHAFTEN
--   * Idempotent durch INSERT IGNORE in Verbindung mit dem UNIQUE-Index auf
--     iso2: ein zweiter Lauf ueberspringt vorhandene Codes, es entstehen
--     keine Dubletten.
--   * Bereits vorhandene Eintraege werden NICHT ueberschrieben. Wer die
--     Namen aktualisieren will, muesste INSERT IGNORE durch
--     "ON DUPLICATE KEY UPDATE country_name = VALUES(country_name)"
--     ersetzen - das wuerde allerdings auch manuelle Aenderungen
--     zuruecksetzen.
--   * Die ids vergibt AUTO_INCREMENT. Das ist unkritisch, weil nirgends
--     eine feste Laender-id im Code steht.
--
-- SPRACHE
--   Die Namen sind deutsch. Sie gehen als Parameter country= an Nominatim
--   (map.js:154), um die Karte auf das gewaehlte Land zu zentrieren.
--   Nominatim versteht deutsche Namen; die Zuordnung zum Kartenausschnitt
--   erfolgt ohnehin ueber den Namen, die Staedtesuche dagegen ueber iso2.
--
-- AUSFUEHREN
--   mariadb -u <user> -p <datenbank> < migrations/004_country_seed.sql
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- Schutzpruefung: laeuft nur mit vorhandener Spalte iso2.
-- ---------------------------------------------------------------------------
DELIMITER $$

DROP PROCEDURE IF EXISTS `pruefe_voraussetzungen_004`$$

CREATE PROCEDURE `pruefe_voraussetzungen_004`()
BEGIN
    DECLARE spalte_da INT DEFAULT 0;

    SELECT COUNT(*) INTO spalte_da
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'country'
      AND COLUMN_NAME  = 'iso2';

    IF spalte_da = 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Abbruch: Spalte country.iso2 fehlt. Bitte zuerst Migration 003 ausfuehren.';
    END IF;
END$$

DELIMITER ;

CALL `pruefe_voraussetzungen_004`();
DROP PROCEDURE `pruefe_voraussetzungen_004`;

-- ---------------------------------------------------------------------------
-- Stammdaten, sortiert nach ISO-Code
-- ---------------------------------------------------------------------------
START TRANSACTION;

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

COMMIT;

-- ---------------------------------------------------------------------------
-- Auswertung
-- ---------------------------------------------------------------------------
SELECT
    COUNT(*)                                        AS `Laender in der Tabelle`,
    SUM(CASE WHEN `iso2` = '' THEN 1 ELSE 0 END)    AS `davon ohne ISO-Code`
FROM `country`;
