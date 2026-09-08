<?php
namespace App\Helper;

use App\Model\TourReview;

/**
 * Bewertungen als HTML - und sonst nichts.
 *
 * WAS DIESE KLASSE BAUT
 * ---------------------
 * Genau einen Baustein, an zwei Stellen eingesetzt:
 *
 *   1. auf der STANDORTSEITE   - die Bewertungen zu diesem Standort,
 *   2. auf dem GUIDE-PROFIL    - die Bewertungen dieses Guides.
 *
 * Beide zeigen dasselbe in derselben Form; sie unterscheiden sich in der
 * Ueberschrift und darin, ob bei einer einzelnen Bewertung dabeisteht, um
 * welche Fuehrung es ging (auf der Standortseite waere das die Wiederholung
 * des Seitentitels). Der Guide sieht auf seinem Profil dasselbe wie ein Kunde -
 * er soll lesen, was dort steht, und nicht eine Sonderansicht davon.
 *
 * DER DURCHSCHNITT WIRD HIER NICHT ENTSCHIEDEN
 * --------------------------------------------
 * Ob es ihn ueberhaupt gibt, steht in App\Model\TourReview: Unterhalb von
 * TourReview::MIN_FOR_AVERAGE liefert die Zusammenfassung 'average' als null,
 * und dann steht hier die Zahl der durchgefuehrten Fuehrungen statt einer
 * Wertung. Diese Klasse rechnet nichts nach - sie zeigt, was sie bekommt.
 *
 * WARUM DAS UEBERHAUPT EIN THEMA IST: "1 Bewertung, 3 Sterne" sieht aus wie
 * ein Befund und ist eine einzelne Stimme. Es trifft den, der es am
 * wenigsten verkraftet - den neuen Guide, der noch keine zweite Stimme
 * sammeln konnte. "Neu - 4 Führungen durchgeführt" ist an dieser Stelle die
 * ehrlichere und die freundlichere Auskunft, und sie ist ueberpruefbar.
 *
 * JEDE METHODE IST EINE REINE FUNKTION - dieselbe Regel wie in
 * App\Helper\LocationView und App\Helper\GuideView: Werte rein, HTML raus.
 * Kein Zugriff auf die Sitzung, auf $_REQUEST oder auf die Datenbank. WER
 * etwas sehen darf, entscheidet der Controller.
 *
 * DAS FORMULAR STEHT NICHT HIER, sondern in assets/js/review.js. Es ist die
 * Ausnahme von der sonstigen Regel dieser Anwendung, und sie hat denselben
 * Grund wie bei der Anfragenseite: Gefragt wird nach dem Auflegen, auf
 * irgendeiner Seite, ohne dass der Server dafuer eine Seite ausliefert. Die
 * Woerter der Skala und die Laengengrenze holt sich das Skript trotzdem vom
 * Server (App\Helper\ViewHelper) - eine zweite Fassung derselben Skala waere
 * eine Skala zu viel.
 */
class ReviewView
{
    /**
     * Der ganze Block: Zusammenfassung und die letzten Bewertungen mit Text.
     *
     * DER EINE EINSTIEGSPUNKT. Alles Weitere in dieser Klasse ist ein
     * Baustein davon.
     *
     * @param array{count:int, average:?float, tours:int} $in_summary
     *        Aus App\Model\TourReview::summaryForGuide() bzw. ...ForLocation()
     * @param array<int,array<string,mixed>> $in_bewertungen
     *        Aus TourReview::latestForGuide() bzw. ...ForLocation()
     * @param array<string,mixed> $in_ansicht Was der Controller entschieden hat:
     *        'eigen'      bool   Sieht der Bewertete selbst zu?
     *        'mit_ort'    bool   Steht bei jeder Bewertung die Fuehrung dabei?
     *
     * HIER STAND EIN DRITTER SCHALTER: 'moderation'. Er blendete an jeder
     * Bewertung einen Entfernen-Knopf ein, sobald der Betrachter das Recht
     * review.remove hatte. Damit sah dieser Block fuer einen einzigen
     * Betrachter anders aus als fuer alle anderen - mitten auf einer Seite,
     * die fuer Kunden gebaut ist. Entfernt wird jetzt im Verwaltungsbereich
     * (index.php?act=admin_reviews), und dieser Block sieht fuer jeden
     * gleich aus.
     * @return string HTML
     */
    public static function blockHtml(array $in_summary, array $in_bewertungen,
                                     array $in_ansicht = []): string
    {
        $eigen = !empty($in_ansicht['eigen']);

        return '<section class="app-panel rev-block">'
             .   '<div class="app-panel__body">'
             .     '<h2 class="rev-block__title">'
             .       ($eigen ? 'Ihre Bewertungen' : 'Bewertungen')
             .     '</h2>'
             .     self::zusammenfassungHtml($in_summary, $eigen)
             .     self::listeHtml($in_bewertungen, $in_ansicht)
             .   '</div>'
             . '</section>';
    }

