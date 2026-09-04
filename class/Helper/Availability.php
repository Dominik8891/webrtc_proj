<?php
namespace App\Helper;

/**
 * Die ueblichen Zeiten eines Standorts - das Raster, die Zeitzone, der
 * Abgleich.
 *
 * WOZU
 * ----
 * Ein Kunde sah bisher nur, ob ein Guide GERADE bereit ist. War er es nicht,
 * blieb offen, ob sich eine Anfrage fuer spaeter lohnt oder ob der Guide nur
 * sonntags kann. Die ueblichen Zeiten sind die Antwort darauf - eine
 * ORIENTIERUNG, kein Kalender: "donnerstags abends", "am Wochenende
 * vormittags".
 *
 * DAS RASTER
 * ----------
 * Sieben Wochentage mal vier Tagesabschnitte, also 28 Felder. Die Abschnitte
 * decken den ganzen Tag ab - auch die Nacht, denn es gibt Nachtfuehrungen,
 * und bei drei Abschnitten fielen sie hinten herunter.
 *
 * Die Grenzen stehen HIER und nirgends sonst. Sie gehen von hier an das
 * Formular des Guides, an die Anzeige auf der Standortseite und an die
 * Pruefung des Wunschzeitpunkts; drei Fassungen derselben Zahl waeren drei
 * Gelegenheiten, sie auseinanderlaufen zu lassen.
 *
 * WIE ES GESPEICHERT WIRD
 * -----------------------
 * Als Zeichenkette aus 28 Nullen und Einsen in location.availability_slots.
 * Die Stelle im Text ist der Platz im Raster: Wochentag * 4 + Abschnitt,
 * Montag zuerst.
 *
 * Warum keine eigene Tabelle: Es sind 28 Ja/Nein-Angaben, die IMMER
 * vollstaendig gelesen und vollstaendig geschrieben werden - zusammen mit
 * dem Standort, auf dessen Seite sie stehen. Eine Tabelle mit 28 Zeilen je
 * Standort waere ein Join fuer eine Angabe, die in eine Spalte passt. Eine
 * spaetere Suche ("wer kann samstagabends?") bleibt moeglich: SUBSTRING auf
 * die Stelle des Rasters.
 *
 * DIE ZEITZONE
 * ------------
 * Die Zeiten gelten AM ORT DER FUEHRUNG. Ein Kunde in Tokio, der einen
 * Standort in Lissabon ansieht, muss "donnerstags abends" als Lissabonner
 * Abend lesen - sonst verabreden sich beide auf verschiedene Uhrzeiten.
 * Woher die Zone kommt, steht in zoneFor(): abgeleitet aus Land und
 * Koordinaten, mit PHP-Bordmitteln und ohne neue Abhaengigkeit. Der Guide
 * kann sie im Formular ueberschreiben - fuer Grenzfaelle bleibt das letzte
 * Wort bei ihm.
 *
 * JEDE METHODE HIER IST EINE REINE FUNKTION: Werte rein, Werte raus. Keine
 * Sitzung, keine Anfrage, keine Datenbank.
 */
class Availability
{
    /**
     * Die Wochentage, Montag zuerst.
     *
     * Der Schluessel ist die Kennung im Formular, der Wert die Beschriftung.
     * Die REIHENFOLGE ist zugleich die Stelle im gespeicherten Muster - wer
     * sie aendert, verschiebt alle Angaben aller Standorte.
     */
    private const TAGE = [
        'mo' => ['kurz' => 'Mo', 'lang' => 'Montag'],
        'di' => ['kurz' => 'Di', 'lang' => 'Dienstag'],
        'mi' => ['kurz' => 'Mi', 'lang' => 'Mittwoch'],
        'do' => ['kurz' => 'Do', 'lang' => 'Donnerstag'],
        'fr' => ['kurz' => 'Fr', 'lang' => 'Freitag'],
        'sa' => ['kurz' => 'Sa', 'lang' => 'Samstag'],
        'so' => ['kurz' => 'So', 'lang' => 'Sonntag'],
    ];

