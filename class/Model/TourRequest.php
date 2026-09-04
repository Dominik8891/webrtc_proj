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
     * Zwei Faelle, in denen der Spaltenwert nicht mehr gilt:
     *
     *   1. Eine OFFENE Anfrage, deren expires_at verstrichen ist. Der
     *      Zeitpunkt steht seit dem Anlegen in der Zeile und traegt beide
     *      Fristen aus config/requests.php - die Antwortfrist und den
     *      verstrichenen Wunschzeitpunkt.
     *   2. Eine ANGENOMMENE Anfrage, zu der es nie ein Gespraech gab und
     *      deren Zeitfenster vorbei ist. Die Verabredung ist verstrichen.
     *
     * Ein einmal begonnenes Gespraech faellt nicht mehr in diesen Fall:
     * started_at ist gesetzt, und was stattgefunden hat, laeuft nicht ab.
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
                  ELSE $a.status
                END";
    }

    /**
     * Darf zu dieser Anfrage JETZT angerufen werden - als SQL-Ausdruck?
     *
     * DIE EINE BEDINGUNG, an der drei Stellen haengen: der Knopf beim Kunden,
     * die Zulassung des Anrufs im Signaling und das Festhalten des Beginns.
     * Sie steht deshalb hier und nicht dreimal nachgebaut.
     *
     * Erlaubt ist der Anruf im vereinbarten ZEITFENSTER um den
     * Wunschzeitpunkt (config/requests.php: call_window_before / _after) -
     * und zwar auch dann, wenn das Gespraech schon einmal lief: Bricht die
     * Verbindung ab, waere ein Rueckruf sonst gesperrt, obwohl die
     * Verabredung noch gilt. Deshalb zaehlt neben 'accepted' auch 'done'.
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

        return "($a.status IN ('" . self::STATUS_ACCEPTED . "', '" . self::STATUS_DONE . "')
                 AND NOW() >= DATE_SUB($a.wish_at, INTERVAL $vor SECOND)
                 AND NOW() <= DATE_ADD($a.wish_at, INTERVAL $nach SECOND))";
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
        return "$a.id, $a.location_id, $a.guide_user_id, $a.customer_user_id,
                $a.wish_at, $a.created_at, $a.expires_at,
                $a.decided_at, $a.started_at, $a.ended_at,
                " . self::statusSql($a) . " AS status,
                " . self::callableSql($a) . " AS callable,
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
                             partner.username AS partner_name
                      FROM tour_request r
                      LEFT JOIN location l      ON l.id = r.location_id
                      LEFT JOIN city            ON city.id = l.city_id
                      LEFT JOIN country         ON country.id = city.country_id
                      LEFT JOIN user partner    ON partner.id = r.$partner
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
     *
     * Sie gehen mit der Antwort des Heartbeats mit und nicht ueber eine
     * eigene Abfrage im Takt: Der Heartbeat laeuft ohnehin alle zehn
     * Sekunden, und eine zweite Schleife daneben waere derselbe Weg noch
     * einmal (App\Controller\UserController::heartbeat).
     *
     * EINE Abfrage fuer beide Zahlen, denn es ist dieselbe Zeilenmenge.
     *
     * @param int $in_user_id
     * @return array{incoming_open:int, outgoing_accepted:int}
     */
    public static function counters($in_user_id): array
    {
        $leer    = ['incoming_open' => 0, 'outgoing_accepted' => 0];
        $user_id = (int)$in_user_id;
        if ($user_id < 1) return $leer;

        $status = self::statusSql('r');

        try {
            $query = "SELECT
                        SUM(r.guide_user_id = :guide
                            AND $status = '" . self::STATUS_OPEN . "')     AS incoming_open,
                        SUM(r.customer_user_id = :customer
                            AND r.started_at IS NULL
                            AND $status = '" . self::STATUS_ACCEPTED . "') AS outgoing_accepted
                      FROM tour_request r
                      WHERE (r.guide_user_id = :guide2 OR r.customer_user_id = :customer2)
                        AND r.status IN ('" . self::STATUS_OPEN . "', '" . self::STATUS_ACCEPTED . "')";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':guide',     $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':guide2',    $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':customer',  $user_id, \PDO::PARAM_INT);
            $stmt->bindParam(':customer2', $user_id, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$zeile) return $leer;

            return [
                'incoming_open'     => (int)($zeile['incoming_open'] ?? 0),
                'outgoing_accepted' => (int)($zeile['outgoing_accepted'] ?? 0),
            ];
        } catch (\PDOException $e) {
            error_log('Fehler beim Zaehlen der Anfragen: ' . $e->getMessage());
            return $leer;
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
     * Haelt das Ende der Fuehrung fest - und macht sie damit zu einer
     * durchgefuehrten.
     *
     * Aufgerufen vom Signaling beim 'hangup'. WELCHE SEITE AUFLEGT, IST
     * OFFEN: Der Guide kann es sein oder der Kunde. Deshalb wird das Paar in
     * beide Richtungen geprueft und nicht angenommen, der Absender sei der
     * Kunde.
     *
     * Getroffen wird nur eine Fuehrung, die BEGONNEN hat. Ein Anruf, der nie
     * zustande kam, macht aus einer Anfrage keine durchgefuehrte Fuehrung.
     *
     * @param int $in_user_a Absender des hangup
     * @param int $in_user_b Empfaenger
     * @return bool true, wenn eine Fuehrung abgeschlossen wurde
     */
    public static function markEnded($in_user_a, $in_user_b): bool
    {
        $a = (int)$in_user_a;
        $b = (int)$in_user_b;
        if ($a < 1 || $b < 1 || $a === $b) return false;

        try {
            $query = "UPDATE tour_request r
                         SET r.ended_at = NOW(),
                             r.status   = '" . self::STATUS_DONE . "'
                       WHERE r.started_at IS NOT NULL
                         AND r.ended_at IS NULL
                         AND r.status IN ('" . self::STATUS_ACCEPTED . "', '" . self::STATUS_DONE . "')
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
            error_log('Fehler beim Abschliessen einer Fuehrung: ' . $e->getMessage());
            return false;
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
     * Schliesst Fuehrungen ab, deren Ende nie angekommen ist.
     *
     * Das Ende kommt vom 'hangup'. Stuerzt ein Browser ab oder faellt das
     * Netz aus, kommt es nie - die Zeile stuende sonst fuer immer als
     * laufende Fuehrung da.
     *
     * ended_at BLEIBT LEER. Ein geschaetztes Ende waere eine Erfindung, und
     * an dieser Spalte haengt spaeter eine Abrechnung. "Beginn bekannt, Ende
     * unbekannt" ist die ehrliche Auskunft.
     *
     * @return int Anzahl der geaenderten Zeilen
     */
    public static function closeStale(): int
    {
        $config = self::config();
        $frist  = (int)$config['stale_call'];

        try {
            return (int)PdoConnect::$connection->exec(
                "UPDATE tour_request
                    SET status = '" . self::STATUS_DONE . "'
                  WHERE status = '" . self::STATUS_ACCEPTED . "'
                    AND started_at IS NOT NULL
                    AND ended_at IS NULL
                    AND started_at <= DATE_SUB(NOW(), INTERVAL $frist SECOND)"
            );
        } catch (\PDOException $e) {
            error_log('Fehler beim Abschliessen haengender Fuehrungen: ' . $e->getMessage());
            return 0;
        }
    }
}
