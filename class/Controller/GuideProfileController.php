<?php
namespace App\Controller;

use App\Helper\Auth;
use App\Helper\GuideView;
use App\Helper\ImageStore;
use App\Helper\Permission;
use App\Helper\Request;
use App\Helper\Role;
use App\Helper\ViewHelper;
use App\Model\GuideProfile;
use App\Model\Location;
use App\Model\TourReview;

/**
 * Die Profilseite eines Guides: zeigen, bearbeiten, Bild ausliefern.
 *
 * WARUM NEBEN GuideController UND NICHT DARIN
 * -------------------------------------------
 * App\Controller\GuideController beantwortet EINE Frage: "moechtest du Guide
 * werden?". Daran haengen die Rolle, die Bedingungen und spaeter die
 * Abrechnung - Vertragsstoff. Diese Klasse hier zeigt einen Menschen und
 * nimmt entgegen, was er ueber sich sagen moechte. Die beiden haben nichts
 * miteinander zu tun ausser der Tabelle, in der sie stehen, und diese
 * Trennung setzt sich in den Modellen fort (App\Model\GuideRole gegenueber
 * App\Model\GuideProfile).
 *
 * WER WAS DARF - und wo das entschieden wird:
 *
 *   ANSEHEN (guide.view)          jeder, auch ein Gast. Ein Guide soll den
 *                                 Link weitergeben koennen; einer, der beim
 *                                 Empfaenger auf dem Anmeldeformular endet,
 *                                 wird nicht weitergegeben. Dieselbe
 *                                 Ueberlegung wie bei der Standortseite.
 *   BEARBEITEN (guide.profile_edit)  wer Standorte anbieten darf. WESSEN
 *                                 Profil bearbeitet wird, steht nicht in der
 *                                 Anfrage, sondern in der Sitzung - immer das
 *                                 des Angemeldeten. Eine Benutzerkennung aus
 *                                 dem Formular wird bewusst nicht gelesen.
 *
 * WER EINE PROFILSEITE HAT
 * ------------------------
 * Wer Guide ist - oder wer Standorte anbietet, ohne die Rolle zu tragen (ein
 * Admin). Die zweite Bedingung ist keine Feinheit: Von jedem Standort fuehrt
 * ein Verweis auf das Profil seines Anbieters, und ein Verweis, der ins Leere
 * zeigt, ist schlimmer als keiner.
 *
 * Wer die Rolle zurueckgegeben hat, hat auch keine Standorte mehr
 * (GuideRole::resign laesst das nicht zu) - fuer ihn gibt es die Seite nicht
 * mehr. Sein Profil bleibt in der Datenbank stehen; nimmt er die Rolle wieder
 * an, ist es wieder da.
 */
class GuideProfileController
{
    /**
     * Zeigt die Profilseite eines Guides.
     *
     * Antwortet fuer ein Konto ohne Profilseite genauso wie fuer eines, das
     * es gar nicht gibt. Zwei unterscheidbare Antworten waeren eine Auskunft
     * darueber, welche Kennungen belegt sind.
     *
     * @return void
     */
    public function showProfilePage(): void
    {
        $user_id = (int)Request::g('id');
        $profil  = GuideProfile::forUser($user_id);

        if ($profil === null) {
            self::zeigeFehlseite();
            return;
        }

        $eigen        = $user_id > 0 && $user_id === Auth::userId();
        $darf_sperren = Auth::can(Permission::LOCATION_BLOCK);

        // GESPERRTE STANDORTE sieht hier nur, wer sie ueberall sieht: ihr
        // Eigentuemer und die Moderation. Sonst waere die Sperre ueber den
        // Umweg "Profil des Anbieters" wirkungslos.
        $standorte = (new Location())->selectLocationsOfGuide($user_id, $eigen || $darf_sperren);

        // Ein Konto ohne Guide-Rolle und ohne Standorte ist kein Guide - es
        // bekommt keine Seite. Ein Guide OHNE Standorte bekommt eine: Er hat
        // sich fuer die Rolle entschieden, und sein Profil soll fertig sein,
        // bevor der erste Standort daran haengt.
        if (!Role::isGuide($profil['type_id'] ?? null) && $standorte === []) {
            self::zeigeFehlseite();
            return;
        }

        ViewHelper::output(GuideView::page($profil, $standorte, [
            'eigen' => $eigen,
            // WAS KUNDEN UEBER DIESEN GUIDE GESAGT HABEN - ueber alle seine
            // Standorte hinweg. Das ist der Unterschied zur Standortseite:
            // Dort steht, wie die Fuehrungen an EINEM Ort waren, hier, wie
            // die Fuehrungen dieses MENSCHEN waren.
            //
            // Fuer jeden Betrachter dasselbe, auch fuer den Gast und auch
            // fuer den Guide selbst: Er soll lesen, was seine Kunden lesen.
            // Ob ein Durchschnitt dabei ist, entscheidet
            // App\Model\TourReview - unterhalb der Schwelle kommt er als
            // null zurueck, und dann steht dort die Zahl der durchgefuehrten
            // Fuehrungen statt einer Wertung.
            'review_summary' => TourReview::summaryForGuide($user_id),
            'reviews'        => TourReview::latestForGuide($user_id),
            // Der Entfernen-Knopf. Er haengt am Recht review.remove, das
            // heute nur der Admin hat - der GUIDE hat es ausdruecklich nicht,
            // auch auf seinem eigenen Profil nicht: Eine Bewertung, die der
            // Bewertete loeschen kann, ist keine Auskunft mehr ueber ihn.
            'moderation'     => Auth::can(Permission::REVIEW_REMOVE),
        ]));
    }

