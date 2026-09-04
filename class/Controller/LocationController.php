<?php
namespace App\Controller;

use App\Model\Location;
use App\Model\LocationImage;
use App\Model\TourRequest;
use App\Helper\Auth;
use App\Helper\ImageStore;
use App\Helper\Languages;
use App\Helper\LocationView;
use App\Helper\Permission;
use App\Helper\Request;
use App\Helper\ViewHelper;

/**
 * LocationController – Standorte anlegen, anzeigen, ändern, löschen, sperren.
 *
 * WAS HIER STEHT UND WAS NICHT
 * ----------------------------
 * Dieser Controller entscheidet, WER etwas sehen und ändern darf und WAS
 * gültige Eingaben sind. Wie die Standortseite AUSSIEHT, steht in
 * App\Helper\LocationView - einer Klasse aus reinen Funktionen, die weder
 * Sitzung noch Anfrage noch Datenbank kennt. Sie bekommt hier übergeben, was
 * sie zeigen darf; ob ein Gast die user_id des Guides bekommt, wird in
 * showLocationPage() entschieden und nirgends sonst.
 *
 * Zusammen waren das 1700 Zeilen in einer Datei, und die beiden Fragen
 * standen ineinander.
 *
 * Der Zugang zu den Routen wird in index.php über die Rechte aus
 * config/routes.php entschieden. Was hier zusätzlich geprüft wird, ist das
 * EIGENTUM an einem Standort - eine Rechtetabelle kann nicht wissen, welcher
 * Datensatz wem gehört.
 *
 * Die Eigentumsprüfung findet zweimal statt, und das ist Absicht:
 *   1. Hier im Controller, damit der Aufrufer eine verständliche Antwort
 *      bekommt.
 *   2. In der WHERE-Klausel des Statements (App\Model\Location). Das ist die
 *      verbindliche Prüfung. Vorher standen dort nur `WHERE id = :id` -
 *      wer die Prüfung im Controller umging oder wer sie beim Ergänzen einer
 *      neuen Aufrufstelle vergaß, änderte fremde Standorte.
 */
class LocationController
{
    /**
     * Schluessel, unter dem die abgelehnten Formulareingaben bis zum
     * naechsten Aufruf des Formulars in der Sitzung liegen.
     */
    private const EINGABEN = 'set_location_eingaben';

    // -----------------------------------------------------------------
    // Die Grenzen der Textfelder. Sie stehen HIER und nicht verstreut in
    // den Pruefungen: Anlegen und Bearbeiten sind zwei Wege zu denselben
    // Feldern, und zwei Wege mit zwei Zahlenpaaren waeren ein Standort, den
    // man anlegen, aber nicht mehr speichern kann.
    //
    // Die Obergrenzen passen zu den Spalten aus migrations/011:
    // title ist varchar(120), description_long ist text.
    // -----------------------------------------------------------------

    /** Ueberschrift des Angebots. */
    private const TITEL_MIN = 3;
    private const TITEL_MAX = 120;

    /**
     * Die kurze Beschreibung - die eine Zeile fuer Kartenfenster und Liste.
     *
     * Die Untergrenze von 5 Zeichen ist die alte und bleibt: Sie hat
     * "Standorte" verhindert, deren Beschreibung "x" lautete. Die Obergrenze
     * ist neu und gehoert zur Aufgabe des Feldes - ein Absatz in einem
     * Kartenfenster ist unlesbar, dafuer gibt es jetzt den langen Text.
     */
    private const KURZ_MIN = 5;
    private const KURZ_MAX = 200;

    /**
     * Die ausfuehrliche Beschreibung.
     *
     * Keine Untergrenze: Sie darf leer bleiben. Ein Guide, der noch nichts
     * geschrieben hat, soll seinen Standort trotzdem speichern koennen -
     * sonst schreibt er "noch nichts" hinein, und das ist schlechter als
     * nichts.
     */
    private const LANG_MAX = 5000;

    /**
     * Uebliche Dauer in Minuten.
     *
     * Unter fuenf Minuten ist keine Fuehrung, ueber acht Stunden auch nicht.
     * Die Grenzen fangen den Vertipper ab (500 statt 50), nicht den
     * Boesewicht - der Wert steht in einer Spalte, die nichts ausloest.
     */
    private const DAUER_MIN = 5;
    private const DAUER_MAX = 480;

    /**
     * Womit das Dauerfeld vorbelegt ist.
     *
     * EIGENE KONSTANTE, obwohl sie heute dieselbe Zahl traegt wie
     * DAUER_MIN: Das sind zwei verschiedene Aussagen. Die Untergrenze sagt
     * "kuerzer geht nicht", die Vorgabe sagt "das steht im Feld, solange
     * niemand etwas anderes eintraegt". Wer die eine aendert, meint selten
     * die andere mit.
     *
     * WAS DAS BEDEUTET: Ein Guide, der das Feld nicht anfasst, speichert
     * fuenf Minuten - und auf der Standortseite steht dann "Dauer: 5
     * Minuten". Das ist eine Aussage. "Nicht angegeben" (NULL in der Spalte,
     * und die Seite erwaehnt die Dauer dann gar nicht) kommt nur noch
     * zustande, wenn er das Feld ausdruecklich leert.
     */
    private const DAUER_VORGABE = 5;

    /**
     * Legt die Eingaben eines abgelehnten Formulars in der Sitzung ab.
     *
     * WARUM UEBERHAUPT
     * ----------------
     * setLocation() antwortet auf eine Ablehnung mit einer Weiterleitung
     * zurueck aufs Formular (Post/Redirect/Get - damit ein Neuladen den
     * Standort nicht ein zweites Mal anlegt). Der Preis dieses Musters ist,
     * dass der POST-Rumpf dabei verlorengeht: Der Nutzer stand vor einem
     * leeren Formular und musste die Beschreibung noch einmal tippen, obwohl
     * nur die Koordinaten gefehlt hatten. Land und Stadt traf es genauso -
     * beide Listen baut erst assets/js/map.js auf, eine Auswahl ueberlebte
     * den Ruecksprung nicht. Die Werte reisen deshalb ueber die Sitzung mit.
     *
     * Nicht ueber die URL: Eine Beschreibung gehoert nicht in die
     * Adresszeile, ins Server-Log und in den Verlauf.
     *
     * @param array<string,string> $werte Die Felder des Formulars
     * @return void
     */
    private static function merkeEingaben(array $werte): void
    {
        $_SESSION[self::EINGABEN] = $werte;
    }

    /**
     * Verwirft die gemerkten Eingaben.
     *
     * Noetig auf dem ERFOLGSWEG: Gemerkt wird vor den Pruefungen, damit
     * keine Ablehnung den Rueckweg vergessen kann. Ohne dieses Wegraeumen
     * laege der eben gespeicherte Standort noch in der Sitzung und stuende
     * beim naechsten, voellig unabhaengigen Aufruf des Formulars wieder
     * darin.
     *
     * @return void
     */
    private static function vergissEingaben(): void
    {
        unset($_SESSION[self::EINGABEN]);
    }

