<?php
namespace App\Helper;

/**
 * Die Standortseite als HTML - und sonst nichts.
 *
 * WARUM DAS EINE EIGENE KLASSE IST
 * --------------------------------
 * Der Controller entscheidet, WER etwas sehen darf: ob der Aufrufer der
 * Eigentuemer ist, ob ein gesperrter Standort ueberhaupt ausgeliefert wird,
 * ob ein Gast eine user_id bekommt. Diese Klasse entscheidet, WIE das
 * aussieht. Zusammen waren es 1700 Zeilen in einem Controller, und die
 * beiden Fragen standen ineinander.
 *
 * JEDE METHODE HIER IST EINE REINE FUNKTION: Werte rein, HTML raus. Kein
 * Zugriff auf die Sitzung, auf $_REQUEST oder auf die Datenbank - was diese
 * Klasse wissen muss, bekommt sie uebergeben. Das ist keine Formsache: Damit
 * laesst sich die Seite in Stuecken pruefen, ohne eine Seite auszuliefern und
 * ohne eine Anmeldung nachzustellen (tests/server_test.php, Abschnitt 29).
 *
 * ALLES, WAS AUS DER DATENBANK KOMMT, GEHT DURCH self::esc(). Titel,
 * Beschreibungen und Ortsnamen sind Eingaben von Nutzern - siehe dort, warum
 * htmlspecialchars dafuer nicht reicht.
 */
class LocationView
{
    /**
     * Die vollstaendige Standortseite.
     *
     * DER EINE EINSTIEGSPUNKT. Alles Weitere in dieser Klasse ist ein
     * Baustein davon.
     *
     * @param array<string,mixed> $in_daten  Aus Location::selectOneForPage()
     * @param array{cover: array<string,mixed>|null, gallery: array<int,array<string,mixed>>} $in_bilder
     *        Die Bilder, BEREITS GETRENNT (App\Model\LocationImage::teile).
     *        Getrennt wird im Controller und nicht hier: Welche Rolle ein Bild
     *        traegt, steht in der Datenbank - diese Klasse bekommt, was sie
     *        zeigen soll, und entscheidet nichts.
     * @param array<string,mixed>            $in_ansicht Was der Controller entschieden hat:
     *        'eigen'       bool   Gehoert der Standort dem Aufrufer?
     *        'angemeldet'  bool   Ist ueberhaupt jemand angemeldet?
     *        'viewer_id'   ?int   Wer darf angerufen werden - null fuer Gast
     *                             und fuer den Eigentuemer selbst
     *        'fehler'      string Meldung nach einer abgelehnten Aenderung
     *        'gespeichert' bool   Meldung nach einer erfolgreichen
     *        'grenzen'     array  Obergrenzen fuer das Bearbeitungsformular;
     *                             leer fuer jeden ausser dem Eigentuemer
     * @return string HTML fuer den Inhaltsbereich
     */
    public static function page(array $in_daten, array $in_bilder, array $in_ansicht): string
    {
        $eigen  = !empty($in_ansicht['eigen']);
        $titel  = self::titelVon($in_daten);

        // ZWEI ARTEN VON BILDERN: das Titelbild fuer den Kopf, die
        // Beispielbilder fuer die Galerie im Inhaltsbereich. Welches welches
        // ist, hat der Guide ausgewaehlt - nicht mehr die Reihenfolge.
        $cover   = $in_bilder['cover']   ?? null;
        $gallery = (array)($in_bilder['gallery'] ?? []);

        $ersetzungen = [
            '###LOCATION_ID###' => (string)(int)$in_daten['id'],
            '###NOTICE###'      => self::hinweisHtml(
                                       (string)($in_ansicht['fehler'] ?? ''),
                                       !empty($in_ansicht['gespeichert'])),
            '###TITLE###'       => self::esc($titel),
            '###PLACE###'       => self::esc(self::ortVon($in_daten)),
            '###STATE###'       => self::zustandHtml($in_daten, $eigen),
            '###BLOCKED###'     => self::sperrHtml($in_daten, $eigen),
            '###COVER###'       => self::titelbildHtml($cover, $titel),
            '###GALLERY###'     => self::galerieHtml($gallery, $titel),
            '###SHORTTEXT###'   => self::kurztextHtml($in_daten),
            '###LONGTEXT###'    => self::langtextHtml($in_daten),
            '###FACTS###'       => self::faktenHtml($in_daten),
            '###ACTION###'      => self::aktionHtml($in_daten, $eigen,
                                       !empty($in_ansicht['angemeldet']),
                                       $in_ansicht['viewer_id'] ?? null),
            '###OWNER_TOOLS###' => $eigen
                                       ? self::bearbeitenHtml($in_daten, $cover, $gallery,
                                             (array)($in_ansicht['grenzen'] ?? []))
                                       : '',
            '###PAGE_DATA###'   => self::seitendatenHtml($in_daten, $in_bilder, $in_ansicht),
        ];

        return str_replace(
            array_keys($ersetzungen),
            array_values($ersetzungen),
            ViewHelper::template('assets/html/location_page.html')
        );
    }

