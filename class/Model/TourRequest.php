<?php

namespace App\Model;

/**
 * Die Anfrage einer Fuehrung - und der Datensatz ueber die Fuehrung selbst.
 *
 * WARUM ES SIE GIBT
 * -----------------
 * Vorher rief ein Kunde den Guide unmittelbar an. Das verlangte, dass beide
 * zufaellig im selben Moment koennen - und der Guide ist die knappere Seite:
 * Er muss losgehen, sich Zeit nehmen, vielleicht hinfahren. Zwischen Wunsch
 * und Fuehrung steht deshalb eine ANFRAGE mit einem Wunschzeitpunkt, die der
 * Guide annimmt oder ablehnt. Danach wird angerufen wie bisher - dieselbe
 * Rollenvergabe, dieselbe Standortkennung
 * (App\Controller\WebRTCController::callRoles).
 *
 * "JETZT SOFORT" IST KEIN SONDERFALL. Es ist der Wunschzeitpunkt NOW(). Es
 * gibt dafuer keine Spalte, keine Marke und keine Verzweigung - nur einen
 * frueheren Zeitpunkt. Alles, was fuer eine Anfrage in drei Tagen gilt, gilt
 * damit auch fuer eine sofortige.
 *
 * ZWEITER ZWECK: DIE AUFZEICHNUNG
 * Ein Anruf hinterliess bisher nur Signalzeilen, die nach 15 Sekunden
 * geloescht wurden. Dass eine Fuehrung stattgefunden hat, stand danach
 * nirgends - fuer Bewertungen und eine spaetere Abrechnung fehlte genau das.
 * Beginn und Ende kommen deshalb aus dem Signaling und stehen in der Zeile.
 *
 * DER ZUSTAND WIRD GERECHNET, NICHT GEGLAUBT
 * ------------------------------------------
 * In der Spalte `status` steht, was zuletzt ENTSCHIEDEN wurde. Ob eine
 * Anfrage inzwischen ABGELAUFEN ist, steht dort NICHT - das ergibt sich aus
 * den Zeitpunkten und wird bei jeder Abfrage ausgerechnet (statusSql()).
 * Damit wirkt ein Ablauf sofort und auch dann, wenn der Cronjob gar nicht
 * eingerichtet ist; er raeumt nur auf. Dasselbe Verfahren wie bei der
 * Bereitschaft (App\Model\Location::AVAILABILITY_SQL).
 *
 * Nach aussen gibt es deshalb immer den GERECHNETEN Zustand. Wer den rohen
 * Spaltenwert weiterreicht, lockt die naechste Lesestelle dazu, "angenommen"
 * mit "gilt noch" zu verwechseln.
 *
 * ALLE METHODEN SIND STATISCH. Eine Anfrage wird nie als Objekt herumgereicht:
 * Sie wird gestellt, beantwortet, gelistet und gezaehlt - das sind Abfragen,
 * keine Zustaende im Speicher.
 */
class TourRequest
{
    // -----------------------------------------------------------------
    // Die Zustaende. Als Text in der Spalte, damit ein Dump ohne
    // Codetabelle lesbar ist.
    // -----------------------------------------------------------------

    /** Gestellt, noch nicht beantwortet. */
    public const STATUS_OPEN = 'open';
    /** Der Guide hat zugesagt. */
    public const STATUS_ACCEPTED = 'accepted';
    /** Der Guide hat abgesagt. */
    public const STATUS_DECLINED = 'declined';
    /** Unbeantwortet verstrichen ODER angenommen und das Fenster ungenutzt. */
    public const STATUS_EXPIRED = 'expired';
    /** Die Fuehrung hat stattgefunden. */
    public const STATUS_DONE = 'done';
    /** Zurueckgezogen - vom Kunden oder vom Guide. */
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Wie weit ein Arbeitsvorrat der Verwaltung zurueckreicht, in Tagen.
     *
     * Die Begruendung steht bei imVorratSql(), das diese Zahl benutzt: Ein
     * Vorrat, der sich nicht abhaken laesst, braucht ein Zeitfenster, sonst
     * ist er nach kurzer Zeit eine Zahl, die nur waechst.
     *
     * Sie steht hier und nicht in App\Model\AdminStats: Die Bedingung, in
     * der sie wirkt, gehoert dieser Klasse - und die Beschriftung auf der
     * Uebersicht liest dieselbe Konstante, damit dort nicht "7 Tage" steht,
     * waehrend die Abfrage vierzehn nimmt.
     */
    public const VORRAT_TAGE = 14;

    /**
     * Alle Zustaende, in der Reihenfolge ihres Ablaufs.
     *
     * @return string[]
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_OPEN,
            self::STATUS_ACCEPTED,
            self::STATUS_DECLINED,
            self::STATUS_EXPIRED,
            self::STATUS_DONE,
            self::STATUS_CANCELLED,
        ];
    }

    /**
     * Die deutschen Namen der Zustaende - fuer Anzeige und Protokoll.
     *
     * Sie stehen hier und nicht in der Ansicht: Liste, Standortseite und
     * Kopfleiste benennen denselben Zustand, und drei Fassungen desselben
     * Wortes waeren drei Gelegenheiten, sie auseinanderlaufen zu lassen.
     *
     * @return array<string,string>
     */
    public static function statusNames(): array
    {
        return [
            self::STATUS_OPEN      => 'offen',
            self::STATUS_ACCEPTED  => 'angenommen',
            self::STATUS_DECLINED  => 'abgelehnt',
            self::STATUS_EXPIRED   => 'abgelaufen',
            self::STATUS_DONE      => 'durchgeführt',
            self::STATUS_CANCELLED => 'abgebrochen',
        ];
    }

    /**
     * Die Fristen aus config/requests.php.
     *
     * Gelesen und gemerkt fuer die Dauer der Anfrage. Hier steht bewusst
     * keine Zahl: Sonst rechnete das Modell mit einer anderen Frist, als der
     * Cronjob aufraeumt.
     *
     * @return array<string,int>
     */
    public static function config(): array
    {
        static $config = null;
        if ($config === null) {
            $roh = require __DIR__ . '/../../config/requests.php';
            $config = array_map('intval', $roh);
        }
        return $config;
    }

    /**
     * Der GERECHNETE Zustand als SQL-Ausdruck.
     *
     * Drei Faelle, in denen der Spaltenwert nicht mehr gilt:
     *
     *   1. Eine OFFENE Anfrage, deren expires_at verstrichen ist. Der
     *      Zeitpunkt steht seit dem Anlegen in der Zeile und traegt beide
     *      Fristen aus config/requests.php - die Antwortfrist und den
     *      verstrichenen Wunschzeitpunkt.
     *   2. Eine ANGENOMMENE Anfrage, zu der es nie ein Gespraech gab und
     *      deren Zeitfenster vorbei ist. Die Verabredung ist verstrichen.
     *   3. Eine BEGONNENE Fuehrung, die niemand beendet hat und deren Frist
     *      fuer den Wiedereinstieg abgelaufen ist (closedSql). Sie ist
     *      durchgefuehrt - und wird damit bewertbar, ohne dass der Cronjob
     *      gelaufen sein muss.
     *
     * Ein einmal begonnenes Gespraech laeuft nicht mehr AB (Fall 2): Was
     * stattgefunden hat, verfaellt nicht. Es geht nur zu (Fall 3).
     *
     * @param string $in_alias Tabellenalias in der Abfrage
     * @return string SQL-Ausdruck, der einen der Zustaende liefert
     */
    public static function statusSql(string $in_alias = 'r'): string
    {
        $a      = self::alias($in_alias);
        $config = self::config();
        // (int) an dieser Stelle ist die Absicherung: Der Wert kommt zwar aus
        // einer Konfigurationsdatei und nicht vom Aufrufer, geht aber als
        // Textbaustein in die Abfrage. Ein Zahlenwert kann dort nichts
        // anrichten, eine Zeichenkette schon.
        $nach   = (int)$config['call_window_after'];

        return "CASE
                  WHEN $a.status = '" . self::STATUS_OPEN . "'
                       AND $a.expires_at <= NOW()
                       THEN '" . self::STATUS_EXPIRED . "'
                  WHEN $a.status = '" . self::STATUS_ACCEPTED . "'
                       AND $a.started_at IS NULL
                       AND DATE_ADD($a.wish_at, INTERVAL $nach SECOND) <= NOW()
                       THEN '" . self::STATUS_EXPIRED . "'
                  WHEN $a.status = '" . self::STATUS_ACCEPTED . "'
                       AND $a.started_at IS NOT NULL
                       AND " . self::closedSql($a) . "
                       THEN '" . self::STATUS_DONE . "'
                  ELSE $a.status
                END";
    }

