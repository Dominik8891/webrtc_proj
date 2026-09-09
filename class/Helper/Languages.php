<?php
namespace App\Helper;

/**
 * Die Sprachen, in denen ein Guide fuehren kann.
 *
 * WARUM EINE LISTE UND KEIN FREITEXT
 * ----------------------------------
 * "Deutsch, Englisch", "DE/EN", "german and english", "🇩🇪🇬🇧" - dasselbe
 * Angebot in vier Schreibweisen. Solange nur ein Mensch es liest, geht das
 * gut. Sobald jemand danach filtern will ("zeig mir Guides, die Spanisch
 * sprechen"), ist Freitext nicht mehr auswertbar, und die Umstellung
 * nachtraeglich heisst: alle Bestandsdaten von Hand sortieren.
 *
 * Gespeichert wird deshalb eine Liste von Kuerzeln nach ISO 639-1, getrennt
 * durch Komma, in location.languages: "de,en,es". Angezeigt wird der Name
 * aus dieser Tabelle. Die Datenbank kennt damit die Sprache, der Nutzer
 * sieht sie ausgeschrieben, und ein Filter waere spaeter ein WHERE mit
 * FIND_IN_SET - ohne dass eine Zeile umgeschrieben werden muesste.
 *
 * WARUM KEINE EIGENE TABELLE
 * --------------------------
 * Sie haette Sinn, wenn Sprachen verwaltet wuerden - angelegt, umbenannt,
 * gelaescht. Werden sie nicht: Die Liste der Weltsprachen aendert sich nicht,
 * und eine Sprache aufzunehmen ist eine Codeaenderung von einer Zeile. Eine
 * Tabelle mit zwei Spalten und einem JOIN je Standortabfrage waere hier nur
 * Aufwand ohne Gegenwert. Wird die Liste doch einmal verwaltet, ist DIESE
 * Klasse die eine Stelle, an der der Zugriff darauf steht.
 *
 * DIE REIHENFOLGE ist die der Auswahl im Formular und damit eine Aussage:
 * vorne, was in dieser Anwendung am haeufigsten vorkommt.
 */
class Languages
{
    /**
     * Kuerzel (ISO 639-1) => Name in der Sprache selbst.
     *
     * In der Sprache selbst und nicht auf Deutsch: Wer nach einer Fuehrung
     * auf Portugiesisch sucht, sucht nach "Portugues" - er liest die Seite
     * ja gerade, weil er die Sprache kann.
     *
     * UND IN DER SCHRIFT DIESER SPRACHE. Hier stand einmal "Russkij",
     * "Zhongwen", "Nihongo", "Arabiy" - Umschriften in lateinische
     * Buchstaben. Sie verfehlen genau den Zweck, den der Absatz darueber
     * nennt: Wer Russisch spricht, sucht nach "Russisch", und das schreibt
     * sich Русский. "Russkij" liest ausser einem Deutschen mit
     * Slawistikkenntnissen niemand - es ist ein deutscher Behelf und damit
     * dasselbe wie ein deutscher Name, nur schwerer zu erkennen. Dasselbe
     * bei "Turkce" und "Francais", denen nur die Zeichen fehlten.
     *
     * Die Datenbank ist utf8mb4 und die Seiten sind UTF-8; an der Schrift
     * scheitert nichts. GESPEICHERT wird ohnehin nur das Kuerzel
     * (location.languages), nie der Name - diese Umstellung beruehrt keinen
     * einzigen Datensatz.
     */
    private const NAMES = [
        'de' => 'Deutsch',
        'en' => 'English',
        'fr' => 'Français',
        'es' => 'Español',
        'it' => 'Italiano',
        'pt' => 'Português',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'tr' => 'Türkçe',
        'ru' => 'Русский',
        'ar' => 'العربية',
        'zh' => '中文',
        'ja' => '日本語',
    ];

    /**
     * Alle waehlbaren Sprachen.
     *
     * @return array<string,string> Kuerzel => Name
     */
    public static function all(): array
    {
        return self::NAMES;
    }

    /**
     * Ist das ein bekanntes Kuerzel?
     *
     * @param mixed $in_code
     * @return bool
     */
    public static function isKnown($in_code): bool
    {
        return is_string($in_code) && isset(self::NAMES[$in_code]);
    }

    /**
     * Name zu einem Kuerzel.
     *
     * @param string $in_code
     * @return string Das Kuerzel selbst, wenn es unbekannt ist - dann steht
     *                dort wenigstens etwas und nicht nichts
     */
    public static function name($in_code): string
    {
        $code = is_string($in_code) ? $in_code : '';
        return self::NAMES[$code] ?? $code;
    }