    /**
     * Die Kopfzeile des Blocks: entweder ein Durchschnitt oder das, was
     * anstelle eines Durchschnitts dasteht.
     *
     * DREI FAELLE, und der mittlere ist der, um den es geht:
     *
     *   genug Bewertungen  Sterne, die Zahl, die Anzahl. Der Regelfall.
     *   zu wenige          KEINE Zahl und keine Sterne, sondern die Zahl der
     *                      durchgefuehrten Fuehrungen und der Hinweis, ab
     *                      wann ein Durchschnitt erscheint. Die einzelnen
     *                      Bewertungen stehen trotzdem darunter: Ein
     *                      geschriebener Satz ist eine Stimme, und dass es
     *                      eine ist, sieht der Leser.
     *   noch gar keine     dieselbe Zeile ohne die Bewertungen.
     *
     * @param array{count:int, average:?float, tours:int} $in_summary
     * @param bool  $in_eigen
     * @return string HTML
     */
    public static function zusammenfassungHtml(array $in_summary, bool $in_eigen): string
    {
        $anzahl  = max(0, (int)($in_summary['count'] ?? 0));
        $schnitt = $in_summary['average'] ?? null;
        $touren  = max(0, (int)($in_summary['tours'] ?? 0));

        if ($schnitt !== null) {
            return '<div class="rev-summary">'
                 .   self::sterneHtml((float)$schnitt, 'rev-stars--lg')
                 .   '<span class="rev-summary__value">' . self::zahl((float)$schnitt) . '</span>'
                 .   '<span class="rev-summary__count">'
                 .     self::esc(self::anzahlText($anzahl))
                 .   '</span>'
                 . '</div>';
        }

        return '<div class="rev-summary rev-summary--young">'
             .   '<span class="app-tag">' . ($anzahl > 0 ? 'Noch wenige Bewertungen' : 'Noch keine Bewertung') . '</span>'
             .   '<p class="rev-summary__note">'
             .     self::esc(self::jungText($anzahl, $touren, $in_eigen))
             .   '</p>'
             . '</div>';
    }

