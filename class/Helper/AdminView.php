<?php
namespace App\Helper;

use App\Model\AdminStats;
use App\Model\TourReview;

/**
 * Die Ansicht des Verwaltungsbereichs: Rahmen, Navigation, Kacheln, Zeilen.
 *
 * WARUM ES DIESEN BEREICH GIBT
 * ----------------------------
 * Die Verwaltungsfunktionen hingen vorher in der Kundenoberflaeche und
 * erzeugten dort Sonderfaelle: Die Standortliste bekam fuer den Admin zwei
 * zusaetzliche Knoepfe und zwei zusaetzliche Zeilenarten, an jeder Bewertung
 * auf Standortseite und Guide-Profil klebte fuer ihn ein "Entfernen", und im
 * Kontomenue stand ein Eintrag, den ausser ihm niemand sah. Jede dieser
 * Stellen war ein "wenn Admin, dann anders" mitten in einer Seite, die fuer
 * Kunden gebaut ist - und jede war eine Gelegenheit, beim naechsten Umbau
 * etwas sichtbar zu machen, was niemand sehen sollte.
 *
 * Jetzt liegen sie hinter einer eigenen Adresse (index.php?act=admin und die
 * drei Listen daneben) mit einer eigenen Navigation. Die Kundenoberflaeche
 * kennt keinen Admin mehr.
 *
 * DERSELBE RAHMEN, EIN ANDERER TON
 * --------------------------------
 * Kopfleiste, Fusszeile, Farbprofile, Knoepfe, Kaesten - alles wie ueberall
 * (App\Helper\ViewHelper::output baut die Seite drumherum). Was sich
 * unterscheidet, ist die Dichte: schmale Zeilen, kleine Schrift, Tabellen
 * statt Karten. Das ist kein zweites Erscheinungsbild, sondern dieselben
 * Bausteine enger gesetzt - die Regeln dafuer stehen in assets/css/admin.css
 * und benutzen ausschliesslich die Variablen aus theme.css. Ein eigenes
 * Farbschema haette der Bereich damit auch dann nicht, wenn jemand das
 * Farbprofil wechselt: Er wechselt mit.
 *
 * WAS HIER NICHT ENTSCHIEDEN WIRD: wer etwas darf. Die Reiter der Navigation
 * fragen zwar Rechte ab - das ist Anzeige. Verbindlich entscheidet index.php
 * anhand von config/routes.php, und zwar erneut, wenn die Route wirklich
 * aufgerufen wird.
 */
class AdminView
{
    /**
     * Die Reiter des Bereichs: Adresse, Beschriftung, benoetigtes Recht.
     *
     * DIE EINE LISTE. Sie steht hier und nicht in vier Vorlagen: Ein Reiter,
     * der auf einer Seite fehlt, waere eine Sackgasse, die niemandem auffaellt
     * - ausser dem, der gerade darin steht.
     *
     * Das Recht je Eintrag ist DASSELBE, das die Route in config/routes.php
     * traegt. Waere es ein anderes, gaebe es einen Reiter, der zu einer
     * Absage fuehrt.
     */
    private const REITER = [
        'uebersicht'  => ['index.php?act=admin'          , 'Übersicht'   , Permission::SYSTEM_ADMIN],
        'benutzer'    => ['index.php?act=list_user'      , 'Benutzer'    , Permission::USER_LIST],
        'standorte'   => ['index.php?act=admin_locations', 'Standorte'   , Permission::LOCATION_BLOCK],
        'bewertungen' => ['index.php?act=admin_reviews'  , 'Bewertungen' , Permission::REVIEW_REMOVE],
    ];

