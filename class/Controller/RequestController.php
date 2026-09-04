<?php
namespace App\Controller;

use App\Model\Location;
use App\Model\TourRequest;
use App\Model\User;
use App\Helper\Auth;
use App\Helper\Permission;
use App\Helper\Role;
use App\Helper\ViewHelper;

/**
 * RequestController - Anfragen stellen, beantworten und ansehen.
 *
 * WORUM ES GEHT
 * -------------
 * Vorher rief ein Kunde den Guide unmittelbar an. Das verlangte, dass beide
 * zufaellig im selben Moment koennen - und der Guide ist die knappere Seite:
 * Er muss losgehen, sich Zeit nehmen, vielleicht hinfahren. Am Anfang steht
 * deshalb eine ANFRAGE mit einem Wunschzeitpunkt; der Guide nimmt an oder
 * lehnt ab, und erst danach wird angerufen - wie bisher, mit derselben
 * Rollenvergabe (App\Controller\WebRTCController::callRoles).
 *
 * "JETZT SOFORT" IST KEIN SONDERFALL. Es ist der Wunschzeitpunkt mit dem
 * Abstand null. Es gibt hier keine Verzweigung dafuer.
 *
 * WAS HIER GEPRUEFT WIRD UND WAS NICHT
 * ------------------------------------
 * Ueber den Zugang zu den Routen entscheidet index.php anhand der Rechte aus
 * config/routes.php. Ein Recht sagt aber nur "diese Rolle darf Anfragen
 * beantworten", nicht "diese Anfrage ist an diesen Guide gerichtet". Die
 * Zustaendigkeit fuer die EINZELNE Zeile steht deshalb in der WHERE-Klausel
 * des Statements (App\Model\TourRequest) - genau wie das Eigentum an einem
 * Standort. Hier davor steht nur, was dem Aufrufer eine verstaendliche
 * Antwort gibt.
 *
 * Die Antwort auf eine fremde oder nicht mehr gueltige Anfrage unterscheidet
 * nicht zwischen "gibt es nicht" und "geht dich nichts an": Die Kennungen
 * sind fortlaufend, und zwei verschiedene Antworten waeren eine Auskunft
 * darueber, welche es gibt.
 */