    /**
     * Maskiert Text fuer die Ausgabe in HTML.
     *
     * ZWEI DINGE, nicht eines:
     *
     * 1. htmlspecialchars mit ENT_QUOTES. Der uebliche Teil - spitze
     *    Klammern und beide Anfuehrungszeichen, damit ein Text weder ein
     *    Element noch ein Attribut beenden kann.
     *
     * 2. Drei Rautenzeichen werden unschaedlich gemacht. DAS IST DER TEIL,
     *    DEN MAN VERGISST: Diese Anwendung baut ihre Seiten mit
     *    str_replace ueber Platzhalter der Form ###NAME###, und
     *    App\Helper\ViewHelper::output() laeuft NACH diesem Controller ueber
     *    das gesamte Dokument. Eine Beschreibung, in der jemand
     *    "###USER###" schreibt, bekaeme sonst an dieser Stelle das
     *    Benutzermenue eingesetzt - Fremdeingabe, die eine Ersetzung des
     *    Servers ausloest. htmlspecialchars sieht das nicht, denn an einer
     *    Raute ist nichts gefaehrlich; gefaehrlich ist sie nur in DIESEM
     *    Bauverfahren.
     *
     *    Ersetzt wird durch die HTML-Entitaet: Im Browser steht danach
     *    wieder "###USER###", im Dokument aber nicht mehr das Muster, auf
     *    das str_replace anspringt.
     *
     * @param mixed $in_wert
     * @return string
     */
    public static function esc($in_wert): string
    {
        $text = is_scalar($in_wert) ? (string)$in_wert : '';
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return str_replace('###', '&#35;&#35;&#35;', $text);
    }

    /**
     * Der Titel eines Standorts - mit Rueckfall.
     *
     * Bestandsdaten haben ihren Titel aus migrations/011 bekommen. Ist er
     * trotzdem leer (ein Standort ohne jede Beschreibung), steht dort der
     * Ort. Eine Ueberschrift mit dem Text "" waere eine Seite ohne Anfang.
     *
     * @param array<string,mixed> $in_daten
     * @return string Unmaskiert - der Aufrufer maskiert
     */
    public static function titelVon(array $in_daten): string
    {
        $titel = trim((string)($in_daten['title'] ?? ''));
        if ($titel !== '') return $titel;

        $ort = self::ortVon($in_daten);
        return $ort !== '' ? 'Führung in ' . $ort : 'Standort';
    }

    /**
     * "Stadt, Land" - so viel davon, wie bekannt ist.
     *
     * @param array<string,mixed> $in_daten
     * @return string Unmaskiert
     */
    public static function ortVon(array $in_daten): string
    {
        $teile = array_filter([
            trim((string)($in_daten['city_name'] ?? '')),
            trim((string)($in_daten['country_name'] ?? '')),
        ], fn($t) => $t !== '');

        return implode(', ', $teile);
    }

    /**
     * Die Zustandsmarke: kann ich hier jetzt eine Fuehrung bekommen?
     *
     * Dieselben drei Werte und dieselben Klassen wie im Kartenfenster
     * (assets/js/home_map.js). Eine Standortseite, die "verfuegbar" anders
     * beantwortet als die Nadel, von der aus man auf sie geklickt hat, waere
     * schlimmer als gar keine Angabe.
     *
     * Ein gesperrter Standort ist nie verfuegbar - die Sperre schlaegt jede
     * Bereitschaft.
     *
     * @param array<string,mixed> $in_daten
     * @param bool                $in_eigen Ist es der eigene Standort?
     * @return string HTML
     */
    public static function zustandHtml(array $in_daten, bool $in_eigen): string
    {
        $zustand = (int)$in_daten['blocked'] === 1 ? 'idle' : (string)$in_daten['availability'];

        if ($in_eigen) {
            $eigen = $zustand === 'live'
                ? 'Sie sind bereit – dieser Standort ist gerade anrufbar.'
                : 'Sie sind nicht bereit – dieser Standort wird gedämpft angezeigt.';
            return '<span class="app-tag app-tag--accent">Ihr Standort</span>'
                 . '<span class="loc__hint">' . self::esc($eigen) . '</span>';
        }

        if ($zustand === 'live') {
            return '<span class="app-tag app-tag--live"><span class="app-dot"></span>Jetzt verfügbar</span>';
        }
        if ($zustand === 'busy') {
            return '<span class="app-tag app-tag--warn"><span class="app-dot"></span>Im Gespräch</span>';
        }
        return '<span class="app-tag">Kein Guide vor Ort</span>';
    }