    /**
     * Die vier Tagesabschnitte, mit ihren Grenzen in Stunden.
     *
     * 'von' ist einschliesslich, 'bis' ausschliesslich. Die Nacht laeuft ueber
     * Mitternacht (22 bis 6) und ist deshalb der einzige Abschnitt, bei dem
     * 'von' groesser ist als 'bis' - partFuerStunde() rechnet damit.
     *
     * WELCHEM TAG EINE NACHT GEHOERT: dem Kalendertag, auf den die Uhrzeit
     * faellt. "Donnerstag nachts" heisst also Donnerstag 22-24 Uhr UND
     * Donnerstag 0-6 Uhr. Das ist die einzige Auslegung, die ohne Rueckfrage
     * auskommt - "die Nacht von Donnerstag auf Freitag" waere die andere, und
     * sie liesse sich im Raster nicht von der ersten unterscheiden.
     */
    private const ABSCHNITTE = [
        'nacht'      => ['kurz' => 'nachts',      'von' => 22, 'bis' => 6],
        'vormittag'  => ['kurz' => 'vormittags',  'von' => 6,  'bis' => 12],
        'nachmittag' => ['kurz' => 'nachmittags', 'von' => 12, 'bis' => 18],
        'abend'      => ['kurz' => 'abends',      'von' => 18, 'bis' => 22],
    ];

    /** Laenge des gespeicherten Musters: 7 Tage mal 4 Abschnitte. */
    public const LAENGE = 28;

    /**
     * Die Wochentage als Liste.
     *
     * @return array<string,array<string,string>>
     */
    public static function tage(): array
    {
        return self::TAGE;
    }

    /**
     * Die Tagesabschnitte als Liste, mit ihren Stundengrenzen.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function abschnitte(): array
    {
        return self::ABSCHNITTE;
    }

    /**
     * Ein leeres Muster - 28 Nullen.
     *
     * @return string
     */
    public static function leer(): string
    {
        return str_repeat('0', self::LAENGE);
    }

    /**
     * Die Stelle eines Feldes im Muster.
     *
     * @param int $in_tag       0 = Montag
     * @param int $in_abschnitt 0 = nachts
     * @return int
     */
    public static function stelle(int $in_tag, int $in_abschnitt): int
    {
        return $in_tag * count(self::ABSCHNITTE) + $in_abschnitt;
    }

    /**
     * Macht aus der Auswahl des Formulars ein Muster.
     *
     * Erwartet eine Liste von Kennungen der Form "tag-abschnitt", etwa
     * "do-abend". Alles, was das Raster nicht kennt, wird verworfen - genau
     * wie bei den Sprachen (App\Helper\Languages::normalize): Eine unbekannte
     * Angabe ist kein Ablehnungsgrund, sie ist einfach keine Angabe.
     *
     * @param mixed $in_roh Liste aus dem Formular
     * @return string 28 Zeichen aus '0' und '1'
     */
    public static function normalize($in_roh): string
    {
        $muster = str_split(self::leer());
        if (!is_array($in_roh)) return implode('', $muster);

        $tage       = array_keys(self::TAGE);
        $abschnitte = array_keys(self::ABSCHNITTE);

        foreach ($in_roh as $eintrag) {
            if (!is_string($eintrag)) continue;

            $teile = explode('-', $eintrag);
            if (count($teile) !== 2) continue;

            $t = array_search($teile[0], $tage, true);
            $a = array_search($teile[1], $abschnitte, true);
            if ($t === false || $a === false) continue;

            $muster[self::stelle($t, $a)] = '1';
        }

        return implode('', $muster);
    }

    /**
     * Bringt einen gespeicherten Wert in eine brauchbare Form.
     *
     * Bestandsdaten haben NULL, aeltere oder verstellte Werte koennen zu kurz,
     * zu lang oder voller Unsinn sein. Was hier herauskommt, ist IMMER ein
     * Muster der richtigen Laenge - die Lesestellen sollen sich darauf
     * verlassen koennen.
     *
     * @param mixed $in_wert
     * @return string
     */
    public static function muster($in_wert): string
    {
        $text = is_string($in_wert) ? $in_wert : '';
        $text = preg_replace('/[^01]/', '0', $text);
        $text = substr($text, 0, self::LAENGE);

        return str_pad((string)$text, self::LAENGE, '0');
    }

