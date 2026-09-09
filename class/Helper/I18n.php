<?php
namespace App\Helper;

/**
 * Die Sprache der Oberflaeche.
 *
 * WOZU DIESE KLASSE
 * -----------------
 * Diese Anwendung ist auf Deutsch geschrieben - jeder Satz steht als
 * Literal an der Stelle, an der er ausgegeben wird. Fuer eine zweite
 * Sprache muesste man jeden dieser Saetze suchen; gefunden wuerde man
 * neunzig Prozent, und die restlichen zehn faenden die Nutzer.
 *
 * Diese Klasse ist die eine Stelle, an der entschieden wird, WELCHE Sprache
 * gilt und WOHER ein Satz kommt. Sie ist das Gegenstueck zu App\Helper\Theme:
 * dort das Farbprofil, hier die Sprache - dieselbe Aufloesungsreihenfolge,
 * dieselbe Trennung zwischen "was ist gueltig" und "was steht in der
 * Datenbank".
 *
 * WIE WEIT DER UMZUG IST
 * ----------------------
 * Das Fundament steht. Umgezogen sind die KATALOGE UND FORMATE -
 * Laendernamen (App\Helper\Countries), Monatsnamen, Wochentage und
 * Tagesabschnitte (App\Helper\Availability), die Zustaende einer Anfrage
 * (App\Model\TourRequest), Dauern, relative Zeitangaben und die beiden
 * E-Mails - und seitdem die TEXTE, DIE PHP ERZEUGT: die Standortseite
 * (App\Helper\LocationView), das Guide-Profil (App\Helper\GuideView), der
 * Bewertungsblock (App\Helper\ReviewView), der Verwaltungsbereich
 * (App\Helper\AdminView), die Kopfleiste (App\Helper\ViewHelper), der
 * Bildspeicher (App\Helper\ImageStore) und die Meldungen der Controller.
 *
 * Seitdem sind auch die VORLAGEN unter assets/html umgezogen - jeder Satz
 * dort steht als {{t:schluessel}} und wird beim Laden der Datei aufgeloest
 * (siehe marker() weiter unten). Was der Marker NICHT kann, ist ein Satz mit
 * einem Wert oder einer Hervorhebung mittendrin: Er nimmt keine Werte
 * entgegen. Solche Saetze stehen im Katalog und werden in PHP gebaut
 * (App\Helper\ViewHelper::tHtml); die Vorlage nennt im Kommentar den
 * Aufrufer.
 *
 * Und seitdem sind auch DIE MELDUNGEN DES BROWSERS umgezogen (assets/js):
 * alles, was erst durch ein Ereignis entsteht und beim Ausliefern der Seite
 * noch gar nicht dastand, dazu die Sprachbloecke von DataTables und select2.
 * Sie holen ihren Text ueber window.webrtcApp.t() und plural()
 * (assets/js/i18n.js) aus demselben Katalog, den bootScript() weiter unten
 * mit der Seite mitschickt - es gibt keinen zweiten.
 *
 * DAMIT IST DER UMZUG DURCH. Dass nichts Neues dazukommt, haelt die Ratsche
 * fest (tests/i18n_scan.php).
 *
 * NICHT UMGEZOGEN WERDEN Logmeldungen und die Texte geworfener Ausnahmen:
 * Sie werden geloggt und nie angezeigt, richten sich also an den Betreiber
 * und nicht an den Benutzer. Ebenso wenig die Meldung in
 * App\Model\PdoConnect - sie ist der Notausgang und darf von keiner
 * weiteren Klasse abhaengen; dort steht, warum.
 *
 * WOHER DIE SPRACHE BEIM SEITENAUFBAU KOMMT
 * -----------------------------------------
 * In dieser Reihenfolge, die erste Quelle mit einer Antwort gewinnt:
 *
 *   1. Das Konto        - nur angemeldet (Spalte user.lang, Migration 022).
 *                         Es gewinnt, weil es die einzige Quelle ist, die
 *                         der Nutzer bewusst gesetzt hat UND die ihm ueber
 *                         Geraete hinweg folgt.
 *   2. Das Cookie       - fuer Gaeste. Siehe unten, warum kein localStorage.
 *   3. Accept-Language  - was der Browser von sich aus mitschickt. Damit
 *                         trifft die Anwendung beim ERSTEN Aufruf schon
 *                         oefter richtig als mit einer festen Vorgabe.
 *   4. self::DEFAULT    - wenn auch das nichts Bekanntes sagt.
 *
 * WARUM EIN COOKIE UND NICHT localStorage
 * ---------------------------------------
 * Das ist der Unterschied zum Farbprofil, und er ist kein Geschmacksfrage:
 * Das Farbprofil setzt der BROWSER (ein Attribut am <html>-Element, den Rest
 * machen CSS-Variablen). Den Text setzt der SERVER - er baut die Seite
 * bereits fertig zusammen. Was nur im localStorage steht, kennt der Server
 * beim Bauen nicht; die erste Seite kaeme in der falschen Sprache und wuerde
 * anschliessend per JavaScript umgeschrieben. Ein Cookie geht mit JEDER
 * Anfrage mit, also auch mit der allerersten.
 *
 * WAS DAS FUER DIE CACHES BEDEUTET
 * --------------------------------
 * Dieselbe Adresse liefert je nach Cookie und Accept-Language verschiedenen
 * Text. Ein Zwischenspeicher, der das nicht weiss, zeigt dem naechsten
 * Besucher die Sprache des vorigen. Deshalb schickt start() eine
 * Vary-Kopfzeile - siehe dort.
 */
