<?php

namespace App\Model;

use App\Helper\I18n;

/**
 * Die Bewertung einer Fuehrung: abgeben, zaehlen, anzeigen, entfernen.
 *
 * WARUM ES SIE GIBT
 * -----------------
 * Ein Kunde soll einem Fremden Geld dafuer geben, dass der ihn per Video
 * durch eine unbekannte Stadt fuehrt. Bisher konnte er sich dabei nur auf
 * das stuetzen, was der Guide ueber sich selbst schreibt (App\Model\
 * GuideProfile). Was ANDERE Kunden erlebt haben, stand nirgends.
 *
 * NUR EINE RICHTUNG
 * -----------------
 * Kunden bewerten Guides. Guides bewerten keine Zuschauer. Es gibt deshalb
 * keine Spalte "wer bewertet wen" und keine Richtungsmarke, sondern zwei
 * Spalten mit festen Rollen: guide_user_id ist der Bewertete,
 * customer_user_id der Bewertende. Eine Zeile in die andere Richtung ist in
 * diesem Schema nicht darstellbar - das ist der Sinn und keine Luecke.
 *
 * BEWERTET WIRD EINE FUEHRUNG, NICHT EIN GUIDE
 * --------------------------------------------
 * Jede Zeile haengt an genau einer Zeile in `tour_request` - der einzigen
 * Stelle, an der steht, dass eine Fuehrung wirklich stattgefunden hat
 * (status 'done', started_at gesetzt). Der Schreibvorgang ist deshalb ein
 * INSERT ... SELECT AUS dieser Tabelle und nicht ein INSERT mit Werten aus
 * dem Browser: Guide, Kunde und Standort kommen aus der Anfragezeile, der
 * Browser steuert Sterne und Text bei - sonst nichts.
 *
 * Der eindeutige Schluessel auf request_id sorgt fuer die zweite Regel: je
 * Fuehrung hoechstens eine Bewertung. Sie steht in der Tabelle und nicht nur
 * hier (migrations/016).
 *
 * WENIGE BEWERTUNGEN SIND KEIN URTEIL
 * -----------------------------------
 * "1 Bewertung, 3 Sterne" ist fuer einen neuen Guide schlechter als gar
 * nichts: Es sieht aus wie ein Befund, ist aber eine einzelne Stimme. Der
 * Durchschnitt wird deshalb erst ab MIN_FOR_AVERAGE Bewertungen ueberhaupt
 * herausgegeben - darunter liefert summary() ihn als null, und zwar HIER und
 * nicht erst in der Anzeige. Eine Ansicht, die ihn bekommt, zeigt ihn
 * irgendwann auch.
 *
 * Was stattdessen dasteht, entscheidet App\Helper\ReviewView: die Zahl der
 * durchgefuehrten Fuehrungen ("Neu - 4 Fuehrungen durchgefuehrt"). Das ist
 * eine Tatsache und kein Urteil, und sie kommt aus derselben Abfrage mit
 * (summary()['tours']).
 *
 * ENTFERNT HEISST AUSGEBLENDET
 * ----------------------------
 * Der Guide kann eine Bewertung weder aendern noch loeschen - sonst waere
 * sie keine Auskunft mehr ueber ihn, sondern eine von ihm. Eine Beschwerde
 * loest ein Admin, indem er sie entfernt (remove()); dabei wird removed_at
 * gesetzt und nichts geloescht. Jede Lesestelle filtert ueber SICHTBAR,
 * damit "entfernt" an genau einer Stelle definiert ist.
 */
class TourReview
{
    /**
     * Die Skala: ganze Sterne von 1 bis 5.
     *
     * HALBE STERNE GIBT ES NUR IN DER ANZEIGE eines Durchschnitts, nie in
     * einer Abgabe. Wer bewertet, waehlt einen von fuenf Werten - eine
     * feinere Abstufung waere Scheingenauigkeit bei einer Frage, die niemand
     * feiner beantworten kann.
     */
    public const STARS_MIN = 1;
    public const STARS_MAX = 5;

    /**
     * Groesste Laenge des freiwilligen Textes in Zeichen.
     *
     * DIE EINE STELLE, gegen die geprueft wird - das Formular bekommt
     * dieselbe Zahl als maxlength (App\Helper\ReviewView). Ein Feld, das mehr
     * annimmt, als der Server durchlaesst, gibt dem Nutzer eine Absage fuer
     * eine Eingabe, die es selbst ausdruecklich erlaubt hat.
     */
    public const BODY_MAX = 1000;

