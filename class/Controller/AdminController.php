<?php
namespace App\Controller;

use App\Helper\AdminView;
use App\Helper\Request;
use App\Helper\ViewHelper;
use App\Model\AdminStats;
use App\Model\Location;
use App\Model\TourRequest;
use App\Model\TourReview;

/**
 * Der Verwaltungsbereich: Uebersicht, Standortliste, Bewertungsliste.
 *
 * WAS DIESER CONTROLLER IST UND WAS NICHT
 * ---------------------------------------
 * Er ZEIGT. Geaendert wird hier nichts: Sperren und Freigeben laufen
 * weiterhin ueber App\Controller\LocationController (block_location /
 * unblock_location), das Entfernen einer Bewertung ueber
 * App\Controller\ReviewController (review_remove), das Anlegen und Loeschen
 * von Konten ueber App\Controller\UserController. Diese Routen gab es
 * vorher, sie pruefen ihre Rechte selbst, und sie sind die eine Stelle, an
 * der die jeweilige Aenderung passiert.
 *
 * Ein zweiter Schreibweg "fuer den Adminbereich" waere genau die Art von
 * Doppelung, wegen der es diesen Bereich ueberhaupt gibt.
 *
 * WARUM DIE UEBERSICHT SYSTEM_ADMIN TRAEGT UND DIE LISTEN NICHT
 * ------------------------------------------------------------
 * Weil die Listen ihr eigenes Recht haben: Wer Standorte sperren darf
 * (location.block), sieht die Standortliste; wer Bewertungen entfernen darf
 * (review.remove), sieht die Bewertungsliste. Heute hat beides nur der Admin.
 * Kaeme morgen eine reine Moderationsrolle dazu, bekaeme sie genau die Liste,
 * zu der ihr Recht passt - ohne dass hier eine Zeile zu aendern waere.
 * config/routes.php traegt dieselben Rechte, und App\Helper\AdminView zeigt
 * die Reiter danach an.
 */
class AdminController
{
    /**
     * Wie viele Zeilen eine Liste hoechstens zeigt.
     *
     * EINE OBERGRENZE UND KEINE SEITENZAHLEN, vorerst: Die Liste ist ein
     * Werkzeug fuer eine Installation mit einigen hundert Standorten. Wird sie
     * groesser, gehoert hier eine Blaetterung hin - und dann an EINER Stelle,
     * nicht in jeder Liste einzeln.
     */
    private const ZEILEN_MAX = 500;

    /**
     * Die Uebersicht - der Einstieg in den Bereich.
     *
     * Sie loest die alte Route 'admin' ab, die eine Zeile Text ausgab
     * ("Willkommen im Admin Panel") und auf die niemand verwiesen hat.
     *
     * @return void
     */
    public function showDashboard(): void
    {
        $out = ViewHelper::template('assets/html/admin_dashboard.html');
        // ZWEI BLOECKE, UND DIE REIHENFOLGE IST DER PUNKT: erst der
        // Arbeitsvorrat, dann der Bestand. Eine Aufgabe, die zwischen
        // Bestandszahlen steht, sieht aus wie eine Bestandszahl.
        $out = str_replace('###VORRAT###', AdminView::vorratHtml(AdminStats::vorrat()), $out);
        $out = str_replace('###TILES###',  AdminView::bestandHtml(AdminStats::bestand()), $out);

        ViewHelper::output(AdminView::page($out, 'uebersicht'));
    }

