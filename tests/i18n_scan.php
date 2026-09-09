<?php
/**
 * Sucht nackte deutsche Literale in Code und Vorlagen.
 *
 * WOZU DAS DER WICHTIGSTE POSTEN DES FUNDAMENTS IST
 * -------------------------------------------------
 * Ein Sprachkatalog ist schnell gebaut. Was ihn kaputt macht, ist der ganz
 * gewoehnliche Alltag: Jemand ergaenzt einen Knopf, schreibt "Speichern"
 * hinein, und niemandem faellt es auf - der Test war ja gruen. Nach einem
 * halben Jahr sind es zweihundert solcher Stellen, und die Anwendung ist
 * wieder halb deutsch. Der Umzug faengt dann von vorne an.
 *
 * Deshalb gibt es hier eine RATSCHE: Was heute deutsch ist, steht in
 * tests/i18n_grundstock.txt und darf bleiben. Was NEU dazukommt, laesst
 * tests/server_test.php fehlschlagen. Der Bestand wird dadurch nicht besser,
 * aber er wird nicht groesser - und das ist der Unterschied zwischen einem
 * Umzug, der irgendwann fertig ist, und einem, der es nie wird.
 *
 * WAS EINE ENTFALLENE ZEILE BEDEUTET
 * ----------------------------------
 * Nichts Schlimmes. Wer einen Text in den Katalog holt, verschwindet aus
 * dieser Liste - das ist der Fortschritt, um den es geht. Der Test MELDET
 * solche Zeilen am Ende, laesst aber nichts scheitern: Sonst muesste jede
 * Uebersetzung zugleich diese Datei anfassen, und der Grundstock waere ein
 * Hindernis statt einer Bremse.
 *
 * DEN GRUNDSTOCK NEU SCHREIBEN
 * ----------------------------
 *     php tests/i18n_scan.php --schreiben
 *
 * Das ist ausdruecklich erlaubt, wenn Texte umgezogen sind - und ausdruecklich
 * NICHT, um einen neuen deutschen Satz durchzulassen. Wer das tut, sieht es
 * im Diff: Dort steht dann eine Zeile MEHR.
 *
 * WAS DURCHSUCHT WIRD
 * -------------------
 *   class/**\/*.php    Serverseitiger Code
 *   assets/js/*.js     Browserseitiger Code
 *   assets/html/*.html Vorlagen
 *
 * NICHT durchsucht wird lang/ - dort GEHOEREN deutsche Saetze hin, das ist
 * der ganze Zweck des Verzeichnisses. Ebenso wenig tests/ und die
 * Markdown-Dateien: Sie richten sich an den Entwickler, nicht an den Nutzer.
 *
 * WAS ALS TEXT ZAEHLT
 * -------------------
 * KOMMENTARE NICHT. Diese Anwendung ist auf Deutsch kommentiert, und daran
 * aendert sich nichts. Deshalb wird PHP ueber token_get_all() zerlegt und
 * nicht mit einer Regex ueberflogen: Ein Tokenizer weiss, was ein Kommentar
 * ist, und eine Regex weiss es nicht.
 *
 * LOGMELDUNGEN AUCH NICHT - also alles, was in error_log(), trigger_error()
 * oder console.warn() steht. Das ist derselbe Gedanke wie beim Kommentar und
 * nicht etwa eine Bequemlichkeit: Ein Logeintrag richtet sich an den
 * Betreiber dieser Installation, nicht an ihren Benutzer. Er gehoert in
 * keinen Sprachkatalog, denn er wird nie uebersetzt - und stuende er hier
 * mit drin, waere die Ratsche gegen die eigene Hausordnung gerichtet: Jede
 * neue deutsche Fehlermeldung im Log liesse den Test scheitern, der
 * Grundstock wuerde routinemaessig neu geschrieben, und genau damit waere er
 * keine Bremse mehr.
 *
 * Erkannt wird ein Literal als deutsch, wenn eines von beidem zutrifft:
 *
 *   1. Es enthaelt einen Umlaut oder ein Eszett. Das ist der sichere Fall -
 *      ein Klassenname, ein Pfad oder ein CSS-Selektor hat keine.
 *   2. Es enthaelt ein Wort aus einer der beiden Listen unten. Fuer die
 *      Funktionswoerter (der, die, und, nicht) genuegt das Wort; fuer die
 *      Oberflaechenwoerter (Speichern, Bereit, Anmelden) ebenso, weil sie
 *      allein schon ein Knopf sein koennen.
 *
 * Vorher fliegt heraus, was offensichtlich Technik ist: Adressen, Pfade,
 * Selektoren, Bezeichner. Der Filter ist bewusst grob - er soll Laerm
 * daempfen und nicht Treffer verhindern. Was er faelschlich meldet, steht
 * einmal im Grundstock und stoert danach niemanden mehr.
 */