    /**
     * Liefert das Avatarbild eines Guides aus.
     *
     * DER EINZIGE WEG, auf dem diese Datei einen Browser erreicht. Sie liegt
     * ausserhalb des Document Root (config/uploads.php); der Webserver kommt
     * gar nicht an sie heran.
     *
     * Geprueft wird dasselbe wie bei einem Standortbild
     * (LocationController::serveImage), soweit es hier eine Entsprechung hat:
     *   1. Gibt es ueberhaupt ein eingetragenes Bild?
     *   2. Ist der Name einer, den ImageStore selbst vergeben hat? Zwischen
     *      der Datenbankzeile und dem Dateisystem soll keine Annahme stehen,
     *      sondern eine Pruefung (ImageStore::avatarPathFor).
     *   3. Liegt die Datei wirklich da?
     *
     * Eine Sperre gibt es hier nicht: Ein Avatarbild haengt an keinem
     * Standort. Wird das KONTO geloescht, verschwindet die Zeile mit
     * (ON DELETE CASCADE) - und damit die Auskunft, welche Datei zu ihm
     * gehoert.
     *
     * Alle Ablehnungen antworten gleich (404).
     *
     * @return void
     */
    public function serveAvatar(): void
    {
        $user_id = (int)Request::g('id');
        // Nur zwei Groessen, und die Vorgabe ist die kleine: Ein Tippfehler
        // im Parameter soll nicht die grosse Datei ausliefern.
        $groesse = Request::g('size') === 'full' ? 'full' : 'thumb';

        $name = GuideProfile::avatarOf($user_id);
        if ($name === null) {
            self::bildFehlt();
        }

        $pfad = ImageStore::avatarPathFor($user_id, $name, $groesse);
        if ($pfad === null || !is_file($pfad) || !is_readable($pfad)) {
            error_log('serveAvatar: Datei fehlt zu Benutzer #' . $user_id . ' (' . $groesse . ')');
            self::bildFehlt();
        }

        // Der Inhalt einer Datei aendert sich nie: Ein neues Bild bekommt
        // einen neuen Namen. Der Name ist damit als ETag brauchbar, und ein
        // Browser laedt jedes Bild genau einmal.
        $etag = '"' . $name . '-' . $groesse . '"';
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            header('ETag: ' . $etag);
            exit;
        }