    /**
     * Legt den Rahmen des Bereichs um einen Seiteninhalt.
     *
     * JEDE SEITE DES BEREICHS GEHT HIER DURCH - auch die Benutzerliste und
     * das Benutzerformular, die es schon vorher gab. Genau das ist der
     * Unterschied zu vorher: Sie waren Seiten wie jede andere und nur an
     * ihrer Adresse als Verwaltung zu erkennen.
     *
     * @param string $in_inhalt Das fertige HTML der Seite
     * @param string $in_aktiv  Schluessel des Reiters, der geradesteht
     * @return string HTML fuer ViewHelper::output()
     */
    public static function page(string $in_inhalt, string $in_aktiv = ''): string
    {
        return '<div class="app-page app-page--wide adm">'
             .   '<header class="adm__head">'
             .     '<div>'
             .       '<h1 class="adm__title">Verwaltung</h1>'
             .       '<p class="adm__sub">Konten, Standorte und Bewertungen der Plattform.</p>'
             .     '</div>'
             .   '</header>'
             .   self::navHtml($in_aktiv)
             .   '<div class="adm__body">' . $in_inhalt . '</div>'
             . '</div>';
    }

    /**
     * Die Navigationsleiste des Bereichs.
     *
     * EIN <nav> MIT VERWEISEN und kein Menue: Die vier Ziele sind wenige,
     * gleichrangig und werden oft gewechselt - ein Aufklappen dazwischen
     * waere ein Klick zu viel. Der aktuelle Reiter ist ein <span> und kein
     * Verweis auf sich selbst; aria-current sagt Vorleseprogrammen dasselbe,
     * was das Auge an der Linie darunter sieht.
     *
     * WEGGELASSEN WIRD, WOFUER DAS RECHT FEHLT. Heute hat der Admin alle
     * vier; morgen kann es eine Rolle geben, die nur Bewertungen moderiert
     * (App\Helper\Permission ist darauf angelegt). Sie bekaeme dann einen
     * Bereich mit einem Reiter statt drei Absagen.
     *
     * @param string $in_aktiv
     * @return string HTML
     */
    private static function navHtml(string $in_aktiv): string
    {
        $eintraege = '';
        foreach (self::REITER as $schluessel => [$ziel, $titel, $recht]) {
            if (!Auth::can($recht)) continue;

            $eintraege .= ($schluessel === $in_aktiv)
                ? '<span class="adm__tab" aria-current="page">' . $titel . '</span>'
                : '<a class="adm__tab" href="' . $ziel . '">' . $titel . '</a>';
        }

        return '<nav class="adm__nav" aria-label="Verwaltung">' . $eintraege . '</nav>';
    }

    // =================================================================
    // DIE UEBERSICHT
    // =================================================================