/**
 * Die Verzeichnisse, in denen gesucht wird - relativ zur Wurzel.
 *
 * @return array<int,string> Absolute Dateipfade, sortiert
 */
function i18n_dateien(string $in_wurzel): array
{
    $dateien = array_merge(
        glob($in_wurzel . '/class/*/*.php') ?: [],
        glob($in_wurzel . '/class/*.php') ?: [],
        glob($in_wurzel . '/assets/js/*.js') ?: [],
        glob($in_wurzel . '/assets/html/*.html') ?: []
    );
    sort($dateien);
    return $dateien;
}

/**
 * Deutsche Funktionswoerter.
 *
 * Sie kommen in keinem Bezeichner und in keiner Adresse vor, und sie stehen
 * in fast jedem deutschen Satz. Ein Treffer heisst deshalb sehr zuverlaessig
 * "hier steht ein Satz".
 */
const I18N_FUNKTIONSWOERTER = [
    'aber', 'alle', 'allen', 'als', 'auch', 'auf', 'aus', 'bei', 'beim',
    'bereits', 'bis', 'damit', 'dann', 'das', 'dass', 'dein', 'dem', 'den',
    'denn', 'der', 'des', 'diese', 'diesem', 'diesen', 'dieser', 'dieses',
    'doch', 'dort', 'durch', 'ein', 'eine', 'einem', 'einen', 'einer',
    'eines', 'erst', 'etwas', 'gegen', 'gibt', 'hat', 'haben', 'hier', 'ihm',
    'ihn', 'ihnen', 'ihr', 'ihre', 'ihrem', 'ihren', 'ihrer', 'immer', 'ist',
    'jede', 'jeden', 'jeder', 'jetzt', 'kann', 'keine', 'keinen', 'keiner',
    'mehr', 'mit', 'muss', 'nach', 'nicht', 'nichts', 'noch', 'nur', 'oder',
    'ohne', 'schon', 'sein', 'seine', 'seit', 'sich', 'sind', 'soll',
    'sollen', 'sonst', 'und', 'unter', 'vom', 'von', 'vor', 'war', 'waren',
    'weil', 'wenn', 'werden', 'wieder', 'wird', 'wurde', 'wurden', 'zum',
    'zur', 'zwei',
];

/**
 * Deutsche Woerter der Oberflaeche.
 *
 * Anders als die Liste darueber stehen diese oft ALLEIN - auf einem Knopf,
 * in einer Spaltenueberschrift, als Statuswort. Genau die Faelle also, die
 * ein Test uebersaehe, der einen ganzen Satz verlangt.
 *
 * Woerter mit Umlaut fehlen hier bewusst: Sie werden schon ueber Regel 1
 * gefunden, und eine zweite Fundstelle macht die Liste nur laenger.
 */
const I18N_OBERFLAECHENWOERTER = [
    'abbrechen', 'abmelden', 'absenden', 'anfrage', 'anfragen', 'angaben',
    'anmelden', 'anmeldung', 'antwort', 'antworten', 'anzeigen', 'bearbeiten',
    'benutzer', 'benutzername', 'bereit', 'bestaetigen', 'beschreibung',
    'bewertung', 'bewertungen', 'dauer', 'einstellungen', 'erfolgreich',
    'fehler', 'fehlgeschlagen', 'freigeben', 'gespeichert', 'hinweis',
    'hochladen', 'konto', 'nachricht', 'nachrichten', 'passwort', 'sperren',
    'sprache', 'standort', 'standorte', 'startseite', 'suchen', 'speichern',
    'verwaltung', 'weiter', 'zurueck',
];