    /**
     * Ist gar nichts angegeben?
     *
     * @param mixed $in_wert
     * @return bool
     */
    public static function istLeer($in_wert): bool
    {
        return strpos(self::muster($in_wert), '1') === false;
    }

    /**
     * Ist dieses Feld gesetzt?
     *
     * @param mixed  $in_wert
     * @param string $in_tag       Kennung, z. B. 'do'
     * @param string $in_abschnitt Kennung, z. B. 'abend'
     * @return bool
     */
    public static function hat($in_wert, string $in_tag, string $in_abschnitt): bool
    {
        $t = array_search($in_tag, array_keys(self::TAGE), true);
        $a = array_search($in_abschnitt, array_keys(self::ABSCHNITTE), true);
        if ($t === false || $a === false) return false;

        return self::muster($in_wert)[self::stelle($t, $a)] === '1';
    }

    /**
     * Welcher Abschnitt gehoert zu dieser Stunde?
     *
     * @param int $in_stunde 0 bis 23
     * @return string Kennung des Abschnitts
     */
    public static function abschnittFuerStunde(int $in_stunde): string
    {
        $stunde = (int)$in_stunde;

        foreach (self::ABSCHNITTE as $kennung => $a) {
            // Der Abschnitt ueber Mitternacht (von > bis) gilt in zwei
            // Stuecken: ab 'von' bis Mitternacht und ab Mitternacht bis 'bis'.
            if ($a['von'] > $a['bis']) {
                if ($stunde >= $a['von'] || $stunde < $a['bis']) return $kennung;
                continue;
            }
            if ($stunde >= $a['von'] && $stunde < $a['bis']) return $kennung;
        }

        // Unerreichbar, solange die Abschnitte den Tag lueckenlos decken -
        // und genau deshalb steht hier keine stille Annahme, sondern der
        // erste Abschnitt und ein Eintrag im Log.
        error_log('Availability: keine Zuordnung fuer Stunde ' . $stunde);
        return array_key_first(self::ABSCHNITTE);
    }

    /**
     * Faellt dieser Zeitpunkt in eine der ueblichen Zeiten?
     *
     * GERECHNET WIRD IN DER ZEITZONE DES STANDORTS. Der Zeitpunkt kommt als
     * absoluter Moment herein (ein DateTimeImmutable traegt seine eigene
     * Zone); hier wird er auf die Ortszeit der Fuehrung umgestellt, und erst
     * daraus werden Wochentag und Stunde gelesen. Ohne diesen Schritt hiesse
     * "donnerstags abends" fuer jeden Betrachter etwas anderes.
     *
     * OHNE ANGABEN IST NICHTS AUSSERHALB: Ein Standort ohne uebliche Zeiten
     * macht keine Aussage, und aus einer fehlenden Aussage folgt kein
     * Hinweis (siehe die Aufrufstelle).
     *
     * @param mixed              $in_wert     Gespeichertes Muster
     * @param \DateTimeInterface $in_moment   Der Wunschzeitpunkt
     * @param string             $in_zone     Zeitzone des Standorts
     * @return bool
     */
    public static function passt($in_wert, \DateTimeInterface $in_moment, string $in_zone): bool
    {
        if (self::istLeer($in_wert)) return false;

        $vorOrt = (new \DateTimeImmutable('@' . $in_moment->getTimestamp()))
            ->setTimezone(new \DateTimeZone(self::zone($in_zone)));

        // 'N' ist der Wochentag nach ISO: 1 = Montag. Das Raster beginnt
        // ebenfalls bei Montag, also einfach eins abziehen.
        $tag     = (int)$vorOrt->format('N') - 1;
        $stunde  = (int)$vorOrt->format('G');
        $abschn  = array_search(self::abschnittFuerStunde($stunde), array_keys(self::ABSCHNITTE), true);

        return self::muster($in_wert)[self::stelle($tag, (int)$abschn)] === '1';
    }