    /**
     * Der Hinweis auf eine bestehende Sperre.
     *
     * Sichtbar ist diese Seite dann nur noch fuer den Eigentuemer und die
     * Moderation - beide sollen wissen, woran sie sind. Der Grund steht
     * dabei: Ohne ihn ist die Sperre fuer den Guide nicht nachvollziehbar.
     *
     * @param array<string,mixed> $in_daten
     * @param bool                $in_eigen
     * @return string HTML oder Leerstring
     */
    public static function sperrHtml(array $in_daten, bool $in_eigen): string
    {
        if ((int)$in_daten['blocked'] !== 1) return '';

        $grund = trim((string)($in_daten['blocked_reason'] ?? ''));
        $wer   = $in_eigen
            ? 'Ihr Standort ist gesperrt und für andere Nutzer nicht sichtbar.'
            : 'Dieser Standort ist gesperrt und für andere Nutzer nicht sichtbar.';

        return '<div class="alert alert-danger" role="alert">'
             . '<strong>Gesperrt.</strong> ' . self::esc($wer)
             . ($grund !== '' ? ' <span class="loc__reason">Grund: ' . self::esc($grund) . '</span>' : '')
             . '</div>';
    }

    /**
     * Der Bildrahmen, der die Seite anfuehrt - mit dem TITELBILD.
     *
     * ER IST RANDLOS UND OHNE KASTEN. Das Bild liegt ueber die volle
     * Fensterbreite und traegt Titel, Ort und Zustand auf sich; die Anordnung
     * macht assets/css/location.css, hier steht nur, was darin liegt.
     *
     * GENAU EIN BILD, und nicht mehr eine Reihe zum Blaettern. Vorher musste
     * dasselbe Bild beides sein: Hintergrund der Kopfzeile und Beispielbild
     * des Ortes. Ein Titelbild braucht ein sehr breites Format und ruhige
     * Flaechen fuer die Schrift, ein Beispielbild soll zeigen, was man dort
     * sieht - beides zugleich geht selten gut. Welches Bild hier steht, waehlt
     * der Guide aus (LocationController::setCoverImage); die uebrigen stehen
     * als Galerie im Inhaltsbereich.
     *
     * OHNE TITELBILD gibt es keinen leeren Fotokasten, sondern einen flachen
     * Streifen: Der Titel muss auch dann irgendwo stehen, und eine
     * bildschirmhohe graue Flaeche verspricht ein Bild, das nicht kommt.
     *
     * DIE LESBARKEIT DES TITELS HAENGT NICHT AN DIESEM BILD. Sie haengt am
     * Band darunter (assets/css/location.css, .loc-hero__band), das dunkel
     * genug ist, dass weisse Schrift darauf in jedem Fall lesbar bleibt -
     * auch auf einem hellen Foto.
     *
     * @param array<string,mixed>|null $in_cover Das Titelbild, oder null
     * @param string                   $in_titel Fuer den Alternativtext
     * @return string HTML
     */
    public static function titelbildHtml(?array $in_cover, string $in_titel): string
    {
        if ($in_cover === null) {
            return '<div class="loc-hero__frame loc-hero__frame--empty"></div>';
        }

        // loading="eager": Es ist das Groesste auf der Seite und steht ganz
        // oben. Haengte es an einem spaeteren Ladevorgang, saehe der Besucher
        // zuerst eine graue Flaeche.
        return '<div class="loc-hero__frame">'
             . '<img class="loc-hero__cover" src="' . self::bildUrl((int)$in_cover['id'], 'full') . '"'
             . ' alt="' . self::esc($in_titel) . '" loading="eager">'
             . '</div>';
    }

    /**
     * Die Beispielbilder als Streifen im Inhaltsbereich.
     *
     * SIE STANDEN FRUEHER IM KOPF und wechselten sich dort mit dem Titelbild
     * ab. Das hiess: Jedes von ihnen musste Schrift tragen, die nicht zu ihm
     * gehoerte, und wurde auf ein sehr breites Format beschnitten. Hier
     * duerfen sie zeigen, was sie zeigen sollen.
     *
     * OHNE BILDER STEHT HIER GAR NICHTS - kein leerer Rahmen, keine
     * Ueberschrift ohne Inhalt. Ein Standort ohne Beispielbilder hat an dieser
     * Stelle nichts zu sagen.
     *
     * Jede Kachel ist ein VERWEIS auf das Bild in voller Groesse. Ohne
     * JavaScript oeffnet ein Klick es damit; mit JavaScript faengt
     * assets/js/location_page.js den Klick ab und zeigt es in der
     * Grossansicht, in der sich auch blaettern laesst.
     *
     * @param array<int,array<string,mixed>> $in_bilder Nur die Beispielbilder
     * @param string                         $in_titel  Fuer den Alternativtext
     * @return string HTML
     */
    public static function galerieHtml(array $in_bilder, string $in_titel): string
    {
        if ($in_bilder === []) return '';

        $alt = self::esc($in_titel);

        $kacheln = '';
        foreach ($in_bilder as $nr => $bild) {
            $id = (int)$bild['id'];
            $kacheln .= '<a class="loc-shots__item"'
                     . ' href="' . self::bildUrl($id, 'full') . '"'
                     . ' data-full="' . self::bildUrl($id, 'full') . '"'
                     . ' data-shot="' . $nr . '">'
                     . '<img src="' . self::bildUrl($id, 'thumb') . '"'
                     . ' alt="' . $alt . ' – Bild ' . ($nr + 1) . '" loading="lazy">'
                     . '</a>';
        }

        return '<section class="loc__shots">'
             . '<h2 class="loc__h2">Bilder vom Ort</h2>'
             . '<div class="loc-shots" id="loc-shots">' . $kacheln . '</div>'
             . '</section>';
    }

