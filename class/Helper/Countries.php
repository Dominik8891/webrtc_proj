<?php
namespace App\Helper;

/**
 * Die Laendernamen.
 *
 * WARUM SIE HIER STEHEN UND NICHT IN DER DATENBANK
 * ------------------------------------------------
 * Sie standen in country.country_name. Aus einem Import mit falscher
 * Codepage waren sie dort unbrauchbar: "Österreich" lag als "├ûsterreich" in
 * der Tabelle - die UTF-8-Bytes C3 96 waren als CP437 gelesen und als deren
 * Zeichen (U+251C, U+00FB) neu gespeichert worden. Auf der Seite stand
 * deshalb vor jedem Umlaut ein senkrechter Strich: "|Äthiopien",
 * "Cura|çao". Betroffen war jeder Name mit einem Zeichen ausserhalb ASCII,
 * und nur der - "Afghanistan" sah richtig aus.
 *
 * Die Namen aus der Tabelle zu reparieren waere die kleinere Aenderung
 * gewesen und die schlechtere: Ein Name in einer Datenspalte ist eine
 * Uebersetzung, die dort nicht hingehoert. Er laesst sich nicht in zwei
 * Sprachen halten, ohne die Tabelle zu verdoppeln, er kann beim naechsten
 * Import wieder kippen, und er ist von aussen nicht nachpruefbar.
 *
 * HIER STEHT ER IM CODE: in der Versionsverwaltung, in einer Datei mit
 * bekannter Kodierung, in zwei Sprachen, und ein Test haelt ihn fest.
 * Die Kodierung der Tabelle kann die Anzeige nicht mehr beruehren.
 *
 * DER SCHLUESSEL IST DER ISO-CODE, nicht der Name. country.iso2 ist bereits
 * UNIQUE und war schon vorher das fachliche Schluesselmerkmal
 * (siehe migrations/003). country.country_name wird nicht mehr gelesen; die
 * Spalte bleibt liegen, damit kein Bestand verloren geht - database.sql
 * sagt das an der Spalte.
 *
 * WOHER DIE NAMEN KOMMEN
 * ----------------------
 * Deutsch: die Liste aus migrations/004, also die Schreibweise, die die
 *          Anwendung bisher gezeigt hat. In der Datei im Projekt war sie
 *          immer richtig - kaputt war nur, was davon in der Datenbank
 *          ankam.
 * Englisch: CLDR (Unicode), erzeugt mit Intl.DisplayNames.
 *
 * ZWEISPRACHIG VON ANFANG AN, obwohl vorerst nur Deutsch angezeigt wird:
 * Die englische Fassung der Oberflaeche kommt, und 248 Namen zweimal
 * nachzutragen waere dieselbe Arbeit ein zweites Mal. Welche Sprache gilt,
 * entscheidet spaeter eine Stelle - bis dahin steht hier self::VORGABE.
 *
 * EINE NEUE SPRACHE ist ein dritter Schluessel je Eintrag. Ein Test prueft,
 * dass keiner fehlt.
 */
class Countries
{
    /** Wird benutzt, solange die Oberflaeche nur Deutsch kann. */
    public const VORGABE = 'de';

    /** Die Sprachen, die dieser Katalog fuehrt. */
    public const SPRACHEN = ['de', 'en'];

