<?php

namespace App\Model;

use App\Helper\Role;

/**
 * Die Zahlen der Verwaltungsuebersicht.
 *
 * WARUM EINE EIGENE KLASSE UND NICHT VIER AUFRUFE IM CONTROLLER
 * ------------------------------------------------------------
 * Weil die Uebersicht ueber vier Tabellen zaehlt und keine davon ihr gehoert.
 * Waeren die Abfragen im Controller, stuende dort die Frage "was heisst
 * durchgefuehrt" ein zweites Mal - beantwortet ist sie in
 * App\Model\TourRequest, und von dort holt sich diese Klasse den Baustein.
 * Dasselbe gilt fuer "sichtbar" bei den Bewertungen.
 *
 * EINE ABFRAGE JE KACHEL, nicht eine je Zahl: Die Aufteilung der Konten nach
 * Rolle und die Zahl der neuen Konten stehen in DERSELBEN Zeilengruppe, also
 * werden sie zusammen geholt. Vier Abfragen fuer eine Seite, die ein Admin
 * ein paar Mal am Tag aufruft.
 *
 * KEINE ZAHL WIRD HIER GEDEUTET. Diese Klasse zaehlt; ob eine Zahl
 * hervorgehoben dasteht, entscheidet die Ansicht (App\Helper\AdminView).
 *
 * WAS BEWUSST NICHT GEZAEHLT WIRD: irgendetwas ueber einzelne Personen.
 * Zurueck kommen Summen. Wer wissen will, welches Konto sich dahinter
 * verbirgt, geht auf die Listen - dort steht es mit Namen, und dort ist es
 * auch eine Auskunft, fuer die es einen Grund gibt.
 */
class AdminStats
{
    /**
     * Zeitraum der Kachel "neue Konten", in Tagen.
     *
     * Sieben, weil eine Woche der Takt ist, in dem jemand auf so eine Seite
     * schaut. Dreissig waere ein Trend und keine Neuigkeit, ein Tag waere
     * Rauschen.
     */
    public const NEU_TAGE = 7;

    /**
     * Zeitraum der Kachel "durchgefuehrte Fuehrungen", in Tagen.
     *
     * Dreissig, und hier absichtlich laenger: Eine Fuehrung ist ein seltenes
     * Ereignis. Ueber sieben Tage stuende dort in einer jungen Installation
     * meistens eine Null, und eine Null sagt nichts darueber, ob die
     * Anwendung benutzt wird.
     */
    public const ZEITRAUM_TAGE = 30;

    /**
     * Alle Zahlen der Uebersicht auf einmal.
     *
     * @return array<string,mixed> Siehe die vier Bausteine unten. Bei einem
     *                             Datenbankfehler fehlt der betroffene
     *                             Baustein nicht - er kommt mit Nullen
     *                             zurueck. Eine Uebersicht, die wegen einer
     *                             Kachel gar nicht mehr aufgeht, waere die
     *                             schlechtere Antwort.
     */
    public static function bestand(): array
    {
        return [
            'konten'      => self::konten(),
            'standorte'   => self::standorte(),
            'fuehrungen'  => self::fuehrungen(),
            'bewertungen' => self::bewertungen(),
        ];
    }

    /**
     * DIE ARBEITSVORRAETE - was auf jemanden wartet.
     *
     * DER UNTERSCHIED ZU bestand(): Die Zahlen dort BESCHREIBEN, diese hier
     * FORDERN. "42 Konten" ist eine Auskunft; "3 haengende Fuehrungen" ist
     * eine Aufgabe. Deshalb stehen sie getrennt und auf der Uebersicht als
     * Erstes - eine Aufgabe, die zwischen Bestandszahlen steht, sieht aus
     * wie eine Bestandszahl.
     *
     * DREI VORRAETE, und alle drei haben gemeinsam, dass sie heute NIRGENDS
     * auffallen:
     *
     *   haengend       Fuehrungen, die begonnen haben und die niemand
     *                  beendet hat. Der Guide sieht seine eigenen in der
     *                  Kopfleiste - aber nur, wenn er die Seite offen hat.
     *   unbeantwortet  Anfragen, die ohne Antwort verfallen sind. Gemerkt
     *                  hat das bisher nur der Kunde, der gewartet hat.
     *   gesperrt       Standorte unter Sperre. Ein Vorgang, den jemand
     *                  eroeffnet hat und den jemand wieder schliessen muss.
     *
     * WAS DIE ZAHLEN BEDEUTEN, steht nicht hier, sondern bei den
     * Bedingungen, aus denen sie kommen (App\Model\TourRequest::
     * runningSql, ::unansweredSql). Diese Klasse zaehlt.
     *
     * @return array{haengend:int, unbeantwortet:int, gesperrt:int}
     */
    public static function vorrat(): array
    {
        $anfragen = TourRequest::adminCounters();

        return [
            'haengend'      => (int)($anfragen['haengend'] ?? 0),
            'unbeantwortet' => (int)($anfragen['unbeantwortet'] ?? 0),
            // Aus derselben Abfrage wie die Bestandskachel - die Zahl steht
            // an beiden Stellen und darf nicht zweimal verschieden
            // ermittelt werden.
            'gesperrt'      => (int)(self::standorte()['gesperrt'] ?? 0),
        ];
    }