    /**
     * Die Adresse eines Bildes.
     *
     * An genau einer Stelle zusammengesetzt. Aendert sich der Routenname,
     * ist es eine Zeile - und nicht sechs verteilte Zeichenketten.
     *
     * @param int    $in_image_id
     * @param string $in_groesse 'full' oder 'thumb'
     * @return string
     */
    public static function bildUrl($in_image_id, string $in_groesse): string
    {
        return 'index.php?act=location_image&amp;id=' . (int)$in_image_id
             . '&amp;size=' . ($in_groesse === 'full' ? 'full' : 'thumb');
    }

    /**
     * Die Kurzbeschreibung als Anreisser.
     *
     * WEGGELASSEN, WENN SIE DEM TITEL GLEICHT. Bestandsdaten haben genau
     * diesen Fall: migrations/011 hat den Titel aus der Beschreibung
     * gebildet, also steht bis zur ersten Bearbeitung derselbe Satz zweimal
     * da. Ihn zweimal anzuzeigen sieht nach einem Fehler aus.
     *
     * @param array<string,mixed> $in_daten
     * @return string HTML oder Leerstring
     */
    public static function kurztextHtml(array $in_daten): string
    {
        $kurz = trim((string)($in_daten['description'] ?? ''));
        if ($kurz === '' || $kurz === trim(self::titelVon($in_daten))) return '';

        return '<p class="loc__lead">' . self::esc($kurz) . '</p>';
    }

    /**
     * Die ausfuehrliche Beschreibung.
     *
     * Der Text ist mehrzeilig, und die Zeilenumbrueche sind eine Aussage des
     * Guides. In HTML bedeutet ein Umbruch im Quelltext aber nichts, also
     * werden sie uebersetzt: eine Leerzeile wird ein neuer Absatz, ein
     * einfacher Umbruch bleibt ein Umbruch.
     *
     * Maskiert wird VOR dem Ersetzen der Umbrueche. Andersherum wuerden die
     * eben eingesetzten <p> und <br> selbst maskiert und stuenden als Text
     * auf der Seite.
     *
     * @param array<string,mixed> $in_daten
     * @return string HTML
     */
    public static function langtextHtml(array $in_daten): string
    {
        $text = trim((string)($in_daten['description_long'] ?? ''));
        if ($text === '') {
            return '<p class="loc__empty">Der Guide hat noch keine ausführliche '
                 . 'Beschreibung hinterlegt.</p>';
        }

        // Zeilenenden vereinheitlichen: Ein Windows-Browser schickt \r\n,
        // sonst kaeme das \r als Zeichen im Text an.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $absaetze = preg_split('/\n{2,}/', self::esc($text));
        $html = '';
        foreach ($absaetze as $absatz) {
            $absatz = trim($absatz);
            if ($absatz === '') continue;
            $html .= '<p>' . nl2br($absatz, false) . '</p>';
        }

        return '<div class="loc__text">' . $html . '</div>';
    }

    /**
     * Dauer und Sprachen als Beschreibungsliste.
     *
     * Was nicht angegeben ist, steht gar nicht da - eine Zeile "Dauer: keine
     * Angabe" ist eine Zeile ohne Auskunft.
     *
     * @param array<string,mixed> $in_daten
     * @return string HTML
     */
    public static function faktenHtml(array $in_daten): string
    {
        $html = '';

        $minuten = $in_daten['duration_minutes'] ?? null;
        if ($minuten !== null && (int)$minuten > 0) {
            $html .= '<dt>Dauer</dt><dd>' . self::esc(self::dauerText((int)$minuten)) . '</dd>';
        }

        $sprachen = Languages::names($in_daten['languages'] ?? '');
        if ($sprachen !== []) {
            $html .= '<dt>Sprachen</dt><dd>' . self::esc(implode(', ', $sprachen)) . '</dd>';
        }

        $ort = self::ortVon($in_daten);
        if ($ort !== '') {
            $html .= '<dt>Ort</dt><dd>' . self::esc($ort) . '</dd>';
        }

        return $html;
    }