    /**
     * ISO 3166-1 alpha-2 => Name je Sprache.
     *
     * Dieselben 248 Laender, die assets/js/map.js in allowedCountryCodes
     * zulaesst - die von OpenStreetMap unterstuetzten Codes. Sortiert nach
     * dem Code, nicht nach dem Namen: Die Anzeige sortiert selbst, und nach
     * welchem der beiden Namen sie das tut, haengt an der Sprache.
     */
    private const NAMES = [
        'AD' => ['de' => 'Andorra',                                      'en' => 'Andorra'],
        'AE' => ['de' => 'Vereinigte Arabische Emirate',                 'en' => 'United Arab Emirates'],
        'AF' => ['de' => 'Afghanistan',                                  'en' => 'Afghanistan'],
        'AG' => ['de' => 'Antigua und Barbuda',                          'en' => 'Antigua & Barbuda'],
        'AI' => ['de' => 'Anguilla',                                     'en' => 'Anguilla'],
        'AL' => ['de' => 'Albanien',                                     'en' => 'Albania'],
        'AM' => ['de' => 'Armenien',                                     'en' => 'Armenia'],
        'AO' => ['de' => 'Angola',                                       'en' => 'Angola'],
        'AQ' => ['de' => 'Antarktis',                                    'en' => 'Antarctica'],
        'AR' => ['de' => 'Argentinien',                                  'en' => 'Argentina'],
        'AS' => ['de' => 'Amerikanisch-Samoa',                           'en' => 'American Samoa'],
        'AT' => ['de' => 'Österreich',                                   'en' => 'Austria'],
        'AU' => ['de' => 'Australien',                                   'en' => 'Australia'],
        'AW' => ['de' => 'Aruba',                                        'en' => 'Aruba'],
        'AX' => ['de' => 'Åland',                                        'en' => 'Åland Islands'],
        'AZ' => ['de' => 'Aserbaidschan',                                'en' => 'Azerbaijan'],
        'BA' => ['de' => 'Bosnien und Herzegowina',                      'en' => 'Bosnia & Herzegovina'],
        'BB' => ['de' => 'Barbados',                                     'en' => 'Barbados'],
        'BD' => ['de' => 'Bangladesch',                                  'en' => 'Bangladesh'],
        'BE' => ['de' => 'Belgien',                                      'en' => 'Belgium'],
        'BF' => ['de' => 'Burkina Faso',                                 'en' => 'Burkina Faso'],
        'BG' => ['de' => 'Bulgarien',                                    'en' => 'Bulgaria'],
        'BH' => ['de' => 'Bahrain',                                      'en' => 'Bahrain'],
        'BI' => ['de' => 'Burundi',                                      'en' => 'Burundi'],
        'BJ' => ['de' => 'Benin',                                        'en' => 'Benin'],
        'BL' => ['de' => 'Saint-Barthélemy',                             'en' => 'St. Barthélemy'],
        'BM' => ['de' => 'Bermuda',                                      'en' => 'Bermuda'],
        'BN' => ['de' => 'Brunei Darussalam',                            'en' => 'Brunei'],
        'BO' => ['de' => 'Bolivien',                                     'en' => 'Bolivia'],
        'BQ' => ['de' => 'Bonaire, Sint Eustatius und Saba',             'en' => 'Caribbean Netherlands'],
        'BR' => ['de' => 'Brasilien',                                    'en' => 'Brazil'],
        'BS' => ['de' => 'Bahamas',                                      'en' => 'Bahamas'],
        'BT' => ['de' => 'Bhutan',                                       'en' => 'Bhutan'],
        'BV' => ['de' => 'Bouvetinsel',                                  'en' => 'Bouvet Island'],
        'BW' => ['de' => 'Botsuana',                                     'en' => 'Botswana'],
        'BY' => ['de' => 'Belarus',                                      'en' => 'Belarus'],
        'BZ' => ['de' => 'Belize',                                       'en' => 'Belize'],
        'CA' => ['de' => 'Kanada',                                       'en' => 'Canada'],
        'CC' => ['de' => 'Kokosinseln',                                  'en' => 'Cocos (Keeling) Islands'],
        'CD' => ['de' => 'Kongo, Demokratische Republik',                'en' => 'Congo - Kinshasa'],
        'CF' => ['de' => 'Zentralafrikanische Republik',                 'en' => 'Central African Republic'],
        'CG' => ['de' => 'Kongo, Republik',                              'en' => 'Congo - Brazzaville'],
        'CH' => ['de' => 'Schweiz',                                      'en' => 'Switzerland'],
        'CI' => ['de' => 'Elfenbeinküste',                               'en' => 'Côte d’Ivoire'],
        'CK' => ['de' => 'Cookinseln',                                   'en' => 'Cook Islands'],
        'CL' => ['de' => 'Chile',                                        'en' => 'Chile'],
        'CM' => ['de' => 'Kamerun',                                      'en' => 'Cameroon'],
        'CN' => ['de' => 'China',                                        'en' => 'China'],
        'CO' => ['de' => 'Kolumbien',                                    'en' => 'Colombia'],
        'CR' => ['de' => 'Costa Rica',                                   'en' => 'Costa Rica'],
        'CU' => ['de' => 'Kuba',                                         'en' => 'Cuba'],
        'CV' => ['de' => 'Cabo Verde',                                   'en' => 'Cape Verde'],
        'CW' => ['de' => 'Curaçao',                                      'en' => 'Curaçao'],
        'CX' => ['de' => 'Weihnachtsinsel',                              'en' => 'Christmas Island'],
        'CY' => ['de' => 'Zypern',                                       'en' => 'Cyprus'],
        'CZ' => ['de' => 'Tschechien',                                   'en' => 'Czechia'],
        'DE' => ['de' => 'Deutschland',                                  'en' => 'Germany'],
        'DJ' => ['de' => 'Dschibuti',                                    'en' => 'Djibouti'],
        'DK' => ['de' => 'Dänemark',                                     'en' => 'Denmark'],
        'DM' => ['de' => 'Dominica',                                     'en' => 'Dominica'],
        'DO' => ['de' => 'Dominikanische Republik',                      'en' => 'Dominican Republic'],
        'DZ' => ['de' => 'Algerien',                                     'en' => 'Algeria'],
        'EC' => ['de' => 'Ecuador',                                      'en' => 'Ecuador'],
        'EE' => ['de' => 'Estland',                                      'en' => 'Estonia'],
        'EG' => ['de' => 'Ägypten',                                      'en' => 'Egypt'],
        'EH' => ['de' => 'Westsahara',                                   'en' => 'Western Sahara'],
        'ER' => ['de' => 'Eritrea',                                      'en' => 'Eritrea'],
        'ES' => ['de' => 'Spanien',                                      'en' => 'Spain'],
        'ET' => ['de' => 'Äthiopien',                                    'en' => 'Ethiopia'],
        'FI' => ['de' => 'Finnland',                                     'en' => 'Finland'],
        'FJ' => ['de' => 'Fidschi',                                      'en' => 'Fiji'],
        'FK' => ['de' => 'Falklandinseln',                               'en' => 'Falkland Islands'],
        'FM' => ['de' => 'Mikronesien',                                  'en' => 'Micronesia'],
        'FO' => ['de' => 'Färöer',                                       'en' => 'Faroe Islands'],
        'FR' => ['de' => 'Frankreich',                                   'en' => 'France'],
        'GA' => ['de' => 'Gabun',                                        'en' => 'Gabon'],
        'GB' => ['de' => 'Vereinigtes Königreich',                       'en' => 'United Kingdom'],
        'GD' => ['de' => 'Grenada',                                      'en' => 'Grenada'],
        'GE' => ['de' => 'Georgien',                                     'en' => 'Georgia'],
        'GF' => ['de' => 'Französisch-Guayana',                          'en' => 'French Guiana'],
        'GG' => ['de' => 'Guernsey',                                     'en' => 'Guernsey'],
        'GH' => ['de' => 'Ghana',                                        'en' => 'Ghana'],
        'GI' => ['de' => 'Gibraltar',                                    'en' => 'Gibraltar'],
        'GL' => ['de' => 'Grönland',                                     'en' => 'Greenland'],
        'GM' => ['de' => 'Gambia',                                       'en' => 'Gambia'],
        'GN' => ['de' => 'Guinea',                                       'en' => 'Guinea'],
        'GP' => ['de' => 'Guadeloupe',                                   'en' => 'Guadeloupe'],
        'GQ' => ['de' => 'Äquatorialguinea',                             'en' => 'Equatorial Guinea'],
        'GR' => ['de' => 'Griechenland',                                 'en' => 'Greece'],
        'GS' => ['de' => 'Südgeorgien und die Südlichen Sandwichinseln', 'en' => 'South Georgia & South Sandwich Islands'],
        'GT' => ['de' => 'Guatemala',                                    'en' => 'Guatemala'],
        'GU' => ['de' => 'Guam',                                         'en' => 'Guam'],
        'GW' => ['de' => 'Guinea-Bissau',                                'en' => 'Guinea-Bissau'],
        'GY' => ['de' => 'Guyana',                                       'en' => 'Guyana'],
        'HK' => ['de' => 'Hongkong',                                     'en' => 'Hong Kong SAR China'],
        'HM' => ['de' => 'Heard und McDonaldinseln',                     'en' => 'Heard & McDonald Islands'],
        'HN' => ['de' => 'Honduras',                                     'en' => 'Honduras'],
        'HR' => ['de' => 'Kroatien',                                     'en' => 'Croatia'],
        'HT' => ['de' => 'Haiti',                                        'en' => 'Haiti'],
        'HU' => ['de' => 'Ungarn',                                       'en' => 'Hungary'],
        'ID' => ['de' => 'Indonesien',                                   'en' => 'Indonesia'],
        'IE' => ['de' => 'Irland',                                       'en' => 'Ireland'],
        'IL' => ['de' => 'Israel',                                       'en' => 'Israel'],
        'IM' => ['de' => 'Isle of Man',                                  'en' => 'Isle of Man'],
        'IN' => ['de' => 'Indien',                                       'en' => 'India'],
        'IO' => ['de' => 'Britisches Territorium im Indischen Ozean',    'en' => 'British Indian Ocean Territory'],
        'IQ' => ['de' => 'Irak',                                         'en' => 'Iraq'],
        'IR' => ['de' => 'Iran',                                         'en' => 'Iran'],
        'IS' => ['de' => 'Island',                                       'en' => 'Iceland'],
        'IT' => ['de' => 'Italien',                                      'en' => 'Italy'],
        'JE' => ['de' => 'Jersey',                                       'en' => 'Jersey'],
        'JM' => ['de' => 'Jamaika',                                      'en' => 'Jamaica'],
        'JO' => ['de' => 'Jordanien',                                    'en' => 'Jordan'],
        'JP' => ['de' => 'Japan',                                        'en' => 'Japan'],
        'KE' => ['de' => 'Kenia',                                        'en' => 'Kenya'],
        'KG' => ['de' => 'Kirgisistan',                                  'en' => 'Kyrgyzstan'],
        'KH' => ['de' => 'Kambodscha',                                   'en' => 'Cambodia'],
        'KI' => ['de' => 'Kiribati',                                     'en' => 'Kiribati'],
        'KM' => ['de' => 'Komoren',                                      'en' => 'Comoros'],
        'KN' => ['de' => 'St. Kitts und Nevis',                          'en' => 'St. Kitts & Nevis'],
        'KP' => ['de' => 'Korea, Demokratische Volksrepublik',           'en' => 'North Korea'],
        'KR' => ['de' => 'Korea, Republik',                              'en' => 'South Korea'],
        'KW' => ['de' => 'Kuwait',                                       'en' => 'Kuwait'],
        'KY' => ['de' => 'Kaimaninseln',                                 'en' => 'Cayman Islands'],
        'KZ' => ['de' => 'Kasachstan',                                   'en' => 'Kazakhstan'],
        'LA' => ['de' => 'Laos',                                         'en' => 'Laos'],
        'LB' => ['de' => 'Libanon',                                      'en' => 'Lebanon'],
        'LC' => ['de' => 'St. Lucia',                                    'en' => 'St. Lucia'],
        'LI' => ['de' => 'Liechtenstein',                                'en' => 'Liechtenstein'],
        'LK' => ['de' => 'Sri Lanka',                                    'en' => 'Sri Lanka'],
        'LR' => ['de' => 'Liberia',                                      'en' => 'Liberia'],
        'LS' => ['de' => 'Lesotho',                                      'en' => 'Lesotho'],
        'LT' => ['de' => 'Litauen',                                      'en' => 'Lithuania'],
        'LU' => ['de' => 'Luxemburg',                                    'en' => 'Luxembourg'],
        'LV' => ['de' => 'Lettland',                                     'en' => 'Latvia'],
        'LY' => ['de' => 'Libyen',                                       'en' => 'Libya'],
        'MA' => ['de' => 'Marokko',                                      'en' => 'Morocco'],
        'MC' => ['de' => 'Monaco',                                       'en' => 'Monaco'],
        'MD' => ['de' => 'Moldau',                                       'en' => 'Moldova'],
        'ME' => ['de' => 'Montenegro',                                   'en' => 'Montenegro'],
        'MF' => ['de' => 'Saint-Martin',                                 'en' => 'St. Martin'],
        'MG' => ['de' => 'Madagaskar',                                   'en' => 'Madagascar'],
        'MH' => ['de' => 'Marshallinseln',                               'en' => 'Marshall Islands'],
        'MK' => ['de' => 'Nordmazedonien',                               'en' => 'North Macedonia'],
        'ML' => ['de' => 'Mali',                                         'en' => 'Mali'],
        'MM' => ['de' => 'Myanmar',                                      'en' => 'Myanmar (Burma)'],
        'MN' => ['de' => 'Mongolei',                                     'en' => 'Mongolia'],
        'MO' => ['de' => 'Macau',                                        'en' => 'Macao SAR China'],
        'MP' => ['de' => 'Nördliche Marianen',                           'en' => 'Northern Mariana Islands'],
        'MQ' => ['de' => 'Martinique',                                   'en' => 'Martinique'],
        'MR' => ['de' => 'Mauretanien',                                  'en' => 'Mauritania'],
        'MS' => ['de' => 'Montserrat',                                   'en' => 'Montserrat'],
        'MT' => ['de' => 'Malta',                                        'en' => 'Malta'],
        'MU' => ['de' => 'Mauritius',                                    'en' => 'Mauritius'],
        'MV' => ['de' => 'Malediven',                                    'en' => 'Maldives'],
        'MW' => ['de' => 'Malawi',                                       'en' => 'Malawi'],
        'MX' => ['de' => 'Mexiko',                                       'en' => 'Mexico'],
        'MY' => ['de' => 'Malaysia',                                     'en' => 'Malaysia'],
        'MZ' => ['de' => 'Mosambik',                                     'en' => 'Mozambique'],
        'NA' => ['de' => 'Namibia',                                      'en' => 'Namibia'],
        'NC' => ['de' => 'Neukaledonien',                                'en' => 'New Caledonia'],
        'NE' => ['de' => 'Niger',                                        'en' => 'Niger'],
        'NF' => ['de' => 'Norfolkinsel',                                 'en' => 'Norfolk Island'],
        'NG' => ['de' => 'Nigeria',                                      'en' => 'Nigeria'],
        'NI' => ['de' => 'Nicaragua',                                    'en' => 'Nicaragua'],
        'NL' => ['de' => 'Niederlande',                                  'en' => 'Netherlands'],
        'NO' => ['de' => 'Norwegen',                                     'en' => 'Norway'],
        'NP' => ['de' => 'Nepal',                                        'en' => 'Nepal'],
        'NR' => ['de' => 'Nauru',                                        'en' => 'Nauru'],
        'NU' => ['de' => 'Niue',                                         'en' => 'Niue'],
        'NZ' => ['de' => 'Neuseeland',                                   'en' => 'New Zealand'],
        'OM' => ['de' => 'Oman',                                         'en' => 'Oman'],
        'PA' => ['de' => 'Panama',                                       'en' => 'Panama'],
        'PE' => ['de' => 'Peru',                                         'en' => 'Peru'],
        'PF' => ['de' => 'Französisch-Polynesien',                       'en' => 'French Polynesia'],
        'PG' => ['de' => 'Papua-Neuguinea',                              'en' => 'Papua New Guinea'],
        'PH' => ['de' => 'Philippinen',                                  'en' => 'Philippines'],
        'PK' => ['de' => 'Pakistan',                                     'en' => 'Pakistan'],
        'PL' => ['de' => 'Polen',                                        'en' => 'Poland'],
        'PM' => ['de' => 'Saint-Pierre und Miquelon',                    'en' => 'St. Pierre & Miquelon'],
        'PN' => ['de' => 'Pitcairninseln',                               'en' => 'Pitcairn Islands'],
        'PR' => ['de' => 'Puerto Rico',                                  'en' => 'Puerto Rico'],
        'PS' => ['de' => 'Palästina',                                    'en' => 'Palestinian Territories'],
        'PT' => ['de' => 'Portugal',                                     'en' => 'Portugal'],
        'PW' => ['de' => 'Palau',                                        'en' => 'Palau'],
        'PY' => ['de' => 'Paraguay',                                     'en' => 'Paraguay'],
        'QA' => ['de' => 'Katar',                                        'en' => 'Qatar'],
        'RE' => ['de' => 'Réunion',                                      'en' => 'Réunion'],
        'RO' => ['de' => 'Rumänien',                                     'en' => 'Romania'],
        'RS' => ['de' => 'Serbien',                                      'en' => 'Serbia'],
        'RU' => ['de' => 'Russland',                                     'en' => 'Russia'],
        'RW' => ['de' => 'Ruanda',                                       'en' => 'Rwanda'],
        'SA' => ['de' => 'Saudi-Arabien',                                'en' => 'Saudi Arabia'],
        'SB' => ['de' => 'Salomonen',                                    'en' => 'Solomon Islands'],
        'SC' => ['de' => 'Seychellen',                                   'en' => 'Seychelles'],
        'SD' => ['de' => 'Sudan',                                        'en' => 'Sudan'],
        'SE' => ['de' => 'Schweden',                                     'en' => 'Sweden'],
        'SG' => ['de' => 'Singapur',                                     'en' => 'Singapore'],
        'SH' => ['de' => 'St. Helena, Ascension und Tristan da Cunha',   'en' => 'St. Helena'],
        'SI' => ['de' => 'Slowenien',                                    'en' => 'Slovenia'],
        'SJ' => ['de' => 'Svalbard und Jan Mayen',                       'en' => 'Svalbard & Jan Mayen'],
        'SK' => ['de' => 'Slowakei',                                     'en' => 'Slovakia'],
        'SL' => ['de' => 'Sierra Leone',                                 'en' => 'Sierra Leone'],
        'SM' => ['de' => 'San Marino',                                   'en' => 'San Marino'],
        'SN' => ['de' => 'Senegal',                                      'en' => 'Senegal'],
        'SO' => ['de' => 'Somalia',                                      'en' => 'Somalia'],
        'SR' => ['de' => 'Suriname',                                     'en' => 'Suriname'],
        'SS' => ['de' => 'Südsudan',                                     'en' => 'South Sudan'],
        'ST' => ['de' => 'São Tomé und Príncipe',                        'en' => 'São Tomé & Príncipe'],
        'SV' => ['de' => 'El Salvador',                                  'en' => 'El Salvador'],
        'SX' => ['de' => 'Sint Maarten',                                 'en' => 'Sint Maarten'],
        'SY' => ['de' => 'Syrien',                                       'en' => 'Syria'],
        'TC' => ['de' => 'Turks- und Caicosinseln',                      'en' => 'Turks & Caicos Islands'],
        'TD' => ['de' => 'Tschad',                                       'en' => 'Chad'],
        'TF' => ['de' => 'Französische Südgebiete',                      'en' => 'French Southern Territories'],
        'TG' => ['de' => 'Togo',                                         'en' => 'Togo'],
        'TH' => ['de' => 'Thailand',                                     'en' => 'Thailand'],
        'TJ' => ['de' => 'Tadschikistan',                                'en' => 'Tajikistan'],
        'TK' => ['de' => 'Tokelau',                                      'en' => 'Tokelau'],
        'TL' => ['de' => 'Timor-Leste',                                  'en' => 'Timor-Leste'],
        'TM' => ['de' => 'Turkmenistan',                                 'en' => 'Turkmenistan'],
        'TN' => ['de' => 'Tunesien',                                     'en' => 'Tunisia'],
        'TO' => ['de' => 'Tonga',                                        'en' => 'Tonga'],
        'TR' => ['de' => 'Türkei',                                       'en' => 'Türkiye'],
        'TT' => ['de' => 'Trinidad und Tobago',                          'en' => 'Trinidad & Tobago'],
        'TV' => ['de' => 'Tuvalu',                                       'en' => 'Tuvalu'],
        'TW' => ['de' => 'Taiwan',                                       'en' => 'Taiwan'],
        'TZ' => ['de' => 'Tansania',                                     'en' => 'Tanzania'],
        'UA' => ['de' => 'Ukraine',                                      'en' => 'Ukraine'],
        'UG' => ['de' => 'Uganda',                                       'en' => 'Uganda'],
        'UM' => ['de' => 'Amerikanische Überseeinseln',                  'en' => 'U.S. Outlying Islands'],
        'US' => ['de' => 'Vereinigte Staaten',                           'en' => 'United States'],
        'UY' => ['de' => 'Uruguay',                                      'en' => 'Uruguay'],
        'UZ' => ['de' => 'Usbekistan',                                   'en' => 'Uzbekistan'],
        'VA' => ['de' => 'Vatikanstadt',                                 'en' => 'Vatican City'],
        'VC' => ['de' => 'St. Vincent und die Grenadinen',               'en' => 'St. Vincent & Grenadines'],
        'VE' => ['de' => 'Venezuela',                                    'en' => 'Venezuela'],
        'VG' => ['de' => 'Britische Jungferninseln',                     'en' => 'British Virgin Islands'],
        'VI' => ['de' => 'Amerikanische Jungferninseln',                 'en' => 'U.S. Virgin Islands'],
        'VN' => ['de' => 'Vietnam',                                      'en' => 'Vietnam'],
        'VU' => ['de' => 'Vanuatu',                                      'en' => 'Vanuatu'],
        'WF' => ['de' => 'Wallis und Futuna',                            'en' => 'Wallis & Futuna'],
        'WS' => ['de' => 'Samoa',                                        'en' => 'Samoa'],
        'YE' => ['de' => 'Jemen',                                        'en' => 'Yemen'],
        'YT' => ['de' => 'Mayotte',                                      'en' => 'Mayotte'],
        'ZA' => ['de' => 'Südafrika',                                    'en' => 'South Africa'],
        'ZM' => ['de' => 'Sambia',                                       'en' => 'Zambia'],
        'ZW' => ['de' => 'Simbabwe',                                     'en' => 'Zimbabwe'],
    ];

