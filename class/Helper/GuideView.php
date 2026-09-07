<?php
namespace App\Helper;

use App\Model\GuideProfile;

/**
 * Der Guide als Mensch - als HTML, und sonst nichts.
 *
 * WAS DIESE KLASSE BAUT
 * ---------------------
 *   1. Die PROFILSEITE (page). Sie hat eine eigene Adresse, ist verlinkbar
 *      und auch fuer Gaeste erreichbar - ein Guide soll sie weitergeben
 *      koennen.
 *   2. Den GUIDE-STREIFEN auf der Standortseite (streifenHtml). Bild,
 *      Anzeigename, ein Satz, ein Weg zum Profil.
 *   3. Das FORMULAR in den Kontoeinstellungen (formularHtml).
 *
 * Alle drei zeigen dieselben Angaben in drei Groessen, und deshalb stehen sie
 * zusammen: Wer den Anzeigenamen anders ableitet, den Satz anders kuerzt oder
 * das Ersatzbild anders zeichnet als die beiden anderen, faellt hier sofort
 * auf.
 *
 * JEDE METHODE IST EINE REINE FUNKTION - dieselbe Regel wie in
 * App\Helper\LocationView: Werte rein, HTML raus. Kein Zugriff auf die
 * Sitzung, auf $_REQUEST oder auf die Datenbank. Was diese Klasse wissen
 * muss, bekommt sie uebergeben; WER etwas sehen darf, entscheidet der
 * Controller.
 *
 * ALLES, WAS AUS DER DATENBANK KOMMT, GEHT DURCH self::esc(). Anzeigename und
 * Selbstbeschreibung sind Eingaben von Nutzern.
 */
class GuideView
{
    /**
     * Wie viele Zeichen der eine Satz auf der Standortseite haben darf.
     *
     * Er steht dort neben einem Bild und unter einem Namen. Laenger als das
     * waere kein Satz mehr, sondern ein Absatz - und der gehoert auf die
     * Profilseite, zu der der Streifen fuehrt.
     */
    public const SATZ_MAX = 150;

    /**
     * Die vollstaendige Profilseite.
     *
     * DER EINE EINSTIEGSPUNKT. Alles Weitere in dieser Klasse ist ein
     * Baustein davon oder gehoert zu einer der beiden anderen Ansichten.
     *
     * @param array<string,mixed> $in_profil Aus App\Model\GuideProfile::forUser()
     * @param array<int,array<string,mixed>> $in_standorte
     *        Aus App\Model\Location::selectLocationsOfGuide()
     * @param array<string,mixed> $in_ansicht Was der Controller entschieden hat:
     *        'eigen'          bool  Ist es das eigene Profil?
     *        'review_summary' array Durchschnitt, Anzahl und Zahl der
     *                               durchgefuehrten Fuehrungen
     *                               (App\Model\TourReview::summaryForGuide).
     *                               Ob es einen Durchschnitt gibt, ist dort
     *                               entschieden und nicht hier.
     *        'reviews'        array Die letzten Bewertungen mit Text
     *        'moderation'     bool  Darf der Betrachter eine Bewertung
     *                               entfernen (Recht review.remove)?
     * @return string HTML
     */
    public static function page(array $in_profil, array $in_standorte, array $in_ansicht): string
    {
        $eigen = !empty($in_ansicht['eigen']);
        $name  = GuideProfile::nameAus($in_profil);

        $ersetzungen = [
            '###GUIDE_AVATAR###'  => Avatar::html($name,
                                         self::avatarUrl($in_profil, 'full'),
                                         'guide-head__avatar'),
            '###GUIDE_NAME###'    => self::esc($name),
            '###GUIDE_META###'    => self::metaHtml($in_profil),
            '###GUIDE_TOOLS###'   => self::werkzeugeHtml($eigen),
            '###GUIDE_ABOUT###'   => self::ueberMichHtml($in_profil, $eigen),
            // DIE BEWERTUNGEN. Gebaut von App\Helper\ReviewView und nicht
            // hier - dieselbe Klasse baut den Block auf der Standortseite,
            // und "wie sieht eine Bewertung aus" ist eine Frage, die nur
            // einmal beantwortet werden darf.
            //
            // DER GUIDE SIEHT SEINE EIGENEN in derselben Form wie ein Kunde;
            // nur die Ueberschrift ist an ihn gerichtet. Eine Sonderansicht
            // fuer ihn waere eine zweite Wahrheit ueber dieselben Zeilen -
            // und die eine, auf die es ankommt, ist die, die seine Kunden
            // lesen.
            '###GUIDE_REVIEWS###' => ReviewView::blockHtml(
                                         (array)($in_ansicht['review_summary'] ?? []),
                                         (array)($in_ansicht['reviews'] ?? []),
                                         [
                                             'eigen' => $eigen,
                                             // HIER schon: Auf dem Profil
                                             // stehen die Bewertungen zu allen
                                             // Standorten dieses Guides
                                             // nebeneinander, und dann ist die
                                             // Fuehrung, um die es ging, die
                                             // Auskunft, die fehlt.
                                             'mit_ort'    => true,
                                             'moderation' => !empty($in_ansicht['moderation']),
                                         ]),
            '###GUIDE_OFFERS###'  => self::angeboteHtml($in_standorte, $name, $eigen),
        ];

        return str_replace(
            array_keys($ersetzungen),
            array_values($ersetzungen),
            ViewHelper::template('assets/html/guide_page.html')
        );
    }