    /**
     * Minuten als lesbare Dauer.
     *
     * "90 Minuten" liest sich schlechter als "1,5 Stunden", und "1 Stunde 30
     * Minuten" schlechter als beides. Unter einer Stunde bleibt es bei
     * Minuten, darueber wird gerundet dargestellt.
     *
     * @param int $in_minuten
     * @return string
     */
    public static function dauerText(int $in_minuten): string
    {
        if ($in_minuten < 60) return $in_minuten . ' Minuten';

        $stunden = intdiv($in_minuten, 60);
        $rest    = $in_minuten % 60;

        $text = $stunden === 1 ? '1 Stunde' : $stunden . ' Stunden';
        if ($rest > 0) $text .= ' ' . $rest . ' Minuten';

        return $text;
    }

    /**
     * Der Knopf, mit dem die Fuehrung beginnt - oder das, was an seiner
     * Stelle steht.
     *
     * VIER FAELLE, und der wichtigste ist der Gast: Ihm fehlt die user_id
     * (die bekommt er von dieser Seite nicht), also kann er von hier aus gar
     * nicht anrufen. Statt eines Knopfes, der nichts tut, steht dort der Weg
     * zur Anmeldung - genau wie im Kartenfenster.
     *
     * DIE STANDORTKENNUNG HAENGT AM KNOPF. Daran haengt beim Server die
     * Rollenvergabe: Von einem Standort aus fuehrt der Angerufene, auch wenn
     * er Admin ist (WebRTCController::callRoles). Ohne sie waere jede
     * Fuehrung ueber einen Admin-Standort ein Gespraech ohne Fuehrung.
     *
     * Wen man anrufen darf, steht in $in_ziel_user_id und wird NICHT aus
     * $in_daten genommen: Ob die user_id des Guides ueberhaupt herausgegeben
     * wird, entscheidet der Controller - fuer einen Gast tut er es nicht.
     *
     * @param array<string,mixed> $in_daten
     * @param bool                $in_eigen
     * @param bool                $in_angemeldet
     * @param int|null            $in_ziel_user_id
     * @return string HTML
     */
    public static function aktionHtml(array $in_daten, bool $in_eigen,
                                      bool $in_angemeldet, $in_ziel_user_id = null): string
    {
        if ($in_eigen) {
            return '<p class="loc__note">Den eigenen Standort ruft man nicht an. '
                 . 'Ob er für andere anrufbar ist, entscheidet Ihr Bereitschaftsschalter '
                 . 'in der Kopfleiste.</p>';
        }

        if ((int)$in_daten['blocked'] === 1) {
            return '<p class="loc__note loc__note--danger">Dieser Standort ist gesperrt. '
                 . 'Von hier aus lässt sich keine Führung starten.</p>';
        }

        $zustand = (string)$in_daten['availability'];

        if ($zustand !== 'live') {
            $text = $zustand === 'busy'
                ? 'Der Guide ist gerade in einer anderen Führung. Versuchen Sie es in ein paar Minuten noch einmal.'
                : 'Dieser Ort wird angeboten, aber gerade ist niemand da. Sobald der Guide bereit ist, lässt sich die Führung von hier aus starten.';
            // Der Knopf steht trotzdem da, nur gesperrt: Sonst springt das
            // Seitenlayout, wenn der Guide waehrend des Lesens bereit wird
            // (assets/js/location_page.js schaltet ihn dann frei).
            return '<button type="button" class="btn btn-secondary loc-call-btn" disabled aria-disabled="true">'
                 . 'Führung starten</button>'
                 . '<p class="loc__note">' . self::esc($text) . '</p>';
        }

        if (!$in_angemeldet || (int)$in_ziel_user_id < 1) {
            return '<p class="loc__note">Für eine Führung brauchen Sie ein Konto – '
                 . 'die Verbindung läuft direkt zwischen Ihnen und dem Guide.</p>'
                 . '<div class="app-actions">'
                 . '<a class="btn btn-primary" href="index.php?act=login_page">Anmelden und starten</a>'
                 . '<a class="btn btn-secondary" href="index.php?act=signup_page">Konto anlegen</a>'
                 . '</div>';
        }

        return '<button type="button" class="btn btn-success loc-call-btn"'
             . ' data-userid="' . (int)$in_ziel_user_id . '"'
             . ' data-locationid="' . (int)$in_daten['id'] . '">'
             . 'Führung starten</button>';
    }

