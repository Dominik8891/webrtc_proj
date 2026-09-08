<?php

namespace App\Model;

use PDO;

/**
 * Die Bremse: zaehlt Versuche serverseitig und weist ab, wenn es zu viele
 * werden.
 *
 * WARUM ES SIE GIBT
 * -----------------
 * Der Login hatte eine Bremse, aber sie lag in $_SESSION - also im Cookie des
 * Aufrufers. Wer es verwarf, fing bei null an; ein Skript, das gar keine
 * Cookies annimmt, hatte nie ein Limit. Gebremst wurde damit ausschliesslich
 * der ehrliche Nutzer. Die Zwei-Faktor-Pruefung hatte gar keinen Zaehler, die
 * Registrierung auch nicht.
 *
 * Ein Zaehler, der beim Aufrufer liegt, ist kein Zaehler. Er steht hier: in
 * der Tabelle `rate_limit` (migrations/018).
 *
 * DER BAUSTEIN, NICHT DREIMAL DASSELBE
 * ------------------------------------
 * Diese Klasse weiss nichts von Login, 2FA oder Registrierung. Sie kennt nur
 * AKTIONEN und deren SCHRANKEN, und die stehen in config/limits.php. Ein
 * Controller sagt, WAS er tut und WORAN es haengt - nie, wie oft es erlaubt
 * ist:
 *
 *     $teile = ['konto' => $username, 'ip' => RateLimit::ip()];
 *
 *     $rest = RateLimit::restsperre('login', $teile);
 *     if ($rest > 0) { ... abweisen ... }
 *
 *     if ($erfolg) { RateLimit::zuruecksetzen('login', $teile); }
 *     else         { $rest = RateLimit::verbuchen('login', $teile); }
 *
 * EINE WEITERE BREMSE - fuer Anfragen oder Bewertungen - ist damit ein
 * Eintrag in config/limits.php und diese vier Zeilen. Es kommt dafuer keine
 * Logik dazu, und vor allem keine ZWEITE Zahl an einer zweiten Stelle.
 *
 * MEHRERE SCHRANKEN JE AKTION
 * ---------------------------
 * Weil eine einzelne immer falsch ist. Eine reine Kontosperre ist eine Waffe
 * gegen den Kontoinhaber: Wer einen Benutzernamen kennt, sperrt das Konto mit
 * fuenf falschen Passwoertern aus. Eine reine IP-Sperre bremst ein Botnetz
 * nicht. Der Aufrufer uebergibt deshalb alle Teile, die er hat, und welche
 * Schranken daraus gebaut werden, entscheidet allein die Konfiguration. Es
 * gilt die STRENGSTE: restsperre() liefert die laengste offene Sperre.
 *
 * WAS BEI EINEM FEHLER PASSIERT
 * -----------------------------
 * Nichts wird abgefangen. Fehlt die Tabelle - Code ausgeliefert, Wanderung
 * vergessen -, schlaegt der Anmeldeversuch mit einem Serverfehler fehl,
 * statt still ohne Bremse durchzulaufen. Das ist Absicht: Eine Bremse, die
 * bei Stoerung unbemerkt verschwindet, ist genau der Zustand, den diese
 * Klasse beseitigen soll. Ein lauter Fehler wird bemerkt und behoben; ein
 * leiser nicht.
 */
class RateLimit
{
    /**
     * Groesste Laenge eines Schluessels in Zeichen.
     *
     * Muss zur Spaltenbreite in migrations/018 passen. Laengere Schluessel
     * werden gekappt und durch eine Kurzfassung ersetzt (siehe
     * schluessel()) - ein Benutzername darf beliebig lang EINGEGEBEN werden,
     * er soll nur nicht den eindeutigen Schluessel der Tabelle sprengen.
     */
    private const SCHLUESSEL_MAX = 190;