    /**
     * Die Anfragenliste - die Seite, auf der die beiden Arbeitsvorraete der
     * Uebersicht abgearbeitet werden.
     *
     * VIER ANSICHTEN, und die ersten beiden sind die Vorraete:
     *
     *   haengend       begonnene Fuehrungen, die niemand beendet hat. Die
     *                  laengst laufende steht oben.
     *   unbeantwortet  Anfragen, die ohne Zu- oder Absage verfallen sind -
     *                  im Zeitfenster aus TourRequest::VORRAT_TAGE.
     *   offen          was gerade auf eine Antwort wartet und noch kann.
     *                  Kein Vorrat, sondern der Blick nach vorn: Hier steht,
     *                  was morgen unbeantwortet waere.
     *   alle           der ganze Verlauf.
     *
     * DIE VORGABE IST 'haengend' und nicht 'alle': Wer diese Seite aufruft,
     * kommt von der Uebersicht und sucht die Arbeit, nicht das Archiv.
     *
     * @return void
     */
    public function showRequests(): void
    {
        $filter = self::filter(Request::g('filter'),
                               ['haengend', 'unbeantwortet', 'offen', 'alle']);
        $zeilen = TourRequest::allForAdmin($filter, self::ZEILEN_MAX);

        $out = ViewHelper::template('assets/html/admin_requests.html');
        // KEIN SCHALTER FUER GELOESCHTE KONTEN. Die Vorraete dieser Seite sind
        // Vorgaenge zwischen zwei Konten, und der Weg zum Abarbeiten fuehrt
        // ueber ein Gespraech mit dem Guide - mit einem geloeschten Konto gibt
        // es keines. Was hier stehenbliebe, waere eine Aufgabe, die niemand
        // mehr erledigen kann.
        $out = str_replace('###FILTER###', self::filterHtml('admin_requests', $filter, [
            'haengend'      => 'Hängende Führungen',
            'unbeantwortet' => 'Ohne Antwort',
            'offen'         => 'Offen',
            'alle'          => 'Alle',
        ]), $out);
        $out = str_replace('###COUNT###', (string)count($zeilen), $out);
        $out = str_replace('###ROWS###',  AdminView::anfrageZeilenHtml($zeilen), $out);

        ViewHelper::output(AdminView::page($out, 'anfragen'));
    }

    /**
     * Die Standortliste.
     *
     * DREI ANSICHTEN, EINE ABFRAGE: 'alle', 'gesperrt' und 'unvollstaendig'.
     * Der Filter kommt aus der Adresszeile und wird gegen eine feste Liste
     * geprueft, bevor er das Modell erreicht - alles andere ist 'alle'. Damit
     * ist er zugleich verweisbar: Der Arbeitsvorrat der Uebersicht zeigt auf
     * index.php?act=admin_locations&filter=gesperrt beziehungsweise
     * &filter=unvollstaendig.
     *
     * WAS AN EINEM ANGEBOT FEHLT, steht in JEDER Zeile und nicht nur im
     * dritten Filter: Ein Standort kann gesperrt UND unvollstaendig sein, und
     * wer die Sperrliste durchgeht, soll das Zweite nicht uebersehen.
     *
     * @return void
     */
    public function showLocations(): void
    {
        $filter    = self::filter(Request::g('filter'), ['alle', 'gesperrt', 'unvollstaendig']);
        $geloescht = self::schalter(Request::g('geloescht'));
        $zeilen    = (new Location())->selectAllForAdmin($filter, self::ZEILEN_MAX, $geloescht);

        $out = ViewHelper::template('assets/html/admin_locations.html');
        $out = str_replace('###FILTER###', self::filterHtml('admin_locations', $filter, [
            'alle'           => 'Alle',
            'gesperrt'       => 'Gesperrte',
            'unvollstaendig' => 'Unvollständige',
        ], $geloescht), $out);
        $out = str_replace('###GELOESCHT###', AdminView::geloeschtSchalterHtml(
            'admin_locations', ['filter' => $filter], $geloescht), $out);
        $out = str_replace('###COUNT###', (string)count($zeilen), $out);
        $out = str_replace('###ROWS###',  AdminView::standortZeilenHtml($zeilen), $out);

        ViewHelper::output(AdminView::page($out, 'standorte'));
    }

