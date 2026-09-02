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
 * WARUM KEIN "SYSTEM"-EINTRAG
 * ---------------------------
 * Ein automatisches Umschalten nach prefers-color-scheme waere ein fuenfter
 * Zustand, der sich nicht speichern laesst ("was war denn nun gewaehlt?").
 * Wer dunkel will, waehlt dunkel.
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
