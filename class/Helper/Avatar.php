<?php
namespace App\Helper;

/**
 * Das Bild eines Menschen in dieser Anwendung - oder das, was an seiner
 * Stelle steht.
 *
 * WOZU EINE EIGENE KLASSE FUER SO WENIG HTML
 * ------------------------------------------
 * Weil dieselbe Entscheidung an vier Stellen faellt: im Benutzermenue der
 * Kopfleiste, auf der Standortseite, auf der Profilseite und in deren
 * Kopfzeile. Die Entscheidung lautet jedes Mal "gibt es ein Bild, ja oder
 * nein", und jedes Mal muss beim Nein etwas Vernuenftiges dastehen. Vier
 * Fassungen davon liefen beim ersten Sonderfall auseinander - und der erste
 * Sonderfall ist schon da: Ein Guide, der kein Bild hochlaedt, ist der
 * Normalfall und nicht die Ausnahme.
 *
 * DIE INITIALEN SIND KEIN LUECKENFUELLER. Ein leerer Kreis sagt "hier fehlt
 * etwas", zwei Buchstaben in der Akzentfarbe sagen "das ist diese Person".
 * Der Unterschied kostet nichts und traegt die halbe Seite - im
 * Benutzermenue macht die Anwendung das laengst so, und genau von dort
 * stammt die Darstellung.
 *
 * WAS HIER NICHT PASSIERT
 * -----------------------
 * Es wird keine Datei angefasst und nichts geladen. Diese Klasse bekommt
 * einen Namen und - wenn es eines gibt - eine fertige Adresse; woher die
 * kommt und ob die Datei dahinter wirklich existiert, entscheidet der
 * Controller, der sie ausliefert.
 */
class Avatar
{
    /**
     * Die Initialen eines Namens.
     *
     * HOECHSTENS ZWEI BUCHSTABEN, und zwar die Anfangsbuchstaben der ersten
     * beiden Woerter: "Maria Silva" wird zu "MS", "maria" zu "M". Drei
     * Buchstaben passen in einem Kreis von 28 Punkten nicht mehr, und der
     * dritte sagt ohnehin nichts.
     *
     * mb_-Funktionen durchgehend: Ein Name faengt oefter mit "Ö" oder "Š" an,
     * als man denkt, und strtoupper macht daraus zwei kaputte Bytes.
     *
     * Bleibt nichts uebrig - ein Name aus Satzzeichen, ein leeres Feld -,
     * steht dort ein Fragezeichen. Das ist ehrlicher als ein leerer Kreis:
     * Es ist zu sehen, dass hier ein Mensch stehen sollte.
     *
     * @param mixed $in_name
     * @return string Ein bis zwei Zeichen, nie leer
     */
    public static function initials($in_name): string
    {
        $name = is_scalar($in_name) ? trim((string)$in_name) : '';
        if ($name === '') return '?';

        // Getrennt wird an allem, was kein Buchstabe und keine Ziffer ist -
        // Leerzeichen, Bindestrich, Punkt. "Anna-Lena" ergibt damit "AL",
        // und das ist richtig: Es ist ein Doppelname.
        $teile = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($teile) || $teile === []) return '?';

        $initialen = '';
        foreach (array_slice($teile, 0, 2) as $teil) {
            $initialen .= mb_substr($teil, 0, 1, 'UTF-8');
        }

        return mb_strtoupper($initialen, 'UTF-8');
    }

    /**
     * Der Avatar als HTML - Bild oder Initialen.
     *
     * ES IST IN BEIDEN FAELLEN EIN ELEMENT MIT DERSELBEN KLASSE. Damit
     * bestimmt das Stylesheet Groesse und Form (Kreis) an einer Stelle, und
     * der Aufrufer muss nicht wissen, welcher der beiden Faelle gerade
     * eintritt.
     *
     * ALT-TEXT IST LEER, wenn ein Bild da ist: Der Name steht unmittelbar
     * daneben, und ein Vorleseprogramm, das ihn zweimal sagt, hilft niemandem.
     * Die Initialen sind aus demselben Grund aria-hidden - sie sind eine
     * Abkuerzung des Namens, der danebensteht, und einzeln vorgelesen ergeben
     * sie nichts.
     *
     * @param mixed       $in_name    Name, aus dem die Initialen entstehen
     * @param string|null $in_bildUrl Fertige Adresse des Bildes, oder null
     * @param string      $in_klasse  Zusaetzliche Klasse des Aufrufers,
     *                                z. B. 'loc-guide__avatar'
     * @return string HTML
     */
    public static function html($in_name, ?string $in_bildUrl, string $in_klasse = ''): string
    {
        $klassen = 'app-avatar' . ($in_klasse === '' ? '' : ' ' . $in_klasse);

        if ($in_bildUrl !== null && $in_bildUrl !== '') {
            return '<img class="' . ViewHelper::esc($klassen) . ' app-avatar--img"'
                 . ' src="' . ViewHelper::esc($in_bildUrl) . '" alt="" loading="lazy">';
        }

        return '<span class="' . ViewHelper::esc($klassen) . '" aria-hidden="true">'
             . ViewHelper::esc(self::initials($in_name))
             . '</span>';
    }

    /**
     * Die Adresse, unter der das Bild eines Guides ausgeliefert wird.
     *
     * DIE EINE STELLE, an der diese Adresse gebaut wird - der Weg fuehrt
     * ausschliesslich ueber den Controller, denn die Dateien liegen
     * ausserhalb des Webroots (config/uploads.php).
     *
     * Zwei Groessen, wie bei den Standortbildern: 'thumb' fuer Listen und
     * Kacheln, 'full' fuer die Kopfzeile der Profilseite.
     *
     * @param int    $in_user_id
     * @param string $in_groesse 'thumb' oder 'full'
     * @return string
     */
    public static function url($in_user_id, string $in_groesse = 'thumb'): string
    {
        return 'index.php?act=guide_avatar&id=' . (int)$in_user_id
             . '&size=' . ($in_groesse === 'full' ? 'full' : 'thumb');
    }
}