/**
 * Aufrufe, deren Argumente uebergangen werden.
 *
 * Was hier hineingeschrieben wird, liest ein Betreiber im Log und kein
 * Benutzer auf einer Seite - siehe Kopfkommentar. Die Argumente werden
 * vollstaendig uebersprungen, samt der Verkettungen darin.
 */
const I18N_LOGAUFRUFE_PHP = ['error_log', 'trigger_error'];
const I18N_LOGAUFRUFE_JS  = ['console.log', 'console.warn', 'console.error',
                             'console.info', 'console.debug'];

/**
 * Sieht dieses Literal nach Technik aus?
 *
 * Adressen, Pfade, Selektoren, Bezeichner, Formatzeichenketten. Sie enthalten
 * gelegentlich etwas, das wie ein deutsches Wort aussieht ("der" in
 * "index.php?act=order"), und waeren dann Laerm im Grundstock.
 *
 * @param string $in_text
 * @return bool
 */
function i18n_istTechnik(string $in_text): bool
{
    $text = trim($in_text);

    // Zu kurz, um ein Satz zu sein.
    if (mb_strlen($text) < 3) return true;

    // Kein einziger Buchstabe: Zahlen, Zeichen, Formatangaben.
    if (!preg_match('/\p{L}{2,}/u', $text)) return true;

    // Adressen und Dateipfade.
    if (preg_match('#^(https?:)?//#i', $text)) return true;
    if (preg_match('#^[\w./-]+\.(php|js|css|html|png|jpe?g|svg|mp3|sql|txt|md)$#i', $text)) return true;

    // Ein einzelnes Wort ohne Leerzeichen, das wie ein Bezeichner aussieht:
    // enthaelt Unterstrich, Schraegstrich, Punkt, Raute oder Doppelpunkt.
    // "app-panel__body", "user.settings", "###USER###", "de,en".
    if (strpos($text, ' ') === false && preg_match('/[_.:#\/\\\\]/', $text)) return true;

    // SQL. Es steht in dieser Anwendung in mehrzeiligen Zeichenketten, und
    // die tragen ihre Erklaerung als "--"-Kommentar IN der Zeichenkette -
    // fuer den Tokenizer ist das Text wie jeder andere. Ein Spaltenname wie
    // "beschreibung" traefe ausserdem die Liste oben.
    //
    // GEPRUEFT WIRD AUF GROSSSCHREIBUNG, und zwar absichtlich: In diesem
    // Projekt stehen SQL-Schluesselwoerter durchgehend in Grossbuchstaben,
    // waehrend ein deutscher Satz "from" oder "where" allenfalls klein und
    // ohnehin nie enthaelt. Damit faengt die Regel das SQL und laesst die
    // Saetze in Ruhe.
    if (preg_match('/\b(SELECT|INSERT INTO|UPDATE|DELETE|ALTER|CREATE|FROM|WHERE|JOIN|GROUP BY|ORDER BY|VALUES)\b/', $text)) {
        return true;
    }

    return false;
}

/**
 * Ist das ein deutscher Text?
 *
 * @param string $in_text
 * @return bool
 */
function i18n_istDeutsch(string $in_text): bool
{
    if (i18n_istTechnik($in_text)) return false;

    // Regel 1: Umlaut oder Eszett. Der sichere Fall.
    if (preg_match('/[äöüÄÖÜß]/u', $in_text)) return true;

    // Regel 2: ein Wort aus einer der beiden Listen.
    $woerter = preg_split('/[^\p{L}]+/u', mb_strtolower($in_text), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($woerter)) return false;

    foreach ($woerter as $wort) {
        if (in_array($wort, I18N_FUNKTIONSWOERTER, true))    return true;
        if (in_array($wort, I18N_OBERFLAECHENWOERTER, true)) return true;
    }

    return false;
}

/**
 * Die Zeichenketten-Literale einer PHP-Datei - ohne Kommentare.
 *
 * ueber token_get_all() und nicht mit einer Regex. Der Grund steht im
 * Kopfkommentar: Diese Anwendung ist deutsch kommentiert, und ein Kommentar
 * ist kein Text der Oberflaeche.
 *
 * @param string $in_quelle
 * @return array<int,string>
 */