        // public und nicht private - anders als beim Standortbild: Dieses
        // Bild haengt an keiner Berechtigung. Es ist fuer jeden dasselbe,
        // auch fuer einen Gast, und darf deshalb in einem gemeinsamen
        // Zwischenspeicher liegen.
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($pfad));
        header('ETag: ' . $etag);
        header('Cache-Control: public, max-age=86400');
        // Gespeichert wird ausschliesslich JPEG (ImageStore). Der Zusatz
        // haelt einen Browser davon ab, den Typ selbst zu erraten - genau
        // darueber wurde frueher aus einem "Bild" eine HTML-Seite.
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: inline');

        readfile($pfad);
        exit;
    }

    /**
     * Nimmt das Profilformular der Kontoseite entgegen.
     *
     * EIN FORMULAR, ZWEI ARTEN VON DATEN: die Textfelder und - falls eines
     * gewaehlt wurde - das Bild. Beide werden nacheinander behandelt, und die
     * Textfelder zuerst: Sie sind der Regelfall, sie koennen nicht scheitern,
     * und ein misslungener Upload soll den eben getippten Text nicht
     * mitnehmen.
     *
     * DIE REIHENFOLGE BEIM BILD ist dieselbe wie bei den Standortbildern:
     * erst die Datei, dann die Zeile, DANN das alte Bild loeschen. Andersherum
     * verwiese die Zeile auf ein Bild, das es nicht mehr gibt - und das zeigt
     * jede Seite als kaputtes Bild an.
     *
     * Nur per POST: Der Vorgang aendert Daten. Als Link in einer Mail oder in
     * einem fremden Bild aufrufbar darf so etwas nicht sein.
     *
     * @return void
     */
    public function saveProfile(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::zurueck();
        }

        // IMMER DAS EIGENE PROFIL. Eine Kennung aus der Anfrage wird nicht
        // gelesen - sonst liessen sich fremde Profile umschreiben.
        $user_id = Auth::userId();

        if (!GuideProfile::save($user_id, [
                'display_name' => Request::g('display_name', ''),
                'about'        => Request::g('about', ''),
                'languages'    => self::rohSprachen(),
            ])) {
            self::zurueck('Das Profil konnte nicht gespeichert werden.');
        }

        $fehler = self::uebernehmeBild($user_id);
        if ($fehler !== null) {
            self::zurueck($fehler);
        }

        self::zurueck();
    }

    /**
     * Entfernt das Avatarbild.
     *
     * EIGENER WEG UND KEIN FELD IM FORMULAR: Es ist das Gegenteil von
     * "speichern", und ein Kaestchen "Bild loeschen" neben einem Dateifeld
     * wird versehentlich angehakt.
     *
     * Erst die Zeile, dann die Datei - andersherum als beim Hochladen und aus
     * demselben Grund: Was in der Datenbank steht, muss auf der Platte
     * liegen. Bleibt die Datei liegen, ist das belegter Plattenplatz; bleibt
     * die Zeile stehen, ist es ein kaputtes Bild auf jeder Seite.
     *
     * @return void
     */
    public function deleteAvatar(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::zurueck();
        }

        $user_id = Auth::userId();
        $alt     = GuideProfile::avatarOf($user_id);

        if ($alt === null) {
            // Es gibt nichts zu entfernen - der gewuenschte Zustand ist
            // bereits da. Das ist kein Fehler.
            self::zurueck();
        }

        if (!GuideProfile::setAvatar($user_id, null)) {
            self::zurueck('Das Bild konnte nicht entfernt werden.');
        }

        ImageStore::deleteAvatar($user_id, $alt);
        self::zurueck();
    }

    /**
     * Nimmt das hochgeladene Bild an, wenn eines dabei war.
     *
     * @param int $in_user_id
     * @return string|null Fehlermeldung, oder null wenn alles gut ging
     */
    private static function uebernehmeBild(int $in_user_id): ?string
    {
        $datei = $_FILES['avatar'] ?? null;

        // KEIN BILD IST DER REGELFALL: Wer nur seinen Text aendert, schickt
        // ein leeres Dateifeld mit. UPLOAD_ERR_NO_FILE ist deshalb kein
        // Fehler, sondern das Ende dieser Methode.
        if (!is_array($datei)
            || (int)($datei['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $alt      = GuideProfile::avatarOf($in_user_id);
        $ergebnis = ImageStore::storeAvatar($datei, $in_user_id);

        if (empty($ergebnis['ok'])) {
            return (string)($ergebnis['error'] ?? 'Das Bild konnte nicht gespeichert werden.');
        }

        if (!GuideProfile::setAvatar($in_user_id, $ergebnis['name'])) {
            // Die Datei liegt schon da, die Zeile fehlt: Sie wieder
            // wegzuraeumen ist der einzige Weg, der keinen Muell hinterlaesst.
            ImageStore::deleteAvatar($in_user_id, $ergebnis['name']);
            return 'Das Bild konnte nicht gespeichert werden.';
        }

        // ERST JETZT das alte Bild. Bis hierher haette jeder Fehlschlag den
        // Guide ohne Bild zurueckgelassen.
        if ($alt !== null && $alt !== $ergebnis['name']) {
            ImageStore::deleteAvatar($in_user_id, $alt);
        }

        return null;
    }

    /**
     * Die angekreuzten Sprachen, so wie sie aus dem Formular kommen.
     *
     * Das Feld heisst languages[] und kommt als Array an; Request::g() liefert
     * nur Skalare. Geprueft und normalisiert wird in App\Helper\Languages -
     * hier wird nur eingesammelt.
     *
     * @return array<int,mixed>
     */
    private static function rohSprachen(): array
    {
        $roh = $_POST['languages'] ?? [];
        return is_array($roh) ? $roh : [];
    }

    /**
     * Zurueck auf die Kontoseite - mit oder ohne Meldung.
     *
     * Post/Redirect/Get: Ein Neuladen der Seite soll nicht ein zweites Mal
     * speichern. Die Meldung reist als Klartext in der Adresse mit und wird
     * dort maskiert wieder herausgeholt (ViewHelper::hinweisHtml) - nicht als
     * Nummer, die man erst nachschlagen muss.
     *
     * @param string $in_fehler Leerstring meldet Erfolg
     * @return never
     */
    private static function zurueck(string $in_fehler = '')
    {
        $ziel = 'index.php?act=settings';
        $ziel .= $in_fehler === ''
            ? '&gespeichert=1'
            : '&fehler=' . rawurlencode($in_fehler);

        header('Location: ' . $ziel);
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
     * Zeigt "Guide nicht gefunden" als vollstaendige Seite.
     *
     * @return void
     */
    private static function zeigeFehlseite(): void
    {
        http_response_code(404);
        ViewHelper::output(
            '<div class="app-page app-page--narrow">'
          . '<div class="app-panel"><div class="app-panel__body">'
          . '<h1 class="app-page-head__title">Guide nicht gefunden</h1>'
          . '<p class="app-page-head__sub">Dieses Profil gibt es nicht mehr, '
          . 'oder es hat es nie gegeben.</p>'
          . '<div class="app-actions">'
          . '<a class="btn btn-primary" href="index.php?act=home">Zur Karte</a>'
          . '</div></div></div></div>'
        );
    }
}