    /**
     * Die ueblichen Zeiten als Satz.
     *
     * FASST TAGE ZUSAMMEN, statt sie aufzuzaehlen: "Mo-Fr abends" liest sich
     * schneller als fuenf Zeilen, und genau darum geht es - um eine
     * Orientierung auf einen Blick. Zusammengefasst wird nur, was
     * aufeinanderfolgt.
     *
     * @param mixed $in_wert
     * @return string Leerstring, wenn nichts angegeben ist
     */
    public static function text($in_wert): string
    {
        $muster = self::muster($in_wert);
        if (self::istLeer($muster)) return '';

        $tage  = array_values(self::TAGE);
        $saetze = [];

        foreach (array_values(self::ABSCHNITTE) as $a_index => $abschnitt) {
            // Welche Tage tragen diesen Abschnitt?
            $treffer = [];
            foreach ($tage as $t_index => $tag) {
                if ($muster[self::stelle($t_index, $a_index)] === '1') $treffer[] = $t_index;
            }
            if ($treffer === []) continue;

            $saetze[] = self::tageText($treffer) . ' ' . $abschnitt['kurz'];
        }

        return implode(', ', $saetze);
    }

    /**
     * Eine Liste von Wochentagen als Text, mit zusammengefassten Strecken.
     *
     * @param int[] $in_tage Stellen im Raster, aufsteigend
     * @return string z. B. "Mo-Fr", "Sa+So", "Do"
     */
    private static function tageText(array $in_tage): string
    {
        $kurz   = array_column(array_values(self::TAGE), 'kurz');
        $stuecke = [];
        $start   = null;
        $vorher  = null;

        foreach ($in_tage as $tag) {
            if ($start === null) { $start = $vorher = $tag; continue; }
            if ($tag === $vorher + 1) { $vorher = $tag; continue; }

            $stuecke[] = [$start, $vorher];
            $start = $vorher = $tag;
        }
        if ($start !== null) $stuecke[] = [$start, $vorher];

        $texte = [];
        foreach ($stuecke as [$von, $bis]) {
            // Erst ab drei Tagen ein Bindestrich: "Sa-So" waere laenger als
            // "Sa+So" und sagt nicht mehr.
            if ($von === $bis)          $texte[] = $kurz[$von];
            elseif ($bis - $von === 1)  $texte[] = $kurz[$von] . '+' . $kurz[$bis];
            else                        $texte[] = $kurz[$von] . '-' . $kurz[$bis];
        }

        return implode(', ', $texte);
    }

    // =================================================================
    // ZEITZONE
    // =================================================================

    /**
     * Eine gueltige Zeitzone - oder UTC.
     *
     * Der Rueckfall ist bewusst UTC und nicht die Zeit des Servers: Eine
     * unbekannte Zone soll auffallen und nicht stillschweigend "wie bei uns"
     * bedeuten.
     *
     * @param mixed $in_zone
     * @return string
     */
    public static function zone($in_zone): string
    {
        return self::istZone($in_zone) ? (string)$in_zone : 'UTC';
    }