    /**
     * Macht aus einer Eingabe einen Code, wie er in diesem Katalog steht.
     *
     * @param mixed $in_iso2
     * @return string 'AT' - oder ein Leerstring, wenn es keiner ist
     */
    public static function normalize($in_iso2): string
    {
        if (!is_string($in_iso2)) return '';
        $code = strtoupper(trim($in_iso2));
        return preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : '';
    }

    /**
     * Ist das ein bekannter Code?
     *
     * @param mixed $in_iso2
     * @return bool
     */
    public static function isKnown($in_iso2): bool
    {
        $code = self::normalize($in_iso2);
        return $code !== '' && isset(self::NAMES[$code]);
    }

    /**
     * Der Name zu einem Code.
     *
     * UNBEKANNT ERGIBT DEN CODE SELBST, nicht einen Leerstring - dieselbe
     * Entscheidung wie in App\Helper\Languages::name(). Ein Standort in
     * einem Land, das dieser Katalog nicht kennt, zeigt dann "XY" statt
     * einer Luecke; das ist wenig, aber es ist eine Auskunft, und es faellt
     * auf.
     *
     * @param mixed  $in_iso2
     * @param string $in_sprache Fehlt sie im Katalog, gilt self::VORGABE
     * @return string
     */
    public static function name($in_iso2, string $in_sprache = self::VORGABE): string
    {
        $code = self::normalize($in_iso2);
        if ($code === '' || !isset(self::NAMES[$code])) return $code;

        $eintrag = self::NAMES[$code];
        return $eintrag[$in_sprache] ?? $eintrag[self::VORGABE];
    }

