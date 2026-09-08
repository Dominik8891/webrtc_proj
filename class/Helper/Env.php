<?php
namespace App\Helper;

/**
 * Die eine Stelle, an der ein Konfigurationswert aus der Umgebung kommt.
 *
 * WOZU DIESE KLASSE
 * -----------------
 * Ein Schalter in der .env hat drei Fehlerquellen, und alle drei sind
 * langweilig: Der Schluessel fehlt, er steht in einer anderen Schreibweise da
 * ("on" statt "1"), oder er enthaelt einen Tippfehler. Bevor es diese Klasse
 * gab, beantwortete jede Stelle diese drei Fragen selbst - App\Helper\MailGate
 * mit einer privaten Methode, config/log_path.php und config/uploads.php mit
 * je einer eigenen foreach-Schleife ueber dieselben drei Quellen.
 *
 * Jetzt steht die Antwort einmal hier, und ein neuer Schalter ist eine Zeile.
 *
 * WORAUS GELESEN WIRD, IN DIESER REIHENFOLGE
 * ------------------------------------------
 *   1. $_SERVER  - vom Webserver gesetzt (Apache SetEnv, nginx fastcgi_param)
 *   2. $_ENV     - von phpdotenv aus der .env eingelesen (config/env.php)
 *   3. getenv()  - das echte Prozess-Environment (Docker, systemd)
 *
 * Die Reihenfolge ist dieselbe wie in config/uploads.php und damit die
 * gewohnte: Was der Betreiber am Server einstellt, gewinnt gegen die Datei im
 * Verzeichnis. Wer eine einzelne Installation abweichend fahren will, muss
 * dafuer nicht die ausgelieferte .env aendern.
 *
 * ES GIBT KEINEN "HALB GESETZTEN" SCHALTER
 * ----------------------------------------
 * Angenommen werden die ueblichen Schreibweisen fuer beide Zustaende; alles
 * andere - ein Tippfehler, ein Kommentar hinter dem Gleichheitszeichen -
 * faellt auf die Vorgabe zurueck UND WIRD PROTOKOLLIERT. Stillschweigend
 * "false" daraus zu machen waere die schlechtere Antwort: Bei FORCE_HTTPS
 * hiesse das, dass ein Vertipper die Weiterleitung abstellt, ohne dass es
 * jemandem auffaellt.
 */
class Env
{
    /** Was als "an" gilt. */
    private const WAHR = ['1', 'true', 'on', 'yes', 'ja'];

    /** Was als "aus" gilt. */
    private const FALSCH = ['0', 'false', 'off', 'no', 'nein'];

    /**
     * Liest einen Schalter (an/aus) aus der Umgebung.
     *
     * @param  string $in_name    Name des Schluessels in der .env
     * @param  bool   $in_vorgabe Wert, wenn nichts Brauchbares dasteht
     * @return bool
     */
    public static function schalter(string $in_name, bool $in_vorgabe): bool
    {
        $roh = self::roh($in_name);

        // Nicht gesetzt ist kein Fehler - das ist der dokumentierte Normalfall
        // einer .env, die den Schluessel nicht kennt.
        if ($roh === null) return $in_vorgabe;

        $wert = strtolower(trim($roh));

        if (in_array($wert, self::WAHR,   true)) return true;
        if (in_array($wert, self::FALSCH, true)) return false;

        error_log("Konfiguration: $in_name hat den unbrauchbaren Wert '$roh' - "
            . 'es gilt die Vorgabe ' . ($in_vorgabe ? 'ein' : 'aus') . '.');
        return $in_vorgabe;
    }

    /**
     * Liest eine ganze Zahl aus der Umgebung.
     *
     * Gebraucht wird das bisher nur fuer HSTS_MAX_AGE, und dort ist die Null
     * ein eigener Zustand ("kein Header"). Deshalb ist eine negative Zahl ein
     * Fehler und keine stillschweigende Null: Wer -1 hinschreibt, meint etwas,
     * und was er meint, kann diese Methode nicht wissen.
     *
     * @param  string $in_name    Name des Schluessels
     * @param  int    $in_vorgabe Wert, wenn nichts Brauchbares dasteht
     * @return int
     */
    public static function zahl(string $in_name, int $in_vorgabe): int
    {
        $roh = self::roh($in_name);

        if ($roh === null) return $in_vorgabe;

        $wert = trim($roh);

        // ctype_digit statt is_numeric: "1e5", "0x10" und "-1" sind in einer
        // Konfigurationsdatei keine Zahlen, sondern Versehen. Leerraum um den
        // Wert ist dagegen keiner - der steht in jeder zweiten .env.
        if (ctype_digit($wert)) return (int)$wert;

        error_log("Konfiguration: $in_name ist keine Zahl ('$roh') - "
            . "es gilt die Vorgabe $in_vorgabe.");
        return $in_vorgabe;
    }

    /**
     * Liest eine Auswahl aus einer festen Liste erlaubter Werte.
     *
     * Fuer Schalter mit mehr als zwei Zustaenden - CSP_MODE hat drei. Ein
     * unbekannter Wert faellt auf die Vorgabe zurueck und wird protokolliert,
     * aus demselben Grund wie oben.
     *
     * @param  string   $in_name    Name des Schluessels
     * @param  string[] $in_erlaubt Erlaubte Werte, klein geschrieben
     * @param  string   $in_vorgabe Wert, wenn nichts Brauchbares dasteht
     * @return string
     */
    public static function auswahl(string $in_name, array $in_erlaubt, string $in_vorgabe): string
    {
        $roh = self::roh($in_name);

        if ($roh === null) return $in_vorgabe;

        $wert = strtolower(trim($roh));

        if (in_array($wert, $in_erlaubt, true)) return $wert;

        error_log("Konfiguration: $in_name kennt den Wert '$roh' nicht (erlaubt: "
            . implode(', ', $in_erlaubt) . ") - es gilt die Vorgabe $in_vorgabe.");
        return $in_vorgabe;
    }

    /**
     * Der Rohwert aus den drei Quellen, oder null.
     *
     * Ein leerer String zaehlt als "nicht gesetzt": In einer .env steht
     * "SCHLUESSEL=" fuer "hier steht nichts", nicht fuer den Leerstring.
     *
     * @param  string      $in_name
     * @return string|null
     */
    private static function roh(string $in_name): ?string
    {
        foreach ([$_SERVER[$in_name] ?? null, $_ENV[$in_name] ?? null, getenv($in_name)] as $kandidat) {
            if (is_string($kandidat) && trim($kandidat) !== '') return $kandidat;
        }
        return null;
    }
}