    /**
     * Die Rueckmeldung aus der Adresszeile.
     *
     * Sie kommt von der eigenen Weiterleitung nach dem Speichern
     * (Post/Redirect/Get) - success=1 oder fehler=<Text>.
     *
     * SERVERSEITIG und nicht als Toast im Browser: Diese Meldung ist die
     * Antwort auf ein abgeschicktes Formular. Sie muss auch dann ankommen,
     * wenn kein Skript laeuft - sonst speichert jemand vergeblich und
     * erfaehrt es nicht.
     *
     * Der Fehlertext kommt aus der ADRESSE und ist damit Fremdeingabe: Wer
     * einen Link mit beliebigem Text baut, bekommt ihn angezeigt. Deshalb
     * geht er durch esc() wie jeder andere fremde Text auch. Schaden kann er
     * damit nicht - er steht als Text in einem Kasten, den der Nutzer selbst
     * aufgerufen hat.
     *
     * @param string $in_fehler      Meldung, oder Leerstring
     * @param bool   $in_gespeichert Erfolgreich gespeichert?
     * @return string HTML oder Leerstring
     */
    public static function hinweisHtml(string $in_fehler, bool $in_gespeichert): string
    {
        $fehler = trim($in_fehler);
        if ($fehler !== '') {
            // Gekuerzt: Ein Kasten mit zweitausend Zeichen aus der Adresszeile
            // waere keine Meldung mehr, sondern eine Flaeche.
            return '<div class="alert alert-danger" role="alert">'
                 . self::esc(mb_substr($fehler, 0, 200)) . '</div>';
        }

        if ($in_gespeichert) {
            return '<div class="alert alert-success" role="alert">Gespeichert.</div>';
        }

        return '';
    }