    /**
     * Alle Laender als Code => Name.
     *
     * @param string $in_sprache
     * @return array<string,string>
     */
    public static function all(string $in_sprache = self::VORGABE): array
    {
        $liste = [];
        foreach (self::NAMES as $code => $eintrag) {
            $liste[$code] = $eintrag[$in_sprache] ?? $eintrag[self::VORGABE];
        }
        return $liste;
    }

    /**
     * Schreibt in Datenbankzeilen den Namen aus diesem Katalog.
     *
     * DIE EINE STELLE, an der eine gelesene Zeile ihren Laendernamen
     * bekommt. Die Abfragen holen weiterhin country.country_name mit - dort
     * steht der kaputte Wert -, und hier wird er ersetzt, bevor die Zeile
     * irgendwohin geht: in eine Ansicht, in eine JSON-Antwort, in die
     * Verwaltungsliste. Deshalb muss keine Ansicht und kein JS-Modul etwas
     * davon wissen.
     *
     * Ohne iso2 in der Zeile bleibt der vorhandene Wert stehen. Das ist der
     * ehrlichere Rueckfall: Eine Abfrage, die den Code nicht mitholt, soll
     * auffallen (der Strich ist dann wieder da) und nicht stillschweigend
     * einen leeren Ort zeigen.
     *
     * @param array<int,array<string,mixed>> $in_zeilen
     * @param string                         $in_sprache
     * @return array<int,array<string,mixed>>
     */
    public static function zeilenNamenSetzen(array $in_zeilen, string $in_sprache = self::VORGABE): array
    {
        foreach ($in_zeilen as &$zeile) {
            if (!is_array($zeile)) continue;
            if (!array_key_exists('iso2', $zeile)) continue;
            if (!self::isKnown($zeile['iso2'])) continue;
            $zeile['country_name'] = self::name($zeile['iso2'], $in_sprache);
        }
        unset($zeile);

        return $in_zeilen;
    }