function i18n_literalePhp(string $in_quelle): array
{
    $out    = [];
    $tokens = token_get_all($in_quelle);

    // Wie tief liegen wir in einer Klammer, und ab welcher Tiefe wird
    // uebersprungen? -1 heisst: nichts wird uebersprungen.
    $tiefe     = 0;
    $abTiefe   = -1;

    foreach ($tokens as $stelle => $token) {
        // Klammern sind einzelne Zeichen und keine Arrays.
        if (!is_array($token)) {
            if ($token === '(') $tiefe++;
            if ($token === ')') {
                $tiefe--;
                if ($abTiefe >= 0 && $tiefe < $abTiefe) $abTiefe = -1;
            }
            continue;
        }

        // Der Beginn eines Logaufrufs: ein bekannter Name, gefolgt von "(".
        if ($abTiefe < 0 && $token[0] === T_STRING
            && in_array($token[1], I18N_LOGAUFRUFE_PHP, true)
            && i18n_naechstesZeichen($tokens, $stelle) === '(') {
            // Ab der Klammer, die gleich kommt: alles darin uebergehen.
            $abTiefe = $tiefe + 1;
            continue;
        }

        if ($abTiefe >= 0) continue;

        // T_CONSTANT_ENCAPSED_STRING: 'text' und "text"
        // T_ENCAPSED_AND_WHITESPACE: die festen Teile in "Hallo $name"
        //                            und in Heredocs
        // T_INLINE_HTML: alles ausserhalb von <?php
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            // Anfuehrungszeichen weg, Maskierungen aufloesen. VON HAND und
            // nicht mit eval(): Ein Testwerkzeug, das den Quelltext des
            // Projekts ausfuehrt, um ihn zu lesen, ist ein Werkzeug zu viel.
            $roh   = $token[1];
            $art   = $roh[0];
            $inhalt = substr($roh, 1, -1);

            $out[] = ($art === "'")
                ? str_replace(['\\\\', "\\'"], ['\\', "'"], $inhalt)
                : stripcslashes($inhalt);
            continue;
        }
        if ($token[0] === T_ENCAPSED_AND_WHITESPACE || $token[0] === T_INLINE_HTML) {
            $out[] = $token[1];
        }
    }
    return $out;
}

/**
 * Das naechste bedeutsame Zeichen hinter einer Tokenstelle.
 *
 * Uebergangen werden Leerraum und Kommentare - zwischen "error_log" und
 * seiner Klammer darf beides stehen.
 *
 * @param array<int,mixed> $in_tokens
 * @param int              $in_stelle
 * @return string Ein Zeichen, oder Leerstring am Ende
 */
function i18n_naechstesZeichen(array $in_tokens, int $in_stelle): string
{
    $anzahl = count($in_tokens);
    for ($i = $in_stelle + 1; $i < $anzahl; $i++) {
        $t = $in_tokens[$i];
        if (is_array($t)) {
            if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) continue;
            return '';
        }
        return $t;
    }
    return '';
}

/**
 * Die Zeichenketten-Literale einer JavaScript-Datei - ohne Kommentare.
 *
 * Es gibt hier keinen Tokenizer, also wird von Hand gelesen: Zeichen fuer
 * Zeichen, mit einem Zustand fuer "in einer Zeichenkette" und einem fuer "in
 * einem Kommentar". Eine Regex kaeme mit dem Fall nicht zurecht, um den es
 * geht - ein "//" INNERHALB einer Zeichenkette (in jeder Adresse) ist kein
 * Kommentarbeginn, und ein Anfuehrungszeichen in einem Kommentar ist kein
 * Zeichenkettenbeginn.
 *
 * REGULAERE AUSDRUECKE werden nicht erkannt und koennen deshalb als
 * Zeichenkette missgedeutet werden. Das ist hinnehmbar: Es fuehrt zu einer
 * Zeile mehr im Grundstock und nie dazu, dass ein echter Text durchrutscht.
 *
 * @param string $in_quelle
 * @return array<int,string>
 */