    /**
     * Ab wie vielen Bewertungen ein Durchschnitt herausgegeben wird.
     *
     * DIE ZAHL, um die es in dieser Klasse eigentlich geht. Bei einer
     * einzelnen Bewertung ist der "Durchschnitt" die Meinung eines einzelnen
     * Menschen, sieht aber aus wie eine Messung - und trifft dabei den, der
     * sie am wenigsten verkraftet: den neuen Guide, der noch keine zweite
     * Stimme sammeln konnte.
     *
     * Drei ist die kleinste Zahl, bei der ein Ausreisser nicht mehr allein
     * bestimmt, was dasteht. Sie hier zu aendern aendert die Anzeige an allen
     * Stellen zugleich.
     */
    public const MIN_FOR_AVERAGE = 3;

    /**
     * Wie viele Bewertungen mit Text eine Seite zeigt.
     *
     * Die letzten - nicht die besten und nicht die schlechtesten. Eine
     * Auswahl waere eine Meinung; die juengsten sind die Auskunft darueber,
     * wie es GERADE ist.
     */
    public const LIST_LIMIT = 5;

    /**
     * Was "sichtbar" heisst, als SQL-Bedingung.
     *
     * An einer Stelle, weil jede Abfrage dieser Klasse sie braucht:
     * Durchschnitt, Anzahl, Listen. Eine vergessene Bedingung wuerde eine
     * entfernte Bewertung wieder auftauchen lassen - genau das, was die
     * Moderation verhindern sollte.
     */
    private const SICHTBAR = 'removed_at IS NULL';

    /**
     * Die Wortmarken der Skala.
     *
     * WOZU: "3 von 5" heisst fuer jeden etwas anderes. Die Woerter stehen am
     * Formular neben den Sternen und in der Beschriftung einer abgegebenen
     * Bewertung - an beiden Stellen dieselben, weil sie hier zusammenkommen.
     *
     * DIE WOERTER SELBST kommen aus dem Sprachkatalog (bewertung.stern.*);
     * diese Methode legt nur fest, DASS es fuenf sind und welche Zahl zu
     * welchem Wort gehoert.
     *
     * @return array<int,string>
     */
    public static function starNames(): array
    {
        // Aus dem Sprachkatalog und nicht aus dieser Liste: Die Stufen
        // stehen im Bewertungsformular und in der Verwaltung, und sie
        // gehoeren zur Sprache der SEITE. Der Schluessel traegt die Zahl -
        // sie ist die Kennung, das Wort ist die Beschriftung.
        $namen = [];
        for ($stern = 1; $stern <= self::STARS_MAX; $stern++) {
            $namen[$stern] = I18n::t('bewertung.stern.' . $stern);
        }
        return $namen;
    }

    /**
     * Ist das eine erlaubte Sternzahl?
     *
     * @param mixed $in_stars
     * @return bool
     */
    public static function isValidStars($in_stars): bool
    {
        if (!is_numeric($in_stars)) return false;

        // GANZE STERNE, und das wird geprueft: (int) allein wuerde aus 3,5
        // klaglos eine 3 machen - eine Bewertung, die so niemand abgegeben
        // hat. Halbe Sterne gibt es nur in der Anzeige eines Durchschnitts.
        $wert = (float)$in_stars;
        if ($wert != (int)$wert) return false;

        return $wert >= self::STARS_MIN && $wert <= self::STARS_MAX;
    }

    // =================================================================
    // ABGEBEN
    // =================================================================

