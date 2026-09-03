<?php
namespace App\Helper;

/**
 * Die Farbprofile der Oberflaeche.
 *
 * WOZU DIESE KLASSE
 * -----------------
 * Ein Farbprofil ist an drei Stellen praesent: in der CSS-Datei (die Werte),
 * auf der Kontoseite (die Auswahl) und in der Datenbank (das Gemerkte).
 * Damit die drei nicht auseinanderlaufen, steht hier die eine Liste, aus der
 * sich alles andere ableitet. Ein fuenftes Profil ist ein Eintrag in
 * self::PROFILE plus ein Block in assets/css/theme.css - sonst nichts.
 *
 * WAS EIN PROFIL AENDERN DARF
 * ---------------------------
 * Flaechen, Linien, Text, Akzent und Schatten. NICHT die Farben der
 * Kartennadeln: Gruen heisst "Guide jetzt verfuegbar", Gelb "im Gespraech",
 * Grau "Standort ohne Guide". Das ist die wichtigste Auskunft der ganzen
 * Anwendung, und sie muss in jedem Profil dieselbe bleiben - sonst bedeutet
 * dieselbe Farbe je nach Einstellung etwas anderes. Ein Test in
 * tests/server_test.php haelt das fest.
 *
 * WARUM KEIN "SYSTEM"-EINTRAG IN DER LISTE
 * ----------------------------------------
 * Die Vorgabe des Betriebssystems (prefers-color-scheme) ist kein fuenftes
 * Profil zur Auswahl, sondern der AUSGANGSPUNKT fuer alle, die noch nie
 * etwas gewaehlt haben. Stuende "System" als Eintrag daneben, gaebe es
 * einen Zustand, den man auswaehlen kann und der doch von aussen bestimmt
 * wird - die Auswahl zeigte dann nicht mehr, was gilt.
 *
 * WOHER DAS PROFIL BEIM SEITENAUFBAU KOMMT
 * ----------------------------------------
 * In dieser Reihenfolge, die erste Quelle mit einer Antwort gewinnt:
 *
 *   1. Das Konto      - nur angemeldet. Es ueberschreibt beim Anmelden auch
 *                       den lokalen Wert: Das Konto ist die Wahrheit.
 *   2. Der Browser    - localStorage. Damit gilt die Wahl auch auf Login,
 *                       Registrierung und Passwort-vergessen, wo noch
 *                       niemand angemeldet ist.
 *   3. Das System     - prefers-color-scheme fuer alle, die nie gewaehlt
 *                       haben.
 *   4. self::DEFAULT  - wenn auch das nichts sagt.
 *
 * Punkt 2 und 3 muessen VOR dem ersten Zeichnen entschieden sein, sonst
 * blitzt die helle Seite auf. Deshalb baut self::bootScript() ein kleines
 * Skript, das im <head> steht - vor den Stilvorlagen.
 */
class Theme
{
    /** Wird benutzt, solange nichts gewaehlt wurde. */
    public const DEFAULT = 'indigo';

    /**
     * Die Profile in der Reihenfolge, in der sie auf der Kontoseite stehen.
     *
     * schluessel => [ Name, Beschreibung, Vorschaufarben ]
     *
     * Die Vorschaufarben sind NUR fuer das kleine Muster neben dem Namen da.
     * Sie sind Kopien aus assets/css/theme.css; die Wahrheit steht dort. Ein
     * Test vergleicht beide, damit die Muster nicht anfangen zu luegen.
     */
    public const PROFILE = [
        'indigo' => [
            'name'  => 'Indigo',
            'text'  => 'Die Vorgabe. Kühles Grau mit indigoblauem Akzent.',
            'muster' => ['#e9edf3', '#ffffff', '#4a54d6'],
        ],
        'himmelblau' => [
            'name'  => 'Himmelblau',
            'text'  => 'Hell und freundlich, mit leicht blauer Grundfläche.',
            'muster' => ['#e2eef8', '#ffffff', '#0f6fc4'],
        ],
        'dunkel' => [
            'name'  => 'Dunkel',
            'text'  => 'Dunkle Flächen für Abende und dunkle Räume.',
            'muster' => ['#0d1116', '#1e242d', '#7f89ff'],
        ],
        'neutral' => [
            'name'  => 'Neutral',
            'text'  => 'Sehr zurückhaltend, ohne farbigen Akzent.',
            'muster' => ['#e9ebee', '#ffffff', '#38424e'],
        ],
    ];