    /**
     * Holt die gemerkten Eingaben und loescht sie dabei.
     *
     * Das Loeschen gehoert zum Holen: Sonst haenge die alte Beschreibung
     * beim naechsten, voellig unabhaengigen Aufruf des Formulars wieder
     * darin - der Nutzer haette ein Feld vorbelegt, das er nie gefuellt hat.
     *
     * @return array<string,string> Leeres Array, wenn nichts gemerkt wurde
     */
    private static function holeEingaben(): array
    {
        $werte = $_SESSION[self::EINGABEN] ?? [];
        unset($_SESSION[self::EINGABEN]);

        return is_array($werte) ? $werte : [];
    }

    /**
     * Setzt die gemerkten Eingaben in die Vorlage ein.
     *
     * Oeffentlich und ohne Seiteneffekte, damit sich die Ersetzung samt
     * Maskierung pruefen laesst, ohne eine Seite auszuliefern
     * (tests/server_test.php).
     *
     * Jeder Wert geht durch htmlspecialchars(): Er landet in einem
     * value=""- bzw. data-""-Attribut, und dort beendet ein
     * Anfuehrungszeichen sonst das Attribut. Die Beschreibung ist freier
     * Text des Nutzers - genau der Fall, in dem das ausgenutzt wuerde.
     * ENT_QUOTES fasst auch das einfache Anfuehrungszeichen.
     *
     * @param string               $vorlage Inhalt von set_location.html
     * @param array<string,string> $werte   Rueckgabe von holeEingaben()
     * @return string Die Vorlage ohne Platzhalter
     */
    public static function fuelleFormular(string $vorlage, array $werte): string
    {
        // Die Sprachauswahl steht NICHT in dieser Liste: Sie ist kein Wert in
        // einem Feld, sondern eine Reihe von Kaestchen, und die baut
        // sprachauswahlHtml() aus dem Katalog - siehe setLocationPage().
        $marken = [
            '###TITLE###'       => 'title',
            '###DESCRIPTION###' => 'description',
            '###LONGTEXT###'    => 'description_long',
            '###DURATION###'    => 'duration',
            '###LATITUDE###'    => 'latitude',
            '###LONGITUDE###'   => 'longitude',
            '###COUNTRY_ID###'  => 'country',
            '###CITY###'        => 'city',
        ];

        foreach ($marken as $marke => $feld) {
            $wert = $werte[$feld] ?? '';
            $wert = is_scalar($wert) ? (string)$wert : '';

            // Die Dauer ist das einzige Feld mit einer VORGABE: Ohne gemerkte
            // Eingabe steht dort nicht nichts, sondern DAUER_VORGABE.
            //
            // Nur wenn ueberhaupt nichts gemerkt ist. Hat der Nutzer das Feld
            // beim abgelehnten Versuch ausdruecklich geleert, bleibt es leer -
            // sonst schriebe der Ruecksprung ihm eine Angabe zurueck, die er
            // gerade weggenommen hat.
            if ($feld === 'duration' && !array_key_exists('duration', $werte)) {
                $wert = (string)self::DAUER_VORGABE;
            }
            $vorlage = str_replace(
                $marke,
                htmlspecialchars($wert, ENT_QUOTES, 'UTF-8'),
                $vorlage
            );
        }

        return $vorlage;
    }

    /**
     * Zeigt das Formular zum Setzen einer Location an.
     *
     * Zugang: Recht location.create, geprüft in index.php. Zusätzlich die
     * Zustimmung zu den geltenden Guide-Bedingungen - das kann eine
     * Rechtetabelle nicht wissen, weil es nicht an der Rolle hängt, sondern
     * an der Fassung, der dieses Konto zugestimmt hat
     * (GuideController::requireCurrentTerms).
     *
     * @return void
     */
    public function setLocationPage()
    {
        GuideController::requireCurrentTerms();

        // Die Eingaben einer abgelehnten Eingabe zurueck ins Formular. Ist
        // nichts gemerkt - der Normalfall -, bleiben alle Platzhalter leer.
        $werte = self::holeEingaben();

        $out = ViewHelper::template('assets/html/set_location.html');
        $out = self::fuelleFormular($out, $werte);
        // Die Sprachauswahl kommt fertig aus demselben Katalog wie das
        // Bearbeitungsformular (App\Helper\Languages). Angehakt ist, was
        // beim abgelehnten Versuch angehakt war.
        $out = str_replace('###LANGUAGES###',
            LocationView::sprachauswahlHtml($werte['languages'] ?? ''), $out);

        // Und die Grenzen der Felder aus denselben Konstanten, gegen die
        // pruefeInhalt() prueft. Ein Formular, das andere Zahlen anzeigt, als
        // der Server durchlaesst, gibt dem Nutzer eine Absage fuer eine
        // Eingabe, die das Feld ausdruecklich erlaubt hat.
        $out = str_replace(
            ['###TITLE_MAX###', '###SHORT_MAX###', '###LONG_MAX###',
             '###DURATION_MIN###', '###DURATION_MAX###'],
            [(string)self::TITEL_MAX, (string)self::KURZ_MAX, (string)self::LANG_MAX,
             (string)self::DAUER_MIN, (string)self::DAUER_MAX],
            $out
        );

        ViewHelper::output($out);
    }