    /**
     * Maskiert Text fuer die Ausgabe in HTML.
     *
     * Gebaut wird das in App\Helper\ViewHelper::esc() - dort steht die eine
     * Fassung der Regel, samt der Begruendung, warum htmlspecialchars allein
     * in dieser Anwendung nicht reicht.
     *
     * @param mixed $in_wert
     * @return string
     */
    public static function esc($in_wert): string
    {
        return ViewHelper::esc($in_wert);
    }

    /**
     * Der Guide auf der STANDORTSEITE.
     *
     * NICHT ALS RANDNOTIZ. Ein Kunde entscheidet hier, ob er einen Fremden
     * losschickt und ihm dafuer Geld gibt; wer dieser Fremde ist, gehoert zu
     * dieser Entscheidung und nicht in eine Fusszeile. Der Streifen steht
     * deshalb im Inhaltsbereich unter der Beschreibung, in voller Breite,
     * und er ist als Ganzes der Weg zum Profil.
     *
     * DREI ANGABEN, MEHR NICHT: Bild, Anzeigename, ein Satz. Alles Weitere -
     * Sprachen, seit wann dabei, die uebrigen Standorte - steht auf der
     * Profilseite, und dorthin fuehrt der Streifen. Wer hier alles zeigt,
     * baut eine zweite Profilseite in eine Standortseite hinein.
     *
     * Die Angaben kommen aus DERSELBEN Zeile, die auch den Standort
     * beschreibt (App\Model\Location::selectOneForPage) - keine zweite
     * Abfrage, kein zweiter Weg zu denselben Daten.
     *
     * DER EIGENTUEMER SIEHT DENSELBEN STREIFEN mit anderer Beschriftung. Ihm
     * "Ihr Guide" vorzusetzen waere falsch, ihn ganz wegzulassen aber auch:
     * Er soll sehen, was ein Kunde an dieser Stelle sieht - und wenn dort nur
     * seine Initialen und kein Satz stehen, ist genau das die Auskunft, die
     * er braucht.
     *
     * @param array<string,mixed> $in_daten Die Standortzeile; gebraucht
     *        werden user_id, username, display_name, about, avatar_file
     * @param bool                $in_eigen Gehoert der Standort dem Aufrufer?
     * @return string HTML
     */
    public static function streifenHtml(array $in_daten, bool $in_eigen = false): string
    {
        $user_id = (int)($in_daten['user_id'] ?? 0);
        if ($user_id < 1) return '';

        $name = GuideProfile::nameAus($in_daten);
        if ($name === '') return '';

        $satz = self::ersterSatz($in_daten['about'] ?? '');

        return '<a class="loc-guide" href="' . self::profilUrl($user_id) . '">'
             .   Avatar::html($name, self::avatarUrl($in_daten, 'thumb'), 'loc-guide__avatar')
             .   '<span class="loc-guide__text">'
             .     '<span class="loc-guide__label">'
             .       ($in_eigen ? 'Sie bieten diese Führung an' : 'Ihr Guide')
             .     '</span>'
             .     '<span class="loc-guide__name">' . self::esc($name) . '</span>'
             .     ($satz !== ''
                    ? '<span class="loc-guide__line">' . self::esc($satz) . '</span>'
                    : '')
             .   '</span>'
             .   '<span class="loc-guide__more">'
             .     ($in_eigen ? 'Ihr Profil' : 'Profil ansehen')
             .   '</span>'
             . '</a>';
    }