    /**
     * Die Kachelreihen der Uebersicht.
     *
     * VIER GRUPPEN, und jede beantwortet eine Frage, die man beim Aufmachen
     * dieser Seite hat: Wie viele Konten sind das, wer bietet etwas an, wird
     * die Anwendung benutzt, und wie kommt sie an.
     *
     * DIE ZAHLEN KOMMEN FERTIG (App\Model\AdminStats). Hier wird nichts
     * gerechnet - nur gesetzt, welche gross dasteht und welche als Beisatz
     * daneben.
     *
     * @param array<string,mixed> $in_zahlen Aus AdminStats::bestand()
     * @return string HTML
     */
    public static function bestandHtml(array $in_zahlen): string
    {
        $konten      = (array)($in_zahlen['konten']      ?? []);
        $standorte   = (array)($in_zahlen['standorte']   ?? []);
        $fuehrungen  = (array)($in_zahlen['fuehrungen']  ?? []);
        $bewertungen = (array)($in_zahlen['bewertungen'] ?? []);

        $je_rolle = (array)($konten['je_rolle'] ?? []);
        // Die Rollen in fester Reihenfolge und mit ihrem kanonischen Namen -
        // beides aus App\Helper\Role. Eine Rolle ohne Konten steht mit einer
        // Null da und faellt nicht weg: Dass es null Guides gibt, ist die
        // Auskunft.
        $rollen = [];
        foreach (Role::all() as $id) {
            $rollen[] = Role::name($id) . ' ' . (int)($je_rolle[$id] ?? 0);
        }

        $neu = (int)($konten['neu'] ?? 0);

        return '<div class="adm-tiles">'
             . self::kachelHtml(
                 'Konten',
                 (int)($konten['gesamt'] ?? 0),
                 implode(' · ', $rollen),
                 $neu > 0
                     ? $neu . ' neu in ' . AdminStats::NEU_TAGE . ' Tagen'
                     : 'keine neuen in ' . AdminStats::NEU_TAGE . ' Tagen'
               )
             . self::kachelHtml(
                 'Standorte',
                 (int)($standorte['gesamt'] ?? 0),
                 (int)($standorte['anbieter'] ?? 0) . ' Konten bieten an',
                 // DIE EINZIGE ZAHL DIESER SEITE, DIE ARBEIT BEDEUTET: Eine
                 // Sperre ist ein Vorgang, den jemand eroeffnet hat und den
                 // jemand wieder schliessen muss. Sie steht deshalb als
                 // Verweis da und nicht als Beisatz - ein Klick fuehrt in die
                 // Liste, in der genau diese Zeilen stehen.
                 (int)($standorte['gesperrt'] ?? 0) > 0
                     ? '<a href="index.php?act=admin_locations&filter=gesperrt">'
                       . (int)$standorte['gesperrt'] . ' gesperrt</a>'
                     : 'keine gesperrt',
                 (int)($standorte['gesperrt'] ?? 0) > 0
               )
             . self::kachelHtml(
                 'Führungen',
                 (int)($fuehrungen['zeitraum'] ?? 0),
                 'in ' . AdminStats::ZEITRAUM_TAGE . ' Tagen durchgeführt',
                 (int)($fuehrungen['gesamt'] ?? 0) . ' insgesamt · '
                     . (int)($fuehrungen['offen'] ?? 0) . ' laufen gerade'
               )
             . self::kachelHtml(
                 'Bewertungen',
                 (int)($bewertungen['sichtbar'] ?? 0),
                 $bewertungen['schnitt'] === null
                     ? 'noch kein Durchschnitt'
                     : 'Durchschnitt ' . self::zahl((float)$bewertungen['schnitt']),
                 (int)($bewertungen['entfernt'] ?? 0) . ' entfernt'
               )
             . '</div>';
    }

    /**
     * Eine Kachel: Ueberschrift, eine grosse Zahl, zwei Beisaetze.
     *
     * DIE ZAHL IST DER INHALT, alles andere ordnet sie ein. Deshalb steht sie
     * gross und allein in ihrer Zeile - eine Kachel, in der die Zahl
     * mitschwimmt, muss man lesen statt anzusehen.
     *
     * $in_fuss darf HTML enthalten (ein Verweis), $in_titel und $in_zusatz
     * nicht - sie werden maskiert. Der Aufrufer ist ausschliesslich diese
     * Klasse; von aussen kommt hier nichts herein.
     *
     * @param string $in_titel
     * @param int    $in_zahl
     * @param string $in_zusatz Erste Zeile unter der Zahl
     * @param string $in_fuss   Zweite Zeile, darf einen Verweis enthalten
     * @param bool   $in_achtung Hebt den Fuss hervor
     * @return string HTML
     */
    private static function kachelHtml(string $in_titel, int $in_zahl,
                                       string $in_zusatz, string $in_fuss,
                                       bool $in_achtung = false): string
    {
        return '<section class="adm-tile' . ($in_achtung ? ' adm-tile--achtung' : '') . '">'
             .   '<h2 class="adm-tile__title">' . ViewHelper::esc($in_titel) . '</h2>'
             .   '<p class="adm-tile__value">' . number_format($in_zahl, 0, ',', '.') . '</p>'
             .   '<p class="adm-tile__note">' . ViewHelper::esc($in_zusatz) . '</p>'
             .   '<p class="adm-tile__foot">' . $in_fuss . '</p>'
             . '</section>';
    }

    // =================================================================
    // DIE STANDORTLISTE
    // =================================================================