function i18n_literaleJs(string $in_quelle): array
{
    $out    = [];
    $laenge = strlen($in_quelle);
    $i      = 0;

    // Fuer die Logaufrufe: die aktuelle Klammertiefe, ab welcher Tiefe
    // uebersprungen wird (-1: gar nicht) und der zuletzt gelesene Bezeichner
    // samt Punkten - daraus wird "console.warn" erkannt.
    $tiefe    = 0;
    $abTiefe  = -1;
    $bezeichner = '';

    while ($i < $laenge) {
        $z = $in_quelle[$i];

        // Kommentare ueberspringen.
        if ($z === '/' && $i + 1 < $laenge) {
            if ($in_quelle[$i + 1] === '/') {
                $ende = strpos($in_quelle, "\n", $i);
                $i = ($ende === false) ? $laenge : $ende + 1;
                continue;
            }
            if ($in_quelle[$i + 1] === '*') {
                $ende = strpos($in_quelle, '*/', $i + 2);
                $i = ($ende === false) ? $laenge : $ende + 2;
                continue;
            }
        }

        // Den laufenden Bezeichner mitschreiben: Buchstaben, Ziffern,
        // Unterstrich und Punkt gehoeren dazu, alles andere beendet ihn.
        if (ctype_alnum($z) || $z === '_' || $z === '$' || $z === '.') {
            $bezeichner .= $z;
            $i++;
            continue;
        }

        // Klammern zaehlen - und beim Oeffnen pruefen, ob davor ein
        // Logaufruf stand.
        if ($z === '(') {
            if ($abTiefe < 0 && in_array($bezeichner, I18N_LOGAUFRUFE_JS, true)) {
                $abTiefe = $tiefe + 1;
            }
            $tiefe++;
            $bezeichner = '';
            $i++;
            continue;
        }
        if ($z === ')') {
            $tiefe--;
            if ($abTiefe >= 0 && $tiefe < $abTiefe) $abTiefe = -1;
            $bezeichner = '';
            $i++;
            continue;
        }

        // Leerraum beendet den Bezeichner nicht sofort: "console.warn (x)"
        // ist derselbe Aufruf. Jedes andere Zeichen schon.
        if (!ctype_space($z)) $bezeichner = '';

        // Zeichenketten einsammeln.
        if ($z === '"' || $z === "'" || $z === '`') {
            $ende = $z;
            $text = '';
            $i++;
            while ($i < $laenge) {
                $c = $in_quelle[$i];
                if ($c === '\\') { $text .= $in_quelle[$i + 1] ?? ''; $i += 2; continue; }
                if ($c === $ende) { $i++; break; }
                // Ein Zeilenumbruch in ' oder " heisst: Das war keine
                // Zeichenkette (oder die Datei ist kaputt). Abbrechen, statt
                // den halben Rest einzusammeln.
                if ($c === "\n" && $ende !== '`') { break; }
                $text .= $c;
                $i++;
            }
            // In einem Logaufruf: gelesen, aber nicht gemeldet.
            if ($abTiefe < 0) $out[] = $text;
            $bezeichner = '';
            continue;
        }

        $i++;
    }

    return $out;
}

/**
 * Die sichtbaren Texte einer HTML-Vorlage - ohne Kommentare.
 *
 * Gesammelt wird beides:
 *   * der Text zwischen den Elementen,
 *   * die Attribute, die ein Nutzer zu sehen bekommt (title, placeholder,
 *     alt, aria-label, value eines Knopfes).
 *
 * Die Kopfkommentare der Vorlagen fallen weg - genau wie in
 * App\Helper\ViewHelper::template(), und aus demselben Grund: Dort steht die
 * Beschreibung der Vorlage, und die ist fuer den Entwickler.
 *
 * @param string $in_quelle
 * @return array<int,string>
 */
function i18n_texteHtml(string $in_quelle): array
{
    $ohneKommentar = preg_replace('/<!--.*?-->/s', ' ', $in_quelle);

    // Skript- und Stilbloecke sind kein sichtbarer Text.
    $ohneSkript = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', (string)$ohneKommentar);

    $out = [];

    // Die Attribute, bevor die Elemente wegfallen.
    if (preg_match_all(
        '/\b(title|placeholder|alt|aria-label|value)\s*=\s*"([^"]*)"/i',
        (string)$ohneSkript,
        $treffer
    )) {
        foreach ($treffer[2] as $wert) $out[] = $wert;
    }

    // Und der Text dazwischen, Stueck fuer Stueck.
    foreach (preg_split('/<[^>]*>/', (string)$ohneSkript) as $stueck) {
        $text = trim(html_entity_decode($stueck, ENT_QUOTES, 'UTF-8'));
        if ($text !== '') $out[] = $text;
    }

    return $out;
}