    /**
     * Der erste Satz einer Selbstbeschreibung.
     *
     * WARUM EIN SATZ UND NICHT DIE ERSTEN N ZEICHEN: Ein hart abgeschnittener
     * Text endet mitten im Wort und liest sich wie ein Fehler. Ein Satz endet
     * dort, wo der Schreiber ihn beendet hat - und der erste Satz einer
     * Selbstbeschreibung ist fast immer der, den jemand als Erstes gelesen
     * haben will.
     *
     * Gesucht wird ein Satzzeichen, dem ein Leerzeichen oder das Textende
     * folgt. Das schuetzt "z. B." und "20.30 Uhr" davor, als Satzende zu
     * gelten - nicht vollstaendig, aber fuer den Zweck gut genug.
     *
     * IST DER ERSTE SATZ ZU LANG, wird an einer Wortgrenze gekuerzt und ein
     * Auslassungszeichen angehaengt. Findet sich gar kein Satzzeichen, gilt
     * derselbe Weg fuer den ganzen Text.
     *
     * @param mixed $in_text
     * @param int   $in_max
     * @return string Unmaskiert - der Aufrufer maskiert
     */
    public static function ersterSatz($in_text, int $in_max = self::SATZ_MAX): string
    {
        $text = is_scalar($in_text) ? trim((string)$in_text) : '';
        if ($text === '') return '';

        // ZEILENUMBRUCH IST AUCH EIN SATZENDE: Wer eine neue Zeile anfaengt,
        // hat den Gedanken beendet - auch ohne Punkt. Deshalb wird zuerst die
        // erste Zeile genommen und erst darin nach einem Satzzeichen gesucht;
        // andersherum liefe der Satz ueber den Umbruch hinweg weiter.
        $zeilen = preg_split('/\R/u', $text, 2);
        $text   = trim((string)preg_replace('/\s+/u', ' ', (string)$zeilen[0]));
        if ($text === '') return '';

        if (preg_match('/^(.+?[.!?])(\s|$)/u', $text, $treffer) === 1) {
            $satz = trim($treffer[1]);
        } else {
            $satz = $text;
        }

        if (mb_strlen($satz, 'UTF-8') <= $in_max) return $satz;

        $kurz = mb_substr($satz, 0, $in_max, 'UTF-8');
        $luecke = mb_strrpos($kurz, ' ', 0, 'UTF-8');
        if ($luecke !== false && $luecke > 0) {
            $kurz = mb_substr($kurz, 0, $luecke, 'UTF-8');
        }

        return rtrim($kurz, " ,;:-") . '…';
    }

    /**
     * Die Adresse einer Profilseite.
     *
     * An genau einer Stelle zusammengesetzt - aendert sich der Routenname,
     * ist es eine Zeile und nicht acht verteilte Zeichenketten.
     *
     * DIE KENNUNG UND NICHT DER BENUTZERNAME. Der Benutzername ist die
     * Anmeldekennung; er gehoert nicht in eine Adresse, die weitergegeben
     * werden soll.
     *
     * @param int $in_user_id
     * @return string
     */
    public static function profilUrl($in_user_id): string
    {
        return 'index.php?act=guide&amp;id=' . (int)$in_user_id;
    }