    /**
     * Verarbeitet das Absenden des Location-Formulars: prüft die Eingaben und
     * legt den Standort an.
     *
     * Zugang: Recht location.create, geprüft in index.php - das haben nur
     * Guide und Admin. Die Rolle ändert sich hier NICHT mehr; darüber
     * entscheidet der Dialog in App\Controller\GuideController.
     *
     * Die Zustimmung wird auch hier geprüft und nicht nur beim Anzeigen des
     * Formulars: Ein POST erreicht diese Methode auch ohne den Umweg über die
     * Seite davor. Eine Prüfung, die sich umgehen lässt, indem man das
     * Formular überspringt, ist keine.
     *
     * @return void
     */
    public function setLocation()
    {
        GuideController::requireCurrentTerms();

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            $country_id  = Request::g('country');
            $city        = Request::g('city');
            $longitude   = Request::g('longitude');
            $latitude    = Request::g('latitude');
            $user_id     = Auth::userId();

            // Titel, Beschreibungen, Dauer und Sprachen kommen aus derselben
            // Pruefung, die auch das Bearbeiten benutzt - siehe
            // pruefeInhalt(). Anlegen und Bearbeiten sind zwei Wege zu
            // denselben Feldern; zwei Pruefungen waeren zwei Meinungen
            // darueber, was ein gueltiger Standort ist.
            $inhalt = self::pruefeInhalt();

            // Die Eingaben fuer den Fall merken, dass gleich abgelehnt wird.
            // Steht VOR den Pruefungen, damit keine davon den Rueckweg
            // vergessen kann: Wer spaeter eine dritte Pruefung ergaenzt, muss
            // dafuer nichts wissen. Der Erfolgsweg raeumt sie am Ende wieder
            // weg (vergissEingaben).
            //
            // Gemerkt wird die ROHE Eingabe und nicht das Ergebnis der
            // Pruefung: Wer 900 Minuten eingetippt hat, soll die 900 im Feld
            // wiederfinden und nicht ein leeres Feld - sonst weiss er nicht,
            // was beanstandet wurde.
            self::merkeEingaben([
                'country'          => $country_id,
                'city'             => $city,
                'latitude'         => $latitude,
                'longitude'        => $longitude,
                'title'            => Request::g('title'),
                'description'      => Request::g('description'),
                'description_long' => Request::g('description_long'),
                'duration'         => Request::g('duration'),
                'languages'        => Languages::normalize(self::rohSprachen()),
            ]);

            if (!$inhalt['ok']) {
                header('Location: index.php?act=set_location_page&success=' . $inhalt['code']);
                exit;
            }

            // Koordinaten pruefen. Sie duerfen NICHT als Leerstring in die
            // Spalten location.latitude/longitude laufen: das sind
            // decimal-Spalten, MySQL wandelt '' im Non-Strict-Mode zu
            // 0.00000000 - und 0/0 ist ein gueltiger Punkt im Atlantik.
            // Ein Standort ohne Koordinaten ist in dieser Anwendung zudem
            // unbrauchbar, weil die Kartenansicht sie zwingend braucht
            // (assets/js/locations_table.js:46-47). Deshalb Abbruch statt
            // NULL-Speicherung.
            // Der Bereich ist zusaetzlich begrenzt, weil latitude als
            // decimal(10,8) nur Werte bis +/-99,99999999 aufnehmen kann.
            if (!is_numeric($latitude)  || $latitude  < -90  || $latitude  > 90 ||
                !is_numeric($longitude) || $longitude < -180 || $longitude > 180) {
                header("Location: index.php?act=set_location_page&success=2");
                exit;
            }

            // Hier stand frueher der stille Aufstieg Zuschauer -> Guide: Wer
            // einen Standort anlegte, bekam die Guide-Rolle dazu, ohne je
            // gefragt worden zu sein. Guide zu sein heisst aber, sich vor Ort
            // von Fremden steuern zu lassen, und kuenftig haengt daran eine
            // Abrechnung - das darf keine Nebenwirkung eines Formulars sein.
            //
            // Die Rolle wird jetzt im Dialog entschieden
            // (App\Controller\GuideController, App\Model\GuideRole). Wer
            // hier ankommt, ist bereits Guide: Das Recht location.create haben
            // nur noch Guide und Admin, und index.php prueft es vor dem
            // Aufruf dieser Methode.
            $location = new Location();
            $location->setCountry($country_id);
            $location->setCity($city);
            $location->setLongitude($longitude);
            $location->setLatitude($latitude);
            self::uebernehmeInhalt($location, $inhalt['werte']);
            $neue_id = $location->setNewLocation($user_id, $country_id);

            // Gespeichert - die oben gemerkten Eingaben sind erledigt. Sonst
            // stuenden sie beim naechsten Aufruf des Formulars wieder darin.
            self::vergissEingaben();

            // AUF DIE STANDORTSEITE, nicht auf die Startseite. Wer gerade
            // einen Standort angelegt hat, will zwei Dinge: sehen, wie er
            // aussieht, und Bilder dazulegen - beides ist dort und nur dort.
            // Die Karte zeigt ihm eine Nadel unter vielen.
            //
            // Nur, wenn wirklich eine ID herauskam: Scheitert das Einfuegen,
            // fuehrte die Weiterleitung auf einen Standort, den es nicht
            // gibt, und der Nutzer bekaeme "Standort nicht gefunden" statt
            // einer Fehlermeldung.
            if ($neue_id) {
                header('Location: index.php?act=location&id=' . (int)$neue_id . '&gespeichert=1');
                exit;
            }

            // MIT act. Ohne den Parameter landete die Weiterleitung bei
            // index.php ohne Aktion - und index.php leitet dann auf
            // index.php?act=home weiter, wobei success=1 verlorengeht. Die
            // Erfolgsmeldung, die assets/js/main.js daran haengt, erschien
            // deshalb nie.
            header("Location: index.php?act=home&success=4");
            exit;
        }
    }

    /**
     * Liest die Sprachauswahl aus der Anfrage.
     *
     * EIGENE METHODE, weil Request::g() hier nicht taugt: Das Formular
     * schickt "languages[]" - ein Array -, und Request::g() bildet
     * Nicht-Skalares bewusst auf den Vorgabewert ab (dort steht, warum: sonst
     * loeste trim($array) einen Fatal Error aus). Der Zugriff auf $_REQUEST
     * steht deshalb hier, an genau einer Stelle, und das Ergebnis geht
     * anschliessend durch Languages::normalize(), das ueber Gueltigkeit
     * entscheidet.
     *
     * @return array|string Roh, ungeprueft - fuer Languages::normalize()
     */
    private static function rohSprachen()
    {
        $roh = $_REQUEST['languages'] ?? [];
        return (is_array($roh) || is_scalar($roh)) ? $roh : [];
    }

    /**
     * Prueft Titel, Beschreibungen, Dauer und Sprachen aus der Anfrage.
     *
     * DIE EINE STELLE, an der steht, was ein gueltiger Standortinhalt ist.
     * Benutzt von setLocation() (anlegen) und updateLocation() (bearbeiten).
     * Ohne sie stuenden die Grenzen zweimal da, und beim naechsten Feld
     * waeren es zwei Formulare mit zwei Meinungen.
     *
     * Was NICHT hierher gehoert: Koordinaten, Land und Stadt. Die gibt es
     * nur beim Anlegen - siehe updateLocation().
     *
     * @return array{ok:bool, code?:string, text?:string, werte?:array<string,mixed>}
     */
    private static function pruefeInhalt(): array
    {
        $titel = trim((string)Request::g('title'));
        $kurz  = trim((string)Request::g('description'));
        $lang  = trim((string)Request::g('description_long'));
        $dauer = trim((string)Request::g('duration'));

        // mb_strlen und nicht strlen: strlen zaehlt Bytes. Ein Titel aus
        // zwei Umlauten haette damit vier Zeichen und kaeme durch eine
        // Mindestlaenge von drei - und ein Titel aus 70 Umlauten waere zu
        // lang fuer varchar(120), obwohl er 70 Zeichen hat.
        if (mb_strlen($titel) < self::TITEL_MIN) {
            return self::inhaltFehler('3',
                'Der Titel muss mindestens ' . self::TITEL_MIN . ' Zeichen lang sein.');
        }
        if (mb_strlen($titel) > self::TITEL_MAX) {
            return self::inhaltFehler('3',
                'Der Titel darf hoechstens ' . self::TITEL_MAX . ' Zeichen lang sein.');
        }

        if (mb_strlen($kurz) < self::KURZ_MIN) {
            return self::inhaltFehler('0',
                'Die Kurzbeschreibung muss mindestens ' . self::KURZ_MIN . ' Zeichen lang sein.');
        }
        if (mb_strlen($kurz) > self::KURZ_MAX) {
            return self::inhaltFehler('0',
                'Die Kurzbeschreibung darf hoechstens ' . self::KURZ_MAX . ' Zeichen lang sein.');
        }

        if (mb_strlen($lang) > self::LANG_MAX) {
            return self::inhaltFehler('6',
                'Die ausfuehrliche Beschreibung darf hoechstens ' . self::LANG_MAX . ' Zeichen lang sein.');
        }

        // Die Dauer darf fehlen. Ein leeres Feld heisst "nicht angegeben"
        // und wird zu NULL - nicht zu 0, denn das waere die Aussage "dauert
        // keine Zeit" (siehe Location::setDurationMinutes).
        $minuten = null;
        if ($dauer !== '') {
            if (!ctype_digit($dauer)) {
                return self::inhaltFehler('7', 'Die Dauer muss eine Zahl in Minuten sein.');
            }
            $minuten = (int)$dauer;
            if ($minuten < self::DAUER_MIN || $minuten > self::DAUER_MAX) {
                return self::inhaltFehler('7', 'Die Dauer muss zwischen '
                    . self::DAUER_MIN . ' und ' . self::DAUER_MAX . ' Minuten liegen.');
            }
        }

        return ['ok' => true, 'werte' => [
            'title'            => $titel,
            'description'      => $kurz,
            // Leer bleibt leer und wird NULL: Ein Leerstring in der Spalte
            // waere "der Guide hat eine leere Beschreibung geschrieben", NULL
            // ist "er hat noch keine geschrieben". Die Seite sagt beides
            // gleich, die Spalte soll die Wahrheit tragen.
            'description_long' => $lang === '' ? null : $lang,
            'duration_minutes' => $minuten,
            // Sprachen sind nie ein Ablehnungsgrund: normalize() verwirft, was
            // es nicht kennt, und laesst den Rest stehen. Wer gar keine
            // auswaehlt, bietet eben keine an - das ist eine Angabe und kein
            // Fehler.
            'languages'        => Languages::normalize(self::rohSprachen()) ?: null,
        ]];
    }

    /**
     * Baut eine Ablehnung aus pruefeInhalt().
     *
     * @param string $in_code Code fuer die Weiterleitung des Anlegeformulars
     * @param string $in_text Klartext fuer die Standortseite
     * @return array{ok:bool, code:string, text:string}
     */
    private static function inhaltFehler(string $in_code, string $in_text): array
    {
        return ['ok' => false, 'code' => $in_code, 'text' => $in_text];
    }

    /**
     * Setzt die geprueften Inhalte in ein Location-Objekt.
     *
     * Damit steht die Zuordnung Feld -> Setter an einer Stelle und nicht in
     * beiden Aufrufern.
     *
     * @param Location            $in_location
     * @param array<string,mixed> $in_werte Aus pruefeInhalt()['werte']
     * @return void
     */
    private static function uebernehmeInhalt(Location $in_location, array $in_werte): void
    {
        $in_location->setTitle($in_werte['title']);
        $in_location->setDescription($in_werte['description']);
        $in_location->setDescriptionLong($in_werte['description_long']);
        $in_location->setDurationMinutes($in_werte['duration_minutes']);
        $in_location->setLanguages($in_werte['languages']);
    }

    /**
     * Gibt alle Länder als JSON zurück (API).
     * @return void
     */
    public function getCountry()
    {
        $location = new Location();
        $data = $location->selectAllCountries();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    /**
     * Gibt alle fremden Locations als JSON zurück (API).
     *
     * Gesperrte Standorte sind hier nicht dabei - genau das ist der Zweck
     * der Sperre. Wer sie moderieren darf (Recht location.block), sieht sie
     * weiterhin, sonst könnte er sie nicht wieder freigeben.
     *
     * @return void
     */
    public function getLocations()
    {
        $may_moderate = Auth::can(Permission::LOCATION_BLOCK);

        $location = new Location();
        $data = $location->selectAllLocations(Auth::userId(), $may_moderate);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    /**
     * Gibt die Standorte fuer die Karte der Startseite als JSON zurueck.
     *
     * Zugang: Recht location.map_public - das hat auch der Gast. Deshalb
     * enthaelt die Antwort keine Personendaten: kein Benutzername, keine
     * user_id, kein roher Anwesenheitsstatus, sondern Ort, Beschreibung und
     * einen von drei Verfuegbarkeitswerten
     * (App\Model\Location::selectPublicMapLocations).
     *
     * Der Zuschnitt der Daten steht im Modell und nicht hier: Eine zweite
     * Stelle, an der entschieden wird, was oeffentlich ist, waere eine
     * Stelle zu viel.
     *
     * @return void
     */
    public function getMapLocations()
    {
        $location = new Location();
        $data = $location->selectPublicMapLocations();
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    /**
     * Gibt alle eigenen Locations des aktuellen Benutzers als JSON zurück.
     *
     * Die Antwort enthält auch gesperrte Standorte samt Grund - der Guide
     * soll sehen, dass und warum sein Standort nicht mehr in der Übersicht
     * auftaucht.
     *
     * @return void
     */
    public function getMyLocations()
    {
        $location = new Location();
        $data = $location->selectAllLocationsOfOneUser(Auth::userId());
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    /**
     * Zeigt die Seite mit der Locations-Tabelle an.
     * @return void
     */
    public function showLocationsPage()
    {
        $out = ViewHelper::template('assets/html/locations_table.html');
        ViewHelper::output($out);
    }

    /**
     * Löscht eine eigene Location anhand der ID.
     *
     * Vorher hatte diese Methode keinerlei Prüfung - weder auf eine
     * Anmeldung noch auf das Eigentum. Eine beliebige ID im POST löschte
     * einen beliebigen fremden Standort.
     *
     * @return void
     */
    public function deleteLocation()
    {
        $location_id = (int)Request::g('id');
        $user_id     = Auth::userId();

        if (!$location_id) {
            self::json(['success' => false, 'error' => 'Keine Location-ID übergeben!']);
        }

        try {
            $location = new Location();
            if ($location->deleteLocation($location_id, $user_id)) {
                // DIE BILDDATEIEN GEHEN NICHT VON SELBST MIT. Die Zeilen in
                // location_image nimmt der Fremdschluessel (ON DELETE
                // CASCADE), die Dateien nicht - die Datenbank kennt das
                // Dateisystem nicht. Ohne diesen Aufruf blieben sie fuer
                // immer liegen, ohne dass irgendetwas noch auf sie zeigt.
                //
                // NACH dem DELETE und nicht davor: Erst hier steht fest, dass
                // der Standort wirklich diesem Benutzer gehoerte. Davor
                // haetten die Bilder eines fremden Standorts drangeglaubt,
                // waehrend das DELETE ihn gar nicht traf.
                ImageStore::deleteLocationDir($location_id);
                self::json(['success' => true]);
            }
            // Kein Treffer heißt: gibt es nicht oder gehört jemand anderem.
            // Beides ergibt dieselbe Antwort.
            error_log("deleteLocation: kein eigener Standort #$location_id fuer Benutzer #$user_id");
            self::json(['success' => false, 'error' => 'Standort nicht gefunden.']);
        } catch (\Exception $e) {
            error_log("Fehler beim Löschen der Location #$location_id: " . $e->getMessage());
            self::json(['success' => false, 'error' => 'Fehler beim Löschen.']);
        }
    }

    /**
     * Sperrt einen fremden Standort (Moderation).
     *
     * Zugang: Recht location.block. Gesperrte Standorte verschwinden aus der
     * Übersicht der anderen Nutzer; der Guide behält seinen Datensatz und
     * sieht in seiner eigenen Standortliste den hinterlegten Grund.
     * Gelöscht wird nichts - das bleibt dem Eigentümer vorbehalten.
     *
     * @return void
     */
    public function blockLocation()
    {
        $location_id = (int)Request::g('id');
        $reason      = trim(Request::g('reason'));

        if (!$location_id) {
            self::json(['success' => false, 'error' => 'Keine Location-ID übergeben!']);
        }
        if ($reason === '') {
            // Der Guide bekommt den Grund angezeigt - ohne Grund ist die
            // Sperre für ihn nicht nachvollziehbar.
            self::json(['success' => false, 'error' => 'Bitte einen Grund angeben.']);
        }
        if (mb_strlen($reason) > 255) {
            $reason = mb_substr($reason, 0, 255);
        }

        $location = new Location();
        if ($location->block($location_id, Auth::userId(), $reason)) {
            self::json(['success' => true]);
        }
        self::json(['success' => false, 'error' => 'Standort nicht gefunden.']);
    }

    /**
     * Hebt die Sperre eines Standorts wieder auf.
     * Zugang: Recht location.block.
     * @return void
     */
    public function unblockLocation()
    {
        $location_id = (int)Request::g('id');

        if (!$location_id) {
            self::json(['success' => false, 'error' => 'Keine Location-ID übergeben!']);
        }

        $location = new Location();
        if ($location->unblock($location_id)) {
            self::json(['success' => true]);
        }
        self::json(['success' => false, 'error' => 'Standort nicht gefunden.']);
    }

    // =================================================================
    // DIE SEITE EINES STANDORTS
    //
    // Sie ist seit diesem Umbau das Ziel jeder Nadel und jeder Listenzeile
    // und damit der Ort, an dem ein Kunde seine Entscheidung trifft. Vorher
    // fuehrte ein Klick auf eine Nadel unmittelbar in den Anruf - der Kunde
    // schickte einen Fremden los, ohne mehr gesehen zu haben als eine Zeile
    // Freitext.
    //
    // WICHTIG FUER DEN ANRUF: Der Knopf "Fuehrung starten" gibt die
    // STANDORTKENNUNG mit. Daran haengt beim Server die Rollenvergabe - von
    // einem Standort aus fuehrt der Angerufene, auch wenn er Admin ist
    // (WebRTCController::callRoles). Ginge sie hier verloren, waere jede
    // Fuehrung ueber einen Admin-Standort ein Gespraech ohne Fuehrung.
    // =================================================================

    /**
     * Zeigt die Seite eines einzelnen Standorts.
     *
     * Zugang: Recht location.view - das hat auch der Gast. Ein Link auf diese
     * Seite soll sich weitergeben lassen; einer, der beim Empfaenger auf dem
     * Anmeldeformular endet, wird nicht weitergegeben.
     *
     * WAS EIN GAST NICHT BEKOMMT: die user_id des Guides. Ohne sie laesst
     * sich von hier aus niemand anrufen - genau wie auf der oeffentlichen
     * Karte. Statt eines Knopfes, der nichts tut, steht dort der Weg zur
     * Anmeldung.
     *
     * WER EINEN GESPERRTEN STANDORT SIEHT: sein Eigentuemer und die
     * Moderation. Fuer alle anderen gibt es ihn nicht - und zwar mit
     * derselben Antwort wie fuer einen Standort, den es wirklich nicht gibt.
     * Zwei verschiedene Antworten waeren eine Auskunft darueber, welche IDs
     * belegt sind.
     *
     * @return void
     */
    public function showLocationPage()
    {
        $location_id = (int)Request::g('id');
        $daten       = (new Location())->selectOneForPage($location_id);

        $user_id      = Auth::userId();
        $ist_eigen    = $daten !== null && (int)$daten['user_id'] === $user_id && $user_id > 0;
        $darf_sperren = Auth::can(Permission::LOCATION_BLOCK);

        if ($daten === null || ((int)$daten['blocked'] === 1 && !$ist_eigen && !$darf_sperren)) {
            self::zeigeFehlseite();
            return;
        }

        // WEN MAN VON HIER AUS ANRUFEN DARF - die einzige Stelle, an der das
        // entschieden wird, und sie ist der Grund fuer diese Zeile:
        //
        //   Ein GAST bekommt die user_id des Guides NICHT. Ohne sie laesst
        //   sich von dieser Seite aus niemand anrufen; der Knopf wird durch
        //   den Weg zur Anmeldung ersetzt. Dieselbe Entscheidung wie bei der
        //   oeffentlichen Karte (Location::selectPublicMapLocations).
        //
        //   Der EIGENTUEMER bekommt sie auch nicht - sich selbst ruft niemand
        //   an.
        //
        // App\Helper\LocationView baut daraus den Knopf und die Seitendaten.
        // Sie entscheidet nichts; sie bekommt hier, was sie zeigen darf.
        $anrufbar = (Auth::isLoggedIn() && !$ist_eigen) ? (int)$daten['user_id'] : null;

        // EINMAL LADEN, hier trennen: Welche Rolle ein Bild traegt, steht in
        // der Zeile - die Ansicht bekommt zwei fertige Listen und muss nicht
        // wissen, wie die Rollen heissen.
        $bilder = LocationImage::teile(LocationImage::forLocation($location_id));

        // DIE LAUFENDE EIGENE ANFRAGE. Sie entscheidet, was im Aktionsbereich
        // steht: das Anfrageformular, "Ihre Anfrage ist beim Guide" oder der
        // Knopf, mit dem die zugesagte Fuehrung beginnt.
        //
        // Nur fuer den ANGEMELDETEN BESUCHER und nur seine eigene: Ein Gast
        // hat keine, und der Eigentuemer fragt seinen eigenen Standort nicht
        // an. Was an diesem Standort sonst noch ansteht, gehoert dem Guide und
        // steht auf seiner Anfragenseite - nicht auf einer Seite, die jeder
        // aufrufen kann.
        $anfrage = ($user_id > 0 && !$ist_eigen)
            ? TourRequest::currentForCustomer($user_id, $location_id)
            : null;

        ViewHelper::output(LocationView::page(
            $daten,
            $bilder,
            [
                'eigen'       => $ist_eigen,
                'angemeldet'  => Auth::isLoggedIn(),
                'viewer_id'   => $anrufbar,
                'fehler'      => (string)Request::g('fehler'),
                'gespeichert' => Request::g('gespeichert') === '1',
                // Nur der Eigentuemer bekommt ein Formular, also braucht auch
                // nur er die Grenzen.
                'grenzen'     => $ist_eigen ? self::grenzen($user_id) : [],
                'anfrage'     => $anfrage,
            ]
        ));
    }

    /**
     * Die Obergrenzen fuer das Bearbeitungsformular und den Upload.
     *
     * Zwei Quellen, und beide sind die jeweils EINE Stelle:
     *
     *   config/uploads.php   was eine Datei sein darf und wie viele es
     *                        werden duerfen (gelesen ueber ImageStore),
     *   die Konstanten oben  wie lang die Textfelder sein duerfen - dieselben
     *                        Zahlen, gegen die pruefeInhalt() prueft.
     *
     * Zusammengefasst werden sie hier und nicht in der Ansicht: Ein Formular,
     * das andere Zahlen anzeigt, als der Server durchlaesst, gibt dem Nutzer
     * eine Absage fuer eine Eingabe, die das Feld ausdruecklich erlaubt hat.
     *
     * @param int $in_user_id Eigentuemer - fuer die spaetere Staffelung je Konto
     * @return array<string,mixed>
     */
    private static function grenzen($in_user_id): array
    {
        $config = ImageStore::config();

        return [
            'max_images'      => ImageStore::maxImages($in_user_id),
            'max_bytes'       => (int)$config['max_file_bytes'],
            'max_source_edge' => (int)$config['max_source_edge'],
            'accept'          => implode(',', $config['accepted_mime']),
            'titel_max'       => self::TITEL_MAX,
            'kurz_max'        => self::KURZ_MAX,
            'lang_max'        => self::LANG_MAX,
            'dauer_min'       => self::DAUER_MIN,
            'dauer_max'       => self::DAUER_MAX,
            'dauer_vorgabe'   => self::DAUER_VORGABE,
        ];
    }

    /**
     * Gibt die Verfuegbarkeit eines Standorts als JSON zurueck.
     *
     * Die Standortseite fragt sie im Takt nach. Bewusst NUR diesen einen
     * Wert: Wer die ganze Seite neu laedt, verliert die Bildergalerie, in
     * der er gerade blaettert - und wer die vollstaendige Standortliste holt,
     * bekommt fuer ein Wort die Daten aller Guides.
     *
     * Ein gesperrter Standort meldet immer 'idle'. Das entscheidet
     * Location::availabilityOf(), damit die Sperre nicht an zwei Stellen
     * ausgewertet wird.
     *
     * @return void
     */
    public function getLocationState()
    {
        $location_id = (int)Request::g('id');
        $zustand     = (new Location())->availabilityOf($location_id);

        if ($zustand === null) {
            self::json(['success' => false, 'error' => 'Standort nicht gefunden.']);
        }

        // MIT DER EIGENEN ANFRAGE, aus demselben Grund, aus dem es diese
        // Route ueberhaupt gibt: Der Zustand aendert sich, waehrend die Seite
        // offen liegt. Der Guide sagt zu, waehrend der Kunde noch die Bilder
        // ansieht - dann muss dort der Startknopf erscheinen, ohne dass er
        // neu laden muss.
        //
        // Sie ist immer die des Aufrufers. Wer die Route mit einer fremden
        // Standortkennung aufruft, bekommt seine eigene Anfrage zu diesem
        // Standort oder null - nie die eines anderen.
        $user_id = Auth::userId();
        $anfrage = $user_id > 0
            ? TourRequest::currentForCustomer($user_id, $location_id)
            : null;

        self::json([
            'success'      => true,
            'availability' => $zustand,
            'request'      => $anfrage,
        ]);
    }

    /**
     * Liefert ein Standortbild aus.
     *
     * DER EINZIGE WEG, auf dem eine hochgeladene Datei einen Browser
     * erreicht. Die Dateien liegen ausserhalb des Document Root
     * (config/uploads.php); der Webserver kommt gar nicht an sie heran.
     *
     * Was hier geprueft wird und an keiner anderen Stelle geprueft werden
     * koennte:
     *   1. Gibt es die Zeile ueberhaupt?
     *   2. Ist der Standort gesperrt? Dann sehen die Bilder nur der
     *      Eigentuemer und die Moderation - sonst waere die Sperre
     *      wirkungslos, sobald jemand die Bild-ID kennt.
     *   3. Ist der Dateiname einer, den ImageStore selbst vergeben hat?
     *      Zwischen der Datenbankzeile und dem Dateisystem soll keine
     *      Annahme stehen, sondern eine Pruefung.
     *
     * Alle Ablehnungen antworten gleich (404). Ein unterscheidbares "gibt es,
     * darfst du aber nicht" waere eine Auskunft darueber, welche IDs belegt
     * sind.
     *
     * @return void
     */
    public function serveImage()
    {
        $image_id = (int)Request::g('id');
        // Nur zwei Groessen, und die Vorgabe ist die kleine: Ein Tippfehler
        // im Parameter soll nicht die Vollansicht ausliefern.
        $groesse  = Request::g('size') === 'full' ? 'full' : 'thumb';

        $bild = LocationImage::findWithLocation($image_id);
        if ($bild === null) {
            self::bildFehlt();
        }

        if ((int)$bild['blocked'] === 1
            && (int)$bild['user_id'] !== Auth::userId()
            && !Auth::can(Permission::LOCATION_BLOCK)) {
            self::bildFehlt();
        }

        $pfad = ImageStore::pathFor($bild['location_id'], $bild['file_name'], $groesse);
        if ($pfad === null || !is_file($pfad) || !is_readable($pfad)) {
            error_log('serveImage: Datei fehlt zu Bild #' . $image_id . ' (' . $groesse . ')');
            self::bildFehlt();
        }

        // Der Inhalt einer Datei aendert sich nie: Wird ein Bild ersetzt,
        // bekommt es einen neuen Namen und eine neue Zeile. Der Name ist
        // damit als ETag brauchbar, und ein Browser laedt jedes Bild genau
        // einmal.
        $etag = '"' . $bild['file_name'] . '-' . $groesse . '"';
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            exit;
        }

        // private, nicht public: Ein gesperrter Standort wird nur bestimmten
        // Aufrufern gezeigt, und ein zwischengeschalteter Proxy weiss davon
        // nichts. Was von einer Berechtigung abhaengt, gehoert nicht in einen
        // gemeinsamen Zwischenspeicher.
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($pfad));
        header('ETag: ' . $etag);
        header('Cache-Control: private, max-age=86400');
        // Gespeichert wird ausschliesslich JPEG (ImageStore). Der Zusatz
        // haelt einen Browser davon ab, den Typ selbst zu erraten - genau
        // darueber wurde frueher aus einem "Bild" eine HTML-Seite.
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');

        readfile($pfad);
        exit;
    }

    /**
     * Antwortet, als gaebe es das Bild nicht.
     *
     * @return never
     */
    private static function bildFehlt()
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bild nicht gefunden.';
        exit;
    }

    /**
     * Zeigt "Standort nicht gefunden" als vollstaendige Seite.
     *
     * @return void
     */
    private static function zeigeFehlseite(): void
    {
        http_response_code(404);
        ViewHelper::output(
            '<div class="app-page app-page--narrow">'
          . '<div class="app-panel"><div class="app-panel__body">'
          . '<h1 class="app-page-head__title">Standort nicht gefunden</h1>'
          . '<p class="app-page-head__sub">Dieser Standort wurde entfernt, '
          . 'gesperrt oder hat es nie gegeben.</p>'
          . '<div class="app-actions">'
          . '<a class="btn btn-primary" href="index.php?act=home">Zur Karte</a>'
          . '</div></div></div></div>'
        );
    }

    // =================================================================
    // BEARBEITEN - ueber dieselbe Seite
    // =================================================================

    /**
     * Nimmt das Bearbeitungsformular der Standortseite entgegen.
     *
     * Geaendert werden Titel, Kurzbeschreibung, ausfuehrliche Beschreibung,
     * Dauer und Sprachen - geprueft mit derselben pruefeInhalt(), die auch
     * das Anlegen benutzt.
     *
     * WAS SICH HIER NICHT AENDERN LAESST: Land, Stadt und Koordinaten. Sie
     * haengen an location.city_id, und ein Punkt, den man ueber die
     * Landesgrenze zieht, machte aus "Lissabon, Portugal" eine Zeile, die
     * nicht mehr stimmt. Ein Standort an einem anderen Ort ist ein anderer
     * Standort und wird ueber das Anlegeformular angelegt, das die Karte und
     * die Laenderauswahl dafuer mitbringt.
     *
     * Zugang: Recht location.edit_own. Das Eigentum prueft diese Methode -
     * und die WHERE-Klausel des UPDATE noch einmal (App\Model\Location).
     *
     * @return void
     */
    public function updateLocation()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php?act=home');
            exit;
        }

        $location_id = (int)Request::g('id');
        $user_id     = Auth::userId();
        $zurueck     = 'index.php?act=location&id=' . $location_id;

        if ($location_id < 1) {
            header('Location: index.php?act=home');
            exit;
        }

        try {
            $location = new Location($location_id);
        } catch (\Exception $e) {
            error_log('updateLocation: ' . $e->getMessage());
            header('Location: index.php?act=home');
            exit;
        }

        // Fremde Standorte gibt es hier nicht. Dieselbe Antwort wie fuer
        // einen Standort, den es nicht gibt - sonst liessen sich ueber diese
        // Route fremde Kennungen abklopfen.
        if (!$location->belongsToUser($user_id)) {
            error_log("updateLocation: Standort #$location_id gehoert nicht zu Benutzer #$user_id");
            self::zeigeFehlseite();
            return;
        }

        $inhalt = self::pruefeInhalt();
        if (!$inhalt['ok']) {
            // Der Klartext reist in der Adresse mit und wird auf der Seite
            // angezeigt - nicht als Nummer, die man erst nachschlagen muss.
            // rawurlencode, weil der Text Leerzeichen und Umlaute enthaelt.
            header('Location: ' . $zurueck . '&edit=1&fehler=' . rawurlencode($inhalt['text']));
            exit;
        }

        self::uebernehmeInhalt($location, $inhalt['werte']);

        if (!$location->updateLocation($user_id)) {
            header('Location: ' . $zurueck . '&edit=1&fehler='
                . rawurlencode('Die Aenderung konnte nicht gespeichert werden.'));
            exit;
        }

        // Post/Redirect/Get: Ein Neuladen der Seite soll nicht ein zweites
        // Mal speichern.
        header('Location: ' . $zurueck . '&gespeichert=1');
        exit;
    }

    // =================================================================
    // BILDER
    // =================================================================

    /**
     * Nimmt ein Bild zu einem eigenen Standort entgegen.
     *
     * REIHENFOLGE: erst die Datei, dann die Zeile. Scheitert das Schreiben
     * der Datei, entsteht gar keine Zeile; scheitert die Zeile, wird die
     * Datei wieder weggeraeumt. Andersherum bliebe eine Zeile ohne Bild
     * zurueck, und die zeigt die Seite als kaputtes Bild an.
     *
     * Die Obergrenze wird VOR dem Annehmen geprueft, aber NACH dem Eigentum:
     * Wie viele Bilder ein fremder Standort hat, geht den Aufrufer nichts an.
     *
     * @return void
     */
    public function uploadImage()
    {
        $user_id  = Auth::userId();
        $location = self::eigenerStandortAusAnfrage();

        $grenze  = ImageStore::maxImages($user_id);
        $vorhanden = LocationImage::countForLocation($location['id']);
        if ($vorhanden >= $grenze) {
            self::json(['success' => false, 'error' =>
                'Mehr als ' . $grenze . ' Bilder sind an einem Standort nicht moeglich.']);
        }

        // Genau ein Feld, genau eine Datei je Aufruf. Mehrere Dateien
        // gleichzeitig waeren eine Teilerfolgsmeldung ("drei von fuenf
        // gespeichert"), die der Browser dem Nutzer erklaeren muesste; die
        // Seite laedt sie stattdessen nacheinander hoch und meldet jede
        // einzeln.
        $datei = $_FILES['image'] ?? null;
        if (!is_array($datei)) {
            self::json(['success' => false, 'error' => 'Es wurde keine Datei geschickt.']);
        }

        $abgelegt = ImageStore::store($datei, $location['id']);
        if (!$abgelegt['ok']) {
            self::json(['success' => false, 'error' => $abgelegt['error']]);
        }

        // DAS ERSTE BILD EINES STANDORTS WIRD SEIN TITELBILD - aber nur, solange
        // gar keines gewaehlt ist. Ohne das stuende ein frischer Standort mit
        // fuenf Bildern unter einem leeren Kopf, und der Guide muesste erst
        // merken, dass da noch eine Entscheidung offen ist.
        //
        // Jedes weitere Bild geht in die Galerie. Das ist der Unterschied zum
        // alten Verhalten, bei dem das erste Bild stillschweigend zum
        // Titelbild wurde und jede Umsortierung die Kopfzeile mit veraendert
        // hat: Ab hier waehlt der Guide aus (setCover).
        $rolle = LocationImage::hasCover($location['id'])
            ? LocationImage::ROLE_GALLERY
            : LocationImage::ROLE_COVER;

        $image_id = LocationImage::add($location['id'], $abgelegt['name'], $rolle);
        if (!$image_id) {
            // Die Datei liegt schon, die Zeile fehlt - also wieder weg damit.
            // Sonst bliebe eine Datei, auf die nichts mehr zeigt und die
            // niemand je wiederfindet.
            ImageStore::delete($location['id'], $abgelegt['name']);
            self::json(['success' => false, 'error' => 'Das Bild konnte nicht gespeichert werden.']);
        }

        self::json([
            'success' => true,
            'image'   => [
                'id'    => (int)$image_id,
                'role'  => $rolle,
                'thumb' => 'index.php?act=location_image&id=' . (int)$image_id . '&size=thumb',
                'full'  => 'index.php?act=location_image&id=' . (int)$image_id . '&size=full',
            ],
        ]);
    }

    /**
     * Loescht ein Bild eines eigenen Standorts.
     *
     * REIHENFOLGE: erst die Zeile, dann die Datei. Andersherum bliebe bei
     * einem Fehler eine Zeile ohne Bild stehen - und die zeigt die Seite als
     * kaputtes Bild an, waehrend eine Datei ohne Zeile nur Platz belegt.
     *
     * Das Eigentum steht in der WHERE-Klausel des DELETE
     * (App\Model\LocationImage::deleteOwned), nicht nur in einer Pruefung
     * davor.
     *
     * @return void
     */
    public function deleteImage()
    {
        $image_id = (int)Request::g('id');
        $user_id  = Auth::userId();

        if ($image_id < 1) {
            self::json(['success' => false, 'error' => 'Kein Bild angegeben.']);
        }

        $geloescht = LocationImage::deleteOwned($image_id, $user_id);
        if ($geloescht === null) {
            // "Gibt es nicht" und "gehoert jemand anderem" ergeben dieselbe
            // Antwort.
            self::json(['success' => false, 'error' => 'Bild nicht gefunden.']);
        }

        ImageStore::delete($geloescht['location_id'], $geloescht['file_name']);
        self::json(['success' => true]);
    }

    /**
     * Setzt die Reihenfolge der Bilder eines eigenen Standorts neu.
     *
     * Erwartet die vollstaendige Liste der Bild-IDs in ihrer neuen Folge, als
     * kommagetrennte Zeichenkette ("12,9,14"). Eine Liste statt einzelner
     * "nach vorn"-Aufrufe: Das Umsortieren ist EINE Entscheidung des
     * Nutzers, und sie soll auch als eine gespeichert werden - sonst stuende
     * die Reihenfolge zwischen zwei Aufrufen in einem Zustand, den niemand
     * gewollt hat.
     *
     * @return void
     */
    public function sortImages()
    {
        $user_id  = Auth::userId();
        $location = self::eigenerStandortAusAnfrage();

        $roh = trim((string)Request::g('order'));
        if ($roh === '') {
            self::json(['success' => false, 'error' => 'Keine Reihenfolge angegeben.']);
        }

        $ids = [];
        foreach (explode(',', $roh) as $stueck) {
            $id = (int)trim($stueck);
            if ($id > 0) $ids[] = $id;
        }
        if ($ids === []) {
            self::json(['success' => false, 'error' => 'Keine Reihenfolge angegeben.']);
        }

        if (!LocationImage::reorder($location['id'], $user_id, $ids)) {
            self::json(['success' => false, 'error' => 'Die Reihenfolge konnte nicht gespeichert werden.']);
        }
        self::json(['success' => true]);
    }

    /**
     * Waehlt eines der Bilder als Titelbild aus.
     *
     * DAS IST DER GANZE UNTERSCHIED zwischen den beiden Bildarten, soweit es
     * den Server betrifft: Ein Titelbild fuellt die Kopfzeile, ein
     * Beispielbild steht in der Galerie. Dieselbe Datei, dieselbe Pruefung,
     * dieselbe Obergrenze - eine andere Rolle.
     *
     * Das bisherige Titelbild wird dabei NICHT geloescht, sondern zurueck in
     * die Galerie genommen (App\Model\LocationImage::setCover). Eine Auswahl
     * ist keine Loeschung.
     *
     * @return void
     */
    public function setCoverImage()
    {
        $image_id = (int)Request::g('id');
        $user_id  = Auth::userId();

        if ($image_id < 1) {
            self::json(['success' => false, 'error' => 'Kein Bild angegeben.']);
        }

        if (!LocationImage::setCover($image_id, $user_id)) {
            // "Gibt es nicht" und "gehoert jemand anderem" ergeben dieselbe
            // Antwort - sonst liessen sich ueber diese Route fremde
            // Bildkennungen abklopfen.
            self::json(['success' => false, 'error' => 'Bild nicht gefunden.']);
        }
        self::json(['success' => true]);
    }

    /**
     * Nimmt das Titelbild zurueck in die Galerie.
     *
     * Der Standort hat danach keinen Bildkopf mehr, sondern einen ruhigen
     * Streifen mit Titel und Ort. Das Bild bleibt erhalten - wer es loeschen
     * will, loescht es.
     *
     * @return void
     */
    public function unsetCoverImage()
    {
        $user_id  = Auth::userId();
        $location = self::eigenerStandortAusAnfrage();

        if (!LocationImage::clearCover($location['id'], $user_id)) {
            self::json(['success' => false, 'error' => 'Das Titelbild konnte nicht geändert werden.']);
        }
        self::json(['success' => true]);
    }

    /**
     * Holt den Standort aus der Anfrage und stellt sicher, dass er dem
     * Aufrufer gehoert.
     *
     * Gemeinsam fuer die Bildrouten. Sie sind sich in genau dieser Pruefung
     * einig, und dreimal dieselben zehn Zeilen waeren dreimal die
     * Gelegenheit, eine davon zu vergessen.
     *
     * Bricht die Verarbeitung bei jedem Fehlschlag ab - der Aufrufer bekommt
     * eine Antwort und kein Ergebnis, mit dem er weiterrechnen muesste.
     *
     * @return array<string,mixed> Der Standort (mindestens 'id')
     */
    private static function eigenerStandortAusAnfrage(): array
    {
        $location_id = (int)Request::g('location_id');
        $user_id     = Auth::userId();

        if ($location_id < 1) {
            self::json(['success' => false, 'error' => 'Kein Standort angegeben.']);
        }

        try {
            $location = new Location($location_id);
        } catch (\Exception $e) {
            error_log('eigenerStandortAusAnfrage: ' . $e->getMessage());
            self::json(['success' => false, 'error' => 'Standort nicht gefunden.']);
        }

        if (!$location->belongsToUser($user_id)) {
            error_log("Bildaktion: Standort #$location_id gehoert nicht zu Benutzer #$user_id");
            self::json(['success' => false, 'error' => 'Standort nicht gefunden.']);
        }

        return ['id' => (int)$location->getId()];
    }

    /**
     * Gibt eine JSON-Antwort aus und beendet die Verarbeitung.
     *
     * @param array $payload
     * @return never
     */
    private static function json(array $payload)
    {
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit;
    }
}