    /**
     * Die Zeilen der Standortliste.
     *
     * GESPERRTE ZUERST, und in der Zeile steht der Grund: Die Liste ist der
     * Ort, an dem ueber eine Freigabe entschieden wird, und die Entscheidung
     * beginnt bei der Frage, warum gesperrt wurde.
     *
     * DER TITEL FUEHRT AUF DIE STANDORTSEITE - dieselbe, die ein Kunde sieht.
     * Das ist Absicht: Wer ueber eine Sperre entscheidet, soll den Standort so
     * ansehen, wie er angeboten wird, und nicht in einer Sonderansicht, die
     * etwas anderes zeigt. Dass ein gesperrter Standort fuer die Moderation
     * ueberhaupt aufgeht, entscheidet weiterhin
     * App\Controller\LocationController::showLocationPage anhand des Rechts
     * location.block.
     *
     * @param array<int,array<string,mixed>> $in_zeilen Aus Location::selectAllForAdmin()
     * @return string HTML
     */
    public static function standortZeilenHtml(array $in_zeilen): string
    {
        if ($in_zeilen === []) {
            return '<tr><td colspan="6" class="adm-empty">Keine Standorte.</td></tr>';
        }

        $html = '';
        foreach ($in_zeilen as $zeile) {
            $id       = (int)($zeile['id'] ?? 0);
            $gesperrt = (int)($zeile['blocked'] ?? 0) === 1;
            $ort      = trim(implode(', ', array_filter([
                            (string)($zeile['city_name']    ?? ''),
                            (string)($zeile['country_name'] ?? ''),
                        ])));
            $titel    = trim((string)($zeile['title'] ?? ''));
            if ($titel === '') $titel = 'Ohne Titel';

            $html .= '<tr' . ($gesperrt ? ' class="adm-row--gesperrt"' : '') . '>'
                  .   '<td class="adm-num">' . $id . '</td>'
                  .   '<td>'
                  .     '<a href="index.php?act=location&id=' . $id . '">'
                  .       ViewHelper::esc($titel) . '</a>'
                  .     ($ort !== '' ? '<span class="adm-sub">' . ViewHelper::esc($ort) . '</span>' : '')
                  .   '</td>'
                  .   '<td>'
                  .     '<a href="index.php?act=guide&id=' . (int)($zeile['user_id'] ?? 0) . '">'
                  .       ViewHelper::esc((string)($zeile['guide_name'] ?? '')) . '</a>'
                  .     '<span class="adm-sub">' . ViewHelper::esc((string)($zeile['username'] ?? '')) . '</span>'
                  .   '</td>'
                  .   '<td>' . self::zustandHtml((string)($zeile['availability'] ?? 'idle')) . '</td>'
                  .   '<td>' . self::sperrHtml($gesperrt, (string)($zeile['blocked_reason'] ?? ''),
                                               $zeile['blocked_at'] ?? null) . '</td>'
                  .   '<td>' . self::sperrKnopfHtml($id, $gesperrt, $titel) . '</td>'
                  . '</tr>';
        }
        return $html;
    }

    /**
     * Die Verfuegbarkeit als Etikett.
     *
     * DIESELBEN DREI WERTE WIE AUF DER KARTE ('live', 'busy', 'idle',
     * App\Model\Location::AVAILABILITY_SQL) und dieselben drei Bedeutungen.
     * Was hier fehlt, ist die FARBE: Gruen, Gelb und Grau gehoeren auf der
     * Karte zur wichtigsten Auskunft der Anwendung, und in einer Tabelle mit
     * fuenfhundert Zeilen waeren sie ein Muster ohne Aussage. Es steht
     * deshalb das Wort da - genauso, wie die Benutzerliste ihren Zustand ueber
     * Form und Gewicht unterscheidet und nicht ueber Ampelfarben.
     *
     * @param string $in_wert
     * @return string HTML
     */
    private static function zustandHtml(string $in_wert): string
    {
        $namen = [
            'live' => 'verfügbar',
            'busy' => 'im Gespräch',
            'idle' => 'nicht da',
        ];
        $text = $namen[$in_wert] ?? $namen['idle'];
        $art  = $in_wert === 'live' ? 'online' : ($in_wert === 'busy' ? 'busy' : 'offline');

        return '<span class="app-state app-state--' . $art . '">'
             .   '<span class="app-state__dot" aria-hidden="true"></span>'
             .   '<span class="app-state__text">' . $text . '</span>'
             . '</span>';
    }

