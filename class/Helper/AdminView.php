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
        'anfragen'    => ['index.php?act=admin_requests' , 'Anfragen'    , Permission::REQUEST_LIST_ALL],
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

        // ZUERST DIE HAENGENDEN FUEHRUNGEN, weil sie als Einzige gerade
        // JEMANDEN aufhalten: Solange eine offen ist, steht beim Kunden der
        // Startknopf und die Bewertung wird nicht faellig.
        if ($haengend > 0) {
            $zeilen .= self::vorratZeileHtml(
                $haengend,
                $haengend === 1 ? 'Führung hängt' : 'Führungen hängen',
                'Begonnen und von niemandem beendet. Solange das so bleibt, steht '
                . 'beim Kunden der Startknopf, und die Bewertung wird nicht fällig.',
                'index.php?act=admin_requests&filter=haengend',
                Permission::REQUEST_LIST_ALL
            );
        }

        if ($unbeantwortet > 0) {
            $zeilen .= self::vorratZeileHtml(
                $unbeantwortet,
                'Anfrage' . ($unbeantwortet === 1 ? '' : 'n') . ' ohne Antwort',
                'Verfallen, ohne dass der Guide zu- oder abgesagt hat – in den letzten '
                . TourRequest::VORRAT_TAGE . ' Tagen. Der Kunde hat gewartet und nichts bekommen.',
                'index.php?act=admin_requests&filter=unbeantwortet',
                Permission::REQUEST_LIST_ALL
            );
        }

        if ($gesperrt > 0) {
            $zeilen .= self::vorratZeileHtml(
                $gesperrt,
                'Standort' . ($gesperrt === 1 ? '' : 'e') . ' gesperrt',
                'Ein Vorgang, den jemand eröffnet hat und den jemand wieder '
                . 'schließen muss – oder bestätigen.',
                'index.php?act=admin_locations&filter=gesperrt',
                Permission::LOCATION_BLOCK
            );
        }

        if ($unvollstaendig > 0) {
            $zeilen .= self::vorratZeileHtml(
                $unvollstaendig,
                'Angebot' . ($unvollstaendig === 1 ? '' : 'e') . ' unvollständig',
                'Ohne Nadel auf der Karte, ohne Bild, ohne Titel, ohne ausführliche '
                . 'Beschreibung oder ohne übliche Zeiten. Für den Guide sieht das '
                . 'fertig aus – er weiß ja, was er anbietet.',
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
                $cron === 1 ? 'Konto hängt auf „online"' : 'Konten hängen auf „online"',
                'Seit über einer Viertelstunde kein Lebenszeichen, trotzdem nicht '
                . 'offline gesetzt: cron/check_online_status.php läuft nicht. '
                . 'Solange das so bleibt, steht in der Benutzerliste jedes Konto '
                . 'als erreichbar.',
                'index.php?act=list_user',
                Permission::USER_LIST
            );
        }

        if ($zeilen === '') {
            return '<p class="adm-vorrat__leer">Nichts offen: keine hängende Führung, '
                 . 'keine unbeantwortete Anfrage, kein gesperrter Standort, kein '
                 . 'unvollständiges Angebot – und der Aufräumjob läuft.</p>';
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
                 // HIER STAND DIE SPERRE ALS VERWEIS UND MIT HERVORHEBUNG.
                 // Sie ist in den Arbeitsvorrat darueber gezogen, wo sie
                 // hingehoert: Eine Sperre ist eine Aufgabe und keine
                 // Bestandszahl. Genannt wird sie hier trotzdem - zum Bestand
                 // gehoert sie auch.
                 (int)($standorte['gesperrt'] ?? 0) . ' davon gesperrt'
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
             .   '<p class="adm-tile__value">' . number_format($in_zahl, 0, ',', '.') . '</p>'
             .   '<p class="adm-tile__note">' . ViewHelper::esc($in_zusatz) . '</p>'
             .   '<p class="adm-tile__foot">' . ViewHelper::esc($in_fuss) . '</p>'
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
            return '<tr><td colspan="7" class="adm-empty">Keine Standorte.</td></tr>';
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
                 . '<span class="app-tag app-tag--danger">Konto gelöscht</span>'
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
            'fehlt_ort'    => 'keine Nadel',
            'fehlt_bild'   => 'kein Bild',
            'fehlt_titel'  => 'kein Titel',
            'fehlt_text'   => 'kein Text',
            'fehlt_zeiten' => 'keine Zeiten',
        ];

        $html = '';
        foreach ($marken as $spalte => $wort) {
            if (empty($in_zeile[$spalte])) continue;
            $html .= '<span class="app-tag app-tag--warn">' . $wort . '</span>';
        }

        // Ein Strich und kein leeres Feld: In einer dichten Tabelle ist eine
        // leere Zelle nicht von einer fehlenden zu unterscheiden.
        return $html !== '' ? '<span class="adm-marken">' . $html . '</span>'
                            : '<span class="adm-none">vollständig</span>';
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
            return '<tr><td colspan="6" class="adm-empty">Nichts in dieser Ansicht.</td></tr>';
        }

        $namen = TourRequest::statusNames();

        $html = '';
        foreach ($in_zeilen as $zeile) {
            $id       = (int)($zeile['id'] ?? 0);
            $laeuft   = !empty($zeile['running']);
            $zustand  = (string)($zeile['status'] ?? '');
            $guide_id = (int)($zeile['guide_user_id'] ?? 0);
            $titel    = trim((string)($zeile['title'] ?? ''));
            if ($titel === '') $titel = 'Ohne Titel';

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
            return '<span class="app-tag app-tag--warn">hängt</span>';
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
        if (!empty($in_zeile['running'])) {
            $seit = (int)($in_zeile['running_since'] ?? 0);
            return ViewHelper::esc('läuft seit ' . self::dauer($seit));
        }

        if (($in_zeile['status'] ?? '') === TourRequest::STATUS_EXPIRED) {
            return ViewHelper::esc('verfallen ' . self::datum($in_zeile['expires_at'] ?? null));
        }

        return ViewHelper::esc('Wunsch: ' . self::datum($in_zeile['wish_at'] ?? null));
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

        $name = ViewHelper::esc($in_name !== '' ? $in_name : ('#' . $in_guide_id));

        return '<div class="app-actions-cell">'
             . '<button type="button" class="app-iconbtn app-iconbtn--chat start-chat-btn"'
             . ' data-userid="' . $in_guide_id . '"'
             . ' aria-label="Chat mit ' . $name . '"'
             . ' title="Guide anschreiben"></button>'
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
        if ($in_sekunden < 60) return 'gerade eben';

        $minuten = intdiv($in_sekunden, 60);
        $stunden = intdiv($minuten, 60);
        $rest    = $minuten % 60;

        if ($stunden < 1)  return $minuten . ' Min';
        // Ab einem Tag sind die Minuten keine Auskunft mehr - da ist laengst
        // klar, dass etwas nicht stimmt.
        if ($stunden >= 24) return intdiv($stunden, 24) . ' Tg ' . ($stunden % 24) . ' Std';

        return $stunden . ' Std' . ($rest > 0 ? ' ' . $rest . ' Min' : '');
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