    /**
     * Die Adresse des Avatarbildes - oder null, wenn es keines gibt.
     *
     * @param array<string,mixed> $in_zeile Mit user_id und avatar_file
     * @param string              $in_groesse 'thumb' oder 'full'
     * @return string|null
     */
    public static function avatarUrl(array $in_zeile, string $in_groesse): ?string
    {
        $datei = $in_zeile['avatar_file'] ?? null;
        if (!is_string($datei) || $datei === '') return null;

        return Avatar::url((int)($in_zeile['user_id'] ?? 0), $in_groesse);
    }

    /**
     * Die Zeile unter dem Namen: seit wann dabei, welche Sprachen.
     *
     * Was nicht angegeben ist, steht gar nicht da - "Sprachen: keine Angabe"
     * ist eine Zeile ohne Auskunft. Bleibt beides leer, bleibt die Zeile weg.
     *
     * @param array<string,mixed> $in_profil
     * @return string HTML
     */
    public static function metaHtml(array $in_profil): string
    {
        $teile = [];

        $seit = self::dabeiSeit($in_profil['joined_at'] ?? null);
        if ($seit !== '') $teile[] = 'Guide seit ' . $seit;

        $sprachen = Languages::names($in_profil['languages'] ?? '');
        if ($sprachen !== []) $teile[] = 'Spricht ' . implode(', ', $sprachen);

        if ($teile === []) return '';

        return '<p class="guide-head__meta">' . self::esc(implode(' · ', $teile)) . '</p>';
    }

    /**
     * "Guide seit" als Monat und Jahr.
     *
     * MONAT UND JAHR, NICHT DER TAG. Der Tag ist keine Auskunft, die jemand
     * braucht - "seit März 2024" sagt alles, was die Frage "ist der neu
     * hier?" beantwortet. Und ein taggenaues Datum neben einem Namen und
     * einem Bild ist eine Angabe mehr ueber eine Person, die nur
     * Stadtfuehrungen anbietet.
     *
     * Die Monatsnamen stehen hier und kommen nicht aus strftime(): Das
     * haengt an der Locale des Servers, und die ist auf einem gemieteten
     * Server oft englisch - dann stuende auf einer deutschen Seite "March".
     *
     * @param mixed $in_datum Wert aus der Datenbank (Y-m-d H:i:s)
     * @return string Leerstring, wenn das Datum fehlt oder unbrauchbar ist
     */
    public static function dabeiSeit($in_datum): string
    {
        $roh = is_scalar($in_datum) ? trim((string)$in_datum) : '';
        if ($roh === '') return '';

        $zeit = strtotime($roh);
        if ($zeit === false) return '';

        $monate = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
                   'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];

        $nr = (int)date('n', $zeit);
        if ($nr < 1 || $nr > 12) return '';

        return $monate[$nr] . ' ' . date('Y', $zeit);
    }

    /**
     * Die Selbstbeschreibung als Fliesstext.
     *
     * Zeilenumbrueche sind eine Aussage des Guides und werden uebersetzt -
     * dieselbe Regel wie bei der ausfuehrlichen Standortbeschreibung
     * (App\Helper\LocationView::langtextHtml). Maskiert wird VOR dem
     * Ersetzen, sonst stuenden die eingesetzten <p> als Text auf der Seite.
     *
     * OHNE TEXT SIEHT EIN KUNDE NICHTS. Ein Kasten "hat nichts geschrieben"
     * waere eine Auskunft ueber ein Formular und nicht ueber einen Menschen.
     * Der EIGENTUEMER bekommt einen Hinweis - er kann etwas daran aendern.
     *
     * @param array<string,mixed> $in_profil
     * @param bool                $in_eigen
     * @return string HTML oder Leerstring
     */
    public static function ueberMichHtml(array $in_profil, bool $in_eigen): string
    {
        $text = trim((string)($in_profil['about'] ?? ''));

        if ($text === '') {
            if (!$in_eigen) return '';
            return '<section class="app-panel guide-about"><div class="app-panel__body">'
                 . '<p class="guide-about__empty">Sie haben noch nichts über sich '
                 . 'geschrieben. Ein paar Sätze darüber, wer Sie sind und warum Sie '
                 . 'diese Orte zeigen, stehen auf jeder Ihrer Standortseiten – und sie '
                 . 'sind das, was ein Kunde vor seiner Anfrage liest.</p>'
                 . '</div></section>';
        }

        $text     = str_replace(["\r\n", "\r"], "\n", $text);
        $absaetze = preg_split('/\n{2,}/', self::esc($text));

        $html = '';
        foreach ((array)$absaetze as $absatz) {
            $absatz = trim($absatz);
            if ($absatz === '') continue;
            $html .= '<p>' . nl2br($absatz, false) . '</p>';
        }

        return '<section class="app-panel guide-about"><div class="app-panel__body">'
             . '<h2 class="guide__h2">Über mich</h2>'
             . '<div class="guide-about__text">' . $html . '</div>'
             . '</div></section>';
    }