    /**
     * Die Sperrangabe einer Zeile: Grund und Zeitpunkt, oder ein Strich.
     *
     * EIN STRICH UND KEIN LEERES FELD: In einer dichten Tabelle ist eine
     * leere Zelle nicht von einer fehlenden zu unterscheiden.
     *
     * @param bool        $in_gesperrt
     * @param string      $in_grund
     * @param string|null $in_wann
     * @return string HTML
     */
    private static function sperrHtml(bool $in_gesperrt, string $in_grund, $in_wann): string
    {
        if (!$in_gesperrt) return '<span class="adm-none">–</span>';

        $grund = trim($in_grund);
        $wann  = self::datum($in_wann);

        return '<span class="app-tag app-tag--danger">gesperrt</span>'
             . ($grund !== '' ? '<span class="adm-sub">' . ViewHelper::esc($grund) . '</span>' : '')
             . ($wann  !== '' ? '<span class="adm-sub">' . ViewHelper::esc($wann)  . '</span>' : '');
    }

    /**
     * Sperren oder Freigeben - der eine Knopf je Zeile.
     *
     * ZWEI ZUSTAENDE, EIN PLATZ. Nebeneinander stuenden in jeder Zeile zwei
     * Knoepfe, von denen immer einer nichts tut.
     *
     * Der Titel steht im data-Attribut und nicht nur im aria-label: Die
     * Rueckfrage vor dem Sperren nennt den Standort, damit niemand den
     * falschen erwischt (assets/js/admin.js).
     *
     * @param int    $in_id
     * @param bool   $in_gesperrt
     * @param string $in_titel
     * @return string HTML
     */
    private static function sperrKnopfHtml(int $in_id, bool $in_gesperrt, string $in_titel): string
    {
        $name = ViewHelper::esc($in_titel);

        return $in_gesperrt
            ? '<button type="button" class="btn btn-secondary btn-sm adm-unblock"'
              . ' data-id="' . $in_id . '" data-title="' . $name . '">Freigeben</button>'
            : '<button type="button" class="btn btn-outline-danger btn-sm adm-block"'
              . ' data-id="' . $in_id . '" data-title="' . $name . '">Sperren</button>';
    }

    // =================================================================
    // DIE BEWERTUNGSLISTE
    // =================================================================

