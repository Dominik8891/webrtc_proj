<?php
namespace App\Helper;

use App\Model\AdminStats;
use App\Model\Location;
use App\Model\TourRequest;
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
     * Die Reiter des Bereichs: Adresse, Katalogschluessel, benoetigtes Recht.
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
        'uebersicht'  => ['index.php?act=admin'          , 'verwaltung.reiter.uebersicht' , Permission::SYSTEM_ADMIN],
        'benutzer'    => ['index.php?act=list_user'      , 'verwaltung.reiter.benutzer'   , Permission::USER_LIST],
        'anfragen'    => ['index.php?act=admin_requests' , 'verwaltung.reiter.anfragen'   , Permission::REQUEST_LIST_ALL],
        'standorte'   => ['index.php?act=admin_locations', 'verwaltung.reiter.standorte'  , Permission::LOCATION_BLOCK],
        'bewertungen' => ['index.php?act=admin_reviews'  , 'verwaltung.reiter.bewertungen', Permission::REVIEW_REMOVE],
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
             .       '<h1 class="adm__title">'
             .         ViewHelper::esc(I18n::t('verwaltung.titel')) . '</h1>'
             .       '<p class="adm__sub">'
             .         ViewHelper::esc(I18n::t('verwaltung.untertitel')) . '</p>'
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
        foreach (self::REITER as $schluessel => [$ziel, $text, $recht]) {
            if (!Auth::can($recht)) continue;

            $titel = ViewHelper::esc(I18n::t($text));
            $eintraege .= ($schluessel === $in_aktiv)
                ? '<span class="adm__tab" aria-current="page">' . $titel . '</span>'
                : '<a class="adm__tab" href="' . $ziel . '">' . $titel . '</a>';
        }

        return '<nav class="adm__nav" aria-label="'
             . ViewHelper::esc(I18n::t('verwaltung.nav')) . '">' . $eintraege . '</nav>';
    }

    // =================================================================
    // DIE UEBERSICHT
    // =================================================================

    /**
     * DER ARBEITSVORRAT - was auf jemanden wartet.
     *
     * ER STEHT AUF DER UEBERSICHT GANZ OBEN, vor den Bestandszahlen, und das
     * ist die ganze Begruendung fuer diesen eigenen Block: Eine Aufgabe, die
     * zwischen Bestandszahlen steht, sieht aus wie eine Bestandszahl. "42
     * Konten" nimmt man zur Kenntnis; "3 haengende Fuehrungen" soll jemanden
     * dazu bringen, etwas zu tun.
     *
     * GEZEIGT WIRD NUR, WAS OFFEN IST. Eine Zeile mit einer Null, die jeden
     * Tag dasteht, erzieht dazu, den ganzen Block zu ueberlesen - und dann
     * faellt die Vier daneben auch nicht mehr auf. Ist nichts offen, steht
     * dort EIN Satz, der aufzaehlt, was geprueft wurde: Erst damit ist die
     * Leere eine Auskunft und nicht bloss ein leerer Kasten.
     *
     * JEDE ZEILE FUEHRT DORTHIN, WO SICH DER VORRAT ABARBEITEN LAESST - aber
     * nur, wenn der Betrachter die Seite auch aufrufen darf. Die Zahl sieht
     * er in jedem Fall (er ist auf dieser Uebersicht, also hat er
     * system.admin); der Verweis waere sonst ein Klick in eine Absage.
     *
     * @param array{haengend:int, unbeantwortet:int, gesperrt:int} $in_vorrat
     *        Aus App\Model\AdminStats::vorrat()
     * @return string HTML
     */
    public static function vorratHtml(array $in_vorrat): string
    {
        $haengend       = max(0, (int)($in_vorrat['haengend']       ?? 0));
        $unbeantwortet  = max(0, (int)($in_vorrat['unbeantwortet']  ?? 0));
        $gesperrt       = max(0, (int)($in_vorrat['gesperrt']       ?? 0));
        $unvollstaendig = max(0, (int)($in_vorrat['unvollstaendig'] ?? 0));
        $cron           = max(0, (int)($in_vorrat['cron']           ?? 0));

        $zeilen = '';

        // DIE BESCHRIFTUNG WAEHLT IHRE FORM UEBER DEN KATALOG
        // (I18n::plural) und nicht mehr ueber ein angehaengtes "n" im
        // Code: "Anfrage" + "n" ist eine deutsche Regel, und die naechste
        // Sprache bildet ihren Plural anders. Die ZAHL steht daneben in
        // ihrer eigenen Spalte - deshalb traegt der Text kein {n}.
        //
        // ZUERST DIE HAENGENDEN FUEHRUNGEN, weil sie als Einzige gerade
        // JEMANDEN aufhalten: Solange eine offen ist, steht beim Kunden der
        // Startknopf und die Bewertung wird nicht faellig.
        if ($haengend > 0) {
            $zeilen .= self::vorratZeileHtml(
                $haengend,
                I18n::plural('verwaltung.vorrat.haengend', $haengend),
                I18n::t('verwaltung.vorrat.haengend_text'),
                'index.php?act=admin_requests&filter=haengend',
                Permission::REQUEST_LIST_ALL
            );
        }

        if ($unbeantwortet > 0) {
            $zeilen .= self::vorratZeileHtml(
                $unbeantwortet,
                I18n::plural('verwaltung.vorrat.unbeantwortet', $unbeantwortet),
                I18n::t('verwaltung.vorrat.unbeantwortet_text',
                        ['tage' => TourRequest::VORRAT_TAGE]),
                'index.php?act=admin_requests&filter=unbeantwortet',
                Permission::REQUEST_LIST_ALL
            );
        }

        if ($gesperrt > 0) {
            $zeilen .= self::vorratZeileHtml(
                $gesperrt,
                I18n::plural('verwaltung.vorrat.gesperrt', $gesperrt),
                I18n::t('verwaltung.vorrat.gesperrt_text'),
                'index.php?act=admin_locations&filter=gesperrt',
                Permission::LOCATION_BLOCK
            );
        }

        if ($unvollstaendig > 0) {
            $zeilen .= self::vorratZeileHtml(
                $unvollstaendig,
                I18n::plural('verwaltung.vorrat.unvollstaendig', $unvollstaendig),
                I18n::t('verwaltung.vorrat.unvollstaendig_text'),
                'index.php?act=admin_locations&filter=unvollstaendig',
                Permission::LOCATION_BLOCK
            );
        }

        // ZULETZT, WEIL ES KEIN VORGANG IST, SONDERN EIN BEFUND ueber die
        // Installation: Hier ist nichts abzuarbeiten, hier ist etwas
        // einzurichten. Er steht trotzdem in dieser Liste - er faellt sonst
        // nirgends auf, und das ist ja gerade das Problem.
        if ($cron > 0) {
            $zeilen .= self::vorratZeileHtml(
                $cron,
                I18n::plural('verwaltung.vorrat.cron', $cron),
                I18n::t('verwaltung.vorrat.cron_text'),
                'index.php?act=list_user',
                Permission::USER_LIST
            );
        }

        if ($zeilen === '') {
            return '<p class="adm-vorrat__leer">'
                 . ViewHelper::esc(I18n::t('verwaltung.vorrat.leer')) . '</p>';
        }

        return '<ul class="adm-vorrat">' . $zeilen . '</ul>';
    }

    /**
     * Eine Zeile des Arbeitsvorrats.
     *
     * DIE ZAHL LINKS UND GROSS, daneben was es ist und warum es zaehlt. Der
     * Satz ist nicht Zierde: Wer diese Seite zum ersten Mal sieht, weiss
     * nicht, was "haengend" bedeutet - und ohne das ist die Zahl keine
     * Aufgabe, sondern ein Raetsel.
     *
     * OHNE DAS RECHT KEIN VERWEIS, aber die Zeile bleibt: Dass etwas offen
     * ist, darf jeder wissen, der auf diese Uebersicht darf. Nur der Weg
     * dorthin haengt am Recht der Zielseite.
     *
     * @param int    $in_zahl
     * @param string $in_titel  Wird maskiert
     * @param string $in_text   Wird maskiert
     * @param string $in_ziel   Adresse der Liste
     * @param string $in_recht  Recht, das die Zielseite verlangt
     * @return string HTML
     */
    private static function vorratZeileHtml(int $in_zahl, string $in_titel,
                                            string $in_text, string $in_ziel,
                                            string $in_recht): string
    {
        $titel = ViewHelper::esc($in_titel);
        $kopf  = Auth::can($in_recht)
               ? '<a href="' . $in_ziel . '">' . $titel . '</a>'
               : $titel;

        return '<li class="adm-vorrat__item">'
             .   '<span class="adm-vorrat__count">' . $in_zahl . '</span>'
             .   '<span class="adm-vorrat__body">'
             .     '<span class="adm-vorrat__title">' . $kopf . '</span>'
             .     '<span class="adm-vorrat__note">' . ViewHelper::esc($in_text) . '</span>'
             .   '</span>'
             . '</li>';
    }

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
                 I18n::t('verwaltung.kachel.konten'),
                 (int)($konten['gesamt'] ?? 0),
                 implode(' · ', $rollen),
                 $neu > 0
                     ? I18n::t('verwaltung.kachel.konten_neu',
                               ['n' => $neu, 'tage' => AdminStats::NEU_TAGE])
                     : I18n::t('verwaltung.kachel.konten_keine_neuen',
                               ['tage' => AdminStats::NEU_TAGE])
               )
             . self::kachelHtml(
                 I18n::t('verwaltung.kachel.standorte'),
                 (int)($standorte['gesamt'] ?? 0),
                 I18n::plural('verwaltung.kachel.standorte_anbieter',
                              (int)($standorte['anbieter'] ?? 0)),
                 // HIER STAND DIE SPERRE ALS VERWEIS UND MIT HERVORHEBUNG.
                 // Sie ist in den Arbeitsvorrat darueber gezogen, wo sie
                 // hingehoert: Eine Sperre ist eine Aufgabe und keine
                 // Bestandszahl. Genannt wird sie hier trotzdem - zum Bestand
                 // gehoert sie auch.
                 I18n::t('verwaltung.kachel.standorte_gesperrt',
                         ['n' => (int)($standorte['gesperrt'] ?? 0)])
               )
             . self::kachelHtml(
                 I18n::t('verwaltung.kachel.fuehrungen'),
                 (int)($fuehrungen['zeitraum'] ?? 0),
                 I18n::t('verwaltung.kachel.fuehrungen_zeitraum',
                         ['tage' => AdminStats::ZEITRAUM_TAGE]),
                 I18n::t('verwaltung.kachel.fuehrungen_fuss', [
                     'gesamt' => (int)($fuehrungen['gesamt'] ?? 0),
                     'offen'  => (int)($fuehrungen['offen']  ?? 0),
                 ])
               )
             . self::kachelHtml(
                 I18n::t('verwaltung.kachel.bewertungen'),
                 (int)($bewertungen['sichtbar'] ?? 0),
                 $bewertungen['schnitt'] === null
                     ? I18n::t('verwaltung.kachel.kein_schnitt')
                     : I18n::t('verwaltung.kachel.schnitt',
                               ['wert' => self::zahl((float)$bewertungen['schnitt'])]),
                 I18n::t('verwaltung.kachel.entfernt',
                         ['n' => (int)($bewertungen['entfernt'] ?? 0)])
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
     * KEINE KACHEL IST HERVORGEHOBEN, und seit dem Arbeitsvorrat darueber ist
     * das auch richtig: Hier stehen ausschliesslich Bestandszahlen, und keine
     * davon ist dringender als eine andere. Was dringend ist, steht oben.
     *
     * Alle vier Angaben werden maskiert. Der Aufrufer ist ausschliesslich
     * diese Klasse; von aussen kommt hier nichts herein - die Regel gilt
     * trotzdem, weil sie sonst beim naechsten Aufrufer vergessen wird.
     *
     * @param string $in_titel
     * @param int    $in_zahl
     * @param string $in_zusatz Erste Zeile unter der Zahl
     * @param string $in_fuss   Zweite Zeile
     * @return string HTML
     */
    private static function kachelHtml(string $in_titel, int $in_zahl,
                                       string $in_zusatz, string $in_fuss): string
    {
        return '<section class="adm-tile">'
             .   '<h2 class="adm-tile__title">' . ViewHelper::esc($in_titel) . '</h2>'
             .   '<p class="adm-tile__value">' . ViewHelper::ganzzahl($in_zahl) . '</p>'
             .   '<p class="adm-tile__note">' . ViewHelper::esc($in_zusatz) . '</p>'
             .   '<p class="adm-tile__foot">' . ViewHelper::esc($in_fuss) . '</p>'
             . '</section>';
    }

    /**
     * Der Umschalter "Geloeschte Konten einblenden".
     *
     * EIN SCHALTER UND KEIN FILTERWERT, und das ist der Punkt: "gesperrt" und
     * "gehoert einem geloeschten Konto" schliessen sich nicht aus. Waere das
     * Geloeschte einer der Filterwerte, liesse sich "gesperrte Standorte
     * geloeschter Konten" gar nicht mehr ansehen - und das ist genau die
     * Liste, die man nach einer Loeschung durchgeht. Der Schalter steht
     * deshalb NEBEN dem Filter und laesst ihn stehen.
     *
     * DIE VORGABE IST AUS. Was einem geloeschten Konto gehoert, ist ueberall
     * sonst verschwunden; in der Verwaltung bleibt es auffindbar, aber es
     * steht nicht im Weg. Wer danach sucht, blendet es ein.
     *
     * DERSELBE SCHALTER IN ALLEN DREI LISTEN - Standorte, Bewertungen,
     * Benutzer. Er steht deshalb hier und nicht dreimal nachgebaut: Drei
     * Fassungen waeren drei Gelegenheiten, dass eine Liste ihn anders
     * beschriftet oder den Filter daneben verliert.
     *
     * @param string               $in_route      Zielroute
     * @param array<string,string> $in_parameter  Was erhalten bleibt (z. B. der Filter)
     * @param bool                 $in_an         Sind sie gerade eingeblendet?
     * @return string HTML
     */
    public static function geloeschtSchalterHtml(string $in_route, array $in_parameter,
                                                 bool $in_an): string
    {
        // Die Adresse traegt die uebrigen Angaben weiter. Ohne das faende
        // sich der Schalter zwar, aber er wuerfe den Filter daneben weg -
        // und man saehe statt "gesperrte, auch geloeschte" wieder alles.
        $teile = [];
        foreach ($in_parameter as $name => $wert) {
            $teile[] = rawurlencode((string)$name) . '=' . rawurlencode((string)$wert);
        }
        // Ausgeschaltet wird durch WEGLASSEN und nicht durch "geloescht=0":
        // Die Vorgabe soll die kurze Adresse sein, nicht eine mit einer Null.
        if (!$in_an) $teile[] = 'geloescht=1';

        $ziel = 'index.php?act=' . rawurlencode($in_route)
              . ($teile === [] ? '' : '&' . implode('&', $teile));

        return '<a class="adm-toggle' . ($in_an ? ' adm-toggle--an' : '') . '"'
             . ' href="' . $ziel . '"'
             . ' aria-pressed="' . ($in_an ? 'true' : 'false') . '">'
             .   '<span class="adm-toggle__box" aria-hidden="true"></span>'
             .   ViewHelper::esc(I18n::t('verwaltung.geloescht_schalter'))
             . '</a>';
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
            return '<tr><td colspan="7" class="adm-empty">'
                 . ViewHelper::esc(I18n::t('verwaltung.standorte.leer')) . '</td></tr>';
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
            if ($titel === '') $titel = I18n::t('verwaltung.ohne_titel');

            $html .= '<tr' . ($gesperrt ? ' class="adm-row--gesperrt"' : '') . '>'
                  .   '<td class="adm-num">' . $id . '</td>'
                  .   '<td>'
                  .     '<a href="index.php?act=location&id=' . $id . '">'
                  .       ViewHelper::esc($titel) . '</a>'
                  .     ($ort !== '' ? '<span class="adm-sub">' . ViewHelper::esc($ort) . '</span>' : '')
                  .   '</td>'
                  .   '<td>' . self::guideZelleHtml($zeile) . '</td>'
                  .   '<td>' . self::zustandHtml((string)($zeile['availability'] ?? 'idle')) . '</td>'
                  .   '<td>' . self::maengelHtml($zeile) . '</td>'
                  .   '<td>' . self::sperrHtml($gesperrt, (string)($zeile['blocked_reason'] ?? ''),
                                               $zeile['blocked_at'] ?? null) . '</td>'
                  .   '<td>' . self::sperrKnopfHtml($id, $gesperrt, $titel) . '</td>'
                  . '</tr>';
        }
        return $html;
    }

    /**
     * Die Guide-Zelle der Standortliste - mit dem Kennzeichen "geloescht".
     *
     * WARUM DIESE ZELLE EINEN SONDERFALL HAT und die uebrigen Listen nicht:
     * Ein geloeschtes Konto ist ueberall sonst verschwunden - keine Nadel,
     * keine Standortseite, kein Bild (App\Model\User::activeSql). Seine
     * Standortzeilen bleiben aber stehen, denn geloescht heisst hier
     * "Kennzeichen gesetzt" und nicht "Zeile weg". Die Verwaltung ist der
     * eine Ort, an dem man sie sehen muss - und dann muss auch dabeistehen,
     * warum der Verweis auf die Standortseite ins Leere fuehrt.
     *
     * KEIN VERWEIS AUF DAS PROFIL eines geloeschten Kontos: Die Seite gibt es
     * nicht mehr, und ein Verweis, der auf eine Fehlseite fuehrt, ist
     * schlimmer als keiner.
     *
     * @param array<string,mixed> $in_zeile
     * @return string HTML
     */
    private static function guideZelleHtml(array $in_zeile): string
    {
        $id       = (int)($in_zeile['user_id'] ?? 0);
        $name     = ViewHelper::esc((string)($in_zeile['guide_name'] ?? ''));
        $konto    = ViewHelper::esc((string)($in_zeile['username'] ?? ''));
        $geloescht = (int)($in_zeile['user_deleted'] ?? 0) === 1;

        if ($geloescht) {
            return '<span class="adm-none">' . $name . '</span>'
                 . '<span class="app-tag app-tag--danger">'
                 .   ViewHelper::esc(I18n::t('verwaltung.konto_geloescht')) . '</span>'
                 . '<span class="adm-sub">' . $konto . '</span>';
        }

        return '<a href="index.php?act=guide&id=' . $id . '">' . $name . '</a>'
             . '<span class="adm-sub">' . $konto . '</span>';
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
            'live' => 'verwaltung.zustand.live',
            'busy' => 'verwaltung.zustand.busy',
            'idle' => 'verwaltung.zustand.idle',
        ];
        $text = ViewHelper::esc(I18n::t($namen[$in_wert] ?? $namen['idle']));
        $art  = $in_wert === 'live' ? 'online' : ($in_wert === 'busy' ? 'busy' : 'offline');

        return '<span class="app-state app-state--' . $art . '">'
             .   '<span class="app-state__dot" aria-hidden="true"></span>'
             .   '<span class="app-state__text">' . $text . '</span>'
             . '</span>';
    }

    /**
     * Was an diesem Angebot fehlt.
     *
     * DIE ZEILE SAGT, WAS ZU TUN IST - das ist der Unterschied zwischen
     * "3 Angebote unvollstaendig" und einem Arbeitsvorrat. Wer die Liste
     * durchgeht, soll nicht jeden Standort einzeln aufmachen muessen, um zu
     * sehen, ob das Bild oder die Zeiten fehlen.
     *
     * DIE MARKEN STEHEN IN FESTER REIHENFOLGE, nach Gewicht: Ohne Nadel ist
     * der Standort unauffindbar, ohne Bild wird er nicht gebucht - das
     * wiegt schwerer als ein fehlender Absatz.
     *
     * WELCHE FUENF ES SIND, entscheidet diese Klasse nicht: Die Spalten
     * kommen aus App\Model\Location::maengelColumnsSql(), und dort steht
     * auch, warum jedes einzelne zaehlt.
     *
     * @param array<string,mixed> $in_zeile
     * @return string HTML
     */
    private static function maengelHtml(array $in_zeile): string
    {
        $marken = [
            'fehlt_ort'    => 'verwaltung.mangel.ort',
            'fehlt_bild'   => 'verwaltung.mangel.bild',
            'fehlt_titel'  => 'verwaltung.mangel.titel',
            'fehlt_text'   => 'verwaltung.mangel.text',
            'fehlt_zeiten' => 'verwaltung.mangel.zeiten',
        ];

        $html = '';
        foreach ($marken as $spalte => $schluessel) {
            if (empty($in_zeile[$spalte])) continue;
            $html .= '<span class="app-tag app-tag--warn">'
                   . ViewHelper::esc(I18n::t($schluessel)) . '</span>';
        }

        // Ein Strich und kein leeres Feld: In einer dichten Tabelle ist eine
        // leere Zelle nicht von einer fehlenden zu unterscheiden.
        return $html !== ''
            ? '<span class="adm-marken">' . $html . '</span>'
            : '<span class="adm-none">'
              . ViewHelper::esc(I18n::t('verwaltung.vollstaendig')) . '</span>';
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

        return '<span class="app-tag app-tag--danger">'
             . ViewHelper::esc(I18n::t('verwaltung.gesperrt')) . '</span>'
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
              . ' data-id="' . $in_id . '" data-title="' . $name . '">'
              . ViewHelper::esc(I18n::t('verwaltung.freigeben')) . '</button>'
            : '<button type="button" class="btn btn-outline-danger btn-sm adm-block"'
              . ' data-id="' . $in_id . '" data-title="' . $name . '">'
              . ViewHelper::esc(I18n::t('verwaltung.sperren')) . '</button>';
    }

    // =================================================================
    // DIE ANFRAGENLISTE - DIE SEITE ZUM ABARBEITEN
    // =================================================================

    /**
     * Die Zeilen der Anfragenliste.
     *
     * WAS AUF DIESER SEITE ABGEARBEITET WIRD, und was ausdruecklich nicht:
     *
     * Die Verwaltung greift in eine Verabredung NICHT ein. Sie nimmt keine
     * Anfrage an, sie lehnt keine ab, und sie beendet keine fremde Fuehrung -
     * dafuer gibt es kein Recht und soll es keines geben: Was zwischen einem
     * Guide und seinem Kunden ausgemacht ist, kann ein Dritter nicht
     * abschliessen, ohne zu wissen, ob es stattgefunden hat.
     *
     * ABGEARBEITET WIRD DURCH ANSPRECHEN. Jede Zeile traegt deshalb den
     * Chatknopf zum GUIDE - denselben wie die Benutzerliste, mit derselben
     * Route (chat_start_direct, Recht chat.start_direct). Das ist die
     * Handlung, die einen dieser Vorgaenge wirklich aufloest: "Deine Fuehrung
     * von heute Mittag laeuft noch, magst du sie beenden?" oder "Bei dir
     * sind drei Anfragen verfallen - passt der Standort noch?".
     *
     * DIE VORRAETE LOESEN SICH VERSCHIEDEN AUF: Eine haengende Fuehrung
     * verschwindet von selbst, sobald die Frist durch ist (closedSql) - die
     * Zeile ist ein Anlass, kein Auftrag. Eine unbeantwortete Anfrage
     * verschwindet erst aus dem Zeitfenster (TourRequest::VORRAT_TAGE); sie
     * ist eine Auskunft ueber einen Guide, nicht ueber einen Vorgang.
     *
     * @param array<int,array<string,mixed>> $in_zeilen Aus TourRequest::allForAdmin()
     * @return string HTML
     */
    public static function anfrageZeilenHtml(array $in_zeilen): string
    {
        if ($in_zeilen === []) {
            return '<tr><td colspan="6" class="adm-empty">'
                 . ViewHelper::esc(I18n::t('verwaltung.anfragen.leer')) . '</td></tr>';
        }

        $namen = TourRequest::statusNames();

        $html = '';
        foreach ($in_zeilen as $zeile) {
            $id       = (int)($zeile['id'] ?? 0);
            $laeuft   = !empty($zeile['running']);
            $zustand  = (string)($zeile['status'] ?? '');
            $guide_id = (int)($zeile['guide_user_id'] ?? 0);
            $titel    = trim((string)($zeile['title'] ?? ''));
            if ($titel === '') $titel = I18n::t('verwaltung.ohne_titel');

            $ort = trim(implode(', ', array_filter([
                (string)($zeile['city_name']    ?? ''),
                (string)($zeile['country_name'] ?? ''),
            ])));

            $html .= '<tr' . ($laeuft ? ' class="adm-row--haengt"' : '') . '>'
                  .   '<td class="adm-num">' . $id . '</td>'
                  .   '<td>' . self::anfrageZustandHtml($zustand, $laeuft, $namen) . '</td>'
                  .   '<td>'
                  .     '<a href="index.php?act=location&id=' . (int)($zeile['location_id'] ?? 0) . '">'
                  .       ViewHelper::esc($titel) . '</a>'
                  .     ($ort !== '' ? '<span class="adm-sub">' . ViewHelper::esc($ort) . '</span>' : '')
                  .   '</td>'
                  .   '<td>'
                  .     '<a href="index.php?act=guide&id=' . $guide_id . '">'
                  .       ViewHelper::esc((string)($zeile['guide_name'] ?? '')) . '</a>'
                  .     '<span class="adm-sub">' . ViewHelper::esc((string)($zeile['guide_username'] ?? '')) . '</span>'
                  .   '</td>'
                  .   '<td>'
                  .     ViewHelper::esc((string)($zeile['customer_username'] ?? '?'))
                  .     '<span class="adm-sub">' . self::anfrageZeitHtml($zeile) . '</span>'
                  .   '</td>'
                  .   '<td>' . self::guideChatKnopfHtml($guide_id, (string)($zeile['guide_name'] ?? '')) . '</td>'
                  . '</tr>';
        }
        return $html;
    }

    /**
     * Der Zustand einer Anfrage als Wort.
     *
     * "haengt" IST KEIN ZUSTAND DER DATENBANK, sondern die Zuspitzung von
     * "angenommen und begonnen und nicht beendet" (TourRequest::runningSql).
     * In dieser Liste ist genau das die Auskunft, um die es geht - deshalb
     * steht sie vor dem gerechneten Status und nicht daneben.
     *
     * Die uebrigen Woerter kommen aus TourRequest::statusNames() und nicht
     * aus dieser Klasse: Anfragenseite und Verwaltung benennen denselben
     * Zustand, und zwei Fassungen desselben Wortes waeren eine zu viel.
     *
     * @param string               $in_status
     * @param bool                 $in_laeuft
     * @param array<string,string> $in_namen
     * @return string HTML
     */
    private static function anfrageZustandHtml(string $in_status, bool $in_laeuft,
                                               array $in_namen): string
    {
        if ($in_laeuft) {
            return '<span class="app-tag app-tag--warn">'
                 . ViewHelper::esc(I18n::t('verwaltung.anfragen.haengt')) . '</span>';
        }

        $wort = $in_namen[$in_status] ?? $in_status;
        // Abgelaufen ist der einzige der uebrigen Zustaende, der etwas
        // bedeutet, was jemand haette verhindern koennen. Er bekommt deshalb
        // eine Marke, die uebrigen bleiben Text.
        return $in_status === TourRequest::STATUS_EXPIRED
            ? '<span class="app-tag app-tag--danger">' . ViewHelper::esc($wort) . '</span>'
            : '<span class="adm-none">' . ViewHelper::esc($wort) . '</span>';
    }

    /**
     * Die Zeitangabe einer Zeile - und zwar die, auf die es ankommt.
     *
     * DREI FAELLE, DREI FRAGEN:
     *
     *   laeuft noch   Seit wann? "seit 3 Std" ist der Unterschied zwischen
     *                 einer normalen Fuehrung und einer, die haengt.
     *   abgelaufen    Wann ist sie verfallen? Danach sortiert die Liste.
     *   sonst         Der Wunschzeitpunkt - worum es ueberhaupt ging.
     *
     * Eine Zeile mit allen drei Zeitpunkten waere vollstaendig und
     * unlesbar. Es steht die eine da, die zur Zeile gehoert.
     *
     * @param array<string,mixed> $in_zeile
     * @return string Maskierter Text
     */
    private static function anfrageZeitHtml(array $in_zeile): string
    {
        // DIE ZEITANGABE STECKT IM SATZ und wird nicht davorgesetzt: Im
        // Englischen steht "running for" vor der Spanne, "expired" vor dem
        // Datum - dass beides im Deutschen zufaellig auch so ist, macht die
        // Verkettung im Code nicht richtiger.
        if (!empty($in_zeile['running'])) {
            $seit = (int)($in_zeile['running_since'] ?? 0);
            return ViewHelper::esc(I18n::t('verwaltung.anfragen.laeuft_seit',
                ['spanne' => self::dauer($seit)]));
        }

        if (($in_zeile['status'] ?? '') === TourRequest::STATUS_EXPIRED) {
            return ViewHelper::esc(I18n::t('verwaltung.anfragen.verfallen',
                ['wann' => self::datum($in_zeile['expires_at'] ?? null)]));
        }

        return ViewHelper::esc(I18n::t('verwaltung.anfragen.wunsch',
            ['wann' => self::datum($in_zeile['wish_at'] ?? null)]));
    }

    /**
     * Der Chatknopf zum Guide.
     *
     * DERSELBE KNOPF WIE IN DER BENUTZERLISTE, bis auf die Klasse
     * (.start-chat-btn) und das Datenfeld - beide liest assets/js/main.js,
     * und von dort geht es in denselben Direktchat. Nachgebaut wird hier
     * nichts: Ein zweiter Weg in denselben Chat waere ein zweiter Ort, an dem
     * man ihn spaeter aendern muss.
     *
     * @param int    $in_guide_id
     * @param string $in_name Nur fuer das aria-label
     * @return string HTML
     */
    private static function guideChatKnopfHtml(int $in_guide_id, string $in_name): string
    {
        if ($in_guide_id < 1) return '<span class="adm-none">–</span>';

        // Ohne Anzeigenamen die Kennung: Ein Vorleseprogramm liest sonst
        // "Chat mit" und danach nichts.
        $name = $in_name !== '' ? $in_name : ('#' . $in_guide_id);

        return '<div class="app-actions-cell">'
             . '<button type="button" class="app-iconbtn app-iconbtn--chat start-chat-btn"'
             . ' data-userid="' . $in_guide_id . '"'
             . ' aria-label="' . ViewHelper::esc(I18n::t('verwaltung.chat_mit',
                   ['name' => $name])) . '"'
             . ' title="' . ViewHelper::esc(I18n::t('verwaltung.guide_anschreiben')) . '"></button>'
             . '</div>';
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
            return '<tr><td colspan="6" class="adm-empty">'
                 . ViewHelper::esc(I18n::t('verwaltung.bewertungen.leer')) . '</td></tr>';
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
                          : '<span class="adm-none">'
                            . ViewHelper::esc(I18n::t('verwaltung.bewertungen.ohne_text')) . '</span>')
                  .     ($entfernt
                          ? '<span class="adm-sub">'
                            . ViewHelper::esc(I18n::t('verwaltung.bewertungen.entfernt_grund', [
                                  'grund' => trim((string)($zeile['removed_reason'] ?? '')) !== ''
                                      ? (string)$zeile['removed_reason']
                                      : I18n::t('verwaltung.bewertungen.ohne_grund'),
                              ]))
                            . '</span>'
                          : '')
                  .   '</td>'
                  .   '<td>'
                  .     ($titel !== ''
                          ? '<a href="index.php?act=location&id=' . (int)($zeile['location_id'] ?? 0) . '">'
                            . ViewHelper::esc($titel) . '</a>'
                          : '<span class="adm-none">–</span>')
                  .     self::kontoZeileHtml(I18n::t('verwaltung.rolle.guide'),
                                                 $zeile['guide_username'] ?? null,
                                                 !empty($zeile['guide_deleted']))
                  .     self::kontoZeileHtml(I18n::t('verwaltung.rolle.kunde'),
                                                 $zeile['customer_username'] ?? null,
                                                 !empty($zeile['customer_deleted']))
                  .   '</td>'
                  .   '<td class="adm-date">' . ViewHelper::esc(self::datum($zeile['created_at'] ?? null)) . '</td>'
                  .   '<td>'
                  .     ($entfernt
                          ? '<span class="app-tag app-tag--danger">'
                            . ViewHelper::esc(I18n::t('verwaltung.bewertungen.entfernt')) . '</span>'
                          : '<button type="button" class="btn btn-outline-danger btn-sm adm-review-remove"'
                            . ' data-id="' . $id . '">'
                            . ViewHelper::esc(I18n::t('verwaltung.bewertungen.entfernen')) . '</button>')
                  .   '</td>'
                  . '</tr>';
        }
        return $html;
    }

    /**
     * Eine Namenszeile der Bewertungsliste - mit dem Kennzeichen "geloescht".
     *
     * BEIDE SEITEN KOENNEN GELOESCHT SEIN, und es bedeutet Verschiedenes:
     *
     *   DER GUIDE. Seine Bewertungen stehen nirgends mehr - seine Standorte
     *   und sein Profil sind weg. Solche Zeilen blendet die Liste per Vorgabe
     *   aus; sichtbar sind sie nur mit dem Schalter, und dann sagt die Marke,
     *   warum sie auf keine Seite mehr verweisen.
     *
     *   DER KUNDE. Seine Bewertung steht WEITERHIN oeffentlich beim Guide -
     *   sie ist eine Auskunft ueber ihn und traegt keinen Namen. Die Zeile
     *   bleibt deshalb immer sichtbar; gekennzeichnet wird nur der Name
     *   daneben, denn der gehoert zu einem Konto, das es nicht mehr gibt.
     *
     * @param string      $in_rolle Beschriftung ("Guide" oder "Kunde")
     * @param string|null $in_name
     * @param bool        $in_geloescht
     * @return string HTML
     */
    private static function kontoZeileHtml(string $in_rolle, $in_name, bool $in_geloescht): string
    {
        $name = trim((string)($in_name ?? ''));
        if ($name === '') $name = '?';

        return '<span class="adm-sub">'
             . ViewHelper::esc(I18n::t('verwaltung.bewertungen.konto', [
                   'rolle' => $in_rolle,
                   'name'  => $name,
               ]))
             . ($in_geloescht
                ? ' <span class="app-tag app-tag--danger">'
                  . ViewHelper::esc(I18n::t('verwaltung.konto_geloescht')) . '</span>'
                : '')
             . '</span>';
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
        // DAS MUSTER STEHT IM KATALOG (datum.mit_uhrzeit). "9.9.2026" und
        // "9/9/2026" sind dieselbe Angabe in zwei Sprachen, und ein fest
        // eingetragenes 'd.m.Y' waere ein deutscher Satz in Kurzform.
        return $zeit === false ? '' : date(I18n::t('datum.mit_uhrzeit'), $zeit);
    }

    /**
     * Eine Zeitspanne in Sekunden als lesbare Dauer.
     *
     * GROB UND ABSICHTLICH: "seit 3 Std 12 Min" beantwortet die Frage dieser
     * Liste - laeuft das noch normal oder haengt es? Sekunden beantworten sie
     * nicht und machen die Zeile nur laenger. Unter einer Minute steht
     * "gerade eben": Eine Fuehrung, die vor vierzig Sekunden begonnen hat,
     * ist kein Vorgang.
     *
     * @param int $in_sekunden Negatives und Null ergeben "gerade eben"
     * @return string
     */
    private static function dauer(int $in_sekunden): string
    {
        if ($in_sekunden < 60) return I18n::t('zeit.gerade_eben');

        $minuten = intdiv($in_sekunden, 60);
        $stunden = intdiv($minuten, 60);
        $rest    = $minuten % 60;

        // DIE ABGEKUERZTE FASSUNG (dauer.kurz.*) und nicht die
        // ausgeschriebene: Diese Spalte ist schmal, und "3 Stunden 12
        // Minuten" macht jede Zeile der Liste laenger, ohne mehr zu sagen.
        // Ohne Formen - "Min" bleibt "Min", auch bei einer.
        if ($stunden < 1) return I18n::t('dauer.kurz.minuten', ['n' => $minuten]);

        // Ab einem Tag sind die Minuten keine Auskunft mehr - da ist laengst
        // klar, dass etwas nicht stimmt.
        if ($stunden >= 24) {
            return I18n::t('dauer.kurz.tage_stunden', [
                'tage'    => I18n::t('dauer.kurz.tage',    ['n' => intdiv($stunden, 24)]),
                'stunden' => I18n::t('dauer.kurz.stunden', ['n' => $stunden % 24]),
            ]);
        }

        $text = I18n::t('dauer.kurz.stunden', ['n' => $stunden]);
        if ($rest === 0) return $text;

        return I18n::t('dauer.kurz.stunden_minuten', [
            'stunden' => $text,
            'minuten' => I18n::t('dauer.kurz.minuten', ['n' => $rest]),
        ]);
    }

    /**
     * Eine Kommazahl mit einer Nachkommastelle.
     *
     * Die Trennzeichen kommen aus dem Katalog (zahl.*): "4,3" auf einer
     * deutschen Seite, "4.3" auf einer englischen. Fest eingetragen waere
     * die deutsche Schreibweise auf einer englischen Seite eine ANDERE
     * Zahl - und niemandem fiele es auf.
     *
     * @param float $in_wert
     * @return string
     */
    private static function zahl(float $in_wert): string
    {
        return number_format(
            $in_wert, 1,
            I18n::t('zahl.dezimaltrenner'),
            I18n::t('zahl.tausendertrenner')
        );
    }
}
