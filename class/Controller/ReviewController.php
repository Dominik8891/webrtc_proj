<?php
namespace App\Controller;

use App\Helper\Auth;
use App\Model\RateLimit;
use App\Model\TourReview;

/**
 * ReviewController - eine Fuehrung bewerten, eine Bewertung entfernen.
 *
 * WORUM ES GEHT
 * -------------
 * Ein Kunde soll einem Fremden Geld dafuer geben, dass der ihn per Video
 * durch eine unbekannte Stadt fuehrt. Was ANDERE Kunden mit diesem Guide
 * erlebt haben, stand bisher nirgends. Nach einer durchgefuehrten Fuehrung
 * kann der Kunde deshalb Sterne vergeben und, wenn er mag, ein paar Saetze
 * schreiben.
 *
 * NUR DIESE RICHTUNG: Kunden bewerten Guides. Es gibt hier keine Route und
 * kein Feld fuer den umgekehrten Weg - und im Schema keine Spalte, die ihn
 * darstellen koennte (migrations/016).
 *
 * WAS HIER GEPRUEFT WIRD UND WAS NICHT
 * ------------------------------------
 * Ueber den Zugang zu den Routen entscheidet index.php anhand der Rechte aus
 * config/routes.php. Ein Recht sagt aber nur "diese Rolle darf bewerten",
 * nicht "diese Fuehrung hat stattgefunden und gehoerte diesem Kunden". Diese
 * Pruefung steht in der WHERE-Klausel des Statements
 * (App\Model\TourReview::create) - dasselbe Muster wie bei den Anfragen und
 * beim Eigentum an einem Standort. Hier davor steht nur, was dem Aufrufer
 * eine verstaendliche Antwort gibt.
 *
 * DIE ANTWORT UNTERSCHEIDET NICHT zwischen "gibt es nicht", "gehoert dir
 * nicht", "hat nie stattgefunden" und "schon bewertet": Die Kennungen sind
 * fortlaufend, und vier verschiedene Antworten waeren eine Auskunft darueber,
 * welche Fuehrungen es gibt und wem sie gehoeren.
 */