class RequestController
{
    /**
     * Stellt eine Anfrage.
     *
     * Erwartet POST mit JSON-Rumpf:
     *   location  Kennung des Standorts
     *   wish_in   Abstand des Wunschzeitpunkts in SEKUNDEN, 0 = jetzt sofort
     *
     * WARUM EIN ABSTAND UND KEIN DATUM: Ein Datum aus dem Browser traegt
     * dessen Zeitzone und dessen womoeglich falsch gestellte Uhr. Der Abstand
     * hat weder das eine noch das andere - die Datenbank rechnet daraus einen
     * Zeitpunkt an ihrer eigenen Uhr, an der auch alle Fristen haengen.
     *
     * GEPRUEFT WIRD DER STANDORT, NICHT DIE BEHAUPTUNG DES KUNDEN. Wer der
     * Guide ist, kommt aus dem Standort und nicht aus der Anfrage; ein
     * gesperrter Standort nimmt keine Anfragen an; ein Konto, das gar keine
     * Standorte anbieten darf, kann keine Fuehrung zusagen.
     *
     * @return void
     */
    public function create()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['success' => false, 'error' => 'Nur per POST.']);
        }

        $daten       = self::body();
        $location_id = (int)($daten['location'] ?? 0);
        $wunsch      = (int)($daten['wish_in'] ?? 0);
        $customer_id = Auth::userId();

        if ($location_id < 1) {
            self::json(['success' => false, 'error' => 'Es fehlt der Standort.']);
        }

        // Der Wunschzeitpunkt muss in der Zukunft liegen - aber ein paar
        // Sekunden Vorlauf sind keine Zukunft, sondern Uebertragungsdauer.
        // Alles bis dahin gilt als "jetzt sofort", statt dem Kunden eine
        // Fehlermeldung fuer einen Klick zu geben, der genau richtig war.
        if ($wunsch < 0) $wunsch = 0;

        $config = TourRequest::config();
        if ($wunsch > (int)$config['lead_time_max']) {
            self::json([
                'success' => false,
                'error'   => 'So weit im Voraus lässt sich eine Führung nicht anfragen.',
            ]);
        }

        $standort = (new Location())->selectOneForPage($location_id);
        if ($standort === null || (int)$standort['blocked'] === 1) {
            // Ein gesperrter Standort ist aus der Uebersicht genommen; ueber
            // ihn beginnt keine Fuehrung mehr - dieselbe Antwort wie fuer
            // einen, den es nicht gibt.
            self::json(['success' => false, 'error' => 'Dieser Standort nimmt keine Anfragen an.']);
        }

        $guide_id = (int)$standort['user_id'];
        if ($guide_id === $customer_id) {
            self::json(['success' => false, 'error' => 'Den eigenen Standort fragt man nicht an.']);
        }
        if (!self::offersLocations($guide_id)) {
            self::json(['success' => false, 'error' => 'Dieser Standort nimmt keine Anfragen an.']);
        }

        // EINE LAUFENDE ANFRAGE JE STANDORT. Eine zweite waehrend die erste
        // offen ist sagt nichts Neues und wuerde beim Guide zweimal dasselbe
        // anzeigen. Abgelehntes, Abgelaufenes und Erledigtes zaehlt nicht -
        // danach darf wieder gefragt werden.
        $laufend = TourRequest::currentForCustomer($customer_id, $location_id);
        if ($laufend !== null) {
            self::json([
                'success' => false,
                'error'   => 'Für diesen Standort läuft bereits eine Anfrage von Ihnen.',
                'request' => $laufend,
            ]);
        }

        $id = TourRequest::create($location_id, $guide_id, $customer_id, $wunsch);
        if ($id === null) {
            self::json(['success' => false, 'error' => 'Die Anfrage konnte nicht gestellt werden.']);
        }

        self::json([
            'success' => true,
            'request' => TourRequest::currentForCustomer($customer_id, $location_id),
        ]);
    }

    /**
     * Der Guide nimmt an.
     *
     * @return void
     */
    public function accept()
    {
        $this->antwort(true);
    }

    /**
     * Der Guide lehnt ab.
     *
     * @return void
     */
    public function decline()
    {
        $this->antwort(false);
    }

    /**
     * Annehmen und Ablehnen sind dieselbe Entscheidung mit zwei Ausgaengen -
     * dieselbe Pruefung, dieselbe Antwort, ein anderer Zustand.
     *
     * @param bool $in_annehmen
     * @return void
     */
    private function antwort(bool $in_annehmen)
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['success' => false, 'error' => 'Nur per POST.']);
        }

        $daten    = self::body();
        $id       = (int)($daten['id'] ?? 0);
        $guide_id = Auth::userId();

        if ($id < 1) {
            self::json(['success' => false, 'error' => 'Es fehlt die Anfrage.']);
        }

        $erfolg = $in_annehmen
            ? TourRequest::accept($id, $guide_id)
            : TourRequest::decline($id, $guide_id);

        if (!$erfolg) {
            // Kein Treffer heisst: gibt es nicht, gehoert jemand anderem, ist
            // schon beantwortet oder ist abgelaufen. Alles vier ergibt
            // dieselbe Antwort.
            error_log('RequestController: Anfrage #' . $id . ' konnte von Benutzer #'
                . $guide_id . ' nicht beantwortet werden.');
            self::json([
                'success' => false,
                'error'   => 'Diese Anfrage lässt sich nicht mehr beantworten.',
            ]);
        }

        self::json(['success' => true, 'requests' => self::listen($guide_id)]);
    }

    /**
     * Zuruecknehmen - von beiden Seiten.
     *
     * Der Kunde zieht seine Anfrage zurueck, der Guide sagt eine Zusage ab.
     * Es ist derselbe Vorgang und deshalb dieselbe Route; wer beteiligt ist,
     * prueft die WHERE-Klausel (App\Model\TourRequest::cancel).
     *
     * @return void
     */
    public function cancel()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['success' => false, 'error' => 'Nur per POST.']);
        }

        $daten   = self::body();
        $id      = (int)($daten['id'] ?? 0);
        $user_id = Auth::userId();

        if ($id < 1) {
            self::json(['success' => false, 'error' => 'Es fehlt die Anfrage.']);
        }

        if (!TourRequest::cancel($id, $user_id)) {
            error_log('RequestController: Anfrage #' . $id . ' konnte von Benutzer #'
                . $user_id . ' nicht zurueckgenommen werden.');
            self::json([
                'success' => false,
                'error'   => 'Diese Anfrage lässt sich nicht mehr zurücknehmen.',
            ]);
        }

        self::json(['success' => true, 'requests' => self::listen($user_id)]);
    }

    /**
     * Beide Listen des Aufrufers als JSON.
     *
     * BEIDE SEITEN IN EINER ANTWORT, weil ein Konto beides sein kann: Ein
     * Guide fragt anderswo selbst eine Fuehrung an. Zwei Routen dafuer waeren
     * zwei Abrufe fuer eine Seite.
     *
     * @return void
     */
    public function getRequests()
    {
        self::json(['success' => true, 'requests' => self::listen(Auth::userId())]);
    }

    /**
     * Die Seite "Anfragen".
     *
     * WARUM SIE EINE EIGENE SEITE IST: Der Guide muss eine Anfrage auch dann
     * noch sehen, wenn er sie im Moment des Eintreffens nicht bemerkt hat -
     * also an einer Stelle, die er im Alltag ohnehin ansteuert. Der Zaehler
     * dafuer steht in der Kopfleiste, auf jeder Seite der Anwendung, gleich
     * neben dem Bereitschaftsschalter (App\Helper\ViewHelper); von dort fuehrt
     * er hierher.
     *
     * Die Seite kommt LEER vom Server und wird von assets/js/requests.js
     * gefuellt. Das ist die Ausnahme von der sonstigen Regel dieser
     * Anwendung - und sie hat einen Grund: Der Inhalt aendert sich, waehrend
     * man hinsieht (der Guide nimmt an, eine Frist laeuft ab), und dieselbe
     * Darstellung muss ohnehin im Takt nachgezogen werden. Eine zweite,
     * serverseitige Fassung derselben Liste waere ein zweiter Bauort fuer
     * dieselben Zeilen.
     *
     * @return void
     */
    public function showRequestsPage()
    {
        ViewHelper::output(ViewHelper::template('assets/html/requests_page.html'));
    }

    // =================================================================
    // Hilfen
    // =================================================================

    /**
     * Beide Listen eines Kontos.
     *
     * @param int $in_user_id
     * @return array{incoming: array, outgoing: array}
     */
    private static function listen($in_user_id): array
    {
        return [
            // "eingehend" heisst: an meine Standorte gerichtet. Wer keine
            // anbietet, bekommt hier schlicht eine leere Liste - dafuer
            // braucht es keine Fallunterscheidung.
            'incoming' => TourRequest::forGuide($in_user_id),
            'outgoing' => TourRequest::forCustomer($in_user_id),
        ];
    }

    /**
     * Darf dieses Konto Standorte anbieten - und damit Fuehrungen zusagen?
     *
     * GEFRAGT WIRD DAS RECHT location.offer, NICHT DIE ROLLE. Dasselbe
     * Kriterium, ueber das ein Standort auf die Karte kommt und ueber das der
     * Anruf zugelassen wird (App\Controller\WebRTCController::offersLocations).
     * Ein Rollenvergleich haette eine kuenftige anbietende Rolle uebergangen.
     *
     * @param int $in_user_id
     * @return bool
     */
    private static function offersLocations($in_user_id): bool
    {
        try {
            $user = new User((int)$in_user_id);
        } catch (\Exception $e) {
            error_log('RequestController::offersLocations: ' . $e->getMessage());
            return false;
        }

        $rolle = Role::id($user->getRoleId());
        return $rolle === null ? false : Permission::has($rolle, Permission::LOCATION_OFFER);
    }

    /**
     * Der JSON-Rumpf der Anfrage.
     *
     * Gelesen wird der ROHE Rumpf und nicht $_POST: Die Aufrufe kommen aus
     * fetch() mit application/json, und dafuer fuellt PHP $_POST nicht.
     *
     * @return array<string,mixed>
     */
    private static function body(): array
    {
        $roh = file_get_contents('php://input');
        $daten = json_decode((string)$roh, true);
        return is_array($daten) ? $daten : [];
    }

    /**
     * Antwortet als JSON und beendet die Anfrage.
     *
     * Wie in App\Controller\LocationController: Der Aufruf ist die letzte
     * Handlung der Methode, damit keine Zeile danach noch etwas ausgibt.
     *
     * @param array $in_payload
     * @return never
     */
    private static function json(array $in_payload)
    {
        header('Content-Type: application/json');
        echo json_encode($in_payload);
        exit;
    }
}