class I18n
{
    /**
     * Die Sprache, solange keine Quelle etwas anderes sagt.
     *
     * Englisch und nicht Deutsch, obwohl die Anwendung heute deutsch ist:
     * Die Vorgabe gilt fuer jeden, dessen Browser nichts Bekanntes meldet -
     * also gerade fuer die, deren Sprache die Anwendung nicht kennt. Fuer
     * die ist Englisch die bessere Annahme. Wer Deutsch spricht, bekommt es
     * ueber Accept-Language.
     */
    public const DEFAULT = 'en';

    /**
     * Die waehlbaren Sprachen: Kuerzel => Name in der Sprache selbst.
     *
     * In der Sprache selbst, aus demselben Grund wie in App\Helper\Languages:
     * Wer die Oberflaeche auf Deutsch will, sucht nach "Deutsch" und nicht
     * nach "German".
     *
     * NICHT DASSELBE WIE App\Helper\Languages. Dort stehen die Sprachen, in
     * denen ein Guide FUEHREN kann - eine Angabe zum Angebot, dreizehn
     * Eintraege lang. Hier stehen die Sprachen, in denen die ANWENDUNG
     * vorliegt; jeder Eintrag hier setzt eine vollstaendige Katalogdatei
     * unter lang/ voraus. Die beiden Listen zusammenzulegen hiesse, fuer
     * Japanisch entweder eine Uebersetzung zu behaupten, die es nicht gibt,
     * oder einem japanischen Guide sein Angebot zu streichen.
     */
    public const SPRACHEN = [
        'de' => 'Deutsch',
        'en' => 'English',
    ];

    /** Der Name des Cookies, in dem die Wahl eines Gastes steht. */
    public const COOKIE = 'webrtcapp_lang';

    /** Wie lange das Cookie gilt, in Sekunden (ein Jahr). */
    public const COOKIE_LAUFZEIT = 31536000;

    /**
     * Die Sprache dieser Anfrage. null, solange start() nicht lief.
     *
     * @var string|null
     */
    private static $aktiv = null;

    /**
     * Die bereits geladenen Kataloge: Kuerzel => Schluessel => Text.
     *
     * @var array<string,array<string,mixed>>
     */
    private static $kataloge = [];