    /**
     * Wann ein Zaehler von vorn beginnt, als SQL-Bedingung.
     *
     * ZWEI GRUENDE, EINE BEDINGUNG:
     *   - das Zaehlfenster ist abgelaufen (der Regelfall), oder
     *   - eine Sperre ist ABGESESSEN.
     *
     * Der zweite Fall ist der wichtige. Ohne ihn haette eine Sperre, die
     * kuerzer ist als ihr Fenster, eine Falle: Nach dem Ablauf der Sperre
     * stuende der Zaehler noch immer auf der Grenze, der naechste Versuch
     * wuerde sofort wieder sperren - und so fort, bis das Fenster endet. Wer
     * eine Sperre abgesessen hat, faengt bei eins an; damit ist jede
     * Kombination von Fenster und Sperre in config/limits.php gefahrlos.
     *
     * Steht als Konstante, weil die Bedingung im Hochzaehlen ZWEIMAL
     * gebraucht wird - fuer den Zaehler und fuer das Fenster - und beide
     * dieselbe sein muessen.
     */
    private const NEUES_FENSTER = '(fenster_bis <= NOW()
                                    OR (gesperrt_bis IS NOT NULL AND gesperrt_bis <= NOW()))';

    /**
     * Die geladene Konfiguration, damit config/limits.php nicht je Aufruf
     * erneut vom Datentraeger gelesen wird.
     */
    private static ?array $limits = null;

    /**
     * Die Schranken einer Aktion aus config/limits.php.
     *
     * EINE UNBEKANNTE AKTION IST EIN FEHLER und kein "dann eben keine
     * Bremse": Ein Tippfehler im Controller wuerde sonst still eine Bremse
     * ausschalten, und niemandem faellt etwas auf - dasselbe Muster wie bei
     * der Routentabelle (App\Helper\Permission::routeErrors), die eine
     * vergessene Rechteangabe ebenfalls nicht durchwinkt.
     *
     * @param  string $aktion Schluessel aus config/limits.php
     * @return array<string,array>  Schrankenname => Einstellungen
     * @throws \InvalidArgumentException wenn die Aktion nicht eingetragen ist
     */
    public static function schranken(string $aktion): array
    {
        if (self::$limits === null) {
            self::$limits = require __DIR__ . '/../../config/limits.php';
        }
        if (!isset(self::$limits[$aktion])) {
            throw new \InvalidArgumentException(
                "Unbekannte Aktion '$aktion' - kein Eintrag in config/limits.php."
            );
        }
        return self::$limits[$aktion];
    }

    /**
     * Wie lange diese Kombination noch abgewiesen wird.
     *
     * Geprueft werden ALLE Schranken der Aktion in einer einzigen Abfrage;
     * es gilt die strengste, also die laengste offene Sperre. Verglichen wird
     * mit NOW() und nicht mit einer Marke in der Zeile: Eine Sperre endet
     * damit von selbst, auch wenn der Aufraeum-Cronjob nie laeuft.
     *
     * @param  string               $aktion Aktion aus config/limits.php
     * @param  array<string,string> $teile  Benannte Bestandteile, z.B.
     *                                      ['konto' => 'anna', 'ip' => '::1']
     * @return int Restsperre in Sekunden, 0 wenn nicht gesperrt
     */
    public static function restsperre(string $aktion, array $teile): int
    {
        $paare = self::paare($aktion, $teile);
        if ($paare === []) {
            return 0;
        }

        // (schranke, schluessel) IN ((?,?),(?,?)) - ein Zeilenvergleich je
        // Schranke. Eine Abfrage statt einer je Schranke: Die Bremse liegt
        // vor JEDEM Anmeldeversuch, auch vor jedem erfolgreichen.
        $platzhalter = implode(', ', array_fill(0, count($paare), '(?, ?)'));
        $werte = [$aktion];
        foreach ($paare as $paar) {
            $werte[] = $paar['schranke'];
            $werte[] = $paar['schluessel'];
        }

        // GREATEST(1, ...): Bleibt weniger als eine Sekunde, liefert
        // TIMESTAMPDIFF 0 - und 0 heisst an dieser Stelle "frei". Eine
        // laufende Sperre soll nicht durch Abrunden enden.
        $sql = "SELECT MAX(GREATEST(1, TIMESTAMPDIFF(SECOND, NOW(), gesperrt_bis))) AS rest
                  FROM rate_limit
                 WHERE aktion = ?
                   AND gesperrt_bis > NOW()
                   AND (schranke, schluessel) IN ($platzhalter)";

        $stmt = PdoConnect::$connection->prepare($sql);
        $stmt->execute($werte);
        $zeile = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($zeile['rest'] ?? 0);
    }

    /**
     * Verbucht einen Versuch auf allen Schranken der Aktion.
     *
     * WAS EIN "VERSUCH" IST, entscheidet der Aufrufer: Bei Login und 2FA ist
     * es ein FEHLVERSUCH - ein erfolgreicher setzt zurueck. Bei der
     * Registrierung gibt es keinen Fehlversuch, dort ist der Vorgang selbst
     * der Versuch.
     *
     * HOCHGEZAEHLT WIRD IN EINEM SCHRITT, mit INSERT ... ON DUPLICATE KEY
     * UPDATE. Kein Lesen, Rechnen, Schreiben: Zwei gleichzeitige Versuche
     * wuerden dabei denselben Ausgangswert lesen und beide auf denselben Wert
     * schreiben - der zweite Versuch waere gratis. Genau darauf laeuft ein
     * Angriff mit vielen parallelen Verbindungen hinaus.
     *
     * Die Zahlen aus der Konfiguration stehen direkt im SQL statt als
     * gebundene Werte: Sie kommen aus einer PHP-Datei dieses Projekts, nicht
     * aus einer Anfrage, und werden mit (int) gecastet - MySQL laesst einen
     * Platzhalter hinter INTERVAL nicht ueberall zu. Der SCHLUESSEL dagegen
     * kommt vom Aufrufer und ist immer gebunden.
     *
     * @param  string               $aktion Aktion aus config/limits.php
     * @param  array<string,string> $teile  Benannte Bestandteile
     * @return int Restsperre in Sekunden NACH diesem Versuch, 0 wenn frei
     */
    public static function verbuchen(string $aktion, array $teile): int
    {
        foreach (self::paare($aktion, $teile) as $paar) {
            $fenster = (int)$paar['einstellung']['fenster'];
            $grenze  = (int)$paar['einstellung']['versuche'];
            $sperre  = (int)$paar['einstellung']['sperre'];
            $neu     = self::NEUES_FENSTER;

            // Die Reihenfolge der drei Zuweisungen traegt die Logik: MySQL
            // wertet sie von oben nach unten aus, spaetere sehen die bereits
            // gesetzten Werte der frueheren.
            //   versuche      liest das ALTE fenster_bis,
            //   fenster_bis   ebenfalls das alte - es wird erst danach gesetzt,
            //   gesperrt_bis  liest das NEUE versuche und vergleicht es mit
            //                 der Grenze.
            // Der innere IF in gesperrt_bis raeumt eine abgesessene Sperre ab,
            // statt sie als alten Zeitpunkt stehen zu lassen: "NULL heisst
            // nicht gesperrt" soll genau eine Schreibweise haben.
            $sql = "INSERT INTO rate_limit
                        (aktion, schranke, schluessel, versuche, fenster_bis,
                         gesperrt_bis, letzter_versuch)
                    VALUES
                        (?, ?, ?, 1, NOW() + INTERVAL $fenster SECOND, NULL, NOW())
                    ON DUPLICATE KEY UPDATE
                        versuche        = IF($neu, 1, versuche + 1),
                        fenster_bis     = IF($neu, NOW() + INTERVAL $fenster SECOND, fenster_bis),
                        gesperrt_bis    = IF(versuche >= $grenze,
                                             NOW() + INTERVAL $sperre SECOND,
                                             IF(gesperrt_bis > NOW(), gesperrt_bis, NULL)),
                        letzter_versuch = NOW()";

            $stmt = PdoConnect::$connection->prepare($sql);
            $stmt->execute([$aktion, $paar['schranke'], $paar['schluessel']]);
        }

        // Zurueckgelesen statt aus dem Zaehlstand geschlossen: Nur die
        // Datenbank weiss, ob dieser Versuch der war, der die Grenze erreicht
        // hat - und ob eine ANDERE Schranke laenger sperrt als diese.
        return self::restsperre($aktion, $teile);
    }

    /**
     * Loescht die Zaehler nach einem erfolgreichen Vorgang.
     *
     * NICHT ALLE. Geloescht werden nur die Schranken, die in
     * config/limits.php 'erfolg_loescht' => true tragen - in aller Regel die,
     * die am KONTO haengen. Eine reine IP-Schranke bleibt stehen: Sie zaehlt
     * die Adresse und nicht dieses Konto. Wuerde ein erfolgreicher Login sie
     * loeschen, koennte ein Angreifer mit einem einzigen eigenen Konto seinen
     * IP-Zaehler beliebig oft zuruecksetzen und damit genau die Schranke
     * aushebeln, die das Durchprobieren von BENUTZERNAMEN begrenzt.
     *
     * @param  string               $aktion Aktion aus config/limits.php
     * @param  array<string,string> $teile  Benannte Bestandteile
     * @return void
     */
    public static function zuruecksetzen(string $aktion, array $teile): void
    {
        $paare = array_values(array_filter(
            self::paare($aktion, $teile),
            fn($paar) => !empty($paar['einstellung']['erfolg_loescht'])
        ));
        if ($paare === []) {
            return;
        }

        $platzhalter = implode(', ', array_fill(0, count($paare), '(?, ?)'));
        $werte = [$aktion];
        foreach ($paare as $paar) {
            $werte[] = $paar['schranke'];
            $werte[] = $paar['schluessel'];
        }

        $stmt = PdoConnect::$connection->prepare(
            "DELETE FROM rate_limit
              WHERE aktion = ?
                AND (schranke, schluessel) IN ($platzhalter)"
        );
        $stmt->execute($werte);
    }

    /**
     * Loescht abgelaufene Zaehler. Fuer cron/check_online_status.php.
     *
     * DAS IST AUFRAEUMEN UND KEINE PRUEFUNG - dieselbe Ueberlegung wie bei
     * der Bereitschaft und bei den Anfragen. Ob jemand gesperrt ist,
     * entscheidet nirgends dieser Aufruf, sondern der Vergleich mit NOW() in
     * restsperre(). Ohne Cronjob bremst alles genauso; die Tabelle sammelt
     * dann nur Zeilen, die nichts mehr aussagen.
     *
     * Geloescht wird nur, was BEIDES hinter sich hat: das Zaehlfenster und
     * eine etwaige Sperre. Eine laufende Sperre mit abgelaufenem Fenster
     * bleibt stehen - sonst waere das Aufraeumen ein Freispruch.
     *
     * @return int Zahl der geloeschten Zeilen
     */
    public static function aufraeumen(): int
    {
        return (int)PdoConnect::$connection->exec(
            "DELETE FROM rate_limit
              WHERE fenster_bis <= NOW()
                AND (gesperrt_bis IS NULL OR gesperrt_bis <= NOW())"
        );
    }

    /**
     * Die Adresse des Aufrufers als Schluesselteil.
     *
     * NUR REMOTE_ADDR, NIE X-Forwarded-For: Dieser Kopf kommt vom Aufrufer
     * selbst. Wer ihn auswertet, ohne einen bekannten Proxy davor zu haben,
     * ersetzt eine Bremse durch ein Textfeld, in das der Angreifer bei jedem
     * Versuch eine neue Adresse schreibt. Steht die Anwendung spaeter hinter
     * einem eigenen Proxy, gehoert dessen Auswertung genau hierher - an eine
     * Stelle, mit einer Liste vertrauenswuerdiger Adressen.
     *
     * BEI IPv6 ZAEHLT DAS /64 UND NICHT DIE EINZELNE ADRESSE. Ein
     * gewoehnlicher Anschluss bekommt ein ganzes /64 zugeteilt, also 2^64
     * Adressen. Eine Bremse an der Einzeladresse waere dort keine: Der
     * naechste Versuch kaeme von der naechsten Adresse desselben Anschlusses.
     * Genullt werden deshalb die hinteren acht Byte.
     *
     * @return string Normalisierte Adresse, '' wenn keine ermittelbar ist
     */
    public static function ip(): string
    {
        $roh = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($roh === '') {
            return '';
        }

        $binaer = @inet_pton($roh);
        if ($binaer === false) {
            // Keine gueltige Adresse - unveraendert und gekappt uebernehmen,
            // damit die Bremse auch dann greift, wenn die Umgebung etwas
            // Unerwartetes liefert.
            return substr($roh, 0, 45);
        }

        // 16 Byte = IPv6. Die hinteren acht Byte nullen ergibt das /64.
        if (strlen($binaer) === 16) {
            $netz = substr($binaer, 0, 8) . str_repeat("\0", 8);
            return inet_ntop($netz) . '/64';
        }

        return inet_ntop($binaer);
    }

    /**
     * Die Kennung des handelnden Kontos als Schluesselteil.
     *
     * WOZU EINE EIGENE METHODE FUER EINEN CAST: Weil der Cast falsch ist.
     * App\Helper\Auth::userId() liefert 0, wenn niemand angemeldet ist -
     * und (string)0 ist "0", also ein NICHT LEERER Schluessel. Eine
     * Kontoschranke wuerde damit nicht wegfallen, sondern saemtliche nicht
     * angemeldeten Aufrufer auf EINEN gemeinsamen Zaehler legen: Der erste,
     * der die Grenze erreicht, sperrt alle uebrigen mit.
     *
     * Der Leerstring ist die Antwort darauf. schluessel() laesst eine
     * Schranke weg, der ein Teil fehlt - die IP-Schranke greift weiter, die
     * Kontoschranke nicht. Das ist richtig so: Ohne Konto gibt es nichts, was
     * sie zaehlen koennte.
     *
     * NUR FUER KENNUNGEN, nicht fuer Benutzernamen: Beim Login ist der
     * Kontoteil des Schluessels der eingegebene Name, und der geht
     * unveraendert hinein (er darf auch leer sein, dann faellt die Schranke
     * ebenso weg).
     *
     * @param  int $userId Kennung, 0 = niemand angemeldet
     * @return string Die Kennung als Text, '' bei 0
     */
    public static function konto(int $userId): string
    {
        return $userId > 0 ? (string)$userId : '';
    }

    /**
     * Die Wartezeit als Satzteil fuer eine Fehlermeldung.
     *
     * AN EINER STELLE, weil drei Controller sie brauchen und der Nutzer
     * ueberall dasselbe lesen soll. Gerundet wird nach oben: "noch 1 Minute"
     * bei 61 Sekunden ist eine Zusage, die nicht gehalten wird.
     *
     * Sekundengenau nur unter einer Minute - "noch 847 Sekunden" ist keine
     * Auskunft, mit der ein Mensch etwas anfangen kann.
     *
     * @param  int $sekunden Restsperre aus restsperre() oder verbuchen()
     * @return string z.B. "noch 15 Minuten"
     */
    public static function wartehinweis(int $sekunden): string
    {
        if ($sekunden <= 0) {
            return 'gleich wieder';
        }
        if ($sekunden < 60) {
            return 'noch ' . $sekunden . ' Sekunde' . ($sekunden === 1 ? '' : 'n');
        }
        $minuten = (int)ceil($sekunden / 60);
        if ($minuten < 60) {
            return 'noch ' . $minuten . ' Minute' . ($minuten === 1 ? '' : 'n');
        }
        $stunden = (int)ceil($minuten / 60);
        return 'noch ' . $stunden . ' Stunde' . ($stunden === 1 ? '' : 'n');
    }

    /**
     * Baut zu jeder Schranke der Aktion ihren Schluessel.
     *
     * Eine Schranke, der ein benoetigter Teil fehlt, faellt heraus - die
     * anderen bleiben. Das ist der Fall "leeres Benutzernamensfeld": Die
     * Kontoschranken greifen dann nicht, die IP-Schranke schon. Ohne diese
     * Regel waeren alle leeren Eingaben EIN Schluessel, und das
     * versehentliche Abschicken eines leeren Formulars durch viele Nutzer
     * wuerde sie gegenseitig aussperren.
     *
     * @param  string               $aktion
     * @param  array<string,string> $teile
     * @return list<array{schranke:string,schluessel:string,einstellung:array}>
     */
    private static function paare(string $aktion, array $teile): array
    {
        $paare = [];
        foreach (self::schranken($aktion) as $name => $einstellung) {
            $schluessel = self::schluessel($einstellung['teile'], $teile);
            if ($schluessel === null) {
                continue;
            }
            $paare[] = [
                'schranke'    => $name,
                'schluessel'  => $schluessel,
                'einstellung' => $einstellung,
            ];
        }
        return $paare;
    }

    /**
     * Setzt den Schluessel einer Schranke aus den benannten Teilen zusammen.
     *
     * KLEINGESCHRIEBEN, weil die Datenbank Benutzernamen ohne Ruecksicht auf
     * Gross- und Kleinschreibung vergleicht (utf8mb4_general_ci): "Anna" und
     * "anna" sind dasselbe Konto. Waeren es zwei Schluessel, haette ein
     * Angreifer die Bremse mit einer anderen Schreibweise umgangen.
     *
     * ZU LANGE SCHLUESSEL werden durch eine Kurzfassung ersetzt statt
     * abgeschnitten: Abschneiden wuerde zwei verschiedene lange
     * Benutzernamen mit gleichem Anfang auf denselben Zaehler legen und damit
     * Unbeteiligte mitsperren. Der Hash ist keine Geheimhaltung, sondern nur
     * eine eindeutige Kurzform - der Regelfall bleibt lesbar in der Tabelle
     * stehen.
     *
     * @param  list<string>         $namen Welche Teile diese Schranke braucht
     * @param  array<string,string> $teile Was der Aufrufer uebergeben hat
     * @return string|null Der Schluessel, oder null wenn ein Teil fehlt
     */
    private static function schluessel(array $namen, array $teile): ?string
    {
        $stuecke = [];
        foreach ($namen as $name) {
            $wert = trim((string)($teile[$name] ?? ''));
            if ($wert === '') {
                return null;
            }
            $stuecke[] = mb_strtolower($wert, 'UTF-8');
        }

        // Das Trennzeichen gehoert dazu: Ohne es waeren ('ab','c') und
        // ('a','bc') derselbe Schluessel.
        $schluessel = implode("\x1f", $stuecke);

        return mb_strlen($schluessel, 'UTF-8') <= self::SCHLUESSEL_MAX
            ? $schluessel
            : 'kurz:' . hash('sha256', $schluessel);
    }
}