/**
 * Der vollstaendige Befund: je Datei die gefundenen deutschen Texte.
 *
 * Die Rueckgabe ist eine sortierte Liste von Zeilen "pfad\ttext" - dieselbe
 * Form, in der der Grundstock auf der Platte liegt. Eine Zeile je Fund, und
 * doppelte Funde derselben Zeichenkette in derselben Datei zaehlen einmal:
 * Es geht um die Stelle, nicht um die Haeufigkeit.
 *
 * @param string $in_wurzel Projektwurzel
 * @return array<int,string>
 */
function i18n_befund(string $in_wurzel): array
{
    $zeilen = [];

    foreach (i18n_dateien($in_wurzel) as $datei) {
        $quelle  = (string)file_get_contents($datei);
        $relativ = ltrim(str_replace($in_wurzel, '', $datei), '/');

        if (substr($datei, -4) === '.php')       $texte = i18n_literalePhp($quelle);
        elseif (substr($datei, -3) === '.js')    $texte = i18n_literaleJs($quelle);
        else                                     $texte = i18n_texteHtml($quelle);

        foreach ($texte as $text) {
            // Mehrzeiliges auf eine Zeile bringen: Der Grundstock ist eine
            // Datei mit einer Zeile je Fund, und ein Umbruch darin waere ein
            // zweiter Eintrag.
            $eine = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
            if ($eine === '' || !i18n_istDeutsch($eine)) continue;

            $zeilen[$relativ . "\t" . $eine] = true;
        }
    }

    $out = array_keys($zeilen);
    sort($out);
    return $out;
}

/** Der Pfad des Grundstocks. */
function i18n_grundstockPfad(string $in_wurzel): string
{
    return $in_wurzel . '/tests/i18n_grundstock.txt';
}

/**
 * Der Grundstock als Liste von Zeilen.
 *
 * @param string $in_wurzel
 * @return array<int,string>
 */
function i18n_grundstock(string $in_wurzel): array
{
    $pfad = i18n_grundstockPfad($in_wurzel);
    if (!is_file($pfad)) return [];

    $zeilen = [];
    foreach (file($pfad, FILE_IGNORE_NEW_LINES) as $zeile) {
        // Leerzeilen und Kommentarzeilen der Datei selbst.
        if ($zeile === '' || $zeile[0] === '#') continue;
        $zeilen[] = $zeile;
    }
    sort($zeilen);
    return $zeilen;
}

// ---------------------------------------------------------------------------
// AUFRUF VON DER KOMMANDOZEILE: den Grundstock neu schreiben.
//
// Nur dann - eingebunden aus tests/server_test.php passiert hier nichts.
// ---------------------------------------------------------------------------
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $wurzel = dirname(__DIR__);
    $befund = i18n_befund($wurzel);

    if (in_array('--schreiben', $argv, true)) {
        $kopf = "# Der Grundstock: deutsche Literale, die es beim Bau des\n"
              . "# Sprachfundaments schon gab. Geschrieben von\n"
              . "#     php tests/i18n_scan.php --schreiben\n"
              . "#\n"
              . "# Eine Zeile je Fund, Format:  pfad<TAB>text\n"
              . "#\n"
              . "# NEUE Zeilen lassen tests/server_test.php fehlschlagen - das ist\n"
              . "# der Zweck dieser Datei. Wer einen Text in lang/ umzieht, darf sie\n"
              . "# neu schreiben; wer einen neuen deutschen Satz in den Code\n"
              . "# schreibt, sieht im Diff eine Zeile MEHR.\n";
        file_put_contents(i18n_grundstockPfad($wurzel), $kopf . implode("\n", $befund) . "\n");
        fwrite(STDERR, count($befund) . " Fundstellen in den Grundstock geschrieben.\n");
        exit(0);
    }

    $grundstock = i18n_grundstock($wurzel);
    $neu        = array_values(array_diff($befund, $grundstock));
    $entfallen  = array_values(array_diff($grundstock, $befund));

    fwrite(STDERR, count($befund) . ' Fundstellen, ' . count($neu) . ' neu, '
        . count($entfallen) . " entfallen.\n");
    foreach ($neu as $zeile) fwrite(STDERR, "  NEU  $zeile\n");
    exit($neu === [] ? 0 : 1);
}