    /**
     * Ist der Wert eine Sprache, in der die Anwendung vorliegt?
     *
     * @param mixed $in_wert
     * @return bool
     */
    public static function isValid($in_wert): bool
    {
        return is_string($in_wert) && isset(self::SPRACHEN[$in_wert]);
    }

    /**
     * Macht aus einem beliebigen Wert eine gueltige Sprache.
     *
     * Faengt NULL (nie gewaehlt), einen Leerstring und den Fall ab, dass ein
     * Katalog spaeter entfaellt, dessen Kuerzel aber noch in einem Konto
     * steht - dasselbe wie Theme::normalize().
     *
     * @param mixed $in_wert
     * @return string Kuerzel aus self::SPRACHEN
     */
    public static function normalize($in_wert): string
    {
        return self::isValid($in_wert) ? $in_wert : self::DEFAULT;
    }

    /**
     * Bestimmt die Sprache aus den vier Quellen und legt sie fest.
     *
     * DIE REIHENFOLGE STEHT IM KLASSENKOMMENTAR. Hier steht nur, dass sie
     * genau einmal ausgewertet wird: Jede Stelle, die spaeter fragt, fragt
     * self::aktiv() und nicht noch einmal die Quellen ab - sonst gaebe es
     * zwei Antworten auf dieselbe Frage.
     *
     * @param string|null $in_ausKonto        Wert aus user.lang, sonst null
     * @param string|null $in_ausCookie       Wert aus dem Cookie, sonst null
     * @param string|null $in_acceptLanguage  Roher Accept-Language-Header
     * @return string Die gueltige Sprache
     */
    public static function aufloesen(
        ?string $in_ausKonto,
        ?string $in_ausCookie,
        ?string $in_acceptLanguage
    ): string {
        if (self::isValid($in_ausKonto))  return self::$aktiv = $in_ausKonto;
        if (self::isValid($in_ausCookie)) return self::$aktiv = $in_ausCookie;

        $ausBrowser = self::ausAcceptLanguage((string)$in_acceptLanguage);
        if ($ausBrowser !== null) return self::$aktiv = $ausBrowser;

        return self::$aktiv = self::DEFAULT;
    }