    /**
     * Der Satz, der anstelle eines Durchschnitts dasteht.
     *
     * ER NENNT EINE TATSACHE UND KEINE WERTUNG: wie viele Fuehrungen
     * stattgefunden haben, wie viele davon bewertet wurden und ab wann ein
     * Durchschnitt erscheint. Alle drei Angaben sind ueberpruefbar, und keine
     * davon urteilt ueber den Guide.
     *
     * Fuer den Guide SELBST steht dasselbe da, nur an ihn gerichtet: Er soll
     * wissen, warum bei ihm keine Zahl steht - sonst haelt er es fuer einen
     * Fehler.
     *
     * @param int  $in_anzahl Sichtbare Bewertungen
     * @param int  $in_touren Durchgefuehrte Fuehrungen
     * @param bool $in_eigen
     * @return string Unmaskiert - der Aufrufer maskiert
     */
    public static function jungText(int $in_anzahl, int $in_touren, bool $in_eigen): string
    {
        $fehlend = max(1, TourReview::MIN_FOR_AVERAGE - $in_anzahl);

        // Die Fuehrungen zuerst: Sie sind die Auskunft, die es hier wirklich
        // gibt. Bei null Fuehrungen bleibt sie weg - "0 Führungen" ist keine
        // Auskunft, sondern eine Verlegenheit.
        $satz = '';
        if ($in_touren > 0) {
            $satz = ($in_touren === 1
                    ? 'Eine Führung durchgeführt'
                    : $in_touren . ' Führungen durchgeführt')
                  . ($in_anzahl > 0
                     ? ($in_anzahl === 1 ? ', eine davon bewertet. ' : ', ' . $in_anzahl . ' davon bewertet. ')
                     : '. ');
        }

        // ZWEI VERSCHIEDENE AUSSAGEN, und sie duerfen nicht denselben Satz
        // bekommen: "noch zu wenige" ist bei null Bewertungen keine Auskunft,
        // sondern eine Ausrede fuer etwas, das gar nicht da ist.
        if ($in_anzahl === 0) {
            $satz .= $in_eigen
                ? 'Bewertet hat noch niemand. Ab ' . TourReview::MIN_FOR_AVERAGE
                . ' Bewertungen steht hier ein Durchschnitt.'
                : ($in_touren > 0
                   ? 'Geschrieben hat darüber noch niemand.'
                   : 'Hier hat noch keine Führung stattgefunden.');
            return $satz;
        }

        $satz .= $in_eigen
            ? 'Ein Durchschnitt erscheint ab ' . TourReview::MIN_FOR_AVERAGE
            . ' Bewertungen – noch ' . $fehlend . '. Bis dahin steht hier keine Zahl: '
            . 'Eine einzelne Stimme sieht aus wie ein Urteil und ist keins.'
            : 'Für einen Durchschnitt sind es noch zu wenige – er erscheint ab '
            . TourReview::MIN_FOR_AVERAGE . ' Bewertungen.';

        return $satz;
    }

    /**
     * Die Liste der Bewertungen mit Text.
     *
     * Ohne Bewertungen bleibt sie WEG, und zwar ersatzlos: Ein Kasten "hat
     * noch niemand geschrieben" waere eine Auskunft ueber ein leeres Feld und
     * nicht ueber den Guide - die Zeile darueber hat das bereits gesagt.
     *
     * @param array<int,array<string,mixed>> $in_bewertungen
     * @param array<string,mixed>            $in_ansicht
     * @return string HTML
     */
    public static function listeHtml(array $in_bewertungen, array $in_ansicht = []): string
    {
        if ($in_bewertungen === []) return '';

        $zeilen = '';
        foreach ($in_bewertungen as $bewertung) {
            $zeilen .= self::eintragHtml((array)$bewertung, $in_ansicht);
        }

        return '<ul class="rev-list">' . $zeilen . '</ul>';
    }

    /**
     * Eine einzelne Bewertung.
     *
     * WAS DABEISTEHT UND WAS NICHT
     * ----------------------------
     * Sterne, der Text, der Monat - und auf dem Guide-Profil die Fuehrung,
     * um die es ging. KEIN NAME: Ein Kunde hat in dieser Anwendung keinen
     * Anzeigenamen, sondern nur einen Benutzernamen, und der ist die
     * Anmeldekennung (dieselbe Regel wie auf der Profilseite). Er gehoert
     * nicht auf eine Seite, die jeder aufrufen kann.
     *
     * DER MONAT UND NICHT DER TAG, aus demselben Grund wie beim "Guide seit"
     * (App\Helper\GuideView::dabeiSeit, von dort kommt auch die Formatierung):
     * Der Tag beantwortet keine Frage, die jemand hat - "März 2026" sagt
     * alles, worauf es ankommt, naemlich wie alt die Auskunft ist.
     *

     * @param array<string,mixed> $in_bewertung
     * @param array<string,mixed> $in_ansicht
     * @return string HTML
     */
    public static function eintragHtml(array $in_bewertung, array $in_ansicht = []): string
    {
        $sterne = (int)($in_bewertung['stars'] ?? 0);
        $text   = trim((string)($in_bewertung['body'] ?? ''));
        $monat  = GuideView::dabeiSeit($in_bewertung['created_at'] ?? null);

        // Der Titel des Standorts kann fehlen: Die Bewertung ueberlebt seine
        // Loeschung (die Tabelle hat bewusst keine Fremdschluessel).
        $titel = trim((string)($in_bewertung['title'] ?? ''));
        $ort   = (!empty($in_ansicht['mit_ort']) && $titel !== '')
               ? 'Zu „' . $titel . '“'
               : '';

        $meta = implode(' · ', array_filter([$ort, $monat], static fn($t) => $t !== ''));

        return '<li class="rev-item">'
             .   '<div class="rev-item__head">'
             .     self::sterneHtml($sterne)
             .     ($meta !== ''
                    ? '<span class="rev-item__meta">' . self::esc($meta) . '</span>'
                    : '')
             .   '</div>'
             .   ($text !== ''
                  ? '<p class="rev-item__text">' . nl2br(self::esc($text), false) . '</p>'
                  : '')
             . '</li>';
    }