    /**
     * Nimmt die Bewertung einer Fuehrung entgegen.
     *
     * DIE BERECHTIGUNG STEHT IN DER WHERE-KLAUSEL, nicht davor: Geschrieben
     * wird mit INSERT ... SELECT aus `tour_request`, und diese Auswahl
     * liefert nur dann eine Zeile, wenn
     *
     *   1. es die Anfrage gibt,
     *   2. sie diesem Kunden gehoert und
     *   3. die Fuehrung DURCHGEFUEHRT UND ZU ist
     *      (App\Model\TourRequest::conductedSql: begonnen, und der Guide hat
     *      beendet oder die Frist fuer den Wiedereinstieg ist verstrichen).
     *
     * Damit kommen Guide, Kunde und Standort AUS DER AUFZEICHNUNG und nicht
     * aus der Anfrage des Browsers - der steuert Sterne und Text bei, sonst
     * nichts. Ein Kunde kann so weder eine fremde Fuehrung bewerten noch eine,
     * die nie stattgefunden hat, noch einen anderen Guide eintragen.
     *
     * DER ABSCHLUSS WIRD GERECHNET und nicht nur aus der Spalte gelesen: Ein
     * Guide, der das Beenden vergisst, wuerde die Bewertung sonst bis zum
     * naechsten Lauf des Cronjobs blockieren - und der ist womoeglich gar
     * nicht eingerichtet. Solange die Fuehrung dagegen LAEUFT, ist sie nicht
     * bewertbar: Der Kunde soll nicht gefragt werden, waehrend der Guide noch
     * zurueck in die Leitung will.
     *
     * DIE ZWEITE BEWERTUNG SCHEITERT AM SCHLUESSEL. Der eindeutige Index auf
     * request_id (migrations/016) faengt sie ab, auch wenn zwei Anfragen
     * gleichzeitig ankommen. Der doppelte Schluessel ist deshalb kein Fehler,
     * ueber den etwas ins Log muss, sondern das erwartete Ergebnis von "schon
     * bewertet" - er wird als solcher zurueckgemeldet.
     *
     * @param int         $in_request_id
     * @param int         $in_customer_id Der Angemeldete
     * @param int         $in_stars       1 bis 5
     * @param string|null $in_body        Freiwilliger Text
     * @return int|null Neue Bewertungs-ID, null wenn nichts geschrieben wurde
     */
    public static function create($in_request_id, $in_customer_id, $in_stars, $in_body): ?int
    {
        $request  = (int)$in_request_id;
        $customer = (int)$in_customer_id;
        $sterne   = (int)$in_stars;

        if ($request < 1 || $customer < 1) return null;
        if (!self::isValidStars($sterne)) return null;

        // Leerer Text heisst NULL und nicht Leerstring: "nichts geschrieben"
        // soll genau eine Schreibweise haben, sonst muss jede Lesestelle
        // beide kennen. Dieselbe Regel wie bei guide_profile.about.
        $text = self::kuerze($in_body, self::BODY_MAX);
        $text = $text === '' ? null : $text;

        try {
            $query = "INSERT INTO tour_review
                            (request_id, guide_user_id, customer_user_id,
                             location_id, stars, body)
                      SELECT r.id, r.guide_user_id, r.customer_user_id,
                             r.location_id, :stars, :body
                        FROM tour_request r
                       WHERE r.id               = :request
                         AND r.customer_user_id = :customer
                         AND " . TourRequest::conductedSql('r');
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':stars',    $sterne,  \PDO::PARAM_INT);
            $stmt->bindParam(':body',     $text);
            $stmt->bindParam(':request',  $request,  \PDO::PARAM_INT);
            $stmt->bindParam(':customer', $customer, \PDO::PARAM_INT);
            $stmt->execute();

            if ($stmt->rowCount() < 1) return null;

            return (int)PdoConnect::$connection->lastInsertId();
        } catch (\PDOException $e) {
            // 23000 ist der doppelte Schluessel: Diese Fuehrung ist schon
            // bewertet. Das ist die Regel und keine Stoerung - es kommt
            // nichts ins Log, der Aufrufer bekommt dieselbe Antwort wie bei
            // jedem anderen Fehlschlag.
            if ($e->getCode() !== '23000') {
                error_log('TourReview::create: ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Die Fuehrung, zu der dieser Kunde JETZT gefragt werden soll.
     *
     * WOZU: Nach dem Auflegen soll die Frage kommen - aber nicht als Dialog
     * mitten im Abbau des Gespraechs. Der Browser laedt auf Telefonen nach
     * dem Ende ohnehin neu (assets/js/rtc.js), und was in diesem Moment auf
     * dem Bildschirm stand, ist danach weg. Gefragt wird deshalb ueber diese
     * Methode: Der Heartbeat laeuft ohnehin alle zehn Sekunden
     * (App\Controller\UserController::heartbeat), und was er mitbringt,
     * ueberlebt jeden Seitenwechsel.
     *
     * DIE JUENGSTE UNBEWERTETE FUEHRUNG, mehr nicht. Wer drei offene hat,
     * bekommt nicht drei Fragen hintereinander - er bekommt eine, und die
     * uebrigen findet er auf der Anfragenseite.
     *
     * ES IST DIESELBE AUSKUNFT WIE AUF DER ANFRAGENSEITE, nur auf eine Zeile
     * verkuerzt: bewertbar ist, was durchgefuehrt ist und noch keine
     * Bewertung hat. Entfernte Bewertungen zaehlen dabei MIT - eine
     * entfernte Bewertung ist abgegeben, und das Entfernen ist keine
     * Einladung, dasselbe noch einmal zu schreiben. Deshalb steht hier
     * bewusst kein SICHTBAR.
     *
     * @param int $in_customer_id
     * @return array<string,mixed>|null null, wenn nichts offen ist
     */
    public static function pendingForCustomer($in_customer_id): ?array
    {
        $customer = (int)$in_customer_id;
        if ($customer < 1) return null;

        try {
            $query = "SELECT r.id, r.location_id, r.guide_user_id, r.ended_at,
                             l.title, city.city_name,
                             guide_profile.display_name, guide.username
                        FROM tour_request r
                        LEFT JOIN tour_review v ON v.request_id = r.id
                        LEFT JOIN location l    ON l.id = r.location_id
                        LEFT JOIN city          ON city.id = l.city_id
                        LEFT JOIN user guide     ON guide.id = r.guide_user_id
                        LEFT JOIN guide_profile  ON guide_profile.user_id = r.guide_user_id
                       WHERE r.customer_user_id = :customer
                         AND " . TourRequest::conductedSql('r') . "
                         AND v.id IS NULL
                       ORDER BY r.started_at DESC
                       LIMIT 1";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':customer', $customer, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $zeile ?: null;
        } catch (\PDOException $e) {
            error_log('TourReview::pendingForCustomer: ' . $e->getMessage());
            return null;
        }
    }

    // =================================================================
    // ANZEIGEN
    // =================================================================

    /**
     * Durchschnitt, Anzahl und Zahl der Fuehrungen - fuer einen Guide.
     *
     * @param int $in_guide_id
     * @return array{count:int, average:?float, tours:int}
     */
    public static function summaryForGuide($in_guide_id): array
    {
        return self::summary('guide_user_id', $in_guide_id);
    }

    /**
     * Dasselbe fuer einen Standort.
     *
     * @param int $in_location_id
     * @return array{count:int, average:?float, tours:int}
     */
    public static function summaryForLocation($in_location_id): array
    {
        return self::summary('location_id', $in_location_id);
    }

    /**
     * Die gemeinsame Abfrage beider Zusammenfassungen.
     *
     * DREI ZAHLEN IN EINER ABFRAGE, weil sie auf einer Seite gemeinsam
     * gebraucht werden und weil zwei davon dieselbe Zeilenmenge betreffen.
     *
     *   count     sichtbare Bewertungen,
     *   average   ihr Mittel - ODER NULL, solange es weniger als
     *             MIN_FOR_AVERAGE sind (siehe Klassenkopf),
     *   tours     durchgefuehrte Fuehrungen. Sie stehen in `tour_request` und
     *             sind das, was ANSTELLE des Durchschnitts dasteht, solange
     *             es ihn nicht gibt.
     *
     * DER SCHWELLENWERT WIRD HIER ANGEWENDET und nicht in der Ansicht: Ein
     * Durchschnitt, der einmal aus dieser Methode herauskommt, erscheint
     * irgendwann auch auf einer Seite.
     *
     * @param string $in_spalte 'guide_user_id' oder 'location_id'
     * @param int    $in_id
     * @return array{count:int, average:?float, tours:int}
     */
    private static function summary(string $in_spalte, $in_id): array
    {
        $leer = ['count' => 0, 'average' => null, 'tours' => 0];

        $id = (int)$in_id;
        if ($id < 1) return $leer;

        // Der Spaltenname kommt aus dieser Klasse und nie von aussen; die
        // Pruefung steht trotzdem da, weil er ein Textbaustein in einer
        // Abfrage ist. Beide Tabellen fuehren beide Spalten unter demselben
        // Namen - deshalb genuegt EIN Name fuer beide Teilabfragen.
        $spalte = self::spalte($in_spalte);

        try {
            $query = "SELECT
                        (SELECT COUNT(*) FROM tour_review v
                          WHERE v.$spalte = :id1 AND v." . self::SICHTBAR . ")  AS anzahl,
                        (SELECT AVG(v.stars) FROM tour_review v
                          WHERE v.$spalte = :id2 AND v." . self::SICHTBAR . ")  AS schnitt,
                        (SELECT COUNT(*) FROM tour_request r
                          WHERE r.$spalte = :id3
                            AND " . TourRequest::conductedSql('r') . ")         AS fuehrungen";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':id1', $id, \PDO::PARAM_INT);
            $stmt->bindParam(':id2', $id, \PDO::PARAM_INT);
            $stmt->bindParam(':id3', $id, \PDO::PARAM_INT);
            $stmt->execute();
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$zeile) return $leer;

            $anzahl = (int)($zeile['anzahl'] ?? 0);

            return [
                'count'   => $anzahl,
                'average' => ($anzahl >= self::MIN_FOR_AVERAGE && $zeile['schnitt'] !== null)
                             ? round((float)$zeile['schnitt'], 1)
                             : null,
                'tours'   => (int)($zeile['fuehrungen'] ?? 0),
            ];
        } catch (\PDOException $e) {
            error_log('TourReview::summary: ' . $e->getMessage());
            return $leer;
        }
    }

    /**
     * Die Bewertungszahlen JE STANDORT - als Baustein fuer fremde Abfragen.
     *
     * WOZU: Auf der Karte und in der Standortliste steht die Bewertung an
     * jeder Zeile, und beide Listen holen alle Standorte auf einmal. Je Zeile
     * eine eigene Abfrage waere bei fuenfzig Nadeln fuenfzig Abfragen - im
     * Takt von fuenfzehn Sekunden.
     *
     * Der Baustein ist deshalb ein LEFT JOIN auf zwei gruppierte Abfragen:
     * eine ueber die sichtbaren Bewertungen, eine ueber die durchgefuehrten
     * Fuehrungen. Beide laufen einmal, nicht einmal pro Zeile.
     *
     * ZWEI TABELLEN, WEIL ES ZWEI AUSKUENFTE SIND: Wie viele Bewertungen es
     * gibt, steht in `tour_review`; wie viele Fuehrungen stattgefunden haben,
     * in `tour_request`. Die zweite ist das, was ANSTELLE eines Durchschnitts
     * dasteht, solange es zu wenige Bewertungen gibt - und sie ist deshalb
     * keine Zugabe, sondern der Kern der Sache.
     *
     * Die Aliase (rev, tours) sind fest: Sie stehen in aggregateColumnsSql()
     * noch einmal, und die beiden gehoeren zusammen.
     *
     * @param string $in_alias Alias der Standorttabelle in der Abfrage
     * @return string SQL-Fragment mit zwei LEFT JOINs
     */
    public static function aggregateJoinSql(string $in_alias = 'location'): string
    {
        $a = self::tabellenAlias($in_alias);

        return "LEFT JOIN (SELECT v.location_id,
                                  COUNT(*)      AS anzahl,
                                  AVG(v.stars)  AS schnitt
                             FROM tour_review v
                            WHERE v." . self::SICHTBAR . "
                            GROUP BY v.location_id) rev
                       ON rev.location_id = $a.id
                LEFT JOIN (SELECT t.location_id,
                                  COUNT(*) AS fuehrungen
                             FROM tour_request t
                            WHERE " . TourRequest::conductedSql('t') . "
                            GROUP BY t.location_id) tours
                       ON tours.location_id = $a.id";
    }

    /**
     * Die drei Spalten, die zu aggregateJoinSql() gehoeren.
     *
     * DIE SCHWELLE STEHT AUCH HIER IM SQL und nicht in der Anzeige - dieselbe
     * Regel wie in summary(): Unterhalb von MIN_FOR_AVERAGE kommt gar kein
     * Durchschnitt heraus, sondern NULL. Ein Wert, der einmal aus dem Modell
     * herauskommt, erscheint irgendwann auch auf einer Seite; die Karte und
     * die Standortliste bauen ihre Zeilen im Browser, und ein Skript, das die
     * Schwelle selbst kennen muesste, waere die zweite Fassung derselben
     * Regel.
     *
     * @return string SQL-Spaltenliste (ohne fuehrendes Komma)
     */
    public static function aggregateColumnsSql(): string
    {
        return "COALESCE(rev.anzahl, 0) AS review_count,
                CASE WHEN COALESCE(rev.anzahl, 0) >= " . self::MIN_FOR_AVERAGE . "
                     THEN ROUND(rev.schnitt, 1)
                END AS review_average,
                COALESCE(tours.fuehrungen, 0) AS review_tours";
    }

    /**
     * Nur Buchstaben, Ziffern und Unterstriche im Tabellenalias.
     *
     * Der Alias kommt ausschliesslich aus diesem Projekt und nie von aussen.
     * Er geht aber als Textbaustein in eine Abfrage, und ein Textbaustein in
     * einer Abfrage wird geprueft - dieselbe Regel wie bei
     * App\Model\TourRequest::alias().
     *
     * @param string $in_alias
     * @return string
     */
    private static function tabellenAlias(string $in_alias): string
    {
        $sauber = preg_replace('/[^a-zA-Z0-9_]/', '', $in_alias);
        return $sauber === '' ? 'location' : $sauber;
    }

    /**
     * Die letzten Bewertungen MIT TEXT eines Guides.
     *
     * @param int $in_guide_id
     * @param int $in_limit
     * @return array<int,array<string,mixed>>
     */
    public static function latestForGuide($in_guide_id, int $in_limit = self::LIST_LIMIT): array
    {
        return self::letzte('guide_user_id', $in_guide_id, $in_limit);
    }

    /**
     * Dasselbe fuer einen Standort.
     *
     * @param int $in_location_id
     * @param int $in_limit
     * @return array<int,array<string,mixed>>
     */
    public static function latestForLocation($in_location_id, int $in_limit = self::LIST_LIMIT): array
    {
        return self::letzte('location_id', $in_location_id, $in_limit);
    }

    /**
     * Die gemeinsame Abfrage beider Listen.
     *
     * NUR BEWERTUNGEN MIT TEXT. Eine Zeile, die aus fuenf Sternen und sonst
     * nichts besteht, hat nichts zu zeigen - sie steht bereits im
     * Durchschnitt und in der Anzahl. Eine Liste voller leerer Eintraege
     * saehe aus wie ein Fehler.
     *
     * DIE LETZTEN und nicht die besten: Eine Auswahl waere eine Meinung.
     *
     * Der Titel des Standorts kommt mit, weil er auf dem GUIDE-Profil die
     * Auskunft ist, um welche Fuehrung es ging. LEFT JOIN, weil diese Tabelle
     * keine Fremdschluessel hat: Ein geloeschter Standort nimmt die Bewertung
     * nicht mit, und dann fehlt eben der Titel.
     *
     * DER NAME DES KUNDEN KOMMT NICHT MIT, und das ist Absicht: Ein Kunde hat
     * in dieser Anwendung keinen Anzeigenamen, sondern nur einen
     * Benutzernamen - und der ist die Anmeldekennung und geht Fremde nichts
     * an (dieselbe Regel wie auf der Profilseite, App\Helper\Permission::
     * GUIDE_VIEW). Eine Bewertung steht deshalb ohne Namen da; was sie
     * einordnet, sind der Monat und die Fuehrung, um die es ging.
     *
     * @param string $in_spalte 'guide_user_id' oder 'location_id'
     * @param int    $in_id
     * @param int    $in_limit
     * @return array<int,array<string,mixed>>
     */
    private static function letzte(string $in_spalte, $in_id, int $in_limit): array
    {
        $id = (int)$in_id;
        if ($id < 1) return [];

        $spalte = self::spalte($in_spalte);
        // Die Grenze geht als Zahl in den Text: LIMIT vertraegt in MySQL
        // keinen gebundenen Parameter, solange PDO nicht emuliert.
        $limit  = max(1, min(50, $in_limit));

        try {
            $query = "SELECT v.id, v.stars, v.body, v.created_at,
                             v.location_id, v.guide_user_id,
                             l.title
                        FROM tour_review v
                        LEFT JOIN location l ON l.id = v.location_id
                       WHERE v.$spalte = :id
                         AND v." . self::SICHTBAR . "
                         AND v.body IS NOT NULL
                         AND v.body <> ''
                       ORDER BY v.created_at DESC, v.id DESC
                       LIMIT $limit";
            $stmt = PdoConnect::$connection->prepare($query);
            $stmt->bindParam(':id', $id, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log('TourReview::letzte: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * ALLE Bewertungen - die Liste des Verwaltungsbereichs.
     *
     * WARUM SIE NEBEN latestForGuide()/latestForLocation() STEHT
     * ---------------------------------------------------------
     * Weil die beiden anderen fuer eine oeffentliche Seite gebaut sind und
     * genau deshalb drei Dinge weglassen, die die Moderation braucht:
     *
     *   1. BEWERTUNGEN OHNE TEXT. Auf einer Standortseite hat eine Zeile aus
     *      fuenf Sternen nichts zu zeigen - in der Moderationsliste schon:
     *      Auch eine Ein-Stern-Wertung ohne Wort zaehlt im Durchschnitt und
     *      kann falsch zugeordnet sein.
     *   2. ENTFERNTE BEWERTUNGEN. Sie sind ausgeblendet, nicht geloescht
     *      (siehe remove()), und die Verwaltung ist der eine Ort, an dem das
     *      auch sichtbar sein muss - samt Grund und Entferner.
     *   3. WER SIE GESCHRIEBEN HAT. Auf der Standortseite steht kein Name,
     *      und das bleibt so: Der Benutzername ist die Anmeldekennung und
     *      geht Fremde nichts an. Fuer eine Beschwerde ist die Frage "kommen
     *      die drei Ein-Stern-Wertungen alle vom selben Konto" aber genau
     *      die, auf die es ankommt - und die Verwaltung darf sie stellen.
     *
     * DIE LETZTEN ZUERST, wie in den oeffentlichen Listen: Eine Auswahl waere
     * eine Meinung, und die Moderation faengt bei dem an, was neu ist.
     *
     * @param string $in_filter          'alle', 'sichtbar', 'entfernt' oder
     *                                    'schwach' ('schwach' = ein oder zwei
     *                                    Sterne, sichtbar - die Zeilen, wegen
     *                                    derer sich jemand meldet)
     * @param int    $in_limit            Obergrenze der Zeilen
     * @param bool   $in_mit_geloeschten  Bewertungen zu geloeschten GUIDES
     *                                    mitliefern (siehe unten, warum nur
     *                                    der Guide zaehlt)
     * @return array<int,array<string,mixed>>
     */
    public static function allForAdmin(string $in_filter = 'alle', int $in_limit = 200,
                                       bool $in_mit_geloeschten = false): array
    {
        // Ein Textbaustein in einer Abfrage wird nachgeschlagen und nicht
        // zusammengesetzt - der Filter kommt aus der Adresszeile.
        $schwach = (int)self::STARS_MIN + 1;
        $wo = [
            'sichtbar' => 'v.' . self::SICHTBAR,
            'entfernt' => 'v.removed_at IS NOT NULL',
            'schwach'  => 'v.' . self::SICHTBAR . " AND v.stars <= $schwach",
        ];

        $bedingungen = [];
        if (isset($wo[$in_filter])) $bedingungen[] = $wo[$in_filter];

        // AUSGEBLENDET WIRD NACH DEM GUIDE, nicht nach dem Kunden - und das
        // ist keine Willkuer, sondern folgt daraus, was Moderation hier
        // ueberhaupt bewirkt:
        //
        //   Ist das Konto des GUIDES geloescht, sind seine Standorte und sein
        //   Profil verschwunden (App\Model\User::activeSql). Die Bewertung
        //   ist damit nirgends mehr zu lesen - sie zu entfernen aendert
        //   nichts, und sie gehoert nicht in die Arbeitsliste.
        //
        //   Ist das Konto des KUNDEN geloescht, steht die Bewertung
        //   WEITERHIN oeffentlich auf der Standortseite und auf dem Profil
        //   des Guides: Sie ist eine Auskunft ueber IHN, und sie traegt
        //   keinen Namen. Sie muss also moderierbar bleiben - und wird
        //   deshalb nie ausgeblendet, nur gekennzeichnet.
        if (!$in_mit_geloeschten) $bedingungen[] = User::activeSql('g');

        $where = $bedingungen === [] ? '' : 'WHERE ' . implode(' AND ', $bedingungen);
        // LIMIT vertraegt in MySQL keinen gebundenen Parameter, solange PDO
        // nicht emuliert.
        $limit = max(1, min(1000, $in_limit));

        try {
            $query = "SELECT v.id, v.stars, v.body, v.created_at,
                             v.removed_at, v.removed_reason,
                             v.location_id, v.guide_user_id, v.customer_user_id,
                             l.title,
                             g.username AS guide_username,
                             k.username AS customer_username,
                             -- BEIDE KENNZEICHEN, immer mitgeliefert: Der
                             -- Guide, weil seine Zeilen nur eingeblendet
                             -- sagen muessen, warum sie nirgends mehr stehen;
                             -- der Kunde, weil seine Bewertung sichtbar
                             -- bleibt und der Name daneben zu einem Konto
                             -- gehoert, das es nicht mehr gibt.
                             g.deleted AS guide_deleted,
                             k.deleted AS customer_deleted
                        FROM tour_review v
                        LEFT JOIN location l ON l.id = v.location_id
                        LEFT JOIN user     g ON g.id = v.guide_user_id
                        LEFT JOIN user     k ON k.id = v.customer_user_id
                        $where
                       ORDER BY v.created_at DESC, v.id DESC
                       LIMIT $limit";
            $stmt = PdoConnect::$connection->query($query);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            error_log('TourReview::allForAdmin: ' . $e->getMessage());
            return [];
        }
    }

    // =================================================================
    // MODERATION
    // =================================================================

    /**
     * Ein Admin entfernt eine Bewertung.
     *
     * ENTFERNT HEISST AUSGEBLENDET: removed_at wird gesetzt, die Zeile bleibt
     * stehen. Sie zaehlt danach nirgends mehr mit - jede Abfrage dieser
     * Klasse filtert ueber SICHTBAR -, aber es bleibt nachvollziehbar, dass
     * es sie gab und wer sie entfernt hat. Dasselbe Muster wie beim Sperren
     * eines Standorts (App\Model\Location::block), wo ebenfalls nichts
     * geloescht wird.
     *
     * NUR EINMAL: Eine bereits entfernte Bewertung wird nicht noch einmal
     * angefasst - sonst uebernaehme der zweite Klick den ersten Entferner.
     *
     * WER DAS DARF, entscheidet das Recht review.remove und damit index.php.
     * Anders als bei einer Anfrage steht hier keine Zustaendigkeit in der
     * WHERE-Klausel: Die Moderation ist fuer JEDE Bewertung zustaendig, und
     * es gibt nichts, wogegen sich der Datensatz pruefen liesse.
     *
     * @param int    $in_id
     * @param int    $in_admin_id
     * @param string $in_reason Begruendung, darf leer bleiben
     * @return bool true, wenn wirklich eine Zeile getroffen wurde
     */
    public static function remove($in_id, $in_admin_id, string $in_reason = ''): bool
    {
        $id    = (int)$in_id;
        $admin = (int)$in_admin_id;
        if ($id < 1 || $admin < 1) return false;

        $grund = self::kuerze($in_reason, 200);
        $grund = $grund === '' ? null : $grund;

        try {
            $stmt = PdoConnect::$connection->prepare(
                "UPDATE tour_review
                    SET removed_at     = NOW(),
                        removed_by     = :admin,
                        removed_reason = :grund
                  WHERE id = :id
                    AND " . self::SICHTBAR
            );
            $stmt->bindParam(':admin', $admin, \PDO::PARAM_INT);
            $stmt->bindParam(':grund', $grund);
            $stmt->bindParam(':id',    $id,    \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log('TourReview::remove: ' . $e->getMessage());
            return false;
        }
    }

    // =================================================================
    // Hilfen
    // =================================================================

    /**
     * Nur die beiden Spalten, nach denen abgefragt wird.
     *
     * Der Name kommt ausschliesslich aus dieser Klasse und nie von aussen. Er
     * geht aber als Textbaustein in eine Abfrage, und ein Textbaustein in
     * einer Abfrage wird geprueft - unabhaengig davon, wer ihn heute setzt.
     * Dieselbe Regel wie bei App\Model\TourRequest::alias().
     *
     * @param string $in_spalte
     * @return string
     */
    private static function spalte(string $in_spalte): string
    {
        return in_array($in_spalte, ['guide_user_id', 'location_id'], true)
             ? $in_spalte
             : 'guide_user_id';
    }

    /**
     * Schneidet eine Eingabe auf ihre Laenge zurecht.
     *
     * ZURECHTGESCHNITTEN UND NICHT ABGEWIESEN - dieselbe Regel wie beim
     * Guide-Profil (App\Model\GuideProfile::kuerze): Wer einen Satz zu viel
     * schreibt, soll nicht vor einer Fehlerseite stehen und alles noch einmal
     * tippen.
     *
     * mb_substr und nicht substr: Ein auf halber Strecke abgeschnittener
     * Umlaut ist kein Zeichen mehr, sondern ein Fragezeichen im Kasten.
     *
     * @param mixed $in_wert
     * @param int   $in_max
     * @return string
     */
    private static function kuerze($in_wert, int $in_max): string
    {
        $text = is_scalar($in_wert) ? (string)$in_wert : '';

        // Steuerzeichen raus, den Zeilenumbruch ausgenommen: Ein Kunde darf
        // Absaetze schreiben, alles andere hat in einem Fliesstext nichts zu
        // suchen.
        $text = preg_replace('/[^\P{C}\n]+/u', '', str_replace(["\r\n", "\r"], "\n", $text));
        $text = trim((string)$text);

        // Mehr als zwei Leerzeilen hintereinander sind Gestaltung mit der
        // Eingabetaste und blaehen jede Seite auf, die den Text zeigt.
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return mb_substr((string)$text, 0, $in_max);
    }
}