    /**
     * Sortiert Zeilen nach dem angezeigten Laendernamen.
     *
     * WARUM NICHT strcmp UND WARUM NICHT strcoll
     * ------------------------------------------
     * strcmp vergleicht Bytes. "Österreich" faengt in UTF-8 mit C3 an und
     * stuende damit hinter "Zypern" - eine Liste, deren Reihenfolge sich
     * niemandem erschliesst. strcoll koennte es richtig, braucht dafuer aber
     * ein gesetztes Locale (setlocale); ohne eines faellt es auf denselben
     * Bytevergleich zurueck, und diese Anwendung setzt keines. Ein
     * Collator aus ext-intl waere die saubere Loesung - die Erweiterung
     * steht nicht in composer.json und soll dafuer nicht dazukommen.
     *
     * Deshalb ein Schluessel: Die Zeichen mit Strichen und Haken werden auf
     * ihren Grundbuchstaben zurueckgefuehrt, danach reicht strcmp. Das ist
     * die Duden-Reihenfolge - Ä zaehlt wie A, Ö wie O -, und fuer die
     * Laendernamen dieses Katalogs genau das Richtige.
     *
     * STABIL: Zeilen mit demselben Land behalten die Reihenfolge, in der sie
     * ankamen (usort ist seit PHP 8.0 stabil). Wer vorher nach Stadt
     * sortiert hat, verliert das hier nicht.
     *
     * @param array<int,array<string,mixed>> $in_zeilen
     * @return array<int,array<string,mixed>>
     */
    public static function sortiereNachName(array $in_zeilen): array
    {
        usort($in_zeilen, static function ($a, $b): int {
            $links  = is_array($a) ? (string)($a['country_name'] ?? '') : '';
            $rechts = is_array($b) ? (string)($b['country_name'] ?? '') : '';
            return strcmp(self::sortierschluessel($links), self::sortierschluessel($rechts));
        });

        return $in_zeilen;
    }