    /**
     * Die Angaben, die das Skript der Seite braucht.
     *
     * Serverseitig gesetzt und nicht nachgeladen: Die Seite soll ohne einen
     * zweiten Abruf vollstaendig sein.
     *
     * Was hier NICHT hineingeht: die user_id, wenn niemand angemeldet ist.
     * Sie kommt aus $in_ansicht und nicht aus $in_daten - dieselbe Quelle wie
     * fuer den Knopf, damit es keinen zweiten Ort gibt, an dem man vergisst,
     * sie herauszunehmen.
     *
     * @param array<string,mixed> $in_daten
     * @param array{cover: array<string,mixed>|null, gallery: array<int,array<string,mixed>>} $in_bilder
     * @param array<string,mixed> $in_ansicht Siehe page()
     * @return string HTML (<script>-Block)
     */
    public static function seitendatenHtml(array $in_daten, array $in_bilder, array $in_ansicht): string
    {
        $eigen   = !empty($in_ansicht['eigen']);
        $grenzen = (array)($in_ansicht['grenzen'] ?? []);
        // Koordinaten nur, wenn es welche gibt. (float)null waere 0.0 - ein
        // gueltiger Punkt im Atlantik, und die Karte zeigte ihn auch brav an.
        // null ist die ehrliche Antwort, und das Skript zeichnet dann keine
        // Karte.
        $hat_punkt = is_numeric($in_daten['latitude']) && is_numeric($in_daten['longitude']);

        $daten = [
            'id'           => (int)$in_daten['id'],
            'lat'          => $hat_punkt ? (float)$in_daten['latitude']  : null,
            'lon'          => $hat_punkt ? (float)$in_daten['longitude'] : null,
            'place'        => self::ortVon($in_daten),
            'availability' => (int)$in_daten['blocked'] === 1 ? 'idle' : (string)$in_daten['availability'],
            'blocked'      => (int)$in_daten['blocked'] === 1,
            'isOwn'        => $eigen,
            'userId'       => isset($in_ansicht['viewer_id']) && (int)$in_ansicht['viewer_id'] > 0
                                  ? (int)$in_ansicht['viewer_id'] : null,
            // Beide Zahlen, denn beide Arten zaehlen gegen dieselbe
            // Obergrenze - siehe maxImages.
            'coverCount'   => isset($in_bilder['cover']) && $in_bilder['cover'] !== null ? 1 : 0,
            'imageCount'   => count((array)($in_bilder['gallery'] ?? [])),
            'maxImages'    => (int)($grenzen['max_images'] ?? 0),
        ];

        // Die Grenzen fuer den Upload gehen mit, damit der Browser eine zu
        // grosse Datei melden kann, bevor er sie acht Megabyte weit
        // hochlaedt. Sie stammen aus config/uploads.php - der einen Stelle,
        // an der sie stehen; im JavaScript steht keine zweite Zahl.
        if ($eigen && isset($grenzen['max_bytes'])) {
            $daten['upload'] = [
                'maxBytes' => (int)$grenzen['max_bytes'],
                'maxEdge'  => (int)$grenzen['max_source_edge'],
                'accept'   => (string)$grenzen['accept'],
            ];
        }

        // JSON_HEX_TAG und JSON_HEX_AMP: Der Block steht in einem <script>,
        // und dort beendet die Zeichenfolge "</script>" aus einem Ortsnamen
        // sonst das Element. json_encode maskiert das nicht von selbst.
        $json = json_encode($daten, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        // UND DIE PLATZHALTER, aus demselben Grund wie in esc(): Hier steht
        // ein Ortsname, und ViewHelper::output() laeuft nach dieser Klasse
        // ueber das ganze Dokument. esc() ist hier nicht zu gebrauchen - im
        // <script> waere "&quot;" ein Syntaxfehler -, also wird die Raute als
        // JSON-Escape geschrieben: \u0023 ergibt im Browser wieder "#", steht
        // aber im Dokument nicht mehr als Muster da, auf das str_replace
        // anspringt.
        return '<script>window.locationPage = '
             . str_replace('###', '\u0023\u0023\u0023', $json)
             . ';</script>';
    }

    /**
     * Das Bearbeitungsformular - nur fuer den Eigentuemer.
     *
     * Es steht in einer eigenen Vorlage (assets/html/location_edit.html) und
     * wird nur gebaut, wenn der Standort dem Aufrufer gehoert. Damit erreicht
     * das Formular einen fremden Browser gar nicht erst; die Routen dahinter
     * pruefen das Eigentum trotzdem noch einmal - Sichtbarkeit ist keine
     * Berechtigung.
     *
     * DIE GRENZEN KOMMEN VON AUSSEN und stehen nicht hier: Sie sind
     * Pruefregeln und gehoeren dorthin, wo geprueft wird
     * (App\Controller\LocationController). Stuenden sie zusaetzlich in
     * dieser Klasse, zeigte das Formular irgendwann andere Zahlen an, als der
     * Server durchlaesst - und der Nutzer bekaeme eine Absage fuer eine
     * Eingabe, die das Feld ausdruecklich erlaubt hat.
     *
     * @param array<string,mixed>            $in_daten
     * @param array<string,mixed>|null       $in_cover   Das Titelbild, oder null
     * @param array<int,array<string,mixed>> $in_gallery Die Beispielbilder
     * @param array<string,mixed>            $in_grenzen Siehe page()
     * @return string HTML
     */
    public static function bearbeitenHtml(array $in_daten, ?array $in_cover,
                                          array $in_gallery, array $in_grenzen): string
    {
        $vorlage = ViewHelper::template('assets/html/location_edit.html');

        $ersetzungen = [
            '###E_LOCATION_ID###' => (string)(int)$in_daten['id'],
            '###E_TITLE###'       => self::esc($in_daten['title'] ?? ''),
            '###E_SHORT###'       => self::esc($in_daten['description'] ?? ''),
            // Ein <textarea> traegt seinen Wert als Inhalt und nicht als
            // Attribut. Maskiert wird trotzdem genauso - ein "</textarea>"
            // im Text beendete sonst das Feld.
            '###E_LONG###'        => self::esc($in_daten['description_long'] ?? ''),
            // Die Dauer mit ihrer Vorgabe, wenn der Standort keine traegt.
            // Bestandsdaten haben keine (migrations/011 setzt sie nicht), und
            // ein leeres Feld waere dort eine stille Aufforderung, es leer zu
            // lassen. Die Zahl kommt aus den Grenzen und steht hier nicht.
            '###E_DURATION###'    => ($in_daten['duration_minutes'] ?? null) !== null
                                        ? (string)(int)$in_daten['duration_minutes']
                                        : (string)(int)($in_grenzen['dauer_vorgabe'] ?? 0),
            '###E_LANGUAGES###'   => self::sprachauswahlHtml($in_daten['languages'] ?? ''),
            '###E_COVER###'       => self::titelbildVerwaltungHtml($in_cover),
            '###E_IMAGES###'      => self::bildverwaltungHtml($in_gallery),
            '###E_IMAGECOUNT###'  => (string)(count($in_gallery) + ($in_cover === null ? 0 : 1)),
            '###E_MAXIMAGES###'   => (string)(int)($in_grenzen['max_images']   ?? 0),
            '###E_TITLE_MAX###'   => (string)(int)($in_grenzen['titel_max']    ?? 0),
            '###E_SHORT_MAX###'   => (string)(int)($in_grenzen['kurz_max']     ?? 0),
            '###E_LONG_MAX###'    => (string)(int)($in_grenzen['lang_max']     ?? 0),
            '###E_DURATION_MIN###'=> (string)(int)($in_grenzen['dauer_min']    ?? 0),
            '###E_DURATION_MAX###'=> (string)(int)($in_grenzen['dauer_max']    ?? 0),
        ];

        return str_replace(array_keys($ersetzungen), array_values($ersetzungen), $vorlage);
    }

    /**
     * Die Sprachauswahl als Kaestchen.
     *
     * Gebaut aus App\Helper\Languages - der einen Stelle, an der der Katalog
     * steht. Eine zweite Liste im Template liefe beim naechsten Eintrag
     * auseinander.
     *
     * @param mixed $in_gewaehlt Gespeicherter Wert ("de,en")
     * @return string HTML
     */
    public static function sprachauswahlHtml($in_gewaehlt): string
    {
        $gewaehlt = array_flip(Languages::codes($in_gewaehlt));

        $html = '';
        foreach (Languages::all() as $code => $name) {
            $id = 'lang-' . $code;
            $html .= '<label class="loc-choice" for="' . $id . '">'
                  . '<input type="checkbox" id="' . $id . '" name="languages[]"'
                  . ' value="' . self::esc($code) . '"'
                  . (isset($gewaehlt[$code]) ? ' checked' : '') . '>'
                  . '<span>' . self::esc($name) . '</span>'
                  . '</label>';
        }
        return $html;
    }

    /**
     * Das Titelbild im Bearbeitungsformular.
     *
     * ENTWEDER das gewaehlte Bild mit einem Knopf, es zurueckzunehmen - ODER
     * der Hinweis, dass noch keines gewaehlt ist. Der Hinweis ist kein
     * Schoenheitsfehler, sondern die Antwort auf die Frage, warum der Kopf
     * der Seite grau ist.
     *
     * "Zurueck in die Galerie" und nicht "Loeschen": Wer sein Titelbild
     * absetzt, will fast immer ein anderes waehlen und nicht dieses Bild
     * verlieren. Loeschen kann er es in der Galerie darunter.
     *
     * @param array<string,mixed>|null $in_cover
     * @return string HTML
     */
    public static function titelbildVerwaltungHtml(?array $in_cover): string
    {
        if ($in_cover === null) {
            return '<p class="loc__empty" data-nocover>'
                 . 'Noch kein Titelbild gewählt. Der Kopf der Seite zeigt so lange nur '
                 . 'Titel und Ort. Wählen Sie unten eines Ihrer Bilder aus – am besten '
                 . 'ein sehr breites mit ruhigen Flächen, auf denen die Schrift steht.'
                 . '</p>';
        }

        $id = (int)$in_cover['id'];
        return '<div class="loc-edit__cover" data-imageid="' . $id . '">'
             . '<img src="' . self::bildUrl($id, 'full') . '" alt="Titelbild">'
             . '<div class="loc-edit__coverActions">'
             . '<button type="button" class="btn btn-secondary btn-sm" id="loc-cover-clear">'
             . 'Zurück in die Galerie</button>'
             . '</div>'
             . '</div>';
    }

    /**
     * Die Beispielbilder im Bearbeitungsformular.
     *
     * Jede Kachel traegt ihre Bild-ID: Daran haengen das Loeschen, die neue
     * Reihenfolge und die Wahl zum Titelbild (assets/js/location_page.js).
     *
     * DREI KNOEPFE JE KACHEL, und der erste ist neu: "Als Titelbild". Er ist
     * der Weg, auf dem der Guide auswaehlt, welches Bild die Kopfzeile fuellt
     * - ohne es dafuer noch einmal hochladen zu muessen.
     *
     * @param array<int,array<string,mixed>> $in_bilder Nur die Beispielbilder
     * @return string HTML
     */
    public static function bildverwaltungHtml(array $in_bilder): string
    {
        if ($in_bilder === []) {
            return '<p class="loc__empty" data-empty>Noch keine Beispielbilder hochgeladen.</p>';
        }

        $html = '';
        foreach ($in_bilder as $nr => $bild) {
            $id = (int)$bild['id'];
            $html .= '<li class="loc-edit__image" data-imageid="' . $id . '">'
                  . '<img src="' . self::bildUrl($id, 'thumb') . '" alt="Bild ' . ($nr + 1) . '">'
                  . '<div class="loc-edit__imageActions">'
                  . '<button type="button" class="app-iconbtn app-iconbtn--cover loc-img-cover"'
                  . ' aria-label="Bild ' . ($nr + 1) . ' als Titelbild verwenden"'
                  . ' title="Als Titelbild"></button>'
                  . '<button type="button" class="app-iconbtn app-iconbtn--up loc-img-up"'
                  . ' aria-label="Bild ' . ($nr + 1) . ' nach vorne" title="Nach vorne"></button>'
                  . '<button type="button" class="app-iconbtn app-iconbtn--down loc-img-down"'
                  . ' aria-label="Bild ' . ($nr + 1) . ' nach hinten" title="Nach hinten"></button>'
                  . '<button type="button" class="app-iconbtn app-iconbtn--delete app-iconbtn--danger loc-img-del"'
                  . ' aria-label="Bild ' . ($nr + 1) . ' löschen" title="Löschen"></button>'
                  . '</div>'
                  . '</li>';
        }
        return $html;
    }

}