    /**
     * Die Standorte, die dieser Guide anbietet.
     *
     * SIE SIND DER ZWEITE GRUND, WARUM ES DIESE SEITE GIBT. Ein Kunde, der
     * einen Guide gut findet, will wissen, was der sonst noch zeigt - und
     * dafuer gab es bisher keinen Weg: Von einem Standort fuehrte nichts zum
     * naechsten desselben Menschen.
     *
     * Jede Kachel ist ein Verweis auf die Standortseite. Das Titelbild steht
     * dabei, wenn es eines gibt; ohne bleibt die Flaeche leer statt einen
     * Platzhalter zu zeigen, der ein Bild verspricht.
     *
     * @param array<int,array<string,mixed>> $in_standorte
     * @param string                         $in_name  Anzeigename, fuer die Ueberschrift
     * @param bool                           $in_eigen
     * @return string HTML
     */
    public static function angeboteHtml(array $in_standorte, string $in_name, bool $in_eigen): string
    {
        $kopf = '<h2 class="guide__h2">'
              . ($in_eigen ? 'Ihre Standorte'
                           : self::esc($in_name) . ' zeigt Ihnen')
              . '</h2>';

        if ($in_standorte === []) {
            $text = $in_eigen
                ? 'Sie bieten noch keinen Standort an. Über "Standort anbieten" wird '
                . 'aus diesem Profil ein Angebot.'
                : 'Dieser Guide bietet gerade keinen Standort an.';
            return '<section class="guide-offers">' . $kopf
                 . '<p class="guide-offers__empty">' . self::esc($text) . '</p>'
                 . '</section>';
        }

        $kacheln = '';
        foreach ($in_standorte as $standort) {
            $kacheln .= self::angebotHtml($standort);
        }

        return '<section class="guide-offers">' . $kopf
             . '<ul class="guide-offers__list">' . $kacheln . '</ul>'
             . '</section>';
    }

    /**
     * Eine einzelne Standortkachel.
     *
     * @param array<string,mixed> $in_standort
     * @return string HTML
     */
    public static function angebotHtml(array $in_standort): string
    {
        $id    = (int)($in_standort['id'] ?? 0);
        $titel = trim((string)($in_standort['title'] ?? ''));
        $ort   = trim(implode(', ', array_filter([
                     (string)($in_standort['city_name'] ?? ''),
                     (string)($in_standort['country_name'] ?? ''),
                 ], static fn($t) => trim($t) !== '')));

        // Ohne Titel steht der Ort in der Ueberschrift. Eine Kachel ohne
        // jede Beschriftung waere ein Verweis, den niemand anklickt.
        if ($titel === '') $titel = $ort !== '' ? $ort : 'Führung';

        $cover_id = (int)($in_standort['cover_image_id'] ?? 0);
        $bild = $cover_id > 0
            ? '<img class="guide-offer__image" src="' . LocationView::bildUrl($cover_id, 'thumb')
              . '" alt="" loading="lazy">'
            : '<span class="guide-offer__image guide-offer__image--empty" aria-hidden="true"></span>';

        // GESPERRT SAGT ES AUCH SO. Diese Kachel sehen nur der Eigentuemer
        // und die Moderation (der Controller liefert gesperrte Standorte
        // sonst gar nicht mit) - und beiden waere mit "Kein Guide vor Ort"
        // nicht geholfen: Das ist zwar richtig, verschweigt aber den Grund.
        //
        // Sonst dieselbe Marke wie auf der Standortseite und auf der Karte,
        // gebaut von derselben Methode, damit "verfuegbar" ueberall dasselbe
        // heisst und gleich aussieht.
        $zustand = (int)($in_standort['blocked'] ?? 0) === 1
            ? '<span class="app-tag app-tag--danger">Gesperrt</span>'
            : LocationView::zustandHtml([
                  'blocked'      => 0,
                  'availability' => $in_standort['availability'] ?? 'idle',
              ], false);

        $kurz = trim((string)($in_standort['description'] ?? ''));

        return '<li class="guide-offer">'
             . '<a class="guide-offer__link" href="index.php?act=location&amp;id=' . $id . '">'
             .   $bild
             .   '<span class="guide-offer__body">'
             .     '<span class="guide-offer__state">' . $zustand . '</span>'
             .     '<span class="guide-offer__title">' . self::esc($titel) . '</span>'
             .     ($ort !== ''
                    ? '<span class="guide-offer__place">' . self::esc($ort) . '</span>'
                    : '')
             .     ($kurz !== '' && $kurz !== $titel
                    ? '<span class="guide-offer__desc">' . self::esc($kurz) . '</span>'
                    : '')
             .   '</span>'
             . '</a>'
             . '</li>';
    }