    /**
     * Die Bewertungsliste.
     *
     * VIER ANSICHTEN, und die dritte ist die, wegen der es die Seite gibt:
     * 'schwach' sind die sichtbaren Bewertungen mit ein oder zwei Sternen -
     * die Zeilen, wegen derer sich ein Guide meldet. 'entfernt' ist das
     * Gedaechtnis: Was ausgeblendet wurde, bleibt nachvollziehbar.
     *
     * @return void
     */
    public function showReviews(): void
    {
        $filter    = self::filter(Request::g('filter'), ['alle', 'sichtbar', 'schwach', 'entfernt']);
        $geloescht = self::schalter(Request::g('geloescht'));
        $zeilen    = TourReview::allForAdmin($filter, self::ZEILEN_MAX, $geloescht);

        $out = ViewHelper::template('assets/html/admin_reviews.html');
        $out = str_replace('###FILTER###', self::filterHtml('admin_reviews', $filter, [
            'alle'     => 'Alle',
            'sichtbar' => 'Sichtbar',
            'schwach'  => '1–2 Sterne',
            'entfernt' => 'Entfernt',
        ], $geloescht), $out);
        $out = str_replace('###GELOESCHT###', AdminView::geloeschtSchalterHtml(
            'admin_reviews', ['filter' => $filter], $geloescht), $out);
        $out = str_replace('###COUNT###', (string)count($zeilen), $out);
        $out = str_replace('###ROWS###',  AdminView::bewertungsZeilenHtml($zeilen), $out);

        ViewHelper::output(AdminView::page($out, 'bewertungen'));
    }

    /**
     * Prueft einen Filterwert aus der Adresszeile gegen die erlaubten.
     *
     * NICHT ABGEWIESEN, SONDERN ZURUECKGEFALLEN: Ein verstellter Wert in der
     * Adresse soll keine Fehlerseite ergeben, sondern die Liste, die ohnehin
     * die Vorgabe ist. Was hier durchgeht, geht als Textbaustein in eine
     * Abfrage - deshalb steht die Pruefung vor dem Modell und nicht erst
     * darin (dort steht sie trotzdem noch einmal).
     *
     * @param mixed    $in_wert
     * @param string[] $in_erlaubt Der erste Eintrag ist die Vorgabe
     * @return string
     */
    private static function filter($in_wert, array $in_erlaubt): string
    {
        $wert = is_string($in_wert) ? trim($in_wert) : '';
        return in_array($wert, $in_erlaubt, true) ? $wert : $in_erlaubt[0];
    }

    /**
     * Der Umschalter ueber einer Liste.
     *
     * DIESELBEN BAUSTEINE WIE DIE UMSCHALTUNG KARTE/LISTE auf der
     * Standortuebersicht (.app-switch in assets/css/theme.css). Der aktive
     * Eintrag ist ein <span> und kein Verweis auf sich selbst - wie bei den
     * Reitern des Bereichs.
     *
     * @param string                $in_route   Zielroute
     * @param string                $in_aktiv   Aktueller Filter
     * @param array<string,string>  $in_auswahl Filterwert => Beschriftung
     * @return string HTML
     */
    private static function filterHtml(string $in_route, string $in_aktiv, array $in_auswahl,
                                       bool $in_geloescht = false): string
    {
        // DER SCHALTER DANEBEN BLEIBT STEHEN, wenn der Filter wechselt. Ohne
        // das haette man beim ersten Filterklick wieder die Vorgabe - und
        // saehe genau die Zeilen nicht mehr, wegen derer man ihn eingeschaltet
        // hat.
        $zusatz = $in_geloescht ? '&geloescht=1' : '';

        $html = '';
        foreach ($in_auswahl as $wert => $titel) {
            $html .= ($wert === $in_aktiv)
                ? '<span class="app-switch__item" aria-current="true">'
                  . ViewHelper::esc($titel) . '</span>'
                : '<a class="app-switch__item" href="index.php?act=' . $in_route
                  . '&filter=' . rawurlencode($wert) . $zusatz . '">'
                  . ViewHelper::esc($titel) . '</a>';
        }

        return '<div class="app-switch" role="group" aria-label="Filter">' . $html . '</div>';
    }

    /**
     * Ein Ja/Nein aus der Adresszeile.
     *
     * NUR "1" IST JA. Alles andere - fehlt, leer, "true", "0", irgendetwas -
     * ist nein. Dieselbe Haltung wie bei filter(): Ein verstellter Wert in
     * der Adresse ergibt die Vorgabe und keine Fehlerseite.
     *
     * @param mixed $in_wert
     * @return bool
     */
    private static function schalter($in_wert): bool
    {
        return is_string($in_wert) && $in_wert === '1';
    }
}