    /** Der Schluessel, unter dem der Browser die Wahl merkt. */
    public const STORAGE_KEY = 'webrtcapp.theme';

    /**
     * Das Profil, das bei "Betriebssystem steht auf dunkel" gilt.
     *
     * prefers-color-scheme kennt nur hell und dunkel. Fuer "dunkel" gibt es
     * hier eine eindeutige Antwort; "hell" faellt auf die Vorgabe zurueck,
     * weil zwischen Indigo, Himmelblau und Neutral niemand von aussen
     * entscheiden kann.
     */
    public const OS_DARK = 'dunkel';

    /**
     * Baut das Skript, das das Farbprofil vor dem ersten Zeichnen setzt.
     *
     * WARUM INLINE UND NICHT IN EINER EIGENEN DATEI
     * ---------------------------------------------
     * Eine externe Datei wird geladen, waehrend die Seite schon gezeichnet
     * wird. Genau dann blitzt bei einem dunklen Profil kurz die helle Seite
     * auf. Nur ein Skript, das im <head> steht und sofort laeuft, ist frueh
     * genug. Deshalb ausnahmsweise Code im Dokument.
     *
     * WARUM ES AUS PHP KOMMT
     * ----------------------
     * Das Skript muss wissen, welche Profile es gibt - sonst schriebe ein
     * verstellter Eintrag im Browserspeicher beliebigen Text in das
     * data-theme-Attribut. Die Liste wird deshalb hier eingesetzt und nicht
     * im JavaScript wiederholt. So gibt es sie weiterhin nur einmal.
     *
     * @param string|null $ausKonto Profil des angemeldeten Kontos, sonst null
     * @return string Ein vollstaendiges <script>-Element
     */
    public static function bootScript(?string $ausKonto): string
    {
        $erlaubt = json_encode(array_keys(self::PROFILE), JSON_UNESCAPED_UNICODE);
        $konto   = json_encode($ausKonto, JSON_UNESCAPED_UNICODE);
        $schluessel = json_encode(self::STORAGE_KEY);
        $vorgabe = json_encode(self::DEFAULT);
        $dunkel  = json_encode(self::OS_DARK);

        return <<<HTML
<script>
/* Setzt das Farbprofil, bevor die Seite gezeichnet wird. Siehe
   App\Helper\Theme::bootScript() - dort steht, warum das hier inline steht. */
(function () {
    var erlaubt = $erlaubt;
    var konto   = $konto;
    var wahl    = null;

    try {
        if (konto && erlaubt.indexOf(konto) !== -1) {
            // Angemeldet: Das Konto gewinnt und schreibt den lokalen Wert um.
            wahl = konto;
            localStorage.setItem($schluessel, konto);
        } else {
            var lokal = localStorage.getItem($schluessel);
            if (lokal && erlaubt.indexOf(lokal) !== -1) wahl = lokal;
        }
    } catch (e) {
        // localStorage kann fehlen oder gesperrt sein (privates Fenster,
        // Einstellung im Browser). Dann bleibt es bei der Systemvorgabe -
        // ein Fehler hier darf die Seite nicht aufhalten.
    }

    if (!wahl) {
        // Nie etwas gewaehlt: Was das Betriebssystem sagt.
        var dunkelGewuenscht = window.matchMedia
            && window.matchMedia('(prefers-color-scheme: dark)').matches;
        wahl = dunkelGewuenscht ? $dunkel : $vorgabe;
    }

    document.documentElement.setAttribute('data-theme', wahl);
})();
</script>
HTML;
    }

    /**
     * Ist der Wert ein bekanntes Profil?
     *
     * Diese Pruefung ist der Grund, warum die Spalte in der Datenbank ein
     * varchar sein darf: Was hineinkommt, entscheidet der Code, nicht das
     * Schema.
     *
     * @param mixed $wert
     * @return bool
     */
    public static function isValid($wert): bool
    {
        return is_string($wert) && isset(self::PROFILE[$wert]);
    }

    /**
     * Macht aus einem beliebigen gespeicherten Wert ein gueltiges Profil.
     *
     * Faengt NULL (nie gewaehlt), einen Leerstring und den Fall ab, dass ein
     * Profil spaeter entfaellt, dessen Name aber noch in einem Konto steht.
     *
     * @param mixed $wert
     * @return string Schluessel aus self::PROFILE
     */
    public static function normalize($wert): string
    {
        return self::isValid($wert) ? $wert : self::DEFAULT;
    }
}