class ReviewController
{
    /**
     * Nimmt die Bewertung einer Fuehrung entgegen.
     *
     * Erwartet POST mit JSON-Rumpf:
     *   request  Kennung der Fuehrung (tour_request.id)
     *   stars    1 bis 5
     *   body     freiwilliger Text, darf fehlen oder leer sein
     *
     * WEN die Bewertung trifft, steht NICHT in der Anfrage: Guide, Kunde und
     * Standort kommen aus der Aufzeichnung der Fuehrung. Der Browser steuert
     * Sterne und Text bei, sonst nichts.
     *
     * @return void
     */
    public function create()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['success' => false, 'error' => 'Nur per POST.']);
        }

        $daten   = self::body();
        $request = (int)($daten['request'] ?? 0);
        $sterne  = $daten['stars'] ?? null;

        // --- Die Bremse (Befund N-10) -------------------------------------
        //
        // WAS HIER BEGRENZT WIRD, IST NICHT DAS BEWERTEN. Je Fuehrung ist
        // ohnehin nur eine Bewertung moeglich, und das steht als eindeutiger
        // Schluessel in der Tabelle (migrations/016), nicht nur hier.
        //
        // Begrenzt wird das ABKLOPFEN. Diese Route nimmt eine beliebige
        // Fuehrungskennung entgegen und antwortet auf "gibt es nicht",
        // "gehoert dir nicht", "hat nie stattgefunden" und "schon bewertet"
        // bewusst gleich - aber der ERFOLGSFALL unterscheidet sich, und damit
        // liesse sich der Kennungsraum absuchen. Deshalb zaehlt hier JEDER
        // Aufruf und nicht nur der erfolgreiche: Der fehlschlagende ist der,
        // um den es geht.
        $teile = ['konto' => RateLimit::konto(Auth::userId())];
        $rest  = RateLimit::restsperre('review_create', $teile);
        if ($rest > 0) {
            self::json([
                'success' => false,
                'error'   => 'Zu viele Bewertungen in kurzer Zeit. Bitte '
                           . RateLimit::wartehinweis($rest) . ' warten.',
            ]);
        }
        RateLimit::verbuchen('review_create', $teile);

        if ($request < 1) {
            self::json(['success' => false, 'error' => 'Es fehlt die Führung.']);
        }

        // DIE STERNE SIND PFLICHT, der Text ist es nicht. Eine Bewertung ohne
        // Sterne waere ein Kommentar - und ein Kommentar ohne Wertung zaehlt
        // in keinem Durchschnitt mit, stuende aber daneben. Wer nichts sagen
        // will, ueberspringt die Frage.
        if (!TourReview::isValidStars($sterne)) {
            self::json([
                'success' => false,
                'error'   => 'Bitte wählen Sie zwischen einem und fünf Sternen.',
            ]);
        }

        $id = TourReview::create($request, Auth::userId(), $sterne, $daten['body'] ?? '');

        if ($id === null) {
            // Kein Treffer heisst: gibt es nicht, gehoert jemand anderem, hat
            // nie stattgefunden oder ist bereits bewertet. Alles vier ergibt
            // dieselbe Antwort.
            error_log('ReviewController: Fuehrung #' . $request . ' konnte von Benutzer #'
                . Auth::userId() . ' nicht bewertet werden.');
            self::json([
                'success' => false,
                'error'   => 'Diese Führung lässt sich nicht bewerten. '
                           . 'Vielleicht haben Sie sie schon bewertet.',
            ]);
        }

        self::json(['success' => true]);
    }

    /**
     * Ein Admin entfernt eine Bewertung.
     *
     * DER GUIDE KANN DAS NICHT, und das ist der Punkt: Eine Bewertung, die
     * der Bewertete loeschen kann, ist keine Auskunft mehr ueber ihn. Fuer
     * eine Beleidigung oder eine offensichtlich falsche Zuordnung gibt es
     * diesen Weg - er steht bei der Moderation (Recht review.remove) und
     * sonst nirgends.
     *
     * ENTFERNT HEISST AUSGEBLENDET: Die Zeile bleibt stehen und zaehlt
     * nirgends mehr mit (App\Model\TourReview::remove). Nach dem Entfernen
     * laesst sich dieselbe Fuehrung nicht erneut bewerten - sonst waere das
     * Entfernen eine Einladung, dasselbe noch einmal zu schreiben.
     *
     * @return void
     */
    public function remove()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['success' => false, 'error' => 'Nur per POST.']);
        }

        $daten = self::body();
        $id    = (int)($daten['id'] ?? 0);

        if ($id < 1) {
            self::json(['success' => false, 'error' => 'Es fehlt die Bewertung.']);
        }

        if (!TourReview::remove($id, Auth::userId(), (string)($daten['reason'] ?? ''))) {
            self::json([
                'success' => false,
                'error'   => 'Diese Bewertung lässt sich nicht entfernen. '
                           . 'Vielleicht ist sie es bereits.',
            ]);
        }

        self::json(['success' => true]);
    }

    // =================================================================
    // Hilfen
    // =================================================================

    /**
     * Der JSON-Rumpf der Anfrage.
     *
     * Gelesen wird der ROHE Rumpf und nicht $_POST: Die Aufrufe kommen aus
     * fetch() mit application/json, und dafuer fuellt PHP $_POST nicht.
     * Dieselbe Hilfe wie in App\Controller\RequestController.
     *
     * @return array<string,mixed>
     */
    private static function body(): array
    {
        $roh   = file_get_contents('php://input');
        $daten = json_decode((string)$roh, true);
        return is_array($daten) ? $daten : [];
    }

    /**
     * Antwortet als JSON und beendet die Anfrage.
     *
     * Der Aufruf ist die letzte Handlung der Methode, damit keine Zeile
     * danach noch etwas ausgibt.
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