    /**
     * Die Zeilen der Bewertungsliste.
     *
     * WAS HIER STEHT UND AUF DER STANDORTSEITE NICHT: der Benutzername des
     * Kunden. Das ist der Unterschied zwischen einer oeffentlichen Seite und
     * einer Verwaltung - und der Grund, warum die Moderation hier stattfindet
     * und nicht mehr im Bewertungsblock der Standortseite: Fuer eine
     * Beschwerde ist "kommen die drei Ein-Stern-Wertungen vom selben Konto"
     * die entscheidende Frage, und beantworten laesst sie sich nur mit den
     * Namen nebeneinander.
     *
     * ENTFERNTE ZEILEN BLEIBEN STEHEN und werden gekennzeichnet. Entfernen
     * ist kein Loeschen (App\Model\TourReview::remove); die Verwaltung ist der
     * eine Ort, an dem das auch zu sehen ist.
     *
     * @param array<int,array<string,mixed>> $in_zeilen Aus TourReview::allForAdmin()
     * @return string HTML
     */
    public static function bewertungsZeilenHtml(array $in_zeilen): string
    {
        if ($in_zeilen === []) {
            return '<tr><td colspan="6" class="adm-empty">Keine Bewertungen.</td></tr>';
        }

        $html = '';
        foreach ($in_zeilen as $zeile) {
            $id       = (int)($zeile['id'] ?? 0);
            $entfernt = ($zeile['removed_at'] ?? null) !== null;
            $text     = trim((string)($zeile['body'] ?? ''));
            $titel    = trim((string)($zeile['title'] ?? ''));

            $html .= '<tr' . ($entfernt ? ' class="adm-row--entfernt"' : '') . '>'
                  .   '<td class="adm-num">' . $id . '</td>'
                  .   '<td class="adm-stars">' . self::sterneHtml((int)($zeile['stars'] ?? 0)) . '</td>'
                  .   '<td>'
                  .     ($text !== ''
                          ? '<span class="adm-text">' . ViewHelper::esc($text) . '</span>'
                          : '<span class="adm-none">ohne Text</span>')
                  .     ($entfernt
                          ? '<span class="adm-sub">Entfernt: '
                            . ViewHelper::esc(trim((string)($zeile['removed_reason'] ?? '')) !== ''
                                ? (string)$zeile['removed_reason'] : 'ohne Grund')
                            . '</span>'
                          : '')
                  .   '</td>'
                  .   '<td>'
                  .     ($titel !== ''
                          ? '<a href="index.php?act=location&id=' . (int)($zeile['location_id'] ?? 0) . '">'
                            . ViewHelper::esc($titel) . '</a>'
                          : '<span class="adm-none">–</span>')
                  .     '<span class="adm-sub">Guide: '
                  .       ViewHelper::esc((string)($zeile['guide_username'] ?? '?')) . '</span>'
                  .     '<span class="adm-sub">Kunde: '
                  .       ViewHelper::esc((string)($zeile['customer_username'] ?? '?')) . '</span>'
                  .   '</td>'
                  .   '<td class="adm-date">' . ViewHelper::esc(self::datum($zeile['created_at'] ?? null)) . '</td>'
                  .   '<td>'
                  .     ($entfernt
                          ? '<span class="app-tag app-tag--danger">entfernt</span>'
                          : '<button type="button" class="btn btn-outline-danger btn-sm adm-review-remove"'
                            . ' data-id="' . $id . '">Entfernen</button>')
                  .   '</td>'
                  . '</tr>';
        }
        return $html;
    }

    /**
     * Sterne als Zahl und Zeichen.
     *
     * KEINE HALBEN. In dieser Liste steht immer eine einzelne, abgegebene
     * Bewertung, und abgegeben werden nur ganze (App\Model\TourReview).
     * Halbe gibt es allein in der ANZEIGE eines Durchschnitts - dafuer ist
     * App\Helper\ReviewView zustaendig.
     *
     * @param int $in_sterne
     * @return string HTML
     */
    private static function sterneHtml(int $in_sterne): string
    {
        $wert = max(0, min(TourReview::STARS_MAX, $in_sterne));

        return '<span class="adm-stars__value">' . $wert . '</span>'
             . '<span class="adm-stars__marks" aria-hidden="true">'
             .   str_repeat('★', $wert) . str_repeat('☆', TourReview::STARS_MAX - $wert)
             . '</span>';
    }

    // =================================================================
    // Hilfen
    // =================================================================

    /**
     * Ein Zeitpunkt aus der Datenbank als Datum mit Uhrzeit.
     *
     * MIT UHRZEIT, anders als auf den oeffentlichen Seiten (dort steht der
     * Monat, siehe App\Helper\GuideView::dabeiSeit). Der Unterschied ist der
     * Zweck: Auf einer Kundenseite beantwortet der Tag keine Frage, in einer
     * Verwaltung ist "heute 14:12" oft genau die Frage.
     *
     * @param mixed $in_wert
     * @return string Leerstring, wenn nichts Verwertbares dasteht
     */
    private static function datum($in_wert): string
    {
        $roh = trim((string)($in_wert ?? ''));
        if ($roh === '') return '';

        $zeit = strtotime($roh);
        return $zeit === false ? '' : date('d.m.Y H:i', $zeit);
    }

    /**
     * Eine Kommazahl mit einer Nachkommastelle, deutsch geschrieben.
     *
     * @param float $in_wert
     * @return string
     */
    private static function zahl(float $in_wert): string
    {
        return number_format($in_wert, 1, ',', '.');
    }
}