    /**
     * Kennt PHP diese Zeitzone?
     *
     * @param mixed $in_zone
     * @return bool
     */
    public static function istZone($in_zone): bool
    {
        if (!is_string($in_zone) || $in_zone === '') return false;
        return in_array($in_zone, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Die Zeitzone eines Standorts, abgeleitet aus Land und Koordinaten.
     *
     * WARUM NICHT AUS DEN KOORDINATEN ALLEIN: Dafuer braeuchte es die Grenzen
     * aller Zeitzonen - mehrere Megabyte Geodaten oder einen Netzaufruf beim
     * Speichern. Beides ist zu viel fuer eine Angabe, die eine Orientierung
     * geben soll und die der Guide ohnehin ueberschreiben kann.
     *
     * WIE ES STATTDESSEN GEHT, in drei Stufen:
     *
     *   1. Hat das Land nur EINE Zone (Deutschland, Japan, Portugal ohne
     *      Inseln), ist sie es. Kein Raten noetig.
     *   2. Haben alle Zonen des Landes DENSELBEN Versatz - jetzt und in einem
     *      halben Jahr, also auch ueber die Sommerzeit hinweg -, ist die Wahl
     *      gleichgueltig. Dann die erste; das ist die gelaeufige
     *      (Europe/Berlin vor Europe/Busingen).
     *   3. Sonst die Zone, deren Bezugspunkt am naechsten liegt. PHP liefert
     *      zu jeder Zone Koordinaten (DateTimeZone::getLocation), und damit
     *      trifft es Denver gegen New York und Sydney gegen Perth.
     *
     * An einer Zeitzonengrenze kann Stufe 3 danebenliegen. Das ist der Preis,
     * und er ist tragbar: Der Guide sieht die erkannte Zone im Formular und
     * kann sie aendern.
     *
     * @param mixed $in_iso2 Laendercode, z. B. 'DE'
     * @param mixed $in_lat  Breitengrad des Standorts
     * @param mixed $in_lon  Laengengrad
     * @return string|null Zeitzone, oder null wenn das Land unbekannt ist
     */
    public static function zoneFor($in_iso2, $in_lat, $in_lon): ?string
    {
        $iso2 = is_string($in_iso2) ? strtoupper(trim($in_iso2)) : '';
        if (strlen($iso2) !== 2) return null;

        $zonen = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $iso2);
        if (!$zonen) return null;
        if (count($zonen) === 1) return $zonen[0];

        // Stufe 2: Unterscheiden sich die Zonen ueberhaupt?
        try {
            $jetzt   = new \DateTimeImmutable('now');
            $spaeter = new \DateTimeImmutable('+6 months');
        } catch (\Exception $e) {
            return $zonen[0];
        }

        $versatz = [];
        foreach ($zonen as $id) {
            $z = new \DateTimeZone($id);
            $versatz[$z->getOffset($jetzt) . ':' . $z->getOffset($spaeter)] = true;
        }
        if (count($versatz) === 1) return $zonen[0];

        // Stufe 3: die naechstgelegene.
        if (!is_numeric($in_lat) || !is_numeric($in_lon)) return $zonen[0];

        $lat = (float)$in_lat;
        $lon = (float)$in_lon;
        $beste = $zonen[0];
        $kleinste = INF;

        foreach ($zonen as $id) {
            $ort = (new \DateTimeZone($id))->getLocation();
            if (!$ort || !isset($ort['latitude'], $ort['longitude'])) continue;

            // Quadrierter Abstand, der Laengengrad mit dem Kosinus der Breite
            // gestaucht - auf 60 Grad Nord sind zwei Laengengrade nur halb so
            // weit auseinander wie am Aequator. Eine Wurzel braucht es nicht:
            // Verglichen wird nur, welcher Abstand kleiner ist.
            $dx = ($ort['longitude'] - $lon) * cos(deg2rad($lat));
            $dy = $ort['latitude'] - $lat;
            $abstand = $dx * $dx + $dy * $dy;

            if ($abstand < $kleinste) { $kleinste = $abstand; $beste = $id; }
        }

        return $beste;
    }

    /**
     * Der Versatz einer Zone gegen UTC, in Sekunden.
     *
     * ZU EINEM ZEITPUNKT und nicht "allgemein": Sommerzeit gibt es, und im
     * Juli steht Berlin anders zu UTC als im Januar.
     *
     * @param string                  $in_zone
     * @param \DateTimeInterface|null $in_moment
     * @return int
     */
    public static function versatz(string $in_zone, ?\DateTimeInterface $in_moment = null): int
    {
        $moment = $in_moment ?? new \DateTimeImmutable('now');
        return (new \DateTimeZone(self::zone($in_zone)))->getOffset($moment);
    }

    /**
     * Die Zone als lesbare Angabe: "Europe/Lisbon (UTC+1)".
     *
     * Der Ortsname steht dabei, weil "UTC+1" allein nicht sagt, WO das gilt -
     * und die Zonenkennung allein nicht, wie weit sie vom Kunden entfernt ist.
     *
     * @param string                  $in_zone
     * @param \DateTimeInterface|null $in_moment
     * @return string
     */
    public static function zoneText(string $in_zone, ?\DateTimeInterface $in_moment = null): string
    {
        $zone     = self::zone($in_zone);
        $sekunden = self::versatz($zone, $in_moment);

        $vorzeichen = $sekunden < 0 ? '-' : '+';
        $stunden    = intdiv(abs($sekunden), 3600);
        $minuten    = intdiv(abs($sekunden) % 3600, 60);

        $versatz = 'UTC' . $vorzeichen . $stunden
                 . ($minuten > 0 ? ':' . str_pad((string)$minuten, 2, '0', STR_PAD_LEFT) : '');

        return $zone . ' (' . $versatz . ')';
    }
}