    /**
     * Eine Sternreihe.
     *
     * HALBE STERNE GIBT ES NUR HIER - in der ANZEIGE eines Durchschnitts.
     * Abgegeben werden ausschliesslich ganze (App\Model\TourReview). Gerundet
     * wird auf halbe Sterne, weil ein Bild mit vier Nachkommastellen keines
     * mehr ist; die genaue Zahl steht daneben.
     *
     * FUER VORLESEPROGRAMME steht die Zahl im aria-label und nicht in fuenf
     * einzelnen Zeichen: "Stern Stern Stern Stern Stern" ist keine Auskunft.
     * Die Zeichen selbst sind deshalb aria-hidden.
     *
     * @param float  $in_wert   Sterne, ganz oder halb
     * @param string $in_klasse Zusaetzliche Klasse (z. B. fuer die grosse Reihe)
     * @return string HTML
     */
    public static function sterneHtml($in_wert, string $in_klasse = ''): string
    {
        $wert = max(0.0, min((float)TourReview::STARS_MAX, (float)$in_wert));
        // Auf halbe Sterne runden - siehe oben.
        $halbe = round($wert * 2) / 2;

        $sterne = '';
        for ($i = 1; $i <= TourReview::STARS_MAX; $i++) {
            if ($halbe >= $i) {
                $klasse = 'rev-star rev-star--on';
            } elseif ($halbe >= $i - 0.5) {
                $klasse = 'rev-star rev-star--half';
            } else {
                $klasse = 'rev-star';
            }
            $sterne .= '<span class="' . $klasse . '" aria-hidden="true">★</span>';
        }

        $klassen = 'rev-stars' . ($in_klasse !== '' ? ' ' . $in_klasse : '');

        return '<span class="' . $klassen . '" role="img" aria-label="'
             . self::esc(self::zahl($wert) . ' von ' . TourReview::STARS_MAX . ' Sternen')
             . '">' . $sterne . '</span>';
    }

    /**
     * "12 Bewertungen" - oder "eine Bewertung".
     *
     * @param int $in_anzahl
     * @return string Unmaskiert
     */
    public static function anzahlText(int $in_anzahl): string
    {
        return $in_anzahl === 1 ? '1 Bewertung' : $in_anzahl . ' Bewertungen';
    }

    /**
     * Eine Zahl mit Komma statt Punkt - und ohne ",0".
     *
     * "4,3" auf einer deutschen Seite, und "5" statt "5,0": Die
     * Nachkommastelle sagt nur dann etwas, wenn dort etwas steht.
     *
     * @param float $in_wert
     * @return string
     */
    public static function zahl(float $in_wert): string
    {
        $gerundet = round($in_wert, 1);
        return $gerundet == (int)$gerundet
             ? (string)(int)$gerundet
             : number_format($gerundet, 1, ',', '');
    }

    /**
     * Maskiert Text fuer die Ausgabe in HTML.
     *
     * Gebaut wird das in App\Helper\ViewHelper::esc() - dort steht die eine
     * Fassung der Regel, samt der Begruendung, warum htmlspecialchars allein
     * in dieser Anwendung nicht reicht. Der Text einer Bewertung ist
     * Fremdeingabe wie jede andere.
     *
     * @param mixed $in_wert
     * @return string
     */
    public static function esc($in_wert): string
    {
        return ViewHelper::esc($in_wert);
    }
}