    /**
     * Ist diese Fuehrung ZU - als SQL-Bedingung?
     *
     * DIE FRAGE, um die es beim Beenden geht, und sie hat drei Antworten:
     *
     *   1. Der Guide hat sie beendet (closed_at gesetzt, status 'done'). Das
     *      ist der Regelfall und die einzige Antwort, die jemand ausgesprochen
     *      hat.
     *   2. Er hat es vergessen, aber seit dem letzten Auflegen ist die Frist
     *      fuer den Wiedereinstieg verstrichen (config/requests.php:
     *      rejoin_window). Dann war es das Ende, auch ohne Klick.
     *   3. Es kam nie ein Auflegen an - ein Absturz, ein Netz, das weg blieb.
     *      Dann gibt es keinen Zeitpunkt, ab dem die Frist zaehlen koennte,
     *      und es zaehlt der Beginn samt der viel laengeren Reissleine
     *      ('stale_call'). Sie muss die laengste Fuehrung ueberdauern.
     *
     * GERECHNET UND NICHT GEGLAUBT, wie der Ablauf einer Anfrage: Die Frist
     * wirkt sofort und auch dann, wenn der Cronjob gar nicht eingerichtet ist;
     * er schreibt nur fest, was hier ohnehin schon gilt (closeStale).
     *
     * WAS NICHT DAZUGEHOERT: eine Anfrage, die nie begonnen hat. Sie laeuft
     * ab (siehe statusSql), aber sie ist keine beendete Fuehrung - der
     * Aufrufer prueft started_at, wo es darauf ankommt.
     *
     * @param string $in_alias Tabellenalias in der Abfrage
     * @return string SQL-Bedingung
     */
    public static function closedSql(string $in_alias = 'r'): string
    {
        $a      = self::alias($in_alias);
        $config = self::config();
        $rejoin = (int)$config['rejoin_window'];
        $stale  = (int)$config['stale_call'];

        return "($a.status = '" . self::STATUS_DONE . "'
                 OR $a.closed_at IS NOT NULL
                 OR ($a.ended_at IS NOT NULL
                     AND DATE_ADD($a.ended_at, INTERVAL $rejoin SECOND) <= NOW())
                 OR ($a.ended_at IS NULL
                     AND $a.started_at IS NOT NULL
                     AND DATE_ADD($a.started_at, INTERVAL $stale SECOND) <= NOW()))";
    }

    /**
     * Hat diese Fuehrung STATTGEFUNDEN und ist sie zu - als SQL-Bedingung?
     *
     * DIE GRUNDLAGE DER BEWERTUNG (App\Model\TourReview) und der Zaehlung
     * "N Fuehrungen durchgefuehrt". Zwei Bedingungen, und beide sind noetig:
     *
     *   begonnen  started_at ist gesetzt - es kam wirklich ein Gespraech
     *             zustande. Eine zugesagte, aber nie gestartete Anfrage
     *             laeuft ab und ist keine Fuehrung.
     *   zu        closedSql - der Guide hat beendet, oder die Frist fuer den
     *             Wiedereinstieg ist verstrichen.
     *
     * GERECHNET UND NICHT NUR AUS DER SPALTE GELESEN: Ein vergessener
     * Abschluss macht die Fuehrung nach der Frist trotzdem bewertbar, ohne
     * dass der Cronjob gelaufen sein muss. Wer hier nur `status = 'done'`
     * prueft, verschiebt die Bewertung auf den naechsten Lauf eines Jobs, der
     * womoeglich gar nicht eingerichtet ist.
     *
     * @param string $in_alias
     * @return string SQL-Bedingung
     */
    public static function conductedSql(string $in_alias = 'r'): string
    {
        $a = self::alias($in_alias);

        return "($a.started_at IS NOT NULL AND " . self::closedSql($a) . ")";
    }

    /**
     * Laeuft diese Fuehrung noch - als SQL-Bedingung?
     *
     * Das Gegenstueck zu closedSql, und die Bedingung, an der drei Dinge
     * haengen: der Wiedereinstieg beider Seiten, der Knopf "Fuehrung beenden"
     * beim Guide und die Zahl in seiner Kopfleiste.
     *
     * BEGONNEN UND NICHT ZU. Eine zugesagte, aber nie begonnene Fuehrung
     * laeuft nicht - sie steht noch aus, und dafuer gibt es das Zeitfenster um
     * den Wunschzeitpunkt (callableSql).
     *
     * @param string $in_alias
     * @return string SQL-Bedingung
     */
    public static function runningSql(string $in_alias = 'r'): string
    {
        $a = self::alias($in_alias);

        return "($a.status = '" . self::STATUS_ACCEPTED . "'
                 AND $a.started_at IS NOT NULL
                 AND NOT " . self::closedSql($a) . ")";
    }

    /**
     * Hat der Guide auf diese Anfrage NIE geantwortet - als SQL-Bedingung?
     *
     * DER ERSTE DER BEIDEN ARBEITSVORRAETE DER VERWALTUNG. Was er meint, ist
     * eng gefasst und deshalb aussagekraeftig: Ein Kunde hat gefragt, die
     * Frist ist verstrichen, und es kam weder eine Zusage noch eine Absage.
     *
     *   decided_at IS NULL   Weder angenommen noch abgelehnt. Nur antwort()
     *                        setzt diese Spalte - eine ABSAGE zaehlt also
     *                        NICHT als unbeantwortet. Ein Guide, der ablehnt,
     *                        hat geantwortet.
     *   started_at IS NULL   Es kam auch kein Gespraech zustande.
     *   abgelaufen           Entweder gerechnet (status 'open', Frist
     *                        verstrichen) oder bereits festgeschrieben
     *                        (status 'expired', der Cronjob war da). Beide
     *                        Faelle sind derselbe Vorgang, und die Auskunft
     *                        darf nicht davon abhaengen, ob ein Job laeuft.
     *
     * WAS NICHT DAZUGEHOERT: eine ZURUECKGEZOGENE Anfrage. Sie traegt
     * ebenfalls kein decided_at, aber den Status 'cancelled' - da hat sich
     * jemand anders entschieden, und dem Guide ist nichts vorzuwerfen.
     *
     * @param string $in_alias
     * @return string SQL-Bedingung
     */
    public static function unansweredSql(string $in_alias = 'r'): string
    {
        $a = self::alias($in_alias);

        return "($a.decided_at IS NULL
                 AND $a.started_at IS NULL
                 AND (($a.status = '" . self::STATUS_OPEN . "' AND $a.expires_at <= NOW())
                      OR $a.status = '" . self::STATUS_EXPIRED . "'))";
    }

    /**
     * Faellt dieser Vorgang noch in das Zeitfenster eines Arbeitsvorrats?
     *
     * WARUM EIN VORRAT EIN ZEITFENSTER BRAUCHT: Er laesst sich nicht
     * abhaken. Eine Anfrage, die vor einem halben Jahr unbeantwortet
     * verfallen ist, bleibt das fuer immer - ohne Fenster waere die Zahl auf
     * der Uebersicht eine, die nur waechst und die nach kurzer Zeit niemand
     * mehr ansieht. Das Gegenteil eines Arbeitsvorrats.
     *
     * VIERZEHN TAGE, und die Zahl hat einen Grund: Eine Anfrage darf sich
     * hoechstens zwei Wochen im Voraus stellen (config/requests.php,
     * lead_time_max). Ein kuerzeres Fenster liesse eine lange vorher
     * gestellte Anfrage schon wieder herausfallen, bevor jemand sie gesehen
     * hat.
     *
     * Gerechnet wird ab dem ABLAUF und nicht ab dem Anlegen: Bis dahin war
     * die Anfrage in Ordnung.
     *
     * @param string $in_alias
     * @return string SQL-Bedingung
     */
    public static function imVorratSql(string $in_alias = 'r'): string
    {
        $a    = self::alias($in_alias);
        $tage = (int)self::VORRAT_TAGE;

        return "($a.expires_at >= DATE_SUB(NOW(), INTERVAL $tage DAY))";
    }

    /**
     * Darf zu dieser Anfrage JETZT angerufen werden - als SQL-Ausdruck?
     *
     * DIE EINE BEDINGUNG, an der drei Stellen haengen: der Knopf beim Kunden,
     * die Zulassung des Anrufs im Signaling und das Festhalten des Beginns.
     * Sie steht deshalb hier und nicht dreimal nachgebaut.
     *
     * ZWEI FAELLE, und sie haben verschiedene Uhren:
     *
     *   DER ERSTE START. Erlaubt im vereinbarten ZEITFENSTER um den
     *   Wunschzeitpunkt (config/requests.php: call_window_before / _after).
     *   Das ist die Verabredung.
     *
     *   DER WIEDEREINSTIEG. Eine begonnene und noch nicht beendete Fuehrung
     *   ist anrufbar, solange die Frist seit dem letzten Auflegen laeuft
     *   (runningSql) - unabhaengig vom Zeitfenster. Bricht die Verbindung um
     *   17:59 ab und endete das Fenster um 18:00, waere die Fuehrung sonst
     *   mitten im Satz vorbei.
     *
     * HIER STAND FRUEHER 'done' NEBEN 'accepted' - genau das war der Fehler:
     * Eine abgeschlossene Fuehrung blieb anrufbar, solange das Zeitfenster
     * lief, und der Kunde konnte sie beliebig oft neu starten. Was zu ist,
     * ist jetzt nicht mehr anrufbar; was unterbrochen ist, faellt in den
     * zweiten Fall und ist nicht mehr 'done' (migrations/017).
     *
     * Abgelehnt, abgebrochen und abgelaufen sind nie anrufbar.
     *
     * @param string $in_alias Tabellenalias in der Abfrage
     * @return string SQL-Bedingung
     */
    public static function callableSql(string $in_alias = 'r'): string
    {
        $a      = self::alias($in_alias);
        $config = self::config();
        $vor    = (int)$config['call_window_before'];
        $nach   = (int)$config['call_window_after'];

        return "(($a.status = '" . self::STATUS_ACCEPTED . "'
                  AND $a.started_at IS NULL
                  AND NOW() >= DATE_SUB($a.wish_at, INTERVAL $vor SECOND)
                  AND NOW() <= DATE_ADD($a.wish_at, INTERVAL $nach SECOND))
                 OR " . self::runningSql($a) . ")";
    }

    /**
     * Nur Buchstaben und Unterstriche im Tabellenalias.
     *
     * Der Alias kommt ausschliesslich aus diesem Projekt und nie von aussen.
     * Er geht aber als Textbaustein in eine Abfrage, und ein Textbaustein in
     * einer Abfrage wird geprueft - unabhaengig davon, wer ihn heute setzt.
     *
     * @param string $in_alias
     * @return string
     */
    private static function alias(string $in_alias): string
    {
        $sauber = preg_replace('/[^a-zA-Z_]/', '', $in_alias);
        return $sauber === '' ? 'r' : $sauber;
    }

    // =================================================================
    // ANLEGEN
    // =================================================================

    /**
     * Stellt eine Anfrage.
     *
     * DER WUNSCHZEITPUNKT KOMMT ALS ABSTAND, NICHT ALS DATUM. Der Aufrufer
     * sagt "in so vielen Sekunden", und die DATENBANK rechnet daraus einen
     * Zeitpunkt. Das ist kein Umweg, sondern die Vermeidung einer ganzen
     * Fehlerklasse: Ein von PHP formatiertes Datum wuerde gegen NOW() der
     * Datenbank verglichen, und beide Uhren stehen in ihrer eigenen Zeitzone.
     * Ein Abstand hat keine Zeitzone. "Jetzt sofort" ist damit schlicht die
     * Null - ein Wert unter anderen, kein Sonderfall.
     *
     * Der Ablaufzeitpunkt wird HIER gerechnet und nicht spaeter: Er haengt an
     * den Fristen, die zum Zeitpunkt des Stellens galten, und bleibt damit
     * nachvollziehbar, auch wenn jemand config/requests.php aendert. Es
     * gewinnt die fruehere der beiden Fristen - die Antwortfrist und der um
     * die Karenz verlaengerte Wunschzeitpunkt.
     *
     * Die Kennung des Guides kommt vom AUFRUFER aus dem Standort und nicht
     * aus der Anfrage des Kunden (App\Controller\RequestController::create).
     *
     * @param int $in_location_id
     * @param int $in_guide_id     Eigentuemer des Standorts
     * @param int $in_customer_id  Wer anfragt
     * @param int $in_wish_seconds Abstand des Wunschzeitpunkts von jetzt;
     *                             0 heisst "jetzt sofort"
     * @return int|null Neue Anfrage-ID, null bei Fehler
     */
    public static function create($in_location_id, $in_guide_id, $in_customer_id, $in_wish_seconds): ?int
    {
        $location = (int)$in_location_id;
        $guide    = (int)$in_guide_id;
        $customer = (int)$in_customer_id;
        $wunsch   = max(0, (int)$in_wish_seconds);

        if ($location < 1 || $guide < 1 || $customer < 1) return null;
        // Sich selbst fragt niemand an. Der Controller weist das ebenfalls ab -
        // hier steht es noch einmal, weil eine Zeile mit gleichem Guide und
        // Kunden in keiner Auswertung etwas zu suchen haette.
        if ($guide === $customer) return null;

        $config = self::config();
        // Der fruehere der beiden Ablaufgruende gewinnt. Gerechnet wird er in
        // Sekunden ab jetzt, damit auch er ohne Datum und ohne Zeitzone
        // auskommt.
        $ablauf = min(
            (int)$config['response_timeout'],
            $wunsch + (int)$config['wish_grace']
        );

        try {
            $query = "INSERT INTO tour_request
                          (location_id, guide_user_id, customer_user_id, status,
                           wish_at, expires_at, created_at)
                      VALUES
                          (:location, :guide, :customer, '" . self::STATUS_OPEN . "',
                           DATE_ADD(NOW(), INTERVAL :wunsch SECOND),
                           DATE_ADD(NOW(), INTERVAL :ablauf SECOND),
                           NOW())";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':location', $location, \PDO::PARAM_INT);
            $stmt->bindParam(':guide',    $guide,    \PDO::PARAM_INT);
            $stmt->bindParam(':customer', $customer, \PDO::PARAM_INT);
            $stmt->bindParam(':wunsch',   $wunsch,   \PDO::PARAM_INT);
            $stmt->bindParam(':ablauf',   $ablauf,   \PDO::PARAM_INT);
            $stmt->execute();

            return (int)PdoConnect::$connection->lastInsertId();
        } catch (\PDOException $e) {
            error_log('Fehler beim Anlegen einer Anfrage: ' . $e->getMessage());
            return null;
        }
    }

    // =================================================================
    // LESEN
    // =================================================================

    /**
     * Die Spalten, die eine Anfrage nach aussen hat.
     *
     * Der Zustand ist der GERECHNETE (siehe statusSql), und 'callable' sagt,
     * ob gerade angerufen werden darf. Beides fertig ausgewertet, damit keine
     * Lesestelle es selbst nachbaut.
     *
     * @param string $in_alias
     * @return string Spaltenliste fuer ein SELECT
     */
    private static function spalten(string $in_alias = 'r'): string
    {
        $a = self::alias($in_alias);
        $config = self::config();
        $rejoin = (int)$config['rejoin_window'];

        return "$a.id, $a.location_id, $a.guide_user_id, $a.customer_user_id,
                $a.wish_at, $a.created_at, $a.expires_at,
                $a.decided_at, $a.started_at, $a.ended_at, $a.closed_at,
                " . self::statusSql($a) . " AS status,
                " . self::callableSql($a) . " AS callable,
                -- LAEUFT NOCH: begonnen und nicht beendet. Daran haengen der
                -- Wiedereinstieg auf beiden Seiten und der Knopf \"Fuehrung
                -- beenden\" beim Guide. Die Ansicht rechnet nichts nach.
                " . self::runningSql($a) . " AS running,
                -- Wie lange der Wiedereinstieg noch offen steht. Negativ oder
                -- NULL heisst: nicht mehr. Der Browser zeigt damit an, wie
                -- lange die Fuehrung noch zu retten ist, ohne selbst eine
                -- Frist zu kennen.
                CASE WHEN $a.ended_at IS NULL THEN NULL
                     ELSE TIMESTAMPDIFF(SECOND, NOW(),
                              DATE_ADD($a.ended_at, INTERVAL $rejoin SECOND))
                END AS rejoin_in,
                TIMESTAMPDIFF(SECOND, NOW(), $a.wish_at)    AS wish_in,
                TIMESTAMPDIFF(SECOND, NOW(), $a.expires_at) AS expires_in";
    }

    /**
     * Die Sortierung beider Listen.
     *
     * Zuerst, was Handlung verlangt: offene Anfragen, dann angenommene, dann
     * alles Erledigte. Innerhalb der ersten beiden Gruppen steht der
     * NAECHSTE Termin oben - dort geht es um das, was gleich ansteht.
     * Im Erledigten steht das JUENGSTE oben, denn dort geht es um das, was
     * gerade war.
     *
     * Beides in einem aufsteigenden Ausdruck: Fuer die erledigten Zeilen wird
     * der Zeitstempel negiert, womit "spaeter" zu "kleiner" wird.
     *
     * @param string $in_alias
     * @return string ORDER-BY-Klausel ohne das Schluesselwort
     */
    private static function sortierung(string $in_alias = 'r'): string
    {
        $a      = self::alias($in_alias);
        $status = self::statusSql($a);

        return "CASE $status
                    WHEN '" . self::STATUS_OPEN . "'     THEN 0
                    WHEN '" . self::STATUS_ACCEPTED . "' THEN 1
                    ELSE 2
                END ASC,
                CASE
                    WHEN $status IN ('" . self::STATUS_OPEN . "', '" . self::STATUS_ACCEPTED . "')
                         THEN  UNIX_TIMESTAMP($a.wish_at)
                    ELSE     -UNIX_TIMESTAMP($a.wish_at)
                END ASC";
    }

    /**
     * Was liegt bei diesem Guide an?
     *
     * Mit Standort und Kundennamen, damit die Liste ohne zweite Abfrage
     * lesbar ist. LEFT JOIN, weil diese Tabelle keine Fremdschluessel hat:
     * Ein geloeschter Standort nimmt die Aufzeichnung der Fuehrung nicht mit,
     * und dann fehlt eben der Titel.
     *
     * @param int $in_guide_id
     * @param int $in_limit
     * @return array<int,array<string,mixed>>
     */
    public static function forGuide($in_guide_id, int $in_limit = 100): array
    {
        return self::liste('r.guide_user_id = :id', 'customer_user_id', $in_guide_id, $in_limit);
    }

    /**
     * Was habe ich als Kunde gestellt?
     *
     * @param int $in_customer_id
     * @param int $in_limit
     * @return array<int,array<string,mixed>>
     */
    public static function forCustomer($in_customer_id, int $in_limit = 100): array
    {
        return self::liste('r.customer_user_id = :id', 'guide_user_id', $in_customer_id, $in_limit);
    }

    /**
     * Die gemeinsame Abfrage beider Listen.
     *
     * Sie unterscheiden sich in genau zwei Dingen: nach welcher Seite
     * gefiltert wird und wessen Name als "Gegenueber" dazugehoert. Alles
     * andere - Spalten, Sortierung, Grenze - ist dasselbe und steht deshalb
     * nur einmal da.
     *
     * @param string $in_bedingung  WHERE-Klausel mit dem Platzhalter :id
     * @param string $in_partner    Spalte, deren Benutzername geholt wird
     * @param int    $in_user_id
     * @param int    $in_limit
     * @return array<int,array<string,mixed>>
     */
    private static function liste(string $in_bedingung, string $in_partner, $in_user_id, int $in_limit): array
    {
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return [];

        // Die Grenze geht als Zahl in den Text: LIMIT vertraegt in MySQL
        // keinen gebundenen Parameter, solange PDO nicht emuliert.
        $limit   = max(1, min(500, $in_limit));
        // Der Spaltenname kommt aus diesem Modell und nie von aussen; die
        // Pruefung steht trotzdem da, weil er ein Textbaustein ist.
        $partner = in_array($in_partner, ['guide_user_id', 'customer_user_id'], true)
                 ? $in_partner : 'guide_user_id';

        try {
            $query = "SELECT " . self::spalten('r') . ",
                             l.title, l.description,
                             city.city_name, country.country_name,
                             partner.username AS partner_name,
                             -- DIE BEWERTUNG DIESER FUEHRUNG, falls es eine
                             -- gibt. Sie steht hier, weil die Anfragenseite
                             -- der Ort ist, an dem eine uebersprungene
                             -- Bewertung wieder auftaucht: Dort braucht jede
                             -- durchgefuehrte Zeile die Auskunft, ob noch
                             -- etwas zu tun ist.
                             --
                             -- ZWEI ANGABEN, WEIL SIE ZWEI FRAGEN
                             -- BEANTWORTEN: 'reviewed' sagt, ob ueberhaupt
                             -- bewertet wurde - eine ENTFERNTE Bewertung
                             -- zaehlt dabei mit, denn dieselbe Fuehrung laesst
                             -- sich danach nicht erneut bewerten.
                             -- 'review_stars' sagt, was sichtbar dasteht, und
                             -- ist bei einer entfernten Bewertung leer.
                             rev.id IS NOT NULL AS reviewed,
                             CASE WHEN rev.removed_at IS NULL THEN rev.stars END AS review_stars
                      FROM tour_request r
                      LEFT JOIN location l      ON l.id = r.location_id
                      LEFT JOIN city            ON city.id = l.city_id
                      LEFT JOIN country         ON country.id = city.country_id
                      LEFT JOIN user partner    ON partner.id = r.$partner
                      LEFT JOIN tour_review rev ON rev.request_id = r.id
                      WHERE $in_bedingung
                      ORDER BY " . self::sortierung('r') . "
                      LIMIT $limit";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':id', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden der Anfragen: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Die Anfrage, die dieser Kunde an diesem Standort gerade laufen hat.
     *
     * "Laufend" heisst: offen oder angenommen - beides Zustaende, in denen
     * eine zweite Anfrage an denselben Standort nichts Neues sagen wuerde.
     * Abgelaufenes, Abgelehntes und Erledigtes zaehlt nicht; danach darf
     * wieder gefragt werden.
     *
     * Von hier bekommt auch die Standortseite ihren Zustand: Der Kunde soll
     * dort sehen, ob seine Anfrage noch offen ist, angenommen wurde - und ob
     * er jetzt losstarten darf.
     *
     * @param int $in_customer_id
     * @param int $in_location_id
     * @return array<string,mixed>|null
     */
    public static function currentForCustomer($in_customer_id, $in_location_id): ?array
    {
        $customer = (int)$in_customer_id;
        $location = (int)$in_location_id;
        if ($customer < 1 || $location < 1) return null;

        try {
            $query = "SELECT " . self::spalten('r') . "
                      FROM tour_request r
                      WHERE r.customer_user_id = :customer
                        AND r.location_id      = :location
                        AND " . self::statusSql('r') . " IN
                            ('" . self::STATUS_OPEN . "', '" . self::STATUS_ACCEPTED . "')
                      ORDER BY r.id DESC
                      LIMIT 1";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':customer', $customer, \PDO::PARAM_INT);
            $stmt->bindParam(':location', $location, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $zeile ?: null;
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden der eigenen Anfrage: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Die beiden Zahlen fuer die Kopfleiste.
     *
     * WAS SIE BEDEUTEN - und warum es genau diese zwei sind: Sie stehen fuer
     * das, was der Betrachter TUN kann.
     *
     *   incoming_open       Anfragen an ihn als Guide, die auf eine Antwort
     *                       warten.
     *   outgoing_accepted   eigene Anfragen, die angenommen wurden und noch
     *                       nicht gelaufen sind - dort wartet die Fuehrung
     *                       auf ihn. Eine begonnene zaehlt nicht mehr mit:
     *                       Sie wartet nicht, sie laeuft.
     *   tours_running       eigene Fuehrungen, die begonnen und nicht beendet
     *                       sind. Sie sind der DRITTE Fall von "hier wartet
     *                       etwas auf dich": Der Guide muss sie beenden,
     *                       sonst bleibt der Startknopf beim Kunden stehen
     *                       und die Bewertung wird nie faellig. Wer die Karte
     *                       nach dem Auflegen weggeklickt hat, findet sie
     *                       ueber diese Zahl wieder.
     *
     * Sie gehen mit der Antwort des Heartbeats mit und nicht ueber eine
     * eigene Abfrage im Takt: Der Heartbeat laeuft ohnehin alle zehn
     * Sekunden, und eine zweite Schleife daneben waere derselbe Weg noch
     * einmal (App\Controller\UserController::heartbeat).
     *
     * EINE Abfrage fuer beide Zahlen, denn es ist dieselbe Zeilenmenge.
     *
     * @param int $in_user_id
     * @return array{incoming_open:int, outgoing_accepted:int, tours_running:int}
     */
    public static function counters($in_user_id): array
    {
        $leer    = ['incoming_open' => 0, 'outgoing_accepted' => 0, 'tours_running' => 0];
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return $leer;

        $status = self::statusSql('r');

        try {
            $query = "SELECT
                        SUM(r.guide_user_id = :guide
                            AND $status = '" . self::STATUS_OPEN . "')     AS incoming_open,
                        SUM(r.customer_user_id = :customer
                            AND r.started_at IS NULL
                            AND $status = '" . self::STATUS_ACCEPTED . "') AS outgoing_accepted,
                        SUM(r.guide_user_id = :guide3
                            AND " . self::runningSql('r') . ")             AS tours_running
                      FROM tour_request r
                      WHERE (r.guide_user_id = :guide2 OR r.customer_user_id = :customer2)
                        AND r.status IN ('" . self::STATUS_OPEN . "', '" . self::STATUS_ACCEPTED . "')";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':guide',     $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':guide2',    $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':guide3',    $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':customer',  $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':customer2', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$zeile) return $leer;

            return [
                'incoming_open'     => (int)($zeile['incoming_open'] ?? 0),
                'outgoing_accepted' => (int)($zeile['outgoing_accepted'] ?? 0),
                'tours_running'     => (int)($zeile['tours_running'] ?? 0),
            ];
        } catch (\PDOException $e) {
            error_log('Fehler beim Zaehlen der Anfragen: ' . $e->getMessage());
            return $leer;
        }
    }

    // =================================================================
    // DIE VERWALTUNG: ALLE ANFRAGEN, UND WAS DAVON ARBEIT IST
    //
    // Zugang ueber das Recht request.list_all, das nur die Verwaltung hat.
    // Beide Abfragen ZEIGEN; geaendert wird an einer Anfrage hier nichts -
    // siehe die Begruendung bei App\Helper\Permission::REQUEST_LIST_ALL.
    // =================================================================

    /**
     * Die beiden Arbeitsvorraete als Zahlen.
     *
     * SIE STEHEN AUF DER UEBERSICHT DER VERWALTUNG, und beide meinen etwas,
     * das heute an keiner Stelle auffaellt:
     *
     *   haengend       Fuehrungen, die begonnen haben und die niemand
     *                  beendet hat. Solange eine offen ist, steht beim
     *                  Kunden der Startknopf, und die Bewertung wird nicht
     *                  faellig - der Guide sieht das in seiner Kopfleiste,
     *                  aber nur fuer sich selbst.
     *   unbeantwortet  Anfragen, die ohne jede Antwort verfallen sind. Der
     *                  Kunde hat gewartet und nichts bekommen; gemerkt hat
     *                  das bisher niemand ausser ihm.
     *
     * DER ZWEITE ZAEHLER TRAEGT EIN ZEITFENSTER, der erste nicht - und das
     * ist kein Versehen: Eine haengende Fuehrung loest sich von selbst auf
     * (nach der Frist gilt sie als durchgefuehrt, closedSql), sie kann also
     * gar nicht auflaufen. Eine unbeantwortete Anfrage bleibt fuer immer
     * unbeantwortet und braucht deshalb imVorratSql().
     *
     * EINE ABFRAGE FUER BEIDE ZAHLEN: Sie zaehlen ueber dieselbe Tabelle.
     *
     * @return array{haengend:int, unbeantwortet:int}
     */
    public static function adminCounters(): array
    {
        $laufend = self::runningSql('r');
        $offen   = self::unansweredSql('r');
        $fenster = self::imVorratSql('r');

        try {
            $stmt = PdoConnect::$connection->query(
                "SELECT SUM($laufend)            AS haengend,
                        SUM($offen AND $fenster) AS unbeantwortet
                   FROM tour_request r"
            );
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($zeile !== false) {
                return [
                    'haengend'      => (int)$zeile['haengend'],
                    'unbeantwortet' => (int)$zeile['unbeantwortet'],
                ];
            }
        } catch (\PDOException $e) {
            error_log('TourRequest::adminCounters: ' . $e->getMessage());
        }

        return ['haengend' => 0, 'unbeantwortet' => 0];
    }

    /**
     * Die Anfragenliste der Verwaltung.
     *
     * WARUM SIE NEBEN forGuide()/forCustomer() STEHT: Die beiden dort sind
     * an eine Kennung gebunden (WHERE guide_user_id = :id) und liefern genau
     * eine Seite der Verabredung. Diese hier ist an KEINE gebunden und
     * liefert beide Seiten mit Namen - das ist ein anderer Zugriff und
     * deshalb eine andere Abfrage, kein zusaetzlicher Parameter an einer
     * bestehenden. Ein Schalter "und wenn Admin, dann ohne WHERE" waere
     * genau die Stelle, an der so etwas eines Tages versehentlich aufgeht.
     *
     * WAS SIE MEHR LIEFERT: BEIDE Benutzernamen. In der Liste des Guides
     * steht ein Partnername, weil er den einen Gegenueber kennt; hier
     * braucht es beide - sonst laesst sich nicht sehen, ob dieselben zwei
     * Konten dreimal aneinander vorbeigelaufen sind.
     *
     * @param string $in_filter 'haengend', 'unbeantwortet', 'offen' oder 'alle'
     * @param int    $in_limit  Obergrenze der Zeilen
     * @return array<int,array<string,mixed>>
     */
    public static function allForAdmin(string $in_filter = 'haengend', int $in_limit = 200): array
    {
        // Ein Textbaustein in einer Abfrage wird nachgeschlagen und nicht
        // zusammengesetzt - der Filter kommt aus der Adresszeile.
        $wo = [
            'haengend'      => self::runningSql('r'),
            'unbeantwortet' => self::unansweredSql('r') . ' AND ' . self::imVorratSql('r'),
            'offen'         => "(r.status = '" . self::STATUS_OPEN . "' AND r.expires_at > NOW())",
            'alle'          => '1',
        ];
        $where = $wo[$in_filter] ?? $wo['haengend'];

        // WONACH SORTIERT WIRD, haengt vom Vorrat ab, und bei beiden Vorraeten
        // steht das Aelteste oben: Bei den haengenden ist die laengst begonnene
        // die dringendste, bei den unbeantworteten die laengst verfallene. Bei
        // den OFFENEN dagegen laeuft die Frist noch - dort steht oben, was
        // zuerst verfaellt.
        $sortierung = [
            'haengend'      => 'r.started_at ASC',
            'unbeantwortet' => 'r.expires_at ASC',
            'offen'         => 'r.expires_at ASC',
            'alle'          => 'r.created_at DESC',
        ];
        $order = $sortierung[$in_filter] ?? $sortierung['haengend'];

        // LIMIT vertraegt in MySQL keinen gebundenen Parameter, solange PDO
        // nicht emuliert.
        $limit = max(1, min(1000, $in_limit));

        try {
            $query = "SELECT " . self::spalten('r') . ",
                             -- Wie lange die Fuehrung schon laeuft. Die
                             -- Auskunft, um die es im Vorrat der haengenden
                             -- geht: eine Fuehrung seit zehn Minuten ist
                             -- normal, eine seit drei Stunden nicht.
                             TIMESTAMPDIFF(SECOND, r.started_at, NOW()) AS running_since,
                             l.title,
                             city.city_name, country.country_name,
                             g.username AS guide_username,
                             k.username AS customer_username,
                             COALESCE(NULLIF(gp.display_name, ''), g.username)
                                 AS guide_name
                      FROM tour_request r
                      LEFT JOIN location l       ON l.id = r.location_id
                      LEFT JOIN city             ON city.id = l.city_id
                      LEFT JOIN country          ON country.id = city.country_id
                      LEFT JOIN user g           ON g.id = r.guide_user_id
                      LEFT JOIN user k           ON k.id = r.customer_user_id
                      LEFT JOIN guide_profile gp ON gp.user_id = r.guide_user_id
                     WHERE $where
                     ORDER BY $order
                     LIMIT $limit";
            $stmt = PdoConnect::$connection->query($query);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log('TourRequest::allForAdmin: ' . $e->getMessage());
            return [];
        }
    }

    // =================================================================
    // ANTWORTEN
    // =================================================================

    /**
     * Der Guide nimmt an.
     *
     * DIE ZUSTAENDIGKEIT STEHT IN DER WHERE-KLAUSEL und nicht nur im
     * Controller - dieselbe Regel wie beim Standort: Eine Rechtetabelle kann
     * nicht wissen, welche Anfrage an wen gerichtet ist. Mitgeprueft wird der
     * Ablauf: Eine verstrichene Anfrage laesst sich nicht mehr annehmen, auch
     * wenn der Cronjob sie noch nicht angefasst hat.
     *
     * @param int $in_id
     * @param int $in_guide_id
     * @return bool true, wenn wirklich eine Zeile getroffen wurde
     */
    public static function accept($in_id, $in_guide_id): bool
    {
        return self::antwort($in_id, $in_guide_id, self::STATUS_ACCEPTED);
    }

    /**
     * Der Guide lehnt ab.
     *
     * @param int $in_id
     * @param int $in_guide_id
     * @return bool
     */
    public static function decline($in_id, $in_guide_id): bool
    {
        return self::antwort($in_id, $in_guide_id, self::STATUS_DECLINED);
    }

    /**
     * Annehmen und Ablehnen sind dieselbe Entscheidung mit zwei Ausgaengen.
     *
     * @param int    $in_id
     * @param int    $in_guide_id
     * @param string $in_status
     * @return bool
     */
    private static function antwort($in_id, $in_guide_id, string $in_status): bool
    {
        $id    = (int)$in_id;
        $guide = (int)$in_guide_id;
        if ($id < 1 || $guide < 1) return false;
        if (!in_array($in_status, [self::STATUS_ACCEPTED, self::STATUS_DECLINED], true)) return false;

        try {
            $query = "UPDATE tour_request
                         SET status = :status, decided_at = NOW()
                       WHERE id = :id
                         AND guide_user_id = :guide
                         AND status = '" . self::STATUS_OPEN . "'
                         AND expires_at > NOW()";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':status', $in_status);
            $stmt->bindParam(':id',     $id,    \PDO::PARAM_INT);
            $stmt->bindParam(':guide',  $guide, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('Fehler beim Beantworten einer Anfrage: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Zurueckziehen - von beiden Seiten.
     *
     * Der Kunde nimmt seine Anfrage zurueck, der Guide sagt eine bereits
     * zugesagte Fuehrung ab. Beides ist derselbe Vorgang und derselbe
     * Zustand: abgebrochen.
     *
     * NICHT MEHR MOEGLICH, SOBALD DIE FUEHRUNG LIEF. Was stattgefunden hat,
     * wird nicht nachtraeglich zu "abgebrochen" - daran haengt spaeter eine
     * Abrechnung.
     *
     * Die Beteiligung steht in der WHERE-Klausel: Eine fremde Anfrage
     * abzubrechen trifft keine Zeile.
     *
     * @param int $in_id
     * @param int $in_user_id Kunde ODER Guide dieser Anfrage
     * @return bool
     */
    public static function cancel($in_id, $in_user_id): bool
    {
        $id      = (int)$in_id;
        $user_id = (int)$in_user_id;
        if ($id < 1 || $user_id < 1) return false;

        try {
            $query = "UPDATE tour_request
                         SET status = '" . self::STATUS_CANCELLED . "'
                       WHERE id = :id
                         AND (customer_user_id = :user OR guide_user_id = :user2)
                         AND status IN ('" . self::STATUS_OPEN . "', '" . self::STATUS_ACCEPTED . "')
                         AND started_at IS NULL";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':id',    $id,      \PDO::PARAM_INT);
            $stmt->bindParam(':user',  $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':user2', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('Fehler beim Zuruecknehmen einer Anfrage: ' . $e->getMessage());
            return false;
        }
    }

    // =================================================================
    // DER ANRUF
    // =================================================================

    /**
     * Gibt es fuer diesen Anruf eine angenommene Anfrage, die JETZT gilt?
     *
     * Die zweite Tuer in die Fuehrung, neben dem Bereitschaftsschalter
     * (App\Controller\WebRTCController::callRoles). Sie ist die staerkere
     * Zusage: Der Guide hat fuer genau diesen Zeitpunkt und genau diesen
     * Kunden zugesagt. Der Schalter sagt dagegen "ich kann jetzt sofort" und
     * gilt fuer jeden.
     *
     * Geprueft wird das Tripel - Kunde, Guide UND Standort. Eine Zusage fuer
     * einen anderen Standort desselben Guides oeffnet nichts.
     *
     * @param int $in_customer_id Der Anrufer
     * @param int $in_guide_id    Der Angerufene
     * @param int $in_location_id Der Standort aus dem Offer
     * @return bool
     */
    public static function acceptedForCall($in_customer_id, $in_guide_id, $in_location_id): bool
    {
        $customer = (int)$in_customer_id;
        $guide    = (int)$in_guide_id;
        $location = (int)$in_location_id;
        if ($customer < 1 || $guide < 1 || $location < 1) return false;

        try {
            $query = "SELECT r.id
                        FROM tour_request r
                       WHERE r.customer_user_id = :customer
                         AND r.guide_user_id    = :guide
                         AND r.location_id      = :location
                         AND " . self::callableSql('r') . "
                       LIMIT 1";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':customer', $customer, \PDO::PARAM_INT);
            $stmt->bindParam(':guide',    $guide,    \PDO::PARAM_INT);
            $stmt->bindParam(':location', $location, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(\PDO::FETCH_ASSOC) !== false;
        } catch (\PDOException $e) {
            error_log('Fehler beim Pruefen einer angenommenen Anfrage: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Haelt den Beginn der Fuehrung fest.
     *
     * Aufgerufen vom Signaling, wenn ein Offer mit Standortkennung
     * durchgelassen wurde. Nur beim ERSTEN Mal: Bricht die Verbindung ab und
     * wird neu angerufen, bleibt der urspruengliche Beginn stehen - er ist
     * der Anfang der Fuehrung und nicht der Anfang der letzten Leitung.
     *
     * @param int $in_customer_id
     * @param int $in_guide_id
     * @param int $in_location_id
     * @return bool true, wenn ein Beginn gesetzt wurde
     */
    public static function markStarted($in_customer_id, $in_guide_id, $in_location_id): bool
    {
        $customer = (int)$in_customer_id;
        $guide    = (int)$in_guide_id;
        $location = (int)$in_location_id;
        if ($customer < 1 || $guide < 1 || $location < 1) return false;

        try {
            $query = "UPDATE tour_request r
                         SET r.started_at = NOW()
                       WHERE r.customer_user_id = :customer
                         AND r.guide_user_id    = :guide
                         AND r.location_id      = :location
                         AND r.started_at IS NULL
                         AND " . self::callableSql('r') . "
                       ORDER BY r.id DESC
                       LIMIT 1";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':customer', $customer, \PDO::PARAM_INT);
            $stmt->bindParam(':guide',    $guide,    \PDO::PARAM_INT);
            $stmt->bindParam(':location', $location, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('Fehler beim Festhalten des Fuehrungsbeginns: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Haelt fest, dass gerade aufgelegt wurde.
     *
     * DAS IST NICHT DAS ENDE DER FUEHRUNG, und genau darin lag der Fehler:
     * Auflegen ist zweideutig. Es kann heissen "wir sind fertig" - oder "das
     * Netz ist weg". Vorher setzte jedes Auflegen den Zustand auf 'done'; die
     * Fuehrung war damit abgeschlossen, blieb aber ueber das Zeitfenster
     * weiter anrufbar (der alte callableSql liess 'done' zu). Der Kunde
     * konnte sie beliebig oft neu starten, und die Frage nach der Bewertung
     * kam, waehrend der Guide noch zurueck in die Leitung wollte.
     *
     * Geschrieben wird deshalb nur noch der ZEITPUNKT. Beendet wird
     * ausdruecklich vom Guide (finish); bis dahin gilt die Fuehrung als
     * unterbrochen, und ab diesem Zeitpunkt laeuft die Frist fuer den
     * Wiedereinstieg (config/requests.php: rejoin_window, ausgewertet in
     * closedSql).
     *
     * UEBERSCHRIEBEN WIRD BEI JEDEM AUFLEGEN. Steigt jemand wieder ein und
     * legt erneut auf, faengt die Frist von vorn an - sie zaehlt ab dem
     * letzten Auflegen und nicht ab dem ersten. Deshalb steht hier, anders
     * als frueher, kein "ended_at IS NULL" in der Bedingung.
     *
     * Aufgerufen vom Signaling beim 'hangup'. WELCHE SEITE AUFLEGT, IST
     * OFFEN: Der Guide kann es sein oder der Kunde. Deshalb wird das Paar in
     * beide Richtungen geprueft und nicht angenommen, der Absender sei der
     * Kunde.
     *
     * Getroffen wird nur eine Fuehrung, die BEGONNEN und noch nicht beendet
     * ist. Ein Anruf, der nie zustande kam, hinterlaesst hier nichts, und
     * eine abgeschlossene Fuehrung bekommt kein neues Ende angehaengt.
     *
     * @param int $in_user_a Absender des hangup
     * @param int $in_user_b Empfaenger
     * @return bool true, wenn ein Auflegen festgehalten wurde
     */
    public static function markEnded($in_user_a, $in_user_b): bool
    {
        $a = (int)$in_user_a;
        $b = (int)$in_user_b;
        if ($a < 1 || $b < 1 || $a === $b) return false;

        try {
            $query = "UPDATE tour_request r
                         SET r.ended_at = NOW()
                       WHERE r.started_at IS NOT NULL
                         AND r.closed_at IS NULL
                         AND r.status = '" . self::STATUS_ACCEPTED . "'
                         AND ((r.customer_user_id = :a1 AND r.guide_user_id = :b1)
                           OR (r.customer_user_id = :b2 AND r.guide_user_id = :a2))
                       ORDER BY r.started_at DESC
                       LIMIT 1";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':a1', $a, \PDO::PARAM_INT);
            $stmt->bindParam(':a2', $a, \PDO::PARAM_INT);
            $stmt->bindParam(':b1', $b, \PDO::PARAM_INT);
            $stmt->bindParam(':b2', $b, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('Fehler beim Festhalten des Auflegens: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Der GUIDE beendet die Fuehrung.
     *
     * DIE EINE STELLE, an der aus einer laufenden Fuehrung eine
     * durchgefuehrte wird - ausdruecklich und von der Seite, die es weiss.
     * Erst danach verschwindet der Startknopf beim Kunden (callableSql), erst
     * danach wird die Bewertung faellig (App\Model\TourReview::create prueft
     * denselben Zustand).
     *
     * WARUM DER GUIDE UND NICHT DER KUNDE: Der Guide ist vor Ort. Er weiss,
     * ob die Fuehrung vorbei ist oder ob er gerade nur durch einen Tunnel
     * faehrt. Der Kunde sieht in beiden Faellen dasselbe - eine abgebrochene
     * Verbindung.
     *
     * DIE ZUSTAENDIGKEIT STEHT IN DER WHERE-KLAUSEL und nicht nur im
     * Controller: guide_user_id = :guide. Eine Rechtetabelle kann nicht
     * wissen, wessen Fuehrung das ist.
     *
     * ended_at WIRD NICHT UEBERSCHRIEBEN, wenn es schon steht: Es ist das
     * Ende des GESPRAECHS, dieser Klick ist ein Verwaltungsakt und kommt
     * womoeglich zehn Minuten spaeter. Nur wenn nie ein Auflegen ankam, wird
     * es hier nachgetragen - dann ist dieser Zeitpunkt das Beste, was es
     * gibt.
     *
     * @param int $in_id
     * @param int $in_guide_id
     * @return bool true, wenn wirklich eine Zeile getroffen wurde
     */
    public static function finish($in_id, $in_guide_id): bool
    {
        $id    = (int)$in_id;
        $guide = (int)$in_guide_id;
        if ($id < 1 || $guide < 1) return false;

        try {
            $query = "UPDATE tour_request r
                         SET r.closed_at = NOW(),
                             r.ended_at  = COALESCE(r.ended_at, NOW()),
                             r.status    = '" . self::STATUS_DONE . "'
                       WHERE r.id             = :id
                         AND r.guide_user_id  = :guide
                         AND r.started_at IS NOT NULL
                         AND r.closed_at IS NULL";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':id',    $id,    \PDO::PARAM_INT);
            $stmt->bindParam(':guide', $guide, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('Fehler beim Beenden einer Fuehrung: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Die Fuehrung, die zwischen diesen beiden gerade LAEUFT.
     *
     * WOZU: Die Rollenvergabe im Signaling braucht eine zweite Quelle. Bisher
     * galt "wer angerufen wird, fuehrt" - das ist richtig, solange der Kunde
     * waehlt. Beim Wiedereinstieg darf aber auch der Guide waehlen, und dann
     * waere der KUNDE der Angerufene und damit der Guide, samt Steuerkreuz
     * auf den Falschen.
     *
     * In dieser Zeile steht, wer der Guide ist. Existiert zwischen den beiden
     * eine laufende Fuehrung, entscheidet sie ueber die Rollen - und nicht
     * die Frage, wer gewaehlt hat (App\Controller\WebRTCController::callRoles).
     *
     * Das erweitert die Rollenvergabe, ohne sie aufzuweichen: Der Guide
     * dieser Zeile wurde beim Anlegen der Anfrage aus dem STANDORT
     * uebernommen und nie behauptet, und die Zeile gilt nur, solange die
     * Fuehrung laeuft (runningSql). Niemand kann sich darueber eine Rolle
     * geben, die er nicht schon hatte.
     *
     * @param int $in_user_a
     * @param int $in_user_b
     * @return array<string,mixed>|null Zeile mit id, guide_user_id,
     *         customer_user_id und location_id - oder null
     */
    public static function runningBetween($in_user_a, $in_user_b): ?array
    {
        $a = (int)$in_user_a;
        $b = (int)$in_user_b;
        if ($a < 1 || $b < 1 || $a === $b) return null;

        try {
            $query = "SELECT r.id, r.guide_user_id, r.customer_user_id, r.location_id
                        FROM tour_request r
                       WHERE " . self::runningSql('r') . "
                         AND ((r.customer_user_id = :a1 AND r.guide_user_id = :b1)
                           OR (r.customer_user_id = :b2 AND r.guide_user_id = :a2))
                       ORDER BY r.started_at DESC
                       LIMIT 1";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':a1', $a, \PDO::PARAM_INT);
            $stmt->bindParam(':a2', $a, \PDO::PARAM_INT);
            $stmt->bindParam(':b1', $b, \PDO::PARAM_INT);
            $stmt->bindParam(':b2', $b, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $zeile ?: null;
        } catch (\PDOException $e) {
            error_log('Fehler beim Suchen einer laufenden Fuehrung: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Die Fuehrung, die dieser GUIDE gerade offen hat.
     *
     * Sie geht mit der Antwort des Heartbeats mit - wie die offene Bewertung
     * beim Kunden und aus demselben Grund: Nach dem Auflegen laedt die Seite
     * auf Telefonen neu, und was in diesem Moment auf dem Bildschirm stand,
     * waere weg. So findet der Guide die Karte "Fuehrung beenden" auf jeder
     * Seite wieder (App\Controller\UserController::heartbeat).
     *
     * EINE, nicht alle: Zwei Fuehrungen gleichzeitig gibt es nicht, und wer
     * doch zwei offene haette, bekaeme nicht zwei Karten uebereinander.
     *
     * @param int $in_guide_id
     * @return array<string,mixed>|null
     */
    public static function runningForGuide($in_guide_id): ?array
    {
        $guide = (int)$in_guide_id;
        if ($guide < 1) return null;

        try {
            $query = "SELECT " . self::spalten('r') . ",
                             l.title, city.city_name,
                             partner.username AS partner_name
                        FROM tour_request r
                        LEFT JOIN location l   ON l.id = r.location_id
                        LEFT JOIN city         ON city.id = l.city_id
                        LEFT JOIN user partner ON partner.id = r.customer_user_id
                       WHERE r.guide_user_id = :guide
                         AND " . self::runningSql('r') . "
                       ORDER BY r.started_at DESC
                       LIMIT 1";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':guide', $guide, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $zeile ?: null;
        } catch (\PDOException $e) {
            error_log('Fehler beim Laden der laufenden Fuehrung: ' . $e->getMessage());
            return null;
        }
    }

    // =================================================================
    // AUFRAEUMEN (Cronjob)
    // =================================================================

    /**
     * Schreibt abgelaufene Anfragen fest.
     *
     * DAS IST AUFRAEUMEN UND KEINE PRUEFUNG - genau wie beim Aufraeumen der
     * Bereitschaft (cron/check_online_status.php). Ob eine Anfrage noch gilt,
     * entscheidet nirgends dieser Aufruf, sondern der Vergleich mit NOW() in
     * jeder einzelnen Abfrage (statusSql). Ohne den Cronjob laeuft alles
     * genauso, nur traegt die Spalte dann dauerhaft einen Wert, der nicht
     * mehr stimmt - und die Tabelle sammelt Karteileichen.
     *
     * Zwei Faelle, dieselben zwei wie in statusSql():
     *   1. offen und die Frist ist verstrichen,
     *   2. angenommen, nie begonnen und das Zeitfenster ist vorbei.
     *
     * @return int Anzahl der geaenderten Zeilen
     */
    public static function expireDue(): int
    {
        $config = self::config();
        $nach   = (int)$config['call_window_after'];
        $summe  = 0;

        try {
            $summe += (int)PdoConnect::$connection->exec(
                "UPDATE tour_request
                    SET status = '" . self::STATUS_EXPIRED . "'
                  WHERE status = '" . self::STATUS_OPEN . "'
                    AND expires_at <= NOW()"
            );

            $summe += (int)PdoConnect::$connection->exec(
                "UPDATE tour_request
                    SET status = '" . self::STATUS_EXPIRED . "'
                  WHERE status = '" . self::STATUS_ACCEPTED . "'
                    AND started_at IS NULL
                    AND DATE_ADD(wish_at, INTERVAL $nach SECOND) <= NOW()"
            );
        } catch (\PDOException $e) {
            error_log('Fehler beim Aufraeumen abgelaufener Anfragen: ' . $e->getMessage());
        }

        return $summe;
    }

    /**
     * Schreibt Fuehrungen fest, die niemand beendet hat.
     *
     * DAS IST AUFRAEUMEN UND KEINE PRUEFUNG, wie expireDue(): Ob eine
     * Fuehrung zu ist, entscheidet nirgends dieser Aufruf, sondern closedSql()
     * in jeder einzelnen Abfrage. Ohne den Cronjob laeuft alles genauso, nur
     * traegt die Spalte dann dauerhaft 'accepted', obwohl die Fuehrung
     * laengst durchgefuehrt ist.
     *
     * Zwei Faelle, dieselben zwei wie in closedSql():
     *   1. Es wurde aufgelegt, aber niemand hat beendet, und die Frist fuer
     *      den Wiedereinstieg ist verstrichen (rejoin_window). Der Regelfall:
     *      Der Guide hat den Knopf vergessen.
     *   2. Es kam nie ein Auflegen an - Absturz, Netz weg. Dann zaehlt der
     *      Beginn samt der langen Reissleine (stale_call).
     *
     * closed_at BLEIBT LEER, und ended_at wird nicht erfunden. Beide Spalten
     * halten fest, was jemand getan hat; hier hat niemand etwas getan. "Ende
     * unbekannt" ist die ehrliche Auskunft - an ended_at haengt spaeter eine
     * Abrechnung.
     *
     * @return int Anzahl der geaenderten Zeilen
     */
    public static function closeStale(): int
    {
        $config = self::config();
        $stale  = (int)$config['stale_call'];
        $rejoin = (int)$config['rejoin_window'];
        $summe  = 0;

        try {
            $summe += (int)PdoConnect::$connection->exec(
                "UPDATE tour_request
                    SET status = '" . self::STATUS_DONE . "'
                  WHERE status = '" . self::STATUS_ACCEPTED . "'
                    AND started_at IS NOT NULL
                    AND closed_at IS NULL
                    AND ended_at IS NOT NULL
                    AND ended_at <= DATE_SUB(NOW(), INTERVAL $rejoin SECOND)"
            );

            $summe += (int)PdoConnect::$connection->exec(
                "UPDATE tour_request
                    SET status = '" . self::STATUS_DONE . "'
                  WHERE status = '" . self::STATUS_ACCEPTED . "'
                    AND started_at IS NOT NULL
                    AND closed_at IS NULL
                    AND ended_at IS NULL
                    AND started_at <= DATE_SUB(NOW(), INTERVAL $stale SECOND)"
            );
        } catch (\PDOException $e) {
            error_log('Fehler beim Abschliessen haengender Fuehrungen: ' . $e->getMessage());
        }

        return $summe;
    }
}