    /**
     * Liest die erste bekannte Sprache aus einem Accept-Language-Header.
     *
     * DER HEADER SIEHT SO AUS:  de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7
     *
     * Ausgewertet wird nach Gewicht (q), nicht nach Reihenfolge: Beides faellt
     * meistens zusammen, aber "en;q=0.5,de;q=0.9" ist ein zulaessiger Header,
     * und dort steht die gewuenschte Sprache an zweiter Stelle. Fehlt q, gilt
     * 1.0 - so sagt es RFC 9110.
     *
     * VOM KUERZEL ZAEHLT NUR DER ERSTE TEIL: "de-AT" und "de-CH" sind fuer
     * diese Anwendung Deutsch. Ein eigener Katalog je Land waere eine
     * Unterscheidung, die kein Text hier braucht.
     *
     * "*" WIRD UEBERGANGEN. Es heisst "irgendetwas" und ist damit keine
     * Antwort auf die Frage, welche Sprache jemand liest - dafuer gibt es
     * self::DEFAULT, und das ist eine Entscheidung dieser Anwendung und
     * nicht die eines Browsers.
     *
     * @param string $in_header Roher Wert von Accept-Language
     * @return string|null Kuerzel aus self::SPRACHEN, oder null
     */
    public static function ausAcceptLanguage(string $in_header): ?string
    {
        $header = trim($in_header);
        if ($header === '') return null;

        $kandidaten = [];
        foreach (explode(',', $header) as $position => $eintrag) {
            $teile   = explode(';', $eintrag);
            $kuerzel = strtolower(trim($teile[0]));
            if ($kuerzel === '' || $kuerzel === '*') continue;

            // Nur der Sprachteil, ohne Land: "de-at" wird zu "de".
            $kuerzel = explode('-', $kuerzel)[0];
            if (!self::isValid($kuerzel)) continue;

            $gewicht = 1.0;
            for ($i = 1; $i < count($teile); $i++) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $teile[$i], $t)) {
                    $gewicht = (float)$t[1];
                }
            }
            // q=0 heisst ausdruecklich "diese nicht".
            if ($gewicht <= 0) continue;

            // Die Position entscheidet bei gleichem Gewicht - sonst haengt
            // das Ergebnis von der Sortierfunktion ab.
            if (!isset($kandidaten[$kuerzel]) || $gewicht > $kandidaten[$kuerzel][0]) {
                $kandidaten[$kuerzel] = [$gewicht, $position];
            }
        }

        if ($kandidaten === []) return null;

        uasort($kandidaten, static function (array $a, array $b): int {
            return $b[0] <=> $a[0] ?: $a[1] <=> $b[1];
        });

        return (string)array_key_first($kandidaten);
    }

    /**
     * Die Sprache dieser Anfrage.
     *
     * Faellt auf die Vorgabe zurueck, wenn start() nicht lief - das ist der
     * Fall in Tests und auf der Kommandozeile (Cronjob). Ein Text soll dort
     * nicht fehlen, nur weil niemand eine Anfrage gestellt hat.
     *
     * @return string
     */
    public static function aktiv(): string
    {
        return self::$aktiv ?? self::DEFAULT;
    }

    /**
     * Setzt die Sprache von Hand. Fuer Tests und fuer den Umschalter.
     *
     * @param mixed $in_sprache
     * @return string Die tatsaechlich gesetzte Sprache
     */
    public static function setzen($in_sprache): string
    {
        return self::$aktiv = self::normalize($in_sprache);
    }

    /**
     * Vergisst Sprache und geladene Kataloge. Nur fuer Tests.
     *
     * @return void
     */
    public static function zuruecksetzen(): void
    {
        self::$aktiv    = null;
        self::$kataloge = [];
    }

    /**
     * Der Katalog einer Sprache.
     *
     * Geladen wird hoechstens einmal je Sprache und Prozess. Fehlt die Datei
     * oder liefert sie kein Array, ist der Katalog leer - dann greift die
     * Kette in t(), und die Anwendung laeuft weiter. Eine fehlende
     * Katalogdatei darf keine Seite kosten.
     *
     * @param string|null $in_sprache Kuerzel, oder null fuer die aktive
     * @return array<string,mixed>
     */
    public static function katalog(?string $in_sprache = null): array
    {
        $sprache = self::normalize($in_sprache ?? self::aktiv());
        if (isset(self::$kataloge[$sprache])) return self::$kataloge[$sprache];

        $pfad = self::katalogPfad($sprache);
        if (!is_file($pfad)) {
            error_log('Sprachkatalog fehlt: ' . $pfad);
            return self::$kataloge[$sprache] = [];
        }

        $inhalt = require $pfad;
        if (!is_array($inhalt)) {
            error_log('Sprachkatalog liefert kein Array: ' . $pfad);
            $inhalt = [];
        }

        return self::$kataloge[$sprache] = $inhalt;
    }

    /**
     * Der Dateipfad eines Katalogs.
     *
     * Oeffentlich, weil die Tests dieselbe Stelle brauchen wie die Anwendung:
     * Ein Test, der den Pfad selbst zusammensetzt, prueft irgendwann eine
     * andere Datei als die, die im Betrieb geladen wird.
     *
     * @param string $in_sprache
     * @return string
     */
    public static function katalogPfad(string $in_sprache): string
    {
        return dirname(__DIR__, 2) . '/lang/' . $in_sprache . '.php';
    }

    /**
     * Der Text zu einem Schluessel.
     *
     * DIE KETTE, und zwar in dieser Reihenfolge:
     *
     *   1. Der Katalog der aktiven Sprache.
     *   2. Der Katalog von self::DEFAULT. Greift nur, solange eine
     *      Uebersetzung noch fehlt - im Regelfall haben beide Kataloge
     *      dieselben Schluessel, und ein Test haelt das fest.
     *   3. DER SCHLUESSEL SELBST. Das ist Absicht und keine Notloesung: Ein
     *      Fehlgriff steht damit sichtbar auf der Seite ("konto.titel" statt
     *      einer Luecke). Eine Luecke faellt im Betrieb niemandem auf - und
     *      damit auch nicht auf, dass ein Text fehlt.
     *
     * @param string               $in_schluessel  z.B. 'sprache.titel'
     * @param array<string,mixed>  $in_werte       Platzhalter, siehe einsetzen()
     * @return string
     */
    public static function t(string $in_schluessel, array $in_werte = []): string
    {
        $text = self::rohtext($in_schluessel);
        if ($text === null) return $in_schluessel;

        return self::einsetzen($text, $in_werte);
    }

    /**
     * Der Text zu einem Schluessel in EINER BESTIMMTEN Sprache.
     *
     * WOZU ES DAS GIBT: fuer die E-Mails. Eine Seite entsteht in der Sprache
     * DESSEN, DER SIE AUFRUFT - da genuegt t(). Eine E-Mail geht an jemand
     * anderen, und sie soll in SEINER Sprache ankommen: Der Guide, dessen
     * Oberflaeche englisch steht, loest mit einer Registrierung keine
     * englische Bestaetigungsmail an einen deutschen Kunden aus. Die Sprache
     * des Empfaengers steht in user.lang.
     *
     * WARUM NICHT setzen() UND HINTERHER ZURUECK: Weil dazwischen jeder Text
     * dieser Anfrage in der fremden Sprache herauskaeme - und weil ein
     * vergessenes Zuruecksetzen (ein return, eine Ausnahme) den Rest der
     * Seite still umstellte. Die Sprache dieser Anfrage bleibt hier
     * unberuehrt; uebergeben wird sie als Wert.
     *
     * @param string              $in_sprache    Kuerzel; Unbekanntes wird zur Vorgabe
     * @param string              $in_schluessel
     * @param array<string,mixed> $in_werte
     * @return string
     */
    public static function tIn(string $in_sprache, string $in_schluessel, array $in_werte = []): string
    {
        $text = self::rohtext($in_schluessel, $in_sprache);
        if ($text === null) return $in_schluessel;
        if (!is_string($text))  return $in_schluessel;

        return self::einsetzen($text, $in_werte);
    }

    /**
     * Der Text zu einem Schluessel in der Form, die zu n passt.
     *
     * WARUM n UEBERGEBEN WIRD UND NICHT NUR EINGESETZT
     * ------------------------------------------------
     * "1 Nachricht" und "2 Nachrichten" sind nicht derselbe Satz mit einer
     * anderen Zahl darin, sondern zwei Saetze. Wer nur die Zahl einsetzt,
     * schreibt "1 Nachrichten" - oder baut sich im Code ein if, und dann
     * steht die Sprachregel im Code statt im Katalog. Die naechste Sprache
     * hat dann drei Formen, und das if steht an der falschen Stelle.
     *
     * DIE FORMEN heissen 'one' und 'other'. Deutsch und Englisch brauchen
     * genau diese zwei, und beide teilen dieselbe Regel: 'one' fuer n === 1,
     * sonst 'other'. Kommt eine Sprache mit mehr Formen dazu (Polnisch,
     * Russisch, Arabisch), wird DIESE Methode um deren Regel ergaenzt - und
     * kein Aufrufer.
     *
     * {n} STEHT IMMER ZUR VERFUEGUNG und muss nicht uebergeben werden: Die
     * Zahl ist der Grund, aus dem plural() ueberhaupt gerufen wird. Ein
     * uebergebenes 'n' hat trotzdem Vorrang - etwa fuer "keine Nachrichten"
     * statt "0 Nachrichten".
     *
     * @param string              $in_schluessel
     * @param int                 $in_n
     * @param array<string,mixed> $in_werte
     * @return string
     */
    public static function plural(string $in_schluessel, int $in_n, array $in_werte = []): string
    {
        $roh = self::rohtext($in_schluessel);
        if ($roh === null) return $in_schluessel;

        if (is_array($roh)) {
            $form = self::pluralForm(self::aktiv(), $in_n);
            // Faellt auf 'other' zurueck: Ein Katalog, dem eine Form fehlt,
            // soll den Satz nicht verlieren.
            $text = $roh[$form] ?? $roh['other'] ?? null;
            if (!is_string($text)) return $in_schluessel;
        } else {
            // Ein Schluessel ohne Formen. Kommt vor, wenn ein Text erst
            // spaeter zaehlbar wurde - dann ist er wenigstens da.
            $text = $roh;
        }

        return self::einsetzen($text, $in_werte + ['n' => $in_n]);
    }

    /**
     * Welche Form gilt in dieser Sprache fuer diese Anzahl?
     *
     * Heute fuer beide Sprachen dieselbe Regel. Die Sprache steht trotzdem im
     * Parameter, damit die naechste Sprache hier ein Fall wird und nicht ein
     * zweites plural().
     *
     * Gerechnet wird mit dem Betrag: "-1 Nachricht" ist dieselbe Form wie
     * "1 Nachricht".
     *
     * @param string $in_sprache
     * @param int    $in_n
     * @return string 'one' oder 'other'
     */
    public static function pluralForm(string $in_sprache, int $in_n): string
    {
        switch ($in_sprache) {
            case 'de':
            case 'en':
            default:
                return abs($in_n) === 1 ? 'one' : 'other';
        }
    }

    /**
     * Setzt Platzhalter der Form {name} ein.
     *
     * NICHT ERSETZT WIRD, WAS NICHT UEBERGEBEN WURDE. "{name}" bleibt dann
     * als "{name}" stehen - aus demselben Grund, aus dem t() den Schluessel
     * zurueckgibt: Ein sichtbarer Rest ist ein Fehler, den jemand meldet.
     * Eine stillschweigend geleerte Stelle ist ein Satz, dem ein Wort fehlt.
     *
     * ES WIRD NICHT MASKIERT. Diese Methode weiss nicht, ob ihr Ergebnis in
     * HTML, in eine E-Mail oder in ein JSON geht. Maskiert wird dort, wo
     * ausgegeben wird - mit ViewHelper::esc(), wie bei jedem anderen Wert
     * auch.
     *
     * @param string              $in_text
     * @param array<string,mixed> $in_werte
     * @return string
     */
    public static function einsetzen(string $in_text, array $in_werte): string
    {
        if ($in_werte === []) return $in_text;

        $suchen  = [];
        $ersetzen = [];
        foreach ($in_werte as $name => $wert) {
            if (!is_scalar($wert) && $wert !== null) continue;
            $suchen[]   = '{' . $name . '}';
            $ersetzen[] = (string)$wert;
        }

        return str_replace($suchen, $ersetzen, $in_text);
    }

    /**
     * Der unveraenderte Katalogeintrag - Zeichenkette oder Formenarray.
     *
     * @param string      $in_schluessel
     * @param string|null $in_sprache    null heisst: die aktive Sprache
     * @return string|array<string,string>|null null, wenn kein Katalog ihn kennt
     */
    private static function rohtext(string $in_schluessel, ?string $in_sprache = null)
    {
        $sprache = self::normalize($in_sprache ?? self::aktiv());

        $eigen = self::katalog($sprache);
        if (isset($eigen[$in_schluessel])) return $eigen[$in_schluessel];

        if ($sprache !== self::DEFAULT) {
            $vorgabe = self::katalog(self::DEFAULT);
            if (isset($vorgabe[$in_schluessel])) return $vorgabe[$in_schluessel];
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // DER MARKER IN DEN VORLAGEN
    // -----------------------------------------------------------------------

    /**
     * Das Muster, mit dem ein Text in einer HTML-Vorlage steht: {{t:schluessel}}
     *
     * Erlaubt sind Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich -
     * also genau das, was ein Schluessel im Katalog sein darf. Kein
     * Leerzeichen und keine Klammer: Was nicht in dieses Muster passt, ist
     * kein Marker und bleibt unangetastet.
     *
     * ZWEI GESCHWEIFTE KLAMMERN und nicht die Rauten der uebrigen Platzhalter
     * (###NAME###), weil die beiden Dinge verschieden sind und
     * auseinandergehalten werden muessen: ###NAME### setzt der SERVER mit
     * fertigem HTML, {{t:...}} ist ein Text aus dem Katalog. Ein Marker
     * bringt nie Markup mit; ein Platzhalter fast immer.
     */
    public const MARKER = '/\{\{t:([A-Za-z0-9_.\-]+)\}\}/';

    /**
     * Loest alle {{t:schluessel}} in einer Vorlage auf.
     *
     * WANN DAS LAEUFT: beim Laden der Vorlage, in
     * App\Helper\ViewHelper::template() - also BEVOR ein Controller
     * Fremdeingabe in die Vorlage einsetzt. Das ist die eigentliche
     * Absicherung: Was danach in die Seite kommt, wird gar nicht mehr nach
     * Markern durchsucht.
     *
     * DIE ZWEITE ABSICHERUNG steht in ViewHelper::esc(): Dort werden die
     * beiden Klammern unschaedlich gemacht. Beides zusammen, und nicht nur
     * eines, aus demselben Grund wie bei den Rauten - eine einzelne
     * Vorkehrung faellt beim naechsten Umbau weg, ohne dass es jemand merkt.
     *
     * MASKIERT WIRD HIER NICHT. Die Kataloge sind Quelltext dieser Anwendung
     * und keine Eingabe; ein Text darf ein "&" oder ein Anfuehrungszeichen
     * enthalten und soll es dann auch. Fremdeingabe kommt hier nie an.
     *
     * @param string $in_html
     * @return string
     */
    public static function marker(string $in_html): string
    {
        if (strpos($in_html, '{{t:') === false) return $in_html;

        return (string)preg_replace_callback(
            self::MARKER,
            static function (array $treffer): string {
                return self::t($treffer[1]);
            },
            $in_html
        );
    }

    // -----------------------------------------------------------------------
    // DER WEG IN DEN BROWSER
    // -----------------------------------------------------------------------

    /**
     * Legt Sprache und Katalog als <script> ins Dokument.
     *
     * WARUM DER GANZE KATALOG UND NICHT NUR DAS GEBRAUCHTE
     * ----------------------------------------------------
     * Weil niemand vorher weiss, was gebraucht wird: Die Meldungen des
     * Browsers entstehen aus Ereignissen (ein Anruf kommt herein, ein Upload
     * schlaegt fehl), nicht aus der Seite. Ein Katalog, der nur die Texte
     * dieser einen Seite enthaelt, laesst genau die Meldung fehlen, die im
     * Ausnahmefall gebraucht wird.
     *
     * WARUM INS DOKUMENT UND NICHT ALS EIGENE ANFRAGE
     * -----------------------------------------------
     * Dasselbe Muster wie bei window.locationPage (App\Helper\LocationView)
     * und window.reviewScale: Angaben, die der Server ohnehin hat, gehen mit
     * der Seite mit. Eine zweite Anfrage waere ein zweiter Weg, auf dem
     * etwas schiefgehen kann - und bis sie beantwortet ist, haette das
     * Skript keine Texte.
     *
     * DIE MASKIERUNG ist dieselbe wie dort: JSON_HEX_TAG und Verwandte
     * verhindern, dass ein "</script>" im Text das Element beendet, und die
     * Rauten werden als # geschrieben, damit ViewHelper::output() nicht
     * ueber einen Text laeuft, der aussieht wie ein Platzhalter.
     *
     * @return string Ein vollstaendiges <script>-Element
     */
    public static function bootScript(): string
    {
        $daten = [
            'lang'    => self::aktiv(),
            'default' => self::DEFAULT,
            'catalog' => self::katalog(self::aktiv()),
            // Der Vorgabekatalog geht mit, damit die Kette im Browser
            // dieselbe ist wie in PHP: Sprache, dann Vorgabe, dann der
            // Schluessel. Ohne ihn faende ein Skript den Text nicht, den
            // die Seite daneben sehr wohl anzeigt.
            'fallback' => self::aktiv() === self::DEFAULT
                ? new \stdClass()
                : self::katalog(self::DEFAULT),
        ];

        $json = json_encode(
            $daten,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );

        return '<script>window.appI18n = '
             . str_replace('###', '\u0023\u0023\u0023', (string)$json)
             . ';</script>';
    }

    // -----------------------------------------------------------------------
    // DER EINSTIEG
    // -----------------------------------------------------------------------

    /**
     * Bestimmt die Sprache dieser Anfrage und meldet sie den Zwischenspeichern.
     *
     * GERUFEN WIRD SIE IN index.php, nach der Datenbankverbindung und vor dem
     * Controller: Die Kontospalte braucht die Verbindung, und der Controller
     * baut bereits Text.
     *
     * DIE VARY-KOPFZEILE. Dieselbe Adresse liefert je nach Cookie und
     * Accept-Language verschiedenen Text. Ein Proxy oder ein Browsercache,
     * der das nicht weiss, legt die erste Antwort ab und gibt sie dem
     * naechsten - der bekaeme dann die Sprache eines Fremden. "Vary: Cookie"
     * ist dabei der wichtigere der beiden Punkte: Das Cookie traegt die
     * bewusste Wahl.
     *
     * @param string|null $in_ausKonto Wert aus user.lang, sonst null
     * @return string Die gueltige Sprache
     */
    public static function start(?string $in_ausKonto): string
    {
        $cookie = isset($_COOKIE[self::COOKIE]) && is_scalar($_COOKIE[self::COOKIE])
            ? (string)$_COOKIE[self::COOKIE]
            : null;

        $accept = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) && is_scalar($_SERVER['HTTP_ACCEPT_LANGUAGE'])
            ? (string)$_SERVER['HTTP_ACCEPT_LANGUAGE']
            : null;

        $sprache = self::aufloesen($in_ausKonto, $cookie, $accept);

        // Auf der Kommandozeile gibt es keine Kopfzeilen (Cronjob, Tests) -
        // dieselbe Vorkehrung wie in App\Helper\SecurityHeaders.
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            header('Vary: Accept-Language, Cookie', false);
        }

        return $sprache;
    }

    /**
     * Schreibt die Wahl in das Cookie.
     *
     * WARUM AUCH FUER ANGEMELDETE. Das Cookie ueberlebt das Abmelden. Wer
     * seine Sprache im Konto umstellt und sich abmeldet, bekommt das
     * Anmeldeformular sonst wieder in der alten Sprache - und muss die Wahl
     * ein zweites Mal treffen, um sich anmelden zu koennen.
     *
     * SameSite=Lax: Das Cookie soll bei einem Klick von aussen mitgehen (ein
     * weitergegebener Standort-Link fuehrt sonst in die falsche Sprache),
     * aber nicht bei einer eingebetteten Anfrage. httponly ist hier FALSCH:
     * Der Umschalter im Browser darf es lesen duerfen, und geheim ist an
     * einer Sprachwahl nichts.
     *
     * @param string $in_sprache
     * @return void
     */
    public static function cookieSetzen(string $in_sprache): void
    {
        if (!self::isValid($in_sprache)) return;
        if (PHP_SAPI === 'cli' || headers_sent()) return;

        setcookie(self::COOKIE, $in_sprache, [
            'expires'  => time() + self::COOKIE_LAUFZEIT,
            'path'     => '/',
            'secure'   => Https::istSicher() || Https::erzwungen(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}