    /**
     * Konten: gesamt, je Rolle, neu im Zeitraum.
     *
     * GELOESCHTE KONTEN ZAEHLEN NICHT MIT. Sie stehen weiter in der Tabelle
     * (App\Model\User::del_it setzt nur ein Kennzeichen), sind aber kein
     * Bestand mehr - dieselbe Regel wie in User::getAll(), das die Liste
     * fuellt. Zwei verschiedene Zahlen auf zwei Seiten derselben Verwaltung
     * waeren ein Fehler, den niemand mehr aufloest.
     *
     * JE ROLLE UND NICHT NUR EINE SUMME: "200 Konten" ist keine Auskunft,
     * "200 Konten, davon 12 Guides" ist eine. Die Rollen kommen aus
     * App\Helper\Role und nicht aus der Abfrage - eine Rolle ohne ein
     * einziges Konto muss mit einer Null dastehen und nicht fehlen.
     *
     * @return array{gesamt:int, je_rolle:array<int,int>, neu:int}
     */
    private static function konten(): array
    {
        // Alle bekannten Rollen mit einer Null vorbelegen, damit die Ansicht
        // sie in fester Reihenfolge ausgeben kann.
        $je_rolle = [];
        foreach (Role::all() as $rolle) {
            $je_rolle[$rolle] = 0;
        }
        $gesamt = 0;
        $neu    = 0;

        // Die Zahl geht als Text in die Abfrage - sie stammt aus einer
        // Konstante dieser Klasse und nie von aussen, wird aber trotzdem
        // durch (int) geschickt. Dieselbe Regel wie in
        // App\Model\TourRequest::statusSql().
        $tage = (int)self::NEU_TAGE;

        try {
            $stmt = PdoConnect::$connection->query(
                "SELECT type_id,
                        COUNT(*) AS anzahl,
                        SUM(created_at >= DATE_SUB(NOW(), INTERVAL $tage DAY)) AS neu
                   FROM user
                  WHERE deleted = 0
                  GROUP BY type_id"
            );
            foreach ($stmt as $zeile) {
                $anzahl  = (int)$zeile['anzahl'];
                $gesamt += $anzahl;
                $neu    += (int)$zeile['neu'];

                // Eine Rollennummer, die App\Helper\Role nicht kennt, zaehlt
                // in der Gesamtzahl mit, bekommt aber keine eigene Kachel:
                // Sie waere eine Zeile ohne Namen. Auffallen soll sie
                // trotzdem - deshalb der Log-Eintrag.
                $id = Role::id($zeile['type_id']);
                if ($id === null) {
                    error_log('AdminStats::konten: unbekannte Rolle '
                        . var_export($zeile['type_id'], true) . ' in user.type_id');
                    continue;
                }
                $je_rolle[$id] = $anzahl;
            }
        } catch (\PDOException $e) {
            error_log('AdminStats::konten: ' . $e->getMessage());
        }

        return ['gesamt' => $gesamt, 'je_rolle' => $je_rolle, 'neu' => $neu];
    }

    /**
     * Standorte: gesamt, gesperrt, und wie viele Guides ueberhaupt anbieten.
     *
     * DIE DRITTE ZAHL IST DIE INTERESSANTE. "40 Standorte" laesst offen, ob
     * sie von vierzig Guides kommen oder von zweien - und das ist fuer die
     * Verwaltung ein Unterschied.
     *
     * @return array{gesamt:int, gesperrt:int, anbieter:int}
     */
    private static function standorte(): array
    {
        try {
            $stmt = PdoConnect::$connection->query(
                "SELECT COUNT(*)                  AS gesamt,
                        SUM(blocked = 1)          AS gesperrt,
                        COUNT(DISTINCT user_id)   AS anbieter
                   FROM location"
            );
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($zeile !== false) {
                return [
                    'gesamt'   => (int)$zeile['gesamt'],
                    'gesperrt' => (int)$zeile['gesperrt'],
                    'anbieter' => (int)$zeile['anbieter'],
                ];
            }
        } catch (\PDOException $e) {
            error_log('AdminStats::standorte: ' . $e->getMessage());
        }

        return ['gesamt' => 0, 'gesperrt' => 0, 'anbieter' => 0];
    }