    /**
     * Die Knoepfe fuer den Eigentuemer des Profils.
     *
     * Nur er sieht sie, und sie fuehren dorthin, wo das Profil bearbeitet
     * wird - in die Kontoeinstellungen. Ein zweites Formular auf dieser
     * Seite waere ein zweiter Weg zu denselben Feldern.
     *
     * @param bool $in_eigen
     * @return string HTML oder Leerstring
     */
    public static function werkzeugeHtml(bool $in_eigen): string
    {
        if (!$in_eigen) return '';

        return '<div class="guide-head__tools">'
             . '<span class="app-tag app-tag--accent">Ihr Profil</span>'
             . '<a class="btn btn-secondary btn-sm" href="index.php?act=settings">Profil bearbeiten</a>'
             . '</div>';
    }

    // =================================================================
    // BEARBEITEN - in den Kontoeinstellungen
    // =================================================================

    /**
     * Das Formular, mit dem ein Guide sein Profil pflegt.
     *
     * ES STEHT IN DEN KONTOEINSTELLUNGEN und nicht auf der Profilseite: Die
     * Profilseite ist das, was ein Kunde sieht, und sie soll fuer den
     * Eigentuemer genauso aussehen wie fuer alle anderen. Wer sein Profil
     * aendert, geht dorthin, wo er auch sein Passwort aendert.
     *
     * EIN FORMULAR FUER ALLES, das Bild eingeschlossen (enctype
     * multipart/form-data). Ein zweites Formular nur fuer den Upload waere
     * ein zweiter Absendeknopf fuer dieselbe Seite - und die Frage, welcher
     * von beiden die Eingaben des anderen verwirft. Das Entfernen des Bildes
     * ist der einzige eigene Weg, weil es das Gegenteil von "speichern" ist.
     *
     * ZWEI FORMULARE NEBENEINANDER, NIE INEINANDER
     * --------------------------------------------
     * Das Formular zum Entfernen steht HINTER dem Hauptformular und ist leer;
     * der Knopf bleibt oben beim Bild und findet es ueber sein
     * form-Attribut. Das sieht umstaendlich aus und ist der einzige Weg, der
     * funktioniert.
     *
     * DER BEFUND, aus dem diese Anordnung entstanden ist: Vorher stand das
     * kleine Formular INNERHALB des grossen. HTML kennt keine verschachtelten
     * Formulare - der Parser verwirft das innere <form> ersatzlos, und sein
     * </form> schliesst dann das AEUSSERE. Alles, was danach kam - der
     * Anzeigename, die Selbstbeschreibung, die Sprachen und der Knopf
     * "Profil speichern" - stand anschliessend ausserhalb jedes Formulars.
     *
     * Die Folgen waren genau die drei gemeldeten: Die Felder wurden nicht
     * mitgeschickt (und beim Speichern mit Leerwerten ueberschrieben), die
     * Haken ebenso wenig - und "Bild entfernen" gehoerte dem Hauptformular
     * und lud ein Bild hoch, statt eines zu loeschen. Sichtbar war davon
     * nichts: Die Werte standen im Quelltext, nur eben in keinem Formular.
     *
     * MIT RUECKFRAGE. Das Entfernen loescht die Datei; danach stehen wieder
     * die Initialen da. Gefragt wird ueber data-confirm am Formular
     * (App\Helper\ViewHelper baut nichts dazu, das macht
     * assets/js/ui.js#bindConfirmForms) - dieselbe Rueckfrage wie beim
     * Loeschen eines Standorts, und derselbe Dialog.
     *
     * OHNE JAVASCRIPT VOLLSTAENDIG BEDIENBAR: normales POST, danach eine
     * Weiterleitung zurueck auf die Einstellungen. Dann faellt allerdings die
     * Rueckfrage weg und das Bild ist nach einem Klick fort - so wie beim
     * Loeschen eines Standorts, das ohne Skript gar nicht erst geht. Ein
     * verlorenes Profilbild laedt man wieder hoch; ein Klick, der nichts tut,
     * waere schlechter.
     *
     * @param array<string,mixed> $in_profil  Aus GuideProfile::forUser()
     * @param array<string,mixed> $in_grenzen Obergrenzen fuer die Felder und
     *        den Upload (App\Controller\SettingsController)
     * @return string HTML
     */
    public static function formularHtml(array $in_profil, array $in_grenzen): string
    {
        $name  = (string)($in_profil['display_name'] ?? '');
        $about = (string)($in_profil['about'] ?? '');
        $user  = (string)($in_profil['username'] ?? '');
        $hat_bild = ($in_profil['avatar_file'] ?? null) !== null;

        $name_max  = (int)($in_grenzen['name_max']  ?? 60);
        $about_max = (int)($in_grenzen['about_max'] ?? 600);
        $mb        = max(1, (int)round(((int)($in_grenzen['max_bytes'] ?? 0)) / 1048576));

        // Das Bild samt der beiden Knoepfe. Gezeigt wird immer etwas - ohne
        // Upload die Initialen, damit sichtbar ist, was ein Kunde stattdessen
        // sieht. Ein leeres Feld verschwiege das.
        $vorschau = Avatar::html(GuideProfile::nameAus($in_profil),
                        self::avatarUrl($in_profil, 'full'), 'guide-form__avatar');

        // Der Knopf steht beim Bild, das Formular dazu hinter dem
        // Hauptformular - verbunden ueber das form-Attribut. Siehe oben,
        // warum es nicht andersherum geht.
        $entfernen = $hat_bild
            ? '<button type="submit" form="guide-avatar-delete"'
              . ' class="btn btn-secondary btn-sm guide-form__remove">Bild entfernen</button>'
            : '';

        // Leer, und das ist richtig: Es traegt keine Eingabe, sondern nur die
        // Adresse und die Rueckfrage. Was entfernt wird, steht nicht in der
        // Anfrage - es ist immer das Bild des Angemeldeten
        // (App\Controller\GuideProfileController).
        $entfernenFormular = $hat_bild
            ? '<form id="guide-avatar-delete" action="index.php?act=guide_avatar_delete"'
              . ' method="post"'
              . ' data-confirm-title="Profilbild entfernen?"'
              . ' data-confirm="Das Bild wird gelöscht. Auf Ihren Standortseiten und'
              . ' in Ihrem Profil stehen danach wieder Ihre Initialen. Ein neues Bild'
              . ' können Sie jederzeit hochladen."'
              . ' data-confirm-ok="Entfernen" data-confirm-danger="1"></form>'
            : '';

        return '<form action="index.php?act=guide_profile_save" method="post"'
             . ' enctype="multipart/form-data" class="guide-form">'

             // --- Bild ---------------------------------------------------
             . '<div class="guide-form__portrait">'
             .   $vorschau
             .   '<div class="guide-form__portraitText">'
             .     '<label class="form-label" for="guide-avatar">Bild</label>'
             .     '<input type="file" id="guide-avatar" name="avatar" class="form-control"'
             .            ' accept="' . self::esc((string)($in_grenzen['accept'] ?? 'image/*')) . '">'
             .     '<p class="form-text">Ein Bild von Ihnen, quadratisch zugeschnitten. '
             .       'Bis zu ' . $mb . ' MB. Ohne Bild stehen Ihre Initialen dort – '
             .       'das ist besser als ein leerer Kreis, aber schlechter als ein Gesicht.</p>'
             .     $entfernen
             .   '</div>'
             . '</div>'

             // --- Anzeigename --------------------------------------------
             . '<div class="guide-form__field">'
             .   '<label class="form-label" for="guide-display-name">Anzeigename</label>'
             .   '<input type="text" id="guide-display-name" name="display_name"'
             .          ' class="form-control" maxlength="' . $name_max . '"'
             .          ' value="' . self::esc($name) . '">'
             .   '<p class="form-text">Der Name, unter dem Kunden Sie sehen – auf Ihren '
             .     'Standortseiten, in der Standortliste und hier. Ihr Benutzername '
             .     ($user !== '' ? '<strong>' . self::esc($user) . '</strong> ' : '')
             .     'bleibt davon unberührt; mit ihm melden Sie sich weiterhin an, und '
             .     'ein Kunde bekommt ihn nicht zu sehen. Ohne Anzeigenamen steht er '
             .     'dort allerdings weiterhin.</p>'
             . '</div>'

             // --- Selbstbeschreibung -------------------------------------
             . '<div class="guide-form__field">'
             .   '<label class="form-label" for="guide-about">Über mich</label>'
             .   '<textarea id="guide-about" name="about" class="form-control" rows="5"'
             .             ' maxlength="' . $about_max . '">' . self::esc($about) . '</textarea>'
             .   '<p class="form-text">Ein paar Sätze über sich. Der ERSTE SATZ steht auf '
             .     'jeder Ihrer Standortseiten neben Ihrem Bild – schreiben Sie ihn so, '
             .     'dass er allein schon etwas sagt.</p>'
             . '</div>'

             // --- Sprachen -----------------------------------------------
             . '<div class="guide-form__field">'
             .   '<span class="form-label">Sprachen</span>'
             .   '<div class="guide-choices">' . self::sprachauswahlHtml($in_profil['languages'] ?? '') . '</div>'
             .   '<p class="form-text">Die Sprachen, die Sie sprechen. Welche Sprachen für '
             .     'eine einzelne Führung gelten, steht weiterhin am Standort – das ist '
             .     'nicht dasselbe.</p>'
             . '</div>'

             . '<div class="app-actions">'
             .   '<button type="submit" class="btn btn-primary">Profil speichern</button>'
             . '</div>'
             . '</form>'

             // HINTER dem Hauptformular, nicht darin.
             . $entfernenFormular;
    }

    /**
     * Die Sprachauswahl als Kaestchen.
     *
     * Gebaut aus App\Helper\Languages - der einen Stelle, an der der Katalog
     * steht. Eigene IDs (guide-lang-*), damit sie sich nicht mit denen des
     * Standortformulars beissen, wenn beide Formulare je auf einer Seite
     * landen.
     *
     * @param mixed $in_gewaehlt Gespeicherter Wert ("de,en")
     * @return string HTML
     */
    public static function sprachauswahlHtml($in_gewaehlt): string
    {
        $gewaehlt = array_flip(Languages::codes($in_gewaehlt));

        $html = '';
        foreach (Languages::all() as $code => $bezeichnung) {
            $id = 'guide-lang-' . $code;
            $html .= '<label class="guide-choice" for="' . $id . '">'
                  . '<input type="checkbox" id="' . $id . '" name="languages[]"'
                  . ' value="' . self::esc($code) . '"'
                  . (isset($gewaehlt[$code]) ? ' checked' : '') . '>'
                  . '<span>' . self::esc($bezeichnung) . '</span>'
                  . '</label>';
        }
        return $html;
    }
}