    /**
     * Macht aus einer Eingabe die Zeichenkette, die gespeichert wird.
     *
     * DIE EINE STELLE, an der entschieden wird, was in location.languages
     * landen darf. Angenommen wird beides, was aus einem Formular kommen
     * kann: ein Array (mehrfache Auswahl, `languages[]`) und eine bereits
     * zusammengesetzte Zeichenkette.
     *
     * Unbekannte Kuerzel fallen weg - nicht die ganze Eingabe. Wer ein
     * Formular nachbaut und "de,xx" schickt, bekommt "de" gespeichert und
     * keinen Fehler; der Standort ist deshalb nicht unbrauchbar.
     *
     * Doppelungen fallen weg, und die Reihenfolge ist die dieser Klasse und
     * nicht die der Eingabe: Sonst stuende bei einem Guide "en,de" und beim
     * naechsten "de,en", und die Anzeige waere ohne Not uneinheitlich.
     *
     * @param mixed $in_werte Array oder kommagetrennte Zeichenkette
     * @return string Leerstring, wenn nichts Gueltiges dabei war
     */
    public static function normalize($in_werte): string
    {
        if (is_string($in_werte)) {
            $in_werte = explode(',', $in_werte);
        }
        if (!is_array($in_werte)) return '';

        $gewaehlt = [];
        foreach ($in_werte as $wert) {
            if (!is_scalar($wert)) continue;
            $code = strtolower(trim((string)$wert));
            if (self::isKnown($code)) $gewaehlt[$code] = true;
        }

        $sortiert = [];
        foreach (array_keys(self::NAMES) as $code) {
            if (isset($gewaehlt[$code])) $sortiert[] = $code;
        }
        return implode(',', $sortiert);
    }

    /**
     * Zerlegt den gespeicherten Wert in Kuerzel.
     *
     * @param mixed $in_gespeichert
     * @return string[] nur bekannte Kuerzel
     */
    public static function codes($in_gespeichert): array
    {
        $normalisiert = self::normalize($in_gespeichert);
        return $normalisiert === '' ? [] : explode(',', $normalisiert);
    }

    /**
     * Die ausgeschriebenen Namen zu einem gespeicherten Wert.
     *
     * @param mixed $in_gespeichert
     * @return string[]
     */
    public static function names($in_gespeichert): array
    {
        return array_map([self::class, 'name'], self::codes($in_gespeichert));
    }

    /**
     * Dieselben Namen, aber fuer eine Aufzaehlung in laufendem Text.
     *
     * WARUM ES DAS BRAUCHT
     * --------------------
     * "Deutsch, English, العربية, 中文" - der arabische Name laeuft von
     * rechts nach links, die uebrigen von links nach rechts. Komma und
     * Leerzeichen dazwischen haben KEINE eigene Richtung; der Browser
     * schlaegt sie derjenigen Seite zu, die er fuer passend haelt. Ergebnis:
     * Das Komma nach dem arabischen Namen springt auf dessen andere Seite,
     * und die Aufzaehlung sieht aus, als fehle ein Trennzeichen und stuende
     * eines zu viel woanders.
     *
     * U+2068 (First Strong Isolate) und U+2069 (Pop Directional Isolate)
     * klammern den Namen als eigenen Abschnitt ein. Der Browser bestimmt
     * dessen Richtung aus dem ersten starken Zeichen und laesst den Text
     * DRUMHERUM davon unberuehrt - genau dafuer sind die beiden Zeichen da.
     *
     * NUR UM DIE RECHTSLAEUFIGEN NAMEN. "Deutsch, English" bleibt Zeichen
     * fuer Zeichen dasselbe wie vorher: Wo es nichts zu verwechseln gibt,
     * gehoeren auch keine unsichtbaren Zeichen in die Seite.
     *
     * NICHT FUER DIE AUSWAHLKAESTCHEN. Dort steht jeder Name allein in einem
     * eigenen Element, und neben ihm steht kein Komma - da gibt es nichts
     * zu isolieren.
     *
     * @param mixed $in_gespeichert
     * @return string[]
     */
    public static function namesInline($in_gespeichert): array
    {
        return array_map(static function (string $name): string {
            return self::istRechtslaeufig($name)
                ? "\u{2068}" . $name . "\u{2069}"
                : $name;
        }, self::names($in_gespeichert));
    }

    /**
     * Faengt der Name mit einer rechtslaeufigen Schrift an?
     *
     * Geprueft werden die Bloecke, aus denen die Namen dieser Liste kommen
     * koennen: Hebraeisch (U+0590-U+05FF) sowie Arabisch samt Erweiterungen
     * (U+0600-U+06FF, U+0750-U+077F, U+08A0-U+08FF). Heute trifft das nur
     * auf 'ar' zu - kaeme Hebraeisch, Persisch oder Urdu dazu, gilt es ohne
     * weitere Aenderung auch fuer sie.
     *
     * @param string $in_name
     * @return bool
     */
    private static function istRechtslaeufig(string $in_name): bool
    {
        return preg_match('/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}'
            . '\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $in_name) === 1;
    }
}