    /**
     * Fuehrungen: durchgefuehrt insgesamt und im Zeitraum.
     *
     * WAS "DURCHGEFUEHRT" HEISST, STEHT NICHT HIER, sondern in
     * App\Model\TourRequest::conductedSql(): begonnen und zu. Die Bedingung
     * ist nicht trivial - eine vergessene Beendigung zaehlt nach Ablauf der
     * Frist trotzdem -, und eine zweite Fassung davon waere die, die beim
     * naechsten Umbau vergessen wird. Die Uebersicht muss dieselbe Zahl
     * zeigen wie die Bewertung, sonst erklaert sie niemandem etwas.
     *
     * GEZAEHLT WIRD NACH started_at, nicht nach dem Anlegen der Anfrage: Der
     * Zeitraum meint "in den letzten dreissig Tagen stattgefunden".
     *
     * @return array{gesamt:int, zeitraum:int, offen:int}
     */
    private static function fuehrungen(): array
    {
        $tage      = (int)self::ZEITRAUM_TAGE;
        $gemacht   = TourRequest::conductedSql('r');
        $laufend   = TourRequest::runningSql('r');

        try {
            $stmt = PdoConnect::$connection->query(
                "SELECT SUM($gemacht)                                     AS gesamt,
                        SUM($gemacht
                            AND r.started_at >= DATE_SUB(NOW(), INTERVAL $tage DAY)) AS zeitraum,
                        SUM($laufend)                                     AS offen
                   FROM tour_request r"
            );
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($zeile !== false) {
                return [
                    'gesamt'   => (int)$zeile['gesamt'],
                    'zeitraum' => (int)$zeile['zeitraum'],
                    'offen'    => (int)$zeile['offen'],
                ];
            }
        } catch (\PDOException $e) {
            error_log('AdminStats::fuehrungen: ' . $e->getMessage());
        }

        return ['gesamt' => 0, 'zeitraum' => 0, 'offen' => 0];
    }

    /**
     * Bewertungen: sichtbare, entfernte und der Durchschnitt.
     *
     * DER DURCHSCHNITT UEBER ALLES, und hier gilt die Schwelle aus
     * App\Model\TourReview::MIN_FOR_AVERAGE ausdruecklich NICHT. Sie schuetzt
     * einen einzelnen Guide davor, dass aus zwei Stimmen ein Urteil ueber ihn
     * wird. Diese Zahl ist kein Urteil ueber irgendjemanden, sondern die
     * Auskunft, wie die Fuehrungen der Plattform insgesamt ankommen - und die
     * gibt es ab der ersten Bewertung.
     *
     * ENTFERNTE ZAEHLEN GETRENNT und gehen nicht in den Durchschnitt ein:
     * Entfernt heisst ausgeblendet (TourReview::remove), nicht geloescht.
     * Dass es sie gab, gehoert in die Verwaltung; ihr Sternwert nicht.
     *
     * @return array{sichtbar:int, entfernt:int, schnitt:?float}
     */
    private static function bewertungen(): array
    {
        try {
            $stmt = PdoConnect::$connection->query(
                "SELECT SUM(removed_at IS NULL)     AS sichtbar,
                        SUM(removed_at IS NOT NULL) AS entfernt,
                        AVG(CASE WHEN removed_at IS NULL THEN stars END) AS schnitt
                   FROM tour_review"
            );
            $zeile = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($zeile !== false) {
                return [
                    'sichtbar' => (int)$zeile['sichtbar'],
                    'entfernt' => (int)$zeile['entfernt'],
                    // null bleibt null: "noch keine Bewertung" ist etwas
                    // anderes als "Durchschnitt 0,0", und 0,0 gibt es auf
                    // einer Skala ab einem Stern ohnehin nicht.
                    'schnitt'  => $zeile['schnitt'] === null
                                ? null : (float)$zeile['schnitt'],
                ];
            }
        } catch (\PDOException $e) {
            error_log('AdminStats::bewertungen: ' . $e->getMessage());
        }

        return ['sichtbar' => 0, 'entfernt' => 0, 'schnitt' => null];
    }
}