    /**
     * Der Schluessel, nach dem sortiert wird.
     *
     * Grossbuchstaben, und die Zeichen mit Strichen und Haken auf ihren
     * Grundbuchstaben zurueckgefuehrt. Die Liste deckt ab, was in den Namen
     * dieses Katalogs vorkommt - deutsch wie englisch.
     *
     * @param string $in_name
     * @return string
     */
    private static function sortierschluessel(string $in_name): string
    {
        $ersatz = [
            'Ä' => 'A', 'Å' => 'A', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A',
            'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Î' => 'I',
            'Ñ' => 'N', 'Ö' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ø' => 'O',
            'Ú' => 'U', 'Ü' => 'U', 'Û' => 'U', 'ß' => 'SS',
            // Der typografische Apostroph aus den CLDR-Namen
            // ("Côte d’Ivoire") zaehlt wie der gerade.
            '’' => "'",
        ];

        return strtr(mb_strtoupper($in_name, 'UTF-8'), $ersatz);
    }

    /**
     * Dasselbe fuer eine einzelne Zeile.
     *
     * @param array<string,mixed> $in_zeile
     * @param string              $in_sprache
     * @return array<string,mixed>
     */
    public static function zeileNamenSetzen(array $in_zeile, string $in_sprache = self::VORGABE): array
    {
        $zeilen = self::zeilenNamenSetzen([$in_zeile], $in_sprache);
        return $zeilen[0];
    }
}
